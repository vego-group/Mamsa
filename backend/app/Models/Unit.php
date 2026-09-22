<?php

namespace App\Models;

use App\Support\Permits\PermitWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    /**
     * The licence columns are group-wide, and this is the barrier that says so.
     *
     * `license_type` and `licensed_units_count` describe the PERMIT a facility
     * operates under, and every apartment in a building operates under the same
     * one. An update that touched them on a single row would leave the rest of
     * the group claiming a different permit — an apartment trading under a
     * licence that may not cover it.
     *
     * So updates go through UnitLicense::applyToGroup(), which writes the whole
     * group in one transaction, and anything else fails here rather than being
     * quietly dropped: a silently ignored write leaves the caller believing a
     * change was saved.
     *
     * CREATION is not guarded on purpose — a new row is a group of one, and the
     * cloner copies from the source it clones, so neither can introduce a
     * disagreement. And this cannot see `Unit::where(...)->update(...)`, which
     * fires no model events; the daily units:check-licenses command is what
     * catches that.
     */
    protected static function booted(): void
    {
        // Creation into an EXISTING group is guarded too, on a different
        // condition: the new row must agree with the siblings it is joining.
        // Nothing in the application does otherwise today — the cloner copies
        // from its source — but a seeder, a console command, or a path written
        // six months from now could, and the daily check would only notice the
        // next morning. This turns "caught within a day" into "cannot happen".
        //
        // The check is against the group's PERMIT, not a sibling row: the
        // permit is the fact, the siblings are its copies. A new row may carry
        // nothing (it will be made to mirror the permit on `created`) or the
        // same values; anything else is a second permit sneaking into a scope
        // that holds one.
        static::creating(function (self $unit) {
            if (PermitWriter::isWriting() || ! $unit->unit_group_id) {
                return;
            }

            $permit = Permit::currentFor($unit);

            if ($permit === null) {
                return; // first row of a new group — it sets the value
            }

            foreach (Permit::MIRROR as $own => $column) {
                $incoming = $unit->{$column};

                if ($incoming !== null && (string) $incoming !== (string) $permit->{$own}) {
                    throw new \LogicException(
                        "A new apartment must carry its group's permit ({$column}) — write it through PermitWriter."
                    );
                }
            }
        });

        // A unit created with its permit columns set has just described a
        // permit; give that permit its row. A unit created into a scope that
        // already has one is made to mirror it. Either way, after `created`
        // the units table and the permits table agree.
        static::created(function (self $unit) {
            if (PermitWriter::isWriting()) {
                return;
            }

            PermitWriter::adopt($unit);
        });

        // The four permit columns have one writer. `$unit->update([...])` from
        // any controller that touches them fails loudly instead of leaving one
        // apartment claiming a permit its siblings — and its permit row — do
        // not have.
        //
        // This cannot see query-builder updates — `Unit::where(...)->update(...)`
        // fires no model events. That is precisely why the daily consistency
        // check exists as well; the two guards catch different mistakes.
        static::updating(function (self $unit) {
            if (PermitWriter::isWriting()) {
                return;
            }

            if ($unit->isDirty(array_values(Permit::MIRROR))) {
                throw new \LogicException(
                    'tourism_permit_no / tourism_permit_file / license_type / licensed_units_count are the permit\'s — write them through PermitWriter::apply().'
                );
            }
        });
    }

    /** The permit this unit trades under right now, or null. */
    public function currentPermit(): ?Permit
    {
        return Permit::currentFor($this);
    }

    /**
     * Assumed check-out time when a listing does not state one.
     *
     * 12:00 is what 27 of 32 units carry and what the documentation states. It
     * matters beyond display: the complaint window closes 48 hours after
     * check-out, so treating an unknown time as midnight would have cut the
     * guest's deadline from 48 hours to 36 — on precisely the units the
     * platform knows least about.
     *
     * The column is NOT NULL as of 2026-09-07, so this is the value written
     * when a partner leaves the field empty, not a guess repeated at read time.
     */
    public const DEFAULT_CHECKOUT_TIME = '12:00';

    /**
     * The only unit types the platform supports (backend gaps #3).
     * Every public endpoint is constrained to these, and partner
     * create/update validation enforces the same set.
     */
    public const SUPPORTED_TYPES = ['apartment', 'studio', 'villa'];

    protected $fillable = [
        'user_id',
        'unit_name',
        'unit_type',
        'code',
        'unit_group_id',
        'apartment_no',
        'price',
        'capacity',
        'bedrooms',
        'beds',
        'bathrooms',
        'area',
        'city',
        'district',
        'address',
        'lat',
        'lng',
        'description',
        'tourism_permit_no',
        'license_type',
        'licensed_units_count',
        'tourism_permit_file',
        'ownership_doc_file',
        'company_license_no',
        'approval_status',
        'submitted_at',
        'rejection_reason',
        'status',
        'is_featured',
        'cancellation_policy',
        'cancellation_policy_id',
        'checkin_time',
        'checkout_time',
        'calendar_token',
        'ical_import_url',
        'mamsa_owned',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'price' => 'float',
        'lat' => 'float',
        'lng' => 'float',
        'capacity' => 'integer',
        'bedrooms' => 'integer',
        'beds' => 'integer',
        'bathrooms' => 'integer',
        'is_featured' => 'boolean',
        'mamsa_owned' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(UnitImage::class);
    }

    public function mainImage(): HasMany
    {
        return $this->hasMany(UnitImage::class)->where('is_main', true);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'unit_features');
    }

    public function icalFeeds(): HasMany
    {
        return $this->hasMany(UnitIcalFeed::class);
    }

    public function blockedDates(): HasMany
    {
        return $this->hasMany(UnitBlockedDate::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function getAvgRatingAttribute(): ?float
    {
        return $this->reviews()->avg('rating');
    }
}
