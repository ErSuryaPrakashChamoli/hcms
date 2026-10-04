# PeopleOS UX.15 — Experience Elevation Report

**Date:** 4 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** UX.15.1 – UX.15.23, as set out in the UX.15 master prompt.

**Recommendation: NOT COMPLETE.** The signature experience is built and has been validated:

- Home, My work, the Approval Center, the person workspace, People, the Organisation map, Workforce pulse, contextual intelligence, Services and the Admin Centre;
- at 10,000+ employees, in two browser engines and on four device classes;
- in light and dark;
- against axe;
- with every role completing its zero-training task.

UX.15's own standard is not met everywhere, though:

- About 150 module list, form and detail pages are still recognisably Filament.
- Safari (WebKit) could not be run.
- Several P1 items remain.

The reasons are in [§16](#16-final-recommendation). Nothing in this report claims production or staging readiness.

Companion documents:
- [UX-15-Research-and-Audit.md](UX-15-Research-and-Audit.md) covers discovery, research, the audit and the screen matrix, now with final statuses.
- [PeopleOS-Design-System.md](PeopleOS-Design-System.md) is the governed design system.
- `docs/ux/ux15/` holds the evidence: screenshots, measurements, the browser matrix, the axe results and the zero-training walkthrough.

---

## 1. Baseline

| | |
|---|---|
| Starting commit | `53522ee` "docs(ux): UX.14 final UX audit and transformation report" (end of the Experience Transformation) |
| Branch | `feature/oct_1_phase_1`, 70 commits ahead of `origin`, never pushed |
| Repository status | Clean working tree |
| Test suite at baseline | 987 tests: 927 passed, 60 skipped (MySQL-only), 0 failed |
| Baseline screenshots | `docs/ux/ux15/before/` (36: 13 desktop + 5 mobile, light and dark) |
| Baseline layout metrics | `docs/ux/ux15/evidence/layout-metrics-before.jsonl` |

The "PeopleOS Experience Transformation.zip" reference package named in the prompt was not on disk. Its equivalent (the UX.1–UX.14 report, design system, research and twenty reference specifications in `docs/ux/`) was used instead, as recorded in the audit.

---

## 2. Research

Thirteen products were studied on 4 October 2026. The sourced notes, with an evidence level for every observation, are in `docs/ux/ux15/UX-15-Research-Notes.md`; the observed → decision → rejected → reason table is §4 of the audit.

| Product | What it taught | Evidence |
|---|---|---|
| Linear | Speed, keyboard-first work, peek, calm surfaces | Mostly fetched |
| Notion | Side peek, record layouts, views, "My tasks" | Mostly fetched |
| Apple HIG | Sidebars, split views, sheets, motion, dark mode, writing | Fetched |
| Stripe Dashboard | Omni-search, context drawer, empty / loading / feedback rules | Fetched |
| Ramp | Approvals inbox, readiness gating, AI recommendation citing policy | Mostly fetched |
| Rippling | Single employee record, effective dating, staged changes | Excerpt only (treated as hypothesis) |
| HiBob | Social home, historical tables, change requests | Mixed |
| Workday + Canvas | The incumbent's patterns and design-system rules | Fetched |
| Personio | Effective-dated edits, unified inbox | Mostly excerpt (hypothesis) |
| Deel | Approvals in place, proactive compliance, mobile | Mixed |
| Lattice | Manager home, AI with sources | Fetched |
| Culture Amp | Analytics as a story, privacy thresholds | Fetched |
| BambooHR | A friendly incumbent and its limits | Mixed |

**Adopted patterns:**
- a command palette that *acts on people* (authorised verbs such as "Start a transfer");
- a **peek → drawer → workspace** ladder for every person mention;
- decisions with their context beside them (master–detail);
- change previewed on its effective date (Before → After);
- analytics told as a story with drill-down and small-group suppression;
- contextual intelligence that cites its source;
- "since your last visit" in business language;
- a plain-language admin finder.

**Rejected patterns:**
- a social feed as the home: noise for operators;
- a free-floating chatbot greeting: replaced by contextual statements;
- KPI-tile dashboards: no story, no drill-down;
- jewel-coloured module tiles: colour without meaning;
- AI that acts without a person deciding;
- "staged AI changes": not compatible with PeopleOS's rule that domain services decide;
- copying any one product's screens.

---

## 3. Audit

Measured at baseline (Playwright layout metrics, axe, source review):

| Weakness | Evidence at baseline |
|---|---|
| Home was a wall of cards | 13–17 boxed cards and 13–15 distinct font sizes per role Home |
| Admin Centre was a module directory | 37 boxes of module links answering "what tables exist?" |
| The theme bypassed its own tokens | 20 literal font sizes, 10 literal radii, 102 literal paddings, 20+ hex literals |
| Colour decorated rather than meant | Indigo for neutral states ("purple everywhere"); violet-tinted dark mode; module tiles coloured by module |
| The 360 read as records | Profile header → eight tabs of tables; no lifetime view, no relationships beyond the line, no "what next" |
| Approvals had no master–detail | Everything at once; context away from the decision |
| Workforce Command Center had no story | KPI tiles and charts; no drill-down |
| AI was a generic chatbot | Greeting card; no context, no sources |
| My work required choosing a tab first | Tabbed list |

**Traditional HRMS patterns identified** (audit §5):
- sidebar → module → submodule → CRUD table;
- page title → KPI cards → chart → table;
- profile header → tabs → forms → tables;
- card, card, card, chart, table.

Each was replaced on the signature workspaces (§5). The first pattern still exists on module pages, which is the main reason for NOT COMPLETE.

---

## 4. Design system

Governed in `resources/css/filament/admin/theme.css` and documented in `PeopleOS-Design-System.md`, sections 1–14.

**Governance rule:** after the token block there are 0 literal font sizes and 0 literal radii. The only remaining hex value is `#000` inside masks. This is enforced by `Ux15FoundationTest`.

| Family | Tokens and rule |
|---|---|
| Colour | Neutral ramp `--pos-n-*` (cool grey in light, graphite in dark). Identity indigo `--pos-primary` #574bc4 is used only for primary action, selection, focus and links. Gold `--pos-accent` #8a6418 (text) and `--pos-gold` #c9a25a (decorative) mark high-value moments. Semantic colours: success #0f7a55, warning #9a5309, danger #b42335, info #2a5fbf. `--pos-on-fill` for text on fills. Filament's grey is re-tuned to the same neutral (hue 255) |
| Typography | Eight steps: `--pos-fs-caption` 12, `meta` 13, `body` 14, `lead` 16, `section` 18, `metric` 22, `title` 28, `display` 36. Nothing below 12 px. Instrument Serif for display and page titles only, Instrument Sans for everything else, tabular figures for numbers |
| Spacing | 4 px grid `--pos-space-1…16` (4–64 px); `--pos-gutter` 24 desktop / 16 phone |
| Density | Comfortable (default) and compact through `--pos-row-pad-y` and the density switch in Preferences |
| Radius | `--pos-radius-sm` 4, `md` 8, `lg` 12, `xl` 16, `pill` |
| Elevation | `--pos-shadow-0…3`: flat, raised, floating (peek, menus), overlay (drawer, dialog). Dark mode uses hairlines and surface steps instead of shadow |
| Motion | `--pos-dur-1…4` (160 / 220 / 280 / 360 ms), `--pos-ease`, `--pos-ease-emphasised`, `--pos-peek-delay` 350 ms. Reduced motion collapses everything. Enter animations use `backwards` fill so modals are never trapped in a transformed containing block |
| States | `--pos-state-hover / pressed / selected / selected-text / disabled`, identical across components |
| Layers | `--pos-z-sticky / nav / peek / drawer / command / overlay` |
| Responsive | Phone < 768 px: bottom bar with Home, Work, Actions, People and Services. Tablet 768–1279: collapsed rail. Desktop ≥ 1280: rail and workspace columns. Coarse pointers get 40 px targets |

**Component system** (Phase 36 names → implementation):

| Phase 36 name | Implementation |
|---|---|
| PeoplePerson | `x-pos.person`: hover or focus peeks, click opens the drawer |
| PeoplePeek | `PeekHost` + `posPeek` |
| PeopleDrawer | `DrawerHost`: person, person-action, approval, change, pulse |
| PeopleWorkspace | `.pos-ws*` |
| PeopleState | `x-pos.state`: empty, caught-up, filtered, loading, error, denied, stale, partial |
| PeopleChange | `x-pos.change`, `x-pos.before-after` |
| PeopleSection | `x-pos.section` |
| PeopleMetric | `x-pos.figure` |
| PeopleIntelligence | `x-pos.intelligence` |
| PeopleApproval | `x-pos.approval-card` |
| PeopleTimeline / Journey | `.pos-tl*`, `x-pos.journey` |
| Streams | `.pos-stream*` |
| Section navigation | `.pos-sectnav` |
| Moments | `.pos-moment` |

---

## 5. Implementation

14 commits, from `e2e5ca7` to `0662272`, plus this report. Across the whole range, 192 files changed with 11,061 insertions and 1,691 deletions; most of the volume is documentation and screenshots. The code itself touched:
- `app/Domain`: 16 files;
- `app/Filament`: 10;
- `app/Livewire`: 3;
- views: 33;
- theme, script and panel provider: 1 each;
- seeders: 2;
- tests: 12 new files.

### 5.1 Screens changed

| Screen | What it is now |
|---|---|
| Home (all roles) | A dated greeting and a one-sentence brief. Then, in order: decisions with their reason, your day as a timeline, what changed since your last visit, your people, momentum, and suggested actions. PeopleOS Intelligence sits beside them. The role lens ("For you / Your team / Company") changes the content, not the layout |
| My work | A focus workspace: "Do this next" with Later or the action; streams of decisions, tasks, follow-ups, waiting-on-others and done. Filters replace tabs; long streams show their first 15 |
| Approval Center | A decision workspace: "N decisions need you", grouped urgent / this week / later, with the queue beside the decision. Each decision brings the person, the reason, team overlap, the effective date and Before → After. J/K/A/R keys work. The queue renders 25 at a time and shows "200+" when a source reaches its scan limit |
| Employee 360 | A person workspace. A lifetime ribbon ("One lifetime record"; a rehire continues the same record). Section navigation: Now, Journey, Work, Growth, Rewards, Documents and Records. Relationships of every type, what's next, what changed and intelligence. The original records sit below, collapsed |
| People | A directory with peek → drawer → 360; group-by; card, table and recently-changed views. Cards show the employee code (same-named people at scale) and allow two-line names |
| Organisation map | Reporting lines with relationship overlays (dotted, functional, project, mentor and others), person and team peek, departments with a team peek, and paged children. People not yet joined are not on the current lines |
| Workforce pulse (was Workforce Command Center) | Opens with a headline sentence built from real figures, then movement, size, attendance, performance and capability, critical skills and exceptions. Every figure is drillable: people for viewers with people access; departments with small groups suppressed for everyone else |
| Contextual AI | PeopleOS Intelligence on Home, the Approval Center and the person workspace. Statements are deterministic, cite their source and suggest a screen, and every one is recorded through `AiGateway::recordContext`. The free-text assistant panel stays for questions |
| Services (My HR) | "How can HR help?": a front door with what needs you, your requests and the services you can start |
| Admin Centre | "What do you want to manage?": a plain-language finder that now finds individual settings by name, governance at a glance, and six areas with their most-used settings |
| Change flows | "Transfer / promote" and "Change manager" are three-step flows (Context → Change → Review) with Before → After and the effective date |
| Shell | Workspaces without a module bar; a quieter "All in {area}" disclosure on module pages; the command palette with Intelligence / Navigation / Knowledge groups and people verbs; the bottom bar on phones; a compact "Ask PeopleOS" entry in the rail |

### 5.2 Components and read models

**Created:**
- components `x-pos.person`, `x-pos.state`, `x-pos.change`, `x-pos.section`, `x-pos.figure`, `x-pos.intelligence`;
- the Livewire `PeekHost`;
- read models `PersonPeek`, `PersonWorkspace`, `WorkforcePulse`, `ContextualIntelligence`;
- the guarded synthetic seeder `UxScaleShowcaseSeeder`.

**Modified:**
- `HomeComposer`, `ApprovalCenter`, `ApprovalItem`, `WorkInbox`, `ChangeFeed`, `ExperienceNavigation`, `QuickActions`, `CommandSearch`, `IntentSearch`, `ExperiencePreferences`;
- `CommandCenter`, `DrawerHost`;
- `x-pos.approval-card`, `x-pos.before-after`, `x-pos.journey`, `x-pos.sparkline`;
- every workspace view.

**Deleted:** `partials/home-today`, `x-pos.hero-art`, `employees/overview-360`.

### 5.3 Design-system, navigation, interaction and motion changes

- **Tokens.**
  - Governed tokens replace every literal size and radius.
  - The neutral is cool grey; indigo means identity, not decoration.
  - Module tiles are neutral.
- **Navigation.**
  - Workspaces show their own header.
  - Module pages get a quieter space bar with "All in {area}" kept in the page flow.
  - The phone bottom bar carries five destinations.
  - The command palette does people verbs ("Start a transfer", "Change manager") through a pick step. The verbs are offered only where the server authorises them.
- **Interaction.**
  - One person chip everywhere in the workspaces.
  - Keyboard list navigation on queues.
  - "Later" snooze on the next item.
  - Drill-down drawers.
  - Guided change flows.
- **Motion.**
  - A small set: fade-up enter, grow, ring-once, swap-in and peek-in.
  - Durations come from tokens; reduced motion collapses them.

### 5.4 Mobile, dark mode and accessibility

- **Mobile.**
  - Phone-first order on every workspace.
  - Bottom bar: Home, Work, Actions, People, Services.
  - Notifications and profile stay in the top bar.
  - 40 px touch targets on coarse pointers.
  - The icon-only quick launch keeps its +; a touch rule had squeezed it to 2 px, found and fixed in UX.15.21.
- **Dark mode.**
  - Graphite neutrals.
  - Indigo by meaning only.
  - Tile and status tokens stated explicitly in both themes.
- **Accessibility.**
  - axe WCAG 2.0/2.1/2.2 AA: 52 checks across both themes, including drawers, peek, command palette, modal and area menu, with **0 violations** (`docs/ux/ux15/evidence/accessibility-axe-ux15.json`).
  - Visible focus.
  - J/K/A/R/Esc keys on queues.
  - Every sparkline has a text alternative.
  - Native date inputs in the guided flows (the Filament date picker nested interactive controls).

---

## 6. Security

The model is unchanged:

**Auth → Tenant → Role → Permission → Organisation scope → Relationship scope → Field security → Record.**

| Invariant | How UX.15 keeps it |
|---|---|
| Tenant isolation | No read model bypasses `BelongsToTenant`; the full suite's tenancy tests pass |
| Policies decide | Every approval item is still authorised by its domain policy (`$user->can()`), including after the scale batching. `pendingAbout()` runs the same sources and policies, constrained to one person. Decisions still go through `ApprovalDecisions` to the owning domain service |
| Organisation and relationship scope | Peek, workspace, pulse drill, org map and People all read through `PeopleVisibility` / `AccessScopes`. The batched team-overlap preload keeps the Employee and LeaveRequest access scopes in its sub-queries |
| Field security and sensitive categories | The person workspace hides sensitive timeline categories and leave dates without the permission (`Ux15PersonWorkspaceTest`); the side panel still passes `UxSecurityTest` |
| Privacy in aggregates | The pulse drill without people access groups by department only, never by designation (which could single someone out), and suppresses groups below the threshold |
| AI policy | Intelligence shows only where `AiGateway::assistantsFor` allows. It is deterministic, cites its sources and makes no employment, pay or compensation recommendation. Every statement is recorded through `AiGateway::recordContext` (`ai_interactions`, `ai_generated = false`) |
| Admin finder | Lists modules and settings only where the viewer may open them. Settings show names and keys, never values. A defect where the panel root matched every path, leaking setting labels, was fixed in UX.15.5 with a test |
| Domain boundaries | Payroll, Attendance, Leave, Performance, Compensation, Service Desk, Audit, Security and Integration code is unchanged. Outside the experience layer: `AiGateway` gained an additive `recordContext`, and `WorkforceMetrics::avgTenure` computes the same value faster (the test asserts equality) |
| Tests | No existing test was edited or disabled. Twelve new test files (43 tests) were added |

**Finding (configuration, not changed).** The demo personas have no organisation scope rows. By the documented rule ("no rows = tenant-wide", security-invariants §1), the demo manager therefore sees and may approve tenant-wide. Real tenants must give managers scope rows. The scale database configures its manager realistically, with a team scope.

---

## 7. Performance

**Method.** In-process requests against a MySQL `*_showcase` database, measuring query count, database time, wall time and HTML size per screen and persona (`docs/ux/ux15/evidence/scale/`).

**Sizes and states.**
- Sizes: 22 (showcase base), 100, 1,000 and 10,785 employees.
- "Before" was measured with the code at the start of UX.15.19. "After" was measured after the fixes, on a freshly built database for 22 / 100 / 1,000 and on the 10k database.
- The 10-employee level is covered by the Pest scale guards (SQLite).
- The machine is a developer workstation running `php artisan serve`. Absolute times are indicative only.

**At 10,785 employees, before → after:**

| Screen (viewer) | Queries | Wall time | HTML |
|---|---|---|---|
| Home (manager with 120+ reports, 300 pending) | 1,450 → 672 | 7.6 s → 2.0 s | 173 KB |
| Home (HR) | 1,382 → 604 | 3.4 s → 1.4 s | 162 KB |
| My work (manager) | 1,344 → 566 | 6.3 s → 1.6 s | 649 → 195 KB |
| Approval Center (manager) | 1,326 → 594 | 6.3 s → 1.7 s | 1,986 → 368 KB |
| Approval Center (HR) | 1,110 → 378 | 2.4 s → 0.8 s | 1,987 → 370 KB |
| Employee 360 (HR) | 1,202 → 220 | 3.1 s → 0.5 s | 285 KB |
| Employee 360 (manager) | 1,462 → 279 | 6.7 s → 0.8 s | 258 KB |
| Workforce pulse (executive / HR admin) | 135–165 → 116–139 | 4.9–11.1 s → 1.2–1.4 s | 135–145 KB |
| People (HR) | 100 → 100 | 0.4–0.8 s | 171 KB |
| Organisation map (HR) | 101 → 101 | 0.9–1.8 s → 0.9 s | 213 KB |

**Findings and fixes:**
1. **Approval queue N+1.**
   - Team overlap cost two queries per request; it is now preloaded for all requests in two queries.
   - Leave balances were read per item; they are now read only when a card shows the Before → After.
   - The person workspace built the whole queue to find one person's items; it now uses `pendingAbout()`.
2. **Approvals page payload.** It rendered a decision card for every item. It now renders 25 at a time, with counts kept whole.
3. **My work payload.** Every row was rendered; streams now show 15, with "Show all N".
4. **Workforce pulse PHP time.**
   - The risks recomputed a second, twelve-metric set; they now reuse the pulse metrics.
   - Average tenure hydrated a model and ran a Carbon difference per employee. It is now plucked and computed once per distinct joining date. The result is identical, and the cost is bounded by the days in the company's history.
5. **Organisation map.** Building the lines was O(N²) and every node scanned every line. Lines are now O(N), with a children index.
6. **Honest counts.** When a source reaches its scan limit (200), Home and the Approval Center say "200+" and explain why.

**After, scaling.** Query counts are flat with headcount for every surface and persona. HR's Home grows by a bounded amount (up to 200 queue items and 120 feed entries).

**Pest guards.** `Ux15ScaleTest` fails without each fix. It asserts at most 2 extra queries per additional pending request (it was 2.8 without the preload, and about 5 before UX.15.20), no balance queries while building the queue, the team-overlap rule, the "200+" cap with paging, preboarding off the map, and the tenure value.

**Remaining (see §14):**
- About 2 policy queries per approval item, kept on purpose so every item is re-authorised.
- About 36 viewer-employee lookups and about 80 cached settings / feature-flag reads per request. These are a constant from shell `canAccess()` checks and platform services, and cheap with Redis.
- People filter option queries take about 100 ms each at 10k.

**Livewire.** "Show more" and "Show all" rebuild the queue, so each costs about the same as a page load. Search on People at 10k returns in about 0.4 s.

---

## 8. Enterprise data

`UxScaleShowcaseSeeder` refuses anything but a disposable `*_showcase` database and marks every row "UX scale seed (synthetic)". It grew the demo tenant to **10,785 employees** with:

- a six-level hierarchy across 25 departments and 5 locations;
- every relationship type: line plus about 280 dotted, functional, project, mentor, buddy, HRBP and secondary;
- 1,545 promotions and 226 transfers, including this month and last;
- joiners this month;
- 252 exits, 201 in notice and 619 in probation (some overdue), plus 101 on leave, 50 suspended and 42 preboarding;
- 478 approved and 441 pending leave requests.

The seeder then added the hard cases once:

| Condition | Result |
|---|---|
| A manager with 120+ direct reports and 300 pending requests (15 already started, 10 cancellations) | Home, My work and Approvals stay usable (§7); the heading says "202+ decisions need you"; long names wrap |
| A flat team of 450 under one director | The org map shows the director with "450"; children are paged |
| Very long names (ten, including diacritics and hyphens) | The 360 header wraps (desktop and phone); People cards clamp to two lines with the full name on hover; queue rows wrap |
| A very long department name | Truncated with an ellipsis on cards and the org map (it used to overflow into the next card; fixed) |
| Departments with no one in them | Not listed among department headcounts; no errors |
| People with no department or manager | They appear as their own roots on the org map, which is correct |
| A 300-entry timeline and a long description | The journey and "What changed" stay bounded |
| A 255-character leave reason (the form's limit) | Shown in full in the decision card |
| Missing photographs (everyone) | Initials avatars everywhere |
| Duplicate names (60 × 60 name pool) | Employee codes tell people apart on cards |
| Preboarding people with future-dated lines | Used to appear as roots beside the CEO; now off the current map |
| Restricted viewers | The pulse drill without people access shows department counts with small groups suppressed; the scoped manager sees 126 people across 1 department and 1 location |
| Unusual combinations | A notice-period team lead with reports; a suspended employee with approved leave; overdue probation; on leave with a pending cancellation. All render with honest statuses |

Screenshots: `docs/ux/ux15/scale/` (14).

---

## 9. Browser and device

Matrix (`docs/ux/ux15/evidence/browser-matrix-ux15.json`, script `ux15-matrix.mjs`):

- **Engines:** Chromium (the Chrome and Edge engine) and Firefox.
- **Viewports:** 1440 desktop, 1280 laptop, 820 tablet (touch) and 390 phone (touch).
- **Screens:** ten workspaces.
- **Checks:** HTTP status, script errors, horizontal overflow, landmarks, the bottom bar on phones, the command palette (Ctrl K, type, Esc), the person drawer, peek on hover (desktop), the bottom-bar Actions (phone) and the dark scheme.

| Result | Detail |
|---|---|
| 105 / 110 first pass | No overflow, no script errors and correct landmarks on all 80 screen checks |
| 4 "peek" failures | Test-locator misses; rechecked green in both engines at both desktop widths (`browser-peek-recheck.txt`) |
| 1 Firefox font warning | An aborted first navigation in the test harness; did not recur |
| Safari / WebKit | **Not validated.** Playwright's WebKit needs system packages (libflite1, libavif16, libmanette-0.2-0, gstreamer1.0-libav) that were not installed without approval |
| Edge | Not installed; covered by the Chromium engine only |

Screenshots: `docs/ux/ux15/browser/` (12, Firefox and tablet).

---

## 10. UX instrumentation

**What exists.** `UxMetrics` keeps anonymous daily counters per tenant: metric name, count and optional duration. There is no user, no query text and no record. Today it records:

- `home.view`;
- `command.open`, `command.search` (with duration), `command.select`;
- `search.intent`, `search.no_results`;
- `drawer.open`;
- `approval.decided`, `approval.failed`;
- `ai.asked`;
- `leave.requested`.

**Future opportunities.** Same rules throughout: aggregate counters, no user, no text, existing retention settings. None was implemented in UX.15.

| Behaviour | Counter | Why |
|---|---|---|
| Time to decision | Median minutes from request to decision, by type | Whether decisions-with-context speed approvals up |
| Queue cap reached | Count of "200+" renders | Whether queues outgrow the scan limit |
| Paging depth | "Show more" and "Show all" per screen | Whether page sizes fit |
| Peek → drawer → workspace funnel | Counts at each level | Whether the ladder replaces opening full records |
| Admin finder misses | Searches with no result (count only) | Gaps in the setting index |
| "Later" snoozes | Count on "do this next" | Whether "next" is chosen well |
| Intelligence follow-through | Clicks on a suggested screen, by context | Whether statements are useful |
| Pulse drill opens | By figure | Which workforce questions matter |
| Navigation dead ends | Denied pages reached from navigation, by route name | Navigation offered something the person cannot open |
| Abandoned change flows | Opened vs confirmed, by flow | Where guided flows lose people |
| Repeated journeys | Top command verbs by key | Candidates for Home or the quick actions |

---

## 11. Visual validation

**Inventory:**

| Set | Location | Count |
|---|---|---|
| Phase 46 after (desktop 1–13 and mobile 1–5, light and dark) | `docs/ux/ux15/after/` | 36 |
| Baseline (same set) | `docs/ux/ux15/before/` | 36 |
| Enterprise scale (10,785 employees) | `docs/ux/ux15/scale/` | 14 |
| Browser / device (Firefox, tablet) | `docs/ux/ux15/browser/` | 12 |
| Zero-training walkthrough end states | `docs/ux/ux15/zero-training/` | 5 |

The Phase 46 desktop files are named as follows:

| Phase 46 screen | File (`-light` / `-dark`) |
|---|---|
| 1 Employee Home | `d01-home-employee` |
| 2 Manager Home | `d02-home-manager` |
| 3 HR Home | `d03-home-hr` |
| 4 Executive Workforce Command Center | `d04-workforce-command-center-executive` |
| 5 My Work | `d05-my-work-manager` |
| 6 Approval Centre | `d06-approvals-manager` |
| 7 People | `d07-people-hr` |
| 8 Employee 360 | `d08-employee-360-hr` |
| 9 Organisation Map | `d09-org-map-hr` |
| 10 Workforce Pulse | `d10-workforce-pulse-executive-home` |
| 11 Contextual AI | `d11-contextual-ai-360` |
| 12 Services | `d12-services-employee` |
| 13 Admin | `d13-admin-centre` |

Mobile files are `m01`–`m05` (Home, My Work, People, Approval, Employee 360).

**Layout metrics, before → after** (`layout-metrics-*.jsonl`; boxed elements / distinct font sizes / elements painted in the primary colour):

| Screen | Boxes | Font sizes | Primary-painted |
|---|---|---|---|
| Home (employee) | 16 → 7 | 14 → 6 | 38 → 10 |
| Home (manager) | 17 → 6 | 15 → 6 | 35 → 14 |
| Home (HR) | 13 → 6 | 13 → 6 | 25 → 9 |
| Home (executive) | 13 → 3 | 13 → 6 | 28 → 9 |
| Workforce pulse | 13 → 5 | 11 → 6 | 4 → 4 |
| Admin Centre | 37 → 11 | 7 → 5 | 1 → 22 (area links) |
| Employee 360 | 7 → 20 | 12 → 7 | 6 → 8 |
| People / Org map / Services | ≈ unchanged boxes | 6–7 → 4 | ≈ |

My work and Approvals had almost no data at baseline (the synthetic activity arrived in UX.15.6), so their counts are not like-for-like.

**Phase 47 review.** For each screen: hierarchy, typography, spacing, density, colour, consistency, interaction, responsiveness, accessibility, credibility, distinctiveness and premium quality.

| Screen | Strengths | Weaknesses still visible |
|---|---|---|
| Homes | One sentence says what matters; decisions carry their reason; at most six type sizes; calm neutral surfaces; gold only for moments | The first-run welcome note and "Need attention" rows are dense on phones |
| My work | "Do this next" is unmistakable; streams read as work, not modules | Rows repeat domain words ("Leave ·", "Attendance ·") |
| Approval Center | Master–detail, Before → After and keys make it feel like a decision tool | Each decision still costs a server round-trip to load the next ones |
| Employee 360 | A lifetime story, relationships and "what's next"; the most distinctive screen | Many panels (20 boxed elements); records tabs below are still Filament tables |
| People | Peek ladder, views and codes | The card grid is still a familiar directory pattern |
| Organisation map | Relationship overlays and peek are uncommon in HRMS | A canvas of boxes; no minimap at 10k |
| Workforce pulse | A headline sentence and drillable figures with privacy | Charts are minimal by design; dashes for restricted figures need explaining |
| Contextual AI | Sourced statements, labelled, never deciding | The free-text panel still resembles an assistant panel |
| Services | A front door, not tabs | Tab row beneath is still module-like |
| Admin | Plain-language finder, now down to the setting | The six area cards are a link directory under the finder |

**Fixed during visual review:**
- headcount sparklines start at zero, so a one-person dip no longer draws as a cliff;
- the welcome note wraps on phones;
- the phone quick-launch icon;
- the long department name overflowing a card;
- People card names and codes;
- "1 departments" pluralisation.

---

## 12. Before / after

### Home

**Before.** A hero, a strip of KPI cards, action cards, panels, charts and a chatbot card: 13–17 cards and 13–15 font sizes.

**Problem.** Nothing said what mattered. Every block competed, the chatbot greeted instead of helping, and nothing told the person what had changed.

**Design decision.** One brief sentence of counts with their meaning. Decisions with the reason each needs you. The day as a timeline. "What changed since your last visit". Your people. Intelligence with sources. The role lens changes content, not layout.

**After.** "Here's what matters today: 6 decisions are waiting for you, 4 things need your attention and 3 things are due today." 3–7 boxes and 6 font sizes.

**Why it is better.** The first line answers "what matters / what needs me". Every item carries its reason and next action, and the screen is calm enough to read in seconds.

### Employee 360

**Before.** A profile header, then eight tabs of tables and a module bar.

**Problem.** A person read as a set of database records. There was no lifetime, no relationships beyond the line manager and no "what's next".

**Design decision.** A person workspace. A lifetime ribbon, one record across rehire. Section navigation (Now, Journey, Work, Growth, Rewards, Documents, Records). Relationships of every type, what's next, what changed, and intelligence. Records are demoted below.

**After.** For example, "In probation · ends in 18 days", "One lifetime record", mentor and dotted lines, "Probation decision is overdue".

**Why it is better.** HR sees "this person's complete story" in one place. Changes and next steps are visible without opening tables, and sensitive categories stay hidden by the same rules.

### My work

**Before.** Tabs over a list.

**Problem.** You had to choose a tab before learning what to do.

**Design decision.** A focus workspace: one "Do this next" with Later or the action, then streams by kind, with filters instead of tabs.

**After.** "Start with Rahul Sharma's casual leave · 1 day. 12 more things need you."

**Why it is better.** The next action is chosen for you, and the rest is ordered by urgency with its reason.

### Approval Centre

**Before.** Grouped cards, everything at once.

**Problem.** There was no master–detail. Context sat away from the decision, and nothing said how many decisions were waiting.

**Design decision.** A decision workspace: "N decisions need you", urgent / this week / later, the queue beside the decision, and Before → After with team overlap and the effective date. J/K/A/R keys. Paged at scale, with "200+" when capped.

**After.** "6 decisions need you · 1 urgent · 2 this week · 3 later". At 10k: "202+ decisions need you", in 1.7 s instead of 6.3 s.

**Why it is better.** A manager decides with the facts beside the button, in priority order, without being buried at scale.

### People

**Before.** Cards or a list with filters and a drawer.

**Problem.** There was no peek, grouping, table view or "recently changed", and same-named people were indistinguishable.

**Design decision.** Peek → drawer → 360 for every name. Group-by. Card, table and recently-changed views. Employee codes and two-line names.

**After.** "10,784 people you can see across 25 departments and 5 locations", searchable in about 0.4 s.

**Why it is better.** A person can be checked without leaving the list, and the directory stays legible at enterprise size.

### Workforce Command Center → Workforce pulse

**Before.** KPI tiles, blocks and charts.

**Problem.** There was no story and no drill-down. The executive had to interpret tiles.

**Design decision.** A headline sentence from real figures, then movement, size, attendance, capability and exceptions. Every figure drills into the people behind it, or into departments with small groups suppressed.

**After.** "Workforce movement is 4.9% of headcount this month, up from 2% last month: 145 internal moves, 177 promotions, 147 joiners and 42 exits." This is at 10k, in 1.3 s instead of 5–11 s.

**Why it is better.** "Here is what changed across my workforce" is the first sentence, and every number explains itself on click without exposing individuals.

---

## 13. Role tests

**Phase 37 — zero training** (scripted walkthrough, `docs/ux/ux15/evidence/zero-training.jsonl`):

| Role | Task | Path | Interactions |
|---|---|---|---|
| Employee | "I need to apply for leave." | Home → **Request leave** opens the form with the balance preview | 1 |
| Manager | "Show me everything I need to act on today." | Home's brief → **Review 6 decisions** → "6 decisions need you", first decision open | 1 |
| HR | "Something changed in this employee's employment." | Ctrl K → name → Enter → person workspace; Journey and What changed show the promotion | 3 |
| Executive | "What's happening with my workforce?" | Home → **Open Workforce pulse** → headline sentence | 1 |
| Administrator | "I need to change an organisation policy." | Admin → finder "probation period" → **Employee · probation · default months** (fixed in UX.15.23; it used to answer "Settings" and "Letter templates") | 1 |

No role needs to know the module or database structure.

**Phase 38 — role experience.** One platform with adaptive content:

| Role | Experience |
|---|---|
| Employee | Personal work and journey: Home, momentum, My HR |
| Manager | Team and decisions: Home decisions, team pulse, Approval Center |
| HR | People and lifecycle: what changed, person workspace, People |
| Executive | Workforce intelligence: Workforce pulse |
| Administrator | Configuration and governance: Admin Centre finder, change centre and platform health |

**Phase 40 — WOW test and Phase 41 — first 30 seconds:**

| Role | Where am I / what matters / what can I do | What changed / what needs me | Feels premium | Looks like a generic HRMS or Filament? |
|---|---|---|---|---|
| Employee | Yes: dated greeting, brief, "Request leave" | Yes: Need attention, your day | Yes | No on Home and My HR. Yes once they open a module list (for example Leave requests) |
| Manager | Yes | Yes: "6 decisions need you", team pulse | Yes | No on workspaces; module tables yes |
| HR | Yes | Yes: since your last visit, person story | Yes | No on workspaces. The records tabs under the 360 still look like Filament |
| Executive | Yes: the headline sentence | Yes | Yes | No |
| Administrator | Yes: "What do you want to manage?" | Partly: governance figures, change centre | Mostly | The area link directory and the module CRUD screens behind it still read as admin tables |

The last column is why the recommendation is NOT COMPLETE.

---

## 14. Remaining issues

**P0:** none known.

**P1:**
1. **Module list, form and detail pages (about 150)** have tokens and quieter chrome only. They fail the Phase 39 traditional-HRMS test as polished Filament. People reach them from Records, Setup and the area menus.
2. **Approval authorisation cost at scale.** Each pending item costs about 2 policy queries, kept so every item is re-authorised by its domain policy. A manager with 200+ pending items waits about 1.7–2.0 s on this machine. A real fix needs a batch authorisation API in the Leave and Identity domains (security code; not attempted).
3. **Safari / WebKit not validated.** System packages are missing. Edge is not installed (its Chromium engine was validated).
4. **The person workspace is panel-heavy** (20 boxed elements), and its records tabs are Filament tables.
5. **Demo personas have no organisation scope rows**, so they are tenant-wide by the documented rule. Tenants must scope managers. A governance warning such as "managers without scope" in the Admin Centre would help.

**P2:**
- A constant per-request cost: about 36 viewer-employee lookups from shell `canAccess()` checks across modules, and about 80 settings and feature-flag cache reads. These are queries with the database cache store and cheap with Redis.
- ChangeFeed relationship checks, bounded at 120 entries (HR Home at 1,000 employees: about 100 queries).
- People filter option queries take about 100 ms each at 10k.
- The person chip, peek and drawer are not yet used on older analytics pages and Filament tables (Phase 30 consistency outside the workspaces).
- "Show more" on Approvals rebuilds the queue (about one page load).
- No org map minimap or virtualisation for very large canvases.
- My work rows repeat domain words.
- On phones, the "Need attention" rows and the welcome note stay dense.
- Synthetic leave without accrual shows negative balances in the Before → After. This is honest, but odd in demos.

**Deferred:**
- full re-architecture of module pages into workspaces;
- batch policy evaluation;
- list virtualisation;
- the instrumentation counters in §10;
- verification of Redis and other target infrastructure (out of scope; never claimed).

**Known limitations:**
- All screenshots and measurements use fictional, synthetic data on disposable `*_showcase` databases.
- Timings come from a developer workstation with `php artisan serve`.
- Intelligence is deterministic. It is labelled "Generated" because it is computed, though no model writes it.
- No production or staging readiness is claimed.

---

## 15. Final UX score

Scored after the validation round. "Signature" means the workspaces listed in §5.1; "Overall" includes the module pages people can still reach. For traditional-HRMS similarity, lower is better.

| Measure | Signature workspaces | Overall | Remaining weakness it points to |
|---|---|---|---|
| Traditional HRMS similarity | 3 / 10 | 5 / 10 | Module pages and records tabs |
| PeopleOS differentiation | 8 / 10 | 7 / 10 | Same |
| Premium quality | 8 / 10 | 7 / 10 | Dense phone rows; panel-heavy 360 |
| Usability | 8.5 / 10 | 8 / 10 | Approval latency at extreme scale |
| Consistency | 8 / 10 | 6.5 / 10 | Person chip and peek absent outside the workspaces |

---

## 16. Final recommendation

**NOT COMPLETE.**

What is complete and validated:
- the research, audit and governed design system;
- every signature workspace;
- correctness and performance at 10,000+ employees, with N+1 regressions guarded by tests;
- two browser engines and four device classes;
- light and dark;
- axe with 0 violations;
- zero-training success for all five roles;
- security invariants intact, with no test weakened.

What prevents COMPLETE under UX.15's own standard ("Could this reasonably be mistaken for a polished Filament … screen?" for every major screen; "validate desktop, tablet and mobile" including Safari):
1. module list, form and detail pages, and the 360's records tabs, still read as polished Filament;
2. Safari / WebKit has not been run;
3. the P1 items in §14.

**Verification at close:**
- **Tests:**
  - the full suite passed: 1,030 tests, 970 passed, 60 MySQL-only skips, 0 failed (§17);
  - the new UX.15 tests are `Ux15*Test` (43 tests in 12 files);
  - Pint passes.
- **Static checks:** `php -l` on changed PHP, Blade compilation via the render tests, and `npm run build`. PHPStan is not part of this repository.
- **MySQL:** the MySQL-only suites remain opt-in and skipped (60 tests), as at baseline. The scale validation itself ran on MySQL.
- **Git:** everything is committed. Nothing was pushed, merged or deployed.

UX.16 has not been started.

## 17. Test results at close

| Run | Result |
|---|---|
| Baseline (`53522ee`) | 987 tests · 927 passed · 60 skipped (MySQL-only) · 0 failed |
| After UX.15 (final code, `0662272`) | **1,030 tests · 970 passed · 60 skipped (MySQL-only, as at baseline) · 0 failed** · 12,148 assertions (`php artisan test --parallel --processes=6`, 628 s) |
| UX.15 tests | 12 new files, 43 tests (`tests/Feature/Experience/Ux15*Test.php`); no existing test edited or disabled |
| Pint | Passed (`vendor/bin/pint --test`) |
| Static checks | `php -l` clean on every changed PHP file; `php artisan view:cache` compiles every Blade template; `npm run build` succeeds |
| Accessibility | axe WCAG 2.0/2.1/2.2 AA: 52 checks, 0 violations |
