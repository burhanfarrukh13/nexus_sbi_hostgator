<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_notebook_chat_tickets', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('client_request_id', 36);
            $table->char('subject_id', 36);
            $table->char('session_id', 36)->nullable();
            $table->longText('question');
            $table->string('study_aid_mode', 20)->nullable();
            $table->string('image_storage_key', 240)->nullable();
            $table->date('usage_date')->nullable();
            $table->boolean('usage_reserved')->default(false);
            $table->string('status', 20)->default('pending');
            $table->longText('response_json')->nullable();
            $table->timestamp('queued_at', 6)->useCurrent();
            $table->timestamp('last_checked_at')->useCurrent();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('user_id', 'fk_ai_notebook_chat_tickets_user')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subject_id', 'fk_ai_notebook_chat_tickets_subject')->references('id')->on('subjects')->cascadeOnDelete();
            $table->unique(['user_id', 'client_request_id'], 'uq_ai_notebook_chat_ticket_owner_request');
            $table->index(['status', 'queued_at', 'id'], 'idx_ai_notebook_chat_tickets_fifo');
            $table->index(['status', 'last_checked_at'], 'idx_ai_notebook_chat_tickets_expiry');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ai_notebook_chat_tickets ADD CONSTRAINT chk_ai_notebook_chat_tickets_status CHECK (status IN ('pending', 'processing', 'completed', 'expired'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_notebook_chat_tickets');
    }
};
