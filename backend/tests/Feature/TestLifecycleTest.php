<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-test-lifecycle-'.Str::uuid();
        config(['filesystems.private_root' => $this->privateRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->privateRoot)) {
            $this->removeDirectory($this->privateRoot);
        }
        parent::tearDown();
    }

    public function test_admin_can_update_test_title(): void
    {
        $fixture = $this->fixture();

        $this->withToken($this->token($fixture['admin']))
            ->putJson("/api/tests/{$fixture['test']}", ['title' => 'Updated test name'])
            ->assertOk()
            ->assertJsonPath('title', 'Updated test name');

        self::assertSame(
            'Updated test name',
            DB::table('tests')->where('id', $fixture['test'])->value('title')
        );
    }

    public function test_students_and_out_of_scope_team_members_cannot_mutate_tests(): void
    {
        $fixture = $this->fixture();
        $otherSubjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $otherSubjectId,
            'code' => 'OUT-1',
            'name' => 'Out of scope',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scopedAdmin = $this->createUser('scoped@example.test', 'admin', ['teacher_assistant']);
        DB::table('team_member_subject_access')->insert([
            'user_id' => $scopedAdmin,
            'subject_id' => $otherSubjectId,
            'created_at' => now(),
        ]);

        $this->withToken($this->token($fixture['student']))
            ->putJson("/api/tests/{$fixture['test']}", ['title' => 'Denied'])
            ->assertForbidden();

        $this->withToken($this->token($scopedAdmin))
            ->putJson("/api/tests/{$fixture['test']}", ['title' => 'Out of scope'])
            ->assertForbidden();

        $this->withToken($this->token($scopedAdmin))
            ->deleteJson("/api/tests/{$fixture['test']}", ['mode' => 'archive'])
            ->assertForbidden();

        $this->withToken($this->token($scopedAdmin))
            ->deleteJson("/api/tests/{$fixture['test']}", ['mode' => 'permanent'])
            ->assertForbidden();

        $this->withToken($this->token($scopedAdmin))
            ->getJson("/api/tests/{$fixture['test']}/attempts")
            ->assertForbidden();

        self::assertNull(DB::table('tests')->where('id', $fixture['test'])->value('archived_at'));
    }

    public function test_archived_test_keeps_history_for_submitters_but_hides_it_from_other_students(): void
    {
        $fixture = $this->fixture();
        $nonSubmitter = $this->createUser('not-attempted@example.test', 'student');
        foreach ([$fixture['student'], $nonSubmitter] as $studentId) {
            DB::table('subject_user_access')->insert([
                'subject_id' => $fixture['subject'],
                'user_id' => $studentId,
                'granted_by_user_id' => $fixture['admin'],
                'created_at' => now(),
            ]);
        }
        DB::table('test_attempts')->insert([
            'id' => (string) Str::uuid(),
            'testId' => $fixture['test'],
            'studentId' => $fixture['student'],
            'appeared' => true,
            'marksObtained' => 2,
            'status' => 'COMPLETED',
            'submitted_at' => now()->subMinutes(5),
            'result_available_at' => now()->subMinute(),
            'submitted_answers' => json_encode([['questionNumber' => 1, 'answerText' => '4']]),
            'ai_assessment' => json_encode(['questions' => [['questionNumber' => 1, 'marksObtained' => 2]]]),
            'createdAt' => now()->subMinutes(10),
            'updatedAt' => now(),
        ]);

        $this->withToken($this->token($fixture['admin']))
            ->deleteJson("/api/tests/{$fixture['test']}", ['mode' => 'archive'])
            ->assertOk()
            ->assertJsonPath('id', $fixture['test'])
            ->assertJsonStructure(['archivedAt']);

        $this->withToken($this->token($fixture['student']))
            ->getJson('/api/tests/me/attempts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.test.id', $fixture['test'])
            ->assertJsonStructure(['0' => ['test' => ['archivedAt']]]);

        $this->withToken($this->token($fixture['student']))
            ->getJson("/api/tests/{$fixture['test']}/me")
            ->assertOk()
            ->assertJsonPath('attempt.marksObtained', 2);

        $this->withToken($this->token($fixture['admin']))
            ->getJson("/api/tests/{$fixture['test']}/attempts")
            ->assertOk()
            ->assertJsonCount(1);

        $this->withToken($this->token($nonSubmitter))
            ->getJson('/api/tests/me/attempts')
            ->assertOk()
            ->assertExactJson([]);

        $this->withToken($this->token($nonSubmitter))
            ->postJson("/api/tests/{$fixture['test']}/me/request-access")
            ->assertNotFound();

        $this->withToken($this->token($nonSubmitter))
            ->postJson("/api/tests/{$fixture['test']}/me/submit", ['answers' => []])
            ->assertBadRequest();

        self::assertSame(
            0,
            DB::table('test_attempts')
                ->where('testId', $fixture['test'])
                ->where('studentId', $nonSubmitter)
                ->count()
        );

        $this->withToken($this->token($nonSubmitter))
            ->getJson("/api/tests/{$fixture['test']}/me")
            ->assertNotFound();

        $this->withToken($this->token($fixture['student']))
            ->postJson("/api/tests/{$fixture['test']}/me/request-access")
            ->assertBadRequest();

        $this->withToken($this->token($fixture['student']))
            ->postJson("/api/tests/{$fixture['test']}/me/submit", [])
            ->assertBadRequest();
    }

    public function test_permanent_delete_removes_attempt_rows_and_private_files(): void
    {
        $fixture = $this->fixture();
        DB::table('subject_user_access')->insert([
            'subject_id' => $fixture['subject'],
            'user_id' => $fixture['student'],
            'granted_by_user_id' => $fixture['admin'],
            'created_at' => now(),
        ]);
        $attemptId = (string) Str::uuid();
        $sourceKey = 'test-sources/source-'.Str::uuid().'.pdf';
        $attemptKey = 'test-attempts/answer-'.Str::uuid().'.jpg';
        foreach ([$sourceKey, $attemptKey] as $key) {
            $path = $this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, 'private test data');
        }
        DB::table('test_source_files')->insert([
            'id' => (string) Str::uuid(),
            'test_id' => $fixture['test'],
            'original_name' => 'source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 17,
            'storage_key' => $sourceKey,
            'created_at' => now(),
        ]);
        DB::table('test_attempts')->insert([
            'id' => $attemptId,
            'testId' => $fixture['test'],
            'studentId' => $fixture['student'],
            'appeared' => true,
            'marksObtained' => 2,
            'status' => 'COMPLETED',
            'submitted_at' => now(),
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('test_attempt_files')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'question_number' => 1,
            'original_name' => 'answer.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 17,
            'storage_key' => $attemptKey,
            'created_at' => now(),
        ]);

        $this->withToken($this->token($fixture['admin']))
            ->deleteJson("/api/tests/{$fixture['test']}", ['mode' => 'permanent'])
            ->assertOk()
            ->assertJsonPath('deleted', true);

        self::assertSame(0, DB::table('tests')->where('id', $fixture['test'])->count());
        self::assertSame(0, DB::table('test_attempts')->where('testId', $fixture['test'])->count());
        self::assertSame(0, DB::table('test_source_files')->where('test_id', $fixture['test'])->count());
        self::assertSame(0, DB::table('test_attempt_files')->where('attempt_id', $attemptId)->count());
        self::assertFileDoesNotExist($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sourceKey));
        self::assertFileDoesNotExist($this->privateRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $attemptKey));
    }

    public function test_private_file_deletion_failure_does_not_delete_test_or_attempt_records(): void
    {
        $fixture = $this->fixture();
        $attemptId = (string) Str::uuid();
        $storageKey = 'test-sources/source-'.Str::uuid().'.pdf';
        DB::table('test_source_files')->insert([
            'id' => (string) Str::uuid(),
            'test_id' => $fixture['test'],
            'original_name' => 'source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 17,
            'storage_key' => $storageKey,
            'created_at' => now(),
        ]);
        DB::table('test_attempts')->insert([
            'id' => $attemptId,
            'testId' => $fixture['test'],
            'studentId' => $fixture['student'],
            'appeared' => true,
            'status' => 'COMPLETED',
            'submitted_at' => now(),
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        $storage = $this->mock(\App\Services\PrivateFileStorage::class);
        $storage->shouldReceive('delete')
            ->once()
            ->with($storageKey)
            ->andThrow(new \RuntimeException('Unable to delete private file.'));

        $this->withToken($this->token($fixture['admin']))
            ->deleteJson("/api/tests/{$fixture['test']}", ['mode' => 'permanent'])
            ->assertServerError();

        self::assertSame(1, DB::table('tests')->where('id', $fixture['test'])->count());
        self::assertSame(1, DB::table('test_attempts')->where('id', $attemptId)->count());
        self::assertSame(1, DB::table('test_source_files')->where('test_id', $fixture['test'])->count());
    }

    private function fixture(): array
    {
        $admin = $this->createUser('admin@example.test', 'admin', ['teacher_assistant']);
        $student = $this->createUser('student@example.test', 'student');
        $subject = (string) Str::uuid();
        $test = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subject,
            'code' => 'MATH-1',
            'name' => 'Mathematics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tests')->insert([
            'id' => $test,
            'title' => 'Lifecycle test',
            'totalMarks' => 2,
            'questions' => json_encode([[
                'questionNumber' => 1,
                'questionText' => 'What is 2 + 2?',
                'idealAnswer' => '4',
                'marks' => 2,
                'markingScheme' => [['criterion' => 'Correct answer', 'marks' => 2]],
            ]]),
            'createdById' => $admin,
            'subject_id' => $subject,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return compact('admin', 'student', 'subject', 'test');
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

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
