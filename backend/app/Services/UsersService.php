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
    public function findByEmailPublic(string $email, ?string $role): ?array
    {
        $query = DB::table('users')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))]);
        if ($role !== null) {
            $query->where('role', $role);
        }
        $user = $query->first(['id', 'email', 'role', 'studentId']);

        return $user ? $this->basicUser($user) : null;
    }

    public function listUsers(?string $role): array
    {
        $query = DB::table('users');
        if ($role !== null) {
            $query->where('role', $role);
        }

        return $query->get(['id', 'email', 'role', 'studentId'])
            ->map(fn (object $user): array => $this->basicUser($user))
            ->all();
    }

    public function searchByEmail(string $fragment, int $limit = 25, ?string $role = null): array
    {
        $safe = mb_strtolower(trim($fragment));
        if (mb_strlen($safe) < 2) {
            return [];
        }

        $query = DB::table('users')
            ->leftJoin('devices', function ($join): void {
                $join->on('devices.userId', '=', 'users.id')
                    ->where('devices.isActive', '=', true);
            })
            ->whereRaw('LOWER(users.email) LIKE ?', ['%'.$safe.'%']);
        if ($role !== null) {
            $query->where('users.role', $role);
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
                'devices.id as device_id',
                'devices.name as device_name',
                'devices.publicKey as device_publicKey',
                'devices.deviceFingerprint as device_deviceFingerprint',
                'devices.isActive as device_isActive',
                'devices.lastHeartbeatAt as device_lastHeartbeatAt',
                'devices.createdAt as device_createdAt',
                'devices.updatedAt as device_updatedAt',
            ])
            ->map(fn (object $user): array => $this->searchUser($user))
            ->all();
    }

    public function createUser(string $email, string $password, ?string $role): array
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
                    'refreshCounter' => 0,
                    'must_reset_password' => false,
                    'createdAt' => now(),
                    'updatedAt' => now(),
                ]);
                $user = [
                    'id' => $id,
                    'email' => $normalizedEmail,
                    'role' => $role,
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
            'devices' => [],
        ];
        if ($user->device_id !== null) {
            $result['devices'][] = [
                'id' => $user->device_id,
                'name' => $user->device_name,
                'publicKey' => $user->device_publicKey,
                'deviceFingerprint' => $user->device_deviceFingerprint,
                'isActive' => (bool) $user->device_isActive,
                'lastHeartbeatAt' => $user->device_lastHeartbeatAt === null
                    ? null
                    : $this->isoDate($user->device_lastHeartbeatAt),
                'createdAt' => $this->isoDate($user->device_createdAt),
                'updatedAt' => $this->isoDate($user->device_updatedAt),
            ];
        }

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
