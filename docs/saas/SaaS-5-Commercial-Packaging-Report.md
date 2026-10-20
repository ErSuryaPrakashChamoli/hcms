# SaaS.5 — FINAL REPORT

**Phase:** Commercial Packaging, Limits & Pricing Architecture (shadow mode) · **Date:** 6 October 2026

Supporting documents:

| Document | Content |
|---|---|
| [SaaS-5 Baseline](SaaS-5-Baseline.md) | Discovery at `cf62eec`: schema, catalogue, limits as they were, unresolved decisions, scope |
| [Decision register](../architecture/decision-register.md#saas5-decisions) | ADR-0035 to ADR-0037 |
| [Security invariants](../architecture/security-invariants.md) | 46–48 |
| [Architecture note](../architecture/saas-4-plans.md) | "SaaS.5 additions" |
| [Browser evidence](evidence/SaaS-5-browser-validation.json) | Browser checks and axe results |

## 1. STATUS

**COMPLETE.** Every SaaS.5 gate passed:
- discovery and architecture done; scope verified;
- packaging and the limit model built;
- no price, trial, default plan or automatic migration added; the engine remains authoritative;
- the authorisation boundary is proved by test;
- isolation, security, concurrency and mutation tests pass;
- the MySQL migration chain is clean (rollback and re-apply);
- performance compared with no material regression and no N+1;
- build, Pint and syntax clean; browser, accessibility and visual regression pass; the showcase works;
- SaaS.1–SaaS.4 regression passes; documentation complete; nothing pushed, merged or deployed.

The leaked credential (§15) is an outstanding operational issue for the owner, not a SaaS.5 gate.

## 2. BASELINE

| | |
|---|---|
| Starting commit | `cf62eec` (SaaS.4 report) |
| Ending commit | The documentation commit that adds this report, directly after `907ca33` (its hash cannot be written inside itself; see `git log`) |
| Branch | `feature/oct_1_phase_1`. Not pushed, not merged, not deployed; production untouched |
| Commits | 2: `907ca33` feat: commercial packaging and the limit model, price boundary (SaaS.5), then the SaaS.5 documentation commit |
| Files changed | 21. `907ca33`: 13 files (application, views, tests), +549 / −49. The documentation commit: 8 files (this report, baseline, decision register, security invariants, architecture note, capability catalogue, plan model, browser evidence) |

## 3. DISCOVERY

- **The roadmap.** SaaS.1's roadmap is organised by workstream:
  - SaaS.3 and SaaS.4 built the engine and the plan catalogue of Workstream 2 (catalogue and entitlements, observe mode);
  - prices appear in that workstream's catalogue list, but their consumers are subscription items (WS3) and invoices (WS5);
  - pricing, packaging, limit values and limit modes are open decisions (D-1, D-3, D-10, D-12, D-13, D-14, D-16).
- **Limits in the repository.** The eight SaaS.3 limits already existed as catalogue entries with a unit and a module, and plan rows already held their values (NULL = unlimited). Only `active_employees.max` is measured (`BillableUnits`, one `observeLimit` call site).
- **Concrete gaps found** (baseline document):
  1. A limit could not be "not included". AI and API request limits answered as if their module were sold, unlike features.
  2. The presentation contradicted the engine. A limit missing from a plan was labelled "not in plan" on Platform › Plans, on the Entitlements page and in the explain command, while the engine answered UNKNOWN with no agreed limit.
  3. A version could be published with a feature but without its module.
  4. The authorisation boundary (SaaS.3 invariant 39) was enforced by review only: no architecture test proved it.
- **No business decision had been made since SaaS.4**, and none was needed: every SaaS.5 change is structural. **No blocker.**

## 4. COMMERCIAL ARCHITECTURE

```
PLAN (code, name)                                  Markedge catalogue, no tenant          — SaaS.4
 └─ PLAN VERSION  draft → published → retired       immutable once published, sale window — SaaS.4
     ├─ CAPABILITIES (modules)   included / excluded / not in plan
     ├─ FEATURES                 included / excluded / not in plan        (a feature brings its module — SaaS.5)
     ├─ LIMITS                   N unit / unlimited / not set             (a module limit brings its module — SaaS.5)
     └─ (no price)               a future PRICE DEFINITION will reference a published version — ADR-0037
TENANT ── effective-dated ASSIGNMENT ──► one published version     — SaaS.4 (no default, no automatic migration)
                         └──► existing ENGINE: override → tenant terms → plan → configured default → UNKNOWN
```

| Concept | In SaaS.5 |
|---|---|
| **Plan, plan version** | Unchanged from SaaS.4: permanent codes, immutable published versions, derived states, one draft at a time; draft edits under the plan lock |
| **Packaging** | A version's content **is** the package: modules, features and limits, each a reference to the code-owned catalogue with the plan's value. There is no second catalogue and no new entity. New: **publication refuses an inconsistent package** (ADR-0036): an included feature without its module, or a limit of a commercial module without that module. Platform › Plans explains a draft's problems before anyone tries to publish |
| **Capabilities, features** | As SaaS.3 and SaaS.4: included, excluded (never for a protected capability), or not in plan |
| **Limits** | The model in §5 (ADR-0035) |
| **Effective dating** | Limit values live in immutable versions. A change is a new draft, then a publication. A pinned tenant keeps its version's limits. Only an explicit, reasoned operator re-assignment moves a tenant, from its start date; history keeps every past day's limit (tested) |
| **Future pricing boundary** | ADR-0037: price is not part of a plan or of an entitlement decision. A future price will be its own versioned definition referencing a published plan version: chosen by a subscription, read only by billing. **No price table exists.** The decisions it needs are listed in §15. Architecture tests keep money columns out of the plan tables and pricing or billing out of entitlement code |

## 5. LIMIT MODEL

How every limit's value is represented (ADR-0035): a whole number of the unit, at least the minimum; NULL is unlimited; an absent value is "not set". The states are the same for every limit:

| State | Outcome / reason |
|---|---|
| Unlimited | `ALLOW` / `UNLIMITED` |
| Within the limit | `ALLOW` / `WITHIN_LIMIT` |
| Exceeds the limit | `DENY` / `LIMIT_EXCEEDED` |
| Not set (no agreed limit) | `UNKNOWN` / `LIMIT_NOT_CONFIGURED` |
| No commercial answer (no plan or configuration) | `UNKNOWN` / `TENANT_UNCONFIGURED`, `BEFORE_CONFIGURATION` or `NO_PLAN_IN_FORCE` |
| Finite limit, usage not measured | `UNKNOWN` / `USAGE_UNAVAILABLE` |
| Not included (only for limits of a commercial module, while that module is not entitled) | `DENY` / `MODULE_NOT_ENTITLED` |

| Key (stable) | Unit | Minimum | Not included possible? | Usage measured? | Enforcement |
|---|---|---|---|---|---|
| `active_employees.max` (protected) | employees | 1 | No (core) | **Yes**: `BillableUnits::activeEmployees()`, observed at `employee.activate` | **None** (shadow observation only) |
| `users.max` | users | 0 | No (core) | No | None |
| `admin_users.max` | users | 0 | No (core) | No | None |
| `legal_entities.max` | legal entities | 0 | No (core) | No | None |
| `locations.max` | locations | 0 | No (core) | No | None |
| `storage_bytes.max` | bytes (shown also in KiB to TiB) | 0 | No (core) | No (needs the storage ledger, G-MET-3) | None |
| `api_requests_monthly.max` | requests per month | 0 | **Yes**: module `integrations` | No (needs usage events) | None |
| `ai_requests_monthly.max` | requests per month | 0 | **Yes**: module `ai` | No (needs usage events) | None |

- **No metering was built:** only the SaaS.3 employee count is measured, and a test keeps `measured()` equal to the `observeLimit` call sites.
- **No limit is enforced anywhere.**
- **Deferred to metering (WS4):** limit modes (hard, soft, grace, D-16) and the definition of "per month" (calendar month or billing period).

## 6. ENTITLEMENT INTEGRATION

- **One change to the pure evaluator.** At the start of `limit()`: a limit whose module is commercial is evaluated against its module (with the full precedence: override, tenant terms, plan). If the module is `DENY`, the limit is `DENY` / `MODULE_NOT_ENTITLED`, naming the layer that left the module out. If the module is `UNKNOWN`, the limit keeps its own answer. Otherwise nothing changed: the order is still override → tenant terms → plan → configured default → UNKNOWN.
- **Vocabulary unchanged.** No new outcome and no new reason. `NOT_IN_PLAN` and `NO_PLAN_IN_FORCE` are preserved; shadow mode is still the only mode; `Decision::enforced()` is false.
- **No core limit changes its answer** (the six core limits are never gated).
- **AI and API request limits** change only while their module is denied, and nothing observes them in HCM code.
- **One vocabulary for the screens.** `Support\PlanValues` gives the Plans page, the Entitlements page ("In the plan" and "Value / limit") and `peopleos:entitlements:explain` the engine's terms. Explanations now say "not set (no agreed limit)" or "not included (ai not in plan)" where they used to say "not in plan", and show limits in their unit.

## 7. AUTHORIZATION BOUNDARY

**Commercial configuration does not replace authorisation.** Since SaaS.5 this is proved by tests and mutants, not by review alone.

| Proof | What it establishes |
|---|---|
| Architecture test *"keeps commercial entitlement out of authorisation"* | The files outside the entitlement domain that use it are exactly: the 13 shadow call sites, the API middleware, the two platform pages, the two commands, the container bindings and the retention purge. Nothing under `Domain/Identity` (users, roles, permissions, scopes), no `Policies` directory and nothing under `Support/Tenancy` references it. Every call site is a bare `observe()` / `observeLimit()` statement whose result is never assigned, tested, or used to evaluate, deny or enforce |
| Existing SaaS.4 architecture test | Plan and assignment models are referenced only by the entitlement services and the two platform pages |
| Behaviour tests | A plan's employee limit of 1 does not stop 3 hires: they are observed as `LIMIT_EXCEEDED`, never refused. Users' permission keys are identical before and after a plan assignment. A plan including a module grants no permission, and a plan without it removes none |
| Mutants M18 and M19 (§10) | Injecting an entitlement reference into `LeaveRequestPolicy`, or making `Leaves::request` act on the observed decision, is caught by the architecture test |

**Plan → Permission, Plan → Policy, Plan → Gate and Plan → Role do not exist.** Commercial enforcement remains a later, controlled phase.

## 8. DATABASE

| | |
|---|---|
| Migrations | **None.** The limit model, the packaging rule and the price boundary need no schema change. Limit values already lived in `plan_entitlements.value_int` (NULL = unlimited), and limit metadata is code (the `Capability` enum). No table was created for hypothetical billing |
| Tables, indexes, constraints | Unchanged from SaaS.4. A new architecture test asserts the plan tables (`plans`, `plan_versions`, `plan_entitlements`, `tenant_plan_assignments`) carry no price, amount, currency, minor-unit, billing, discount, tax or fee column |
| Rollback / re-apply | With no SaaS.5 migration, the whole chain was re-verified at HEAD on MySQL 8.4.11 (throwaway `hcm_saas5_migrate`, dropped afterwards). All 113 migrations applied cleanly (308 tables). `migrate:rollback --step=3` removed the SaaS.4, SaaS.3 and SaaS.2 migrations: 9 tables, none left behind. `migrate` re-applied them; 308 tables, 0 pending |

## 9. SECURITY

| Area | Result |
|---|---|
| **Tenant isolation** | Tests: one tenant's plan limits never reach another tenant's decisions or cached state; assignments are invisible across tenants (SaaS.4 tests unchanged, plus `CommercialPackagingTest`). Mutant M07, removing tenant scoping from assignments, is killed. MySQL race 8 (readers of another tenant during an assignment) still passes |
| **Catalogue security** | Only `PlanCatalog` writes the catalogue, and only `EntitlementConfiguration` writes assignments (architecture test). Published versions are immutable in the catalogue and in the model (mutants M08–M10 killed). There is one draft per plan (M11 killed). Tenant users cannot open the platform pages (403, tests and browser on 8090 and the disposable showcase) |
| **Operator access** | Only platform operators change plans, limits or assignments. Mutants M12 and M13 (operator checks removed) are killed, as is M14 (reason requirement removed). A tenant administrator setting a plan limit or its own employee limit is refused (test). The existing operator definition applies; there is still no platform role catalogue (G-PLAT-1) |
| **Audit** | Unchanged and append-only. Every limit edit is a `PLAN_VERSION_EDITED` event with each capability's before and after values. MySQL race 9 shows two concurrent edits audited in order, the second seeing the first's result. Both audit chains verify after every race |
| **Protected capabilities** | A plan still cannot switch them off or set the employee limit to 0. That minimum is now catalogue metadata (`minimumLimit()`). Mutants M15 and M16 are killed |

## 10. TEST RESULTS

**Full suite** (`php artisan test --parallel --processes=2`, SQLite):

| Total | Passed | Skipped | Failed | Assertions |
|---|---|---|---|---|
| **1270** | **1188** | **82** | **0** | **14,890** |

- **The skips:** all 82 are the opt-in MySQL suite, run separately below.
- **New tests: 14.**
  - `CommercialPackagingTest`: 8 tests, 66 assertions.
  - 3 architecture tests: the authorisation boundary, the price boundary, and "measured = observed".
  - 3 MySQL races.
- **Re-run after the final markup change:** the entitlement folder (71 tests: 37 SaaS.3, 26 SaaS.4, 8 SaaS.5) and the architecture suite (131) pass.

| Gate | Result |
|---|---|
| **MySQL** (8.4.11, throwaway `hcm_saas5_concurrency`, dropped afterwards) | Whole opt-in suite **82 / 82 passed**, 363 assertions |
| **Concurrency** | 11 plan races pass. New in SaaS.5: 9. Two operators setting the same limit on one draft at once: one row, both edits audited in order, the second seeing the first. 10. Readers during a publication: a pinned tenant never sees the new version's limit and never a mix. 11. An assignment during a publication pins the version on sale today, whichever commits first. The 8 SaaS.4 races, including the lost-update race 7, still pass |
| **Security** | `CommercialPackagingTest` (operator-only limits, tenant isolation of limits, authorisation unchanged), the SaaS.4 security tests, the new authorisation-boundary architecture test, and the mutants below |
| **Mutation** (21 targeted mutants, on a private copy of the tree so the served tree was never touched) | **21 / 21 killed, 0 survived.** M01–M02 limit bypass (comparison; module gate); M03–M04 unlimited vs not included (engine; labels); M05–M06 UNKNOWN → DENY (unconfigured tenant; limit not set); M07 tenant scoping removed; M08–M10 published-version mutation (two model guards; catalogue); M11 duplicate draft; M12–M13 unauthorised catalogue and assignment changes; M14 reason requirement removed; M15–M16 protected capability off, protected limit 0; M17 inconsistent package published; M18 a policy reading entitlements; M19 an HCM call site acting on a decision; M20 the "measured" claim drifting; M21 a price entering the plan catalogue. Spot checks confirmed the killing tests are the intended invariants (M16: protected-limit validation; M18: authorisation allow-list; M21: price boundary) |
| **Browser** (Chromium, desktop light and dark, phone; disposable `hcm_saas5_ui_showcase`, final assets, dropped afterwards) | **SaaS.5 walkthrough 17 / 17.** Covered: limits in their units; "not set" versus "not included"; measurement stated honestly; the inconsistent-draft warning (light and dark); publication refused with the reason; editor labels and help; the fix in the editor clears the warning; the consistent package publishes; Entitlements page labels; no page-level scroll on phones; tenant administrator refused. **SaaS.4 regression walkthrough 32 / 32.** No console errors ([evidence](evidence/SaaS-5-browser-validation.json)) |
| **Accessibility** (axe-core, WCAG 2.0 / 2.1 A and AA) | **No violations** on: plan detail with the draft warning (light and dark), edit-draft modal with the new help texts, tenant entitlements, both pages at phone width. The only finding is the pre-existing, app-wide validation-message contrast, on the SaaS.4 assign modal with errors (§15). Keyboard and focus checks pass in the regression walkthrough. Visual inspection caught the capability matrix's columns running together; padding was added and both walkthroughs re-run on the final markup |
| **Visual regression** | Existing suites on their disposable frozen-clock showcase (8092), on the final assets: **visual regression 120 passed, 0 failed** (198 undeclared combinations skipped by design) and **behavioural browser suite 74 passed, 0 failed** (6 skipped by design). The same results came before the final markup and CSS change. **No baseline was updated.** WebKit ran through the user-space launcher fixed in the SaaS.4 closure |
| **Performance** (3 interleaved rounds, `cf62eec` vs HEAD, tenant unconfigured and on a full package with all 8 limits, array and database cache) | Query counts **identical on every surface**: payroll calculate 790, leave request 55, API 9 (11 with the database cache), home 51 (62), entitlement cold load 1 unconfigured or 5 on a plan (4 or 8 with the database cache), warm 0, Entitlements page 29 or 36, Plans page 7 or 14. The only measurable difference: 80 warm limit decisions take 1.9–2.0 ms instead of 1.7–1.9 ms (the module check for the two module-bound limits, about 2 µs per decision). Every other timing is within run-to-run variance. **No N+1** (the SaaS.4 constant-query page tests pass); no cache was added |
| **Static and build** | Pint: pass. `php -l` on all 13 changed or new PHP files: clean. `php artisan view:cache`: all Blade views compile. `npm run build`: built; the new classes are present in the theme |

## 11. SHOWCASE

| | |
|---|---|
| Migration | **None needed.** SaaS.5 has no schema change. `hcm_ux_showcase` was not migrated, reset or seeded; it is exactly as SaaS.4 left it |
| 8090 | Running throughout. It serves this working tree, so it shows the SaaS.5 code without a restart |
| Personas | All six (employee, manager, HR, tenant administrator, payroll, executive) sign in. Their everyday pages answer 200; Platform › Plans and Platform › Entitlements answer 403; no Platform menu; no console errors beyond those deliberate 403s |
| Operator pages on the showcase data | Rendered as the showcase's operator, in-process, inside a rolled-back transaction (that account's password is unknown and was not reset). Plans shows the empty catalogue. The demo tenant is unconfigured, with no plan in force, every commercial capability `TENANT_UNCONFIGURED`, and limits flagged "not measured yet". Row counts were identical before and after |
| Browser (operator flows) | On a disposable showcase (`hcm_saas5_ui_showcase`, same seeder, dropped afterwards): **SaaS.5 walkthrough 16 / 16** and **SaaS.4 regression walkthrough 32 / 32** (§10) |
| Visual regression | Existing suites on their disposable frozen-clock showcase (8092), on the final assets: **visual regression 120 passed, 0 failed** (198 undeclared combinations skipped by design) and **behavioural browser suite 74 passed, 0 failed** (6 skipped by design). The same results came before the final markup and CSS change. **No baseline was updated.** WebKit ran through the user-space launcher fixed in the SaaS.4 closure |

## 12. REGRESSION

- **SaaS.1 decisions:** the bounded context, ADR-0018's contract shape and the proposed ADRs are respected. ADR-0017, ADR-0021 (migrations) and ADR-0026 stay proposed.
- **SaaS.2 hardening:** intact. MFA, sessions, invitations and audit are untouched; the browser walkthroughs sign operators in with password plus TOTP.
- **SaaS.3 engine:**
  - all 37 SaaS.3 entitlement tests pass unchanged; the 6 SaaS.3 MySQL races pass;
  - shadow mode, fail-open, `UNKNOWN` for unconfigured tenants and protected capabilities are unchanged (mutants M05 and M06 killed);
  - feature flags are untouched; no RMS dependency.
- **SaaS.4 plans:**
  - all SaaS.4 feature tests pass;
  - **one SaaS.4 test was adapted:** it published a feature without its module to show the feature-needs-module rule, which SaaS.5's packaging rule now refuses at publication. It now holds the module off with a tenant override and asserts the same `MODULE_NOT_ENTITLED` outcome (the source becomes `override`). The refusal itself is a new test;
  - the 8 SaaS.4 MySQL races and the 32-check SaaS.4 browser walkthrough pass;
  - query counts are identical to `cf62eec` (§10).

## 13. NOT IMPLEMENTED

Deliberately not built in SaaS.5:
- billing, payments, invoices;
- subscriptions, trials, self-service signup;
- global (or any) commercial enforcement;
- full usage metering;
- **actual prices** and price tables;
- **automatic plan migration** (only explicit, reasoned operator re-assignment exists);
- a **default plan**;
- limit modes and operator roles.

No packaging content and no limit value were created.

## 14. DECISIONS

| ADR | Decision | Why |
|---|---|---|
| 0035 | **The limit model.** Limits stay catalogue entries with code-owned metadata (unit, module, measured, minimum). The seven states are distinct, and a limit of a commercial module never outlives its module | The brief requires unlimited, not included, unknown and exceeded to stay apart. It extends the SaaS.3 feature rule to limits instead of inventing a state |
| 0036 | **A published version is a consistent package** (a feature brings its module; a module limit brings its module) | It follows from the catalogue's own structure; inconsistent packages would sell something that can never be available |
| 0037 | **Price is not part of a plan or of entitlement.** A future price is a separate versioned definition; no price table until the price decisions exist | The brief: no price in entitlement logic and no tables for hypothetical billing. SaaS.1 places price consumers in WS3 and WS5 |

**Not decided (no ADR):** packaging content, limit values, prices, limit modes, trials, plan-version migration, a default plan, operator roles, tenant-local dates.

## 15. OPEN ITEMS

| # | Item | Owner |
|---|---|---|
| 1 | **Leaked demo credential, still active.** The pre-SaaS.2 demo tenant-administrator account (an address on the owner's domain) still accepts the old seeded credential in `hcm` and `hcm_ux_showcase`, re-checked during SaaS.5 by hash comparison (nothing printed or changed). The credential is also in git history. Operational security prerequisite: rotate it | Owner |
| 2 | **Pre-existing WCAG contrast of validation messages:** 4.33:1 against the 4.5:1 required. It is the design-system token used by every form's error text; seen again on the assign modal. Not in SaaS.5's scope; unchanged | UX / design system |
| 3 | **Price decisions before any price definition can be built:** D-1 pricing metric, D-3 currencies and markets, the billing intervals to offer, D-10 tax-inclusive display, D-12 free plan; the consumers are WS3 and WS5 (ADR-0037) | Product / commercial |
| 4 | **Limit decisions before enforcement or metering:** limit values (D-1, D-14); limit modes, hard, soft or grace (D-16); what "per month" means; usage meters for the seven unmeasured limits (WS4) | Product / commercial, WS4 |
| 5 | **Still open from earlier phases:** packaging content; trials (D-4); moving tenants to newer versions (D-5, ADR-0021: a subscription-era policy); a default or Legacy plan (ADR-0026); operator roles (D-15, G-PLAT-1); tenant-local dates | Owner / product |
| 6 | **The visual suite depends on external hosts** (`fonts.bunny.net`, `ui-avatars.com`) | UX test infrastructure |

## 16. PRODUCTION READINESS

**SaaS.5 COMPLETE.**

**PeopleOS is not production-ready.** Overall production readiness remains governed by the master roadmap:
- statutory verification (0 / 24);
- DR and staging verification, operator sign-off;
- rotating the leaked credential;
- the remaining commercial workstreams: subscriptions and trials, metering and enforcement, billing, tax and payments, self-service, offboarding, the control plane, and commercial validation.
