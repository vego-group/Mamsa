<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminPanel;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AdminProfile — BACKEND_SPEC §6. camelCase keys, exactly as the frontend types
 * expect. `role` is the literal 'superadmin' the TS union permits.
 *
 * @mixin User
 */
class AdminProfileResource extends JsonResource
{
    /**
     * The admin-panel contract returns bare objects — no `data` envelope
     * (lists use { items, ... }; details/profile are the object itself).
     */
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => (string) $this->id,
            'name'            => $this->name ?? '',
            'email'           => $this->email ?? '',
            'phone'           => (string) $this->phone,
            'role'            => 'superadmin',
            'verified'        => true,
            'memberSince'     => optional($this->created_at)->toIso8601String(),
            // Not yet tracked per-admin (no reviewer audit trail) — honest zeros
            // rather than fabricated counts. Wire when an admin action log exists.
            'totalReviews'    => 0,
            'actionsToday'    => 0,
            'preferredLocale' => in_array($this->preferred_locale, ['ar', 'en'], true)
                ? $this->preferred_locale
                : 'ar',
        ];
    }
}
