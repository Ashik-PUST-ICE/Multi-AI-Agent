<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('trace_id', 32)->nullable();
            $table->string('trace_span_id', 16)->nullable();
        });
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->string('trace_id', 32)->nullable();
            $table->string('trace_span_id', 16)->nullable();
            $table->string('parent_span_id', 16)->nullable();
        });
        Schema::table('run_events', function (Blueprint $table): void {
            $table->string('trace_id', 32)->nullable();
            $table->string('trace_span_id', 16)->nullable();
            $table->string('parent_span_id', 16)->nullable();
        });

        DB::table('tasks')->orderBy('id')->chunkById(100, function ($tasks): void {
            foreach ($tasks as $task) {
                DB::table('tasks')->where('id', $task->id)->update([
                    'trace_id' => bin2hex(random_bytes(16)),
                    'trace_span_id' => bin2hex(random_bytes(8)),
                ]);
            }
        });

        DB::table('agent_runs')->orderBy('id')->chunkById(100, function ($runs): void {
            foreach ($runs as $run) {
                $task = DB::table('tasks')->where('id', $run->task_id)->first(['trace_id', 'trace_span_id']);
                if ($task === null) {
                    continue;
                }

                DB::table('agent_runs')->where('id', $run->id)->update([
                    'trace_id' => $task->trace_id,
                    'trace_span_id' => bin2hex(random_bytes(8)),
                    'parent_span_id' => $task->trace_span_id,
                ]);
            }
        });

        DB::table('run_events')->orderBy('id')->chunkById(100, function ($events): void {
            foreach ($events as $event) {
                $taskId = $event->task_id;
                if ($taskId === null && $event->agent_run_id !== null) {
                    $taskId = DB::table('agent_runs')->where('id', $event->agent_run_id)->value('task_id');
                }
                if ($taskId === null) {
                    continue;
                }

                $task = DB::table('tasks')->where('id', $taskId)->first(['trace_id', 'trace_span_id']);
                $run = $event->agent_run_id === null
                    ? null
                    : DB::table('agent_runs')->where('id', $event->agent_run_id)->first(['trace_span_id']);
                if ($task === null) {
                    continue;
                }

                DB::table('run_events')->where('id', $event->id)->update([
                    'task_id' => $taskId,
                    'trace_id' => $task->trace_id,
                    'trace_span_id' => bin2hex(random_bytes(8)),
                    'parent_span_id' => $run?->trace_span_id ?? $task->trace_span_id,
                ]);
            }
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->unique('trace_id');
        });
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->index(['trace_id', 'trace_span_id']);
        });
        Schema::table('run_events', function (Blueprint $table): void {
            $table->index(['trace_id', 'trace_span_id']);
        });
    }

    public function down(): void
    {
        Schema::table('run_events', function (Blueprint $table): void {
            $table->dropIndex(['trace_id', 'trace_span_id']);
            $table->dropColumn(['trace_id', 'trace_span_id', 'parent_span_id']);
        });
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropIndex(['trace_id', 'trace_span_id']);
            $table->dropColumn(['trace_id', 'trace_span_id', 'parent_span_id']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique(['trace_id']);
            $table->dropColumn(['trace_id', 'trace_span_id']);
        });
    }
};
