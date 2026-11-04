<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use App\Services\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TestSubmissionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private array $storedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('filesystems.private_root', storage_path('framework/testing/test-submissions'));
    }

    protected function tearDown(): void
    {
        $storage = app(PrivateFileStorage::class);
        foreach ($this->storedKeys as $key) {
            $storage->delete($key);
        }
        parent::tearDown();
    }

    public function test_student_submission_persists_answers_and_private_file_metadata(): void
    {
        [$student, $test] = $this->createAccessibleTest();
        $response = $this->withToken($this->token($student))
            ->post('/api/tests/'.$test.'/me/submit', [
                'answers' => json_encode([[
                    'questionNumber' => 1,
                    'answerText' => '  4  ',
                    'answerDelta' => [['insert' => '4']],
                ]], JSON_THROW_ON_ERROR),
                'files' => [UploadedFile::fake()->createWithContent('question-1.txt', 'answer attachment')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('status', 'SUBMITTED');

        $attemptId = $response->json('attemptId');
        $this->assertDatabaseHas('test_attempts', [
            'id' => $attemptId,
            'testId' => $test,
            'studentId' => $student,
            'appeared' => 1,
            'status' => 'SUBMITTED',
            'submission_mode' => 'PER_QUESTION',
        ]);
        $attempt = DB::table('test_attempts')->where('id', $attemptId)->first();
        $answers = json_decode($attempt->submitted_answers, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('4', $answers[0]['answerText']);
        self::assertSame([['insert' => '4']], $answers[0]['answerDelta']);
        self::assertCount(1, $answers[0]['fileIds']);

        $file = DB::table('test_attempt_files')->where('attempt_id', $attemptId)->first();
        self::assertSame('question-1.txt', $file->original_name);
        self::assertSame(1, (int) $file->question_number);
        self::assertNull($file->file_data);
        self::assertSame('text/plain', $file->mime_type);
        $this->storedKeys[] = $file->storage_key;
        $path = app(PrivateFileStorage::class)->resolve($file->storage_key);
        self::assertSame('answer attachment', file_get_contents($path));
        self::assertStringNotContainsString(realpath(public_path()), $path);
        self::assertNotNull($attempt->submitted_at);
    }

    public function test_submission_requires_every_question_and_enforces_deadline_and_entitlement(): void
    {
        [$student, $test] = $this->createAccessibleTest();
        $this->withToken($this->token($student))
            ->post('/api/tests/'.$test.'/me/submit', [
                'answers' => '[]',
            ], ['Accept' => 'application/json'])
            ->assertBadRequest()
            ->assertJsonPath('message', 'Submit an answer or an answer file for every question.');
        $this->assertDatabaseMissing('test_attempts', ['testId' => $test, 'studentId' => $student]);

        DB::table('tests')->where('id', $test)->update(['deadline_at' => now()->subMinute()]);
        $this->withToken($this->token($student))
            ->post('/api/tests/'.$test.'/me/submit', [
                'answers' => json_encode([['questionNumber' => 1, 'answerText' => '4']], JSON_THROW_ON_ERROR),
            ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('message', 'The test deadline has passed.');

        DB::table('tests')->where('id', $test)->update(['deadline_at' => now()->addDay()]);
        DB::table('folder_permissions')->where('userId', $student)->update(['billing_suspended' => true]);
        $this->withToken($this->token($student))
            ->post('/api/tests/'.$test.'/me/submit', [
                'answers' => json_encode([['questionNumber' => 1, 'answerText' => '4']], JSON_THROW_ON_ERROR),
            ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertJsonPath('message', 'No active folder access for this test.');
    }

    public function test_resubmit_checks_attempt_ownership_state_and_requires_a_real_grader(): void
    {
        [$student, $test] = $this->createAccessibleTest();
        $otherStudent = $this->createUser();
        $attemptId = (string) Str::uuid();
        DB::table('test_attempts')->insert([
            'id' => $attemptId,
            'testId' => $test,
            'studentId' => $student,
            'appeared' => true,
            'status' => 'FAILED',
            'submitted_at' => now()->subHour(),
            'submitted_answers' => json_encode([['questionNumber' => 1, 'answerText' => '4', 'fileIds' => []]], JSON_THROW_ON_ERROR),
            'createdAt' => now()->subHour(),
            'updatedAt' => now()->subHour(),
        ]);
        $folderId = DB::table('test_folders')->where('testId', $test)->value('folderId');
        DB::table('folder_permissions')->insert([
            'id' => (string) Str::uuid(),
            'userId' => $otherStudent,
            'folderId' => $folderId,
            'canView' => true,
            'billing_suspended' => false,
            'createdAt' => now(),
        ]);

        $this->withToken($this->token($otherStudent))
            ->postJson('/api/tests/'.$test.'/me/attempts/'.$attemptId.'/resubmit')
            ->assertNotFound()
            ->assertJsonPath('message', 'Test attempt not found.');
        $this->withToken($this->token($student))
            ->postJson('/api/tests/'.$test.'/me/attempts/'.$attemptId.'/resubmit')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Assessment grading is not available in this deployment.');
        $this->assertDatabaseHas('test_attempts', [
            'id' => $attemptId,
            'status' => 'FAILED',
        ]);

        DB::table('test_attempts')->where('id', $attemptId)->update(['status' => 'COMPLETED']);
        $this->withToken($this->token($student))
            ->postJson('/api/tests/'.$test.'/me/attempts/'.$attemptId.'/resubmit')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Only a failed submission with saved answers can be resubmitted.');
    }

    private function createAccessibleTest(): array
    {
        $student = $this->createUser();
        $folder = (string) Str::uuid();
        $test = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $folder,
            'name' => 'Test submissions',
            'default_price' => 0,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('tests')->insert([
            'id' => $test,
            'title' => 'Submission test',
            'totalMarks' => '2.00',
            'deadline_at' => now()->addDay(),
            'questions' => json_encode([[
                'questionNumber' => 1,
                'questionText' => 'What is 2 + 2?',
                'idealAnswer' => '4',
                'markingScheme' => [['criterion' => 'Correct answer', 'marks' => 2]],
                'marks' => 2,
            ]], JSON_THROW_ON_ERROR),
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('test_folders')->insert(['testId' => $test, 'folderId' => $folder]);
        DB::table('folder_permissions')->insert([
            'id' => (string) Str::uuid(),
            'userId' => $student,
            'folderId' => $folder,
            'canView' => true,
            'billing_suspended' => false,
            'createdAt' => now(),
        ]);

        return [$student, $test];
    }

    private function createUser(): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => 'submission-'.Str::uuid().'@example.test',
            'password' => Hash::make('submission-password'),
            'role' => 'student',
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();

        return app(JwtTokenService::class)->issue([
            'sub' => $userId,
            'email' => $user->email,
            'role' => 'student',
            'adminPermissions' => [],
            'mustResetPassword' => false,
            'sessionVersion' => 0,
            'jti' => (string) Str::uuid(),
        ], (string) config('auth.jwt.access_secret'), '60m');
    }
}
