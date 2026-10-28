<?php

namespace App\Tools;

use RuntimeException;

class ToolTrustLevelException extends RuntimeException
{
    public function __construct(
        public readonly string $toolName,
        public readonly int $requiredLevel,
        public readonly int $currentLevel,
    ) {
        parent::__construct(
            "Agent trust level {$currentLevel} is below the required level {$requiredLevel} for tool [{$toolName}].",
        );
    }
}
