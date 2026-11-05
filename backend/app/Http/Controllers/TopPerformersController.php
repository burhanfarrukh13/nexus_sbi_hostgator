<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TopPerformersController
{
    public function __invoke(Request $request): JsonResponse
    {
        $studentId = (string) $request->attributes->get('apiIdentity')['userId'];
        if (!DB::table('users')->where('id', $studentId)->where('role', 'student')->exists()) {
            throw new AccessDeniedHttpException('Student account required.');
        }

        $subjectIds = DB::table('subject_user_access')
            ->where('user_id', $studentId)
            ->pluck('subject_id');
        if ($subjectIds->isEmpty()) {
            return response()->json([]);
        }

        $subjectIds = $subjectIds->sort()->values();
        $cacheKey = 'leaderboard:v1:'.hash('sha256', $subjectIds->implode('|').':'.$studentId);

        return response()->json(Cache::remember($cacheKey, now()->addSeconds(60), function () use ($studentId, $subjectIds): array {
        $performances = DB::table('test_attempts as attempt')
            ->join('tests as test', 'test.id', '=', 'attempt.testId')
            ->join('subject_user_access as access', function ($join): void {
                $join->on('access.subject_id', '=', 'test.subject_id')
                    ->on('access.user_id', '=', 'attempt.studentId');
            })
            ->join('users as student', 'student.id', '=', 'attempt.studentId')
            ->whereIn('test.subject_id', $subjectIds)
            ->where('student.role', 'student')
            ->whereNotNull('attempt.submitted_at')
            ->whereNotNull('attempt.marksObtained')
            ->where(function ($query): void {
                $query->whereNull('attempt.result_available_at')
                    ->orWhere('attempt.result_available_at', '<=', now());
            })
            ->groupBy('attempt.studentId')
            ->selectRaw(
                'attempt.studentId as studentId, COUNT(*) as testsAttempted, '.
                'SUM(attempt.marksObtained) as earnedMarks, SUM(test.totalMarks) as maxMarks'
            )
            ->get()
            ->map(static function (object $row): array {
                $earned = (float) $row->earnedMarks;
                $maximum = (float) $row->maxMarks;

                return [
                    'studentId' => (string) $row->studentId,
                    'testsAttempted' => (int) $row->testsAttempted,
                    'totalMarks' => round($earned, 2),
                    'maxMarks' => round($maximum, 2),
                    'percentage' => $maximum > 0 ? round($earned / $maximum * 100, 2) : 0,
                ];
            })
            ->sort(static fn (array $left, array $right): int =>
                ($right['percentage'] <=> $left['percentage']) ?:
                ($right['testsAttempted'] <=> $left['testsAttempted'])
            )
            ->values();

        $ranked = $performances->map(static fn (array $row, int $index): array => [
            ...$row,
            'rank' => $index + 1,
        ]);
        $topTen = $ranked->take(10);
        $currentUser = $ranked->firstWhere('studentId', $studentId);
        if ($currentUser !== null && !$topTen->contains('studentId', $studentId)) {
            $topTen->push($currentUser);
        }

        return $topTen->values()->map(static fn (array $row): array => [
            'rank' => $row['rank'],
            'displayIdentifier' => $row['studentId'] === $studentId ? 'You' : 'Learner '.$row['rank'],
            'isCurrentUser' => $row['studentId'] === $studentId,
            'testsAttempted' => $row['testsAttempted'],
            'totalMarks' => $row['totalMarks'],
            'maxMarks' => $row['maxMarks'],
            'percentage' => $row['percentage'],
        ])->all();
        }));
    }
}
