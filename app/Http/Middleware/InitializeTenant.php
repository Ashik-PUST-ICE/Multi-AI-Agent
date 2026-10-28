<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenant
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if (! is_int($tenantId) && ! ctype_digit((string) $tenantId)) {
            return response()->json(['message' => 'The authenticated account has no active workspace.'], 403);
        }

        $this->tenantContext->initialize((int) $tenantId);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
