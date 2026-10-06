# SaaS.4 — Working Baseline (discovery)

Recorded on 6 October 2026, before any SaaS.4 code change. Every row was checked against the repository, not only against earlier reports.

| Item | Value |
|---|---|
| Branch | `feature/oct_1_phase_1` |
| HEAD | `1f7808f` (SaaS.3 report), as the brief requires |
| Working tree | Clean |
| Work after SaaS.3 | None |
| Showcase server | Port 8090 is **not running** (it was up during SaaS.3). `hcm_ux_showcase` has not run the SaaS.2 or the SaaS.3 migration. The development database `hcm` has not run them either |

## What SaaS.4 builds on (verified in code)

| Area | State at `1f7808f` | Matches the SaaS.3 report? |
|---|---|---|
| Commercial tables | `tenant_entitlement_profiles`, `tenant_entitlements`, `entitlement_overrides`, `entitlement_shadow_observations` (migration `2026_10_22_100001`), all `tenant_id NOT NULL` + `BelongsToTenant` | Yes |
| Plan, price, subscription or billing tables | **None** | Yes |
| Entitlement services | `Entitlements` (the contract: `evaluate`, `evaluateFor`, `observe`, `observeLimit`; never throws), `EntitlementEvaluator` (pure), `EntitlementStateStore` (request memo, cache `tenant:{id}:entitlements`, 3 reads cold, 1 for an unconfigured tenant), `EntitlementConfiguration` (the only write path: platform operator + reason, today-or-later, one lock row per tenant, audit on both chains, cache forgotten after commit), `ShadowRecorder`, `EntitlementDiagnostics`, `BillableUnits` | Yes |
| Capability catalogue | `Capability` enum: `core` + 18 modules + 4 features + 8 limits; protected: payroll, onboarding, exit, `active_employees.max`; every permission prefix (51) and API scope (37) mapped once (tested) | Yes |
| Decision vocabulary | `ALLOW` / `DENY` / `UNKNOWN` / `NOT_APPLICABLE`, 17 reasons, sources `none` / `catalog` / `configuration` / `override`. The SaaS.3 model reserves source `plan` for the configuration layer | Yes |
| Commercial audit trail | `ENTITLEMENT_CONFIGURED`, `_SET`, `_ENDED`, `_OVERRIDE_GRANTED`, `_OVERRIDE_REVOKED`, each on the tenant chain and the platform chain (`AuditRecorder::record(..., platform: true)`), module `entitlements` | Yes |
| Tenant commercial fields | `tenants.tier` (infrastructure tier), `region` (residency), `trial_ends_at` (metadata): persisted, read by no commercial code | Yes. **Not repurposed** in SaaS.4 |
| Platform operators | `users.is_platform_admin` + `tenant_id IS NULL` (`User::isPlatformAdmin()`). There is **no platform permission catalogue** (SaaS.1 G-PLAT-1 deferred by SaaS.2; no `platform.*` keys in `config/peopleos.php`) | Yes |
| Platform UI | Platform group: Tenants (resource), Readiness, **Entitlements** (`/admin/platform-entitlements`, operators only, writes through `EntitlementConfiguration`) | Yes |
| Commands | `peopleos:entitlements:explain {tenant} {capability?} {--at=}`, `peopleos:entitlements:shadow-report {--days=} {--tenant=} {--by-surface}` | Yes |
| Tests | `tests/Feature/Entitlements/*` (37) + `tests/MySql/EntitlementConcurrencyTest.php` (6). Full suite at SaaS.3: 1221 tests, 1150 passed, 71 skipped, 0 failed | Yes |
| Versioning convention | ADR-0009: versions `draft → published → retired` (`App\Domain\Configuration\Enums\VersionStatus`, used by policy, form and workflow versions); published versions immutable; "superseded" and "scheduled" are derived, not stored | Used for plan versions |

## The roadmap position of SaaS.4

SaaS.1 §23 Workstream 2 ("Commercial Foundation & Entitlement Engine, observe mode") lists:
- the catalogue and the contract (built in SaaS.3);
- **catalogue tables (plans, versions, prices, plan entitlements)**;
- overrides and snapshots;
- a Legacy plan with a backfill;
- read-only operator views of plans and entitlements.

SaaS.3 built the engine and left plans out on purpose. SaaS.4 builds the plan part of Workstream 2, within the brief's limits:

| Workstream 2 item | SaaS.4 |
|---|---|
| Plans, plan versions, plan entitlements | **Built** |
| Plan prices | **Not built.** Pricing is excluded by the brief and undecided (D-1) |
| Tenant → plan | **Built as an effective-dated assignment** pinned to a plan version. Subscriptions, whose items will pin versions in Workstream 3, are excluded by the brief |
| Snapshots and compiler | **Not needed yet.** The tenant's state, including its assigned plan versions, is cached whole and evaluated in memory (SaaS.3 ADR-0027). Published versions are immutable, so nothing has to be recompiled when the catalogue changes |
| Legacy plan + backfill (ADR-0026, Proposed) | **Not done.** The brief keeps existing tenants unconfigured and UNKNOWN, and no plan is invented or assigned by default |
| Operator views | **Built** (management, not only views, because assignment is a platform operation) |

## Decisions inherited and not converted

| Source | Status | Use in SaaS.4 |
|---|---|---|
| ADR-0017 (commercial records platform-owned, not `BelongsToTenant`) | Proposed | The **plan catalogue** has no tenant at all (platform level, like `compliance_rules`). A tenant's **plan assignment** is part of its entitlement configuration and follows ADR-0027 (`BelongsToTenant`). ADR-0017 stays open for billing records |
| ADR-0018 (entitlement contract, enforcement) | Proposed | Unchanged: shadow only |
| ADR-0021 (plan versioning and grandfathering) | Proposed | Immutable published versions and pinned assignments are built. Plan migrations (moving pinned tenants to a new version) belong to subscriptions and stay proposed |
| ADR-0026 (Legacy backfill; off → observe → enforce) | Proposed | Not done (see above) |
| D-1, D-13, D-14, D-16 (packaging, billable unit, seats, limit modes) | Unresolved | **No plan is created by SaaS.4.** Operators enter plan content; the mechanism decides nothing about packaging |
| D-4 (trials), D-5 (cancellation, migration notice), D-15 (operator roles, dual control) | Unresolved | No trial terms, no plan migration, no new operator role |
| SaaS.3 open: tenant-local business dates | Unresolved | UTC kept, as everywhere else |

## Blockers

**None.** A plan model, its lifecycle and a tenant assignment can be built without choosing a business policy:
- packaging, limit values, prices and trials stay data that operators may enter later, or decisions that stay open;
- existing tenants keep the SaaS.3 default (UNKNOWN).

## Outstanding operational items found

| Item | State (checked 6 October 2026) |
|---|---|
| Leaked demo password (SaaS.2 §14) | **Still outstanding.** The account seeded before SaaS.2 still accepts the old credential in the development database `hcm` and in `hcm_ux_showcase`. Checked by hash comparison; nothing was printed or changed. Rotation remains the owner's action |
| Showcase migrations | `hcm_ux_showcase` lacks the SaaS.2 and SaaS.3 migrations; 8090 is not running |
