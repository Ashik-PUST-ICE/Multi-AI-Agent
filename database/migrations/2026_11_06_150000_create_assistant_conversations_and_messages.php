<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title', 120);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('role', 16);
            $table->longText('content');
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->timestamps();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
