<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use App\Services\QuestionImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\PrivateFileStorage;
use Tests\TestCase;

class TestsAuthoringCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_grading_prompt_is_saved_to_mysql(): void
    {
        $admin = $this->createUser('grading-admin@example.test', 'admin', ['testing_manager']);
        $token = $this->token($admin);

        $this->withToken($token)
            ->putJson('/api/tests/ai-grading-settings', [
                'deficiencyReportPrompt' => 'Explain the strongest answer evidence and the next learning steps.',
            ])
            ->assertOk()
            ->assertJsonPath('deficiencyReportPrompt', 'Explain the strongest answer evidence and the next learning steps.');
        $this->assertDatabaseHas('ai_grading_settings', [
            'id' => 1,
            'deficiency_report_prompt' => 'Explain the strongest answer evidence and the next learning steps.',
        ]);
        $this->withToken($token)
            ->getJson('/api/tests/ai-grading-settings')
            ->assertOk()
            ->assertJsonPath('deficiencyReportPrompt', 'Explain the strongest answer evidence and the next learning steps.');
    }

    public function test_access_requests_are_listed_and_a_granted_deadline_updates_the_attempt(): void
    {
        $manager = $this->createUser('testing-manager@example.test', 'admin', ['testing_manager']);
        $student = $this->createUser('access-request-student@example.test', 'student');
        $folderId = $this->createFolder('Requested access');
        $testId = $this->createTest($folderId, now()->subHour()->toIso8601String());
        DB::table('folder_permissions')->insert([
            'id' => (string) Str::uuid(),
            'userId' => $student,
            'folderId' => $folderId,
            'canView' => true,
            'billing_suspended' => false,
            'createdAt' => now(),
        ]);
        $attemptId = $this->createAttempt($testId, $student, [
            'status' => 'REQUESTED',
            'access_requested_at' => now()->subMinute(),
        ]);
        $token = $this->token($manager);

        $this->withToken($token)
            ->getJson('/api/tests/access-requests')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.attemptId', $attemptId)
            ->assertJsonPath('0.studentId', $student)
            ->assertJsonPath('0.testId', $testId);

        $deadline = now()->addDay()->toIso8601String();
        $this->withToken($token)
            ->putJson("/api/tests/{$testId}/deadline/{$student}", ['deadlineAt' => $deadline])
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->assertDatabaseHas('test_attempts', [
            'id' => $attemptId,
            'status' => 'IN_PROGRESS',
            'access_requested_at' => null,
        ]);
        $this->withToken($token)->getJson('/api/tests/access-requests')->assertOk()->assertJsonCount(0);
    }

    public function test_notebook_sources_and_chat_are_persisted_and_use_the_configured_cloud_chat_api(): void
    {
        $admin = $this->createUser('notebook-manager@example.test', 'admin', ['folder_manager']);
        $folderId = $this->createFolder('Biology');
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-hostgator-test-'.Str::uuid();
        Config::set('filesystems.private_root', $root);
        Config::set('services.embeddings.url', '');
        Config::set('services.ai_chat.url', 'https://chat.example.test/v1/chat/completions');
        Config::set('services.ai_chat.key', 'test-key');
        Config::set('services.ai_chat.model', 'test-model');
        Http::fake(['chat.example.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'Photosynthesis converts light energy into chemical energy.']]],
        ])]);

        $token = $this->token($admin);
        $this->withToken($token)
            ->post('/api/ai-notebook/sources', [
                'folderId' => $folderId,
                'file' => UploadedFile::fake()->createWithContent(
                    'photosynthesis.txt',
                    'Photosynthesis converts light energy into chemical energy in plants.'
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('completedChunks', 1);
        $source = DB::table('ai_notebook_sources')->where('folder_id', $folderId)->first();
        self::assertNotNull($source);
        $this->assertDatabaseHas('ai_notebook_chunks', [
            'source_id' => $source->id,
            'embedding_model' => 'lexical',
        ]);

        $this->withToken($token)
            ->postJson('/api/ai-notebook/chat', [
                'folderId' => $folderId,
                'question' => 'What does photosynthesis do?',
            ])
            ->assertOk()
            ->assertJsonPath('answer', 'Photosynthesis converts light energy into chemical energy.')
            ->assertJsonStructure(['sessionId', 'citations' => [['sourceId', 'title', 'chunk']]]);
        $this->assertDatabaseCount('ai_notebook_chat_sessions', 1);
        $this->assertDatabaseCount('ai_notebook_chat_messages', 2);

        app(PrivateFileStorage::class)->delete($source->storage_key);
        rmdir($root.DIRECTORY_SEPARATOR.'ai-notebook');
        rmdir($root);
    }

    public function test_teacher_assistant_can_create_and_list_source_shaped_tests(): void
    {
        $actorId = $this->createUser('teacher@example.test', 'admin', ['teacher_assistant']);
        $schoolId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $sectionId = (string) Str::uuid();
        $folderId = (string) Str::uuid();
        DB::table('schools')->insert([
            'id' => $schoolId, 'name' => 'Authoring School', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('classes')->insert([
            'id' => $classId, 'school_id' => $schoolId, 'numeric_level' => 11,
            'display_name' => 'Class 11', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sections')->insert([
            'id' => $sectionId, 'class_id' => $classId, 'name' => 'B', 'capacity' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('folders')->insert([
            'id' => $folderId, 'name' => 'Accounting', 'default_price' => '0.00',
            'createdAt' => now(), 'updatedAt' => now(),
        ]);

        $created = $this->withToken($this->token($actorId))
            ->postJson('/api/tests', $this->testPayload($folderId, $classId, $sectionId))
            ->assertCreated()
            ->assertJsonPath('title', 'Midterm Examination')
            ->assertJsonPath('totalMarks', '2.50')
            ->assertJsonPath('createdBy.id', $actorId)
            ->assertJsonPath('class.displayName', 'Class 11')
            ->assertJsonPath('section.name', 'B')
            ->assertJsonPath('folders.0.id', $folderId)
            ->assertJsonPath('questions.0.questionNumber', 1)
            ->assertJsonPath('questions.0.markingScheme.0.criterion', 'Show calculation');
        $testId = $created->json('id');

        $this->assertDatabaseHas('test_folders', ['testId' => $testId, 'folderId' => $folderId]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'test_created',
            'details' => "Test {$testId} created",
            'userId' => $actorId,
        ]);

        $this->withToken($this->token($actorId))
            ->getJson('/api/tests')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $testId)
            ->assertJsonPath('0.sourceFiles', []);
    }

    public function test_test_authoring_enforces_permissions_folder_scope_deadline_and_mark_totals(): void
    {
        $teacher = $this->createUser('author@example.test', 'admin', ['teacher_assistant']);
        $other = $this->createUser('no-permission@example.test', 'admin');
        $folderId = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $folderId, 'name' => 'Required Folder', 'default_price' => 0,
            'createdAt' => now(), 'updatedAt' => now(),
        ]);

        $payload = $this->testPayload($folderId);
        $this->withToken($this->token($other))
            ->postJson('/api/tests', $payload)
            ->assertForbidden();
        $this->withToken($this->token($other))
            ->getJson('/api/tests')
            ->assertForbidden();

        $this->withToken($this->token($teacher))
            ->postJson('/api/tests', array_merge($payload, ['folderIds' => []]))
            ->assertBadRequest()
            ->assertJsonPath('message', 'Select at least one course folder for this test.');
        $this->withToken($this->token($teacher))
            ->postJson('/api/tests', array_merge($payload, ['deadlineAt' => null]))
            ->assertBadRequest()
            ->assertJsonPath('message', 'Set a deadline for every online test.');
        $question = $payload['questions'][0];
        $question['marks'] = 1;
        $question['markingScheme'][0]['marks'] = 1;
        $this->withToken($this->token($teacher))
            ->postJson('/api/tests', array_merge($payload, ['questions' => [$question]]))
            ->assertBadRequest()
            ->assertJsonPath('message', 'Total marks must equal the sum of question marks (1).');

        $this->assertDatabaseCount('tests', 0);
    }

    public function test_test_dto_rejects_numeric_strings_and_unknown_properties(): void
    {
        $teacher = $this->createUser('strict-author@example.test', 'admin', ['teacher_assistant']);
        $folderId = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $folderId, 'name' => 'Strict Validation Folder', 'default_price' => 0,
            'createdAt' => now(), 'updatedAt' => now(),
        ]);
        $payload = $this->testPayload($folderId);
        $payload['totalMarks'] = '2.5';

        $this->withToken($this->token($teacher))
            ->postJson('/api/tests', $payload)
            ->assertBadRequest();

        $payload['totalMarks'] = 2.5;
        $payload['unexpected'] = true;
        $this->withToken($this->token($teacher))
            ->postJson('/api/tests', $payload)
            ->assertBadRequest();

        $this->assertDatabaseCount('tests', 0);
    }

    public function test_question_import_parser_extracts_marked_sections_and_mark_allocations(): void
    {
        $parsed = app(QuestionImportParser::class)->parse([
            "-- 1 of 1 --\nQuestion #1 (Easy): Calculate the total.\nAnswer #1: 2 + 2 = 5\nMarking #1:\nShow calculation | 2"
        ], false);

        self::assertSame([], $parsed['errors']);
        self::assertSame('EASY', $parsed['groups'][0]['difficulty']);
        self::assertSame('Calculate the total.', $parsed['groups'][0]['questionText']);
        self::assertSame('2 + 2 = 5', $parsed['groups'][0]['solution']);
        self::assertSame([['criterion' => 'Show calculation', 'marks' => 2.0]], $parsed['groups'][0]['markingScheme']);

    }

    public function test_preview_route_requires_files_and_rejects_unsupported_extensions(): void
    {
        $teacher = $this->createUser('previewer@example.test', 'admin', ['teacher_assistant']);
        $token = $this->token($teacher);
        $this->withToken($token)->postJson('/api/tests/questions/preview')
            ->assertBadRequest()
            ->assertJsonPath('message', 'At least one PDF or DOCX file is required.');

        $this->withToken($token)
            ->post('/api/tests/questions/preview', [
                'files' => [UploadedFile::fake()->create('questions.txt', 1, 'text/plain')],
            ], ['Accept' => 'application/json'])
            ->assertBadRequest()
            ->assertJsonPath('message', 'questions.txt: only PDF and DOCX files are supported.');
    }

    public function test_student_attempt_discovery_is_scoped_to_active_folder_permissions(): void
    {
        $student = $this->createUser('access-student@example.test', 'student');
        $activeFolder = $this->createFolder('Accessible');
        $suspendedFolder = $this->createFolder('Suspended');
        $activeTest = $this->createTest($activeFolder, now()->subMinute()->toIso8601String());
        $suspendedTest = $this->createTest($suspendedFolder, now()->addDay()->toIso8601String());
        DB::table('folder_permissions')->insert([
            [
                'id' => (string) Str::uuid(), 'userId' => $student, 'folderId' => $activeFolder,
                'canView' => true, 'billing_suspended' => false, 'createdAt' => now(),
            ],
            [
                'id' => (string) Str::uuid(), 'userId' => $student, 'folderId' => $suspendedFolder,
                'canView' => true, 'billing_suspended' => true, 'createdAt' => now(),
            ],
        ]);
        $attemptId = $this->createAttempt($activeTest, $student, [
            'status' => 'IN_PROGRESS',
        ]);

        $this->withToken($this->token($student))
            ->getJson('/api/tests/me/attempts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $activeTest)
            ->assertJsonPath('0.status', 'EXPIRED')
            ->assertJsonPath('0.attempt.id', $attemptId)
            ->assertJsonPath('0.test.title', 'Practice Test')
            ->assertJsonPath('0.test.questions.0.questionText', 'What is 2 + 2?')
            ->assertJsonMissingPath('0.test.questions.0.idealAnswer')
            ->assertJsonMissingPath('0.test.questions.0.markingScheme');
    }

    public function test_student_test_view_redacts_answers_and_checks_entitlement(): void
    {
        $student = $this->createUser('test-view-student@example.test', 'student');
        $folder = $this->createFolder('Test View');
        $test = $this->createTest($folder, now()->addDay()->toIso8601String());
        DB::table('folder_permissions')->insert([
            'id' => (string) Str::uuid(), 'userId' => $student, 'folderId' => $folder,
            'canView' => true, 'billing_suspended' => false, 'createdAt' => now(),
        ]);

        $this->withToken($this->token($student))
            ->getJson('/api/tests/'.$test.'/me')
            ->assertOk()
            ->assertJsonPath('id', $test)
            ->assertJsonPath('questions.0.questionText', 'What is 2 + 2?')
            ->assertJsonMissingPath('questions.0.idealAnswer')
            ->assertJsonMissingPath('questions.0.markingScheme')
            ->assertJsonPath('attempt', null)
            ->assertJsonPath('solutionSourceFiles', []);

        $attemptId = $this->createAttempt($test, $student, [
            'status' => 'COMPLETED',
            'marksObtained' => '2.00',
            'ai_assessment' => json_encode([
                'totalMarksAwarded' => 2,
                'totalMarksAvailable' => 2,
                'deficiencyReport' => 'Correct.',
                'questions' => [[
                    'questionNumber' => 1,
                    'marksAwarded' => 2,
                    'marksAvailable' => 2,
                    'criterionResults' => [],
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('test_attempt_files')->insert([
            'id' => (string) Str::uuid(), 'attempt_id' => $attemptId, 'question_number' => 1,
            'original_name' => 'answer.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 128,
            'storage_key' => 'private/answer.pdf', 'file_data' => null, 'created_at' => now(),
        ]);
        DB::table('test_source_files')->insert([
            'id' => (string) Str::uuid(), 'test_id' => $test, 'original_name' => 'solutions.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 256, 'storage_key' => 'private/solutions.pdf',
            'file_data' => null, 'created_at' => now(),
        ]);
        $this->withToken($this->token($student))
            ->getJson('/api/tests/'.$test.'/me')
            ->assertOk()
            ->assertJsonPath('questions.0.questionSourceLocations.0.pageNumbers.0', 1)
            ->assertJsonPath('questions.0.solutionSourceLocations.0.pageNumbers.0', 2)
            ->assertJsonPath('attempt.aiAssessment.questions.0.solution', '4')
            ->assertJsonPath('attempt.files.0.originalName', 'answer.pdf')
            ->assertJsonMissingPath('attempt.files.0.storageKey')
            ->assertJsonPath('solutionSourceFiles.0.originalName', 'solutions.pdf')
            ->assertJsonMissingPath('solutionSourceFiles.0.storageKey');

        DB::table('folder_permissions')->where('userId', $student)->update(['billing_suspended' => true]);
        $this->withToken($this->token($student))
            ->getJson('/api/tests/'.$test.'/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'No active folder access for this test.');
        $this->withToken($this->token($student))
            ->getJson('/api/tests/'.(string) Str::uuid().'/me')
            ->assertNotFound()
            ->assertJsonPath('message', 'Test not found');
        $this->withToken($this->token($student))
            ->getJson('/api/tests/not-a-uuid/me')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Invalid identifier');
    }

    public function test_access_request_persists_attempt_then_enforces_deadline_and_pending_state(): void
    {
        $student = $this->createUser('request-student@example.test', 'student');
        $folder = $this->createFolder('Access Requests');
        $expiredTest = $this->createTest($folder, now()->subMinute()->toIso8601String());
        $currentTest = $this->createTest($folder, now()->addDay()->toIso8601String());
        DB::table('folder_permissions')->insert([
            'id' => (string) Str::uuid(), 'userId' => $student, 'folderId' => $folder,
            'canView' => true, 'billing_suspended' => false, 'createdAt' => now(),
        ]);

        $this->withToken($this->token($student))
            ->postJson('/api/tests/'.$currentTest.'/me/request-access')
            ->assertBadRequest()
            ->assertJsonPath('message', 'This test has not expired.');
        $this->assertDatabaseHas('test_attempts', [
            'testId' => $currentTest, 'studentId' => $student, 'status' => 'NOT_ATTEMPTED',
        ]);

        $requested = $this->withToken($this->token($student))
            ->postJson('/api/tests/'.$expiredTest.'/me/request-access')
            ->assertCreated()
            ->assertJsonPath('requested', true);
        $attemptId = $requested->json('attemptId');
        $this->assertDatabaseHas('test_attempts', [
            'id' => $attemptId, 'testId' => $expiredTest, 'studentId' => $student, 'status' => 'REQUESTED',
        ]);
        $this->withToken($this->token($student))
            ->postJson('/api/tests/'.$expiredTest.'/me/request-access')
            ->assertBadRequest()
            ->assertJsonPath('message', 'An access request is already pending.');

        $submitted = $this->createTest($folder, now()->subDay()->toIso8601String());
        $this->createAttempt($submitted, $student, [
            'status' => 'SUBMITTED',
            'submitted_at' => now()->subHour(),
        ]);
        $this->withToken($this->token($student))
            ->postJson('/api/tests/'.$submitted.'/me/request-access')
            ->assertBadRequest()
            ->assertJsonPath('message', 'This test has already been submitted.');
    }

    private function testPayload(string $folderId, ?string $classId = null, ?string $sectionId = null): array
    {
        $question = [
            'questionNumber' => 1,
            'questionText' => 'Calculate the net amount.',
            'idealAnswer' => 'Show the calculation.',
            'difficulty' => 'MODERATE',
            'marks' => 2.5,
            'markingScheme' => [['criterion' => 'Show calculation | 2.5', 'marks' => 2.5]],
            'questionSourceLocations' => [['sourceIndex' => 0, 'pageNumbers' => [1]]],
        ];
        return [
            'title' => ' Midterm Examination ',
            'description' => '  Assessment details  ',
            'testDate' => now()->addWeek()->toDateString(),
            'deadlineAt' => now()->addDays(2)->toIso8601String(),
            'totalMarks' => 2.5,
            'classId' => $classId,
            'sectionId' => $sectionId,
            'folderIds' => [$folderId],
            'contentCovered' => [(string) Str::uuid()],
            'questions' => [$question],
        ];
    }

    private function createUser(string $email, string $role, array $permissions = []): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('test-authoring-password'),
            'role' => $role,
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        foreach ($permissions as $permission) {
            DB::table('user_role_assignments')->insert([
                'userId' => $id, 'granteeId' => $id, 'permission' => $permission, 'grantedAt' => now(),
            ]);
        }
        return $id;
    }

    private function createFolder(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $id,
            'name' => $name,
            'default_price' => 0,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        return $id;
    }

    private function createTest(string $folderId, string $deadline): string
    {
        $id = (string) Str::uuid();
        DB::table('tests')->insert([
            'id' => $id,
            'title' => 'Practice Test',
            'description' => 'Assessment description',
            'testDate' => now()->toDateString(),
            'totalMarks' => '2.00',
            'deadline_at' => $deadline,
            'questions' => json_encode([[
                'questionNumber' => 1,
                'questionText' => 'What is 2 + 2?',
                'idealAnswer' => '4',
                'questionSourceLocations' => [['sourceIndex' => 0, 'pageNumbers' => [1]]],
                'solutionSourceLocations' => [['sourceIndex' => 0, 'pageNumbers' => [2]]],
                'markingScheme' => [['criterion' => 'Correct answer', 'marks' => 2]],
                'marks' => 2,
            ]], JSON_THROW_ON_ERROR),
            'contentCovered' => json_encode([], JSON_THROW_ON_ERROR),
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('test_folders')->insert(['testId' => $id, 'folderId' => $folderId]);
        return $id;
    }

    private function createAttempt(string $testId, string $studentId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('test_attempts')->insert(array_merge([
            'id' => $id,
            'testId' => $testId,
            'studentId' => $studentId,
            'appeared' => false,
            'status' => 'NOT_ATTEMPTED',
            'createdAt' => now(),
            'updatedAt' => now(),
        ], $overrides));
        return $id;
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $permissions = DB::table('user_role_assignments')->where('userId', $userId)->pluck('permission')->all();
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
