<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The access log writes exactly the six approved fields (2026-10-01) and
 * nothing else — no query string, no body, no headers.
 */
class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/access-log-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        config()->set('logging.channels.access.path', $this->dir.'/access.log');
        Log::forgetChannel('access');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(): array
    {
        $lines = [];
        foreach (File::glob($this->dir.'/access-*.log') as $file) {
            foreach (array_filter(explode("\n", File::get($file))) as $line) {
                $lines[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }

    public function test_a_request_writes_one_line_with_exactly_the_approved_fields(): void
    {
        config()->set('logging.access_log.enabled', true);

        $this->getJson('/api/v1/config?secret=do-not-log', ['X-Custom' => 'do-not-log'])->assertOk();

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $line = $lines[0];

        $this->assertSame(['t', 'ip', 'path', 'status', 'ms', 'uid'], array_keys($line));
        $this->assertSame('/api/v1/config', $line['path']);
        $this->assertSame(200, $line['status']);
        $this->assertSame('127.0.0.1', $line['ip']);
        $this->assertIsInt($line['ms']);
        $this->assertNull($line['uid']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $line['t']);

        $raw = implode('', array_map(File::get(...), File::glob($this->dir.'/access-*.log')));
        $this->assertStringNotContainsString('do-not-log', $raw);
    }

    public function test_the_signed_in_user_is_recorded(): void
    {
        config()->set('logging.access_log.enabled', true);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/config');

        $this->assertSame($user->id, $this->lines()[0]['uid']);
    }

    public function test_error_responses_are_logged_with_their_status(): void
    {
        config()->set('logging.access_log.enabled', true);

        $this->getJson('/api/v1/units/999999999')->assertNotFound();

        $this->assertSame(404, $this->lines()[0]['status']);
    }

    public function test_the_calendar_token_in_the_path_is_masked(): void
    {
        config()->set('logging.access_log.enabled', true);
        $token = str_repeat('Ab1', 20);

        $this->get("/api/v1/calendar/{$token}.ics");
        // Malformed: matches no route, and is still never written out.
        $this->get('/api/v1/calendar/short-SECRETISH.ics');

        $this->assertSame('/api/v1/calendar/***.ics', $this->lines()[0]['path']);
        $this->assertSame('/api/v1/calendar/***.ics', $this->lines()[1]['path']);

        $raw = implode('', array_map(File::get(...), File::glob($this->dir.'/access-*.log')));
        $this->assertStringNotContainsString($token, $raw);
        $this->assertStringNotContainsString('SECRETISH', $raw);
    }

    public function test_nothing_is_written_when_disabled(): void
    {
        config()->set('logging.access_log.enabled', false);

        $this->getJson('/api/v1/config')->assertOk();

        $this->assertSame([], $this->lines());
    }
}
