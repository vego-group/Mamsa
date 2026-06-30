<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'title'            => $this->title,
            'subtitle'         => $this->subtitle,
            'discount_percent' => $this->discount_percent,
            'image_url'        => $this->image_url,
            'valid_until'      => $this->valid_until?->toDateString(),
            // Pre-formatted Arabic line for the card, e.g. "ساري حتى 31 أغسطس 2024".
            'valid_until_label' => $this->valid_until
                ? 'ساري حتى ' . $this->valid_until->locale('ar')->translatedFormat('j F Y')
                : null,
        ];
    }
}
