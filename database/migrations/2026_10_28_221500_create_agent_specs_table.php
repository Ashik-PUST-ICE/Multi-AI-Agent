<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_specs', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_name');
            $table->unsignedInteger('version');
            $table->tinyInteger('status')->default(AGENT_SPEC_STATUS_DRAFT)->index();
            $table->string('role');
            $table->longText('system_prompt');
            $table->json('input_schema');
            $table->json('output_schema');
            $table->json('allowed_tools')->nullable();
            $table->unsignedTinyInteger('minimum_trust_level')->default(0);
            $table->string('default_model')->nullable();
            $table->string('fallback_model')->nullable();
            $table->unsignedTinyInteger('max_steps')->default(5);
            $table->unsignedInteger('max_tokens')->default(2000);
            $table->unsignedInteger('timeout_seconds')->default(60);
            $table->json('stop_conditions')->nullable();
            $table->json('success_criteria')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['agent_name', 'version']);
            $table->index(['agent_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_specs');
    }
};
