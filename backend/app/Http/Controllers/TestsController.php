<?php

namespace App\Http\Controllers;

use App\Services\QuestionPreviewService;
use App\Services\PrivateFileStorage;
use App\Services\TestAuthoringService;
use App\Services\TestAssessmentService;
use App\Services\TestStudentAccessService;
use App\Services\TestSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TestsController
{
    public function __construct(
        private readonly TestAuthoringService $tests,
        private readonly TestAssessmentService $assessment,
        private readonly QuestionPreviewService $preview,
        private readonly TestStudentAccessService $studentAccess,
        private readonly TestSubmissionService $submissions
    ) {
    }

    public function myAttempts(Request $request): JsonResponse
    {
        if (!Schema::hasTable('tests') || !Schema::hasTable('test_attempts')) {
            return response()->json([]);
        }

        return response()->json(
            $this->studentAccess->listMyAttempts((string) $request->attributes->get('apiIdentity')['userId'])
        );
    }

    public function showForStudent(Request $request, string $id): JsonResponse
    {
        if (!Schema::hasTable('tests') || !Schema::hasTable('test_attempts')) {
            return response()->json([
                'id' => $id,
                'title' => 'Test unavailable',
                'description' => 'This database build does not include the test tables required for this feature.',
                'questions' => [],
                'attempt' => null,
            ]);
        }

        return response()->json(
            $this->studentAccess->getTestForStudent(
                $id,
                (string) $request->attributes->get('apiIdentity')['userId']
            )
        );
    }

    public function requestAccess(Request $request, string $id): JsonResponse
    {
        return response()->json(
            $this->studentAccess->requestAccess(
                $id,
                (string) $request->attributes->get('apiIdentity')['userId']
            ),
            201
        );
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return response()->json($this->submissions->submit(
            $id,
            (string) $request->attributes->get('apiIdentity')['userId'],
            $request->input('answers', '[]'),
            $request->file('files', [])
        ), 201);
    }

    public function resubmit(Request $request, string $id, string $attemptId): JsonResponse
    {
        return response()->json($this->submissions->resubmit(
            $id,
            $attemptId,
            (string) $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function index(): JsonResponse
    {
        if (!Schema::hasTable('tests')) {
            return response()->json([]);
        }

        return response()->json($this->tests->listAll());
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateBody($request);

        return response()->json(
            $this->tests->create($input, (string) $request->attributes->get('apiIdentity')['userId']),
            201
        );
    }

    public function previewQuestions(Request $request): JsonResponse
    {
        $files = $request->file('files', []);
        if (!is_array($files) || $files === []) {
            throw new BadRequestHttpException('At least one PDF or DOCX file is required.');
        }

        return response()->json($this->preview->preview($files), 201);
    }

    public function accessRequests(): JsonResponse
    {
        if (!Schema::hasTable('tests') || !Schema::hasTable('test_attempts')) {
            return response()->json([]);
        }

        $requests = DB::table('test_attempts as attempt')
            ->join('tests as test', 'test.id', '=', 'attempt.testId')
            ->join('users as student', 'student.id', '=', 'attempt.studentId')
            ->whereNotNull('attempt.access_requested_at')
            ->where('attempt.status', 'REQUESTED')
            ->orderBy('attempt.access_requested_at')
            ->get([
                'attempt.id as attemptId',
                'attempt.testId',
                'attempt.studentId',
                'attempt.access_requested_at as requestedAt',
                'test.title as testTitle',
                'test.totalMarks',
                'test.deadline_at as testDeadlineAt',
                'student.fullName as studentName',
                'student.email as studentEmail',
            ]);

        return response()->json($requests->map(fn (object $request): array => [
            'attemptId' => $request->attemptId,
            'testId' => $request->testId,
            'studentId' => $request->studentId,
            'requestedAt' => $request->requestedAt,
            'testTitle' => $request->testTitle,
            'totalMarks' => $request->totalMarks,
            'testDeadlineAt' => $request->testDeadlineAt,
            'studentName' => $request->studentName,
            'studentEmail' => $request->studentEmail,
        ])->all());
    }

    public function grantDeadline(Request $request, string $testId, string $studentId): JsonResponse
    {
        $input = $request->validate([
            'deadlineAt' => ['required', 'date'],
        ]);
        if (!Schema::hasTable('tests') || !Schema::hasTable('test_attempts')) {
            return response()->json(['message' => 'Test access tables are not installed.'], 503);
        }

        $test = DB::table('tests')->where('id', $testId)->first();
        $student = DB::table('users')->where('id', $studentId)->where('role', 'student')->first();
        if (!$test || !$student) {
            return response()->json(['message' => 'Test or student not found.'], 404);
        }
        $deadline = \Illuminate\Support\Carbon::parse($input['deadlineAt']);
        if (!$deadline->isFuture() ||
            ($test->deadline_at !== null && $deadline->lessThan(\Illuminate\Support\Carbon::parse($test->deadline_at)))) {
            return response()->json([
                'message' => 'The granted deadline must be in the future and cannot be earlier than the test deadline.',
            ], 422);
        }

        $updated = DB::transaction(function () use ($testId, $studentId, $deadline): bool {
            $attempt = DB::table('test_attempts')
                ->where('testId', $testId)
                ->where('studentId', $studentId)
                ->lockForUpdate()
                ->first();
            if (!$attempt || $attempt->status !== 'REQUESTED' || $attempt->access_requested_at === null) {
                return false;
            }
            if ($attempt->submitted_at !== null ||
                in_array($attempt->status, ['PROCESSING', 'SUBMITTED', 'COMPLETED'], true)) {
                return false;
            }

            DB::table('test_attempts')->where('id', $attempt->id)->update([
                'deadline_override_at' => $deadline,
                'access_requested_at' => null,
                'status' => 'IN_PROGRESS',
                'appeared' => true,
                'updatedAt' => now(),
            ]);

            return true;
        });
        if (!$updated) {
            return response()->json([
                'message' => 'A pending access request is required before a new deadline can be granted.',
            ], 422);
        }

        return response()->json(['ok' => true, 'testId' => $testId, 'studentId' => $studentId]);
    }

    public function gradingSettings(): JsonResponse
    {
        $setting = DB::table('ai_grading_settings')->where('id', 1)->first();

        return response()->json([
            'deficiencyReportPrompt' => $setting?->deficiency_report_prompt ?? '',
            'resultReleaseDelayHours' => (int) ($setting?->result_release_delay_hours ?? 24),
        ]);
    }

    public function saveGradingSettings(Request $request): JsonResponse
    {
        $input = $request->validate([
            'deficiencyReportPrompt' => ['nullable', 'string', 'max:4000'],
            'resultReleaseDelayHours' => ['required', 'integer', 'min:0', 'max:720'],
        ]);
        $payload = [
            'deficiencyReportPrompt' => $input['deficiencyReportPrompt'] ?? '',
            'resultReleaseDelayHours' => (int) $input['resultReleaseDelayHours'],
        ];
        DB::table('ai_grading_settings')->updateOrInsert(
            ['id' => 1],
            [
                'deficiency_report_prompt' => $payload['deficiencyReportPrompt'],
                'result_release_delay_hours' => $payload['resultReleaseDelayHours'],
                'updated_at' => now(),
            ]
        );

        return response()->json($payload);
    }

    public function attempts(string $testId): JsonResponse
    {
        return response()->json($this->assessment->listAttempts($testId));
    }

    public function retryAssessment(string $testId, string $attemptId): JsonResponse
    {
        try {
            return response()->json($this->assessment->gradeAttempt($testId, $attemptId));
        } catch (\RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 503);
        }
    }

    public function downloadAttemptFile(string $testId, string $fileId, PrivateFileStorage $storage): JsonResponse|StreamedResponse
    {
        $file = DB::table('test_attempt_files as file')
            ->join('test_attempts as attempt', 'attempt.id', '=', 'file.attempt_id')
            ->where('attempt.testId', $testId)
            ->where('file.id', $fileId)
            ->first(['file.storage_key', 'file.original_name', 'file.mime_type']);
        if (!$file || !is_string($file->storage_key)) {
            return response()->json(['message' => 'Submission file not found.'], 404);
        }

        try {
            $path = $storage->resolve($file->storage_key);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Submission file is unavailable.'], 404);
        }
        if (!is_file($path)) {
            return response()->json(['message' => 'Submission file is unavailable.'], 404);
        }

        return response()->streamDownload(
            static function () use ($path): void {
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    return;
                }
                fpassthru($stream);
                fclose($stream);
            },
            $file->original_name,
            [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'attachment'
        );
    }

    public function updateMarks(Request $request, string $testId, string $studentId): JsonResponse
    {
        $input = $request->validate([
            'marksObtained' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:10000'],
            'deficiencyNotes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'reviewResolution' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        if ($input === []) {
            return response()->json(['message' => 'Provide marks or a review response to save.'], 422);
        }

        return response()->json($this->assessment->updateMarks($testId, $studentId, $input));
    }

    private function validateBody(Request $request): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'testDate' => ['sometimes', 'nullable', 'date'],
            'deadlineAt' => ['sometimes', 'nullable', 'date'],
            'totalMarks' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
            'subjectId' => ['required', 'uuid:4', 'exists:subjects,id'],
            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*' => ['required', 'array:questionNumber,questionText,idealAnswer,difficulty,questionSourceLocations,solutionSourceLocations,marks,markingScheme'],
            'questions.*.questionNumber' => ['required', 'integer', 'min:1', 'max:100'],
            'questions.*.questionText' => ['required', 'string', 'min:1', 'max:20000'],
            'questions.*.idealAnswer' => ['required', 'string', 'min:1', 'max:20000'],
            'questions.*.difficulty' => ['sometimes', 'nullable', 'in:EASY,MODERATE,DIFFICULT'],
            'questions.*.questionSourceLocations' => ['sometimes', 'array', 'max:10'],
            'questions.*.solutionSourceLocations' => ['sometimes', 'array', 'max:10'],
            'questions.*.questionSourceLocations.*' => ['required', 'array:sourceIndex,pageNumbers'],
            'questions.*.questionSourceLocations.*.sourceIndex' => ['required', 'integer', 'min:0', 'max:9'],
            'questions.*.questionSourceLocations.*.pageNumbers' => ['required', 'array', 'max:30'],
            'questions.*.questionSourceLocations.*.pageNumbers.*' => ['required', 'integer', 'min:1', 'max:30'],
            'questions.*.solutionSourceLocations.*' => ['required', 'array:sourceIndex,pageNumbers'],
            'questions.*.solutionSourceLocations.*.sourceIndex' => ['required', 'integer', 'min:0', 'max:9'],
            'questions.*.solutionSourceLocations.*.pageNumbers' => ['required', 'array', 'max:30'],
            'questions.*.solutionSourceLocations.*.pageNumbers.*' => ['required', 'integer', 'min:1', 'max:30'],
            'questions.*.marks' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
            'questions.*.markingScheme' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*.markingScheme.*' => ['required', 'array:criterion,marks'],
            'questions.*.markingScheme.*.criterion' => ['required', 'string', 'min:1', 'max:2000'],
            'questions.*.markingScheme.*.marks' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:10000'],
        ];

        $validator = Validator::make($request->all(), $rules);
        $validator->after(function ($validator) use ($request): void {
            foreach (array_keys($request->all()) as $key) {
                if (!in_array($key, ['title', 'description', 'testDate', 'deadlineAt', 'totalMarks', 'subjectId', 'questions'], true)) {
                    $validator->errors()->add($key, 'The field is not allowed.');
                }
            }
            $questions = $request->input('questions');
            foreach (is_array($questions) ? $questions : [] as $index => $question) {
                if (!is_array($question)) {
                    continue;
                }
                if (array_key_exists('questionNumber', $question) && !is_int($question['questionNumber'])) {
                    $validator->errors()->add("questions.{$index}.questionNumber", 'The question number must be an integer.');
                }
                if (array_key_exists('marks', $question) && !is_int($question['marks']) && !is_float($question['marks'])) {
                    $validator->errors()->add("questions.{$index}.marks", 'The value must be a number.');
                }
                foreach (array_keys($question) as $key) {
                    if (!in_array($key, ['questionNumber', 'questionText', 'idealAnswer', 'difficulty', 'questionSourceLocations', 'solutionSourceLocations', 'marks', 'markingScheme'], true)) {
                        $validator->errors()->add("questions.{$index}.{$key}", 'The field is not allowed.');
                    }
                }
                foreach (['questionSourceLocations', 'solutionSourceLocations'] as $locationField) {
                    $locations = $question[$locationField] ?? [];
                    if (!is_array($locations)) {
                        continue;
                    }
                    foreach ($locations as $locationIndex => $location) {
                        if (!is_array($location)) {
                            continue;
                        }
                        if (array_key_exists('sourceIndex', $location) && !is_int($location['sourceIndex'])) {
                            $validator->errors()->add("questions.{$index}.{$locationField}.{$locationIndex}.sourceIndex", 'The value must be an integer.');
                        }
                        $pageNumbers = $location['pageNumbers'] ?? [];
                        if (is_array($pageNumbers)) {
                            foreach ($pageNumbers as $pageIndex => $pageNumber) {
                                if (!is_int($pageNumber)) {
                                    $validator->errors()->add("questions.{$index}.{$locationField}.{$locationIndex}.pageNumbers.{$pageIndex}", 'The value must be an integer.');
                                }
                            }
                        }
                    }
                }
                $criteria = $question['markingScheme'] ?? [];
                if (is_array($criteria)) {
                    foreach ($criteria as $criterionIndex => $criterion) {
                        if (is_array($criterion) && array_key_exists('marks', $criterion) &&
                            !is_int($criterion['marks']) && !is_float($criterion['marks'])) {
                            $validator->errors()->add("questions.{$index}.markingScheme.{$criterionIndex}.marks", 'The value must be a number.');
                        }
                    }
                }
            }
            $totalMarks = $request->input('totalMarks');
            if ($totalMarks !== null && !is_int($totalMarks) && !is_float($totalMarks)) {
                $validator->errors()->add('totalMarks', 'The value must be a number.');
            }
        });
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return $validator->validated();
    }
}
