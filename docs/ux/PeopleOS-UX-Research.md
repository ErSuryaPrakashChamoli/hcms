# PeopleOS UX research

Experience Transformation, research phase (UX-1), 3 October 2026. This document answers one question: **what should PeopleOS do differently?** It is the input to the design system (`PeopleOS-Design-System.md`) and to every screen redesigned in this transformation.

## 1. Method and evidence

**Products studied.**
- HCM: Rippling, Deel, HiBob (Bob), Personio, Workday (HCM and the Canvas design system), BambooHR, Lattice, Culture Amp (and its Kaizen design system).
- Modern SaaS outside HR: Linear, Superhuman, Notion, Stripe, Ramp, Mercury, Slack, Figma, Vercel (Geist).

**Sources.** Public product pages, help centres, release notes, open design-system repositories (Canvas Kit, Kaizen, Geist) and public reviews. No product was used under a login. Nothing was copied: no screens, assets, wording or flows. We record patterns and the reasons behind them, then decide what PeopleOS does.

**Evidence quality.**
- Most findings come from pages that were fetched and read.
- Several vendor sites blocked fetching: rippling.com, help.hibob.com, support.personio.de, canvas.workday.com, the BambooHR help centre, support.mercury.com and help.superhuman.com. Findings from their search-engine excerpts are marked *(snippet)*.
- Findings from user reviews are marked *(review)*.
- Eleven high-impact claims were re-fetched and confirmed:
  - Ramp Policy Agent;
  - Linear's 2026 refresh;
  - the Lattice AI Agent;
  - Deel's August 2026 changes;
  - Rippling AI's interface;
  - Ask BambooHR;
  - Bamboo AI;
  - Canvas Kit v16;
  - Bob Companion;
  - Personio Assistant;
  - Workday's July 2026 layout.
- Dates are as stated on each source.

**Limits.**
- This is desk research. It does not include usability testing with PeopleOS users.
- Accessibility claims are the vendors' own.
- A pattern being popular is not evidence that it works. Section 4 says where we think a pattern is overused.

## 2. Findings by product (condensed)

### Rippling
- **Navigation:** a left icon sidebar. Reviewers say the icons are unclear until hovered and ask for menu search *(review)*.
- **Home:** a task summary, "a heads-up view of all your pending tasks and approvals every time you log in" *(snippet, May 2025)*.
- **Approvals:** done from Slack, email or the approvals tab. Chains route by employee attributes and re-route when an approver is away *(snippet)*.
- **Rippling AI (2026):**
  - Structured data appears as sortable tables.
  - Clarifying questions appear as selection controls.
  - Actions need a dedicated confirmation.
  - It inherits the user's permissions and "shows its work" *(snippet)*.
- **Data Cloud (July 2026):** charts from a plain-language prompt, with an inspectable query, point-in-time history and a "my direct and indirect reports" filter.

### Deel
- **Admin navigation:** a persistent, collapsible left sidebar (June 2026). A profile selector covers people who hold several roles or belong to several organisations.
- **Home (August 2026):** "highlights exactly what action is required … instead of a generic dashboard".
- **Worker profile:**
  - Two-level subpages.
  - Arrow keys move to the previous or next profile.
  - Prioritised, expandable quick actions.
- **Org chart:** new view types, and AI bulk edits by prompt.
- **Workflows:** a simulation tool, and a runtime view of every run.
- **Decision-time context:** a misclassification warning "at the exact moment of decision", a risk icon on invoice rows, and "Approve with issues" on mobile.
- **Chat and mobile:** Slack and Teams approvals write back to Deel. A mobile inbox collects every pending approval and task.

### HiBob (Bob)
- **Home:** social-feed style. Birthdays, anniversaries, who is out, shoutouts and quick links.
- **Directory:** by site, department or team, plus "Club View" (hobbies, pronouns, nationality).
- **Lifecycle:** effective-dated history tables *(snippet)*.
- **Bob Companion (April 2026):**
  - One conversational surface over several specialised agents.
  - It can act, for example "schedule a 1-on-1".
  - In Slack, "answers and available actions always follow each employee's existing permissions".
- **Workflows:** branching, multi-step approvals, status visibility and an audit-ready history.
- **Accessibility:** WCAG 2.2 AA stated as a goal.

### Personio
- **Redesign:** inspired by consumer apps. Home was refocused on the user and their immediate team.
  - A two-column layout: announcements, tasks and celebrations on one side, widgets on the other.
  - Clock in from Home.
  - A team-capacity timeline.
  - A balance check before booking time off.
- **Search:** Ctrl/Cmd+K global search, keyboard driven, across people, positions, documents, settings and functions *(snippet)*.
- **Central Inbox:**
  - Tasks, approvals and notifications in one prioritised list.
  - Sort and filter.
  - Bulk "approve all" and grouping of similar approvals *(snippet)*.
- **Profile:**
  - Sections show the viewer's access rights.
  - One edit can be scheduled for a future date.
  - Locked attributes are marked *(snippet)*.
- **Personio Assistant (February 2026):** requests absences inside the assistant. Linked sources and feedback *(snippet)*.
- **Accessibility:** follows WCAG 2.2 AA.

### Workday and Canvas
- **Home:**
  - "Awaiting Your Action" shows the top three inbox items with due tags.
  - Quick tasks appear as pills, and apps are ordered by use.
  - Timely Suggestions pair one line with one link.
- **2026 layout:** search became the primary entry point on Home (2026R1). Global navigation is always visible on the left, and a "Saved" bookmark was added (July 2026).
- **Search grammar:** prefixes narrow by object type (`bp:`), and `?` lists the prefixes.
- **Related Actions:** next to every object, opening action categories plus an object preview.
- **Inbox:** Approve / Deny / Send Back / Save for Later, Overdue and Due-soonest sorting, and delegation.
- **In-task guidance:** a manager awarding a bonus sees the allowable range, the policy and the peer average.
- **Canvas Kit v16:**
  - Three token tiers (base, system, brand).
  - A KBD component for showing keyboard shortcuts.
  - Side panels as overlay or inline.
  - Loading guidance: no indicator under one second, and a skeleton when the layout is predictable.
  - An AI working indicator kept in a live region separate from the generated text.

### BambooHR
- **2024 UI:** a left, icon-led bar and a custom typeface. The stated aim was "warmth" and an interface that "gets out of the way".
- **Home:** widgets with an Edit mode. Which widgets show depends on access level.
- **Ask BambooHR:**
  - Answers come "straight from the source … along with a citation".
  - Answers are permission-aware.
  - A Q&A report shows HR what people ask.
- **Bamboo AI (July 2026):**
  - Embedded agents.
  - Reports edited by prompt.
  - Payroll suggestions you "review and edit before applying".
- **Directory:** "View in org chart" on every row.

### Lattice
- **Navigation:** shows only the products an admin has enabled.
- **Home:** tiles for quick actions, Coming up (1:1s), Tasks, Celebrations and Team, plus AI-summarised updates.
- **AI Agent:**
  - Each answer has a source link and an arrow to the page, with exact citations (December 2025).
  - Answers follow the requester's permissions.
  - An admin "Make a correction" loop.
  - Results toggle between chart and table.
- **AI review drafts:** "See sources". The AI cannot submit a review.
- **Accessibility:** "partially compliant" with WCAG 2.2.

### Culture Amp
- **Home:**
  - Single-step and multi-step tasks, with progress shown.
  - Upcoming, recent and suggested items.
  - A direct-report snapshot for managers.
- **AI comment summaries:** need at least five meaningful comments. They are labelled "AI generated" and grouped by sentiment.
- **Explorer side panels:** open "without losing your place".
- **Confidentiality:**
  - Minimum group sizes for results.
  - A separate minimum for comments.
  - Protection against working out who said what by elimination.
- **AI Coach:**
  - Lists its data sources as available or not.
  - Keeps conversations private.
  - States what it "will not" do, for example suggest final ratings.
- **Kaizen design rules:**
  - WCAG 2.1 AA.
  - An 8-point grid and 44 px touch targets.
  - Never put critical information in tooltips.
  - At most one primary button per section.

### Modern SaaS references
- **Linear:**
  - Cmd+K runs any action, and `?` opens a searchable shortcut list.
  - Peek with Space.
  - Filters live in the URL.
  - Triage with single keys.
  - Supporting UI "should recede" (2026 refresh).
  - Themes are generated from three variables in LCH.
- **Superhuman:**
  - One palette everywhere, with synonyms and context, that teaches the shortcuts.
  - A 100 ms response ceiling.
  - Undo within 10 seconds.
  - AI answers cite the emails they used.
- **Notion:**
  - Records open in side peek.
  - Enterprise search "will always cite its sources".
  - Q&A uses only pages the user can view.
- **Stripe:**
  - A search grammar, and pasting an ID jumps to the record.
  - ContextView (beside the page) versus FocusView (blocking).
  - State rules: loading, then error, then empty.
  - "A filtered-to-zero view must not say create first item."
- **Ramp:**
  - The policy agent uses three fixed labels: approve, review or reject.
  - It cites the rule it relied on.
  - An "I'm not sure" escape hatch, and audit of the rationale.
- **Mercury:**
  - Multi-level approval thresholds.
  - The submitter is never their own approver.
  - Mobile approvals.
- **Slack:**
  - Home / DMs / Activity / Later.
  - Dense or detailed layouts.
  - AI answers cite messages.
  - A public accessibility changelog.
- **Figma:** docked, resizable panels (floating panels slowed people down in beta), and a Cmd+K actions menu.
- **Vercel (Geist):**
  - Colour steps tied to roles.
  - Skeletons sized exactly to content and never used as an empty state.
  - Loaders appear only after 150–300 ms.
  - Optimistic updates roll back or offer undo.
  - State lives in the URL.

The research report with every source link is kept in section 6.

## 3. Thirty topics, classified

**How to read the columns.**

| Column | Meaning | Basis |
|---|---|---|
| **A** | Common: most HCM products do it | Evidence (section 2) |
| **B** | Becoming expected in 2025–26 releases | Evidence (section 2) |
| **C** | Clearly useful to people | Our judgement |
| **D** | Overused: present where it does not help | Our judgement |
| **E** | Missing from traditional HCM | Our judgement |
| **F** | Where PeopleOS differentiates | Our decision |

| # | Topic | A | B | C | D | E | F (PeopleOS decision) |
|---|---|---|---|---|---|---|---|
| 1 | Navigation | Left icon sidebar | Collapsible, dimmed; only enabled modules | Labels plus saved items | Icon-only rails without labels | Task-oriented navigation; menu search | Nine labelled sections that adapt to the person; modules move to a context rail, a space bar and the command center |
| 2 | Home / dashboards | Widgets, celebrations | "What action is required" | Top items with due dates | Generic KPI tiles | Clear priority logic | Ranked "What matters now" with the reason for each item, then the role's panel |
| 3 | Employee profile | Tabs, custom fields | Configurable tabs, quick actions | Next/previous; per-section access | Deep tab sprawl | Visible effective dating | Employee 360 as journey, with progressive sections and actions |
| 4 | Search | People search | Search as main entry; Cmd+K | Type prefixes; settings searchable | — | ID jump; saved queries | Search that returns people, records, modules, answers and actions in one list |
| 5 | Command interfaces | Rare in HCM | Cmd+K search (Personio) | Context-aware actions | — | Entity + verb | Command Center: a person row carries Open / Preview / Journey / Org / Start a change |
| 6 | Onboarding | Checklists | Welcome content | Multi-step progress | Long forms | Shared new-hire timeline | Journey stages show onboarding progress to employee, manager and HR |
| 7 | Employee self-service | Leave, payslips | Chat-driven requests | Balance before booking | FAQ portals | Preview of the outcome | One-click start from Home (leave, attendance fix, HR request); balance shown as you book |
| 8 | Manager experience | My Team pages | AI team summaries | Context at decision time | Dashboards without actions | Coverage at approval time | Team today, plus approvals that show team absence and balance impact |
| 9 | HR operations | Bulk edits, reports | Simulation before running | Impact previews | Modal-heavy CRUD | Diff before save | Explained attention list; Before → After on employee changes |
| 10 | Analytics | Dashboards, builders | Prompt → chart with query | Permission-aware dashboards | Vanity charts | Point-in-time history | "What changed / needs attention / trending / requires decision" |
| 11 | AI assistants | Chat in most | Agents that act with confirmation | Permission-scoped answers | Chat-only AI everywhere | Correction loop | Contextual, scoped, assistive; never decides |
| 12 | AI answer presentation | Text + thumbs | Citations | Tables, selections, confirmations | Prose for data questions | Fixed recommendation labels | Answer / Key facts / Sources / Suggested actions, every time |
| 13 | Notifications | Bell with counts | Unified inbox | Overdue filter | Email-only alerts | Snooze, keyboard triage | Grouped notification center with snooze and deep links |
| 14 | Workflows / approvals | Multi-step chains | Visual builders | Substitute approvers | Rigid serial chains | Context at decision | Approval Center with what / who / why / impact / effective / risk |
| 15 | Mobile | Approvals, leave, clock-in | Mobile inbox | Offline, biometrics | Full web replicas | — | Mobile = Home, Work, People, Services, Profile; same permissions |
| 16 | Tables | Sortable, exportable | Saved views | Sticky headers | Endless columns | Density toggle | Filament tables kept; PeopleOS density, sticky headers and calmer chrome |
| 17 | Forms | Long sectioned forms | Multi-step templates | Inline access hints | Modal forms for everything | Effective date + impact preview | Guided forms: context → information → decision → review → confirm |
| 18 | Filters | Faceted filters | Natural-language filters | Filters in the URL | Hidden filter state | Typed grammar | URL-held filters in People and Org map |
| 19 | Cards | Home cards | Action cards | One clear call to action | Decorative stat cards | — | Every card carries the next action |
| 20 | Timelines | History tables | Capacity timelines | Effective-dated rows | — | Visual lifecycle | Journey map from preboarding to alumni |
| 21 | Org charts | Zoom, search, export | Scenarios, dotted lines | Compact layouts | Static PDFs | Edit-in-place with preview | Progressive org map with focus, zoom, pan and a department view |
| 22 | Empty states | Generic "no data" | Guidance blocks | Call-and-response copy | Illustration only | First-use vs filtered-empty | What / why / next, distinct for filtered results and no permission |
| 23 | Loading states | Spinners | Skeletons, AI indicators | Delay thresholds | Spinners everywhere | — | Layout-true skeletons, shown only after a short delay |
| 24 | Error states | Toasts | Inline fix hints | Errors that say how to fix | Generic errors | Undo | What happened / what it means / what to do |
| 25 | Motion | Minimal | Subtle, reduced-motion aware | Interruptible animation | Decorative motion | — | 150–400 ms, purposeful, off with reduced motion |
| 26 | Accessibility | WCAG 2.1 claims | WCAG 2.2 AA goals | Design-system rules | Claims without evidence | Public changelog | AA contrast verified per token; keyboard-first; tested |
| 27 | Density | Medium | Dense/detailed toggle | Recede supporting UI | Whitespace-heavy admin tables | Role-based density | Comfortable for employees, Compact for HR operators |
| 28 | Visual hierarchy | Brand-coloured chrome | Calmer neutrals | One primary per section | Colour everywhere | — | Quiet chrome, editorial headings, content first |
| 29 | Personalisation | Draggable widgets | Personal views | Renameable features | Over-configurable homes | Smart defaults | Role-aware defaults plus density, lens, pins, recents |
| 30 | Engagement | Shoutouts, celebrations | Values + points + AI drafts | Privacy thresholds | Social-feed noise | Follow-through on insights | Announcements in "What changed"; surveys keep their privacy thresholds |

## 4. What should PeopleOS do differently?

The common pattern in HCM is a module catalogue with a dashboard on top. Modern products are moving towards **work-first, context-rich, keyboard-friendly, explainable** experiences. PeopleOS should not imitate any one of them. It should combine the useful patterns around its own strengths:
- one employee record;
- effective-dated history;
- a hash-chained audit trail;
- strict tenant, organisation and relationship scopes;
- workflow and approval engines that already exist.

**1. Start from the person's work, not from modules.**
- Home answers "what matters to me now" and ranks it.
- Each item says why it matters, for example: "Payroll cannot pay you without a primary bank account."
- KPIs come second, and only for the roles that act on them.
- *Implemented:* Home, HomeComposer, WorkInbox.

**2. Nine sections, not thirty groups.**
- The sections are Home, My work, People, Organisation, Insights, Workflows, Services, Communication and Admin.
- A section appears only if the person can open something inside it.
- Modules stay one step away in three places:
  - the section's context rail;
  - the in-page space bar;
  - the command center.
- *Implemented:* ExperienceNavigation, ModuleCatalogue.

**3. One command center that searches and acts.**
- One query returns people, requests, policies, documents, organisation, workflows, reports, services, actions and smart answers.
- Rows carry their own actions: open, preview, journey, org map, start a change.
- It never searches more than the person may see.
- *Implemented:* CommandCenter, CommandSearch, IntentSearch.

**4. Decisions arrive with their context.**
- An approval shows:
  - what, who, why and the requester;
  - the effective date and the risk;
  - the impact, for example the leave balance before and after, and who else in the team is away.
- Decisions go to the owning domain service, which re-checks every rule.
- *Implemented:* Approval Center, ApprovalCenter, ApprovalDecisions.

**5. Before → After for every change that matters.**
- Compensation, transfers, promotions, manager changes and lifecycle moves show a field-level preview before saving.
- This builds on PeopleOS's effective dating and audit.
- *Implemented:* the BeforeAfter component, the Approval Center, and Employee 360 actions.

**6. The employee journey is visible.**
- Preboarding → onboarding → joined → probation → confirmed → active → growth → transition → exit → alumni, drawn from lifecycle data that already exists.
- Clicking a stage shows its events.

**7. Context stays in place.**
- Previews and decisions open in drawers that stack; the page underneath keeps its scroll and filters.
- Full screens are reserved for long work.

**8. AI is assistive, scoped and honest.**
- Every answer has the same shape: Answer, Key facts, Sources, Suggested actions.
- It uses only data the person may see.
- It states what it will not do: decide employment, pay, compensation, termination, promotion or hiring.
- It never offers free-form natural-language-to-SQL. Smart search uses a fixed, reviewed set of question patterns.

**9. Calm by default, dense on demand.**
- Quiet chrome and editorial headings.
- One primary action per section.
- Comfortable density for employees, Compact for HR operators.
- A dark theme designed separately, not inverted.
- Reduced motion respected everywhere.

**10. States are designed, not left over.** The following each have their own wording:
- empty for the first time;
- filtered to nothing;
- no permission;
- loading (a layout-true skeleton, never a blank page);
- error (what happened, what it means, what to do).

**11. Measurable.**
- Click targets are part of the definition of done, for example: request leave in at most 3 clicks, find a person in at most 2.
- Usage is counted anonymously (counts and timings only, never content) so the experience can be improved without watching people.

### Patterns we deliberately do not adopt
- **Social feeds on Home** (birthdays, hobbies, "Club View"). They are noisy in operational work and expose personal data. Recognition belongs in its own place.
- **Drag-and-drop widget dashboards** for everyone. Role-aware defaults, a lens switch and hiding a card cover the real need.
- **Floating panels.** Figma's beta evidence argues for docked panels.
- **AI that acts autonomously on employment data, or AI chat as the only way in.** PeopleOS AI explains and suggests; people decide.
- **Unrestricted natural-language querying of the database.**
- **Icon-only navigation without labels.** Reviews of Rippling show the cost.
- **Undo for decisions that other people act on immediately** (approvals that post leave or trigger payroll inputs). PeopleOS asks for a confirmation step instead.

## 5. From research to design

| Research signal | Design system decision |
|---|---|
| Calmer neutrals, supporting UI recedes (Linear, Canvas) | Paper-toned canvas, white working surfaces, hairline borders, one brand colour (lagoon teal) with a warm accent |
| Role-tied colour steps; generated themes (Geist, Linear) | Semantic tokens (`--pos-*`) with a designed dark set; every text pair checked for WCAG AA |
| KBD component (Canvas v16) | `.pos-kbd` and visible shortcuts in the command center, approvals and the `?` sheet |
| Skeleton rules (Canvas, Geist) | `.pos-skeleton` shapes that mirror content, only for lazy panels and drawers |
| One primary per section (Kaizen) | `pos-btn-primary` used once per card; the rest are secondary or ghost |
| Side peek / ContextView (Notion, Stripe) | `.pos-drawer`, right side on desktop, bottom sheet on phones, stacking with Back |
| Dense/detailed views (Slack) | `data-pos-density` = comfortable / compact |
| 44 px touch targets (Kaizen) | Bottom navigation items at least 48 px; buttons at least 30 px with spacing |

## 6. Sources

- **Rippling:**
  - softwarefinder.com/hr/rippling/reviews (pages 9 and 73)
  - rippling.com/blog/may-2025-product-updates
  - rippling.com/permissions
  - rippling.com/blog/configurable-profiles-launch
  - rippling.com/platform/workflows
  - rippling.com/blog/introducing-rippling-ai
  - langchain.com/blog/how-rippling-went-ai-native-…
  - cpapracticeadvisor.com/2026/07/01/rippling-introduces-data-cloud
  - rippling.com/blog/rippling-data-cloud-bi-and-dashboards
- **Deel:**
  - deel.com/blog/whats-coming-june-2026, -july-2026, -august-2026, -september-2026
  - deel.com/blog/whats-new-october-2025
  - deel.com/blog/changelog
  - deel.com/hr-platform/ai
  - deel.com/hr-platform/workflows
  - deel.com/hr/workforce-planning
  - deel.com/plugins
- **HiBob:**
  - hibob.com/features/core-hr-lp
  - hibob.com/platform/core/self-service
  - hibob.com/compare/bob-vs-alternatives-guide
  - hibob.com/blog/value-bob-ai
  - heartcorehr.hibob.com (Slackbot beta)
  - hibob.com/lp/automation-2
  - hibob.com/privacy/hibobs-website-accessibility-statement
- **Personio:**
  - personio.com/about-personio/press/personio-redesign
  - community.personio.de (a new Personio; explainer videos; org chart; Personio Assistant; workflow automation; accessibility)
  - support.personio.de (global search; inbox; sections and attributes; assistant overview)
  - personio.com/blog/redesigned-inbox
- **Workday and Canvas:**
  - kean.edu Workday people-experience guide
  - doc.workday.com (home page cards)
  - union.edu (2026R1)
  - erp.umd.edu (July 2026 homepage)
  - helpdesk.ucumberlands.edu (search)
  - pensacolastate.edu (related actions)
  - it.tamus.edu (inbox)
  - newsroom.workday.com (2024-09-17)
  - diginomica.com (Sana)
  - github.com/Workday/canvas-kit (v16 upgrade guide, tokens, LoadingSparkles, AIIngressButton)
  - canvas.workday.com/v9/patterns/loading
- **BambooHR:**
  - bamboohr.com/product-updates (new user interface, redesigned home, org-chart quick link, recognition, Ask BambooHR)
  - bamboohr.com/hr-software/ask-ai-assistant
  - bamboohr.com/about-bamboohr/press-release/bamboohr-launches-bamboo-ai
  - bamboohr.com/mobile
- **Lattice:**
  - help.lattice.com (navigation, home page, AI agent, corrections, charts, engagement insights, org chart)
  - lattice.com/blog (December 2025; Fall/Winter 2025; Spring/Summer 2026)
  - lattice.com/trust/accessibility
- **Culture Amp:**
  - support.cultureamp.com (home page, AI comment summaries and comparisons, heatmap explorer, confidentiality, AI Coach, taking action)
  - updates.cultureamp.com
  - github.com/cultureamp/kaizen-design-system (DESIGN.md, Workflow, EmptyState)
- **Modern SaaS:**
  - linear.app/now (UI redesign; 2026 refresh)
  - linear.app/docs (display options, filters, peek, triage intelligence)
  - blog.superhuman.com (command palette; speed; inbox zero; Ask AI)
  - notion.com (releases 2022-07-20; enterprise search; Q&A)
  - docs.stripe.com (dashboard search; Stripe Apps design; empty-state and state patterns; Workbench)
  - support.ramp.com/policy-agent-overview
  - builders.ramp.com/post/how-to-build-agents-users-can-trust
  - support.mercury.com (approvals)
  - slack.com/help (Activity view; AI citations; accessibility changelog)
  - figma.com/blog/our-approach-to-designing-ui3
  - help.figma.com (actions menu; shortcuts; accessibility)
  - vercel.com/geist (colors, materials, skeleton)
  - vercel.com/design/guidelines
