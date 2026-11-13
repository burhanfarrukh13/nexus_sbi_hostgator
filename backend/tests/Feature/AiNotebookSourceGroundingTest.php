<?php

namespace Tests\Feature;

use App\Services\CloudChatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

class AiNotebookSourceGroundingTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    private string $subjectId;
    private string $studentId;
    private object $chatClient;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->subjectId] = $this->createTest();
        $this->studentId = $this->createUser();
        $this->grantSubject($this->studentId, $this->subjectId);
        $this->chatClient = new class extends CloudChatClient {
            public int $calls = 0;
            public array $messages = [];

            public function complete(
                array $messages,
                bool $jsonResponse = false,
                ?int $timeoutOverride = null,
                ?int $maxTokens = null
            ): string {
                $this->calls++;
                $this->messages[] = $messages;

                return 'The source-supported answer.';
            }
        };
        $this->app->instance(CloudChatClient::class, $this->chatClient);
        config([
            'services.embeddings.url' => 'https://openrouter.example.test/api/v1/embeddings',
            'services.embeddings.key' => 'test-key',
            'services.embeddings.model' => 'test-model',
            'services.embeddings.dimensions' => 3,
        ]);
    }

    public function test_question_without_relevant_sources_is_refused_without_calling_the_chat_model(): void
    {
        $response = $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/chat', [
                'clientRequestId' => (string) Str::uuid(),
                'subjectId' => $this->subjectId,
                'question' => 'What is the capital of France?',
            ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'I couldn’t find an answer to that in this subject’s uploaded sources, so I can’t answer it from the Notebook.'
            )
            ->assertJsonPath('citations', [])
            ->assertJsonPath('studyAid', null);

        self::assertSame(0, $this->chatClient->calls);
        self::assertNotEmpty($response->json('sessionId'));
    }

    public function test_irrelevant_embedding_is_refused_without_calling_the_chat_model(): void
    {
        $this->addEmbeddedChunk('Photosynthesis converts light energy into chemical energy in plants.');
        Http::fake([
            'openrouter.example.test/*' => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.2, 0.9797958971, 0.0]]],
            ]),
        ]);

        $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/chat', [
                'clientRequestId' => (string) Str::uuid(),
                'subjectId' => $this->subjectId,
                'question' => 'How do volcanoes form?',
            ])
            ->assertOk()
            ->assertJsonPath('citations', [])
            ->assertJsonPath(
                'answer',
                'I couldn’t find an answer to that in this subject’s uploaded sources, so I can’t answer it from the Notebook.'
            );
        self::assertSame(0, $this->chatClient->calls);
    }

    public function test_relevant_source_content_reaches_the_model(): void
    {
        $this->addEmbeddedChunk('Photosynthesis converts light energy into chemical energy in plants.');
        Http::fake([
            'openrouter.example.test/*' => Http::response([
                'data' => [['index' => 0, 'embedding' => [1.0, 0.0, 0.0]]],
            ]),
        ]);
        $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/chat', [
                'clientRequestId' => (string) Str::uuid(),
                'subjectId' => $this->subjectId,
                'question' => 'How does photosynthesis work?',
            ])
            ->assertOk()
            ->assertJsonPath('answer', 'The source-supported answer.')
            ->assertJsonCount(1, 'citations');

        self::assertSame(1, $this->chatClient->calls);
        self::assertStringContainsString(
            'Photosynthesis converts light energy into chemical energy in plants.',
            $this->chatClient->messages[0][0]['content']
        );
        self::assertStringContainsString('Do not use general knowledge', $this->chatClient->messages[0][0]['content']);
    }

    public function test_context_endpoint_marks_unsupported_questions_for_the_cloudflare_worker_to_refuse(): void
    {
        $this->addEmbeddedChunk('Photosynthesis converts light energy into chemical energy in plants.');
        Http::fake([
            'openrouter.example.test/*' => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.2, 0.9797958971, 0.0]]],
            ]),
        ]);

        $this->withToken($this->token($this->studentId))
            ->postJson('/api/ai-notebook/context', [
                'subjectId' => $this->subjectId,
                'question' => 'How do volcanoes form?',
            ])
            ->assertOk()
            ->assertJsonPath('grounded', false)
            ->assertJsonPath('sourceContext', '')
            ->assertJsonPath(
                'refusal',
                'I couldn’t find an answer to that in this subject’s uploaded sources, so I can’t answer it from the Notebook.'
            );

        self::assertSame(0, $this->chatClient->calls);
    }

    private function addEmbeddedChunk(string $content): void
    {
        $sourceId = (string) Str::uuid();
        DB::table('ai_notebook_sources')->insert([
            'id' => $sourceId,
            'subject_id' => $this->subjectId,
            'title' => 'Biology notes',
            'original_name' => 'biology.txt',
            'mime_type' => 'text/plain',
            'storage_key' => 'test/biology.txt',
            'status' => 'ready',
            'total_chunks' => 1,
            'completed_chunks' => 1,
            'created_at' => now(),
        ]);
        DB::table('ai_notebook_chunks')->insert([
            'id' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'ordinal' => 0,
            'content' => $content,
            'embedding' => json_encode([1.0, 0.0, 0.0], JSON_THROW_ON_ERROR),
            'embedding_model' => 'test-model',
            'created_at' => now(),
        ]);
    }
}
