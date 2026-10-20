# UX.18 Visual Regression Report

**Date:** 5 October 2026 · **Branch:** `feature/oct_1_phase_1` · **Scope:** the automated visual regression system built in UX.18. The suite lives in `tests/visual/`, with its own [README](../../tests/visual/README.md).

## 1. What it is

PeopleOS now has an automated screenshot comparison of the role experiences against reviewed baselines. Playwright Test's `toHaveScreenshot` drives it (`@playwright/test` 1.62.1, pinned in `package.json`):

- `npm run visual:test` rebuilds the data at a frozen moment and compares every screen with its baseline;
- each difference produces expected, actual and diff images and an HTML report (`tests/visual/results/report/`);
- `npm run visual:update` exists, but only for reviewed changes (§6).

Screenshots alone are not the strategy. The suite fails a run when a screen differs, and a person classifies every difference before any baseline moves.

## 2. Baseline strategy

| Recorded for every baseline | Where |
|---|---|
| Browser | Playwright project name: `chromium-*`, `firefox-desktop`, `webkit-phone` (WebKit engine, not Apple Safari) |
| Viewport | Project: phone 390 × 844 (touch), tablet 768 × 1024 (touch), landscape tablet 1024 × 768 (touch), desktop 1440 × 900 |
| Theme | File name suffix `-light` / `-dark` (the theme is set before load; `prefers-color-scheme` emulated) |
| Role and persona | Screen id prefix (`employee-`, `manager-`, `hr-`, `executive-`, `admin-`, `payroll-`); personas in `tests/visual/personas.mjs`, each signing in with its own permissions (no elevated fixture user) |
| Dataset | `hcm_ux_visual_showcase`, rebuilt by `prepare.sh` from `UxShowcaseSeeder` (fictional people) at `PEOPLEOS_VISUAL_FROZEN_NOW` = 2026-10-05 09:00 |
| Expected state | The `SHOTS` table in `ux.visual.mjs`: path, viewports, themes, and the action that sets up the state (palette query, modal, sheet, menu, held request, error) |
| Location | `tests/visual/__screenshots__/<project>/<screen>-<theme>.png` |
| Commit | Created in `11ae8f1`; every later baseline change is in a commit that says which screens changed and why (§6) |

Every capture also asserts the HTTP status: 200, or the error status a shot is about (the 404 page). An error page, a refusal or a sign-in screen can therefore never become a baseline by accident. The first draft of the suite caught exactly this: a leave-type form that answered 404 for the administrator was replaced by a department form, and the assertion was added.

## 3. Determinism

A difference should mean the product changed. Before the suite was trusted, it was run until it was stable.

| Source of noise | Control |
|---|---|
| Dates, greetings, "x minutes ago" | Frozen clock (`AppServiceProvider::freezeVisualClock`), inert unless the variable is set, the app is not in production and the database is a `*_visual_showcase` one (`VisualClockGuardTest`, 7 tests); served by `serve.sh` with the same clock and the array cache (a frozen clock would never let the login limiter's window pass) |
| State written by viewing (sign-ins, Home visits, recent items) | Data rebuilt before every run (`prepare.sh`); one worker, fixed order |
| Notifications that shared one second (ties in ordering) | The showcase seeder gives each its own moment, and marks the right one read |
| Debounced search (the first answer replaced by a later one) | The palette query is filled, not typed, the network allowed to settle, the input selection collapsed |
| Animations, caret, spinners | Reduced motion, `animations: 'disabled'`, caret hidden, spinners hidden (`stabilise.css`) |
| Fonts and network content | Fonts are served by the app; no third-party content |
| Random identifiers | Not rendered; the showcase uses fixed fictional people |
| The frozen clock against the browser's real clock | Found late in UX.18: the session cookie's expiry is stamped from the frozen clock, but browsers judge it by the real time. With the normal 120-minute lifetime, every run more than two hours after the frozen moment (on any later day, for example) failed at sign-in. `serve.sh` now uses browser-session cookies and a very long server lifetime |

**Measured stability:**
- three full runs on rebuilt data;
- the palette screens repeated 8 times in every engine (48/48).

Each instability found on the way was a harness race, fixed in the harness, not by loosening the threshold:
- a keystroke lost before focus;
- a race between the debounced search answer and the screenshot;
- the text selection as the palette opened;
- tied notification times.

The comparison threshold is strict: at most 0.1 % of pixels may differ, with a per-pixel colour threshold of 0.2.

## 4. Coverage

| Viewport (project) | Baselines | Light | Dark |
|---|---|---|---|
| Phone 390 × 844, Chromium | 51 | 39 | 12 |
| Tablet 768 × 1024, Chromium | 16 | 8 | 8 |
| Landscape tablet 1024 × 768, Chromium | 7 | 7 | — |
| Desktop 1440 × 900, Chromium | 38 | 26 | 12 |
| Desktop 1440, Firefox (smoke) | 4 | 4 | — |
| Phone 390, WebKit engine (smoke) | 4 | 4 | — |
| **Total** | **120** | | |

**Role matrix** (41 screens, each at the viewports and themes listed in `SHOTS`):

| Role | Screens |
|---|---|
| Employee | Home, My Work, People, My HR, command palette ("leave"), Request leave form, notifications, 404 page |
| Manager | Home, Home with a list expanded in place, My Work, Approval Center, decision sheet, My team, a report's Employee 360, notifications page and sheet |
| HR | Home, My Work, People directory (list and filters open), employee register, service requests, Employee 360, assistant, phone menu, hire form, person sheet while loading |
| Executive | Home, Workforce pulse, pulse drill-down, org map, Approval Center with nothing waiting (empty state) |
| Administrator | Home, Admin Centre, users, audit, Employee 360 (identity and access), department form |
| Payroll | Home, People |

**Shared surfaces:**
- the role-aware phone bar (every phone shot);
- overlays: command center, modal form, decision sheet, assistant, notification sheet, phone menu;
- notifications;
- search;
- forms: request leave, hire, department;
- the empty state (Approval Center);
- the loading state (a sheet with its request held);
- the error state (404).

## 5. Results

| Run | Result |
|---|---|
| Determinism: three full runs on rebuilt data | Clean (no difference) |
| Palette screens repeated 8 times in every engine | 48 / 48 |
| Review run after the target-size fix (A5) | 17 screens differed, all classified intentional (§6); only those baselines updated (`cc3ae08`) |
| **Final run** (data rebuilt at the frozen moment, after the last reviewed update) | **120 passed, 0 failed** (5.4 min, one worker) |

The 198 skipped entries in a run are screen, theme and project combinations that a shot does not declare (for example a phone-only screen at desktop width, or a dark screen outside the cross-engine smoke set). They are skipped by design and are not failures.

## 6. Differences classified during UX.18

Every difference after the first baselines was classified before anything moved:

| Screen(s) | Classification | Decision |
|---|---|---|
| `manager-notifications` (phone, desktop), `manager-bell` | Environment noise in the data: four seeded notifications shared one second, so their order was a tie | Fixed the seeder (each its own moment); baselines re-captured on the fixed data |
| `employee-palette-leave` (Firefox, then WebKit, then Chromium phone dark) | Environment noise in the harness: a keystroke lost before focus, the debounced answer racing the screenshot, the opening selection | Harness fixed (fill, settle, collapse); 48/48 stable; baselines re-captured once |
| `admin-home` (phone light and dark, WebKit phone) | **Intentional:** the focus-order fix scrolls the chip row itself instead of `scrollIntoView()`, so the chosen chip sits at a different horizontal position | Diff confined to the chip row (reviewed); three baselines updated in `3826795` |
| `employee-page-not-found` (new) | **Intentional:** the 404 guidance no longer assumes a keyboard ("Ctrl K with a keyboard") | Baselines created after the wording change |
| `executive-approvals-empty`, `hr-person-sheet-loading`, `employee-page-not-found` (new) | Baseline added (new coverage). The loading shot exposed a real defect: the sheet never showed its skeleton (fixed in `3826795`) | Reviewed and added |
| Landscape tablet set (new) | Baseline added (new coverage) | Reviewed and added |
| 17 shots, Chromium phone and desktop and Firefox desktop: `manager-home`, `hr-home`, `executive-home`, `admin-home` (desktop, light and dark), `manager-360-report`, `hr-360` (desktop), `hr-employees-table`, `admin-users`, `admin-audit` (phone) | **Intentional:** the 2.5.8 target-size fix (A5) makes intelligence-card links and table row actions at least 24 px tall. Each diff was reviewed and is confined to those links and rows and what follows them | Only these baselines updated (`--update-snapshots=changed`, filtered to the affected screens) |
| The whole suite (sign-in failed) | Environment noise in the harness: the frozen session cookie had expired in real time | `serve.sh` fixed (session cookies without an expiry date); no baseline touched |

**Actual regressions found by the suite:** none in the product. The suite's first runs surfaced one product defect, the missing loading skeleton, which was fixed before its baseline was taken.

## 7. Remaining limitations

- **Engines.** Chromium carries the full matrix; Firefox and the WebKit engine have a four-screen smoke set each, because cross-engine font rasterisation makes full baselines noisy. Apple Safari is not covered (no Safari environment exists here; see the main report §12).
- **Machine dependence.** Baselines are tied to this Linux workstation's font rendering. A CI machine needs its own baselines, generated once and reviewed, or a container with the same fonts.
- **Data coverage.** Screens with long lists show the showcase's data, not scale data; the scale behaviour is covered by the performance work, not by screenshots.
- **Not covered:** module CRUD screens beyond the employee register, users, audit, service requests and two forms (the UX.15 module-grid debt is a UX.19 item), the org map's zoom and drag interactions, and print.
- **Loading states** are covered for the sheet; skeletons elsewhere (Home sections are rendered on the server) are not separately captured.
