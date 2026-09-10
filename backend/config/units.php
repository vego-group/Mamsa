<?php

declare(strict_types=1);

return [
    /*
     * Whether a partner may turn one listing into a building.
     *
     * A gradual-rollout switch, not a legal gate — the legal gate is the
     * licence type, which the database and the application both enforce
     * regardless of this flag. Turning this off stops NEW expansions; buildings
     * that already exist keep selling, because a booked apartment is a contract
     * and a feature flag is not a reason to break one.
     */
    'multi_unit_enabled' => (bool) env('MULTI_UNIT_ENABLED', true),
];
