<?php

namespace App\Http\Controllers;

use App\Services\SubjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AnalyticsController
{
    public function __construct(private readonly SubjectAccessService $subjectAccess)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('apiIdentity')['userId'];
        $allowedSubjectIds = $this->subjectAccess->allowedSubjectIds($userId);
        $scopeCacheKey = $allowedSubjectIds === null
            ? 'unrestricted'
            : hash('sha256', implode('|', $allowedSubjectIds));

        return response()->json(Cache::remember(
            'admin:analytics:overview:v2:'.$userId.':'.$scopeCacheKey,
            now()->addSeconds(60),
            function () use ($userId): array {
                $testIds = DB::table('tests');
                $this->subjectAccess->constrainSubjects($testIds, $userId, 'tests.subject_id');
                $testIds = $testIds->pluck('tests.id');

                $subjects = DB::table('subjects');
                $this->subjectAccess->constrainSubjects($subjects, $userId, 'subjects.id');
                $students = DB::table('users')->where('role', 'student');
                $this->subjectAccess->constrainStudents($students, $userId);
                $gradedAttempts = DB::table('test_attempts')->whereIn('testId', $testIds)->whereNotNull('marksObtained');
                $submittedAttempts = DB::table('test_attempts')->whereIn('testId', $testIds)->whereNotNull('submitted_at');
                $sources = DB::table('ai_notebook_sources');
                $this->subjectAccess->constrainSubjects($sources, $userId, 'ai_notebook_sources.subject_id');
                $queries = DB::table('student_queries as query')
                    ->join('users as user', 'user.id', '=', 'query.userId');
                $this->subjectAccess->constrainStudents($queries, $userId, 'user.id');

                return [
                    'totalStudents' => $students->count(),
                    'totalSubjects' => $subjects->count(),
                    'totalTests' => $testIds->count(),
                    'submittedAttempts' => $submittedAttempts->count(),
                    'gradedAttempts' => $gradedAttempts->count(),
                    'averageMarks' => (float) ($gradedAttempts->avg('marksObtained') ?? 0),
                    'notebookSources' => $sources->count(),
                    'studentQueries' => (clone $queries)->count(),
                    'openStudentQueries' => (clone $queries)->where('query.status', 'open')->count(),
                ];
            }
        ));
    }
}
