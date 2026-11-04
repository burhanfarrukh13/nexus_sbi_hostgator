<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_years', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('school_id', 36);
            $table->string('label', 32);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['school_id', 'label']);
            $table->index('school_id', 'idx_school_years_school');
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX uq_one_current_school_year_per_school ON school_years(school_id) WHERE is_current = 1'
            );
        } elseif (DB::getDriverName() === 'mysql') {
            Schema::table('school_years', function (Blueprint $table): void {
                $table->char('current_school_id', 36)->nullable();
            });
        }

        Schema::create('students', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('enrollment_no', 32)
                ->collation(DB::getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'BINARY')
                ->unique();
            $table->string('full_name', 255);
            $table->string('guardian_name', 255)->nullable();
            $table->string('guardian_phone', 32)->nullable();
            $table->string('phone', 32)->nullable();
            $table->date('dob')->nullable();
            $table->text('address')->nullable();
            $table->date('joined_at');
            $table->enum('status', ['active', 'left', 'graduated', 'suspended'])->default('active');
            $table->char('user_id', 36)->nullable();
            $table->char('current_class_id', 36)->nullable();
            $table->char('current_section_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique('user_id', 'uq_students_user_id');
            $table->index(['current_class_id', 'current_section_id'], 'idx_students_class_section');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('current_class_id')->references('id')->on('classes')->nullOnDelete();
            $table->foreign('current_section_id')->references('id')->on('sections')->nullOnDelete();
        });

        Schema::create('student_cohort_memberships', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('student_id', 36);
            $table->char('batch_id', 36)->nullable();
            $table->char('school_year_id', 36)->nullable();
            $table->char('class_id', 36)->nullable();
            $table->char('section_id', 36)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->index(['student_id', 'starts_on', 'ends_on'], 'idx_student_cohort_student_dates');
            $table->index(['batch_id', 'school_year_id'], 'idx_student_cohort_batch_year');
        });

        Schema::create('licenses', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('userId', 36);
            $table->char('deviceId', 36);
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active');
            $table->timestamp('expiresAt')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();
            $table->index(['userId', 'status'], 'idx_licenses_user_status');
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('deviceId')->references('id')->on('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('student_cohort_memberships');
        Schema::dropIfExists('students');
        if (DB::getDriverName() === 'mysql') {
            Schema::table('school_years', function (Blueprint $table): void {
                $table->dropUnique('uq_one_current_school_year_per_school');
                $table->dropColumn('current_school_id');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS uq_one_current_school_year_per_school');
        }
        Schema::dropIfExists('school_years');
    }
};
