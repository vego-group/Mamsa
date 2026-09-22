<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Permits\PermitWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tourism permit — the document a listing trades under.
 *
 * Read the shape in the migration. Two things the model adds: which units a
 * permit covers ({@see units()}), and the one query every reader needs — the
 * `current` permit of a unit's scope ({@see currentFor()}).
 *
 * Written ONLY through {@see PermitWriter}. The four
 * mirrored columns on `units` are derived from this row; the model guard on
 * Unit refuses to let anything else change them.
 */
class Permit extends Model
{
    public const SCOPE_UNIT = 'unit';

    public const SCOPE_GROUP = 'group';

    public const STATUS_CURRENT = 'current';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    /** The unit columns that mirror this row, keyed by the permit column. */
    public const MIRROR = [
        'number' => 'tourism_permit_no',
        'file' => 'tourism_permit_file',
        'license_type' => 'license_type',
        'licensed_units_count' => 'licensed_units_count',
    ];

    protected $fillable = [
        'scope_type', 'scope_id', 'number', 'file', 'license_type', 'licensed_units_count',
        'expires_at', 'addr_city', 'addr_district', 'addr_building', 'addr_unit_no',
        'status', 'created_by', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'review_notes',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'reviewed_at' => 'datetime',
        'licensed_units_count' => 'integer',
    ];

    /* ---------- scope ---------- */

    /**
     * The scope a unit's permit lives at: its group when it has one, else
     * itself. A grouped unit never has a permit of its own in mode B; in
     * mode A (one permit per apartment) it does — see PermitWriter.
     *
     * @return array{0: string, 1: string} [scope_type, scope_id]
     */
    public static function scopeOf(Unit $unit): array
    {
        return $unit->unit_group_id
            ? [self::SCOPE_GROUP, (string) $unit->unit_group_id]
            : [self::SCOPE_UNIT, (string) $unit->getKey()];
    }

    /**
     * Permits that could cover this unit: its own (unit-scoped) and, when it
     * is grouped, the group's. Its own wins when both exist — a private permit
     * on one door of a building is that door's, whatever the building holds.
     */
    public function scopeForUnit(Builder $query, Unit $unit): Builder
    {
        return $query->where(function (Builder $q) use ($unit) {
            if ($unit->getKey() !== null) {
                $q->orWhere(fn (Builder $w) => $w->where('scope_type', self::SCOPE_UNIT)->where('scope_id', (string) $unit->getKey()));
            }
            if ($unit->unit_group_id) {
                $q->orWhere(fn (Builder $w) => $w->where('scope_type', self::SCOPE_GROUP)->where('scope_id', (string) $unit->unit_group_id));
            }
            if ($unit->getKey() === null && ! $unit->unit_group_id) {
                $q->whereRaw('1 = 0');
            }
        })
            // unit before group — see above
            ->orderByRaw("CASE scope_type WHEN 'unit' THEN 0 ELSE 1 END");
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CURRENT);
    }

    /** The permit a unit trades under right now, or null. */
    public static function currentFor(Unit $unit): ?self
    {
        return static::query()->forUnit($unit)->current()->first();
    }

    /** Every unit this permit covers. */
    public function units(): Builder
    {
        return $this->scope_type === self::SCOPE_GROUP
            ? Unit::where('unit_group_id', $this->scope_id)
            : Unit::whereKey((int) $this->scope_id);
    }

    /* ---------- relations ---------- */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /* ---------- derived ---------- */

    /** The values the mirrored unit columns must hold for this permit. */
    public function mirrorValues(): array
    {
        $values = [];

        foreach (self::MIRROR as $own => $unitColumn) {
            $values[$unitColumn] = $this->{$own};
        }

        return $values;
    }

    public function isEmpty(): bool
    {
        return $this->number === null && $this->file === null && $this->license_type === null;
    }
}
