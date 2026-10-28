<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->json('agent_brains')->nullable();
            $table->string('routing_policy')->default(MODEL_ROUTING_POLICY_CHEAPEST);
        });
    }

    public function down(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->dropColumn(['agent_brains', 'routing_policy']);
        });
    }
};
