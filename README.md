# Multi-AI-Agent

Laravel API-first multi-agent workspace. The Milestone 1 web client submits tasks to `/api/v1`, displays agent progress, and lets the task owner decide pending sensitive-tool approvals.

## Requirements

- PHP 8.2+
- Composer
- Node.js and npm
- MariaDB/MySQL or SQLite

## Local setup

1. Install PHP dependencies and create the environment file:

   ```sh
   composer install
   copy .env.example .env
   php artisan key:generate
   ```

2. Configure the database in `.env`, then migrate:

   ```sh
   php artisan migrate
   php artisan db:seed --class='Database\Seeders\AgentSpecSeeder'
   ```

   The seeder registers the initial four versioned agent specifications and the `agent_specs.manage` permission.

3. Install frontend dependencies and build the client:

   ```sh
   npm install
   npm run build
   ```

4. Start Laravel and its queue worker in separate terminals:

   ```sh
   php artisan serve
   php artisan queue:work --tries=3
   ```

   During frontend development, use `npm run dev` instead of `npm run build`.

## Web client authentication

The browser client calls the same authenticated `/api/v1` endpoints as other clients. It accepts a Sanctum personal access token and keeps it in the current browser tab's `sessionStorage`; disconnecting or closing the tab clears it.

To issue a token for an existing user from Tinker:

```sh
php artisan tinker
```

```php
$user = App\Models\User::findOrFail(1);
$user->createToken('web-dashboard')->plainTextToken;
```

Paste the returned token into the dashboard's API token field. Never paste a token into source code, `.env`, screenshots, or shared logs.

## API endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/v1/tasks` | List the authenticated user's tasks |
| `POST` | `/api/v1/tasks/estimate` | Preview routing, token ceiling, and available user-entered price estimates |
| `POST` | `/api/v1/tasks` | Create and queue a sandbox task |
| `GET` | `/api/v1/tasks/{task}` | View task and agent-run status |
| `GET` | `/api/v1/approvals` | List the user's pending tool approvals |
| `POST` | `/api/v1/approvals/{approval}/approve` | Approve the exact pending action |
| `POST` | `/api/v1/approvals/{approval}/reject` | Reject the pending action |
| `GET` | `/api/v1/admin/agent-specs` | List agent specifications and versions (requires `agent_specs.manage`) |
| `POST` | `/api/v1/admin/agent-specs/{agentName}/versions` | Create a draft spec version |
| `PATCH` | `/api/v1/admin/agent-specs/{agentName}/versions/{version}` | Edit a draft spec |
| `POST` | `/api/v1/admin/agent-specs/{agentName}/versions/{version}/status` | Advance draft → test → active |
| `POST` | `/api/v1/admin/agent-specs/{agentName}/versions/{version}/rollback` | Create a new draft from an earlier version |

Task submission accepts `title` and `prompt`. New tasks run in sandbox mode. Sensitive live tools require owner approval for the exact tool input; approval requests expire and are rejected when expired. Live web search remains disabled until its security adapter is implemented.

The complexity router sends a request to one agent by default and selects the four-agent pipeline only for an explicitly combined request. The web client displays the route and budget estimate before requiring a second submit to start. Per-agent and per-task token ceilings are hard limits; estimated input that leaves no room for a response is rejected. Provider-reported usage is stored per agent run, with a conservative text-based estimate when a provider omits usage metadata.

Users may optionally submit `model_pricing` entries with provider, model, currency, and input/output cost per million tokens to `PUT /api/v1/llm/settings`. Rates are accepted only for approved models. The estimate endpoint reports cost as unavailable when a selected model lacks a rate or route currencies differ; it never substitutes invented prices.

Agent specifications define role, schemas, permitted tools, trust metadata, token/step/timeout limits, stop conditions, and success criteria. Agent-to-agent handoffs use versioned structured envelopes and only the context required for the next stage. Invalid agent output gets one schema-correction attempt and fails if it remains invalid. Admin specification routes require the Spatie `agent_specs.manage` permission; model choices still come only from the user's approved settings.

LLM provider, model, timeout, and tool-call settings are controlled by application settings/configuration. User API keys are encrypted at rest; never add provider credentials to source code or commit them.

## Validation

```sh
php artisan test
vendor/bin/pint --test
npm run build
```
