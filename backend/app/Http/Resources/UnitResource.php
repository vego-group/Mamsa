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
            // FR-021 — the unit's LIVE tiered policy for pre-booking display
            // (unit page / checkout). Same shape as the booking's
            // policy_snapshot (minus checkin_at) and same default-policy
            // fallback as the snapshot builder, so what the guest sees before
            // paying is exactly what gets frozen at payment. Only emitted
            // where the relation is eager-loaded (booking-embedded units use
            // policy_snapshot instead).
            'cancellation_policy_details' => $this->when(
                $this->relationLoaded('cancellationPolicy'),
                fn () => $this->policyDetails(),
            ),
            'status'              => $this->status,
            'approval_status'     => $this->approval_status,
            'rejection_reason'    => $this->when(
                in_array($this->approval_status, ['rejected']),
                $this->rejection_reason
            ),
            'images'              => $this->whenLoaded('images', function () {
                // Real photos only — ignore the generic default placeholder rows.
                $real = $this->images->filter(
                    fn ($img) => filled($img->path) && $img->path !== \App\Support\Media::defaultImagePath()
                );

                if ($real->isNotEmpty()) {
                    return $real->values()->map(fn ($img) => [
                        'id'      => $img->id,
                        'url'     => $img->url,
                        'is_main' => (bool) $img->is_main,
                    ]);
                }

                // No real photo yet → the single bundled default image.
                return [[
                    'id'      => 0,
                    'url'     => \App\Support\Media::defaultImageUrl(),
                    'is_main' => true,
                ]];
            }),
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

    /**
     * @return array{template: ?string, name: ?string, tiers: array<int, array<string, mixed>>}|null
     */
    private function policyDetails(): ?array
    {
        // Units without an assigned policy inherit the platform default —
        // mirrors CancellationPolicyService::snapshotForBooking().
        $policy = $this->cancellationPolicy ?? self::defaultPolicy();

        if (! $policy) {
            return null;
        }

        return [
            'template' => $policy->key,
            'name'     => $policy->name_ar,
            'tiers'    => $policy->tiers->map(fn ($t) => [
                'min_hours_before_checkin' => (int) $t->min_hours_before_checkin,
                'refund_percent'           => (int) $t->refund_percent,
                'label'                    => $t->label_ar,
            ])->values()->all(),
        ];
    }

    /** Per-request memo so unit lists don't re-query the default policy N times. */
    private static ?\App\Models\CancellationPolicy $defaultPolicy = null;

    private static function defaultPolicy(): ?\App\Models\CancellationPolicy
    {
        return self::$defaultPolicy ??= \App\Models\CancellationPolicy::with('tiers')
            ->orderByDesc('is_default')
            ->first();
    }
}
