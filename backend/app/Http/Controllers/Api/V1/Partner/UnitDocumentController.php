<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\DashboardUpload;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
    /** Column per document type — the request never names a column directly. */
    private const COLUMNS = [
        'tourism_permit' => 'tourism_permit_file',
        'ownership_doc'  => 'ownership_doc_file',
    ];

    /** POST /partner/units/{unit}/documents */
    public function store(Request $request, Unit $unit): JsonResponse
    {
        $this->authorizeUnit($request, $unit);

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(self::COLUMNS))],
            // Images as well as PDF: a deed or licence is photographed at least
            // as often as it is scanned, and the dashboard's own rules for these
            // kinds allow both. A PDF-only rule here would reject the common case.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'], // 10 MB
        ], [
            'type.required' => 'نوع المستند مطلوب.',
            'type.in'       => 'نوع المستند غير صحيح.',
            'file.required' => 'اختر ملفاً.',
            'file.mimes'    => 'الصيغ المسموحة: pdf, jpg, png, webp.',
            'file.max'      => 'حجم الملف يجب ألا يتجاوز 10 ميجابايت.',
        ]);

        $column = self::COLUMNS[$data['type']];

        // Replacing a document removes the previous file, but ONLY when the old
        // value is a path this surface wrote. A `file_...` id belongs to a
        // DashboardUpload row that the dashboard owns and may reference
        // elsewhere, so deleting its bytes from here would break that surface.
        $previous = $unit->{$column};

        $path = $request->file('file')->store("units/{$unit->id}/docs", 'public');
        $unit->update([$column => $path]);

        if (filled($previous) && ! str_starts_with((string) $previous, 'file_')) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json([
            'data' => [
                'type' => $data['type'],
                'path' => $path,
                'url'  => DashboardUpload::resolveUrl($path),
            ],
        ], 201);
    }

    /** DELETE /partner/units/{unit}/documents/{type} */
    public function destroy(Request $request, Unit $unit, string $type): JsonResponse
    {
        $this->authorizeUnit($request, $unit);

        abort_unless(isset(self::COLUMNS[$type]), 404, 'نوع المستند غير صحيح');

        $column = self::COLUMNS[$type];
        $value  = $unit->{$column};

        if (filled($value) && ! str_starts_with((string) $value, 'file_')) {
            Storage::disk('public')->delete($value);
        }

        $unit->update([$column => null]);

        return response()->json(['message' => 'تم حذف المستند']);
    }

    private function authorizeUnit(Request $request, Unit $unit): void
    {
        abort_if($unit->user_id !== $request->user()->id, 403, 'غير مصرح');
    }
}
