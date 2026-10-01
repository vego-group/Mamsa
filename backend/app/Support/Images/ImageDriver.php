<?php

declare(strict_types=1);

namespace App\Support\Images;

/**
 * The raster operations {@see ImageProcessor} needs, in the smallest surface
 * that both available backends can honour.
 *
 * Two implementations exist because the environments differ: production has
 * ImageMagick (and therefore HEIC), the test container has GD only. Coding to
 * whichever happens to be installed would mean the pipeline is untested where
 * it actually runs.
 *
 * Handles are opaque — an `Imagick` or a `GdImage` — and must be released with
 * {@see destroy()} by the caller that created them.
 */
interface ImageDriver
{
    public function name(): string;

    /** Can this backend decode HEIC/HEIF (what iPhones shoot by default)? */
    public function supportsHeic(): bool;

    /**
     * Decode raw bytes. Returns null when the bytes are not a readable image —
     * the magic-byte check upstream proves the header, not the whole file.
     */
    public function load(string $bytes): mixed;

    /** @return array{0: int, 1: int} width, height */
    public function dimensions(mixed $image): array;

    /**
     * Bake the EXIF orientation flag into the pixels and drop the flag.
     *
     * Browsers honour the flag on a plain <img>, so an untouched upload looks
     * right today. The moment we re-encode it stops being true: the tag is
     * gone but the pixels never moved. Every derivative would be sideways.
     */
    public function autoOrient(mixed $image, string $bytes): mixed;

    /** Centre-crop to the given aspect and scale down to fit. Never enlarges. */
    public function cover(mixed $image, int $width, int $height): mixed;

    /** Scale the long edge down to $maxEdge. Never enlarges, never crops. */
    public function contain(mixed $image, int $maxEdge): mixed;

    /** Encode to WebP. Metadata is never carried over. */
    public function toWebp(mixed $image, int $quality): ?string;

    /** Encode to JPEG. Metadata is never carried over. */
    public function toJpeg(mixed $image, int $quality): ?string;

    /** Encode to PNG, keeping the alpha channel. Metadata is never carried over. */
    public function toPng(mixed $image): ?string;

    public function destroy(mixed $image): void;
}
