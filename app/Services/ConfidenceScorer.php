<?php

namespace App\Services;

class ConfidenceScorer
{
    /**
     * @param  list<string>  $researchTexts
     * @param  list<array{severity: string}>  $findings
     * @return array{score: int, source_count: int, rationale: string}
     */
    public function score(int $skepticConfidence, array $researchTexts, array $findings): array
    {
        $sources = [];
        foreach ($researchTexts as $researchText) {
            preg_match_all('~https?://[^\s)\]>]+~i', $researchText, $matches);
            foreach ($matches[0] as $source) {
                $sources[rtrim($source, '.,;')] = true;
            }
        }

        $sourceCount = count($sources);
        $sourceContribution = min(30, $sourceCount * 10);
        $findingPenalty = array_sum(array_map(
            static fn (array $finding): int => match ($finding['severity']) {
                'critical' => 30,
                'high' => 20,
                'medium' => 10,
                default => 3,
            },
            $findings,
        ));
        $score = (int) round(max(0, min(100, ($skepticConfidence * 0.7) + $sourceContribution - $findingPenalty)));

        return [
            'score' => $score,
            'source_count' => $sourceCount,
            'rationale' => sprintf(
                'Calculated from 70%% of the Skeptic assessment (%d), up to 30 points for distinct cited URLs (%d found), minus %d points for reported findings. This is an estimate, not proof.',
                $skepticConfidence,
                $sourceCount,
                $findingPenalty,
            ),
        ];
    }
}
