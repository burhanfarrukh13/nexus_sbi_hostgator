<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_queries', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('userId', 36);
            $table->string('title', 200);
            $table->text('message');
            $table->text('response')->nullable();
            $table->char('respondedById', 36)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('respondedById')->references('id')->on('users')->nullOnDelete();
            $table->index('userId', 'idx_student_queries_user');
            $table->index('status', 'idx_student_queries_status');
            $table->index(['createdAt', 'id'], 'idx_student_queries_created_at');
        });

        Schema::create('tests', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('title', 200);
            $table->decimal('totalMarks', 8, 2)->default(0);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
        });

        Schema::create('test_attempts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('testId', 36);
            $table->char('studentId', 36);
            $table->boolean('appeared')->default(false);
            $table->decimal('marksObtained', 8, 2)->nullable();
            $table->string('status', 20)->default('NOT_ATTEMPTED');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->unique(['testId', 'studentId'], 'uq_test_attempts_test_student');
            $table->index('studentId', 'idx_test_attempts_student');
            $table->index(['appeared', 'status', 'studentId', 'testId', 'marksObtained'], 'idx_attempts_leaderboard');
            $table->foreign('testId')->references('id')->on('tests')->cascadeOnDelete();
            $table->foreign('studentId')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_attempts');
        Schema::dropIfExists('tests');
        Schema::dropIfExists('student_queries');
    }
};
