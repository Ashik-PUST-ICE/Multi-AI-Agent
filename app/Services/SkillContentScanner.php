<?php

namespace App\Services;

class SkillContentScanner
{
    public function __construct(
        private readonly CodingProposalSecretScanner $secretScanner,
    ) {}

    /**
     * Return finding labels only; never return matched content.
     *
     * @return list<string>
     */
    public function findings(string $name, string $description, string $instructions): array
    {
        $findings = $this->secretScanner->findingsInFields([
            'name' => $name,
            'description' => $description,
            'instructions' => $instructions,
        ]);
        $patterns = [
            'instruction_override' => '/\b(?:ignore|bypass|override|disable)\s+(?:all\s+)?(?:previous|system|safety|security|access|trust|approval|policy|rules)\b/i',
            'secret_exfiltration' => '/\b(?:reveal|exfiltrate|print|send|leak)\b.{0,60}\b(?:secret|credential|api[\s_-]?key|password)\b/i',
        ];

        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $instructions) === 1) {
                $findings[] = $label;
            }
        }

        return array_values(array_unique($findings));
    }
}
