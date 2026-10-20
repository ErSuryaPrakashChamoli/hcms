# PeopleOS design system

This is the source of truth for how PeopleOS looks, moves and behaves. **UX.15.4** turned it into a governed system: every colour, size, radius, shadow, duration and layer in the interface comes from a token, and every component is built from those tokens.

**Implementation.**
- `resources/css/filament/admin/theme.css`: tokens, Filament overrides, component classes.
- `resources/views/components/pos/*`: Blade components.
- `resources/js/peopleos.js`: Alpine behaviours.
- `app/Livewire/Experience/*`: hosts for command, drawer, peek and assistant.

The research behind each decision is in `PeopleOS-UX-Research.md` (UX.1) and `UX-15-Research-and-Audit.md` (UX.15).

**Governance rules (checked in UX.15.4).**
- Component rules use tokens only. Literal values are allowed for `0`, `1px` hairlines and layout dimensions (widths, heights, grid tracks), and `#000` inside masks.
- At UX.15.4 the theme contains **0 literal font sizes** (was 20), **0 literal radii** (was 10 values) and **no colour literals outside the token blocks** (was 20+).
- New colours, sizes or radii are added as tokens here first, with a reason.

## 1. Principles

1. **Work first.** Every screen answers "what matters, what changed, what needs me, what can I do, what happens next". Navigation is by outcome, not by module.
2. **Person + work + context + action + insight + automation.** A workspace brings these together. Modules are discoverable, not the first thing seen.
3. **Peek → Drawer → Workspace.** One information model everywhere:
   - **peek:** a small card on hover or focus;
   - **drawer:** more information and actions, beside the page;
   - **workspace:** the complete experience.
4. **Colour means something.** Neutral for normal information. Indigo for identity (primary action, selection, focus, links). Gold for high-value moments. Green, amber and red for state. Nothing is coloured for decoration.
5. **Calm and information-rich.** Spacious, but not empty. Streams, timelines and sentences instead of walls of cards; a surface only where an object is independent.
6. **The UI never decides access.** Every surface asks the same policies, scopes and field rules as the system of record.
7. **Accessible by construction.** Contrast checked per token, everything by keyboard, status never by colour alone, motion optional.

## 2. Colour

Tokens live on `:root` (light) and `.dark` (a separately designed set, not an inversion). Tailwind utilities can use them as `bg-pos-surface`, `text-pos-primary` and so on (the `@theme` mapping).

### 2.1 Meaning

| Colour | Means | Used for | Never used for |
|---|---|---|---|
| Neutral | Normal information | Text, surfaces, borders, icons, default chips, avatars, non-semantic categories | — |
| Indigo (iris) | PeopleOS identity | Primary action (one per area), selection, focus ring, links, the active-section indicator | Neutral status chips, decoration, backgrounds of whole sections |
| Gold (champagne) | High-value moments | Milestones, recognition, promotions, the lifetime record, the "generated" label on intelligence | Warnings |
| Green | Positive / completed | Done, approved, healthy, joined | Decoration |
| Amber | Attention | Due soon, needs a look | Decoration |
| Red | Critical / destructive | Overdue, blocked, rejected, destructive actions | Decoration |
| Blue | Informational | Neutral notices, moves | Primary actions |

### 2.2 Tokens

| Token | Light | Dark | Use |
|---|---|---|---|
| `--pos-canvas` | `#f5f6f8` | `#0e0f13` | App background |
| `--pos-surface` | `#ffffff` | `#16181d` | Panels, drawers, workspaces' independent objects |
| `--pos-surface-muted` | `#eef0f3` | `#1c1f25` | Wells, table headers, neutral fills |
| `--pos-surface-elevated` | `#ffffff` | `#20232a` | Peek, menus, command center |
| `--pos-surface-sunken` | `#fafbfc` | `#121418` | Change previews, quiet insets |
| `--pos-border` / `-strong` | `#e6e8ec` / `#d6d9e0` | `#2a2e36` / `#3a3f49` | Hairlines, outlines |
| `--pos-text` | `#15171d` | `#ecedf1` | Primary text |
| `--pos-text-secondary` | `#4b515d` | `#b9bdc7` | Supporting text |
| `--pos-text-muted` | `#646a77` | `#8f95a2` | Captions, hints, labels |
| `--pos-text-disabled` | `#9ba1ad` | `#5f6470` | Disabled controls (with the reason in text nearby) |
| `--pos-primary` / `-strong` / `-soft` | `#574bc4` / `#463ba6` / `#efeefb` | `#a39ef5` / `#c1bdf8` / 14 % | Identity |
| `--pos-accent` / `-soft`, `--pos-gold` | `#8a6418` / `#f6efe1`, `#c9a25a` | `#e2b86b` / 13 % | High-value moments (text-safe and decorative) |
| `--pos-success` / `-soft` | `#0f7a55` / `#e4f3ec` | `#4cc797` / 13 % | Positive, completed |
| `--pos-warning` / `-soft` | `#9a5309` / `#fbf0dc` | `#f0b44c` / 13 % | Attention |
| `--pos-danger` / `-soft` | `#b42335` / `#fbe8eb` | `#f27b84` / 13 % | Critical, destructive |
| `--pos-info` / `-soft` | `#2a5fbf` / `#e7eefa` | `#72a5f2` / 13 % | Informational |
| `--pos-on-fill` | `#ffffff` | `#0e0f13` | Text on filled buttons and badges |
| `--pos-rail-*` | ink `#0f1424` → `#141a2e`; text `#c5cad8`; muted `#8b92a6`; indicator `#9d96f2`; gold `#e2c48c`; badge `#c4314b` | `#0a0b0e` → `#0f1116` | The midnight rail in both themes; the active item is marked by an indicator, not filled |
| `--pos-avatar-0…4-*` | neutral, slate, rose, sage, sand | tuned for dark | Avatar initials; no meaning attached |
| `--pos-chart-1…5` | iris, teal, gold, rose, slate | lighter set | Data visualisation, in this order |

**Legacy category tones** (`rose`, `emerald`, `sky`, `amber`, `gold`, `violet`, `indigo`, `teal`) resolve to meaning:

| Tone | Resolves to |
|---|---|
| rose | danger |
| emerald | success |
| sky | info |
| amber | warning |
| gold | accent |
| violet, indigo, teal | neutral |

**Filament palette** (`AdminPanelProvider::colors`):
- Primary is hand-tuned so 600 = `#574bc4` and 700 = `#463ba6`. Both 500 and 600 take white text, because Filament may pick either for a button.
- Gray is the same cool neutral as `--pos-n-*` (OKLCH hue 255, very low chroma). UX.15 replaced the earlier violet-grey.

### 2.3 Contrast (WCAG 2.2 AA, measured for UX.15.4)

| Pair | Light | Dark |
|---|---|---|
| text / surface | 17.9 | 15.2 |
| text-secondary / surface | 8.0 | 9.4 |
| text-muted / surface · canvas · muted well | 5.4 · 5.0 · 4.8 | 5.9 · 6.4 · 5.5 |
| primary / surface | 6.5 | 7.4 |
| primary-strong / primary-soft (selected state) | 7.5 | — |
| on-fill text on primary | 6.5 (white) | 8.0 (graphite) |
| accent (gold) / surface · soft | 5.4 · 4.7 | 9.6 |
| success / surface · soft | 5.3 · 4.7 | 8.4 |
| warning / surface · soft | 5.8 · 5.1 | 9.6 |
| danger / surface · soft | 6.5 · 5.5 | 6.7 |
| info / surface · soft | 6.0 · 5.2 | 7.1 |
| rail text · muted · indicator · gold / rail | 11.2 · 5.9 · 7.1 · 10.9 | — |
| white on rail badge | 5.4 | — |

Every text pair is at least 4.5:1.

### 2.4 Rules

- Status always pairs colour with words or an icon (`<x-pos.status>`, `<x-pos.state>`, stream marks with titles).
- One primary (indigo) action per area. Secondary actions are neutral.
- Gradients appear in two places only: the rail and the intelligence mark. Working surfaces are flat.

## 3. Typography

| Family | Typeface | Use |
|---|---|---|
| Sans | **Instrument Sans** 400 / 500 / 600 | Everything except display |
| Display | **Instrument Serif** 400 | Page titles and the Home greeting only |
| Code | JetBrains Mono | IDs and codes |

**Scale.** Eight steps; nothing is smaller than 12 px.

| Token | Size / line | Classes | Use |
|---|---|---|---|
| `--pos-fs-caption` | 12 / 16 | `.pos-label`, `.pos-sec-title`, `.pos-ws-eyebrow`, `.pos-kbd`, badges | Overlines (uppercase, +0.06em), counts, keys |
| `--pos-fs-meta` | 13 / 18 | `.pos-caption`, `.pos-meta`, `.pos-body-sm`, `.pos-stream-meta` | Meta lines, hints, table text in compact mode |
| `--pos-fs-body` | 14 / 21 | `.pos-body`, `.pos-stream-title`, Filament body | Running text, list titles, controls |
| `--pos-fs-lead` | 16 / 24 | `.pos-lead`, `.pos-ws-brief`, `.pos-h3` | The workspace brief, item headings |
| `--pos-fs-section` | 18 / 26 | `.pos-section-title`, `.pos-h2` | Section headings |
| `--pos-fs-metric` | 22 / 28 | `.pos-figure-value`, `.pos-metric` | Figures |
| `--pos-fs-title` | 28 / 34 | `.pos-ws-title`, `.fi-header-heading`, `.pos-title` | Page titles (serif) |
| `--pos-fs-display` | 36 / 42 | `.pos-ws-title-display`, `.pos-display` | The Home greeting (serif) |

**Numbers.** Tabular figures (`.pos-num`, figures, timelines, tables).

**Measure.** Running text stays under about 72 characters (`.pos-ws-head-text`, state text 52 ch).

## 4. Space, shape, elevation

**Spacing.** 4 px grid tokens: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64.

| Rhythm token | Comfortable | Compact | Phone |
|---|---|---|---|
| `--pos-gutter` (page side) | 24 | 24 | 16 |
| `--pos-section-gap` (between workspace sections) | 32 | 24 | 24 |
| `--pos-card-pad` (panel padding) | 20 | 14 | 16 |
| `--pos-row-pad-y` (stream and table rows) | 10 | 6 | 10 |
| `--pos-gap` (inside groups) | 16 | 12 | 16 |

**Radius.**

| Token | Size | Use |
|---|---|---|
| `sm` | 4 | Small marks, keys, inline highlights |
| `md` | 8 | Buttons, inputs, rows, rail items |
| `lg` | 12 | Panels, tables, peek |
| `xl` | 16 | Drawers, dialogs, command center |
| `pill` | 999 | Status, chips, avatars, person chips |

**Elevation.**

| Token | Use | Dark mode |
|---|---|---|
| `--pos-shadow-0` | Flat: hairline only | Hairline |
| `--pos-shadow-1` | Resting panels | Hairline |
| `--pos-shadow-2` | Floating: peek, menus, hover lift | Strong hairline + deep soft shadow |
| `--pos-shadow-3` | Overlay: drawer, dialog, command center | Strong hairline + deeper shadow |

**Surfaces.**
- Canvas is the background, and workspaces sit directly on it.
- A panel (surface plus shadow-1) is used only for an independent object: a decision, a person card, the intelligence panel, a stream.
- Panels never nest.

**Layers.**

| Token | Value |
|---|---|
| `--pos-z-sticky` | 20 |
| `--pos-z-nav` | 40 |
| `--pos-z-overlay` | 60 |
| `--pos-z-drawer` | 61 |
| `--pos-z-command` | 62 |
| `--pos-z-peek` | 70 |

## 5. Iconography

Heroicons:
- **Outline 24** for navigation and stream icons.
- **Mini 20** inline and in buttons.

Icons are neutral by default and take a semantic colour only with a meaning (stream icon tones, state icons). An icon never carries meaning alone; it always has text, or an `aria-label` when it is the only content of a button.

## 6. Interaction states

| State | Token | Treatment |
|---|---|---|
| Hover | `--pos-state-hover` | A 4 % text-tinted fill on rows, chips and ghost buttons; panels may lift to shadow-2 |
| Pressed | `--pos-state-pressed` | 8 % fill; buttons translate 1 px |
| Selected | `--pos-state-selected` / `-text` | Primary-soft fill with primary-strong text (chips, lens, focused rows, section navigation underline) |
| Focus | `--pos-focus`, `--pos-focus-ring` | 2 px outline with 2 px offset on `:focus-visible`; rows get an inset ring |
| Disabled | `--pos-state-disabled`, `--pos-text-disabled` | A muted fill and disabled text (not opacity), with the reason nearby |
| Error / success / warning | status tokens | Always with words; errors say what to do |
| Loading | `<x-pos.state variant="loading">`, `.pos-skeleton` | Skeleton when the layout is known, after a short delay |

## 7. Motion

| Token | Duration | Use |
|---|---|---|
| `--pos-dur-1` | 160 ms | Micro: hover, press, chip toggle, peek in |
| `--pos-dur-2` | 220 ms | Small: drawer out, command center in, list item change |
| `--pos-dur-3` | 280 ms | Medium: drawer in, page enter, section reveal |
| `--pos-dur-4` | 360 ms | Large: journey progression, meter fill, success check |

- **Easing:** `--pos-ease` (standard, 0.2 0 0 1), `--pos-ease-emphasised` (0.2 0.8 0.2 1) and `--pos-ease-in` (exits).
- **Peek delay:** `--pos-peek-delay` is 350 ms, so passing the pointer over a name does not open cards.
- **Purpose only.** Motion shows where something came from (peek and drawer from their trigger), where it went (a decided item leaving the queue) and what changed (journey progression). Nothing loops except the skeleton shimmer and a busy spinner.
- **Reduced motion:** every animation and transition collapses to 1 ms and smooth scrolling is off.

## 8. Data visualisation

- Prefer a sentence and a number to a chart. A chart must answer the question in its title.
- SVG or CSS only, with no chart library. Each chart has `role="img"` and an `aria-label` stating the change in words.
- Changes are coloured by meaning (`data-meaning="good|bad"`), not by direction: more leavers is bad, more joiners is good.
- Small populations are suppressed below the privacy threshold (WorkforceMetrics). Restricted figures show "—" with the reason. The UI never estimates a figure the viewer may not see.

## 9. Density

`<html data-pos-density="comfortable|compact">` is set per person (Preferences) before first paint. Compact tightens row padding, panel padding and gaps (section 4). It is intended for operators who live in tables. The People directory and Filament tables follow it.

## 10. Layout and responsive rules

| Name | Width | Shell |
|---|---|---|
| Phone | < 768 | Single column; bottom bar (Home, Work, Actions, People, Services) with notifications and profile in the top bar; drawers as bottom sheets; search as an icon; 16 px gutter |
| Tablet | 768–1279 | Single or two columns; sidebar as an overlay or collapsed rail |
| Desktop | ≥ 1280 | Rail plus workspace; workspaces may add a 352 px side column (`.pos-ws-cols`) |

**Workspace anatomy (`.pos-ws`):**
1. **Header (`.pos-ws-head`):** eyebrow (where am I), title, a brief sentence (what matters, from real counts), and actions (what can I do).
2. **Optionally, section navigation (`.pos-sectnav`):** a quiet underline, not a tab strip.
3. **Sections (`.pos-sec`):** titled regions of streams, timelines, figures and panels.

**Touch targets.** At least 24 × 24 px on desktop and 44 × 44 px on phones; bottom-bar items are 48 px. Nothing important lives only in a tooltip or a peek; the drawer always holds everything the peek shows, plus actions.

## 11. Component system

PeopleOS components, mapped to the brief's component names. One component per job; no parallel systems.

| PeopleOS name | Implementation | Notes |
|---|---|---|
| PeopleShell | Filament panel + render hooks (`resources/views/filament/shell/*`) | Rail, top bar, command trigger, quick launch, bottom bar |
| PeopleNav | `ExperienceNavigation` + `ModuleCatalogue` | Nine sections; each destination's own `canAccess()` |
| PeopleCommand | `CommandCenter` + `.pos-command-*` | ⌘K / Ctrl+K, `/`, `N`; listbox semantics; authorised actions only |
| PeopleSearch | `.pos-search` | Leading icon, live results, clear |
| PeopleCard | `.pos-person-card` (directory) | Opens the drawer; directory fields only |
| PeopleAvatar | `<x-pos.avatar>` | Initials, calm tint, `xs`–`xl` |
| PeoplePerson | `<x-pos.person id name sub>` | A person anywhere: hover or focus = peek, click = drawer |
| PeoplePeek | `PeekHost` + `PersonPeek` + `posPeek` | Directory fields the viewer may already see; same refusal as a missing record |
| PeopleDrawer | `DrawerHost` + `.pos-drawer` | Stacking with Back, focus trap, Esc, focus return; bottom sheet on phones |
| PeopleTimeline | `.pos-tl` | Time-ordered events with a spine; tone by meaning |
| PeopleJourney | `<x-pos.journey>` | Lifecycle stages from real data |
| PeopleChange | `<x-pos.change changes effective>` | Field, before (struck), after (emphasised), effective date; `<x-pos.before-after>` delegates to it |
| PeopleApproval | `<x-pos.approval-card>` | Decision with context; confirm in place |
| PeopleInsight / PeopleAI | `.pos-intel` (+ `<x-pos.ai-response>`) | Contextual intelligence with sources and a "generated" label |
| PeopleTable | Filament tables (restyled), `.pos-table` | The records level; density-aware |
| PeopleFilter | `.pos-chip[aria-pressed]`, `.pos-select`, `.pos-segmented` | State in the URL where it matters |
| PeopleMetric | `<x-pos.figure value label delta meaning>` | One figure primitive; replaces KPI tiles |
| PeopleActivity | `.pos-stream` | Divided rows of things to read or act on |
| PeopleSection | `<x-pos.section title count>` | A titled region, not a card |
| PeopleEmptyState / PeopleSkeleton | `<x-pos.state>` (`<x-pos.empty>` kept), `.pos-skeleton` | Empty, caught up, filtered, loading, error + retry, denied, stale, partial failure |
| PeopleToast | Filament notifications (restyled) | Short result text |
| PeopleModal | Filament modals (restyled) | For existing domain action forms |
| PeopleForm | Filament forms (restyled) + step flows | Context → change → review → confirm for high-value changes |
| PeopleOrganisationMap | `OrganisationMap` + `.pos-org-*` | Progressive tree, zoom, pan, focus, relationships |
| PeoplePulse | Workforce pulse (`WorkforceCommandCentre`) | Narrative rows with drill-down |
| PeopleStatus / PeopleBadge | `<x-pos.status>`, `.pos-moment`, `.pos-count` | Words plus colour; gold moments |
| PeopleAction | `.pos-btn-*`, `.pos-action-tile` | One primary action per area |

## 12. Accessibility rules

- **Contrast:** AA for all text (section 2.3); focus always visible.
- **Keyboard:**
  - Ctrl/⌘+K and `/` search; `N` new; `?` shortcuts;
  - `G` then `H`/`W`/`A`/`P`/`O` navigates;
  - `J`/`K` move; `A`/`R` decide; Esc closes;
  - shortcuts pause while typing.
- **Peek:** reachable by keyboard focus (with `aria-describedby` on the trigger). It is supplementary; Enter opens the drawer with the same information and the actions.
- **Dialogs and drawers:** `role="dialog"`, `aria-modal`, a labelled title, focus trap, Esc, and focus returned to the trigger.
- **Command center:** combobox plus listbox with `aria-activedescendant`.
- **States:** errors use `role="alert"`; loading uses a polite live region; denials explain why.
- **Other:** a skip link first; reduced motion honoured; colour never the only signal.

## 13. Writing

- **Plain words, sentence case, active voice.** Buttons state the result: "Confirm transfer", "Approve leave", "Return to proposer".
- **The workspace brief** is one sentence from real counts ("2 decisions are waiting for you and 1 thing is due today."). Never invented.
- **Empty states:**
  - name what is empty, why, and the next step;
  - "You're all caught up" when work is done;
  - "Nothing matches these filters" when a filter caused it.
- **Errors:** what happened and what to do, without blame. Domain refusals are shown verbatim.
- **Intelligence copy** states facts and their source, and is labelled "Generated". It never recommends an employment, pay or compensation decision.

## 14. Filament integration

- **Filament stays the engine:** forms, validation, tables, resources, policies, and actions with their domain services.
- **The PeopleOS layer adds:**
  - the theme;
  - custom navigation;
  - render hooks for the shell;
  - Livewire experience hosts (command, drawer, peek, assistant);
  - Blade `x-pos.*` components;
  - read models that only read, through the same policies and scopes.
- **Existing Filament actions are reused**, never re-implemented. Pages mount them (`?action=`); drawers deep-link to them.
- **No business logic in the presentation layer.** `ApprovalDecisions` only forwards a decision to the owning service.
