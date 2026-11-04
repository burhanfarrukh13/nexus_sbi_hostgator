<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubjectsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_subjects_are_standalone_and_manage_notebook_and_test_organization(): void
    {
        $admin = $this->createUser('admin@example.test', ['teacher_assistant']);
        $token = $this->token($admin);

        $created = $this->withToken($token)
            ->postJson('/api/subjects', [
                'code' => 'BIO-1',
                'name' => 'Biology',
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'code', 'name', 'createdAt', 'updatedAt'])
            ->assertJsonPath('name', 'Biology');

        $subjectId = $created->json('id');
        $this->withToken($token)
            ->getJson('/api/subjects/list')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $subjectId);

        $this->withToken($token)
            ->getJson('/api/ai-notebook/subjects')
            ->assertOk()
            ->assertJsonFragment(['id' => $subjectId, 'name' => 'Biology']);
        $this->withToken($token)
            ->getJson("/api/ai-notebook/subjects/{$subjectId}/sources")
            ->assertOk()
            ->assertExactJson([]);
        $this->withToken($token)
            ->putJson("/api/ai-notebook/subjects/{$subjectId}/prompt/default", [
                'prompt' => 'Use concise explanations.',
                'responseLanguage' => 'english',
            ])
            ->assertOk()
            ->assertJsonPath('prompt', 'Use concise explanations.');
        $this->withToken($token)
            ->getJson("/api/ai-notebook/subjects/{$subjectId}/prompt")
            ->assertOk()
            ->assertJsonPath('prompt', 'Use concise explanations.');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'notebook_subject_created',
            'details' => 'Biology',
        ]);

        $this->withToken($token)
            ->putJson('/api/subjects/'.$subjectId, ['name' => 'Biology II'])
            ->assertOk()
            ->assertJsonPath('name', 'Biology II');

        $this->withToken($token)
            ->deleteJson('/api/subjects/'.$subjectId)
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_subject_creation_requires_permissions_and_duplicate_codes_are_rejected(): void
    {
        $student = $this->createUser('student@example.test', [], 'student');
        $this->withToken($this->token($student))
            ->postJson('/api/subjects', ['code' => 'SCI-1', 'name' => 'Science'])
            ->assertForbidden();

        $admin = $this->createUser('manager@example.test', ['teacher_assistant']);
        $token = $this->token($admin);
        $this->withToken($token)
            ->postJson('/api/subjects', ['code' => 'SCI-1', 'name' => 'Science'])
            ->assertCreated();
        $this->withToken($token)
            ->postJson('/api/subjects', ['code' => 'SCI-1', 'name' => 'Science Duplicate'])
            ->assertBadRequest()
            ->assertJsonPath('message', 'A subject with code "SCI-1" already exists.');
    }

    public function test_student_can_save_personal_notebook_prompt_and_response_language(): void
    {
        $student = $this->createUser('prompt-student@example.test', [], 'student');
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'LANG-1',
            'name' => 'Languages',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $student,
            'created_at' => now(),
        ]);
        DB::table('ai_notebook_prompts')->insert([
            'subject_id' => $subjectId,
            'prompt' => 'Use the subject default.',
            'response_language' => 'english',
            'updated_at' => now(),
        ]);

        $this->withToken($this->token($student))
            ->getJson("/api/ai-notebook/subjects/{$subjectId}/prompt")
            ->assertOk()
            ->assertJsonPath('prompt', 'Use the subject default.')
            ->assertJsonPath('responseLanguage', 'english');
        $this->withToken($this->token($student))
            ->putJson("/api/ai-notebook/subjects/{$subjectId}/prompt/personal", [
                'prompt' => 'Explain using simple examples.',
                'responseLanguage' => 'roman_urdu',
            ])
            ->assertOk()
            ->assertJsonPath('prompt', 'Explain using simple examples.')
            ->assertJsonPath('responseLanguage', 'roman_urdu');
        $this->withToken($this->token($student))
            ->getJson("/api/ai-notebook/subjects/{$subjectId}/prompt")
            ->assertOk()
            ->assertJsonPath('prompt', 'Explain using simple examples.')
            ->assertJsonPath('responseLanguage', 'roman_urdu')
            ->assertJsonPath('defaultPrompt', 'Use the subject default.');

        $otherStudent = $this->createUser('unassigned@example.test', [], 'student');
        $this->withToken($this->token($otherStudent))
            ->putJson("/api/ai-notebook/subjects/{$subjectId}/prompt/personal", [
                'prompt' => 'Attempt to modify.',
                'responseLanguage' => 'urdu',
            ])
            ->assertForbidden();

        self::assertSame(
            'roman_urdu',
            DB::table('ai_notebook_user_prompts')
                ->where('subject_id', $subjectId)
                ->where('user_id', $student)
                ->value('response_language')
        );
    }

    private function createUser(string $email, array $permissions, string $role = 'admin'): string
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
