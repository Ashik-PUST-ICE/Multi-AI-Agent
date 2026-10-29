<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->decimal('max_task_cost', 14, 6)->nullable();
            $table->string('budget_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->dropColumn(['max_task_cost', 'budget_currency']);
        });
    }
};
