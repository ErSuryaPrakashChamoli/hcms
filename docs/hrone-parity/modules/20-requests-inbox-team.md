# HROne vs PeopleOS — 20 Requests · Inbox · Team · Search (field by field)

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A.

**Inputs.**

- `hrone-research/pages.jsonl` (latest line per id): the 15 `route_app_request_*` pages, `route_app_inbox`,
  `route_app_inbox_extended`, `route_app_search` (+ tabs), `route_app_socialwall_search` (+ tabs).
- `hrone-research/components.json`: every request form behind the pages and the request types that have no route
  (Leave, Leave encashment, On duty, Change shift, Resignation), the post-submit dialog, the workflow tracker, the
  discussion panel, the 78 inbox task views (components whose children include `app-inbox-header2`), the inbox filters,
  archive and insight panels, the per-type request history lists (`app-*-search`, `app-*-card`), the Team components
  (`app-overview-team`, `app-peoples`, team calendar / goal / geo dashboards, `app-position-management`) and the global
  search components (`app-global-search-*`, `app-enterprise-search`, `app-profile`).
- `hrone-research/routes.json` (request, inbox, team and search routes).
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` (read for structure only; no personal data
  copied). `docs/hrone-parity/hrone-feature-inventory.md` §1 (rail: Request tabs, Team flyout) and its "TEAM ACTIONS".
- PeopleOS, checked in code: `app/Filament/Pages/{MyHr,MyDay,MyWork,MyTeam,TeamRequests,TeamWorkforce,Approvals,TaskInbox,OutOfOffice,RequestDiscussion,DeclareInvestments,AttendanceCalendar,LeaveCalendar,Home,SocialWall,PositionHierarchyPage,VacanciesPage,ReportLibraryPage}.php`,
  the request resources (`LeaveRequests`, `TimeOfficeRequests`, `AttendanceRegularisations`, `Loans`,
  `ReimbursementClaims`, `VariablePayRequests`, `ExpenseAdvances`, `ExpenseReceipts`, `ExpenseReports`,
  `TravelRequests`, `AssetRequests`, `Tickets`, `MyLeaveEncashments`, `MyInvestmentProofs`, `Goals`, `Positions`),
  `app/Filament/Support/{LeaveActions,TimeOfficeActions,ServiceDeskActions,ExitActions,TaskActions,SendBackActions}.php`,
  the services behind them (`Leaves`, `TimeOfficeRequests`, `Regularisations`, `Loans`, `ReimbursementClaims`,
  `VariablePay`, `ExpenseAdvances`, `TravelRequests`, `AssetRequests`, `Exits`, `GoalPlans`, `SelfServiceLock`),
  `app/Domain/Experience/Services/{ApprovalCenter,WorkInbox,ExperienceTasks,CommandSearch,QuickActions,InboxArchives,TaskDelegations}.php`,
  the Approval Center sources in `app/Domain/Experience/Sources/`, `config/peopleos.php` (role templates, task sources),
  `docs/architecture/hrone-parity.md` (P3, P4, P5 sections) and `docs/hrone-parity/setup/00-build-status.md`.

**Progress.**

- Covered: every captured `/app/request/...` page and its form components; the five request types reached only from the
  rail "Request" flyout (from components); the shared request chrome; the per-type request history lists; `/app/inbox`
  and `/app/inbox/extended` with filters, archive, insight, header actions and all 78 inbox task types; Team → People,
  Team analytics, Team reports and Team → Position management (from components); `/app/search` and
  `/app/socialwall/search` (global search plus the home page rendered under it).
- Not captured yet: the crawl's `rail` pass had not run when this matrix was written (`pages.jsonl` held only `route`
  lines, 325 at the last read), so there are no `rail_Request*` or `rail_Team*` pages. The Request flyout list was read
  from the page text (the flyout is open on every request page); its tabs Pending / Approved / Rejected / Undo / Drafts
  come from the feature inventory. Team reports (`/app/team/team-reports`) and Team → Position were not crawled as
  pages; their rows come from components only. The "All Messages" dropdown options were not captured (rows marked
  *(inferred)*). `initiate-separation` has no route in `routes.json`; its form is `app-request-separation`
  (opened from the flyout item "Resignation").
- Result: 510 rows — HAVE 200, PARTIAL 191, MISSING 101, N-A 18 (see "Counts"); 15 build packages RQ-1 … RQ-13
  (RQ-7 split into 7a / 7b / 7c). Re-check when the crawl's `rail` pass lands: add any `rail_Request*` / `rail_Team*`
  page whose fields are not in the tables below.
- Re-check done 2026-10-29 (last section): permission-tree branches Inbox, Request [23], Team [5], Enterprise wall, Smart search, Shortcuts, Dashboard, Studio, the `rail_` / `menu_Team__` / `myspace_` captures and `globalAction` — 122 rows added (38 / 40 / 40 / 4), 7 earlier statuses changed (HP-8j, check-in approval, team uploads), file total 632 (241 / 230 / 139 / 22); new packages RQ-14 … RQ-16.

**Built (2026-10-30).** RQ-1 (request registry of 26 types, "New request" with search, favourites and "Raise for someone",
"My requests" with status tabs, type filter, date ranges, search, hide closed and a hide flag, a manager following a report's
requests, every type a Quick action, My work "waiting on others" from the registry, the open-requests panel inside the
time-office family's forms, HR bulk cancel of leave and claims): the rows it closes are marked "→ built (RQ-1)" (rows only
partly closed say which part and which package finishes them); see `docs/architecture/hrone-parity.md` "RQ-1". RQ-2 (request
chrome: whose request, "Who approves this" as a read-only dry run, "Read the policy", follow-ups after submitting, the
requester's request page with its trail, "Send reminder", "Notify others" (off until switched on per type) and the discussion,
and "Workflow and policy" on the approver's card): rows marked "→ built (RQ-2)"; see `docs/architecture/hrone-parity.md` "RQ-2". RQ-8 (unified inbox: Approval Center items and the person's own tasks in one list by request type with counts and a side
search, views and quick filters, a filter panel fitted to the request type, keyword search, due labels, the item beside the
list with previous / next and a focus mode, tick-and-archive, "Not relevant" for system tasks behind `task.ignore`, "More",
a profile peek, the approver's Insight; the leave-cancellation item's reason and balance after cancelling): rows marked
"→ built (RQ-8)"; see `docs/architecture/hrone-parity.md` "RQ-8".
The counts below are the analysis-time counts.

**Status key.** HAVE: an employee, manager or HR person can do the same today. PARTIAL: something exists but does not
cover the HROne element. MISSING: nothing equivalent. N-A: UI chrome, vendor marketing, or recruitment.
*(inferred)* marks a meaning taken from a control name because HROne's label or behaviour was not captured.

**Conventions used in the build notes** (from PeopleOS docs and code): additive changes whose defaults reproduce
today's behaviour; per-group options as fields of a Policy Engine type (`peopleos.policies.types`, applicability by
`policy_assignment_rules`); organisation switches as typed settings listed in `config/peopleos_setup.php`; new models
tenant-scoped (`BelongsToTenant` + `Auditable`), classified in `DataInventory`, guarded by a policy; Approval Center items
through a tagged `ApprovalSource` (`peopleos.approval_sources`); payroll money through `PayrollInputSource`; wiring in
`ParityServiceProvider`; permissions declared in `config/peopleos.php` then `php artisan peopleos:sync-permissions`;
statutory values never hard-coded; every self-service path first calls `SelfServiceLock::assertOpen`.

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | Relevance |
|---|---|---|
| Self-service request entry points, one per module: header actions on each resource list ("Request a loan or advance", "Claim a reimbursement", "Request variable pay", "Request an advance", "Add expense", "New expense report", "Request an asset", travel "New trip", time-office "Request", "Request encashment"), My day ("Apply leave", "Regularise attendance", "Resign"), My HR ("Ask HR", "Request" a catalogue service), Declare investments wizard | `app/Filament/Resources/*/Pages/List*.php`, `MyDay.php`, `MyHr.php`, `ExitActions::resign`, `DeclareInvestments.php` | Every HROne request form maps to one of these |
| Quick actions and Command Center: search across answers, actions, people, navigation, requests (tickets, letters), knowledge, documents, organisation, workflows & reports, services; modes all / actions / people; "pick" a person then an action (act on behalf); recent items | `QuickActions.php` (employee actions: request leave, fix my attendance, raise an HR request, browse services, payslip, documents, bank details), `CommandSearch.php`, `app/Livewire/Experience/CommandCenter.php` | HROne global search and the Request flyout search |
| Approval Center: one queue of decisions grouped Urgent / This week / Later, built-in kinds (workflow step, leave, leave cancellation, regularisation, compensation change, letter) plus sources (loans, variable pay, reimbursement claims, loan guarantees; time office, time-office cancellation, location requests, leave donation, leave encashment; expense report, exceptional expense, advance, advance cancellation, travel, travel part, travel cancellation; asset request / return; goal plan, PIP, PIP evaluation; transfer); bulk approval, discussion, send back, archive, delegation (P3, P4) | `Approvals.php`, `ApprovalCenter.php`, `ApprovalDecisions`, `app/Domain/Experience/Sources/*` | HROne Inbox |
| Task inbox: workflow tasks (Waiting for me, Onboarding tasks, Handled by me, Sent back to me, Archived, All open tasks), actions approve / reject / mark done / send back / reassign / discussion / archive | `TaskInbox.php`, `TaskActions.php` | HROne Inbox task cards that are not domain approvals |
| My work: Decisions, Tasks, Follow-ups, Waiting on others (own pending leave, regularisations, tickets, letters, compensation proposals), Done | `MyWork.php`, `WorkInbox.php` | HROne "Initiated requests" / request tracking |
| Employee tasks (My HR → Tasks): workflow tasks, service desk, policy acknowledgements, communications, asset acknowledgements, one-on-one feedback, feedback requests (exit interview, confirmation, probation, induction), surveys | `config/peopleos.php` → `experience.task_sources`, `ExperienceTasks.php` | HROne inbox "acknowledge" and "fill in" cards |
| Out of office (delegation), inbox archive (manual / auto), employee request lock | `OutOfOffice.php`, `TaskDelegations`, `InboxArchives`, `SelfServiceLock` (P4) | HROne Delegate, Archive inbox, "ESS is locked" |
| Team pages for managers: My team (today's headcount, present / on leave / absent, needs attention), Team HR requests, Team positions (read-only seats), Team career, Team learning, Leave calendar | `MyTeam.php`, `TeamRequests.php`, `TeamWorkforce.php`, `TeamCareer.php`, `TeamLearning.php`, `LeaveCalendar.php` | HROne Team flyout |
| Manager role template: no `analytics.*` permission, so managers have no report library | `config/peopleos.php` → roles → `manager` | HROne Team reports |

---

# 20.1 Request

## 20.1.0 Rail "Request" flyout (`app-sidebar` "New Request"; not crawled as a page)

Items read from the flyout text on every request page: Asset, Attendance regularization, Change goal, Change shift,
Confirm invest, Expense, Expense advance, Helpdesk ticket, Job requisition, Leave, Leave encashment, Loan, On duty,
Propose invest, Reimbursement, Resignation, Restricted holiday, Short leave, Travel, Variable (20 types).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "New Request" list (20 request types, alphabetical) | One launcher for every request the person may raise; each opens its form (route or flyer) | No single launcher. Entry points are spread over module lists (header actions), My day (leave, regularise, resign), My HR (Ask HR, service catalogue), Quick actions (only leave, regularise, HR request, services, bank details) | PARTIAL → built (RQ-1: request registry of 26 types; "New request" page in the My work rail, grouped by module, only what the person can raise now) | RQ-1: a request-type registry (tagged `peopleos.request_types`: key, label, icon, module, permission, availability check through the module's policy, form action) and a "New request" page / rail flyout listing what the viewer may raise now |
| 2 | Flyout search (`placeholder: Search`) | Filters the request types by name | Command Center "actions" mode matches Quick actions only (no loan, claim, variable, travel, advance, asset, RH, short leave, on duty, shift, encashment, investments) | PARTIAL → built (RQ-1: search on "New request"; every type is a Quick action, so the Command Center finds it) | RQ-1: register every request type as a Quick action from the registry so search finds them |
| 3 | "Favorites" / "My space" / "View all" entries in the flyout | Pin frequent requests; jump to My space; open the full list | `ExperiencePreferences` keeps recent items, pinned people and favourite reports; no favourite requests | MISSING → built (RQ-1: favourites (at most 12) shown first on "New request"; "View all" is the page itself; the My space jump → built (MS-1: "My space" in the avatar menu and the Me group)) | RQ-1: `favourite_requests` in `ExperiencePreferences` (same cap as pinned people) shown first in the launcher |
| 4 | Tab "Pending" (feature inventory) | Own requests waiting for approval, all types | Each module list shows own records with a status filter; My work "Waiting on others" lists only own pending leave, regularisations, tickets, letters and compensation proposals | PARTIAL → built (RQ-1: "My requests" Pending tab over every type, cancellations waiting included) | RQ-1: "My requests" read model over the registry (each type supplies an own-records query and a status map to Pending / Approved / Rejected / Withdrawn / Draft) |
| 5 | Tab "Approved" | Own approved requests | Per-module lists only | PARTIAL → built (RQ-1: "My requests" Approved tab) | RQ-1 |
| 6 | Tab "Rejected" | Own rejected requests | Per-module lists only | PARTIAL → built (RQ-1: "My requests" Rejected tab) | RQ-1 |
| 7 | Tab "Undo" | Requests the employee withdrew (HROne status "Undo") | Withdraw / cancel exists per type (leave cancel and cancellation request, time-office cancel / cancellation request, regularisation cancel, loan / claim / variable cancel, encashment withdraw, advance / trip / asset request cancel, ticket cancel); no cross-type list | PARTIAL → built (RQ-1: "My requests" Withdrawn tab: withdrawn or cancelled requests) | RQ-1 maps cancelled-by-requester to "Withdrawn" |
| 8 | Tab "Drafts" | Saved, unsubmitted requests | Drafts exist for the tax declaration, expense reports and service requests (`ServiceRequests::submitDraft`); other requests are submit-only | PARTIAL → built (RQ-1: "My requests" Drafts tab: expense reports in draft or sent back, expenses not yet in a report, tax declarations in draft, saved helpdesk drafts; no new draft states) | RQ-1 lists the drafts that exist; no new draft states (additive) |
| 9 | Flyout item "Job requisition" | Raise a job requisition | — | N-A | Recruitment (separate RMS) |

## 20.1.1 Shared request chrome (every `/app/request/...` page)

Components: `app-request-header-detail`, `app-view-request-workflow`, `app-policy-view-flyer`, `app-dialog-on-request`,
`app-request-workflow`, `app-chat-firebase`, `app-hrone-document-viewer`, `app-view-pending-request`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Request header: avatar, name (code), company, designation | Shows whose request it is (the employee, or the one picked "on behalf") | Filament action modals have no header; on-behalf forms show the picked employee in a select | PARTIAL → built (RQ-2: `RequestChrome::header`: initials, name (code), designation · company on every wired request form; "You are raising this request for …" on an on-behalf form, following its employee picker) | RQ-2: a shared request header component (photo, name, code, company, designation) on every request form |
| 2 | "View workflow" → REQUEST WORKFLOW "Approver list –", "Intimation", "NO WORKFLOW MAPPED" | Before submitting, shows who will approve at each level and who is informed | Workflow definitions and `ApproverResolver` exist; nothing previews the route for a request not yet submitted | MISSING → built (RQ-2: "Who approves this" in the forms: `RequestWorkflows::preview` (a dry run: first match by priority, start conditions against the employee, approval steps resolved by `ApproverResolver` on an unsaved run) else the module's own approvers; intimation from the notification rules as kinds of people) | RQ-2: `RequestWorkflows::preview($type, $employee)` = the workflow that would start (P5 first-match / priority) and its approval nodes resolved through `ApproverResolver`, else the module's built-in approver (manager / role); intimation = notification-rule audience |
| 3 | "View HR Handbook" (`app-my-policies` flyer) | Opens the HR handbook / policy relevant to the request | HR handbook and policies exist (HP-9a, knowledge base, My HR → Policies); no link from request forms | PARTIAL → built (RQ-2: "Read the policy": up to three published knowledge-base articles of the type's category the person may read, in the form, on the request page and on the approver's card) | RQ-2: each registered request type names a knowledge-base category (`kb.categories`: leave, attendance, travel, expense, payroll …); the form shows "Read the policy" for the viewer's visible articles of that category |
| 4 | "Attachment" button / document viewer (preview PDF, download, remove; notes on formats that are only uploaded, not parsed) | Attach and preview supporting files | File uploads on most forms (leave, time office, regularisation, claims, advances, expenses, travel); no in-form PDF preview | PARTIAL | Per-form rows below say where an attachment is missing |
| 5 | SUBMIT → dialog "Confirmed! / Great!" with "New request", "View pending request", "Close" | Confirms the request and offers next steps | Success toast ("… sent for approval") | PARTIAL → built (RQ-2: the module's success message gains "Follow this request", "Notify others" (when on), "Raise another" and "View my requests" on one's own request) | RQ-2: confirmation panel with "Raise another", "View my requests" (RQ-1 list filtered to the type) |
| 6 | Dialog "Notify Others": search the employee(s), "Write a message" (max 500 characters), "Intimation message" | The requester informs chosen colleagues of the request (e.g. team about leave) | Notification rules inform fixed audiences; the requester cannot add people | MISSING → built (RQ-2: "Notify others" on the request page: `request.intimated`, 1–20 colleagues the requester may find, message ≤ 500, per-type setting `requests.notify_others.types` (empty = off), audited without the text) | RQ-2: `request.intimated` event with the chosen user ids and message (≤ 500), per type switch `requests.notify_others.types` (empty = off, today's behaviour) |
| 7 | "View pending request" (`app-view-pending-request`): tabs "Applied request" / "Pending request", columns Date, Request type, Remarks; "NO PENDING REQUESTS" | Shows the employee's other open requests of that family while filling a form | Not on forms; own lists exist per module | MISSING → built (RQ-1: "Your open requests" panel in the leave, regularisation and time-office forms, for one's own form only) | RQ-1 panel reused inside forms (time-office family: leave, AR, OD, short leave, RH, shift) |
| 8 | Workflow tracker after submit (`app-request-workflow`): "Your request workflow", per level "Responded by … on … at …", "TAT", "Pending…", "Missed to respond", "Request is approved / rejected / undo", "Intimation", "View Form", "View Details" | The requester follows each approval level, its turnaround and outcome | Requests run by a workflow: the run viewer (`WorkflowInstanceResource`) shows steps and log; domain requests show status and reviewer only; Approvals "Completed" shows the approver's own decisions | PARTIAL → built (RQ-2: request page ("Follow" in My requests): submitted, each decision / send back / move / automatic decision from the audit trail and `workflow_actions`, waiting with whom and due; never an approver's decision note) | RQ-2: request timeline for the requester (submitted, each decision or send-back from audit / `workflow_logs`, waiting with whom, due) on every registered type |
| 9 | "Send Reminder" | The requester nudges the pending approver | Escalation by SLA only; no requester reminder | MISSING → built (RQ-2: "Send reminder": `request.reminder` to the people it waits with, once per `requests.reminder.min_hours` (default 24) per request, audited) | RQ-2: `request.reminder` notification to the current approver(s), at most once per 24 h per request (setting `requests.reminder.min_hours`, default 24) |
| 10 | "Add approver": "Select the level after which you wish to add the new approver", "Choose the employee to be added", Comments, "New approver confirmation" | Insert an extra approver into a running workflow | `WorkflowEngine::reassign` replaces the assignee of a pending task; no insertion of a level | MISSING | RQ-9: `WorkflowEngine::addApprover($instance, $afterNode, $userId, $comment)` creating an ad-hoc approval node for that run only; permission `task.add_approver`; audited |
| 11 | "Move to archive" (in the tracker) | The requester hides a closed request from their list *(inferred)* | Archive exists for approvers' queues (P4), not for one's own requests | MISSING → built (RQ-1: "Hide" on a closed request in My requests, per person, with "Show hidden"; nothing changes on the request) | RQ-1: per-person hide flag on "My requests" (no data change) |
| 12 | Discussion (`app-chat-firebase`): "Collaborate with approvers on this request", "Start a discussion…", disclaimer "All the approvers of the workflow will be able to view the discussions on this request" | Thread between requester and approvers | HAVE for listed types (P3 `TaskComments`, `RequestDiscussion`; readers = requester, approvers, earlier participants) but enabled only for `inbox.discussion.types` and reached from the approval card, the notification or "Sent back to me" | PARTIAL → built (RQ-2: "Discussion" on the request page: the existing `TaskComments` thread, while `inbox.discussion.types` lists its kind) | RQ-2: "Discussion" on the requester's request timeline (same `TaskComments`) |
| 13 | "Workflow details are not available as the request was imported" | Imported requests carry no workflow | Imported records have no run | HAVE | — |
| 14 | "ESS is locked" (sidebar) | Requests refused while the employee request lock applies | `SelfServiceLock::assertOpen` in every self-service path (P4) | HAVE | — |
| 15 | Request on behalf (Team actions "GO TO FORM", HR Desk "Request on behalf") | A manager or HR raises a request for an employee | On behalf exists for leave, time office, expense, asset, travel, loan (payroll.manage), variable pay (payroll.manage or leave.approve), Ask HR (agents); Command Center "pick a person then an action" | PARTIAL → built (RQ-1: "Raise for someone" on New request lists each type's on-behalf form the person may open; the form asks whom; per-type gaps stay with matrices 23–26 and RQ-16) | RQ-1: registry flag `on_behalf` + permission per type; one "Raise for…" path (pick employee → form) |

## 20.1.2 Initiate separation — Resignation (flyout "Resignation"; `app-resignation-request-flyer` → `app-request-separation`)

No route in `routes.json` (the brief's `initiate-separation`); fields from the component's form controls.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Verify Details" step: "Please verify your profile details for future communication" | Before resigning, the employee confirms how to reach them after exit | No such step in "Resign" (`ExitActions::resign`); contact changes go through profile change requests | MISSING | RQ-6: optional first step (setting `exit.resign.verify_contact`, default off) prefilled from the person record; changes saved through the profile-change service so HR verification still applies |
| 2 | Personal email id · `personalEmail` | Post-exit email | Person record holds personal email; not on the form | MISSING | RQ-6 row 1 |
| 3 | Mobile number · `internationalCode` (ISD) + `mobileNo` | Post-exit phone | Person record; not on the form | MISSING | RQ-6 row 1 |
| 4 | Present residence: Current Address · `addressCurrent`, country `countryCodeCurrent`, state `stateCodeCurrent`, city `cityCodeCurrent`, Current Pin `zipCodeCurrent` | Current address | Addresses on the person record; not on the form | MISSING | RQ-6 row 1 |
| 5 | Permanent residence: "Same as present", Permanent Address · `addressPermanent`, country, state, city, Permanent Pin | Permanent address | As row 4 | MISSING | RQ-6 row 1 |
| 6 | Resignation Request Date · `separationRequestDate` | Date the resignation is given; may be backdated within the policy's days | `Exits::resign($resignedOn)` honours the exit policy's `employee_backdate_days`, but the form never asks (always today) | PARTIAL | RQ-6: show the date picker when the employee's exit policy allows backdating |
| 7 | Notice period · `noticePeriod` | Notice days from the separation policy (probation / confirmed) | `Exits::noticeDays()` reads the exit policy (`notice_period_days`, `notice_days_probation`); the confirmation text shows the global `exit.notice_days` instead | PARTIAL | RQ-6: show `noticeDays($employee)` (fixes the mismatch) |
| 8 | Last Working Date (LWD) · `lastWorkingDate` + "Calendar day –" | LWD as per policy (resignation date + notice), shown with its weekday | LWD computed in `Exits::resign`; not shown before submitting | PARTIAL | RQ-6: live "Last day as per policy" in the form |
| 9 | Proposed LWD · `proposedLWD` | The employee asks for another last day | "Requested last working day" (only earlier than the policy LWD is honoured) | HAVE | — |
| 10 | Disclaimer "LWD auto-adjusted due to holiday or week off" | A last day falling on a holiday / weekly off moves to the working day before *(inferred)* | No adjustment | MISSING | RQ-6: exit policy field `lwd_on_non_working_day` (keep / previous working day / next working day; blank = keep, today's behaviour) using `HolidayResolver` and the work schedule |
| 11 | Notice recovery days + "Recovery amount applicable if notice period not completed" | Shows the notice shortfall that will be recovered | Shortfall is computed at full and final (`FinalSettlements`); not shown when resigning | PARTIAL | RQ-6: show shortfall days when the proposed LWD is earlier than the policy LWD |
| 12 | Reason · `reason` (select, placeholder "Reason") | Separation reason from the master | "Reason" select from separation reasons offered to employees (CA-3, `WorkforceReason` kind separation, `for_employee`) | HAVE | — |
| 13 | Comment · `remarks` | Free text | "Reason for leaving" (required) | HAVE | — |
| 14 | Attachment (document viewer) | Resignation letter | No attachment | MISSING | RQ-6: optional file on the exit case (documents disk, `DocumentUploadHint`) |
| 15 | "NO INFORMATION!" / confirmation dialog / Notify Others | Post-submit dialog (see 20.1.1 rows 5–6) | Toast | PARTIAL → built (RQ-2: confirmation follow-ups and Notify others on one's resignation) | RQ-2 |
| 16 | "View workflow" / "View HR Handbook" | See 20.1.1 rows 2–3 | `ResignationWorkflowBridge` decides when a workflow runs on "Exit case (resignation)" | PARTIAL → built (RQ-2: "Who approves this" (incl. a resignation workflow) and "Read the policy" in Resign) | RQ-2 |

## 20.1.3 Loan (`/app/request/initiate-loan`; `app-loan-request-page` → `app-loan-request`, `app-view-loan-detail`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Loan type · `payCode` (select, placeholder "Loan type") | Loan types are pay components of type loan | Type from `config('peopleos.loans.types')`, narrowed by the loan policy's `allowed_types` | HAVE | — |
| 2 | Loan amount · `loanAmount` ("Loan Amount should be greater than 0") | Principal | "Amount" (min 1; policy max, `allow_exception`) | HAVE | — |
| 3 | No of EMI · `equatedMonthlyInstallments` ("No. of EMI should be greater than 0") | Instalments | "Repay in (months)" (1–120; policy `max_installments`) | HAVE | — |
| 4 | Loan required date · `requireDate` ("You cannot apply loan for past date.") | When the money is needed | No needed-by date; "Recovery from (YYYY-MM)" instead | MISSING | RQ-5: `loans.required_on` (nullable, not in the past), shown to the approver; recovery month stays as is |
| 5 | Comments · `remarks` ("You can write your reason for this request here") | Reason | "Reason" (required) | HAVE | — |
| 6 | Attachment | Supporting document | None on loans | MISSING | RQ-5: `loans.attachments` JSON (same disk and limits as claims) |
| 7 | "Map the guarantor" · `guarantorEmployeeId` ("Not allowed to map loan guarantor as yourself"; search by name / code / designation / email) | Name one or more guarantors | "Guarantors" (multiple; policy `guarantor_required`, `guarantors_required`, never oneself, `max_guarantees_per_guarantor`, `guarantor_must_accept` → guarantor consent task) | HAVE | — |
| 8 | "Download the loan guarantee related terms and conditions … from here" + "Upload the signed document" / Consent form | Guarantee terms to sign and upload | No terms document or signed consent | MISSING | RQ-5: loan policy field `guarantee_terms_document` (a document / letter template) offered for download; guarantor's acceptance may require an uploaded signed copy (policy `guarantee_signed_copy_required`, default no) |
| 9 | "Request is already pending" | One open request at a time | Policy `max_active` (open loans) | HAVE | — |
| 10 | "VIEW DETAILS" → existing loans: Loan amount, Total interest, Paid, To pay, Date of request, Date of dispense, Approved by, Consent form, Attachment | The employee sees current loans before asking | Loans list (own) with principal, monthly, instalments, outstanding, status; schedule on the loan view | PARTIAL | RQ-5: "Your open loans" summary inside the request form |
| 11 | Activity log (`app-loan-activity-log`) | History of the loan | Audit trail (not shown to the employee) and schedule | PARTIAL → built (RQ-2: the loan's trail on its request page) | RQ-2 request timeline |
| 12 | Loan detail adjustments (HR): "Recover complete loan", "Skip EMI for this month", "Restructure loan" (new amount, new no. of EMIs, loan end date), "Reduce this month's EMI", "Settle loan out of system", Adjust / CANCEL | Post-approval loan servicing | Prepay (instalments paid now), mark paid out, cancel; BP-9 approver may edit amount / instalments / first month / interest | PARTIAL | Belongs to the payroll matrix (08-workforce-payroll BP-9); listed for completeness |
| 13 | Approver form (`app-loan-detail`): approve amount, EMIs, interest type, interest rate, first month of deduction, auto loan scheme, IGNORED / DISPENSE / APPROVE / IGNORE / REJECT, comment, existing loans (count, outstanding) | Approver decides and may change terms | Approve with optional edits (policy `approver_may_edit`, `interest_editable`), reject, mark paid out | PARTIAL | Interest type and "loan scheme" are payroll-matrix items; "Ignore" see 20.2.1 row 27 |

## 20.1.4 Reimbursement (`/app/request/initiate-reimbursement`; `app-reimbursement-request-page`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Select component · `payCode` (required) | Reimbursement pay component | "Reimbursement" (components of type reimbursement paid on claim), helper "Available to claim" | HAVE | — |
| 2 | "Paid till date" (read-only) | Amount already paid this financial year | Ledger exists (`reimbursement_ledger_entries`); the form shows only the balance | PARTIAL | RQ-5: show paid this FY next to "Available to claim" |
| 3 | Claim amount · `claimedAmount` | Amount claimed | "Amount" | HAVE | — |
| 4 | Submitted bill amount · `billAmount` | Total of the bills | Bills repeater (number, date, vendor, amount); setting `payroll.claims.not_above_bills` | HAVE | — |
| 5 | "Bills already submitted" (read-only) | Bills submitted earlier this FY | Not shown | MISSING | RQ-5: sum of earlier bills this FY in the helper |
| 6 | Comments · `remarks` (required) | Description | "Description" (required) | HAVE | — |
| 7 | Attachment | Bill images | "Bill images or PDFs" (multiple) | HAVE | — |
| 8 | Leave Period / Leave type / "Select leave" · `leaveRequestIdentity`; "Claim already …" | Link an LTA-type claim to an approved leave | No link from a claim to a leave | MISSING | RQ-5: salary component flag `claim_needs_leave` (default off); then the claim picks one of the employee's approved leaves (not already claimed) |
| 9 | "ADD ANOTHER REQUEST" (`formArrayName: reimbursementRequest`) | Several components in one submission | One component per claim | MISSING | RQ-5: repeater of component rows submitted together (one claim per row, same transaction) |
| 10 | "View Detail" table: Total Claimed, Total Paid, Total Bill, Taxable | FY summary per component | Not shown | MISSING | RQ-5 summary from the ledger (taxable = paid without bills under the regime rule already used by payroll) |
| 11 | "Are you sure you want to submit?" / CANCEL / DONE | Confirmation | Filament action submit | HAVE | — |
| 12 | Cancellation remark / Activity log | Withdraw with remark; history | "Cancel" with reason; audit | PARTIAL → built (RQ-2: the claim's trail on its request page; the cancellation reason stays on the claim) | RQ-2 timeline |

## 20.1.5 Proposed investment declaration (`/app/request/proposedinvestment`) and 20.1.6 Confirmed investment declaration (`/app/request/confirmedinvestment`)

Both pages use `app-initiate-invest-dec-form` (steps `app-iid-form-step-one` … `five`); the page showed steps
"Chapter VI A – Others", "Income from other", "Summary" (the steps follow the regime and the sections switched on).
Rows apply to both unless the row says otherwise.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Add Investment": "Please select how you wish to proceed" — "Use last approved request" / "Make a new request" → PROCEED | Start from the last approved declaration or blank | The wizard opens the current year's declaration (draft or submitted); no copy of last year's | MISSING | RQ-7: "Start from last year's declaration" copying items (amounts editable) into a new draft |
| 2 | Note "This investment will be considered from <month>" | From which payroll month the declaration counts | TDS uses the latest verified / submitted declaration from the next payroll run; not stated | PARTIAL | RQ-7: show the first payroll month the declaration will affect |
| 3 | "Show new tax terminology" (toggle) | Show section names in the new Income-tax Act wording *(inferred)* | Section codes from `InvestmentCatalogue` (old wording) | MISSING | RQ-7: a label map per tax rule version (statutory data, not hard-coded) |
| 4 | Tax type / "Change tax regime" dialog: "Please select your preferred tax regime" New / Old, Confirm / Cancel | Choose the regime | Start step "regime" with explanations | HAVE | — |
| 5 | Tax regime change request (approver view: "The user has requested to change the tax regime from … to …") | A regime change after the first choice goes for approval | Regime may change freely until payroll verifies | PARTIAL | RQ-7: setting `tax.regime_change_needs_approval` (default off) |
| 6 | Step HRA: "Declare HRA", "Add rent", rent rows · `rentList`: From date, To date, Monthly rent (`rentPaid`), City (`city` / `cityName`) | Rent per period (several periods and cities a year) | One "Rent per month" × "Months you rent", metro toggle | PARTIAL | RQ-7: rent periods repeater (from, to, monthly rent, city with metro flag from statutory data) |
| 7 | Landlord: Landlord's name, Landlord's PAN, Landlord's address, "Specify relationship" (`relationShipFirst`, "Relationship to tenant"), PAN not available (`isPanNotAbvl`) | Landlord details | Landlord name, PAN (format check; required by `landlordPanRequired()` or over ₹1 lakh), address, other landlords (`maxLandlords`) | PARTIAL | RQ-7: relationship to tenant; "PAN not available" declaration |
| 8 | Landlord 2 (`landlordNameSecond`, `landlordPanSecond`, `relationShipSecond`) + "Add landlord" / "Remove landlord" | Second landlord | "Other landlords of this home" repeater | HAVE | — |
| 9 | "Verify landlord's PAN" (Verify / Retry / "Out of credits" / "PAN verified successfully!") | PAN check against the tax database (paid credits) | Format check only | MISSING | External (PAN verification provider); seam only — blocked on vendor (as CA-15) |
| 10 | "Please download the declaration form and upload it" / Upload file / rent remarks (`rentRemarks`) | Signed rent declaration | Proof upload per item later (My investment proofs) | PARTIAL | RQ-7: optional declaration template download on the rent step |
| 11 | Steps two / three (80C, Chapter VI-A others): Amount, Remarks, UPLOAD DOC, "+", "System Amount" ("Not a valid amount") | Declared amount per sub-section, with the amount payroll already counts (e.g. PF, employer NPS 80CCD(2)) | One step per section with every option, amount, proof note; review totals each section against what payroll counts | HAVE | — |
| 12 | 80D style "Parents contribution" / "Self contribution", max limit for self / parent (`maxLimitForSelf`, `maxLimitForParent`) | Split health insurance between self and parents with their own limits | Catalogue items per 80D option | PARTIAL | RQ-7: self / parents split with limits from the tax rule data |
| 13 | Step "Income from other" (`app-iid-form-step-four`): Select income from other sources; Lenders information (Lender name, Lender PAN, Owner name, Lender address, Possession date) · `lenders`; Total TDS deducted / collected | Other income and house-property (home loan) lender details | Other income items; home-loan interest (24B) amounts; no lender details | PARTIAL | RQ-7: lender repeater on 24B (name, PAN, owner, address, possession date) |
| 14 | Summary: MAXIMUM AVAILABLE, TOTAL DEDUCTION, AVAILED, TOTAL AMOUNT; declaration "I hereby confirm that I will be investing / contributing …" | Totals and undertaking | Review step with totals per section and warnings | PARTIAL | RQ-7: undertaking checkbox (text a tenant setting) |
| 15 | Tax Estimator: Old / New regime, Net taxable income, Net annual TDS, TDS to be deducted, Current month TDS, Breakup (A annual gross … P TDS this month), "TDS Estimation Slip", Recalculate; "Salary processing for this fiscal year is already closed …" | Projected tax under both regimes | `TaxEstimator::compare` on the review step when `showEstimator()` | PARTIAL | RQ-7: full breakup lines and a printable estimation slip |
| 16 | "No Access — The investment declaration window is currently not open" | Window closed | "The declaration window is closed" callout (`windowOpenFor`) | HAVE | — |
| 17 | SAVE AS DRAFT / NEXT / Previous | Draft and steps | Save as draft, wizard steps, submit | HAVE | — |
| 18 | Overall Remarks ("Please enter the remarks for your edits in the investment declaration") | HR edits need a remark | payroll.manage files for anyone (`fillFor`); audit reason | PARTIAL | RQ-7: required remark when someone else edits |
| 19 | Confirmed investment: separate request (workflow 80) re-declaring actual amounts with proofs | Year-end actuals with proof | My investment proofs: per item proof files, "Amount the proof shows", status, accepted amount, payroll note | HAVE | — |
| 20 | Approver review (`app-investment-dec-review`): Edit, ADD INVESTMENT, Activity log, "Download proofs" | Payroll reviews | TaxDeclarations verify / return (`TaxDeclarationWorkflowBridge`), proofs per item | PARTIAL | Bulk "download proofs" for one declaration (RQ-7) |

## 20.1.7 Restricted holiday (`/app/request/initiate-restricted-holiday`; `app-restricted-holiday-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Holiday type · `holidayId` (select) | Pick one of the restricted holidays of the employee's calendar | Time-office request "Restricted holiday" → "Optional holiday" select (`TimeOfficeActions::optionalHolidays`, calendars with `optional_requires_selection`) | HAVE | — |
| 2 | "No. of restricted holidays already availed : N" | Count used this year | Quota enforced (`assertRestrictedHolidayQuota`, `restricted_holiday_quota`, `rh_prorate_rules`); not shown | PARTIAL | RQ-7: helper text "Taken N of M this year" from the same quota read |
| 3 | "No. of restricted holidays eligible for : N" | Quota after proration | As row 2 | PARTIAL | RQ-7 (same helper) |
| 4 | Comments · `remarks` | Reason | "Reason" (required) | HAVE | — |
| 5 | Approver view: Restricted holiday, Availed, Eligible, Comments, approver remarks | Approver sees the quota | Approval Center "time_office" item (reason, facts); quota not in the facts | PARTIAL | RQ-7: add availed / eligible to the item's facts |

## 20.1.8 Attendance regularization (`/app/request/initiate-attendance-regularization`; `app-attendance-regularization-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Month calendar (Su–Sa, month / year switch) "Select day(s) for which you wish to apply"; legend Today, Absent, Half day absent; "Dates marked 'Absent': …" | Pick one or more days straight from the attendance calendar | Single "Date" picker in "Regularise attendance" (My day, Home); Attendance calendar is read-only | PARTIAL | RQ-3: calendar day picker (multi-select) reusing `AttendanceCalendar` day states |
| 2 | Per selected date row (`requestDetails`): type · `attendanceRegularizationType` — Forgot In / Forgot Out / Both | What is being corrected | "Type" (missed punch, late coming, early leaving, work from home, on duty, field duty, marked absent by mistake) | HAVE | Different list, equivalent purpose |
| 3 | In date · `inDate` / Out date · `outDate` (From date / To date) | Punch dates (out may be the next day for night shifts) | "Actual in" / "Actual out" times on the same date | PARTIAL | RQ-3: out date for shifts crossing midnight |
| 4 | Start Hours / Start Minutes / End hours / End Minutes (`inTimeHours`, `inTimeMintues`, `outTimeHours`, `outTimeMintues`) | Corrected times | Time pickers | HAVE | — |
| 5 | Work mode · `workplaceId`, Select city · `cityCode`, Select office · `officeId` | Where the employee worked that day | Work modes exist (WorkModes resource); not on the regularisation | MISSING | RQ-3: optional work mode / location per date (setting `attendance.regularisation.ask_location`, default off) |
| 6 | Comments · `attendanceRemarks` (required) | Reason | "Reason" (required, 255) | HAVE | — |
| 7 | Attachment (required where the policy says, "*") | Proof | `AttendanceActions::attachmentsField()` | HAVE | — |
| 8 | "AR balance :" | Remaining regularisations this period | Period limit enforced (`RequestRules::assertPeriodLimit` 'ar'); not shown | PARTIAL | RQ-3: "N of M left this month" |
| 9 | "View Punches" (`app-emp-punches-details-flyer`: punch date / time / type In-Out, remarks, machine no., location, shift, working hours, breakup, overtime, comp-off, "Reprocess attendance") | See the day's punches before correcting | Day detail lives on attendance records / punches screens (HR); not reachable from the request | PARTIAL | RQ-3: day drawer with the employee's own punches and day result |
| 10 | "View pending request" | Open AR requests | See 20.1.1 row 7 | MISSING → built (RQ-1: open-requests panel in the regularisation form) | RQ-1 / RQ-3 |
| 11 | "You have applied for AR for following week offs / holidays / leave — Are you sure you want to continue?" (Date, Type; Cancel / Continue) | Warn before regularising a non-working or leave day | Backdated window, pending and payroll-lock checks; no warning | MISSING | RQ-3: soft warning listing such dates |
| 12 | "Please select AR date from calendar" | Validation | Required date | HAVE | — |
| 13 | Approver view: Request type, Requested check-in / check-out, Office, City, Work mode, Attachment, approver remarks (`approverRemarks`) | Decide | Approval Center "Attendance regularisation" (reason, times, approve / reject with note, send back) | HAVE | Location fields follow row 5 |

## 20.1.9 Short leave (`/app/request/initiate-short-leave`; `app-short-leave-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Month calendar "Select day for which you wish to apply for 'Short leave'"; legend Today / Absent / Half day absent | Pick the day on the calendar | Time-office request "Short leave" → "Date" picker | PARTIAL | RQ-3 picker |
| 2 | From date / To date (`inDate`, `outDate`) | Day(s) of the short leave | "Date" | HAVE | — |
| 3 | Start Hours / Minutes, End hours / minutes | Time window | "From time" / "To time" | HAVE | — |
| 4 | Select leave type · `applyLeaveId` | Leave to deduct when the short leave counts against leave *(inferred)* | Short-leave policy `over_limit = deduct_leave` with `deduct_leave_code` / `deduct_days` (automatic, not chosen) | PARTIAL | RQ-3: when the policy lets the employee choose, a leave-type select limited to the policy's list |
| 5 | "Short leave balance :" | Remaining short leaves this month | Limits `max_per_month`, `max_minutes_per_month` enforced; not shown | PARTIAL | RQ-3 "N of M left" |
| 6 | Comments · `remarks` | Reason | "Reason" | HAVE | — |
| 7 | "View Punches" | As 20.1.8 row 9 | — | PARTIAL | RQ-3 |
| 8 | Approver view: Start time, End time, Leave type, Comments, approver remarks | Decide | Approval Center "time_office" | HAVE | — |

## 20.1.10 Help desk ticket (`/app/request/initiate-help-desk-ticket`; `app-help-desk-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Category · `categoryId` | Ticket category (only those the helpdesk policy allows) | "Ask HR" → "What is it about?" (active categories filtered by the helpdesk access policy, HD-C) | HAVE | — |
| 2 | Subcategory · `subcategory` | Sub-category | Sub-categories are catalogue services (`service_definitions.subcategory`), raised through "Request" on My HR → Services; "Ask HR" has no sub-category | PARTIAL | RQ-7: "Ask HR" offers the category's services as a second select (opening the service form when it has fields) |
| 3 | Priority · `priorityId` (from `helpdesk/ticket/Priority`) | Priority | "Priority" (low / normal / high / urgent) | HAVE | — |
| 4 | Description · `remarks` | Problem text | "Subject" + "Description" | HAVE | — |
| 5 | Attachment · `attachedFileName` | File | Catalogue services take an attachment per version rule; "Ask HR" has none | PARTIAL | RQ-7: optional attachment on "Ask HR" |
| 6 | Text "Purpose" *(inferred: a purpose field shown for some categories)* | Extra field | Service versions carry their own form fields | HAVE | — |
| 7 | Approver / resolver view: Ticket id, Request date, Status, Priority, Category, Subcategory, Assigned to, Description, Due date, RCA, "Are you sure you want to close the ticket?", "Do you want to reopen this ticket?", YES CLOSE / Reopen | Work the ticket | Ticket view (status changes, assignment, SLA due, close with satisfaction, reopen); RCA per HD-B notes | PARTIAL | RCA belongs to the helpdesk matrix (11-expense-asset-helpdesk 15.x) |

## 20.1.11 Expense advance (`/app/request/initiate-advance-request`; `app-advance-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Project · `projectId` | Project the advance is for | No projects in PeopleOS | MISSING | Blocked: projects module (gap register; "project-based approval" blocked in 00-build-status) |
| 2 | Expense advance amount · `requestAmount` | Amount | "Amount" + "Currency" (policy max shown in the modal) | HAVE | — |
| 3 | Purpose · `remarks` ("You can write your reason for this request here") | Reason | "Purpose" (required when the policy says) | HAVE | — |
| 4 | Required Date · `requiredDate` | When needed | "Needed on" (not in the past) | HAVE | — |
| 5 | Attachment · `fileName` ("Please upload document") | Supporting document | "Supporting documents" (required when the policy says) | HAVE | — |
| 6 | "Please share the preferred mode of advance" · `advanceMode` (hint "I will like 200 USD in card and rest in Indian currency") | How the employee wants the money | Not asked | MISSING | RQ-7: `expense_advances.preferred_mode` text (≤ 500), optional; shown on dispense |
| 7 | "Request is already pending" | One open advance at a time *(inferred)* | Advance policy rules (`ExpenseAdvanceRules::refusal`) | HAVE | — |
| 8 | "view ledger" → Advance Outstanding ledger: Date, Opening outstanding balance, Outstanding balance (CR/DB), Closing outstanding balance, Reason | Employee sees outstanding advances | Advance entries (`ExpenseAdvanceEntry`, Entries relation manager on the advance) | PARTIAL | RQ-7: the employee's running ledger across advances inside the form |
| 9 | "View activity log" ("Dispense expense advance request of … as expense advance to …") | History | Audit + entries | PARTIAL → built (RQ-2: the advance's trail on its request page; dispense entries stay on the advance) | RQ-2 timeline |
| 10 | "You cannot enter amount greater than Estimated cost" (advance raised from a travel request) | Advance capped by the trip estimate | Trip advance on the travel form (`advance_amount`, trip currency) | HAVE | — |
| 11 | Approver / dispense view (`app-dis-adv-detail`): Project, Request amount, Approved amount, Required date, Outstanding amount, Purpose, dispense amount, remarks | Decide and pay out | Approval Center "Expense advance"; Dispense advances page | HAVE | — |

## 20.1.12 Expense (`/app/request/initiate-expense-receipt-request`; tabs Amount / Mileage; `app-expense-receipt`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs "Amount" / "Mileage" (expense type) | Amount-based vs distance-based expenses | Category decides amount vs mileage (`ExpenseReceiptForm`: amount fields or odometer / distance / from / to) | HAVE | — |
| 2 | "Drag and drop receipts to parse them" / "Parse Receipt" (10 MB, png / jpeg / jpg / pdf); "Parse receipt?" confirm ("Following fields would be overridden": expense date, description, distance, amount, invoice no.); "PARSING Error!" | OCR fills the receipt from the image | Upload only | MISSING | RQ-7 (seam): `ReceiptParser` contract with a null implementation; a provider is an owner decision (external) |
| 3 | "Add Expense" (`app-create-expense`): Expense date `receiptDate`, Category `expensePolicyCategoryAmountId` / `…KilometerId`, Currency, Amount `inputAmount`, Description, Invoice no. `invoiceNumber`, Merchant `merchantName`, City `cityCode` / From city, Purpose, Other participant, Hours / Minutes, Claim reimbursement `isReimbursable`, Pre approval code `preapprovalReferenceCode`, Declaration `isDeclarationRequired`, Tax type / Destination of supply / GSTIN / Tax name / Sac-hsn, custom fields `newfieldOne`…`newfieldTen` | Capture one receipt | Expense date, category, travel request link, currency, amount, odometer start / end, distance, from / to, hours worked, purpose, location city + state, merchant, invoice number, other participants, description, tax (supplier GSTIN, tax rows), receipts, exceptional reason, declaration; custom fields via the expense form setup; `reimbursable` on the model | PARTIAL | Missing on the form: "Claim reimbursement" toggle (model has `reimbursable`), pre-approval code, SAC / HSN; RQ-7 |
| 4 | Project · `projectId` | Project of the expense | — | MISSING | Blocked (projects) |
| 5 | Mileage: From location / To location "set location on map", "click to view map", "Invalid trip … manually edit the distance" | Map-measured distance | Typed from / to and distance; map distance blocked (provider) | PARTIAL | Blocked (map provider) |
| 6 | "Expense receipt limit : … KM / INR" | Shows the category limit | `ExpenseLimits::for` helper on the form | HAVE | — |
| 7 | "Do you want to copy previous expense data?" Yes / No; "How many copies do want to add?" | Duplicate a receipt N times | — | MISSING | RQ-7: "Clone" action on a receipt (n ≤ 30) |
| 8 | "How would you like to split this expense?" Same date / Date range; From / To date, No. of expense receipts, Amount per receipt ("not more than 30", "at least 2") → Split Expense | Split one bill into daily receipts | — | MISSING | RQ-7: "Split" action creating n receipts over a date range |
| 9 | "Add Bulk Expense" (`app-add-bulk-expense`): grid rows (S.No., Receipt, Category, date, amount, …), "Show mandatory fields only", "Add row", "Clone expense", "Delete expense", "Import expense": Download template / Upload file (.xls, .xlsm, ≤ 500 KB, ≤ 500 rows) | Many receipts at once, or from Excel | One receipt per form; expense imports are not in the import catalogue | MISSING | RQ-7: bulk grid; Excel import through the staged import pipeline (`ImportTypes`, P2 governance) |
| 10 | Receipt list (`app-expenses`): Receipt no., amount, Category, status, Date; Sort by; Filter (status, category, travel code, date); Select all; bulk Edit ("Select the fields you want to edit"), Delete, Clone, "Add to report" | Manage unreported receipts | Expense receipts list (own), status filter; add to report from the report screen | PARTIAL | RQ-7: bulk edit of selected receipts' common fields |
| 11 | "Receipts need review. N suspected and N rejected receipts pending review" | Duplicate-suspected receipts | `ExpenseDuplicateDetector` (`duplicate_score`) | HAVE | — |
| 12 | Report (`app-report`): Report name, Purpose, Report start / end date, Project, Travel code; "Policy violation"; totals (submitted, reimbursable, non-reimbursable); max 5 attachments × 10 MB; Save as draft / Submit | Group receipts into a report | Expense reports: new report, add expenses, submit, cover letter, cancel; policy checks; travel link | HAVE | Project blocked |
| 13 | Receipt view (`app-receipt-view-form`): tax details, similar receipts, all attachments, history, attendance of the day, trip details, payment details (status, mode, cheque no., paid) | Detail | Receipt view, duplicate score, payment on report | PARTIAL | Attendance-of-the-day panel: RQ-3 day drawer reused |

## 20.1.13 Job requisition (`/app/request/initiate-job-opening`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Raise a job requisition — Choose a preferred option": Create a new requisition / Clone from an existing job / Copy details from team member; View workflow | Requisition request | — | N-A | Recruitment (separate RMS) |

## 20.1.14 Change goal (`/app/request/initiate-change-goal`; `app-edit-goal`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "No approved goal available" / EDIT | Open the last approved goal sheet for change | Goals list; the employee's changes to a system-mapped sheet become proposals (`GoalPlans::propose`, owner decision 2026-10-29) | HAVE | — |
| 2 | Goal sheet rows (`app-goal-approval-view-form`): KRA name `keyResultAreaName`, KRA % weightage, group `groupId`, KPI name, KPI % weightage, KPI target, unit of measure, target due date; competencies (name, weightage); Attachment; Check-In; Period | Edit KRAs / KPIs / competencies | Goal form (KRA, weightage, target, measure, due date), competencies on performance templates, check-ins | HAVE | Field-level parity sits in 12-performance-engage |
| 3 | Feedback template mapping (`feedbackId`, "Please select the feedback template you want to map") | Attach a feedback form to the goal sheet | Feedback templates exist (P13); not mapped to goal sheets | MISSING | Performance matrix (HP-8i / HP-8h); listed only |
| 4 | Goal creation options: Create new goal, Assign last approved, Assign recommended, Generate with One AI, Assign from Bank or Create New | Ways to fill the sheet | "Use template" (goal templates, HP-8e); no "last approved", no AI generation | PARTIAL | Performance matrix; RQ-7 only notes the request path |
| 5 | "Copy goals of team member" (`app-copy-goal-from-team`: search by name, pick a team member, Copy Goals; "Any changes made to the goal sheet will be lost") | Reuse a colleague's sheet | — | MISSING | Performance matrix |
| 6 | Review rating formula flyer ("Achievement b/w", "Customize target", USE THIS) | Per-KPI rating formula | KPI rating formulas (HP-8c) | HAVE | — |
| 7 | View workflow / approval of the changed sheet | Manager approves the change | Goal plan approval in the Approval Center (`goal_plan`, policy `plan_approval`) | HAVE | — |

## 20.1.15 Variable (`/app/request/initiate-variable`; `app-variable-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Pay month · `salaryDate` ("In which month's payroll do you want to pay the variable?", list of open months) | Payroll month | "Pay in (YYYY-MM)" text (this month or later) | PARTIAL | RQ-5: select of open / future payroll months from `PayCycle` (25th–24th months respected) |
| 2 | Select employee * · `employeeVariableSalary[].employeeId` (several rows) | A manager raises variable pay for several reports in one request | One employee per request; "For an employee" for payroll.manage or leave.approve | PARTIAL | RQ-5: rows of (employee, component, amount) for the requester's reports, one request per row in one transaction |
| 3 | Pay component · `payCode` | Variable component | "Pay item" (earnings, variable-only once marked, PS-1) | HAVE | — |
| 4 | Pay variable · `payAmount` | Amount | "Amount" | HAVE | — |
| 5 | Comments · `remarks` | Reason | "Reason" (required) | HAVE | — |
| 6 | Attachment | Supporting file | — | MISSING | RQ-5: attachments JSON on `variable_pay_requests` |
| 7 | "Total eligibility in current Financial Year :-" / "Total variable paid in current Financial Year :-" | What the employee may still get | Variable pay plans (`VariablePayPlan` schedule); not shown on the request | MISSING | RQ-5: eligibility = the variable component's annual CTC amount (or the plan), paid = approved requests paid this FY |
| 8 | Activity log | History | Audit | PARTIAL → built (RQ-2: the variable pay request's trail on its request page) | RQ-2 |
| 9 | Approver: Employee name, Pay month, Pay variable (editable `payAmount`), Comments, Attachment, eligibility / paid totals, approver remarks | Approver may change the amount | Approve (note) / reject / cancel; amount not editable | PARTIAL | RQ-5: approver amount edit within the eligibility (policy switch, default off) |

## 20.1.16 Travel (`/app/request/initiate-travel`; `app-travel-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Purpose of travel · `purposeOfTravel` | Trip purpose | "Purpose" | HAVE | — |
| 2 | From date / To date ("Please mention your overall travel duration") | Trip dates | "Starts on" / "Ends on" | HAVE | — |
| 3 | Reason for delay · `travelDelayReason` | Backdated trip | "Reason for the delay (the trip has already started)" | HAVE | — |
| 4 | Project · `projectId` | Project | — | MISSING | Blocked (projects) |
| 5 | Currency · `currencyCode` (INR default) / Estimated cost · `estimatedCost` | Trip estimate | Currency / Estimated cost (expense form settings decide shown / required) | HAVE | — |
| 6 | International travel · `isInternationalRequest`; Visa number; Passport no.; Passport Expiry Date | International details | International toggle, visa number, passport number | PARTIAL | RQ-7: passport expiry date (warn when it ends before the trip) |
| 7 | "Would you like to raise expense advance request?" Yes / No · `isTravelAdvanceAdded` (opens `app-advance-request` with "not greater than estimated cost") | Advance with the trip | "Advance needed for the trip" amount | HAVE | — |
| 8 | "ADD NEW TRAVEL DETAILS" → "Please choose the type of your travel": Travel / Accommodation | Add legs | "Journeys and stays" repeater, category decides journey or stay | HAVE | — |
| 9 | Travel leg: Mode of travel · `travelModeId`; trip type One way / Round trip / Multi city · `travelMode`; From · `fromCity`; To · `toCity`; "ADD ANOTHER CITY" | Journey | Mode, From, To (+ state); multi-city by adding legs; no one-way / round-trip choice | PARTIAL | RQ-7: trip type on the journey leg (round trip = return date required) |
| 10 | From / To near-by location · `travelFromNearbyLocation`, `travelToNearbyLocation` | Precise pick-up / drop *(inferred)* | — | MISSING | RQ-7: optional text fields on the leg |
| 11 | Departure date · `travelDepartureTime`, Preferred departure time · `travelPreDepartureTime`, Return date · `travelReturnTime`, Preferred return time · `travelPreReturnTime` | Times | Travel date, return, one "Preferred time (HH:MM)" | PARTIAL | RQ-7: preferred return time |
| 12 | "Do you want company to book ticket on your behalf?" · `ticketBookedByUiKey` | Who books | "Who books it" (I / the company) | HAVE | — |
| 13 | Accommodation: Accommodation type · `accomodationModeId`, Location / city · `cityCode`, Near by location, No. of guest · `noOfGuest`, Check-in / Preferred check-in time, Check-out / Preferred check-out time, "Do you want company to book accommodation on your behalf?" | Stay | Stay leg: category, city, state, check-in / check-out dates, preferred time, who books it | PARTIAL | RQ-7: guests and preferred check-out time |
| 14 | "Any special request" (e.g. seat preference, veg / non-veg, train / flight) · `remarks` | Preferences | "Notes" on the leg | HAVE | — |
| 15 | Travel accommodation preference · `travelAccommodationPreference` | Preference *(inferred)* | Notes | PARTIAL | RQ-7 (same notes or a select from expense form settings) |
| 16 | Attachment ("Max file size is 5MB", "Add Attachment") | Documents | "Attachment" (multiple) | HAVE | — |
| 17 | "Confirm travel request — Please review the details below before submitting" → Confirm submit; "Exceptional" flag | Review step | Submit from the trip; exceptional trips per policy | PARTIAL | RQ-7: review panel before submit |
| 18 | "Save and add another" / Save (leg) | Leg editor | Repeater add | HAVE | — |
| 19 | Approver (`app-travel-approval-details`) and booking (`app-book-travel-details`: booking status, category, booking date, booking amount, commission, invoice no., merchant, claim reimbursement, advance details) | Decide and book | Approval Center travel request / part (per workflow group, owner decision 2026-10-29); booking through the travel desk service request (`TravelBookings`) | PARTIAL | Booking amounts and commission belong to the expense matrix (EX-F) |

## 20.1.17 Asset (`/app/request/initiate-asset-request`; `app-product-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Request Type · `requestType`: New / Return / Both | Ask for new assets, return held ones, or both in one request | "Request for": New asset / Replacement / Return | PARTIAL | RQ-7: "Both" = return (one or more held assets) plus new lines in one request; replacement stays |
| 2 | New product details: Description · `requestDescription` | Why | "Reason" (required for return / replacement) | HAVE | — |
| 3 | Asset details rows · `requestProductList`: Product · `productId`, Asset type, Asset sub type | What is asked | Asset type, Asset sub type, Product (visibility per audience: `asset.request.subtype_visible_to`, `model_visible_to`) | HAVE | — |
| 4 | "+ ADD ANOTHER ASSET" | Several assets in one request | One asset per request | MISSING | RQ-7: request lines (`asset_request_lines`) with the current single fields as the first line |
| 5 | Period · `periodType`: Permanent / Temporary + Return date · `returnDate` | Temporary loan of an asset | — (assignment has `expected_return_on` set by the asset owner) | MISSING | RQ-7: period and return date on the line, copied to `expected_return_on` at fulfilment |
| 6 | Comment · `requestComment` | Note | Reason | HAVE | — |
| 7 | "Select product(s) you want to return" (asset cards: Code, Serial no., Product, Asset sub type, Period, Managed by, Assigned to, Return date; Return / Undo; SHOW MORE) | Pick held assets to return | "Asset" select of assets in custody (one) | PARTIAL | RQ-7: multi-select return lines |
| 8 | (PeopleOS only) "Needed by" date | — | `needed_by` | HAVE | Extra in PeopleOS |
| 9 | Approver (`app-asset-details`) and owner fulfilment (`app-asset-task-owner-details`: Requested, New, Return asset(s), View product details, ASSIGN, Return date, task for expiring assets) | Decide and fulfil | Approval Center `asset_request` / `asset_return_request`; fulfilment by the owner role (AS-B) | HAVE | — |

## 20.1.18 Request types reached only from the flyout (no `/app/request` route)

### Leave (`app-initiate-leave` → `app-leave-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Month calendar "Select day(s) for which you wish to apply for Leave request" (Today, Absent, Half day absent) | Pick days on the calendar | From / To date pickers (My day, Home, Leave requests) | PARTIAL | RQ-3 picker |
| 2 | Type of leaves · `leaveId` + "Leave balance :" (View more / View less) | Leave type with balances | "Leave type" (entitled types + unpaid; labels with balance, `LeaveActions::typeLabel`) | HAVE | — |
| 3 | From / To · `fromDate`, `toDate`; No of days | Dates and day count | From / To; days and balance before → after (`BeforeAfterPreview::leave`) | HAVE | — |
| 4 | Full Day / First half / Second half · `fromDateStatus`, `toDateStatus` | Half days | Start / End session | HAVE | — |
| 5 | Note "Please note that this leave request will be approved by an exceptional workflow" | Tells the employee the leave goes to a different route (e.g. beyond balance) | Workflows may run on leave (P5); nothing tells the employee | PARTIAL → built (RQ-2: "Who approves this" in the leave form names the workflow that would decide it, or says it "may go to" one whose conditions depend on the request) | RQ-2 approver preview covers it |
| 6 | Attachment · `fileName` | Supporting document | Upload (saved to documents) or pick an existing document | HAVE | — |
| 7 | Comments · `reason` | Reason | "Reason" (required) | HAVE | — |
| 8 | "Do you want to make your leave public?" Yes / No | Whether colleagues see the leave in team planned leaves | Leave calendar shows everyone in scope, never reasons; no per-request choice | MISSING | RQ-4: `leave_requests.is_private` (default false = today); private leaves show as "Away" without type on calendars and home cards |
| 9 | "Comp Off generated from:" (dates) | Which worked days the comp-off comes from | Comp-off credits generated by the attendance policy (TO-4) as leave credits; no source dates on the request | PARTIAL | RQ-4: show the credit's source dates when the type is comp-off |
| 10 | "View Team leaves" (`app-team-leave`: date range, leave type filter, 1st / 2nd half, "On leave" list, Reset / Apply) | See who in the team is away before applying | Leave calendar page (leave.view / leave.approve) | PARTIAL | RQ-4: team-away panel in the form for the chosen dates (same scope rules as the calendar) |
| 11 | "View pending request" | Open requests | — | MISSING → built (RQ-1: open-requests panel in the leave form) | RQ-1 |
| 12 | Approver view: Leave type, No of days, Remaining balance, Comments, Comp-off dates, Attachment, approver remarks | Decide | Approval Center leave item (balance after, team away, approve / reject / send back) | HAVE | — |

### Leave encashment (`app-initiate-leave-encashment`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Leave type · `leaveType` | Encashable type | "Leave type" (encashable, active) | HAVE | — |
| 2 | "Specify leaves" · `leaveValue` | Days to encash | "Days" (0.5 steps) | HAVE | — |
| 3 | "Available leave balance:" | Balance shown | Not shown | PARTIAL | RQ-4: helper with balance and the encashment rule's limit (TO-7) |
| 4 | "Enter comments below" · `remarks` | Comment | "Reason" | HAVE | — |
| 5 | Upload file * | Supporting file (required) | — | MISSING | RQ-4: attachment, required when the leave type's encashment rule says |
| 6 | Approver: Request date, Leave type, No. of leaves to be encashed, Uploaded file, Comments, approver remarks | Decide | Approval Center `leave_encashment` | HAVE | — |

### On duty (`app-initiate-on-duty` → `app-on-duty-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "1. Select on duty date(s)" calendar, Range / single, Absent / Today / Half day absent; "No absent days available to mark On duty for this month" | Pick dates | From / To pickers | PARTIAL | RQ-3 |
| 2 | "2. Select reason" · `onDutyType` ("Same reason will be used for all selected dates") | On-duty type | "On-duty type" from the time-office policy's `on_duty_types` | HAVE | — |
| 3 | Per date: Check in-out (`inDate`, `outDate`, hours, minutes), "ADD ANOTHER TIME" (several slots a day), Update | Times per date, more than one slot | Part of the day: full / first half / second half / hours (one slot for the whole request) | PARTIAL | RQ-3: per-date rows with one or more slots |
| 4 | Shift / Check in time / Check out time (shown per date) | Context of the day | — | MISSING | RQ-3 day context |
| 5 | Project · `project`, Project task · `projectTask`, Category · `projectBillableCategory` | Billable project time | — | MISSING | Blocked (projects) |
| 6 | Work mode · `workplaceId`, City · `cityCode`, Office · `officeId` | Where the duty was | — | MISSING | RQ-3 (same as AR row 5) |
| 7 | Comments · `remarks` / `comment` | Reason | "Reason" | HAVE | — |
| 8 | Attachment | Proof | "Supporting documents" | HAVE | — |
| 9 | "OD balance :" | Remaining on-duty days | Monthly limit enforced; not shown | PARTIAL | RQ-3 |
| 10 | "You have applied for On duty for following week offs / holidays / Leave — Are you sure you want to continue?" | Warning | — | MISSING | RQ-3 |
| 11 | View Punches / View pending request | Context | — | PARTIAL → built (RQ-1: pending requests part: open-requests panel in the time-office form; "View punches" stays RQ-3) | RQ-3 / RQ-1 |
| 12 | Approver: Request type, Project, Requested check-in / check-out, Attachment, City, Office, Work mode, Category, Project task, approver remarks | Decide | Approval Center `time_office` | HAVE | Location / project facts follow rows 5–6 |

### Change shift (`app-initiate-change-shift`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Calendar Range / Single Date ("Please select dates to continue") | Pick dates | "Shift change": From / To; "Week-off change": weekly off to work + day to take off | PARTIAL | RQ-3 |
| 2 | Grid per date: Date, Shift ("Select shift"), Weekly off ("Select week-off"), Actions: Add next day, Remove date, Copy shift to all rows | Different shift or weekly off per date in one request | One shift for the whole range; week-off swap as a separate type | PARTIAL | RQ-3: per-date grid (shift and / or weekly off) stored as one request with day rows |
| 3 | "Change shift setting off — Sorry, you won't be able to change shift." | Policy switch | Time-office policy refuses shift change when off (TO-3) | HAVE | — |
| 4 | "Note : Maximum 2 days week off and 1 day shift change is allowed" | Policy limits shown | Monthly limits enforced (`assertMonthlyLimit`); not shown | PARTIAL | RQ-3: show the limits |
| 5 | "You have made N changes in existing shift / weekoff" | Summary before submit | — | MISSING | RQ-3 |
| 6 | Add comment ("Enter comment below") / Upload file (pdf, doc, docx, jpeg, jpg, png, bmp; 5 MB) / Done / Submit | Reason and file | Reason + attachments (jpeg, png, pdf only) | PARTIAL | Accept doc / docx for time-office attachments (config list) |
| 7 | Approver (`app-change-shift-detail`): Earlier shift, New shift, Earlier weekly off, New weekly off, Comment, Attachment, Remarks, Reject / Approve | Decide | Approval Center `time_office` (facts) | PARTIAL | RQ-3: earlier vs new per date in the facts |
| 8 | Bulk change-shift approval (`app-bulk-change-shift-approval`) | Approve many | Bulk approval for listed types (P3) | HAVE | — |

## 20.1.19 Request history lists (per type; `app-*-search` / `app-*-card`, reached from "All Searches", My space and the HR desk)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Common card statuses Pending / Undo / Rejected / Approved; "SHOW MORE"; Workflow (opens the tracker); FILTERS / CLEAR ALL / Apply | Track own requests per type | Each module's list: own records, status filter, view | PARTIAL → built (RQ-1: one list over every type with status tabs, a request-type filter and date filters; "Open" goes to the module screen) | RQ-1 (one list, per-type tabs) |
| 2 | Help text "Once you submit cancel request from here, you need to go to insights page to approve it" (AR, OD, short leave, leave, encashment) | Cancellation of an approved request goes for approval | Leave cancellation request (`requestCancellation`), time-office `cancel_requested`, both in the Approval Center | HAVE | Regularisation and encashment cancel without approval (direct) |
| 3 | Attendance regularization list: filters AR date, Request date, Approved date, Status; card "Requested Date" | AR history | Regularisations list (date, type, in, out, reason, status, by, reviewed by), status filter | PARTIAL → built (RQ-1: AR date / requested / decided date ranges on My requests) | Date-range filters (RQ-1) |
| 4 | Leave list: Leave start / end date, Request date, Approved date, Leave type, Status; "Cancel leave"; bulk "Cancel all" (status filter needed) | Leave history | Leave requests list (own / scope), cancel, cancellation request | PARTIAL → built (RQ-1: leave in My requests with its dates; HR bulk "Cancel leave" on Leave requests (bulk.run + leave.manage, Leaves::cancel)) | Bulk cancel for HR (RQ-1) |
| 5 | On duty list: On duty start / request / approval date, Status, On duty type, Project | OD history | Time-office requests list (type, status) | PARTIAL → built (RQ-1: on duty as its own type with date asked for / requested / decided ranges) | RQ-1 |
| 6 | Restricted holiday list: RH date, Request date, Approved date, Status; card "Holiday Name" | RH history | Time-office requests list | PARTIAL → built (RQ-1: restricted holiday as its own type with its date ranges) | RQ-1 |
| 7 | Short leave list: Start date, Request date, Approval date, Status | Short leave history | Time-office requests list | PARTIAL → built (RQ-1: short leave as its own type with its date ranges) | RQ-1 |
| 8 | Change shift list: "View all your past and upcoming Change shift requests here"; Change shift date, Request date, Approved date, Status | Shift history | Time-office requests list | PARTIAL → built (RQ-1: change shift as its own type with its date ranges) | RQ-1 |
| 9 | Comp off list: Generated for date, Request date, Approved date | Comp-off history | Time-office comp-off claims; comp-off credits in leave transactions | PARTIAL → built (RQ-1: comp-off as its own type with its date ranges) | RQ-1 |
| 10 | Overtime list: Start date, Request date, Approval date, Status; card Requested Date, OT Date | OT history | Time-office overtime claims | PARTIAL → built (RQ-1: overtime as its own type with its date ranges) | RQ-1 |
| 11 | Leave encashment list: Requested date, Approve date, Leave type, Status | Encashment history | My leave encashments (leave, year, days, reason, status, decided by, asked on), withdraw | HAVE | — |
| 12 | Expense advance list: Requested date, Approved date, Status | Advance history | Expense advances list | HAVE | — |
| 13 | Helpdesk list: Ticket id, Description, Assigned to, Requested by, Requested on; statuses Inprogress / Reopen / Hold / Closed / Undo / Open; filters Status, Date, Ticket code, Request by | Ticket history | My HR → Requests; Tickets list | HAVE | — |
| 14 | Travel list: Requested / Approved / Travel date, Project, Status, Assigned to, Approver; "Book Travel" | Trip history | Travel requests list | PARTIAL | Project blocked |
| 15 | Asset request list: Request date, Request type, Status; card Approved by, Approved date | Asset request history | Asset requests list | HAVE | — |
| 16 | Variable list: Requested by, Requested on, Pay month, Status | Variable history | Variable pay requests list | HAVE | — |
| 17 | Loan list: Loan request date, Loan approved date, Dispense date, Loan type, Status | Loan history | Loans list (type, status filters) | HAVE | Date filters (RQ-1) |
| 18 | Reimbursement list: Request date, Approved date, Component type, Status; bulk cancel (status filter) | Claim history | Reimbursement claims list (status filter, cancel) | PARTIAL → built (RQ-1: claims in My requests with claim / requested / decided ranges; payroll bulk "Cancel claims" (bulk.run + payroll.manage, ReimbursementClaims::cancel)) | Bulk cancel for payroll (RQ-1) |
| 19 | Investment list: Request / Approved date, Tax regime, Financial year, Status; "Investment Request", "HRA Import", "Investment Import"; bulk document download | Declaration history | Tax declarations list; proofs per item | PARTIAL | Bulk proof download (RQ-7) |
| 20 | Expense receipt list: Receipt code, Expense date, Category, Invoice no., Amount, Project, Reimbursable, Payment status, Receipt status (Pending / Suspected / Rejected / Approved), Expense type | Receipt history | Expense receipts list | HAVE | Project blocked |
| 21 | Custom app ("Studio") requests (`app-lego-initiated-requests`): Workflow status, Trigger, Stage ID, Clone Submission, Cancel request, Edit, View workflow status | Requests made through HROne's app builder | Configuration Forms with submissions and approvals (`form.submit`, `form.approve`) | PARTIAL → built (RQ-1: in part: form submissions are a registry type listed in My requests for those who fill forms; clone / cancel a submission stays open (the forms module has neither)) | Clone / cancel a form submission (RQ-1 registry includes form submissions) |

---

# 20.2 Inbox

## 20.2.1 `/app/inbox` and `/app/inbox/extended` (`app-inbox`, `app-inbox-expanded-view`, `app-inbox-side-menu`, `app-inbox-header2`, `app-bulk-move-to-archive`, `app-insights-inbox`, `app-inbox-status`)

The captured tabs of `/app/inbox/extended` (All, Confirmation, Create goal, Declare statutory details, Initiate settlement,
Leave cancel, New announcement, Relieving letter, each with a count) are this tenant's pending task categories, not a
fixed list; the full set of task types is in 20.2.2.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Inbox" heading with layout toggle (list + detail ↔ expanded `/app/inbox/extended`) | Two reading modes | Approvals: list with a side detail on wide screens, drawer otherwise; Task inbox is a separate table | PARTIAL → built (RQ-8: Workflows → Unified inbox (`Inbox`): Approval Center items and the person's own tasks in one list with the item beside it, and "One at a time") | RQ-8: one inbox page over Approval Center items and workflow / employee tasks, with list + detail and focus modes |
| 2 | "New – <count>" (collapsible) | All pending tasks with the total | Approvals heading "N decisions need you", navigation badges on Approvals and Task inbox | HAVE | — |
| 3 | "All" | Every pending task | Approvals (all kinds) | HAVE | — |
| 4 | Task categories with counts (one per pending task type, e.g. Confirmation, Create goal, Declare statutory details, Initiate settlement, Leave cancel, New announcement, Relieving letter) | Filter the inbox by task type | Approvals groups by urgency (Urgent / This week / Later), not by type; no per-type counts | MISSING → built (RQ-8: request-type facets with counts (RQ-1 registry labels; cancellations, "Forms" and workflow names as their own)) | RQ-8: type facets from `ApprovalCenter::kinds()` plus task kinds, with counts |
| 5 | Side menu "Search by name" | Find a category | — | MISSING → built (RQ-8: "Search by name" over the facets) | RQ-8 |
| 6 | "Archive" (collapsible list) | Archived tasks, restorable | Approvals "Archived" (Restore); Task inbox "Archived" tab (P4) | HAVE | — |
| 7 | "Insight": Task completed N, On time %, Delayed % | The approver's own responsiveness | — (gap register IP-03 / AN-08) | MISSING → built (RQ-8: Insight — decided, on time %, delayed % over 7 / 30 / 90 days from workflow steps and Approval Center decisions (`inbox_decisions`); others only with `task.view_all` / `audit.view`) | RQ-8: from decided items in the period: count, share decided by due time, share late (audit + workflow tasks `completed_at` vs `due_at`) |
| 8 | "Studio" | Inbox of tasks from HROne's app builder | Configuration Forms approvals (`form.approve`) appear as workflow steps when a workflow runs on them | PARTIAL → built (RQ-8: facet "Forms" for configuration-form runs, with the run's overall status) | RQ-8 facet "Forms" |
| 9 | "All Messages" dropdown *(inferred: All / unread / by state)* | Message view filter | — | MISSING → built (RQ-8: "Messages" choice All / Unread / Overdue / Due today / Due tomorrow (read marks per person)) | RQ-8: All / Unread / Overdue / Due today / Due tomorrow |
| 10 | Filter panel: Enter Employee Code or Name, Start date, End date, Leave type, Category, Sub category, Project, Travel Code, Type of poll; organisation-unit dialog ("All Searches", Clear all, Done); "Filter applied" | Narrow the queue | — | MISSING → built (RQ-8: filters employee code or name, requested from / until, leave type, ticket category, travel request, organisation unit; project / poll stay blocked) | RQ-8: filters on requester / employee, requested-at range, leave type, ticket category, travel request, organisation unit (`AccessScope` dimensions); project / poll blocked |
| 11 | Search (magnifier; "We couldn't find anything. Check your spelling or try another keyword.") | Keyword search over tasks | — | MISSING → built (RQ-8: keyword search ("We couldn't find anything …")) | RQ-8 |
| 12 | Quick filters (feature inventory): Overdue, Due today, Due tomorrow, Missed response from me, Relieved employee | Common views | Urgency groups (urgent includes overdue) | PARTIAL → built (RQ-8: quick filters Overdue, Due today, Due tomorrow, Missed by me (workflow steps decided without the person), Relieved employees) | RQ-8: the five as facets ("relieved employee" = items about employees who have left) |
| 13 | Task card: title, raised date-time, task type label, period (e.g. goal cycle, leave dates) | One line per task | Approvals row: title, type label, from / due date, requester; Task inbox row: title, workflow, about, type, due, raised | HAVE | — |
| 14 | Due badge: "DUE IN : N DAYS", "DUE : TODAY", "DUE : TOMORROW", "OVERDUE", "MISSED TO RESPOND" | Time pressure per task | "due <date>" and urgency group; overdue in red on Task inbox | PARTIAL → built (RQ-8: Due in N days / Due today / Due tomorrow / Overdue / Missed to respond on every row) | RQ-8: relative due labels |
| 15 | Empty states "GOOD! All your pending tasks appear here", "NO MESSAGES — Well done! you responded to all messages in time", "SHOW ALL NEW MESSAGES" | Empty / reset | "You're all caught up" | HAVE | — |
| 16 | Detail header: title, From (person or "System"), To, Due date | Who sent it, to whom, by when | Approval card: requester, requested at, due, effective date, risk | HAVE | — |
| 17 | Header action "Move to archive" | Archive this task | Per-item "Archive" (types in `inbox.archive.types`) | HAVE | — |
| 18 | Header action "Send Back" → "Select send back to" (choose the person / level); note "This will send back the request to … Once … takes action, it will come back to you for approval" | Return to the requester or an earlier approver | Send back to the requester only (P3, `inbox.send_back.types`) | PARTIAL | RQ-9: send back to an earlier approver of the same run (the run reopens that step, then returns to the sender) |
| 19 | Header action "Delegate" → "Delegate task" ("Once a task has been delegated, then it will not be visible in your inbox and you will not able to undo the action") | Hand one task to someone else | Reassign a pending workflow task to a person or role (`task.reassign`); period delegation (Out of office, P4); no hand-over of a single domain request | PARTIAL | RQ-9: "Delegate" on any Approval Center item = a one-item delegation (`task_delegations` with an item id), permission `task.delegate` |
| 20 | Header action "View workflow" | The run's levels and responses | Run viewer for workflow tasks ("Open run") | PARTIAL → built (RQ-2: "Workflow and policy" on the Approvals page card: the trail, earlier levels and whom it waits with, loaded per item) | RQ-2 timeline in the approval card |
| 21 | Header action "Employee directory" (`app-view-emp-info` → social profile) | Look at the employee | Item links to the record; Employee 360 when permitted | PARTIAL → built (RQ-8: profile peek in the item (PersonPeek + CA-6 sections; nothing outside the viewer's visibility)) | RQ-8: profile peek in the card (same visibility as People) |
| 22 | "More" → "Give a badge" (badge, give to, message, suggested messages, do not post on social wall, attachment, internal notes) | Recognise from the inbox | Badges / recognition exist (`RecognitionActions`, HP-9c) | PARTIAL → built (RQ-8: "More" → Give a badge (the existing form, colleague picked)) | RQ-8: launch the existing action from the card |
| 23 | "More" → "Give Reward Points" (quota this month, give to, points, message, do not post on social wall) | Reward from the inbox | `RewardPointActions` (HP-9d) | PARTIAL → built (RQ-8: "More" → Give reward points (colleague picked; only while points are on)) | RQ-8 |
| 24 | "More" → "Schedule 1-on-1" (with, agenda, date, frequency, meeting type, time zone, address / room, start / end time; calendar-connection note) | Schedule a meeting from the inbox | One-on-ones resource | PARTIAL → built (RQ-8: "More" → Schedule a one-on-one (the existing form)) | RQ-8 |
| 25 | "More" → "Create an initiative" (name, due date, assign to, KPI, initiative type; Save / Save and new) | Assign an initiative | Initiatives pending (HP-8j) | MISSING → built (RQ-8: "More" → Create an initiative (the existing form)) | After HP-8j |
| 26 | "More" → View HR Handbook (`app-policy-view-flyer`) | Policy reference | My HR → Policies | PARTIAL → built (RQ-2: policy articles of the request type in the card's "Workflow and policy") | RQ-2 row 3 |
| 27 | "IGNORE" on system-generated tasks ("This task is not relevant now, please ignore") and status IGNORED | Dismiss a system task without acting | Task inbox "Mark done" completes; no ignore | MISSING → built (RQ-8: "Not relevant" on system to-dos (event- or system-started runs), `task.ignore`, reason, audited, restorable) | RQ-8: "Not relevant" with a reason for system tasks (closes the task, audited); not for approvals |
| 28 | Statuses COMPLETED / REJECTED / APPROVED / IGNORED | Outcome badges in the archive / completed list | Approvals "Completed" (last two weeks, status); Task inbox "Handled by me" | HAVE | — |
| 29 | "MISSED TO RESPOND — You can no longer respond to this request" | The step's turnaround passed and the workflow moved on without this approver | Escalation reminders; auto-decide only at the last level (P5); otherwise the task stays pending | PARTIAL | RQ-9: escalation action `skip` (move to the next level, mark the task missed) — accepted on publish only where a later approval exists |
| 30 | "View last approver's status" | What the previous level decided | Facts on some items; run log for workflows | PARTIAL → built (RQ-2: earlier levels' decisions in the card's trail) | RQ-2 timeline |
| 31 | Decision comments (`approverRemarks`) with "Add approver comment" | Note with the decision | Note on approve, reason on reject | HAVE | — |
| 32 | Expanded view: centred task card, previous / next arrows, side icons, "View task categories", tabs "New" / "Archive" | Work through tasks one by one | Approvals focus mode; no previous / next | PARTIAL → built (RQ-8: previous / next within the current filter, "N of M", focus mode) | RQ-8: next / previous within the current filter |
| 33 | Bulk "Move to archive" table: S.NO, EMPLOYEE CODE, EMPLOYEE NAME, ASSIGN DATE, DUE DATE, DETAILS; Hint | Archive many at once | Manual mode "Archive them" (items older than N days) | PARTIAL → built (RQ-8: tick-and-archive in the list (types offering Move to archive or past the archive period)) | RQ-8: tick-and-archive in the list |
| 34 | Bulk approval ("Bulk Approval Inbox" setting) | Approve many | "Select all" + "Approve selected" for `inbox.bulk_approval.types` (P3) | HAVE | — |
| 35 | Inbox actions settings (`app-inbox-actions`): Request Type, Bulk Approval Inbox, Move to archive, Delegate, Send Back, Update | Which request types get which inbox action | Setup → Automation "Inbox actions" (`inbox.bulk_approval.types`, `inbox.discussion.types`, `inbox.send_back.types`, `inbox.archive.types`, `inbox.delegation.types`) | HAVE | — |
| 36 | Archive inbox settings (`app-archive-inbox-task`): enable bulk archiving, Auto / Manual, after N days (1–365), excluded tasks | Auto archive | `inbox.archive.enabled`, `.mode`, `.after_days`, `.excluded_types`, nightly `peopleos:inbox:archive` | HAVE | — |
| 37 | Feedback form inside a task (`app-inbox-dynamic-form`: rating, subjective answers, attachment ≤ 5 MB) | Answer a feedback template in the inbox | Feedback requests in My tasks (P13) | HAVE | — |
| 38 | Inbox count on the home page ("Inbox N Pending tasks") | Entry point | Home decisions card, navigation badges | HAVE | — |
| 39 | "Initiated" requests view (`app-initiated-request`: Select filter, Employee Code or Name, Start date, End date, Enter Ticket id, Go; "NO REQUEST") | Requests the person raised, searchable | My work "Waiting on others" (part of the types) | PARTIAL → built (RQ-1: My requests: request-type filter, date range, search by reference, reason or ID; a manager picks a team member) | RQ-1 |
| 40 | Intimation cards ("Intimation" in workflow) | FYI cards for people informed of a request | Notification rules (in-app / email) | PARTIAL → built (RQ-2: colleagues the requester chose get an in-app note (or the tenant's `request.intimated` rule), with the requester's message) | RQ-2 row 6 |

## 20.2.2 Inbox task types (the 78 task views whose header is `app-inbox-header2`)

Each row is one HROne inbox card type: what the approver or assignee does in it, and where PeopleOS puts the same work.
"Approval Center" = `Approvals.php` through `ApprovalCenter` kinds; "My tasks" = `ExperienceTasks` sources.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | `app-leave` (leave detail: type, days, remaining balance, comments, comp-off dates, attachment, approver remarks) | Approve / reject leave | Approval Center `leave` (send back, bulk) | HAVE | — |
| 2 | Leave cancel (category "Leave cancel") | Approve a leave cancellation | Approval Center `leave_cancellation` | HAVE | — |
| 3 | `app-leave-encashment-detail` | Approve encashment | Approval Center `leave_encashment` | HAVE | — |
| 4 | `app-attendance-regularization` | Approve AR | Approval Center `regularisation` | HAVE | — |
| 5 | `app-on-duty` | Approve on duty | Approval Center `time_office` | HAVE | — |
| 6 | `app-short-leave` | Approve short leave | Approval Center `time_office` | HAVE | — |
| 7 | `app-restricted-holiday` | Approve RH | Approval Center `time_office` | HAVE | — |
| 8 | `app-change-shift-detail` (earlier / new shift and weekly off) | Approve shift change | Approval Center `time_office` | HAVE | Per-date detail RQ-3 |
| 9 | `app-compensatory-off` (shift, check-in / out, working hours, extra hours, comp-off generation; "card generated within 15 minutes"; "You have availed OT so your comp off verification task is not relevant now") | Verify comp-off earned from attendance | Comp-off claim requests (`time_office` comp_off); automatic generation by the attendance policy (TO-4) | PARTIAL | RQ-10: optional verification step for system-generated comp-off (policy switch, default off = today) |
| 10 | `app-overtime` (shift, check-in / out, working hours, extra hours, pay overtime hours; "availed comp off so OT verification not relevant") | Verify overtime | Overtime claims (`time_office` overtime); OT from attendance output | PARTIAL | RQ-10 (same verification switch for OT) |
| 11 | `app-mark-attendance-inbox` | Approve attendance marked by a manager *(inferred)* | Attendance overrides by HR (no approval) | PARTIAL | RQ-11 team "Mark attendance" with optional approval |
| 12 | `app-location-inbox` | Approve a check-in location | Approval Center `location_request` | HAVE | — |
| 13 | `app-wfh-inbox-request` ("Apply your workplan": dates, work mode, office, city, shift; copy to rows; "Work Plan policy of your organisation is turned off") | Employee plans office / home days | No work plan; regularisation type "Work from home" only | MISSING | RQ-10: work-plan request type in time office (policy section "Work plan", default off) |
| 14 | `app-wfh-inbox-approval` (date, work mode, office, city, shift; approve / reject per date; reset all) | Approve the work plan | — | MISSING | RQ-10 |
| 15 | `app-loan` (activity log, guarantor details) | Approve loan | Approval Center `loan` | HAVE | — |
| 16 | `app-loan-guarantor` | Guarantor accepts | Approval Center `loan_guarantee` | HAVE | — |
| 17 | `app-reimbursement` (claimed amount, submitted bill amount, bill already submitted, leave period / type, attachment) | Approve claim | Approval Center `reimbursement_claim` (approved amount editable) | HAVE | — |
| 18 | `app-claim-reimbursement` ("Claim Reimbursement", IGNORE / YES, CLAIM) | System asks the employee to claim an unclaimed reimbursement | — | MISSING | RQ-10: monthly "claim your reimbursement" task when a pay-on-claim component has balance (setting `payroll.claims.remind`, default off) |
| 19 | `app-variable` (editable pay variable, eligibility / paid totals) | Approve variable pay | Approval Center `variable_pay` | HAVE | Amount edit RQ-5 |
| 20 | `app-investment-dec-inbox` (review: edit, add investment, regime change request, activity log, download proofs) | Payroll reviews a declaration | Tax declarations screen; `TaxDeclarationWorkflowBridge` when a workflow runs; not an Approval Center kind | PARTIAL | RQ-10: Approval Center source for submitted declarations / proofs waiting for verification |
| 21 | `app-investment-declaration` (task "declare", IGNORE / SUBMIT) | Ask the employee to declare | Declaration window and reminders via notification rules | PARTIAL | RQ-10: "Declare your investments" employee task while the window is open and nothing is submitted |
| 22 | `app-statutory-setting` ("Please declare statutory details": PAN–Aadhaar linked, regime, NPS employer contribution amount / %, prorate, PRAN, VPF, PF applicable / number / UAN / limits …) | HR declares a joiner's statutory set-up | Statutory profiles / PF options resources; no task | PARTIAL | RQ-10: HR task on joining for employees without statutory details |
| 23 | `app-assign-salary-structure` (new join CTC, assign / review salary structure, salary insights vs new Code of Wages, "never intend to process payroll in HROne") | Assign a joiner's salary structure | Salary structures and compensation changes; no task | PARTIAL | RQ-10: HR task on joining for employees without a structure |
| 24 | `app-fbp-salary-structure` (declare / approve FBP) | Flexible benefit plan | FBP missing (P5 note) | MISSING | Payroll matrix (BP-7) |
| 25 | `app-settlement-inbox` (Initiate settlement; IGNORED; comments) | Start full and final | Final settlements on the exit case | PARTIAL | RQ-10: HR task when an exit reaches its last day without a settlement |
| 26 | `app-separation` (resignation detail: created on, resigned on, relieving date as per policy, proposed LWD, reason, comment, total / actual recovery amount; YES ACCEPT / REJECT; task: approver reason, notice recovery relaxation days, proposed relieving date, consider for future openings Yes / No, blacklist reason / comments, who raises the replacement hiring) | Manager / HR accepts the resignation | Acceptance only through a workflow (`ResignationWorkflowBridge` → `Exits::acceptResignation`); rehire eligibility and blacklist reason on the exit case (CA-3); no Approval Center kind | PARTIAL | RQ-6: Approval Center source "Resignation" (accept with relieving date, recovery relaxation days, rehire eligibility + reason; reject = withdraw); replacement hiring N-A |
| 27 | `app-confirmation` (confirmation detail: "Would you like to proceed?", confirmation date, View profile, YES CONFIRM / Extend Probation / Terminate) | Decide confirmation | Confirmation reviews (WF-1) on their own screen; feedback in My tasks | PARTIAL | RQ-10: Approval Center source for confirmation reviews due |
| 28 | `app-exit-interview` (resignation accepted details, interview type, SHOW EXIT INTERVIEW) | Fill the exit interview | Feedback requests (P13) / exit case "My exit interview" | HAVE | — |
| 29 | `app-letter-generation` (template, primary … fourth signatory, publish, re-generate & publish) | Generate a letter (e.g. relieving) | Letters drafted on exit / completion by policy; letter approval in the Approval Center (`letter`); signatories (letter studio) | HAVE | — |
| 30 | `app-acknowledge-letter` | Employee acknowledges a letter | — (letter-acceptance tasks not built, P4 note) | MISSING | RQ-10: letter acknowledgement task (template flag) |
| 31 | `app-ack-hr-handbook` | Acknowledge the HR handbook | Policy acknowledgements (My tasks) | HAVE | — |
| 32 | `app-acknowledge-asset` ("I hereby acknowledge …", digital signature upload / draw) | Acknowledge an assigned asset with signature | Asset acknowledgement task (AS-A); no signature | PARTIAL | Signature capture with the letter studio's e-sign decision |
| 33 | `app-asset-approval` | Approve an asset request | Approval Center `asset_request` / `asset_return_request` | HAVE | — |
| 34 | `app-asset-task-owner-approval` (manage asset request: assign, return, expiring-asset task) | Fulfil an asset request | Asset owner fulfilment (AS-B) | HAVE | — |
| 35 | `app-expense-report-approval` (receipts, policy violation, configurable columns) | Approve expense report | Approval Center `expense_report`, `expense_exceptional` | HAVE | — |
| 36 | `app-dis-adv-approval` | Dispense an advance | Dispense advances page; Approval Center `expense_advance` | HAVE | — |
| 37 | `app-travel-approval` (approve; booking details) | Approve / book travel | Approval Center `travel_request`, `travel_request_part`, `travel_cancellation`; travel desk booking request | HAVE | — |
| 38 | `app-help-desk-approval` (close with RCA, reopen) | Work a ticket | Ticket queue / My tasks (`ServiceDeskTaskSource`) | HAVE | RCA see 20.1.10 row 7 |
| 39 | `app-profile-home` (document, "No Changes") | Approve an employee's profile change | Profile updates by employees go through catalogue services (e.g. "Update bank details") with their own approval | PARTIAL | Core HR matrix (profile change approval) |
| 40 | `app-welcome-task` (profile completion: bank details with verification, documents, family / nominee) | Joiner completes the profile | Onboarding tasks (profile sections, CA-4 document rules) | HAVE | — |
| 41 | `app-welcome-employee` (welcome message, things you should know / avoid, shortcuts, assign buddy, buddy tasks) | Manager welcomes a joiner | Onboarding buddy and tasks (`Onboarding`), welcome message setting (HP-9a) | PARTIAL | Onboarding matrix |
| 42 | `app-upcoming-employee` (not joining remark, new joining date, create employee, delete pre-boarding tasks) | Manage an upcoming joiner | Pre-joining onboarding plans (templates may start before joining) | PARTIAL | Onboarding matrix |
| 43 | `app-on-boarding` (pre-requisite task: done / pending, delegate, related link, video link, expected / completed date, IGNORE) | Onboarding task | Task inbox "Onboarding tasks" | HAVE | Ignore = 20.2.1 row 27 |
| 44 | `app-induction` (start / end, expected standards, outcomes, 1-on-1, initiative, approver remarks) | Induction approval | Induction programmes (08.2, WF-3) | HAVE | — |
| 45 | `app-induction-evaluation` (progress, plans, challenges, other feedback, last 1-on-1, YES IGNORE) | Induction evaluation | Induction feedback (P13 feedback requests) | PARTIAL | Onboarding matrix |
| 46 | `app-pre-transfer` (pre-requisite tasks before a transfer) | Transfer pre-tasks | Transfers (WF-2) without pre-tasks | PARTIAL | Workforce matrix |
| 47 | `app-transfer-approval` | Approve a transfer | Approval Center `transfer` | HAVE | — |
| 48 | `app-do-transfer-approval` (effective date, reason, allowances, change goals?, revise salary?, other OU) | Execute a transfer | Transfers with allowances (WF-3) | HAVE | — |
| 49 | `app-contract-approval` (contract name, start / end, attachment, status, lapsed leave, view contract) | Approve an employee contract | — (contracts pending, BP-16) | MISSING | After BP-16 |
| 50 | `app-renew-contract` (renew / roll off) | Contract renewal | — | MISSING | After BP-16 |
| 51 | `app-add-position-approval` | Approve a new position | Workforce plans and position versions; no position-request approval | PARTIAL | RQ-12 |
| 52 | `app-goal-cycle-approval` (goal sheet with KRA / KPI / competencies) | Approve a goal sheet ("Create goal" category) | Approval Center `goal_plan` | HAVE | — |
| 53 | `app-emp-check-in` (progress, comment, ignore) | KPI check-in reminder | Performance check-ins (no task) | PARTIAL | Performance matrix |
| 54 | `app-emp-goal-checkin` (task period, last check-in period, progress, comment) | Goal check-in task | Goal "Check in" action | PARTIAL | Performance matrix |
| 55 | `app-review-initiation` | Start a performance review | Appraisals / performance cycles | PARTIAL | Performance matrix |
| 56 | `app-review-acknowledgement` (view performance review, comments) | Employee acknowledges the review | Appraisal acknowledgement on the appraisal | PARTIAL | Performance matrix |
| 57 | `app-pip` | Approve a PIP | Approval Center `pip` | HAVE | — |
| 58 | `app-pip-evaluation` | Evaluate a PIP | Approval Center `pip_evaluation` | HAVE | — |
| 59 | `app-policy360-nomination-approval` | Approve 360 nominations | 360 feedback pending (HP-8h) | MISSING | After HP-8h |
| 60 | `app-policy360-send-nomination-approval` | Send nominations for approval | As row 59 | MISSING | After HP-8h |
| 61 | `app-initiative-inbox` | Initiative task | Initiatives pending (HP-8j) | MISSING | After HP-8j |
| 62 | `app-one-on-one-acknowledge` (agenda, date, time, time zone, meeting type, room) | Acknowledge a 1-on-1 | One-on-one feedback task (HP-8b) | PARTIAL | Performance matrix |
| 63 | `app-announcement-inbox` ("New announcement") | Read / acknowledge an announcement | Communications in My tasks (`CommunicationTaskSource`) | HAVE | — |
| 64 | `app-events` (name, type, sub-type, date, time, seats, mode, host, agenda, attachment, video URL, acknowledge, nominate yourself) | Event invitation | — (no events) | MISSING | Engage matrix (events) |
| 65 | `app-events-feedback-template` | Event feedback | — | MISSING | Engage matrix |
| 66 | `app-polls` | Answer a poll | — (polls not built, P5 note) | MISSING | Engage matrix (polls) |
| 67 | `app-polls-card-approval` (poll from, created by, unpublished, comments) | Approve a poll | — | MISSING | Engage matrix |
| 68 | `app-survey-approval` (survey, comments, average rating, ignored) | Survey task | Surveys in My tasks (`SurveyTasks`) | HAVE | — |
| 69 | `app-timesheet-approval` ("Timesheet Request — You can select or deselect the request to approve or reject") | Approve timesheets | — (no timesheets / projects) | MISSING | Blocked (projects) |
| 70 | `app-project-approval` | Approve a project | — | MISSING | Blocked (projects) |
| 71 | `app-milestone-approval` | Approve a milestone | — | MISSING | Blocked (projects) |
| 72 | `app-milestone-activity-log` | Milestone history card | — | MISSING | Blocked (projects) |
| 73 | `app-hrone-notification` ("Request support team to implement this process!") | Vendor product notice | — | N-A | Vendor marketing |
| 74 | `app-assign-recruiter` | Recruiter assignment | — | N-A | Recruitment |
| 75 | `app-interview-feedback-approval` | Interview feedback | — | N-A | Recruitment |
| 76 | `app-job-opening-approval` | Requisition approval | — | N-A | Recruitment |
| 77 | `app-offer-letter-aprroval` | Offer approval | — | N-A | Recruitment |
| 78 | `app-resume-shortlisting` | Shortlisting | — | N-A | Recruitment |
| 79 | `app-replacement-request` ("Raise job opening" after a resignation; ignore) | Replacement hiring | — | N-A | Recruitment |

---

# 20.3 Team

Rail "Team" flyout (feature inventory §1): People, Team Analytics, Reports; routes `/app/team/team-reports`,
`/app/team/team-reports/search`, `/app/team/position`. None of them was crawled as a page; rows come from components.

## 20.3.1 Team → People (`app-overview-team`, `app-peoples`, `app-my-team-action`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs "People", "Search", "Action", "Imports" | Team home: list, search, actions on behalf, uploads | My team page (summary, needs attention, list); Command Center | PARTIAL | RQ-11: Team page with People / Actions tabs |
| 2 | Scope filter: All Team / Direct reports / Indirect reports / Functional team | Which team | My team = current direct reports only (`directReports()`) | PARTIAL | RQ-11: indirect (whole line below) and functional (`functional` reporting relationship) through `PeopleVisibility` / relationship scope |
| 3 | People cards / table with "Choose your preference" | Card or table view *(inferred)* | Table: code, employee, designation, state, today (on leave / attendance status) | HAVE | — |
| 4 | "Customize" (select at least 8 fields, Select all, Reset to default) | Choose the columns | Fixed columns | MISSING | RQ-11: toggleable columns (Filament column toggles) from a fixed allow-list of non-sensitive fields |
| 5 | "Save Filters" ("Do you want to save these filters for future use?" Cancel / Yes, sure), "All Filters", "Below is the list of applied filters", Clear all | Saved team filters | — | MISSING | RQ-11: saved filters in `ExperiencePreferences` |
| 6 | Organisation-unit filter dialog | Filter the team by OU | — | MISSING | RQ-11 |
| 7 | "Employee(s) Selected" (multi-select) + bulk actions | Act on several team members | — | MISSING | RQ-11 |
| 8 | Bulk "Assign holiday" / "Remove holiday" / "Replace holiday" (`app-assign-holiday-from-team`: employee, company, business unit, holiday; form step 1 select employees by search / organisation / location / status / type; step 2 "Is this a restricted holiday?", Holiday name, Holiday date, ADD HOLIDAY, holiday bank note) | Give or take holidays for selected people | Holiday calendars apply by rules; no per-person holiday from the team | MISSING | RQ-11: manager action only when the organisation allows it (setting `team.holiday_assign`, default off); writes per-employee holiday overrides through `HolidayResolver` |
| 9 | Bulk mark attendance (`app-bulk-attendance-edit`: From, To, First half, Second half status, Select all) | Manager marks attendance | Attendance overrides (HR screen, attendance scope) | PARTIAL | RQ-11: team action creating overrides, optionally for approval (20.2.2 row 11) |
| 10 | Bulk change shift (`app-bulk-shift-edit`: From, To, Shift, Weekly off, "Do you want to allow auto shift correction?") | Manager assigns shifts | Shift overrides (HR screen); time-office shift change on behalf | PARTIAL | RQ-11: team action over `ShiftOverrides` within the manager's scope (setting `team.shift_assign`, default off) |
| 11 | Bulk leave balance (`app-bulk-leavebalance-details`: Type of leave, Add / Subtract balance, Leave count, Reason; status popup: total processed, success, failed, download error log) | Adjust balances | Leave transactions (HR) | PARTIAL | HR-only today; RQ-11 exposes it only to holders of the leave-adjust permission |
| 12 | Bulk OT / comp-off on behalf (`app-bulk-overtime-compoff-details`: From, To, dates, action, comment, copy to below rows) | Raise OT / comp-off for the team | Time-office request "on behalf" one at a time | PARTIAL | RQ-11 |
| 13 | "Announcement" to selected people ("Announcement created successfully … View announcement") | Message the team | Announcements with audiences (communication permissions, HR) | PARTIAL | RQ-11: team audience for managers holding `communication.manage` or a new `communication.team` |
| 14 | Profile / social profile of a member (`app-profile`, `app-social-profile`) | Open a member | Employee 360 (employee.view, scope) | HAVE | — |
| 15 | Org chart (`app-org-chart-flyer`) | Team in the org chart | Organisation map / designer | HAVE | — |
| 16 | "Action" tab: search actions → pick employee → "GO TO FORM" (`app-on-behalf-search-emp`) | Raise any request for a member | Command Center pick (act on behalf); per-module "on behalf" actions | PARTIAL → built (RQ-1: in part: "Raise for someone" lists the module on-behalf forms the person may open; manager on-behalf breadth stays RQ-16 / RQ-11) | RQ-1 / RQ-11 |
| 17 | "Imports" tab (team uploads) *(inferred)* | Upload team data | Uploads are HR screens (P2 governance) | PARTIAL | — (HR keeps uploads) |
| 18 | NO DATA / SHOW MORE | Paging | Table paging | HAVE | — |
| 19 | Today summary (feature inventory: team planned leaves, people on leave today) | Who is in / away | My team summary: headcount, present, on leave, absent, late, missing punch, remote, regularisations pending, overtime pending | HAVE | — |

## 20.3.2 Team actions (per team member; feature inventory "TEAM ACTIONS (inner)")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | 1 on 1 | Member's 1-on-1s | One-on-ones (performance.team) | HAVE | — |
| 2 | 360 feedback | Member's 360 | — (HP-8h) | MISSING | After HP-8h |
| 3 | Asset request | Member's asset requests / raise one | Asset requests "Request for an employee" | HAVE | — |
| 4 | Attendance | Member's attendance | Attendance calendar (attendance.view may pick anyone in scope); manager role holds attendance.view | HAVE | — |
| 5 | Attendance regularization | Member's AR | Regularisations list (scope) | HAVE | — |
| 6 | Attendance YTD | Year-to-date attendance | Attendance output summaries (HR reports) | PARTIAL | RQ-11: YTD tab on the member view |
| 7 | Badges and rewards | Member's recognition | Recognition / reward points lists | HAVE | — |
| 8 | Clearance | Member's exit clearance | Exit case clearances (`exit.clear`) | HAVE | — |
| 9 | Compensatory off | Member's comp-off | Time-office requests / leave transactions | PARTIAL → built (RQ-1: a manager follows a report's requests in My requests, comp-off included, as the time-office policy lets them see each record) | RQ-1 lists |
| 10 | Confirmation | Member's confirmation | Confirmation reviews | HAVE | — |
| 11 | CTC | Member's CTC | Compensation (compensation.view; not in the manager template) | PARTIAL | Visibility is a tenant choice (CA-6 profile visibility) |
| 12 | Exit interview | Member's exit interview | Exit case | HAVE | — |
| 13 | Expense advance | Member's advances | Expense advances (expense.approve scope) | HAVE | — |
| 14 | Expense report | Member's reports | Expense reports | HAVE | — |
| 15 | Geo locations | Member's approved check-in locations | Geo fences / location requests | HAVE | — |
| 16 | Goal history | Member's past goals | Goals list (performance.team) | HAVE | — |
| 17 | Goals and initiatives | Member's goals | Goals; initiatives pending (HP-8j) | PARTIAL | After HP-8j |
| 18 | Helpdesk | Member's tickets | Team HR requests (status only, manager-visible services) | PARTIAL | By design (no form data shown) |
| 19 | Holiday calendar | Member's holidays | Attendance calendar holidays | HAVE | — |
| 20 | Initiative | Member's initiatives | — | MISSING | After HP-8j |
| 21 | Job requisition | — | — | N-A | Recruitment |
| 22 | Leave | Member's leave | Leave requests (scope), apply on behalf | HAVE | — |
| 23 | Leave balance | Member's balances | Leave balances (scope) | HAVE | — |
| 24 | Leave encashment | Member's encashments | Leave encashments (HR list) | PARTIAL → built (RQ-1: same, leave encashments, as the leave policy lets them see each record) | RQ-1 |
| 25 | Location approval | Member's location requests | Location requests | HAVE | — |
| 26 | Mark attendance | Mark a member's attendance | HR overrides | PARTIAL | RQ-11 |
| 27 | My assets | Member's assets | Assets (custodian) | HAVE | — |
| 28 | On duty | Member's OD | Time-office requests | HAVE | — |
| 29 | Overtime | Member's OT | Time-office requests / attendance records | HAVE | — |
| 30 | Payslip | Member's payslips | Payslips (payroll permissions only) | PARTIAL | Tenant choice (CA-6) |
| 31 | Performance | Member's performance | Appraisals (performance.team) | HAVE | — |
| 32 | PIP | Member's PIP | Improvement plans | HAVE | — |
| 33 | PIP evaluation | Member's PIP evaluation | Improvement plans | HAVE | — |
| 34 | Position assigned | Member's position | Employee 360 position | HAVE | — |
| 35 | Profile | Member's profile | Employee 360 | HAVE | — |
| 36 | Restricted holiday | Member's RH | Time-office requests | HAVE | — |
| 37 | Shift | Member's shift | Shift resolver / overrides (HR) | PARTIAL | RQ-11 |
| 38 | Short leave | Member's short leave | Time-office requests | HAVE | — |
| 39 | Social profile | Member's social profile | Social wall profile | PARTIAL | Engage matrix |
| 40 | Travel | Member's trips | Travel requests "for an employee" | HAVE | — |

## 20.3.3 Team analytics (`app-team-calendar`, `app-team-goal-dashboard`, `app-team-geo-dashboard`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Team calendar: Today / Weekly / Monthly | Team events by week or month | Leave calendar (leave only; managers via leave.approve) | PARTIAL | RQ-11: team calendar over leave, holidays, time-office requests, birthdays / anniversaries, trips (each source gated as today) |
| 2 | Calendar sidebar "All Events" + process dialog (All, pin events, Apply) | Choose which processes show | — | MISSING | RQ-11 |
| 3 | People filter: Direct Reports / Indirect Reports, Select all, Clear all; OU filter | Whose events | Direct reports only | PARTIAL | RQ-11 |
| 4 | Event timeline flyer (search employee, "Showing results for") | Per-person events | — | MISSING | RQ-11 |
| 5 | Empty states ("Sorry! You don't have data access to view events", "Select more processes and people!") | Guidance | — | N-A | UI chrome |
| 6 | Team goal dashboard: performance cycle select; sidebar Processes, Progress (≤ 50 % / ≥ 50 %), Goal status, people filter; goal cards with status | Team goal progress | Goals list (scope); performance analytics (HR) | PARTIAL | Performance matrix (manager goal dashboard) |
| 7 | KPI details flyer: system calculated status, total achieved, total KPIs mapped, goal cycle, achieved, linked, activity log, goal check-in | KPI drill-down | Goal view and check-ins | PARTIAL | Performance matrix |
| 8 | "Share goal on enterprise wall" (image, message, Publish) | Celebrate an achieved goal | Social wall posts (HP-9e) | PARTIAL | Engage matrix |
| 9 | Geo dashboard: Map View / List View, Select date, Search team member, Team member, Date, Time, Address, Coordinates, Check-in / Check-out address, "updated every 15 mins", Processes filter, Head count, Download report, Filter | Where the team checked in | Web / mobile punches with geo data (TO-2 check-in policy, tracking); punches list for HR; no team map | PARTIAL | RQ-11: list view for managers (map blocked: map provider, CA-8) |

## 20.3.4 Team reports (`/app/team/team-reports`, `/app/team/team-reports/search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Team reports list (component not captured; same report search as `/app/processes/reports/search`) *(inferred)* | Ready-made reports limited to the manager's team | Report library (41 datasets, 149 templates) needs `analytics.reports`; the manager role template has no analytics permission | MISSING | RQ-11: "Team reports" = report-library templates flagged `team_safe`, run under the manager's access scope (datasets already scope through `AccessScope`), new permission `analytics.team` added to the manager template |
| 2 | Report search (`app-report-search`: "Enter report name") | Find a report | Report library search (HR) | PARTIAL | RQ-11 |
| 3 | Download history / password-protected download (`app-recent-downloads`, `app-set-password-dialog`) | Past downloads, protected files | Report exports (CSV) and schedules | PARTIAL | See 20.4 rows 14–15 |

## 20.3.5 Team → Position management (`/app/team/position`; `app-position-management`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Heading "Position Management — Create, update, & track job positions from requisition to hire"; "Position list in which the employee is the hiring manager" | Hiring manager's positions | Team positions (`TeamWorkforce`: positions under the positions I hold and my reports'; code, title, status, seats, occupied; read-only) | PARTIAL | RQ-12: a hiring-manager lens (positions where I am hiring manager) next to the reporting lens |
| 2 | Search positions | Find a position | — | MISSING | RQ-12 |
| 3 | Saved views (System views / Custom views; Save Filter, Name of the view, Apply View, Reset All) | Saved filters | — | MISSING | RQ-12 (same store as RQ-11 row 5) |
| 4 | Advanced filters: Position age, start / end date, title, parent position, status, floating, parent mapped, hiring type, hiring manager, sub status, skill type, salary (per annum) min–max, experience min–max, OU (company, business unit, department, sub-department, designation, branch, sub-branch, region, grade, level), job requisition raised / age, job type, urgent hiring, expected DOJ | Narrow positions | Positions resource: status filter only | PARTIAL | RQ-12: filters over the fields that exist (status, dates, parent, organisation dimensions, grade, designation); others follow rows 9–14 |
| 5 | Columns: Employee code, Employee name, Position code, Position title, Parent position, Parent position title, Hiring type, Status, Expected DOJ, Action, Job Request (Raised / Not raised) | Position grid | Code, title, status, seats, occupied (+ company, FTE, from on the HR list) | PARTIAL | RQ-12: occupant, parent position; job request column N-A (recruitment) |
| 6 | Position card: status Pending / Undo / Rejected / Approved, Requested by / date, Vacant / Reserved / Occupied, Temporary / Permanent, Urgent hiring | Card view | Position statuses (proposed, planned, open, frozen, held, abolished, closed) and occupancy | PARTIAL | — |
| 7 | "Add new" position (`app-position-planning-content`) and "Edit" | Raise a new position / change one | Positions are created and changed by `workforce.manage` (HR), approved by `workforce.approve`; managers cannot propose | PARTIAL | RQ-12: managers may propose a position (request) for their area, approved by the existing approval |
| 8 | Position title · `jobTitle`; OU details (company, business unit, department, sub-department, designation, grade, level, region, branch, sub-branch); Employment type; Parent position · `parentWorkforcePlanningRequestId`; Reporting manager | Core position fields | Position versions: title, company, organisation unit, location, establishment, designation, job family, grade, cost centre, parent position, employment type, worker type | HAVE | — |
| 9 | Hiring type · `hiringTypeId` | Replacement / new *(inferred)* | — | MISSING | RQ-12: option list `hiring_types` (tenant master) on the position version |
| 10 | Start month / End month · `startTime`, `endTime` ("Start month can not be greater than … end month") | Position validity | `first_effective_from` and version dates | HAVE | — |
| 11 | Salary min / max (currency) · `salaryFrom`, `salaryTo` | Budgeted pay range | Compensation ranges by grade; workforce budgets (costs, permission-gated) | PARTIAL | RQ-12: optional range on the version (cost permission applies) |
| 12 | Min / Max experience · `experienceFrom`, `experienceTo` | Experience band | Role requirements `min_experience_years` (Phase 9) | PARTIAL | — |
| 13 | Skill type · `skillTypeId`; Skills; Qualification · `qualificationCode` | Requirements | Role requirements (skills, qualifications) | HAVE | — |
| 14 | Hiring manager (employee code) · `positionOwnerEmployeeId` | Who hires | — | MISSING | RQ-12: `hiring_manager_id` on the position version |
| 15 | Position status · `workforcePlanningStatus`; Sub status · `workforcePlanningSubStatus` (Vacant / Occupied: "If 'Vacant' … a new position needs to be created … If 'Occupied' … mapped to an existing employee") | Planning status | Position status and occupancy (computed) | HAVE | — |
| 16 | Mapped employee code · `mappedEmployeeId` ("Duplicate employee mapping") | Map the occupant | Assignments / occupancy | HAVE | — |
| 17 | Position Floating Yes / No · `isPositionFloating` | Temporary floating position before the actual one | — | MISSING | RQ-12 (with row 21) |
| 18 | Remarks; Job description (template, rich text) · `jobDescriptionBodyWithHtml` | Notes and JD | Position description / requirements | PARTIAL | — |
| 19 | Seniority, Function, Customer, Project, Preferred location(s) | Extra classification | Job family; no customer / project | PARTIAL | Projects blocked |
| 20 | "Do you want the job opening request to be auto-created?" · `isAutocreateJobopening`; Add / Create / Cancel job requisition; Job Request Raised / Not raised | Link to recruitment | — | N-A | Recruitment |
| 21 | "Assign actual positions" (`app-assign-actual-position-drawer`: Floating position → Actual position, Confirm actual position, Position reassignment) | Move people from floating to actual positions | — | MISSING | RQ-12 |
| 22 | Clone (`app-clone-position-drawer`: Number of clones) | Copy a position N times | — | MISSING | RQ-12: clone into N proposed positions |
| 23 | "Use import" (download Excel, upload edited sheet, errors file) | Bulk positions | Imports cover employees, bank, punches, attendance, salaries, time-office and payroll uploads; no positions | MISSING | RQ-12: position upload type on the staged pipeline (P2) |
| 24 | Org chart flyer: Position details (title, department, location, created on, position age, inactivate position), Position history (modified field, old, new), Organisation chart, Position org chart, Download PDF; "Due to cyclic reporting we are not able to display org chart" | Position detail, history and chart | Position view (versions, occupancy, requirements, succession), position hierarchy page | PARTIAL | PDF download of the chart (RQ-12, low priority) |
| 25 | "Your last request is pending, you cannot initiate new request" | One open change per position | Position change requests through `workforce.manage` / approve | HAVE | — |
| 26 | Pagination First / Previous / Next / Last | Paging | Table paging | HAVE | — |

---

# 20.4 Search and social wall search

## 20.4.1 Global search (`/app/search`; header box `app-enterprise-search`, overlay `app-global-search-home`, `-header`, `-result`, `-modulewise-reports`, `-report-view`, `-right-panel`)

The crawl of `/app/search` captured the home page under the search overlay; its APIs are the search sources
(`MenuAccess/TeamAction`, `MenuAccess/globalAction`, `report/Master`, `MenuAccess/AvailableSearch`,
`workforce/import/ImportList`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header box "Search for actions, pages, requests, reports, people…" | One search for everything | Command Center (answers, actions, people, navigation, requests, knowledge, documents, organisation, workflows & reports, HR services), ordered by role lens | HAVE | — |
| 2 | Org-chart button beside the search box | Open the org chart | Organisation map | HAVE | — |
| 3 | "search in" scope selector (All, actions, imports, …) | Restrict the search | Modes All / Actions / People | PARTIAL | RQ-13: add Requests, Reports, Imports scopes |
| 4 | "Search by Page Name" | Find a screen | Navigation group (`ModuleCatalogue`) | HAVE | — |
| 5 | People search "by name or code or designation or email id" | Find a person | Name, preferred name, employee code, work email (`PeopleVisibility::matchName`); not designation | PARTIAL | RQ-13: designation match |
| 6 | Filters: Organization, Location, Employee, Type of Manager; Clear all / Apply; "Filters applied : … More…" | Narrow people results | — | MISSING | RQ-13: organisation / location / manager-type filters on the People group |
| 7 | "Recent Searches" | Recent queries | "Recent" group (recent items opened) | HAVE | — |
| 8 | "View All Searches" | Every searchable list of HROne ("All Searches") | Navigation group | PARTIAL | — |
| 9 | Results "All actions" / "actions" | Start an action | "Actions" group (Quick actions) | HAVE | Coverage of request types: RQ-1 |
| 10 | Results "All imports" / "imports" | Find an upload screen / template | Uploads are not searchable | MISSING | RQ-13: `ImportTypes` the viewer may work on as a search group |
| 11 | Result tabs "Records" / "Reports" | Records vs reports | "Workflows & reports" and "Records" groups | HAVE | — |
| 12 | Report view inside search: Select module, Select report, Create view, Edit filter, "You do not have access for reports" | Run a report from search | Report library and reports (HR); opening from search goes to the report | PARTIAL | — |
| 13 | Module-wise reports: Reports, History, Report name, Download (.pdf / .xlsx), Open, SHOW MORE | Download reports | Reports export to CSV only (`ReportExports`) | PARTIAL | RQ-13: XLSX export (and PDF with the BP-14 PDF-library decision) |
| 14 | Download history (`app-recent-downloads`): View filters, Run now, Regenerate, Cancel, Delete, Completed time, Schedule time, Status, View name, Report name, "Last three months reports requested by you are visible", Refresh | The person's report runs | Report runs are recorded (`ReportRun`) and schedules exist; no personal download history screen | PARTIAL | RQ-13: "My downloads" (last 3 months of own runs, re-run, delete file) |
| 15 | Password-protected report ("Enter password to view report", Password / Confirm password) | Protect sensitive downloads | — | MISSING | Blocked with BP-14 (PDF / file protection library decision) |
| 16 | Search home "Recent actions" / "View Processes" | Shortcuts before typing | Idle state: Recent, then Suggested actions | HAVE | — |
| 17 | "Important Alert" (e.g. "Your leave cycle is ending soon … apply for the carry-forward … Carry Forward Leave") | Time-bound prompts | Needs attention / role signals on Home and My work | PARTIAL | RQ-13: show the viewer's top signals in the search idle state |
| 18 | Alert "Enable ESS lock to complete your goal cycle" (Enable ESS Lock) | Suggest the request lock to push goal completion | Employee request lock exists (P4); no prompt | MISSING | RQ-13: signal when a goal cycle has overdue tasks and the lock is off (HR only) |
| 19 | Alert "Biometric sync failure … View sync status" | Device sync problems | Silent-device alerts (TO-8) | HAVE | — |
| 20 | Errors panel (`app-insight-error`: error list, Ignore, Selected) | Integration / process errors | Inbound events and integration errors screens | PARTIAL | — |
| 21 | Apps list ("These apps will take you to new page") | Module launcher | Navigation / All modules | HAVE | — |
| 22 | Subscription notices (HR Desk suspended for non-payment, invoice acceptance overdue, subscription payment overdue) | Vendor billing | Platform billing is separate (SaaS.7) | N-A | Vendor-side |
| 23 | "HR Impact Wrapped", IMU box, HR Commune, newsletter subscription (official / personal email, WhatsApp, in-product announcement), Join LinkedIn | Vendor community and marketing | — | N-A | Vendor marketing |
| 24 | Quick hacks ("Which new process do you want to implement?", request support team, tasks, hours saved, average time) | Vendor services request | — | N-A | Vendor-side |
| 25 | Update contact details dialog (`app-contact-integration-dialog`) | Vendor contact upkeep | — | N-A | Vendor-side |
| 26 | Person result → profile with "All Searches" for that person (`app-profile`: leave, AR, OD, RH, short leave, comp-off, overtime, shift, encashment, advances, receipts, travel, tickets, assets, goals, PIP, payslip, CTC, calendar, holidays, leave balance, badges, Wi-Fi, geo locations, positions) | Everything about one person from search | People result actions: open (Employee 360), preview, timeline, org; Employee 360 tabs | PARTIAL | RQ-13: "Requests" tab on the person preview from the RQ-1 read model, gated per type |

## 20.4.2 Home page shown at `/app/search` and `/app/socialwall/search` (tabs Vision / Mission / Purpose)

`/app/socialwall/search` adds the social-wall calls (wishes, upcoming events for the wall, wall-of-fame rank). Field-level
parity of these cards sits with the Engage matrix (`docs/hrone-parity/setup/12-performance-engage.md`); rows here record
what the page shows.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Greeting "Hello, <first name>! Hope you are having a great day" | Welcome | Home hero greeting and brief | HAVE | — |
| 2 | "Profile – N% Completed" | Profile completeness | Onboarding profile sections (CA-4) as tasks; no percentage on Home | PARTIAL | RQ-13 (Home): completeness from `ProfileSections` |
| 3 | Quick buttons Post / Badge / Reward / Event / More | Start social actions | Wall post, give badge, reward points exist (HP-9c/d/e); no events | PARTIAL | Events: Engage matrix |
| 4 | Highlights — Today's celebration (N celebrations, birthday, wish) | Celebrations with wishes | Birthdays / anniversaries card, wish actions | HAVE | — |
| 5 | Wall of fame — Badge received this week, See more | Recognition | Wall-of-fame card | HAVE | — |
| 6 | Team planned leaves — See more | Team leave | Team card / leave calendar (managers) | PARTIAL | RQ-4 privacy flag applies |
| 7 | Referrals / IJP (Refer Talent, IJP Apply, Referral Refer, All Referrals/IJP) | Internal jobs and referrals | — | N-A | Recruitment |
| 8 | People on leave today — See more | Who is away today | My team summary (managers) | PARTIAL | RQ-11 |
| 9 | Core values | Organisation values | Mission statement record (HP-9b) | HAVE | — |
| 10 | Mission statement — Vision / Mission / Purpose tabs | Mission | Mission card (vision, mission, purpose) | HAVE | — |
| 11 | Upcoming Events | Events | — | MISSING | Engage matrix (events) |
| 12 | Happiness Index: Terrible / Poor / Good / Great / Excellent, "What went Good?", reasons Other / Growth / Colleagues / Facilities / Management / Appreciation, Comments, Anonymous feedback, SUBMIT | Mood check-in | Mood check-in (HP-9f, reasons, privacy) | HAVE | — |
| 13 | Feed | Social wall | Wall card / Social wall page (HP-9e) | HAVE | — |
| 14 | Inbox — N Pending tasks | Inbox entry | Decisions card | HAVE | — |
| 15 | Calendar (Go to calendar; legend Today, Present, Leave, Absent, Holiday; "Raise request for": Apply leave, Attendance regularization, On duty, Late count) | Month view with request shortcuts from a day | "Your day" card (check in / out, payslip); Attendance calendar (read-only) | PARTIAL | RQ-3: request shortcuts on a calendar day |
| 16 | Leave balance ("Oops! You have no leave balance.") | Balances | My day leave balances | HAVE | — |
| 17 | Tasks for today — See All, "Start making your to do list!", Create to do list | Personal to-do list | — (only assigned tasks) | MISSING | RQ-13 (Home): personal to-dos in `ExperiencePreferences` (private, no audit needed) |
| 18 | Goals (period; "No Goals Created"; Create Goals) | Own goals | Goals list; Home "For you" when goals are due | PARTIAL | — |
| 19 | Initiatives ("No initiatives assigned") | Own initiatives | — (HP-8j) | MISSING | After HP-8j |
| 20 | Open positions (count) | Internal openings | — | N-A | Recruitment (IJP) |
| 21 | Yesterday's attendance: Total working hours, In-time, Out-time | Yesterday's punches | "Your day" (today); attendance calendar | PARTIAL | RQ-13 (Home): yesterday line on the day card |
| 22 | Wishes (social wall: all wishes), Wall-of-fame rank detail | Social wall data | Wishes and wall of fame | HAVE | — |

---

## Counts

Rows of the tables above (one row per HROne element; the inbox task-type table has one row per task view plus the
"Leave cancel" category).

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 20.1 Request (flyout, shared chrome, 17 request forms, flyout-only types, history lists) | 246 | 96 | 98 | 50 | 2 |
| 20.2 Inbox (page, filters, actions, settings; 78 task types) | 119 | 46 | 40 | 26 | 7 |
| 20.3 Team (people, team actions, analytics, reports, position management) | 97 | 39 | 37 | 18 | 3 |
| 20.4 Search and social wall search (global search, home page under it) | 48 | 19 | 16 | 7 | 6 |
| **Total** | **510** | **200** | **191** | **101** | **18** |

By form: Resignation 16 (3 / 6 / 7 / 0), Loan 13 (6 / 4 / 3), Reimbursement 12 (6 / 2 / 4), Investments 20
(6 / 11 / 3), Restricted holiday 5 (2 / 3 / 0), Attendance regularization 13 (6 / 4 / 3), Short leave 8 (4 / 4 / 0),
Helpdesk 7 (4 / 3 / 0), Expense advance 11 (7 / 2 / 2), Expense 13 (4 / 4 / 5), Change goal 7 (4 / 1 / 2), Variable 9
(3 / 4 / 2), Travel 19 (10 / 7 / 2), Asset 9 (5 / 2 / 2), Leave + encashment + on duty + change shift 38
(16 / 14 / 8), history lists 21 (8 / 13 / 0) — figures are HAVE / PARTIAL / MISSING.

Most PARTIAL rows in 20.1 are fields PeopleOS validates but does not show (balances, quotas, eligibility) or flows it
supports one item at a time (one date, one asset, one component, one employee per request).

## Biggest gaps

1. **No request front door.** HROne has one "New Request" list of 20 types with search and favourites, and one
   Pending / Approved / Rejected / Undo / Drafts tracker across all of them. PeopleOS spreads entry points over module
   lists, My day, My HR and five Quick actions; "Waiting on others" covers only leave, regularisations, tickets, letters
   and compensation proposals (20.1.0, 20.1.19).
2. **No route preview or requester-side tracking.** Nothing shows who will approve before submitting ("View workflow"),
   and the requester cannot see each level's response and turnaround, send a reminder, or notify colleagues
   (20.1.1 rows 2, 6, 8, 9).
3. **Inbox navigation.** No per-type categories with counts, no filters (employee, dates, leave type, category, OU), no
   search, no relative due labels, no "Insight" (tasks completed, on-time %, delayed %) (20.2.1 rows 4–14).
4. **Attendance requests are one-date forms.** No calendar multi-date selection with per-date rows (AR in / out dates,
   several on-duty slots a day, shift and weekly off per date), no balances (AR, OD, short leave, RH) shown, no warning
   for weekly offs / holidays / leave, no punches beside the form (20.1.7–20.1.9, 20.1.18).
5. **Workflow decision options.** No ad-hoc "Add approver", no send back to an earlier approver, no one-task delegate
   for any approver, no "missed to respond" skip when a level's turnaround passes (20.1.1 row 10, 20.2.1 rows 18, 19,
   29).
6. **Team workspace for managers.** Direct reports only; no indirect / functional team, saved filters, column choice or
   bulk team actions (assign holiday, mark attendance, change shift, OT / comp-off); team calendar is leave-only; no
   team reports (the manager role has no analytics permission) (20.3.1, 20.3.3, 20.3.4).
7. **Resignation request.** No contact-verification step, no resignation date or policy notice / LWD shown (the modal
   shows the global notice days, not the policy's), no LWD adjustment to a working day, no notice-recovery preview, no
   attachment; acceptance happens only through a workflow, not in the Approval Center (20.1.2, 20.2.2 row 26).
8. **Inbox task kinds PeopleOS lacks.** Work-plan (WFH) request and approval, "claim your reimbursement" reminders,
   joiner tasks (declare statutory details, assign salary structure), declare-investment prompts, letter
   acknowledgement; confirmation reviews, settlements and tax-declaration verification are not Approval Center items
   (20.2.2).
9. **Payroll requests.** Loans: no required-by date, attachment or guarantee terms / signed consent. Reimbursements:
   no multi-component submission, no LTA leave link, no FY paid / submitted totals. Variable pay: no multi-employee
   rows for managers, open-month select, eligibility / paid totals, attachment or approver amount edit
   (20.1.3, 20.1.4, 20.1.15).
10. **Expense capture tools and projects.** No receipt parsing, clone, split, bulk grid, Excel import or bulk edit; no
    projects anywhere (advance, expense, travel, on duty, timesheets, project / milestone approvals) (20.1.12, 20.2.2
    rows 69–72).

Also notable: team position management for hiring managers (hiring manager, hiring type, floating positions, clone,
saved views, position import; 20.3.5), investment-declaration details (rent periods by date and city, lender details for
24B, copy last year's declaration; 20.1.5), multi-asset and temporary asset requests (20.1.17), and search scopes,
filters, imports and download history (20.4.1).

---

## Build packages (ordered)

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **RQ-1 Request registry, "New request" and "My requests".** Contract `RequestType` (key, label, icon, module, permission, `availableTo($employee)` through the module's policy, form action, `onBehalf` permission, own-records query, status map to Pending / Approved / Rejected / Withdrawn / Draft, history columns and date filters) tagged `peopleos.request_types`; one adapter per module (leave, encashment, time office ×7, regularisation, loan, claim, variable pay, advance, expense report, travel, asset, ticket / service, tax declaration, proofs, resignation, goal plan, form submissions). "New request" page and rail flyout (search, favourites in `ExperiencePreferences::favourite_requests`), "My requests" page (tabs by status, per-type facets, date-range filters, hide closed), every type registered as a Quick action, `WorkInbox::waiting` read from the registry, HR bulk cancel where the module already cancels. Defaults: existing screens unchanged | 20.1.0 #1–8; 20.1.1 #7, #11, #15; 20.1.8 #10; 20.1.18 Leave #11, On duty #11; 20.1.19 #1, #3–10, #18, #21; 20.2.1 #39; 20.3.1 #16; 20.3.2 #9, #24 | `app/Domain/Experience/Contracts/RequestType.php` (new), `app/Domain/Experience/Services/RequestRegistry.php`, `MyRequests.php` (new read model), adapters under each module's `Support/`, `ParityServiceProvider`, `app/Filament/Pages/NewRequest.php`, `MyRequests.php` (new), `QuickActions.php`, `ExperiencePreferences.php`, `WorkInbox.php`, `AdminPanelProvider` (navigation) | — | L |
| 2 | **RQ-2 Request chrome.** Shared request header; "Who approves this" preview (`RequestWorkflows::preview`: first matching workflow by P5 priority, approval nodes through `ApproverResolver`, else the module's built-in approver; intimation from notification rules); "Read the policy" link per type (kb category); confirmation panel (raise another / view my requests); requester timeline (submitted, each decision / send back / reassign from audit and `workflow_logs`, waiting with, due); "Send reminder" (`request.reminder`, min hours setting, default 24); "Notify others" (`request.intimated`, ≤ 500 chars, per-type setting `requests.notify_others.types`, empty = off); Discussion entry from the timeline (existing `TaskComments`) | 20.1.1 #1–3, #5, #6, #8, #9, #12; 20.1.2 #15, #16; 20.1.3 #11; 20.1.4 #12; 20.1.11 #9; 20.1.15 #8; 20.1.18 Leave #5; 20.2.1 #20, #26, #30, #40 | `app/Domain/Workflow/Support/RequestWorkflows.php`, `ApproverResolver`, `app/Filament/Support/RequestChrome.php` (new), `app/Domain/Experience/Services/RequestTimeline.php` (new), `config/peopleos.php` (settings, notification events), `config/peopleos_setup.php` (Automation group "Requests"), `RequestDiscussion.php` | RQ-1 | M |
| 3 | **RQ-8 Unified inbox.** One inbox over Approval Center items and employee / workflow tasks: type facets with counts and side search, quick filters (overdue, due today, due tomorrow, missed by me, relieved employees), filter panel (employee, requested-at range, leave type, ticket category, travel request, organisation unit), keyword search, relative due labels, list + detail with previous / next, tick-and-archive, "Not relevant" for system tasks (reason, audited), "More" launching give badge / reward points / schedule one-on-one, profile peek, approver "Insight" (decided count, on-time %, delayed % for a period). Approvals and Task inbox keep working | 20.2.1 #1, #4, #5, #7–12, #14, #21–24, #27, #32, #33 | `app/Filament/Pages/Approvals.php` (or a new `Inbox.php` over it), `resources/views/filament/pages/experience/approvals.blade.php`, `ApprovalCenter.php` (facets, filters), `ApprovalItem` (due label, employee facts), `app/Domain/Experience/Services/InboxInsight.php` (new), `TaskInbox.php` | RQ-1 (facet labels), RQ-2 (timeline in the card) | L |
| 4 | **RQ-9 Workflow decision options.** `WorkflowEngine::addApprover` (ad-hoc approval node after a chosen level, this run only; permission `task.add_approver`), send back to an earlier approver of the run (that step reopens, then returns to the sender), one-item delegation for Approval Center items (`task_delegations.item_id`, `task.delegate`), escalation action `skip` (mark the task missed and move on; accepted on publish only where a later approval exists). Defaults off / unchanged | 20.1.1 #10; 20.2.1 #18, #19, #29 | `app/Domain/Workflow/Services/WorkflowEngine.php`, `WorkflowDefinition`, `TaskDelegations.php`, `ApprovalDecisions`, migration (`task_delegations.item_id`, ad-hoc node log), `config/peopleos.php` (permission, escalation actions) → `peopleos:sync-permissions` | — | M |
| 5 | **RQ-3 Calendar picking for attendance requests.** Month picker reusing `AttendanceCalendar` day states (absent / half-day absent / today) for AR, short leave, on duty, change shift and leave; per-date rows (AR: type, in date, out date for night shifts; OD: one or more time slots a day; shift change: shift and / or weekly off per date with copy-to-all, add next day, remove); balances shown ("N of M left") from the existing limit reads; soft warning for weekly offs / holidays / leave days; day drawer with own punches and day result; optional work mode / location per date (setting `attendance.regularisation.ask_location`, default off); request shortcuts from a calendar day (home and attendance calendar) | 20.1.8 #1, #3, #5, #8, #9, #11; 20.1.9 #1, #4, #5, #7; 20.1.18 On duty #1, #3, #4, #6, #9, #10, Change shift #1, #2, #4, #5, #7; 20.1.12 #13; 20.4.2 #15 | `TimeOfficeActions.php`, `MyDay.php`, `Home.php`, `AttendanceCalendar.php` (picker component), `Regularisations.php` (multi-date in one transaction, out date), `TimeOfficeRequests.php` (day rows / slots: new `time_office_request_days`), `RequestRules`, attendance policy fields, migrations | — | L |
| 6 | **RQ-4 Leave and encashment forms.** Private leave flag (`leave_requests.is_private`, default false; calendars and home cards show "Away" without type), comp-off source dates, team-away panel for the chosen dates (calendar scope rules), encashment balance helper and attachment (required when the leave type's encashment rule says) | 20.1.18 Leave #8–10, Encashment #3, #5; 20.4.2 #6 | `LeaveActions.php`, `Leaves.php`, `LeaveCalendar.php`, `resources/views/filament/pages/home/team.blade.php`, `ListMyLeaveEncashments.php`, `LeaveEncashments` service, migrations | — | M |
| 7 | **RQ-5 Payroll requests.** Loans: `required_on`, attachments, loan-policy guarantee terms document and signed-copy switch, "your open loans" panel. Claims: several component rows in one submission, FY paid / bills-already-submitted helpers, summary table, salary-component flag `claim_needs_leave` linking an approved leave (default off). Variable pay: rows of (employee, component, amount) for the requester's reports, pay-month select from `PayCycle`, attachments, FY eligibility / paid, approver amount edit (policy switch, default off) | 20.1.3 #4, #6, #8, #10; 20.1.4 #2, #5, #8–10; 20.1.15 #1, #2, #6, #7, #9; 20.2.2 #19 | `ListLoans.php`, `Loans.php`, `LoanGuarantor`, `ListReimbursementClaims.php`, `ReimbursementClaims.php`, `ListVariablePayRequests.php`, `VariablePay.php`, `VariablePayRequestResource.php`, `config/peopleos.php` (loan policy fields), migrations | RQ-2 (timeline rows) | M |
| 8 | **RQ-6 Resignation.** "Resign" as a short wizard: optional contact check (setting `exit.resign.verify_contact`, default off; changes through the profile-change service), resignation date when the policy allows backdating, policy notice / LWD shown (fixes the global-notice text), proposed LWD with shortfall days, attachment; exit policy field `lwd_on_non_working_day` (blank = keep). Approval Center source "Resignation" (accept with relieving date, recovery relaxation days, rehire eligibility and blacklist reason; reject = withdraw), used when no workflow runs on resignations | 20.1.2 #1–8, #10, #11, #14; 20.2.2 #26 | `ExitActions.php`, `Exits.php`, `app/Domain/Experience/Sources/ResignationApprovalSource.php` (new), `ParityServiceProvider`, `config/peopleos.php` (exit policy field, setting), `config/peopleos_setup.php`, migration (`exit_cases` attachment) | — | M |
| 9 | **RQ-7a Other request forms.** Restricted holiday "taken N of M" helper and approver facts; "Ask HR" sub-category (category's services) and attachment; advance preferred mode and employee advance ledger; travel passport expiry, trip type on journeys, nearby locations, guests, preferred return / check-out times, review step; asset request lines (several assets, "Both" = return + new), period permanent / temporary with return date, multi-asset return | 20.1.7 #2, #3, #5; 20.1.10 #2, #5; 20.1.11 #6, #8; 20.1.16 #6, #9–11, #13, #15, #17; 20.1.17 #1, #4, #5, #7 | `TimeOfficeActions.php`, `TimeOfficeApprovalSource.php`, `ServiceDeskActions.php`, `ListExpenseAdvances.php`, `ExpenseAdvances.php`, `TravelRequestForm.php`, `TravelRequests.php`, `ListAssetRequests.php`, `AssetRequests.php`, migrations (`expense_advances.preferred_mode`, travel leg fields, `asset_request_lines`) | — | M |
| 10 | **RQ-7b Investment declaration details.** Start from last year's declaration; first affected payroll month; label map per tax-rule version ("new tax terminology", statutory data); regime-change approval switch (default off); rent periods (from, to, monthly rent, city with metro flag from statutory data), landlord relationship and "PAN not available"; 80D self / parents split with limits from rule data; 24B lender details; undertaking checkbox (tenant text); estimator breakup and printable slip; required remark when someone else edits; bulk proof download per declaration. PAN verification stays a seam (provider) | 20.1.5 #1–3, #5–7, #10, #12–15, #18, #20; 20.1.19 #19 | `DeclareInvestments.php`, `InvestmentCatalogue.php`, `InvestmentDeclarations`, `TaxEstimator`, `TaxSettings`, `MyInvestmentProofResource.php`, `TaxDeclarationResource.php`, statutory rule data, migrations | — | M |
| 11 | **RQ-7c Expense capture tools.** Receipt "Claim reimbursement" toggle, pre-approval code and SAC / HSN on the form; clone (n ≤ 30) and split (date range, n receipts); bulk grid; Excel import on the staged pipeline (P2 governance); bulk edit of selected receipts; `ReceiptParser` contract with a null implementation (provider is an owner decision) | 20.1.12 #2, #3, #7–10 | `ExpenseReceiptForm.php`, `ListExpenseReceipts.php`, `ExpenseReceipts.php`, `app/Domain/Expense/Imports/*` (new type), `ImportTypes.php`, `app/Domain/Expense/Contracts/ReceiptParser.php` (new) | — | M |
| 12 | **RQ-10 New inbox task kinds.** Work-plan request type and approval (time-office policy section "Work plan", default off); comp-off / OT verification switch; "claim your reimbursement" task (setting `payroll.claims.remind`, default off); "declare your investments" task while the window is open; joiner tasks for missing statutory details and salary structure; letter acknowledgement (template flag); Approval Center sources for confirmation reviews due, settlements due, and tax declarations / proofs awaiting verification | 20.2.2 #9, #10, #13, #14, #18, #20–23, #25, #27, #30 | `TimeOfficeRequest` (type), `TimeOfficeApprovalSource.php`, `config/peopleos.php` → `experience.task_sources` (new sources), new sources under `app/Domain/Experience/Sources/`, `Letters`, `ParityServiceProvider`, migrations | RQ-1 (registry entry for work plans) | L |
| 13 | **RQ-11 Team workspace.** Team page: scope all / direct / indirect / functional, column choice, saved filters, OU filter, multi-select bulk actions over existing services (mark attendance with optional approval, change shift over `ShiftOverrides` with setting `team.shift_assign`, assign / remove holiday with `team.holiday_assign`, OT / comp-off on behalf, leave-balance adjust for holders of that permission, team announcement), member YTD attendance; team calendar (leave, holidays, time-office requests, celebrations, trips; process and people filters; per-person timeline); team geo list view (map blocked); Team reports (`team_safe` report templates under the manager's scope, permission `analytics.team` in the manager template) | 20.3.1 #1, #2, #4–13; 20.3.2 #6, #26, #37; 20.3.3 #1–4, #9; 20.3.4 #1, #2; 20.4.2 #8; 20.2.2 #11 | `MyTeam.php` (or `Team.php`), `LeaveCalendar.php` → team calendar, `ShiftOverrides`, `AttendanceOverrides`, `HolidayResolver`, `ReportLibrary.php` (template flag), `config/peopleos.php` (permission, manager template, settings) → `peopleos:sync-permissions`, `config/peopleos_setup.php` | RQ-1 (on-behalf forms) | L |
| 14 | **RQ-12 Team position management.** Hiring-manager lens (`hiring_manager_id` on position versions) next to the reporting lens; search, saved views, filters over existing fields; occupant and parent columns; managers propose positions (approved by `workforce.approve`); hiring type master, salary range (cost permission applies), floating positions with "assign actual position", clone N, position upload type (P2 pipeline), chart PDF; position-request approval in the Approval Center | 20.3.5 #1–5, #7, #9, #11, #14, #17, #21–24; 20.2.2 #51 | `TeamWorkforce.php`, `PositionResource.php`, `app/Domain/Workforce/*` (position versions, services, approval source), `ImportTypes.php`, migrations | RQ-11 (saved views store) | L |
| 15 | **RQ-13 Search and home additions.** Search scopes Requests / Reports / Imports; designation in people search; organisation / location / manager-type filters; uploads as a search group; XLSX report export (PDF with the BP-14 decision); "My downloads" (own runs, last 3 months, re-run, delete); top signals in the idle state; HR prompt to switch on the request lock for an overdue goal cycle; person preview "Requests" tab from RQ-1; Home: profile completeness, personal to-dos, yesterday's attendance line | 20.4.1 #3, #5, #6, #10, #13, #14, #17, #18, #26; 20.4.2 #2, #17, #21 | `CommandSearch.php`, `CommandCenter.php`, `ReportExports.php`, `app/Filament/Pages/MyDownloads.php` (new), `RoleSignals.php`, `Home.php`, `ExperiencePreferences.php`, `ProfileSections` | RQ-1 | M |

Not packaged here (owned by other matrices or blocked): projects and timesheets (advance / expense / travel / on-duty
project fields, timesheet and project / milestone approvals — blocked on a projects module); map distance and maps
(CA-8 map provider); PAN verification and receipt OCR providers (external); password-protected downloads (BP-14);
events, polls, 360 nominations and initiatives (Engage HP-9 / Performance HP-8h, HP-8j); contracts (BP-16); FBP
(BP-7); goal-sheet tools (copy from team member, last approved, AI) and feedback-template mapping (Performance matrix);
loan servicing (skip / restructure EMIs) and interest types (08-workforce-payroll BP-9); recruitment rows (N-A).

---

## Re-check against the permission tree and live captures (2026-10-29)

Written after the crawl's `rail`, `menu` and `myspace` passes landed. Analysis only; no personal data copied (the
captures show real names and codes; only labels, counts of reports and structure are used here).

**Inputs for this section.** `hrone-research/menu-permission-tree.md` and its raw form in `menu-catalogue.json`
(`MenuAccessPermission/0`, which also gives each node's grantable rights) for the branches Apps → Shortcuts, Home →
Enterprise wall, Inbox, Request → New request [23], Team [5], Smart search, Dashboard and Studio; `menu-catalogue.json` →
`/api/core/MenuAccess/globalAction/...` (68 HR Desk global actions); `pages.jsonl` lines `rail_Inbox*` (11),
`rail_Request*` (4), `rail_Team*` (5), `menu_Team__*` (8), `route_app_team_team_reports_search*` (3) and the request
histories among `myspace_*`; page text under `hrone/deep/<id>/`; `components.json` for what the captures point at
(`app-wcr-mark-attendance`, `app-wcr-attendance-clock`, `app-mark-attendance-detail`, `app-attendance-location-view`,
`app-trip-details-drawer`, `app-employee-trips`, the 41 `app-initiated-*` views, `app-shortcut-pins`,
`app-opf-shortcuts`, `app-lego-inbox*`, `app-lego-initiated-requests`, `app-analytic-dashboard-home`,
`app-team-calendar*`, `app-team-geo-dashboard`, `app-list-view`, `app-overview-team`, `app-peoples`,
`app-my-team-action`). PeopleOS re-checked in code: `MyTeam.php`, `Approvals.php`, `TaskInbox.php`, `MyWork.php`,
`Home.php` (+ `home/day.blade.php`, `home/requests.blade.php`), `ApprovalCenter.php` (leave items), `WebPunch.php`,
`ListLocationRequests.php`, `ListLeaveRequests.php`, `ListTimeOfficeRequests.php`, `ServiceDeskActions.php`,
`ListTravelRequests.php`, `ListExpenseReceipts.php`, `ListAssetRequests.php`, `ListVariablePayRequests.php`,
`ExitActions.php`, `TransferRequests.php`, `ImprovementPlans.php`, `ExperiencePreferences.php`, `DashboardViewer.php`,
`ReportLibraryPage.php`, `NineBoxGrid.php`, `CalibrationBoard.php`, `InitiativeResource` (+ `ManageInitiatives` tabs),
`config/peopleos.php` (role templates, task permissions, `experience.task_sources`). Code merged since this matrix was
written (14:55): only HP-8g (calibration aids, 9-box) and HP-8j (initiatives); P3 / P4 / P5 inbox work and EX-F trips
were already credited above.

Rows in this section use the same columns and status key. Several rows restate, from the request / team angle, a status
that matrices 22–27 rate in detail; their build note names that matrix (22 such pointer rows).

### R.1 Coverage of every node and capture

| Node / capture | Matrix-20 row(s) before this re-check | Coverage now |
|---|---|---|
| Apps → Shortcuts {View} | — | New: R.6 #1–2 |
| Home → Enterprise wall → Engage {Badge, Points, Hr post, Hide post / delete comment, Event, Polls, Survey, Announcement} | 20.4.2 #3, #5, #13 | Pointer row R.6 #3 (rated in matrix 27 §27.16 #1–8) |
| Home → Enterprise wall → Knowledge base {Employee knowledge base} | — | Pointer row R.6 #4 |
| Home → Enterprise wall → Performance {1-on-1, Create an initiative, Continuous feedback} | — | Pointer row R.6 #5 |
| Inbox → Task → Action → All action {All action, All action 2, Ignore api, View form based on only request, Team, Employeeskillendorsecount} | — (20.2.1 #27 for "ignore") | New: R.3.1 #1–5 |
| Request → New request [23] (the list) | 20.1.0 #1 (20 types read from the flyout) | Deepened: 23 types with rights, R.2.1 |
| … Asset {Create} | 20.1.17 | Covered; rights R.2.1 #1 |
| … Attendance regularization {Create, Cancel request} | 20.1.8 | Covered; R.2.1 #2 |
| … Change goal {Create} | 20.1.14 | Covered; R.2.1 #3 |
| … Change shift {Create, Cancel request} | 20.1.18 "Change shift" | Covered; R.2.1 #4 |
| … Confirm invest {Create} | 20.1.5 / 20.1.6 | Covered; R.2.1 #5 |
| … Expense {Create, Undo request} | 20.1.12 | Covered; R.2.1 #6 |
| … Expense advance {Create, Cancel request} | 20.1.11 | Covered; R.2.1 #7 |
| … Helpdesk ticket {Create, Ticket reopen, Undo} | 20.1.10 | Covered; R.2.1 #8 |
| … Job requisition {Create} | 20.1.0 #9, 20.1.13 | Covered (N-A); R.2.1 #9 |
| … Leave {Create, Cancel request} | 20.1.18 "Leave" | Covered; R.2.1 #10 |
| … Leave encashment {Create, Cancel request} | 20.1.18 "Leave encashment" | Covered; R.2.1 #11 |
| … Loan {Create} | 20.1.3 | Covered; R.2.1 #12 |
| … Location approval {Create} | 20.2.2 #12 (approval side only) | New: R.2.1 #13, R.2.2 #1–7 |
| … Mark attendance {Create} | 20.2.2 #11 (meaning mis-inferred) | New: R.2.1 #14, R.2.3 #1–7; 20.2.2 #11 corrected (R.7) |
| … On duty {Create, Cancel request} | 20.1.18 "On duty" | Covered; R.2.1 #15 |
| … Propose invest {Create} | 20.1.5 | Covered; R.2.1 #16 |
| … Reimbursement {Create, Cancel request} | 20.1.4 | Covered; R.2.1 #17 |
| … Resignation {Create, Cancel request} | 20.1.2 | Covered; R.2.1 #18 |
| … Restricted holiday {Create, Cancel request} | 20.1.7 | Covered; R.2.1 #19 |
| … Short leave {Create, Cancel request} | 20.1.9 | Covered; R.2.1 #20 |
| … Start trip (no rights) | — | New: R.2.1 #21, R.2.4 #1–2 |
| … Travel {Create, Cancel request} | 20.1.16 | Covered; R.2.1 #22 |
| … Variable {Create, Cancel request} | 20.1.15 | Covered; R.2.1 #23 |
| Team → People → People {View} | 20.3.1 #1–19 | Deepened: R.4.1 #1–3 |
| Team → People → Action [9]: Payroll (6), Helpdesk (1), Time office (11), Workforce (2), Expense (3), Recruitment (2), Performance (7), Asset (2), Engage (1) | 20.3.1 #16 (one generic row) | New: R.4.2 #1–10 |
| Team → People → Team profile [9]: Workforce (6), Time office (18), Performance (11), Engage (1), Payroll (2), Recruitment (2), Asset (2), Helpdesk (1), Expense (3) | 20.3.2 #1–40 | Covered (each right maps to a 20.3.2 row); new rights R.4.3 #1–5; 20.3.2 #17, #20 changed (R.7) |
| Team → Team Analytics → Custom Analytics {View} | — | New: R.4.4 #1–2 |
| Team → Team Analytics → Timeline {View} | 20.3.3 #1–5 | Deepened: R.4.4 #3 |
| Team → Team Analytics → Goals {View} | 20.3.3 #6–8 | Deepened: R.4.4 #4 |
| Team → Team Analytics → Geo dashboard {View} | 20.3.3 #9 | Deepened: R.4.4 #5 |
| Team → Reports [7]: Time office 24, Workforce 14, Performance 21, Payroll 2, Recruitment 1, Expense 12, Engage 8 | 20.3.4 #1–3 (component not captured then) | New / deepened: R.4.5 #1–11 |
| Team → Imports [3]: Performance 6, Time office 5, Payroll 1 | 20.3.1 #17 | New: R.4.6 #1–3; 20.3.1 #17 changed (R.7) |
| Team → Analytics [4]: Time office 7, Workforce 1, Recruitment 1, Performance 2 | — | New: R.4.7 #1–11 |
| Smart search → Time office (5 rights) | 20.4.1 #1 (the search box) | Pointer row R.6 #6 (rated in matrix 24 §24.14) |
| Smart search → Performance (2 rights) | — | Pointer row R.6 #7 (rated in matrix 27 §27.15) |
| Dashboard (no rights; `app-main-dashboard`) | 20.4.2 #1–22 | Covered; R.6 #8 |
| Studio → Studio | 20.2.1 #8, 20.1.19 #21 | Deepened: R.5 #1–4 |
| `rail_Inbox`, `rail_Inbox__All` and the category captures `__55` (Confirmation), `__2214` (Create goal), `__215` (Declare statutory details), `__460` (Initiate settlement), `__1` (Leave cancel), `__2` (New announcement), `__222` (Relieving letter) | 20.2.1 #1–40; 20.2.2 #2, #22, #25, #27, #29, #52, #63 | Deepened: R.3.2 #1–2, R.3.3 #1–4; the rest confirmed as written |
| `rail_Inbox__Insight`, `rail_Inbox__On_time_*` ("Task completed", "On time %", "Delayed %") | 20.2.1 #7 | Covered (confirmed; still MISSING) |
| `rail_Request`, `rail_Request__Pending`, `__Rejected`, `__Drafts` (`/app/request/initiatedrequest`, API `Request/InboxRequest/Search`) | 20.1.0 #4–8, 20.2.1 #39 | Deepened: R.2.5 #1–4 |
| `rail_Team`, `menu_Team__People` (`/app/team/team-people`) | 20.3.1 | Deepened: R.4.1 |
| `rail_Team__Team_Analytics` (+ tabs Timeline, Goals, Geo dashboard), `menu_Team__Team_Analytics` (+ same tabs) | 20.3.3 | R.4.4 |
| `menu_Team__Reports` (+ Recent / Favorite tabs), `route_app_team_team_reports_search` (+ tabs) | 20.3.4 | R.4.5 #1–4 |
| `myspace_*` request histories: Asset request, Attendance regularization, Change shift, Compensatory off, Expense advance, Expense receipt, Expense report, Fbp approval history, Leave, Leave encashment, Loan history, Location approval, On duty, Overtime, Proposed / Confirmed investment history, Reimbursement history, Restricted holiday, Short leave, Travel, Variable history, Ticket | 20.1.19 #1–21 | Deepened: R.2.6 #1–6 (all but two were empty for the crawl user; the other `myspace_*` ids belong to matrix 22) |
| `globalAction` (68 HR Desk actions) | 20.1.1 #15 | New: R.2.7 #1 + cross-reference table (21 request-form actions); the other 47 actions are HR Desk operations rated in matrices 23–27 |

### R.2 Request — new and deepened rows

#### R.2.1 Request → New request [23]: the grantable request types and their rights (`MenuAccessPermission`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Asset {Create} | Raise an asset request | "Request an asset" (`asset.request`, Employee and Manager templates); own request cancellable while open | HAVE | Form: 20.1.17 |
| 2 | Attendance regularization {Create, Cancel request} | Raise / withdraw an AR | "Regularise attendance" (`attendance.regularise`); own pending AR cancellable | HAVE | Form: 20.1.8 |
| 3 | Change goal {Create} | Propose changes to an approved goal sheet | Changes to a mapped sheet become proposals (`performance.goals`, `GoalPlans::propose`) | HAVE | Form: 20.1.14 |
| 4 | Change shift {Create, Cancel request} | Shift / weekly-off change | Time-office "Shift change" / "Week-off change"; cancel, cancellation request when approved | HAVE | Form: 20.1.18 |
| 5 | Confirm invest {Create} | Year-end proofs | My investment proofs (proof window; `payroll.payslip` holders) | HAVE | Form: 20.1.6 |
| 6 | Expense {Create, Undo request} | Receipts and reports; withdraw a submitted report | Add expense / New expense report (`expense.claim`); report "Cancel" (`ExpenseReports::cancel`) | HAVE | Form: 20.1.12 |
| 7 | Expense advance {Create, Cancel request} | Advance; withdraw | "Request an advance" (`expense.claim`); cancel / cancellation | HAVE | Form: 20.1.11 |
| 8 | Helpdesk ticket {Create, Ticket reopen, Undo} | Ticket; reopen a closed one; withdraw | "Ask HR" / catalogue "Request" (`servicedesk.request`); `ServiceDesk::reopen`, `ServiceDesk::cancel` | HAVE | Form: 20.1.10 |
| 9 | Job requisition {Create} | Requisition | — | N-A | Recruitment (separate RMS) |
| 10 | Leave {Create, Cancel request} | Leave; cancel / ask to cancel | "Apply leave" (`leave.apply`); cancel pending, cancellation request when approved | HAVE | Form: 20.1.18 |
| 11 | Leave encashment {Create, Cancel request} | Encashment; withdraw | "Request encashment" (`leave.apply`), withdraw | HAVE | Form: 20.1.18 |
| 12 | Loan {Create} | Loan / salary advance | "Request a loan or advance" (`payroll.request`) | HAVE | Form: 20.1.3 |
| 13 | Location approval {Create} | Ask for a place to count as a check-in location | Location requests → "Request a location" (`attendance.regularise`); withdraw while pending | HAVE | Form rows R.2.2 |
| 14 | Mark attendance {Create} | Web check-in / check-out raised as a request (approved when the check-in policy says) | Check in / out on Home and My day (`WebPunch`, check-in policy applies); never a request, never approved | PARTIAL | Rows R.2.3; RQ-14 |
| 15 | On duty {Create, Cancel request} | On duty; withdraw | Time-office "On duty"; cancel / cancellation request | HAVE | Form: 20.1.18 |
| 16 | Propose invest {Create} | Declaration | Declare investments wizard | HAVE | Form: 20.1.5 |
| 17 | Reimbursement {Create, Cancel request} | Claim; withdraw | "Claim a reimbursement" (`payroll.request`); cancel | HAVE | Form: 20.1.4 |
| 18 | Resignation {Create, Cancel request} | Resign; withdraw the resignation | "Resign" (`exit.resign`); "Withdraw resignation" (`Exits::withdraw`) | HAVE | Form: 20.1.2 |
| 19 | Restricted holiday {Create, Cancel request} | RH; withdraw | Time-office "Restricted holiday"; cancel | HAVE | Form: 20.1.7 |
| 20 | Short leave {Create, Cancel request} | Short leave; withdraw | Time-office "Short leave"; cancel | HAVE | Form: 20.1.9 |
| 21 | Start trip (no rights below it) | Start a tracked trip whose distance feeds a mileage expense *(inferred from `app-employee-trips`, `app-trip-details-drawer` and the "expense live trip" licence)* | — | MISSING | Rows R.2.4 |
| 22 | Travel {Create, Cancel request} | Trip; withdraw | "New trip" (`expense.claim`); cancel / cancellation request | HAVE | Form: 20.1.16 |
| 23 | Variable {Create, Cancel request} | Variable pay; withdraw | "Request variable pay" (`payroll.request`); cancel | HAVE | Form: 20.1.15 |
| 24 | Each type's rights granted separately: Create vs Cancel request / Undo request / Ticket reopen | A role may raise a type without being allowed to withdraw it (or the reverse) | One module permission covers raising; withdrawing one's own request is always open under the module's rules | PARTIAL | RQ-1 extension: registry fields `raise_permission` / `cancel_permission` (null = today's module rule); no new permission keys unless a tenant needs them |

#### R.2.2 Request → New request → Location approval (`app-initiated-location-approval` → `app-attendance-location-view`; approver `app-location-inbox`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Place taken from the device: Source (Web / Mobile), Coordinates, Address, IP address | Ask to approve the place where the employee stands | "Request a location": typed Place, Latitude, Longitude, Radius (20–20,000 m, default 200), Address; no "use my position", no source or IP kept | PARTIAL | RQ-14: "Use my current location" fills latitude / longitude from the browser (as `WebPunch` does); source and IP stored on the request |
| 2 | "You want to get location approval for — Forever / Specific (Days)" | Permanent or temporary place | "Needed from" / "Needed until" within the check-in policy's maximum (TO-2) | HAVE | — |
| 3 | Remarks | Why | "Reason" (required) | HAVE | — |
| 4 | Photos | Photo evidence of the place | No photos on location requests | MISSING | RQ-14: optional photo (private disk) when the check-in policy asks for photos |
| 5 | City, Office, Work mode | Where and how the employee works from that place | — | MISSING | With SV-18 hybrid work (matrix 13); RQ-14 adds the fields once SV-18 lands |
| 6 | Map / "View on map" / polygon preview | See the place | — | MISSING | Blocked: map provider (CA-8) |
| 7 | Approver card: request facts, Activity log, approver Comments, due badges | Decide | Approval Center `location_request` (approve / reject with note); Location requests list | HAVE | 20.2.2 #12 |

#### R.2.3 Request → New request → Mark attendance (`app-wcr-mark-attendance`, `app-wcr-attendance-clock`, history `app-initiated-mark-attendance`)

The approver side (`app-mark-attendance-inbox` → `app-mark-attendance-detail`) is the approval of a web / mobile
check-in, rated in matrix 24 §24.3.1 (TX-10); 20.2.2 #11 is corrected accordingly (R.7).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Clock: "Last punch" / "Last check in time", Check in / Check out, "Clocked in" / "Clocked out" | Web clock | "Your day" card on Home and My day: "Checked in at", "Checked out at", Check in / Check out (check-in policy: location, devices, IP, windows) | HAVE | — |
| 2 | "Hours worked" | Time worked so far today | Not shown (worked time exists only after processing) | MISSING | RQ-14: hours since the first check-in today on the day card |
| 3 | "Write your remark" | Note with the check-in | Punches can hold a note; web check-in never asks | PARTIAL | RQ-14: optional remark (setting `attendance.web_punch.ask_remark`, default off) |
| 4 | Work mode, Select city, Select office | Where the employee works today | — | MISSING | SV-18 + RQ-14 (matrix 24 §24.3.1 #9) |
| 5 | Source | Web or mobile | Punch source `web` / app recorded | HAVE | — |
| 6 | "View request workflow" (a check-in that needs approval) | Check-in held for approval and routed | Out-of-fence check-ins are flagged or refused; never held for approval | MISSING | TX-10 (matrix 24: `outside_fence_action = approve`, kind `checkin_approval`); RQ-14 shows the route (RQ-2 preview) |
| 7 | Requester history: Request date, Approval date, Status (Pending / Undo / Rejected / Approved), Location status | Own check-ins that needed approval | Punches on the attendance calendar; no approval status | PARTIAL | RQ-14 with TX-10 (matrix 22 §22.14.12 rates the same list PARTIAL) |

#### R.2.4 Request → New request → Start trip

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Start trip" (live trip: Start Point, End Point, Address, Coordinate, "TRIP DETAILS", "View on map") | The mobile app tracks a journey; its distance becomes a mileage receipt *(inferred)* | Mileage receipts take typed from / to and distance or odometer readings | MISSING | Blocked: background location in a mobile app and a map / distance provider (CA-8); not packaged |
| 2 | Trip evidence on the receipt (`app-trip-details-drawer`) and "Invalid trip — the distance was manually edited as the trip was not completed successfully" | Show the tracked route behind a claim | — | MISSING | Same blocker |

#### R.2.5 Request page `/app/request/initiatedrequest` (`rail_Request*`; API `POST /api/Request/InboxRequest/Search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | The rail "Request" opens the tracker itself: "NEW REQUEST" button above tabs Pending / Approved / Rejected / Undo / Drafts | One place to raise and follow requests | Home "My requests" card (5 items) and My work "Waiting on others" (leave, regularisations, tickets, letters, compensation proposals) | PARTIAL → built (RQ-1: My requests in the My work rail carries the "New request" button; New request beside it) | RQ-1 ("My requests" page carries the "New request" launcher) |
| 2 | "All Category" filter | One request family at a time | — | MISSING → built (RQ-1: request-type filter with counts) | RQ-1 per-type facet |
| 3 | Empty states "You have no pending / rejected / draft requests" | — | Table empty states | HAVE | — |
| 4 | Detail per category: 41 `app-initiated-*` views (each = the request's form read-only + the workflow tracker): asset request, AR, change shift, comp-off, confirmation, contract approval, custom (Studio), expense advance, expense report, FBP approval, goal cycle, help desk, import approval, induction, investment declaration, job opening, leave, leave encashment, loan, location approval, mark attendance, offer letter, on duty, overtime, PIP, polls, profile, project approval, reimbursement, restricted holiday, settlement, short leave, assign salary structure, transfer approval, travel, variable, work plan, add position | The tracker also lists what HR initiated for others (uploads, salary structures, transfers, positions, settlements, PIPs, confirmations, polls) | Each record's own view page; HR-initiated work tracked on its own screen (Recent uploads, compensation proposals in "Waiting on others", transfer / position / PIP lists) | PARTIAL | RQ-1 extension: registry entries for HR-initiated kinds (data uploads awaiting approval, compensation changes, transfer requests, position changes, PIPs, confirmation reviews, settlements); offer letter / job opening N-A; projects blocked |

#### R.2.6 My space request histories (`myspace_*`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | One My space page per request type with "View all your past and upcoming … requests here" and "No results found — Try adjusting the filters or come back later!" | Own history per type, all under My space | Own lists per module (Me and each module's navigation), not gathered in one place | PARTIAL → built (RQ-1: in part: My requests gathers the per-type histories in one place; MS-1: the My space hub lists each history page — leave, time-office, regularisation, location, loans, claims, variable pay, investments, expenses, travel, assets — plus My requests and New request) | Matrix 22 MS-1 (My space hub) + RQ-1 |
| 2 | Location approval history card: "Location status : Active", "Requested Date :", status | Whether the approved place is still an active check-in location | Location requests (own): place, needed window, status; the resulting geo location's state not shown | PARTIAL | RQ-14 (matrix 22 §22.14.11 rates the list HAVE) |
| 3 | "Fbp approval history" | Own flexible-benefit declarations | — (FBP missing) | MISSING | BP-7 / PO-21 (matrix 25) |
| 4 | Expense report history with "Filters" | Own reports | Expense reports (own; status filter) | HAVE | — |
| 5 | "Tickets" — "Status: Booked", "You can find all the tickets booked by the company", "NO TICKETS" | Travel tickets the company booked for the employee | Company bookings recorded on the trip's journeys by the travel desk; seen on the trip | PARTIAL | Matrix 22 MS-16 / matrix 26 (booked legs as a list) |
| 6 | Proposed and Confirmed investment history as two lists | Declarations vs proofs | Tax declarations and My investment proofs | HAVE | — |

#### R.2.7 HR Desk global actions that open request forms (`globalAction`, rights "Request on behalf" / "Create" / "Apply reimbursement")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | One grantable on-behalf action per request type (21 of the 68 global actions, opened from the HR Desk search) | HR raises any request for an employee through the employee's form | On behalf per module under module permissions (`leave.manage`, `attendance.manage`, `payroll.manage`, `expense.approve`, asset team / HR, `servicedesk.agent`, `exit.manage`, `employee.position`); AR and encashment by HR are applied and approved at once; no claim, advance, expense report or proof on behalf | PARTIAL → built (RQ-1: in part: the registry names each type's on-behalf form and permission ("Raise for someone"); the per-type gaps rated in matrices 23–26 remain) | RQ-1 `on_behalf` per type; per-type gaps rated in matrices 23–26 (table below) |

Cross-reference (not counted; status as rated in the module matrices):

| Global action (`navigationCode`) | Request form here | Rated in | Status there |
|---|---|---|---|
| Resignation request (`resignation_91`) | 20.1.2 | 23 §23.9 #19 | PARTIAL |
| Transfer request (`transferrequest_919`) | — (not a flyout type) | 23 §23.9 #18 | HAVE |
| Attendance regularization request (`attendanceregularization_95`) | 20.1.8 | 24 §24.8 #1 | PARTIAL |
| Leave request (`leave_98`) | 20.1.18 | 24 §24.8 #2 | HAVE |
| On duty request (`onduty_96`) | 20.1.18 | 24 §24.8 #3 | HAVE |
| Restricted holiday request (`restrictedholiday_97`) | 20.1.7 | 24 §24.8 #4 | HAVE |
| Short leave request (`shortleave_99`) | 20.1.9 | 24 §24.8 #5 | HAVE |
| Leave encashment request (`leaveencashment_1470`) | 20.1.18 | 24 §24.8 #6 | PARTIAL |
| Shift request (`changeshiftrequest_1471`) | 20.1.18 | 24 §24.8 #7 | HAVE |
| Reimbursement request (`reimbursement_110`) | 20.1.4 | 25 §25.3.1 #3 | MISSING |
| Propose investment request (`purposedinvestmentdeclaration_114`) | 20.1.5 | 25 §25.3.2 #3 | HAVE |
| Confirmed investment (`confirmedinvestmentdeclaration_181`) | 20.1.6 | 25 §25.3.3 #3 | PARTIAL |
| Variable pay request (`variable_739`) | 20.1.15 | 25 §25.4 #3 | HAVE |
| Loan request (`loan_112`) | 20.1.3 | 25 §25.5 #3 | HAVE |
| Assign salary structure (`salarystructure_113`) | — | 25 §25.2.2 | PARTIAL |
| Add expense (`expense_798`) | 20.1.12 | 26 §26.7 #1 | PARTIAL |
| Advance request (`expenseadvance_496`) | 20.1.11 | 26 §26.7 #2 | PARTIAL |
| Travel request (`travel_1016`) | 20.1.16 | 26 §26.7 #4 | HAVE |
| Asset request (`assetrequest_1028`) | 20.1.17 | 26 §26.7 #7 | HAVE |
| Add ticket (`helpdeskticket_376`) | 20.1.10 | 26 §26.7 #8 | HAVE |
| Change goal (`goaledit_852`) | 20.1.14 | 27 §27.2.2 #10 | HAVE |
| Job requisition request (`jobopening_815`) | 20.1.13 | — | N-A (recruitment) |

### R.3 Inbox — new and deepened rows

#### R.3.1 Inbox → Task → Action → All action (page-access rights)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "All action", "All action 2" | Open the inbox and act on tasks *(inferred: two generations of the inbox)* | `task.view` + `task.act` in every role template | HAVE | — |
| 2 | "Ignore api" | Right to ignore system-generated tasks | No ignore (20.2.1 #27) | MISSING → built (RQ-8: permission `task.ignore` in the `task` prefix — Tenant HR Admin and HR Manager through their `task.*`; no other template) | RQ-8 extension: permission `task.ignore` gating "Not relevant" (→ `peopleos:sync-permissions`) |
| 3 | "View form based on only request" *(inferred: the approver sees the request's own form, not the employee's wider record)* | Limit what an approver sees | Approval cards show the request facts; the employee record opens only with `employee.view` in scope | HAVE | *(inferred)* |
| 4 | "Team" *(inferred: team context on the card)* | Who else in the team is affected | Leave cards: "N other people in the team away on these dates" | HAVE | *(inferred)* |
| 5 | "Employeeskillendorsecount" *(inferred: skill-endorsement count on the employee header)* | — | Skill endorsements are wall posts; no counts | MISSING | Waits for matrix 22 owner decision D-MS4 (skill endorsements) |

#### R.3.2 Inbox page (`rail_Inbox*`; APIs `POST inbox/task/search`, `GET inbox/Filter/{category}`, `GET core/Workflow/PendingWith/{request}/{category}`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Filter set loaded per category (`inbox/Filter/{category}` on every category click) | The filter panel fits the task type (leave type for leave, category for tickets, travel code for expenses …) | — | MISSING → built (RQ-8: the panel offers leave type, ticket category and travel request only when the chosen request type has them) | RQ-8 extension: facet-specific filters (deepens 20.2.1 #10) |
| 2 | "Pending with" for the open item *(inferred: who else holds the step)* | Shows the other approvers of the level / where it waits | Card shows requester, due, risk; not the other assignees | MISSING → built (RQ-2: "Waiting with" in the card's "Workflow and policy": every person who has it now, or the workflow step's assignees) | RQ-2 extension ("waiting with" on the approver's card too) |

#### R.3.3 Inbox task cards seen live (deepen 20.2.2)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Leave cancel card: dates with weekday, Leave type, No of days, Remaining balance, Comments, "Cancellation remark", approver Comments, REJECT / ACCEPT REQUEST | Decide a cancellation knowing why and the balance | `leave_cancellation` item: title, dates, the leave's original reason, "Approve cancellation" / "Keep leave"; `cancel_reason` and the balance after cancellation not shown | PARTIAL → built (RQ-8: "Why cancel: …" and "<type> balance after cancelling" before → after on the item, on Approvals and the unified inbox) | RQ-8 extension: cancellation reason and "balance after cancellation" in the item (20.2.2 #2 stays HAVE for the decision) |
| 2 | Initiate settlement card: View Profile, Write Notes, Date of joining / resignation, Expected last working day, Date of relieving, Resignation reason by employee / by approver, Employment status at resignation, Employee other status (+ remarks); steps 1 Review relieving details, 2 Review employee's attendance, 3 Review employee's salary (REVIEW each); pending clearance checklist; IGNORE / SUBMIT FOR APPROVAL | HR walks a settlement through three reviews and sends it for approval | Final settlement on the exit case (calculate, add line, approve, mark paid), clearances on the case; no task, no stepwise review, no notes | PARTIAL | RQ-10 extension: the settlement task (20.2.2 #25) opens a three-step review drawer over the existing services; matrix 25 PO-12 |
| 3 | Generate relieving letter card: "Please generate the relieving letter for the employee", VIEW TEMPLATE, Change (template), "Do you want to download letter?", "Do you want to share the letter on email Id?" Personal / Official email, IGNORE / GENERATE & PUBLISH (digital-sign call) | Generate, download and email the letter | Letters drafted on exit by policy, generated with signatories, downloadable; letters are never emailed (`Letters` sends no mail); e-sign pending | PARTIAL | RQ-10 extension: "Email the letter" (personal / work address from the person record; off by default; audited); e-sign with SV-17 (20.2.2 #29 stays HAVE for generation) |
| 4 | Create goal card: goal period, View workflow, Create new goal / Assign last approved / Assign recommended / Copy goals of team member / Generate with One AI | The manager creates a member's goal sheet from the inbox | Managers fill reports' goal plans (`performance.team`), templates (HP-8e); no "create goal" task, no last-approved / copy / AI | PARTIAL | RQ-10 extension: task "Create the goal sheet" when the goal-sheet policy maps a cycle and none exists; sheet tools in matrix 27 |

### R.4 Team — new and deepened rows

#### R.4.1 Team → People (`rail_Team`, `menu_Team__People`; APIs `MenuAccess/TeamAction`, `Team/Direct/Indirect/Employees`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Search "Search by name or code or designation or email id" | Find a member | My team table searches first / last name only | PARTIAL | RQ-11: search code, designation, work email |
| 2 | Member card: avatar, name (#code), Total Experience, Current Org Experience, Goal Status ("Not created" …) | Quick facts | Code, employee, designation, state, today | PARTIAL | RQ-11: tenure here, prior experience (when shared) and current goal-plan status as optional columns (with 20.3.1 #4) |
| 3 | Rail "Team" flyout: People / Team Analytics / Reports | One Team area | My team, Team HR requests, Team positions, Team career, Team learning, Leave calendar as separate pages | PARTIAL | RQ-11: one Team area with these as tabs (Team analytics / reports from RQ-15) |

#### R.4.2 Team → People → Action [9] (manager actions on behalf; `app-my-team-action` → `app-on-behalf-search-emp` "GO TO FORM")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Payroll {Reimbursement, Loan request, Confirm invest, Propose invest, Increment calculator, Variable} | Manager raises payroll requests for a member | Variable pay "For an employee" (managers through `leave.approve`); loans, declarations on behalf need `payroll.manage`; claims never on behalf; increment calculator is HR (matrix 25 PO-17) | PARTIAL | RQ-16 (types switched on per tenant) |
| 2 | Helpdesk {Ticket request} | Raise a ticket for a member | "Ask HR" for someone needs `servicedesk.agent`; managers see team requests read-only | PARTIAL | RQ-16 |
| 3 | Time office {Attendance regularization, On duty, Leave, Restricted holiday, Short leave, Assign shift, Attendance status, Leave cancel, Manage holiday, Leave encashment, Change shift} | Manager raises time-office requests and changes rosters, day status and holidays | "Apply on behalf" (`leave.manage`), "Raise on behalf" (`attendance.manage`) are HR; managers decide but cannot raise; roster, day status, holidays HR-only | PARTIAL | RQ-16 (requests); RQ-11 (assign shift, attendance status, holidays); matrix 24 §24.13 #1–5 |
| 4 | Workforce {Resignation, Transfer request} | Manager initiates a member's exit or transfer | "Initiate exit" (`exit.manage`), transfer requests (`employee.position`) — HR only | PARTIAL | RQ-16 (transfer request for a report, approved as today; exit initiation behind a setting) |
| 5 | Expense {Expense advance, Expense, Travel} | Manager files for a member | "Add expense for an employee", "Request travel for an employee" (`expense.approve` in scope — managers hold it); advance on behalf has no screen | PARTIAL | XO-5 (matrix 26) |
| 6 | Recruitment → Job requisition | — | — | N-A | Recruitment |
| 7 | Recruitment → Position assigned *(inferred: map a member to a position)* | Position mapping by the hiring manager | Team positions read-only; assignment by `employee.position` | PARTIAL | RQ-12 |
| 8 | Performance {Goal edit, Pip, Create virtual team, View virtual team, Normalize review rating, Normalize performance rating, Publish performance rating} | Manager edits goals, opens PIPs, builds virtual teams, calibrates | Reports' goal plans (`performance.team`); PIPs for people one manages (`ImprovementPlans::assertMayManage`); calibration / finalize need `performance.calibrate` / `.manage`; no virtual teams | PARTIAL | Virtual teams and rating normalisation: matrix 27 |
| 9 | Asset {Asset request} (listed twice) | Request an asset for a member | "Request for an employee" (`AssetRequestPolicy::team`) | HAVE | — |
| 10 | Engage {Team announcement} | Announce to the team | As 20.3.1 #13 | PARTIAL | RQ-11 |

#### R.4.3 Team → People → Team profile [9]: rights not in 20.3.2

Every other right maps to a 20.3.2 row: Workforce (View, View profile #35, Clearance #8, Confirmation #10, Exit interview
#12, Social profile #39); Time office (View leave balance #23, View attendance #4, View approved location #15, AR #5,
Attendance ytd #6, Compensatory off #9, Leave #22, Overtime #29, On duty #28, Holiday calendar #19, Location approval #25,
Mark attendance #26, Restricted holiday #36, Shift #37, Short leave #38, Leave encashment #24); Performance (View goals and
initiatives #17, View performance #31, Initiative #20, 1 on 1 #1, 360 feedback #2, Pip evaluation #33, Pip #32, Goal
history #16); Engage (View badges and rewards #7); Payroll (Ctc #11, Payslip #30); Recruitment (Job requisition #21,
Position assigned #34); Asset (My assets #27, Asset request #3); Helpdesk (#18); Expense (Expense advance #13, Expense
report #14, Travel #40).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Time office → "Add punch" | Manager adds a member's missed punch | Manual punch HR-only (`attendance.manage`) | MISSING | TX-7 with `team.punch_edit` (matrix 24 §24.13 #7) |
| 2 | Time office → "Delete punch" | Remove a wrong punch | Punches are never deleted; exclusion pending | MISSING | TX-7 (exclude, never delete) |
| 3 | Performance → "Create initiatives" | Assign an initiative to a member | "Assign initiative to" the people one manages (HP-8j, `performance.initiatives` in the Manager template) | HAVE | — |
| 4 | Performance → "Complete initiatives" | Complete a member's initiative | Start / Update completion / Complete by the performance manager (HP-8j) | HAVE | — |
| 5 | Performance → "Training" | Member's training | Team learning (`learning.team`) | HAVE | — |

#### R.4.4 Team → Team Analytics [4] (`/app/team/dashboard`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tab "Custom Analytics": "Hi There! Please select from the curated list of dashboards we have for you", "Select Dashboard/s (n)": Workforce (10 widgets), Time Office (6 widgets); "Skip for Now", "Create DashBoard", "Share" (APIs `analytics/list/M`, `library/DefaultDashboardList/M`) | A manager's dashboards over team-scoped widgets | Dashboards need `analytics.view` (not in the Manager template); My team summary tiles only | MISSING | RQ-15: curated team boards (workforce, time office) over team-safe metrics |
| 2 | Builder (`app-analytic-dashboard-home`): Create New Widget / dashboard, Add quick dashboard, Add from existing, Widget Glossary, Reorder, Share, star, widget size / position, copy widget | Build and share team boards | HR dashboard builder (matrix 21 §21.1.12) | MISSING | RQ-15 (personal boards only, over team-safe sources; AN-15) |
| 3 | Timeline "Events (n)": Leave, 1 on 1, Badge received, Birthdays, Comp off generated, Confirmation, Expense request (Total Amount), Expense request (Total Mileage), Initiative due date (+ "All Events" with pins) | Event types on the team calendar | Leave calendar (leave only); birthdays page; the others on their own screens | PARTIAL | RQ-11 extension: one-on-ones, badges received, comp-off credits, confirmation due dates, expense totals / mileage, initiative due dates as calendar sources (each gated as today); deepens 20.3.3 #1–2 |
| 4 | Goals: "Achievement status" filter (next to Processes, Progress ≤ 50 % / ≥ 50 %, Goal status, People, performance cycle, APPLY) | Goal health | — | MISSING | Matrix 27 GO-4 / GO-6 (§27.7 #4); deepens 20.3.3 #6 |
| 5 | Geo dashboard: Processes "Mobile checkin", "Headcount by sub branch"; "Search team member", "Select date", "No checkin found — We are unable to pin any location at the moment" | The team's check-ins and headcount per sub-branch | Punches carry coordinates; no team list; no sub-branch headcount for managers | PARTIAL | RQ-11 geo list (map blocked, CA-8); headcount tile in RQ-15; deepens 20.3.3 #9 |

#### R.4.5 Team → Reports [7] (`/app/team/team-reports`, `/search`; `menu_Team__Reports*`)

Matrices 24 and 27 rate their team-report pointer rows PARTIAL (the HR templates exist); here the team door is rated, as
20.3.4 #1: managers can open none of them.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Team Reports" with module tiles and counts (Workforce 14, Time Office 24, Payroll 2, Performance 20 shown / 21 in the tree, Expense 12, Recruitment 1, Engage 8); "Modules" / "Processes" grouping | Team report catalogue | — (Manager template has no report permission) | MISSING | RQ-15 |
| 2 | Tabs "All reports" / "Recent Reports" ("Reports that you access will be displayed here") / "Favorite Reports" | Recents and favourites | HR library without recents / favourites (`favourite_reports` preference key exists, unused) | MISSING | RQ-15 with AN-16 |
| 3 | "Scheduled Reports" | Schedule a team report | Report schedules for HR reports | PARTIAL | RQ-15 (link delivery, AN-18) |
| 4 | Report card: name, ".xlsx", one-line description, SHOW MORE | Pick and download | HR library cards with descriptions; CSV only | PARTIAL | AN-17 (xlsx) + RQ-15 |
| 5 | Workforce [14]: Work experience, Education details, Joining status, Snapshot, Confirmation feedback, Employee login report, Exit interview feedback, Induction plan, Induction evaluation report, Exit interview status, Confirmation status, Preboarding checklist, Contract details, Employee details | Same reports for the team | HR side: matrix 23 §23.13 #3 / matrix 21 | MISSING | RQ-15 (`team_safe` flags; login and contract reports only with their HR permissions) |
| 6 | Time office [24]: Clocking time, Daily attendance, Attendance register form 25, Late and early go, Leave balance, Monthly overtime detail, Monthly shift roster, Compensatory off request, Overtime request, Daily check in, Approved geo location, Leave request, On duty request, Monthly attendance register, Mobile check in request, Short leave request, Restricted holiday request, Attendance regularization request, Inconsistent punch report, Monthly working hour, Overtime register, Geo location request, Arrear attendance status days, Leave encashment report | Same for the team | HR side: matrix 24 §24.12 #3 | MISSING | RQ-15 |
| 7 | Payroll [2]: Variable paid, Salary register | Same for the team | HR side: matrix 25 §25.13 #5 | MISSING | RQ-15 (salary register only where CA-6 lets managers see pay) |
| 8 | Performance [21]: Goal details, Goal status, Performance review details, Performance summary, Performance review rating, … by approver, … details by approver, Pip evaluation, PIP request, Employee goal checkin, Performance review rating by approver (Pivoted), Performance feedback details, Continuous feedback report, Initiative details, 1 on 1 details, Tni details, Performance Digital Signature Status Report, 360 nomination status, 360 feedback status, 360 feedback details, Goals not created | Same for the team | HR side: matrix 27 §27.13 #5 | MISSING | RQ-15 (360 after HP-8h, review rows after HP-8i) |
| 9 | Expense [12]: Travel details, Travel summary, Expense advance, Live trip details, Outstanding expense advance, Accommodation details, Expense advance recovery register, Expense payment register, Expense receipt by amount, Expense receipt by mileage, Expense receipt details, Expense report details | Same for the team | HR side: matrix 26 §26.8 #9 | MISSING | RQ-15 (live trips blocked) |
| 10 | Engage [8]: Badges ledger, Reward points ledger, Happiness index details, Survey details, Event details report, Event attendance report, Pre-event feedback report, Post-event feedback report | Same for the team | HR side: matrix 27 §27.13 #6 | MISSING | RQ-15 (small-group rules for happiness / survey; events after GO-15) |
| 11 | Recruitment [1]: Job requisition summary | — | — | N-A | Recruitment |

#### R.4.6 Team → Imports [3] (the "Imports" tab of Team → People)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Time office [5]: Geo work locations, Attendance check-ins, Shift, Attendance status, Leave balance | Manager uploads for the team | Uploads are HR screens; rows not limited to the uploader's scope | MISSING | RQ-15 with TX-13 (`team.uploads`, default off; D-TX9) |
| 2 | Performance [6]: OKR total achievement, OKR final achievement, Goals, Normalize review ratings, Normalize performance ratings, Initiatives | Same | — | MISSING | RQ-15 with GO-10 (matrix 27) |
| 3 | Payroll [1]: Variable pay | Same | — | MISSING | RQ-15 with PO-6 (matrix 25) |

#### R.4.7 Team → Analytics [4] (team analytics views; not captured as pages)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Time office → Daily checkin | Team check-ins per day | My team: present / late / missing punch today | PARTIAL | RQ-15 over TX-14's templates |
| 2 | Time office → Compensatory off trend | Comp-off over time | — | MISSING | RQ-15 / TX-14 |
| 3 | Time office → Overtime trend | Overtime over time | My team counts pending overtime today only | MISSING | RQ-15 / TX-14 |
| 4 | Time office → Planned vs unplanned leave | Leave planning | — | MISSING | RQ-15 / TX-14 |
| 5 | Time office → On duty by type | OD mix | — | MISSING | RQ-15 / TX-14 |
| 6 | Time office → Project wise on duty hours | OD hours by project | — | MISSING | Blocked (projects) |
| 7 | Time office → Late and early count | Lateness | My team "late" today | PARTIAL | RQ-15 / TX-14 |
| 8 | Workforce → Upcoming join | Joiners coming to the team | Current reports only | PARTIAL | RQ-15 (matrix 23 §23.12 #8) |
| 9 | Recruitment → Job requisition | — | — | N-A | Recruitment |
| 10 | Performance → Review bell curve | Periodic review ratings distribution | — (periodic reviews wait for HP-8i) | MISSING | After HP-8i |
| 11 | Performance → Performance bell curve | Rating distribution of the team | 9-box team view for `performance.team` (HP-8g `NineBoxGrid`); bell-curve targets and the calibration board need `performance.calibrate` | PARTIAL | GO-3 (matrix 27 §27.12 #3) |

### R.5 Studio (`Studio → Studio`; `app-lego-inbox`, `app-lego-inbox-header`, `app-lego-initiated-requests`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Studio (HROne's app builder) as a grantable page | Build custom apps with forms and workflows | Configuration Forms (`form.*`: forms, versions, submissions, approvals through workflows) | PARTIAL | Builder depth belongs to setup matrix 02 (forms); the page has no route in `routes.json` and was not captured |
| 2 | Studio inbox header: From, To, "Overall status", Date | Who raised it and where it stands | Form approvals arrive as workflow steps (Approval Center / Task inbox: run, requester, due) | PARTIAL → built (RQ-8: facet "Forms"; the item shows From, To and "Overall status" — the run's status and the submission's) | RQ-8 facet "Forms" (20.2.1 #8) shows the run's overall status |
| 3 | Studio inbox body: Workflow Status (Trigger, Stage ID, Actions), "No workflow status available", Approve / Reject, "The deadline for this task has passed", search "Type to search…", ALL | Decide with the stage in view | Workflow step decisions; run viewer with steps and log | HAVE | — |
| 4 | "Record" / "Select record" / "Edit" inside the task (`app-dynamic-form`) | The approver edits the submitted record before deciding | Submissions are read-only to approvers | MISSING | Setup matrix 02 (approver-editable fields per form version, default off, audited) |

### R.6 Shortcuts, enterprise wall, smart search, dashboard

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Apps → Shortcuts {View} (`app-shortcut-pins`): pinned items grouped Request / Reports / Imports / Actions / Searches; Unpin; "Pop out in new tab"; "Seems like you've not pinned any item — Look for this icon to pin your favourite task" | Personal shortcuts to any request form, report, upload, action or list | Recent items and pinned people (`ExperiencePreferences`); `favourite_reports` unused; no pins for requests, reports, uploads, actions, lists | PARTIAL | RQ-13 extension: `pins` (type + key, cap 12) with a pin on request types (RQ-1 launcher), report cards, upload screens, Quick actions and lists; shown in the Command Center idle state |
| 2 | Company shortcut links (`app-opf-shortcuts`: Shortcut name, Paste URL, Non-Editable, SSO Authentication, "share shortcut links automatically", else with the manager's welcome message) | Links pushed to employees' shortcut tab | — | MISSING | Setup SV-13 (matrix 13 WF-13) / matrix 23 WO-8 |
| 3 | Home → Enterprise wall → Engage {Badge, Points, Hr post, Hide post / delete comment, Event, Polls, Survey, Announcement} | Wall rights | Rated in matrix 27 §27.16 #1–8 (4 HAVE, 2 PARTIAL, 2 MISSING) | PARTIAL | GO-15 / GO-16 / GO-20 (matrix 27) |
| 4 | Home → Enterprise wall → Knowledge base {Employee knowledge base} | KB from the wall | `kb.view` (matrix 27 §27.16 #9) | HAVE | — |
| 5 | Home → Enterprise wall → Performance {1-on-1, Create an initiative, Continuous feedback} | Quick performance actions | Matrix 27 §27.16 #10–12 (2 HAVE, 1 PARTIAL) | PARTIAL | GO-20 |
| 6 | Smart search → Time office (Often came late over last 90 days, Take leaves most frequently 180 days, Take delayed action 90 days, Clocked maximum / least hours 30 days) | Ready answers from the search bar | Matrix 24 §24.14 #1–5 (PARTIAL: this-month templates) | PARTIAL | TX-14; RQ-13 extension: "Smart search" group in the Command Center listing the answers the viewer may run |
| 7 | Smart search → Performance (View top / bottom performers, last performance review) | Ranked lists from search | Matrix 27 §27.15 (no rankings by principle) | MISSING | Owner decision "ranked performer lists" (matrix 21) |
| 8 | Dashboard (top-level node, no rights; `app-main-dashboard`) | The home dashboard as a grantable page | Home (role-based blocks), rated in 20.4.2 | HAVE | — |

### R.7 Status changes to earlier rows (merged work and new evidence)

| Row | Was | Now | Why |
|---|---|---|---|
| 20.2.1 #25 "More" → "Create an initiative" | MISSING | PARTIAL | HP-8j initiatives exist (`InitiativeResource`, "Assign initiative to"); not launched from the approval card — build note now RQ-8 (launch from "More") → built (RQ-8) |
| 20.2.2 #61 `app-initiative-inbox` | MISSING | HAVE | `InitiativeFeedbackTaskSource` in My tasks; notifications assigned / created / feedback requested / given / completed |
| 20.3.2 #17 Goals and initiatives | PARTIAL | HAVE | Initiatives "My team" tab (`ManageInitiatives`, performance managers) next to goals |
| 20.3.2 #20 Initiative | MISSING | HAVE | Same tab; managers create, update, complete (`performance.initiatives` in the Manager template) |
| 20.4.2 #19 Initiatives on Home | MISSING | PARTIAL | My initiatives page; no Home card (matrix 27 GO-20) |
| 20.2.2 #11 `app-mark-attendance-inbox` | PARTIAL (inferred "attendance marked by a manager") | MISSING | Its detail (`app-mark-attendance-detail`) is the approval of a web / mobile check-in (source, coordinates, address, IP, photos, city / office / work mode, approve the place forever or for N days); PeopleOS cannot hold a check-in for approval — TX-10 (matrix 24 §24.3.1), no longer RQ-11 |
| 20.3.1 #17 "Imports" tab | PARTIAL | MISSING | Team → Imports [3] = 12 manager uploads; PeopleOS uploads are HR-only and not limited to the uploader's team (RQ-15 with TX-13 / GO-10 / PO-6, `team.uploads` default off) |

Text corrections, status unchanged: 20.1.0 #1 lists 23 types (adds Location approval, Mark attendance, Start trip);
20.3.2 #22 "apply on behalf" is HR-only (`leave.manage`) — the row's HAVE is the member's leave view (manager on-behalf is
R.4.2 #3); 20.3.4 #1 is now captured (R.4.5). HP-8g (9-box, bell-curve targets) changes no earlier row; it backs R.4.7
#11. P3 / P4 / P5 and EX-F were already credited when this matrix was written (bulk approval, discussion, send back,
archive, delegation, request lock, workflow coverage, trips by workflow group): no further change.

### R.8 Counts after the re-check

The "Counts" table above shows the figures as first written. With the seven status changes and this section:

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 20.1 Request (as written) | 246 | 96 | 98 | 50 | 2 |
| 20.2 Inbox (after R.7) | 119 | 47 | 40 | 25 | 7 |
| 20.3 Team (after R.7) | 97 | 41 | 35 | 18 | 3 |
| 20.4 Search and social wall search (after R.7) | 48 | 19 | 17 | 6 | 6 |
| **Earlier rows (510 → statuses updated)** | **510** | **203** | **190** | **99** | **18** |
| R.2 Request: rights per type, location approval, mark attendance, start trip, tracker, My space histories, on-behalf | 51 | 28 | 11 | 11 | 1 |
| R.3 Inbox: rights, per-category filters, pending with, live cards | 11 | 3 | 4 | 4 | 0 |
| R.4 Team: people, actions, team profile, team analytics, reports, imports, analytics | 48 | 4 | 19 | 22 | 3 |
| R.5 Studio | 4 | 1 | 2 | 1 | 0 |
| R.6 Shortcuts, enterprise wall, smart search, dashboard | 8 | 2 | 4 | 2 | 0 |
| **Re-check rows** | **122** | **38** | **40** | **40** | **4** |
| **Whole file** | **632** | **241** | **230** | **139** | **22** |

22 of the 122 rows are pointer rows restating a status rated in matrices 22–27 (R.2.6 #3, #5; R.4.3 #1–2; R.4.4 #4;
R.4.5 #5–10; R.4.6 #1–3; R.4.7 #8, #11; R.6 #2–7). The global-action cross-reference table is not counted.

### R.9 Biggest gaps found by the re-check

1. **Managers have no team reports, analytics, dashboards or uploads.** Team → Reports (82 reports in 7 modules),
   Team → Analytics (11 views), Team Analytics → Custom Analytics and Team → Imports (12 uploads) are all closed to
   the Manager template (no `analytics.*`, HR-only uploads) (R.4.4–R.4.7).
2. **Managers raise few requests on behalf.** Expense, travel, asset and variable pay only; leave, every time-office
   request, tickets, loans, claims, declarations, resignation and transfer need HR permissions (R.4.2).
3. **Check-in requests.** "Mark attendance" has no remark, work mode, hours worked or approval; location requests are
   typed coordinates without photos; "Start trip" (tracked mileage) is absent and blocked (R.2.2–R.2.4).
4. **Request tracker breadth.** HROne's tracker spans 41 categories including what HR initiated, with an "All Category"
   filter and per-type Create / Cancel rights; PeopleOS has none of the cross-type tracker (R.2.1 #24, R.2.5).
5. **Inbox depth.** Filters per category, "pending with", an ignore right; the leave-cancel card hides the cancellation
   remark and balance; settlements have no stepwise review task; relieving letters cannot be emailed (R.3).
6. **Shortcuts.** No pins for requests, reports, uploads, actions or lists; no company shortcut links (R.6 #1–2).

### R.10 Build packages (new, continuing the numbering) and extensions

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 16 | **RQ-14 Check-in requests.** "Use my current location" on location requests (browser position as `WebPunch` reads it; source and IP stored), optional photo when the check-in policy asks; hours worked so far on the day card; optional remark on web check-in (`attendance.web_punch.ask_remark`, default off); a check-in held for approval shows its route (RQ-2 preview) and appears in the requester's history with request / approval date, status and the place's location status; work mode / city / office once SV-18 lands. Defaults reproduce today | R.2.1 #14; R.2.2 #1, #4, (#5 with SV-18); R.2.3 #2, #3, (#4), #6, #7; R.2.6 #2 | `app/Filament/Support/WebPunch.php`, `resources/views/filament/partials/web-punch.blade.php`, `home/day.blade.php`, `MyDay.php`, `ListLocationRequests.php`, `GeoLocations`, `LocationRequest` (migration: `source`, `ip_address`, `photos`), `config/peopleos_setup.php` | TX-10 (matrix 24: `outside_fence_action = approve`, kind `checkin_approval`), RQ-2, SV-18 | M |
| 17 | **RQ-15 Team reports, analytics and uploads** (takes over the Team-reports part of RQ-11). `team_safe` flag on report-library templates, run under the manager's team scope; new permission `analytics.team` in the Manager template; Team → Reports page (module tiles with counts, Modules / Processes, All / Recent / Favourite tabs from AN-16, scheduled team reports with link delivery, xlsx with AN-17); team analytics views from TX-14 / matrix 23 / GO-3 templates; curated team boards (workforce, time office) and personal boards over team-safe metrics; headcount by sub-branch tile; scope-limited manager uploads behind `team.uploads` (default off, D-TX9) for the 12 Team → Imports types | 20.3.4 #1–2; R.4.4 #1, #2, #5 (tile); R.4.5 #1–10; R.4.6 #1–3; R.4.7 #1–5, #7, #8, #11 | `ReportLibrary.php`, `ReportRunner`, `app/Filament/Pages/TeamReports.php` (new), `DashboardViewer.php`, `Dashboards`, `WorkforceMetrics`, `ImportGovernance`, `config/peopleos.php` (permission, Manager template) → `peopleos:sync-permissions`, `config/peopleos_setup.php` | RQ-11 (team scope), AN-15 / AN-16 / AN-17 / AN-18 (matrix 21), TX-13, TX-14, GO-3, GO-10, PO-6 | L |
| 18 | **RQ-16 Manager requests on behalf.** Setting `team.on_behalf.types` (empty = today: expense, travel, asset, variable pay only) letting line managers raise listed types for direct / indirect reports in scope, never for themselves, through each type's own service with the manager as actor: leave, AR / OD / RH / short leave / change shift / encashment (sent for approval, not applied at once), tickets (categories the helpdesk access policy allows), loans / claims / declarations where the tenant allows, transfer request for a report (approved as today), exit initiation behind its own switch, leave cancel by the manager (matrix 24 §24.13 #4) | R.4.2 #1–4; 20.3.1 #16 (with RQ-1) | `ListLeaveRequests.php`, `ListTimeOfficeRequests.php`, `ServiceDeskActions.php`, `ListLoans.php`, `ListReimbursementClaims.php`, `TransferRequests.php`, `ExitActions.php`, module policies (`onBehalf`), `config/peopleos_setup.php` | RQ-1 (`on_behalf` per type), RQ-11 (team Action tab) | M |

Extensions of existing packages:

- **RQ-1**: 23 types including Location approval and Mark attendance (Start trip blocked); per-type `raise_permission` /
  `cancel_permission` (R.2.1 #24); "New request" button on the tracker and the "All Category" filter (R.2.5 #1–2);
  registry entries for HR-initiated kinds (R.2.5 #4); on-behalf right per type (R.2.7 #1); My space entries with MS-1
  (R.2.6 #1) → built (MS-1: "My requests" and "New request" are My space items).
- **RQ-2**: "waiting with" on the approver's card (R.3.2 #2) → built (RQ-2).
- **RQ-8**: `task.ignore` permission (R.3.1 #2); filters per category (R.3.2 #1); cancellation reason and balance on
  the leave-cancel item (R.3.3 #1); "Create an initiative" in the card's "More" (20.2.1 #25); forms facet with overall
  status (R.5 #2).
- **RQ-10**: settlement three-step review drawer (R.3.3 #2); "Email the letter" on relieving / other letter tasks
  (R.3.3 #3); "Create the goal sheet" task (R.3.3 #4). 20.2.2 #11 leaves RQ-11 for TX-10.
- **RQ-11**: team search on code / designation / email, member facts (tenure, experience, goal status), one Team area
  (R.4.1); more team-calendar sources (R.4.4 #3); geo list view (R.4.4 #5). Team reports move to RQ-15.
- **RQ-13**: pins / shortcuts (R.6 #1) and a "Smart search" group (R.6 #6).

Not packaged here: Start trip (mobile tracking + map provider); FBP history (BP-7 / PO-21); booked travel tickets list
(MS-16 / matrix 26); add / delete punch (TX-7); achievement status (GO-4 / GO-6); wall rights (GO-15 / GO-16 / GO-20);
company shortcut links (SV-13); approver-editable Studio records (setup matrix 02); skill-endorsement counts (D-MS4);
ranked performer lists (owner decision); virtual teams and rating normalisation (matrix 27); recruitment rows (N-A).
