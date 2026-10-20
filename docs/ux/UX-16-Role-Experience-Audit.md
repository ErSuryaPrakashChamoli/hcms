# UX.16 Role Experience Audit

**Date:** 4 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Starting commit:** `b43efa4` (UX.15 closure complete) · **Scope:** UX.16 role-based experience refinement. Not UX.17–UX.19.

This audit was written before any UX.16 code change. Sources:
- the UX.15 reports (`PeopleOS-UX-15-Experience-Elevation-Report.md`, `UX-15-Research-and-Audit.md`, `UX-15-Closure-Report.md`, `UX-15-Module-Migration-Plan.md`);
- a read of the implementation (file references below);
- 60 "before" screenshots in `docs/ux/ux16/before/`: 30 views × light/dark, one set per role persona, including the command palette.

## 0. Baseline

| | |
|---|---|
| Branch / HEAD | `feature/oct_1_phase_1` at `b43efa4`, clean working tree, 99 commits ahead of origin, never pushed |
| Tests at start | 1,046 tests · 986 passed · 60 skipped (MySQL-only) · 0 failed (`php artisan test --parallel`, re-run before UX.16) |
| Showcase personas | Priya (employee), Amit (employee + manager, team Platform), Neha (employee + HR manager, company Demo Technologies), Meera (employee + executive), Kavya (employee + tenant HR admin = the administrator persona), Arjun (employee + payroll admin). Every persona is multi-role |

## 1. How the experience adapts today

**Roles are permission bundles.** The `roles` table is mirrored from `config/peopleos.php` (13 system roles). A user can hold several roles through `role_user`, which has a company pivot. Security is decided by `User::hasPermission`, policies, `AccessScopes` (organisation scope), relationship rules and field gates. The UI does not decide access.

**Lenses.** `app/Domain/Experience/Services/RoleLens.php` derives seven experience lenses from permissions and reporting relationships, never from role names:

| Lens | Derived from |
|---|---|
| employee | an employee record linked to the user |
| manager | that employee has current reports |
| hr | `employee.update`, `servicedesk.agent` or `onboarding.manage` |
| hr_admin | `configuration.publish`, or `employee.create` + `policy.update` |
| payroll | `payroll.calculate` or `payroll.approve` |
| executive | `analytics.executive` |
| system_admin | platform admin, `user.assign_roles` or `settings.update` |

`primary()` picks the stored preference, or else the first match in `executive > hr_admin > hr > payroll > manager > employee > system_admin`. The lens grants nothing.

**What the lens actually changes today:**
- Home sections (via `HomeComposer::for()`);
- the order of the idle "Suggested" list in the command palette;
- the default AI assistant in the shell panel.

**What ignores the lens:**
- the navigation (`ExperienceNavigation` never reads it);
- the mobile bar;
- the user menu;
- the login landing (everyone lands on Home);
- My Work;
- typed command-palette results;
- notifications;
- the Assistant page;
- the Employee 360;
- the People directory.

Where those differ by role, it is only because each page's own `canAccess()` hides what the user cannot open.

## 2. Findings that apply to every role

| # | Finding | Evidence | Severity |
|---|---|---|---|
| G1 | **Administrators land in the executive view.** `tenant-hr-admin` includes `analytics.*`, so `executive` wins the priority. Kavya's Home opens on "Company", not on governance | `RoleLens.php:36`; `before/r5-admin-home` | P1 |
| G2 | **Duplicated "View as" chips.** Kavya sees six (For you, People operations, HR admin, Payroll, Company, Platform). HR admin and Platform are both "administration". Chip labels differ from the Preferences labels | `home.blade.php:5-8`, `RoleLens::LABELS` | P1 |
| G3 | **The same Home template for all roles:** greeting, brief, chips, welcome, then "Need attention". The brief, the decision count and "Need attention" ignore the lens. The executive's attention list is personal reminders (acknowledge policies, tax declaration) above the workforce pulse | `HomeComposer::for()`, `before/r4-executive-home` | P1 |
| G4 | **The administrator Home has almost no governance.** The system-admin lens shows three counts (configuration changes, dead letters, failed jobs); the hr_admin lens shows HR operations plus a workforce pulse. Nothing covers access, roles, scope coverage, security settings, notification failures or audit | `HomeComposer::operations()/platform()` | P1 |
| G5 | **My Work is one template for everyone.** Do this next, Decisions, Tasks, Follow-ups and Waiting are the same streams and copy. HR, executive and admin work never appears (HR operations live only on Home) | `MyWork.php`, `WorkInbox.php`; `before/r2-manager-my-work`, `before/r3-hr-my-work` | P1 |
| G6 | **The navigation ignores the role.** Section order and landings are fixed. A multi-role user always sees the union in the same order | `ExperienceNavigation.php:20-30, 66-76` | P2 |
| G7 | **Typed palette results are not role-aware.** The group order is fixed and people come back in database order. An administrator typing "permission" gets **no results** (the page is called Roles). "Manage permissions", "Open audit", "Show my team", "Review probation" and "Show workforce changes" do not exist as actions | `CommandSearch.php:87-97, 158-167`; `before/r5-admin-command` | P1 |
| G8 | **AI context is the same for every role.** The Home intelligence statements are the same for any allowed viewer. The Assistant page defaults to the first assistant in config order, while the shell panel defaults by lens. Suggested questions are static | `ContextualIntelligence::forHome()`, `AssistantPage.php:41-47`, `AiAssistant.php:43-45` | P2 |
| G9 | **Notifications have no priority.** Grouping comes from event-name substrings, sorted newest first, the same for every role | `NotificationCategories.php`, `NotificationCenter.php:89` | P2 |
| G10 | **The Employee 360 is the same for every viewer allowed in.** Differences come only from section gates and own/other wording. There is no "what this viewer is here for" emphasis | `header.blade.php`, `workspace.blade.php`, `PersonWorkspace.php` | P2 |
| G11 | **Security (tenant boundary).** The system-admin Home shows the global `failed_jobs` count, which spans tenants, to a tenant administrator. Admin Centre gates the same figure to platform administrators; Home does not. Home's configuration and dead-letter counts are also not gated by the screen they summarise (only their links are) | `HomeComposer.php:386-399` vs `AdminCentre.php:168-185` | **P0 (fix first)** |
| G12 | **Employees cannot open their own Employee 360.** The `employee` role has no `employee.view`, and `PermissionPolicy::view` has no own-record rule, so the page returns 403. "My profile" in the user menu hides itself. The 360 already contains own-viewer logic, which only users with `employee.view` (managers, HR) can reach | `EmployeePolicy`, `PermissionPolicy.php:23-26`; `before/r1-employee-self-360` | P1 (decision) |
| G13 | **Reminder links are hard-coded** (`/admin/{slug}`) without a `canAccess()` check, so a reminder can lead to a page the person cannot open | `NeedsAttention.php:88-91` | P2 |
| G14 | **Thin showcase data for role signals:** no attendance punches, no expiring documents, no configuration changes or integration failures, and almost everyone in probation for years | showcase database | P2 (validation) |

**Decision on G12.** Letting employees open their own 360 would broaden authorisation: it needs an own-record rule in the policy, or a new permission. The UX.16 brief says the UI must never grant access and forbids weakening security or introducing permission architecture unless proven necessary. UX.16 therefore keeps the rule unchanged. The employee's self-experience goes through the existing self-service pages (My HR, My day, My career, My learning, My compensation), and the 360 gains own-viewer emphasis for people who can already open it. A narrowly scoped `employee.self` permission is recorded as an owner decision for UX.19.

## 3. Role audit

### EMPLOYEE (Priya)

| | |
|---|---|
| **Current experience** | Home: greeting and brief, "Request leave", welcome, Need attention (3 reminders), Your day (not checked in, next leave, tasks), Your people, Your momentum (journey, balances), Start something. My Work: generic streams. People: "Your manager, your team and you". Own 360: 403 |
| **Primary jobs** | Take leave, fix attendance, see pay, finish personal tasks (acknowledgements, declarations), follow own requests, grow (learning, goals) |
| **Most important information** | What needs me today, my leave balance and next leave, my requests and their state, payslip availability, probation and review dates, documents about to expire, manager and team |
| **Most important actions** | Request leave, fix attendance, ask HR, acknowledge, open payslip, update bank details |
| **Current friction** | "My requests" is not on Home; it sits in My Work as "Waiting on others". No personal dates (probation review, document expiry, payslip ready, manager change). No self 360 |
| **Unnecessary information** | The welcome note on every visit until dismissed (by design). Nothing administrative leaks |
| **Missing context** | Why a date matters ("probation review in 12 days"), the state of their own requests |
| **Navigation issues** | Personal pages live only in the user menu and the "Your pages" bar. The palette for "leave" offers "Open approvals" even though the employee has no approval rights |
| **Action priority issues** | The primary button is "Request leave", which is good; "Start something" mixes in manager actions when the person also manages |
| **Security/scope constraints** | Self-service by permission. No `employee.view`. Documents need `document.own`, payslips the payslip policy, compensation `compensation.self` |
| **Proposed UX change** | "For you" signals from real personal data (request needs attention, probation review due, document expiring, payslip available, manager changed). "My requests" with states on Home and in My Work. The palette offers personal verbs (apply leave, payslip, bank details). No approval actions without approval rights. Self entry to My HR from the directory |
| **Priority** | P1 |

### MANAGER (Amit)

| | |
|---|---|
| **Current experience** | Home: decisions (6, with Review), Need attention (personal), Team pulse (in today, on leave), Start something. My Work: decisions first, the same template as HR. The palette's "approve" works. The 360 of a report is the same as for HR |
| **Primary jobs** | Decide on requests, keep the team's attendance and leave healthy, run probation and reviews, follow team changes |
| **Most important information** | Decisions waiting, people away or with attendance exceptions, probation decisions due, reviews and goals to complete, team members who changed |
| **Most important actions** | Approve or reject, review probation, complete reviews, open a team member, see the leave calendar |
| **Current friction** | Team exceptions, probation due, reviews and team changes are not on Home. Personal reminders sit above the team. "Show my team" and "Review probation" are not palette verbs |
| **Unnecessary information** | Personal acknowledgements ahead of team items |
| **Missing context** | Which team member, why it matters, where to act |
| **Navigation issues** | My team sits inside People. No team-first ordering |
| **Action priority issues** | Decisions are primary (good). Team actions are scattered |
| **Security/scope constraints** | Relationship scope: current reports by the configured manager relationship types (`PerformanceRelationships`), plus organisation scope (team Platform). Visibility must not broaden beyond `PeopleVisibility` and `AccessScopes` |
| **Proposed UX change** | Team signals ("N approvals need you", "N team members have attendance exceptions", "N probation decisions due", "N reviews to complete", "N team members changed"), each linking to the context. The team comes first in search results. Palette verbs: approve leave, show my team, review probation. A manager emphasis on a report's 360 |
| **Priority** | P1 |

### HR (Neha; payroll lead Arjun as the payroll lens)

| | |
|---|---|
| **Current experience** | Home: decisions (leave in scope), Need attention (personal), People operations figures (joining, probation, onboarding overdue, SLA, exits, stale leave), Start something. My Work: generic. The payroll lens Home shows the latest run |
| **Primary jobs** | Run the lifecycle (joiners, probation, exits), service requests, documents, compliance, employee changes, workflows |
| **Most important information** | Operational exceptions by severity, with reasons and counts; documents awaiting verification; stuck workflows; changes taking effect soon |
| **Most important actions** | Add an employee, transfer, open a request, verify documents, open the employee |
| **Current friction** | HR operations are on Home only, not in My Work. Personal reminders sit above operations. No document verification or workflow signals |
| **Unnecessary information** | Personal acknowledgements first |
| **Missing context** | What is overdue versus coming up |
| **Navigation issues** | Fixed section order: Organisation and Workflows sit below Insights |
| **Action priority issues** | Start something lists find, add employee, transfer; good but generic |
| **Security/scope constraints** | Organisation scope: Neha is limited to company Demo Technologies. Every count comes from scoped queries (`AccessScope` global scope, `CaseAccess`). No "HR superuser" |
| **Proposed UX change** | People operations first on Home and as the lead stream in My Work, with new signals (documents to verify, workflows needing attention, changes taking effect this week). Personal items move to a compact "For you" strip. Palette verbs: open service requests, create employee, transfer employee |
| **Priority** | P1 |

### EXECUTIVE (Meera)

| | |
|---|---|
| **Current experience** | Home: Need attention (personal), What changed (1 joiner), Workforce pulse figures (21, 0, 1, 4.7%, 0), Start something (pulse, org map, request leave, fix attendance). Workforce pulse page: narrative and drill-down |
| **Primary jobs** | Understand workforce movement, cost, headcount, attrition and important changes; drill into evidence |
| **Most important information** | The movement story this month versus last, headcount trend, attrition, critical positions and succession where permitted, material changes |
| **Most important actions** | Open Workforce pulse, explore a movement, open the org map, read changes |
| **Current friction** | Home opens with personal reminders and bare figures without a story; the narrative exists only on the Workforce pulse page |
| **Unnecessary information** | Request leave and fix attendance in Start something, which are personal and belong in "For you" |
| **Missing context** | "What changed?" then "Explore" from Home |
| **Navigation issues** | Insights (Workforce pulse) is fifth in the rail |
| **Action priority issues** | No primary action. Workforce pulse should be primary |
| **Security/scope constraints** | No transactional access; no `employee.view` (cannot open individual 360s). Protected metrics show "restricted" without their domain permission. Small groups are suppressed in drill-downs. No risk scoring, no predictions |
| **Proposed UX change** | A narrative workforce pulse on Home from `WorkforcePulse` (headline, movement this month versus last, what changed, Explore), material changes, and critical positions where permitted. Personal items compact. Insights first in navigation. Palette: show workforce pulse, show workforce changes |
| **Priority** | P1 |

### ADMINISTRATOR (Kavya; tenant super-admins)

| | |
|---|---|
| **Current experience** | Home in the executive view (G1): decisions (6 leave approvals), personal attention, "Company" figures. Admin Centre: finder, module categories, awaiting approval, platform health. The palette gives no result for "permission" |
| **Primary jobs** | Govern configuration (pending and recent changes), access (users, roles, scopes), security (MFA, SSO, IP policy), integrations, audit, notifications, workflows |
| **Most important information** | Configuration awaiting approval and recently changed; users without roles; managers or HR without organisation scope; security policy state; integration failures; notification delivery failures; workflows in error; audit activity |
| **Most important actions** | Review configuration, manage roles and permissions, open audit, configure workflows, open the integration hub, open security policy |
| **Current friction** | Lands in the executive view. No governance summary on Home. Role and permission search fails |
| **Unnecessary information** | Workforce figures first; operational leave decisions first (authorised, but not the admin's main job) |
| **Missing context** | Why a governance item matters ("2 users can sign in with no role") |
| **Navigation issues** | Admin is the last section |
| **Action priority issues** | No admin quick actions on Home (no QuickAction is tagged `hr_admin`) |
| **Security/scope constraints** | Each figure must be gated by the screen it summarises. Platform-level signals (failed jobs, cross-tenant health) are for platform administrators only (G11) |
| **Proposed UX change** | One Administration view (HR admin + system admin lenses together). A governance Home: configuration, access and scope coverage, security, integrations and delivery, workflows, audit, each gated by its screen and linking to the control. Decisions and personal items follow. Admin first in navigation. Palette: manage permissions, open audit, configure workflow, security policy |
| **Priority** | P1 |

## 4. Audit by surface

| Surface | Today | Role problem | UX.16 change |
|---|---|---|---|
| Login and landing | Everyone lands on Home | None if Home leads with the role. Landing on another page would add a hop | Keep Home as the landing. Make its first screen role-led |
| Home | One template, lens-composed | G1–G4 | Role-led Home for the five experiences (§6) |
| Navigation | Nine sections, fixed order | G6 | Order and section landing follow the primary experience. Nothing authorised is hidden |
| Command palette | Fixed group order, lexical | G7 | Role-ranked groups, team-first people, role verbs, synonyms ("permission" → Roles) |
| My Work | One template | G5 | Role lead stream (personal, team, operations, workforce or governance) before the shared streams |
| Notifications | Newest first, no priority | G9 | "Needs you" first, ranked by role relevance and severity. No new notifications generated |
| Search (people) | Database order | G7 | Manager: team first. Ordering by name for everyone. No visibility change |
| People directory | The same views for all | Manager wants the team first; HR wants lifecycle context | Role-aware intro and default grouping. Field security unchanged |
| Employee 360 | The same for every viewer | G10, G12 | A viewer emphasis line and a context panel for self, manager, HR and admin (identity and access only with `user.view`) |
| Organisation map | The same | Fine for UX.16 | No change |
| Approvals | Permission and scope | Fine. Must stay governed by the workflow engine | No new categories. Role-aware empty-state copy only |
| Requests and services | My HR front door | Employees need request state on Home | "My requests" on the employee Home and in My Work |
| Analytics | Workforce pulse page | Executive Home lacks the story | Narrative pulse on the executive Home |
| Communication | Announcements | Fine | No change |
| AI | Same statements; static prompts | G8 | Role-aware intelligence statements and suggested questions through existing assistants. Same gateway, audit and citations |
| Settings and profile | Preferences: "Home opens as" | Labels differ from the chips | One label set; experience-level choice |
| Mobile foundation | Bottom bar | Role-agnostic | Basic regression only; role mobile is UX.17 |
| Contextual actions | QuickActions with lens tags | No admin tags; missing role verbs | Add role verbs, each gated by `canAccess()` or permission |

## 5. Role experience matrix (16.3)

| | Employee | Manager | HR | Executive | Administrator |
|---|---|---|---|---|---|
| **Primary goal** | What matters to me today | What needs my decision; how my team is | What needs HR operational attention | What is happening across my workforce | What needs configuration, governance or system attention |
| **Top 5 tasks** | Request leave · fix attendance · finish tasks and acknowledgements · follow my requests · learn | Decide requests · probation decisions · reviews · team attendance · follow team changes | Onboard joiners · probation and lifecycle · service requests · verify documents · employee changes | Read workforce movement · review headcount and attrition · see material changes · check critical roles · drill into evidence | Review configuration · manage roles and access · check security policy · resolve integration and delivery failures · review audit |
| **Top 5 information needs** | Leave balance and next leave · my request states · tasks due · payslip · probation, document and manager dates | Decisions waiting · away today · attendance exceptions · probation due · team changes | Overdue lifecycle items · SLA breaches · onboarding overdue · documents to verify · stuck workflows | Movement this month versus last · headcount trend · attrition · critical positions (if permitted) · important changes | Configuration pending and recent · users without roles · scope coverage · security settings · failures (integrations, notifications, workflows) |
| **Top 5 actions** | Request leave · regularise · ask HR · open payslip · update bank details | Approve or reject · review probation · open team member · leave calendar · complete review | Add employee · transfer · open requests · verify document · open employee | Open Workforce pulse · explore movement · org map · read changes · ask the workforce analyst | Review configuration · manage roles and permissions · open audit · configure workflow · open integration hub |
| **Top 5 alerts/exceptions** | Request needs attention · probation review due · document expiring · payslip available · manager changed | Approvals waiting · attendance exceptions · probation due · reviews to complete · team member changed | Probation overdue · SLA breached · onboarding overdue · leaving within 7 days · documents awaiting verification | Movement up or down · attrition change · critical position without a successor (if permitted) · large org change · approvals for them | Configuration awaiting approval · users with no role · managers or HR without scope · delivery failures · workflows in error |
| **Primary entities** | Self, own requests, documents, payslips | Direct and configured-relationship reports, their requests | Employees in scope, lifecycle cases, tickets, documents, workflows | Workforce aggregates, departments, positions | Configuration changes, users, roles, scopes, integrations, workflows, audit events |
| **Primary workspace** | Home + My HR | Home + Approval Center + My team | Home + People + Service desk | Home + Workforce pulse | Home + Admin Centre |
| **Primary search behaviour** | Personal verbs and own records first ("leave" → apply leave, my leave, policy) | Team members first, then verbs | Employees in scope, requests, verbs | Insights pages, workforce verbs | Settings, roles and permissions, audit, modules |
| **Primary approval behaviour** | Own requests waiting on others (no approver role unless granted) | Team decisions (permission + relationship + scope) | Operational approvals in scope | Workforce decisions where configured (headcount plans, configuration) | Configuration changes awaiting approval |
| **Primary AI context** | Explain my leave balance; my next steps | Summarise changes in my team; what is pending | Lifecycle actions needing attention | What changed in my workforce | Which configurations changed recently |
| **Primary analytics** | Own balances and journey | Team pulse | People operations counts | Workforce pulse, movement, headcount | Governance counts |
| **Navigation depth** | Shallow: Home, My work, People, Services | Medium: + team, approvals | Deep: + organisation, workflows, insights | Shallow: insights first | Deep: admin first |
| **Default Home** | For you | Your team | People operations (Payroll for payroll leads) | Company (workforce pulse) | Administration |
| **Default landing context** | Personal brief | Decisions and team | Operational exceptions | Workforce story | Governance attention |

**Multi-role resolution.** The lens set stays permission- and relationship-derived (unchanged). For a person with several lenses, Home opens on the most specific responsibility:

**administration > HR operations > payroll > executive > manager > employee**

- Kavya (all lenses) → Administration;
- Neha → HR;
- Arjun → Payroll;
- Meera → Executive;
- Amit → Manager.

A stored "Home opens as" choice always wins. The other experiences stay one chip away, with HR admin and Platform merged into one "Administration" chip. Personal items never disappear: every non-employee view keeps a compact "For you" strip.

## 6. UX.16 implementation plan

Security first, then shared signals, then each surface:

1. **G11 security fix.** Gate every Home governance figure by the screen it summarises; platform-level figures go to platform administrators only. Add a regression test.
2. **Experience model.** `RoleLens` gains the five-experience mapping (HR admin and system admin form one Administration view), the new priority, and one label set used by Home chips and Preferences. `lenses()` and `has()` are unchanged, so no authorisation changes.
3. **`RoleSignals` read model** (new, in `app/Domain/Experience/Services`). It provides personal, team, operations, workforce and governance signals. Each is a count with a reason, a severity and a link; each comes from existing domain queries under the viewer's permissions, scopes and relationships. Home and My Work both use it.
4. **Role-led Home** for the five experiences (§3). Existing sections and the strings the UX shell tests check are kept.
5. **Role-aware My Work:** the lead stream per experience, then the shared streams.
6. **Navigation:** section order and landing by primary experience; nothing hidden.
7. **Command palette and search:** role-ranked groups, team-first people (ordered by name), new role verbs gated by permission or `canAccess()`, synonyms.
8. **Employee 360:** a viewer emphasis line and a context panel (self, manager, HR, executive, admin). The admin identity and access context needs `user.view`.
9. **AI:** role-aware Home intelligence statements (sourced, marked Generated, recorded through `AiGateway::recordContext`). The Assistant page defaults by lens, and suggested questions are lens-first. No new assistant, no autonomy.
10. **Notifications:** "Needs you" first, ranked by role relevance and severity.
11. **People directory:** role-aware intro and default grouping.
12. **Showcase data (synthetic, through domain services):** Priya's probation review in 12 days and a document expiring in 18 days; Rohan's probation due; a document awaiting verification; a configuration change awaiting approval. Every reason says "UX showcase seed (synthetic)".
13. **Tests:** role journeys, the multi-role resolution, signals under scope, the security regression list in §16.32, and zero-training measurements.
14. **Validation and documents:** role screenshots light and dark, targeted axe, targeted browser runs, query counts, the refinement report, and the UX.17/18/19 handoffs.

**Out of scope (handoffs):** mobile role Home and navigation (UX.17); full axe, performance and visual-regression gates (UX.18); final sign-off (UX.19); the employee self-360 permission decision (owner, UX.19).
