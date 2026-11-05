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

    public function test_login_limiter_allows_different_emails_from_one_shared_ip(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->postJson('/api/auth/login', [
                'email' => "student{$index}@example.test",
                'password' => 'not-the-password',
            ])->assertUnauthorized();
        }
    }

    public function test_login_limiter_blocks_sixth_attempt_for_same_email_and_ip(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->postJson('/api/auth/login', [
                'email' => 'target@example.test',
                'password' => 'not-the-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'target@example.test',
            'password' => 'not-the-password',
        ])->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['statusCode', 'message', 'error']);
    }

    public function test_refresh_limiter_is_per_user_and_returns_retry_after(): void
    {
        $userIds = [];
        for ($index = 1; $index <= 11; $index++) {
            $userId = (string) Str::uuid();
            $userIds[] = $userId;
            $this->postJson('/api/auth/refresh', [
                'userId' => $userId,
                'refreshToken' => str_repeat('x', 32),
            ])->assertUnauthorized();
        }

        $limitedUserId = $userIds[0];
        for ($index = 0; $index < 9; $index++) {
            $this->postJson('/api/auth/refresh', [
                'userId' => $limitedUserId,
                'refreshToken' => str_repeat('x', 32),
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/refresh', [
            'userId' => $limitedUserId,
            'refreshToken' => str_repeat('x', 32),
        ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_authenticated_api_budget_is_per_user_and_returns_retry_after(): void
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => 'reader@example.test',
            'password' => Hash::make('correct-horse-battery'),
            'role' => 'student',
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        $token = $this->postJson('/api/auth/login', [
            'email' => 'reader@example.test',
            'password' => 'correct-horse-battery',
        ])->assertCreated()->json('accessToken');

        for ($index = 0; $index < 120; $index++) {
            $this->withToken($token)->getJson('/api/leaderboard')->assertOk();
        }

        $this->withToken($token)->getJson('/api/leaderboard')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['statusCode', 'message', 'error']);
    }
}
