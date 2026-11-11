<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamMembersCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_and_create_team_members_with_audit(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin']);
        $existing = $this->createUser('existing@example.test', 'admin', ['teacher_assistant']);
        $this->createUser('student@example.test', 'student');

        $this->withToken($this->token($actor))
            ->getJson('/api/team-members')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'id' => $existing,
                'email' => 'existing@example.test',
                'permissions' => ['teacher_assistant'],
            ]);

        $response = $this->withToken($this->token($actor))
            ->postJson('/api/team-members', [
                'email' => 'New.Member@example.test',
                'password' => 'initial-admin-password',
                'fullName' => 'New Member',
                'phone' => '+1 555 0100',
                'permissions' => ['password_manager', 'teacher_assistant'],
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'email', 'fullName', 'phone', 'permissions'])
            ->assertJsonPath('email', 'new.member@example.test')
            ->assertJsonPath('permissions', ['password_manager', 'teacher_assistant'])
            ->assertJsonMissingPath('password');

        $createdId = $response->json('id');
        $this->assertDatabaseHas('users', [
            'id' => $createdId,
            'email' => 'new.member@example.test',
            'role' => 'admin',
        ]);
        $this->assertSame(
            ['password_manager', 'teacher_assistant'],
            DB::table('user_role_assignments')->where('userId', $createdId)->orderBy('permission')->pluck('permission')->all()
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'team_member_created',
            'userId' => $actor,
        ]);
    }

    public function test_team_member_creation_enforces_dto_and_web_permission_rules(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin']);
        $this->withToken($this->token($actor))
            ->postJson('/api/team-members', [
                'email' => 'invalid@example.test',
                'password' => 'short',
                'permissions' => ['admin', 'admin'],
            ])
            ->assertBadRequest()
            ->assertJsonStructure(['statusCode', 'message', 'error']);

        $this->withToken($this->token($actor))
            ->postJson('/api/team-members', [
                'email' => 'accountant@example.test',
                'password' => 'initial-admin-password',
                'permissions' => ['accountant'],
            ])
            ->assertBadRequest()
            ->assertJsonStructure(['statusCode', 'message', 'error']);
    }

    public function test_management_routes_require_super_admin_permission(): void
    {
        $actor = $this->createUser('manager@example.test', 'admin', ['password_manager']);

        $this->withToken($this->token($actor))
            ->getJson('/api/team-members')
            ->assertForbidden()
            ->assertJsonPath('statusCode', 403);

        $this->withToken($this->token($actor))
            ->putJson('/api/team-members/'.(string) Str::uuid().'/permissions', ['permissions' => []])
            ->assertForbidden();

        $this->withToken($this->token($actor))
            ->postJson('/api/team-members', [
                'email' => 'denied@example.test',
                'password' => 'initial-admin-password',
                'permissions' => [],
            ])
            ->assertForbidden();
    }

    public function test_permission_update_replaces_the_set_and_allows_revocation(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin']);
        $member = $this->createUser('member@example.test', 'admin', ['teacher_assistant', 'user_manager']);
        $token = $this->token($actor);

        $this->withToken($token)
            ->putJson('/api/team-members/'.$member.'/permissions', [
                'permissions' => ['testing_manager'],
            ])
            ->assertOk()
            ->assertJsonPath('id', $member)
            ->assertJsonPath('role', 'admin')
            ->assertJsonMissingPath('permissions');

        $this->assertSame(
            ['testing_manager'],
            DB::table('user_role_assignments')->where('userId', $member)->pluck('permission')->all()
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'team_member_permissions_changed',
            'userId' => $actor,
        ]);

        $this->withToken($token)
            ->putJson('/api/team-members/'.$member.'/permissions', ['permissions' => []])
            ->assertOk();
        $this->assertDatabaseMissing('user_role_assignments', ['userId' => $member]);
    }

    public function test_super_admin_can_edit_team_member_details_permissions_and_subject_scope(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin']);
        $member = $this->createUser('member@example.test', 'admin', ['teacher_assistant']);
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'EDIT-1',
            'name' => 'Editing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($this->token($actor))
            ->putJson('/api/team-members/'.$member, [
                'fullName' => 'Edited Member',
                'email' => 'edited@example.test',
                'phone' => '+1 555 0111',
                'permissions' => ['testing_manager', 'user_manager'],
                'subjectIds' => [$subjectId],
            ])
            ->assertOk()
            ->assertJsonPath('fullName', 'Edited Member')
            ->assertJsonPath('email', 'edited@example.test')
            ->assertJsonPath('phone', '+1 555 0111')
            ->assertJsonPath('permissions', ['testing_manager', 'user_manager'])
            ->assertJsonPath('subjectIds.0', $subjectId)
            ->assertJsonMissingPath('password');

        $this->assertDatabaseHas('users', [
            'id' => $member,
            'email' => 'edited@example.test',
            'fullName' => 'Edited Member',
            'phone' => '+1 555 0111',
        ]);
        $this->assertSame(
            ['testing_manager', 'user_manager'],
            DB::table('user_role_assignments')->where('userId', $member)->orderBy('permission')->pluck('permission')->all()
        );
        $this->assertDatabaseHas('team_member_subject_access', [
            'user_id' => $member,
            'subject_id' => $subjectId,
            'granted_by_user_id' => $actor,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'team_member_updated',
            'userId' => $actor,
        ]);
    }

    public function test_super_admin_can_delete_team_member_but_not_self_or_last_super_admin(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin']);
        $member = $this->createUser('member@example.test', 'admin', ['teacher_assistant']);
        $token = $this->token($actor);

        $this->withToken($token)
            ->deleteJson('/api/team-members/'.$member)
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('users', ['id' => $member]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'team_member_deleted',
            'userId' => $actor,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/team-members/'.$actor)
            ->assertBadRequest()
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $otherSuperAdmin = $this->createUser('second-owner@example.test', 'admin', ['admin']);
        $this->withToken($token)
            ->deleteJson('/api/team-members/'.$actor)
            ->assertBadRequest()
            ->assertJsonPath('message', 'You cannot delete your own account.');
        $this->withToken($this->token($otherSuperAdmin))
            ->deleteJson('/api/team-members/'.$actor)
            ->assertOk();
        $this->assertDatabaseHas('users', ['id' => $otherSuperAdmin]);
    }

    public function test_password_manager_can_reset_students_and_force_password_change(): void
    {
        $actor = $this->createUser('password.manager@example.test', 'admin', ['password_manager']);
        $student = $this->createUser('student@example.test', 'student');
        DB::table('users')->where('id', $student)->update([
            'refreshTokenHash' => str_repeat('a', 64),
            'previousRefreshTokenHash' => str_repeat('b', 64),
        ]);
        $oldStudentToken = $this->token($student);

        $this->withToken($this->token($actor))
            ->putJson('/api/team-members/'.$student.'/password', [
                'newPassword' => 'new-student-password',
            ])
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $student,
            'must_reset_password' => 1,
            'refreshTokenHash' => null,
            'previousRefreshTokenHash' => null,
        ]);
        self::assertTrue(Hash::check('new-student-password', DB::table('users')->where('id', $student)->value('password')));
        $this->withToken($oldStudentToken)->getJson('/api/auth/me')
            ->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'password_reset',
            'userId' => $actor,
        ]);
    }

    public function test_password_manager_cannot_reset_admins_or_their_own_account(): void
    {
        $actor = $this->createUser('password.manager@example.test', 'admin', ['password_manager']);
        $target = $this->createUser('other.admin@example.test', 'admin', ['teacher_assistant']);
        $token = $this->token($actor);

        $this->withToken($token)
            ->putJson('/api/team-members/'.$target.'/password', ['newPassword' => 'new-admin-password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Password managers may reset student accounts only');

        $this->withToken($token)
            ->putJson('/api/team-members/'.$actor.'/password', ['newPassword' => 'new-admin-password'])
            ->assertBadRequest()
            ->assertJsonPath('message', 'Use the self-service password change flow for your own account');
    }

    public function test_scoped_password_manager_can_reset_only_students_in_assigned_subjects(): void
    {
        $actor = $this->createUser('scoped.manager@example.test', 'admin', ['password_manager']);
        $allowedStudent = $this->createUser('allowed.student@example.test', 'student');
        $otherStudent = $this->createUser('other.student@example.test', 'student');
        $allowedSubject = (string) Str::uuid();
        $otherSubject = (string) Str::uuid();

        foreach ([[$allowedSubject, 'BIO'], [$otherSubject, 'CHEM']] as [$subjectId, $code]) {
            DB::table('subjects')->insert([
                'id' => $subjectId,
                'code' => $code,
                'name' => $code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('subject_user_access')->insert([
            [
                'subject_id' => $allowedSubject,
                'user_id' => $allowedStudent,
                'created_at' => now(),
            ],
            [
                'subject_id' => $otherSubject,
                'user_id' => $otherStudent,
                'created_at' => now(),
            ],
        ]);
        DB::table('team_member_subject_access')->insert([
            'user_id' => $actor,
            'subject_id' => $allowedSubject,
            'created_at' => now(),
        ]);
        $token = $this->token($actor);

        $this->withToken($token)
            ->putJson('/api/team-members/'.$allowedStudent.'/password', [
                'newPassword' => 'allowed-student-password',
            ])
            ->assertCreated()
            ->assertJson(['success' => true]);
        $this->withToken($token)
            ->putJson('/api/team-members/'.$otherStudent.'/password', [
                'newPassword' => 'other-student-password',
            ])
            ->assertForbidden();

        self::assertTrue(Hash::check(
            'allowed-student-password',
            DB::table('users')->where('id', $allowedStudent)->value('password')
        ));
        self::assertFalse(Hash::check(
            'other-student-password',
            DB::table('users')->where('id', $otherStudent)->value('password')
        ));
    }

    public function test_super_admin_must_prove_their_password_to_reset_non_super_admin_team_member(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin'], 'owner-current-password');
        $target = $this->createUser('member@example.test', 'admin', ['teacher_assistant']);
        $token = $this->token($actor);

        $this->withToken($token)
            ->putJson('/api/team-members/'.$target.'/password', ['newPassword' => 'new-member-password'])
            ->assertBadRequest()
            ->assertJsonPath('message', 'Current password is required to reset a team member account');

        $this->withToken($token)
            ->putJson('/api/team-members/'.$target.'/password', [
                'newPassword' => 'new-member-password',
                'currentPassword' => 'wrong-current-password',
            ])
            ->assertBadRequest()
            ->assertJsonPath('message', 'Current password is incorrect');

        $this->withToken($token)
            ->putJson('/api/team-members/'.$target.'/password', [
                'newPassword' => 'new-member-password',
                'currentPassword' => 'owner-current-password',
            ])
            ->assertCreated()
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('users', ['id' => $target, 'must_reset_password' => 1]);
    }

    private function createUser(string $email, string $role, array $permissions = [], string $password = 'current-account-password'): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make($password),
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
