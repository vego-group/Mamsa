<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The snapshot exists to make a skipped procedure step VISIBLE. So the two
 * things it must never do are miss a contract change, and cry about a
 * non-change — either one and people stop running it.
 */
class ApiSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/snapshots');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function take(string $name): string
    {
        $path = $this->dir."/{$name}.json";
        $this->artisan("api:snapshot --out={$path}")->assertSuccessful();

        return $path;
    }

    public function test_a_snapshot_records_routes_and_public_shapes(): void
    {
        $snap = json_decode(file_get_contents($this->take('a')), true);

        $this->assertNotEmpty($snap['routes']);
        $this->assertNotEmpty($snap['shapes']);
        $this->assertContains('GET api/v1/units', $snap['routes']);
    }

    public function test_two_identical_snapshots_report_no_change(): void
    {
        $a = $this->take('a');
        $b = $this->take('b');

        $this->artisan("api:snapshot --diff={$a} --against={$b}")
            ->expectsOutputToContain('No contract change')
            ->assertSuccessful();
    }

    public function test_an_added_key_is_reported_and_fails(): void
    {
        $a = $this->take('a');

        // Hand-edit the later snapshot: standing in for a deploy that added a
        // field to a public response.
        $after = json_decode(file_get_contents($a), true);
        $key   = array_key_first($after['shapes']);
        $after['shapes'][$key]['keys'][] = 'data[].secret_new_field';
        file_put_contents($b = $this->dir.'/b.json', json_encode($after));

        $this->artisan("api:snapshot --diff={$a} --against={$b}")
            ->expectsOutputToContain('secret_new_field')
            ->expectsOutputToContain('goes to the frontend BEFORE it ships')
            ->assertFailed();
    }

    public function test_a_removed_route_is_reported_and_fails(): void
    {
        $a     = $this->take('a');
        $after = json_decode(file_get_contents($a), true);
        array_shift($after['routes']);
        file_put_contents($b = $this->dir.'/b.json', json_encode($after));

        $this->artisan("api:snapshot --diff={$a} --against={$b}")
            ->expectsOutputToContain('REMOVED route')
            ->assertFailed();
    }

    public function test_the_snapshot_carries_no_values(): void
    {
        $raw = file_get_contents($this->take('a'));

        // Shapes only. A snapshot that embedded payloads would be a file of
        // partner addresses and permit numbers being emailed around.
        $this->assertStringNotContainsString('tourism_permit_no"  :', $raw);
        foreach (json_decode($raw, true)['shapes'] as $shape) {
            $this->assertArrayNotHasKey('body', $shape);
            $this->assertSame(['status', 'keys'], array_keys($shape));
        }
    }
}
