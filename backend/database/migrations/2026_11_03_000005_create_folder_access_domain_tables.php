<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lectures', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('duration', 100)->nullable();
            $table->string('contentGroup', 255)->default('');
            $table->integer('orderIndex')->nullable();
            $table->date('contentDate')->nullable();
            $table->char('folderId', 36);
            $table->char('contentKeyId', 36)->nullable()->unique();
            $table->char('sourceLectureId', 36)->nullable();
            $table->timestamp('deletedAt')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('folderId')->references('id')->on('folders')->cascadeOnDelete();
            $table->foreign('sourceLectureId')->references('id')->on('lectures')->nullOnDelete();
            $table->index(['folderId', 'deletedAt']);
        });

        Schema::create('folder_permissions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('userId', 36);
            $table->char('folderId', 36);
            $table->boolean('canView')->default(true);
            $table->boolean('billing_suspended')->default(false);
            $table->timestamp('createdAt')->useCurrent();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('folderId')->references('id')->on('folders')->cascadeOnDelete();
            $table->unique(['userId', 'folderId'], 'uq_folder_permissions_user_folder');
        });

        Schema::create('folder_access_plans', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('folder_id', 36);
            $table->foreign('folder_id')->references('id')->on('folders')->restrictOnDelete();
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_access_plans');
        Schema::dropIfExists('folder_permissions');
        Schema::dropIfExists('lectures');
    }
};
