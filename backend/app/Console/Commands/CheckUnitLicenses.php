<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\LicenseGroupInconsistent;
use App\Support\OpsAlert;
use App\Support\Units\UnitLicense;
use Illuminate\Console\Command;

/**
 * Do the apartments in each building still agree about their permit?
 *
 * The licence is stored on every row of a group rather than in one place,
 * because a group has no parent row to hang it on — `unit_group_id` is a shared
 * label, deliberately not a foreign key. That makes agreement a property the
 * code maintains rather than one the schema enforces, and anything a schema
 * does not enforce is worth asking about on a schedule.
 *
 * The model guard blocks the likely mistake (an Eloquent update on one row).
 * This catches the one the guard cannot see: `Unit::where(...)->update(...)`,
 * which fires no model events at all. Two guards, two different mistakes.
 */
class CheckUnitLicenses extends Command
{
    protected $signature = 'units:check-licenses {--alert : Email the operations recipients when a group disagrees}';

    protected $description = 'Report buildings whose apartments disagree about their licence';

    public function handle(): int
    {
        $groups = UnitLicense::inconsistentGroups();

        if ($groups === []) {
            $this->info('Every building agrees with itself.');

            return self::SUCCESS;
        }

        foreach ($groups as $group) {
            $this->error(sprintf(
                'group %s: %d distinct license_type, %d distinct licensed_units_count',
                $group->unit_group_id, (int) $group->types, (int) $group->counts,
            ));
        }

        if ($this->option('alert')) {
            OpsAlert::raise(new LicenseGroupInconsistent($groups));
        }

        // Non-zero so a scheduler or CI step treats it as the fault it is.
        return self::FAILURE;
    }
}
