<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resolves subject access for students and scoped administrative team members.
 *
 * A team member with no rows in team_member_subject_access is treated as
 * unrestricted. This preserves existing team accounts when the additive table
 * is installed; adding one or more rows opts that member into restricted scope.
 */
class SubjectAccessService
{
    public function isSuperAdmin(string $userId): bool
    {
        return DB::table('users')
            ->where('id', $userId)
            ->where('role', 'admin')
            ->whereExists(static function (Builder $query) use ($userId): void {
                $query->selectRaw('1')
                    ->from('user_role_assignments')
                    ->whereColumn('user_role_assignments.userId', 'users.id')
                    ->where('user_role_assignments.permission', 'admin');
            })
            ->exists();
    }

    public function isScopedTeamMember(string $userId): bool
    {
        if ($this->isSuperAdmin($userId) ||
            !DB::table('users')->where('id', $userId)->where('role', 'admin')->exists()) {
            return false;
        }

        return DB::table('team_member_subject_access')->where('user_id', $userId)->exists();
    }

    public function allowedSubjectIds(string $userId): ?array
    {
        $user = DB::table('users')->where('id', $userId)->first(['role']);
        if (!$user) {
            return [];
        }
        if ($user->role === 'student') {
            return DB::table('subject_user_access')
                ->where('user_id', $userId)
                ->orderBy('subject_id')
                ->pluck('subject_id')
                ->all();
        }
        if (!$this->isScopedTeamMember($userId)) {
            return null;
        }

        return DB::table('team_member_subject_access')
            ->where('user_id', $userId)
            ->orderBy('subject_id')
            ->pluck('subject_id')
            ->all();
    }

    public function constrainSubjects(Builder $query, string $userId, string $subjectColumn = 'subjects.id'): Builder
    {
        $user = DB::table('users')->where('id', $userId)->first(['role']);
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->role === 'student') {
            return $query->whereExists(static function (Builder $access) use ($userId, $subjectColumn): void {
                $access->selectRaw('1')
                    ->from('subject_user_access')
                    ->whereColumn('subject_user_access.subject_id', $subjectColumn)
                    ->where('subject_user_access.user_id', $userId);
            });
        }
        if ($this->isScopedTeamMember($userId)) {
            return $query->whereExists(static function (Builder $access) use ($userId, $subjectColumn): void {
                $access->selectRaw('1')
                    ->from('team_member_subject_access')
                    ->whereColumn('team_member_subject_access.subject_id', $subjectColumn)
                    ->where('team_member_subject_access.user_id', $userId);
            });
        }

        return $query;
    }

    public function constrainStudents(Builder $query, string $adminId, string $studentColumn = 'users.id'): Builder
    {
        if (!$this->isScopedTeamMember($adminId)) {
            return $query;
        }

        return $query->whereExists(static function (Builder $access) use ($adminId, $studentColumn): void {
            $access->selectRaw('1')
                ->from('subject_user_access as student_access')
                ->join('team_member_subject_access as team_access', 'team_access.subject_id', '=', 'student_access.subject_id')
                ->whereColumn('student_access.user_id', $studentColumn)
                ->where('team_access.user_id', $adminId);
        });
    }

    public function assertSubjectAccessible(string $userId, string $subjectId): void
    {
        $query = DB::table('subjects');
        $this->constrainSubjects($query, $userId);
        if (!$query->where('subjects.id', $subjectId)->exists()) {
            throw new AccessDeniedHttpException('You do not have access to this subject.');
        }
    }

    public function assertTestAccessible(string $userId, string $testId): object
    {
        $query = DB::table('tests');
        $this->constrainSubjects($query, $userId, 'tests.subject_id');
        $test = $query->where('tests.id', $testId)->first();
        if (!$test) {
            throw new AccessDeniedHttpException('You do not have access to this test.');
        }

        return $test;
    }

    public function assertStudentAccessible(string $adminId, string $studentId): void
    {
        $query = DB::table('users')->where('users.role', 'student');
        $this->constrainStudents($query, $adminId);
        if (!$query->where('users.id', $studentId)->exists()) {
            throw new AccessDeniedHttpException('You do not have access to this student.');
        }
    }
}
