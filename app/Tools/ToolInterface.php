<?php

namespace App\Tools;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function requiresApproval(): bool;

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function simulate(array $input): array;
}
