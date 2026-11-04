<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('event', 100);
            $table->text('details')->nullable();
            $table->char('userId', 36)->nullable();
            $table->char('deviceId', 36)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deviceId')->references('id')->on('devices')->nullOnDelete();
            $table->index(['event', 'createdAt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
