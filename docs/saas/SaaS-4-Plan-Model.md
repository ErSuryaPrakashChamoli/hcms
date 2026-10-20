# SaaS.4 — Commercial Plan Model

**Status:** Implemented (shadow mode; nothing is enforced).
**Migration:** `2026_10_23_100001_create_commercial_plan_tables` (additive).
**Code:** `app/Domain/Entitlements` (`Plan`, `PlanVersion`, `PlanEntitlement`, `TenantPlanAssignment`, `PlanCatalog`, the plan layer of `EntitlementEvaluator`).

## 1. Why this shape (smallest model that can grow)

| Considered | Decision | Why |
|---|---|---|
| A plan as one row with a JSON list of capabilities | **No** | It could not keep history, could not be constrained in the database (one value per capability), and would invite editing a plan tenants are on |
| `plans` + `plan_versions` + `plan_entitlements` (SaaS.1 domain model §3) | **Yes**, without prices | Plan versions are SaaS.1's unit of commercial terms. Prices are excluded (D-1 open, brief), and so are trial terms (D-4) and dunning (WS5) |
| `plans.status` stored (draft / active / retired) | **Derived instead** | The ADR-0009 vocabulary (Draft → Scheduled → Active → Superseded → Archived) follows from the version statuses and the sale window. A stored copy could drift |
| Version status: draft / published / retired | **Yes**: the existing `VersionStatus` enum | The same lifecycle as policy, form and workflow versions (ADR-0009). *Scheduled*, *active* and *superseded* are derived (`PlanState`) |
| `kind` (base / add-on), `visibility`, `sort`, `markets` | **Not yet** | Add-ons need subscriptions with several items (WS3). Visibility and markets serve self-service and pricing (WS5, WS6). Each is an additive column later |
| Tenant → plan through subscription items | **Not yet**: `tenant_plan_assignments` | Subscriptions are excluded by the brief (WS3). The assignment pins a version exactly as a subscription item will, so a subscription can later write assignments, or replace them as the source, without changing the engine |
| Compiled snapshots | **Not needed** | Published versions are immutable, so the tenant's state can cache them whole (ADR-0033). Snapshots stay an option for add-ons and subscriptions |
| A default "Legacy" plan for existing tenants (proposed ADR-0026) | **Not built** | The brief keeps unconfigured tenants UNKNOWN. A Legacy plan can be created and assigned explicitly by an operator if the owner approves it |

## 2. Entities

### `plans` (platform catalogue, no tenant)

| | |
|---|---|
| Keys | `id`; `code` UNIQUE (2–64 characters: lower-case letters, digits, `-`, `_`; starts with a letter) |
| Columns | `name` (≤ 120), `description` (≤ 500), `created_by`, `updated_by`, timestamps |
| Immutable | `code` (model guard). Plans are never deleted (model guard) |
| Mutable | `name`, `description` (`PlanCatalog::update`, reasoned, audited with before and after) |
| Audit | `PLAN_CREATED`, `PLAN_UPDATED` on the platform chain |

### `plan_versions`

| | |
|---|---|
| Keys | `id`; UNIQUE `(plan_id, version)`; UNIQUE `draft_plan_id` (a generated column equal to `plan_id` while the status is `draft`, so there is at most one draft per plan); INDEX `(plan_id, status)` |
| Columns | `version`, `status` (`draft`, `published`, `retired`), `effective_from` / `effective_to` (sale window, inclusive business dates; set when published), `change_note`, `created_by`, `published_by` / `published_at`, `retired_by` / `retired_at` |
| Immutable after publication | Everything except `status` (published → retired only), `effective_to` (set when a later version is published) and the retirement fields (model guard). Versions are never deleted |
| Audit | `PLAN_VERSION_DRAFTED`, `PLAN_VERSION_EDITED` (each capability's before and after), `PLAN_VERSION_PUBLISHED` (the full content and the versions it supersedes), `PLAN_VERSION_RETIRED` |

### `plan_entitlements`

| | |
|---|---|
| Keys | `id`; UNIQUE `(plan_version_id, capability)` |
| Columns | `capability` (a key of the `Capability` enum), `value_bool` (modules and features), `value_int` (limits; NULL = unlimited) |
| Meaning | `true`: included. `false`: explicitly excluded (never for a protected capability). A limit value N: limited to N (never 0 for the protected limit). NULL: unlimited. **No row: not in the plan** for a module or feature; **not set** (no agreed limit) for a limit. SaaS.5: a limit of a commercial module is **not included** while that module is not, and publication refuses inconsistent packages (ADR-0035, ADR-0036) |
| Immutable | While its version is not a draft (model guard on save and delete) |

The catalogue (identity, type, module, enforcement class, permission and API-scope ownership) stays in the code-owned `Capability` enum; a plan row only stores the plan's value.

### `tenant_plan_assignments` (tenant data, `BelongsToTenant`)

| | |
|---|---|
| Keys | `id`; UNIQUE `(tenant_id, active_from)` (generated: `effective_from` while active); INDEX `(tenant_id, effective_from)`, `(plan_version_id, status)` |
| Columns | `plan_version_id`, `effective_from` / `effective_to` (inclusive; NULL = open-ended), `status` (`active`, `cancelled`), `reason`, `reference` (contract or deal), `created_by`, `closed_by` / `closed_at` / `close_reason`, `superseded_by` |
| Immutable | `tenant_id`, `plan_version_id`, `effective_from` (model guard). Ending sets `effective_to`; a row that has not started is cancelled |
| Rules | One active assignment covers any day (painted like the SaaS.3 configuration rows). Changes start today or later. The version must be published (not draft, not retired) and on sale on the first day |
| Audit | `PLAN_ASSIGNED` (field `plan`: previous → new, effective date, reference) and `PLAN_ASSIGNMENT_ENDED` (field `effective_to`), each on the tenant chain and the platform chain |

### Columns added to SaaS.3 tables

| Column | Why |
|---|---|
| `tenant_entitlement_profiles.has_plan_assignments` (default false) | The state loader reads plans only for tenants that have had one, so the SaaS.3 read counts are unchanged for the rest |
| `entitlement_shadow_observations.last_assignment_id` | Which plan assignment produced the last observed decision |

## 3. Lifecycle

```
Plan version:   draft ──publish(from)──► published ──retire──► retired
                                            │
                derived on a day:  scheduled (from > day) · active (on sale) · superseded (sale ended)
Tenant:         (no plan) ──assign(v, from[, to])──► on v ──assign(w, later)──► on w …  ──end──► no plan in force
```

| Operation | Allowed when | Effect |
|---|---|---|
| `create` | Always (operator) | Plan + empty draft v1 |
| `draft` | No draft exists (otherwise returns it) | v(n+1) copying the latest version |
| `define` / `set` / `remove` | The version is a draft | Under the plan's lock (read, check, write): rows created, updated or deleted; one audit event with every change |
| `publish(from)` | Draft with at least one row; `from` is today or later and after every published version's start | Frozen; on sale from `from`; previous published versions end their sale the day before |
| `retire` | Published | No new assignments; assigned tenants keep it |
| `assignPlan(v, from, to)` | `v` published and on sale on `from`; `from` is today or later | Painted over earlier assignments; the cache is forgotten after commit |
| `endPlanAssignment(a, last)` | `last` is yesterday or later | Ended (or cancelled if not started) |

## 4. How a decision is taken (unchanged engine, one more layer)

1. **Catalogue**: the core and security are `NOT_APPLICABLE`.
2. **Override** covering the day.
3. **The tenant's own configuration row** (from its configured-from date).
4. **Plan**: the assignment covering the day, then its version's row:
   - included → `ALLOW` / `ENTITLED`;
   - excluded → `DENY` / `NOT_ENTITLED`;
   - no row → `DENY` / `NOT_IN_PLAN`;
   - limits compare with usage; no row → `UNKNOWN` / `LIMIT_NOT_CONFIGURED`.
5. **Configured without a row**: as SaaS.3 (`DENY` / `NOT_ENTITLED`, or `UNKNOWN` for limits).
6. **Otherwise `UNKNOWN`**:
   - `NO_PLAN_IN_FORCE` if a plan had started but none covers the day;
   - `BEFORE_CONFIGURATION` if configuration or a plan starts later;
   - `TENANT_UNCONFIGURED` if there is no configuration and no plan at all.

A feature still requires its module. Every decision from the plan layer carries:
- `source = plan`;
- the assignment id;
- the plan version id;
- `enforced = false`.
