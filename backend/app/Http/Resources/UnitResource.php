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
            // How many apartments in this building are bookable. 1 for a
            // standalone listing, so a client can read it unconditionally.
            // Present only where the controller computed it -- a resource that
            // guessed would be guessing about availability.
            'available_count'     => $this->whenNotNull($this->available_count),
            'price'               => $this->price,
            'capacity'            => $this->capacity,
            'bedrooms'            => $this->bedrooms,
            'beds'                => $this->beds,
            'bathrooms'           => $this->bathrooms,
            'area'                => $this->area,
            'city'                => $this->city,
            'district'            => $this->district,
            'lat'                 => $this->lat,
            'lng'                 => $this->lng,
            'description'         => $this->description,
            'checkin_time'        => $this->checkin_time,
            'checkout_time'       => $this->checkout_time,
            // The EFFECTIVE preset key, not the dead `cancellation_policy` enum
            // column it used to echo. That column only ever held `no_cancel` /
            // `48_hours` — neither of which is a policy the refund engine knows
            // — so a client using it as a pre-payment fallback showed the guest
            // a refund schedule the platform would never honour.
            //
            // Always one of the preset keys, and always the policy that would
            // actually be applied. Same value as
            // `cancellation_policy_details.template`.
            'cancellation_policy' => $this->effectivePolicyKey(),
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
            'is_featured'         => (bool) $this->is_featured,
            // Uniform KSA VAT rate applied to every unit (15%). Exposed so the
            // storefront never hardcodes it.
            'tax_percent'         => \App\Support\Pricing::taxPercent(),
            // Needed for a `newest` sort to mean anything client-side, and so a
            // "new listing" badge is read from the record rather than invented.
            'created_at'          => $this->created_at?->toIso8601ZuluString(),
            'approval_status'     => $this->approval_status,
            'rejection_reason'    => $this->when(
                in_array($this->approval_status, ['rejected']),
                $this->rejection_reason
            ),
            // Compliance paperwork — the licence number, the CR number and the
            // two document links. NEVER public: a title deed carries the owner's
            // name and the property's registry details, and the licence number
            // is not ours to publish either. Visible only to the partner who
            // owns the unit and to admins.
            $this->mergeWhen(
                // `$request->user()` resolves the DEFAULT guard, which on this
                // public route is never populated — the token is a sanctum one.
                // Reading it that way made the block invisible to the owner too,
                // so the fields silently never appeared for anyone.
                // Default guard FIRST. Asking for 'sanctum' resolves that guard
                // and caches its user on the app instance; in tests the instance
                // is reused across requests, so resolving it during one request
                // left a stale user authenticated for the next and turned a
                // later admin call into a 403. Production makes a fresh app per
                // request and never saw it — the suite did.
                ($u = $request->user() ?: $request->user('sanctum'))
                    && ($u->id === $this->user_id || $u->isAdmin()),
                fn () => [
                    // Gated with the compliance fields, NOT public: the exact
                    // street of an occupied home is not something a guest needs
                    // before booking, and the public payload stays byte-identical.
                    // The edit form needs it, so the owner must get it back —
                    // without this a saved address reloads blank and looks lost.
                    'address'             => $this->address,
                    // Multi-unit building membership. Gated with the rest, not
                    // because a door number is a secret, but because the PUBLIC
                    // payload is a contract the frontend has signed off at
                    // exactly 30 keys — and the guest card shows the building,
                    // not the door. The partner's own list needs both to tell a
                    // hundred otherwise identical listings apart.
                    'unit_group_id'       => $this->unit_group_id,
                    'apartment_no'        => $this->apartment_no,
                    'tourism_permit_no'   => $this->tourism_permit_no,
                    'company_license_no'  => $this->company_license_no,
                    'tourism_permit_url'  => \App\Models\DashboardUpload::resolveUrl($this->tourism_permit_file),
                    'ownership_doc_url'   => \App\Models\DashboardUpload::resolveUrl($this->ownership_doc_file),
                    // Partner-scoped, surfaced here so the unit form can show
                    // whether it is already on file without a second request.
                    'bank_certificate_url' => \App\Models\DashboardUpload::resolveUrl(
                        $this->owner?->partnerDetail?->bank_certificate_file,
                    ),
                ],
            ),

            'images'              => $this->whenLoaded('images', function () {
                // Real photos only — ignore the generic default placeholder rows.
                $real = $this->images->filter(
                    fn ($img) => filled($img->path) && $img->path !== \App\Support\Media::defaultImagePath()
                );

                if ($real->isNotEmpty()) {
                    // Partner-controlled order. Falls back to id for rows
                    // written before sort_order existed, which is the order
                    // they were already coming back in.
                    return $real
                        ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                        ->values()
                        ->map(fn ($img) => [
                            'id'      => $img->id,
                            'url'     => $img->url,
                            'is_main' => (bool) $img->is_main,
                            // Null until the derivative set exists (legacy rows,
                            // or a file the processor could not read). Clients
                            // fall back to `url`.
                            'width'    => $img->width !== null ? (int) $img->width : null,
                            'height'   => $img->height !== null ? (int) $img->height : null,
                            'variants' => $img->variant_urls,
                        ]);
                }

                // No real photo yet → the single bundled default image.
                return [[
                    'id'       => 0,
                    'url'      => \App\Support\Media::defaultImageUrl(),
                    'is_main'  => true,
                    'width'    => null,
                    'height'   => null,
                    'variants' => null,
                ]];
            }),
            // Legacy Arabic-string list (kept for existing consumers)…
            'features'            => $this->whenLoaded('features', fn () =>
                $this->features->pluck('name')
            ),
            // …and the structured form: stable `key` (null → generic icon) + label.
            'amenities'           => $this->whenLoaded('features', fn () =>
                \App\Support\Dashboard\Maps::amenityPairs($this->features->pluck('name'))
            ),
            // Prefer the eager-loaded aggregates. Computing these per row cost
            // TWO queries per unit, so a 50-item page ran 100 extra queries to
            // produce two numbers.
            //
            // `avg_rating` is 0 (never null) when there are no reviews, and
            // `reviews_count` is the real count — so a client can tell "unrated"
            // from "rated zero" by reading the count, not the average.
            'avg_rating'          => round((float) ($this->reviews_avg_rating ?? $this->reviews()->avg('rating')), 1),
            'reviews_count'       => (int) ($this->reviews_count ?? $this->reviews()->count()),
            'owner'               => $this->whenLoaded('owner', fn () => [
                'id'          => $this->owner->id,
                'name'        => $this->owner->name,
                // individual | company — companies were showing as "مالك فردي".
                'type'        => $this->owner->partnerDetail?->type ?? 'individual',
                'is_verified' => $this->owner->partnerDetail?->status === \App\Models\PartnerDetail::STATUS_APPROVED,
                // No avatar storage yet — null so the UI keeps its initials fallback.
                'avatar_url'  => null,
            ]),
        ];
    }

    /**
     * The preset the refund engine would apply: the unit's own, else the
     * platform default. Mirrors CancellationPolicyService::snapshotForBooking().
     */
    private function effectivePolicyKey(): ?string
    {
        $policy = $this->relationLoaded('cancellationPolicy')
            ? ($this->cancellationPolicy ?? self::defaultPolicy())
            : ($this->cancellationPolicy()->first() ?? self::defaultPolicy());

        return $policy?->key;
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
