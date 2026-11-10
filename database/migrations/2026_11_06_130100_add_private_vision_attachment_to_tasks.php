<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('vision_image_path')->nullable();
            $table->string('vision_image_mime_type', 64)->nullable();
            $table->timestamp('vision_image_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['vision_image_expires_at']);
            $table->dropColumn(['vision_image_path', 'vision_image_mime_type', 'vision_image_expires_at']);
        });
    }
};
