<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SubjectsService
{
    public function list(?string $userId, bool $isAdmin): array
    {
        $query = DB::table('subjects');
        if (!$isAdmin && $userId !== null) {
            $query->join('subject_user_access', 'subject_user_access.subject_id', '=', 'subjects.id')
                ->where('subject_user_access.user_id', $userId);
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

    public function update(string $id, array $data): array
    {
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

    public function delete(string $id): void
    {
        if (!DB::table('subjects')->where('id', $id)->exists()) {
            throw new NotFoundHttpException('Subject not found.');
        }
        if (DB::table('tests')->where('subject_id', $id)->exists() ||
            DB::table('ai_notebook_sources')->where('subject_id', $id)->exists() ||
            DB::table('ai_notebook_chat_sessions')->where('subject_id', $id)->exists()) {
            throw new BadRequestHttpException('A subject with tests or notebook activity cannot be deleted.');
        }
        DB::table('subjects')->where('id', $id)->delete();
    }
}
