<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AnalyticsController
{
    public function overview(): JsonResponse
    {
        $gradedAttempts = DB::table('test_attempts')->whereNotNull('marksObtained');

        return response()->json([
            'totalStudents' => DB::table('users')->where('role', 'student')->count(),
            'totalSubjects' => DB::table('subjects')->count(),
            'totalTests' => DB::table('tests')->count(),
            'submittedAttempts' => DB::table('test_attempts')->whereNotNull('submitted_at')->count(),
            'gradedAttempts' => (clone $gradedAttempts)->count(),
            'averageMarks' => (float) ((clone $gradedAttempts)->avg('marksObtained') ?? 0),
            'notebookSources' => DB::table('ai_notebook_sources')->count(),
            'studentQueries' => DB::table('student_queries')->count(),
            'openStudentQueries' => DB::table('student_queries')->where('status', 'open')->count(),
        ]);
    }
}
