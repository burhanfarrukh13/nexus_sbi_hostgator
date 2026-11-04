<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_attempts', function (Blueprint $table): void {
            $table->timestamp('deadline_override_at')->nullable();
            $table->timestamp('access_requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('result_available_at')->nullable();
            $table->string('submission_mode', 20)->nullable();
            $table->json('submitted_answers')->nullable();
            $table->json('ai_assessment')->nullable();
            $table->text('deficiencyNotes')->nullable();
            $table->text('grading_error')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('review_submitted_at')->nullable();
            $table->text('review_resolution')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->index(['studentId', 'status', 'access_requested_at'], 'idx_test_attempts_student_status_request');
        });

        Schema::create('test_attempt_files', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('attempt_id', 36);
            $table->unsignedInteger('question_number')->nullable();
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_key', 255)->nullable();
            $table->longText('file_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('attempt_id')->references('id')->on('test_attempts')->cascadeOnDelete();
            $table->index(['attempt_id', 'question_number'], 'idx_test_attempt_files_attempt_question');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_attempt_files');
        Schema::table('test_attempts', function (Blueprint $table): void {
            $table->dropIndex('idx_test_attempts_student_status_request');
            $table->dropColumn([
                'deadline_override_at',
                'access_requested_at',
                'submitted_at',
                'result_available_at',
                'submission_mode',
                'submitted_answers',
                'ai_assessment',
                'deficiencyNotes',
                'grading_error',
                'review_note',
                'review_submitted_at',
                'review_resolution',
                'review_resolved_at',
            ]);
        });
    }
};
