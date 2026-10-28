<?php

namespace App\Tools;

use InvalidArgumentException;

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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $name, array $input, string $mode = TASK_MODE_SANDBOX): array
    {
        $tool = $this->get($name);
        $result = match ($mode) {
            TASK_MODE_SANDBOX => $tool->simulate($input),
            TASK_MODE_LIVE => $tool->execute($input),
            default => throw new InvalidArgumentException("Unsupported execution mode [{$mode}]."),
        };

        return $result;
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
