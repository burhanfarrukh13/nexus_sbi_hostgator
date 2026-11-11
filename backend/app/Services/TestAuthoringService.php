<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\PrivateFileStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TestAuthoringService
{
    public function listAll(): array
    {
        return DB::table('tests')->orderByDesc('createdAt')->get()
            ->map(fn (object $test): array => $this->testView($test))
            ->all();
    }

    public function create(array $data, string $actorId): array
    {
        $actor = DB::table('users')->where('id', $actorId)->first();
        if (!$actor) {
            throw new NotFoundHttpException('Actor user not found');
        }
        $permissions = DB::table('user_role_assignments')
            ->where('userId', $actorId)
            ->pluck('permission')
            ->all();
        if (!in_array('admin', $permissions, true) && !in_array('teacher_assistant', $permissions, true)) {
            throw new AccessDeniedHttpException('Missing permission: teacher_assistant or admin');
        }

        $subjectId = (string) ($data['subjectId'] ?? '');
        if (!Str::isUuid($subjectId) || !DB::table('subjects')->where('id', $subjectId)->exists()) {
            throw new BadRequestHttpException('Select a valid notebook subject for this test.');
        }
        $totalMarks = (float) $data['totalMarks'];
        if (!$this->validMark($totalMarks) || $totalMarks < 0.01 || $totalMarks > 10000) {
            throw new BadRequestHttpException('Total marks must be positive and have at most two decimal places.');
        }

        $questions = $this->normalizeQuestions($data['questions'] ?? []);
        if ($questions !== [] && empty($data['deadlineAt'])) {
            throw new BadRequestHttpException('Set a deadline for every online test.');
        }
        $questionTotal = array_sum(array_column($questions, 'marks'));
        if ($questions !== [] && abs($questionTotal - $totalMarks) > 1e-6) {
            throw new BadRequestHttpException("Total marks must equal the sum of question marks ({$questionTotal}).");
        }
        if (!empty($data['deadlineAt']) && Carbon::parse($data['deadlineAt'])->lessThanOrEqualTo(now())) {
            throw new BadRequestHttpException('The test deadline must be in the future.');
        }

        $id = (string) Str::uuid();
        $now = now();
        try {
            DB::transaction(function () use ($data, $questions, $subjectId, $actorId, $totalMarks, $id, $now): void {
                DB::table('tests')->insert([
                    'id' => $id,
                    'title' => trim($data['title']),
                    'description' => array_key_exists('description', $data) && $data['description'] !== null
                        ? trim($data['description'])
                        : null,
                    'testDate' => empty($data['testDate']) ? null : Carbon::parse($data['testDate'])->toDateString(),
                    'totalMarks' => number_format($totalMarks, 2, '.', ''),
                    'deadline_at' => empty($data['deadlineAt']) ? null : Carbon::parse($data['deadlineAt']),
                    'questions' => json_encode($questions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'createdById' => $actorId,
                    'subject_id' => $subjectId,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
            });
        } catch (QueryException $exception) {
            throw $exception;
        }

        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'test_created',
            'details' => "Test {$id} created",
            'userId' => $actorId,
            'createdAt' => now(),
        ]);

        return $this->testView(DB::table('tests')->where('id', $id)->first());
    }

    public function updateTitle(string $testId, string $title, string $actorId): array
    {
        $this->assertCanManageTest($testId, $actorId);

        DB::transaction(function () use ($testId, $title): void {
            $test = DB::table('tests')->where('id', $testId)->lockForUpdate()->first();
            if (!$test) {
                throw new NotFoundHttpException('Test not found.');
            }

            DB::table('tests')->where('id', $testId)->update([
                'title' => trim($title),
                'updatedAt' => now(),
            ]);
        });

        $this->recordLifecycleAudit($actorId, 'test_title_updated', "Test {$testId} title updated");

        return $this->testView(DB::table('tests')->where('id', $testId)->first());
    }

    public function archive(string $testId, string $actorId): array
    {
        $this->assertCanManageTest($testId, $actorId);

        DB::transaction(function () use ($testId): void {
            $test = DB::table('tests')->where('id', $testId)->lockForUpdate()->first();
            if (!$test) {
                throw new NotFoundHttpException('Test not found.');
            }

            if ($test->archived_at === null) {
                DB::table('tests')->where('id', $testId)->update([
                    'archived_at' => now(),
                    'updatedAt' => now(),
                ]);
            }
        });

        $this->recordLifecycleAudit($actorId, 'test_archived', "Test {$testId} archived");

        return $this->testView(DB::table('tests')->where('id', $testId)->first());
    }

    public function permanentlyDelete(string $testId, string $actorId, PrivateFileStorage $storage): array
    {
        $this->assertCanManageTest($testId, $actorId);

        DB::transaction(function () use ($testId, $actorId, $storage): void {
            $test = DB::table('tests')->where('id', $testId)->lockForUpdate()->first();
            if (!$test) {
                throw new NotFoundHttpException('Test not found.');
            }

            $storageKeys = DB::table('test_source_files')
                ->where('test_id', $testId)
                ->whereNotNull('storage_key')
                ->pluck('storage_key')
                ->all();
            $attemptStorageKeys = DB::table('test_attempt_files as file')
                ->join('test_attempts as attempt', 'attempt.id', '=', 'file.attempt_id')
                ->where('attempt.testId', $testId)
                ->whereNotNull('file.storage_key')
                ->pluck('file.storage_key')
                ->all();

            // Delete is idempotent for missing files. If any file cannot be
            // removed, keep the database records so the operation can be retried.
            foreach (array_unique([...$storageKeys, ...$attemptStorageKeys]) as $storageKey) {
                if (is_string($storageKey) && $storageKey !== '') {
                    $storage->delete($storageKey);
                }
            }

            // The schema cascades attempt/report and file metadata rows from tests.
            DB::table('tests')->where('id', $testId)->delete();
            $this->recordLifecycleAudit($actorId, 'test_permanently_deleted', "Test {$testId} permanently deleted");
        });

        return ['ok' => true, 'id' => $testId, 'deleted' => true];
    }

    private function assertCanManageTest(string $testId, string $actorId): void
    {
        $actor = DB::table('users')->where('id', $actorId)->first();
        if (!$actor) {
            throw new NotFoundHttpException('Actor user not found.');
        }

        $permissions = DB::table('user_role_assignments')
            ->where('userId', $actorId)
            ->pluck('permission')
            ->all();
        if (!in_array('admin', $permissions, true) &&
            !in_array('teacher_assistant', $permissions, true)) {
            throw new AccessDeniedHttpException('Missing permission: teacher_assistant or admin');
        }

        app(SubjectAccessService::class)->assertTestAccessible($actorId, $testId);
    }

    private function recordLifecycleAudit(string $actorId, string $event, string $details): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'details' => $details,
            'userId' => $actorId,
            'createdAt' => now(),
        ]);
    }

    private function normalizeQuestions(array $questions): array
    {
        if (count($questions) > 100) {
            throw new BadRequestHttpException('A test may contain at most 100 questions.');
        }
        $numbers = [];
        $normalized = [];
        foreach ($questions as $question) {
            $number = (int) $question['questionNumber'];
            if ($number < 1 || $number > 100 || isset($numbers[$number])) {
                throw new BadRequestHttpException('Question numbers must be unique and between 1 and 100.');
            }
            $numbers[$number] = true;
            $questionText = trim($question['questionText']);
            $idealAnswer = trim($question['idealAnswer']);
            if ($questionText === '' || $idealAnswer === '') {
                throw new BadRequestHttpException("Question {$number} requires a question and answer.");
            }
            $scheme = $question['markingScheme'] ?? null;
            if (!is_array($scheme) || $scheme === []) {
                throw new BadRequestHttpException("Question {$number} requires a marking scheme.");
            }
            $criteria = [];
            foreach ($scheme as $criterion) {
                $name = preg_replace('/\s*\|\s*\d+(?:\.\d+)?\s*$/', '', $criterion['criterion']) ?? '';
                $name = trim($name);
                $marks = (float) $criterion['marks'];
                if ($name === '' || !$this->validMark($marks) || $marks < 0.01) {
                    throw new BadRequestHttpException("Question {$number} has an invalid marking criterion.");
                }
                $criteria[] = ['criterion' => $name, 'marks' => $marks];
            }
            $criteriaTotal = array_sum(array_column($criteria, 'marks'));
            $marks = (float) $question['marks'];
            if (!$this->validMark($marks) || $marks < 0.01 || abs($criteriaTotal - $marks) > 1e-6) {
                throw new BadRequestHttpException("Question {$number} marks must equal its marking-criteria total ({$criteriaTotal}).");
            }
            $normalizedQuestion = [
                'questionNumber' => $number,
                'questionText' => $questionText,
                'idealAnswer' => $idealAnswer,
            ];
            foreach (['questionSourceLocations', 'solutionSourceLocations'] as $locationKey) {
                if (!empty($question[$locationKey])) {
                    $normalizedQuestion[$locationKey] = $question[$locationKey];
                }
            }
            if (!empty($question['difficulty'])) {
                $normalizedQuestion['difficulty'] = $question['difficulty'];
            }
            $normalizedQuestion['markingScheme'] = $criteria;
            $normalizedQuestion['marks'] = round($criteriaTotal, 2);
            $normalized[] = $normalizedQuestion;
        }
        usort($normalized, static fn (array $a, array $b): int => $a['questionNumber'] <=> $b['questionNumber']);
        foreach ($normalized as $index => $question) {
            if ($question['questionNumber'] !== $index + 1) {
                throw new BadRequestHttpException('Question numbers must be contiguous, starting at 1.');
            }
        }
        return $normalized;
    }

    private function validMark(float $value): bool
    {
        return is_finite($value) && abs($value * 100 - round($value * 100)) < 1e-7;
    }

    private function testView(object $test): array
    {
        $subject = DB::table('subjects')->where('id', $test->subject_id)->first();

        $createdBy = null;
        if (!empty($test->createdById)) {
            $user = DB::table('users')->where('id', $test->createdById)
                ->first(['id', 'email', 'role', 'studentId', 'fullName', 'phone', 'must_reset_password', 'createdAt', 'updatedAt']);
            if ($user) {
                $createdBy = [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'studentId' => $user->studentId,
                    'fullName' => $user->fullName,
                    'phone' => $user->phone,
                    'mustResetPassword' => (bool) $user->must_reset_password,
                    'createdAt' => $user->createdAt,
                    'updatedAt' => $user->updatedAt,
                ];
            }
        }

        return [
            'id' => $test->id,
            'title' => $test->title,
            'description' => $test->description,
            'testDate' => $test->testDate,
            'totalMarks' => number_format((float) $test->totalMarks, 2, '.', ''),
            'deadlineAt' => $test->deadline_at,
            'archivedAt' => $test->archived_at,
            'questions' => $this->decodeJson($test->questions, []),
            'subjectId' => $test->subject_id,
            'createdById' => $test->createdById,
            'createdAt' => $test->createdAt,
            'updatedAt' => $test->updatedAt,
            'subject' => $subject ? [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
            ] : null,
            'createdBy' => $createdBy,
            'sourceFiles' => $this->sourceFiles($test->id),
        ];
    }

    private function sourceFiles(string $testId): array
    {
        return DB::table('test_source_files')->where('test_id', $testId)->orderBy('created_at')
            ->get(['id', 'test_id', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
            ->map(fn (object $file): array => [
                'id' => $file->id,
                'originalName' => $file->original_name,
                'mimeType' => $file->mime_type,
                'sizeBytes' => $file->size_bytes,
                'createdAt' => $file->created_at,
            ])->all();
    }

    private function decodeJson(mixed $value, array $default): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }
}
