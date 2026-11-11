<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use App\Services\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SubjectScopedAdminTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-subject-delete-'.Str::uuid();
        config(['filesystems.private_root' => $this->privateRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateRoot);
        parent::tearDown();
    }

    public function test_team_subject_scope_limits_discovery_queries_and_id_based_test_and_notebook_access(): void
    {
        $owner = $this->createUser('owner@example.test', 'admin', ['admin']);
        $manager = $this->createUser('scoped@example.test', 'admin', [
            'teacher_assistant',
            'testing_manager',
            'user_manager',
            'student_query',
        ]);
        $subjectA = $this->createSubject('BIO', 'Biology');
        $subjectB = $this->createSubject('CHEM', 'Chemistry');
        $studentA = $this->createUser('bio.student@example.test', 'student');
        $studentB = $this->createUser('chem.student@example.test', 'student');
        $this->grantStudentSubject($studentA, $subjectA);
        $this->grantStudentSubject($studentA, $subjectB);
        $this->grantStudentSubject($studentB, $subjectB);

        $createdMember = $this->withToken($this->token($owner))
            ->postJson('/api/team-members', [
                'email' => 'created.scoped@example.test',
                'password' => 'initial-admin-password',
                'permissions' => ['testing_manager', 'user_manager'],
                'subjectIds' => [$subjectA],
            ])
            ->assertCreated()
            ->assertJsonPath('subjectIds.0', $subjectA);
        $createdId = (string) $createdMember->json('id');
        $this->assertDatabaseHas('team_member_subject_access', [
            'user_id' => $createdId,
            'subject_id' => $subjectA,
            'granted_by_user_id' => $owner,
        ]);

        $this->withToken($this->token($owner))
            ->putJson('/api/team-members/'.$createdId.'/permissions', [
                'permissions' => ['testing_manager', 'user_manager'],
                'subjectIds' => [$subjectB],
            ])
            ->assertOk()
            ->assertJsonPath('subjectIds.0', $subjectB);
        $this->assertDatabaseMissing('team_member_subject_access', [
            'user_id' => $createdId,
            'subject_id' => $subjectA,
        ]);

        $testA = $this->createTest($subjectA, 'Biology test');
        $testB = $this->createTest($subjectB, 'Chemistry test');
        $this->createAttempt($testA, $studentA, true);
        $this->createAttempt($testB, $studentB, true);
        $this->createQuery($studentA, 'Biology question');
        $chemistryQueryId = $this->createQuery($studentB, 'Chemistry question');
        $sourceB = $this->createNotebookSource($subjectB, $owner);

        $managerToken = $this->token($manager);
        DB::table('team_member_subject_access')->insert([
            'user_id' => $manager,
            'subject_id' => $subjectA,
            'granted_by_user_id' => $owner,
            'created_at' => now(),
        ]);
        $this->withToken($managerToken)->getJson('/api/subjects/list')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $subjectA);
        $this->withToken($managerToken)->getJson('/api/ai-notebook/subjects')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $subjectA);
        $this->withToken($managerToken)->getJson('/api/users')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $studentA);
        $this->withToken($managerToken)->getJson('/api/users/search?email=chem.student')
            ->assertOk()->assertExactJson([]);
        $this->withToken($managerToken)->getJson('/api/tests')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.subjectId', $subjectA);
        $this->withToken($managerToken)->getJson('/api/tests/'.$testB.'/attempts')
            ->assertForbidden();
        $this->withToken($managerToken)->getJson('/api/tests/access-requests')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.testId', $testA);
        $this->withToken($managerToken)->putJson('/api/tests/'.$testB.'/marks/'.$studentB, [
            'marksObtained' => 5,
        ])->assertForbidden();
        $this->withToken($managerToken)->putJson('/api/tests/'.$testB.'/deadline/'.$studentB, [
            'deadlineAt' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();
        $this->withToken($managerToken)->getJson('/api/ai-notebook/subjects/'.$subjectB.'/sources')
            ->assertForbidden();
        $this->withToken($managerToken)->getJson('/api/ai-notebook/sources/'.$sourceB.'/chunks')
            ->assertForbidden();
        $this->withToken($managerToken)->getJson('/api/student-queries')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.user.id', $studentA);
        $this->withToken($managerToken)->postJson('/api/student-queries/'.$chemistryQueryId.'/reply', [
            'response' => 'Not in your subject scope',
        ])->assertNotFound();
        $this->withToken($managerToken)->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->assertJsonPath('totalStudents', 1)
            ->assertJsonPath('totalSubjects', 1)
            ->assertJsonPath('totalTests', 1)
            ->assertJsonPath('studentQueries', 1);
        $this->withToken($managerToken)->putJson('/api/users/'.$studentB.'/subjects', [
            'subjectIds' => [$subjectB],
        ])->assertForbidden();

        // A legacy team member without scope rows retains the prior full subject listing.
        $legacy = $this->createUser('legacy.team@example.test', 'admin', ['teacher_assistant']);
        $this->withToken($this->token($legacy))->getJson('/api/subjects/list')
            ->assertOk()->assertJsonCount(2);
    }

    public function test_subject_deletion_cascades_database_records_preserves_audit_and_removes_private_files(): void
    {
        $owner = $this->createUser('owner@example.test', 'admin', ['admin']);
        $student = $this->createUser('student@example.test', 'student');
        $teamMember = $this->createUser('teacher@example.test', 'admin', ['teacher_assistant']);
        $subjectId = $this->createSubject('DELETE', 'Subject to remove');
        $this->grantStudentSubject($student, $subjectId);
        DB::table('team_member_subject_access')->insert([
            'user_id' => $teamMember,
            'subject_id' => $subjectId,
            'granted_by_user_id' => $owner,
            'created_at' => now(),
        ]);
        $testId = $this->createTest($subjectId, 'Test to remove');
        $attemptId = $this->createAttempt($testId, $student, false);

        /** @var PrivateFileStorage $storage */
        $storage = app(PrivateFileStorage::class);
        $testSourceKey = $storage->store(UploadedFile::fake()->create('test.pdf', 1), 'test-sources');
        $attemptFileKey = $storage->store(UploadedFile::fake()->create('answer.pdf', 1), 'test-attempts');
        $notebookSourceKey = $storage->store(UploadedFile::fake()->create('source.txt', 1), 'ai-notebook');
        $ticketImageKey = $storage->store(UploadedFile::fake()->create('question.png', 1), 'ai-notebook');
        DB::table('test_source_files')->insert([
            'id' => (string) Str::uuid(),
            'test_id' => $testId,
            'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'storage_key' => $testSourceKey,
            'created_at' => now(),
        ]);
        DB::table('test_attempt_files')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'original_name' => 'answer.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1,
            'storage_key' => $attemptFileKey,
            'created_at' => now(),
        ]);
        $sourceId = (string) Str::uuid();
        DB::table('ai_notebook_sources')->insert([
            'id' => $sourceId,
            'subject_id' => $subjectId,
            'title' => 'Source',
            'original_name' => 'source.txt',
            'mime_type' => 'text/plain',
            'storage_key' => $notebookSourceKey,
            'uploaded_by_user_id' => $owner,
            'created_at' => now(),
        ]);
        DB::table('ai_notebook_chunks')->insert([
            'id' => (string) Str::uuid(),
            'source_id' => $sourceId,
            'ordinal' => 0,
            'content' => 'Indexed source content',
            'embedding' => '[0.1,0.2,0.3]',
            'embedding_model' => 'test-model',
            'created_at' => now(),
        ]);
        $sessionId = (string) Str::uuid();
        DB::table('ai_notebook_chat_sessions')->insert([
            'id' => $sessionId,
            'subject_id' => $subjectId,
            'user_id' => $student,
            'title' => 'Chat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_notebook_chat_messages')->insert([
            'id' => (string) Str::uuid(),
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Question',
            'citations' => '[]',
            'created_at' => now(),
        ]);
        DB::table('ai_notebook_chat_tickets')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $student,
            'client_request_id' => (string) Str::uuid(),
            'subject_id' => $subjectId,
            'question' => 'Question',
            'image_storage_key' => $ticketImageKey,
            'status' => 'pending',
            'queued_at' => now(),
            'last_checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_notebook_prompts')->insert([
            'subject_id' => $subjectId,
            'prompt' => 'Default',
            'updated_at' => now(),
        ]);
        DB::table('ai_notebook_user_prompts')->insert([
            'subject_id' => $subjectId,
            'user_id' => $student,
            'prompt' => 'Personal',
            'updated_at' => now(),
        ]);

        $this->withToken($this->token($owner))->deleteJson('/api/subjects/'.$subjectId)
            ->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('subjects', ['id' => $subjectId]);
        $this->assertDatabaseMissing('tests', ['id' => $testId]);
        $this->assertDatabaseMissing('test_attempts', ['id' => $attemptId]);
        $this->assertDatabaseMissing('test_attempt_files', ['attempt_id' => $attemptId]);
        $this->assertDatabaseMissing('test_source_files', ['test_id' => $testId]);
        $this->assertDatabaseMissing('ai_notebook_sources', ['id' => $sourceId]);
        $this->assertDatabaseMissing('ai_notebook_chunks', ['source_id' => $sourceId]);
        $this->assertDatabaseMissing('ai_notebook_chat_sessions', ['id' => $sessionId]);
        $this->assertDatabaseMissing('ai_notebook_chat_messages', ['session_id' => $sessionId]);
        $this->assertDatabaseMissing('ai_notebook_chat_tickets', ['subject_id' => $subjectId]);
        $this->assertDatabaseMissing('ai_notebook_prompts', ['subject_id' => $subjectId]);
        $this->assertDatabaseMissing('ai_notebook_user_prompts', ['subject_id' => $subjectId]);
        $this->assertDatabaseMissing('subject_user_access', ['subject_id' => $subjectId]);
        $this->assertDatabaseMissing('team_member_subject_access', ['subject_id' => $subjectId]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'notebook_subject_deleted',
            'userId' => $owner,
        ]);
        foreach ([$testSourceKey, $attemptFileKey, $notebookSourceKey, $ticketImageKey] as $key) {
            self::assertFileDoesNotExist($storage->resolve($key));
        }
    }

    public function test_subject_deletion_keeps_database_records_when_private_file_cleanup_fails(): void
    {
        $owner = $this->createUser('owner@example.test', 'admin', ['admin']);
        $subjectId = $this->createSubject('RETRY', 'Retry deletion');
        DB::table('ai_notebook_sources')->insert([
            'id' => (string) Str::uuid(),
            'subject_id' => $subjectId,
            'title' => 'Source',
            'original_name' => 'source.txt',
            'mime_type' => 'text/plain',
            'storage_key' => 'ai-notebook/failed.txt',
            'created_at' => now(),
        ]);
        $storage = \Mockery::mock(PrivateFileStorage::class);
        $storage->shouldReceive('delete')
            ->once()
            ->with('ai-notebook/failed.txt')
            ->andThrow(new RuntimeException('Storage unavailable'));
        $this->app->instance(PrivateFileStorage::class, $storage);

        $this->withToken($this->token($owner))
            ->deleteJson('/api/subjects/'.$subjectId)
            ->assertServerError()
            ->assertJsonPath(
                'message',
                'Subject data was not deleted completely because a stored file could not be removed. Resolve the storage issue and retry.'
            );

        $this->assertDatabaseHas('subjects', ['id' => $subjectId]);
        $this->assertDatabaseHas('ai_notebook_sources', [
            'subject_id' => $subjectId,
            'storage_key' => 'ai-notebook/failed.txt',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'notebook_subject_deleted',
            'userId' => $owner,
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

    private function createSubject(string $code, string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function grantStudentSubject(string $studentId, string $subjectId): void
    {
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $studentId,
            'created_at' => now(),
        ]);
    }

    private function createTest(string $subjectId, string $title): string
    {
        $id = (string) Str::uuid();
        DB::table('tests')->insert([
            'id' => $id,
            'title' => $title,
            'totalMarks' => 10,
            'subject_id' => $subjectId,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createAttempt(string $testId, string $studentId, bool $requestAccess): string
    {
        $id = (string) Str::uuid();
        DB::table('test_attempts')->insert([
            'id' => $id,
            'testId' => $testId,
            'studentId' => $studentId,
            'status' => $requestAccess ? 'REQUESTED' : 'NOT_ATTEMPTED',
            'access_requested_at' => $requestAccess ? now() : null,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createQuery(string $studentId, string $title): string
    {
        $id = (string) Str::uuid();
        DB::table('student_queries')->insert([
            'id' => $id,
            'userId' => $studentId,
            'title' => $title,
            'message' => 'Help',
            'status' => 'open',
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createNotebookSource(string $subjectId, string $uploaderId): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_notebook_sources')->insert([
            'id' => $id,
            'subject_id' => $subjectId,
            'title' => 'Scoped source',
            'original_name' => 'source.txt',
            'mime_type' => 'text/plain',
            'storage_key' => 'ai-notebook/not-found.txt',
            'uploaded_by_user_id' => $uploaderId,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $permissions = DB::table('user_role_assignments')->where('userId', $userId)->orderBy('permission')->pluck('permission')->all();

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
