<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'phone'            => $this->phone,
            'email'            => $this->email,
            'email_verified'   => ! is_null($this->email_verified_at),
            'is_active'        => (bool) $this->is_active,
            'roles'            => $this->getRoleNames(),
            'permissions'      => $this->when(
                $request->is('api/v1/auth/me')
                    || $request->is('api/v1/auth/verify-otp')
                    || $request->is('api/v1/auth/admin/login')
                    || $request->is('api/v1/auth/partner/register'),
                fn () => $this->getAllPermissions()->pluck('name')
            ),
            'is_admin'         => $this->isAdmin(),
            'is_partner'       => $this->isPartner(),
            // Partner application review state (pending/approved/rejected).
            'partner_status'   => $this->when($this->isPartner(), fn () => $this->partnerDetail?->status),
            // individual | company — distinguishes the two partner kinds.
            'partner_type'     => $this->when($this->isPartner(), fn () => $this->partnerDetail?->type),
            // No avatar storage yet — null so the UI keeps its initials fallback.
            'avatar_url'       => null,
            'profile_complete' => ! blank($this->name),
        ];
    }
}
