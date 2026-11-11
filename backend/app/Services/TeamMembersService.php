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
    public function __construct(private readonly SubjectAccessService $subjectAccess)
    {
    }

    private const WEB_PERMISSIONS = [
        'admin',
        'teacher_assistant',
        'user_manager',
        'password_manager',
        'testing_manager',
        'student_query',
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
            $user['subjectIds'] = DB::table('team_member_subject_access')
                ->where('user_id', $member->id)
                ->orderBy('subject_id')
                ->pluck('subject_id')
                ->all();

            return $user;
        })->all();
    }

    private function replaceSubjectAccess(string $userId, array $subjectIds, string $grantedByUserId): void
    {
        DB::table('team_member_subject_access')->where('user_id', $userId)->delete();
        $now = now();
        foreach (array_values(array_unique($subjectIds)) as $subjectId) {
            DB::table('team_member_subject_access')->insert([
                'user_id' => $userId,
                'subject_id' => $subjectId,
                'granted_by_user_id' => $grantedByUserId,
                'created_at' => $now,
            ]);
        }
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
                $this->replaceSubjectAccess($id, $data['subjectIds'] ?? [], $actorId);
                $this->audit(
                    'team_member_created',
                    "Team member {$email} created",
                    $actorId,
                    ['permissions' => $data['permissions'], 'subjectIds' => $data['subjectIds'] ?? [], 'actorId' => $actorId]
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
            'subjectIds' => $data['subjectIds'] ?? [],
        ];
        if (array_key_exists('fullName', $data)) {
            $created['fullName'] = $data['fullName'];
        }
        if (array_key_exists('phone', $data)) {
            $created['phone'] = $data['phone'];
        }

        return $created;
    }

    public function updatePermissions(string $targetUserId, array $permissions, string $actorId, ?array $subjectIds = null): array
    {
        if (!$this->callerCanManage($actorId)) {
            throw new BadRequestHttpException('Only super admins can change permissions');
        }
        $user = $this->requireUser($targetUserId);
        if ($user->role !== 'admin') {
            throw new BadRequestHttpException('Cannot change permissions on a student user');
        }
        $this->validateWebPermissions($permissions);

        DB::transaction(function () use ($targetUserId, $permissions, $actorId, $user, $subjectIds): void {
            $this->replacePermissions($targetUserId, $permissions);
            if ($subjectIds !== null) {
                $this->replaceSubjectAccess($targetUserId, $subjectIds, $actorId);
            }
            $actor = $this->requireUser($actorId);
            $this->audit(
                'team_member_permissions_changed',
                "Permissions updated for {$user->email}",
                $actor->id,
                [
                    'permissions' => $permissions,
                    'subjectIds' => $subjectIds ?? DB::table('team_member_subject_access')
                        ->where('user_id', $targetUserId)->orderBy('subject_id')->pluck('subject_id')->all(),
                    'actorId' => $actorId,
                ]
            );
        });

        $result = $this->userResponse($user);
        $result['subjectIds'] = DB::table('team_member_subject_access')
            ->where('user_id', $targetUserId)->orderBy('subject_id')->pluck('subject_id')->all();
        return $result;
    }

    public function update(string $targetUserId, array $data, string $actorId): array
    {
        if (!$this->callerCanManage($actorId)) {
            throw new AccessDeniedHttpException('Only super admins can edit team members.');
        }
        $user = $this->requireUser($targetUserId);
        if ($user->role !== 'admin') {
            throw new BadRequestHttpException('Only team member accounts can be edited here.');
        }
        $this->validateWebPermissions($data['permissions']);
        $email = mb_strtolower(trim($data['email']));

        if (DB::table('users')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('id', '!=', $targetUserId)
            ->exists()) {
            throw new BadRequestHttpException('A user with that email already exists.');
        }

        try {
            DB::transaction(function () use ($targetUserId, $data, $actorId, $email, $user): void {
                DB::table('users')->where('id', $targetUserId)->update([
                    'email' => $email,
                    'fullName' => trim((string) ($data['fullName'] ?? '')) ?: null,
                    'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                    'updatedAt' => now(),
                ]);
                $this->replacePermissions($targetUserId, $data['permissions']);
                $this->replaceSubjectAccess($targetUserId, $data['subjectIds'], $actorId);
                $this->audit(
                    'team_member_updated',
                    "Team member {$user->email} updated",
                    $actorId,
                    [
                        'targetUserId' => $targetUserId,
                        'email' => $email,
                        'permissions' => $data['permissions'],
                        'subjectIds' => $data['subjectIds'],
                    ]
                );
            });
        } catch (\Illuminate\Database\QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new BadRequestHttpException('A user with that email already exists.');
            }
            throw $exception;
        }

        $updated = $this->requireUser($targetUserId);
        $result = $this->userResponse($updated);
        $result['permissions'] = $this->getPermissions($targetUserId);
        $result['subjectIds'] = DB::table('team_member_subject_access')
            ->where('user_id', $targetUserId)
            ->orderBy('subject_id')
            ->pluck('subject_id')
            ->all();

        return $result;
    }

    public function delete(string $targetUserId, string $actorId): void
    {
        if (!$this->callerCanManage($actorId)) {
            throw new AccessDeniedHttpException('Only super admins can delete team members.');
        }
        if ($targetUserId === $actorId) {
            throw new BadRequestHttpException('You cannot delete your own account.');
        }
        $target = $this->requireUser($targetUserId);
        if ($target->role !== 'admin') {
            throw new BadRequestHttpException('Only team member accounts can be deleted here.');
        }
        $isSuperAdmin = in_array('admin', $this->getPermissions($targetUserId), true);

        DB::transaction(function () use ($targetUserId, $actorId, $target, $isSuperAdmin): void {
            if ($isSuperAdmin) {
                $superAdmins = DB::table('users')
                    ->where('role', 'admin')
                    ->whereExists(static function ($query): void {
                        $query->selectRaw('1')
                            ->from('user_role_assignments')
                            ->whereColumn('user_role_assignments.userId', 'users.id')
                            ->where('user_role_assignments.permission', 'admin');
                    })
                    ->lockForUpdate()
                    ->pluck('users.id');
                if ($superAdmins->count() <= 1) {
                    throw new BadRequestHttpException('The last super admin cannot be deleted.');
                }
            }
            $this->audit(
                'team_member_deleted',
                "Team member {$target->email} deleted",
                $actorId,
                ['targetUserId' => $targetUserId, 'email' => $target->email]
            );
            DB::table('users')->where('id', $targetUserId)->delete();
        });
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
        if ($user->role === 'student' && !$isSuperAdmin) {
            $this->subjectAccess->assertStudentAccessible($actorId, $targetUserId);
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
