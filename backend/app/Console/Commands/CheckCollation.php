<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\CollationFallback;
use App\Support\OpsAlert;
use App\Support\Units\DoorName;
use Illuminate\Console\Command;

/**
 * Does this server still sort Arabic the way the browser does?
 *
 * Door names are ordered with Collator('ar'), the frontend with
 * localeCompare('ar'). ICU without Arabic locale data does not fail — it
 * silently falls back to `root`, which puts Latin names first, and the two
 * sides list one building in two orders. A server or PHP update is enough to
 * cause it. DoorName already logs the fallback, but a log nobody opens is not
 * an alert, so this asks daily and emails the operations recipients.
 */
class CheckCollation extends Command
{
    protected $signature = 'ops:check-collation {--alert : Email the operations recipients when Arabic collation is missing}';

    protected $description = 'Check that ICU loads Arabic collation (ar), not the root fallback';

    public function handle(): int
    {
        $actual = $this->actualLocale();
        $icu = \defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : 'unknown';

        if ($actual === 'ar') {
            $this->info("Collator('ar') loads ar (ICU {$icu}).");

            return self::SUCCESS;
        }

        $this->error("Collator('ar') loaded {$actual}, not ar (ICU {$icu}) — door names sort Latin-first, unlike the frontend.");

        if ($this->option('alert')) {
            OpsAlert::raise(new CollationFallback($actual, $icu, PHP_VERSION, (string) config('app.url')));
        }

        // Non-zero so a scheduler or CI step treats it as the fault it is.
        return self::FAILURE;
    }

    /** Separate so a test can stand in for a server without the Arabic data. */
    protected function actualLocale(): string
    {
        return DoorName::collationLocale();
    }
}
