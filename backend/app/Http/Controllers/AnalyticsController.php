<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AnalyticsController
{
    public function overview(): JsonResponse
    {
        return response()->json(Cache::remember('admin:analytics:overview:v1', now()->addSeconds(60), static function (): array {
        $gradedAttempts = DB::table('test_attempts')->whereNotNull('marksObtained');
        return [
            'totalStudents' => DB::table('users')->where('role', 'student')->count(),
            'totalSubjects' => DB::table('subjects')->count(),
            'totalTests' => DB::table('tests')->count(),
            'submittedAttempts' => DB::table('test_attempts')->whereNotNull('submitted_at')->count(),
            'gradedAttempts' => (clone $gradedAttempts)->count(),
            'averageMarks' => (float) ((clone $gradedAttempts)->avg('marksObtained') ?? 0),
            'notebookSources' => DB::table('ai_notebook_sources')->count(),
            'studentQueries' => DB::table('student_queries')->count(),
            'openStudentQueries' => DB::table('student_queries')->where('status', 'open')->count(),
        ];
        }));
    }
}
