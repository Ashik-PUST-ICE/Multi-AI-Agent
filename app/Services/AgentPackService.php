<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\AgentPack;
use App\Models\User;
use App\Models\UserAgentPack;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AgentPackService
{
    use ResponseTrait;

    /**
     * @var list<string>
     */
    private const CORE_AGENTS = ['Orchestrator', 'Research', 'Writer', 'Reviewer'];

    public function __construct(
        private readonly AgentRegistry $agentRegistry,
    ) {}

    public function index(User $user): JsonResponse
    {
        $this->seedBuiltInPacks();
        $activePacks = AgentPack::query()
            ->where('status', AGENT_PACK_STATUS_ACTIVE)
            ->orderBy('name')
            ->get()
            ->unique('slug');
        $installations = $user->agentPackInstallations()
            ->with('pack')
            ->get()
            ->keyBy(static fn (UserAgentPack $installation): int => $installation->pack->id);

        return $this->success([
            'can_manage' => $user->can('agent_packs.manage'),
            'packs' => $activePacks->map(function (AgentPack $pack) use ($installations): array {
                $installation = $installations->get($pack->id);

                return [
                    ...$this->packData($pack),
                    'installed' => $installation !== null,
                    'enabled' => $installation?->status === USER_AGENT_PACK_STATUS_ENABLED,
                ];
            })->values()->all(),
        ]);
    }

    public function install(User $user, string $slug, bool $enabled): JsonResponse
    {
        $this->seedBuiltInPacks();
        $pack = AgentPack::query()
            ->where('slug', $slug)
            ->where('status', AGENT_PACK_STATUS_ACTIVE)
            ->orderByDesc('version')
            ->firstOrFail();

        $installation = UserAgentPack::query()->updateOrCreate(
            ['user_id' => $user->id, 'agent_pack_id' => $pack->id],
            [
                'tenant_id' => $user->tenant_id,
                'status' => $enabled ? USER_AGENT_PACK_STATUS_ENABLED : USER_AGENT_PACK_STATUS_DISABLED,
            ],
        );

        return $this->success([
            'slug' => $pack->slug,
            'version' => $pack->version,
            'installed' => true,
            'enabled' => $installation->status === USER_AGENT_PACK_STATUS_ENABLED,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enabledPack(User $user, ?string $slug): ?array
    {
        if ($slug === null) {
            return null;
        }

        $this->seedBuiltInPacks();
        $pack = AgentPack::query()
            ->where('slug', $slug)
            ->where('status', AGENT_PACK_STATUS_ACTIVE)
            ->orderByDesc('version')
            ->first();

        if ($pack === null) {
            throw new ModelNotFoundException('The requested agent pack is not available.');
        }
        $this->assertDefinitionIntegrity($pack);

        $installed = UserAgentPack::query()
            ->where('user_id', $user->id)
            ->where('agent_pack_id', $pack->id)
            ->where('status', USER_AGENT_PACK_STATUS_ENABLED)
            ->exists();
        if (! $installed) {
            throw ValidationException::withMessages([
                'agent_pack' => 'Install and enable this pack in Settings before using it.',
            ]);
        }

        return $this->packData($pack);
    }

    public function adminIndex(): JsonResponse
    {
        $this->seedBuiltInPacks();
        $packs = AgentPack::query()
            ->orderBy('slug')
            ->orderByDesc('version')
            ->get()
            ->map(fn (AgentPack $pack): array => $this->packData($pack))
            ->all();

        return $this->success(['packs' => $packs]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): JsonResponse
    {
        $this->seedBuiltInPacks();
        if (AgentPack::query()->where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'This pack slug already exists.']);
        }

        $definition = $this->buildDefinition($data);
        $pack = AgentPack::query()->create([
            'slug' => $data['slug'],
            'version' => 1,
            'name' => $data['name'],
            'description' => $data['description'],
            'definition' => $definition,
            'definition_sha256' => $this->definitionHash($definition),
            'status' => AGENT_PACK_STATUS_DRAFT,
            'is_builtin' => false,
            'created_by' => $user->id,
        ]);

        return $this->success(['pack' => $this->packData($pack)], CREATED_SUCCESSFULLY, 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createVersion(User $user, string $slug, array $data = []): JsonResponse
    {
        $pack = DB::transaction(function () use ($user, $slug, $data): AgentPack {
            $latestPack = AgentPack::query()
                ->where('slug', $slug)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCustomPack($latestPack);
            $attributes = [
                'name' => $data['name'] ?? $latestPack->name,
                'description' => $data['description'] ?? $latestPack->description,
                'agents' => $data['agents'] ?? $latestPack->definition['agents'],
                'guidance' => $data['guidance'] ?? collect($latestPack->definition['profiles'])
                    ->mapWithKeys(static fn (array $profile, string $agent): array => [$agent => $profile['guidance']])
                    ->all(),
                'examples' => $data['examples'] ?? $latestPack->definition['examples'],
            ];
            $definition = $this->buildDefinition($attributes);

            return AgentPack::query()->create([
                'slug' => $slug,
                'version' => $latestPack->version + 1,
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'definition' => $definition,
                'definition_sha256' => $this->definitionHash($definition),
                'status' => AGENT_PACK_STATUS_DRAFT,
                'is_builtin' => false,
                'created_by' => $user->id,
            ]);
        });

        return $this->success(['pack' => $this->packData($pack)], CREATED_SUCCESSFULLY, 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(string $slug, int $version, array $data): JsonResponse
    {
        $pack = DB::transaction(function () use ($slug, $version, $data): AgentPack {
            $pack = AgentPack::query()
                ->where('slug', $slug)
                ->where('version', $version)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCustomPack($pack);
            if ($pack->status !== AGENT_PACK_STATUS_DRAFT) {
                throw new ConflictHttpException('Only draft packs can be edited.');
            }

            $guidance = collect($pack->definition['profiles'])
                ->mapWithKeys(static fn (array $profile, string $agent): array => [$agent => $profile['guidance']])
                ->all();
            $attributes = [
                'name' => $data['name'] ?? $pack->name,
                'description' => $data['description'] ?? $pack->description,
                'agents' => $data['agents'] ?? $pack->definition['agents'],
                'guidance' => $data['guidance'] ?? $guidance,
                'examples' => $data['examples'] ?? $pack->definition['examples'],
            ];
            $definition = $this->buildDefinition($attributes);
            $pack->update([
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'definition' => $definition,
                'definition_sha256' => $this->definitionHash($definition),
            ]);

            return $pack->refresh();
        });

        return $this->success(['pack' => $this->packData($pack)], UPDATED_SUCCESSFULLY);
    }

    public function transition(string $slug, int $version, int $status): JsonResponse
    {
        $pack = DB::transaction(function () use ($slug, $version, $status): AgentPack {
            $pack = AgentPack::query()
                ->where('slug', $slug)
                ->where('version', $version)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCustomPack($pack);
            if ($pack->status !== AGENT_PACK_STATUS_DRAFT
                || ! in_array($status, [AGENT_PACK_STATUS_ACTIVE, AGENT_PACK_STATUS_RETIRED], true)) {
                throw new ConflictHttpException('Only a draft pack can be activated.');
            }

            if ($status === AGENT_PACK_STATUS_ACTIVE) {
                AgentPack::query()
                    ->where('slug', $slug)
                    ->where('status', AGENT_PACK_STATUS_ACTIVE)
                    ->update(['status' => AGENT_PACK_STATUS_RETIRED]);
            }
            $pack->update(['status' => $status]);

            return $pack->refresh();
        });

        return $this->success(['pack' => $this->packData($pack)], UPDATED_SUCCESSFULLY);
    }

    public function rollback(User $user, string $slug, int $version): JsonResponse
    {
        $source = AgentPack::query()
            ->where('slug', $slug)
            ->where('version', $version)
            ->firstOrFail();
        $this->assertCustomPack($source);

        return $this->createVersion($user, $slug, [
            'name' => $source->name,
            'description' => $source->description,
            'agents' => $source->definition['agents'],
            'guidance' => collect($source->definition['profiles'])
                ->mapWithKeys(static fn (array $profile, string $agent): array => [$agent => $profile['guidance']])
                ->all(),
            'examples' => $source->definition['examples'],
        ]);
    }

    private function seedBuiltInPacks(): void
    {
        foreach (config('agent_packs.packs', []) as $slug => $attributes) {
            $definition = $this->buildDefinition($attributes);
            AgentPack::query()->firstOrCreate(
                ['slug' => $slug, 'version' => 1],
                [
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'definition' => $definition,
                    'definition_sha256' => $this->definitionHash($definition),
                    'status' => AGENT_PACK_STATUS_ACTIVE,
                    'is_builtin' => true,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function buildDefinition(array $attributes): array
    {
        $agents = array_values(array_unique($attributes['agents']));
        if ($agents === [] || array_diff($agents, self::CORE_AGENTS) !== []) {
            throw ValidationException::withMessages([
                'agents' => 'Packs may only use the four supported core agents.',
            ]);
        }

        $guidance = $attributes['guidance'] ?? [];
        if (array_diff(array_keys($guidance), $agents) !== []) {
            throw ValidationException::withMessages([
                'guidance' => 'Pack guidance may only target agents listed in the pack.',
            ]);
        }

        $profiles = [];
        foreach ($agents as $agentName) {
            $specification = $this->agentRegistry->activeSpec($agentName);
            $profileGuidance = trim((string) ($guidance[$agentName] ?? ''));
            if ($profileGuidance === '') {
                $profileGuidance = $specification['role'];
            }
            $profiles[$agentName] = [
                'guidance' => $profileGuidance,
                'allowed_tools' => array_values($specification['allowed_tools'] ?? []),
                'minimum_trust_level' => (int) ($specification['minimum_trust_level'] ?? AGENT_TRUST_LEVEL_NONE),
            ];
        }

        return [
            'agents' => $agents,
            'profiles' => $profiles,
            'examples' => array_values($attributes['examples']),
        ];
    }

    private function assertCustomPack(AgentPack $pack): void
    {
        if ($pack->is_builtin) {
            throw new ConflictHttpException('Built-in packs are read-only.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function packData(AgentPack $pack): array
    {
        $this->assertDefinitionIntegrity($pack);

        return [
            'slug' => $pack->slug,
            'version' => $pack->version,
            'name' => $pack->name,
            'description' => $pack->description,
            'status' => $pack->status,
            'is_builtin' => $pack->is_builtin,
            'agents' => $pack->definition['agents'] ?? [],
            'profiles' => $pack->definition['profiles'] ?? [],
            'examples' => $pack->definition['examples'] ?? [],
        ];
    }

    private function definitionHash(array $definition): string
    {
        return hash(
            'sha256',
            json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function assertDefinitionIntegrity(AgentPack $pack): void
    {
        $expectedHash = $this->definitionHash($pack->definition ?? []);
        if (! is_string($pack->definition_sha256) || ! hash_equals($pack->definition_sha256, $expectedHash)) {
            throw new RuntimeException("Agent pack [{$pack->slug}] failed its definition integrity check.");
        }
    }
}
