# HROne vs PeopleOS — 26 Expense, Asset and Helpdesk operations (HR Desk → Expense / Asset / Helpdesk), field by field

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A. Package prefix: **XO-**. No personal data is copied: employee names, codes, amounts, counts and the
tenant's own name seen on screenshots, page text or API bodies are left out.

**Inputs.**

- `hrone-research/menu-tree.json` → panels **"Expense"** (groups Travel — Travel request, Travel bookings, Travel
  cancellations; Advances — Advance requests, Advance cancellations; Expense — Expense receipts, Expense reports; top
  Reports, Expense Setup), **"Asset"** (Asset inventory, Employee Assets, Asset acknowledgement, Asset request, Reports,
  Asset Setup) and **"Helpdesk"** (Tickets, Reports, Helpdesk Setup), with the URL and `navigationCode` each item opened.
- `hrone-research/pages.jsonl` (latest line per id): the 16 `menu_Expense__*`, 10 `menu_Asset__*` and 4 `menu_Helpdesk__*`
  captures and their `__tab_01_Reports` lines, `rail_Expense`, `rail_Asset`, `rail_Helpdesk` (+ Reports tabs),
  `desk_Tickets`, `desk_Asset_inventory`, `desk_Travel_request` (+ Reports tabs), `rail_Analytics__Expense`,
  `rail_Analytics__Helpdesk`, `route_app_analytics_{expense,helpdesk}`, `route_app_request_initiate_{travel,
  advance_request, expense_receipt_request, asset_request, help_desk_ticket}` (request forms: pointed to matrix 20) and
  the `route_app_core_expense_*` / `rail_Setup__{Expense,Helpdesk}*` setup captures (pointed to the setup matrices).
- Lookups returned by the captured APIs (configuration, not personal data): `Asset/InventoryStatus/1` (In Stock, In Use,
  In Repair, In Transit, Missing, Reserved, Retired, Sold), `Expense/GeneralSetting` (switches incl. `isShowSuspectButton`,
  `isBulkEditAmount`, `isBulkEditMileage`, `isFinalApprovalPaymentRequired`, `isShowExpensePaymentDetail`),
  `Expense/Card` (receipt card fields Purpose, Location, Project, Other participant, Merchant, Field one … ten),
  `Expense/Tax/setting` (tax heads), `core/scheduler?moduleId=5|6` (daily "Expense dashboard", "Helpdesk dashboard" and
  "Send Notification of intimation task and escalate task relate to helpdesk" jobs), the Reports-tab views
  (`hrDesk/globalSearch/ReportList`: Travel summary / Travel details / Accommodation details on Travel request, Expense
  advance on Advance requests, Expense report details on Expense reports, Ticket details by requestee on Tickets) and the
  search APIs each register calls (`expense/travel/request/Admin/Search`, `expense/receipt/Admin/Search`,
  `expense/report/Admin/Search`, `Expense/AdvanceRequest/Advance/Employees`, `Asset/Inventory/Search`,
  `Asset/GetEmployeeAssetDetails`, `Asset/GetAssetAcknowledgementDetails`, `Asset/GetEmployeeRequestDetails`,
  `helpdesk/ticket/Request/Search`). Every register opened empty for the crawl account, so columns, filters and dialogs
  come from `components.json`.
- `hrone-research/menu-permission-tree.md` + `menu-catalogue.json` → `MenuAccessPermission/0` with each node's grantable
  rights: HR Desk → Search → Expense [8] / Asset [4] / Helpdesk [1]; Inbox Insights → Expense [8] / Asset [2] / Helpdesk
  [1]; Action → Expense [5] / Asset [2] / Helpdesk [1]; Imports → Asset [4] (no Expense or Helpdesk import node);
  Analytics → Expense [5] / Asset [2]; Reports → Expense [12] / Asset [3] / Helpdesk [2]; Team → Reports → Expense [12];
  Team → People → Action / Team profile → Expense, Asset, Helpdesk. `AvailableSearch` (each page's `navigationCode`,
  `searchGroupName`, `importIds`: 79 on Asset inventory, 104 / 106 / 107 on Asset request; `actionIds`) and
  `globalAction` (Expense: Add expense, Advance request, Pay expenses, Recover advance, Travel request; Asset: Add asset
  (inventory), Asset request; Helpdesk: Add ticket).
- `hrone-research/components.json`: travel `app-travel-search`, `app-travel-card`, `app-travel-approval-view-form`,
  `app-travel-approval-details`, `app-book-travel-details`, `app-book-travel-email-flyer`,
  `app-book-travel-itinerary-edit-drawer`, `app-book-travel-plan-edit-drawer`, `app-parse-confirm-dialog`; advances
  `app-expense-advance-search`, `app-advance-card`, `app-dispense-advance-search`, `app-card`, `app-dis-adv-detail`,
  `app-view-activity-log`, `app-expense-advance-recovery-grid(-flyer)`, `app-expense-recovery-flyer`; expense
  `app-expense-receipt-global-search`, `app-receipt-card`, `app-expense-receipt-search`, `app-report-card`,
  `app-receipt-view-form`, `app-receipt-carousel-content`, `app-report-approval-details`, `app-view-report-history`,
  `app-emp-punches-details-flyer`, `app-expense-payment-grid(-flyer)`, `app-expense-pay-flyer`, `app-expense-receipt`;
  asset `app-add-inventory-search`, `app-hardware-inventory-card`, `app-view-hardware-inventory`,
  `app-add-hardware-inventory(-flyer)`, `app-hi-form-step-one/two/three`, `app-hi-form-review`,
  `app-add-more-hardware-inventory-flyer`, `app-add-vendor-flyer`, `app-asset-card`, `app-employee-asset-search`,
  `app-employee-asset-card`, `app-employee-asset-flyer`, `app-emp-assets`, `app-asset-acknowledgement`,
  `app-acknowledge-asset`, `app-asset-request-search`, `app-asset-request-card`, `app-asset-view-form-flyer`,
  `app-asset-details`, `app-asset-task-owner-details`, `app-assign-asset-flyer`, `app-return-asset-flyer`,
  `app-view-product-details-flyer`; helpdesk `app-help-desk-search`, `app-help-desk-search-card`,
  `app-help-desk-view-form`, `app-help-desk-detail`, `app-update-ticket-flyer`, `app-feedback`, `app-feedback-message`,
  `app-help-desk-request(-flyer)`; shared `app-request-workflow(-flyer)`, `app-activity-log`, `app-inbox-status`,
  `app-bulk-process-popup`, `app-bulk-request-status-popup`.
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` for the ids above (structure only).
- PeopleOS, checked in code: `app/Domain/Expense` (`ExpenseReceipts`, `ExpenseReports`, `ExpenseAdvances`,
  `TravelRequests`, `TravelBookings`, `ExpenseDuplicateDetector`, `ExpenseCoverLetters`, `ExpenseSettlementSource`,
  `ExpenseWorkflowBridge`, models incl. `TravelRequestPart`), `app/Domain/Payroll/Inputs/Expense*Input`,
  `app/Domain/Assets` (`Assets`, `AssetRequests`, `AssetEntitlements`, `AssetAcknowledgementTaskSource`,
  `AssetRequestTriggers`), `app/Domain/ServiceDesk` (`ServiceDesk`, `CaseAssignment`, `ServiceDeskBulk`, `ResolverRules`,
  `HelpdeskAccess`, `ServiceDeskAnalytics`, `SlaClock`), `app/Domain/Experience/Sources/{ExpenseApprovalSource,
  AssetRequestApprovalSource}`; Filament `Resources/{ExpenseReceipts, ExpenseReports (+ Lines / Parts),
  ExpenseAdvances (+ Entries), TravelRequests (+ Legs / Parts), Assets (+ Assignments / Movements / Repairs),
  AssetRequests, Tickets (+ Comments / Transitions), Employees/RelationManagers/AssetsRelationManager}`,
  `Pages/{PayExpenses, DispenseAdvances, ServiceDeskAnalyticsPage}`, `Support/{AssetActions, ServiceDeskActions}`;
  `app/Domain/Employment/Imports/{ImportTypes, RowImportTypes}`; `app/Domain/Analytics/{Services/ReportLibrary,
  Services/WorkforceMetrics, Datasets/*}`; `config/peopleos.php` (permissions `expense.*`, `asset.*`, `servicedesk.*`,
  `peopleos.assets`, `peopleos.servicedesk`, `peopleos.expense`); docs `docs/architecture/hrone-parity.md` (EX-A … EX-G,
  AS-A, AS-B, HD-A … HD-C, owner decisions of 2026-10-29 on asset return at exit and on trips spanning several workflow
  groups), `docs/hrone-parity/setup/00-build-status.md`, `HROne-Setup-Parity-Report.md` §8 (open expense decisions).

**Pointed, not repeated.** Employee request forms (help desk ticket, expense advance, expense with Amount / Mileage tabs,
travel, asset) → `20-requests-inbox-team.md` §20.1.10–20.1.12, §20.1.16, §20.1.17; request history lists → §20.1.19
rows #12–#15, #20; inbox task views (`app-acknowledge-asset`, `app-asset-approval`, `app-asset-task-owner-approval`,
`app-expense-report-approval`, `app-dis-adv-approval`, `app-travel-approval`, `app-help-desk-approval`) → §20.2.2
rows #32–#38; the workflow tracker on every card (`app-request-workflow`: Send Reminder, Add approver, Discussion) →
§20.1.1 rows #8–#10 (RQ-2, RQ-9); team actions → §20.3.2 #3, #13, #14, #18, #27, #40; team reports → §20.3.4. The report catalogue
(HR Desk → Reports → Expense / Asset / Helpdesk), report centre and views → `21-analytics-reports-projects.md` §21.2.1,
§21.2.2 "Asset (3)", "Expense (12)", "Helpdesk (2)"; the Expense and Helpdesk dashboards → §21.1.5, §21.1.6; the Inbox
Insights page itself → §21.3 (AN-30). My space items (My assets, Trips, Travel tickets, expense histories) →
`22-my-space-self-profile.md`. Shared HR-desk register chrome (Filters flyer, chips, saved filters, bulk framework,
typed-count confirmation, import wizard) → `23-workforce-operations.md` §23.1 and §23.10.0 (WO-1, WO-2). Setup (expense
settings, policies, categories, forms; asset settings, policy, types, products, inventory form; helpdesk settings,
categories, resolver rules, policy) → `setup/11-expense-asset-helpdesk.md` §11, §14, §15 and
`setup/13-setup-reverification.md` §10, §13, §14 (EX-1, HD-1, HD-2) and its permission-tree cross-check. Such rows
appear here once, naming the matrix and section.

**Rules this matrix applies.** Every proposal is additive and its default reproduces today's behaviour. Money moves only
through the built rails: approved reports and advances are paid, and advances recovered, by `ExpenseReportInput`,
`ExpenseAdvancePayoutInput`, `ExpenseAdvanceRecoveryInput` (tag `peopleos.payroll_inputs`) or the
`ExpenseSettlementSource`; nothing is paid twice (`PayrollCommitments`, `ExpenseCommitments`); finalized payroll is never
reopened, so HROne's "add to a pay cycle" is rated against the open payroll months only. No one decides, pays or
recovers their own records. Tax rates on receipts are as printed (never shipped as values). Per-group options belong in
policy types (`expense`, `expense_advance`, `asset`, `helpdesk`), organisation switches in Setup → Expense / Asset /
Helpdesk.

**Progress.**

- Covered: every page of the three panels (Expense 8 + Advances register reached only from the catalogue, Asset 4,
  Helpdesk 1) with its Records view (search, filters, card columns, row actions, detail flyers and dialogs from
  components) and its Reports tab, the panel Reports pages, the eight module global actions, all nodes of HR Desk →
  Search → Expense [8] / Asset [4] / Helpdesk [1] with their rights, Action → Expense [5] / Asset [2] / Helpdesk [1],
  Imports → Asset [4], Inbox Insights → Expense [8] / Asset [2] / Helpdesk [1], Analytics → Expense [5] / Asset [2],
  Reports → Expense [12] / Asset [3] / Helpdesk [2] (pointer rows), Team → Reports → Expense [12], and the remaining
  tree nodes of these modules (Request, Team, Profile, Setup, Automation, Dashboards) as pointer rows.
- Could not: no register had data for the crawl account, so no populated card, booking, payment or recovery screen was
  seen; those rows come from component labels and are marked *(inferred)* where only a label is known. The import
  templates of the four asset imports were not captured (no `ImportList` row for module 7), so each import is rated from
  its name. HROne's Helpdesk has no ticket merge and no separate internal-note control; those rows say so.

**Built (2026-10-30).** XO-1 (Expense desk and Asset desk in HROne's panel order over the existing registers; travel and
advance registers with requested / decided / decided-by / waiting-with columns, date and WO-2 employee filters, HROne's
Travel bookings, Travel cancellations and Advance cancellations as views — refused cancellations now kept in
`cancel_refused_at`; "Report" links on the expense, travel, asset and ticket registers; the asset register's
"Assets by employee" and "Waiting for acknowledgement" views) and XO-2 (the read-only expense receipt page; the receipt
register's invoice / reimbursable / updated / payment columns and type, checks, payment-status and date filters; the
duplicate check keeps the receipts it matched): the rows they close are marked "→ built" with the package (rows only
partly closed say which part and which package finishes them); see `docs/architecture/hrone-parity.md` "XO-1 / XO-2
Expense and asset desks, register depth, the expense receipt page". The counts below are the analysis-time counts.

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | Relevance |
|---|---|---|
| Expense & travel navigation group: Expense receipts / My expenses, Expense reports / My expense reports, Expense advances / My expense advances, Travel requests / My travel requests, Pay expenses, Dispense advances; one register per record type, scoped by `AccessScope` (`ScopedByEmployee`), employees see their own under Me | `app/Filament/Resources/{ExpenseReceipts,ExpenseReports,ExpenseAdvances,TravelRequests}`, `Pages/{PayExpenses,DispenseAdvances}` | HROne Expense panel |
| Expense permissions `expense.view / manage / claim / approve / pay / travel_desk`; never deciding, paying or recovering one's own; `expense.approve` in scope files on someone's behalf (`ExpensePolicy::onBehalf`) | `config/peopleos.php`, `app/Domain/Expense/Policies/*` | HROne page rights, "Request on behalf" |
| Receipts (`ExpenseReceipts::capture / update / withdraw`): category in the employee's policy, limits and capping (`over_limit`), exceptional, duplicates (`ExpenseDuplicateDetector`: `suspected_duplicate`, `same_invoice_number`), mileage, attendance, tax lines, travel link; statuses unreported / reported / approved / partly approved / rejected / expired / withdrawn | `app/Domain/Expense/Services/ExpenseReceipts.php` | HROne expense receipts |
| Reports (`ExpenseReports`): draft → submit → parts by workflow group (+ exceptional) → line-by-line approval (0 rejects) / reject / return → approved / partly approved / rejected; advances settled; `net_payable`; Pay expenses (outside payment or "Pay with payroll"), payroll input, F&F; cover letter | `ExpenseReports.php`, `ExpenseReportResource`, `PayExpenses` | HROne expense reports, Pay expenses |
| Advances (`ExpenseAdvances`): request (policy rules), approve (for less) / reject, cancel (approval when the policy says), dispense (reference, date, full approved amount), payroll payout, settle against reports, refund, write off, payroll recovery from `recovery_due_on`, F&F recovery; ledger `expense_advance_entries` | `ExpenseAdvances.php`, `ExpenseAdvanceResource` (+ `EntriesRelationManager`), `DispenseAdvances` | HROne Advances, Recover advance |
| Travel (`TravelRequests`, `TravelBookings`): trip + legs (journeys, stays), workflow groups and parts (owner decision 2026-10-29), approval, cancellation with approval, helpdesk booking requests for company legs, "Record booking" (reference, provider, cost, currency, notes) by the travel desk, advance inside the trip, expenses against the trip | `TravelRequests.php`, `TravelBookings.php`, `TravelRequestResource` (+ Legs / Parts) | HROne Travel request / bookings / cancellations |
| Approval Center source for expense (`expense_report`, `expense_exceptional`, `expense_advance`, `expense_advance_cancellation`, `travel_request`, `travel_request_part`, `travel_cancellation`) and assets (`asset_request`, `asset_return_request`); asset acknowledgement as a My-tasks item; workflow bridges | `app/Domain/Experience/Sources/*`, `AssetAcknowledgementTaskSource`, `ExpenseWorkflowBridge`, `AssetRequestWorkflowBridge` | HROne inbox tasks / Inbox Insights |
| Asset register (`assets`: tag, name, category, sub type, model, serial, status in stock / assigned / in transit / in repair / lost / retired / disposed, condition, custodian, owned by company, location, purchase date / cost, vendor text, invoice number, warranty until, notes, custom fields of entity `asset`); actions Receive, Assign (date, condition, expected return, note), Transfer, Take back, Send for repair, Back from repair, Change location, Report lost, Found, Retire, Dispose (method incl. sale, date, realised value, reference); custody history with `acknowledged_at`, movements, repairs, audit history; book value | `AssetResource`, `AssetActions`, `Assets.php`, `asset_*` tables | HROne Asset inventory |
| Asset requests (`AssetRequests`): new / replacement / return, sources employee / manager / hr / policy / exit, approval per `asset.request.workflow_for`, fulfilment by the asset type's owner role, due dates, joiner and exit automation (owner decision 2026-10-29) | `AssetRequestResource`, `AssetRequests.php`, `AssetRequestTriggers` | HROne Asset request |
| Service desk: tickets (number, category, service = sub-category, priority low / normal / high / urgent, statuses draft … closed / cancelled, team role, agent, owner, SLA first response and resolution due, pause, escalation level, confidentiality, root cause + code, satisfaction), comments with visibility employee / internal / restricted, transitions, bulk assign / move / escalate (`servicedesk.bulk`), resolver rules, helpdesk access policy, service analytics | `TicketResource`, `ServiceDeskActions::forTicket`, `ServiceDesk.php`, `CaseAssignment.php`, `ServiceDeskBulk.php` | HROne Tickets |
| Report library (category "Expense & travel": 16 templates incl. HROne's 12; "Assets & service desk": assets assigned, assets by category, tickets by category, SLA breaches) and metrics (`expense_reports_pending`, `expense_payable`, `expense_advances_outstanding`, `expense_advances_overdue`, `open_tickets`) | `ReportLibrary.php`, `WorkforceMetrics.php`, datasets `expense_*`, `travel_request*`, `assets`, `servicedesk` | HROne Reports tabs, Analytics tiles |
| Uploads: staged import pipeline with P2 governance; types for workforce, time office and payroll only (`ImportTypes::BASE`, `RowImportTypes::TYPES`) — no asset, expense or helpdesk upload | `app/Domain/Employment/Imports/*`, `ImportActions` | HROne Imports → Asset |

---

## 26.0 Index — HR Desk → Search → Expense [8] / Asset [4] / Helpdesk [1] and the three panels

Not counted. "Rights" = the actions HROne can grant on the node. "Overall" = the weakest material row of the section.

| # | Search node (rights) | Panel item · `navigationCode` | Section | Overall |
|---|---|---|---|---|
| 1 | Expense → Travel (View) | Travel request · `travel_1016` | 26.2.1, 26.2.2 | PARTIAL |
| 2 | Expense → Travel booking (View) | Travel bookings · `travelbooking` | 26.2.3 | PARTIAL |
| 3 | Expense → Travel cancel (View) | Travel cancellations · `travelcancel_2460` | 26.2.4 | PARTIAL |
| 4 | Expense → Expense advance (View) | Advance requests · `expenseadvance_496` | 26.3.1 | PARTIAL |
| 5 | Expense → Dispense advance (View) | Advances · `dispenseadvance_773` (catalogue only; not in the crawled panel) | 26.3.2 | PARTIAL |
| 6 | Expense → Expense advance cancel (View) | Advance cancellations · `expenseadvancecancel_2561` | 26.3.3 | PARTIAL |
| 7 | — (Action node "Expense recovery") | Recover advance · `expenseadvancerecovery_1336` | 26.3.4 | MISSING |
| 8 | Expense → Expense receipt (View) | Expense receipts · `expensereceipt_2488` | 26.4.1 | PARTIAL |
| 9 | Expense → Expense report (View) | Expense reports · `expensereport_812` | 26.4.2, 26.4.3 | PARTIAL |
| 10 | — (Action node "Expense payment") | Pay expenses · `expensepayment_818` | 26.4.4 | PARTIAL |
| 11 | Asset → Asset inventory (View, Edit) | Asset inventory · `inventory_986` | 26.5.1–26.5.3 | PARTIAL |
| 12 | Asset → Asset by employee (View) | Employee Assets · `assetsearch_1029` | 26.5.4 | PARTIAL |
| 13 | Asset → Asset acknowledgement (View) | Asset acknowledgement · `assetacknowledgement_2239` | 26.5.5 | PARTIAL |
| 14 | Asset → Asset request (View) | Asset request · `assetrequest_1028` | 26.5.6 | PARTIAL |
| 15 | Helpdesk → Helpdesk (View, Ticket reopen) | Tickets · `helpdesk_560` | 26.6.1, 26.6.2 | PARTIAL |
| — | — | Reports (`openReports`, modules 5 / 7 / 6) | 26.8 | PARTIAL |
| — | — | Expense Setup / Asset Setup / Helpdesk Setup | `setup/11-expense-asset-helpdesk.md`, `setup/13-setup-reverification.md` | — |

---

## 26.1 Panel and register chrome (`rail_Expense`, `rail_Asset`, `rail_Helpdesk`, every `/app/processes/search/*/search` page of the three modules)

The three panels use the Workforce shell rated in matrix 23 §23.1 (Records / Reports tabs, search, Filters flyer,
IMPORTS, split action button, cards with SHOW MORE, Create View). Only what differs here is rated.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Rail "Expense" panel: groups Travel (Travel request, Travel bookings, Travel cancellations), Advances (Advance requests, Advance cancellations), Expense (Expense receipts, Expense reports), Reports, Expense Setup; panel search | One home for expense operations, grouped by process | Navigation group "Expense & travel": Expense receipts, Expense reports, Expense advances, Travel requests, Pay expenses, Dispense advances (+ masters and forms for `expense.manage`) | PARTIAL → built (XO-1: Expense desk (Expense & travel → Expense desk, own rail area) in HROne's process order — Travel · Advances · Expense · Payments · Reports · Expense setup — with Travel bookings, Travel cancellations, Advance requests / cancellations, Dispense advances, Suspected duplicates and Pay expenses as entries over the existing registers and pages (preset views); navigation entries unchanged) | XO-1: order the group by HROne's process (Travel · Advances · Expense · Payments · Reports) and add the preset registers of 26.2.3, 26.2.4, 26.3.2, 26.3.3 as filtered entries of the existing resources (no new screens where a resource exists) |
| 2 | Rail "Asset" panel: Asset inventory, Employee Assets, Asset acknowledgement, Asset request, Reports, Asset Setup | Asset operations home | Navigation group "Assets": Asset register, Asset requests (+ types, sub types, models for `asset.manage`) | PARTIAL → built (XO-1: Asset desk (Assets → Asset desk, own rail area) — Asset inventory · Employee assets · Asset acknowledgement · Asset request · Reports · Asset setup — with "Assets by employee" and "Waiting for acknowledgement" as views of the asset register (new HR-side Acknowledgement filter); their own registers stay XO-9) | XO-1: add "Assets by employee" (26.5.4) and "Acknowledgements" (26.5.5) entries |
| 3 | Rail "Helpdesk" panel: Tickets, Reports, Helpdesk Setup | Helpdesk home | Navigation group "Service Desk": HR queue, Service analytics, ticket categories, services, SLA policies, resolver rules | HAVE | — |
| 4 | Tabs "Records" / "Reports" on each register | Register vs the page's default report | Registers are Filament lists; reports in Analytics → Report library | PARTIAL → built (XO-1: "Report" on Travel requests (travel summary / details / accommodation / live trip), Expense advances (advance amount, outstanding, recovery register), Expense receipts (XO-2), Expense reports (report details, pending, payment register), the asset register (assets assigned, by category) and Tickets (tickets by category, SLA breaches) — "Ticket details by requestee" waits for AN-23) | WO-1 "Report" header action (matrix 23 §23.1 #2) opening the matching template: Travel summary / Travel details / Accommodation details (Travel request), Expense advance (Advance requests), Expense report details (Expense reports), Hardware inventory (Asset inventory), Ticket details by requestee (Tickets) |
| 5 | Reports tab of Travel request: system views "Travel summary_System", "Travel details_System", "Accommodation details_System", "Create View", "EDIT FILTER" | Three saved report views on the register | Templates `travel_summary`, `travel_details`, `accommodation_details` (EX-F); saved reports | HAVE | Linked by WO-1 |
| 6 | Reports tab of Advance requests: "Expense advance_System" | Advance report view | Template `expense_advance_amount` | HAVE | — |
| 7 | Reports tab of Expense reports: "Expense report details_System" | Report details view | Template `expense_report_details` | HAVE | — |
| 8 | Reports tab of Tickets: "Ticket details by requestee_System" | Ticket report view | Dataset `servicedesk` lacks requester org fields | PARTIAL | AN-23 (matrix 21 Helpdesk #1) |
| 9 | Reports tab empty ("Seems like you're new here. Create new report view.", CREATE VIEW) on Travel bookings, Travel cancellations, Advance cancellations, Expense receipts, Asset inventory, Employee Assets, Asset acknowledgement, Asset request | No default view; the user may create one | Report library templates per dataset; saved reports | HAVE | — |
| 10 | Search "Search employee by name, code, designation, email" (expense, ticket, asset request, acknowledgement pages) | Find records by employee | Expense, travel, advance lists search the record number only; Tickets search number and employee name; Asset requests search number | PARTIAL → partly built (XO-1 / XO-2: the WO-2 employee search on Travel requests, Expense advances and Expense receipts; the other registers here keep their search until their packages) | WO-2 shared employee search (code, names, e-mail, designation) added to every register here |
| 11 | Search "Search managed by name or code or designation" (Asset inventory) | Find assets by the person who manages them | Asset register searches tag, name, serial; no "managed by" | MISSING | XO-8 (managed-by) then WO-2 search on it |
| 12 | "IMPORTS" on Expense and Helpdesk pages | Opens the import dashboard; HROne's page-access tree has no Expense or Helpdesk import node | — | N-A | Nothing to import in HROne for these modules from the HR desk (the employee's bulk-expense Excel import is matrix 20 §20.1.12 #9, RQ-7) |
| 13 | "Imports ▾" on Asset inventory (import 79) and Asset request (imports 104, 106, 107) | The page's asset imports | No asset uploads | MISSING | 26.5.7 (XO-11) |
| 14 | Split button "Add expense ▾" / "Advance request ▾" / "Travel request ▾" (Expense pages) listing the five Expense global actions | Start any expense action from any expense page | Header actions per register: Add expense, Add expense for an employee, New expense report, Request an advance, Request travel, Request travel for an employee; Pay expenses / Dispense advances pages | PARTIAL | 26.7 rows; XO-5 adds the missing on-behalf actions |
| 15 | Split button "Add asset (inventory) ▾" (Asset pages) and "ACTIONS" (Asset acknowledgement) | Asset global actions | "Receive asset" on the register; "Request for an employee" on Asset requests | PARTIAL | 26.7 rows |
| 16 | Split button "Add ticket ▾" (Tickets) | Raise a ticket on someone's behalf | "Ask HR" (For employee) and service "Request" for an employee | HAVE | — |
| 17 | Cards with avatar, "SHOW MORE" paging, `app-hr-desk-blank-state` ("No results found — Try adjusting the filters or come back later!") | Card list and empty state | Tables with pagination and empty states | HAVE | Card layout is WO-1 (matrix 23 §23.1 #10) |
| 18 | "View workflow" flyer on travel, advance, report and asset-request cards (`app-request-workflow-flyer`) | See each approval level, send reminder, add approver | Approval Center items; workflow run viewer | PARTIAL → partly built (RQ-2: each level, whom it waits with and "Send reminder" on the requester's request page for travel, advance, report and asset requests; "Workflow and policy" on the approver's card; add approver stays RQ-9) | Matrix 20 §20.1.1 #8–#10 (RQ-2, RQ-9) |
| 19 | Note "Use filters to narrow down expense receipts by status, type, date or project" / "In order to download documents in bulk, please apply appropriate filter" | Guidance on filtering and bulk download | — | N-A | Help text; the features are rows 26.4.1 / 26.4.2 |
| 20 | Banners (subscription overdue, contact verification pending, low bandwidth, chat bubble), "Looking for something else" | Vendor chrome / page jump | Command palette | N-A | Vendor chrome (matrix 23 §23.1 #17, #20) |

---

# Expense (panel "Expense", module 5)

## 26.2 Travel

### 26.2.1 Travel request register (`/app/processes/search/travel/search`, `travel_1016`; `app-travel-search` → `app-travel-card`, `app-travel-request-flyer`; API `expense/travel/request/Admin/Search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register scope: every trip the HR user's data access covers (`Admin/Search`) | Organisation-wide trip register | Expense & travel → Travel requests for `expense.view / approve / pay / travel_desk` holders, inside the access scope (`TravelRequestResource::oversees`) | HAVE | — |
| 2 | Card column "Requested date" | When the trip was raised | Not shown (`created_at` kept) | PARTIAL → built (XO-1: "Requested on" (HR side, hidden until chosen; shown by the "Waiting for approval" view)) | XO-1: toggleable "Requested on" column |
| 3 | Card column "Approved date" | When it was approved | `decided_at` kept, not shown | PARTIAL → built (XO-1: "Decided on") | XO-1: "Decided on" column |
| 4 | Card column "Travel date" | Trip dates | "Starts on" / "Ends on" columns | HAVE | — |
| 5 | Card column "Status" | Open / approved / booked / rejected / cancelled | Status badge (waiting, approved, approved · booking, approved · booked, rejected, cancellation requested, cancelled) | HAVE | — |
| 6 | Card column "Assigned to" | Whom the request waits with *(inferred)* | Not shown; the Approval Center lists the item to its approvers | PARTIAL → built (XO-1: "Waiting with" — the pending tasks of the workflow running on the trip (or on each pending part of a mixed trip), else "Expense approvers (Approval Center)") | XO-1: "Waiting with" column from the open workflow task / Approval Center owner (same source as RQ-2) |
| 7 | Card column "Approver" | Who decided | `decided_by` kept, not shown; parts show "Decided by" for mixed trips | PARTIAL → built (XO-1: "Decided by") | XO-1: "Decided by" column |
| 8 | Card column / chip "Project", filter "Project" | Trip's project | No projects | MISSING | Blocked on projects (PM-10, matrix 21 §21.4.10 #5) |
| 9 | Filters flyer: "Requested date", "Travel date", "Approved date" (ranges) with chips "Requested date :", "Travel date :", CLEAR ALL, Apply | Date-range filters | Status filter only | PARTIAL → built (XO-1: Requested, Travel date (overlapping the range) and Decided date ranges, plus the WO-2 codes / organisation / employee-status / pending-resignation filters and employee search (HR side)) | XO-1: date-range filters on requested, travel (overlapping the range) and decided dates |
| 10 | Filter "Status" | Status filter | Status filter (multiple) | HAVE | — |
| 11 | "Book Travel" on the card | Open the booking dialog for the trip | Travel desk records each company booking on the trip page ("Journeys and stays" → Record booking) | PARTIAL | 26.2.3 (XO-7) |
| 12 | Card → detail flyer (`app-travel-approval-view-form`) | Read the whole trip | Trip page (`ViewTravelRequest`) | HAVE | Field depth in 26.2.2 |
| 13 | (PeopleOS) Approve, Reject, Approve cancellation, Keep trip, Cancel trip on the register | — | `TravelRequestResource` record actions, never one's own; mixed trips decided part by part | HAVE | Extra in PeopleOS (HROne's HR register is view-only: right "View") |

### 26.2.2 Travel request detail as HR sees it (`app-travel-approval-details`, `app-activity-log`, `app-hrone-document-viewer`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Purpose of travel | Why | "Purpose" | HAVE | — |
| 2 | Project | Trip's project | — | MISSING | Blocked (PM-10) |
| 3 | "Overall tour duration" From date / To date | Trip dates | "Starts on" / "Ends on", trip state (upcoming / travelling now / completed) | HAVE | — |
| 4 | Currency · Estimated cost | Trip estimate | Estimated cost in the trip currency and in the base currency | HAVE | — |
| 5 | "Exceptional" | Trip flagged exceptional *(inferred: outside policy)* | Backdated and international trips are high-risk in the Approval Center; no exceptional flag on a trip | PARTIAL | Matrix 20 §20.1.16 #17 (RQ-7); no new field here |
| 6 | "This is an international travel", Visa number, Passport No, Passport Expiry Date | International details | International flag, visa and passport numbers (encrypted, masked) | PARTIAL | Passport expiry is matrix 20 §20.1.16 #6 (RQ-7) |
| 7 | Reason for delay | Backdated trip reason | "Reason for the delay" | HAVE | — |
| 8 | Expense advance details: amount, required date, preferred mode of advance | The advance raised with the trip | "Advance" (number · status) on the trip | PARTIAL | XO-5: show amount, needed on and (after RQ-7) preferred mode on the trip page |
| 9 | Travel details per journey: From, From nearby, To, To nearby, Departure date & time, Preference, "Any special request" | The journey as requested | "Journeys and stays": leg summary, category, date, return, booked by, booking status, reference, cost, helpdesk request; preferred time and notes stored, not shown; nearby places not captured | PARTIAL | XO-7: show preferred time and notes on the legs table; nearby places are RQ-7 (matrix 20 §20.1.16 #10) |
| 10 | Travel details per stay: Check-in / Check-out date & time, Location/city, Near by location, No of guest | The stay as requested | Stay leg: city, state, check-in, check-out, preferred time | PARTIAL | Guests / nearby location: RQ-7 |
| 11 | Comments | Requester's comment *(inferred)* | Leg notes; trip has no comment field | PARTIAL | Covered by the leg notes; no new field |
| 12 | "Cancellation remarks :" | Why the trip was cancelled | "Cancellation reason" | HAVE | — |
| 13 | Attachment (Download, document viewer) | Trip documents | Attachments stored under `tenants/{id}/expense/travel`; not listed on the trip page | PARTIAL | XO-7: attachments section with signed downloads (`ParityFileController` type `travel-request`) |
| 14 | Custom fields of the travel plan form (Field one … ten) | Tenant fields | Custom fields of entity `travel_request` saved on the trip; not shown on the trip page | PARTIAL | XO-7: `CustomFieldsSchema::infolistSection(TravelRequest::class)` |
| 15 | "View More" / "View History" (activity log, workflow levels) | Trip history | Audit trail kept; no history tab on the trip page | PARTIAL | XO-7: `AuditHistoryRelationManager` on the trip; workflow trail is RQ-2 → built (RQ-2: the trip's trail, incl. its workflow levels and parts, on the requester's request page) |
| 16 | "Edit" (HR edits the plan) | Change the trip before or after approval *(inferred)* | No edit after submission | MISSING | 26.2.3 #20–#22 (XO-7, owner decision D-XO3) |
| 17 | "Confirm travel request — Please review the details below before submitting", "Confirm approve" | Review / confirm step | Approve dialog with note | HAVE | — |
| 18 | Status badges (`app-inbox-status`: COMPLETED, REJECTED, APPROVED, IGNORED, MISSED TO RESPOND, OVERDUE, DUE: TODAY / TOMORROW) | Task state | Status badge; Approval Center due / overdue | PARTIAL → built (RQ-8: the unified inbox labels every row Due in N days / Due today / Due tomorrow / Overdue, "Missed to respond" and "Not relevant"; approved / rejected stay on the Approvals "Completed" list) | Matrix 20 RQ-8 |
| 19 | (PeopleOS) "Approvals by workflow group" for trips spanning several groups | — | `PartsRelationManager` (owner decision 2026-10-29) | HAVE | Extra in PeopleOS |

### 26.2.3 Travel bookings — the travel desk (`/app/processes/search/booktravel/search`, `travelbooking`; booking dialog `app-book-travel-details` "BOOK TRAVEL DETAILS" with stepper, `app-book-travel-email-flyer`, `app-book-travel-itinerary-edit-drawer`, `app-book-travel-plan-edit-drawer`, `app-parse-confirm-dialog`, `app-file-uploader`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register "Travel bookings": approved trips with tickets or stays the company books (same card as 26.2.1) | The travel desk's work list | No register; company legs become helpdesk booking requests (one per trip and desk, `TravelBookings::open`) in the HR queue, and the trip list can be filtered "Approved · booking" | PARTIAL | XO-7: "Travel bookings" register over `travel_request_legs` (company-booked, status pending / booking / booked / cancelled; filters date range, kind journey / stay, category, desk, trip status) for `expense.travel_desk` holders, linking the trip and the helpdesk request |
| 2 | Inbox task "Book travel ticket" (Inbox Insights node) | A booking task per trip for the travel agent | Booking request ticket in the travel desk's queue (category purpose `travel_booking` / `accommodation_booking`, or the category's `booking_service_id`), SLA and routing of the service | HAVE | — |
| 3 | Booking details: Booking status (`travelBookingStatusId`) | Pending / booked / not required / cancelled per ticket *(inferred)* | Leg status pending / booking / booked / cancelled, set by recording a booking or by cancelling the trip | PARTIAL | XO-7: explicit status change by the desk (booked, not required, cancelled) |
| 4 | Category (`categoryId`) | Expense category of the booking | Leg category (travel-linked category) | HAVE | — |
| 5 | Booking / Expense date (`receiptDate`) | When it was booked / the expense date | Not kept | MISSING | XO-7: `booking_details.booked_on` |
| 6 | Booking amount (`bookingAmount`) | Ticket / stay cost | "Cost" + currency, kept in the base currency (`booking_cost_base`) | HAVE | — |
| 7 | Commission amount (`commissionAmount`) | Agent's commission | — | MISSING | XO-7: `booking_details.commission` (base amount kept) |
| 8 | Cancellation amount (`canceledAmount`) | Charge for a cancelled booking | — | MISSING | XO-7: on cancelling a booked leg, the charge is recorded (`booking_details.cancellation_charge`) |
| 9 | Hours / Minutes (`travelHours`, `travelMinutes`) | Journey duration *(inferred)* | — | MISSING | XO-7: optional `booking_details.duration_minutes` |
| 10 | Description, Purpose ("reason for travel"), From / To city, Location, Other participants (employee search) | Receipt-style details of the booking | Booking notes; the trip holds purpose and places | PARTIAL | XO-7 booking as receipt (#12) carries them |
| 11 | Invoice no (`invoiceNumber`), Merchant name (`merchantName`) | Supplier invoice | "Airline, railway, hotel or agent" (provider) | PARTIAL | XO-7: `booking_details.invoice_number` |
| 12 | Claim reimbursement (`isReimbursable`), "Expense receipt limit :", "Extracted expense details", "Save and submit" (the booking becomes an expense receipt of the employee) | The booking is filed as a company-paid (or reimbursable) receipt on the trip, counted against limits | Booking cost is information on the leg; it never becomes a receipt, so company-booked costs are not in expense reports or limits | MISSING | XO-7 (owner decision D-XO4): optional "File as expense" on a recorded booking → `ExpenseReceipts::capture` for the employee with the leg's category, `reimbursable = false` (company paid) unless the employee paid, linked to the trip; default off = today |
| 13 | Tax details: Tax type (`taxType`), Destination of supply (`stateCode`), GSTIN, tax rows (`expenseTaxId`, `amount`) | GST on the booking invoice | Receipts carry supplier GSTIN and tax lines; bookings do not | MISSING | XO-7: through #12 (the receipt's tax section) |
| 14 | Pre approval code | Reference to a pre-approval | — | MISSING | Same as matrix 20 §20.1.12 #3 (RQ-7) |
| 15 | Project (`projectId`) | Project of the booking | — | MISSING | Blocked (PM-10) |
| 16 | Custom receipt fields `newfieldOne` … `newfieldTen` | Tenant fields on the booking receipt | Custom fields of `expense_receipt` (receipts only) | MISSING | Through #12 |
| 17 | Attachment upload (ticket / invoice file) | Booking document for the employee | Record booking takes no file | MISSING | XO-7: booking files under `tenants/{id}/expense/travel`, shown to the employee on the trip |
| 18 | "Parsing receipt", "Parse receipt?" confirm ("Some fields might be overridden …") | OCR fills the booking from the invoice | — | MISSING | Seam `ReceiptParser` (matrix 20 §20.1.12 #2, RQ-7); provider is an owner decision (external) |
| 19 | "Send email" (`isSendMail`) → email flyer: To (`toEmailTemplate`), Cc (`ccEmailId`), Subject, body (rich text), Done | Mail the itinerary / booking to the employee or an agent | Event `expense.travel_booked` reaches notification rules (else in-app to the employee); no composed mail | PARTIAL | XO-7: "Send booking details" action composing a mail (to the employee by default, CC list, subject and body prefilled from a notification template) sent through the tenant mail transport; audited |
| 20 | "Edit itinerary" (`app-book-travel-itinerary-edit-drawer`: mode of travel, one-way / round / multi-city, accommodation preference, legs, remarks, from / to city, nearby places, departure and preferred departure, return and preferred return, required, accommodation type, city, nearby location, guests, check-in / preferred, check-out / preferred) | The desk corrects legs while booking | No change to a trip's legs after submission | MISSING | XO-7 (owner decision D-XO3): desk edit of an approved trip's legs (audited, the employee told; changes beyond dates or places within the trip either re-open approval or not, as decided) |
| 21 | "Edit travel plan" (`app-book-travel-plan-edit-drawer`: purpose, from / to date, delay reason, project, currency, estimated cost, international, visa, passport no / expiry, Remove) | The desk corrects the plan | — | MISSING | XO-7 with #20 (same decision) |
| 22 | "Add accommodation/ticket" | The desk adds a leg (e.g. a hotel the employee forgot) | — | MISSING | XO-7 with #20 |
| 23 | "Not required" / "Cancelled" on a leg | Close a leg without booking, or cancel a booked leg | Legs are cancelled only with the whole trip | MISSING | XO-7: `not_required` leg status and leg cancellation (with #8 charge); the trip becomes `booked` when every company leg is booked or not required |
| 24 | "View travel details", "View History" (activity log) | Context while booking | Trip page; helpdesk request history | PARTIAL | 26.2.2 #15 |
| 25 | Advance details on the booking screen | Context: the trip's advance | Trip page shows the advance | PARTIAL | 26.2.2 #8 |
| 26 | Who books (employee or company) and employee-recorded bookings | Employee books own tickets | "Booked by" per leg; the employee records their own legs | HAVE | — |
| 27 | Stepper "Previous" / "save" / "Add more details" | Dialog chrome | Modal form | N-A | Chrome |

### 26.2.4 Travel cancellations (`/app/processes/search/travelcancel/search`, `travelcancel_2460`; same card)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of travel cancellation requests (pending and decided) | HR sees every cancellation | Travel requests filtered "Cancellation requested" / "Cancelled"; refused cancellations ("Keep trip") are visible only in the audit | PARTIAL → built (XO-1: "Travel cancellations" view (Cancellation filter: waiting, cancelled, refused); refusals stamp `travel_requests.cancel_refused_at` in `TravelRequests::rejectCancellation`, kept when the trip is cancelled later) | XO-1: "Travel cancellations" preset of the travel register (cancel requested, cancelled, and trips whose cancellation was refused — `cancel_refused_at` stamp) |
| 2 | Card columns (requested / approved / travel date, status, assigned to) and filters | As 26.2.1 | As 26.2.1 | PARTIAL → built (XO-1: the 26.2.1 columns and filters apply) | 26.2.1 #2–#9 |
| 3 | Cancellation approval (workflow "Travel cancel", Inbox Insights node "Travel cancel") | Approve the cancellation of an approved trip | `expense.travel_cancel_requested` workflow trigger; Approval Center `travel_cancellation`; "Approve cancellation" / "Keep trip" | HAVE | — |
| 4 | Cancellation remarks | Reason | Cancellation reason (required) | HAVE | — |
| 5 | Effect on bookings and advance | Bookings and unpaid advance cancelled *(inferred)* | Cancelling cancels legs, running workflows and an unpaid advance; booking requests' assignees told; refused once expenses are claimed | HAVE | — |

## 26.3 Advances

### 26.3.1 Advance requests (`/app/processes/search/expenseadvance/search`, `expenseadvance_496`; `app-expense-advance-search` → `app-advance-card`, `app-advance-request-flyer` → `app-advance-request`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of every advance request in data access | HR advance register | Expense & travel → Expense advances (in scope) | HAVE | — |
| 2 | Card column "Requested date" | Raised on | Not shown | PARTIAL → built (XO-1: "Requested on") | XO-1 column |
| 3 | Card column "Approved date" | Decided on | Not shown (`decided_at`) | PARTIAL → built (XO-1: "Decided on" and "Decided by" (and "Waiting with")) | XO-1 column |
| 4 | Card column / filter "Status" | Status | Status badge and filter (requested … written off) | HAVE | — |
| 5 | Filter "Requested date" (range), chips, CLEAR ALL, Apply | Date filter | — | MISSING → built (XO-1: Requested and Decided date ranges (+ WO-2 employee filters, HR side)) | XO-1 date-range filter |
| 6 | Card → request view: amount, required date, purpose, attachment, project | Read the advance | Advance page: asked for, approved, outstanding, settle by, recovery date, paid how, dispensed on, reference, purpose, decision note; Files column | HAVE | Project blocked (PM-10) |
| 7 | Preferred mode of advance (`advanceMode`) | How the employee wants the money | Not asked | MISSING | Matrix 20 §20.1.11 #6 (RQ-7); XO-5 shows it on dispense |
| 8 | "view ledger" → Advance Outstanding ledger (Date, Opening outstanding balance, Outstanding balance (CR/DB), Closing outstanding balance, Reason) | The employee's running outstanding across advances | Ledger per advance (Entries: dispensed, adjusted, refunded, written off, recovered) | PARTIAL | XO-6: employee ledger view (all advances' entries in date order with running balance), reused by 26.3.2 and 26.3.4 |
| 9 | "View activity log" ("Dispense expense advance request of … as expense advance to …") | History | Audit kept; no history tab | PARTIAL | XO-5: `AuditHistoryRelationManager` on the advance |
| 10 | (PeopleOS) Approve (for less), Reject, Approve cancellation, Keep advance, Dispense, Record refund, Write off, Cancel on the register | — | `ExpenseAdvanceResource` record actions | HAVE | Extra in PeopleOS |

### 26.3.2 Advances — dispense (`/app/processes/search/dispenseadvance/search`, `dispenseadvance_773`, Search node "Dispense advance"; `app-dispense-advance-search` → `app-card` → `app-dis-adv-detail`, `app-dis-adv-approval`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of advances to dispense and dispensed (card columns "Requested date", "Dispense date", "Status") | Finance's dispense list with history | Dispense advances page: approved advances waiting (number, employee, purpose, needed on, to pay, "Policy says" with payroll / by bank, approved on); dispensed ones only in Expense advances (status Dispensed, "Dispensed on" on the page) | PARTIAL | XO-5: "Dispensed" tab on Dispense advances (status dispensed / settled / recovered, dispensed on, reference, channel) with dispense-date filter |
| 2 | Filters "Status", "Requested date", "Dispense date", chips | Filter the list | — | MISSING | XO-5 |
| 3 | Detail "Dispense advance request — REQUEST DETAILS": Request amount, Approved amount, Required Date, Purpose, Remarks, Comments, Attachment | What is being paid | Advance page fields and files | HAVE | — |
| 4 | Project | Advance project | — | MISSING | Blocked (PM-10) |
| 5 | Outstanding amount, "Total expense advance outstanding amount", "View ledger" | What the employee already owes | Outstanding per advance; no employee total on the dispense screen | PARTIAL | XO-5 column "Employee's outstanding" + the XO-6 ledger link |
| 6 | Preferred mode of advance | Employee's wish | — | MISSING | RQ-7 then XO-5 column |
| 7 | Travel request details "View details" | The trip the advance is for | `expense_advances.travel_request_id` kept; not shown on the advance or the dispense list | PARTIAL | XO-5: "Trip" column / entry linking the trip |
| 8 | Dispense amount (`dispenseAmount`, editable) | Pay part or all of the approved amount *(inferred: may differ from approved)* | Dispense pays the approved amount in full | MISSING | XO-5 (owner decision D-XO8): optional dispense amount ≤ approved; the rest stays approved (a second dispense) or lapses, as decided; default = full amount (today) |
| 9 | Remarks (`remarks`) on dispense | Finance's note | Payment reference and paid-on date; no note | PARTIAL | XO-5: optional note on dispense (ledger entry note) |
| 10 | (PeopleOS) Payment reference and date on dispense; payroll payout (`ExpenseAdvancePayoutInput`) for policies that pay with payroll; refusal while a payroll run carries the payout | — | `ExpenseAdvances::dispense`, `PayrollCommitments` | HAVE | Extra in PeopleOS; open owner decision "advances approved for payroll may be dispensed by bank before payroll" (HROne-Setup-Parity-Report §8) applies, not re-raised |
| 11 | Inbox status "IGNORED" on the dispense task | The dispense task was ignored / archived | Dispensing is a page queue, not an inbox task | N-A | Inbox archive is matrix 20 (RQ-8) |
| 12 | Inbox view `app-dis-adv-approval` (More / Less) | Dispense from the inbox | — | PARTIAL | Matrix 20 §20.2.2 #36 |

### 26.3.3 Advance cancellations (`/app/processes/search/expenseadvancecancel/search`, `expenseadvancecancel_2561`; same `app-advance-card`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of advance cancellation requests (pending and decided) | HR sees cancellations | Expense advances filtered "Cancellation requested" / "Cancelled"; refused cancellations only in the audit | PARTIAL → built (XO-1: "Advance cancellations" view (waiting, cancelled, refused — `expense_advances.cancel_refused_at` stamped by `ExpenseAdvances::rejectCancellation`)) | XO-1: "Advance cancellations" preset (cancel requested, cancelled, refused — `cancel_refused_at`) |
| 2 | Card columns and filters (requested date, approved date, status) | As 26.3.1 | As 26.3.1 | PARTIAL → built (XO-1: the 26.3.1 columns and filters apply) | 26.3.1 #2–#5 |
| 3 | Cancellation approval (Inbox Insights "Expense advance cancel"; policy "cancellation approval") | Approve the cancellation of an approved advance | `cancel_requested` → Approve cancellation / Keep advance; Approval Center `expense_advance_cancellation`; workflow trigger `expense.advance_cancel_requested` | HAVE | — |
| 4 | Request-side "Cancel request" right (Request → Expense advance) | Employee cancels | Cancel (at once while requested, or by an approver; else cancellation approval); refused once dispensed | HAVE | — |

### 26.3.4 Recover advance (global action "Recover advance", `expenseadvancerecovery_1336`, Action node "Expense recovery" (View); `app-expense-advance-recovery-grid-flyer` "Expense Recovery" → `app-expense-advance-recovery-grid`, `app-expense-recovery-flyer`, `app-bulk-process-popup`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Grid of employees with outstanding advances: Select, Employee code, Employee name, Action, recovery Month, recovery Mode, last expense submitted | Recovery desk per employee | No recovery desk; each advance shows outstanding, settle by and "Recovered through payroll from" | MISSING | XO-6: "Advance recovery" page (`expense.pay`): one row per employee with outstanding (dispensed advances), oldest settle-by, scheduled recovery month and mode, last report submitted on |
| 2 | "Recovered" / "Unrecovered" | Done vs pending recoveries | Advance status Recovered vs Dispensed (filter on Expense advances) | PARTIAL | XO-6 tabs |
| 3 | Search "Enter employee code, name" | Find an employee | — | MISSING | XO-6 (WO-2 search) |
| 4 | Filter options: OU filter (Organization, Location), Status (employee status), Employee, "Last expense submitted duration" | Narrow the desk (e.g. no expense filed for 60 days) | — | MISSING | XO-6 filters (organisation group from WO-2; "no report submitted for N days") |
| 5 | Show / Show more / Show less, Reset, Apply, Clear all | Paging and filter chrome | Table chrome | HAVE | — |
| 6 | "Employee(s) Selected" + "Payroll Recovery" / "Out of System Recovery" (bulk) | Recover many at once | — | MISSING | XO-6 bulk actions with a result summary |
| 7 | "View Ledger" (Advance Outstanding ledger: Date, Opening outstanding balance, Outstanding balance credited, Closing outstanding balance, Reason) | Employee's advance ledger | Per-advance entries | PARTIAL | XO-6 employee ledger (26.3.1 #8) |
| 8 | Payroll Recovery: Salary month (`paymentMonth`; "You can only select months in future for which payroll is not processed"), Recovery Amount (`recoveryAmount`), Comments; "You are about to proceed for payment recovery … Yes, Recover Payroll" | Schedule a payroll deduction of a chosen amount in a chosen future month | Automatic: the whole outstanding is deducted by the first payroll on or after `recovery_due_on` (policy `recovery = payroll`, `recovery_after_days`); waits while a report is undecided; no manual month or amount | PARTIAL | XO-6 (owner decision D-XO2): "Recover through payroll" — month among open, not-finalized pay months from `PayCycle`, amount ≤ outstanding; stored as a scheduled recovery the `ExpenseAdvanceRecoveryInput` reads (scheduled rows win over the automatic date); refused when a run not yet finalized already carries the advance; never one's own |
| 9 | Out of System Recovery: Cheque — Bank Name (`bankName`), Cheque number, Cheque Amount, Cheque date; Cash — Cash amount, Cash received date, remarks; "Save Recovery" | Record money returned outside payroll | "Record refund": amount, payment reference, received on | PARTIAL | XO-6: refund mode (bank transfer / cheque / cash) with bank name, cheque number and date, note; ledger entry `refunded` keeps them |
| 10 | Bulk progress popup | Long run | — | MISSING | XO-6 (WO-1 bulk framework) |
| 11 | (PeopleOS) Write off (reason), recovery at exit (`EXP_ADVANCE_BALANCE` in the F&F), never twice (`PayrollCommitments`, `ExpenseCommitments`) | — | `ExpenseAdvances::writeOff`, `ExpenseSettlementSource` | HAVE | Extra in PeopleOS |

## 26.4 Expense

### 26.4.1 Expense receipts register (`/app/processes/search/expensereceiptglobal/search`, `expensereceipt_2488`; `app-expense-receipt-global-search` → `app-receipt-card`, `app-expense-receipt-flyer`, `app-emp-punches-details-flyer`; API `expense/receipt/Admin/Search`, `Expense/Card`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of every receipt in data access | HR receipt register | Expense & travel → Expense receipts (in scope) | HAVE | — |
| 2 | Receipt code / Receipt no. | Number | Number (code series `expense_receipt`) | HAVE | — |
| 3 | Expense date | Date | Expense date (sortable) | HAVE | — |
| 4 | Category | Category | Category badge + filter | HAVE | — |
| 5 | Amount details: Amount, Currency code | Amount as claimed | Amount with currency (km · amount for mileage), claimable, approved | HAVE | — |
| 6 | Invoice number | Invoice | Stored; not a column | PARTIAL → built (XO-2: "Invoice number" column (HR side, hidden until chosen)) | XO-2: toggleable column |
| 7 | Project | Project | — | MISSING | Blocked (PM-10) |
| 8 | Reimbursable | Repaid to the employee or company-paid | `expense_receipts.reimbursable` stored; not a column, not on the form | PARTIAL → built (XO-2: "Repaid to the employee" column (the form toggle stays RQ-7)) | XO-2 column; the form toggle is matrix 20 §20.1.12 #3 (RQ-7) |
| 9 | Description | Text | Stored; not shown in the register | PARTIAL → built (XO-2: on the receipt page) | XO-2 receipt detail page (#17) |
| 10 | Updated on | Last change | `updated_at`; not shown | PARTIAL → built (XO-2: "Updated on" column (sortable)) | XO-2 toggleable column |
| 11 | Pre Approved Reference | Pre-approval code | — | MISSING | RQ-7 (matrix 20 §20.1.12 #3) |
| 12 | History ("NO HISTORY FOUND") | Receipt change history | Audit trail kept; no receipt page | PARTIAL → built (XO-2: History tab (`AuditHistoryRelationManager`, `audit.view`) on the receipt page) | XO-2 (#17) with `AuditHistoryRelationManager` |
| 13 | Tax details | Tax lines | `expense_receipt_taxes` + supplier GSTIN stored; not shown | PARTIAL → built (XO-2: Tax section (supplier GSTIN, supply of, tax lines: tax, rate, amount)) | XO-2 (#17) |
| 14 | Mileage details: From Location, To Location, Hours, Distance (Km), Rate, Odometer start / end reading | Route and computation | Stored (from / to, worked hours, distance, rate per km, odometer readings and photos); register shows km and amount | PARTIAL → built (XO-2: Mileage section (from, to, hours worked, distance, measured distance and its source, rate per km, odometer readings; photos in Files)) | XO-2 (#17) |
| 15 | "File is not available." / receipt files | Receipt image | Files column with signed links | HAVE | — |
| 16 | "Full detail could not be loaded. Showing available information." | Error text | — | N-A | UI message |
| 17 | Card → full receipt view (`app-receipt-view-form` parts: tax details, similar receipts, all attachments, history, attendance, trip details, payment details) | One receipt in full | No receipt page; "Change" opens the form for the owner on undecided receipts | MISSING → built (XO-2: read-only receipt page `ViewExpenseReceipt` (every captured field, custom fields, tax lines, mileage, checks with the receipts it resembles, report and its payment, trip, files through the signed route, history) for the owner or HR in scope; no actions) | XO-2: read-only receipt page (all captured fields, custom fields, tax lines, flags with the duplicate's number, report, trip, payment status of the report, history) |
| 18 | Filter "Receipt status": Pending / Suspected / Rejected / Approved | Find receipts by decision, incl. suspected | Status filter (unreported, in a report, approved, partly approved, rejected, expired, withdrawn); flags shown, not filterable | PARTIAL → built (XO-2: "Checks" filter (any of over the limit, suspected duplicate, invoice already claimed, distance edited) and the "Suspected duplicates" / "Over the limit" views) | XO-2: "Checks" filter (over the limit, suspected duplicate, invoice already claimed, distance edited) |
| 19 | Filter "Expense type" (Amount / Mileage) | Type | — (the dataset has type) | MISSING → built (XO-2: "Expense type" filter) | XO-2 filter on `type` |
| 20 | Filter "Payment status" | Paid / unpaid via the report | — | MISSING → built (XO-2: "Payment status" filter (paid, scheduled for payroll, waiting for payment, nothing to pay) through the report, as `ExpenseReport::paymentStatus()` decides it; "Payment" column) | XO-2 filter through the report's payment status |
| 21 | Filter "Select date" (range) | Expense date range | — | MISSING → built (XO-2: "Expense date" range) | XO-2 date-range filter |
| 22 | Filter "Project" | Project | — | MISSING | Blocked (PM-10) |
| 23 | Chips "Receipt No :", "Expense date :", "Expense category :", "Amount :", "Payment status :", "Receipt status :", CLEAR ALL | Applied filters | Filament filter indicators | HAVE | — |
| 24 | Attendance of the expense day (`app-emp-punches-details-flyer`: punches, shift, leave, overtime, trips, travel, expense of the day) | Check the claim against attendance | Attendance rule checked at capture (`ExpenseAttendance`); no day view from the receipt | MISSING | RQ-3 day drawer (matrix 20 §20.1.12 #13) opened from the receipt page |
| 25 | (PeopleOS) "Exceptional" filter; "Change" and "Withdraw" for the owner on unreported receipts; "Add expense for an employee" | — | `ExpenseReceiptResource`, `ListExpenseReceipts` | HAVE | Extra in PeopleOS |

### 26.4.2 Expense reports register (`/app/processes/search/expensereceipt/search`, `expensereport_812`; `app-expense-receipt-search` → `app-report-card`, `app-request-workflow-flyer`; API `expense/report/Admin/Search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of every report in data access | HR report register | Expense & travel → Expense reports (in scope); navigation badge with reports waiting | HAVE | — |
| 2 | Report name | Title | Title | HAVE | — |
| 3 | Report code | Number | Number (code series `expense_report`) | HAVE | — |
| 4 | Expense duration date / "Period :" | Period covered | From / To | HAVE | — |
| 5 | Expense report creation date / "Created date :" | Created on | Not shown (submitted shown, toggleable) | PARTIAL | XO-3 column |
| 6 | "Expense Receipt code" (search) | Find the report holding a receipt | Number search only | MISSING | XO-3: search by a receipt number in the report |
| 7 | Report status (Pending / Rejected / Approved / Undo) | Status + filter | Status filter (draft, submitted, returned, approved, partly approved, rejected, paid, cancelled); "Undo" = cancelled | HAVE | — |
| 8 | Payment status / "Payment status :" (column and filter) | Paid, scheduled, unpaid | `paymentStatus()` on the report page and on Pay expenses; not a register column or filter | PARTIAL | XO-3 column + filter (waiting, scheduled for payroll, paid outside payroll, paid with payroll, paid in F&F) |
| 9 | Filters "Start date" / "End date" (period), "Created date" | Date filters | — | MISSING | XO-3 date-range filters |
| 10 | Project (filter, column) | Project | — | MISSING | Blocked (PM-10) |
| 11 | Bulk document download: "Employee(s) selected", "Document Type" (`selectedData`), note "In order to download documents in bulk, please apply appropriate filter" | Download the receipts (and cover letters) of the filtered reports at once | One file at a time from the lines; cover letter per report | MISSING | XO-3: bulk action "Download documents" (receipt files and / or cover letters of the selected reports as one ZIP, queued, signed link, audited per file; respects `expense.view` scope) |
| 12 | Claimed / approved / net payable on the card *(inferred from the payment summary)* | Amounts | Claimed, approved, net payable columns | HAVE | — |
| 13 | Card → report view (26.4.3); "View workflow" | Read the report | Report page with lines and approvals | HAVE | Depth in 26.4.3 |
| 14 | (PeopleOS) "Submit" for an editable report on the register; cancel and cover letter on the page | — | `ExpenseReportResource` | HAVE | Extra in PeopleOS |

### 26.4.3 Expense report detail and review (`app-receipt-view-form`, `app-report-approval-details`, `app-receipt-carousel-content`, `app-view-report-history`, `app-trip-details-drawer`)

The approver's inbox view is matrix 20 §20.2.2 #35 (HAVE); these rows rate the report as HR opens it from the register and the
review controls HROne shows inside it.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Summary: Report name, Expense duration, Total reported amount | Header | Report section: number, employee, title, status, from, to, submitted, claimed, approved, advances settled, net payable | HAVE | — |
| 2 | Summary: Project | Project | — | MISSING | Blocked (PM-10) |
| 3 | Summary: Travel code | Trip(s) the report covers | `expense_report_travel` kept; not shown | PARTIAL | XO-3: "Trips" entry linking each trip |
| 4 | Summary: Total requested distance (Kms), Total approved distance (Kms) | Mileage totals | — | MISSING | XO-3: two computed entries (sum of `distance_km`; approved distance = approved ÷ rate per line) |
| 5 | "Sort by" Date / Receipt no. / Type | Order the lines | Lines table, default order | PARTIAL | XO-3: sortable date, number, type columns |
| 6 | Line columns: Receipt no., Category, Receipt Date, Type, Distance (Km), Amount, Actions | Lines | Number, date, category, merchant, amount, claimable, approved, checks, files, approver note, status | PARTIAL | XO-3: type and distance columns |
| 7 | "Settings — Configure the visibility of receipt fields in the table. Select upto 5 fields …", "Add to table", "Limit exceeded" | Approver picks up to five extra fields | Toggleable merchant and approver-note columns | PARTIAL | XO-3: column picker over the receipt fields incl. custom fields (stored per user, ≤ 5) |
| 8 | "Click on each row to view details receipt no. wise"; carousel (Image view / Receipt details, Next / Previous) | Walk through receipts with their images | Files open in a new tab; no receipt detail | MISSING | XO-2 receipt page + XO-3 "next / previous receipt" within the report |
| 9 | Line decision: Approve / Reject, Remark | Decide each receipt | Approve each line up to its claimable amount (0 rejects), note per line; reject the part; return the report | HAVE | Open owner decision "return of a report discards other approvers' line decisions" (HROne-Setup-Parity-Report §8) applies, not re-raised |
| 10 | Line decision "Suspected" (setting `isShowSuspectButton`), "Suspected amount" | Approver parks a receipt as suspected | Duplicate suspicion is set by the system (`suspected_duplicate`) only; approvers approve or reject | MISSING | XO-3 (owner decision D-XO7): approver flag `suspected_by_approver` on a line (note required) — the part cannot be decided while a line is suspected, or the line is held out of the payment, as decided; setting `expense.review.suspect_button` default off |
| 11 | "Policy violation" | Shows what broke the policy | Checks: over the limit (capped), exceptional with reason, suspected duplicate, invoice already claimed, distance edited | HAVE | Open owner decision "over-limit claim without reason is capped (not refused)" applies, not re-raised |
| 12 | "Exceptional" | Exceptional receipt | Exceptional part and badge | HAVE | — |
| 13 | "Invalid trip — The distance was manually edited as the trip was not completed successfully." | Edited mileage | Flag "Distance edited" | HAVE | — |
| 14 | "Duplicity : View", "Similar Receipts" | Show the receipts it resembles | Score and flag; the similar receipt is not named | PARTIAL → built (XO-2: `expense_receipts.duplicate_matches` keeps the matched receipts (id, score, why) without changing the verdict; the receipt page names those the viewer may open (link) and counts the others; dataset field "Resembles receipts" (XO-3 brings it into the report review)) | XO-2: keep the matched receipt id(s) with the score and list them on the receipt page |
| 15 | "View on Map", trip details drawer (Start / End point, Address, Coordinate) | Route map | Typed places only | MISSING | Blocked (maps provider, EXT) |
| 16 | Tax details (Tax Type, GSTIN, Destination of supply, Tax Name, Amount) | Tax per receipt | Stored, not shown on the report | PARTIAL → built (XO-2: on the receipt page (the report review itself is XO-3)) | XO-2 receipt page |
| 17 | "View all attachments — You can click on the attachment to view it." | All files of the report | Files per line | PARTIAL | XO-3 "All files" panel (signed links) |
| 18 | Attendance "View details" per receipt | Day check | — | MISSING | RQ-3 day drawer |
| 19 | Payment details: Payment status, Total submitted receipts, Total paid amount | Payment summary | Payment section: payment status, scheduled for payroll on, paid on, reference (shown to the employee when `expense.payment.show_to_employee`) | HAVE | — |
| 20 | Payment details: Approved reimbursable amount, Approved non-reimbursable amount | Split of the approved total | `reimbursable_approved` kept; only total approved and net payable shown | PARTIAL | XO-3: both entries |
| 21 | Payment details: Rejected amount, Paid receipts | Rejected total; receipts paid | — | MISSING | XO-3: computed entries |
| 22 | Payment details: Payment mode, Cheque no. | How it was paid | Channel (outside payroll / with payroll / in the F&F) and reference | PARTIAL | XO-4 payment mode and cheque fields |
| 23 | Activity log (`app-view-report-history`) | Report history | Approvals table (decided by, note); audit kept, not shown | PARTIAL | XO-3: `AuditHistoryRelationManager` on the report |
| 24 | "Download" (report document) | Printable report | "Download cover letter" when `expense.report.cover_letter` is on | PARTIAL | XO-3: printable report summary (lines, approvals, payment) independent of the cover letter switch |
| 25 | Amount / Mileage tabs inside the review | Separate the two types | One lines table | PARTIAL | XO-3: type filter on the lines |
| 26 | Approver bulk edit of amounts (settings `isBulkEditAmount`, `isBulkEditMileage`) | Change several approved amounts at once | Per-line approved amounts in one dialog | HAVE | — |

### 26.4.4 Pay expenses (global action "Pay expenses", `expensepayment_818`, Action node "Expense payment" (Create); `app-expense-payment-grid-flyer` "Expense payment" → `app-expense-payment-grid`, `app-expense-pay-flyer`, `app-bulk-process-popup`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Grid of employees with approved, unpaid expenses | Payment desk | Pay expenses page (`expense.pay`): approved / partly approved reports with net payable, unpaid, one row per report | HAVE | — |
| 2 | One row per employee (all their payable reports together) | Pay an employee once | One row per report | PARTIAL | XO-4: "Group by employee" toggle (sum of the employee's payable reports; paying the row pays each report) |
| 3 | Columns: Employee code, Employee name | Who | Employee name (number links to the report) | PARTIAL | XO-4: employee code column |
| 4 | Column: Advance amount | Advances settled against the reports | "Advances settled" | HAVE | — |
| 5 | Column: Reimbursable amount | Reimbursable approved | "Approved amount" (total) | PARTIAL | XO-4: reimbursable approved column |
| 6 | Column: Non Reimbursable | Company-paid approved | — | MISSING | XO-4 column (information only; never paid to the employee) |
| 7 | Column: Payable amount | Net to pay | "To pay" (net payable) | HAVE | — |
| 8 | Search "Search by name or code or designation or email id" | Find an employee | Report number search | PARTIAL | WO-2 search |
| 9 | Filters: Expense period, Report code | Narrow by period or report | Number search | PARTIAL | XO-4 period filter |
| 10 | Filters: Organization, Location, Status (employee), Type (employee) | Organisation and employee filters | — | MISSING | XO-4 (WO-2 organisation filter group) |
| 11 | Filter "Pay status" | Paid / unpaid / scheduled | Page lists unpaid only; Payment column shows waiting / scheduled for payroll since … | PARTIAL | XO-4: "Paid" tab (paid reports with channel, date, reference) |
| 12 | Filter "Exceptional Report" | Exceptional parts only | — | MISSING | XO-4 filter (report has an exceptional part) |
| 13 | Filter "Project" | Project | — | MISSING | Blocked (PM-10) |
| 14 | Select, "Employees selected", "Pay" (bulk) | Pay many | Bulk "Pay the selected with payroll", "Record one payment for the selected" (refusals listed) | HAVE | — |
| 15 | Report detail from the grid (`app-receipt-view-form`) | Check before paying | Number links to the report page | HAVE | — |
| 16 | Pay flyer: Payment cycle — Payment month, Payment year | Choose the pay cycle that carries the payment | "Pay with payroll" = the next payroll run that calculates (from the scheduling date); no month choice | PARTIAL | XO-4 (owner decision D-XO1): optional pay month among open, not-finalized `PayCycle` months (`payment_scheduled_for`); `ExpenseReportInput` takes a report only in that month; blank = next payroll (today) |
| 17 | "By clicking PAY NOW you will be adding <amount> to <month> pay cycle. This action cannot be undone." | Confirmation | Confirmation "The next payroll repays this report (non-taxable) and marks it paid when it is finalized." | HAVE | Wording follows XO-4 month choice |
| 18 | Comments (`paymentRemarks`) | Payment note | — | MISSING | XO-4 note on payment (kept on the report) |
| 19 | Payment mode Cash / Bank / Cheque; Bank (`bankCode`); Cheque number, Cheque date | How an outside payment was made | "Record payment": reference + paid on | PARTIAL | XO-4: mode (bank transfer / cheque / cash), paying account (`CompanyBankAccount`, BP-13), cheque number and date; reference stays |
| 20 | "Duplicity : View", "Expense type", trip map in the flyer | Context | Report page | PARTIAL | 26.4.3 rows |
| 21 | Bulk progress popup | Long run | Notification with counts and refusals | HAVE | — |
| 22 | Payment step after final approval (`isFinalApprovalPaymentRequired`) | Reports wait on this desk | `expense.payment.step_required` (default on) | HAVE | Open owner decision "payment step on by default" applies, not re-raised |
| 23 | (PeopleOS) Refusal while a payroll run not yet finalized or an unpaid F&F carries the report; never one's own | — | `PayrollCommitments`, `ExpenseCommitments`, `ExpensePolicy` | HAVE | Extra in PeopleOS |

---

# Asset (panel "Asset", module 7)

## 26.5 Asset operations

### 26.5.1 Asset inventory register (`/app/processes/search/addhardwareinventory/search`, `inventory_986`, rights View, Edit; `app-add-inventory-search` → `app-hardware-inventory-card`, `app-asset-card`; APIs `Asset/InventoryStatus/1`, `Asset/Inventory/Search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of every inventory item | Asset register | Assets → Asset register (`asset.view` / `asset.manage` / `asset.assign`; employees with `asset.own` see their own as "My assets") | HAVE | — |
| 2 | Column "Asset name" | Name | Name (model under it) | HAVE | — |
| 3 | Column "Asset serial no." | Serial | Serial (searchable) | HAVE | — |
| 4 | Column "Product" | Product | Model shown under the name | HAVE | — |
| 5 | Column "Asset type" | Type | Category | HAVE | — |
| 6 | Column "Asset sub type" | Sub type | Filter only; not a column | PARTIAL | XO-8: toggleable sub type column |
| 7 | Column / filter "Managed by" ("Manage by") | Person who manages the asset | — | MISSING | XO-8: `assets.managed_by_employee_id` (nullable) with column, filter and search |
| 8 | Column "Assigned to" | Custodian | Custodian | HAVE | — |
| 9 | Column "Assigned on" | Assignment date | In custody history; not a column | PARTIAL | XO-8 column from the active assignment |
| 10 | Column "Return date" | Expected return of a temporary assignment | `asset_assignments.expected_return_on` kept; not a column | PARTIAL | XO-8 column |
| 11 | "ACTIVITY LOG" on each card | History | Movements, repairs, custody history, audit history on the asset page | HAVE | — |
| 12 | Filter "Status" with HROne statuses In Stock, In Use, In Repair, Missing, Retired | Status filter | Status filter: in stock, assigned, in repair, lost, retired | HAVE | — |
| 13 | Status "In Transit" | Moving between places | Status `in_transit` exists (and counts as reportable lost) but no action sets it | PARTIAL | XO-8: "Dispatch" / "Received at location" actions on Change location |
| 14 | Status "Reserved" | Held for someone or a joiner | — | MISSING | XO-8 (owner decision D-XO6): status `reserved` with "reserved for" (employee or asset request); assignable only to that person; default unused |
| 15 | Status "Sold" | Sold asset | Dispose with method "Sale", date, realised value, reference (status disposed) | HAVE | — |
| 16 | Filter "Search by asset name" | Name search | Table search (tag, name, serial) | HAVE | — |
| 17 | Filter asset type (multiple) | Type filter | Category filter | HAVE | — |
| 18 | Filter "Search by asset sub type name" | Sub type filter | Sub type filter | HAVE | — |
| 19 | Filter "Assignment type" (Permanent / Temporary) | Temporary assignments | — | MISSING | XO-8 filter (active assignment has `expected_return_on`) |
| 20 | Filter "Warranty expired" | Out of warranty | Warranty date column (red when past); no filter | PARTIAL | XO-8 ternary filter |
| 21 | Chips, CLEAR ALL, Apply | Filter chrome | Filter indicators | HAVE | — |
| 22 | Right "Edit" (Search → Asset inventory) | Change an item | Edit for `asset.manage` (reason on edit) | HAVE | — |
| 23 | (PeopleOS) Location, condition columns; custom fields | — | `AssetResource` | HAVE | Extra in PeopleOS |

### 26.5.2 Asset item view and activity log (`app-view-hardware-inventory`, `app-hi-form-review`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header: Product, Asset type, Serial No., Asset code | Identity | Status, condition, category, sub type, model, serial, custodian, location, owned by, purchase, vendor, warranty, notes, custom fields | HAVE | — |
| 2 | Product image | Picture of the product | Product documents (files) on the model; no image | PARTIAL | XO-8: optional image on the product (`asset_models.image_path`), shown on the asset |
| 3 | "View form" (all entered fields, review layout) | Read everything | Asset page infolist | HAVE | — |
| 4 | "Edit" | Change | Edit (asset.manage) | HAVE | — |
| 5 | "Note: The warranty of this asset has been expired" | Warning | Warranty date coloured red when past | HAVE | — |
| 6 | ACTIVITY LOG with filters "From date", "To date", "Activity type", Reset, Apply | Filtered history | Movements table (type, date, from / to) and audit history, no filters | PARTIAL | XO-8: date-range and movement-type filters on Movements |
| 7 | Invoice / Warranty / Other attachments in the review | Files of the asset | — (model documents only) | MISSING | 26.5.3 #19 |

### 26.5.3 Add asset (inventory) (global action "Add asset (inventory)", `addhardwareinventory_733`, Action node "Add hardware inventory" (Create); `app-add-hardware-inventory` stepper Inventory details · Invoice details · Other details · Summary; `app-hi-form-step-one/two/three`, `app-hi-form-review`, `app-add-more-hardware-inventory-flyer`, `app-add-vendor-flyer`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Stepper "Add Hardware" / "Add Inventory" with Next / Previous and a Summary step | Guided entry with review | "Receive asset": one form (Asset, Procurement, custom fields) | PARTIAL | XO-8: the form as a wizard (Asset · Purchase · Other · Review), following the owner's forms-as-wizards preference and the form wizard engine |
| 2 | Name (`assetName`) | Asset name | Name | HAVE | — |
| 3 | Tag | Asset tag | Asset tag (unique, fixed after creation) | HAVE | — |
| 4 | Asset Code (`assetCode`) — "Copy the asset code and add another digit or alphabet to it" | A second, coded identifier | Asset tag only; no generated code | PARTIAL | XO-8: optional auto-number from a code series type `asset` (P9 `NumberSeries`) filling the tag when blank |
| 5 | Serial no. | Serial | Serial (required when the type needs it; duplicate rule per sub type, AS-A) | HAVE | — |
| 6 | Product (`productId`) with Asset type and Asset sub type shown | Pick the product | Category, sub type, model selects (sub type from the model when blank) | HAVE | — |
| 7 | Description | Text | Notes | HAVE | — |
| 8 | Vendor (`vendorId`) + "+ Add vendor" (vendor master flyer: code, name, address, country, state, city, PIN, GSTIN, primary and secondary contacts, vendor fields 1–5) | Supplier from a master | Vendor as free text; no vendor master | MISSING | Vendor master is matrix 01 §1.33 (PR-01); XO-8 adds `assets.vendor_id` once it exists (text kept) |
| 9 | GST no. (`gServiceTNo`) | Supplier GSTIN on the invoice | — | MISSING | XO-8 `assets.supplier_gstin` (GSTIN validation of matrix 01 §1.2) |
| 10 | Invoice number | Invoice | Invoice number | HAVE | — |
| 11 | Invoice date | Invoice date | Purchase date | HAVE | One date covers invoice and acquisition (row 17) |
| 12 | Payment type (`modeCode`) | How the purchase was paid *(inferred)* | — | MISSING | XO-8 `assets.payment_mode` (select from `peopleos.assets.payment_modes`) |
| 13 | Currency (`currencyCode`) + Cost (per item) | Cost and its currency | Purchase cost (no currency) | PARTIAL | XO-8 `purchase_currency` + base amount through `CurrencyRates` |
| 14 | Other currency (`otherCurrencyCode`) + Other cost (`otherCost`) | Freight, installation *(inferred)* | — | MISSING | XO-8 `other_cost` (+ currency) |
| 15 | GST amount (`gServiceTAmount`) | Tax paid | — | MISSING | XO-8 `tax_amount` (as invoiced; no rate shipped) |
| 16 | Depreciation type (`depreciationType`) | Method for book value | Straight line over the product's (else the type's) life (`Assets::bookValue`) | PARTIAL | XO-8: `depreciation_method` (straight line default; written-down value with a rate entered by the tenant) — book value only, no accounting |
| 17 | Acquisition date | Date acquired | Purchase date | HAVE | — |
| 18 | Manufacturing date | Date made | — | MISSING | XO-8 `manufactured_on` |
| 19 | Upload files: Invoice / Warranty / Other ("Max file size is 500 kb. You can upload up to 5 / 10 files") | Asset documents | Product (model) documents only | MISSING | XO-8 `assets.documents` (kinds invoice / warranty / other) on the private disk, `ParityFileController` type `asset`, audited download |
| 20 | Warranty period (months) + Warranty expiry | Warranty | Warranty until (date) | HAVE | Months can be typed as a helper that fills the date (XO-8, no new column) |
| 21 | Last audit date | Physical verification date | — | MISSING | XO-8 `last_audited_on` + "Record audit" action (also in bulk) |
| 22 | End of life | Planned retirement | Life months on the product / type (book value); no date | PARTIAL | XO-8 computed "End of life" entry (purchase date + life) with an override date |
| 23 | "This asset is owned by" (`ownByOU`, organisation-unit options) + Sub Branch | Owning unit | Owned by (company) + Location | PARTIAL | XO-8: owning unit level select (company / business unit / department / location) — reuse organisation pickers |
| 24 | Managed by (`manageBy`) | Responsible person | — | MISSING | 26.5.1 #7 |
| 25 | Status at entry (`statusId`) | Enter an item as in use, in repair … | Received as in stock; then actions | PARTIAL | XO-8: status at receipt limited to in stock / reserved / in repair; other statuses come only from actions (history stays true) |
| 26 | Custom asset fields `assetField1` … `assetField8` | Tenant fields | Custom fields of entity `asset` | HAVE | — |
| 27 | Assigned to, Assignment type Permanent / Temporary, Assigned on, Return date at entry | Receive and hand over in one step | "Assign" after receiving (employee, date, condition, expected return, note) | PARTIAL | XO-8: optional "Hand over now" step in the wizard calling `Assets::assign` |
| 28 | Sold date, Sold amount | Disposal by sale at entry *(inferred: for historic records)* | Dispose (method sale, date, value) after receiving | PARTIAL | Historic disposals come with the asset import (XO-11) |
| 29 | "+ Add more hardware inventory" (rows: Serial no., Status, Manufacturing date, asset fields, Asset Code, Sold date / amount, Assigned to, Assigned on, Assignment type, Return date), "Please specify the inventory count", "Remove all" | Receive N identical items with their serials in one go | One asset per form | MISSING | XO-8: "Receive several" (count ≤ 100, one row per item: tag, serial, optional custodian), one transaction, per-item audit |
| 30 | Summary / review (`app-hi-form-review`) with Activity log | Check before saving | — | MISSING | XO-8 wizard review step (#1) |

### 26.5.4 Employee Assets (`/app/processes/search/asset/search`, `assetsearch_1029`, Search node "Asset by employee" (View); `app-employee-asset-search` → `app-employee-asset-card`, `app-employee-asset-flyer` → `app-emp-assets`, `app-asset-card`; API `Asset/GetEmployeeAssetDetails`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | One card per employee holding assets, "Count of assets :", SHOW MORE | Who holds what, at a glance | No register by employee; Employee 360 → Assets per person; asset register has no custodian filter | MISSING | XO-9: "Assets by employee" register (employees with active assignments: code, name, department, count, unacknowledged count, oldest temporary return date) |
| 2 | Search by employee | Find a holder | — | MISSING | XO-9 (WO-2 search) |
| 3 | Flyer "Assigned (n)" listing the employee's assets | The person's assets | Employee 360 → Assets: tag, asset, category, serial, assigned on, returned on, acknowledged, status, Open | HAVE | — |
| 4 | Asset card fields: Code, Serial no., Product, Asset sub type, Period, Managed by, Assigned to, Return date, "Returned" / "Not returned" | Item details in the flyer | Tag, serial, asset, category, dates, status | PARTIAL | XO-9: period (permanent / temporary), expected return, sub type, product; managed by after XO-8 |
| 5 | "Return" / "Undo" on the card | Take an asset back from the list, or undo | "Take back" on the asset page (condition, note); no undo | PARTIAL | XO-9: "Take back" from the Employee 360 row; undo is N-A (a return is a recorded movement; a wrong return is corrected by assigning again, audited) |
| 6 | Custodian filter on the inventory *(implied by the page)* | — | — | MISSING | XO-9: custodian filter on the asset register |

### 26.5.5 Asset acknowledgement (`/app/processes/search/assetAcknowledgement/search`, `assetacknowledgement_2239`; `app-asset-acknowledgement`, `app-acknowledge-asset`; API `Asset/GetAssetAcknowledgementDetails`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register "ASSET ACKNOWLEDGEMENT" of handed-over assets and their acknowledgement | Who has / has not acknowledged | No register; `acknowledged_at` on custody history and Employee 360; `Assets::pendingAcknowledgements()`; My-tasks item and reminders | PARTIAL | XO-9: "Asset acknowledgements" register over active assignments needing acknowledgement (sub type `acknowledgement_required`) |
| 2 | Columns: Product, Asset type, Asset sub type, Serial No., Asset code, Product image | Asset identity | In custody history (tag, employee, dates) | PARTIAL | XO-9 columns |
| 3 | Columns: Assigned on, "Acknowledged on :" / Acknowledged date | Dates | Assigned on, acknowledged at | HAVE | — |
| 4 | Column "Request Type :" | New or return the acknowledgement relates to *(inferred)* | Assignments from requests keep the request; not shown | PARTIAL | XO-9 column (source request number) |
| 5 | Filter "Status" (acknowledged / pending) | Pending ones | — | MISSING | XO-9 filter |
| 6 | Filter asset type (multiple), "Search by asset name" | Narrow | — | MISSING | XO-9 filters |
| 7 | "ACTIONS" (page actions) *(inferred: remind / export)* | Act on pending acknowledgements | Automatic reminders every `asset.acknowledgement.reminder_days` (`peopleos:assets:acknowledgement-reminders`, 08:30) | PARTIAL | XO-9 bulk "Remind now" (same event `asset.acknowledgement_due`, stamped) |
| 8 | Acknowledgement task with signature ("I hereby acknowledge …", draw / upload signature, "Are you sure you want to sign this asset acknowledgement?") | Signed acknowledgement | "Acknowledge receipt" (click) | PARTIAL | Matrix 20 §20.2.2 #32 (signature with the letter studio's e-sign decision) |
| 9 | Which assets need acknowledgement (sub type "Do you want to acknowledge employee when asset is received?") | Per sub type | `asset_subtypes.acknowledgement_required` (AS-A) | HAVE | — |

### 26.5.6 Asset request register and fulfilment (`/app/processes/search/assetrequest/search`, `assetrequest_1028`; `app-asset-request-search` → `app-asset-request-card`, `app-asset-view-form-flyer` → `app-product-request`; owner task `app-asset-task-owner-details`, `app-assign-asset-flyer`, `app-return-asset-flyer`, `app-view-product-details-flyer`; approval `app-asset-details`; API `Asset/GetEmployeeRequestDetails`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of every asset request in data access | HR request register | Assets → Asset requests (the asset team's queue, due and overdue) | HAVE | — |
| 2 | Card "Requested date :" / column "Request date" | Raised on | Not shown | PARTIAL | XO-10 column |
| 3 | Card "Request type :" / column "Request type", filter "Request type" | New / return / both | Kind (new, replacement, return) column and filter | HAVE | "Both" is matrix 20 §20.1.17 #1 (RQ-7) |
| 4 | Card "Approved by :", "Approved date :" | Decision | Decision note on the page; decider and date kept, not shown | PARTIAL | XO-10 columns |
| 5 | Column / filter "Status" | Status | Status (requested, approved, rejected, fulfilled, cancelled) column and filter | HAVE | — |
| 6 | Filters "Requested date", "Approved date" (ranges) | Date filters | — | MISSING | XO-10 filters |
| 7 | Card → request view (Request Type, New product details — Description, Comment, asset rows Product / Asset type / Asset sub type / Period / Return date; products to return) | Read the request | Request page: kind, asset, asset to return or replace, handed over, raised by, needed by, due on, owner role, reason, notes | HAVE | Several lines and period are RQ-7 (matrix 20 §20.1.17 #4, #5) |
| 8 | Approval view (`app-asset-details`: request type, comments, remarks, return assets, new product details, period, return date) | Approver's view | Approval Center `asset_request` / `asset_return_request`; approve / reject on the register | HAVE | Matrix 20 §20.2.2 #33 |
| 9 | "Manage asset request" (owner task): Request type, Requested / New / Return asset(s), Comments, "No new product", "No requested product", Product, Asset type, Asset sub type, Period, Return date, ACTIONS | Fulfil the approved request | "Fulfil" (asset picker filtered to the request's type / sub type / product, condition of an asset taken back, note) or "Decline" (reason), by the type's owner role or the asset team; due on the type's fulfilment days | HAVE | One line per request until RQ-7 |
| 10 | "View product details" flyer (Product details: product, type, sub type, period, return date; Done) | See what is asked | Request page | HAVE | — |
| 11 | ASSIGN flyer (`app-assign-asset-flyer`): pick asset(s), "(Please select one)", Asset details, Period Permanent / Temporary, Return date | Hand over with the period | Fulfil hands over one asset; no period or return date (assignment `expected_return_on` left empty) | PARTIAL | XO-10: period and return date on fulfil (default from the request line after RQ-7) → `expected_return_on` |
| 12 | RETURN in the flyer "(Select multiple products to return)" | Take several back at once | One asset per return request | PARTIAL | RQ-7 multi-line returns; XO-10 fulfils all lines in one step |
| 13 | Return flyer (`app-return-asset-flyer`): Comment, Attachment (drop files), "This asset is no more assigned to this user" | Record the return with a note and a file | Take back / fulfil return: condition, note; no file | PARTIAL | XO-10: optional return photo / document on the movement (private disk) |
| 14 | Owner task notes: "This task has been generated to acquire the expired product" / "… the temporarily assigned asset" | System return tasks when a temporary assignment or a product's life ends | No task at the expected return date or end of life | MISSING | XO-10: daily job raising a return request (source `system`, idempotent per assignment) when `expected_return_on` is reached (`asset.return.temporary_due_days`, default off) or the asset reaches end of life (`asset.return.end_of_life`, default off) |
| 15 | Request-side and HR-side "Asset request" action (Action node, rights Create) | Raise for an employee | "Request for an employee" (asset team in scope; manager for reports) | HAVE | — |
| 16 | Inbox Insights "Manage request" | Open fulfilment tasks | Asset requests list (approved, due, overdue; owner role) | PARTIAL | 26.9 |
| 17 | (PeopleOS) Joiner and exit requests raised automatically (asset policy; owner decision 2026-10-29), source column, needed by / due on, overdue colour | — | `AssetRequestTriggers` | HAVE | Extra in PeopleOS |

### 26.5.7 Imports → Asset [4] ("Imports ▾" on Asset inventory — import 79 — and Asset request — imports 104, 106, 107)

Template columns were not captured; each import is rated from its name.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Import "Assets" | Load inventory items (with assignments) from a sheet | No asset upload | MISSING | XO-11: `AssetImports` (RowImports): tag, name, type code, sub type code, product, serial, status (in stock / assigned / retired / disposed), custodian code + assigned on + expected return, owned by, location, purchase date / cost / currency, vendor, invoice, warranty until, managed by, custom fields; through `Assets` services (receive, assign) so history and audit are written |
| 2 | Import "Products" | Load products | No upload (Asset models screen) | MISSING | XO-11: `AssetModelImports`: type code, sub type code, manufacturer, name, description, life months, deployment type, licence basis |
| 3 | Import "Asset types" | Load types | No upload | MISSING | XO-11: `AssetCategoryImports`: code, name, requires serial, IT asset, life months, owner role, fulfilment days |
| 4 | Import "Asset sub types" | Load sub types | No upload | MISSING | XO-11: `AssetSubtypeImports`: type code, code, name, allow duplicate serial, acknowledgement required |
| 5 | Import wizard, logs and approval | Shared upload flow | Staged pipeline with P2 governance (`ImportGovernance`, `RecentUploads`) | HAVE | Asset types register with `RowImportTypes` (screen `assets`, permission `asset.manage`) |

---

# Helpdesk (panel "Helpdesk", module 6)

## 26.6 Tickets

### 26.6.1 Tickets register (`/app/processes/search/helpdesk/search`, `helpdesk_560`, rights View, Ticket reopen; `app-help-desk-search` → `app-help-desk-search-card`, `app-help-desk-request-flyer`; API `helpdesk/ticket/Request/Search`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of every ticket in data access | HR ticket register | Service Desk → HR queue (`CaseAccess::visible`: organisation scope, own, explicit access, manager-visible services); navigation badge with open unassigned / mine | HAVE | — |
| 2 | Column "Ticked id" / chip "Ticket id :" | Number | Number (searchable, sortable) | HAVE | — |
| 3 | Column "Description" | What the ticket is about | Service / category column; subject and details on the page | PARTIAL | XO-12: "Subject" column (limit 60, never for restricted cases) |
| 4 | Column / chip "Assigned to" | Resolver | "Agent" column; filters "Assigned to me", "Unassigned", "Team" | PARTIAL | XO-12: agent filter (pick any agent in scope) |
| 5 | Column "Requested by" / filter "Request by" (employee search) | Raiser | "Employee" column (searchable by name); "Raised by" on the page | HAVE | — |
| 6 | Column "Requested on" / filter "Date" / chip "Requested date :" | Raised on | "Raised" column; "raised" date-range filter | HAVE | — |
| 7 | Filter "Ticket code" | Number | Table search | HAVE | — |
| 8 | Status values: Open, Inprogress, Hold, Closed | Ticket lifecycle | Submitted / acknowledged / assigned (open), in progress, waiting for employee / waiting for HR (hold, SLA paused per `sla_pause_statuses`), resolved, closed | HAVE | — |
| 9 | Status "Reopen" | Reopened tickets stand out | Reopen moves a resolved / closed case back to in progress (transition logged); no reopened marker | PARTIAL | XO-12: `reopened_count` + "Reopened" filter / badge |
| 10 | Status "Undo" | Withdrawn by the requester | Cancelled (requester or agent, reason) | HAVE | — |
| 11 | Filter "Status" | Status filter | Status filter (multiple, default open) | HAVE | — |
| 12 | Right "Ticket reopen" (Search → Helpdesk) | HR may reopen | Agents who can work the case reopen resolved / closed cases (reason) | HAVE | — |
| 13 | Card → ticket view (26.6.2) | Work a ticket | Case page | HAVE | — |
| 14 | (PeopleOS) Priority, team, SLA due columns; filters service, category, priority, team, SLA at risk, overdue; bulk Assign / Move status / Escalate (`servicedesk.bulk`) | — | `TicketResource`, `ServiceDeskBulk` | HAVE | Extra in PeopleOS |

### 26.6.2 Ticket workspace (`app-help-desk-detail`, `app-help-desk-view-form`, `app-update-ticket-flyer`, `app-feedback`, `app-feedback-message`, `app-help-desk-approval`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header: Ticket id, Request date, Status, Priority, Category, Subcategory, Assigned to, Description, "View details" | Ticket facts | Case header: number, subject, status, priority, service (sub-category) and category, confidentiality, employee, raised by, source, submitted, details; "Assignment and SLA" for agents (team, agent, owner, escalation level, first response due / done, resolution due, SLA) | HAVE | — |
| 2 | Attachment ("view") | Files of the ticket | Comment attachments (signed, 15 min); service requests' attachment; "Ask HR" has none | PARTIAL | Initial attachment on "Ask HR" is matrix 20 §20.1.10 #5 (RQ-7) |
| 3 | Due Date | Resolution due | Resolution due (business hours, pauses) | HAVE | — |
| 4 | Escalation Date | When it escalates (TAT) | Escalation level; warnings at `warn_percent`, escalation on breach | PARTIAL | Two-stage SLA (due day + TAT, reassign at TAT) is `setup/13` HD-1 (SV-10) |
| 5 | Badges "MISSED TO RESPOND", "OVERDUE", "DUE : TODAY", "DUE : TOMORROW" | Urgency at a glance | SLA text ("x service hours left" / "Breached by …" / paused), red due date, "SLA at risk" and "Overdue" filters | PARTIAL | XO-12: due-day badge (today / tomorrow / overdue) and "first response missed" badge from `first_response_due_at` |
| 6 | Update flyer: Status (`label:Status`) | Move the ticket | Acknowledge, Take it, Start work, Wait for employee, Wait for HR (internal reason), Resolve, Close, Cancel | HAVE | — |
| 7 | Update flyer: Priority | Change priority | Priority fixed at intake (service or "Ask HR" choice) | MISSING | XO-12 (owner decision D-XO5): "Change priority" (reason; transition logged; SLA due recomputed from the new priority's target from creation, never earlier than now + warn margin) |
| 8 | Update flyer: "Delegate" | Reassign to another resolver | "Assign" (eligible agents in the employee's scope, optional team, reason); bulk assign | HAVE | — |
| 9 | Update flyer: Category / Subcategory | Re-categorise a misfiled ticket | Category and service fixed at intake | MISSING | XO-12: "Re-categorise" (agent; new category / service the requester could have used; resolver rules re-run, SLA replaced, audited; service-form data kept read-only) |
| 10 | "ADD CANNED MESSAGE", "Canned Messages" picker (`app-feedback-message`: Search a message, No messages, Select a message), Subject, Message | Reuse standard replies | Reply composed each time; knowledge article can be linked at resolution | MISSING | XO-12: `service_desk_canned_replies` (title, body with `{employee.first_name}` placeholders, category scope, active) managed under Service Desk (`servicedesk.manage`); "Insert reply" in the Reply dialog |
| 11 | Comments (`remarks`) | Note with the update | Reply with visibility "Visible to the employee", "Internal HR note", "Restricted" | HAVE | — |
| 12 | Internal notes | Notes the employee never sees | Internal / restricted comment visibility, filtered in SQL | HAVE | HROne has no separate internal-note control; PeopleOS exceeds it |
| 13 | RCA, "RCA Details" | Root cause on closing | Root cause code + analysis at "Resolve" (required when the category says, HD-A); shown only to people who work the case | HAVE | — |
| 14 | Close: "Are you sure you want to close the ticket?", RCA, Comments, "YES, CLOSE" | Close with RCA | Resolve (resolution, root cause, knowledge article) then Close (agent or requester); auto-close after `auto_close_days` | HAVE | — |
| 15 | Reopen: "Do you want to reopen this ticket?", "Reopen Ticket" | Reopen | Reopen with reason (requester or agent) | HAVE | — |
| 16 | Undo: "Do you want to rollback this ticket?", "Undo" | Requester withdraws the ticket | "Cancel request" (reason) | HAVE | — |
| 17 | Satisfaction popup (`app-feedback`: "Please rate your experience with … for …", Comments, max 500) | Requester rates the help | "Close & rate" (1–5 and comment; required with `servicedesk.feedback_required`; off per category `feedback_enabled`) | HAVE | — |
| 18 | "UPDATE" | Save the update | Each action saves on its own | HAVE | — |
| 19 | Ticket merge | — | HROne shows no merge control; PeopleOS has none | N-A | Not in HROne |
| 20 | SLA policy per priority, intimation at the due day, reassignment at TAT | Service levels | SLA policies (first response and resolution targets per priority, business hours, pauses, warning, escalation role and levels), resolver rules | PARTIAL | `setup/13` HD-1, HD-2 (SV-10) |
| 21 | Resolver inbox view (`app-help-desk-approval`: close with RCA, reopen) | Work from the inbox | My tasks (`ServiceDeskTaskSource`), HR queue | HAVE | Matrix 20 §20.2.2 #38 |
| 22 | (PeopleOS) Confidential cases with grant access, domain changes ("Apply the change", "Restart approval"), knowledge link, transitions log | — | `ServiceDeskActions::forTicket` | HAVE | Extra in PeopleOS |

---

# Cross-module nodes

## 26.7 HR Desk → Action → Expense [5] / Asset [2] / Helpdesk [1] and the module global actions (`globalAction`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Action → Expense → Expense (Create) = global "Add expense" (`expense_798`) | HR files expenses for an employee | "Add expense for an employee" (`expense.approve` in scope, never one's own) on Expense receipts; no report for an employee (New expense report is own only) | PARTIAL | XO-5: "New report for an employee" (pick the employee's unreported receipts; submit on behalf exempt from windows, as the service already allows) |
| 2 | Action → Expense → Expense advance (Create) = global "Advance request" (`expenseadvance_496`) | HR requests an advance for an employee | `ExpenseAdvances::request` accepts an actor filing on behalf (`ExpensePolicy::onBehalf`); the screen offers "Request an advance" for oneself only | PARTIAL | XO-5: "Request an advance for an employee" header action |
| 3 | Action → Expense → Expense payment (Create) = global "Pay expenses" (`expensepayment_818`) | Payment desk | Pay expenses page | PARTIAL | 26.4.4 (XO-4) |
| 4 | Action → Expense → Travel (Create) = global "Travel request" (`travel_1016`) | HR raises a trip for an employee | "Request travel for an employee" (backdating limit exempt on behalf) | HAVE | — |
| 5 | Action → Expense → Expense recovery (View) = global "Recover advance" (`expenseadvancerecovery_1336`) | Recovery desk | — | MISSING | 26.3.4 (XO-6) |
| 6 | Action → Asset → Add hardware inventory (Create) = global "Add asset (inventory)" (`addhardwareinventory_733`) | Receive inventory | "Receive asset" | PARTIAL | 26.5.3 (XO-8) |
| 7 | Action → Asset → Asset request (Create) = global "Asset request" (`assetrequest_1028`) | HR raises an asset request for an employee | "Request for an employee" on Asset requests | HAVE | — |
| 8 | Action → Helpdesk → Helpdesk ticket (Request on behalf) = global "Add ticket" (`helpdeskticket_376`) | HR raises a ticket for an employee | "Ask HR" with "For employee" (HR), service "Request" for an employee; source "Raised by HR"; helpdesk access policy does not limit HR | HAVE | — |

## 26.8 Reports — panel Reports pages, HR Desk → Reports → Expense [12] / Asset [3] / Helpdesk [2], Team → Reports → Expense [12]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Expense → Reports page (`openReports`, module 5): tabs Favourite, All, Expense report, Expense advance, Travel plan, History; each report with ".xlsx", description, Download, Open | Module report centre with process tabs | Report library category "Expense & travel"; CSV export; no process tabs or favourites | PARTIAL | Matrix 21 §21.2.1 (AN-16 process grouping, favourites, xlsx) |
| 2 | Asset → Reports page (module 7): tabs Favourite, All, Asset, Asset inventory, Asset acknowledgement, History | Same for assets | Category "Assets & service desk" | PARTIAL | Matrix 21 §21.2.1 |
| 3 | Helpdesk → Reports page (module 6): tabs Favourite, All, Helpdesk, History | Same for helpdesk | Category "Assets & service desk" | PARTIAL | Matrix 21 §21.2.1 |
| 4 | Reports → Expense [12]: Accommodation details, Expense advance, Expense advance recovery register, Expense live trip, Expense payment register, Expense receipt by amount, Expense receipt by mileage, Expense receipt details, Expense report details, Outstanding expense advance, Travel details, Travel summary | HROne's expense reports | All twelve as templates (EX-G, EX-F) | HAVE | Matrix 21 §21.2.2 "Expense (12)" |
| 5 | Reports → Asset → Hardware inventory report | Inventory by type | `assets_by_category`, `assets_assigned` | HAVE | Matrix 21 §21.2.2 Asset #3 |
| 6 | Reports → Asset → Asset history report | Full history | Movements recorded; no history dataset | PARTIAL | Matrix 21 AN-21 (`asset_history`) |
| 7 | Reports → Asset → Asset acknowledgement report | Acknowledgement status | `asset_assignments.acknowledged_at` and reminders exist (AS-A); no dataset or template | PARTIAL | **Correction to matrix 21 §21.2.2 Asset #1** (rated MISSING because "AssetAssignment has no acknowledgement"): the column exists since AS-A; only the dataset `asset_assignments` and template `asset_acknowledgements` remain (AN-21) |
| 8 | Reports → Helpdesk → Ticket details by requestee, Ticket details by resolver | Tickets with requester / resolver details | `servicedesk` dataset without requester org fields or resolver details | PARTIAL | Matrix 21 §21.2.2 Helpdesk #1–#2 (AN-23) |
| 9 | Team → Reports → Expense [12] (the same twelve, limited to the manager's team) | Managers run expense reports on their team | Templates exist; managers hold `expense.approve` but no analytics permission, so no team reports | MISSING | Matrix 20 §20.3.4 (RQ-11: `team_safe` templates under the manager's scope — mark the twelve expense templates `team_safe`) |
| 10 | Panel "Reports" "Download" (background, encrypted) and "Open" (report viewer, One AI analysis) | Output options | CSV export, schedules | PARTIAL | Matrix 21 §21.2.1 |

## 26.9 HR Desk → Inbox Insights → Expense [8] / Helpdesk [1] / Asset [2]

The Inbox Insights page (organisation-wide counts of open tasks by module and task type, reminders, archive) is MISSING
(matrix 21 §21.3, AN-30). Each node below is a task type whose items PeopleOS already produces; what is missing is the
organisation-wide count, so each is PARTIAL with AN-30 registering the kind under its module.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Expense → Expense advance (View) | Open advance approvals | Approval Center `expense_advance`; workflow tasks on `expense.advance_requested` | PARTIAL | AN-30 kind (module Expense) |
| 2 | Expense → Dispense advance (View) | Advances waiting to be paid | Dispense advances page (approved, waiting); not a task | PARTIAL | AN-30 count from approved advances; XO-5 navigation badge on Dispense advances |
| 3 | Expense → Expense (View) | Open report approvals | Approval Center `expense_report` (parts) | PARTIAL | AN-30 kind |
| 4 | Expense → Exceptional expense (View) | Open exceptional approvals | Approval Center `expense_exceptional`; trigger `expense.exceptional_submitted` | PARTIAL | AN-30 kind |
| 5 | Expense → Travel (View) | Open trip approvals | Approval Center `travel_request`, `travel_request_part` | PARTIAL | AN-30 kind |
| 6 | Expense → Travel cancel (View) | Open trip cancellations | Approval Center `travel_cancellation` | PARTIAL | AN-30 kind |
| 7 | Expense → Book travel ticket (View) | Open booking tasks | Travel desk booking requests (tickets) in the HR queue | PARTIAL | AN-30 count from open booking requests (category purpose travel / accommodation booking) |
| 8 | Expense → Expense advance cancel (View) | Open advance cancellations | Approval Center `expense_advance_cancellation` | PARTIAL | AN-30 kind |
| 9 | Helpdesk → Helpdesk (View) | Open tickets per resolver | HR queue (badge: open unassigned or mine), service analytics | PARTIAL | AN-30 kind (open tickets by team / agent) |
| 10 | Asset → Asset request (View) | Open asset-request approvals | Approval Center `asset_request`, `asset_return_request` | PARTIAL | AN-30 kind |
| 11 | Asset → Manage request (View) | Open fulfilment tasks | Asset requests list (approved, due / overdue, owner role) | PARTIAL | AN-30 count of approved, unfulfilled requests |

## 26.10 HR Desk → Analytics → Expense [5] / Asset [2], dashboards and module schedulers

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Expense → Total expense advance outstanding today | KPI | Metric `expense_advances_outstanding` (`expense.view`) | HAVE | — |
| 2 | Expense → Total reimbursable expense | KPI | Approved amounts; no reimbursable split metric | PARTIAL | Matrix 21 AN-6 (`expense_month_reimbursable`) |
| 3 | Expense → Total non reimbursable expense | KPI | — | PARTIAL | Matrix 21 AN-6 (`expense_month_company_paid`) |
| 4 | Expense → Travel ticket to be booked | Open journey bookings | Dataset `travel_request_legs` (booking status, who books); no KPI | PARTIAL | XO-13: metric `travel_tickets_to_book` (company journeys pending / booking on approved trips) |
| 5 | Expense → Accommodation to be booked | Open stay bookings | Same dataset; no KPI | PARTIAL | XO-13: metric `accommodation_to_book` |
| 6 | Asset → Asset inventory by status | Chart | Dataset `assets` has status; template groups by category only | PARTIAL | XO-13: template `assets_by_status` (+ widget) |
| 7 | Asset → Asset inventory by asset type | Chart | Template `assets_by_category` (bar) | HAVE | — |
| 8 | Analytics → Dashboards → Expense (View) | Expense dashboard | "Expense overview" (EX-G) | PARTIAL | Matrix 21 §21.1.5 (AN-6) |
| 9 | Analytics → Dashboards → Helpdesk (View) | Helpdesk dashboard | Service analytics page | PARTIAL | Matrix 21 §21.1.6 (AN-7) |
| 10 | Scheduler "Expense dashboard" / "Helpdesk dashboard" (daily refresh) | Pre-computed dashboards | Computed live | N-A | Live data, no refresh job needed |
| 11 | Scheduler "Send Notification of intimation task and escalate task relate to helpdesk" (daily) | Intimation and escalation of tickets | `peopleos:service-desk:process` hourly (SLA warnings, breach escalation, waiting reminders, auto-close) | HAVE | — |

## 26.11 Other page-access nodes of these modules (pointer rows)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Request → New request → Expense (Create, Undo request), Expense advance (Create, Cancel request), Helpdesk ticket (Create, Ticket reopen, Undo), Travel (Create, Cancel request), Asset (Create) | Employee requests | See matrix 20 | PARTIAL | `20-requests-inbox-team.md` §20.1.10–20.1.12, §20.1.16, §20.1.17 |
| 1a | Request → New request → Start trip | Field trip tracked by check-ins (start / end point, coordinates; `app-employee-trips`) — a time-office feature, not a travel request | Location pings stored (TO-2 tracking); no trip view | PARTIAL | `22-my-space-self-profile.md` §22.14.19 (Trips); not an expense item |
| 2 | Team → People → Action → Expense (Expense advance, Expense, Travel), Asset (Asset request ×2), Helpdesk (Ticket request) | Manager acts for a team member | See matrix 20 | PARTIAL | §20.3.2 #3, #13, #14, #18, #40 |
| 3 | Team → People → Team profile → Asset (My assets, Asset request), Helpdesk (Helpdesk), Expense (Expense advance, Expense report, Travel) | Manager views a member's items | See matrix 20 | PARTIAL | §20.3.2 #3, #13, #14, #18, #27, #40 |
| 4 | Profile → Asset request, Expense advance, Expense receipt, Expense report, My assets, Ticket, Travel, Trips | Employee's own pages | See matrix 22 | PARTIAL | `22-my-space-self-profile.md` |
| 5 | Setup → Expense [3] (Expense management [6], Expense advance, Settings), Asset [3] (Asset management [4], Inventory, Settings), Helpdesk [2] (Helpdesk management [2], Settings) | Module setup | See setup matrices | PARTIAL | `setup/11` §11, §14, §15; `setup/13` §10, §13, §14 and nodes 183–240 of its cross-check |
| 6 | Setup → Automation → Workflow → Expense / Asset; Notification → Expense / Asset / Helpdesk; Task automation → Expense ("Update task owner"); Forms & templates → Letter → Expense | Module automation | See setup matrices | PARTIAL | `setup/02` §02.7, §02.10, §02.11, §03.5 and `setup/13` §3 (statuses corrected for expense and asset) |
| 7 | Access rights → CXO access (Expense, Helpdesk views), Calendar access (expense events) | Sharing and calendars | See matrix 01 / 21 | PARTIAL | `setup/01` §5.3–5.4, matrix 21 §21.1.1 (AN-1) |

---

## Counts

Rows of the tables in §26.1–§26.11 (the index §26.0 and the building blocks §0 are not counted). Pointer rows are
counted once with the status of the row they point to.

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 26.1 Panel and register chrome | 20 | 7 | 8 | 2 | 3 |
| 26.2 Travel (register, detail, travel desk, cancellations) | 64 | 20 | 25 | 18 | 1 |
| 26.3 Advances (requests, dispense, cancellations, recovery) | 37 | 10 | 15 | 11 | 1 |
| 26.4 Expense (receipts, reports, review, Pay expenses) | 88 | 32 | 31 | 24 | 1 |
| 26.5 Asset (inventory, item view, add inventory, by employee, acknowledgement, requests, imports) | 97 | 42 | 29 | 26 | 0 |
| 26.6 Helpdesk (tickets register, ticket workspace) | 36 | 25 | 7 | 3 | 1 |
| 26.7 Action nodes and global actions | 8 | 3 | 4 | 1 | 0 |
| 26.8 Reports (pointer rows + one correction) | 10 | 2 | 7 | 1 | 0 |
| 26.9 Inbox Insights | 11 | 0 | 11 | 0 | 0 |
| 26.10 Analytics tiles, dashboards, schedulers | 11 | 3 | 7 | 0 | 1 |
| 26.11 Other page-access nodes (pointer rows) | 8 | 0 | 8 | 0 | 0 |
| **Total** | **390** | **144** | **152** | **86** | **8** |

By module: Expense (26.2–26.4 and its share of 26.7–26.10) is mostly built at the level of records and money flows
(EX-A … EX-G); its gaps are desk depth — the travel desk, the recovery desk, payment details and review aids. Asset has
the most MISSING rows: inventory entry depth, the registers by employee and acknowledgement, and imports. Helpdesk is
close to parity; its gaps are ticket-editing controls (priority, category, canned replies) and the two-stage SLA already
tracked as SV-10.

**Correction to another matrix.** `21-analytics-reports-projects.md` §21.2.2 Asset #1 (Asset acknowledgement report) is
rated MISSING on the ground that `AssetAssignment` has no acknowledgement; `asset_assignments.acknowledged_at` and its
reminders exist since AS-A, so the row is PARTIAL (dataset and template only, AN-21). See §26.8 #7.

## Biggest gaps

1. **Travel desk workspace** (§26.2.3, 15 MISSING): no bookings register; a booking is a note on a leg, never an expense
   receipt, so company-booked travel cost is outside reports and limits; no booking date, commission, cancellation
   charge, invoice, tax or files; the desk cannot edit the itinerary or plan, add a leg, mark a leg not required or
   cancel one leg; no composed booking mail. (XO-7; D-XO3, D-XO4)
2. **Advance recovery desk** (§26.3.4): no per-employee recovery screen, no "recover in month X for amount Y" through
   payroll (only the policy's automatic date for the whole outstanding), out-of-system recovery lacks cheque / cash
   details, no bulk, no running employee ledger. (XO-6; D-XO2)
3. **Asset inventory entry** (§26.5.1–26.5.3): no bulk "receive N items with serials", no invoice / warranty files on an
   asset, no managed-by, GST, payment mode, cost currency, other cost, manufacturing or last-audit dates, no Reserved
   status, single long form instead of a wizard with review. (XO-8; D-XO6)
4. **Asset imports** (§26.5.7): no upload for products, asset types, sub types or assets — a tenant moving from HROne
   cannot load its inventory. (XO-11)
5. **Expense receipt page and register filters** (§26.4.1): no read-only receipt page (tax lines, mileage detail,
   history, the receipt a duplicate matches), no filters by type, payment status, date or checks. (XO-2)
6. **Expense report review aids** (§26.4.2–26.4.3): no approver "Suspected" decision, no distance totals,
   reimbursable / non-reimbursable / rejected split, column picker, receipt walk-through or bulk document download;
   payment status is not a register column or filter. (XO-3; D-XO7)
7. **Pay expenses** (§26.4.4): one row per report (not per employee), no chosen pay month, no payment mode (bank /
   cheque / cash) with paying account and cheque details, no organisation, period or exceptional filters. (XO-4; D-XO1)
8. **Assets by employee and acknowledgement registers** (§26.5.4–26.5.5): neither register exists; acknowledgement is
   visible only per asset or per employee; signed acknowledgement waits for the e-sign decision. (XO-9)
9. **Ticket editing** (§26.6.2): agents cannot change priority or re-categorise a misfiled ticket, and there are no
   canned replies or due-day badges; the two-stage SLA (TAT with reassignment) is SV-10. (XO-12; D-XO5)
10. **Advance dispense and on-behalf filing** (§26.3.2, §26.7): dispense pays the full approved amount with no note and
    no dispensed history tab; HR cannot request an advance or build a report for an employee from the screens (the
    services allow it). (XO-5; D-XO8)

Also open, owned elsewhere: Inbox Insights counts for the eleven expense / asset / helpdesk task kinds (AN-30), team
expense reports for managers (RQ-11), projects on trips, advances, receipts and reports (PM-10), maps distance and
receipt OCR (external providers).

## Build packages (ordered)

Every package is additive: new columns are nullable, new settings default to today's behaviour, money still moves only
through the payroll-input and settlement rails (tag `peopleos.payroll_inputs`, `peopleos.settlement_sources`) with the
"never twice" guards, and nobody decides, pays or recovers their own records. No statutory value is introduced.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **XO-1 Expense and asset register depth and presets** — requested / decided / "waiting with" / decided-by columns, date-range filters, presets "Travel cancellations" and "Advance cancellations" (refused cancellations kept: `cancel_refused_at` on trips and advances), panel order by process, "Report" link per register to its library template, Assets nav entries for XO-9 | 26.1 #1, #2, #4; 26.2.1 #2, #3, #6, #7, #9; 26.2.4 #1–#2; 26.3.1 #2, #3, #5; 26.3.3 #1–#2 | `TravelRequestResource`, `ExpenseAdvanceResource`, `TravelRequests::rejectCancellation`, `ExpenseAdvances::rejectCancellation`, migration (two timestamps), navigation config | WO-1, WO-2 (shared search and filters) | M |
| 2 | **XO-2 Expense receipt page and receipt register** — read-only receipt page (every field, custom fields, tax lines, mileage detail, flags naming the matched receipts, report, trip, payment status, audit history); columns invoice, reimbursable, updated; filters type, checks, payment status, date range; `ExpenseDuplicateDetector` keeps the matched receipt ids | 26.4.1 #6, #8–#10, #12–#14, #17–#21; 26.4.3 #14, #16 | `ExpenseReceiptResource` (+ `Pages/ViewExpenseReceipt`), `ExpenseDuplicateDetector`, migration `expense_receipts.duplicate_matches` (json), `ExpenseReceiptsDataset` (matched ids) | — | M |
| 3 | **XO-3 Expense report review and documents** — trips, distance totals, reimbursable / non-reimbursable / rejected split, sortable type and distance columns, column picker (≤ 5, per user), next / previous receipt, all-files panel, audit tab, printable summary; register: created column, receipt-number search, payment status column and filter, period and created filters, bulk "Download documents" (queued ZIP, audited per file); approver "Suspected" behind `expense.review.suspect_button` (default off, D-XO7) | 26.4.2 #5, #6, #8, #9, #11; 26.4.3 #3–#8, #10, #17, #20, #21, #23–#25 | `ExpenseReportResource`, `LinesRelationManager`, `PartsRelationManager`, `ExpenseReports::approvePart`, new `Jobs/ExportExpenseDocuments`, `ExperiencePreferences`, `config/peopleos.php` + `peopleos_setup.php` (setting) | XO-2 | L |
| 4 | **XO-4 Pay expenses depth** — group by employee, employee code / reimbursable / non-reimbursable columns, period / organisation / exceptional filters, "Paid" tab; optional pay month among open `PayCycle` months (`payment_scheduled_for`, blank = next payroll, D-XO1) read by `ExpenseReportInput`; outside payment mode (bank transfer / cheque / cash), paying account (`CompanyBankAccount`), cheque number and date, payment note | 26.4.4 #2, #3, #5, #6, #9–#12, #16, #18, #19; 26.4.3 #22 | `Pages/PayExpenses`, `ExpenseReports::markPaid / scheduleForPayroll`, `Payroll/Inputs/ExpenseReportInput`, migration (report payment columns), tests for the "never twice" guards | WO-2; BP-13 (done) | M |
| 5 | **XO-5 Advance desk and on-behalf filing** — "Request an advance for an employee", "New report for an employee"; Dispense advances "Dispensed" tab with dispense-date filter, employee outstanding column, trip link, dispense note, optional partial dispense (D-XO8, default full amount), navigation badge; advance amount and needed-on on the trip page; audit tab on advances | 26.3.1 #9; 26.3.2 #1, #2, #5, #7–#9; 26.2.2 #8; 26.7 #1, #2; 26.9 #2 | `ListExpenseAdvances`, `ListExpenseReports`, `Pages/DispenseAdvances`, `ExpenseAdvanceResource`, `ExpenseAdvances::dispense` (+ ledger note), `TravelRequestResource` | XO-1 | M |
| 6 | **XO-6 Advance recovery desk** — "Advance recovery" page per employee (outstanding, oldest settle-by, scheduled recovery, last report submitted), recovered / unrecovered tabs, organisation / employee-status / "no report for N days" filters, employee ledger with running balance, "Recover through payroll" (open month, amount ≤ outstanding → `expense_advance_recovery_schedules`, read first by `ExpenseAdvanceRecoveryInput`; D-XO2), refund modes bank / cheque / cash with bank name, cheque number and date, bulk with summary | 26.3.4 #1–#4, #6–#10; 26.3.1 #8; 26.7 #5 | new `Pages/AdvanceRecovery`, migration (schedules table; refund mode columns on `expense_advance_entries`), `ExpenseAdvances` (`scheduleRecovery`, `refund`), `Payroll/Inputs/ExpenseAdvanceRecoveryInput`, `PendingPayrollInputs`, `DataInventory` | WO-1 (bulk), WO-2 (filters) | L |
| 7 | **XO-7 Travel desk workspace** — "Travel bookings" register over company legs; leg statuses not required / cancelled (with cancellation charge); booking details booked on, commission, invoice number, duration, files; optional "File as expense" (company-paid receipt on the trip, default off, D-XO4); desk edit of itinerary / plan / added legs (D-XO3); "Send booking details" mail; trip page attachments (`ParityFileController` type `travel-request`), custom fields, audit history, leg preferred time and notes | 26.2.2 #9, #13–#16; 26.2.3 #1, #3, #5, #7–#13, #16, #17, #19–#23 | new `Resources/TravelBookings`, `TravelBookings` (`markNotRequired`, `cancelLeg`, `fileAsExpense`), `TravelRequests::deskEdit`, `LegsRelationManager`, `ParityFileController`, migration (leg columns), notification template | XO-2 (receipt page for filed bookings); P16 tenant mail transport for outside mail | L |
| 8 | **XO-8 Asset inventory depth** — managed by, sub type / assigned on / return date columns, filters assignment type, warranty expired, managed by; dispatch / receive for in-transit; status reserved (D-XO6); "Receive asset" as a wizard with review; asset code from a code series; supplier GSTIN, payment mode, cost currency, other cost, tax amount, depreciation method (straight line default), manufacturing date, last audit date + "Record audit", end-of-life entry, owning unit, documents (invoice / warranty / other), "Hand over now", "Receive several" (≤ 100 rows); movement filters; product image | 26.1 #11; 26.5.1 #6, #7, #9, #10, #13, #14, #19, #20; 26.5.2 #2, #6, #7; 26.5.3 #1, #4, #9, #12–#16, #18, #19, #21–#25, #27, #29, #30 | assets migration, `AssetResource` (wizard), `AssetActions`, `Assets` (`receiveMany`, `recordAudit`, `dispatch`, `reserve`), `AssetModel`, `ParityFileController` type `asset`, `config/peopleos.php` (`assets.statuses`, `payment_modes`, depreciation methods), `NumberSeries` type `asset`, `DataInventory` | Vendor master PR-01 only for 26.5.3 #8 | L |
| 9 | **XO-9 Assets by employee and acknowledgement registers** — holders register (count, unacknowledged, oldest temporary return), custodian filter on the asset register, Employee 360 take back and period / expected return / sub type / product columns, acknowledgement register (columns, status and type filters, source request) with bulk "Remind now" | 26.5.4 #1, #2, #4–#6; 26.5.5 #1, #2, #4–#7; 26.1 #2 | new `Pages/AssetHolders`, `Resources/AssetAcknowledgements` (on `AssetAssignment`), `Employees/RelationManagers/AssetsRelationManager`, `Assets::remindAcknowledgements(ids)` | XO-1 | M |
| 10 | **XO-10 Asset request desk and return tasks** — requested / decided / decider columns and date filters; period and return date on fulfil → `expected_return_on`; files on a return; daily system return requests when a temporary assignment is due (`asset.return.temporary_due_days`) or an asset reaches end of life (`asset.return.end_of_life`), both default off | 26.5.6 #2, #4, #6, #11–#14 | `AssetRequestResource`, `AssetRequests::fulfil`, `Assets::returnAsset`, new command `peopleos:assets:return-due` (daily), `AssetRequest::SOURCES` (`system`), `config/peopleos.php` + `peopleos_setup.php` | RQ-7 (multi-line requests) for #12 | M |
| 11 | **XO-11 Asset uploads** — asset types, sub types, products and assets (with custodian and historic disposals) through `RowImports` with P2 governance, screen `assets`, permission `asset.manage` | 26.1 #13; 26.5.7 #1–#4; 26.5.3 #28 | new `app/Domain/Assets/Imports/*`, `RowImportTypes` (screen `assets`), `ImportTypes::MODULES`, upload screen link on the asset register | XO-8 for the new columns (can ship first with today's columns) | M |
| 12 | **XO-12 Ticket workspace depth** — subject column, agent filter, reopened count and filter, due-day and first-response-missed badges, "Change priority" (reason, SLA recomputed, D-XO5), "Re-categorise" (resolver rules re-run, SLA replaced, audited), canned replies master and "Insert reply" | 26.6.1 #3, #4, #9; 26.6.2 #5, #7, #9, #10 | `TicketResource`, `ServiceDeskActions`, `ServiceDesk` (`changePriority`, `recategorise`), `SlaClock`, `ResolverRules`, migration (`tickets.reopened_count`, `service_desk_canned_replies`), new `Resources/CannedReplies` (`servicedesk.manage`) | SV-10 (TAT rows stay there) | M |
| 13 | **XO-13 Expense and asset tiles** — metrics `travel_tickets_to_book`, `accommodation_to_book`; template `assets_by_status`; the twelve expense templates marked `team_safe`; expense / asset / helpdesk task kinds registered for Inbox Insights | 26.10 #4–#6; 26.8 #9; 26.9 #1–#11 | `WorkforceMetrics`, `ReportLibrary`, `AnalyticsDefaults` | AN-30 (insights page), RQ-11 (team reports) | S |

Packages owned by other matrices that close rows here: WO-1 / WO-2 (search, filters, bulk framework — matrix 23),
RQ-2 / RQ-7 / RQ-8 / RQ-9 / RQ-11 (matrix 20), AN-1 / AN-6 / AN-7 / AN-16 / AN-21 / AN-23 / AN-30 (matrix 21), PM-10
(projects), SV-10 (two-stage helpdesk SLA, `setup/13` HD-1), PR-01 (vendor master, `setup/01` §1.33), external providers
(maps distance, receipt OCR).

## Owner decisions

**Already open — reused, not re-raised** (`HROne-Setup-Parity-Report.md` §8): return of an expense report discards the
other approvers' line decisions (§26.4.3 #9); an over-limit claim without a reason is capped, not refused (§26.4.3 #11);
advances approved for payroll payout may be dispensed by bank before payroll (§26.3.2 #10); `expense.exit.pending_reports`
defaults to `ignore` (alternative `block`); the payment step is on by default (§26.4.4 #22). Also open elsewhere: signed
asset acknowledgement with the letter studio's e-sign decision (§26.5.5 #8), receipt OCR and maps providers.

**New (this matrix)**

| # | Topic | Question | Proposal (default keeps today's behaviour) |
|---|---|---|---|
| D-XO1 | Pay month for expenses (XO-4) | May finance choose a later open payroll month for a report, as HROne's "payment cycle" does, instead of only the next payroll? | Yes, among open, not-finalized `PayCycle` months; blank = next payroll (today). |
| D-XO2 | Manual payroll recovery of advances (XO-6) | May HR schedule a recovery month and a partial amount that takes precedence over the policy's automatic recovery date? | Yes; a scheduled recovery wins over the automatic one for that advance; the automatic date stays the default. |
| D-XO3 | Travel desk edits to an approved trip (XO-7) | May the desk change legs, times and places, or add a leg, without sending the trip back for approval? | Yes inside the approved dates and estimate (audited, employee told); a later end date or a higher estimate goes back for approval. |
| D-XO4 | Company bookings as expenses (XO-7) | Should a booking the desk records be filed as a company-paid receipt on the trip (counted in per-trip limits and expense totals)? | Optional "File as expense", off by default; when on, counted against per-trip limits. |
| D-XO5 | Changing a ticket's priority (XO-12) | Is the SLA due recomputed from the ticket's creation with the new target, or from the moment of the change? | From creation, never earlier than now plus the warning margin; reason required. |
| D-XO6 | Asset status "Reserved" (XO-8) | Add a reserved status (held for a named employee or request)? | Yes; only that employee (or the request's fulfilment) can receive it; unused by default. |
| D-XO7 | Approver "Suspected" on an expense line (XO-3) | Does a suspected line block the part's decision, or is it held out (approved at 0 for now) and decided later? | Held out with a note, cleared later by a "Clear suspicion" decision; setting off by default. |
| D-XO8 | Partial dispense of an advance (XO-5) | May finance dispense less than the approved amount, and what happens to the rest? | Several dispenses up to the approved amount; settle-by counts from the first; the rest lapses when the advance is settled or cancelled. |
