<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\AgentPackController;
use App\Http\Controllers\Api\AgentSpecController;
use App\Http\Controllers\Api\AgentTrustController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\KnowledgeSystemSettingsController;
use App\Http\Controllers\Api\NotificationResponseController;
use App\Http\Controllers\Api\ObservabilityController;
use App\Http\Controllers\Api\ProviderSettingsController;
use App\Http\Controllers\Api\SecurityControlController;
use App\Http\Controllers\Api\SkillController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskReplayShareController;
use App\Http\Controllers\Api\ToolApprovalController;
use App\Http\Controllers\Api\WebAccessController;
use App\Llm\ProviderCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
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

    Route::get('/v1/user', function (Request $request) {
        return response()->json([
            'user' => [
                ...$request->user()->only(['id', 'name', 'email']),
                'can_manage_knowledge_settings' => $request->user()->can('knowledge_settings.manage'),
                'can_manage_security' => $request->user()->can('security.manage'),
            ],
        ]);
    });

    Route::get('/v1/agent-packs', [AgentPackController::class, 'index'])->name('api.v1.agent-packs.index');
    Route::get('/v1/skills', [SkillController::class, 'index'])->name('api.v1.skills.index');
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

    Route::post('/v1/tasks/estimate', [TaskController::class, 'estimate'])
        ->middleware('throttle:30,1')
        ->name('api.v1.tasks.estimate');
    Route::post('/v1/tasks', [TaskController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.tasks.store');
    Route::get('/v1/tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
    Route::get('/v1/observability/summary', [ObservabilityController::class, 'summary'])
        ->name('api.v1.observability.summary');
    Route::get('/v1/tasks/{task}', [TaskController::class, 'show'])
        ->whereNumber('task')
        ->name('api.v1.tasks.show');
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
        Route::put('/keys/{provider}', [ProviderSettingsController::class, 'saveKey'])
            ->where('provider', '[a-zA-Z0-9_-]+')
            ->name('keys.save');
        Route::delete('/keys/{provider}', [ProviderSettingsController::class, 'deleteKey'])
            ->where('provider', '[a-zA-Z0-9_-]+')
            ->name('keys.delete');
        Route::post('/test-connection', [ProviderSettingsController::class, 'testConnection'])
            ->middleware('throttle:10,1')
            ->name('connection.test');
    });
});
