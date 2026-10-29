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
        public readonly int $deferSeconds = 0,
        public readonly ?int $policyId = null,
    ) {
        parent::__construct("Tool [{$toolName}] requires user approval before execution.");
    }
}
