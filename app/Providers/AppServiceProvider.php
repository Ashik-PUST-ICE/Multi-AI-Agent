<?php

namespace App\Providers;

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
        Gate::before(function ($user, $ability) {
            if ($user && method_exists($user, 'hasRole')) {
                return $user->hasRole('super-admin') ? true : null;
            }

            return null;
        });
    }
}
