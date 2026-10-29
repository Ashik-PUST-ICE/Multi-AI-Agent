<?php

namespace Tests\Feature;

use App\Jobs\RunAgentJob;
use App\Models\NotificationResponseToken;
use App\Models\PendingApproval;
use App\Models\User;
use App\Tools\ToolInterface;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationResponseTest extends TestCase
{
    use DatabaseMigrations;

    public function test_one_time_response_link_requires_post_and_is_consumed_once(): void
    {
        Queue::fake();
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'safe_read';
            }

            public function description(): string
            {
                return 'Read non-sensitive information.';
            }

            public function requiresApproval(): bool
            {
                return false;
            }

            public function minimumTrustLevel(): int
            {
                return AGENT_TRUST_LEVEL_READ;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $input): array
            {
                return [];
            }

            public function simulate(array $input): array
            {
                return [];
            }
        });
        $this->app->instance(ToolRegistry::class, $registry);

        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'External response',
            'description' => 'Check a safe item.',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_PENDING,
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => [],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        $approval = PendingApproval::query()->create([
            'task_id' => $task->id,
            'agent_run_id' => $run->id,
            'action' => 'tool:safe_read',
            'requested_by' => $user->id,
            'payload' => ['tool_name' => 'safe_read'],
            'status' => APPROVAL_STATUS_PENDING,
            'expires_at' => now()->addMinutes(10),
        ]);
        $token = bin2hex(random_bytes(32));
        $responseToken = NotificationResponseToken::query()->create([
            'pending_approval_id' => $approval->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'decision' => 'approve',
            'expires_at' => now()->addMinutes(10),
        ]);

        $url = "/api/v1/notification-responses/{$token}/confirm";
        $this->get($url)->assertOk();
        $this->assertNull($responseToken->fresh()->consumed_at);
        $this->assertSame(APPROVAL_STATUS_PENDING, $approval->fresh()->status);

        $this->post($url, ['confirm' => '1'])->assertOk();
        $this->assertNotNull($responseToken->fresh()->consumed_at);
        $this->assertSame(APPROVAL_STATUS_APPROVED, $approval->fresh()->status);
        Queue::assertPushed(RunAgentJob::class);
        $this->post($url, ['confirm' => '1'])->assertGone();
    }

    public function test_response_link_cannot_approve_a_high_risk_tool(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'file_write';
            }

            public function description(): string
            {
                return 'Write a file.';
            }

            public function requiresApproval(): bool
            {
                return true;
            }

            public function minimumTrustLevel(): int
            {
                return AGENT_TRUST_LEVEL_WRITE;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $input): array
            {
                return [];
            }

            public function simulate(array $input): array
            {
                return [];
            }
        });
        $this->app->instance(ToolRegistry::class, $registry);

        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'High risk response',
            'description' => 'Write a file.',
            'mode' => TASK_MODE_LIVE,
            'status' => TASK_STATUS_PENDING,
        ]);
        $run = $task->agentRuns()->create([
            'agent_name' => 'Writer',
            'input' => [],
            'status' => AGENT_RUN_STATUS_PENDING,
        ]);
        $approval = PendingApproval::query()->create([
            'task_id' => $task->id,
            'agent_run_id' => $run->id,
            'action' => 'tool:file_write',
            'requested_by' => $user->id,
            'payload' => ['tool_name' => 'file_write'],
            'status' => APPROVAL_STATUS_PENDING,
            'expires_at' => now()->addMinutes(10),
        ]);
        $token = bin2hex(random_bytes(32));
        $responseToken = NotificationResponseToken::query()->create([
            'pending_approval_id' => $approval->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'decision' => 'approve',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->post("/api/v1/notification-responses/{$token}/confirm", ['confirm' => '1'])->assertForbidden();
        $this->assertNull($responseToken->fresh()->consumed_at);
        $this->assertSame(APPROVAL_STATUS_PENDING, $approval->fresh()->status);
    }
}
