<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Tenancy\TenantContext;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenantContext = app(TenantContext::class);

            if ($tenantContext->isInitialized()) {
                $model->tenant_id = $tenantContext->tenantId();

                return;
            }

            if ($model->tenant_id === null && isset($model->user_id)) {
                $model->tenant_id = User::query()
                    ->whereKey($model->user_id)
                    ->value('tenant_id');
            }
        });
    }
}
