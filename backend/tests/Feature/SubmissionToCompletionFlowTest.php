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

        $gradingInstructions = (string) $chat->calls[0][0]['content'];
        self::assertStringContainsString('using "you" and "your"', $gradingInstructions);
        self::assertStringContainsString('Never refer to the student or learner in the third person', $gradingInstructions);
        self::assertStringContainsString('regardless of reportGuidance', $gradingInstructions);
        self::assertStringContainsString('"or", "either", "any one", and "(any one)" indicate acceptable alternatives', $gradingInstructions);
        self::assertStringContainsString('"3/5" incomplete just because "60%" is not also written', $gradingInstructions);
        self::assertStringContainsString('award proportional partial credit', $gradingInstructions);
        self::assertStringContainsString('Do not deduct for harmless spelling errors', $gradingInstructions);
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

    public function test_incomplete_assessment_is_retried_with_completion_guidance_and_output_budget(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        config(['services.ai_chat.grading_max_tokens' => 7000]);
        $chat = new ScriptedChatClient(function (array $messages, int $call): array {
            return $call === 0
                ? ['deficiencyReport' => '', 'questions' => []]
                : $this->gradedAnswer(2);
        });
        $this->useChat($chat);

        $response = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([['questionNumber' => 1, 'answerText' => '4']], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated();

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf((string) $response->json('attemptId')));
        self::assertCount(2, $chat->calls);
        self::assertSame([7000, 7000], $chat->maxTokenRequests);
        self::assertStringContainsString(
            'previous response was rejected as incomplete or invalid',
            $chat->calls[1][1]['content'][0]['text']
        );
        self::assertStringContainsString(
            'exactly 1 question entries',
            $chat->calls[1][1]['content'][0]['text']
        );
    }

    public function test_grading_prompt_preserves_alternative_conditions_and_partial_credit(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        $questions = [
            [
                'questionNumber' => 1,
                'questionText' => 'Give the probability.',
                'idealAnswer' => '3/5 or 60%',
                'marks' => 1,
                'markingScheme' => [['criterion' => '3/5 or 60%', 'marks' => 1]],
            ],
            [
                'questionNumber' => 2,
                'questionText' => 'Give one function of the State Bank.',
                'idealAnswer' => 'Issuing currency, conducting monetary policy, or regulating banks (any one)',
                'marks' => 1,
                'markingScheme' => [['criterion' => 'Issuing currency, conducting monetary policy, or regulating banks (any one)', 'marks' => 1]],
            ],
            [
                'questionNumber' => 3,
                'questionText' => 'Give four required parts.',
                'idealAnswer' => 'A, B, C, and D',
                'marks' => 1,
                'markingScheme' => [['criterion' => 'Four required parts: A, B, C, and D', 'marks' => 1]],
            ],
            [
                'questionNumber' => 4,
                'questionText' => 'Give an equivalent percentage.',
                'idealAnswer' => '1/2',
                'marks' => 1,
                'markingScheme' => [['criterion' => '1/2', 'marks' => 1]],
            ],
            [
                'questionNumber' => 5,
                'questionText' => 'State the function of the heart.',
                'idealAnswer' => 'The heart pumps blood around the body.',
                'marks' => 1,
                'markingScheme' => [['criterion' => 'Pumps blood around the body', 'marks' => 1]],
            ],
        ];
        DB::table('tests')->where('id', $testId)->update([
            'questions' => json_encode($questions, JSON_THROW_ON_ERROR),
            'totalMarks' => '5.00',
        ]);
        $chat = ScriptedChatClient::always([
            'deficiencyReport' => 'You gave both accepted alternatives correctly and completed half of the final criterion.',
            'questions' => [
                [
                    'questionNumber' => 1,
                    'deficiencyReport' => 'You correctly gave 3/5.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 1,
                        'feedback' => 'You gave one accepted form.',
                        'evidence' => ['3/5'],
                    ]],
                ],
                [
                    'questionNumber' => 2,
                    'deficiencyReport' => 'You correctly identified a bank-regulation function.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 1,
                        'feedback' => 'You named one accepted function.',
                        'evidence' => ['monitoring banks'],
                    ]],
                ],
                [
                    'questionNumber' => 3,
                    'deficiencyReport' => 'You supplied two of the four required parts.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 0.5,
                        'feedback' => 'You supplied half of the required parts.',
                        'evidence' => ['A and B'],
                    ]],
                ],
                [
                    'questionNumber' => 4,
                    'deficiencyReport' => 'You gave 50%, an equivalent form of 1/2.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 1,
                        'feedback' => 'You gave an equivalent answer.',
                        'evidence' => ['50%'],
                    ]],
                ],
                [
                    'questionNumber' => 5,
                    'deficiencyReport' => 'You accurately paraphrased the heart’s function.',
                    'criteria' => [[
                        'criterionIndex' => 0,
                        'marksAwarded' => 1,
                        'feedback' => 'You correctly described the heart circulating blood.',
                        'evidence' => ['circulates blood throughout the body'],
                    ]],
                ],
            ],
        ]);
        $this->useChat($chat);

        $attemptId = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'answers' => json_encode([
                    ['questionNumber' => 1, 'answerText' => '3/5'],
                    ['questionNumber' => 2, 'answerText' => 'make laws and regulations and monitoring banks'],
                    ['questionNumber' => 3, 'answerText' => 'A and B'],
                    ['questionNumber' => 4, 'answerText' => '50%'],
                    ['questionNumber' => 5, 'answerText' => 'The heart circulates blood throughout the body.'],
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertCreated()
            ->json('attemptId');
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertEquals(4.5, (float) DB::table('test_attempts')->where('id', $attemptId)->value('marksObtained'));
        $gradingInput = json_decode((string) $chat->calls[0][1]['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3/5 or 60%', $gradingInput['questions'][0]['criteria'][0]['criterion']);
        self::assertSame('Issuing currency, conducting monetary policy, or regulating banks (any one)', $gradingInput['questions'][1]['criteria'][0]['criterion']);
        $gradedQuestions = json_decode(
            (string) DB::table('test_attempts')->where('id', $attemptId)->value('ai_assessment'),
            true,
            512,
            JSON_THROW_ON_ERROR
        )['questions'];
        self::assertEquals(0.5, $gradedQuestions[2]['criterionResults'][0]['marksAwarded']);
        self::assertSame('1/2', $gradingInput['questions'][3]['criteria'][0]['criterion']);
        self::assertEquals(1, $gradedQuestions[3]['criterionResults'][0]['marksAwarded']);
        self::assertSame('Pumps blood around the body', $gradingInput['questions'][4]['criteria'][0]['criterion']);
        self::assertEquals(1, $gradedQuestions[4]['criterionResults'][0]['marksAwarded']);
        self::assertStringContainsString('any one sufficient alternative', $chat->calls[0][0]['content']);
        self::assertStringContainsString('accept 50%, 0.5', $chat->calls[0][0]['content']);
        self::assertStringContainsString('proportional partial credit', $chat->calls[0][0]['content']);
        self::assertStringContainsString('Accept synonyms and clear paraphrases', $chat->calls[0][0]['content']);
        self::assertStringContainsString('evaluate meaning in context, not word-for-word overlap', $chat->calls[0][0]['content']);
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
        $originalPdf = $this->makePdf('Question 1 Answer 4', 11);
        $uploads = [
            UploadedFile::fake()->createWithContent('paper-source-paper.pdf', $originalPdf),
        ];
        for ($page = 1; $page <= 11; $page++) {
            $uploads[] = UploadedFile::fake()->image("paper-page-{$page}.jpg", 20, 20);
        }

        $attemptId = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", [
                'files' => $uploads,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingQueued', true)
            ->json('attemptId');

        $storedFiles = DB::table('test_attempt_files')
            ->where('attempt_id', $attemptId)
            ->orderBy('original_name')
            ->get();
        self::assertCount(12, $storedFiles);
        $pageNames = $storedFiles
            ->pluck('original_name')
            ->filter(static fn (string $name): bool => str_starts_with($name, 'paper-page-'))
            ->all();
        sort($pageNames, SORT_NATURAL);
        self::assertSame(
            array_map(static fn (int $page): string => "paper-page-{$page}.jpg", range(1, 11)),
            $pageNames
        );
        self::assertSame(
            1,
            $storedFiles->where('original_name', 'paper-source-paper.pdf')->count()
        );
        self::assertSame(
            strlen($originalPdf),
            (int) $storedFiles->firstWhere('original_name', 'paper-source-paper.pdf')->size_bytes
        );
        $admin = $this->createUser('admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $admin,
            'granteeId' => $admin,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);
        foreach ($storedFiles->filter(
            static fn (object $file): bool => str_starts_with($file->original_name, 'paper-page-')
        ) as $pageFile) {
            $this->withToken($this->token($admin))
                ->getJson("/api/tests/{$testId}/attempts/files/{$pageFile->id}/pages")
                ->assertOk()
                ->assertJsonPath('pageCount', 1);
            $this->withToken($this->token($admin))
                ->get("/api/tests/{$testId}/attempts/files/{$pageFile->id}/pages/1")
                ->assertOk()
                ->assertHeader('Content-Type', 'image/jpeg');
        }

        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        self::assertSame(11, $chat->transcriptionCalls());
        self::assertSame(1, $chat->gradingCalls());
        $assessment = json_decode(
            (string) DB::table('test_attempts')->where('id', $attemptId)->value('ai_assessment'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(range(1, 11), $assessment['questions'][0]['studentPageNumbers']);
        $sourceId = $storedFiles->firstWhere('original_name', 'paper-source-paper.pdf')->id;
        $this->withToken($this->token($admin))
            ->get("/api/tests/{$testId}/attempts/files/{$sourceId}")
            ->assertOk()
            ->assertDownload('paper-source-paper.pdf');
    }

    public function test_complete_paper_pages_are_not_assigned_to_spurious_questions(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $questions = [];
        for ($number = 1; $number <= 3; $number++) {
            $questions[] = [
                'questionNumber' => $number,
                'questionText' => "Question {$number}",
                'idealAnswer' => "Answer {$number}",
                'marks' => 2,
            ];
        }
        DB::table('tests')->where('id', $testId)->update([
            'questions' => json_encode($questions, JSON_THROW_ON_ERROR),
            'totalMarks' => '6.00',
        ]);
        $student = $this->createUser();
        $this->grantSubject($student, $subjectId);
        $this->useRenderer($this->makeJpeg());
        $chat = new ScriptedChatClient(function (array $messages): array {
            if (!ScriptedChatClient::isTranscription($messages)) {
                return [
                    'deficiencyReport' => 'Feedback.',
                    'questions' => array_map(static fn (int $number): array => [
                        'questionNumber' => $number,
                        'deficiencyReport' => 'Feedback.',
                        'criteria' => [[
                            'criterionIndex' => 0,
                            'marksAwarded' => 1,
                            'feedback' => 'Criterion feedback.',
                            'evidence' => [],
                        ]],
                    ], [1, 2, 3]),
                ];
            }

            $input = json_decode($messages[1]['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            $page = (int) $input['pageNumber'];
            $activeQuestion = $page <= 3 ? 1 : ($page <= 6 ? 2 : ($page <= 9 ? 3 : 4));
            $heading = in_array($page, [1, 4, 7, 10], true) ? "Question {$activeQuestion}" : '';

            return [
                'activeQuestionNumber' => $activeQuestion,
                'activeQuestionHeading' => $heading,
                'transcriptions' => array_map(static fn (int $number): array => [
                    'questionNumber' => $number,
                    'answerText' => "Question {$number} text from page {$page}.",
                    'uncertainty' => '',
                ], [1, 2, 3]),
            ];
        });
        $this->useChat($chat);
        $uploads = [
            UploadedFile::fake()->createWithContent('paper-source-complete.pdf', $this->makePdf('', 11)),
        ];
        for ($page = 1; $page <= 11; $page++) {
            $uploads[] = UploadedFile::fake()->image("paper-page-{$page}.jpg", 20, 20);
        }

        $attemptId = $this->withToken($this->token($student))
            ->post("/api/tests/{$testId}/me/submit", ['files' => $uploads])
            ->assertCreated()
            ->json('attemptId');
        $this->artisan('ai-tests:grade-pending')->assertExitCode(0);

        self::assertSame('COMPLETED', $this->statusOf($attemptId));
        $assessment = json_decode(
            (string) DB::table('test_attempts')->where('id', $attemptId)->value('ai_assessment'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame([1, 2, 3], $assessment['questions'][0]['studentPageNumbers']);
        self::assertSame([4, 5, 6], $assessment['questions'][1]['studentPageNumbers']);
        self::assertSame([7, 8, 9], $assessment['questions'][2]['studentPageNumbers']);

        $gradingCall = array_values(array_filter(
            $chat->calls,
            static fn (array $messages): bool => !ScriptedChatClient::isTranscription($messages)
        ))[0];
        $gradingInput = json_decode($gradingCall[1]['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Question 1 text from page 3', $gradingInput['questions'][0]['studentAnswer']);
        self::assertStringNotContainsString('Question 2 text', $gradingInput['questions'][0]['studentAnswer']);
        self::assertStringContainsString('Question 2 text from page 6', $gradingInput['questions'][1]['studentAnswer']);
        self::assertStringNotContainsString('Question 3 text', $gradingInput['questions'][1]['studentAnswer']);
        self::assertStringContainsString('Question 3 text from page 9', $gradingInput['questions'][2]['studentAnswer']);

        $attachedPages = array_column($gradingInput['attachedImages'], 'pageNumber');
        self::assertSame([1, 4, 7, 2, 5, 8], $attachedPages);
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
