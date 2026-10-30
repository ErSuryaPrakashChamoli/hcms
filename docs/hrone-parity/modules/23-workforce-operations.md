# HROne vs PeopleOS — 23 Workforce operations (HR Desk → Workforce), field by field

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A. Package prefix: **WO-**.

**Inputs.**

- `hrone-research/menu-tree.json` → panel **"Workforce"** (top items Employees (profile), Employees history (profile
  history), Confirmations, Business partners, Reports, Workforce Setup; groups Onboarding (7), Transfer (4), Separation
  (5), Workforce letters (11); the "People" group's items are the two top items) and the URL each item opened.
- `hrone-research/pages.jsonl` (latest line per id): `rail_Workforce` (+ `__tab_01_Reports`), `rail_HR_Desk`, and the 31
  `menu_Workforce__*` pages with their `__tab_01_Reports` captures (Employees_profile, Employees_history_profile_history,
  Confirmations, Business_partners, Reports, New_hires_upcoming_join, Background_verifications, Preboardings,
  Welcome_team_members, Profile_completion, Inductions, Induction_evaluations, Transfer_requests, Transfers,
  Pre_Transfers, Post_Transfers, Resignation_requests, Resignation_cancellation, Clearances, Final_clearances,
  Exit_interviews, Adhoc_letters_misc_letter, Shared_letters, Shared_letters_by_employee, Confirmation_letters,
  Extension_letters, Termination_letters, Contract_letters, Transfer_intimation_letters, Transfer_letters,
  Resignation_acceptance_letters, Relieving_letters), and `menu_Team__People`. No `desk_*` capture opens a Workforce page
  (the five `desk_*` ids are Tickets, Asset inventory, HR handbook, Employee goals, Travel request — other matrices).
- `hrone-research/menu-permission-tree.md` and `menu-catalogue.json` → `MenuAccessPermission/0` (with each node's grantable
  actions): HR Desk → Search → Workforce [29], Inbox Insights → Workforce [32], Action → Workforce [5], Imports →
  Workforce [20] / Core [18] / Integration [13], Analytics → Workforce [7]; Team → Reports → Workforce [14], Team →
  People → Action [9] / Team profile [9], Team → Analytics → Workforce [1]. `globalAction` (Workforce: All action, Add
  business partner, Add employee, Add new hire (upcoming join), Change manager, Generate letter (misc. letter), Invite to
  HROne, Product enhancement request, Resignation request, Share letters/ documents, Transfer request) and
  `AvailableSearch` (each Workforce page's `importIds` / `actionIds`).
- `hrone-research/components.json`: `app-manage-employee-search`, `app-employee-search-filter`, `app-bulk-employee-search`,
  `app-create-new-employee`, `app-create-employee-advance-tabs`, `app-create-employee-snapshot`,
  `app-create-employee-setting`, `app-create-employee-contract`, `app-contract-approval`, `app-renew-contract`,
  `app-create-employee-history`, `app-employment-history-dialog`, `app-update-employee`, `app-mailer-details-flyer`,
  `app-send-email`, `app-candidate-blacklist`, `app-delete-task`, `app-profile-history*`, `app-upcoming-*`,
  `app-create-upcoming-employee-*`, `app-onboarding-search`, `app-on-boarding-pre-task`, `app-onboarding-search-view-flyer`,
  `app-welcome-note-search`, `app-welcome-employee`, `app-welcome-task*`, `app-induction*`, `app-confirmation*`,
  `app-transfer-*`, `app-do-transfer-*`, `app-pre-transfer*`, `app-pre-tansfer-pre-task`, `app-separation*`,
  `app-separation-task`, `app-review-relieving-details`, `app-employee-resignation-card-view`, `app-resignation-detail`,
  `app-clearance-*`, `app-view-clearance-task`, `app-final-clearance-*`, `app-exit-interview*`, `app-employee-letter-*`,
  `app-letter-generation*`, `app-other-letter-generation`, `app-bulk-letter`, `app-bulk-download-letter`,
  `app-publish-status-shown`, `app-shared-letter-*`, `app-publish-letter-search`, `app-published-letter-document`,
  `app-letter-type-document`, `app-external-consultant*`, `app-add-external-consultant`, `app-vendor`,
  `app-add-vendor-flyer`, `app-change-manager-search`, `app-send-bulk-password-form`, `app-feature-request`,
  `app-product-enhancement-wizard`, `app-new-do-imports` + steps 1–4, `app-new-recent-import`, `app-recent-imports`,
  `app-use-import`, `app-import-approval*`, `app-whatsapp-logs`, `app-onboarding-policy-form`.
- Lookup lists returned by the captured APIs (configuration, not personal data): employee status (Probation,
  Confirmed, Resigned, Relieved, Settled), employee type (Permanent, Consultant, Wages, Trainee, Contractual), other status
  (Not applicable, Suspended, Absconding, Terminated, Retired, Sabbatical, Maternity), letter trigger / event types
  (`OtherLetter/trigger/event/type`), business-partner user types (`External/Employee/logOnType`: Customer, Recruiter,
  BGV, Vendor, System Consultant, Other), shareable letter types (`LetterType/Upload`), report process counts
  (`report/Count/Process`: New Hire 5, Employee 31, Confirmation 2, Transfer 4, Separation 7, Business partner 1), and the
  response field names of `globalSearch/Confirmation`, `globalSearch/FinalClearance`, `EmployeeProfileHistory`,
  `induction/request/Admin/Search`, `Transfer/Reason`.
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` for the ids above (structure only; no
  personal data copied).
- PeopleOS, checked in code: `app/Filament/Resources/Employees/{EmployeeResource, Tables/EmployeesTable,
  Pages/{ListEmployees,CreateEmployee,EditEmployee,ViewEmployee}, Schemas/{EmployeeForm,EmployeeInfolist},
  RelationManagers/*}`, `app/Filament/Pages/{People,ChangeIntelligencePage,RecentUploads,TaskInbox,PeopleAnalyticsPage,
  WorkforceAnalyticsPage,WorkforceDashboard,ExitInsights}.php`, resources `OnboardingPlans`, `OnboardingTemplates`,
  `BgvCases`, `ConfirmationReviews`, `TransferRequests`, `ExitCases` (+ Clearances / Letters / Settlement relation
  managers), `ClearanceChecklists`, `Letters`, `LetterTemplates`, `LetterSignatories`, `EmployeeImports`,
  `BankAccountImports`, `Users`, `EmployeeDevices`, `IntegrationSystems`, `FeedbackRequests`; support classes
  `ExitActions`, `WorkforceActions`, `ImportActions`; domains `Employment` (actions Hire / CreatePreEmployee / AssignPosition
  / Transfer / ChangeManager / Rehire / Promote, services `TransferRequests`, `EmployeeCodeGenerator`, imports
  `EmployeeImports`, `BankAccountImports`, `ImportTypes`, `RowImportTypes`, `ImportGovernance`, `ImportDownloads`),
  `Lifecycle` (`LifecycleEngine`, `ConfirmationReviews`, states in `LifecycleState`), `Onboarding`, `Bgv`, `Exit` (`Exits`,
  `ExitInterviews`, `FinalSettlements`, `ResignationWorkflowBridge`), `Letters` (`Letters`, `PolicyLetters`), `Identity`
  (`UserInvitations`, `PasswordResets`), `Integration` (`IntegrationMappings`, `ExternalReferences`), `Configuration`
  (`Blueprints`), `Analytics` (`ReportLibrary`, `EmployeesDataset`, `WorkforceMetrics`), `Experience` approval sources;
  `config/peopleos.php` (permissions, lifecycle transitions, exit / letters / onboarding / bgv vocabularies, feature
  `employee.import_export`), `config/peopleos_setup.php`; docs `docs/architecture/hrone-parity.md` (WF-1, WF-2, WF-3 / P13,
  P2 import governance, CA-1 … CA-4), `docs/hrone-parity/setup/00-build-status.md`, `HROne-Gap-Register.md` (WF-01 …
  WF-13, IP-04).

**Pointed, not repeated.** Request forms (resignation, transfer on behalf …) → `20-requests-inbox-team.md` §20.1; inbox
task cards (separation, confirmation, letter generation, upcoming joiner, welcome, induction, pre-transfer, do transfer
…) → §20.2.2; Team → People / team actions → §20.3.1–20.3.2; the report catalogue (HR Desk → Reports → Workforce [51])
→ `21-analytics-reports-projects.md` §21.2.2 "Workforce (51)"; the Inbox Insights page itself → §21.3; the self
profile's section forms field by field → `22-my-space-self-profile.md` §22.2; Workforce setup policies (confirmation,
induction, separation, transfer, onboarding) → `setup/08-workforce-payroll.md` §08.1–08.7 and
`setup/13-setup-reverification.md` (SV-6, SV-13); BGV / e-KYC / journal voucher / vendor setup →
`setup/01-core-hr-access-integration.md` §6.3–6.7, §1.33. Such rows appear here once, naming the matrix and section.

**Progress.**

- Covered: every page of the Workforce panel (31 pages: the Records view with its filters, cards, card menu, bulk
  actions and dialogs from components, and the Reports tab with its columns), the HR-side profile parts matrix 22 left
  to the HR desk (Add employee wizard, Contract, Employee setting, upcoming-joiner review), all 29 nodes of HR Desk →
  Search → Workforce and the 38 grantable rights of its "Profile" node, Action → Workforce [5] and the 11 Workforce
  global actions, Imports → Workforce [20] / Core [18] / Integration [13] with the import wizard and logs, Inbox Insights
  → Workforce [32], Analytics → Workforce [7], Team → Reports → Workforce [14], Team → People → Action [9] / Team
  profile [9], Team → Analytics → Workforce [1], and HR Desk → Reports → Workforce [51] (one pointer row: matrix 21 has all
  51).
- Not captured as pages: the dialogs behind the split buttons and card menus (the crawl did not click them); their rows
  come from components. Most Onboarding, Transfer and Separation registers were empty for the crawl account ("No results
  found"), so card layouts come from component text. "Workforce Setup" (top item) is the setup area analysed in
  `setup/08-workforce-payroll.md` and is not repeated.
- Result: 503 rows — HAVE 129, PARTIAL 227, MISSING 133, N-A 14 (counted by script over every table row in §23.1–§23.14;
  the §23.0 index is not counted); 20 build packages WO-1 … WO-20; 12 owner decisions D-WO1 … D-WO12.

**Built (2026-10-29).** WO-1 (Workforce desk, bulk-action framework, saved views, register report link, card view) and
WO-2 (shared employee search and filters, Rehire / reactivate, Send e-mail, printable profile, Activity tab): the rows
they close are marked "→ built" with the package; see `docs/architecture/hrone-parity.md` "WO-1 / WO-2 Workforce desk,
bulk actions, employee search". The counts below are the analysis-time counts.

**Status key.** HAVE: HR can see / do the same today. PARTIAL: something exists but does not cover the HROne element
(often: one-at-a-time where HROne works in bulk, a filter on a general list where HROne has a dedicated register, or the
data is recorded but not shown). MISSING: nothing equivalent. N-A: UI chrome, vendor marketing / licensing, recruitment,
or a deliberate PeopleOS rule (stated in the build note). *(inferred)* marks a meaning taken from a control name because
HROne's label or behaviour was not captured.

**Conventions used in the build notes** (same as matrices 20–22): additive changes whose defaults reproduce today's
behaviour; per-group options as Policy Engine type fields; organisation switches as typed settings in
`config/peopleos_setup.php`; new models tenant-scoped (`BelongsToTenant` + `Auditable`, `HasEffectiveDates` where history
matters), classified in `DataInventory`, guarded by a policy; sensitive values encrypted and masked without the
sensitive permission; every write through the owning domain action / service (People / Employment actions with
`ProfileChangeGuard`, `LifecycleEngine`, `Exits`, `Onboarding`, `Letters`, `TransferRequests`); approvals through a
workflow trigger (P5 bridge) or a tagged `ApprovalSource`; uploads on the staged pipeline under P2 import governance
(`ImportTypes` registry, `RowImportTypes` for one-row-one-change uploads); bulk actions authorise and audit each record
on its own and never hard-delete business records (cancel / supersede instead); permissions declared in
`config/peopleos.php` then `php artisan peopleos:sync-permissions`; statutory values never hard-coded (rule data).

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | Relevance |
|---|---|---|
| Employees register: columns ID, name, designation, department, company, location, manager, type, status, joining date, work email (toggleable); filters status (multi), company, department, location, designation, grade, employment type, category, work mode, manager (collapsible, kept in the session); search by code / name / email; row View / Edit; header "Export" (CSV in the import format, feature `employee.import_export`) and "Hire employee"; no bulk actions | `Employees/Tables/EmployeesTable.php`, `Pages/ListEmployees.php` | HROne Employees (profile) Records |
| People directory: cards or list, group by department / location / manager, search, filters department / location / status / manager, person peek | `app/Filament/Pages/People.php` | HROne card view |
| Hire wizard (Person → Employment → Position → Manager): person fields, duplicate-person check, company, joining date, probation end, employee code auto / manual from the company's code series, work email / phone, position dimensions, line manager, audit reason; a future joining date makes a preboarding employee, today or a past date a joined employee in probation | `Pages/CreateEmployee.php`, `Schemas/EmployeeForm.php`, `HireEmployeeAction`, `EmployeeCodeGenerator` | HROne Add employee / Add new hire |
| Employee 360 actions: Message; Request (Raise an HR request, Generate a letter); Action (Transfer / promote, Change manager, Change lifecycle state, Mark as joined, Start onboarding, Initiate background check, Assign work schedule, Start workflow); More (Edit profile, All changes, Statutory details view / edit); 30+ relation tabs (addresses, family, qualifications, experience, documents, bank, skills, positions, reporting, onboarding, BGV, letters, exit, timeline, workflows …) | `Pages/ViewEmployee.php`, `RelationManagers/*` | HROne card menu and profile tabs |
| Lifecycle: pre_employee → preboarding / onboarding → joined → probation → confirmed / active … notice_period → exited → alumni; transitions in `peopleos.lifecycle.transitions` | `LifecycleState`, `LifecycleEngine` | HROne employment status |
| Onboarding / induction plans from templates (items: task, document, form, acknowledgement, profile section, feedback; owner employee / manager / buddy / HRBP / role / user; phases preboarding … day 90; templates may start N days before joining) | `Onboarding`, `OnboardingPlanResource`, `OnboardingTemplateResource` | HROne Preboardings, Profile completion, Inductions |
| BGV cases (manual provider; checks identity, address, education, employment, reference, criminal, document; consent) | `Bgv`, `BgvCaseResource` | HROne Background verifications |
| Confirmation reviews (WF-1: opened daily, confirm / extend / terminate, manager feedback and self-review P13) | `ConfirmationReviews`, `ConfirmationReviewResource` | HROne Confirmations |
| Transfer requests (WF-2/3: dimensions, manager, reason, allowances, approval by someone else; Approval Center source) and direct HR "Transfer / promote" | `TransferRequests`, `TransferRequestResource`, `TransferApprovalSource`, `AssignPositionAction` | HROne Transfer requests / Transfers |
| Exit cases (types resignation, termination, retirement, contract expiry, absconding, death, mutual, other; statuses initiated → notice → clearance → settlement → completed / withdrawn / cancelled; clearance stages manager / IT / finance / asset / HR with checklists; exit interviews standard + feedback templates; F&F settlement; letters on exit start / completion; alumni) | `Exits`, `ExitActions`, `ExitCaseResource`, `ClearanceChecklistResource`, `FinalSettlements` | HROne Separation group |
| Letters: templates (rich text or Word), one signatory, generate per employee, approve / reject, issue (stored as an employee document, employee notified), download; letter types include confirmation, transfer, relieving, experience …; policy letters drafted on confirm / extend / terminate / exit | `Letters`, `LetterResource`, `LetterTemplateResource`, `PolicyLetters` | HROne Workforce letters |
| Uploads: employee import (add / update / both, 27 fields, template + format guide), bank details upload, staged pipeline (upload → inspect → map → validate → approve → run), P2 approval rule (self / other person / workflow), Recent uploads log with downloads | `EmployeeImports`, `BankAccountImports`, `ImportGovernance`, `RecentUploads`, `ImportDownloads` | HROne Imports |
| Users and invitations (one user at a time; resend / revoke invitation, password reset link, reset MFA, sign out everywhere) | `Users/Pages/{CreateUser,EditUser}.php`, `UserInvitations` | HROne Invite to HROne, Send password reset link |
| Report library (41 datasets, 149 templates) and analytics pages | `ReportLibrary`, `DatasetRegistry`, `PeopleAnalyticsPage`, `WorkforceAnalyticsPage` | HROne Reports tabs, Analytics |

---

## 23.0 Index — HR Desk → Search → Workforce [29] and the Workforce panel

Not counted. Each node's rows are in the section named. "Rights" = the actions HROne can grant on the node. "Overall" =
the weakest material row of the section.

| # | Search node (rights) | Workforce panel item / URL code | Section | Overall |
|---|---|---|---|---|
| 1 | Profile (38 rights, §23.2.4) | Employees (profile) · `profile_50` | 23.2.1–23.2.6 | PARTIAL |
| 2 | Profile history (View) | Employees history (profile history) · `profilehistory_159` | 23.2.7 | PARTIAL |
| 3 | Confirmation (View) | Confirmations · `confirmation_51` | 23.4 | PARTIAL |
| 4 | — (Search node lives under Recruitment: "New hire"; page itself is Workforce) | New hires (upcoming join) · `addupcomingjoin_732` | 23.3.1 | PARTIAL |
| 5 | — (no Search node; menu `bgvsearch_2244`) | Background verifications | 23.3.2 | PARTIAL |
| 6 | Preboarding (View, Delete, Initiate) | Preboardings · `preboarding_779` | 23.3.3 | PARTIAL |
| 7 | Welcome note (View) | Welcome team members · `welcomenotesearch_704` | 23.3.4 | MISSING |
| 8 | Complete profile (View) | Profile completion · `welcometask_1582` | 23.3.5 | PARTIAL |
| 9 | Induction (View) | Inductions · `induction_1086` | 23.3.6 | PARTIAL |
| 10 | Induction evaluation (View) | Induction evaluations · `induction_1090` | 23.3.7 | PARTIAL |
| 11 | Transfer request (View) | Transfer requests · `transferrequest_919` | 23.5.1 | PARTIAL |
| 12 | Do transfer (View) | Transfers · `dotransfer_929` | 23.5.2 | PARTIAL |
| 13 | Pre transfer (View) | Pre-Transfers · `pretransfer_1104` | 23.5.3 | MISSING |
| 14 | Post transfer (View) | Post-Transfers · `posttransfer_1103` | 23.5.3 | MISSING |
| 15 | Resignation (View) | Resignation requests · `resignation_91` | 23.6.1 | PARTIAL |
| 16 | Resignation cancel (View) | Resignation cancellation · `cancelresignation_156` | 23.6.2 | PARTIAL |
| 17 | Clearance (View, Manual initiate, Delete, Initiate, Edit) | Clearances · `clearance_89` | 23.6.3 | PARTIAL |
| 18 | Final clearance (View, Delete, Initiate, Edit) | Final clearances · `finalclearance_157` | 23.6.4 | PARTIAL |
| 19 | Exit interview (View) | Exit interviews · `exitinterview_88` | 23.6.5 | PARTIAL |
| 20 | Misc. letter (View, Unpublish) | Adhoc letters (misc. letter) · `otherletter_438` | 23.7.2 | PARTIAL |
| 21 | Shared letter/documents (View, Unpublish) | Shared letters · `sharedletter_1072` | 23.7.3 | MISSING |
| 22 | Published letters/documents (View, Unpublish) | Shared letters by employee · `publishedlettersdocuments_2203` | 23.7.4 | PARTIAL |
| 23 | Confirmation letter (View letter, Generate letter, Unpublish) | Confirmation letters · `confirmationletter_97` | 23.7.1 | PARTIAL |
| 24 | Extension letter (View letter, Generate letter, Unpublish) | Extension letters · `extensionletter_356` | 23.7.1 | PARTIAL |
| 25 | Termination letter (View letter, Generate letter, Unpublish) | Termination letters · `terminationletter_358` | 23.7.1 | PARTIAL |
| 26 | Contract letter (View letter) | Contract letters · `contractletter_945` | 23.7.1 | MISSING |
| 27 | Transfer intimation letter (View letter, Generate letter, Unpublish) | Transfer intimation letters · `transferintimationletter_2241` | 23.7.1 | PARTIAL |
| 28 | Transfer letter (View letter, Generate letter, Unpublish) | Transfer letters · `transferletter_943` | 23.7.1 | PARTIAL |
| 29 | Resignation acceptance letter (View letter) | Resignation acceptance letters · `resignationacceptanceletter_2215` | 23.7.1 | PARTIAL |
| 30 | Relieving letter (View letter, Generate letter, Unpublish) | Relieving letters · `relievingletter_256` | 23.7.1 | PARTIAL |
| 31 | Add business partner (View) | Business partners · `addexternal_1069` | 23.8 | MISSING |
| — | — | Reports (`openReports`) | 23.13 | PARTIAL |
| — | — | Workforce Setup | `setup/08-workforce-payroll.md` | — |

---

## 23.1 Workforce panel and the shared register chrome (`rail_Workforce`, every `/app/processes/search/*/search` page)

Every Workforce page is the same shell: a left panel (People, Onboarding, Confirmations, Transfer, Separation, Workforce
letters, Business partners, Reports, Workforce Setup), a title, **Records / Reports** tabs, a search box, a "Filters"
button, an "IMPORTS" (or "Imports ▾") button, a split action button (e.g. "Add employee ▾") or "ACTIONS", cards with
"SHOW MORE" paging and, on bulk-capable pages, a note "In order to do bulk action, please select filters" (or "send bulk
email" / "download letter in bulk").

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Rail "Workforce" panel with groups People, Onboarding, Confirmations, Transfer, Separation, Workforce letters, Business partners, Reports, Workforce Setup; collapse arrows; panel search "Search" | One home for every HR operations register, grouped by employee journey | Navigation groups People (Employees, Directory, Confirmation reviews, Transfer requests, Exit cases …), Workforce (positions, planning), Onboarding plans / templates, Letters; no single "operations" panel grouped by journey | PARTIAL → built (WO-1: Workforce desk, journey-ordered over the existing registers, preset views) | WO-1: a "Workforce desk" navigation group (or cluster) ordering the registers by journey (People · Onboarding · Confirmations · Transfer · Separation · Letters · Business partners · Reports), each entry linking the existing resource with its preset filter; no new screen where a resource already exists |
| 2 | Tabs "Records" / "Reports" on each page | Records = the operational register; Reports = the page's default report with "Create View" | Registers are Filament lists; reports live in the report library (Analytics → Report library) | PARTIAL → built (WO-1: "Report" on each register opens the library template with the register's filters) | WO-1: a "Report" header action on each register opening the matching library template with the register's filters (no second report engine) |
| 3 | Search "Search employee by name, code, designation, email" | Find a record by the employee | Each list searches the employee name (some also code / email); designation is not searchable | PARTIAL → built (WO-2: shared employee search on every workforce register) | WO-2: one shared employee search (code, names, work email, designation) used by every workforce register |
| 4 | "Filters" flyer (`app-employee-search-filter`): SELECT FILTERS, APPLY FILTERS, RESET FILTERS, CLEAR ALL, filter chips | Filter panel with chips | Filament filters above the table (collapsible, kept in the session), "Reset" | HAVE | — |
| 5 | Filter "Search Employees" + "Add bulk employees" (`app-bulk-employee-search`: paste codes "eg.: Emp001,Emp002") | Restrict to a pasted list of employee codes | — | MISSING → built (WO-2: pasted codes to keep / leave out, ≤ 1000) | WO-2: filter "Employee codes" (comma / newline list, ≤ 1000) on every register |
| 6 | Filters "Organization" (`app-organization-filter`: company, business unit, department, sub-department, designation, grade, level, region, branch, sub-branch) and "Location" (`app-location-filter`) | Organisation-unit filter | Company, department, location, designation, grade, employment type, category, work mode, manager on Employees; registers other than Employees filter only by status / type | PARTIAL → built (WO-2: organisation filter group incl. business unit, division, level, department with sub-departments) | WO-2: the same organisation filter set (incl. business unit, division, level, parent department) as a reusable filter group on every register |
| 7 | Filters "Status" (`app-emp-status-filter`), "Type" (`app-emp-type-filter`), "Other Status", "Request Date" (from / to, "Please select both start and end date") | Employment status, type, other status, request date range | Lifecycle state and employment type on Employees; date filters absent | PARTIAL → built (WO-2: joining, added-on and request date ranges; "other status" waits for WF-08) | WO-2 date-range filters (joining, creation, request); "other status" waits for WF-08 |
| 8 | "Exclude employees with pending resignation", "Exclude Employees" | Leave out leavers / named people | — | MISSING → built (WO-2: leave out pending resignations; leave out pasted codes) | WO-2 toggle (exit case open) and an exclude-codes list |
| 9 | "Save Filters" ("Do you want to save these filters for future use?" Cancel / Yes, sure), saved filter list (`app-view-saved-filters`) | Named, reusable filter sets | Filters persist only for the session | MISSING → built (WO-1: saved views per person and register, default view) | WO-1: saved views per user and register in `ExperiencePreferences` (same store as RQ-11 / RQ-12 in matrix 20) |
| 10 | Card grid with avatar, "SHOW MORE" | Card view of records | Tables everywhere; the People directory offers cards | PARTIAL → built (WO-1: card view on Employees) | WO-1: card layout toggle on the Employees register (Filament `contentGrid` + a card view) |
| 11 | Settings gear "Customize" ("Please select the fields you want to view in the card") | Choose card fields | Toggleable table columns on Employees | PARTIAL → built (WO-1: card-field picker, kept with the saved view) | WO-1: card-field picker stored with the saved view |
| 12 | "Employee(s) selected" + bulk actions (the note "In order to do bulk action, please select filters") | Act on the filtered set | No bulk actions on the workforce registers | MISSING → built (WO-1: bulk actions on selected rows or all matching the filters; per-record result) | WO-1: bulk-action framework: select rows or "all matching the filters"; each record authorised and audited by its own service; result summary (done / refused with reasons) |
| 13 | Typed-count confirmation for bulk initiate / delete (`app-delete-task`: "You are going to initiate / permanently delete the … number of records", "Please enter the number of records … in the box to confirm", captcha, "This action is irreversible") | Guard against mass mistakes | Destructive actions ask a reason; no bulk | MISSING → built (WO-1: type the count over 25 records; cancel instead of delete per D-WO3) | WO-1: typed-count confirmation for any bulk action over 25 records; PeopleOS cancels instead of deleting (owner decision D-WO3) |
| 14 | Bulk progress popup (`app-bulk-process-popup`: "Please hold on…", OK) and result popup (`app-bulk-request-status-popup`) | Long bulk runs | Queued jobs exist (e.g. `IssueOnboardingItems`); no shared popup | MISSING → built (WO-1: queued run, notification, Bulk runs page with each record's outcome) | WO-1: queued bulk run with a progress / result notification |
| 15 | "IMPORTS" button on each page (the page's import types; `AvailableSearch.importIds`) | Upload data for this register | Upload screens (Employee import, Bank details upload) under Data → Uploads | PARTIAL | §23.10; WO-1 links each register to its upload types |
| 16 | Split action button "▾" (all Workforce actions: §23.9) | Start an HR action from any page | Header actions per resource; Employee 360 action groups | PARTIAL | §23.9 |
| 17 | "Looking for something else" (search pages / actions drop-down) | Jump to another page | Command palette / global search | HAVE | — |
| 18 | Settings drawer `app-common-settings` | Cross-module settings | Setup centre | N-A | Covered in `setup/08-workforce-payroll.md` §08.7 |
| 19 | Reports tab: report picker (`mat-select`), "Create View", "EDIT FILTER", column grid | Page report with saved views | Report library templates; saved reports (`analytics.reports`) | PARTIAL | §23.13; matrix 21 §21.2.1 (views, schedules) |
| 20 | Banners (subscription overdue, contact verification pending, low bandwidth, chat bubble) | Vendor chrome | — | N-A | Vendor / UI chrome |

---

## 23.2 People

### 23.2.1 Employees (profile) — Records (`/app/processes/search/employees/search`, `profile_50`; `app-manage-employee-search`)

Captured: cards in a three-column grid, the bulk-action note, "Imports ▾", "Add employee ▾", gear, "SHOW MORE". APIs
`Employee/EmployeeList` (returns `employeeCount`, `filterEmployeeCount`, `filterEmployeeIds` for bulk use),
`Employee/StaticPageLayoutDetails/3` (tenant field layout), `BGV/Setting?kycFor=E`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Card header: avatar / initials, "Name (#code)", company, second line (department *(inferred)*), ⋮ menu | Who the employee is | Table row: ID, name, designation, department, company (toggle); People directory cards: name, title, team, location | PARTIAL | WO-1 card layout on Employees (§23.1 #10) |
| 2 | Card "Date of joining :" | DOJ | Column "Joining date" (toggleable, sortable) | HAVE | — |
| 3 | Card "Reporting manager :" ("Not Available" when none) | Line manager | Column "Manager" (toggleable) | HAVE | — |
| 4 | Card "Employment type :" (Permanent, Consultant, Wages, Trainee, Contractual) | Type | Column "Type" (hidden by default) from the position's employment type | HAVE | — |
| 5 | Card status chip (Probation / Confirmed / Resigned / Relieved / Settled) | Employment status | Column "Status" = lifecycle state badge | HAVE | — |
| 6 | Filter "Employment status" (`employeeStatusId`) | Status filter | Filter "Status" (multi) | HAVE | — |
| 7 | Filters "Joining date" and "Creation date" (ranges) | Who joined / was added in a period | — | MISSING → built (WO-2: joining date and added-on ranges) | WO-2 date-range filters on `joining_date` and `created_at` |
| 8 | Filter "BGV Initiated" | Employees with / without a BGV | BGV cases on their own list; no employee filter | MISSING → built (WO-2: background check filter none / in progress / clear / discrepancy) | WO-2 ternary filter "Has a BGV case" (+ result) |
| 9 | Filter "Ekyc" (e-KYC status) | e-KYC done or not | No e-KYC (CA-15 vendors blocked) | MISSING | After CA-15 (external) |
| 10 | Filter "ID card" + "Employee Creation date / Employee DOJ / Last generated date", "Id card Status", "last generated Date" | Who has / lacks a generated ID card | No ID cards | MISSING | WO-20 with MS-17 (virtual ID card) |
| 11 | Filters "Joining status" / "Review status" (upcoming joiners: Joining, Not Joining, Blacklisted; review Not Initiated / Under review / Verified / Invalid) | Pre-joiner state | Lifecycle pre_employee / preboarding only | PARTIAL | §23.3.1 (WO-4) |
| 12 | Header count / `filterEmployeeCount` | How many match | Table pagination total | HAVE | — |
| 13 | Org chart flyer from a card (`app-org-chart-flyer` → position details & history, position org chart, clone position, inactivate position, Download Pdf) | See where the employee sits | Organisation map / designer, Position hierarchy page | PARTIAL | Org-chart PDF and position clone belong to the position matrix (matrix 20 §20.3.5, RQ-12) |
| 14 | Card "Team / Reporting Manager / Functional Manager" lines in the employee info | Both managers | Reporting relationships of every type (line, functional, dotted, project, mentor, buddy, HRBP) on the 360 "Reporting" tab | HAVE | — |

### 23.2.2 Employees (profile) — card menu and bulk actions (`app-manage-employee-search` dialogs; rights of Search → Workforce → Profile)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Employee info" (card menu) | Open the profile | Row "View" → Employee 360 | HAVE | — |
| 2 | "Activity log" ("Message", "Send Date", "View Details") | Messages / mailers sent to the employee | Notification deliveries (`NotificationDeliveryResource`, by recipient) and the Communications tab of the 360 | PARTIAL → built (WO-2: Activity tab from the audit trail; deliveries stay on the delivery log) | WO-2: "Activity" tab on the 360 merging notification deliveries and audit events about the employee |
| 3 | "Change employee code" (Old employee code `currentEmployeeCode`, New employee code `newEmployeeCode`) | Re-code one employee | Edit profile → "Employee code" (unique per tenant, audited with a reason) | HAVE | Bulk re-coding is Imports → Core → Employee code (§23.10.3) |
| 4 | "Reactivate employee" (relieved employee back to active) | Undo a separation / rehire | "Change lifecycle state" allows exited → active and alumni → active; `RehireEmployeeAction` exists but is not on a screen | PARTIAL → built (WO-2: Rehire / reactivate through RehireEmployeeAction and the lifecycle engine) | WO-2: "Rehire / reactivate" action on exited employees (reason, new joining date, keeps history) through `RehireEmployeeAction` |
| 5 | "Initiate separation" (Date of Resignation `dateOfResignation` "Select resignation date", Date of Leaving `dateOfLeaving` "Select final working day") | HR starts an exit for the employee(s) | Exit cases → "Initiate exit" (employee, type, resignation date, notice days, last working day, knowledge transfer, reason, rehire eligibility) — one at a time from the exit list | PARTIAL → built (WO-1: bulk Initiate exit and Initiate exit on the 360) | WO-1 bulk "Initiate exit" (same dialog, one exit case per employee) and the action on the 360 |
| 6 | "Initiate clearance" (bulk and card) | Start clearance tasks | Exit case → "Start clearance" (one case) | PARTIAL → built (WO-1: bulk Start clearance on exit cases) | WO-11 bulk start from the Employees / Clearances registers |
| 7 | "Initiate final clearance" | Start the final clearance step | Exit case → "Complete exit" (after clearances and settlement) | PARTIAL → built (WO-1: bulk Complete exit on exit cases) | WO-11 |
| 8 | "Initiate onboarding" (bulk and card) | Start onboarding tasks | Employee 360 → "Start onboarding" (template or best match) | PARTIAL → built (WO-1: bulk Start onboarding) | WO-1 bulk start (each through `Onboarding::start`) |
| 9 | "Initiate preboarding" | Start pre-joining tasks | Templates with "starts N days before joining" start on their day (`peopleos:onboarding:start-due`) or via "Start onboarding"; no separate preboarding start | PARTIAL | WO-8 |
| 10 | "Send completion mailer" / "Send profile completion mailer" ("In order to send bulk email, apply filters") | Ask employees to complete their profile | Onboarding `profile_section` items remind their owner; no mailer | PARTIAL | WO-8 profile-completion request (mail template + task) in bulk |
| 11 | "Initiate BGV" — "Please select the integration partner" (ON GRID / CFIRST / SPRING VERIFY), "BGV Partner:", "Please select the verification types:", "Following details are being shared" (Full Name, Phone no, Email id), Initiate | Send the employee to a BGV vendor | Employee 360 → "Initiate background check" (check types, written consent, consent document; manual provider only) | PARTIAL → built (WO-1: bulk Initiate background check, consent per person; vendors after CA-15) | Vendor partners after CA-15 (external); WO-1 bulk initiate |
| 12 | "Download BGV report"; card "BGV Completion Status - / BGV Partner - / BGV Quality - / Review Status -" | Vendor report and status on the card | BGV case view (status, result per check, evidence documents); no report file | PARTIAL → built (WO-2: background check column on Employees; vendor report after CA-15) | WO-2: BGV status columns on Employees; vendor report file after CA-15 |
| 13 | "Initiate e-KYC" / "Initiate e-kYC", "eKYC VERIFICATION MAILER", "e-KYC status", credits ("Out of eKYC Credits", "Credits low", "eKYC active") | Aadhaar / PAN e-KYC by a vendor | — | MISSING | After CA-15 (external vendor; credit metering N-A) |
| 14 | "Initiate CCRV" / "Download CCRV" | Court / criminal record verification by a vendor *(inferred)* | BGV check type "Criminal record (where lawful)" recorded manually | PARTIAL | After CA-15 |
| 15 | "Generate Id card" / "Generate Id Card" (bulk) | Produce ID cards | — | MISSING | WO-20 with MS-17 (ID card letter template rendered per employee; bulk PDF) |
| 16 | "Enable whatsapp bot" ("Are you sure you want to enable WhatsApp Bot on the employee's number?") | WhatsApp self-service for the employee | WhatsApp is a log-only channel stub | MISSING | Owner decision D-WO7 (WhatsApp provider); then a per-person opt-in |
| 17 | "Download profile data" ("In which format do you want to download this file?" Pdf) | Profile as a PDF | — (the 360 is screen only; employee export CSV is the import format) | MISSING → built (WO-2: printable profile, browser PDF; server PDF after the PDF-library decision) | WO-2: printable profile (HTML print view, PDF when the PDF-library decision lands), sensitive sections only with their permission, audited |
| 18 | "Send email" (`app-send-email`: Subject, Body, Add CC, Add BCC, Work email, Personal email, "Enter personal email") | Mail one or more employees | "Message" = `mailto:` the work e-mail; announcements to audiences | PARTIAL → built (WO-2: Send e-mail on the 360 and in bulk; copy to sender, no CC / BCC) | WO-2: "Send e-mail" (tenant mail transport, template optional, audited without the body) |
| 19 | Upcoming-joiner items on the same component: "Mark as not joining", "Candidate will not be joining", "CREATE EMPLOYEE" | Pre-joiner outcomes | §23.3.1 | PARTIAL | §23.3.1 rows |
| 20 | "Blacklist" (`app-candidate-blacklist`: "Reason for blacklisting", Comment ≤ 500) | Block a person from rehire | Exit "Eligible for rehire" + rehire-block reason (CA-3) at exit only | PARTIAL | WO-11: "Mark not eligible for rehire" on an exited employee (reason from the blacklist master) |
| 21 | "Message candidate" / "Log call" (`app-message-candidate`) | Candidate communication | — | N-A | Recruitment (separate RMS) |
| 22 | "Do you want to save these filters for future use?" / "Save Filters" | Saved filters | — | MISSING → built (WO-1) | §23.1 #9 (WO-1) |

### 23.2.3 Add employee (split button "Add employee"; `app-create-new-employee` → `app-create-employee-advance-tabs` steps Basic · Additional Details · Access & Settings; `app-create-employee-snapshot`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Step layout Basic → Additional Details → Access & Settings (Next / Previous / Create Employee) | Guided creation | Wizard Person → Employment → Position → Manager | HAVE | — |
| 2 | Prefix · `salutation` | Title | — | MISSING | MS-4 (matrix 22) adds `people.salutation`; WO-3 shows it in the wizard |
| 3 | First / Middle / Last name | Name | First, middle, last, preferred name | HAVE | — |
| 4 | Employee code · `employeeCode` (auto from code series or typed) | Code | "Employee code": Auto-generate (preview of the next code in the company / employment-type series) or Enter manually (checked for uniqueness) | HAVE | — |
| 5 | Date of birth, Gender, Blood group, Nationality | Personal basics | DOB (≥ 14 years), gender, marital status, nationality, blood group, birthday / wedding anniversary sharing | HAVE | — |
| 6 | Work email · `officialEmail`; ISD code + Mobile · `internationalCode`, `mobileNo` | Contact | Work email, work phone, personal email / phone (no ISD select) | PARTIAL | MS-4 phone format (E.164 with country code) |
| 7 | PAN · `panNumber`; UID (Aadhaar) · `uniqueIdentificationNumber` | Identity numbers at creation | Statutory details are set afterwards on the 360 ("Edit statutory details": PAN, Aadhaar reference, UAN, PF / ESIC numbers) | PARTIAL | WO-3: optional statutory step in the wizard (same `ChangeStatutoryIdentityAction`, CA-4 document rule applies) |
| 8 | Duplicate check "It seems this employee has worked in past with your organisation. His UID / PAN is linked with the following employee" (code, name, status, last working day, employee / organization resignation reason, Blacklisted; Cancel / Create employee) | Spot a returning employee before creating a new record | `PersonMatcher` on the Person step (same personal e-mail, phone or work e-mail = definite; name + DOB = possible) and "Existing person" picker; PAN / Aadhaar not compared; past exit details not shown | PARTIAL | WO-3: match also on PAN / Aadhaar reference (hashed compare), show the earlier employment, last working day, separation reason and rehire eligibility; block when not eligible unless overridden with a reason |
| 9 | Biometric Id · `biometricId` | Device user id | — | MISSING | MS-4 `employees.device_user_id`; WO-3 adds it to the wizard |
| 10 | Skill type · `skillTypeId` | Wage skill category | Designation `wage_skill_category` (CA-2) | PARTIAL | Per-employee override → BP-11 |
| 11 | Date of joining; Retirement date · `dateOfRetirement` | Dates | Joining date; probation end (blank = tenant default or confirmation policy); retirement from the exit policy's age (no date field) | PARTIAL | MS-2 shows the computed retirement date; no input needed |
| 12 | Employment type · `employeeTypeId`; Employment status · `employeeStatusId`; Date of confirmation | Status at creation (e.g. creating an already-confirmed employee) | Employment type on the Position step; a future joining date gives preboarding, otherwise joined → probation; already-confirmed staff need a lifecycle change afterwards | PARTIAL | WO-3: "Starting state" (pre-employee default; joined / probation / confirmed for historic loading, with confirmation date) — needs `employee.lifecycle` |
| 13 | Date of resignation / leaving / settlement, Other status + date + remarks, employee / organization / settlement separation reason, alumni status, blacklist reason / comments (`app-create-employee-snapshot`) | Load a historic leaver or set a status on edit | Exit cases record these through the exit flow; no direct fields | PARTIAL | Historic leavers through the import (WO-17 "exited" rows create a completed exit case); other status → WF-08 |
| 14 | Company, Business unit, Department, Sub-department, Designation, Region, Branch, Sub-branch, Grade, Level, dimensions (`options`) | Position dimensions | Company, location, business unit, division, department, team, designation, level, grade, employment type, employee category, work mode, cost centre | HAVE | Region / branch / sub-branch = locations (CA-7 / WF-12 for the geography masters) |
| 15 | Unique position code · `workforcePlanningRequestId`, Original unique position code, "Add new position" (`app-position-planning-flyer`) | Seat the hire in a planned position | "Transfer / promote" picks a position (seat) after hire; the hire wizard does not | PARTIAL | WO-3: optional "Position (seat)" on the Position step (open seats only, `workforce.view`) |
| 16 | Reporting manager · `reportingManagerId`; Functional manager · `functionalManagerId` | Managers | Line manager on the Manager step; functional manager later via "Change manager" (type functional) | PARTIAL | WO-3 adds functional manager (and HRBP) to the Manager step |
| 17 | Additional Details step: personal (`app-create-employee-personal`), family, bank, previous employer, other details (custom fields) ("No additional detail fields available", "Loading forms…") | Capture more at creation | Custom fields on the edit form; the other sections are 360 tabs after creation | PARTIAL | WO-3: optional steps (address, family, bank, previous employment, custom fields) reusing the 360 forms and their actions |
| 18 | Access & Settings step (`app-create-employee-setting`) | Login and settings at creation | Login linked on the edit form ("Login user" = an existing user) | PARTIAL | §23.2.5 rows 14–22; WO-3: "Create a login and send the invitation" toggle (user from the work e-mail, `UserInvitations::issue`) |
| 19 | "Permanent Residence", "Required", "Verify later" / "Verifying bank account details…", "We securely verify your bank details" | Bank account verification by penny-drop *(inferred)* | Bank accounts with per-bank number rules (BP-13); no live verification | MISSING | External provider (owner decision with CA-15) |
| 20 | Legal-entity warning "We recommend not to change legal entities from here … separation … new employee in the next company … PF ECR … tax" | Guard against company change on edit | Company changes go through "Transfer / promote" (position history); no warning about statutory effects | PARTIAL | WO-10: warning on a company change in transfers (statutory registrations differ) |
| 21 | "Do you want to enable this employee's login? Currently employee login is disabled" | Login switch on edit | User status on Users (`active` / inactive) | PARTIAL | §23.2.5 #14 |
| 22 | After creation: "Consider the following next steps: Add another employee · Employee other details · Employee setting · Complete employee details" | Next steps | Redirect to the new Employee 360 | PARTIAL | WO-3: completion panel with links (Add another, open sections, start onboarding) |
| 23 | Tenant field layout (`StaticPageLayoutDetails/3`: rename, hide, mandatory, visible to roles) | Tenant-shaped employee form | Custom fields only | MISSING | Matrix 02 §03.4 (`form_layouts`, WF-09); the wizard reads it once built |

### 23.2.4 Grantable rights of HR Desk → Search → Workforce → Profile (38)

HROne grants each right per role. PeopleOS grants by permission key (`employee.view`, `employee.update`,
`employee.position`, `employee.lifecycle`, `employee.sensitive.view`, `document.*`, `bgv.*`, `onboarding.*`, `exit.*`);
per-section view / update is the pending CA-6 "profile visibility" package.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | View | Open employee profiles | `employee.view` (non-sensitive 360) within the access scope | HAVE | — |
| 2 | View snapshot / Update snapshot | Basic details | 360 header and "All details"; Edit profile (`employee.update`) | HAVE | — |
| 3 | View personal / Update personal | Personal section | Person fields on Edit profile; Addresses / Emergency contacts tabs | HAVE | Field-level rules → CA-6 |
| 4 | View family / Update family | Family | Family & nominees tab (`FamilyMembersRelationManager`) | HAVE | — |
| 5 | View professional / Update professional | Education and experience | Qualifications and Experience tabs | HAVE | — |
| 6 | View health insurance / Update health insurance | Insurance | — | MISSING | WF-10 (gap register); MS-5 in matrix 22 |
| 7 | View employee other details / Update employee other details | Custom fields | Custom fields section on Edit profile / "All details" | HAVE | — |
| 8 | View bank / Update bank | Bank accounts | Bank accounts tab (sensitive, masked without `employee.sensitive.view`; changes through `ChangeBankAccountAction`) | HAVE | — |
| 9 | View previous employer / Update previous employer | Previous employment (and its tax) | Experience tab; previous-employer income / TDS on tax declarations | PARTIAL | MS-4 (matrix 22 §22.2.8) |
| 10 | View skills / Update skills | Skills | Skills tab | HAVE | — |
| 11 | View document / Update document | Documents | Documents tab (`document.view`, `document.upload`, `document.verify`) | HAVE | — |
| 12 | View history | Change history | Timeline tab; "All changes" (Change intelligence, `audit.view`) | HAVE | — |
| 13 | View setting / Update setting | Employee settings | Users screen (login) and other screens; no per-employee settings panel | PARTIAL | §23.2.5 #14–22 (WO-6) |
| 14 | View contract / Update contract | Contracts | — | MISSING | WO-5 with BP-16 |
| 15 | Upcoming join | See / manage upcoming joiners | Employees filtered to pre_employee / preboarding | PARTIAL | WO-4 |
| 16 | Change employee code | Re-code | Edit profile employee code | HAVE | — |
| 17 | Generate id card | ID cards | — | MISSING | WO-20 / MS-17 |
| 18 | Reactivate relieved employee | Rehire / reactivate | Lifecycle change exited → active | PARTIAL | §23.2.2 #4 |
| 19 | Send whatsapp invite | WhatsApp invitation | — | MISSING | D-WO7 |
| 20 | Bgv | Start BGV | "Initiate background check" (`bgv.manage`) | HAVE | Vendors after CA-15 |
| 21 | Initiate preboarding | Pre-joining tasks | §23.2.2 #9 | PARTIAL | WO-8 |
| 22 | Initiate onboarding | Onboarding tasks | "Start onboarding" (`onboarding.manage`) | HAVE | Bulk → WO-1 |
| 23 | Initiate clearance | Clearance | Exit case "Start clearance" (`exit.manage`) | PARTIAL | From the employee → WO-11 |
| 24 | Initiate final clearance | Final clearance | Exit case "Complete exit" | PARTIAL | WO-11 |
| 25 | Download employee details | Profile file | — | MISSING | §23.2.2 #17 |
| 26 | Download bgv report | BGV report file | BGV case view only | PARTIAL | After CA-15 |
| 27 | Download employee device report | Devices used by the employee | Attendance → Check-in devices (all employees, status, first / last seen); no per-employee report | PARTIAL | WO-6: device list on the employee's settings panel + export |
| 28 | Download mapped wifi report | Wi-Fi networks mapped to the employee for check-in | Check-in policy Wi-Fi rules (TO-2); Wi-Fi banks pending (CA-8) | MISSING | After CA-8 |
| 29 | Per-section view / update split (rows 2–14 as separate rights) | Fine-grained profile access | One `employee.update` for every section; sensitive data has its own permission | PARTIAL | CA-6 profile visibility (setup matrix 01 §1.21, pending rebuild) |

### 23.2.5 HR-side profile parts not covered by matrix 22 (Contract, Employee setting, History) and HR editing of the sections

Matrix 22 §22.2 rates every field of the ten profile sections from the employee's side. HR uses the same forms with
direct edit rights; this table rates the HR side once per section and adds the HR-only parts matrix 22 left here
(§22.2.13).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | HR edits Snapshot (`app-create-employee-snapshot`) | HR changes basic details | Edit profile (person + employment + custom fields, audit reason) | HAVE | Field gaps (prefix, biometric id, ISD) in matrix 22 §22.2.1 |
| 2 | HR edits Personal / Family / Professional / Bank / Previous employer / Documents / Skills sections | HR maintains the record | 360 relation tabs with create / edit (permissions per tab) | HAVE | Field gaps in matrix 22 §22.2.2–22.2.11 |
| 3 | HR edits Health insurance | Insurance | — | MISSING | WF-10 / MS-5 |
| 4 | HR edits Employees Other Details (dynamic form) | Custom fields | Custom fields section | HAVE | — |
| 5 | Contract list "Contract(s) information", "Inactive contracts", card (Vendor name, Period, Start date, End date) | An employee's contracts | — (employment type only; contract end not recorded) | MISSING | WO-5 (with BP-16): `employee_contracts` (employee, name, code, description, start, end, period, payroll by, vendor, vendor's unique code, attachment, lapse-leave choice, status) |
| 6 | "Create new contract": Contract name · `contractName`, description, code · `contractCode`, start / end date, Contract period · `contractPeriodValue` | Define a contract term | — | MISSING | WO-5 |
| 7 | Payroll by · `isPayrollBy` (Payment to employee / Payment to vendor), Vendor · `vendorId`, Vendor code, Unique code (Vendor) | Who is paid for a contract worker | — (no vendor master) | MISSING | WO-5 after the vendor master (setup 01 §1.33, WO-15) |
| 8 | Upload attachment (pdf, doc, docx, xls, xlsx, jpeg, jpg, png, bmp, zip, rar; ≤ 5 MB) | Contract document | Employee documents (allow-listed types) | PARTIAL | WO-5 stores the file as an employee document of type "Contract" |
| 9 | "Do you want to lapse leave?" · `isPurgeLeave`; "Which leaves do you want to lapse?" | Lapse leave balances at contract end | — | MISSING | WO-5: on contract end, a leave transaction "lapse" per chosen leave type through the leave service |
| 10 | Contract approval (`app-contract-approval`: Contract Name, Start, End, Attachment, Status, Lapsed Leave, View contract) | A contract goes for approval | — | MISSING | WO-5: workflow trigger `contract.created` |
| 11 | Renew contract (`app-renew-contract`: same fields + "Roll off employee") | Renew or end at expiry | — | MISSING | WO-5: renewal task N days before end (setting), outcomes renew (new contract) / roll off (exit of type contract expiry) |
| 12 | History tab (`app-create-employee-history`: Timeline / History, Type filter, Suspended on, By, Requested on, Confirmed on, Earlier / New, Approved on) | Lifetime events and field changes | Timeline tab (categories, dates); "All changes" (audit before / after) | HAVE | — |
| 13 | Employment history from EPFO (`app-employment-history-dialog`: verify by UAN / mobile / PAN, "User provided details" vs "EPFO records", Joining / Leaving, Restart verification) | Verify past employment against EPFO | — | MISSING | External provider (owner decision with CA-15); not built without an approved source |
| 14 | Settings: "Enable employee login" · `enableEmployeeLogOn` | Login on / off | User status on Users; employee ↔ user link on Edit profile | PARTIAL | WO-6: login switch on the 360 (creates / invites or deactivates the linked user through `UserInvitations` / user status) |
| 15 | Time zone preference · `timeZonePreference` | Person's time zone | Location time zone; user preferences | PARTIAL | — (location time zone governs; personal override via Preferences) |
| 16 | "Make this employee profile private on the directory?" · `isProfilePrivate`; "Hide this employee from the wishes section" · `isHiddenWishesSection` | Directory / celebration privacy | Birthday / anniversary sharing flags; no directory-private flag | PARTIAL | MS-6 (matrix 22 §22.2.13 #3) |
| 17 | "Allow login on multiple device" · `allowCheckInFromMultipleDevice`; Active devices (Device name, type, id, created date, EDIT, Logout); Attendance marking devices (Device ID, UUID, Inactivate, Device history); "Login devices", "Last session" | Device control per employee | Check-in devices (approve / block, first / last seen) and sessions (sign out everywhere) on separate screens | PARTIAL | WO-6: device and session panel on the employee's settings |
| 18 | Two factor authentication (Enable / Disable) · `enableMultifactor` | Per-person MFA | Security policy MFA by role / tenant; "Reset multi-factor authentication" on the user | PARTIAL | WO-6 (show state + reset; per-person forcing stays in the security policy) |
| 19 | Facial biometric (De-register, "Registration missing") · `isEnableFacialRecognition` | Face check-in data | Check-in photos (TO-2); no face template | N-A | No face-recognition data kept (biometric data minimisation) — owner decision D-WO12 |
| 20 | Wi-Fi attendance (Device Name, Device UUID, Add device, Download Instructions) | Wi-Fi check-in devices | Wi-Fi rules in the check-in policy; banks pending | PARTIAL | After CA-8 |
| 21 | SSO integration licence · `isActiveSingleSign`, `singleSignInId`; SCIM licence · `enableProvisioning` | SSO / SCIM per person | SSO connections per tenant (`SsoConnectionResource`); SCIM pending (CA-14) | PARTIAL | CA-14 |
| 22 | Licences: WhatsApp, team, geo pulse, facial recognition, expense live trip, project, LND (`enableTeamsAccess`, `enableWhatsappAccess`, `isEnableLiveTracking`, `isEnableContinuousLocation`, `enableProjectAccess`, `enableLNDAccess`) | Per-person paid add-ons | Entitlements per tenant plan (SaaS.3–5); features per tenant | N-A | Vendor licensing; PeopleOS licenses per tenant plan, not per person |

### 23.2.6 Employees (profile) — Reports tab (33 columns; report `employeeReport/1/3853`)

The Reports tab shows HROne's "Employee details"-style report for the page. The catalogue report is rated in matrix 21
§21.2.2 Workforce #11 (HAVE via `employee_master`); the columns are rated here against `EmployeesDataset`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Employee code, Full name | Identity | `employee_code`, `name` | HAVE | — |
| 2 | Prefix | Title | — | MISSING | MS-4 then the dataset |
| 3 | Date of birth, Gender, Blood group, Nationality, Age | Personal | `date_of_birth`, `gender`, `blood_group`, `nationality`, `age` | HAVE | — |
| 4 | Tenure to retirement | Time left before retirement age | Retirement age in the exit policy; not in the dataset | PARTIAL | WO-19: `retirement_date`, `years_to_retirement` from the employee's exit policy |
| 5 | Employment status; Employee other status | Status and secondary status | `lifecycle_state`; other status not recorded | PARTIAL | Other status after WF-08 |
| 6 | Company, Business unit, Department, Designation | Organisation | `company`, `business_unit`, `department`, `designation` | HAVE | — |
| 7 | Branch, Sub branch | Location levels | `location` (single level) | PARTIAL | Geography masters (CA-7 / WF-12) |
| 8 | Reporting manager code, Reporting manager, Functional manager | Managers | `manager`, `manager_code`; functional manager not in the dataset | PARTIAL | WO-19: `functional_manager` (+ code) |
| 9 | Personal status, Education status, Work experience status, Family status, Health insurance status, Nominee details status, Other dependent status, Child info status, Bank status, Previous employer details status, Document status | Completion / approval status of each profile section | Missing mandatory documents dataset (CA-4); no per-section completion | MISSING | WO-8: `ProfileCompletion` service (per section: filled / missing / pending change) feeding a `profile_completion` dataset and the Profile completion register (§23.3.5) |
| 10 | Notice period in confirmation; Notice period in probation | Notice days from the employee's separation policy | `Exits::noticeDays` (policy or setting); not in the dataset | PARTIAL | WO-19: `notice_days_probation`, `notice_days_confirmed` resolved per employee |
| 11 | "Create View" (save the column set and filters as a view) | Saved report view | Saved reports in the report library | HAVE | — |

### 23.2.7 Employees history (profile history) (`/app/processes/search/employeeProfileHistory/history`, `profilehistory_159`; `app-profile-history`, `-card`, `-flyer`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of profile change requests (one card per request): employee, company, department, "Profile type :" (Snapshot, Personal, Bank …), status (Approved / Rejected / Pending), "Last approved date :", "On :", "Pending With :" | Every profile change raised by employees (and HR), section by section | Profile change services (address, bank account, PAN / UAN, emergency contact, family member) on the service desk; Change intelligence lists audited changes per employee / field | PARTIAL | WO-7 (after MS-3 change requests): "Profile changes" register over change requests with section, status, pending with, decided on |
| 2 | Flyer "Profile history": Earlier / New per field, Remarks | What changed | Change intelligence: field, before → after (sensitive values masked) | HAVE | — |
| 3 | Workflow view (`app-request-workflow`) | Approval trail | Service request timeline / workflow run viewer | PARTIAL | RQ-2 (matrix 20) |
| 4 | "IMPORTS" / "ACTIONS" buttons | Page chrome | — | N-A | Chrome (§23.1) |
| 5 | Reports tab columns: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch | Employee block | Change intelligence filters by employee; no dataset | PARTIAL | WO-7: dataset `profile_changes` |
| 6 | Reports tab columns: Label, Old value, New value | Field and values | Audit changes (field, before, after) | PARTIAL | WO-7 (values masked unless sensitive permission) |
| 7 | Reports tab columns: Requested by, Requested on, Approved by, Approved on | Who / when | Audit actor and time; approvals on the service request | PARTIAL | WO-7 |

---

## 23.3 Onboarding

### 23.3.1 New hires (upcoming join) (`/app/processes/search/upcomingemployees/search`, `addupcomingjoin_732`; `app-upcoming-emp-card`, `app-create-upcoming-employee-snapshot`, `app-create-upcoming-employee-advance-tabs`, `app-upcoming-emp-document*`, `app-mailer-details-flyer`)

Captured: empty register with "Filters", note "In order to send bulk email, apply filters", "Imports ▾", "Add new hire
(upcoming join) ▾". API `Upcoming/Employee/Search`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of upcoming joiners (people created before joining, not yet employees) | Track offers accepted until day one | Employees in lifecycle pre_employee / preboarding (Employees filter "Status"); Home / Command centre "Joining in 14 days"; template `upcoming_joiners` | PARTIAL | WO-4: "Upcoming joiners" register (preset of Employees: pre-joining states, expected joining date, days to join, onboarding progress, BGV status, documents pending) |
| 2 | "Add new hire (upcoming join)" form — Basic Information: upcoming join photo, salutation, first / middle / last name, employee code, DOB, gender, nationality | Create a pre-joiner | "Hire employee" with a future joining date creates a preboarding employee (same person fields; no photo, no salutation) | PARTIAL | Photo → MS-7; salutation → MS-4 |
| 3 | Email: Official email · `officialEmail`, Personal email, "copy to" · `isCopyTo` *(inferred: copy mails to both addresses)*; ISD + Mobile | Contact | Work email, personal email / phone | PARTIAL | MS-4 (ISD) |
| 4 | Employment status & type: Skill type, Date of joining, Employment type | Terms | Joining date (future allowed), employment type on the Position step | HAVE | Skill type → BP-11 |
| 5 | Position: Position code (`workforcePlanningRequestId`, original code), "Add new position", company, business unit, department, sub-department, designation, region, branch, sub-branch, grade, level, dimensions, reporting / functional manager | Seat and organisation | Position dimensions + line manager; seat picked after hire | PARTIAL | WO-3 #15–16 |
| 6 | Advance tabs Basic / Additional Details (personal, family, bank, previous employer, other details; "No additional detail fields configured") | More data before joining | 360 tabs after creation | PARTIAL | WO-3 #17 |
| 7 | "Share for profile completion" — "Would you like to share profile completion mailer with upcoming joiner?" Yes / No | Invite the joiner to fill their own profile before day one | No pre-joiner access (gap register WF-13: owner decision on candidate access) | MISSING | WO-4 after owner decision D-WO11 (pre-joiner portal: limited, time-boxed sign-in or signed one-time form links) |
| 8 | Mailer flyer: Subject · `emailSubject`, Body · `emailBody` (rich text), Custom template / Saved templates, "You want to share profile sharing emailer on?" Personal / Work email, Add CC / BCC (employees or comma-separated e-mails), Save template (name, Default), Activity log | Compose the profile-completion mail | Notification templates per event (HR-editable subject / body), rules decide recipients | PARTIAL | WO-4: "Send profile-completion mail" using a notification template (CC / BCC to employees, audit without body); saved templates = notification templates |
| 9 | Card: Date of joining, Employment type, BGV Completion Status, "Not Initiated", Action logs, Edit, Activity Log | Joiner summary | Employees row (joining date, type, state); BGV on the 360 | PARTIAL | WO-4 register columns |
| 10 | Review steps 1–9 ("Review all the steps for upcoming joiner profile"): Joining details, personal, professional, other details, previous employer, bank, health insurance, family, documents | HR checks what the joiner filled | HR fills these on the 360; nothing filled by the joiner to review | MISSING | WO-4 after D-WO11 (each section reviewed: accept / send back) |
| 11 | Document review: Under Review / Verified / Invalid, "download all documents", Deselect, Download, Document preview, Comments | Verify joining documents | Documents tab: verify / reject (`document.verify`); one download at a time | PARTIAL | WO-4: "Download all" (zip, audited) |
| 12 | "Send For Profile Completion" (send back to the joiner) | Ask for corrections | — | MISSING | WO-4 after D-WO11 |
| 13 | "New joining date" (cannot be earlier than today) + Remarks | Postpone the joining date | Edit profile cannot change the joining date ("Set at hire; change via lifecycle actions"); "Mark as joined" sets the actual date | PARTIAL | WO-4: "Change expected joining date" on pre-joiners (reason, reschedules not-yet-started onboarding items and the start-due date) |
| 14 | "Mark as Not Joining" (`notJoiningRemark`; warning "This action will permanently delete all preboarding tasks … inbox cards will also be archived") | Joiner drops out | No outcome: pre_employee / preboarding cannot end (transitions only towards joining) | MISSING | WO-4 with owner decision D-WO1 (new end state such as `not_joined`, or cancel + archive); open onboarding plan cancelled (`Onboarding::cancel`), tasks closed, never deleted |
| 15 | "Convert" / "CREATE EMPLOYEE" ("You don't have rights to convert this employee") | Make the joiner an employee | "Mark as joined" (joining date → joined → probation; `employee.lifecycle`) | HAVE | — |
| 16 | Joining status filter: Joining / Not Joining / Blacklisted | Outcome filter | — | MISSING | WO-4 (with row 14) |
| 17 | Imports ▾ "New hire", "New hires (advance)" | Bulk pre-joiners | Employee import (add mode) with a future joining date creates preboarding employees | PARTIAL | §23.10.1 #18–19 |
| 18 | Reports tab (report 110/1968, "Create View", "EDIT FILTER") | Joining status report | Template `upcoming_joiners` (matrix 21 §21.2.2 Workforce #26 "Joining status" HAVE) | HAVE | — |
| 19 | Inbox task "Upcoming join" (`app-upcoming-employee`) | Joiner task card | Matrix 20 §20.2.2 #42 | PARTIAL | RQ-10 |

### 23.3.2 Background verifications (`/app/processes/search/bgv_verification/search`, `bgvsearch_2244`; BGV parts of `app-manage-employee-search`)

Captured: empty register, note "In order to send bulk email, apply filters", "IMPORTS", "ACTIONS"; APIs
`BGV/Setting?kycFor=U` and `Upcoming/Employee/Search` (the register lists upcoming joiners and employees with their BGV).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of people sent to BGV (joiners and employees) with status | Track verifications | Background checks list (`BgvCaseResource`: case, employee, provider, status, overall result, initiated, completed; filters status, result) | HAVE | — |
| 2 | Card fields "BGV Completion Status -", "BGV Partner -", "BGV Quality -" (Done — all checks cleared / some checks need clarification / multiple issues found), "Review Status -" | Vendor status and quality | Case status + overall result; per-check status and notes (Checks tab) | HAVE | Quality grading wording comes with vendors (CA-15) |
| 3 | Filters "BGV Initiated", "Review status", status | Narrow the register | Filters status, overall result | PARTIAL → built (WO-2: shared filters; employees without a case on the Employees register) | WO-2 (filter people without a case) |
| 4 | "Initiate BGV": integration partner (On Grid / CFirst / SpringVerify), package, verification types, details shared (full name, phone, e-mail), Initiate | Start a vendor BGV | "Initiate background check": check types, consent, consent document; manual provider | PARTIAL | Vendor drivers after CA-15 (`BgvProvider` contract already exists) |
| 5 | Bulk initiate / bulk e-mail ("In order to send bulk email, apply filters") | Many at once | One at a time | MISSING → built (WO-1: bulk initiate, consent per person) | WO-1 bulk "Initiate background check" (consent per person required — refused where missing) |
| 6 | "Download BGV report" | Vendor report | Evidence documents per check | PARTIAL | After CA-15 |
| 7 | e-KYC: "Initiate e-KYC", mailer, status, credits | Identity KYC | — | MISSING | After CA-15 |
| 8 | CCRV initiate / download | Criminal record check | Manual check type "criminal" | PARTIAL | After CA-15 |
| 9 | BGV setup: enable BGV, partner, mapping type (single / multiple legal entity), legal entities, packages, verification types with level count (max 3), "Enable background verification for: Upcoming join / Employee" (`app-bgv-verification`) | Configure vendors | — | MISSING | Setup matrix 01 §6.3–6.4 (CA-15, blocked on vendors) |
| 10 | Reports tab ("CREATE VIEW"; no default report) | BGV report | No BGV dataset | PARTIAL | WO-19: dataset `bgv_cases` (case, checks, result, dates) |

### 23.3.3 Preboardings (`/app/processes/search/preboarding/search`, `preboarding_779`; `app-onboarding-search`, `app-on-boarding-pre-task`, `app-onboarding-search-view-flyer`, `app-delete-task`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of joiners with pre-joining tasks; card "Joining date :", "Pre-requisite task", DONE / PENDING per task, ⋮ | Pre-joining task status across joiners | Onboarding plans list (employee, template, kind, anchor date, progress, open tasks, overdue, status; filter kind); tasks per employee on the 360 Onboarding tab; owners see their tasks in the task inbox | PARTIAL | WO-8: "Pre-joining tasks" register (one row per task: joiner, task, owner, due, status; filters phase, owner, status, joining date) |
| 2 | Filters Status, Joining date | Narrow | Plan status / kind only | PARTIAL | WO-8 |
| 3 | Task view: Comments, Related link, Video Link (player), Important, done, "Status :", "Joining date :" | Task detail with reference material | Task title, description, type, due, owner, mandatory, completion note / evidence | PARTIAL | WO-8: template items gain `link_url` and `video_url` (shown as links; no hosting) |
| 4 | Card ⋮ / bulk "Initiate" (start pre-boarding tasks) | Start tasks for selected joiners | "Start onboarding" per employee; templates that start N days before joining start themselves | PARTIAL → built (WO-1: bulk Start onboarding on the new-hires view) | WO-1 / WO-8 bulk start |
| 5 | Card ⋮ / bulk "Delete" with typed-count confirmation | Remove generated tasks | Plans can be cancelled by the service (`Onboarding::cancel`), not from a screen; tasks are skipped with a reason | PARTIAL | WO-8: "Cancel plan" action (reason; tasks closed, never deleted) — D-WO3 |
| 6 | Rights View / Delete / Initiate | Access | `onboarding.view`, `onboarding.manage` | HAVE | — |
| 7 | Reports tab (CREATE VIEW) / catalogue "Preboarding checklist" | Task status report | `onboarding_tasks` dataset with the phase filter (matrix 21 Workforce #36 HAVE) | HAVE | — |
| 8 | Inbox insight "Pre boarding" / task card (`app-on-boarding-pre-task`) | Pending pre-boarding tasks | Task inbox items for owners | HAVE | Org-wide counts → §23.11 |

### 23.3.4 Welcome team members (`/app/processes/search/welcomenotes/search`, `welcomenotesearch_704`; `app-welcome-note-search`, `app-welcome-employee`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of welcome notes; card "Shared by :", "Shared on :", "Read by :", "Read on :"; filter Status | Who welcomed whom and whether the joiner read it | — | MISSING | WO-8: `welcome_notes` (employee, manager, content, shared_at, read_at, status) + register |
| 2 | Manager task "Welcome team member": Welcome message (required), Things you should know, Things you should avoid at work, Comments | The manager writes a welcome for the joiner | Tenant-wide first-login welcome message (HP-9a, `experience.welcome.message`); social-wall joiner post | PARTIAL | WO-8: onboarding item type `welcome_note` owned by the manager (fields as HROne), shown to the joiner on first sign-in / My HR |
| 3 | Shortcuts (Shortcut name · `shortcutName`, Paste URL · `shortcutPath`, add more; "No shortcuts") | Useful links for the joiner | Quick links / pinned pages per user | PARTIAL | WO-8 (links inside the welcome note) |
| 4 | "Assign Buddy" (search buddy; "No Buddy Details") | Name a buddy | Reporting relationship type "buddy" via "Change manager"; onboarding items owned by the buddy | HAVE | Shown inside the welcome task by WO-8 |
| 5 | Buddy tasks: "Please select the tasks to be assigned to buddy", Task name · `taskName`, Due day · `dueDay`, Add task, "Suggested Buddy Task", USE | Give the buddy a checklist | Template items with owner type buddy and due offset | PARTIAL | WO-8: manager adds buddy tasks to the running plan (ad-hoc items) |
| 6 | Status IGNORED / overdue (inbox chrome) | Task outcome | Task statuses | HAVE | — |
| 7 | Joiner view "Your buddy will help you onboard with following things" | Joiner sees the note and buddy tasks | — | MISSING | WO-8 |
| 8 | Reports tab (report 202/1986) / catalogue "Welcome note report" | Report | — (matrix 21 Workforce #47 MISSING) | MISSING | WO-8 dataset `welcome_notes` |

### 23.3.5 Profile completion (`/app/processes/search/welcometask/search`, `welcometask_1582`; `app-welcome-task-search`, `app-welcome-task`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of joiners asked to complete their profile; card "Joining date :", "Complete profile"; filters Status, Joining date | Track profile completion | Onboarding items of type `profile_section` (complete only when the section is filled) inside plans | PARTIAL | WO-8: register over `profile_section` items + completion % from the `ProfileCompletion` service (§23.2.6 #9) |
| 2 | Joiner task "Profile completion — Takes just 10-15 minutes to finish!" with sections snapshot, personal, professional, family, health insurance, other details, bank, previous employer, documents, skills; Submit | The joiner fills their own profile | The employee cannot edit their record; changes through HR services (matrix 22 §22.2) | PARTIAL | MS-3 (self-service changes) then WO-8 links each item to the section form |
| 3 | "Send completion mailer" (bulk) | Remind | Task reminders to the owner | PARTIAL | WO-8 |
| 4 | Reports tab (CREATE VIEW) | Report | — | MISSING | WO-8 `profile_completion` dataset |

### 23.3.6 Inductions (`/app/processes/search/induction/search`, `induction_1086`; `app-induction-search`, `app-induction-request`, `app-induction-approval-details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of inductions; card "Induction period : start – end", status (e.g. Ignored), workflow flyer | Track each joiner's induction | Onboarding plans of kind induction (filter kind; anchor date, progress, status) | PARTIAL | WO-8: induction register columns start / end, status, approver |
| 2 | "Initiate induction approval": Start date · `startDate`, End date · `endDate` | Induction window | Plan anchor date; item due offsets | PARTIAL | WO-8: `onboarding_plans.ends_on` for induction plans |
| 3 | Expected standards · `inductionExpectedStandard`, Outcomes | What the joiner should reach | Template description | PARTIAL | WO-8: plan fields `expected_standards`, `outcomes` (text) |
| 4 | "Schedule Meeting" / "+ Add 1 On 1" (`app-schedule-one`) | Plan 1-on-1s in the induction | One-on-ones (performance) exist separately | PARTIAL | WO-8: item type `one_on_one` that schedules through the one-on-one service |
| 5 | "+ Add Initiative" (`app-assign-initiative`) | Assign initiatives | — (initiatives HP-8j pending) | MISSING | After HP-8j |
| 6 | Approver remarks · `remarks`; approval card "INDUCTION request approval" | The induction plan is approved | Plans start without approval | MISSING | WO-8: optional workflow trigger `induction.planned` (no workflow = starts as today) |
| 7 | Reports tab columns: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch | Employee block | — (no induction dataset) | PARTIAL | AN-29 `induction_plans` (matrix 21 Workforce #25) |
| 8 | Reports tab columns: Start date, End date, No of initiatives assigned, 1 on 1 meetings planned | Plan content | Plan dates; no initiatives / 1-on-1 items | PARTIAL | AN-29 + WO-8 |
| 9 | Reports tab columns: Request status, Request by, Request on, Action by, Action on, Pending with | Approval trail | — | MISSING | WO-8 with row 6 |
| 10 | Inbox "Induction" / "Initiate induction" | Task cards | Matrix 20 §20.2.2 #44 | HAVE | — |

### 23.3.7 Induction evaluations (`/app/processes/search/inductionevaluation/search`, `induction_1090`; `app-induction-evaluation`, `app-induction-eval-details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of induction evaluations; card "Induction period :", status | Track evaluations | Feedback requests of process `induction` (Me → Feedback for the respondent; HR reads with `onboarding.manage`); no register | PARTIAL | WO-8: evaluation column / filter on the induction register |
| 2 | Evaluation form: Progress · `requestProgress`, Plans · `requestPlan`, Challenges · `requestProblems`, Any other feedback · `otherMinutesOfMeeting` | Structured evaluation | Feedback template questions (any wording; starter "Induction evaluation" template in the library) | HAVE | — |
| 3 | "Show last 1-on-1" (agenda, date, time) | Context from the last meeting | One-on-ones on the performance tab | PARTIAL | WO-8 shows the latest one-on-one in the evaluation |
| 4 | Induction details (start, end, expected standards, outcomes, schedule meeting), "View all details" | Context | Plan view | PARTIAL | WO-8 (plan fields of §23.3.6) |
| 5 | "Induction extention date" | Extend the induction | — | MISSING | WO-8: "Extend induction" (new end date, reason) |
| 6 | "YES, IGNORE" / NO ("Are you sure you want to ignore?"), Comments, Approver remarks, activity log | Skip or decide the evaluation | HR may cancel a pending feedback with a reason | HAVE | — |
| 7 | Reports tab (report 257/1966) / catalogue "Induction evaluation report" | Report | Matrix 21 Workforce #24 PARTIAL | PARTIAL | AN-29 |

---

## 23.4 Confirmations (`/app/processes/search/confirmation/search`, `confirmation_51`; `app-confirmation-process-search`, `app-confirmation-process-card`, `app-confirmation-process-view-form` → `app-confirmation-task`, `app-confirmation-process-workflow`, `app-confirmation-detail`)

Captured: cards (company, department, "Confirmation date :", status Pending / Confirmed), "IMPORTS", "ACTIONS", "SHOW
MORE". API `globalSearch/Confirmation` (fields `dateOfConfirmation`, `confirmationIsBasedOnDays`,
`confirmationProbationDays`, `confirmationExtensionDays`, `confirmationNoOfExtension`, `feedbackId`, `confirmationStatus`,
approver, remarks).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of confirmations (one card per employee due / decided) | Track probation confirmations | People → Confirmation reviews (`ConfirmationReviewResource`): employee, probation end, extension no., status, feedback, extended to, decision note, decided by | HAVE | — |
| 2 | Card "Confirmation date :" and status chip (Pending / Confirmed) | Due date and outcome | Probation end date and review status | HAVE | — |
| 3 | Search by name / code / designation / e-mail; filters (organisation, status, dates) | Find | Search by employee name; filter status | PARTIAL → built (WO-2: shared search, organisation and date filters) | WO-2 shared search and organisation / date filters |
| 4 | Detail: "Would you like to proceed?", Confirmation Date, View profile, "YES, CONFIRM", "Extend Probation", "View last approver's status" | Decide | Row actions Confirm (note), Extend (months, note), Terminate (note; opens an exit of type termination); manager feedback must be in first | HAVE | — |
| 5 | Task view (`app-confirmation-task`): Comments, Termination Date, "Completed by", "Completed on", dynamic feedback form | What was decided, by whom, with the feedback | Decision note, decider, decided at; feedback statuses (answers to readers with `employee.lifecycle`) | HAVE | — |
| 6 | Workflow flyer (approval trail with several approvers) | Multi-level approval | Decided by one person with `employee.lifecycle` (never about themselves); no workflow levels | PARTIAL | WO-9: optional workflow trigger `confirmation.decided` (proposal by the manager, approval by HR) — no workflow = today's single decision |
| 7 | Confirmation by days or months (`confirmationIsBasedOnDays`, probation / extension days, number of extensions) | Policy basis | Confirmation policy: probation months, extension longest / how many (WF-1) | PARTIAL | Days basis → setup matrix 08 §08.1 |
| 8 | Bulk confirm (ACTIONS) *(inferred)* | Decide several | One at a time | MISSING → built (WO-1: bulk Confirm through ConfirmationReviews) | WO-1 bulk confirm (each through `ConfirmationReviews::confirm`; refused where feedback is pending) |
| 9 | Reports tab columns: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch | Employee block | No confirmation dataset (templates `probation_due`, `probation_overdue`, `confirmations_this_month` read employee dates) | PARTIAL | AN-29 `confirmation_reviews` (matrix 21 Workforce #6–#7) |
| 10 | Reports tab columns: Expected confirmation date, Confirmation date as per policy, Confirmation date, Confirmation status | Dates and outcome | Probation end (= expected / as per policy), confirmation date on the employee, review status | PARTIAL | AN-29 |
| 11 | Reports tab columns: Pending with, Approved by, Approved on, Approver remarks | Decision trail | Decided by / at, decision note (not in a dataset; no "pending with") | PARTIAL | AN-29 + WO-9 |
| 12 | Confirmation / extension / termination letters from the decision | Letters | Policy letters on confirm / extend / terminate (`PolicyLetters`) | HAVE | Registers → §23.7.1 |

---

## 23.5 Transfer

### 23.5.1 Transfer requests (`/app/processes/search/transfer/search`, `transferrequest_919`; `app-transfer-search` → `app-transfer-cards`, `app-transfer-request`, `app-transfer-request-view-form`, `app-transfer-approval-details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of transfer requests; card "Transfer request date :", "Transfer approval date :", status | Track requests | People → Transfer requests (`TransferRequestResource`): employee, from (effective date), changes (before → after summary), reason, allowances, requested by, status | HAVE | — |
| 2 | Filters Status, Transfer request date, Transfer approval date, Transfer date | Narrow | Filter status | PARTIAL | WO-10 date-range filters (requested, reviewed, effective) |
| 3 | "Transfer request" (split button; on behalf) — Requested transfer date · `suggestedTransferDate` | When it should take effect | "Request a transfer": From (effective date) | HAVE | — |
| 4 | "What do you want to change?" (`options`) then "Please fill the new fields for transfer": position · `positionId`, business unit, department, sub-department, designation, grade, level, region, branch, sub-branch | Choose which dimensions change, then the new values | The transfer policy's allowed dimensions as fields; blank = unchanged; summary shows before → after | HAVE | Position (seat) on a request → WO-10 |
| 5 | Reporting manager · `reportingManagerId`, Functional manager · `functionalManagerId` | New managers | New reporting manager (line) | PARTIAL | WO-10: functional manager on the request |
| 6 | Reason · `reasonId` (transfer reasons master: active, for employee / approver, remark mandatory) | Why | Reason (transfer reasons master, WF-2) | HAVE | — |
| 7 | Remarks · `requestedRemarks` | Note | Note | HAVE | — |
| 8 | Upload attachment (≤ 5 MB; pdf, doc, docx, jpeg, jpg, png, bmp) | Supporting document | — | MISSING | WO-10: attachment on transfer requests (documents disk, allow-list) |
| 9 | "Please note, transfer will be in effect in the system from transfer effective date" | Information | Effective-dated position changes | HAVE | — |
| 10 | View form: Suggested Date, Reason, Remarks, Transfer Details, Attachment, Back | Read the request | Summary in the list and the approval card | HAVE | — |
| 11 | Approval card "Transfer request approval" (requested date, reason, remarks, transfer details, comments, attachment) | Approve | Approve / reject (note) on the list and the Approval Center (`TransferApprovalSource`); never by the requester or the employee | HAVE | — |
| 12 | Cancel a pending request | Withdraw | "Cancel" (reason) | HAVE | — |
| 13 | Reports tab (report 209/1984) / catalogue "Transfer request" | Report | Matrix 21 Workforce #45 PARTIAL (`transfers` template = completed moves only) | PARTIAL | AN-29 `transfer_requests` |

### 23.5.2 Transfers — do transfer (`/app/processes/search/dotransfer/search`, `dotransfer_929`; `app-do-transfer-details`, `app-do-transfer-view-form`, `app-do-transfer-approval`)

In HROne an approved request becomes a "Do transfer" task where HR fixes the effective date and side effects; PeopleOS
applies the approved request directly (`TransferEmployeeAction`) and also lets HR transfer directly ("Transfer / promote").

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of final transfers (`Transfer/Insight/Search/FinalTransfer`), filters as §23.5.1 #2 | Transfers awaiting / done by HR | Approved transfer requests (status filter); position history per employee; template `transfers` | PARTIAL | WO-10: "Transfers" preset (approved + effective date) |
| 2 | Effective transfer date · `finalTransferDate` (may differ from "Requested Transfer Date") | HR fixes the date | The request's effective date is applied as is | PARTIAL | WO-10: approver may set the effective date (within the transfer policy's notice / backdating days) |
| 3 | Reason · `reasonId`; approver comment / comments | Decision note | Review note | HAVE | — |
| 4 | Allowances (`allowance[]`: Allowance · `allowanceId`, Amount, Variable · `allowanceVariableId`; add / remove rows) | One-off transfer allowances to payroll | Transfer allowances (pay components marked as such, amount; paid once by payroll — WF-3) | HAVE | Variable-pay link → BP-9 variable plans if needed |
| 5 | "Do you want to change goals?" · `isGoalChanged` | Re-open goals after a move | — | MISSING | WO-10: flag raising a goal-sheet review task for the new manager (performance hook; no change without HP-8d rules) |
| 6 | "Do you want to revise salary structure?" · `isSalaryRevised` | Start a salary revision | Compensation changes are separate | MISSING | WO-10: flag opening a draft compensation change (`compensation.propose`) for the employee |
| 7 | "Do you want to delete all approved geo-locations for this employee transfer?" · `isRemoveGeoLocation` | Clear location-bound check-in approvals | Approved locations stay until changed | MISSING | WO-10: optional end-dating of the employee's approved locations (default off; D-WO9) |
| 8 | "Do you want to do transfer for other OUs mapped in policy?" · `isMapOtherOU` | Apply the policy's other mapped dimensions *(inferred)* | Transfer policy lists what may change | PARTIAL | Setup matrix 13 SV-6 (transfer policy advance setting) |
| 9 | Position and OU fields, managers (`positionId`, …, `functionManagerId`) | Final values | From the request | HAVE | — |
| 10 | Attachment (view) | Document | — | MISSING | WO-10 (§23.5.1 #8) |
| 11 | HR direct transfer without a request | Immediate transfer | Employee 360 → "Transfer / promote" (change type, effective from, dimensions, position seat, FTE, vacate seat, Before → After, reason) | HAVE | — |
| 12 | Legal-entity change from a transfer (statutory warning) | Company change | Allowed via dimensions | PARTIAL | WO-10 warning (§23.2.3 #20) |
| 13 | Reports tab (report 210/1951) / catalogue "Do transfer" | Report | Matrix 21 Workforce #9 PARTIAL | PARTIAL | AN-29 |

### 23.5.3 Pre-Transfers and Post-Transfers (`pretransfersearch` `pretransfer_1104`, `posttransfersearch` `posttransfer_1103`; `app-pre-transfer`, `app-pre-transfer-search`, `app-pre-tansfer-pre-task`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Pre-transfer register: tasks to finish before a transfer; card "Pre-requisite task", DONE / PENDING; filters Status | Checklist before the move (handover, asset return …) | — (transfers have no tasks) | MISSING | WO-10: transfer checklists = onboarding templates of new kinds `pre_transfer` / `post_transfer` (same items, owners, due offsets from the effective date), started when a transfer is approved |
| 2 | Post-transfer register: tasks after the move (same card) | Checklist after the move (new access, induction in the new unit …) | — | MISSING | WO-10 |
| 3 | Task card: "Assigned date :", "Expected completed date :", "Completed date :", Comments, Related link, Video link, Delegate, Done, "On / By" | Do a checklist task | Onboarding task mechanics (owner, due, complete with note / evidence) exist for onboarding only | MISSING | WO-10 reuses `Onboarding` tasks (links → WO-8 #3) |
| 4 | Inbox "Pre transfer" / "Post transfer" | Task cards | — | MISSING | WO-10 (task inbox via the onboarding task source) |
| 5 | Reports: catalogue "Pre transfer checklist", "Post transfer checklist" | Report | Matrix 21 Workforce #34–#35 rated PARTIAL ("transfer tasks"); the code has no transfer tasks | MISSING | WO-10 (`onboarding_tasks` dataset filtered by kind); matrix 21 rows to be corrected |

---

## 23.6 Separation

### 23.6.1 Resignation requests (`/app/processes/search/separation/search`, `resignation_91`; `app-separation-process-search`, `-card`, `-view-form` → `app-separation-task`, `-workflow`, `app-resignation-detail`, `app-employee-resignation-card-view`, `app-review-relieving-details`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of resignations; card "Resignation date :"; "SHOW MORE" | Track resignations | Exit cases (`ExitCaseResource`): case no., employee, type, last working day (red within 7 days), status, clearance n / m, F&F, manager; filters status (open by default), type | HAVE | — |
| 2 | Search; organisation / date filters | Find | Search case no. / employee name; filters status, type | PARTIAL → built (WO-2: shared search and filters, resignation date and last-working-day ranges) | WO-2 shared search and filters (resignation date range, LWD range) |
| 3 | "Resignation request" (split button; HR raises on behalf: `app-resignation-request-flyer` → `app-request-separation`) | Resign on behalf | "Initiate exit" (HR) | PARTIAL | Form fields rated in matrix 20 §20.1.2 (RQ-6) |
| 4 | Workflow flyer: "Help: Once you submit cancel request from here, you need to go to insights page to approve it" | Withdrawal goes for approval | Withdraw is immediate (HR with `exit.manage`, or the employee) | PARTIAL | §23.6.2 (WO-11) |
| 5 | Detail card: Created on, Resigned on, Relieving date as per policy, Proposed last working date, Requested reason, Comment, Remarks, "View Task" | Resignation facts | Exit case view: resignation date, notice days, notice end (as per policy), last working day, separation reason, reason text | HAVE | — |
| 6 | Detail card: Total recovery amount, Actual recovery amount | Notice-shortfall recovery | Clearance recoverable amounts and F&F lines; no notice-recovery figure | PARTIAL | WO-11: notice shortfall days × daily rate as a settlement line (rule from the exit policy; formula not hard-coded) |
| 7 | "YES, ACCEPT" / "REJECT", "View last approver's status" | Decide the resignation | Through a workflow on "Exit case (resignation)": approved → accepted, rejected → withdrawn (`ResignationWorkflowBridge`); without a workflow the resignation proceeds unaccepted | PARTIAL | WF-02 / RQ-6: an accept / reject action on the case when no workflow runs (setting `exit.resignation.requires_acceptance`, default off) |
| 8 | Approver task: Approver reason · `approvedReason` (separation reasons offered to approvers) | Approver's view of the reason | Separation reason for approvers (CA-3) when HR initiates; not asked of a workflow approver | PARTIAL | WO-11: approver may set / change the approver reason on acceptance |
| 9 | Approver task: "Please suggest a relieving date" / Proposed relieving date, "Calendar day -" | Approver sets the last working day | LWD fixed at initiation (employee may request one); no change action | MISSING | WO-11: "Change last working day" (reason; within policy backdating; recomputes notice shortfall) on the case and in the acceptance |
| 10 | Approver task: Notice recovery relaxation days · `noticeRecoverRelaxationDays` + disclaimer "added due to holiday or week off on LWD" | Waive part of the notice shortfall | — | MISSING | WO-11: `exit_cases.notice_relaxation_days` (feeds row 6) |
| 11 | Approver task: "Do you want to consider this employee for future openings?" Yes / No · `alumniStatus`; Blacklist reason · `blacklistReasonId`, "Enter blacklist comments" · `blacklistComments` | Rehire eligibility | "Eligible for rehire" + "Why not eligible" (blacklist reasons, CA-3) on "Initiate exit"; not on an employee's own resignation afterwards | PARTIAL | WO-11: "Rehire eligibility" action on any open / completed case |
| 12 | Approver task: "Who will raise the replacement hiring?" · `replacementRequestBy` | Replacement requisition | — | N-A | Recruitment (separate RMS); a position vacancy is already visible in workforce planning |
| 13 | Approver task: Comments, "Attachment -" | Notes / file | Withdraw / complete notes; no attachment | PARTIAL | RQ-6 (resignation attachment) |
| 14 | Review relieving details (`app-review-relieving-details`): Pay group, Resignation reason, Date of resignation, Date of relieving, Notice period as per policy, Notice period recovery days, Last salary processed, Salary hold since, Salary hold amount; "Edit Relieving Date", "Edit Settlement Date", Leaving reason / Other reason, Settlement month | HR reviews pay effects before relieving | Exit case facts; salary holds on their own screen (`SalaryHoldResource`); settlement on the case's Settlement tab; no review panel or settlement month | PARTIAL | WO-11: "Relieving review" panel on the exit case (last payroll processed, open salary holds, notice shortfall, settlement month choice) |
| 15 | Reports tab columns: Employee code, Full name, Employment status, Current employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Biometric id | Employee block | `exits` dataset: employee code, name, department, designation, location, manager | PARTIAL | WO-19: company, business unit, current state on `exits`; biometric id after MS-4 |
| 16 | Reports tab columns: Request type, Resignation date, Request status | What / when / state | `type`, `resignation_date`, `status` | HAVE | — |
| 17 | Reports tab columns: Employee resignation type, Employee resignation reason, Approver resignation type, Approver resignation reason, Requestee remarks | Both sides' reasons | `separation_reason`, `separation_kind`, `reason_for_leaving` (interview); one reason per case | PARTIAL | WO-11 keeps the employee's and the approver's reason separately (rows 8, 11) |
| 18 | Reports tab columns: Relieving date as per policy, Relieving date, Notice period waived, Notice recover relaxation days | Dates and waivers | `notice_end_date`, `last_working_day`, `notice_days`; no waiver / relaxation | PARTIAL | WO-11 (rows 9–10) |
| 19 | Reports tab columns: Pending with, Approved by, Approved on, Approver remarks | Decision trail | Workflow history; `resignation_accepted_at` / `_by` not in the dataset | PARTIAL | WO-19 adds accepted by / on |
| 20 | Reports tab column: Alumni status | Rehire / alumni | `rehire_eligible`, `rehire_block_reason` | HAVE | — |
| 21 | Catalogue "Resignation", "Resignation report by level" | Reports | Matrix 21 Workforce #38 HAVE, #39 PARTIAL | PARTIAL | AN-30 (approval steps dataset) |

### 23.6.2 Resignation cancellation (`/app/processes/search/cancelseparation/search`, `cancelresignation_156`; `app-separation-cancellation-process-search`, `-view-form`, `-workflow`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of cancellation requests (cards as resignations) | Track withdrawals of resignations | Exit cases with status withdrawn (filter) | PARTIAL | WO-11: a withdrawal request (pending → approved / rejected) instead of an immediate withdrawal when the setting asks for it |
| 2 | View form: the original request (`app-request-separation`) + the task (`app-separation-task`) | What is being cancelled | Case view | HAVE | — |
| 3 | Workflow (approval of the cancellation) | Cancellation needs approval | Withdraw is immediate; a rejected resignation workflow withdraws | MISSING | WO-11: setting `exit.withdrawal.requires_approval` (default off = today) + workflow trigger `exit.withdrawal_requested`; D-WO8 |
| 4 | Reports tab columns (as §23.6.1 #15–#20) plus Level, Comments, Requested date | Cancellation report with approval level | Exits dataset (status withdrawn); no level / requested date | PARTIAL | WO-11 + AN-30 approval steps |

### 23.6.3 Clearances (`/app/processes/search/clearance/search`, `clearance_89`; `app-clearance-process-search`, `app-clearance-process-card`, `app-clearance-process-view-form` → `app-view-clearance-task`, `app-delete-task`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of leavers with clearance; card "Relieving date :", status ("Not initiated" …), ⋮; filter Status | Track clearance per leaver | Exit cases list "Clearance n / m" column; clearance stages on each case | PARTIAL | WO-11: "Clearances" register (one row per stage / task: leaver, LWD, stage, owner, status, recoverable) |
| 2 | Bulk "Employees selected" → Initiate / Delete (typed-count confirmation) | Start or remove clearance for many | "Start clearance" per case | PARTIAL → built (WO-1: bulk start; cancel instead of delete waits for WO-11 per D-WO3) | WO-1 bulk start; delete → cancel (D-WO3) |
| 3 | "Initiate clearance — Are you sure you want to manually initiate clearance?" | Start clearance before the automatic trigger | "Start clearance" (exit case, `exit.manage`) | HAVE | — |
| 4 | Task list per leaver: Task, "Assigned date :", "Expected completed date :", "Completed date :", "Assigning department :", On / By | Department-wise clearance tasks with dates | Stages (manager, IT, finance, asset, HR or the policy's mapped checklist) with items, owner user / role, status (pending / cleared / blocked / n.a.), remarks, cleared by / at; no per-task dates | PARTIAL | WO-11: per-stage due date (from the LWD and a stage offset) and expected / completed dates |
| 5 | Sub-status (`categoryStatus` per checklist) | Finer status inside a task | Status + remarks | PARTIAL | Setup matrix 13 SV-23 (checklist task depth) |
| 6 | Recovery Amount; Total recovery amount / Actual recovery amount | Dues to recover | "Amount to recover in F&F" when clearing a stage → settlement | HAVE | — |
| 7 | Attachment / Upload file, Video Link, Related link, Comment | Evidence and guidance | Remarks only | PARTIAL | WO-11: evidence file on a stage; links per item (SV-23) |
| 8 | DONE / PENDING, Important, Prequisite / Pre-requisite task (task dependent on) | Order and mandatory tasks | Stages independent; all must be cleared or n.a. before completion | PARTIAL | SV-23 (dependencies, auto-complete after due date) |
| 9 | Delegate ("Once a task has been delegated … not visible in your inbox") | Hand a task to someone else | Delegation (P4) for workflow tasks; clearance owners by role | PARTIAL | WO-11: clearance stages honour P4 delegation |
| 10 | Activity log | History | Audit events on the case | HAVE | — |
| 11 | IGNORE ("The resignation of an employee has been pulled back. So, the clearance task is not relevant now.") | Close tasks of a withdrawn exit | Withdrawing cancels the case (stages left as they are) | PARTIAL | WO-11: withdrawal marks open stages "not applicable — exit withdrawn" |
| 12 | Role mapping: "Employee will be permanently removed from the mapped role", Role, Current employee, Replace with, "Replace employee from the mapped role?", REMOVE / REPLACE, "User Role Not Assigned", ACCESS RIGHTS link | Hand over the leaver's approver / admin roles | Exit completion deactivates the user; approvers who cannot act fall to the manager reliever / delegation | PARTIAL | WO-11: "Roles held" step on the HR stage listing the leaver's roles and scopes with replace / remove (audited, `user.assign_roles`); D-WO10 |
| 13 | Team reassignment: "… team member are mapped to this employee", "To change manager for them, Click here" (`app-change-manager-search`), "Reassigning the team to a different manager is permanent" | Move the leaver's reportees | Reportees keep the leaver as manager until changed one by one | MISSING | WO-11 + WO-16 bulk change manager from the exit case (effective the day after LWD) |
| 14 | Rights Clearance: View, Manual initiate, Delete, Initiate, Edit | Access | `exit.view`, `exit.manage`, `exit.clear` (own stages) | HAVE | — |
| 15 | Setup: clearance checklist builder (`app-clearance`, `app-clearance-add-task`, `app-clearance-review`: type, name, tasks with assigning department, role, due day, description, links, attach file, sub-status, auto-complete, mandatory for settlement / creation, task dependencies, templates, clone) | Define checklists | Clearance checklists (name, items) mapped by the exit policy (WF-2) | PARTIAL | Setup matrix 13 SV-23 |
| 16 | Reports tab (report 9/1948) / catalogue "Clearance task", "Clearance pdf" | Reports | Matrix 21 Workforce #5 HAVE, #4 PARTIAL | PARTIAL | AN-29 no-dues certificate |

### 23.6.4 Final clearances (`/app/processes/search/finalclearance/search`, `finalclearance_157`; `app-final-clearance-process-search`, `app-final-clearance-process-view-form` → `app-view-clearance-task`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of leavers for final clearance; card "Relieving date :", "Not initiated", ⋮; filters | The last step after department clearances | Exit cases in status clearance / settlement | PARTIAL | WO-11 preset "Ready to complete" (all stages cleared) |
| 2 | Initiate (bulk / card), Delete, Edit (rights View, Delete, Initiate, Edit) | Start / undo final clearance | "Complete exit" (needs all stages cleared and an approved settlement, or "complete without settlement") | PARTIAL → built (WO-1: bulk Complete exit, refused when not ready) | WO-1 bulk complete (each refused when not ready) |
| 3 | Total recovery amount, Actual recovery amount, Comments, Save | Final dues decision | F&F settlement: calculate, add lines, approve, mark paid; clearance recoverables flow in | HAVE | — |
| 4 | Response fields `exitInterviewStatus` / manager / HR interview status, `isFeedbackInitiate`, `clearanceStatus`, `cancelRequestStatus` on the card | Overall readiness at a glance | Exit case view: clearance, settlement, interview, feedback statuses | HAVE | — |
| 5 | Reports tab (report 10/3850) / catalogue "Final clearance status" | Report | Matrix 21 Workforce #19 HAVE | HAVE | — |

### 23.6.5 Exit interviews (`/app/processes/search/exitinterview/search`, `exitinterview_88`; `app-exit-interview-process-search`, `-card`, `-view-form` → `app-exit-interview-task`, `app-exit-interview`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of exit interviews; card "Relieving date :" | Track interviews | Interview status on each exit case; feedback requests (exit interview by employee / manager / HR) on Me → Feedback; Exit insights (aggregates) | PARTIAL | WO-12: "Exit interviews" register (leaver, LWD, employee / manager / HR interview status, received on) |
| 2 | View form "Feedback" (dynamic form answers) | Read an interview | "Record exit interview" (standard form) and feedback answers for `exit.interview` holders | HAVE | — |
| 3 | Task: "RESIGNATION ACCEPTED", Resigned on, Notice period, Last working day, Reason, Exit Interview Type, "SHOW EXIT INTERVIEW", IGNORED | Interview task with context | Feedback request with due date = LWD; HR may cancel with a reason | HAVE | — |
| 4 | Reports tab columns: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch | Employee block | No interview dataset | PARTIAL | AN-29 `exit_interviews` (matrix 21 Workforce #17–#18) |
| 5 | Reports tab columns: Exit interview status, Resignation date, Relieving date, Received on, Overall remark | Interview status and summary | Exits dataset has `reason_for_leaving` from the standard interview; no status / received on | PARTIAL | AN-29 + WO-12 |

---

## 23.7 Workforce letters

### 23.7.1 Process letter registers — Confirmation, Extension, Termination, Contract, Transfer intimation, Transfer, Resignation acceptance, Relieving (`/app/processes/search/{confirmationletter,extensionletter,terminationletter,contractletter,transferintimationletter,transferletter,resignationacceptanceletter,relievingletter}/search`; `app-employee-letter-search` → `app-employee-letter-grid`, `app-employee-letter-process-card`, `app-employee-letter-view` → `app-letter-generation-detail`, `app-bulk-letter`, `app-bulk-download-letter`, `app-publish-status-shown`)

The eight registers are one component filtered by letter type (`LetterList/1/{2,3,5,26,34,18,128,4}`; each type maps a
template, a mode and "upload allowed"). A process (confirmation, transfer, resignation …) assigns a letter to the
register; HR generates, signs and publishes it. PeopleOS: Letters (`LetterResource`: number, employee, type, subject,
status, created, issued; filter type; "Generate letter"; approve / reject / issue / download / Word draft), policy letters
drafted on confirm / extend / terminate and on exit start / completion, the 360 "Generate a letter" and the exit case
"Generate letter".

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | One register per process letter type | A queue per letter kind | One Letters list with a type filter | PARTIAL | WO-13: per-type presets (navigation entries with the type filter) — no new screens |
| 2 | Pending letters assigned by a process (card shows "Type :", status; columns "Letter process", "Assigned on") | Letters waiting to be generated after an event | Policy letters are generated as drafts immediately (`PolicyLetters::draft`); status draft / pending approval | PARTIAL | WO-13: letters keep `source` (already stored) shown as "Process"; draft = assigned |
| 3 | Filters: Status, "Choose letter template", "Signature Type", Employee type, Generated by, Generated date, User type; search employee | Narrow the register | Filter type; search employee | PARTIAL | WO-13 filters status, template, signatory, generated by, generated date range, employment type |
| 4 | Card: "Generated by :", "Generated on :", "Type :", document viewer | Who generated it | Requested by, created at; view page | HAVE | — |
| 5 | Bulk generate (`app-bulk-letter`): "Do you want to change letter template, if not then the letter will be generated as per policy" Yes / No, Select Template, "Generate & publish", Ignore, Clear selection | Generate many letters at once | One employee per generation | MISSING | WO-13: bulk generate for selected employees / pending drafts (template = policy's unless changed), each through `Letters::generate`; queued; result summary |
| 6 | Bulk download (`app-bulk-download-letter`: "In order to download letter in bulk, apply a status filter as published"; filters Generated date, Generated by, Letter template, Status) | Download many issued letters | One download at a time (audited) | MISSING | WO-13: zip of issued letters in the filter (each download audited, `letter.view`) |
| 7 | Generation detail: Select Template, View template | Pick the template | Template select; preview in the letter studio | HAVE | — |
| 8 | Signatories: Primary, Secondary, Third, Fourth signatory | Up to four signers | One signing authority per letter (letter signatories with signature image) | PARTIAL | WO-13: up to four signatories per template / letter (`letter_signatories` ordered list); variables `{{ signatory_2.name }}` … |
| 9 | "Initiate Digital Signature", "Signature expiry date", "Employee will sign their own letter", "Copy link", signature status per signatory ("Not initiated"), "Download audit report" | E-signing with an audit trail | — | MISSING | After SV-17 (e-sign provider; owner decision) |
| 10 | "Publish" | Release the letter to the employee | "Issue" (after approval when the template requires it): stored as an employee document, employee notified | HAVE | — |
| 11 | "Revoke" / rights "Unpublish" ("Please download / unpublish the letter for the employee") | Withdraw a published letter | — (issued letters stay) | MISSING | WO-13: "Revoke" (reason; letter status revoked, the document hidden from the employee, never deleted) — D-WO5 |
| 12 | "Re-generate & Publish" (warning: deletes the current letter and publishes a new one even if digitally signed) | Replace a wrong letter | Generate a new letter; the old one stays | PARTIAL | WO-13: "Supersede" (new letter linked to the old, old revoked) — D-WO6 |
| 13 | "Do you want to download letter?" · `isDownload` | Download after generation | Download action | HAVE | — |
| 14 | E-mail sharing: "Do you want to share the letter on email Id?", Personal / Official email (`personalMailId`, `officialMailId`), Additional recipients, Add CC / BCC (employees, personal e-mails), "Where do you want to share the email?" | Mail the letter | Notification `letter.issued` in-app / e-mail without the file | PARTIAL | WO-13: "E-mail the letter" (attachment, to work / personal address, CC / BCC; tenant mail transport; audited without content) |
| 15 | Acknowledgement Details: Date, Time, IP Address, Coordinates; "Acknowledgement is pending" / "No acknowledgement is required" | Employee acknowledges the letter | — | MISSING | WF-01 / RQ-10 (matrix 20 §20.2.2 #30): template flag `requires_acknowledgement`, `letters.acknowledged_at` / `_ip` |
| 16 | Warnings "This employee's wages are currently below the defined minimum wage …", "Exclusions are greater than wages …" | Compliance check on salary letters | Minimum-wage checks in payroll compliance; not on letters | PARTIAL | WO-13: salary-annexure letters show the compliance warnings of the structure (read from the payroll checks) |
| 17 | "Check Out All Approver", workflow | Letter approval levels | Letter approval in the Approval Center (single approver, `LetterWorkflowBridge` when a workflow runs) | HAVE | — |
| 18 | Rights per register: View letter, Generate letter, Unpublish | Access | `letter.view`, `letter.issue`, `letter.manage` | PARTIAL | Unpublish → row 11 |
| 19 | Reports tab columns: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch | Employee block | `letters` dataset (templates `letters_issued_this_month`, `letters_pending`) | HAVE | — |
| 20 | Reports tab columns: Letter process, Letter type, Letter template, Letter status, Assigned on, Generated by, Generated on | Letter facts | Type, template, status, created / issued, requested by | HAVE | "Process" = source (row 2) |
| 21 | Confirmation letters (`confirmationletter_97`) | Letters on confirmation | Type `confirmation`; drafted on confirm by the confirmation policy | HAVE | — |
| 22 | Extension letters (`extensionletter_356`) | Letters on probation extension | Drafted on extend by the confirmation policy (any template); no `extension` type | PARTIAL | WO-13 adds letter types `probation_extension`, `termination`, `contract`, `transfer_intimation`, `resignation_acceptance` (WF-01) |
| 23 | Termination letters (`terminationletter_358`) | Letters on termination | Drafted on terminate (confirmation policy `letter_on_terminate`); types warning / show cause; no `termination` type | PARTIAL | WO-13 |
| 24 | Contract letters (`contractletter_945`) | Letters for contracts | — (no contracts) | MISSING | WO-13 after WO-5 |
| 25 | Transfer intimation letters (`transferintimationletter_2241`) | Notice of an upcoming transfer | — (transfers draft no letter) | MISSING | WO-13: transfer policy `letter_on_request_approval` (intimation) and `letter_on_effect` (transfer letter) |
| 26 | Transfer letters (`transferletter_943`) | Letter on transfer | Type `transfer`, generated by hand | PARTIAL | WO-13 (row 25) |
| 27 | Resignation acceptance letters (`resignationacceptanceletter_2215`) | Letter on accepting a resignation | Exit policy `letter_on_exit` drafts at exit start (not on acceptance) | PARTIAL | WO-13: exit policy `letter_on_acceptance` |
| 28 | Relieving letters (`relievingletter_256`) | Letter at relieving | Type `relieving`; exit policy `letter_on_completion`; exit case "Generate letter" | HAVE | — |

### 23.7.2 Adhoc letters (misc. letter) (`/app/processes/search/otherletter/search`, `otherletter_438`; "Generate letter (misc. letter)" → `app-other-letter-generation`; "View schedules")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of miscellaneous letters (grid as §23.7.1 rows 3–4, 19–20; note on bulk download) | Any-purpose letters | Letters list (types appreciation, warning, salary certificate, employment certificate, NOC, custom …) | HAVE | — |
| 2 | "Select event frequency": trigger type One time / Event based (`triggerTypeId`) | Send now or on an event | Generate now only | PARTIAL | WO-13: letter schedules (`letter_schedules`: template, trigger, event, offset, audience, next run; daily command) |
| 3 | One-time events "Publish Now" / "Publish Later" + "Select publish date", "Select time of publish" | Release at a chosen time | Issue immediately after approval | MISSING | WO-13 (scheduled issue) |
| 4 | Event types: Date of Joining, Date of Birth, Date of Confirmation, Date of Resignation, Date of Leaving, Retirement Date, Work Anniversary, Absconding; "Select before or after" + "Number of days" | Automatic letters around life events | Policy letters on confirm / extend / terminate / exit start / completion; celebrations post greetings, not letters | PARTIAL | WO-13 schedules on employee date events (offset ±N days) |
| 5 | Applicability: "For a specific employee", "Employees organisation-wise" (organisation filter), "For upcoming joiner" (select / add bulk upcoming joiners), "Search employees", "Add bulk employees" | Audience | One employee | PARTIAL | WO-13 audience = employees, organisation filter (`AudienceCriteriaSchema` already used by announcements), pre-joiners |
| 6 | Template · `letterTemplateId`, title · `title`, View template | What to send | Template, extra variables | HAVE | — |
| 7 | "Save for later use" · `isSaveForLetterUse` | Keep the set-up | — | MISSING | WO-13 (saved schedule) |
| 8 | E-mail options ("Send miscellaneous email to": work / personal, CC / BCC; download letter) | Delivery | §23.7.1 #13–14 | PARTIAL | WO-13 |
| 9 | Signatories 1–4 with e-mail type each (`signatory1EmailType` …), "Signature Expiry (Days from Initiation)", "Employee will sign their own letter", Copy link | E-sign set-up | One signatory, no e-sign | PARTIAL | §23.7.1 #8–9 |
| 10 | Remarks; "Generate Letter" | Submit | Generate | HAVE | — |
| 11 | "View schedules" / "Schedule letters": Template name, Event frequency, Occurrence, Scheduled date, Status, Created on, Action | Manage scheduled letters | — | MISSING | WO-13 (schedules list) |
| 12 | Rights Misc. letter: View, Unpublish; action "Generate letter (misc. letter)" | Access | `letter.view`, `letter.issue` | PARTIAL | Unpublish → §23.7.1 #11 |
| 13 | Word template merge-field guide (`app-letter-type-document`: Insert → Quick Parts → Field → Mail Merge → MergeField, upload again) | Build Word templates | Letter studio: rich-text or Word templates with a variables guide and checks | HAVE | — |

### 23.7.3 Shared letters (`/app/processes/search/sharedLetter/search`, `sharedletter_1072`; "Share letters/ documents" → `app-publish-letter-search`; register `app-shared-letter-card`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Share letters/ documents": Letter type · `letterTypeId` (Appointment, Confirmation, Salary, Transfer, Form 16, Other, Medical insurance E-cards, Virtual ID cards, Contract, Employee details document), Letter name · `letterName` | Publish ready-made files (made outside) to many employees | Documents uploaded one employee at a time (Documents tab); Form 16 has its own upload (My tax certificates) | PARTIAL | WO-14: "Share documents in bulk" (document type, title) |
| 2 | Step 1 DOWNLOAD the sheet (employee code + file name, "Do not add extension") | Map files to employees | — | MISSING | WO-14: template sheet (employee code, file name) |
| 3 | Step 2 Upload the edited sheet | Provide the mapping | — | MISSING | WO-14 on the staged upload pipeline (new import type `document_share`, P2 governance) |
| 4 | Step 3 "Drop Zip file here or Browse files" ("Zipped file has been uploaded. Please submit letters to publish.") | Upload all files at once | — | MISSING | WO-14: zip (size cap, allow-listed types, each file scanned like other uploads) matched to the sheet; unmatched rows reported |
| 5 | "Do you also want to email the letter" Yes / No, "Share on" · `mailOn` | Notify / mail employees | Document uploads notify nobody | PARTIAL | WO-14: notify in-app (default) / e-mail with link |
| 6 | Register card: "Letter type :", "Published on :", "Published letters :" (count), status; filters Letter type, Status | One row per bulk share | — | MISSING | WO-14 register of share batches |
| 7 | "Unpublish" — "Select employees to unpublish letters" (Employee code, Employee name, Attachment, View) | Withdraw shared files | Document delete (`document.delete`) one at a time | PARTIAL | WO-14: unpublish = hide from the employee (audited), never delete (D-WO5) |
| 8 | Rights Shared letter/documents: View, Unpublish; action "Share letters/ documents" (Action → Workforce) | Access | `document.upload`, `document.delete` | PARTIAL | WO-14 permission `document.share` |
| 9 | Catalogue "Shared letter/document report" | Report | Matrix 21 Workforce #41 PARTIAL | PARTIAL | WO-14 dataset over share batches |

### 23.7.4 Shared letters by employee (`/app/processes/search/publishedLetterdocument/search`, `publishedlettersdocuments_2203`; `app-published-letter-document`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: one card per employee and published letter / document — "Letter type :", "Published on :", "Published By :" | What each employee received | Employee documents per employee (Documents tab); issued letters in Letters | PARTIAL | WO-14: "Published to employees" list (documents of company category + issued letters; filters type, published by, date) |
| 2 | Filters Letter type, Published By, Publish date, Created by | Narrow | — (per employee only) | MISSING | WO-14 |
| 3 | "Employees selected" bulk + Unpublish (rights View, Unpublish) | Withdraw for several | — | MISSING | WO-14 |
| 4 | View (`app-letter-generation-detail`) | Open the item | Document / letter view | HAVE | — |

---

## 23.8 Business partners (`/app/processes/search/externalconsultant/search`, `addexternal_1069`; `app-external-consultant` → `app-external-consultant-card`, `app-add-external-consultant`)

Business partners are non-employee people who sign in to HROne (consultants, vendors, BGV agents, customers). Captured:
cards with e-mail, mobile, ⋮ and the user type; "IMPORTS", "Add business partner ▾". APIs `External/Employee/logOnType/1`
(user types) and `External/Employee/search` (salutation, code, names, gender, nationality, mobile, DOB, user type,
reference, personal / official e-mail, vendor).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of business partners; card: avatar, e-mail, mobile, user type, ⋮ | Who outside the workforce has access | Users without an employee record exist (Users list: name, e-mail, roles, status); organisation contacts (CA-2) have no login | PARTIAL | WO-15 (gap register IP-04): "Business partners" = users of kind `partner` with a profile; owner decision D-WO2 on what partners may see |
| 2 | Filters User type, Status | Narrow | Users filters (status, role) | PARTIAL | WO-15 |
| 3 | Add / Edit: Salutation · `salutationId`, First / Middle / Last name, Business partner code · `employeeCode` ("Only a-z, A-Z, 0-9 and _"), DOB, Gender, Nationality | Partner identity | User: name, e-mail | PARTIAL | WO-15: `partner_profiles` (user_id, code (number series), salutation, names, DOB, gender, nationality) |
| 4 | User type · `userTypeId` (Customer, Recruiter, BGV, Vendor, System Consultant, Other) | Kind of partner, drives access | Roles only | MISSING | WO-15: partner type (option list); Recruiter = N-A (RMS) |
| 5 | ISD code + Mobile · `mobileNumber`, Personal email · `personalEmail`, Official email | Contact | User e-mail | PARTIAL | WO-15 |
| 6 | Vendor name · `vendorId` | Link to a vendor | — (no vendor master) | MISSING | WO-15 after the vendor master (setup matrix 01 §1.33) |
| 7 | "Send password" | Give the partner access | Invitation on user creation; "Send a new invitation" / "Send password reset link" on the user | HAVE | — |
| 8 | ⋮ "Disable login" | Revoke access | User status (deactivate), "Sign out everywhere" | HAVE | — |
| 9 | Imports → Workforce → Vendor (bulk partners / vendors) | Bulk load | — | MISSING | §23.10.1 #4 |
| 10 | Reports tab ("CREATE VIEW") / catalogue "Business partners Report" | Report | Matrix 21 Workforce #3 PARTIAL | PARTIAL | WO-15 dataset `partners` |

---

## 23.9 HR Desk actions — Action → Workforce [5] and the Workforce global actions (`globalAction`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "All action" (`allaction_252`) — the Actions tab of HR Desk listing every action the role may run | One place to start any HR action | Command Center / global search actions and quick actions per role | HAVE | — |
| 2 | Add employee (`profile_50`; right "Add employee") | Create an employee | Employees → "Hire employee" | HAVE | §23.2.3 |
| 3 | Add new hire (upcoming join) (`addupcomingjoin_732`; right "Add upcoming join") | Create a pre-joiner | Same wizard (a future joining date gives a preboarding employee) | PARTIAL | §23.3.1 |
| 4 | Change manager (`employee_320`; `app-change-manager-search`): "Reporting team" / "Functional team", Show, "Selected Employee(s) whose manager has to be changed" (Select, Employee name, Employee code, Status, New Assigned Manager), "New Manager", Remove, "Employees selected" | Move several people to a new manager at once | Employee 360 → "Change manager" for one employee (relationship type, manager, effective from, Before → After) | PARTIAL | WO-16: bulk change manager (pick a current manager's reporting or functional team or any employees, new manager, effective date; one `ChangeManagerAction` per person) |
| 5 | "Do you want to delegate tasks to the new manager?" Yes / No | Move open approvals with the team | Pending approvals keep their resolved approver; delegation (P4) is per person and period | MISSING | WO-16: optional re-resolution of the moved employees' open workflow tasks to the new manager (`WorkflowEngine::reassign`, audited) |
| 6 | Generate letter (misc. letter) (`generateletter_425`) | Misc. letter | §23.7.2 | PARTIAL | WO-13 |
| 7 | Invite to HROne (`sendbulkpassword_226`; right "Invite to system") — filters: Search employees, Add bulk employees, Organization, Location, Status, Type, Creation date from / to, Joining date from / to | Invite many employees to sign in | Users → create one user (invitation sent); the employee ↔ user link on Edit profile | PARTIAL | WO-16: bulk invite (employees without a login in the filter: create user from work e-mail, link, `UserInvitations::issue`; skips without e-mail listed) |
| 8 | Invite: "Send to new user" / "Reset password old user" | First invitation vs reset for existing users | Invitation for new users; reset link for active users (one at a time) | PARTIAL | WO-16 both modes in bulk |
| 9 | Invite: "What is your password type?" Standard Password / Advanced Password | How the first password is set | Administrators never choose or see passwords; the invitee sets one under the tenant policy | N-A | Deliberate (SaaS.2 invitation rule) |
| 10 | Invite: "Do you want to copy anyone on the employee invite?" (Add CC) | CC HR on invitations | — | MISSING | WO-16 (CC on the invitation mail, never the link itself) |
| 11 | Invite: "Do you want to enable employee whatsapp bot?" | WhatsApp onboarding | — | MISSING | D-WO7 |
| 12 | Invite: Subscription — Total / Active / Available users; Total / Active / Available WhatsApp and ESS licences; "Invite will go only to 10% of the ESS license" | Licence headroom | Plan limits (SaaS.5) enforced on user creation | N-A | Vendor licensing |
| 13 | Invite: "User would get the password on next scheduled run" | Invitations sent in batches | Sent immediately (queued mail) | HAVE | — |
| 14 | Invite: List (Employee Code, Employee Name, Email Id, Mobile No, SMS Status, Email Status; Download List, View List), Activity log (Message, Send Date, Message Details) | Delivery status of the invitations | Notification deliveries (e-mail status per message); invitation state on the user | PARTIAL | WO-16: invitation batch view (per person: sent, accepted, failed); SMS N-A until a provider |
| 15 | Invite: Disclaimer "Any logged-in user for the mobile app may be logged out within 1 week" | Reset side effect | Sign out everywhere is explicit | N-A | Vendor app behaviour |
| 16 | WhatsApp logs (`app-whatsapp-logs`: Sent on, Status, Type, To, Category, Subject, Reason, Recipient, View; filters) | WhatsApp delivery log | Channel is a log stub | MISSING | D-WO7 |
| 17 | Send password reset link (right on Action → Workforce → Employee) | Reset an employee's password | Users → "Send password reset link" | HAVE | WO-16 adds the bulk form |
| 18 | Transfer request (`transferrequest_919`) | Raise a transfer | §23.5.1 | HAVE | — |
| 19 | Resignation request (`resignation_91`; right "Request on behalf") | Resign on behalf | "Initiate exit"; fields in matrix 20 §20.1.2 | PARTIAL | RQ-6 |
| 20 | Share letters/ documents (`publishletters_700`) | Bulk share | §23.7.3 | MISSING | WO-14 |
| 21 | Add business partner (`addexternal_1069`; right "Add") | Create a partner | §23.8 | PARTIAL | WO-15 |
| 22 | Product enhancement request / Feature request (`featurerequestcreate_1010`; `app-feature-request`: Modules, Process, challenges you face, how you manage today, ideal solution, "don't have feature"; `app-product-enhancement-wizard`, `app-product-map` roadmap) | Ask the vendor for a feature | — | N-A | Vendor product feedback (PeopleOS takes product feedback outside the tenant workspace) |

---

## 23.10 Imports (HR Desk → Imports; "IMPORTS" on each Workforce page)

### 23.10.0 Import wizard, logs and approval (`app-import-dashboard` → `app-new-do-imports` steps 1–4, `app-new-recent-import`, `app-recent-imports`, `app-use-import`, `app-import-approval*`)

PeopleOS uploads: Employee import (`EmployeeImportResource`: modes add / update / both, template download, format
guide, column mapping, validation with per-row errors, approve, run, result file) and Bank details upload, both on the
staged pipeline with P2 governance and Recent uploads. The employee import / export is a platform-controlled feature
(`employee.import_export`, off until Markedge enables it for the organisation).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Stepper "Select Process → Import intent → Prepare your data → Review & fix" | Guided import | Upload → inspect → map → validate → approve → run on each upload screen | HAVE | — |
| 2 | Step 1: "All modules" picker, search, "Recent", "No imports", "No access — Currently you don't have access to this feature" | Pick an import type the role may use | Each upload has its own screen and permission; Recent uploads lists types the viewer may work on | PARTIAL | WO-17: one "New upload" picker over `ImportTypes` (module, search, recent) |
| 3 | Step 2 intent: "Insert New Records" / "Update Existing Records" | Add or update | Employee import modes add / update / both; other uploads one mode | HAVE | — |
| 4 | Step 2 intent: "Delete Record — Remove entries from the system using unique identifiers" | Bulk delete | — | N-A | Business records are never bulk-deleted (audit, retention); "inactivate" uploads per master (SV-9) — D-WO4 |
| 5 | Step 2 parameters per type (employee selection by bulk codes / organisation / specific employees; "Select one or more employee other detail fields you want to update"; plus module parameters: pay group, month / year, cycles, state, branch, dates …) | Narrow the template to what is being changed | Templates carry every field; update mode needs only the code and changed columns | PARTIAL | WO-17: "choose columns" for update templates (custom-field picker for other details) |
| 6 | Step 3: "Download template" ("Pre-formatted spreadsheet with required fields and sample data"), "View instructions" | Template and guide | Template download and format guide (columns, required, example, notes) | HAVE | — |
| 7 | Step 3: "Upload excel" (drag and drop) / "All dates must be in DD/MM/YYYY format" | Upload | CSV upload; dates YYYY-MM-DD | PARTIAL | WO-17: accept .xlsx and the organisation's date format (spreadsheet library decision shared with AN-* in matrix 21) |
| 8 | Step 3: "Enter manually — New!" (`app-online-excel-view`) | Type rows online instead of a file | — | MISSING | WO-17: online grid that produces the same staged rows (low priority) |
| 9 | Step 4 "Review & fix": "There are n errors in the table", search values, "Re-upload fixes", "Fix online" | Correct errors before running | Per-row errors on the upload; fix by re-mapping or a new file | PARTIAL | WO-17 (fix online with row 8) |
| 10 | Submitted: "Data imported submitted — Your file was validated and submitted. You can review the details in the import log", "View import log" | Confirmation | Status messages; approve / run | HAVE | — |
| 11 | Import log (`app-new-recent-import`, `app-recent-imports`): Import name, Type, Submitted time, Start time, End time, Total records, Processed records, Success, Failed, Status, Status file, Uploaded file; month select; Refresh; search | History of uploads | Recent uploads: type and mode, file, uploader, uploaded / run started / finished, rows / processed / succeeded / failed / errors, status, approver, workflow, source; month, type, module, status filters; downloads of the file and the result | HAVE | — |
| 12 | "Download error log" / "Status file"; "REVIEW AND FIX" | Error file | Result file (every row with action, status, errors) | HAVE | — |
| 13 | Legacy flow (`app-use-import`: Download Excel Sheet, upload edited sheet, "Excel errors found — download the commented file") | Older import | — | N-A | Superseded in HROne by rows 1–12 |
| 14 | Import approval (`app-import-approval`: "Do you want to have workflows on import?", per import: HR Desk / Team / Project applicability, module filter; approval card: Module, Import source, Import name, Import type, Import date-time, Upload file) | Imports go through approval | P2: approval mode self / other person / workflow, types it applies to, trigger `import.validated`; Approval Center | HAVE | Setup matrix 02 §02.2–02.3 |
| 15 | Inbox insight "Import request" | Pending import approvals | Validated uploads awaiting approval (Recent uploads status) | HAVE | Org-wide counts → §23.11 |

### 23.10.1 Imports → Workforce [20]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Employee | Add / update employees | Employee import (27 fields: names, DOB, gender, marital status, nationality, personal e-mail / phone, code, joining / probation dates, work e-mail / phone, external reference, company and position dimension codes, manager code) | HAVE | Feature `employee.import_export` must be on |
| 2 | Employee (advance) | Employees with more fields *(inferred: statutory, managers, settings)* | — (blood group, salutation, functional manager, statutory numbers not importable) | PARTIAL | WO-17: extra columns (blood group, functional manager code, HRBP code, statutory numbers through `ChangeStatutoryIdentityAction`, custom fields) |
| 3 | Employee settings | Per-employee settings (login, privacy, devices …) *(inferred)* | — | MISSING | WO-17 after WO-6 |
| 4 | Vendor | Vendors / business partners | — | MISSING | WO-17 after WO-15 / vendor master |
| 5 | Personal details | Personal section (addresses, blood group, IDs …) | Person basics through the employee import (update mode); addresses not importable | PARTIAL | WO-17 `person_addresses` upload (type, lines, city, state, postal code, country) |
| 6 | Children | Children | — | MISSING | WO-17 family upload (relation child) through `ChangeFamilyMemberAction` |
| 7 | Dependents | Other dependants | — | MISSING | WO-17 (same family upload) |
| 8 | Family details (parent and spouse) | Parents / spouse | — | MISSING | WO-17 (same family upload) |
| 9 | Work experience | Past jobs | — | MISSING | WO-17 `person_experiences` upload |
| 10 | Health insurance | Insurance | — | MISSING | After WF-10 |
| 11 | Employee other details | Custom fields | — (custom fields not importable; gap register WF-09 / WF-11) | MISSING | WO-17 custom-field upload (entity employee; only active fields; type-checked) |
| 12 | Bank | Bank accounts | Bank details upload (new / existing mode, format guide, maker-checker under P2) | HAVE | — |
| 13 | Previous employment | Previous employer (and tax) | Tax declarations hold previous-employer income / TDS (entered per employee) | MISSING | WO-17 (experience upload + previous-employer income through payroll's tax upload, matrix 08 BP-17) |
| 14 | Education | Qualifications | — | MISSING | WO-17 `person_qualifications` upload (links masters when the name matches, CA-3) |
| 15 | Nominee details | Nominees | — | MISSING | WO-17 (family upload with nominee shares) |
| 16 | Contract | Contracts | — | MISSING | After WO-5 |
| 17 | Shortcut | Shortcuts / quick links per employee *(inferred)* | — | MISSING | Low priority (welcome-note links, WO-8) |
| 18 | New hire | Pre-joiners | Employee import with a future joining date (preboarding) | PARTIAL | WO-17 "New hires" preset (expected joining date, no lifecycle beyond preboarding, optional onboarding start) |
| 19 | New hires (advance) | Pre-joiners with more fields *(inferred)* | As row 18 | PARTIAL | WO-17 |
| 20 | Employee skills | Skills with proficiency | — | MISSING | WO-17 `person_skills` upload (skill code, proficiency from the skill scale) |

### 23.10.2 Imports → Core [18]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Departments | Department master | Created on screen; not importable | MISSING | SV-9 organisation master imports (setup matrix 13) = WO-18 |
| 2 | Sub departments | Sub-departments | Departments with a parent (CA-2); not importable | MISSING | WO-18 (parent department code column) |
| 3 | Designations | Designations | Screen only | MISSING | WO-18 |
| 4 | Designation wise skills | Skills required per designation | Role requirements (career architecture, versioned); not importable | MISSING | WO-18 (publishes a requirement version) |
| 5 | Business regions | Business regions | — | MISSING | CA-7 geography, then WO-18 |
| 6 | Dimension | Custom organisation dimensions | Cost centres, profit centres, teams, divisions; no generic dimension master import | MISSING | WO-18 |
| 7 | Branches | Branches | Locations; not importable | MISSING | WO-18 (locations upload) |
| 8 | Sub branches | Sub-branches | — (single location level) | MISSING | CA-7 / WF-12 then WO-18 |
| 9 | Districts | Districts | — | MISSING | CA-7 (needs an approved source) |
| 10 | Business units | Business units | Screen only | MISSING | WO-18 |
| 11 | Grades | Grades | Configuration packs / Blueprints import grades (JSON), not a spreadsheet upload | PARTIAL | WO-18 |
| 12 | Levels | Levels | Blueprints (JSON) | PARTIAL | WO-18 |
| 13 | States | States | Statutory state codes are rule data | N-A | States come with country packs (not tenant data) |
| 14 | City | Cities | — | MISSING | CA-7 |
| 15 | Regions | Regions | — | MISSING | CA-7 / WO-18 |
| 16 | Employee code | Bulk change of employee codes | One at a time on Edit profile | MISSING | WO-17: "Employee code change" upload (old code, new code; uniqueness across the batch; audited per row) |
| 17 | Customers | Customer master | — (projects / customers CA-17 pending) | MISSING | After CA-17 |
| 18 | Skill master | Skills | Blueprints (JSON) | PARTIAL | WO-18 |

### 23.10.3 Imports → Integration [13] (journal voucher mappings)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | JV company / business unit / department / sub department / designation / grade / level / region / branch / sub branch mapping (10 imports) | GL / ERP codes per organisation unit, in bulk | Integration systems → mappings (dimension value ↔ external value per system, `IntegrationMappings`), entered one by one; no journal voucher yet | PARTIAL | SV-27 journal vouchers (setup matrix 13) + WO-18 mapping upload (system, dimension, PeopleOS code, external value) |
| 2 | JV employee mapping | ERP / vendor code and GL codes per employee | External references per employee (`ExternalReferences`) | PARTIAL | WO-18 upload into external references (setup matrix 01 §6.7) |
| 3 | JV cost distribution mapping | Split an employee's cost across units by % | — | MISSING | SV-27 (`employee_cost_allocations`) |
| 4 | JV dimension mapping | GL codes per custom dimension | Mappings support any registered dimension | PARTIAL | SV-27 / WO-18 |

---

## 23.11 Inbox Insights → Workforce [32]

The Inbox Insights page (organisation-wide open-task counts by type, overdue / all pending / relieved owners, reminders,
bulk archive) is rated in matrix 21 §21.3 (AN-30, MISSING). Each row here rates whether PeopleOS has the **task kind**
that AN-30 would count: HAVE = the kind exists and is listed somewhere for its owners; PARTIAL = it exists only in part
or only on its own screen (not as a task); MISSING = no such task.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Profile | Profile changes awaiting approval | Profile-change service requests (address, bank, PAN / UAN, emergency contact, family member) | PARTIAL | MS-3 change requests, then AN-30 counts them |
| 2 | Contract approval | Contracts awaiting approval | — | MISSING | WO-5 |
| 3 | Import request | Uploads awaiting approval | Validated uploads awaiting the checker or a workflow (P2) | HAVE | AN-30 source |
| 4 | Confirmation | Confirmations to decide | Open confirmation reviews; manager feedback requests | HAVE | — |
| 5 | Confirmation letter | Letters to generate / approve | Letter drafts pending approval (Approval Center kind `letter`) | PARTIAL | WO-13 (generation queue) |
| 6 | Extension letter | As row 5 | As row 5 | PARTIAL | WO-13 |
| 7 | Termination letter | As row 5 | As row 5 | PARTIAL | WO-13 |
| 8 | Resignation | Resignations to accept | Workflow tasks when a resignation workflow exists; otherwise none | PARTIAL | WF-02 / RQ-6 |
| 9 | Exit interview | Interviews to give | Feedback requests (exit interview by employee / manager / HR) in My tasks | HAVE | — |
| 10 | Clearance task | Clearance tasks to do | Clearance stages for their owner user / role (`exit.clear`) on the exit case; not in My tasks | PARTIAL | WO-11: clearance stages as a task source (`peopleos.experience.task_sources`) |
| 11 | Final clearance | Final clearances to complete | Exits ready to complete (no task) | PARTIAL | WO-11 |
| 12 | Relieving letter | As row 5 | As row 5 (policy letter on completion) | PARTIAL | WO-13 |
| 13 | Resignation acceptance letter | As row 5 | — (no acceptance letter) | MISSING | WO-13 |
| 14 | Transfer intimation letter | As row 5 | — | MISSING | WO-13 |
| 15 | Transfer letter | As row 5 | Letters generated by hand | PARTIAL | WO-13 |
| 16 | Contract letter | As row 5 | — | MISSING | WO-13 / WO-5 |
| 17 | Resignation cancel | Withdrawals awaiting approval | Immediate withdrawal (no task) | MISSING | WO-11 (D-WO8) |
| 18 | Upcoming join | Pre-joiner task card | — (pre-joiners are records, no task) | MISSING | WO-4 (joining-day task to HR: confirm joining / change date / not joining) |
| 19 | Renew contract | Contracts to renew | — | MISSING | WO-5 |
| 20 | Pre boarding | Pre-joining tasks | Onboarding tasks (phase preboarding) for their owners | HAVE | — |
| 21 | On boarding | Onboarding tasks | Onboarding tasks | HAVE | — |
| 22 | Social profile | Social-profile approvals / updates *(inferred)* | — | MISSING | MS-6 (matrix 22) |
| 23 | Welcome team | Manager's welcome-team task | — | MISSING | WO-8 |
| 24 | Welcome note | Joiner reads the welcome note | — | MISSING | WO-8 |
| 25 | Transfer request | Transfers to approve | Transfer requests in the Approval Center (`TransferApprovalSource`) | HAVE | — |
| 26 | Do transfer | HR finalises an approved transfer | Approval applies the transfer directly (no second task) | PARTIAL | WO-10 (optional "finalise" step when the policy asks) |
| 27 | Induction | Induction plans to approve | Induction plans start without approval | PARTIAL | WO-8 |
| 28 | Induction evaluation | Evaluations to give | Feedback requests (process induction) | HAVE | — |
| 29 | Initiate induction | Manager starts the induction plan | Induction plans start automatically or by HR | PARTIAL | WO-8 (manager initiation task) |
| 30 | Pre transfer | Pre-transfer tasks | — | MISSING | WO-10 |
| 31 | Post transfer | Post-transfer tasks | — | MISSING | WO-10 |
| 32 | Complete profile | Profile-completion tasks | Onboarding `profile_section` items | PARTIAL | WO-8 / MS-3 |

---

## 23.12 Analytics → Workforce [7] and Team → Analytics → Workforce [1]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Active headcount | Current headcount widget | Metric `headcount` (Workforce metrics, dashboards, People analytics) | HAVE | — |
| 2 | Gender ratio | Gender split | Template `gender_diversity` (pie); metric `women_share` | HAVE | — |
| 3 | Upcoming join | Joiners expected | Template `upcoming_joiners` (next 30 days) | HAVE | — |
| 4 | Tenure trend | Tenure over time | `avg_tenure_months` (now), `tenure_profile` (bands); no monthly series of tenure | PARTIAL | WO-19: `avg_tenure` in `WorkforceMetrics::series` (month-end headcount's average tenure) |
| 5 | Headcount by bussiness (business unit) | Headcount per business unit | `employees` dataset has `business_unit`; no template (templates by company, department, location, designation, grade, type, cost centre) | PARTIAL | WO-19: template `headcount_business_unit` |
| 6 | Headcount by department | Per department | Template `headcount_department` | HAVE | — |
| 7 | Headcount by state | Per state | Locations carry `state_code`; the dataset has no state column | PARTIAL | WO-19: `location_state` column + template `headcount_state` |
| 8 | Team → Analytics → Workforce → Upcoming join | A manager's upcoming joiners | Managers see their team on My team; no upcoming-joiner tile for the team | PARTIAL | RQ-11 (matrix 20) team tile from the same template, scoped to the manager's team |

---

## 23.13 Reports — HR Desk → Reports → Workforce [51], the Workforce panel "Reports" page and Team → Reports → Workforce [14]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | HR Desk → Reports → Workforce [51] (Business partners report … EKYC overall status) | The Workforce report catalogue | All 51 rated one by one in matrix 21 §21.2.2 "Workforce (51)": 17 HAVE / 29 PARTIAL / 5 MISSING. Name differences: the tree's "Headcount by branch" = matrix 21 #20 "Headcount by sub branch"; "Employees other details" = #15 "Employee other detail"; "EKYC overall status" = #13 "Employee eKYC Summary"; "Welcome note report" = #47 "Welcome note". Corrections from this matrix: #34–#35 (pre / post transfer checklists) are MISSING in code (no transfer tasks) | PARTIAL | AN-29 / AN-30 (matrix 21) + WO-19 |
| 2 | Workforce panel "Reports" page (`openReports`): categories Favourite, All, New Hire, Employee, Confirmation, Transfer, Separation, Business partner, History; search; each report card with name, format (.xlsx), description, Download, Open; counts per process (`report/Count/Process`) | Browse and run the module's reports | Report library: categories, search, templates with descriptions, run, export CSV, schedules | PARTIAL | Spreadsheet output (.xlsx) → matrix 21 AN-* output package; "History" = download history (matrix 20 §20.4) |
| 3 | Team → Reports → Workforce: Work experience, Education details, Joining status, Snapshot, Confirmation feedback, Employee login report, Exit interview feedback, Induction plan, Induction evaluation report, Exit interview status, Confirmation status, Preboarding checklist, Contract details, Employee details (14) | The same reports limited to the manager's team | The manager role has no report-library permission (matrix 20 §20.3.4 #1 MISSING); the HR versions: HAVE Education details, Joining status, Snapshot, Preboarding checklist, Employee details; PARTIAL Work experience, Confirmation feedback, Employee login report, Exit interview feedback, Induction plan, Induction evaluation report, Exit interview status, Confirmation status, Contract details | PARTIAL | RQ-11 "Team reports": the same templates run with the manager's team scope (`PeopleVisibility`), only non-sensitive datasets; each report's own gap closes with AN-29 / WO-5 |

---

## 23.14 Team → People → Action [9] and Team profile [9]

Team actions per member are rated item by item in matrix 20 §20.3.1–20.3.2; the Workforce ones are repeated here
because they reach workforce processes.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Team → People → People (View) | The manager's team list | My team (direct reports, today's status) | PARTIAL | RQ-11 (indirect / functional teams) |
| 2 | Team → People → Action → Workforce: Resignation (on behalf of a member) | Manager resigns a member on their behalf *(inferred: e.g. absconding or a verbal resignation)* | Only HR initiates exits (`exit.manage`) | MISSING | RQ-11: manager "Raise resignation for a member" through `Exits::initiate` with `by_manager` (needs the setting `exit.manager_may_initiate`, default off) |
| 3 | Team → People → Action → Workforce: Transfer request | Manager requests a member's transfer | "Request a transfer" needs the transfer permission (HR); managers cannot raise one for their team | PARTIAL | RQ-11: managers may request for their own team (approval stays with HR / workflow) |
| 4 | Team profile → Workforce: View, View profile | Open a member's profile | Employee 360 within the manager's scope | HAVE | — |
| 5 | Team profile → Workforce: Clearance | Member's clearance | Clearance stages owned by the manager role (`exit.clear`) | HAVE | — |
| 6 | Team profile → Workforce: Confirmation | Member's confirmation | Manager feedback in My tasks; decision by HR | HAVE | — |
| 7 | Team profile → Workforce: Exit interview | Member's exit interview | Manager's exit interview feedback (exit policy) | HAVE | — |
| 8 | Team profile → Workforce: Social profile | Member's social profile | Social wall profile | PARTIAL | MS-6 |
| 9 | Team → People → Action → Payroll, Helpdesk, Time office, Expense, Recruitment, Performance, Asset, Engage; Team profile → Time office, Performance, Engage, Payroll, Recruitment, Asset, Helpdesk, Expense | Other modules' team actions | Rated in matrix 20 §20.3.2 (40 items) | PARTIAL | Matrix 20 RQ-11; Recruitment N-A |

---

## Counts

Rows of the tables in §23.1–§23.14 (the §0 building blocks and the §23.0 index are not counted).

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 23.1 Workforce panel and shared register chrome | 20 | 2 | 10 | 6 | 2 |
| 23.2.1 Employees — Records | 14 | 7 | 3 | 4 | 0 |
| 23.2.2 Employees — card menu and bulk actions | 22 | 2 | 14 | 5 | 1 |
| 23.2.3 Add employee | 23 | 5 | 14 | 4 | 0 |
| 23.2.4 Profile rights (38) | 29 | 13 | 10 | 6 | 0 |
| 23.2.5 HR-side profile parts (contract, settings, history) | 22 | 4 | 8 | 8 | 2 |
| 23.2.6 Employees — Reports tab | 11 | 4 | 5 | 2 | 0 |
| 23.2.7 Employees history | 7 | 1 | 5 | 0 | 1 |
| 23.3.1 New hires (upcoming join) | 19 | 3 | 11 | 5 | 0 |
| 23.3.2 Background verifications | 10 | 2 | 5 | 3 | 0 |
| 23.3.3 Preboardings | 8 | 3 | 5 | 0 | 0 |
| 23.3.4 Welcome team members | 8 | 2 | 3 | 3 | 0 |
| 23.3.5 Profile completion | 4 | 0 | 3 | 1 | 0 |
| 23.3.6 Inductions | 10 | 1 | 6 | 3 | 0 |
| 23.3.7 Induction evaluations | 7 | 2 | 4 | 1 | 0 |
| 23.4 Confirmations | 12 | 5 | 6 | 1 | 0 |
| 23.5.1 Transfer requests | 13 | 9 | 3 | 1 | 0 |
| 23.5.2 Transfers (do transfer) | 13 | 4 | 5 | 4 | 0 |
| 23.5.3 Pre- / Post-Transfers | 5 | 0 | 0 | 5 | 0 |
| 23.6.1 Resignation requests | 21 | 4 | 14 | 2 | 1 |
| 23.6.2 Resignation cancellation | 4 | 1 | 2 | 1 | 0 |
| 23.6.3 Clearances | 16 | 4 | 11 | 1 | 0 |
| 23.6.4 Final clearances | 5 | 3 | 2 | 0 | 0 |
| 23.6.5 Exit interviews | 5 | 2 | 3 | 0 | 0 |
| 23.7.1 Process letter registers (8) | 28 | 9 | 12 | 7 | 0 |
| 23.7.2 Adhoc letters (misc.) | 13 | 4 | 6 | 3 | 0 |
| 23.7.3 Shared letters | 9 | 0 | 5 | 4 | 0 |
| 23.7.4 Shared letters by employee | 4 | 1 | 1 | 2 | 0 |
| 23.8 Business partners | 10 | 2 | 5 | 3 | 0 |
| 23.9 HR Desk actions (Action → Workforce, global actions) | 22 | 5 | 8 | 5 | 4 |
| 23.10.0 Import wizard, logs, approval | 15 | 8 | 4 | 1 | 2 |
| 23.10.1 Imports → Workforce [20] | 20 | 2 | 4 | 14 | 0 |
| 23.10.2 Imports → Core [18] | 18 | 0 | 3 | 14 | 1 |
| 23.10.3 Imports → Integration [13] | 4 | 0 | 3 | 1 | 0 |
| 23.11 Inbox Insights → Workforce [32] | 32 | 7 | 13 | 12 | 0 |
| 23.12 Analytics → Workforce [7] + Team analytics [1] | 8 | 4 | 4 | 0 | 0 |
| 23.13 Reports (HR Desk [51] pointer, panel page, Team [14]) | 3 | 0 | 3 | 0 | 0 |
| 23.14 Team → People → Action / Team profile | 9 | 4 | 4 | 1 | 0 |
| **Total** | **503** | **129** | **227** | **133** | **14** |

By journey: People (§23.2) 128 rows (36 / 59 / 29 / 4) · Onboarding (§23.3) 66 (13 / 37 / 16 / 0) · Confirmations 12
(5 / 6 / 1 / 0) · Transfer 31 (13 / 8 / 10 / 0) · Separation 51 (14 / 32 / 4 / 1) · Letters 54 (14 / 24 / 16 / 0) ·
Imports 57 (10 / 14 / 30 / 3).

---

## Biggest gaps

1. **No bulk work on any workforce register** (§23.1 #12–14, §23.2.2 #5–11, §23.3.2 #5, §23.3.3 #4–5, §23.4 #8,
   §23.6.3 #2, §23.6.4 #2): HROne's registers are built for "filter, select, act" (initiate onboarding / pre-boarding /
   clearance / final clearance / BGV / separation, confirm, mail), with a typed-count guard; PeopleOS acts one record at a
   time from the Employee 360 or the case.
2. **Letter lifecycle** (§23.7.1–23.7.2, §23.11 #5–16): one letter per generation, one signatory, no bulk generate or
   bulk download, no revoke / unpublish or supersede, no e-mail with the letter, no acknowledgement, no scheduled or
   event-based letters (joining, birthday, confirmation, resignation, leaving, retirement, anniversary), and five process
   letter types missing (probation extension, termination, contract, transfer intimation, resignation acceptance).
3. **Uploads beyond employees and bank details** (§23.10.1–23.10.3): 14 of 20 Workforce import types (family,
   dependants, nominees, education, work experience, previous employment, custom fields, skills, contracts, settings,
   vendors …), 14 of 18 Core master imports, the bulk employee-code change and the journal-voucher mapping uploads are
   missing; uploads are CSV with ISO dates only; the employee import is off until the platform switch is set.
4. **Upcoming joiners** (§23.3.1, §23.3.5, §23.11 #18): no register of its own, no "not joining" outcome, no change of
   the expected joining date, and no pre-joiner self-service (profile-completion mail, joiner-filled sections, HR review
   and send-back) — the last waits for the pre-joiner access decision (WF-13).
5. **Separation depth** (§23.6.1–23.6.3): resignations are accepted only through a workflow; the approver cannot set the
   last working day, notice relaxation days or the approver reason; no notice-shortfall recovery; withdrawal is immediate
   (no approval); clearance stages lack due / expected / completed dates, attachments, dependencies, sub-status and
   delegation; the leaver's roles and reportees are not handed over; no relieving review panel.
6. **Transfers** (§23.5.2–23.5.3): no pre- / post-transfer checklists; the approver cannot set a different effective
   date; no goal-review, salary-revision or geo-location side effects; no attachment; no functional-manager change on a
   request.
7. **Bulk change manager and bulk invitations** (§23.9 #4–17): reporting lines change one employee at a time with no
   option to move open approvals; logins are created and invited one user at a time; no CC, no delivery list.
8. **Employee contracts** (§23.2.5 #5–11, §23.11 #2 / #16 / #19): no contract records, approval, renewal / roll-off,
   leave lapse or contract letters (with BP-16).
9. **Onboarding desk** (§23.3.3–23.3.7): no cross-joiner pre-boarding task register, no links / videos on tasks, no
   manager welcome note and buddy-task step, no profile-completion register, induction plans without window, expected
   standards, outcomes, 1-on-1s, approval or extension.
10. **Bulk document sharing** (§23.7.3–23.7.4): no "share files to many employees" (sheet + zip), no register of what was
    published to whom, no unpublish.

Also notable: no saved views or card view on registers (§23.1 #9–11); business partners as external users (§23.8); a
profile-changes register (§23.2.7, after MS-3); per-employee settings panel (§23.2.5 #14–18); ID cards (§23.2.2 #15);
vendor-dependent BGV / e-KYC / CCRV / bank and EPFO verification (CA-15, external).

---

## Owner decisions needed

| # | Decision | Proposed default (reproduces today's behaviour until chosen) |
|---|---|---|
| D-WO1 | "Not joining" for an upcoming joiner: a new lifecycle end state (`not_joined`) or cancel-and-archive of the pre-employee record | New end state `not_joined` (record kept for retention and rehire checks; onboarding cancelled; never deleted) |
| D-WO2 | Business partners (consultants, vendors, BGV agents, customers) as sign-in users without an employee record: allowed? which roles may they hold? counted against the plan? | Off by default; when on, partners hold only roles marked "partner-assignable" and never `employee.sensitive.*` |
| D-WO3 | HROne bulk "Delete" of generated pre-boarding / clearance tasks | Cancel with a reason (tasks closed, kept); no hard delete |
| D-WO4 | Import intent "Delete records" | Not offered; masters get an "inactivate" upload instead (SV-9) |
| D-WO5 | "Unpublish" / "Revoke" of issued letters and shared documents | Hide from the employee and mark revoked (reason, audited); the file and record stay |
| D-WO6 | "Re-generate & publish" | Supersede: the new letter links to the old one, the old one is revoked; nothing deleted |
| D-WO7 | WhatsApp (invites, employee bot, delivery logs) | Not built until a provider is chosen; SMS / WhatsApp remain log-only channels |
| D-WO8 | Resignation withdrawal: immediate (today) or a request that needs approval | Immediate by default; organisations opt in to approval |
| D-WO9 | On transfer, end the employee's approved check-in locations | Off by default (approver may tick it) |
| D-WO10 | The leaver's roles (approver / admin scopes) and reportees at exit: move automatically to the leaver's manager, or HR chooses | HR chooses on the exit case; unresolved items listed before "Complete exit" (warning, not a block) |
| D-WO11 | Pre-joiner access (WF-13): time-boxed sign-in for joiners, or signed one-time form links; which sections before joining; consent text | Signed one-time links per section, valid until the joining date, consent captured first; no portal account |
| D-WO12 | Facial-recognition data for check-in (HROne per-employee face registration) | Not kept (biometric data minimisation); check-in photos only |

---

## Build packages (ordered)

Prefix **WO-**. Every package is additive and keeps today's defaults; bulk actions call the existing services per
record (authorised and audited one by one); new settings in `config/peopleos_setup.php`, new permissions in
`config/peopleos.php` then `php artisan peopleos:sync-permissions`.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **WO-1 Workforce desk and bulk-action framework** → built (2026-10-29; `docs/architecture/hrone-parity.md` "WO-1 / WO-2") — journey-ordered navigation over the existing registers, per-register "Report" link, saved views (per user, per register), card view and card fields on Employees, bulk actions on "selected" or "all matching the filters" with typed-count confirmation over 25 records, queued run with a result summary | §23.1 #1–2, 9–14; bulk rows §23.2.2 #5–8, §23.3.2 #5, §23.3.3 #4, §23.4 #8, §23.6.3 #2, §23.6.4 #2 | `app/Filament/Support/Pages/PeopleListRecords.php`, new `app/Filament/Support/BulkRecordActions.php` + job, `ExperiencePreferences` (saved views), `ModuleCatalogue` / `ExperienceNavigation`, `EmployeesTable.php` | — | L |
| 2 | **WO-2 Shared employee search and filters; Employees register depth** → built (2026-10-29; same section) — search by code / names / e-mail / designation, pasted employee-code list, organisation filter group, date ranges (joining, created, request), "exclude pending resignation", BGV filter; 360 actions Rehire / reactivate, Send e-mail, printable profile, Activity tab | §23.1 #3, 5–8; §23.2.1 #7–8; §23.2.2 #2, 4, 17–18; §23.3.2 #3; §23.4 #3; §23.6.1 #2 | new `app/Filament/Support/EmployeeFilters.php`, `EmployeesTable.php`, `ViewEmployee.php`, `RehireEmployeeAction`, notification deliveries query | WO-1 | M |
| 3 | **WO-13 Letter registers and lifecycle** — per-type presets, process column, filters; bulk generate (policy template or chosen), bulk download (zip); up to four signatories; revoke and supersede (D-WO5, D-WO6); e-mail the letter (attachment, CC / BCC); compliance warnings on salary letters; new types (probation extension, termination, contract, transfer intimation, resignation acceptance) with policy hooks (`letter_on_acceptance`, transfer letters); letter schedules (one-time later, event-based ± days, audiences, saved, schedule list) | §23.7.1 #1–3, 5–6, 8, 11–12, 14, 16, 22–27; §23.7.2 #2–5, 7–8, 11–12; §23.11 #5–7, 12–16 | `app/Domain/Letters/{Services/Letters.php, Models/Letter.php, Support/PolicyLetters.php}`, new `LetterSchedule` model + `LetterSchedules` service + daily command, `LetterResource`, `config/peopleos.php` (`letters.types`, `letters.statuses`), policy types `exit` / `transfer` / `confirmation` | WO-1; e-sign rows wait for SV-17 | L |
| 4 | **WO-11 Separation desk** — accept / reject without a workflow (setting `exit.resignation.requires_acceptance`), approver reason, change last working day, notice relaxation days and notice-shortfall settlement line (rule from the exit policy), rehire-eligibility action, withdrawal requests with approval (D-WO8), clearance register, stage dates, evidence file, P4 delegation, withdrawn exits mark stages n.a., roles / reportees hand-over step (D-WO10), relieving review panel, clearance stages as a task source | §23.6.1 #6–11, 14, 17–18; §23.6.2 #1, 3–4; §23.6.3 #1, 4, 7, 9, 11–13; §23.6.4 #1; §23.2.2 #6–7, 20; §23.11 #10–11, 17 | `app/Domain/Exit/{Services/Exits.php, Services/FinalSettlements.php, Models/ExitCase.php, Models/ExitClearance.php, Listeners/ResignationWorkflowBridge.php}`, new `ExitWithdrawalRequest`, new `ClearanceTaskSource`, `ExitActions.php`, `ExitCaseResource`, exit policy type fields | WO-1, WO-16 | L |
| 5 | **WO-16 Bulk change manager and bulk invitations** — pick a manager's reporting / functional team or any employees, new manager, effective date, optional re-assignment of their open workflow tasks; invite employees without a login (create user from work e-mail, link, invitation), reset links for existing users, CC, batch delivery view | §23.9 #4–5, 7–8, 10, 14, 17; §23.6.3 #13 | `ChangeManagerAction`, `WorkflowEngine::reassign`, new `BulkInvitations` service, `UserInvitations`, `PasswordResets`, Employees / Users list actions | WO-1 | M |
| 6 | **WO-3 Hire wizard depth** — optional statutory step (PAN / Aadhaar reference), returning-employee match on PAN / Aadhaar with past exit and rehire eligibility, starting state for historic loads, position seat, functional manager / HRBP, optional section steps (address, family, bank, previous employment, custom fields), "create a login and invite", completion panel | §23.2.3 #7–8, 12, 15–18, 22 (#2, #6, #9 with MS-4) | `Pages/CreateEmployee.php`, `HireEmployeeAction`, `PersonMatcher`, `ChangeStatutoryIdentityAction`, `UserInvitations` | MS-4 (matrix 22) for prefix / device id / ISD | M |
| 7 | **WO-4 Upcoming joiners** — register (expected joining, days to join, onboarding progress, BGV, documents pending), change expected joining date (reschedules onboarding), not-joining outcome (D-WO1), joining-day task to HR, "download all documents", profile-completion mail; joiner-filled sections with review / send-back after D-WO11 | §23.3.1 #1, 8–9, 11, 13–14, 16 (+ #7, 10, 12 after D-WO11); §23.11 #18 | `EmployeeResource` preset or new `UpcomingJoiners` page, `LifecycleEngine` + `peopleos.lifecycle.transitions`, `Onboarding::cancel`, notification template `employee.profile_completion_requested` | D-WO1, D-WO11 | M |
| 8 | **WO-8 Onboarding desk** — pre-joining task register (filters phase / owner / status / joining date), links and videos on items, cancel plan, welcome-note item (manager: message, things to know / avoid, comments, links; buddy and ad-hoc buddy tasks; joiner view; register and dataset), profile-completion register and `ProfileCompletion` service, induction plan fields (window, expected standards, outcomes), `one_on_one` item, optional induction approval (`induction.planned`), manager-initiated induction, extend induction, latest one-on-one in the evaluation | §23.3.3 #1–3, 5; §23.3.4 #1–3, 5, 7–8; §23.3.5 #1, 3–4; §23.3.6 #1–4, 6, 9; §23.3.7 #1, 3–5; §23.2.6 #9; §23.11 #23–24, 27, 29, 32 | `app/Domain/Onboarding/{Services/Onboarding.php, Models/OnboardingPlan.php, Models/OnboardingTemplateItem.php}`, new `WelcomeNote` model, new `ProfileCompletion` service, `OnboardingPlanResource`, new task register page, `config/peopleos.php` (`onboarding.item_types`) | WO-1; MS-3 (matrix 22) for joiner-filled sections | L |
| 9 | **WO-10 Transfer desk** — request filters by dates, functional manager and attachment on requests, approver-set effective date (within policy days), flags for goal review / salary revision / geo-location end-dating (D-WO9), company-change warning, pre- and post-transfer checklists as onboarding templates of kinds `pre_transfer` / `post_transfer` (tasks, inbox, dataset) | §23.5.1 #2, 5, 8; §23.5.2 #1–2, 5–7, 10, 12; §23.5.3 #1–5; §23.11 #26, 30–31; §23.2.3 #20 | `app/Domain/Employment/{Services/TransferRequests.php, Models/TransferRequest.php, Actions/TransferEmployeeAction.php}`, `TransferRequestResource`, `Onboarding` (kinds), transfer policy type fields | WO-8 (task mechanics); SV-6 | M |
| 10 | **WO-17 Workforce uploads** — upload-type picker, column choice for update templates, .xlsx and organisation date format, online grid / fix online; new types: employee (advance) columns, addresses, family (children, dependants, parents / spouse, nominees), work experience, education, previous employment, custom fields, skills, new hires preset, employee-code change; later settings, vendors, contracts, health insurance | §23.10.0 #2, 5, 7–9; §23.10.1 #2–11, 13–20; §23.10.2 #16 | `app/Domain/Employment/Imports/{RowImportTypes.php, ImportTypes.php}`, new `*Imports` services, `RecentUploads` | P2 (done); WO-5 / WO-6 / WO-15 / WF-10 for their types; spreadsheet-library decision (matrix 21) | L |
| 11 | **WO-18 Organisation master and mapping uploads** (= setup SV-9 + the mapping part of SV-27) — departments (with parent), designations, designation skills, business units, locations, grades, levels, skills, dimensions; integration mappings and employee external references | §23.10.2 #1–12, 14–15, 18; §23.10.3 #1–2, 4 | `RowImportTypes` (area core), new master import services, `IntegrationMappings`, `ExternalReferences` | SV-9; CA-7 for geography | M |
| 12 | **WO-14 Bulk document sharing** — "Share documents in bulk" (document type, title, sheet of employee code + file name, zip, notify / e-mail), share-batch register, unpublish (D-WO5), published-documents list with filters | §23.7.3 #1–9; §23.7.4 #1–3; §23.9 #20 | new `DocumentShares` service + `document_share_batches`, `Documents` (hidden flag), import type `document_share`, new resource, permission `document.share` | WO-1, P2 | M |
| 13 | **WO-9 Confirmation desk** — optional workflow on the decision (`confirmation.decided`), bulk confirm, decision trail fields for reports | §23.4 #6, 8, 11 | `ConfirmationReviews`, `ConfirmationReviewResource` | WO-1 | S |
| 14 | **WO-12 Exit interview register** — leaver, LWD, employee / manager / HR interview status, received on | §23.6.5 #1, 5 | `ExitCaseResource` preset or new page, `FeedbackRequests::summary` | AN-29 (`exit_interviews` dataset, matrix 21) | S |
| 15 | **WO-7 Profile changes register** — every profile change request with section, status, pending with, decided on; dataset `profile_changes` (masked values) | §23.2.7 #1, 5–7 | change-request model from MS-3, new resource, dataset | MS-3 (matrix 22) | S |
| 16 | **WO-6 Employee settings panel** — login switch (invite / deactivate the linked user), devices and sessions, MFA state and reset, device report | §23.2.4 #13, 27; §23.2.5 #14, 17–18; §23.10.1 #3 | `ViewEmployee.php` (Settings tab), `EmployeeDevice`, session store, `UserInvitations` | — | M |
| 17 | **WO-5 Employee contracts** — contracts (name, code, period, payroll by employee / vendor, vendor codes, attachment, lapse leave), approval (`contract.created`), renewal / roll-off task before the end date, contract letters, contracts upload | §23.2.4 #14; §23.2.5 #5–11; §23.7.1 #24; §23.11 #2, 16, 19; §23.10.1 #16 | new `app/Domain/Employment/{Models/EmployeeContract.php, Services/EmployeeContracts.php}`, relation manager, reminder command, leave service (lapse) | BP-16 (setup matrix 08); vendor master (setup 01 §1.33) | L |
| 18 | **WO-15 Business partners** — partner users (kind, profile: code, salutation, names, DOB, gender, nationality, type, contact, vendor), register, invite / disable, partners dataset | §23.8 #1–6, 10; §23.9 #21 | `app/Domain/Identity/Models/User.php` (kind), new `PartnerProfile`, resource, number series, role flag "partner-assignable" | D-WO2; vendor master | M |
| 19 | **WO-19 Workforce datasets and metrics** — employees: retirement date / years to retirement, functional manager, notice days (probation / confirmed), location state; exits: company, business unit, current state, accepted by / on; `bgv_cases` dataset; templates `headcount_business_unit`, `headcount_state`; average-tenure series | §23.2.6 #4, 8, 10; §23.3.2 #10; §23.6.1 #15, 19; §23.12 #4–5, 7 | `app/Domain/Analytics/Datasets/{EmployeesDataset.php, ExitsDataset.php}`, new `BgvCasesDataset`, `WorkforceMetrics::series`, `ReportLibrary` | — | S |
| 20 | **WO-20 ID cards** — ID-card letter type rendered per employee, bulk generation, last-generated filter | §23.2.1 #10; §23.2.2 #15; §23.2.4 #17 | `Letters` (type `id_card`), WO-13 bulk generation | MS-17 (matrix 22), WO-13 | S |

**Outside this matrix's packages** (rows point to them): MS-3 / MS-4 / MS-5 / MS-6 / MS-7 / MS-17 (matrix 22), RQ-6 /
RQ-10 / RQ-11 (matrix 20), AN-29 / AN-30 and the spreadsheet / PDF output package (matrix 21), CA-6 profile visibility,
CA-7 geography, CA-8 Wi-Fi banks, CA-14 SCIM, CA-15 BGV / e-KYC / verification vendors (external), SV-6, SV-9, SV-23,
SV-27 (setup matrix 13), BP-11 / BP-16 (setup matrix 08), WF-08 other status, WF-10 health insurance, SV-17 e-sign.
