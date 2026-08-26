<?php

declare(strict_types=1);

namespace App\Support\Images;

use Illuminate\Support\Facades\Storage;

/**
 * Unit-photo pipeline: one decode at upload time produces the canonical file
 * plus the fixed-size derivatives the storefront asks for.
 *
 * Two jobs, and the first is the one that matters most:
 *
 * 1. NORMALISE the stored original — bake in EXIF orientation, then drop every
 *    metadata block. Camera EXIF carries GPS to within a few metres, and unit
 *    photos are served from a public bucket, so an untouched upload publishes
 *    the property's real address whatever the listing says.
 * 2. DERIVE thumb/card/full so a 96×64 thumbnail stops downloading a full-size
 *    photograph.
 *
 * Everything here is best-effort by design: a failure leaves the original in
 * place with null derivatives, and the client falls back to `url`. An upload
 * that cannot be optimised is still an upload the partner made.
 */
final class ImageProcessor
{
    /**
     * The derivative set. `cover` crops to the box; `contain` never crops —
     * the lightbox shows the guest what the partner actually photographed, and
     * a crop there hides part of the unit before a booking.
     *
     * @var array<string, array{w: int, h: int, fit: string}>
     */
    public const VARIANTS = [
        'thumb' => ['w' => 400,  'h' => 300,  'fit' => 'cover'],
        'card'  => ['w' => 800,  'h' => 600,  'fit' => 'cover'],
        'full'  => ['w' => 2048, 'h' => 2048, 'fit' => 'contain'],
    ];

    /** Magic bytes → format. `[offset, signature]`; the client MIME is never trusted. */
    private const SIGNATURES = [
        'jpeg' => [[0, "\xFF\xD8\xFF"]],
        'png'  => [[0, "\x89PNG\r\n\x1A\n"]],
        'webp' => [[0, 'RIFF'], [8, 'WEBP']],
        'heic' => [[4, 'ftypheic'], [4, 'ftypheix'], [4, 'ftypheim'], [4, 'ftypheis'],
                   [4, 'ftyphevc'], [4, 'ftypmif1'], [4, 'ftypmsf1']],
    ];

    /**
     * Decompression-bomb guard. A 60 MP frame is ~240 MB decoded, which is a
     * request that dies rather than an image anyone uploaded on purpose.
     */
    private const MAX_MEGAPIXELS = 50;

    private static ?ImageDriver $driver = null;

    private static bool $resolved = false;

    /** ImageMagick where available (it is the one that reads HEIC), else GD. */
    public static function driver(): ?ImageDriver
    {
        if (self::$resolved) {
            return self::$driver;
        }

        self::$resolved = true;
        self::$driver = match (true) {
            extension_loaded('imagick') && class_exists(\Imagick::class) => new ImagickDriver(),
            extension_loaded('gd')                                       => new GdDriver(),
            default                                                      => null,
        };

        return self::$driver;
    }

    /** Test seam — forget the cached driver. */
    public static function flushDriver(): void
    {
        self::$driver = null;
        self::$resolved = false;
    }

    public static function available(): bool
    {
        return self::driver() !== null;
    }

    /** @return list<string> the formats an upload may actually be, here and now. */
    public static function acceptedFormats(): array
    {
        $formats = ['jpeg', 'png', 'webp'];

        if (self::driver()?->supportsHeic()) {
            $formats[] = 'heic';
        }

        return $formats;
    }

    /** Format name from the bytes themselves, or null if it is not an image we take. */
    public static function detect(string $bytes): ?string
    {
        foreach (self::SIGNATURES as $format => $alternatives) {
            foreach ($alternatives as [$offset, $signature]) {
                if (str_starts_with(substr($bytes, $offset, strlen($signature)), $signature)) {
                    // WebP's RIFF header needs both parts; a plain RIFF is a
                    // WAV file with an image extension.
                    if ($format === 'webp' && ! str_starts_with(substr($bytes, 8, 4), 'WEBP')) {
                        continue;
                    }

                    return $format;
                }
            }
        }

        return null;
    }

    /**
     * Rewrite an upload into its canonical stored form: orientation applied,
     * metadata gone, HEIC turned into something a browser can render.
     *
     * @return array{bytes: string, ext: string, width: int, height: int}|null
     *         null when the bytes will not decode or are implausibly large
     */
    public static function normalise(string $bytes, string $format): ?array
    {
        $driver = self::driver();

        if (! $driver) {
            return null;
        }

        $image = $driver->load($bytes);

        if (! $image) {
            return null;
        }

        try {
            [$w, $h] = $driver->dimensions($image);

            if ($w < 1 || $h < 1 || ($w * $h) > self::MAX_MEGAPIXELS * 1_000_000) {
                return null;
            }

            $image = $driver->autoOrient($image, $bytes);
            [$w, $h] = $driver->dimensions($image);

            // HEIC has to change format — nothing renders it in a browser.
            // The rest keep theirs: a partner's PNG staying a PNG is one less
            // surprise, and the re-encode is what strips the metadata.
            [$ext, $encoded] = match ($format) {
                'png'   => ['png', $driver->toPng($image)],
                'webp'  => ['webp', $driver->toWebp($image, self::quality())],
                default => ['jpg', $driver->toJpeg($image, self::originalQuality())],
            };

            if ($encoded === null) {
                return null;
            }

            // Re-encoding an already-compressed upload can make it BIGGER — a
            // file saved at quality 60 comes back out at 90. That trade is only
            // worth it when there is metadata to remove; otherwise the upload
            // is left exactly as the partner sent it.
            if ($format !== 'heic'
                && strlen($encoded) >= strlen($bytes)
                && ! self::carriesMetadata($bytes, $format)) {
                return [
                    'bytes'  => $bytes,
                    'ext'    => $format === 'jpeg' ? 'jpg' : $format,
                    'width'  => $w,
                    'height' => $h,
                ];
            }

            return [
                'bytes'  => $encoded,
                'ext'    => $ext,
                'width'  => $w,
                'height' => $h,
            ];
        } finally {
            $driver->destroy($image);
        }
    }

    /**
     * Write the derivative set next to the original and return `key => path`.
     * Deterministic names (`{base}_{key}.webp`) so a URL can be reconstructed
     * without a lookup.
     *
     * @return array<string, string>
     */
    public static function derivatives(string $bytes, string $directory, string $base, string $disk = 'public'): array
    {
        $driver = self::driver();

        if (! $driver) {
            return [];
        }

        $written = [];

        foreach (self::VARIANTS as $key => $spec) {
            $image = $driver->load($bytes);

            if (! $image) {
                break;
            }

            try {
                $image = $spec['fit'] === 'cover'
                    ? $driver->cover($image, $spec['w'], $spec['h'])
                    : $driver->contain($image, $spec['w']);

                $encoded = $driver->toWebp($image, self::quality());

                if ($encoded === null) {
                    continue;
                }

                $path = trim($directory, '/')."/{$base}_{$key}.webp";
                Storage::disk($disk)->put($path, $encoded);
                $written[$key] = $path;
            } finally {
                $driver->destroy($image);
            }
        }

        return $written;
    }

    /** Remove a derivative set (used when an upload is reprocessed). */
    public static function forget(array $variants, string $disk = 'public'): void
    {
        foreach ($variants as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk($disk)->delete($path);
            }
        }
    }

    /**
     * Does the file carry a metadata block worth removing?
     *
     * Only used to decide whether an unhelpful re-encode is worth doing, so a
     * false positive costs nothing but a re-encode. Orientation lives in EXIF,
     * so anything needing rotation answers true here.
     */
    public static function carriesMetadata(string $bytes, string $format): bool
    {
        return match ($format) {
            'jpeg'  => self::jpegHasAppSegment($bytes),
            'png'   => (bool) preg_match('/(eXIf|tEXt|iTXt|zTXt)/', substr($bytes, 0, 65536)),
            'webp'  => str_contains(substr($bytes, 0, 65536), 'EXIF') || str_contains(substr($bytes, 0, 65536), 'XMP '),
            default => true,
        };
    }

    /** Walk the JPEG segment chain looking for APP1..APP15 or a comment. */
    private static function jpegHasAppSegment(string $bytes): bool
    {
        $length = strlen($bytes);
        $i = 2;                                   // past SOI

        while ($i + 4 <= $length && $bytes[$i] === "\xFF") {
            $marker = ord($bytes[$i + 1]);

            // Standalone markers carry no length field.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                $i += 2;

                continue;
            }

            // Start of scan / end of image — pixel data from here on.
            if ($marker === 0xDA || $marker === 0xD9) {
                return false;
            }

            // APP1..APP15 is EXIF, XMP, ICC, IPTC — the blocks that carry a
            // position, a device id, or a timestamp.
            //
            // Deliberately NOT counted: APP0 (JFIF, a few bytes of pixel
            // density) and 0xFE (a text comment — most encoders stamp their own
            // name there). Neither locates anybody, and treating them as
            // strippable would force a re-encode that makes the file bigger for
            // no privacy gain. They still go when a re-encode happens anyway.
            if ($marker >= 0xE1 && $marker <= 0xEF) {
                return true;
            }

            $size = unpack('n', substr($bytes, $i + 2, 2))[1] ?? 0;

            if ($size < 2) {
                return false;                     // malformed; stop guessing
            }

            $i += 2 + $size;
        }

        return false;
    }

    /* ---------- geometry, shared by both drivers ---------- */

    /**
     * The largest centred box inside WxH that has the target aspect ratio.
     * Cropping before scaling is what keeps a portrait from being squashed.
     *
     * @return array{0: int, 1: int}
     */
    public static function coverCrop(int $sw, int $sh, int $tw, int $th): array
    {
        $target = $tw / $th;

        return ($sw / $sh) > $target
            ? [max(1, (int) round($sh * $target)), $sh]
            : [$sw, max(1, (int) round($sw / $target))];
    }

    /**
     * Where that crop lands after scaling. Capped at the crop's own size, so a
     * small original is cropped to the right shape and left at its own
     * resolution rather than enlarged into invented detail.
     *
     * @return array{0: int, 1: int}
     */
    public static function coverTarget(int $cw, int $ch, int $tw, int $th): array
    {
        return $cw <= $tw ? [$cw, $ch] : [$tw, $th];
    }

    /**
     * Long edge scaled down to $maxEdge, aspect preserved. Returns the source
     * size unchanged when it is already within bounds — never an upscale.
     *
     * @return array{0: int, 1: int}
     */
    public static function containTarget(int $sw, int $sh, int $maxEdge): array
    {
        $long = max($sw, $sh);

        if ($long <= $maxEdge) {
            return [$sw, $sh];
        }

        $scale = $maxEdge / $long;

        return [max(1, (int) round($sw * $scale)), max(1, (int) round($sh * $scale))];
    }

    private static function quality(): int
    {
        return (int) config('dashboard.image_quality', 82);
    }

    /**
     * The canonical file is re-encoded only to strip metadata, so it is kept
     * closer to the source than the derivatives are.
     */
    private static function originalQuality(): int
    {
        return (int) config('dashboard.image_original_quality', 90);
    }
}
