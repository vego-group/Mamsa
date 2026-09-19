<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Support\Documents\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Step three: move the documents people can still reach off the public disk.
 *
 * Step two changed where NEW documents land and how ALL documents are read —
 * the reader checks the vault first and falls back to the public disk. That
 * fallback is what let step two ship without breaking anything, and it is also
 * why the documents already public stayed public: nothing moved them. This
 * moves them.
 *
 * DRIVEN FROM THE DATABASE, not from directories. A folder list is a guess
 * about where things are; the references are where things actually are. A
 * directory-driven pass once missed every file under units/{id}/docs/ — and
 * would have left them public while the count said the exposure was closed.
 *
 * Copy, verify size, then delete — never a bare move. A half-completed move on
 * a compliance document is worse than not starting: the reader would find the
 * file on neither disk and the reviewer would get a 404 on a permit they could
 * see the day before.
 *
 * Refuses to run while any reference is a bare path. A bare path cannot be
 * signed (there is no upload row to authorise against), so moving its bytes
 * would take the document off the public disk with no way to serve it.
 * documents:backfill-uploads fixes that first.
 */
class MigrateReferencedDocuments extends Command
{
    protected $signature = 'documents:migrate-referenced
        {--dry-run : List what would move and change nothing}
        {--manifest= : Write the before/after snapshot to this path}';

    protected $description = 'Move every referenced compliance document from the public disk into the vault';

    public function handle(): int
    {
        // Gate: every reference must be signable before anything moves. A bare
        // path has no upload row to authorise against, so moving its bytes would
        // take the document off the public disk with no way to serve it.
        $bare = $this->barePathReferences();

        if ($bare !== []) {
            $this->error(count($bare).' reference(s) are bare paths and cannot be signed — run documents:backfill-uploads first:');
            foreach ($bare as $b) {
                $this->line('  '.$b);
            }

            return self::FAILURE;
        }

        $public = Storage::disk('public');
        $moved = [];
        $skipped = [];
        $failed = [];

        foreach (DocumentStorage::referencedPaths() as $path) {
            if (! $public->exists($path)) {
                // Already in the vault (step two wrote it there), or a dangling
                // reference — either way there is nothing on the public disk to
                // move. Reported so the count is explained, not silently short.
                $skipped[] = ['path' => $path, 'why' => DocumentStorage::locate($path)[0] ? 'already in vault' : 'file on neither disk'];

                continue;
            }

            $row = ['path' => $path, 'bytes' => $public->size($path), 'target' => DocumentStorage::vaultPath($path)];

            if ($this->option('dry-run')) {
                $moved[] = $row + ['moved' => false];

                continue;
            }

            Storage::disk(config('documents.vault_disk'))->put($row['target'], $public->get($path));

            $vault = Storage::disk(config('documents.vault_disk'));

            if (! $vault->exists($row['target']) || $vault->size($row['target']) !== $row['bytes']) {
                $failed[] = $row;

                continue; // public copy left in place — the reader still finds it
            }

            $public->delete($path);
            $moved[] = $row + ['moved' => true];
        }

        $this->table(
            ['file', 'bytes', 'to'],
            array_map(fn ($r) => [$r['path'], number_format($r['bytes']), $r['target']], $moved),
        );
        $this->info(($this->option('dry-run') ? 'Would move ' : 'Moved ').count($moved).' referenced document(s).');

        foreach ($skipped as $s) {
            $this->line("  skipped {$s['path']} — {$s['why']}");
        }

        if ($failed !== []) {
            $this->error(count($failed).' file(s) failed size verification and were LEFT ON THE PUBLIC DISK.');
        }

        if ($path = $this->option('manifest')) {
            file_put_contents($path, json_encode([
                'ran_at' => now()->toIso8601String(),
                'dry_run' => (bool) $this->option('dry-run'),
                'moved' => $moved,
                'skipped' => $skipped,
                'failed' => $failed,
                'still_public_after' => $this->stillPublic(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line("manifest: {$path}");
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Document columns still holding a bare path rather than a `file_…` id.
     *
     * Filtered in PHP, not with LIKE: `\_` escaping behaves differently on
     * MySQL, MariaDB and sqlite, and this project runs all three. A gate that
     * fires on every row on one engine is a gate that blocks the migration for
     * a reason that is not real.
     *
     * @return list<string>
     */
    private function barePathReferences(): array
    {
        $out = [];
        $isBare = fn ($v) => filled($v) && ! str_starts_with((string) $v, 'file_');

        foreach (['tourism_permit_file', 'ownership_doc_file'] as $column) {
            foreach (Unit::whereNotNull($column)->get(['id', $column]) as $u) {
                if ($isBare($u->{$column})) {
                    $out[] = "units#{$u->id}.{$column} = {$u->{$column}}";
                }
            }
        }

        foreach ((new PartnerDetail)->getFillable() as $column) {
            if (! str_contains($column, 'file')) {
                continue;
            }
            foreach (PartnerDetail::whereNotNull($column)->get(['id', $column]) as $d) {
                if ($isBare($d->{$column})) {
                    $out[] = "partner_details#{$d->id}.{$column} = {$d->{$column}}";
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function stillPublic(): array
    {
        $out = [];

        foreach ((array) config('documents.sensitive_dirs', []) as $dir) {
            foreach (Storage::disk('public')->allFiles($dir) as $f) {
                $out[] = $f;
            }
        }

        return $out;
    }
}
