<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_skill_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_skill_version_id')->constrained('user_skill_versions')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('mode', 20);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->json('cost_by_currency')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_skill_id', 'task_id']);
            $table->index(['user_skill_id', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_skill_usages');
    }
};
