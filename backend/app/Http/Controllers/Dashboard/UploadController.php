<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\DashboardUpload;
use App\Support\Images\ImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Two-step upload (contract §9.1). No S3 on shared hosting, so "presign"
 * returns a short-lived SIGNED URL to our own PUT endpoint rather than an S3
 * URL — the client flow is identical. All validation (type via magic bytes,
 * size) happens server-side on receipt; the client MIME is never trusted.
 */
class UploadController extends DashboardController
{
    /**
     * kind → what the bytes are allowed to be.
     *
     * `images` means any format {@see ImageProcessor} can decode here — jpeg,
     * png, webp, and heic wherever ImageMagick is installed. HEIC matters
     * because it is what an iPhone shoots by default; it used to be refused
     * outright with a message naming png/jpg, which reads as "your photo is
     * broken" rather than "convert it first".
     *
     * `derive` marks the kinds that get the fixed-size derivative set. A KYC
     * document is read, not browsed, so it is normalised (orientation, EXIF)
     * but never resized — shrinking an ID scan costs legibility for nothing.
     */
    private const RULES = [
        'unit_photo'  => ['images' => true,  'pdf' => false, 'derive' => true],
        'license_pdf' => ['images' => false, 'pdf' => true,  'derive' => false],
        // Images allowed as well as PDF: a KYC document is usually PHOTOGRAPHED,
        // not scanned to PDF — and registration already accepts jpg/png for the
        // identity scan, so a PDF-only rule here left partners who registered
        // earlier unable to supply the same file through the dashboard.
        'company_doc' => ['images' => true,  'pdf' => true,  'derive' => false],
    ];

    public function presign(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'kind'     => ['required', 'in:'.implode(',', DashboardUpload::KINDS)],
            'fileName' => ['required', 'string', 'max:255'],
            'mimeType' => ['required', 'string', 'max:100'],
            'size'     => ['required', 'integer', 'min:1', 'max:'.config('dashboard.upload_max_bytes')],
        ]);

        $upload = DashboardUpload::create([
            'id'            => 'file_'.Str::lower((string) Str::ulid()),
            'user_id'       => $request->user()->id,
            'kind'          => $data['kind'],
            'original_name' => $data['fileName'],
            'mime'          => $data['mimeType'],
            'size'          => $data['size'],
            'status'        => 'pending',
        ]);

        $uploadUrl = URL::temporarySignedRoute('pd.uploads.receive', now()->addMinutes(30), ['upload' => $upload->id]);

        return $this->ok(['uploadUrl' => $uploadUrl, 'fileId' => $upload->id]);
    }

    /** Signed PUT target — the raw bytes land here. */
    public function receive(Request $request, string $upload): JsonResponse
    {
        $record = DashboardUpload::find($upload);
        if (! $record) {
            $this->fail('UPLOAD_NOT_FOUND', 'ملف غير موجود', 404);
        }
        if ($record->status === 'stored') {
            $this->fail('UPLOAD_USED', 'تم رفع هذا الملف مسبقاً', 409);
        }

        $bytes = $request->getContent();
        $size  = strlen($bytes);

        if ($size === 0) {
            $this->fail('EMPTY_FILE', 'الملف فارغ', 400);
        }
        if ($size > (int) config('dashboard.upload_max_bytes')) {
            $this->fail('FILE_TOO_LARGE', 'حجم الملف يتجاوز الحد المسموح (10MB)', 400);
        }

        $rules  = self::RULES[$record->kind];
        $isPdf  = str_starts_with($bytes, '%PDF');
        $format = $isPdf ? null : ImageProcessor::detect($bytes);

        if (! ($isPdf && $rules['pdf']) && ! ($format !== null && $rules['images'])) {
            $this->fail('INVALID_FILE_TYPE', 'نوع الملف غير صالح — مسموح: '.self::label($rules), 400);
        }

        // Extension follows the BYTES, not the kind: a company_doc may now be a
        // photo, and storing a PNG as .pdf would make it unopenable.
        $meta = ['status' => 'stored', 'size' => $size];
        $ext  = 'pdf';

        if ($format !== null) {
            [$bytes, $ext, $meta] = $this->prepareImage($record, $bytes, $format, $rules['derive']);
            $size = strlen($bytes);
        }

        $path = "dashboard/{$record->kind}/{$record->id}.{$ext}";
        Storage::disk('public')->put($path, $bytes);

        if ($rules['derive']) {
            // Best effort: a listing whose photos could not be optimised is
            // still a listing. The client falls back to `url`, and
            // `images:process` can pick the file up later.
            $meta['variants'] = ImageProcessor::derivatives(
                $bytes, "dashboard/{$record->kind}", $record->id,
            ) ?: null;
        }

        $record->update($meta + ['status' => 'stored', 'path' => $path, 'size' => $size]);

        return $this->ok(['fileId' => $record->id, 'url' => Storage::disk('public')->url($path)]);
    }

    /**
     * Decode-and-rewrite step every accepted image goes through.
     *
     * The re-encode is not an optimisation — it is how EXIF gets removed.
     * Camera metadata carries GPS to within a few metres and these files are
     * served publicly, so an untouched upload publishes the property's real
     * position regardless of the address on the listing.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>} bytes, extension, column updates
     */
    private function prepareImage(DashboardUpload $record, string $bytes, string $format, bool $enforceSize): array
    {
        if ($format === 'heic' && ! ImageProcessor::driver()?->supportsHeic()) {
            $this->fail('INVALID_FILE_TYPE', 'صيغة HEIC غير مدعومة على هذا الخادم — حوّل الصورة إلى JPEG', 400);
        }

        $normalised = ImageProcessor::normalise($bytes, $format);

        // A file whose header says "image" but which will not decode is a
        // broken image, and storing it only moves the failure to the guest's
        // browser where nobody can act on it.
        if (! $normalised) {
            $this->fail('INVALID_FILE_TYPE', 'تعذّرت قراءة الصورة — حاول رفعها بصيغة JPEG', 400);
        }

        if ($enforceSize) {
            $this->assertLargeEnough($normalised['width'], $normalised['height']);
        }

        return [$normalised['bytes'], $normalised['ext'], [
            'width'  => $normalised['width'],
            'height' => $normalised['height'],
        ]];
    }

    /**
     * Long/short edge rather than width/height, so the rule does not reject
     * every portrait photo — which would be the one shape a phone produces
     * most often, and the shape the full-screen viewer exists to handle.
     */
    private function assertLargeEnough(int $width, int $height): void
    {
        $minLong  = (int) config('dashboard.image_min_long_edge');
        $minShort = (int) config('dashboard.image_min_short_edge');

        if (max($width, $height) < $minLong || min($width, $height) < $minShort) {
            $this->fail(
                'IMAGE_TOO_SMALL',
                "دقة الصورة منخفضة ({$width}×{$height}) — الحد الأدنى {$minLong}×{$minShort}",
                400,
            );
        }
    }

    /** @param array<string, mixed> $rules */
    private static function label(array $rules): string
    {
        $parts = $rules['pdf'] ? ['pdf'] : [];

        if ($rules['images']) {
            $parts = array_merge($parts, ImageProcessor::acceptedFormats());
        }

        return implode('/', $parts);
    }
}
