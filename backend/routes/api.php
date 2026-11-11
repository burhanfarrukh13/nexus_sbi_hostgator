<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AiNotebookController;
use App\Http\Controllers\GradingQueueController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StudentQueriesController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\TeamMembersController;
use App\Http\Controllers\TestsController;
use App\Http\Controllers\TopPerformersController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware(['auth.compat', 'throttle:authenticated-api'])->prefix('subjects')->group(function (): void {
    Route::get('/list', [SubjectsController::class, 'index'])->middleware('cache.private-etag');
    Route::post('', [SubjectsController::class, 'store'])
        ->middleware('permission.compat:admin|teacher_assistant');
    Route::put('/{id}', [SubjectsController::class, 'update'])
        ->middleware('permission.compat:admin|teacher_assistant');
    Route::delete('/{id}', [SubjectsController::class, 'destroy'])
        ->middleware('permission.compat:admin|teacher_assistant');
});

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');
    Route::post('/signout', [AuthController::class, 'signOut'])->middleware(['auth.compat', 'throttle:authenticated-api']);
    Route::get('/me', [AuthController::class, 'me'])->middleware(['auth.compat', 'throttle:authenticated-api']);
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware(['auth.compat', 'throttle:authenticated-api', 'throttle:10,1']);
});

Route::get('/storage/file', [StorageController::class, 'download'])
    ->middleware(['auth.compat', 'throttle:authenticated-api'])
    ->name('storage.private-file');

Route::middleware(['auth.compat', 'throttle:authenticated-api'])->prefix('team-members')->group(function (): void {
    Route::get('', [TeamMembersController::class, 'index'])
        ->middleware('permission.compat:admin');
    Route::post('', [TeamMembersController::class, 'store'])
        ->middleware('permission.compat:admin');
    Route::put('/{userId}', [TeamMembersController::class, 'update'])
        ->middleware('permission.compat:admin');
    Route::delete('/{userId}', [TeamMembersController::class, 'destroy'])
        ->middleware('permission.compat:admin');
    Route::put('/{userId}/permissions', [TeamMembersController::class, 'updatePermissions'])
        ->middleware('permission.compat:admin');
    Route::put('/{userId}/password', [TeamMembersController::class, 'changePassword'])
        ->middleware('permission.compat:admin|password_manager');
});

Route::middleware(['auth.compat', 'throttle:authenticated-api'])->prefix('student-queries')->group(function (): void {
    Route::get('', [StudentQueriesController::class, 'index']);
    Route::post('', [StudentQueriesController::class, 'store']);
    Route::post('/{id}/reply', [StudentQueriesController::class, 'reply']);
});

Route::get('/leaderboard', TopPerformersController::class)->middleware(['auth.compat', 'throttle:authenticated-api']);

Route::middleware(['auth.compat', 'throttle:authenticated-api', 'permission.compat:admin|testing_manager'])
    ->prefix('admin/analytics')
    ->group(function (): void {
        Route::get('/overview', [AnalyticsController::class, 'overview']);
    });

Route::middleware(['auth.compat', 'throttle:authenticated-api'])->prefix('users')->group(function (): void {
    Route::get('/search', [UsersController::class, 'search'])
        ->middleware('permission.compat:admin|password_manager|user_manager');
    Route::get('', [UsersController::class, 'index'])
        ->middleware('permission.compat:admin|user_manager|password_manager');
    Route::post('', [UsersController::class, 'store'])
        ->middleware('permission.compat:admin|user_manager');
    Route::put('/{userId}/subjects', [UsersController::class, 'updateSubjects'])
        ->middleware('permission.compat:admin|user_manager');
});

Route::middleware(['auth.compat', 'throttle:authenticated-api'])->prefix('ai-notebook')->group(function (): void {
    Route::get('/subjects', [AiNotebookController::class, 'subjects'])->middleware('cache.private-etag');
    Route::get('/subjects/{subjectId}/sources', [AiNotebookController::class, 'subjectSources'])->middleware('cache.private-etag');
    Route::get('/sources/{sourceId}/chunks', [AiNotebookController::class, 'sourceChunks'])
        ->middleware('permission.compat:admin|teacher_assistant');
    Route::post('/sources', [AiNotebookController::class, 'storeSource'])
        ->middleware('permission.compat:admin|teacher_assistant');
    Route::delete('/sources/{id}', [AiNotebookController::class, 'deleteSource'])
        ->middleware('permission.compat:admin|teacher_assistant');
    Route::get('/sessions', [AiNotebookController::class, 'sessions']);
    Route::post('/context', [AiNotebookController::class, 'context']);
    Route::post('/record-message', [AiNotebookController::class, 'recordMessage']);
    Route::post('/chat', [AiNotebookController::class, 'chat'])->middleware('throttle:notebook-chat');
    Route::get('/sessions/{id}', [AiNotebookController::class, 'session']);
    Route::delete('/sessions/{id}', [AiNotebookController::class, 'deleteSession']);
    Route::get('/subjects/{subjectId}/prompt', [AiNotebookController::class, 'prompt']);
    Route::put('/subjects/{subjectId}/prompt/default', [AiNotebookController::class, 'savePrompt'])
        ->middleware('permission.compat:admin|teacher_assistant');
    Route::put('/subjects/{subjectId}/prompt/personal', [AiNotebookController::class, 'savePersonalPrompt']);
    Route::delete('/subjects/{subjectId}/prompt/personal', [AiNotebookController::class, 'resetPersonalPrompt']);
});

Route::middleware(['auth.compat', 'throttle:authenticated-api'])->prefix('tests')->group(function (): void {
    Route::get('/me/attempts', [TestsController::class, 'myAttempts'])->middleware('cache.private-etag');
    Route::get('/{id}/me/status', [TestsController::class, 'statusForStudent'])->middleware('throttle:test-status');
    Route::get('/{id}/me', [TestsController::class, 'showForStudent'])->middleware('throttle:test-status');
    Route::put('/{id}/me/draft', [TestsController::class, 'saveDraft']);
    Route::post('/{id}/me/request-access', [TestsController::class, 'requestAccess']);
    Route::post('/{id}/me/submit', [TestsController::class, 'submit']);
    Route::post('/{id}/me/attempts/{attemptId}/resubmit', [TestsController::class, 'resubmit']);
    Route::post('/{id}/me/review', [TestsController::class, 'submitReview']);
    Route::get('/{id}/me/attempt-files/{fileId}/pages', [TestsController::class, 'studentAttemptFilePageInfo']);
    Route::get('/{id}/me/attempt-files/{fileId}/pages/{pageNumber}', [TestsController::class, 'studentAttemptFilePage']);
    Route::get('/{id}/me/source-files/{fileId}/pages', [TestsController::class, 'studentSourceFilePageInfo']);
    Route::get('/{id}/me/source-files/{fileId}/pages/{pageNumber}', [TestsController::class, 'studentSourceFilePage']);
    Route::get('/{testId}/attempts', [TestsController::class, 'attempts'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::post('/{testId}/attempts/{attemptId}/retry', [TestsController::class, 'retryAssessment'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/{testId}/attempts/files/{fileId}', [TestsController::class, 'downloadAttemptFile'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/{testId}/attempts/files/{fileId}/pages', [TestsController::class, 'attemptFilePageInfo'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/{testId}/attempts/files/{fileId}/pages/{pageNumber}', [TestsController::class, 'attemptFilePage'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/{testId}/source-files/{fileId}', [TestsController::class, 'downloadSourceFile'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/{testId}/source-files/{fileId}/pages', [TestsController::class, 'sourceFilePageInfo'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/{testId}/source-files/{fileId}/pages/{pageNumber}', [TestsController::class, 'sourceFilePage'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::put('/{testId}/marks/{studentId}', [TestsController::class, 'updateMarks'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/access-requests', [TestsController::class, 'accessRequests'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::put('/{testId}/deadline/{studentId}', [TestsController::class, 'grantDeadline'])
        ->middleware('permission.compat:admin|teacher_assistant|testing_manager');
    Route::get('/grading-queue', [GradingQueueController::class, 'index'])
        ->middleware('permission.compat:admin');
    Route::post('/grading-queue/retry', [GradingQueueController::class, 'retryAll'])
        ->middleware('permission.compat:admin');
    Route::get('/ai-grading-settings', [TestsController::class, 'gradingSettings'])
        ->middleware('permission.compat:admin');
    Route::put('/ai-grading-settings', [TestsController::class, 'saveGradingSettings'])
        ->middleware('permission.compat:admin');
});

Route::middleware(['auth.compat', 'throttle:authenticated-api', 'permission.compat:admin|teacher_assistant'])
    ->prefix('tests')
    ->group(function (): void {
        Route::get('', [TestsController::class, 'index']);
        Route::post('/questions/preview', [TestsController::class, 'previewQuestions']);
        Route::post('', [TestsController::class, 'store']);
        Route::put('/{testId}', [TestsController::class, 'updateTitle']);
        Route::delete('/{testId}', [TestsController::class, 'destroy']);
        Route::post('/{testId}/source-files', [TestsController::class, 'storeSourceFiles']);
    });
