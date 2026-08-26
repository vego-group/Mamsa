<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\CancellationPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `description` round trip.
 *
 * The storefront renders the field as line-based markup — `##` headings, `-`
 * lists, `>` notes — parsed on the client. All of that is positional: a marker
 * means nothing unless it is the first thing on its line. So the only contract
 * that matters here is that the bytes we are handed are the bytes we hand back.
 */
class UnitDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    /** The shape the storefront actually parses. */
    private const FORMATTED = "## ما يميّز المكان\n*مسبح خاص*\n\n## المساحات\n- **غرفة النوم:** سرير كينج.\n\n> تسجيل الدخول بعد الساعة 3 عصراً.";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CancellationPolicySeeder::class);

        foreach (['Admin', 'SuperAdmin', 'Individual', 'User'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');
        $this->partner = User::factory()->create(['is_active' => true]);
    }

    /* ---------- newlines ---------- */

    public function test_the_admin_path_stores_the_description_byte_for_byte(): void
    {
        $id = $this->createViaAdmin(['description' => self::FORMATTED]);

        $this->assertSame(self::FORMATTED, Unit::findOrFail($id)->description);
    }

    public function test_the_partner_path_stores_the_description_byte_for_byte(): void
    {
        $unit = $this->partnerUnit();

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['description' => self::FORMATTED])
            ->assertOk();

        $this->assertSame(self::FORMATTED, $unit->fresh()->description);
    }

    public function test_the_public_read_returns_the_stored_bytes(): void
    {
        $unit = $this->partnerUnit(['description' => self::FORMATTED, 'approval_status' => 'approved']);

        $this->assertSame(
            self::FORMATTED,
            $this->getJson("/api/v1/units/{$unit->id}")->assertOk()->json('data.description'),
        );
    }

    public function test_the_admin_read_returns_the_stored_bytes(): void
    {
        $id = $this->createViaAdmin(['description' => self::FORMATTED]);

        $this->assertSame(
            self::FORMATTED,
            $this->actingAs($this->admin, 'admin-panel')->getJson("/admin/units/{$id}")->assertOk()->json('description'),
        );
    }

    public function test_interior_blank_lines_and_leading_spaces_survive(): void
    {
        // A blank line between blocks is what separates one list from the next,
        // and indentation is meaningful in nested items.
        $text = "أول سطر\n\n\n  سطر بمسافتين بادئتين\nآخر سطر";

        $id = $this->createViaAdmin(['description' => $text]);

        $this->assertSame($text, Unit::findOrFail($id)->description);
    }

    /* ---------- markers ---------- */

    public function test_every_marker_character_survives_untouched(): void
    {
        $markers = "# ## ### * ** - > » • – — \n";

        $id = $this->createViaAdmin(['description' => "بداية\n{$markers}\nنهاية"]);

        $stored = (string) Unit::findOrFail($id)->description;

        foreach (['#', '##', '*', '**', '-', '>', '»', '•', '–', '—'] as $marker) {
            $this->assertStringContainsString($marker, $stored, "marker {$marker} was lost");
        }
    }

    public function test_the_note_marker_is_not_html_escaped(): void
    {
        // `>` opening a line is the note marker. htmlspecialchars at save time
        // would store `&gt;` and the guest would read it literally — and the
        // damage would be in the column, not just the render.
        $id = $this->createViaAdmin(['description' => "مقدمة\n> ملاحظة مهمة"]);

        $stored = (string) Unit::findOrFail($id)->description;

        $this->assertStringContainsString("\n> ملاحظة", $stored);
        $this->assertStringNotContainsString('&gt;', $stored);
    }

    /**
     * strip_tags() opens "tag mode" on a `<` followed by anything but a space
     * and deletes through to the next `>`. With `>` as the note marker, that
     * crossed line boundaries and ate the marker too. Every case below was
     * corrupted in the column — not the render — before this changed.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('angleBracketCases')]
    public function test_an_angle_bracket_never_deletes_surrounding_text(string $text): void
    {
        $id = $this->createViaAdmin(['description' => $text]);

        $this->assertSame($text, Unit::findOrFail($id)->description);
    }

    /** @return array<string, array{0: string}> */
    public static function angleBracketCases(): array
    {
        return [
            'space after the bracket'  => ["المساحة < 100 متر\n> ملاحظة مهمة"],
            'digit after the bracket'  => ['المساحة <100 متر مربع'],
            'letter after the bracket' => ["أقل من <b متر\n> ملاحظة مهمة"],
            'less than or equal'       => ["شروط <= ثلاثة\n> ملاحظة مهمة"],
            'a pair on one line'       => ["قارن <a و b> ثم\n> ملاحظة"],
        ];
    }

    public function test_a_script_tag_is_stored_verbatim_and_escaped_by_the_reader(): void
    {
        // strip_tags() was never protecting anything here: it turned
        // `<script>alert(1)</script>` into `alert(1)`, keeping the payload and
        // dropping only the part that made it inert to read. Safety is the
        // consumer escaping at render, which all of them do.
        $text = "الوصف <script>alert(1)</script> تم";

        $id = $this->createViaAdmin(['description' => $text]);

        $this->assertSame($text, Unit::findOrFail($id)->description);

        // JSON-encoded on the way out, so it reaches a client as data.
        $this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/units/{$id}")
            ->assertOk()
            ->assertJsonPath('description', $text);
    }

    /* ---------- length ---------- */

    public function test_two_thousand_arabic_characters_are_accepted(): void
    {
        // Arabic is three bytes per character in UTF-8, so a byte-counting rule
        // would cap this at ~666 characters while the writer's counter says 2000.
        $text = str_repeat('ك', 2000);

        $id = $this->createViaAdmin(['description' => $text]);

        $this->assertSame(2000, mb_strlen((string) Unit::findOrFail($id)->description));
    }

    public function test_beyond_the_limit_is_refused(): void
    {
        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/units', $this->body(['description' => str_repeat('ك', 2001)]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_the_partner_path_shares_the_same_limit(): void
    {
        $unit = $this->partnerUnit();

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['description' => str_repeat('ك', 2000)])
            ->assertOk();

        $this->assertSame(2000, mb_strlen((string) $unit->fresh()->description));
    }

    /* ---------- clearing ---------- */

    public function test_null_clears_an_optional_field(): void
    {
        $unit = $this->partnerUnit(['description' => 'وصف قديم', 'address' => 'عنوان قديم']);

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['description' => null, 'address' => null])
            ->assertOk();

        $this->assertNull($unit->fresh()->description);
        $this->assertNull($unit->fresh()->address);
    }

    public function test_an_empty_string_also_clears_the_field(): void
    {
        // Laravel's ConvertEmptyStringsToNull turns "" into null before the
        // rules run, so both spellings clear. Worth pinning: the console can
        // send whichever its form produces without a special case.
        $unit = $this->partnerUnit(['description' => 'وصف قديم']);

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['description' => ''])
            ->assertOk();

        $this->assertNull($unit->fresh()->description);
    }

    public function test_a_draft_may_hold_no_description_but_cannot_be_submitted_without_one(): void
    {
        // Answers "does the 10-character minimum make the field mandatory
        // forever?" — no. It gates SUBMIT, not save, so a half-finished draft
        // can sit with nothing in it.
        $unit = $this->partnerUnit(['description' => null]);

        $this->assertArrayHasKey('description', \App\Support\Units\UnitWriter::submitErrors($unit));

        $unit->update(['description' => str_repeat('و', 20)]);

        // Other gates (photos, permit) may still complain; only this one is
        // under test.
        $this->assertArrayNotHasKey('description', \App\Support\Units\UnitWriter::submitErrors($unit->fresh()));
    }

    public function test_the_submit_gate_accepts_the_full_two_thousand(): void
    {
        $unit = $this->partnerUnit(['description' => str_repeat('و', 2000)]);

        $this->assertArrayNotHasKey('description', \App\Support\Units\UnitWriter::submitErrors($unit));
    }

    public function test_an_absent_key_leaves_the_field_alone(): void
    {
        $unit = $this->partnerUnit(['description' => 'وصف قديم']);

        $this->actingAs($this->partner, 'dashboard')
            ->patchJson("/units/u_{$unit->id}", ['bedrooms' => 3])
            ->assertOk();

        $this->assertSame('وصف قديم', $unit->fresh()->description);
    }

    /* ---------- helpers ---------- */

    /** @param array<string, mixed> $overrides */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'وحدة اختبار',
            'type'          => 'apartment',
            'city'          => 'الرياض',
            'district'      => 'النرجس',
            'pricePerNight' => 400,
            'bedrooms'      => 2,
            'bathrooms'     => 1,
            'capacity'      => 4,
            'sizeSqm'       => 90,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createViaAdmin(array $overrides = []): string
    {
        return (string) $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/units', $this->body($overrides))
            ->assertStatus(201)
            ->json('id');
    }

    /** @param array<string, mixed> $attrs */
    private function partnerUnit(array $attrs = []): Unit
    {
        return $this->partner->units()->create(array_merge([
            'unit_name'       => 'وحدة شريك',
            'unit_type'       => 'apartment',
            'code'            => 'MRN'.fake()->unique()->numerify('#####'),
            'price'           => 300,
            'capacity'        => 2,
            'bedrooms'        => 1,
            'approval_status' => 'draft',
            'status'          => 'available',
            'calendar_token'  => str()->random(60),
        ], $attrs));
    }
}
