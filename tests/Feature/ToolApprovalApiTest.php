<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Llm\ProviderCatalog;
use App\Models\PendingApproval;
use App\Models\User;
use App\Services\AgentRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ToolApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_and_approve_only_their_own_pending_action(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $approval = $this->createPendingApproval($owner);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'approvals');
        $this->postJson("/api/v1/approvals/{$approval->id}/approve")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/approvals')
            ->assertOk()
            ->assertJsonPath('approvals.0.id', $approval->id)
            ->assertJsonPath('approvals.0.payload.tool_name', 'write_file');

        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [
            'decision_note' => 'Approved for this exact path.',
        ])->assertOk()
            ->assertJsonPath('status', APPROVAL_STATUS_APPROVED);

        $this->assertDatabaseHas('pending_approvals', [
            'id' => $approval->id,
            'status' => APPROVAL_STATUS_APPROVED,
            'decided_by' => $owner->id,
        ]);
        Queue::assertPushed(RunAgentJob::class);
    }

    public function test_rejecting_an_action_fails_its_task_without_dispatching_a_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $approval = $this->createPendingApproval($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/approvals/{$approval->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', APPROVAL_STATUS_REJECTED);

        $approval->refresh();
        $this->assertSame(APPROVAL_STATUS_REJECTED, $approval->status);
        $this->assertSame(TASK_STATUS_FAILED, $approval->task->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_expired_approval_fails_the_waiting_task_when_owner_opens_approval_list(): void
    {
        $owner = User::factory()->create();
        $approval = $this->createPendingApproval($owner);
        $approval->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/approvals')
            ->assertOk()
            ->assertJsonCount(0, 'approvals');

        $approval->refresh();
        $this->assertSame(APPROVAL_STATUS_REJECTED, $approval->status);
        $this->assertSame(TASK_STATUS_FAILED, $approval->task->fresh()->status);
    }

    public function test_sensitive_live_tool_creates_a_sanitized_approval_and_pauses_the_run(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'tool_calls' => [[
                            'id' => 'call-approval-1',
                            'function' => [
                                'name' => 'web_search',
                                'arguments' => '{"query":"Laravel","api_key":"do-not-persist-this"}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
        ]);
        $catalog = app(ProviderCatalog::class);
        $providerId = array_key_first($catalog->all());
        $modelId = array_key_first($catalog->provider($providerId)['models']);
        $user = User::factory()->create();
        $user->llmSettings()->create([
            'provider' => $providerId,
            'model' => $modelId,
            'approved_models' => [
                ['provider' => $providerId, 'model' => $modelId],
            ],
            'agent_models' => [],
        ]);
        $user->apiKeys()->create([
            'provider' => $providerId,
            'api_key' => 'test-provider-key',
        ]);
        $task = $user->tasks()->create([
            'title' => 'Approval gate task',
            'input' => ['prompt' => 'Search for Laravel.'],
            'status' => TASK_STATUS_PENDING,
            'mode' => TASK_MODE_LIVE,
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'mode' => TASK_MODE_LIVE,
            'input' => [
                'prompt' => 'Search for Laravel.',
                'context' => [],
            ],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);

        app(AgentRunService::class)->run($agentRun->id);

        $agentRun->refresh();
        $task->refresh();
        $approval = $agentRun->pendingApprovals()->firstOrFail();

        $this->assertSame(AGENT_RUN_STATUS_PENDING, $agentRun->status);
        $this->assertSame(TASK_STATUS_PENDING, $task->status);
        $this->assertSame(APPROVAL_STATUS_PENDING, $approval->status);
        $this->assertSame('web_search', $approval->payload['tool_name']);
        $this->assertSame('[REDACTED]', $approval->payload['tool_input']['api_key']);
        $this->assertSame('Laravel', $approval->payload['tool_input']['query']);
        $this->assertDatabaseHas('run_events', [
            'agent_run_id' => $agentRun->id,
            'event_type' => 'tool.approval_requested',
        ]);
    }

    private function createPendingApproval(User $user): PendingApproval
    {
        $task = $user->tasks()->create([
            'title' => 'Approval test',
            'input' => ['prompt' => 'Test approval.'],
            'status' => TASK_STATUS_PENDING,
            'mode' => TASK_MODE_LIVE,
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'mode' => TASK_MODE_LIVE,
            'input' => ['prompt' => 'Test approval.', 'context' => []],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);

        return $agentRun->pendingApprovals()->create([
            'task_id' => $task->id,
            'action' => 'tool:write_file',
            'requested_by' => $user->id,
            'payload' => [
                'tool_name' => 'write_file',
                'tool_input' => ['path' => 'reports/output.txt'],
                'approval_signature' => str_repeat('a', 64),
            ],
            'status' => APPROVAL_STATUS_PENDING,
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
