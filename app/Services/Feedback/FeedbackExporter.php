<?php

namespace App\Services\Feedback;

use App\Models\FeedbackReport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Turns a report into a single artefact an engineer (or an AI agent) can act on:
 * a Markdown brief, or a ZIP bundling the brief with the screenshots + voice
 * note. Transcribes on the way out if not already.
 */
class FeedbackExporter
{
    public function __construct(private readonly FeedbackService $service) {}

    /** The report as a self-contained Markdown brief. */
    public function markdown(FeedbackReport $report): string
    {
        $this->service->transcribeReport($report); // idempotent; no-op without a provider
        $report->refresh()->load(['reporter', 'branch']);

        $num = $report->number ? "#{$report->number}" : "id {$report->id}";
        $lines = [];
        $lines[] = "# Feedback {$num} — {$report->severity}";
        $lines[] = '';
        $lines[] = "- **Status:** {$report->status}";
        $lines[] = '- **Reporter:** '.($report->reporter->name ?? 'Unknown').($report->role_at_report ? " ({$report->role_at_report})" : '');
        $lines[] = '- **Route:** '.($report->route ?: '—');
        $lines[] = '- **Branch:** '.($report->branch->name ?? '—');
        $lines[] = '- **When:** '.$report->created_at?->toDateTimeString();

        $lines[] = "\n## Description\n";
        $lines[] = $report->description ?: '_No description_';

        if ($report->transcript) {
            $lines[] = "\n## Voice transcript\n";
            $lines[] = $report->transcript;
        }

        // Per-page notes, grouped by the page they were recorded on — the
        // reporter's train of thought, in order, page by page.
        $notes = $report->notes()->get();
        if ($notes->isNotEmpty()) {
            $lines[] = "\n## Notes by page\n";

            foreach ($notes->groupBy(fn ($n) => $n->route ?: '—') as $route => $group) {
                $lines[] = "\n### {$route}\n";

                foreach ($group as $note) {
                    if ($note->body) {
                        $lines[] = "- {$note->body}";
                    }
                    if ($note->transcript) {
                        $lines[] = "- _(voice)_ {$note->transcript}";
                    } elseif ($note->audio_url) {
                        $lines[] = "- _(voice note, not transcribed)_ {$note->audio_url}";
                    }
                }
            }
        }

        $lines[] = "\n## Steps before reporting\n";
        foreach (($report->breadcrumbs ?? []) as $b) {
            $lines[] = "- [{$b['kind']}] ".($b['label'] ?? '');
        }
        if (empty($report->breadcrumbs)) {
            $lines[] = '_None recorded_';
        }

        $lines[] = "\n## Console\n";
        $lines[] = '```';
        foreach (($report->console_entries ?? []) as $c) {
            $lines[] = "[{$c['level']}] ".($c['message'] ?? '');
        }
        $lines[] = '```';

        $lines[] = "\n## Network\n";
        foreach (($report->network_entries ?? []) as $n) {
            $status = $n['status'] ?? 'ERR';
            $dur = isset($n['durationMs']) ? " ({$n['durationMs']}ms)" : '';
            $lines[] = "- {$status} {$n['method']} {$n['url']}{$dur}";
        }

        $lines[] = "\n## Correlated backend logs\n";
        foreach ($this->service->logsForReport($report, null) as $log) {
            $lines[] = "- {$log->status_code} {$log->method} {$log->path} ({$log->duration_ms}ms) · req={$log->request_id}";
            if ($log->message) {
                $lines[] = "  ```\n  ".str_replace("\n", "\n  ", $log->message)."\n  ```";
            }
        }

        $lines[] = "\n## Environment\n";
        foreach (($report->client_meta ?? []) as $k => $v) {
            $val = is_array($v) ? json_encode($v) : (string) $v;
            $lines[] = "- **{$k}:** {$val}";
        }

        return implode("\n", $lines)."\n";
    }

    /** Build a ZIP (brief + screenshots + audio) on disk; returns its path. */
    public function zipPath(FeedbackReport $report): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fb_').'.zip';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('report.md', $this->markdown($report));

        foreach (($report->screenshots ?? []) as $i => $shot) {
            $this->addStoredFile($zip, $shot['url'] ?? '', 'screenshots/shot-'.($i + 1));
        }
        if ($report->audio_url) {
            $this->addStoredFile($zip, $report->audio_url, 'voice-note');
        }

        $zip->close();

        return $tmp;
    }

    /** Copy a public-disk file into the zip, keeping its extension. */
    private function addStoredFile(ZipArchive $zip, string $url, string $nameNoExt): void
    {
        $path = Str::after($url, '/storage/');
        if ($path === $url || ! Storage::disk('public')->exists($path)) {
            return;
        }
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $zip->addFromString($nameNoExt.($ext ? ".{$ext}" : ''), Storage::disk('public')->get($path));
    }
}
