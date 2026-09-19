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
     *
     * PARTNER expansions only. The admin route that expands the platform's own
     * listings (POST /admin/units/:id/apartments) and a reviewer re-approving an
     * apartment that already exists are not behind it — see
     * UnitLicense::guardLicenceCovers() for why.
     */
    'multi_unit_enabled' => (bool) env('MULTI_UNIT_ENABLED', true),
];
