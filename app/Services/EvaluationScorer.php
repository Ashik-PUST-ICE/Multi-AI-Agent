<?php

namespace App\Services;

class EvaluationScorer
{
    /**
     * Score response/reference token F1 and literal criterion coverage.
     *
     * @param  list<string>  $criteria
     * @return array{score: float, metrics: array{token_f1: float|null, criteria_coverage: float|null, matched_criteria: list<string>}}
     */
    public function score(string $response, ?string $referenceAnswer, array $criteria): array
    {
        $tokenF1 = $referenceAnswer === null || trim($referenceAnswer) === ''
            ? null
            : $this->tokenF1($response, $referenceAnswer);
        $normalizedResponse = mb_strtolower($response);
        $matchedCriteria = array_values(array_filter(
            $criteria,
            static fn (string $criterion): bool => str_contains($normalizedResponse, mb_strtolower($criterion)),
        ));
        $criteriaCoverage = $criteria === []
            ? null
            : round(count($matchedCriteria) / count($criteria) * 100, 2);
        $scores = array_values(array_filter([$tokenF1, $criteriaCoverage], static fn (?float $score): bool => $score !== null));

        return [
            'score' => round(array_sum($scores) / count($scores), 2),
            'metrics' => [
                'token_f1' => $tokenF1,
                'criteria_coverage' => $criteriaCoverage,
                'matched_criteria' => $matchedCriteria,
            ],
        ];
    }

    private function tokenF1(string $response, string $referenceAnswer): float
    {
        $responseTokens = $this->tokens($response);
        $referenceTokens = $this->tokens($referenceAnswer);

        if ($responseTokens === [] && $referenceTokens === []) {
            return 100.0;
        }

        if ($responseTokens === [] || $referenceTokens === []) {
            return 0.0;
        }

        $referenceCounts = array_count_values($referenceTokens);
        $overlap = 0;
        foreach (array_count_values($responseTokens) as $token => $count) {
            $overlap += min($count, $referenceCounts[$token] ?? 0);
        }

        $precision = $overlap / count($responseTokens);
        $recall = $overlap / count($referenceTokens);

        return round(($precision + $recall) === 0.0 ? 0.0 : 2 * $precision * $recall / ($precision + $recall) * 100, 2);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return $matches[0];
    }
}
