<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'phone'              => $this->phone,
            'email'              => $this->email,
            'email_verified'     => ! is_null($this->email_verified_at),
            'is_active'          => (bool) $this->is_active,
            'roles'              => $this->roles->pluck('name'),
            'is_admin'           => $this->isAdmin(),
            'profile_complete'   => ! blank($this->name),
        ];
    }
}
