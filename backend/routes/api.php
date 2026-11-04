<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\AiNotebookController;
use App\Http\Controllers\FoldersController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SchoolsController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\StudentsController;
use App\Http\Controllers\StudentQueriesController;
use App\Http\Controllers\TeamMembersController;
use App\Http\Controllers\TestsController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware(['auth.compat', 'permission.compat:admin|testing_manager'])
    ->prefix('admin/analytics')
    ->group(function (): void {
        Route::get('/overview', [AdminAnalyticsController::class, 'overview']);
        Route::get('/filters', [AdminAnalyticsController::class, 'filters']);
        Route::get('/students', [AdminAnalyticsController::class, 'students']);
        Route::get('/students/{studentId}', [AdminAnalyticsController::class, 'student']);
        Route::get('/questions', [AdminAnalyticsController::class, 'questions']);
        Route::get('/topics', [AdminAnalyticsController::class, 'topics']);
        Route::get('/quizzes', [AdminAnalyticsController::class, 'quizzes']);
        Route::get('/activity', [AdminAnalyticsController::class, 'activity']);
    });

Route::middleware(['auth.compat', 'permission.compat:admin'])->prefix('school')->group(function (): void {
    Route::get('/list', [SchoolsController::class, 'index']);
    Route::post('', [SchoolsController::class, 'store']);
});

Route::middleware(['auth.compat'])->prefix('subjects')->group(function (): void {
    Route::get('/list', [SubjectsController::class, 'index'])
        ->middleware('permission.compat:admin|folder_manager|teacher_assistant|user_manager');
    Route::post('', [SubjectsController::class, 'store'])
        ->middleware('permission.compat:admin|folder_manager');
    Route::put('/{id}', [SubjectsController::class, 'update'])
        ->middleware('permission.compat:admin|folder_manager');
    Route::post('/{id}/map-class', [SubjectsController::class, 'mapToClass'])
        ->middleware('permission.compat:admin|folder_manager');
    Route::post('/{id}/assign-teacher', [SubjectsController::class, 'assignTeacher'])
        ->middleware('permission.compat:admin|folder_manager');
});

Route::middleware(['auth.compat'])->prefix('students')->group(function (): void {
    Route::get('/list', [StudentsController::class, 'index'])
        ->middleware('permission.compat:admin|user_manager|teacher_assistant|password_manager|accountant|accountant_fees|accountant_payments|accountant_reports');
    Route::get('/{id}', [StudentsController::class, 'show'])
        ->middleware('permission.compat:admin|user_manager|accountant|accountant_fees|accountant_payments|accountant_reports');
    Route::post('', [StudentsController::class, 'store'])
        ->middleware('permission.compat:admin|user_manager');
    Route::put('/{id}', [StudentsController::class, 'update'])
        ->middleware('permission.compat:admin|user_manager');
    Route::post('/{id}/assign', [StudentsController::class, 'assign'])
        ->middleware('permission.compat:admin|user_manager');
    Route::delete('/{id}/permanent', [StudentsController::class, 'permanentlyDestroy'])
        ->middleware('permission.compat:admin');
    Route::delete('/{id}', [StudentsController::class, 'destroy'])
        ->middleware('permission.compat:admin|user_manager');
});

Route::middleware(['auth.compat'])->prefix('student-queries')->group(function (): void {
    Route::post('', [StudentQueriesController::class, 'store']);
    Route::get('', [StudentQueriesController::class, 'index']);
    Route::get('/{id}', [StudentQueriesController::class, 'show']);
    Route::post('/{id}/reply', [StudentQueriesController::class, 'reply'])
        ->middleware('permission.compat:admin|student_query');
});

Route::get('/leaderboard', [LeaderboardController::class, 'index'])
    ->middleware('auth.compat');

Route::middleware(['auth.compat'])->prefix('folders')->group(function (): void {
    Route::get('/authorized', [FoldersController::class, 'authorized']);
    Route::get('/permissions', [FoldersController::class, 'permissionsForUser'])
        ->middleware('permission.compat:admin|user_manager|password_manager');
    Route::delete('/permissions/{permissionId}', [FoldersController::class, 'revokePermission'])
        ->middleware('permission.compat:admin|folder_manager|user_manager');

    Route::get('', [FoldersController::class, 'index'])
        ->middleware('permission.compat:admin|folder_manager|user_manager');
    Route::post('', [FoldersController::class, 'store'])
        ->middleware('permission.compat:admin|folder_manager');
    Route::put('/{id}', [FoldersController::class, 'update'])
        ->middleware('permission.compat:admin|folder_manager|accountant_fees');
    Route::delete('/{id}', [FoldersController::class, 'destroy'])
        ->middleware('permission.compat:admin');
    Route::post('/{id}/permissions', [FoldersController::class, 'assignPermission'])
        ->middleware('permission.compat:admin|folder_manager|user_manager');
    Route::delete('/{id}/permissions/{permissionId}', [FoldersController::class, 'revokePermission'])
        ->middleware('permission.compat:admin|folder_manager|user_manager');
});

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    Route::post('/signout', [AuthController::class, 'signOut'])->middleware('auth.compat');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth.compat');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth.compat');
});

Route::get('/storage/file', [StorageController::class, 'download'])
    ->middleware('auth.compat')
    ->name('storage.private-file');

Route::middleware(['auth.compat'])->prefix('team-members')->group(function (): void {
    Route::get('', [TeamMembersController::class, 'index'])
        ->middleware('permission.compat:admin');
    Route::post('', [TeamMembersController::class, 'store'])
        ->middleware('permission.compat:admin');
    Route::put('/{userId}/permissions', [TeamMembersController::class, 'updatePermissions'])
        ->middleware('permission.compat:admin');
    Route::put('/{userId}/password', [TeamMembersController::class, 'changePassword'])
        ->middleware('permission.compat:admin|password_manager');
});

Route::middleware(['auth.compat'])->prefix('users')->group(function (): void {
    Route::get('/search', [UsersController::class, 'search'])
        ->middleware('permission.compat:admin|password_manager|user_manager');
    Route::get('', [UsersController::class, 'index'])
        ->middleware('permission.compat:admin|user_manager|password_manager');
    Route::post('', [UsersController::class, 'store'])
        ->middleware('permission.compat:admin|user_manager');
});

Route::middleware(['auth.compat'])->prefix('ai-notebook')->group(function (): void {
    Route::get('/folders', [AiNotebookController::class, 'folders']);
    Route::get('/folders/{folderId}/sources', [AiNotebookController::class, 'folderSources']);
    Route::post('/sources', [AiNotebookController::class, 'storeSource'])
        ->middleware('permission.compat:admin|folder_manager');
    Route::delete('/sources/{id}', [AiNotebookController::class, 'deleteSource'])
        ->middleware('permission.compat:admin|folder_manager');
    Route::get('/sessions', [AiNotebookController::class, 'sessions']);
    Route::post('/chat', [AiNotebookController::class, 'chat']);
    Route::get('/sessions/{id}', [AiNotebookController::class, 'session']);
    Route::delete('/sessions/{id}', [AiNotebookController::class, 'deleteSession']);
    Route::get('/folders/{folderId}/prompt', [AiNotebookController::class, 'prompt']);
    Route::put('/folders/{folderId}/prompt/default', [AiNotebookController::class, 'savePrompt'])
        ->middleware('permission.compat:admin|folder_manager');
});

Route::middleware(['auth.compat'])->prefix('tests')->group(function (): void {
    Route::get('/me/attempts', [TestsController::class, 'myAttempts']);
    Route::get('/{id}/me', [TestsController::class, 'showForStudent']);
    Route::post('/{id}/me/request-access', [TestsController::class, 'requestAccess']);
    Route::post('/{id}/me/submit', [TestsController::class, 'submit']);
    Route::post('/{id}/me/attempts/{attemptId}/resubmit', [TestsController::class, 'resubmit']);
    Route::get('/access-requests', [TestsController::class, 'accessRequests'])
        ->middleware('permission.compat:admin|testing_manager');
    Route::put('/{testId}/deadline/{studentId}', [TestsController::class, 'grantDeadline'])
        ->middleware('permission.compat:admin|testing_manager');
    Route::get('/ai-grading-settings', [TestsController::class, 'gradingSettings'])
        ->middleware('permission.compat:admin|testing_manager');
    Route::put('/ai-grading-settings', [TestsController::class, 'saveGradingSettings'])
        ->middleware('permission.compat:admin|testing_manager');
});

Route::middleware(['auth.compat', 'permission.compat:admin|teacher_assistant'])
    ->prefix('tests')
    ->group(function (): void {
        Route::get('', [TestsController::class, 'index']);
        Route::post('/questions/preview', [TestsController::class, 'previewQuestions']);
        Route::post('', [TestsController::class, 'store']);
    });
