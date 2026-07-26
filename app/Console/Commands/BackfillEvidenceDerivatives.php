<?php

namespace App\Console\Commands;

use App\Models\Inventory\WastagePhoto;
use App\Services\Media\EvidenceImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Build thumb/display renditions for evidence photos uploaded before they
 * existed.
 *
 * Safe to re-run: it skips anything that already has a thumbnail on disk. Never
 * touches an original.
 */
class BackfillEvidenceDerivatives extends Command
{
    protected $signature = 'inventory:backfill-evidence-derivatives
                            {--apply : Write the files. Without this the command only reports.}
                            {--force : Rebuild even where derivatives already exist.}';

    protected $description = 'Generate thumbnail and display renditions for existing wastage evidence photos';

    public function handle(EvidenceImageProcessor $processor): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $disk = Storage::disk('public');

        $candidates = WastagePhoto::query()
            ->orderBy('id')
            ->get()
            ->filter(function (WastagePhoto $p) use ($disk, $force) {
                // Video has no derivatives - that would need ffmpeg.
                if ($p->mime_type !== null && ! str_starts_with(strtolower($p->mime_type), 'image/')) {
                    return false;
                }
                if (! $disk->exists($p->path)) {
                    return false;
                }

                return $force || $p->thumb_path === null || ! $disk->exists($p->thumb_path);
            });

        if ($candidates->isEmpty()) {
            $this->info('Every evidence photo already has its renditions.');

            return self::SUCCESS;
        }

        $originalBytes = $candidates->sum(fn (WastagePhoto $p) => $disk->size($p->path));

        $this->line("{$candidates->count()} photo(s), ".$this->mb($originalBytes).' of originals.');

        if (! $apply) {
            $this->warn('Re-run with --apply to write the renditions.');

            return self::SUCCESS;
        }

        $built = 0;
        $failed = 0;
        $derivedBytes = 0;

        foreach ($candidates as $photo) {
            $result = $processor->process($photo->path, $photo->mime_type);

            if ($result === null) {
                $failed++;
                $this->line("  <fg=yellow>skipped</> #{$photo->id} - could not be processed, original still serves");

                continue;
            }

            $photo->update($result);
            $built++;
            $derivedBytes += $disk->size($result['thumb_path']);

            $this->line("  <fg=green>built</> #{$photo->id} ".$this->mb($disk->size($photo->path)).' -> '.$this->mb($disk->size($result['thumb_path'])).' thumb');
        }

        $this->newLine();
        $this->info("{$built} built, {$failed} skipped.");
        $this->info('A grid of these now costs '.$this->mb($derivedBytes).' instead of '.$this->mb($originalBytes).'.');

        return self::SUCCESS;
    }

    private function mb(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
