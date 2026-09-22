<?php

declare(strict_types=1);

namespace App\Support\Permits;

use App\Models\Permit;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing permit a row.
 *
 * Before the permits table, a permit was four columns on each unit it covered.
 * This reads those columns back into rows, one per scope:
 *
 *  - a group whose members all agree (one distinct number/file/type/count)
 *    gets ONE group-scoped permit — the building's;
 *  - a group whose members DISAGREE gets one unit-scoped permit per member
 *    that carries any value, and is reported: those rows were written by
 *    something that should not have been able to, and a human decides which
 *    is right;
 *  - a standalone unit with any value gets a unit-scoped permit;
 *  - a unit with no number, no file and no type gets nothing — NULL stays
 *    NULL, nothing is guessed.
 *
 * Numbers are normalised on the way in and the normalised form is written
 * back onto the units, so the mirror is exact from the first row.
 *
 * Idempotent: a scope that already has a current permit is skipped.
 */
final class PermitBackfill
{
    /**
     * @return array{groups: int, split_groups: list<string>, standalone: int, skipped: int, normalised: int, rows: int}
     */
    public static function run(bool $dryRun = false): array
    {
        $report = ['groups' => 0, 'split_groups' => [], 'standalone' => 0, 'skipped' => 0, 'normalised' => 0, 'rows' => 0];

        $run = function () use (&$report) {
            // ---- groups ----
            $groupIds = Unit::whereNotNull('unit_group_id')->distinct()->pluck('unit_group_id');

            foreach ($groupIds as $groupId) {
                if (Permit::where('scope_type', Permit::SCOPE_GROUP)->where('scope_id', $groupId)->current()->exists()) {
                    $report['skipped']++;

                    continue;
                }

                $members = Unit::where('unit_group_id', $groupId)->get();
                $carrying = $members->filter(fn (Unit $u) => self::hasPermit($u));

                if ($carrying->isEmpty()) {
                    continue;
                }

                $distinct = $carrying->map(fn (Unit $u) => self::signature($u))->unique();

                if ($distinct->count() === 1) {
                    $permit = self::createFrom($carrying->first(), Permit::SCOPE_GROUP, (string) $groupId);
                    $report['normalised'] += self::mirror($permit, $members);
                    $report['groups']++;
                    $report['rows']++;

                    continue;
                }

                // Disagreement: one permit per carrying member, and say so.
                foreach ($carrying as $member) {
                    if (Permit::where('scope_type', Permit::SCOPE_UNIT)->where('scope_id', (string) $member->id)->current()->exists()) {
                        continue;
                    }
                    $permit = self::createFrom($member, Permit::SCOPE_UNIT, (string) $member->id);
                    $report['normalised'] += self::mirror($permit, collect([$member]));
                    $report['rows']++;
                }
                $report['split_groups'][] = (string) $groupId;
            }

            // ---- standalone ----
            Unit::whereNull('unit_group_id')->orderBy('id')->chunkById(200, function ($units) use (&$report) {
                foreach ($units as $unit) {
                    if (! self::hasPermit($unit)) {
                        continue;
                    }
                    if (Permit::where('scope_type', Permit::SCOPE_UNIT)->where('scope_id', (string) $unit->id)->current()->exists()) {
                        $report['skipped']++;

                        continue;
                    }
                    $permit = self::createFrom($unit, Permit::SCOPE_UNIT, (string) $unit->id);
                    $report['normalised'] += self::mirror($permit, collect([$unit]));
                    $report['standalone']++;
                    $report['rows']++;
                }
            });
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $run();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($run);
        }

        return $report;
    }

    private static function hasPermit(Unit $u): bool
    {
        return PermitNumber::normalize($u->tourism_permit_no) !== null
            || filled($u->tourism_permit_file)
            || $u->license_type !== null;
    }

    private static function signature(Unit $u): string
    {
        return implode('|', [
            PermitNumber::normalize($u->tourism_permit_no) ?? '',
            (string) $u->tourism_permit_file,
            (string) $u->license_type,
            (string) $u->licensed_units_count,
        ]);
    }

    private static function createFrom(Unit $u, string $scopeType, string $scopeId): Permit
    {
        return Permit::create([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'number' => PermitNumber::normalize($u->tourism_permit_no),
            'file' => filled($u->tourism_permit_file) ? $u->tourism_permit_file : null,
            'license_type' => $u->license_type,
            'licensed_units_count' => $u->licensed_units_count,
            'status' => Permit::STATUS_CURRENT,
        ]);
    }

    /** Write the permit's values onto its units; returns how many rows changed. */
    private static function mirror(Permit $permit, $units): int
    {
        $values = $permit->mirrorValues();
        $changed = 0;

        foreach ($units as $unit) {
            if ($unit->only(array_keys($values)) != $values) {
                $changed++;
            }
        }

        if ($changed > 0) {
            PermitWriter::write(fn () => Unit::whereIn('id', $units->pluck('id'))->update($values));
        }

        return $changed;
    }
}
