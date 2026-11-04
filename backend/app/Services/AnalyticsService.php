<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public function filterOptions(): array
    {
        $batches = DB::table('student_cohort_memberships')
            ->whereNotNull('batch_id')
            ->selectRaw('DISTINCT batch_id as id, CAST(batch_id AS CHAR) as name')
            ->orderBy('name')
            ->get()
            ->all();
        $schoolYears = DB::table('student_cohort_memberships')
            ->whereNotNull('school_year_id')
            ->selectRaw('DISTINCT school_year_id as id, CAST(school_year_id AS CHAR) as name')
            ->orderBy('name')
            ->get()
            ->all();

        return ['batches' => $batches, 'schoolYears' => $schoolYears];
    }

    public function overview(array $filters): array
    {
        $cohort = DB::table('users as u')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->where('u.role', 'student');
        $this->studentScope($cohort, $filters);
        $enrolled = (int) $cohort->distinct()->count('u.id');

        $submitted = $this->submittedAttempts($filters);
        $activeSince = Carbon::now('UTC')->subDays(30)->format('Y-m-d H:i:s');
        $attemptAggregate = (clone $submitted)->selectRaw(
            'COUNT(*) as quiz_attempts, COUNT(DISTINCT a.user_id) as students_with_quiz, '.
            'COALESCE(AVG(a.score_percent), 0) as average_score, '.
            'COUNT(DISTINCT CASE WHEN a.score_percent >= 50 THEN a.user_id END) as passing_students, '.
            'COUNT(DISTINCT CASE WHEN a.submitted_at >= ? THEN a.user_id END) as active_students',
            [$activeSince]
        )->first();
        $attemptIds = (clone $submitted)->select('a.id');
        $questionsAttempted = DB::table('quiz_attempt_answers as qaa')
            ->joinSub($attemptIds, 'submitted', 'submitted.id', '=', 'qaa.attempt_id')
            ->count();
        $studentsWithQuiz = (int) ($attemptAggregate->students_with_quiz ?? 0);

        return [
            'enrolledStudents' => $enrolled,
            'activeStudents' => (int) ($attemptAggregate->active_students ?? 0),
            'studentsWithQuiz' => $studentsWithQuiz,
            'studentsWithNoActivity' => max(0, $enrolled - $studentsWithQuiz),
            'quizAttempts' => (int) ($attemptAggregate->quiz_attempts ?? 0),
            'questionsAttempted' => (int) $questionsAttempted,
            'averageScore' => (float) ($attemptAggregate->average_score ?? 0),
            'passRate' => $studentsWithQuiz
                ? (int) ($attemptAggregate->passing_students ?? 0) / $studentsWithQuiz * 100
                : 0,
        ];
    }

    public function students(array $filters): array
    {
        $answerCounts = DB::table('quiz_attempt_answers')
            ->selectRaw(
                'attempt_id, COUNT(id) as questions_attempted, SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_count, '.
                'SUM(CASE WHEN is_correct IS NOT NULL THEN 1 ELSE 0 END) as graded_count'
            )
            ->groupBy('attempt_id');
        $query = DB::table('users as u')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('classes as c', 'c.id', '=', 's.current_class_id')
            ->leftJoin('quiz_attempts as a', 'a.user_id', '=', 'u.id')
            ->leftJoin('topics as t', 't.id', '=', 'a.topic_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id')
            ->leftJoinSub($answerCounts, 'qa', 'qa.attempt_id', '=', 'a.id')
            ->where('u.role', 'student')
            ->where('a.status', 'SUBMITTED');
        $this->studentScope($query, $filters);
        $this->attemptScope($query, $filters);

        $sort = match ($filters['sort'] ?? 'activity') {
            'score' => 'average_score',
            'accuracy' => 'accuracy',
            'completion' => 'quiz_attempts',
            'activity', 'last-activity' => 'last_activity',
            default => 'last_activity',
        };
        return $query->selectRaw(
            'u.id as student_id, COALESCE(s.full_name, u.email) as student_name, s.enrollment_no, c.display_name as class_name, '.
            'COUNT(DISTINCT a.id) as quiz_attempts, COALESCE(SUM(qa.questions_attempted), 0) as questions_attempted, '.
            'COALESCE(AVG(a.score_percent), 0) as average_score, COALESCE(MAX(a.score_percent), 0) as best_score, '.
            'COALESCE(SUM(qa.correct_count) * 1.0 / NULLIF(SUM(qa.graded_count), 0), 0) * 100 as accuracy, '.
            'MAX(COALESCE(a.submitted_at, a.started_at)) as last_activity'
        )
            ->groupBy('u.id', 's.full_name', 'u.email', 's.enrollment_no', 'c.display_name')
            ->orderByDesc($sort)
            ->limit($filters['pageSize'] ?? 25)
            ->offset((($filters['page'] ?? 1) - 1) * ($filters['pageSize'] ?? 25))
            ->get()
            ->map(fn (object $row): array => [
                'student_id' => $row->student_id,
                'student_name' => $row->student_name,
                'enrollment_no' => $row->enrollment_no,
                'class_name' => $row->class_name,
                'quiz_attempts' => (int) $row->quiz_attempts,
                'questions_attempted' => (int) $row->questions_attempted,
                'average_score' => (float) $row->average_score,
                'best_score' => (float) $row->best_score,
                'accuracy' => (float) $row->accuracy,
                'last_activity' => $row->last_activity,
            ])->all();
    }

    public function questions(array $filters): array
    {
        $questionPreview = DB::getDriverName() === 'sqlite'
            ? 'SUBSTR(q.question_text, 1, 200)'
            : 'LEFT(q.question_text, 200)';
        $query = DB::table('quiz_attempt_answers as qa')
            ->join('quiz_attempts as a', 'a.id', '=', 'qa.attempt_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->join('questions as q', 'q.id', '=', 'qa.question_id')
            ->leftJoin('topics as t', 't.id', '=', 'q.topic_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id')
            ->where('a.status', 'SUBMITTED');
        $this->studentScope($query, $filters);
        $this->attemptScope($query, $filters);
        if (!empty($filters['difficulty'])) {
            $query->where('q.difficulty', $filters['difficulty']);
        }
        if (!empty($filters['questionType'])) {
            $query->where('q.question_type', $filters['questionType']);
        }

        return $query->selectRaw(
            'q.id as question_id, '.$questionPreview.' as question_preview, q.difficulty, q.question_type, '.
            'COUNT(qa.id) as total_attempts, COUNT(DISTINCT a.user_id) as unique_students, '.
            'SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) as correct_count, '.
            'SUM(CASE WHEN qa.is_correct = 0 THEN 1 ELSE 0 END) as incorrect_count, '.
            'COALESCE(AVG(CASE WHEN qa.is_correct IS NULL THEN NULL WHEN qa.is_correct = 1 THEN 1 ELSE 0 END), 0) * 100 as accuracy, '.
            'MAX(a.submitted_at) as last_attempted'
        )
            ->groupBy('q.id', 'q.question_text', 'q.difficulty', 'q.question_type')
            ->orderBy('accuracy')
            ->limit($filters['pageSize'] ?? 25)
            ->offset((($filters['page'] ?? 1) - 1) * ($filters['pageSize'] ?? 25))
            ->get()
            ->map(fn (object $row): array => [
                'question_id' => $row->question_id,
                'question_preview' => $row->question_preview,
                'difficulty' => $row->difficulty,
                'question_type' => $row->question_type,
                'total_attempts' => (int) $row->total_attempts,
                'unique_students' => (int) $row->unique_students,
                'correct_count' => (int) $row->correct_count,
                'incorrect_count' => (int) $row->incorrect_count,
                'accuracy' => (float) $row->accuracy,
                'last_attempted' => $row->last_attempted,
            ])->all();
    }

    public function topics(array $filters): array
    {
        $query = DB::table('topics as t')
            ->join('grids as g', 'g.id', '=', 't.grid_id')
            ->leftJoin('quiz_attempts as a', function ($join): void {
                $join->on('a.topic_id', '=', 't.id')->where('a.status', '=', 'SUBMITTED');
            })
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('topic_progress as tp', function ($join): void {
                $join->on('tp.topic_id', '=', 't.id')->where('tp.video_status', '=', 'WATCHED');
            });
        if (!empty($filters['courseId'])) {
            $query->where('g.course_id', $filters['courseId']);
        }
        if (!empty($filters['gridId'])) {
            $query->where('t.grid_id', $filters['gridId']);
        }
        if (!empty($filters['topicId'])) {
            $query->where('t.id', $filters['topicId']);
        }
        $this->studentScope($query, $filters);

        return $query->selectRaw(
            't.id as topic_id, t.title, COUNT(DISTINCT a.id) as quiz_attempts, '.
            'COUNT(DISTINCT a.user_id) as active_students, COALESCE(AVG(a.score_percent), 0) as average_score, '.
            'COUNT(DISTINCT tp.user_id) as lecture_completions'
        )
            ->groupBy('t.id', 't.title')
            ->orderByDesc('active_students')
            ->get()
            ->map(fn (object $row): array => [
                'topic_id' => $row->topic_id,
                'title' => $row->title,
                'quiz_attempts' => (int) $row->quiz_attempts,
                'active_students' => (int) $row->active_students,
                'average_score' => (float) $row->average_score,
                'lecture_completions' => (int) $row->lecture_completions,
            ])->all();
    }

    public function quizzes(array $filters): array
    {
        $durationExpression = DB::getDriverName() === 'sqlite'
            ? '(julianday(a.submitted_at) - julianday(a.started_at)) * 86400'
            : 'TIMESTAMPDIFF(SECOND, a.started_at, a.submitted_at)';
        $query = DB::table('quiz_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('topics as t', 't.id', '=', 'a.topic_id')
            ->leftJoin('mock_exams as me', 'me.id', '=', 'a.mock_exam_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id');
        $this->studentScope($query, $filters);
        $this->attemptScope($query, $filters);

        return $query->selectRaw(
            "a.tier_type, COALESCE(me.title, t.title, 'Unassigned') as quiz_title, ".
            'COALESCE(a.mock_exam_id, a.topic_id) as quiz_id, COUNT(*) as attempts, '.
            'COALESCE(AVG(a.score_percent), 0) as average_score, '.
            'SUM(CASE WHEN a.score_percent >= 50 THEN 1 ELSE 0 END) as passed, '.
            "SUM(CASE WHEN a.status = 'IN_PROGRESS' THEN 1 ELSE 0 END) as abandoned, ".
            'AVG('.$durationExpression.') / 60 as average_duration_minutes'
        )
            ->groupBy('a.tier_type', 'me.title', 't.title', 'a.mock_exam_id', 'a.topic_id')
            ->orderBy('quiz_title')
            ->get()
            ->map(fn (object $row): array => [
                'tier_type' => $row->tier_type,
                'quiz_title' => $row->quiz_title,
                'quiz_id' => $row->quiz_id,
                'attempts' => (int) $row->attempts,
                'average_score' => (float) $row->average_score,
                'passed' => (int) $row->passed,
                'abandoned' => (int) $row->abandoned,
                'average_duration_minutes' => $row->average_duration_minutes === null ? null : (float) $row->average_duration_minutes,
            ])->all();
    }

    public function activity(array $filters): array
    {
        $query = DB::table('quiz_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('quiz_attempt_answers as qa', 'qa.attempt_id', '=', 'a.id')
            ->leftJoin('topics as t', 't.id', '=', 'a.topic_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id')
            ->whereNotNull('a.submitted_at');
        $this->studentScope($query, $filters);
        $this->attemptScope($query, $filters);

        return $query->selectRaw(
            'DATE(a.submitted_at) as activity_day, COUNT(*) as quiz_attempts, '.
            'COUNT(DISTINCT a.user_id) as active_students, COUNT(qa.id) as questions_answered'
        )
            ->groupByRaw('DATE(a.submitted_at)')
            ->orderBy('activity_day')
            ->get()
            ->map(fn (object $row): array => [
                'activity_day' => $row->activity_day,
                'quiz_attempts' => (int) $row->quiz_attempts,
                'active_students' => (int) $row->active_students,
                'questions_answered' => (int) $row->questions_answered,
            ])->all();
    }

    public function studentDetail(string $studentId, array $filters): array
    {
        $filters['studentId'] = $studentId;
        $summary = $this->students(array_merge($filters, ['page' => 1, 'pageSize' => 1]));
        $history = DB::table('quiz_attempts as a')
            ->leftJoin('topics as t', 't.id', '=', 'a.topic_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id')
            ->leftJoin('courses as c', 'c.id', '=', 'g.course_id')
            ->where('a.user_id', $studentId)
            ->when(!empty($filters['courseId']), fn (Builder $query) => $query->where('g.course_id', $filters['courseId']))
            ->when(!empty($filters['topicId']), fn (Builder $query) => $query->where('a.topic_id', $filters['topicId']))
            ->orderByDesc('a.started_at')
            ->limit(100)
            ->get([
                'a.id', 'a.started_at', 'a.submitted_at', 'a.tier_type',
                'a.score_percent as score', 'a.status', 't.title as topic_title', 'c.title as course_title',
            ])->all();
        $questionAnalysis = DB::table('quiz_attempt_answers as qa')
            ->join('quiz_attempts as a', 'a.id', '=', 'qa.attempt_id')
            ->join('questions as q', 'q.id', '=', 'qa.question_id')
            ->leftJoin('topics as t', 't.id', '=', 'q.topic_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id')
            ->where('a.user_id', $studentId)
            ->when(!empty($filters['courseId']), fn (Builder $query) => $query->where('g.course_id', $filters['courseId']))
            ->when(!empty($filters['topicId']), fn (Builder $query) => $query->where('q.topic_id', $filters['topicId']))
            ->selectRaw(
                'q.id, q.difficulty, COUNT(*) as attempts, '.
                'SUM(CASE WHEN qa.is_correct = 1 THEN 1 ELSE 0 END) as correct'
            )
            ->groupBy('q.id', 'q.difficulty')
            ->orderBy('correct')
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'difficulty' => $row->difficulty,
                'attempts' => (int) $row->attempts,
                'correct' => (int) $row->correct,
            ])->all();
        $lectureActivity = DB::table('lecture_progress as lp')
            ->join('lectures as l', 'l.id', '=', 'lp.lecture_id')
            ->where('lp.user_id', $studentId)
            ->orderByDesc('lp.last_watched_at')
            ->select('lp.*', 'l.title')
            ->get()
            ->all();
        $flashcardRow = DB::table('flashcard_reviews')
            ->where('user_id', $studentId)
            ->selectRaw(
                'COUNT(*) as reviews, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as got_it',
                ['GOT_IT']
            )->first();

        return [
            'summary' => $summary[0] ?? null,
            'quizHistory' => $history,
            'questionAnalysis' => $questionAnalysis,
            'lectureActivity' => $lectureActivity,
            'topicAggregate' => $this->topics($filters),
            'quizAggregate' => $this->quizzes($filters),
            'flashcards' => $flashcardRow
                ? ['reviews' => (int) $flashcardRow->reviews, 'got_it' => (int) $flashcardRow->got_it]
                : ['reviews' => 0, 'got_it' => 0],
        ];
    }

    private function submittedAttempts(array $filters): Builder
    {
        $query = DB::table('quiz_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('topics as t', 't.id', '=', 'a.topic_id')
            ->leftJoin('grids as g', 'g.id', '=', 't.grid_id')
            ->where('a.status', 'SUBMITTED');
        $this->studentScope($query, $filters);
        $this->attemptScope($query, $filters);

        return $query;
    }

    private function studentScope(Builder $query, array $filters): void
    {
        if (!empty($filters['studentId'])) {
            $query->where('u.id', $filters['studentId']);
        }
        if (!empty($filters['search'])) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(function (Builder $subquery) use ($pattern): void {
                $subquery->whereRaw('LOWER(COALESCE(s.full_name, u.email)) LIKE LOWER(?)', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(s.enrollment_no, '')) LIKE LOWER(?)", [$pattern]);
            });
        }
        if (!empty($filters['classId'])) {
            $query->where('s.current_class_id', $filters['classId']);
        }
        if (!empty($filters['sectionId'])) {
            $query->where('s.current_section_id', $filters['sectionId']);
        }
        foreach (['batchId' => 'batch_id', 'schoolYearId' => 'school_year_id'] as $filter => $column) {
            if (!empty($filters[$filter])) {
                $query->whereExists(function (Builder $cohort) use ($filters, $filter, $column): void {
                    $cohort->selectRaw('1')
                        ->from('student_cohort_memberships as scm')
                        ->whereColumn('scm.student_id', 's.id')
                        ->where(function (Builder $active): void {
                            $active->whereNull('scm.ends_on')->orWhereDate('scm.ends_on', '>=', DB::raw('CURRENT_DATE'));
                        })
                        ->where("scm.{$column}", $filters[$filter]);
                });
            }
        }
    }

    private function attemptScope(Builder $query, array $filters): void
    {
        if (!empty($filters['courseId'])) {
            $query->where('g.course_id', $filters['courseId']);
        }
        if (!empty($filters['gridId'])) {
            $query->where('t.grid_id', $filters['gridId']);
        }
        if (!empty($filters['topicId'])) {
            $query->where('a.topic_id', $filters['topicId']);
        }
        if (!empty($filters['tierType'])) {
            $query->where('a.tier_type', $filters['tierType']);
        }
        if (!empty($filters['from'])) {
            $query->where('a.submitted_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('a.submitted_at', '<=', $filters['to']);
        }
    }
}
