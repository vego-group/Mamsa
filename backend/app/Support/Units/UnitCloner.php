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

        $clone = Unit::create($attributes + [
            'unit_group_id'   => $groupId,
            'apartment_no'    => $number,
            // The door number is what tells two otherwise identical listings
            // apart in the partner's own list.
            'unit_name'       => self::nameFor($source, $number),
            'code'            => self::uniqueCode(),
            'calendar_token'  => Str::random(60),
            'approval_status' => 'draft',
        ]);

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

    /** Base name plus the door number, without stacking suffixes on re-runs. */
    private static function nameFor(Unit $source, string $number): string
    {
        $base = $source->apartment_no
            ? Str::beforeLast($source->unit_name, ' - ' . $source->apartment_no)
            : $source->unit_name;

        return Str::limit(trim($base) . ' - ' . $number, 150, '');
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
