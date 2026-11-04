<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folder_access_plans', function (Blueprint $table): void {
            $table->char('user_id', 36)->nullable();
            $table->char('student_id', 36)->nullable();
            $table->char('permission_id', 36)->nullable()->unique();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->nullOnDelete();
            $table->foreign('permission_id')->references('id')->on('folder_permissions')->nullOnDelete();
            $table->index(['user_id', 'folder_id'], 'idx_folder_access_user_folder');
            $table->index('student_id', 'idx_folder_access_student');
        });

        Schema::create('folder_access_installments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('plan_id', 36);
            $table->unsignedInteger('sequence_no');
            $table->string('kind', 24);
            $table->date('period_month')->nullable();
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['plan_id', 'sequence_no']);
            $table->index('due_date', 'idx_folder_access_installments_due');
            $table->foreign('plan_id')->references('id')->on('folder_access_plans')->restrictOnDelete();
        });

        Schema::create('folder_access_payments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('installment_id', 36);
            $table->string('entry_type', 12)->default('payment');
            $table->decimal('amount', 12, 2);
            $table->string('method', 16);
            $table->timestamp('received_at')->useCurrent();
            $table->char('received_by_user_id', 36)->nullable();
            $table->string('reference_no', 64)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['installment_id', 'received_at'], 'idx_folder_access_payments_installment_received');
            $table->foreign('installment_id')->references('id')->on('folder_access_installments')->restrictOnDelete();
            $table->foreign('received_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_access_payments');
        Schema::dropIfExists('folder_access_installments');
        Schema::table('folder_access_plans', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['student_id']);
            $table->dropForeign(['permission_id']);
            $table->dropUnique(['permission_id']);
            $table->dropIndex('idx_folder_access_user_folder');
            $table->dropIndex('idx_folder_access_student');
            $table->dropColumn(['user_id', 'student_id', 'permission_id']);
        });
    }
};
