<?php

declare(strict_types=1);

return [
    /*
     * Whether a listing must carry a permit expiry date to be submitted for
     * review.
     *
     * Off until an admin has filled in the dates for the listings that already
     * exist. It has a reach that is easy to miss: an approved listing goes back
     * to `pending` whenever it is edited, so with this on, ANY edit to an old
     * listing — a price change — would be refused until its permit date is
     * entered. That is the right end state and the wrong first day.
     */
    'expiry_required' => (bool) env('PERMIT_EXPIRY_REQUIRED', false),

    /*
     * How close to expiry a permit starts reading as "expiring" on the
     * consoles, and the first reminder threshold (phase 3).
     */
    'warning_days' => (int) env('PERMIT_WARNING_DAYS', 30),
];
