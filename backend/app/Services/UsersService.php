<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsersService
{
    private const INITIAL_STUDENT_PASSWORD = 'abc@123';

    public function __construct(private readonly SubjectAccessService $subjectAccess)
    {
    }

    public function findByEmailPublic(string $email, ?string $role, ?string $adminId = null): ?array
    {
        $query = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
        if ($role !== null) {
            $query->where('role', $role);
        }
        if ($adminId !== null) {
            $this->subjectAccess->constrainStudents($query, $adminId);
        }
        $user = $query->first(['id', 'email', 'role', 'studentId', 'fullName', 'phone']);

        return $user ? $this->basicUser($user) : null;
    }

    public function listUsers(?string $role, ?string $adminId = null): array
    {
        $query = DB::table('users');
        if ($role !== null) {
            $query->where('role', $role);
        }
        if ($adminId !== null) {
            $this->subjectAccess->constrainStudents($query, $adminId);
        }

        $users = $query->get(['id', 'email', 'role', 'studentId', 'fullName', 'phone']);
        $accessByUser = DB::table('subject_user_access')
            ->whereIn('subject_user_access.user_id', $users->pluck('id'))
            ->when(
                $adminId !== null && $this->subjectAccess->isScopedTeamMember($adminId),
                static fn ($query) => $query->join(
                    'team_member_subject_access',
                    'team_member_subject_access.subject_id',
                    '=',
                    'subject_user_access.subject_id'
                )->where('team_member_subject_access.user_id', $adminId)
            )
            ->get(['subject_user_access.user_id as user_id', 'subject_user_access.subject_id as subject_id'])
            ->groupBy('user_id');

        return $users
            ->map(fn (object $user): array => $this->basicUser($user) + [
                'subjectIds' => ($accessByUser->get($user->id) ?? collect())
                    ->pluck('subject_id')
                    ->all(),
            ])
            ->all();
    }

    public function createStudent(array $data, string $actorId): array
    {
        $email = mb_strtolower(trim($data['email']));
        $crn = trim($data['crn']);
        $subjectIds = array_values(array_unique($data['subjectIds'] ?? []));
        $userId = (string) Str::uuid();
        $now = now();

        return DB::transaction(function () use ($data, $actorId, $email, $crn, $subjectIds, $userId, $now): array {
            DB::table('users')->insert([
                'id' => $userId,
                'email' => $email,
                'password' => Hash::make(self::INITIAL_STUDENT_PASSWORD),
                'role' => 'student',
                'studentId' => $crn,
                'fullName' => trim($data['fullName']),
                'phone' => trim($data['phone']),
                'refreshCounter' => 0,
                'must_reset_password' => true,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);

            foreach ($subjectIds as $subjectId) {
                DB::table('subject_user_access')->insert([
                    'subject_id' => $subjectId,
                    'user_id' => $userId,
                    'granted_by_user_id' => $actorId,
                    'created_at' => $now,
                ]);
            }

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'event' => 'student_account_created',
                'details' => 'Student account created',
                'userId' => $actorId,
                'metadata' => json_encode([
                    'studentId' => $userId,
                    'crn' => $crn,
                    'subjectIds' => $subjectIds,
                ], JSON_THROW_ON_ERROR),
                'createdAt' => $now,
            ]);

            return [
                'id' => $userId,
                'email' => $email,
                'role' => 'student',
                'studentId' => $crn,
                'fullName' => trim($data['fullName']),
                'phone' => trim($data['phone']),
                'subjectIds' => $subjectIds,
                'mustResetPassword' => true,
            ];
        });
    }

    public function updateStudentSubjects(
        string $userId,
        array $subjectIds,
        string $actorId,
        ?array $manageableSubjectIds = null
    ): array
    {
        $student = DB::table('users')->where('id', $userId)->where('role', 'student')->first();
        if (!$student) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Student not found.');
        }

        $subjectIds = array_values(array_unique($subjectIds));
        $existingSubjectIds = DB::table('subject_user_access')
            ->where('user_id', $userId)->pluck('subject_id')->all();
        $retainedSubjectIds = $manageableSubjectIds === null
            ? []
            : array_values(array_diff($existingSubjectIds, $manageableSubjectIds));
        $storedSubjectIds = array_values(array_unique([...$retainedSubjectIds, ...$subjectIds]));
        $now = now();
        DB::transaction(function () use ($userId, $subjectIds, $storedSubjectIds, $actorId, $now): void {
            DB::table('subject_user_access')->where('user_id', $userId)->delete();
            foreach ($storedSubjectIds as $subjectId) {
                DB::table('subject_user_access')->insert([
                    'subject_id' => $subjectId,
                    'user_id' => $userId,
                    'granted_by_user_id' => $actorId,
                    'created_at' => $now,
                ]);
            }
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'event' => 'student_subject_access_updated',
                'details' => 'Student subject access updated',
                'userId' => $actorId,
                'metadata' => json_encode([
                    'studentId' => $userId,
                    'subjectIds' => $storedSubjectIds,
                ], JSON_THROW_ON_ERROR),
                'createdAt' => $now,
            ]);
        });

        return $this->basicUser($student) + [
            'subjectIds' => $manageableSubjectIds === null ? $storedSubjectIds : $subjectIds,
        ];
    }

    public function searchByEmail(string $fragment, int $limit = 25, ?string $role = null, ?string $adminId = null): array
    {
        $safe = mb_strtolower(trim($fragment));
        if (mb_strlen($safe) < 2) {
            return [];
        }

        $query = DB::table('users')
            ->whereRaw('LOWER(email) LIKE ?', ['%'.$safe.'%']);
        if ($role !== null) {
            $query->where('users.role', $role);
        }
        if ($adminId !== null) {
            $this->subjectAccess->constrainStudents($query, $adminId);
        }

        return $query->orderBy('users.email')
            ->limit($limit)
            ->get([
                'users.id',
                'users.email',
                'users.role',
                'users.studentId',
                'users.fullName',
                'users.phone',
                'users.must_reset_password',
                'users.createdAt',
                'users.updatedAt',
            ])
            ->map(fn (object $user): array => $this->searchUser($user))
            ->all();
    }

    public function createUser(string $email, string $password, ?string $role, ?string $fullName = null): array
    {
        $role ??= 'student';
        $normalizedEmail = mb_strtolower(trim($email));
        $passwordHash = Hash::make($password, ['rounds' => 12]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = (string) Str::uuid();
            $studentId = $role === 'student'
                ? 'STU-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 16))
                : null;
            try {
                DB::table('users')->insert([
                    'id' => $id,
                    'email' => $normalizedEmail,
                    'password' => $passwordHash,
                    'role' => $role,
                    'studentId' => $studentId,
                    'fullName' => $fullName === null ? null : trim($fullName),
                    'refreshCounter' => 0,
                    'must_reset_password' => false,
                    'createdAt' => now(),
                    'updatedAt' => now(),
                ]);
                $user = [
                    'id' => $id,
                    'email' => $normalizedEmail,
                    'role' => $role,
                    'fullName' => $fullName === null ? null : trim($fullName),
                ];
                if ($studentId !== null) {
                    $user['studentId'] = $studentId;
                }

                return $user;
            } catch (QueryException $exception) {
                if (!$this->isStudentIdCollision($exception) || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to generate a unique student ID');
    }

    private function basicUser(object $user): array
    {
        $result = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'fullName' => $user->fullName ?? null,
            'phone' => $user->phone ?? null,
            'fullName' => $user->fullName,
        ];
        if ($user->studentId !== null) {
            $result['studentId'] = $user->studentId;
        }

        return $result;
    }

    private function searchUser(object $user): array
    {
        $result = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
        ];
        if ($user->studentId !== null) {
            $result['studentId'] = $user->studentId;
        }
        $result += [
            'fullName' => $user->fullName,
            'phone' => $user->phone,
            'mustResetPassword' => (bool) $user->must_reset_password,
            'createdAt' => $this->isoDate($user->createdAt),
            'updatedAt' => $this->isoDate($user->updatedAt),
        ];

        return $result;
    }

    private function isStudentIdCollision(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'studentid') || str_contains($message, 'users_student_id_unique');
    }

    private function isoDate(string|DateTimeInterface $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
