<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FoldersService
{
    public function listAll(?string $subjectId, ?string $classId, ?string $sectionId): array
    {
        $query = DB::table('folders')
            ->leftJoin('subjects', 'subjects.id', '=', 'folders.subject_id')
            ->select('folders.*');
        if ($subjectId) {
            $query->where('folders.subject_id', $subjectId);
        }
        if ($classId) {
            $query->where('subjects.class_id', $classId);
        }
        if ($sectionId) {
            $query->where('subjects.section_id', $sectionId);
        }

        return $query->get()->map(fn (object $folder): array => $this->folderResponse($folder, 'all'))->all();
    }

    public function create(array $data): array
    {
        $id = (string) Str::uuid();
        $now = now();
        DB::table('folders')->insert([
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_price' => number_format((float) ($data['defaultPrice'] ?? 0), 2, '.', ''),
            'subject_id' => $data['subjectId'] ?? null,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $metadata = [
            'name' => $data['name'],
            'defaultPrice' => $this->formatCurrency(($data['defaultPrice'] ?? 0)),
        ];
        if (array_key_exists('description', $data)) {
            $metadata['description'] = $data['description'];
        }
        if (array_key_exists('subjectId', $data)) {
            $metadata['subjectId'] = $data['subjectId'];
        }
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'folder_created',
            'details' => "Folder {$id} created",
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'createdAt' => $now,
        ]);

        return $this->folderResponse(DB::table('folders')->where('id', $id)->first());
    }

    public function update(string $id, array $updates): ?array
    {
        if (!DB::table('folders')->where('id', $id)->exists()) {
            throw new NotFoundHttpException('Folder not found');
        }
        if ($updates !== []) {
            $columns = [];
            foreach ([
                'name' => 'name',
                'description' => 'description',
                'coverImageUrl' => 'coverImageUrl',
                'subjectId' => 'subject_id',
                'defaultPrice' => 'default_price',
            ] as $input => $column) {
                if (array_key_exists($input, $updates)) {
                    $columns[$column] = $input === 'defaultPrice' && $updates[$input] !== null
                        ? number_format((float) $updates[$input], 2, '.', '')
                        : $updates[$input];
                }
            }
            $columns['updatedAt'] = now();
            DB::table('folders')->where('id', $id)->update($columns);
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'folder_updated',
            'details' => "Folder {$id} updated",
            'metadata' => json_encode($updates, JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);

        return $this->folderResponse(DB::table('folders')->where('id', $id)->first());
    }

    public function delete(string $id): void
    {
        $folder = DB::table('folders')->where('id', $id)->first();
        if (!$folder) {
            throw new NotFoundHttpException('Folder not found');
        }
        if (DB::table('folder_access_plans')->where('folder_id', $id)->exists()) {
            throw new ConflictHttpException(
                'This folder has billing history and cannot be deleted. Revoke access instead.'
            );
        }

        DB::table('folders')->where('id', $id)->delete();
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'folder_deleted',
            'details' => "Folder {$id} deleted",
            'metadata' => json_encode(['id' => $id], JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);
    }

    public function assignPermission(string $folderId, string $userId): array
    {
        $folder = DB::table('folders')->where('id', $folderId)->first();
        if (!$folder) {
            throw new NotFoundHttpException('Folder not found');
        }
        if ((float) $folder->default_price > 0) {
            throw new ConflictHttpException(
                'A billing plan is required for this folder. Use the folder billing access workflow.'
            );
        }
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            throw new NotFoundHttpException('User not found');
        }
        $permission = DB::table('folder_permissions')
            ->where('folderId', $folderId)
            ->where('userId', $userId)
            ->first();
        if ($permission) {
            return $this->permissionResponse($permission);
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::table('folder_permissions')->insert([
            'id' => $id,
            'userId' => $userId,
            'folderId' => $folderId,
            'canView' => true,
            'billing_suspended' => false,
            'createdAt' => $now,
        ]);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'folder_permission_assigned',
            'details' => "Permission granted for user {$userId} to folder {$folderId}",
            'userId' => $userId,
            'metadata' => json_encode(['folderId' => $folderId, 'userId' => $userId], JSON_THROW_ON_ERROR),
            'createdAt' => $now,
        ]);

        return $this->permissionResponse(DB::table('folder_permissions')->where('id', $id)->first());
    }

    public function revokePermission(?string $folderId, string $permissionId): void
    {
        $permission = DB::table('folder_permissions')
            ->where('id', $permissionId)
            ->first();

        if (!$permission) {
            throw new NotFoundHttpException('Folder permission not found');
        }

        $resolvedFolderId = $permission->folderId;
        if ($folderId !== null && $folderId !== '' && DB::table('folders')->where('id', $folderId)->exists()) {
            $resolvedFolderId = $folderId;
        }

        DB::table('folder_permissions')->where('id', $permissionId)->delete();
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'folder_permission_revoked',
            'details' => "Permission {$permissionId} revoked",
            'userId' => $permission->userId,
            'metadata' => json_encode([
                'permissionId' => $permissionId,
                'folderId' => $resolvedFolderId,
            ], JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);
    }

    public function authorized(string $userId): array
    {
        $permissions = DB::table('folder_permissions')
            ->join('folders', 'folders.id', '=', 'folder_permissions.folderId')
            ->where('folder_permissions.userId', $userId)
            ->where('folder_permissions.canView', true)
            ->where('folder_permissions.billing_suspended', false)
            ->select('folders.*')
            ->get();

        return $permissions->map(fn (object $folder): array => $this->folderResponse($folder, 'lectures'))->all();
    }

    public function permissionsForUser(string $userId): array
    {
        $rows = DB::table('folder_permissions')
            ->join('folders', 'folders.id', '=', 'folder_permissions.folderId')
            ->join('users', 'users.id', '=', 'folder_permissions.userId')
            ->where('folder_permissions.userId', $userId)
            ->orderBy('folder_permissions.createdAt')
            ->get([
                'folder_permissions.*',
                'folders.id as joinedFolderId',
                'folders.name as joinedFolderName',
                'folders.description as joinedFolderDescription',
                'folders.default_price as joinedFolderDefaultPrice',
                'folders.coverImageUrl as joinedFolderCoverImageUrl',
                'folders.subject_id as joinedFolderSubjectId',
                'folders.createdAt as joinedFolderCreatedAt',
                'folders.updatedAt as joinedFolderUpdatedAt',
                'users.id as joinedUserId',
                'users.email as joinedUserEmail',
                'users.role as joinedUserRole',
                'users.studentId as joinedUserStudentId',
                'users.fullName as joinedUserFullName',
                'users.phone as joinedUserPhone',
                'users.must_reset_password as joinedUserMustResetPassword',
                'users.createdAt as joinedUserCreatedAt',
                'users.updatedAt as joinedUserUpdatedAt',
            ]);

        return $rows->map(function (object $row): array {
            return [
                'id' => $row->id,
                'canView' => (bool) $row->canView,
                'billingSuspended' => (bool) $row->billing_suspended,
                'createdAt' => $this->isoDate($row->createdAt),
                'user' => $this->joinedUserResponse($row),
                'folder' => $this->joinedFolderResponse($row),
            ];
        })->all();
    }

    private function folderResponse(object $folder, string $relations = 'none'): array
    {
        $response = [
            'id' => $folder->id,
            'name' => $folder->name,
            'description' => $folder->description ?? '',
            'defaultPrice' => $this->formatCurrency($folder->default_price),
            'coverImageUrl' => $folder->coverImageUrl ?? null,
            'subjectId' => $folder->subject_id,
            'createdAt' => $this->isoDate($folder->createdAt),
            'updatedAt' => $this->isoDate($folder->updatedAt),
        ];
        if ($relations !== 'none') {
            $response['lectures'] = DB::table('lectures')
                ->where('folderId', $folder->id)
                ->whereNull('deletedAt')
                ->get()
                ->map(fn (object $lecture): array => $this->lectureResponse($lecture))
                ->all();
        }
        if ($relations === 'all') {
            $response['permissions'] = DB::table('folder_permissions')
                ->where('folderId', $folder->id)
                ->get()
                ->map(function (object $permission): array {
                    $user = DB::table('users')->where('id', $permission->userId)->first();

                    return [
                        ...$this->permissionResponse($permission),
                        'user' => $user ? $this->userResponse($user) : null,
                    ];
                })
                ->all();
            $subject = $folder->subject_id
                ? DB::table('subjects')->where('id', $folder->subject_id)->first()
                : null;
            $response['subject'] = $subject ? $this->subjectResponse($subject) : null;
        }

        return $response;
    }

    private function permissionResponse(object $permission): array
    {
        return [
            'id' => $permission->id,
            'canView' => (bool) $permission->canView,
            'billingSuspended' => (bool) $permission->billing_suspended,
            'createdAt' => $this->isoDate($permission->createdAt),
        ];
    }

    private function lectureResponse(object $lecture): array
    {
        return [
            'id' => $lecture->id,
            'title' => $lecture->title,
            'description' => $lecture->description,
            'duration' => $lecture->duration,
            'contentGroup' => $lecture->contentGroup,
            'orderIndex' => $lecture->orderIndex,
            'contentDate' => $lecture->contentDate,
            'createdAt' => $this->isoDate($lecture->createdAt),
            'updatedAt' => $this->isoDate($lecture->updatedAt),
        ];
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

    private function joinedUserResponse(object $row): array
    {
        return [
            'id' => $row->joinedUserId,
            'email' => $row->joinedUserEmail,
            'role' => $row->joinedUserRole,
            'studentId' => $row->joinedUserStudentId,
            'fullName' => $row->joinedUserFullName,
            'phone' => $row->joinedUserPhone,
            'mustResetPassword' => (bool) $row->joinedUserMustResetPassword,
            'createdAt' => $this->isoDate($row->joinedUserCreatedAt),
            'updatedAt' => $this->isoDate($row->joinedUserUpdatedAt),
        ];
    }

    private function joinedFolderResponse(object $row): array
    {
        return [
            'id' => $row->joinedFolderId,
            'name' => $row->joinedFolderName,
            'description' => $row->joinedFolderDescription ?? '',
            'defaultPrice' => $this->formatCurrency($row->joinedFolderDefaultPrice),
            'coverImageUrl' => $row->joinedFolderCoverImageUrl ?? null,
            'subjectId' => $row->joinedFolderSubjectId,
            'createdAt' => $this->isoDate($row->joinedFolderCreatedAt),
            'updatedAt' => $this->isoDate($row->joinedFolderUpdatedAt),
        ];
    }

    private function subjectResponse(object $subject): array
    {
        return [
            'id' => $subject->id,
            'schoolId' => $subject->school_id,
            'code' => $subject->code,
            'name' => $subject->name,
            'classId' => $subject->class_id,
            'sectionId' => $subject->section_id,
            'createdAt' => $this->isoDate($subject->created_at),
            'updatedAt' => $this->isoDate($subject->updated_at),
        ];
    }

    private function formatCurrency(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function isoDate(mixed $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
