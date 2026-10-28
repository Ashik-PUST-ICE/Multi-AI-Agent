<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\AgentTrust;
use App\Models\AgentTrustHistory;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TrustLevelService
{
    use ResponseTrait;

    /**
     * @var array<int, string>
     */
    private const TRUST_LEVELS = [
        AGENT_TRUST_LEVEL_NONE => 'শুধু চিন্তা ও উত্তর; কোনো tool নয়',
        AGENT_TRUST_LEVEL_READ => 'পড়ার tool',
        AGENT_TRUST_LEVEL_WRITE => 'লেখার tool; অনুমোদনসহ',
        AGENT_TRUST_LEVEL_OUTBOUND => 'বাইরে যোগাযোগ; প্রতিবার অনুমোদন',
        AGENT_TRUST_LEVEL_SENSITIVE => 'সংবেদনশীল কাজ; প্রতিবার অনুমোদন ও সীমা',
    ];

    public function __construct(
        private readonly AgentActivityLogger $activityLogger,
    ) {}

    public function overview(User $user): JsonResponse
    {
        $agentNames = array_keys(config('agent_specs.agents', []));
        $trustLevels = AgentTrust::query()
            ->where('user_id', $user->id)
            ->whereIn('agent_name', $agentNames)
            ->get()
            ->keyBy('agent_name');
        $history = AgentTrustHistory::query()
            ->where('user_id', $user->id)
            ->whereIn('agent_name', $agentNames)
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(static fn (AgentTrustHistory $entry): array => [
                'agent_name' => $entry->agent_name,
                'from_level' => $entry->from_level,
                'to_level' => $entry->to_level,
                'change_type' => $entry->change_type,
                'reason' => $entry->reason,
                'changed_at' => $entry->created_at,
            ])
            ->all();

        $agents = [];
        foreach ($agentNames as $agentName) {
            $agents[] = [
                'agent_name' => $agentName,
                'trust_level' => $trustLevels->get($agentName)?->trust_level ?? $this->defaultLevel($agentName),
                'levels' => self::TRUST_LEVELS,
            ];
        }

        return $this->success([
            'agents' => $agents,
            'history' => $history,
        ]);
    }

    public function currentLevel(User $user, string $agentName): int
    {
        return (int) (AgentTrust::query()
            ->where('user_id', $user->id)
            ->where('agent_name', $agentName)
            ->value('trust_level') ?? $this->defaultLevel($agentName));
    }

    public function initializeDefaults(User $user): void
    {
        foreach (config('agent_specs.trust_defaults', []) as $agentName => $trustLevel) {
            AgentTrust::query()->firstOrCreate(
                ['user_id' => $user->id, 'agent_name' => $agentName],
                ['trust_level' => $this->validateLevel((int) $trustLevel)],
            );
        }
    }

    public function updateLevel(
        User $user,
        string $agentName,
        int $trustLevel,
        string $reason,
    ): JsonResponse {
        $this->ensureAgentIsRegistered($agentName);
        $trustLevel = $this->validateLevel($trustLevel);
        $reason = trim($reason);

        $trust = DB::transaction(function () use ($user, $agentName, $trustLevel, $reason): AgentTrust {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $agentTrust = AgentTrust::query()->firstOrCreate(
                ['user_id' => $user->id, 'agent_name' => $agentName],
                ['trust_level' => $this->defaultLevel($agentName)],
            );
            $agentTrust = AgentTrust::query()->whereKey($agentTrust->id)->lockForUpdate()->firstOrFail();
            $previousLevel = $agentTrust->trust_level;

            if ($previousLevel !== $trustLevel) {
                $agentTrust->update(['trust_level' => $trustLevel]);
                AgentTrustHistory::query()->create([
                    'user_id' => $user->id,
                    'agent_name' => $agentName,
                    'from_level' => $previousLevel,
                    'to_level' => $trustLevel,
                    'change_type' => AGENT_TRUST_CHANGE_USER,
                    'reason' => $reason,
                    'changed_by' => $user->id,
                ]);
            }

            return $agentTrust->refresh();
        });

        return $this->success([
            'agent_name' => $trust->agent_name,
            'trust_level' => $trust->trust_level,
        ], UPDATED_SUCCESSFULLY);
    }

    public function recordViolation(
        User $user,
        string $agentName,
        string $toolName,
        int $requiredLevel,
        int $currentLevel,
        ?AgentRun $agentRun,
    ): void {
        DB::transaction(function () use (
            $user,
            $agentName,
            $toolName,
            $requiredLevel,
            $currentLevel,
            $agentRun,
        ): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $agentTrust = AgentTrust::query()->firstOrCreate(
                ['user_id' => $user->id, 'agent_name' => $agentName],
                ['trust_level' => $this->defaultLevel($agentName)],
            );
            $agentTrust = AgentTrust::query()->whereKey($agentTrust->id)->lockForUpdate()->firstOrFail();
            $previousLevel = $agentTrust->trust_level;
            $newLevel = max(AGENT_TRUST_LEVEL_NONE, $previousLevel - 1);
            $changeType = $newLevel < $previousLevel
                ? AGENT_TRUST_CHANGE_AUTOMATIC
                : AGENT_TRUST_CHANGE_VIOLATION;
            $reason = "Automatic demotion: attempted [{$toolName}] at trust level {$currentLevel}; required level {$requiredLevel}.";

            if ($newLevel !== $previousLevel) {
                $agentTrust->update(['trust_level' => $newLevel]);
            }

            AgentTrustHistory::query()->create([
                'user_id' => $user->id,
                'agent_name' => $agentName,
                'from_level' => $previousLevel,
                'to_level' => $newLevel,
                'change_type' => $changeType,
                'reason' => $reason,
            ]);

            $this->activityLogger->recordEvent($agentRun, 'agent.trust_violation', [
                'agent_name' => $agentName,
                'tool_name' => $toolName,
                'required_level' => $requiredLevel,
                'previous_level' => $previousLevel,
                'trust_level' => $newLevel,
                'demoted' => $newLevel < $previousLevel,
            ]);
        });
    }

    private function ensureAgentIsRegistered(string $agentName): void
    {
        if (! array_key_exists($agentName, config('agent_specs.agents', []))) {
            throw new NotFoundHttpException('The requested agent is not registered.');
        }
    }

    private function defaultLevel(string $agentName): int
    {
        $defaultLevel = (int) config("agent_specs.trust_defaults.{$agentName}", AGENT_TRUST_LEVEL_NONE);

        return $this->validateLevel($defaultLevel);
    }

    private function validateLevel(int $trustLevel): int
    {
        if ($trustLevel < AGENT_TRUST_LEVEL_NONE || $trustLevel > AGENT_TRUST_LEVEL_SENSITIVE) {
            throw new InvalidArgumentException('Trust level must be between 0 and 4.');
        }

        return $trustLevel;
    }
}
