<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReindexNotebookEmbeddingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reindexes_legacy_lexical_chunks_with_openrouter_vectors(): void
    {
        Config::set('services.embeddings.url', 'https://embeddings.example.test/v1/embeddings');
        Config::set('services.embeddings.key', 'test-key');
        Config::set('services.embeddings.model', 'test-embedding-model');
        Config::set('services.embeddings.dimensions', 3);

        $userId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $sourceId = (string) Str::uuid();
        $chunkId = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'embedding-admin@example.test',
            'password' => 'not-used',
            'role' => 'admin',
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'EMBED-1',
            'name' => 'Embeddings',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_notebook_sources')->insert([
            'id' => $sourceId,
            'subject_id' => $subjectId,
            'title' => 'Legacy source',
            'original_name' => 'legacy.txt',
            'mime_type' => 'text/plain',
            'storage_key' => 'test/legacy.txt',
            'status' => 'ready',
            'uploaded_by_user_id' => $userId,
            'total_chunks' => 1,
            'completed_chunks' => 1,
            'created_at' => now(),
        ]);
        DB::table('ai_notebook_chunks')->insert([
            'id' => $chunkId,
            'source_id' => $sourceId,
            'ordinal' => 0,
            'content' => 'An existing notebook chunk.',
            'embedding' => '[]',
            'embedding_model' => 'lexical',
            'created_at' => now(),
        ]);
        Http::fake([
            'embeddings.example.test/*' => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
            ]),
        ]);

        self::assertSame(0, Artisan::call('ai-notebook:reindex-embeddings'));
        self::assertSame('test-embedding-model', DB::table('ai_notebook_chunks')
            ->where('id', $chunkId)
            ->value('embedding_model'));
        self::assertSame([0.1, 0.2, 0.3], json_decode(
            DB::table('ai_notebook_chunks')->where('id', $chunkId)->value('embedding'),
            true,
            512,
            JSON_THROW_ON_ERROR
        ));
        Http::assertSent(static fn ($request): bool =>
            $request['input'] === ['An existing notebook chunk.'] &&
            $request['dimensions'] === 3
        );
    }
}
