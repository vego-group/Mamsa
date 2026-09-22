<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Models\Permit;
use App\Models\Unit;
use App\Support\Permits\PermitMode;
use App\Support\Permits\PermitNumber;
use App\Support\Permits\PermitUniqueness;
use App\Support\Permits\PermitWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning one listing into a building — in either of the two licence modes.
 *
 * Shared by the partner dashboard and the admin console because the rules are
 * the same and only the envelope differs; the surfaces catch
 * {@see LicenseViolation} and render it their own way.
 *
 * The difference between the modes is not a branch in the middle of the work,
 * it is the whole shape of it:
 *
 *  - **single_permit**: the building's facility permit already covers the new
 *    doors, so the clones copy the documents and there is nothing to write.
 *  - **per_unit**: each new door arrives with its own permit, so the clones
 *    copy NO documents and each gets its permit written to its own scope.
 *    Copying here would attach one apartment's licence to its neighbours —
 *    the precise thing a private permit does not do.
 */
final class ApartmentExpansion
{
    /**
     * @param  array<int, array<string, mixed>>  $permits  one per NEW apartment, per-unit mode only
     * @return array{group: Collection<int, Unit>, added: Collection<int, Unit>, before: int}
     *
     * @throws LicenseViolation
     */
    public static function run(Unit $source, int $total, array $permits, ?int $actorId = null): array
    {
        $mode = PermitMode::of($source);
        $before = UnitLicense::groupSize($source);
        $new = max(0, $total - $before);

        if ($mode === PermitMode::PER_UNIT) {
            PermitMode::guardCount($new, $permits);
            self::guardPermitsAreDistinct($permits);
        }

        return DB::transaction(function () use ($source, $total, $permits, $mode, $before, $actorId) {
            $existingIds = $source->unit_group_id
                ? Unit::where('unit_group_id', $source->unit_group_id)->pluck('id')->all()
                : [$source->id];

            // Documents travel with the clones only when one permit covers them
            // all. In per-unit mode each door brings its own, so copying would
            // put apartment 402's licence on 403.
            $group = UnitCloner::ensureTotal($source, $total, copyDocuments: $mode === PermitMode::SINGLE);

            $added = $group->reject(fn (Unit $u) => in_array($u->id, $existingIds, true))->values();

            if ($mode === PermitMode::PER_UNIT) {
                self::attachPermits($added, $permits, $actorId);
            }

            return ['group' => $group, 'added' => $added, 'before' => $before];
        });
    }

    /**
     * Give each new apartment the permit that names it.
     *
     * A permit may name its apartment with `apartmentNo`, and then it goes to
     * that door whatever order it arrived in — the partner filled a form per
     * apartment and the numbers are how they think about them. Anything not
     * naming a door is handed out in order, which is the only other honest
     * reading of a list.
     *
     * @param  Collection<int, Unit>  $added
     * @param  array<int, array<string, mixed>>  $permits
     */
    private static function attachPermits(Collection $added, array $permits, ?int $actorId): void
    {
        $byNumber = [];
        $unnamed = [];

        foreach ($permits as $permit) {
            $door = isset($permit['apartmentNo']) ? trim((string) $permit['apartmentNo']) : '';
            $door === '' ? $unnamed[] = $permit : $byNumber[$door] = $permit;
        }

        foreach ($added as $apartment) {
            $permit = $byNumber[(string) $apartment->apartment_no] ?? array_shift($unnamed);

            if ($permit === null) {
                // Every new apartment must end up licensed; a list that cannot
                // be matched to the doors is a refusal, not a partial write.
                throw LicenseViolation::of(
                    'PERMITS_COUNT_MISMATCH',
                    'تصاريح الوحدات الجديدة لا تطابق أرقام الشقق',
                    ['requested' => $added->count(), 'permits_provided' => count($permits)],
                );
            }

            unset($byNumber[(string) $apartment->apartment_no]);

            PermitUniqueness::guard(
                $permit['number'] ?? null,
                Permit::SCOPE_UNIT,
                (string) $apartment->id,
            );

            PermitWriter::apply($apartment, array_filter([
                'number' => $permit['number'] ?? null,
                'file' => $permit['fileId'] ?? null,
                'license_type' => UnitLicense::PRIVATE_HOSPITALITY,
                'expires_at' => $permit['expiresAt'] ?? null,
                'addr_city' => $permit['address']['city'] ?? null,
                'addr_district' => $permit['address']['district'] ?? null,
                'addr_building' => $permit['address']['building'] ?? null,
                'addr_unit_no' => $permit['address']['unitNo'] ?? null,
            ], fn ($v) => $v !== null), $actorId);
        }
    }

    /**
     * Two apartments in one request may not carry the same number.
     *
     * Caught here rather than by the per-row uniqueness check, because that one
     * compares against what is already stored — and within a single request
     * nothing is stored yet, so two identical numbers would both pass it and
     * the second would overwrite nothing while claiming the same licence.
     *
     * @param  array<int, array<string, mixed>>  $permits
     */
    private static function guardPermitsAreDistinct(array $permits): void
    {
        $numbers = [];

        foreach ($permits as $permit) {
            $number = PermitNumber::normalize(isset($permit['number']) ? (string) $permit['number'] : null);

            if ($number === null) {
                continue;
            }

            if (in_array($number, $numbers, true)) {
                throw LicenseViolation::of(
                    'DUPLICATE_PERMIT_NUMBER',
                    'رقم التصريح مكرر داخل الطلب نفسه',
                    ['permit_number' => $number],
                );
            }

            $numbers[] = $number;
        }
    }
}
