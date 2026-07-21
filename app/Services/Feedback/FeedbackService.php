<?php

namespace App\Services\Feedback;

use App\Jobs\TranscribeFeedbackReport;
use App\Models\Branch;
use App\Models\FeedbackReport;
use App\Models\RequestLog;
use App\Models\User;
use App\Notifications\FeedbackFixedNotification;
use App\Services\Feedback\Transcription\Transcriber;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingest + correlation for feedback reports.
 *
 * The submit path lives by I2: diagnostic and context fields DEGRADE, they never
 * reject. Oversized capture arrays are trimmed (keep newest); a bogus branch id
 * coerces to null. The only hard rejects are file size / count caps (enforced in
 * the form request), never a diagnostic field.
 */
class FeedbackService
{
    /** Buffer caps — mirror the client ring buffers; trim to keep newest. */
    private const CAPS = [
        'breadcrumbs' => 60,
        'console_entries' => 200,
        'network_entries' => 50,
        'request_ids' => 50,
    ];

    /**
     * @param  array<string, mixed>  $data  validated + payload-merged fields
     */
    public function createReport(array $data, Request $request, User $reporter): FeedbackReport
    {
        $report = FeedbackReport::create([
            'reporter_id' => $reporter->id,
            'branch_id' => $this->coerceBranchId($data['branch_id'] ?? null), // I2 / C1
            'role_at_report' => $data['role_at_report'] ?? null,
            'route' => $data['route'] ?? null,
            'severity' => $this->coerceSeverity($data['severity'] ?? null),
            'description' => $data['description'] ?? null,
            'audio_url' => ($f = $request->file('audio')) ? $this->upload($f, 'audio') : null,
            'replay_url' => ($r = $request->file('replay')) ? $this->upload($r, 'replay') : null,
            'replay_id' => $data['replay_id'] ?? null,
            'screenshots' => $this->buildScreenshots(
                $this->normaliseFiles($request->file('screenshots')),
                $data['screenshot_meta'] ?? [],
            ),
            // I2 — trim to caps (keep newest), never reject.
            'breadcrumbs' => $this->trimNewest($data['breadcrumbs'] ?? null, self::CAPS['breadcrumbs']),
            'console_entries' => $this->trimNewest($data['console_entries'] ?? null, self::CAPS['console_entries']),
            'network_entries' => $this->trimNewest($data['network_entries'] ?? null, self::CAPS['network_entries']),
            'request_ids' => $this->trimNewest($data['request_ids'] ?? null, self::CAPS['request_ids']),
            'client_meta' => is_array($data['client_meta'] ?? null) ? $data['client_meta'] : null,
            'status' => 'new',
        ]);

        $this->assignNumber($report); // C17 — best-effort, never blocks the report

        // Auto-transcribe the voice note off-request (no-op if no provider).
        if ($report->audio_url) {
            TranscribeFeedbackReport::dispatch($report->id);
        }

        return $report->fresh();
    }

    /**
     * Transcribe a report's voice note in place. Idempotent — a no-op when there
     * is no audio or a transcript already exists. Reads the audio bytes straight
     * off the public disk rather than re-fetching our own URL.
     */
    public function transcribeReport(FeedbackReport $report): void
    {
        if (! $report->audio_url || $report->transcript) {
            return;
        }

        $path = Str::after($report->audio_url, '/storage/');
        if ($path === $report->audio_url || ! Storage::disk('public')->exists($path)) {
            return;
        }

        $text = app(Transcriber::class)->transcribe(
            Storage::disk('public')->get($path),
            basename($path),
        );

        if ($text) {
            $report->transcript = $text;
            $report->save();
        }
    }

    /**
     * Correlated backend logs for a report. Default: this user's actions only,
     * filtered by the report's captured request ids. Fallback: everything ±N
     * minutes around the report (the "show me everything around then" tab).
     *
     * @return Collection<int, RequestLog>
     */
    public function logsForReport(FeedbackReport $report, ?int $windowMinutes): Collection
    {
        if ($windowMinutes !== null) {
            $windowMinutes = max(1, min(30, $windowMinutes)); // clamp 1–30
            $created = $report->created_at;

            return RequestLog::query()
                ->whereBetween('created_at', [
                    $created->copy()->subMinutes($windowMinutes),
                    $created->copy()->addMinutes($windowMinutes),
                ])
                ->orderBy('created_at')
                ->limit(500)
                ->get();
        }

        $ids = $report->request_ids ?? [];
        if (empty($ids)) {
            return collect();
        }

        return RequestLog::query()
            ->whereIn('request_id', $ids)
            ->orderBy('created_at')
            ->limit(500)
            ->get();
    }

    /** Notify a reporter their report was fixed — best-effort (P5). */
    public function notifyReporterFixed(FeedbackReport $report): void
    {
        try {
            $report->reporter?->notify(new FeedbackFixedNotification($report));
        } catch (Throwable $e) {
            Log::warning('Feedback fixed-notification failed', ['id' => $report->id, 'error' => $e->getMessage()]);
        }
    }

    /** Purge request logs older than the retention window. */
    public function purgeExpiredRequestLogs(int $days): int
    {
        return RequestLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Invalid severity degrades to the default rather than rejecting (I2). */
    private function coerceSeverity(mixed $value): string
    {
        $allowed = ['blocking', 'annoying', 'cosmetic', 'suggestion'];

        return in_array($value, $allowed, true) ? $value : 'annoying';
    }

    /** A membership/branch id is not guaranteed valid — coerce to valid-or-null. */
    private function coerceBranchId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $id = (int) $value;

        return Branch::whereKey($id)->exists() ? $id : null;
    }

    /** @param array<int, mixed>|null $arr @return array<int, mixed> */
    private function trimNewest(?array $arr, int $cap): array
    {
        if (empty($arr)) {
            return [];
        }

        return array_values(array_slice($arr, -$cap));
    }

    /** $request->file('screenshots') may be a single file, an array, or null. */
    private function normaliseFiles(mixed $files): array
    {
        if ($files === null) {
            return [];
        }

        return is_array($files) ? $files : [$files];
    }

    /**
     * Align screenshot metadata with uploaded files BY INDEX — meta[i] describes
     * the i-th uploaded file.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<string, mixed>>  $meta
     * @return array<int, array<string, mixed>>
     */
    private function buildScreenshots(array $files, array $meta): array
    {
        $out = [];
        foreach ($files as $i => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $m = is_array($meta[$i] ?? null) ? $meta[$i] : [];
            $out[] = [
                'url' => $this->upload($file, 'screenshots'),
                'source' => $m['source'] ?? 'capture',
                'route' => is_string($m['route'] ?? null) ? $m['route'] : null,
                'pins' => is_array($m['pins'] ?? null) ? $m['pins'] : [],
                'rects' => is_array($m['rects'] ?? null) ? $m['rects'] : [],
            ];
        }

        return $out;
    }

    /** Store to the public disk, return the URL. (Sidesteps blueprint C16.) */
    private function upload(UploadedFile $file, string $dir): ?string
    {
        try {
            $path = $file->store("feedback/{$dir}", 'public');

            return Storage::disk('public')->url($path);
        } catch (Throwable $e) {
            // An upload failure degrades — the report survives without the file.
            Log::warning('Feedback upload failed', ['dir' => $dir, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Assign the sequential human "#number" with conflict-retry (C17). The
     * unique index on `number` is the real guard: if two submits pick the same
     * value, the loser gets a unique violation and retries with a fresh max.
     * `number` is nullable, so a failed assignment never blocks the report.
     *
     * (No SELECT … FOR UPDATE — Postgres rejects it with aggregate functions.)
     */
    private function assignNumber(FeedbackReport $report): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $report->number = (FeedbackReport::max('number') ?? 0) + 1;
                $report->save();

                return;
            } catch (QueryException $e) {
                // Unique violation — another submit took this number; retry.
                if ($attempt === 4) {
                    Log::warning('Feedback #number assignment gave up', ['id' => $report->id]);
                }
            }
        }
    }
}
