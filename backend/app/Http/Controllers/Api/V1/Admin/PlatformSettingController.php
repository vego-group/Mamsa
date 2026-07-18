<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Pricing;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Owner decision 2026-07-18: cleaning/service fees abolished, so nothing here
 * is editable anymore — the PATCH surface was removed entirely. Kept as a
 * read-only endpoint (per the frontend's own suggestion) exposing the legal
 * VAT rate for any future settings screen.
 */
class PlatformSettingController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        return $this->success([
            'tax_percent' => Pricing::taxPercent(),
        ]);
    }
}
