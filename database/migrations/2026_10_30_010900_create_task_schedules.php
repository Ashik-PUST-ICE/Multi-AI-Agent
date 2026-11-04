<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('scheduled_task_run_id')->nullable()->unique();
        });

        Schema::create('task_schedules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title');
            $table->text('prompt');
            $table->string('cadence', 16);
            $table->string('timezone', 64);
            $table->time('local_time');
            $table->tinyInteger('weekday')->nullable();
            $table->timestamp('next_run_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->tinyInteger('enabled')->default(1)->index();
            $table->timestamp('last_scheduled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'next_run_at']);
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('task_schedule_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->tinyInteger('status')->default(SCHEDULED_TASK_RUN_PENDING)->index();
            $table->timestamp('scheduled_for');
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['task_schedule_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('task_schedules');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['scheduled_task_run_id']);
            $table->dropColumn('scheduled_task_run_id');
        });
    }
};
