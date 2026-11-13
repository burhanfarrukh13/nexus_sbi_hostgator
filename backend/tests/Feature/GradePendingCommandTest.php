<?php

namespace Tests\Feature;

use App\Services\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGradingFixtures;
use Tests\Support\ScriptedChatClient;
use Tests\TestCase;

class GradePendingCommandTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryPrivateStorage();
        $this->useRenderer(null);
    }

    public function test_one_run_drains_the_whole_queue(): void
    {
        [, $testId] = $this->createTest();
        $attempts = [
            $this->insertAttempt($testId, ['submittedMinutesAgo' => 30]),
            $this->insertAttempt($testId, ['submittedMinutesAgo' => 20]),
            $this->insertAttempt($testId, ['submittedMinutesAgo' => 10]),
            $this->insertAttempt($testId, ['submittedMinutesAgo' => 5]),
            $this->insertAttempt($testId, ['submittedMinutesAgo' => 2]),
            $this->insertAttempt($testId, ['submittedMinutesAgo' => 1]),
        ];
        $chat = ScriptedChatClient::always($this->gradedAnswer());
        $this->useChat($chat);

        $this->artisan('ai-tests:grade-pending')
            ->expectsOutputToContain('Graded 6, failed 0, requeued 0')
            ->assertExitCode(0);

        foreach ($attempts as $attemptId) {
            self::assertSame('COMPLETED', $this->statusOf($attemptId));
            self::assertEquals(2, (float) DB::table('test_attempts')->where('id', $attemptId)->value('marksObtained'));
            self::assertSame(1, (int) DB::table('test_attempts')->where('id', $attemptId)->value('grading_attempts'));
        }
        self::assertCount(6, $chat->calls);
    }

    public function test_the_limit_option_stops_the_run_early(): void
    {
        [, $testId] = $this->createTest();
        $first = $this->insertAttempt($testId, ['submittedMinutesAgo' => 30]);
        $second = $this->insertAttempt($testId, ['submittedMinutesAgo' => 20]);
        $third = $this->insertAttempt($testId, ['submittedMinutesAgo' => 10]);
        $this->useChat(ScriptedChatClient::always($this->gradedAnswer()));

        $this->artisan('ai-tests:grade-pending --limit=2')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($first));
        self::assertSame('COMPLETED', $this->statusOf($second));
        self::assertSame('SUBMITTED', $this->statusOf($third));
    }

    public function test_retries_go_before_untried_submissions_and_then_the_oldest_first(): void
    {
        [, $testId] = $this->createTest();
        $retry = $this->insertAttempt($testId, ['submittedMinutesAgo' => 60, 'gradingAttempts' => 1]);
        $newer = $this->insertAttempt($testId, ['submittedMinutesAgo' => 5]);
        $older = $this->insertAttempt($testId, ['submittedMinutesAgo' => 40]);
        $this->useChat(ScriptedChatClient::always($this->gradedAnswer()));

        $this->artisan('ai-tests:grade-pending --limit=2')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($retry));
        self::assertSame('COMPLETED', $this->statusOf($older));
        self::assertSame('SUBMITTED', $this->statusOf($newer));
    }

    public function test_existing_failed_attempts_are_automatically_rechecked(): void
    {
        [, $testId] = $this->createTest();
        $processing = $this->insertAttempt($testId, ['status' => 'PROCESSING']);
        $failed = $this->insertAttempt($testId, ['status' => 'FAILED', 'gradingError' => 'Needs a manual retry.']);
        $completed = $this->insertAttempt($testId, ['status' => 'COMPLETED']);
        $chat = ScriptedChatClient::always($this->gradedAnswer());
        $this->useChat($chat);

        $this->artisan('ai-tests:grade-pending')
            ->expectsOutputToContain('Recovered 1 grading attempt(s) for retry.')
            ->expectsOutputToContain('Graded 1, failed 0, requeued 0')
            ->assertExitCode(0);

        self::assertSame('PROCESSING', $this->statusOf($processing));
        self::assertSame('COMPLETED', $this->statusOf($failed));
        self::assertSame('COMPLETED', $this->statusOf($completed));
        self::assertCount(1, $chat->calls);
    }

    public function test_a_scanned_pdf_is_rendered_transcribed_and_graded(): void
    {
        [, $testId] = $this->createTest();
        $attemptId = $this->insertAttempt($testId, ['answers' => []]);
        // No text layer: the grader can only work from the rendered pages.
        $this->attachFile($attemptId, 'paper.pdf', 'application/pdf', $this->makePdf(''));
        config(['services.ai_chat.model' => 'gemma4:test']);
        $this->useRenderer($this->makeJpeg());
        $chat = new ScriptedChatClient($this->transcribingGrader());
        $this->useChat($chat);

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertEquals(2, (float) DB::table('test_attempts')->where('id', $attemptId)->value('marksObtained'));
        self::assertSame(1, $chat->transcriptionCalls());
        self::assertSame(1, $chat->gradingCalls());
        $assessment = json_decode((string) DB::table('test_attempts')->where('id', $attemptId)->value('ai_assessment'), true);
        self::assertSame([1], $assessment['questions'][0]['studentPageNumbers']);
    }

    public function test_a_pdf_with_a_text_layer_is_graded_when_the_server_cannot_render_pages(): void
    {
        [, $testId] = $this->createTest();
        $attemptId = $this->insertAttempt($testId, ['answers' => []]);
        $this->attachFile($attemptId, 'paper.pdf', 'application/pdf', $this->makePdf('Question 1 Answer 4'));
        $this->useRenderer(null);
        $chat = ScriptedChatClient::always($this->gradedAnswer());
        $this->useChat($chat);

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertSame(0, array_sum(array_map(
            static fn (array $messages): int => ScriptedChatClient::isTranscription($messages) ? 1 : 0,
            $chat->calls
        )));
    }

    public function test_an_unreadable_pdf_fails_cleanly_and_does_not_block_the_queue(): void
    {
        [, $testId] = $this->createTest();
        $scanned = $this->insertAttempt($testId, ['answers' => [], 'submittedMinutesAgo' => 30]);
        $this->attachFile($scanned, 'paper.pdf', 'application/pdf', $this->makePdf(''));
        $typed = $this->insertAttempt($testId, ['submittedMinutesAgo' => 10]);
        $this->useRenderer(null);
        $this->useChat(ScriptedChatClient::always($this->gradedAnswer()));

        // Even a non-provider failure is automatically requeued; other submissions still proceed.
        $this->artisan('ai-tests:grade-pending')
            ->expectsOutputToContain('Graded 1, failed 0, requeued 1')
            ->assertExitCode(0);

        self::assertSame('SUBMITTED', $this->statusOf($scanned));
        self::assertNull(DB::table('test_attempts')->where('id', $scanned)->value('grading_error'));
        self::assertTrue(
            \Illuminate\Support\Carbon::parse(DB::table('test_attempts')->where('id', $scanned)->value('updatedAt'))
                ->greaterThan(now())
        );
        self::assertSame('COMPLETED', $this->statusOf($typed));
    }

    public function test_a_provider_outage_requeues_a_paper_with_a_delay(): void
    {
        [, $testId] = $this->createTest();
        $attempts = [];
        foreach ([40, 30, 20, 10] as $minutes) {
            $attempts[] = $this->insertAttempt($testId, ['submittedMinutesAgo' => $minutes]);
        }
        $chat = new ScriptedChatClient(static function (): never {
            throw new \RuntimeException('Ollama chat provider is unavailable.');
        });
        $this->useChat($chat);
        self::assertTrue(app(\App\Services\TestAssessmentService::class)->isRetryableFailure('Ollama chat provider is unavailable.'));

        $this->artisan('ai-tests:grade-pending')
            ->expectsOutputToContain('stopping this run')
            ->assertExitCode(1);

        foreach (array_slice($attempts, 0, 3) as $attemptId) {
            self::assertSame('SUBMITTED', $this->statusOf($attemptId));
            self::assertSame(1, (int) DB::table('test_attempts')->where('id', $attemptId)->value('grading_attempts'));
            self::assertNull(DB::table('test_attempts')->where('id', $attemptId)->value('grading_error'));
            self::assertTrue(
                \Illuminate\Support\Carbon::parse(DB::table('test_attempts')->where('id', $attemptId)->value('updatedAt'))
                    ->greaterThan(now())
            );
        }
        // The fourth paper was never touched; the provider failure limit stopped the run.
        self::assertSame('SUBMITTED', $this->statusOf($attempts[3]));
        self::assertSame(0, (int) DB::table('test_attempts')->where('id', $attempts[3])->value('grading_attempts'));
    }

    public function test_provider_failures_are_requeued_without_an_attempt_limit(): void
    {
        [, $testId] = $this->createTest();
        $attemptId = $this->insertAttempt($testId, ['gradingAttempts' => 2]);
        $this->useChat(new ScriptedChatClient(static function (): never {
            throw new \RuntimeException('Ollama chat provider is unavailable.');
        }));

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('SUBMITTED', $this->statusOf($attemptId));
        self::assertSame(3, (int) DB::table('test_attempts')->where('id', $attemptId)->value('grading_attempts'));
        self::assertNull(DB::table('test_attempts')->where('id', $attemptId)->value('grading_error'));
        self::assertTrue(
            \Illuminate\Support\Carbon::parse(DB::table('test_attempts')->where('id', $attemptId)->value('updatedAt'))
                ->greaterThan(now())
        );
    }

    public function test_non_provider_failures_get_one_automatic_retry_then_remain_for_review(): void
    {
        [, $testId] = $this->createTest();
        $attemptId = $this->insertAttempt($testId, [
            'status' => 'FAILED',
            'gradingError' => 'The marking scheme is invalid.',
            'gradingAttempts' => 2,
        ]);

        self::assertFalse(
            app(\App\Services\TestAssessmentService::class)
                ->requeueAfterBackgroundFailure($attemptId, 'The marking scheme is invalid.')
        );
        self::assertSame('FAILED', $this->statusOf($attemptId));
        self::assertSame(
            'Automated grading could not complete after an automatic retry. Administrator review is required.',
            DB::table('test_attempts')->where('id', $attemptId)->value('grading_error')
        );
    }

    public function test_the_run_exits_quietly_when_every_worker_slot_is_busy(): void
    {
        [, $testId] = $this->createTest();
        $attemptId = $this->insertAttempt($testId);
        $chat = ScriptedChatClient::always($this->gradedAnswer());
        $this->useChat($chat);
        $storage = app(PrivateFileStorage::class);
        $first = $storage->acquireWorkerLock(2);
        $second = $storage->acquireWorkerLock(2);
        self::assertNotNull($first);
        self::assertNotNull($second);

        try {
            $this->artisan('ai-tests:grade-pending')
                ->expectsOutputToContain('already running')
                ->assertExitCode(0);
        } finally {
            $storage->releaseWorkerLock($first);
            $storage->releaseWorkerLock($second);
        }

        self::assertSame('SUBMITTED', $this->statusOf($attemptId));
        self::assertSame([], $chat->calls);
    }

    public function test_the_run_validates_its_options(): void
    {
        $this->artisan('ai-tests:grade-pending --limit=-1')->assertExitCode(2);
        $this->artisan('ai-tests:grade-pending --max-seconds=5')->assertExitCode(2);
    }
}
