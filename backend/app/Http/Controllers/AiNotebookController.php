<?php

namespace App\Http\Controllers;

use App\Services\CloudChatClient;
use App\Services\CloudEmbeddingClient;
use App\Services\PrivateFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AiNotebookController
{
    private const CHUNK_LENGTH = 1600;
    private const CHUNK_OVERLAP = 240;
    private const DAILY_REQUEST_LIMIT = 20;

    public function __construct(
        private readonly PrivateFileStorage $storage,
        private readonly CloudEmbeddingClient $embeddings,
        private readonly CloudChatClient $chatClient
    ) {
    }

    public function subjects(Request $request): JsonResponse
    {
        $identity = $this->identity($request);
        $query = DB::table('subjects');
        if (($identity['role'] ?? null) === 'student') {
            $query->join('subject_user_access', 'subject_user_access.subject_id', '=', 'subjects.id')
                ->where('subject_user_access.user_id', $identity['userId']);
        }

        return response()->json($query->orderBy('subjects.name')->get([
            'subjects.id',
            'subjects.name',
            'subjects.code',
        ]));
    }

    public function subjectSources(Request $request, string $subjectId): JsonResponse
    {
        $this->ensureSubjectAccessible($request, $subjectId);
        $sources = DB::table('ai_notebook_sources')
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($sources->map(fn (object $source): array => $this->sourceResponse($source))->all());
    }

    public function storeSource(Request $request): JsonResponse
    {
        $input = $request->validate([
            'subjectId' => ['required', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,docx,txt', 'max:102400'],
        ]);
        $subjectId = $input['subjectId'];
        $this->ensureSubjectAccessible($request, $subjectId, true);
        $file = $request->file('file');
        if (!$file) {
            return response()->json(['message' => 'A source file is required.'], 422);
        }

        try {
            $text = $this->extractText($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        } catch (\Throwable $exception) {
            Log::warning('AI Notebook source extraction failed.', [
                'exception' => $exception::class,
            ]);
            return response()->json(['message' => 'The uploaded file could not be read as a supported text document.'], 422);
        }
        $chunks = $this->chunkText($text);
        if ($chunks === []) {
            return response()->json(['message' => 'The uploaded file contains no extractable text.'], 422);
        }
        if (count($chunks) > 5000) {
            return response()->json(['message' => 'The document is too large to index safely.'], 422);
        }
        if (!$this->embeddingsConfigured()) {
            return response()->json([
                'message' => 'OpenRouter embeddings are not fully configured. Set the embeddings API key, model, and dimensions before uploading sources.',
            ], 503);
        }

        $id = (string) Str::uuid();
        $storageKey = $this->storage->store($file, 'ai-notebook');
        $now = now();
        DB::table('ai_notebook_sources')->insert([
            'id' => $id,
            'subject_id' => $subjectId,
            'title' => trim((string) ($input['title'] ?? '')) ?: $file->getClientOriginalName(),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'storage_key' => $storageKey,
            'status' => 'processing',
            'uploaded_by_user_id' => $this->identity($request)['userId'],
            'total_chunks' => count($chunks),
            'completed_chunks' => 0,
            'created_at' => $now,
        ]);

        try {
            $vectorsByChunk = [];
            $modelByChunk = [];
            foreach (array_chunk($chunks, 64) as $batchIndex => $batch) {
                $embedded = $this->embeddings->embed($batch);
                foreach ($embedded['embeddings'] as $index => $vector) {
                    $chunkIndex = $batchIndex * 64 + $index;
                    $vectorsByChunk[$chunkIndex] = $vector;
                    $modelByChunk[$chunkIndex] = $embedded['model'];
                }
            }

            DB::transaction(function () use ($id, $chunks, $vectorsByChunk, $modelByChunk): void {
                foreach ($chunks as $index => $content) {
                    DB::table('ai_notebook_chunks')->insert([
                        'id' => (string) Str::uuid(),
                        'source_id' => $id,
                        'ordinal' => $index,
                        'content' => $content,
                        'embedding' => json_encode($vectorsByChunk[$index], JSON_THROW_ON_ERROR),
                        'embedding_model' => $modelByChunk[$index],
                        'created_at' => now(),
                    ]);
                }
                DB::table('ai_notebook_sources')->where('id', $id)->update([
                    'status' => 'ready',
                    'completed_chunks' => count($chunks),
                ]);
            });
        } catch (RuntimeException $exception) {
            DB::table('ai_notebook_sources')->where('id', $id)->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(
            $this->sourceResponse(DB::table('ai_notebook_sources')->where('id', $id)->first()),
            201
        );
    }

    public function deleteSource(Request $request, string $id): JsonResponse
    {
        $source = DB::table('ai_notebook_sources')->where('id', $id)->first();
        if (!$source) {
            throw new NotFoundHttpException('Notebook source not found.');
        }
        $this->ensureSubjectAccessible($request, $source->subject_id, true);
        $this->storage->delete($source->storage_key);
        DB::table('ai_notebook_sources')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $identity = $this->identity($request);
        $query = DB::table('ai_notebook_chat_sessions')
            ->where('user_id', $identity['userId']);
        if (($identity['role'] ?? null) === 'student') {
            $query->whereExists(function ($access): void {
                $access->select(DB::raw(1))
                    ->from('subject_user_access')
                    ->whereColumn('subject_user_access.subject_id', 'ai_notebook_chat_sessions.subject_id')
                    ->whereColumn('subject_user_access.user_id', 'ai_notebook_chat_sessions.user_id');
            });
        }
        $subjectId = (string) $request->query('subjectId', '');
        if ($subjectId !== '') {
            $this->ensureSubjectAccessible($request, $subjectId);
            $query->where('subject_id', $subjectId);
        }

        return response()->json($query->orderByDesc('updated_at')->get()->map(
            static fn (object $session): array => [
                'id' => $session->id,
                'subjectId' => $session->subject_id,
                'title' => $session->title,
                'createdAt' => $session->created_at,
                'updatedAt' => $session->updated_at,
            ]
        )->all());
    }

    public function chat(Request $request): JsonResponse
    {
        $input = $request->validate([
            'subjectId' => ['required', 'uuid'],
            'question' => ['required', 'string', 'min:1', 'max:4000'],
            'sessionId' => ['sometimes', 'uuid'],
            'studyAidMode' => ['sometimes', 'nullable', 'in:summary,mind_map'],
        ]);
        $identity = $this->identity($request);
        $subjectId = $input['subjectId'];
        $this->ensureSubjectAccessible($request, $subjectId);
        $sessionId = $input['sessionId'] ?? null;
        $session = $sessionId === null
            ? null
            : DB::table('ai_notebook_chat_sessions')
                ->where('id', $sessionId)
                ->where('user_id', $identity['userId'])
                ->first();
        if ($sessionId !== null && (!$session || $session->subject_id !== $subjectId)) {
            throw new NotFoundHttpException('Notebook session not found.');
        }

        $this->recordUsage($identity['userId']);
        $retrieved = $this->retrieveChunks($subjectId, $input['question']);
        $subject = DB::table('subjects')->where('id', $subjectId)->first();
        $defaultPrompt = DB::table('ai_notebook_prompts')->where('subject_id', $subjectId)->first();
        $personalPrompt = DB::table('ai_notebook_user_prompts')
            ->where('subject_id', $subjectId)
            ->where('user_id', $identity['userId'])
            ->first();
        $prompt = $personalPrompt?->prompt ?: ($defaultPrompt?->prompt ?? '');
        $language = $personalPrompt?->response_language
            ?? $defaultPrompt?->response_language
            ?? 'english';
        $instructions = trim(
            'You are a subject-specific learning assistant for '.($subject->name ?? 'the selected subject').'. '.
            'Answer accurately using the supplied source excerpts. If they do not contain the answer, say so plainly. '.
            'Do not invent citations. Respond in '.str_replace('_', ' ', $language).'. '.
            $prompt
        );
        if (($input['studyAidMode'] ?? null) === 'summary') {
            $instructions .= ' Present the response as a concise study summary.';
        } elseif (($input['studyAidMode'] ?? null) === 'mind_map') {
            $instructions .= ' Present a hierarchical mind map in readable text.';
        }

        $context = implode("\n\n", array_map(
            static fn (array $item): string => '[Source: '.$item['title'].', chunk '.($item['ordinal'] + 1)."]\n".$item['content'],
            $retrieved
        ));
        if ($context === '') {
            $context = 'No indexed source excerpt matched the question. Tell the user the subject sources do not currently contain a relevant passage.';
        }

        $history = $session === null
            ? []
            : DB::table('ai_notebook_chat_messages')
                ->where('session_id', $sessionId)
                ->orderByDesc('created_at')
                ->limit(12)
                ->get(['role', 'content'])
                ->reverse()
                ->map(static fn (object $message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                ])->all();

        try {
            $answer = $this->chatClient->complete([
                ['role' => 'system', 'content' => $instructions."\n\nSOURCE EXCERPTS:\n".$context],
                ...$history,
                ['role' => 'user', 'content' => $input['question']],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $sessionId ??= (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($session, $sessionId, $subjectId, $identity, $input, $answer, $retrieved, $now): void {
            if ($session === null) {
                DB::table('ai_notebook_chat_sessions')->insert([
                    'id' => $sessionId,
                    'subject_id' => $subjectId,
                    'user_id' => $identity['userId'],
                    'title' => Str::limit($input['question'], 120, ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('ai_notebook_chat_sessions')->where('id', $sessionId)->update([
                    'updated_at' => $now,
                ]);
            }
            $citations = array_map(static fn (array $item): array => [
                'sourceId' => $item['sourceId'],
                'title' => $item['title'],
                'chunk' => $item['ordinal'] + 1,
            ], $retrieved);
            foreach ([
                ['role' => 'user', 'content' => $input['question'], 'citations' => []],
                ['role' => 'assistant', 'content' => $answer, 'citations' => $citations],
            ] as $message) {
                DB::table('ai_notebook_chat_messages')->insert([
                    'id' => (string) Str::uuid(),
                    'session_id' => $sessionId,
                    'role' => $message['role'],
                    'content' => $message['content'],
                    'citations' => json_encode($message['citations'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
            }
        });

        return response()->json([
            'sessionId' => $sessionId,
            'answer' => $answer,
            'response' => $answer,
            'message' => $answer,
            'content' => $answer,
            'citations' => array_map(static fn (array $item): array => [
                'sourceId' => $item['sourceId'],
                'title' => $item['title'],
                'chunk' => $item['ordinal'] + 1,
            ], $retrieved),
            'studyAid' => ($input['studyAidMode'] ?? null) === null
                ? null
                : ['type' => $input['studyAidMode']],
        ]);
    }

    public function session(Request $request, string $id): JsonResponse
    {
        $session = DB::table('ai_notebook_chat_sessions')
            ->where('id', $id)
            ->where('user_id', $this->identity($request)['userId'])
            ->first();
        if (!$session) {
            throw new NotFoundHttpException('Notebook session not found.');
        }
        $this->ensureSubjectAccessible($request, $session->subject_id);

        $messages = DB::table('ai_notebook_chat_messages')
            ->where('session_id', $id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'id' => $session->id,
            'subjectId' => $session->subject_id,
            'title' => $session->title,
            'createdAt' => $session->created_at,
            'updatedAt' => $session->updated_at,
            'messages' => $messages->map(static fn (object $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'citations' => json_decode($message->citations, true) ?: [],
                'createdAt' => $message->created_at,
            ])->all(),
        ]);
    }

    public function deleteSession(Request $request, string $id): JsonResponse
    {
        $session = DB::table('ai_notebook_chat_sessions')
            ->where('id', $id)
            ->where('user_id', $this->identity($request)['userId'])
            ->first();
        if (!$session) {
            throw new NotFoundHttpException('Notebook session not found.');
        }
        $this->ensureSubjectAccessible($request, $session->subject_id);
        DB::table('ai_notebook_chat_sessions')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    public function prompt(Request $request, string $subjectId): JsonResponse
    {
        $this->ensureSubjectAccessible($request, $subjectId);
        $default = DB::table('ai_notebook_prompts')->where('subject_id', $subjectId)->first();
        $personal = DB::table('ai_notebook_user_prompts')
            ->where('subject_id', $subjectId)
            ->where('user_id', $this->identity($request)['userId'])
            ->first();

        return response()->json([
            'subjectId' => $subjectId,
            'prompt' => $personal?->prompt ?: ($default?->prompt ?? ''),
            'responseLanguage' => $personal?->response_language
                ?? $default?->response_language
                ?? 'english',
            'defaultPrompt' => $default?->prompt ?? '',
            'defaultResponseLanguage' => $default?->response_language ?? 'english',
            'personalPrompt' => $personal?->prompt,
        ]);
    }

    public function savePersonalPrompt(Request $request, string $subjectId): JsonResponse
    {
        $this->ensureSubjectAccessible($request, $subjectId);
        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'responseLanguage' => ['required', 'string', 'in:english,urdu,roman_urdu'],
        ]);
        $userId = $this->identity($request)['userId'];
        $payload = [
            'prompt' => $input['prompt'],
            'response_language' => $input['responseLanguage'],
            'updated_at' => now(),
        ];
        DB::table('ai_notebook_user_prompts')->updateOrInsert(
            ['subject_id' => $subjectId, 'user_id' => $userId],
            ['subject_id' => $subjectId, 'user_id' => $userId, ...$payload]
        );

        return response()->json([
            'subjectId' => $subjectId,
            'prompt' => $payload['prompt'],
            'responseLanguage' => $payload['response_language'],
        ]);
    }

    public function savePrompt(Request $request, string $subjectId): JsonResponse
    {
        $this->ensureSubjectAccessible($request, $subjectId, true);
        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'responseLanguage' => ['sometimes', 'string', 'in:english,urdu,roman_urdu'],
        ]);
        $payload = [
            'subject_id' => $subjectId,
            'prompt' => $input['prompt'],
            'response_language' => $input['responseLanguage'] ?? 'english',
            'updated_at' => now(),
        ];
        DB::table('ai_notebook_prompts')->updateOrInsert(
            ['subject_id' => $subjectId],
            $payload
        );

        return response()->json([
            'subjectId' => $subjectId,
            'prompt' => $payload['prompt'],
            'responseLanguage' => $payload['response_language'],
        ]);
    }

    private function sourceResponse(object $source): array
    {
        $size = 0;
        try {
            $path = $this->storage->resolve($source->storage_key);
            $size = is_file($path) ? (int) filesize($path) : 0;
        } catch (RuntimeException) {
            $size = 0;
        }

        return [
            'id' => $source->id,
            'subjectId' => $source->subject_id,
            'title' => $source->title,
            'originalName' => $source->original_name,
            'mimeType' => $source->mime_type,
            'sizeBytes' => $size,
            'status' => $source->status,
            'errorMessage' => $source->error_message,
            'totalChunks' => (int) $source->total_chunks,
            'completedChunks' => (int) $source->completed_chunks,
            'createdAt' => $source->created_at,
        ];
    }

    private function extractText(string $path, string $extension): string
    {
        if ($extension === 'pdf') {
            $text = (new PdfParser())->parseFile($path)->getText();
        } elseif ($extension === 'docx') {
            $archive = new \ZipArchive();
            if ($archive->open($path) !== true) {
                throw new RuntimeException('The uploaded DOCX file could not be opened.');
            }
            $xml = $archive->getFromName('word/document.xml');
            $archive->close();
            if (!is_string($xml)) {
                throw new RuntimeException('The uploaded DOCX file does not contain document text.');
            }
            $text = html_entity_decode(strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } elseif ($extension === 'txt') {
            $text = file_get_contents($path);
            if (!is_string($text)) {
                throw new RuntimeException('The uploaded text file could not be read.');
            }
        } else {
            throw new RuntimeException('Only PDF, DOCX, and TXT sources are supported.');
        }

        $text = trim(preg_replace('/[ \t]+/', ' ', $text) ?? '');
        if (mb_strlen($text) > 8_000_000) {
            throw new RuntimeException('The extracted document text exceeds the indexing limit.');
        }

        return $text;
    }

    private function chunkText(string $text): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $step = self::CHUNK_LENGTH - self::CHUNK_OVERLAP;
        for ($offset = 0; $offset < $length; $offset += $step) {
            $chunk = trim(mb_substr($text, $offset, self::CHUNK_LENGTH));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    private function retrieveChunks(string $subjectId, string $question): array
    {
        $rows = DB::table('ai_notebook_chunks as chunk')
            ->join('ai_notebook_sources as source', 'source.id', '=', 'chunk.source_id')
            ->where('source.subject_id', $subjectId)
            ->where('source.status', 'ready')
            ->get([
                'chunk.id',
                'chunk.source_id',
                'chunk.ordinal',
                'chunk.content',
                'chunk.embedding',
                'chunk.embedding_model',
                'source.title',
            ]);
        if ($rows->isEmpty()) {
            return [];
        }

        $queryVector = null;
        if ($this->embeddingsConfigured() && $rows->contains(
            static fn (object $row): bool => $row->embedding_model !== 'lexical'
        )) {
            $queryVector = $this->embeddings->embed([$question])['embeddings'][0];
        }
        $terms = array_values(array_unique(preg_split('/[^a-z0-9]+/i', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $ranked = [];
        foreach ($rows as $row) {
            $vector = json_decode($row->embedding, true);
            $score = is_array($queryVector) && is_array($vector) && $vector !== []
                ? $this->cosineSimilarity($queryVector, $vector)
                : $this->lexicalScore($terms, $row->content);
            if ($score > 0) {
                $ranked[] = [
                    'sourceId' => $row->source_id,
                    'title' => $row->title,
                    'ordinal' => (int) $row->ordinal,
                    'content' => $row->content,
                    'score' => $score,
                ];
            }
        }
        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return array_slice($ranked, 0, 5);
    }

    private function cosineSimilarity(array $left, array $right): float
    {
        if (count($left) !== count($right)) {
            return 0.0;
        }
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;
        foreach ($left as $index => $value) {
            if (!is_numeric($value) || !is_numeric($right[$index] ?? null)) {
                return 0.0;
            }
            $a = (float) $value;
            $b = (float) $right[$index];
            $dot += $a * $b;
            $leftNorm += $a * $a;
            $rightNorm += $b * $b;
        }

        return $leftNorm > 0 && $rightNorm > 0 ? $dot / sqrt($leftNorm * $rightNorm) : 0.0;
    }

    private function lexicalScore(array $terms, string $content): float
    {
        if ($terms === []) {
            return 0.0;
        }
        $content = mb_strtolower($content);
        $score = 0.0;
        foreach ($terms as $term) {
            $score += substr_count($content, $term);
        }

        return $score / max(1, count($terms));
    }

    private function recordUsage(string $userId): void
    {
        DB::transaction(function () use ($userId): void {
            DB::table('ai_notebook_daily_usage')->insertOrIgnore([
                'userId' => $userId,
                'usageDate' => now()->toDateString(),
                'requestCount' => 0,
                'updatedAt' => now(),
            ]);
            $usage = DB::table('ai_notebook_daily_usage')->where('userId', $userId)->lockForUpdate()->first();
            if ($usage->usageDate !== now()->toDateString()) {
                DB::table('ai_notebook_daily_usage')->where('userId', $userId)->update([
                    'usageDate' => now()->toDateString(),
                    'requestCount' => 1,
                    'updatedAt' => now(),
                ]);
            } elseif ((int) $usage->requestCount >= self::DAILY_REQUEST_LIMIT) {
                abort(429, 'The daily AI Notebook request limit has been reached.');
            } else {
                DB::table('ai_notebook_daily_usage')->where('userId', $userId)->update([
                    'requestCount' => (int) $usage->requestCount + 1,
                    'updatedAt' => now(),
                ]);
            }
        });
    }

    private function embeddingsConfigured(): bool
    {
        return str_starts_with((string) config('services.embeddings.url'), 'https://') &&
            (string) config('services.embeddings.key') !== '' &&
            (string) config('services.embeddings.model') !== '' &&
            (int) config('services.embeddings.dimensions') > 0;
    }

    private function identity(Request $request): array
    {
        return (array) $request->attributes->get('apiIdentity');
    }

    private function canManageSources(array $identity): bool
    {
        return ($identity['role'] ?? null) === 'admin' ||
            in_array('admin', $identity['adminPermissions'] ?? [], true) ||
            in_array('teacher_assistant', $identity['adminPermissions'] ?? [], true);
    }

    private function ensureSubjectAccessible(Request $request, string $subjectId, bool $requireManager = false): void
    {
        if (!Str::isUuid($subjectId)) {
            abort(400, 'Invalid subject identifier.');
        }
        if (!DB::table('subjects')->where('id', $subjectId)->exists()) {
            throw new NotFoundHttpException('Subject not found.');
        }
        $identity = $this->identity($request);
        if ($requireManager && !$this->canManageSources($identity)) {
            throw new AccessDeniedHttpException('Notebook management permission is required.');
        }
        if (!$this->canManageSources($identity) &&
            ($identity['role'] ?? null) === 'student' &&
            !DB::table('subject_user_access')
                ->where('subject_id', $subjectId)
                ->where('user_id', $identity['userId'])
                ->exists()) {
            throw new AccessDeniedHttpException('You do not have access to this subject.');
        }
    }
}
