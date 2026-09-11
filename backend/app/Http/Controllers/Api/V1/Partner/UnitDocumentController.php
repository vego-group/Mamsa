<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\DashboardUpload;
use App\Models\Unit;
use App\Support\Documents\DocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Partner unit documents on the v1 surface — the tourism licence and the
 * ownership proof (title deed or lease contract).
 *
 * The Next.js dashboard attaches these through the two-step presign flow
 * (POST /uploads/presign → PUT /uploads/{id}), which mints a DashboardUpload
 * and stores its `file_...` id on the unit. That flow is cookie-session and
 * root-mounted, so the Bearer-token v1 clients could not reach it: a partner on
 * the Vue app had no way to attach the licence AT ALL, even though it is
 * required to submit a listing for review.
 *
 * This is the direct equivalent — one multipart request, no presign round trip.
 * It writes a storage PATH into the same column the dashboard fills with an id.
 * That is safe because DashboardUpload::resolveUrl() already branches on the
 * `file_` prefix and treats anything else as a path, so the admin panel renders
 * both without knowing which surface produced them.
 */
class UnitDocumentController extends Controller
{
    /**
     * Where each document lives. The request names a TYPE, never a column.
     *
     * `bank_certificate` is scoped to the partner, not the unit: one bank
     * account serves every listing they own, so storing it per unit would keep
     * duplicate copies that can drift apart. The upload is offered on the unit
     * form because that is where a partner is already attaching paperwork.
     */
    private const TYPES = [
        'tourism_permit' => ['scope' => 'unit',    'column' => 'tourism_permit_file'],
        'ownership_doc' => ['scope' => 'unit',    'column' => 'ownership_doc_file'],
        'bank_certificate' => ['scope' => 'partner', 'column' => 'bank_certificate_file'],
    ];

    /** POST /partner/units/{unit}/documents */
    public function store(Request $request, Unit $unit): JsonResponse
    {
        $this->authorizeUnit($request, $unit);

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            // Images as well as PDF: a deed or licence is photographed at least
            // as often as it is scanned, and the dashboard's own rules for these
            // kinds allow both. A PDF-only rule here would reject the common case.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'], // 10 MB
        ], [
            'type.required' => 'نوع المستند مطلوب.',
            'type.in' => 'نوع المستند غير صحيح.',
            'file.required' => 'اختر ملفاً.',
            'file.mimes' => 'الصيغ المسموحة: pdf, jpg, png, webp.',
            'file.max' => 'حجم الملف يجب ألا يتجاوز 10 ميجابايت.',
        ]);

        [$holder, $column] = $this->target($request, $unit, $data['type']);

        // Replacing a document removes the previous file, but ONLY when the old
        // value is a path this surface wrote. A `file_...` id belongs to a
        // DashboardUpload row that the dashboard owns and may reference
        // elsewhere, so deleting its bytes from here would break that surface.
        $previous = $holder->{$column};

        // Recorded as a DashboardUpload rather than a bare path.
        //
        // This surface used to store `units/12/docs/abc.pdf` straight into the
        // column. A bare path cannot be signed: the /documents route resolves an
        // upload row to decide who may read it, and a path has no owner to check.
        // So these documents could never move off the public disk while the
        // other surfaces did — the same file type protected or not depending on
        // which screen uploaded it.
        //
        // An id costs one row and makes every document in the system uniform.
        // Both resolveUrl() and signedUrl() already branch on the `file_` prefix,
        // so existing bare paths keep working untouched.
        $upload = $this->record($request, $unit, $data['type'], $request->file('file'));

        $holder->update([$column => $upload->id]);

        // The previous document is finished unless something else still points
        // at it. The old rule — never delete an id — was right when this surface
        // did not create ids; now that it does, keeping it would orphan a file
        // on every single replacement.
        DocumentStorage::forget($previous);

        return response()->json([
            'data' => [
                'type' => $data['type'],
                // `path` stays in the response for clients that read it, but it
                // is now the upload id — the value actually stored on the unit.
                'path' => $upload->id,
                'url' => DashboardUpload::signedUrl($upload->id),
            ],
        ], 201);
    }

    /**
     * Store the bytes in the vault and record the row that authorises them.
     *
     * `kind` maps the caller's document TYPE onto the storage kinds the rest of
     * the platform uses, so a permit uploaded here lands in the same directory,
     * under the same rules, as one uploaded from the dashboard.
     */
    private function record(Request $request, Unit $unit, string $type, UploadedFile $file): DashboardUpload
    {
        $kind = match ($type) {
            'tourism_permit' => 'license_pdf',
            'bank_certificate' => 'company_doc',
            default => 'ownership_doc',
        };

        $id = 'file_'.Str::ulid();
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'pdf');
        $path = "dashboard/{$kind}/{$id}.{$ext}";

        DocumentStorage::put($path, (string) file_get_contents($file->getRealPath()), sensitive: true);

        return DashboardUpload::create([
            'id' => $id,
            'user_id' => $request->user()->id,
            'kind' => $kind,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
            'status' => 'stored',
        ]);
    }

    /** DELETE /partner/units/{unit}/documents/{type} */
    public function destroy(Request $request, Unit $unit, string $type): JsonResponse
    {
        $this->authorizeUnit($request, $unit);

        abort_unless(isset(self::TYPES[$type]), 404, 'نوع المستند غير صحيح');

        [$holder, $column] = $this->target($request, $unit, $type);
        $value = $holder->{$column};

        // Clear the column FIRST. forget() only drops an upload nothing points
        // at, so running it while this column still held the id would find its
        // own reference and keep the file forever.
        $holder->update([$column => null]);

        DocumentStorage::forget($value);

        return response()->json(['message' => 'تم حذف المستند']);
    }

    /**
     * The record that stores this document, and the column on it.
     *
     * @return array{0: Model, 1: string}
     */
    private function target(Request $request, Unit $unit, string $type): array
    {
        $spec = self::TYPES[$type];

        if ($spec['scope'] === 'partner') {
            // firstOrCreate, not a bare relation read: a partner who has not
            // completed KYC yet still has paperwork to hand in, and failing on
            // a missing row would block them for no reason they could act on.
            $detail = $request->user()->partnerDetail()->firstOrCreate(
                ['user_id' => $request->user()->id],
                ['type' => 'individual'],
            );

            return [$detail, $spec['column']];
        }

        return [$unit, $spec['column']];
    }

    private function authorizeUnit(Request $request, Unit $unit): void
    {
        abort_if($unit->user_id !== $request->user()->id, 403, 'غير مصرح');
    }
}
