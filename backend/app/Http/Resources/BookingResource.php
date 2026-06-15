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
            'unit'         => $this->whenLoaded('unit', fn () => new UnitResource($this->unit)),
            'start_date'   => $this->start_date?->toDateString(),
            'end_date'     => $this->end_date?->toDateString(),
            'nights'       => $this->nights,
            'guests'       => $this->guests,
            'total_amount' => $this->total_amount,
            'status'       => $this->status,
            'status_label' => $this->statusLabel(),
            'notes'        => $this->notes,
            'payment'      => $this->whenLoaded('payment', fn () => [
                'id'             => $this->payment?->id,
                'payment_method' => $this->payment?->payment_method,
                'payment_status' => $this->payment?->payment_status,
                'amount'         => $this->payment?->amount,
                'paid_at'        => $this->payment?->paid_at?->toISOString(),
            ]),
            'review'       => $this->whenLoaded('review', fn () => $this->review ? [
                'id'      => $this->review->id,
                'rating'  => $this->review->rating,
                'comment' => $this->review->comment,
            ] : null),
            'created_at'   => $this->created_at?->toISOString(),
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'قيد الانتظار',
            'confirmed' => 'مؤكد',
            'cancelled' => 'ملغى',
            default     => $this->status,
        };
    }
}
