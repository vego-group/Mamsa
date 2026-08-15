<?php

declare(strict_types=1);

namespace App\Support\AdminPanel;

use App\Http\Controllers\AdminPanel\Concerns\MapsSpec;
use App\Models\Booking;
use App\Models\Unit;
use App\Support\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the admin-panel Unit / UnitDetail shapes
 * (BACKEND_SPEC §5.6, §6). Reused by UnitsController and ApprovalsController
 * (whose ApprovalDetail embeds a full UnitDetail).
 */
class UnitPresenter
{
    use MapsSpec;

    public const OCCUPANCY_WINDOW = 90;

    /** Query with per-unit aggregates (bookings/revenue/rating/occupancy). */
    public function baseQuery(): Builder
    {
        $since = now()->subDays(self::OCCUPANCY_WINDOW)->toDateString();

        return Unit::query()->with(['owner', 'images'])
            ->withCount(['bookings as bookings_count'])
            ->withSum(['bookings as revenue' => fn ($q) => $q->whereIn('status', Booking::REVENUE_STATUSES)], 'total_amount')
            ->withAvg(['reviews as rating'], 'rating')
            ->withCount('reviews as reviews_count')
            ->addSelect(['booked_nights' => Booking::query()
                ->selectRaw($this->nightsSql())
                ->whereColumn('unit_id', 'units.id')
                ->whereIn('status', Booking::REVENUE_STATUSES)
                ->where('start_date', '>=', $since)]);
    }

    /** Spec UnitStatus → internal approval_status (for filtering). */
    public function internalStatus(string $spec): string
    {
        return match ($spec) {
            'pending_review' => 'pending',
            'approved'       => 'approved',
            'rejected'       => 'rejected',
            'draft'          => 'draft',
            default          => $spec,
        };
    }

    /** @return array<string, mixed> Unit (list row) — §6. */
    public function card(Unit $u): array
    {
        $mamsaOwned = (bool) $u->mamsa_owned;

        return [
            'id'             => (string) $u->id,
            'code'           => $u->code ?: $this->code('UNT', $u->id),
            'name'           => $u->unit_name,
            'partnerId'      => (string) $u->user_id,
            'partnerName'    => $mamsaOwned ? 'ممسى' : ($u->owner?->name ?? 'ممسى'),
            'city'           => $u->city ?? '',
            'district'       => $u->district ?? '',
            'type'           => $this->unitType($u->unit_type),
            'status'         => $this->unitStatus($u->approval_status),
            'pricePerNight'  => (float) $u->price,
            'bedrooms'       => (int) $u->bedrooms,
            'bathrooms'      => (int) $u->bathrooms,
            'capacity'       => (int) $u->capacity,
            'sizeSqm'        => (float) $u->area,
            'rating'         => $u->rating !== null ? round((float) $u->rating, 1) : 0.0,
            'reviewsCount'   => (int) $u->reviews_count,
            'occupancyRate'  => min(100, (int) round(((int) $u->booked_nights / self::OCCUPANCY_WINDOW) * 100)),
            'revenue'        => $this->money($u->revenue),
            'bookingsCount'  => (int) $u->bookings_count,
            'coverImage'     => $this->coverImage($u),
            'mamsaOwned'     => $mamsaOwned,
            'rejectionReason'=> $u->rejection_reason,
            'approvedAt'     => $u->approval_status === 'approved' ? $this->iso($u->updated_at) : null,
        ];
    }

    /**
     * @return array<string, mixed> ApprovalRequest (queue row) — §6. A request is
     * a unit awaiting review; submittedAt uses submitted_at. Expects owner loaded.
     */
    public function approvalRow(Unit $u): array
    {
        $owner = $u->owner;

        return [
            'id'                => (string) $u->id,
            'code'              => $this->code('REQ', $u->id),
            'unitId'            => (string) $u->id,
            'unitName'          => $u->unit_name,
            'unitType'          => $this->unitType($u->unit_type),
            // Cover photo so the queue is scannable; falls back to the shared
            // default image, so this is never null.
            'coverImage'        => $this->coverImage($u),
            'city'              => $u->city ?? '',
            'partnerId'         => (string) ($u->user_id ?? ''),
            'partnerName'       => $owner?->name ?? '',
            'partnerType'       => $owner?->partnerDetail?->type ?? 'individual',
            // True submission time where known; updated_at is the historical
            // proxy for rows that predate the submitted_at column.
            'submittedAt'       => $this->iso($u->submitted_at ?? $u->updated_at),
            'requestType'       => $u->rejection_reason ? 'resubmission' : 'new',
            'previousRejection' => $u->rejection_reason
                ? ['reason' => $u->rejection_reason, 'at' => $this->iso($u->updated_at)]
                : null,
        ];
    }

    /** @return array<string, mixed> UnitDetail — §6. Expects features + owner.partnerDetail loaded. */
    public function detail(Unit $u): array
    {
        return array_merge($this->card($u), [
            'description'     => $u->description ?? '',
            'images'          => $this->images($u),
            'amenities'       => $u->relationLoaded('features') ? $u->features->pluck('name')->filter()->values()->all() : [],
            'lat'             => $u->lat !== null ? (float) $u->lat : 0.0,
            'lng'             => $u->lng !== null ? (float) $u->lng : 0.0,
            'publicUrl'       => $this->publicUrl($u),
            'tourismPermitNo' => $u->tourism_permit_no,
            'permitFileUrl'   => $this->fileUrl($u->tourism_permit_file),
            'ownerIdNumber'   => $u->owner?->partnerDetail?->national_id,
        ]);
    }

    private function coverImage(Unit $u): string
    {
        $img = $u->images->firstWhere('is_main', true) ?? $u->images->first();

        return $img && filled($img->path) && $img->path !== Media::defaultImagePath()
            ? $img->url
            : Media::defaultImageUrl();
    }

    /** @return array<int, string> */
    private function images(Unit $u): array
    {
        $imgs = $u->images
            ->filter(fn ($i) => filled($i->path) && $i->path !== Media::defaultImagePath())
            ->map(fn ($i) => $i->url)->values()->all();

        return $imgs !== [] ? $imgs : [Media::defaultImageUrl()];
    }

    private function publicUrl(Unit $u): ?string
    {
        $base = rtrim((string) env('FRONTEND_URL', ''), '/');

        return ($base !== '' && $u->approval_status === 'approved') ? "{$base}/units/{$u->id}" : null;
    }

    private function fileUrl(?string $path): ?string
    {
        // permit column stores a DashboardUpload id (file_...) → resolve to its
        // real public path (NOT the id used as a path, which 404/403s).
        return \App\Models\DashboardUpload::resolveUrl($path);
    }
}
