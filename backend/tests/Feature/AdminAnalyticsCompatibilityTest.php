<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAnalyticsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_analytics_routes_return_source_shaped_aggregates(): void
    {
        $fixture = $this->createAnalyticsFixture();
        $token = $this->token($fixture['managerId']);

        $this->withToken($token)->getJson('/api/admin/analytics/overview')
            ->assertOk()
            ->assertJsonPath('enrolledStudents', 1)
            ->assertJsonPath('activeStudents', 1)
            ->assertJsonPath('studentsWithQuiz', 1)
            ->assertJsonPath('studentsWithNoActivity', 0)
            ->assertJsonPath('quizAttempts', 1)
            ->assertJsonPath('questionsAttempted', 2)
            ->assertJsonPath('averageScore', 80)
            ->assertJsonPath('passRate', 100);

        $this->withToken($token)->getJson('/api/admin/analytics/filters')
            ->assertOk()
            ->assertJsonPath('batches.0.id', $fixture['batchId'])
            ->assertJsonPath('schoolYears.0.id', $fixture['schoolYearId']);

        $this->withToken($token)->getJson('/api/admin/analytics/students?sort=accuracy&page=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('0.student_id', $fixture['studentUserId'])
            ->assertJsonPath('0.student_name', 'Analytics Student')
            ->assertJsonPath('0.quiz_attempts', 1)
            ->assertJsonPath('0.questions_attempted', 2)
            ->assertJsonPath('0.average_score', 80)
            ->assertJsonPath('0.accuracy', 50);

        $filters = http_build_query([
            'batchId' => $fixture['batchId'],
            'schoolYearId' => $fixture['schoolYearId'],
            'classId' => $fixture['classId'],
            'sectionId' => $fixture['sectionId'],
            'courseId' => $fixture['courseId'],
            'gridId' => $fixture['gridId'],
            'topicId' => $fixture['topicId'],
            'tierType' => 'tier_1',
            'search' => 'Analytics',
            'from' => Carbon::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
            'to' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'page' => 1,
            'pageSize' => 10,
        ]);
        $this->withToken($token)->getJson('/api/admin/analytics/students?'.$filters)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.student_id', $fixture['studentUserId']);

        $this->withToken($token)->getJson('/api/admin/analytics/students/'.$fixture['studentUserId'])
            ->assertOk()
            ->assertJsonPath('summary.student_id', $fixture['studentUserId'])
            ->assertJsonPath('quizHistory.0.id', $fixture['attemptId'])
            ->assertJsonPath('questionAnalysis.0.attempts', 1)
            ->assertJsonPath('lectureActivity.0.title', 'Analytics Lecture')
            ->assertJsonPath('topicAggregate.0.topic_id', $fixture['topicId'])
            ->assertJsonPath('quizAggregate.0.attempts', 1)
            ->assertJsonPath('flashcards.reviews', 1)
            ->assertJsonPath('flashcards.got_it', 1);

        $this->withToken($token)->getJson('/api/admin/analytics/questions?difficulty=EASY&questionType=MCQ')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.total_attempts', 1)
            ->assertJsonPath('0.unique_students', 1)
            ->assertJsonPath('0.correct_count', 0)
            ->assertJsonPath('0.incorrect_count', 1);

        $this->withToken($token)->getJson('/api/admin/analytics/topics?courseId='.$fixture['courseId'])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.quiz_attempts', 1)
            ->assertJsonPath('0.active_students', 1)
            ->assertJsonPath('0.lecture_completions', 1);

        $this->withToken($token)->getJson('/api/admin/analytics/quizzes?tierType=TIER_1')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.quiz_title', 'Analytics Topic')
            ->assertJsonPath('0.attempts', 1)
            ->assertJsonPath('0.passed', 1);

        $this->withToken($token)->getJson('/api/admin/analytics/activity')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.quiz_attempts', 2)
            ->assertJsonPath('0.active_students', 1)
            ->assertJsonPath('0.questions_answered', 2);
    }

    public function test_analytics_require_admin_or_testing_manager_and_validate_filters(): void
    {
        $fixture = $this->createAnalyticsFixture();
        $studentToken = $this->token($fixture['studentUserId']);

        $this->getJson('/api/admin/analytics/overview')->assertUnauthorized();
        $this->withToken($studentToken)->getJson('/api/admin/analytics/overview')->assertForbidden();

        $managerToken = $this->token($fixture['managerId']);
        $this->withToken($managerToken)
            ->getJson('/api/admin/analytics/overview?tierType=TIER_4')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Invalid tierType');
        $this->withToken($managerToken)
            ->getJson('/api/admin/analytics/students/not-a-uuid')
            ->assertBadRequest()
            ->assertJsonPath('message', 'studentId must be a UUID');
        $this->withToken($managerToken)
            ->getJson('/api/admin/analytics/students?page=0')
            ->assertBadRequest();
    }

    private function createAnalyticsFixture(): array
    {
        $managerId = $this->createUser('analytics.manager@example.test', 'admin', ['testing_manager']);
        $studentUserId = $this->createUser('analytics.student@example.test', 'student');
        $schoolId = (string) Str::uuid();
        $classId = (string) Str::uuid();
        $sectionId = (string) Str::uuid();
        $studentId = (string) Str::uuid();
        $schoolYearId = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $courseId = (string) Str::uuid();
        $gridId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $questionIds = [(string) Str::uuid(), (string) Str::uuid()];
        $attemptId = (string) Str::uuid();
        $folderId = (string) Str::uuid();
        $lectureId = (string) Str::uuid();
        $flashcardId = (string) Str::uuid();

        DB::table('schools')->insert([
            'id' => $schoolId, 'name' => 'Analytics School', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('classes')->insert([
            'id' => $classId, 'school_id' => $schoolId, 'numeric_level' => 10,
            'display_name' => 'Class 10', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sections')->insert([
            'id' => $sectionId, 'class_id' => $classId, 'name' => 'A', 'capacity' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('students')->insert([
            'id' => $studentId, 'enrollment_no' => 'AN-001', 'full_name' => 'Analytics Student',
            'joined_at' => now()->toDateString(), 'status' => 'active', 'user_id' => $studentUserId,
            'current_class_id' => $classId, 'current_section_id' => $sectionId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('student_cohort_memberships')->insert([
            'id' => (string) Str::uuid(), 'student_id' => $studentId, 'batch_id' => $batchId,
            'school_year_id' => $schoolYearId, 'class_id' => $classId, 'section_id' => $sectionId,
            'starts_on' => now()->toDateString(),
        ]);
        DB::table('courses')->insert([
            'id' => $courseId, 'code' => 'ANL', 'title' => 'Analytics Course', 'is_active' => true,
        ]);
        DB::table('grids')->insert([
            'id' => $gridId, 'course_id' => $courseId, 'grid_code' => 'G1', 'title' => 'Grid 1',
            'total_past_papers' => 0,
        ]);
        DB::table('topics')->insert([
            'id' => $topicId, 'grid_id' => $gridId, 'title' => 'Analytics Topic', 'sequence_order' => 1,
        ]);
        foreach ($questionIds as $index => $questionId) {
            DB::table('questions')->insert([
                'id' => $questionId, 'topic_id' => $topicId, 'tier_type' => 'TIER_1',
                'question_text' => str_repeat('Question '.$index.' ', 20), 'options' => json_encode(['A', 'B']),
                'correct_answer' => 'A', 'marks' => 1, 'is_active' => true,
                'difficulty' => 'EASY', 'question_type' => 'MCQ',
            ]);
        }
        $startedAt = Carbon::now('UTC')->subHours(2);
        $submittedAt = Carbon::now('UTC')->subHours(1);
        DB::table('quiz_attempts')->insert([
            'id' => $attemptId, 'user_id' => $studentUserId, 'topic_id' => $topicId,
            'tier_type' => 'TIER_1', 'started_at' => $startedAt, 'submitted_at' => $submittedAt,
            'score' => 8, 'total_marks' => 10, 'score_percent' => 80,
            'status' => 'SUBMITTED', 'question_ids' => json_encode($questionIds),
        ]);
        foreach ($questionIds as $index => $questionId) {
            DB::table('quiz_attempt_answers')->insert([
                'id' => (string) Str::uuid(), 'attempt_id' => $attemptId, 'question_id' => $questionId,
                'selected_option' => $index === 0 ? 'B' : 'A', 'is_correct' => $index !== 0,
                'marks_awarded' => $index === 0 ? 0 : 1,
            ]);
        }
        DB::table('topic_progress')->insert([
            'user_id' => $studentUserId, 'topic_id' => $topicId, 'video_status' => 'WATCHED',
        ]);
        DB::table('folders')->insert([
            'id' => $folderId, 'name' => 'Analytics Folder', 'default_price' => 0,
            'createdAt' => now(), 'updatedAt' => now(),
        ]);
        DB::table('lectures')->insert([
            'id' => $lectureId, 'title' => 'Analytics Lecture', 'folderId' => $folderId,
            'contentGroup' => '', 'createdAt' => now(), 'updatedAt' => now(),
        ]);
        DB::table('lecture_progress')->insert([
            'user_id' => $studentUserId, 'lecture_id' => $lectureId, 'watched_percent' => 100,
            'status' => 'COMPLETED', 'last_watched_at' => $submittedAt, 'updated_at' => now(),
        ]);
        DB::table('flashcards')->insert([
            'id' => $flashcardId, 'topic_id' => $topicId, 'content' => 'Analytics flashcard',
        ]);
        DB::table('flashcard_reviews')->insert([
            'user_id' => $studentUserId, 'flashcard_id' => $flashcardId, 'status' => 'GOT_IT',
            'last_reviewed_at' => $submittedAt,
        ]);

        return compact(
            'managerId', 'studentUserId', 'schoolYearId', 'batchId', 'classId', 'sectionId',
            'courseId', 'gridId', 'topicId', 'attemptId'
        );
    }

    private function createUser(string $email, string $role, array $permissions = []): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('analytics-password'),
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
