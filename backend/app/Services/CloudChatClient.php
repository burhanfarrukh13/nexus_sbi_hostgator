<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CloudChatClient
{
    public function complete(array $messages): string
    {
        $url = (string) config('services.ai_chat.url');
        $key = (string) config('services.ai_chat.key');
        $model = (string) config('services.ai_chat.model');
        $timeout = min(90, max(3, (int) config('services.ai_chat.timeout', 45)));
        if (!str_starts_with($url, 'https://') || $key === '' || $model === '') {
            throw new RuntimeException('Cloud AI chat is not configured. Set AI_CHAT_API_URL, AI_CHAT_API_KEY, and AI_CHAT_MODEL.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($key)
                ->timeout($timeout)
                ->post($url, [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                ]);
        } catch (Throwable) {
            throw new RuntimeException('Cloud AI chat provider is unavailable.');
        }

        if (!$response->successful()) {
            throw new RuntimeException('Cloud AI chat provider rejected the request.');
        }

        $content = $response->json('choices.0.message.content');
        if (is_array($content)) {
            $content = implode("\n", array_filter(array_map(
                static fn (mixed $part): string => is_array($part) && is_string($part['text'] ?? null)
                    ? $part['text']
                    : '',
                $content
            )));
        }
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Cloud AI chat provider returned no answer.');
        }

        return trim($content);
    }
}
