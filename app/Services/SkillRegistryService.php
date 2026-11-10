<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSkill;
use App\Models\UserSkillVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SkillRegistryService
{
    public function __construct(
        private readonly SkillContentScanner $scanner,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function index(User $user): array
    {
        $builtIn = array_map(static fn (array $skill): array => [
            'id' => null,
            'name' => $skill['name'],
            'version' => $skill['version'],
            'description' => $skill['description'],
            'owner' => 'built_in',
            'visibility' => 'built_in',
            'source' => 'built_in',
            'status' => 'active',
            'minimum_trust_level' => $skill['minimum_trust_level'],
            'required_tools' => $skill['required_tools'],
            'model_capabilities' => $skill['model_capabilities'],
            'scripts_enabled' => false,
            'can_manage' => false,
        ], config('skills.built_in', []));

        $userSkills = UserSkill::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhere(function ($shared): void {
                        $shared->where('visibility', 'tenant')
                            ->where('status', 'active');
                    });
            })
            ->with(['versions' => fn ($query) => $query->orderByDesc('version')])
            ->orderBy('name')
            ->get()
            ->map(fn (UserSkill $skill): array => $this->skillData(
                $skill,
                $skill->user_id === $user->id,
                false,
            ))
            ->all();

        return [...$builtIn, ...$userSkills];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $user, array $data): array
    {
        $source = $data['source'] ?? 'authored';
        $scannerFindings = $this->scanner->findings(
            $data['name'],
            $data['description'],
            $data['instructions'],
        );

        $skill = DB::transaction(function () use ($data, $scannerFindings, $source, $user): UserSkill {
            $skill = UserSkill::query()->create([
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'name' => $data['name'],
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(8)),
                'description' => $data['description'],
                'visibility' => $data['visibility'] ?? 'personal',
                'source' => $source,
                'publisher_claim' => $source === 'external_upload' ? ($data['publisher_claim'] ?? null) : null,
                'source_reference' => $source === 'external_upload' ? ($data['source_reference'] ?? null) : null,
                'status' => 'pending_review',
                'current_version' => 1,
            ]);
            $skill->versions()->create([
                'version' => 1,
                'instructions' => $data['instructions'],
                'content_sha256' => $this->contentHash($data['name'], $data['description'], $data['instructions']),
                'scan_findings' => $scannerFindings,
                'verification_status' => 'pending_review',
            ]);

            return $skill;
        });

        return $this->skillData($skill->load('versions'), true, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $skillId): array
    {
        $skill = $this->visibleSkills($user)
            ->with(['versions' => fn ($query) => $query->orderByDesc('version')])
            ->findOrFail($skillId);

        return $this->skillData($skill, $skill->user_id === $user->id, true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $skillId, array $data): array
    {
        $skill = $this->ownedSkill($user, $skillId);

        return DB::transaction(function () use ($data, $skill): array {
            $skill = UserSkill::query()->whereKey($skill->id)->lockForUpdate()->firstOrFail();
            $currentVersion = $skill->versions()->where('version', $skill->current_version)->firstOrFail();
            if (! hash_equals($currentVersion->content_sha256, $data['expected_hash'])) {
                throw ValidationException::withMessages([
                    'expected_hash' => 'This skill changed since it was loaded. Reload it before editing.',
                ]);
            }

            $nextVersion = $skill->current_version + 1;
            $findings = $this->scanner->findings($data['name'], $data['description'], $data['instructions']);
            $skill->update([
                'name' => $data['name'],
                'description' => $data['description'],
                'visibility' => $data['visibility'],
                'current_version' => $nextVersion,
                'status' => 'pending_review',
            ]);
            $skill->versions()->create([
                'version' => $nextVersion,
                'instructions' => $data['instructions'],
                'content_sha256' => $this->contentHash($data['name'], $data['description'], $data['instructions']),
                'scan_findings' => $findings,
                'verification_status' => 'pending_review',
            ]);

            return $this->skillData($skill->load('versions'), true, true);
        });
    }

    /**
     * @param  array{confirm: bool, content_hash: string}  $data
     * @return array<string, mixed>
     */
    public function verify(User $user, int $skillId, array $data): array
    {
        $skill = $this->ownedSkill($user, $skillId);

        return DB::transaction(function () use ($data, $skill, $user): array {
            $skill = UserSkill::query()->whereKey($skill->id)->lockForUpdate()->firstOrFail();
            $version = $skill->versions()
                ->where('version', $skill->current_version)
                ->lockForUpdate()
                ->firstOrFail();
            if (! hash_equals($version->content_sha256, $data['content_hash'])) {
                throw ValidationException::withMessages([
                    'content_hash' => 'The skill content changed. Review the current version before verifying it.',
                ]);
            }

            $findings = $this->scanner->findings($skill->name, $skill->description, $version->instructions);
            if ($findings !== []) {
                $version->update(['scan_findings' => $findings]);
                throw ValidationException::withMessages([
                    'content_hash' => 'The skill has safety scan findings and cannot be verified.',
                ]);
            }

            $version->update([
                'verification_status' => 'verified',
                'verified_by' => $user->id,
                'verified_at' => now(),
                'scan_findings' => [],
            ]);
            $skill->update(['status' => 'active']);

            return $this->skillData($skill->load('versions'), true, true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setEnabled(User $user, int $skillId, bool $enabled): array
    {
        $skill = $this->ownedSkill($user, $skillId);
        if ($enabled) {
            $version = $skill->versions()->where('version', $skill->current_version)->firstOrFail();
            if ($version->verification_status !== 'verified') {
                throw ValidationException::withMessages([
                    'enabled' => 'Verify the current skill version before enabling it.',
                ]);
            }
        }

        $skill->update(['status' => $enabled ? 'active' : 'disabled']);

        return $this->skillData($skill->load('versions'), true, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(User $user, int $skillId, int $versionId): array
    {
        $skill = $this->ownedSkill($user, $skillId);

        return DB::transaction(function () use ($skill, $versionId): array {
            $skill = UserSkill::query()->whereKey($skill->id)->lockForUpdate()->firstOrFail();
            $sourceVersion = $skill->versions()->whereKey($versionId)->firstOrFail();
            $nextVersion = (int) $skill->current_version + 1;
            $findings = $this->scanner->findings(
                $skill->name,
                $skill->description,
                $sourceVersion->instructions,
            );
            $skill->update([
                'current_version' => $nextVersion,
                'status' => 'pending_review',
            ]);
            $skill->versions()->create([
                'version' => $nextVersion,
                'instructions' => $sourceVersion->instructions,
                'content_sha256' => $this->contentHash($skill->name, $skill->description, $sourceVersion->instructions),
                'scan_findings' => $findings,
                'verification_status' => 'pending_review',
            ]);

            return $this->skillData($skill->load('versions'), true, true);
        });
    }

    /**
     * @return list<UserSkill>
     */
    public function activeFor(User $user): array
    {
        return UserSkill::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhere('visibility', 'tenant');
            })
            ->with(['versions' => fn ($query) => $query->where('verification_status', 'verified')])
            ->get()
            ->filter(function (UserSkill $skill): bool {
                return $skill->versions->contains(
                    fn (UserSkillVersion $version): bool => $version->version === $skill->current_version,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function skillData(UserSkill $skill, bool $canManage, bool $includeInstructions): array
    {
        $current = $skill->versions->first(
            fn (UserSkillVersion $version): bool => $version->version === $skill->current_version,
        );

        $data = [
            'id' => (int) $skill->id,
            'name' => $skill->name,
            'slug' => $skill->slug,
            'version' => $current?->version,
            'description' => $skill->description,
            'owner' => $canManage ? 'you' : 'workspace',
            'visibility' => $skill->visibility,
            'source' => $skill->source,
            'publisher_claim' => $skill->publisher_claim,
            'source_reference' => $skill->source_reference,
            'status' => $skill->status,
            'verification_status' => $current?->verification_status,
            'content_hash' => $current?->content_sha256,
            'scan_findings' => $current?->scan_findings ?? [],
            'minimum_trust_level' => AGENT_TRUST_LEVEL_NONE,
            'required_tools' => [],
            'model_capabilities' => [],
            'scripts_enabled' => false,
            'can_manage' => $canManage,
            'versions' => $skill->versions
                ->map(static fn (UserSkillVersion $version): array => [
                    'id' => (int) $version->id,
                    'version' => $version->version,
                    'content_hash' => $version->content_sha256,
                    'verification_status' => $version->verification_status,
                    'verified_at' => $version->verified_at?->toISOString(),
                    'scan_findings' => $version->scan_findings ?? [],
                    'current' => $version->version === $skill->current_version,
                ])
                ->values()
                ->all(),
        ];
        if ($includeInstructions && $canManage) {
            $data['instructions'] = $current?->instructions;
        }

        return $data;
    }

    private function ownedSkill(User $user, int $skillId): UserSkill
    {
        return UserSkill::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->findOrFail($skillId);
    }

    private function visibleSkills(User $user): Builder
    {
        return UserSkill::query()
            ->where('tenant_id', $user->tenant_id)
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhere(function ($shared): void {
                        $shared->where('visibility', 'tenant')
                            ->where('status', 'active');
                    });
            });
    }

    private function contentHash(string $name, string $description, string $instructions): string
    {
        return hash('sha256', json_encode([
            'name' => $name,
            'description' => $description,
            'instructions' => $instructions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
