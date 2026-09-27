# Phase 15 — AI and Intelligence

Blueprint §93–§95, §103, §121 Phase 15. Built 2026-09-28.

## Architecture (§93)

`Assistant page → AiGateway (permission-aware) → Assistant → Domain services → Data`. An assistant never touches tables directly and never writes: it calls the same services the UI uses, under the asking user's permissions, and returns an `AiAnswer` (prose, `sources`, suggested `actions` as links, `intent`, `isInference`, compact `facts`). The gateway checks the tenant feature flag and permission, records an `AiInteraction` (question, answer, sources, actions, intent, provider, model, tokens, latency, feedback, inference flag), and audits inference answers.

The **language model is optional** (`ai.llm` feature, off by default): when enabled and a provider is configured, the gateway sends only the system prompt, the question, the deterministic draft and the `facts` (identifiers redacted when `peopleos.ai.redact_identifiers` is on) and uses the model's rephrasing; on any failure it falls back to the deterministic answer. Provider abstraction `AiProvider` with `NullProvider` and `AnthropicProvider` (Messages API via HTTP; `PEOPLEOS_AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY`, `PEOPLEOS_AI_MODEL`).

## Assistants (§94)

| Key | Permission | What it answers |
|---|---|---|
| `employee` | `ai.use` | Leave balance and requests, latest payslip, attendance this month, open HR requests, upcoming holidays, goals, learning, assets, Needs Attention; delegates policy questions. |
| `policy` | `ai.use` | Notice period setting, the employee's resolved attendance / leave / overtime policy settings, and knowledge-base articles in the employee's audience (keyword retrieval with snippets and links). Never answers from general knowledge. |
| `manager` | `ai.manager` (only shown to users with current direct reports) | Team today (present / on leave / absent / late), pending approvals and reviews, team goal progress, attrition-risk signals for direct reports. |
| `hr` | `ai.hr` | Probations ending, onboarding backlog, expiring documents, open verifications, workforce metrics, and **natural-language people search** (`PeopleQuery`: location / department / designation names, lifecycle words, gender, "joined this year / last month / last N days / in 2025", tenure) run through the employees dataset. |
| `payroll_auditor` | `ai.payroll_auditor` | Findings from `PayrollAnomalyDetector` on the latest calculated run. |
| `workforce` | `ai.workforce` | Summary, 12‑month trend, critical skills, attrition risk ranking, cost, capacity (exits in progress). |

**`PayrollAnomalyDetector`** — duplicate primary bank accounts across employees, blocking exceptions, zero / negative net, deductions above a share of gross, high LOP, missing bank or PAN, net-pay change vs the previous finalized run, TDS jumps, employees newly appearing, salary revisions above a threshold in the period (with the recorded reason), exited employees still in the run. Thresholds in `peopleos.ai.payroll_audit`. Read-only.

**`AttritionRisk`** — additive, transparent signals with points from `peopleos.ai.attrition_risk`: short tenure, no pay revision in 24 months, low latest rating, mandatory learning overdue, no one-on-one in 90 days, repeated absences, recent constructive feedback, stalled high performer, open grievance. Bands low / medium / high. Every output lists its signals and is labelled a system-generated inference (§95).

**`ConfigurationSearch`** — §103 "What do you want to configure?": keyword map over admin pages (`peopleos.ai.config_search`).

## Admin UI

- **Me → Assistant**: chat with assistant switcher (only entitled assistants), examples, history, sources, action links, thumbs up / down, inference and model badges.
- **Payroll → Payroll Auditor**: findings for a chosen run with severity and evidence.
- **Analytics → Workforce Intelligence**: attrition-risk table with signals and critical skills.
- **Configuration → What do you want to configure?**: live search.
- **Audit → AI interactions**: governance log (`ai.admin` or `audit.view`) with the full question, answer, sources and feedback.

**Permissions** — `ai.use|manager|hr|payroll_auditor|workforce|admin`. Employee: `ai.use`; Manager: `ai.use`, `ai.manager`; HR Admin: use, manager, hr, workforce; Payroll Admin: `ai.payroll_auditor`; Executive: `ai.workforce`; Auditor: `ai.admin`. Feature flags `ai.assistants`, `ai.payroll_auditor`, `ai.workforce_intelligence`, `ai.llm`.

## Governance (§95)

- Permission-aware by construction: the gateway resolves the user's employee and assistants only call services with that user.
- Auditable: every interaction is stored; inference answers additionally write an audit event.
- Explainable: sources and evidence accompany every answer; risk scores list their signals.
- No write actions: assistants return links to the pages where a human acts.
- Compliance rules are never bypassed: payroll and statutory figures come from the payroll domain unchanged.
- High-impact decisions: attrition risk and audit findings are labelled indicators, not decisions.

## Conventions

- Add an assistant by implementing `Assistant`, registering it in `AiGateway::ASSISTANTS` and `peopleos.ai.assistants` (label, permission, feature).
- Intent matching is keyword-based (`Intents::detect`); prefer adding keywords over adding assistants.
- Tests: `tests/Feature/Ai/AiTest.php` (gating, logging, each assistant, anomaly detector, attrition risk, config search, provider fake + fallback), `tests/Feature/Admin/AiPagesRenderTest.php`.

## Known gaps / deferred

- Retrieval is keyword-based; no embeddings or vector search. Conversations are single-turn (history is shown, not fed back).
- Only the Anthropic provider is implemented; OpenAI-compatible endpoints would need a second provider class.
- Anomaly rules and risk signals are static thresholds, not learned models; no drift monitoring.
- No streaming responses; the chat page waits for the full answer.
