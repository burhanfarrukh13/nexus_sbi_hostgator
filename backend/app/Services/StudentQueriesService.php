<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StudentQueriesService
{
    public function create(string $userId, array $data): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::table('student_queries')->insert([
            'id' => $id,
            'userId' => $userId,
            'title' => $data['title'],
            'message' => $data['message'],
            'status' => 'open',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $this->audit('student_query_created', "Query {$id} created", $userId, [
            'userId' => $userId,
            'queryId' => $id,
        ]);

        $result = $this->findById($id);
        unset($result['respondedBy']);

        return $result;
    }

    public function listForUser(string $userId): array
    {
        return DB::table('student_queries')
            ->where('userId', $userId)
            ->orderByDesc('createdAt')
            ->get()
            ->map(fn (object $query): array => $this->queryFields($query))
            ->all();
    }

    public function getForUser(string $userId, string $id): array
    {
        $query = DB::table('student_queries')->where('id', $id)->first();
        if (!$query) {
            throw new NotFoundHttpException('Query not found');
        }
        if ($query->userId !== $userId) {
            throw new AccessDeniedHttpException('Not allowed');
        }

        return $this->queryWithRelations($query);
    }

    public function listAll(): array
    {
        return DB::table('student_queries')
            ->orderByDesc('createdAt')
            ->get()
            ->map(fn (object $query): array => $this->queryWithRelations($query))
            ->all();
    }

    public function findById(string $id): array
    {
        $query = DB::table('student_queries')->where('id', $id)->first();
        if (!$query) {
            throw new NotFoundHttpException('Query not found');
        }

        return $this->queryWithRelations($query);
    }

    public function reply(string $id, array $data, string $adminId): array
    {
        $query = DB::table('student_queries')->where('id', $id)->first();
        if (!$query) {
            throw new NotFoundHttpException('Query not found');
        }
        $admin = DB::table('users')->where('id', $adminId)->first();
        $now = now();
        DB::table('student_queries')->where('id', $id)->update([
            'response' => $data['response'],
            'status' => 'responded',
            'respondedById' => $admin?->id,
            'updatedAt' => $now,
        ]);
        $this->audit('student_query_responded', "Query {$id} responded", $admin?->id, [
            'queryId' => $id,
            'adminId' => $adminId,
        ]);

        return $this->findById($id);
    }

    private function queryWithRelations(object $query): array
    {
        $result = $this->queryFields($query);
        $result['user'] = $this->publicUser($query->userId);
        $result['respondedBy'] = $query->respondedById !== null
            ? $this->publicUser($query->respondedById)
            : null;

        return $result;
    }

    private function queryFields(object $query): array
    {
        return [
            'id' => $query->id,
            'title' => $query->title,
            'message' => $query->message,
            'response' => $query->response,
            'status' => $query->status,
            'createdAt' => $query->createdAt,
            'updatedAt' => $query->updatedAt,
        ];
    }

    private function publicUser(string $id): ?array
    {
        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'studentId' => $user->studentId,
            'fullName' => $user->fullName,
            'phone' => $user->phone,
            'createdAt' => $user->createdAt,
            'updatedAt' => $user->updatedAt,
        ];
    }

    private function audit(string $event, string $details, ?string $userId, array $metadata): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'details' => $details,
            'userId' => $userId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);
    }
}
