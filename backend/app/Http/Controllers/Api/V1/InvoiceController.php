<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Support\ZatcaQr;
use Illuminate\Http\JsonResponse;

/**
 * Tax invoice for a guest's own booking — contract §7.1.
 *
 * Mamsa is the supplier of record (§1.5), so the invoice is issued in Mamsa's
 * name and the host never appears as the supplier. Every figure is read from
 * the booking's FROZEN split, never recomputed, so an invoice reprinted a year
 * later is byte-identical to the one issued at the time.
 *
 * `qrCode` is null until a real ZATCA VAT registration number is configured.
 * That is deliberate: a QR encoding a placeholder VAT number would look valid
 * and fail at the tax authority, which is worse than an absent one. The client
 * shows a "preparing" state and starts rendering the code automatically the
 * moment the configuration lands — no frontend deploy required.
 */
class InvoiceController extends Controller
{
    /** GET /api/v1/bookings/{booking}/invoice */
    public function show(Booking $booking): JsonResponse
    {
        if ($booking->user_id !== auth()->id()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        // A tax invoice documents a supply that was actually paid for. An
        // unpaid or cancelled booking has no invoice to issue.
        if (! in_array($booking->status, Booking::REVENUE_STATUSES, true)) {
            return response()->json([
                'message' => 'الفاتورة الضريبية متاحة بعد إتمام الدفع فقط',
                'code'    => 'INVOICE_NOT_AVAILABLE',
            ], 409);
        }

        $booking->load(['unit', 'user', 'payment']);

        $netBase = round((float) ($booking->subtotal ?? 0), 2);
        $vat     = round((float) ($booking->taxes ?? 0), 2);
        $gross   = round((float) $booking->total_amount, 2);
        $vatRate = round((float) ($booking->tax_percent ?? 0) / 100, 4);

        $issuedAt = ($booking->payment?->paid_at ?? $booking->created_at);
        $seller   = (array) config('invoice.seller');

        return response()->json([
            'invoiceNumber' => $this->number($booking),
            'issuedAt'      => $issuedAt?->toIso8601String(),

            // Server-owned: a change to the company's registration details
            // updates every future invoice without a frontend release.
            'seller' => [
                'name'      => (string) ($seller['name'] ?? ''),
                'vatNumber' => (string) ($seller['vat_number'] ?? ''),
                'crNumber'  => (string) ($seller['cr_number'] ?? ''),
                'address'   => (string) ($seller['address'] ?? ''),
            ],

            'buyerName' => (string) ($booking->user?->name ?? ''),

            'lines' => [[
                'description' => $this->lineDescription($booking),
                'checkIn'     => $booking->start_date?->toDateString(),
                'checkOut'    => $booking->end_date?->toDateString(),
                'nights'      => $booking->nights,
                'netBase'     => $netBase,
                'vatRate'     => $vatRate,
                'vat'         => $vat,
                'gross'       => $gross,
            ]],

            'totalNetBase' => $netBase,
            'totalVat'     => $vat,
            'totalGross'   => $gross,
            'currency'     => (string) config('invoice.currency', 'SAR'),

            // null until a real VAT number exists — see the class docblock.
            'qrCode' => ZatcaQr::forInvoice(
                sellerName: (string) ($seller['name'] ?? ''),
                vatNumber: (string) ($seller['vat_number'] ?? ''),
                issuedAt: $issuedAt,
                totalGross: $gross,
                totalVat: $vat,
            ),
        ]);
    }

    /**
     * Deterministic, gap-free per booking: the same booking always yields the
     * same number, so no sequence table is needed and a reprint never differs.
     */
    private function number(Booking $booking): string
    {
        $prefix = (string) config('invoice.number_prefix', 'INV');
        $at     = $booking->created_at ?? now();

        return sprintf('%s-%s-%s-%06d', $prefix, $at->format('Y'), $at->format('m'), $booking->id);
    }

    /** Arabic-primary line description — ZATCA requires Arabic content. */
    private function lineDescription(Booking $booking): string
    {
        $unit = $booking->unit;
        $where = collect([$unit?->district, $unit?->city])->filter()->implode('، ');

        return 'إقامة — '.($unit?->unit_name ?? 'وحدة').($where !== '' ? '، '.$where : '');
    }
}
