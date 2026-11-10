<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\AgentPackController;
use App\Http\Controllers\Api\AgentSpecController;
use App\Http\Controllers\Api\AgentTrustController;
use App\Http\Controllers\Api\ApiIntegrationController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AssistantSettingsController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CodingProposalController;
use App\Http\Controllers\Api\DeviceAuthorizationController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceTaskQueueController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\KnowledgeSystemSettingsController;
use App\Http\Controllers\Api\McpServerController;
use App\Http\Controllers\Api\NotificationResponseController;
use App\Http\Controllers\Api\ObservabilityController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\ProactiveBriefingController;
use App\Http\Controllers\Api\ProviderSettingsController;
use App\Http\Controllers\Api\SecurityControlController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskEventStreamController;
use App\Http\Controllers\Api\TaskReplayShareController;
use App\Http\Controllers\Api\TaskScheduleController;
use App\Http\Controllers\Api\ToolApprovalController;
use App\Http\Controllers\Api\WebAccessController;
use App\Llm\ProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Broadcast::routes([
    'prefix' => 'v1',
    'middleware' => ['auth:sanctum', 'tenant', 'throttle:60,1'],
]);

Route::get('/v1/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'locale' => app()->getLocale(),
        'message' => __('app.health'),
    ]);
});

Route::get('/v1/info', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '0.1.0',
        'providers' => array_keys(app(ProviderCatalog::class)->all()),
    ]);
});

Route::get('/v1/openapi.yaml', function () {
    return response()->file(base_path('docs/openapi.yaml'), [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('api.v1.openapi');

Route::get('/v1/task-replays/{token}', [TaskReplayShareController::class, 'show'])
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('api.v1.task-replays.show');
Route::get('/v1/notification-responses/{token}/confirm', [NotificationResponseController::class, 'show'])
    ->middleware('throttle:30,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('api.v1.notification-responses.show');
Route::post('/v1/notification-responses/{token}/confirm', [NotificationResponseController::class, 'decide'])
    ->middleware('throttle:10,1')
    ->where('token', '[a-f0-9]{64}')
    ->name('api.v1.notification-responses.decide');

Route::prefix('/v1/auth')->name('api.v1.auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login');
    Route::post('/device/code', [DeviceAuthorizationController::class, 'issueCode'])
        ->middleware('throttle:10,1')
        ->name('device.code');
    Route::post('/device/token', [DeviceAuthorizationController::class, 'poll'])
        ->middleware('throttle:30,1')
        ->name('device.token');
    Route::post('/device/approve', [DeviceAuthorizationController::class, 'authorizeDevice'])
        ->middleware('throttle:5,1')
        ->name('device.approve');
    Route::post('/device/deny', [DeviceAuthorizationController::class, 'denyDevice'])
        ->middleware('throttle:5,1')
        ->name('device.deny');
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/v1/coding/proposals', [CodingProposalController::class, 'index'])
        ->name('api.v1.coding.proposals.index');
    Route::post('/v1/coding/proposals', [CodingProposalController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('api.v1.coding.proposals.store');
    Route::get('/v1/coding/proposals/{proposal}', [CodingProposalController::class, 'show'])
        ->whereNumber('proposal')
        ->name('api.v1.coding.proposals.show');
    Route::post('/v1/coding/proposals/{proposal}/approve', [CodingProposalController::class, 'approve'])
        ->whereNumber('proposal')
        ->middleware('throttle:20,1')
        ->name('api.v1.coding.proposals.approve');
    Route::post('/v1/coding/proposals/{proposal}/reject', [CodingProposalController::class, 'reject'])
        ->whereNumber('proposal')
        ->middleware('throttle:20,1')
        ->name('api.v1.coding.proposals.reject');
    Route::get('/v1/devices', [DeviceController::class, 'index'])->name('api.v1.devices.index');
    Route::post('/v1/devices', [DeviceController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.devices.store');
    Route::delete('/v1/devices/{device}', [DeviceController::class, 'destroy'])
        ->whereNumber('device')
        ->name('api.v1.devices.destroy');
    Route::get('/v1/device-queue', [DeviceTaskQueueController::class, 'index'])
        ->name('api.v1.device-queue.index');
    Route::get('/v1/devices/{device}/queue', [DeviceTaskQueueController::class, 'pollDevice'])
        ->whereNumber('device')
        ->middleware('throttle:60,1')
        ->name('api.v1.devices.queue');
    Route::post('/v1/device-queue/{queue}/reconfirm', [DeviceTaskQueueController::class, 'reconfirm'])
        ->whereNumber('queue')
        ->middleware('throttle:10,1')
        ->name('api.v1.device-queue.reconfirm');
    Route::delete('/v1/device-queue/{queue}', [DeviceTaskQueueController::class, 'cancel'])
        ->whereNumber('queue')
        ->name('api.v1.device-queue.destroy');
    Route::post('/v1/assistant/messages', [AssistantController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('api.v1.assistant.messages.store');
    Route::get('/v1/assistant/conversations', [AssistantController::class, 'index'])
        ->name('api.v1.assistant.conversations.index');
    Route::get('/v1/assistant/conversations/{conversation}', [AssistantController::class, 'show'])
        ->whereNumber('conversation')
        ->name('api.v1.assistant.conversations.show');
    Route::get('/v1/assistant/settings', [AssistantSettingsController::class, 'show'])
        ->name('api.v1.assistant.settings.show');
    Route::put('/v1/assistant/settings', [AssistantSettingsController::class, 'update'])
        ->name('api.v1.assistant.settings.update');
    Route::get('/v1/assistant/proactive/briefing', [ProactiveBriefingController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('api.v1.assistant.proactive.briefing');
    Route::get('/v1/audit-logs', [AuditLogController::class, 'index'])
        ->name('api.v1.audit-logs.index');
    Route::post('/v1/auth/logout', [AuthController::class, 'logout'])
        ->name('api.v1.auth.logout');
    Route::get('/v1/trust', [AgentTrustController::class, 'index'])->name('api.v1.trust.index');
    Route::put('/v1/trust/{agentName}', [AgentTrustController::class, 'update'])
        ->where('agentName', '[A-Za-z0-9_-]+')
        ->name('api.v1.trust.update');
    Route::get('/v1/access', [AccessController::class, 'show'])->name('api.v1.access.show');
    Route::put('/v1/access', [AccessController::class, 'update'])->name('api.v1.access.update');
    Route::post('/v1/access/policies', [AccessController::class, 'createPolicy'])
        ->middleware('throttle:20,1')
        ->name('api.v1.access.policies.store');
    Route::delete('/v1/access/policies/{policy}', [AccessController::class, 'revokePolicy'])
        ->whereNumber('policy')
        ->name('api.v1.access.policies.destroy');
    Route::delete('/v1/access/deferred-actions/{action}', [AccessController::class, 'cancelDeferredAction'])
        ->whereNumber('action')
        ->name('api.v1.access.deferred-actions.destroy');
    Route::get('/v1/notifications', [AccessController::class, 'notifications'])->name('api.v1.notifications.index');
    Route::post('/v1/notifications/{notification}/read', [AccessController::class, 'markNotificationRead'])
        ->whereNumber('notification')
        ->name('api.v1.notifications.read');
    Route::get('/v1/notifications/channels', [AccessController::class, 'notificationChannels'])
        ->name('api.v1.notifications.channels');
    Route::put('/v1/notifications/channels/{channel}', [AccessController::class, 'updateNotificationChannel'])
        ->where('channel', 'email|push|telegram|whatsapp')
        ->name('api.v1.notifications.channels.update');
    Route::post('/v1/notifications/push/vapid', [AccessController::class, 'generatePushVapid'])
        ->middleware('throttle:5,1')
        ->name('api.v1.notifications.push.vapid');
    Route::post('/v1/notifications/push/subscriptions', [AccessController::class, 'subscribePush'])
        ->middleware('throttle:10,1')
        ->name('api.v1.notifications.push.subscribe');
    Route::get('/v1/web-access', [WebAccessController::class, 'show'])
        ->name('api.v1.web-access.show');
    Route::put('/v1/web-access', [WebAccessController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('api.v1.web-access.update');
    Route::delete('/v1/web-access/search-key', [WebAccessController::class, 'deleteSearchKey'])
        ->name('api.v1.web-access.search-key.destroy');
    Route::get('/v1/api-integrations', [ApiIntegrationController::class, 'index'])
        ->name('api.v1.api-integrations.index');
    Route::post('/v1/api-integrations', [ApiIntegrationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.api-integrations.store');
    Route::put('/v1/api-integrations/{integration}/enabled', [ApiIntegrationController::class, 'setEnabled'])
        ->whereNumber('integration')
        ->name('api.v1.api-integrations.enabled');
    Route::delete('/v1/api-integrations/{integration}', [ApiIntegrationController::class, 'destroy'])
        ->whereNumber('integration')
        ->name('api.v1.api-integrations.destroy');
    Route::get('/v1/mcp-servers', [McpServerController::class, 'index'])->name('api.v1.mcp-servers.index');
    Route::post('/v1/mcp-servers', [McpServerController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.mcp-servers.store');
    Route::put('/v1/mcp-servers/{server}/tools', [McpServerController::class, 'approveTools'])
        ->whereNumber('server')
        ->name('api.v1.mcp-servers.tools.update');
    Route::post('/v1/mcp-servers/{server}/refresh', [McpServerController::class, 'refresh'])
        ->whereNumber('server')
        ->middleware('throttle:10,1')
        ->name('api.v1.mcp-servers.refresh');
    Route::delete('/v1/mcp-servers/{server}', [McpServerController::class, 'destroy'])
        ->whereNumber('server')
        ->name('api.v1.mcp-servers.destroy');

    Route::get('/v1/user', function (Request $request) {
        return response()->json([
            'user' => [
                ...$request->user()->only(['id', 'name', 'email']),
                'can_manage_knowledge_settings' => $request->user()->can('knowledge_settings.manage'),
                'can_manage_security' => $request->user()->can('security.manage'),
                'can_manage_platform' => $request->user()->can('platform.manage'),
            ],
        ]);
    });

    Route::get('/v1/agent-packs', [AgentPackController::class, 'index'])->name('api.v1.agent-packs.index');
    Route::get('/v1/skills', [SkillController::class, 'index'])->name('api.v1.skills.index');
    Route::post('/v1/skills', [SkillController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.skills.store');
    Route::post('/v1/skills/drafts/from-task/{task}', [SkillController::class, 'suggestFromTask'])
        ->whereNumber('task')
        ->middleware('throttle:10,1')
        ->name('api.v1.skills.drafts.from-task');
    Route::get('/v1/skills/{skill}', [SkillController::class, 'show'])
        ->whereNumber('skill')
        ->name('api.v1.skills.show');
    Route::put('/v1/skills/{skill}', [SkillController::class, 'update'])
        ->whereNumber('skill')
        ->middleware('throttle:10,1')
        ->name('api.v1.skills.update');
    Route::post('/v1/skills/{skill}/verify', [SkillController::class, 'verify'])
        ->whereNumber('skill')
        ->middleware('throttle:10,1')
        ->name('api.v1.skills.verify');
    Route::put('/v1/skills/{skill}/enabled', [SkillController::class, 'setEnabled'])
        ->whereNumber('skill')
        ->name('api.v1.skills.enabled');
    Route::post('/v1/skills/{skill}/rollback/{version}', [SkillController::class, 'rollback'])
        ->whereNumber('skill')
        ->whereNumber('version')
        ->middleware('throttle:10,1')
        ->name('api.v1.skills.rollback');
    Route::get('/v1/skills/{skill}/evaluations', [SkillController::class, 'evaluationData'])
        ->whereNumber('skill')
        ->name('api.v1.skills.evaluations.index');
    Route::get('/v1/skills/{skill}/usage', [SkillController::class, 'usage'])
        ->whereNumber('skill')
        ->name('api.v1.skills.usage.show');
    Route::post('/v1/skills/{skill}/test-cases', [SkillController::class, 'storeTestCase'])
        ->whereNumber('skill')
        ->middleware('throttle:20,1')
        ->name('api.v1.skills.test-cases.store');
    Route::put('/v1/skills/{skill}/test-cases/{testCase}', [SkillController::class, 'updateTestCase'])
        ->whereNumber('skill')
        ->whereNumber('testCase')
        ->middleware('throttle:20,1')
        ->name('api.v1.skills.test-cases.update');
    Route::delete('/v1/skills/{skill}/test-cases/{testCase}', [SkillController::class, 'deleteTestCase'])
        ->whereNumber('skill')
        ->whereNumber('testCase')
        ->name('api.v1.skills.test-cases.destroy');
    Route::put('/v1/skills/{skill}/evaluation-policy', [SkillController::class, 'setEvaluationPolicy'])
        ->whereNumber('skill')
        ->name('api.v1.skills.evaluation-policy.update');
    Route::post('/v1/skills/{skill}/evaluate', [SkillController::class, 'evaluate'])
        ->whereNumber('skill')
        ->middleware('throttle:5,1')
        ->name('api.v1.skills.evaluate');
    Route::put('/v1/agent-packs/{slug}/installation', [AgentPackController::class, 'install'])
        ->where('slug', '[a-z0-9-]+')
        ->name('api.v1.agent-packs.installation');

    Route::get('/v1/knowledge/documents', [KnowledgeController::class, 'documents'])->name('api.v1.knowledge.documents.index');
    Route::post('/v1/knowledge/documents', [KnowledgeController::class, 'upload'])
        ->middleware('throttle:10,1')
        ->name('api.v1.knowledge.documents.store');
    Route::get('/v1/knowledge/documents/{document}/download', [KnowledgeController::class, 'downloadDocument'])
        ->whereNumber('document')
        ->name('api.v1.knowledge.documents.download');
    Route::post('/v1/knowledge/documents/{document}/reindex', [KnowledgeController::class, 'reindexDocument'])
        ->middleware('throttle:10,1')
        ->whereNumber('document')
        ->name('api.v1.knowledge.documents.reindex');
    Route::delete('/v1/knowledge/documents/{document}', [KnowledgeController::class, 'deleteDocument'])
        ->whereNumber('document')
        ->name('api.v1.knowledge.documents.destroy');
    Route::get('/v1/knowledge/embedding-settings', [KnowledgeController::class, 'embeddingSettings'])
        ->name('api.v1.knowledge.embedding-settings.show');
    Route::put('/v1/knowledge/embedding-settings', [KnowledgeController::class, 'saveEmbeddingSettings'])
        ->name('api.v1.knowledge.embedding-settings.update');
    Route::post('/v1/knowledge/search', [KnowledgeController::class, 'search'])
        ->middleware('throttle:20,1')
        ->name('api.v1.knowledge.search');
    Route::get('/v1/memories', [KnowledgeController::class, 'memories'])->name('api.v1.memories.index');
    Route::post('/v1/memories', [KnowledgeController::class, 'storeMemory'])->name('api.v1.memories.store');
    Route::delete('/v1/memories/{memory}', [KnowledgeController::class, 'deleteMemory'])
        ->whereNumber('memory')
        ->name('api.v1.memories.destroy');

    Route::prefix('/v1/admin/agent-packs')
        ->middleware('can:agent_packs.manage')
        ->name('api.v1.admin.agent-packs.')
        ->controller(AgentPackController::class)
        ->group(function (): void {
            Route::get('/', 'adminIndex')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('/{slug}/versions', 'createVersion')
                ->where('slug', '[a-z0-9-]+')
                ->name('versions.store');
            Route::patch('/{slug}/versions/{version}', 'update')
                ->where('slug', '[a-z0-9-]+')
                ->whereNumber('version')
                ->name('versions.update');
            Route::post('/{slug}/versions/{version}/status', 'transition')
                ->where('slug', '[a-z0-9-]+')
                ->whereNumber('version')
                ->name('versions.status');
            Route::post('/{slug}/versions/{version}/rollback', 'rollback')
                ->where('slug', '[a-z0-9-]+')
                ->whereNumber('version')
                ->name('versions.rollback');
        });

    Route::prefix('/v1/admin/knowledge-settings')
        ->middleware('can:knowledge_settings.manage')
        ->name('api.v1.admin.knowledge-settings.')
        ->controller(KnowledgeSystemSettingsController::class)
        ->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::put('/', 'update')->name('update');
        });

    Route::prefix('/v1/admin/security')
        ->middleware('can:security.manage')
        ->name('api.v1.admin.security.')
        ->controller(SecurityControlController::class)
        ->group(function (): void {
            Route::get('/kill-switch', 'showSystemKillSwitch')->name('kill-switch.show');
            Route::put('/kill-switch', 'updateSystemKillSwitch')->name('kill-switch.update');
        });

    Route::prefix('/v1/admin/platform')
        ->middleware('can:platform.manage')
        ->name('api.v1.admin.platform.')
        ->controller(PlatformAdminController::class)
        ->group(function (): void {
            Route::get('/dashboard', 'dashboard')->name('dashboard');
            Route::get('/evaluations', 'evaluationData')->name('evaluations.index');
            Route::post('/evaluations/cases', 'createEvaluationCase')->name('evaluations.cases.store');
            Route::post('/evaluations/run', 'runEvaluation')
                ->middleware('throttle:3,1')
                ->name('evaluations.run');
        });

    Route::post('/v1/tasks/estimate', [TaskController::class, 'estimate'])
        ->middleware('throttle:30,1')
        ->name('api.v1.tasks.estimate');
    Route::post('/v1/tasks', [TaskController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.tasks.store');
    Route::get('/v1/task-schedules', [TaskScheduleController::class, 'index'])
        ->name('api.v1.task-schedules.index');
    Route::post('/v1/task-schedules', [TaskScheduleController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.task-schedules.store');
    Route::delete('/v1/task-schedules/{schedule}', [TaskScheduleController::class, 'destroy'])
        ->whereNumber('schedule')
        ->name('api.v1.task-schedules.destroy');
    Route::get('/v1/tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
    Route::get('/v1/observability/summary', [ObservabilityController::class, 'summary'])
        ->name('api.v1.observability.summary');
    Route::get('/v1/tasks/{task}', [TaskController::class, 'show'])
        ->whereNumber('task')
        ->name('api.v1.tasks.show');
    Route::get('/v1/tasks/{task}/events/stream', [TaskEventStreamController::class, 'stream'])
        ->whereNumber('task')
        ->name('api.v1.tasks.events.stream');
    Route::get('/v1/tasks/{task}/graph', [TaskController::class, 'graph'])
        ->whereNumber('task')
        ->name('api.v1.tasks.graph');
    Route::get('/v1/tasks/{task}/trace', [ObservabilityController::class, 'trace'])
        ->whereNumber('task')
        ->name('api.v1.tasks.trace');
    Route::post('/v1/tasks/{task}/kill-switch', [SecurityControlController::class, 'stopTask'])
        ->whereNumber('task')
        ->name('api.v1.tasks.kill-switch');
    Route::post('/v1/tasks/{task}/agent-runs/{agentRun}/kill-switch', [SecurityControlController::class, 'stopAgentRun'])
        ->whereNumber('task')
        ->whereNumber('agentRun')
        ->name('api.v1.tasks.agent-runs.kill-switch');
    Route::get('/v1/tasks/{task}/replay', [TaskController::class, 'replay'])
        ->whereNumber('task')
        ->name('api.v1.tasks.replay');
    Route::post('/v1/tasks/{task}/replay-shares', [TaskReplayShareController::class, 'store'])
        ->middleware('throttle:10,1')
        ->whereNumber('task')
        ->name('api.v1.tasks.replay-shares.store');
    Route::delete('/v1/tasks/{task}/replay-shares/{share}', [TaskReplayShareController::class, 'destroy'])
        ->whereNumber('task')
        ->whereNumber('share')
        ->name('api.v1.tasks.replay-shares.destroy');
    Route::post('/v1/tasks/{task}/replay/{agentRun}/resume', [TaskController::class, 'resumeFromRun'])
        ->whereNumber('task')
        ->whereNumber('agentRun')
        ->name('api.v1.tasks.replay.resume');
    Route::post('/v1/tasks/{task}/transition-to-live', [TaskController::class, 'transitionToLive'])
        ->whereNumber('task')
        ->name('api.v1.tasks.transition-to-live');
    Route::get('/v1/approvals', [ToolApprovalController::class, 'index'])->name('api.v1.approvals.index');
    Route::post('/v1/approvals/batch', [ToolApprovalController::class, 'decideBatch'])
        ->middleware('throttle:20,1')
        ->name('api.v1.approvals.batch');
    Route::post('/v1/approvals/{approval}/approve', [ToolApprovalController::class, 'approve'])
        ->whereNumber('approval')
        ->name('api.v1.approvals.approve');
    Route::post('/v1/approvals/{approval}/reject', [ToolApprovalController::class, 'reject'])
        ->whereNumber('approval')
        ->name('api.v1.approvals.reject');

    Route::prefix('/v1/admin/agent-specs')
        ->middleware('can:agent_specs.manage')
        ->name('api.v1.admin.agent-specs.')
        ->controller(AgentSpecController::class)
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/{agentName}/versions/{version}', 'show')
                ->where('agentName', '[A-Za-z0-9_-]+')
                ->whereNumber('version')
                ->name('show');
            Route::get('/{agentName}/versions/{version}/lint', 'lint')
                ->where('agentName', '[A-Za-z0-9_-]+')
                ->whereNumber('version')
                ->name('lint');
            Route::post('/{agentName}/versions', 'store')
                ->where('agentName', '[A-Za-z0-9_-]+')
                ->name('store');
            Route::patch('/{agentName}/versions/{version}', 'update')
                ->where('agentName', '[A-Za-z0-9_-]+')
                ->whereNumber('version')
                ->name('update');
            Route::post('/{agentName}/versions/{version}/status', 'transition')
                ->where('agentName', '[A-Za-z0-9_-]+')
                ->whereNumber('version')
                ->name('status');
            Route::post('/{agentName}/versions/{version}/rollback', 'rollback')
                ->where('agentName', '[A-Za-z0-9_-]+')
                ->whereNumber('version')
                ->name('rollback');
        });

    Route::prefix('/v1/llm')->name('api.v1.llm.')->group(function () {
        Route::get('/catalog', [ProviderSettingsController::class, 'catalog'])->name('catalog');
        Route::get('/settings', [ProviderSettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [ProviderSettingsController::class, 'update'])->name('settings.update');
        Route::delete('/keys/legacy/{provider}', [ProviderSettingsController::class, 'deleteLegacyKey'])
            ->where('provider', '[a-zA-Z0-9_-]+')
            ->name('keys.legacy.delete');
        Route::put('/keys/{agentName}/{provider}', [ProviderSettingsController::class, 'saveKey'])
            ->where('agentName', '[A-Za-z0-9_-]+')
            ->where('provider', '[a-zA-Z0-9_-]+')
            ->name('keys.save');
        Route::delete('/keys/{agentName}/{provider}', [ProviderSettingsController::class, 'deleteKey'])
            ->where('agentName', '[A-Za-z0-9_-]+')
            ->where('provider', '[a-zA-Z0-9_-]+')
            ->name('keys.delete');
        Route::post('/test-connection', [ProviderSettingsController::class, 'testConnection'])
            ->middleware('throttle:10,1')
            ->name('connection.test');
    });
});
