<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CloudEmbeddingClient
{
    public function embed(array $texts): array
    {
        if ($texts === [] || count($texts) > 64) {
            throw new RuntimeException('Embedding request batch must contain 1 to 64 texts.');
        }
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '' || mb_strlen($text) > 20000) {
                throw new RuntimeException('Embedding text must be non-empty and at most 20,000 characters.');
            }
        }

        $url = (string) config('services.embeddings.url');
        $key = (string) config('services.embeddings.key');
        $model = (string) config('services.embeddings.model');
        $dimensions = (int) config('services.embeddings.dimensions');
        $timeout = min(60, max(3, (int) config('services.embeddings.timeout', 30)));
        if (!str_starts_with($url, 'https://') || $key === '' || $model === '' || $dimensions < 1) {
            throw new RuntimeException('Cloud embeddings are not fully configured.');
        }

        try {
            $response = Http::acceptJson()
                ->withToken($key)
                ->timeout($timeout)
                ->post($url, ['model' => $model, 'input' => array_values($texts)]);
        } catch (Throwable) {
            throw new RuntimeException('Cloud embedding provider is unavailable.');
        }

        if (!$response->successful()) {
            throw new RuntimeException('Cloud embedding provider rejected the request.');
        }
        $items = $response->json('data');
        if (!is_array($items) || count($items) !== count($texts)) {
            throw new RuntimeException('Cloud embedding provider returned an invalid response.');
        }

        $vectors = [];
        foreach ($items as $item) {
            $index = $item['index'] ?? null;
            $vector = $item['embedding'] ?? null;
            if (!is_int($index) || $index < 0 || $index >= count($texts) ||
                !is_array($vector) || count($vector) !== $dimensions) {
                throw new RuntimeException('Cloud embedding dimensions or ordering are invalid.');
            }
            foreach ($vector as $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new RuntimeException('Cloud embedding contains a non-numeric value.');
                }
            }
            $vectors[$index] = array_map('floatval', $vector);
        }
        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('Cloud embedding provider returned duplicate or missing indexes.');
        }
        ksort($vectors);

        return [
            'model' => $model,
            'dimensions' => $dimensions,
            'embeddings' => array_values($vectors),
        ];
    }
}
