<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->unit_name,
            'type'                => $this->unit_type,
            'code'                => $this->code,
            'price'               => $this->price,
            'capacity'            => $this->capacity,
            'bedrooms'            => $this->bedrooms,
            'bathrooms'           => $this->bathrooms,
            'area'                => $this->area,
            'city'                => $this->city,
            'district'            => $this->district,
            'lat'                 => $this->lat,
            'lng'                 => $this->lng,
            'description'         => $this->description,
            'checkin_time'        => $this->checkin_time,
            'checkout_time'       => $this->checkout_time,
            'cancellation_policy' => $this->cancellation_policy,
            'status'              => $this->status,
            'approval_status'     => $this->approval_status,
            'rejection_reason'    => $this->when(
                in_array($this->approval_status, ['rejected']),
                $this->rejection_reason
            ),
            'images'              => $this->whenLoaded('images', fn () =>
                $this->images->map(fn ($img) => [
                    'id'      => $img->id,
                    'url'     => $img->url,
                    'is_main' => $img->is_main,
                ])
            ),
            'features'            => $this->whenLoaded('features', fn () =>
                $this->features->pluck('name')
            ),
            'avg_rating'          => round((float) $this->reviews()->avg('rating'), 1),
            'reviews_count'       => $this->reviews()->count(),
            'owner'               => $this->whenLoaded('owner', fn () => [
                'id'   => $this->owner->id,
                'name' => $this->owner->name,
            ]),
        ];
    }
}
