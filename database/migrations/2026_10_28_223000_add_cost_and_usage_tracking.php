<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->json('model_pricing')->nullable();
        });

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->json('token_usage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropColumn('token_usage');
        });

        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->dropColumn('model_pricing');
        });
    }
};
