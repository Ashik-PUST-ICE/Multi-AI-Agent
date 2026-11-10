<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_run_id')->nullable()->index();
            $table->unsignedBigInteger('handoff_to')->nullable()->index();
            $table->string('status', 24)->default('recorded')->index();
        });

        DB::table('run_events')->orderBy('id')->chunkById(250, function ($events): void {
            foreach ($events as $event) {
                $payload = is_array($event->payload)
                    ? $event->payload
                    : json_decode((string) $event->payload, true);
                $payload = is_array($payload) ? $payload : [];
                $targetRunId = $payload['next_agent_run_id'] ?? null;
                $validHandoff = $event->event_type === 'agent.handoff'
                    && (is_int($targetRunId) || (is_string($targetRunId) && ctype_digit($targetRunId)))
                    && DB::table('agent_runs')
                        ->where('id', (int) $targetRunId)
                        ->where('task_id', $event->task_id)
                        ->exists();

                DB::table('run_events')
                    ->where('id', $event->id)
                    ->update([
                        'parent_run_id' => $event->agent_run_id,
                        'handoff_to' => $validHandoff ? (int) $targetRunId : null,
                        'status' => $this->eventStatus($event->event_type),
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('run_events', function (Blueprint $table): void {
            $table->dropIndex(['parent_run_id']);
            $table->dropIndex(['handoff_to']);
            $table->dropIndex(['status']);
            $table->dropColumn(['parent_run_id', 'handoff_to', 'status']);
        });
    }

    private function eventStatus(string $eventType): string
    {
        return match ($eventType) {
            'agent.started', 'tool.started' => 'running',
            'agent.completed', 'task.completed', 'tool.completed' => 'completed',
            'agent.failed', 'task.failed', 'tool.failed', 'skeptic.rejected',
            'observability.silent_failure', 'observability.stagnation' => 'failed',
            'tool.approval_required', 'agent.waiting_for_approval' => 'waiting',
            default => 'recorded',
        };
    }
};
