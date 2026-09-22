<?php

declare(strict_types=1);

namespace App\Support\Permits;

use App\Models\Permit;
use App\Models\Unit;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitLicense;
use Illuminate\Support\Collection;

/**
 * Which of the two ways a building can be licensed.
 *
 *  - **single_permit** (mode B): one `tourist_facility` permit issued to the
 *    property, covering every door. The permit is scoped to the group, the
 *    apartments mirror it, and `licensed_units_count` caps how many there may
 *    be. This is what shipped in phases 1–3.
 *
 *  - **per_unit** (mode A): every apartment carries its OWN permit — its own
 *    number, file and expiry, scoped to that one row. A private-hospitality
 *    permit names one specific unit, so a building of them is not one licence
 *    covering five doors; it is five licences that happen to share an address.
 *
 * The mode is not a column. It is read from the licence type the group already
 * carries, because that is the fact a document establishes and a column would
 * only restate — and a second source of truth for the same thing is how a
 * group ends up disagreeing with itself.
 *
 * A group has ONE mode. Mixing is refused rather than resolved: an apartment
 * under the building's facility permit sitting beside one under its own private
 * permit is two different legal claims about the same building, and no code can
 * pick between them.
 */
final class PermitMode
{
    public const SINGLE = 'single_permit';

    public const PER_UNIT = 'per_unit';

    /** The mode this listing's group is in, or null when nothing says yet. */
    public static function of(Unit $unit): ?string
    {
        return match ($unit->license_type) {
            UnitLicense::TOURIST_FACILITY => self::SINGLE,
            UnitLicense::PRIVATE_HOSPITALITY => self::PER_UNIT,
            default => null,
        };
    }

    /**
     * Refuse a request whose shape does not match the mode the group is in.
     *
     * `permits` present means "a permit per new apartment", which is only
     * meaningful in per-unit mode; absent means "they are covered by the
     * building's permit", which is only meaningful in single-permit mode. The
     * refusal names the group's mode so a client can correct the form rather
     * than guess which half was wrong.
     */
    public static function guardShape(Unit $unit, bool $permitsGiven): void
    {
        $mode = self::of($unit);

        if ($mode === null) {
            throw LicenseViolation::of(
                'MULTI_UNIT_REQUIRES_FACILITY_LICENSE',
                'صنّف تصريح الوحدة أولاً قبل إضافة وحدات',
                ['license_type' => null, 'max_units' => 1],
            );
        }

        if ($mode === self::SINGLE && $permitsGiven) {
            throw LicenseViolation::of(
                'PERMIT_MODE_MIXED',
                'هذا المبنى تحت تصريح واحد يغطي كل الوحدات — لا تُرسل تصاريح منفصلة',
                ['group_mode' => self::SINGLE],
            );
        }

        if ($mode === self::PER_UNIT && ! $permitsGiven) {
            throw LicenseViolation::of(
                'PERMIT_MODE_MIXED',
                'كل وحدة في هذا المبنى تحتاج تصريحها الخاص — أرسل تصريحاً لكل وحدة جديدة',
                ['group_mode' => self::PER_UNIT],
            );
        }
    }

    /**
     * The permits list must name exactly the apartments being created.
     *
     * `requested` is the number of NEW apartments, not the total asked for:
     * a partner going from three doors to five owes two permits, and telling
     * them "you sent 2 of 5" would send them looking for three more documents
     * they do not need.
     *
     * @param  array<int, mixed>  $permits
     */
    public static function guardCount(int $newApartments, array $permits): void
    {
        if (count($permits) !== $newApartments) {
            throw LicenseViolation::of(
                'PERMITS_COUNT_MISMATCH',
                'عدد التصاريح المرسلة لا يساوي عدد الوحدات الجديدة',
                ['requested' => $newApartments, 'permits_provided' => count($permits)],
            );
        }
    }

    /**
     * How many doors this listing may legally hold.
     *
     * In single-permit mode the facility permit's own count is the ceiling. In
     * per-unit mode there is no ceiling from any one document — each apartment
     * brings its own licence — so the only limit is the cloner's.
     */
    public static function ceiling(Unit $unit): ?int
    {
        return self::of($unit) === self::SINGLE
            ? ($unit->licensed_units_count === null ? null : (int) $unit->licensed_units_count)
            : null;
    }

    /** Is this unit one door of a per-unit building? */
    public static function isPerUnitMember(Unit $unit): bool
    {
        return $unit->unit_group_id !== null && self::of($unit) === self::PER_UNIT;
    }

    /**
     * The permit rows a per-unit group holds — one per door that has one.
     *
     * @return Collection<int, Permit>
     */
    public static function permitsOf(Unit $unit): Collection
    {
        if (! $unit->unit_group_id) {
            return collect(array_filter([Permit::currentFor($unit)]));
        }

        $ids = Unit::where('unit_group_id', $unit->unit_group_id)->pluck('id')->map(strval(...))->all();

        return Permit::query()
            ->where('scope_type', Permit::SCOPE_UNIT)
            ->whereIn('scope_id', $ids)
            ->current()
            ->get();
    }
}
