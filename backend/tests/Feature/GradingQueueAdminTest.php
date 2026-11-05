<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

class GradingQueueAdminTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    private string $testId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryPrivateStorage();
        [, $this->testId] = $this->createTest();
    }

    private function adminToken(string $permission = 'admin'): string
    {
        $admin = $this->createUser('admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $admin,
            'granteeId' => $admin,
            'permission' => $permission,
            'grantedAt' => now(),
        ]);

        return $this->token($admin);
    }

    public function test_it_lists_failed_blocked_stuck_and_backlogged_papers_but_not_healthy_ones(): void
    {
        $failed = $this->insertAttempt($this->testId, [
            'status' => 'FAILED', 'gradingError' => 'Automated grading failed.', 'gradingAttempts' => 3, 'submittedMinutesAgo' => 50,
        ]);
        $blocked = $this->insertAttempt($this->testId, [
            'status' => 'SUBMITTED', 'gradingError' => 'Needs manual review.', 'submittedMinutesAgo' => 40,
        ]);
        $stuck = $this->insertAttempt($this->testId, [
            'status' => 'PROCESSING', 'updatedMinutesAgo' => 90, 'submittedMinutesAgo' => 100,
        ]);
        $backlog = $this->insertAttempt($this->testId, ['status' => 'SUBMITTED', 'submittedMinutesAgo' => 120]);
        $freshlyWaiting = $this->insertAttempt($this->testId, ['status' => 'SUBMITTED', 'submittedMinutesAgo' => 5]);
        $grading = $this->insertAttempt($this->testId, ['status' => 'PROCESSING', 'updatedMinutesAgo' => 3]);
        $completed = $this->insertAttempt($this->testId, ['status' => 'COMPLETED']);

        $response = $this->withToken($this->adminToken())->getJson('/api/tests/grading-queue')->assertOk();

        $issues = collect($response->json('attempts'))->pluck('issue', 'id')->all();
        self::assertEquals([
            $failed => 'failed',
            $blocked => 'blocked',
            $stuck => 'stuck',
            $backlog => 'backlog',
        ], $issues);
        self::assertNotContains($freshlyWaiting, array_keys($issues));
        self::assertNotContains($grading, array_keys($issues));
        self::assertNotContains($completed, array_keys($issues));

        $response->assertJsonPath('attentionTotal', 4)
            ->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('counts.blocked', 1)
            ->assertJsonPath('counts.waiting', 2)
            ->assertJsonPath('counts.processing', 2)
            ->assertJsonPath('counts.completedLast24h', 1)
            ->assertJsonPath('settings.providerRetryDelayMinutes.0', 5)
            ->assertJsonPath('settings.providerRetryDelayMinutes.3', 60);
        self::assertGreaterThanOrEqual(119, $response->json('oldestWaitingMinutes'));
        $first = $response->json('attempts.0');
        foreach (['studentEmail', 'testTitle', 'gradingError', 'waitingMinutes', 'gradingAttempts'] as $key) {
            self::assertArrayHasKey($key, $first);
        }
        // The list is oldest first so the longest-waiting paper is on top.
        self::assertSame($backlog, $first['id']);
    }

    public function test_it_reports_ai_notebook_usage_for_today(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();
        DB::table('ai_notebook_daily_usage')->insert([
            ['userId' => $first, 'usageDate' => now()->toDateString(), 'requestCount' => 7, 'updatedAt' => now()],
            ['userId' => $second, 'usageDate' => now()->toDateString(), 'requestCount' => 5, 'updatedAt' => now()],
        ]);
        DB::table('ai_notebook_daily_usage')->insert([
            'userId' => $this->createUser(), 'usageDate' => now()->subDay()->toDateString(), 'requestCount' => 20, 'updatedAt' => now(),
        ]);

        $this->withToken($this->adminToken())->getJson('/api/tests/grading-queue')
            ->assertOk()
            ->assertJsonPath('usage.aiNotebookRequestsToday', 12);
    }

    public function test_estimated_clear_time_uses_only_measured_successful_grading_samples_and_admin_alerts(): void
    {
        Cache::put('nexus:grading:paper-duration-samples', [20.0, 40.0], now()->addDay());
        Cache::put(\App\Console\Commands\GradePendingTestAttempts::HEARTBEAT_KEY, [
            'at' => now()->toIso8601String(),
            'state' => 'finished',
            'providerRetries' => 2,
        ], now()->addDay());
        $this->insertAttempt($this->testId, ['status' => 'SUBMITTED']);
        $this->insertAttempt($this->testId, ['status' => 'SUBMITTED']);
        $this->insertAttempt($this->testId, ['status' => 'FAILED', 'gradingError' => 'review']);

        $this->withToken($this->adminToken())->getJson('/api/tests/grading-queue')
            ->assertOk()
            ->assertJsonPath('estimatedClear.seconds', 30)
            ->assertJsonPath('estimatedClear.measuredSecondsPerPaper', 30)
            ->assertJsonPath('estimatedClear.sampleCount', 2)
            ->assertJsonPath('estimatedClear.workers', 2)
            ->assertJsonFragment(['The last grading run retried provider failures.'])
            ->assertJsonFragment(['One or more submissions need administrator review.']);
    }

    public function test_retry_all_requeues_failed_and_blocked_papers_only(): void
    {
        $failed = $this->insertAttempt($this->testId, ['status' => 'FAILED', 'gradingError' => 'x', 'gradingAttempts' => 3]);
        $blocked = $this->insertAttempt($this->testId, ['status' => 'SUBMITTED', 'gradingError' => 'old error']);
        $waiting = $this->insertAttempt($this->testId, ['status' => 'SUBMITTED']);
        $processing = $this->insertAttempt($this->testId, ['status' => 'PROCESSING']);
        $completed = $this->insertAttempt($this->testId, ['status' => 'COMPLETED']);

        $this->withToken($this->adminToken())->postJson('/api/tests/grading-queue/retry')
            ->assertOk()
            ->assertJsonPath('requeued', 2);

        foreach ([$failed, $blocked] as $attemptId) {
            $attempt = DB::table('test_attempts')->where('id', $attemptId)->first();
            self::assertSame('SUBMITTED', $attempt->status);
            self::assertNull($attempt->grading_error);
            self::assertSame(0, (int) $attempt->grading_attempts);
        }

        self::assertSame('SUBMITTED', $this->statusOf($waiting));
        self::assertSame('PROCESSING', $this->statusOf($processing));
        self::assertSame('COMPLETED', $this->statusOf($completed));
        $this->assertDatabaseHas('audit_logs', ['event' => 'grading_queue_retry_all']);

        // Nothing left to retry the second time, and no extra audit row.
        $this->withToken($this->adminToken())->postJson('/api/tests/grading-queue/retry')
            ->assertOk()->assertJsonPath('requeued', 0);
        self::assertSame(1, DB::table('audit_logs')->where('event', 'grading_queue_retry_all')->count());
    }

    public function test_grading_queue_details_and_controls_require_admin_permission(): void
    {
        $this->withToken($this->adminToken('testing_manager'))
            ->getJson('/api/tests/grading-queue')->assertForbidden();
        $this->withToken($this->adminToken('teacher_assistant'))
            ->postJson('/api/tests/grading-queue/retry')->assertForbidden();
    }

    public function test_it_flags_a_grading_worker_that_is_not_running(): void
    {
        $token = $this->adminToken();
        $attemptId = $this->insertAttempt($this->testId, ['status' => 'SUBMITTED', 'submittedMinutesAgo' => 3]);

        // Work is waiting and the cron job has never reported in.
        $this->withToken($token)->getJson('/api/tests/grading-queue')
            ->assertOk()
            ->assertJsonPath('worker.stalled', true)
            ->assertJsonPath('worker.lastRunAt', null);

        // A real worker run leaves a heartbeat and clears the warning.
        $this->useChat(\Tests\Support\ScriptedChatClient::always($this->gradedAnswer()));
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);
        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        $this->insertAttempt($this->testId, ['status' => 'SUBMITTED', 'submittedMinutesAgo' => 2]);
        $this->withToken($token)->getJson('/api/tests/grading-queue')
            ->assertOk()
            ->assertJsonPath('worker.stalled', false)
            ->assertJsonPath('worker.state', 'finished')
            ->assertJsonPath('worker.lastRun.graded', 1);

        // The heartbeat goes quiet for longer than two cron intervals.
        \Illuminate\Support\Facades\Cache::put(
            \App\Console\Commands\GradePendingTestAttempts::HEARTBEAT_KEY,
            ['at' => now()->subMinutes(50)->toIso8601String(), 'state' => 'finished'],
            now()->addDay()
        );
        $this->withToken($token)->getJson('/api/tests/grading-queue')
            ->assertOk()
            ->assertJsonPath('worker.stalled', true);

        // An empty queue is never a problem, however old the heartbeat is.
        DB::table('test_attempts')->where('status', 'SUBMITTED')->delete();
        $this->withToken($token)->getJson('/api/tests/grading-queue')
            ->assertOk()
            ->assertJsonPath('worker.stalled', false);
    }

    public function test_students_and_anonymous_callers_are_refused(): void
    {
        $student = $this->createUser();

        $this->getJson('/api/tests/grading-queue')->assertUnauthorized();
        $this->withToken($this->token($student))->getJson('/api/tests/grading-queue')->assertForbidden();
        $this->withToken($this->token($student))->postJson('/api/tests/grading-queue/retry')->assertForbidden();
    }
}
