<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserMcpServer;
use App\Services\AuditLogService;
use App\Services\McpServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class McpServerController extends Controller
{
    public function __construct(
        private readonly McpServerService $mcpServerService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'servers' => UserMcpServer::query()
                ->where('user_id', $request->user()->id)
                ->orderBy('name')
                ->get()
                ->map(fn (UserMcpServer $server): array => $this->mcpServerService->present($server))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (UserMcpServer::query()->where('user_id', $request->user()->id)->count() >= 20) {
            throw ValidationException::withMessages(['name' => 'A maximum of 20 MCP servers can be configured.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'access_token' => ['nullable', 'string', 'max:4096'],
        ]);
        $endpointParts = parse_url($data['endpoint']);
        if (! is_array($endpointParts) || isset($endpointParts['query']) || isset($endpointParts['fragment'])) {
            throw ValidationException::withMessages([
                'endpoint' => 'MCP endpoint query strings and fragments are not allowed; store authentication as a bearer token.',
            ]);
        }
        if (UserMcpServer::query()->where('user_id', $request->user()->id)->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'A server with this name already exists.']);
        }

        $server = $this->mcpServerService->addServer(
            $request->user(),
            $data['name'],
            $data['endpoint'],
            $data['access_token'] ?? null,
        );

        return response()->json(['server' => $server], 201);
    }

    public function approveTools(Request $request, int $server): JsonResponse
    {
        $data = $request->validate([
            'approved_tools' => ['required', 'array', 'max:100'],
            'approved_tools.*' => ['required', 'string', 'max:128', 'distinct'],
            'enabled' => ['required', 'boolean'],
        ]);
        $ownedServer = UserMcpServer::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($server)
            ->firstOrFail();

        try {
            $updated = $this->mcpServerService->updateServer(
                $request->user(),
                $server,
                $data['approved_tools'],
                $data['enabled'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['approved_tools' => $exception->getMessage()]);
        }
        $user = $request->user();
        $this->auditLogService->record($user, 'mcp.tools.approved', $ownedServer, [
            'approved_tools' => $updated['approved_tools'],
            'enabled' => $updated['enabled'],
        ], $user);

        return response()->json(['server' => $updated]);
    }

    public function refresh(Request $request, int $server): JsonResponse
    {
        $mcpServer = UserMcpServer::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($server)
            ->firstOrFail();
        $tools = $this->mcpServerService->discover(
            $request->user(),
            $mcpServer->endpoint,
            $mcpServer->access_token,
        );
        $mcpServer->forceFill([
            'discovered_tools' => $tools,
            'approved_tools' => [],
            'enabled' => false,
        ])->save();

        return response()->json(['server' => $this->mcpServerService->present($mcpServer->fresh())]);
    }

    public function destroy(Request $request, int $server): JsonResponse
    {
        UserMcpServer::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($server)
            ->firstOrFail()
            ->delete();

        return response()->json(['deleted' => true]);
    }
}
