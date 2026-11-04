<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tests', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->date('testDate')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->json('questions')->nullable();
            $table->char('createdById', 36)->nullable();
            $table->char('classId', 36)->nullable();
            $table->char('sectionId', 36)->nullable();
            $table->json('contentCovered')->nullable();
            $table->foreign('createdById')->references('id')->on('users')->nullOnDelete();
            $table->foreign('classId')->references('id')->on('classes')->nullOnDelete();
            $table->foreign('sectionId')->references('id')->on('sections')->nullOnDelete();
            $table->index('sectionId', 'idx_tests_section');
            $table->index('createdAt', 'idx_tests_created_at');
        });

        Schema::create('test_folders', function (Blueprint $table): void {
            $table->char('testId', 36);
            $table->char('folderId', 36);
            $table->primary(['testId', 'folderId']);
            $table->foreign('testId')->references('id')->on('tests')->cascadeOnDelete();
            $table->foreign('folderId')->references('id')->on('folders')->cascadeOnDelete();
        });

        Schema::create('test_source_files', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('test_id', 36);
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->string('storage_key', 255)->nullable();
            $table->longText('file_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('test_id')->references('id')->on('tests')->cascadeOnDelete();
            $table->index('test_id', 'idx_test_source_files_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_source_files');
        Schema::dropIfExists('test_folders');
        Schema::table('tests', function (Blueprint $table): void {
            $table->dropForeign(['createdById']);
            $table->dropForeign(['classId']);
            $table->dropForeign(['sectionId']);
            $table->dropIndex('idx_tests_section');
            $table->dropIndex('idx_tests_created_at');
            $table->dropColumn([
                'description',
                'testDate',
                'deadline_at',
                'questions',
                'createdById',
                'classId',
                'sectionId',
                'contentCovered',
            ]);
        });
    }
};
