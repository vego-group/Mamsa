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
                $request->is('api/v1/auth/me') || $request->is('api/v1/auth/verify-otp'),
                fn () => $this->getAllPermissions()->pluck('name')
            ),
            'is_admin'         => $this->isAdmin(),
            'is_partner'       => $this->isPartner(),
            'profile_complete' => ! blank($this->name),
        ];
    }
}
