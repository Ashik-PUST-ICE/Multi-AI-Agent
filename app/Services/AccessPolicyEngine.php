<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAccessPolicy;
use App\Models\UserAccessSetting;
use App\Tools\ToolApprovalRequiredException;
use App\Tools\ToolRegistry;
use App\Tools\ToolTrustLevelException;
use Illuminate\Support\Facades\DB;

class AccessPolicyEngine
{
    private const NEVER_AUTO_TOOL_PATTERNS = [
        'lock',
        'unlock',
        'stove',
        'gas',
        'email',
        'message',
        'telegram',
        'whatsapp',
        'payment',
        'transfer',
        'purchase',
        'buy',
        'access_settings',
        'access_policy',
        'api_key',
        'credential',
        'trust_level',
        'kill_switch',
        'install_agent',
        'install_tool',
        'install_pack',
        'install_skill',
        'delete',
        'remove',
        'purge',
        'destroy',
        'rm_rf',
        'format_disk',
        'sudo',
        'prompt_injection',
        'skeptic',
        'mcp',
        'custom_tool',
        'webhook',
        'code_execute',
        'run_code',
        'browser_submit',
        'browser_download',
        'login',
        'password_form',
        'payment_form',
        'api_request',
        'http_request',
    ];

    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * Returns an approval signature only when the owner has explicitly approved
     * this exact call or a bounded Auto policy covers it.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $approvedCallSignatures
     * @return list<string>
     */
    public function authorizeToolCall(
        ?User $user,
        ?Task $task,
        ?AgentRun $agentRun,
        string $agentName,
        string $toolName,
        array $input,
        string $mode,
        int $trustLevel,
        array $approvedCallSignatures,
        ?ToolRegistry $toolRegistry = null,
    ): array {
        $toolRegistry ??= $this->tools;
        $tool = $toolRegistry->get($toolName);
        if ($trustLevel < $tool->minimumTrustLevel()) {
            throw new ToolTrustLevelException($toolName, $tool->minimumTrustLevel(), $trustLevel);
        }

        if ($mode === TASK_MODE_SANDBOX) {
            return [];
        }

        if ($user === null || $task === null || $agentRun === null) {
            throw new \RuntimeException('Live tool calls require an authenticated task owner and agent run.');
        }

        $signature = $toolRegistry->approvalSignature($toolName, $input);
        if (in_array($signature, $approvedCallSignatures, true)) {
            return [$signature];
        }

        $settings = UserAccessSetting::query()->firstWhere('user_id', $user->id);
        $agentModes = $settings?->agent_access_modes ?? [];
        $accessMode = (int) ($agentModes[$agentName] ?? $settings?->access_mode ?? ACCESS_MODE_USER);

        if ($accessMode !== ACCESS_MODE_AUTO) {
            throw new ToolApprovalRequiredException($toolName, $input, $signature);
        }

        $neverAuto = $this->isNeverAuto($toolName);
        $deferredPolicy = null;
        $policyFailureReason = 'no_active_policy';
        $policy = $neverAuto ? null : DB::transaction(function () use ($user, $task, $agentName, $toolName, $tool, $input, &$policyFailureReason, &$deferredPolicy): ?UserAccessPolicy {
            $query = UserAccessPolicy::query()
                ->where('user_id', $user->id)
                ->where('agent_name', $agentName)
                ->where('tool_name', $toolName)
                ->where('status', ACCESS_POLICY_STATUS_ACTIVE)
                ->where('expires_at', '>', now())
                ->whereColumn('uses_count', '<', 'maximum_uses')
                ->where(function ($scopes) use ($task): void {
                    $scopes->where('scope', ACCESS_POLICY_SCOPE_ALWAYS)
                        ->orWhere(function ($day): void {
                            $day->where('scope', ACCESS_POLICY_SCOPE_DAY)
                                ->where('created_at', '>=', now()->startOfDay());
                        })
                        ->orWhere(function ($week): void {
                            $week->where('scope', ACCESS_POLICY_SCOPE_WEEK)
                                ->where('created_at', '>=', now()->startOfWeek());
                        })
                        ->orWhere(function ($taskScope) use ($task): void {
                            $taskScope->where('scope', ACCESS_POLICY_SCOPE_TASK)
                                ->where('task_id', $task->id);
                        });
                });

            $policy = $query->lockForUpdate()->orderByDesc('id')->first();
            if ($policy === null) {
                return null;
            }
            if (($tool->minimumTrustLevel() >= AGENT_TRUST_LEVEL_WRITE || $tool->requiresApproval())
                && ! $this->matchesPolicyInput($policy, $input)) {
                $policyFailureReason = 'input_constraints_mismatch';

                return null;
            }
            if ($policy->undo_window_seconds > 0) {
                $policyFailureReason = 'undo_window_requires_deferred_execution';
                $deferredPolicy = $policy;

                return null;
            }

            $policy->increment('uses_count');

            return $policy;
        });

        if ($neverAuto || $policy === null) {
            if ($deferredPolicy !== null) {
                throw new ToolApprovalRequiredException(
                    $toolName,
                    $input,
                    $signature,
                    $deferredPolicy->undo_window_seconds,
                    $deferredPolicy->id,
                );
            }

            $settings->update([
                'access_mode' => ACCESS_MODE_USER,
                'agent_access_modes' => array_map(
                    static fn ($mode): int => ACCESS_MODE_USER,
                    [...($settings->agent_access_modes ?? []), $agentName => ACCESS_MODE_USER],
                ),
            ]);
            app(AgentActivityLogger::class)->recordEvent($agentRun, 'access.auto_reverted', [
                'agent_name' => $agentName,
                'tool_name' => $toolName,
                'reason' => $neverAuto ? 'never_auto_tool' : $policyFailureReason,
            ]);
            app(NotificationService::class)->accessAutoReverted($user, $task, $agentName, $toolName);

            throw new ToolApprovalRequiredException($toolName, $input, $signature);
        }

        return [$signature];
    }

    public function isNeverAuto(string $toolName): bool
    {
        $normalizedName = strtolower($toolName);

        foreach (self::NEVER_AUTO_TOOL_PATTERNS as $pattern) {
            if (str_contains($normalizedName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function matchesPolicyInput(UserAccessPolicy $policy, array $input): bool
    {
        return $this->matchesExactInput($policy->input_constraints ?? [], $input);
    }

    /**
     * @return list<string>
     */
    public function neverAutoList(): array
    {
        return self::NEVER_AUTO_TOOL_PATTERNS;
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>  $input
     */
    private function matchesExactInput(array $constraints, array $input): bool
    {
        return $constraints !== []
            && $this->canonicalJson($constraints) === $this->canonicalJson($input);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        $sort = function (array $items) use (&$sort): array {
            foreach ($items as $key => $item) {
                if (is_array($item) && ! array_is_list($item)) {
                    $items[$key] = $sort($item);
                }
            }
            if (! array_is_list($items)) {
                ksort($items);
            }

            return $items;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}
