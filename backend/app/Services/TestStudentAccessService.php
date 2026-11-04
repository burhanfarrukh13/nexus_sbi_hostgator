<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TestStudentAccessService
{
    public function listMyAttempts(string $studentId): array
    {
        $tests = DB::table('tests')
            ->whereExists(function ($query) use ($studentId): void {
                $query->selectRaw('1')
                    ->from('test_folders as tf')
                    ->join('folder_permissions as fp', 'fp.folderId', '=', 'tf.folderId')
                    ->whereColumn('tf.testId', 'tests.id')
                    ->where('fp.userId', $studentId)
                    ->where('fp.canView', true)
                    ->where('fp.billing_suspended', false);
            })
            ->orderByDesc('createdAt')
            ->get();

        if ($tests->isEmpty()) {
            return [];
        }

        $attempts = DB::table('test_attempts')
            ->where('studentId', $studentId)
            ->whereIn('testId', $tests->pluck('id'))
            ->get()
            ->keyBy('testId');

        return $tests->map(function (object $test) use ($attempts): array {
            $questions = $this->decodeJson($test->questions, []);
            $attempt = $attempts->get($test->id);
            $effectiveDeadline = $attempt->deadline_override_at ?? $test->deadline_at ?? null;
            $safeAttempt = $this->studentAttemptView($attempt, $questions);
            $status = (string) ($safeAttempt['status'] ?? 'NOT_ATTEMPTED');
            if (in_array($status, ['NOT_ATTEMPTED', 'IN_PROGRESS'], true) &&
                $effectiveDeadline !== null && Carbon::parse($effectiveDeadline)->lessThanOrEqualTo(now())) {
                $status = 'EXPIRED';
            }

            return [
                'id' => $test->id,
                'status' => $status,
                'effectiveDeadlineAt' => $this->isoDate($effectiveDeadline),
                'accessRequestedAt' => $this->isoDate($attempt->access_requested_at ?? null),
                'attempt' => $safeAttempt,
                'test' => [
                    'id' => $test->id,
                    'title' => $test->title,
                    'description' => $test->description,
                    'testDate' => $test->testDate,
                    'totalMarks' => (string) $test->totalMarks,
                    'deadlineAt' => $this->isoDate($test->deadline_at),
                    'questions' => array_map(static fn (array $question): array => [
                        'questionNumber' => $question['questionNumber'] ?? null,
                        'questionText' => $question['questionText'] ?? null,
                        'marks' => $question['marks'] ?? null,
                    ], $questions),
                    'createdAt' => $this->isoDate($test->createdAt),
                ],
            ];
        })->all();
    }

    public function getTestForStudent(string $testId, string $studentId): array
    {
        $test = $this->requireStudentTestAccess($testId, $studentId);
        $attempt = DB::table('test_attempts')
            ->where('testId', $testId)
            ->where('studentId', $studentId)
            ->first();

        $questions = $this->decodeJson($test->questions, []);
        $contentIds = array_values(array_filter($this->decodeJson($test->contentCovered, [])));
        $lectures = $contentIds === []
            ? []
            : DB::table('lectures')
                ->whereIn('id', $contentIds)
                ->get(['id', 'title', 'duration'])
                ->map(static fn (object $lecture): array => (array) $lecture)
                ->all();

        $released = $attempt !== null && $attempt->marksObtained !== null;
        $studentQuestions = array_map(static function (array $question) use ($released): array {
            $safe = [
                'questionNumber' => $question['questionNumber'] ?? null,
                'questionText' => $question['questionText'] ?? null,
                'marks' => $question['marks'] ?? null,
            ];
            if ($released) {
                $safe['questionSourceLocations'] = $question['questionSourceLocations'] ?? [];
                $safe['solutionSourceLocations'] = $question['solutionSourceLocations'] ?? [];
            }
            return $safe;
        }, $questions);

        $attemptFiles = $attempt === null
            ? []
            : DB::table('test_attempt_files')
                ->where('attempt_id', $attempt->id)
                ->orderBy('created_at')
                ->get(['id', 'question_number', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
                ->map(fn (object $file): array => [
                    'id' => $file->id,
                    'questionNumber' => $file->question_number,
                    'originalName' => $file->original_name,
                    'mimeType' => $file->mime_type,
                    'sizeBytes' => $file->size_bytes,
                    'createdAt' => $this->isoDate($file->created_at),
                ])->all();

        $solutionSourceFiles = $released
            ? DB::table('test_source_files')
                ->where('test_id', $testId)
                ->orderBy('created_at')
                ->get(['id', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
                ->map(fn (object $file): array => [
                    'id' => $file->id,
                    'originalName' => $file->original_name,
                    'mimeType' => $file->mime_type,
                    'sizeBytes' => $file->size_bytes,
                ])->all()
            : [];

        return [
            'id' => $test->id,
            'title' => $test->title,
            'description' => $test->description,
            'testDate' => $test->testDate,
            'totalMarks' => (string) $test->totalMarks,
            'deadlineAt' => $this->isoDate($test->deadline_at),
            'questions' => $studentQuestions,
            'lectures' => $lectures,
            'attempt' => $this->studentAttemptView($attempt, $questions, $attemptFiles),
            'solutionSourceFiles' => $solutionSourceFiles,
            'effectiveDeadlineAt' => $this->isoDate($attempt->deadline_override_at ?? $test->deadline_at ?? null),
        ];
    }

    public function requestAccess(string $testId, string $studentId): array
    {
        $test = $this->requireStudentTestAccess($testId, $studentId);
        if (!DB::table('users')->where('id', $studentId)->exists()) {
            throw new NotFoundHttpException('Student not found');
        }

        $now = now();
        DB::table('test_attempts')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'testId' => $test->id,
            'studentId' => $studentId,
            'appeared' => false,
            'status' => 'NOT_ATTEMPTED',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        return DB::transaction(function () use ($test, $studentId): array {
            $attempt = DB::table('test_attempts')
                ->where('testId', $test->id)
                ->where('studentId', $studentId)
                ->lockForUpdate()
                ->first();
            if (!$attempt) {
                throw new NotFoundHttpException('Could not create test attempt.');
            }

            if ($attempt->submitted_at !== null ||
                in_array($attempt->status, ['PROCESSING', 'SUBMITTED', 'COMPLETED'], true)) {
                throw new BadRequestHttpException('This test has already been submitted.');
            }
            $cutoff = $attempt->deadline_override_at ?? $test->deadline_at;
            if ($cutoff === null || Carbon::parse($cutoff)->isFuture()) {
                throw new BadRequestHttpException('This test has not expired.');
            }
            if ($attempt->status === 'REQUESTED') {
                throw new BadRequestHttpException('An access request is already pending.');
            }

            $requestedAt = now();
            DB::table('test_attempts')
                ->where('id', $attempt->id)
                ->update([
                    'status' => 'REQUESTED',
                    'access_requested_at' => $requestedAt,
                    'updatedAt' => $requestedAt,
                ]);

            return [
                'requested' => true,
                'attemptId' => $attempt->id,
                'requestedAt' => $this->isoDate($requestedAt),
            ];
        });
    }

    private function requireStudentTestAccess(string $testId, string $studentId): object
    {
        if (!Str::isUuid($testId)) {
            throw new BadRequestHttpException('Invalid identifier');
        }

        $test = DB::table('tests')->where('id', $testId)->first();
        if (!$test) {
            throw new NotFoundHttpException('Test not found');
        }

        $hasAccess = DB::table('test_folders as tf')
            ->join('folder_permissions as fp', 'fp.folderId', '=', 'tf.folderId')
            ->where('tf.testId', $testId)
            ->where('fp.userId', $studentId)
            ->where('fp.canView', true)
            ->where('fp.billing_suspended', false)
            ->exists();
        if (!$hasAccess) {
            throw new AccessDeniedHttpException('No active folder access for this test.');
        }

        return $test;
    }

    private function studentAttemptView(?object $attempt, array $questions, array $files = []): ?array
    {
        if ($attempt === null) {
            return null;
        }
        $marksObtained = $attempt->marksObtained;
        $released = $marksObtained !== null;
        $assessment = $this->decodeJson($attempt->ai_assessment, []);
        if ($released && isset($assessment['questions']) && is_array($assessment['questions'])) {
            $assessment['questions'] = array_map(static function (array $result) use ($questions): array {
                if (!array_key_exists('solution', $result) || $result['solution'] === null) {
                    foreach ($questions as $question) {
                        if (($question['questionNumber'] ?? null) === ($result['questionNumber'] ?? null)) {
                            $result['solution'] = $question['idealAnswer'] ?? '';
                            break;
                        }
                    }
                    $result['solution'] ??= '';
                }
                return $result;
            }, $assessment['questions']);
        }

        return [
            'id' => $attempt->id,
            'status' => $attempt->status,
            'appeared' => (bool) $attempt->appeared,
            'submittedAt' => $this->isoDate($attempt->submitted_at),
            'submissionMode' => $attempt->submission_mode,
            'submittedAnswers' => $this->decodeJson($attempt->submitted_answers, null),
            'files' => $files,
            'reviewNote' => $attempt->review_note,
            'reviewSubmittedAt' => $this->isoDate($attempt->review_submitted_at),
            'marksObtained' => $marksObtained,
            'deficiencyNotes' => $released ? $attempt->deficiencyNotes : null,
            'aiAssessment' => $released && $assessment !== [] ? $assessment : null,
            'gradingError' => $attempt->grading_error
                ? 'The result could not be prepared. Please contact your administrator.'
                : null,
            'reviewResolution' => $attempt->review_resolution,
            'reviewResolvedAt' => $this->isoDate($attempt->review_resolved_at),
        ];
    }

    private function decodeJson(mixed $value, mixed $default): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function isoDate(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
