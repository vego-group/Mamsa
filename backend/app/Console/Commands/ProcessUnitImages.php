<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DashboardUpload;
use App\Models\UnitImage;
use App\Support\Images\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill for photos uploaded before the pipeline existed: measure them,
 * strip the metadata they still carry, and build the derivative set.
 *
 * Runs from the CLI rather than a queue because there is no worker on shared
 * hosting — the scheduler cron is the only thing that runs unattended, and a
 * one-off backfill does not need to be a daemon.
 *
 * Idempotent: an upload that already has variants on disk is skipped unless
 * --force is given.
 */
class ProcessUnitImages extends Command
{
    protected $signature = 'images:process
        {--force : Reprocess uploads that already have derivatives}
        {--limit=0 : Stop after this many uploads (0 = no limit)}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Generate thumb/card/full derivatives and strip EXIF from unit photos';

    public function handle(): int
    {
        if (! ImageProcessor::available()) {
            $this->error('No image backend available (neither imagick nor gd is loaded).');

            return self::FAILURE;
        }

        $this->info('Driver: '.ImageProcessor::driver()?->name());

        $dry   = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');

        $query = DashboardUpload::query()
            ->where('kind', 'unit_photo')
            ->where('status', 'stored')
            ->whereNotNull('path');

        if (! $force) {
            $query->whereNull('variants');
        }

        $done = $skipped = $failed = 0;

        foreach ($query->orderBy('id')->cursor() as $upload) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            $result = $this->process($upload, $dry, $force);

            match ($result) {
                'done'   => $done++,
                'failed' => $failed++,
                default  => $skipped++,
            };
        }

        $this->info(sprintf(
            '%s%d processed, %d skipped, %d failed.',
            $dry ? '[dry-run] ' : '', $done, $skipped, $failed,
        ));

        return self::SUCCESS;
    }

    private function process(DashboardUpload $upload, bool $dry, bool $force): string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($upload->path)) {
            $this->warn("  {$upload->id}: file missing at {$upload->path}");

            return 'skipped';
        }

        $bytes  = (string) $disk->get($upload->path);
        $format = ImageProcessor::detect($bytes);

        if ($format === null) {
            $this->warn("  {$upload->id}: not a readable image");

            return 'failed';
        }

        $normalised = ImageProcessor::normalise($bytes, $format);

        if (! $normalised) {
            $this->warn("  {$upload->id}: could not decode");

            return 'failed';
        }

        $this->line(sprintf(
            '  %s  %dx%d  %s → %s',
            $upload->id,
            $normalised['width'], $normalised['height'],
            self::human(strlen($bytes)), self::human(strlen($normalised['bytes'])),
        ));

        if ($dry) {
            return 'done';
        }

        // The canonical path may change extension (a HEIC becomes a JPEG), and
        // every unit_images row pointing at the old one has to follow.
        $path = "dashboard/{$upload->kind}/{$upload->id}.{$normalised['ext']}";
        $disk->put($path, $normalised['bytes']);

        if ($force && is_array($upload->variants)) {
            ImageProcessor::forget($upload->variants);
        }

        $variants = ImageProcessor::derivatives($normalised['bytes'], "dashboard/{$upload->kind}", $upload->id) ?: null;

        if ($path !== $upload->path) {
            $disk->delete($upload->path);
        }

        $upload->update([
            'path'     => $path,
            'size'     => strlen($normalised['bytes']),
            'width'    => $normalised['width'],
            'height'   => $normalised['height'],
            'variants' => $variants,
        ]);

        UnitImage::where('file_id', $upload->id)->update([
            'path'     => $path,
            'width'    => $normalised['width'],
            'height'   => $normalised['height'],
            'variants' => $variants === null ? null : json_encode($variants),
        ]);

        return 'done';
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).'MB'
            : round($bytes / 1024).'KB';
    }
}
