<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_assistant_settings', function (Blueprint $table): void {
            $table->string('voice_language', 16)->default('bn-BD');
            $table->boolean('proactive_enabled')->default(false);
            $table->unsignedTinyInteger('proactive_max_suggestions')->default(3);
            $table->string('proactive_timezone', 64)->default('UTC');
            $table->date('proactive_briefing_date')->nullable();
            $table->json('proactive_briefing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'voice_language',
                'proactive_enabled',
                'proactive_max_suggestions',
                'proactive_timezone',
                'proactive_briefing_date',
                'proactive_briefing',
            ]);
        });
    }
};
