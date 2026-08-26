<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DashboardUpload;
use App\Models\Unit;
use App\Models\UnitImage;
use App\Models\User;
use App\Support\Images\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFactory;
use Tests\TestCase;

/**
 * The unit-photo pipeline: what the receiver accepts, what it rewrites, and
 * what the storefront gets back.
 */
class UnitImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->partner = User::factory()->create();
    }

    /* ---------- geometry ---------- */

    public function test_a_small_original_is_cropped_to_shape_but_never_enlarged(): void
    {
        // 432×768 portrait → 4:3 card. The crop is real, the upscale is not:
        // stretching 432px to 800px would invent detail that was never shot.
        [$cw, $ch] = ImageProcessor::coverCrop(432, 768, 800, 600);
        [$tw, $th] = ImageProcessor::coverTarget($cw, $ch, 800, 600);

        $this->assertSame([432, 324], [$cw, $ch], 'crop keeps the full width of a portrait');
        $this->assertSame([432, 324], [$tw, $th], 'and stays at its own resolution');
    }

    public function test_a_large_original_is_cropped_then_scaled_down(): void
    {
        [$cw, $ch] = ImageProcessor::coverCrop(4000, 3000, 400, 300);
        [$tw, $th] = ImageProcessor::coverTarget($cw, $ch, 400, 300);

        $this->assertSame([4000, 3000], [$cw, $ch]);
        $this->assertSame([400, 300], [$tw, $th]);
    }

    public function test_full_never_upscales_past_the_source(): void
    {
        // The request asked for a 2048 long edge. Everything currently in the
        // library is 1024, and blowing it up is the "invented detail" the
        // frontend explicitly ruled out for AI upscaling — same objection.
        $this->assertSame([1024, 576], ImageProcessor::containTarget(1024, 576, 2048));
        $this->assertSame([2048, 1152], ImageProcessor::containTarget(4096, 2304, 2048));
        $this->assertSame([1152, 2048], ImageProcessor::containTarget(2304, 4096, 2048));
    }

    /* ---------- format detection ---------- */

    public function test_it_detects_the_formats_from_bytes_not_from_the_claimed_mime(): void
    {
        $this->assertSame('jpeg', ImageProcessor::detect(ImageFactory::jpeg(64, 64)));
        $this->assertSame('png', ImageProcessor::detect(ImageFactory::png(64, 64)));
        $this->assertSame('webp', ImageProcessor::detect(ImageFactory::webp(64, 64)));
        $this->assertNull(ImageProcessor::detect('%PDF-1.4 not an image'));

        // RIFF alone is a WAV container; only RIFF....WEBP is an image.
        $this->assertNull(ImageProcessor::detect('RIFF'.str_repeat("\x00", 4).'WAVEfmt '));
    }

    /* ---------- the receiver ---------- */

    public function test_a_photo_upload_is_measured_and_derived(): void
    {
        $upload = $this->send($this->presign(), ImageFactory::jpeg(1600, 1200));

        $upload->refresh();

        $this->assertSame(1600, $upload->width);
        $this->assertSame(1200, $upload->height);
        $this->assertSame(['thumb', 'card', 'full'], array_keys($upload->variants));

        foreach ($upload->variants as $path) {
            Storage::disk('public')->assertExists($path);
        }

        // Deterministic names, so a URL can be rebuilt without a lookup.
        $this->assertSame("dashboard/unit_photo/{$upload->id}_thumb.webp", $upload->variants['thumb']);
    }

    public function test_the_derivatives_are_smaller_than_the_original(): void
    {
        // Kept modest on purpose: GD holds 4 bytes per pixel while it works, and
        // the suite shares one 128M process.
        $upload = $this->send($this->presign(), ImageFactory::jpeg(1600, 1200))->refresh();

        $disk     = Storage::disk('public');
        $original = strlen((string) $disk->get($upload->path));
        $thumb    = strlen((string) $disk->get($upload->variants['thumb']));

        $this->assertLessThan($original, $thumb);

        // The point of the whole exercise: a 96×64 slot must stop pulling a
        // full photograph down a mobile connection.
        $this->assertLessThan(0.2 * $original, $thumb);
    }

    public function test_a_photo_below_the_minimum_resolution_is_refused(): void
    {
        $this->call('PUT', $this->presign(), [], [], [], [], ImageFactory::jpeg(320, 240))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IMAGE_TOO_SMALL');
    }

    public function test_the_minimum_is_measured_on_the_long_and_short_edge(): void
    {
        // 576×1024 is the same photo held upright. Reading the rule as
        // width≥1024 would reject every portrait, which is the shape the
        // full-screen viewer exists for.
        $this->send($this->presign(), ImageFactory::jpeg(576, 1024));

        $this->assertSame(1, DashboardUpload::where('status', 'stored')->count());
    }

    public function test_webp_is_accepted(): void
    {
        // Previously refused: the magic-byte table only listed PNG and JPEG,
        // so a WebP came back as "نوع الملف غير صالح — مسموح: png/jpg".
        $upload = $this->send($this->presign(), ImageFactory::webp(1280, 720))->refresh();

        $this->assertSame('stored', $upload->status);
        $this->assertStringEndsWith('.webp', $upload->path);
    }

    public function test_a_png_stays_a_png(): void
    {
        $upload = $this->send($this->presign(), ImageFactory::png(1280, 720))->refresh();

        $this->assertStringEndsWith('.png', $upload->path);
    }

    public function test_a_file_that_only_pretends_to_be_an_image_is_refused(): void
    {
        // A valid PNG signature with no image behind it. This used to be
        // stored, and the storefront would serve a broken <img>.
        $this->call('PUT', $this->presign(), [], [], [], [], "\x89PNG\r\n\x1a\n")
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_FILE_TYPE');
    }

    public function test_a_pdf_is_still_refused_for_a_photo(): void
    {
        $this->call('PUT', $this->presign(), [], [], [], [], '%PDF-1.4 whatever')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_FILE_TYPE');
    }

    /* ---------- metadata ---------- */

    public function test_metadata_segments_do_not_survive_the_upload(): void
    {
        $source = ImageFactory::jpegWithExifSegment(1280, 720);

        $this->assertStringContainsString('Exif', $source, 'the fixture must actually carry one');

        $upload = $this->send($this->presign(), $source)->refresh();
        $stored = (string) Storage::disk('public')->get($upload->path);

        // Camera EXIF pins the property to within a few metres and these files
        // are served from a public bucket, so this is a live data leak, not a
        // tidiness question.
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('GPSLatitude', $stored);
    }

    public function test_a_clean_file_is_not_re_encoded_just_to_make_it_bigger(): void
    {
        // A photo already saved at a low quality comes back LARGER at 90. That
        // trade only pays for itself when there is metadata to strip.
        $source = ImageFactory::jpeg(1280, 720, quality: 40);

        $this->assertFalse(ImageProcessor::carriesMetadata($source, 'jpeg'));

        $upload = $this->send($this->presign(), $source)->refresh();
        $stored = (string) Storage::disk('public')->get($upload->path);

        $this->assertSame($source, $stored, 'clean bytes should be left alone');
        $this->assertSame(1280, $upload->width, 'and still measured');
        $this->assertNotNull($upload->variants, 'and still derived');
    }

    public function test_a_file_carrying_metadata_is_rewritten_even_if_it_grows(): void
    {
        $source = ImageFactory::jpegWithExifSegment(1280, 720);

        $this->assertTrue(ImageProcessor::carriesMetadata($source, 'jpeg'));

        $stored = (string) Storage::disk('public')
            ->get($this->send($this->presign(), $source)->refresh()->path);

        $this->assertNotSame($source, $stored);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    /* ---------- the read side ---------- */

    public function test_the_unit_resource_carries_dimensions_and_variants(): void
    {
        $unit = $this->unit();

        UnitImage::create([
            'unit_id'  => $unit->id,
            'path'     => 'dashboard/unit_photo/x.jpg',
            'is_main'  => true,
            'width'    => 1600,
            'height'   => 1200,
            'variants' => ['thumb' => 'dashboard/unit_photo/x_thumb.webp'],
        ]);

        $image = $this->getJson("/api/v1/units/{$unit->id}")
            ->assertOk()
            ->json('data.images.0');

        $this->assertSame(1600, $image['width']);
        $this->assertSame(1200, $image['height']);
        $this->assertStringEndsWith('/storage/dashboard/unit_photo/x_thumb.webp', $image['variants']['thumb']);

        // `url` is untouched so an unmigrated client keeps working.
        $this->assertStringEndsWith('/storage/dashboard/unit_photo/x.jpg', $image['url']);
    }

    public function test_an_image_without_derivatives_reports_null_rather_than_the_original(): void
    {
        $unit = $this->unit();

        UnitImage::create([
            'unit_id' => $unit->id,
            'path'    => 'dashboard/unit_photo/legacy.jpg',
            'is_main' => true,
        ]);

        $image = $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->json('data.images.0');

        // Pointing `variants.thumb` at the full-size original would satisfy the
        // shape and defeat the purpose; the client needs to know to fall back.
        $this->assertNull($image['variants']);
        $this->assertNull($image['width']);
    }

    public function test_photos_come_back_in_the_order_they_were_attached(): void
    {
        $unit = $this->unit();

        // Rows deliberately inserted against their intended order, so passing
        // means sort_order decided it and not the auto-increment id.
        foreach ([['c.jpg', true, 2], ['a.jpg', false, 0], ['b.jpg', false, 1]] as [$file, $main, $order]) {
            UnitImage::create([
                'unit_id'    => $unit->id,
                'path'       => "dashboard/unit_photo/{$file}",
                'is_main'    => $main,
                'sort_order' => $order,
            ]);
        }

        $images = $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->json('data.images');

        $this->assertStringEndsWith('a.jpg', $images[0]['url']);
        $this->assertStringEndsWith('b.jpg', $images[1]['url']);
        $this->assertStringEndsWith('c.jpg', $images[2]['url']);

        // The cover is identified by the flag, not by its position. Hoisting it
        // to index 0 would have been a silent reordering of a live contract.
        $this->assertTrue($images[2]['is_main']);
    }

    public function test_rows_written_before_sort_order_existed_keep_their_order(): void
    {
        $unit = $this->unit();

        foreach (['x.jpg', 'y.jpg', 'z.jpg'] as $file) {
            UnitImage::create([
                'unit_id' => $unit->id,
                'path'    => "dashboard/unit_photo/{$file}",
                'is_main' => false,
            ]);
        }

        $urls = $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->json('data.images.*.url');

        // All default to sort_order 0, so id breaks the tie — which is exactly
        // the order they were already coming back in before this change.
        $this->assertStringEndsWith('x.jpg', $urls[0]);
        $this->assertStringEndsWith('z.jpg', $urls[2]);
    }

    /* ---------- helpers ---------- */

    private function unit(): Unit
    {
        return $this->partner->units()->create([
            'unit_name'       => 'وحدة اختبار',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'approval_status' => 'approved',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ]);
    }

    private function presign(): string
    {
        return $this->actingAs($this->partner, 'dashboard')
            ->postJson('/uploads/presign', [
                'kind' => 'unit_photo', 'fileName' => 'p.jpg', 'mimeType' => 'image/jpeg', 'size' => 1024,
            ])->json('uploadUrl');
    }

    private function send(string $url, string $bytes): DashboardUpload
    {
        $this->call('PUT', $url, [], [], [], [], $bytes)->assertOk();

        return DashboardUpload::latest('created_at')->firstOrFail();
    }
}
