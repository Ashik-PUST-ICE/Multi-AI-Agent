<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_task_queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('task_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_token_id')->index();
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
            $table->index(['device_token_id', 'status', 'priority', 'created_at'], 'device_queue_poll_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_task_queue_entries');
    }
};
