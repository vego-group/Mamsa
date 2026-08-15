<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UnitImage;
use App\Support\Media;
use Illuminate\Console\Command;

/**
 * Delete unit_images rows that point at the shared default image.
 *
 * The API already filters them out of every response, which fixes today's
 * consumers — but the rows survive, so any future query that COUNTS images (an
 * "N photos" badge, a completeness check, a partner-side validation) still
 * reads those units as photographed. Filtering fixes the symptom at each call
 * site; deleting fixes it once.
 *
 * They carry no information: the path is a constant, identical on every row.
 * Nothing is lost by removing them, and the unit reverts to what it truly is —
 * a listing with no photos.
 */
class PurgePlaceholderUnitImages extends Command
{
    protected $signature = 'units:purge-placeholder-images {--dry-run : Report what would be deleted and change nothing}';

    protected $description = 'Remove unit_images rows that only point at the shared default image';

    public function handle(): int
    {
        $dry     = (bool) $this->option('dry-run');
        $default = Media::defaultImagePath();

        $query = UnitImage::query()->where(function ($q) use ($default) {
            $q->where('path', $default)->orWhereNull('path')->orWhere('path', '');
        });

        $count = (clone $query)->count();
        $units = (clone $query)->distinct()->count('unit_id');

        if ($count === 0) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $this->line("Placeholder rows: {$count}, across {$units} unit(s).");

        // Units that would be left with NO images at all — the honest outcome,
        // but worth naming before it happens.
        $emptied = (clone $query)->distinct()->pluck('unit_id')
            ->filter(fn ($id) => UnitImage::where('unit_id', $id)
                ->where('path', '!=', $default)->whereNotNull('path')->where('path', '!=', '')
                ->doesntExist())
            ->count();

        $this->line("Units left with zero photos afterwards: {$emptied}.");

        if ($dry) {
            $this->info('Dry run — nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} placeholder row(s).");

        return self::SUCCESS;
    }
}
