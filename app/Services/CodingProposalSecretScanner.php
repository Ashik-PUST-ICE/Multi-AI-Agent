<?php

namespace App\Services;

final class CodingProposalSecretScanner
{
    /**
     * Return finding labels only; matched values are never returned or logged.
     *
     * @return list<string>
     */
    public function findings(string $content): array
    {
        $patterns = [
            'private_key' => '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/i',
            'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
            'github_token' => '/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/',
            'gitlab_token' => '/\bglpat-[A-Za-z0-9_-]{20,}\b/',
            'slack_token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
            'google_api_key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
            'openai_api_key' => '/\bsk-(?:proj-)?[A-Za-z0-9_-]{20,}\b/',
        ];
        $findings = [];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $findings[] = $label;
            }
        }

        $assignmentPattern = '/\b(?:api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|password|passwd|secret|token)\b\s*[:=]\s*(?:"([^"\r\n]{8,})"|\'([^\'\r\n]{8,})\'|([A-Za-z0-9_+\/.=-]{12,}))/i';
        if (preg_match_all($assignmentPattern, $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $value = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]);
                if (! $this->isPlaceholder($value)) {
                    $findings[] = 'credential_assignment';
                    break;
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    public function findingsInFields(array $fields): array
    {
        $findings = [];
        foreach ($fields as $content) {
            $findings = [...$findings, ...$this->findings($content)];
        }

        return array_values(array_unique($findings));
    }

    private function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value, " \t\n\r\0\x0B\"'`"));

        return $normalized === ''
            || preg_match('/^(?:<[^>]+>|\\$\\{[^}]+\\}|your[_ -]|example|sample|placeholder|dummy|test|changeme|replace[_ -]|xxx)/i', $normalized) === 1;
    }
}
