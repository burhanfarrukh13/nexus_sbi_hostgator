<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoldersCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_list_create_update_and_delete_match_source_contract(): void
    {
        $actor = $this->createUser('folder.manager@example.test', ['folder_manager']);
        $schoolId = $this->createSchool();
        $subjectId = $this->createSubject($schoolId, 'ENG', 'English');
        $token = $this->token($actor);

        $created = $this->withToken($token)
            ->postJson('/api/folders', [
                'name' => 'Revision',
                'description' => 'Exam material',
                'subjectId' => $subjectId,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'name', 'description', 'defaultPrice', 'subjectId', 'createdAt', 'updatedAt'])
            ->assertJsonPath('name', 'Revision')
            ->assertJsonPath('subjectId', $subjectId);
        $folderId = $created->json('id');

        $this->withToken($token)
            ->getJson('/api/folders?subjectId='.$subjectId)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $folderId, 'name' => 'Revision']);

        $this->withToken($token)
            ->putJson('/api/folders/'.$folderId, [
                'name' => 'Final Revision',
                'description' => '',
                'coverImageUrl' => '/storage/folder-covers/manual.png',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Final Revision')
            ->assertJsonPath('description', '')
            ->assertJsonPath('coverImageUrl', '/storage/folder-covers/manual.png');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'folder_created',
            'details' => "Folder {$folderId} created",
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'folder_updated',
            'details' => "Folder {$folderId} updated",
        ]);

        $this->withToken($this->token($this->createUser('super@example.test', ['admin'])))
            ->deleteJson('/api/folders/'.$folderId)
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('folders', ['id' => $folderId]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'folder_deleted',
            'details' => "Folder {$folderId} deleted",
        ]);
    }

    public function test_folder_permission_assignment_is_idempotent_and_authorized_listing_filters_rows(): void
    {
        $actor = $this->createUser('user.manager@example.test', ['user_manager']);
        $student = $this->createUser('student@example.test', [], 'student');
        $folderId = $this->createFolder('Open Folder', 0);
        $otherFolderId = $this->createFolder('Other Folder', 0);
        $suspendedFolderId = $this->createFolder('Suspended Folder', 0);
        $token = $this->token($actor);

        $first = $this->withToken($token)
            ->postJson('/api/folders/'.$folderId.'/permissions', ['userId' => $student])
            ->assertCreated()
            ->assertJsonPath('canView', true);
        $permissionId = $first->json('id');
        $second = $this->withToken($token)
            ->postJson('/api/folders/'.$folderId.'/permissions', ['userId' => $student])
            ->assertCreated();
        self::assertSame($permissionId, $second->json('id'));

        $this->createPermission($student, $otherFolderId, false, false);
        $this->createPermission($student, $suspendedFolderId, true, true);

        $authorized = $this->withToken($this->token($student))
            ->getJson('/api/folders/authorized')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $folderId)
            ->assertJsonStructure(['*' => ['lectures']]);
        self::assertCount(1, $authorized->json());

        $listed = $this->withToken($this->token($actor))
            ->getJson('/api/folders/permissions?userId='.$student)
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['id' => $folderId])
            ->assertJsonFragment(['id' => $student]);
        self::assertCount(3, $listed->json());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'folder_permission_assigned',
            'userId' => $student,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/folders/'.$otherFolderId.'/permissions/'.$permissionId)
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->assertDatabaseMissing('folder_permissions', ['id' => $permissionId]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'folder_permission_revoked',
            'userId' => $student,
        ]);
    }

    public function test_finance_and_billing_guards_match_folder_rules(): void
    {
        $folderManager = $this->createUser('folder@example.test', ['folder_manager']);
        $accountant = $this->createUser('fees@example.test', ['accountant_fees']);
        $userManager = $this->createUser('users@example.test', ['user_manager']);
        $paidFolderId = $this->createFolder('Paid Folder', 20);
        $openFolderId = $this->createFolder('Open Folder', 0);

        $this->withToken($this->token($folderManager))
            ->postJson('/api/folders', ['name' => 'Priced', 'defaultPrice' => 5])
            ->assertForbidden();

        $this->withToken($this->token($accountant))
            ->putJson('/api/folders/'.$openFolderId, ['defaultPrice' => 8.5])
            ->assertOk()
            ->assertJsonPath('defaultPrice', '8.50');
        $this->withToken($this->token($accountant))
            ->putJson('/api/folders/'.$openFolderId, ['name' => 'Not allowed'])
            ->assertForbidden();

        $this->withToken($this->token($userManager))
            ->postJson('/api/folders/'.$paidFolderId.'/permissions', ['userId' => $folderManager])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A billing plan is required for this folder. Use the folder billing access workflow.');
        $this->withToken($this->token($folderManager))
            ->deleteJson('/api/folders/'.$paidFolderId)
            ->assertForbidden();

        DB::table('folder_access_plans')->insert([
            'id' => (string) Str::uuid(),
            'folder_id' => $openFolderId,
        ]);
        $superAdmin = $this->createUser('owner@example.test', ['admin']);
        $this->withToken($this->token($superAdmin))
            ->deleteJson('/api/folders/'.$openFolderId)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This folder has billing history and cannot be deleted. Revoke access instead.');
    }

    public function test_folder_routes_enforce_permission_sets_and_source_validation(): void
    {
        $folderManager = $this->createUser('folder@example.test', ['folder_manager']);
        $userManager = $this->createUser('users@example.test', ['user_manager']);
        $folderId = $this->createFolder('Existing', 0);
        $token = $this->token($folderManager);

        $this->withToken($token)
            ->postJson('/api/folders', ['name' => ''])
            ->assertBadRequest();
        $this->withToken($token)
            ->postJson('/api/folders/'.$folderId.'/permissions', ['userId' => 'not-a-uuid'])
            ->assertBadRequest();
        $this->withToken($token)
            ->getJson('/api/folders')
            ->assertOk();
        $this->withToken($token)
            ->getJson('/api/folders/permissions?userId='.$folderManager)
            ->assertForbidden();
        $this->withToken($this->token($userManager))
            ->deleteJson('/api/folders/'.$folderId)
            ->assertForbidden();
        $this->withToken($token)
            ->deleteJson('/api/folders/permissions/'.(string) Str::uuid())
            ->assertNotFound();
        $this->withToken($token)
            ->putJson('/api/folders/'.(string) Str::uuid(), ['name' => 'Unknown'])
            ->assertNotFound();
    }

    private function createSchool(): string
    {
        $id = (string) Str::uuid();
        DB::table('schools')->insert([
            'id' => $id,
            'name' => 'Nexus School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createSubject(string $schoolId, string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $id,
            'school_id' => $schoolId,
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('folders')->insert([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'description' => null,
            'default_price' => '0.00',
            'coverImageUrl' => null,
            'subject_id' => $id,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createFolder(string $name, float $price): string
    {
        $id = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $id,
            'name' => $name,
            'description' => null,
            'default_price' => number_format($price, 2, '.', ''),
            'coverImageUrl' => null,
            'subject_id' => null,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createPermission(string $userId, string $folderId, bool $canView, bool $suspended): void
    {
        DB::table('folder_permissions')->insert([
            'id' => (string) Str::uuid(),
            'userId' => $userId,
            'folderId' => $folderId,
            'canView' => $canView,
            'billing_suspended' => $suspended,
            'createdAt' => now(),
        ]);
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
