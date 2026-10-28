<?php

namespace App\Llm;

use RuntimeException;
use Throwable;

class ProviderRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
