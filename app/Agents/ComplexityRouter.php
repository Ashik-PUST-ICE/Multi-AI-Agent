<?php

namespace App\Agents;

final class ComplexityRouter
{
    /**
     * @return array{strategy: string, agent_sequence: list<string>, reason: string}
     */
    public function route(string $prompt): array
    {
        $normalizedPrompt = mb_strtolower($prompt);
        $requestsMultipleRoles = preg_match(
            '/\b(research and write|research then write|compare and recommend|analyze and review|'
                .'independent review|multiple agents|multi-agent)\b|একাধিক এজেন্ট|গবেষণা করে লিখ|'
                .'গবেষণা এবং লিখ|তুলনা করে সুপারিশ|বিশ্লেষণ এবং পর্যালোচনা/u',
            $normalizedPrompt,
        ) === 1;

        if ($requestsMultipleRoles) {
            return [
                'strategy' => 'multi_agent',
                'agent_sequence' => ['Orchestrator', 'Research', 'Writer', 'Reviewer'],
                'reason' => 'The request explicitly combines research or analysis with synthesis or independent review.',
            ];
        }

        $singleAgent = $this->singleAgentForPrompt($normalizedPrompt);

        return [
            'strategy' => 'single_agent',
            'agent_sequence' => [$singleAgent],
            'reason' => "The request does not show a clear benefit from splitting; routing directly to {$singleAgent}.",
        ];
    }

    private function singleAgentForPrompt(string $prompt): string
    {
        if (preg_match('/\b(review|critique|proofread|check|reviewer)\b|পর্যালোচনা|যাচাই|সম্পাদনা/u', $prompt) === 1) {
            return 'Reviewer';
        }

        if (preg_match('/\b(research|investigate|find facts|sources)\b|গবেষণা|তথ্য খুঁজ|উৎসসহ/u', $prompt) === 1) {
            return 'Research';
        }

        return 'Writer';
    }
}
