<?php

namespace Tests\Feature;

use App\Services\CloudChatClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

class AiNotebookDailyLimitTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    private string $studentId;
    private string $subjectId;
    private CloudChatClient $chatClient;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->subjectId] = $this->createTest();
        $this->studentId = $this->createUser();
        $this->grantSubject($this->studentId, $this->subjectId);
        $this->chatClient = new class extends CloudChatClient {
            public bool $shouldFail = false;

            public function complete(
                array $messages,
                bool $jsonResponse = false,
                ?int $timeoutOverride = null,
                ?int $maxTokens = null
            ): string
            {
                if ($this->shouldFail) {
                    throw new RuntimeException('provider unavailable');
                }

                return 'A helpful answer.';
            }
        };
        $this->app->instance(CloudChatClient::class, $this->chatClient);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_settings_are_private_validated_and_default_to_fifteen(): void
    {
        $adminId = $this->createUser('admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $adminId,
            'granteeId' => $adminId,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);
        $adminToken = $this->token($adminId);

        $this->getJson('/api/tests/ai-grading-settings')->assertUnauthorized();
        $this->withToken($this->token($this->studentId))
            ->getJson('/api/tests/ai-grading-settings')->assertForbidden();
        $managerId = $this->createUser('admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $managerId,
            'granteeId' => $managerId,
            'permission' => 'testing_manager',
            'grantedAt' => now(),
        ]);
        $this->withToken($this->token($managerId))
            ->getJson('/api/tests/ai-grading-settings')->assertForbidden();
        $this->withToken($adminToken)->getJson('/api/tests/ai-grading-settings')
            ->assertOk()->assertJsonPath('notebookDailyQuestionLimit', 15);
        $this->withToken($adminToken)->putJson('/api/tests/ai-grading-settings', [
            'deficiencyReportPrompt' => '',
            'resultReleaseDelayHours' => 0,
            'notebookDailyQuestionLimit' => 25,
        ])->assertOk()->assertJsonPath('notebookDailyQuestionLimit', 25);
        $this->withToken($adminToken)->putJson('/api/tests/ai-grading-settings', [
            'deficiencyReportPrompt' => '',
            'resultReleaseDelayHours' => 0,
            'notebookDailyQuestionLimit' => 0,
        ])->assertBadRequest();
    }

    public function test_successful_answers_count_toward_limit_but_failed_answers_are_refunded(): void
    {
        $this->chatClient->shouldFail = true;
        $clientRequestId = (string) Str::uuid();
        $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/chat', [
                'clientRequestId' => $clientRequestId,
                'subjectId' => $this->subjectId,
                'question' => 'Another question?',
            ])->assertStatus(202)
            ->assertJsonPath('retryAfterSeconds', 4)
            ->assertJsonMissingPath('queuePosition');

        self::assertSame(0, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->studentId)->value('requestCount'));
        self::assertSame('Another question?', DB::table('ai_notebook_chat_tickets')
            ->where('client_request_id', $clientRequestId)->value('question'));

        $date = Carbon::now('Asia/Karachi')->toDateString();
        DB::table('ai_notebook_daily_usage')->updateOrInsert(
            ['userId' => $this->studentId],
            ['usageDate' => $date, 'requestCount' => 14, 'updatedAt' => now()],
        );
        $this->chatClient->shouldFail = false;
        $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/chat', [
                'clientRequestId' => $clientRequestId,
                'subjectId' => $this->subjectId,
                'question' => 'Why?',
            ])->assertOk()
            ->assertJsonMissingPath('queuePosition')
            ->assertJsonMissingPath('position')
            ->assertJsonMissingPath('queue');
        self::assertSame(15, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->studentId)->value('requestCount'));

        $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/chat', [
                'clientRequestId' => (string) Str::uuid(),
                'subjectId' => $this->subjectId,
                'question' => 'One too many?',
            ])->assertStatus(429)
            ->assertJsonMissingPath('queuePosition');
    }

    public function test_cap_is_enforced_and_counter_resets_at_asia_karachi_midnight(): void
    {
        config(['services.ai_notebook.day_reset_timezone' => 'Asia/Karachi']);
        Carbon::setTestNow('2026-11-05 18:59:00 UTC');
        $this->withToken($this->token($this->studentId))->postJson('/api/ai-notebook/chat', [
            'clientRequestId' => (string) Str::uuid(),
            'subjectId' => $this->subjectId, 'question' => 'Before midnight?',
        ])->assertOk();
        self::assertSame('2026-11-05', DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->studentId)->value('usageDate'));

        DB::table('ai_grading_settings')->where('id', 1)->update(['notebook_daily_question_limit' => 1]);
        $this->withToken($this->token($this->studentId))->postJson('/api/ai-notebook/chat', [
            'clientRequestId' => (string) Str::uuid(),
            'subjectId' => $this->subjectId, 'question' => 'At the limit?',
        ])->assertStatus(429)
            ->assertJsonPath('message', 'You\'ve used all 1 AI Notebook question for today. Your allowance resets at midnight.')
            ->assertJsonMissingPath('queuePosition');

        Carbon::setTestNow('2026-11-05 19:01:00 UTC');
        $this->withToken($this->token($this->studentId))->postJson('/api/ai-notebook/chat', [
            'clientRequestId' => (string) Str::uuid(),
            'subjectId' => $this->subjectId, 'question' => 'After midnight?',
        ])->assertOk();
        self::assertSame('2026-11-06', DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->studentId)->value('usageDate'));
        self::assertSame(1, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $this->studentId)->value('requestCount'));
    }

    public function test_daily_question_limit_applies_to_students_not_admins(): void
    {
        $adminId = $this->createUser('admin');
        $adminToken = $this->token($adminId);

        $date = Carbon::now('Asia/Karachi')->toDateString();
        DB::table('ai_notebook_daily_usage')->insert([
            'userId' => $adminId,
            'usageDate' => $date,
            'requestCount' => 15,
            'updatedAt' => now(),
        ]);

        $this->withToken($adminToken)->postJson('/api/ai-notebook/chat', [
            'clientRequestId' => (string) Str::uuid(),
            'subjectId' => $this->subjectId,
            'question' => 'Can staff use the Notebook?',
        ])->assertOk();

        self::assertSame(15, (int) DB::table('ai_notebook_daily_usage')
            ->where('userId', $adminId)
            ->value('requestCount'));
    }
}
