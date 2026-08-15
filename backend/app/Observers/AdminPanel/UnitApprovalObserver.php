<?php

declare(strict_types=1);

namespace App\Observers\AdminPanel;

use App\Models\Unit;
use App\Observers\AdminPanel\Concerns\NotifiesAdmins;

/**
 * A unit entering `pending` review (new submission or resubmission) raises a
 * "new approval request" in the admin feed — BACKEND_SPEC §5.11.
 */
class UnitApprovalObserver
{
    use NotifiesAdmins;

    /**
     * Stamp the moment a unit enters review — the basis for review-time SLA.
     *
     * Done here rather than at the four call sites that set `pending` (partner
     * submit and edit-reverts-to-pending, on both the dashboard and /api/v1)
     * so no path can forget it, including ones added later. `saving` runs
     * before the write, so this costs no extra query and cannot recurse.
     */
    public function saving(Unit $unit): void
    {
        if ($unit->isDirty('approval_status') && $unit->approval_status === 'pending') {
            $unit->submitted_at = now();
        }
    }

    public function created(Unit $unit): void
    {
        if ($unit->approval_status === 'pending') {
            $this->announce($unit);
        }
    }

    public function updated(Unit $unit): void
    {
        if ($unit->wasChanged('approval_status') && $unit->approval_status === 'pending') {
            $this->announce($unit);
        }
    }

    private function announce(Unit $unit): void
    {
        $this->notifyAdmins(
            'approval',
            'طلب مراجعة جديد',
            'الوحدة "'.$unit->unit_name.'" بانتظار المراجعة',
            ['unit_id' => $unit->id],
        );
    }
}
