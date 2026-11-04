<?php

namespace App\Tools;

use App\Services\SafeSandboxService;
use InvalidArgumentException;
use LogicException;

class CodeExecuteTool implements ContextualToolInterface
{
    public function name(): string
    {
        return 'code_execute';
    }

    public function description(): string
    {
        return 'Run a command inside an isolated Docker sandbox only after explicit owner approval.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function minimumTrustLevel(): int
    {
        return AGENT_TRUST_LEVEL_SENSITIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Command to run inside the isolated sandbox.',
                    'maxLength' => 2000,
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 300,
                    'description' => 'Execution timeout in seconds.',
                ],
                'workdir' => [
                    'type' => 'string',
                    'description' => 'Optional working directory inside the sandbox.',
                    'maxLength' => 255,
                ],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        throw new LogicException('Live code execution requires an authenticated agent execution context.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executeWithContext(array $input, ToolExecutionContext $context): array
    {
        $command = $input['command'] ?? null;
        if (! is_string($command)) {
            throw new InvalidArgumentException('A command string is required.');
        }

        $timeout = $input['timeout_seconds'] ?? 30;
        $workdir = $input['workdir'] ?? null;

        return app(SafeSandboxService::class)->runCode($context->user, $command, [
            'timeout_seconds' => is_numeric($timeout) ? (int) $timeout : 30,
            'workdir' => is_string($workdir) ? $workdir : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function simulate(array $input): array
    {
        $command = $input['command'] ?? null;
        if (! is_string($command) || trim($command) === '') {
            throw new InvalidArgumentException('A non-empty command is required.');
        }

        return [
            'simulated' => true,
            'command' => trim($command),
            'status' => 'not_run',
            'notice' => 'Sandbox simulation only; no command was executed.',
        ];
    }
}
