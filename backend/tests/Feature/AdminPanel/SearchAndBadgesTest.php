<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\Booking;
use App\Models\PartnerDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin list search, the applied-sort echo, and the KYC `verified` badge —
 * the three things the admin panel could not tell had failed.
 */
class SearchAndBadgesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Individual', 'Company', 'User', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('SuperAdmin');

        $this->partner = User::factory()->create(['is_active' => true, 'name' => 'شركة الأفق']);
        $this->partner->assignRole('Company');
        $this->partner->partnerDetail()->create([
            'type' => 'company', 'cr_number' => '1010101010',
            'status' => PartnerDetail::STATUS_APPROVED,
        ]);

        $this->unit = $this->partner->units()->create([
            'unit_name' => 'شاليه الواحة', 'unit_type' => 'chalet',
            'code' => 'MRN'.fake()->unique()->numerify('#####'),
            'price' => 350, 'capacity' => 4, 'bedrooms' => 2, 'beds' => 3, 'bathrooms' => 1,
            'area' => 90, 'city' => 'جدة', 'district' => 'الشاطئ', 'lat' => 21.5, 'lng' => 39.1,
            'approval_status' => 'approved', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);
    }

    private function booking(string $guestName, string $phone): Booking
    {
        return Booking::create([
            'unit_id' => $this->unit->id,
            'user_id' => User::factory()->create(['name' => $guestName, 'phone' => $phone])->id,
            'code' => 'BK-7788', 'start_date' => now()->subDays(5), 'end_date' => now()->subDays(2),
            'guests' => 2, 'subtotal' => 3000.00, 'taxes' => 450.00, 'commission_amount' => 60.00,
            'partner_share' => 2940.00, 'total_amount' => 3450.00,
            'status' => Booking::STATUS_COMPLETED,
        ]);
    }

    private function search(string $resource, string $term): array
    {
        return $this->actingAs($this->admin, 'admin-panel')
            ->getJson("/admin/{$resource}?search=".urlencode($term))->assertOk()->json();
    }

    /* ---- search actually filters ---- */

    /**
     * A search box that returns the full queue is indistinguishable from one
     * that works — so the test that matters is that a non-matching term
     * returns NOTHING, not that a matching one returns something.
     */
    public function test_a_non_matching_booking_search_returns_no_rows(): void
    {
        $this->booking('أحمد الغامدي', '+966551234567');

        $this->assertSame(0, $this->search('bookings', 'zzzz-no-such-thing')['total']);
    }

    public function test_bookings_are_searchable_by_guest_name_unit_and_partner(): void
    {
        $this->booking('أحمد الغامدي', '+966551234567');

        // The admin-visible code is the derived BKG-#### form, covered by
        // test_the_displayed_booking_code_is_searchable.
        foreach (['أحمد الغامدي', 'شاليه الواحة', 'شركة الأفق'] as $term) {
            $this->assertSame(1, $this->search('bookings', $term)['total'], "search failed for: {$term}");
        }
    }

    /** An admin copies `BKG-0231` off the row; that string exists in no column. */
    public function test_the_displayed_booking_code_is_searchable(): void
    {
        $booking = $this->booking('أحمد الغامدي', '+966551234567');

        $body = $this->search('bookings', sprintf('BKG-%04d', $booking->id));

        $this->assertSame(1, $body['total']);
        $this->assertSame((string) $booking->id, $body['items'][0]['id']);
    }

    /** An admin types 0551234567 for a +966551234567 record. */
    public function test_a_locally_formatted_phone_matches_an_e164_record(): void
    {
        $this->booking('أحمد الغامدي', '+966551234567');

        $this->assertSame(1, $this->search('bookings', '0551234567')['total']);
        $this->assertSame(1, $this->search('bookings', '551234567')['total']);
    }

    public function test_approvals_search_covers_code_unit_partner_and_city(): void
    {
        $pending = $this->partner->units()->create([
            'unit_name' => 'استراحة النخيل', 'unit_type' => 'chalet', 'code' => 'MRN55555',
            'price' => 400, 'capacity' => 6, 'bedrooms' => 3, 'beds' => 4, 'bathrooms' => 2,
            'area' => 120, 'city' => 'الرياض', 'district' => 'النرجس', 'lat' => 24.7, 'lng' => 46.7,
            'approval_status' => 'pending', 'status' => 'available',
            'calendar_token' => str()->random(60),
        ]);

        foreach (['MRN55555', 'استراحة النخيل', 'شركة الأفق', 'الرياض'] as $term) {
            $this->assertSame(1, $this->search('approvals', $term)['total'], "search failed for: {$term}");
        }

        $this->assertSame(0, $this->search('approvals', 'لا يوجد')['total']);
        $this->assertSame((string) $pending->id, $this->search('approvals', 'MRN55555')['items'][0]['id']);
    }

    /* ---- the applied-sort echo ---- */

    /**
     * `sortBy=commission` ran the default order and looked like it worked for
     * months. The envelope now says what was actually applied.
     */
    public function test_an_unrecognised_sort_is_echoed_as_null(): void
    {
        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/bookings?sortBy=commission&sortDir=asc')->assertOk()->json();

        $this->assertNull($body['sortBy'], 'commission is not an accepted sort');
        $this->assertNull($body['sortDir']);
    }

    public function test_an_accepted_sort_is_echoed_back(): void
    {
        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/bookings?sortBy=total&sortDir=asc')->assertOk()->json();

        $this->assertSame('total', $body['sortBy']);
        $this->assertSame('asc', $body['sortDir']);
    }

    /* ---- the KYC badge ---- */

    /**
     * Approving a partner used to turn every document row green at once,
     * including rows with no file behind them. `verified` must record a review
     * of the DOCUMENT, not a decision about the partner.
     */
    public function test_approving_a_partner_does_not_verify_its_documents(): void
    {
        $documents = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->partner->id)->assertOk()->json('documents');

        $statuses = collect($documents)->pluck('status')->unique()->all();

        $this->assertSame(['pending_review'], $statuses,
            'an approved partner must not imply a per-document review');
    }

    public function test_only_an_explicitly_verified_document_reads_verified(): void
    {
        $this->actingAs($this->admin, 'admin-panel')
            ->postJson('/admin/partners/'.$this->partner->id.'/documents/commercial_registration/verify')
            ->assertOk();

        $documents = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->partner->id)->assertOk()->json('documents');

        $byKind = collect($documents)->keyBy('kind');

        $this->assertSame('verified', $byKind['commercial_registration']['status']);
        $this->assertSame('pending_review', $byKind['vat_certificate']['status'],
            'verifying one document must not verify the rest');
    }

    /* ---- documentsComplete: submission only, folded over documents[] ---- */

    /**
     * The contradiction this replaced: `documentsComplete: false` printed above
     * five rows that all read verified. It read unrelated columns AND required
     * KYC approval, so neither field was wrong about its own question and the
     * screen still disagreed with itself.
     */
    public function test_documents_complete_is_a_fold_over_the_rows_shown(): void
    {
        $detail = $this->partner->partnerDetail;

        // A company with its CR and IBAN typed but no files uploaded.
        $this->assertFalse($this->partnerDetail()['documentsComplete']);

        $detail->update([
            'iban'                      => 'SA0380000000608010167519',
            'vat_certificate_file'      => 'file_vat',
            'operator_license_file'     => 'file_licence',
            'authorization_letter_file' => 'file_auth',
        ]);

        $body = $this->partnerDetail();

        $this->assertTrue($body['documentsComplete']);

        // Deliberately NOT folded over the public `fileUrl` here: that resolves
        // through the uploads table and is null both for "never uploaded" and
        // "upload row missing". Completeness reads the stored reference, which
        // is the only thing that answers "was it supplied".
        $this->assertCount(5, $body['documents']);
    }

    /** Approval is a separate claim and must not feed completeness. */
    public function test_documents_complete_does_not_depend_on_kyc_approval(): void
    {
        $this->partner->partnerDetail->update([
            'iban'                      => 'SA0380000000608010167519',
            'vat_certificate_file'      => 'file_vat',
            'operator_license_file'     => 'file_licence',
            'authorization_letter_file' => 'file_auth',
            'status'                    => PartnerDetail::STATUS_PENDING,
        ]);

        $this->assertTrue(
            $this->partnerDetail()['documentsComplete'],
            'everything is on file; whether anyone reviewed it is the other question',
        );
    }

    private function partnerDetail(): array
    {
        return $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->partner->id)->assertOk()->json();
    }

    /* ---- city filter ---- */

    /**
     * `units.city` stores `مكة المكرمة`. A client sending English got an empty
     * list — no error, just "no results", which reads as real data.
     */
    public function test_english_city_names_resolve_to_the_stored_arabic(): void
    {
        $this->unit->update(['city' => 'مكة المكرمة']);

        foreach (['Makkah', 'makkah', 'Mecca', 'مكة المكرمة'] as $term) {
            $body = $this->actingAs($this->admin, 'admin-panel')
                ->getJson('/admin/units?city='.urlencode($term))->assertOk()->json();

            $this->assertSame(1, $body['total'], "city filter failed for: {$term}");
        }
    }

    public function test_an_unknown_city_still_filters_rather_than_matching_everything(): void
    {
        $body = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/units?city=Atlantis')->assertOk()->json();

        $this->assertSame(0, $body['total']);
    }

    public function test_the_cities_endpoint_serves_the_shared_vocabulary(): void
    {
        $rows = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/cities')->assertOk()->json();

        $byKey = collect($rows)->keyBy('key');

        $this->assertSame('مكة المكرمة', $byKey['makkah']['ar']);
        $this->assertSame('Makkah', $byKey['makkah']['en']);
        $this->assertSame('الرياض', $byKey['riyadh']['ar']);
    }

    /** A rejected partner still marks its documents rejected — not a false review claim. */
    public function test_a_rejected_partner_marks_its_documents_rejected(): void
    {
        $this->partner->partnerDetail->update(['status' => PartnerDetail::STATUS_REJECTED]);

        $documents = $this->actingAs($this->admin, 'admin-panel')
            ->getJson('/admin/partners/'.$this->partner->id)->assertOk()->json('documents');

        $this->assertSame(['rejected'], collect($documents)->pluck('status')->unique()->all());
    }
}
