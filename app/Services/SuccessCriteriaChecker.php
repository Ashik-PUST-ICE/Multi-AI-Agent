<?php

namespace App\Services;

class SuccessCriteriaChecker
{
    /**
     * @param  list<string>  $criteria
     * @param  list<array{criterion: string, status: string, evidence: string}>  $assessment
     * @param  list<array{severity: string}>  $findings
     * @return array{passed: bool, checklist: list<array{criterion: string, status: string, evidence: string}>}
     */
    public function check(array $criteria, array $assessment, array $findings): array
    {
        $expectedCriteria = $criteria !== []
            ? $criteria
            : ['The response addresses the task and discloses material uncertainty.'];
        $assessmentByCriterion = [];

        if (count($assessment) !== count($expectedCriteria)) {
            $assessment = [];
        }

        foreach ($assessment as $item) {
            if (! in_array($item['criterion'], $expectedCriteria, true)
                || array_key_exists($item['criterion'], $assessmentByCriterion)) {
                $assessmentByCriterion = [];
                break;
            }
            $assessmentByCriterion[$item['criterion']] = $item;
        }

        $checklist = [];
        foreach ($expectedCriteria as $criterion) {
            $item = $assessmentByCriterion[$criterion] ?? null;
            $status = $item['status'] ?? 'uncertain';
            $evidence = $item['evidence'] ?? '';

            if ($status === 'met' && trim($evidence) === '') {
                $status = 'uncertain';
            }

            $checklist[] = [
                'criterion' => $criterion,
                'status' => $status,
                'evidence' => $evidence,
            ];
        }

        $hasBlockingFinding = collect($findings)->contains(
            static fn (array $finding): bool => in_array($finding['severity'], ['high', 'critical'], true),
        );
        $passed = ! $hasBlockingFinding
            && collect($checklist)->every(static fn (array $item): bool => $item['status'] === 'met');

        return ['passed' => $passed, 'checklist' => $checklist];
    }
}
