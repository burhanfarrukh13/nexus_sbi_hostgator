<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('code', 128);
            $table->string('title', 255);
            $table->date('target_exam_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('folder_id', 36)->nullable()->unique();
            $table->char('class_id', 36)->nullable();
            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->nullOnDelete();
        });

        Schema::create('grids', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('course_id', 36);
            $table->string('grid_code', 64);
            $table->string('title', 255);
            $table->unsignedInteger('total_past_papers')->default(0);
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->index('course_id', 'idx_grids_course');
        });

        Schema::create('topics', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('grid_id', 36);
            $table->string('title', 255);
            $table->unsignedInteger('sequence_order');
            $table->char('lecture_folder_id', 36)->nullable();
            $table->foreign('grid_id')->references('id')->on('grids')->cascadeOnDelete();
            $table->index('grid_id', 'idx_topics_grid');
        });

        Schema::create('questions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('topic_id', 36);
            $table->string('tier_type', 32);
            $table->text('question_text');
            $table->json('options');
            $table->text('correct_answer')->nullable();
            $table->integer('marks')->default(1);
            $table->json('marking_scheme')->nullable();
            $table->text('explanation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('difficulty', 16)->default('MODERATE');
            $table->text('solution')->nullable();
            $table->text('feedback')->nullable();
            $table->string('question_type', 16)->default('MCQ');
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
            $table->index('topic_id', 'idx_questions_topic_id');
            $table->index(['topic_id', 'difficulty'], 'idx_questions_difficulty');
        });

        Schema::create('topic_progress', function (Blueprint $table): void {
            $table->char('user_id', 36);
            $table->char('topic_id', 36);
            $table->string('video_status', 20)->default('PENDING');
            $table->primary(['user_id', 'topic_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
        });

        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('topic_id', 36)->nullable();
            $table->char('mock_exam_id', 36)->nullable();
            $table->string('tier_type', 32);
            $table->unsignedInteger('seed')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedInteger('total_marks')->nullable();
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->string('idempotency_key', 255)->nullable()->unique();
            $table->json('question_ids')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'topic_id', 'tier_type', 'submitted_at'], 'idx_quiz_attempts_user_topic_tier_submitted');
            $table->index(['status', 'submitted_at'], 'idx_quiz_attempts_analytics_status_date');
        });

        Schema::create('mock_exams', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('course_id', 36);
            $table->string('title', 255);
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('total_marks')->default(0);
            $table->json('question_selection')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });

        Schema::create('quiz_attempt_answers', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('attempt_id', 36);
            $table->char('question_id', 36);
            $table->text('selected_option')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->integer('marks_awarded')->default(0);
            $table->foreign('attempt_id')->references('id')->on('quiz_attempts')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->unique(['attempt_id', 'question_id'], 'uq_quiz_attempt_answers_attempt_question');
            $table->index('attempt_id', 'idx_quiz_attempt_answers_attempt_id');
        });

        Schema::create('flashcards', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('topic_id', 36);
            $table->string('standard_reference', 255)->nullable();
            $table->text('content');
            $table->foreign('topic_id')->references('id')->on('topics')->cascadeOnDelete();
        });

        Schema::create('flashcard_reviews', function (Blueprint $table): void {
            $table->char('user_id', 36);
            $table->char('flashcard_id', 36);
            $table->string('status', 20);
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->unsignedInteger('interval_days')->default(0);
            $table->decimal('ease_factor', 4, 2)->default(2.50);
            $table->primary(['user_id', 'flashcard_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('flashcard_id')->references('id')->on('flashcards')->cascadeOnDelete();
            $table->index('next_review_at', 'idx_flashcard_reviews_next_review_at');
        });

        Schema::create('lecture_progress', function (Blueprint $table): void {
            $table->char('user_id', 36);
            $table->char('lecture_id', 36);
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('watched_percent', 5, 2)->default(0);
            $table->string('status', 20)->default('NOT_STARTED');
            $table->timestamp('first_started_at')->nullable();
            $table->timestamp('last_watched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['user_id', 'lecture_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('lecture_id')->references('id')->on('lectures')->cascadeOnDelete();
            $table->index(['lecture_id', 'last_watched_at'], 'idx_lecture_progress_lecture_watched');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecture_progress');
        Schema::dropIfExists('flashcard_reviews');
        Schema::dropIfExists('flashcards');
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('mock_exams');
        Schema::dropIfExists('topic_progress');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('grids');
        Schema::dropIfExists('courses');
    }
};
