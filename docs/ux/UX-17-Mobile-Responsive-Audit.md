# UX.17 Mobile and Responsive Audit

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Starting commit:** `1220a27` (UX.16 complete) · **Scope:** UX.17 mobile and responsive experience. Not UX.18 or UX.19.

This audit was written before any UX.17 code change. Sources:
- the UX.16 audit and refinement report, read against the code (`HomeComposer`, `RoleLens`, `ExperienceNavigation`, `RoleSignals`, the Home partials, `bottom-nav.blade.php`, `command-center.blade.php`, `peopleos.js`, `workspace.blade.php`, `people.blade.php`, `approvals.blade.php`, the theme);
- a probe of every role's main surfaces at four viewports (`ux17/evidence/m17-probe.mjs`). For each page it records HTTP status, sideways overflow, the headings in the first screen above the bottom bar, where the role's lead section starts, page length in screens, touch targets under 24 px and 40 px, sideways-scrolling regions, the bottom bar and script errors (`probe-phone-before.json`, `probe-other-before.json`);
- 70 "before" screenshots in `ux17/before/`: first screen, light and dark, phone, small phone, tablet and desktop;
- seven mobile zero-training tasks on a 390 × 844 touch phone (`zero-training-before.json`);
- a stacking check of the overlays against the bottom bar.

## 0. Baseline

| | |
|---|---|
| HEAD | `1220a27`, clean tree, 115 commits ahead of origin, never pushed |
| Tests at start | 1,062 tests · 1,002 passed · 60 skipped (MySQL-only) · 0 failed (UX.16 final run) |
| Personas (showcase, fictional) | Priya (employee), Amit (manager), Neha (HR), Meera (executive), Kavya (administrator), Arjun (payroll). All are multi-role (every one is also an employee) |
| Viewports | Phone 390 × 844 (touch); small phone 360 × 780 (touch); tablet 768 × 1024 (touch); desktop 1440 × 900 |

## 1. Responsive infrastructure today

| Piece | What exists | Where |
|---|---|---|
| Breakpoints | 640 (tables stack), 768 (phone tokens, header stacking), 1024 (rail and bottom bar swap), 1100/1280 (two columns) | `theme.css` |
| Phone tokens | Gutter 16 px, section gap 24 px, card padding 16 px under 768 px | `theme.css` §1 |
| Touch | `pointer: coarse` raises small buttons, chips and stream rows to 40–52 px | `theme.css` "UX.15 touch" |
| Bottom bar | Five items for everyone: Home, Work (decision badge), Actions (opens the palette on actions), People, Services. Hidden at 1024 px and up | `shell/bottom-nav.blade.php` |
| Top bar on phones | Menu, search (icon), notifications bell, "New" (+), avatar | Filament top bar + `command-trigger`, `quick-launch` |
| Drawers | Right-hand drawers become bottom sheets (88 vh) under 768 px | `.pos-drawer` |
| Filament tables | Rows stack into readable entries under 640 px; row actions pinned on wider screens | `theme.css` §D |
| Approval Center | Decision pane beside the queue at 1280 px and up; a bottom-sheet drawer below | `approvals.blade.php` |
| Command palette | A floating card 8 px from the top on phones; list up to 56 vh; keyboard hints hidden on phones | `command-center.blade.php`, `.pos-command` |
| Employee 360 | One workspace; Now holds a main column and an aside (intelligence, the UX.16 viewer panel, snapshot) | `employees/workspace.blade.php` |
| Tests | Two Pest tests read the bottom bar (`UxShellTest`, `Ux15NavigationTest`); browser runs live in the evidence scripts | `tests/Feature/Experience` |

## 2. What the probe found (before)

**No page overflows sideways** at any of the four viewports (all 0 px). All pages return 200. The problems are hierarchy, reach and covering, not breakage.

| Role | Home lead starts (phone 390, fold 766) | Home length (phone) | Home lead (small phone 360, fold 702) |
|---|---|---|---|
| Employee | 544 px | 3.7 screens | 544 px |
| Manager | 616 px | 5.1 screens | 616 px |
| HR | 592 px | 4.3 screens | 616 px |
| Executive | 592 px | 3.1 screens | 636 px |
| Administrator | 688 px | 3.5 screens | **732 px (below the fold)** |
| Payroll | 592 px | 2.8 screens | 592 px |

"Lead starts" is where the role's own first section begins. Its first row starts about 60 px lower.

**Zero-training (phone, before)**:

| Role | Task | Taps | Answer in first screen | Note |
|---|---|---|---|---|
| Employee | "I need to request leave." | 1 | — | The form opens, but **its Submit button is covered by the bottom bar** |
| Manager | "I need to approve this request." | 1 | — | Review opens the decision sheet; Approve is uncovered |
| HR | "Which employee changes need attention?" | 1 (Work) | No on Home | People operations are below the first screen on Home; My Work shows them |
| Executive | "What is happening in my workforce?" | 0 | Yes | The headline is in the first screen |
| Administrator | "What requires system attention?" | 1 (Open Admin Centre) | No on Home | Governance rows are below the first screen; the tap leads to the Admin Centre, not to the governance items |
| Manager | "Show me my team." | 1 (People) | Yes | The bar says People; the directory lists the team first |
| HR | "Find Rahul Sharma." | 2 + typing | — | Results stay above the on-screen keyboard |

## 3. Findings

Severity: **P1** blocks or seriously slows a role's main mobile task; **P2** friction or inconsistency; **P3** polish. "Scope" says whether UX.17 fixes it or hands it on.

### 3.1 Navigation and shell

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M1 | All | Phone, tablet | The bar puts each role's frequent places under the thumb (decisions for managers, operations for HR, pulse for executives, administration for administrators) | The same five items for everyone: Home, Work, Actions, People, Services. Managers reach approvals only through Work; executives and administrators get Services and People, which they rarely use; administrators have no administration entry | P1 | `probe-phone-before.json` (`nav`), every `before/p*-home` | A role-aware bar from one service (`MobileNavigation`): five slots per experience, each slot the first destination the person may open (`canAccess()`), with fallbacks. Actions stays in the centre. The decision count always rides on Approvals or Work, so decisions never disappear | UX.17 |
| M2 | All | Phone, tablet | Overlays own the screen; their actions are reachable | The bar (z 40) sits above Filament modals (z 40, earlier in the DOM), the mobile menu and the bell's slide-over. **The Request leave form's Submit row is covered** (modal ends at 818 px; bar from 780 px). The palette and drawers sit above it | **P1** | stacking check; `zero-training-before.json` (employee: `action_control_uncovered: false`); `before/p1-employee-request-leave`, `before/p7-hr-menu` | Hide the bar while a modal, the mobile menu, the palette, a drawer or the assistant is open | UX.17 |
| M3 | Multi-role | Phone | The bar follows the view the person chose | "View as" on Home stores the preference, but the bar is rendered outside the Home component and keeps the old view until the next page | P2 | `Home::switchLens`, `body-end.blade.php` | The bar becomes a small Livewire component that re-renders on the switch event | UX.17 |
| M4 | All | Phone, tablet | One way to start something | "New" (+) in the top bar and "Actions" (+) in the bottom bar open the same palette | P2 | `before/p*` top bars | Hide the top-bar "New" where the bottom bar shows (under 1024 px) | UX.17 |
| M5 | All | Tablet | A bar sized for a tablet | The phone bar stretches across 768 px (five 150 px cells) | P3 | `before/t*` | Centre the bar at a comfortable width on tablets | UX.17 |
| M6 | All | Phone | Back closes an open sheet | Android back leaves the page while a sheet is open (the sheet goes with the page). Livewire's SPA navigation owns the history: pushing an entry per sheet makes Livewire restore a cached page snapshot on back, which would discard live page state | P3 | `livewire.esm.js` popstate handler | Not changed. Every sheet gets a visible close control instead (M14, M18) | Recorded; UX.18 browser behaviour |

### 3.2 Home

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M7 | All | Phone | The first screen answers the role's question | The header stacks eyebrow, display title, brief, two full-width buttons (the second duplicates the bar's Actions), up to three rows of "View as" chips (administrator: six chips) and the welcome note. The lead starts at 544–688 px (§2); on a 360 px phone the administrator's governance is below the fold | **P1** | §2, `before/p5-admin-home`, `before/s5-admin-home` | A compact phone header: one primary action (the bar has Actions), "View as" on one scrolling row, a compact welcome | UX.17 |
| M8 | All | Phone | Progressive disclosure, not a desktop column | Every list renders in full: manager Home 5.1 screens (decisions, Your team, Your day, What changed, For you, intelligence, Team pulse, Start something). A signal row is about 110 px (count, text and button share a narrow row; the reason wraps to 5–6 lines) | **P1** | §2, `ux17/before-full` (scratch) | Phones show three rows per list with "Show N more" in place (no navigation); signal rows become one tap target with the count inline; intelligence shows its first insight with "more" | UX.17 |
| M9 | Employee | Phone, touch | Instructions match the device | The welcome note says "Press Ctrl K", "? for every shortcut" and "Hover a name to peek" on a touch phone | P2 | `before/p1-employee-home` | Touch wording on coarse pointers; keyboard wording stays on desktop | UX.17 |
| M10 | Executive | Phone | A concise story, no tiny charts | Headline, movement, five figures and a 6-month sparkline drawn about 40 px tall | P3 | `before/p4-executive-home` | Keep headline, movement and the key figures; the trend chart stays on Workforce pulse and desktop | UX.17 |
| M11 | Manager | Phone | No repetition | Decisions appear in "Decisions waiting" and again in "Your team"; Team pulse repeats approvals pending | P3 | `before-full/p2-manager-home` | Kept (each is the same count from one source); the caps in M8 shorten it. Recorded for UX.19 acceptance | UX.19 |

### 3.3 My Work, Approval Center, notifications

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M12 | All | Phone | Filters take one line | Six filter chips wrap into two or three rows above the work | P2 | `before/p*-my-work` | One scrolling row on phones | UX.17 |
| M13 | Manager | Phone | The queue first | The intelligence card (three insights, about 300 px) comes before the queue | P2 | `before/p2-manager-approvals` | Intelligence shows its first insight with "more" on phones (M8) | UX.17 |
| M14 | All | Phone | Notifications reachable, deep links safe | The bell opens Filament's slide-over; its bottom is covered by the bar (M2). The notification center page works; tabs scroll. Deep links are checked by each record's policy (`NotificationCenter::linkFor`). **The personas have almost no notifications** (Amit 0, Meera 1), so nothing can be evidenced | P2 | DB counts, `before/p7-manager-notifications` | M2 fixes the covering. Realistic notifications are seeded through the real `Notifier` (synthetic, fictional) | UX.17 (data) |

### 3.4 Employee 360

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M15 | Manager, HR, admin | Phone, tablet | The viewer panel ("Your team", "People operations", "Identity and access") close to the top | It sits in the aside, which follows the whole main column: Now, Recently, then Intelligence, then the panel, about 2.5 screens down (1,270 px of 1,800) | **P1** | `before-full/p2-manager-360-report`, `p3-hr-360`, `p5-admin-360` | One DOM order for every size: the Now panel, then the aside (the viewer panel first, then intelligence and snapshot), then Recently. Desktop keeps two columns; the panel moves above intelligence in the aside | UX.17 |
| M16 | All viewers | Phone | Header actions in reach | Summarise, Message, Request, Action and More wrap into three rows | P3 | `before/p3-hr-360` | Kept. The UX.15 header still reads correctly; tightening it is visual polish | UX.19 |

### 3.5 Directory and tables

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M17 | HR, payroll, admin | Phone | A list that reads on a phone | The People list view (their default) scrolls sideways by 36–57 px; Status is clipped ("Probatio…"); Department and Location are hidden under 768 px. Five controls (search, four selects) take two rows before the list | **P1** | `probe-phone-before.json` (`scrollers: pos-panel.overflow-x-auto`), `before/p3-hr-people` | Under 640 px each row becomes a stacked card (name, role, department · location, status); filters sit behind a "Filters" disclosure with a count of active filters. Same query, same fields, same gates | UX.17 |
| M18 | HR, payroll, admin | Phone | Dense module tables usable | Filament tables already stack into entries (UX.15 closure). Row actions ("View", "Edit") and filter-remove buttons are under 24 px | P2 | `probe-phone-before.json` (`small24Samples`) | Coarse-pointer minimums for row actions, checkboxes and filter chips. No change to columns, filters, sorting or pagination | UX.17 (targeted) |

### 3.6 Command and search on touch

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M19 | All | Phone | An obvious way out; results above the keyboard | A floating card with no visible close control on phones (Esc hint hidden); the page shows around it; the list is capped at 56 vh. Results stay above the keyboard (zero-training) | P1 | `before/p1-employee-palette-leave`, `p2-manager-palette-person` | A full-screen sheet on phones with a Cancel button, sized to the visual viewport so the keyboard never covers the list, with safe-area padding. ⌘K/Ctrl+K and desktop layout unchanged | UX.17 |
| M20 | All | Phone, touch | Row actions reachable | Actions for the highlighted result (Open profile, Preview, Journey, In org map) are 26 px tall | P2 | `before/p2-manager-palette-person` | 40 px on coarse pointers | UX.17 |

### 3.7 Governance and operations

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M21 | HR, admin, manager, employee | Phone | Status, title, context, action, readable | Count (3.5 rem), text and a 32 px button share one row; the reason wraps to 5–6 lines in about 170 px | P1 | `before/p5-admin-home`, `p3-hr-home`, `p3-hr-my-work` | On phones: count inline before the title, full-width text, the whole row is the link (same URL, same accessible name, the existing gate), a chevron shows it opens | UX.17 |
| M22 | HR | Phone | Figures that fit | Five operations figures above the rows take about 150 px | P3 | `before/p3-hr-home` | Kept; with M21 the rows rise into the first screen | — |

### 3.8 Forms, overlays, AI, touch

| # | Role | Viewport | Expected | Actual (today) | Sev. | Evidence | Proposed correction | Scope |
|---|---|---|---|---|---|---|---|---|
| M23 | All | Phone | Submit always reachable | Filament modal forms put the footer after the last field; with M2 it is also covered | P1 (with M2) | `before/p1-employee-request-leave` | M2, plus a sticky footer for modal forms on phones | UX.17 |
| M24 | All | Phone | Comfortable targets without inflating desktop | Top-bar icon buttons 34–36 px; space-bar links, section links and person chips under 40 px on phones (836 targets under 40 px and 78 under 24 px across 36 phone pages) | P2 | `probe-phone-before.json` | Coarse-pointer minimums: 40 px for top-bar buttons and space-bar links, 24 px minimum everywhere the probe found less | UX.17 (targeted); full audit UX.18 |
| M25 | All | Phone | The assistant as a contextual sheet | The assistant opens as a bottom sheet (88 vh) with role suggestions first, sources and a close button; it sits above the bar. On Home the intelligence card sits at the end | — | stacking check, `before/p3-hr-ai` | Validate only; intelligence compaction (M8) | UX.17 (validate) |
| M26 | All | Firefox | No script errors | UX.16 found an intermittent `[object Object]` from Filament's notification lazy load (1 in 16 before UX.16, 4 in 16 after) | P2 | UX.16 `browser-roles-recheck.txt` | Re-test; not fixed unless UX.17 makes it worse | UX.18 unless worsened |

### 3.9 Not a finding

- **Tablet** layouts are coherent: one column, the bar, no overflow. Only M5 applies.
- **Desktop** is the regression baseline (`before/d*`).
- **Security.** Every bar item, palette verb and 360 fact must stay gated by the destination's own `canAccess()`, policy or permission. Nothing in this audit needs an authorisation change. G12 (employee self-360) stays an owner decision; G13 (reminder links) is not touched by the planned changes.

## 4. Role mobile matrix (target)

| | Employee | Manager | HR | Executive | Administrator | Payroll |
|---|---|---|---|---|---|---|
| Phone question | What matters to me today? | What needs my decision, and how is my team? | What needs HR operational attention? | What is happening across my workforce? | What needs configuration, governance or system attention? | What blocks the payroll run? |
| Bar | Home · Work · Actions · Services · People | Home · Approvals · Actions · Team · Work | Home · Work · Actions · People · Requests | Home · Pulse · Actions · Org map · Work | Home · Admin · Actions · Users · Work | Home · Work · Actions · Payroll · People |
| First screen | For you (own items), the Request leave action | Decisions, then Your team | People operations | Workforce headline and movement | Governance | Payroll run |
| Decision count on | Work | Approvals | Work | Work | Work | Work |
| Fallback when a destination is not allowed | — | Team → People; Approvals → Work | Requests → Services | Pulse → Work; Org map → People | Admin → Services; Users → People | Payroll → Services |

## 5. Plan

1. **Shell:** `MobileNavigation` (role bar, gated, fallbacks, decision badge); the bar as a Livewire component that follows view switches; the bar hidden under overlays; top-bar "New" hidden where the bar shows; a centred bar on tablets.
2. **Home on phones:** compact header, one-row view switcher, touch welcome, compact signal rows, list caps with "Show N more", compact intelligence, no trend chart on the executive phone Home.
3. **My Work and the Approval Center:** one-row filters; compact intelligence.
4. **Employee 360:** Now → viewer panel → intelligence → snapshot → Recently on narrow screens.
5. **Directory:** stacked cards for the list view under 640 px; filters behind a disclosure on phones; touch copy.
6. **Command palette:** a full-screen sheet on phones with Cancel, sized to the visual viewport; 40 px row actions on touch.
7. **Forms and tables:** sticky modal footers on phones; coarse-pointer minimums for row actions and filter chips.
8. **Data:** realistic notifications for the personas through `Notifier` (synthetic, fictional).
9. **Tests:** the role bar per experience and its gates, multi-role switching, the 360 order, People cards, the palette markup, and security regressions (tenant, organisation and relationship scope, field gates, notification deep links, governance and reminder links).
10. **Validation:** the same probe, captures and zero-training after the change; Chromium, Firefox and the WebKit engine (not Apple Safari); targeted axe; the Firefox recheck; build, Pint, `php -l`, `view:cache`, the full suite and MySQL.

**Out of scope:** comprehensive performance, accessibility and visual-regression gates (UX.18); owner decisions and final acceptance (UX.19); module grids beyond touch targets (UX.15 debt, UX.19).
