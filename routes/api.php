<?php

use App\Http\Controllers\Api\ProviderSettingsController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\ToolApprovalController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/v1/user', function (Request $request) {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'email']),
        ]);
    });

    Route::post('/v1/tasks', [TaskController::class, 'store'])->name('api.v1.tasks.store');
    Route::get('/v1/tasks/{task}', [TaskController::class, 'show'])
        ->whereNumber('task')
        ->name('api.v1.tasks.show');
    Route::get('/v1/approvals', [ToolApprovalController::class, 'index'])->name('api.v1.approvals.index');
    Route::post('/v1/approvals/{approval}/approve', [ToolApprovalController::class, 'approve'])
        ->whereNumber('approval')
        ->name('api.v1.approvals.approve');
    Route::post('/v1/approvals/{approval}/reject', [ToolApprovalController::class, 'reject'])
        ->whereNumber('approval')
        ->name('api.v1.approvals.reject');

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
