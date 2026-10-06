# SaaS.3 — Entitlement Model

**Status:** Implemented (shadow mode). **Migration:** `2026_10_22_100001_create_entitlement_tables`. **Code:** `app/Domain/Entitlements`.

## 1. Why this shape (smallest robust model)

| Considered | Decision | Why |
|---|---|---|
| `entitlement_definitions` table | **No.** The catalogue is the `Capability` enum | Same reason as the permission catalogue: a capability exists only if code observes it. A table would let a definition exist that nothing checks. Tests keep the enum complete |
| Plans, plan versions, plan entitlements | **No** (not in scope) | Plans arrive with subscriptions (SaaS.1). Packaging is undecided (D-1). Their place in the precedence order is reserved (§4) |
| `tenant_entitlements` | **Yes** | The tenant's commercial configuration: one effective-dated value per capability at a time |
| `entitlement_overrides` | **Yes** | Exceptions with their own lifecycle (reasoned, time-boxed, revocable); kept apart so "why ALLOW?" always names the layer |
| A per-tenant profile | **Yes**: `tenant_entitlement_profiles` | It separates "unconfigured" from "configured, and this capability is absent". It also holds the configuration version and is the row configuration changes lock (never the `tenants` row) |
| `entitlement_decisions` (one row per check) | **No** | It would write on every request. Replaced by aggregated `entitlement_shadow_observations` |
| Compiled snapshots (SaaS.1 `tenant_entitlement_snapshots`) | **Not yet** | With two small tables per tenant the state is cached whole and evaluated in memory, which is cheaper and simpler. Snapshots become useful when plans, add-ons and subscriptions are compiled (SaaS.4+) |
| JSON configuration on the tenant | **No** | No history, no per-row audit, no database uniqueness |
| Platform-owned, not `BelongsToTenant` (proposed ADR-0017) | **Not adopted for entitlements** | Entitlement configuration is tenant data: the fail-closed tenant scope protects every read. Operators reach it through `runAs(tenant)`, with no `bypass()`. Only the cross-tenant shadow summary reads without a tenant, and it is on the allow-list. ADR-0017 stays open for billing records, which must outlive tenant deletion |

## 2. Entities

All four tables have `tenant_id NOT NULL` with a cascade foreign key, and use `BelongsToTenant` (fail-closed). None is written outside `EntitlementConfiguration` (configuration) or `ShadowRecorder` (observations).

### `tenant_entitlement_profiles`

| | |
|---|---|
| Ownership | The tenant's commercial state, managed by platform operators |
| Keys | `id`; UNIQUE `tenant_id` |
| Columns | `state` (`unconfigured`, `configured`); `configured_from` (business date); `version` (incremented by every change); `updated_by` → users (null on delete) |
| Effective dating | `configured_from`: before it, everything is UNKNOWN (`BEFORE_CONFIGURATION`) |
| Mutable | `state` and `configured_from` (only while the start is still in the future: a configuration that has started cannot be moved or undone in SaaS.3); `version`; `updated_by` |
| Absence | No row = unconfigured. Existing tenants have none, so nothing changes for them |
| Audit | `ENTITLEMENT_CONFIGURED` on the tenant chain and the platform chain |
| Locking | `SELECT … FOR UPDATE` on this row serialises all configuration changes of one tenant. It is created with `INSERT IGNORE` outside the transaction, so two first-time writers never deadlock |

### `tenant_entitlements` and `entitlement_overrides`

Same shape, different meaning and rules.

| | |
|---|---|
| Ownership | Configuration: the tenant's agreed commercial terms (to be compiled from plans later). Override: a deliberate exception (enterprise uplift, pilot, hold) |
| Keys | `id`; INDEX `(tenant_id, capability, effective_from)`; UNIQUE `(tenant_id, capability, active_from)`, where `active_from` is a generated column equal to `effective_from` while the row is active and NULL otherwise |
| Columns | `capability` (catalogue key); `value_bool` (modules, features); `value_int` (limits; NULL = unlimited); `effective_from`, `effective_to` (business dates, inclusive; `effective_to` NULL = open-ended); `status` (`active`, `cancelled`); `reason` (required); `reference` (ticket, contract or deal); `created_by`; `closed_by`, `closed_at`, `close_reason`; `superseded_by` |
| Immutable | `capability`, `value_*`, `effective_from`, `reason`, `created_by` |
| Mutable | `effective_to` may only be brought earlier (to yesterday at the earliest); `status` may go `active` → `cancelled` only for a row that has not started; the closing fields |
| History rule | Changes start **today or later**. A past day never changes its answer, so "what was the tenant entitled to in February?" is always answerable |
| Overlap rule (configuration) | `set()` paints its range: a row that started earlier ends the day before; a row starting inside the range is cancelled; a row continuing past the range resumes the day after as a new row. At most one active row covers any day |
| Overlap rule (override) | An override may not overlap another active override for the same capability. "Revoke it first" keeps every exception deliberate and single |
| Revocation | A started override ends yesterday (revoked from today); one not yet started is cancelled |
| Idempotency | The same change twice (same value and dates) returns the existing row: no new row, no audit |
| Audit | `ENTITLEMENT_SET` / `ENTITLEMENT_ENDED` and `ENTITLEMENT_OVERRIDE_GRANTED` / `ENTITLEMENT_OVERRIDE_REVOKED`, each on the tenant chain and the platform chain, with the reason, reference, version and the ids of replaced rows |
| Retention | Kept: they are the commercial history (deleted with the tenant by the cascade) |

### `entitlement_shadow_observations`

| | |
|---|---|
| Purpose | Aggregated shadow decisions: what would have been denied, what could not be decided, how often, first and last seen |
| Keys | `id`; UNIQUE `(tenant_id, observed_on, capability, outcome, reason, surface)`; INDEX `(observed_on, outcome)` |
| Columns | `occurrences` (lower bound), `first_seen_at`, `last_seen_at`, `last_source`, `last_entitlement_id`, `last_override_id` |
| Never stored | Users, employees, values, salaries, tokens, secrets |
| Written by | `ShadowRecorder` only, after the response, at most once per key per window. It is an upsert, so concurrent writers never duplicate a row |
| Audit | None. This is observability, not a business record and not commercial state (allow-listed in the architecture test) |
| Retention | `retention:purge` deletes rows older than `peopleos.entitlements.shadow.retention_days` (90) |

## 3. Decision states

| Outcome | Reasons | Meaning | Shadow behaviour |
|---|---|---|---|
| `ALLOW` | `ENTITLED`, `OVERRIDE_GRANTED`, `WITHIN_LIMIT`, `UNLIMITED` | The tenant has it | Recorded; the action runs |
| `DENY` | `NOT_ENTITLED`, `OVERRIDE_DENIED`, `MODULE_NOT_ENTITLED`, `LIMIT_EXCEEDED` | It **would** be refused if enforcement were enabled | Recorded and logged; **the action runs** |
| `UNKNOWN` | `TENANT_UNCONFIGURED`, `BEFORE_CONFIGURATION`, `MODULE_UNKNOWN`, `LIMIT_NOT_CONFIGURED`, `USAGE_UNAVAILABLE`, `NO_TENANT_CONTEXT`, `EVALUATION_FAILED`, `SHADOW_DISABLED` | No commercial answer: missing configuration is never read as DENY | Recorded (failures also logged); **the action runs** |
| `NOT_APPLICABLE` | `NOT_COMMERCIAL` | The capability is not an entitlement (the core, security) | Not recorded |

Every decision also carries:
- the capability and the tenant;
- the business date and the surface;
- the source (`catalog`, `configuration`, `override`, `none`);
- the row ids behind it;
- the limit and usage for limits;
- `mode = shadow`, `enforced = false`.

## 4. Precedence (deterministic)

1. **Catalogue.** A non-commercial capability is `NOT_APPLICABLE`. This is decided before anything else.
2. **Override.** An active override covering the date decides the value. This also holds for an unconfigured tenant: an explicit decision always counts.
3. **Configuration** (from the configured-from date). The row covering the date decides. If there is no row, a module or feature is `DENY` (`NOT_ENTITLED`) and a limit is `UNKNOWN` (`LIMIT_NOT_CONFIGURED`), because no limit was agreed.
4. **Unconfigured** (or before the configured-from date): `UNKNOWN`.
5. **Then the type rules:**
   - a feature that resolved to ALLOW is checked against its module (`MODULE_NOT_ENTITLED` / `MODULE_UNKNOWN`), so a feature override alone never opens a module;
   - a limit compares the resolved value with the measured usage (at or below = ALLOW, above = DENY, no usage = UNKNOWN; no value = unlimited).

**Reserved for later.** Plan entitlements compiled from subscriptions will fill the configuration layer (step 3, source `plan`), with overrides still above them. No row in SaaS.3 comes from a plan.

**SaaS.4 update.** The plan layer now exists, between the tenant's own configuration rows and the configured default (source `plan`). Its new reasons are `NOT_IN_PLAN` and `NO_PLAN_IN_FORCE`. See [SaaS-4 Plan Model](SaaS-4-Plan-Model.md). Subscriptions are still to come; today an operator assigns the plan.

**Tie-break** if corrupt data ever produced two rows for one day: the latest `effective_from` wins, then the highest id. The configuration service and the generated-column unique index make this unreachable through the application.

## 5. Effective dating

| Rule | Value |
|---|---|
| Granularity | Business **dates** (the PeopleOS `HasEffectiveDates` convention), inclusive at both ends |
| Clock | Today's date in the application time zone (**UTC**), as every effective-dated record in PeopleOS today. For an India tenant a change dated 1 April takes effect at 05:30 IST. Tenant-local evaluation is a later decision (report §23) |
| Earliest change | Today. Back-dating is refused; past days keep their answer |
| Same-day change | Takes effect for the whole of today. Observations already recorded earlier today keep the decision they had |
| Historical question | `evaluate($capability, '2027-02-15')` reads the rows covering that date. Nothing is recomputed or rewritten |

## 6. Cache and memo

| Layer | Key | Lifetime | Invalidation |
|---|---|---|---|
| Request memo | Tenant id, inside the request-scoped `EntitlementStateStore` | One request or one queued job. Laravel forgets scoped instances per job; the store is also forgotten at the end of every handled request. Callers resolve it at call time, so no long-lived object holds it | `forget()` on every change |
| Cache | `tenant:{id}:entitlements` (default store), separate from every authorisation cache | `peopleos.entitlements.cache_seconds` (600) | Forgotten **after commit** by every configuration change |
| Database | Three indexed tenant-scoped reads | — | — |

The cached value is the whole tenant state (profile plus active rows). Any business date is evaluated in memory from it.

Measured costs:
- 3 queries on a cold cache, then none for the rest of the request;
- 0 queries on a warm cache;
- 1 query for an unconfigured tenant.

A cache failure falls back to the database. A database failure gives `UNKNOWN` (`EVALUATION_FAILED`).
