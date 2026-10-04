# UX.16 Role Experience Refinement Report

**Date:** 4 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** UX.16, role-based experience refinement. Not UX.17, UX.18 or UX.19.

**Verdict: UX.16 — COMPLETE.** Each of the five roles now answers its own question on landing. One design system, one security model and one people model remain.

**Experience:**
- The administrator lands on governance, no longer on the executive view.
- The executive lands on the workforce story.
- HR's operations are in the first screen.
- Managers see their team's exceptions.
- Employees see dates about their own record.
- My Work, navigation, command and search, the Employee 360, AI context and notifications follow the same role signals.

**Checks:**
- Authorisation is unchanged, apart from one pre-existing tenant-boundary leak found and fixed first (a global failed-jobs count).
- Tests: 0 failed.
- Accessibility: axe 0.
- Layout: no page overflows at desktop or phone width.
- Performance: two regressions were found during validation and fixed.

**Not claimed:** UX.17–UX.19 work, Apple Safari, or production readiness. Nothing is pushed, merged or deployed.

Companion documents:
- [UX-16-Role-Experience-Audit.md](UX-16-Role-Experience-Audit.md): the audit, findings G1–G14 and the role matrix, written before any code;
- evidence in `docs/ux/ux16/` (before/after screenshots, zero-training runs, measurements and scripts).

---

## 1. Baseline

| | |
|---|---|
| Branch / HEAD at start | `feature/oct_1_phase_1` at `b43efa4` (UX.15 closure complete), clean working tree, 99 commits ahead of origin, never pushed |
| Tests at start | 1,046 tests · 986 passed · 60 skipped (MySQL-only) · 0 failed (re-run before UX.16) |
| How roles worked | Roles are permission bundles. `RoleLens` derived seven lenses from permissions and relationships, but they changed only Home, the idle palette order and the shell assistant's default. Navigation, My Work, typed search, notifications, the Assistant page and the 360 were the same for every role. Administrators opened in the executive view |
| Personas (showcase, fictional) | Priya (employee), Amit (employee + manager), Neha (employee + HR manager, scoped to one company), Meera (employee + executive), Kavya (employee + tenant HR admin: the administrator), Arjun (employee + payroll admin). Every persona is multi-role |

## 2. Starting commit

`b43efa4` "docs: close UX15 experience elevation (UX.15 closure C.23)".

## 3. Final commit

The commit that adds this report ("docs: complete UX16 role refinement report"); code complete at `f560029`. 15 commits in UX.16.

| Commit | Change |
|---|---|
| `93e928d` | ux: audit role experiences (UX.16.1-16.3) |
| `45720fb` | fix: keep platform-wide figures off tenant administrators' Home (UX.16 G11) |
| `d7ed825` | ux: compose Home per role experience (UX.16.5-16.9, 16.20) |
| `d902940` | ux: lead My Work with each role's own work (UX.16.12) |
| `7b88a2c` | ux: order navigation by role experience (UX.16.10) |
| `5019c9e` | ux: make command and search role-aware (UX.16.11, 16.18) |
| `79409ff` | ux: emphasise the Employee 360 by viewer relationship (UX.16.14) |
| `210bdc8` | ux: make AI context role-aware within the existing boundaries (UX.16.16) |
| `92c5ef4` | ux: order notifications by role relevance (UX.16.17) |
| `a4b5413` | ux: open the People directory the way each role works (UX.16.13) |
| `0efb926` | chore: give the showcase realistic role-signal data (UX.16.24) |
| `62a7b31` | test: add role experience and role journey coverage (UX.16.22, 16.32) |
| `65f854d` | perf: keep role signals cheap at enterprise volume (UX.16.27) |
| `f560029` | perf: trim the HR and executive leads in My Work (UX.16.27) |
| (this report) | docs: complete UX16 role refinement report |

## 4. Role experience matrix

The full matrix (primary goal; top 5 tasks, information needs, actions and alerts; entities; workspace; search, approval, AI and analytics behaviour; navigation depth; default Home and landing context) is §5 of the [audit](UX-16-Role-Experience-Audit.md#5-role-experience-matrix-163). As built:

| | Employee | Manager | HR | Executive | Administrator |
|---|---|---|---|---|---|
| Question answered on landing | What matters to me today | What needs my decision; how my team is | What needs operational attention | What is happening across my workforce | What needs governance or system attention |
| Home leads with | For you (own record), Need attention, Your day, My requests | Decisions, Your team, Your day | People operations | Workforce headline and movement | Governance, At a glance |
| My Work leads with | About you, then next step | Next step, then Your team | People operations | Workforce | Governance |
| Rail after Home | My work, People, Services | My work, People (opens on My team) | My work, People, Organisation, Workflows | Insights, People, Organisation | Admin, Organisation, People, Workflows |
| Search ranks first | Actions and own requests | People (team first) | People | Actions, navigation | Actions, navigation |
| Notifications first | Personal action | Team decisions | Operational exceptions | Material workforce change | System and security |
| AI opens with | "Explain my leave balance" | "Summarise changes in my team" | "What employee lifecycle actions need attention?" | "What changed in my workforce?" | "Which configurations changed recently?" |
| 360 emphasis | Your record (own) | Your team (reports) | People operations | No 360 without `employee.view` | Identity and access |

Payroll is the payroll side of HR operations: its own Home lead (the run and what blocks sign-off), My Work lead, AI question ("Audit the latest payroll") and notification order.

## 5. Employee changes

- **For you** on Home: signals from the person's own record, all real data:
  - "Your leave request needs attention" (a request declined in the last 14 days);
  - "Your probation review is due in N days";
  - "Your Passport expires in N days" (documents expiring within 30 days);
  - "Your payslip for October 2026 is available" (unviewed, last 14 days; payslip policy);
  - "Your manager changed to …" (a new primary line in the last 30 days).
- **My requests** on Home and in My Work, each request with where it stands.
- **About you** leads My Work, after the personal next step.
- **Palette verbs:** "Update bank details" (the governed bank-change service, which requires approval); "download payslip"; manager actions rank below the employee's own.
- **Elsewhere** links to My learning and My career.
- **Unchanged:** the employee cannot open the Employee 360, by permission (see §14 and §26).

## 6. Manager changes

- **Your team** on Home (after decisions):
  - "N decisions need you";
  - "N team members have attendance exceptions" (absent, missed punch or late in 7 days, not regularised);
  - "N probation decisions are due" (configured manager relationship types, within 30 days or overdue);
  - "N performance reviews are waiting for you";
  - "N team members changed role or organisation" (30 days).

  Each links to the place to act.
- **Personal items stay a compact "For you".**
- **People** opens on My team; the directory and search list the team first, marked "Your team".
- **Palette:** "Show my team", "Review probation", "Approve leave".
- **360 of a report:** relationship type, decisions waiting about them, and probation end.

## 7. HR changes

- **People operations first** on Home and in My Work, most severe first, from scoped queries. Items:
  - probation overdue and ending within 14 days;
  - joining in 7 days;
  - onboarding tasks overdue;
  - HR requests past SLA;
  - leaving within 7 days;
  - leave waiting over 3 days.

  New items:
  - documents awaiting verification;
  - employee changes taking effect this week;
  - workflows stopped with an error.
- **The opening sentence** leads with the operational count.
- **The directory** opens on the list view.
- **Palette:** "Create employee", "Transfer employee", "Open service requests".
- **360:** lifecycle, documents to verify, open HR requests, and background-check status (each by permission).
- **Elsewhere:** People and Attendance exceptions.

## 8. Executive changes

- **The workforce story on Home**:
  - the Workforce pulse headline (movement this month against last, as a share of headcount);
  - movement figures against last month;
  - critical positions at risk (with succession permission);
  - headcount figures and a six-month sparkline;
  - workforce decisions (headcount plans in review, with `workforce.approve`);
  - "Explore" links to Workforce pulse.

  "Open Workforce pulse" is the primary action.
- **Personal items** are compact.
- **Insights** comes second in the rail.
- **Palette:** "Show workforce pulse", "Show workforce changes" (for executives without audit access, which opens Workforce pulse).
- **Limits kept:** no individual 360 without `employee.view`; drill-downs stay aggregated with small groups suppressed. No predictions, no risk scores.

## 9. Administrator changes

- **One Administration experience.** The HR admin and system admin lenses are merged, and administrators no longer land in the executive view.
- **Governance on Home**, each item gated by the screen it summarises:
  - configuration changes awaiting approval;
  - people who can sign in without a role;
  - people who approve or edit employees across the whole tenant (no organisation scope);
  - multi-factor sign-in not required;
  - integration messages failed for good;
  - failing webhook endpoints;
  - notifications that failed this week;
  - workflows stopped with an error;
  - background jobs failed (platform administrators only).
- **At a glance:**
  - configuration changes this week;
  - active users;
  - roles;
  - multi-factor sign-in;
  - audited events this week.
- **"Open Admin Centre"** is the primary action, and **Admin** comes second in the rail, with Insights opening on change history.
- **Palette:** "Manage roles and permissions", "Open audit", "Configure workflows", "Security policy", "Integration hub".
- **360:** identity and access (account status, roles, last sign-in, authenticator app, organisation scope), only with user access.
- **Security fix (G11):** the system-admin Home showed the global `failed_jobs` count, which spans tenants, to tenant administrators; it no longer does.

## 10. Navigation changes

`ExperienceNavigation` orders the nine sections by the primary experience (`ORDER`) and adds role landings (`ROLE_LANDING`: the manager's People opens on My team, the administrator's Organisation on the designer and Insights on change history).

Every section the person can open is still present, and each destination still checks its own `canAccess()`. Verified per persona in the browser (§19).

## 11. My Work changes

My Work opens with a role lead block from the same `RoleSignals` as Home, so the two never disagree and are gated the same way:
- About you (employee);
- Your team (manager);
- People operations (HR);
- Payroll (payroll);
- Workforce (executive);
- Governance (administrator).

Operational roles see their lead before the personal "Do this next" and in the opening sentence. "Elsewhere" adds each role's own places. The shared streams are unchanged; this is not a filtered task table.

## 12. Command palette changes

- Result groups are ordered by experience (employees: actions and own requests; managers and HR: people; executives and administrators: navigation). Intelligence answers always come first. Groups are only reordered.
- Among equal matches, the viewer's own role's actions rank first.
- **New verbs**, each gated by the screen it opens:
  - update bank details;
  - review probation;
  - show workforce changes;
  - manage roles and permissions;
  - open audit;
  - configure workflows;
  - security policy;
  - integration hub.
- **Role keywords:**
  - create employee;
  - transfer employee;
  - service requests;
  - show my team;
  - approve leave;
  - download payslip.
- **Fixed:** an administrator typing "permission" got no results.

## 13. Search changes

- People results come back in name order (they were in database order).
- For anyone who manages people, current reports come first and are marked "Your team".
- Both queries start from `PeopleVisibility`, so record visibility is unchanged. An employee without `employee.view` still finds only their circle (tested).

## 14. Employee 360 changes

One workspace, composed by who is looking (`PersonWorkspace::viewer`). The Now view gains one panel:
- **Your record:** your own, with My HR and My career;
- **Your team:** a current report;
- **People operations:** HR;
- **Identity and access:** administrators with user access. The account must belong to the current tenant.

Anyone else gets no panel, and every fact has its own permission. There is no second 360 implementation.

**Not changed:** employees without `employee.view` still cannot open their own 360. Allowing it would broaden authorisation, so it is recorded as an owner decision (§27).

## 15. AI context changes

- **Same boundaries as before:** the AI gateway, permissions, organisation scope, AI data policy, citations and audit. No new assistant, nothing autonomous.
- **Home intelligence** leads with one statement for the person's own work, built only from figures their Home already composed, with its source.
- **The shell panel and the Assistant page** open on the experience's assistant (the page used config order) and offer the role's question first.
- **New deterministic intents:**
  - HR copilot "what needs attention", from `RoleSignals::operations`;
  - manager "changes in my team" (current reports; 30 days);
  - workforce analyst "what changed" (Workforce pulse aggregates, no names);
  - the HR copilot change summary, narrowed to configuration when asked (`audit.view` as before).

Each role's question was answered on the showcase by the real gateway (§19).

## 16. Notification changes

- On the "All" view, unread notifications come first, ordered by what matters to the role (`NotificationCategories::ROLE_ORDER`) and newest first within each group. Read ones follow, newest first.
- The subheading says what comes first.
- Nothing is generated, hidden or re-routed; tabs, filters and snooze are unchanged.

## 17. Security validation

| Requirement | Evidence |
|---|---|
| The UI never grants access | Lenses still come only from permissions and relationships. `switchLens` and Preferences accept only held lenses (tested). Every signal, verb, link and 360 fact is gated by the destination's own `canAccess()`, policy or permission |
| Tenant isolation | Governance counts filter the unscoped `User` by tenant; another tenant's role-less user is not counted (test). The platform-wide failed-jobs count is for platform administrators only (G11, regression test that fails on the old code) |
| Organisation scope | HR operations count only the companies in the viewer's scope rows (test: 1 against 2 tenant-wide) |
| Relationship scope | Team signals and the manager's AI answers cover current reports only (tests). Mutation check: removing the team filter fails the test |
| Field security | No new fields exposed. The 360 identity facts need user access; payslips need the payslip policy; documents only the viewer's own; compensation untouched |
| Command palette and search | Role verbs only with permission (tests); people ranking inside `PeopleVisibility` |
| Notifications | Ordering only; no content added or moved between users |
| AI | Same gateway, assistants, permissions, AI policy and audit. Answers come from scoped queries (tests) |
| Security tests | 211 tests in the security, tenancy, identity, audit and architecture groups plus the UX.16 security and role tests and the UX.15 authorisation-equivalence and persona tests: all pass (§18) |

No security regression was found. The one tenant-boundary issue found (G11) was pre-existing and was fixed first.

## 18. Test results

| Run | Result |
|---|---|
| Baseline (`b43efa4`, before UX.16) | 1,046 tests · 986 passed · 60 skipped (MySQL-only) · 0 failed |
| **Final code** (`php artisan test --parallel`) | **1,062 tests · 1,002 passed · 60 skipped (MySQL-only, as at baseline) · 0 failed** · 13,227 assertions · 609 s |
| MySQL concurrency and scale suites (opt-in, on MySQL) | 60 tests · 60 passed · 245 assertions (`hcm_p14_concurrency`). The Employee 360 scale guard holds 29 queries at 15, 150, 1,500 and 10,000 employees |
| Security, tenancy, identity, audit and architecture groups, plus the UX.16 security and role tests and the UX.15 authorisation-equivalence and persona tests | 211 tests · 211 passed · 4,268 assertions |
| New UX.16 tests | 16: `Ux16RoleSecurityTest` (1), `Ux16RoleExperienceTest` (10), `Ux16RoleJourneysTest` (5). Mutation check: removing the team or tenant filter in `RoleSignals` fails them; the G11 test fails on the old code |
| Existing tests changed | One assertion set, in `UxPersonasTest`. It asserted that the super-admin's Home showed the executive figures, which is the defect fixed by UX.16 (G1). It now asserts the administration Home, and that the executive view (one switch away) still shows those figures. No test was disabled, skipped or weakened |
| Pint | Passed (`vendor/bin/pint --test`) |
| Static checks | `php -l` clean on all 47 changed PHP files; `php artisan view:cache` compiles every template; `npm run build` succeeds |

## 19. Browser validation

**Targeted browser check:** every role's Home and My Work in Chromium, Firefox and the WebKit engine (Playwright WPE MiniBrowser 26.5, **not Apple Safari**), at 1440 and 390 px: 71 of 72 pass. The one miss is the intermittent Firefox notifications abort explained in §21, which is pre-existing.

**Visual check:** the 80 after captures and the palette, 360 and directory states were reviewed by eye (§23 and §24). The full browser matrix is UX.18.

**Zero-training test (16.23).** The same scripted first-time user on both versions: they look at the landing page first, then the most obvious control. Same showcase data; before = `b43efa4` served from a temporary worktree.

| Role | Question | Before UX.16 | After UX.16 |
|---|---|---|---|
| Employee | "I need to take leave." | 1 click (Request leave), 1.8 s, form opens | 1 click, 1.7 s (unchanged) |
| Manager | "Show me what needs my attention." | On landing (decisions and team pulse), 0 clicks | On landing, 0 clicks (Your team now lists probation and exceptions) |
| HR | "Which employee changes need action?" | On landing but **below the first screen** | On landing, **first screen**, 0 clicks |
| Executive | "What's happening in my workforce?" | 1 click and 1 extra screen (Open Workforce pulse), 1.7 s | **On landing**, 0 clicks, 1.3 s |
| Administrator | "What requires system attention?" | 1 click (find the "Platform" chip): 3 figures, one of them cross-tenant | **On landing**, 0 clicks: the governance list |

Dead ends (links in the answer that refuse the person): 0 in both versions. Wrong attempts: 0 in both. Evidence: `ux16/evidence/zero-training-{before,after}.json`, `ux16/zero-training/`.

**Role journeys (16.22)**, Pest, through the real authorised paths, each with its neighbouring boundary:

| Role | Journey | Boundary checked |
|---|---|---|
| Employee | Home → Request leave → pending → My requests | Cannot open Admin Centre |
| Manager | Home decisions → Approval Center approve → done in My Work | n/a |
| HR | Home "probation overdue" → 360 → lifecycle action (confirmed, audited) → the count drops | n/a |
| Executive | Home headline → Workforce pulse → drill-down, aggregated, no names | Cannot open an individual 360 |
| Administrator | Home "MFA not required" → Security policy → change (audited) → the item clears | Governance does not open employee records |

## 20. Accessibility regression status

axe-core, WCAG 2.0/2.1/2.2 A and AA, Chromium 1440 × 1000, reduced motion, light and dark. This is a targeted check on changed components; the full accessibility audit is UX.18.

| Run | Checks | Violations |
|---|---|---|
| UX.16 surfaces (`axe-roles.mjs`): each role's Home and My Work, People (employee, manager, HR), the 360 viewer panels (manager, HR, admin), notifications, Preferences, palette states ("bank", "workforce", "permission") | 46 | **0** |
| UX.15 closure set re-run (`axe-ux15-regression.json`) | 78 | **0** |

In the UX.15 set the HR "person drawer" step could not click a card, because HR's directory now opens on the list. The same drawer, with cards requested explicitly, and the list-view peek were checked in both themes: 0 violations.

**New controls and their semantics:**
- The "View as" chips stay a labelled tablist.
- Signal rows announce their count as text (the large figure is `aria-hidden`), and every Open/Review link names its target for screen readers.
- The My Work lead and the 360 viewer panel are labelled sections.
- No new focus traps; the drawers and palette are unchanged.

## 21. Responsive regression status

UX.17 owns mobile, so UX.16 ran basic regression only. Every role's Home and My Work in Chromium, Firefox and the WebKit engine (not Apple Safari), at desktop 1440 and phone 390 with touch: 72 page loads (`browser-roles.json`).

| Engine | Pass |
|---|---|
| Chromium | 24 / 24 |
| Firefox | 23 / 24 |
| WebKit | 24 / 24 |

No page overflows horizontally at either width. The one miss is Firefox on the manager's Home: an intermittent `uncaught exception: [object Object]`. It occurs when Filament's lazy-loaded database notifications request (the only Livewire update during Home's load, in both versions) is aborted. It reproduces on the pre-UX.16 code too: 1 in 16 loads before, 4 in 16 after, the same request each time (`browser-roles-recheck.txt`). It is handed to UX.18.

Phone layouts were not redesigned. Role-specific mobile work is in the UX.17 handoff.

## 22. Performance regression status

Measured in-process at **10,585 synthetic employees**, median of 3 after a warm-up, the same database for both versions. Before = `b43efa4`; after = final code. Wall time and queries:

| Role | Screen | Before UX.16 | After UX.16 |
|---|---|---|---|
| Employee | Home | 0.31 s · 188 queries | 0.30 s · 189 queries |
| Employee | My work | 0.20 s · 117 queries | 0.21 s · 127 queries |
| Employee | People | 0.18 s · 98 queries | 0.18 s · 101 queries |
| Manager | Home | 1.00 s · 268 queries | 1.02 s · 288 queries |
| Manager | My work | 0.62 s · 165 queries | 0.71 s · 189 queries |
| Manager | People | 0.60 s · 116 queries | 0.61 s · 120 queries |
| HR | Home | 1.66 s · 501 queries | 1.58 s · 500 queries |
| HR | My work | 0.68 s · 157 queries | 0.72 s · 176 queries |
| HR | People | 1.39 s · 106 queries | 1.88 s · 110 queries |
| Payroll | Home | 1.28 s · 447 queries | 1.31 s · 440 queries |
| Payroll | My work | 0.33 s · 116 queries | 0.36 s · 121 queries |
| Payroll | People | 1.28 s · 98 queries | 1.40 s · 101 queries |
| Executive | Home | 0.70 s · 200 queries | 0.64 s · 196 queries |
| Executive | My work | 0.26 s · 124 queries | 0.36 s · 137 queries |
| Executive | People | 0.21 s · 99 queries | 0.22 s · 103 queries |
| Administrator | Home | 1.04 s · 236 queries | 0.66 s · 215 queries |
| Administrator | My work | 0.54 s · 158 queries | 0.63 s · 174 queries |
| Administrator | People | 0.48 s · 109 queries | 0.58 s · 113 queries |

HR People shows +0.5 s in this run only. Repeated three times, alternating before and after, it measured 1.52 / 1.51 / 1.40 s before and 1.74 / 1.52 / 1.42 s after, with identical database time, so the gap is noise. Another project's tests were running on the workstation (load average about 2.5–3).

- **Two regressions were found during validation and fixed:**
  - The executive Home and My Work had called the full Workforce pulse model (1.35 s and 1.15 s). `WorkforcePulse::movement()`, with My Work skipping the trend, brings them to 0.64 s and 0.36 s (0.70 s and 0.26 s before UX.16).
  - "Employee changes take effect this week" ran a correlated exists over every position row (165 ms). Selecting the window first brings the whole HR operations set to 63 ms.
- **Administrator Home is faster** (1.04 s → 0.66 s): it no longer computes executive figures.
- **Manager, executive and administrator My Work are about 0.1 s slower:** they now compute their role leads (team, workforce headline, governance). Everything else is within noise.
- **Pre-existing hot spots** (HR Home about 500 queries and 1.6 s; People pages for scoped HR and payroll about 1.1–1.2 s of database time) are unchanged and handed to UX.18.
- **Payload:** Livewire snapshots are unchanged (about 8 KB). HTML grew 4–17 KB where the list view now opens by default for HR, payroll and administrators.

**Realistic data (16.24).** The final code was measured at 100, 1,000 and 10,585 synthetic employees (the guarded `UxScaleShowcaseSeeder` on disposable `*_showcase` databases). The data includes:
- multi-role users;
- a manager with 125 reports and a flat team of 450;
- line, dotted, functional, mentor and project relationships;
- 434 pending leave requests;
- long names;
- departments with no one in them;
- people with no department or manager.

| Role | Screen | 100 | 1,000 | 10,585 |
|---|---|---|---|---|
| Employee | Home | 189 q · 0.64 s | 189 q · 0.45 s | 189 q · 0.30 s |
| Employee | My work | 127 q · 0.36 s | 127 q · 0.31 s | 127 q · 0.21 s |
| Employee | People | 101 q · 0.33 s | 101 q · 0.28 s | 101 q · 0.18 s |
| Manager | Home | 288 q · 1.39 s | 285 q · 1.27 s | 288 q · 1.02 s |
| Manager | My work | 189 q · 1.01 s | 189 q · 0.94 s | 189 q · 0.71 s |
| Manager | People | 120 q · 0.70 s | 120 q · 0.72 s | 120 q · 0.61 s |
| HR | Home | 282 q · 1.47 s | 417 q · 2.15 s | 500 q · 1.58 s |
| HR | My work | 167 q · 0.89 s | 167 q · 0.96 s | 176 q · 0.72 s |
| HR | People | 109 q · 0.77 s | 109 q · 0.82 s | 110 q · 1.88 s |
| Payroll | Home | 231 q · 1.13 s | 366 q · 1.77 s | 440 q · 1.31 s |
| Payroll | My work | 121 q · 0.55 s | 121 q · 0.60 s | 121 q · 0.36 s |
| Payroll | People | 101 q · 0.66 s | 101 q · 0.77 s | 101 q · 1.40 s |
| Executive | Home | 196 q · 0.53 s | 196 q · 0.51 s | 196 q · 0.64 s |
| Executive | My work | 138 q · 0.40 s | 137 q · 0.42 s | 137 q · 0.36 s |
| Executive | People | 103 q · 0.44 s | 103 q · 0.38 s | 103 q · 0.22 s |
| Administrator | Home | 215 q · 0.78 s | 215 q · 0.85 s | 215 q · 0.66 s |
| Administrator | My work | 174 q · 0.73 s | 174 q · 0.75 s | 174 q · 0.63 s |
| Administrator | People | 113 q · 0.62 s | 113 q · 0.61 s | 113 q · 0.58 s |

- **Query counts are flat across sizes** for every role surface, except the HR and payroll Homes. The extra queries there come from the bounded "What changed" feed already recorded in UX.15 (the pre-UX.16 HR Home also made 501 queries at 10k), not from the role signals: the same people's My Work, which computes the operations signals, stays flat at 167 → 176.
- **Timings at 100 and 1,000 are not comparable** (fresh database, shared workstation); only the 10,585 runs were taken back to back.

Evidence: `ux16/evidence/roles-10k-{before,after}.json`, script `role-measure.php`.

## 23. Screenshots

**145 screenshots** in `docs/ux/ux16/`, fictional showcase data, desktop 1440 × 900:

| Set | Count | Contents |
|---|---|---|
| `before/` | 60 | 30 views × light/dark, captured on `b43efa4` before any UX.16 change. Employee: Home, My Work, People, own 360 (403), My HR, palette "leave". Manager: Home, My Work, Approvals, My team, People, a report's 360, palette "approve". HR: Home, My Work, People, 360, service requests, palette "transfer". Executive: Home, Workforce pulse, org map, pulse drill-down, palette "workforce". Administrator: Home, Admin Centre, roles, audit, platform readiness, palette "permission" |
| `after/` | 80 | The same 30 views plus: the 360 viewer panels (manager, HR, administrator identity), executive and administrator My Work, administrator notifications, payroll Home and My Work, palette "bank" (employee) and "Rahul" (manager); × light/dark |
| `zero-training/` | 5 | The end state of each role's zero-training task (after) |

Capture lists: `before/capture-list.json`, `after/capture-list.json`.

## 24. Before / after review

| Surface | Before UX.16 | Role problem | Design decision | After UX.16 |
|---|---|---|---|---|
| Employee Home | Need attention, Your day, momentum | Nothing about the person's own dates; requests hidden in My Work | Personal signals from real data; My requests on Home | For you (probation review, passport expiry), Need attention, Your day, My requests |
| Manager Home | Decisions, personal attention, Team pulse | Team exceptions, probation and changes invisible; personal items first | Team signals after decisions; personal compact | Decisions, Your team (5 probation decisions due), Your day, compact For you |
| HR Home | Decisions, personal attention, then People operations | Operations below personal noise | Operations first; new document, change and workflow signals | People operations first (17 overdue probations, 204 onboarding tasks, 3 documents) |
| Executive Home | Personal attention, bare figures | No story; personal noise first | Narrative from Workforce pulse; personal compact | Headline, movement against last month, figures, Explore |
| Administrator Home | Executive view: decisions, personal attention, company figures | Wrong experience; no governance | One Administration view; governance gated by screen | Governance (MFA, tenant-wide approvers), At a glance, decisions |
| My Work | One template for all | Generic; HR, executive and admin work absent | Role lead from RoleSignals | Role lead block, then the shared streams |
| Command palette | Fixed groups; "permission" finds nothing | Role-blind | Role group order, role verbs, team-first people | "permission" → Manage roles and permissions; "Rahul" → Your team first |

Screenshot pairs share file names in `ux16/before/` and `ux16/after/`.

## 25. Role scores

Out of 10, before → after, judged from the captures, the zero-training runs and the tests. Not inflated; none above 8.5, because UX.17–UX.19 work remains.

| Role | Clarity | Task efficiency | Context relevance | Navigation | Information hierarchy | PeopleOS differentiation | Consistency |
|---|---|---|---|---|---|---|---|
| Employee | 8 → 8.5 | 8 → 8.5 | 7 → 8.5 | 8 → 8 | 8 → 8.5 | 8 → 8 | 8 → 8.5 |
| Manager | 8 → 8.5 | 8 → 8.5 | 7.5 → 8.5 | 7.5 → 8 | 7.5 → 8.5 | 8 → 8.5 | 8 → 8.5 |
| HR | 7 → 8.5 | 7 → 8 | 7 → 8.5 | 7 → 8 | 6.5 → 8.5 | 7.5 → 8 | 7.5 → 8.5 |
| Executive | 7 → 8.5 | 7 → 8.5 | 6.5 → 8 | 6.5 → 8 | 6 → 8.5 | 8 → 8.5 | 8 → 8.5 |
| Administrator | 5 → 8 | 5.5 → 8 | 4.5 → 8 | 6 → 8 | 5 → 8 | 6 → 7.5 | 7 → 8 |

**Evidence per role:**
- **Employee:** personal signals and My requests on Home; leave still one click.
- **Manager:** team signals on landing; People opens on the team.
- **HR:** operations in the first screen on Home and My Work.
- **Executive:** the answer on landing (was one click and a screen away).
- **Administrator:** lands on governance instead of the executive view.

**What holds administrator differentiation back:** governance items are counts that link to Filament module screens (UX.15 layer) rather than a dedicated governance workspace.

## 26. Remaining issues

**Open, P2:**
- **Employee self-360 (G12):** employees without `employee.view` cannot open their own Employee 360. Their self-experience is My HR, My day, My career, My learning and My compensation, and the 360 shows a "Your record" panel to people who can open it. Granting self-view needs an owner decision on a narrowly scoped permission (for example `employee.self`, own record only, with the Records view reviewed field by field). Not done in UX.16 because it would broaden authorisation.
- **Reminder links (G13):** "Need attention" reminders still link by hard-coded path without a `canAccess()` check. Zero-training found no dead end, but one is possible for unusual permission mixes.
- **Configuration approval threshold:** governance shows changes awaiting approval, but the showcase's medium-risk threshold auto-approved the seeded change, so the screenshot shows "changes this week" rather than "awaiting approval" (correct behaviour, thin demo).
- **No notifications in the showcase for the personas:** role ordering is proven by tests; real traffic will tell whether the order feels right.
- **Administrator governance** is counts that link to module screens; there is no dedicated governance workspace with history.
- **Payroll** is the payroll side of HR rather than a sixth role; its experience is thinner than the five main ones.
- **Personalisation (16.21)** reuses what exists: "Home opens as" (now with the same labels as Home), density, recent items, pinned people, favourite reports, the employee register's remembered filters, the list lens in the URL and snooze. No new personalisation was added.

- **Administrator directory:** no account-status column. Identity and access are shown in the 360's "Identity and access" panel instead.
- **Cleanup:**
  - The measurement worktree was removed.
  - The validation databases `hcm_ux_scale_showcase` (10,585 employees) and `hcm_ux_ladder_showcase` (1,000) were dropped after their evidence was saved. The guarded seeders rebuild them in a few minutes: `UxShowcaseSeeder`, then `UX_SCALE_EMPLOYEES=… UxScaleShowcaseSeeder`, then `UX_SCALE_EDGE=1`.
  - `hcm_ux_showcase` (the 8090 demo) was kept. Its role-signal data was added through `UxShowcaseSeeder`.

**Not open:** no security regression; tests 0 failed; axe 0; no page overflow.

## 27. Handoffs

**UX.17 (mobile and responsive):**
- the bottom bar is role-agnostic (Home, Work, Actions, People, Services) and should follow the experience (for example Team for managers, Pulse for executives, Admin for administrators);
- role Homes on phones are long (the manager's decisions plus Your team plus Your day); a phone order per role is needed;
- the 360 viewer panel sits below the Now panel on phones;
- the HR, payroll and administrator directory defaults to the list view, which stacks on phones; a card default on phones may read better;
- governance and operations rows on phones;
- the role palette on touch.

**UX.18 (performance, accessibility, visual regression):**
- HR Home at 10k: about 500 queries and 1.7 s, pre-existing (operations counts and decision sources);
- the People directory for scoped HR and payroll: about 1.1 s of database time at 10k, pre-existing (filter options);
- HR My Work +0.1 s for the operations lead;
- the intermittent Firefox `[object Object]` from Filament's aborted notification lazy load;
- the full axe audit beyond the targeted UX.16 set;
- visual regression automation for the 145 role screenshots;
- a broader browser matrix including role surfaces on tablets.

**UX.19 (final sign-off):**
- the employee self-360 permission decision (G12);
- whether payroll is its own experience or stays inside HR;
- acceptance criteria per role (the zero-training questions in §19 are a candidate set);
- reminder link checks (G13);
- whether the HR admin + system admin merge into one Administration experience fits every tenant's operating model;
- remaining UX debt from UX.15: module grids still Filament components; a configuration workspace.
