<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StudentQueriesService
{
    public function create(string $userId, array $data): array
    {
        $id = (string) Str::uuid();
        $now = now();
        DB::table('student_queries')->insert([
            'id' => $id,
            'userId' => $userId,
            'title' => trim($data['title']),
            'message' => trim($data['message']),
            'status' => 'open',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $this->audit('student_query_created', $id, $userId);

        return $this->find($id);
    }

    public function listForUser(string $userId): array
    {
        return $this->query()
            ->where('query.userId', $userId)
            ->orderByDesc('query.createdAt')
            ->get()
            ->map(fn (object $row): array => $this->format($row))
            ->all();
    }

    public function listAll(): array
    {
        return $this->query()
            ->orderByDesc('query.createdAt')
            ->get()
            ->map(fn (object $row): array => $this->format($row))
            ->all();
    }

    public function reply(string $id, string $response, string $adminId): array
    {
        if (!DB::table('student_queries')->where('id', $id)->exists()) {
            throw new NotFoundHttpException('Student query not found.');
        }

        DB::table('student_queries')->where('id', $id)->update([
            'response' => trim($response),
            'status' => 'responded',
            'respondedById' => $adminId,
            'updatedAt' => now(),
        ]);
        $this->audit('student_query_responded', $id, $adminId);

        return $this->find($id);
    }

    private function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table('student_queries as query')
            ->join('users as user', 'user.id', '=', 'query.userId')
            ->leftJoin('users as responder', 'responder.id', '=', 'query.respondedById')
            ->select([
                'query.id',
                'query.userId',
                'query.title',
                'query.message',
                'query.response',
                'query.status',
                'query.createdAt',
                'query.updatedAt',
                'user.id as user_id',
                'user.email as user_email',
                'user.fullName as user_full_name',
                'user.studentId as user_student_id',
                'responder.id as responder_id',
                'responder.email as responder_email',
                'responder.fullName as responder_full_name',
            ]);
    }

    private function find(string $id): array
    {
        $row = $this->query()->where('query.id', $id)->first();
        if (!$row) {
            throw new NotFoundHttpException('Student query not found.');
        }

        return $this->format($row);
    }

    private function format(object $row): array
    {
        return [
            'id' => $row->id,
            'title' => $row->title,
            'message' => $row->message,
            'response' => $row->response,
            'status' => $row->status,
            'createdAt' => $row->createdAt,
            'updatedAt' => $row->updatedAt,
            'user' => [
                'id' => $row->user_id,
                'email' => $row->user_email,
                'fullName' => $row->user_full_name,
                'studentId' => $row->user_student_id,
            ],
            'respondedBy' => $row->responder_id === null ? null : [
                'id' => $row->responder_id,
                'email' => $row->responder_email,
                'fullName' => $row->responder_full_name,
            ],
        ];
    }

    private function audit(string $event, string $queryId, string $actorId): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'details' => "Student query {$queryId}",
            'userId' => $actorId,
            'metadata' => json_encode(['queryId' => $queryId], JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);
    }
}
