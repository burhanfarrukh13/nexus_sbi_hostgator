<?php

namespace Tests\Feature;

use App\Services\CloudChatClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OllamaChatClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.ai_chat.url', 'https://ollama.example.test/v1/chat/completions');
        Config::set('services.ai_chat.key', 'test-ollama-key');
        Config::set('services.ai_chat.model', 'test-chat-model');
    }

    public function test_it_sends_openai_compatible_chat_requests_to_ollama_cloud(): void
    {
        Http::fake([
            'ollama.example.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'A useful answer.']],
                ],
            ]),
        ]);

        $result = app(CloudChatClient::class)->complete([
            ['role' => 'user', 'content' => 'Explain this topic.'],
        ]);

        self::assertSame('A useful answer.', $result);
        Http::assertSent(static fn ($request): bool =>
            $request->url() === 'https://ollama.example.test/v1/chat/completions' &&
            $request->hasHeader('Authorization', 'Bearer test-ollama-key') &&
            $request['model'] === 'test-chat-model'
        );
    }

    public function test_it_requires_ollama_cloud_configuration(): void
    {
        Config::set('services.ai_chat.key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set OLLAMA_API_URL, OLLAMA_API_KEY, and OLLAMA_MODEL.');
        app(CloudChatClient::class)->complete([
            ['role' => 'user', 'content' => 'Hello.'],
        ]);
    }

    public function test_it_can_request_json_mode_for_structured_assessments(): void
    {
        Http::fake([
            'ollama.example.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"questions":[]}']],
                ],
            ]),
        ]);

        $result = app(CloudChatClient::class)->complete([
            ['role' => 'user', 'content' => 'Return JSON.'],
        ], true);

        self::assertSame('{"questions":[]}', $result);
        Http::assertSent(static fn ($request): bool =>
            $request['response_format'] === ['type' => 'json_object']
        );
    }

    public function test_it_retries_rate_limited_provider_calls_and_honors_retry_after(): void
    {
        Config::set('services.ai_chat.retry_delays', [0]);
        Http::fake([
            'ollama.example.test/*' => Http::sequence()
                ->push(['error' => 'busy'], 429, ['Retry-After' => '0'])
                ->push(['choices' => [['message' => ['content' => 'Recovered answer.']]]]),
        ]);

        self::assertSame('Recovered answer.', app(CloudChatClient::class)->complete([
            ['role' => 'user', 'content' => 'Try again safely.'],
        ]));

        Http::assertSentCount(2);
    }

    public function test_notebook_timeout_override_is_accepted_without_changing_the_chat_contract(): void
    {
        Config::set('services.ai_chat.timeout', 300);
        Config::set('services.ai_chat.retry_delays', []);
        Http::fake([
            'ollama.example.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Short-timeout answer.']]],
            ]),
        ]);

        app(CloudChatClient::class)->complete([
            ['role' => 'user', 'content' => 'Notebook question.'],
        ], false, 60);

        Http::assertSentCount(1);
    }

    public function test_it_sends_image_content_to_a_vision_capable_model(): void
    {
        Config::set('services.ai_chat.model', 'gemma4:31b');
        Http::fake([
            'ollama.example.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"questions":[]}']],
                ],
            ]),
        ]);
        $messages = [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Read this answer page.'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,ZW1wdHk=']],
            ],
        ]];

        self::assertSame('{"questions":[]}', app(CloudChatClient::class)->complete($messages, true));
        Http::assertSent(static fn ($request): bool =>
            $request['messages'][0]['content'][1]['image_url']['url'] === 'data:image/jpeg;base64,ZW1wdHk='
        );
    }

    public function test_it_rejects_image_grading_with_a_text_only_model(): void
    {
        Config::set('services.ai_chat.model', 'text-only-model');
        Http::fake();

        try {
            app(CloudChatClient::class)->complete([[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Read this answer page.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,ZW1wdHk=']],
                ],
            ]]);
            self::fail('Image grading must require a vision-capable model.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('vision-capable model', $error->getMessage());
        }
        Http::assertNothingSent();
    }
}
