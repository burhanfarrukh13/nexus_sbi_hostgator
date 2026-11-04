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

    public function test_subject_create_maps_class_creates_folder_and_audits(): void
    {
        $actor = $this->createUser('owner@example.test', ['folder_manager']);
        [$schoolId, $classId, $sectionId] = $this->createAcademicScope();

        $response = $this->withToken($this->token($actor))
            ->postJson('/api/subjects', [
                'schoolId' => $schoolId,
                'classId' => $classId,
                'sectionId' => $sectionId,
                'code' => 'MATH-1',
                'name' => 'Mathematics',
                'defaultPrice' => 24.5,
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'schoolId', 'classId', 'sectionId', 'code', 'name', 'createdAt', 'updatedAt'])
            ->assertJsonPath('name', 'Mathematics');

        $subjectId = $response->json('id');
        $this->assertDatabaseHas('class_subjects', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);
        $this->assertDatabaseHas('folders', [
            'subject_id' => $subjectId,
            'name' => 'Mathematics',
            'default_price' => '24.50',
        ]);
        $folderId = DB::table('folders')->where('subject_id', $subjectId)->value('id');
        $this->withToken($this->token($actor))
            ->getJson('/api/ai-notebook/folders')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $folderId,
                'subjectId' => $subjectId,
            ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'subject_created',
            'details' => 'Mathematics',
        ]);

        $this->withToken($this->token($actor))
            ->getJson('/api/subjects/list?schoolId='.$schoolId.'&classId='.$classId.'&sectionId='.$sectionId)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $subjectId);
    }

    public function test_subject_update_mapping_and_teacher_assignment_are_source_shaped(): void
    {
        $actor = $this->createUser('owner@example.test', ['folder_manager']);
        $teacher = $this->createUser('teacher@example.test', []);
        [$schoolId, $classId, $sectionId] = $this->createAcademicScope();
        $subjectId = $this->insertSubject($schoolId, 'ENG-1', 'English');
        $secondClassId = (string) Str::uuid();
        DB::table('classes')->insert([
            'id' => $secondClassId,
            'school_id' => $schoolId,
            'numeric_level' => 2,
            'display_name' => 'Grade 2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->token($actor);
        $this->withToken($token)
            ->putJson('/api/subjects/'.$subjectId, ['name' => 'English Language'])
            ->assertOk()
            ->assertJsonPath('name', 'English Language');

        $this->withToken($token)
            ->postJson('/api/subjects/'.$subjectId.'/map-class', ['classId' => $secondClassId])
            ->assertCreated()
            ->assertJson(['ok' => true]);
        $this->withToken($token)
            ->getJson('/api/subjects/list?classId='.$secondClassId)
            ->assertOk()
            ->assertJsonFragment(['id' => $subjectId]);

        $this->withToken($token)
            ->postJson('/api/subjects/'.$subjectId.'/assign-teacher', [
                'classId' => $classId,
                'sectionId' => $sectionId,
                'userId' => $teacher,
            ])
            ->assertCreated()
            ->assertJson(['ok' => true]);
        $this->withToken($token)
            ->postJson('/api/subjects/'.$subjectId.'/assign-teacher', [
                'classId' => $classId,
                'sectionId' => $sectionId,
                'userId' => $teacher,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('subject_teachers', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'subject_teacher_assigned',
            'details' => $subjectId,
        ]);
    }

    public function test_subject_guards_and_duplicate_code_validation(): void
    {
        $owner = $this->createUser('owner@example.test', ['admin']);
        $teacher = $this->createUser('teacher@example.test', ['teacher_assistant']);
        [$schoolId, ,] = $this->createAcademicScope();
        $this->insertSubject($schoolId, 'SCI-1', 'Science');

        $this->withToken($this->token($teacher))
            ->getJson('/api/subjects/list?schoolId='.$schoolId)
            ->assertOk();
        $this->withToken($this->token($teacher))
            ->postJson('/api/subjects', [
                'schoolId' => $schoolId,
                'code' => 'SCI-2',
                'name' => 'Science 2',
            ])
            ->assertForbidden();

        $this->withToken($this->token($owner))
            ->postJson('/api/subjects', [
                'schoolId' => $schoolId,
                'code' => 'SCI-1',
                'name' => 'Science Duplicate',
            ])
            ->assertBadRequest()
            ->assertJsonPath('message', 'A subject with code "SCI-1" already exists for this school.');

        $this->withToken($this->token($owner))
            ->postJson('/api/subjects', [
                'schoolId' => $schoolId,
                'code' => 'SCI-2',
                'name' => 'Science 2',
                'defaultPrice' => 1.234,
            ])
            ->assertBadRequest();
    }

    private function createAcademicScope(): array
    {
        $schoolId = (string) Str::uuid();
        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Nexus School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classId = (string) Str::uuid();
        DB::table('classes')->insert([
            'id' => $classId,
            'school_id' => $schoolId,
            'numeric_level' => 1,
            'display_name' => 'Grade 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (string) Str::uuid();
        DB::table('sections')->insert([
            'id' => $sectionId,
            'class_id' => $classId,
            'name' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$schoolId, $classId, $sectionId];
    }

    private function insertSubject(string $schoolId, string $code, string $name): string
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

        return $id;
    }

    private function createUser(string $email, array $permissions): string
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
