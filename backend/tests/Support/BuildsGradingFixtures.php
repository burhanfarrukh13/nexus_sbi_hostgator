<?php

namespace Tests\Support;

use App\Services\CloudChatClient;
use App\Services\DocumentPageRenderer;
use App\Services\JwtTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared set-up for the grading-queue tests: users, a one-question test, queued
 * attempts, stored answer files and a scripted AI provider.
 */
trait BuildsGradingFixtures
{
    private string $privateRoot = '';

    /** Points private storage at a throwaway folder so tests never touch real papers. */
    protected function useTemporaryPrivateStorage(): void
    {
        $this->privateRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nexus-test-'.Str::random(12);
        mkdir($this->privateRoot, 0700, true);
        config([
            'filesystems.private_root' => $this->privateRoot,
            'services.grading.workers' => 2,
            'services.grading.stale_minutes' => 45,
        ]);
        $this->beforeApplicationDestroyed(function (): void {
            $this->deleteDirectory($this->privateRoot);
        });
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }

    protected function useChat(CloudChatClient $chat): void
    {
        $this->app->instance(CloudChatClient::class, $chat);
    }

    /**
     * A renderer that never needs Imagick or pdftoppm: it returns $jpeg for every page,
     * or fails like a server without a renderer when $jpeg is null.
     */
    protected function useRenderer(?string $jpeg): void
    {
        $this->app->instance(DocumentPageRenderer::class, new class($jpeg) extends DocumentPageRenderer {
            public function __construct(private readonly ?string $jpeg)
            {
            }

            public function renderPage(string $path, string $mimeType, int $pageNumber): array
            {
                if ($this->jpeg === null) {
                    throw new RuntimeException('PDF page rendering is unavailable.');
                }

                return ['buffer' => $this->jpeg, 'mimeType' => 'image/jpeg'];
            }
        });
    }

    /** One-question test worth 2 marks; returns [subjectId, testId]. */
    protected function createTest(): array
    {
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'code' => 'T-'.Str::upper(Str::random(8)),
            'name' => 'Mathematics',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $testId = (string) Str::uuid();
        DB::table('tests')->insert([
            'id' => $testId,
            'title' => 'Quick arithmetic',
            'deadline_at' => now()->addDay(),
            'totalMarks' => '2.00',
            'questions' => json_encode([[
                'questionNumber' => 1,
                'questionText' => 'What is 2 + 2?',
                'idealAnswer' => '4',
                'marks' => 2,
            ]], JSON_THROW_ON_ERROR),
            'subject_id' => $subjectId,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return [$subjectId, $testId];
    }

    protected function createUser(string $role = 'student'): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('current-account-password'),
            'role' => $role,
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    protected function grantSubject(string $studentId, string $subjectId): void
    {
        DB::table('subject_user_access')->insert([
            'subject_id' => $subjectId,
            'user_id' => $studentId,
            'created_at' => now(),
        ]);
    }

    protected function token(string $userId): string
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

    /**
     * Inserts an attempt for a fresh student. Options: status, submittedMinutesAgo,
     * updatedMinutesAgo, gradingAttempts, gradingError, answers (null = no typed answer).
     */
    protected function insertAttempt(string $testId, array $options = []): string
    {
        $studentId = $this->createUser();
        $id = (string) Str::uuid();
        $answers = array_key_exists('answers', $options)
            ? $options['answers']
            : [['questionNumber' => 1, 'answerText' => '4']];
        DB::table('test_attempts')->insert([
            'id' => $id,
            'testId' => $testId,
            'studentId' => $studentId,
            'appeared' => 1,
            'status' => $options['status'] ?? 'SUBMITTED',
            'submitted_at' => now()->subMinutes($options['submittedMinutesAgo'] ?? 1),
            'submitted_answers' => $answers === null ? null : json_encode($answers, JSON_THROW_ON_ERROR),
            'grading_attempts' => $options['gradingAttempts'] ?? 0,
            'grading_error' => $options['gradingError'] ?? null,
            'createdAt' => now()->subMinutes(120),
            'updatedAt' => now()->subMinutes($options['updatedMinutesAgo'] ?? 1),
        ]);

        return $id;
    }

    /** Stores bytes in private storage and links them to the attempt, like a real upload. */
    protected function attachFile(string $attemptId, string $name, string $mimeType, string $bytes, ?int $questionNumber = null): void
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $key = 'attempt-files/'.Str::uuid().'.'.$extension;
        $path = $this->privateRoot.DIRECTORY_SEPARATOR.'attempt-files'.DIRECTORY_SEPARATOR.basename($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $bytes);
        DB::table('test_attempt_files')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'question_number' => $questionNumber,
            'original_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($bytes),
            'storage_key' => $key,
            'created_at' => now(),
        ]);
    }

    /** A valid PDF with the requested page count and a text layer on each page. */
    protected function makePdf(string $text, int $pageCount = 1): string
    {
        $pageCount = max(1, $pageCount);
        $pageIds = [];
        for ($page = 1; $page <= $pageCount; $page++) {
            $pageId = 3 + (($page - 1) * 2);
            $pageIds[] = "{$pageId} 0 R";
        }
        $fontId = 3 + ($pageCount * 2);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', $pageIds).'] /Count '.$pageCount.' >>',
        ];
        for ($page = 1; $page <= $pageCount; $page++) {
            $pageId = 3 + (($page - 1) * 2);
            $contentId = $pageId + 1;
            $pageText = $text === '' ? '' : "{$text} Page {$page}";
            $stream = "BT /F1 18 Tf 72 700 Td ({$pageText}) Tj ET";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentId} 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    protected function makeJpeg(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** A well-formed model answer that awards $marks to the single criterion of question 1. */
    protected function gradedAnswer(float $marks = 2, string $evidence = '4'): array
    {
        return [
            'deficiencyReport' => 'Good work overall.',
            'questions' => [[
                'questionNumber' => 1,
                'deficiencyReport' => 'Question feedback.',
                'criteria' => [[
                    'criterionIndex' => 0,
                    'marksAwarded' => $marks,
                    'feedback' => 'Criterion feedback.',
                    'evidence' => [$evidence],
                ]],
            ]],
        ];
    }

    /** Responder that transcribes any page as "4" and grades everything else normally. */
    protected function transcribingGrader(): \Closure
    {
        return fn (array $messages): array => ScriptedChatClient::isTranscription($messages)
            ? [
                'activeQuestionNumber' => 1,
                'activeQuestionHeading' => 'Question 1',
                'transcriptions' => [['questionNumber' => 1, 'answerText' => '4', 'uncertainty' => '']],
            ]
            : $this->gradedAnswer();
    }

    protected function statusOf(string $attemptId): string
    {
        return (string) DB::table('test_attempts')->where('id', $attemptId)->value('status');
    }
}
