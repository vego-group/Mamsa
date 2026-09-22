<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Permits\PermitBackfill;
use Illuminate\Console\Command;

/**
 * The permits backfill, runnable by hand — with --dry-run to see what the
 * migration WOULD do on a server before it runs there. Idempotent either way.
 */
class BackfillPermits extends Command
{
    protected $signature = 'permits:backfill {--dry-run : Report without writing}';

    protected $description = 'Create a permits row for every permit currently held as columns on units';

    public function handle(): int
    {
        $report = PermitBackfill::run(dryRun: (bool) $this->option('dry-run'));

        $this->line(($this->option('dry-run') ? '[dry run] ' : '').'permit rows: '.$report['rows']);
        $this->line('  group permits     : '.$report['groups']);
        $this->line('  standalone permits: '.$report['standalone']);
        $this->line('  scopes skipped (already had a permit): '.$report['skipped']);
        $this->line('  unit rows whose mirror changed (normalisation): '.$report['normalised']);

        if ($report['split_groups'] !== []) {
            $this->warn('groups whose members DISAGREED — one permit per member was written, a human decides:');
            foreach ($report['split_groups'] as $g) {
                $this->warn('  '.$g);
            }
        }

        return self::SUCCESS;
    }
}
