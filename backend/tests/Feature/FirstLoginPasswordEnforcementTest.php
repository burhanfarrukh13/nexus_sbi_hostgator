<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

/**
 * Students are created with the shared initial password. These tests prove the server,
 * not the browser, forces them to replace it before anything else works.
 */
class FirstLoginPasswordEnforcementTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    private const INITIAL_PASSWORD = 'abc@123';

    private function createStudentThroughAdminApi(): string
    {
        $admin = $this->createUser('admin');
        DB::table('user_role_assignments')->insert([
            'userId' => $admin,
            'granteeId' => $admin,
            'permission' => 'admin',
            'grantedAt' => now(),
        ]);
        $this->withToken($this->token($admin))->postJson('/api/users', [
            'email' => 'new.student@example.test',
            'crn' => 'CRN-2026-001',
            'fullName' => 'New Student',
            'phone' => '03001234567',
            'subjectIds' => [],
        ])->assertCreated()->assertJsonPath('mustResetPassword', true);

        return 'new.student@example.test';
    }

    private function loginWithInitialPassword(string $email): string
    {
        return (string) $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::INITIAL_PASSWORD])
            ->assertCreated()
            ->assertJsonPath('user.mustResetPassword', true)
            ->json('accessToken');
    }

    public function test_a_new_student_cannot_use_any_service_until_the_password_is_changed(): void
    {
        $email = $this->createStudentThroughAdminApi();
        $token = $this->loginWithInitialPassword($email);

        foreach ([
            ['GET', '/api/tests/me/attempts'],
            ['GET', '/api/ai-notebook/subjects'],
            ['GET', '/api/leaderboard'],
            ['GET', '/api/student-queries'],
            ['GET', '/api/subjects/list'],
            ['POST', '/api/tests/00000000-0000-4000-8000-000000000000/me/submit'],
        ] as [$method, $path]) {
            $this->withToken($token)->json($method, $path)
                ->assertForbidden()
                ->assertJsonPath('code', 'PASSWORD_RESET_REQUIRED');
        }

        // Only the three password-reset routes stay reachable.
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_a_refreshed_session_is_still_locked_to_the_password_change(): void
    {
        $email = $this->createStudentThroughAdminApi();
        $login = $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::INITIAL_PASSWORD])
            ->assertCreated();

        $refreshed = $this->postJson('/api/auth/refresh', [
            'userId' => $login->json('user.id'),
            'refreshToken' => $login->json('refreshToken'),
        ])->assertCreated();

        $this->withToken($refreshed->json('accessToken'))->getJson('/api/tests/me/attempts')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_RESET_REQUIRED');
    }

    public function test_the_lock_follows_the_database_not_the_claims_in_an_old_token(): void
    {
        $studentId = $this->createUser();
        $token = $this->token($studentId);
        $this->withToken($token)->getJson('/api/tests/me/attempts')->assertOk();

        // An admin resets the password: the flag is set again but the old token says otherwise.
        DB::table('users')->where('id', $studentId)->update(['must_reset_password' => true]);

        $this->withToken($token)->getJson('/api/tests/me/attempts')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_RESET_REQUIRED');
    }

    public function test_the_initial_password_cannot_be_replaced_with_a_weak_one_and_current_password_is_not_required(): void
    {
        $token = $this->loginWithInitialPassword($this->createStudentThroughAdminApi());

        foreach (['abc@123', 'short', 'abc@1234567'] as $weak) {
            $this->withToken($token)->postJson('/api/auth/change-password', [
                'currentPassword' => self::INITIAL_PASSWORD,
                'newPassword' => $weak,
            ])->assertBadRequest();
        }
        $this->withToken($token)->postJson('/api/auth/change-password', [
            'newPassword' => 'a-long-new-password',
        ])->assertCreated()->assertJson(['success' => true]);
        $this->assertDatabaseHas('users', ['email' => 'new.student@example.test', 'must_reset_password' => 0]);
    }

    public function test_self_service_password_change_uses_only_the_new_password(): void
    {
        $studentId = $this->createUser();
        DB::table('users')->where('id', $studentId)->update([
            'password' => \Illuminate\Support\Facades\Hash::make('a-long-current-password'),
            'must_reset_password' => true,
        ]);

        $this->withToken($this->token($studentId))->postJson('/api/auth/change-password', [
            'newPassword' => 'a-long-current-password',
        ])->assertCreated()->assertJson(['success' => true]);
    }

    public function test_changing_the_password_unlocks_the_account_and_retires_the_initial_one(): void
    {
        $email = $this->createStudentThroughAdminApi();
        $oldToken = $this->loginWithInitialPassword($email);

        $this->withToken($oldToken)->postJson('/api/auth/change-password', [
            'currentPassword' => self::INITIAL_PASSWORD,
            'newPassword' => 'my-own-strong-password',
        ])->assertCreated()->assertJson(['success' => true])->assertJsonMissingPath('password');

        $this->withToken($oldToken)->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'password_changed',
            'userId' => DB::table('users')->where('email', $email)->value('id'),
        ]);
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => self::INITIAL_PASSWORD])
            ->assertUnauthorized();

        $fresh = $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'my-own-strong-password'])
            ->assertCreated()
            ->assertJsonPath('user.mustResetPassword', false);
        $this->withToken($fresh->json('accessToken'))->getJson('/api/tests/me/attempts')->assertOk();
    }

    public function test_password_change_attempts_are_rate_limited(): void
    {
        $studentId = $this->createUser();
        DB::table('users')->where('id', $studentId)->update(['must_reset_password' => true]);
        $token = $this->token($studentId);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withToken($token)->postJson('/api/auth/change-password', [
                'newPassword' => 'short',
            ])->assertBadRequest();
        }

        $this->withToken($token)->postJson('/api/auth/change-password', [
            'newPassword' => 'short',
        ])->assertStatus(429);
    }
}
