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

    public function test_list_and_exact_email_lookup_apply_super_admin_and_student_scopes(): void
    {
        $superAdmin = $this->createUser('root@example.test', 'admin', ['admin']);
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $student = $this->createUser('student.one@example.test', 'student');
        $otherStudent = $this->createUser('student.two@example.test', 'student');
        $admin = $this->createUser('staff@example.test', 'admin', ['folder_manager']);

        $this->withToken($this->token($manager))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $student, 'email' => 'student.one@example.test', 'role' => 'student'])
            ->assertJsonFragment(['id' => $otherStudent, 'email' => 'student.two@example.test'])
            ->assertJsonMissing(['id' => $admin]);

        $this->withToken($this->token($manager))
            ->getJson('/api/users?email=STUDENT.ONE%40EXAMPLE.TEST')
            ->assertOk()
            ->assertExactJson([[
                'id' => $student,
                'email' => 'student.one@example.test',
                'role' => 'student',
                'studentId' => DB::table('users')->where('id', $student)->value('studentId'),
            ]]);
        $this->withToken($this->token($manager))
            ->getJson('/api/users?email=staff%40example.test')
            ->assertOk()
            ->assertExactJson([]);
        $this->withToken($this->token($manager))
            ->getJson('/api/users?email=not-an-email')
            ->assertBadRequest();
        $this->withToken($this->token($manager))
            ->getJson('/api/users?email=%20student.one%40example.test%20')
            ->assertBadRequest();
        $this->withToken($this->token($admin))
            ->getJson('/api/users')
            ->assertForbidden();

        $this->withToken($this->token($superAdmin))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(5)
            ->assertJsonFragment(['id' => $admin, 'role' => 'admin']);
        $this->withToken($this->token($superAdmin))
            ->getJson('/api/users?email=staff%40example.test')
            ->assertOk()
            ->assertJsonPath('0.id', $admin);
    }

    public function test_user_creation_validates_role_and_password_and_returns_only_public_identity(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        $superAdmin = $this->createUser('root@example.test', 'admin', ['admin']);

        $this->withToken($this->token($manager))
            ->postJson('/api/users', [
                'email' => 'new@example.test',
                'password' => 'short-password',
                'role' => 'admin',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only super admins can create admin accounts');
        $this->withToken($this->token($manager))
            ->postJson('/api/users', ['email' => 'bad', 'password' => 'tiny'])
            ->assertBadRequest();
        $this->withToken($this->token($manager))
            ->postJson('/api/users', [
                'email' => '  new@example.test  ',
                'password' => 'long-enough-password',
            ])
            ->assertBadRequest();
        $this->withToken($this->token($manager))
            ->postJson('/api/users', [
                'email' => 'new@example.test',
                'password' => 'short-pass',
                'role' => 'student',
            ])
            ->assertBadRequest();
        $this->withToken($this->token($manager))
            ->postJson('/api/users', [
                'email' => 'new@example.test',
                'password' => 'long-enough-password',
                'role' => 'unknown',
            ])
            ->assertBadRequest();

        $createdStudent = $this->withToken($this->token($manager))
            ->postJson('/api/users', [
                'email' => 'New.Student@Example.Test',
                'password' => 'long-enough-password',
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'new.student@example.test')
            ->assertJsonPath('role', 'student')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('mustResetPassword');
        $studentId = $createdStudent->json('studentId');
        self::assertMatchesRegularExpression('/^STU-[A-F0-9]{16}$/', $studentId);
        $studentRow = DB::table('users')->where('id', $createdStudent->json('id'))->first();
        self::assertSame('student', $studentRow->role);
        self::assertTrue(Hash::check('long-enough-password', $studentRow->password));
        self::assertFalse((bool) $studentRow->must_reset_password);

        $createdAdmin = $this->withToken($this->token($superAdmin))
            ->postJson('/api/users', [
                'email' => 'New.Admin@Example.Test',
                'password' => 'long-enough-password',
                'role' => 'admin',
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'new.admin@example.test')
            ->assertJsonPath('role', 'admin')
            ->assertJsonMissingPath('studentId');
        $this->assertDatabaseHas('users', [
            'id' => $createdAdmin->json('id'),
            'role' => 'admin',
            'studentId' => null,
        ]);
    }

    public function test_partial_search_is_student_scoped_bounded_and_keeps_users_without_active_devices(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['password_manager']);
        $studentWithoutDevice = $this->createUser('target.learner@example.test', 'student');
        $studentWithDevice = $this->createUser('target.other@example.test', 'student');
        $staff = $this->createUser('target.staff@example.test', 'admin', ['folder_manager']);
        $this->createDevice($studentWithDevice, true);
        $this->createDevice($studentWithDevice, false);

        $search = $this->withToken($this->token($manager))
            ->getJson('/api/users/search?email=%20TARGET.%20')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.email', 'target.learner@example.test')
            ->assertJsonPath('0.devices', [])
            ->assertJsonPath('1.email', 'target.other@example.test')
            ->assertJsonCount(1, '1.devices')
            ->assertJsonPath('1.devices.0.id', DB::table('devices')->where('userId', $studentWithDevice)->where('isActive', true)->value('id'))
            ->assertJsonPath('1.devices.0.isActive', true)
            ->assertJsonMissingPath('1.password')
            ->assertJsonMissingPath('1.devices.1');
        self::assertStringNotContainsString($staff, json_encode($search->json(), JSON_THROW_ON_ERROR));

        $this->withToken($this->token($manager))
            ->getJson('/api/users/search?email=x')
            ->assertOk()
            ->assertExactJson([]);
        $this->withToken($this->token($manager))
            ->getJson('/api/users/search?email=ta')
            ->assertOk()
            ->assertJsonMissing(['id' => $staff]);
        $this->withToken($this->token($manager))
            ->getJson('/api/users/search')
            ->assertOk()
            ->assertExactJson([]);

        $folderManager = $this->createUser('folder@example.test', 'admin', ['folder_manager']);
        $this->withToken($this->token($folderManager))
            ->getJson('/api/users/search?email=target')
            ->assertForbidden();
    }

    public function test_search_limits_results_to_twenty_five_in_email_order(): void
    {
        $manager = $this->createUser('manager@example.test', 'admin', ['user_manager']);
        for ($index = 30; $index >= 1; $index--) {
            $this->createUser(sprintf('search%02d@example.test', $index), 'student');
        }

        $response = $this->withToken($this->token($manager))
            ->getJson('/api/users/search?email=search')
            ->assertOk()
            ->assertJsonCount(25);
        self::assertSame('search01@example.test', $response->json('0.email'));
        self::assertSame('search25@example.test', $response->json('24.email'));
    }

    private function createUser(string $email, string $role, array $permissions = []): string
    {
        static $passwordHash;
        $passwordHash ??= Hash::make('current-account-password');
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => $passwordHash,
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

    private function createDevice(string $userId, bool $isActive): string
    {
        $id = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => $id,
            'userId' => $userId,
            'deviceFingerprint' => 'fingerprint-'.$id,
            'name' => 'Student device',
            'publicKey' => 'public-key',
            'isActive' => $isActive,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

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
