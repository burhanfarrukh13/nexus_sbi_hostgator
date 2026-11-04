<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentQueriesAndLeaderboardCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_queries_are_private_and_admin_access_requires_handler_permission(): void
    {
        $student = $this->createUser('student@example.test', [], 'student');
        $otherStudent = $this->createUser('other@example.test', [], 'student');
        $admin = $this->createUser('support@example.test', ['student_query']);
        $unprivilegedAdmin = $this->createUser('admin@example.test', []);

        $created = $this->withToken($this->token($student))
            ->postJson('/api/student-queries', [
                'title' => '  Help with lessons  ',
                'message' => '  I need clarification.  ',
            ])
            ->assertCreated()
            ->assertJsonPath('title', '  Help with lessons  ')
            ->assertJsonPath('message', '  I need clarification.  ')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('user.email', 'student@example.test')
            ->assertJsonMissingPath('user.password');
        $queryId = $created->json('id');

        $this->withToken($this->token($student))
            ->getJson('/api/student-queries')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $queryId)
            ->assertJsonMissingPath('0.user');
        $this->withToken($this->token($otherStudent))
            ->getJson('/api/student-queries')
            ->assertOk()
            ->assertExactJson([]);
        $this->withToken($this->token($otherStudent))
            ->getJson('/api/student-queries/'.$queryId)
            ->assertForbidden()
            ->assertJsonPath('message', 'Not allowed');

        $this->withToken($this->token($unprivilegedAdmin))
            ->getJson('/api/student-queries')
            ->assertForbidden()
            ->assertJsonPath('message', 'Student query permission is required');
        $this->withToken($this->token($admin))
            ->getJson('/api/student-queries')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user.email', 'student@example.test')
            ->assertJsonPath('0.respondedBy', null);
        $this->withToken($this->token($admin))
            ->getJson('/api/student-queries/'.$queryId)
            ->assertOk()
            ->assertJsonPath('user.id', $student);
        $this->withToken($this->token($student))
            ->getJson('/api/student-queries/not-a-uuid')
            ->assertBadRequest();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'student_query_created',
            'details' => "Query {$queryId} created",
            'userId' => $student,
        ]);
        $audit = DB::table('audit_logs')->where('event', 'student_query_created')->first();
        self::assertSame([
            'userId' => $student,
            'queryId' => $queryId,
        ], json_decode($audit->metadata, true));
    }

    public function test_query_dto_validation_and_admin_reply_permissions_and_side_effects(): void
    {
        $student = $this->createUser('student@example.test', [], 'student');
        $admin = $this->createUser('support@example.test', ['student_query']);
        $unprivilegedAdmin = $this->createUser('admin@example.test', []);
        $queryId = $this->createQuery($student);

        $this->withToken($this->token($student))
            ->postJson('/api/student-queries', ['title' => '', 'message' => 'message'])
            ->assertBadRequest();
        $this->withToken($this->token($student))
            ->postJson('/api/student-queries', ['title' => str_repeat('t', 201), 'message' => 'message'])
            ->assertBadRequest();
        $this->withToken($this->token($student))
            ->postJson('/api/student-queries', ['title' => 'title', 'message' => str_repeat('m', 4001)])
            ->assertBadRequest();
        $this->withToken($this->token($student))
            ->postJson('/api/student-queries/'.$queryId.'/reply', ['response' => 'unauthorized'])
            ->assertForbidden();
        $this->withToken($this->token($unprivilegedAdmin))
            ->postJson('/api/student-queries/'.$queryId.'/reply', ['response' => 'unauthorized'])
            ->assertForbidden();

        $this->withToken($this->token($admin))
            ->postJson('/api/student-queries/'.$queryId.'/reply', ['response' => 'Please see the lesson notes.'])
            ->assertCreated()
            ->assertJsonPath('response', 'Please see the lesson notes.')
            ->assertJsonPath('status', 'responded')
            ->assertJsonPath('user.id', $student)
            ->assertJsonPath('respondedBy.email', 'support@example.test');
        $this->assertDatabaseHas('student_queries', [
            'id' => $queryId,
            'response' => 'Please see the lesson notes.',
            'status' => 'responded',
            'respondedById' => $admin,
        ]);
        $audit = DB::table('audit_logs')
            ->where('event', 'student_query_responded')
            ->where('userId', $admin)
            ->first();
        self::assertSame("Query {$queryId} responded", $audit->details);
        self::assertSame([
            'queryId' => $queryId,
            'adminId' => $admin,
        ], json_decode($audit->metadata, true));
    }

    public function test_leaderboard_ranks_completed_appeared_attempts_and_hides_peer_scores(): void
    {
        $currentUser = $this->createUser('current@example.test', [], 'student');
        $peerUser = $this->createUser('peer@example.test', [], 'student');
        $excludedUser = $this->createUser('excluded@example.test', [], 'student');
        $this->createAttempt($peerUser, 97, 100, true, 'COMPLETED');
        $this->createAttempt($currentUser, 40, 50, true, 'COMPLETED');
        $this->createAttempt($currentUser, 50, 50, true, 'COMPLETED');
        $this->createAttempt($excludedUser, 100, 100, false, 'COMPLETED');
        $this->createAttempt($excludedUser, 100, 100, true, 'IN_PROGRESS');
        $this->createAttempt($excludedUser, null, 100, true, 'COMPLETED');

        $response = $this->withToken($this->token($currentUser))
            ->getJson('/api/leaderboard')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.rank', 1)
            ->assertJsonPath('0.displayIdentifier', 'Learner 1')
            ->assertJsonPath('0.isCurrentUser', false)
            ->assertJsonPath('1.rank', 2)
            ->assertJsonPath('1.displayIdentifier', 'You')
            ->assertJsonPath('1.isCurrentUser', true)
            ->assertJsonPath('1.totalMarks', 90)
            ->assertJsonPath('1.maxMarks', 100)
            ->assertJsonPath('1.testsAttempted', 2)
            ->assertJsonPath('1.percentage', 90);

        $result = $response->json();
        self::assertArrayNotHasKey('studentId', $result[0]);
        self::assertArrayNotHasKey('totalMarks', $result[0]);
        self::assertArrayNotHasKey('maxMarks', $result[0]);
        self::assertArrayNotHasKey('testsAttempted', $result[0]);
        self::assertStringNotContainsString($peerUser, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('peer@example.test', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('97', json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function createQuery(string $userId): string
    {
        $id = (string) Str::uuid();
        DB::table('student_queries')->insert([
            'id' => $id,
            'userId' => $userId,
            'title' => 'Existing question',
            'message' => 'Existing message',
            'status' => 'open',
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createAttempt(string $studentId, ?float $marks, float $totalMarks, bool $appeared, string $status): void
    {
        $testId = (string) Str::uuid();
        DB::table('tests')->insert([
            'id' => $testId,
            'title' => 'Practice test',
            'totalMarks' => $totalMarks,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('test_attempts')->insert([
            'id' => (string) Str::uuid(),
            'testId' => $testId,
            'studentId' => $studentId,
            'appeared' => $appeared,
            'marksObtained' => $marks,
            'status' => $status,
            'createdAt' => now(),
            'updatedAt' => now(),
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
            'studentId' => $role === 'student' ? 'STU-'.strtoupper(str_replace('-', '', $id)) : null,
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
