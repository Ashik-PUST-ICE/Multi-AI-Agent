<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_assistant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('persona')->default('friendly');
            $table->string('custom_persona')->nullable();
            $table->boolean('voice_input_enabled')->default(false);
            $table->boolean('voice_output_enabled')->default(false);
            $table->boolean('vision_enabled')->default(false);
            $table->boolean('smart_home_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assistant_settings');
    }
};
