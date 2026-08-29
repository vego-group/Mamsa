<?php

declare(strict_types=1);

namespace App\Support\AdminPanel;

use App\Http\Controllers\AdminPanel\Concerns\MapsSpec;
use App\Models\Booking;
use App\Models\Unit;
use App\Support\Dashboard\Maps;
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
            // Null when the unit has no photo of its own — the browse surfaces
            // render a quiet placeholder rather than a shared stock image, so
            // "no photography" stays visible wherever a unit is listed.
            'coverImage'     => $this->realCoverImage($u),
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
            // Null when the listing has no photo of its own: the reviewer needs
            // to see that, and a shared default would hide it (frontend §3).
            'coverImage'        => $this->realCoverImage($u),
            'city'              => $u->city ?? '',
            'partnerId'         => (string) ($u->user_id ?? ''),
            // A Mamsa-owned listing has no partner: `user_id` is the admin who
            // created it. Showing their personal name here would put a staff
            // member in the queue as though they were an applicant — and the
            // units list already reads 'ممسى' for the same row, so a reviewer
            // saw two different owners for one unit.
            'partnerName'       => $u->mamsa_owned ? 'ممسى' : ($owner?->name ?? ''),
            'partnerType'       => $u->mamsa_owned ? 'mamsa' : ($owner?->partnerDetail?->type ?? 'individual'),
            'mamsaOwned'        => (bool) $u->mamsa_owned,
            // True submission time where known; updated_at is the historical
            // proxy for rows that predate the submitted_at column.
            'submittedAt'       => $this->iso($u->submitted_at ?? $u->updated_at),
            'requestType'       => $u->rejection_reason ? 'resubmission' : 'new',
            'previousRejection' => $u->rejection_reason
                ? ['reason' => $u->rejection_reason, 'at' => $this->iso($u->updated_at)]
                : null,
        ];
    }

    /**
     * @return array<string, mixed> UnitDetail — §6. Expects features +
     *                              owner.partnerDetail loaded.
     *
     * Everything `PATCH /admin/units/{id}` accepts must be readable here, or an
     * edit form cannot show an admin what they are about to change — and a
     * field it renders from a default rather than from the record is a screen
     * stating something untrue about the unit.
     */
    public function detail(Unit $u): array
    {
        $u->loadMissing('cancellationPolicy');

        return array_merge($this->card($u), [
            'description'     => $u->description ?? '',
            'images'          => $this->images($u),
            // Same photos, re-sendable: `id` is the upload id that goes back in
            // `photoFileIds`, so an edit can add one photo without replacing the
            // gallery. `images` stays as display URLs and is not going away.
            'photos'          => $this->photos($u),
            'amenities'       => $u->relationLoaded('features') ? $u->features->pluck('name')->filter()->values()->all() : [],
            // The write side takes KEYS (`wifi`); `amenities` above are the
            // stored Arabic labels. Without this the round trip needs a
            // hardcoded label→key table on the client, which drifts the day a
            // label is reworded.
            'amenityKeys'     => Maps::amenitiesToKeys($u->relationLoaded('features') ? $u->features->pluck('name') : collect()),
            // `city` is the stored Arabic label. The slug is here so a locale
            // toggle doesn't have to match on the label.
            'cityKey'         => Maps::cityToSlug($u->city ?? ''),
            'address'         => $u->address,
            'beds'            => $u->beds !== null ? (int) $u->beds : null,
            'checkIn'         => self::hm($u->checkin_time),
            'checkOut'        => self::hm($u->checkout_time),
            // Preset slug. A unit that never chose one inherits the platform
            // default, so echo what the engine would actually apply rather than
            // null — null would be read as "no policy".
            'cancellationPolicy' => $u->cancellationPolicy?->key ?? self::defaultPolicyKey(),
            'lat'             => $u->lat !== null ? (float) $u->lat : 0.0,
            'lng'             => $u->lng !== null ? (float) $u->lng : 0.0,
            'publicUrl'       => $this->publicUrl($u),
            'tourismPermitNo' => $u->tourism_permit_no,
            'permitFileUrl'   => $this->fileUrl($u->tourism_permit_file),
            // Proof of the right to list. Same resolver: the column holds
            // either an upload id or a storage path depending on which
            // surface sent it, and resolveUrl() reads both.
            'ownershipDocUrl' => $this->fileUrl($u->ownership_doc_file),
            // The id, not the URL — this is what goes back in the write body.
            'tourismLicenseFileId' => $u->tourism_permit_file,
            'ownershipDocFileId'   => $u->ownership_doc_file,
            'ownerIdNumber'   => $u->owner?->partnerDetail?->national_id,
        ]);
    }

    /**
     * Photos in a form an edit can send back.
     *
     * `id` is the upload id from the presign flow. It is **null** for a row
     * written before that flow existed: such a photo has no re-sendable
     * identity, so a client merging a gallery must fall back to "this replaces
     * everything" for that unit. There are currently no such rows on either
     * server.
     *
     * Placeholder rows are excluded for the same reason as {@see images()} — a
     * reviewer must see "no photos" as no photos.
     *
     * @return array<int, array{id: ?string, url: string, isCover: bool}>
     */
    private function photos(Unit $u): array
    {
        $real  = $u->images->filter(fn ($i) => filled($i->path) && $i->path !== Media::defaultImagePath());
        $cover = $real->firstWhere('is_main', true) ?? $real->first();

        // Same derivative set the storefront gets — the approvals queue renders
        // these as small thumbnails too, and was downloading full photographs
        // for every row.
        return $real
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn ($i) => [
                'id'       => filled($i->file_id) ? $i->file_id : null,
                'url'      => $i->url,
                'isCover'  => $cover && $i->id === $cover->id,
                'width'    => $i->width !== null ? (int) $i->width : null,
                'height'   => $i->height !== null ? (int) $i->height : null,
                'variants' => $i->variant_urls,
            ])->values()->all();
    }

    /** Per-request memo so a unit list doesn't re-query the default N times. */
    private static ?string $defaultPolicyKey = null;

    private static function defaultPolicyKey(): ?string
    {
        return self::$defaultPolicyKey ??= \App\Models\CancellationPolicy::query()
            ->orderByDesc('is_default')->value('key');
    }

    /** `15:00:00` / a Carbon time → `15:00`, the format the write side takes. */
    private static function hm(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        return substr((string) $time, 0, 5) ?: null;
    }

    /**
     * The unit's own photo, or null when it has none.
     *
     * "Has no photos" is review-relevant, so the reviewer queue must be able to
     * show absence as absence — a shared default there would make empty
     * listings look photographed and identical rows look alike anyway.
     */
    private function realCoverImage(Unit $u): ?string
    {
        $img = $u->images->firstWhere('is_main', true) ?? $u->images->first();

        return $img && filled($img->path) && $img->path !== Media::defaultImagePath()
            ? $img->url
            : null;
    }

    /**
     * The unit's own photos — EMPTY when it has none, never padded with the
     * shared default.
     *
     * The approval detail page gates its Approve button behind a "photos
     * reviewed" checklist step. A placeholder made a photoless listing look
     * photographed, so a reviewer could tick that step and approve a listing
     * with no photos onto the public site — defeating the control meant to
     * prevent exactly that.
     *
     * @return array<int, string>
     */
    private function images(Unit $u): array
    {
        return $u->images
            ->filter(fn ($i) => filled($i->path) && $i->path !== Media::defaultImagePath())
            ->map(fn ($i) => $i->url)->values()->all();
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
