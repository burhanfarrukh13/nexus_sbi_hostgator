<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSupportAnalyticsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_queries_are_private_and_admins_with_permission_can_reply(): void
    {
        $student = $this->createUser('student@example.test', 'student');
        $otherStudent = $this->createUser('other@example.test', 'student');
        $manager = $this->createUser('support@example.test', 'admin', ['student_query']);

        $created = $this->withToken($this->token($student))
            ->postJson('/api/student-queries', [
                'title' => 'Notebook help',
                'message' => 'My source is not loading.',
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Notebook help')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('user.id', $student);

        $queryId = $created->json('id');
        $this->withToken($this->token($student))
            ->getJson('/api/student-queries')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $queryId);
        $this->withToken($this->token($otherStudent))
            ->getJson('/api/student-queries')
            ->assertOk()
            ->assertExactJson([]);

        $this->withToken($this->token($manager))
            ->getJson('/api/student-queries')
            ->assertOk()
            ->assertJsonCount(1);
        $this->withToken($this->token($manager))
            ->postJson("/api/student-queries/{$queryId}/reply", [
                'response' => 'Please refresh the Notebook page.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'responded')
            ->assertJsonPath('response', 'Please refresh the Notebook page.')
            ->assertJsonPath('respondedBy.id', $manager);

        $this->withToken($this->token($this->createUser('unprivileged@example.test', 'admin')))
            ->getJson('/api/student-queries')
            ->assertForbidden();
    }

    public function test_analytics_returns_current_scope_metrics_and_requires_permission(): void
    {
        $subjectId = (string) Str::uuid();
        $testId = (string) Str::uuid();
        $studentId = $this->createUser('analytics-student@example.test', 'student');
        $managerId = $this->createUser('analytics-manager@example.test', 'admin', ['testing_manager']);

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'ANA-1',
            'name' => 'Analytics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tests')->insert([
            'id' => $testId,
            'title' => 'Analytics test',
            'totalMarks' => '10.00',
            'questions' => '[]',
            'subject_id' => $subjectId,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('test_attempts')->insert([
            'id' => (string) Str::uuid(),
            'testId' => $testId,
            'studentId' => $studentId,
            'appeared' => true,
            'marksObtained' => '8.00',
            'status' => 'COMPLETED',
            'submitted_at' => now(),
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        $this->withToken($this->token($managerId))
            ->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->assertJsonPath('totalStudents', 1)
            ->assertJsonPath('totalSubjects', 1)
            ->assertJsonPath('totalTests', 1)
            ->assertJsonPath('submittedAttempts', 1)
            ->assertJsonPath('gradedAttempts', 1)
            ->assertJsonPath('averageMarks', 8);

        $this->withToken($this->token($studentId))
            ->getJson('/api/admin/analytics/overview')
            ->assertForbidden();
    }

    private function createUser(string $email, string $role, array $permissions = []): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('current-account-password'),
            'role' => $role,
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        foreach ($permissions as $permission) {
            DB::table('user_role_assignments')->insert([
                'userId' => $id,
                'granteeId' => $id,
                'permission' => $permission,
                'grantedAt' => now(),
            ]);
        }

        return $id;
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();

        return app(JwtTokenService::class)->issue([
            'sub' => $userId,
            'email' => $user->email,
            'role' => $user->role,
            'adminPermissions' => DB::table('user_role_assignments')
                ->where('userId', $userId)
                ->pluck('permission')
                ->all(),
            'mustResetPassword' => false,
            'sessionVersion' => (int) $user->refreshCounter,
            'jti' => (string) Str::uuid(),
        ], (string) config('auth.jwt.access_secret'), '60m');
    }
}
