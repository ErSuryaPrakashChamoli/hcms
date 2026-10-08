# SaaS.4 — FINAL REPORT

**Phase:** Commercial Plan Foundation (shadow mode), including the closure / final validation pass · **Date:** 6 October 2026

Supporting documents:

| Document | Content |
|---|---|
| [SaaS-4 Baseline](SaaS-4-Baseline.md) | Discovery at `1f7808f`: what existed, the roadmap position, inherited decisions, blockers (none) |
| [SaaS-4 Plan Model](SaaS-4-Plan-Model.md) | Entities, keys, immutability, lifecycle, decision path |
| [Decision register](../architecture/decision-register.md#saas4-decisions) | ADR-0031 to ADR-0034 |
| [Architecture note](../architecture/saas-4-plans.md) | The developer's map |
| [Security invariants](../architecture/security-invariants.md) | 42–45 |
| [Browser evidence](evidence/SaaS-4-browser-validation.json) | The 32 browser checks and the axe results |

## 1. STATUS

**COMPLETE**

Every completion criterion of the closure brief is met:
- full suite with 0 failures; SaaS.4, SaaS.3 and architecture tests pass;
- MySQL and concurrency tests pass; migration clean, rollback and re-apply pass;
- performance compared with no unacceptable regression;
- Pint, syntax, Blade and build clean;
- showcase migrated and 8090 validated; browser, accessibility and existing visual/browser regression pass;
- tenant isolation, authorisation and audit validated;
- documentation complete; no unapproved roadmap scope.

The pre-existing items in §11 are not SaaS.4 gates. **The leaked credential remains an outstanding operational security issue for the owner.**

## 2. BASELINE

| | |
|---|---|
| Starting commit | `1f7808f` (SaaS.3 report) |
| Ending commit | The documentation commit that adds this report, directly after `29b20e8` (its hash cannot be written inside itself; see `git log`) |
| Branch | `feature/oct_1_phase_1`. Not pushed, not merged, not deployed; production untouched |
| Commits | 2: `29b20e8` feat: commercial plans and tenant plan assignment in shadow mode (SaaS.4), then the SaaS.4 documentation commit |
| Files changed | 41. `29b20e8`: 32 files (application, migration, views, tests), +2506 / −74. The documentation commit: 9 files (this report, baseline, plan model, architecture note, decision register, security invariants, two SaaS.3 doc pointers, browser evidence) |

## 3. IMPLEMENTATION

| Area | What exists (verified in the repository) |
|---|---|
| **Plan catalogue** | `plans`: a permanent, validated, unique `code` (2–64 characters: lower-case letters, digits, `-`, `_`), a name and a description. Platform-level (no tenant). Never deleted, and the code never changes (model guards). `PlanCatalog` is the only write path |
| **Plan versions** | `plan_versions`: a number, a status, a sale window (`effective_from` / `effective_to`), who published or retired it and when, and what changed. A published version is immutable (model guards on the version and its entitlements). There is one draft per plan (generated-column unique index). A new draft copies the latest version |
| **Plan lifecycle** | Stored statuses are `draft → published → retired`: the existing `VersionStatus`, as for policy, form and workflow versions (ADR-0009). *Scheduled*, *active* and *superseded* are derived from the sale window (`PlanState`), never stored. A draft can never reach a tenant. Publishing ends the previous version's sale window the day before. Retiring stops new assignments; assigned tenants keep their version |
| **Plan capabilities** | `plan_entitlements`: one row per capability key of the code-owned `Capability` catalogue; the plan stores only its value (included / excluded / limit / unlimited). The capability's identity, type, module and protection are not duplicated. Unknown keys, `core` and wrong value types are refused. A protected capability can be included or left out, never switched off (ADR-0034). Draft edits run under the plan's row lock (see the closure fix below) |
| **Tenant plan assignment** | `tenant_plan_assignments` (`BelongsToTenant`) pins a published version for an inclusive date range. It is changed only through `EntitlementConfiguration::assignPlan` / `endPlanAssignment`: platform operators only, reason of at least 5 characters, idempotent, serialised on the tenant's profile row. There is no default assignment |
| **Effective dating** | Business dates, inclusive, UTC (`HasEffectiveDates`). Changes start today or later and must be inside the version's sale window. One assignment per day: the earlier one ends, a future one is cancelled, a longer one resumes afterwards. History is kept; nothing is rewritten |
| **Entitlement integration** | One more step in the existing pure `EntitlementEvaluator`: catalogue → override → the tenant's own configuration row → **plan** → configured default → UNKNOWN. There is no second engine; `Entitlements::observe()` and `evaluate()` are unchanged for callers. Pinned versions are read with the tenant's cached state (two extra reads on a cold cache, only for tenants that have had a plan) |
| **New decision reasons** | `NOT_IN_PLAN` (DENY, source `plan`: the plan does not include the capability: "not sold", distinct from `NOT_ENTITLED`, an explicit "off"). `NO_PLAN_IN_FORCE` (UNKNOWN: a plan had started, none covers the day, and the tenant has no configuration of its own). Decisions from the plan layer carry the assignment id and the plan version id |
| **Platform UI** | **Platform › Plans** (`admin/platform-plans`): catalogue, versions with derived states, tenants per version, the capability-by-version matrix, platform audit trail; actions: new plan, edit details, new draft, edit draft capabilities, publish, retire. **Platform › Entitlements** (extended): the plan in force, an "In the plan" column, plan assignment history, the commercial audit trail; actions: assign plan, end plan. Both pages are platform operators only and use the SaaS.3 page pattern |
| **Audit** | The catalogue writes `PLAN_CREATED`, `PLAN_UPDATED`, `PLAN_VERSION_DRAFTED`, `PLAN_VERSION_EDITED`, `PLAN_VERSION_PUBLISHED` and `PLAN_VERSION_RETIRED` on Markedge's platform chain. Assignments write `PLAN_ASSIGNED` and `PLAN_ASSIGNMENT_ENDED` on the tenant's chain and the platform chain. Each event records the actor, the reason, the previous and new values (field changes) and the effective date; the timestamp comes from the chain |

**Closure fix (a genuine SaaS.4 defect, found by the race review).** `PlanCatalog::set()` and `remove()` read a draft's content before taking the plan lock. Two operators editing different capabilities of one draft at the same moment could lose one edit. The read now happens under the lock (`PlanCatalog::change()`).
- MySQL race 7 failed on the old code (the AI edit was lost) and passes on the new.

**Closure fix (accessibility).** The capability matrix's "not in plan" cells used a grey that failed contrast. The tables' horizontal scroll regions were not keyboard-focusable on phones. Both are fixed: darker text, and labelled focusable regions on both platform pages.

## 4. DATABASE

| | |
|---|---|
| Migrations | 1, additive: `2026_10_23_100001_create_commercial_plan_tables`. It writes no rows |
| Tables | `plans`, `plan_versions`, `plan_entitlements`, `tenant_plan_assignments`. Columns added to SaaS.3 tables: `tenant_entitlement_profiles.has_plan_assignments` (default false), `entitlement_shadow_observations.last_assignment_id` (nullable) |
| Unique constraints | `plans.code`; `plan_versions (plan_id, version)`; `plan_versions.draft_plan_id` (virtual generated column, so one draft per plan); `plan_entitlements (plan_version_id, capability)`; `tenant_plan_assignments (tenant_id, active_from)` (virtual generated column: one active assignment per start date) |
| Other indexes | `plan_versions (plan_id, status)`; `tenant_plan_assignments (tenant_id, effective_from)` and `(plan_version_id, status)`; foreign-key indexes. 19 indexes on the four tables in all |
| Foreign keys | `plan_versions.plan_id → plans` and `tenant_plan_assignments.plan_version_id → plan_versions`: restrict, so history can never lose its plan. `plan_entitlements.plan_version_id → plan_versions`: cascade. `tenant_plan_assignments.tenant_id → tenants`: cascade (tenant data, ADR-0027). Actor columns `→ users`: set null |
| Validated on MySQL 8.4.11 (throwaway `hcm_saas4_migrate`, dropped afterwards) | Clean migration of the whole schema. Then probes against the live database: second draft, duplicate version number, duplicate code, duplicate capability and second active assignment on one start date were all refused (1062). An active and a cancelled assignment on one date were accepted. Deleting a referenced plan or plan version was refused (1451). Deleting the tenant cascaded its assignments |
| Rollback / re-apply | `migrate:rollback --step=1` removed all 4 tables and both added columns, kept the 4 SaaS.3 tables and deleted the migration row. `migrate` re-created everything (4 tables, 2 columns, 19 indexes); 0 pending |

## 5. SECURITY

| Area | Result |
|---|---|
| **Tenant isolation** | `tenant_plan_assignments` is fail-closed tenant-scoped. Tests: tenant A sees none of tenant B's assignments; A's decisions, cache key and cached state never contain B's plan; queued jobs evaluate their own tenant (B on a plan → A unconfigured → B). Plans are platform catalogue, unreadable to every tenant user: both pages answer 403. A tenant administrator cannot assign or end anything for A or B. MySQL race 8: readers of tenant B, during tenant A's assignment, only ever saw B's unconfigured answer |
| **Authorisation** | Refused in the services and on both pages (tests and browser): employee, manager, tenant administrator with every permission (`*`), a tenant user carrying the operator flag, and a tenantless non-operator (who cannot even enter the panel and is sent to the login page). There is no platform role catalogue (G-PLAT-1 deferred); the existing operator definition (`User::isPlatformAdmin()`: flag and no tenant) is the control. **Authorisation is unchanged:** no permission, policy, gate, scope or navigation item reads a plan. A test confirms a plan neither grants nor removes a permission, and an architecture test keeps plan models out of HCM code |
| **Platform operator controls** | Reason required: at least 5 characters in the service, and in the form both the browser and the server refuse it (browser-tested). Changes start today or later. A draft or retired version can never be assigned; the page offers published versions only, and the server refuses a draft id. Tests cover idempotency and both kinds of lock |
| **Audit** | Every catalogue change is on the platform chain. Every assignment is on both chains, with actor, reason, previous and new plan, and effective date (tests, plus browser checks of both trails). Audit rows refuse updates (`ImmutableAuditRecordException`), and both chains verify after every test and race |
| **Protected capabilities** | A plan can never exclude payroll, onboarding or exit, or set the employee limit to 0: refused at define and at publish (tests), and in the browser (the option is not offered; 0 is refused by browser and server). The core and security are never in a plan. Leaving payroll out is observed as `NOT_IN_PLAN` and payroll still runs to payslips (test) |
| **Fail-open commercial / fail-closed security** | With the plan tables unreadable, decisions are `UNKNOWN` / `EVALUATION_FAILED` and payroll still finalises (test). Authorisation failures stay 403 or a redirect to login |

## 6. TEST RESULTS

**Full suite** (`php artisan test --parallel --processes=2`, SQLite, final run on the closure code):

| Total | Passed | Skipped | Failed | Assertions |
|---|---|---|---|---|
| **1256** | **1177** | **79** | **0** | **14,747** |

- **The skips.** All 79 skipped tests are the opt-in MySQL suite, which runs separately (below); nothing else skips.
  - Correction to earlier reports: the SaaS.3 report described its 71 skips as "60 browser/visual + 11 MySQL-only". In fact they were the 71 MySQL tests. The Playwright suites are not part of the Pest run.
- **An earlier full run** during SaaS.4, before the closure fixes, gave 1252 tests: 1175 passed, 77 skipped, 0 failed.
- **New SaaS.4 tests:** 35 in all:
  - 26 feature tests (`PlanCatalogTest` 6, `PlanAssignmentTest` 8, `PlanSecurityTest` 8, `PlanPerformanceTest` 4);
  - 8 MySQL races (`PlanConcurrencyTest`);
  - 1 architecture rule.
- **The entitlement feature folder** passes in full: 63 tests (37 SaaS.3 + 26 SaaS.4). The architecture suite (128 tests) passes.

| Gate | Result |
|---|---|
| **MySQL** (8.4.11, throwaway `hcm_saas4_concurrency`, dropped afterwards) | Whole opt-in suite **79 / 79 passed**: 71 earlier MySQL tests, including the 6 SaaS.3 entitlement races, plus 8 new plan races |
| **Concurrency** (`PlanConcurrencyTest`, 8 / 8, 43 assertions; real forked processes, database cache store) | 1. Two different plans assigned at once: one active, one cancelled, no overlap. 2. The same assignment twice: one row, one audit event. 3. Two new drafts at once: one draft (v2). 4. The same draft published twice: published once, sale window superseded once, one audit event. 5. Retire versus assign: the assignment either came first or is refused as retired. 6. The shared cache is invalidated on assignment. 7. Two edits to one draft at once: both kept, no lost update; this race **failed on the pre-fix code and passes on the fix**. 8. Readers during an assignment saw only the old plan or the new one, never a mix, and the other tenant's readers only their own unconfigured answer. Both audit chains verify after every race |
| **Security** | `PlanSecurityTest` (8) and `PlanCatalogTest` (operator-only, reasons, immutability). Covered: isolation, every non-operator refused, page-level reason and draft bypass, append-only audit with chain verification, payroll fail-open, the plan employee limit observed but never refusing a hire. Architecture: plans confined to the entitlement services and the two platform pages |
| **Accessibility** | axe-core (WCAG 2.0 / 2.1 A and AA) on the plans list, plan detail, edit-draft modal, assign modal and tenant entitlements page, desktop and phone: **no violations**. The exception is pre-existing: the theme's validation-message contrast, 4.33:1, identical on the SaaS.3 form, listed in §11. **Fixed in closure:** low-contrast "not in plan" cells, and table scroll regions not focusable on phones. Keyboard: the plan actions are reachable by Tab with a visible focus indicator; Escape closes modals; every capability field is labelled; validation messages show |
| **Browser** (Chromium, desktop and phone; disposable `hcm_saas4_ui_showcase`, dropped afterwards) | **32 / 32 checks passed** ([evidence](evidence/SaaS-4-browser-validation.json)). Covered: plans list and detail; draft, published and retired behaviour; published versions cannot be edited; capability form against the catalogue; protected rules; invalid values refused by browser and server; reason mandatory (browser and server); assignment with effective date and history; UNKNOWN, NOT_IN_PLAN and NO_PLAN_IN_FORCE explained; both audit trails; tenant administrator, manager and employee refused (403, no Platform menu); no page-level horizontal scroll on phones; no console errors. On 8090 itself: the six-persona smoke and the rolled-back operator render (§8) |
| **Visual regression** | **120 passed, 0 failed** (198 skipped by design); behavioural browser suite **74 passed, 0 failed** (6 skipped by design), on the final run. The first run failed 26: 24 WebKit, because WebKit's network process loaded `/snap/core20` libraries injected by the VS Code snap environment, fixed by a clean environment in the user-space WebKit launcher; and 2 chromium palette captures with a transient external font and avatar load. **No baseline was changed** |
| **Performance** | §7: no regression; the Entitlements page dropped from 83 to 29 queries |
| **Migration** | §4: clean migration, constraint probes, rollback and re-apply on MySQL 8.4 all passed |
| **Static** | `vendor/bin/pint --test`: pass. `php -l` on all 32 changed or new PHP files: clean. `php artisan view:cache`: all 297 Blade views compile. `npm run build`: built; the new pages' classes are in the theme. Architecture tests: pass |

## 7. PERFORMANCE

Three interleaved rounds compared the baseline worktree at `1f7808f` with HEAD:
- same probe, same data (15 salaried employees, one leave taker, one API key);
- array and database cache stores;
- 7 timed iterations per surface; the values below are the per-round medians.

| Surface | Queries `1f7808f` | Queries HEAD | Queries HEAD, tenant on a plan | ms `1f7808f` (array cache) | ms HEAD (array cache) |
|---|---|---|---|---|---|
| Payroll calculate (15 employees) | 790 | 790 | 790 | 685 / 714 / 669 | 718 / 749 / 662 |
| Leave request | 55 | 55 | 55 | 52 / 46 / 46 | 56 / 56 / 53 |
| API employees (HTTP) | 9 (DB cache 11) | 9 (11) | 9 (11) | 31 / 29 / 30 | 33 / 31 / 33 |
| Home (HTTP, not instrumented) | 51 (DB cache 62) | 51 (62) | 51 (62) | 398 / 397 / 382 | 421 / 411 / 376 |
| Entitlement: cold load + 1 decision | 1 (unconfigured) | 1 | **5** (DB cache 8) | 0.7 | 0.5–0.7 (plan: 2.4–3.6) |
| Entitlement: 31 capabilities × 10, warm | 0 | 0 | 0 | 15–18 | 14–18 (plan: 15–17) |
| Entitlements page (operator, HTTP) | **83** | **29** | 36 | 203–250 | 161–192 (plan: 185–200) |
| Plans page (operator, HTTP) | — | 7 (empty catalogue) | 14 | — | 125–136 (plan: 137–155) |

| Finding | Detail |
|---|---|
| Material regression | **None.** Every HCM surface has the same query count before and after, with or without a plan, and timings overlap within run-to-run variance |
| Plan cost | A cold read costs two indexed reads (assignments, then the pinned versions with their entitlements joined). After that, nothing per decision |
| Caching | No new cache. Plan data rides in the existing `tenant:{id}:entitlements` entry: per-tenant key, forgotten after commit on every assignment change, and immune to catalogue changes because published versions are immutable |
| N+1 | One was found and fixed in the SaaS.3 Entitlements page, which re-read the tenant's state once per capability (83 → 29 queries). Tests prove both platform pages keep a constant query count as the catalogue and a tenant's plan history grow. The explain command also reads the state once |

## 8. SHOWCASE

| | |
|---|---|
| `hcm_ux_showcase` migration | Backed up first (private copy in the session scratchpad). The pending additive migrations were applied: SaaS.2 `2026_10_21_100001` (prerequisite: the code needs its columns to sign anyone in), SaaS.3 `2026_10_22_100001` (prerequisite: SaaS.4 alters its tables) and SaaS.4 `2026_10_23_100001`. Users, employees, audit events and tenants were unchanged by the migration (16 / 22 / 1077 / 1), and no plan or assignment was created. Not reset, not re-seeded |
| 8090 status | It was **not running** at the start of SaaS.4. It was restarted detached on the migrated database and is **running**. `/up` 200; signed-out platform pages redirect to login |
| Browser validation on 8090 | All six personas (employee, manager, HR, tenant admin, payroll, executive) signed in; every everyday page tried answered 200 (home, My HR, leave requests, approvals, teams, employees, people analytics, audit, payroll control room); both platform commercial pages answered 403 and no Platform menu showed. The only console errors were those deliberate 403 loads. The executive's first attempt hit the login limiter (six sign-ins in a minute) and passed on retry. The persona sign-ins recorded their normal sign-in audit events |
| Operator pages on 8090 data | The showcase's only operator account has an unknown password, and resetting it would change the showcase. So Plans and Entitlements were rendered against `hcm_ux_showcase` as that operator, in-process, inside a transaction that was rolled back. The checks: Plans renders the empty catalogue; the demo tenant is unconfigured, no plan in force, every commercial capability `TENANT_UNCONFIGURED`. Row counts were identical before and after |
| UX regression | The existing suites ran on their own disposable frozen-clock showcase (`hcm_ux_visual_showcase`, 8092), rebuilt from scratch per run as documented. **Final results:** visual regression **120 passed, 0 failed** (198 undeclared screen, theme and project combinations skipped by design, as in UX.18); behavioural browser suite **74 passed, 0 failed** (6 skipped by design). **No baseline was updated.** How the first run's failures were classified: 24 WebKit failures were a host problem, now fixed (see §6). The 2 chromium `employee-palette-leave` captures showed identical content, but with a fallback font and the avatar's alt text: the suite's two external asset hosts (`fonts.bunny.net`, `ui-avatars.com`) failed to load during those captures. The same code passed unchanged on the rerun. Neither is a SaaS.4 change; SaaS.4 alters no tenant-facing screen |

## 9. SAAS.3 REGRESSION

- **All 37 SaaS.3 entitlement feature tests pass unchanged** except one assertion: the exact column list of `entitlement_shadow_observations` gained `last_assignment_id`, and the check is still exact. All 6 SaaS.3 MySQL races pass.
- **Unchanged behaviour:**
  - tenants without a plan evaluate exactly as before, with the same query counts (3 cold for a configured tenant, 1 for an unconfigured one);
  - missing configuration is never DENY;
  - shadow mode is still the only mode (`Decision::enforced()` is false, and `observe()` never throws);
  - the payroll safety tests still pass.
- **The rest of SaaS.1–SaaS.3:**
  - commercial entitlement ≠ authorisation (ADR-0018 contract);
  - UNKNOWN stays safe; protected capabilities stay protected; existing tenants are not denied;
  - feature flags are untouched;
  - SaaS.2 MFA, session, invitation and audit hardening is intact (the browser walkthrough signs operators in with password + TOTP);
  - no RMS dependency (architecture test).

## 10. NOT IMPLEMENTED

SaaS.4 did **not** implement any of the following:
- billing, payments, payment gateways, invoices, GST;
- a pricing engine, prices or pricing UI;
- self-service signup or customer self-provisioning;
- subscriptions, renewals or plan migrations of assigned tenants;
- trials;
- full usage metering (only the existing active-employee count is measured);
- any commercial enforcement, global or partial.

Nor did it add:
- a default or Legacy plan for existing tenants;
- add-on plans, plan visibility or markets;
- a platform role catalogue.

## 11. OPEN ITEMS

| # | Item | Owner / where |
|---|---|---|
| 1 | **Leaked demo credential (SaaS.2 §14), still active.** Re-checked on 6 October 2026 by hash comparison (nothing printed or changed). The pre-SaaS.2 demo tenant-administrator account, an address on the owner's domain, still accepts the old seeded credential in the development database `hcm` and in the showcase `hcm_ux_showcase` (served on 8090). The credential is also in git history. **Operational security prerequisite:** rotate it wherever it is used | Owner |
| 2 | **Pre-existing contrast of form validation messages.** The design system's danger colour for field error text measures 4.33:1 on white (WCAG AA needs 4.5:1). Found by axe on the SaaS.4 assign modal, and present identically on the SaaS.3 "Start configuration" modal, so it is app-wide and predates SaaS.4. Not changed here: it is a theme token, and changing it would alter every form and the visual baselines | UX / design system |
| 3 | **The visual suite depends on two external hosts** (Filament's Bunny font provider and the ui-avatars avatar provider). A failed fetch shows up as a screenshot difference | UX test infrastructure |
| 4 | Decisions still open from SaaS.1 and SaaS.3 (not opened by SaaS.4): packaging, limit values and prices (D-1, D-13, D-14, D-16); trials (D-4); plan migrations and notice (D-5, ADR-0021); a default or Legacy plan for existing tenants (ADR-0026); operator roles and dual control (D-15, G-PLAT-1); tenant-local business dates | Product / commercial / owner |
| 5 | Plans never switch a protected capability off; per-tenant configuration and overrides can still record an explicit "off" for one (SaaS.3 behaviour, shadow only). Whether that should also be refused belongs to the protected-capability enforcement decision | Owner, before enforcement |

## 12. DECISIONS

Made in SaaS.4 and recorded as ADR-0031 to ADR-0034, **Accepted (shadow)**:

| ADR | Decision | Rationale |
|---|---|---|
| 0031 | Stable plans; immutable `draft → published → retired` versions (`VersionStatus`); derived scheduled, active and superseded states; one draft per plan; publishing supersedes the previous sale window; retiring stops new assignments | It reuses the ADR-0009 lifecycle instead of inventing statuses. Immutability keeps history and caching correct |
| 0032 | A tenant plan assignment is effective-dated, tenant-scoped configuration: operator-only, reasoned, both audit chains, one per day, no default assignment | It keeps ADR-0027 (fail-closed tenant data) and the SaaS.3 history rules. A subscription can later write assignments without changing the engine |
| 0033 | The plan is one layer of the existing engine (catalogue → override → tenant configuration → plan → configured default → UNKNOWN), with source `plan`, `NOT_IN_PLAN` and `NO_PLAN_IN_FORCE` | There is no second engine. A tenant-specific term is more specific than a plan. "Not sold" and "switched off" stay distinguishable |
| 0034 | No plan switches a protected capability off; the core and security are never in a plan | It applies the brief's protection rule without deciding packaging |

ADR-0021 is implemented for immutability and pinning, and stays Proposed for plan migrations. ADR-0017 and ADR-0026 are unchanged. **No business policy was decided:** no price, package, trial term, limit value or default plan.

## 13. PRODUCTION READINESS

**SaaS.4 COMPLETE.**

**PeopleOS is not production-ready.** SaaS.4 adds a commercial plan foundation in shadow mode; it is not a commercial SaaS launch. Overall production readiness remains governed by the established roadmap and its separate gates, which SaaS.4 does not change:
- statutory rule verification (0 / 24 verified);
- DR verification, staging validation, operator sign-off and production network and load;
- the leaked-credential rotation;
- the remaining commercial workstreams: subscriptions and trials (WS3), metering and enforcement (WS4), billing, tax and payments (WS5), self-service (WS6), offboarding (WS7), the control plane (WS8) and commercial validation (WS9).
