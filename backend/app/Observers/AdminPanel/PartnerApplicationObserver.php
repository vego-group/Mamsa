<?php

declare(strict_types=1);

namespace App\Observers\AdminPanel;

use App\Models\PartnerDetail;
use App\Observers\AdminPanel\Concerns\NotifiesAdmins;

/**
 * A partner application entering `pending` (new applicant or resubmission after
 * a rejection) raises a "new partner application" in the admin feed — §5.11.
 */
class PartnerApplicationObserver
{
    use NotifiesAdmins;

    public function created(PartnerDetail $detail): void
    {
        if ($detail->status === PartnerDetail::STATUS_PENDING) {
            $this->announce($detail);
        }
    }

    public function updated(PartnerDetail $detail): void
    {
        if ($detail->wasChanged('status') && $detail->status === PartnerDetail::STATUS_PENDING) {
            $this->announce($detail);
        }
    }

    private function announce(PartnerDetail $detail): void
    {
        $this->notifyAdmins(
            'partner',
            'طلب شريك جديد',
            'تقدّم شريك جديد بطلب توثيق',
            ['partner_id' => $detail->user_id],
        );
    }
}
