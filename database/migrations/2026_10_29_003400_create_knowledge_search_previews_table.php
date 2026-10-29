<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_search_previews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->char('prompt_hash', 64);
            $table->json('document_ids');
            $table->json('context');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_search_previews');
    }
};
