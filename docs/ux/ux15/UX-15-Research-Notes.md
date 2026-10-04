# UX.15 Market Research Notes — Enterprise HCM Redesign (PeopleOS by Markedge)

Research date: 2026-10-04. Method: WebSearch + WebFetch against primary sources (official docs, help centres,
design-system sites, changelogs) wherever reachable.

**Evidence legend**
- **FETCHED** — the page itself was retrieved and read: via WebFetch (a summarising fetch; quotes are as returned by
  the fetch), via `curl` + text extraction where WebFetch was blocked or returned only navigation (Canvas, Lattice,
  Culture Amp), via Apple's JSON data endpoints for the HIG, or via `pdftotext` for PDFs.
- **EXCERPT** — only a search-engine excerpt/summary of the page was seen; treat as lower confidence.
- "NOT VERIFIED" marks something that was looked for but could not be confirmed from a primary source.

Scope note: these are observations of *mechanisms* (how a pattern works), not endorsements. Marketing claims are
excluded or flagged. Several HCM vendor help centres (Rippling, HiBob, Personio, Deel, Lattice, BambooHR) block or
partially render automated fetches; where that happened the observation is marked EXCERPT.

---

## 1. Linear (issue tracking — reference for speed, keyboard-first, views)

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Information architecture | Strict object hierarchy: Workspace > Teams (each owns its workflow, cycles, conventions; can nest as sub-teams) > Issues ("the fundamental unit of work"); Projects group issues toward an outcome; Initiatives sit above projects; Views are "different perspectives on the same work … without changing the underlying work". A small, named set of nouns that every screen maps onto. | https://linear.app/docs/conceptual-model | FETCHED |
| Command system | Cmd/Ctrl+K command menu "runs any action or jumps to any page when you type its name"; positioned as the fallback for every shortcut ("if you forget any other shortcut, open the command menu"). Command menu is *selection-aware*: with issues selected, Cmd+K shows actions for that selection. Peek auto-activates while arrowing through command-menu results. | https://linear.app/enablement/guides/navigating-linear ; https://linear.app/docs/select-issues ; https://linear.app/docs/peek | FETCHED (guide, select-issues, peek); shortcut-fallback quote EXCERPT |
| Keyboard navigation | Two-key "go to" sequences (G then I = Inbox, G then M = My Issues, G then P = Projects); `/` opens search; `?` (or Cmd+/) shows the shortcut sheet; search accepts a raw identifier (e.g. `LIN-123`). Stated aim: "every common action should be reachable in two keystrokes or fewer" (third-party excerpt). | https://linear.app/enablement/guides/navigating-linear | FETCHED (G-sequences, `/`, `?`); "two keystrokes" EXCERPT |
| Contextual actions → learnability | Right-click context menus on any issue list the same actions *with their shortcuts shown*, described as "a great way to learn the keyboard shortcuts" — the menu doubles as a shortcut tutor. | https://linear.app/enablement/guide/navigating-linear | EXCERPT |
| Peek (preview without navigation) | `Space` toggles a preview of the focused issue/project (hold Space = temporary preview); ↑/↓ moves to adjacent rows while the preview updates; Esc closes. Shows description, assignee, status, priority, cycle, labels, estimate, dates; for projects shows the project graph. Explicitly modelled on macOS Quick Look. | https://linear.app/docs/peek | FETCHED |
| Tables/lists — selection & bulk | Select with `X` on the focused row, Shift+click, hover-revealed checkbox at row's left edge, Cmd+A (after filtering); Shift+↑/↓ extends a range. With a selection, "common bulk actions will show up at the bottom" (floating bulk bar), plus Cmd+K and right-click both operate on the selection. | https://linear.app/docs/select-issues | FETCHED |
| Display options vs filters | Display options (Shift+V) are separated from filters: layout (list/board via Cmd+B; timeline for projects with week/month/quarter/year zoom), grouping + sub-grouping (swim lanes), ordering, which properties are shown, show empty groups, sub-issue toggle, density. Personal changes persist; "Set as default" pushes a workspace default; "Reset to default" reverts. | https://linear.app/docs/display-options | FETCHED |
| Filters | `F` opens filter menu; type a value directly (team, status, user) to skip category navigation ("quick filters"); nested AND/OR groups; operator auto-adapts ("is" → "is either of" when multiple values chosen); filter state is encoded in the URL so a link reproduces the view. | https://linear.app/docs/filters | FETCHED |
| Saved views | Any filtered list can be saved (Opt/Alt+V). Three sharing scopes: personal, team/project, workspace. Starred views appear in the sidebar; a favourite view can be set as the user's landing page. Copying a link does not grant access — the view must be shared first. | https://linear.app/docs/custom-views | FETCHED |
| Inbox / notification triage | Inbox is a triage queue, not a feed: Priority tab, J/K to move, `U` toggle read, Backspace delete, `H` snooze until a time, Shift+S unsubscribe, Cmd+F quick-search within inbox by title/ID/type/assignee/team. Auto-subscription on create/assign/mention. | https://linear.app/docs/inbox | FETCHED |
| Analytics / storytelling | Project & initiative updates carry a health state (On track / At risk / Off track); leads receive cadence reminders in their local timezone with nudges 1 and 2 working days later; updates auto-include a progress report (target-date changes, lead changes, milestone progress) shown only when progress moved >2%; optional agent-drafted update text from recent activity; distributed to Slack and Inbox. Narrative + auto-diff, rather than a dashboard. | https://linear.app/docs/initiative-and-project-updates | FETCHED |
| Visual system / density / colour | 2024 redesign: reduced 98 theme variables to 3 inputs (base colour, accent colour, contrast) generated in LCH (perceptually uniform lightness) with an automatic high-contrast option; Inter Display for headings, Inter for body; less blue in chrome for a "neutral and timeless" look; text/icons darker in light mode and lighter in dark mode for contrast; alignment of sidebar/tab icons and labels tuned so density is "felt" rather than seen. | https://linear.app/now/how-we-redesigned-the-linear-ui | FETCHED |
| Calm UI (2026) | March 2026 refresh: headers, navigation and view controls made consistent across projects, issues, reviews and documents; icons redrawn/resized; navigation sidebar dimmed "allowing the main content area to stand out". Third-party summaries also mention compacted tabs and softer borders (not stated in the changelog itself). | https://linear.app/changelog/2026-03-12-ui-refresh | FETCHED (compact tabs/softer borders: EXCERPT only) |
| Navigation personalisation | Sidebar can be reordered by drag, items hidden behind a "More" menu, and unread shown as count or dot (Dec 2024). Mobile (Jan 2026): users rearrange the bottom toolbar and pin specific projects/initiatives/documents. | https://linear.app/changelog/2024-12-18-personalized-sidebar ; https://linear.app/changelog/2026-01-22-customize-your-navigation-in-linear-mobile | EXCERPT (sidebar); FETCHED (mobile) |
| Mobile | Oct 2025 mobile redesign: custom frosted-glass material for depth/contrast, bottom toolbar navigation for core workflows, "Create issue" button at top of every screen. | https://linear.app/changelog/2025-10-16-mobile-app-redesign | EXCERPT |
| Undo | Moving an issue between teams can be undone with Cmd/Ctrl+Z (docs). A general toast-with-undo pattern was NOT VERIFIED in docs. | https://linear.app/docs/editing-issues | FETCHED |

---

## 2. Notion (workspace — reference for peek, views, record layouts, progressive disclosure)

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Peek modes (record opening) | Every database view has an "Open pages as" setting: **Side peek** (slides in from the right, list stays visible — default), **Center peek** (centred modal for focus), **Full page**. The choice is per view, so a triage table can use side peek while a document library opens full page. Ctrl+Shift+J/K (Mac) moves to next/previous record inside peek without closing it. | https://www.notion.com/help/views-filters-and-sorts ; https://www.notion.com/help/keyboard-shortcuts ; https://bullet.so/blog/how-to-open-center-peek-notion/ | FETCHED (views, shortcuts); default/side-peek detail EXCERPT |
| Record layout (profile-like pages) | Database page "Layouts": a heading area where up to **15 properties can be pinned** (overflow becomes a horizontal scroller); a **property group** split into named, draggable sections; a collapsible right-hand **details panel** that can hold properties or modules; a **tabbed** layout with a "Content" tab plus tabs showing related database views. One layout applies to every page in the database and to new pages. Property search box filters long property lists in real time. | https://www.notion.com/help/layouts | FETCHED |
| Views | Same data, many layouts: Table, Board, Timeline, Calendar, List, Gallery, Chart (bar/line/donut); forms feed databases. Each view keeps its own property visibility, tab display (icon/text/both), and open-mode. Views are tabs at the top of a database. | https://www.notion.com/help/views-filters-and-sorts | FETCHED |
| Filters | Simple filters as chips; advanced filters with AND/OR groups "nested up to three layers deep". Filters/sorts can be saved for everyone or kept personal (a viewer can tweak without changing the shared view). | https://www.notion.com/help/views-filters-and-sorts | FETCHED |
| Grouping | Group by property into collapsible sections, with optional sub-grouping as a second level; select/multi-select sort by their custom defined order rather than alphabetically. | https://www.notion.com/help/views-filters-and-sorts | FETCHED |
| Search / command | Cmd/Ctrl+P or Cmd/Ctrl+K opens search (with recent pages shown before typing). Filters: title-only, created by, teamspace, "in" (parent page), date range; sort by relevance/last edited/created. AI search across workspace and connected apps. Explicit documented gaps: mentions, comments, select values not indexed. | https://www.notion.com/help/search | FETCHED |
| Inline command grammar | `/` opens a block-insertion menu; `@` mentions people (notifies), pages (auto-renames), dates, and `@remind` reminders; Markdown shortcuts while typing; `/turn` converts block type preserving content. A small set of trigger characters gives access to most of the product without menus. | https://www.notion.com/help/keyboard-shortcuts | FETCHED |
| Home / personal workspace | Home tab with sections: Upcoming events, Recents, Favorites, Agents, Teamspaces, Shared/Private pages, plus **My Tasks** which aggregates assigned items across all databases. Each section's item count can be set, sections reordered or hidden. | https://www.notion.com/help/home-and-my-tasks | FETCHED |
| Navigation (2026) | Notion 3.4 (Mar 2026) reorganised an overcrowded sidebar into four tabs (pages, agent chats, meetings, notifications); sections are user-customisable; shipped opt-in first. Also added "archive" as distinct from delete to improve search relevance. | https://www.notion.com/en-gb/releases/2026-03-26 | FETCHED |
| Analytics / dashboards | Notion 3.4 added a **Dashboard view** (charts + KPIs + metrics "so you can see what's up and what's changed"); can be built/maintained by describing it to the Notion Agent; Business/Enterprise only. Earlier, Chart views were a database layout. | https://www.notion.com/en-gb/releases/2026-03-26 | FETCHED |
| Progressive disclosure | Tabs block (3.4) organises long pages into clickable sections "without the maze of subpages"; toggles/H4 headings; layouts hide properties via eye icon. | https://www.notion.com/en-gb/releases/2026-03-26 ; https://www.notion.com/help/layouts | FETCHED |
| Permissions UX | Row-level ("page-level access") rules in the Share menu: grant Can view/comment/edit on rows where a Person or Created-by property matches the current user (Business/Enterprise). Users with row access but no database access see an error at the source database. Directly analogous to "employee sees only own records". | https://notion.com/help/guides/assign-custom-database-permissions | EXCERPT |
| Automations | Database automations: triggers (page added, property edited with conditions, every {frequency}) → actions (edit property, add page to another DB, notify member, Gmail, Slack, edit pages in another DB). Automations cannot trigger other automations, but buttons can. | https://www.notion.com/help/database-automations | FETCHED |
| Recovery / error tolerance | Trash keeps pages 30 days; **version history** snapshots every 10 min during editing plus 2 min after last edit; retention 7/30/90 days/unlimited by plan; preview before restore, copy individual blocks from an old version, and a restore is itself reversible. Notion relies on history/trash rather than a toast-undo for deletes (help centre does not document an undo toast). | https://www.notion.com/help/duplicate-delete-and-restore-content | FETCHED |
| Mobile | Mobile bottom bar optimised for capture: search, notifications, create new page "with just one tap". (Source is a 2020 release; current mobile IA NOT VERIFIED.) | https://www.notion.com/en-gb/releases/2020-02-19 | EXCERPT |
| Dark mode | Appearance setting with three options — "Use system setting", Light, Dark — applied across all workspaces on the account; toggle shortcut Cmd/Ctrl+Shift+L. | https://www.notion.com/help/appearance-settings | EXCERPT |

---

## 3. Apple — Human Interface Guidelines (macOS / iOS 26 "Liquid Glass" era)

HIG pages are client-rendered; content was read from Apple's own JSON data endpoints
(`developer.apple.com/tutorials/data/design/human-interface-guidelines/<page>.json`), which back the public HIG pages
at `developer.apple.com/design/human-interface-guidelines/<page>`. Both URLs are listed.

| Topic | OBSERVED (mechanism / rule) | Source URL | Evidence |
|---|---|---|---|
| 2025 design language | June 2025 "broadest software design update ever": a new translucent material, **Liquid Glass**, spanning iOS 26, iPadOS 26, macOS Tahoe 26, watchOS 26, tvOS 26; it "dynamically transform[s] to help bring greater focus to content". | https://www.apple.com/newsroom/2025/06/apple-introduces-a-delightful-and-elegant-new-software-design/ | EXCERPT |
| Layering (chrome vs content) | Liquid Glass is "a distinct functional layer for controls and navigation elements — like tab bars and sidebars — that floats above the content layer". "Don't use Liquid Glass in the content layer"; "use Liquid Glass effects sparingly". Regular variant (blurs, keeps text legible — for sidebars, alerts, popovers) vs Clear variant (only over visually rich media; add ~35% dimming layer on bright backgrounds). | https://developer.apple.com/design/human-interface-guidelines/materials (data: …/materials.json) | FETCHED |
| Sidebar navigation | Sidebars for top-level areas/collections; "show no more than two levels of hierarchy in a sidebar" — deeper trees use a split view with a content list between sidebar and detail. Let people customise sidebar contents; group with disclosure controls; let people hide the sidebar but "avoid hiding the sidebar by default". Icon colour must "serve a clear purpose" (default to accent; fixed colours sparingly, e.g. Mail's VIP). On iPhone, prefer a tab bar first. | https://developer.apple.com/design/human-interface-guidelines/sidebars | FETCHED |
| Split view / master–detail | 2–3 panes: sidebar → content list → detail/inspector. "Persistently highlight the current selection in each pane that leads to the detail view" to keep people oriented. macOS: thin dividers, allow hiding panes with "multiple ways to reveal hidden panes"; iPadOS must handle narrow/compact/intermediate widths. | https://developer.apple.com/design/human-interface-guidelines/split-views | FETCHED |
| Toolbars / primary action | Max ~3 groups (leading, centre, trailing) grouped by function & frequency; exactly **one** primary action styled `.prominent` on the trailing side (e.g. Done/Submit); overflow is system-managed ("don't add an overflow menu manually"), a More menu for secondary actions; titles under 15 characters, never the app name; Liquid Glass era: fewer tinted toolbar backgrounds, monochrome toolbars over colourful content. | https://developer.apple.com/design/human-interface-guidelines/toolbars | FETCHED |
| Tab bars (mobile) | Tab bar is for navigation "not to provide actions" (actions go in toolbars). Default ≤5 tabs; "avoid overflow tabs"; "don't disable or hide tab bar buttons, even when their content is unavailable" — explain empty instead; badges reserved for critical info; optional dedicated search tab at trailing end; tab bar floats on Liquid Glass and can minimise on scroll; iPad tab bar can adapt into a sidebar (`sidebarAdaptable`). | https://developer.apple.com/design/human-interface-guidelines/tab-bars | FETCHED |
| Sheets / modals | Sheets for "a simple task that they can complete before returning to the parent view"; complex tasks → full-screen modal or separate window. Cancel/Close (discard), Done (complete/save), Back (previous step in multi-step flow); "provide an alternative to the Done button". One sheet at a time — close the first before showing another. iPhone medium/large detents enable progressive disclosure; grabber cycles detents. Swipe-to-dismiss with unsaved changes → confirm via action sheet. | https://developer.apple.com/design/human-interface-guidelines/sheets | FETCHED |
| Alerts & destructive actions | Alerts interrupt — use only for essential, actionable info; "avoid displaying alerts for common, undoable actions, even when they're destructive"; alert only for "uncommon destructive action that they can't undo". Buttons are verbs ("Erase", "Delete"), not "OK"; destructive style only for actions people didn't deliberately choose; default button trailing, Cancel leading. | https://developer.apple.com/design/human-interface-guidelines/alerts | FETCHED |
| Undo | Support undo to "help people explore and experiment safely". Labels describe the result ("Undo Address Change"); no unnecessary limits on undo depth; "show the results of an undo" (scroll to the restored item); Cmd+Z / Shift+Cmd+Z in the Edit menu. | https://developer.apple.com/design/human-interface-guidelines/undo-and-redo | FETCHED |
| Feedback / status | "Integrate status feedback into your interface" near the items it describes (e.g. unread counts in toolbar); warn only for "unexpected and irreversible" data loss; confirm completion of significant actions; "show people when a command can't be carried out and help them understand why"; multi-modal feedback (colour + text + sound + haptics). | https://developer.apple.com/design/human-interface-guidelines/feedback | FETCHED |
| Loading | "Show something as soon as possible" — placeholder text/graphics replaced progressively; determinate vs indeterminate indicators by whether duration is known; "let people do other things … while they wait". | https://developer.apple.com/design/human-interface-guidelines/loading | FETCHED |
| Empty states & error copy | Empty states: "guide people on actions they can take, and give them a button or link to do so". Errors: show "as close to the problem as possible, avoid blame, and be clear about what someone can do to fix it" ("Choose a password with at least 8 characters" beats "That password is too short"); avoid "oops!". Verbs on buttons ("Send" not "Let's do it!"); "Get Started"/"Done" bookend flows. | https://developer.apple.com/design/human-interface-guidelines/writing | FETCHED |
| Search | Give important search a primary position; one searchable location app-wide; "clearly display the current scope of a search" (scope bar, tokens, placeholder); show recent searches before typing + predictive suggestions; consider privacy of search history and allow clearing it. | https://developer.apple.com/design/human-interface-guidelines/searching | FETCHED |
| Motion | "Add motion purposefully"; "don't add motion for the sake of adding motion"; "make motion optional" (never the only carrier of information); brief, precise feedback animations; "avoid adding motion to UI interactions that occur frequently"; "let people cancel motion" (never block on an animation). | https://developer.apple.com/design/human-interface-guidelines/motion | FETCHED |
| Typography | Text styles (Large Title, Title 1–3, Headline, Body, Callout, Subhead, Footnote, Caption 1–2) each combine weight, size, leading. Default/minimum sizes: iOS 17/11 pt, macOS 13/10 pt. "Avoid light font weights" (Ultralight/Thin/Light); prefer Regular–Bold; minimise number of typefaces. | https://developer.apple.com/design/human-interface-guidelines/typography | FETCHED |
| Dark mode | Respect the system setting; "don't offer app-specific appearance settings". Contrast ≥4.5:1, aim 7:1 for small custom-colour text. Semantic colours, not hard-coded values. iOS uses **base** (recede) vs **elevated** (advance) background sets for depth. Test Dark + Increase Contrast + Reduce Transparency combinations. Soften white image backgrounds. | https://developer.apple.com/design/human-interface-guidelines/dark-mode | FETCHED |
| Accessibility | Hit targets: iOS default 44×44 pt (min 28×28); macOS 28×28 (min 20×20). Contrast 4.5:1 up to 17 pt, 3:1 for 18 pt+/bold. Don't use colour as the sole indicator (add shape/icon). Text scalable to ≥200%. Full Keyboard Access; don't override system shortcuts. Reduce Motion: replace x/y/z transitions with fades, tighten springs, avoid blur animations. | https://developer.apple.com/design/human-interface-guidelines/accessibility | FETCHED |
| Inspector panels | An "Inspectors" HIG page was looked for but the data endpoint returned 404; the trailing "inspector" pane is referenced inside Split Views guidance only. | https://developer.apple.com/design/human-interface-guidelines/split-views | FETCHED (inspector page NOT VERIFIED) |

---

## 4. Stripe Dashboard (+ Stripe Apps design guidance)

Stripe publishes design rules for third-party apps that live inside its Dashboard (Stripe Apps). These encode the
Dashboard's own conventions (the empty-state doc says it "mirrors the empty state pattern used across the Stripe
Dashboard"), so they are used here as a proxy for Stripe's internal patterns.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Navigation / IA | Sidebar in three tiers: (1) core objects — Home, Balances, Transactions, Customers, Product catalogue; (2) **Shortcuts** — automatically lists recently visited pages, any of which can be pinned; (3) Products (Payments, Billing, Reporting, Connect) with the long tail behind **More**. Settings split into Personal / Account / Product. | https://docs.stripe.com/dashboard/basics | FETCHED |
| Home | Home = analytics/charts plus "important notifications, like unresolved disputes or identity verifications" (exceptions needing action). User customises "Your overview" by adding/removing widgets. | https://docs.stripe.com/dashboard/basics | FETCHED |
| Search (omni-search) | One search box across object types (payments, customers, invoices, payouts, connected accounts, products); top results appear instantly, Enter = all results grouped by object type, each group expandable into a sortable table. Accepts card last4, receipt number, business name, natural dates ("last week"); pasting an object ID jumps straight to the object. Field filters (`email:`, `amount:`, `status:`, `created:`), range operators (`>`, `<`, `..`), type/state filters (`is:customer`, `is:refunded`), negation with `-`, quoted phrases. Search terms live in the URL so searches can be bookmarked/shared. | https://docs.stripe.com/dashboard/search | FETCHED |
| Keyboard | `?` shows the list of keyboard shortcuts for common actions. | https://docs.stripe.com/dashboard/basics | FETCHED |
| Side panel (drawer) | Apps render in a **ContextView** drawer *beside* the Stripe object so users "look at them side-by-side and share context". Default to ContextView; use **FocusView** (blocking backdrop, wider canvas) only for start-to-finish tasks or forms that "shouldn't be easily interrupted"; **SettingsView** for configuration. A dock of app icons opens the drawer. | https://docs.stripe.com/stripe-apps/design | FETCHED |
| Contextual surfaces | Extensions target "surfaces": object **details pages** (with access to the current object), **list pages** (only for features not tied to one object), and **Home** (only for genuinely useful overview). Guidance: meet "users in their existing workflows" rather than sending them to a separate area. | https://docs.stripe.com/stripe-apps/design | FETCHED |
| Constrained styling | "Custom styling of UI elements is intentionally limited … to ensure a high accessibility bar"; colours are restricted because "color contrast is an important aspect of accessible UI". Brand expression confined to an app indicator (colour bar + icon). | https://docs.stripe.com/stripe-apps/design | FETCHED |
| Empty states | Distinguish three empties: first use, filters returned nothing, everything removed. Title states what is missing ("No customers yet."), description <14 words explaining when data appears, action mirrors title ("Add customer", not "Get started"). **Never show a "create first" CTA when items exist but are filtered out** — show "No customers match your filters." plus Clear filters. Section-level empties use a compact dashed-border box. Render order: loading → error (with retry) → empty → content. | https://docs.stripe.com/stripe-apps/patterns/empty-state | FETCHED |
| Loading | Show indicator as soon as fetch begins; match scope (large spinner for the view, medium per section, small inline); `delay` of 200–300 ms to avoid flash on fast loads; keep tab bars visible and put loading *inside* each tab ("If you show a spinner instead of the tab bar, users lose their navigation context"); buttons use a `pending` state that also disables them to prevent double submission. | https://docs.stripe.com/stripe-apps/patterns/loading | FETCHED |
| Feedback (toast vs banner) | Toast = temporary, always triggered by a user action, ≤30 characters / under four words, optional shortcut action, bottom of drawer. Banner = persistent, can appear any time for system-level requirements, title + body, requires an action to dismiss, under the header. | https://docs.stripe.com/stripe-apps/patterns/communicating-state | FETCHED |
| Filters | Filter chips above tables: "suggested" chip shows "+" and opens a menu; "active" chip shows the value and an ✕ to clear; "Clear filters" link appears only when ≥1 filter active; filter labels match column headers; filter before pagination so counts stay accurate. | https://docs.stripe.com/stripe-apps/patterns/filter-controls | FETCHED |
| Multi-step & actions | Multi-step tasks go in FocusView to prevent accidental abandonment; step controls in the footer; final step is the primary button. Action buttons live in the header so they remain visible when content scrolls. | https://docs.stripe.com/stripe-apps/patterns/progress-stepping ; https://docs.stripe.com/stripe-apps/patterns/action-buttons | FETCHED |
| AI assistant | Dashboard assistant opens **in a drawer** (from help icon or from suggested prompts on the Payments analytics page). Three jobs: answer docs/support questions (RAG), answer questions about the user's own data ("Why did Jenny Rosen's last payment fail?", "What caused my authentication success rate to drop last week?"), and act on the user's behalf ("Create a 20% promo code") where "you only need to confirm". Sigma has an NL→SQL assistant with Generate vs Edit modes. | https://docs.stripe.com/assistant ; https://docs.stripe.com/stripe-data/write-queries | FETCHED (assistant); Sigma EXCERPT |
| Mobile | Mobile app scoped to monitor + a few high-frequency actions: customisable home charts (add/remove/reorder), push notifications (daily summary, new payments, disputes), global search, 17 iOS lock-screen metric widgets, detail screens with a **bottom action bar** (Refund, overflow ⋯). Live-mode only; role restrictions enforced (View-only can't refund). Some low-risk actions are reversible without confirmation (deactivate payment link → "Activate" in action bar). | https://docs.stripe.com/dashboard/mobile | FETCHED |
| Dark mode | No native Dashboard dark mode found; a Stripe developer-community thread says it "isn't a high priority right now"; third-party extensions fill the gap. (Embedded Connect components do support dark-mode tokens.) | https://insiders.stripe.dev/t/dashboard-dark-mode/2473 ; https://docs.stripe.com/connect/embedded-appearance-support-dark-mode | EXCERPT |
| Saved views / columns | Reports support "Edit columns" and custom columns; a general saved-view feature for Dashboard lists was NOT VERIFIED in docs. | https://docs.stripe.com/reports/activity-breakdown | EXCERPT |

---

## 5. Ramp (spend management — reference for approvals, exception-first review, "work where you are")

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Unified approvals inbox | One **Inbox** tab holds every approval type (reimbursements, card transactions, bills, spend requests) with a count badge. Readiness gating: the inbox "does *not* include transactions with missing items, transactions with exemptions, or pending transactions that have not cleared" — an item appears only once "all required fields are satisfied". Reviewers therefore only see actionable items. | https://support.ramp.com/hc/en-us/articles/4417421399699-Reviewing-transactions-from-Ramp-cards | FETCHED |
| Grouped bulk approval | Items auto-group "in priority: expenses associated with a Trip, then Spend Limit, then cardholder"; "all expenses within a group can be approved at once"; bulk approve via row or group checkboxes. | https://support.ramp.com/hc/en-us/articles/4417421399699-Reviewing-transactions-from-Ramp-cards | FETCHED |
| Nuanced rejection | Rejection is not a dead end: reviewer chooses **Request changes** or **Request repay**, each opening a thread with the employee. A final approver's approval auto-dismisses remaining missing-item requirements. | https://support.ramp.com/hc/en-us/articles/4417421399699-Reviewing-transactions-from-Ramp-cards | FETCHED |
| Status sub-tabs | Card transactions list has sub-tabs: All, Needs review, Flagged, Fully approved, Declined — a fixed, small set of status lenses rather than free-form filtering. | https://support.ramp.com/hc/en-us/articles/4417421399699-Reviewing-transactions-from-Ramp-cards | FETCHED |
| AI recommendation + rationale | Policy Agent sorts expenses into homepage sections: "ready to approve", "to review", "ready to reject". Each expense's activity feed shows the recommendation, "the agent's rationale and cited policy text", re-assessments when new info arrives, and "the policy version used". Humans keep "final authority"; overrides are logged in the audit log. Admins choose whether non-admin reviewers see the assessments. Auto-approval only within admin-set conditions (e.g. amount < $1,000, programs, low-risk merchants). | https://support.ramp.com/hc/en-us/articles/47618318137875-Use-Policy-Agent-for-approvals | FETCHED |
| Exception-first review | Stated outcome: agents escalate "only the 10–15% of expenses that need further human judgment"; controllers can add "hidden notes" (agent-only guidance not shown in the employee-facing policy). (Accuracy figures are vendor claims.) | https://ramp.com/blog/ramp-policy-agent-ga-launch ; https://ramp.com/new-on-ramp-q4-2025 | FETCHED |
| Approvals outside the app | Managers/admins "approve, edit, or reject the request directly in Slack"; routing respects roles (managers only for direct reports); "all funds created or requested on Slack will appear … along with the full audit trail". Q4 2025: "Interact with Ramp entirely through Slack or SMS, no log-in required" to create, submit, approve expenses or ask questions. | https://support.ramp.com/hc/en-us/articles/360052081394-Set-up-Ramp-s-Slack-integration-for-Admins ; https://ramp.com/new-on-ramp-q4-2025 | FETCHED |
| Employee task capture | SMS asks for a receipt after an in-person purchase; employee replies with a photo (text becomes the memo); receipts auto-match to transactions. Push notification deep-links straight into the transaction to complete missing items; "I don't have a receipt" is an explicit action rather than a blocked state. | https://support.ramp.com/hc/en-us/articles/360042588454-Submitting-receipts-memos-and-accounting-for-your-Ramp-transactions ; https://support.ramp.com/hc/en-us/articles/1500011601642-What-to-do-if-you-re-missing-a-receipt | EXCERPT |
| Delegation | Delegate approvers for out-of-office: delegate receives requests, reviews, reimbursements, bills and the weekly "Missing Items" email; applies to existing and future approval chains. | https://support.ramp.com/hc/en-us/articles/16777041497363-Delegate-approvers | EXCERPT |
| Reminders | Managers get a weekly reminder email listing transactions awaiting their approval; reviewers can nudge employees about missing items. | https://support.ramp.com/hc/en-us/articles/4417421399699-Reviewing-transactions-from-Ramp-cards | EXCERPT (weekly email) / FETCHED (reminders) |
| Pre-filled bulk forms | 2025: bulk reimbursements "automatically groups related expenses and pre-fills required fields"; recurring memo templates set once. | https://ramp.com/blog/2025-release-notes | FETCHED |
| Audit | SOX-oriented audit log filterable by user or action, CSV export. | https://ramp.com/blog/2025-release-notes | FETCHED |
| Analytics | "AI Reporting": ask spend questions in plain language and receive charts and insights. | https://ramp.com/blog/2025-release-notes | FETCHED |
| Design system | Internal design system "Ryu" (React/TypeScript); design process described as AI-first and "builder led" (job listings). Ramp does not appear to publish public design-system docs (NOT VERIFIED). | https://www.builtinnyc.com/job/design-engineer/10101452 | EXCERPT |

---

## 6. Rippling (HR/IT/Finance suite — reference for single employee record, policy-driven approvals, effective dating)

Note: rippling.com (blog, recipes, product pages) returned HTTP 403 to automated fetches in this session, and no public
help-centre article was indexed. **All Rippling rows are EXCERPT-level** and should be re-verified by a human before
being cited in design decisions.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Single record / IA | "Employee Graph" — one employee record that every app (HR, payroll, IT, spend, third-party apps such as Greenhouse, Jira, Salesforce) reads from, used both for automation triggers and for reporting across systems. | https://www.rippling.com/blog/unify-and-level-up-your-workforce-analytics-with-ripplings-new-custom-reports | EXCERPT |
| Dynamic groups | **Supergroups**: membership defined by logic (incl. RQL expressions) over employee attributes; groups drive policies, permissions, app licences, email lists, Slack channels, and update automatically as attributes change. | https://www.rippling.com/blog/unify-and-level-up-your-workforce-analytics-with-ripplings-new-custom-reports ; https://www.rippling.com/products/it/platform/permissions | EXCERPT |
| Permissions model | Permission profiles = **scope** (whose data: a reporting line, a department) × **access** (which data/apps, e.g. Payroll) × **actions** (view, edit, approve). Assigned automatically via Supergroups. Positioned as "access to data, not views" — managers can report/automate on anything in scope. | https://www.rippling.com/products/it/platform/permissions | EXCERPT |
| Approvals | Separate approval policies per action type (hiring, termination, transitions); routing by attributes (tenure, department) so chains survive org changes; multi-approver logic; re-routing when the primary approver is on vacation; escalate only large changes (example: comp increases >10% or >$5,000 need higher approval; a recipe auto-assigns a review task to the department's Director above a threshold). Managed in an Approvals app; some logic in Workflow Studio. Email/Slack notification templates. | https://www.rippling.com/products/it/platform/permissions ; https://rippling.com/recipes/when-comp-increase-is-over-threshold-assign-review-task | EXCERPT |
| Effective dating | May 2025: admins can **edit effective dates of historical changes** on an employee profile and are "notified for potential downstream impacts on payroll, benefits, and more". An Employee Data Change Report lists every change with who initiated it, when it was initiated, and when it took effect (initiated-at vs effective-at shown separately). | https://www.rippling.com/blog/may-2025-product-updates ; https://rippling.com/recipes/employee-change-tracking-report-template | EXCERPT |
| Configurable profile | "Configurable profiles" (2025): admins rename/reorder/add **tabs, sections, fields**, embed **reports** inside the employee profile, pin a field (e.g. "languages") to the top of every profile or only for certain teams; profile layout "dynamically changes based on who the viewer is". | https://rippling.com/blog/configurable-profiles-launch | EXCERPT |
| Automation | Workflow Studio: no-code triggers on any employee attribute (start date, salary change, device health, course completion), third-party app data, or formula fields; actions send emails/Slack/meeting invites or update systems. | https://www.rippling.com/en-GB/workflow | EXCERPT |
| AI (act + stage + show work) | Rippling AI resolves "my team"/"last quarter" against real data; converts questions into SQL/formulas/reports so answers are "auditable and deterministic, showing its work"; commands such as "Reassign all of David's direct reports to Jamie effective Monday" are **staged as one change for review and confirmation** rather than executed silently. Employees ask about benefits, payroll, leave eligibility, reimbursement status. | https://www.rippling.com/blog/introducing-rippling-ai | EXCERPT |
| Analytics | Custom Reports join HR/IT data with third-party apps (Carta, Greenhouse, Zendesk, Jira…). Headcount planning shows plan vs actual, open roles and cost in real time with permissions "enforced automatically". | https://www.rippling.com/blog/unify-and-level-up-your-workforce-analytics-with-ripplings-new-custom-reports ; https://www.rippling.com/en-AU/products/hr/headcount-planning | EXCERPT |
| Mobile | Mobile app supports time-off requests and manager approval (balances visible before approving), timecard-approval push alerts, mobile time clock. | https://rippling.com/mobile-time-clock | EXCERPT |
| Dark mode | October 2025 product updates reportedly added a Dark Mode toggle in account settings. | https://www.rippling.com/blog/october-2025-product-updates | EXCERPT |
| User-reported friction | Capterra reviews (2025–26) mention navigation that "can feel unintuitive … because so many HR, payroll, and IT features are bundled together", hard-to-find settings/documents "in a dynamic hidden menu", and overwhelming initial setup — the cost of a very broad suite. | https://www.capterra.com/p/172127/Rippling/reviews/ | EXCERPT |

---

## 7. HiBob ("Bob" — reference for social/culture layer, historical tables, request-change flows)

Note: help.hibob.com articles were not indexed; the HiBob community ("Heartcore") and API docs were reachable.
HiBob marketing pages yielded few concrete mechanisms.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Home as social feed | Homepage behaves like a company social feed: birthdays, work anniversaries, new joiners, company/people stats, quick links, and an availability strip ("who's in and who's out, who's sick, on vacation, working remotely, from the office, or traveling"). Shoutouts/Kudos with emoji reactions and comments; Clubs, Superpowers, Hobbies on profiles. Daily digest of who's out/birthdays/joiners pushed to Microsoft Teams. | https://www.hibob.com/product-briefs/mobile-app/ ; https://appsource.microsoft.com/en-us/product/saas/wa200000765 | FETCHED (mobile brief) / EXCERPT (feed details, Teams digest) |
| Directory & org chart | People Directory searchable with photo, title, manager; view by site, department or team. Org chart shows relationships between people, teams, departments; custom org charts "using multiple layers of data". | https://www.hibob.com/features/core-hr/ | EXCERPT |
| Effective-dated history | Profile data in **historical tables** (Work, Employment, Payroll, Lifecycle), each row an event with an effective date; "Only a single row can be effective at any given time"; future-dated rows supported (termination effective Apr 17 takes effect at midnight Apr 18 in the *site's* timezone). Viewing history is a separate permission ("View history") per category. | https://apidocs.hibob.com/docs/additional-employee-data.md | FETCHED |
| Change requests on profile | Employee/manager opens a profile → **Actions > Update profile** → picks a flow → fills details → Next; approver sees a **summary of proposed changes** in "Org > My pending approvals". Requester sees an "approvals pending" banner on the profile and can open "View approval flow" to see where it is. Completion of an approval can trigger an associated task list (downstream automation). | https://heartcorehr.hibob.com/tasks-flows-170/making-bob-your-single-source-of-truth-people-data-update-flows-4315 | FETCHED |
| Approval limits (honest constraints) | Time-off flows support max **two approvers**; teams can't be approvers; approver changes "only affect future requests" (pending keep old approvers); second-level managers can act on requests "pending approval of others" within their line but not outside it; custom approval flows override per-employee approvers. | https://heartcorehr.hibob.com/time-attendance-174/time-off-request-approval-limitations-7891 | FETCHED |
| Mobile | Mobile home: company news, praise, birthdays; clock in/out "from the home screen or the Attendance tab"; time-off requests with status, remaining balance and cancel; status check-ins (working/break); directory with one-tap call/email/message; goals & tasks. | https://www.hibob.com/product-briefs/mobile-app/ | FETCHED |
| Conversational AI | "Bob Companion": a single chat window that routes to specialised agents (documents, performance, etc.); answers are **profile-aware** (demo: office-dog policy shown for the employee's Tel Aviv site); employees can ask about leave and request time off in the same thread. | https://openai.com/index/hibob/ ; https://totaltech.brandonhall.com/wp-content/uploads/2025/04/SPP_HiBob_mc_022026-2.pdf | EXCERPT |
| Workforce planning | Interactive org charts with **positions** (incl. open roles), multiple headcount plans/scenarios per event (new site, reorg, acquisition), custom approval flows with governance, finance participation via budgets, AI planning assistant. | https://www.hibob.com/platform/planning/ | EXCERPT |
| Analytics | "Core dashboards" for headcount, growth, retention, absenteeism; DEI/headcount/trend use cases. Concrete drill-down/filter mechanics NOT VERIFIED (marketing page only). | https://www.hibob.com/hi/analytics/ | FETCHED (no mechanism detail) |

---

## 8. Workday (HCM incumbent) + Canvas Design System

Note: canvas.workday.com returns 403 to the WebFetch tool but serves normally to a standard browser user agent; Canvas
pages below were retrieved with `curl` and read as text (counted as FETCHED). Workday customer-facing behaviour is
mostly documented by customer universities' training material (Workday's own admin guide is login-gated except a few
pages).

### 8a. Canvas Design System (canvas.workday.com, v15 at time of research)

| Topic | OBSERVED (rule / mechanism) | Source URL | Evidence |
|---|---|---|---|
| System scope | Canvas = "Workday's single source of truth for everything product development". Tokens: Color, Depth, Motion, Opacity, Shape, Size, Space, Type. Components include Action Bar, Side Panel, Table, Tabs, Banner, Loading Dots, Skeleton, Status Indicator, Toast, Dialog, Modal. Large content guide (UI text for buttons, dialogs, empty values, errors, grids, wizard instructions, related actions menu) plus Globalization (RTL/Bidi). | https://canvas.workday.com/get-started/introduction | FETCHED |
| Side panel | Anchors left/right, full viewport height; can **push/resize** page content or **float** over it. Three uses: local page navigation (collapsible, not closable), editing/displaying supporting info (may be temporary, disappears when the related content loses focus), and overlay panels (block the page; have Close not Collapse). Auto-collapse at small breakpoints when it need not stay open; keep open + resize page when it is used for editing. Caution against multiple open side panels shrinking content. Expand/collapse icon button must have a tooltip ("Collapse"/"Expand"). RTL flip built in. | https://canvas.workday.com/components/containers/side-panel | FETCHED |
| Action bar (task footer) | Sticky bar at the **bottom** of the screen for task progress actions (navigate pages, save progress, submit, cancel); placement persists "until the task is successfully submitted"; max 3 actions + overflow menu (1–7 items); one primary button per screen, primary left-aligned in LTR; tertiary buttons not allowed in the bar. Actions unrelated to task progress should live elsewhere. | https://canvas.workday.com/components/buttons/action-bar | FETCHED |
| Feedback hierarchy | Toast = low emphasis (process status, e.g. "successfully submitted"; optional short action link; dismissible); Banner = medium (errors/alerts; persists until resolved); Dialog = high (requires decision). Toast `mode` maps to ARIA: `status` (polite live region), `alert`, `dialog`. | https://canvas.workday.com/components/popups/toast | FETCHED |
| Errors vs alerts | Errors are "hard-stops", alerts/warnings are "soft-stops" that let the user continue. Copy rules: tell the user how to fix it, active voice but avoid blaming, second person, no "please"/"sorry", don't prefix with "Error"/"Alert" (added automatically), don't repeat the field label — Workday auto-inserts a **hyperlinked field-label subheading** above each error message; put variables at the end after a colon for translation. | https://canvas.workday.com/guidelines/content/ui-text/error-and-alert-messages | FETCHED |
| Empty values (data display) | A vocabulary for "no value": `none` (empty list/object, e.g. no manager), `(empty)` (empty string), `0` (known numeric count), `N/A` (attribute not valid), `—` (pending; distinct from loading dots), blank (table cells only), hide the attribute entirely if it can never apply; never show `NULL` as a field value. | https://canvas.workday.com/guidelines/content/ui-text/empty-values | FETCHED |
| Status indicators | Non-interactive, read-only; max-width 200 px; preferably one word; never wrap; avoid tooltips/truncation; combine colour with text; low-emphasis variant in dense tables, high-emphasis sparingly next to headers; if a status must be clickable use a link/tertiary button instead. | https://canvas.workday.com/components/indicators/status-indicator | FETCHED |
| Loading | Skeleton when the layout is known and most of the page loads at once (with motion to signal activity); Loading Dots when layout is unknown or to signal processing/change. | https://canvas.workday.com/components/indicators/skeleton | FETCHED |
| Motion | Principles: Fast, Simple, Purposeful; subtle; performance-aware; defer to platform (HIG/Material) motion on native mobile. Two easing families: **Quick** (`cubic-bezier(0.2,0,0.2,1)` for small, short interactions such as button presses/checkboxes) and **Purposeful** (larger movements); motion tokens shipped in `@workday/canvas-tokens-web` v4. Page also has a "Motion & Accessibility" section. | https://canvas.workday.com/styles/tokens/motion | FETCHED |
| Accessible forms | Instructions at top (incl. how required is shown); required indicated without relying on colour and programmatically; format requirements in the label, placeholder only for examples; supporting info in context not in a separate document; **don't disable submit buttons** (users can't tell what's missing); prefer read-only/static text over disabled controls (better contrast) and explain why a field is unavailable; hide controls users lack permission for ("need to know"). | https://canvas.workday.com/guidelines/accessibility/accessible-forms | FETCHED |
| Grid copy | Singular column headings, unique labels, abbreviations allowed in grids only; Workday states it "intends to update our grid tooling … to improve performance, meet accessibility standards, and improve usability" (Tables 2.0) — an admission the legacy grid falls short. | https://canvas.workday.com/guidelines/content/ui-text/grids | FETCHED |
| Colour & contrast framework | 15-step tonal scale (0 = white … 1000 = black). Contrast is guaranteed by **step difference**: ≥500 steps = 4.5:1 (AA text), ≥700 = 7:1 (AAA text), ≥400 (both >200) = 3:1 non-text. Designers can pick accessible pairs "just from the step number" without a calculator; use colour roles (tokens); never colour alone. | https://canvas.workday.com/guidelines/color/color-contrast | FETCHED |
| Theming | Canvas Kit v14/v15 moved from JS theme objects to **CSS variables**: import tokens once at root, override at `:root` for global theming, scoped provider only for multi-brand/embedded sections. No explicit dark-mode guidance was found on the pages read (NOT VERIFIED). | https://canvas.workday.com/get-started/for-developers/theming/overview | FETCHED |
| Menus & wizard copy | Related Actions menus: level 1 categories (nouns/verbs), level 2 specific verbs in title case ("Benefits > Change Benefits", not "Benefits > Change"). Wizard buttons say what happens next ("Save and Continue", not "Next"). | https://canvas.workday.com/guidelines/content/ui-text/related-actions-menu ; https://canvas.workday.com/guidelines/content/ui-text/wizard-instructions | FETCHED |

### 8b. Workday product behaviour

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| 2024 "new Workday" | Sept 2024 announcement: Workday Assistant (natural-language Q&A personalised by "role, location, and needs", e.g. explaining payslip changes), bringing third-party apps into Workday, deeper Teams/Slack via Workday Everywhere. The press release contains little concrete UI detail. | https://newsroom.workday.com/2024-09-17-Welcome-to-the-New-Workday-A-Reimagined-User-Experience-Powered-by-AI | FETCHED |
| Home & menu (2025R2) | Home gains **Quick Actions** (replacing "Your Top Apps"); the Menu becomes a **categorised left sidebar** with hover-to-reveal sub-menus, customisable order, saved favourites. New **Request Absence** quick action opens a "micro calendar pop-up" with Calendar vs Date Range toggle and a View Balances link that returns to the request. Profile page gets a persistent left navigation. | https://staff.flinders.edu.au/content/dam/staff/documents/workday-user-guides/workday-updates/user-experience-updates-2025-r2.pdf | FETCHED (PDF text extracted) |
| Home & search (2026R1) | Redesigned home with search "repositioned as the primary entry point", "updated visual elements that reduce clicks on high-volume workflows", search-experience preference moved to Account Preferences, overflow scrolling for pinned categories; mobile: visual refresh of home/search/hubs/cards and a simplified profile. | https://www.union.edu/wd4u/workday-releases/2026-spring-release-2026R1 | FETCHED |
| Inbox / business processes | Inbox is "your personal activity stream … You must act on every item". Three task types: **Actionable** (enter info + Submit), **Approvals** (approve/deny), **To Dos** (written instruction, may redirect to another task — user must return and click Submit to clear it). Left filter pane with custom filters; Archive tab shows only processes from the **last 30 days**; each item has Details and **Process** tabs (history + future steps). Stuck tasks: gear icon → Cancel/Skip/Delete Incomplete, or View Details → Related Actions ("brick") icon on the parent process → Cancel. Reassign requires a proposed person + reason and stays in your inbox until HR approves. | https://workdaytraining.geisinger.org/PDFContent/J165_WorkdayInbox.pdf | FETCHED (PDF text extracted) |
| Validations | Critical validations = hard red error blocking progress; warning validations = soft orange alert allowing continue; "View All" button to read messages. | https://workdaytraining.geisinger.org/PDFContent/J165_WorkdayInbox.pdf | FETCHED |
| Effective dating | Effective-dated changes are started from an object's **Related Actions** menu (e.g. "Edit Effective-Dated Custom Object" → enter effective date, applied to all changes in that transaction). Change Job starts from "Start Job Change"/"Request Transfer" with a list of reasons. | https://doc.workday.com/admin-guide/en-us/manage-workday/tenant-configuration/custom-objects-and-labels/dan1370796495420.html ; https://workday.utexas.edu/training/change-job | EXCERPT |
| Work in chat | Workday Everywhere / Assistant in Teams & Slack: **Guides** (links into tasks/reports), **actionable notifications** (e.g. time-off request with an Approve button), **Quick Actions** (e.g. Give Anytime Feedback pop-up) across 18 capability areas; tasks that can't run in chat show a card with a link back to Workday. Workday Assistant to be retired in 2027R2 in favour of a "Self-Service Agent for Workday Everywhere". | https://doc.workday.com/admin-guide/en-us/human-capital-management/hcm-innovation-services/workday-assistant/reference--workday-assistant-and-workday-everywher.html | FETCHED |

---

## 9. Personio (European SMB/mid-market HRIS — reference for propose/approve permissions, effective-dated attributes, inbox)

Note: support.personio.de (Cloudflare challenge) and personio.com (Vercel checkpoint / HTTP 429) blocked automated
fetches; most rows are EXCERPT from search-engine summaries of the official support articles. The community post was
reachable.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Effective-dated attribute edits | Every attribute change asks *when*: **Today** (immediate), past date (retroactive — recorded in profile History; applied only if no later-dated entry exists), future date (scheduled — applied at 03:00 on that date), and **"Set as temporary change"** with a revert date after which the old value returns. Users with Propose/Edit access see the pending scheduled value and its date; View-only users see only the current value until it takes effect. Profile has a **History** tab listing changes with effective dates. | https://support.personio.de/hc/en-us/articles/213331029 | EXCERPT |
| Three-level field permissions | Per profile **section**: **View** (see), **Propose** (submit changes — immediate or scheduled — that go to an approval process), **Edit** (change without approval). Admins attach approval processes to employee-data changes, so "self-service edit" becomes "self-service proposal" for sensitive fields. | https://support.personio.de/hc/en-us/articles/360000038205-Permissions ; https://support.personio.de/hc/en-us/articles/21501960338461-Implement-access-and-approvals | EXCERPT |
| Employment changes | Promotions/role changes update position, supervisor, department, salary on the existing profile (no new profile, hire date unchanged). Personio ran a closed prototype test for a dedicated "administration of employment changes" flow (community). | https://support.personio.de/hc/en-us/articles/34554225234845-Manage-employment-changes ; https://community.personio.de/product-testing-research-interviews-en-261/closed-test-our-prototype-for-administration-of-employment-changes-in-personio-14798 | EXCERPT |
| Unified inbox | Redesigned Inbox (announced Sept 2024, shipped ~Nov/Dec 2024): tasks, approvals and notifications in "a prioritized list"; a **homepage widget** to action work quickly plus a **full-screen** view with all decision details; sorting, filtering and **bulk actions** ("approval of multiple requests with a single click"). Approval types: attendance, time off, employee-data changes, flexible work schedules. Same inbox on mobile. | https://personio.com/blog/redesigned-inbox ; https://support.personio.de/hc/en-us/articles/21983843629981 | EXCERPT |
| Home (2025) | Two-column home: left = announcements, tasks, celebrations; right = user-customisable widgets (profiles, time off, people). 2024 redesign refocused home "on users and their immediate teams" to show which HR processes need attention. | https://community.personio.de/product-spotlight-en-260/short-explainer-videos-for-employees-discover-the-updated-features-and-new-design-of-personio-14805 ; https://www.personio.com/about-personio/press/personio-redesign/ | FETCHED (community) / EXCERPT (press) |
| Profile "About" tab | Revamped About tab: workplace connections and organisational context, contact details, direct links to Slack profiles, the colleague's **local time zone**, and Google Maps directions to their office — the profile as a "how do I work with this person" card. | https://community.personio.de/product-spotlight-en-260/short-explainer-videos-for-employees-discover-the-updated-features-and-new-design-of-personio-14805 | FETCHED |
| Settings | Settings menu replaced by a **Settings homepage** giving an overview of product areas from one central point. | https://community.personio.com/product-updates/new-in-personio-the-new-settings-homepage-1112 | EXCERPT |
| Analytics via assistant | Personio Assistant (beta Apr 2025): plain-English questions about people, time, compensation, recruiting; returns **interactive, shareable charts or tables** that can be downloaded; answers respect attribute-level permissions. | https://www.personio.com/blog/personio-assistant/ | EXCERPT |
| Employee help desk → AI | "Personio Conversations" (ticketing) is being retired (available until 30 June 2026) in favour of Personio Assistant for employee questions. | https://www.usagepricing.com/blueprint/activity/personio-2026-06-30-conversations-retired | EXCERPT |
| Workflow automation | Pre-built templates, code-free workflow builder and a **monitoring dashboard** to track how running workflows are progressing (Sept 2024). | https://dutchnews.nl/businesswire/whats-new-with-personio-improved-people-insights-performance-reviews-and-custom-workflows-drive-impact-for-hr-teams | FETCHED |
| Brand theming | April 2025: customers can make Personio "look and feel like their own" (brand/culture customisation). | https://www.personio.com/about-personio/press/new-personio/ | EXCERPT |
| User-reported friction | Capterra 2026: "not very self explanatory, and the interface keeps on changing"; navigation "tricky and often unintuitive"; reporting range limited/unreliable for some. Change fatigue is a real cost of continuous redesign. | https://capterra.com/p/158622/Personio/reviews/ | EXCERPT |

---

## 10. Deel (global payroll/EOR + HR — reference for approval-in-place, proactive compliance alerts, mobile parity)

Note: help.letsdeel.com articles were not indexed for the queries tried; Deel's own blog was reachable. Several Deel
statements are product-marketing claims — mechanisms are recorded, outcome claims are not.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Approvals hub | Approve expenses, bonuses, allowances, overtime, submitted work "in one place" from the dashboard, including bulk approval. Approval policy is set per **team** in Team Settings (all payments vs fixed-rate recurring/allowances, plus optional expenses/bonuses/overtime/work submitted) and auto-applies to every contract in the team. Multiple managers per team (each item needs all designated approvers); approval permission can be granted to any manager regardless of role. | https://www.deel.com/blog/streamlined-approvals/ | FETCHED |
| Approval in chat | Deel Plugin for Slack/Teams: "Managers approve time off, expenses, and contract changes right where the request lands, no separate portal required"; weekly digest with embedded action buttons; approvals also on mobile. | https://www.deel.com/blog/deel-plugin-hr/ | FETCHED |
| Personalised answers in chat | Deel AI inside Slack's AI panel streams answers with **suggested prompts** "so employees don't need to know what to ask", grounded in "each person's actual leave balance, payslip, benefits, and contract, not a generic help article". | https://www.deel.com/blog/deel-plugin-hr/ | FETCHED |
| AI agents | Aug 2025 "AI Workforce": hub to launch/manage/create agents; seven pre-built agents (e.g. Time Off agent analyses a request and flags coverage gaps before approval; Payroll agent flags anomalies against rules/thresholds before payout; location-compliance agent compares IP geolocation to tax rules). Reported controls: role-based access, approval chains, audit trails, SSO; usage metrics (hours saved, tasks completed) shown in-platform. | https://www.deel.com/blog/deel-launches-ai-workforce/ ; https://cfotech.co.uk/story/deel-launches-ai-workforce-to-streamline-global-hr-payroll | FETCHED (blog) / EXCERPT (controls) |
| Proactive compliance | Compliance Hub monitors and flags risks in-platform "with context, supporting data, and guidance": expiring visas, misclassification risk (AI assessment localised in 15 countries), minimum wage/benefit non-compliance; monthly Workforce Insights report in-app + email; Compliance Monitor explains regulatory changes across ~150 countries. | https://www.deel.com/blog/compliance-hub | EXCERPT |
| Navigation / workspace | April 2025: new navigation organising a growing product suite; switch between products (Engage, Payroll, resource hub); personalise the workspace with **widgets** (Deel AI, recent payments, Compliance Hub). | https://www.deel.com/blog/new-deel-mobile-app/ | FETCHED |
| Mobile parity | Mobile app claims "access to 100% of Deel features"; manager alerts for time-sensitive contract approvals and onboarding; worker payslips, withdrawals, tax docs; smart notifications (paycheck deposits, contract signings); SSO/Face ID/fingerprint; **offline mode** for basic functions. | https://www.deel.com/blog/new-deel-mobile-app/ | FETCHED |
| Org chart | Org chart (Deel Engage plugin) auto-updates from HRIS/IT, browse reports and departments, highlights new hires, departures, anniversaries with notifications and recaps. | https://deel.com/plugins/org-charts | EXCERPT |

---

## 11. Lattice (performance/engagement + HRIS — reference for manager home, AI with sources, read-only impersonation)

Note: Lattice moved its help centre to `help.lattice.com/en-us/articles/<id>`; older `/hc/en-us/` URLs 404. Article bodies
were retrieved with `curl` (WebFetch only returned the navigation) — counted as FETCHED.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Home page | "Snapshot of where employees are today": **Quick actions** at the top (give/request feedback, create a goal); **Coming up** tile (next 1:1s/events → opens the agenda to prep); **Tasks** (write reviews, respond to survey, update goal) with "All tasks"; **Celebrations** (anniversaries/birthdays, hover for detail); **Team** (managers drill into indirect reports via "(x) reports"); team Updates with optional AI summary of "sentiment and key themes"; Goals across cycles with inline Update on row hover. | https://help.lattice.com/en-us/articles/15178925-the-home-page | FETCHED |
| Tasks semantics | Tasks page has Active and Closed tabs. Review- and compensation-related tasks **cannot be dismissed** until done or the cycle closes; all others can be skipped on hover; closed tasks show their expiry date. | https://help.lattice.com/en-us/articles/15179277-the-tasks-page | FETCHED |
| Search + AI in one bar | Home has a single "Search or ask a question" bar with shortcut chips (1:1s, Feedback, Updates, Time Off, Goals, Grow, Profile, Org Chart) and an expandable AI Agent panel on the right. Spring/Summer 2026 moved the AI agent into a **right-hand sidebar** "without disrupting navigation". | https://help.lattice.com/en-us/articles/15178463-use-the-lattice-ai-agent ; https://lattice.com/blog/lattice-spring-summer-2026-product-release | FETCHED |
| AI answers with sources + actions | Every AI answer has a **Source** link to the document/tool it came from (arrow jumps to the page); thumbs up/down goes to admins; answers are permission-scoped (managers can ask about their team, ICs cannot; even admins are limited to their reporting line in the agent). The agent can **act**: add a 1:1 talking point, draft an Update, submit/request feedback. Admins can see questions asked about uploaded documents. | https://help.lattice.com/en-us/articles/15178463-use-the-lattice-ai-agent | FETCHED |
| Knowledge permissions | Knowledge Vault documents get access rules (all employees + future hires / specific people or groups / admins only) with a **"Preview employees"** tab showing exactly who will gain access before saving; bulk "Manage Access" via checkboxes; up to 15 min to propagate. | https://help.lattice.com/en-us/articles/15179026-lattice-ai-agent-document-permissions | FETCHED |
| Evidence-based review drafts | 2026: AI drafts reviews from 1:1s, past reviews, feedback and growth areas with "See sources"; manager keeps control of ratings and submission (no auto-submit). Comp: inline editing in table view; reopen a submission back through the approval chain "without restarting entire workflow". | https://lattice.com/blog/lattice-spring-summer-2026-product-release | FETCHED |
| Navigation (2026) | "My Team" views moved *inside* each product tab (1:1s, Updates, Feedback, Grow) instead of a separate dashboard; Manager Reviews reorganised around actionable items (peer nominations, completion tracking). | https://lattice.com/blog/lattice-spring-summer-2026-product-release | FETCHED |
| Read-only impersonation | "Log in as the user" from Admin > Directory for support/troubleshooting. **Read-only**: no changes, no actions, no downloads; private surveys/pulse, private 1:1 notes and AI 1:1 transcripts are blocked; drafts can be typed but never submitted; sessions max 1 hour; a **blue banner** shows whose view you're in and that access is read-only; every impersonation is logged on an Impersonations page. | https://help.lattice.com/en-us/articles/15179065-admin-impersonation | FETCHED |
| Analytics — saved views | Charts: pick a data point, set filters/time period/breakdowns, **Save view** (named), manage in "My views", share a saved view, schedule a report; pre-built view templates in Explorer. Managers see Reporting > Charts; admins see Admin > Analytics. | https://help.lattice.com/en-us/articles/15178921-saved-views-in-charts | FETCHED |
| Analytics — heatmaps | Engagement/people analytics as filterable heatmaps, line/bar/scatter; filters by tenure, gender, department, manager vs IC, salary band, performance rating. | https://lattice.com/blog/lattice-analytics-understand-your-company-culture-like-never-before | EXCERPT |
| Effective-dated HRIS | Bulk field updates ask for an **effective date**; a change "may be skipped if the employee already has conflicting changes scheduled". Profile history available to managers, employees and admins for Job Title, Department, Manager, Work Location, Job Level/Function/Type and comp fields; audit export via Profile audit logs report with effective-date range. | https://help.lattice.com/hc/en-us/articles/31479718046871-Bulk-Update-Employee-Fields ; https://help.lattice.com/hc/en-us/articles/26404691466135-View-an-Employee-s-History-in-Their-Profile | EXCERPT (pages now 404) |
| Accessibility posture | States it is "partially compliant with WCAG 2.2" and aims for full keyboard operability and AT compatibility — an honest, specific claim. | https://help.lattice.com/en-us/articles/15178424-accessibility-in-lattice | FETCHED |

---

## 12. Culture Amp (engagement/performance — reference for analytics storytelling, explorer panels, confidentiality-by-design)

Support article bodies retrieved with `curl` (support.cultureamp.com is Intercom-hosted) — counted as FETCHED.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| Drill-in side panel on charts | **Heatmap Explorer**: click any heatmap cell → a panel opens on the right with insights for exactly that cell (participation, favourable/neutral/unfavourable breakdown and trend, demographics with the biggest spread, recommended focus areas, AI comment summaries for questions, AI comment comparisons for the index factor) "without leaving your current view". It "surfaces data that already exists in other report views, it doesn't add or hide anything." A matrix documents which insight appears for which cell type and access level. | https://support.cultureamp.com/en/articles/11544511-heatmap-explorer | FETCHED |
| Small-n fallback | If a selected demographic group has **<25 responses**, the panel shows company-wide recommended focus areas instead (statistical honesty rather than noisy per-group advice). | https://support.cultureamp.com/en/articles/11544511-heatmap-explorer | FETCHED |
| Confidentiality as design | Reporting group minimum (e.g. 5) hides any group below it and prevents filtering down to it; separate comments group minimum (counts respondents, not comments); **indirect identification protection** hides an additional group so a hidden score can't be derived by subtraction. Settings are locked once the survey launches and are shown to respondents on the welcome page. "These rules are a deliberate design choice, not a technical limitation." | https://support.cultureamp.com/en/articles/7048386-confidentiality-protections-in-reporting | FETCHED |
| From insight to action | Action Framework: report owners (typically department managers) are **prompted to take action** once a report is shared; select a focus by clicking a focus flag beside a question (Focus Agent suggests highest-impact areas); browse an "Inspiration Engine" of actions other companies took; track in an Action plans page with filters; collect team feedback on whether actions worked. Guidance: let the report owner create actions (if an admin does it they become owner and the manager misses notifications). | https://support.cultureamp.com/en/articles/7048673-take-action-with-action-framework ; https://support.cultureamp.com/en/articles/10212683-platform-actions-plans-page | FETCHED / EXCERPT (plans page) |
| AI comment comparisons — controls | AI comparisons of comments from highest vs lowest scoring groups; admins can toggle comparisons on/off for **shared** reports independently of AI summaries; thumbs up/down feedback; one-click copy for presentations. | https://updates.cultureamp.com/132953-enhanced-control-and-feedback-options-for-ai-comment-comparisons-in-heatmap-reports ; https://updates.cultureamp.com/132955-ai-comment-comparisons-in-the-heatmap-now-generally-available | FETCHED (controls) / EXCERPT (GA) |
| Comparisons & benchmarks | Heatmap survey comparisons against past surveys, company data and benchmarks; global engagement benchmarks refreshed for calendar 2024 data. | https://updates.cultureamp.com/132926-heatmap-survey-comparisons-now-available-in-engage | EXCERPT |
| AI coach in context | AI Coach embedded across Engage and Perform (GA in surveys 21 Oct 2025) to help managers interpret data and act; Engage reporting buttons re-laid-out to make Coach discoverable. | https://www.hrdive.com/press-release/20251028-culture-amp-expands-ai-coach-amplifying-manager-impact-and-ushering-in-a-n/ | EXCERPT |
| Data in external assistants | Aug 24, 2026: Engage and Perform data available inside ChatGPT, Claude and other assistants via **MCP** ("Pull my team's survey results into Claude and summarize the latest engagement trends"). Permission specifics not detailed in the announcement. | https://www.cultureamp.com/company/announcements/culture-intelligence-in-daily-tools | FETCHED |
| Change communication | Product changes announced in an in-app Messenger "News" section and a public Product Updates newsfeed; support tells users the newsfeed is "your first point of reference" if something changes. | https://support.cultureamp.com/en/articles/11754345-ai-coach-product-updates-2025 | FETCHED |
| Design system | **Kaizen** (cultureamp.design; React, Storybook-hosted, built on React Aria Components where practical, in development since 2017). Storybook content not readable via fetch. | https://cultureamp.design ; https://jobs.blackbird.vc/companies/culture-amp/jobs/45019481-senior-product-designer-design-system | FETCHED (site is Storybook shell) / EXCERPT (details) |

---

## 13. BambooHR (SMB HRIS — reference for a friendly, simple incumbent and its limits)

Note: BambooHR's help centre (bamboohr.screenstepslive.com) requires login; help rows are EXCERPT from search
summaries. Product-update pages and the design "behind the scenes" ebook were reachable.

| Topic | OBSERVED (mechanism) | Source URL | Evidence |
|---|---|---|---|
| 2024/25 UI refresh | First major redesign in ~5 years: navigation moved from top bar to a **left icon-focused sidebar** ("People expect software to work in ways they've come to expect from using other software"; also leaves room for growth); rounded corners chosen via preference testing; more negative space ("divisive among data-oriented users"); one unified icon font; **Fields** (custom serif) for brand warmth. Explicitly "doesn't impact the way the platform operates" — tools stay where they were. | https://www.bamboohr.com/resources/ebooks/behind-the-scenes-ui-2024 ; https://bamboohr.com/product-updates/new-user-interface | FETCHED |
| Home = Insights dashboard | Redesigned home with a role-aware **Insights Dashboard** (admins, execs, managers, employees). Edit mode to add/delete/move widgets; widgets auto-filtered by Access Level permissions. 16 widgets across demographics, employment, hiring/onboarding, comp/benefits, time off. Click a widget to expand, apply filters, then jump to the underlying standard report. | https://www.bamboohr.com/product-updates/introducing-insights-dashboard-and-a-redesigned-home | FETCHED |
| Inbox | Inbox icon top-right of Home with a pending count; opens a breakdown by request type (e.g. Time Off Requests); select a request to approve/deny. Multi-level approval: only first approver is notified on submit; next level is emailed after the first approves. | https://bamboohr.screenstepslive.com/m/58956/l/747950-the-inbox ; https://bamboohr.screenstepslive.com/m/58956/l/788839-approve-time-off | EXCERPT |
| Change requests | From a profile, **Request a Change** → choose type (templates such as "Job Information", containing all fields in the Job Information table) → workflow → submit; requester gets email on approve/deny; sequential approvers. Custom approval templates can be created. | https://bamboohr.screenstepslive.com/m/58956/l/588024-submit-a-change-request-with-a-custom-approval ; https://handbook.sourcegraph.com/departments/people-talent/people-ops/process/compensation-role-changes | EXCERPT |
| Effective-dated tables | Job, Employment Status and Compensation are **history tables** (rows with effective dates). 2025–26: when a new history row is added, department/division/manager carry forward automatically to reduce re-entry. A Change History report lists job-information changes in a date range. | https://www.bamboohr.com/product-updates/unlock-job-tab-fields-and-updated-syncing-for-eor-employees ; https://bamboohr.screenstepslive.com/m/53902/l/1149082-change-history-report | FETCHED (product update) / EXCERPT (report) |
| Mobile | Mobile Home → Announcements, Inbox, People (directory), Files, My Info, Time Clock; who's out today/any future date; time-off balances incl. future-balance calculation while requesting; approve/deny with push notifications; directory filter by department/division/location with tap-to-call/email. | https://bamboohr.screenstepslive.com/m/53916/l/540972-home-in-the-mobile-app ; https://bamboohr.com/mobile | EXCERPT |
| Dark mode | iOS mobile app follows the system dark-mode setting; no web dark mode found (users request it). | https://www.bamboohr.com/product-updates/bamboohr-mobile-app-dark-mode | FETCHED (iOS) / EXCERPT (web request) |
| AI | "Ask BambooHR" (2024) answers employee policy/benefit questions; 2026 added chat history and broader workforce-data answers; "Bamboo AI" agent platform launched July 2026. | https://bamboohr.com/exp/year-in-review-2024 ; https://outsail.co/post/bamboohr-unveils-new-features-and-revamps-pricing-structure | FETCHED (2024) / EXCERPT (2026) |
| User-reported friction | 2026 reviews: custom reporting limited; "the menu on the left is not very intuitive"; limited approval-hierarchy flexibility. | https://www.capterra.com/p/110968/BambooHR/reviews/ | EXCERPT |


---
---

# Part 2 — Cross-product synthesis by topic

Reading guide: **Recurs** = seen in ≥3 products (a de-facto convention users will expect). **Distinctive** = one or
two products do something notably better. **Missing in HCM** = gap observed specifically among the HR products
researched (Rippling, HiBob, Workday, Personio, Deel, Lattice, Culture Amp, BambooHR). Product names point back to the
Part 1 tables, where every claim carries its URL and FETCHED/EXCERPT marker. Claims resting only on EXCERPT evidence
are flagged *(excerpt)*.

## S1. Navigation & information architecture

**Recurs**
- **Left sidebar as primary navigation is now universal**, including late adopters: BambooHR moved top-bar → left
  icon sidebar (2024/25), Workday moved its Menu to a categorised left sidebar (2025R2), Notion and Linear refined
  theirs. Apple HIG: sidebar for top-level areas, max **two levels** of hierarchy, deeper trees go to a split view.
- **Personalisable sidebar**: reorder, hide behind "More", pin/favourite (Linear, Notion 3.4, Stripe "Shortcuts",
  Workday favourites, Linear mobile toolbar). Apple: "let people customize the contents of a sidebar" but don't hide
  the sidebar by default.
- **Recents + pins** as an automatic second tier (Stripe Shortcuts auto-lists recently visited pages that can be
  pinned; Notion Home "Recents"/"Favorites"; Linear favourites → can become landing page).
- **Dimmed chrome so content leads**: Linear 2026 dimmed sidebar; Apple Liquid Glass puts navigation on a separate
  layer above content and says "don't use Liquid Glass in the content layer".
- **Home = "what needs me" + "what's happening"**: Lattice (quick actions, coming up, tasks, celebrations, team),
  Personio (two columns: announcements/tasks/celebrations + custom widgets), BambooHR (role-aware insights widgets),
  HiBob (social feed + who's out), Workday (Quick Actions + search as primary entry), Stripe (charts + exceptions such
  as unresolved disputes).

**Distinctive**
- **Linear's small noun set** (workspace › team › issue/project/initiative; views as lenses) — every screen maps to a
  few named objects, which keeps IA learnable.
- **Stripe's tiering**: core objects → auto Shortcuts → long tail behind "More"; settings split into Personal /
  Account / Product.
- **Notion 3.4** split an overcrowded sidebar into four tabs (pages, agent chats, meetings, notifications) and shipped
  it opt-in first.
- **Lattice 2026** moved "My Team" *into* each product tab rather than a separate manager dashboard — the manager
  lens is a filter on every area, not a separate place.

**Missing in HCM**
- Breadth-induced disorientation is the dominant complaint for suites (Rippling: "so many HR, payroll, and IT
  features are bundled together"; Workday: "way too many clicks", "constantly having to go to the home screen to
  navigate back"; Personio: "interface keeps on changing") *(reviews, excerpt)*. None of the HCM products researched
  publishes a Linear-style object model or a Stripe-style "recent + pinned" tier.
- Manager vs employee vs HR-admin "modes" are mostly separate areas (Lattice Admin vs Reporting; Workday Inbox vs
  profile) rather than one IA with role-scoped lenses.

## S2. Command systems & search

**Recurs**
- **Cmd/Ctrl+K** opens a palette or search in Linear (actions + navigation) and Notion (search; Cmd+P too); `?`
  reveals the shortcut sheet in Linear and Stripe.
- **Recent items before typing** (Notion search, Apple HIG "display a person's recent searches before they start
  typing").
- **Search scoped and filterable**: Notion (created by, teamspace, in-page, date), Stripe (field filters, `is:` type
  and state, ranges, negation), Apple ("clearly display the current scope of a search").
- **Search state in the URL** so a query/view can be bookmarked or shared (Stripe search, Linear filters).
- **Search bar doubles as AI entry point**: Lattice "Search or ask a question" with shortcut chips; Workday 2026R1
  makes search "the primary entry point" on Home; Workday Assistant / Stripe assistant / Personio Assistant.

**Distinctive**
- **Linear's selection-aware command menu** — with rows selected, Cmd+K lists bulk actions for that selection; right-
  click menus show the shortcut next to each action, turning menus into a shortcut tutor; peek auto-previews results
  while arrowing through the palette.
- **Stripe's typed omni-search**: paste any ID and land on the object; `email:` `amount:>` `is:refunded` `-exp:`;
  results grouped by object type, each expandable into a sortable table.
- **Notion's trigger grammar** (`/` insert, `@` mention person/page/date/reminder) — most capability without menus.

**Missing in HCM**
- No HCM product researched documents a **command palette that executes actions** (e.g. "Request time off",
  "Change manager for…"). HCM vendors instead jumped to chat assistants. Workday's "Related Actions" brick icon and
  multi-level menus (Canvas: "Benefits > Change Benefits") are the closest analogue — discoverable only via the object.
- No structured search syntax for people data (Stripe-style `dept:` `manager:` `status:on-leave`) was found in any HCM
  product.

## S3. Peek, drawers, side panels & workspace layout

**Recurs**
- **Open-in-context side panel** is everywhere: Notion side peek (default) / centre peek / full page per view; Linear
  `Space` peek (Quick Look-style); Stripe ContextView drawer beside the object; Culture Amp Heatmap Explorer panel on
  cell click; Lattice AI agent in a right sidebar; Stripe assistant opens in a drawer.
- **Escalation ladder**: preview (peek) → side panel (work alongside) → focus/blocking view (start-to-finish task) →
  full page. Stripe makes it explicit (default ContextView; FocusView only for forms that "shouldn't be easily
  interrupted"); Apple: sheets for simple tasks, full-screen/new window for complex; Canvas side panels push or float,
  overlay variant has Close not Collapse.
- **Next/previous without closing** the panel (Linear ↑/↓ in peek; Notion Ctrl+Shift+J/K).
- **Persistent selection highlight** in the list that drives the detail (Apple split views).

**Distinctive**
- **Notion's per-view open mode** — the same records can open as side peek in a triage table and full page in a
  library.
- **Canvas's three side-panel jobs** (local nav = collapsible not closable; contextual edit = may disappear when focus
  leaves; overlay = blocks page) with responsive auto-collapse and a caution against multiple open panels.
- **Culture Amp's "explorer adds nothing new"** principle — the panel only re-surfaces data available elsewhere, scoped
  to the clicked cell, so it cannot become a second source of truth.

**Missing in HCM**
- Approvals in HCM still mostly open a **full task page** (Workday Inbox "View Details" with Details/Process tabs;
  BambooHR inbox → request page) *(BambooHR excerpt)*. A Linear/Notion-style keyboard-navigable list + peek for
  approving a queue was not found in any HCM product's docs (Personio's redesigned inbox has a full-screen view with
  details *(excerpt)*, closest).
- Employee records rarely open as a peek from lists (directory → full profile is the norm).

## S4. Approvals & task inboxes

**Recurs**
- **One inbox for all request types** with a count badge (Ramp, Personio, BambooHR, Workday, HiBob "My pending
  approvals", Lattice Tasks).
- **Approve where the request lands**: Slack/Teams actionable notifications (Ramp, Deel plugin, Workday Everywhere
  with Approve button), mobile push (Rippling, BambooHR, Personio, Deel), email digests with buttons (Deel weekly).
- **Bulk approve** (Ramp groups, Personio "multiple requests with a single click", Deel dashboard, Linear bulk bar).
- **Delegation / out-of-office** (Ramp delegate approvers incl. existing chains; Rippling re-route when on vacation
  *(excerpt)*; Workday reassign — needs HR approval).
- **Process visibility for the requester**: HiBob "approvals pending" banner + "View approval flow"; Workday Process
  tab (history + future steps).

**Distinctive**
- **Ramp's readiness gating**: items appear in the approver's inbox only when complete ("all required fields are
  satisfied"); the employee is chased for missing items, not the approver.
- **Ramp's grouping** (trip → spend limit → person) so related items are approved together.
- **Ramp's non-binary rejection**: "Request changes" vs "Request repay", each opening a thread.
- **AI recommendation with cited rationale**: Ramp Policy Agent sorts into ready-to-approve / review / ready-to-reject,
  shows "rationale and cited policy text" and "the policy version used"; humans keep final authority; overrides
  logged. Lattice comp: reopen a submission back through the approval chain "without restarting entire workflow".
- **Threshold-based escalation**: Rippling (comp increase >10% or >$5,000 → higher approver) *(excerpt)*.
- **Lattice tasks semantics**: review/comp tasks can't be dismissed; others can be skipped; closed tasks show expiry.

**Missing in HCM**
- **Honest workflow limits** remain common: HiBob time-off max two approvers, approver changes don't affect pending
  requests; Workday stuck tasks require gear-menu Cancel/Skip or Related Actions on the parent process; Workday archive
  shows only 30 days.
- No HCM product (other than Ramp's spend domain) documents **readiness gating**, **grouped approvals**, or
  **recommendations with cited policy** for HR requests (leave, job changes, comp).
- Approver context is thin: Rippling shows the employee's balances before approving time off *(excerpt)*, but team coverage
  ("who else is out") at decision time is only mentioned by Deel's Time Off agent *(marketing)*.

## S5. Employee / profile experience

**Recurs**
- **Header + tabs + sections** with admin-configurable layout: Notion layouts (≤15 pinned properties in heading,
  property groups, details panel, tabbed related views); Rippling configurable profiles (rename/reorder tabs, embed
  reports, viewer-dependent layout) *(excerpt)*; Personio section-level permissions.
- **Field-level permission tiers**: Personio View / Propose / Edit; HiBob per-category "View history" permission;
  Rippling scope × access × action *(excerpt)*.
- **Change via request, not edit, for sensitive data**: HiBob Actions > Update profile → summary of proposed changes
  → approver; BambooHR Request a Change templates; Personio Propose.
- **Directory + org chart** with one-tap contact (HiBob, BambooHR mobile, Deel org-chart plugin).

**Distinctive**
- **Personio's "About" tab as a working-with-me card**: organisational context, Slack link, the colleague's local
  time zone, map directions to their office.
- **Rippling: profile layout varies by viewer**, pin a field (e.g. languages) to the top of profiles for specific teams
  *(excerpt)*.
- **Lattice read-only impersonation** ("Log in as the user") with a blue banner, no actions/downloads, private content
  blocked, 1-hour cap, all sessions logged — a safe way to see exactly what an employee sees.

**Missing in HCM**
- Effective-dated history is usually a **separate table/tab** (BambooHR Job table, HiBob historical tables, Personio
  History tab) rather than a timeline woven into the profile header ("Manager changes to X on 1 Nov").
- Profiles are organised by **data category** (Personal, Job, Compensation) not by **task** ("What can I do here?");
  only HiBob's "Actions" menu and Workday's Related Actions expose tasks, and both are menus.

## S6. Analytics & storytelling

**Recurs**
- **Customisable widget homes** (Stripe, BambooHR 16 widgets, Notion 3.4 Dashboard view, Deel widgets) with **click
  to drill** into a standard report (BambooHR) or panel (Culture Amp).
- **Saved, shareable, schedulable views** (Lattice charts saved views + share + schedule; Linear views; Notion).
- **Natural-language questions returning charts** (Personio Assistant, Ramp AI Reporting, Stripe assistant on payments
  analytics, Rippling AI generating SQL/reports *(excerpt)*).

**Distinctive**
- **Narrative + auto-diff updates** (Linear project updates: health On track/At risk/Off track, reminders in the
  lead's timezone, auto progress report shown only when progress moved >2%, agent-drafted text).
- **Culture Amp**: heatmap cell → explorer panel with favourability split, trend, biggest-spread demographics, focus
  areas, AI comment summary; **small-n fallback** (<25 responses → company-wide focus areas); **confidentiality
  minimums** (reporting and comments) + **indirect identification protection**, locked at launch and shown to
  respondents; then **Take Action** prompts the report owner with focus flags and an action-plan tracker.
- **Rippling AI "shows its work"** (answers expressed as SQL/formulas/reports so they're auditable) *(excerpt)*.
- **Stripe home surfaces exceptions** (unresolved disputes) alongside charts.

**Missing in HCM**
- Outside Culture Amp, HCM analytics stop at charts/widgets; few connect **metric → explanation → owner → action**.
- **Privacy thresholds for people analytics** (minimum group sizes, inference protection) are explicit only in Culture
  Amp; none of the HRIS dashboards (BambooHR, HiBob, Personio) document k-anonymity-style suppression for small
  groups in headcount/comp breakdowns (NOT VERIFIED either way).

## S7. Tables, lists & bulk work

**Recurs**
- **Filters as chips** with clear-all (Stripe suggested/active chip pattern; Linear quick filters; Notion simple
  filters) and **advanced AND/OR groups** (Linear nested; Notion up to three levels).
- **Display options separate from filters**: which columns/properties, grouping and sub-grouping, ordering, density,
  show empty groups (Linear; Notion per-view property visibility; Stripe "Edit columns" in reports).
- **Personal vs shared view state**: Linear personal persistence + "Set as default" for workspace; Notion filters saved
  for everyone or kept personal.
- **Bulk selection**: checkbox reveal on hover, Shift-range, select-all after filter, floating bulk bar (Linear),
  group checkboxes (Ramp).

**Distinctive**
- **Linear's keyboard table model**: `X` select, Shift+↑/↓ extend, Cmd+K on selection, `F` filter, Shift+V display,
  Opt+V save view, Space peek.
- **Stripe's rule "filter before pagination"** so counts are honest, and "filter labels match column headers".
- **Canvas grid copy rules** (singular unique headers; abbreviations allowed only in grids; empty-value vocabulary:
  none / (empty) / 0 / N/A / — / blank).

**Missing in HCM**
- Workday admits its legacy grid needs work ("intends to update our grid tooling … to improve performance, meet
  accessibility standards, and improve usability").
- No HCM product researched documents **saved views on the people list** with personal/shared scopes comparable to
  Linear/Notion (Lattice has saved views for *charts* only).
- **Density control** is documented only in Linear.

## S8. Forms, multi-step flows & effective dating

**Recurs**
- **Effective date as a first-class question** on every HR change: Personio (Today / past / future at 03:00 /
  temporary with revert date), Lattice bulk update (effective date; conflicting scheduled change → skipped), Rippling
  (edit effective dates of historical changes with downstream-impact notices) *(excerpt)*, HiBob (one effective row at
  a time; future-dated termination effective at midnight in the *site's* timezone), BambooHR history rows,
  Workday/SuccessFactors effective-dated business processes.
- **Two timestamps**: when it was entered vs when it takes effect (Rippling change report; Lattice audit log with
  effective-date range).
- **Review before commit**: HiBob approver sees a "summary of proposed changes"; Rippling AI stages bulk changes
  ("Reassign all of David's direct reports to Jamie effective Monday") for confirmation *(excerpt)*; Stripe assistant
  "you only need to confirm"; Lattice "Preview employees" before saving an access rule.
- **Sticky task footer**: Canvas Action Bar (bottom, ≤3 actions + overflow, persists until submitted); Stripe
  progress stepping in the footer with final primary button; Apple sheets with Cancel/Done/Back.

**Distinctive**
- **Personio temporary change** (auto-revert date) and **visibility of pending future values** only to
  Propose/Edit users.
- **BambooHR carry-forward**: new history rows inherit department/division/manager.
- **Canvas accessible-forms rules**: don't disable submit buttons; prefer read-only text over disabled controls and
  explain why; required not by colour alone; formats in labels, placeholders only for examples.
- **Canvas wizard copy**: "Save and Continue", not "Next".

**Missing in HCM**
- Downstream **impact preview** (payroll, benefits, approvals, access) before an effective-dated change commits is
  mentioned only by Rippling *(excerpt)*; no product documents a visual "before/after on date X" diff for job changes.
- Retroactive edits are fragile across products (Personio: applied only if no later-dated entry exists; Lattice:
  conflicting scheduled changes skipped; HiBob: one row effective at a time) — conflict handling is rarely surfaced
  in the UI before submit.

## S9. Motion

**Recurs** — Purposeful, brief, interruptible, optional: Apple ("don't add motion for the sake of adding motion",
"make motion optional", "let people cancel motion", avoid motion on frequent interactions) and Canvas (Fast, Simple,
Purposeful; Quick vs Purposeful easing; defer to platform motion on native). Reduce Motion → fades instead of
x/y/z transitions (Apple).
**Distinctive** — Canvas publishes concrete easing tokens (Quick `cubic-bezier(0.2,0,0.2,1)`); Stripe's spinner
`delay` (200–300 ms) is effectively a motion rule against flashing loaders; Linear peek's hold-Space temporary
preview.
**Missing in HCM** — Beyond Canvas, no HCM vendor publishes motion guidance.

## S10. Typography & density

**Recurs** — One workhorse sans for UI (Inter at Linear; SF at Apple) with a display cut or brand face for headings
(Linear Inter Display; BambooHR's Fields serif for warmth). Apple: avoid light weights; minimum sizes (macOS 13 pt
default / 10 pt min; iOS 17/11 pt); limit typefaces.
**Distinctive** — Linear generates whole themes from 3 inputs in **LCH** (perceptually even lightness) with a
contrast slider; tuned alignment so density is "felt". BambooHR openly notes extra negative space was "divisive among
data-oriented users" — the classic friendliness-vs-density tension in HR tools.
**Missing in HCM** — No HCM product researched offers a user-selectable density setting (only Linear does).

## S11. Mobile

**Recurs** — Bottom navigation + a global create/primary action (Linear Create button on every screen; Notion one-tap
create; Stripe + menu); mobile = **monitor + high-frequency actions** (approve, request time off, clock in, payslips):
HiBob, BambooHR, Personio, Deel, Rippling, Stripe. Detail screens use a **bottom action bar** with overflow (Stripe).
Push notifications deep-link to the item (Ramp, Stripe).
**Distinctive** — Stripe lock-screen metric widgets (17); Deel offline mode and claimed full parity; Linear user-
customisable bottom toolbar and pins; HiBob clock-in from the home screen; BambooHR future-balance calculation while
requesting leave; Workday Request Absence micro-calendar with Calendar vs Date-range toggle and View Balances.
Apple: ≤5 tabs, never hide/disable tabs, tab bar minimises on scroll, iPad tab bar adapts to sidebar.
**Missing in HCM** — Manager mobile approval with *context* (team calendar/coverage) is rarely documented; Ramp's
SMS-reply capture model (reply to a text with a photo) has no HR equivalent found (e.g. reply to confirm a shift or
attach a sick note).

## S12. Dark mode

**Recurs** — Respect the OS setting (Apple: "don't offer app-specific appearance settings"; BambooHR iOS follows
system; Notion offers "Use system setting" plus explicit Light/Dark).
**Distinctive** — Linear's generated themes keep contrast consistent in both modes; Apple base vs elevated
backgrounds; test Dark + Increase Contrast + Reduce Transparency.
**Missing in HCM** — Web dark mode is absent or recent across HR products: Stripe has none ("isn't a high priority");
BambooHR mobile only; Rippling added a toggle in Oct 2025 *(excerpt)*; Workday, HiBob, Personio, Lattice web dark mode
NOT VERIFIED. Note Apple's rule conflicts with Notion/Rippling's explicit toggles — a system-default *plus* override is
the pragmatic web norm.

## S13. Accessibility

**Recurs** — WCAG contrast 4.5:1 text / 3:1 large or non-text (Apple, Canvas); colour never the only signal (Apple,
Canvas status indicators combine colour + text); keyboard operability (Apple Full Keyboard Access; Lattice aims for
"full keyboard operability"); ARIA live regions for toasts (Canvas `status` = polite).
**Distinctive** — Canvas **step-difference contrast framework** (≥500 steps = AA text, ≥700 = AAA) lets designers
pick safe pairs without a calculator; Stripe deliberately **limits custom styling** of third-party UI to protect
contrast; Apple hit targets (iOS 44×44 pt default) and text scaling to 200%; Lattice's candid "partially compliant
with WCAG 2.2".
**Missing in HCM** — Public, specific accessibility conformance statements are rare among HR vendors (Lattice and
Workday Canvas are exceptions among those researched).

## S14. Empty, loading & error states

**Recurs**
- **Loading**: show something immediately (Apple), skeleton when layout is known vs dots/spinner when not (Canvas),
  scope the indicator to what is loading (Stripe large/medium/small), keep navigation (tabs) visible while content
  loads (Stripe), button `pending` state that disables to prevent double submit (Stripe).
- **Empty**: say what's missing and offer the next action (Apple, Stripe); Canvas empty-value vocabulary for fields.
- **Errors**: near the problem, say how to fix it, no blame, no "oops" (Apple, Canvas); errors vs warnings =
  hard-stop vs soft-stop (Canvas; Workday critical red vs warning orange).

**Distinctive**
- Stripe: distinguish **first-use empty vs filtered-to-zero vs all-removed**; never show "create first" when items
  are merely filtered out; state precedence **loading → error (retry) → empty → content**; 200–300 ms spinner delay.
- Canvas: error messages get an auto-inserted **hyperlinked field label** above them; variables at the end after a
  colon for translation; no "please"/"sorry".

**Missing in HCM** — Little public guidance from HR vendors other than Workday. Permission-caused emptiness ("you
can't see this because…") is called out only by Canvas ("explain why the field is disabled and how to proceed") and
Notion (row-access users hit an error at the source database).

## S15. Feedback, confirmation & undo

**Recurs** — Toast for transient success after a user action; banner for persistent system-level issues; dialog only
for decisions (Stripe toast ≤30 chars vs banner; Canvas low/medium/high emphasis). Avoid confirmation dialogs for
common reversible actions (Apple alerts; NN/g "cry wolf"); prefer **undo** (Apple undo guidance; Linear Cmd+Z;
Notion version history/trash 30 days).
**Distinctive** — Apple: label undo with the action ("Undo Address Change") and scroll to show what was restored;
Stripe mobile: deactivating a payment link happens without a prompt and is reversed via "Activate" in the action bar;
Notion: restoring a past version is itself reversible.
**Missing in HCM** — No HR product researched documents **undo** for HR actions; changes go through approvals or
effective-dated rows instead, and corrections require new transactions (Workday cancel/rescind via Related Actions).
A "pending, cancellable until approved/effective" state is the natural HCM equivalent of undo but is only partly
present (HiBob mobile lets employees view status and cancel time-off requests; BambooHR mobile lets them edit and
comment on requests *(excerpt)*).

## S16. AI assistants, agents & trust (cross-cutting, 2025–26)

**Recurs** — Assistant in a **right-side drawer/panel** (Stripe, Lattice, Notion agent chats), **permission-scoped
answers** (Lattice, Personio, Deel, Workday "role, location"), **acts with confirmation** (Stripe "you only need to
confirm", Rippling staged changes *(excerpt)*, Lattice add talking point/draft update), **runs inside chat tools**
(Workday Everywhere, Deel Slack AI panel, Ramp SMS/Slack, Culture Amp via MCP).
**Distinctive** — **citations**: Lattice "Source" link per answer and "See sources" on review drafts; Ramp cites the
policy text *and version*; Rippling expresses answers as SQL/reports *(excerpt)*; Culture Amp lets admins toggle AI
comparisons off for shared reports and collects thumbs feedback; Lattice "Preview employees" for knowledge access.
**Missing in HCM** — Few products expose **what data the AI used and who can see the conversation** in the UI
(Lattice notes admins can see questions about uploaded documents; others don't document it).


---
---

# Part 3 — Anti-patterns observed in traditional HCM UIs (Workday / SAP SuccessFactors / BambooHR / Zoho People style)

Each item states the pattern, the evidence, and the contrasting pattern seen elsewhere. Review-site quotes are
individual user opinions (EXCERPT level) and indicate perception, not measured fact.

| # | Anti-pattern | Evidence (source, evidence level) | Contrast seen elsewhere |
|---|---|---|---|
| A1 | **Navigate-by-hub, return-to-home loops** — reaching a task means going back to Home/menu and drilling down again; many clicks per task. | Workday reviews: "way too many clicks and navigation is sometimes an issue"; "constantly having to go to the home screen to navigate back into a position" — https://www.g2.com/survey_responses/workday-hcm-review-3163551 ; https://capterra.com/p/66908/Workday-HCM/reviews/?page=6 (EXCERPT). SuccessFactors: "difficult navigation", inconsistent between modules — https://www.capterra.com/p/144575/SuccessFactors-Perform-and-Reward/reviews/ (EXCERPT). | Linear G-sequences + Cmd+K; Stripe auto "Shortcuts" (recent + pinned); Apple: one primary search location. |
| A2 | **Actions hidden behind object menus** (Related Actions "brick"/"twinkie", Take Action dropdowns, gear icons) — the user must know which object owns the action and dig through two-level menus. | Workday Inbox guide: stuck task → gear icon → Cancel/Skip/Delete Incomplete, else View Details → "Related Actions (brick) icon on the Parent Process" → Cancel — https://workdaytraining.geisinger.org/PDFContent/J165_WorkdayInbox.pdf (FETCHED). Canvas Related Actions menus are multi-level ("Benefits > Change Benefits") — https://canvas.workday.com/guidelines/content/ui-text/related-actions-menu (FETCHED). SuccessFactors "Take Action → Change Job and Compensation Info → Job Information" — https://userapps.support.sap.com/sap/support/knowledge/en/2435984 (EXCERPT). | Linear selection-aware Cmd+K and right-click menus that show shortcuts; Ramp/Lattice quick actions on Home; HiBob "Actions > Update profile" at least surfaces flows by name. |
| A3 | **Inbox items that don't finish where they start** — "To Do" tasks redirect elsewhere and must be revisited to click Submit; reassignment sits in your inbox until HR approves; history visible only 30 days. | Workday Inbox guide (To Dos: "don't forget to return to the inbox to click this button"; Archive shows "the last 30 days"; Reassign "will remain in your inbox until the Reassignment is approved") — https://workdaytraining.geisinger.org/PDFContent/J165_WorkdayInbox.pdf (FETCHED). | Ramp readiness gating (only complete, actionable items reach approvers); Lattice task semantics (skip vs non-dismissable, expiry dates); Linear inbox triage (snooze, unsubscribe, delete). |
| A4 | **Rigid approval chains with surprising edge cases** — fixed approver counts, changes that don't apply to in-flight requests, no per-person override. | HiBob: max two time-off approvers; "Changes only affect future requests"; custom flows block per-employee overrides — https://heartcorehr.hibob.com/time-attendance-174/time-off-request-approval-limitations-7891 (FETCHED). BambooHR reviews: limited approval-hierarchy flexibility — https://www.capterra.com/p/110968/BambooHR/reviews/ (EXCERPT). | Rippling attribute-based routing + vacation re-routing + threshold escalation (EXCERPT); Ramp delegates applying to existing and future chains; Lattice reopen-through-chain "without restarting". |
| A5 | **Legacy data grids** — dense, inaccessible, slow tables; few saved views or column controls for operational users. | Workday's own design system: "intends to update our grid tooling … to improve performance, meet accessibility standards, and improve usability" — https://canvas.workday.com/guidelines/content/ui-text/grids (FETCHED). SuccessFactors "sluggish", pages "not formatted properly for mobile" (EXCERPT, Capterra). | Linear display options (columns/grouping/density) + saved views with scopes; Stripe chip filters + "Clear filters" + filter-before-pagination; Notion per-view property visibility. |
| A6 | **"Early-2000s" visual language and inconsistent modules** — each module (Learning vs Performance vs Core) feels like a different product. | SuccessFactors: "clunky", "software environment from the early 2000s", inconsistent between modules (EXCERPT, Capterra/Gartner Peer Insights). Workday: "look and feel is reminiscent of early computer era" (EXCERPT, Capterra). Zoho People: "cluttered interfaces", overwhelming configuration — https://www.g2.com/products/zoho-people/reviews (EXCERPT). | Linear 2026: "headers, navigation, and view controls are now consistent across projects, issues, reviews, and documents"; one design system (Canvas, Kaizen, Stripe Apps components). |
| A7 | **Effective dating as an opaque table of rows** — history lives in separate tables; conflicts with later-dated rows silently block or skip retro changes; timezone of effect is implicit. | HiBob: "Only a single row can be effective at any given time"; termination effective Apr 17 takes effect midnight Apr 18 in the site timezone — https://apidocs.hibob.com/docs/additional-employee-data.md (FETCHED). Personio retro change applies only if no later entry exists (EXCERPT, support 213331029). Lattice bulk update skips conflicting scheduled changes (EXCERPT). SuccessFactors effective dates "from that date forward" with application errors in some configurations (EXCERPT, SAP KB). | Rippling downstream-impact notifications when editing historical effective dates (EXCERPT); Personio shows pending future value + date to Propose/Edit users and supports temporary changes with auto-revert. |
| A8 | **Disabled controls and hard stops without explanation** — greyed-out fields/buttons, red validations collected behind a "View All" button. | Workday critical (red, blocking) vs warning (orange) validations read via "View All" — https://workdaytraining.geisinger.org/PDFContent/J165_WorkdayInbox.pdf (FETCHED). Canvas itself recommends *against* disabled submit buttons and for explaining disabled fields — https://canvas.workday.com/guidelines/accessibility/accessible-forms (FETCHED) — indicating the legacy pattern exists. | Apple: errors "as close to the problem as possible … clear about what someone can do to fix it"; Canvas hyperlinked field-label subheadings; Stripe pending button state. |
| A9 | **Reporting that is either too rigid or too technical** — limited custom reports for SMB tools; heavy report-writer tools for enterprise. | BambooHR: "Reporting customization is limited" (EXCERPT, Capterra/G2 2026). Personio: limited/unreliable reporting for some (EXCERPT, Capterra). | BambooHR's own newer Insights widgets with drill-down; Lattice saved/scheduled chart views; Culture Amp explorer panel; NL-to-chart assistants (Personio, Ramp, Stripe). |
| A10 | **Continuous redesign without change management** — users lose muscle memory when layouts shift. | Personio: "the interface keeps on changing" (EXCERPT, Capterra 2026). BambooHR explicitly mitigated this ("all of your tools are right where you need them to be") — https://bamboohr.com/product-updates/new-user-interface (FETCHED). | Notion 3.4 sidebar shipped **opt-in** first; Culture Amp in-app "News" + public newsfeed as "first point of reference" for changes. |
| A11 | **Confirmation-dialog overuse instead of undo** (general enterprise pattern). | NN/g: overuse causes habituation — "if you cry wolf too many times, people will stop paying attention"; prefer undo, specific button labels — https://www.nngroup.com/articles/confirmation-dialog/ (FETCHED). Apple: no alerts for "common, undoable actions, even when they're destructive" (FETCHED). | Linear Cmd+Z; Notion trash/version history; Stripe mobile reversible deactivate; Apple descriptive undo labels. |
| A12 | **Rigid linear workflows / no side-by-side reference** in complex tasks (general enterprise pattern). | NN/g complex-application guidelines: avoid "rigid, linear workflows"; "access and view supplemental information without leaving the primary screen"; "reduce clutter without reducing capability" — https://www.nngroup.com/articles/complex-application-design/ (FETCHED). | Stripe ContextView side-by-side drawer; Notion side peek; Culture Amp explorer panel; Canvas contextual side panel. |
| A13 | **Assistant-first as a substitute for fixing navigation** — vendors cite large time lost searching and answer with chat. | Workday's 2024 release frames the problem as people wasting "up to two hours a day searching for HR and financial information" and answers with Workday Assistant (EXCERPT, Workday newsroom); SAP claims Joule gives "90% faster execution of navigation and transactional tasks" — https://sapinsider.org/articles/unlocking-the-power-of-joule-ai-in-successfactors-simplifying-navigation-actions-and-information-retrieval (EXCERPT, vendor claim). Workday Assistant itself is slated for retirement in 2027R2 (FETCHED, doc.workday.com). | Products that pair AI with strong direct-manipulation UI and citations (Lattice sources, Ramp cited policy + version, Stripe confirm-before-act). |

---

# Part 4 — Coverage, method notes and verification gaps

**Access limitations encountered (affects confidence)**
- **Blocked to automated fetch**: rippling.com (HTTP 403, all pages); support.personio.de (Cloudflare challenge) and
  personio.com (Vercel checkpoint/429); BambooHR help centre (login wall); peoplemanagingpeople.com (403);
  canvas.workday.com (403 to WebFetch but readable via a browser user-agent with curl).
- **Client-rendered docs** read via alternative endpoints: Apple HIG via its JSON data endpoints; Lattice and Culture
  Amp Intercom help articles via curl (WebFetch returned only navigation).
- **Rippling** observations are entirely EXCERPT-level; **Personio** mostly EXCERPT; treat both as hypotheses to
  confirm in a live demo or trial tenant.

**Not verified / looked for but not found**
- Linear: a general toast-with-undo pattern (only Cmd+Z for team moves documented).
- Stripe: native Dashboard dark mode (community says not a priority); saved views on Dashboard lists.
- Apple: a dedicated "Inspectors" HIG page (data endpoint 404).
- HiBob, Personio, Lattice, Workday: web dark mode status.
- Any HCM product: an action-executing command palette; structured people-search syntax; density setting;
  saved views with personal/shared scopes on the people list; undo for HR actions; small-group suppression in HRIS
  dashboards (other than Culture Amp surveys).

**Date context** — Research performed 2026-10-04. Most-recent items found: Linear UI refresh (Mar 2026) and mobile nav
customisation (Jan 2026); Notion 3.4 (Mar 2026); Workday 2026R1 (Mar 2026); Lattice Spring/Summer 2026 release;
Culture Amp MCP (Aug 2026); BambooHR Bamboo AI (Jul 2026, excerpt); Ramp Policy Agent GA (Jan 2026).
