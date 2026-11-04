<?php

namespace Tests\Feature;

use App\Services\CloudEmbeddingClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Tests\TestCase;

class CloudEmbeddingClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.embeddings.url', 'https://embeddings.example.test/v1/embeddings');
        Config::set('services.embeddings.key', 'test-key');
        Config::set('services.embeddings.model', 'test-model');
        Config::set('services.embeddings.dimensions', 3);
    }

    public function test_it_returns_vectors_with_configured_model_and_dimensions(): void
    {
        Http::fake(['embeddings.example.test/*' => Http::response([
            'data' => [
                ['index' => 1, 'embedding' => [4, 5, 6]],
                ['index' => 0, 'embedding' => [1, 2, 3]],
            ],
        ])]);

        $result = app(CloudEmbeddingClient::class)->embed(['first', 'second']);

        self::assertSame('test-model', $result['model']);
        self::assertSame(3, $result['dimensions']);
        self::assertSame([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]], $result['embeddings']);
    }

    public function test_it_rejects_wrong_vector_dimensions(): void
    {
        Http::fake(['embeddings.example.test/*' => Http::response([
            'data' => [['index' => 0, 'embedding' => [1, 2]]],
        ])]);

        $this->expectException(RuntimeException::class);
        app(CloudEmbeddingClient::class)->embed(['text']);
    }

    public function test_it_rejects_provider_errors_without_logging_sensitive_data(): void
    {
        Http::fake(['embeddings.example.test/*' => Http::response(['error' => 'rejected'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter embedding provider rejected the request.');
        app(CloudEmbeddingClient::class)->embed(['sensitive input']);
    }

    public function test_it_surfaces_a_timeout_without_exposing_provider_details(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('timeout with secret request details');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter embedding provider is unavailable.');
        app(CloudEmbeddingClient::class)->embed(['sensitive input']);
    }

    public function test_it_rejects_malformed_provider_data(): void
    {
        Http::fake(['embeddings.example.test/*' => Http::response(['data' => 'invalid'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter returned an invalid embedding response.');
        app(CloudEmbeddingClient::class)->embed(['text']);
    }
}
