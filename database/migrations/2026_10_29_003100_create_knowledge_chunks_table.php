<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('knowledge_document_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedInteger('chunk_index');
            $table->longText('content');
            $table->json('embedding');
            $table->string('embedding_provider', 64);
            $table->string('embedding_model', 128);
            $table->timestamps();
            $table->unique(['knowledge_document_id', 'chunk_index']);
            $table->index(['user_id', 'knowledge_document_id']);
            $table->foreign('knowledge_document_id')
                ->references('id')
                ->on('knowledge_documents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
