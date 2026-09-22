<?php

declare(strict_types=1);

namespace App\Support\Permits;

use App\Models\Permit;
use App\Models\Unit;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitLicense;
use Illuminate\Support\Facades\DB;

/**
 * The one place a permit is written.
 *
 * A permit covers a scope — one unit, or one whole group — and the four
 * permit columns on every unit in that scope are read copies of it. Two
 * writers to the same fact is how a building ends up with apartments
 * disagreeing about their permit (staging had exactly that: one door under
 * `TL-50000`, seven under `TL-DEMO-8UNITS`, all approved). So the unit model
 * refuses to let those columns change unless this class is doing it, and
 * every controller, command and clone path routes through here.
 *
 * Creation is the one exception, on purpose: a brand-new unit may be created
 * WITH its permit columns set (the wizard does this, and so does every test
 * fixture), and {@see adopt()} then materialises the permit row from them.
 * There is nothing for a new standalone row to disagree with; a new row
 * joining a group that already has a permit is checked by the model guard
 * and then made to mirror it.
 *
 * Phase 1 semantics: one `current` permit per scope, updated in place.
 * Renewal — a `pending` row beside the current one — is phase 3.
 */
final class PermitWriter
{
    /** The keys {@see apply()} accepts, and the permit column each maps to. */
    private const FIELDS = [
        'number' => 'number',
        'file' => 'file',
        'license_type' => 'license_type',
        'licensed_units_count' => 'licensed_units_count',
        'expires_at' => 'expires_at',
        'addr_city' => 'addr_city',
        'addr_district' => 'addr_district',
        'addr_building' => 'addr_building',
        'addr_unit_no' => 'addr_unit_no',
    ];

    private static bool $writing = false;

    /* ---------- the barrier ---------- */

    /**
     * True only while this class is writing mirrored columns. Unit::booted()
     * lets a write through when this is on and refuses it otherwise.
     */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /** Run `$fn` with the barrier lowered. Always restores it, even on failure. */
    public static function write(callable $fn): mixed
    {
        $previous = self::$writing;
        self::$writing = true;

        try {
            return $fn();
        } finally {
            self::$writing = $previous;
        }
    }

    /* ---------- writing ---------- */

    /**
     * Change the current permit of `$unit`'s scope.
     *
     * `$changes` holds any subset of FIELDS. Keys absent are left as they are;
     * a key present with null clears that field. When the result has neither
     * a number, a file nor a licence type, the permit row is removed and the
     * mirrors cleared — a listing with no permit has no permit row.
     *
     * The licence rules live in UnitLicense and are applied here BEFORE any
     * write: the pair must be coherent, and a change of type or count must be
     * legal for the size the group already has.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws LicenseViolation
     */
    public static function apply(Unit $unit, array $changes, ?int $actorId = null): ?Permit
    {
        $unknown = array_diff(array_keys($changes), array_keys(self::FIELDS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('PermitWriter::apply does not accept: '.implode(', ', $unknown));
        }

        return DB::transaction(function () use ($unit, $changes, $actorId) {
            $unit->refresh();

            [$scopeType, $scopeId] = Permit::scopeOf($unit);

            // The permit being changed is the one at the scope this unit's
            // licence belongs to — not merely the one covering it. A door in a
            // per-unit building may still be mirroring the building's old
            // facility permit; writing its own must create a row beside that
            // one rather than editing it and taking its neighbours with it.
            $current = Permit::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->current()
                ->lockForUpdate()
                ->first();

            // The starting point is the current permit; failing that, whatever
            // the unit's own columns say (a legacy row never adopted).
            $base = $current
                ? $current->only(array_keys(self::FIELDS))
                : [
                    'number' => $unit->tourism_permit_no,
                    'file' => $unit->tourism_permit_file,
                    'license_type' => $unit->license_type,
                    'licensed_units_count' => $unit->licensed_units_count,
                ];

            $merged = array_merge(array_fill_keys(array_keys(self::FIELDS), null), $base, $changes);
            $merged['number'] = PermitNumber::normalize($merged['number'] === null ? null : (string) $merged['number']);
            $merged['licensed_units_count'] = $merged['licensed_units_count'] === null ? null : (int) $merged['licensed_units_count'];

            // Licence rules — before anything is written.
            UnitLicense::guardFields($merged['license_type'], $merged['licensed_units_count']);
            UnitLicense::guardChange($unit, $merged['license_type'], $merged['licensed_units_count']);

            // And the number is not already another listing's. Inside the
            // transaction, after the lock above: two requests both reading
            // "free" and both writing is the race a check outside cannot see.
            PermitUniqueness::guard($merged['number'], $scopeType, $scopeId, $current?->getKey());

            $empty = $merged['number'] === null && $merged['file'] === null && $merged['license_type'] === null;

            if ($empty) {
                $current?->delete();
                self::mirror($scopeType, $scopeId, array_fill_keys(array_values(Permit::MIRROR), null), $unit);

                return null;
            }

            if ($current) {
                $current->fill($merged)->save();
            } else {
                $current = Permit::create(array_merge($merged, [
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'status' => Permit::STATUS_CURRENT,
                    'created_by' => $actorId,
                ]));
            }

            self::mirror($scopeType, $scopeId, $current->mirrorValues(), $unit);

            return $current;
        });
    }

    /**
     * Give a unit's scope a permit row from the unit's own columns, when it has
     * none. Called when a unit is created with permit columns set, and by the
     * backfill. When the scope already has a current permit, the unit is made
     * to mirror it instead — a clone created without documents in a building
     * that has a permit is covered by that permit, not by nothing.
     */
    public static function adopt(Unit $unit): ?Permit
    {
        return DB::transaction(function () use ($unit) {
            $current = Permit::query()->forUnit($unit)->current()->lockForUpdate()->first();

            if ($current) {
                if ($unit->only(array_values(Permit::MIRROR)) != $current->mirrorValues()) {
                    self::mirror($current->scope_type, (string) $current->scope_id, $current->mirrorValues(), $unit);
                }

                return $current;
            }

            [$scopeType, $scopeId] = Permit::scopeOf($unit);

            $number = PermitNumber::normalize($unit->tourism_permit_no);

            if ($number === null && $unit->tourism_permit_file === null && $unit->license_type === null) {
                return null;
            }

            $permit = Permit::create([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'number' => $number,
                'file' => $unit->tourism_permit_file,
                'license_type' => $unit->license_type,
                'licensed_units_count' => $unit->licensed_units_count,
                'status' => Permit::STATUS_CURRENT,
            ]);

            // Normalisation may have changed the number; the mirror must match.
            self::mirror($scopeType, $scopeId, $permit->mirrorValues(), $unit);

            return $permit;
        });
    }

    /**
     * A standalone unit has just become the first member of a group. Called by
     * the cloner the moment it assigns the group id, before any clone exists.
     *
     * Whether the source's permit now covers the group depends on what kind
     * of permit it is, and nothing else. A FACILITY permit is issued to the
     * property, so it covers every door in it: it is re-scoped to the group,
     * and the clones about to be created find a group permit to mirror. A
     * PRIVATE permit covers one door and stays with that door. An
     * UNCLASSIFIED permit stays put too — nothing may be assumed about what a
     * permit nobody has classified covers, and the clones then carry a permit
     * only if the caller copied one onto them.
     */
    public static function joinGroup(Unit $source): ?Permit
    {
        if (! $source->unit_group_id) {
            throw new \LogicException('joinGroup() needs a unit that already carries its group id.');
        }

        $own = Permit::query()
            ->where('scope_type', Permit::SCOPE_UNIT)
            ->where('scope_id', (string) $source->getKey())
            ->current()
            ->first();

        if (! $own) {
            return Permit::currentFor($source);
        }

        if ($own->license_type !== UnitLicense::TOURIST_FACILITY) {
            return $own;
        }

        $own->fill(['scope_type' => Permit::SCOPE_GROUP, 'scope_id' => (string) $source->unit_group_id])->save();

        return $own;
    }

    /* ---------- internals ---------- */

    /**
     * Write the read copies onto every unit in the scope, in one statement.
     *
     * A query-builder update fires no model events, so this is not caught by
     * the model guard — which is fine, because this IS the guarded writer —
     * but it also means the in-memory `$unit` would go stale. It is refreshed
     * so a caller reading it straight after sees what the database holds.
     *
     * @param  array<string, mixed>  $values  unit column => value
     */
    private static function mirror(string $scopeType, string $scopeId, array $values, ?Unit $refresh = null): void
    {
        self::write(function () use ($scopeType, $scopeId, $values) {
            $query = $scopeType === Permit::SCOPE_GROUP
                ? Unit::where('unit_group_id', $scopeId)
                : Unit::whereKey((int) $scopeId);

            $query->update($values);
        });

        $refresh?->refresh();
    }
}
