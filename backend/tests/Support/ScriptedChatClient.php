<?php

namespace Tests\Support;

use App\Services\CloudChatClient;
use Closure;

/**
 * Stand-in for the Ollama chat client. Tests decide what the "model" answers, so no
 * HTTP request is ever made and no API key is needed.
 */
final class ScriptedChatClient extends CloudChatClient
{
    /** @var list<array> Every message list the grader sent, in order. */
    public array $calls = [];

    /** @param Closure(array, int): (string|array) $responder Receives the messages and the 0-based call number. */
    public function __construct(private readonly Closure $responder)
    {
    }

    /** Always answers with the same assessment (arrays are JSON-encoded). */
    public static function always(array|string $response): self
    {
        return new self(static fn (): array|string => $response);
    }

    public function complete(
        array $messages,
        bool $jsonResponse = false,
        ?int $timeoutOverride = null,
        ?int $maxTokens = null
    ): string
    {
        $number = count($this->calls);
        $this->calls[] = $messages;
        $response = ($this->responder)($messages, $number);

        return is_array($response) ? json_encode($response, JSON_THROW_ON_ERROR) : $response;
    }

    /** True when the call asked the model to transcribe a page rather than grade answers. */
    public static function isTranscription(array $messages): bool
    {
        return str_starts_with((string) ($messages[0]['content'] ?? ''), 'Transcribe only');
    }

    public function transcriptionCalls(): int
    {
        return count(array_filter($this->calls, static fn (array $messages): bool => self::isTranscription($messages)));
    }

    public function gradingCalls(): int
    {
        return count($this->calls) - $this->transcriptionCalls();
    }
}
