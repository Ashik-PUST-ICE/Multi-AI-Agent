<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('agent_name', 80)->index();
            $table->text('prompt');
            $table->text('reference_answer')->nullable();
            $table->json('criteria')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'agent_name']);
        });

        Schema::create('evaluation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_case_id')->constrained()->cascadeOnDelete();
            $table->string('agent_name', 80);
            $table->unsignedInteger('agent_spec_version');
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('status', 16)->index();
            $table->decimal('score', 6, 2)->nullable();
            $table->json('metrics')->nullable();
            $table->longText('output')->nullable();
            $table->json('token_usage')->nullable();
            $table->decimal('cost_amount', 16, 8)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'evaluation_case_id', 'created_at'], 'evaluation_results_case_history_index');
            $table->index(['agent_name', 'agent_spec_version', 'provider', 'model'], 'evaluation_results_comparison_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_results');
        Schema::dropIfExists('evaluation_cases');
    }
};
