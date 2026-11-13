<?php

namespace Tests\Feature;

use App\Services\TestAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGradingFixtures;
use Tests\Support\ScriptedChatClient;
use Tests\TestCase;

class StaleAttemptRecoveryTest extends TestCase
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

    public function test_a_stuck_processing_paper_goes_back_into_the_queue(): void
    {
        $attemptId = $this->insertAttempt($this->testId, [
            'status' => 'PROCESSING',
            'updatedMinutesAgo' => 50,
            'gradingAttempts' => 1,
        ]);

        self::assertSame(1, app(TestAssessmentService::class)->recoverStaleAttempts());

        $attempt = DB::table('test_attempts')->where('id', $attemptId)->first();
        self::assertSame('SUBMITTED', $attempt->status);
        self::assertNull($attempt->grading_error);
        self::assertSame(1, (int) $attempt->grading_attempts, 'The attempt counter must survive recovery.');
    }

    public function test_provider_recovery_has_no_attempt_limit(): void
    {
        $attemptId = $this->insertAttempt($this->testId, [
            'status' => 'PROCESSING',
            'updatedMinutesAgo' => 50,
            'gradingAttempts' => 3,
        ]);

        self::assertSame(1, app(TestAssessmentService::class)->recoverStaleAttempts());

        $attempt = DB::table('test_attempts')->where('id', $attemptId)->first();
        self::assertSame('SUBMITTED', $attempt->status);
        self::assertNull($attempt->grading_error);
        self::assertSame(3, (int) $attempt->grading_attempts);
    }

    public function test_a_paper_still_being_graded_is_left_alone(): void
    {
        // 25 minutes is past the old 20-minute rule but inside the 45-minute window.
        $attemptId = $this->insertAttempt($this->testId, ['status' => 'PROCESSING', 'updatedMinutesAgo' => 25]);

        self::assertSame(0, app(TestAssessmentService::class)->recoverStaleAttempts());
        self::assertSame('PROCESSING', $this->statusOf($attemptId));
    }

    public function test_waiting_submissions_are_never_failed_however_long_they_wait(): void
    {
        $waiting = $this->insertAttempt($this->testId, [
            'status' => 'SUBMITTED',
            'submittedMinutesAgo' => 3 * 24 * 60,
            'updatedMinutesAgo' => 3 * 24 * 60,
        ]);

        self::assertSame(0, app(TestAssessmentService::class)->recoverStaleAttempts());

        $attempt = DB::table('test_attempts')->where('id', $waiting)->first();
        self::assertSame('SUBMITTED', $attempt->status);
        self::assertNull($attempt->grading_error);
    }

    public function test_completed_papers_are_untouched_and_failed_papers_are_requeued(): void
    {
        $completed = $this->insertAttempt($this->testId, ['status' => 'COMPLETED', 'updatedMinutesAgo' => 600]);
        $failed = $this->insertAttempt($this->testId, [
            'status' => 'FAILED',
            'updatedMinutesAgo' => 600,
            'gradingError' => 'Original failure reason.',
        ]);

        self::assertSame(1, app(TestAssessmentService::class)->recoverStaleAttempts());

        self::assertSame('COMPLETED', $this->statusOf($completed));
        self::assertSame('SUBMITTED', $this->statusOf($failed));
        self::assertNull(DB::table('test_attempts')->where('id', $failed)->value('grading_error'));
    }

    public function test_failed_papers_after_automatic_retry_remain_visible_for_admin_review(): void
    {
        $attemptId = $this->insertAttempt($this->testId, [
            'status' => 'FAILED',
            'gradingError' => 'Automated grading could not complete after an automatic retry. Administrator review is required.',
            'gradingAttempts' => 2,
        ]);

        self::assertSame(0, app(TestAssessmentService::class)->recoverStaleAttempts());
        self::assertSame('FAILED', $this->statusOf($attemptId));
        self::assertSame(
            'Automated grading could not complete after an automatic retry. Administrator review is required.',
            DB::table('test_attempts')->where('id', $attemptId)->value('grading_error')
        );
    }

    public function test_the_window_comes_from_config_and_cannot_be_set_below_ten_minutes(): void
    {
        $attemptId = $this->insertAttempt($this->testId, ['status' => 'PROCESSING', 'updatedMinutesAgo' => 25]);

        config(['services.grading.stale_minutes' => 20]);
        self::assertSame(1, app(TestAssessmentService::class)->recoverStaleAttempts());
        self::assertSame('SUBMITTED', $this->statusOf($attemptId));

        $recent = $this->insertAttempt($this->testId, ['status' => 'PROCESSING', 'updatedMinutesAgo' => 5]);
        self::assertSame(0, app(TestAssessmentService::class)->recoverStaleAttempts(1));
        self::assertSame('PROCESSING', $this->statusOf($recent));
    }

    public function test_the_grading_command_recovers_a_crashed_run_and_then_grades_the_paper(): void
    {
        $attemptId = $this->insertAttempt($this->testId, [
            'status' => 'PROCESSING',
            'updatedMinutesAgo' => 60,
            'gradingAttempts' => 1,
        ]);
        $this->useChat(ScriptedChatClient::always($this->gradedAnswer()));

        $this->artisan('ai-tests:grade-pending')
            ->expectsOutputToContain('Recovered 1 grading attempt(s) for retry.')
            ->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertSame(2, (int) DB::table('test_attempts')->where('id', $attemptId)->value('grading_attempts'));
    }
}
