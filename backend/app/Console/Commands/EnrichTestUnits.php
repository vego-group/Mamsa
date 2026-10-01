<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DashboardUpload;
use App\Models\Unit;
use App\Support\Images\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Give the test units the content the newest features actually need.
 *
 * The existing seeders predate all of it: they point every unit at one shared
 * placeholder and write single-line descriptions. So nothing on staging
 * exercised the derivative pipeline, the line-based description markup, or the
 * 2000-character limit — the three things the consoles most recently shipped
 * against, and the ones a frontend cannot verify without data that uses them.
 *
 * Photos go through the REAL pipeline (normalise → strip metadata → derive
 * thumb/card/full), because seeding the columns directly would produce rows
 * that look right and prove nothing.
 *
 * Non-production only, and idempotent: a unit that already has real photos is
 * left alone unless --fresh.
 *
 *   php artisan test-units:enrich
 *   php artisan test-units:enrich --fresh
 */
class EnrichTestUnits extends Command
{
    protected $signature = 'test-units:enrich
        {--fresh : Rebuild photos for units that already have them}
        {--photos=4 : Photos per unit}';

    protected $description = 'Give test units real photos with derivatives, and formatted descriptions';

    /**
     * Formatted descriptions in the storefront's own markup — headings, feature
     * cards, lists, numbered steps and a note. The third deliberately contains
     * `<=`, which strip_tags() used to delete along with the line break and the
     * note marker that followed it.
     *
     * @var list<string>
     */
    private const DESCRIPTIONS = [
        "## ما يميّز المكان\n*مسبح خاص*\n*تسجيل دخول ذاتي*\n*موقف خاص*\n\n## المساحات\n- **غرفة النوم:** سرير كينج مع دولاب واسع.\n- **الصالة:** جلسة عائلية وتلفزيون بشاشة مسطّحة.\n- **المطبخ:** مجهّز بالكامل مع غسّالة صحون.\n\n## طريقة الوصول\n1. اخرج من البوابة الشمالية.\n2. اتجه يميناً 400 متر.\n3. المبنى على يسارك بعد الصيدلية.\n\n> تسجيل الدخول بعد الساعة 3 عصراً، والخروج قبل 12 ظهراً.",

        "## عن الوحدة\nشقة عصرية بإطلالة مفتوحة، مناسبة للعائلات وللإقامات الطويلة.\n\n## المرافق\n- واي فاي عالي السرعة\n- تكييف مركزي\n- مصعد\n\n> يُمنع التدخين داخل الوحدة.",

        "## الموقع\nعلى بُعد دقائق من الوجهات الرئيسية.\nالمساحة <= 100 متر مربع، وهي كافية لأربعة أشخاص بأريحية.\n\n## ملاحظات\n- الوصول الذاتي متاح على مدار الساعة.\n- يتوفّر موقف واحد ضمن المبنى.\n\n> الحدّ الأقصى أربعة ضيوف — لا يُسمح بالزيارات الليلية.",
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to run on production — this writes test content.');

            return self::FAILURE;
        }

        if (! ImageProcessor::available()) {
            $this->error('No image backend available.');

            return self::FAILURE;
        }

        $this->info('Driver: '.ImageProcessor::driver()?->name());

        $pool = $this->sourcePool();
        $this->line('Source images: '.count($pool));

        $photos = max(1, (int) $this->option('photos'));
        $fresh  = (bool) $this->option('fresh');

        $withPhotos = $withText = 0;
        $index = 0;

        foreach (Unit::with('images')->orderBy('id')->get() as $unit) {
            $hasReal = $unit->images->contains(fn ($i) => filled($i->file_id));

            if (! $hasReal || $fresh) {
                $this->attachPhotos($unit, $pool, $photos, $index);
                $withPhotos++;
            }

            // Every unit gets markup, rotating so a reviewer sees more than one
            // shape. The long one lands on a single unit — see below.
            $unit->update(['description' => self::DESCRIPTIONS[$index % count(self::DESCRIPTIONS)]]);
            $withText++;
            $index++;
        }

        $this->edgeCases();

        $this->newLine();
        $this->info("Photos rebuilt for {$withPhotos} unit(s); descriptions written for {$withText}.");

        return self::SUCCESS;
    }

    /**
     * Attach a fresh set of photos, each one through the real upload pipeline
     * so the stored rows are indistinguishable from a partner's upload.
     *
     * @param  list<array{bytes: string, label: string}>  $pool
     */
    private function attachPhotos(Unit $unit, array $pool, int $count, int $offset): void
    {
        $this->forgetPhotos($unit);

        $owner = (int) $unit->user_id;

        for ($n = 0; $n < $count; $n++) {
            $source = $pool[($offset + $n) % count($pool)];
            $format = ImageProcessor::detect($source['bytes']);

            if ($format === null) {
                continue;
            }

            $normalised = ImageProcessor::normalise($source['bytes'], $format);

            if (! $normalised) {
                continue;
            }

            $id   = 'file_'.Str::lower((string) Str::ulid());
            $path = "dashboard/unit_photo/{$id}.{$normalised['ext']}";

            Storage::disk('public')->put($path, $normalised['bytes']);

            $variants = ImageProcessor::derivatives($normalised['bytes'], 'dashboard/unit_photo', $id) ?: null;

            DashboardUpload::create([
                'id'            => $id,
                'user_id'       => $owner,
                'kind'          => 'unit_photo',
                'original_name' => $source['label'],
                'mime'          => 'image/'.($normalised['ext'] === 'jpg' ? 'jpeg' : $normalised['ext']),
                'size'          => strlen($normalised['bytes']),
                'path'          => $path,
                'status'        => 'stored',
                'width'         => $normalised['width'],
                'height'        => $normalised['height'],
                'variants'      => $variants,
            ]);

            $unit->images()->create([
                'file_id'    => $id,
                'path'       => $path,
                'is_main'    => $n === 0,
                'sort_order' => $n,
                'width'      => $normalised['width'],
                'height'     => $normalised['height'],
                'variants'   => $variants,
            ]);
        }

        $this->line(sprintf('  unit %-4d %s', $unit->id, Str::limit((string) $unit->unit_name, 40)));
    }

    /** Drop a unit's existing photos, including the files behind them. */
    private function forgetPhotos(Unit $unit): void
    {
        foreach ($unit->images as $image) {
            if (is_array($image->variants)) {
                ImageProcessor::forget($image->variants);
            }
            if (filled($image->file_id)) {
                Storage::disk('public')->delete((string) $image->path);
                DashboardUpload::whereKey($image->file_id)->delete();
            }
        }

        $unit->images()->delete();
    }

    /**
     * The specific shapes a frontend needs to see at least once, and which a
     * rotation would otherwise never produce.
     */
    private function edgeCases(): void
    {
        $units = Unit::orderBy('id')->get();

        // A description near the new ceiling, so the character counter and the
        // 2000 limit can both be exercised against something real.
        if ($long = $units->get(0)) {
            $body = "## وصف مطوّل\n";
            while (mb_strlen($body) < 1900) {
                $body .= "- تفصيل إضافي عن الوحدة ومرافقها وموقعها القريب من الخدمات.\n";
            }
            $long->update(['description' => mb_substr($body, 0, 1980)."\n\n> نهاية الوصف."]);
            $this->line('  long description  → unit '.$long->id.' ('.mb_strlen((string) $long->fresh()->description).' chars)');
        }

        // An address carrying a `<`, which used to lose everything after it.
        if ($addr = $units->get(1)) {
            $addr->update(['address' => '<200م من المسجد، حي النرجس، الرياض']);
            $this->line('  address with `<`  → unit '.$addr->id);
        }

        // A unit with no amenities at all, so the empty state is reachable.
        if ($bare = $units->get(2)) {
            $bare->features()->detach();
            $this->line('  no amenities      → unit '.$bare->id);
        }
    }

    /**
     * Source photography, mixed on purpose:
     *
     *  - the curated artwork already on the disk, which is REAL and smaller than
     *    every derivative box, so it exercises the never-upscale path;
     *  - two generated frames large enough to exercise the 2048 cap and a
     *    portrait crop, which no bundled asset is big enough to reach.
     *
     * @return list<array{bytes: string, label: string}>
     */
    private function sourcePool(): array
    {
        $pool = [];
        $disk = Storage::disk('public');

        foreach (['categories/apartment.jpg', 'categories/studio.jpg', 'categories/villa.jpg',
            'budgets/1000_2000.jpg', 'budgets/2000_3000.jpg', 'budgets/500_1000.jpg'] as $path) {
            if ($disk->exists($path)) {
                $pool[] = ['bytes' => (string) $disk->get($path), 'label' => basename($path)];
            }
        }

        foreach ([[3000, 2000, 'wide-3000x2000.jpg'], [1200, 1800, 'tall-1200x1800.jpg']] as [$w, $h, $label]) {
            if ($bytes = $this->generate($w, $h)) {
                $pool[] = ['bytes' => $bytes, 'label' => $label];
            }
        }

        return $pool;
    }

    /** A synthetic frame — banded, so a resize is visible rather than flat colour. */
    private function generate(int $width, int $height): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x++) {
            $t = $x / max(1, $width - 1);
            $colour = imagecolorallocate($image, (int) (40 + 180 * $t), (int) (90 + 60 * $t), (int) (160 - 60 * $t));
            imageline($image, $x, 0, $x, $height, $colour);
        }

        $band = imagecolorallocate($image, 250, 250, 250);
        for ($i = 1; $i <= 4; $i++) {
            $y = (int) ($height * $i / 5);
            imagefilledrectangle($image, 0, $y, $width, $y + max(2, (int) ($height / 120)), $band);
        }

        ob_start();
        imagejpeg($image, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes ?: null;
    }
}
