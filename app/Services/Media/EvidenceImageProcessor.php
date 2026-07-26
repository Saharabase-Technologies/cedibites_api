<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

/**
 * Smaller renditions of an evidence photo, so a page of thumbnails does not cost
 * a page of full-resolution phone photos.
 *
 * Measured on production after the first field test: one wastage claim held six
 * photos totalling ~14 MB, and opening it pulled all of them to draw six 112px
 * squares. The largest was 6.2 MB. At 400px that grid is about 250 KB.
 *
 * ── Rules this works under ───────────────────────────────────────────────────
 *
 * THE ORIGINAL IS NEVER TOUCHED. This is dispute evidence. Whatever the phone
 * sent is what gets kept and what "view full size" opens; these are a
 * convenience layered over it, and every consumer falls back to the original.
 *
 * FAILURE IS NEVER FATAL. A photo that GD cannot decode - an odd HEIC variant,
 * a truncated upload - still attaches, still displays, and is simply not
 * optimised. Losing evidence to save bandwidth would be a bad trade.
 *
 * GD, NOT IMAGICK. Imagick is not installed on the VPS (checked) and GD is.
 * `spatie/image` comes in with `spatie/laravel-medialibrary`, which is already a
 * dependency, so none of this adds one.
 */
class EvidenceImageProcessor
{
    /** Longest edge for the grid thumbnail. */
    public const THUMB_WIDTH = 400;

    /** Longest edge for the lightbox. Generous - the point is to read a label. */
    public const DISPLAY_WIDTH = 1600;

    /**
     * Build both renditions from a stored original.
     *
     * @param  string  $path  path on the `public` disk, e.g. inventory/wastage/12/abc.jpg
     * @return array{thumb_path: string, thumb_url: string, display_path: string, display_url: string}|null
     *                                                                                                      Null when the file is not a processable image, or processing failed.
     */
    public function process(string $path, ?string $mimeType = null): ?array
    {
        if ($mimeType !== null && ! str_starts_with(strtolower($mimeType), 'image/')) {
            return null; // Video. No transcoding here - that would need ffmpeg.
        }

        $disk = Storage::disk('public');
        $absolute = $disk->path($path);

        if (! is_file($absolute)) {
            return null;
        }

        $displayPath = $this->derivativePath($path, 'display');
        $thumbPath = $this->derivativePath($path, 'thumb');

        try {
            $disk->makeDirectory(dirname($displayPath));

            /*
             * Display first, then the thumbnail FROM the display rather than
             * from the original. Decoding a 12-megapixel JPEG is the expensive
             * part, and doing it once instead of twice roughly halves the time
             * the phone spends waiting. 1600 -> 400 is still a heavy downsample,
             * so nothing visible is lost.
             */
            $this->render($absolute, $disk->path($displayPath), self::DISPLAY_WIDTH, 82);
            $this->render($disk->path($displayPath), $disk->path($thumbPath), self::THUMB_WIDTH, 78);
        } catch (\Throwable $e) {
            // Tidy up a half-written pair so nothing points at a broken file.
            foreach ([$displayPath, $thumbPath] as $partial) {
                if ($disk->exists($partial)) {
                    $disk->delete($partial);
                }
            }

            Log::warning('Could not build evidence image derivatives.', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'thumb_path' => $thumbPath,
            'thumb_url' => $disk->url($thumbPath),
            'display_path' => $displayPath,
            'display_url' => $disk->url($displayPath),
        ];
    }

    /** Remove derivatives when their original goes. */
    public function forget(?string ...$paths): void
    {
        $disk = Storage::disk('public');

        foreach (array_filter($paths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    private function render(string $from, string $to, int $width, int $quality): void
    {
        Image::useImageDriver(ImageDriver::Gd)
            ->loadFile($from)
            /*
             * Phones record orientation in EXIF rather than rotating the pixels.
             * Resampling without applying it produces a sideways thumbnail next
             * to an upright original - and on a page whose entire job is "show
             * me the food", a picture on its side is a real cost.
             */
            ->orientation()
            ->width($width)
            ->quality($quality)
            ->save($to);
    }

    /**
     * Derivatives sit beside the original under a suffix, keeping the wastage
     * folder self-describing: `abc.jpg` -> `abc_thumb.jpg`. Deleting a claim's
     * folder still takes everything with it.
     */
    private function derivativePath(string $path, string $suffix): string
    {
        $dir = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        // Always JPEG: the renditions are for viewing, and a 6 MB PNG
        // screenshot has no business staying a PNG at 400px.
        return ($dir === '.' ? '' : $dir.'/')."{$name}_{$suffix}.jpg";
    }
}
