<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_schools_in_name_order_and_create_with_audit(): void
    {
        $actor = $this->createAdmin('owner@example.test', ['admin']);
        $this->insertSchool('Zeta School');
        $this->insertSchool('Alpha School');

        $this->withToken($this->token($actor))
            ->getJson('/api/school/list')
            ->assertOk()
            ->assertJsonPath('0.name', 'Alpha School')
            ->assertJsonPath('1.name', 'Zeta School');

        $response = $this->withToken($this->token($actor))
            ->postJson('/api/school', [
                'name' => 'North Campus',
                'address' => '12 Main Street',
                'phone' => '+1 555 0100',
                'logoUrl' => 'https://example.test/logo.png',
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'name', 'address', 'phone', 'logoUrl', 'createdAt', 'updatedAt'])
            ->assertJsonPath('name', 'North Campus')
            ->assertJsonPath('logoUrl', 'https://example.test/logo.png');

        $schoolId = $response->json('id');
        $this->assertDatabaseHas('schools', [
            'id' => $schoolId,
            'name' => 'North Campus',
            'logo_url' => 'https://example.test/logo.png',
        ]);
        $audit = DB::table('audit_logs')->where('event', 'school_created')->first();
        self::assertNotNull($audit);
        self::assertSame('North Campus', $audit->details);
        self::assertSame(
            ['actorId' => $actor, 'schoolId' => $schoolId],
            json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR)
        );
        self::assertNull($audit->userId);
    }

    public function test_school_routes_require_super_admin_permission(): void
    {
        $actor = $this->createAdmin('owner@example.test', []);
        $token = $this->token($actor);

        $this->withToken($token)->getJson('/api/school/list')->assertForbidden();
        $this->withToken($token)->postJson('/api/school', ['name' => 'Denied School'])->assertForbidden();
        $this->assertDatabaseMissing('schools', ['name' => 'Denied School']);
    }

    public function test_school_creation_applies_source_dto_validation(): void
    {
        $actor = $this->createAdmin('owner@example.test', ['admin']);

        $this->withToken($this->token($actor))
            ->postJson('/api/school', [
                'name' => 'x',
                'phone' => str_repeat('1', 33),
            ])
            ->assertBadRequest()
            ->assertJsonPath('statusCode', 400);

        $this->assertDatabaseCount('schools', 0);
    }

    private function createAdmin(string $email, array $permissions): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('current-account-password'),
            'role' => 'admin',
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

    private function insertSchool(string $name): void
    {
        DB::table('schools')->insert([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $permissions = DB::table('user_role_assignments')
            ->where('userId', $userId)
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
