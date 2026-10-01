<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\DashboardUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Two-step upload for the admin console — the same flow the partner dashboard
 * runs (contract §9.1), on an admin session.
 *
 * There is no S3 on shared hosting, so "presign" hands back a short-lived
 * SIGNED URL to our own PUT endpoint. That receiving endpoint is shared with
 * the partner dashboard on purpose: it is where the real security lives (magic
 * bytes, size cap, single-use), and a second copy of it would be a second place
 * for those checks to rot. The signature is the authorisation, so the route
 * needs no session of its own.
 *
 * Uploads are owned by the acting ADMIN (`user_id`), and the unit write path
 * only accepts fileIds owned by the same user — so one admin cannot attach
 * another's pending upload.
 */
class UploadsController extends Controller
{
    /** The kinds an admin needs. `company_doc` is partner KYC and stays theirs. */
    private const KINDS = ['unit_photo', 'license_pdf'];

    /** POST /admin/uploads/presign */
    public function presign(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'kind'     => ['required', 'in:'.implode(',', self::KINDS)],
            'fileName' => ['required', 'string', 'max:255'],
            // Sent for convenience, never trusted: the real type check is done
            // on the received BYTES.
            'mimeType' => ['required', 'string', 'max:100'],
            'size'     => ['required', 'integer', 'min:1', 'max:'.config('dashboard.upload_max_bytes')],
        ], [
            'kind.required'     => 'نوع الملف مطلوب',
            'kind.in'           => 'نوع الملف غير مدعوم',
            'fileName.required' => 'اسم الملف مطلوب',
            'size.max'          => 'حجم الملف يتجاوز الحد المسموح (10MB)',
        ]);

        $upload = DashboardUpload::create([
            'id'            => 'file_'.Str::lower((string) Str::ulid()),
            'user_id'       => $request->user()->getKey(),
            'kind'          => $data['kind'],
            'original_name' => $data['fileName'],
            'mime'          => $data['mimeType'],
            'size'          => $data['size'],
            'status'        => 'pending',
        ]);

        return response()->json([
            'uploadUrl' => URL::temporarySignedRoute('pd.uploads.receive', now()->addMinutes(30), ['upload' => $upload->id]),
            'fileId'    => $upload->id,
        ], 201);
    }
}
