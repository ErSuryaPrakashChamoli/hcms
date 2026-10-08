# SaaS.5 — Working Baseline (discovery)

Recorded on 6 October 2026, before any SaaS.5 code change. Every row was checked against the repository, not only against the earlier reports.

| Item | Value |
|---|---|
| Branch | `feature/oct_1_phase_1` |
| HEAD | `cf62eec` (SaaS.4 report), as the brief requires |
| Working tree | Clean; nothing committed after SaaS.4 |
| Showcase | `hcm_ux_showcase` carries every migration up to SaaS.4; 8090 is running on it |

## Current commercial schema and models (verified)

| Table | Model | Owner | What it holds |
|---|---|---|---|
| `tenant_entitlement_profiles` | `TenantEntitlementProfile` | Tenant (`BelongsToTenant`) | Configured-from date, version (the per-tenant lock row), `has_plan_assignments` |
| `tenant_entitlements` | `TenantEntitlement` | Tenant | The tenant's own effective-dated terms (SaaS.3) |
| `entitlement_overrides` | `EntitlementOverride` | Tenant | Reasoned, effective-dated exceptions (SaaS.3) |
| `entitlement_shadow_observations` | `EntitlementShadowObservation` | Tenant | Aggregated shadow decisions |
| `plans` | `Plan` | Markedge (no tenant) | Permanent code, name, description |
| `plan_versions` | `PlanVersion` | Markedge | `draft → published → retired`, sale window, immutable once published |
| `plan_entitlements` | `PlanEntitlement` | Markedge | One row per capability a version mentions: `value_bool` (modules, features) or `value_int` (limits; NULL = unlimited) |
| `tenant_plan_assignments` | `TenantPlanAssignment` | Tenant | Which published version a tenant is on, effective-dated |

**There is no price, subscription, trial, usage, invoice or payment table, and no such model.**

## Capability catalogue (code-owned `Capability` enum)

- **Not commercial:** `core`.
- **18 modules.**
- **4 features:** `ai.external_model`, `analytics.scheduled_reports`, `integrations.api`, `integrations.webhooks`.
- **8 limits.**
- **Protected:** payroll, onboarding, exit, `active_employees.max`.
- The enum already gives every limit its type, unit and module.

### The eight SaaS.3 limits as they exist today

| Key | Unit | Module (from `Capability::module()`) | Usage measured? | Where observed |
|---|---|---|---|---|
| `active_employees.max` | employees | core | **Yes**: `BillableUnits::activeEmployees()` | `employee.activate` (`LifecycleEngine::transition`), the only `observeLimit` call site |
| `users.max` | users | core | No | — |
| `admin_users.max` | users | core | No | — |
| `legal_entities.max` | legal entities | core | No | — |
| `locations.max` | locations | core | No | — |
| `storage_bytes.max` | bytes | core | No (needs the storage ledger, SaaS.1 G-MET-3) | — |
| `api_requests_monthly.max` | requests per month | **integrations** | No (needs usage events, SaaS.1 §8.3) | — |
| `ai_requests_monthly.max` | requests per month | **ai** | No | — |

These keys differ from SaaS.1's proposed meters (`employees.active`, `users.hr`, `companies`, `payroll.population`, `ai.tokens.monthly`, `webhooks.endpoints`…). The SaaS.3 catalogue is authoritative and no limit is added.

### Limit values and semantics today

| A plan (or the tenant's own terms) says… | Decision today |
|---|---|
| `value_int` N, usage at or below N | `ALLOW` / `WITHIN_LIMIT` |
| N, usage above N | `DENY` / `LIMIT_EXCEEDED` |
| N, usage not measured | `UNKNOWN` / `USAGE_UNAVAILABLE` |
| NULL | `ALLOW` / `UNLIMITED` |
| Nothing (absent from the plan) | `UNKNOWN` / `LIMIT_NOT_CONFIGURED` ("no limit agreed") |
| No plan, no configuration | `UNKNOWN` / `TENANT_UNCONFIGURED`, `BEFORE_CONFIGURATION` or `NO_PLAN_IN_FORCE` |

Two gaps against the SaaS.5 brief:
1. **"Not included" cannot be expressed for a limit.** `ai_requests_monthly.max` belongs to `ai`. On a plan without AI, the limit answers as if AI were sold (a number, or "no limit agreed"), unlike a feature, which never outlives its module.
2. **The UI says the opposite of the engine.** Platform › Plans (editor and matrix), the Entitlements page's "In the plan" column and `peopleos:entitlements:explain` label a limit absent from a plan **"not in plan"**, while the engine answers `UNKNOWN` / `LIMIT_NOT_CONFIGURED` (no agreed limit). "Not included" and "unknown" are collapsed in the presentation.

Validation today:
- a limit takes a whole number ≥ 0 or unlimited;
- a protected limit is never 0 in a plan;
- a feature can be published in a plan without its module (it then evaluates to `MODULE_NOT_ENTITLED`).

## Plan and version behaviour (verified)

- Published versions are immutable: model guards, plus an architecture test confining writes to `PlanCatalog` and `EntitlementConfiguration`.
- There is one draft per plan (a generated-column unique index), and a new draft copies the latest version.
- Draft edits read, check and write under the plan's row lock (the SaaS.4 lost-update fix).
- Tenants stay pinned to their version. The only way to move a tenant is an explicit operator re-assignment to another published version from a date. No automatic migration exists.

## Authorisation boundary today

- **The 13 HCM call sites**, plus the API middleware, use the contract only as `observe()` / `observeLimit()` statements whose result is ignored.
- **No policy, gate, permission, role or scope references the entitlement domain**, but only review enforces this (SaaS.3 invariant 39). There is **no architecture test** for it.
- Plan models are confined to the entitlement services and the two platform pages by test.

## Unresolved commercial decisions (from SaaS.1 §26, SaaS.3 §23, SaaS.4 §11)

| Decision | Can SaaS.5 proceed without it? | SaaS.5 treatment |
|---|---|---|
| D-1 pricing metric and packaging (which capabilities form which plan) | Yes: the mechanism carries any package; operators enter content | **Deferred.** No plan content is created |
| Limit values (part of D-1, D-14) | Yes | **Deferred.** No limit value is set |
| D-16 limit enforcement mode (hard, soft, grace) | Yes: modes matter only when limits are enforced (WS4) | **Deferred.** Not represented; an additive `limit_mode` column belongs with enforcement |
| D-13 billable quantity (peak or average); what "per month" means (calendar month or billing period) | Yes: no consumption meter exists | **Deferred** to metering (WS4) |
| Prices: D-1 metric, D-3 currencies and markets, billing periods offered, D-10 tax-inclusive display, D-12 free plan | Yes: nothing before subscriptions (WS3) or billing (WS5) reads a price | **Deferred.** The boundary is fixed (no price in entitlement logic); no price table is created (the brief: no tables for hypothetical billing) |
| D-4 trials | Yes | **Deferred** (subscription state, WS3) |
| D-5 / ADR-0021: moving tenants to newer versions | Yes | **Deferred** (C): ADR-0021 ties migrations to renewal boundaries, a subscription concept (WS3); the notice policy is D-5. Only explicit re-assignment exists |
| ADR-0026: default or Legacy plan | Yes | **Deferred.** Tenants without a plan stay UNKNOWN |
| D-15 / G-PLAT-1: operator roles | Yes | **Deferred.** The existing operator definition applies |
| Tenant-local business dates | Yes | **Deferred.** UTC as everywhere |

**No blocker.** Nothing in the SaaS.5 scope needs a business policy to be chosen.

## Proposed SaaS.5 scope (no schema change expected)

1. **The limit model, code-owned on the existing `Capability` enum (no second catalogue):**
   - for every limit: its key, unit and module, whether its usage is measured today, and its validation bounds;
   - whether "not included" can apply: only when its module is commercial;
   - tests tie "measured" to the actual `observeLimit` call sites.
2. **Limit semantics.** A limit never outlives its module: when the module is not entitled, the limit is `DENY` / `MODULE_NOT_ENTITLED`, exactly as for features. The outcomes then stay apart:
   - "unlimited" is `ALLOW` / `UNLIMITED`;
   - "not included" is `DENY` / `MODULE_NOT_ENTITLED`;
   - "unknown" is `UNKNOWN`, with its reason;
   - "exceeds" is `DENY` / `LIMIT_EXCEEDED`.

   The labels are corrected everywhere: "not set (no agreed limit)" versus "not included".
3. **Packaging integrity at publication** (derived from the catalogue, not a business rule): an included feature needs its module in the plan; a limit belonging to a commercial module needs that module in the plan.
4. **Pricing boundary:**
   - an ADR records that a price will be a separate, versioned definition referencing a plan version, never read by entitlement logic;
   - architecture tests keep money out of the plan schema and pricing or billing out of the entitlement domain;
   - the exact decisions needed before any price table are listed.
5. **Authorisation-boundary architecture tests:**
   - an exact allow-list of files that may use the entitlement domain;
   - HCM call sites only observe and ignore the result;
   - identity, policies and tenancy never reference it.
6. **UI:** Platform › Plans shows each limit with its unit, whether it is measured and the corrected semantics; the editor labels match the engine.
7. **Verification:**
   - tests for every point above;
   - targeted mutation testing of the security and commercial invariants;
   - MySQL races (limit edits, publication during reads and assignments);
   - performance against `cf62eec`;
   - browser, accessibility and visual regression.

## Deferred (and not to be built in SaaS.5)

- Prices, price tables, currencies, billing intervals, discounts, taxes.
- Subscriptions, trials, plan migrations, a default plan.
- Usage meters beyond the active-employee count; limit modes; enforcement.
- Billing, payments, invoices, signup.

## Outstanding operational items

| Item | State |
|---|---|
| Leaked demo credential (SaaS.2 §14) | Still active at the start of SaaS.5 (re-checked during the SaaS.4 closure). Owner action |
| Validation-message contrast 4.33:1 (design-system token) | Pre-existing, app-wide; recorded, not in SaaS.5 scope |
