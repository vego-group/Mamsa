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

    /*
     * Whether the legacy Bearer surfaces may still WRITE units.
     *
     * `/api/v1/partner` and `/api/v1/admin` (the testvue console) each carried
     * a full unit write path. Every permit rule — uniqueness, expiry,
     * per-apartment permits — would have to be implemented and kept correct on
     * four surfaces instead of two, and the admin one could approve a listing
     * without the permit check the admin console's own approve() runs. So they
     * are retired: 410, and every call written down (RetiredEndpoint).
     *
     * A flag rather than deleted routes because nobody can prove a negative
     * from this host — it keeps no access logs, so "no client uses them" was
     * an absence of evidence, not an observation. If the retired log turns out
     * to name a real client, `LEGACY_UNIT_WRITES=true` gives them back with no
     * deploy of code. The suite turns it on where it exercises those
     * controllers, so the revert path stays covered rather than becoming
     * untested code the day it is needed.
     *
     * READS are never affected, and the public guest API is untouched.
     */
    'legacy_unit_writes' => (bool) env('LEGACY_UNIT_WRITES', false),
];
