# SaaS.1 — Entitlement Architecture

**Status:** Proposed (SaaS.1, architecture only; **no enforcement is implemented in SaaS.1**) · **Date:** 6 October 2026 · **Part of:** [SaaS.1 Commercial Architecture & Gap Analysis](SaaS-1-Commercial-Architecture-Gap-Analysis.md)

## 1. The question and its answer

> *Is tenant X allowed to perform capability Y right now?*

```
allowed(X, Y, now) = AccessMode(X, now) permits Y          // State Machines §5: full / grace / restricted / locked / none
                   ∧ Entitlement(X, Y, now)               // what X has bought (plan version + add-ons + overrides), effective now
                   ∧ OperationalFlag(X, Y)                // tenant opt-out / platform kill switch (today's tenant_features)
                   ∧ Limit(X, Y) not exhausted            // for count- and consumption-limited capabilities
and, for a user action, additionally
                   ∧ Permission(user, Y) ∧ Scope(user, record) ∧ FieldSecurity(user, field)   // unchanged security chain
```

Each term has exactly one owner, and none of them knows how the others are computed.

**HCM modules ask one question through one contract:**

```php
if (! $entitlements->allows('payroll')) …          // GOOD
if ($tenant->subscription->plan === 'enterprise') … // never: HCM never sees plans, prices or billing
```

## 2. What exists today, and how it maps

| Today (verified) | What it is | Target role |
|---|---|---|
| Permissions (`config/peopleos.php` catalogue, roles, `User::hasPermission`, `Gate::before`, `PermissionPolicy`) | **Who** inside a tenant may do what | Unchanged. Entitlements sit **under** permissions: a missing entitlement removes the permission's effect (§6.1) |
| Feature flags: 8 keys in `peopleos.features`; `tenant_features` rows; `FeatureFlags` (per-tenant cache, request memo); toggled by `features.update` holders | Tenant-toggleable switches. Two (`organisation.designer`, `security.mfa`) are never read (Threat Model W13). Not commercial: they "exist but are not commercial entitlements" (UX.19 report §31) | Become the **operational layer**: a tenant may switch **off** an entitled capability, never **on** an unentitled one. A new platform-level kill switch can force a capability off for a tenant (incident response); today the platform cannot lock a flag |
| `tenants.status` (`trial` / `active` / `suspended`); `allowsAccess()` = not suspended; checked by `canAccessPanel`, API keys, `BindTenantContext`, `TenantRunner` | A coarse on/off for the whole tenant | Replaced as input by the derived **access mode** (State Machines §5) |
| `tenants.tier`, `region`, `trial_ends_at` | Stored metadata, never read for behaviour (and dropped on create, Threat Model W14) | Superseded by subscription and residency models; read-only, then deprecated (Gap Analysis §20) |
| Rate limits: API 120/min per key (config key undefined, fallback used); AI 20/min per user in `AiGateway`; an unused `ai` limiter | Abuse protection | Stays as abuse protection. Plan quotas are a separate concept (§8), enforced by the entitlement gate, not the throttle |
| Usage: `ai_interactions` (tokens, latency), `api_keys.last_used_at`, `ux_metrics` daily counters, per-file `size_bytes` on some tables | Logs and telemetry, not metering | Sources for usage events (§8); `ux_metrics` stays product telemetry |

## 3. The capability catalogue (code-owned, like permissions)

Capabilities are declared in `config/peopleos.php` under a new `capabilities` key, beside `permissions`. They are validated by tests, and plans reference them by key. The catalogue is code, not a table, for three reasons:
- capabilities are what the code can enforce;
- a capability nobody enforces must not be sellable;
- the existing permission catalogue made the same choice.

**Modules.** A module is a group of permission groups, API scopes, navigation, jobs and schedules. The groups below are the permission groups that exist today (`config/peopleos.php:140-497`).

| Capability | Permission groups it governs | API scopes | Always on? |
|---|---|---|---|
| `core` | tenant, company, user, role, settings, features, audit, organisation, people_setup, legal_structure, custom_field, form, policy, configuration, blueprint, workflow, task, document, employee, notification | employees.*, organisation.read, documents.read, workflows.read | **Yes**: the HCM core and every security control |
| `onboarding` | onboarding, bgv | bgv.write, rms.* (pre-employee ingress) | Plan-dependent |
| `attendance` | attendance | attendance.* | Plan-dependent |
| `leave` | leave | leave.* | Plan-dependent |
| `payroll` | payroll, compliance (statutory) | payroll.read, compliance.read | Plan-dependent |
| `compensation` | compensation | compensation.* | Plan-dependent |
| `performance` | performance | performance.* | Plan-dependent |
| `learning` | learning, skills, development | learning.* | Plan-dependent |
| `talent` | career, talent, succession | career.read, talent.read, succession.read | Plan-dependent |
| `workforce` | workforce | positions.read, workforce.* | Plan-dependent |
| `assets` | asset | assets.read | Plan-dependent |
| `service_desk` | servicedesk, grievance, kb | servicedesk.read | Plan-dependent |
| `engagement` | engagement, communication | engagement.read, communications.read | Plan-dependent |
| `exit` | exit, letter, alumni | — | Plan-dependent |
| `analytics` | analytics | reports.run | Plan-dependent (basic reports may stay in `core`; decision D-1) |
| `ai` | ai | — | Plan-dependent; also gated by the existing AI data policy |
| `integrations` | api_key, integration | integrations.*, webhooks.* | Plan-dependent |
| `enterprise_identity` | enterprise: sso, scim (security policy stays in `core`) | scim | Plan-dependent |
| `warehouse` | enterprise: warehouse | — | Plan-dependent |

**Features** (booleans inside modules) are added only when code enforces them. Candidates:
- `ai.llm` (external language model; today a flag);
- `configuration.approval` (today a flag);
- `organisation.designer` (today a dead flag: becomes sellable only once enforced);
- `reports.scheduled`, `workflow.custom`, `sso.enforce`.

**Limits** (integers; null = unlimited):

| Capability | Counted from (authoritative) | Kind |
|---|---|---|
| `employees.active` | `employees` in employed lifecycle states, tenant-wide (§8) | Count |
| `users.active` | `users` with status active in the tenant, excluding alumni-only users | Count |
| `users.admin` | Active users whose permissions include administration keys (`configuration.publish`, `user.assign_roles`, `settings.update`, `security.manage`), derived from permissions, never role names | Count |
| `users.hr` | Active users holding HR-operator permissions (`employee.update`, `servicedesk.agent`, `onboarding.manage`), the same signals `RoleLens` uses | Count |
| `companies`, `legal_entities`, `locations` | Active organisation rows | Count |
| `payroll.population` | Employees in a payroll run's population at calculation | Count (per run) |
| `storage.bytes` | Sum of stored file sizes per tenant (needs a storage ledger, §8.3) | Consumption |
| `ai.requests.monthly`, `ai.tokens.monthly` | AI usage events | Consumption |
| `api.requests.monthly` | API usage events | Consumption |
| `webhooks.endpoints`, `integrations.systems` | Configured rows | Count |

**Principle: security is never an upsell.** MFA, audit, the hash chain, encryption, sensitive-access auditing, the IP allow-list, data export for offboarding and tenant isolation are in `core` for every plan. `audit.sensitive_access` is today a tenant-switchable flag. It must become a non-commercial security setting that is never part of a plan.

## 4. Resolving what a tenant is entitled to

**Sources, in order of precedence** (later wins for the same capability):
1. **Plan version entitlements** of the subscription's base item effective on the date (`plan_entitlements`). During `trialing`, the trial values (`trial_value_*`) replace them.
2. **Add-on entitlements** of add-on items effective on the date:
   - booleans OR;
   - limits add (`value_int` is an increment);
   - "unlimited" (null) wins.
3. **Entitlement overrides** effective on the date (`entitlement_overrides`):
   - `operation = set` replaces;
   - `operation = add` increments.

   Overrides are the enterprise and support lever. They are time-boxed, reasoned and audited.
4. **No subscription at all** (legacy and migration window): the **default entitlement set** of the migration strategy (Gap Analysis §20), never "everything by accident".

**Compilation.** `EntitlementCompiler::compile(tenant)` reads the sources and computes the effective set for every interval in which the inputs are constant. It writes `tenant_entitlement_snapshots` rows (Domain Model §7) with a hash of the inputs. It runs:
- on every subscription transition, item change, override grant or revoke, and plan migration (in the same transaction, so state and entitlements never disagree);
- daily, as an integrity check that recompiles and compares hashes (P2).

**Effective dating.** A downgrade scheduled for 1 November produces a snapshot row effective from 1 November at compile time. Nothing has to "remember" to switch at midnight: the evaluator reads the row effective now. Past snapshots are never rewritten, so "what was the tenant entitled to on date D" is a lookup.

**Grandfathering.** Snapshots derive from the plan versions **pinned on subscription items**. Publishing plan v3 changes nothing for subscribers on v1 or v2 until an explicit plan migration moves them (Gap Analysis §10).

## 5. The contract

```php
// app/Domain/Commercial/Entitlements/Contracts/Entitlements.php (target; not created in SaaS.1)
interface Entitlements
{
    /** Module / feature check for the bound tenant: access mode ∧ entitlement ∧ operational flag. */
    public function allows(string $capability): bool;

    /** The same, with the reason, for messages, logs and the control plane. */
    public function decide(string $capability): Decision;   // allowed + reason: not_in_plan | lapsed_read_only | flag_off |
                                                            // access_restricted | access_locked | limit_reached | trial_expired
    /** Module mode for the read-only-after-lapse rule (§9): enabled | read_only | off. */
    public function mode(string $module): ModuleMode;

    /** Limit value (null = unlimited) and current usage, for UI and API. */
    public function limit(string $limit): ?int;
    public function usage(string $limit): int;

    /**
     * Atomic count-limit enforcement: inside $work's transaction, lock the tenant's limit row, count the authoritative
     * source, refuse with LimitReached if count + $units > limit, else run $work. Used by domain actions (§6.3).
     */
    public function within(string $limit, int $units, \Closure $work): mixed;

    /** Consumption limits (AI, API, storage): check the period aggregate, then record a usage event idempotently. */
    public function consume(string $meter, int $quantity, string $idempotencyKey): Decision;
}
```

**`Decision` is a value object** (`allowed`, `reason`, `capability`, `limit`, `usage`, `snapshot_version`), so every refusal can say why:
- "Payroll is not part of your plan";
- "You have reached 100 active employees; your plan allows 100";
- "Your trial ended on 3 November".

The same object feeds the log line, the audit metadata and the UI message.

**Determinism.** `allows()` and `decide()` are pure functions of (snapshot row, access mode, operational flags) once those are loaded. They are unit-testable without a database.

## 6. Where enforcement happens

Each layer has a job. **No single layer is the boundary.**

### 6.1 Permission layer: module licensing (broadest coverage)

- **How it works.** Every permission key belongs to a permission group, and every group belongs to a module (§3). When a user's permission keys are loaded (`User::permissionKeys()`, cached per user instance today), keys of modules in mode `off` are dropped. Keys that write to modules in mode `read_only` are dropped too.
- **Why this layer.** Every existing check goes through it:
  - `Gate::before` → `hasPermission`;
  - the `PermissionPolicy` family;
  - the 63 Filament `canAccess` overrides that call `can('<perm>')`;
  - `ModuleCatalogue` navigation;
  - command search;
  - the AI assistants' permission gates.
- **What it adds.** No per-check cost: the filter runs once per user per request.
- **What it needs.** A `kind` (read or write) on each permission in the catalogue, for read-only mode. This is a one-off classification of the 254 keys in 49 groups, verified by a test that every key is classified.
- **Platform administrators** (`Gate::before` returns true) are **not** filtered for platform abilities. Inside a tenant they see what the tenant is entitled to (decision D-15: whether operators may act on unentitled modules for support).

### 6.2 API layer

- **How it works.** `AuthenticateApiKey` maps the route scope to its module (§3) and refuses with 403 (`code: not_in_plan`) when the module is `off`. A read scope is allowed when `read_only`.
- **Metering.** It records an API usage event keyed on the request id (§8).
- **Rate limit.** Stays as abuse protection.

### 6.3 Domain actions: count limits (the only correct place for counts)

The limit check must sit in the **same transaction** as the write, under a lock, on **every path** that increases the count:

| Limit | Choke point (verified paths) | Lock |
|---|---|---|
| `employees.active` | **`LifecycleEngine::transition()`** whenever the transition moves an employee from a non-employed to an employed state (`isEmployed()`). This single point covers: `HireEmployeeAction` (UI hire wizard, `POST /api/v1/employees`, CSV import rows), "Mark as joined" (pre-employee/preboarding → joined), the generic lifecycle UI and `POST employees/{id}/lifecycle`, `RehireEmployeeAction` and alumni → active, and resignation withdrawn → active. `CreatePreEmployeeAction` (RMS hand-over) creates `pre_employee` rows that do not count until they join | Per-tenant limit row, `SELECT … FOR UPDATE`. The hire path already serialises auto-coded hires on `employee_code_sequences`, but explicit codes and lifecycle moves do not, so a dedicated lock is needed |
| `employees.active` (bulk) | `EmployeeImports::approve()` knows `create_count` before running: it **reserves** the headroom up front and fails fast with the number that fits. Each row still passes the engine check, so concurrency stays safe | As above |
| `users.active`, `users.admin`, `users.hr` | Every path that creates or activates a user: Filament `CreateUser` (no transaction today), SSO just-in-time (`Sso.php`), SCIM create, replace and patch to active, provisioning's first admin, and exit → alumni user activation (excluded from `users.active` by definition). Role grants that confer admin or HR permissions are checked against `users.admin` / `users.hr` at role assignment | Per-tenant limit row |
| `companies`, `legal_entities`, `locations` | The organisation create actions | Per-tenant limit row |
| `payroll.population` | `PayrollRuns::calculate()`, before calculation starts (already under a run lock). Never at finalisation: a calculated run may always be finalised (statutory safety, decision D-7) | Run lock (exists) |
| `storage.bytes` | A single `StoredFiles` service that every upload path calls. Today uploads are written by several services with inconsistent prefixes and sizes (§8.3); the storage ledger comes first | Ledger row |
| `ai.*` | `AiGateway::ask()`, already the single choke point with a per-user rate limit | Aggregate check + idempotent usage event |
| `webhooks.endpoints`, `integrations.systems` | Their create services | Per-tenant limit row |

**Why the lock.** "Concurrent employee creation cannot exceed the limit" is a required test (Gap Analysis §21). Without a lock, two hires both read 99 of 100 and both commit. With the per-tenant row lock, the second waits, re-counts and is refused. The authoritative count is always taken inside the lock from the HCM table, not from a cached counter, so it can never drift.

**Counting outside user scope.** `Employee` carries the organisation `AccessScope`. A count in a scoped user's request would count only what that user sees. Commercial counts always use the tenant-wide query, without `AccessScope` but **with** the tenant scope, through one `BillableUnits` service (§8.1).

### 6.4 Middleware and session: access mode

- **Where it is checked.** `ResolveTenant` and `canAccessPanel` (panel and Livewire requests), `AuthenticateApiKey` (API), and the download and attachment routes, which today miss the tenant-status check (Threat Model W5).
- **What the check asks.** The access mode (`full`, `grace`, `restricted`, `locked`, `none`), not just "suspended".
- **`restricted` mode** is enforced through §6.1: every module becomes `read_only` except billing, export and employee self-service reads.

### 6.5 Jobs and scheduler

- **Jobs.** `BindTenantContext` checks the access mode instead of only `Suspended`: `locked` and `none` skip, `restricted` runs only read or statutory-completion jobs.
- **Scheduler.** Commands that sweep a module (payroll and learning reminders, attendance processing, engagement…) ask `allows('<module>')` per tenant inside `TenantRunner` and skip quietly when the module is `off`.
- **Retention purge** keeps running for every tenant (an obligation), as today.

### 6.6 UI: explanation only

- **Navigation, buttons and pages** read `decide()` to hide unentitled modules and explain refusals ("Not in your plan", with a link to billing for owners).
- **The UI is never the boundary.** A direct URL to an unentitled Filament page is refused by §6.1, because the page's `canAccess` uses a permission that is filtered out.

### 6.7 Not to be the sole enforcement point

| Layer | Why it is insufficient alone |
|---|---|
| UI, navigation, `ModuleCatalogue` | Hidden is not forbidden (security invariant 9). API, jobs, imports and direct URLs bypass it |
| Middleware only | Does not see jobs, imports, scheduled sweeps or service calls between modules; cannot count |
| Policies only | Do not cover counts, jobs or consumption; record-level only |
| Model events (`creating` / `saving`) only | Cannot hold the right lock or explain a refusal; can be bypassed with `saveQuietly` or query-builder inserts. Acceptable only as a **backstop** that throws if a count limit is exceeded without a reservation |
| Cached counters only | Drift from the authoritative table; race conditions |
| Front-end | Never a control |

## 7. Caching and performance

UX.18 measured every role surface at 10,585 employees, and UX.19 proved it did not regress. Commercial checks must add **no measurable cost** to an HCM request.

| What | Where | Cost per request |
|---|---|---|
| Entitlement snapshot | Cache key `tenant:{id}:entitlements:v{version}` (version from a tiny `tenant:{id}:entitlements:version` key or the snapshot row); memoised in a request-scoped service registered with the existing `EXPERIENCE_SCOPED` reset (`AppServiceProvider`). Queue workers already forget scoped instances per job | One cache read, or one indexed row on a cold cache, per request |
| Access mode | Derived from tenant and subscription status, cached with the snapshot (same version bump on any transition) | Same read |
| Permission filtering | Applied once when `permissionKeys()` is built (already cached per user instance) | Zero per check |
| `allows()` | Array lookup on the memoised snapshot | O(1) |
| Count limits | Only inside the creating action, already in a transaction | One locked row + one indexed count per **write**, never per read |
| Usage shown to owners | Daily aggregates (`usage_aggregates`), not live counts | One row |

**Invalidation.** Any compile writes the new snapshot and bumps the version **after commit**. The next request reads the new version, and old keys expire. A missed bump can at worst serve the previous snapshot until the TTL (proposed 10 minutes), and the daily integrity recompile catches drift. Enforcement of **count** limits never relies on the cache: it always counts under lock.

**Database indexes needed** (later phases):
- `employees (tenant_id, lifecycle_state)`: verify whether the existing composite indexes cover the billable count;
- `users (tenant_id, status)`;
- the snapshot `(tenant_id, effective_from)` index (Domain Model §7).

## 8. Units, counting and metering

### 8.1 What counts

| Term | Definition in PeopleOS | Counts toward |
|---|---|---|
| **User** | A login (`users` row with this `tenant_id`) | `users.active` if status active, excluding alumni-only users (exit → alumni activates a user with the `alumni` role, `Exits.php:289-295`). Platform administrators (`tenant_id` null) never count. API keys never count |
| **Seat** | A commercial unit of access. **Recommendation:** do not sell generic seats; sell **active employees** and, where needed, **admin seats** | `users.admin` if the plan limits admins |
| **Employee** | The one lifetime `employees` row per person per tenant (unique `tenant_id + person_id`; ADR-0002) | Not directly |
| **Active employee** | An employee whose lifecycle state is employed: `onboarding`, `joined`, `probation`, `confirmed`, `active`, `on_leave`, `suspended`, `notice_period` (`LifecycleState::isEmployed()`). Not `pre_employee`, `preboarding`, `exited` or `alumni` | **`employees.active`**: the recommended billing metric (decision D-1, D-13) |
| **Admin** | A user with administration permissions (§3) | `users.admin` |
| **Manager** | An employee with current reports (derived from relationships) | Nothing extra: managers are employees |
| **HR user** | A user with HR-operator permissions | `users.hr` (if limited) |
| **Payroll user** | A user with `payroll.calculate` or `payroll.approve` | Nothing extra unless a plan sells payroll operator seats (decision D-14) |

**One person, one lifetime record:**
- A rehire reactivates the **same** employee, so it is the same unit, never a second one.
- Leaving and returning within one billing period does not create two units. With peak-based billing (D-13) it counts once.
- No commercial rule may create or require a second employee record.

**One canonical counter.** The codebase has three headcount definitions today:
- `WorkforceMetrics` excludes `pre_employee` and `preboarding`, then filters by dates;
- the payroll population excludes `pre_employee`, `alumni` and two non-existent state names, and includes preboarding with a null joining date;
- `isEmployed()`.

Commercial counting must not inherit that ambiguity. `BillableUnits::activeEmployees(tenant, at)` is the single definition, built on `isEmployed()`. It is tenant-wide and without `AccessScope`. It is used for enforcement, daily sampling, invoices and the owner's usage page. Analytics keeps its own definitions; they answer a different question.

### 8.2 Count meters (sampled)

- **Daily sample.** A daily job (`SampleBillableUnits`, per tenant via `TenantRunner`) writes `usage_aggregates(period_type = day)` for `employees.active`, `users.active` and `users.admin`.
- **Billing quantity.** The quantity for a period is derived from the samples: the peak (recommended for monthly plans) or the average (decision D-13).
- **Late samples.** A late sample (missed run) is recomputed from the lifecycle transitions table, which is append-only and dated. The count on any past day is therefore reproducible: authoritative, not estimated.

### 8.3 Consumption meters (events)

| Meter | Event source | Idempotency key |
|---|---|---|
| `ai.requests`, `ai.tokens` | `AiGateway::ask()` after the interaction row is written (tokens are non-zero only for external model calls today) | `ai_interaction:{id}` |
| `api.requests` | `AuthenticateApiKey` after the response (terminable middleware) | `api_request:{request_id}` |
| `storage.bytes` | A new `StoredFiles` ledger (`+size` on store, `-size` on delete) written by every upload path | `file:{disk}:{path}:{op}` |
| `notifications.sms` (if billed later) | `DeliverNotification` on success | `delivery:{id}` |

**Storage needs groundwork first.** Uploads are stored by many services. Six paths use `tenants/{id}/…`; learning evidence, certificates, statutory exports and compliance evidence use other prefixes; warehouse files use the tenant slug. Several tables record no size (grievance notes, announcements, certificates, report runs, statutory exports). A storage ledger and a uniform tenant prefix are prerequisites for both storage limits and tenant export and deletion (Gap Analysis G-MET-3, G-OFF-4).

**Aggregation.**
- `AggregateUsage` (every 15 minutes, per tenant) folds new events into `usage_aggregates(period_type = month)` by `last_event_id`.
- Consumption checks read the aggregate plus a small in-memory tolerance. AI and API quotas are soft by nature: a burst may exceed the quota by the events in flight, which is acceptable and documented.
- **Hard** stops apply only to counts (§6.3).

**MySQL or Redis?** MySQL is authoritative: events and aggregates live there, and invoices reference aggregates. Redis (or the cache) may hold a per-minute hot counter for API quotas later, if event writes become a bottleneck (P3). It is never authoritative and is always reconciled from events. **Recommendation for the first implementation:** MySQL only. The API write volume is bounded by the existing 120/min-per-key throttle.

## 9. Limit breaches, downgrades and lapses

| Situation | Behaviour |
|---|---|
| Hard limit reached | The action is refused with the reason, and owners are notified once per day. Existing data is untouched |
| Soft limit reached (`limit_mode = soft`, decision D-16) | Allowed. Warnings at 80 % and 100 %; overage priced on the next invoice from the plan version's overage price |
| Downgrade below current usage | Refused at request time, with the reason (Billing doc §3) |
| Module removed (downgrade or lapse) | Mode `read_only` for the retention window: historical data stays readable and exportable, nothing new can be created. Then `off`. Data is **never deleted** by a downgrade |
| Trial expired, suspended, past due beyond grace | Access mode (State Machines §5) |
| In-flight statutory work when a module lapses | A calculated payroll run may be finalised, and a started statutory return completed (decision D-7). New runs refused |

## 10. Tests the implementation must ship

- **Resolver unit tests:** precedence (plan < add-on < override), trial values, effective-dated rows, unlimited, determinism (same sources, same hash).
- **Enforcement matrix** (a test per row of §6.3): every path that can increase a count is refused at the limit, including API, import, rehire and "mark joined"; concurrent hires cannot exceed the limit (MySQL concurrency suite).
- **Permission filtering:** an unlicensed module's pages, resources, API scopes, scheduled sweeps, command search entries and AI assistants are all refused. Read-only mode allows reads and refuses writes.
- **Cache isolation:** tenant A's snapshot is never served to tenant B (same worker, consecutive jobs; consecutive requests).
- **Performance guard:** the UX.18 query-count tests (`Ux18PerformanceTest` and the scale tests) keep their counts; entitlement checks add zero queries per page after warm-up.
