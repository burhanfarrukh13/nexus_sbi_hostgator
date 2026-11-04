<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LeaderboardService
{
    public function forUser(string $userId): array
    {
        $rows = DB::table('test_attempts as a')
            ->join('users as student', 'student.id', '=', 'a.studentId')
            ->join('tests as t', 't.id', '=', 'a.testId')
            ->where('a.appeared', true)
            ->whereNotNull('a.marksObtained')
            ->where('a.status', 'COMPLETED')
            ->selectRaw(
                'a.studentId as studentId, ROUND(SUM(a.marksObtained), 2) as totalMarks, '.
                'CAST(SUM(t.totalMarks) AS SIGNED) as maxMarks, COUNT(a.id) as testsAttempted'
            )
            ->groupBy('a.studentId')
            ->orderByDesc('totalMarks')
            ->orderByDesc('testsAttempted')
            ->orderBy('a.studentId')
            ->get();

        return $rows->values()->map(function (object $row, int $index) use ($userId): array {
            $isCurrentUser = $row->studentId === $userId;
            $rank = $index + 1;
            if (!$isCurrentUser) {
                return [
                    'rank' => $rank,
                    'displayIdentifier' => "Learner {$rank}",
                    'isCurrentUser' => false,
                ];
            }

            $totalMarks = (float) ($row->totalMarks ?? 0);
            $maxMarks = (int) ($row->maxMarks ?? 0);

            return [
                'rank' => $rank,
                'displayIdentifier' => 'You',
                'isCurrentUser' => true,
                'totalMarks' => $totalMarks,
                'maxMarks' => $maxMarks,
                'testsAttempted' => (int) ($row->testsAttempted ?? 0),
                'percentage' => $maxMarks > 0 ? (int) round(($totalMarks / $maxMarks) * 100) : 0,
            ];
        })->all();
    }
}
