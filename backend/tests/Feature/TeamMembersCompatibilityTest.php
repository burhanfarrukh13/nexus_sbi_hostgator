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
        $existing = $this->createUser('existing@example.test', 'admin', ['folder_manager']);
        $this->createUser('student@example.test', 'student');

        $this->withToken($this->token($actor))
            ->getJson('/api/team-members')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment([
                'id' => $existing,
                'email' => 'existing@example.test',
                'permissions' => ['folder_manager'],
            ]);

        $response = $this->withToken($this->token($actor))
            ->postJson('/api/team-members', [
                'email' => 'New.Member@example.test',
                'password' => 'initial-admin-password',
                'fullName' => 'New Member',
                'phone' => '+1 555 0100',
                'permissions' => ['password_manager', 'folder_manager'],
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'email', 'fullName', 'phone', 'permissions'])
            ->assertJsonPath('email', 'new.member@example.test')
            ->assertJsonPath('permissions', ['password_manager', 'folder_manager'])
            ->assertJsonMissingPath('password');

        $createdId = $response->json('id');
        $this->assertDatabaseHas('users', [
            'id' => $createdId,
            'email' => 'new.member@example.test',
            'role' => 'admin',
        ]);
        $this->assertSame(
            ['folder_manager', 'password_manager'],
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
            ->assertJsonPath('message', 'Permissions not available in the web portal: accountant.');
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
        $member = $this->createUser('member@example.test', 'admin', ['folder_manager', 'user_manager']);
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
        $target = $this->createUser('other.admin@example.test', 'admin', ['folder_manager']);
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

    public function test_super_admin_must_prove_their_password_to_reset_non_super_admin_team_member(): void
    {
        $actor = $this->createUser('owner@example.test', 'admin', ['admin'], 'owner-current-password');
        $target = $this->createUser('member@example.test', 'admin', ['folder_manager']);
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
