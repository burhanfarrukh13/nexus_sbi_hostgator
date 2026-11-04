<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestsAuthoringCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_authoring_and_student_access_use_subjects_without_folder_tables(): void
    {
        $admin = $this->createUser('teacher@example.test', 'admin', ['teacher_assistant']);
        $student = $this->createUser('student@example.test', 'student');
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'MATH-1',
            'name' => 'Mathematics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $student,
            'granted_by_user_id' => $admin,
            'created_at' => now(),
        ]);

        $created = $this->withToken($this->token($admin))
            ->postJson('/api/tests', $this->testPayload($subjectId))
            ->assertCreated()
            ->assertJsonPath('subjectId', $subjectId)
            ->assertJsonPath('subject.name', 'Mathematics');
        $testId = $created->json('id');

        $this->withToken($this->token($student))
            ->getJson('/api/tests/me/attempts')
            ->assertOk()
            ->assertJsonPath('0.test.id', $testId)
            ->assertJsonMissingPath('0.test.questions.0.idealAnswer');

        $this->withToken($this->token($student))
            ->getJson("/api/tests/{$testId}/me")
            ->assertOk()
            ->assertJsonPath('questions.0.questionText', 'What is 2 + 2?')
            ->assertJsonMissingPath('questions.0.idealAnswer')
            ->assertJsonPath('lectures', []);
    }

    public function test_test_authoring_requires_permission_and_valid_subject(): void
    {
        $student = $this->createUser('student@example.test', 'student');
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'ENG-1',
            'name' => 'English',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($this->token($student))
            ->postJson('/api/tests', $this->testPayload($subjectId))
            ->assertForbidden();

        $teacher = $this->createUser('teacher@example.test', 'admin', ['teacher_assistant']);
        $this->withToken($this->token($teacher))
            ->postJson('/api/tests', $this->testPayload((string) Str::uuid()))
            ->assertBadRequest();
    }

    private function testPayload(string $subjectId): array
    {
        return [
            'title' => 'Quick arithmetic',
            'description' => 'A short test.',
            'totalMarks' => 2,
            'subjectId' => $subjectId,
            'deadlineAt' => now()->addDay()->toISOString(),
            'questions' => [[
                'questionNumber' => 1,
                'questionText' => 'What is 2 + 2?',
                'idealAnswer' => '4',
                'marks' => 2,
                'markingScheme' => [['criterion' => 'Correct answer', 'marks' => 2]],
            ]],
        ];
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
        $permissions = DB::table('user_role_assignments')
            ->where('userId', $userId)
            ->orderBy('permission')
            ->pluck('permission')
            ->all();

        return app(JwtTokenService::class)->issue([
            'sub' => $userId,
            'email' => $user->email,
            'role' => $user->role,
            'adminPermissions' => $permissions,
            'mustResetPassword' => false,
            'sessionVersion' => (int) $user->refreshCounter,
            'jti' => (string) Str::uuid(),
        ], (string) config('auth.jwt.access_secret'), '60m');
    }
}
