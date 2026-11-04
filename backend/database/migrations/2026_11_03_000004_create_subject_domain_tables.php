<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('school_id', 36);
            $table->unsignedTinyInteger('numeric_level');
            $table->string('display_name', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['school_id', 'numeric_level']);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        Schema::create('sections', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('class_id', 36);
            $table->string('name', 16);
            $table->unsignedInteger('capacity')->default(40);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['class_id', 'name']);
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('school_id', 36);
            $table->string('code', 32);
            $table->string('name', 128);
            $table->char('class_id', 36)->nullable();
            $table->char('section_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['school_id', 'code']);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->nullOnDelete();
            $table->foreign('section_id')->references('id')->on('sections')->nullOnDelete();
            $table->index('school_id');
            $table->index('class_id');
            $table->index('section_id');
        });

        Schema::create('class_subjects', function (Blueprint $table): void {
            $table->char('class_id', 36);
            $table->char('subject_id', 36);
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['class_id', 'subject_id']);
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
        });

        Schema::create('subject_teachers', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('subject_id', 36);
            $table->char('class_id', 36);
            $table->char('section_id', 36)->nullable();
            $table->char('user_id', 36);
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('subject_id')->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('class_id')->references('id')->on('classes')->cascadeOnDelete();
            $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        Schema::create('folders', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->decimal('default_price', 12, 2)->default(0);
            $table->string('coverImageUrl', 512)->nullable();
            $table->char('subject_id', 36)->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('subject_id')->references('id')->on('subjects')->nullOnDelete();
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
        Schema::dropIfExists('subject_teachers');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('classes');
    }
};
