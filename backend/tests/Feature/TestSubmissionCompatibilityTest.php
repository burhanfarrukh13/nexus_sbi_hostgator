<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestSubmissionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_submission_persists_answers_without_folder_permissions(): void
    {
        $student = $this->createUser('student@example.test', 'student');
        [$subjectId, $testId] = $this->createTest();
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $student,
            'created_at' => now(),
        ]);

        $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([
                    ['questionNumber' => 1, 'answerText' => '4'],
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED');

        $attempt = DB::table('test_attempts')
            ->where('testId', $testId)
            ->where('studentId', $student)
            ->first();
        self::assertNotNull($attempt);
        self::assertSame($subjectId, DB::table('tests')->where('id', $testId)->value('subject_id'));
        self::assertSame(
            '4',
            json_decode($attempt->submitted_answers, true, 512, JSON_THROW_ON_ERROR)[0]['answerText']
        );
    }

    public function test_server_draft_restores_and_submission_retries_are_idempotent(): void
    {
        $student = $this->createUser('draft-recovery@example.test', 'student');
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);
        $token = $this->token($student);
        $draftAnswers = json_encode([
            ['questionNumber' => 1, 'answerText' => 'Draft answer'],
        ], JSON_THROW_ON_ERROR);

        $this->withToken($token)->putJson("/api/tests/{$testId}/me/draft", [
            'answers' => $draftAnswers,
        ])->assertOk()->assertJsonPath('saved', true);
        $this->withToken($token)->getJson("/api/tests/{$testId}/me")
            ->assertOk()
            ->assertJsonPath('draftAnswers.0.answerText', 'Draft answer');

        $submissionId = (string) Str::uuid();
        $submitted = json_encode([
            ['questionNumber' => 1, 'answerText' => 'Final answer'],
        ], JSON_THROW_ON_ERROR);
        $first = $this->withToken($token)->postJson("/api/tests/{$testId}/me/submit", [
            'answers' => $submitted,
            'clientSubmissionId' => $submissionId,
        ])->assertCreated()->assertJsonPath('status', 'SUBMITTED');

        $retry = $this->withToken($token)->postJson("/api/tests/{$testId}/me/submit", [
            'answers' => json_encode([
                ['questionNumber' => 1, 'answerText' => 'Different retry payload'],
            ], JSON_THROW_ON_ERROR),
            'clientSubmissionId' => $submissionId,
        ])->assertCreated()
            ->assertJsonPath('attemptId', $first->json('attemptId'))
            ->assertJsonPath('status', 'SUBMITTED');

        self::assertSame(1, DB::table('test_attempts')->where('testId', $testId)->count());
        $attempt = DB::table('test_attempts')->where('id', $first->json('attemptId'))->first();
        self::assertSame($submissionId, $attempt->client_submission_id);
        self::assertNull($attempt->draft_answers);
        self::assertSame('Final answer', json_decode($attempt->submitted_answers, true)[0]['answerText']);
        $this->withToken($token)->putJson("/api/tests/{$testId}/me/draft", [
            'answers' => $draftAnswers,
        ])->assertOk()->assertJsonPath('saved', false);
    }

    public function test_pdf_complete_paper_is_queued_for_server_side_ingestion(): void
    {
        $student = $this->createUser('scanned-paper@example.test', 'student');
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);

        $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'files' => [UploadedFile::fake()->createWithContent('paper.pdf', "%PDF-1.4\nunreadable scan")],
            ])
            ->assertCreated()
            ->assertJsonPath('gradingPending', true)
            ->assertJsonPath('gradingQueued', true)
            ->assertJsonPath('status', 'SUBMITTED');

        $attempt = DB::table('test_attempts')->where('testId', $testId)->where('studentId', $student)->first();
        self::assertSame('SUBMITTED', $attempt->status);
        self::assertNull($attempt->grading_error);
    }

    public function test_image_answers_are_queued_and_processed_by_the_grading_command(): void
    {
        $student = $this->createUser('image-answer@example.test', 'student');
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);
        $this->configureAssessment([
            'deficiencyReport' => 'The answer is correct.',
            'questions' => [[
                'questionNumber' => 1,
                'deficiencyReport' => 'Correct.',
                'criteria' => [[
                    'criterionIndex' => 0,
                    'marksAwarded' => 2,
                    'feedback' => 'Correct answer.',
                    'evidence' => ['visible answer'],
                ]],
            ]],
        ]);
        config(['services.ai_chat.model' => 'gemma4:test']);

        $response = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'files' => [UploadedFile::fake()->image('question-1-page-1.jpg', 10, 10)],
            ])
            ->assertCreated()
            ->assertJsonPath('gradingPending', true)
            ->assertJsonPath('gradingQueued', true)
            ->assertJsonPath('status', 'SUBMITTED');

        $attemptId = $response->json('attemptId');
        self::assertSame(
            'SUBMITTED',
            DB::table('test_attempts')->where('id', $attemptId)->value('status')
        );
        Http::assertNothingSent();

        $exitCode = $this->artisan('ai-tests:grade-pending --limit=1')->run();
        self::assertSame(
            0,
            $exitCode,
            (string) DB::table('test_attempts')->where('id', $attemptId)->value('grading_error')
        );

        self::assertSame(
            'COMPLETED',
            DB::table('test_attempts')->where('id', $attemptId)->value('status')
        );
        $this->withToken($this->token($student))
            ->getJson("/api/tests/{$testId}/me")
            ->assertOk()
            ->assertJsonPath('attempt.gradingComplete', true)
            ->assertJsonPath('attempt.isResultReleased', true)
            ->assertJsonPath('attempt.marksObtained', 2);
        Http::assertSent(fn ($request): bool =>
            is_array($request['messages'][1]['content'] ?? null) &&
            collect($request['messages'][1]['content'])->contains(
                fn (array $part): bool => ($part['type'] ?? null) === 'image_url' &&
                    str_starts_with($part['image_url']['url'] ?? '', 'data:image/jpeg;base64,')
            )
        );
    }

    public function test_ai_marks_are_released_immediately_by_default(): void
    {
        // Grade during the request (the production default queues typed answers).
        config(['services.grading.queue_typed' => false]);
        $student = $this->createUser('graded-student@example.test', 'student');
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);
        $this->configureAssessment([
            'deficiencyReport' => 'Good work.',
            'questions' => [[
                'questionNumber' => 1,
                'deficiencyReport' => 'Correct answer.',
                'criteria' => [[
                    'criterionIndex' => 0,
                    'marksAwarded' => 2,
                    'feedback' => 'Correct.',
                    'evidence' => ['4'],
                ]],
            ]],
        ]);

        $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([
                    ['questionNumber' => 1, 'answerText' => '4'],
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingPending', false)
            ->assertJsonMissingPath('marksObtained')
            ->assertJsonMissingPath('aiAssessment');

        $attempt = DB::table('test_attempts')->where('testId', $testId)->where('studentId', $student)->first();
        self::assertSame('COMPLETED', $attempt->status);
        self::assertEquals(2, (float) $attempt->marksObtained);
        self::assertNotNull($attempt->ai_assessment);
        self::assertLessThanOrEqual(
            now()->addSeconds(5)->timestamp,
            \Illuminate\Support\Carbon::parse($attempt->result_available_at)->timestamp
        );

        $token = $this->token($student);
        $this->withToken($token)
            ->getJson('/api/tests/me/attempts')
            ->assertOk()
            ->assertJsonPath('0.attempt.status', 'COMPLETED')
            ->assertJsonPath('0.attempt.marksObtained', 2)
            ->assertJsonPath('0.attempt.isResultReleased', true);
        $this->withToken($token)
            ->getJson("/api/tests/{$testId}/me")
            ->assertOk()
            ->assertJsonPath('attempt.isResultReleased', true)
            ->assertJsonPath('attempt.aiAssessment.deficiencyReport', 'Good work.');
    }

    public function test_admin_can_change_marks_and_set_the_result_release_delay(): void
    {
        // Grade during the request (the production default queues typed answers).
        config(['services.grading.queue_typed' => false]);
        $student = $this->createUser('editable-student@example.test', 'student');
        $admin = $this->createUser('grading-admin@example.test', 'admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $admin,
            'granteeId' => $admin,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);
        $this->configureAssessment([
            'deficiencyReport' => 'Reviewed.',
            'questions' => [[
                'questionNumber' => 1,
                'deficiencyReport' => 'Reviewed.',
                'criteria' => [[
                    'criterionIndex' => 0,
                    'marksAwarded' => 1,
                    'feedback' => 'Partially correct.',
                    'evidence' => ['partly'],
                ]],
            ]],
        ]);
        $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([
                    ['questionNumber' => 1, 'answerText' => 'partly'],
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated();

        $attemptsResponse = $this->withToken($this->token($admin))
            ->getJson("/api/tests/{$testId}/attempts")
            ->assertOk()
            ->assertJsonPath('0.student.id', $student);
        self::assertEquals(1, (float) $attemptsResponse->json('0.marksObtained'));
        $updateResponse = $this->withToken($this->token($admin))
            ->putJson("/api/tests/{$testId}/marks/{$student}", [
                'marksObtained' => 1.5,
                'deficiencyNotes' => 'Administrator-adjusted mark.',
            ])
            ->assertOk();
        self::assertEquals(1.5, (float) $updateResponse->json('marksObtained'));

        self::assertEquals(1.5, (float) DB::table('test_attempts')
            ->where('testId', $testId)
            ->where('studentId', $student)
            ->value('marksObtained'));
        $this->withToken($this->token($admin))
            ->putJson('/api/tests/ai-grading-settings', [
                'deficiencyReportPrompt' => '',
                'resultReleaseDelayHours' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('resultReleaseDelayHours', 0);
    }

    public function test_invalid_model_marks_are_not_persisted(): void
    {
        // Grade during the request (the production default queues typed answers).
        config(['services.grading.queue_typed' => false]);
        $student = $this->createUser('invalid-grade-student@example.test', 'student');
        $admin = $this->createUser('retry-grading-admin@example.test', 'admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $admin,
            'granteeId' => $admin,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);
        config([
            'services.ai_chat.url' => 'https://ollama.example.test/v1/chat/completions',
            'services.ai_chat.key' => 'test-ollama-key',
            'services.ai_chat.model' => 'test-chat-model',
        ]);
        Http::fakeSequence('ollama.example.test/*')
            ->push(['choices' => [['message' => ['content' => json_encode([
                'deficiencyReport' => 'Invalid.',
                'questions' => [[
                    'questionNumber' => 1,
                    'deficiencyReport' => 'Invalid.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 3,
                        'feedback' => 'Invalid score.',
                        'evidence' => ['4'],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR)]]]], 200)
            ->push(['choices' => [['message' => ['content' => json_encode([
                'deficiencyReport' => 'Invalid again.',
                'questions' => [[
                    'questionNumber' => 1,
                    'deficiencyReport' => 'Invalid.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 3,
                        'feedback' => 'Invalid score.',
                        'evidence' => ['4'],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR)]]]], 200)
            ->push(['choices' => [['message' => ['content' => json_encode([
                'deficiencyReport' => 'Reviewed.',
                'questions' => [[
                    'questionNumber' => 1,
                    'deficiencyReport' => 'Correct.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 2,
                        'feedback' => 'Correct answer.',
                        'evidence' => ['4'],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR)]]]], 200);

        $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([
                    ['questionNumber' => 1, 'answerText' => '4'],
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingPending', true)
            ->assertJsonPath('gradingQueued', false)
            ->assertJsonPath('gradingMessage', 'Your answers are saved. A teacher will check them.')
            ->assertJsonMissingPath('gradingError');

        $attempt = DB::table('test_attempts')->where('testId', $testId)->where('studentId', $student)->first();
        self::assertNull($attempt->marksObtained);
        self::assertNull($attempt->ai_assessment);
        // Students only ever see a generic failure reason, never the model's raw output.
        self::assertStringContainsString('can be retried', (string) $attempt->grading_error);
        self::assertStringNotContainsString('rubric', (string) $attempt->grading_error);
        $this->withToken($this->token($student))
            ->getJson("/api/tests/{$testId}/me")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'SUBMITTED')
            ->assertJsonPath('attempt.gradingError', null);
        // A student resubmit only puts the paper back in the queue; it never grades in the request.
        $this->withToken($this->token($student))
            ->postJson("/api/tests/{$testId}/me/attempts/{$attempt->id}/resubmit")
            ->assertOk()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingQueued', true);
        $this->assertDatabaseHas('test_attempts', [
            'id' => $attempt->id,
            'status' => 'SUBMITTED',
        ]);

        // The admin retry button also queues the paper; the grading command then finishes it.
        $this->withToken($this->token($admin))
            ->postJson("/api/tests/{$testId}/attempts/{$attempt->id}/retry")
            ->assertOk()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingQueued', true);
        // A bad marking result is a teacher-attention problem, not an outage retry.
        $this->artisan('ai-tests:grade-pending')->assertExitCode(1);
        self::assertSame('FAILED', DB::table('test_attempts')->where('id', $attempt->id)->value('status'));
        $this->withToken($this->token($admin))
            ->postJson("/api/tests/{$testId}/attempts/{$attempt->id}/retry")
            ->assertOk()
            ->assertJsonPath('status', 'SUBMITTED');
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        $this->assertDatabaseHas('test_attempts', [
            'id' => $attempt->id,
            'status' => 'COMPLETED',
            'grading_error' => null,
        ]);
    }

    public function test_student_leaderboard_is_subject_scoped_anonymous_and_uses_released_results_only(): void
    {
        $student = $this->createUser('leaderboard-student@example.test', 'student');
        $topStudent = $this->createUser('leaderboard-top@example.test', 'student');
        $unreleasedStudent = $this->createUser('leaderboard-unreleased@example.test', 'student');
        $otherSubjectStudent = $this->createUser('leaderboard-other-subject@example.test', 'student');
        [$subjectId, $testId] = $this->createTest();
        $this->grantSubject($student, $subjectId);
        $this->grantSubject($topStudent, $subjectId);
        $this->grantSubject($unreleasedStudent, $subjectId);

        $otherSubjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $otherSubjectId,
            'code' => 'SCI-1',
            'name' => 'Science',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherTestId = (string) Str::uuid();
        DB::table('tests')->insert([
            'id' => $otherTestId,
            'title' => 'Science test',
            'totalMarks' => '2.00',
            'questions' => '[]',
            'subject_id' => $otherSubjectId,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        $this->grantSubject($otherSubjectStudent, $otherSubjectId);

        $this->insertLeaderboardAttempt($topStudent, $testId, 2, now()->subMinute());
        $this->insertLeaderboardAttempt($student, $testId, 1, now()->subMinute());
        $this->insertLeaderboardAttempt($unreleasedStudent, $testId, 2, now()->addHour());
        $this->insertLeaderboardAttempt($otherSubjectStudent, $otherTestId, 2, now()->subMinute());

        $response = $this->withToken($this->token($student))
            ->getJson('/api/leaderboard')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.rank', 1)
            ->assertJsonPath('0.displayIdentifier', 'Learner 1')
            ->assertJsonPath('1.rank', 2)
            ->assertJsonPath('1.displayIdentifier', 'You')
            ->assertJsonPath('1.isCurrentUser', true)
            ->assertJsonMissingPath('0.studentId');

        $response->assertDontSee('leaderboard-top@example.test');
        $response->assertDontSee('leaderboard-unreleased@example.test');
        $response->assertDontSee('leaderboard-other-subject@example.test');
    }

    public function test_leaderboard_requires_a_student_account(): void
    {
        $admin = $this->createUser('leaderboard-admin@example.test', 'admin');

        $this->withToken($this->token($admin))
            ->getJson('/api/leaderboard')
            ->assertForbidden();
    }

    private function insertLeaderboardAttempt(
        string $studentId,
        string $testId,
        float $marks,
        \Illuminate\Support\Carbon $resultAvailableAt,
    ): void {
        DB::table('test_attempts')->insert([
            'id' => (string) Str::uuid(),
            'testId' => $testId,
            'studentId' => $studentId,
            'marksObtained' => $marks,
            'status' => 'COMPLETED',
            'submitted_at' => now()->subHours(2),
            'result_available_at' => $resultAvailableAt,
            'createdAt' => now()->subHours(2),
            'updatedAt' => now()->subHours(2),
        ]);
    }

    private function grantSubject(string $studentId, string $subjectId): void
    {
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $studentId,
            'created_at' => now(),
        ]);
    }

    private function configureAssessment(array $assessment): void
    {
        config([
            'services.ai_chat.url' => 'https://ollama.example.test/v1/chat/completions',
            'services.ai_chat.key' => 'test-ollama-key',
            'services.ai_chat.model' => 'test-chat-model',
        ]);
        Http::fake([
            'ollama.example.test/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode($assessment, JSON_THROW_ON_ERROR)],
                ]],
            ]),
        ]);
    }

    private function createTest(): array
    {
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'MATH-1',
            'name' => 'Mathematics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $testId = (string) Str::uuid();
        DB::table('tests')->insert([
            'id' => $testId,
            'title' => 'Quick arithmetic',
            'description' => null,
            'testDate' => null,
            'deadline_at' => now()->addDay(),
            'totalMarks' => '2.00',
            'questions' => json_encode([[
                'questionNumber' => 1,
                'questionText' => 'What is 2 + 2?',
                'idealAnswer' => '4',
                'marks' => 2,
            ]], JSON_THROW_ON_ERROR),
            'createdById' => null,
            'subject_id' => $subjectId,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return [$subjectId, $testId];
    }

    private function createUser(string $email, string $role): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('current-account-password'),
            'role' => $role,
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $permissions = DB::table('user_role_assignments')
            ->where('userId', $userId)
            ->orderBy('permission')
            ->pluck('permission')
            ->all();

        return app(JwtTokenService::class)->issue([
            'sub' => $userId,
            'email' => $user->email,
            'role' => $user->role,
            'adminPermissions' => $permissions,
            'mustResetPassword' => false,
            'sessionVersion' => (int) $user->refreshCounter,
            'jti' => (string) Str::uuid(),
        ], (string) config('auth.jwt.access_secret'), '60m');
    }
}
