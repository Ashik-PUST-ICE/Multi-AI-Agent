<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AgentActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentActivityLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_messages_and_events_are_persisted_with_credentials_redacted(): void
    {
        $user = User::factory()->create();
        $task = $user->tasks()->create([
            'title' => 'Logging test',
            'input' => ['prompt' => 'Test logging.'],
        ]);
        $agentRun = $task->agentRuns()->create([
            'agent_name' => 'Research',
            'input' => ['prompt' => 'Test logging.'],
        ]);

        app(AgentActivityLogger::class)->recordMessage(
            $agentRun,
            'assistant',
            'Bearer top-secret-token api_key=sk-very-secret-provider-key',
            ['api_key' => 'never-store-this'],
        );

        $message = $agentRun->messages()->firstOrFail();
        $event = $agentRun->runEvents()->firstOrFail();
        $this->assertStringNotContainsString('top-secret-token', $message->content);
        $this->assertStringNotContainsString('very-secret-provider-key', $message->content);
        $this->assertSame('[REDACTED]', $message->metadata['api_key']);
        $this->assertSame('agent.message', $event->event_type);
        $this->assertSame($message->id, $event->payload['message_id']);
    }

    public function test_nested_credentials_are_redacted_before_assistant_messages_are_serialized(): void
    {
        $safeMessage = app(AgentActivityLogger::class)->redactValue([
            'tool_calls' => [[
                'function' => [
                    'arguments' => [
                        'api_key' => 'nested-private-key',
                        'query' => 'public query',
                    ],
                ],
            ]],
        ]);

        $this->assertSame(
            '[REDACTED]',
            $safeMessage['tool_calls'][0]['function']['arguments']['api_key'],
        );
        $this->assertSame(
            'public query',
            $safeMessage['tool_calls'][0]['function']['arguments']['query'],
        );
    }
}
