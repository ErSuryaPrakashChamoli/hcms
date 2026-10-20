# UX.17 Mobile and Responsive Experience Report

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** UX.17 mobile and responsive experience. Not UX.18 (performance, accessibility and visual-regression gates) or UX.19 (sign-off). Nothing was pushed, merged or deployed.

Companion documents: the [UX.17 audit](UX-17-Mobile-Responsive-Audit.md) (findings M1–M26, written before any change) and the evidence in `docs/ux/ux17/`.

## 1. Executive summary

PeopleOS on a phone now follows the role, the same way the desktop does since UX.16. It is still one platform, one DOM, one security model and one design system: no separate mobile product, no mobile routes, no device sniffing.

- **A role-aware bar.** Each experience gets its own five places (for example Home · Approvals · Actions · Team · Work for a manager, Home · Admin · Actions · Users · Work for an administrator). Every place is a screen the person may already open; decisions are always counted on Approvals or Work; the bar follows a view switch at once.
- **The first screen answers the role's question.** On a 390 × 844 phone, HR's first operations row moved from 828 px (below the first screen) to 676 px, the administrator's first governance row from 737 px to 573 px, and every role's Home got shorter (manager 5.1 → 4.1 screens).
- **Overlays own the screen.** The bar no longer covers modal forms, the menu or sheets: the employee's Request leave form had its Submit row covered; now it is reachable and sticks to the bottom of the window.
- **Phone-shaped surfaces.** The Employee 360 viewer panel moved up (phone 1,793 → 1,040 px; inside the first screen on tablets). The directory list reads as cards instead of scrolling sideways. The command center is a full-screen sheet with Cancel that stays above the on-screen keyboard. Governance and operations rows are one tap target each. Long lists show three rows first, with the rest in place.
- **Zero-training (phone):** HR and the administrator now get their answer on landing (before: one tap and a screen change); the employee's leave form is fully usable (before: Submit covered); the other tasks are unchanged or clearer.
- **Validation:** browser matrix 214 of 216 loads pass (Chromium 72/72, the WebKit engine 72/72, Firefox 70/72; the two misses are the intermittent Firefox `[object Object]` UX.16 recorded, which the pre-UX.17 code shows too); targeted axe 58 checks · 0 violations, UX.16 set 46 · 0, UX.15 set 78 · 0; tests 1,074 tests · 1,014 passed · 60 skipped (MySQL-only) · 0 failed; MySQL 60/60; the security regression set 242/242.

Four pre-existing issues surfaced and were fixed along the way (the phone search button had no accessible name; the notification center's unread dot and read rows failed axe; the bar covered modal footers). Two remain for later: the 360 header is long on phones, and Android Back leaves a page rather than closing an open sheet (§24).

## 2. Starting commit

`1220a27` "docs: align the UX16 handoff figures with the measurements" (UX.16 complete).

## 3. Ending commit

The commit that adds this report ("docs: UX17 mobile and responsive experience report"); code complete at `8d3d080`, evidence in the commit before it. 14 commits in UX.17:

| Commit | Change |
|---|---|
| `2b9dfa7` | ux: audit the mobile and responsive experience (UX.17 discovery) |
| `10fd4e2` | ux: give each role its own phone bar (UX.17 navigation) |
| `478bc4e` | ux: lay out Home and My Work for phones (UX.17) |
| `23b0dda` | ux: bring the Employee 360 viewer panel up on narrow screens (UX.17) |
| `a6683b0` | ux: directory cards and filters on phones, touch wording (UX.17) |
| `38c1281` | ux: command center as a full-screen sheet on phones (UX.17) |
| `9be2a9e` | ux: forms, notifications and touch targets on phones (UX.17) |
| `fd6aa31` | chore: realistic notifications for the showcase personas (UX.17.23) |
| `a2eb2fe` | test: cover the mobile experience and its boundaries (UX.17.26, 17.22) |
| `60226d3` | ux: accessibility fixes found on phones (UX.17.20) |
| `1f5494b` | ux: phone figures read as a line of facts (UX.17) |
| `8d3d080` | fix: name the bar's waiting count without Livewire block markers (UX.17) |
| `f272c3a` | test: UX17 browser, accessibility, zero-training and screenshot evidence |
| (this report) | docs: UX17 mobile and responsive experience report |

## 4. Discovery findings

The audit (written before any change) probed 36 pages per viewport across six personas at phone 390 × 844, small phone 360 × 780, tablet 768 × 1024 and desktop 1440 × 900, ran seven phone zero-training tasks and checked overlay stacking. Highlights:

- **No page overflowed sideways** at any viewport. The problems were hierarchy, reach and covering, not breakage.
- **P1:**
  - M1: the bar was the same for every role (Home, Work, Actions, People, Services);
  - M2: the bar covered modal form footers, the menu and the bell's sheet;
  - M7: role leads started 544–688 px down on phones;
  - M8: phone Homes were long (manager 5.1 screens);
  - M15: the 360 viewer panel was about two screens down;
  - M17: the directory list scrolled sideways for HR, payroll and administrators;
  - M19: the command center had no visible way out on phones;
  - M21: governance and operations rows wrapped to five or six lines in about 170 px.
- **P2/P3:** touch wording (Ctrl K, hover), the view switcher wrapping to three rows, filters wrapping, duplicate "New" buttons, small touch targets, a stretched tablet bar, thin notification data, back-button behaviour.

## 5. Mobile architecture decisions

| Decision | Why |
|---|---|
| **One DOM for every size**, laid out with the existing breakpoints (640, 768, 1024, 1280) | No duplicated markup to keep in sync or to audit for security; no device detection |
| **The bar is composed on the server** by `MobileNavigation`, from the experience (`RoleLens`) and each destination's own `canAccess()` | The bar cannot offer what the person may not open; fallbacks when a destination is refused; it fails closed for anyone but the signed-in user |
| **The bar is a small Livewire component** (`BottomNav`) that re-renders on `pos-experience-changed` | Home's view switch stores the preference; the bar used to keep the old view until the next page. A redirect would have skipped Home's own render |
| **Decisions always ride on Approvals or Work** | A view switch never hides a decision |
| **Overlays hide the bar** (any scroll-locked overlay, the palette, the menu) | The bar was above Filament modals in the stacking order and covered their footers |
| **Progressive disclosure in place** (`x-pos.phone-cap`): three rows first, "Show N more" toggles the rest, `aria-expanded`, focus kept | Shorter phone screens without removing anything or adding navigation |
| **Device wording by pointer** (`pointer: coarse`), not by width | Touch screens get "tap", mice and keyboards keep "hover" and "Ctrl K" |
| **No history entries for sheets** | Livewire's SPA navigation owns history; on Back it restores a cached page snapshot, which would discard live page state. Every sheet has a visible close control instead |
| **Desktop unchanged** except the 360 aside order (viewer panel above intelligence) | The audit's desktop screenshots are the regression baseline |

## 6. Role-by-role changes

| Role | Phone question | Bar | First screen now | Other changes |
|---|---|---|---|---|
| Employee | What matters to me today? | Home · Work · Actions · Services · People | For you (own items), Request leave | Touch wording; lists capped; the leave form's Submit reachable |
| Manager | What needs my decision, and how is my team? | Home · **Approvals** · Actions · **Team** · Work | Decisions with Review (opens the decision sheet), then Your team | Team opens My team; the 360 "Your team" panel follows Now |
| HR | What needs HR operational attention? | Home · Work · Actions · People · **Requests** | People operations: a line of facts and the first rows | Directory cards and filters behind one button; the 360 "People operations" panel follows Now |
| Executive | What is happening across my workforce? | Home · **Pulse** · Actions · **Org map** · Work | The workforce headline | Joiners and exits once (in the movement); no tiny trend chart on phones; org map touch wording |
| Administrator | What needs configuration, governance or system attention? | Home · **Admin** · Actions · **Users** · Work | Governance rows, each one tap | The 360 "Identity and access" panel follows Now |
| Payroll | What blocks the payroll run? | Home · Work · Actions · **Payroll** · People | The payroll run | Same caps and cards |

## 7. Mobile navigation

- `MobileNavigation::BAR` lists five slots per experience; each slot names candidates in order (for example the manager's second slot is Approvals, else Work). A candidate appears only if its own `canAccess()` allows it; a slot with no allowed candidate is dropped.
- **Decisions:** the count rides on Approvals when it is in the bar, otherwise on Work (and Work then also marks the Approval Center as its own place). Screen readers hear "Work, 6 waiting".
- **Current place:** `aria-current="page"` on the first matching item; the bar keeps the page's route from its first render.
- **One way to start something** where the bar shows: the centre Actions button. The top bar's New and Home's "Start something" now appear with the rail layout only (1024 px and up).
- **Tablets** get a centred, floating bar (560 px) instead of five 150 px cells.
- **Overlays:** the bar steps aside while a modal, the menu, the palette, a drawer or the assistant is open.
- **Unchanged:** the `nav` landmark (`aria-label="Primary"`), search, the bell and the avatar in the top bar, the full navigation in the menu.

## 8. Home changes

- **Compact phone header:** the brief at body size, one primary action (the role's own: Review decisions, Request leave, Open Workforce pulse, Open Admin Centre), "View as" on one scrolling row with the chosen view scrolled into sight.
- **Welcome note:** touch screens read "Search finds anyone; the + button starts anything. Tap a name for more." and lose the Shortcuts button; desktop keeps the keyboard wording.
- **Role signal rows** (For you, Your team, People operations, Governance): on phones the count sits inline, the text takes the full width, and the whole row is the link (same URL, same accessible name such as "Review: Multi-factor sign-in is not required"), with a chevron.
- **Lists** (decisions, need attention, your day, my requests, what changed, signals): three rows first, then "Show N more" in place. While a list is capped, its "N more in the Approval Center" link waits until the rest is shown; the section header already links there.
- **Intelligence:** the first insight, then "Show N more insights".
- **Figures** (HR operations, the 360 Now panel): on phones a wrapped line of facts, value beside label; nothing is hidden and nothing scrolls.
- **Executive:** joiners and exits appear once (in the movement), and the six-month sparkline stays on Workforce pulse and desktop.

| Phone 390 × 844 (fold 766) | First lead row, before → after | Home length, before → after |
|---|---|---|
| Employee | 572 → 525 px | 3.7 → 3.7 screens |
| Manager | 665 → 594 px | 5.1 → 4.1 |
| HR | **828 → 676 px** (into the first screen) | 4.3 → 3.3 |
| Executive | 657 → 589 px | 3.1 → 2.8 |
| Administrator | **737 → 573 px** | 3.5 → 3.0 |
| Payroll | (figures, no rows) | 2.8 → 2.4 |

On the small phone (360 × 780, fold 702) HR moved from 852 to 676 px and the administrator from 781 to 573 px. Same data on both sides (`probe-*-before-same-data.json`, `probe-*-after.json`). The welcome note was showing in both runs.

## 9. My Work changes

- Filters on one scrolling row on phones (they wrapped into two or three rows).
- The role lead (About you, Your team, People operations, Workforce, Governance) uses the same signal rows and caps as Home.
- "Need attention"-style rows with two actions put them under the text on phones.
- Unchanged: the streams, filters and their counts.

## 10. Employee 360 changes

One order for every size: **Now → the viewer panel (Your record, Your team, People operations, Identity and access) → intelligence → snapshot → Recently.** On desktop the aside spans both rows beside Now and Recently, with the viewer panel first. There is no duplicated markup; every fact keeps its own gate.

| Viewer panel top | Phone 390 | Small phone 360 | Tablet 768 (fold 945) | Desktop 1440 (fold 900) |
|---|---|---|---|---|
| Manager | 1,793 → 1,040 px | 1,883 → 1,093 | 1,554 → **916** | 798 → **381** |
| HR | 1,864 → 1,071 | 2,018 → 1,152 | 1,541 → **863** | 874 → **433** |
| Administrator | 1,950 → 1,136 | 2,104 → 1,217 | 1,598 → **920** | 874 → **433** |

On phones the panel is now about a third of a swipe into the second screen (it was two screens down). It stays below the Now panel on purpose: the person's lifecycle state comes first. The 360 header (actions over three rows) is the remaining height and is recorded for UX.19 (§24). G12 is unchanged: employees without `employee.view` still cannot open their own 360.

## 11. Directory and table transformation

| Surface | Treatment | Why |
|---|---|---|
| People directory, list view (the HR, payroll and administrator default) | **Cards under 640 px:** name and status, role, department · location. No sideways scroll (it hid 36–57 px and clipped Status) | Rows of people read as entries; the same query, fields and gates |
| People directory toolbar | **Filters behind one button on phones**, with the number of filters in use; search and view switch stay visible | Five controls took two rows before the list |
| People directory, cards view | Unchanged (already phone-shaped) | — |
| Filament module tables (employees, users, audit, requests, payroll runs) | Already stacked into entries under 640 px (UX.15 closure); UX.17 adds touch targets (32 px row actions, 24 px filter removal) | Columns, filters, sorting, pagination and actions unchanged; module grids stay UX.15 debt (UX.19) |
| Notification center | Text full width; Open, read and snooze under it | The text was squeezed to about 120 px |

## 12. Command and search touch experience

- **Phones:** a full-screen sheet sized to the visible viewport (tracked through `visualViewport`, so the on-screen keyboard never covers results), safe-area padding, and a **Cancel** button. Results stayed above the keyboard in zero-training (HR "Find Rahul Sharma": two taps and typing, before and after).
- **Touch:** row actions (Open profile, Preview, Journey, In org map) are 40 px on coarse pointers.
- **Desktop:** Ctrl+K/⌘K, the floating card, the keyboard hints and Tab-to-switch-scope are unchanged (keyboard review: Ctrl+K opens with focus in the input; Esc closes and returns focus).
- **Ranking and permissions:** the same `CommandSearch` (UX.16 role ranking, verbs gated by permission). No change.
- **The phone search button** now keeps its accessible name (UX.15 hid the label with `display: none`).

## 13. Governance and operations

On phones each item reads status (the count) → title → context (the reason) → action (the whole row, with a chevron). The link, its URL and its gate are the ones UX.16 built (each signal only for someone who may open the screen it summarises; platform figures for platform administrators only). Governance "At a glance" is unchanged. Every governance and reminder link shown on the phone Home for the employee, manager and administrator test personas opens with a status below 400 (test).

## 14. AI

- The assistant opens as a bottom sheet with its close button, role-ordered assistants and suggested questions, answers with key facts and sources, through the same gateway, permissions, AI data policy and audit.
- On phones it sits above everything (the bar steps aside). The Home intelligence card shows its first insight with "Show N more insights", so it never takes over the phone Home.
- Unchanged: no autonomous action, no scoring.

## 15. Notifications

- The bell opens Filament's sheet over the full screen; the bar no longer covers its bottom.
- On phones the notification center keeps the text full width, with actions under it.
- Deep links stay `NotificationCenter::linkFor`, checked by each record's policy:
  - the employee's own passport notice has no Open link, because her 360 is closed to her by permission (G12);
  - HR in scope gets a link, and HR out of scope gets none (tests).
- **Showcase data:** the personas had almost no notifications (manager 0). `UxShowcaseNotificationsSeeder` sends 15 in-app notifications through the real `Notifier` with the event names the platform emits. Each describes a real showcase record:
  - pending leave and a missed-punch correction (manager);
  - an HR request and its SLA, and an expiring passport (HR);
  - the same passport (employee);
  - a proposed configuration change (administrator);
  - an announcement (everyone).
- Role ordering (UX.16) is unchanged and now visible: "3 unread, approvals first for your role".

## 16. Multi-role handling

Phone run (`multi-role.json`), switching the Home view through every view held, without reloading:

| Person | Views held | Bar after each switch | Decision count |
|---|---|---|---|
| Kavya (administrator + all) | For you, Your team, People operations, Administration, Payroll, Company | employee → manager → HR → admin → payroll → executive bars, each its own | On Work, or on Approvals in "Your team"; 6 every time |
| Amit (employee + manager) | For you, Your team | employee ↔ manager | Work(6) ↔ Approvals(6) |
| Neha (employee + HR) | For you, People operations | employee ↔ HR | Work(6) |

- The stored default survives a reload.
- Only views the person holds can be chosen: `switchLens` and `setLens` refuse others, and nothing is announced (test).
- Search, the 360 and the directory stay scoped as before.
- The menu's section order (UX.16) follows on the next page.

## 17. Accessibility

Targeted checks (the full audit is UX.18). axe-core, WCAG 2.0/2.1/2.2 A and AA, light and dark:

| Run | Checks | Violations |
|---|---|---|
| UX.17 surfaces at phone (touch), tablet and desktop, including open states: palette sheet, modal form, open filters, an expanded list, the assistant, the menu (`axe-mobile.json`) | 58 | **0** |
| UX.16 set, re-run (`axe-ux16-regression.json`) | 46 | **0** |
| UX.15 closure set, re-run (`axe-ux15-regression.json`) | 78 | **0** (the HR person drawer re-checked with cards requested explicitly at desktop and phone, light and dark: 0; `axe-ux15-drawer-recheck.txt`) |

**Found and fixed during UX.17:**
- the icon-only phone search button had no name (pre-existing, UX.15);
- the unread dot used `aria-label` on a plain span, and read rows faded below text contrast (pre-existing; they surfaced with realistic notifications);
- a scrolling figure row would have needed a tab stop (introduced and replaced before commit).

**Keyboard and screen-reader semantics** (`keyboard.json`):
- **Desktop:** 30 tab stops on the manager's Home, all with a visible focus indicator.
- **Phone, with a keyboard:**
  - a signal row shows a ring when its link has focus, and the link is named "Review: …";
  - "Show N more" toggles `aria-expanded` and keeps focus;
  - the Filters button toggles `aria-expanded`/`aria-controls`;
  - the palette opens with focus in the input; Esc closes and returns focus; Cancel is a named, visible button;
  - the bar is a labelled `nav`, with `aria-current` and "Work, 6 waiting".
- Tab inside the palette input still switches scope (UX.15 design), so Cancel is a touch and swipe target and Esc is the keyboard exit.
- **Reduced motion:** the phone sheet uses the existing animations, which honour `prefers-reduced-motion`.
- **Touch targets** (probe, 36 pages per viewport): under 24 px went 78 → 30 on phones (71 → 23 on tablets); under 40 px went 862 → 678. Desktop is unchanged (143 and 1,142). The remaining small targets are mostly Filament module chrome (UX.18).

## 18. Performance observations

In-process server measurement, back to back on the same showcase data, median of three after a warm-up (`mobile-before.json`, `mobile-after.json`, `perf-repeat.txt`). The HTML is the same for every viewport.

- **Queries:** unchanged within one per page across 30 persona × screen pairs (the manager's Team destination adds one direct-reports existence check).
- **Payload:** +2–5 KB of HTML per page (the bar component and phone-only markup such as "Show N more" and touch wording); +≈550 bytes of Livewire snapshot (the bar component).
- **Time:** most pages within noise. Repeated alternately, HR Home measured about 40 ms slower (782 → 820 ms average) and the manager's My Work about 55 ms slower (518 → 573 ms). This is attributed to the bar component's mount and render and the extra Blade components. Not optimised in UX.17; handed to UX.18 with the existing HR Home hot spot.
- **Client:** no polling, no data fetched for phones only, no hidden desktop-only trees added. The palette tracks `visualViewport` only while open. No images were added.

## 19. Security validation

| Requirement | Evidence |
|---|---|
| Mobile never grants access | Every bar item is a destination whose own `canAccess()` allows it (test over six experiences); refused destinations fall back (test); the bar is empty when composed for anyone but the signed-in user (test); a mutation that removes the Users gate or the signed-in-user check fails the tests |
| No UI-only authorisation | The bar, caps, cards and sheets only arrange what the server already decided; no route, query or policy changed |
| Tenant isolation | Another tenant's administrator sees none of this tenant's people or bar destinations (test) |
| Organisation scope | HR limited to one company reaches only that company's people from the directory and the 360 (test) |
| Relationship scope | The manager's Team destination lists current reports only (test); an employee without people access cannot open another person's 360 (test) |
| Field security | No new fields; the 360 viewer panel keeps its UX.16 gates; the directory card shows the same columns as the list |
| Multi-role switching | Only held views are accepted; refused switches announce nothing (test) |
| Employee 360 access | Unchanged; G12 not resolved silently |
| Command actions | Same `CommandSearch`; unchanged |
| Notification deep links | Behind each record's policy: no link to an employee's own 360, none to HR out of scope (test) |
| Governance and reminder links | Every governance and reminder link on the phone Home opens for the person who sees it (test, three personas). G13 (hard-coded reminder paths) is not changed by UX.17 and stays an open decision |

The security regression set: **242 tests · 242 passed · 4,521 assertions.** It covers the security, tenancy, identity, audit, architecture and notifications folders, the UX.17 mobile tests, the UX.16 role, security and journey tests, the UX.15 approval authorisation and demo-scope tests, and the UX security and persona tests. No security regression was found.

## 20. Responsive test matrix

`browser-matrix.json`: each role's Home, My Work and its bar's own destination, six personas. Engines: Chromium, Firefox and the WebKit engine (Playwright WPE MiniBrowser 26.5; **not Apple Safari**, which was not tested). Viewports: phone 390 (touch), small phone 360 (touch), tablet 768 (touch) and desktop 1440. Firefox has no touch emulation in Playwright, so it ran at the same sizes without touch. A load passes on HTTP 200, no sideways overflow, no script error after settling, the bar shown on phones and tablets with at least four items, and hidden on desktop.

| Engine | Phone 390 | Small phone 360 | Tablet 768 | Desktop 1440 | Total |
|---|---|---|---|---|---|
| Chromium | 18 / 18 | 18 / 18 | 18 / 18 | 18 / 18 | **72 / 72** |
| Firefox | 18 / 18 | 18 / 18 | 18 / 18 | 16 / 18 | **70 / 72** |
| WebKit engine (not Safari) | 18 / 18 | 18 / 18 | 18 / 18 | 18 / 18 | **72 / 72** |

- No load overflowed sideways, and the bar was correct on every load: shown with its role's items on phones and tablets, hidden on desktop.
- The two Firefox misses are the manager's and the payroll lead's desktop Home, with `[object Object]` (below).
- An earlier run on the same code, before the last two CSS-only commits, passed 216 of 216 (`browser-matrix-run1.json`).

**Firefox `[object Object]` (UX.16 known issue):** re-tested with 16 loads of the manager's Home per server and width (`firefox-recheck.txt`). It occurred 0 of 16 times before UX.17 and 0 of 16 after, at desktop and at phone width, and in none of the matrix's Firefox loads. It is intermittent and load-dependent (UX.16 saw 1 and then 4 in 16), so it is **not declared fixed**; UX.17 did not make it worse. It stays with UX.18.

## 21. Before/after task evidence

Seven phone zero-training tasks on a 390 × 844 touch screen, the same heuristics and the same data on both sides (`zero-training-before-same-data.json`, `zero-training-after.json`, `zt17.mjs`). "First screen" means visible above the bar without scrolling.

| Role | Task | Before | After |
|---|---|---|---|
| Employee | "I need to request leave." | 1 tap to the form; **Submit covered by the bar** | 1 tap; Submit visible and sticky |
| Manager | "I need to approve this request." | 1 tap (Review) → decision sheet, Approve visible | Same |
| HR | "Which employee changes need attention?" | Not in the first screen; 1 tap (Work) and a screen change | **In the first screen, 0 taps** |
| Executive | "What is happening in my workforce?" | In the first screen | Same |
| Administrator | "What requires system attention?" | Not in the first screen; 1 tap to the Admin Centre (not the governance items) | **In the first screen, 0 taps** |
| Manager | "Show me my team." | 1 tap "People" → the directory, team first | 1 tap "Team" → My team |
| HR | "Find Rahul Sharma." | 2 taps and typing; results above the keyboard | Same; now a full-screen sheet with Cancel |

**Terminology:** the bar uses the words of the screens it opens (Approvals, Team, Requests, Pulse, Org map, Admin, Users, Payroll); touch wording replaces Ctrl K and hover. The next action is the first row or the primary button in every task; every task reached the right destination; none hit a dead end. Screenshots of each task's end state: `docs/ux/ux17/zero-training/` (7).

## 22. Screenshot inventory

**150** screenshots in `docs/ux/ux17/`, all fictional showcase data, first screen (what a person sees without scrolling):

| Set | Count | Contents |
|---|---|---|
| `before/` | 70 | Captured on `1220a27` code before any UX.17 change (before the notification seed). Phone 390: the five roles plus payroll: Home, My Work, My HR, the palette, the leave form, Approvals, My team, a report's 360, the HR directory, employees and requests tables, the assistant, the menu, the executive pulse, users, audit, the Admin Centre, notifications; small phone 360: five Homes; tablet 768: eight views; desktop 1440: ten regression views. Light, plus dark for the main ones |
| `after/` | 73 | The same 70 views, plus three after-only states: the directory filters open, a phone list expanded in place, the bell's sheet |
| `zero-training/` | 7 | The end state of each phone task (after) |

Capture lists: `before/capture-list.json`, `after/capture-list.json` (`m17-capture.mjs`). The evidence scripts import a local session helper (`auth.mjs`, which signs in the showcase personas) from the Playwright workspace; it is not committed.

## 23. Test results

| Run | Result |
|---|---|
| Baseline (`1220a27`, UX.16 final) | 1,062 tests · 1,002 passed · 60 skipped (MySQL-only) · 0 failed |
| **Final code** (`php artisan test --parallel`) | **1,074 tests · 1,014 passed · 60 skipped (MySQL-only, as at baseline) · 0 failed** · 13,320 assertions · 536 s |
| MySQL concurrency and scale suites (opt-in, `hcm_p14_concurrency`) | **60 / 60 · 245 assertions.** The first run had 59/60: one service-desk concurrency test hit a duplicate Faker e-mail in the persistent concurrency database (users accumulate across runs). It is not UX.17 code and passed on the rerun |
| Security regression set (§19) | 242 tests · 242 passed · 4,521 assertions |
| New UX.17 tests | 12 in `Ux17MobileTest` (bar per experience, gating and fallbacks, fail-closed, decisions on a switch, the bar following Home and Preferences, current place, 360 order, directory cards and filters, the palette sheet, phone caps and touch wording, tenant, organisation and relationship scope, notification deep links, governance and reminder links). Mutation checks: removing the Users gate or the signed-in-user check in `MobileNavigation` fails them |
| Existing tests changed | **None.** UX.17 kept the bar's class, landmark and labels that the UX.15 tests read. When a late markup change broke `UxShellTest` (Livewire's block markers inside the label span), the markup was fixed, not the test |
| Pint | Passed (`vendor/bin/pint --test`) |
| Static checks | `php -l` clean on all 27 changed PHP and Blade files; `php artisan view:cache` compiles every template; `npm run build` succeeds |

## 24. Remaining issues

**Open (none blocks UX.17):**
- **P2 · 360 header height on phones (M16):** name, role, badges, joined, reports-to, Summarise and four actions take about 380 px, so the viewer panel starts about a third of a swipe into the second screen. A tighter phone header is visual polish → UX.19.
- **P3 · Back while a sheet is open (M6):** Android Back leaves the page (the sheet goes with it). History entries per sheet would make Livewire restore a cached page snapshot. Every sheet has a visible close control. → UX.18 (browser behaviour).
- **P3 · The palette's Tab key switches scope (UX.15),** so Cancel is not a Tab stop from the input; Esc closes. Recorded for UX.19 acceptance.
- **P3 · Duplicated counts on the manager Home (M11):** decisions appear in Decisions waiting and again in Your team. Shortened by the caps; whether to merge them is a UX.19 decision.
- **P2 · Small targets in Filament module chrome:** 30 under 24 px remain across 36 phone pages, mostly module tables and filters → UX.18 (full accessibility audit).
- **Performance:** about +40–55 ms on two heavier pages in-process (§18) → UX.18.
- **Firefox `[object Object]`:** not reproduced, not declared fixed → UX.18.
- **Apple Safari** was not tested (WebKit engine only).

**Resolved:** M1, M2, M3, M4, M5, M7, M8, M9, M10, M12, M13, M14, M15 (moved up; see M16), M17, M18 (targets), M19, M20, M21, M23, M24 (targeted), M25 (validated).

**Cleanup:**
- The before worktree, which served the `1220a27` code on port 8091 for same-data comparisons, was stopped and removed.
- `hcm_ux_showcase` and the 8090 server stay.
- The personas' view preferences changed by the multi-role run were restored to their earlier values.

## 25. UX.18 handoff

- Comprehensive performance:
  - HR Home at scale (about 500 queries and 1.6 s at 10k, UX.16);
  - the People directory's database time for scoped HR and payroll;
  - the My Work regression from UX.16;
  - the UX.17 bar component cost (+40–55 ms in-process on HR Home and manager My Work).
- The full accessibility audit, including the remaining small targets in Filament module chrome and screen-reader runs on real devices.
- Automated visual regression for the UX.16 and UX.17 screenshot sets (`m17-capture.mjs` gives a starting list).
- Tablet browser coverage beyond the 768 px class and real-device runs, including Apple Safari (only the WebKit engine was tested).
- Back-button behaviour for sheets under Livewire SPA navigation.
- The intermittent Firefox `[object Object]` from Filament's notification lazy load (not reproduced in UX.17, not declared fixed).

## 26. UX.19 handoff

- **G12:** employee access to their own Employee 360 (owner decision; unchanged).
- **G13:** permission checks for reminder links (not touched by UX.17; the phone Home links opened for the test personas).
- Whether payroll is its own experience (UX.17 gives it its own bar: Home · Work · Actions · Payroll · People).
- Final acceptance criteria per role: the phone zero-training tasks in §21 are a candidate set.
- Administration view consolidation (HR admin + system admin as one bar and Home).
- A compact phone header for the Employee 360 (M16).
- Whether the manager Home should merge the decision counts (M11).
- The UX.15 debt: module grids remain standard Filament components (UX.17 only adjusted touch targets).

## 27. Final verdict

**UX.17 — COMPLETE**

Every completion criterion in the brief is met: the audit, the five role experiences, the role-aware bar and Home, My Work, the Employee 360, the dense lists, governance and operations, touch search, notifications, AI and multi-role switching. Security boundaries are intact, with no UI-only authorisation and no major desktop regression. Phone, tablet and desktop were tested in light and dark. The responsive matrix passes apart from the known, pre-existing, intermittent Firefox error. Targeted accessibility is clean, and the build, Pint, PHP syntax and the full suite pass. Before/after evidence and zero-training are captured, the audit, report and handoffs are written, and nothing was pushed, merged or deployed. The open items in §24 are P2/P3 and belong to UX.18 or UX.19.
