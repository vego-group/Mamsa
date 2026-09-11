<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DashboardUpload;
use App\Models\PartnerDetail;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Take compliance documents that nothing references off the public disk.
 *
 * Tourism permits, commercial registrations and national ID scans are written
 * to `storage/app/public`, which this host serves as static files through a
 * symlink — no signature, no expiry, no authorisation. A ULID filename is the
 * only thing between the document and anyone who has ever seen its URL.
 *
 * This handles the subset that is safe to move today: files no live column
 * points at. They are superseded permits and abandoned uploads — invisible on
 * every screen, and still downloadable. Moving them cannot break a page,
 * because no page can reach them.
 *
 * MOVED, NOT DELETED. A superseded permit may be the evidence for an approval
 * decision already taken, so this removes public reach and keeps the document.
 * Deleting is a separate decision for whoever owns the records.
 *
 * Unit photos are deliberately untouched: they are public by nature, and
 * routing them through PHP would put every listing image on the app server.
 */
class SecureOrphanDocuments extends Command
{
    protected $signature = 'documents:secure-orphans
        {--dry-run : List what would move and change nothing}
        {--manifest= : Write the before/after snapshot to this path}';

    protected $description = 'Move unreferenced compliance documents off the public disk';

    /** Directories holding documents rather than public imagery. */
    private const SENSITIVE = ['dashboard/license_pdf', 'dashboard/national_id', 'dashboard/company_doc'];

    /** Where the moved files land — under storage/app, outside the public symlink. */
    private const VAULT = 'secured-documents';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $vault = Storage::disk('local');

        $referenced = $this->referencedPaths();
        $moved = [];
        $failed = [];

        foreach (self::SENSITIVE as $dir) {
            foreach ($public->allFiles($dir) as $path) {
                if ($referenced->contains($path)) {
                    continue;
                }

                $row = [
                    'path' => $path,
                    'bytes' => $public->size($path),
                    'target' => self::VAULT.'/'.$path,
                ];

                if ($this->option('dry-run')) {
                    $moved[] = $row + ['moved' => false];

                    continue;
                }

                // Copy-verify-delete rather than move: a half-completed move on
                // a compliance document is worse than not starting one.
                $vault->put($row['target'], $public->get($path));

                if (! $vault->exists($row['target']) || $vault->size($row['target']) !== $row['bytes']) {
                    $failed[] = $row;

                    continue;
                }

                $public->delete($path);
                $moved[] = $row + ['moved' => true];
            }
        }

        $this->table(
            ['file', 'bytes', 'moved to'],
            array_map(fn ($r) => [$r['path'], number_format($r['bytes']), $r['target']], $moved),
        );

        $this->info(($this->option('dry-run') ? 'Would move ' : 'Moved ').count($moved).' unreferenced document(s).');

        if ($failed !== []) {
            $this->error(count($failed).' file(s) failed verification and were LEFT IN PLACE.');
        }

        if ($path = $this->option('manifest')) {
            file_put_contents($path, json_encode([
                'ran_at' => now()->toIso8601String(),
                'dry_run' => (bool) $this->option('dry-run'),
                'referenced' => $referenced->values()->all(),
                'moved' => $moved,
                'failed' => $failed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->line("manifest: {$path}");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every public-disk path a live column points at.
     *
     * Columns hold either a raw path or a `file_…` upload id, so both are
     * resolved. A file reachable from any of them is in use and stays put —
     * this command's whole safety rests on that list being complete, which is
     * why it reads the columns rather than a hardcoded guess.
     *
     * @return Collection<int, string>
     */
    private function referencedPaths(): Collection
    {
        $values = collect();

        foreach (['tourism_permit_file', 'ownership_doc_file'] as $column) {
            $values = $values->merge(Unit::whereNotNull($column)->pluck($column));
        }

        foreach (PartnerDetail::query()->getModel()->getFillable() as $column) {
            if (str_contains($column, 'file')) {
                $values = $values->merge(PartnerDetail::whereNotNull($column)->pluck($column));
            }
        }

        $ids = $values->filter(fn ($v) => str_starts_with((string) $v, 'file_'));
        $paths = $values->reject(fn ($v) => str_starts_with((string) $v, 'file_'));

        return $paths
            ->merge(DashboardUpload::whereIn('id', $ids)->pluck('path'))
            ->filter()
            ->unique()
            ->values();
    }
}
