<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DashboardUpload;
use App\Models\PartnerDetail;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Give every document reference an upload row, so every document can be signed.
 *
 * Two shapes exist in the document columns. Most hold a `file_…` upload id. A
 * few hold a bare path, written by the unit-documents endpoint before it
 * started recording rows — and a bare path cannot be signed, because the
 * /documents route resolves an upload to decide who may read it and a path has
 * no owner to check.
 *
 * That is survivable today: the reader falls back to the public URL, so those
 * documents still display. It stops being survivable at the migration. Moving
 * the bytes off the public disk leaves a reference with no way to mint a signed
 * link, and the reviewer gets a 404 on a permit they could see the day before.
 *
 * So the migration cannot run until this has. It is deliberately separate and
 * dry-runnable: it rewrites columns, and a column rewrite on compliance data
 * should be inspected before it happens.
 *
 * The row points at the EXISTING path. No bytes move here — that is the
 * migration's job, and doing both at once would make a failure hard to unpick.
 */
class BackfillDocumentUploads extends Command
{
    protected $signature = 'documents:backfill-uploads {--dry-run : Report what would change and change nothing}';

    protected $description = 'Give bare-path document references an upload row so they can be signed';

    /** column → the upload kind its documents belong to. */
    private const KIND = [
        'tourism_permit_file' => 'license_pdf',
        'ownership_doc_file' => 'ownership_doc',
        'bank_certificate_file' => 'company_doc',
        'cr_file' => 'company_doc',
        'national_id_file' => 'national_id',
        'authorization_letter_file' => 'company_doc',
        'vat_certificate_file' => 'company_doc',
        'operator_license_file' => 'company_doc',
    ];

    public function handle(): int
    {
        $public = Storage::disk('public');
        $done = [];
        $missing = [];

        foreach ($this->references() as $ref) {
            [$model, $column, $value] = $ref;

            // A reference whose file is not on disk cannot be given a row that
            // means anything — a row pointing at nothing is worse than a bare
            // path, because it looks resolvable. Reported, never invented.
            if (! $public->exists($value)) {
                $missing[] = "{$model->getTable()}#{$model->getKey()}.{$column} → {$value}";

                continue;
            }

            $row = [
                'reference' => "{$model->getTable()}#{$model->getKey()}.{$column}",
                'path' => $value,
                'kind' => self::KIND[$column] ?? 'company_doc',
                'bytes' => $public->size($value),
            ];

            if ($this->option('dry-run')) {
                $done[] = $row + ['id' => '(would create)'];

                continue;
            }

            $id = 'file_'.Str::ulid();

            DB::transaction(function () use ($id, $model, $column, $value, $row, $public) {
                DashboardUpload::create([
                    'id' => $id,
                    // The document belongs to whoever owns the thing it is
                    // attached to — a unit's partner, or the partner detail's
                    // own user. That is the identity the signed route checks.
                    'user_id' => $this->ownerId($model),
                    'kind' => $row['kind'],
                    'original_name' => basename($value),
                    'mime' => $public->mimeType($value) ?: 'application/octet-stream',
                    'size' => $row['bytes'],
                    'path' => $value,
                    'status' => 'stored',
                ]);

                $model->forceFill([$column => $id])->saveQuietly();
            });

            $done[] = $row + ['id' => $id];
        }

        $this->table(
            ['reference', 'kind', 'bytes', 'upload id'],
            array_map(fn ($r) => [$r['reference'], $r['kind'], number_format($r['bytes']), $r['id']], $done),
        );

        $this->info(($this->option('dry-run') ? 'Would backfill ' : 'Backfilled ').count($done).' reference(s).');

        if ($missing !== []) {
            $this->warn(count($missing).' reference(s) point at a file that is not on disk — left alone:');
            foreach ($missing as $m) {
                $this->line('  '.$m);
            }
        }

        // Non-zero when anything is left unresolved: the migration must not run
        // while a reference exists that it cannot carry across.
        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every document reference that is a bare path rather than an upload id.
     *
     * @return list<array{0: Model, 1: string, 2: string}>
     */
    private function references(): array
    {
        $out = [];

        $targets = [[Unit::class, ['tourism_permit_file', 'ownership_doc_file']]];
        $targets[] = [PartnerDetail::class, array_values(array_filter(
            (new PartnerDetail)->getFillable(),
            fn (string $c) => str_contains($c, 'file'),
        ))];

        foreach ($targets as [$class, $columns]) {
            foreach ($columns as $column) {
                $class::query()->whereNotNull($column)->where($column, '!=', '')->each(
                    function ($model) use ($column, &$out) {
                        $value = (string) $model->{$column};

                        if (! str_starts_with($value, 'file_')) {
                            $out[] = [$model, $column, $value];
                        }
                    }
                );
            }
        }

        return $out;
    }

    private function ownerId(mixed $model): ?int
    {
        return (int) ($model->user_id ?? 0) ?: null;
    }
}
