<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A validation failure names every field, whichever way it was raised.
 *
 * The admin surface has two paths to the same outcome:
 * AdminPanel\Controller::validate(), which has always returned every field, and
 * the exception renderer, which caught anything raised the ordinary Laravel way
 * — a FormRequest, or a bare $request->validate() — and returned one sentence
 * with no fields at all.
 *
 * So the answer depended on how the check happened to be written. No admin
 * controller uses the second form today, which is exactly why it was worth
 * closing: the next person to reach for the ordinary idiom would have got the
 * thin version, and an admin would be sent round the form once per mistake.
 *
 * The route here is defined by the test because no production route exercises
 * that path — the point is the renderer, not any particular endpoint.
 */
class ValidationEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'SuperAdmin'] as $r) {
            Role::findOrCreate($r, 'web');
        }

        Route::middleware('admin-panel')->post('/admin/__validation_probe', function (Request $request) {
            $request->validate([
                'permitNumber' => ['required'],
                'permitFile' => ['required'],
                'latitude' => ['required', 'numeric'],
            ]);

            return response()->json(['ok' => true]);
        });
    }

    public function test_every_failing_field_comes_back_not_only_the_first(): void
    {
        $response = $this->actingAs($this->admin(), 'admin-panel')
            ->postJson('/admin/__validation_probe', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        // The whole point: three mistakes, one round trip. Returning only the
        // first sends an admin back through the form once per field.
        $response
            ->assertJsonStructure(['message', 'code', 'fields' => ['permitNumber', 'permitFile', 'latitude']]);

        $this->assertCount(3, $response->json('fields'));
    }

    public function test_the_envelope_matches_the_one_the_helper_produces(): void
    {
        // Same shape either way, so a client cannot tell which path ran:
        // flat `message` + `code`, with `fields` keyed by request-body key.
        $json = $this->actingAs($this->admin(), 'admin-panel')
            ->postJson('/admin/__validation_probe', ['permitNumber' => 'TL-1'])
            ->assertStatus(422)
            ->json();

        $this->assertSame(['message', 'code', 'fields'], array_keys($json));
        $this->assertArrayNotHasKey('permitNumber', $json['fields'], 'a field that passed must not be reported');
        $this->assertArrayHasKey('permitFile', $json['fields']);
        $this->assertIsString($json['fields']['permitFile']);
    }

    public function test_a_non_validation_failure_carries_no_fields_key(): void
    {
        // `fields` is omitted rather than sent empty — a key that is always
        // present but sometimes meaningless is one a client has to test anyway.
        $this->getJson('/admin/me')
            ->assertStatus(401)
            ->assertJsonMissingPath('fields');
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('SuperAdmin');

        return $admin;
    }
}
