# UX.15 Closure Report

**Date:** 4 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** closing UX.15 (Experience Elevation) by resolving its five P1 issues. This is not UX.16.

**Verdict: UX.15 — COMPLETE.** Four P1 items are resolved. P1-03 (Safari) is closed under the completion rule for an external environment constraint (C.22): Apple Safari cannot run in this Linux environment, so the WebKit engine was validated instead and **Safari itself is not claimed as tested**. Accessibility, responsive, dark-mode, security and full-suite checks pass on the final code. Nothing is pushed, merged or deployed, and UX.16 is not started.

Related documents:
- [PeopleOS-UX-15-Experience-Elevation-Report.md](PeopleOS-UX-15-Experience-Elevation-Report.md), updated with the closure;
- [UX-15-Module-Migration-Plan.md](UX-15-Module-Migration-Plan.md), the inventory and strategy written before implementation;
- [UX-15-Research-and-Audit.md](UX-15-Research-and-Audit.md).

Evidence is in `docs/ux/ux15/closure/`.

---

## 1. Baseline

| | |
|---|---|
| Starting commit | `fcd9932` "docs: add UX15 experience report" (UX.15 reported NOT COMPLETE) |
| Branch | `feature/oct_1_phase_1`, 86 commits ahead of origin, never pushed |
| Repository status at start | Clean working tree |
| Tests at start | 1,030 tests: 970 passed, 60 skipped (MySQL-only), 0 failed |
| Open P1 items | P1-01 module pages, P1-02 approval performance, P1-03 Safari, P1-04 Employee 360, P1-05 demo scope |

## 2. Starting and final commit

| | |
|---|---|
| Starting commit | `fcd9932` |
| Final commit | The commit that adds this report ("docs: close UX15 experience elevation"); code complete at `00c025a`, evidence at `425c34e` |
| Commits created | 13 |
| Files changed | 516: 267 resource page classes; 200 evidence files (screenshots, measurements and scripts); and 49 others (the experience layer, the authorisation pass, the 360 views, 4 new test files and one extended, 3 seeders, theme, scripts, and 4 documents) |

| Commit | Phase | Change |
|---|---|---|
| `60cde4a` | C.1–C.2 | Module inventory and migration plan |
| `2ae626d` | C.3–C.4 | Experience primitives: base pages, context read model, review, central defaults |
| `d44bf51` | C.5–C.8 | All resource pages on the base pages; families migrated |
| `4f5d9de` | P1-02 | Bounded authorisation pass; equivalence tests |
| `e19b84c` | P1-04 | Employee 360 progressive disclosure |
| `3379c17` | P1-05 | Demo organisation scopes |
| `c6aa53a` | C.5 | Earlier custom pages: KPI tiles to figure strips |
| `3ce58fc` | C.16 | Module context stays cheap at 10k |
| `777da9f` | C.13 | WCAG AA for the Filament chrome in the layer |
| `7c99c32` | C.13 | One skip link; concise review announcement |
| `00c025a` | C.5–C.8 | Record pages: decisive actions and "More", section-nav tabs, panel labels, pinned row actions |
| `425c34e` | C.12–C.17 | Final validation evidence |
| (this report) | C.23 | Documentation |

## 3. P1 closure matrix

**P1-01 Module pages**

| | |
|---|---|
| Status | Resolved, within the stated architecture (Filament stays the engine for tables, fields and actions) |
| Evidence | Every one of the 267 resource page classes extends a PeopleOS base page (architecture test). Every resource list renders with the PeopleOS context and without the "› List" breadcrumb (test, more than 130 lists). Before/after captures for each family are in `closure/before` and `closure/after`. The browser matrix covers the module families in three engines and four viewports |
| Pages migrated | 147 list and manage pages, 48 create, 48 edit and 28 view pages through the base pages. Modal create, edit and view on the 59 manage pages, and the 117 relation-manager tables, through central defaults. 6 earlier custom pages moved from KPI tiles (37) to figure strips. Every date and time picker gets the accessible trigger |
| Reusable components | `PeopleListRecords` / `PeopleManageRecords` (context sentence, narrowing-only status lens, Approval Center hand-off, honest empty states, bounded page sizes, sentence-case titles); `PeopleCreateRecord` / `PeopleEditRecord` / `PeopleViewRecord` (record breadcrumbs, state line, named actions); `ModuleContext` (counts from each page's own table query); `posFormReview` (Context → Change → Review → Confirm, also inside drawers); `PeopleOsUi` (person chips with peek, side drawers, accessible pickers); `ArrangesRecordActions` (up to three decisive actions, the rest and destructive ones in "More"); theme part D (section-nav tabs, panel labels, pinned row actions) |
| Remaining | Data tables and form fields are still Filament's components, styled and wrapped by PeopleOS (the architecture keeps Filament for forms, tables and actions). Deferred (P2): a configuration workspace with an impact pane and in-page history; a drawer step for the 28 resources whose rows open a full record page |
| Verdict | **Resolved.** No module page is untouched standard Filament any more. Each one composes the PeopleOS layer: context first, a narrowing lens, the decision hand-off, person chips, pinned row actions, drawers, Review before Confirm, and record pages led by their decisive actions with section-nav tabs. Module pages remain less distinctive than the signature workspaces, because their grids and fields are Filament components; §18 scores this honestly |

**P1-02 Approval performance**

| | |
|---|---|
| Status | Resolved |
| Before | 10,785 employees, manager with 202+ pending decisions: Approval Center 594 queries / **1.80 s**, of which 419 were authorisation lookups. Home 2.15 s. My work 1.70 s |
| After | 193 queries / **0.80 s** (15 authorisation lookups). Home 1.09 s; My work 0.72 s; HR Approvals 0.77 → 0.58 s. Pre-change code run from a worktree against the same database, median of 3 |
| Authorization equivalence | Proven. An oracle re-implements the pre-change checks literally and is compared with each policy decision outside a pass and inside a primed pass. Coverage: leave and attendance; tenant-wide, department, relationship-only and other-organisation scopes; direct, indirect, dotted, unrelated and cross-organisation employees; own requests; an unauthorised manager; an HR approver without an employee record; a suspended approver; a revoked permission; another tenant's request. The Approval Center queue equals the oracle's for three scope shapes. A mutant that primes every employee as reachable makes the test fail |
| Security impact | None weakened. Policies still decide every item, and decisions are re-checked by the owning service outside the pass. The pass is request-scoped and forgets every fact when it ends; the long-lived `AccessScopes` singleton holds no new state |
| Verdict | **Resolved** |

**P1-03 Safari**

| | |
|---|---|
| Status | Closed as an external environment constraint, formally documented. **Apple Safari was not tested.** The WebKit engine was tested instead |
| Environment | Ubuntu 26.04 workstation. `sudo` needs an interactive password, so no system packages were installed. Playwright's WebKit (WPE MiniBrowser, WebKit 26.5, Playwright build 2336) runs once its missing libraries are unpacked into the scratchpad without root (`apt-get download` + `dpkg -x`: libflite1, libavif16, libmanette-0.2-0, gstreamer1.0-libav, libyuv0, libdav1d7, libgav1-2, libhidapi-hidraw0, libbacktrace0). It is launched from a clean environment, because the VS Code snap leaked its GIO paths into WebKit's network process |
| Tests | 21 WebKit journeys on the final code, 21 passed (`closure/webkit/webkit-journeys.json`). They cover: login; Home; My work; the Approval Center with J/K; People search, peek and drawer; the Employee 360 views; the command palette; a module list and its lens; keyboard focus; a form review; a drawer form; dark mode; and an iPhone 13 device profile (Home with the bottom bar, Approvals, a stacked module list, the 360). Also: the WebKit rows of the three-engine browser matrix (§12), and pointer and keyboard parity for the date picker |
| Evidence | `closure/webkit/` (journey results, screenshots, the journey script and the launcher); `closure/browser/browser-matrix.json` (WebKit rows) |
| Limitations | This is the **WebKit engine, not Apple Safari**. Apple Safari runs only on macOS and iOS and cannot run in this Linux environment. Untested: Safari-specific behaviour on macOS/iOS (iOS viewport and keyboard handling, macOS font rendering, Safari extensions and privacy features) |
| Verdict | **Closed under the brief's external-constraint rule, with no Safari claim.** Apple Safari cannot run on Linux, and no macOS or iOS device or approved cloud device service is available here. Safari is therefore not validated. Required to close it fully: Safari on macOS (or an iPhone, or the iOS Simulator in Xcode), running the same journeys (`closure/webkit/webkit-journeys.mjs` lists them) |

**P1-04 Employee 360**

| | |
|---|---|
| Status | Resolved |
| Before | 18 panels, 17 headings, 3 panels above the fold; 4.1 screens on desktop, 8 on a phone |
| After | 3 panels (all above the fold), 4 headings; 1.1 screens on desktop, 3 on a phone |
| Panels removed/collapsed | The Now panel and "What's next" are merged. Journey, What changed (beyond the latest three), Position, Relationships, Growth, Rewards and Documents moved into views one step away. All details, Additional information, Applicable policies and the record tabs moved into the Records view |
| Information preserved | Nothing deleted. Every view is still rendered by the same gates. Hash links (`#journey`, `#records`) still work. A test proves a hidden view carries no restricted entry |
| Mobile result | 8 → 3 screens; the views work at phone width with no horizontal overflow (Chromium, Firefox and the WebKit iPhone viewport) |
| Verdict | **Resolved** |

**P1-05 Demo organisation scope**

| | |
|---|---|
| Status | Resolved |
| Manager scope | Team Platform: they reach their Platform team and reporting line (any reporting type), and nobody in Mobile, Design, Finance or Demo Services |
| HR scope | The HR business partner is scoped to company Demo Technologies and cannot see Demo Services. The HR admin is tenant-wide by design (no rows) |
| Executive scope | Tenant-wide by design (no rows) |
| Administrator scope | Tenant-wide (no rows). The payroll lead is scoped to Demo Technologies. Employees are self-service by permission |
| Authorization test | `Ux15DemoScopeTest` runs the real seeders and checks stored scope rows, visibility per persona, approvals within scope (policy and Approval Center) and employee self-service |
| Verdict | **Resolved** |

## 4. Module migration strategy

The full strategy is in [UX-15-Module-Migration-Plan.md](UX-15-Module-Migration-Plan.md). In short, pages become PeopleOS by **composition**:
- base page classes;
- one context read model;
- central component defaults;
- one client-side review component.

There was no page-by-page redesign. Security rules for the layer:
1. counts come from each page's own table query;
2. the lens only narrows;
3. person chips peek through `PersonPeek`;
4. the review reads only the form;
5. sensitive lists show counts, never amounts;
6. no base page changes authorisation.

## 5. Module families migrated

| Family | Resources | What changed |
|---|---|---|
| A Simple list | 9 | Context sentence, empty states, search first, drawers on manage pages |
| B Search and filter list | 16 | The same, plus status lens and person chips |
| C Detail workspace | 6 | Record-name title, state line and breadcrumbs; up to three decisive actions with the rest in "More"; section-nav tabs and panel labels. The Employee 360 is simplified separately (P1-04) |
| D Create and edit forms | 48 + 48 pages, 59 manage-page drawers | Context line, "New …" / "Create …", Review before Confirm (create forms: once the person starts), drawers for modal forms, Delete behind "More", accessible date pickers |
| E Request and approval | 11 | Lens defaults to what is waiting; hand-off to the Approval Center with the viewer's decision count |
| F Configuration | 62 | Effective-dated split (in effect / starting later / ended), changed this week, edits in drawers with review |
| G Sensitive HR and pay | 27 | The same patterns with counts only |
| H Analytics | 2 + custom pages | Specialised layouts kept; KPI tiles replaced by figure strips on six custom pages |
| I Timeline and history | 8 | Context sentence (what changed this week), search first |
| J Relationship management | 6 | Person chips, lens |

## 6. Approval performance before and after

See P1-02. At 10,785 employees, server-side, median of 3:

| Screen (viewer) | Before (`60cde4a` code) | After P1-02 | Final code, run 1 | Final code, run 2 |
|---|---|---|---|---|
| Approval Center (manager, 202+ pending) | 594 queries · 419 authorisation lookups · **1.80 s** | 193 · 15 · **0.80 s** | 193 · 15 · 1.00 s | 193 · 15 · **0.73 s** |
| Home (manager) | 2.15 s | 1.09 s | 1.16 s | 1.07 s |
| My work (manager) | 1.70 s | 0.72 s | 0.76 s | 0.74 s |
| Approval Center (HR business partner) | 0.77 s | 0.58 s | 0.70 s | 0.58 s |

Final run 1 coincided with a load average of 2.7 on the workstation; query counts are identical in every after run. In the browser, the 202+ queue answers in about 0.7 s (median time to first byte) and is loaded in about 1.0 s (`after/c41-scale-approvals-browser-timing.json`).

Raw results: `closure/evidence/approval-10785-before.json`, `approval-10785-after.json`, `approval-final.json`, `approval-final-rerun.json`; script `approval-measure.php`.

Module lists at 10,785 employees (`module-lists-10785-*.json`): the layer costs 2–6 queries per list with wall time within noise. One case was fixed during validation: request lists rebuilt the approval queue to count one type, and now use the cached decision count.

## 7. Authorization-equivalence proof

`tests/Feature/Experience/Ux15ApprovalAuthorizationTest.php`:

| Test | What it proves |
|---|---|
| Every viewer × every request, oracle vs policy outside a pass vs policy inside a primed pass | Identical decisions across the cases in §3 P1-02 |
| The Approval Center queue vs the oracle | Identical for tenant-wide, relationship-only and department scopes |
| No authorisation query per additional item | At most 0.1 queries per item between 10 and 40 pending; no fact survives the pass |
| Mutant check | Priming every employee as reachable makes the first test fail |

## 8. Employee 360 before and after

See P1-04. Screenshots: `closure/before/c20-employee-360-hr-{desktop,mobile}-full.png` and `closure/after/c20-employee-360-hr-{desktop,mobile}-full.png`, plus `c20-employee-360-hr-records-view.png`. Measurements: `closure/employee-360-{before,after}.json`.

## 9. Demo scope before and after

| Persona | Before | After |
|---|---|---|
| Manager | No rows, so tenant-wide; saw and could approve everyone | Team Platform plus reporting line |
| HR business partner | Tenant-wide | Company Demo Technologies |
| Payroll lead | Tenant-wide | Company Demo Technologies |
| HR admin, executive | Tenant-wide | Tenant-wide (by design) |
| Employees | Self-service | Self-service |
| Consultants (Consulting department) | Recorded under Demo Technologies though Consulting belongs to Demo Services | Demo Services |

## 10. Safari status

See P1-03.

Summary of what was attempted, what ran and what remains:

| | |
|---|---|
| Exact limitation | Apple Safari exists only for macOS and iOS. This workstation runs Ubuntu 26.04, and `sudo` needs an interactive password |
| Attempted approach | Playwright's WebKit build was installed for the user only. Its missing system libraries were unpacked without root (`apt-get download` + `dpkg -x`), and the browser was launched from a clean environment through a wrapper (`run-webkit.sh`) |
| Required environment | Safari on macOS 15 or later, or an iPhone/iPad, or the iOS Simulator in Xcode. An external device service (BrowserStack, Sauce Labs) would also work but needs an account and a tunnel to this private build, which was not authorised |
| Validated instead | The WebKit engine (WPE MiniBrowser 26.5): 21 of 21 journeys passed on the final code. In the browser matrix, WebKit joins Chromium and Firefox at four viewports, including a 390 × 844 touch phone viewport; the journeys also use the iPhone 13 device profile |
| Remaining risk | Safari-specific behaviour: iOS viewport, keyboard and safe-area handling on a real device; macOS font rendering; Safari's privacy features, such as Intelligent Tracking Prevention for the session cookie. The WebKit engine shares Safari's layout and JavaScript cores, so the remaining risk is mostly in platform integration rather than in rendering |

This is labelled throughout as **WebKit engine validation, not Safari validation**.

## 11. Accessibility status

axe-core, WCAG 2.0/2.1/2.2 A and AA, Chromium 1440 × 1000 with reduced motion, light and dark. The set is the UX.15 workspaces plus the closure's module families, the Employee 360 views, review states and drawer forms (`closure/accessibility/ux15-axe-closure.mjs`).

| Run | Checks | Checks with violations |
|---|---|---|
| First closure run (`axe-closure-first-run.json`) | 78 | 25 |
| Final code (`axe-closure-final.json`) | 78 | **0** |

The first run found seven problems in the Filament chrome that the layer now presents, plus one regression of the closure's own:
- **Regression:** a "quiet" opacity on row actions dropped link labels to 2.6–4.1:1. It was removed.
- **Placeholders:** empty-value placeholders were at 2.5:1 (light) and 3.2:1 (dark). They now use the muted text token.
- **Dark filled buttons:** white text on success, warning and danger buttons was at 3.5–4.3:1. They now sit one shade deeper.
- **Earlier custom pages:** light-only grey utilities were unreadable in dark (3.2:1). They now follow the PeopleOS text tokens; an explicit `dark:` class still wins.
- **Select clear control:** a 16 px target became 24 px, with the glyph kept at 16 px.
- **Livewire's progress bar:** `role="bar"` is not an ARIA role. It is now a named `progressbar`.
- **Date and time pickers:** Filament nests the labelled input inside a `<button tabindex="-1">`. The wrapper is now a plain element; behaviour is identical to the original markup in Chromium and WebKit (`datepicker-parity.json`).

The UX.15 result (0 violations) is not regressed.

Manual review (`closure/accessibility/manual-review.md`):
- **Keyboard:** one skip link (Filament 5.8's duplicate is hidden); every stop shows a focus ring.
- **Drawers:** focus moves in on open; Escape closes; focus returns to the trigger.
- **Forms:** the review announces a short status rather than the whole list.
- **Tables:** lens chips are toggle buttons; row actions are full contrast and pinned.
- **Command palette:** focus is in the combobox.
- **Reduced motion:** 0 running animations.

## 12. Responsive status

Browser matrix on the final code (`closure/browser/browser-matrix.json`). The 21 screens are 10 signature workspaces, 10 module-family pages and one custom page. Each is checked for HTTP status, horizontal overflow, script errors and navigation shape, plus interactions (command palette, person drawer, peek, phone bottom bar, dark scheme). They run in Chromium, Firefox and the WebKit engine at desktop 1440, laptop 1280, tablet 820 (touch) and phone 390 (touch).

| Engine | First pass |
|---|---|
| Chromium | 97 / 99 |
| Firefox | 94 / 99 |
| WebKit | 97 / 99 |
| **Total** | **288 / 297** |

All 9 first-pass misses pass on a recheck that loads each screen on its own (`closure/browser/browser-recheck.txt`):
- **Peek on hover, 6 misses (all engines, 1440 and 1280).** The matrix step switches to list view through a loose button-name match after closing the drawer, and stays on cards. Loaded directly in list view, peek opens in every engine.
- **Firefox script errors, 3 misses (Approvals at 1440 and 1280, Workforce pulse at 1280).** Filament's lazy-loaded database notifications abort when the matrix navigates away immediately. Settled, there are 0 errors.

No screen overflows horizontally at any viewport. Phones get stacked table rows, the bottom bar and the review as a bottom sheet. Tablets keep the table with row actions pinned. The Employee 360 is 3 screens on a phone (from 8).

## 13. Dark mode status

Every capture set has light and dark variants (signature 18 × 2, module families 18 × 2, closure states 12 × 2). axe is clean in dark (§11). Dark-specific fixes at closure:
- filled success/warning/danger buttons sit one shade deeper for white text;
- grey utilities on earlier custom pages follow the PeopleOS text tokens;
- placeholders use the muted token.

The WebKit journeys include a dark-mode check (graphite surfaces, `rgb(14, 15, 19)`).

## 14. Scale validation

| Check | Result |
|---|---|
| Data | 10,785 synthetic employees (`hcm_ux_scale_showcase`, guarded seeder; dropped after the evidence was saved, §17), large approval queue (202+), large teams, long names |
| Approvals, Home, My work | §6 |
| Module lists | 2–6 extra queries, wall time within noise |
| 10 / 100 / 1,000 levels | Pest guards keep query counts flat: `Ux15ScaleTest`, the scale tests, and the new tests above |

Cleanup is in §17.

## 15. Test results

| Run | Result |
|---|---|
| Baseline (`fcd9932`) | 1,030 tests · 970 passed · 60 skipped (MySQL-only) · 0 failed |
| **Final code** (`00c025a`, `php artisan test --parallel`) | **1,046 tests · 986 passed · 60 skipped (MySQL-only, as at baseline) · 0 failed** · 13,075 assertions · 570 s |
| MySQL concurrency and scale suites (opt-in, on MySQL) | 60 tests · 60 passed · 244 assertions |
| Security, tenancy, identity, audit and architecture groups (final code) | 195 tests · 195 passed · 3,864 assertions |
| Closure tests | 16 new tests: `Ux15ModuleExperienceTest` (8), `Ux15ApprovalAuthorizationTest` (3), `Ux15Employee360ClosureTest` (3), `Ux15DemoScopeTest` (1), and one architecture rule ("every resource page on a PeopleOS base page"). All pass. No existing test was edited to pass, weakened or disabled |
| Authorisation equivalence | `Ux15ApprovalAuthorizationTest`: oracle = policy outside a pass = policy inside a primed pass for every case; the queue equals the oracle's; a mutant fails the test (§7) |
| Pint | Passed |
| Static checks | `php -l` clean on all 312 changed PHP files; `php artisan view:cache` compiles every template; `npm run build` succeeds |
| Accessibility | axe: 78 checks, 0 violations (§11) |

## 16. Screenshot inventory

**172 screenshots** in `docs/ux/ux15/closure/`, all fictional showcase data:

| Set | Count | Contents |
|---|---|---|
| `before/` | 52 | 18 module-family screens × light/dark (c01–c18), 8 earlier custom pages (c21–c28), the Employee 360 desktop and phone (c20), and 6 approvals captures from the UX.15 end state (d06, m04, the 202+ queue on desktop and phone) |
| `after/` | 74 | The same 36 module-family captures and 8 custom pages. The Employee 360 (3, including the Records view). 24 closure states (c29–c40): drawer form, edit and create review, lens, person peek, demo scope (manager and HR business partner), tablet and phone module pages, the 360 on a tablet. The 202+ approval queue at 10,785 employees (desktop light and dark, phone) |
| `after/signature/` | 36 | The 18 signature workspaces × light/dark, desktop and phone |
| `webkit/` | 10 | WebKit engine journeys, including the iPhone 13 profile |

Before/after pairs share file names. Approvals before/after: `before/d06-approvals-manager-*` with `after/signature/d06-approvals-manager-*`, and `before/c41-…` with `after/c41-…` for the large queue (timing in `after/c41-scale-approvals-browser-timing.json`).

## 17. Cleanup

| Item | Action |
|---|---|
| `hcm_ux_scale_showcase` (10,785 synthetic employees, 59 MB) | **Dropped** after confirming it is a disposable, single-tenant (`demo`) showcase database that is not in `.env` and not used by any process. Its evidence is preserved in `closure/evidence/`, `closure/after/c41-*` and the UX.15 `scale/` set, and the guarded seeder rebuilds it |
| `hcm_ux_ladder_showcase` (1,000 synthetic employees, 28 MB) | **Dropped** on the same checks |
| Temporary 8091 server (scale database, for the large-queue capture) | Started for the capture, then stopped |
| `scratchpad/wt-before` git worktree (the "before" code for measurements) | Removed with `git worktree remove` |
| `hcm_ux_showcase` and the 8090 server | **Kept**, untouched. The showcase was reseeded once during the closure to load the demo scopes (P1-05) |
| Other databases (main, phase 12–14 replay and concurrency) | Untouched |

## 18. Visual scores

Scored after the closure, against the UX.15 scores. For traditional-HRMS similarity, lower is better.

| Measure | Signature workspaces: UX.15 → closure | Module pages: UX.15 → closure | Overall: UX.15 → closure | Why it moved, or did not |
|---|---|---|---|---|
| Traditional HRMS similarity (lower is better) | 3 → 3 | 7 → 5 | 5 → **4** | Module pages lead with context, chips, drawers, review and decisive actions, but their grids and fields are still recognisably Filament components |
| PeopleOS differentiation | 8 → 8 | 4 → 6 | 7 → **7.5** | One interaction language now reaches every page. The signature workspaces did not change |
| Premium quality | 8 → 8.5 | 6 → 7 | 7 → **7.5** | The 360 is one calm screen. Record pages lost the coloured button wall. Phone rows are still dense |
| Usability | 8.5 → 9 | 7 → 8 | 8 → **8.5** | Approvals at 202+ items take 0.7–0.8 s instead of 1.8 s. Lists say what matters and hand decisions to the Approval Center. Row actions stay in reach. Forms end in a review |
| Consistency | 8 → 8.5 | 5 → 7.5 | 6.5 → **7.5** | The same chips, peek, drawers, tabs, labels and action model run from Home to any record. The configuration workspace and record drawers are still to come (P2) |

UX.15 gave no separate module-page column. Those "UX.15" figures are estimated at closure from the `before/` captures, and only the closure deltas are claimed. No score reaches the signature level for module pages, because the remaining P2 items in §19 are real.

## 19. Remaining P2 and deferred items

**P1:** none open. P1-03 is closed under the external-constraint rule; Apple Safari itself remains untested (§10).

**P2 (new or refined at closure):**
- **Configuration workspace.** The configuration family has an effective-dated lens and "changed this week", and edits through drawers with review. A dedicated workspace (current state → impact → history in one view) is not built. The history stays in each record's History tab.
- **Peek → drawer for full record pages.** 28 resources open a full record page from the row; an intermediate drawer is not provided. Person chips peek everywhere.
- **Data grids and fields are Filament components.** They are styled, contextualised and wrapped, but not replaced (by architecture). This is why module pages score below the signature workspaces in §18.
- **Approval Center: other request types.** The bounded authorisation pass primes leave and attendance, the measured bottleneck. Compensation changes and letters still use one policy lookup each; they are few at the tested scale.
- **Command palette input** shows the caret inside the modal palette rather than a focus outline (unchanged from UX.15).
- **Pinned row actions** show a soft edge even when a table fits.

**P2 carried from UX.15, still open:**
- a constant per-request shell cost (viewer-employee lookups, settings reads);
- bounded ChangeFeed relationship checks;
- People filter option queries;
- "Show more" on Approvals rebuilds the queue;
- no org-map minimap or virtualisation;
- My work rows repeat domain words;
- dense "Need attention" rows on phones;
- negative synthetic leave balances in demos.

**Deferred:** batch policy evaluation beyond the bounded pass; list virtualisation; UX instrumentation counters; Redis and target-infrastructure verification (never claimed).

**Known limitations:** synthetic, fictional data on disposable `*_showcase` databases; timings from a developer workstation (`php artisan serve`); no production or staging readiness claimed.

## 20. Final verdict

**UX.15 — COMPLETE.**

| C.22 criterion | Result |
|---|---|
| All five P1 issues resolved, or proven impossible because of an external constraint, with no misleading claim | P1-01, P1-02, P1-04 and P1-05 resolved. P1-03 closed as an external constraint: the WebKit engine was validated, and **Apple Safari was not tested** |
| Module pages no longer broadly look like untouched standard Filament | Yes. All 267 resource page classes compose the PeopleOS layer (§3, §5) |
| A reusable PeopleOS migration layer exists | Yes (§4; `app/Filament/Support/Pages`, `PeopleOsUi`, `ModuleContext`, `posFormReview`, theme part D) |
| Representative families migrated and validated | Yes. All ten families; matrix, axe and captures |
| Approval authorisation equivalent | Yes, proven by test with a mutant check (§7) |
| Approval performance materially improved | 1.80 s → 0.73–0.80 s; 594 → 193 queries |
| Employee 360 hierarchy improved | 18 → 3 panels; 4.1 → 1.1 screens |
| Demo personas realistically scoped | Yes, through audited scope rows (§9) |
| Safari genuinely tested, or the limitation formally documented | Documented (§10) |
| Accessibility clean | axe 0 / 78 |
| Responsive validation passes | 297 / 297 after recheck; first pass 288 / 297 (§12) |
| Dark mode passes | Yes (§13) |
| Full suite has zero failures; no tests disabled | 1,046 tests, 0 failed; none disabled |
| No security control weakened | None. Policies still decide every record; tenancy, scope, audit and workflow are untouched; 195 security-group tests pass |
| Realistic data validation | 10,785 employees and a 202+ queue |
| Visual evidence | 172 screenshots (§16) |
| Documentation updated | This report, the Elevation report, the Research-and-Audit matrix and the migration plan |
| Committed; not pushed, merged or deployed | Yes |

What COMPLETE does not mean:
- Apple Safari on macOS or iOS is untested (§10).
- Module grids and fields are still Filament components.
- The P2 items in §19 remain.
- Nothing here claims production or staging readiness.

UX.16 has not been started.
