<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:deployment', function (): void {
    $this->line('Nexus SBI HostGator API. Run migrations explicitly with: php artisan migrate --force');
})->purpose('Show HostGator deployment guidance');

// Grades queued submissions. The command keeps draining the queue for GRADING_RUN_SECONDS
// (default 780) and then exits, so one run fits inside HostGator's 15-minute cron minimum.
// - Local development: `php artisan schedule:work` (runs it every minute).
// - HostGator: a cPanel cron every 15 minutes running `php artisan schedule:run`, or the
//   command directly. withoutOverlapping() keeps a second schedule:run tick from starting
//   another worker while one is still draining. To run two workers in parallel, add two cron
//   lines that call `php artisan ai-tests:grade-pending` directly, offset by about 7 minutes;
//   the worker locks (GRADING_WORKERS) cap how many run at once.
Schedule::command('ai-tests:grade-pending')
    ->everyMinute()
    ->withoutOverlapping(30);
