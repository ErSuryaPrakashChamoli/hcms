# UX.15 Experience Elevation: research and audit

This document records what UX.15 found **before** implementation: what the repository actually contains, how the UX.1–UX.14 experience compares with its own specification, what the market does, and where PeopleOS still reads as a traditional HRMS or as Filament. It ends with the screen audit matrix and the implementation plan that the rest of UX.15 follows.

**Baseline.** Branch `feature/oct_1_phase_1`, commit `53522ee` ("docs(ux): UX.14 final UX audit and transformation report"). Working tree clean. The branch is 70 commits ahead of `origin/feature/oct_1_phase_1`; nothing has been pushed, merged or deployed. Last full suite at this tree: 987 tests, 927 passed, 60 MySQL-only skipped, 0 failed. `vendor/bin/pint --test`: passed.

**Reference package.** The brief names "PeopleOS Experience Transformation.zip". No such file exists on this machine (the whole filesystem and `~/Downloads` were searched). The same material is in the repository and was used instead:
- `docs/ux/PeopleOS-UX-Transformation-Report.md` (the UX.14 report, 31 sections);
- `docs/ux/PeopleOS-Design-System.md` and `docs/ux/PeopleOS-UX-Research.md`;
- `docs/ux/visual-regression/` (20 reference screenshots);
- the private report page published from that report (same content, same screenshots).

**Method.**
1. Repository discovery (code, routes, panel, resources, Livewire, Blade, CSS, JS, policies, tenancy, domain services).
2. 36 "before" screenshots of every screen the brief lists for visual validation, in light and dark, desktop and phone, taken from the running baseline against the fictional showcase database (`docs/ux/ux15/before/`).
3. Measurements of the rendered pages (boxed cards, distinct font sizes, primary-colour use, server response).
4. Market research on thirteen products (section 4).
5. A screen-by-screen challenge against the brief's traditional-HRMS tests (sections 5 and 8).

---

## 1. Repository discovery

### 1.1 Platform and shell

| Area | What exists |
|---|---|
| Stack | Laravel 13, PHP 8.5, Filament 5.8, Livewire 4, Alpine, Tailwind v4 (Filament Vite theme), Pest on SQLite (MySQL suites opt-in) |
| Panel | One panel, `admin` at `/admin`, SPA mode with prefetching, global search off, `unsavedChangesAlerts()`. `authMiddleware([Authenticate, ResolveTenant, EnforceSecurityPolicy], isPersistent: true)`, so Livewire update requests bind the tenant and enforce the tenant security policy too |
| Routes | `routes/web.php` (downloads behind `auth` + `ResolveTenant`, SSO, exit-tenant), `routes/api.php` (`/api/v1/*` behind API-key scopes, SCIM), health routes in `bootstrap/app.php` |
| Navigation | `ExperienceNavigation` builds nine sections (Home, My work, People, Organisation, Insights, Workflows, Services, Communication, Admin) from `ModuleCatalogue`, which checks each destination's own `canAccess()`. The 32 Filament navigation groups still exist underneath |
| Shell render hooks | Head end, body start, command trigger (global-search slot), "New" quick launch, pinned and recent people in the rail, assistant card in the rail footer, the module space bar at page start, and the command, drawer and assistant hosts plus mobile bottom navigation at body end |
| Experience read models | `app/Domain/Experience/Services`: ApprovalCenter, ApprovalDecisions, ChangeFeed, CommandSearch, IntentSearch, Employee360 (per-domain summaries), ExperienceNavigation, ExperiencePreferences, ExperienceTasks, HomeBlocks, HomeComposer, JourneyMap, ModuleCatalogue, NeedsAttention, PeopleVisibility, QuickActions, RoleLens, UxMetrics, WorkInbox |
| Livewire | `CommandCenter`, `DrawerHost`, `ChangeFeedPanel`, `AiAssistant` |
| Components | 15 Blade components in `resources/views/components/pos` (avatar, status, empty, tile-icon, ring, bars, columns, donut, sparkline, hero-art, before-after, approval-card, journey, stepper, ai-response) |
| Theme | `resources/css/filament/admin/theme.css` (1,501 lines): tokens, light and dark sets, density, motion, Filament overrides, component layer |
| Script | `resources/js/peopleos.js` (198 lines): command palette, list keyboard navigation, drawer, pan-zoom, shortcuts, reduced motion |

### 1.2 Which screens are custom and which are still standard Filament

- **Custom PeopleOS surfaces (10):** Home, My work, Approvals, People, Organisation map, Workforce Command Centre, Notifications, Admin Centre, Preferences, and the Employee 360 header, journey and overview.
- **Standard Filament (the large majority):** 147 resources.

| Page pattern | Resources |
|---|---|
| Single modal-CRUD "Manage" page | 58 |
| List + Create + Edit | 43 |
| List + View | 22 |
| List only | 18 |
| List + Create + Edit + View | 5 |

- **Other Filament pages:** 53 Filament pages exist; about 40 of them (control rooms, analytics pages, portals such as My HR, My day, My career, Team pages) are Filament pages with section and table layouts.
- **Wizard:** only "Hire employee" is a multi-step wizard. The heaviest forms are the Employee 360 actions (about 30 fields in total), workforce plans and courses (29 each) and goals (25).
- **Tables:** filters in 109 files. Bulk actions in only 2 places. Persisted filters only on the employee list. No saved views. No deferred loading.

### 1.3 How the experience reaches domain services

Every write in the experience layer forwards to the owning domain service:
- `ApprovalDecisions` to `WorkflowEngine`, `Leaves`, `Regularisations`, `CompensationChanges` and `Letters`;
- the Employee 360 actions to `AssignPositionAction`, `ChangeManagerAction`, `LifecycleEngine`, `Onboarding`, `Letters` and `ServiceDesk`;
- quick actions open existing pages or mount existing Filament actions through `?action=`.

Read models never write, apart from personal display preferences and anonymous daily counters.

### 1.4 Security model as the UI sees it

| Layer | Mechanism |
|---|---|
| Auth and tenant | `ResolveTenant` binds the tenant. `BelongsToTenant` adds a fail-closed `TenantScope` (`1 = 0` when no tenant is bound). Cross-tenant writes throw |
| Role and permission | Permission keys declared in `config/peopleos.php`. `User::hasPermission()`. `Gate::before` resolves dotted keys and grants platform administrators. Policies are registered explicitly; `PermissionPolicy` maps abilities to keys and adds scope |
| Organisation scope | `AccessScopes` over `user_access_scopes` (no rows means tenant-wide), applied through the `AccessScope` global scope and the `ScopedByEmployee` / `ScopedByOrganisation` traits |
| Relationship scope | `AccessScopes::employeeKeys()`: a manager reaches today's reports through any reporting type |
| Field security | `employee.sensitive.view/update`. Bank details masked with an audited, reasoned reveal. Statutory view audited. Encrypted casts for identifiers and account numbers. `TimelineCategories::SENSITIVE` (compensation, bank, statutory, personal). `CompensationAccess::level()` (full / team / self) |
| AI | `AiGateway`: feature flag plus permission per assistant, rate limit, `AiDataPolicy` boundary, `ai_interactions` log, deterministic by default |

**UX consequence.** The UI must keep asking these services. Hiding a button is never the control; the URL and the action are refused on the server. The existing persona matrix test (`UxPersonasTest`) and `UxSecurityTest` prove this for the redesigned surfaces and must stay green.

### 1.5 Domain facts that limit or enable the redesign

| Fact | Consequence for UX.15 |
|---|---|
| `reporting_relationships` holds line, functional, dotted, HRBP, mentor, buddy, project and secondary relationships, effective-dated; only `line` is primary | The org map can show dotted-line, functional and matrix (project, secondary) relationships from real data. Today it shows only the primary line |
| Moves are `employee_positions.change_type` (hire, transfer, promotion, demotion, reassignment, correction, rehire); exits are `exit_cases`; critical positions are `critical_positions` (designation + organisation node) with `CriticalPositions::upcomingIncumbentExit()` | A real "workforce movement" story is possible: internal movements, promotions, exits, critical positions affected |
| Rehire reuses the same `Employee` and `Person` (`RehireEmployeeAction`) | "One person, one lifetime record" is true in the data and can be shown honestly |
| No confirmation-recommendation model; confirmation is a lifecycle transition. Onboarding document tasks carry `is_mandatory` and status | Intelligence may say "probation ends in N days and confirmation is not recorded" and "N required onboarding documents are open". It must not claim a "recommendation not submitted" |
| Workflow tasks support approve, reject or complete with a note. No clarification or send-back. Compensation changes can be returned to the proposer | "Request clarification" can be offered only where a domain supports it (compensation). It will not be simulated elsewhere |
| `AiGateway` assistants are deterministic by default; an external model only rephrases facts when the tenant enables it | Contextual intelligence must be rule-based over permitted data, labelled as generated, and gated by the same assistant permissions and feature flags |
| `UxShowcaseSeeder` refuses any database not named `*_showcase`; the scale helpers bulk-insert populations for tests | Realistic-data validation can use a separate disposable showcase database |

---

## 2. Reference → specification → implementation → gap → recommendation

The reference is the UX.14 report and its 20 references. The specification is the Experience Transformation brief plus the UX.15 brief. The implementation is what the baseline renders.

| Experience | Specification | Actual implementation at `53522ee` | Gap | Recommendation |
|---|---|---|---|---|
| Home | A living personal workspace answering what matters, what changed, what needs me, what I can do, what happens next | Illustrated hero with lens chips; KPI tile strip; three "next action" cards; Your day; Team pulse or Journey; role panels; Key insights charts; Company pulse; Start something tiles; right rail with a chat card ("Hi Priya, how can I help today?"), Today and What changed; organisation banner. 13–17 boxed cards per role, 13–15 distinct font sizes | It is the "hero → KPI cards → cards → chart" dashboard the brief rejects. The chat card is the generic chatbot the brief forbids. "What changed" is a small rail widget, not since-last-visit | Rebuild as an editorial workspace: a one-sentence brief of the day from real counts, then Decisions waiting, Your day timeline, What changed since your last visit, Your people strip, Your momentum, Suggested actions. Remove the KPI strip, hero illustration and chat card. Contextual intelligence instead of a chatbot |
| My work | "What should I do right now?" | Tabs (Needs attention, Today, Upcoming, Waiting on others, Completed) over one list | A tabbed list; the user must pick a tab to find out. No "now" focus; requests, follow-ups and recent changes absent | One focus workspace: the next thing to do, then Decisions, Tasks, Your requests, Follow-ups, Waiting on others, Recently changed, as streams with counts and filters |
| Approval Center | A decision workspace ("7 decisions need you") with person, request, context, impact, effective date, actions | Grouped card list (Urgent, Today, Upcoming, Completed), decisions in each card, keyboard A/R | Good bones, but every card shows everything at once; no master–detail; the person's context (balance, team calendar, history) is not beside the decision. The showcase had no pending items, so the screen looked empty | Master–detail decision workspace: a queue on the left, the selected decision with full context on the right; "N decisions need you" headline; clarification only where a domain supports it |
| Employee 360 | A person workspace: Now, Journey, Work, Growth, Rewards, Documents; What changed; What's next; Relationships; contextual actions; one lifetime record | Custom header with grouped actions, then Filament sections (People snapshot, Journey, 360 overview cards), then eight relation-group tabs of tables. The module space bar above the header lists internal module names (Alumni, Background checks, Onboarding templates, Exit cases…) | "Profile header → sections → tabs → tables". No Now, What's next or Relationships. The lifetime-record idea is invisible. Module names leak into the person's page | Person workspace with section navigation (Now, Journey, Work, Growth, Rewards, Documents), a lifetime ribbon ("Joined 2023 · 3 years 6 months · 1 employment record"), What changed, What's next, Relationships (all reporting types), contextual intelligence. Records (the system-of-record tables) stay, demoted below the workspace. No module bar on a person |
| People directory | People feel like people; search, filter, group, cards, list, table, recently changed; peek → drawer → 360 | Cards or a compact list, search and four filters, a preview drawer | No grouping, no table view, no recently-changed view, no peek; card clicks open the drawer directly | Add group-by (team, department, location, manager), a table view, a "Recently changed" view, and the person peek on hover or focus everywhere a person is named |
| Organisation map | Zoom, pan, expand, collapse, focus, search, employee and team peek, manager, dotted-line, functional and matrix relationships | Tree with zoom, pan, expand, focus, search, department view | Only the primary line; no dotted, functional or matrix relationships; no peek; team view minimal | Relationship overlays from `reporting_relationships`, highlighted on hover and focus; person peek; team peek in the department view |
| Workforce Command Center | A workforce story with progressive drill-down | KPI tile row (6), What changed block, decisions block, risks block, two charts. 13 boxed cards | A wall of tiles and charts; no narrative; no movement story; no drill-down | "Workforce pulse": a headline sentence from real data, then movement (internal moves, promotions, exits, critical positions affected), attendance, performance, capability, planning, exceptions as narrative rows, each opening a drill-down drawer of the people behind the number (permission-aware) |
| Contextual AI | Not a chatbot; insights from the current context, labelled, auditable | A chat panel and a Home chat card that greets the user; "Summarise" on the 360 runs the HR copilot | Generic greeting pattern; no proactive, contextual insight | "PeopleOS Intelligence" cards: deterministic facts from permitted data in the current context (probation ending, onboarding documents open, pending decisions, upcoming leave), each with its source and a suggested screen. Asking a question still goes through `AiGateway` |
| What changed | First-class, since your last visit, business language | A 30-day feed of timeline entries and announcements | No "since your last visit"; no grouped summary; items do not open in context | Since-your-last-visit summary ("3 things changed: 2 transfers, 1 pay change…"), grouped by business event, each opening a contextual drawer. Timeline entries only; never raw audit records |
| Before → After | Manager, organisation, position, compensation, employment changes | Leave form; Transfer / promote and Change manager forms; compensation in the approval card | Present but visually inconsistent between form and approval | One PeopleChange component (field, before, after, effective date) used in forms, approvals and drawers |
| Peek → Drawer → Workspace | One consistent three-level model | Drawer and workspace exist; no peek; some person names open the drawer, others link straight to the 360, others are plain text | No peek at all; inconsistent person interactions | A person chip component with peek (hover, focus or long-press), drawer (click), workspace (Open profile), used everywhere a person is named |
| Forms | Context → change → review → confirm | Filament modal forms with live previews on three actions | Complex changes are still "fields → save" | Turn the high-value changes (transfer / promote, change manager) into step flows with a review step |
| Tables | Search, filter, sort, saved views, density, bulk, columns | Filament tables, restyled; density aware | No saved views; tables are the default answer on most pages | Saved views on the operator tables that need them; tables kept as the "records" level, not the first experience |
| States | Loading, skeleton, empty, error, retry, permission denied, stale, partial failure | Empty states and skeletons on new surfaces; error pages | No partial-failure, stale or retry states inside pages | A state component with those variants; Home and workspace blocks fail independently with a retry |
| Mobile | Intentional flows: Today, My work, People, Actions, Notifications, Profile | Bottom bar Home / Work / People / Services / Profile | Home on a phone shows a large hero and the welcome card first; nothing actionable above the fold. "Profile" lights up while viewing someone else's 360 | Bottom bar Home / Work / Actions / People / Services (notifications and profile in the top bar); phone-first Home order (decisions and today first); fix the active state |
| Dark mode | A genuine design system | Separate tokens, but violet-tinted canvas, surfaces, hero and chips | Purple everywhere | Neutral graphite surfaces; indigo only for identity, primary action, selection and focus |
| Navigation | A workspace, not a database | Nine sections; context rail of areas; a space bar of module names on every module page and on the 360; an assistant card in the rail footer | Module architecture shows through the space bar and Admin Centre | Keep the nine sections; demote module names; no space bar on workspaces; "Setup" modules grouped apart from everyday ones; a compact assistant entry |
| Services | Plain-language help | My HR with 11 tabs over tables | A tab strip of modules | A service front door: search or ask, common requests, your open requests as a timeline, answers from knowledge |
| Admin | "Here is the policy I need to manage" | Admin Centre: 37 boxes of module links | A list of what tables exist | A governance workspace: find a setting in plain language (Configuration finder), pending configuration changes, health, then "What do you want to manage?" categories in plain words |

## 3. Issues found

**Missing experiences.**
- Person peek.
- Since-last-visit change summary.
- Relationships on the 360 and on the org map (dotted, functional, matrix).
- Workforce movement story.
- Contextual intelligence.
- Saved views.
- Partial-failure and stale states.
- A service front door.
- A plain-language admin entry.

**Weak experiences.**
- Home (a card wall).
- My work (a tabbed list).
- Workforce Command Center (tiles and charts).
- Services (11 tabs).
- Admin Centre (module lists).
- The Employee 360 below the header (tabs of tables).

**Inconsistent components.**
- Person names behave three ways (drawer, link, plain text).
- KPI tiles, pulse cells, metric cells and insight cells are four variants of one idea.
- Status chips use the primary colour for neutral states (for example, "Probation").

**Overly traditional patterns.**
- KPI rows on Home and the Workforce Command Center.
- Profile header → tabs → tables on the 360.
- Module navigation (space bar).
- Tab strips (My HR, My work).

**Excessive card usage.** Measured boxed cards per screen:

| Screen | Boxed cards |
|---|---|
| Home, employee | 16 |
| Home, manager | 17 |
| Home, HR | 13 |
| Home, executive | 13 |
| Workforce Command Center | 13 |
| Employee 360 | 7 |
| Admin Centre | 37 |

**Navigation problems.**
- Module names are exposed by the space bar, including on a person's page.
- The rail-footer assistant card occupies the rail permanently.
- Phone "Profile" active state is wrong.

**Interaction problems.**
- No peek.
- The drawer opens from some places only.
- Approval cards show everything at once.
- Clicking a change in "What changed" leaves the page instead of opening context.

**Responsive problems.**
- The phone Home leads with decoration.
- The 360 module bar scrolls horizontally on phones.
- The 360 action buttons wrap into two rows.

**Accessibility gaps.**
- The baseline audit was clean (axe-core, 0 violations), but small text works against legibility: 9.5 px, 10.5 px, 11 px and 11.5 px are all in use.
- There is no visible keyboard route to a person preview other than opening the drawer.

**Visual inconsistencies.**

| Property | Distinct values at baseline |
|---|---|
| Font sizes declared in the theme | 20 (9.5 to 48 px) |
| Font sizes rendered on Home, per role | 13–15 |
| Corner radii | 10 (2 to 20 px) |
| Hard-coded padding declarations | 102 |
| Hex literals outside the token blocks | 20+ |
| Primary-colour references in the theme | 56 |
| Gold accent references in the theme | 6 |

**Duplicated interaction patterns.**
- Two assistant entry points (rail-footer card and Home chat card) plus the 360 "Summarise".
- Three places that list "things to do" with different row designs (Home next cards, My work rows, Your day rows).

**Areas that still feel like Filament.**
- The 360 records tabs.
- All module list, form and detail pages.
- Control rooms and analytics pages.
- My HR tabs.
- Section headers and infolist grids.

**Areas that still feel like a conventional HCM.**
- Home KPI strip.
- Workforce Command Center tiles.
- The 360 tab strip.
- My HR tabs.
- Admin module lists.

---

## 4. Market and product research

### 4.1 Products reviewed and evidence

Research date 4 October 2026. The full notes, with a source URL for every observation and its evidence level, are in [`docs/ux/ux15/UX-15-Research-Notes.md`](ux15/UX-15-Research-Notes.md).
- **FETCHED:** the page itself was read.
- **EXCERPT:** only a search summary was seen, so treat it as lower confidence.

| Product | Why it was studied | Evidence quality |
|---|---|---|
| Linear | Speed, keyboard-first work, peek, views, calm UI | Mostly fetched |
| Notion | Side peek, record layouts, views, home and My Tasks | Mostly fetched |
| Apple (Human Interface Guidelines) | Sidebars, split views, sheets, motion, dark mode, accessibility, writing | Fetched (Apple's own data pages) |
| Stripe Dashboard | Omni-search, context drawer, empty, loading and feedback rules | Fetched |
| Ramp | Approvals inbox, readiness gating, AI recommendation with cited policy | Mostly fetched |
| Rippling | Single employee record, effective dating, staged AI changes | **Excerpt only** (site blocked automated reading) |
| HiBob | Social home, historical tables, change-request flows | Mixed |
| Workday + Canvas | The incumbent's patterns and its own design-system rules | Fetched (Canvas, training PDFs) |
| Personio | Effective-dated edits, propose / edit permissions, unified inbox | **Mostly excerpt** |
| Deel | Approvals in place, proactive compliance, mobile | Mixed |
| Lattice | Manager home, AI with sources, read-only impersonation | Fetched |
| Culture Amp | Analytics storytelling, explorer panel, confidentiality by design | Fetched |
| BambooHR | A friendly incumbent and its limits | Mixed (help centre behind login) |

Rippling and Personio observations are treated as hypotheses, not facts, in the decisions below.

### 4.2 Observed → PeopleOS decision → rejected → reason

Each topic separates what was **observed** (with product names that point to the notes), what PeopleOS **adopts**, what it **rejects**, and **why**.

**Navigation and information architecture**

| | |
|---|---|
| **Observed** | The left sidebar is now universal. BambooHR (2024/25) and Workday (2025R2) both moved to one. Apple: at most two levels in a sidebar, deeper trees go to a split view. Recents + pins form an automatic second tier (Stripe "Shortcuts", Notion). Linear dims its chrome (2026) so content leads. Lattice (2026) moved "My team" into each area instead of a separate manager dashboard. Suite breadth is the top complaint about Rippling, Workday and Personio (reviews, excerpt) |
| **PeopleOS decision** | Keep the nine outcome sections with a maximum of two levels (section → area). Keep pins and recents as the second tier. Dim the rail so the workspace leads. Role is a lens on the same sections (as Lattice), not a separate place. Workspaces carry their own header; module names appear only at the records level and in "Setup" |
| **Rejected** | A top bar of modules. A separate manager or HR "portal". User-reorderable sidebars in this phase |
| **Reason** | Outcome sections answer "what can I accomplish?". Separate portals split the record. Reordering adds state to support before the default structure has been proven |

**Command system and search**

| | |
|---|---|
| **Observed** | Cmd/Ctrl+K in Linear (runs actions, selection-aware) and Notion. `?` shortcut sheets (Linear, Stripe). Recents shown before typing (Notion, Apple). Stripe's typed omni-search (paste an ID, field filters, results grouped by type, state in the URL). Lattice's "Search or ask" bar. **No HCM product documents a palette that executes actions**; HCM vendors went to chatbots instead |
| **PeopleOS decision** | The command center stays the first-class entry point and grows. Explicit verb commands are added: Apply leave, Create employee, Start transfer, Change manager, Approve request, Open Workforce pulse. Each appears only when the server authorises it. An Intelligence group ("Show recent organisation changes", "Who joined this month") answers from fixed, permission-checked queries. Paste an employee ID to jump straight to the person. Recents before typing |
| **Rejected** | Free-text natural-language-to-SQL. A chat box as the primary search |
| **Reason** | The brief forbids an unrestricted NL-to-SQL engine. A palette that acts is the clearest differentiator found in the market; a chatbot as search hides navigation problems (anti-pattern A13) |

**Peek, drawer and workspace**

| | |
|---|---|
| **Observed** | An escalation ladder everywhere: peek (Linear Space, Quick Look style) → side panel beside the content (Notion side peek, Stripe ContextView, Culture Amp explorer) → focus view only for start-to-finish tasks (Stripe FocusView, Apple sheets) → full page. Next / previous without closing (Linear, Notion). Culture Amp: the panel "adds nothing new" and only re-surfaces data available elsewhere. **HCM products rarely open a person as a peek from a list** |
| **PeopleOS decision** | Standardise Peek → Drawer → Workspace for people everywhere. Peek on hover or focus (with a delay), and on long-press on touch. Drawer on click. Employee 360 as the workspace. The drawer and peek only re-surface data the viewer can already open (PeopleVisibility), so they can never become a second source of truth. Multi-step changes use a focused step flow |
| **Rejected** | Floating multi-panel layouts. More than one drawer open at once (the drawer stacks with Back instead) |
| **Reason** | One ladder learned once works everywhere (brief Phase 20 and 30). Multiple panels shrink content (Canvas caution) |

**Approvals and decision work**

| | |
|---|---|
| **Observed** | One inbox for every request type with a count (Ramp, Personio, BambooHR, Workday). Ramp's readiness gating (only complete items reach approvers), grouping, non-binary rejection ("Request changes" vs "Request repay") and an AI recommendation that cites the policy text and version. Lattice reopens a submission through the chain "without restarting". Approval context is thin in HCM (balances before approving time off; coverage rarely shown). Workday Inbox items that do not finish where they start (anti-pattern A3) |
| **PeopleOS decision** | A decision workspace: a queue on the left, the selected decision with its full context on the right. Context includes the person, request, reason, impact, effective date, Before → After, the requester's balance and who else is away in the same period (from approved leave the viewer may see), and the record's recent history. A headline of "N decisions need you". Decisions finish in place. "Request clarification" only where a domain supports it (compensation: return to proposer) |
| **Rejected** | Bulk approve in this phase. AI recommendations to approve or reject. Simulated clarification for domains without a send-back state |
| **Reason** | Bulk approval needs per-item checks the domains do not yet offer as a batch. The brief forbids autonomous employment decisions, and a recommendation to approve is one step from that. Faking a clarification state would bypass workflow rules |

**Employee profile**

| | |
|---|---|
| **Observed** | Header + tabs + sections is the convention (Notion layouts, Rippling configurable profiles *(excerpt)*). Personio's "About" tab as a "how to work with this person" card (relationships, local time, location). Effective-dated history lives in **separate tables** (BambooHR, HiBob, Personio) rather than in the profile's story. Profiles are organised by data category, not by task |
| **PeopleOS decision** | The Employee 360 is a person workspace organised by meaning: Now, Journey, Work, Growth, Rewards, Documents. History is woven into the story ("What changed", the lifetime journey). What's next lists upcoming events and deadlines. Relationships show the manager, team and every reporting type (dotted, functional, project, mentor, buddy, HRBP). Actions are contextual. The system-of-record tables remain under Records |
| **Rejected** | Admin-configurable profile layouts in this phase. Tabs as the primary structure |
| **Reason** | A configurable layout is a configuration product of its own. Tabs-first is exactly the "profile header → tabs → tables" pattern the brief rejects |

**Analytics and storytelling**

| | |
|---|---|
| **Observed** | Widget homes with click-to-drill (BambooHR, Stripe, Notion 3.4). Linear's narrative updates with an automatic diff shown only when something moved. Culture Amp: metric → explorer panel → owner → action, with small-group fallbacks and confidentiality minimums. Outside Culture Amp, HCM analytics stop at charts |
| **PeopleOS decision** | The Workforce Command Center becomes "Workforce pulse". A headline sentence states what changed, from real figures. Movement, attendance, performance, capability, planning and exceptions are narrative rows. Each figure opens a drill-down drawer of the people or events behind it, permission-aware and with the existing small-group suppression. Rows appear only when there is something to say |
| **Rejected** | A customisable widget canvas. Generated prose that is not traceable to figures |
| **Reason** | Widget canvases recreate the card wall. Every sentence must be reproducible from the figures shown |

**Tables**

| | |
|---|---|
| **Observed** | Filters as chips with clear-all; display options separate from filters; personal vs shared view state; density (Linear); "filter before pagination" (Stripe). Workday admits its own grid falls short. **No HCM product has saved views on the people list** |
| **PeopleOS decision** | Tables are the records level, not the first experience. The People directory gets group-by and a table view. Saved personal views (named filter sets in the URL) on the operator tables that need them most. Density follows the personal setting |
| **Rejected** | Shared or workspace-wide saved views in this phase. Rebuilding Filament's table engine |
| **Reason** | Shared views need their own permission model. Filament's table already enforces scopes and policies, so replacing it would risk the security model for a presentation gain |

**Forms, change flows and effective dating**

| | |
|---|---|
| **Observed** | The effective date is a first-class question (Personio, Lattice, HiBob, BambooHR history rows). Review before commit (HiBob summary of proposed changes; Rippling staged changes *(excerpt)*; Stripe "you only need to confirm"). A sticky task footer (Canvas action bar). Canvas: don't disable submit, explain unavailable fields, buttons say what happens next. **A visual before/after on a date for job changes is not documented by any HCM product** |
| **PeopleOS decision** | High-value changes (transfer / promote, change manager) become step flows: context → change → review (Before → After with the effective date) → confirm. One PeopleChange component everywhere a change is previewed: forms, approvals, drawers. Button labels state the result ("Confirm transfer"). Unavailable options are explained, not just disabled |
| **Rejected** | Temporary changes with automatic revert. Editing historical effective dates |
| **Reason** | Both change domain behaviour (the brief keeps domains as they are). Before → After on a date is the differentiator and needs no domain change |

**Motion**

| | |
|---|---|
| **Observed** | Purposeful, brief, interruptible, optional (Apple, Canvas). Concrete easing tokens (Canvas "Quick" `cubic-bezier(0.2,0,0.2,1)`). A loader delay of 200–300 ms against flashing (Stripe). Fades instead of movement under reduced motion (Apple) |
| **PeopleOS decision** | Four duration tokens (160, 220, 280, 360 ms) and two easings (standard, emphasised). Motion explains origin, destination and change: peek and drawer from their trigger, list items leaving after a decision, journey progression, the command palette. A loader delay. Reduced motion becomes fades only |
| **Rejected** | Decorative loops. Motion on very frequent interactions |
| **Reason** | Motion should carry meaning; on frequent actions it slows people down |

**Typography and density**

| | |
|---|---|
| **Observed** | One workhorse sans plus a display face (Linear Inter / Inter Display; BambooHR's serif for warmth). Apple: avoid light weights, respect minimum sizes, few typefaces. Linear generates themes in LCH so lightness stays perceptually even. BambooHR's extra white space was "divisive among data-oriented users". Only Linear offers a density setting |
| **PeopleOS decision** | Instrument Sans for the interface, Instrument Serif only for display and page titles, an 8-step scale with nothing below 12 px, tabular figures. Comfortable and compact density, chosen per person |
| **Rejected** | More typefaces. Sub-12 px meta text |
| **Reason** | The audit found 20 sizes down to 9.5 px; legibility comes before fitting more in |

**Mobile**

| | |
|---|---|
| **Observed** | Bottom navigation plus a global create action (Linear, Notion, Stripe). Mobile = monitor + high-frequency actions (approve, request time off, clock in, payslips). A bottom action bar on detail screens (Stripe). Apple: at most five tabs, never hide tabs, explain empty states |
| **PeopleOS decision** | Bottom bar: Home (today), Work, Actions (the authorised quick actions, centred), People, Services. Notifications on the top-bar bell; profile under the top-bar avatar. Phone-first ordering on Home (decisions and today first). Approvals in one tap from Work. Drawers become bottom sheets |
| **Rejected** | A separate mobile app or feature subset |
| **Reason** | One responsive product keeps one security model |

**Dark mode**

| | |
|---|---|
| **Observed** | Respect the OS setting, with an override (Notion; Apple prefers system-only). Apple: base vs elevated backgrounds, test with increased contrast. Web dark mode is absent or recent across HR products |
| **PeopleOS decision** | A separately designed graphite set with elevated surface steps instead of shadows. The indigo, gold and status colours are re-tuned for dark. System default plus the existing toggle |
| **Rejected** | An inverted light palette. Violet-tinted surfaces |
| **Reason** | The audit found "purple everywhere" in dark; meaning must come from colour, not ambience |

**Accessibility**

| | |
|---|---|
| **Observed** | Contrast 4.5:1 for text and 3:1 for non-text; never colour alone; keyboard operability; polite live regions for toasts (Canvas). Canvas's step-difference contrast rule. Lattice's candid "partially compliant with WCAG 2.2" |
| **PeopleOS decision** | Keep axe-core at zero violations across roles and themes. Peek reachable by keyboard focus. Every state has text, not just colour. Targets at least 24 px (48 px in the phone bar). An honest statement of what was tested |
| **Rejected** | Claiming full conformance |
| **Reason** | Automated audits do not prove conformance; claims must match evidence |

**Empty, loading and error states**

| | |
|---|---|
| **Observed** | Show something immediately; skeleton when the layout is known (Apple, Canvas). State precedence loading → error with retry → empty → content. Distinguish first-use, filtered-to-zero and all-removed empties (Stripe). Errors near the problem with the fix, no blame (Apple, Canvas). Permission-caused emptiness explained (Canvas) |
| **PeopleOS decision** | One state component with variants: loading (skeleton), empty (first use / filtered / caught up), error with retry, permission denied (explains why), stale ("updated N min ago · refresh") and partial failure (one block fails, the rest of the page stays) |
| **Rejected** | Full-page errors for a single failed block. "Oops" copy |
| **Reason** | A workspace composed from many domains must degrade by block |

**Feedback, confirmation and undo**

| | |
|---|---|
| **Observed** | Toast for transient success, banner for persistent issues, dialog only for decisions (Stripe, Canvas). Avoid confirmations for common reversible actions (Apple; NN/g). **No HCM product documents undo for HR actions**; "pending, cancellable" is the HCM equivalent |
| **PeopleOS decision** | Toasts state the result in a few words; banners for persistent issues. Decisions confirm in place with a short note, not a modal. Cancelling one's own pending request stays the "undo", through the existing domain cancellation paths |
| **Rejected** | A generic undo for decisions |
| **Reason** | An approval notifies people and starts downstream work; reversing it must go through the domain's own cancellation rules |

**AI**

| | |
|---|---|
| **Observed** | Assistants in a right drawer (Stripe, Lattice). Permission-scoped answers (Lattice, Personio, Deel). Act only with confirmation (Stripe, Lattice). Citations: Lattice "Source", Ramp cites the policy and its version. Few products show what data the AI used. Using assistants as a substitute for navigation is anti-pattern A13 |
| **PeopleOS decision** | "PeopleOS Intelligence": proactive, contextual statements computed from data the viewer may already see in that context. Each insight carries its source, a "generated" label and a suggested screen. Gated by the same assistant permissions and feature flags as `AiGateway`. Questions still go through `AiGateway` and its `ai_interactions` log. No greeting chatbot |
| **Rejected** | Autonomous actions. Recommendations on employment, pay or promotion. A generic "How can I help?" opener |
| **Reason** | The brief's AI rules, and the market's best practice of citations and confirmation |

### 4.3 What PeopleOS takes from the market, in one paragraph

The market converges on a sidebar, a home of "what needs me", an inbox, side panels and assistants. The gaps in HCM specifically:
- a command palette that **acts on people**;
- a **peek ladder** for people;
- **decisions with context beside them**;
- **change previewed on its effective date**;
- **analytics that tell a story** with drill-down and privacy thresholds;
- **contextual intelligence with sources**.

These gaps are PeopleOS's interaction language. None of them requires copying another product's screens, and all of them can be built on PeopleOS's existing single record, effective-dated history and security model.

---

## 5. Design thesis and traditional-HRMS elimination

**Thesis.** Every UX.15 surface is built from **person + work + context + action + insight + automation**, and answers five questions in this order:

| Question | Where it is answered |
|---|---|
| What matters? | The day's brief at the top of Home and of every workspace |
| What changed? | Since your last visit, in business language |
| What needs me? | Decisions and tasks, with the reason each needs you |
| What can I do? | Contextual actions the server has already authorised |
| What happens next? | Upcoming events, deadlines and the next step of each flow |

Modules become progressively discoverable: through the command center, the Admin Centre's plain-language finder and the records level of a workspace. They stop being the first thing a person sees.

**Patterns found and what replaces them.**

| Traditional pattern | Where it is at baseline | Replacement |
|---|---|---|
| Sidebar → module → submodule → resource → CRUD table | Space bar on module pages and on the 360; Admin Centre module lists | Workspaces first; modules under "Records" or "Setup"; plain-language admin finder |
| Page title → KPI card ×N → chart → table | Home (all roles); Workforce Command Center | Brief sentence → streams and timelines → narrative rows with drill-down |
| Profile header → tabs → forms → tables | Employee 360 | Person workspace (Now, Journey, Work, Growth, Rewards, Documents) with records demoted |
| Card, card, card, card, chart, table | Home; Admin Centre | Lists, timelines and strips, with cards only where an object is independent (a decision, a person) |

## 6. Design-system audit and direction

**Audit (baseline `theme.css`).**
- Tokens exist for colour, surfaces, status, tiles, rail, shadow, radius (5 tokens), spacing (4 px grid, 9 tokens), density (3 tokens) and motion (4 durations, 2 easings).
- They are bypassed widely: 20 literal font sizes, 10 literal radii, 102 literal padding declarations and 20+ hex literals after the token blocks.
- Category "jewel" tiles colour icons by module (rose, emerald, sky, amber, violet, indigo, teal, gold), so colour decorates rather than means.
- Indigo is used for neutral states (status chips, lens chips, active rail pill, hero, links, orb), giving "purple everywhere"; dark mode makes it worse with violet-tinted canvas and surfaces.

**Direction for UX.15.4 (governed tokens; everything else derives from them).**

| Token family | Rule |
|---|---|
| Colour | Neutral-led surfaces (cool grey in light, graphite in dark). Indigo = PeopleOS identity: primary action, selection, focus, links. Gold = high-value moments (milestones, promotions, recognition, the lifetime record). Green = positive / completed. Amber = attention. Red = critical / destructive. Blue = informational. Neutral = normal information. Module tiles become neutral; colour only when it carries meaning |
| Typography | One scale of 8 steps: 12 caption, 13 meta, 14 body, 16 lead, 18 section title, 22 page title, 28 display, 36 hero display. Nothing below 12 px. Serif (Instrument Serif) only for display and page titles; Instrument Sans for everything else; tabular figures for numbers |
| Spacing | 4 px grid: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64. Page gutter 24 (desktop) / 16 (phone); section gap 32; card padding 20 (comfortable) / 14 (compact) |
| Density | Comfortable (default) and compact (operators); one switch changes row height, padding and gaps |
| Radius | 4 (small: chips, checkboxes), 8 (controls), 12 (cards, panels), 16 (drawers, dialogs), pill |
| Elevation | 0 flat (hairline), 1 raised (hover), 2 floating (peek, menus), 3 overlay (drawer, dialog). Dark uses hairlines and surface steps, not shadow |
| Motion | 160 ms micro, 220 ms small, 280 ms medium, 360 ms large; one standard and one emphasised easing; reduced motion collapses all |
| States | Hover, active, selected, focus, disabled, error, success and warning have their own tokens, identical across components |
| Breakpoints | Phone < 768 (bottom bar), tablet 768–1279 (collapsed rail), desktop ≥ 1280 |

## 7. Navigation review

The nine sections match the brief's structure and stay. What changes:
- **Workspaces have no module bar.** Home, My work, Approvals, People, Org map, Employee 360, Workforce pulse, Services and Admin show their own workspace header instead.
- **Module pages keep a quieter space bar.** Everyday modules come first; configuration modules sit behind "Setup".
- **The rail footer becomes a compact "Ask PeopleOS" entry**, not a permanent card.
- **Phone bottom bar:** Home (today), Work, Actions (the authorised quick actions, in the centre), People and Services. Notifications stay on the top-bar bell and your profile on the top-bar avatar. *(Revised during UX.15.5 from "Today, Work, People, Actions, Inbox": the bell already covers notifications, Services is a frequent employee task, and the labels match the existing mobile-layout test.)*
- **Personal pages stay under the avatar** ("You"), as in UX.14.

## 8. Screen audit matrix

Priority: P0 = signature experience that fails the traditional-HRMS test; P1 = important gap; P2 = polish. Status is updated at the end of UX.15 in the final report.

| Screen | Current experience | Problem | Traditional HRMS pattern | Proposed change | Priority | Implementation status |
|---|---|---|---|---|---|---|
| Home (all roles) | Hero, KPI strip, action cards, panels, charts, chat card | Card wall; generic chatbot; no since-last-visit | Title → KPI cards → cards → chart | Editorial workspace: brief, decisions, day timeline, what changed, people, momentum, suggested actions, intelligence | P0 | Planned (UX.15.6) |
| My work | Tabs over a list | Must choose a tab to learn what to do | Tabbed list | Focus workspace with "do this next" and streams | P0 | Planned (UX.15.7) |
| Approval Center | Grouped cards | Everything at once; no master–detail; context not beside the decision | Approval list | Decision workspace: queue + detail, "N decisions need you" | P0 | Planned (UX.15.8) |
| Employee 360 | Header, sections, eight tabs of tables, module bar | Person reads as records | Profile header → tabs → tables | Person workspace; lifetime ribbon; relationships; what's next; intelligence; records demoted | P0 | Planned (UX.15.9) |
| People directory | Cards or list, filters, drawer | No peek, grouping, table or recently changed | Directory grid | Peek → drawer → 360; group-by; table and recently-changed views | P1 | Planned (UX.15.10) |
| Organisation map | Primary-line tree | No dotted, functional or matrix relationships; no peek | Static hierarchy | Relationship overlays; person and team peek | P1 | Planned (UX.15.11) |
| Workforce Command Center | KPI tiles, blocks, charts | No story; no drill-down | KPI cards → charts | Workforce pulse narrative with drill-down drawers | P0 | Planned (UX.15.12) |
| Contextual AI | Chat panel, greeting card | Generic chatbot | Chatbot widget | Contextual intelligence cards; questions through the gateway | P0 | Planned (UX.15.13) |
| What changed | 30-day feed | Not since-last-visit; no context | Activity log | Since-your-last-visit summary with contextual drawers | P1 | Planned (UX.15.6) |
| Forms (transfer / promote, change manager) | Modal forms with preview | Fields → save | Long modal form | Step flow: context → change → review → confirm | P1 | Planned (UX.15.14) |
| Drawers | Person, change, approval | Not reachable from every person mention | — | One person chip everywhere; peek level added | P1 | Planned (UX.15.14) |
| Services (My HR) | 11 tabs over tables | Module tabs | Tab strip of modules | Service front door | P1 | Planned (UX.15.5) |
| Admin Centre | 37 boxes of module links | "What tables exist?" | Module directory | Governance workspace with plain-language finder | P1 | Planned (UX.15.5) |
| Notifications | Grouped list, snooze | Fine | — | Token alignment only | P2 | Planned (UX.15.4) |
| Preferences | Density, lens, theme | Fine | — | Token alignment only | P2 | Planned (UX.15.4) |
| Command center | Grouped results, row actions | Lacks an "intelligence" group and verbs like "Start transfer" | — | Add intelligence and change verbs (authorised only) | P1 | Planned (UX.15.5) |
| Module list, form and detail pages (~150) | Restyled Filament | Recognisably Filament | CRUD tables | Token alignment, quieter space bar, saved views where needed; full re-architecture deferred | P1 | Planned (UX.15.4, UX.15.14) |
| Mobile (all) | Bottom bar, stacked pages | Decoration first; wrong active state | Shrunk desktop | Phone-first order; bottom bar Home / Work / Actions / People / Services | P1 | Planned (UX.15.16) |
| Dark mode | Violet-tinted set | Purple everywhere | — | Graphite neutrals, indigo by meaning | P1 | Planned (UX.15.17) |
| Error pages | Designed 403/404/419/429/500/503 | Fine | — | Token alignment | P2 | Planned (UX.15.4) |

## 9. Implementation plan

The order follows the brief (UX.15.1–UX.15.23):
1. **Discovery, research and audit (UX.15.1–15.3):** this document.
2. **Design-system governance (UX.15.4):** the governed token scales replace literals; neutral-led palette; semantic status; a state component; the person chip with peek.
3. **Screens (UX.15.5–15.14):**
   - navigation and the workspace header;
   - Home;
   - My work;
   - Approval Center;
   - Employee 360;
   - People;
   - Org map;
   - Workforce pulse;
   - contextual intelligence;
   - forms, drawers and change preview.
4. **Cross-cutting (UX.15.15–15.18):** motion, mobile, dark mode, accessibility.
5. **Validation (UX.15.19–15.23):**
   - a disposable large-population showcase database (10, 100, 1,000 and 10,000+ employees, long names, large teams, hundreds of approvals, long timelines, restricted fields) for realistic-data and performance validation;
   - browser and device checks (Chromium, plus WebKit and Firefox if they can be installed);
   - screenshots;
   - the final review.

**Constraints carried into implementation.**
- No domain rewrite.
- No new write paths except presentation preferences.
- No simulated domain capabilities: no fake clarification, no fake recommendation.
- Synthetic data only, clearly marked.
- Every existing test stays as it is.
