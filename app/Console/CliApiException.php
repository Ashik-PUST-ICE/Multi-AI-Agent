<?php

namespace App\Console;

use RuntimeException;

final class CliApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
    ) {
        parent::__construct($message, $statusCode);
    }
}
