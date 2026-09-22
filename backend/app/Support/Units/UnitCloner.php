<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One spec, many doors.
 *
 * A tower with 100 identical apartments is 100 bookable things that happen to
 * share a description. This turns one built listing into all of them, and
 * stamps them with a `unit_group_id` so the rest of the platform can later
 * treat them as a single card.
 *
 * The operation is IDEMPOTENT by apartment number: numbers already present in
 * the group are skipped, so a run that dies at apartment 63 can simply be run
 * again. That matters because this writes up to a hundred rows plus their
 * photos, and a half-finished building is otherwise very hard to reason about.
 */
final class UnitCloner
{
    /**
     * Ceiling on one call, counting the source.
     *
     * Not a database limit — a blast-radius one. Every row here is a real
     * listing that a human then has to review, and a typo'd range (401-4020)
     * should fail loudly rather than flood the approval queue.
     */
    public const MAX_GROUP = 100;

    /**
     * Columns a clone must NOT inherit, and why each one is here.
     *
     * The list is exclusions rather than an allow-list on purpose: a clone
     * means "the same listing", so a column added next month should be copied
     * by default. Only the ones that are *identity* or *history* are dropped.
     */
    private const NOT_COPIED = [
        // Identity — both are UNIQUE. Copying `calendar_token` would hand every
        // apartment one iCal feed, so an external sync on 402 would rewrite the
        // calendars of the other 99.
        //
        // `unit_name` is NOT here: every apartment in a building shares its
        // name. Suffixing it with the door number ("البرج - 402") was the first
        // attempt, and the storefront exposed it as wrong — the collapsed card
        // is the BUILDING, so it inherited a name ending in a door number that
        // means nothing to a guest. The door is a field, `apartment_no`, and
        // the partner's list renders it from there.
        'id', 'code', 'calendar_token',
        'unit_group_id', 'apartment_no',
        'created_at', 'updated_at',

        // History belongs to the row that earned it. A clone of an approved
        // listing has never been reviewed, and starts where any new listing
        // starts.
        'approval_status', 'submitted_at', 'rejection_reason', 'is_featured',

        // The source's external calendar describes the source's apartment.
        'ical_import_url', 'ical_synced_at',
    ];

    /**
     * The compliance documents.
     *
     * Whether these may be copied depends on something no code can answer:
     * a tourism permit issued for the BUILDING covers every apartment in it,
     * while one issued per apartment does not. Copying in the second case
     * would attach 402's licence to 99 apartments it does not cover, and an
     * admin would approve them on that evidence — so the default is to leave
     * them blank and let the submit gate ask for each one.
     */
    private const DOCUMENTS = [
        'tourism_permit_no',
        'tourism_permit_file',
        'ownership_doc_file',
        'company_license_no',
    ];

    /**
     * Give every apartment in `$numbers` a row, cloned from `$source`.
     *
     * The first unused number lands on the source itself when it has none yet:
     * the original apartment is a member of its own building, and leaving it
     * ungrouped would strand it outside the listing its 99 siblings share.
     *
     * @param  list<string>  $numbers  door numbers, e.g. ['401','402',…]
     * @return Collection<int,Unit>    the whole group, source included
     */
    public static function assign(Unit $source, array $numbers, bool $copyDocuments = false): Collection
    {
        return DB::transaction(function () use ($source, $numbers, $copyDocuments) {
            $groupId = $source->unit_group_id ?: (string) Str::ulid();

            if (! $source->unit_group_id) {
                $source->forceFill(['unit_group_id' => $groupId])->save();
            }

            $group = Unit::where('unit_group_id', $groupId)->lockForUpdate()->get();
            $taken = $group->pluck('apartment_no')->filter()->map(strval(...))->all();

            $wanted = collect($numbers)
                ->map(fn ($n) => trim((string) $n))
                ->filter()
                ->unique()
                ->reject(fn ($n) => in_array($n, $taken, true))
                ->values();

            // The source is the first member, so it consumes the first number.
            if (blank($source->apartment_no) && $wanted->isNotEmpty()) {
                $source->forceFill(['apartment_no' => $wanted->shift()])->save();
            }

            foreach ($wanted as $number) {
                self::cloneOne($source, $groupId, $number, $copyDocuments);
            }

            return Unit::where('unit_group_id', $groupId)->orderBy('apartment_no')->get();
        });
    }

    /**
     * Make the building hold exactly `$total` apartments.
     *
     * "I have 5 units" is a TOTAL, not an instruction to create five more. The
     * first implementation generated doors 1..N and added any that were
     * missing, which on a building already numbered 401-405 read every one of
     * 1..5 as absent and doubled it to ten. A partner saying "5" twice must get
     * five both times.
     *
     * Shrinking is refused rather than performed: an apartment may already hold
     * a booking, and silently deleting one to satisfy a smaller number would
     * cancel a stay nobody asked to cancel. Removing one is a deliberate act on
     * that unit.
     *
     * @return Collection<int,Unit> the whole group
     */
    public static function ensureTotal(Unit $source, int $total, bool $copyDocuments = false): Collection
    {
        $existing = $source->unit_group_id
            ? Unit::where('unit_group_id', $source->unit_group_id)->pluck('apartment_no')->all()
            : [];

        // With no group yet the source has no door number either, and assign()
        // spends the first number on it — so ask for `total`, not `total - 1`.
        // Once the group exists every member is already numbered and only the
        // shortfall is new.
        $needed = $source->unit_group_id
            ? $total - count($existing)
            : $total;

        if ($needed <= 0) {
            return $source->unit_group_id
                ? Unit::where('unit_group_id', $source->unit_group_id)->orderBy('apartment_no')->get()
                : collect([$source]);
        }

        return self::assign($source, self::nextNumbers($existing, $source, $needed), $copyDocuments);
    }

    /**
     * Door numbers for `$needed` new apartments that collide with none in use.
     *
     * Continues the building's own numbering where it is numeric — 401-405 plus
     * two more is 406 and 407, not 1 and 2 — because a door number a partner
     * chose carries meaning a generated sequence would talk over.
     *
     * @param  array<int, string|null>  $existing
     * @return list<string>
     */
    private static function nextNumbers(array $existing, Unit $source, int $needed): array
    {
        $taken = collect($existing)->push($source->apartment_no)->filter()->map(strval(...));

        $numeric = $taken->filter(fn ($n) => ctype_digit($n))->map(fn ($n) => (int) $n);
        $next    = $numeric->isNotEmpty() ? $numeric->max() + 1 : 1;

        $numbers = [];

        while (count($numbers) < $needed) {
            if (! $taken->contains((string) $next)) {
                $numbers[] = (string) $next;
            }
            $next++;
        }

        return $numbers;
    }

    /** How many rows a group would hold after adding `$numbers`. */
    public static function projectedSize(Unit $source, array $numbers): int
    {
        $existing = $source->unit_group_id
            ? Unit::where('unit_group_id', $source->unit_group_id)->pluck('apartment_no')->filter()->all()
            : [];

        $new = collect($numbers)->map(fn ($n) => trim((string) $n))->filter()->unique()
            ->reject(fn ($n) => in_array($n, $existing, true));

        return max(count($existing), 1) + $new->count() - (blank($source->apartment_no) ? 1 : 0);
    }

    private static function cloneOne(Unit $source, string $groupId, string $number, bool $copyDocuments): Unit
    {
        $attributes = collect($source->getAttributes())
            ->except(self::NOT_COPIED)
            ->when(! $copyDocuments, fn ($c) => $c->except(self::DOCUMENTS))
            ->all();

        // array_merge, NOT the `+` union operator: union keeps the LEFT side's
        // value on a duplicate key, so `unit_name` — which IS copied from the
        // source — silently won over the override below and every apartment in
        // the building came out with the same name. The other overrides only
        // escaped that because their keys are in NOT_COPIED and so absent from
        // $attributes; this was one column away from being right by accident.
        $clone = Unit::create(array_merge($attributes, [
            'unit_group_id'   => $groupId,
            'apartment_no'    => $number,
            'code'            => self::uniqueCode(),
            'calendar_token'  => Str::random(60),
            'approval_status' => 'draft',
        ]));

        // Photos are SHARED, not duplicated on disk: a hundred copies of the
        // same eight images is storage spent to say nothing new. Deletion is
        // reference-counted (see UnitImageController::deleteFile) so removing a
        // photo from 402 cannot blank it for the rest of the building.
        foreach ($source->images as $image) {
            $clone->images()->create(
                collect($image->getAttributes())
                    ->except(['id', 'unit_id', 'created_at', 'updated_at'])
                    ->all()
            );
        }

        $clone->features()->sync($source->features->pluck('id'));

        return $clone;
    }

    /** `code` is UNIQUE; a 100-row loop is where a random collision finally happens. */
    private static function uniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Unit::where('code', $code)->exists());

        return $code;
    }
}
