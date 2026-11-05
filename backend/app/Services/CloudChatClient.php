<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CloudChatClient
{
    public function complete(array $messages, bool $jsonResponse = false, ?int $timeoutOverride = null): string
    {
        $url = (string) config('services.ai_chat.url');
        $key = (string) config('services.ai_chat.key');
        $model = (string) config('services.ai_chat.model');
        $timeout = min(300, max(3, $timeoutOverride ?? (int) config('services.ai_chat.timeout', 300)));
        if (!str_starts_with($url, 'https://') || $key === '' || $model === '') {
            throw new RuntimeException('Ollama Cloud is not configured. Set OLLAMA_API_URL, OLLAMA_API_KEY, and OLLAMA_MODEL.');
        }
        if ($this->containsImages($messages) && !$this->supportsVision($model)) {
            throw new RuntimeException('The configured Ollama model does not support image grading. Set OLLAMA_MODEL to a vision-capable model such as gemma4.');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
        ];
        if ($jsonResponse) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = null;
        $retryDelays = array_values((array) config('services.ai_chat.retry_delays', [1, 3, 8]));
        $maxAttempts = count($retryDelays) + 1;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->withToken($key)
                    ->timeout($timeout)
                    ->post($url, $payload);
            } catch (Throwable) {
                if ($attempt < $maxAttempts - 1) {
                    $this->waitBeforeRetry($attempt, $retryDelays);
                    continue;
                }
                throw new RuntimeException('Ollama chat provider is unavailable.');
            }
            if ($response->successful() ||
                ($response->status() < 500 && $response->status() !== 429) ||
                $attempt >= $maxAttempts - 1) {
                break;
            }
            $this->waitBeforeRetry($attempt, $retryDelays, $response->header('Retry-After'));
        }

        if ($response === null) {
            throw new RuntimeException('Ollama chat provider is unavailable.');
        }
        if (!$response->successful()) {
            throw new RuntimeException('Ollama chat provider rejected the request.');
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
            throw new RuntimeException('Ollama chat provider returned no answer.');
        }

        return trim($content);
    }

    /**
     * Retry-After takes precedence over local exponential backoff. Otherwise
     * jitter spreads simultaneous retries without changing the model request.
     *
     * @param list<int|float> $delays
     */
    private function waitBeforeRetry(int $attempt, array $delays, ?string $retryAfter = null): void
    {
        $seconds = null;
        if ($retryAfter !== null && trim($retryAfter) !== '') {
            $retryAfter = trim($retryAfter);
            if (is_numeric($retryAfter)) {
                $seconds = max(0.0, (float) $retryAfter);
            } else {
                $timestamp = strtotime($retryAfter);
                if ($timestamp !== false) {
                    $seconds = max(0.0, (float) ($timestamp - time()));
                }
            }
        }
        if ($seconds === null) {
            $base = max(0.0, (float) ($delays[$attempt] ?? 8));
            $seconds = $base * (1 + (random_int(0, 20) / 100));
        }
        if ($seconds > 0) {
            usleep((int) min($seconds * 1_000_000, 300_000_000));
        }
    }

    private function containsImages(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'image_url') {
                    return true;
                }
            }
        }

        return false;
    }

    private function supportsVision(string $model): bool
    {
        return preg_match('/(?:^|[:\/-])(?:vision|llava|gemma[34]|qwen2\.5-vl|qwen2\.5.*vl|pixtral|minicpm-v|granite3-vision|moondream|mistral-small3\.1|llama-?3\.2-vision|phi-3\.5-vision|smolvlm)/i', trim($model)) === 1;
    }
}
