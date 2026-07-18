<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Pricing;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-wide pricing knobs (frontend contract 2026-07-18).
 * GET  — any admin (also what the superadmin settings screen renders).
 * PATCH — SuperAdmin only (route-level role gate); edits service_fee_percent
 * exclusively. tax_percent is the legal VAT rate: read-only everywhere,
 * `prohibited` here so an attempted edit fails loudly instead of silently.
 */
class PlatformSettingController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        return $this->success($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_percent'         => ['prohibited'],
        ]);

        Pricing::setServiceFeePercent((float) $data['service_fee_percent']);

        return $this->success($this->payload(), 'تم تحديث الإعدادات');
    }

    /** @return array{service_fee_percent: float, tax_percent: float} */
    private function payload(): array
    {
        return [
            'service_fee_percent' => Pricing::serviceFeePercent(),
            'tax_percent'         => Pricing::taxPercent(),
        ];
    }
}
