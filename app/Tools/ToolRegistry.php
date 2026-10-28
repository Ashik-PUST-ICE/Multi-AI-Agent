<?php

namespace App\Tools;

use InvalidArgumentException;
use JsonException;

class ToolRegistry
{
    /**
     * @var array<string, ToolInterface>
     */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $name = trim($tool->name());
        $description = trim($tool->description());

        if ($name === '') {
            throw new InvalidArgumentException('Tool names cannot be empty.');
        }

        if ($description === '') {
            throw new InvalidArgumentException("Tool [{$name}] must have a description.");
        }

        $inputSchema = $tool->inputSchema();

        if (($inputSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException("Tool [{$name}] must define an object input schema.");
        }

        if ($tool->minimumTrustLevel() < AGENT_TRUST_LEVEL_READ
            || $tool->minimumTrustLevel() > AGENT_TRUST_LEVEL_SENSITIVE) {
            throw new InvalidArgumentException("Tool [{$name}] must require a trust level between 1 and 4.");
        }

        $isDuplicate = isset($this->tools[$name]);

        if ($isDuplicate) {
            throw new InvalidArgumentException("Tool [{$name}] is already registered.");
        }

        $this->tools[$name] = $tool;
    }

    public function get(string $name): ToolInterface
    {
        $normalizedName = trim($name);
        $tool = $this->tools[$normalizedName] ?? null;

        if ($tool === null) {
            throw new InvalidArgumentException("Tool [{$normalizedName}] is not registered.");
        }

        return $tool;
    }

    /**
     * @param  list<string>  $allowedNames
     * @return array<int, array{name: string, description: string}>
     */
    public function definitions(array $allowedNames): array
    {
        $definitions = [];

        foreach ($allowedNames as $name) {
            $tool = $this->get($name);
            $definition = $this->definitionFor($tool);
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * Sandbox mode always calls simulate(), even when execute() is available.
     *
     * @param  list<string>  $approvedCallSignatures
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(
        string $name,
        array $input,
        string $mode = TASK_MODE_SANDBOX,
        array $approvedCallSignatures = [],
        int $trustLevel = 0,
    ): array {
        $tool = $this->get($name);

        if (! in_array($mode, [TASK_MODE_SANDBOX, TASK_MODE_LIVE], true)) {
            throw new InvalidArgumentException("Unsupported execution mode [{$mode}].");
        }

        if ($trustLevel < AGENT_TRUST_LEVEL_NONE || $trustLevel > AGENT_TRUST_LEVEL_SENSITIVE) {
            throw new InvalidArgumentException('Trust level must be between 0 and 4.');
        }

        if ($trustLevel < $tool->minimumTrustLevel()) {
            throw new ToolTrustLevelException($name, $tool->minimumTrustLevel(), $trustLevel);
        }

        if ($mode === TASK_MODE_SANDBOX) {
            return $tool->simulate($input);
        }

        $approvalSignature = $this->approvalSignature($name, $input);
        $isApproved = in_array($approvalSignature, $approvedCallSignatures, true);

        $requiresApproval = $tool->requiresApproval()
            || $tool->minimumTrustLevel() >= AGENT_TRUST_LEVEL_WRITE;

        if ($requiresApproval && ! $isApproved) {
            throw new ToolApprovalRequiredException($name, $input, $approvalSignature);
        }

        return $tool->execute($input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function approvalSignature(string $name, array $input): string
    {
        return hash('sha256', json_encode(
            [$name, $this->sortKeysRecursively($input)],
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function sortKeysRecursively(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $input[$key] = $this->sortKeysRecursively($value);
            }
        }

        if (! array_is_list($input)) {
            ksort($input);
        }

        return $input;
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    private function definitionFor(ToolInterface $tool): array
    {
        $name = trim($tool->name());
        $description = $tool->description();
        $inputSchema = $tool->inputSchema();

        return [
            'name' => $name,
            'description' => $description,
            'input_schema' => $inputSchema,
        ];
    }
}
