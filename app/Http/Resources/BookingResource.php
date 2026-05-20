<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'unit'         => new UnitResource($this->whenLoaded('unit')),
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'nights'       => $this->start_date && $this->end_date
                                ? $this->start_date->diffInDays($this->end_date)
                                : null,
            'total_amount' => (float) $this->total_amount,
            'status'       => $this->status,
            'status_label' => $this->status_label,
            'notes'        => $this->notes,
            'payment'      => $this->whenLoaded('payment', fn () => [
                'status'    => $this->payment?->payment_status,
                'method'    => $this->payment?->payment_method,
                'paid_at'   => $this->payment?->paid_at?->toIso8601String(),
            ]),
            'created_at'   => $this->created_at->toIso8601String(),
        ];
    }
}
