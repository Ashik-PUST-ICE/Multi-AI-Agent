# Agent security threat model

This document records the current security boundary for Phase V. It follows the OWASP Agentic Top 10 risk categories and describes implemented controls separately from remaining gaps. It is not a security certification.

## Trust boundaries and assets

- **Untrusted inputs:** user prompts, Knowledge file contents, model outputs and tool arguments.
- **Sensitive assets:** user-owned provider credentials, task and tenant data, approved model selections, trust levels, and pending approvals.
- **Execution boundary:** authenticated `/api/v1` requests enter Laravel; queued jobs execute agent runs; the provider resolver uses the requesting user's settings; `ToolRegistry` enforces trust, approval, and Sandbox-vs-Live dispatch.
- **External boundary:** no live outbound adapter is enabled. `WebSearchTool::execute()` intentionally fails closed. No task content should be treated as trusted instructions.

## OWASP Agentic Top 10 mapping

| Risk | Exposure and current controls | Remaining risk / release gate |
|---|---|---|
| ASI01 Agent Goal Hijack | Tasks retain a SHA-256 hash of their original description. A mismatch halts execution before provider or tool work. The task objective must remain authoritative over Knowledge and model output. | This detects objective mutation, not semantic drift in model output. Validate goal adherence in red-team tests before enabling write or outbound tools. |
| ASI02 Tool Misuse | Tools must be explicitly registered, have input schemas and minimum trust levels, and receive owner approval for write-or-higher actions. Sandbox dispatches only `simulate()`. Per-task Live outbound actions are throttled. | The current live web-search adapter is disabled, and no outbound tool is enabled. Tool descriptions are not cryptographically bound to implementations. Keep write/outbound tools disabled until Phase V tests pass. |
| ASI03 Identity and Privilege Abuse | API routes require Sanctum authentication and tenant scoping; owner controls query through the owner's task relation. Security administration uses the `security.manage` permission. Provider credentials are resolved for the requesting user and are not intentionally placed in agent messages. | Credentials are user/provider-scoped rather than isolated per agent identity. Do not add a credential-bearing tool until per-agent least-privilege credentials and isolation tests exist. |
| ASI04 Agentic Supply Chain | Agent Packs are sourced from the local database registry and their definition JSON is fingerprinted; use and serialization verify the hash. | Hashes detect accidental or partial tampering but are not signatures: a database writer can change both definition and hash. Publisher identity, signed artifacts, and external-registry verification are not implemented. External installs must remain disabled. |
| ASI05 Unexpected Code Execution | The tool interface requires both `execute()` and `simulate()`; Sandbox mode calls only `simulate()`. Agent-directed code execution is not provided. | File extraction invokes a configured parser process for uploaded data. It is not an agent command runner, but remains an attack surface and needs isolated resource limits and malicious-file testing. |
| ASI06 Memory and Context Poisoning | Knowledge is owner-scoped and private; extracted context is passed as data. Message/activity serialization redacts credential-shaped values. Task objective integrity is checked independently. | Poisoned Knowledge or memory can still influence model output. No automatic prompt-injection detector is a security boundary; red-team tests for poisoned memory and files remain required. |
| ASI07 Insecure Inter-Agent Communication | Messages use a versioned envelope with typed required fields. Workers check task ID and recipient. Run claiming transitions only pending runs under a database lock, preventing duplicate job execution. | There is no cryptographic sender provenance or envelope nonce. Legacy envelopes are still accepted for compatibility. Add adversarial sender/replay tests and stronger provenance before exposing any external message transport. |
| ASI08 Cascading Failures | Task, agent-run, and system kill switches block work at provider/tool checkpoints. A claimed run is atomic, and execution stops after a kill switch is observed. Outbound calls have a task-level cap. | An in-flight provider request cannot be interrupted; stop takes effect at the next checkpoint. Throttling applies only to Live tools whose declared trust level is outbound or higher. |
| ASI09 Human-Agent Trust Exploitation | Tool approvals are bound to a signature of tool name and normalized arguments; approval payloads are sanitized. Agents cannot alter access settings. | User-interface clarity and notification policy need ongoing review. Approval must never be inferred from silence, and agents must not self-approve. |
| ASI10 Rogue Agents | Agent definitions are registry-controlled, tools are allow-listed per agent specification, and output/input schemas are validated. Kill switches and trust limits constrain execution. | Behavioral monitoring and adversarial tests for deceptive tool descriptions, poisoned memory, and instruction hierarchy are incomplete. Model output remains untrusted. |

## Operational requirements

- Keep execution in Sandbox by default; never invoke `execute()` in Sandbox.
- Never store secrets in source control, prompts, agent messages, logs, or replay payloads.
- Keep any future write or outbound tools disabled until Phase V and Phase AA are complete and the owner explicitly approves Live actions.
- Treat kill-switch state as a checkpoint control: it cannot cancel an already in-flight provider HTTP request.
- Re-run migration, authorization, duplicate-job, objective-integrity, prompt-injection, and malicious-file tests when execution surfaces change.
