<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\Unit;

/**
 * Maps a Unit model to the partner-dashboard contract shape (§4). Kept separate
 * from the public-site UnitResource, which speaks a different (Arabic-value)
 * shape for the storefront.
 */
class UnitPresenter
{
    public static function make(Unit $unit): array
    {
        $unit->loadMissing(['images', 'features']);

        $cover = $unit->images->firstWhere('is_main', true) ?? $unit->images->first();

        // Prefer eager-loaded aggregates (withCount/withAvg) to avoid N+1 on
        // list endpoints; fall back to the model accessors for single fetches.
        $reviewsCount = $unit->reviews_count ?? $unit->reviews()->count();
        $avgRating    = $unit->reviews_avg_rating ?? $unit->avg_rating;

        return [
            'id'                   => 'u_'.$unit->id,
            'code'                 => $unit->code,
            'name'                 => $unit->unit_name,
            'type'                 => $unit->unit_type,
            'status'               => $unit->approval_status,
            // Draft fields can be null (partial body) — don't coerce to 0.
            'pricePerNight'        => $unit->price !== null ? (float) $unit->price : null,
            // Per-unit, partner-editable; column default 0 so never null.
            'cleaningFee'          => (float) $unit->cleaning_fee,
            'bedrooms'             => $unit->bedrooms !== null ? (int) $unit->bedrooms : null,
            'capacity'             => $unit->capacity !== null ? (int) $unit->capacity : null,
            'bathrooms'            => $unit->bathrooms !== null ? (int) $unit->bathrooms : null,
            'rating'               => $reviewsCount > 0 ? round((float) $avgRating, 1) : null,
            'reviewsCount'         => (int) $reviewsCount,
            'city'                 => Maps::cityToSlug($unit->city),
            'district'             => $unit->district,
            'description'          => $unit->description,
            'amenities'            => Maps::amenitiesToKeys($unit->features->pluck('name')),
            'checkIn'              => self::hm($unit->checkin_time),
            'checkOut'             => self::hm($unit->checkout_time),
            'lat'                  => $unit->lat !== null ? (float) $unit->lat : null,
            'lng'                  => $unit->lng !== null ? (float) $unit->lng : null,
            'address'              => $unit->address,
            'tourismLicenseNumber' => $unit->tourism_permit_no,
            'tourismLicenseFileId' => $unit->tourism_permit_file,
            'photos'               => $unit->images->map(fn ($img) => [
                // The source fileId (stable, re-sendable in photoFileIds on edit)
                // when the photo came via the presign flow; else the row id.
                'id'      => $img->file_id ?: 'ph'.$img->id,
                'url'     => $img->url,
                'isCover' => $cover && $img->id === $cover->id,
            ])->values(),
            'rejectionReason'      => $unit->approval_status === 'rejected' ? $unit->rejection_reason : null,
            'publicUrl'            => $unit->approval_status === 'approved'
                ? rtrim((string) config('dashboard.public_site_url'), '/').'/units/'.$unit->code
                : null,
            'updatedAt'            => $unit->updated_at?->toIso8601ZuluString(),
        ];
    }

    private static function hm(mixed $time): ?string
    {
        if (! $time) {
            return null;
        }

        return substr((string) $time, 0, 5); // "15:00:00" → "15:00"
    }
}
