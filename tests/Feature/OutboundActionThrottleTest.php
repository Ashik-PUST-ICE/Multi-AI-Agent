<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\User;
use App\Services\OutboundActionThrottle;
use App\Tools\ExecutionHaltedException;
use App\Tools\ToolInterface;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class OutboundActionThrottleTest extends TestCase
{
    use DatabaseMigrations;

    public function test_live_outbound_actions_stop_at_the_configured_task_limit(): void
    {
        config(['security.maximum_outbound_actions_per_task' => 2]);
        $run = $this->createRun(TASK_MODE_LIVE);
        $registry = $this->outboundToolRegistry();
        foreach ([1, 2] as $sequence) {
            $run->runEvents()->create([
                'task_id' => $run->task_id,
                'event_type' => 'tool.started',
                'mode' => TASK_MODE_LIVE,
                'sequence' => $sequence,
                'payload' => ['tool_name' => 'outbound_test'],
                'occurred_at' => now(),
            ]);
        }

        $this->expectException(ExecutionHaltedException::class);
        $this->expectExceptionMessage('outbound-action limit');
        (new OutboundActionThrottle($registry))->assertAllowed($run, 'outbound_test');
    }

    public function test_sandbox_actions_are_not_counted_as_outbound_calls(): void
    {
        config(['security.maximum_outbound_actions_per_task' => 0]);
        $run = $this->createRun(TASK_MODE_SANDBOX);
        $throttle = new OutboundActionThrottle($this->outboundToolRegistry());

        $this->assertNull($throttle->assertAllowed($run, 'outbound_test'));
    }

    private function createRun(string $mode): AgentRun
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Outbound throttle test',
            'description' => 'Test the outbound call limit.',
            'mode' => $mode,
            'status' => TASK_STATUS_RUNNING,
        ]);

        return $task->agentRuns()->create([
            'agent_name' => 'Research',
            'mode' => $mode,
            'input' => [],
            'status' => AGENT_RUN_STATUS_RUNNING,
        ]);
    }

    private function outboundToolRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry;
        $registry->register(new class implements ToolInterface
        {
            public function name(): string
            {
                return 'outbound_test';
            }

            public function description(): string
            {
                return 'Test outbound action.';
            }

            public function requiresApproval(): bool
            {
                return true;
            }

            public function minimumTrustLevel(): int
            {
                return AGENT_TRUST_LEVEL_OUTBOUND;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
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

        return $registry;
    }
}
