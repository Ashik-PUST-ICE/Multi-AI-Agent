<?php

namespace App\Services;

use App\Models\User;

class SkillMatcher
{
    /**
     * Match only curated built-in skills; matching does not grant capabilities.
     *
     * @param  array<string, mixed>  $taskBrief
     * @return list<array<string, mixed>>
     */
    public function match(string $prompt, array $taskBrief, ?User $user = null): array
    {
        $catalog = config('skills.built_in', []);
        if ($user !== null) {
            foreach (app(SkillRegistryService::class)->activeFor($user) as $skill) {
                $version = $skill->versions->first(
                    fn ($candidate): bool => $candidate->version === $skill->current_version,
                );
                if ($version === null) {
                    continue;
                }

                $keywords = preg_split(
                    '/[^\p{L}\p{N}]+/u',
                    mb_strtolower($skill->name.' '.$skill->description),
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                );
                $catalog[] = [
                    'name' => $skill->name,
                    'version' => (string) $version->version,
                    'user_skill_id' => (int) $skill->id,
                    'user_skill_version_id' => (int) $version->id,
                    'description' => $skill->description,
                    'intents' => [],
                    'keywords' => array_values(array_filter(
                        $keywords ?: [],
                        static fn (string $word): bool => mb_strlen($word) >= 3,
                    )),
                    'minimum_trust_level' => AGENT_TRUST_LEVEL_NONE,
                    'required_tools' => [],
                    'model_capabilities' => [],
                    'instructions' => $version->instructions,
                ];
            }
        }
        $intent = $taskBrief['intent'] ?? 'question';
        $prompt = mb_strtolower($prompt);
        $matches = [];

        foreach ($catalog as $skill) {
            if (! is_array($skill)
                || ! is_string($skill['name'] ?? null)
                || ! is_string($skill['description'] ?? null)
                || ! is_string($skill['instructions'] ?? null)) {
                throw new \RuntimeException('A built-in skill definition is invalid.');
            }
            $reasons = [];
            if (in_array($intent, $skill['intents'] ?? [], true)) {
                $reasons[] = "intent:{$intent}";
            }
            foreach ($skill['keywords'] ?? [] as $keyword) {
                if (is_string($keyword) && str_contains($prompt, mb_strtolower($keyword))) {
                    $reasons[] = "keyword:{$keyword}";
                    break;
                }
            }
            if ($reasons === []) {
                continue;
            }

            $matches[] = [
                'name' => $skill['name'],
                'version' => $skill['version'],
                'description' => $skill['description'],
                'match_reason' => implode(', ', $reasons),
                'minimum_trust_level' => (int) ($skill['minimum_trust_level'] ?? AGENT_TRUST_LEVEL_NONE),
                'required_tools' => $skill['required_tools'] ?? [],
                'model_capabilities' => $skill['model_capabilities'] ?? [],
                'instructions' => $skill['instructions'],
                ...(isset($skill['user_skill_id'], $skill['user_skill_version_id']) ? [
                    'user_skill_id' => (int) $skill['user_skill_id'],
                    'user_skill_version_id' => (int) $skill['user_skill_version_id'],
                ] : []),
            ];
        }

        usort($matches, static function (array $left, array $right): int {
            $leftIntent = str_starts_with($left['match_reason'], 'intent:') ? 1 : 0;
            $rightIntent = str_starts_with($right['match_reason'], 'intent:') ? 1 : 0;

            return $rightIntent <=> $leftIntent ?: strcmp($left['name'], $right['name']);
        });

        return array_slice($matches, 0, max(0, (int) config('skills.maximum_matches', 2)));
    }

    /**
     * @param  list<array<string, mixed>>  $skills
     * @return list<array{name: string, version: string, description: string, match_reason: string}>
     */
    public function summaries(array $skills): array
    {
        return array_map(static function (array $skill): array {
            $summary = [
                'name' => $skill['name'],
                'version' => $skill['version'],
                'description' => $skill['description'],
                'match_reason' => $skill['match_reason'],
            ];
            if (isset($skill['user_skill_id'], $skill['user_skill_version_id'])) {
                $summary['user_skill_id'] = (int) $skill['user_skill_id'];
                $summary['user_skill_version_id'] = (int) $skill['user_skill_version_id'];
            }

            return $summary;
        }, $skills);
    }
}
