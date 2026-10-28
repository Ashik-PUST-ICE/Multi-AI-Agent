<?php

namespace App\Providers;

use App\Models\PendingApproval;
use App\Models\Task;
use App\Policies\PendingApprovalPolicy;
use App\Policies\TaskPolicy;
use App\Tenancy\TenantContext;
use App\Tools\ToolRegistry;
use App\Tools\WebSearchTool;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        $this->app->bind(ToolRegistry::class, function (): ToolRegistry {
            $toolRegistry = new ToolRegistry;
            $toolRegistry->register(new WebSearchTool);

            return $toolRegistry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(PendingApproval::class, PendingApprovalPolicy::class);

        Gate::before(function ($user, $ability) {
            if ($ability === 'agent_specs.manage'
                && $user
                && method_exists($user, 'hasRole')) {
                return $user->hasRole('super-admin') ? true : null;
            }

            return null;
        });
    }
}
