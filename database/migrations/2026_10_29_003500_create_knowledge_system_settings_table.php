<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_system_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('maximum_upload_kilobytes');
            $table->unsignedInteger('maximum_extracted_characters');
            $table->unsignedInteger('maximum_chunks_per_document');
            $table->unsignedInteger('chunk_characters');
            $table->unsignedInteger('chunk_overlap_characters');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_system_settings');
    }
};
