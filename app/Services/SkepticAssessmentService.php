<?php

namespace App\Services;

use JsonException;
use RuntimeException;

class SkepticAssessmentService
{
    public function __construct(
        private readonly SuccessCriteriaChecker $criteriaChecker,
        private readonly ConfidenceScorer $confidenceScorer,
    ) {}

    /**
     * @param  list<string>  $criteria
     * @param  array<string, mixed>  $agentOutputs
     * @return array<string, mixed>
     */
    public function assess(string $response, array $criteria, array $agentOutputs): array
    {
        try {
            $report = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The Skeptic response was not valid JSON.', previous: $exception);
        }

        if (! is_array($report)
            || ! is_int($report['confidence'] ?? null)
            || $report['confidence'] < 0
            || $report['confidence'] > 100
            || ! is_array($report['findings'] ?? null)
            || ! is_array($report['criteria'] ?? null)) {
            throw new RuntimeException('The Skeptic response did not match the assessment contract.');
        }

        $findings = [];
        foreach ($report['findings'] as $finding) {
            if (! is_array($finding)
                || ! in_array($finding['severity'] ?? null, ['low', 'medium', 'high', 'critical'], true)
                || ! is_string($finding['claim'] ?? null)
                || ! is_string($finding['reason'] ?? null)
                || ! is_string($finding['evidence'] ?? null)) {
                throw new RuntimeException('The Skeptic response contains an invalid finding.');
            }

            $findings[] = [
                'severity' => $finding['severity'],
                'claim' => $finding['claim'],
                'reason' => $finding['reason'],
                'evidence' => $finding['evidence'],
            ];
        }

        $criteriaAssessment = [];
        foreach ($report['criteria'] as $criterionAssessment) {
            if (! is_array($criterionAssessment)
                || ! is_string($criterionAssessment['criterion'] ?? null)
                || ! in_array($criterionAssessment['status'] ?? null, ['met', 'unmet', 'uncertain'], true)
                || ! is_string($criterionAssessment['evidence'] ?? null)) {
                throw new RuntimeException('The Skeptic response contains an invalid criteria assessment.');
            }

            $criteriaAssessment[] = [
                'criterion' => $criterionAssessment['criterion'],
                'status' => $criterionAssessment['status'],
                'evidence' => $criterionAssessment['evidence'],
            ];
        }

        $criteriaResult = $this->criteriaChecker->check($criteria, $criteriaAssessment, $findings);
        $researchTexts = [];
        if (is_string($agentOutputs['Research']['text'] ?? null)) {
            $researchTexts[] = $agentOutputs['Research']['text'];
        }
        $confidence = $this->confidenceScorer->score($report['confidence'], $researchTexts, $findings);
        $approved = ($report['approved'] ?? false) === true && $criteriaResult['passed'];

        return [
            'approved' => $approved,
            'criteria_passed' => $criteriaResult['passed'],
            'checklist' => $criteriaResult['checklist'],
            'findings' => $findings,
            'confidence' => $confidence,
        ];
    }
}
