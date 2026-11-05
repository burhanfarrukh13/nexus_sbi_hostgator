<?php

namespace App\Http\Controllers;

use App\Console\Commands\GradePendingTestAttempts;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin view of the background grading queue: what is waiting, what is stuck and what
 * failed, so a class's papers are never silently lost.
 */
class GradingQueueController
{
    /** A paper that has waited this long without being graded counts as a backlog. */
    private const LONG_WAIT_MINUTES = 60;

    private const LIST_LIMIT = 100;

    /** The cron runs every 15 minutes at most; two missed runs mean something is wrong. */
    private const WORKER_SILENT_MINUTES = 35;

    public function index(): JsonResponse
    {
        $now = now();
        $staleMinutes = (int) config('services.grading.stale_minutes', 45);

        $byStatus = DB::table('test_attempts')
            ->whereNotNull('submitted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $blocked = DB::table('test_attempts')
            ->where('status', 'SUBMITTED')
            ->whereNotNull('grading_error')
            ->count();
        $waitingQuery = DB::table('test_attempts')->where('status', 'SUBMITTED')->whereNull('grading_error');
        $waiting = (clone $waitingQuery)->count();
        $oldestWaiting = (clone $waitingQuery)->min('submitted_at');
        $worker = $this->workerStatus($waiting + $blocked, $now);
        $durationSamples = Cache::get('nexus:grading:paper-duration-samples', []);
        $durationSamples = is_array($durationSamples) ? array_filter($durationSamples, 'is_numeric') : [];
        $measuredSecondsPerPaper = $durationSamples === []
            ? null
            : round(array_sum($durationSamples) / count($durationSamples), 1);
        $workerCount = max(1, (int) config('services.grading.workers', 2));
        $estimatedSecondsToClear = $waiting > 0 && $measuredSecondsPerPaper !== null
            ? (int) ceil($waiting * $measuredSecondsPerPaper / $workerCount)
            : null;
        $alerts = [];
        if ($worker['stalled']) {
            $alerts[] = 'The grading worker heartbeat is overdue while work is waiting.';
        }
        if ((int) ($byStatus['FAILED'] ?? 0) > 0 || $blocked > 0) {
            $alerts[] = 'One or more submissions need administrator review.';
        }
        if ((int) ($worker['lastRun']['providerRetries'] ?? 0) > 0) {
            $alerts[] = 'The last grading run retried provider failures.';
        }
        if ($oldestWaiting !== null && $this->minutesSince($oldestWaiting, $now) >= self::LONG_WAIT_MINUTES) {
            $alerts[] = 'The grading backlog includes work waiting at least one hour.';
        }

        $needsAttention = fn ($query) => $this->needsAttention($query, $now, $staleMinutes);
        $attentionTotal = $needsAttention(DB::table('test_attempts as attempt'))->count();
        $rows = $needsAttention(DB::table('test_attempts as attempt'))
            ->join('tests as test', 'test.id', '=', 'attempt.testId')
            ->join('users as student', 'student.id', '=', 'attempt.studentId')
            ->orderBy('attempt.submitted_at')
            ->limit(self::LIST_LIMIT)
            ->get([
                'attempt.id',
                'attempt.testId',
                'attempt.status',
                'attempt.grading_attempts',
                'attempt.grading_error',
                'attempt.submitted_at',
                'attempt.updatedAt',
                'test.title as testTitle',
                'student.email as studentEmail',
                'student.studentId as studentCrn',
                'student.fullName as studentName',
            ]);

        return response()->json([
            'generatedAt' => $now->toIso8601String(),
            'worker' => $worker,
            'counts' => [
                'waiting' => $waiting,
                'processing' => (int) ($byStatus['PROCESSING'] ?? 0),
                'failed' => (int) ($byStatus['FAILED'] ?? 0),
                'blocked' => $blocked,
                'completedLast24h' => DB::table('test_attempts')
                    ->where('status', 'COMPLETED')
                    ->where('updatedAt', '>=', $now->copy()->subDay())
                    ->count(),
            ],
            'oldestWaitingMinutes' => $oldestWaiting === null ? null : $this->minutesSince($oldestWaiting, $now),
            'estimatedClear' => [
                'seconds' => $estimatedSecondsToClear,
                'measuredSecondsPerPaper' => $measuredSecondsPerPaper,
                'sampleCount' => count($durationSamples),
                'workers' => $workerCount,
            ],
            'alerts' => $alerts,
            'settings' => [
                'staleMinutes' => $staleMinutes,
                'providerRetryDelayMinutes' => [5, 15, 30, 60],
                'longWaitMinutes' => self::LONG_WAIT_MINUTES,
            ],
            'usage' => [
                'aiNotebookRequestsToday' => (int) DB::table('ai_notebook_daily_usage')
                    ->where('usageDate', Carbon::now((string) config('services.ai_notebook.day_reset_timezone', 'Asia/Karachi'))->toDateString())
                    ->sum('requestCount'),
            ],
            'attentionTotal' => $attentionTotal,
            'attempts' => $rows->map(fn (object $row): array => [
                'id' => $row->id,
                'testId' => $row->testId,
                'testTitle' => $row->testTitle,
                'studentEmail' => $row->studentEmail,
                'studentCrn' => $row->studentCrn,
                'studentName' => $row->studentName,
                'status' => $row->status,
                'issue' => $this->issueFor($row, $now, $staleMinutes),
                'gradingAttempts' => (int) $row->grading_attempts,
                'gradingError' => $row->grading_error,
                'submittedAt' => $row->submitted_at,
                'waitingMinutes' => $row->submitted_at === null ? null : $this->minutesSince($row->submitted_at, $now),
            ])->all(),
        ]);
    }

    /** Puts every FAILED paper, and every waiting paper the worker skips because of an old error, back in the queue. */
    public function retryAll(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        $requeued = DB::transaction(function () use ($identity): int {
            $count = DB::table('test_attempts')
                ->whereNotNull('submitted_at')
                ->where(function ($query): void {
                    $query->where('status', 'FAILED')
                        ->orWhere(function ($blocked): void {
                            $blocked->where('status', 'SUBMITTED')->whereNotNull('grading_error');
                        });
                })
                ->update([
                    'status' => 'SUBMITTED',
                    'grading_error' => null,
                    'grading_attempts' => 0,
                    'updatedAt' => now(),
                ]);
            if ($count > 0) {
                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(),
                    'event' => 'grading_queue_retry_all',
                    'details' => 'Failed grading attempts were put back in the queue',
                    'userId' => is_array($identity) ? ($identity['userId'] ?? null) : null,
                    'metadata' => json_encode(['requeued' => $count], JSON_THROW_ON_ERROR),
                    'createdAt' => now(),
                ]);
            }

            return $count;
        });

        return response()->json(['requeued' => $requeued]);
    }

    /**
     * The grading command leaves a heartbeat in the cache on every run. Papers waiting
     * while the heartbeat is missing or old means the cPanel cron job is not running.
     */
    private function workerStatus(int $queued, CarbonInterface $now): array
    {
        $heartbeat = Cache::get(GradePendingTestAttempts::HEARTBEAT_KEY);
        $lastRunAt = is_array($heartbeat) && is_string($heartbeat['at'] ?? null) ? $heartbeat['at'] : null;
        $silentMinutes = $lastRunAt === null ? null : $this->minutesSince($lastRunAt, $now);

        return [
            'lastRunAt' => $lastRunAt,
            'state' => is_array($heartbeat) ? ($heartbeat['state'] ?? null) : null,
            'lastRun' => is_array($heartbeat) ? array_intersect_key($heartbeat, array_flip([
                'graded', 'failed', 'requeued', 'providerRetries', 'measuredSecondsPerPaper', 'seconds',
            ])) : [],
            'silentMinutes' => $silentMinutes,
            'stalled' => $queued > 0 && ($silentMinutes === null || $silentMinutes > self::WORKER_SILENT_MINUTES),
        ];
    }

    private function needsAttention(
$query, CarbonInterface $now, int $staleMinutes)
    {
        $stuckBefore = $now->copy()->subMinutes($staleMinutes);
        $longWaitBefore = $now->copy()->subMinutes(self::LONG_WAIT_MINUTES);

        return $query->whereNotNull('attempt.submitted_at')->where(function ($outer) use ($stuckBefore, $longWaitBefore): void {
            $outer->where('attempt.status', 'FAILED')
                ->orWhere(function ($blocked): void {
                    $blocked->where('attempt.status', 'SUBMITTED')->whereNotNull('attempt.grading_error');
                })
                ->orWhere(function ($stuck) use ($stuckBefore): void {
                    $stuck->where('attempt.status', 'PROCESSING')->where('attempt.updatedAt', '<', $stuckBefore);
                })
                ->orWhere(function ($backlog) use ($longWaitBefore): void {
                    $backlog->where('attempt.status', 'SUBMITTED')
                        ->whereNull('attempt.grading_error')
                        ->where('attempt.submitted_at', '<', $longWaitBefore);
                });
        });
    }

    private function issueFor(object $row, CarbonInterface $now, int $staleMinutes): string
    {
        return match (true) {
            $row->status === 'FAILED' => 'failed',
            $row->status === 'PROCESSING' => 'stuck',
            $row->grading_error !== null => 'blocked',
            default => 'backlog',
        };
    }

    private function minutesSince(mixed $timestamp, CarbonInterface $now): int
    {
        return max(0, (int) floor(\Carbon\Carbon::parse($timestamp)->diffInMinutes($now, false)));
    }
}
