<?php

namespace App\Console\Commands;

use App\Services\TestAssessmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GradePendingTestAttempts extends Command
{
    protected $signature = 'ai-tests:grade-pending {--limit=1 : Maximum number of image submissions to grade}';

    protected $description = 'Grade queued image and scanned-paper test submissions';

    public function handle(TestAssessmentService $assessment): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10) {
            $this->error('The limit must be an integer from 1 to 10.');

            return self::INVALID;
        }

        $attempts = DB::table('test_attempts as attempt')
            ->where('attempt.status', 'SUBMITTED')
            ->whereNull('attempt.grading_error')
            ->whereNotNull('attempt.submitted_at')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('test_attempt_files as file')
                    ->whereColumn('file.attempt_id', 'attempt.id')
                    ->where('file.mime_type', 'like', 'image/%');
            })
            ->orderBy('attempt.submitted_at')
            ->limit($limit)
            ->get(['attempt.id', 'attempt.testId']);

        $failed = 0;
        foreach ($attempts as $attempt) {
            try {
                $assessment->gradePendingAttempt((string) $attempt->testId, (string) $attempt->id);
            } catch (Throwable $error) {
                $failed++;
                Log::error('Queued test grading command failed.', [
                    'attemptId' => $attempt->id,
                    'message' => $error->getMessage(),
                ]);
                $this->error("Attempt {$attempt->id} could not be graded.");
            }
        }

        $this->info('Processed '.$attempts->count().' queued image submission(s).');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
