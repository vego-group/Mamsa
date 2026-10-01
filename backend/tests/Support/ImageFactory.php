<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Real encoded images for tests.
 *
 * The upload path decodes what it is given, so a fabricated header is no
 * longer enough to exercise it — a test that PUTs eight bytes of PNG magic
 * proves only that the magic check ran.
 */
final class ImageFactory
{
    /** A JPEG of exactly $width × $height, with some structure so it compresses like a photo. */
    public static function jpeg(int $width, int $height, int $quality = 90): string
    {
        return self::encode(self::canvas($width, $height), fn ($img) => imagejpeg($img, null, $quality));
    }

    public static function png(int $width, int $height): string
    {
        return self::encode(self::canvas($width, $height), fn ($img) => imagepng($img));
    }

    public static function webp(int $width, int $height): string
    {
        return self::encode(self::canvas($width, $height), fn ($img) => imagewebp($img, null, 90));
    }

    /**
     * A JPEG carrying an APP1 metadata segment, spliced in after SOI the way a
     * camera writes one. Used to prove the segment does not survive an upload —
     * that block is where GPS coordinates live.
     */
    public static function jpegWithExifSegment(int $width, int $height, string $payload = 'GPSLatitude24.85N'): string
    {
        $jpeg = self::jpeg($width, $height);

        $body    = "Exif\x00\x00".$payload;
        $length  = strlen($body) + 2;                       // the length field counts itself
        $segment = "\xFF\xE1".pack('n', $length).$body;

        // SOI is the first two bytes; APP segments follow it.
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private static function canvas(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        // Flat colour compresses to almost nothing, which makes size
        // assertions meaningless; a gradient plus a block behaves like content.
        for ($x = 0; $x < $width; $x++) {
            $colour = imagecolorallocate($image, (int) (255 * $x / max(1, $width)), 120, 200);
            imageline($image, $x, 0, $x, $height, $colour);
        }

        imagefilledrectangle(
            $image,
            (int) ($width * 0.2), (int) ($height * 0.2),
            (int) ($width * 0.6), (int) ($height * 0.7),
            imagecolorallocate($image, 20, 30, 40),
        );

        return $image;
    }

    private static function encode(\GdImage $image, callable $writer): string
    {
        ob_start();
        $writer($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
