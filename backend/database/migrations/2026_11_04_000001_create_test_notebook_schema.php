<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('email', 255)->unique('uq_users_email');
            $table->string('password');
            $table->enum('role', ['admin', 'student'])->default('student');
            $table->string('studentId', 255)->nullable()->unique('uq_users_student_id');
            $table->char('refreshTokenHash', 64)->nullable();
            $table->char('previousRefreshTokenHash', 64)->nullable();
            $table->unsignedBigInteger('refreshCounter')->default(0);
            $table->string('fullName', 200)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->boolean('must_reset_password')->default(false);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->index(['role', 'email'], 'idx_users_role_email');
        });

        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->char('userId', 36);
            $table->char('granteeId', 36);
            $table->string('permission', 64);
            $table->timestamp('grantedAt')->useCurrent();
            $table->primary(['userId', 'granteeId', 'permission']);
            $table->foreign('userId', 'fk_user_role_assignments_user')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granteeId', 'fk_user_role_assignments_grantee')->references('id')->on('users')->cascadeOnDelete();
            $table->index('granteeId', 'idx_user_role_assignments_grantee');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('event', 100);
            $table->text('details')->nullable();
            $table->char('userId', 36)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->foreign('userId', 'fk_audit_logs_user')->references('id')->on('users')->nullOnDelete();
            $table->index(['event', 'createdAt'], 'idx_audit_logs_event_created');
        });

        Schema::create('student_queries', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('userId', 36);
            $table->string('title', 200);
            $table->text('message');
            $table->text('response')->nullable();
            $table->char('respondedById', 36)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('userId', 'fk_student_queries_user')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('respondedById', 'fk_student_queries_responded_by')->references('id')->on('users')->nullOnDelete();
            $table->index('userId', 'idx_student_queries_user');
            $table->index('status', 'idx_student_queries_status');
            $table->index(['createdAt', 'id'], 'idx_student_queries_created_at');
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('code', 32)->unique('uq_subjects_code');
            $table->string('name', 128);
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('created_by_user_id', 'fk_subjects_creator')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('subject_user_access', function (Blueprint $table): void {
            $table->char('subject_id', 36);
            $table->char('user_id', 36);
            $table->char('granted_by_user_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['subject_id', 'user_id']);
            $table->foreign('subject_id', 'fk_subject_user_access_subject')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('user_id', 'fk_subject_user_access_user')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by_user_id', 'fk_subject_user_access_granted_by')->references('id')->on('users')->nullOnDelete();
            $table->index('user_id', 'idx_subject_user_access_user');
        });

        Schema::create('tests', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('testDate')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->decimal('totalMarks', 8, 2)->default(0);
            $table->json('questions')->nullable();
            $table->char('createdById', 36)->nullable();
            $table->char('subject_id', 36);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('createdById', 'fk_tests_created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('subject_id', 'fk_tests_subject')->references('id')->on('subjects')->restrictOnDelete();
            $table->index(['subject_id', 'createdAt'], 'idx_tests_subject_created');
            $table->index('createdAt', 'idx_tests_created');
        });

        Schema::create('test_attempts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('testId', 36);
            $table->char('studentId', 36);
            $table->boolean('appeared')->default(false);
            $table->decimal('marksObtained', 8, 2)->nullable();
            $table->string('status', 20)->default('NOT_ATTEMPTED');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('deadline_override_at')->nullable();
            $table->timestamp('access_requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('result_available_at')->nullable();
            $table->string('submission_mode', 20)->nullable();
            $table->json('submitted_answers')->nullable();
            $table->json('draft_answers')->nullable();
            $table->char('client_submission_id', 36)->nullable();
            $table->json('ai_assessment')->nullable();
            $table->text('deficiencyNotes')->nullable();
            $table->text('grading_error')->nullable();
            $table->unsignedTinyInteger('grading_attempts')->default(0);
            $table->text('review_note')->nullable();
            $table->timestamp('review_submitted_at')->nullable();
            $table->text('review_resolution')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->unique(['testId', 'studentId'], 'uq_test_attempts_test_student');
            $table->index(['studentId', 'status', 'access_requested_at'], 'idx_test_attempts_student_status_request');
            $table->index(['status', 'grading_attempts', 'submitted_at'], 'idx_test_attempts_grading_queue');
            $table->foreign('testId', 'fk_test_attempts_test')->references('id')->on('tests')->cascadeOnDelete();
            $table->foreign('studentId', 'fk_test_attempts_student')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('test_source_files', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('test_id', 36);
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_key', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('test_id', 'fk_test_source_files_test')->references('id')->on('tests')->cascadeOnDelete();
            $table->index('test_id', 'idx_test_source_files_test');
        });

        Schema::create('test_attempt_files', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('attempt_id', 36);
            $table->unsignedInteger('question_number')->nullable();
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_key', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('attempt_id', 'fk_test_attempt_files_attempt')->references('id')->on('test_attempts')->cascadeOnDelete();
            $table->index(['attempt_id', 'question_number'], 'idx_test_attempt_files_attempt_question');
        });

        Schema::create('ai_notebook_sources', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('subject_id', 36);
            $table->string('title', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 128);
            $table->string('storage_key', 255);
            $table->string('status', 16)->default('processing');
            $table->text('error_message')->nullable();
            $table->char('uploaded_by_user_id', 36)->nullable();
            $table->unsignedInteger('total_chunks')->default(0);
            $table->unsignedInteger('completed_chunks')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('subject_id', 'fk_ai_notebook_sources_subject')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'fk_ai_notebook_sources_uploader')->references('id')->on('users')->nullOnDelete();
            $table->index(['subject_id', 'created_at'], 'idx_ai_notebook_sources_subject_created');
        });

        Schema::create('ai_notebook_chunks', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('source_id', 36);
            $table->unsignedInteger('ordinal');
            $table->longText('content');
            $table->json('embedding');
            $table->string('embedding_model', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('source_id', 'fk_ai_notebook_chunks_source')->references('id')->on('ai_notebook_sources')->cascadeOnDelete();
            $table->unique(['source_id', 'ordinal'], 'uq_ai_notebook_chunks_source_ordinal');
            $table->index(['source_id', 'embedding_model'], 'idx_ai_notebook_chunks_source_model');
        });

        Schema::create('ai_notebook_chat_sessions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('subject_id', 36);
            $table->char('user_id', 36);
            $table->string('title', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('subject_id', 'fk_ai_notebook_sessions_subject')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('user_id', 'fk_ai_notebook_sessions_user')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'subject_id', 'updated_at'], 'idx_ai_notebook_sessions_user_subject_updated');
        });

        Schema::create('ai_notebook_chat_messages', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('session_id', 36);
            $table->string('role', 16);
            $table->longText('content');
            $table->json('citations');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('session_id', 'fk_ai_notebook_messages_session')->references('id')->on('ai_notebook_chat_sessions')->cascadeOnDelete();
            $table->index(['session_id', 'created_at'], 'idx_ai_notebook_messages_session_created');
        });

        Schema::create('ai_notebook_prompts', function (Blueprint $table): void {
            $table->char('subject_id', 36)->primary();
            $table->text('prompt');
            $table->string('response_language', 32)->default('english');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('subject_id', 'fk_ai_notebook_prompts_subject')->references('id')->on('subjects')->cascadeOnDelete();
        });

        Schema::create('ai_notebook_user_prompts', function (Blueprint $table): void {
            $table->char('subject_id', 36);
            $table->char('user_id', 36);
            $table->text('prompt');
            $table->string('response_language', 32)->default('english');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->primary(['subject_id', 'user_id']);
            $table->foreign('subject_id', 'fk_ai_notebook_user_prompts_subject')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('user_id', 'fk_ai_notebook_user_prompts_user')->references('id')->on('users')->cascadeOnDelete();
        });

        // One row per user. usageDate is the day the counter applies to, and
        // AiNotebookController::reserveUsage() resets requestCount when the date rolls over,
        // so the primary key is intentionally userId alone (not userId + usageDate).
        Schema::create('ai_notebook_daily_usage', function (Blueprint $table): void {
            $table->char('userId', 36)->primary();
            $table->date('usageDate');
            $table->unsignedInteger('requestCount')->default(0);
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('userId', 'fk_ai_notebook_daily_usage_user')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ai_grading_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('deficiency_report_prompt');
            $table->unsignedSmallInteger('result_release_delay_hours')->default(0);
            $table->unsignedSmallInteger('notebook_daily_question_limit')->default(15);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        DB::table('ai_grading_settings')->insert([
            'id' => 1,
            'deficiency_report_prompt' => 'Review the student work fairly, highlight the strongest points first, then identify the main gaps with clear, actionable guidance.',
            'result_release_delay_hours' => 0,
            'notebook_daily_question_limit' => 15,
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE test_attempt_files ADD CONSTRAINT chk_test_attempt_files_size CHECK (size_bytes > 0 AND size_bytes <= 31457280)');
            DB::statement('ALTER TABLE test_attempt_files ADD CONSTRAINT chk_test_attempt_files_question_number CHECK (question_number IS NULL OR question_number > 0)');
            DB::statement("ALTER TABLE ai_notebook_sources ADD CONSTRAINT chk_ai_notebook_sources_status CHECK (status IN ('processing', 'ready', 'failed'))");
            DB::statement("ALTER TABLE ai_notebook_chat_messages ADD CONSTRAINT chk_ai_notebook_messages_role CHECK (role IN ('user', 'assistant'))");
            DB::statement('ALTER TABLE ai_notebook_daily_usage ADD CONSTRAINT chk_ai_notebook_daily_usage_count CHECK (requestCount BETWEEN 0 AND 1000)');
            DB::statement('ALTER TABLE ai_grading_settings ADD CONSTRAINT chk_ai_grading_settings_singleton CHECK (id = 1)');
        }
    }

    public function down(): void
    {
        foreach ([
            'ai_grading_settings',
            'ai_notebook_daily_usage',
            'ai_notebook_user_prompts',
            'ai_notebook_prompts',
            'ai_notebook_chat_messages',
            'ai_notebook_chat_sessions',
            'ai_notebook_chunks',
            'ai_notebook_sources',
            'test_attempt_files',
            'test_source_files',
            'test_attempts',
            'tests',
            'student_queries',
            'subject_user_access',
            'subjects',
            'audit_logs',
            'user_role_assignments',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
