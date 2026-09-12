<?php

return [
    'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
    'secret_key'      => env('MOYASAR_SECRET_KEY'),
    'webhook_secret'  => env('MOYASAR_WEBHOOK_SECRET'),
    'currency'        => 'SAR',

    /*
     * When to stop waiting for a payment webhook and ask Moyasar directly.
     *
     * A webhook that never arrives leaves a paid booking unconfirmed, and until
     * now the only thing that noticed was the guest returning to the page and
     * triggering /payments/verify. A guest who closed the tab after paying had
     * no server-side path at all.
     *
     * Fifteen minutes is long enough that a healthy webhook has landed and a
     * 3-DS challenge has finished, and short enough that a partner is not told
     * about a booking hours after the money moved.
     */
    'reconcile_after_minutes' => (int) env('MOYASAR_RECONCILE_AFTER_MINUTES', 15),

    /*
     * When a payment that is still unresolved becomes a person's problem rather
     * than the job's. Asking repeatedly and never escalating is how something
     * stays broken quietly.
     */
    'reconcile_alert_after_hours' => (int) env('MOYASAR_RECONCILE_ALERT_AFTER_HOURS', 6),
];
