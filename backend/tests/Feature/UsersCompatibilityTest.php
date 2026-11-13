<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsersCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_manager_can_list_search_and_create_student_accounts(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $student = $this->createUser('student.one@example.test', 'student');
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'SCI-1',
            'name' => 'Science',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($this->token($manager))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $student);

        $this->withToken($this->token($manager))
            ->getJson('/api/users/search?email=student')
            ->assertOk()
            ->assertJsonPath('0.id', $student)
            ->assertJsonMissingPath('0.password');

        $this->withToken($this->token($manager))
            ->postJson('/api/users', [
                'role' => 'student',
                'fullName' => 'New Student',
                'email' => 'new.student@example.test',
                'phone' => '+1-555-0101',
                'crn' => 'CRN-1001',
                'subjectIds' => [$subjectId],
            ])
            ->assertCreated()
            ->assertJsonPath('role', 'student')
            ->assertJsonPath('fullName', 'New Student')
            ->assertJsonPath('email', 'new.student@example.test')
            ->assertJsonPath('studentId', 'CRN-1001')
            ->assertJsonPath('phone', '+1-555-0101')
            ->assertJsonPath('subjectIds.0', $subjectId)
            ->assertJsonPath('mustResetPassword', true)
            ->assertJsonMissingPath('password');

        $created = DB::table('users')->where('email', 'new.student@example.test')->first();
        $this->assertNotNull($created);
        $this->assertSame('New Student', $created->fullName);
        $this->assertSame('CRN-1001', $created->studentId);
        $this->assertSame('+1-555-0101', $created->phone);
        $this->assertSame(1, (int) $created->must_reset_password);
        $this->assertTrue(Hash::check('abc@123', $created->password));
        $this->assertDatabaseHas('subject_user_access', [
            'subject_id' => $subjectId,
            'user_id' => $created->id,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'new.student@example.test',
            'password' => 'abc@123',
        ])->assertCreated()
            ->assertJsonPath('user.mustResetPassword', true);
        $this->withToken((string) $login->json('accessToken'))
            ->getJson('/api/subjects/list')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_RESET_REQUIRED');
        $this->withToken((string) $login->json('accessToken'))
            ->postJson('/api/auth/change-password', [
                'currentPassword' => 'abc@123',
                'newPassword' => 'student-new-password',
            ])
            ->assertCreated();
        $studentLogin = $this->postJson('/api/auth/login', [
            'email' => 'new.student@example.test',
            'password' => 'student-new-password',
        ])->assertCreated()
            ->assertJsonPath('user.mustResetPassword', false);

        $this->withToken($this->token($manager))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonFragment([
                'email' => 'new.student@example.test',
                'fullName' => 'New Student',
                'role' => 'student',
            ]);

        $this->withToken((string) $studentLogin->json('accessToken'))
            ->getJson('/api/subjects/list')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $subjectId);
    }

    public function test_user_manager_can_replace_student_subject_access(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $student = $this->createUser('student@example.test', 'student');
        $subjectIds = [];
        foreach (['BIO-1', 'CHEM-1'] as $index => $code) {
            $subjectIds[] = (string) Str::uuid();
            DB::table('subjects')->insert([
                'id' => $subjectIds[$index],
                'code' => $code,
                'name' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->withToken($this->token($manager))
            ->putJson("/api/users/{$student}/subjects", ['subjectIds' => [$subjectIds[0]]])
            ->assertOk()
            ->assertJsonPath('subjectIds.0', $subjectIds[0]);

        $this->withToken($this->token($student))
            ->getJson('/api/ai-notebook/subjects')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $subjectIds[0]);

        $this->withToken($this->token($manager))
            ->putJson("/api/users/{$student}/subjects", ['subjectIds' => []])
            ->assertOk()
            ->assertJsonPath('subjectIds', []);

        $this->withToken($this->token($student))
            ->getJson('/api/ai-notebook/subjects')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_user_manager_can_edit_student_details_without_changing_subject_access_or_password(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $student = $this->createUser('student@example.test', 'student');
        $originalHash = DB::table('users')->where('id', $student)->value('password');
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'EDIT-1',
            'name' => 'Editable subject',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $student,
            'created_at' => now(),
        ]);

        $this->withToken($this->token($manager))
            ->putJson("/api/users/{$student}", [
                'fullName' => 'Updated Student',
                'email' => 'updated.student@example.test',
                'phone' => '+1-555-0222',
                'crn' => 'CRN-2026-EDIT',
            ])
            ->assertOk()
            ->assertJsonPath('id', $student)
            ->assertJsonPath('fullName', 'Updated Student')
            ->assertJsonPath('email', 'updated.student@example.test')
            ->assertJsonPath('phone', '+1-555-0222')
            ->assertJsonPath('studentId', 'CRN-2026-EDIT');

        $this->assertDatabaseHas('users', [
            'id' => $student,
            'fullName' => 'Updated Student',
            'email' => 'updated.student@example.test',
            'phone' => '+1-555-0222',
            'studentId' => 'CRN-2026-EDIT',
        ]);
        $this->assertSame($originalHash, DB::table('users')->where('id', $student)->value('password'));
        $this->assertDatabaseHas('subject_user_access', [
            'subject_id' => $subjectId,
            'user_id' => $student,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'student_account_updated',
            'userId' => $manager,
        ]);
    }

    public function test_student_detail_edits_validate_unique_email_and_crn(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $student = $this->createUser('student@example.test', 'student');
        $otherStudent = $this->createUser('other@example.test', 'student');

        $this->withToken($this->token($manager))
            ->putJson("/api/users/{$student}", [
                'fullName' => 'Student Name',
                'email' => 'other@example.test',
                'phone' => '+1-555-0222',
                'crn' => DB::table('users')->where('id', $otherStudent)->value('studentId'),
            ])
                ->assertStatus(400)
                ->assertJsonPath('error', 'Bad Request');
    }

    public function test_user_manager_cannot_edit_a_student_outside_assigned_subjects(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $subjectIds = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ($subjectIds as $index => $subjectId) {
            DB::table('subjects')->insert([
                'id' => $subjectId,
                'code' => 'SCOPE-'.$index,
                'name' => 'Scope '.$index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('team_member_subject_access')->insert([
            'user_id' => $manager,
            'subject_id' => $subjectIds[0],
        ]);
        $student = $this->createUser('student.outside@example.test', 'student');
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectIds[1],
            'user_id' => $student,
            'created_at' => now(),
        ]);

        $this->withToken($this->token($manager))
            ->putJson("/api/users/{$student}", [
                'fullName' => 'Changed Name',
                'email' => 'changed@example.test',
                'phone' => '+1-555-0222',
                'crn' => 'CRN-CHANGED',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $student,
            'email' => 'student.outside@example.test',
        ]);
    }

    private function createUser(string $email, string $role, array $permissions = []): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('current-account-password'),
            'role' => $role,
            'studentId' => $role === 'student' ? 'STU-'.strtoupper(substr(str_replace('-', '', $id), 0, 16)) : null,
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
