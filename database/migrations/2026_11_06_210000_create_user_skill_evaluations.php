<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_skills', function (Blueprint $table): void {
            $table->boolean('evaluation_auto_disable')->default(false);
            $table->decimal('evaluation_minimum_score', 5, 2)->nullable();
        });

        Schema::create('user_skill_test_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_skill_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('prompt');
            $table->text('reference_answer')->nullable();
            $table->json('criteria')->nullable();
            $table->timestamps();
            $table->index(['user_skill_id', 'id']);
        });

        Schema::create('user_skill_evaluation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_skill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_skill_version_id')->constrained('user_skill_versions')->cascadeOnDelete();
            $table->foreignId('initiated_by')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('model', 160);
            $table->string('status', 20)->default('running')->index();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedSmallInteger('case_count')->default(0);
            $table->json('case_results')->nullable();
            $table->json('token_usage')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_skill_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_skill_evaluation_runs');
        Schema::dropIfExists('user_skill_test_cases');
        Schema::table('user_skills', function (Blueprint $table): void {
            $table->dropColumn(['evaluation_auto_disable', 'evaluation_minimum_score']);
        });
    }
};
