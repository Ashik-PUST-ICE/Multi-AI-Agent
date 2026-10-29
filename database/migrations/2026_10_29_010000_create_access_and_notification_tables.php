<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('access_mode')->default(ACCESS_MODE_USER);
            $table->json('agent_access_modes')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->string('quiet_hours_start', 5)->nullable();
            $table->string('quiet_hours_end', 5)->nullable();
            $table->string('digest_time', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('user_access_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('agent_name', 64);
            $table->string('tool_name', 100);
            $table->json('input_constraints')->nullable();
            $table->unsignedTinyInteger('scope');
            $table->unsignedInteger('maximum_uses');
            $table->unsignedInteger('uses_count')->default(0);
            $table->unsignedSmallInteger('undo_window_seconds')->default(0);
            $table->unsignedTinyInteger('status')->default(ACCESS_POLICY_STATUS_ACTIVE);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->unsignedTinyInteger('priority')->default(NOTIFICATION_PRIORITY_NORMAL);
            $table->string('title', 160);
            $table->string('message', 500);
            $table->json('data')->nullable();
            $table->string('dedupe_key', 160)->nullable()->unique();
            $table->unsignedTinyInteger('status')->default(NOTIFICATION_STATUS_UNREAD);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('user_access_policies');
        Schema::dropIfExists('user_access_settings');
    }
};
