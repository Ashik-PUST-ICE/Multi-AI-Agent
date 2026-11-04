<?php

namespace App\Tools;

use App\Services\McpServerService;
use InvalidArgumentException;
use LogicException;

class McpCallTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'mcp_call';
    }

    public function description(): string
    {
        return 'Call an owner-approved MCP tool. Each live call requires outbound trust and exact owner approval.';
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
        return [
            'type' => 'object',
            'properties' => [
                'server_id' => ['type' => 'integer', 'minimum' => 1],
                'tool_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                'arguments' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'required' => ['server_id', 'tool_name', 'arguments'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        throw new LogicException('MCP calls require an authenticated owner context.');
    }

    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        if (! is_int($input['server_id'] ?? null)
            || ! is_string($input['tool_name'] ?? null)
            || ! is_array($input['arguments'] ?? null)) {
            throw new InvalidArgumentException('MCP call input must include a server, tool name, and object arguments.');
        }
        if (strlen(json_encode($input['arguments'], JSON_THROW_ON_ERROR)) > 16000) {
            throw new InvalidArgumentException('MCP arguments exceed the 16 KB limit.');
        }

        return app(McpServerService::class)->call(
            $context->user,
            $input['server_id'],
            $input['tool_name'],
            $input['arguments'],
        );
    }

    public function simulate(array $input): array
    {
        return [
            'simulated' => true,
            'server_id' => $input['server_id'] ?? null,
            'tool_name' => $input['tool_name'] ?? null,
            'called' => false,
            'notice' => 'Sandbox simulation only; no MCP request was sent.',
        ];
    }
}
