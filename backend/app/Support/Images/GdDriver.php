<?php

declare(strict_types=1);

namespace App\Support\Images;

use GdImage;

/**
 * GD backend. Always present on our PHP builds, so this is the floor the
 * pipeline can rely on everywhere including CI.
 *
 * GD cannot read HEIC and never writes EXIF, which makes metadata stripping
 * automatic here rather than a step that can be forgotten.
 */
final class GdDriver implements ImageDriver
{
    public function name(): string
    {
        return 'gd';
    }

    public function supportsHeic(): bool
    {
        return false;
    }

    public function load(string $bytes): ?GdImage
    {
        // imagecreatefromstring() emits a warning on malformed input; the
        // return value is the signal we act on.
        $image = @imagecreatefromstring($bytes);

        return $image instanceof GdImage ? $image : null;
    }

    public function dimensions(mixed $image): array
    {
        return [imagesx($image), imagesy($image)];
    }

    public function autoOrient(mixed $image, string $bytes): GdImage
    {
        $orientation = self::readOrientation($bytes);

        if ($orientation === null || $orientation === 1) {
            return $image;
        }

        // The eight EXIF orientations are a rotation, a mirror, or both.
        $rotated = match ($orientation) {
            3, 4    => imagerotate($image, 180, 0),
            5, 6    => imagerotate($image, -90, 0),
            7, 8    => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated instanceof GdImage && $rotated !== $image) {
            imagedestroy($image);
            $image = $rotated;
        }

        // 2/4/5/7 are the mirrored halves of the set.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    public function cover(mixed $image, int $width, int $height): GdImage
    {
        [$sw, $sh] = $this->dimensions($image);
        [$cw, $ch] = ImageProcessor::coverCrop($sw, $sh, $width, $height);
        [$tw, $th] = ImageProcessor::coverTarget($cw, $ch, $width, $height);

        $canvas = $this->canvas($tw, $th);

        imagecopyresampled(
            $canvas, $image,
            0, 0,
            (int) round(($sw - $cw) / 2), (int) round(($sh - $ch) / 2),
            $tw, $th, $cw, $ch,
        );

        return $canvas;
    }

    public function contain(mixed $image, int $maxEdge): GdImage
    {
        [$sw, $sh] = $this->dimensions($image);
        [$tw, $th] = ImageProcessor::containTarget($sw, $sh, $maxEdge);

        if ($tw === $sw && $th === $sh) {
            return $image;
        }

        $canvas = $this->canvas($tw, $th);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $tw, $th, $sw, $sh);

        return $canvas;
    }

    public function toWebp(mixed $image, int $quality): ?string
    {
        return $this->capture(fn () => imagewebp($image, null, $quality));
    }

    public function toJpeg(mixed $image, int $quality): ?string
    {
        // JPEG has no alpha; without this a transparent PNG comes back black.
        [$w, $h] = $this->dimensions($image);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);

        $bytes = $this->capture(fn () => imagejpeg($flat, null, $quality));
        imagedestroy($flat);

        return $bytes;
    }

    public function toPng(mixed $image): ?string
    {
        imagesavealpha($image, true);

        return $this->capture(fn () => imagepng($image, null, 6));
    }

    public function destroy(mixed $image): void
    {
        if ($image instanceof GdImage) {
            imagedestroy($image);
        }
    }

    /**
     * A truecolour canvas that keeps transparency, so a PNG with an alpha
     * channel does not come back with a black background.
     */
    private function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        return $canvas;
    }

    /** GD writes to a stream; buffer it to get bytes back. */
    private function capture(callable $write): ?string
    {
        ob_start();
        $ok = (bool) $write();
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /** EXIF orientation without a temp file. Returns null when there is none. */
    private static function readOrientation(string $bytes): ?int
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return null;
        }

        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
    }
}
