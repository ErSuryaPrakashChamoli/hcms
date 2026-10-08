# UX.19 Final UX Audit

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Starting commit:** `59118a3` (UX.18 final) · **Scope:** the audit that opens UX.19, the final UX sign-off. It was written before any UX.19 code change. The decisions and fixes it calls for are carried out and reported in the [UX.19 sign-off report](UX-19-Final-UX-Signoff-Report.md).

Evidence for this audit is in `docs/ux/ux19/evidence/` (`*-before.*`) and `docs/ux/ux19/before/` (screenshots).

## 1. How the audit was done

**History read:**
- UX.15: the Experience Elevation report, the Research and Audit, the Closure report;
- UX.16: the Role Experience Audit and the Refinement report;
- UX.17: the Mobile and Responsive Audit and the Experience report;
- UX.18: the Performance, Accessibility and Visual Regression report, the Accessibility Audit and the Visual Regression report.

**Implementation checked, rather than trusting the reports:**
- the policies behind the Employee 360 (`EmployeePolicy`, `PermissionPolicy`, every 360 relation manager's own gate, `PersonWorkspace`, `Employee360`, `TimelineCategories`, `CompensationAccess`, `TalentRecordPolicy`, `SuccessionPolicy`, `EmployeeOwnedPolicy`);
- Filament's page and resource authorisation hooks;
- `NeedsAttention`, `WorkInbox`, `RoleSignals`, `HomeComposer`, `RoleLens`, `MobileNavigation`, `NotificationCenter::linkFor`;
- the AI gateway's proposal filter and every assistant's suggested links;
- the Home, My Work and Employee 360 templates;
- the role catalogue in `config/peopleos.php` and the blueprint (§16–17 Employee 360, §52 employee experience, §80 field-level security).

**Probes run on a disposable copy of the showcase** (`hcm_ux19_showcase`; the showcase's 14 fictional sign-in personas; in-process requests through the full kernel, signed in as each persona):

| Probe | What it asks | File |
|---|---|---|
| Reach probe | Can each persona open each reminder destination, each AI suggestion destination, the self-service pages and their own Employee 360? | `reach-probe.php` → `reach-before.json` |
| Route sweep | Every parameterless admin page as every persona: is any refusal a crash (5xx) or a missing page (404) instead of a 403? | `route-sweep.sh`, `reach-probe.php` (`ALL=1`) → `route-sweep-before.json`. Result: 3,479 requests (249 pages × 14 personas, plus own records): 1,566 × 200, 1,906 × 403, **7 × 500** (all F6), no 404 |
| AI link probe | Every assistant each persona may use, its example questions and the Home opening question: does each suggested link open for the person it is suggested to? | `ai-link-probe.php` → `ai-links-before.json` |
| Header probe | How much of the first screen the Employee 360 header takes, per viewer, at phone, tablet and desktop size | `header-probe.mjs` → `header-before.json` |

**Screenshots:** the screens UX.19 might change, phone, tablet and desktop, light and dark where relevant (`docs/ux/ux19/before/`, 16 files). The 120 reviewed UX.18 baselines are the rest of the visual reference.

## 2. Current UX state

The UX programme (UX.1–UX.18) delivered one platform with six role experiences derived from permissions and relationships: Employee, Manager, HR (people operations), Payroll, Executive and Administration. A lens never grants access; every surface enforces Auth → Tenant → Role → Permission → Organisation scope → Relationship scope → Field security → Record on its own.

| Experience | Question Home answers on landing | What it leads with (UX.16–17, verified in the templates) |
|---|---|---|
| Employee | What matters to me today? | For you (own record), Need attention, Your day, My requests |
| Manager | What needs my decision; how is my team? | Decisions, Your team, Your day |
| HR | What needs operational attention? | People operations |
| Payroll | Where is the run; what blocks sign-off? | The latest run and its blockers |
| Executive | What is happening across my workforce? | Workforce headline and movement |
| Administration | What needs configuration, governance or system attention? | Governance items, each gated by its screen |

**What UX.18 established and UX.19 must keep:**
- 0 WCAG violations in the full audit;
- 120 reviewed visual baselines;
- the Firefox fix and Android Back;
- every role surface faster or unchanged at 100, 1,000 and 10,585 employees;
- 1,089 tests with 0 failed; MySQL 60/60; security set 257/257.

## 3. Findings

Classes: **P0** security or correctness blocker · **P1** core workflow blocker · **P2** material UX issue · **P3** polish or future improvement · **STAGING** needs a real environment · **COMMERCIAL** SaaS and commercial architecture · **PRODUCTION** production readiness.

**No P0 and no P1 was found.** Tenant isolation, organisation and relationship scope, and field security held in every probe. No refusal anywhere leaked data.

| # | Finding | Evidence | Class | Owner | Planned disposition |
|---|---|---|---|---|---|
| F1 | **Employees cannot see their own record anywhere** (G12). The `employee` role has no `employee.view`, so the Employee 360 refuses them (403). My HR is the action layer and says so ("Employee 360 remains the record"). The person sheet shows only directory facts. The "My profile" menu item hides itself. No link leads an employee to their own 360, so there is no dead end, but there is no record either | `reach-before.json`: own 360 = 403 for Priya, Rahul and Meera; `MyHr.php` docblock; `employee-own-360-*.png` | P2 | UX.19 | Decision D1 (§4) |
| F2 | **"Policies to acknowledge" reminder is a dead end for everyone.** It links to `/admin/kb`, which does not exist (the knowledge base is `/admin/articles`; acknowledging happens in My HR → Policies). The Employee Assistant's "What should I do next?" repeats the same link | `reach-before.json`: `/admin/kb` = 404 for all 14 personas; `ai-links-before.json`: 7 × 404 | P2 | UX.19 | G13 fix |
| F3 | **"Announcements to acknowledge" reminder is refused for most people.** It links to the announcements management screen (`/admin/announcements`). Employees, managers, payroll and executives get 403; the feed they can act in is `/admin/announcements-feed` | `reach-before.json`: 403 for Priya, Rahul, Amit, Arjun and Meera | P2 | UX.19 | G13 fix |
| F4 | **Reminder links are hard-coded paths without an access check** (G13). Manager reminders count work whether or not the person may act on it. The bank-account and PAN reminders have no next step at all | `NeedsAttention.php` (`url('/admin/'.$slug)`); consumed by Home, My Work, My HR, My Day, My Team and the manager's team signals | P2 | UX.19 | G13 fix |
| F5 | **AI suggested actions lead to screens the person cannot open.** 38 of 260 suggested links are dead ends (Workforce pulse for HR and other `ai.workforce` holders; articles and requests for a user without them; the two reminder links above). The proposal filter checks only that a link is local | `ai-links-before.json`; `AiGateway::proposals()` | P2 | UX.19 | Filter proposals by the person's access (no AI scope change) |
| F6 | **My compensation crashes (500) instead of refusing** for a user with no employee record. `mount()` records a sensitive view before Filament's access hook refuses the request. This predates UX.18 (the same null lookup existed before) | `route-sweep-before.json`: `/admin/my-compensation` = 500 for all 7 personas without an employee record (the only 5xx in 3,479 requests); `laravel.log` TypeError | P2 | UX.19 | Refuse with 403 before recording anything |
| F7 | **The Employee 360 header takes half the phone's first screen.** For HR and administrators it is 372 of 766 px (49 %) and 276 px for a manager. "Summarise" sits alone on a full row and the four action groups wrap onto two more. The section bar starts at 516 px, and the viewer panel sits in the second screen (1,071 / 1,136 px) | `header-before.json`; `hr-360-header-phone-*.png` | P2 | UX.19 | Decision D6 |
| F8 | **The "View as" chips announce tabs they are not.** `role="tablist"`/`role="tab"`, but there is no tab panel and no arrow-key or roving-focus behaviour. Choosing one saves a preference ("Home opens as") and changes the phone bar. The identical chips on My Work are toggle buttons (`aria-pressed`) | `home.blade.php:64-68`, `my-work.blade.php:36`; `peopleos.js` has no tab keyboard handling | P3 | UX.19 | Decision D8 |
| F9 | **The manager Home repeats the decision count six times in one screen** (M11). The brief, the button, the Decisions header, a "6 decisions need you" row in Your team directly under that list, Intelligence and Team pulse. My Work already drops the duplicate row from Your team; Home does not | `manager-home-desktop-light.png`; `MyWork.php:145` vs `HomeComposer.php:118` | P3 | UX.19 | Drop the duplicate row on Home, as My Work does, unless the person has hidden Decisions |
| F10 | **Other tablists lack arrow-key navigation:** My HR sections, the assistant picker, journey stages and the palette scope. They work with Tab and Enter, but not the arrow keys the role announces | Templates; no handler in `peopleos.js` | P3 | UX.19 if a shared handler is cheap and testable; else STAGING (with the screen-reader pass) | Decide during implementation |
| F11 | **The onboarding-plan list's "Open" row action links to the Employee 360 without a view check** (a possible 403 for a scoped viewer) | `OnboardingPlanResource.php:61` | P3 | UX.19 | Gate it by the 360's own check |
| F12 | The command palette's Tab key switches scope, so Cancel is not a Tab stop from the input (UX.17 P3) | `command-center.blade.php:84-89`: the footer shows "Tab scope" and "Esc close"; Escape closes from anywhere since UX.18 | P3 | — | **Accepted as designed** |
| F13 | A 12 × 48 px "—" link in the phone audit list's stacked rows (UX.18 R5) | `a11y-audit-final.json` (UX.18) | — | — | **Not a failure.** WCAG 2.5.8's equivalent-control exception applies: the same row's other cells link to the same record |

## 4. UX.19 decisions (handed over by UX.18)

The eight decisions, with what the evidence says before anything is changed:

| # | Decision | What the evidence shows | Planned outcome |
|---|---|---|---|
| D1 | **G12: employee self Employee 360** | **For allowing it:** the 360 was built for its subject. `PersonWorkspace` has a "Your record" viewer panel and own exit and onboarding dates. `TimelineCategories` treats an own record explicitly. Attendance, leave, payslips, requests, exit, letters, assets and communications panels already have own-record rules. **Field security already guards the self case:** compensation is self-level only with the tenant's `compensation.self_service` setting; compensation proposals are never shown to their subject; talent records are "never visible to the employee themself"; succession uses the same access service; bank and statutory data need `employee.sensitive.view`; background checks need `bgv.view`. The appraisal panel shows nothing the appraisal screen does not already show its subject. Relation managers are read-only on the view page, and `ProfileChangeGuard` refuses profile writes without permission. **The only barrier** is `EmployeePolicy::view`, which needs `employee.view`. That permission also opens everyone in scope, so it cannot be granted for this | An **explicit, separate permission** `employee.self`: own record only, read-only, current tenant only, granted to the `employee` role, revocable by role. `viewAny`, the register, update, sensitive data and every domain panel rule stay unchanged |
| D2 | **G13: reminder link permissions** | F2–F4: one 404 for everyone, one 403 for most people, hard-coded paths, manager counts regardless of ability to act, and two reminders with no next step | Resolve each reminder to its screen class through that screen's own `canAccess()`. Show a manager reminder only when the person can act on it. Send acknowledgements to the screens where acknowledging happens. Give the bank and PAN reminders a next step (My HR). Destination pages keep their own checks |
| D3 | **Payroll experience** | Payroll already has: a Home block (the latest run, exceptions, status), a My Work lead, its own phone bar (Home · Work · Actions · Payroll · People), the Payroll Auditor as its opening AI, and notification order. Its business screens (control room, auditor, runs) are unchanged. No evidence of a payroll task that fails without a separate product | **Option C: a contextual experience inside the shared platform,** not a separate product or role model. Accepted with its own acceptance criteria and zero-training task |
| D4 | **Role acceptance criteria** | UX.17's phone zero-training tasks are the candidate set; UX.16 and UX.17 tests cover most boundaries | Criteria per role (§5), demonstrated by tests, probes and a final zero-training run |
| D5 | **HR Admin vs System Admin** | In the default role catalogue the two lenses always come together (`tenant-hr-admin` and `tenant-super-admin` hold both; no default role holds one without the other). Every governance item on the Administration Home is gated by the `canAccess()` of the screen it summarises, so a custom role holding only one side sees only its side | **One Administration experience whose sections compose from permissions (hybrid).** Not split. To be proven with an IT-only and a configuration-only custom role |
| D6 | **Mobile Employee 360 header** | F7 | **Compact the phone header without redesigning it:** the actions flow as one wrapping row and the avatar is smaller on phones. Identity, lifecycle, relationship, the viewer panel, every action and the section bar stay. Measure before and after |
| D7 | **Standard Filament module grids** | The UX.18 full audit includes the employee register, users, audit and service requests: 0 WCAG violations; targets ≥ 24 px after A5; visual baselines stable. Every resource page composes the PeopleOS layer (UX.15 closure). The grids are recognisably Filament tables, but no task fails because of them | **Accepted as intentional debt.** No redesign. Documented, with the configuration workspace and the record drawer as backlog items |
| D8 | **"View as" chips** | F8 | **A toggle-button group** (`role="group"`, `aria-pressed`), the same pattern as My Work. It matches what the control does (switches and remembers the view; there is no tab panel). It keeps Tab and Enter, which already work, and stops promising arrow keys. Same look |

## 5. Role acceptance criteria (draft, validated in the report)

Each criterion must be demonstrated by a test, a probe or the final zero-training run, not by appearance.

| Area | Employee | Manager | HR | Payroll | Executive | Administration |
|---|---|---|---|---|---|---|
| Home | Answers "what matters to me today" in the first screen; own dates; requests | Decisions first, then team exceptions; no duplicated counts | Operations first | The run and its blockers first | Workforce story first; aggregates only | Governance first, each item gated by its screen |
| Navigation | No register, no admin; nothing exposed that refuses | My team, Approvals | People operations, Organisation, Workflows | Payroll | Insights, People (aggregate), Organisation | Admin first |
| My Work | About you, then next step | Your team, then decisions; nothing lost on switching | People operations first | Payroll first | Workforce first | Governance first |
| People / Directory | Directory facts only; own card opens own record | Team first | Scoped to their organisation | Scoped | Aggregates; no individual 360 | Identity view |
| Employee 360 | **Own record, read-only** (D1) | Reports | In scope | In scope; sensitive with permission | Refused | Identity and access panel |
| Command and search | Actions and own requests | Team first | People | Payroll actions | Actions, navigation | "permission" finds roles |
| Notifications | Own; links resolved at click time | Team decisions first | Operational exceptions | Payroll | Material workforce change | System and security |
| AI | Own data; **every suggested link opens** | Team | HR copilot | Payroll auditor | Workforce analyst; no individual prediction | Configuration |
| Approvals | Own requests' status | Decide in the sheet | Where routed | Where routed | Where configured | Where routed |
| Role switching | — | Decisions kept when switching | Same | Same | Same | One Administration view |
| Phone, tablet, desktop | The leave form is usable; the bar is never over an action; no sideways scroll | Same | Same | Same | Same | Same |
| Security | No other employee's 360; no sensitive data; tenant-bound | Only reports | Only in scope | Sensitive with permission only | No individual records | Governance is not a back door into employee records |

**Zero-training tasks** (the UX.17 set plus payroll):
- Employee: request leave.
- Manager: approve a request.
- Manager: show my team.
- HR: find employee changes needing attention.
- Executive: understand workforce status.
- Administrator: find system or governance attention.
- Payroll: find what blocks the current run.
- New with D1: Employee: see my own record.

## 6. Unresolved items from UX.15–UX.18, dispositioned

| Source | Item | Disposition | Owner |
|---|---|---|---|
| UX.15 P2 | Configuration workspace (current → impact → history in one view) | Not built. History stays in each record's History tab and Change Intelligence | P3, product backlog (owner-prioritised; there is no further UX phase) |
| UX.15 P2 | Peek → drawer for full record pages (28 resources) | Not built; person chips peek everywhere | P3, product backlog |
| UX.15 P2 | Data grids and fields are Filament components | D7: accepted | — |
| UX.15 P2 | Approval Center: compensation and letter requests use one policy lookup each | Few at tested scale | PRODUCTION (measure at production scale) |
| UX.15 P2 | Palette input shows the caret, not a focus outline | Re-checked in UX.19 (§7 of the report) | UX.19 |
| UX.15 P2 | Pinned row actions show a soft edge on tables that fit | Polish | P3, product backlog |
| UX.15 carried | Per-request shell cost; ChangeFeed checks; People filter options | **Resolved in UX.18** | — |
| UX.15 carried | "Show more" on Approvals rebuilds the queue | Performance at scale | PRODUCTION |
| UX.15 carried | Org map: no minimap or virtualisation | Large-tenant performance | PRODUCTION |
| UX.15 carried | My Work rows repeat domain words | Polish | P3, product backlog |
| UX.15 carried | Dense "Need attention" rows on phones | **Resolved in UX.17** (phone caps) | — |
| UX.15 carried | Negative synthetic leave balances in demos | Showcase data, not product behaviour | Closed |
| UX.16 | G12, G13 | D1, D2 | UX.19 |
| UX.16 | Configuration approval threshold shows "changes this week" in the demo | Correct behaviour; thin demo data | Closed |
| UX.16 | No notifications in the showcase | **Resolved in UX.17** (seeded) | — |
| UX.16 | Administrator governance is counts linking to module screens; no governance workspace with history | Every count opens its control; the 360 identity panel and Change Intelligence carry history | P3, product backlog |
| UX.16 | Payroll thinner than the five main experiences | D3 | UX.19 |
| UX.16 | Personalisation reuses what exists | Accepted; no new personalisation | — |
| UX.16 | Administrator directory has no account-status column | The 360 identity panel shows it | P3, product backlog |
| UX.17 | 360 header height on phones (M16) | D6 | UX.19 |
| UX.17 | Back while a sheet is open | **Resolved in UX.18** | — |
| UX.17 | Palette Tab key switches scope | F12: accepted | — |
| UX.17 | Duplicated counts on the manager Home (M11) | F9 | UX.19 |
| UX.17 | Small targets in Filament module chrome | **Resolved in UX.18** (A5); F13 remainder is not a failure | — |
| UX.17 | +40–55 ms on two pages; Firefox `[object Object]` | **Resolved in UX.18** | — |
| UX.18 R1, R2 | Apple Safari; Android Chrome on real devices | Not available here | STAGING |
| UX.18 R3 | Human screen-reader pass | Not available here | STAGING |
| UX.18 R4 | Workforce pulse and executive Home at large scale | Precomputed daily snapshots | PRODUCTION |
| UX.18 R5 | Filament leftovers (empty action header, wizard `role="group"`, the "—" link, register queries) | D7 and F13 | Accepted / PRODUCTION (register queries) |
| UX.18 R6 | Visual baselines tied to this workstation | Generate and review CI baselines when CI exists | PRODUCTION |
| UX.18 R7 | Livewire rejects aborted calls with a plain object | Guarded by a test and the convention | PRODUCTION (upstream report) |
| UX.18 R8 | "View as" chips | D8 | UX.19 |

## 7. Security considerations for UX.19

- **D1 widens access, so it is designed narrowly:**
  - a separate permission (never `employee.view`);
  - own record only (`employees.user_id` = the signed-in user);
  - the current tenant only;
  - only the Employee 360 view page relaxes Filament's resource-level check. The register, edit, create and navigation keep `viewAny`.
  - The record check runs on mount and on every Livewire update.
  - Every domain panel keeps its own rule; no panel is opened by the UI.
  - Tests must prove:
    - another employee's 360 is still refused;
    - the register is still refused;
    - sensitive panels, compensation proposals, talent and succession stay hidden;
    - the panels are read-only;
    - revoking the permission closes it;
    - tenant isolation holds.
- **D2 and F5 must not merely hide links.** The destination pages keep their own authorisation (tested by opening every link as the person who sees it). The access check decides what is offered, never what is allowed.
- **D5** adds no access; its tests use custom roles to prove each half sees only its own governance.
- **D6, D8 and F9** change presentation only.

## 8. Staging-only validation (not UX.19 failures)

STAGING / PRODUCTION VALIDATION REQUIRED:
- Apple Safari on iPhone, iPad and Mac;
- Android Chrome on a real device;
- a human screen-reader pass (VoiceOver, TalkBack, NVDA);
- real production network latency;
- multi-user production load.

None of these is claimed in UX.19.

## 9. Outside the UX scope

- **COMMERCIAL** (the next workstream, SaaS / Commercial Architecture and Gap Analysis): plans and plan versions, subscriptions, trials, billing, payment gateway, invoices, GST, dunning, enforceable entitlements (modules, seats, employee, usage and AI limits), self-service signup and provisioning, onboarding, upgrades and downgrades, suspension, cancellation, tenant offboarding, export, retention and deletion, platform usage, tenant health, commercial administration, and identity gaps such as password reset and sign-up.
- **PRODUCTION:** statutory payroll verification, production configuration, disaster recovery, operator sign-off, staging validation, real-device browsers, production network, multi-user load, and the PRODUCTION items in §6.

UX.19 implements none of these.

## 10. Plan

In order, each with its own commit and tests:
1. **G13 and F5:** reminders and AI suggestions resolve to screens the person can open.
2. **F6, F11:** My compensation refuses cleanly; the onboarding-plan "Open" action is gated.
3. **G12 (D1):** the explicit self path.
4. **F9, D6, D8:** the manager Home row, the phone header and the view control.
5. **D5:** administration composition tests.
6. **Validation:** full and MySQL suites, the security set, accessibility, responsive, visual regression, browser tests, performance, and the final zero-training run.
7. **The sign-off report.**
