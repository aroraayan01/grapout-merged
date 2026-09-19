<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Storing a product picture at two sizes.
 *
 * Export buyers zoom in — on stitching, on a weld, on the print quality of a
 * label — so the original goes on disk untouched, at whatever resolution the
 * phone took it. A grid of thirty such originals is thirty phone photos on
 * every search, so a display copy is made beside it: long edge capped, JPEG,
 * good enough to judge and quick enough to scroll.
 *
 * Made with GD when GD is there and skipped when it is not. A server without
 * the extension serves the original for both jobs and is slower, not broken.
 */
class BusinessImage
{
    /** Longest edge of the display copy, in pixels. */
    public const DISPLAY_EDGE = 1400;

    /**
     * @return array{path: string, thumb_path: ?string}
     */
    public static function store(UploadedFile $file, string $dir): array
    {
        $path = $file->store($dir, 'public');

        return ['path' => $path, 'thumb_path' => self::displayCopy($path)];
    }

    /** Remove both files, quietly — a missing file is already gone. */
    public static function delete(?string $path, ?string $thumb): void
    {
        foreach (array_filter([$path, $thumb]) as $p) {
            try {
                Storage::disk('public')->delete($p);
            } catch (\Throwable) {
                // Nothing to do about a file that would not delete.
            }
        }
    }

    private static function displayCopy(string $path): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            $full = $disk->path($path);
            $info = @getimagesize($full);
            if (! $info) {
                return null;
            }
            [$w, $h, $type] = $info;

            $src = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($full),
                IMAGETYPE_PNG => @imagecreatefrompng($full),
                IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($full) : null,
                default => null,
            };
            if (! $src) {
                return null;
            }

            $scale = min(1, self::DISPLAY_EDGE / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($tw, $th);
            // Transparent PNGs land on white: a product photo with a hole in
            // it looks like a bug in a grid, not a design choice.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            $thumb = preg_replace('/\.[^.\/]+$/', '', $path) . '-display.jpg';
            ob_start();
            imagejpeg($dst, null, 86);
            $bytes = ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);

            $disk->put($thumb, $bytes);

            return $thumb;
        } catch (\Throwable) {
            return null;
        }
    }
}
