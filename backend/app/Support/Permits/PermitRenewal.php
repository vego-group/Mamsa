<?php

declare(strict_types=1);

namespace App\Support\Permits;

use App\Models\Permit;
use App\Models\PermitReminder;
use App\Models\Unit;
use App\Support\Units\LicenseViolation;
use App\Support\Units\UnitLicense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Renewing a permit without taking the listing down.
 *
 * The obvious implementation — overwrite the permit, send the listing back for
 * review — is wrong in both halves. A partner whose permit expires in three
 * weeks would have to choose between filing early and losing their listing to
 * the review queue, or filing late and losing it to the expiry; and a reviewer
 * would be handed the whole listing again when the only new fact is one
 * document.
 *
 * So a renewal is a SECOND permit row, `pending`, beside the `current` one.
 * The listing keeps selling on the old permit, and its `approval_status` is
 * never touched. On approval the two swap in one transaction and the calendar
 * cap moves with them. On rejection the old one carries on to its own date.
 * If the old one lapses while the renewal is still waiting, the listing simply
 * goes quiet ({@see PermitExpiry}) and comes back the moment the renewal is
 * approved — nothing to undo, because nothing was written.
 */
final class PermitRenewal
{
    /** The fields a renewal may carry — the same ones a permit has. */
    private const FIELDS = [
        'number', 'file', 'license_type', 'licensed_units_count', 'expires_at',
        'addr_city', 'addr_district', 'addr_building', 'addr_unit_no',
    ];

    /**
     * File a renewal for this listing's permit.
     *
     * Anything the renewal does not name is inherited from the permit in
     * force: a partner replacing only the document and the date should not
     * have to retype the number, and a blank field must not read as "clear it".
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws LicenseViolation
     */
    public static function open(Unit $unit, array $fields, ?int $actorId = null): Permit
    {
        $unknown = array_diff(array_keys($fields), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('PermitRenewal::open does not accept: '.implode(', ', $unknown));
        }

        return DB::transaction(function () use ($unit, $fields, $actorId) {
            $current = Permit::query()->forUnit($unit)->current()->lockForUpdate()->first();

            if (! $current) {
                throw LicenseViolation::of(
                    'NO_PERMIT_TO_RENEW',
                    'لا يوجد تصريح حالي لتجديده — أضف التصريح أولاً',
                );
            }

            if (self::pendingFor($unit)) {
                throw LicenseViolation::of(
                    'RENEWAL_ALREADY_PENDING',
                    'يوجد طلب تجديد قيد المراجعة بالفعل',
                );
            }

            $merged = array_merge($current->only(self::FIELDS), $fields);
            $merged['number'] = PermitNumber::normalize($merged['number'] === null ? null : (string) $merged['number']);
            $merged['licensed_units_count'] = $merged['licensed_units_count'] === null ? null : (int) $merged['licensed_units_count'];

            // The same rules a permit must satisfy to be written at all. Checked
            // now rather than at approval: a renewal that could never be
            // accepted should be refused while the partner is still looking at
            // the form.
            UnitLicense::guardFields($merged['license_type'], $merged['licensed_units_count']);
            UnitLicense::guardChange($unit, $merged['license_type'], $merged['licensed_units_count']);

            // A renewal carrying the same number forward is not a duplicate of
            // the permit it replaces — that one is excluded by id.
            PermitUniqueness::guard($merged['number'], $current->scope_type, (string) $current->scope_id, $current->getKey());

            if (empty($merged['expires_at'])) {
                throw LicenseViolation::of(
                    'PERMIT_EXPIRY_REQUIRED',
                    'تاريخ انتهاء التصريح الجديد مطلوب',
                );
            }

            if (Carbon::parse($merged['expires_at'])->startOfDay()->lessThan(now()->startOfDay())) {
                throw LicenseViolation::of(
                    'PERMIT_EXPIRED',
                    'تاريخ انتهاء التصريح الجديد في الماضي',
                    ['permit_expires_at' => Carbon::parse($merged['expires_at'])->toDateString()],
                );
            }

            return Permit::create(array_merge($merged, [
                // The renewal covers whatever the current permit covers — the
                // same building, or the same single door.
                'scope_type' => $current->scope_type,
                'scope_id' => $current->scope_id,
                'status' => Permit::STATUS_PENDING,
                'created_by' => $actorId,
            ]));
        });
    }

    /** The renewal waiting on this listing's permit, if any. */
    public static function pendingFor(Unit $unit): ?Permit
    {
        return Permit::query()->forUnit($unit)->where('status', Permit::STATUS_PENDING)->first();
    }

    /**
     * Accept a renewal: it becomes the permit in force, the old one is kept as
     * history, and every unit it covers mirrors the new values.
     *
     * One transaction, because a half-applied swap would leave a listing
     * trading under a permit that is marked superseded.
     */
    public static function approve(Permit $renewal, ?int $reviewerId = null): Permit
    {
        return DB::transaction(function () use ($renewal, $reviewerId) {
            $renewal->refresh();

            if ($renewal->status !== Permit::STATUS_PENDING) {
                throw LicenseViolation::of('RENEWAL_NOT_PENDING', 'طلب التجديد لم يعد قيد المراجعة');
            }

            Permit::where('scope_type', $renewal->scope_type)
                ->where('scope_id', $renewal->scope_id)
                ->where('status', Permit::STATUS_CURRENT)
                ->update(['status' => Permit::STATUS_SUPERSEDED]);

            $renewal->fill([
                'status' => Permit::STATUS_CURRENT,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ])->save();

            PermitWriter::write(fn () => $renewal->units()->update($renewal->mirrorValues()));

            // The warnings were about the OLD date. Clearing them lets the new
            // one warn in its own right when its time comes, without the unique
            // key treating it as already sent.
            PermitReminder::where('permit_id', $renewal->id)->delete();

            return $renewal->fresh();
        });
    }

    /** Refuse a renewal. The permit in force carries on to its own date. */
    public static function reject(Permit $renewal, string $reason, ?int $reviewerId = null, ?string $notes = null): Permit
    {
        if ($renewal->status !== Permit::STATUS_PENDING) {
            throw LicenseViolation::of('RENEWAL_NOT_PENDING', 'طلب التجديد لم يعد قيد المراجعة');
        }

        $renewal->fill([
            'status' => Permit::STATUS_REJECTED,
            'rejection_reason' => $reason,
            // Internal: the partner is told the reason, never the notes.
            'review_notes' => $notes,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ])->save();

        return $renewal->fresh();
    }
}
