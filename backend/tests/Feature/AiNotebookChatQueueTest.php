<?php

namespace Tests\Feature;

use App\Services\CloudChatClient;
use App\Services\PrivateFileStorage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

class AiNotebookChatQueueTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    private string $subjectId;
    private string $firstStudentId;
    private string $secondStudentId;
    private CloudChatClient $chatClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTemporaryPrivateStorage();
        [$this->subjectId] = $this->createTest();
        $this->firstStudentId = $this->createUser();
        $this->secondStudentId = $this->createUser();
        $this->grantSubject($this->firstStudentId, $this->subjectId);
        $this->grantSubject($this->secondStudentId, $this->subjectId);
        $this->chatClient = new class extends CloudChatClient {
            public int $calls = 0;
            public bool $shouldFail = false;
            public array $questions = [];
            public array $messages = [];
            public array $timeouts = [];
            public array $maxTokens = [];

            public function complete(
                array $messages,
                bool $jsonResponse = false,
                ?int $timeoutOverride = null,
                ?int $maxTokens = null
            ): string
            {
                $this->calls++;
                $this->messages[] = $messages;
                $this->timeouts[] = $timeoutOverride;
                $this->maxTokens[] = $maxTokens;
                $last = $messages[count($messages) - 1]['content'] ?? '';
                $this->questions[] = is_string($last)
                    ? $last
                    : (string) ($last[0]['text'] ?? '');
                if ($this->shouldFail) {
                    throw new \RuntimeException('provider unavailable');
                }

                return 'A helpful answer.';
            }
        };
        $this->useChat($this->chatClient);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_eight_chat_slots_are_non_blocking_and_cap_provider_work(): void
    {
        config(['services.ai_notebook.max_concurrent' => 8]);
        $storage = $this->app->make(PrivateFileStorage::class);
        $slots = [];
        for ($index = 0; $index < 8; $index++) {
            $slots[] = $storage->acquireChatSlot();
            self::assertIsResource($slots[$index]);
        }

        self::assertNull($storage->acquireChatSlot());
        foreach ($slots as $slot) {
            $storage->releaseChatSlot($slot);
        }
        $lastSlot = $storage->acquireChatSlot();
        self::assertIsResource($lastSlot);
        $storage->releaseChatSlot($lastSlot);
    }

    public function test_waiting_tickets_are_fifo_and_responses_expose_no_queue_information(): void
    {
        config(['services.ai_notebook.max_concurrent' => 1]);
        $storage = $this->app->make(PrivateFileStorage::class);
        $heldSlot = $storage->acquireChatSlot(1);
        self::assertIsResource($heldSlot);
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        $this->sendQuestion($this->firstStudentId, $firstId, 'First question?')
            ->assertStatus(202)
            ->assertExactJson(['retryAfterSeconds' => 4]);
        $this->sendQuestion($this->secondStudentId, $secondId, 'Second question?')
            ->assertStatus(202)
            ->assertExactJson(['retryAfterSeconds' => 4]);

        $storage->releaseChatSlot($heldSlot);
        $this->checkTicket($this->secondStudentId, $secondId)
            ->assertStatus(202)
            ->assertExactJson(['retryAfterSeconds' => 4]);
        self::assertSame(0, $this->chatClient->calls);

        $this->checkTicket($this->firstStudentId, $firstId)->assertOk();
        $this->checkTicket($this->secondStudentId, $secondId)->assertOk();
        self::assertSame(['First question?', 'Second question?'], $this->chatClient->questions);
    }

    public function test_ticket_rechecks_allow_the_frontend_retry_cadence_without_opening_new_question_rate_limits(): void
    {
        config(['services.ai_notebook.max_concurrent' => 1]);
        $storage = $this->app->make(PrivateFileStorage::class);
        $heldSlot = $storage->acquireChatSlot(1);
        self::assertIsResource($heldSlot);
        $requestId = (string) Str::uuid();
        $this->sendQuestion($this->firstStudentId, $requestId, 'Still waiting?')
            ->assertStatus(202);

        for ($retry = 0; $retry < 20; $retry++) {
            $this->checkTicket($this->firstStudentId, $requestId)
                ->assertStatus(202)
                ->assertExactJson(['retryAfterSeconds' => 4]);
        }

        $storage->releaseChatSlot($heldSlot);
    }

    public function test_completed_request_retries_return_the_stored_answer_without_repeating_provider_work(): void
    {
        config([
            'services.ai_chat.timeout' => 300,
            'services.ai_chat.notebook_timeout' => 60,
        ]);
        $requestId = (string) Str::uuid();
        $firstResponse = $this->sendQuestion($this->firstStudentId, $requestId, 'Explain this topic?')
            ->assertOk()
            ->json();

        $this->checkTicket($this->firstStudentId, $requestId)
            ->assertOk()
            ->assertExactJson($firstResponse);
        self::assertSame(1, $this->chatClient->calls);
        self::assertSame([60], $this->chatClient->timeouts);
        self::assertSame([2048], $this->chatClient->maxTokens);
        self::assertStringContainsString(
            '3-6 concise paragraphs',
            $this->chatClient->messages[0][0]['content']
        );
        self::assertSame(2, DB::table('ai_notebook_chat_messages')->count());
        self::assertSame(1, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->firstStudentId)->value('requestCount'));
    }

    public function test_ticket_expiry_refunds_the_reservation_and_a_later_owner_retry_preserves_the_question(): void
    {
        config(['services.ai_notebook.max_concurrent' => 1]);
        $storage = $this->app->make(PrivateFileStorage::class);
        $heldSlot = $storage->acquireChatSlot(1);
        self::assertIsResource($heldSlot);
        $expiredId = (string) Str::uuid();
        $otherId = (string) Str::uuid();

        $this->sendQuestion($this->firstStudentId, $expiredId, 'Keep this question?')
            ->assertStatus(202);
        Carbon::setTestNow(now()->addSeconds(121));
        $this->sendQuestion($this->secondStudentId, $otherId, 'Another question?')
            ->assertStatus(202);

        $expired = DB::table('ai_notebook_chat_tickets')
            ->where('user_id', $this->firstStudentId)
            ->where('client_request_id', $expiredId)
            ->first();
        self::assertSame('expired', $expired->status);
        self::assertSame('Keep this question?', $expired->question);
        self::assertSame(0, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->firstStudentId)->value('requestCount'));

        Carbon::setTestNow(now()->addMicroseconds(10));
        $this->checkTicket($this->firstStudentId, $expiredId)
            ->assertStatus(202)
            ->assertExactJson(['retryAfterSeconds' => 4]);
        self::assertSame(1, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->firstStudentId)->value('requestCount'));
        $storage->releaseChatSlot($heldSlot);

        $this->checkTicket($this->secondStudentId, $otherId)->assertOk();
        $this->checkTicket($this->firstStudentId, $expiredId)->assertOk();
        self::assertSame(['Another question?', 'Keep this question?'], $this->chatClient->questions);
    }

    public function test_provider_failure_keeps_the_ticket_and_refunds_quota_before_retry(): void
    {
        $requestId = (string) Str::uuid();
        $this->chatClient->shouldFail = true;
        $this->sendQuestion($this->firstStudentId, $requestId, 'Do not lose this?')
            ->assertStatus(202)
            ->assertExactJson(['retryAfterSeconds' => 4]);

        self::assertSame(0, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->firstStudentId)->value('requestCount'));
        $ticket = DB::table('ai_notebook_chat_tickets')
            ->where('client_request_id', $requestId)->first();
        self::assertSame('pending', $ticket->status);
        self::assertSame('Do not lose this?', $ticket->question);
        self::assertFalse((bool) $ticket->usage_reserved);

        $this->chatClient->shouldFail = false;
        $this->checkTicket($this->firstStudentId, $requestId)->assertOk();
        self::assertSame(1, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->firstStudentId)->value('requestCount'));
        self::assertSame(2, $this->chatClient->calls);
    }

    public function test_ticket_ids_cannot_be_used_to_query_another_students_answer(): void
    {
        $requestId = (string) Str::uuid();
        $this->sendQuestion($this->firstStudentId, $requestId, 'Private question?')->assertOk();

        $this->checkTicket($this->secondStudentId, $requestId)
            ->assertStatus(422)
            ->assertJsonMissingPath('answer');
        self::assertSame(1, $this->chatClient->calls);
    }

    public function test_queued_photo_is_stored_privately_and_used_on_owner_retry(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to create an image fixture.');
        }
        config(['services.ai_notebook.max_concurrent' => 1]);
        $storage = $this->app->make(PrivateFileStorage::class);
        $heldSlot = $storage->acquireChatSlot(1);
        self::assertIsResource($heldSlot);
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        $requestId = (string) Str::uuid();

        $this->withToken($this->token($this->firstStudentId))
            ->post('/api/ai-notebook/chat', [
                'clientRequestId' => $requestId,
                'subjectId' => $this->subjectId,
                'image' => UploadedFile::fake()->createWithContent('question.jpg', $jpeg),
            ])
            ->assertStatus(202)
            ->assertExactJson(['retryAfterSeconds' => 4]);

        $ticket = DB::table('ai_notebook_chat_tickets')
            ->where('client_request_id', $requestId)->first();
        $imagePath = $storage->resolve($ticket->image_storage_key);
        self::assertFileExists($imagePath);
        $publicPath = realpath(public_path());
        self::assertFalse(str_starts_with(
            strtolower($imagePath),
            strtolower(rtrim((string) $publicPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR),
        ));

        $storage->releaseChatSlot($heldSlot);
        $this->checkTicket($this->firstStudentId, $requestId)->assertOk();
        $lastMessage = $this->chatClient->messages[0][count($this->chatClient->messages[0]) - 1]['content'];
        self::assertSame('image_url', $lastMessage[1]['type']);
        self::assertFalse(is_file($imagePath));
    }

    private function sendQuestion(string $userId, string $requestId, string $question)
    {
        return $this->withToken($this->token($userId))->postJson('/api/ai-notebook/chat', [
            'clientRequestId' => $requestId,
            'subjectId' => $this->subjectId,
            'question' => $question,
        ]);
    }

    private function checkTicket(string $userId, string $requestId)
    {
        return $this->withToken($this->token($userId))->postJson('/api/ai-notebook/chat', [
            'clientRequestId' => $requestId,
        ]);
    }
}
