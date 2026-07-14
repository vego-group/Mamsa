<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\DashboardUpload;
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
    /** kind → [allowed extensions label, magic-byte prefixes]. */
    private const RULES = [
        'unit_photo'  => ['png/jpg', ["\x89PNG", "\xFF\xD8\xFF"]],
        'license_pdf' => ['pdf', ["%PDF"]],
        'company_doc' => ['pdf', ["%PDF"]],
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

        [$label, $magics] = self::RULES[$record->kind];
        if (! self::matchesMagic($bytes, $magics)) {
            $this->fail('INVALID_FILE_TYPE', "نوع الملف غير صالح — مسموح: {$label}", 400);
        }

        $ext  = $record->kind === 'unit_photo'
            ? (str_starts_with($bytes, "\x89PNG") ? 'png' : 'jpg')
            : 'pdf';
        $path = "dashboard/{$record->kind}/{$record->id}.{$ext}";

        Storage::disk('public')->put($path, $bytes);

        $record->update(['status' => 'stored', 'path' => $path, 'size' => $size]);

        return $this->ok(['fileId' => $record->id, 'url' => Storage::disk('public')->url($path)]);
    }

    /** @param array<int, string> $magics */
    private static function matchesMagic(string $bytes, array $magics): bool
    {
        foreach ($magics as $magic) {
            if (str_starts_with($bytes, $magic)) {
                return true;
            }
        }

        return false;
    }
}
