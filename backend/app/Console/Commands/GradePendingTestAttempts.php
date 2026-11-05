<?php

namespace App\Console\Commands;

use App\Services\PrivateFileStorage;
use App\Services\TestAssessmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GradePendingTestAttempts extends Command
{
    protected $signature = 'ai-tests:grade-pending
        {--limit=0 : Maximum submissions to grade in this run (0 = keep going until the queue is empty or time runs out)}
        {--max-seconds= : Stop starting new submissions after this many seconds (default: GRADING_RUN_SECONDS, 780)}';

    protected $description = 'Grade queued test submissions (typed answers, photos and scanned papers)';

    /** Consecutive provider failures before a run stops and waits for the next cron tick. */
    private const PROVIDER_FAILURE_LIMIT = 3;

    /** Cache key the admin dashboard reads to tell whether the cron job is really running. */
    public const HEARTBEAT_KEY = 'nexus:grading:heartbeat';

    private function heartbeat(string $state, array $summary = []): void
    {
        try {
            Cache::put(self::HEARTBEAT_KEY, ['at' => now()->toIso8601String(), 'state' => $state] + $summary, now()->addDays(7));
        } catch (Throwable) {
            // A broken cache must never stop grading.
        }
    }

    public function handle(TestAssessmentService $assessment, PrivateFileStorage $storage): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 0 || $limit > 500) {
            $this->error('The limit must be an integer from 0 to 500.');

            return self::INVALID;
        }
        $maxSeconds = $this->option('max-seconds');
        $maxSeconds = $maxSeconds === null || $maxSeconds === ''
            ? (int) config('services.grading.run_seconds', 780)
            : filter_var($maxSeconds, FILTER_VALIDATE_INT);
        if ($maxSeconds === false || $maxSeconds < 30 || $maxSeconds > 1500) {
            $this->error('max-seconds must be an integer from 30 to 1500.');

            return self::INVALID;
        }

        // A cron run that starts while every worker is still busy simply exits; the
        // running workers keep draining the queue.
        $worker = $storage->acquireWorkerLock();
        if ($worker === null) {
            $this->info('All grading workers are already running; nothing to do.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $graded = 0;
        $failed = 0;
        $requeued = 0;
        $providerRetries = 0;
        $providerFailures = 0;
        $paperDurations = [];
        $stoppedEarly = false;
        $seen = [];

        try {
            $recovered = $assessment->recoverStaleAttempts();
            if ($recovered > 0) {
                $this->warn("Recovered {$recovered} stuck grading attempt(s).");
            }

            while (($limit === 0 || $graded + $failed + $requeued < $limit)
                && microtime(true) - $startedAt < $maxSeconds) {
                $this->heartbeat('running');
                // Submissions that have not been tried yet go first, then the oldest.
                $candidates = DB::table('test_attempts')
                    ->where('status', 'SUBMITTED')
                    ->whereNull('grading_error')
                    ->whereNotNull('submitted_at')
                    ->where('updatedAt', '<=', now())
                    ->when($seen !== [], fn ($query) => $query->whereNotIn('id', array_keys($seen)))
                    ->orderBy('grading_attempts')
                    ->orderBy('submitted_at')
                    ->limit(5)
                    ->get(['id', 'testId']);
                if ($candidates->isEmpty()) {
                    break;
                }

                foreach ($candidates as $candidate) {
                    $attemptId = (string) $candidate->id;
                    $seen[$attemptId] = true;
                    $paperStartedAt = microtime(true);
                    try {
                        if (!$assessment->gradePendingAttempt((string) $candidate->testId, $attemptId)) {
                            continue; // another worker claimed it first
                        }
                        $graded++;
                        $paperDurations[] = microtime(true) - $paperStartedAt;
                        $providerFailures = 0;
                    } catch (Throwable $error) {
                        $message = $error->getMessage();
                        $retryable = $assessment->isRetryableFailure($error);
                        Log::error('Queued test grading failed.', [
                            'attemptId' => $attemptId,
                            'message' => $message,
                        ]);
                        if ($assessment->requeueAfterBackgroundFailure($attemptId, $error)) {
                            $requeued++;
                            if ($retryable) {
                                $providerRetries++;
                            }
                        } else {
                            $failed++;
                            $this->error("Attempt {$attemptId} could not be graded.");
                        }
                        $providerFailures = $retryable ? $providerFailures + 1 : 0;
                    }
                    break;
                }

                if ($providerFailures >= self::PROVIDER_FAILURE_LIMIT) {
                    $stoppedEarly = true;
                    $this->warn('The AI provider keeps failing; stopping this run so it can recover.');
                    break;
                }
                gc_collect_cycles();
            }
        } finally {
            $storage->releaseWorkerLock($worker);
        }

        $seconds = (int) round(microtime(true) - $startedAt);
        $measuredSamples = [];
        try {
            $measuredSamples = Cache::get('nexus:grading:paper-duration-samples', []);
            $measuredSamples = is_array($measuredSamples) ? $measuredSamples : [];
            if ($paperDurations !== []) {
                $measuredSamples = array_slice([...$measuredSamples, ...$paperDurations], -50);
                Cache::put('nexus:grading:paper-duration-samples', $measuredSamples, now()->addDays(30));
            }
        } catch (Throwable) {
            $measuredSamples = $paperDurations;
        }
        $measuredSecondsPerPaper = $measuredSamples === []
            ? null
            : round(array_sum($measuredSamples) / count($measuredSamples), 1);
        $this->heartbeat('finished', [
            'graded' => $graded,
            'failed' => $failed,
            'requeued' => $requeued,
            'providerRetries' => $providerRetries,
            'measuredSecondsPerPaper' => $measuredSecondsPerPaper,
            'seconds' => $seconds,
        ]);
        $this->info("Graded {$graded}, failed {$failed}, requeued {$requeued} in {$seconds}s.");

        return $failed > 0 || $stoppedEarly ? self::FAILURE : self::SUCCESS;
    }
}
