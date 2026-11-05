<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGradingFixtures;
use Tests\Support\ScriptedChatClient;
use Tests\TestCase;

/**
 * Submit through the real API, let the queue worker grade it, then read the result back
 * as the student. The AI provider is a scripted CloudChatClient, so nothing goes online.
 */
class SubmissionToCompletionFlowTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryPrivateStorage();
        $this->useRenderer(null);
        Http::fake();
    }

    public function test_a_typed_answer_is_queued_graded_by_the_worker_and_shown_to_the_student(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        $chat = ScriptedChatClient::always($this->gradedAnswer(2));
        $this->useChat($chat);

        $response = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([['questionNumber' => 1, 'answerText' => '4']], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingPending', true)
            ->assertJsonPath('gradingQueued', true)
            ->assertJsonMissingPath('marksObtained');
        $attemptId = $response->json('attemptId');

        // The web request never talks to the AI provider.
        self::assertSame([], $chat->calls);
        self::assertStringContainsString('your result will appear in My Tests', (string) $response->json('gradingMessage'));
        self::assertSame('SUBMITTED', $this->statusOf($attemptId));

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertCount(1, $chat->calls);
        $attempt = DB::table('test_attempts')->where('id', $attemptId)->first();
        self::assertEquals(2, (float) $attempt->marksObtained);
        self::assertSame('Good work overall.', $attempt->deficiencyNotes);
        self::assertNull($attempt->grading_error);

        $this->withToken($this->token($student))
            ->getJson("/api/tests/{$testId}/me")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'COMPLETED')
            ->assertJsonPath('attempt.marksObtained', 2)
            ->assertJsonPath('attempt.isResultReleased', true)
            ->assertJsonPath('attempt.aiAssessment.deficiencyReport', 'Good work overall.');
        Http::assertNothingSent();
    }

    public function test_a_photo_answer_goes_through_the_same_queue(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        $chat = ScriptedChatClient::always($this->gradedAnswer(1.5, 'visible answer'));
        $this->useChat($chat);

        $attemptId = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'files' => [UploadedFile::fake()->image('question-1-page-1.jpg', 20, 20)],
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingQueued', true)
            ->json('attemptId');
        self::assertSame([], $chat->calls);

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertEquals(1.5, (float) DB::table('test_attempts')->where('id', $attemptId)->value('marksObtained'));
        $imageParts = collect($chat->calls[0][1]['content'])->where('type', 'image_url');
        self::assertCount(1, $imageParts, 'The photo must reach the model as an image part.');
    }

    public function test_a_pdf_paper_goes_through_the_same_queue(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        $this->useRenderer($this->makeJpeg());
        $chat = new ScriptedChatClient($this->transcribingGrader());
        $this->useChat($chat);

        $attemptId = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'files' => [UploadedFile::fake()->createWithContent('paper.pdf', $this->makePdf('Question 1 Answer 4'))],
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingQueued', true)
            ->json('attemptId');

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertSame(1, $chat->transcriptionCalls());
        self::assertSame(1, $chat->gradingCalls());
    }

    public function test_provider_failures_are_delayed_and_admin_retries_still_use_the_queue(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        $admin = $this->createUser('admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $admin,
            'granteeId' => $admin,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);

        // A bad key: the provider rejects every request, for every background attempt.
        $failing = new ScriptedChatClient(static function (): never {
            throw new \RuntimeException('Ollama chat provider rejected the request.');
        });
        $this->useChat($failing);
        $attemptId = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([['questionNumber' => 1, 'answerText' => '4']], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->json('attemptId');

        // A provider outage stays queued with a delay instead of exposing FAILED to the student.
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);
        $attempt = DB::table('test_attempts')->where('id', $attemptId)->first();
        self::assertSame('SUBMITTED', $attempt->status);
        self::assertSame(1, (int) $attempt->grading_attempts);
        self::assertNull($attempt->grading_error);
        self::assertTrue(\Illuminate\Support\Carbon::parse($attempt->updatedAt)->greaterThan(now()));

        // When the provider is available again, the next eligible worker run completes it.
        $fixed = ScriptedChatClient::always($this->gradedAnswer(2));
        $this->useChat($fixed);
        $this->travel(5)->minutes();
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);
        self::assertSame('COMPLETED', $this->statusOf($attemptId));

        // The admin Retry button queues a failed paper; the next worker run grades it.
        $other = $this->insertAttempt($testId, ['status' => 'FAILED', 'gradingError' => 'Provider was down.', 'gradingAttempts' => 3]);
        $this->withToken($this->token($admin))
            ->postJson("/api/tests/{$testId}/attempts/{$other}/retry")
            ->assertOk()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingQueued', true);
        self::assertSame('SUBMITTED', $this->statusOf($other));
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);
        self::assertSame('COMPLETED', $this->statusOf($other));
    }
}
