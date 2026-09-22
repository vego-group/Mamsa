<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Models\Unit;
use App\Support\Permits\PermitWriter;
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
     * The write barrier lives in {@see PermitWriter}, which owns all four permit
     * columns (number, file, type, count) rather than only the two licence
     * ones. These two remain so the model guard and older callers read the same
     * way — they are the same switch.
     */
    public static function isWriting(): bool
    {
        return PermitWriter::isWriting();
    }

    /** @see PermitWriter::write() */
    public static function write(callable $write): mixed
    {
        return PermitWriter::write($write);
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

    /**
     * Units whose mirrored columns disagree with their scope's current permit.
     *
     * The model guard stops Eloquent writes; a query-builder update, a seeder
     * or a hand-run SQL statement it cannot see. Asked every day for the same
     * reason inconsistentGroups() is: a row here is trading under something
     * other than its permit.
     *
     * @return array<int, object{unit_id: int, permit_id: int, column: string, unit_value: ?string, permit_value: ?string}>
     */
    public static function mirrorMismatches(): array
    {
        $out = [];

        \App\Models\Permit::query()->current()->orderBy('id')->chunk(200, function ($permits) use (&$out) {
            foreach ($permits as $permit) {
                foreach ($permit->units()->get(['id', ...array_values(\App\Models\Permit::MIRROR)]) as $unit) {
                    foreach (\App\Models\Permit::MIRROR as $own => $column) {
                        if ((string) $unit->{$column} !== (string) $permit->{$own}) {
                            $out[] = (object) [
                                'unit_id' => (int) $unit->id,
                                'permit_id' => (int) $permit->id,
                                'column' => $column,
                                'unit_value' => $unit->{$column} === null ? null : (string) $unit->{$column},
                                'permit_value' => $permit->{$own} === null ? null : (string) $permit->{$own},
                            ];
                        }
                    }
                }
            }
        });

        return $out;
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

        self::guardLicenceCovers($unit, $size);
    }

    /**
     * The legal half of {@see guardGroupSize()} alone: does this listing's
     * permit cover a group of `$size`?
     *
     * Separated from the rollout flag because the two answer different
     * questions. The flag says whether PARTNERS may expand yet — a product
     * switch, off on production until their UI ships. The permit says whether
     * a building of this size may trade at all — a legal fact that holds
     * regardless of any switch. The platform expanding its own inventory, and
     * a reviewer re-approving an apartment that already exists, need the
     * second answer and are not the rollout the first one gates.
     */
    public static function guardLicenceCovers(Unit $unit, int $size): void
    {
        if ($size <= 1) {
            return;
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
     * Is changing the licence to (`$type`, `$count`) legal for the size this
     * listing's group already has?
     *
     * Downgrading a building to a single-unit permit is blocked while it still
     * HAS more than one apartment: the apartments do not disappear when the
     * permit changes, and allowing it would leave rows trading under a licence
     * that does not cover them. The partner reduces the building first.
     */
    public static function guardChange(Unit $unit, ?string $type, ?int $count): void
    {
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

        if ($size > 1 && $count !== null && $size > $count) {
            throw LicenseViolation::of(
                'QUANTITY_EXCEEDS_LICENSED_UNITS',
                'عدد الوحدات الحالي أكبر من العدد المرخّص',
                ['group_size' => $size, 'licensed_units_count' => $count],
            );
        }
    }

    /**
     * Write the licence across the whole group, or refuse.
     *
     * Kept under the name every caller knows; the write itself is the permit
     * writer's — the licence type and count are two fields of the scope's
     * permit and travel with it. {@see PermitWriter::apply()}
     *
     * @param  array<string, mixed>  $attributes  the licence fields being written
     */
    public static function applyToGroup(Unit $unit, array $attributes): void
    {
        $changes = [];

        foreach (['license_type', 'licensed_units_count'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $changes[$key] = $attributes[$key];
            }
        }

        PermitWriter::apply($unit, $changes);
    }
}
