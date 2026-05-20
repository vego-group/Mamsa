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
            'price'               => (float) $this->price,
            'capacity'            => $this->capacity,
            'bedrooms'            => $this->bedrooms,
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
            'images'              => $this->whenLoaded('images', fn () =>
                $this->images->map(fn ($img) => [
                    'id'  => $img->id,
                    'url' => asset('storage/'.$img->path),
                ])
            ),
            'features'            => $this->whenLoaded('features', fn () =>
                $this->features->pluck('name')
            ),
            'avg_rating'          => $this->whenLoaded('reviews', fn () =>
                round($this->reviews->avg('rating'), 1)
            ),
            'reviews_count'       => $this->whenLoaded('reviews', fn () =>
                $this->reviews->count()
            ),
        ];
    }
}
