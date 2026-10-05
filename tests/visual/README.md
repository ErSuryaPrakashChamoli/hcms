# PeopleOS visual regression (UX.18)

Automated screenshot comparison of the role experiences at phone, tablet and desktop, in light and dark, against reviewed baselines in `__screenshots__/`. Playwright's `toHaveScreenshot` drives it, with `@playwright/test` pinned in `package.json`.

## Determinism

A difference should mean the product changed. Four things keep the rendering identical from run to run:

- **Frozen, fresh data.** `prepare.sh` rebuilds the disposable `hcm_ux_visual_showcase` database (fictional showcase only) with `PEOPLEOS_VISUAL_FROZEN_NOW` set. The clock hook in `AppServiceProvider::freezeVisualClock()` is inert unless three things hold:
  - the variable is set;
  - the app is not in production;
  - the database name ends in `_visual_showcase`.

  Every run must start from fresh data, because a run itself writes state: audit events for sign-ins, the Home visit, recent items. `npm run visual:test` always rebuilds first.
- **Frozen server.** `serve.sh` serves that database with the same frozen clock and the array cache. The array cache matters because a frozen clock would otherwise never let the login limiter's window pass.
- **Fixed order.** One worker, fixed order: some screens record state, so order is part of the data.
- **Stable rendering:**
  - reduced motion, animations disabled, caret hidden, spinners hidden (`stabilise.css`);
  - the fonts are served by the app;
  - the palette query is filled, not typed, and its selection collapsed.

## Run

```bash
# once: an empty database named hcm_ux_visual_showcase, and the browsers (npx playwright install chromium firefox webkit)
tests/visual/serve.sh &              # port 8092 (VISUAL_PORT); keep it running
npm run visual:test                  # rebuild the data, then compare every screen with its baseline
# WEBKIT_EXE=<launcher> is needed where Playwright's WebKit cannot find its system libraries
```

Results go to `tests/visual/results/` (gitignored):
- `visual-results.json` for automation;
- `report/` (HTML) to review expected, actual and diff side by side.

## Coverage

- **Personas:** employee, manager, HR, executive, administrator and payroll, each with its own permissions (no elevated fixture user).
- **Viewports and themes:** phone 390 × 844 (touch), tablet 768 × 1024 (touch) and desktop 1440 × 900, light and dark.
- **Cross-engine smoke:** Firefox at desktop and the WebKit engine at phone width, for four screens. The WebKit engine is Playwright's WPE MiniBrowser, not Apple Safari.
- **Shared surfaces:** the phone bar, the command center, a modal form, the decision sheet, the assistant, the mobile menu, the notification sheet and page, the directory filters, an expanded phone list, empty states.

The list is `SHOTS` in `ux.visual.mjs`.

## When a screenshot differs

Classify every difference before touching a baseline:

1. **Intentional:** the change was meant. Update that one baseline, with `npx playwright test -c tests/visual -g "<id>" --update-snapshots` after `prepare.sh`, and say why in the commit.
2. **Regression:** fix the product, never the baseline.
3. **Environment noise:** fix the harness, then re-run. Examples are a different browser build or a race in a step.
4. **Baseline update required:** the fixture data changed on purpose. Update the affected baselines only, and say which.

Never run `--update-snapshots` across the whole suite to make a run green.
