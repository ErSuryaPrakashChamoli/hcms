# SaaS.3 — Working Baseline

Recorded on 6 October 2026, before any SaaS.3 code change.

| Item | Value |
|---|---|
| Branch | `feature/oct_1_phase_1` |
| HEAD | `71c5f7f` (SaaS.2 report), as the brief requires |
| Working tree | Clean |
| Work after SaaS.2 | None. HEAD is the SaaS.2 documentation commit |
| Showcase server | `127.0.0.1:8090` serves this working tree against `hcm_ux_showcase`, which still has not run the SaaS.2 migration (SaaS.2 report §21). Not touched |

## What exists that the entitlement engine sits beside

| Area | State at `71c5f7f` (verified) | Consequence for SaaS.3 |
|---|---|---|
| Commercial model | None. No plans, subscriptions, billing, entitlements or metering (SaaS.1 §2, SaaS.2 §20) | The engine must work with **no commercial configuration** and must not invent plans |
| Feature flags | 8 keys in `peopleos.features`, rows in `tenant_features`, `FeatureFlags` (per-tenant cache plus request memo). Read in code: `ai.llm`, `ai.payroll_auditor`, `ai.workforce_intelligence`, `configuration.approval`, `audit.sensitive_access`; `ai.assistants`, `organisation.designer` and `security.mfa` are never read | Flags stay operational switches; analysed, not migrated (SaaS.3 report §29 analysis) |
| `tenants.tier`, `region`, `trial_ends_at` | Persisted (SaaS.2), read by nothing | Not interpreted by the engine |
| Permissions | 254 keys in 49 groups, 51 key prefixes; `User::permissionKeys()` cached per user; `Gate::before` for operators | Authorisation is untouched; capabilities map onto key prefixes for the future |
| API scopes | 37 scopes on `AuthenticateApiKey` routes | Each scope maps to one capability |
| Audit | Hash-chained `AuditRecorder`; `platform: true` writes Markedge's chain (SaaS.2) | Configuration changes audited on both chains |
| Cache | Per-tenant keys (`tenant:{id}:…`) on the default store; request memos (`FeatureFlags`, `SettingsRepository`, the latter process-wide and flushed per request, job and boot) | The entitlement state follows the `SettingsRepository` pattern |
| Queued work | `TenantAwareJob` + `BindTenantContext`; `TenantRunner` for scheduled sweeps; Laravel `defer()` runs after the HTTP response, at command end and after each worker job | Shadow observations are written deferred, off the request path |
| Effective dating | `HasEffectiveDates`: `effective_from` / `effective_to` business dates, both inclusive, evaluated against today's date in the application time zone (UTC) | Same convention for entitlements |
| Choke points for instrumentation | `PayrollRuns::open/calculate/finalize`, `PunchIngestion::record`, `AttendanceProcessor::process`, `Leaves::request`, `Goals::create`, `Appraisals::launch`, `Learning::enrol`, `ReportRunner::execute`, `AiGateway::ask`, `AuthenticateApiKey`, `Webhooks::publish`, `LifecycleEngine::transition` | One observation per business action, not per UI element |

## Decisions inherited and not converted

| Source | Status | Use in SaaS.3 |
|---|---|---|
| ADR-0017 (commercial records platform-owned, not `BelongsToTenant`) | Proposed | **Not adopted for entitlement configuration** (SaaS.3 report §17 explains why); still open for billing records |
| ADR-0018 (entitlement contract) | Proposed | Implemented in shadow form; accepted only when enforcement lands |
| ADR-0021 (plan versioning) | Proposed | Not touched: no plans in SaaS.3 |
| ADR-0026 (Legacy plan backfill, off → observe → enforce) | Proposed | **The Legacy-plan backfill is not done.** Existing tenants stay *unconfigured* and evaluate to UNKNOWN. The observe stage is what SaaS.3 builds |
| D-1 (pricing metric, packaging), D-4 (trials), D-13 / D-14 / D-16 (units, seats, limit modes) | Unresolved | No package, plan, trial or limit value is invented; only the mechanism |
