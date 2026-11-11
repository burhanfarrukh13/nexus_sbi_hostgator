<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TestSubmissionService
{
    private const MAX_FILES = 31;
    private const MAX_FILE_BYTES = 30 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 30 * 1024 * 1024;

    public function __construct(
        private readonly PrivateFileStorage $storage,
        private readonly TestAssessmentService $assessment,
        private readonly DocumentPageRenderer $renderer
    ) {
    }

    public function submit(
        string $testId,
        string $studentId,
        mixed $answersInput,
        mixed $uploads,
        mixed $clientSubmissionId = null
    ): array
    {
        $test = $this->requireStudentTestAccess($testId, $studentId);
        if ($clientSubmissionId !== null &&
            (!is_string($clientSubmissionId) || !Str::isUuid($clientSubmissionId))) {
            throw new BadRequestHttpException('clientSubmissionId must be a UUID.');
        }
        if (is_string($clientSubmissionId)) {
            $existing = DB::table('test_attempts')
                ->where('testId', $testId)
                ->where('studentId', $studentId)
                ->where('client_submission_id', $clientSubmissionId)
                ->whereNotNull('submitted_at')
                ->first();
            if ($existing) {
                return $this->existingSubmissionResponse($existing);
            }
        }
        $questions = $this->decodeJson($test->questions, []);
        if ($questions === []) {
            throw new BadRequestHttpException('This test does not contain online questions.');
        }

        $answers = $this->parseAnswers($answersInput);
        $files = $this->normalizeUploads($uploads);
        $prepared = $this->validateUploads($files, $questions);
        $answerMap = [];
        foreach ($answers as $answer) {
            if (isset($answerMap[$answer['questionNumber']])) {
                throw new BadRequestHttpException("Question {$answer['questionNumber']} was submitted more than once.");
            }
            $answerMap[$answer['questionNumber']] = $answer;
        }

        usort($questions, static fn (array $a, array $b): int => (int) $a['questionNumber'] <=> (int) $b['questionNumber']);
        $completePaper = count(array_filter($prepared, static fn (array $file): bool => in_array($file['category'], ['paper', 'paper-page', 'paper-source'], true))) > 0;
        $questionFileNumbers = array_filter($prepared, static fn (array $file): bool => $file['category'] === 'question');
        $hasTypedAnswers = array_filter($answers, static fn (array $answer): bool =>
            trim((string) ($answer['answerText'] ?? '')) !== '' ||
            !empty($answer['answerDelta'])
        ) !== [];
        if ($completePaper && (
            $questionFileNumbers !== [] ||
            $hasTypedAnswers
        )) {
            throw new BadRequestHttpException('Submit either one complete paper or answers/files for each question, not both.');
        }

        $answerTexts = [];
        foreach ($questions as $question) {
            $number = (int) $question['questionNumber'];
            $answerTexts[$number] = trim((string) ($answerMap[$number]['answerText'] ?? ''));
        }
        $fullPaperTexts = [];
        foreach ($prepared as $file) {
            if ($file['extractedText'] === '') {
                continue;
            }
            if ($file['category'] === 'question') {
                $number = $file['questionNumber'];
                $existing = trim((string) ($answerTexts[$number] ?? ''));
                if ($file['extractedText'] !== '') {
                    $answerTexts[$number] = $existing === ''
                        ? $file['extractedText']
                        : $existing."\n\n".$file['extractedText'];
                }
            } else {
                $fullPaperTexts[] = $file['extractedText'];
            }
        }
        $completePaperText = implode("\n\n", $fullPaperTexts);
        if ($completePaper) {
            foreach ($questions as $question) {
                $number = (int) $question['questionNumber'];
                $answerTexts[$number] = $completePaperText !== ''
                    ? "[See shared complete-paper text; match the student's Answer# labels to this question.]"
                    : '[See complete-paper image upload; align by visible question/answer labels.]';
            }
        }

        $this->assertImagePageLimit($prepared);
        $storedKeys = [];
        $oldKeys = [];
        try {
            $result = DB::transaction(function () use ($test, $studentId, $questions, $answerMap, $answerTexts, $prepared, $completePaper, $clientSubmissionId, &$storedKeys, &$oldKeys): array {
                $currentTest = DB::table('tests')
                    ->where('id', $test->id)
                    ->lockForUpdate()
                    ->first();
                if (!$currentTest) {
                    throw new NotFoundHttpException('Test not found.');
                }
                if ($currentTest->archived_at !== null) {
                    throw new BadRequestHttpException('This test is archived and no longer accepts submissions.');
                }
                $test = $currentTest;

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
                $attempt = DB::table('test_attempts')
                    ->where('testId', $test->id)
                    ->where('studentId', $studentId)
                    ->lockForUpdate()
                    ->first();
                if (!$attempt) {
                    throw new NotFoundHttpException('Could not create test attempt.');
                }

                if (in_array($attempt->status, ['PROCESSING', 'COMPLETED', 'SUBMITTED'], true) || $attempt->submitted_at !== null) {
                    if (is_string($clientSubmissionId) &&
                        $attempt->client_submission_id === $clientSubmissionId &&
                        $attempt->submitted_at !== null) {
                        return $this->existingSubmissionResponse($attempt) + ['_idempotent' => true];
                    }
                    throw new BadRequestHttpException('This test has already been submitted or is being graded.');
                }
                $cutoff = $attempt->deadline_override_at ?? $test->deadline_at;
                if ($cutoff !== null && Carbon::parse($cutoff)->lessThanOrEqualTo(now())) {
                    throw new AccessDeniedHttpException('The test deadline has passed.');
                }

                $oldKeys = DB::table('test_attempt_files')
                    ->where('attempt_id', $attempt->id)
                    ->whereNotNull('storage_key')
                    ->pluck('storage_key')
                    ->all();
                $fileIdsByIndex = [];
                foreach ($prepared as $index => $file) {
                    $uploaded = $file['upload'];
                    $key = $this->storage->store($uploaded, 'test-attempts');
                    $storedKeys[] = $key;
                    $fileId = (string) Str::uuid();
                    $fileIdsByIndex[$index] = $fileId;
                }

                $submittedAnswers = [];
                foreach ($questions as $question) {
                    $number = (int) $question['questionNumber'];
                    $answer = $answerMap[$number] ?? [];
                    $questionFiles = [];
                    foreach ($prepared as $index => $file) {
                        if ($file['category'] === 'question' && $file['questionNumber'] === $number) {
                            $questionFiles[] = $fileIdsByIndex[$index];
                        }
                    }
                    $text = trim((string) ($answerTexts[$number] ?? ''));
                    if ($text === '' && isset($answer['answerText'])) {
                        $text = trim((string) $answer['answerText']);
                    }
                    if ($text === '' && $questionFiles !== []) {
                        $text = "[Handwritten or image answer attached for Question {$number}.]";
                    } elseif ($text === '' && $completePaper) {
                        $text = '[See complete-paper upload; align by Answer# labels.]';
                    }
                    $submittedAnswers[] = [
                        'questionNumber' => $number,
                        'answerText' => $text,
                        ...(array_key_exists('answerDelta', $answer) ? ['answerDelta' => $answer['answerDelta']] : []),
                        'fileIds' => $questionFiles,
                    ];
                }

                DB::table('test_attempt_files')->where('attempt_id', $attempt->id)->delete();
                foreach ($prepared as $index => $file) {
                    DB::table('test_attempt_files')->insert([
                        'id' => $fileIdsByIndex[$index],
                        'attempt_id' => $attempt->id,
                        'question_number' => $file['category'] === 'question' ? $file['questionNumber'] : null,
                        'original_name' => mb_substr($file['name'], 0, 255),
                        'mime_type' => $file['mimeType'],
                        'size_bytes' => $file['size'],
                        'storage_key' => $storedKeys[$index],
                        'created_at' => $now,
                    ]);
                }
                DB::table('test_attempts')->where('id', $attempt->id)->update([
                    'status' => 'SUBMITTED',
                    'appeared' => true,
                    'submission_mode' => $completePaper ? 'COMPLETE_PAPER' : 'PER_QUESTION',
                    'submitted_at' => $now,
                    'draft_answers' => null,
                    'client_submission_id' => $clientSubmissionId,
                    'marksObtained' => null,
                    'deficiencyNotes' => null,
                    'ai_assessment' => null,
                    'submitted_answers' => json_encode($submittedAnswers, JSON_THROW_ON_ERROR),
                    'grading_error' => null,
                    'grading_attempts' => 0,
                    'review_note' => null,
                    'review_submitted_at' => null,
                    'review_resolution' => null,
                    'review_resolved_at' => null,
                    'result_available_at' => null,
                    'updatedAt' => $now,
                ]);

                return [
                    'attemptId' => $attempt->id,
                    'status' => 'SUBMITTED',
                    'submittedAt' => Carbon::parse($now)->toISOString(),
                ];
            });
        } catch (\Throwable $error) {
            foreach ($storedKeys as $key) {
                try {
                    $this->storage->delete($key);
                } catch (\Throwable) {
                }
            }
            throw $error;
        }

        foreach ($oldKeys as $key) {
            try {
                $this->storage->delete((string) $key);
            } catch (\Throwable) {
            }
        }
        if (($result['_idempotent'] ?? false) === true) {
            unset($result['_idempotent']);
            return $result;
        }

        $hasMedia = array_filter($prepared, static fn (array $file): bool =>
            str_starts_with($file['mimeType'], 'image/') || $file['mimeType'] === 'application/pdf'
        ) !== [];
        if ($hasMedia || (bool) config('services.grading.queue_typed', true)) {
            return $result + [
                'gradingPending' => true,
                'gradingQueued' => true,
                'gradingMessage' => 'Your answers are saved. You can close this page; your result will appear in My Tests when it is ready.',
            ];
        }

        try {
            $assessment = $this->assessment->gradeAttempt($testId, $result['attemptId']);

            return $result + [
                'gradingPending' => false,
                'resultAvailableAt' => $assessment['resultAvailableAt'] ?? null,
            ];
        } catch (\RuntimeException $error) {
            $retryable = $this->assessment->isRetryableFailure($error);
            $requeued = $retryable && $this->assessment->requeueAfterBackgroundFailure(
                (string) $result['attemptId'],
                $error->getMessage()
            );

            return array_merge($result, [
                'status' => 'SUBMITTED',
                'gradingPending' => true,
                'gradingQueued' => $requeued,
                'gradingMessage' => $requeued
                    ? 'Your answers are saved. You can close this page; your result will appear in My Tests when it is ready.'
                    : 'Your answers are saved. A teacher will check them.',
            ]);
        }
    }

    public function saveDraft(string $testId, string $studentId, mixed $answersInput): array
    {
        $test = $this->requireStudentTestAccess($testId, $studentId);
        $questions = $this->decodeJson($test->questions, []);
        $questionNumbers = array_fill_keys(array_map(
            static fn (array $question): int => (int) $question['questionNumber'],
            $questions
        ), true);
        $answers = $this->parseAnswers($answersInput);
        $seen = [];
        foreach ($answers as $answer) {
            if (!isset($questionNumbers[$answer['questionNumber']]) ||
                isset($seen[$answer['questionNumber']])) {
                throw new BadRequestHttpException('Draft answers must reference each test question at most once.');
            }
            $seen[$answer['questionNumber']] = true;
        }

        DB::table('test_attempts')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'testId' => $testId,
            'studentId' => $studentId,
            'appeared' => false,
            'status' => 'NOT_ATTEMPTED',
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        $savedAt = now();
        $updated = DB::transaction(function () use ($testId, $studentId, $answers, $savedAt): bool {
            $attempt = DB::table('test_attempts')
                ->where('testId', $testId)
                ->where('studentId', $studentId)
                ->lockForUpdate()
                ->first();
            if (!$attempt || $attempt->submitted_at !== null ||
                in_array($attempt->status, ['SUBMITTED', 'PROCESSING', 'COMPLETED'], true)) {
                return false;
            }
            DB::table('test_attempts')->where('id', $attempt->id)->update([
                'draft_answers' => json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'IN_PROGRESS',
                'updatedAt' => $savedAt,
            ]);

            return true;
        });

        return [
            'saved' => $updated,
            'savedAt' => $updated ? $savedAt->utc()->toISOString() : null,
        ];
    }

    private function existingSubmissionResponse(object $attempt): array
    {
        $completed = $attempt->status === 'COMPLETED' || $attempt->marksObtained !== null;

        return [
            'attemptId' => $attempt->id,
            'status' => $completed ? 'COMPLETED' : 'SUBMITTED',
            'submittedAt' => $attempt->submitted_at === null
                ? null
                : Carbon::parse($attempt->submitted_at)->toISOString(),
            'gradingPending' => !$completed,
            'gradingQueued' => !$completed && $attempt->status !== 'FAILED',
            'gradingMessage' => $completed
                ? 'Your saved answers have been checked.'
                : ($attempt->status === 'FAILED'
                    ? 'Your answers are saved. A teacher will check them.'
                    : 'Your answers are saved. You can close this page; your result will appear in My Tests when it is ready.'),
        ];
    }

    public function resubmit(string $testId, string $attemptId, string $studentId): array
    {
        $this->requireStudentTestAccess($testId, $studentId);
        if (!Str::isUuid($attemptId)) {
            throw new BadRequestHttpException('Invalid identifier');
        }
        $attempt = DB::table('test_attempts')
            ->where('id', $attemptId)
            ->where('testId', $testId)
            ->first();
        if (!$attempt) {
            throw new NotFoundHttpException('Test attempt not found.');
        }
        if ($attempt->studentId !== $studentId) {
            throw new NotFoundHttpException('Test attempt not found.');
        }
        if ($attempt->status !== 'FAILED' || $attempt->submitted_at === null) {
            throw new BadRequestHttpException('Only a failed submission with saved answers can be resubmitted.');
        }

        // Grading happens in the background worker; never inside this web request.
        return $this->assessment->requeueAttempt($testId, $attemptId, $studentId);
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
        if (!DB::table('users')->where('id', $studentId)->where('role', 'student')->exists()) {
            throw new AccessDeniedHttpException('Student account required.');
        }
        if (!DB::table('subject_user_access')
            ->where('user_id', $studentId)
            ->where('subject_id', $test->subject_id)
            ->exists()) {
            throw new AccessDeniedHttpException('You do not have access to this test subject.');
        }
        if ($test->archived_at !== null) {
            throw new BadRequestHttpException('This test is archived and no longer accepts submissions.');
        }

        return $test;
    }

    private function parseAnswers(mixed $value): array
    {
        if ($value === null) {
            $answers = [];
        } elseif (is_array($value)) {
            $answers = $value;
        } else {
            if (!is_string($value)) {
                throw new BadRequestHttpException('answers must be valid JSON.');
            }
            try {
                $answers = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new BadRequestHttpException('answers must be valid JSON.');
            }
        }
        if (!is_array($answers) || count($answers) > 100) {
            throw new BadRequestHttpException('answers must be an array with at most 100 items.');
        }

        foreach ($answers as $index => $answer) {
            if (!$answer instanceof \stdClass) {
                throw new BadRequestHttpException("answers.{$index} must be an object.");
            }
            $answer = get_object_vars($answer);
            foreach (array_keys($answer) as $key) {
                if (!in_array($key, ['questionNumber', 'answerText', 'answerDelta'], true)) {
                    throw new BadRequestHttpException("answers.{$index}.{$key} is not allowed.");
                }
            }
            $number = $answer['questionNumber'] ?? null;
            if ((!is_int($number) && !(is_float($number) && floor($number) === $number)) ||
                $number < 1 || $number > 100) {
                throw new BadRequestHttpException("answers.{$index}.questionNumber must be an integer between 1 and 100.");
            }
            $answer['questionNumber'] = (int) $number;
            if (isset($answer['answerText']) && (!is_string($answer['answerText']) || mb_strlen($answer['answerText']) > 20000)) {
                throw new BadRequestHttpException("answers.{$index}.answerText must be a string of at most 20000 characters.");
            }
            if (isset($answer['answerDelta']) && (!is_array($answer['answerDelta']) || count($answer['answerDelta']) > 5000)) {
                throw new BadRequestHttpException("answers.{$index}.answerDelta must be an array with at most 5000 items.");
            }
            $answers[$index] = $answer;
        }

        return $answers;
    }

    private function normalizeUploads(mixed $uploads): array
    {
        if ($uploads === null || $uploads === []) {
            return [];
        }
        if ($uploads instanceof UploadedFile) {
            $uploads = [$uploads];
        }
        if (!is_array($uploads) || count($uploads) > self::MAX_FILES) {
            throw new BadRequestHttpException('At most '.self::MAX_FILES.' files may be submitted.');
        }
        foreach ($uploads as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new BadRequestHttpException('A submitted file could not be uploaded.');
            }
            $size = $file->getSize();
            if ($size === false || $size < 1 || $size > self::MAX_FILE_BYTES) {
                throw new BadRequestHttpException($file->getClientOriginalName().': file size must be between 1 byte and 30 MiB.');
            }
        }
        if (array_sum(array_map(static fn (UploadedFile $file): int => (int) $file->getSize(), $uploads)) > self::MAX_TOTAL_BYTES) {
            throw new BadRequestHttpException('Combined answer files may not exceed 30 MiB.');
        }

        return array_values($uploads);
    }

    private function validateUploads(array $uploads, array $questions): array
    {
        $questionNumbers = array_map(static fn (array $question): int => (int) $question['questionNumber'], $questions);
        $seen = [];
        $prepared = [];
        $hasPaper = false;
        foreach ($uploads as $upload) {
            $name = $upload->getClientOriginalName();
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mimeType = $upload->getClientMimeType();
            $imageMimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $textMimes = [
                'pdf' => ['application/pdf', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'],
                'txt' => ['text/plain', 'application/octet-stream'],
            ];
            $isImage = isset($imageMimes[$extension]) && $imageMimes[$extension] === $mimeType;
            $isText = isset($textMimes[$extension]) && in_array($mimeType, $textMimes[$extension], true);
            if (!isset($imageMimes[$extension]) && !isset($textMimes[$extension])) {
                throw new BadRequestHttpException("{$name}: use PDF, DOCX, TXT, JPG, PNG, or WEBP.");
            }
            if (!$isImage && !$isText) {
                throw new BadRequestHttpException("{$name}: the file type does not match its extension.");
            }
            $bytes = file_get_contents($upload->getRealPath());
            if ($bytes === false) {
                throw new BadRequestHttpException("{$name}: the uploaded file could not be read.");
            }
            if ($extension === 'pdf' && !str_contains(substr($bytes, 0, 1024), '%PDF-')) {
                throw new BadRequestHttpException("{$name}: invalid PDF file.");
            }
            if ($extension === 'docx' && !str_starts_with($bytes, "PK")) {
                throw new BadRequestHttpException("{$name}: invalid DOCX file.");
            }

            $questionPageMatch = preg_match('/^question-(\d+)-page-(\d+)\.[^.]+$/i', $name, $questionPage);
            $questionMatch = preg_match('/^question-(\d+)\.[^.]+$/i', $name, $question);
            $pageMatch = preg_match('/^paper-page-(\d+)\.[^.]+$/i', $name, $page);
            $paperSource = preg_match('/^paper-source-.+\.pdf$/i', $name) === 1;
            $paper = preg_match('/^paper\.(pdf|docx|txt|jpg|jpeg|png|webp)$/i', $name) === 1;
            if ($questionMatch === 1) {
                $questionNumber = (int) $question[1];
                if (!in_array($questionNumber, $questionNumbers, true)) {
                    throw new BadRequestHttpException("File refers to unknown question {$questionNumber}.");
                }
                if (isset($seen["question-{$questionNumber}"])) {
                    throw new BadRequestHttpException("Only one file is allowed for question {$questionNumber}.");
                }
                $seen["question-{$questionNumber}"] = true;
                $category = 'question';
                $pageNumber = null;
            } elseif ($questionPageMatch === 1 && $isImage) {
                $questionNumber = (int) $questionPage[1];
                $pageNumber = (int) $questionPage[2];
                if (!in_array($questionNumber, $questionNumbers, true)) {
                    throw new BadRequestHttpException("File refers to unknown question {$questionNumber}.");
                }
                if ($pageNumber < 1 || $pageNumber > 30 || isset($seen["question-{$questionNumber}-page-{$pageNumber}"])) {
                    throw new BadRequestHttpException('Question answer page numbers must be unique and between 1 and 30.');
                }
                $seen["question-{$questionNumber}-page-{$pageNumber}"] = true;
                $category = 'question';
            } elseif ($pageMatch === 1 && $isImage) {
                $pageNumber = (int) $page[1];
                if ($pageNumber < 1 || $pageNumber > 30 || isset($seen["page-{$pageNumber}"])) {
                    throw new BadRequestHttpException('Complete-paper page numbers must be unique and between 1 and 30.');
                }
                $seen["page-{$pageNumber}"] = true;
                $category = 'paper-page';
                $questionNumber = null;
            } elseif ($paperSource && $isText && $extension === 'pdf') {
                if ($hasPaper || isset($seen['paper-source'])) {
                    throw new BadRequestHttpException('Submit only one complete paper document.');
                }
                $seen['paper-source'] = true;
                $category = 'paper-source';
                $questionNumber = null;
                $pageNumber = null;
                $hasPaper = true;
            } elseif ($paper) {
                if ($hasPaper || isset($seen['paper'])) {
                    throw new BadRequestHttpException('Submit only one complete paper document.');
                }
                $seen['paper'] = true;
                $category = 'paper';
                $questionNumber = null;
                $pageNumber = null;
                $hasPaper = true;
            } else {
                throw new BadRequestHttpException("{$name}: use question-<number>.<ext>, paper.<ext>, or paper-page-<number>.<image-ext>.");
            }
            $pageCount = null;
            if ($category === 'paper-source') {
                $pageCount = $this->pdfPageCount($bytes);
                if ($pageCount === null) {
                    throw new BadRequestHttpException("{$name}: could not read the PDF page count.");
                }
            }
            $prepared[] = [
                'upload' => $upload,
                'name' => $name,
                'mimeType' => $mimeType,
                'size' => (int) $upload->getSize(),
                'category' => $category,
                'questionNumber' => $questionNumber,
                'pageNumber' => $pageNumber,
                // Other PDF text extraction and page counting are deferred to the worker.
                // The preserved source copy is counted to verify its rendered pages here.
                'extractedText' => $extension === 'pdf' ? '' : $this->extractText($extension, $bytes, $upload->getRealPath()),
                'pageCount' => $pageCount,
            ];
        }

        $hasDirectPaper = count(array_filter(
            $prepared,
            static fn (array $file): bool => $file['category'] === 'paper'
        )) > 0;
        $hasPaperPages = count(array_filter(
            $prepared,
            static fn (array $file): bool => $file['category'] === 'paper-page'
        )) > 0;
        if ($hasDirectPaper && $hasPaperPages) {
            throw new BadRequestHttpException('Do not submit both a PDF document and separate complete-paper pages.');
        }

        foreach ($prepared as $file) {
            if ($file['category'] !== 'paper-source') {
                continue;
            }
            $pageNumbers = array_map(
                static fn (array $page): int => (int) $page['pageNumber'],
                array_filter($prepared, static fn (array $page): bool => $page['category'] === 'paper-page')
            );
            sort($pageNumbers);
            if ($pageNumbers !== range(1, (int) $file['pageCount'])) {
                throw new BadRequestHttpException('Every page of the original PDF must be included with the upload.');
            }
        }

        return $prepared;
    }

    private function extractText(string $extension, string $bytes, string|false $path): string
    {
        if ($extension === 'txt') {
            return trim($bytes);
        }
        if ($extension === 'docx') {
            $archive = new \ZipArchive();
            if ($path === false) {
                return '';
            }
            if ($archive->open($path) !== true) {
                return '';
            }
            $xml = $archive->getFromName('word/document.xml');
            $archive->close();
            if (!is_string($xml)) {
                return '';
            }

            return trim(html_entity_decode(strip_tags(str_replace(
                ['</w:p>', '<w:br/>', '<w:tab/>'],
                ["\n", "\n", "\t"],
                $xml
            )), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        if ($extension === 'pdf') {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                return trim($parser->parseContent($bytes)->getText());
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }

    private function assertImagePageLimit(array $prepared): void
    {
        $imageCount = 0;
        foreach ($prepared as $file) {
            if ($file['category'] === 'paper-source') {
                continue;
            }
            $imageCount += str_starts_with($file['mimeType'], 'image/')
                ? 1
                : (int) ($file['pageCount'] ?? 0);
        }
        if ($imageCount > 30) {
            throw new BadRequestHttpException('An exam can include at most 30 page images across photos and PDF pages.');
        }
    }

    private function pdfPageCount(string $bytes): ?int
    {
        try {
            $count = count((new \Smalot\PdfParser\Parser())->parseContent($bytes)->getPages());
        } catch (\Throwable) {
            return null;
        }
        if ($count < 1 || $count > DocumentPageRenderer::MAX_PAGES) {
            throw new BadRequestHttpException('PDF answer files must contain between 1 and 30 pages.');
        }

        return $count;
    }

    private function decodeJson(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $fallback;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fallback;
        }

        return is_array($decoded) ? $decoded : $fallback;
    }
}
