<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_llm_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('provider');
            $table->string('model');
            $table->json('approved_models');
            $table->json('agent_models')->nullable();
            $table->string('fallback_provider')->nullable();
            $table->string('fallback_model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_llm_settings');
    }
};
