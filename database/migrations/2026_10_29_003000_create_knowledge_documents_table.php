<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('original_name', 255);
            $table->string('disk_path', 512)->unique();
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->tinyInteger('status')->default(KNOWLEDGE_DOCUMENT_STATUS_PENDING)->index();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
