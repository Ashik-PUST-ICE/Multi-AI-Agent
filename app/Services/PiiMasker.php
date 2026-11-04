<?php

namespace App\Services;

use RuntimeException;

class PiiMasker
{
    private const EMAIL_PATTERN = '/(?<![\w.+-])[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}(?![A-Z0-9.-])/i';

    private const SSN_PATTERN = '/(?<!\d)\d{3}-\d{2}-\d{4}(?!\d)/';

    private const CARD_PATTERN = '/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/';

    private const PHONE_PATTERN = '/(?<!\d)\+?\d[\d(). -]{6,24}\d(?!\d)/';

    public function maskText(string $text): string
    {
        $text = $this->replace(self::EMAIL_PATTERN, '[PII:EMAIL]', $text);
        $text = $this->replace(self::SSN_PATTERN, '[PII:SSN]', $text);
        $text = $this->replaceCallback(self::CARD_PATTERN, fn (string $match): string => (
            $this->digitCount($match) >= 13 && $this->digitCount($match) <= 19
                ? '[PII:CARD]'
                : $match
        ), $text);

        return $this->replaceCallback(self::PHONE_PATTERN, fn (string $match): string => (
            $this->digitCount($match) >= 9 && $this->digitCount($match) <= 15
                ? '[PII:PHONE]'
                : $match
        ), $text);
    }

    public function safeStreamCutoff(string $text, int $cutoff): int
    {
        foreach ([self::EMAIL_PATTERN, self::SSN_PATTERN, self::CARD_PATTERN, self::PHONE_PATTERN] as $pattern) {
            $matches = [];
            $matchCount = preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            if ($matchCount === false) {
                throw new RuntimeException('Unable to safely inspect streamed text for personal information.');
            }

            foreach ($matches[0] as [$match, $offset]) {
                $end = $offset + strlen($match);
                if ($offset >= $cutoff || $end <= $cutoff) {
                    continue;
                }

                if ($pattern === self::CARD_PATTERN && ($this->digitCount($match) < 13 || $this->digitCount($match) > 19)) {
                    continue;
                }
                if ($pattern === self::PHONE_PATTERN && ($this->digitCount($match) < 9 || $this->digitCount($match) > 15)) {
                    continue;
                }

                $cutoff = $offset;
            }
        }

        return $cutoff;
    }

    private function digitCount(string $value): int
    {
        return strlen(preg_replace('/\D/', '', $value) ?? '');
    }

    private function replace(string $pattern, string $replacement, string $text): string
    {
        $result = preg_replace($pattern, $replacement, $text);

        if ($result === null) {
            throw new RuntimeException('Unable to safely mask personal information.');
        }

        return $result;
    }

    private function replaceCallback(string $pattern, callable $callback, string $text): string
    {
        $result = preg_replace_callback($pattern, static fn (array $matches): string => $callback($matches[0]), $text);

        if ($result === null) {
            throw new RuntimeException('Unable to safely mask personal information.');
        }

        return $result;
    }
}
