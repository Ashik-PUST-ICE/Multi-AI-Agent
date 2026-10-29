<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAccessPolicy;
use App\Models\UserAccessSetting;
use App\Models\UserNotification;
use App\Services\AccessPolicyEngine;
use App\Services\DeferredToolActionService;
use App\Tools\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\VAPID;

class AccessController extends Controller
{
    public function __construct(
        private readonly AccessPolicyEngine $accessPolicyEngine,
        private readonly ToolRegistry $tools,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $settings = $this->settings($request->user());

        return response()->json([
            'access' => [
                'mode' => $this->modeName($settings->access_mode),
                'agent_modes' => array_map($this->modeName(...), $settings->agent_access_modes ?? []),
                'never_auto_tools' => $this->accessPolicyEngine->neverAutoList(),
            ],
            'policies' => $request->user()->accessPolicies()
                ->where('status', ACCESS_POLICY_STATUS_ACTIVE)
                ->orderByDesc('id')
                ->get()
                ->map(fn (UserAccessPolicy $policy): array => $this->policyData($policy))
                ->all(),
            'notifications' => [
                'channels' => ['in_app'],
                'preferences' => $settings->notification_preferences ?? ['tool_approval' => ['in_app' => true]],
                'quiet_hours' => [
                    'start' => $settings->quiet_hours_start,
                    'end' => $settings->quiet_hours_end,
                ],
                'digest_time' => $settings->digest_time,
                'external_channels_enabled' => $request->user()->notificationChannels()->where('enabled', true)->exists(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $agentNames = array_keys(config('agent_specs.trust_defaults', []));
        $data = $request->validate([
            'mode' => ['required', Rule::in(['user', 'auto'])],
            'agent_modes' => ['sometimes', 'array', 'max:20'],
            'agent_modes.*' => ['required', Rule::in(['user', 'auto'])],
            'notification_preferences' => ['sometimes', 'array:tool_approval'],
            'notification_preferences.tool_approval' => ['sometimes', 'array:in_app,channels'],
            'notification_preferences.tool_approval.in_app' => ['sometimes', 'boolean'],
            'notification_preferences.tool_approval.channels' => ['sometimes', 'array', 'max:5'],
            'notification_preferences.tool_approval.channels.*' => ['required', Rule::in(['in_app', 'email', 'push', 'telegram', 'whatsapp'])],
            'quiet_hours.start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'digest_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ]);

        foreach (array_keys($data['agent_modes'] ?? []) as $agentName) {
            if (! in_array($agentName, $agentNames, true)) {
                throw ValidationException::withMessages([
                    'agent_modes' => "Unknown agent [{$agentName}].",
                ]);
            }
        }

        $settings = $this->settings($request->user());
        $settings->update([
            'access_mode' => $data['mode'] === 'auto' ? ACCESS_MODE_AUTO : ACCESS_MODE_USER,
            'agent_access_modes' => array_map(
                static fn (string $mode): int => $mode === 'auto' ? ACCESS_MODE_AUTO : ACCESS_MODE_USER,
                $data['agent_modes'] ?? $settings->agent_access_modes ?? [],
            ),
            'notification_preferences' => $data['notification_preferences']
                ?? $settings->notification_preferences
                ?? ['tool_approval' => ['in_app' => true, 'channels' => ['in_app']]],
            'quiet_hours_start' => data_get($data, 'quiet_hours.start', $settings->quiet_hours_start),
            'quiet_hours_end' => data_get($data, 'quiet_hours.end', $settings->quiet_hours_end),
            'digest_time' => array_key_exists('digest_time', $data) ? $data['digest_time'] : $settings->digest_time,
        ]);

        return response()->json(['access' => [
            'mode' => $this->modeName($settings->access_mode),
            'agent_modes' => array_map($this->modeName(...), $settings->agent_access_modes ?? []),
        ]]);
    }

    public function createPolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_name' => ['required', 'string', Rule::in(array_keys(config('agent_specs.trust_defaults', [])))],
            'tool_name' => ['required', 'string', 'max:100'],
            'input_constraints' => ['sometimes', 'array', 'max:20'],
            'scope' => ['required', Rule::in(['task', 'day', 'week', 'always'])],
            'task_id' => ['required_if:scope,task', 'nullable', 'integer'],
            'maximum_uses' => ['required', 'integer', 'min:1', 'max:1000'],
            'expires_in_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'undo_window_seconds' => ['required', 'integer', 'min:0', 'max:600'],
        ]);

        try {
            $tool = $this->tools->get($data['tool_name']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['tool_name' => $exception->getMessage()]);
        }

        if ($this->accessPolicyEngine->isNeverAuto($data['tool_name'])) {
            throw ValidationException::withMessages([
                'tool_name' => 'This tool is on the fixed Never-Auto list and cannot receive an Auto policy.',
            ]);
        }
        if (($tool->minimumTrustLevel() >= AGENT_TRUST_LEVEL_WRITE || $tool->requiresApproval())
            && empty($data['input_constraints'])) {
            throw ValidationException::withMessages([
                'input_constraints' => 'Write, outbound, and approval-sensitive tools need exact JSON input constraints before Auto can be granted.',
            ]);
        }

        $user = $request->user();
        $taskId = $data['task_id'] ?? null;
        if ($taskId !== null && ! $user->tasks()->whereKey($taskId)->exists()) {
            throw ValidationException::withMessages(['task_id' => 'The selected task is not owned by this account.']);
        }

        $scope = match ($data['scope']) {
            'task' => ACCESS_POLICY_SCOPE_TASK,
            'day' => ACCESS_POLICY_SCOPE_DAY,
            'week' => ACCESS_POLICY_SCOPE_WEEK,
            default => ACCESS_POLICY_SCOPE_ALWAYS,
        };
        $maximumExpiry = match ($data['scope']) {
            'day' => 1440,
            'week' => 10080,
            default => 10080,
        };
        if ($data['expires_in_minutes'] > $maximumExpiry) {
            throw ValidationException::withMessages([
                'expires_in_minutes' => "The selected scope may expire after at most {$maximumExpiry} minutes.",
            ]);
        }

        $policy = $user->accessPolicies()->create([
            'tenant_id' => $user->tenant_id,
            'task_id' => $scope === ACCESS_POLICY_SCOPE_TASK ? $taskId : null,
            'agent_name' => $data['agent_name'],
            'tool_name' => $data['tool_name'],
            'input_constraints' => $data['input_constraints'] ?? null,
            'scope' => $scope,
            'maximum_uses' => $data['maximum_uses'],
            'undo_window_seconds' => $data['undo_window_seconds'],
            'status' => ACCESS_POLICY_STATUS_ACTIVE,
            'expires_at' => now()->addMinutes($data['expires_in_minutes']),
        ]);

        return response()->json(['policy' => $this->policyData($policy)], 201);
    }

    public function revokePolicy(Request $request, int $policy): JsonResponse
    {
        $ownedPolicy = $request->user()->accessPolicies()->findOrFail($policy);
        $ownedPolicy->update(['status' => ACCESS_POLICY_STATUS_REVOKED]);

        return response()->json(['policy_id' => $ownedPolicy->id, 'status' => 'revoked']);
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()
            ->orderByRaw('status = ? DESC', [NOTIFICATION_STATUS_UNREAD])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (UserNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'priority' => $notification->priority,
                'title' => $notification->title,
                'message' => $notification->message,
                'data' => $notification->data,
                'status' => $notification->status,
                'delivered_at' => $notification->delivered_at,
                'read_at' => $notification->read_at,
                'response_at' => $notification->response_at,
                'deliveries' => $notification->deliveries->map(static fn ($delivery): array => [
                    'channel' => $delivery->channel,
                    'status' => $delivery->status,
                    'attempts' => $delivery->attempts,
                    'failure_reason' => $delivery->failure_reason,
                    'attempted_at' => $delivery->attempted_at,
                    'delivered_at' => $delivery->delivered_at,
                ]),
            ]);

        return response()->json(['notifications' => $notifications]);
    }

    public function markNotificationRead(Request $request, int $notification): JsonResponse
    {
        $ownedNotification = $request->user()->notifications()->findOrFail($notification);
        $ownedNotification->update([
            'status' => NOTIFICATION_STATUS_READ,
            'read_at' => now(),
        ]);

        return response()->json(['notification_id' => $ownedNotification->id, 'status' => 'read']);
    }

    public function cancelDeferredAction(Request $request, int $action, DeferredToolActionService $service): JsonResponse
    {
        if (! $service->cancel($action, (int) $request->user()->id)) {
            return response()->json(['message' => 'The undo window has already closed.'], 409);
        }

        return response()->json(['deferred_action_id' => $action, 'status' => 'cancelled']);
    }

    public function notificationChannels(Request $request): JsonResponse
    {
        $configs = $request->user()->notificationChannels()->get()->keyBy('channel');
        $push = $configs->get('push');

        return response()->json([
            'channels' => collect(['email', 'push', 'telegram', 'whatsapp'])->mapWithKeys(
                static fn (string $channel): array => [$channel => [
                    'configured' => $configs->has($channel),
                    'enabled' => (bool) ($configs->get($channel)?->enabled ?? false),
                ]],
            ),
            'push_public_key' => $push?->settings['public_key'] ?? null,
            'push_subscriptions' => $request->user()->pushSubscriptions()->count(),
        ]);
    }

    public function updateNotificationChannel(Request $request, string $channel): JsonResponse
    {
        if (! in_array($channel, ['email', 'push', 'telegram', 'whatsapp'], true)) {
            throw ValidationException::withMessages(['channel' => 'Unsupported notification channel.']);
        }

        $rules = match ($channel) {
            'email' => [
                'enabled' => ['required', 'boolean'],
                'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
                'port' => ['required_if:enabled,true', 'nullable', 'integer', 'min:1', 'max:65535'],
                'username' => ['nullable', 'string', 'max:255'],
                'password' => ['nullable', 'string', 'max:8192'],
                'encryption' => ['required_if:enabled,true', 'nullable', Rule::in(['tls', 'ssl', 'none'])],
                'from_address' => ['required_if:enabled,true', 'nullable', 'email', 'max:255'],
                'from_name' => ['nullable', 'string', 'max:160'],
                'recipient' => ['required_if:enabled,true', 'nullable', 'email', 'max:255'],
            ],
            'telegram' => [
                'enabled' => ['required', 'boolean'],
                'bot_token' => ['required_if:enabled,true', 'nullable', 'string', 'max:512'],
                'chat_id' => ['required_if:enabled,true', 'nullable', 'string', 'max:128'],
            ],
            'whatsapp' => [
                'enabled' => ['required', 'boolean'],
                'access_token' => ['required_if:enabled,true', 'nullable', 'string', 'max:8192'],
                'phone_number_id' => ['required_if:enabled,true', 'nullable', 'string', 'max:64'],
                'recipient' => ['required_if:enabled,true', 'string', 'max:32', 'regex:/^\+?[1-9][0-9]{6,14}$/'],
            ],
            default => ['enabled' => ['required', 'boolean']],
        };
        $data = $request->validate($rules);
        $user = $request->user();
        $existing = $user->notificationChannels()->where('channel', $channel)->first();
        $settings = [...($existing?->settings ?? []), ...array_filter(
            $data,
            static fn (string $key): bool => $key !== 'enabled' && $data[$key] !== null,
            ARRAY_FILTER_USE_KEY,
        )];

        if ($channel === 'email' && ($data['enabled'] ?? false)) {
            foreach (['host', 'port', 'encryption', 'from_address', 'recipient'] as $required) {
                if (! array_key_exists($required, $settings)) {
                    throw ValidationException::withMessages([$required => 'This email delivery setting is required.']);
                }
            }
        }
        if ($channel === 'telegram' && ($data['enabled'] ?? false)) {
            foreach (['bot_token', 'chat_id'] as $required) {
                if (empty($settings[$required])) {
                    throw ValidationException::withMessages([$required => 'This Telegram delivery setting is required.']);
                }
            }
        }
        if ($channel === 'whatsapp' && ($data['enabled'] ?? false)) {
            foreach (['access_token', 'phone_number_id', 'recipient'] as $required) {
                if (empty($settings[$required])) {
                    throw ValidationException::withMessages([$required => 'This WhatsApp delivery setting is required.']);
                }
            }
        }

        if ($channel === 'push' && ($data['enabled'] ?? false)) {
            if (empty($settings['public_key']) || empty($settings['private_key']) || empty($settings['subject'])) {
                throw ValidationException::withMessages(['channel' => 'Generate VAPID credentials before enabling Web Push.']);
            }
        }

        $config = $user->notificationChannels()->updateOrCreate(
            ['channel' => $channel],
            ['tenant_id' => $user->tenant_id, 'enabled' => $data['enabled'], 'settings' => $settings],
        );

        return response()->json(['channel' => $channel, 'enabled' => $config->enabled]);
    }

    public function generatePushVapid(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => ['required', 'string', 'max:255']]);
        if (! str_starts_with($data['subject'], 'mailto:') && ! str_starts_with($data['subject'], 'https://')) {
            throw ValidationException::withMessages(['subject' => 'VAPID subject must be a mailto: or HTTPS contact URI.']);
        }

        $keys = VAPID::createVapidKeys();
        $user = $request->user();
        $existing = $user->notificationChannels()->where('channel', 'push')->first();
        $settings = [
            ...($existing?->settings ?? []),
            'public_key' => $keys['publicKey'],
            'private_key' => $keys['privateKey'],
            'subject' => $data['subject'],
        ];
        $user->notificationChannels()->updateOrCreate(
            ['channel' => 'push'],
            ['tenant_id' => $user->tenant_id, 'enabled' => false, 'settings' => $settings],
        );

        return response()->json(['public_key' => $keys['publicKey'], 'enabled' => false]);
    }

    public function subscribePush(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);
        $endpoint = $data['endpoint'];
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
            || ! is_string($host)
            || ! $this->isAllowedPushHost($host)) {
            throw ValidationException::withMessages(['endpoint' => 'Use an HTTPS endpoint from a supported public push service.']);
        }

        $user = $request->user();
        $user->pushSubscriptions()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'tenant_id' => $user->tenant_id,
                'endpoint' => $endpoint,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
            ],
        );

        return response()->json(['subscribed' => true], 201);
    }

    private function isAllowedPushHost(string $host): bool
    {
        foreach (['fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'] as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function settings(User $user): UserAccessSetting
    {
        return UserAccessSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'access_mode' => ACCESS_MODE_USER,
                'agent_access_modes' => [],
                'notification_preferences' => ['tool_approval' => ['in_app' => true]],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function policyData(UserAccessPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'agent_name' => $policy->agent_name,
            'tool_name' => $policy->tool_name,
            'input_constraints' => $policy->input_constraints,
            'scope' => match ($policy->scope) {
                ACCESS_POLICY_SCOPE_TASK => 'task',
                ACCESS_POLICY_SCOPE_DAY => 'day',
                ACCESS_POLICY_SCOPE_WEEK => 'week',
                default => 'always',
            },
            'task_id' => $policy->task_id,
            'maximum_uses' => $policy->maximum_uses,
            'uses_count' => $policy->uses_count,
            'undo_window_seconds' => $policy->undo_window_seconds,
            'expires_at' => $policy->expires_at,
            'status' => $policy->status,
        ];
    }

    private function modeName(int $mode): string
    {
        return $mode === ACCESS_MODE_AUTO ? 'auto' : 'user';
    }
}
