# UX.19 Final UX Sign-off Report

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** UX.19, the final UX sign-off and experience acceptance. It closes the UX programme. It does not cover SaaS or commercial architecture, staging, production or production readiness. Nothing was pushed, merged or deployed.

Companion documents:
- [UX.19 Final UX Audit](UX-19-Final-UX-Audit.md), written before any UX.19 change;
- evidence in `docs/ux/ux19/` (`evidence/`, `before/`, `after/`, `zero-training/`).

## 1. Executive summary

**The question:** does PeopleOS now feel like a coherent Employee Operating System rather than a collection of HRMS modules?

**Yes, within the limits stated here.** Each of the six experiences answers its own question on the first screen, on a phone and on a desktop:
- the employee's day;
- the manager's decisions and team;
- HR's operations;
- payroll's run;
- the executive's workforce;
- the administrator's governance.

They are all built from one platform, one person model and one security model.

The final audit found **no P0 and no P1**. It found P2 problems in exactly the places a sign-off must check:
- **Dead ends.** A reminder sent everyone to a page that does not exist. Another sent most people to a screen that refuses them. 38 of 260 AI-suggested links were refused for the person they were offered to.
- **A crash.** My compensation answered 500 instead of 403 for anyone without an employee record.
- **No own record.** The employee could not see their own record anywhere, although the product's thesis is one person, one lifetime record.
- **The phone 360 header** took half of the first screen.

All of these are fixed and tested.

**The eight decisions handed over by UX.18 are made** (§8):

| Decision | Outcome |
|---|---|
| G12 | Employees open their own Employee 360 read-only, through a new, separate, revocable permission |
| G13 | Reminders resolve through each destination's own access check |
| Payroll | A contextual experience inside the platform |
| Role acceptance criteria | Defined and demonstrated |
| HR Admin vs System Admin | One Administration experience composed from permissions |
| Mobile 360 header | Compacted, not redesigned |
| Module grids | Accepted as intentional debt |
| "View as" | A toggle-button group |

**Validation:**

| Check | Result |
|---|---|
| Full suite | 1,105 tests, 0 failed (60 skipped, MySQL-only) |
| MySQL suite | 60/60 |
| Security regression set | 273/273 |
| Accessibility audit | 330 checks, 0 WCAG violations |
| UX.15 / UX.16 / UX.17 regression sets | 78/0, 46/0, 58/0 |
| Visual regression | 120/120, after 26 reviewed, intentional updates |
| Browser tests | 74 passed, 6 skipped by design, in Chromium, Firefox and the WebKit engine |
| Zero-training | 8 tasks completed on phone and desktop |
| Route sweep | every admin page as all 14 personas: 0 errors, 0 missing pages (7 × 500 before) |
| Performance | no regression against UX.18 at 10,585 employees (all 24 surfaces, alternating runs; sum +1.1 %; recheck of the final code within -1 % to +2 % with identical query counts) |

**Not claimed:**
- Apple Safari, Android Chrome on real devices, a human screen-reader pass, production network latency and multi-user load are **STAGING / PRODUCTION VALIDATION REQUIRED** (§30).
- PeopleOS is **not** a commercially complete SaaS product (§31).
- PeopleOS is **not** production-ready (§32).

**UX PROGRAMME: UX.1–UX.19 COMPLETE. Verdict: UX.19 — COMPLETE** (§33).

## 2. Starting commit

`59118a3` "docs: UX.18 performance, accessibility and visual regression reports" (UX.18 — COMPLETE; clean tree; 139 ahead of origin).

**Baseline:**
- 1,089 tests, 0 failed (60 skipped, MySQL-only);
- MySQL 60/60;
- security set 257/257;
- browser tests 55 passed, 5 skipped;
- 120 visual baselines;
- 0 WCAG violations in the UX.18 audit.

## 3. Final commit

The documentation commit, "docs: UX.19 final UX sign-off report", adds this report and the validation evidence. It is the last commit of UX.19. A commit cannot quote its own hash, so the hash is in `git log` and the closing response. The last code commit is `4110c05`.

## 4. Commits created

| # | Commit | Subject | Files | Lines |
|---|---|---|---|---|
| 1 | `a804d4f` | docs: UX.19 final UX audit, before any change | 28 | +8,269 (audit, probes, before evidence) |
| 2 | `126f25f` | fix: reminders and AI suggestions only send people where they may go (UX.19, G13) | 12 | +3,719 / −45 (incl. probe output) |
| 3 | `0aae2f6` | feat: employees open their own Employee 360, read-only (UX.19, G12) | 7 | +246 / −6 |
| 4 | `ceba6c6` | ux: final experience decisions: manager Home, phone 360 header, View as (UX.19) | 9 | +311 / −12 |
| 5 | `5642386` | test: visual baselines for the UX.19 changes, and after evidence | 44 | +487 (26 reviewed baselines, after screenshots) |
| 6 | `2402e68` | perf: work out a reminder's destination only when a reminder needs it (UX.19) | 4 | +228 / −44 |
| 7 | `4110c05` | perf: check the Employee 360's actions only for the person viewing their own record (UX.19) | 1 | +3 / −2 |
| 8 | (final) | docs: UX.19 final UX sign-off report | report and evidence | — |

Baseline images and JSON evidence dominate the line counts. No history was rewritten.

## 5. Files changed

**Commits 1 to 7:** 100 files, +13,204 / −50. Without baselines and documentation: **25 files, +973 / −50.**

| Area | Files |
|---|---|
| Application (10 PHP) | **`ScreenAccess` (new)**, `NeedsAttention`, `AiGateway`, `AiInteraction`, `EmployeePolicy`, `HomeComposer`, `ViewEmployee`, `MyHr`, `MyCompensation`, `OnboardingPlanResource` |
| Configuration | `config/peopleos.php`: the `employee.self` permission, granted to the `employee` role |
| Front end | `resources/js/peopleos.js` (tablist keys); `resources/css/filament/admin/theme.css` (phone 360 header, "Reports to", "View as" pressed style, palette focus) |
| Blade views (5) | `filament/employees/header`, `filament/pages/home`, `filament/pages/home/team`, `filament/pages/assistant`, `components/pos/ai-response` |
| PHP tests | `Ux19SelfRecordTest`, `Ux19LinksTest`, `Ux19ExperienceTest` (new); `Ux17MobileTest`, `UxPersonasTest` (updated for G12, with reasons) |
| Browser tests | `tests/browser/ux19.browser.mjs` (new); `focus-order.browser.mjs` (selector for `aria-pressed`) |
| Visual baselines | 26 reviewed updates in `tests/visual/__screenshots__/` |
| Documentation | `UX-19-Final-UX-Audit.md`, this report; `docs/ux/ux19/` (evidence scripts and results, `before/`, `after/`, `zero-training/`) |

**Migrations:** none. **Routes and policies:** no new route. One policy ability widened (`EmployeePolicy::view`, own record with `employee.self`). Run `php artisan peopleos:sync-permissions` after deploying (done on the development, showcase and disposable databases).

## 6. UX programme history

| Phase | What it established |
|---|---|
| UX.1–UX.14 | The UX transformation: design system, Home, My Work, the command center, the Employee 360, the People directory, the Approval Center, notifications, AI surfaces. The transformation report said PARTIAL because module CRUD screens were still structurally Filament; UX.15 brought the PeopleOS layer to every module page, and the rest is decision D7 |
| UX.15 + closure | Experience Elevation: the person workspace (one lifetime record, 18 → 3 panels), the PeopleOS layer on every module page, Approval Center at 0.7–0.8 s, the WebKit engine validated (Safari not claimed) |
| UX.16 | Role experiences: five (then six) experiences derived from permissions and relationships, role-first Homes, My Work leads, role-aware navigation, search, notifications, 360 emphasis and AI |
| UX.17 | Mobile: a role-aware phone bar, the role's question answered in the first screen, phone-shaped surfaces, overlays above the bar |
| UX.18 | Validation gate: every surface faster or unchanged at 10,585 employees, 0 WCAG failures, 120 reviewed visual baselines, the Firefox error fixed, Android Back |
| **UX.19** | **Final sign-off:** the eight decisions, no dead ends, the employee's own record, acceptance criteria demonstrated, the programme closed |

## 7. UX.19 audit

The [audit](UX-19-Final-UX-Audit.md) was written and committed (`a804d4f`) before any code changed. It used:
- the UX.15–UX.18 reports;
- the implementation;
- probes on a disposable showcase copy: a reach probe, a sweep of every admin page as all 14 personas (3,479 requests), an AI suggested-link probe, and the 360 header measurement.

**Its findings and what happened to each:**

| # | Finding | Class | Outcome |
|---|---|---|---|
| F1 | No own record for employees (G12) | P2 | **Fixed** (D1) |
| F2 | "Policies to acknowledge" → 404 for every persona | P2 | **Fixed** (G13) |
| F3 | "Announcements to acknowledge" → 403 for most people | P2 | **Fixed** (G13) |
| F4 | Reminder links hard-coded; manager reminders regardless of permission; bank and PAN reminders with no next step | P2 | **Fixed** (G13) |
| F5 | 38 of 260 AI-suggested links were dead ends | P2 | **Fixed**: 0 of 240 AI links offered now; reminders 39 of 39 open |
| F6 | My compensation: 500 instead of 403 (7 of 14 personas) | P2 | **Fixed** |
| F7 | Phone 360 header was 49 % of the first screen | P2 | **Fixed** (D6): 39 % |
| F8 | "View as" announced tabs it is not | P3 | **Fixed** (D8) |
| F9 | Manager Home repeated the decision count six times (M11) | P3 | **Fixed**: the duplicate row is gone |
| F10 | Tablists without arrow keys | P3 | **Fixed**: a shared handler |
| F11 | Onboarding-plan "Open" without the 360's check | P3 | **Fixed** |
| F12 | Palette Tab key switches scope (UX.17) | P3 | **Accepted** (visible "Tab scope · Esc close"; Escape closes from anywhere) |
| F13 | 12 px "—" link in the phone audit list | — | **Not a failure** (WCAG 2.5.8 equivalent-control exception) |

The UX.15 P2 "palette input shows the caret, not a focus outline" is also **fixed**: the input bar now shows focus, as the top-bar search and the assistant do.

## 8. Decision log

| Decision | Evidence | Options considered | Decision | Reason | Implementation required? | Tests required? | Deferred item? | Owner phase |
|---|---|---|---|---|---|---|---|---|
| **G12 · Employee self Employee 360** | `employee` role had no `employee.view`; own 360 = 403 (Priya, Rahul, Meera). The 360 already had a "Your record" panel and own-record rules in attendance, leave, payslips, requests, exit, letters and assets. Field security already guards the subject: compensation is self-level only with the tenant setting; compensation proposals are never shown to their subject; talent records are "never visible to the employee themself"; succession uses the same access service; bank, statutory and background checks have their own permissions. Relation managers are read-only on the view page and `ProfileChangeGuard` refuses writes | (a) Keep refusing and document My HR as the alternative. (b) Grant `employee.view` (opens everyone in scope: rejected). (c) An explicit own-record-only permission. (d) A new, separate "My record" page | **(c) `employee.self`**: own record only, read-only, current tenant, granted to the `employee` role, revocable by role | The thesis is one person, one lifetime record, and the subject was the one person excluded. The record already answers to every domain's own rule, so (c) widens access only to the person's own record, without a parallel page to keep in step. Not convenience: an employee cannot otherwise see what HR holds about them | Yes (`EmployeePolicy`, the 360 page's resource-level check for `employee.self` only, My HR "Your record", config) | Yes: `Ux19SelfRecordTest` (5 tests; 2 mutation checks), browser test, persona matrix | No. The breadth of a self view is a tenant choice through roles (remove `employee.self` from a role to withdraw it) | UX.19 (done) |
| **G13 · Reminder link permissions** | `/admin/kb` 404 for all; `/admin/announcements` 403 for employees, managers, payroll and executives; manager reminders counted without the decision permission; no next step for bank and PAN | (a) Hide broken links. (b) Fix the slugs only. (c) Resolve each reminder to its screen class through that screen's own `canAccess()`, per person, per request; decision reminders only for deciders | **(c)**, with destinations where the work is done (My HR policies, the announcements feed, the Approval Center, My HR for records) | It fixes the dead ends and keeps working when permissions or scope change. The backend still authorises every destination | Yes (`NeedsAttention`, `ScreenAccess`) | Yes: `Ux19LinksTest` (6 tests; 2 mutation checks); probe: 39 reminder links, all 200 | No | UX.19 (done) |
| **Payroll experience** | Payroll already had its own Home lead (run, exceptions, status), My Work lead, phone bar (Home · Work · Actions · Payroll · People), opening assistant (Payroll Auditor) and notification order. Zero-training: the answer is in the first screen on phone and desktop | (A) Fold into HR. (B) A dedicated product. (C) A contextual experience inside the platform | **(C)** | The evidence shows no payroll task failing for want of a separate product. Payroll business logic is not a UX matter | No new UI; acceptance criteria and tests | Yes: `Ux19ExperienceTest` (payroll), zero-training | No | UX.19 (done) |
| **Role acceptance criteria** | UX.17 zero-training set; UX.16 and UX.17 tests | — | Criteria per role (§9), demonstrated by tests, probes and the final zero-training run | Acceptance must be demonstrable, not aesthetic | No | Yes (§9 lists the evidence per criterion) | No | UX.19 (done) |
| **HR Admin vs System Admin** | No default role holds one lens without the other. Every governance item is gated by its screen's `canAccess()` | (a) Split into two experiences. (b) Merge by role name. (c) One Administration experience composed from permissions | **(c)** | It follows actual permissions, never role names. A tenant's IT-only or configuration-only administrator sees only their half | No (already built in UX.16) | Yes: `Ux19ExperienceTest` with an IT-only and a configuration-only custom role; every governance link opens; neither reaches employee records | No | UX.19 (done) |
| **Mobile Employee 360 header** | 372 of 766 px (49 %) for HR and administrators; Summarise alone on a row; actions in three rows; viewer panel in the second screen | (a) Accept. (b) Redesign the 360 (rejected: UX.16 principle). (c) Compact the phone header | **(c)** | Objective layout waste: a lone button on a full row, actions in three rows. Identity, lifecycle, relationship and actions all stay | Yes (CSS, header template) | Yes: browser test (≤ 2 action rows, "Reports to" with the person), markup test, visual baselines, before/after measurement | The viewer panel still starts in the second screen on phones (999 px vs 766). Moving it above the Now panel would be a redesign; accepted | UX.19 (done); remainder accepted |
| **Standard Filament module grids** | UX.18 audit: 0 WCAG violations on the register, users, audit and service requests; targets ≥ 24 px after A5; stable baselines; every resource page composes the PeopleOS layer | (a) Redesign the grids. (b) Fix a few. (c) Accept as intentional debt | **(c)** | No task fails because of them; a redesign would reopen UX.15 for appearance only | No | No (covered by the UX.18 audit and visual suite) | Configuration workspace; record drawer instead of full record pages | Product backlog (owner-prioritised; not a UX phase) |
| **"View as" chips** | `role="tablist"` without a tab panel, arrow keys or roving focus; choosing saves "Home opens as" and changes the phone bar; My Work and module pages use the same chip as a toggle button | (a) Keep tabs and add the keyboard contract. (b) A toggle-button group. (c) A select | **(b)** | Matches what the control does (switch and remember, no panel); consistent with the same chip elsewhere; Tab and Enter already worked | Yes (markup, CSS selector) | Yes: feature and browser tests (group, `aria-pressed`, Enter switches) | No | UX.19 (done) |

**Further decisions made from the evidence:**

| Item | Decision |
|---|---|
| M11 manager Home counts | Decisions listed once; Your team carries the other team exceptions (as My Work already did) |
| F12 palette Tab key | Accepted as designed |
| F10 tablists | Arrow keys, Home and End; manual activation |
| Phone bar "current place" on the own record | "People" is marked while an employee views their own record; consistent with where the 360 lives, P3 (U7) |

**Owner decisions:** none outstanding. Every decision above could be resolved from the product's principles and the evidence. The one with a business dimension is how much of their own record an employee sees. It is left to each tenant through role permissions: `employee.self` can be removed from a role, and every section keeps its own permission.

## 9. Role acceptance criteria

Each criterion was demonstrated, not judged on appearance.

| Role | Criterion | Demonstrated by | Result |
|---|---|---|---|
| Employee | Home answers "what matters to me today" in the first screen (own dates, attention, requests) | UX.16 Home tests; zero-training | Accepted |
| Employee | Request leave in one tap; Submit visible and uncovered on a phone | zero-training (phone and desktop: 1 tap, control uncovered) | Accepted |
| Employee | **Sees their own record, read-only; never another employee's 360 or the register** | `Ux19SelfRecordTest`; persona matrix; browser test; zero-training (2 taps: user menu → My profile; also My HR → Your record) | Accepted |
| Employee | Every reminder leads somewhere they may go | `Ux19LinksTest`; probe (39/39) | Accepted |
| Manager | Decisions first; each decision once on Home; team exceptions next | `Ux19ExperienceTest`; UX.16 tests; screenshots | Accepted |
| Manager | Approve a request: one tap to the decision sheet, Approve visible | zero-training (phone and desktop) | Accepted |
| Manager | Show my team: one tap (bar "Team" on phones, "My team →" on desktop) | zero-training | Accepted |
| Manager | Never loses decisions when switching view | `Ux17MobileTest` ("never loses decisions") | Accepted |
| HR | People operations in the first screen, within organisation scope | zero-training (0 taps); `Ux16RoleExperienceTest` (scope) | Accepted |
| Payroll | The run and what blocks sign-off in the first screen | zero-training (0 taps); `Ux19ExperienceTest` | Accepted |
| Executive | Workforce story in the first screen; aggregates only; no individual 360 | zero-training (0 taps); `Ux16RoleJourneysTest` | Accepted |
| Administrator | Governance in the first screen; each item gated by its screen; IT-only and configuration-only admins see only their half | zero-training (0 taps); `Ux19ExperienceTest`; `Ux16RoleSecurityTest` | Accepted |
| All | No dead end from navigation, reminders or AI | route sweep; reach and AI probes | Accepted (route sweep: 0 × 5xx, 0 × 404 in 3,479 requests; 240 AI and 39 reminder links, 0 dead ends) |
| All | Phone, tablet and desktop: no sideways overflow, bar never over an action | responsive matrix; UX.18 tablet probe; visual suite | Accepted (216/216) |
| All | Accessible: 0 WCAG violations; keyboard operable | §22 | Accepted |
| All | Security boundaries unchanged except the documented own-record path | §25 | Accepted |

## 10. Employee experience

- **Question:** "What matters to me today?" Answered on Home: For you (own dates), Need attention, Your day, My requests.
- **UX.19 changes:**
  - **The own record (G12).** "Your record" in My HR, "My profile" in the user menu, the own card's sheet and notifications about their record all open it. It is read-only. Sensitive data, compensation proposals, talent, succession, HR documents and background checks stay hidden.
  - **Reminders lead somewhere.** "Policies to acknowledge" goes to My HR → Policies (it was a 404). "Announcements to acknowledge" goes to the feed (it was a 403). Bank account and PAN now have a next step: ask HR in My HR.
  - **"What should I do next?"** in the assistant suggests only screens that open.
- **Accepted.**

## 11. Manager experience

- **Question:** "What needs my decision, and what is happening with my team?" Answered by Decisions, then Your team.
- **UX.19 changes:**
  - Decisions are listed once on Home (there were six repetitions of the count).
  - A team-decision reminder appears only for someone who may decide, and opens the Approval Center.
  - The phone 360 header for a report is 252 px (from 276).
- **Accepted.**

## 12. HR experience

- **Question:** "What needs HR operational attention?" Answered by People operations, first on Home and My Work, within organisation scope.
- **UX.19 changes:**
  - The phone 360 header is 300 px (from 372).
  - The Workforce pulse is no longer suggested by the assistant to HR, who may not open it.
- **Accepted.**

## 13. Executive experience

- **Question:** "What is happening across my workforce?" Answered by the workforce headline and movement, in aggregates. No individual Employee 360.
- **UX.19 changes:** none needed; the zero-training answer is in the first screen.
- **Accepted.** The pulse's speed at very large scale is a production item (R4, §32).

## 14. Administrator experience

- **Question:** "What requires configuration, governance or system attention?" Answered by Governance first, each item gated by its screen.
- **UX.19 changes:**
  - The decision on the administration view, proven with custom roles.
  - The phone 360 header is compact.
- **Accepted.** No dedicated governance workspace with history: product backlog (§29).

## 15. Payroll decision

**Option C: a contextual experience inside the shared platform.** It is not a separate product or role model, and it is not folded into HR either. It has:
- Home: the run's period, status, employees and exceptions, with "Resolve before sign-off";
- a My Work lead: "The current run and what blocks sign-off";
- its own phone bar: Home · Work · Actions · Payroll · People;
- the Payroll Auditor as its opening assistant;
- payroll notifications first.

Zero-training: "What blocks the current payroll run?" is answered in the first screen on phone and desktop. Payroll business logic was not touched.

## 16. Navigation

- **Desktop navigation, tablet rail, phone bar and role switching:** unchanged in structure, and accepted.
- **Every destination they show passes its own access check:** UX.16 and UX.17 tests.
- **No dead ends:** the route sweep opened every parameterless admin page as all 14 personas (3,479 requests). Final code: 1,569 × 200, 1,910 × 403 (correct refusals), **0 × 5xx, 0 × 404**. Before UX.19 there were 7 × 500 (My compensation, F6). The before and after sweeps differ in exactly ten answers:
  - My compensation, 500 → 403, for the 7 personas without an employee record;
  - the own record, 403 → 200, for the 3 personas with the employee role (Priya, Rahul, and Meera, who is also an employee).

  No other page changed its answer for any persona.
- **Reminders and AI suggestions** now resolve through `ScreenAccess` / the destination's `canAccess()`.
- **Terminology:** the bar uses the words of the screens it opens.
- **Role switching:** "View as" is a toggle group; the bar follows a switch at once.
- **No role leakage:** the persona matrix, and `Ux17MobileTest` (bar items).
- **No hidden approval decisions:** decisions are always counted on Approvals or Work.

## 17. My Work

- **Leads by role:** About you (employee), Your team (manager), People operations (HR), Payroll, Workforce (executive), Governance (administrator).
- **Decisions are kept** across view switches.
- **The reminder rows** in Follow-ups and Do this next now carry working links. The most severe reminder with a next step leads: for a person without a bank account, "Bank account missing" ("Payroll cannot pay you…").
- **Accepted.**

## 18. Employee 360

- **One workspace for every viewer.** The viewer panel follows the legitimate relationship:
  - self: "Your record";
  - manager: "Your team";
  - HR: "People operations";
  - administrator: "Identity and access".
- **New in UX.19:**
  - the subject is a viewer, read-only;
  - the phone header is compact;
  - "Reports to" stays with the person;
  - there is no empty action row when the viewer has no action.
- **No role gains access through the panel.** Each section answers to its own rule (`Ux19SelfRecordTest`; the existing 360 security tests in the security set).
- **Accepted.**

## 19. Command and search

- **Role verbs, team-first people and "permission → roles":** UX.16.
- **The phone sheet with Cancel:** UX.17.
- **Escape from anywhere:** UX.18.
- **New in UX.19:**
  - the input bar shows focus;
  - the scope chips move with the arrow keys;
  - an employee's own name now opens their own record.
- **Profile links** still need `can('view')` for every result.
- **Accepted.**

## 20. AI

- **Role-aware opening question:** each experience opens on its own assistant.
- **Permission- and tenant-aware:** the gateway checks permission and feature; interactions are tenant-owned; data is read through scoped queries.
- **Relationship scope:** the manager assistant reads the manager's reports only.
- **Cited:** sources on every answer.
- **No autonomous actions:** proposals are `open_screen`, `requires_confirmation`.
- **No employee scoring:** the Workforce Analyst refuses individual predictions.
- **New in UX.19:** suggested actions are checked for the person asking (`ScreenAccess`), and stored ones again when shown. **0 of the 240 AI links now offered are dead ends**, against 38 of 260 before (the links no longer offered are the dead ones).
- **AI scope was not expanded.**
- **Accepted.**

## 21. Notifications

- **Role-ordered** (UX.16).
- **Unread state and the phone sheet** (UX.17); notifications load with the page (UX.18).
- **Deep links are resolved at click time** through each record's policy, so a permission change is respected. They are not an authorisation shortcut.
- **New in UX.19:** a notification about the person's own record opens it (G12); a colleague's record still gives no link (`Ux17MobileTest`, updated with the reason).
- **Accepted.**

## 22. Accessibility regression

| Run | Checks | WCAG violations |
|---|---|---|
| UX.18 full audit, re-run on the final code (axe-core 4.13, Chromium; 52 page states × phone, tablet, desktop × light, dark) | 312 | **0** |
| UX.19 destinations: the own record's Records view, My HR policies, the announcements feed | 18 | **0** |
| UX.15 / UX.16 / UX.17 regression sets | 78 / 46 / 58 | **0 / 0 / 0** |

- **The employee's own record** (`/admin/employees/4`) was a refusal page in UX.18. It is now the record: 0 violations, one h1, no small targets, at all six sizes and themes.
- **Keyboard** (browser tests in three engines):
  - tablists move with the arrow keys, Home and End;
  - "View as" is a group whose chips Enter switches;
  - the palette shows focus;
  - the first Tab still reaches the skip link (UX.18 test).
- **Accessible names and roles:**
  - "View as" is now `role="group"` with `aria-pressed`;
  - "Your record" is a named link;
  - the 360 actions keep their names.
- **Not done here:** a human screen-reader pass (STAGING, §30).

## 23. Responsive regression

- **Responsive matrix** (6 personas; Chromium, Firefox, the WebKit engine; phone 390, small phone 360, tablet 768, desktop 1440): **216 / 216** (Chromium 72/72, Firefox 72/72, WebKit engine 72/72): HTTP 200, no sideways overflow, no script error after settling, the role bar on phones and tablets and not on desktop (`browser-matrix-ux19.json`).
- **Phone 360 header** (Chromium, before → after, px):

| Viewer | Phone header | Section bar starts | Tablet / desktop |
|---|---|---|---|
| HR | 372 → **300** | 516 → **444** | unchanged (230 / 184) |
| Administrator | 372 → **300** | 516 → **444** | unchanged |
| Manager | 276 → **252** | 420 → **396** | unchanged |
| Employee (own record; refused before UX.19) | **200** | **344** | 174 / 132 |

- **Visual suite:** 120/120 on phone, tablet (portrait and landscape) and desktop, light and dark, after the reviewed updates (§27).
- **No new sideways overflow, clipped control, overlay collision or bar overlap.**

## 24. Performance regression

UX.19 is not an optimisation phase. The test is whether anything regressed against UX.18.

**Method:** UX.18's (`docs/ux/ux18/evidence/perf-measure.php`, `perf-interleave.sh`):
- in-process requests through the full kernel, as each persona;
- the 10,585-employee database (`hcm_ux19_s10k_showcase`, rebuilt with the UX.18 seeders, disposable);
- UX.18's final code (a worktree at `59118a3`) and UX.19's code measured **alternately**, surface by surface (before, after, then after, before), so machine drift hits both sides alike. The workstation's swap was full again during the run.

**Run 1:** all 24 surfaces, 2 rounds × 5 requests after 2 warm-ups (`perf-interleave-10k.jsonl`).

| Role | Surface | UX.18 final (median) | UX.19 (median) | Change | Queries UX.18 → UX.19 | DB time UX.18 → UX.19 |
|---|---|---|---|---|---|---|
| Employee | Home | 314 ms | 343 ms | +9 % | 112 → 112 | 67 → 72 ms |
| Employee | My work | 225 ms | 239 ms | +6 % | 68 → 68 | 39 → 39 ms |
| Employee | Directory | 184 ms | 186 ms | +1 % | 45 → 45 | 26 → 28 ms |
| Manager | Home | 1,025 ms | 1,102 ms | +7 % | 183 → 185 | 313 → 336 ms |
| Manager | My work | 729 ms | 744 ms | +2 % | 121 → 123 | 197 → 188 ms |
| Manager | My team | 434 ms | 459 ms | +6 % | 87 → 88 | 118 → 130 ms |
| Manager | People | 440 ms | 468 ms | +6 % | 60 → 60 | 240 → 250 ms |
| Manager | Approval Center | 572 ms | 546 ms | -5 % | 119 → 119 | 129 → 120 ms |
| Manager | Employee 360 (report) | 542 ms | 546 ms | +1 % | 188 → 190 | 164 → 165 ms |
| Manager | Notifications | 203 ms | 203 ms | 0 % | 51 → 51 | 48 → 50 ms |
| HR | Home | 818 ms | 815 ms | 0 % | 146 → 146 | 260 → 249 ms |
| HR | My work | 544 ms | 563 ms | +3 % | 117 → 117 | 145 → 142 ms |
| HR | People directory | 653 ms | 654 ms | 0 % | 54 → 54 | 452 → 452 ms |
| HR | Employee register | 790 ms | 784 ms | -1 % | 113 → 113 | 379 → 390 ms |
| HR | Employee 360 | 524 ms | 518 ms | -1 % | 189 → 191 | 144 → 140 ms |
| Executive | Home | 368 ms | 362 ms | -1 % | 107 → 105 | 220 → 218 ms |
| Executive | My work | 248 ms | 252 ms | +2 % | 80 → 78 | 125 → 122 ms |
| Executive | Workforce pulse | 778 ms | 720 ms | -8 % | 49 → 49 | 199 → 196 ms |
| Administrator | Home | 516 ms | 556 ms | +8 % | 127 → 128 | 92 → 93 ms |
| Administrator | My work | 470 ms | 452 ms | -4 % | 111 → 112 | 80 → 76 ms |
| Administrator | Users | 234 ms | 233 ms | 0 % | 45 → 45 | 30 → 32 ms |
| Payroll | Home | 374 ms | 360 ms | -4 % | 89 → 89 | 132 → 129 ms |
| Payroll | My work | 200 ms | 204 ms | +2 % | 65 → 65 | 52 → 52 ms |
| Payroll | People | 596 ms | 606 ms | +2 % | 45 → 45 | 431 → 437 ms |
| **All 24** | **sum of medians** | **11,782 ms** | **11,915 ms** | **+1.1 %** | | |

**What run 1 showed:**
- The sum is +1.1 %: within noise, and every query count is equal or within +2.
- Three Homes measured +7 % to +9 %. But the manager People page, which UX.19 does not touch, measured +6 %, so part of that is noise.
- Part of it was real: `NeedsAttention` worked out all fourteen reminder destinations on every request, even with no reminder to show (+1–2 queries on the manager and administrator Homes).
- Fixed in `2402e68`: a destination is worked out only when a reminder needs it.
- The two Employee 360 views made two more queries (188 → 190, 189 → 191). The new header asked every action whether it was visible to decide whether to draw the action row, and Filament asks again when rendering. Fixed in `4110c05`: the check runs only for the person viewing their own record (anyone else always has an action). Measured again: 189 (HR) and 188 (manager), UX.18's counts.

**Run 2: recheck of the final code** (`perf-recheck.sh`, `perf-recheck-10k.jsonl`). 4 alternating rounds × 9 requests, the Homes, My team and two surfaces UX.19 did not touch as a noise control:

| Role | Surface | UX.18 final | UX.19 final | Change | Queries |
|---|---|---|---|---|---|
| Employee | Home | 228 ms | 230 ms | +1 % | 112 → 112 |
| Manager | Home | 683 ms | 677 ms | -1 % | 183 → 183 |
| Administrator | Home | 468 ms | 478 ms | +2 % | 127 → 127 |
| Manager | My team | 323 ms | 326 ms | +1 % | 87 → 87 |
| Manager | People (control: not touched by UX.19) | 401 ms | 402 ms | 0 % | 60 → 60 |
| HR | Home (control: not touched by UX.19) | 737 ms | 746 ms | +1 % | 146 → 146 |

**Conclusion:** no regression. Every rechecked surface is within -1 % to +2 % of UX.18 with **identical query counts**, and the 360s are back to UX.18's query counts. UX.18's 10k improvements stand (HR Home and the scoped directory well under a second).

**The employee's own record is new** (it was a refusal). It is the same Employee 360 the manager and HR already open, with fewer sections; the route sweep and zero-training opened it without error.

**Not attempted, as instructed:** precomputed workforce analytics, production-server performance, more than 10,585 employees, and load testing (§32).

## 25. Security validation

UX.19 widened access in exactly one place, by design (G12). Everything else is presentation, or narrows what is offered.

The route sweep proves it. Before and after, every parameterless admin page was opened as all 14 personas (3,479 requests each). The only differences:
- the own record, 403 → 200, for the 3 personas holding the employee role;
- My compensation, 500 → 403.

No other page opened or closed for anyone.

| Requirement | Evidence |
|---|---|
| Tenant isolation | `isOwnRecord` needs the record's tenant to equal both the user's and the current tenant (test with a foreign employee carrying the same user id); the existing tenancy tests |
| Organisation scope | Unchanged; `Ux16RoleExperienceTest` (HR counts in scope), persona matrix |
| Relationship scope | The manager sees reports only; team reminders are computed from current reports on every request |
| Field security | The subject does not get bank, statutory, compensation proposals, talent, succession, HR documents, background checks or workflows; sensitive timeline categories stay hidden (`Ux19SelfRecordTest`) |
| Employee 360 | Another employee's 360 and the register stay refused for `employee.self`; the record check runs on mount and on every update; read-only; revocable; a policy mutation is caught |
| Role switching | A lens grants nothing; "View as" changes presentation only |
| Command actions | Unchanged; profile links need `can('view')` |
| Notification links | Resolved at click time through each record's policy |
| Reminder links | Resolved through the destination's own check; the destination still authorises (test: the person loses the permission, the reminder goes, and the screen refuses) |
| Governance actions | Each item gated by its screen; governance is not a way into employee records (`Ux19ExperienceTest`) |
| Multi-role users | Decisions kept across switches (`Ux17MobileTest`); the bar follows the view |

**Never treated as authorisation:** hidden UI, disabled buttons, absence from navigation, or `ScreenAccess` (which only decides what is offered).

**Security regression set: 273 tests, 273 passed, 4,770 assertions** (UX.18's 257, plus the 16 UX.19 tests).

## 26. Zero-training tasks

Final run on the showcase (`zt19.mjs`). The UX.17 heuristics: a first-time person looks at the first screen of Home, then uses the most obvious control. Phone 390 × 844 (touch) and desktop 1440 × 900.

| Role | Task | Size | First action | Taps / clicks | Screens | Completion | Wording | Security |
|---|---|---|---|---|---|---|---|---|
| Employee | Request leave | phone | "Request leave" (Home) | 1 | 0 (sheet) | Form open; Submit uncovered | The task's own words | 200; own leave only |
| Employee | Request leave | desktop | "Request leave" | 1 | 0 | Same | Same | Same |
| Employee | See my own record | phone | User menu → "My profile" (or My HR → "Your record") | 2 | 1 | Own record, "Your record" panel; header in the first screen | "My profile" / "Your record" | 200 own; colleagues refused |
| Employee | See my own record | desktop | Same | 2 | 1 | Same | Same | Same |
| Manager | Approve a request | phone | "Review" (Decisions) | 1 | 0 (sheet) | Decision sheet; Approve uncovered | "Review" | Only decisions routed to them |
| Manager | Approve a request | desktop | "Review" | 1 | 0 | Same | Same | Same |
| Manager | Show my team | phone | Bar "Team" | 1 | 1 | My team, first screen | "Team" | Current reports only |
| Manager | Show my team | desktop | "My team →" (Your team) | 1 | 1 | Same | "My team" | Same |
| HR | Employee changes needing attention | phone | — | **0** | 0 | People operations in the first screen | "People operations" | Organisation scope |
| HR | Same | desktop | — | **0** | 0 | Same | Same | Same |
| Payroll | What blocks the current run | phone | — | **0** | 0 | Run status and "5 exceptions · Resolve before sign-off" in the first screen | "Payroll", "Exceptions" | Payroll permissions |
| Payroll | Same | desktop | — | **0** | 0 | Same | Same | Same |
| Executive | Workforce status | phone | — | **0** | 0 | Workforce headline in the first screen | "Workforce pulse" | Aggregates only |
| Executive | Same | desktop | — | **0** | 0 | Same | Same | Same |
| Administrator | System and governance attention | phone | — | **0** | 0 | Governance items in the first screen | "Governance" | Each item gated by its screen |
| Administrator | Same | desktop | — | **0** | 0 | Same | Same | Same |

All 16 runs completed; every page reached answered 200. The tasks were not optimised for the fewest clicks. The own record takes two taps because it sits where people expect "me": the avatar menu and My HR.

End-state screenshots: `docs/ux/ux19/zero-training/` (16).

## 27. Visual evidence

Evidence scripts are in `docs/ux/ux19/evidence/`. The browser ones (`zt19.mjs`, `a11y-audit-ux19.mjs`) import the local session helper `auth.mjs` from the Playwright workspace, which is not committed (as in UX.17 and UX.18).

Focused, not a new collection:
- **`docs/ux/ux19/before/` and `after/`** (16 each, the same views): the HR 360 header (phone, tablet, desktop, light and dark), the manager and administrator 360 headers (phone), the employee's own record (phone and desktop, light and dark; a refusal page before), the manager Home (phone and desktop), and My HR (phone and desktop).
- **`zero-training/`** (16).
- **The UX.18 visual suite** stays the regression reference. Its first UX.19 run had 26 differences, each reviewed and classified **intentional** before any baseline moved:

| Screens | Classification |
|---|---|
| Employee Home (phone, tablet, desktop, light and dark) and My Work (phone, desktop) | Intentional (G13): bank and PAN reminders now have a next step, so "Do this next" leads with the most severe |
| Employee My HR (phone) | Intentional (G12): "Your record" |
| Employee notifications (phone) | Intentional (G12): the note about the person's own record now opens it |
| Palette (Chromium phone and desktop, light and dark; Firefox; WebKit engine) | Intentional: the input bar shows focus |
| HR, manager and administrator 360 (phone, light and dark) | Intentional (D6): compact header |
| HR 360 desktop dark | Intentional: "Reports to" spacing, plus the UX.18 target-size change that had stayed under the threshold on its own |
| Manager Home (desktop light and dark, Firefox, phone expanded) | Intentional (M11): decisions listed once |

- Baselines were updated with `--update-snapshots=changed` on rebuilt data. A fresh run then passed **120/120**.
- **Regressions found by the suite: none.**

## 28. Test results

| Run | Result |
|---|---|
| Baseline (`59118a3`) | 1,089 tests, 1,029 passed, 60 skipped, 0 failed |
| **Full suite, final code** (`4110c05`, `php artisan test --parallel --processes=2`) | **1,105 tests · 1,045 passed · 60 skipped (MySQL-only, as at baseline) · 0 failed** · 13,573 assertions |
| MySQL concurrency and scale (`hcm_p14_concurrency`) | **60 / 60** · 244 assertions |
| Security regression set (§25) | **273 / 273** · 4,770 assertions |
| New tests | `Ux19SelfRecordTest` (5), `Ux19LinksTest` (6), `Ux19ExperienceTest` (5); browser `ux19.browser.mjs` (5 × 4 projects); mutation checks: 5 (two for G13, two for G12, one for the manager Home), all caught |
| Role, multi-role, Employee 360, command/search and notification tests | Pass (in the full suite and the security set: `Ux16*`, `Ux17MobileTest`, `Ux15*`, `UxSecurityTest`, `UxPersonasTest`) |
| Browser tests (`tests/browser`: Chromium phone and desktop, Firefox, WebKit engine) | **80 tests: 74 passed, 6 skipped by design, 0 failed** |
| Visual regression (`tests/visual`) | **120 / 120** after the reviewed update, and again on the final code (`4110c05`); 198 undeclared combinations skipped |
| Accessibility | 312/0 + 18/0; UX.15/16/17 sets 78/0, 46/0, 58/0 |
| Responsive matrix | **216 / 216** (Chromium, Firefox, WebKit engine; 6 personas; 390, 360, 768, 1440) |
| Route sweep (every admin page × 14 personas) | **3,479 requests: 0 × 5xx, 0 × 404** (before: 7 × 500) |
| Pint / PHP syntax / Blade / build | Pass: `vendor/bin/pint --test`; `php -l` clean on all 23 changed PHP files; `php artisan view:cache` (Blade templates cached successfully); `npm run build` (built) |

**Existing tests changed** (intentional behaviour changes, each with its reason in the test):

| Test | Change and reason |
|---|---|
| `Ux17MobileTest` (notification links) | It asserted that an employee gets no link to their own 360 ("G12 unchanged"). G12 is now decided, so it expects the own link and still no link to a colleague's record |
| `UxPersonasTest` (persona matrix) | The employee persona's own 360 went from 403 to 200. A new assertion keeps the boundary: the same employee is refused the manager's 360 |
| `focus-order.browser.mjs` | It found the chosen chip by `aria-selected`; the chips are toggle buttons now (`aria-pressed`) |

No test was edited merely to make it pass.

**Failures met during UX.19:**
- **A test artefact, fixed in the test.** A user model kept permissions read in another tenant's context; fresh instances are used.
- **A real harness issue.** A Livewire `mount()` abort is reported as a 403 response, not an exception; the test uses `assertStatus(403)`.
- **A mutation that survived.** Removing the policy's permission check was not caught at first, because the page's resource check also refused. A direct policy assertion was added, and the mutation is now caught.
- **An existing test caught a design gap.** `AiAssistiveControlsTest` showed links must be worked out for the person asking, not the signed-in user. `ScreenAccess::as` was added.
- **WebKit environment.** The WebKit engine needs the documented `WEBKIT_EXE` launcher; with it, all 20 WebKit tests pass.

## 29. Remaining UX issues

None blocks the sign-off. Each has an owner.

| # | Item | Class | Owner |
|---|---|---|---|
| U1 | The phone 360 viewer panel still starts in the second screen (999 px vs a 766 px first screen) | P3 (accepted; moving it above Now would be a redesign) | Product backlog |
| U2 | Configuration workspace (current → impact → history) | P3 | Product backlog |
| U3 | Record drawer instead of full record pages for 28 resources | P3 | Product backlog |
| U4 | Governance workspace with history (counts link to their controls today) | P3 | Product backlog |
| U5 | Module grids are Filament components (D7: accepted debt) | P3 | Product backlog |
| U6 | My Work rows repeat domain words; pinned row actions show a soft edge on tables that fit | P3 | Product backlog |
| U7 | The phone bar marks "People" on the employee's own record | P3 (observation) | Product backlog |
| U8 | Tablists keep every tab as a Tab stop (arrow keys added; no roving tabindex) | P3 | With the screen-reader pass (STAGING) |
| U9 | Administrator directory has no account-status column (the 360 identity panel shows it) | P3 | Product backlog |

"Product backlog" means owner-prioritised product work after real-user feedback in staging. **There is no further UX phase.**

## 30. Staging-only limitations

**STAGING / PRODUCTION VALIDATION REQUIRED.** Not UX.19 failures, and not claimed:

| Item | Status | Ready to run |
|---|---|---|
| Apple Safari (iPhone, iPad, Mac) | No Apple environment here | `tests/visual`, `tests/browser` and the accessibility audit in Safari |
| Android Chrome on a real device | The emulator exhausts this workstation's memory (UX.18, `environment-limits.txt`) | `docs/ux/ux18/evidence/android-chrome.mjs` against a device |
| Human screen-reader pass (VoiceOver, TalkBack, NVDA) | Not possible here | Include U8 |
| Real production network latency | Localhost only | Browser timing scripts (UX.18) |
| Multi-user production load | Not done | Production hardening |

## 31. Commercial / SaaS handoff

Completing UX.19 does **not** make PeopleOS a commercially complete SaaS product. UX.19 implemented none of the following. They belong to the next workstream:

**NEXT WORKSTREAM: SaaS / Commercial Architecture & Gap Analysis.**

It should begin with architecture and discovery, not immediate billing implementation. Its scope:
- plans, plan versions, subscriptions, trials;
- billing, payment gateway, invoices, GST/tax, dunning;
- enforceable entitlements (modules, seats, employee limits, usage limits, AI limits);
- self-service signup, tenant provisioning, onboarding;
- upgrades and downgrades, suspension, cancellation;
- tenant offboarding, export, retention and deletion;
- platform usage, tenant health, commercial administration;
- identity gaps such as password reset and sign-up.

**Notes for that workstream from the UX side:**
- **`employee.self`** is a role permission like any other. If plans package features, it is not a commercial lever: it is an employee's access to their own data.
- **Feature flags (8) exist but are not commercial entitlements.**
- **Platform administrators** keep the platform readiness page and cross-tenant figures. A tenant administrator never sees them (UX.16 G11).

## 32. Production-readiness handoff

PeopleOS is **not** declared production-ready. These remain separate gates:
- statutory payroll rules not fully verified;
- production configuration;
- disaster recovery;
- operator sign-off;
- staging validation;
- real-device browsers;
- production network conditions;
- multi-user load validation.

**Carried production items from the UX programme:**
- precomputed workforce analytics for the executive surfaces at large scale (UX.18 R4);
- performance through a production web server and above 10,585 employees;
- CI visual baselines (R6);
- an upstream report on Livewire's aborted-call rejection (R7);
- Approval Center single lookups for compensation and letter requests;
- the "Show more" rebuild on Approvals;
- org-map virtualisation at large scale.

## 33. Final UX verdict

### 33.1 Final UX scorecard

**These are product-team evaluations against the evidence, not independent user research.** Scores are out of 10, UX.16 → UX.19. UX.16 did not score payroll separately (it was "the payroll side of HR"); its "before" column is an estimate from the UX.16 captures. No score is 10; the remaining P3 items, and the validation still owed to staging, are why.

| Role | Clarity | Efficiency | Context | Navigation | Hierarchy | Differentiation | Consistency | Overall assessment |
|---|---|---|---|---|---|---|---|---|
| Employee | 8.5 → 8.5 | 8.5 → 9 | 8.5 → 9 | 8 → 8.5 | 8.5 → 8.5 | 8 → 8.5 | 8.5 → 8.5 | Strong: the person's own record is now part of their experience; every reminder leads somewhere; leave is one tap on any size |
| Manager | 8.5 → 9 | 8.5 → 8.5 | 8.5 → 8.5 | 8 → 8.5 | 8.5 → 9 | 8.5 → 8.5 | 8.5 → 8.5 | Strong: decisions once, then the team; one tap to decide or to the team |
| HR | 8.5 → 8.5 | 8 → 8.5 | 8.5 → 8.5 | 8 → 8.5 | 8.5 → 8.5 | 8 → 8 | 8.5 → 8.5 | Strong: operations in the first screen on any size; HR Home fast at scale (UX.18) |
| Payroll | ≈7 → 8 | ≈7 → 8 | ≈7 → 8 | ≈7 → 8 | ≈7 → 8 | ≈6.5 → 7 | ≈8 → 8.5 | Good: a contextual experience with the run first; no separate product needed |
| Executive | 8.5 → 8.5 | 8.5 → 8.5 | 8 → 8 | 8 → 8 | 8.5 → 8.5 | 8.5 → 8.5 | 8.5 → 8.5 | Strong; pulse speed at very large scale is a production item |
| Administrator | 8 → 8.5 | 8 → 8.5 | 8 → 8 | 8 → 8 | 8 → 8.5 | 7.5 → 7.5 | 8 → 8.5 | Good: governance first and composed from permissions; no governance workspace with history yet |

**Evidence for each movement:**
- **Employee:**
  - efficiency (reminders all open; own record two taps);
  - context (own record and next steps);
  - navigation (no dead ends);
  - differentiation (one lifetime record visible to its subject).
- **Manager:** clarity and hierarchy (the count once); navigation (Team in the bar, Back closes sheets).
- **HR:** efficiency (first screen on phones in UX.17; 2.0 → 0.9 s at 10k in UX.18); navigation (no dead-end suggestions).
- **Administrator:** clarity, efficiency and hierarchy (governance in the first screen on phones in UX.17); consistency (composed by permissions, proven).
- **Payroll:** run first on Home, My Work, bar and assistant; zero-training 0 taps.

### 33.2 Definition of done (brief §31)

Every item is met:
- the UX.15–UX.18 reports reviewed;
- the final audit completed before any change;
- G12 resolved, and G13 resolved;
- the payroll decision made (C);
- role acceptance criteria established and demonstrated;
- the HR Admin / System Admin decision made;
- the mobile 360 header decided and fixed;
- the module-grid debt dispositioned;
- the "View as" decision made;
- every experience accepted: employee, manager, HR, executive, administrator, payroll (contextual);
- every surface accepted: navigation, My Work, the Employee 360, command and search, AI, notifications, multi-role;
- every regression passes: security, accessibility, responsive, visual, performance;
- the zero-training tasks completed;
- evidence captured;
- every unresolved item assigned (§29, §30);
- the SaaS / commercial scope and the production-readiness scope separated (§31, §32);
- documentation complete;
- the git tree clean; nothing pushed, merged or deployed.

None of the failure conditions in brief §33 applies:
- every core role completes its task;
- no contradictory navigation;
- no security boundary weakened (the one widening is the documented own-record path, tested);
- the Employee 360 exposes nothing unauthorised;
- reminder links do not bypass permission;
- no decision lost on switching;
- no accessibility or responsive regression;
- no unexplained visual difference;
- documentation present;
- acceptance criteria demonstrated.

### 33.3 Verdict

PeopleOS now reads as one Employee Operating System:
- one person, with one lifetime record, which the person can now see;
- one platform and one security model;
- six role-aware experiences;
- contextual action and progressive disclosure;
- accessible, responsive, measured, visually guarded;
- personalisation that grants nothing.

**UX PROGRAMME: UX.1–UX.19 COMPLETE**

**NEXT WORKSTREAM: SaaS / Commercial Architecture & Gap Analysis**, beginning with architecture and discovery, not with billing implementation (§31).

**UX.19 — COMPLETE**
