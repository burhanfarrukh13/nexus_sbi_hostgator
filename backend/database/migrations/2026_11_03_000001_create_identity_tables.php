<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('email', 255)->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'student'])->default('student');
            $table->string('studentId', 255)->nullable()->unique();
            $table->char('refreshTokenHash', 64)->nullable();
            $table->char('previousRefreshTokenHash', 64)->nullable();
            $table->unsignedBigInteger('refreshCounter')->default(0);
            $table->string('fullName', 200)->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->boolean('must_reset_password')->default(false);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->index(['role', 'email']);
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('userId', 36);
            $table->string('deviceFingerprint', 128)->nullable();
            $table->string('name', 255)->nullable();
            $table->text('publicKey');
            $table->boolean('isActive')->default(true);
            $table->char('activeUserId', 36)->nullable();
            $table->timestamp('lastHeartbeatAt')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['userId', 'isActive']);
        });

        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->char('userId', 36);
            $table->char('granteeId', 36);
            $table->enum('permission', [
                'admin',
                'teacher_assistant',
                'folder_manager',
                'user_manager',
                'password_manager',
                'accountant',
                'accountant_fees',
                'accountant_payments',
                'accountant_expenses',
                'accountant_reports',
                'student_query',
                'testing_manager',
            ]);
            $table->timestamp('grantedAt')->useCurrent();
            $table->primary(['userId', 'granteeId', 'permission']);
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granteeId')->references('id')->on('users')->cascadeOnDelete();
            $table->index('userId');
            $table->index('granteeId');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('users');
    }
};
