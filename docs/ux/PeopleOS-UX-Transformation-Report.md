# PeopleOS Experience Transformation report

**Branch:** `feature/oct_1_phase_1`. **Baseline:** `b25b5e4`. **Work commits:** UX.1–UX.14 (section 31).
**Date:** 3–4 October 2026.

**Status: PARTIAL.** The new experience is built, tested and documented:
- **Redesigned:** the signature surfaces (Home, My work, Approval Center, Employee 360, People, Org map, Workforce Command Center, command center, drawers, notifications) no longer read as a traditional HRMS or as Filament.
- **Still classic:** the ~150 module screens (Filament list, form and detail pages) are restyled and gain the PeopleOS navigation and space bar, but they are still recognisably Filament.

Under the brief's §58 rule, that is a "yes, partly" to "does it feel like Filament?", so the UX is not declared complete. See section 29.

The transformation changed presentation, navigation and read models only. No payroll, statutory, attendance, leave-ledger, compensation, audit, tenancy, workflow, identity, lifecycle or integration logic was rewritten.

---

## 1. Research findings

The full study is in `docs/ux/PeopleOS-UX-Research.md`.

**What was studied.**
- Eight HCM products: Rippling, Deel, HiBob, Personio, Workday (with the Canvas design system), BambooHR, Lattice and Culture Amp.
- Nine modern SaaS references: Linear, Superhuman, Notion, Stripe, Ramp, Mercury, Slack, Figma and Vercel.
- Thirty topics, each classified A–F.
- Sources were public pages; blocked sites are marked as search excerpts. Eleven high-impact claims were re-fetched and confirmed.

**Main findings.**
- **Home.** The market is moving from module dashboards to "what action is required" homes (Deel, Personio, Workday).
- **Decision support.** Products increasingly give context at the moment of decision (Workday, Deel, Ramp).
- **Search.** Cmd+K search is spreading.
- **Profiles.** Records open as side peeks.
- **AI.** Assistants answer within the user's permissions and cite sources (Lattice, BambooHR, Notion, Slack).
- **What is missing.** Very few HCM products offer:
  - a command palette that acts on a person;
  - field-level Before → After previews with effective dates;
  - a visual lifecycle journey;
  - designed empty, loading and error states;
  - published accessibility evidence.

## 2. Competitive UX patterns

| Pattern | Seen in | PeopleOS decision |
|---|---|---|
| Action-first home | Deel, Personio, Workday | Adopted, with the reason shown for each item |
| Unified inbox | Personio, Deel, Workday | Adopted as My work plus the Approval Center |
| Cmd+K search | Personio, Linear, Superhuman | Adopted and extended: search that acts on a person |
| Side peek | Notion, Linear, Stripe, Culture Amp | Adopted as drawers with stacking |
| AI with citations | Lattice, BambooHR, Notion, Slack | Adopted: Answer / Key facts / Sources / Suggested actions |
| Social-feed home | HiBob | Rejected: noise and personal data |
| Drag-and-drop widgets | BambooHR, Deel | Rejected in favour of role defaults, a lens switch and hiding cards |
| Icon-only rails | Rippling | Rejected: the rail keeps its labels |
| Autonomous AI actions | Several | Rejected: AI only explains and suggests |

## 3. PeopleOS differentiation strategy

PeopleOS combines the useful patterns around its own strengths:
- a single employee record;
- effective-dated history;
- the audit chain;
- strict tenant, organisation and relationship scopes;
- the existing workflow and approval engines.

Its differentiators are:
1. A command center that searches and acts.
2. Decisions that arrive with their context.
3. Before → After previews on changes that matter.
4. The employee journey as a map.
5. Contextual AI that is scoped and honest.
6. Nine sections that adapt to the person, instead of thirty module groups.
7. Designed states, and click targets that were measured.

## 4. Design principles

1. Work first.
2. Context without leaving the page.
3. Elegant confidence: a midnight rail, an iris primary, a champagne accent, muted jewel tones and one primary action per section.
4. Explain, don't only display.
5. The UI never decides access.
6. Accessible by construction.

Details are in `docs/ux/PeopleOS-Design-System.md`, section 1.

## 5. Design system

The design system is in `docs/ux/PeopleOS-Design-System.md` and is implemented in `resources/css/filament/admin/theme.css`.

It is a Filament Vite theme that keeps Filament's CSS and adds:
- semantic `--pos-*` tokens for light and a separately designed dark set;
- density tokens;
- motion tokens;
- shell overrides;
- a `.pos-*` component layer.

Blade components live in `resources/views/components/pos/`:

| Group | Components |
|---|---|
| Identity and status | avatar, status, empty, tile-icon |
| Charts | ring, bars, columns, donut, sparkline |
| Decisions and change | before-after, approval-card, journey, stepper |
| AI and art | ai-response, hero-art |

Alpine behaviours are in `resources/js/peopleos.js`.

**Mid-course revision.** The product owner shared a reference dashboard and asked for its richer colour, "not exactly the same colour but very elegant". The teal palette was replaced with PeopleOS's own palette (section 6). Everything was re-checked for contrast.

## 6. Colour system

| Role | Light | Dark |
|---|---|---|
| Primary (iris) | `#574bc4` (strong `#463ba6`, soft `#f0eefc`) | `#a49cf2` |
| Accent (champagne gold) | `#8f6420` (`#c9a25a` decorative) | `#e2b86b` |
| Canvas | `#f6f6fa` | `#0c0e1d` |
| Surface | `#fff` | `#13152b` |
| Text | `#15172e` | `#eceaf8` |
| Rail | midnight `#12152e` → `#1b1e42` | `#0a0c1c` |

- **Category tiles** use muted jewel tones: rose, emerald, sapphire, amber, violet, teal, gold and indigo.
- **Status colours** for success, warning, danger and info are dark enough for small text on white and on their soft fills.
- **Contrast** of every text pair was measured; all are ≥ 4.5:1. The table is in Design System §2.2.
- **Filament palettes** are hand-tuned in OKLCH so that Filament's buttons use exactly the brand iris.

## 7. Typography

| Use | Typeface |
|---|---|
| Interface | Instrument Sans (400 / 500 / 600) |
| Display (titles, greeting, assistant hello, banner) | Instrument Serif |
| Code | JetBrains Mono |

- **Scale:** Display, H1, H2, H3, Body, Body small, Caption, Label (overline), Metric, Metric large, Code.
- **Numbers:** tabular figures for every number.

## 8. Navigation

**Nine sections** (`ExperienceNavigation`): Home, My work, People, Organisation, Insights, Workflows, Services, Communication and Admin.
- A section appears only when the person can open something in it.
- Each destination is checked with its own `canAccess()` (`ModuleCatalogue`).
- The active section expands into its areas, which form the context rail.

**Where modules went.** The 30 Filament navigation groups and ~180 modules moved to:
- a **space bar** on every module page, showing the other modules of that area plus the current section and area;
- the **Admin Centre** (all modules, filterable);
- the **command center**.

**Personal pages** (My HR, My day, My career, My learning, My compensation, Preferences) moved to the avatar menu. They link to each other through a "Your pages" bar.

**Navigation by role** (verified by the persona matrix in section 28):
- employees see Home / My work / People / Organisation / Services / Communication;
- tenant admins see all nine.

**Mobile.** A bottom navigation with Home / Work / People / Services / Profile.

## 9. Command Center

**Opening it.**
- Press ⌘K or Ctrl+K, or "/".
- Press "N" to open it in actions mode; the "New" button does the same.
- Tab switches the scope: Everything / Actions / People.

**Results** come grouped:
- smart answers;
- actions;
- people;
- modules ("Go to");
- requests;
- policies and knowledge;
- documents;
- organisation;
- workflows and reports;
- HR services.

**Row actions.** A person result carries Open profile, Preview, Journey, In org map and Start a change. These sit in a toolbar below the listbox, so it stays a valid ARIA listbox.

**Recents** are kept per person and re-resolved against current access on every render.

**Smart answers (`IntentSearch`).** A fixed, reviewed set of patterns:
- joining soon;
- probation ending;
- pending leave;
- on leave today;
- pending approvals;
- certificates expiring;
- open requests;
- compensation changes this month;
- completed training;
- people reporting to someone;
- headcount.

Each pattern maps to one permission-checked query. There is no natural-language-to-SQL; hostile text produces nothing (tested).

**Quick actions (`QuickActions`).** Each opens an existing page or an existing Filament action through `?action=`, so the forms and services are unchanged.

## 10. Home

Home is role-aware (`HomeComposer`, `HomeBlocks`). It contains:
- **Hero:** an illustrated greeting with a lens switch (For you / Your team / People operations / HR admin / Payroll / Company / Platform).
- **First-login welcome:** dismissible.
- **KPI strip:** tiles per lens, including a leave-balance ring.
- **Next best actions:** up to three, with Review / Continue / Open and "Not now" (snoozes until tomorrow).
- **Your day:** one-on-ones, training sessions, approved leave and things due today. No invented meetings.
- **Your journey** (employee) or **Team pulse** (manager).
- **Role panels:**
  - HR: people operations, each with the reason it matters;
  - executive: workforce pulse;
  - payroll: the current run and its exceptions;
  - admin: platform health.
- **Key insights:** attendance by month and learning completion, for self or team, each gated by the matching permission.
- **Company pulse:** announcements.
- **Start something:** quick actions.
- **Right rail:** the assistant, Today (check in or out, request leave, payslip) and "What changed?".
- **Organisation banner:** shows only figures the viewer may see.

## 11. My Work

One inbox (`WorkInbox`) with Needs attention / Today / Upcoming / Waiting on others / Completed.
- **Approvals** come from the Approval Center.
- **Tasks** come from `ExperienceTasks`; workflow tasks are de-duplicated into the Approval Center.
- **Reminders** come from `NeedsAttention`.
- **Waiting on others** is the person's own open leave, corrections, HR requests, letters and compensation proposals.
- **Keyboard:** J/K to move, Enter to open.

## 12. Approval Center

**Read model (`ApprovalCenter`).**
- Covers workflow tasks, leave, leave cancellations, attendance corrections, compensation changes and letters.
- An item appears only when the viewer's existing policy lets them decide it:
  - the requester never sees their own item;
  - organisation and relationship scope are applied;
  - compensation separation of duties is kept;
  - the letters rule (the requester never approves) is kept.
- Groups: Urgent / Today / Upcoming / Completed.
- Each item shows what, who, why, impact, effective date, risk, requester, and Before → After where it applies:
  - leave balance before and after;
  - corrected attendance times;
  - CTC before and after, only when `CompensationAccess` allows.

**Decisions (`ApprovalDecisions`).**
- The item is re-resolved and re-authorised on the server.
- The decision must be one the item offers, and rejecting needs a reason.
- The decision goes to the owning service: `WorkflowEngine`, `Leaves`, `Regularisations`, `CompensationChanges` or `Letters`. Each re-checks its own rules.
- "Request change" exists only where a domain supports it (compensation: return to proposer).
- Keyboard: A to approve, R to reject.

## 13. Employee 360

**Header.** Avatar, name, role, department, location, lifecycle status, tenure and manager (opens a drawer). Actions are grouped as Message · Request · Action · More. Every existing action keeps its name, authorisation and service.

**Request.** Adds two actions:
- an agent-only "raise an HR request" (the existing action, pre-filled);
- "generate a letter" (the Letters service, with the same rule as the Letters screen).

**HR assistant.** "Summarise" opens the HR copilot.

**Sections.**
- **People snapshot:** key facts, with every previous field under "All details".
- **Journey.**
- **360 overview:** grouped Today / Growth / Wellbeing / Rewards / Operations.

**Record tabs.** The thirty tabs are grouped as:
- Timeline;
- Employment;
- Time;
- Growth;
- Rewards;
- Operations;
- Personal ("Bank & personal" when bank details are visible);
- History.

**Before → After** previews on Transfer / promote and Change manager.

## 14. Employee Journey

`JourneyMap` shows ten stages, Preboarding → Alumni, built from existing data:
- lifecycle transitions and state;
- position changes;
- the onboarding plan;
- the exit case;
- the timeline.

Each stage shows its events. The rules:
- **Timeline categories:** events pass the same per-category visibility as the 360 timeline.
- **Sensitive categories** need `employee.sensitive.view`, including for the person themselves.
- **Exit details** need `exit.view`.
- **Who sees a journey:** the person themselves, or anyone who may open their 360.
- **Where it appears:** a compact four-node version on the employee's Home.

## 15. People Directory

The `People` page offers:
- cards, or a compact list for HR (it follows the density setting);
- search by name, ID or email;
- filters for department, location, status and manager;
- filter options drawn only from people the viewer can see;
- filters held in the URL;
- a quick-preview drawer for every person;
- two columns on phones.

**Visibility (`PeopleVisibility`).**
- With `employee.view`: the employee list as it already is. The organisation scope is applied explicitly for scoped viewers.
- Without it: the viewer's own circle (self, manager, direct reports).

## 16. Organisation

`OrganisationMap` is a reporting tree that loads level by level:
- large teams show "+N more";
- find-and-focus opens the chain to a person;
- zoom and pan work by mouse, touch and keyboard;
- the map opens centred;
- clicking a person opens a preview.

A department view shows headcount and, with a workforce permission, open positions. The Organisation Designer and the module pages stay one click away.

## 17. Analytics

The Workforce Command Center is organised around four questions:

1. **What changed:** month over month, with direction coloured by meaning (more leavers is red).
2. **What needs attention:** risks above thresholds, each with a reason (attrition, absenteeism, mandatory learning, skills without an expert, grievances).
3. **What is trending:** headcount; joiners against leavers; people cost when permitted.
4. **What requires a decision:** your approvals, plans in review and configuration changes.

Every figure comes from `WorkforceMetrics`, with its permission and small-population suppression. Charts are SVG or CSS with text alternatives; there is no chart library.

## 18. AI

`AiAssistant` is a thin presentation over the existing `AiGateway`, which already enforces:
- feature flags;
- assistant permissions;
- the rate limit;
- the external-model data boundary;
- audit.

**Where it appears.**
- A card in the Home rail.
- A right-hand panel from the rail footer.
- In context:
  - "Summarise" on the 360 (HR Copilot, through the Employee 360's own rules);
  - "Summarise what is pending" on Approvals (manager).

**Answer format.** Answer / Key facts / Sources / Suggested actions, with an "Assistive" badge, how the answer was produced, and helpful / not helpful feedback.

**Boundaries.**
- Suggested actions only open screens.
- The panel states that no employment, pay or compensation decision is made there.

## 19. Forms

Filament forms are kept (validation, services, audit reasons) and restyled. Additions:
- **Leave form:** a live preview of working days, from the same `LeaveDayCounter`, and the balance before → after. This shows wherever leave is requested.
- **Transfer / promote and change manager:** a live field-level Before → After with the effective date.
- **Guided change flow from a person:** a Stepper (choose → fill in → review Before → After → confirm). It opens only the 360 actions the viewer is authorised for.
- **First-login welcome.**

## 20. Motion

| Kind | Duration | Examples |
|---|---|---|
| Micro | 150–200 ms | Hover, press, chips, check-draw |
| Medium | 280 ms | Staggered card entry, page enter, drawer slide |
| Large | 360 ms | Meter fill, success check |

- **Interactions:** the command center scales in; drawers slide in (bottom sheets on phones); decided approval cards and work rows leave with `wire:transition`; the Today card pulses after check-in.
- **Reduced motion:** everything collapses to 1 ms, and scrolling is not smooth.

## 21. Mobile

Mobile is designed rather than shrunk:
- bottom navigation with 48 px targets and an approvals badge;
- the search icon opens the same command center;
- on Home, Today (check in, request leave, payslip) comes right after the KPIs;
- drawers become bottom sheets;
- two-column people cards;
- the org map is touch-pannable;
- the command center works at full width.

All were reviewed at 390 × 844 (references 15–17).

## 22. Dark mode

Dark mode is a separately designed token set, not an inversion:
- midnight surfaces;
- a lighter iris;
- a gold accent;
- jewel tiles at 14–16 % fills;
- hairline elevation instead of shadows;
- a dusk hero.

A test checks that every light semantic token has a dark counterpart (section 28). The rail is midnight in both themes. References 03, 10 and 17 show it.

## 23. Accessibility

**Audit.** An axe-core 4 audit (WCAG 2.0 / 2.1 / 2.2, A and AA) ran across:
- Home, My work, Approvals, People, Org map, Employee 360, Notifications, Workforce Command Center, Admin Centre and Preferences;
- the employee, manager and HR personas, in light and dark;
- the open command center, person drawer, assistant panel and shortcut sheet.

The final run reports **no violations**. Fixes made along the way:
- journey tab semantics;
- command options no longer contain interactive content;
- the org-map toggle meets the 24 px minimum target size.

**Keyboard.**
- Ctrl/⌘K, "/", "N" and "?".
- G then H/W/A/P/O to go to Home, My work, Approvals, People or the Org map.
- J/K to move, Enter to open, A/R to decide.
- Esc closes.
- Shortcuts pause while typing or while a modal or drawer is open. The A/R shortcut was verified in a browser.

**Structure.** A skip link, focus traps with focus returned to the trigger, ARIA listbox and combobox, tablists, live regions for skeletons and AI, and status never shown by colour alone.

**Contrast.** Every text pair is AA (measured).

## 24. Performance

Median server response on the development server (PHP built-in server, debug on, so absolute numbers are pessimistic), before → after scoping the experience read models per request:

| Screen | Before | After |
|---|---|---|
| Home | 669 ms | 384 ms |
| Approvals | 494 ms | 230 ms |
| People | 527 ms | 280 ms |
| Employee 360 | 635 ms | 386 ms |
| Leave requests (Filament list) | 523 ms | 275 ms |
| SPA navigation to My work | 914 ms | 610 ms |

**How the gain was made.** The read models (module catalogue, lenses, preferences, navigation, visibility, approvals) are scoped per request and forgotten after every handled request. The shell therefore builds them once per page.

**Other measurements.**
- **Command search:** results render about 14–20 ms after the 120 ms debounce on an idle server.
- **Lazy loading:** "What changed" loads lazily with a layout-true skeleton.
- **Asset sizes:**
  - theme CSS: about 82 KB gzipped (most of it is Filament's own CSS);
  - interaction script: 2 KB gzipped;
  - no chart or JS framework added.
- **Org map:** level-by-level loading keeps it bounded.

**Not measured:** production hardware, opcache and Redis, and real mobile networks (section 29).

## 25. Security

The order Tenant → Permission → Organisation scope → Relationship scope → Field security → Record is preserved. The presentation layer grants nothing.

**Read models** (ApprovalCenter, WorkInbox, HomeComposer / HomeBlocks, ChangeFeed, CommandSearch, IntentSearch, PeopleVisibility, JourneyMap) read through:
- existing policies;
- `AccessScopes`;
- `CaseAccess`;
- `CompensationAccess`;
- `TimelineCategories`;
- `WorkforceMetrics` permissions.

**Fail-closed rules.**
- `QuickActions` and `ModuleCatalogue` answer only for the signed-in user, because destinations use static `canAccess()`.
- Every drawer and approval id is re-resolved on the server.
- Notification deep links resolve only when the record is in the tenant and the viewer may open it.

**Blocked URLs, not hidden UI.** Unauthorised pages return 403 or 404. Actions mounted from a URL (`?action=`) stay hidden without permission. These are covered by tests.

**Data written by this work.**
- Personal display preferences.
- Anonymous, whitelisted daily counters (`ux_metrics`): no user id and no content.
- Presentation metadata on in-app notifications (group, event, source).

**Architecture exemptions.** Two documented exemptions were added to the audit architecture test:
- preferences are not business records;
- metrics must stay anonymous.

**Limits held:**
- no RMS dependency or recruitment functionality;
- no statutory bypass;
- no natural-language-to-SQL;
- the AI stays assistive.

## 26. Screens and components redesigned

**New pages:**
- Home (replaces the dashboard)
- My work
- Approvals
- People
- Organisation map
- Notifications
- Admin Centre
- Preferences

**Redesigned screens:**
- Employee 360 (header, snapshot, journey, grouped overview, grouped tabs)
- Workforce Command Center
- every module page (space bar, restyled tables, forms, sections, badges, buttons and modals)
- error pages (403 / 404 / 419 / 429 / 500 / 503)

**New shell:**
- brand mark and wordmark;
- the midnight context rail with nine sections, pins, recents and the assistant card;
- the centred command trigger;
- the "New" quick launch;
- the space bar;
- the drawer, AI and command hosts;
- bottom navigation;
- the shortcut sheet;
- the skip link.

**Components (design system §11, all 25 implemented):**
- PeopleCard
- Avatar
- Status
- Metric
- Timeline
- Drawer
- Command
- Action
- Approval
- Journey
- Insight
- Change
- Chart (sparkline, bars, columns, donut, ring)
- Table
- Empty
- Skeleton
- Notification
- Search
- Filter
- Modal
- Sheet
- Stepper
- BeforeAfter
- OrgMap
- AIResponse

## 27. Screenshots and visual references

There are twenty references in `docs/ux/visual-regression/`, with an index and `capture.mjs`. They were taken from a disposable showcase database of fictional people (`UxShowcaseSeeder`, which refuses any database not named `*_showcase`). They cover:
- Home for each lens, in light and dark;
- Approvals and My work;
- the command center;
- the 360 in light and dark;
- People, the org map and the Workforce Command Center;
- notifications;
- three phone views;
- the person drawer, the assistant panel and the Admin Centre.

## 28. Tests

**Full suite (final run, at `cfaa3ed` plus this report):** 987 tests, 927 passed, 60 skipped, 0 failed, 11,877 assertions, 457 s (`php artisan test --parallel --processes=6`, SQLite in-memory). The 60 skipped tests are the MySQL-only concurrency and race tests, which need a dedicated MySQL database and were not part of this run. No existing test was removed or weakened.

**New tests in `tests/Feature/Experience`:**
- `UxApprovalsTest` (8)
- `UxSecurityTest` (8)
- `UxShellTest` (10)
- `UxPersonasTest` (2)

They cover every §56 item:

| §56 item | Where it is tested |
|---|---|
| Role-specific home | Shell, Personas |
| Command search authorisation | Security |
| Employee 360 authorisation | Security, Personas |
| Drawer actions | Security |
| Approval actions | Approvals |
| Before → After preview | Approvals |
| Mobile layout (markup) | Shell |
| Dark mode tokens | Shell |
| Keyboard navigation (markup and script) | Shell; the browser was also checked |
| Reduced motion | Shell |
| Sensitive-field hiding | Security |
| Cross-tenant navigation | Security, Approvals |
| Unauthorised deep links | Security, Personas |

**The 7-persona matrix** checks Employee, Manager, HR, HR Admin, Executive, Payroll and Admin, with real roles, against eight URLs.

**Existing tests.** None were removed or weakened. Two existing files changed:
- the documented audit exemptions in `ArchitectureTest`;
- `TestCase::withoutVite()`.

**§50 click targets**, verified in a browser on the showcase:

| Flow | Clicks | Target | Evidence |
|---|---|---|---|
| Request leave | 2 | ≤ 3 | Row created |
| Approve | 3 | ≤ 2–3 | Approved by the manager |
| Find employee | 2 (directory), 0 (Ctrl K + Enter) | ≤ 2 | Browser |
| Start an action | 2 | ≤ 3 | Browser |
| Understand workforce change | 1 | ≤ 3 | Browser |
| Payslip | 1 | ≤ 2 | Not exercised: no demo payslip |
| Raise HR request | 2 | ≤ 3 | Ticket created |

**Final UX audit (§58).**

| # | Question | Answer |
|---|---|---|
| 1 | Does this look like a traditional HRMS? | Signature surfaces: no. Deep administrative module pages: partly; they are classic list and form screens, now restyled. |
| 2 | Does it feel like Filament? | Signature surfaces: no. Module CRUD screens: partly. **This is why the status is PARTIAL.** |
| 3 | Does navigation require module knowledge? | No for common work (nine sections, command center, Home actions). Rare modules still need the space bar or Admin Centre. |
| 4 | Is the employee the primary object? | Yes: 360, journey, drawers, people search with row actions, Home. |
| 5 | Can users understand what needs attention immediately? | Yes: ranked, with reasons, on Home and in My work. |
| 6 | Are workflows contextual? | Mostly: approvals with context, change flows from a person, Before → After. Workflow design screens are unchanged. |
| 7 | Does the product feel premium? | Yes on the new surfaces (palette, serif display, illustration, motion). |
| 8 | Does it feel coherent? | Yes: one token system across the shell, new pages and Filament; a few deep pages show denser Filament layouts. |
| 9 | Does motion improve understanding? | Yes: where things came from and that they worked; no decorative loops. |
| 10 | Does dark mode feel designed? | Yes: separate tokens, a dusk hero, a midnight rail. |
| 11 | Does mobile feel intentional? | Yes for employee flows; heavy admin tables remain desktop-first. |
| 12 | Is information density appropriate? | Yes: comfortable by default, compact for operators. |
| 13 | Are tables excellent? | Good, not excellent: restyled, sticky, density-aware, keeping Filament's sort, filter, search, column toggle and bulk features. Saved views and inline preview are not built. |
| 14 | Are forms approachable? | Improved: live previews and a guided change flow. Long configuration forms are unchanged. |
| 15 | Are analytics understandable? | Yes for the Command Center (four questions, reasons). Older analytics pages are restyled only. |
| 16 | Is AI contextual? | Yes: Home, 360 and Approvals, with sources and boundaries. |
| 17 | Does it feel different within 30 seconds? | Yes: Home, the rail, Ctrl K and the 360 make the difference obvious. |

## 29. Known limitations

- **Module CRUD screens** (~150 resources) remain Filament list, form and view pages: restyled, with the PeopleOS shell and space bar, but structurally Filament. This is the main reason the status is PARTIAL.
- **Tables:**
  - no saved views, inline row preview, or keyboard row navigation inside Filament tables;
  - column control, filters, sorting, pagination, bulk actions and export are Filament's, where each resource already configures them.
- **Personalisation:** "favourite reports" and reordering quick actions are not built. Density, lens, pins, recents, hiding and restoring Home cards, and "Not now" are built.
- **Approvals:** "Request change" exists only for compensation (return to proposer). Other domains have no "send back" state, so rejecting with a reason is the path. There is no bulk approve and no undo; decisions confirm instead.
- **Notifications:** the Filament bell is still the quick view. The Notification Center is reached from the My work rail and search, not from the bell. Group metadata exists only on notifications stored after this change; older ones fall into "Updates".
- **AI** uses the existing assistants (mostly deterministic answers, plus an external model only where the tenant enables it). Answer quality is the assistants', not new.
- **Performance** was measured on a development server, not production. Mobile was checked in Chromium emulation, not on real devices.
- **Screenshot dates:** some references show "3 October" from the server clock around midnight UTC.
- **Showcase data:** the seeder's sample leave requests are refused by real rules (probation, a weekend date). The click verification created its own.

## 30. Deferred items

- A PeopleOS treatment of the Filament list page:
  - a header summary;
  - saved views in the URL;
  - inline preview rows;
  - keyboard row navigation.
- Wiring the Filament bell to the Notification Center, and digest preferences.
- Bulk "approve all similar", with per-item checks.
- Favourite reports, and personal quick-action ordering.
- Real-device mobile testing, and a production-like performance run (opcache, Redis, queue workers).
- A public accessibility changelog page.
- A pixel-diff job in CI for the visual references.

## 31. Git status

**Baseline:** `b25b5e4`. Commits on `feature/oct_1_phase_1`:

| Commit | Slice |
|---|---|
| `0501815` | UX.1 research and design system |
| `29ae815` | UX.2 shell and role-adaptive navigation |
| `4c5652a` | UX.3 command center, smart intents, quick actions |
| `4bbf335` | UX.4 Home, My work, Approval Center |
| `08f9b55` | UX.5 Employee 360 and journey map |
| `adb090d` | UX.6 People directory and org map |
| `a20cc73` | UX.7 Workforce Command Center |
| `13273e1` | UX.8 contextual AI |
| `080f6bd` | UX.9 drawers, guided change, leave preview |
| `0c9afbe` | UX.10 notification center and micro-interactions |
| `2ec16ae` | UX.11 responsive and mobile |
| `0307ff3` | UX.12 dark mode and accessibility |
| `8a4c677` | UX.13 tests and performance |
| `cfaa3ed` | UX.13 references, personas, welcome, error states |
| UX.14 | This report and the final audit (the commit that adds this file) |

**About the slices.** UX.2–UX.6 were developed as one integrated shell and committed in phase slices. Intermediate commits reference components from later slices; the branch head is the consistent state.

**Final test run:** 987 tests, 927 passed, 60 skipped (MySQL-only), 0 failed, 11,877 assertions.

**Not done, by instruction:** nothing was pushed, merged or deployed, and history was not rewritten. The development database `hcm` was not modified beyond running the new additive migration. Visual work used the disposable `hcm_ux_showcase` database.
