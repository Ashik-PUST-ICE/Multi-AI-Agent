<?php

namespace App\Tools;

interface ContextualToolInterface extends ToolInterface
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executeWithContext(array $input, ToolExecutionContext $context): array;
}
