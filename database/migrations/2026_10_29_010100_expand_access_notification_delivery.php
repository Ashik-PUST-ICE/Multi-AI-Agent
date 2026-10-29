<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_access_policies', function (Blueprint $table): void {
            $table->timestamp('last_reminder_at')->nullable();
        });
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reminder_count')->default(0);
            $table->timestamp('next_reminder_at')->nullable()->index();
            $table->timestamp('response_at')->nullable();
            $table->text('external_response_links')->nullable();
        });

        Schema::create('notification_channel_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->boolean('enabled')->default(false);
            $table->json('settings');
            $table->timestamps();
            $table->unique(['user_id', 'channel']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('user_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_reference', 160)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'channel']);
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('public_key', 255);
            $table->text('auth_token');
            $table->timestamps();
            $table->unique(['user_id', 'endpoint_hash']);
        });

        Schema::create('notification_response_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pending_approval_id')->constrained('pending_approvals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('decision', 12);
            $table->timestamp('expires_at');
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['pending_approval_id', 'expires_at']);
        });

        Schema::create('deferred_tool_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pending_approval_id')->unique()->constrained('pending_approvals')->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('user_access_policies')->cascadeOnDelete();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('agent_run_id')->index();
            $table->string('agent_name', 64);
            $table->string('tool_name', 100);
            $table->text('tool_input');
            $table->char('approval_signature', 64);
            $table->timestamp('scheduled_at')->index();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deferred_tool_actions');
        Schema::dropIfExists('notification_response_tokens');
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_channel_configs');
        Schema::table('user_access_policies', function (Blueprint $table): void {
            $table->dropColumn('last_reminder_at');
        });
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->dropIndex(['next_reminder_at']);
            $table->dropColumn(['reminder_count', 'next_reminder_at', 'response_at', 'external_response_links']);
        });
    }
};
