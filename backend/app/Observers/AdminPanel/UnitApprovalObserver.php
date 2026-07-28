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
