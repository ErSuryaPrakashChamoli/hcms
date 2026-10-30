# HROne vs PeopleOS — 24 Time Office operations (HR Desk → Time Office), field by field

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A (none occur in this area). Package prefix: **TX-**.

**Inputs.**

- `hrone-research/menu-tree.json` → panel **"Time Office"** (top items Shift rosters, Shift requests, Reports, Time Office
  Setup; groups Shift (its two items are the top items), Checkins (7), Overtime & comp off (2), Attendance (3 in the tree;
  the live panel also shows an "Attendance" item, see §24.5.1), Leave (7), Holiday (2)) and the URL each item opened.
- `hrone-research/pages.jsonl` (latest line per id): `rail_Time_Office` (+ `__tab_01_Reports`), the 24 `menu_Time_Office__*`
  pages and their `__tab_01_Reports` captures (Shift_rosters, Shift_requests, Reports, Mark_attendance,
  Attendance_regularization_requests / _cancellations, On_duty_requests / _cancellations, Location_requests,
  Work_location_Geo_Based, Overtime_requests, Comp_off_requests, Attendance_calendar, Run_attendance_attendance_dashboard,
  Paid_days, Leave_requests, Leave_cancellations, Leaves_balance, Leave_encashment_requests / _cancellations,
  Short_leave_requests / _cancellations, Restricted_holidays / _cancellations), and the route captures
  `route_app_processes_timeoffice_{attendance,processattendance,shift,attendanceytd}`, `route_app_processattendance`,
  `route_app_analytics_cxo_timeoffice`. No `desk_*` capture opens a Time Office page (the ten `desk_*` ids are Tickets,
  Asset inventory, HR handbook, Employee goals, Travel request — other matrices).
- `hrone-research/menu-permission-tree.md` and `menu-catalogue.json` → `MenuAccessPermission/0` (with each node's
  grantable actions): HR Desk → Search → Time office [23], Inbox Insights → Time office [19], Action → Time office [12],
  Imports → Time office [16], Analytics → Time office [7], Reports → Time office [44]; Team → Reports → Time office [24],
  Team → Imports → Time office [5], Team → Analytics → Time office [7], Team → People → Action → Time office (11 rights),
  Team profile → Time office (18 rights); Smart search → Time office (5 rights); Analytics → Dashboards → Time office.
  `globalAction` (Time Office: Attendance regularization request, Carry forward leaves, Change attendance status, Change
  holiday, Change shift, Leave encashment request, Leave request, On duty request, Restricted holiday request, Run
  attendance (attendance dashboard), Shift request, Short leave request) and `AvailableSearch` (each Time Office page's
  `navigationCode`, `importIds`, `actionIds`).
- `hrone-research/components.json`: registers `app-shift-process-*`, `app-change-shift-*`, `app-bulk-change-shift-approval`,
  `app-mark-attendance-*`, `app-attendance-location-view`, `app-location-approval-search`, `app-geo-location-search`,
  `app-approved-locations-flyer`, `app-attendance-regularization-*`, `app-on-duty-*`, `app-overtime*`,
  `app-compensatory-off*`, `app-compoff-overtime-view`, `app-bulk-overtime-compoff-details`, `app-attendance-process-*`,
  `app-attendance-calendar`, `app-employee-attendance-flyer` → `app-emp-attendance`, `app-emp-punches-details-flyer`,
  `app-odd-punches-flyer`, `app-process-attendance-layout` / `-form`, `app-pro-att-common-card`, `app-month-selector`,
  `app-process-att-filter`, `app-reminders-card`, `app-warning-card`, `app-res-arrear`, `app-review-emp-attendance`,
  `app-rea-*`, `app-attendance-ytd-*`, `app-att-ytd-deduction`, `app-leave-search`, `app-leave-process-card`,
  `app-leave-detail`, `app-leave-balance-*`, `app-edit-leave-flyer`, `app-bulk-leavebalance-details`,
  `app-bulk-request-status-popup`, `app-leave-carry-forward-*`, `app-leave-encashment*`, `app-short-leave-*`,
  `app-restricted-holiday-*`, `app-assign-holiday-*`, `app-holiday-bank`, `app-bulk-shift-change`, `app-bulk-shift-edit`,
  `app-mark-attendance-search`, `app-bulk-attendance-edit`, `app-bulk-attendance-change-team`, `app-employee-search-filter`,
  `app-bulk-employee-search`, `app-request-workflow`, `app-weekoff-holiday-alert`, `app-verify-overtime`,
  `app-verify-compensatory-off`, `app-to-ccs-step-one` (check-in policy, for the "mark attendance" request).
- Lookup lists named by the captured APIs (configuration, not personal data): `timeoffice/setting/general`,
  `setting/Attendance`, `setting/leave/Type`, `setting/leave/AllWorkSchedule`, `payroll/PayGroup`, `payroll/MonthYear`,
  `payroll/FinancialYearList`, `core/EmployeeType`, `EmployeeStatus`, `EmployeeOtherStatus`, `scheduler/SchedulerType`,
  `AttendanceProcess/Card/{month}/{year}`, `AttendanceProcess/PendingRequest/{month}/{year}`,
  `Leave/CarryForward/Alert/ForHrdesk`, and the register searches (`…/Request/ForAdmin`, `leave/ByAdmin/Search`,
  `leave/Cancellation/Admin/Search`, `Employee/leaveBalance/Search`, `LeaveEncashment/Request/(Cancellation/)Search`,
  `attendance/Employees/ForCalender`, `mobile/checkin/EmployeesFromGeoLocationForAdmin`,
  `ChangeShift/WeeklyOff/Request/Search`).
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` for the ids above (structure only; no personal
  data copied — card contents are described by field, never by value).
- PeopleOS, checked in code: domains `Attendance` (models `AttendanceRecord`, `AttendancePunch`, `AttendanceRegularisation`,
  `TimeOfficeRequest`, `ShiftOverride`, `AttendanceOverride`, `AttendancePenalty`, `AttendanceArrear`, `GeoFence`,
  `LocationRequest`, `EmployeeDevice`, `HolidayCalendar`, `Holiday`, `HolidayLibrary*`; services `AttendanceProcessor`,
  `AttendanceOutput` (the quantities contract payroll reads), `PunchIngestion`, `CheckInGuard`, `Regularisations`,
  `TimeOfficeRequests`, `ShiftRoster`, `AttendanceOverrides`, `LatePenalties`, `GeoLocations`, `HolidayResolver`;
  support `RequestRules`, `AttendanceDay`; listeners `CompOffGenerator`, `TimeOfficeWorkflowBridge`; imports
  `PunchImports`, `AttendanceSheetImports`, `ShiftRosterImports`, `GeoLocationImports`, `OvertimeImports`,
  `DayOverrideImports`) and `Leave` (`LeaveRequest`, `LeaveBalance`, `LeaveLedgerEntry`, `LeaveEncashment`, `LeaveDonation`;
  `Leaves`, `LeaveAccrual` (accrue, `closeYear`, carry-forward expiry), `LeaveAdjustments`, `CompOffCredits`,
  `LeaveDonations`; imports `LeaveBalanceImports`, `LeaveHistoryImports`); Filament resources `AttendanceRecords`,
  `AttendancePunches`, `AttendanceRegularisations`, `TimeOfficeRequests`, `ShiftOverrides` ("Shift roster"),
  `AttendanceOverrides` ("Day overrides"), `AttendancePenalties`, `AttendanceArrears`, `GeoFences`, `LocationRequests`,
  `EmployeeDevices`, `AttendanceDevices`, `Shifts`, `WorkSchedules`, `HolidayCalendars` (+ holidays, rules, "Add from holiday
  library"), `TimeOfficeImports`, `AttendanceSheetImports`, `PunchImports`, `LeaveRequests`, `LeaveBalances`,
  `LeaveTransactions`, `LeaveEncashments`, `MyLeaveEncashments`, `LeaveDonations`, `LeaveTypes`; pages `AttendanceCalendar`,
  `AttendanceExceptionCentre`, `LeaveCalendar`, `Approvals`, `MyTeam`, `PayCyclePage`, `RecentUploads`, `PayrollControlRoom`;
  support classes `AttendanceActions`, `TimeOfficeActions`, `LeaveActions`; Approval Center source
  `TimeOfficeApprovalSource`; payroll inputs `AttendanceArrearInput`, `LeaveEncashmentInput`, `ShiftAllowanceInput`,
  payroll adjustment type `lop`; `PayrollRuns::finalize` (locks the period's attendance days); commands
  `peopleos:attendance:process` (02:00 daily, `--date` / `--to`), `peopleos:leave:accrue` (`--close-year`),
  `peopleos:leave:expire-comp-off`, `peopleos:attendance:absence-alerts`; `config/peopleos.php` (permissions `attendance.view /
  manage / approve / regularise`, `leave.view / apply / approve / manage`; attendance statuses and exception types; time-office,
  check-in, short-leave policy types), `config/peopleos_setup.php`; analytics datasets `attendance`, `attendance_monthly`,
  `attendance_punches`, `attendance_regularisations`, `shift_roster`, `holidays`, `leave`, `leave_balances`, `leave_ledger`,
  `leave_encashments`, `time_office_requests`, `attendance_penalties`, `attendance_arrears`, `checkin_devices`,
  `leave_donations` and their report-library templates; architecture test "keeps the attendance domain free of payroll money"
  (`tests/Feature/Architecture/ArchitectureTest.php`: attendance and leave code may not contain `ctc`, `salary`, `payslip`,
  `earning`, `deduction`, `tds`, `wage` …); docs `docs/architecture/hrone-parity.md` (HP-1, HP-1b, TO-1 … TO-8),
  `docs/hrone-parity/setup/00-build-status.md`, `HROne-Gap-Register.md` (TO-01 … TO-23).

**Pointed, not repeated.** Request forms (AR, leave, on duty, short leave, restricted holiday, change shift, encashment)
→ `20-requests-inbox-team.md` §20.1.7–20.1.9, §20.1.18 (RQ-3, RQ-4, RQ-7a); per-type request history lists → §20.1.19;
inbox task cards (leave, AR, OD, RH, change shift, comp-off / OT verification, mark attendance, location) → §20.2.2 rows
1–12; the request workflow tracker (approvers, TAT, send reminder, add approver) → §20.2.1 and RQ-2 / RQ-9; Team → People
bulk actions and the per-member team profile → §20.3.1–20.3.2 (RQ-11); the employee's own calendar, attendance YTD,
roster, holiday list and leave balance page → `22-my-space-self-profile.md` §22.4, §22.11, §22.12, §22.14.3, §22.14.17
(MS-11, MS-12); the report catalogue (HR Desk → Reports → Time office [44]) → `21-analytics-reports-projects.md`
§21.2.2 "Time Office (44)"; the time office dashboard (Analytics → Dashboards → Time office, `/app/analytics/cxo-timeoffice`)
→ §21.1.2 (AN-3); the Inbox Insights page itself → §21.3 (AN-30); the shared register chrome (search, filter flyer, saved
filters, bulk framework) and the import wizard / import log / import approval → `23-workforce-operations.md` §23.1 and
§23.10.0 (WO-1, WO-2, WO-17); Time office Setup (shift, rotation, group, weekly off, holiday, check-in policy, biometric
server, attendance policy, leave type / policy, short leave, settings) → `setup/09-time-office.md` and
`setup/13-setup-reverification.md` §8 (SV-5, SV-15); Setup → Automation → Workflow / Notification / Task automation → Time
Office → `setup/02-automation-forms-governance.md`. Such rows appear here once, naming the matrix and section.

**Progress.**

- Covered: every page of the Time Office panel (24 captured pages + the "Attendance" item seen on the live panel), each with
  its Records view (search, filters, cards, card menu, bulk notes, split buttons, dialogs from components) and its Reports
  tab (default report columns where captured); the Run attendance dashboard (three steps, pre-verification, arrear,
  process / unprocess, override paid days, review employee attendance, error list, reminders) and Paid days; all 23 nodes of
  HR Desk → Search → Time office with their rights; Action → Time office [12] and the 12 Time Office global actions with
  their dialogs (change shift, change attendance status, change holiday, carry forward leaves); Imports → Time office [16]
  and Team → Imports → Time office [5]; Inbox Insights → Time office [19]; Analytics → Time office [7] and Team → Analytics
  → Time office [7]; Smart search → Time office (5); Team → People → Action → Time office (11) and Team profile → Time
  office (18) (pointer rows to matrix 20); Reports [44] and Team → Reports [24] (pointer rows to matrix 21).
- Not captured as pages: dialogs behind split buttons, "ACTIONS" menus and card menus (the crawl did not click them);
  their rows come from components. Most request registers were empty for the crawl account ("No results found"), so card
  layouts come from component text. Import template columns are served by the server and are not in components; import rows
  are rated by import name. The seven Analytics → Time office views are not in components; their meaning is inferred from
  the name and marked *(inferred)*.
- Result: see "Counts" (status counted by script over every table row in §24.1–§24.14; the §0 building blocks and the
  §24.0 index are not counted).

**Built (2026-10-29).** TX-1 (Time office desk, register depth: WO-2 search and employee filters, request / decision /
day-asked-for ranges, preset views for HROne's request and cancellation registers, saved views, report and upload links,
read-only detail views, regularisation "raise on behalf") and TX-2 (the narrower rights `attendance.roster`, `override`,
`punch`, `process`, `arrears`, `leave.adjust`, `leave.carry_forward`, with `attendance.manage` / `leave.manage` still
granting them): the rows they close are marked "→ built" with the package (rows only partly closed say which part and
which package finishes them); see `docs/architecture/hrone-parity.md` "TX-1 / TX-2". The counts below are the
analysis-time counts.

**Status key.** HAVE: HR can see / do the same today. PARTIAL: something exists but does not cover the HROne element
(often: one-at-a-time where HROne works in bulk, a filter on a general list where HROne has a dedicated register, or the
data is recorded but not shown). MISSING: nothing equivalent. N-A: UI chrome, vendor timing / marketing, or a deliberate
PeopleOS rule (stated in the build note). *(inferred)* marks a meaning taken from a control name because HROne's label or
behaviour was not captured.

**Conventions used in the build notes** (same as matrices 20–23): additive changes whose defaults reproduce today's
behaviour; per-group options as Policy Engine type fields (`attendance`, `time_office`, `checkin`, `short_leave`, leave
entitlements); organisation switches as typed settings in `config/peopleos_setup.php` (Time office / Leave modules); new
models tenant-scoped (`BelongsToTenant` + `Auditable`, `HasEffectiveDates` where history matters), access-scoped when
employee-linked, classified in `DataInventory`, guarded by a policy; every write through the owning domain service
(`AttendanceOverrides`, `ShiftRoster`, `TimeOfficeRequests`, `Regularisations`, `PunchIngestion`, `GeoLocations`,
`Leaves`, `LeaveAdjustments`, `LeaveAccrual`, `HolidayResolver`); approvals through a workflow trigger (P5 bridge) or a tagged
`ApprovalSource`; uploads on the staged pipeline under P2 import governance (`RowImportTypes`, area `time_office`); bulk
actions authorise and audit each record on its own and never hard-delete business records (cancel / exclude / inactivate
instead); payroll-locked days keep today's rule (refused unless `attendance.allow_corrections_after_lock`, then an
arrear); permissions declared in `config/peopleos.php` then `php artisan peopleos:sync-permissions`. **Architecture rule:**
Attendance and Leave code never uses payroll words (`salary`, `deduction`, `earning`, `wage` …) and never computes money;
attendance produces quantities (paid days, penalty days, arrear days, overtime minutes) and payroll reads them through
`AttendanceOutput` / `LeaveOutput` and the `PayrollInputSource` inputs. HROne's "deduction" wording therefore becomes
"reduction" / "penalty days" in attendance names.

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | Relevance |
|---|---|---|
| Attendance records: employee (+ code), date, shift, status, in, out, worked, late, OT (approved / recorded), exceptions, regularised; filters date range, status (multi), department; row actions Regularise (HR applies and approves at once), Approve OT (minutes editable), Reject OT, Reprocess; header Manual punch (employee, date-time, direction, note), Process day, Process dates (range, chosen employees or everyone, queued, ≤ 2 months) | `AttendanceRecordResource`, `ListAttendanceRecords`, `AttendanceActions` | HROne Attendance register, Run attendance (processing) |
| Exception centre: 30-day counts (exceptions, missing punch, late, absent, OT pending, regularisations pending, failed punches) and the table of days with exceptions (filter by exception type) | `AttendanceExceptionCentre` | HROne pre-verification, reminders |
| Attendance calendar: one employee's month (picker for `attendance.view`), day codes and colours, in / out, late, holiday, shift, trip; totals (present, half days, absent, leave, on duty / WFH, paid days after penalties, late minutes, penalty days); holidays; printable slip | `AttendanceCalendar` | HROne Attendance calendar, Paid days |
| Time-office requests: short leave, on duty (full / half / hours, multi-day, type), shift change, week-off change, overtime claim, comp-off claim, restricted holiday; one list with type / status filters; approve / reject / approve cancellation / keep / cancel / resubmit; "New request" (self), "Raise on behalf" (`attendance.manage`); Approval Center kinds `time_office`, `time_office_cancellation` | `TimeOfficeRequestResource`, `TimeOfficeActions`, `TimeOfficeRequests`, `TimeOfficeApprovalSource` | HROne Shift requests, OD, short leave, RH, OT, comp-off registers |
| Regularisations: employee, date, type (missed punch, late, early, WFH, on duty, field duty, absent), in, out, reason, status (default filter pending), by, reviewed by; approve / reject; only pending requests can be cancelled | `AttendanceRegularisationResource`, `Regularisations` | HROne AR requests / cancellations |
| Shift roster (day overrides): employee, date, shift (empty = weekly off), source (roster / request / import), reason, by; filters shift, source; Remove; header "Roster employees" (employees, from, to, shift or weekly off, reason); work schedules with rotations, rules and `auto_shift_ids` | `ShiftOverrideResource`, `ShiftRoster`, `WorkScheduleResource` | HROne Shift rosters, Change shift |
| Day overrides: one employee, one day: status (present, absent, half day, weekly off, holiday, WFH, on duty, field duty) and / or worked minutes, reason; Remove | `AttendanceOverrideResource`, `AttendanceOverrides` | HROne Change attendance status, Edit attendance |
| Late-mark penalties (warn / half / full day per N late marks; waive with reason) and attendance arrears (paid-day differences on payroll-locked days, settled by `AttendanceArrearInput`) | `AttendancePenaltyResource`, `AttendanceArrearResource`, `LatePenalties` | HROne attendance penalty, attendance correction arrear |
| Geo work locations (radius or polygon, per work location / employee / everyone, valid dates, status) and location requests (place, coordinates, needed until, reason; approve / reject / withdraw); check-in devices (approve / block); check-in policy (`CheckInGuard`: sources, location, photos, Wi-Fi, IP, outside-fence action flag / reject) | `GeoFenceResource`, `LocationRequestResource`, `EmployeeDeviceResource`, `GeoLocations`, `CheckInGuard` | HROne Location requests, Work location, Mark attendance |
| Leave: requests (apply on behalf, cancel, cancellation request / decision), balances (opening, accrued, adjusted, used, pending, encashed, lapsed, closing, available; Adjust ±; Encash = HR records and approves), transactions (ledger), encashments (approve / reject / cancel; paid in the next payroll), donations; accrual (daily), year close (`--close-year`: carry forward up to the rule, lapse the rest) | Leave resources, `Leaves`, `LeaveAdjustments`, `LeaveAccrual`, `LeaveEncashmentInput` | HROne Leave group, Carry forward leaves |
| Holiday calendars by rules; holidays (date, name, type, half day, source); holiday library (platform lists, tenant adopts); restricted-holiday quota, joiner proration, cancel lock, without-shift option | `HolidayCalendarResource`, `HolidayLibrary`, `HolidayResolver` | HROne Restricted holidays, Change holiday, holiday bank |
| Time office uploads (leave balances opening / adjustment, leave history, shift roster, geo locations, overtime hours, day overrides), monthly attendance upload (day codes, modes, blank-day rule, pay-cycle month), punch imports; staged pipeline with P2 approval; Recent uploads | `TimeOfficeImportResource`, `AttendanceSheetImportResource`, `PunchImportResource`, `RowImportTypes` | HROne Imports → Time office |
| Pay cycle (25th–24th months, dated changes) read by attendance, leave and payroll through `PayCycleReader` | `PayCyclePage`, `payroll.pay_cycle` | HROne attendance month / cut-off |
| Payroll locks a period's attendance days when a run is finalized (`is_locked`, `finalized_at`); manual loss-of-pay days are a payroll adjustment (`lop`); the payroll exception "Attendance not processed" | `PayrollRuns::finalize`, `PayrollAdjustment::TYPES`, `PayrollCalculator` | HROne process / unprocess attendance, override paid days |
| Report library (41 datasets, 149 templates) | `ReportLibrary`, `DatasetRegistry` | HROne Reports tabs and report centre |

---

## 24.0 Index — HR Desk → Search → Time office [23] and the Time Office panel

Not counted. Each node's rows are in the section named. "Rights" = the actions HROne can grant on the Search node; "imp" /
"act" = the `importIds` / `actionIds` HROne links to the page.

| # | Search node (rights) | Time Office panel item · URL code (imp / act) | Section | Overall |
|---|---|---|---|---|
| 1 | Shift (View, Update, Inactive) | Shift rosters · `shift_108` (imp 45 / act 529) | 24.2.1 | PARTIAL |
| 2 | Change shift (View) | Shift requests · `changeshift_1449` (act 2417) | 24.2.2 | PARTIAL |
| 3 | Mark attendance (View) | Mark attendance · `markattendance_346` | 24.3.1 | MISSING |
| 4 | Attendance regularization (View) | Attendance regularization requests · `attendanceregularization_95` (act 103) | 24.3.2 | PARTIAL |
| 5 | Attendance regularization cancel (View) | Attendance regularization cancellations · `attendanceregularizationcancel_100` (act 103) | 24.3.3 | MISSING |
| 6 | On duty (View) | On duty requests · `onduty_96` (act 106) | 24.3.4 | PARTIAL |
| 7 | On duty cancel (View) | On duty cancellations · `ondutycancel_101` (act 106) | 24.3.5 | PARTIAL |
| 8 | Location approval (View) | Location requests · `locationapproval_347` (imp 66) | 24.3.6 | PARTIAL |
| 9 | Geo locations (View) | Work location (Geo Based) · `geolocations_661` (imp 66) | 24.3.7 | PARTIAL |
| 10 | Overtime (View) | Overtime requests · `overtime_241` (imp 54 / act 2567) | 24.4.1 | PARTIAL |
| 11 | Compensatory off (View) | Comp off requests · `compensatoryoff_123` (imp 146 / act 2566) | 24.4.2 | PARTIAL |
| 12 | Attendance (View, Update, Inactive, Add punch, Delete punch) | Attendance · `attendance_103` (imp 60, 41, 98 / act 528) — on the live panel, not in the menu crawl | 24.5.1 | PARTIAL |
| 13 | Attendance calendar (View) | Attendance calendar · `attendancecalendar_572` (imp 60, 41 / act 528) | 24.5.2 | PARTIAL |
| 14 | — (Action node "Attendance dashboard") | Run attendance (attendance dashboard) · `processattendance_106` | 24.5.3 | MISSING (desk, close / reopen); processing itself PARTIAL |
| 15 | Attendance ytd (View) | Paid days · `attendanceytd` (imp 44) | 24.5.4 | MISSING |
| 16 | Leave (View) | Leave requests · `leave_98` (imp 134 / act 110) | 24.6.1 | PARTIAL (register HAVE; date filters, bulk cancel missing) |
| 17 | Leave cancel (View) | Leave cancellations · `leavecancel_102` (imp 134 / act 110) | 24.6.2 | PARTIAL |
| 18 | Leave balance (View, Update) | Leaves balance · `leavebalance_105` (imp 42, 43 / act 2576) | 24.6.3 | PARTIAL |
| 19 | Leave encashment (View) | Leave encashment requests · `leaveencashment_1452` (act 2415) | 24.6.4 | PARTIAL (register HAVE; filters, pay month, bulk process missing) |
| 20 | Leave encashment cancel (View) | Leave encashment cancellations · `leaveencashmentcancel_1508` (act 2415) | 24.6.4 | PARTIAL |
| 21 | Short leave (View) | Short leave requests · `shortleave_99` (act 399) | 24.6.5 | PARTIAL |
| 22 | Short leave cancel (View) | Short leave cancellations · `shortleavecancel_104` (act 399) | 24.6.5 | PARTIAL |
| 23 | Restricted holiday (View) | Restricted holidays · `restrictedholiday_97` (imp 58 / act 108) | 24.7.1 | PARTIAL |
| 24 | Restricted holiday cancel (View) | Restricted holidays cancellations · `restrictedholidaycancel_971` (imp 58 / act 108) | 24.7.1 | PARTIAL |
| — | — | Reports (`openReports`) | 24.12 | PARTIAL |
| — | — | Time Office Setup | `setup/09-time-office.md`, `setup/13-setup-reverification.md` §8 | — |

The Search tree has 23 nodes; "Attendance dashboard" is an Action node (§24.8), which is why the table has 24 items.

---

## 24.1 Time Office panel and the shared register chrome (`rail_Time_Office`, every `/app/processes/search/*/search` Time Office page)

Every Time Office page is the same shell as the Workforce pages (matrix 23 §23.1): a left panel, a title, **Records /
Reports** tabs, a search box, a "Filters" flyer, an "IMPORTS" (or "Imports ▾") button, a split request button (e.g.
"Leave request ▾") or "ACTIONS", cards with "SHOW MORE" paging and, on bulk-capable pages, a note such as "In order to do
bulk cancel of leave, apply status filter". The rows below rate the shell once; page sections rate what differs.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Rail "Time Office" panel: title, collapse toggle, panel search "Search", groups Shift (Shift rosters, Shift requests), Checkins (7), Overtime & comp off (2), Attendance (Attendance, Attendance calendar, Run attendance, Paid days), Leave (7), Holiday (2), then Reports and Time Office Setup | One home for every time-office register, grouped by subject | Navigation groups "Attendance" (≈20 entries: calendar, exception centre, shifts, punches, uploads, schedules, roster, holiday calendars, devices, records, regularisations, time-office requests, day overrides, penalties, arrears, geo locations, location requests, check-in devices, time office uploads) and "Leave" (calendar, requests, balances, transactions, encashments, donations, types); ordered by build history, not by HROne's groups; several HROne registers are one PeopleOS list with a type / status filter | PARTIAL → built (TX-1: Time office desk — Shift · Checkins · Overtime & comp off · Attendance · Leave · Holiday · Reports and uploads · Setup — over the existing registers, preset views, own rail area) | TX-1: a "Time office desk" cluster ordered Shift · Checkins · Overtime & comp off · Attendance · Leave · Holiday · Reports · Setup; each HROne register = an entry opening the existing resource with its preset filter (e.g. Time-office requests ▸ type On duty, status Cancellation requested); no new list where a resource exists |
| 2 | Tabs "Records" / "Reports" on each page; Reports tab = the page's default report with a view picker, "Create View" and (some pages) "EDIT FILTER" | Register and its report side by side | Registers are Filament lists; reports live in Analytics → Report library | PARTIAL → built (TX-1: "Report" on every time-office / leave register, the library templates with the register's filters) | TX-1: "Report" header action on each register opening the matching library template with the register's filters (no second report engine; same as WO-1) |
| 3 | "Looking for something else" banner menu | HROne navigation help | — | N-A | Vendor chrome |
| 4 | Search "Search employee by name, code, designation, email" | Find records of an employee | Time-office lists search the employee's first / last name; the code is shown under the name but not searchable; designation and e-mail not searchable | PARTIAL → built (TX-1: WO-2 employee search on every time-office / leave register) | WO-2 shared employee search (code, names, work e-mail, designation) applied to every time-office register in TX-1 |
| 5 | "Filters" flyer (`app-employee-search-filter`): SELECT FILTERS, APPLY FILTERS, RESET FILTERS, CLEAR ALL, filter chips | Filter panel with chips | Filament filters above the table, kept in the session, "Reset" | HAVE | — |
| 6 | Filter "Search Employees" + "Add bulk employees" (`app-bulk-employee-search`: paste codes "eg.: Emp001,Emp002") | Restrict to a pasted list of employees | — | MISSING → built (TX-1: pasted employee codes to keep / leave out) | WO-2 "Employee codes" filter on every time-office register (TX-1) |
| 7 | Filters "Organization" (company, business unit, department, sub-department, designation, grade, level, region, branch, sub-branch) and "Location" | Organisation-unit filter | Department filter on Attendance records and Leave requests only; none on time-office requests, regularisations, shift roster, overrides, balances, encashments, geo locations | PARTIAL → built (TX-1: WO-2 organisation group on every time-office / leave register) | WO-2 organisation filter group on every time-office register (TX-1) |
| 8 | Filters "Status" (employment status), "Type" (employee type), "Other Status", "Date of joining" | Workforce filters on time registers | — on time-office registers | MISSING → built (TX-1: employee status, employment type and joining date group) | WO-2 lifecycle state, employment type, joining-date range (TX-1) |
| 9 | Filter "Request Date" (from / to, "Please select both start and end date") and per-page date filters (request / approval / effective date) | Date windows | Date range on Attendance records and leave period; none on time-office requests, regularisations, encashments, location requests | PARTIAL → built (TX-1: raised, decided and day-asked-for ranges on every request register) | TX-1: request-date, decision-date and effective-date ranges on every request register |
| 10 | Filters "Leave Type", "Pay Group", "Salary Month", "Pay Arrear (For) Month", "Arrear Days" (1–5, 5–10, Greater than 10, Zero, Less than zero) | Module filters on leave / processing registers | Leave type on leave requests and balances; no pay groups (payroll matrix); no arrear-days or arrear-month filter | PARTIAL | Arrear filters with TX-3; pay group waits for the payroll pay-group decision (payroll matrices) |
| 11 | "Exclude Employees", "Exclude employees with pending resignation" | Leave out named people / leavers | — | MISSING → built (TX-1: leave out pending resignations; leave out pasted codes) | WO-2 (exit case open toggle, exclude-codes list) on time registers (TX-1) |
| 12 | "Save Filters" ("Do you want to save these filters for future use?"), saved filter list | Named reusable filter sets | Session-only filters | MISSING → built (TX-1: WO-1 saved views on every time-office / leave register) | WO-1 saved views, available on time-office registers (TX-1) |
| 13 | Cards: initials avatar, name (#code), company, department, request facts, status; "SHOW MORE" paging | Card view of records | Table rows with the same facts (employee name with code underneath), paging 25 / 50 / 100 | HAVE | Tables by design; no card view planned |
| 14 | Card status chips Pending / Undo / Rejected / Approved | Request states | pending, approved, rejected, cancelled (withdrawn), cancel_requested | HAVE | — |
| 15 | Card "Workflow" (opens `app-request-workflow`: approvers, responded by / on, TAT, Send Reminder, Add approver, discussion) and due badges (MISSED TO RESPOND, OVERDUE, DUE : TODAY / TOMORROW) | Track and nudge the approval | Approval Center and workflow instances; no tracker from the register row | PARTIAL → TX-1 part built (detail views show the workflow run and link its tracker; reminders / add approver stay RQ-2 / RQ-9) → RQ-2 part built (the requester's request page: trail, waiting with and due, "Send reminder", discussion; "Workflow and policy" on the approver's card; add approver and due badges stay RQ-9 / RQ-8) → RQ-8 part built (due badges in the unified inbox; add approver stays RQ-9) | Matrix 20 RQ-2 / RQ-8 / RQ-9; TX-1 detail view links the tracker |
| 16 | Help text "Once you submit cancel request from here, you need to go to insights page to approve it" | Cancellation of approved items goes for approval | Leave and time-office cancellation requests in the Approval Center | HAVE | Matrix 20 §20.1.19 #2 |
| 17 | Bulk note ("In order to do bulk cancel of leave, apply status filter", "… bulk inactive location, apply location status filter"), "Employees selected", "Cancel all" / "Inactivate all" | Act on the filtered set | No bulk actions on any time-office or leave register | MISSING | TX-9 (on WO-1's bulk framework: each record through its service, typed-count confirmation, result summary) |
| 18 | Split request button "<Request> ▾" on each request register | Raise the request on behalf of an employee | "Raise on behalf" on Time-office requests; "Apply on behalf" on Leave requests; AR and encashment by HR are applied and approved at once (§24.8) | PARTIAL | §24.8 rows |
| 19 | "IMPORTS" / "Imports ▾" on registers | Open the page's uploads | Uploads have their own screens (Time office uploads, Monthly attendance uploads, Punch imports) | PARTIAL → built (TX-1: "Upload" on each register opens its upload with the type chosen) | TX-1: "Upload" header action on each register opening the matching upload type (§24.9) |
| 20 | Empty state "No results found — Try adjusting the filters or come back later!" | Empty register | Empty states | HAVE | — |
| 21 | Data scope of the HR desk (records limited to the role's data access) | Who sees what | Access scope on every employee-linked model (`ScopedByEmployee`, `AccessScope`); employees without `*.view` see only their own rows | HAVE | — |
| 22 | HR-desk alert for leave carry forward (API `Leave/CarryForward/Alert/ForHrdesk`, called on every Time Office page) *(inferred)* | Remind HR that the leave year is closing and carry forward is pending | — (year close is a CLI option) | MISSING | TX-8: HR-desk notice from N days before a leave year ends until the year is closed (setting `leave.carry_forward_alert_days`, default 0 = off) |

---

## 24.2 Shift

### 24.2.1 Shift rosters (`/app/processes/search/shift/search`, `shift_108`; `app-shift-process-search` → `app-shift-process-grid`, `app-shift-process-edit`; Reports tab)

Captured: Records with "Imports ▾" and the split "Change shift ▾"; the Reports tab's default report (15 columns). Search
rights on node "Shift": View, Update, Inactive. Import 45 (Shift). APIs of the Reports tab: `employeeReport/123/2025`.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of employees and their shift (one card per employee, "SHOW MORE") | Who works which shift | "Shift roster" lists only day overrides (employee, date, shift, source, reason, by); the base pattern sits in Work schedules / assignments; no per-employee roster view | PARTIAL | TX-5 roster board: employee × day grid (week / month) of the resolved shift (schedule + overrides), with leave / holiday / weekly-off marks |
| 2 | Card "Details" → `app-employee-attendance-flyer` → the employee's attendance calendar | Open the person's month | Attendance calendar (pick the employee) shows the shift code per day | PARTIAL | TX-5: board cell / row opens the calendar for that employee and month; calendar content per matrix 22 §22.4 |
| 3 | Edit (`app-shift-process-edit`): From · `fromDate`, To · `toDate` | Range to roster | "Roster employees": from, to | HAVE | — |
| 4 | Edit: "Shift" | Shift for the range | "Roster employees": shift | HAVE | — |
| 5 | Edit: "Weekly off" (chosen separately from the shift) | Weekly-off days within the range | A rostered day is a shift or a weekly off (empty shift); no weekday pattern for a range | PARTIAL | TX-5: "Weekly off on" weekday multi-select applied over the range (those days become weekly offs, the rest take the shift) |
| 6 | Edit: "Select all" *(inferred: all days of the range / all listed employees)* | Apply to every row | The action applies to every chosen employee and every day of the range | HAVE | — |
| 7 | Edit: "Do you want to allow auto shift correction ?" | Let punches pick the nearest shift for these days | Auto shift correction is a work-schedule property (`auto_shift_ids`, TO-5), not a choice for a rostered range | PARTIAL | TX-5: flag `auto_shift` on rostered days (default false = today) read by `ShiftResolver` with the schedule's auto list |
| 8 | Edit: CLOSE / CANCEL | Leave the editor | Modal cancel | HAVE | — |
| 9 | Split "Change shift ▾" | The bulk "Change shift" global action | "Roster employees" | PARTIAL | §24.8 #8 (TX-5) |
| 10 | "Imports ▾" (import 45 Shift) | Upload a roster | Shift roster upload (employee code, date, to date, shift code or WO; modes add and change / only new days / only rostered days) | HAVE | §24.9 #3 |
| 11 | Search rights "Update" (edit a shift) and "Inactive" *(inferred: remove a rostered shift)* | Grant edit and removal separately | Assign and Remove both need `attendance.manage` (with configuring shifts, devices, holidays) | PARTIAL → built (TX-2: `attendance.roster`) | TX-2: `attendance.roster` (assign / remove day roster), included in `attendance.*` so existing roles keep it |
| 12 | Reports tab (default report): Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Attendance date, Shift code, Shift timings, Changed by, Changed on, Source; view picker; "Create View" | Day-wise shift history | Dataset `shift_roster` holds schedule assignments (schedule, code, from, to, current, reason); day overrides (date, shift, source, by) are not in a dataset; the roster list shows source and by but not "changed on" | PARTIAL → TX-1 part built (Report link on Shift rosters); `day_roster` dataset with AN-28 | AN-28 dataset `day_roster` (employee, date, shift code, shift times, source, changed by / on) — matrix 21 §21.2.2 Time Office #36, #43; TX-1 report link |

### 24.2.2 Shift requests (`/app/processes/search/changeShiftSearch/search`, `changeshift_1449`; `app-change-shift-search`, `app-change-shift-card`, `app-change-shift-detail`, `app-bulk-change-shift-approval`)

Captured: empty register ("No results found"), "IMPORTS", split "Shift request ▾"; API
`ChangeShift/WeeklyOff/Request/Search` (shift and weekly-off change requests together); Reports tab with "CREATE VIEW" only.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of shift and weekly-off change requests ("View all your past and upcoming Change shift requests here") | HR sees every change request | Time-office requests filtered by type Shift change / Week-off change (one list for all request types) | PARTIAL → built (TX-1: preset view "Shift requests") | TX-1 preset entry "Shift requests" |
| 2 | Filter "Change shift date" | Requests for days in a window | — | MISSING → built (TX-1: day-asked-for range) | TX-1 effective-date range |
| 3 | Filter "Request date" | Raised in a window | — (Raised column hidden by default, sortable) | MISSING → built (TX-1: raised range) | TX-1 |
| 4 | Filter "Approved date" | Decided in a window | — | MISSING → built (TX-1: decided range) | TX-1 decision-date range |
| 5 | Filter "Status"; FILTERS / CLEAR ALL / Apply | Request state | Status filter (multi) | HAVE | — |
| 6 | Card: employee, "Requested Date :", status (Pending / Undo / Rejected / Approved) | Request summary | Employee, type, date (to date), detail (shift asked for), status, decided by, raised | HAVE | — |
| 7 | Detail: "Earlier shift" / "New shift" | What changes | The new shift is shown; the earlier shift is kept in `effects` (overrides replaced) but not shown | PARTIAL → built (TX-1: detail view with earlier / new shift per day) | TX-1 detail view (infolist): earlier and new shift per date from `effects` / `ShiftResolver` |
| 8 | Detail: "Earlier weekly off" / "New weekly off" | Weekly-off swap | Week-off change keeps the off day worked and `swap_date`; the list's detail column shows neither | PARTIAL → built (TX-1: detail view and Detail column for week-off changes) | TX-1 detail view and a detail-column case for `week_off_change` |
| 9 | Detail: "Remarks", "Attachment" | Requester's reason and file | Reason column; attachments stored on the request but not viewable from the list (no view page) | PARTIAL → built (TX-1: detail view with remarks and documents behind signed, audited links) | TX-1 detail view with the attachments (private disk, signed download) |
| 10 | Detail: "Comment" + Approve / Reject | Decide with a note | Approve (note) / Reject (note required) | HAVE | — |
| 11 | Split "Shift request ▾" (request on behalf; per-date rows with shift and weekly off, "Add next day", "Copy shift to all rows", "Remove date") | HR raises a change for an employee | "Raise on behalf": type Shift change (from, to, shift, reason, attachments) or Week-off change | HAVE | Per-date rows: matrix 20 §20.1.18 "Change shift" (RQ-3) |
| 12 | "IMPORTS" (no import type linked to this page) | — | — | N-A | Nothing to upload; roster uploads are §24.9 #3 |
| 13 | Bulk approval (`app-bulk-change-shift-approval`): tabs "Pending for approval (n)" / "Missed to Respond (n)"; Select; columns Employee name, Requested date, Change shift date, Earlier shift, New shift; "Accept request", "Reject", "Move to archive" | Decide many shift requests at once | Approval Center bulk approval for switched-on kinds (P3) and archive (P4); no earlier / new shift columns, no bulk reject | PARTIAL | TX-9: bulk approve / reject from the register; earlier / new shift columns from TX-1 |
| 14 | Reports tab: "CREATE VIEW" (no default report) | Build a report on shift requests | Library template for shift changes on `time_office_requests` (HP-1b) | HAVE | — |

---

## 24.3 Checkins

### 24.3.1 Mark attendance (`/app/processes/search/markattendancesearch/search`, `markattendance_346`; `app-mark-attendance-global-search` → `app-mark-attendance-card`; detail `app-mark-attendance-inbox` → `app-mark-attendance-detail`, `app-attendance-location-view`)

Captured: cards (avatar, name (#code), company, department, "Location status :", "Requested Date :", status Approved /
Rejected), note "In order to do bulk inactive location, apply location status filter.", "IMPORTS", "ACTIONS"; API
`mobile/checkin/Attendance/Request/ForAdmin`; Reports tab "Create View", "EDIT FILTER" (`employeeReport/66/2002`). The
employee side (`app-wcr-mark-attendance`: last check-in, source, remark, work mode, city, office, "View request workflow")
and the check-in policy (`app-to-ccs-step-one`, `mobileAttendanceType`) show that a web / mobile check-in can be a request
that someone approves, and that approving it can approve the place as a work location.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of web / mobile check-ins awaiting or past approval | HR sees check-ins that needed approval | Check-ins outside every geo location are accepted-and-flagged or rejected (`outside_fence_action`); there is no approval of a check-in and no register of flagged ones (the Punches list does not show geo status) | MISSING | TX-10: check-in policy option `outside_fence_action = approve` (default stays `flag`): the punch is stored with `approval_status = pending`, not counted until approved; register = punches with an approval status |
| 2 | Filters "Request date", "Approval date" | Windows | — | MISSING → TX-1 part built (date ranges on the punch register); check-in approvals with TX-10 | TX-10 (TX-1 date ranges) |
| 3 | Filter "Status" | Pending / approved / rejected | Punches filter by processing status only | MISSING | TX-10 |
| 4 | Filter "Location status" + card "Location status :" (Active / Inactive) | Whether the place approved with the check-in is still an active work location | — | MISSING | TX-10: approving may save the place as an employee geo location; the register shows that location's status |
| 5 | Card "Requested Date :" + status | Summary | — | MISSING | TX-10 |
| 6 | Detail: Request date, Source (Web / Mobile), Coordinates, Address, IP address, Remarks | Evidence of the check-in | Punches keep source, latitude / longitude, IP, Wi-Fi, note, geo status and distance; the Punches list shows source, device and raw payload only | PARTIAL | TX-10 punch detail (infolist) with source, coordinates (link out), nearest location and distance, IP, Wi-Fi, note |
| 7 | Detail: "Map", "Polygon area (View on map)", "Polygon" | See the place on a map | — | MISSING | Blocked: map provider (CA-8, as matrix 20); coordinates open in the user's own map app meanwhile |
| 8 | Detail: "Photos", "Photo is missing", "Location is missing", help "Location or photo may be missing as there may have been challenges retrieving location or photo on the user device" | Photo evidence | Photos stored on the punch (`photos`, `face_verified`, TO-2); not displayed anywhere | PARTIAL | TX-10: photos in the punch detail (private disk, `attendance.view` in scope); missing-photo / missing-location marks |
| 9 | Detail: "City", "Office", "Work mode" | Where the employee says they work today | Punches carry no work mode / office / city; work mode is a position attribute | MISSING | With setup SV-18 hybrid work (matrix 13): optional punch fields from `WorkModes` and locations when the check-in policy asks (default off) |
| 10 | Detail: "You want to get location approval for — Forever / Specific (Days)" (`isSpecificPeriod`, `specificPeriodDays`) | Approve the place permanently or for N days | Location requests carry valid-from / valid-until within the policy maximum (TO-2); a check-in cannot become a location | PARTIAL | TX-10: approving a check-in may add the place as the employee's geo location, forever or for N days (≤ policy maximum) |
| 11 | Detail: approver "Comments" (`approverRemarks`), "Activity log" | Decide with a note; history | — for check-ins | MISSING | TX-10: Approval Center kind `checkin_approval` (note, audit trail) |
| 12 | "Employees selected" + "Inactivate all" (bulk inactivate the locations; needs the location-status filter); "ACTIONS" | Retire many approved places at once | Geo locations inactivated one at a time | MISSING | TX-9 bulk inactivate (reason, per-record audit) |
| 13 | "IMPORTS" (no import type linked) *(inferred: attendance check-ins)* | Upload check-ins | Punch imports | HAVE | §24.9 #1 |
| 14 | Reports tab (`employeeReport/66`: mobile check-in request / approved geo location) with "Create View", "EDIT FILTER" | Report on mark-attendance requests | Punches dataset (source, device); no approval fields; geo-location datasets missing | PARTIAL | Matrix 21 §21.2.2 Time Office #1, #15, #30 (AN-28) |

### 24.3.2 Attendance regularization requests (`/app/processes/search/attendanceregularization/search`, `attendanceregularization_95`; `app-attendance-regularization-search`, `-process-card`, `-detail`, `-request-flyer`)

Captured: cards (avatar, name (#code), company, department, "Requested Date :", status Pending / Approved), "IMPORTS",
split "Attendance regularization request ▾", "SHOW MORE"; API `attendance/regularization/Request/ForAdmin`; Reports tab
(`employeeReport/44/4950`, 27 columns).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of AR requests | HR sees every AR | Regularisations: employee, date, type, in, out, reason, status, by, reviewed by; default filter pending | HAVE | — |
| 2 | Filter "AR date" | Requests for days in a window | — | MISSING → built (TX-1: attendance-date range) | TX-1 |
| 3 | Filter "Request date" | Raised in a window | — | MISSING → built (TX-1: raised range) | TX-1 |
| 4 | Filter "Approved date" | Decided in a window | — | MISSING → built (TX-1: decided range) | TX-1 |
| 5 | Filter "Status" | State | Status filter | HAVE | — |
| 6 | Card "Requested Date :" | When it was raised | Not a column on regularisations | PARTIAL → built (TX-1: "Raised" column) | TX-1 "Raised" column |
| 7 | Detail "Request type" | AR reason type | Type (missed punch, late, early leaving, WFH, on duty, field duty, marked absent by mistake) | HAVE | — |
| 8 | Detail "Requested check-in" / "Requested check-out" | Times asked for | In / out columns | HAVE | — |
| 9 | Detail "Attachment" (download) | Supporting file | Attachments stored (TO-3, required when the policy says so); not viewable from the list | PARTIAL → built (TX-1: detail view with documents) | TX-1 detail view with attachments |
| 10 | Detail "Office", "City", "Work mode" | Where the day was worked | — (WFH is an AR type) | MISSING | With SV-18 hybrid work (matrix 13); fields optional, default off |
| 11 | Detail "Comments" (`approverRemarks`) + Approve / Reject | Decide with a note | Approve (note) / Reject (note required); approval reprocesses the day | HAVE | — |
| 12 | Week-off / holiday alert on deciding (`app-weekoff-holiday-alert`: "Are you sure you want to continue?" Date, Type, Employee code) | Warn when the AR covers a week off, holiday or leave | Request rules can refuse AR on week-offs / holidays (`ar_not_on`, TO-3); no warn-and-continue for the approver | PARTIAL → built (TX-1: detail view shows the day's status now) | TX-1 detail view shows the day's current status (weekly off / holiday / leave) next to the request |
| 13 | Split "Attendance regularization request ▾" (on behalf) | HR raises an AR that follows the approval path | HR "Regularise" on an attendance record applies and approves at once; no on-behalf request left for an approver | PARTIAL | §24.8 #1 |
| 14 | "IMPORTS" (no import type linked) | — | — | N-A | Nothing to upload; day results are uploaded as status (§24.9 #6) |
| 15 | Reports tab: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Request type, Attendance date, Attendance day, Reason, Shift code, Shift timings, Actual punch in/ out, Punch in (date), Punch in timing, Punch out (date), Punch out timing, Remarks, Request status, Request by, Request on, Approved by, Approved on, Approver remark | AR report | `attendance_regularisations`: date, month, type, requested in / out, reason, status, applied on, reviewed by / on, review note, pending days (+ employee fields); missing weekday, shift code / times, actual first-in / last-out, requested by | PARTIAL | AN-28: add `weekday`, `shift_code`, `shift_start`, `shift_end`, `actual_in`, `actual_out` (from the attendance record's `original_snapshot`), `requested_by` |

### 24.3.3 Attendance regularization cancellations (`/app/processes/search/attendanceregularizationcancel/search`, `attendanceregularizationcancel_100`)

Captured: empty register, same buttons as §24.3.2; Reports tab with the same 27 columns.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of requests to cancel an approved AR (each goes for approval; the day is reprocessed when approved) | Undo an approved regularisation | Only pending regularisations can be cancelled (`Regularisations::cancel` refuses others); an approved AR stays | MISSING | TX-9: `cancel_requested` on regularisations (requester or HR; approval through the Approval Center kind `regularisation_cancellation` or the workflow; on approval the day is reprocessed from the punches; payroll-locked days follow the lock rule) |
| 2 | Filters (AR date, request date, approved date, status), cards and detail as §24.3.2 | Same register chrome | — (follows row 1) | MISSING | TX-9 with TX-1 filters |
| 3 | Reports tab (same 27 columns) | Cancellation report | — | MISSING | AN-28 (dataset gains cancellation fields with TX-9) |

### 24.3.4 On duty requests (`/app/processes/search/onduty/search`, `onduty_96`; `app-on-duty-search`, `app-on-duty-process-card`, `app-on-duty-detail`)

Captured: empty register, "IMPORTS", split "On duty request ▾"; APIs `onduty/Request/ForAdmin`, `core/Project/All`;
Reports tab "Create View", "EDIT FILTER" (`employeeReport/46/4952`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of on-duty requests | HR sees every OD | Time-office requests with type On duty (one list) | PARTIAL → built (TX-1: preset view "On duty requests") | TX-1 preset entry "On duty requests" |
| 2 | Filters "On Duty start date", "On Duty request date", "On Duty approval date" | Windows | — | MISSING → built (TX-1: day-asked-for, raised and decided ranges) | TX-1 |
| 3 | Filter "Status" | State | Status filter | HAVE | — |
| 4 | Filter "On duty type" | OD reason type | `category` stored on the request (TO-3); no filter, not shown in the list | PARTIAL → built (TX-1: on-duty type filter and column) | TX-1 filter and column |
| 5 | Filter "Project" | OD for a project | — | MISSING | Blocked: projects module (matrix 21 PM-*) |
| 6 | Card: "Requested Date :", "OD Date :", "Project :", status | Summary | Date (to date, times), detail (part of day), status; no project | PARTIAL | Project with PM-*; rest HAVE |
| 7 | Detail: Request type (on-duty type), Requested check-in / check-out | What was asked | Category, part of day (full / halves / hours), from / to time | HAVE | — |
| 8 | Detail: "Project", "Project Task", "Category" (billable) | Time logged against a project | — | MISSING | Blocked: projects module (matrix 21 PM-*) |
| 9 | Detail: "City", "Office", "Work mode" | Where the duty was | — | MISSING | With SV-18 (matrix 13), optional |
| 10 | Detail: "Attachment" (download) | Supporting file | Attachments stored, not viewable from the list | PARTIAL → built (TX-1: detail view with documents) | TX-1 detail view |
| 11 | Detail: approver "Comments" + Approve / Reject; week-off / holiday alert | Decide | Approve / reject with note; request rules refuse OD on off days when the policy says so | HAVE | Day-status hint with TX-1 (as §24.3.2 #12) |
| 12 | Split "On duty request ▾" (on behalf) | HR raises an OD | "Raise on behalf": type On duty (from, to, part of day, times, type, reason, attachments) | HAVE | Several time slots a day: matrix 20 RQ-3 |
| 13 | "IMPORTS" (no import type linked) | — | — | N-A | Nothing to upload |
| 14 | Reports tab (On duty request) | OD report | Template `on_duty_requests` (`time_office_requests`) | HAVE | On-duty type field added with TX-14 |

### 24.3.5 On duty cancellations (`/app/processes/search/ondutycancel/search`, `ondutycancel_101`)

Captured: empty register, same buttons and Reports tab as §24.3.4.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of OD cancellation requests (filters and cards as §24.3.4) | Undo an approved OD with approval | Time-office requests type On duty, status "Cancellation requested" / "Cancelled"; Approve cancellation / Keep request | PARTIAL → built (TX-1: preset view "On duty cancellations") | TX-1 preset entry "On duty cancellations" |
| 2 | Reports tab (same report) | Cancellation report | `time_office_requests` has status and cancellation reason | HAVE | — |

### 24.3.6 Location requests (`/app/processes/search/locationapproval/search`, `locationapproval_347`; `app-location-approval-search` → `app-mark-attendance-card`; detail `app-location-inbox` → `app-attendance-location-view`)

Captured: cards (avatar, company, department, "Location status : Active", "Requested Date :", Approved), bulk note,
"Imports ▾" (import 66), "ACTIONS", "SHOW MORE"; API `mobile/checkin/Attendance/Request/ForAdmin`; Reports tab
(`employeeReport/66/2002`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of location requests | HR sees requested work locations | Location requests: employee, place, coordinates, needed until, reason, status; approve / reject / withdraw | HAVE | — |
| 2 | Filters "Request date", "Approval date" | Windows | — | MISSING → built (TX-1: raised and decided ranges) | TX-1 |
| 3 | Filter "Status" | State | Status filter | HAVE | — |
| 4 | Filter "Location status" + card "Location status :" | Is the approved location still active | Approval creates a geo location (`attendance_geo_fence_id`) with its own status; the request list does not show it | PARTIAL | TX-10 column / filter from the linked geo location |
| 5 | Card "Requested Date :" | Raised on | Not a column | PARTIAL → built (TX-1: "Raised" column) | TX-1 "Raised" column |
| 6 | Detail: Request date, Source (Web / Mobile), Coordinates, Address, IP address, Remarks, Photos, City, Office, Work mode | Evidence for the place | Name, latitude / longitude, radius, address, reason, valid from / until; no source, IP, photos, work mode | PARTIAL | TX-10: source and IP captured on the request; work mode with SV-18 |
| 7 | Detail: "Map", "Polygon area (View on map)" | Place on a map | Polygon supported on geo locations (typed points); requests are point + radius; no map | MISSING | Blocked: map provider (CA-8) |
| 8 | Detail: "You want to get location approval for — Forever / Specific (Days)" | Permanent or temporary approval | Valid-from / valid-until within the policy maximum (`temporary_locations`, TO-2) | HAVE | — |
| 9 | Approver "Comments", "Activity log" | Decide with a note | Approve / reject note; audit trail | HAVE | — |
| 10 | "Employees selected" + "Inactivate all"; "ACTIONS" | Retire many locations | One at a time | MISSING | TX-9 |
| 11 | "Imports ▾" (import 66 Geo work locations) | Upload places | Geo location upload | HAVE | §24.9 #2 |
| 12 | Reports tab (geo location request / approved geo location) | Report | No location-request or geo-location dataset | PARTIAL | Matrix 21 §21.2.2 Time Office #1, #15 (AN-28) |

### 24.3.7 Work location (Geo Based) (`/app/processes/search/geolocation/search`, `geolocations_661`; `app-geo-location-search`, `app-approved-locations-flyer`)

Captured: one card per employee (avatar, company, department, "DOJ :", "Locations mapped : n"), "Imports ▾" (import 66),
"ACTIONS", "SHOW MORE"; API `mobile/checkin/EmployeesFromGeoLocationForAdmin`; Reports tab (`employeeReport/66/2002`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: one card per employee with joining date and number of locations mapped | Who can check in where | Geo work locations list is per place (name, applies to: work location / one employee / everyone, coordinates, area, valid, source, status); no per-employee view or count | PARTIAL | TX-10: "Locations by employee" view (employee, joining date, locations that apply: own + work location's + everyone's, count) |
| 2 | Flyer list of an employee's approved locations: "Requested on", "Approved on", "Source", "Co-ordinates", SHOW MORE | One person's places | Geo locations (filter by status only; no employee filter) | PARTIAL | TX-10 (employee filter; requested / approved dates from the linked request) |
| 3 | "Add location": Location name (`geoLocation`), Latitude, Longitude, range checks (−90…90, −180…180), Cancel | Add a place for the employee | Geo work location form: name, latitude, longitude, address, radius, polygon points, valid from / until, for a work location or one employee | HAVE | — |
| 4 | Add location: polygon drawing (`app-add-polygon`) and preview map (`app-polygon-preview-map`) | Draw the boundary | Boundary points typed in order; no map drawing or preview | PARTIAL | Blocked: map provider (CA-8) |
| 5 | Help "Get the latitude and longitude of a place: 1. Navigate to Google Maps 2. Enter the address … 3. Right-click on the map's pinpoint …" | Guide HR | — | MISSING | TX-10 helper text on the form (no provider named) |
| 6 | "Delete location" with "Please fill the reason in comment" | Remove a place | Status inactive on the form; no reason captured | PARTIAL | TX-10: "Inactivate" action with reason (audited); never deleted |
| 7 | "Imports ▾" (import 66) | Upload places | Geo location upload | HAVE | §24.9 #2 |
| 8 | Reports tab (approved geo location) | Report | No geo-location dataset | PARTIAL | Matrix 21 §21.2.2 Time Office #1 (AN-28) |

---

## 24.4 Overtime & comp off

### 24.4.1 Overtime requests (`/app/processes/search/overtime/search`, `overtime_241`; `app-overtime-search` → `app-overtime-card`; detail `app-overtime` → `app-overtime-detail`, `app-compoff-overtime-view`; `app-bulk-overtime-compoff-details`, `app-verify-overtime`)

Captured: empty register, "Imports ▾" (import 54), "ACTIONS"; API `Overtime/Request/ForAdmin`; Reports tab "Create View",
"EDIT FILTER" (`employeeReport/50/2022`). HROne overtime cards are generated from attendance (verification) or raised by
the employee.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of overtime (system-generated verification cards and claims) | One place for all OT to decide | Two places: recorded OT pending approval on attendance records (Exception centre, Approve OT / Reject OT) and overtime claims in Time-office requests | PARTIAL | TX-11: "Overtime" register over both sources (recorded OT with status, claims), one decision path each |
| 2 | Filters "Start date", "Request date", "Approval date" | Windows | Date range on attendance records; none on claims | PARTIAL → built for claims (TX-1: preset "Overtime requests" with day / raised / decided ranges); recorded OT with TX-11 | TX-1 / TX-11 |
| 3 | Filter "Status" | State | Claims: status; recorded OT: no overtime-status filter | PARTIAL | TX-11 filter on `overtime_status` |
| 4 | Card: "Requested Date :", "OT Date :", status | Summary | Date and status on both sources | HAVE | — |
| 5 | Detail: Shift, Check-in time, Check-out time, Working hours | The day behind the OT | On the attendance-record row (shift, in, out, worked); not on a claim | PARTIAL | TX-11: claims show the day's shift and punches |
| 6 | Detail: "Extra working hours" and "Pay overtime hours" (editable when the setting allows) | Approve fewer hours than worked | Approve OT with minutes editable ("Recorded: n minutes"); claims approved as claimed | HAVE | — |
| 7 | "IGNORED" status; "You have availed comp off so your OT verification task is not relevant now" | Only one of OT / comp-off per day | Comp-off is refused when OT was approved for the day; OT approval does not check an earned comp-off; no "ignored" state | PARTIAL | TX-11: one-benefit-per-day check both ways (attendance policy setting, default off = today) and an `ignored` outcome for the losing item |
| 8 | Note "The approval card will be generated within 15 minutes after submitting your request" | HROne queue timing | Requests appear at once | N-A | Vendor timing |
| 9 | "Activity log", "View workflow" | History | Audit trail; workflow when one runs | HAVE | — |
| 10 | Bulk OT / comp-off entry (`app-bulk-overtime-compoff-details`): From, To, date rows with tick (`isSelected`) and value, "Copy to below rows", Comment ("Type a reason..."), Save / Submit, result popup | Record OT (or comp-off) for an employee over many dates | Overtime upload (approved claims) or one claim at a time | PARTIAL | TX-11: "Record overtime / comp-off" form (employee, date rows with value, copy down, reason) through `TimeOfficeRequests::recordApproved` |
| 11 | "ACTIONS" *(inferred: bulk approve / verify)* | Decide many | Approval Center bulk approval for claims (P3); recorded OT one row at a time | PARTIAL | TX-9 |
| 12 | "Imports ▾" (import 54 Override overtime) | Upload OT | Overtime upload | HAVE | §24.9 #7 |
| 13 | Reports tab (Overtime request) | OT report | Templates `overtime_claims`, `overtime_this_month` | HAVE | — |

### 24.4.2 Comp off requests (`/app/processes/search/compoff/search`, `compensatoryoff_123`; `app-compensatory-off-search` → `app-compensatory-off-card`; detail `app-compensatory-off` → `app-compensatory-off-detail`; `app-verify-compensatory-off`)

Captured: cards (avatar, company, department, "Requested Date:", "CO Date:", Pending), "Imports ▾" (import 146),
"ACTIONS", "SHOW MORE"; API `CompOff/Request/ForAdmin`; Reports tab (`employeeReport/49/1999`, 21 columns).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of comp-off requests (generated from work on off days or raised) | HR sees every comp-off | Time-office requests type Comp-off claim; generated automatically when the time-office policy says so (`comp_off_auto`, auto-approve option, TO-4) | PARTIAL → built (TX-1: preset view "Comp off requests") | TX-1 preset entry "Comp off requests" |
| 2 | Filters "Generated for date", "Request date", "Approved date"; FILTERS / CLEAR ALL | Windows | — | MISSING → built (TX-1: day-asked-for, raised and decided ranges) | TX-1 |
| 3 | Card: "Requested Date:", "CO Date:", status | Summary | Date, detail (days, expiry), status | HAVE | — |
| 4 | Detail: Shift, Check-in time, Check-out time, Working hours, Extra working hours | Evidence of the work | On the attendance record, not on the claim | PARTIAL | TX-11 (as §24.4.1 #5) |
| 5 | Detail: "Compensatory off generated" | Days credited | Days and expiry on the claim; credit in the leave ledger (`comp-off-{claim}`) | HAVE | — |
| 6 | "IGNORED"; "You have availed OT so your comp off verification task is not relevant now" | One benefit per day | Comp-off refused when OT approved; no ignored state | PARTIAL | TX-11 |
| 7 | "ACTIONS" *(inferred: bulk verify / approve)* | Decide many | Approval Center bulk for switched-on kinds | PARTIAL | TX-9 |
| 8 | "Imports ▾" (import 146 Compensatory off) | Upload comp-off | Leave balance upload with the comp-off code (no source date, no expiry) | PARTIAL | §24.9 #13 |
| 9 | Reports tab: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Attendance date, Attendance day, Shift code, Shift timings, Punch in time, Punch out time, Working hours, Comp off hours, Comp off count, Request status, Request by, Request on | Comp-off report | `time_office_requests` (from, days, expiry, status, applied on, decided by) and template `comp_off_credits`; no weekday, shift, punches, working hours or comp-off hours | PARTIAL | AN-28: comp-off rows joined to the attendance day (weekday, shift, first in, last out, worked hours, minutes beyond the full-day threshold) |

---

## 24.5 Attendance

### 24.5.1 Attendance (`/app/processes/timeoffice/attendance`, `attendance_103`; `app-attendance-process-layout` → `app-attendance-process-grid`, `app-attendance-process-edit`)

Seen on the live panel (Attendance group, first item) and captured as `route_app_processes_timeoffice_attendance`
("NEW REQUEST", "SHOW MORE"; APIs `setting/leave/AllWorkSchedule`, `POST timeoffice/attendance`); not in the menu crawl.
Search rights on node "Attendance": View, Update, Inactive, Add punch, Delete punch. Imports 60, 41, 98; action 528.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of employees with the day's "IN" / "OUT" (cards, "SHOW MORE") | Daily attendance at a glance | Attendance records: employee, date, shift, status, in, out, worked, late, OT, exceptions, regularised; filters date range, status, department | HAVE | — |
| 2 | Work-schedule filter (API `AllWorkSchedule`) *(inferred)* | Attendance of one schedule | — (no shift / schedule filter on records) | MISSING → built (TX-1: shift and work-schedule filters on attendance records) | TX-1 shift and work-schedule filters on attendance records |
| 3 | Card "Details" → the employee's calendar | Open the month | Calendar is a separate page (pick the employee) | PARTIAL | TX-7: row action "Calendar" opening the employee's month |
| 4 | "Edit" (`app-attendance-process-edit`): From, To, "First half" status, "Second half" status, "Select all", CLOSE / CANCEL | Set the status of a range of days by half | Day overrides: one employee, one day, whole-day status (present, absent, half day, weekly off, holiday, WFH, on duty, field duty) and / or worked minutes, reason | PARTIAL | TX-6: overrides for a date range with first-half / second-half status |
| 5 | Right "Add punch" | HR adds a missed punch | "Manual punch" (employee, date-time, direction, note) and the day is reprocessed | HAVE | — |
| 6 | Right "Delete punch" | HR removes a wrong punch | — (punches are never removed; no exclusion) | MISSING | TX-7: "Exclude punch" with reason (kept as evidence, skipped by processing, day reprocessed; "Restore") — D-TX5 |
| 7 | Right "Inactive" *(inferred: inactivate a punch or a day's record)* | Take a record out of use | — | MISSING | TX-7 (exclusion covers punches; day records are recalculated, never inactivated) |
| 8 | Rights "View" / "Update" separately | Read-only vs edit access | `attendance.view` vs `attendance.manage` (manage also configures shifts, holidays, devices) | PARTIAL → built (TX-2: `attendance.override`, `attendance.punch`, `attendance.process`) | TX-2: `attendance.override` (day status / hours) and `attendance.punch` (add / exclude punches) inside `attendance.*` |
| 9 | "NEW REQUEST" | HR raises a request from the page | Time-office "Raise on behalf"; Regularise row action | HAVE | — |
| 10 | Imports (60, 98 Attendance status; 41 Attendance check-ins) | Upload days or punches | Monthly attendance upload, day override upload, punch imports | HAVE | §24.9 #1, #6, #12 |

### 24.5.2 Attendance calendar (`/app/processes/search/attendancecalendar/search`, `attendancecalendar_572`; register API `attendance/Employees/ForCalender`; `app-employee-attendance-flyer` → `app-emp-attendance`, `app-emp-punches-details-flyer`, `app-odd-punches-flyer`)

Captured: one card per employee (avatar, name, company, department), "Imports ▾", split "Change attendance status ▾",
"SHOW MORE"; Reports tab (`employeeReport/20/2019`, 15 columns).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of employees, each opening the month calendar | Browse calendars by person | Attendance calendar with an employee picker (`attendance.view`, in scope); no list | PARTIAL | TX-7: calendar reachable from Attendance records, Employees and the roster board rows |
| 2 | The employee calendar itself (month grid, legend, punch-source marks, summary, weekly hours breakup, late / early detail, day drawer, odd punches, visibility) | One person's month | Month grid, codes, in / out, late, holiday, shift, trip; totals; holidays; printable slip | PARTIAL | Rated field by field in matrix 22 §22.4 rows 1–22 (MS-11, RQ-3); not repeated |
| 3 | HR actions inside the day drawer: "Add punch" (date, time, In / Out, comments), "Cancel leave", "Reprocess attendance" ("( Updated By System )") | Correct a day from the calendar | Separate screens: Manual punch (header action of records), leave cancel on Leave requests, Reprocess on a record row | PARTIAL | TX-7: HR day drawer (punches with source, device, location, excluded mark; add / exclude punch; leave on the day with cancel; overrides; Reprocess) sharing RQ-3's drawer |
| 4 | Split "Change attendance status ▾" | Bulk status change | Day overrides one at a time | PARTIAL | §24.8 #9 (TX-6) |
| 5 | "Imports ▾" (60 Attendance status, 41 Attendance check-ins) | Upload | Monthly attendance upload, punch imports | HAVE | §24.9 |
| 6 | Reports tab: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Attendance date, Working hour, Shift code, Shift timings, Attendance status, Checkin timings | Daily attendance report | `attendance` dataset: date, status, shift, shift start / end, first in, last out, worked hours (+ employee fields); templates `attendance_today` / `_yesterday` | HAVE | All punches of the day ("Checkin timings") via `attendance_punches` |

### 24.5.3 Run attendance (attendance dashboard) (`/app/processes/search/processattendance/action`, `processattendance_106`; `app-process-attendance-layout` → `app-month-selector`, `app-pro-att-common-card`, `app-process-attendance-form`, `app-process-att-filter`, `app-res-arrear`, `app-review-emp-attendance`, `app-rea-*`, `app-reminders-card`, `app-warning-card`)

Captured (page and screenshot): title, "IMPORTS", "ACTIONS", month heading with "Change"; three step cards — Step 1
"Attendance Pre-Verification (Non-mandatory)" with "Download" and "Employee absent days count", "ACTIONS"; Step 2 "Calculate
attendance correction arrear (Mandatory)" with "Calculation completed", "Ready to calculate", "Total employee",
"CALCULATE"; Step 3 "Process Attendance (Mandatory)" with the same three counts and "PROCESS"; an "Error Type List"
(Attendance policy missing, Last month attendance not process) and "Reminders" (Attendance regularization request, Leave
request, On duty request, Restricted holiday request, Short leave request, Employees with missing shift, Employees who
joined after cut off date — counts, two with a download icon). APIs: employee type / status / other status, time-office
general and attendance settings, pay groups, month list, financial years, leave types, scheduler types,
`AttendanceProcess/Card/{m}/{y}`, `AttendanceProcess/PendingRequest/{m}/{y}`. Action rights (Action → Time office →
Attendance dashboard): Process attendance, View, Attendance pre-verification, Process arrear, Apply leave, Edit attendance.

PeopleOS processes attendance continuously (on each punch when `attendance.process_on_punch`, nightly at 02:00 for the
previous day, "Process day" / "Process dates" on demand); days are locked only when a payroll run is finalized; a
correction to a locked day becomes an attendance arrear when `attendance.allow_corrections_after_lock` is on. There is no
attendance month as an object, no close / reopen and no dashboard.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Title and month selector ("October 2026 · Change", `app-month-selector`) | Work on one attendance month | Pay-cycle months exist (`PayCyclePage`, `PayCycleReader`); no attendance-month page | MISSING | TX-3: "Run attendance" page per pay-cycle month (company-aware), permission `attendance.process` (TX-2) |
| 2 | Header "IMPORTS" | Uploads used before processing | Upload screens elsewhere | PARTIAL → TX-1 part built (upload links on Attendance records and the desk); the Run attendance page with TX-3 | TX-1 upload links on the page (§24.9) |
| 3 | Step 1 card "Attendance Pre-Verification (Non-mandatory)": "Employee absent days count", "Download" | Check absences before closing | Exception centre counts absent days (last 30 days, unlocked) and lists them; template `absentees_today`; no month count, no download of the month's absences | PARTIAL | TX-3 step 1: count and CSV of absent / half-day absent days in the month (from `attendance` records) |
| 4 | Step 1 "ACTIONS" → "Absent details": Select, Employee code, Employee name, Attendance status, Attendance Date, Type, Action; "Filter by" | Review each absence | Exception centre table filtered by exception "absent" (employee, date, shift, status, in, out …) | PARTIAL | TX-3 (selectable rows on WO-1's bulk framework) |
| 5 | "Apply leave for selected employees" (Select leave type) / "Apply leave" | Turn absences into leave in bulk | Leave applied on behalf one employee at a time | MISSING | TX-3: bulk "Apply leave" on selected absent days through `Leaves::request` + approval as HR (`leave.manage`), balance rules applied, refusals listed — D-TX7 |
| 6 | "Mark present" (selected days) | Turn absences into present in bulk | Day override one employee one day | PARTIAL | TX-3 / TX-6 bulk override (reason required) |
| 7 | Step 2 card "Calculate attendance correction arrear (Mandatory)": Calculation completed n, Ready to calculate n, Total employee n, "CALCULATE" | Work out arrears from corrections to processed months | Arrears are recorded automatically when a locked day changes (with the setting on) and listed in Attendance arrears (status pending → settled by payroll); no calculate step, no counts | PARTIAL | TX-3 step 2: counts of pending arrear rows for the month, "Review arrears" link; arrears stay automatic |
| 8 | Arrear dialog (`app-res-arrear`): "Select months for which you would like to process attendance correction arrear", "Calculate attendance correction arrear", "Pending Reviews", "process" / "unlock", "Please review following errors" | Choose which earlier months' corrections are paid now | Every pending arrear is settled in the next payroll (`AttendanceArrearInput`); no month choice, no hold | PARTIAL | TX-3: hold / release pending arrear rows per month (`attendance_arrears.status` gains `held`; default flow unchanged) |
| 9 | Arrear filter (`app-process-att-filter`): Pay Arrear (For) Month, Arrear Days, Attendance policy, Punch type, Search Employees / Add bulk employees, Organization, Location, Status, Employee other status, Type, Exclude Employees, APPLY / RESET | Narrow the arrear run | Attendance arrears: status filter only | MISSING | TX-3 filters (WO-2 group + month, arrear days, attendance policy, punch mode) |
| 10 | "Process attendance arrear" / "Unprocess attendance arrear"; columns "Arrear days", "Pay Arrear (For) month" | Release or withdraw arrears for the month | Pending → settled by payroll automatically | PARTIAL | TX-3 (hold / release as row 8); never after the payroll that settled them is finalized |
| 11 | Step 3 card "Process Attendance (Mandatory)": Calculation Completed n, Ready to calculate n, Total employee n, "PROCESS" | Calculate the whole month for everyone | "Process dates" (range ≤ 2 months, chosen employees or everyone, queued); no month counts | PARTIAL | TX-3 step 3: queue the month for the filtered set; counts processed / not processed (`not_processed` days) |
| 12 | "Process attendance" (closes the month: requests and corrections stop, the employee's calendar shows "ESS is locked") / "Unprocess attendance" (reopens) | Freeze attendance before payroll; reopen if needed | Days lock only when payroll finalizes; employees' requests for last month can be blocked by `request_lock` days (TO-4); no HR close or reopen | MISSING | TX-3: `attendance_month_closes` (company, month, status open / closed, closed by / at, reopened by / at, reason); closing marks the month's days closed (same effect as a lock for requests and overrides; corrections after close follow `allow_corrections_after_lock`); reopen allowed until a finalized payroll covers the month (D-TX1, D-TX6); payroll reads the state through a contract and warns, not blocks |
| 13 | "Your request to process in background has been submitted. Once processed, you will be notified through email and push notification." / "Okay, got it!" | Background run with a completion notice | "Process dates" queues jobs and reports the queued count; no completion notice | PARTIAL | TX-3: batch with progress and an in-app + mail notice on completion (notification event `attendance.month_processed`) |
| 14 | Processing filter (same `app-process-att-filter`: attendance policy, punch type, organisation, location, status, other status, type, exclude) | Process a subset | "Process dates": chosen employees or everyone | PARTIAL | TX-3 (WO-2 filter group) |
| 15 | Review employee's attendance (`app-review-emp-attendance`) step "Pending Request": "You can not proceed further until all pending request are closed. You can do the same from insights." | Block closing while requests are open | — | MISSING | TX-3: close is refused while time-office / regularisation / leave requests for the month are pending (setting `attendance.close_requires_no_pending`, default true when month close is on), with the list and links |
| 16 | Review step "Process Attendance" (tabs Attendance / Arrear) | Process one employee | Reprocess (row), "Process dates" for one employee | PARTIAL | TX-3 per-employee review panel |
| 17 | Review step "Summary" (`app-rea-attendance-card`): Working hours, Weekly Off hours, Holiday hours, National holiday hours, Current month overtime hour, Attendance month / year, Total arrear days, Paid days as per attendance process, Present days, Week days off, Holidays, Paid leave, Effective paid days; tabs OVERTIME / ARREAR / PAID DAYS / DETAILS; "Please review following errors" | Check one employee's month before payroll | `AttendanceOutput::summary` (days, paid days, half days, absent days, worked / scheduled minutes, late, early, approved OT, unprocessed days, penalty days) shown as calendar totals; hours by day type and arrear days not summarised | PARTIAL | TX-4: month summary per employee adds hours on working days / weekly offs / holidays, arrear days and "effective paid days" (after adjustments) |
| 18 | Editable summary counts (`numberOfPresent`, `numberOfWeekOff`, `numberOfHoliday`, `numberOfPaidLeave`) | HR sets the month's counts by hand | — | MISSING | TX-4 paid-day adjustment "set counts" mode |
| 19 | "Override Paid Days": "Please choose the override mode for paid days?" — "Manually set paid days" / "Automatically apply penalty"; "Comments" | Change a month's paid days for selected employees | Payroll adjustment "Loss of pay (days)" adds LOP days inside payroll; nothing on the attendance side | PARTIAL | TX-4 `attendance_paid_day_adjustments` (employee, month, mode set / reduce, values, reason), read by `AttendanceOutput::summary` as quantities — D-TX2 |
| 20 | Override help: "How is it calculated? Paid Days = Sum of Present day, paid leave, holiday, and week off after deduction", worked example, "Decimal number will be rounded as configured in setting for partial deduction" | Explain the formula | — | MISSING | TX-4 help text (wording uses "reduction"); rounding from the attendance policy's paid-days round-off |
| 21 | Override inputs per category — Present days / Paid leave / Holiday / Week off: "No deduction" / "Partial deduction" / "Full deduction", "How much % you want to deduct partially", "No. of present days / paid leave / holiday / week off", "Unpaid weekoff", "Total no. of paid days will be", "Employees selected", "Edit", "Apply" | Reduce chosen day categories by a percentage | — | MISSING | TX-4 "reduce" mode: per category none / partial % / full, preview of resulting paid days per employee, applied to selected employees |
| 22 | "Attendance penalty" (action) | Apply the policy's late / early penalties for the month | Late-mark penalties are applied automatically on every processed day (warn / half / full day per N late marks) and can be waived per row | HAVE | — |
| 23 | Error "Attendance policy missing" (count) | Employees no attendance policy applies to | — (the defaults apply silently) | MISSING | TX-3 readiness check: employees in the month with no `attendance` / `time_office` policy resolved |
| 24 | Error "Last month attendance not process" (count) | Previous month still open | — | MISSING | TX-3 (with month close): previous month not closed / days not processed |
| 25 | Reminders with counts (and download for the first two): Attendance regularization request, Leave request, On duty request, Restricted holiday request, Short leave request | Open requests that affect the month | Approval Center counts for the approver; exception centre "regularisations pending"; no month-scoped list or download | PARTIAL | TX-3 reminders card: pending items whose dates fall in the month, by type, with CSV |
| 26 | Reminder "Employees with missing shift" | People without a shift | Exception `no_shift` on processed days; no count of employees without a work schedule | PARTIAL | TX-3: employees employed in the month with no schedule assignment or roster |
| 27 | Reminder "Employees who joined after cut off date" | Joiners whose first days fall after the cycle cut-off | — (pay cycle known; no list) | MISSING | TX-3 list from `PayCycleReader` and joining dates; the pay-in-next-month rule stays a payroll setting (payroll matrices) |
| 28 | Action rights: Process attendance, View, Attendance pre-verification, Process arrear, Apply leave, Edit attendance | Grant each step separately | All under `attendance.manage` | PARTIAL → built (TX-2: `attendance.process`, `attendance.arrears`, `attendance.override`; the steps of TX-3 check them) | TX-2: `attendance.process` (steps 1 and 3, close / reopen), `attendance.arrears` (step 2), `attendance.override` (edit), `leave.manage` for "Apply leave" |
| 29 | Scheduler types (API `scheduler/SchedulerType`) *(inferred: schedule the processing)* | Run processing on a schedule | Nightly `peopleos:attendance:process` (02:00, previous day) | HAVE | — |

### 24.5.4 Paid days (`/app/processes/search/attendanceytd/search`, `attendanceytd`; `app-attendance-ytd-search` → `app-attendance-ytd-grid`, `app-attendance-ytd-details`, `app-att-ytd-deduction`)

Captured: "Imports ▾" (import 44 Override paid days), "ACTIONS"; APIs employee type / status / other status, time-office
general and attendance settings, pay groups, month list, financial years; Reports tab with "CREATE VIEW" only. The grid
was empty for the crawl account; fields come from components.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: one card per employee with paid days for the month / year *(inferred)* | Paid days of everyone before payroll | Paid days per employee on the calendar (one at a time) and on payroll entries after a run | MISSING | TX-4 "Paid days" register for a pay-cycle month: present, paid leave, unpaid leave, holidays, weekly offs, absent, penalty days, arrear days, adjustments, paid days; FY-to-date view |
| 2 | Filters from the page's APIs: pay group, month / year, financial year, employee type / status / other status *(inferred)* | Choose the population and period | — | MISSING | TX-4 (month, FY, WO-2 filters); pay group with the payroll pay-group decision |
| 3 | Details flyer (`app-attendance-ytd-details`): month / year select and the value | One employee's paid days by month | Calendar totals for one month | PARTIAL | TX-4 per-employee breakdown; self view is matrix 22 §22.14.3 (MS-11) |
| 4 | Breakdown table (`app-att-ytd-deduction`): Attendance Month, Arrear Days, Attendance Date, Value, Reason, Leave Code, Leave Name ("NO DATA FOUND") | Why paid days are lower: penalties and arrears per day | Late-mark penalties (date, late marks, action, days, waived) and attendance arrears (date, before / after, paid days) as separate HR lists | PARTIAL | TX-4: one breakdown per employee and month (penalty days, arrear days, adjustments; leave code when a penalty takes leave) |
| 5 | "Change" on a breakdown row | Correct the day | Regularise / override from other screens | PARTIAL | TX-7 (day drawer from the row) |
| 6 | "ACTIONS" *(inferred: Override paid days)* | Adjust paid days | — | MISSING | TX-4 (as §24.5.3 #19–21) |
| 7 | "Imports ▾" (import 44 Override paid days) | Upload paid-day adjustments | — | MISSING | §24.9 #5 |
| 8 | Reports tab: "CREATE VIEW" (no default report) | Paid-days reports | Templates over `payroll` (paid days, LOP days; "Monthly paid day", "Paid day YTD") — after a payroll run | HAVE | Matrix 21 §21.2.2 Time Office #35, #41; pre-payroll figures come with TX-4's dataset `attendance_paid_days` |

---

## 24.6 Leave

### 24.6.1 Leave requests (`/app/processes/search/leave/search`, `leave_98`; `app-leave-search` → `app-leave-process-card`; detail `app-leave` → `app-leave-detail`)

Captured: cards (avatar, company, department, "Requested Date :", "Leave type :" (code), "Leave Date :" from – to, status
Pending), note "In order to do bulk cancel of leave, apply status filter.", "Imports ▾" (import 134), split "Leave request
▾", "SHOW MORE"; API `request/employee/leave/ByAdmin/Search`; Reports tab (`employeeReport/43/4946`, 24 columns).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of leave requests | HR sees all leave | Leave requests: employee (+ code), type, from, to, days, reason, status, reviewed by, requested (toggle) | HAVE | — |
| 2 | Filters "Leave start date", "Leave end date" | Leave in a window | "Period" filter (from / until, overlapping) | HAVE | — |
| 3 | Filters "Request date", "Approved date" | Raised / decided in a window | — | MISSING → built (TX-1: raised and decided ranges) | TX-1 |
| 4 | Filter "Leave type" | One type | Type filter | HAVE | — |
| 5 | Filter "Status" | State | Status filter (multi) | HAVE | — |
| 6 | Search with suggestions and "GO" (`app-leave-search`) *(inferred)* | Find an employee's leave | Name search | PARTIAL → built (TX-1: WO-2 employee search) | WO-2 (TX-1) |
| 7 | Card: "Requested Date :", "Leave type :", "Leave Date :", status | Summary | Columns as row 1 | HAVE | — |
| 8 | Detail: "No of days", "Remaining balance" | Impact on the balance | Days column; remaining balance shown in the Approval Center impact, not on the register | PARTIAL → built (TX-1: detail view with the remaining balance) | TX-1 detail view with the balance after the request |
| 9 | Detail: "Compoff Dates", "Comp Off generated from:" | Which worked days a comp-off leave uses | — | MISSING | Matrix 20 RQ-4 (comp-off source dates) |
| 10 | Detail: "Attachment", "Comments", approver remarks + Approve / Reject | Decide with evidence | Approve / reject with note; the attached document is not viewable from the register | PARTIAL → built (TX-1: detail view with the document link) | TX-1 detail view (document link) |
| 11 | "Cancel leave" (row) | Cancel an employee's leave | Cancel; cancellation request, Approve cancellation, Keep leave | HAVE | — |
| 12 | "Employees selected" + "Cancel all" (bulk cancel, status filter first) | Cancel many leaves | — | MISSING | TX-9 bulk cancel (reason; each through `Leaves::cancel` / cancellation rules; locked days refused) |
| 13 | Split "Leave request ▾" (on behalf) | HR applies leave for an employee | "Apply on behalf" (employee, type, from, to, sessions, reason, document) | HAVE | — |
| 14 | "Imports ▾" (import 134 Leave requests) | Upload leave | Leave history upload | HAVE | §24.9 #16 |
| 15 | Reports tab: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Request type, Leave type, Leave count, From, To, Shift timings, Remarks, Request status, Request by, Request on, Approved by, Approved on, Approver remark, Approver code, Leave category | Leave report | `leave` dataset: from, to, month, type, code, paid, sessions, days, status, reason, applied on, reviewed by / on, review note (+ employee fields); no request kind (leave / cancellation), shift times, requested by or approver code; "Leave category" ≈ paid / unpaid | PARTIAL | AN-28: `request_kind`, `requested_by`, `approver_code`, shift times of the first day |

### 24.6.2 Leave cancellations (`/app/processes/search/leavecancel/search`, `leavecancel_102`)

Captured: cards (avatar, company, department, "Requested Date :", "Leave type :", "Leave Date :", status Pending / Approved),
"Imports ▾" (134), split "Leave request ▾"; API `leave/Cancellation/Admin/Search`; Reports tab (same 24 columns).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of leave-cancellation requests (pending and decided) | HR sees cancellations | A cancellation request is a state of the leave (`cancel_requested_at`; Approval Center kind `leave_cancellation`); the register cannot list cancellations (no filter for "cancellation requested", no list of decided ones) | PARTIAL → built (TX-1: preset "Leave cancellations", cancellation filter) | TX-1 preset entry "Leave cancellations" (filter on cancellation requested / cancelled) |
| 2 | Card: cancellation "Requested Date", leave type, leave dates, status | Summary | Leave dates and type; cancellation requested date not shown | PARTIAL → built (TX-1: "Cancellation requested" column) | TX-1 column |
| 3 | Approve / reject the cancellation | Decide | Approve cancellation / Keep leave | HAVE | — |
| 4 | Reports tab (same 24 columns) | Cancellation report | Template `rejected_cancelled_leave`; no cancellation-requested date or decider | PARTIAL | AN-28 (`cancel_requested_at`, `cancellation_reviewed_by`) |

### 24.6.3 Leaves balance (`/app/processes/search/leavebalance/search`, `leavebalance_105`; `app-leave-balance-search` → `app-leave-balance-card`, `app-leave-balance-view`, `app-edit-leave-flyer`, `app-bulk-leavebalance-details`, `app-bulk-request-status-popup`)

Captured: one card per employee listing each leave type (name, code, "n available"), "Imports ▾" (42, 43), "ACTIONS",
"SHOW MORE"; API `Employee/leaveBalance/Search`; Reports tab (`employeeReport/54/2010`). Search rights: View, Update.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register: per employee, each leave type with code and available days | Balances of everyone | Balances: employee, type, year, opening, accrued, adjusted, used, pending, encashed, lapsed, closing, available (a row per type) | HAVE | — |
| 2 | Filters (leave type, year, workforce filters) | Narrow | Type and year filters | PARTIAL → built (TX-1: WO-2 employee filters on balances) | WO-2 organisation filters (TX-1) |
| 3 | "Edit" (`app-edit-leave-flyer`): Type of leaves, "Add Leave" / "Subtract Leave", Leave (count), Remarks | Adjust one balance | "Adjust": days (+ / −, halves), note | HAVE | — |
| 4 | "Details" → ledger (`app-leave-balance-view`): Year, Transaction date, Action type, Leave count, Reason | Movements behind a balance | Leave transactions: date, code, employee, type, period, movement, days, note, operation; filters movement, type, period | HAVE | — |
| 5 | Details: month table "Leave month name", "Balance added", "Balance subtracted" | Month-by-month movement | Ledger rows only; no month table | PARTIAL | Matrix 22 MS-12 (month table) and matrix 21 AN-28 `leave_month_balances` |
| 6 | Details: "Leave Donate" ("To whom do you want to donate?", "How many leaves you want to donate?", Remarks) from the HR view *(inferred: HR donates for an employee)* | Donation on behalf | Donations are raised by the donor; approve / reject / withdraw | PARTIAL | TX-8: HR "Donate on behalf" through `LeaveDonations` (same approval) |
| 7 | "ACTIONS" → bulk balance change (`app-bulk-leavebalance-details`): Type of leave, Adjust type "Add balance" / "Subtract balance", Leave count, Reason, Submit | Adjust many employees at once | Leave balance upload (kind adjustment); no on-screen bulk for selected employees | PARTIAL | TX-8: bulk "Adjust balance" for selected / filtered employees through `LeaveAdjustments::adjust` |
| 8 | Bulk result (`app-bulk-request-status-popup`): "Update status", "Total employees processed", "Success", "Failed", "Download error log", disclaimer | Outcome of the bulk change | Upload result file (row, action, status, errors) | PARTIAL | TX-8 (WO-1 result summary with an error CSV) |
| 9 | Rights "View" / "Update" on the node | Read vs adjust | `leave.view` vs `leave.manage` (manage also configures types, encashes, applies on behalf, cancels any request) | PARTIAL → built (TX-2: `leave.adjust`) | TX-2: `leave.adjust` (balance changes) inside `leave.*` |
| 10 | "Imports ▾" (42 Opening leave balance, 43 Leave balance) | Upload balances | Leave balance upload (opening once per employee / type / year, or adjustment) | HAVE | §24.9 #8–9 |
| 11 | Reports tab: Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Calendar month, Leave type, Add, Subtract | Monthly movement report | `leave_ledger` per entry (credit / debit); no month pivot | PARTIAL | Matrix 21 §21.2.2 Time Office #19–22, #28 (AN-28 + AN-24 pivot) |

### 24.6.4 Leave encashment requests and cancellations (`/app/processes/search/leaveEncashmentSearch/search`, `leaveencashment_1452`; `…/leaveencashmentcancel/search`, `leaveencashmentcancel_1508`; `app-leave-encashment-search`, `-process-card`, `-detail`)

Captured: empty registers ("No Leave Encashment", "No Cancel Leave Encashment"), "IMPORTS", split "Leave encashment request
▾"; APIs `setting/leave/Type`, `LeaveEncashment/Request/Search`, `…/Cancellation/Search`; Reports tabs with "CREATE VIEW".

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of encashment requests | HR sees encashments | Encashments: employee, type, year, days, reason, status, reviewed by; approve / reject / cancel | HAVE | — |
| 2 | Filters "Requested Date", "Approve date" | Windows | — | MISSING → built (TX-1: raised and decided ranges) | TX-1 |
| 3 | Filters "Status", "Leave type" | State and type | Status filter; no leave-type filter | PARTIAL → built (TX-1: leave-type filter) | TX-1 leave-type filter |
| 4 | Card: "Requested Date :", "Leave type :", "Leaves to encash :", status | Summary | Type, days, status (requested date not a column) | PARTIAL → built (TX-1: "Raised" column) | TX-1 "Raised" column |
| 5 | Detail: "Uploaded file", "Comments", approver comment / remarks | Evidence and decision | Reason and decision note; no attachment on encashments | PARTIAL | Matrix 20 RQ-4 (encashment attachment) |
| 6 | Split "Leave encashment request ▾" (on behalf) | HR raises an encashment that follows the approval path | Balances → "Encash": HR records and approves at once | PARTIAL | §24.8 #6 |
| 7 | Cancellations register: requests to cancel an approved encashment, with approval | Undo an encashment before payout | HR cancels directly (reason); the employee withdraws a pending one; cancelled rows visible through the status filter | PARTIAL → built (TX-1: preset "Encashment cancellations") | TX-1 preset "Encashment cancellations" (status cancelled); cancellation stays a direct HR action (matrix 20 §20.1.19 #2) |
| 8 | Pay month of the encashment (HROne encashment process: "Pay month") | Which payroll pays it | Approved encashments are paid in the next payroll run (`LeaveEncashmentInput`) | PARTIAL | TX-8: optional pay month on an approval (`pay_period_id`, null = next run, today's behaviour) honoured by the input |
| 9 | HR bulk encashment process (Payroll → Payroll processing → Leave encashment: leave type, pay month, "As per policy" / "Specify value", applicability, review, Process; cards "No of employees", "Leave encash by", "Leave encashment date", "Pay month"; "Unprocess") | Encash a leave type for many employees | One encashment per balance row | MISSING | Owned by the payroll operations matrix (Payroll → Leave encashment); listed here because it moves leave balances |
| 10 | "IMPORTS" (no import type linked) | — | — | N-A | Nothing to upload |
| 11 | Reports tab: "CREATE VIEW" | Encashment reports | Leave encashment report (`leave_encashments`) | HAVE | Matrix 21 §21.2.2 Time Office #26 |

### 24.6.5 Short leave requests and cancellations (`/app/processes/search/shortleave/search`, `shortleave_99`; `…/shortleavecancel/search`, `shortleavecancel_104`; `app-short-leave-search` → `app-short-leave-card`, `app-short-leave-detail`)

Captured: empty registers, "IMPORTS", split "Short leave request ▾"; API `shortLeave/Request/Admin`; Reports tab "Create
View", "EDIT FILTER" (`employeeReport/28/4948`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of short-leave requests | HR sees short leave | Time-office requests type Short leave (one list) | PARTIAL → built (TX-1: preset view "Short leave requests") | TX-1 preset entry |
| 2 | Filters "Start date", "Request date", "Approval date" | Windows | — | MISSING → built (TX-1: day-asked-for, raised and decided ranges) | TX-1 |
| 3 | Filter "Status" | State | Status filter | HAVE | — |
| 4 | Card "Requested Date :", status | Summary | Date, minutes, status | HAVE | — |
| 5 | Detail: "Start time", "End time" | Hours away | From / to time, minutes | HAVE | — |
| 6 | Detail: "Leave type" (leave taken when beyond the short-leave limit) | Which leave was debited | The policy debits `deduct_leave_code` on approval (`ShortLeaveBalanceBridge`, keyed `sl-{id}`); the request does not show it | PARTIAL → built (TX-1: detail view shows the leave debited) | TX-1 detail view (debited leave from the ledger key) |
| 7 | Approver "Comments" + Approve / Reject | Decide | Approve / reject with note | HAVE | — |
| 8 | Split "Short leave request ▾" (on behalf) | HR raises one | "Raise on behalf": type Short leave | HAVE | — |
| 9 | Cancellations register (filters and cards as above) | Undo an approved short leave with approval | Status "Cancellation requested" / "Cancelled" with type Short leave | PARTIAL → built (TX-1: preset view "Short leave cancellations") | TX-1 preset entry |
| 10 | "IMPORTS" (no import type linked) | — | — | N-A | Nothing to upload |
| 11 | Reports tab (Short leave request) | Report | Template `short_leave_requests` | HAVE | — |

### 24.6.6 Carry forward leaves (global action "Carry forward leaves", `leavecarryforward_253`; Action → Time office → Process leave carry forward; `app-leave-carry-forward-search` → `app-leave-carry-forward-view`, `app-leave-carry-forward-filter`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Filter flyer: Leave policy, Search Employees / Add bulk employees, Organization, Location, Status, Type; APPLY / RESET FILTERS; "Filters Applied", CLEAR ALL | Choose who to carry forward | — (year close runs for everyone) | MISSING | TX-8 year-end screen (leave year, leave policy / type, WO-2 filters) |
| 2 | Preview table: Select, Employee code, Employee name, Policy name, Leave type, No. of total leave, No. of leaves to be lapsed, No. of leaves to be carry forward; "Leave type" select; "Show" | See what will carry and lapse | `LeaveAccrual::closeYear` computes carry (all / zero / up to the limit) and lapse but posts at once; no preview | MISSING | TX-8: dry-run of `closeYear` returning per employee and type: closing, carry, lapse |
| 3 | "Carry forward" (selected) → "Leave carry forwarded successfully." / Ok | Post the carry forward | `peopleos:leave:accrue --close-year=YYYY` (command line, all employees, idempotent by key) | PARTIAL → TX-2 part built (right `leave.carry_forward`); the year-end screen with TX-8 | TX-8: post for the selected rows (`leave.carry_forward`, TX-2), background batch, audit as today |
| 4 | "Download" (preview) | Keep the list | — | MISSING | TX-8 CSV |
| 5 | "Pending actions" | Employees still to carry forward | — | MISSING | TX-8: count of employees with a closing balance and no carry / lapse entry for the year |

---

## 24.7 Holiday

### 24.7.1 Restricted holidays and cancellations (`/app/processes/search/restrictedholiday/search`, `restrictedholiday_97`; `…/restrictedholidaycancel/search`, `restrictedholidaycancel_971`; `app-restricted-holiday-search`, `-process-card`, `-detail`)

Captured: empty registers, "Imports ▾" (import 58), split "Restricted holiday request ▾"; API
`restricted/holiday/Request/ForAdmin`; Reports tab "Create View", "EDIT FILTER" (`employeeReport/48/2024`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register of restricted-holiday requests | HR sees RH choices | Time-office requests type Restricted holiday (one list) | PARTIAL → built (TX-1: preset view "Restricted holidays") | TX-1 preset entry |
| 2 | Filters "Restricted holiday date", "Request date", "Approved date" / "Approval date" | Windows | — | MISSING → built (TX-1: day-asked-for, raised and decided ranges) | TX-1 |
| 3 | Filter "Status" | State | Status filter | HAVE | — |
| 4 | Card "Holiday Name :", status | Summary | Detail column shows the holiday's name; status | HAVE | — |
| 5 | Detail: "Restricted holiday", "Availed", "Eligible" | Quota used vs allowed | Quota enforced (`restricted_holiday_quota`, joiner proration, FY / calendar year basis, TO-6); not shown to the approver | PARTIAL → built (TX-1: detail view "chosen N of M"; the request-form helper stays RQ-7a) | Matrix 20 RQ-7a ("taken N of M" helper) also on the TX-1 detail view |
| 6 | Approver "Comments" + Approve / Reject | Decide | Approve / reject with note | HAVE | — |
| 7 | Split "Restricted holiday request ▾" (on behalf) | HR chooses an RH for an employee | "Raise on behalf": type Restricted holiday (optional holidays of the employee's calendar) | HAVE | — |
| 8 | Cancellations register | Undo an RH with approval | Status "Cancellation requested" / "Cancelled"; cancellation refused for chosen roles once processed (`rh_cancel_locked_for`, TO-6) | PARTIAL → built (TX-1: preset view "Restricted holiday cancellations") | TX-1 preset entry |
| 9 | "Imports ▾" (import 58 Restricted holiday balance) | Upload RH balances | — | MISSING | §24.9 #11 |
| 10 | Reports tab (Restricted holiday request) | Report | Template `restricted_holiday_requests` | HAVE | — |

### 24.7.2 Change holiday — Manage holiday (global action "Change holiday", `assignholiday_1160`; Action → Time office → Manage holiday; `app-assign-holiday-popup` → `app-assign-holiday-form`, `-step1`, `-step2`, `app-holiday-bank`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Choose your preference": "Assign holiday" / "Remove holiday" / "Replace holiday"; "Holiday" select | Give, take away or swap a holiday for chosen employees | Holidays come only from the employee's calendar (calendars apply by rules); no per-employee change | MISSING | TX-12 `employee_holiday_changes` (employee, date, action add / remove / replace-with, holiday name, restricted flag, reason) read by `HolidayResolver` after the calendar — D-TX3 |
| 2 | Step 1 "Select Employee": Add bulk employees (codes), Search Employees, Organization, Location, Status, Type | Pick the people | — | MISSING | TX-12 (WO-2 selector) |
| 3 | Step 2: "Is this a restricted holiday?", "Holiday name", "Holiday date", "ADD HOLIDAY" | Define the holiday to add | — (holidays are added to a calendar for everyone on it) | MISSING | TX-12 |
| 4 | Step 2 note: "If you add a holiday from two months prior to the current month, it will be available in the holiday bank but not in the list below. If you want to add it, please contact your administrator." | Back-dating limit | — | MISSING | TX-12 setting `attendance.holiday_change_backdate_months` (default 2); changes on payroll-locked days follow the lock rule |
| 5 | Holiday bank (`app-holiday-bank`): Year, list (Holiday name, Holiday date, National / Holiday), "EDIT HOLIDAY", "NEW HOLIDAY" / "Add new holiday", "Select All", Save, Close | Reusable holiday list to pick from | Holiday library (platform lists by country / region; the tenant adopts entries into a calendar with "Add from holiday library"); tenants add their own holidays on a calendar | HAVE | — |
| 6 | Stepper Next / Previous; bulk-process popup ("Please hold on…", "Change") | Run the change | — | MISSING | TX-12 |
| 7 | Team version (`app-assign-holiday-from-team`: Employees selected, Assign / Remove / Replace holiday, Employee Name, Company, Business unit, Select all, Holiday) | Managers change holidays for their team | — | MISSING | Matrix 20 RQ-11 (`team.holiday_assign`, default off) on TX-12's service |

---

## 24.8 HR Desk actions — Action → Time office [12] and the Time Office global actions (`globalAction`)

Each Action node with its grantable right, the global action that opens it (Search for actions, the "Looking for something
else" menu and the split buttons) and its dialog. "GO TO FORM" switches from the employee picker to the form.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Attendance regularization — "Request on behalf" (global "Attendance regularization request", form matrix 20 §20.1.8) | HR raises an AR that goes to the approver | HR "Regularise" on an attendance record: request + immediate approval when the user holds `attendance.manage`; no routed on-behalf request | PARTIAL → built (TX-1: "Raise on behalf" on Regularisations — approve now or send for approval) | TX-1: "Raise on behalf" on Regularisations with a choice "approve now" (today) / "send for approval" |
| 2 | Leave — "Request on behalf" (global "Leave request") | HR applies leave | Leave requests → "Apply on behalf" (`leave.manage`) | HAVE | — |
| 3 | On duty — "Request on behalf" (global "On duty request") | HR raises OD | Time-office "Raise on behalf" (`attendance.manage`) | HAVE | — |
| 4 | Restricted holiday — "Request on behalf" (global "Restricted holiday request") | HR chooses an RH | Time-office "Raise on behalf" | HAVE | — |
| 5 | Short leave — "Request on behalf" (global "Short leave request") | HR raises short leave | Time-office "Raise on behalf" | HAVE | — |
| 6 | Leave encashment — "Request on behalf" (global "Leave encashment request", `leaveencashment_1470`) | HR raises an encashment for approval | Balances → "Encash" records and approves at once | PARTIAL | TX-8: "Request encashment on behalf" left pending for the approver (choice as row 1) |
| 7 | Change Shift — "Request on behalf" (global "Shift request", `changeshiftrequest_1471`) | HR raises a shift change | Time-office "Raise on behalf" (Shift change / Week-off change) | HAVE | — |
| 8 | Shift — "Assign shift" (global "Change shift", `shift_343`): `app-bulk-shift-change` (GO TO FORM, Search Employees, Add bulk employees, Organization, Location, Status, Type, ASSIGN SHIFT) → `app-bulk-shift-edit` (From, To, Shift, Weekly off, Select all, "Do you want to allow auto shift correction ?", "n employee selected", confirm, "Please hold on…") | Roster many employees at once | "Roster employees" (employees picked by name, from, to, one shift or weekly off, reason); no organisation filters or pasted codes, no weekly-off pattern, no auto-shift choice | PARTIAL | TX-5 (selector from WO-2; weekly-off weekdays; `auto_shift` flag; result summary) |
| 9 | Attendance — "Attendance status" (global "Change attendance status", `attendance_342`): `app-mark-attendance-search` (GO TO FORM, Add bulk employees, ATTENDANCE STATUS, Search Employees, Organization, Location, Status, Type) → `app-bulk-attendance-edit` (From, To, First half, Second half, Select all, confirm, "Please hold on…") | Set the status of many employees' days by half | Day overrides: one employee, one day, whole-day status; day-override upload for files | PARTIAL | TX-6 |
| 10 | Manage holiday — "Manage holiday" (global "Change holiday", `assignholiday_1160`) | Change holidays for chosen employees | — | MISSING | §24.7.2 (TX-12) |
| 11 | Process leave carry forward — "Leave carry forward" (global "Carry forward leaves", `leavecarryforward_253`) | Year-end carry forward with preview | Command-line year close | PARTIAL | §24.6.6 (TX-8) |
| 12 | Attendance dashboard — Process attendance, View, Attendance pre-verification, Process arrear, Apply leave, Edit attendance (global "Run attendance (attendance dashboard)", `processattendance_106`) | Month processing desk | Processing exists (nightly, per day, date ranges); no month desk, close or reopen | PARTIAL | §24.5.3 (TX-3) |
| 13 | "GO TO FORM" and the generic confirm dialog | Navigation inside the action | Modal forms | N-A | UI chrome |

---

## 24.9 Imports — HR Desk → Imports → Time office [16] and Team → Imports → Time office [5]

The import wizard, import log and import approval are rated once in matrix 23 §23.10.0 (WO-17: one upload picker,
.xlsx and the organisation's date format, fix online). PeopleOS time-office uploads: Time office uploads (row types leave
balances, leave history, shift roster, geo locations, overtime hours, day overrides), Monthly attendance uploads (day
codes for a pay-cycle month), Punch imports; all on the staged pipeline with P2 approval and Recent uploads. HROne's
template columns are served by the server and were not captured; rows are rated by the import's name.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Attendance check-ins | Load punches | Punch imports: employee code, timestamp, timezone, direction, external punch id, device code (duplicates skipped by fingerprint) | HAVE | — |
| 2 | Geo work locations | Load approved places | Geo location upload: name, latitude, longitude, radius, work location code, employee code, address; modes add and update / new / existing | HAVE | Polygons and valid dates in the upload with TX-10 |
| 3 | Shift | Load a roster | Shift roster upload: employee code, date, to date, shift code (WO = weekly off); modes | HAVE | Weekly-off weekdays and auto-shift columns with TX-5 |
| 4 | Override arrear days | Set arrear days by hand | — (arrears come only from corrections to locked days) | MISSING | TX-13 (with TX-3): upload of arrear-day rows (employee, month, days, reason) as `attendance_arrears` with source `upload` |
| 5 | Override paid days | Set a month's paid days by hand | — | MISSING | TX-13 (with TX-4): rows become paid-day adjustments (set or reduce) |
| 6 | Attendance status | Load day results | Monthly attendance upload (P, A, HD, WO, H, WFH, OD and leave codes per day; fill / correct / both; blank-day rule; pay-cycle month) and day override upload (status / worked minutes per day) | HAVE | Half-day statuses by half with TX-6 |
| 7 | Override overtime | Load approved overtime | Overtime upload: employee code, date, minutes or hours, reason → approved claims | HAVE | — |
| 8 | Opening leave balance | Go-live balances | Leave balance upload, kind "opening" (once per employee / type / year) | HAVE | — |
| 9 | Leave balance | Balance corrections | Leave balance upload, kind "adjustment" | HAVE | — |
| 10 | Overtime plan | Planned (pre-approved) overtime | — (no overtime planning) | MISSING | TX-11 / TX-13 with D-TX8 (`overtime_plans`; policy switch "overtime needs a plan", default off) |
| 11 | Restricted holiday balance | Per-employee RH quota | RH quota is per calendar (with joiner proration); no per-employee quota change | MISSING | TX-13: `restricted_holiday_quota_adjustments` (employee, year, ± count, reason) read by the quota check |
| 12 | Attendance status (second node) *(inferred: the day-wise variant next to the month sheet)* | Load day results | As row 6 | HAVE | — |
| 13 | Compensatory off | Load earned comp-offs | Leave balance upload with the comp-off leave code (no worked date, no expiry) | PARTIAL | TX-13: comp-off upload (employee, worked date, days, expiry) through `CompOffCredits` / `TimeOfficeRequests::recordApproved` |
| 14 | Override working hours | Set a day's worked hours | Day override upload (worked minutes) | HAVE | — |
| 15 | Override previous month overtime | Overtime for a month already processed | Overtime upload refuses payroll-locked days unless `attendance.allow_corrections_after_lock`; no overtime arrear | PARTIAL | TX-13: with the setting on, accepted rows on locked days become overtime arrears (quantity rows read by payroll's existing arrear input) |
| 16 | Leave requests | Load leave history | Leave history upload (employee code, leave code, from, to, sessions, reason) through `Leaves` | HAVE | — |
| 17 | Team → Imports → Time office [5]: Geo work locations, Attendance check-ins, Shift, Attendance status, Leave balance | Managers upload for their own team | Uploads are HR screens (`employee.import` + module permission); rows are not limited to the uploader's access scope | MISSING | TX-13: scope-limited uploads (rows outside the uploader's scope refused) for these five types, behind setting `team.uploads` (default off; matrix 20 §20.3.1 #17 keeps HR-only as today) — D-TX9 |
| 18 | Import wizard, log and approval for these types | Guided upload, history, checker | Staged pipeline per screen; Recent uploads; P2 approval | PARTIAL | Matrix 23 §23.10.0 (WO-17) |

---

## 24.10 Inbox Insights → Time office [19]

The Inbox Insights page (organisation-wide open-task counts, overdue, relieved owners, reminders, bulk archive) is rated
in matrix 21 §21.3 (AN-30, MISSING). Each row rates whether PeopleOS has the **task kind** AN-30 would count (as matrix 23
§23.11): HAVE = the kind exists and reaches its decision maker; PARTIAL = only in part or only on its own screen;
MISSING = no such task.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Attendance regularization | ARs to decide | Approval Center kind `regularisation` | HAVE | — |
| 2 | Attendance regularization cancel | AR cancellations to decide | — (approved ARs cannot be cancelled) | MISSING | TX-9 |
| 3 | Leave encashment cancel | Encashment cancellations to decide | HR cancels directly; no task | PARTIAL → built (TX-1: desk entry with the count of cancelled encashments) | By design (matrix 20 §20.1.19 #2); counts of cancelled encashments via TX-1 |
| 4 | On duty | ODs to decide | Approval Center `time_office` | HAVE | — |
| 5 | On duty cancel | OD cancellations | Approval Center `time_office_cancellation` | HAVE | — |
| 6 | Leave | Leave to decide | Approval Center `leave` | HAVE | — |
| 7 | Leave cancel | Leave cancellations | Approval Center `leave_cancellation` | HAVE | — |
| 8 | Short leave | Short leave to decide | Approval Center `time_office` | HAVE | — |
| 9 | Short leave cancel | Cancellations | Approval Center `time_office_cancellation` | HAVE | — |
| 10 | Restricted holiday | RH to decide | Approval Center `time_office` | HAVE | — |
| 11 | Restricted holiday cancel | Cancellations | Approval Center `time_office_cancellation` | HAVE | — |
| 12 | Compensatory off | Comp-off claims | Approval Center `time_office` (claims; generated ones auto-approved when the policy says so) | HAVE | — |
| 13 | Verify compensatory off | Confirm comp-off earned from attendance | Generated claims are approved once; no separate verification | PARTIAL | Matrix 20 RQ-10 / setup matrix 02 (`verify_comp_off`, default off) |
| 14 | Overtime | Overtime to decide | OT claims in the Approval Center; recorded OT pending approval only on attendance records / exception centre (not an Approval Center kind) | PARTIAL | TX-11: recorded OT pending approval as an Approval Center kind `overtime` (manager in scope) |
| 15 | Verify overtime | Confirm OT earned from attendance | As row 14 | PARTIAL | Matrix 20 RQ-10 (`verify_overtime`, default off) with TX-11 |
| 16 | Mark attendance | Check-ins awaiting approval | — | MISSING | TX-10 (`checkin_approval`) |
| 17 | Location approval | Location requests | Approval Center `location_request` | HAVE | — |
| 18 | Change shift | Shift / week-off changes | Approval Center `time_office` | HAVE | — |
| 19 | Leave encashment | Encashments to decide | Approval Center `leave_encashment` | HAVE | — |

---

## 24.11 Analytics → Time office [7], Team → Analytics → Time office [7] and Analytics → Dashboards → Time office

The seven analytics views are not in components (server-side widgets); meanings come from their names.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Daily checkin *(inferred: check-ins per day, by source)* | Check-in volume | Templates `punch_log_today`, `punch_failures`; dataset `attendance_punches` (source, device); no daily series | PARTIAL | TX-14 template "Check-ins per day by source" (and AN-3 punch-source tile) |
| 2 | Compensatory off trend | Comp-off earned / used by month | Template `comp_off_credits`; no monthly series | PARTIAL | TX-14 series from `leave_ledger` comp-off credits / debits (AN-3 row 8) |
| 3 | Overtime trend | Overtime hours by month | `attendance_monthly.overtime_hours`; templates for this month only | PARTIAL | TX-14 / AN-3 series by month |
| 4 | Planned vs unplanned leave *(inferred: applied ahead vs on / after the day)* | Leave discipline | `leave` dataset flag "Applied after the leave started"; template `backdated_leave`; no planned / unplanned split | PARTIAL | TX-14: field `planned` (applied ≥ N days ahead; setting `leave.planned_notice_days`, default 1) and a split template |
| 5 | On duty by type | OD days per on-duty type | `time_office_requests` has no on-duty type field | PARTIAL | TX-14: add `category` (on-duty type) to the dataset + grouped template |
| 6 | Project wise on duty hours | OD hours per project | — | MISSING | Blocked: projects module (matrix 21 PM-*) |
| 7 | Late and early count | Late arrivals / early departures | Templates `late_summary_this_month`, `early_leaving_this_month`; `attendance_monthly` late / early counts | HAVE | — |
| 8 | Team → Analytics → Time office (the same seven, for a manager's team) | Team view | Managers hold no analytics permission; team views wait for `team_safe` templates | PARTIAL | Matrix 20 RQ-11 (team reports) over TX-14's templates |
| 9 | Analytics → Dashboards → Time office (`/app/analytics/cxo-timeoffice`) | Time office dashboard | — | MISSING | Matrix 21 §21.1.2 (AN-3) |

---

## 24.12 Reports — HR Desk → Reports → Time office [44], the Time Office panel "Reports" page and Team → Reports → Time office [24]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | HR Desk → Reports → Time office [44] (Clocking time … Leave Balance Summary (Pivoted) V2) | The time-office report catalogue | Rated report by report in matrix 21 §21.2.2 "Time Office (44)": 30 HAVE, 14 PARTIAL (geo location / requests, arrear calculation, Form 25, bulk slip, late deduction by duration, month-wise leave balances (4), mobile check-in, monthly register pivot, monthly deductions, day roster) | PARTIAL | Matrix 21 AN-24 / AN-28; no report row is missing from matrix 21 |
| 2 | Time Office panel "Reports" page (`menu_Time_Office__Reports`): tabs Favourite, All, Shift, Checkins, Leave, Holiday, Attendance, History; per report: name, ".xlsx", description, "Download", "Open"; search | Report centre filtered to time office | Report library (category filter, run, CSV export, schedules) | PARTIAL | Matrix 21 §21.2.1 (favourites, history, xlsx: AN-*) |
| 3 | Team → Reports → Time office [24] (Clocking time, Daily attendance, Attendance register form 25, Late and early go, Leave balance, Monthly overtime detail, Monthly shift roster, Compensatory off request, Overtime request, Daily check in, Approved geo location, Leave request, On duty request, Monthly attendance register, Mobile check in request, Short leave request, Restricted holiday request, Attendance regularization request, Inconsistent punch report, Monthly working hour, Overtime register, Geo location request, Arrear attendance status days, Leave encashment report) | The same reports for a manager's team | Same templates exist for HR (status per matrix 21); managers have no report access | PARTIAL | Matrix 20 RQ-11 (`team_safe` templates under the manager's scope) |

---

## 24.13 Team → People → Action → Time office (11 rights) and Team profile → Time office (18 rights)

Team pages, team bulk actions and the per-member team profile are rated in matrix 20 §20.3.1–20.3.2 (RQ-11). The rows
below place the eleven time-office team actions and the two team-profile rights matrix 20 does not list.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Team actions Attendance regularization, On duty, Leave, Restricted holiday, Short leave, Leave encashment, Change shift (raise for a team member) | Manager raises requests on behalf | On-behalf forms need `attendance.manage` / `leave.manage` (HR); managers decide but cannot raise | PARTIAL | Matrix 20 §20.3.1 #16 (RQ-1 / RQ-11) |
| 2 | Team action "Assign shift" | Manager rosters the team | HR "Roster employees" only | PARTIAL | Matrix 20 §20.3.1 #10 (RQ-11 on TX-5's service, `team.shift_assign`) |
| 3 | Team action "Attendance status" | Manager marks attendance | HR day overrides only | PARTIAL | Matrix 20 §20.3.1 #9 (RQ-11 on TX-6) |
| 4 | Team action "Leave cancel" | Manager cancels a member's leave | Managers decide cancellation requests (`leave.approve`); cancelling a member's leave needs `leave.manage` | PARTIAL | RQ-11 (manager cancel within scope, setting-controlled) |
| 5 | Team action "Manage holiday" | Manager changes holidays for the team | — | MISSING | Matrix 20 §20.3.1 #8 (RQ-11 on TX-12) |
| 6 | Team profile → Time office: View leave balance, View attendance, View approved location, Attendance regularization, Attendance ytd, Compensatory off, Leave, Overtime, On duty, Holiday calendar, Location approval, Mark attendance, Restricted holiday, Shift, Short leave, Leave encashment | A member's time-office tabs | Rated in matrix 20 §20.3.2 rows 4–6, 9, 15, 19, 22–26, 28–29, 36–38 (mostly HAVE; YTD, comp-off, encashment, mark attendance, shift PARTIAL) | PARTIAL | Matrix 20 RQ-11 |
| 7 | Team profile → Time office: "Add punch", "Delete punch" | Manager adds or removes a member's punch | Manual punch is HR-only (`attendance.manage`); no exclusion | MISSING | TX-7 (exclude / add) with a team option (setting `team.punch_edit`, default off) under RQ-11 |

---

## 24.14 Smart search → Time office (5 rights)

HROne's smart search answers five ready questions from the search bar. The search bar itself is rated in matrix 20
§20.4.1 (RQ-13).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Often came late over last (90 days)" | Ranked late-comers | Template `late_summary_this_month` (this month only) | PARTIAL | TX-14: rolling-window template (90 days, top N by late days) reachable from search |
| 2 | "Take leaves most frequently (180 days)" | Frequent leave takers | `leave_availed_by_employee` (this year) | PARTIAL | TX-14: rolling 180-day count of leave requests per employee |
| 3 | "Take delayed action (90 days)" *(inferred: approvers slowest to decide)* | Slow approvers | Datasets carry "Pending for (days)"; no ranking of deciders by delay | PARTIAL | TX-14: decision-lag template over leave, regularisations and time-office requests (decided by, average days to decide) |
| 4 | "Clocked maximum hours (30 days)" | Longest hours | `work_hours_this_month` | PARTIAL | TX-14 rolling 30-day top N |
| 5 | "Clocked least hours (30 days)" | Shortest hours | `work_hours_this_month` | PARTIAL | TX-14 rolling 30-day bottom N |

---

## Counts

Rows of the tables in §24.1–§24.14 (the §0 building blocks and the §24.0 index are not counted), counted by script.

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 24.1 Time Office panel and shared register chrome | 22 | 6 | 9 | 6 | 1 |
| 24.2.1 Shift rosters | 12 | 5 | 7 | 0 | 0 |
| 24.2.2 Shift requests | 14 | 5 | 5 | 3 | 1 |
| 24.3.1 Mark attendance | 14 | 1 | 4 | 9 | 0 |
| 24.3.2 Attendance regularization requests | 15 | 5 | 5 | 4 | 1 |
| 24.3.3 Attendance regularization cancellations | 3 | 0 | 0 | 3 | 0 |
| 24.3.4 On duty requests | 14 | 5 | 4 | 4 | 1 |
| 24.3.5 On duty cancellations | 2 | 1 | 1 | 0 | 0 |
| 24.3.6 Location requests | 12 | 5 | 4 | 3 | 0 |
| 24.3.7 Work location (Geo Based) | 8 | 2 | 5 | 1 | 0 |
| 24.4.1 Overtime requests | 13 | 5 | 7 | 0 | 1 |
| 24.4.2 Comp off requests | 9 | 2 | 6 | 1 | 0 |
| 24.5.1 Attendance | 10 | 4 | 3 | 3 | 0 |
| 24.5.2 Attendance calendar | 6 | 2 | 4 | 0 | 0 |
| 24.5.3 Run attendance (attendance dashboard) | 29 | 2 | 16 | 11 | 0 |
| 24.5.4 Paid days | 8 | 1 | 3 | 4 | 0 |
| 24.6.1 Leave requests | 15 | 8 | 4 | 3 | 0 |
| 24.6.2 Leave cancellations | 4 | 1 | 3 | 0 | 0 |
| 24.6.3 Leaves balance | 11 | 4 | 7 | 0 | 0 |
| 24.6.4 Leave encashment requests and cancellations | 11 | 2 | 6 | 2 | 1 |
| 24.6.5 Short leave requests and cancellations | 11 | 6 | 3 | 1 | 1 |
| 24.6.6 Carry forward leaves | 5 | 0 | 1 | 4 | 0 |
| 24.7.1 Restricted holidays and cancellations | 10 | 5 | 3 | 2 | 0 |
| 24.7.2 Change holiday (Manage holiday) | 7 | 1 | 0 | 6 | 0 |
| 24.8 HR Desk actions (Action → Time office, global actions) | 13 | 5 | 6 | 1 | 1 |
| 24.9 Imports → Time office [16] + Team imports [5] | 18 | 10 | 3 | 5 | 0 |
| 24.10 Inbox Insights → Time office [19] | 19 | 13 | 4 | 2 | 0 |
| 24.11 Analytics → Time office [7] + team + dashboard | 9 | 1 | 6 | 2 | 0 |
| 24.12 Reports (HR [44] pointer, panel page, Team [24]) | 3 | 0 | 3 | 0 | 0 |
| 24.13 Team → Action / Team profile → Time office | 7 | 0 | 5 | 2 | 0 |
| 24.14 Smart search → Time office | 5 | 0 | 5 | 0 | 0 |
| **Total** | **339** | **107** | **142** | **82** | **8** |

By group: Panel chrome (§24.1) 22 rows (6 / 9 / 6 / 1) · Shift (§24.2) 26 rows (10 / 12 / 3 / 1) · Checkins (§24.3) 68 (19 / 23 / 24 / 2) · Overtime & comp off
(§24.4) 22 (7 / 13 / 1 / 1) · Attendance (§24.5) 53 (9 / 26 / 18 / 0) · Leave (§24.6) 57 (21 / 24 / 10 / 2) · Holiday
(§24.7) 17 (6 / 3 / 8 / 0) · HR Desk nodes outside the panel (§24.8–§24.14) 74 (29 / 32 / 12 / 1).

---

## Biggest gaps

1. **No attendance month desk, close or reopen** (§24.5.3 #1, #3–#16, #23–#27; §24.8 #12): PeopleOS processes days
   continuously and locks them only when payroll finalizes. HROne's "Run attendance" works on a month: pre-verification of
   absences (with bulk "apply leave" / "mark present"), arrear review, a processing run with counts and a completion
   notice, a close that freezes requests ("ESS is locked") and a reopen, readiness errors (no attendance policy, last month
   open, missing shift, joiners after cut-off) and a block while requests are pending.
2. **No paid-days register and no paid-days override on the attendance side** (§24.5.4, §24.5.3 #17–#21, §24.9 #4–#5): paid
   days are seen one employee at a time (calendar) or after a payroll run; HR can only add loss-of-pay days inside payroll;
   no "set counts", no per-category reduction with a percentage and preview, no explanation of the formula, no override
   upload and no arrear-days override.
3. **No bulk work on any time-office or leave register** (§24.1 #17, §24.2.2 #13, §24.3.1 #12, §24.3.6 #10, §24.4.1 #11,
   §24.4.2 #7, §24.6.1 #12): no bulk cancel of leave, bulk decisions on shift requests or overtime, bulk inactivation of
   locations.
4. **Rostering is a list of day overrides, not a roster** (§24.2.1 #1–#2, #5, #7; §24.8 #8): no employee × day board,
   weekly-off weekdays cannot be set with a shift for a range, automatic shift correction cannot be chosen for a rostered
   range, employees are picked only by name.
5. **Attendance status changes are one employee, one day, whole day** (§24.5.1 #4, §24.8 #9, §24.5.3 #6): HROne sets a
   date range for many employees with a status per half day.
6. **No approval of check-ins ("Mark attendance") and a thin location desk** (§24.3.1, §24.3.6 #4, #6, §24.3.7 #1–#2, #5–#6,
   §24.10 #16): check-ins outside every geo location are only flagged or rejected; photos, IP and coordinates collected on
   punches are never shown; no per-employee view of approved places; places are inactivated without a reason.
7. **Punches cannot be excluded and HR has no day drawer** (§24.5.1 #6–#7, §24.5.2 #3, §24.13 #7): a wrong punch can be
   neither deleted nor excluded; HR corrects a day from three different screens.
8. **An approved regularisation cannot be cancelled** (§24.3.3, §24.10 #2): only pending ARs can be withdrawn; time-office
   requests and leave already support cancellation with approval.
9. **Registers are generic lists** (§24.1 #1, #4–#12; the date filters of every request page; §24.2.2 #7–#9, §24.3.2 #9,
   §24.6.1 #8, #10): one "Time-office requests" list stands for seven HROne registers; no request / decision / effective
   date filters, organisation filters or employee-code search; no detail view with attachments, earlier shift, swapped
   weekly off, balance after the request or the leave debited for a short leave.
10. **Leave year-end and bulk balance work** (§24.6.6, §24.6.3 #6–#8, §24.1 #22, §24.6.4 #8): carry forward runs only from
    the command line for everyone (no preview, selection, download, pending count or alert); bulk balance changes only by
    upload; encashments always pay in the next payroll (no chosen pay month).

Also notable: per-employee holiday changes (§24.7.2); one overtime desk over recorded OT and claims, the "ignored" outcome
and bulk OT / comp-off entry (§24.4); remaining uploads — restricted-holiday balance, comp-off with expiry, previous-month
overtime, team-scoped uploads (§24.9); time-office analytics views and smart search (§24.11, §24.14); finer rights for
roster, overrides, processing, arrears, balance adjustment and carry forward (§24.2.1 #11, §24.5.1 #8, §24.5.3 #28,
§24.6.3 #9). Blocked elsewhere: project fields on on-duty (matrix 21 PM-*), maps and polygon drawing (CA-8), work mode /
office / city on check-ins and requests (SV-18).

---

## Owner decisions needed

| # | Decision | Proposed default (reproduces today's behaviour until chosen) |
|---|---|---|
| D-TX1 | Attendance month close separate from payroll finalization: may HR close (freeze) an attendance month before payroll, and does payroll require it? | Off (`attendance.month_close.enabled` = false): days lock at payroll finalization as today. When on: HR closes per company and pay-cycle month; payroll shows a warning, not a block, for a month not closed; reopen allowed until a finalized payroll covers the month |
| D-TX2 | Where a paid-days override lives: an attendance-side adjustment (quantity, before payroll, HROne's model) or only payroll's loss-of-pay adjustment (today) | Add attendance paid-day adjustments read by `AttendanceOutput` (quantities only); payroll's manual LOP stays for payroll-only corrections; the entry basis shows both and warns when both touch the same month |
| D-TX3 | Holidays for chosen employees (assign / remove / replace) on top of the calendar rules | Allowed as dated exceptions per employee (none exist by default); calendars stay the main rule; changes on payroll-locked days follow the lock rule |
| D-TX4 | Check-ins outside every geo location: add "accept after approval"? Who approves? May approving also approve the place? | New option `approve` (default stays `flag`); approver = the employee's manager in scope or a workflow on `attendance.checkin_requested`; approving the place is an explicit tick, forever or for N days within the policy maximum |
| D-TX5 | HROne "Delete punch" | Never deleted: "Exclude punch" with a reason (kept as evidence, skipped by processing, can be restored); device data untouched |
| D-TX6 | HROne "Unprocess attendance" / "Unprocess attendance arrear" after payroll has used the month | Not possible once a finalized payroll covers the month (finalized payroll is never reopened); later corrections become arrears under `attendance.allow_corrections_after_lock` |
| D-TX7 | HR bulk "Apply leave" on absent days | Created on behalf and approved by the HR user (`leave.manage`, reason required); balance, eligibility and negative-balance limits applied; refused rows listed, never forced |
| D-TX8 | Overtime plans (HROne "Overtime plan" upload and `allowOvertimePlanning`) | Not built until asked; if built, a time-office policy switch "overtime needs a plan" (default off) and `overtime_plans` per employee and date |
| D-TX9 | Managers uploading time-office data for their team (Team → Imports [5]) | Off (`team.uploads` = false, HR-only as matrix 20 decided); when on, only rows inside the uploader's access scope are accepted |

---

## Build packages (ordered)

Prefix **TX-**. Every package is additive and keeps today's defaults; bulk actions call the existing services per record
(authorised and audited one by one); new settings in `config/peopleos_setup.php` (Time office / Leave), new permissions in
`config/peopleos.php` then `php artisan peopleos:sync-permissions`; attendance and leave code keeps the "no payroll words,
no money" rule and payroll reads new quantities through `AttendanceOutput` / payroll inputs.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **TX-1 Time office desk and register depth** → built (2026-10-29; `docs/architecture/hrone-parity.md` "TX-1 / TX-2") — navigation cluster in HROne's order with preset entries over the existing lists (shift requests, OD / short leave / RH / comp-off and their cancellations, leave and encashment cancellations); shared employee search and organisation / lifecycle / joining filters (WO-2), pasted codes, exclude lists, saved views (WO-1); request-, decision- and effective-date filters; on-duty type, leave type, shift and work-schedule filters; "Raised" columns; detail views (infolists) with attachments, earlier / new shift, swapped weekly off, balance after a leave, leave debited by a short leave, the day's current status; "Report" and "Upload" header links; AR "raise on behalf" with "approve now / send for approval" | §24.1 #1, 2, 4, 6–9, 11, 12, 15, 19; §24.2.1 #12; §24.2.2 #1–4, 7–9; §24.3.1 #2; §24.3.2 #2–4, 6, 9, 12; §24.3.4 #1, 2, 4, 10; §24.3.5 #1; §24.3.6 #2, 5; §24.4.1 #2; §24.4.2 #1, 2; §24.5.1 #2; §24.5.3 #2; §24.6.1 #3, 6, 8, 10; §24.6.2 #1, 2; §24.6.3 #2; §24.6.4 #2–4, 7; §24.6.5 #1, 2, 6, 9; §24.7.1 #1, 2, 5, 8; §24.8 #1; §24.10 #3 | `TimeOfficeRequestResource`, `AttendanceRegularisationResource`, `LeaveRequestResource`, `LeaveEncashmentResource`, `LocationRequestResource`, `AttendanceRecordResource`, `LeaveBalanceResource` (filters, columns, view pages), a navigation cluster class, `Regularisations` (on-behalf without approval), `AttendanceActions` | WO-1, WO-2 (matrix 23) | M |
| 2 | **TX-2 Finer time-office rights** → built (2026-10-29; same section) — `attendance.roster`, `attendance.override`, `attendance.punch`, `attendance.process`, `attendance.arrears`, `leave.adjust`, `leave.carry_forward` (all inside `attendance.*` / `leave.*`, so HR admin and the time-office role keep today's access); policies and actions switch from `attendance.manage` / `leave.manage` to the narrower key with the old key still accepted | §24.2.1 #11; §24.5.1 #8; §24.5.3 #28; §24.6.3 #9; §24.6.6 #3 | `config/peopleos.php` (permissions, role templates), `AttendanceRecordPolicy`, `AttendanceConfigPolicy`, `LeaveRequestPolicy`, Filament actions | — | S |
| 3 | **TX-3 Run attendance: attendance month desk, close and reopen** — page per company and pay-cycle month with the three steps: absences (count, CSV, selectable list, bulk "apply leave" (D-TX7) and "mark present"), arrears (pending rows of the month, hold / release, filters), processing (queued run for the filtered population with progress and a completion notice); month close / reopen (`attendance_month_closes`; closed days refuse requests and overrides like locked days; reopen until a finalized payroll covers the month) behind `attendance.month_close.enabled` (D-TX1, D-TX6); readiness errors (no attendance policy, previous month open / unprocessed, employees without schedule, joiners after cut-off); reminders of pending requests in the month with CSV; close blocked while requests are pending (setting); payroll warning through a read contract | §24.1 #10; §24.5.3 #1, 3–16, 23–27; §24.8 #12; §24.9 #4 (with TX-13) | new `app/Filament/Pages/RunAttendance.php`, `app/Domain/Attendance/Models/AttendanceMonthClose.php` + migration, `AttendanceMonthCloses` service, `ProcessAttendanceRange` batch, `AttendanceArrear` (status `held`), `AttendanceArrearInput` (skip held), `RequestRules` / `AttendanceOverrides` / `Leaves` (closed-day check), `PayCycleReader`, a payroll read contract `AttendancePeriodState`, `config/peopleos_setup.php`, notification event `attendance.month_processed`, `DataInventory` | TX-2; D-TX1 | L |
| 4 | **TX-4 Paid-days register and paid-day adjustments** — "Paid days" register for a pay-cycle month and FY-to-date (present, paid / unpaid leave, holidays, weekly offs, absent, penalty days, arrear days, adjustments, paid days) with a per-employee breakdown; month summary adds hours by day type and arrear days; adjustments (`attendance_paid_day_adjustments`: set counts, or reduce per category none / partial % / full, with preview, reason, rounding from the attendance policy) read by `AttendanceOutput::summary` as quantities (D-TX2); help text in "reduction" wording; dataset `attendance_paid_days` | §24.5.3 #17–21; §24.5.4 #1–4, 6; §24.9 #5 (with TX-13) | new `app/Filament/Pages/PaidDays.php`, `app/Domain/Attendance/Models/PaidDayAdjustment.php` + migration, `PaidDayAdjustments` service, `AttendanceOutput`, `AttendanceDay`, `PayrollCalculator` basis (read only), analytics dataset, `DataInventory` | TX-3; D-TX2 | M |
| 5 | **TX-5 Roster board and bulk shift assignment** — employee × day board (week / month) of the resolved shift with leave / holiday / weekly-off marks, cell edit, select by WO-2 filters or pasted codes; "Assign shift" with weekly-off weekdays for the range and an `auto_shift` flag on rostered days (read by `ShiftResolver`); remove; result summary; roster upload gains the same columns; dataset `day_roster` shared with AN-28 | §24.2.1 #1, 2, 5, 7, 9; §24.8 #8; §24.13 #2 (with RQ-11) | new `app/Filament/Pages/RosterBoard.php`, `ShiftRoster::assignPattern`, `ShiftOverride` (`auto_shift`, migration), `ShiftResolver`, `ShiftRosterImports`, `ListShiftOverrides` | TX-1, TX-2 | L |
| 6 | **TX-6 Attendance status by range and half day** — "Change attendance status" for selected / filtered employees, a date range and a status per half (`attendance_overrides.first_half_status`, `second_half_status`, null = today's whole-day behaviour), through `AttendanceOverrides::set` per day with reasons and a result summary; row "Edit" on attendance records; manager / HR windows from setup TO-6 when built | §24.5.1 #4; §24.5.2 #4; §24.5.3 #6; §24.8 #9; §24.13 #3 (with RQ-11) | `AttendanceOverride` + migration, `AttendanceOverrides`, `AttendanceProcessor` (half statuses), `ListAttendanceOverrides`, `AttendanceActions`, `DayOverrideImports` (half columns) | TX-1, TX-2 | M |
| 7 | **TX-7 Punch management and the HR day drawer** — "Exclude punch" / "Restore" with reason (`processing_status = excluded`, skipped by `punchesBetween`, day reprocessed; D-TX5); HR day drawer from records, the calendar and the roster board: punches with source, device, coordinates, photos, excluded mark; add / exclude punch; the day's leave with cancel; overrides; Reprocess; "Calendar" row action; team option for managers (`team.punch_edit`, default off) | §24.5.1 #3, 6, 7; §24.5.2 #1, 3; §24.5.4 #5; §24.13 #7 | `PunchIngestion`, `AttendancePunch`, `AttendancePunchResource`, a shared day-drawer component with matrix 20 RQ-3, `AttendanceCalendar` | TX-2; RQ-3 (matrix 20) | M |
| 8 | **TX-8 Leave desk** — year-end carry-forward screen (leave year, policy / type, filters, dry-run of `LeaveAccrual::closeYear` with closing / carry / lapse, select, post in background, CSV, pending count) and an HR-desk alert before a leave year ends (`leave.carry_forward_alert_days`, default 0); bulk "Adjust balance" for selected employees with an error CSV; HR "Donate on behalf"; encashment "request on behalf" left pending (choice); optional pay month on an encashment (`pay_period_id`, null = next run) honoured by `LeaveEncashmentInput` | §24.1 #22; §24.6.3 #6–8; §24.6.4 #8; §24.6.6 #1–5; §24.8 #6, 11 | new `app/Filament/Pages/LeaveYearEnd.php`, `LeaveAccrual` (dry-run), `LeaveAdjustments`, `LeaveDonations`, `LeaveEncashment` + migration, `LeaveEncashmentInput`, `ListLeaveBalances`, `config/peopleos_setup.php` | TX-1, TX-2, WO-1 | M |
| 9 | **TX-9 Cancellations and bulk decisions** — cancellation of approved regularisations with approval (`cancel_requested`, Approval Center kind `regularisation_cancellation` or workflow; day reprocessed; locked days per the lock rule); bulk cancel of leave, bulk approve / reject of shift requests, overtime and comp-off, bulk inactivate of geo locations — each through its service on WO-1's bulk framework with typed-count confirmation and a result summary | §24.1 #17; §24.2.2 #13; §24.3.1 #12; §24.3.3 #1–3; §24.3.6 #10; §24.4.1 #11; §24.4.2 #7; §24.6.1 #12; §24.10 #2 | `Regularisations`, `AttendanceRegularisation` (status), `ApprovalCenter` (kind), `RegularisationWorkflowBridge`, register bulk actions, `GeoLocations` | TX-1, WO-1 | M |
| 10 | **TX-10 Check-in approvals and the location desk** — check-in policy option `outside_fence_action = approve` (D-TX4): pending punches not counted until approved; Approval Center kind `checkin_approval` (note, audit); register with request / decision dates, status and the resulting location's status; approving may save the place for the employee forever or N days; punch detail with source, coordinates (link out), nearest location and distance, IP, Wi-Fi, photos (private); "Locations by employee" view (joining date, places that apply, count) with an employee filter; inactivate a place with a reason; coordinate help text; source and IP on location requests | §24.3.1 #1–6, 8, 10, 11; §24.3.6 #4, 6; §24.3.7 #1, 2, 5, 6; §24.10 #16 | `CheckInGuard`, `AttendancePunch` (`approval_status`, migration), `PunchIngestion::punchesBetween`, new `CheckInApprovalSource` (tagged `peopleos.approval_sources`), `GeoLocations`, `GeoFenceResource`, `LocationRequestResource`, `AttendancePunchResource`, `config/peopleos.php` (policy option), `DataInventory` | TX-1; D-TX4 | M |
| 11 | **TX-11 Overtime and comp-off desk** — one overtime register over recorded OT pending approval and OT claims (status filter, the day's shift and punches on claims); recorded OT as an Approval Center kind `overtime` for the manager in scope; one-benefit-per-day check both ways (attendance policy setting, default off) with an `ignored` outcome; "Record overtime / comp-off" form (employee, date rows with values, copy down, reason) through `TimeOfficeRequests::recordApproved`; overtime plans only with D-TX8 | §24.4.1 #1–3, 5, 7, 10; §24.4.2 #4, 6; §24.9 #10; §24.10 #14, 15 (with RQ-10) | new `app/Filament/Pages/OvertimeDesk.php` (or a resource over both sources), `Regularisations::approveOvertime`, `TimeOfficeRequests`, `CompOffGenerator`, new `OvertimeApprovalSource`, `config/peopleos.php` (policy field) | TX-1; RQ-10 (matrix 20) | M |
| 12 | **TX-12 Holiday changes for chosen employees** — assign / remove / replace a holiday for selected employees (WO-2 selector; optional restricted flag; holiday from the library or typed), stored as `employee_holiday_changes` and read by `HolidayResolver` after the calendar (D-TX3); back-dating limit `attendance.holiday_change_backdate_months` (default 2); affected days reprocessed; team version for RQ-11 | §24.7.2 #1–4, 6, 7; §24.8 #10; §24.13 #5 | new model + migration, `EmployeeHolidayChanges` service, `HolidayResolver`, a page or `HolidayCalendarResource` action, `config/peopleos_setup.php`, `DataInventory` | TX-1, TX-2; D-TX3 | M |
| 13 | **TX-13 Remaining time-office uploads** — override arrear days (with TX-3), override paid days (with TX-4), restricted-holiday quota adjustments (`restricted_holiday_quota_adjustments` read by the quota check), comp-off credits with worked date and expiry (through `CompOffCredits`), previous-month overtime as overtime arrears when `attendance.allow_corrections_after_lock` is on, overtime plans (D-TX8); scope-limited team uploads for the five Team → Imports types behind `team.uploads` (D-TX9) | §24.9 #4, 5, 10, 11, 13, 15, 17 | new `RowImports` subclasses registered in `RowImportTypes` (area `time_office`), `HolidayResolver` (quota), `CompOffCredits`, `ImportGovernance` (scope check) | TX-3, TX-4, TX-11; WO-17 | M |
| 14 | **TX-14 Time-office analytics views and smart search** — templates: check-ins per day by source, comp-off trend, overtime trend, planned vs unplanned leave (`planned` field; `leave.planned_notice_days`, default 1), on duty by type (`category` in `time_office_requests`), rolling-window rankings (late in 90 days, leave frequency in 180 days, decision lag in 90 days, most / least hours in 30 days) reachable from search; team versions with RQ-11's `team_safe` flag | §24.11 #1–5, 8; §24.14 #1–5 | `ReportLibrary`, `TimeOfficeRequestsDataset`, `LeaveDataset`, `AttendanceMonthlyDataset`, `CommandSearch` shortcuts | AN-3 (matrix 21), RQ-13 (matrix 20) | S |

Not packaged here (owned by other matrices or blocked): request forms and calendar picking (matrix 20 RQ-3, RQ-4, RQ-7a),
the workflow tracker and inbox (RQ-2, RQ-8, RQ-9), comp-off / OT verification switches (RQ-10), team actions and team
reports (RQ-11), the employee's own calendar depth (matrix 22 MS-11, MS-12), report datasets and pivots (matrix 21 AN-24,
AN-28), the time office dashboard (AN-3), Inbox Insights (AN-30), the import wizard (WO-17), the HR bulk leave-encashment
process (payroll operations matrix), work mode / office / city on check-ins and requests (setup SV-18), project fields on
on-duty requests (matrix 21 PM-*), maps and polygon drawing (CA-8, map provider), pay groups (payroll matrices).
