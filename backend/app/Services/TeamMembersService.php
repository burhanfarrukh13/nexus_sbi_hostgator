<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TeamMembersService
{
    private const WEB_PERMISSIONS = [
        'admin',
        'teacher_assistant',
        'folder_manager',
        'user_manager',
        'password_manager',
        'student_query',
        'testing_manager',
    ];

    public function list(): array
    {
        $members = DB::table('users')
            ->where('role', 'admin')
            ->orderByDesc('createdAt')
            ->get([
                'id',
                'email',
                'role',
                'studentId',
                'fullName',
                'phone',
                'must_reset_password',
                'createdAt',
                'updatedAt',
            ]);

        return $members->map(function (object $member): array {
            $user = $this->userResponse($member);
            $user['permissions'] = DB::table('user_role_assignments')
                ->where('userId', $member->id)
                ->orderBy('permission')
                ->pluck('permission')
                ->all();

            return $user;
        })->all();
    }

    public function create(array $data, string $actorId): array
    {
        if (!$this->callerCanManage($actorId)) {
            throw new BadRequestHttpException('Only super admins can create team members');
        }
        $this->validateWebPermissions($data['permissions']);
        $email = mb_strtolower(trim($data['email']));

        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new BadRequestHttpException('A user with that email already exists');
        }

        $id = (string) Str::uuid();
        try {
            DB::transaction(function () use ($id, $email, $data, $actorId): void {
                DB::table('users')->insert([
                    'id' => $id,
                    'email' => $email,
                    'password' => Hash::make($data['password'], ['rounds' => 12]),
                    'role' => 'admin',
                    'fullName' => $data['fullName'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'refreshCounter' => 0,
                    'must_reset_password' => false,
                    'createdAt' => now(),
                    'updatedAt' => now(),
                ]);
                $this->replacePermissions($id, $data['permissions']);
                $this->audit(
                    'team_member_created',
                    "Team member {$email} created",
                    $actorId,
                    ['permissions' => $data['permissions'], 'actorId' => $actorId]
                );
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new BadRequestHttpException('A user with that email already exists');
            }
            throw $exception;
        }

        $created = [
            'id' => $id,
            'email' => $email,
            'permissions' => $data['permissions'],
        ];
        if (array_key_exists('fullName', $data)) {
            $created['fullName'] = $data['fullName'];
        }
        if (array_key_exists('phone', $data)) {
            $created['phone'] = $data['phone'];
        }

        return $created;
    }

    public function updatePermissions(string $targetUserId, array $permissions, string $actorId): array
    {
        if (!$this->callerCanManage($actorId)) {
            throw new BadRequestHttpException('Only super admins can change permissions');
        }
        $user = $this->requireUser($targetUserId);
        if ($user->role !== 'admin') {
            throw new BadRequestHttpException('Cannot change permissions on a student user');
        }
        $this->validateWebPermissions($permissions);

        DB::transaction(function () use ($targetUserId, $permissions, $actorId, $user): void {
            $this->replacePermissions($targetUserId, $permissions);
            $actor = $this->requireUser($actorId);
            $this->audit(
                'team_member_permissions_changed',
                "Permissions updated for {$user->email}",
                $actor->id,
                ['permissions' => $permissions, 'actorId' => $actorId]
            );
        });

        return $this->userResponse($user);
    }

    public function changePassword(string $targetUserId, array $data, string $actorId): array
    {
        if (!$this->callerCanChangePassword($actorId)) {
            throw new BadRequestHttpException('Only super admins or password managers can change passwords');
        }
        $user = $this->requireUser($targetUserId);
        $actor = $this->requireUser($actorId);
        $actorPermissions = $this->getPermissions($actorId);
        $isSuperAdmin = in_array('admin', $actorPermissions, true);

        if ($actorId === $targetUserId) {
            throw new BadRequestHttpException('Use the self-service password change flow for your own account');
        }
        if ($user->role !== 'student' && !$isSuperAdmin) {
            throw new AccessDeniedHttpException('Password managers may reset student accounts only');
        }
        if ($user->role !== 'student') {
            if (in_array('admin', $this->getPermissions($targetUserId), true)) {
                throw new AccessDeniedHttpException('Super admin accounts cannot be reset through team password management');
            }
            if (empty($data['currentPassword'])) {
                throw new BadRequestHttpException('Current password is required to reset a team member account');
            }
            if (!Hash::check($data['currentPassword'], $actor->password)) {
                throw new BadRequestHttpException('Current password is incorrect');
            }
        }

        $hashed = Hash::make($data['newPassword'], ['rounds' => 12]);
        DB::transaction(function () use ($targetUserId, $actorId, $hashed, $user): void {
            DB::table('users')->where('id', $targetUserId)->update([
                'password' => $hashed,
                'must_reset_password' => true,
                'refreshTokenHash' => null,
                'previousRefreshTokenHash' => null,
                'refreshCounter' => DB::raw('refreshCounter + 1'),
                'updatedAt' => now(),
            ]);
            $this->audit(
                'password_reset',
                "Password reset for {$user->email}",
                $actorId,
                ['actorId' => $actorId, 'targetUserId' => $targetUserId]
            );
        });
        return ['success' => true];
    }

    private function replacePermissions(string $userId, array $permissions): void
    {
        DB::table('user_role_assignments')->where('userId', $userId)->delete();
        foreach ($permissions as $permission) {
            DB::table('user_role_assignments')->insert([
                'userId' => $userId,
                'granteeId' => $userId,
                'permission' => $permission,
                'grantedAt' => now(),
            ]);
        }
    }

    private function validateWebPermissions(array $permissions): void
    {
        $unsupported = array_values(array_diff($permissions, self::WEB_PERMISSIONS));
        if ($unsupported !== []) {
            throw new BadRequestHttpException(
                'Permissions not available in the web portal: '.implode(', ', $unsupported).'.'
            );
        }
    }

    private function getPermissions(string $userId): array
    {
        return DB::table('user_role_assignments')
            ->where('userId', $userId)
            ->orderBy('permission')
            ->pluck('permission')
            ->all();
    }

    private function callerCanManage(string $userId): bool
    {
        $user = DB::table('users')->where('id', $userId)->first(['id', 'role']);

        return $user && $user->role === 'admin' &&
            in_array('admin', $this->getPermissions($userId), true);
    }

    private function callerCanChangePassword(string $userId): bool
    {
        $user = DB::table('users')->where('id', $userId)->first(['id', 'role']);
        if (!$user || $user->role !== 'admin') {
            return false;
        }
        $permissions = $this->getPermissions($userId);

        return in_array('admin', $permissions, true) ||
            in_array('password_manager', $permissions, true);
    }

    private function requireUser(string $userId): object
    {
        $user = DB::table('users')->where('id', $userId)->first([
            'id',
            'email',
            'role',
            'studentId',
            'fullName',
            'phone',
            'must_reset_password',
            'createdAt',
            'updatedAt',
            'password',
        ]);
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }

        return $user;
    }

    private function userResponse(object $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'studentId' => $user->studentId,
            'fullName' => $user->fullName,
            'phone' => $user->phone,
            'mustResetPassword' => (bool) $user->must_reset_password,
            'createdAt' => $this->isoDate($user->createdAt),
            'updatedAt' => $this->isoDate($user->updatedAt),
        ];
    }

    private function isoDate(string|DateTimeInterface $value): string
    {
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function audit(string $event, string $details, string $actorId, array $metadata): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'details' => $details,
            'userId' => $actorId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);
    }
}
