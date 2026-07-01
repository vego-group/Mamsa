<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestimonialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'role'       => $this->role,
            'quote'      => $this->quote,
            'avatar_url' => $this->avatar_url,
            'rating'     => $this->rating,
            'deal'       => $this->deal,
        ];
    }
}
