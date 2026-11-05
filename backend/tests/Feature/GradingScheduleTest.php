<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class GradingScheduleTest extends TestCase
{
    public function test_the_grading_command_is_scheduled_every_minute_without_overlapping(): void
    {
        // Listing the commands loads routes/console.php, where the schedule is declared.
        $this->artisan('list')->assertExitCode(0);

        $events = collect(app(Schedule::class)->events())
            ->filter(static fn (Event $event): bool => str_contains((string) $event->command, 'ai-tests:grade-pending'))
            ->values();

        self::assertCount(1, $events, 'ai-tests:grade-pending must be registered exactly once.');
        /** @var Event $event */
        $event = $events->first();
        self::assertSame('* * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping, 'A second schedule:run tick must not start another worker.');
        self::assertGreaterThanOrEqual(
            20,
            $event->expiresAt,
            'The overlap mutex has to outlive a full drain run (about 13 minutes).'
        );
    }

    public function test_the_command_exists_and_the_run_budget_fits_the_cron_interval(): void
    {
        self::assertArrayHasKey('ai-tests:grade-pending', \Illuminate\Support\Facades\Artisan::all());
        // HostGator's cron minimum is 15 minutes, so a run must end before the next tick.
        self::assertLessThan(15 * 60, (int) config('services.grading.run_seconds'));
        self::assertGreaterThanOrEqual(10 * 60, (int) config('services.grading.run_seconds'));
    }
}
