<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\CheckCollation;
use App\Notifications\CollationFallback;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckCollationTest extends TestCase
{
    use RefreshDatabase;

    private function serverLoads(string $locale): void
    {
        $this->app->bind(CheckCollation::class, fn () => new class($locale) extends CheckCollation
        {
            public function __construct(private readonly string $fake)
            {
                parent::__construct();
            }

            protected function actualLocale(): string
            {
                return $this->fake;
            }
        });
    }

    public function test_arabic_collation_passes_and_alerts_nobody(): void
    {
        Notification::fake();
        $this->serverLoads('ar');

        $this->artisan('ops:check-collation --alert')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_root_fallback_fails_and_mails_the_value_that_came_back(): void
    {
        Notification::fake();
        config()->set('complaints.alert_recipients', ['ops@example.test']);
        $this->serverLoads('root');

        $this->artisan('ops:check-collation --alert')->assertFailed();

        Notification::assertSentOnDemand(CollationFallback::class, function (CollationFallback $n, array $channels, AnonymousNotifiable $to) {
            $mail = $n->toMail($to);
            $text = $mail->subject.' '.implode(' ', $mail->introLines);

            return $n->actualLocale === 'root'
                && str_contains($mail->subject, '"root"')
                && str_contains($text, 'المتوقع: ar')
                && $to->routes['mail'] === ['ops@example.test'];
        });
    }

    public function test_without_the_alert_flag_it_only_reports(): void
    {
        Notification::fake();
        $this->serverLoads('root');

        $this->artisan('ops:check-collation')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_it_is_scheduled_daily_with_the_alert_flag(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'ops:check-collation --alert'));

        $this->assertCount(1, $events);
    }
}
