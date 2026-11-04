<?php

namespace App\Services;

use RuntimeException;

class PiiMaskingStream
{
    private const HOLD_BACK_BYTES = 40;

    private string $buffer = '';

    public function __construct(private readonly PiiMasker $masker) {}

    public function push(string $chunk): string
    {
        $this->buffer .= $chunk;
        $cutoff = strlen($this->buffer) - self::HOLD_BACK_BYTES;

        if ($cutoff <= 0) {
            return '';
        }

        $cutoff = $this->masker->safeStreamCutoff($this->buffer, $cutoff);
        $prefix = substr($this->buffer, 0, $cutoff);
        $whitespace = [];
        $matches = preg_match_all('/\s+/', $prefix, $whitespace, PREG_OFFSET_CAPTURE);

        if ($matches === false) {
            throw new RuntimeException('Unable to safely split streamed text for personal-information masking.');
        }
        if ($matches === 0) {
            return '';
        }

        $lastWhitespace = $whitespace[0][array_key_last($whitespace[0])];
        $safeLength = $lastWhitespace[1] + strlen($lastWhitespace[0]);
        $safeText = substr($this->buffer, 0, $safeLength);
        $this->buffer = substr($this->buffer, $safeLength);

        return $this->masker->maskText($safeText);
    }

    public function flush(): string
    {
        $remaining = $this->masker->maskText($this->buffer);
        $this->buffer = '';

        return $remaining;
    }
}
