# UX.18 Performance, Accessibility and Visual Regression Report

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** UX.18, the engineering validation and hardening gate after the UX transformation. It does not cover UX.19 (product and UX sign-off), staging, production or production readiness. Nothing was pushed, merged or deployed.

Companion documents:
- [Accessibility audit](UX-18-Accessibility-Audit.md);
- [Visual regression report](UX-18-Visual-Regression-Report.md);
- evidence in `docs/ux/ux18/evidence/`.

## 1. Executive summary

PeopleOS can now show, with repeatable evidence, that the UX transformation is fast at 10,585 employees, accessible on the surfaces audited, visually guarded, and stable across Chromium, Firefox and the WebKit engine. Security was not weakened along the way.

- **Performance.**
  - Measured first, at 100, 1,000 and 10,585 employees, for every role surface (93 measurements per scale; median and p95; queries; database time; per-component server time).
  - Fixed where the SQL showed it. The change feed checked visibility per person; the directory applied the organisation scope twice; the signed-in person's own record was looked up up to a dozen times per page; the closed command center built its suggestions on every page load.
  - At 10k HR Home went from 2,025 to 880 ms (469 to 146 queries), the HR directory from 2,168 to 760 ms, payroll Home from 1,447 to 606 ms and manager My Work from 899 to 731 ms; every measured surface is faster or unchanged at every scale, in a before/after run under identical conditions (§8).
  - Every equivalence is proven by tests, and two mutation checks fail when a fix is reverted.
- **Accessibility.** A full audit: 312 checks (52 page states × phone, tablet, desktop × light, dark), plus scripted keyboard, reflow, text-spacing, motion and accessibility-tree reviews, and a Firefox and WebKit-engine subset.
  - The first audit found 14 WCAG failures (3 distinct causes), and the keyboard review found a focus-order defect that UX.17 had introduced.
  - All are fixed; the final audit has **0** WCAG violations. The UX.15, UX.16 and UX.17 regression sets have 0 too.
- **Visual regression.** An automated Playwright suite with 120 reviewed baselines across 6 personas, phone, tablet (portrait and landscape) and desktop, light and dark, with a cross-engine smoke set.
  - It is deterministic: frozen data and clock, and three clean runs.
  - It found one real defect on the way: a sheet that never showed its loading state.
- **Browsers and devices.**
  - The inherited Firefox `[object Object]` error is explained and fixed: 57 of 96 loads before, 0 of 96 after (§15).
  - Android Back now closes the topmost overlay first.
  - Tablet sizes are validated.
  - **Apple Safari and Android Chrome could not be tested here.** There is no Apple device or simulator. The Android emulator could not run alongside the owner's applications: twice it exhausted memory, and the OOM killer ended the session and the owner's editor. This is recorded as an environment limitation, not claimed (§12).
- **Tests and test data.** Full suite 1,089 tests, 1,029 passed, 60 skipped (MySQL-only), 0 failed; MySQL 60/60; security set 257/257; 60 browser tests, the visual suite, the 216-load responsive matrix, Pint, PHP syntax, Blade compilation and the asset build pass. The MySQL suite's one-off duplicate-e-mail failure (UX.17) is fixed at its cause.

**Verdict: UX.18 — COMPLETE** (§22). Every item of the completion gate is met. Two are met as documented environment limitations rather than results: Apple Safari and Android Chrome, with the evidence in `environment-limits.txt`. They are validation gaps for staging, not known defects (§12, §20).

## 2. Starting commit

`69f6386` "docs: UX17 mobile and responsive experience report" (UX.17 complete; clean tree; 129 ahead of origin).

## 3. Final commit

The documentation commit, "docs: UX.18 performance, accessibility and visual regression reports", adds these three reports and the evidence. It is the last commit of UX.18. A commit cannot quote its own hash, so the hash is in `git log` and the closing response. The last code commit is `cc3ae08`.

## 4. Commits created

| # | Commit | Subject | Files | Lines |
|---|---|---|---|---|
| 1 | `11ae8f1` | test: automated visual regression with reviewed baselines | 121 | +436 / −4 |
| 2 | `0e78349` | perf: remove repeated and per-person queries from role surfaces | 19 | +606 / −27 |
| 3 | `24fd040` | ux: Back closes the topmost overlay before leaving the page | 7 | +253 / −2 |
| 4 | `ecfd836` | fix: no uncaught "[object Object]" when leaving a page mid-request | 4 | +60 / −9 |
| 5 | `dae9409` | test: reproducible MySQL suite runs, no duplicate factory e-mails | 1 | +16 / −1 |
| 6 | `3826795` | ux: accessibility fixes from the full UX.18 audit | 23 | +362 / −18 |
| 7 | `1454a73` | test: landscape tablet coverage; the visual suite creates its own database | 12 | +18 / −7 |
| 8 | `a546433` | fix: reveal the chosen view chip once web fonts have loaded | 2 | +16 / −5 |
| 9 | `cc3ae08` | test: visual baselines for the target-size fix; frozen sessions that do not expire | 19 | +6 / −1 |
| 10 | (final) | docs: UX.18 performance, accessibility and visual regression reports | reports and evidence | — |

Line counts for baseline images are counted as binary. No history was rewritten.

## 5. Files changed

**Commits 1 to 9:** 171 files, +1,756 / −57 lines. Without the 120 baseline images, the lock file and the evidence scripts, that is 46 files, +1,231 / −57.

| Area | Files |
|---|---|
| Application (16 PHP files) | `WorkforceMetrics`, `ChangeFeed`, `PeopleVisibility`, `AccessScope`, **`CurrentEmployee` (new)**, `PerformanceRelationships`, `FeatureFlags`, `MyCompensation`, `MyHr`, `MyTeam`, `People`, `LearningActions`, `ServiceDeskActions`, `CommandCenter`, `AppServiceProvider`, `AdminPanelProvider` |
| Configuration and seeders | `config/peopleos.php` (the visual clock setting), `UxShowcaseNotificationsSeeder` |
| Front end | `resources/js/peopleos.js`, `resources/css/filament/admin/theme.css` |
| Blade views (9) | `errors/404`, `errors/pos-layout`, `employees/workspace`, `experience/approvals`, `experience/my-work`, `pages/home`, `livewire/experience/{ai-assistant, command-center, drawer-host}` |
| PHP tests | `Ux18PerformanceTest` (new), `VisualClockGuardTest` (new), `tests/Pest.php` (the MySQL e-mail provider) |
| Browser tests | `tests/browser/` (new): `playwright.config.mjs`, `back`, `aborted-calls`, `focus-order` |
| Visual regression | `tests/visual/` (new): config, specs, personas, setup, `prepare.sh`, `serve.sh`, `stabilise.css`, README; 120 baselines |
| Tooling | `package.json` (scripts; `@playwright/test` 1.62.1), `package-lock.json`, `.gitignore` (run output) |
| Documentation (final commit) | The three UX.18 reports; `docs/ux/ux18/evidence/` (51 files; four scripts were committed earlier with the code they measured) |

No migration, permission key, route or policy was added or changed.

## 6. Performance baseline

**Method** (`perf-measure.php`, `perf-profile.php`):
- **In-process server measurement.** A fresh application per request, through the full kernel, middleware, tenancy, policies and scopes, as each persona. Two warm-up requests, then nine measured; median and p95.
- **What is recorded:** wall time, queries, database time, response and Livewire snapshot bytes, and each Livewire component's own mount, render and dehydrate time (with Livewire's profiler). That last figure is what isolates the phone bar.
- **Query profiles:** every statement with its time and the first application frame that issued it; exact duplicates flagged.
- **Browser timing:** separately, in Chromium (§6.3).

**Scale databases** (disposable, fictional, guarded `*_showcase` seeders; built by `build-scale.sh` from `UxShowcaseSeeder` and `UxScaleShowcaseSeeder`):

| Database | Employees | In probation | Notice period | Exited | Departments | Line relationships | Other relationship types | Pending leave | Timeline entries | Largest line team |
|---|---|---|---|---|---|---|---|---|---|---|
| `hcm_ux18_s100_showcase` | 100 | 20 | 1 | 1 | 24 | 99 | 6 | 7 | 396 | 29 |
| `hcm_ux18_s1k_showcase` | 1,000 | 65 | 16 | 1 | 24 | 999 | 37 | 18 | 3,278 | 29 |
| `hcm_ux18_s10k_showcase` | **10,585** | 607 | 197 | 248 | 25 | 10,582 | 282 | 434 | 34,375 | 450 (edge case: a flat team; a manager with 300 pending requests) |

- **Personas:** the showcase's own (16 users), with the same roles and scope rows as the 8090 showcase. HR (Neha) is scoped to one company, so her directory and Home are organisation-scoped at scale; the manager (Amit) leads team Platform.
- **Lifetime:** all three databases were dropped after validation (§19).
- **Larger scale:** the existing seeder tops out at the 10,585-person shape, and no larger infrastructure exists; nothing beyond it was measured.

### 6.1 Server time before UX.18, 10,585 employees

The table shows the baseline at 10,585 employees; the 100 and 1,000 baselines are in `perf-before-*.json`. Median and p95 are in ms.

| Role | Surface | Median | p95 | Queries | DB time (median) | Phone bar component |
|---|---|---|---|---|---|---|
| Employee | Home | 376 | 443 | 191 | 114 | 4.2 ms |
| Employee | My work | 234 | 252 | 127 | 70 | 3.9 ms |
| Employee | Directory | 204 | 210 | 101 | 58 | 4.1 ms |
| Employee | My HR | 239 | 271 | 136 | 76 | 4 ms |
| Employee | Employee 360 (own, refused) | 25 | 44 | 15 | 9 | 0 ms |
| Employee | Notifications | 191 | 253 | 90 | 50 | 3.8 ms |
| Manager | Home | 1028 | 1133 | 292 | 363 | 8.8 ms |
| Manager | My work | 786 | 858 | 190 | 242 | 8.5 ms |
| Manager | My team | 522 | 553 | 154 | 170 | 8 ms |
| Manager | People | 618 | 656 | 121 | 329 | 7.9 ms |
| Manager | Approval Center | 777 | 1047 | 197 | 201 | 8.3 ms |
| Manager | Employee 360 (report) | 809 | 860 | 291 | 260 | 9.5 ms |
| Manager | Notifications | 351 | 583 | 113 | 106 | 8.2 ms |
| HR | Home | 1735 | 1910 | 469 | 622 | 3.2 ms |
| HR | My work | 795 | 885 | 175 | 218 | 3.1 ms |
| HR | People directory | 1478 | 1788 | 109 | 1128 | 3.3 ms |
| HR | Employee register | 1087 | 1243 | 167 | 491 | 3.4 ms |
| HR | Employee 360 | 781 | 846 | 272 | 230 | 3 ms |
| HR | Service requests | 511 | 565 | 129 | 118 | 3.2 ms |
| Executive | Home | 628 | 753 | 195 | 417 | 3 ms |
| Executive | My work | 341 | 433 | 136 | 166 | 3.3 ms |
| Executive | Workforce pulse | 1227 | 1703 | 118 | 522 | 3.2 ms |
| Administrator | Home | 730 | 862 | 214 | 144 | 3 ms |
| Administrator | My work | 691 | 829 | 173 | 121 | 3.4 ms |
| Administrator | Admin Centre | 280 | 376 | 100 | 56 | 3.1 ms |
| Administrator | Users | 403 | 433 | 104 | 60 | 3.2 ms |
| Administrator | People | 569 | 746 | 112 | 296 | 3 ms |
| Payroll | Home | 1190 | 1224 | 409 | 466 | 3.6 ms |
| Payroll | My work | 371 | 421 | 120 | 101 | 3.4 ms |
| Payroll | Payroll control room | 768 | 1001 | 100 | 500 | 3.3 ms |
| Payroll | People | 1492 | 1678 | 100 | 1138 | 3.4 ms |

### 6.2 What the profiles showed

| Surface | Finding (10,585 employees) |
|---|---|
| HR Home (469 queries, 695 ms DB) | The "What changed" feed checked each listed person separately: `PerformanceRelationships::manages` (2 queries) and `AccessScopes::allowsEmployeeId` (1) per person, 80 people: 247 queries, 300 ms. 209 of the 469 queries were exact duplicates |
| HR People directory (1,128 ms DB) | The visibility query applied the organisation scope twice (explicitly and again through the global scope) in every directory query; filter options were two passes; "more than eight people" counted everyone |
| Every page | The signed-in person's own employee record was re-queried by several pages and the navigation (up to 14 identical queries); feature flags read the cache store on each check (up to 37 reads); the closed command center built its suggestions on every load (≈33 ms of server time on every page for managers, HR and payroll) |
| Workforce pulse, executive Home | Twelve full headcount counts for the trend (390 ms) |
| Phone bar (UX.17 handoff: "+40–55 ms") | The bar component itself: 8–9 ms for managers (its decision count and the My team check), 3–4 ms for others. The UX.17 figure was a whole-page difference that also included the other shell components and the command center above |
| My Work (managers, executives, administrators) | The same per-request repetitions as above, plus the decision count; no My Work-specific query problem |

### 6.3 Browser timing

**Browser timing on the 10,585-employee data** (Chromium, the UX.17 and the final code served side by side and loaded alternately). Two runs (`browser-perf.mjs`, `browser-perf.json`; `cpu18` in `browser-cpu.json`):

- **Time to largest contentful paint (localhost, 5 loads per page).**
  - The median over the 22 page and viewport pairs is 1,388 → 1,332 ms; the sum is 40.9 → 33.1 s. HR Home on a phone went 6,192 → 2,896 ms; the HR directory on desktop 4,032 → 2,488 ms.
  - Wall time through PHP's single-host development server is noisy with five samples, and a few rows moved the other way (manager Home on a phone, 2,292 → 2,676 ms).
- **Browser CPU per load** (Chromium's own task, script, layout and style counters, 8 loads per side, alternating): **lower on every page measured**, by 7–25 %.
  - Examples: HR Home desktop 358 → 271 ms; the administrator Home 319 → 245 ms; manager My Work 378 → 312 ms.
  - Scripting is unchanged or lower, so the overlay Back manager and the other UX.18 client changes cost nothing measurable.
- **Network time** is near zero by construction (localhost); real network latency belongs to staging measurements.
- **HTML size** grew by 4–13 KB per page, from the notification list rendered with the page.

## 7. Performance fixes

| Fix | Correctness and security |
|---|---|
| **Change feed:** one `AuthorizationContext` pass over the people listed; scope answers primed in one set query (`primeEmployeeIds`); "does this manager manage this person" answered from the manager's current reports inside a pass | The same checks decide every item; facts live only for the pass. `Ux18PerformanceTest`: identical items, out-of-scope people still excluded, query count no longer grows with the number of people. Reverting the pass fails the test (mutation check) |
| **Directory:** the organisation scope applied once, explicitly, when the viewer is the signed-in person (both constraints stay when asked about someone else); filter options in one pass; "more than eight" reads nine ids | `Ux18PerformanceTest`: exactly the people the global scope allows, one scope subquery; fail-closed for another viewer. Reverting fails the test |
| **`CurrentEmployee`:** the signed-in person's own employee record, looked up once per request (the same query and scopes), keyed by tenant, user and scoping state, cleared when an employee is saved; request-scoped | Never crosses tenants or users (test with a second tenant); follows writes |
| **Feature flags:** read once per request (still cached per tenant, invalidated on change) | A change in the same request is seen (test) |
| **Command center:** suggestions built when it opens, not on every page load; "Loading suggestions…" while they arrive | The same `CommandSearch`, ranking and permissions when it opens (test) |
| **Workforce headcount:** one aggregate query for every day of a trend; a memo only for the length of one operation | Equal to the single-day counts (test); separate calls still count afresh (the existing reporting and scale tests caught a first version that cached across calls, which was corrected) |
| **My team:** answered from the manager lens (the same "has current direct reports" check, already resolved for the request) | Same answer (test) |
| **Database notifications load with the page** (Firefox fix, §15) | About +10 ms server time and one query per page; one HTTP request fewer |

**Caches introduced** (all request-scoped):
- *Feature flags:* the existing per-tenant cache key `tenant:{id}:features` is unchanged, with an in-memory copy for the request.
- *`CurrentEmployee`:* keyed by tenant, user, scoping and bypass state.
- *Authorisation facts:* exist only inside an `AuthorizationContext::run()` pass.

None is global, none crosses tenants or users, and each is invalidated by the write it depends on or forgotten at the end of the request. No index was added; the profiles showed repetition, not missing indexes.

## 8. Performance results and remaining issues

**How before and after were compared.**
- **Why not the separate runs:** a separate "after" run right after the fixes came out 31 % faster in total at 10k, but a later "final" run came out 16 % slower and was discarded as invalid. Runs taken at different times are not comparable here. During it the workstation's swap was full (4,078 of 4,095 MB), 409 MB of MySQL was paged out and the load average reached 5.3. Database time rose on pages with fewer queries, and a trivial refused request got slower too (`perf-final-invalid.log`).
- **Interleaved method instead:** the final comparison measures the UX.17 code (a worktree at `69f6386`) and the final code alternately, surface by surface (before, final, then final, before; 5 measured requests after 2 warm-ups each time). Machine drift hits both sides alike (`perf-interleave.sh`, `perf-interleave.jsonl`). The table gives the median of the two rounds.
- **The † rows:** HR and administrator My Work at 100 employees measured +14 % in the interleaved run. A four-round recheck (9 requests each, alternating) showed both faster (`perf-recheck100.jsonl`), so those rows show the recheck.

**Results: server time, queries and database time.**

| Surface | Role | Dataset | Before (median) | After (median) | Delta | Queries before | Queries after | DB time before | DB time after | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| Home | Employee | 10,585 | 388 ms | 294 ms | -24 % | 189 | 110 | 112 ms | 70 ms | improved |
| My work | Employee | 10,585 | 274 ms | 250 ms | -9 % | 127 | 68 | 74 ms | 47 ms | improved |
| Directory | Employee | 10,585 | 188 ms | 186 ms | -1 % | 101 | 45 | 50 ms | 32 ms | unchanged |
| Home | Manager | 10,585 | 1255 ms | 928 ms | -26 % | 289 | 183 | 428 ms | 293 ms | improved |
| My work | Manager | 10,585 | 899 ms | 731 ms | -19 % | 190 | 121 | 274 ms | 200 ms | improved |
| My team | Manager | 10,585 | 525 ms | 389 ms | -26 % | 154 | 87 | 182 ms | 112 ms | improved |
| People | Manager | 10,585 | 664 ms | 472 ms | -29 % | 121 | 60 | 361 ms | 238 ms | improved |
| Approval Center | Manager | 10,585 | 821 ms | 598 ms | -27 % | 197 | 119 | 216 ms | 138 ms | improved |
| Employee 360 (report) | Manager | 10,585 | 889 ms | 642 ms | -28 % | 291 | 188 | 280 ms | 185 ms | improved |
| Notifications | Manager | 10,585 | 348 ms | 214 ms | -38 % | 113 | 51 | 102 ms | 53 ms | improved |
| Home | HR | 10,585 | 2025 ms | 880 ms | -57 % | 469 | 146 | 721 ms | 274 ms | improved |
| My work | HR | 10,585 | 1265 ms | 778 ms | -38 % | 175 | 117 | 328 ms | 199 ms | improved |
| People directory | HR | 10,585 | 2168 ms | 760 ms | -65 % | 109 | 54 | 1648 ms | 510 ms | improved |
| Employee register | HR | 10,585 | 1183 ms | 1035 ms | -13 % | 167 | 113 | 572 ms | 476 ms | improved |
| Employee 360 | HR | 10,585 | 1336 ms | 870 ms | -35 % | 272 | 189 | 378 ms | 211 ms | improved |
| Home | Executive | 10,585 | 1262 ms | 886 ms | -30 % | 195 | 107 | 809 ms | 528 ms | improved |
| My work | Executive | 10,585 | 580 ms | 536 ms | -8 % | 136 | 80 | 264 ms | 252 ms | improved |
| Workforce pulse | Executive | 10,585 | 1620 ms | 1179 ms | -27 % | 118 | 49 | 694 ms | 310 ms | improved |
| Home | Administrator | 10,585 | 1096 ms | 906 ms | -17 % | 214 | 127 | 216 ms | 142 ms | improved |
| My work | Administrator | 10,585 | 748 ms | 655 ms | -12 % | 173 | 111 | 139 ms | 100 ms | improved |
| Users | Administrator | 10,585 | 372 ms | 309 ms | -17 % | 104 | 45 | 66 ms | 34 ms | improved |
| Home | Payroll | 10,585 | 1447 ms | 606 ms | -58 % | 409 | 89 | 556 ms | 210 ms | improved |
| My work | Payroll | 10,585 | 416 ms | 286 ms | -31 % | 120 | 65 | 120 ms | 66 ms | improved |
| People | Payroll | 10,585 | 1909 ms | 907 ms | -52 % | 100 | 45 | 1518 ms | 625 ms | improved |
| Home | Employee | 1,000 | 438 ms | 367 ms | -16 % | 189 | 110 | 117 ms | 80 ms | improved |
| My work | Employee | 1,000 | 409 ms | 298 ms | -27 % | 127 | 68 | 96 ms | 50 ms | improved |
| Directory | Employee | 1,000 | 292 ms | 238 ms | -19 % | 101 | 45 | 61 ms | 32 ms | improved |
| Home | Manager | 1,000 | 1070 ms | 845 ms | -21 % | 286 | 180 | 334 ms | 228 ms | improved |
| My work | Manager | 1,000 | 816 ms | 618 ms | -24 % | 190 | 121 | 228 ms | 154 ms | improved |
| My team | Manager | 1,000 | 650 ms | 490 ms | -25 % | 144 | 77 | 164 ms | 98 ms | improved |
| People | Manager | 1,000 | 578 ms | 428 ms | -26 % | 121 | 60 | 170 ms | 106 ms | improved |
| Approval Center | Manager | 1,000 | 510 ms | 372 ms | -27 % | 159 | 81 | 149 ms | 87 ms | improved |
| Employee 360 (report) | Manager | 1,000 | 844 ms | 682 ms | -19 % | 291 | 188 | 266 ms | 179 ms | improved |
| Notifications | Manager | 1,000 | 414 ms | 279 ms | -33 % | 113 | 51 | 108 ms | 53 ms | improved |
| Home | HR | 1,000 | 1407 ms | 699 ms | -50 % | 386 | 137 | 460 ms | 176 ms | improved |
| My work | HR | 1,000 | 620 ms | 586 ms | -5 % | 166 | 108 | 177 ms | 142 ms | improved |
| People directory | HR | 1,000 | 628 ms | 448 ms | -29 % | 108 | 53 | 208 ms | 98 ms | improved |
| Employee register | HR | 1,000 | 844 ms | 741 ms | -12 % | 167 | 113 | 223 ms | 177 ms | improved |
| Employee 360 | HR | 1,000 | 990 ms | 862 ms | -13 % | 272 | 189 | 278 ms | 209 ms | improved |
| Home | Executive | 1,000 | 444 ms | 286 ms | -36 % | 195 | 107 | 153 ms | 79 ms | improved |
| My work | Executive | 1,000 | 352 ms | 266 ms | -24 % | 136 | 80 | 98 ms | 64 ms | improved |
| Workforce pulse | Executive | 1,000 | 498 ms | 470 ms | -6 % | 118 | 49 | 101 ms | 64 ms | improved |
| Home | Administrator | 1,000 | 612 ms | 494 ms | -19 % | 214 | 127 | 146 ms | 91 ms | improved |
| My work | Administrator | 1,000 | 560 ms | 482 ms | -14 % | 173 | 111 | 117 ms | 85 ms | improved |
| Users | Administrator | 1,000 | 380 ms | 295 ms | -22 % | 104 | 45 | 62 ms | 34 ms | improved |
| Home | Payroll | 1,000 | 1044 ms | 326 ms | -69 % | 335 | 89 | 352 ms | 80 ms | improved |
| My work | Payroll | 1,000 | 350 ms | 286 ms | -18 % | 120 | 65 | 96 ms | 62 ms | improved |
| People | Payroll | 1,000 | 456 ms | 305 ms | -33 % | 100 | 45 | 162 ms | 81 ms | improved |
| Home | Employee | 100 | 340 ms | 274 ms | -19 % | 189 | 110 | 100 ms | 72 ms | improved |
| My work | Employee | 100 | 266 ms | 206 ms | -22 % | 127 | 68 | 70 ms | 44 ms | improved |
| Directory | Employee | 100 | 214 ms | 174 ms | -19 % | 101 | 45 | 53 ms | 32 ms | improved |
| Home | Manager | 100 | 940 ms | 706 ms | -25 % | 286 | 180 | 280 ms | 186 ms | improved |
| My work | Manager | 100 | 772 ms | 613 ms | -21 % | 190 | 121 | 217 ms | 138 ms | improved |
| My team | Manager | 100 | 556 ms | 391 ms | -30 % | 144 | 77 | 142 ms | 76 ms | improved |
| People | Manager | 100 | 393 ms | 272 ms | -31 % | 121 | 60 | 106 ms | 57 ms | improved |
| Approval Center | Manager | 100 | 386 ms | 297 ms | -23 % | 159 | 81 | 112 ms | 70 ms | improved |
| Employee 360 (report) | Manager | 100 | 798 ms | 505 ms | -37 % | 291 | 188 | 236 ms | 135 ms | improved |
| Notifications | Manager | 100 | 297 ms | 200 ms | -32 % | 113 | 51 | 85 ms | 42 ms | improved |
| Home | HR | 100 | 1189 ms | 664 ms | -44 % | 275 | 137 | 336 ms | 154 ms | improved |
| My work | HR | 100 | 766 ms† | 572 ms† | -25 % | 166 | 108 | 232 ms | 216 ms | improved |
| People directory | HR | 100 | 745 ms | 472 ms | -37 % | 108 | 53 | 161 ms | 70 ms | improved |
| Employee register | HR | 100 | 942 ms | 780 ms | -17 % | 167 | 113 | 208 ms | 138 ms | improved |
| Employee 360 | HR | 100 | 1288 ms | 934 ms | -27 % | 272 | 189 | 318 ms | 201 ms | improved |
| Home | Executive | 100 | 511 ms | 348 ms | -32 % | 195 | 107 | 144 ms | 73 ms | improved |
| My work | Executive | 100 | 360 ms | 320 ms | -11 % | 136 | 80 | 82 ms | 59 ms | improved |
| Workforce pulse | Executive | 100 | 357 ms | 274 ms | -23 % | 118 | 49 | 78 ms | 36 ms | improved |
| Home | Administrator | 100 | 696 ms | 490 ms | -29 % | 214 | 127 | 148 ms | 84 ms | improved |
| My work | Administrator | 100 | 598 ms† | 473 ms† | -21 % | 173 | 111 | 100 ms | 90 ms | improved |
| Users | Administrator | 100 | 464 ms | 362 ms | -22 % | 104 | 45 | 62 ms | 31 ms | improved |
| Home | Payroll | 100 | 934 ms | 434 ms | -54 % | 224 | 89 | 261 ms | 94 ms | improved |
| My work | Payroll | 100 | 395 ms | 326 ms | -17 % | 120 | 65 | 98 ms | 58 ms | improved |
| People | Payroll | 100 | 473 ms | 307 ms | -35 % | 100 | 45 | 106 ms | 48 ms | improved |

**Summary by scale** (sum of the 24 surfaces' medians, same run):

| Dataset | Before | After | Change | Slower surfaces |
|---|---|---|---|---|
| 10,585 employees | 23,678 ms | 15,296 ms | **−35 %** | none |
| 1,000 employees | 15,207 ms | 11,162 ms | **−27 %** | none |
| 100 employees | 14,754 ms | 10,992 ms | **−25 %** | none (after the recheck) |

**Status by handed-over item:**

| UX.17 handoff | Before → after (10,585) | Status |
|---|---|---|
| HR Home at scale | 2,025 → 880 ms; 469 → 146 queries | **Improved** |
| People Directory database time (scoped HR) | 1,648 → 510 ms DB; 2,168 → 760 ms | **Improved** |
| My Work (manager, executive, administrator) | 899 → 731, 580 → 536, 748 → 655 ms | **Improved** (executive: −8 %) |
| Phone bar component cost | Manager 10.8 → 3.2 ms; others 3–6 ms (unchanged). The "+40–55 ms" in UX.17 was a whole-page difference, mostly the command center building suggestions on every load (now 3–5 ms, from 30–60 ms) | **Improved** |
| Employee 360 (HR, manager) | 1,336 → 870, 889 → 642 ms | **Improved** |
| Representative mobile surfaces (the server HTML is the same for every viewport; browser timing below) | | **Improved** |

**Remaining (not regressions):**
- **Workforce pulse (1,179 ms) and the executive Home (886 ms) at 10k.** Their remaining cost is the analytics metrics (attrition, tenure, cost), each one aggregate query over 10,585 people; the next step is precomputed daily snapshots. **Requires architectural follow-up** (production hardening), not UX.
- **The employee register (Filament table, 1,035 ms at 10k).** The table's own count and pagination queries. Part of the module-grid work (UX.19).
- **Database notifications.** They now load with the page (+10–30 ms server time; the HTML grows by about 10 KB), in exchange for one request fewer and the Firefox fix. **Acceptable** (§15).
- **Scale.** No dataset larger than 10,585 was built (the seeder's shape); a 50k+ run belongs to staging.

## 9. Accessibility audit

Full details: [UX-18-Accessibility-Audit.md](UX-18-Accessibility-Audit.md).

| Run | Checks | WCAG violations |
|---|---|---|
| First full audit (axe-core 4.13, Chromium 151) | 312 | 14 (3 causes) |
| **Final full audit** | **312** | **0** |
| Firefox 153 subset / WebKit engine 26.5 subset | 80 / 80 | 0 / 0 |
| UX.17 / UX.16 / UX.15 regression sets | 58 / 46 / 78 | 0 / 0 / 0 |
| Scripted keyboard review | 5 roles, 40 stops each, 5 overlays, list keys | 1 defect found and fixed (focus order) |
| Reflow (320 px, 200 % zoom), text spacing, reduced motion | 6 + 6 pages, 4 pages, palette open | Pass |

No human screen-reader pass was possible here (§7 of the audit).

## 10. Accessibility fixes

| # | WCAG | Fix |
|---|---|---|
| A1 | 2.4.3 Focus order | For anyone with several views, Tab no longer skips the skip link, header and navigation (UX.17 regression: `scrollIntoView` moved the focus starting point) |
| A2 | 1.4.3 Contrast | Filament 600 status text shades take the PeopleOS status tokens |
| A3 | 1.4.3 Contrast | Error pages: dark label on the dark-mode primary button |
| A4 | 2.1.1 Keyboard | The hire wizard's step header wraps on tablets instead of scrolling with nothing focusable |
| A5 | 2.5.8 Target size | Table row actions and intelligence links at least 24 px |
| A6–A8 | 4.1.2 / 1.3.1 (best practice) | A real list for the approval queue; the sheet is a `div` dialog; no duplicate 360 landmarks |
| A9 | 4.1.3 / loading state | Event-opened sheets show and announce their loading skeleton |
| A10 | Device wording | The 404 page no longer assumes a keyboard |

The three UX.17 fixes (phone search name, unread marker, read-row contrast) still pass.

## 11. Visual regression system

Full details: [UX-18-Visual-Regression-Report.md](UX-18-Visual-Regression-Report.md).

- `tests/visual/`: Playwright Test `toHaveScreenshot`, 41 screens, **120 reviewed baselines**.
- **Coverage:** 6 personas; phone 390 × 844; tablet 768 × 1024 and 1024 × 768; desktop 1440 × 900; light and dark; a Firefox and WebKit-engine smoke set.
- **Determinism:** a frozen clock, only for `*_visual_showcase` databases outside production (7 guard tests); data rebuilt for every run; one worker; animations, caret and spinners stilled; harness races fixed rather than thresholds loosened.
- **Commands:** `npm run visual:test`, and `visual:update` only after review. Every difference is classified as intentional, regression, environment noise or baseline update.
- **Run results:** the final run on data rebuilt at the frozen moment, after the last reviewed baseline update, was **120 passed, 0 failed** (5.4 min, one worker). The 198 skipped are screen, theme and project combinations a shot does not declare. Before that: three clean full runs, and the palette screens 48/48 when repeated.

## 12. Browser and device validation

| Environment | Status | Evidence |
|---|---|---|
| Chromium 151 (Playwright) | **Tested:** responsive matrix, Back tests, visual suite, accessibility | §13, §14, §11 |
| Firefox 153 (Playwright) | **Tested:** matrix, Back and aborted-call tests, axe subset, visual smoke, the `[object Object]` investigation | §13–§15 |
| WebKit engine 26.5 (Playwright WPE MiniBrowser; **not Apple Safari**) | **Tested:** matrix, Back tests, axe subset, visual smoke | §13, §14 |
| **Apple Safari (iOS, iPadOS, macOS)** | **Not tested.** There is no Apple device, simulator or macOS host in this environment | `environment-limits.txt` (Linux host; no `xcrun` or `safaridriver`) |
| **Android Chrome** | **Attempted, not completed.** The workstation's Android emulator (AVD Pixel 6, Android 14, Chrome) booted, and Chrome and the port mapping were confirmed. But with the owner's applications open, the emulator twice exhausted the 14 GB of memory, at 4 GB and then at 2 GB. The kernel's OOM killer ended the emulator, the test browsers, the showcase server and, the second time, the owner's editor. The emulator is not started again on this machine. No Android Chrome result is claimed | `environment-limits.txt` (kernel OOM records, memory); `android-chrome.mjs` (ready to run on a machine or device that can) |
| Physical phones and tablets | **Not available** | — |

**Recommendation for UX.19 or staging:** run `android-chrome.mjs` against a physical Android phone (USB debugging), and the visual and Back suites in Safari on a Mac or iPhone, before sign-off.

## 13. Responsive validation

**Browser matrix** (`browser-matrix.mjs`, the UX.17 matrix script rerun on the final code; `browser-matrix.json`):
- **What:** each role's Home, My Work and its own bar destination; 6 personas; Chromium, Firefox and the WebKit engine; phone 390, small phone 360, tablet 768 and desktop 1440.
- **Pass criteria:** HTTP 200; no sideways overflow; no script error after settling; the role bar shown on phones and tablets with at least four items and hidden on desktop.

| Engine | Phone 390 | Small phone 360 | Tablet 768 | Desktop 1440 | Total |
|---|---|---|---|---|---|
| Chromium 151 | 18 / 18 | 18 / 18 | 18 / 18 | 18 / 18 | **72 / 72** |
| Firefox 153 | 18 / 18 | 18 / 18 | 18 / 18 | 18 / 18 | **72 / 72** (UX.17: 70 / 72, both misses the `[object Object]` error, now fixed) |
| WebKit engine 26.5 (not Safari) | 18 / 18 | 18 / 18 | 18 / 18 | 18 / 18 | **72 / 72** |

**Tablet-class sizes** (`layout-probe.mjs`, `tablet-probe.json`; 36 pages per size, 6 personas, Chromium, touch):

| Size | Navigation | Sideways overflow | Script errors | Non-200 |
|---|---|---|---|---|
| 768 × 1024 (portrait) | Role bar, no rail | 0 | 0 | 0 |
| 820 × 1180 (iPad-class portrait) | Role bar, no rail | 0 | 0 | 0 |
| 1024 × 768 (landscape, at the rail breakpoint) | Rail, no bar | 0 | 0 | 0 |
| 1180 × 820 (larger landscape) | Rail, no bar | 0 | 0 | 0 |

- **No awkward middle state.** At every size exactly one primary navigation is shown, never both and never neither. At 1024 px the rail leaves 768 px for content, a single column that matches the 768 portrait layout. Touch wording follows the pointer, not the width, so a landscape tablet still says "tap".
- **Visual coverage.** The landscape set (7 baselines) and the portrait set (16) are in the visual suite.

**Regression checklist (UX.18 brief §24):**

| Check | Result |
|---|---|
| No unintended horizontal scroll | 0 px overflow on every audited page at 320, 390, 768, 820, 1024, 1180 and 1440 px, and at 200 % zoom |
| No clipped actions, overlay collisions or navigation overlap | The bar steps aside under every overlay (UX.17, re-verified by the Back tests); modal footers are sticky and uncovered |
| No form-button obstruction | The Request leave Submit stays visible (visual baseline `employee-request-leave-form`) |
| No unreadable text | Final axe: 0 contrast violations in light and dark |
| No broken sticky positioning | The section navigation, modal footers and table headers in the visual baselines |
| No accidental desktop-only wording | Touch wording by pointer (UX.17); the last instance, on the 404 page, fixed |
| No role-specific navigation leakage | `Ux17MobileTest` (every bar item passes its own access check) in the security set |

## 14. Android Back behaviour

UX.17 found that Android Back left the page while a sheet was open. The cause: Livewire's SPA navigation owns history, and nothing gave an overlay its own history step.

**Fix** (`overlayBack` in `peopleos.js`):
- **Following overlays.** It follows open overlays from the DOM: Filament modals, the phone menu, the command center, drawers and sheets, the assistant.
- **One step per overlay.** Each overlay gets a same-URL history step. Back pops it and closes that overlay; only then does Back navigate.
- **Closing another way.** Esc, Cancel, a click outside or a finished action take the step back out.
- **Following a link from an overlay** removes its steps before navigating, so no stale step is left.
- **Nested overlays** close one Back at a time.
- **Livewire.** The listener runs in the capture phase and handles only its own steps, so Livewire's own Back and Forward are untouched. Livewire treats same-document steps as scroll restorations, which is why the approach is safe.

**Regression tests** (`tests/browser/back.browser.mjs`): command center, Escape, decision sheet, nested command center over a sheet, link from the command center, modal form, assistant, phone menu, directory filters (part of the page, so Back leaves as usual). Results:
- They pass in Chromium (phone and desktop), Firefox and the WebKit engine. The suite's latest full run, with the aborted-call and focus-order tests, was 55 passed and 5 skipped by design.
- The Android hardware Back key could not be pressed on a device (§12). The browser Back these tests use is what Android Chrome's Back triggers.

## 15. Firefox investigation

**Reproduced** (`firefox-repro.mjs`):
- Leaving a page while any Livewire call is in flight makes Livewire reject that call's promise with a plain object (`{status: null, body: null, …}`). With no catch, Firefox and the WebKit engine report an uncaught `[object Object]`; Chromium does not.
- Filament's lazily loaded database notifications (`__lazyLoad`) were in flight on every page load, for every role, so any quick navigation could trigger it. The command center's own calls could do the same.

Each condition: leave the page 0, 150, 400 or 900 ms after it starts loading, 6 trials per timing (24 loads per persona and network).

| Firefox, loads with an uncaught error | Manager | HR | Employee |
|---|---|---|---|
| Before, normal network | 8 of 24 | 7 of 24 | 10 of 24 |
| Before, Livewire slowed 1.5 s | 18 of 24 | 24 of 24 | 21 of 24 |
| **After, normal network** | **0 of 24** | **0 of 24** | — |
| **After, Livewire slowed 1.5 s** | **0 of 24** | **0 of 24** | — |

Same personas before and after (manager and HR): **57 of 96 loads before, 0 of 96 after.** In the WebKit engine the error reproduced before the fix (a targeted run with the notifications request in flight) and is **0 of 32** after (manager, both networks).

- **Is it application-side?** The trigger is general to Livewire, and the call left in flight was Filament's, so ownership is shared. PeopleOS controls both levers, so it is fixed here, with no vendor patch.
- **Is it notification-specific?** The notifications request was the one always in flight; any in-flight call reproduces it (shown with the command center).
- **Role- or page-specific?** No: every role, every page with the shell.
- **Slow network?** Yes: a slow network widens the window to 100 %.

**Fix:**
- database notifications load with the page (`isLazy: false`);
- PeopleOS's own Livewire calls catch an aborted call (`quiet()`).

A browser regression test (`aborted-calls.browser.mjs`) slows every Livewire call and leaves pages mid-load and mid-call in all three engines.

**Follow-up for the Livewire and Filament owners:** an aborted call should not reject with a non-Error object, or should be marked as aborted. Any future uncaught `$wire` call in PeopleOS can reintroduce the symptom; the pattern is recorded in the project conventions.

## 16. Touch-target validation

Measured on every audited page and state (`a11y-audit-final.json`, WCAG 2.5.8 with the spacing exception approximated):

| Viewport | Targets under 24 px without the spacing exception | Owner |
|---|---|---|
| Phone | 1: the "—" placeholder link in the audit list's stacked rows (12 × 48 px) | Filament |
| Tablet | 0 | — |
| Desktop | 0 (after A5: 10 Filament "Edit" row actions and one PeopleOS intelligence link were 20 px tall) | — |

UX.17's coarse-pointer sizes (40 px top bar and space bar, 32 px row actions, 24 px filter removal, 40 px command row actions) remain. The one Filament remainder belongs to the module grids (UX.19). Controls were not enlarged across the board; only targets that failed were changed.

## 17. Security regression

| Requirement | Evidence |
|---|---|
| Role-aware navigation cannot expose inaccessible screens | `Ux17MobileTest` (every bar item passes its own `canAccess()`; fallbacks; fail-closed for another user) passes; unchanged in UX.18 |
| Visual-regression fixtures use no elevated permissions | Each screen signs in as its own persona through the login form (`personas.mjs`); no admin or test user is used for another role's screens |
| Cached answers do not cross users or tenants | `CurrentEmployee` keyed by tenant and user (test across two tenants); feature-flag cache keyed per tenant; authorisation facts live only for a pass |
| Preload payloads do not expose hidden employee data | The command center no longer builds suggestions before it opens; database notifications rendered with the page are the viewer's own (the same Filament query); the snapshot size grew by the notification list only |
| Tenant, organisation and relationship scope; field security; permission checks | The security regression set (§18) |
| Notification deep links, governance and reminder links, multi-role | `Ux17MobileTest`, `Ux16RoleSecurityTest`, `Ux16RoleJourneysTest`, `UxSecurityTest` (in the set) |
| Optimisations keep the same answers | `Ux18PerformanceTest` (8 tests; two mutation checks) |
| The frozen clock cannot reach a real tenant | `VisualClockGuardTest` (7 tests: other databases, production, unset) |

**Security regression set: 257 tests, 257 passed, 4,555 assertions.** It covers:
- the security, tenancy, identity, audit, architecture and notification folders;
- `Ux18PerformanceTest`, `VisualClockGuardTest`, `Ux17MobileTest`;
- the UX.16 role, security and journey tests;
- the UX.15 approval-authorisation and demo-scope tests;
- `UxSecurityTest` and `UxPersonasTest`.

No security boundary was weakened.

## 18. Test results

| Run | Result |
|---|---|
| Baseline (`69f6386`, UX.17 final) | 1,074 tests, 1,014 passed, 60 skipped (MySQL-only), 0 failed |
| **Full suite, final code** (`php artisan test --parallel --processes=2`) | **1,089 tests · 1,029 passed · 60 skipped (MySQL-only, as at baseline) · 0 failed** · 13,354 assertions |
| MySQL concurrency and scale suites (`hcm_p14_concurrency`) | **60 / 60** (244 assertions; a race test's assertion count depends on which side wins). First run in UX.18 after the harness fix: 60/60, 333 users, 333 distinct e-mails |
| Security regression set (§17) | **257 / 257**, 4,555 assertions |
| New PHP tests | `Ux18PerformanceTest` (8: equivalence of every optimisation; two mutation checks), `VisualClockGuardTest` (7) |
| Browser tests (`tests/browser`, Playwright: Chromium phone and desktop, Firefox, WebKit engine) | **60 tests: 55 passed, 5 skipped by design, 0 failed** (3.5 min). The skips are the desktop cases where the overlay does not exist (side pane, rail, filters) and the phone-only chip check |
| Visual regression (`tests/visual`) | **120 baselines: 120 passed, 0 failed** (final run after the last reviewed update; 198 undeclared combinations skipped) |
| Accessibility (axe-core 4.13) | Final full audit 312/0; Firefox 80/0; WebKit 80/0; UX.17/16/15 sets 58/0, 46/0, 78/0 |
| Responsive matrix | 216 / 216 |
| Existing tests changed | **None.** No test was edited to make it pass. When the 360 landmark fix first changed `<section>` wrappers into `<div>`s, a UX.15 test that locates views by their `<section>` tags failed. The markup was changed back to `<section>` without a name rather than editing the test |
| Pint / PHP syntax | Pass / `php -l` clean on all 32 changed PHP files (30 application and test files, 2 evidence scripts) |
| Blade compilation / asset build | Pass: `php artisan view:cache` (Blade templates cached successfully) and `npm run build` (built) |

**Failures met during UX.18 and their classification:**
- **Code regression (fixed before commit).** A first `WorkforceMetrics` memo cached across calls; two existing tests (reporting, workforce scale) caught it.
- **Flaky test-data harness (fixed).** The MySQL duplicate-e-mail issue: the persistent concurrency database plus Faker's per-application `unique()`.
- **Environment.** The 8-process parallel run and the Android emulator exhausted the workstation's memory; the suite was rerun with 2 processes.
- **Harness races (fixed).** In the visual and browser suites: the WebKit engine reaching network idle before Livewire started, a debounced search, a keystroke lost before focus.

## 19. Screenshot and evidence inventory

**Screenshots: 120 reviewed visual baselines** in `tests/visual/__screenshots__/` (§11):

| Project | Count |
|---|---|
| chromium-phone | 51 |
| chromium-tablet | 16 |
| chromium-tablet-landscape | 7 |
| chromium-desktop | 38 |
| firefox-desktop | 4 |
| webkit-phone | 4 |

Light and dark across 6 personas and 41 screens. Actual and diff images of any run are written to `tests/visual/results/` (gitignored); the classified differences of UX.18 are listed in the visual report §6.

**Evidence in `docs/ux/ux18/evidence/`:**

| Area | Files |
|---|---|
| Scale data | `build-scale.sh`, `build-scale.log` |
| Performance (server) | `perf-measure.php`, `perf-profile.php`, `perf-interleave.sh`; `perf-before-{s100,s1k,s10k}.json` (baseline), `perf-after-*.json` (first after-run), `perf-interleave.jsonl` (**the before/after comparison**), `perf-recheck100.jsonl`, `perf-final-invalid-*.json` and `perf-final-invalid.log` (the run discarded for swap pressure, kept for transparency), `profile-*-10k.json` (query profiles) |
| Performance (browser) | `browser-perf.mjs`, `browser-perf.json`, `browser-cpu.mjs`, `browser-cpu.json` |
| Accessibility | `a11y-audit.mjs`, `a11y-manual.mjs`, `axe-drawers18.mjs`; `a11y-audit-first.json`, `a11y-audit-final.json`, `a11y-recheck.json`, `a11y-firefox.json`, `a11y-webkit.json`, `a11y-manual.json`, `axe-ux15-regression.json`, `axe-ux16-regression.json`, `axe-ux17-regression.json`, `axe-drawers-recheck.txt` |
| Firefox investigation | `firefox-repro.mjs`; `firefox-repro-before.json`, `firefox-repro-after.json`, `webkit-repro-after.json` |
| Responsive and tablet | `browser-matrix.mjs`, `browser-matrix.json`, `layout-probe.mjs`, `tablet-probe.json` |
| Environment limits and Android (not run, §12) | `environment-limits.txt`, `android-chrome.mjs` |

**Automated suites added to the repository:**
- `tests/visual/`: visual regression;
- `tests/browser/`: Back, aborted calls and focus order, 60 browser tests;
- `tests/Feature/Experience/Ux18PerformanceTest.php`;
- `tests/Feature/Platform/VisualClockGuardTest.php`.

**Disposable databases:**
- `hcm_ux18_s100_showcase`, `hcm_ux18_s1k_showcase`, `hcm_ux18_s10k_showcase` and `hcm_ux_visual_showcase` were dropped after validation; `build-scale.sh` and `tests/visual/prepare.sh` recreate them.
- `hcm_ux_showcase` (the 8090 demo) is kept.

## 20. Remaining issues

| # | Item | Severity | Belongs to |
|---|---|---|---|
| R1 | **Apple Safari (iOS, iPadOS, macOS) not tested**: no Apple environment here | P2: a validation gap, not a known defect | Before sign-off: run `tests/visual`, `tests/browser` and the a11y subset in Safari on a Mac or iPhone |
| R2 | **Android Chrome not tested**: the emulator exhausts this workstation's memory (§12) | P2: a validation gap | Before sign-off: `android-chrome.mjs` against a physical device |
| R3 | No human screen-reader pass (VoiceOver, TalkBack, NVDA) | P2 | Before go-live |
| R4 | Workforce pulse and the executive Home are the slowest executive surfaces at 10k (≈0.9–1.2 s server) | P3 | Production hardening: precomputed daily workforce snapshots |
| R5 | Filament-owned leftovers: empty action-column table header and the wizard step's `role="group"` (best practice), one 12 px "—" link on the phone audit list, the employee register's table queries | P3 | UX.19 (module grids) |
| R6 | Visual baselines are tied to this workstation's font rendering | P3 | When CI is set up: generate CI baselines once, review, commit |
| R7 | Livewire rejects aborted calls with a plain object; any future uncaught `$wire` call can reintroduce the Firefox symptom | P3 (guarded by a test and a convention) | Upstream report to Livewire/Filament; keep catching in PeopleOS |
| R8 | The "View as" chips are a `tablist` whose tabs switch the page rather than a panel | P3 (observation) | UX.19 (a toggle-button group may describe them better) |

No blocker, P0 or P1 remains open.

## 21. UX.19 handoff

**A. Technical issues resolved in UX.18**
- Performance:
  - HR Home at scale (−57 % at 10k);
  - the scoped People directory (−65 %);
  - My Work (−8 % to −38 % by role);
  - the phone bar (manager 10.8 → 3.2 ms);
  - per-request repetition of "who am I", feature flags and command center suggestions;
  - the workforce headcount trend.
- Accessibility:
  - 14 WCAG failures from 3 causes;
  - the focus-order regression from UX.17;
  - target sizes;
  - list, dialog and landmark semantics;
  - the sheet loading state;
  - device wording on the 404 page.
- The Firefox `[object Object]` error, reproduced and fixed (57/96 → 0/96).
- Android and browser Back closes the topmost overlay first.
- The palette closes on Escape wherever focus is.
- MySQL suite reproducibility (no duplicate factory e-mails).
- Automated visual regression with 120 reviewed baselines.

**B. Technical issues remaining for later production hardening**
- Precomputed workforce analytics for the executive surfaces at large scale (R4).
- Performance runs above 10,585 employees and through a production web server (PHP-FPM, OPcache, a separate database host), with real network latency.
- Visual baselines for CI machines (R6).
- An upstream report on Livewire's aborted-call rejection (R7).

**C. UX and product decisions for UX.19** (none was decided in UX.18)
- **G12:** whether employees may open their own Employee 360.
- **G13:** permission checks for reminder links.
- Whether payroll gets its own experience.
- Final acceptance criteria per role (the UX.17 phone zero-training tasks, the UX.18 Back tests and the visual baselines are candidate evidence).
- Whether HR Admin and System Admin remain one administration view.
- Whether the mobile Employee 360 header should be redesigned further.
- The UX.15 module-grid debt: module grids are still standard Filament components, which also carries R5.
- Whether the "View as" chips should become a toggle-button group (R8).

**D. To wait until staging**
- Apple Safari (R1) and Android Chrome (R2) on real devices.
- A human screen-reader pass (R3).
- Network-latency and production-server performance.
- Load testing with concurrent users.

## 22. Final verdict

Against the completion gate (brief §37):

- **Measured and fixed.** Performance was measured at three scales, and every UX.17 handoff was investigated and improved. No surface is slower at any scale.
- **Accessibility.** The full audit, keyboard review and touch-target audit are done. 0 WCAG violations remain on PeopleOS-controlled surfaces.
- **Android Back** is fixed and tested in three engines.
- **Firefox.** The `[object Object]` error was reproduced, explained and fixed.
- **Engines and tablets.** Chromium, Firefox, the WebKit engine and tablets are validated.
- **Visual regression** is automated, deterministic and documented: 120 baselines, light and dark, phone, tablet and desktop, six roles.
- **Security.** The security and multi-role regression set passes 257/257. No boundary was weakened.
- **Tests and builds.** Full suite 0 failed; MySQL 60/60; Pint, PHP syntax, Blade compilation and the asset build pass.
- **Reports and evidence** are committed. The tree is clean. Nothing was pushed, merged or deployed.

None of the blockers listed in the brief (§38) applies. Two items are documented as environment limitations with evidence (`environment-limits.txt`), as the brief allows:
- **Apple Safari:** there is no Apple environment.
- **Android Chrome:** attempted; the emulator exhausted the workstation's memory twice (kernel OOM records), and no physical device is available.

They are validation gaps for staging (R1, R2), not known defects, and are not claimed as passes.

**UX.18 — COMPLETE**
