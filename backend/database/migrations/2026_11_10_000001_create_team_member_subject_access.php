<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_subject_access', function (Blueprint $table): void {
            $table->char('user_id', 36);
            $table->char('subject_id', 36);
            $table->char('granted_by_user_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'subject_id']);
            $table->foreign('user_id', 'fk_team_member_subject_access_user')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('subject_id', 'fk_team_member_subject_access_subject')
                ->references('id')->on('subjects')->cascadeOnDelete();
            $table->foreign('granted_by_user_id', 'fk_team_member_subject_access_granted_by')
                ->references('id')->on('users')->nullOnDelete();
            $table->index('subject_id', 'idx_team_member_subject_access_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_subject_access');
    }
};
