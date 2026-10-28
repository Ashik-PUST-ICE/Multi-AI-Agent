<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Agents\SchemaValidator;
use App\Models\AgentSpec;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AgentSpecService
{
    use ResponseTrait;

    private const MUTABLE_FIELDS = [
        'role',
        'system_prompt',
        'input_schema',
        'output_schema',
        'allowed_tools',
        'minimum_trust_level',
        'default_model',
        'fallback_model',
        'max_steps',
        'max_tokens',
        'timeout_seconds',
        'stop_conditions',
        'success_criteria',
    ];

    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly SchemaValidator $schemaValidator,
    ) {}

    public function index(): JsonResponse
    {
        $agentSpecs = AgentSpec::query()
            ->orderBy('agent_name')
            ->orderByDesc('version')
            ->get()
            ->map(fn (AgentSpec $agentSpec): array => $this->specificationData($agentSpec))
            ->all();

        return $this->success(['agent_specs' => $agentSpecs]);
    }

    public function show(string $agentName, int $version): JsonResponse
    {
        $agentSpec = $this->findSpec($agentName, $version);

        return $this->success(['agent_spec' => $this->specificationData($agentSpec)]);
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function createVersion(User $user, string $agentName, array $validatedData): JsonResponse
    {
        $agentSpec = DB::transaction(function () use ($user, $agentName, $validatedData): AgentSpec {
            $this->lockVersions($agentName);
            $latestVersion = AgentSpec::query()
                ->where('agent_name', $agentName)
                ->orderByDesc('version')
                ->first();

            $sourceSpecification = $latestVersion?->toArray()
                ?? $this->agentRegistry->activeSpec($agentName);
            $nextVersion = ($latestVersion?->version ?? 1) + 1;
            $draftAttributes = $this->editableAttributes($sourceSpecification);
            $draftAttributes = [...$draftAttributes, ...$this->editableAttributes($validatedData)];
            $this->validateSpecification($agentName, $draftAttributes);

            return AgentSpec::query()->create([
                'agent_name' => $agentName,
                'version' => $nextVersion,
                'status' => AGENT_SPEC_STATUS_DRAFT,
                'created_by' => $user->id,
                ...$draftAttributes,
            ]);
        });

        return $this->success(
            ['agent_spec' => $this->specificationData($agentSpec)],
            CREATED_SUCCESSFULLY,
            201,
        );
    }

    /**
     * @param  array<string, mixed>  $validatedData
     */
    public function updateDraft(
        User $user,
        string $agentName,
        int $version,
        array $validatedData,
    ): JsonResponse {
        $agentSpec = DB::transaction(function () use ($user, $agentName, $version, $validatedData): AgentSpec {
            $agentSpec = $this->findSpecForUpdate($agentName, $version);

            if ($agentSpec->status !== AGENT_SPEC_STATUS_DRAFT) {
                throw new ConflictHttpException('Only draft specifications can be edited.');
            }

            $updatedAttributes = [
                ...$this->editableAttributes($agentSpec->toArray()),
                ...$this->editableAttributes($validatedData),
            ];
            $this->validateSpecification($agentName, $updatedAttributes);

            $agentSpec->fill($updatedAttributes);
            $agentSpec->created_by = $user->id;
            $agentSpec->save();

            return $agentSpec;
        });

        return $this->success(
            ['agent_spec' => $this->specificationData($agentSpec)],
            UPDATED_SUCCESSFULLY,
        );
    }

    public function transition(string $agentName, int $version, int $newStatus): JsonResponse
    {
        $agentSpec = DB::transaction(function () use ($agentName, $version, $newStatus): AgentSpec {
            $this->lockVersions($agentName);
            $agentSpec = $this->findSpecForUpdate($agentName, $version);
            $this->assertAllowedTransition($agentName, $agentSpec, $newStatus);

            if (in_array($newStatus, [AGENT_SPEC_STATUS_TEST, AGENT_SPEC_STATUS_ACTIVE], true)) {
                $this->validateSpecification($agentName, $agentSpec->toArray());
            }

            if ($newStatus === AGENT_SPEC_STATUS_ACTIVE) {
                AgentSpec::query()
                    ->where('agent_name', $agentName)
                    ->where('status', AGENT_SPEC_STATUS_ACTIVE)
                    ->whereKeyNot($agentSpec->id)
                    ->update(['status' => AGENT_SPEC_STATUS_RETIRED]);
            }

            $agentSpec->status = $newStatus;
            $agentSpec->save();

            return $agentSpec;
        });

        return $this->success(
            ['agent_spec' => $this->specificationData($agentSpec)],
            UPDATED_SUCCESSFULLY,
        );
    }

    public function rollback(User $user, string $agentName, int $version): JsonResponse
    {
        $agentSpec = DB::transaction(function () use ($user, $agentName, $version): AgentSpec {
            $this->lockVersions($agentName);
            $sourceSpecification = $this->findSpecForUpdate($agentName, $version);
            $latestVersion = AgentSpec::query()
                ->where('agent_name', $agentName)
                ->max('version');
            $rollbackAttributes = $this->editableAttributes($sourceSpecification->toArray());
            $this->validateSpecification($agentName, $rollbackAttributes);

            return AgentSpec::query()->create([
                'agent_name' => $agentName,
                'version' => ((int) $latestVersion) + 1,
                'status' => AGENT_SPEC_STATUS_DRAFT,
                'created_by' => $user->id,
                ...$rollbackAttributes,
            ]);
        });

        return $this->success(
            ['agent_spec' => $this->specificationData($agentSpec)],
            CREATED_SUCCESSFULLY,
            201,
        );
    }

    private function findSpec(string $agentName, int $version): AgentSpec
    {
        return AgentSpec::query()
            ->where('agent_name', $agentName)
            ->where('version', $version)
            ->firstOrFail();
    }

    private function findSpecForUpdate(string $agentName, int $version): AgentSpec
    {
        return AgentSpec::query()
            ->where('agent_name', $agentName)
            ->where('version', $version)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockVersions(string $agentName): void
    {
        AgentSpec::query()
            ->where('agent_name', $agentName)
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * @param  array<string, mixed>  $specification
     * @return array<string, mixed>
     */
    private function editableAttributes(array $specification): array
    {
        return array_intersect_key($specification, array_flip(self::MUTABLE_FIELDS));
    }

    /**
     * @param  array<string, mixed>  $specification
     */
    private function validateSpecification(string $agentName, array $specification): void
    {
        $defaultDefinitions = config('agent_specs.agents', []);
        $defaultDefinition = $defaultDefinitions[$agentName] ?? null;

        if (! is_array($defaultDefinition)) {
            throw (new ModelNotFoundException)->setModel(AgentSpec::class);
        }

        foreach (['input_schema', 'output_schema'] as $schemaField) {
            $schema = $specification[$schemaField] ?? null;

            if (! is_array($schema) || ($schema['type'] ?? null) !== 'object') {
                throw ValidationException::withMessages([
                    $schemaField => "The [{$schemaField}] must be a JSON object schema.",
                ]);
            }

            $schemaErrors = $this->schemaValidator->definitionErrors($schema);
            if ($schemaErrors !== []) {
                throw ValidationException::withMessages([
                    $schemaField => "The [{$schemaField}] schema structure is invalid: ".implode('; ', $schemaErrors),
                ]);
            }
        }

        $inputSchema = $specification['input_schema'];
        $inputProperties = $inputSchema['properties'] ?? [];
        $requiredInputProperties = $inputSchema['required'] ?? [];

        if (($inputProperties['prompt']['type'] ?? null) !== 'string'
            || ($inputProperties['context']['type'] ?? null) !== 'object'
            || ! in_array('prompt', $requiredInputProperties, true)
            || ! in_array('context', $requiredInputProperties, true)
            || array_diff($requiredInputProperties, ['prompt', 'context']) !== []) {
            throw ValidationException::withMessages([
                'input_schema' => 'The input contract must require a string prompt and an object context only.',
            ]);
        }

        $outputSchema = $specification['output_schema'];
        $outputProperties = $outputSchema['properties'] ?? [];
        $requiredOutputProperties = $outputSchema['required'] ?? [];

        if (($outputProperties['text']['type'] ?? null) !== 'string'
            || ! in_array('text', $requiredOutputProperties, true)) {
            throw ValidationException::withMessages([
                'output_schema' => 'The output contract must require a string text field.',
            ]);
        }

        $supportedTools = $defaultDefinition['allowed_tools'] ?? [];
        $requestedTools = $specification['allowed_tools'] ?? [];
        $unsupportedTools = array_diff($requestedTools, $supportedTools);

        if ($unsupportedTools !== []) {
            throw ValidationException::withMessages([
                'allowed_tools' => 'A specification cannot grant tools unsupported by its agent.',
            ]);
        }

        $queueRetryAfter = config('queue.connections.'.config('queue.default').'.retry_after');
        $agentTimeout = $specification['timeout_seconds'] ?? null;

        if (is_numeric($queueRetryAfter) && is_numeric($agentTimeout) && $agentTimeout >= $queueRetryAfter) {
            throw ValidationException::withMessages([
                'timeout_seconds' => 'The agent timeout must be shorter than the queue retry interval.',
            ]);
        }
    }

    private function assertAllowedTransition(string $agentName, AgentSpec $agentSpec, int $newStatus): void
    {
        $allowedTransitions = [
            AGENT_SPEC_STATUS_DRAFT => [AGENT_SPEC_STATUS_TEST],
            AGENT_SPEC_STATUS_TEST => [AGENT_SPEC_STATUS_ACTIVE],
            AGENT_SPEC_STATUS_ACTIVE => [AGENT_SPEC_STATUS_RETIRED],
            AGENT_SPEC_STATUS_RETIRED => [],
        ];

        if (! in_array($newStatus, $allowedTransitions[$agentSpec->status] ?? [], true)) {
            throw new ConflictHttpException('The requested agent specification lifecycle transition is not allowed.');
        }

        if ($agentSpec->status === AGENT_SPEC_STATUS_ACTIVE && $newStatus === AGENT_SPEC_STATUS_RETIRED) {
            $hasReplacement = AgentSpec::query()
                ->where('agent_name', $agentName)
                ->where('status', AGENT_SPEC_STATUS_ACTIVE)
                ->whereKeyNot($agentSpec->id)
                ->exists();

            if (! $hasReplacement) {
                throw new ConflictHttpException('An active agent specification cannot be retired without a replacement.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function specificationData(AgentSpec $agentSpec): array
    {
        return $agentSpec->only([
            'agent_name',
            'version',
            'status',
            'role',
            'system_prompt',
            'input_schema',
            'output_schema',
            'allowed_tools',
            'minimum_trust_level',
            'default_model',
            'fallback_model',
            'max_steps',
            'max_tokens',
            'timeout_seconds',
            'stop_conditions',
            'success_criteria',
            'created_by',
            'created_at',
            'updated_at',
        ]);
    }
}
