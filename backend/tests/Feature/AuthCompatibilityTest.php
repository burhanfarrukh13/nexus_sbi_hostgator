<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_me_refresh_and_replay_detection_keep_token_contract(): void
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => 'admin@example.test',
            'password' => Hash::make('correct-horse-battery'),
            'role' => 'admin',
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('user_role_assignments')->insert([
            'userId' => $id,
            'granteeId' => $id,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => ' ADMIN@example.test ',
            'password' => 'correct-horse-battery',
        ])->assertCreated()
            ->assertJsonStructure(['accessToken', 'refreshToken', 'user' => ['id', 'email', 'role', 'adminPermissions']]);

        $oldRefresh = $login->json('refreshToken');
        $this->withToken($login->json('accessToken'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('userId', $id);

        $rotated = $this->postJson('/api/auth/refresh', [
            'userId' => $id,
            'refreshToken' => $oldRefresh,
        ])->assertCreated()->assertJsonStructure(['accessToken', 'refreshToken', 'user']);

        $this->postJson('/api/auth/refresh', [
            'userId' => $id,
            'refreshToken' => $oldRefresh,
        ])->assertUnauthorized();

        $this->withToken($rotated->json('accessToken'))
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_first_login_password_reset_blocks_other_api_routes(): void
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => 'reset@example.test',
            'password' => Hash::make('correct-horse-battery'),
            'role' => 'admin',
            'refreshCounter' => 0,
            'must_reset_password' => true,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'reset@example.test',
            'password' => 'correct-horse-battery',
        ])->assertCreated();
        $token = $login->json('accessToken');

        $this->withToken($token)->getJson('/api/storage/file')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_RESET_REQUIRED');
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/auth/change-password', [
            'currentPassword' => 'correct-horse-battery',
            'newPassword' => 'a-new-secret-password',
        ])->assertCreated()->assertJson(['success' => true]);
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_auth_validation_uses_nest_compatible_bad_request_status(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => 'short',
        ])->assertBadRequest()
            ->assertJsonStructure(['statusCode', 'message', 'error']);
    }
}
