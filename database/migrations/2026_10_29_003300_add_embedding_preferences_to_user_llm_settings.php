<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->string('embedding_provider', 64)->nullable();
            $table->string('embedding_model', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_llm_settings', function (Blueprint $table): void {
            $table->dropColumn(['embedding_provider', 'embedding_model']);
        });
    }
};
