<?php

namespace App\Http\Controllers;

use App\Services\DocumentPageRenderer;
use App\Services\PrivateFileStorage;
use App\Services\QuestionPreviewService;
use App\Services\TestAuthoringService;
use App\Services\TestAssessmentService;
use App\Services\TestStudentAccessService;
use App\Services\TestSubmissionService;
use App\Services\SubjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TestsController
{
    public function __construct(
        private readonly TestAuthoringService $tests,
        private readonly TestAssessmentService $assessment,
        private readonly QuestionPreviewService $preview,
        private readonly TestStudentAccessService $studentAccess,
        private readonly TestSubmissionService $submissions,
        private readonly SubjectAccessService $subjectAccess
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

    public function statusForStudent(Request $request, string $id): JsonResponse|\Illuminate\Http\Response
    {
        $status = $this->studentAccess->getStatusForStudent(
            $id,
            (string) $request->attributes->get('apiIdentity')['userId']
        );
        $etag = '"'.hash('sha256', json_encode($status, JSON_THROW_ON_ERROR)).'"';
        $headers = [
            'ETag' => $etag,
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response()->json($status, 200, $headers);
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
            $request->file('files', []),
            $request->input('clientSubmissionId')
        ), 201);
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        return response()->json($this->submissions->saveDraft(
            $id,
            (string) $request->attributes->get('apiIdentity')['userId'],
            $request->input('answers', '[]')
        ));
    }

    public function resubmit(Request $request, string $id, string $attemptId): JsonResponse
    {
        return response()->json($this->submissions->resubmit(
            $id,
            $attemptId,
            (string) $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function submitReview(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        return response()->json($this->studentAccess->submitReview(
            $id,
            (string) $request->attributes->get('apiIdentity')['userId'],
            trim($input['note'])
        ), 201);
    }

    public function storeSourceFiles(Request $request, string $testId, PrivateFileStorage $storage): JsonResponse
    {
        $this->assertManageableTest($testId, $request);
        if (!Str::isUuid($testId) || !DB::table('tests')->where('id', $testId)->exists()) {
            return response()->json(['message' => 'Test not found.'], 404);
        }
        $files = $request->file('files', []);
        if (!is_array($files) || count($files) < 1 || count($files) > 10) {
            throw new BadRequestHttpException('Upload between 1 and 10 PDF or DOCX source files.');
        }
        $totalBytes = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new BadRequestHttpException('A source file could not be uploaded.');
            }
            $size = (int) $file->getSize();
            $totalBytes += $size;
            if ($size < 1 || $size > 30 * 1024 * 1024) {
                throw new BadRequestHttpException('Each source file must be between 1 byte and 30 MiB.');
            }
            if (!in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'docx'], true)) {
                throw new BadRequestHttpException('Only PDF and DOCX source files are supported.');
            }
        }
        if ($totalBytes > 100 * 1024 * 1024) {
            throw new BadRequestHttpException('Combined source files may not exceed 100 MiB.');
        }

        $keys = [];
        try {
            foreach ($files as $index => $file) {
                $bytes = file_get_contents($file->getRealPath());
                if (!is_string($bytes)) {
                    throw new BadRequestHttpException('A source file could not be read.');
                }
                $extension = strtolower($file->getClientOriginalExtension());
                if (($extension === 'pdf' && !str_contains(substr($bytes, 0, 1024), '%PDF-')) ||
                    ($extension === 'docx' && !str_starts_with($bytes, 'PK'))) {
                    throw new BadRequestHttpException($file->getClientOriginalName().': invalid document file.');
                }
                $keys[$index] = $storage->store($file, 'test-sources');
            }
            DB::transaction(function () use ($testId, $files, $keys): void {
                $createdAt = now();
                foreach ($files as $index => $file) {
                    DB::table('test_source_files')->insert([
                        'id' => (string) Str::uuid(),
                        'test_id' => $testId,
                        'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                        'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                        'size_bytes' => (int) $file->getSize(),
                        'storage_key' => $keys[$index],
                        'created_at' => $createdAt->copy()->addSeconds($index),
                    ]);
                }
            });
        } catch (\Throwable $error) {
            foreach ($keys as $key) {
                try {
                    $storage->delete($key);
                } catch (\Throwable) {
                }
            }
            throw $error;
        }

        return response()->json([
            'sourceFiles' => $this->sourceFilesForTest($testId),
        ], 201);
    }

    public function downloadSourceFile(string $testId, string $fileId, PrivateFileStorage $storage): JsonResponse|StreamedResponse
    {
        $this->assertManageableTest($testId, request());
        $file = DB::table('test_source_files')
            ->where('test_id', $testId)
            ->where('id', $fileId)
            ->first();
        if (!$file || !is_string($file->storage_key)) {
            return response()->json(['message' => 'Source file not found.'], 404);
        }
        try {
            $path = $storage->resolve($file->storage_key);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Source file is unavailable.'], 404);
        }
        if (!is_file($path)) {
            return response()->json(['message' => 'Source file is unavailable.'], 404);
        }

        return response()->streamDownload(
            static function () use ($path): void {
                readfile($path);
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

    public function sourceFilePageInfo(string $testId, string $fileId, PrivateFileStorage $storage, DocumentPageRenderer $renderer): JsonResponse
    {
        $this->assertManageableTest($testId, request());
        $file = DB::table('test_source_files')->where('test_id', $testId)->where('id', $fileId)->first();
        return $this->pageInfoResponse($file, $storage, $renderer);
    }

    public function sourceFilePage(string $testId, string $fileId, string $pageNumber, PrivateFileStorage $storage, DocumentPageRenderer $renderer): \Illuminate\Http\Response|JsonResponse
    {
        $this->assertManageableTest($testId, request());
        $file = DB::table('test_source_files')->where('test_id', $testId)->where('id', $fileId)->first();
        return $this->pageResponse($file, $pageNumber, $storage, $renderer);
    }

    public function attemptFilePageInfo(string $testId, string $fileId, PrivateFileStorage $storage, DocumentPageRenderer $renderer): JsonResponse
    {
        $this->assertManageableTest($testId, request());
        $file = DB::table('test_attempt_files as file')
            ->join('test_attempts as attempt', 'attempt.id', '=', 'file.attempt_id')
            ->where('attempt.testId', $testId)
            ->where('file.id', $fileId)
            ->first(['file.*']);
        return $this->pageInfoResponse($file, $storage, $renderer);
    }

    public function attemptFilePage(string $testId, string $fileId, string $pageNumber, PrivateFileStorage $storage, DocumentPageRenderer $renderer): \Illuminate\Http\Response|JsonResponse
    {
        $this->assertManageableTest($testId, request());
        $file = DB::table('test_attempt_files as file')
            ->join('test_attempts as attempt', 'attempt.id', '=', 'file.attempt_id')
            ->where('attempt.testId', $testId)
            ->where('file.id', $fileId)
            ->first(['file.*']);
        return $this->pageResponse($file, $pageNumber, $storage, $renderer);
    }

    public function studentAttemptFilePageInfo(Request $request, string $testId, string $fileId, PrivateFileStorage $storage, DocumentPageRenderer $renderer): JsonResponse
    {
        $file = $this->studentAttemptFile($request, $testId, $fileId);
        return $this->pageInfoResponse($file, $storage, $renderer);
    }

    public function studentAttemptFilePage(Request $request, string $testId, string $fileId, string $pageNumber, PrivateFileStorage $storage, DocumentPageRenderer $renderer): \Illuminate\Http\Response|JsonResponse
    {
        $file = $this->studentAttemptFile($request, $testId, $fileId);
        return $this->pageResponse($file, $pageNumber, $storage, $renderer);
    }

    public function studentSourceFilePageInfo(Request $request, string $testId, string $fileId, PrivateFileStorage $storage, DocumentPageRenderer $renderer): JsonResponse
    {
        $this->assertStudentResultReleased($request, $testId);
        $file = DB::table('test_source_files')->where('test_id', $testId)->where('id', $fileId)->first();
        return $this->pageInfoResponse($file, $storage, $renderer);
    }

    public function studentSourceFilePage(Request $request, string $testId, string $fileId, string $pageNumber, PrivateFileStorage $storage, DocumentPageRenderer $renderer): \Illuminate\Http\Response|JsonResponse
    {
        $this->assertStudentResultReleased($request, $testId);
        $file = DB::table('test_source_files')->where('test_id', $testId)->where('id', $fileId)->first();
        return $this->pageResponse($file, $pageNumber, $storage, $renderer);
    }

    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('tests')) {
            return response()->json([]);
        }

        $tests = $this->tests->listAll();
        $subjectIds = $this->subjectAccess->allowedSubjectIds(
            (string) $request->attributes->get('apiIdentity')['userId']
        );
        if ($subjectIds !== null) {
            $tests = array_values(array_filter($tests, static fn (array $test): bool =>
                in_array((string) ($test['subjectId'] ?? ''), $subjectIds, true)
            ));
        }

        return response()->json($tests);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateBody($request);
        $this->subjectAccess->assertSubjectAccessible(
            (string) $request->attributes->get('apiIdentity')['userId'],
            (string) $input['subjectId']
        );

        return response()->json(
            $this->tests->create($input, (string) $request->attributes->get('apiIdentity')['userId']),
            201
        );
    }

    public function updateTitle(Request $request, string $testId): JsonResponse
    {
        $this->assertManageableTest($testId, $request);
        $input = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
        ]);
        if (trim($input['title']) === '') {
            throw new BadRequestHttpException('The test title cannot be blank.');
        }

        return response()->json($this->tests->updateTitle(
            $testId,
            $input['title'],
            (string) $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function destroy(Request $request, string $testId, PrivateFileStorage $storage): JsonResponse
    {
        $this->assertManageableTest($testId, $request);
        $input = $request->validate([
            'mode' => ['required', 'in:archive,permanent'],
        ]);
        $actorId = (string) $request->attributes->get('apiIdentity')['userId'];

        if ($input['mode'] === 'archive') {
            return response()->json($this->tests->archive($testId, $actorId));
        }

        return response()->json($this->tests->permanentlyDelete($testId, $actorId, $storage));
    }

    public function previewQuestions(Request $request): JsonResponse
    {
        $files = $request->file('files', []);
        if (!is_array($files) || $files === []) {
            throw new BadRequestHttpException('At least one PDF or DOCX file is required.');
        }

        return response()->json($this->preview->preview($files), 201);
    }

    public function accessRequests(Request $request): JsonResponse
    {
        if (!Schema::hasTable('tests') || !Schema::hasTable('test_attempts')) {
            return response()->json([]);
        }

        $requests = DB::table('test_attempts as attempt')
            ->join('tests as test', 'test.id', '=', 'attempt.testId')
            ->join('users as student', 'student.id', '=', 'attempt.studentId')
            ->whereNull('test.archived_at')
            ->whereNotNull('attempt.access_requested_at')
            ->where('attempt.status', 'REQUESTED')
            ->orderBy('attempt.access_requested_at');
        $this->subjectAccess->constrainSubjects(
            $requests,
            (string) $request->attributes->get('apiIdentity')['userId'],
            'test.subject_id'
        );
        $requests = $requests
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
        if (!$test || !$student || $test->archived_at !== null) {
            return response()->json(['message' => 'Test or student not found.'], 404);
        }
        $identity = $request->attributes->get('apiIdentity');
        $this->subjectAccess->assertTestAccessible((string) $identity['userId'], $testId);
        $this->subjectAccess->assertStudentAccessible((string) $identity['userId'], $studentId);
        if (!DB::table('subject_user_access')
            ->where('subject_id', $test->subject_id)
            ->where('user_id', $studentId)
            ->exists()) {
            throw new AccessDeniedHttpException('The student does not have access to this test subject.');
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
            'resultReleaseDelayHours' => (int) ($setting?->result_release_delay_hours ?? 0),
            'notebookDailyQuestionLimit' => (int) ($setting?->notebook_daily_question_limit ?? 15),
        ]);
    }

    public function saveGradingSettings(Request $request): JsonResponse
    {
        $input = $request->validate([
            'deficiencyReportPrompt' => ['nullable', 'string', 'max:4000'],
            'resultReleaseDelayHours' => ['required', 'integer', 'min:0', 'max:720'],
            'notebookDailyQuestionLimit' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
        ]);
        $payload = [
            'deficiencyReportPrompt' => $input['deficiencyReportPrompt'] ?? '',
            'resultReleaseDelayHours' => (int) $input['resultReleaseDelayHours'],
            'notebookDailyQuestionLimit' => (int) ($input['notebookDailyQuestionLimit']
                ?? DB::table('ai_grading_settings')->where('id', 1)->value('notebook_daily_question_limit')
                ?? 15),
        ];
        DB::table('ai_grading_settings')->updateOrInsert(
            ['id' => 1],
            [
                'deficiency_report_prompt' => $payload['deficiencyReportPrompt'],
                'result_release_delay_hours' => $payload['resultReleaseDelayHours'],
                'notebook_daily_question_limit' => $payload['notebookDailyQuestionLimit'],
                'updated_at' => now(),
            ]
        );

        return response()->json($payload);
    }

    public function attempts(string $testId): JsonResponse
    {
        $this->assertManageableTest($testId, request());
        return response()->json($this->assessment->listAttempts($testId));
    }

    public function retryAssessment(string $testId, string $attemptId): JsonResponse
    {
        $this->assertManageableTest($testId, request());
        // Queue the submission; the background grading worker picks it up.
        return response()->json($this->assessment->requeueAttempt($testId, $attemptId));
    }

    public function downloadAttemptFile(string $testId, string $fileId, PrivateFileStorage $storage): JsonResponse|StreamedResponse
    {
        $this->assertManageableTest($testId, request());
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
        $this->assertManageableTest($testId, $request);
        $this->subjectAccess->assertStudentAccessible(
            (string) $request->attributes->get('apiIdentity')['userId'],
            $studentId
        );
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

    private function sourceFilesForTest(string $testId): array
    {
        return DB::table('test_source_files')
            ->where('test_id', $testId)
            ->orderBy('created_at')
            ->get(['id', 'original_name', 'mime_type', 'size_bytes', 'created_at'])
            ->map(static fn (object $file): array => [
                'id' => $file->id,
                'originalName' => $file->original_name,
                'mimeType' => $file->mime_type,
                'sizeBytes' => (int) $file->size_bytes,
                'createdAt' => $file->created_at,
            ])->all();
    }

    private function assertManageableTest(string $testId, Request $request): object
    {
        if (!Str::isUuid($testId)) {
            throw new BadRequestHttpException('Invalid test identifier.');
        }

        return $this->subjectAccess->assertTestAccessible(
            (string) $request->attributes->get('apiIdentity')['userId'],
            $testId
        );
    }

    private function pageInfoResponse(?object $file, PrivateFileStorage $storage, DocumentPageRenderer $renderer): JsonResponse
    {
        if (!$file || !is_string($file->storage_key ?? null)) {
            return response()->json(['message' => 'Page source file not found.'], 404);
        }
        try {
            $path = $storage->resolve($file->storage_key);
            if (!is_file($path)) {
                return response()->json(['message' => 'Page source file is unavailable.'], 404);
            }
            $mimeType = (string) ($file->mime_type ?? 'application/octet-stream');
            $pageCount = $mimeType === 'application/pdf'
                ? $renderer->pageCount($path)
                : (str_starts_with($mimeType, 'image/') ? 1 : 0);
            if ($pageCount < 1) {
                return response()->json(['message' => 'Only PDF and image files have renderable pages.'], 422);
            }

            return response()->json(['pageCount' => $pageCount]);
        } catch (RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 503);
        }
    }

    private function pageResponse(
        ?object $file,
        string $pageNumber,
        PrivateFileStorage $storage,
        DocumentPageRenderer $renderer
    ): \Illuminate\Http\Response|JsonResponse {
        if (!$file || !is_string($file->storage_key ?? null)) {
            return response()->json(['message' => 'Page source file not found.'], 404);
        }
        if (!ctype_digit($pageNumber) || (int) $pageNumber < 1 || (int) $pageNumber > 30) {
            return response()->json(['message' => 'Page number must be between 1 and 30.'], 422);
        }
        try {
            $path = $storage->resolve($file->storage_key);
            if (!is_file($path)) {
                return response()->json(['message' => 'Page source file is unavailable.'], 404);
            }
            $page = $renderer->renderPage($path, (string) $file->mime_type, (int) $pageNumber);

            return response($page['buffer'], 200, [
                'Content-Type' => $page['mimeType'],
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (RuntimeException $error) {
            $status = str_contains(strtolower($error->getMessage()), 'does not exist') ||
                str_contains(strtolower($error->getMessage()), 'contain one page')
                ? 404
                : 503;

            return response()->json(['message' => $error->getMessage()], $status);
        }
    }

    private function studentAttemptFile(Request $request, string $testId, string $fileId): ?object
    {
        $identity = (array) $request->attributes->get('apiIdentity');
        $file = DB::table('test_attempt_files as file')
            ->join('test_attempts as attempt', 'attempt.id', '=', 'file.attempt_id')
            ->join('tests as test', 'test.id', '=', 'attempt.testId')
            ->where('attempt.testId', $testId)
            ->where('attempt.studentId', $identity['userId'] ?? '')
            ->where('file.id', $fileId)
            ->first([
                'file.*',
                'test.subject_id',
                'test.archived_at',
                'attempt.submitted_at',
                'attempt.status as attempt_status',
            ]);
        if (!$file || !DB::table('subject_user_access')
            ->where('user_id', $identity['userId'] ?? '')
            ->where('subject_id', $file->subject_id)
            ->exists()) {
            return null;
        }
        if ($file->archived_at !== null && $file->submitted_at === null &&
            !in_array($file->attempt_status, ['SUBMITTED', 'PROCESSING', 'COMPLETED', 'FAILED'], true)) {
            return null;
        }

        return $file;
    }

    private function assertStudentResultReleased(Request $request, string $testId): void
    {
        $identity = (array) $request->attributes->get('apiIdentity');
        $test = DB::table('tests')->where('id', $testId)->first();
        if (!$test || ($identity['role'] ?? null) !== 'student' ||
            !DB::table('subject_user_access')
                ->where('user_id', $identity['userId'] ?? '')
                ->where('subject_id', $test->subject_id)
                ->exists()) {
            throw new AccessDeniedHttpException('You do not have access to this test.');
        }
        $attempt = DB::table('test_attempts')
            ->where('testId', $testId)
            ->where('studentId', $identity['userId'])
            ->first();
        if (!$attempt || ($test->archived_at !== null && $attempt->submitted_at === null &&
                !in_array($attempt->status, ['SUBMITTED', 'PROCESSING', 'COMPLETED', 'FAILED'], true)) ||
            $attempt->status !== 'COMPLETED' || $attempt->marksObtained === null ||
            ($attempt->result_available_at !== null && Carbon::parse($attempt->result_available_at)->isFuture())) {
            throw new AccessDeniedHttpException('Source pages are available after results are released.');
        }
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
