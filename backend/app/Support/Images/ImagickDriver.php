<?php

declare(strict_types=1);

namespace App\Support\Images;

use Imagick;
use ImagickException;

/**
 * ImageMagick backend — preferred where installed (production has 7.1.2 with
 * HEIC and WebP). It is the only one of the two that can read what an iPhone
 * produces by default.
 */
final class ImagickDriver implements ImageDriver
{
    public function name(): string
    {
        return 'imagick';
    }

    public function supportsHeic(): bool
    {
        static $supported = null;

        return $supported ??= (bool) array_intersect(
            ['HEIC', 'HEIF'],
            array_map('strtoupper', (new Imagick())->queryFormats()),
        );
    }

    public function load(string $bytes): ?Imagick
    {
        try {
            $image = new Imagick();
            $image->readImageBlob($bytes);

            // A HEIC burst or an animated WebP decodes to several frames; the
            // first is the photo, the rest would silently become an animation.
            if ($image->getNumberImages() > 1) {
                $image = $image->coalesceImages();
                $image->setIteratorIndex(0);
                $flat = $image->getImage();
                $image->clear();
                $image = $flat;
            }

            return $image;
        } catch (ImagickException) {
            return null;
        }
    }

    public function dimensions(mixed $image): array
    {
        return [$image->getImageWidth(), $image->getImageHeight()];
    }

    public function autoOrient(mixed $image, string $bytes): Imagick
    {
        // Done by hand rather than via autoOrientImage(), which is missing from
        // some builds — including the one on production. A missing method is an
        // Error, not an ImagickException, so it would have taken the whole
        // upload down rather than degrading.
        try {
            $orientation = $image->getImageOrientation();

            match ($orientation) {
                Imagick::ORIENTATION_TOPRIGHT    => $image->flopImage(),
                Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180),
                Imagick::ORIENTATION_BOTTOMLEFT  => $image->flipImage(),
                Imagick::ORIENTATION_LEFTTOP     => $this->transpose($image),
                Imagick::ORIENTATION_RIGHTTOP    => $image->rotateImage('#000', 90),
                Imagick::ORIENTATION_RIGHTBOTTOM => $this->transverse($image),
                Imagick::ORIENTATION_LEFTBOTTOM  => $image->rotateImage('#000', -90),
                default                          => null,
            };

            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        } catch (\Throwable) {
            // An unrotated photo is a far smaller problem than a refused upload.
        }

        return $image;
    }

    /** Mirror across the main diagonal (EXIF orientation 5). */
    private function transpose(Imagick $image): void
    {
        $image->flopImage();
        $image->rotateImage('#000', -90);
    }

    /** Mirror across the anti-diagonal (EXIF orientation 7). */
    private function transverse(Imagick $image): void
    {
        $image->flopImage();
        $image->rotateImage('#000', 90);
    }

    public function cover(mixed $image, int $width, int $height): Imagick
    {
        [$sw, $sh] = $this->dimensions($image);
        [$cw, $ch] = ImageProcessor::coverCrop($sw, $sh, $width, $height);
        [$tw, $th] = ImageProcessor::coverTarget($cw, $ch, $width, $height);

        $image->cropImage($cw, $ch, (int) round(($sw - $cw) / 2), (int) round(($sh - $ch) / 2));
        $image->setImagePage(0, 0, 0, 0);   // drop the crop offset, else it is written into the file

        if ($tw !== $cw || $th !== $ch) {
            $image->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1);
        }

        return $image;
    }

    public function contain(mixed $image, int $maxEdge): Imagick
    {
        [$sw, $sh] = $this->dimensions($image);
        [$tw, $th] = ImageProcessor::containTarget($sw, $sh, $maxEdge);

        if ($tw !== $sw || $th !== $sh) {
            $image->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1);
        }

        return $image;
    }

    public function toWebp(mixed $image, int $quality): ?string
    {
        return $this->encode($image, 'webp', $quality);
    }

    public function toJpeg(mixed $image, int $quality): ?string
    {
        // JPEG has no alpha; flatten onto white or transparency turns black.
        try {
            $flat = new Imagick();
            $flat->newImage($image->getImageWidth(), $image->getImageHeight(), 'white');
            $flat->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
            $bytes = $this->encode($flat, 'jpeg', $quality);
            $flat->clear();

            return $bytes;
        } catch (ImagickException) {
            return null;
        }
    }

    public function toPng(mixed $image): ?string
    {
        // ImageMagick reads PNG "quality" as zlib*10 + filter, so 95 is max
        // compression with the adaptive filter — not a 95% quality setting.
        return $this->encode($image, 'png', 95);
    }

    public function destroy(mixed $image): void
    {
        if ($image instanceof Imagick) {
            $image->clear();
        }
    }

    private function encode(Imagick $image, string $format, int $quality): ?string
    {
        try {
            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);
            // Everything the camera wrote — GPS included — goes here.
            $image->stripImage();

            return $image->getImageBlob();
        } catch (ImagickException) {
            return null;
        }
    }
}
