<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Who is allowed to run a building, and how big it may be.
 *
 * A permit is issued to a FACILITY, not to a bed: a partner with ten serviced
 * apartments holds one permit covering all ten. Two kinds exist, and the
 * difference decides whether a listing may become a building at all —
 *
 *   tourist_facility     a whole property operated as one commercial concern.
 *                        May hold many apartments.
 *   private_hospitality  one home someone owns and lets to visitors. Covers
 *                        exactly one, by definition.
 *
 * That is a legal limit, not a preference, so it is enforced here rather than
 * in a form: every path that can grow a group asks this class first.
 *
 * WHY THE LICENCE LIVES ON EVERY ROW, NOT ON A PARENT
 *
 * A group has no parent. `unit_group_id` is a shared ULID and deliberately not
 * a foreign key — see the 2026_08_30 migration: keying a group off the first
 * unit's id would let deleting apartment 401 orphan the other ninety-nine. So
 * there is no single row to hang a permit on, and the licence is copied to
 * every member instead, with this class as the only writer. Writes go to the
 * whole group in one transaction, so the members cannot disagree about the
 * permit they operate under — a group with two different values would mean an
 * apartment trading under a licence that does not cover it.
 *
 * This is NOT the same question as `copy_documents` on the cloner. A permit
 * FILE may belong to one apartment, which is why copying it is opt-in. A permit
 * TYPE is a property of the facility: one building cannot be both kinds.
 */
final class UnitLicense
{
    public const TOURIST_FACILITY = 'tourist_facility';

    public const PRIVATE_HOSPITALITY = 'private_hospitality';

    public const TYPES = [self::TOURIST_FACILITY, self::PRIVATE_HOSPITALITY];

    /**
     * Set only while this class is writing the licence columns.
     *
     * The Unit model refuses an UPDATE that touches them unless this is on, so
     * `$unit->update(['license_type' => …])` from some future controller fails
     * loudly instead of leaving one apartment claiming a permit its siblings do
     * not have. Creation is deliberately NOT guarded: a new row is a group of
     * one, and the cloner copies from the source it is cloning, so neither can
     * introduce a disagreement.
     *
     * This cannot see query-builder updates — `Unit::where(...)->update(...)`
     * fires no model events. That is precisely why the daily consistency check
     * exists as well; the two guards catch different mistakes.
     */
    private static bool $writing = false;

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /** @template T @param callable(): T $write @return T */
    public static function write(callable $write): mixed
    {
        self::$writing = true;

        try {
            return $write();
        } finally {
            self::$writing = false;
        }
    }

    /**
     * Groups whose members disagree about their permit.
     *
     * Should always be empty. A row here means an apartment is trading under a
     * licence that may not cover it, which is the failure this whole class is
     * built to prevent — so it is worth asking about every day rather than
     * trusting that no future path ever writes these columns another way.
     *
     * @return array<int, object{unit_group_id: string, types: int, counts: int}>
     */
    public static function inconsistentGroups(): array
    {
        return DB::table('units')
            ->selectRaw('unit_group_id')
            ->selectRaw('COUNT(DISTINCT license_type) AS types')
            ->selectRaw('COUNT(DISTINCT licensed_units_count) AS counts')
            ->whereNotNull('unit_group_id')
            ->groupBy('unit_group_id')
            ->havingRaw('COUNT(DISTINCT license_type) > 1 OR COUNT(DISTINCT licensed_units_count) > 1')
            ->get()
            ->all();
    }

    /** How many rows this listing's group holds — 1 for a standalone unit. */
    public static function groupSize(Unit $unit): int
    {
        return $unit->unit_group_id
            ? Unit::where('unit_group_id', $unit->unit_group_id)->count()
            : 1;
    }

    /**
     * The pair is coherent on its own terms.
     *
     * Checked before anything is written, and mirrored by a CHECK constraint —
     * the constraint catches a stray write, this returns an answer a partner
     * can act on.
     */
    public static function guardFields(?string $type, ?int $count): void
    {
        if ($type === self::TOURIST_FACILITY && $count === null) {
            throw LicenseViolation::of(
                'LICENSED_UNITS_COUNT_REQUIRED',
                'تصريح المرفق السياحي يتطلب عدد الوحدات المرخّصة',
            );
        }

        // A private-hospitality permit covers one unit, so a count on it is
        // either meaningless or a misreading of the form. Rejected rather than
        // ignored: silently dropping a number the partner typed is how they end
        // up believing a limit is in force that is not.
        if ($type === self::PRIVATE_HOSPITALITY && $count !== null && $count !== 1) {
            throw LicenseViolation::of(
                'LICENSED_UNITS_COUNT_NOT_APPLICABLE',
                'تصريح الضيافة الخاصة يغطي وحدة واحدة فقط',
                ['max_licensed_units_count' => 1],
            );
        }
    }

    /**
     * May this listing be (or become) a group of `$size`?
     *
     * `$size` is the size AFTER the operation, so the caller works it out with
     * whatever it already knows — the cloner computes it before writing, and
     * this stays a pure check.
     */
    public static function guardGroupSize(Unit $unit, int $size): void
    {
        if ($size <= 1) {
            return; // a standalone listing needs no facility permit
        }

        if (! config('units.multi_unit_enabled')) {
            throw LicenseViolation::of(
                'MULTI_UNIT_DISABLED',
                'إضافة أكثر من وحدة غير مفعّلة حالياً',
            );
        }

        if ($unit->license_type !== self::TOURIST_FACILITY) {
            throw LicenseViolation::of(
                'MULTI_UNIT_REQUIRES_FACILITY_LICENSE',
                'أكثر من وحدة يتطلب تصريح مرفق ضيافة سياحي',
                ['license_type' => $unit->license_type, 'max_units' => 1],
            );
        }

        $licensed = $unit->licensed_units_count;

        if ($licensed !== null && $size > (int) $licensed) {
            throw LicenseViolation::of(
                'QUANTITY_EXCEEDS_LICENSED_UNITS',
                'العدد المطلوب أكبر من عدد الوحدات المرخّصة في التصريح',
                ['requested' => $size, 'licensed_units_count' => (int) $licensed],
            );
        }
    }

    /**
     * Write the licence across the whole group, or refuse.
     *
     * Downgrading a building to a single-unit permit is blocked while it still
     * HAS more than one apartment: the apartments do not disappear when the
     * permit changes, and allowing it would leave rows trading under a licence
     * that does not cover them. The partner reduces the building first.
     *
     * @param  array<string, mixed>  $attributes  the licence fields being written
     */
    public static function applyToGroup(Unit $unit, array $attributes): void
    {
        $type = array_key_exists('license_type', $attributes)
            ? $attributes['license_type'] : $unit->license_type;
        $count = array_key_exists('licensed_units_count', $attributes)
            ? $attributes['licensed_units_count'] : $unit->licensed_units_count;

        self::guardFields($type, $count === null ? null : (int) $count);

        $size = self::groupSize($unit);

        if ($size > 1 && $type !== self::TOURIST_FACILITY) {
            // The message deliberately does NOT say "reduce the units first".
            // There is no route that reduces a building: the expansion endpoint
            // treats a smaller count as a no-op, and the dashboard only deletes
            // DRAFT units — every apartment in an approved building fails that
            // check. Telling a partner to do something the product cannot do is
            // worse than a plain refusal, because they will go looking for a
            // button that is not there. Shrinking is a backlog item; until it
            // exists this says who can help.
            throw LicenseViolation::of(
                'LICENSE_DOWNGRADE_BLOCKED_BY_QUANTITY',
                'هذا الإعلان مبنى متعدد الوحدات، ولا يمكن تحويله إلى تصريح ضيافة خاصة. تواصل مع الدعم لتعديل عدد وحدات المبنى.',
                ['group_size' => $size, 'shrink_supported' => false],
            );
        }

        if ($size > 1 && $count !== null && $size > (int) $count) {
            throw LicenseViolation::of(
                'QUANTITY_EXCEEDS_LICENSED_UNITS',
                'عدد الوحدات الحالي أكبر من العدد المرخّص',
                ['group_size' => $size, 'licensed_units_count' => (int) $count],
            );
        }

        $write = [
            'license_type' => $type,
            'licensed_units_count' => $count,
        ];

        if (! $unit->unit_group_id) {
            self::write(fn () => $unit->forceFill($write)->save());

            return;
        }

        // One statement, so the group cannot be observed half-updated — and no
        // path exists that writes these columns to a single member.
        DB::transaction(function () use ($unit, $write) {
            Unit::where('unit_group_id', $unit->unit_group_id)->update($write);
            $unit->forceFill($write)->syncOriginal();
        });
    }
}
