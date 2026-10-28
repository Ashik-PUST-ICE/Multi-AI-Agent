<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_run_messages_approvals_and_events_are_related(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Research task',
            'input' => ['prompt' => 'Summarize sources'],
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => ['query' => 'Laravel'],
        ]);
        $message = $task->messages()->create([
            'agent_run_id' => $run->id,
            'role' => 'assistant',
            'content' => 'Research started',
            'metadata' => ['source_count' => 0],
        ]);
        $approval = $task->pendingApprovals()->create([
            'agent_run_id' => $run->id,
            'action' => 'publish',
            'requested_by' => 'Writer',
            'payload' => ['title' => 'Draft'],
        ]);
        $event = $task->runEvents()->create([
            'agent_run_id' => $run->id,
            'event_type' => 'run.started',
            'sequence' => 1,
            'payload' => ['agent' => 'Research'],
        ]);

        $this->assertSame($user->id, $task->user->id);
        $this->assertSame(TASK_STATUS_PENDING, $task->status);
        $this->assertSame(TASK_MODE_SANDBOX, $task->mode);
        $this->assertSame(AGENT_RUN_STATUS_PENDING, $run->status);
        $this->assertSame(APPROVAL_STATUS_PENDING, $approval->status);
        $this->assertSame($task->id, $run->task->id);
        $this->assertSame($run->id, $message->agentRun->id);
        $this->assertSame($run->id, $approval->agentRun->id);
        $this->assertSame($run->id, $event->agentRun->id);
        $this->assertSame(['prompt' => 'Summarize sources'], $task->fresh()->input);
        $this->assertSame(['source_count' => 0], $message->fresh()->metadata);
        $this->assertSame(['title' => 'Draft'], $approval->fresh()->payload);
        $this->assertSame(['agent' => 'Research'], $event->fresh()->payload);
        foreach ([
            'tasks',
            'agent_runs',
            'agent_messages',
            'pending_approvals',
            'run_events',
        ] as $tableName) {
            $this->assertTrue(Schema::hasTable($tableName));
            $this->assertSame([], Schema::getForeignKeys($tableName));
        }

        foreach ([
            'roles',
            'permissions',
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
        ] as $tableName) {
            $this->assertSame([], Schema::getForeignKeys($tableName));
        }

        $this->assertContains(Schema::getColumnType('tasks', 'status'), ['integer', 'tinyint']);
        $this->assertContains(Schema::getColumnType('agent_runs', 'status'), ['integer', 'tinyint']);
        $this->assertContains(Schema::getColumnType('pending_approvals', 'status'), ['integer', 'tinyint']);
    }
}
