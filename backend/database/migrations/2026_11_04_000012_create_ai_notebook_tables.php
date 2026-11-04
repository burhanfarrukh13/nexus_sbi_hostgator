<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_notebook_sources', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('folder_id', 36);
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
            $table->foreign('folder_id')->references('id')->on('folders')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['folder_id', 'created_at'], 'idx_ai_notebook_sources_folder_created');
        });

        Schema::create('ai_notebook_chunks', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('source_id', 36);
            $table->unsignedInteger('ordinal');
            $table->longText('content');
            $table->json('embedding');
            $table->string('embedding_model', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('source_id')->references('id')->on('ai_notebook_sources')->cascadeOnDelete();
            $table->unique(['source_id', 'ordinal'], 'uq_ai_notebook_chunks_source_ordinal');
            $table->index(['source_id', 'embedding_model'], 'idx_ai_notebook_chunks_source_model');
        });

        Schema::create('ai_notebook_chat_sessions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('folder_id', 36);
            $table->char('user_id', 36);
            $table->string('title', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->foreign('folder_id')->references('id')->on('folders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'folder_id', 'updated_at'], 'idx_ai_notebook_sessions_user_folder_updated');
        });

        Schema::create('ai_notebook_chat_messages', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('session_id', 36);
            $table->string('role', 16);
            $table->longText('content');
            $table->json('citations');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('session_id')->references('id')->on('ai_notebook_chat_sessions')->cascadeOnDelete();
            $table->index(['session_id', 'created_at'], 'idx_ai_notebook_messages_session_created');
        });

        Schema::create('ai_notebook_prompts', function (Blueprint $table): void {
            $table->char('folder_id', 36)->primary();
            $table->text('prompt');
            $table->string('response_language', 32)->default('english');
            $table->timestamp('updated_at')->useCurrent();
            $table->foreign('folder_id')->references('id')->on('folders')->cascadeOnDelete();
        });

        Schema::create('ai_notebook_user_prompts', function (Blueprint $table): void {
            $table->char('folder_id', 36);
            $table->char('user_id', 36);
            $table->text('prompt');
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['folder_id', 'user_id']);
            $table->foreign('folder_id')->references('id')->on('folders')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ai_notebook_daily_usage', function (Blueprint $table): void {
            $table->char('userId', 36)->primary();
            $table->date('usageDate');
            $table->unsignedInteger('requestCount')->default(0);
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ai_grading_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('deficiency_report_prompt');
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::table('ai_grading_settings')->insert([
            'id' => 1,
            'deficiency_report_prompt' => 'Review the student work fairly, highlight the strongest points first, then identify the main gaps with clear, actionable guidance.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_grading_settings');
        Schema::dropIfExists('ai_notebook_daily_usage');
        Schema::dropIfExists('ai_notebook_user_prompts');
        Schema::dropIfExists('ai_notebook_prompts');
        Schema::dropIfExists('ai_notebook_chat_messages');
        Schema::dropIfExists('ai_notebook_chat_sessions');
        Schema::dropIfExists('ai_notebook_chunks');
        Schema::dropIfExists('ai_notebook_sources');
    }
};
