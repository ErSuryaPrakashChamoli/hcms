# PeopleOS design system

Experience Transformation (UX-1). This is the source of truth for how PeopleOS looks, moves and behaves. The implementation lives in `resources/css/filament/admin/theme.css` (tokens and component classes), `resources/views/components/pos/*` (Blade components), `resources/js/peopleos.js` (Alpine behaviours) and the Livewire components in `app/Livewire/Experience`. The research behind each decision is in `PeopleOS-UX-Research.md`.

## 1. Principles

1. **Work first.** Every screen answers "what do I need to do, and why". Navigation is by job, not by module.
2. **Context without leaving.** Previews and decisions open in drawers. The page underneath keeps its place.
3. **Elegant confidence.** A midnight rail frames bright, calm working surfaces:
   - one primary colour (iris), one warm accent (champagne gold);
   - muted jewel tones mark categories;
   - one primary action per section;
   - no neon, no glass, no rainbow charts.
4. **Explain, don't just display.** Attention items carry their reason. Metrics carry their definition. AI carries its sources.
5. **Same rules everywhere.** The UI never decides access. Every surface asks the same policies, scopes and field rules as the system of record.
6. **Accessible by construction.**
   - Contrast is checked per token.
   - Everything works by keyboard.
   - Status is never shown by colour alone.
   - Motion respects reduced motion.

## 2. Colour

> **Revision (4 October 2026).** The product owner shared a reference dashboard. They asked for its richer colour combinations — a dark navy rail, an indigo/violet primary, colourful category tiles and an illustrated greeting — but "not exactly the same colour" and "very elegant". The palette below is PeopleOS's own answer to that direction:
> - a deep **iris** primary;
> - a **midnight** rail;
> - a **champagne-gold** accent;
> - **muted jewel tones** for categories.
>
> It replaces the earlier teal palette. Every text pair was re-checked for WCAG AA.

Colours are semantic tokens on `:root` (light) and `.dark`, a separately designed dark set rather than an inversion. Components use tokens only. Tailwind utilities can use them as `bg-pos-surface`, `text-pos-primary` and so on (the `@theme` mapping).

### 2.1 Tokens

| Token | Light | Dark | Use |
|---|---|---|---|
| `--pos-canvas` | `#f6f6fa` | `#0c0e1d` | App background (soft lavender-grey) |
| `--pos-surface` | `#ffffff` | `#13152b` | Cards, panels, drawers |
| `--pos-surface-muted` | `#f1f1f7` | `#191c38` | Wells, table headers, insight panels |
| `--pos-surface-elevated` | `#ffffff` | `#1c1f3d` | Command center, popovers |
| `--pos-border` / `-strong` | `#e7e7f0` / `#d0d1e0` | `#262a4d` / `#353a66` | Hairlines, input outlines |
| `--pos-text` | `#15172e` | `#eceaf8` | Primary text |
| `--pos-text-secondary` | `#474a65` | `#b9b9d4` | Supporting text |
| `--pos-text-muted` | `#5d617e` | `#9093b4` | Captions, hints, labels |
| `--pos-primary` / `-strong` / `-soft` | `#574bc4` / `#463ba6` / `#f0eefc` | `#a49cf2` / `#bdb7f6` / iris 16 % | Iris: primary actions, current state, focus |
| `--pos-accent` / `-soft` | `#8f6420` / `#f7efe0` | `#e2b86b` / 14 % | Champagne gold: the brand arc, AI "Assistive" badge, highlights |
| `--pos-success` / `-soft` | `#0f7a55` / `#e3f4ec` | `#34d399` / 14 % | Done, approved, healthy |
| `--pos-warning` / `-soft` | `#9a5309` / `#fbf0dc` | `#fbbf24` / 14 % | Needs a look soon |
| `--pos-danger` / `-soft` | `#b42335` / `#fbe8eb` | `#f87171` / 14 % | Overdue, blocked, destructive |
| `--pos-info` / `-soft` | `#2a5fbf` / `#e6eefb` | `#60a5fa` / 14 % | Neutral information, moves |
| `--pos-rail-bg` → `-bg-2` | `#12152e` → `#1b1e42` | `#0a0c1c` → `#121535` | The midnight navigation rail (both themes) |
| `--pos-rail-text` / `-muted` / `-active` | `#c9cbe3` / `#8e91b3` / `#574bc4` | same | Rail labels, icons, the active pill |

**Category tiles.** These are decorative icon colours on soft fills; every tile carries a text label.

| Tile | Light | Use |
|---|---|---|
| rose | `#d9566b` on `#fbe9ec` | Attention |
| emerald | `#2e9a77` on `#e2f4ec` | Team, people present |
| sapphire (`sky`) | `#3f78d0` on `#e5eefb` | Leave, moves |
| amber | `#c98a2b` on `#f8eedb` | Approvals |
| violet | `#7d63d8` on `#efeafb` | Learning, services |
| teal | `#23959a` on `#e0f2f2` | Attendance, schedules |
| gold | `#b58a3c` on `#f7efe0` | Letters, recognition |
| indigo | `#574bc4` on `#ebe8fb` | Insight, balance |

In dark mode the tiles become lighter jewel tones on 14–16 % fills.

**Filament palette.** It is hand-tuned in OKLCH (`AdminPanelProvider::colors`) so that Filament's components agree with PeopleOS:
- primary-600 is exactly the iris `#574bc4`, and primary-700 is the hover `#463ba6`;
- gray is a cool violet-grey that belongs to the canvas and the rail;
- success, warning, danger and info are generated from the status colours.

Generated palettes were rejected as too saturated.

### 2.2 Contrast (WCAG 2.2 AA, measured)

**Light theme**

| Pair | Ratio |
|---|---|
| text / surface | 17.6 |
| text-secondary / surface | 8.6 |
| text-muted / canvas | 5.6 |
| text-muted / surface-muted | 5.4 |
| primary / surface | 6.5 |
| primary / primary-soft | 5.7 |
| white on primary | 6.5 |
| gold accent / surface | 5.2 |
| gold accent / accent-soft | 4.6 |
| success / surface | 5.3 |
| success / soft | 4.7 |
| warning / surface | 5.8 |
| warning / soft | 5.1 |
| danger / surface | 6.5 |
| danger / soft | 5.5 |
| info / surface | 6.0 |
| info / soft | 5.2 |
| rail text / rail | 11.2 |
| rail muted / rail | 5.8 (5.2 at the rail's lower end) |
| white on active pill | 6.5 |

**Dark theme**

| Pair | Ratio |
|---|---|
| text / surface | 15.1 |
| text-muted / elevated | 5.4 |
| primary / surface | 7.4 |
| gold / surface | 9.7 |
| canvas text on primary | 7.9 |

Every text pair is at least 4.5:1. Category tile icons are decorative, because their labels carry the meaning.

### 2.3 Rules

- **Status** always pairs colour with a label: a dot plus words (`<x-pos.status>`), or an icon tile plus words.
- **Danger** means overdue, blocked or destructive, never decoration.
- **Charts** use `--pos-chart-1…5`, ordered for distinguishability and redefined for dark:
  - iris;
  - teal;
  - gold;
  - rose;
  - sapphire.
- **Gradients are allowed in three places only:**
  - the illustrated hero (dusk sky);
  - the organisation banner (midnight with an iris and gold glow);
  - the rail.
  Working surfaces stay flat.

## 3. Typography

| Family | Typeface | Weights | Set by |
|---|---|---|---|
| Sans | **Instrument Sans** | 400 / 500 / 600 | `->font()` |
| Display | **Instrument Serif** | 400 | `->serifFont()`, exposed as `--font-display` |
| Code | JetBrains Mono | — | `->monoFont()` |

The display serif is used sparingly, for page titles, the greeting, the assistant's hello and the organisation banner. It gives PeopleOS an editorial, human voice. Nothing else uses it.

| Style | Class | Size / line | Weight | Use |
|---|---|---|---|---|
| Display | `.pos-display`, `.fi-header-heading` | clamp(28–46 px) / 1.1 | Serif 400 | Page titles, greeting |
| H1 | `.pos-h1` | 26 / 1.25 | 600 | Section heroes |
| H2 | `.pos-h2` | 19 / 1.3 | 600 | Card titles |
| H3 | `.pos-h3` | 16 / 1.4 | 600 | Group titles, item titles |
| Body | `.pos-body` | 15 / 1.6 | 400 | Running text |
| Body small | `.pos-body-sm` | 13.5 / 1.5 | 400 | Supporting text |
| Caption | `.pos-caption` | 12.5 / 1.45 | 400, muted | Meta lines, hints |
| Label | `.pos-label` | 11.5 / 1.4, uppercase, +0.08em | 600, muted | Overlines naming a block |
| Metric | `.pos-metric` | 26 / 1.1, tabular | 600 | KPI values |
| Metric large | `.pos-metric-lg` | 48 / 1, serif, tabular | 400 | Hero figures |
| Code | `font-mono` | 13 | 400 | IDs, codes |

Numbers in tables and metrics use tabular figures (`.pos-num`).

## 4. Space, shape, elevation

- **Spacing.** A 4 px grid, from `--pos-space-1` (4) to `--pos-space-12` (48).
  - Cards pad `--pos-card-pad` (20; compact 14).
  - Rows pad `--pos-row-pad-y` (12; compact 6).
  - Layout gaps are `--pos-gap` (20; compact 12).
- **Radius.**

  | Token | Size | Use |
  |---|---|---|
  | `sm` | 6 | Chips, inline fills |
  | `md` | 10 | Buttons, inputs, rows |
  | `lg` | 14 | Cards, tables |
  | `xl` | 18 | Drawers, dialogs, command center |
  | `pill` | 999 | Status, lens chips |

- **Elevation.** Three steps only:

  | Token | Use |
  |---|---|
  | `--pos-shadow-1` | Resting surfaces: a hairline plus a soft drop |
  | `--pos-shadow-2` | Hover, focus |
  | `--pos-shadow-3` | Overlays: drawers, command center, dialogs |

  Dark mode replaces shadows with lighter hairlines, because shadows do not read on dark.
- **Surfaces.**
  - Canvas (paper) is the background.
  - Surface (white) is where work happens.
  - Surface-muted is for wells, table headers and "Before" values.
  - Elevated is for floating layers.
  - Cards never sit on cards. Inner grouping uses wells.

## 5. Iconography

Heroicons:
- **Outline 24** for navigation and tiles.
- **Mini 20** for inline and buttons.
- **Solid** only for the active navigation item.

An icon never carries meaning alone. It always has a text label, or an `aria-label` when it is the only content of a button.

## 6. Interaction states

| State | Treatment |
|---|---|
| Hover | Surface-muted fill (rows, ghost buttons), shadow-2 (interactive cards), `translateY(-1px)` on cards |
| Focus | 2 px `--pos-focus` outline with 2 px offset (`:focus-visible` only); list rows get a primary-soft fill via `[data-focused]` |
| Active / pressed | `translateY(1px) scale(.99)`; `aria-pressed` / `aria-selected` / `aria-current` drive selected styles, never classes alone |
| Disabled | 50 % opacity, no pointer events, and the reason given in text nearby |
| Loading | Skeleton for layout-predictable content, shown after a short delay (`wire:loading.delay`); a spinner only inside the control that is busy |
| Selected (lists) | Primary-soft background; in the command center, `aria-selected="true"` on the option |

## 7. Motion

| Kind | Duration | Easing | Examples |
|---|---|---|---|
| Micro | 150–200 ms (`--pos-dur-1/2`) | `--pos-ease` (0.2, 0.8, 0.2, 1) | Hover, press, chip toggle, check-draw |
| Medium | 280 ms (`--pos-dur-3`) | `--pos-ease` | Card enter (`.pos-enter`, staggered 60 ms), page enter, drawer in |
| Large | 360 ms (`--pos-dur-4`) | `--pos-ease` | Meter fill, success check |
| Exit | 150–200 ms | `--pos-ease-in` | Drawer and command center out (reverse) |

- **Purpose only:** orientation (where something came from), feedback (it worked), continuity (the drawer slides from where you clicked). Nothing loops except skeleton shimmer and the busy spinner.
- **Reduced motion:** `@media (prefers-reduced-motion: reduce)` sets every animation and transition to 1 ms, and smooth scrolling is turned off in `peopleos.js`.
- **Phones:** drawers become bottom sheets (`pos-sheet-in`).

## 8. Data visualisation

- **Prefer words and numbers to charts.** A chart must answer a question stated in its title, for example "Headcount over six months".
- **Sparklines** (`<x-pos.sparkline>`) are SVG, with no library. Each has `role="img"` and an `aria-label` that states the change in words ("from 18 to 21").
- **Bars and comparisons** use `--pos-chart-*` in order. They are labelled directly (no legends where avoidable), have no 3D or gradients, and use tabular numbers.
- **Small populations are suppressed** below the privacy threshold (WorkforceMetrics). The UI shows "—" with the reason in the hint.
- **Restricted metrics** show "—" with "Restricted: needs …". The UI never estimates a figure the viewer may not see.

## 9. Density

`<html data-pos-density="comfortable|compact">` is set per person (Preferences) before first paint.

| Mode | Card pad | Row pad | Gap | Intended for |
|---|---|---|---|---|
| Comfortable (default) | 20 | 12 | 20 | Employees, managers, executives |
| Compact | 14 | 6 | 12 | HR operators living in tables (Filament table cells follow it) |

The People directory opens as a compact list when the person chose Compact.

## 10. Layout and responsive rules

**Breakpoints.**

| Name | Width | Shell |
|---|---|---|
| Phone | < 768 | Single column, bottom navigation, drawers as sheets, search as an icon |
| Tablet | 768–1023 | Single column, bottom navigation, sidebar as overlay |
| Desktop | 1024–1279 | Persistent context rail (collapsible), two-column Home from 1100 |
| Wide | ≥ 1280 | Approval list in two columns; content width is full, cards limit line length |

**Shell.**
- The top bar holds the logo, the command trigger (centre of gravity), notifications, the quick-launch button "New" and the avatar menu (the person's own pages and preferences).
- The left context rail holds the nine sections. The active section expands into its areas; pinned people and recent items sit below.
- Module pages get a space bar at the top: the other modules of the same area, one click away.
- Touch targets are at least 44 × 44 px on phones (bottom navigation items 48 px). Nothing important lives in a tooltip.

## 11. Components (§41)

| Component | Implementation | Notes |
|---|---|---|
| PeopleCard | `.pos-person-card` (directory), `.pos-person-chip` (team strip), `.pos-person-inline` (in text) | Opens the person drawer; directory fields only |
| Avatar | `<x-pos.avatar name size>` | Deterministic calm tint from the name; initials; `xs`–`xl` |
| Status | `<x-pos.status tone label>` | Dot plus words; tones: neutral, primary, success, warning, danger, info, accent |
| Metric | `.pos-metric`, `.pos-metric-cell`, `.pos-metric-lg` | Tabular, with a caption label |
| Timeline | `.pos-timeline`, `.pos-timeline-node` | Employee 360 journey events |
| Drawer | `DrawerHost` + `.pos-drawer` | One host; stacking with Back; focus trap; Esc; returns focus |
| Command | `CommandCenter` + `.pos-command-*` | ⌘K / Ctrl+K, `/`, `N`; listbox semantics; row actions |
| Action | `.pos-action-tile`, `.pos-btn-*` | Tiles start flows; one primary button per card |
| Approval | `<x-pos.approval-card item>` | What / who / why / impact / effective / risk; inline confirm |
| Journey | `<x-pos.journey>` + `.pos-journey-*` | Stages from lifecycle data; current stage emphasised |
| Insight | `.pos-attention` rows with `data-severity` | Severity bar plus reason text |
| Change | `ChangeFeedPanel` + `.pos-feed-item` | Typed, filterable, source-linked |
| Chart | `<x-pos.sparkline>`, `<x-pos.bars>` | SVG, accessible text alternative |
| Table | Filament tables (restyled), `.pos-table` for light read-only lists | Sticky headers, density-aware |
| Empty | `<x-pos.empty icon title why>` | What / why / next; separate copy for filtered-to-zero and no access |
| Skeleton | `.pos-skeleton`, `livewire.experience.skeleton` | Mirrors the final layout; `aria-busy` and a polite live region |
| Notification | `NotificationCenter` page + Filament database notifications | Grouped, snooze, mark read, deep links |
| Search | `.pos-search` | Leading icon, live results, clear |
| Filter | `.pos-chip[aria-pressed]`, `.pos-select`, `.pos-segmented` | State in the URL where it matters |
| Modal | Filament modals (restyled: xl radius, shadow-3) | Used for the forms of existing domain actions |
| Sheet | `.pos-drawer` at < 768 px | Bottom sheet with the same behaviour |
| Stepper | `<x-pos.stepper>` | Guided forms: context → information → decision → review → confirm |
| BeforeAfter | `<x-pos.before-after changes>` | Field, before (muted, struck), after (emphasised) |
| OrgMap | `OrganisationMap` + `.pos-org-*` | Progressive tree, zoom and pan, focus, departments |
| AIResponse | `<x-pos.ai-response>` | Answer / Key facts / Sources / Suggested actions; "Assistive" label |

## 12. Accessibility rules

- **Contrast:** AA for all text (section 2.2). Focus is always visible.
- **Keyboard:**
  - Ctrl/⌘+K and `/` open search; `N` opens quick actions; `?` lists shortcuts.
  - `G` then `H`/`W`/`A`/`P`/`O` navigates.
  - `J`/`K` move through lists; `A`/`R` approve or reject the focused item; Esc closes.
  - Shortcuts are ignored while typing in a field.
- **Dialogs and drawers:** `role="dialog"`, `aria-modal`, a labelled title, a focus trap (`x-trap`), Esc to close, and focus returned to the trigger.
- **Command center:** combobox plus listbox, with `aria-activedescendant` on the input and `aria-selected` on options.
- **Tabs:** `role="tablist"`/`tab`/`tabpanel` with `aria-selected`. Toggles use `aria-pressed`.
- **Live regions:** skeletons are `aria-busy`. Result counts and success messages are announced politely.
- **A skip link** ("Skip to content") is the first focusable element.
- **Reduced motion** is honoured (section 7). Colour is never the only signal (section 2.3).
- **Forms:** every input has a visible label. Errors say what happened and what to do, next to the field.

## 13. Writing

- Plain words and sentence case. Say what will happen: "Submit request", "Confirm: Approve", "Return to proposer".
- Attention items explain why ("Payroll cannot pay you without a primary bank account").
- **Empty states:**
  - name what is empty;
  - say why;
  - offer the next step;
  - when a filter caused it, say so ("No one matches these filters").
- **Errors:**
  - what happened;
  - what it means;
  - what to do.
  Domain refusals ("already decided", "insufficient balance") are shown verbatim.
- AI copy is labelled "Assistive". It never claims certainty or authority.

## 14. Filament integration

- **Filament stays the engine:**
  - forms, validation and tables;
  - resources and policies;
  - actions with their domain services.
- **The PeopleOS layer adds:**
  - the theme (tokens and overrides);
  - the custom navigation (nine sections);
  - render hooks for the shell (command trigger, quick launch, skip link, rail extras, space bar, drawer host, bottom navigation, shortcuts);
  - Livewire experience components;
  - Blade `x-pos.*` components.
- **Existing Filament actions are reused, never re-implemented:**
  - Home mounts the leave, attendance and HR-request actions.
  - Drawers deep-link to Employee 360 actions with `?action=`.
- **No business logic in the presentation layer.** Read models (ApprovalCenter, WorkInbox, HomeComposer, ChangeFeed, CommandSearch, IntentSearch) only read, through the same policies and scopes. ApprovalDecisions only forwards a decision to the owning service.
