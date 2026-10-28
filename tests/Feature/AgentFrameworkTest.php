<?php

namespace Tests\Feature;

use App\Agents\BaseAgent;
use App\Agents\ResearchAgent;
use App\Llm\ChatResponse;
use App\Llm\LlmProvider;
use App\Tools\ToolApprovalRequiredException;
use App\Tools\ToolInterface;
use App\Tools\ToolRegistry;
use App\Tools\WebSearchTool;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AgentFrameworkTest extends TestCase
{
    public function test_sandbox_always_simulates_tools_and_agent_returns_provider_response(): void
    {
        $tool = new class implements ToolInterface
        {
            public bool $executed = false;

            public function name(): string
            {
                return 'web_search';
            }

            public function description(): string
            {
                return 'Search the web.';
            }

            public function requiresApproval(): bool
            {
                return false;
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ];
            }

            public function execute(array $input): array
            {
                $this->executed = true;

                throw new LogicException('Sandbox must not execute this tool.');
            }

            public function simulate(array $input): array
            {
                return ['simulated' => true, 'query' => $input['query']];
            }
        };
        $registry = new ToolRegistry;
        $registry->register($tool);
        $provider = new class implements LlmProvider
        {
            public array $calls = [];

            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                $this->calls[] = ['messages' => $messages, 'tools' => $tools];

                if (count($this->calls) === 1) {
                    return new ChatResponse(null, [[
                        'id' => 'call-1',
                        'name' => 'web_search',
                        'arguments' => ['query' => 'Laravel'],
                    ]], ['input_tokens' => 5, 'output_tokens' => 1], 'tool_calls');
                }

                return new ChatResponse('Search simulated.', [], ['input_tokens' => 0, 'output_tokens' => 3], 'stop');
            }
        };

        $result = App::make(ResearchAgent::class, [
            'provider' => $provider,
            'tools' => $registry,
        ])->run('Research Laravel', ['source' => 'user input']);

        $this->assertFalse($tool->executed);
        $this->assertSame('Search simulated.', $result['text']);
        $this->assertSame([
            'input_tokens' => 5,
            'output_tokens' => 4,
            'total_tokens' => 9,
        ], $result['usage']);
        $this->assertSame('web_search', $provider->calls[0]['tools'][0]['name']);
        $this->assertStringContainsString('untrusted data', $provider->calls[0]['messages'][0]['content']);
        $this->assertSame('user', $provider->calls[0]['messages'][2]['role']);
        $this->assertStringContainsString('user input', $provider->calls[0]['messages'][2]['content']);
        $this->assertStringContainsString('simulated', $provider->calls[1]['messages'][4]['content']);
    }

    public function test_web_search_simulation_does_not_send_network_requests(): void
    {
        $result = (new WebSearchTool)->simulate(['query' => 'Laravel']);

        $this->assertTrue($result['simulated']);
        $this->assertSame([], $result['results']);
        $this->assertStringContainsString('no web request', $result['notice']);
    }

    public function test_live_web_search_is_disabled_until_security_controls_exist(): void
    {
        $this->expectException(LogicException::class);
        (new WebSearchTool)->execute(['query' => 'Laravel']);
    }

    public function test_registry_rejects_duplicate_and_unknown_tools(): void
    {
        $tool = new WebSearchTool;
        $registry = new ToolRegistry;
        $registry->register($tool);

        try {
            $registry->register($tool);
            $this->fail('Registering a duplicate tool must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already registered', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->get('missing_tool');
    }

    public function test_registry_rejects_unsupported_modes(): void
    {
        $registry = new ToolRegistry;
        $registry->register(new WebSearchTool);

        $this->expectException(InvalidArgumentException::class);
        $registry->run('web_search', ['query' => 'Laravel'], 'unknown');
    }

    public function test_live_sensitive_tool_requires_approval_for_the_exact_input(): void
    {
        $tool = new class implements ToolInterface
        {
            public bool $executed = false;

            public function name(): string
            {
                return 'write_file';
            }

            public function description(): string
            {
                return 'Write a file.';
            }

            public function requiresApproval(): bool
            {
                return true;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];
            }

            public function execute(array $input): array
            {
                $this->executed = true;

                return ['written' => $input['path']];
            }

            public function simulate(array $input): array
            {
                return ['simulated' => true];
            }
        };
        $registry = new ToolRegistry;
        $registry->register($tool);
        $input = ['path' => 'reports/result.txt'];

        try {
            $registry->run('write_file', $input, TASK_MODE_LIVE);
            $this->fail('A sensitive live tool must require approval.');
        } catch (ToolApprovalRequiredException $exception) {
            $this->assertSame('write_file', $exception->toolName);
            $this->assertSame($input, $exception->input);
        }

        $approvalSignature = $registry->approvalSignature('write_file', $input);
        $result = $registry->run('write_file', $input, TASK_MODE_LIVE, [$approvalSignature]);

        $this->assertTrue($tool->executed);
        $this->assertSame(['written' => 'reports/result.txt'], $result);
        $this->assertNotSame(
            $approvalSignature,
            $registry->approvalSignature('write_file', ['path' => 'reports/other.txt']),
        );
    }

    public function test_agent_enforces_the_configured_total_tool_call_limit(): void
    {
        config(['llm.max_tool_calls' => 1]);

        $tool = new class implements ToolInterface
        {
            public int $simulationCount = 0;

            public function name(): string
            {
                return 'sample_lookup';
            }

            public function description(): string
            {
                return 'Look up a sample.';
            }

            public function requiresApproval(): bool
            {
                return false;
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
                $this->simulationCount++;

                return ['simulated' => true];
            }
        };
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register($tool);
        $provider = new class implements LlmProvider
        {
            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                return new ChatResponse(null, [
                    ['id' => 'call-1', 'name' => 'sample_lookup', 'arguments' => []],
                    ['id' => 'call-2', 'name' => 'sample_lookup', 'arguments' => []],
                ], [], 'tool_calls');
            }
        };
        $agent = new class($provider, $toolRegistry) extends BaseAgent
        {
            public function name(): string
            {
                return 'LimitedAgent';
            }

            protected function systemPrompt(): string
            {
                return 'Use the tool when needed.';
            }

            protected function allowedTools(): array
            {
                return ['sample_lookup'];
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent exceeded the maximum number of tool calls.');
        $agent->run('Check the configured limit.');
    }

    public function test_agent_corrects_schema_invalid_output_once(): void
    {
        $provider = new class implements LlmProvider
        {
            public int $calls = 0;

            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return new ChatResponse(null, [], ['output_tokens' => 1], 'stop');
                }

                return new ChatResponse('{"text":"Corrected output."}', [], ['output_tokens' => 2], 'stop');
            }
        };
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new WebSearchTool);

        $result = App::make(ResearchAgent::class, [
            'provider' => $provider,
            'tools' => $toolRegistry,
        ])->run('Research a topic.');

        $this->assertSame('Corrected output.', $result['text']);
        $this->assertGreaterThan(0, $result['usage']['input_tokens']);
        $this->assertSame(3, $result['usage']['output_tokens']);
        $this->assertSame(
            $result['usage']['input_tokens'] + $result['usage']['output_tokens'],
            $result['usage']['total_tokens'],
        );
        $this->assertSame(2, $provider->calls);
    }

    public function test_agent_fails_if_its_single_output_correction_is_invalid(): void
    {
        $provider = new class implements LlmProvider
        {
            public int $calls = 0;

            public function chat(array $messages, array $tools = [], array $options = []): ChatResponse
            {
                $this->calls++;

                return new ChatResponse(
                    $this->calls === 1 ? null : '{"text":""}',
                    [],
                    [],
                    'stop',
                );
            }
        };
        $toolRegistry = new ToolRegistry;
        $toolRegistry->register(new WebSearchTool);
        $agent = App::make(ResearchAgent::class, [
            'provider' => $provider,
            'tools' => $toolRegistry,
        ]);

        try {
            $agent->run('Research a topic.');
            $this->fail('An invalid output after correction must fail the agent run.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('after one correction', $exception->getMessage());
        }

        $this->assertSame(2, $provider->calls);
    }
}
