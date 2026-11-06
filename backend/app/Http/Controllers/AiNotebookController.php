<?php

namespace App\Http\Controllers;

use App\Services\CloudChatClient;
use App\Services\CloudEmbeddingClient;
use App\Services\ImageCompactor;
use App\Services\PrivateFileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Carbon\Carbon;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AiNotebookController
{
    private const CHUNK_LENGTH = 1600;
    private const CHUNK_OVERLAP = 240;
    private const DEFAULT_DAILY_REQUEST_LIMIT = 15;
    private const MAX_SUBJECT_CHUNKS = 3000;
    private const CHAT_TICKET_TTL_SECONDS = 120;
    private const CHAT_PROCESSING_STALE_SECONDS = 360;

    public function __construct(
        private readonly PrivateFileStorage $storage,
        private readonly CloudEmbeddingClient $embeddings,
        private readonly CloudChatClient $chatClient,
        private readonly ImageCompactor $imageCompactor
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

    public function sourceChunks(Request $request, string $sourceId): JsonResponse
    {
        $input = $request->validate([
            'afterOrdinal' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        $source = DB::table('ai_notebook_sources')->where('id', $sourceId)->first();
        if (!$source) {
            throw new NotFoundHttpException('Notebook source not found.');
        }
        $this->ensureSubjectAccessible($request, $source->subject_id, true);
        if ($source->status !== 'ready') {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Notebook source is not ready for caching.');
        }
        $limit = min(200, (int) ($input['limit'] ?? 200));
        $rows = DB::table('ai_notebook_chunks')
            ->where('source_id', $sourceId)
            ->where('ordinal', '>=', (int) ($input['afterOrdinal'] ?? 0))
            ->orderBy('ordinal')
            ->limit($limit + 1)
            ->get(['id', 'ordinal', 'content']);
        $hasMore = $rows->count() > $limit;
        $chunks = $rows->take($limit)->map(static fn (object $chunk): array => [
            'id' => $chunk->id,
            'ordinal' => (int) $chunk->ordinal,
            'content' => $chunk->content,
        ])->all();

        return response()->json([
            'sourceId' => $sourceId,
            'subjectId' => $source->subject_id,
            'title' => $source->title,
            'createdAt' => $source->created_at,
            'totalChunks' => (int) $source->total_chunks,
            'chunks' => $chunks,
            'nextOrdinal' => $hasMore ? $chunks[count($chunks) - 1]['ordinal'] + 1 : null,
        ]);
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
        $existingChunks = (int) DB::table('ai_notebook_chunks as chunk')
            ->join('ai_notebook_sources as source', 'source.id', '=', 'chunk.source_id')
            ->where('source.subject_id', $subjectId)
            ->count();
        if ($existingChunks + count($chunks) > self::MAX_SUBJECT_CHUNKS) {
            return response()->json([
                'message' => 'This subject has reached the safe Notebook indexing limit. An administrator can remove unused sources before adding more material.',
            ], 422);
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

    public function context(Request $request): JsonResponse
    {
        $input = $request->validate([
            'subjectId' => ['required', 'uuid'],
            'question' => ['required', 'string', 'max:4000'],
            'sessionId' => ['sometimes', 'nullable', 'uuid'],
            'studyAidMode' => ['sometimes', 'nullable', 'in:summary,mind_map'],
        ]);
        $identity = $this->identity($request);
        $userId = (string) $identity['userId'];
        $subjectId = (string) $input['subjectId'];
        $this->ensureSubjectAccessible($request, $subjectId);

        $usage = ($identity['role'] ?? null) === 'student'
            ? $this->reserveUsage($userId)
            : null;

        $sessionId = $input['sessionId'] ?? null;
        $session = $sessionId === null
            ? null
            : DB::table('ai_notebook_chat_sessions')
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->where('subject_id', $subjectId)
                ->first();
        if ($sessionId !== null && !$session) {
            $sessionId = null;
        }

        $userQuestion = trim((string) $input['question']);
        $retrieved = $this->retrieveChunks($subjectId, $userQuestion);
        $subject = DB::table('subjects')->where('id', $subjectId)->first();
        $defaultPrompt = DB::table('ai_notebook_prompts')->where('subject_id', $subjectId)->first();
        $personalPrompt = DB::table('ai_notebook_user_prompts')
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
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
            $instructions .= ' Create a concise, well-organized study summary using only source excerpts, with a main idea, key concepts, and short recap.';
        } elseif (($input['studyAidMode'] ?? null) === 'mind_map') {
            $instructions .= ' Create a visual study mind map using only source excerpts. Return raw JSON only with exactly {"title":"short topic title","branches":[{"label":"main idea","children":[{"label":"supporting idea","children":[]}]}]}. Each node has a concise string label and children array. Use 2-12 top-level branches, up to 8 children per node, and no more than 96 nodes. Do not invent unsupported facts.';
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
                    'content' => preg_replace('/\s*<!--nexus-study-aid:[A-Za-z0-9+\/=]+-->/', '', $message->content) ?? $message->content,
                ])->all();

        $citations = array_map(static fn (array $item): array => [
            'sourceId' => $item['sourceId'],
            'title' => $item['title'],
            'chunk' => $item['ordinal'] + 1,
        ], $retrieved);

        return response()->json([
            'instructions' => $instructions,
            'sourceContext' => $context,
            'history' => $history,
            'sessionId' => $sessionId ?? (string) Str::uuid(),
            'citations' => $citations,
            'model' => config('services.ai_chat.model'),
            'usageDate' => $usage['date'] ?? null,
        ]);
    }

    public function recordMessage(Request $request): JsonResponse
    {
        $input = $request->validate([
            'subjectId' => ['required', 'uuid'],
            'sessionId' => ['required', 'uuid'],
            'userQuestion' => ['required', 'string'],
            'answer' => ['required', 'string'],
            'citations' => ['sometimes', 'array'],
            'studyAid' => ['sometimes', 'nullable', 'array'],
        ]);
        $identity = $this->identity($request);
        $userId = (string) $identity['userId'];
        $subjectId = (string) $input['subjectId'];
        $sessionId = (string) $input['sessionId'];
        $userQuestion = (string) $input['userQuestion'];
        $answer = (string) $input['answer'];
        $storedAnswer = $answer;
        if (($input['studyAid']['type'] ?? null) === 'mind_map') {
            $storedAnswer .= "\n\n<!--nexus-study-aid:".base64_encode(json_encode($input['studyAid'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).'-->';
        }

        $now = now();
        DB::transaction(function () use ($sessionId, $subjectId, $userId, $userQuestion, $storedAnswer, $now): void {
            $session = DB::table('ai_notebook_chat_sessions')
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->first();
            if ($session === null) {
                DB::table('ai_notebook_chat_sessions')->insert([
                    'id' => $sessionId,
                    'subject_id' => $subjectId,
                    'user_id' => $userId,
                    'title' => Str::limit($userQuestion, 120, ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('ai_notebook_chat_sessions')->where('id', $sessionId)->update([
                    'updated_at' => $now,
                ]);
            }

            DB::table('ai_notebook_chat_messages')->insert([
                [
                    'id' => (string) Str::uuid(),
                    'session_id' => $sessionId,
                    'role' => 'user',
                    'content' => $userQuestion,
                    'created_at' => $now,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'session_id' => $sessionId,
                    'role' => 'assistant',
                    'content' => $storedAnswer,
                    'created_at' => $now,
                ],
            ]);
        });

        return response()->json(['saved' => true]);
    }

    public function chat(Request $request): JsonResponse
    {
        $input = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'subjectId' => ['sometimes', 'uuid'],
            'question' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'sessionId' => ['sometimes', 'uuid'],
            'studyAidMode' => ['sometimes', 'nullable', 'in:summary,mind_map'],
            'image' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);
        $identity = $this->identity($request);
        $userId = (string) $identity['userId'];
        $clientRequestId = strtolower((string) $input['clientRequestId']);
        $imageFile = $request->file('image');
        $this->expireIdleChatTickets();

        $ticket = DB::table('ai_notebook_chat_tickets')
            ->where('user_id', $userId)
            ->where('client_request_id', $clientRequestId)
            ->first();
        $newImageKey = null;
        if ($ticket === null) {
            if (!isset($input['subjectId'])) {
                abort(422, 'Start a Notebook question before checking its status.');
            }
            $subjectId = (string) $input['subjectId'];
            $this->ensureSubjectAccessible($request, $subjectId);
            $question = trim((string) ($input['question'] ?? ''));
            if ($question === '' && !$imageFile) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Enter a question or attach a photo.');
            }
            if (!empty($input['studyAidMode']) && $imageFile) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Remove the attached photo before requesting a study aid.');
            }
            $sessionId = $input['sessionId'] ?? null;
            if ($sessionId !== null) {
                $session = DB::table('ai_notebook_chat_sessions')
                    ->where('id', $sessionId)
                    ->where('user_id', $userId)
                    ->where('subject_id', $subjectId)
                    ->first();
                if (!$session) {
                    throw new NotFoundHttpException('Notebook session not found.');
                }
            }

            $userQuestion = $question !== ''
                ? $question
                : 'Analyze the attached photo using relevant information from this subject notebook.';
            if ($imageFile) {
                $newImageKey = $this->storage->store($imageFile, 'ai-notebook-chat');
            }
            try {
                $created = DB::transaction(function () use (
                    $identity,
                    $userId,
                    $clientRequestId,
                    $subjectId,
                    $sessionId,
                    $userQuestion,
                    $input,
                    $newImageKey
                ): array {
                    $existing = DB::table('ai_notebook_chat_tickets')
                        ->where('user_id', $userId)
                        ->where('client_request_id', $clientRequestId)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return ['ticket' => $existing, 'created' => false];
                    }

                    // Reserve at admission so concurrent queued questions cannot
                    // overshoot the student's daily limit.
                    $usage = ($identity['role'] ?? null) === 'student'
                        ? $this->reserveUsage($userId)
                        : null;
                    $now = now();
                    $id = (string) Str::uuid();
                    DB::table('ai_notebook_chat_tickets')->insert([
                        'id' => $id,
                        'user_id' => $userId,
                        'client_request_id' => $clientRequestId,
                        'subject_id' => $subjectId,
                        'session_id' => $sessionId,
                        'question' => $userQuestion,
                        'study_aid_mode' => $input['studyAidMode'] ?? null,
                        'image_storage_key' => $newImageKey,
                        'usage_date' => $usage['date'] ?? null,
                        'usage_reserved' => $usage !== null,
                        'status' => 'pending',
                        'response_json' => null,
                        'queued_at' => $now->format('Y-m-d H:i:s.u'),
                        'last_checked_at' => $now,
                        'processing_at' => null,
                        'completed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return [
                        'ticket' => DB::table('ai_notebook_chat_tickets')->where('id', $id)->first(),
                        'created' => true,
                    ];
                });
            } catch (QueryException $exception) {
                $this->deletePrivateFileQuietly($newImageKey);
                $ticket = DB::table('ai_notebook_chat_tickets')
                    ->where('user_id', $userId)
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if (!$ticket) {
                    throw $exception;
                }
                $created = ['ticket' => $ticket, 'created' => false];
            } catch (\Throwable $exception) {
                $this->deletePrivateFileQuietly($newImageKey);
                throw $exception;
            }
            $ticket = $created['ticket'];
            if (!$created['created'] && $newImageKey !== null) {
                $this->deletePrivateFileQuietly($newImageKey);
            }
        } else {
            $this->ensureSubjectAccessible($request, $ticket->subject_id);
            $ticket = DB::transaction(function () use ($ticket, $userId, $identity): object {
                $locked = DB::table('ai_notebook_chat_tickets')
                    ->where('id', $ticket->id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (!$locked) {
                    throw new NotFoundHttpException('Notebook request not found.');
                }
                if ($locked->status === 'expired') {
                    $usage = ($identity['role'] ?? null) === 'student'
                        ? $this->reserveUsage($userId)
                        : null;
                    $now = now();
                    DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->update([
                        'status' => 'pending',
                        'usage_date' => $usage['date'] ?? null,
                        'usage_reserved' => $usage !== null,
                        'queued_at' => $now->format('Y-m-d H:i:s.u'),
                        'last_checked_at' => $now,
                        'processing_at' => null,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->update([
                        'last_checked_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->first();
            });
        }

        if ($ticket->status === 'completed') {
            return $this->completedChatResponse($ticket);
        }

        $slot = null;
        try {
            $claim = DB::transaction(function () use ($ticket, $identity, $userId, &$slot): array {
                $locked = DB::table('ai_notebook_chat_tickets')
                    ->where('id', $ticket->id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (!$locked || $locked->status === 'completed') {
                    return ['ticket' => $locked, 'process' => false];
                }
                if ($locked->status === 'processing' &&
                    $locked->processing_at !== null &&
                    Carbon::parse($locked->processing_at)->lessThanOrEqualTo(now()->subSeconds(self::CHAT_PROCESSING_STALE_SECONDS))) {
                    if ((bool) $locked->usage_reserved && $locked->usage_date !== null) {
                        $this->refundUsage($userId, $locked->usage_date);
                    }
                    DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->update([
                        'status' => 'pending',
                        'usage_reserved' => false,
                        'processing_at' => null,
                        'last_checked_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $locked = DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->first();
                }
                if ($locked->status !== 'pending') {
                    return ['ticket' => $locked, 'process' => false];
                }

                $head = DB::table('ai_notebook_chat_tickets')
                    ->where('status', 'pending')
                    ->where('last_checked_at', '>', now()->subSeconds(self::CHAT_TICKET_TTL_SECONDS))
                    ->orderBy('queued_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if (!$head || $head->id !== $locked->id) {
                    return ['ticket' => $locked, 'process' => false];
                }

                $slot = $this->storage->acquireChatSlot();
                if ($slot === null) {
                    return ['ticket' => $locked, 'process' => false];
                }
                if (($identity['role'] ?? null) === 'student' && !(bool) $locked->usage_reserved) {
                    $usage = $this->reserveUsage($userId);
                    DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->update([
                        'usage_date' => $usage['date'],
                        'usage_reserved' => true,
                    ]);
                }
                DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->update([
                    'status' => 'processing',
                    'processing_at' => now(),
                    'updated_at' => now(),
                ]);

                return [
                    'ticket' => DB::table('ai_notebook_chat_tickets')->where('id', $locked->id)->first(),
                    'process' => true,
                ];
            });
        } catch (\Throwable $exception) {
            $this->storage->releaseChatSlot($slot);
            throw $exception;
        }

        if (!$claim['process']) {
            $this->storage->releaseChatSlot($slot);
            if (($claim['ticket']->status ?? null) === 'completed') {
                return $this->completedChatResponse($claim['ticket']);
            }

            return $this->waitingChatResponse();
        }

        try {
            return $this->answerChatTicket($claim['ticket'], $identity);
        } catch (\Throwable $exception) {
            $this->refundAndRequeueChatTicket($claim['ticket']->id, $userId);

            return $this->waitingChatResponse();
        } finally {
            $this->storage->releaseChatSlot($slot);
        }
    }

    private function answerChatTicket(object $ticket, array $identity): JsonResponse
    {
        $subjectId = $ticket->subject_id;
        $sessionId = $ticket->session_id;
        $session = $sessionId === null
            ? null
            : DB::table('ai_notebook_chat_sessions')
                ->where('id', $sessionId)
                ->where('user_id', $identity['userId'])
                ->where('subject_id', $subjectId)
                ->first();
        if ($sessionId !== null && !$session) {
            $sessionId = null;
        }

        $image = null;
        if ($ticket->image_storage_key !== null) {
            $imagePath = $this->storage->resolve($ticket->image_storage_key);
            $imageBytes = file_get_contents($imagePath);
            if (!is_string($imageBytes)) {
                throw new RuntimeException('The attached photo could not be read.');
            }
            $image = $this->imageCompactor->compact($imageBytes);
        }

        $userQuestion = $ticket->question;
        $retrieved = $this->retrieveChunks($subjectId, $userQuestion);
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
        if ($ticket->study_aid_mode === 'summary') {
            $instructions .= ' Create a concise, well-organized study summary using only source excerpts, with a main idea, key concepts, and short recap.';
        } elseif ($ticket->study_aid_mode === 'mind_map') {
            $instructions .= ' Create a visual study mind map using only source excerpts. Return raw JSON only with exactly {"title":"short topic title","branches":[{"label":"main idea","children":[{"label":"supporting idea","children":[]}]}]}. Each node has a concise string label and children array. Use 2-12 top-level branches, up to 8 children per node, and no more than 96 nodes. Do not invent unsupported facts.';
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
                    'content' => preg_replace('/\s*<!--nexus-study-aid:[A-Za-z0-9+\/=]+-->/', '', $message->content) ?? $message->content,
                ])->all();

        $answer = $this->chatClient->complete([
                ['role' => 'system', 'content' => $instructions."\n\nSOURCE EXCERPTS:\n".$context],
                ...$history,
                ['role' => 'user', 'content' => $image === null
                    ? $userQuestion
                    : [
                        ['type' => 'text', 'text' => $userQuestion],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$image['mimeType'].';base64,'.base64_encode($image['buffer'])]],
                    ]],
            ], $ticket->study_aid_mode === 'mind_map', (int) config('services.ai_chat.notebook_timeout', 60));
        $studyAid = null;
        if ($ticket->study_aid_mode === 'mind_map') {
            $studyAid = $this->parseMindMap($answer);
            $answer = 'Mind map: '.$studyAid['title'];
        } elseif ($ticket->study_aid_mode === 'summary') {
            $studyAid = ['type' => 'summary'];
        }

        $sessionId ??= (string) Str::uuid();
        $now = now();
        $storedAnswer = $answer;
        if (($studyAid['type'] ?? null) === 'mind_map') {
            $storedAnswer .= "\n\n<!--nexus-study-aid:".base64_encode(json_encode($studyAid, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)).'-->';
        }
        $citations = array_map(static fn (array $item): array => [
            'sourceId' => $item['sourceId'],
            'title' => $item['title'],
            'chunk' => $item['ordinal'] + 1,
        ], $retrieved);
        $responseData = [
            'sessionId' => $sessionId,
            'answer' => $answer,
            'response' => $answer,
            'message' => $answer,
            'content' => $answer,
            'citations' => $citations,
            'studyAid' => $studyAid,
        ];
        DB::transaction(function () use ($session, $sessionId, $subjectId, $identity, $userQuestion, $storedAnswer, $citations, $now, $image, $ticket, $responseData): void {
            if ($session === null) {
                DB::table('ai_notebook_chat_sessions')->insert([
                    'id' => $sessionId,
                    'subject_id' => $subjectId,
                    'user_id' => $identity['userId'],
                    'title' => Str::limit($userQuestion, 120, ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('ai_notebook_chat_sessions')->where('id', $sessionId)->update([
                    'updated_at' => $now,
                ]);
            }
            foreach ([
                ['role' => 'user', 'content' => $userQuestion.($image === null ? '' : "\n[Photo attached]"), 'citations' => []],
                ['role' => 'assistant', 'content' => $storedAnswer, 'citations' => $citations],
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
            $updated = DB::table('ai_notebook_chat_tickets')
                ->where('id', $ticket->id)
                ->where('user_id', $identity['userId'])
                ->where('status', 'processing')
                ->update([
                    'status' => 'completed',
                    'response_json' => json_encode($responseData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'usage_reserved' => false,
                    'processing_at' => null,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Notebook response could not be saved.');
            }
        });

        if ($ticket->image_storage_key !== null && $this->deletePrivateFileQuietly($ticket->image_storage_key)) {
            DB::table('ai_notebook_chat_tickets')->where('id', $ticket->id)->update([
                'image_storage_key' => null,
                'updated_at' => now(),
            ]);
        }

        return response()->json($responseData);
    }

    private function refundAndRequeueChatTicket(string $ticketId, string $userId): void
    {
        try {
            DB::transaction(function () use ($ticketId, $userId): void {
                $ticket = DB::table('ai_notebook_chat_tickets')
                    ->where('id', $ticketId)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if (!$ticket || $ticket->status !== 'processing') {
                    return;
                }
                if ((bool) $ticket->usage_reserved && $ticket->usage_date !== null) {
                    $this->refundUsage($userId, $ticket->usage_date);
                }
                DB::table('ai_notebook_chat_tickets')->where('id', $ticketId)->update([
                    'status' => 'pending',
                    'usage_reserved' => false,
                    'processing_at' => null,
                    'last_checked_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable) {
            // Keep the original question private and let a later idempotent retry
            // recover a transient persistence failure without logging user data.
        }
    }

    private function expireIdleChatTickets(): void
    {
        $cutoff = now()->subSeconds(self::CHAT_TICKET_TTL_SECONDS);
        $staleTickets = DB::table('ai_notebook_chat_tickets')
            ->where('status', 'pending')
            ->where('last_checked_at', '<=', $cutoff)
            ->orderBy('last_checked_at')
            ->limit(100)
            ->get(['id', 'user_id', 'usage_date', 'usage_reserved']);

        foreach ($staleTickets as $stale) {
            DB::transaction(function () use ($stale, $cutoff): void {
                $ticket = DB::table('ai_notebook_chat_tickets')
                    ->where('id', $stale->id)
                    ->where('status', 'pending')
                    ->where('last_checked_at', '<=', $cutoff)
                    ->lockForUpdate()
                    ->first();
                if (!$ticket) {
                    return;
                }
                if ((bool) $ticket->usage_reserved && $ticket->usage_date !== null) {
                    $this->refundUsage($ticket->user_id, $ticket->usage_date);
                }
                DB::table('ai_notebook_chat_tickets')->where('id', $ticket->id)->update([
                    'status' => 'expired',
                    'usage_reserved' => false,
                    'processing_at' => null,
                    'updated_at' => now(),
                ]);
            });
        }
    }

    private function completedChatResponse(object $ticket): JsonResponse
    {
        $payload = json_decode((string) $ticket->response_json, true);

        return is_array($payload)
            ? response()->json($payload)
            : $this->waitingChatResponse();
    }

    private function waitingChatResponse(): JsonResponse
    {
        // This payload is intentionally free of queue position, count, ordinal,
        // depth, or "ahead" information.
        return response()->json(['retryAfterSeconds' => 4], 202);
    }

    private function deletePrivateFileQuietly(?string $key): bool
    {
        if ($key === null) {
            return true;
        }
        try {
            $this->storage->delete($key);

            return true;
        } catch (\Throwable) {
            // Private file cleanup is best effort; never log question/photo data.

            return false;
        }
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
            'messages' => $messages->map(fn (object $message): array => $this->messageResponse($message))->all(),
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
            'defaultLanguage' => $default?->response_language ?? 'english',
            'userPrompt' => $personal?->prompt ?: null,
            'userLanguage' => $personal?->response_language,
            'usesDefault' => $personal === null,
        ]);
    }

    public function savePersonalPrompt(Request $request, string $subjectId): JsonResponse
    {
        $this->ensureSubjectAccessible($request, $subjectId);
        $input = $request->validate([
            'prompt' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'responseLanguage' => ['sometimes', 'nullable', 'string', 'in:english,urdu,roman_urdu'],
        ]);
        $userId = $this->identity($request)['userId'];
        $existing = DB::table('ai_notebook_user_prompts')
            ->where('subject_id', $subjectId)
            ->where('user_id', $userId)
            ->first();
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '' && empty($input['responseLanguage'])) {
            DB::table('ai_notebook_user_prompts')
                ->where('subject_id', $subjectId)
                ->where('user_id', $userId)
                ->delete();

            return $this->prompt($request, $subjectId);
        }
        $payload = [
            'prompt' => array_key_exists('prompt', $input)
                ? $prompt
                : ($existing->prompt ?? ''),
            'response_language' => $input['responseLanguage'] ?? $existing->response_language ?? 'english',
            'updated_at' => now(),
        ];
        DB::table('ai_notebook_user_prompts')->updateOrInsert(
            ['subject_id' => $subjectId, 'user_id' => $userId],
            ['subject_id' => $subjectId, 'user_id' => $userId, ...$payload]
        );

        return $this->prompt($request, $subjectId);
    }

    public function resetPersonalPrompt(Request $request, string $subjectId): JsonResponse
    {
        $this->ensureSubjectAccessible($request, $subjectId);
        DB::table('ai_notebook_user_prompts')
            ->where('subject_id', $subjectId)
            ->where('user_id', $this->identity($request)['userId'])
            ->delete();

        return $this->prompt($request, $subjectId);
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

    private function parseMindMap(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('The notebook could not create a valid study mind map. Please try again.');
        }
        try {
            $decoded = json_decode(substr($response, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('The notebook could not create a valid study mind map. Please try again.', previous: $error);
        }
        if (!is_array($decoded) || !is_string($decoded['title'] ?? null) ||
            trim($decoded['title']) === '' || !is_array($decoded['branches'] ?? null) ||
            count($decoded['branches']) < 2 || count($decoded['branches']) > 12) {
            throw new RuntimeException('The notebook returned an incomplete study mind map.');
        }
        $count = 0;
        $branches = $this->validateMindMapNodes($decoded['branches'], 0, $count);

        return [
            'type' => 'mind_map',
            'title' => mb_substr(trim($decoded['title']), 0, 120),
            'branches' => $branches,
        ];
    }

    private function validateMindMapNodes(array $nodes, int $depth, int &$count): array
    {
        if (($depth > 4 && $nodes !== []) || count($nodes) > 12) {
            throw new RuntimeException('The notebook returned a study mind map that is too large.');
        }
        $validated = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['label'] ?? null) ||
                trim($node['label']) === '' || !is_array($node['children'] ?? null) ||
                count($node['children']) > 8 || ++$count > 96) {
                throw new RuntimeException('The notebook returned an invalid study mind map.');
            }
            $validated[] = [
                'label' => mb_substr(trim($node['label']), 0, 120),
                'children' => $this->validateMindMapNodes($node['children'], $depth + 1, $count),
            ];
        }

        return $validated;
    }

    private function messageResponse(object $message): array
    {
        $content = (string) $message->content;
        $studyAid = null;
        if (preg_match('/\s*<!--nexus-study-aid:([A-Za-z0-9+\/=]+)-->\s*$/', $content, $marker) === 1) {
            $json = base64_decode($marker[1], true);
            $decoded = is_string($json) ? json_decode($json, true) : null;
            if (is_array($decoded) && ($decoded['type'] ?? null) === 'mind_map') {
                $studyAid = $decoded;
                $content = preg_replace('/\s*<!--nexus-study-aid:[A-Za-z0-9+\/=]+-->\s*$/', '', $content) ?? $content;
            }
        }

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $content,
            'citations' => json_decode($message->citations, true) ?: [],
            'createdAt' => $message->created_at,
            ...($studyAid === null ? [] : ['studyAid' => $studyAid]),
        ];
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
            try {
                $normalizedQuestion = preg_replace('/\s+/u', ' ', mb_strtolower(trim($question))) ?? trim($question);
                $cacheKey = 'ai-notebook:query-embedding:'.hash('sha256', implode('|', [
                    (string) config('services.embeddings.model'),
                    (string) config('services.embeddings.dimensions'),
                    $normalizedQuestion,
                ]));
                $queryVector = Cache::remember(
                    $cacheKey,
                    now()->addMinutes(10),
                    fn (): array => $this->embeddings->embed([$question])['embeddings'][0]
                );
            } catch (\Throwable $error) {
                Log::warning('AI Notebook query embedding failed; using lexical retrieval.', [
                    'exception' => $error::class,
                ]);
            }
        }
        $contentById = [];
        if (!is_array($queryVector)) {
            $contentById = DB::table('ai_notebook_chunks')
                ->whereIn('id', $rows->pluck('id'))
                ->pluck('content', 'id')
                ->all();
        }
        $terms = array_values(array_unique(preg_split('/[^a-z0-9]+/i', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $ranked = [];
        foreach ($rows as $row) {
            $vector = json_decode($row->embedding, true);
            $score = is_array($queryVector) && is_array($vector) && $vector !== []
                ? $this->cosineSimilarity($queryVector, $vector)
                : $this->lexicalScore($terms, (string) ($contentById[$row->id] ?? ''));
            if ($score > 0) {
                $ranked[] = [
                    'id' => $row->id,
                    'sourceId' => $row->source_id,
                    'title' => $row->title,
                    'ordinal' => (int) $row->ordinal,
                    'content' => '',
                    'score' => $score,
                ];
            }
        }
        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $top = array_slice($ranked, 0, 5);
        if ($top === []) {
            return [];
        }
        $contents = is_array($queryVector)
            ? DB::table('ai_notebook_chunks')
                ->whereIn('id', array_column($top, 'id'))
                ->pluck('content', 'id')
            : collect($contentById);
        foreach ($top as &$item) {
            $item['content'] = (string) ($contents[$item['id']] ?? '');
            unset($item['id'], $item['score']);
        }
        unset($item);

        return $top;
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

    private function reserveUsage(string $userId): array
    {
        $date = Carbon::now((string) config('services.ai_notebook.day_reset_timezone', 'Asia/Karachi'))->toDateString();
        $limit = (int) (DB::table('ai_grading_settings')->where('id', 1)->value('notebook_daily_question_limit')
            ?? self::DEFAULT_DAILY_REQUEST_LIMIT);

        return DB::transaction(function () use ($userId, $date, $limit): array {
            DB::table('ai_notebook_daily_usage')->insertOrIgnore([
                'userId' => $userId,
                'usageDate' => $date,
                'requestCount' => 0,
                'updatedAt' => now(),
            ]);
            $usage = DB::table('ai_notebook_daily_usage')->where('userId', $userId)->lockForUpdate()->first();
            if ($usage->usageDate !== $date) {
                DB::table('ai_notebook_daily_usage')->where('userId', $userId)->update([
                    'usageDate' => $date,
                    'requestCount' => 1,
                    'updatedAt' => now(),
                ]);
            } elseif ((int) $usage->requestCount >= $limit) {
                $questionLabel = $limit === 1 ? 'question' : 'questions';
                abort(429, "You've used all {$limit} AI Notebook {$questionLabel} for today. Your allowance resets at midnight.");
            } else {
                DB::table('ai_notebook_daily_usage')->where('userId', $userId)->update([
                    'requestCount' => (int) $usage->requestCount + 1,
                    'updatedAt' => now(),
                ]);
            }

            return ['date' => $date];
        });
    }

    private function refundUsage(string $userId, string $date): void
    {
        DB::table('ai_notebook_daily_usage')
            ->where('userId', $userId)
            ->where('usageDate', $date)
            ->where('requestCount', '>', 0)
            ->decrement('requestCount');
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
