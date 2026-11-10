<?php

namespace App\Services;

use App\Models\AgentSpec;

class AgentPromptLinter
{
    /**
     * @return array<string, mixed>
     */
    public function lint(AgentSpec $specification): array
    {
        $prompt = $specification->system_prompt;
        $lines = preg_split('/\R/u', $prompt) ?: [];
        $findings = [];
        $addFinding = static function (
            string $rule,
            string $severity,
            string $message,
            ?int $line = null,
        ) use (&$findings): void {
            $findings[] = array_filter([
                'rule' => $rule,
                'severity' => $severity,
                'message' => $message,
                'line' => $line,
            ], static fn (mixed $value): bool => $value !== null);
        };

        if (trim($prompt) === '') {
            $addFinding('empty_prompt', 'critical', 'The system prompt is empty.');
        } elseif (mb_strlen(trim($prompt)) < 120) {
            $addFinding('short_prompt', 'warning', 'The system prompt is brief; verify that its role, boundaries, and expected behavior are explicit.');
        }

        $placeholderPattern = '/\{\{[^{}\r\n]{1,100}\}\}|\$\{[A-Za-z_][A-Za-z0-9_]*\}|<\s*(?:TODO|INSERT|YOUR)\b[^>\r\n]*>/i';
        $overridePattern = '/\b(?:ignore|disregard|override|bypass)\s+(?:all\s+)?(?:previous|prior|system|developer|safety)\s+(?:instructions|rules|messages|policies)\b/i';
        $secretRequestPattern = '/\b(?:reveal|expose|print|return|show)\b.{0,50}\b(?:api[\s_-]?keys?|passwords?|credentials?|secrets?|system prompt)\b/i';
        $hasUntrustedBoundary = preg_match('/\b(?:untrusted|user[- ]provided|user[- ]supplied|external content|tool output)\b/i', $prompt) === 1;
        $seenLines = [];

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine === '') {
                continue;
            }
            $lineNumber = $index + 1;

            if (preg_match($placeholderPattern, $line) === 1) {
                $addFinding(
                    'unresolved_placeholder',
                    'warning',
                    'A template placeholder remains; confirm it is intentional before activation.',
                    $lineNumber,
                );
            }
            if (preg_match($overridePattern, $line) === 1) {
                $addFinding(
                    'instruction_override_language',
                    'high',
                    'This line contains language that may override system, developer, or safety instructions; review it carefully.',
                    $lineNumber,
                );
            }
            if (preg_match($secretRequestPattern, $line) === 1) {
                $addFinding(
                    'sensitive_data_request',
                    'high',
                    'This line may request sensitive credentials or hidden instructions; review its purpose and scope.',
                    $lineNumber,
                );
            }

            $normalizedLine = mb_strtolower(preg_replace('/\s+/u', ' ', $trimmedLine) ?? $trimmedLine);
            if (isset($seenLines[$normalizedLine])) {
                $addFinding(
                    'duplicate_line',
                    'info',
                    'A non-empty instruction line is repeated.',
                    $lineNumber,
                );
            } else {
                $seenLines[$normalizedLine] = true;
            }
        }

        if (! $hasUntrustedBoundary) {
            $addFinding(
                'missing_untrusted_input_boundary',
                'info',
                'Consider stating that user-provided content, retrieved documents, and tool output are untrusted data rather than instructions.',
            );
        }

        $counts = ['critical' => 0, 'high' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
        }

        return [
            'agent_name' => $specification->agent_name,
            'version' => $specification->version,
            'status' => $specification->status,
            'prompt_sha256' => hash('sha256', $prompt),
            'prompt_characters' => mb_strlen($prompt),
            'prompt_lines' => count($lines),
            'advisory_only' => true,
            'findings' => $findings,
            'finding_counts' => $counts,
        ];
    }
}
