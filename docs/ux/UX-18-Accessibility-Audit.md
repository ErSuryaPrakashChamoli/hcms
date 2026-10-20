# UX.18 Accessibility Audit

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** the comprehensive accessibility audit handed over by UX.17. It covers every role experience, the shared shell and overlays, forms, tables and cards, at phone, tablet and desktop, in light and dark.

This audit does not claim the product is fully accessible. Automated tools find a subset of problems. The keyboard, reflow and screen-reader-oriented checks below were scripted, so they are repeatable, but no person using assistive technology took part (§7).

## 1. Scope and method

| | |
|---|---|
| Pages | 52 page states for six personas (employee, manager, HR, executive, administrator, payroll): every role's Home and My Work; People (cards, list, filters); the Employee 360 (Now, Journey, Records); My HR; the employee register, users, audit, roles, leave requests, service requests and payroll runs; the Approval Center; Workforce pulse and its drill-down; the org map; the Admin Centre and security policy; Preferences; notifications; the hire and department forms; the 403 and 404 pages. Open states: command center, Request leave modal, decision sheet, person sheet, assistant, notification sheet, user menu, phone menu, directory filters, a list expanded in place |
| Viewports | Phone 390 × 844 (touch), tablet 768 × 1024 (touch), desktop 1440 × 1000 |
| Themes | Light and dark |
| Automated tool | axe-core 4.13.0, run on the live showcase (fictional people). Every violation of the WCAG 2.0, 2.1 and 2.2 A/AA rule tags is reported, and best-practice rules separately |
| Browsers | Chromium 151 (all 312 checks); Firefox and the WebKit engine for a cross-engine subset (§3) |
| Structure checks | Landmarks, one `h1`, heading-level skips, `lang`, sideways overflow, touch targets under 24 px (WCAG 2.5.8, spacing exception approximated), split into PeopleOS-owned and Filament-owned controls |
| Scripted manual review | Keyboard path per role, overlays (focus in, trapped, Escape, focus returned), list keys, reflow at 320 px and 200 % zoom, text spacing (WCAG 1.4.12), reduced motion, the accessible role/name tree |
| Scripts | `docs/ux/ux18/evidence/a11y-audit.mjs`, `a11y-manual.mjs`; results `a11y-audit-first.json`, `a11y-audit-final.json`, `a11y-manual.json` |

## 2. Automated results

| Run | Checks | Checks with a WCAG violation | Violating nodes | Errors |
|---|---|---|---|---|
| First audit (before fixes) | 312 | 14 | 14 | 4* |
| Re-check of the affected pages after fixes | 78 | 0 | 0 | 0 |
| **Final audit (after all fixes)** | **312** | **0** | **0** | **0** |

\* The four "errors" in the first run were an audit-script artefact: the "expand in place" step looked for the phone-only "Show N more" button on tablet and desktop, where it does not exist by design. Those pages were still audited without that step, and the script now applies the step on phones only.

**Structure in the final audit:**
- every page has exactly one `h1`, no skipped heading level and `lang="en"`;
- no page scrolls sideways at any viewport;
- 288 pages answered 200; the 403 and 404 states answered 403 and 404 as intended;
- 12 same-document hash views (`#journey`, `#records`) report no status, by design.

**Best-practice findings remaining (not WCAG failures; both in Filament's own markup):**
- `empty-table-header` (32): the header cell above Filament table row actions has no text.
- `aria-allowed-role` (6): Filament's wizard step uses `role="group"` on an element that does not allow it.

Both are recorded for the Filament module grids in UX.19 (§8).

## 3. Cross-engine and regression runs

| Run | Checks | Violations | Notes |
|---|---|---|---|
| Cross-engine subset, Firefox 153: My Work, Approval Center, People, Employee 360, notifications, Admin Centre (all their states), phone and desktop, light and dark | 80 | **0** | Firefox has no touch emulation in Playwright, so its "phone" is a 390 px window |
| Cross-engine subset, the WebKit engine (Playwright WPE MiniBrowser 26.5, **not Apple Safari**), same pages | 80 | **0** | |
| UX.17 set re-run (`axe-mobile`) | 58 | **0** | |
| UX.16 set re-run | 46 | **0** | |
| UX.15 closure set re-run | 78 | **0** | Two steps could not click: the HR person drawer (HR's directory opens on the list since UX.16, as in UX.17) and the HR "What changed" drawer. Home's "What changed" lists changes since the last visit, and the audits had visited HR's Home all day ("Nothing changed since your last visit"), a data artefact. Both drawers were audited directly instead, from the card view and from People › Recently changed, at desktop and phone width in both themes: 8 checks, **0** violations (`axe-drawers-recheck.txt`) |

## 4. Violations found and fixed

| # | Criterion | Where | Severity | Cause | Fix |
|---|---|---|---|---|---|
| A1 | **2.4.3 Focus order** | Home for everyone with more than one view (manager, HR, executive, administrator, payroll), at every size | **Serious** (found by the scripted keyboard review, not by axe) | The first Tab skipped the skip link, header and navigation and landed after the chosen "View as" chip. UX.17 scrolled the chip row with `scrollIntoView()`, which moves Chromium's sequential-focus starting point. Also on My Work | The row now scrolls itself (`posRevealChip`), only when it overflows, and again once web fonts have loaded: in the WebKit engine the row only overflows after the fonts arrive. The chip stays in sight on phones. Browser regression test `focus-order.browser.mjs` (stable 84/84 across Chromium, Firefox and the WebKit engine). A side effect of the bug: the first review run pressed Enter on a chip and switched a showcase persona's view, which was restored |
| A2 | 1.4.3 Contrast | Payroll control room (phone, tablet, desktop; light and dark) | Serious | Filament's `text-warning-600` on small text: 4.17:1 light, 4.25:1 dark. Older custom pages use the same 600 status shades in other places | The 600 status text shades take the PeopleOS status tokens (designed for text in both themes), unless the page sets its own dark shade. This covers pages the audit did not visit too |
| A3 | 1.4.3 Contrast | 403 and 404 pages, dark | Serious | The primary button kept a white label on the light-violet dark primary (2.43:1) | A dark label in dark mode (`--on-primary`) |
| A4 | 2.1.1 Keyboard (scrollable region) | Hire form at 768 px | Serious | Filament's wizard step header scrolled sideways with nothing focusable in it | Between 768 and 1279 px the steps wrap into two columns instead of scrolling |
| A5 | 2.5.8 Target size | Employee register row actions (desktop); intelligence links (desktop) | Minor | 20 px tall and adjacent to another target | 24 px minimum on every pointer; coarse pointers keep the larger UX.17 sizes |
| A6 | 4.1.2 / best practice | Approval Center queue | Minor | Decision buttons had `role="listitem"`, which buttons cannot take | A real list: each button inside an `<li>`, same look |
| A7 | 4.1.2 / best practice | Every sheet (drawer) | Minor | `role="dialog"` on an `<aside>`, which ARIA does not allow | The sheet is a `<div role="dialog">` |
| A8 | 1.3.1 / best practice (landmark-unique) | Employee 360 Journey, Growth, Rewards, Documents and Records views | Minor | Each view wrapper was a region labelled by the same heading as the region inside it, so two landmarks shared one name | The wrappers keep their `<section>` but no accessible name, so they are no longer landmarks; the inner region keeps the name |
| A9 | 4.1.3 Status messages / loading state | Any sheet opened from a card, row or event (the usual way) | Moderate (found by the visual suite) | The skeleton waited for a `show` call, but event-opened sheets load through `__dispatch`: the body was blank, and nothing was announced while loading | The skeleton shows for `__dispatch` too and announces "Loading details…" (`aria-busy`, polite) |
| A10 | Device wording (UX.17 rule) | 404 page | Minor | "Use search (Ctrl K)" on a phone | "Use search on Home … (Ctrl K with a keyboard)" |

**Still fixed from UX.17 (regression checked):** the phone search button keeps its accessible name ("Search people, requests, actions…"); the notification center's unread marker is an `aria-hidden` dot plus "Unread:" text; read notifications are no longer faded below contrast. All three pass in the final audit.

## 5. Keyboard review (scripted)

| Check | Result |
|---|---|
| First Tab on Home, five roles | "Skip to content" for all five; Enter lands in `main` (after fix A1; before it, only the employee) |
| Focus visibility, the first 40 tab stops on each role's Home | Every stop has a visible indicator (outline, ring or row ring); no focus on hidden elements |
| Command center | Focus moves into the input; Tab stays inside; Escape closes; focus returns to the trigger. Escape now closes it wherever focus is (UX.18 fix found by the Back tests) |
| Person sheet, Request leave modal, assistant, notifications sheet | Focus moves in; Tab stays inside; Escape closes; focus returns |
| Approval Center J/K | J and K move focus between decisions |
| Phone layout with a keyboard | Signal rows show a ring on their link; "Show N more" toggles `aria-expanded` and keeps focus; the Filters button toggles `aria-expanded`/`aria-controls`; the bar announces "Work, 6 waiting" |
| Back key | Closes the topmost overlay first (UX.18; see the main report §14) |

## 6. Zoom, reflow, spacing and motion

| Check | Result |
|---|---|
| Reflow at 320 CSS px (1.4.10): HR Home, People list, Employee 360, Approval Center, users, My Work | 0 px sideways overflow on all six |
| 200 % zoom of a 1280 px window (640 CSS px at 2×), same pages | 0 px overflow |
| Text spacing (1.4.12: line-height 1.5, paragraph 2 em, letter 0.12 em, word 0.16 em) on HR Home and the administrator Home (phone), the Approval Center and the Employee 360 (desktop) | No overflow; no clipped visible text. Three "clipped" hits are the deliberately visually-hidden "Open: …" link names (a false positive) |
| Reduced motion | With `prefers-reduced-motion: reduce`, no element animates or transitions longer than 10 ms (the palette open included) |
| Colour | No information by colour alone: signal rows carry the count and the reason in text; status badges have labels; dark mode is designed, not inverted (UX.15) |

## 7. Screen-reader-oriented observations

The accessible role/name tree (what a screen reader is given), read through Playwright's aria snapshot, is saved in `a11y-manual.json` (`tree`):

- **Phone bar (manager):** `navigation "Primary"` with the links "Home", "Approvals, 6 waiting", "Team" and "Work", and the button "Start something: leave, requests, approvals and more". The current place carries `aria-current="page"` (asserted by `Ux17MobileTest`; the snapshot format does not print it).
- **Home (manager):** one `h1` ("Good morning, Amit."); "View as" a `tablist` with the tabs "For you" and "Your team" (selected); section headings at level 2 that include their count ("Decisions waiting for you 6", "Your team 2"). Observation for UX.19: the "View as" tabs switch the whole page rather than a tab panel, so a toggle-button group would describe them more exactly; axe does not flag it.
- **Command center:** `dialog "Command center"` with a `combobox` (expanded, the query as its value), a "Cancel" button, the "Search scope" tablist, and a `listbox "Results"` of named `group`s ("Actions", "Navigation") whose `option`s are named by title, verb and detail ("Request leave Request Pick dates, see your balance, submit").
- **Decision sheet:** `dialog "Decision"` with a "Close panel" button and an `article` named by the request ("Casual leave · 1 day"): requester, reason (quote), a `list "Context"`, a `group "Before → After"` (term/definition), then the "Approve" and "Reject" buttons and "View details".

**Not done:** a pass with a real screen reader (NVDA, VoiceOver, TalkBack or Orca) by a person. Orca is installed on this workstation, but driving it would take over speech on the owner's live desktop session, so it was not used. A human pass with VoiceOver (iOS and macOS) and TalkBack (Android) is recommended before go-live (§8).

## 8. Remaining issues and deferred items

| Item | Owner | Why it remains |
|---|---|---|
| Empty header cell above Filament table row actions (best practice) | UX.19 (module grids) | Filament's table markup; fixing it in PeopleOS means overriding the table view, which belongs with the module-grid decision |
| Filament wizard step `role="group"` (best practice) | UX.19 (module grids) | Filament's markup |
| One "—" placeholder link in the audit list on phones (12 × 48 px) | UX.19 (module grids) | Filament column rendering |
| Human screen-reader pass (VoiceOver, TalkBack, NVDA) | Before go-live | Not possible here without taking over the owner's session; no Apple devices |
| Real-device zoom, text-size and switch-control checks | Before go-live | No physical devices here; Android was validated in an emulator only |
| Older custom pages with raw grey utilities (`text-gray-500`) | Watch | They passed axe on every audited page; the status shades were fixed globally |
