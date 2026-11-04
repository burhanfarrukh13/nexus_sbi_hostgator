<?php

namespace App\Console\Commands;

use App\Services\CloudEmbeddingClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReindexNotebookEmbeddings extends Command
{
    protected $signature = 'ai-notebook:reindex-embeddings {--source= : Reindex one source by its UUID}';

    protected $description = 'Generate OpenRouter embeddings for existing AI Notebook source chunks';

    public function handle(CloudEmbeddingClient $embeddings): int
    {
        $sourceId = trim((string) $this->option('source'));
        if ($sourceId !== '' && !Str::isUuid($sourceId)) {
            $this->error('The source option must be a valid UUID.');

            return self::FAILURE;
        }

        $sources = DB::table('ai_notebook_sources')
            ->where('status', 'ready')
            ->when($sourceId !== '', static fn ($query) => $query->where('id', $sourceId))
            ->orderBy('created_at')
            ->get(['id', 'title']);
        if ($sourceId !== '' && $sources->isEmpty()) {
            $this->error('No ready notebook source was found with that UUID.');

            return self::FAILURE;
        }

        if ($sources->isEmpty()) {
            $this->info('No ready notebook sources need indexing.');

            return self::SUCCESS;
        }

        $indexed = 0;
        foreach ($sources as $source) {
            $chunks = DB::table('ai_notebook_chunks')
                ->where('source_id', $source->id)
                ->orderBy('ordinal')
                ->get(['id', 'content']);
            foreach ($chunks->chunk(64) as $batch) {
                $result = $embeddings->embed($batch->pluck('content')->all());
                DB::transaction(function () use ($batch, $result): void {
                    foreach ($batch->values() as $index => $chunk) {
                        DB::table('ai_notebook_chunks')->where('id', $chunk->id)->update([
                            'embedding' => json_encode($result['embeddings'][$index], JSON_THROW_ON_ERROR),
                            'embedding_model' => $result['model'],
                        ]);
                    }
                });
                $indexed += $batch->count();
            }

            $this->line("Indexed {$chunks->count()} chunks: {$source->title}");
        }

        $this->info("OpenRouter embeddings are ready for {$indexed} notebook chunks.");

        return self::SUCCESS;
    }
}
