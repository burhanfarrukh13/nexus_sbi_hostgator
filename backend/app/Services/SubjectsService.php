<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SubjectsService
{
    public function __construct(
        private readonly SubjectAccessService $access,
        private readonly PrivateFileStorage $storage
    ) {
    }

    public function list(?string $userId, bool $isAdmin): array
    {
        $query = DB::table('subjects');
        if ($userId !== null) {
            $this->access->constrainSubjects($query, $userId);
        }

        return $query
            ->orderBy('name')
            ->get([
                'subjects.id',
                'subjects.code',
                'subjects.name',
                'subjects.created_at',
                'subjects.updated_at',
            ])
            ->map(static fn (object $subject): array => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'createdAt' => $subject->created_at,
                'updatedAt' => $subject->updated_at,
            ])->all();
    }

    public function create(array $data, string $actorId): array
    {
        $code = trim((string) $data['code']);
        if (DB::table('subjects')->where('code', $code)->exists()) {
            throw new BadRequestHttpException('A subject with code "'.$code.'" already exists.');
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::table('subjects')->insert([
            'id' => $id,
            'code' => $code,
            'name' => trim((string) $data['name']),
            'created_by_user_id' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'notebook_subject_created',
            'details' => trim((string) $data['name']),
            'metadata' => json_encode(['actorId' => $actorId, 'subjectId' => $id], JSON_THROW_ON_ERROR),
            'createdAt' => $now,
        ]);

        return DB::table('subjects')->where('id', $id)->first()
            ? [
                'id' => $id,
                'code' => $code,
                'name' => trim((string) $data['name']),
                'createdAt' => $now,
                'updatedAt' => $now,
            ]
            : throw new \RuntimeException('The notebook subject could not be created.');
    }

    public function update(string $id, array $data, ?string $actorId = null): array
    {
        if ($actorId !== null) {
            $this->access->assertSubjectAccessible($actorId, $id);
        }
        $subject = DB::table('subjects')->where('id', $id)->first();
        if (!$subject) {
            throw new NotFoundHttpException('Subject not found.');
        }

        $updates = [];
        foreach (['name', 'code'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = trim((string) $data[$field]);
            }
        }
        if (isset($updates['code']) && DB::table('subjects')
            ->where('code', $updates['code'])
            ->where('id', '!=', $id)
            ->exists()) {
            throw new BadRequestHttpException('A subject with code "'.$updates['code'].'" already exists.');
        }

        if ($updates !== []) {
            $updates['updated_at'] = now();
            DB::table('subjects')->where('id', $id)->update($updates);
            if ($actorId !== null && isset($updates['name'])) {
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(),
                    'event' => 'notebook_subject_renamed',
                    'details' => $updates['name'],
                    'userId' => $actorId,
                    'metadata' => json_encode([
                        'actorId' => $actorId,
                        'subjectId' => $id,
                        'previousName' => $subject->name,
                        'name' => $updates['name'],
                    ], JSON_THROW_ON_ERROR),
                    'createdAt' => now(),
                ]);
            }
        }

        $updated = DB::table('subjects')->where('id', $id)->first();

        return [
            'id' => $updated->id,
            'code' => $updated->code,
            'name' => $updated->name,
            'createdAt' => $updated->created_at,
            'updatedAt' => $updated->updated_at,
        ];
    }

    public function delete(string $id, string $actorId): void
    {
        $this->access->assertSubjectAccessible($actorId, $id);
        DB::transaction(function () use ($id, $actorId): void {
            $subject = DB::table('subjects')->where('id', $id)->lockForUpdate()->first();
            if (!$subject) {
                throw new NotFoundHttpException('Subject not found.');
            }
            $fileKeys = DB::table('ai_notebook_sources')->where('subject_id', $id)->pluck('storage_key')->all();
            $testIds = DB::table('tests')->where('subject_id', $id)->pluck('id');
            if ($testIds->isNotEmpty()) {
                $attemptIds = DB::table('test_attempts')->whereIn('testId', $testIds)->pluck('id');
                $fileKeys = array_merge($fileKeys, DB::table('test_source_files')
                    ->whereIn('test_id', $testIds)->pluck('storage_key')->all());
                if ($attemptIds->isNotEmpty()) {
                    $fileKeys = array_merge($fileKeys, DB::table('test_attempt_files')
                        ->whereIn('attempt_id', $attemptIds)->pluck('storage_key')->all());
                }
            }
            if (Schema::hasTable('ai_notebook_chat_tickets')) {
                $fileKeys = array_merge($fileKeys, DB::table('ai_notebook_chat_tickets')
                    ->where('subject_id', $id)->pluck('image_storage_key')->all());
            }
            $fileKeys = array_values(array_unique(array_filter($fileKeys, static fn ($key): bool =>
                is_string($key) && $key !== ''
            )));

            if ($testIds->isNotEmpty()) {
                $attemptIds = DB::table('test_attempts')->whereIn('testId', $testIds)->pluck('id');
                if ($attemptIds->isNotEmpty()) {
                    DB::table('test_attempt_files')->whereIn('attempt_id', $attemptIds)->delete();
                    DB::table('test_attempts')->whereIn('id', $attemptIds)->delete();
                }
                DB::table('test_source_files')->whereIn('test_id', $testIds)->delete();
                DB::table('tests')->whereIn('id', $testIds)->delete();
            }
            if (Schema::hasTable('ai_notebook_chat_tickets')) {
                DB::table('ai_notebook_chat_tickets')->where('subject_id', $id)->delete();
            }
            DB::table('ai_notebook_sources')->where('subject_id', $id)->delete();
            DB::table('ai_notebook_chat_sessions')->where('subject_id', $id)->delete();
            DB::table('ai_notebook_prompts')->where('subject_id', $id)->delete();
            DB::table('ai_notebook_user_prompts')->where('subject_id', $id)->delete();
            DB::table('subject_user_access')->where('subject_id', $id)->delete();
            if (Schema::hasTable('team_member_subject_access')) {
                DB::table('team_member_subject_access')->where('subject_id', $id)->delete();
            }
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'event' => 'notebook_subject_deleted',
                'details' => $subject->name,
                'userId' => $actorId,
                'metadata' => json_encode([
                    'actorId' => $actorId,
                    'subjectId' => $id,
                    'subjectCode' => $subject->code,
                    'subjectName' => $subject->name,
                    'deletedTestCount' => $testIds->count(),
                ], JSON_THROW_ON_ERROR),
                'createdAt' => now(),
            ]);
            DB::table('subjects')->where('id', $id)->delete();

            foreach ($fileKeys as $key) {
                try {
                    $this->storage->delete($key);
                } catch (\Throwable $exception) {
                    Log::error('Private file cleanup failed during subject deletion.', [
                        'subjectId' => $id,
                        'exception' => $exception::class,
                    ]);
                    throw new RuntimeException(
                        'Subject data was not deleted completely because a stored file could not be removed. Resolve the storage issue and retry.',
                        0,
                        $exception
                    );
                }
            }

        });
    }
}
