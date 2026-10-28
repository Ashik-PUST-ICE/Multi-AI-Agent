<?php

namespace App\Tools;

use RuntimeException;

class ToolApprovalRequiredException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $input,
        public readonly string $approvalSignature,
    ) {
        parent::__construct("Tool [{$toolName}] requires user approval before execution.");
    }
}
