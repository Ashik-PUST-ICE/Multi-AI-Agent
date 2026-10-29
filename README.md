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
   php artisan db:seed
   php artisan db:seed --class='Database\Seeders\AgentSpecSeeder'
   ```

   The default seeder registers the `user` and `admin` roles and the `agent_specs.manage`, `agent_packs.manage`, and `knowledge_settings.manage` permissions. The agent-spec seeder registers the configured versioned agent specifications, including the Phase J Skeptic. Starter agent packs are seeded on first access to the pack API.

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

Users can register or sign in from the browser client. Registration creates a private workspace and assigns only the `user` role. Login and registration return a Sanctum personal access token; the browser keeps it in the current tab's `sessionStorage`, and logout revokes the current token. The client also accepts a manually issued token for existing accounts.

To issue a token for an existing user from Tinker:

```sh
php artisan tinker
```

```php
$user = App\Models\User::findOrFail(1);
$user->createToken('web-dashboard')->plainTextToken;
```

Paste the returned token into the dashboard's API token field. Never paste a token into source code, `.env`, screenshots, or shared logs. Registration requires a password with at least 12 characters, including letters and numbers.

## Agent Trust Levels

Each user controls their own agent trust levels on the Agents page. Orchestrator, Writer, Reviewer, and Skeptic start at level 0; Research starts at level 1 for read-only tools. Read tools require level 1, write tools level 2, outbound communication level 3, and sensitive actions level 4. A tool call below its declared minimum is blocked before either `simulate()` or `execute()` can run. Live calls at level 2 or above always require approval, in addition to any tool-specific approval rule. When an agent attempts a tool above its current level, the system records the violation and automatically lowers that agent's trust by one level, never below 0. User changes and automatic demotions are kept in per-user history.

## API endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/v1/auth/register` | Register a user, create a private workspace, and issue a token |
| `POST` | `/api/v1/auth/login` | Authenticate an active user and issue a token |
| `POST` | `/api/v1/auth/logout` | Revoke the current token |
| `GET` | `/api/v1/user` | Return the authenticated user's account |
| `GET` | `/api/v1/trust` | Read the authenticated user's agent trust levels and change history |
| `PUT` | `/api/v1/trust/{agentName}` | Change one of the authenticated user's agent trust levels, with a reason |
| `GET` | `/api/v1/knowledge/documents` | List the authenticated user's private documents and index status |
| `POST` | `/api/v1/knowledge/documents` | Upload, extract, chunk, and index a private document |
| `GET` | `/api/v1/knowledge/documents/{document}/download` | Download an owned private document |
| `POST` | `/api/v1/knowledge/documents/{document}/reindex` | Rebuild an owned document's index for the selected embedding model |
| `DELETE` | `/api/v1/knowledge/documents/{document}` | Delete an owned document and its indexed chunks |
| `GET` | `/api/v1/knowledge/embedding-settings` | Read supported embedding choices and the selected setting (never API keys) |
| `PUT` | `/api/v1/knowledge/embedding-settings` | Select a supported embedding provider and model |
| `POST` | `/api/v1/knowledge/search` | Search indexed documents and return source/chunk citations |
| `GET` | `/api/v1/admin/knowledge-settings` | Read system-wide Knowledge limits (requires `knowledge_settings.manage`) |
| `PUT` | `/api/v1/admin/knowledge-settings` | Update system-wide Knowledge limits (requires `knowledge_settings.manage`) |
| `GET` | `/api/v1/memories` | List the user's short- and long-term memories |
| `POST` | `/api/v1/memories` | Create a user-owned memory |
| `DELETE` | `/api/v1/memories/{memory}` | Delete an owned memory |
| `GET` | `/api/v1/tasks` | List the authenticated user's tasks |
| `POST` | `/api/v1/tasks/estimate` | Preview TaskBrief, matched skills, routing, token ceiling, and available user-entered price estimates |
| `POST` | `/api/v1/tasks` | Create and queue a sandbox task |
| `GET` | `/api/v1/tasks/{task}` | View task and agent-run status |
| `GET` | `/api/v1/skills` | List vetted built-in skills and their fixed permission metadata |
| `GET` | `/api/v1/tasks/{task}/replay` | Read-only event replay in Sandbox mode |
| `POST` | `/api/v1/tasks/{task}/replay/{agentRun}/resume` | Restart a completed/failed step as a new Sandbox task |
| `POST` | `/api/v1/tasks/{task}/replay-shares` | Create an expiring read-only replay link (1–168 hours) |
| `DELETE` | `/api/v1/tasks/{task}/replay-shares/{share}` | Revoke one of the task owner's replay links |
| `GET` | `/api/v1/task-replays/{token}` | View the sanitized timeline for a valid share link |
| `POST` | `/api/v1/tasks/{task}/transition-to-live` | Request a gated Sandbox-to-Live transition (currently fail-closed) |
| `GET` | `/api/v1/approvals` | List the user's pending tool approvals |
| `POST` | `/api/v1/approvals/{approval}/approve` | Approve the exact pending action |
| `POST` | `/api/v1/approvals/{approval}/reject` | Reject the pending action |
| `GET` | `/api/v1/admin/agent-specs` | List agent specifications and versions (requires `agent_specs.manage`) |
| `POST` | `/api/v1/admin/agent-specs/{agentName}/versions` | Create a draft spec version |
| `PATCH` | `/api/v1/admin/agent-specs/{agentName}/versions/{version}` | Edit a draft spec |
| `POST` | `/api/v1/admin/agent-specs/{agentName}/versions/{version}/status` | Advance draft → test → active |
| `POST` | `/api/v1/admin/agent-specs/{agentName}/versions/{version}/rollback` | Create a new draft from an earlier version |
| `GET` | `/api/v1/agent-packs` | List active starter/custom packs and installation state |
| `PUT` | `/api/v1/agent-packs/{slug}/installation` | Install/enable or disable a pack for the authenticated user |
| `GET` | `/api/v1/admin/agent-packs` | List pack versions (requires `agent_packs.manage`) |
| `POST` | `/api/v1/admin/agent-packs` | Create a custom pack draft |
| `POST` | `/api/v1/admin/agent-packs/{slug}/versions` | Create a draft version |
| `PATCH` | `/api/v1/admin/agent-packs/{slug}/versions/{version}` | Edit a custom draft version |
| `POST` | `/api/v1/admin/agent-packs/{slug}/versions/{version}/status` | Activate a reviewed custom draft (`status`: 1 active, 2 retired) |
| `POST` | `/api/v1/admin/agent-packs/{slug}/versions/{version}/rollback` | Create a draft from an earlier version |

## Private knowledge and memory

Configure an embedding provider and model in the Knowledge view before uploading. Embedding choices currently include OpenAI and Gemini; the user's saved provider key is used server-side and is never returned by the API. The Knowledge File Manager service stores bytes on Laravel's private `local` disk, while `knowledge_documents` owns per-user metadata and `knowledge_chunks` owns extracted text and embeddings. Bytes are not stored in the database and are never served from the public disk. Supported documents are TXT, Markdown, CSV, PDF, XLS/XLSX, and PNG/JPG/JPEG/WEBP. PDF and spreadsheet extraction use Composer libraries; image OCR requires a local Tesseract installation, whose executable path is host configuration (`KNOWLEDGE_OCR_BINARY`) and is not an end-user setting. Files are treated as data only: spreadsheet formulas/macros are not executed.

Admins with the `knowledge_settings.manage` permission can change upload size, extraction size, chunk count, chunk size, and overlap from the Knowledge page. These operational policies are stored in the database and apply system-wide. The configured upload limit must not exceed the PHP/web-server request limits. Storage credentials, encryption/app keys, and host executable paths remain server-side configuration; do not expose them as ordinary database form inputs.

Changing the embedding provider or model marks existing documents as requiring reindexing. Reindexing replaces a document's old chunks only after extraction and embedding succeed; failed reindex attempts leave the prior index intact. Search and task context only use chunks matching the currently selected embedding settings. Task context is opt-in: select the documents before estimating, then submit with the short-lived preview token returned by the estimate. The task stores source document and chunk citations alongside the selected excerpts. Embedding-provider charges are separate from the task's LLM cost estimate.

Memory records have an explicit user or task scope, owner and creator provenance, user-input source, expiry, and an integer active/superseded state. User memories are private to their creator; task memories can only be listed or created by that task's owner and are never visible to another task. Tenant-wide and shared scopes are not enabled because their membership and consent rules are not defined. Only user-entered memory can be written through the API; agents cannot write or promote memory. Adding a new memory with the same non-empty label in the same scope supersedes the active version while retaining its prior content as history. An active memory can be deleted by its owner. Expired short-term memory is excluded from retrieval but retained for the owner to inspect or delete. Each memory selection is revalidated on retrieval; memory retrieval is not cached.

Tasks never receive personal memory implicitly. The user must select up to 10 active personal memories during task estimation; the estimate includes their content, and task submission is rejected if the selected content changed after that estimate. Selected memory is passed as untrusted reference data, not instructions, and remains scoped to that task. Task-specific memories are managed through `/api/v1/memories?task_id={ownedTaskId}` and the memory creation endpoint with `scope_type: 1`; they are not automatically inserted into a different task's context.

Task submission accepts `title`, `prompt`, and optionally an enabled `agent_pack` slug, up to 10 distinct `success_criteria` strings (500 characters each), and a task cost cap; any client-supplied `mode` is ignored and tasks start in Sandbox. Sandbox tools are routed to `simulate()` only, sandbox runs use the configured lower token ceiling, and when user-entered rates exist they use the least expensive approved model in a single currency. Agent runs, messages, and events inherit mode from their owning task/run; mode changes on existing tasks and recorded run data are rejected. The owner-scoped replay endpoint reads recorded events and sanitized messages only; it never invokes tools or providers. Restarting a completed or failed stage creates a new Sandbox task, uses current approved model settings and trust gates, and does not repeat Live actions. Share links are bearer links whose hashes are stored server-side; they expire within seven days, can be revoked by the owner, and expose a whitelisted event timeline only—not prompts, message content, or arbitrary event payloads. Provider cost in the timeline is computed from provider-reported/estimated token usage and the user's configured rates, and is not a billing statement.

The explicit Live transition endpoint records user requests, but is currently fail-closed: a completed Sandbox task must have trusted `evaluation.passed` and `skeptic.approved` events, approved Live models, credentials, and enough Live token budget. Evaluation is not implemented, so ordinary Sandbox tasks cannot currently transition. If those gates later pass, it creates a separate Live task; tool approvals and token limits continue to apply. Knowledge documents and memories are not automatically attached to tasks; the user must explicitly select them, and no agent tool can write them. This explicit selection is the current data-access control; there is no separately configurable Sandbox real-data policy or external real-data connector. Agent specs are mode-independent definitions. Agent packs provide domain guidance for the original four agents only; their allowed tools and minimum Trust levels are derived from the active agent specifications, never from pack content. Users must install and enable a pack before using it, and each task stores the selected pack version for deterministic execution. Admin-authored custom packs are versioned, and built-in packs are read-only. Separate Sandbox/Live API keys are also not implemented.

Sensitive live tools require owner approval for the exact tool input; approval requests expire and are rejected when expired. Live web search remains disabled until its security adapter is implemented.

The complexity router sends a request to one agent by default and selects the multi-agent pipeline (Orchestrator, Research, Writer, Reviewer, Skeptic) only for an explicitly combined request. The web client displays the route and budget estimate before requiring a second submit to start. Per-agent and per-task token ceilings are hard limits; estimated input that leaves no room for a response is rejected. Provider-reported usage is stored per agent run, with a conservative text-based estimate when a provider omits usage metadata.

The Skeptic independently assesses the Writer's response against the task and supplied success criteria. It must use a different user-approved, compatible model from both Writer's selected model and configured fallback; if no other compatible model has configured credentials, routing fails closed with a Settings error. Even when comparing Fixed routing, Skeptic uses the remaining approved models rather than reusing Writer's Fixed model. The criteria checker requires each criterion exactly once, marked `met` with evidence; missing or malformed assessments fail closed, and high/critical findings prevent approval. An unsuccessful assessment can trigger one bounded Writer → Reviewer → Skeptic retry, after which the task ends without approval if criteria remain unmet. Confidence is an estimate, not proof: 70% of the Skeptic score, plus 10 points per distinct Research HTTP(S) URL (capped at 30), minus 3/10/20/30 points per low/medium/high/critical finding, clamped to 0–100. The task view shows the score, criteria checklist, and findings.

Users may optionally submit `model_pricing` entries with provider, model, currency, and input/output cost per million tokens to `PUT /api/v1/llm/settings`. Rates are accepted only for approved models. The estimate endpoint reports cost as unavailable when a selected model or configured fallback lacks a rate or route currencies differ; it never substitutes invented prices. Maximum estimates include one bounded fallback attempt at the same token ceiling. Each provider response records its own token usage and estimated cost in the flight recorder; task summaries total those user-rate estimates by currency and are not billing statements.

An optional account-level per-task limit (`max_task_cost` and `budget_currency` in provider settings) and a lower per-task `max_cost` can be set. A task is rejected before queueing if its maximum estimate is unpriced, uses another currency, or exceeds the effective limit. Before each model request, the runner also checks that the request's maximum estimated cost fits the remaining task limit. User-entered rates and estimated input tokens cannot guarantee the provider's final bill. Task estimate requests are limited to 30 per minute and task submissions to 10 per minute per throttle key.

The Brain page lets users set each initial agent to Auto, Fixed, or Custom, choose a cheapest/balanced/best-quality Auto policy, approve models, enter their own rates, configure one explicitly selected fallback, and save provider credentials. Auto capability-filters approved models against the active agent specification and estimates prompt complexity from its length and explicit complexity cues. Cheapest uses comparable user-entered rates; balanced raises its minimum context-window percentile for more complex prompts and chooses the least expensive compatible model above that threshold. No verified quality evaluation exists yet, so best-quality explicitly uses the configured default when compatible and reports that quality could not be verified. The task estimate compares Auto and Fixed costs and marks evaluation results unavailable.

Routing decisions, rationale, and a fallback attempt are recorded as run events. A single user-selected fallback may be tried only for retryable provider failures before any output or tool call; it must also be approved, capability-compatible, and have configured credentials. The selection is never inferred to be "stronger" without evaluation data. Each Sandbox run records a fingerprint of its Brain settings, and Live transition is blocked if the settings changed since that run. Brain settings do not change task mode or trust level. Routing evaluation and improvement proposals are unavailable until a trusted evaluation test set and results exist; no routing preference is changed automatically.

Task estimates include a versioned `TaskBrief` created by a bounded local-rule analyzer before orchestration. It classifies Bengali, Banglish, English, or mixed-language requests; suggests intent, explicitly detected file/date entities, clarification questions (at most two), success criteria, and advisory risk/complexity. The original task text is kept unchanged. The analyzer makes no model call, does not ask questions autonomously, and cannot grant trust, tool, or access permissions. Proposed criteria are suggestions; users can add them to and edit the task's criteria before submission. The estimate displays matched skill summaries and adds selected guidance to the agent context; routing receives the complexity label as advisory metadata.

`GET /api/v1/skills` exposes the fixed curated built-in skill catalog. Matching is capped at two skills. Only matched skill instructions are progressively added to agent system guidance, marked untrusted; skill metadata cannot alter the active agent specification, model capability filter, trust level, or permitted tools. No external skill installation, user/organization skill authoring, script execution, skill version lifecycle, correction learning, skill evaluation, or system-prompt lint/evaluation is enabled. This keeps unverified code and externally supplied skill instructions out of execution.

Agent specifications define role, schemas, permitted tools, trust metadata, token/step/timeout limits, stop conditions, and success criteria. Agent-to-agent handoffs use versioned structured envelopes and only the context required for the next stage. Invalid agent output gets one schema-correction attempt and fails if it remains invalid. Admin specification routes require the Spatie `agent_specs.manage` permission; model choices still come only from the user's approved settings.

LLM provider, model, timeout, and tool-call settings are controlled by application settings/configuration. User API keys are encrypted at rest; never add provider credentials to source code or commit them.

## Observability

Authenticated owners can read the last 30 days of failure-category and warning counts from `GET /api/v1/observability/summary`. The task owner can export one task's Flight Recorder as OpenTelemetry-compatible JSON with `GET /api/v1/tasks/{task}/trace`; trace IDs and parent span IDs connect task, agent-run, model-call, and tool events. Exported spans contain allowlisted operational attributes only and exclude prompts, model output, tool arguments/results, credentials, and arbitrary event payloads.

The Skeptic review signal is a bounded heuristic: repeated approvals without findings raise a review warning after five assessments, but this is not proof of sycophancy. Repeated normalized output from the same agent on the same task input stops that task as stagnated; repeated output after a context change is not treated as a loop. Empty text outputs are recorded as silent failures and cannot complete a task. Failure counters are based on recorded failed events and are limited to 1,000 recent events per summary.

The trace endpoint emits OTLP trace JSON but does not push to a collector or claim full OpenTelemetry SDK instrumentation. Agent handoffs remain on the existing versioned envelope; A2A and MCP protocol adapters are not implemented by this phase.

## Validation

```sh
php artisan test
vendor/bin/pint --test
npm run build
```
