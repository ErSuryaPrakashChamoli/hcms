# HROne parity programme

Closes the gaps recorded in [docs/hrone-parity/HROne-Gap-Register.md](../hrone-parity/HROne-Gap-Register.md), found by a read-only walk through the HROne portal (feature inventory and configuration catalogue in the same folder). Recruitment is out of scope: it stays with the external recruitment system behind the RMS boundary.

Rules every phase follows:

- **Additive.** Existing screens, flows and behaviour stay as they were; new behaviour is either new data (a new request type, an override) or a setting/policy whose default reproduces today's behaviour.
- **Configurable per client.** Options live in the Policy Engine (per employee group, versioned) or in typed tenant settings listed in the Setup centre; nothing client-specific is hard-coded.
- **Platform invariants.** New models are tenant-scoped, audited, access-scoped when employee-linked, classified in `DataInventory`, guarded by a policy, and shown on PeopleOS base pages (architecture tests enforce each).
- **Wiring in one place.** `App\Providers\ParityServiceProvider` registers this programme's policies, listeners and Approval Center sources.

## Setup centre (CF-01)

Configuration → **Setup** (`App\Filament\Pages\SetupCentre`, catalogue `config/peopleos_setup.php`, service `App\Domain\Configuration\Services\SetupCatalogue`). For each of 14 modules: typed settings (boolean, number, text, select, multiselect, roles; min/max and options validated server-side; only changed values written through `SettingsRepository::set` with the reason, so each is audited), the module's policy types with policy counts and a link to the filtered policy list, and the module's configuration screens (only those the viewer can open). A setting is listed only when code reads it; `SetupCentreTest` checks every declared key has a default, every policy type exists and every link resolves. Access: `settings.view` or `policy.view`; saving needs `settings.update`.

When a phase adds a setting or policy type, it adds it to the module's entry in `config/peopleos_setup.php`.

## Approval Center extension point

`App\Domain\Experience\Contracts\ApprovalSource` — sources tagged `peopleos.approval_sources` add items to the Approval Center and receive the decisions on them (`ApprovalCenter::sources()`, `ApprovalDecisions::extension()`). Items already running a workflow are left to the workflow task. Later phases (loans, claims, expenses, timesheets, transfers …) add a source instead of editing the center.

## HP-1 Time Office

### Data

| Table | Purpose |
|---|---|
| `time_office_requests` | Short leave, on duty (full/half/hours, multi-day), shift change, overtime claim, comp-off claim, restricted holiday. One approval path; `effects` records what approval did (previous shift overrides, comp-off ledger entry, expiry). |
| `attendance_shift_overrides` | Day-level roster: the shift (or weekly off) on a date, from HR, an approved shift change or an import. Read first by `ShiftResolver`. |
| `attendance_overrides` | HR override of a day's status and/or worked minutes. |
| `attendance_penalties` | Late-mark penalties (warn / half day / full day) from the attendance policy's `late_marks_threshold` and `late_mark_action`, per calendar month. |
| `attendance_arrears` | Paid-day differences for days locked by payroll and corrected afterwards (only with `attendance.allow_corrections_after_lock`). |
| `attendance_geo_fences`, `attendance_location_requests` | Geo work locations (shared, per work location, per employee) and employee requests for them. |
| `attendance_employee_devices` | Check-in devices per employee (approved / pending / blocked). |
| `leave_donations` | Leave given to a colleague; approval posts two ledger adjustments. |
| columns | `attendance_punches.geo_status / attendance_geo_fence_id / geo_distance_m / device_identifier`; `holiday_calendars.optional_requires_selection / restricted_holiday_quota`; `leave_encashments.cancelled_at / cancelled_by / cancel_reason`. |

### Engine

`AttendanceProcessor` keeps its precedence (approved full-day leave first) and adds, all inert without new data: approved full-day on duty acts like an on-duty regularisation on working days (weekly offs and holidays keep their status); half-day on duty halves the expectation like half-day leave; hourly on duty counts as worked time; approved short leave lowers the full-day requirement and excuses lateness then early leaving (never the overtime baseline); an approved overtime claim becomes the day's approved overtime; an HR override comes after the calculation (status wins outright; worked minutes re-derive a working day's status). Calculation and saving are split (`calculate()`), so a payroll-locked day can be worked out on a scratch record: the locked record is never rewritten, and a change in its paid quantity (`AttendanceDay::paidQuantity`) is kept as a pending arrear. `HolidayResolver` returns an optional holiday only to employees who chose it, on calendars that require a choice.

`LatePenalties::evaluate` runs on every `attendance.processed` event: every Nth late mark of the month puts the action on that day; waived penalties and locked days are left alone. `AttendanceOutput` reports applied penalty days per day (`AttendanceDay::$penaltyDays`) and payroll adds them to that day's loss of pay (never above one day). Attendance itself still computes quantities only.

`CheckInGuard` (called by `PunchIngestion::record`) annotates every web/mobile/API punch with its nearest geo location and distance, and enforces the employee's `checkin` policy: allowed sources, location required, inside a geo location (flag or reject), and single approved device (first device auto-approved when configured; one device cannot serve two employees). A rejected punch is stored with `processing_status = rejected` as evidence and is never counted (`punchesBetween` skips it); the API answers 422 with the reason. With no check-in policy nothing is enforced.

### Services

`TimeOfficeRequests` (request / approve / reject / cancel / approveCancellation / rejectCancellation; per-type checks against the `short_leave` and `time_office` policies; employee row lock against concurrent requests; one live request of a kind per day except short leave; no self-decisions), `ShiftRoster`, `AttendanceOverrides`, `LatePenalties`, `GeoLocations` (location requests, device review), `CheckInGuard`. Leave: `CompOffCredits` (credit keyed `comp-off-{claim}`, reversal refused once used, FIFO expiry by `peopleos:leave:expire-comp-off`), `LeaveDonations`, `LeaveAdjustments::cancelEncashment`. The leave domain hears `attendance.timeoffice_approved` / `_cancelled` (`CompOffClaimBridge`); workflows on any of these subjects decide them through `TimeOfficeWorkflowBridge` and `LeaveExtrasWorkflowBridge` in the name of the person who completed the last task.

Locked days: requests, overrides, roster changes, regularisations and leave on payroll-locked days are refused as before unless `attendance.allow_corrections_after_lock` is on; then they are accepted and the locked day yields an arrear.

### Configuration

Policy types `short_leave`, `time_office`, `checkin` (config `peopleos.policies.types`); settings `attendance.allow_corrections_after_lock`, `leave.donation.allowed_types`, `leave.donation.max_days_per_year`; holiday calendar fields for restricted holidays. All appear in the Setup centre (Time office, Leave).

### Screens

Attendance: Time-office requests (self-service and on behalf), Shift roster, Day overrides, Late-mark penalties, Attendance arrears, Geo work locations, Location requests, Check-in devices. Leave: Leave donations; Encashments gained employee requests and cancellation. Holiday calendars gained the restricted-holiday section. Pending items go to the Approval Center through `TimeOfficeApprovalSource`.

### Permissions

No new keys: employees raise requests with `attendance.regularise` (description widened) or `leave.apply`; approvers decide with `attendance.approve` / `leave.approve` (`leave.manage` for encashments) inside their access scope; HR runs the roster, overrides, geo locations and devices with `attendance.manage`.

### Tests

`tests/Feature/HroneParity/TimeOfficeTest.php` (each request type end to end, cancellation with and without approval, roster, overtime claim, comp-off credit/expiry, restricted-holiday quota, penalties into the output and waiver, overrides, arrears after lock, check-in policy, donation, encashment cancellation, Approval Center), `SetupCentreTest.php`. Existing attendance, leave and architecture suites pass unchanged.

## HP-1b Time Office configuration, uploads and reports

- **Uploads.** `App\Domain\Employment\Imports\RowImports` is the base of one-row-one-change uploads on the existing staged pipeline (`employee_imports`): a subclass declares fields, decides each row (`check`) and applies it through the domain service (`apply`); modes (`MODES`) work as for the other uploads. `RowImportTypes` lists them with the module permission each needs besides `employee.import` (read by `EmployeeImportPolicy` and `ImportActions`). Types: `leave_balances` (opening once per employee/type/year, or adjustment — comp-off and RH balances by leave code), `leave_history` (approved leave through `Leaves`), `shift_roster`, `geo_locations`, `overtime_hours` (approved claims via `TimeOfficeRequests::recordApproved`), `attendance_overrides`. Screen: Attendance → Time office uploads.
- **Reports.** Datasets `time_office_requests`, `attendance_penalties`, `attendance_arrears`, `checkin_devices`, `leave_donations` and twelve report-library templates (pending requests, reconciliation, short leave, on duty, comp-off, overtime claims, restricted holidays, shift changes, penalty detail and by department, arrear days, devices, donations).
- **Policy options.** Short leave: manager/HR back-dated windows, one per day unless allowed, beyond the monthly limit refuse or take leave (`ShortLeaveBalanceBridge` debits the configured leave on approval and credits it back on cancellation, keyed `sl-{id}`). Time-office: monthly limits per request type. Attendance: half day when late or early beyond N minutes. Leave: half-yearly accrual; `joiner_credit` for the joining slice (full — the default and previous behaviour — rule of 15 all/none, rule of 15 all/half, none, proportionate).
- **Settings.** `attendance.sandwich_rule` (none / weekly offs / weekly offs and holidays): an off day without punches between two absent or unpaid-leave days is absent (`sandwich` exception); a working day processed later re-decides the off days before it. `leave.block_on_present_day`.
- **Screens.** Attendance calendar (month grid with status, times and lateness; totals with paid and penalty days; holidays incl. optional ones chosen; printable slip), Attendance → Process dates (queued range recalculation), My encashments (employee self-service; the HR register keeps its access).

Deferred: auto shift by punch time and weekly-off swap requests (shift-rotation design), RH proration for joiners, Form 25.

## HP-2 Payroll money flows

### Extension points

- **Payroll inputs** (`App\Domain\Payroll\Contracts\PayrollInputSource`, tag `peopleos.payroll_inputs`, `App\Domain\Payroll\Inputs\PayrollInputs`). `PayrollCalculator::inputSources()` runs after approved adjustments and before statutory, so taxable lines are taxed and reimbursement lines are not. Each line's basis carries `source: {key, ids}`; `PayrollInputs::onFinalized` (on `payroll.finalized`) hands each source exactly the ids its lines carried, so a record is settled only when the run that paid it is final (finalized payroll is never reopened). With no such records no line is added and the engine version stays `payroll-2.5`. `PayrollInputs::dailyRate()` is the shared day rate (basic / basic+DA / gross full-month amounts over the run's divisor or a fixed number of days).
- **Settlement sources** (`App\Domain\Exit\Contracts\SettlementSource`, tag `peopleos.settlement_sources`). Full and final settlement adds their lines when it calculates and calls `settled()` when the settlement is paid.

### Sources and features

| Feature | Records | Paid / recovered by |
|---|---|---|
| Leave encashment payout (PY-11) | `leave_encashments` (approved) | `LeaveEncashmentInput`: taxable earning `LEAVE_ENC` at `payroll.encashment_basis` / `payroll.encashment_divisor`; settled → `paid` |
| Attendance arrears (TO-15) | `attendance_arrears` (pending, days of earlier months) | `AttendanceArrearInput`: `ATT_ARREAR` (taxable earning) or `ATT_RECOVERY` (deduction) at the gross day rate; settled → `settled` |
| Loans and salary advances (PY-01) | `loans`, `loan_installments` | `LoanDisbursementInput` (non-taxable `LOAN_PAYOUT` when paid through payroll) and `LoanRecoveryInput` (`LOAN_EMI` per loan, instalments due up to the month); `LoanSettlementSource` recovers the rest at exit and closes the loan |
| Variable pay (PY-05) | `variable_pay_requests` | `VariablePayInput`: the chosen earning component's own tax and PF/ESI treatment |
| Reimbursement claims (PY-02) | `reimbursement_claims`, `reimbursement_ledger_entries`; `salary_components.pay_on_claim` | A pay-on-claim reimbursement is not paid monthly: the calculator records the month's amount in `inputs.claim_accruals`, posted to the ledger on finalize. Claims are checked against the balance (ledger less claims waiting), approved (possibly for less), paid by `ReimbursementClaimInput` (non-taxable `CLAIM_<code>`), then posted as paid |
| Salary hold (PY-06) | `salary_holds` | `BankFile` leaves held employees out of the month's file until released (with the file, or with an outside payment reference) |

Loans follow the `loan` policy type (types offered, largest amount, most instalments, interest a year on a reducing balance, service, open loans, guarantor, payout by payroll or bank); `Loans` builds equal or EMI schedules, prepays and defers instalments (deferring adds one at the end). Requests, claims and variable pay are decided in the Approval Center (`PayrollRequestsApprovalSource`) or on their screens (`payroll.manage`, in scope, never one's own); employees raise them with the new permission `payroll.request` (employee and manager role templates).

Settings (Setup → Payroll): `payroll.encashment_basis`, `payroll.encashment_divisor`, `payroll.claims.attachment_required`, `payroll.claims.allow_beyond_balance`; policy type `loan`.

Screens: Payroll → Loans & advances (with the instalment schedule), Variable pay, Salary holds, Reimbursement claims (each also under Me for employees); salary component form gained "Paid on claim".

## HP-3 Tax and statutory

All of it lives in the Compliance domain (statutory logic stays out of Payroll services; statutory values come only
from rule versions). Every addition is inert until a record or a setting exists, so earlier payroll results are
unchanged (engine version stays `payroll-2.5`; new tax-basis keys appear only when they apply).

| Feature | Records | Behaviour |
|---|---|---|
| Proofs feed TDS (PY-12) | `tds_employee_investments.proof_files`, `proof_claimed_amount`, `proof_submitted_at` | Employees submit proofs in the proof window (Me → My investment proofs); payroll verifies or rejects (`InvestmentProofs`). From `payroll.tax.verified_proofs_from`, `TaxDeclarationAmounts` gives TDS the verified amounts only (payroll-owned 80CCD(2) and the metro flag kept) |
| Declaration windows and rules (PY-12, CF-12) | settings `payroll.tax.declaration_from/to`, `proof_from/to`, `regime_locked_after_submit`, `landlord_pan_required`, `max_landlords` (`TaxSettings`) | Outside the window only payroll changes declarations; the regime can be locked once submitted; every landlord's PAN can be required; up to N landlords per rent declaration |
| Declaration heads (PY-13) | catalogue sections `24B` (home-loan interest) and `OTHER_INCOME`; `employee_tax_declarations.previous_employer` (encrypted: name, TAN, period, PF, PT) | Other income adds to taxable income (s.192(2B)); previous employer's PF counts in 80C and PT in the PT deduction (old regime); home-loan interest counts only when the rule in force carries `house_property_interest_limit` (and optionally `house_property_loss_setoff_limit`) — no current rule version does, so it is recorded on the basis as `house_property_not_counted` |
| Form 12BB (PY-13) | — | Printable from Tax declarations (signed link, audited `EXPORT`): rent and every landlord, 24(b), Chapter VI-A items with verified amounts, other income, previous employer |
| Perquisites and income outside payroll (PY-07, PY-17, PY-18, PY-25) | `tax_income_entries` (kind perquisite / outside_payroll; cancelled, never edited) | Perquisite taxable value (value − recovered) and outside payments (taxable part) join the projected salary; tax, PF and PT deducted outside count as already deducted / in 80C / in PT |
| Statutory overrides (PY-14) | `statutory_overrides` (employee, pay period, line, amount, reason; cancelled, never edited) | `StatutoryOverrides` sets the line's amount after the engine computes it (0 removes it), keeps the calculated amount and reason on the basis, raises a non-blocking `statutory_override` exception; PF/ESI/PT/LWF overrides apply before TDS reads them, TDS last. Only `payroll.override` (new), never on one's own pay, never once the pay is finalised |
| VPF and PF wage basis (PY-15) | `employee_pf_options` (effective-dated) | `VPF` deduction (percent of PF wages or a monthly amount) counted in 80C; the employee's ceiling / actual wage basis replaces the establishment's for PF and for the CTC balancing estimate |
| NPS (PY-16) | component classifications `nps_employee`, `nps_employer` | Employer NPS joins salary and 80CCD(2); employee NPS counts with 80C |

Uploads (Payroll → Payroll uploads, the time-office upload screen generalised by `RowImportTypes` area): perquisites,
income outside payroll, statutory overrides (`payroll.override`), PF options. Reports: perquisite register,
out-of-salary payments, statutory override audit, VPF register. Setup → Payroll gained the tax dates (new `date`
setting type) and links. Signed downloads for proof files and reimbursement bills go through `ParityFileController`
(record policy or own record, audited).

## HROne setup parity (HS)

The owner asked for HROne's whole admin Setup tree (Core HR, Automation, Forms & templates, Governance, Access
rights, Integration, Workforce, Time office, Payroll, Expense, Performance, Engage, Asset, Helpdesk; recruitment
excluded) field by field. The fields come from HROne's compiled front end (each setup form's questions, options and
control names); the matrices are in `docs/hrone-parity/setup/` (one file per section), built in packages.

Policy forms (`PolicySettingsSchema`) gained HROne-style controls: `yes_no` and `radio` choices, `time`, `tags`
(lists such as IP ranges), `checkboxes`, `textarea`, titled `section`s whose fields stay flat in the settings, and
`visible_when` follow-up questions; the version view shows labelled values. Editing a policy reloads the page after
"New draft" / "Publish draft" (the versions list did not refresh), and a policy without assignment rules says that
it applies to nobody.

### Time office → Check in policy (TO-2)

`checkin` policy: web (punch limit, time window, IP addresses / CIDR ranges), mobile (location, photo / facial with a
photo count, radius- or polygon-based geo locations, continuous tracking, locations approved only for a period with a
maximum, punch limit, window, approved WiFi networks), devices. `CheckInGuard` enforces them per channel (web; mobile
and API). Punches keep their evidence (`photos`, `face_verified`, `ip_address`, `wifi_ssid`, `wifi_bssid`); geo
locations may be polygons and valid between dates; location requests carry dates (required, within the maximum, when
the policy approves locations for a period). The punch API takes `photos[]` (files) or `photos_base64[]`,
`face_verified`, `wifi_ssid`, `wifi_bssid`; `POST /api/v1/attendance/locations` stores location pings when the
employee's policy turns continuous tracking on (`attendance_location_pings`).

Web check-in (Home, My day) now sends a browser device id and, when the policy needs it, the browser's location
(Permissions-Policy allows geolocation for PeopleOS's own origin; camera and microphone stay off), with the request
IP; a refused punch is reported as not accepted with the reason, and rejected punches no longer show the employee
as checked in.

Open in HP-2/HP-3: FBP declarations and LTA claims (with their tax treatment), out-of-payroll payments and income outside the system (TDS), negative-salary carry-forward, per-employee unprocess, automatic arrears on back-dated revisions, pay groups, payslip passwords, bank-specific files, payroll uploads, year-end handling of unclaimed reimbursements.

### Time office → request rules, attendance policy, shifts, holidays, leave types, devices (TO-3 … TO-8)

Field-level status per HROne control: `docs/hrone-parity/setup/09-time-office.md`. Every rule below applies only when
its policy or record sets it, so earlier policies behave as before (attendance engine version unchanged).

- **TO-3 request rules** (`App\Domain\Attendance\Support\RequestRules`). The requester's role (employee / manager /
  HR = `attendance.manage`) picks the backdated window. Regularisation (attendance policy section AR), on duty and
  change shift / week off (time-office policy sections) and short leave check future dates, week-offs / holidays,
  processed (locked) days, attachments (`attachments` on regularisations and time-office requests), per-week /
  month / year limits (on-duty limits may leave managers or HR free), at least one punch and the shift range (AR).
  On-duty requests carry their type (`category`). New request `week_off_change` (work a weekly off, take
  `swap_date` off) applies two roster overrides on approval and restores them on cancellation. Organisation setting
  `attendance.requests_after_benefits` refuses AR / OD / short leave for a day that earned overtime or comp-off.
- **TO-4 attendance policy** (`AttendanceProcessor`): `punch_mode` single (one punch = the shift) / auto / none
  (present by default), `timing` flexi (no late / early marks), `count_within_shift`, `weekly_off_paid` = unpaid
  (exception `unpaid_off`; `AttendanceDay` counts it unpaid), weekly off / holiday entitlement by days present in the
  week (re-decided when a weekday changes), `absence_alert_days` (`peopleos:attendance:absence-alerts` →
  `attendance.absence_alert`), `request_lock` days of the month (attendance requests and leave for last month);
  time-office `comp_off_auto` / `comp_off_auto_approve` (`CompOffGenerator`).
- **TO-5 shift master**: colour, first-half length, early check-in window (the day's punch window), absence criteria
  (late first check-in → first half or day absent; early check-out → second half absent), time before the start and
  after a cut-off not counted, requestable, short leave / AR / OD / leave allowed per shift, shift allowance
  eligibility; work schedules may list shifts for automatic shift correction (nearest the first punch). Shift
  allowance: attendance policy rate, minimum / maximum days, off days; `AttendanceOutput::shiftAllowanceDays`,
  paid by `ShiftAllowanceInput` (`SHIFT_ALW`).
- **TO-6 holidays**: restricted-holiday quota prorated for joiners by joining month, counted per calendar or financial
  year (`attendance.holiday_year_basis`), cancellation refused for chosen roles once processed, optional shift
  requirement.
- **TO-7 leave types** (policy entitlement rows): ESI / age / marital eligibility, request counts and days per
  month / year / tenure, backdated days per role, future dates, pending resignation, birthday window, leave types it
  cannot be clubbed with, carry forward all / none / up to the limit, credits rounded and capped, encashment per
  request and balance kept.
- **TO-8 devices**: a device is either pushed to or read by a sync agent (server type, name, table, connection
  reference — credentials stay with the agent —, frequency, column mapping); `peopleos:attendance:device-alerts`
  (hourly) e-mails once per silence (`ATTENDANCE_DEVICE_ALERTED`).

Policy forms read like HROne's: yes / no and option radios with follow-up questions, sections per step.

### Workforce (WF-1, WF-2)

Matrix: `docs/hrone-parity/setup/08-workforce-payroll.md` (section 08).

- **Separation (exit) policy, now read** (`Exits::noticeDays`): notice for confirmed employees and during probation,
  the employee's backdating window on self-service resignation, HR's backdating limit, retirement age
  (`peopleos:lifecycle:retirement-due` raises `employee.retirement_due` once, 60 days ahead), a mapped clearance
  checklist (`clearance_checklists` items replace a stage's default items), letters drafted when the exit starts and
  completes. Without a policy the `exit.notice_days` setting applies as before.
- **Confirmation policy and reviews**: probation months at hire (else the organisation default), review lead days,
  extension (longest, how many) and termination switches, letters on confirm / extend. `confirmation_reviews` are
  opened daily (`peopleos:lifecycle:confirmation-reviews`) and decided on People → Confirmation reviews by someone
  with `employee.lifecycle` (never about themselves): confirm (lifecycle Confirmed from the probation end), extend
  (probation end moves; the next review opens later) or terminate (an exit of type termination).
- **Transfer policy and requests**: what a transfer may change (position dimensions, manager), notice and backdating
  days, reason required. `transfer_requests` (People → Transfer requests, and the Approval Center) are approved by
  someone other than the requester and the employee, which applies `TransferEmployeeAction`.
- **Reason masters** (`workforce_reasons`: separation, transfer, blacklist) and **clearance checklists** (Admin).
- Letters from policies go through `PolicyLetters::draft` (a missing template or an error never blocks the decision).

### Policy wizard (every policy type)

HROne drafts each policy as a numbered, step-by-step screen (Policy → its sections → Applicability → Advance
applicability → Review, with NEXT). PeopleOS now does the same for **every** policy type, from the type's own
questions, so a new policy type gets the wizard without screen code.

- **Page** `App\Filament\Pages\PolicyWizard` (`/policy-wizard?type=…` for a new policy, `?policy=<id>` to change one):
  steps on the left (numbered, reachable once visited; an existing policy opens every step), the current step on the
  right with Previous / Next; Review lists every answer (follow-ups whose parent says no are left out), asks for the
  reason, and offers **Save as draft** and (with `policy.publish`) **Publish** from a date. Steps after "Policy" come
  from `PolicySettingsSchema::steps($type)`: the questions outside any section ("General"), then one step per section
  and per table. All steps live in one form; only the current one is shown and validated, and saving validates them
  all (the first step with errors opens).
- **Model** `App\Domain\Configuration\Services\PolicySetup`: `load()` reads a policy into the wizard (its draft, else
  its latest version, and its applicability); `save()` writes it in one audited transaction: the policy, its draft
  version (a published version is never changed — editing starts a new draft; only the type's own keys are kept),
  the applicability rules, and publishing when asked. Permissions are checked again there (`policy.create` /
  `policy.update` / `policy.publish`), and a reason is required.
- **Applicability** is kept as plain assignment rules: "applies to" everyone / selected groups (company, business
  unit, division, department, location, grade, level, designation, employment type, employee category, work mode,
  lifecycle state, gender — each "is any of", all chosen kinds must match) / only named employees; advance
  applicability names employees (a rule on the new rule field `employee_id`) and an end date. Rules the wizard
  creates make the specific win: named employees (priority 50) before groups (100) before everyone (1000). Rules it
  adopts keep their name and priority, so saving never reorders resolution. A policy whose rules use anything else
  (other operators, "any" matching, inactive or more rules) keeps them untouched: the wizard says so and links to
  the rule editor. A policy that applied to nobody keeps applying to nobody until someone chooses.
- **Entry points**: Policies → New policy (the old create route redirects to the wizard, so existing links land
  there); a policy row opens the wizard (row action "Versions & rules" keeps the version history and rule editor);
  the policy page has "Edit in wizard"; the Setup centre lists "Add policy" for each of a module's policy types.
- Tests: `tests/Feature/HroneParity/PolicyWizardTest.php` (steps for every type, new → publish for everyone,
  groups + named employees draft → reopen → publish, rules left to the rule editor, permissions).

### Payroll → pay components, tax switches, My compensation (PS-1 … PS-3)

Matrix: `docs/hrone-parity/setup/08-workforce-payroll.md` (BP-1, part of BP-7 and BP-8).

- **PS-1 pay component flags** (migration `2026_11_15_100003_extend_salary_component_flags`): which statutory wages a
  component is part of (`pt_wages`, `lwf_wages`, `esi_eligibility_wages`; blank = derived as before: PT and LWF on
  gross, the ESI ceiling on ESI wages), `show_on_payslip` / `show_in_register` (hidden lines are paid but not printed),
  `pay_in_joining_month` / `pay_in_exit_month`, per-component `rounding`, `pay_nature` (once variable components are
  marked, variable pay uses only them), `exempt_in_regimes` (a non-taxable component is taxable in the other regimes),
  claim rules on reimbursements (bills required, window, limit per claim). Read by `PayrollComputation::ptWages()` /
  `lwfWages()`, `StatutoryEngine`, `TaxComputer`, `PayrollCalculator`, `Payslips`, `ReimbursementClaims`, `VariablePay`.
  Tests: `PayComponentFlagsTest`.
- **PS-2 tax switches**: declaration days of the month (`payroll.tax.declaration_day_from` / `_to`), whether payroll
  may change a submitted declaration (`payroll_may_edit`), Form 12BB visibility, the regime estimator on the
  declaration (`show_estimator`, `TaxEstimator`: rough yearly comparison from the TDS rule in force; payroll's monthly
  TDS stays the actual figure; the CTC fallback is read through `CompensationOutput`), Form 16 self-service (Me → My tax
  certificates), and bills under the new regime (`payroll.claims.new_regime_bills`). Tests: `TaxSwitchesTest`.
- **PS-3 My compensation switches** (`compensation.self_service.show_*`: history on by default; net take-home, wage
  split, perquisites and insight off). The latest paid salary comes through the payroll read contract
  `PaidPayReader::latestPaid()` (finalized or paid runs only), so the compensation UI never reads payroll entries.
  Tests: `CompensationSelfServiceTest`.

### Helpdesk (HD-A, HD-B, HD-C) and Asset masters (AS-A)

Matrix: `docs/hrone-parity/setup/11-expense-asset-helpdesk.md` (sections 14 and 15). Everything is inert until a
setting, a category flag, a rule, a policy or a sub type says otherwise; no new permission keys (configuration needs
`servicedesk.manage` / `asset.manage`, viewers `servicedesk.view` / `asset.view`).

- **HD-A switches and categories.** Settings (Setup → Helpdesk) `servicedesk.show_sla_to_employee` (requester sees a
  "Service level" block: resolution due, on track / overdue / paused, never who works it) and
  `servicedesk.feedback_required` (a requester closing a resolved request must rate it; HR closing and the automatic
  close are exempt). `ticket_categories.purpose` (`peopleos.servicedesk.category_purposes`: general, travel booking,
  accommodation booking, asset requests), `rca_required` (`ServiceDesk::resolve` refuses without the root cause, under
  the request lock) and `feedback_enabled` (off: no rating asked for or kept); `tickets.root_cause` (masked in audit)
  and `root_cause_code` (`peopleos.servicedesk.root_causes`, dataset field `root_cause`). The category list shows its
  services (HROne sub-categories) and filters by category and status.
- **HD-B resolver rules** (`service_resolver_rules`, Service Desk → Resolver rules, with a review page).
  `ResolverRules::match` runs at submission (`ServiceRequests`): active rules in force whose categories, services,
  companies and locations (empty = any) cover the request; lowest priority number, then the most specific (service >
  category > any, then location, then company). The rule's role is the team, its default resolver the agent (else the
  least-loaded eligible member), its SLA policy replaces the service's (`tickets.resolver_rule_id` remembers the rule).
  No match: the service version / category routing as before. The rule's intimation role hears
  `servicedesk.ticket.intimated` at intake and on every (re)assignment — only active members who can see the case, at
  most 25. SLA policies expose `business_hours` (service days = the resolvers' weekly offs, start and end;
  `ServiceSlaPolicy::serviceHours` validates and normalises; blank = the organisation default) and an "or due days"
  input converted to service hours.
- **HD-C helpdesk access policy** (policy type `helpdesk`, wizard steps "Categories and services" and "Raising on
  behalf"; `options_from` `ticket_categories`, `service_definitions`). `HelpdeskAccess` filters
  `ServiceCatalogue::availableTo` and the "Ask HR" categories, and `ServiceRequests` refuses self-service and manager
  requests outside the employee's policy (a listed service is allowed whatever its category); `raise_on_behalf_of_team`
  = no stops that manager raising for the team. HR on someone's behalf is not limited; no policy = as before.
- **AS-A asset taxonomy.** `asset_subtypes` (Assets → Sub types: `allow_duplicate_serial`, `acknowledgement_required`);
  `asset_models` gained sub type, description, `life_months`, `deployment_type` / `licence_basis`
  (`peopleos.assets.deployment_types`, `licence_bases`) and `documents` (private `tenants/{id}/assets/models`, signed and
  audited download through `ParityFileController` type `asset-model`); `assets.asset_subtype_id` (taken from the product
  when blank, must belong to the asset type); a sub type without duplicate serials refuses a serial another of its
  assets carries (model hook, so receive, form and edit). Asset types gained `owner_role_id` (told when a leaving
  employee still holds one) and `fulfilment_days` (for AS-B requests). Acknowledgement: `AssetAcknowledgementTaskSource`
  (My tasks) and `peopleos:assets:acknowledgement-reminders` (daily 08:30, every `asset.acknowledgement.reminder_days`,
  stamped on `asset_assignments.acknowledgement_reminded_at`, event `asset.acknowledgement_due`). Exit:
  `asset.exit.recovery_required` (on = as before) — off lets the asset clearance clear with a waiver reason (audit
  metadata `asset_recovery_waived`); `asset.exit.recovery_basis` = `book_value` pre-fills the amount with
  `Assets::bookValue` (straight line over the product's, else the type's, life).
- Tests: `tests/Feature/HroneParity/HelpdeskSwitchesTest.php`, `ResolverRulesTest.php`, `HelpdeskPolicyTest.php`,
  `AssetTaxonomyTest.php`.

### Governance → Security, Forms & templates → Code series, Automation → Role (HS-P1, HS-P9, HS-P14)

Matrix: `docs/hrone-parity/setup/02-automation-forms-governance.md` (sections 02.8, 03.2, 04.5, 04.6). Every default
reproduces the earlier behaviour.

**P1 Security policy completion.**

- **Password expiry is enforced** (finding 1: `SecurityPolicy::passwordExpired()` had no caller).
  `EnforceAccountSecurity` sends a tenant user's password session (not SSO) whose password is older than
  `security.password_expiry_days` to `ChangeExpiredPassword` (`/admin/password/expired`, `filament.admin.auth.password-expired`,
  in `OPEN_ROUTES`), after the MFA and e-mail checks, on every page, Livewire call and protected download (JSON: 403).
  A password with no known change date counts as expired (owner decision 2026-10-29: when a tenant sets an expiry
  period, such accounts change their password at the next sign-in); the check reads the user's own tenant. The page proves the
  current password, applies the tenant's rules, refuses the current password, audits `PASSWORD_CHANGED` (`method:
  expired`), keeps the session (other sessions end through the session password check). Platform operators are not
  affected.
- **Reminders**: `peopleos:lifecycle:reminders` notifies (in-app + e-mail, event `security.password_expiring`) each
  active user whose password expires within `security.password_expiry_warning_days` (default 7; 0 = none), once per user
  and expiry date (`scheduler_claims` `password_expiring:{user}`). Nothing is sent while passwords never expire.
- **Strict password**: `security.password_require_symbol` (a symbol on top of length, mixed case and a digit) and
  `security.password_block_personal` (no name part, e-mail name or employee code of 3+ characters), in
  `SecurityPolicy::passwordProblems`.
- **Recent passwords**: `security.password_history_count` (0–24, default 0). `PasswordHistory` keeps that many hashes
  per tenant user in `password_histories` (written by the `User` saved hook when a password changes, only while the
  rule is on; pruned to the count; never audited or exported: column `password`), and `PasswordPolicy` refuses the
  current password and the recent ones (invitation, reset link, profile, expired-password page).
- **Disable login by status**: `security.disable_login_states` (multiselect, options `login_disable_states`:
  pre-employee, preboarding, onboarding, on leave, suspended, notice period, exited, alumni; the working states are
  never offered and are ignored if stored, so one setting cannot lock the workforce out). `DisableLoginOnStatus`
  (listener on `EmployeeLifecycleChanged`, ParityServiceProvider) suspends the employee's active account on entering a
  listed state (audited "Login disabled by status …", `users.disabled_by_rule = true`) and reactivates it on leaving
  only when the rule suspended it; any other change of status clears the mark (`User` saving hook), so an
  administrator's, SCIM's or an exit's suspension is never undone. `peopleos:security:login-status` (daily 00:40)
  applies the rule to everyone, for states listed after employees entered them. Exits keep their own behaviour.
- **Dead defaults removed**: `security.password.min_length` and `security.session.lifetime_minutes` (finding 2; nothing
  read them beyond listing all settings).
- Screens: Setup → Governance and Enterprise → Security policy show the new settings.

**P9 Number series.**

- `number_series` (model `App\Domain\Configuration\Models\CodeSeries`, audited, `PeopleSetupPolicy`): code type `key`,
  `company_id` (null = organisation), `prefix` (≤24) / `suffix` (≤16) with `{YYYY} {YY} {MM} {FY}` (financial year from
  April, `2026-27`), `digits`, `start_number`, `status`. Code types (`config peopleos.numbering.types`): `loan`,
  `letter` (+ `letter.<type>` per letter type, falling back to `letter`), `exit_case`, `alumni_request`, `ticket`
  (organisation only for alumni requests and tickets). Their built-in formats are exactly the earlier ones
  (`LN-`, `{letters.number_prefix}-`, `EXIT-`, `ALR-`, `TKT-` + `{YYYY}-` + 5 digits).
- `App\Support\Numbering\NumberSeries`: `definition()` (company series, else organisation series, else fallback type,
  else built-in), `prepare()` / `next()` / `preview()`, `configured()`, `overview()`, `problemWith()` (validation).
  Numbers are counted in the existing locked `number_sequences` rows, one per rendered pattern (so the built-in ticket
  format continues the Phase 12 `TKT-YYYY` counter, two series with one pattern share a counter, and a period token
  restarts numbering), seeded from the highest number already used in the pattern; numbers already taken are skipped.
  Loans, letters, exit cases, alumni requests and tickets (ServiceDesk and ServiceRequests) now take their numbers
  from it; the "last number + 1" reads (unlocked, and blind to records outside the caller's access scope) are gone.
- Screen People Setup → Number series (`NumberSeriesResource`): Module, Code type, Applicable on, Pattern, Restarts,
  Next number, Status, Configured? (Yes / Missing for some companies / Uses the letter series / Built-in format);
  filters Module, Code type, Applicability; "New series" is a Code type → Configure → Review wizard; "All code types"
  lists every type with its status, including those with no series. Linked from Setup → Core HR.
- Employee code series by employment type: `employee_code_series.employment_type_id`; `EmployeeCodeGenerator`
  resolves company + type, company, type (all companies), default; the hire wizard, hire and pre-employee actions pass
  the employment type.

**P14 Roles polish.**

- `roles.status` (active / inactive; system roles stay active): an inactive role grants no permissions
  (`User::permissionKeys`, `roleSlugs`) and no new workflow tasks.
- `roles.reliever_user_id` and setting `workflow.manager_reliever_user_id` (Setup → Automation, default 0 = nobody).
  `ApproverResolver`: a role with no active holder (or inactive) goes to its active reliever; an inactive role with no
  reliever resolves to nobody (the node's fallback applies); an active role without holder or reliever keeps the task in
  the role queue as before. A manager approver whose account is inactive goes to the manager reliever; with no manager
  the node's fallback comes first and the manager reliever after it (`ApproverResolver::reliever`, last step of
  `WorkflowEngine::createTask`). The workflow builder offers the per-node "Fallback approver" (`config.fallback`).
- Role screen: status and reliever on the form and list; **Members** relation manager (name, e-mail, employee code in
  the viewer's scope, companies, status; add / remove with `user.assign_roles`, the RoleGrants ceiling and a reason,
  through `RoleMembers`, audited `ROLE_CHANGED` on the person); **Roles by person** page (`RolesByPerson`, `role.view` +
  `user.view`; inactive roles marked, "Reliever for", one optional column per role as a users × roles matrix), reached
  from the Roles list ("By person") and Setup → Automation.

**Data, permissions, commands.** Migrations `2026_11_17_200001_password_history_and_login_rules`,
`2026_11_17_200002_create_number_series`, `2026_11_17_200003_add_role_status_and_relievers`. `DataInventory`:
`password_histories` (identity), `number_series` (configuration). No new permission keys (number series use
`people_setup.*`, members `user.assign_roles`). New command `peopleos:security:login-status` (scheduled daily 00:40).

**Tests.** `tests/Feature/HroneParity/SecurityPolicyCompletionTest.php`, `NumberSeriesTest.php`, `RolesPolishTest.php`.

### Expense foundation and masters (EX-A) and expense policies (EX-B)

Matrix: `docs/hrone-parity/setup/11-expense-asset-helpdesk.md` (sections 11.1–11.10). A new module, `app/Domain/Expense`
(`Models`, `Services`, `Support`, `Contracts`, `Policies`, `Exceptions`), with masters and the limit resolver that the
later packages (receipts and reports EX-C, advances EX-D, payroll and exit EX-E, travel EX-F, reports EX-G) build on.
Nothing is claimed, paid or recovered yet; no existing behaviour changes.

- **Module.** `Capability::Expense` (`expense`, permission prefix `expense`; commercial, shadow like the others).
  Permission group `expense`: `expense.view`, `expense.manage`, `expense.claim`, `expense.approve`, `expense.pay`,
  `expense.travel_desk`. Role templates: employee `expense.claim`; manager `expense.claim`, `expense.approve`; HR manager
  `expense.view`, `expense.approve`; payroll admin `expense.view`, `expense.pay`; auditor `expense.view`; tenant HR admin
  `expense.*`; new system role **Expense Admin** (`expense-admin`: masters, expense and advance policies, exchange rates,
  `expense.*`). Navigation group "Expense & travel" (space Pay; Admin centre category "Pay, compensation and
  compliance"). DataInventory category `expense` (personal, financial significance, exported, deleted with the tenant;
  file prefix `tenants/{id}/expense` for later receipts). Wiring in `ParityServiceProvider` (`ExpenseConfigPolicy` for
  every master; `ExpenseUsage` bound to `NoExpenseUsage`).
- **Masters** (migration `2026_11_18_200001_create_expense_masters`; Expense & travel → …):
  - `expense_groups` (Expense groups);
  - `expense_categories` + `expense_category_limits` (Expense categories): type amount / mileage (fixed once created),
    group, code (fixed, `MasterCode`), description; mileage rate per km, odometer, location input (none / text / map —
    map waits for a maps provider); limit basis (none / amount / percentage of CTC / km) with limit rows per value of one
    employee dimension (`limit_dimension`: grade, designation, level … — `EmployeePosition::DIMENSIONS`) and location group
    (null = every other), each with amount, percent or km and a period (`peopleos.expense.periods`; one row per
    combination, `match_key`); the CTC component a percentage is taken of; out-of-limit as exceptional; hourly bands for
    daily allowances (no overlap) and auto-filled hours; description, receipt (above an amount), reimbursable, claim
    frequency and count; tax applicable and GST type; travel link (none / reference / plan), travel or accommodation
    form, helpdesk booking service, who books; before joining; attendance rule (present / minimum time / on duty, any or
    named types); submission window (From < To days); declaration. `ExpenseCategories::save()` validates every rule on
    the server and clears what does not apply to the type and choices; limit rows are diffed (unchanged rows untouched,
    each change audited with the reason);
  - `expense_location_groups` + `expense_location_group_places` (Location groups): whole states or cities of a state
    (IN states from `peopleos.compliance.states`, typed elsewhere); a place belongs to one group;
    `ExpenseLocationGroups::resolve()` matches the city first, then the state, active groups only;
  - `expense_tax_types` (Tax): names, an optional usual rate (never shipped). `ExpenseDefaults` seeds starter groups for
    every new tenant and the country's tax heads (config `peopleos.expense.defaults.tax_types`, IN: the four GST heads,
    names only); "Add the standard tax types" adds missing ones for older tenants.
- **Forms.** Page Expense & travel → Expense and travel forms (`ExpenseFormSetup`, `expense.manage`; `expense.view` reads):
  the expense form matrix (purpose, location, participants, merchant, invoice number and the custom fields of the new
  entity `expense_receipt` — visible and mandatory on amount, mileage, accommodation and travel details, renamed labels)
  and the travel form (currency, estimated cost, international travel, visa, passport, attachment and the custom fields of
  `travel_request` — visible, mandatory, label). Settings `expense.form.fields` / `expense.travel_form.fields` (audited
  through `SettingsRepository`; defaults `peopleos.expense.form_fields` / `travel_form_fields`); `ExpenseForms::missing()`
  and `travelMissing()` are the server-side checks EX-C and EX-F call. The project field waits for projects (HP-5).
- **Currency.** Exchange rates gained "Add several rates" (one effective date, many currencies into the base currency).
- **Setup centre.** Module `expense`: settings `expense.year_start_month` (default 4: yearly, half-yearly and quarterly
  limits and frequencies count from April) and `expense.exceptional.enabled` (default off), policy types `expense` and
  `expense_advance`, links to every master, the forms page, exchange rates and custom fields. Settings may now carry a
  `group` (HROne's headings): a module with grouped settings shows one block per group. The policy-type "Open" link now
  uses the policy list's `filters` query parameter, so it really lists only that type.
- **Policy types** (`peopleos.policies.types`, wizard steps from their sections):
  - `expense` — step "Expense categories" (repeater named "name · type · group" via `item_label` => `options`: category,
    exceptional-only, and overrides of the category's limit amount, km, period, location-wise amounts, receipt threshold,
    frequency count, out-of-limit handling) and "Limit across all categories" (yes / no, amount, frequency). The policy list
    shows a "Categories" count when filtered to expense policies;
  - `expense_advance` — "Advance requests" (offered, largest, open at a time, most outstanding, purpose, attachment,
    cancellation approval), "Payout" (bank or payroll), "Settlement and recovery" (settle within days, recovered through
    payroll / at exit / not, days after the settle-by date). Read through `ExpenseAdvanceRules` (HROne's defaults for
    unanswered questions; no policy = HROne's "Expense advance policy needs to be configured …").
  - New `options_from` sources `expense_categories`, `expense_groups`, `expense_location_groups`, `expense_periods` (both
    in `PolicySettingsSchema::options()` and `SetupCatalogue::optionsFor()`).
- **Limit resolver** `ExpenseLimits::for($employee, $category, $date, $locationGroup, [minutes, trip, except_receipt_id])`
  → `ExpenseLimit`: refused without an expense policy, for a category not in it or inactive, or exceptional-only while
  exceptional expenses are off; else the limit from the policy's location-group override, the policy's amount (km for
  mileage), or the category's best row (employee's dimension value and location group → value anywhere → everyone in
  the group → everyone), its period (policy override first), hourly band percentage, usage over the period window
  (`ExpensePeriods`, `ExpenseUsage` contract), the claim frequency and the policy's overall limit, `headroom()`, the
  money cap of a km limit, out-of-limit action (`cap` / `exceptional`) and the receipt threshold. A percentage of CTC
  comes from `CompensationAmounts` — the CompensationOutput contract only (a formula component is worked out with the
  structure's own formulas, as the salary annexure does); no compensation in force gives 0 and `compensationMissing`.
  `ExpensePolicies::categoriesFor()` lists what an employee may claim. Architecture test
  `tests/Feature/Architecture/ExpenseInvariantsTest.php`: the expense domain never names a compensation model or table
  and reads compensation only in `CompensationAmounts`; it names payroll only as the CTC component master.
- Not built here (later packages): receipts, reports, workflows and the Approval Center source (EX-C), advances (EX-D),
  payroll inputs and the settlement source (EX-E), travel (EX-F), datasets (EX-G); map distance (EXT); projects (HP-5).
  The percentage-of-CTC option is offered to every tenant (entitlements are shadow-only and HCM may not read them).
- Tests: `tests/Feature/HroneParity/ExpenseMastersTest.php`, `ExpensePoliciesTest.php`,
  `tests/Feature/Architecture/ExpenseInvariantsTest.php`; `CapabilityCatalogTest` counts 20 modules.

### Performance → masters, settings, rating scales and KPI formulas (HP-8a, HP-8b, HP-8c)

Matrix: `docs/hrone-parity/setup/12-performance-engage.md` (sections 12.0, 12.1, 12.6–12.9, 12.11, 12.15–12.17). The
Setup centre's Performance module had no settings; it now lists every setting below, and every default reproduces the
earlier behaviour.

**HP-8a Performance masters and terminology.**

- `performance_lookups` (`PerformanceLookup`, kinds in `peopleos.performance.lookup_kinds`): perspectives, initiative
  types and PIP reasons. `PerformanceDefaults::seedMasters()` (at provisioning; "Add standard entries" for existing
  tenants, idempotent) adds HROne's standard rows as system rows (Customer, External Process, Financial, Internal process,
  Learning and growth; BUDDY, INDUCTION, INITIATIVE, PIP, TNI; Absenteeism, Inappropriate attitude, Insubordination,
  Punctuality, Unsatisfactory performance): renamed or deactivated, never deleted or re-coded. Screen Performance →
  Performance masters (`PerformanceLookupResource`, a tab per kind). Initiative types wait for the initiative record
  (HP-8j).
- `performance_units` (`PerformanceUnit`, Performance → Units of measurement): label, code, default target, how progress
  is calculated (a measure type, or none), default unit (one per tenant), applicability (rule conditions = HROne's OU
  mapping), KPI rating formula, status; Percent / the base currency / Rating / Units seeded (none is the default, so new
  goals start as before). `performance.units.employee_can_add` (off) lets employees add units for themselves
  (`PerformanceMasters::addUnitForUser`, "+" on the goal's unit select): not admin units, offered only to their creator
  (HR sees all; the "Added by" filter = HROne's Admin / User tabs).
- Links: `kras.perspective_id`, `goals.perspective_id`, `goals.unit_id`, `key_results.unit_id`, `key_results.due_date`
  (shown when `performance.goals.kpi_due_date` is on), `improvement_plans.reason_codes` (PIP reasons; the reason text
  stays the details; `ImprovementPlans::open(..., reasonCodes:)`). A goal with a unit takes the unit's code as its unit
  text and, when not given, its measure type and default target (`Goals::create/update`); `PerformanceMasters` refuses an
  inactive perspective or formula and a unit the actor or the goal owner may not use (unchanged references are not
  re-checked). Without any unit the goal form keeps its free-text unit. A goal picked from the KRA library takes the
  KRA's perspective, and choosing one of its suggested KPIs fills the title, target, unit, reverse flag and formula.
  Datasets: goals (perspective, unit, lower is better), improvement plans (PIP reasons).
- Terminology: settings `performance.labels.kra|kpi|competency|perspective|uom|target|feedback|achievement|kpi_due_date`
  (blank = PeopleOS's wording) read through `App\Domain\Performance\Support\PerformanceLabels` (`get`, `plural`,
  `goalTypes`): KRA library and goal types, KPI rating formulas, competencies (navigation, cycle form, review form),
  perspectives, units, target / achievement / KPI due date on goals and key results, feedback (one-on-one feedback card).
  Where a screen used other wording ("Key results", "Target value", "Current value"), that stays the fallback.
  "Use OKR terms" (Setup → Performance header action, reason required, audited) sets KRA = Objective, KPI = Key result,
  achievement = Progress (`PerformanceLabels::useOkrTerms`).

**HP-8b Performance general settings and the matrix defects.**

- Rating publication `performance.rating_publish` (auto / manual). `appraisals.published_at` / `published_by`
  (migration backfills finalized appraisals as published at their finalization). Auto: `Appraisals::finalize` publishes
  at once (timeline, "appraisal is final" event to the employee, as before). Manual: nothing reaches the employee until
  `Appraisals::publish($cycle, ?ids, actor)` (needs `performance.calibrate`; "Publish ratings" on the cycle, the
  calibration board, the appraisal list bulk action and per appraisal; audited as one operation; each appraisal once,
  locked row); the `performance.appraisal.finalized` event (notification, rules, webhooks) then fires. Acknowledgement
  needs a published rating; a cycle cannot close with unpublished ratings.
- Defect fix (employees saw ratings early): `AppraisalVisibility` — ratings, scores, label, promotion / PIP flags and
  the summary show to HR (`performance.view` / `performance.calibrate`) and the employee's performance managers always,
  to the employee only once published, to nobody else (a peer reviewer sees only the review they write). Applied on the
  appraisal list and page, the Reviews tab (the employee sees their self review, and the reviews by others once
  published), Employee 360 → Performance, the Employee 360 summary and the career passport.
- Defect fix (inert cycle options): `settings.show_self_to_manager` (default on) now hides the self review and self
  rating from managers when off (HR still sees them); `settings.peer_anonymous` is the default of "Anonymous to the
  employee" and of `Appraisals::addPeerReview(..., anonymous: null)`.
- `performance.goals.edit_only_in_goal_stage` (off): employees and managers create, change or close a cycle's goals only
  while the cycle is active at its goal-setting stage and inside its dates (`Goals::assertGoalPeriodOpen`, also before
  the goal form saves its key results); performance administrators, goals outside a cycle and check-ins are not limited.
- `performance.rating.final_decimals` (0 = a scale level, as before; 1–2 = a number) and `performance.rating.rounding`
  (round / floor / ceil): the calibrate / finalize field and its default (`RatingRules`, `PerformanceActions::ratingField`)
  and the label of a rating on a scale without ranges (`RatingScale::labelFor($rating, $rounding)`).
- `performance.cycle.default_type` (annual) and `performance.year_start_month` (0 = not set): a new cycle's type and,
  with a year set, its period (annual / half-yearly / quarterly containing today; `PerformancePeriods`); the calibration
  board and performance analytics open on the cycle of the current period.
- `performance.profile.show_360` (off): Employee 360 → Performance adds the peer rating and "360 feedback" (submitted
  peer / upward / skip-level / external reviews of published appraisals; anonymous authors masked except for HR).
- One-on-ones: `performance.one_on_one.scope` (team = the host's reports, as before; organisation = any employee with
  `performance.goals` hosts a one-on-one with any employed colleague) enforced by `OneOnOnes::schedule` (the page
  schedules through it); `one_on_ones.initiated_by`. `performance.one_on_one.feedback_from` (initiator / participant;
  none by default): after a held meeting (last 30 days) those roles get a My tasks item (`OneOnOneFeedbackTaskSource`)
  and a "Give feedback" action — `one_on_one_feedback` (rating 1–5, comment, acknowledged_at; once per person and
  meeting, never changed).
- Goal check-in prompts: `performance.checkin.company|team|employee.interval` (none / weekly / monthly, default none) and
  `.day` (weekly 1 = Monday … 7 = Sunday; monthly 1–28), `performance.checkin.send_at` (hour, tenant clock).
  `PerformanceReminders::checkInPrompts()` prompts creators of open company / business goals, of department / team
  goals, and employees with active goals (`performance.reminder.goal_check_in`, once per person, scope and day in the
  reminder log); command `peopleos:performance:check-in-prompts` (hourly, each tenant once a day from its hour,
  `scheduler_claims`). Creators who are not employees are not prompted.

**HP-8c Rating scales and KPI rating formulas.**

- Level ranges: `rating_scales.levels[].from/to` (both or neither, no overlaps; `RatingScale::normaliseLevels`); a rating
  takes the label of the range containing it, else the level by the rounding rule (nearest by default). Ranges are in the
  template version's scale snapshot.
- Competency scale: `performance_cycles.competency_rating_scale_id` (null = the rating scale, as before), locked with
  the cycle; the template version pins `competency_rating_scale_id` + `competency_scale_snapshot` (in the checksum only
  when present, so earlier versions' checksums hold); `PerformanceCycle::competencyScale()`. Competency ratings are
  validated and scored (`competency_score`) on it and the review form offers its levels; a scale a launched cycle uses
  for competencies keeps its levels too.
  The template "Publish version" form takes the competency rating scale too.
- KPI rating formulas: `kpi_rating_formulas` (`KpiRatingFormula`, Performance → KPI rating formulas: name, code, based
  on achievement ÷ target or target ÷ achievement, bands from % / to % (blank = no limit) → rating, status; bands
  validated, no overlaps). `goals.kpi_rating_formula_id` (else the unit's), `goals.is_reverse`, `key_results.is_reverse`,
  library KPIs `unit_id` / `is_reverse` / `kpi_rating_formula_id`. `KpiRatings::suggest(Goal, ?scale)`: achievement %
  not capped at 100 (reverse KPIs always target ÷ achievement; key results weighted), the band's rating snapped to the
  nearest scale level, nothing outside the scale.
- `performance.review.show_formula_rating` (off) shows it on each goal of the review form;
  `performance.review.formula_rating_mode` (suggest / prefill / lock) — lock is also enforced in
  `Appraisals::submitReview` (`KpiRatings::assertLockedRatings`). `performance.review.show_reverse_rating` (off) shows the
  "lower is better" toggles (goal, key result, library KPI) and marks reverse goals on the review form.

**Data, permissions, commands.** Migrations `2026_11_19_200001_create_performance_masters`,
`2026_11_19_200002_add_rating_publish_and_one_on_one_feedback`, `2026_11_19_200003_add_competency_scale_and_kpi_rating_formulas`.
`DataInventory`: `performance_lookups`, `kpi_rating_formulas`, `one_on_one_feedback` (performance), `performance_units`
(goals). Policies: masters, units and formulas follow `PerformanceConfigPolicy` (`performance.manage` to change),
one-on-one feedback `EmployeeOwnedPolicy` (and `ScopedByEmployee`); one-on-ones now `OneOnOnePolicy` (employee-owned
rules, plus the employee who scheduled and hosts a meeting may open and update it) (ParityServiceProvider). No new permission keys. New command
`peopleos:performance:check-in-prompts` (hourly). Setup → Performance links: KPI rating formulas, masters, units.

**Tests.** `tests/Feature/HroneParity/PerformanceMastersTest.php`, `PerformanceSettingsTest.php`,
`RatingScalesAndKpiFormulasTest.php`.

### Performance → goal sheet policy, goal templates, PIP policy (HP-8d, HP-8e, HP-8f)

Matrix: `docs/hrone-parity/setup/12-performance-engage.md` (sections 12.3, 12.5, 12.10, 12.11, the 12.0 row "copy rating
comments to the next level", defect 3). Every rule comes from a policy or a setting whose default is today's behaviour:
without a goal sheet policy goals, goal plans, reviews and scoring work exactly as before; without a PIP policy plans open,
extend and close as before.

**HP-8d Goal sheet policy** (policy type `goal_sheet`, Setup → Performance; the wizard's steps are its sections: Goal
creation, KRA weightage, KPI weightage, Competencies, Goal creation configuration, Performance review configuration,
Performance scorecard configuration, Other setting). Read through `App\Domain\Performance\Services\GoalSheetRules`
(`PolicyResolver::resolve('goal_sheet', $employee, today())`; a blank answer = no rule).

- KRA rules (`Goals::create/update/close/submitPlan`, employee-level goals): `total_goal_weight` takes precedence over the
  template version and the cycle in `Goals::requiredWeight($cycleId, $employee)`; `goal_weight_mode` any / specific (each
  KRA needs a weightage) / equal (the system splits the total — the required total, else 100 — in whole numbers over the
  open, unlocked goals of the sheet after every create, change and close: 100 over 3 = 34, 33, 33); `min_goal_weight` /
  `max_goal_weight`; `max_goals` on create, `min_goals` / `max_goals` when the sheet is submitted;
  `unit_and_target_required`. Goals a template creates take the template's values (the sheet is checked when submitted).
  Plan status, weight totals and counts include goals `pending_approval`.
- KPI rules (`GoalSheetRules::assertKeyResults`): `min_kpis` / `max_kpis`, `kpi_weight_mode` (equal = the KPIs share the
  KPI total), `kpi_total_weight` (none / equal to the KRA's weightage / 100), `min_kpi_weight` / `max_kpi_weight`, UOM and
  target. Checked for key results created with a goal, and for the goal form's rows before it saves them
  (`Goals::create(..., $formKeyResults)`, `Goals::assertKeyResultRows` on the edit page; `Goals::keyResultsSaved` then
  applies equal KPI weightage).
- Field rights (`App\Domain\Performance\Support\GoalFieldRules`, section "Goal creation configuration", repeater
  `field_rules`): per goal field (title, description, KRA library, perspective, measure, UOM, start value, target,
  weightage, start date, due date, KPIs) and per role — goal creator, employee, manager (a performance manager of the
  owner), approver (someone who may decide the owner's goal sheet, while the goal is `pending_approval`) — hidden / view
  only / view and edit / mandatory; blank = view and edit. A user holding several roles gets the widest right;
  performance administrators, organisation goals and system runs are not limited. `GoalResource` hides, disables (not
  saved) and requires the fields; `Goals::create/update` refuse a change to a field the user may not edit and a missing
  mandatory field.
- Goal sheet approval (`plan_approval` none / manager / workflow): `Goals::submitPlan` (now also offered as "Submit goal
  sheet" on the goals list; only the employee, a performance manager or HR submits) moves the drafts to the new goal
  status `pending_approval` on a `goal_plans` row (`GoalPlan`, `GoalPlans::submit`) and fires
  `performance.goal_plan.submitted` (notification to the managers; a workflow trigger only when the policy says
  "workflow", subject type "Goal plan"). The reporting manager or HR decides it (`GoalPlans::approve` → goals active;
  `reject` with a reason → goals back to draft); never the employee or the person who submitted it. Decided in the
  Approval Center (`PerformanceApprovalSource`, type `goal_plan`, "Send back") or by the workflow
  (`PerformanceWorkflowBridge`, in the name of whoever completed its last task); a plan with a running workflow is not
  listed in the Approval Center.
- Reviews (section "Performance review configuration", per level self / manager / approver): `*_review_level` goal / KPI
  (each key result is rated; the KRA takes their weighted rating; rows `appraisal_ratings.subject_type = key_result`),
  `*_review_input` scale / percentage (an achievement % becomes the rating through the goal's KPI rating formula, else the
  same share of the scale; `appraisal_ratings.achievement_pct`), `*_evidence` off / optional / required (uploads on the
  review form, `appraisal_reviews.evidence`; `Appraisals::submitReview` refuses a review without required evidence).
  `PerformanceActions::reviewForm($appraisal, $withGoals, $type)` builds the form from it; the Reviews tab shows KPI
  ratings, percentages and evidence.
- Competencies (section "Competencies"): `include_competencies` (no = no competency rows, `competency_score` empty, the
  rating from goals only), `competency_codes` (the employee's set instead of the cycle's), `competency_block_weight` (the
  competency share of the overall rating; goals get the rest), number and single-weightage limits checked by the policy
  validator. **Defect 3 fixed:** `Appraisals::averageScore` is weighted by each competency's weightage (as pinned in the
  cycle's template version, else the competency's own) when the rated competencies carry one; a plain average otherwise.
- Scorecard (`scorecard_hidden`: reviewer ratings, KRA details, competency details, KRA score, competency score, computed
  rating): `AppraisalVisibility::partVisible` — hidden from the employee on the appraisal list and page, the Reviews tab and
  Employee 360 → Performance; HR and managers always see everything; the final rating and label show once published.
- Copy the previous level's inputs: tenant setting `performance.review.copy_previous_level` (off), overridden per group by
  `copy_goal_inputs` / `copy_competency_inputs`; the manager's form starts from the submitted self review (never when the
  cycle hides the self review from managers), the approver's from the manager's (`PerformanceActions::previousInputs`).
- Approver level: new stage `approver_review` (offered on cycles and review templates, never in a default) and review type
  `approver` (the manager's own line manager, `Appraisals::ensureReviews`); a submitted approver review drives the
  computed rating; the approver opens the appraisal (`AppraisalPolicy`, registered in `ParityServiceProvider`) and sees it
  like a manager ("Approver review" action, `AppraisalVisibility`).
- Policy validator: a policy type may name a `validator` (`validatePolicySettings(array)`), run by `PolicySetup::save` and
  `Policies::publish` (`Policies::validateSettings`): goal sheet minimums above maximums, a fractional total, competencies
  outside the number or weightage limits are refused.
- "Duplicate" on every policy (`Policies::duplicate`, row action on Policies and on the policy page; `policy.create`, reason
  required): a new policy of the same type with the source's draft (else latest version) as its draft and no assignment
  rules (applies to nobody until chosen); opens in the wizard.

**HP-8e Goal templates.** `goal_templates` (`GoalTemplate`: code, name, description, status, applicability = HROne's "Map
template", `feedback_template_id`) and `goal_template_items` (`GoalTemplateItem`: KRAs and, with `parent_item_id`, their
KPIs — title, measure, UOM, start, target, weightage, reverse, perspective, KPI rating formula). Screen Performance → Goal
templates (`GoalTemplateResource`: settings, KRAs with nested KPIs, map template, feedback template; "Add from KRA library"
copies a library KRA and its suggested KPIs, `GoalTemplates::addFromLibrary`). A template goals came from is made
inactive, never deleted. `GoalTemplates::assign($template, $employee, $cycle, $actor)` creates draft goals through
`Goals::create` (`goals.goal_template_id`; source `system` for the mapping, `manual` for a person; KRAs already on the
sheet are skipped; a person must be allowed to set the employee's goals). System mapping: goal sheet policy
`goal_creation = system` with `goal_template_code` (blank = the first active template whose conditions match) and
`goal_sheet_owner` (who fills the mapped sheet in first: employee / reporting manager — told
`performance.goal_sheet.assigned`; the reporting manager confirms it either way, below); `assignForCycle` runs when a
cycle reaches goal setting (or at launch when it has no goal-setting stage), once per employee and template, a failure
never stopping the cycle. "Use template" on the goals list and the new-goal page. Feedback form: feedback process
`goal_review` ("Goal sheet feedback by the manager", module performance, read by `performance.view`); when a cycle
reaches the manager review, the manager is asked the form of the templates the employee's goals came from
(`GoalTemplates::raiseFeedback`, one per manager review, a My tasks item); the appraisal page shows its status to HR and
managers. Options sources `goal_templates` and `competencies` (`PolicySettingsSchema::options`,
`SetupCatalogue::optionsFor`).

**Owner decision 2026-10-29: the reporting manager owns a mapped goal sheet.** A goal sheet is mapped when the employee's
goal sheet policy says `goal_creation = system` and the goal is in a cycle (`GoalSheetRules::managerOwned`). On it the
employee's own changes are proposals (`Goals::proposes` / `proposesFor`: the actor is the employee themselves):

- `Goals::create` saves the employee's addition as a draft whatever status was asked for (completed / cancelled are
  refused) and `Goals::update` keeps the status for the goal plan (the employee cannot complete or cancel a mapped goal,
  nor change a closed one); a create, a real change, or a change of the KPI rows (`update(..., keyResultsChanged: true)`,
  passed by the edit page from `Goals::keyResultRowsChanged`) calls `GoalPlans::propose`.
- `GoalPlans::propose` reuses the goal plan approval: the sheet's drafts and the changed goal (when it was active) move to
  `pending_approval` on the sheet's pending plan — a new one (`approval` manager, or workflow when the policy's
  `plan_approval` says so, even when the policy asks for no approval; `performance.goal_plan.submitted` with `proposal`
  in its context tells the managers and starts the workflow) or the pending one (its counts updated, nobody told again).
  "Submit goal sheet" by the employee goes the same way ("already waiting for approval" when nothing new is left).
- The manager (or HR) changes the sheet directly — a pending goal stays pending — and confirms it with the existing
  approval (Approval Center "Goal sheet approval", the workflow, or `GoalPlans::approve`); confirming a mapped sheet
  checks what submitting checks (the required weightage total and the policy's number of KRAs), because proposals skip
  those checks. Sending it back returns the pending goals to draft. The manager's own submission of a mapped sheet follows
  `plan_approval` as before (none = active at once). `GoalPlans::decide` now takes the employee row before the plan, as
  every proposal does (lock order).
- Screens: the goal form's status is disabled for the employee on a mapped sheet (with the reason); "Close" is hidden for
  them; the policy question reads "who fills in the mapped goal sheet first?" (stored values `employee` / `manager` keep
  their meaning; anything else reads as the employee). Check-ins stay the employee's. Manual goal sheets, goals outside a
  cycle and system runs are unchanged.

**HP-8f PIP policy** (policy type `pip`; sections Settings, Letters, Advance settings, Approval), read through
`App\Domain\Performance\Services\ImprovementPlanRules`. `duration_days` (the new plan form's end date follows the
employee's policy; two months without one), `max_extensions` (`improvement_plans.extension_count`; 0 = any). Letter types
`pip` (PIP letter), `pip_outcome` (PIP evaluation letter), `performance` (Performance letter); `letter_on_activate` +
`activate_letter_template` and `letter_on_close` + `close_letter_template` (the select offers templates of the field's
`letter_types`) drafted through `PolicyLetters` with the plan as source. `start_checklist` / `close_checklist` (item,
days, owner manager / HR / employee) become checkpoints (`improvement_plan_checkpoints.kind` start / close, `owner`):
start items when the plan starts (capped at its end), close items when the outcome is recorded — they are reviewed after
the outcome and the plan cannot be closed out before. `approval` (none / workflow): the plan waits in `pending_approval`
(trigger `performance.pip.requested`, subject type "Improvement plan") until HR with `performance.pip` /
`performance.manage` approves (it starts: checklist, letter, timeline, the employee told) or rejects it (cancelled, with
the reason); `evaluation_approval`: an outcome (completed / unsuccessful) waits in `pending_outcome`
(`performance.pip.evaluation_requested`) until approved (recorded) or sent back (the plan returns to active / extended).
Never decided by the person who opened the plan or by the employee. Decided on Improvement plans (Approve / Reject plan,
Approve / Send back outcome), in the Approval Center (`PerformanceApprovalSource` types `pip`, `pip_evaluation`) or by a
workflow (`PerformanceWorkflowBridge`); a plan with a running workflow is not listed in the Approval Center. PIP reasons
are the HP-8a masters.

**Data, permissions, settings.** Migrations `2026_11_27_200001_create_goal_sheets_and_templates` (`goal_templates`,
`goal_template_items`, `goal_plans`, `goals.goal_template_id`, `appraisal_ratings.achievement_pct`,
`appraisal_reviews.evidence`) and `2026_11_27_200002_add_pip_policy_columns` (`improvement_plans.extension_count`,
`approved_by`, `approved_at`, `decision_note`, `requested_outcome`, `evaluation_from`;
`improvement_plan_checkpoints.kind`, `owner`). `DataInventory` (goals): `goal_templates`, `goal_template_items`,
`goal_plans`. Policies (`ParityServiceProvider`): goal templates and items `PerformanceConfigPolicy`, goal plans
`EmployeeOwnedPolicy` (`ScopedByEmployee`), appraisals `AppraisalPolicy` (the employee-owned rules plus the approver).
New statuses fit their MySQL columns (`improvement_plans.status` is 16 characters, hence `pending_outcome`). No new permission keys. New setting `performance.review.copy_previous_level`
(off). New config: goal status `pending_approval`, PIP statuses `pending_approval` / `pending_outcome`, stage
`approver_review`, review type `approver`, `goal_plan_statuses`, `checkpoint_kinds`, letter types, feedback module
`performance` / process `goal_review`, workflow triggers and subject types, notification events
(`performance.goal_plan.submitted|approved|rejected`, `performance.goal_sheet.assigned`,
`performance.pip.requested|approved|rejected|evaluation_requested`). `WorkflowTrigger` listens to `PerformanceEvent` for
these three triggers only (other performance trigger names listed in the workflow builder still start nothing, as
before). Setup → Performance: policy types `goal_sheet`, `pip`; links Goal templates, Improvement plans.

**Open.** HROne's "Choose role for system mapping" was settled by the owner decision of 2026-10-29 (above). Workflow
approvers decide in their own name, so a workflow on PIP
requests needs approvers allowed to decide PIPs (`performance.pip`). Evidence files are kept on the documents disk
(`performance-evidence/`) and listed by name on the review, not offered for download.

**Tests.** `tests/Feature/HroneParity/GoalSheetPolicyTest.php`, `GoalSheetReviewsTest.php`, `GoalTemplatesTest.php`,
`PipPolicyTest.php`; owner decision: `GoalSheetManagerOwnedTest.php` (manual sheets unchanged, proposals and the
manager's confirmation, the employee's submission, workflow approval, older stored answers, the goal screens).

### Performance → bell curve, 9-box and initiatives (HP-8g Calibration aids, HP-8j Initiatives)

Matrix: `docs/hrone-parity/setup/12-performance-engage.md` (sections 12.9, 12.13, 12.14; build packages 8 and 16).
Every default keeps the earlier behaviour: no distribution check (no forced distribution), appraisals finalize and
cycles close as before, the calibration board shows the same distribution (plus targets once someone sets them), and
nobody has an initiative until one is created.

**HP-8g Calibration aids (bell curve and 9-box).**

- Bell curve: `performance_distribution_targets` (`PerformanceDistributionTarget`: cycle or null = the default, level
  value, target %). `App\Domain\Performance\Services\PerformanceDistribution`: `saveTargets(?cycle, [level => %], actor)`
  (performance.manage; every level of the scale — the cycle's, or the default rating scale for the default set; blank =
  0; adding up to 100; all blank removes the set; replaced as one audited operation, the scope's rows locked, retried on
  a deadlock); `targets($cycle)` = the cycle's own, else the default ones naming the cycle's levels (only when they still
  add up to 100); `compare($cycle, ?appraisals)` = per level count, actual %, target %, gap and "outside the tolerance",
  over the ratings that stand (final, else calibrated, else computed) placed at the level whose label they take
  (`RatingRules` rounding). Targets are an aid: nothing here changes a rating.
- Check: settings `performance.distribution.check` (`off` default / `warn` / `block`) and
  `performance.distribution.tolerance_pct` (5 points). `warning($cycle)` (over every appraisal of the cycle, not the
  caller's scope) names the levels outside the tolerance; null when off, without targets or inside. Warn: shown after
  "Finalize" (calibration board, appraisal pages: `PerformanceActions::withDistributionWarning`, a persistent warning
  notification) and in the "Close cycle" confirmation and after it; the cycle still closes and the close audit carries
  `distribution_warning`. Block: `Appraisals::close` refuses (`assertClosable`) until the ratings are calibrated or the
  targets changed; finalizing is never blocked.
- Calibration board (`CalibrationBoard`): target vs actual per level (cards with the gap, a grouped column chart
  `x-pos.columns`, the source of the targets, tolerance and check), "Filter" by department / grade (the employee's
  current position; the table and the comparison follow it), "Distribution targets" (performance.manage; this cycle or
  the default).
- 9-box: `performance_nine_box_bands` (`PerformanceNineBoxBand`: cycle or null = default, axis performance / potential,
  band low / moderate / high, from / to). `App\Domain\Performance\Services\NineBox`: performance = the rating that stands
  on the cycle's rating scale (marked provisional until final); potential = setting `performance.nine_box.potential_source`
  — `competencies` (default: the appraisal's competency rating on the cycle's competency scale, HROne's KRA × competency
  9-box) or `talent` (the "potential" of the employee's latest final talent assessment on the default talent model,
  read only for people `TalentAccess::mayViewTalent` allows — talent.view in scope, never about oneself, never a manager
  by the reporting line alone; others see "Potential not shown"). No manager-entered potential was added: PeopleOS
  already holds both. Bands: the cycle's, else the default (when its thresholds fit the axis's scale), else thirds of the
  scale (rounded to 2 decimals: 1–5 → low below 2.33, high from 3.67). `saveBands(?cycle, axis, moderateFrom, highFrom)`
  (performance.manage, validated, audited) and `resetBands`. Box names in `peopleos.performance.nine_box.boxes`
  (potential_performance: Future leader, Growth employee, Enigma, High-impact performer, Core employee, Dilemma, Trusted
  professional, Effective employee, Under-performer).
- Who sees whom (`NineBox::viewMode`): `performance.view` / `performance.calibrate` everyone of the cycle in their access
  scope (the appraisal query's `AccessScope`), by name; `performance.team` the people they manage
  (`PerformanceRelationships::teamOf`), by name; `performance.analytics` only counts per box — no names, a box below
  `performance.analytics_min_group` shown as "<k", the whole grid suppressed when fewer are placed; anyone else: no page
  (403). People without a rating or a potential score are listed under "Not placed" with the reason.
- Page `App\Filament\Pages\NineBoxGrid` (Performance → 9-box, `/admin/nine-box`): cycle picker (active / closed /
  archived; the current period's cycle first), department / grade filter, "Bands" and "Use thirds" (performance.manage),
  names linking to the appraisal, and "Initiative" on a person the viewer may assign initiatives to (HP-8j; HROne's
  9-box offers "assign initiative").

**HP-8j Initiatives.**

- `initiatives` (`Initiative`, `ScopedByEmployee`): employee, initiative type (`performance_lookups` kind
  `initiative_type`, HP-8a masters; required while any type is active), title, description, linked goal (one of the
  employee's own, not cancelled), start / due date, status planned → in progress → completed, or cancelled (with a
  reason), completion 0–100 (any progress starts a planned one; completing sets 100 and `completed_at`), outcome, owner
  (the employee who assigned it; null = self), `created_by`. Completed and cancelled initiatives are read-only; nothing is
  deleted. `initiative_feedback` (`InitiativeFeedback`): reviewer, `requested_by` (null = given without a request),
  status requested / given / ignored, feedback (audited as sensitive), `requested_at` / `responded_at`; answered once,
  never changed or deleted.
- `App\Domain\Performance\Services\Initiatives`: the employee (`performance.initiatives`), their performance managers
  (`performance.initiatives` + a configured performance-manager relationship) and HR (`performance.initiatives_manage`,
  within `AccessScopes`) create, change, start, complete and cancel; `performance.view` reads all in scope. Assigning to
  someone else stamps the owner and tells the employee (`performance.initiative.assigned`); an employee's own initiative
  tells their performance managers (`performance.initiative.created`); completing tells the other side
  (`performance.initiative.completed`). Feedback: `requestFeedback` (the employee, manager or HR asks a colleague working
  here, not the employee; one open request per colleague; `performance.initiative.feedback_requested` + a My tasks item
  `InitiativeFeedbackTaskSource`, no due date), `respond` / `ignore` (only the colleague asked, once, under a row lock),
  `giveFeedback` (managers, the person who assigned it or HR — never the employee); given feedback tells the employee and
  the requester (`performance.initiative.feedback_given`; notifications carry titles and names, never the text).
  `visibleTo` (list), `mayView`, `mayEdit`, `feedbackVisibleTo` (a colleague reads only their own feedback).
- Screen `InitiativeResource` ("My initiatives" under Me; "Initiatives" under Performance for HR): tabs All (HR), Mine,
  My team (performance managers), Asked for my feedback (with the open count); create / edit in the drawer ("Assign
  initiative to": oneself, the people one manages, HR's scope), view with the feedback, Start, Update completion,
  Complete (confirmation), Cancel, Request feedback, Give feedback, Answer feedback request, Ignore request.
- Report: dataset `initiatives` (performance.initiatives_manage or performance.view; type, goal, assigned by, dates, days
  left, overdue, completion, status, feedback requested / given) and library template "Initiative details".

**Data, permissions, settings.** Migrations `2026_12_02_200001_create_performance_calibration_aids`
(`performance_distribution_targets`, `performance_nine_box_bands`) and `2026_12_02_200002_create_initiatives`
(`initiatives`, `initiative_feedback`). `DataInventory` (performance): all four tables. Policies (`ParityServiceProvider`):
targets and bands `PerformanceConfigPolicy`, initiatives `InitiativePolicy` (as `Initiatives` decides; never deleted). New
permission keys `performance.initiatives` (Employee, Manager templates) and `performance.initiatives_manage` (Tenant HR
Admin through `performance.*`) — run `php artisan peopleos:sync-permissions`. New settings
`performance.distribution.check` (off), `performance.distribution.tolerance_pct` (5),
`performance.nine_box.potential_source` (competencies). New config `peopleos.performance.distribution_checks`,
`nine_box` (potential sources, bands, box names), `initiative_statuses`, `initiative_feedback_statuses`; notification
events `performance.initiative.assigned|created|feedback_requested|feedback_given|completed`; task source
`InitiativeFeedbackTaskSource`. Setup → Performance: the three settings and links Bell curve (calibration board), 9-box,
Initiatives.

**Open.** HROne's 9-box dashboard lets HR rename boxes and pick their colours: PeopleOS names them in config. The
optional "record as talent assessment" from a 9-box box is not built (talent assessments stay with talent.assess). HROne
also offers bell curves of periodic review ratings ("Review bell curve"): they wait for periodic reviews (HP-8i).
Initiative comments and the activity log are the audit trail (no comment thread).

**Tests.** `tests/Feature/HroneParity/CalibrationAidsTest.php` (defaults unchanged, targets per cycle / default, compare,
warn and block on close, calibration board filter / chart / finalize warning / targets form, 9-box placement and bands,
manager / analytics / employee access, talent potential, tenant isolation, the page), `InitiativesTest.php` (permissions
and templates, self-service, manager assignment, HR scope fail-closed, feedback request / answer / ignore / manager
feedback and who reads it, listing per person and tenant isolation, the screens and the dataset).

### Performance → 360 feedback policy and rounds (HP-8h), performance policy (HP-8i)

Matrices: `docs/hrone-parity/setup/12-performance-engage.md` (sections 12.2, 12.12; build packages 14, 15) and the live
forms of `docs/hrone-parity/setup/13-setup-reverification.md` (PF-1 … PF-11, packages SV-12 and SV-11), read from
`components.json` (`app-policy360-step-one`, `app-goal-policy-step1`, `-goal-review`, `-competency-review`, `-appraisal`,
`app-initiate360-feedback`, `app-initiate-review-cycle`). Every default keeps today's behaviour: without a 360 policy no
round runs; without a performance policy (or with blank answers) appraisals are launched, scored and shown exactly as
before. Both policy types are drafted in the policy wizard (their sections are its steps) and listed in Setup →
Performance.

**HP-8h 360 feedback policy** (policy type `feedback_360`, validator and reader `App\Domain\Performance\Services\
Feedback360Rules`). HROne's single "Policy" step keeps its sections, so the wizard shows them as steps: Frequency &
duration (`frequency` yearly / half-yearly / quarterly / monthly, `window_start_day` "day of the cycle", `window_days`
"window to complete 360 feedback", PeopleOS's `auto_launch`), Manager feedback (`manager_feedback`,
`functional_manager_feedback`, one `manager_anonymous`, one `manager_template_id` — as HROne's form), Peer feedback and
Subordinate feedback (`*_feedback`, `*_match` = up to three of company, location, business unit, division, department,
team, designation, level, grade, `*_max`, `*_anonymous`, `*_template_id`), Client feedback (`client_feedback`,
`client_max`, `client_anonymous`, `client_template_id`), Nomination settings (`nominator` employee / reporting manager /
role + `nominator_role`, `nomination_scope` organisation / direct reportees, `min_nominations`, `nomination_approval`
none / manager / HR) and PeopleOS's Results and privacy (`employee_sees`, `manager_sees`, `min_responses`,
`include_in_appraisal` + `appraisal_weight`). Templates are P13 feedback templates of the new process `feedback_360`
(module performance). Flat keys with follow-ups replace the earlier plan of a `relations` repeater because HROne's form
is a set of toggles per source. Blank answers: yearly, window from day 1 for 14 days, no automatic launch; peers and
subordinates anonymous, managers and clients named; the employee nominates from across the organisation without
approval; results shown after the round closes; at least 3 answers before an anonymous source is shown; no blend. The
validator refuses: no source, a source without an active 360 template, more than three criteria, a role nominator
without a role, a minimum above the maximums, a blend without its weight.

- Rounds (`feedback_rounds`, `FeedbackRound`; `App\Domain\Performance\Services\FeedbackRounds`). `launch(policy, data,
  actor)` (performance.feedback_360, HROne's "Initiate 360 feedback"): the period of the policy's frequency containing a
  day (months from the performance year's start month, January when not set) unless dates are given, the window from the
  policy unless given, the policy's population (every employed employee the policy resolves to) or chosen employees under
  it, narrowed to the actor's access scope; an employee already in an open round of the same policy and period is left
  out. The round keeps the policy's answers and a copy of each source's template questions, so changing either never
  changes a running round. An optional performance cycle links the round to appraisals (blend, below). `extend` (a later
  date, reason required, audited; clients' links follow), `close` (answers stop; clients' links die; linked appraisals are
  scored again; employees who may now read their results are told), `cancel` (reason; pending nominations cancelled).
  Schedule `peopleos:performance:feedback-rounds` (daily 06:40): closes rounds whose window has passed and launches the
  current period's round of policies with `auto_launch`, once per policy and period, when its window opens.
- Nominations (`feedback_participants` = an assessed employee's nomination set, the approval unit, HROne's "360
  nomination"; `feedback_nominations` = who is asked; `App\Domain\Performance\Services\FeedbackNominations`). The manager
  (primary reporting line) and functional managers are asked by the system at launch. The nominating person (or a holder
  of the nominating role, never about themselves; HR in scope may also nominate) chooses peers and subordinates from
  `candidates()` — employed colleagues with a login who share the policy's criteria with the assessed employee (not the
  managers the system already asks); peers
  from across the organisation or (direct reportees) those with the same reporting manager; subordinates the employee's
  reports (all performance-manager relationships, or the primary direct reports) — up to each source's maximum, one source
  per person, and clients by name and e-mail. `submit` checks the minimum and asks the reviewers at once (no approval) or
  waits for approval: the Approval Center source `Feedback360ApprovalSource` (type `feedback_360_nomination`, "360
  nomination" in Setup → Inbox actions) lists it for the reporting manager (approval "manager") or HR with
  performance.feedback_360 in scope — never the submitter or the assessed employee; approve asks the reviewers, "Send
  back" returns the set with a note. Workflow approval of nominations is not built (Approval Center only).
- Answers (`feedback_responses`, `FeedbackResponse`): the reviewer answers once, on the round's copy of their source's
  template (`FeedbackQuestions::validate`), while the window is open; "Not taking part" records HROne's "Chose not to
  participate". The answer row has a random UUID key, no timestamps, the source, the answers and a 0–100 score (the share
  of the scale of its rating and agreement answers), and links to the nomination only when the source is not anonymous —
  so an anonymous answer cannot be traced to its reviewer (like survey responses; not Auditable, listed with them in the
  architecture test's documented exceptions; the nomination's status change is audited instead).
- Clients (PF-4): each client gets an e-mail (`Feedback360Invitation`) with a signed link
  `/feedback/360/{tenant}/{token}` (route `feedback360.external`, `signed` + throttle; the signature covers tenant and
  token, so no tenant scoping is bypassed: the controller binds the tenant the link names with `runAs`, and refuses a
  tenant that is not open). The token is 64 random characters stored as a SHA-256 hash (`token_hash`, a DataInventory
  secret column), expires when the window closes (extending the window extends it), and is cleared once the client answers
  or declines; HR can send a new link (`resendLink`). The page (`resources/views/feedback360/external.blade.php`) shows
  the organisation, the employee's name and the questions, with `noindex`, `no-referrer` and `no-store` headers. Only the
  client's name and e-mail are kept (the brief's data minimisation; HROne also asks a designation).
- Results (`App\Domain\Performance\Services\FeedbackRoundResults`, the only read path — screens, export and blend):
  HR (performance.feedback_360 or performance.view) in their access scope, never about themselves; the employee's
  performance managers once the round has closed (unless `manager_sees` says no); the employee once it has closed unless
  `employee_sees` = never (open owner decision on peer visibility settled as a policy option, default after close). An
  anonymous source is shown only aggregated (per-question averages, option counts, comments in alphabetical order, no
  names) and only from `min_responses` answers; a named source shows each answer with the reviewer. "Export results"
  (CSV) gives per employee and source the asked / answered counts and the average score — blank below the minimum, never
  single answers or text.
- Blend (option, default off): when a closed round linked to the appraisal's cycle had `include_in_appraisal`, its
  average score from peers, subordinates, functional managers and clients (from at least `min_responses` answers) is
  placed on the cycle's scale, stored as the appraisal's `peer_rating` and weighed into `computed_rating` by
  `appraisal_weight` % (at most 50) in `Appraisals::score`.
- Screens: Performance → 360 feedback rounds (`FeedbackRoundResource`: list, "Launch 360 feedback", view with sources and
  nomination settings, "Employees and nominations" with statuses and results, Extend window, Close round, Cancel round,
  Export results); Me → 360 feedback (`FeedbackParticipantResource`: About me with Results, To nominate with Nominate
  reviewers / Submit nominations, To approve with Approve / Send back, All for HR); Me → 360 feedback to give
  (`FeedbackNominationResource`: Give feedback on the round's form, Not taking part). My tasks (`Feedback360TaskSource`):
  feedback to give (due when the window closes), nominations to make, periodic reviews to rate. Notifications
  `performance.feedback360.nominate|nominations_submitted|nominations_returned|requested|results_ready` (names and
  round titles only).

**HP-8i Performance policy** (policy type `performance`, `App\Domain\Performance\Services\PerformancePolicyRules`,
resolved once per employee per request — a scoped service, because visibility is asked per row). Steps as HROne's form:
Goal, Goal review, Visibility, Advance, Competency review, Appraisal. Applied:

- Goal window (PF-6): `goal_window_timing` before / after, `goal_window_offset_days`, `goal_window_days` — goals of a
  cycle are created, changed or closed by employees and managers only inside the window from the cycle start
  (`Goals::assertGoalPeriodOpen`, now told the goal's owner; performance administrators are not limited). Feedback form on
  the performance review (`review_feedback` + `review_feedback_template_id`, new process `performance_review`): asked of
  the manager when the cycle reaches the manager review (`FeedbackRequests::raise`, subject the manager review).
- Periodic reviews (PF-7; `performance_period_reviews`, `PerformancePeriodReview`, `App\Domain\Performance\Services\
  PeriodicReviews`): a `review_cycle` shorter than the appraisal cycle cuts its period into reviews, planned for every
  appraisal at launch (rated by the appraisal's manager), each with a window from the period's end (before / after by
  `review_window_offset_days`, for `review_window_days`, 15 by default). Schedule `peopleos:performance:periodic-reviews`
  (daily 06:45) opens reviews whose window has started and marks unrated reviews whose window has passed as missed;
  `review_manual_start` + `review_start_roles` leave the opening to performance administrators or holders of those roles
  ("Initiate periodic review" on the cycle; a passed window restarts from today). The reviewer (or a performance
  administrator) rates an open review in its window ("Periodic reviews" on the appraisal page).
- Roll-up (PF-11): `appraisal_basis` average of all reviews / last review / custom (`custom_weights`, one per review in
  order, adding up to 100) makes the periodic reviews the computed rating; `include_appraisal` + `appraisal_weight` weigh
  the year-end review in. Blank basis, or no rated review yet: the year-end review alone (today).
- Review rating (PF-7, PF-10): `rating_method` sum of all goals (rating share × weightage ÷ 100, at most 100 %) instead
  of the weighted average; `goal_weight` / `competency_weight` (adding up to 100) replace the cycle's weights (a goal
  sheet policy's competency weightage still comes first); `allow_above_max` + `max_goal_rating` and `competency_above_max`
  + `max_competency_rating` accept ratings above the scale (the review form offers whole steps up to the limit; the
  computed rating never passes the scale's maximum); `copy_last_level` / `copy_competency_inputs` sit between a goal sheet
  policy's answer and the organisation setting, and nothing is copied from a level the viewer may not see.
- Visibility (PF-8; `AppraisalVisibility::reviewVisible`, `ratingsVisible`, `finalCommentsVisible`): ratings and
  comments of the review chain (employee → manager → approver) — all (as before), preceding level, last level, reviewers
  (not the employee), none (HR only); final rating and final comments — all, all except the employee, last level, none.
  HR always sees everything; a reviewer always sees what they wrote. Applied on the Reviews tab (a hidden review shows its
  status only, "Read" is hidden), the appraisal list and page, Employee 360 → Performance, the periodic reviews, the
  career passport (a final rating not visible to all is left out) and copying inputs.
- Recommendations (PF-9; `appraisals.recommendations`): `recommend_training` (TNI: competencies and a note),
  `recommend_promotion`, `recommend_increment` (`increment_type` amount / percentage), `recommend_variable` add a
  "Recommendations" section to the manager and approver review forms; stored on the appraisal (masked in the audit trail),
  shown to HR and managers, and the finalize form's promotion box starts from it. At finalization the training needs
  become open development needs (source "appraisal", once per appraisal and competency) that Learning reads through
  `DevelopmentNeedsReader`; `PerformanceOutcomesReader::finalOutcome()` now carries `recommendations`, and a compensation
  cycle naming the performance cycle adds "(appraisal suggests 8%)" to each line's reason — the proposed amount is never
  changed (`PerformanceRecommendations`; Compensation still depends only on the outcomes contract).
- Eligibility (PF-11): `eligibility` + `joiner_cutoff_days` — at launch (`Appraisals::eligible`) an employee who joined
  more than that many days after the cycle's start gets no appraisal. Performance letter: `performance_letter` +
  `letter_template_id` (letter type `performance`) drafted through `PolicyLetters` when the rating reaches the employee.
- Recorded on the policy, not yet read (marked "(recorded)" in the form): `goal_cycle` (periodic goal sheets),
  `goal_manual_start` + roles (cycles are launched by HR), `joiner_goals` + days, `start_month` (Setup → Performance's
  year start applies), `show_rating`, `edit_kpi_rating` (Setup → Performance's formula rating mode applies),
  `change_system_rating`, `ack_review_rating` + role, `lock_system_achievement`, `round_review_rating`,
  `competency_with_review`, `ack_overall_rating` + role (employees acknowledge published ratings as before),
  `round_final_rating` (Setup → Performance's rounding applies). Validator: a review cycle longer than the goal cycle,
  weightages not adding up to 100, a missing highest rating, custom weightages not adding up to 100 or without a review
  cycle shorter than a year, eligibility without days, a missing feedback or letter template, an appraisal weightage
  missing.

**Data, permissions, settings, commands.** Migrations `2026_12_03_200001_create_feedback_360_rounds`
(`feedback_rounds`, `feedback_participants`, `feedback_nominations`, `feedback_responses`) and
`2026_12_03_200002_create_performance_period_reviews` (`performance_period_reviews`, `appraisals.recommendations`).
`DataInventory` (performance): the five tables; the category label names 360 answers and external client reviewers (name
and e-mail only); `token_hash` is a secret column. Policies (`ParityServiceProvider`): the four 360 models
`Feedback360Policy` (rounds HR's; sets by nominator, decider, HR in scope and whoever reads the results; nominations by
their reviewer and HR in scope; nothing updated or deleted outside the services, never deleted), periodic reviews
`EmployeeOwnedPolicy`; `PerformancePolicyRules` scoped; approval source tagged. New permission key
`performance.feedback_360` (Tenant HR Admin through `performance.*`) — run `php artisan peopleos:sync-permissions`. No new
organisation setting. New config: feedback processes `feedback_360`, `performance_review`;
`peopleos.performance.feedback_360` (relations, statuses, match dimensions, frequencies, default window 14 days, minimum
3 answers), `period_review_statuses`; notification events above and `performance.period_review.opened`; task source
`Feedback360TaskSource`. Commands `peopleos:performance:feedback-rounds` (daily 06:40) and
`peopleos:performance:periodic-reviews` (daily 06:45), both in `docs/operations/queue-and-scheduler.md`. Setup →
Performance: policy types `feedback_360`, `performance`; link "360 feedback rounds".

**Open.** Owner decision on peer visibility: settled as the policy option `employee_sees` (default: aggregated, after the
round closes) — confirm the default. Workflow approval of nominations (HROne's request workflow) is not built: the
Approval Center decides. HROne's 360 template overall rating (XC-43) and the 1-on-1 feedback process (XC-44 part) wait
for SV-24. The answers marked "(recorded)" wait for the processes that read them (periodic goal sheets, review
acknowledgement, achievement locks, per-policy rounding). Review bell curves of periodic reviews (HP-8g "Review bell
curve") are not built. A client's designation is not stored (the brief limits external data to name and e-mail).

**Tests.** `tests/Feature/HroneParity/Feedback360Test.php` (policy validation and wizard steps, launch for the population,
nominations by criteria and maximums, approval in the Approval Center and send-back, anonymity with the minimum and
visibility after close, export, window / extend / schedule close, automatic launch, client link (signature, expiry, once,
name and e-mail only), blend on / off, access fail-closed and tenant isolation, the screens) and
`PerformancePolicyTest.php` (defaults unchanged with no policy or blank answers, validator and steps, eligibility,
periodic reviews planned / opened / missed / rolled up average, last, custom, with the year-end review, manual initiation,
sum of all goals with weightage and above-max ratings, per-level visibility, recommendations to learning and the outcomes
contract, the compensation line suggestion, goal window, review feedback form and performance letter, periodic review
access and tenant isolation, the screens).

### Expense receipts and reports (EX-C) and expense advances (EX-D)

Matrix: `docs/hrone-parity/setup/11-expense-asset-helpdesk.md` (11.1, 11.2, 11.4, 11.7, 11.8, 11.10). Expenses can now be
filed, reported, decided and their outside payment recorded; advances requested, dispensed and settled. PeopleOS pays
nothing: "Pay expenses" and "Dispense advances" record the payment's reference and date. Payroll and exit money flows
(EX-E), travel (EX-F) and report datasets (EX-G) come later; projects (HP-5) and a maps provider (EXT) are not here.

- **Data** (migrations `2026_11_20_200001_create_expense_receipts_and_reports`, `2026_11_20_200002_create_expense_advances`):
  `expense_receipts` (+ `expense_receipt_taxes`), `expense_reports`, `expense_report_parts`, `expense_advances`,
  `expense_advance_entries` (ledger: one entry per key and advance, never changed). Every model is tenant-scoped,
  audited and access-scoped (`ScopedByEmployee`), guarded by `ExpensePolicy` (own records with `expense.claim`; in scope
  with `expense.view` / `expense.approve` / `expense.pay`; never deciding or paying one's own; `expense.approve` in scope
  files on someone's behalf). DataInventory category `expense` lists the six tables; receipt and advance files live
  under `tenants/{id}/expense/` (downloaded through `ParityFileController`, types `expense-receipt` / `expense-advance`).
  Numbers come from new code series types `expense_receipt` (RCP-), `expense_report` (EXR-), `expense_advance` (EXA-).
- **Receipts** (`ExpenseReceipts::capture` / `update` / `withdraw`, under a lock on the employee): the category must be in
  the employee's expense policy on the date (`ExpenseLimits`); no future dates; before joining only when the category
  allows; inside the category's filing window; not expired; the expense form matrix (`ExpenseForms`: mandatory fields for
  the category's section, hidden fields not stored, custom fields of `expense_receipt` saved as custom values); the
  description, declaration (`declaration_accepted_at`), receipt above the threshold and two odometer photos; currency
  converted at the expense date's rate (refused when there is none); mileage = distance × rate, the distance from the
  odometer (or a map distance a provider supplies: `computed_distance_km` + `distance_source = map`) editable only as
  `expense.mileage.*` allows; attendance (present, minimum time, on duty — any or named on-duty types — through the
  attendance output contract and approved on-duty requests, `ExpenseAttendance`); hourly bands with hours auto-filled
  from attendance; claim frequency; limits and the policy's overall limit — over them the claim is capped at what is left
  (`over_limit` flag, `over_limit_amount`) or, with a reason where the category allows it and exceptional expenses are on,
  filed as exceptional (exceptional-only categories always are); tax lines as printed and the supplier's GST registration
  number; duplicates (`ExpenseDuplicateDetector`: the same file refused; amount 40 + date 30/15 + merchant 15 + invoice 15
  at or above the threshold flags `suspected_duplicate`; the same invoice from the same merchant refused / flagged /
  allowed). `ExpenseUsage` is now `ReceiptUsage` (unreported, reported and approved receipts count — approved ones at
  their approved amount).
- **Reports** (`ExpenseReports`): draft → add / remove unreported, unexpired expenses (one month only when
  `expense.report.multiple_months` is off; one workflow group only when `expense.report.mixed_workflow_groups` is off,
  exceptional expenses always apart) → submit inside the windows (`expense.report_window.*`; filing on behalf is exempt;
  `window_cycle` kept). Submitting splits the report into `expense_report_parts` by workflow group
  (`ExpenseSettings::workflowGroup`: `all`, `category:<code>`, `group:<code>` per `expense.workflow.split_by`,
  `split_scope`, `exclusive_categories`, `exclusive_groups`; `exceptional` always) and raises
  `expense.report_submitted` — `expense.exceptional_submitted` for the exceptional part — per part, with
  `workflow_group`, number and amount in the context (workflow condition nodes can branch on it). A part is approved line
  by line (each line up to its claimable amount, 0 rejects it, a note per line), rejected with a reason, or the whole
  report is returned to the employee (every part is decided again after resubmission; running workflows are
  cancelled). When every part is decided the report becomes approved / partly approved / rejected; dispensed advances
  are settled against its reimbursable approved amount oldest first and the rest is `net_payable` (company-paid lines,
  `reimbursable = false`, are approved but never repaid). Cancel: a draft, returned or undecided report (its expenses
  become unreported). Mark paid (Pay expenses): reference and date, `payment_channel = bank`.
- **Advances** (`ExpenseAdvances`): request checked against `ExpenseAdvanceRules` — `refusal()` (HROne's "Expense
  advance policy needs to be configured …"), the largest advance, open advances at a time and the most outstanding
  across them (under a lock on the employee), purpose and attachment; approve (possibly for less) or reject; cancel — a
  request at once, an approved advance at once by an approver or when the policy needs no approval, otherwise
  `cancel_requested` (`expense.advance_cancel_requested`); dispense (`expense.pay`, never one's own; ledger `disbursed`,
  `settle_by` = payment date + `settle_within_days`); settled against reports (ledger `adjusted`, keyed `report-{id}`),
  refunds (keyed by the reference) and write-offs; `settled` / `written_off` when nothing is outstanding.
- **Workflows and approvals.** Trigger events `expense.report_submitted`, `expense.exceptional_submitted`,
  `expense.advance_requested`, `expense.advance_cancel_requested` (subject types "Expense report (part)" and "Expense
  advance"); `ExpenseWorkflowBridge` (on `WorkflowCompleted`) decides the part (as claimed) or the advance in the name of
  the last approver. `ExpenseApprovalSource` (tag `peopleos.approval_sources`) lists parts (exceptional ones as
  "Exceptional expense", flagged duplicates as high risk; approve / reject / return to employee), advances and advance
  cancellations that no workflow is running for.
- **Notifications.** Events `expense.report_*` and `expense.advance_*` (`ExpenseEvent`) reach notification rules; with
  no rule the manager (submissions, requests) or the employee (decisions, payments) gets an in-app note.
- **Cover letter.** `expense.report.cover_letter` (default off) shows "Download cover letter" on a report
  (`ExpenseCoverLetters`, audited): the active letter template of the new type `expense_cover` (written or Word) filled
  with the employee's letter variables and `expense.*` (report_number, title, status, period, submitted_on, decided_on,
  currency, total_claimed, total_approved, advance_adjusted, net_payable, receipts_count, approver, lines — a table on a
  line of its own), else a standard sheet with signature lines. The Letter studio's template check lists `expense.*` as
  values typed at generation (they are filled by the report, not the catalogue).
- **Screens.** Expense & travel (people who see expenses in scope) / Me (everyone else): Expense receipts / My expenses
  (Add expense, Add expense for an employee, change, withdraw; the category's limit left as a hint), Expense reports / My
  expense reports (new report, add expenses, submit, cancel, cover letter; lines and approvals with per-line approval),
  Expense advances / My expense advances (request, decide, cancel, dispense, refund, write off; ledger); Pay expenses and
  Dispense advances (`expense.pay`). Setup → Expense links all of them.
- **Settings** (Setup → Expense, groups "Expense" and "Workflow"): `expense.expiry.period` / `unit` / `based_on`,
  `expense.report_window.enabled` / `first_from` / `first_to` / `second_enabled` / `second_from` / `second_to`,
  `expense.report.multiple_months`, `expense.report.mixed_workflow_groups`, `expense.workflow.split_by` / `split_scope` /
  `exclusive_categories` / `exclusive_groups`, `expense.duplicates.check` / `threshold_percent` /
  `same_invoice_number`, `expense.mileage.distance_editable` / `distance_source` / `map_edit_allowed` /
  `map_edit_tolerance_percent`, `expense.payment.show_to_employee`, `expense.report.cover_letter`. No new permission
  keys (the `expense` group of EX-A).
- **Command.** `peopleos:expense:expire` (daily 01:25): unreported expenses past the expiry period, worked out with the
  tenant's current settings, become expired.
- **Seams for EX-E.** An approved report with `net_payable` > 0 and no `paid_on` is what a payroll input will pay;
  advance adjustments are final ledger entries; advances approved for payroll payout wait on Dispense advances; dispensed
  advances carry `settle_by` and `outstanding` for recovery. Travel links (plan / reference), booking and per-trip
  periods wait for EX-F.
- Tests: `tests/Feature/HroneParity/ExpenseReceiptsTest.php`, `ExpenseReportsTest.php`, `ExpenseAdvancesTest.php`.

### Core HR → Setup plumbing, organisation fields, employee masters, document rules (CA-1 … CA-4)

Matrix: `docs/hrone-parity/setup/01-core-hr-access-integration.md` (section 01, rows 1.1–1.28). Every default reproduces
the earlier behaviour; nothing is renamed or removed.

**CA-1 Setup plumbing.**

- Two new Setup setting types (`SetupCatalogue`, `SetupCentre`, documented at the top of `config/peopleos_setup.php`):
  `matrix` (rows × boolean columns: `rows` or `rows_from` a config list, `columns`; the form shows one row of tick boxes
  per row; stored as `{row: {column: bool}}` for every declared row and column; unknown rows or columns refused) and
  `file` (`accept` image|document, `max_kb`, `public`). A file is uploaded to the private documents disk under
  `tenants/{id}/settings` (`SettingFiles`); on save its bytes are checked (PNG / JPEG for images, the document allowlist
  without active content otherwise), a replaced or cleared file is removed, and it is served only through
  `SettingFileController` (`/settings/files/{key}`, `settings.file`): signed link (stable for a day so browsers cache a
  logo), the protected middleware chain, the setting read in the signed-in person's own organisation, `public` → any
  signed-in person else `settings.view`, `ATTACHMENT_DOWNLOADED` audit. Also: a select may be `nullable` (blank =
  null) and a text may carry a `pattern`.
- New `options_from` sources in both `SetupCatalogue::optionsFor` and `PolicySettingsSchema::options`: `articles`
  (published HR articles), `skill_scales` (0 = built-in list, then active scales), `dashboards`, `industries`.
  `projects` waits for the projects module (CA-17).
- Setup module **Access rights** (`access`): Roles (page and action access), Roles by person, Users and their data
  access (organisation scope on the user form), Security policy. No settings yet (widget / calendar / geo access and
  module data-access groups are CA-9).

**CA-2 Organisation master fields.**

- Companies: `industry` (defaults to `organisation.industry`; a new establishment takes its company's industry, else the
  organisation's — model hooks, so every creation path follows), `phone`, `fax`, `contact_employee_id` (employee
  picker), `social_links` (LinkedIn, Facebook, X, Instagram, YouTube URLs; `peopleos.organisation.social_networks`),
  `logo_path` (PNG / JPEG ≤ 5 MB under `tenants/{id}/organisation/logos`; replaced logos removed; shown on the company
  card through `ParityFileController` type `company-logo`, `company.view`). Form section "Contact and brand"; card
  section of the same name; list columns industry and phone (hidden by default).
- GSTIN: registration type `gstin` (authority GST, establishment level, state required) in
  `peopleos.compliance.registration_types`. Its format is data on the type (`pattern` + `format_hint`, `check`
  luhn_mod36, `state_prefixes` per state: the GST state code list, an identifier list, not a statutory rate), checked by
  the generic `App\Domain\Compliance\Support\RegistrationNumberFormat` when a registration is saved: GST-specific code
  stays in the Tax domain (SaaS.7 architecture rule), and HCM code does not import it. No payroll statute reads it.
- Designations: `min_experience_years` / `max_experience_years` (decimal, min ≤ max), `job_description` (rich text),
  `is_hazardous`, `wage_skill_category` (vocabulary `peopleos.compliance.wage_skill_categories`: unskilled,
  semi-skilled, skilled, highly skilled — a classification only; minimum-wage amounts come from the state rule
  versions). Form section "Role profile"; list columns. Role requirements: one optional **primary skill** per version
  (`skills[].primary`, more than one refused in `CareerArchitecture::publishRequirements`; stored only when true).
- Sub-departments: `departments.parent_department_id` ("Parent department" on the department form and list filter).
  `DepartmentHierarchy` refuses cycles and keeps the organisation tree in step: a parent set on the form moves the
  department's node under the parent's node (when both are in the tree); a department node moved in the designer takes
  the department above it as parent (or none). `EmployeeRuleContext` adds `parent_department_id` (new rule field
  "Parent department"), and every dataset with the shared employee columns, plus the employees dataset, adds
  `department_parent` (one query per run).
- Enterprise settings (Setup → Core HR, groups Enterprise / Employee): `branding.website` (letter variable
  `{{ organisation.website }}`), `organisation.industry`, `branding.logo_path` (file, public: shown in place of the
  PeopleOS monogram in the workspace brand), `branding.theme_colour` (hex; the workspace primary colour, registered
  lazily at panel boot by `TenantBranding` so it is read once the tenant is known; blank = the PeopleOS iris) and
  `branding.employee_may_change_theme` (default on; off hides the light/dark switcher for people without
  `settings.update` and drops a stored choice so the device setting applies). `branding.primary_colour` stays untouched:
  provisioning stored its unused default for every tenant, so wiring it would have changed every workspace.
- Letter variables: `company.phone`, `company.fax`, `company.industry`, `company.contact_name`, `organisation.name`,
  `organisation.website`.
- Organisation contacts: `organisation_contacts` (`OrganisationContact`, company or all companies, type HR / decision
  maker / technical administrator, name, e-mail, mobile, designation, status), Organisation → Organisation contacts
  (`company.*`), listed for the selected tenant on Platform operations.

**CA-3 Employee masters.**

- Qualifications: `qualifications` (name, level school … doctorate, status, order) with `qualification_specialisations`
  (Qualification form repeater), People Setup → Qualifications (`people_setup.*`). `person_qualifications` gains
  `qualification_id` / `specialisation_id`: the Employee 360 education form picks from the masters when there are any
  (text stays for anything else); a pick copies the names into the text columns and typed text that matches a master
  links to it. The qualifications dataset adds `qualification_level`.
- Separation reasons extend the WF-2 master (`workforce_reasons`, kind separation): `separation_kind`
  (voluntary / involuntary), `for_approver` (default on), `approver_remark_required`. HR's "Initiate exit" offers
  reasons for approvers (remark required when the reason says so); the employee's "Resign" asks for one of the reasons
  offered to employees once the organisation keeps any (and its remark rule). `exit_cases.workforce_reason_id` (the
  WF-2 column, now filled); the exits dataset adds `separation_reason`, `separation_kind` and `rehire_block_reason`.
- Rehire-block reasons are the WF-2 `blacklist` reasons: when HR marks an exit not eligible for rehire, the reason is
  required once the organisation keeps such reasons (`exit_cases.rehire_block_reason_id`); exits started elsewhere
  without an explicit choice behave as before. The pre-employee API (`GET /api/v1/pre-employees/{reference}`) returns
  `rehire_eligible` (from the latest exit; null = never exited) and `rehire_block_reason`.
- Option lists: `option_lists` (`OptionList`, `OptionLists` service, People Setup → Option lists, `people_setup.*`):
  family relations and document categories extend their built-in config lists; a row with a built-in code renames or
  hides it (built-in rows cannot be deleted), any other code adds an option; labels of hidden options still resolve.
  Used by the family form and `ChangeFamilyMemberAction`, the family-member service request, document type categories
  and every place that shows a category.
- `skills.profile_scale` (Setup → Core HR, `options_from` skill_scales, default 0 = Beginner … Expert):
  `SkillProficiencies` gives career path steps that scale's levels; `CareerPassport` compares by rank and
  `WorkforceMetrics` counts experts in the upper half of the levels.
- Fix: `WorkforceSetupPolicy::view` read a missing `employee_id` on masters (strict mode error once a reason or checklist
  existed on its list).

**CA-4 Document rules.**

- `document_types` gains `sample_path` + `sample_instructions` (shown with an "Open the sample" link in the Employee 360
  and My HR upload dialogs, `DocumentUploadHint`; served through `ParityFileController` type `document-type-sample` to
  anyone who may see document types, including employees with `document.own`), `employee_can_view` (default on; off keeps
  documents of the type from the employee), `employee_can_upload` (My HR → Documents "Upload a document": own record
  only, status pending, never verified by the uploader), `mandatory_for_employee`, `expiry_alert_days` (else
  `documents.expiry.reminder_days`; `Documents::expiringWithin`), `linked_field` (`peopleos.documents.linked_fields`:
  PAN, Aadhaar, UAN, bank account, address) + `required_with_field`: `ProfileChangeGuard::requireLinkedDocument` refuses
  entering or changing that field (statutory identity, bank account add / account number change, address add / update)
  while the employee has no current (pending or verified) document of the type.
- Missing mandatory documents (`DocumentRules`): a "Documents to upload" reminder for the employee (Needs attention →
  My HR documents tab), "Still needed from you" on My HR, and dataset `missing_documents` (one row per employee and
  missing mandatory type) with report templates "Missing documents" and "Missing joining documents".
- `documents.block_joining_without_mandatory` (default off): a pre-employee, pre-boarding or onboarding employee cannot
  move to an employed state while a "mandatory for onboarding" type has no current document (`LifecycleEngine`); a
  rehire is not affected.

**Data, permissions, commands.** Migrations `2026_11_21_200001_add_core_hr_organisation_fields`,
`2026_11_21_200002_create_core_hr_employee_masters`, `2026_11_21_200003_add_document_type_rules`. `DataInventory`:
`organisation_contacts` (organisation), `qualifications`, `qualification_specialisations` (people), `option_lists`
(configuration); files `companies.logo_path`, `document_types.sample_path`; prefixes `tenants/{id}/settings`,
`tenants/{id}/organisation`, `tenants/{id}/document-types`. Policies (ParityServiceProvider): organisation contacts
`OrganisationContactPolicy` (`company.*`); qualifications, specialisations and option lists `PeopleSetupPolicy`;
document types may be viewed with `document.own`. No new permission keys and no new commands. Route
`settings.file`.

**Tests.** `tests/Feature/HroneParity/CoreHrSetupTest.php`, `CoreHrEmployeeMastersTest.php`, `DocumentRulesTest.php`;
`SetupCentreTest` accepts the new setting types.

### Expense money through payroll and exit (EX-E) and expense reports and dashboard (EX-G)

Matrix: `docs/hrone-parity/setup/11-expense-asset-helpdesk.md` (11.1 row 16, 11.2 recovery, 11.11; build packages 8 and 12).
Approved reports and advances can now be paid, and advances recovered, through payroll and the full and final
settlement, and expenses are reported in the report library and an expense dashboard. Travel (EX-F) is not here.

- **Data** (migration `2026_11_22_200001_add_expense_payroll_and_exit_columns`): `expense_reports.payment_scheduled_at`,
  `payment_scheduled_by` (null = scheduled on approval), `payroll_run_id`, `final_settlement_id`;
  `expense_advances.disbursed_payroll_run_id`, `recovery_due_on`; `expense_advance_entries.payroll_run_id`,
  `final_settlement_id`. Run and settlement ids are plain references (no foreign keys), as on the HP-2 tables. New
  advance status `recovered`; report channel `payroll` / `settlement` (`ExpenseReport::CHANNELS`, `paymentStatus()`).
  No new tables, so `DataInventory` is unchanged (category `expense`).
- **Payment step** (`expense.payment.step_required`, default **on** = today's behaviour): approved reports wait on Pay
  expenses, which records an outside payment (as before) or **Pay with payroll** (`ExpenseReports::scheduleForPayroll`,
  `expense.pay` in scope, never one's own; bulk "Pay the selected with payroll"; channel `payroll`,
  `payment_scheduled_at`). Off: a report with a net payable is scheduled for payroll when it is decided. HROne's default
  for this question is not known; the matrix proposed off, but off would send every approved report to payroll, so the
  default keeps the payment step.
- **Payroll inputs** (tag `peopleos.payroll_inputs`; in `app/Domain/Payroll/Inputs` like the HP-2 inputs, because the
  expense domain may not name payroll runs or computations — `ExpenseInvariantsTest`):
  - `ExpenseReportInput` (key `expense_report`): reports approved / partly approved, channel `payroll`, unpaid, net
    payable > 0, scheduled on or before the period's end → one line `EXPENSE` "Expense reimbursement", type
    `reimbursement`, non-taxable, outside gross, no PF/ESI, classification `expense_reimbursement`, basis
    `{reports: [numbers], source: {key, ids}}`. Settled → `ExpenseReports::paidThrough(ids, 'payroll', payment date,
    "Payroll <period>", run)`: status `paid`, `payroll_run_id`, `paid_on` = the period's payment date (else its end).
  - `ExpenseAdvancePayoutInput` (key `expense_advance_payout`): advances approved (on or before the period's end) with
    `disbursement_channel = payroll` → one `EXP_ADVANCE` line each (reimbursement, non-taxable, `expense_advance`).
    Not in the month the employee leaves (`exit_date` on or before the period's end): finance dispenses it instead.
    Settled → `ExpenseAdvances::paidOut`: ledger `disbursed` (+), `disbursed_payroll_run_id`, settle-by = payment date +
    the policy's days, `recovery_due_on`.
  - `ExpenseAdvanceRecoveryInput` (key `expense_advance_recovery`): dispensed advances with outstanding > 0 whose
    `recovery_due_on` is on or before the period's end → one `EXP_ADV_RECOVERY` deduction each
    (`expense_advance_recovery`, basis advance, settle-by, recovery date), amount = outstanding. Nothing while the
    employee has a report waiting for a decision (its approval settles advances first). Settled →
    `ExpenseAdvances::recovered` with the amount each line deducted: ledger `recovered` (−) keyed `payroll-{run}`,
    status `recovered` when nothing is left.
  - `recovery_due_on` = settle-by + `recovery_after_days`, set when an advance is dispensed (outside payroll or with
    payroll) under a policy with `recovery = payroll`; `exit_only` / `none` leave it empty. Advances dispensed before
    EX-E have no recovery date and are never recovered by payroll (they stay as they were; the final settlement still
    recovers them at exit unless their policy says `none`).
- **Exit** (`App\Domain\Expense\Settlement\ExpenseSettlementSource`, tag `peopleos.settlement_sources`): earning
  `EXPENSE_PAYABLE` (approved unpaid reports, basis `reports: [{id, number, amount}]`) and deduction
  `EXP_ADVANCE_BALANCE` (outstanding of dispensed advances, not those whose policy says `none`, basis `advances`). Shown
  gross, one line each (the settlement nets them). Records the final salary's own `SAL_…` lines already carry (the
  payroll inputs run inside the F&F salary calculation) are left out, and so are records a payroll run not yet
  finalized or another open exit's settlement carries. `settled()` (settlement paid): every report it carried (own and
  `SAL_EXPENSE`) `paid` with channel `settlement` and the settlement's reference, every advance recovered (ledger keyed
  `fnf-{settlement}`), any advance payout in the final salary dispensed.
- **Nothing paid twice** (`App\Domain\Expense\Contracts\PayrollCommitments`, bound to
  `App\Domain\Payroll\Inputs\PendingPayrollInputs`, which reads `payroll_entries.inputs.input_sources` of runs not yet
  finalized; `App\Domain\Expense\Support\ExpenseCommitments` adds open exits' unpaid settlements): while a run or a
  settlement carries a record, Pay expenses refuses to pay or schedule the report, Dispense advances refuses to dispense
  (and cancelling is refused for) a payout it carries, refunds and write-offs are refused for an advance it recovers,
  and a report decided meanwhile does not adjust that advance. The inputs read their rows with a shared lock and skip
  records carried elsewhere, so a calculation and an outside payment of the same record serialise. Records are settled
  only by the finalized run whose lines carried their ids (`PayrollInputs::onFinalized`); finalized payroll is never
  reopened; without expense records no line is added and `input_sources` stays empty; the engine version stays
  `payroll-2.5`.
- **Exit clearance** (`expense.exit.pending_reports`, default `ignore` = today's behaviour; `block`): `Exits::clearStage`
  refuses the finance stage while the employee has reports waiting for a decision ("Expense reports still waiting for
  approval: …"). The matrix proposed `block` as the default; it stays an owner decision.
- **Screens.** Pay expenses: Payment column (waiting / scheduled for payroll since …), "Pay with payroll" (row and bulk);
  Dispense advances and the report / advance pages explain payroll payment; the report's Payment section shows a
  scheduled payroll; the advance shows "Recovered through payroll from". Setup → Expense lists both settings and links
  to the report library and Dashboards.
- **Notifications.** New events `expense.report_scheduled`, `expense.advance_recovered`; `expense.report_paid` and
  `expense.advance_dispensed` raised by payroll or the settlement carry `channel` (`payroll` / `settlement`).
- **EX-G datasets** (category "Expense & travel", permission `expense.view`, rows inside the viewer's access scope):
  `expense_receipts` (amount and mileage receipts: category, group, type, currencies, tax on the receipt, distance, rate
  per km, route, limit, over the limit, claimable, approved, exceptional, suspected duplicate, report, status),
  `expense_reports` (claimed, approved, company-paid, advances settled, net payable, paid, payment status / channel,
  scheduled, paid on / month / reference, waiting days), `expense_advances` (asked, approved, paid how, settle-by,
  recovery date, settled against reports / refunded / recovered / written off from the ledger, outstanding, days past
  settle-by), `expense_advance_ledger` (every movement, through payroll / settlement / report / outside payroll). Not
  in `analytics.unattended_datasets`: no API scope covers expenses.
- **Report library** (category "Expense & travel"): HROne's Expense advance (advance amount), Expense advance recovery
  register, Expense payment register, Expense receipt by amount, by mileage, Expense receipt details, Expense report
  details, Outstanding expense advance; plus reports waiting for approval, expense by category and by department (this
  financial year) and expense paid by month. HROne's travel templates (live trip, travel details, travel summary,
  accommodation details) wait for EX-F.
- **Expense dashboard** (AN-07): metrics `expense_reports_pending`, `expense_payable`, `expense_advances_outstanding`,
  `expense_advances_overdue` (`WorkforceMetrics`, restricted to `expense.view`). Dashboards → "Add the expense
  dashboard" (analytics managers; `AnalyticsDefaults::seedExpense()`, idempotent) adds "Expense overview" — the four
  KPIs, expense by category, outstanding advances and the payment register as shared reports — for the roles holding
  `expense.view`. It is not added at provisioning (the starter set is unchanged).
- No new permission keys, policy types or scheduled commands.
- Tests: `tests/Feature/HroneParity/ExpensePayrollTest.php` (default unchanged, Pay with payroll and permissions,
  no-payment-step scheduling, payroll payout and recovery with every "never twice" guard, exit settlement, finance
  clearance), `ExpenseReportingTest.php` (datasets, scope, every expense template with values, the dashboard and its
  restrictions).

### Engage → celebrations, HR handbook, mission statement and badges (HP-9a, HP-9b, HP-9c)

Matrix: `docs/hrone-parity/setup/12-performance-engage.md` (sections 13.0–13.4, 13.6). Setup → Engage now groups its
settings under Celebration, HR handbook and Badges, and links Badges, Recognition and Mission statement. Every new
setting's default keeps the earlier behaviour; reward points (HP-9d), the social wall (HP-9e) and the mood bot (HP-9f)
followed (section "Engage → reward points, social wall and happiness index" below).

**HP-9a Celebrations and the HR handbook.**

- Work and wedding anniversaries (`App\Domain\People\Services\Anniversaries`, kinds `work_anniversary`,
  `wedding_anniversary`), each off until `celebration.{kind}.enabled`; per kind `greeting_message` ({name}, {years} for
  work anniversaries, {organisation}), `announce_to` (none / manager / team), `visible_to` (organisation / company /
  team) and `notify_roles`. A work anniversary falls on the joining date's day and month from the first full year; a
  wedding anniversary is recorded as day and month only (`people.wedding_anniversary_month/day`,
  `wedding_anniversary_shared` default off: colleagues see and are told only when the person shares it; HR with
  `celebration.manage` always sees it) on Edit profile, the hire wizard (`EmployeeForm::weddingAnniversaryFields`),
  "My wedding anniversary" on the Celebrations page and the Employee 360. Same people and clock as birthdays (working
  here now, tenant date, 29 February on 28 February).
- The hourly `peopleos:birthdays:send` also sends each celebrated kind's day notices (claimed per day as
  `anniversaries.day`, separate from birthdays): the greeting to the employee (`celebration.{kind}.greeting`), the nudge
  to the people around them (`celebration.{kind}.today`), the day's list to the holders of `notify_roles`, and
  `employee.work_anniversary` / `employee.wedding_anniversary` (new workflow triggers and notification events, so a
  tenant can add its own rule or workflow). Nothing is sent while no kind is switched on, and the command's output is
  unchanged.
- Wishes: `birthday_wishes.kind` (birthday / work_anniversary / wedding_anniversary, default birthday); one wish per
  sender, recipient, year and kind (unique `birthday_wishes_once_per_kind_unique`, created before the old key is
  dropped so MySQL keeps an index for the employee foreign key). `Birthdays` reads only `kind = birthday`;
  `Anniversaries::wish` checks the day, the kind being celebrated, visibility and once a year.
- `celebration.birthdays.notify_roles` (multiselect of roles, default none): holders also get the day's list of the
  birthdays they may see (never their own; private ones only for celebration managers), in-app.
- The Birthdays page becomes "Celebrations" (navigation and title) with a tab per celebrated occasion (today with
  wishes, the next two weeks, any month, wishes received, CSV per month for celebration managers); with no anniversary
  switched on it is the Birthdays page as before. Home's birthday card lists anniversaries too ("Celebrations today",
  wish in one click). "Preview messages" (celebration managers) shows each switched-on greeting and the welcome filled
  in for the viewer (`Birthdays::previewMessages()`; `Birthdays::render()` fills {name} / {organisation}).
- First-login welcome: `experience.welcome.enabled` (default on) and `experience.welcome.message` (blank = PeopleOS's
  own text; {name}, {organisation}) on Home (`Home::showWelcome`, `welcomeMessage`). WhatsApp delivery of these
  messages waits for a WhatsApp provider (IP-07).
- HR handbook: `kb.acknowledgement.due_days` (0 = no due date: reminders after
  `peopleos.servicedesk.policy_acknowledgement_reminder_days` as before). When set, the My tasks item carries the due
  date (published + N days, `KnowledgeBase::acknowledgementDueOn`) and the weekly reminders start from it
  (`KnowledgeBase::reminderDays`, read by `ServiceDeskProcessor`). "Who acknowledged" (writers: every audience member
  with read and acknowledgement times of the current version, `KnowledgeBase::acknowledgementList`) and its CSV.
  Created and published date filters on the article list. `kb.download_allowed` (default off): "Download" on an article
  gives the published version as a printable page (Markdown rendered with HTML escaped; never a working copy; only for
  writers and readers in the audience; audited `DOWNLOAD`). PeopleOS has no PDF renderer, so the page is printed or
  saved as PDF by the browser (as letters are).

**HP-9b Mission statement and core values.** `mission_statements` (`App\Domain\Engagement\Models\MissionStatement`):
code, name, mission, vision, purpose, `core_values` ([{title, description, icon}]), `show_on_home`, `audience` (rule
conditions; empty = everyone), status, `effective_from`. Screen Engagement → Mission & values
(`MissionStatementResource`: HROne's Basic details / Advance details / Applicability steps as form sections; filters
policy name and status). `MissionStatements::forEmployee()` picks the statement in force whose audience matches, the
most specific first (most conditions, then the latest in force, then the newest); Home shows it as "Our mission" (side
card, all experiences; hidden when none applies or the person hides the card). Policy `MissionStatementPolicy`
(`engagement.view` to list, `engagement.manage` to change; statements are made inactive, not deleted). Badges may name
the core value they stand for (`badges.core_value`).

**HP-9c Recognition: badges and custom badges.** New domain `app/Domain/Recognition`.

- `badges` (`Badge`): HROne's nine system badges seeded at provisioning and by "Add standard badges"
  (`RecognitionDefaults`: Super Woman, Making an Impact, Making Work Fun, Amazing Mentor, Great Job, Team Player, Thank
  You, Going Above and Beyond, Out of the Box Thinker) — renamed, restyled or not allowed (`status`), never deleted or
  re-coded. Custom badges add HROne's rules: appearance (name, icon, colour, description, core value), who may give
  (`giver_roles`, `givers` rule conditions) and receive (`receivers`), nature (`visibility` public / private), receiving
  frequency (`receive_limit` per `receive_period` month / quarter / year / ever, counted per calendar or rolling period),
  `reason_required`, `attachment_required`, and the certificate (`certificate_template_id`, a letter template of the new
  type `badge_certificate` or `appreciation`). A badge that was given is switched off, not deleted.
- `recognitions` (`Recognition`, access-scoped by the receiver): the ledger — badge, receiver, giver, reason (kept out
  of the audit trail), visibility, attachments (tenant folder `tenants/{id}/recognition`, served by
  `ParityFileController` type `recognition` to whoever may see the recognition). Never changed or deleted; HR withdraws
  one once with a reason (it leaves the wall, the quota and the counts).
- `Recognitions::give()`: the giver needs `recognition.give`; the badge must be allowed (custom ones only while
  `engage.badges.custom_allowed`); giver roles and conditions, receiver conditions (and working here now), never
  oneself, reason / attachment when required; under a lock on the giver's user row the monthly quota
  `engage.badges.per_month` (default 10, calendar month on the tenant's clock, 0 = no limit), and under a lock on the
  receiver's row the receiving limit. The receiver is told in-app (`recognition.received`). The certificate — the
  badge's template, else `engage.badges.certificate_template` (template code; default none) — is generated through
  `Letters::generate` (source = the recognition, variables `badge.*`, `recognition.reference|date|given_by|reason`) and
  issued into the employee's documents when the template needs no approval; as with `PolicyLetters`, a letter error
  never takes the badge back.
- Visibility: public badges to everyone with `recognition.view`; private ones to the receiver, their manager, the giver
  and HR (`recognition.manage`, within their access scope) (`Recognitions::visibleTo`, `RecognitionPolicy`).
- Screens: Engagement → Badges (`BadgeResource`, tabs Badges / Custom badges, allow / do not allow, "Add custom badge"
  only while custom badges are allowed, "Preview certificate" through `Letters::preview` for letter issuers);
  Recognition (`RecognitionResource`, under Me for employees and Engagement for HR: "Give a badge" with the quota left,
  filters, attachments, "Certificate" for the receiver once issued, "Share on LinkedIn", "Withdraw" for HR). Home: "Wall
  of fame" side card (EN-03: who received the most public badges this calendar month, with their latest badge).
- `engage.badges.linkedin_share` (default off): the receiver of a public badge gets LinkedIn's own "Add to profile"
  certification link (badge, organisation, month, reference) — no LinkedIn key and no public page from PeopleOS.
- Report: dataset `badges_ledger` (`recognition.manage`; private and withdrawn badges included) and library templates
  "Badges ledger (this month)" and "Badges received per employee (this financial year)".

**Data, permissions, commands.** Migrations `2026_11_23_200001_add_anniversaries_to_celebrations`,
`2026_11_23_200002_create_mission_statements`, `2026_11_23_200003_create_badges_and_recognitions`. `DataInventory`:
`mission_statements`, `badges`, `recognitions` (engagement), prefix `tenants/{id}/recognition`; the new `people`
columns are personal data of the existing people category. New permission keys `recognition.view`, `recognition.give`
(Employee, Manager, HR Executive, HR Manager templates), `recognition.manage` (HR Manager; Tenant HR Admin through
`recognition.*`), entitlement module Engagement (`Capability::permissionPrefixes`) — run
`php artisan peopleos:sync-permissions`. New letter type `badge_certificate` (code series `letter.badge_certificate`).
New Setup options source `certificate_templates` (`SetupCatalogue::optionsFor`, `PolicySettingsSchema::options`). No
new command: `peopleos:birthdays:send` carries the anniversaries.

**Tests.** `tests/Feature/HroneParity/CelebrationsAndHandbookTest.php`, `MissionStatementsTest.php`,
`RecognitionBadgesTest.php`.

### Governance → Data import, Automation → Inbox actions (HS-P2, HS-P3)

Matrix: `docs/hrone-parity/setup/02-automation-forms-governance.md` (sections 02.2, 02.3, 04.2, 04.3). Every default
reproduces the earlier behaviour: uploads are approved by the person working on them, and the Approval Center decides
one item at a time, approve or reject.

**P2 Import governance.**

- **Upload types** (`App\Domain\Employment\Imports\ImportTypes`): every type on the staged pipeline (`employee_imports.type`)
  — employees, bank details, punches, monthly attendance, salaries and the `RowImportTypes` time-office and payroll
  uploads — with its name, its module (Workforce / Time office / Payroll), who may work on it (each upload screen's own
  checks) and its service. Setup `options_from: import_types`.
- **Approval rule** (`ImportGovernance`): `imports.approval_mode` = `self` (default: as before; bank and salary uploads stay
  with their uploader) / `other_person` (maker-checker: someone other than the uploader who may work on that type, and
  holds `imports.approver_role` when set) / `workflow`; `imports.approval_types` (empty = every type). Enforced on the
  model: `EmployeeImport` `updating` refuses the move to `approved` that breaks the rule, so every upload service's
  `approve()` follows it. Bank and salary uploads let the checker approve (`assertApprover`), see the upload once it is
  validated (`EmployeeImportPolicy`, `ImportGovernance::ownOrChecked` on their lists); their uploader still runs them.
  Under another approver the checker only approves or discards (inspect / map / validate stay with the uploader).
- **Workflow**: the move to `validated` raises `ImportEvent` `import.validated` (new trigger, subject `EmployeeImport` =
  "Data upload"); for types under `workflow` it starts the workflows listening for it (`WorkflowTrigger::onImport`).
  While a run is in flight nobody approves by hand; `ImportWorkflowBridge` (WorkflowCompleted) approves the upload in the
  name of the last task's completer through the type's own service (never the uploader; its rules apply again) or
  discards it on rejection (`import.rejected` to the uploader). Changing a validated upload (re-map or
  discard) cancels its run; validating again starts a new one. With no run, maker-checker applies.
- **Events and notifications**: `import.validated` (to the approver role's holders when set), `import.approved` (to the
  uploader when someone else approved), `import.rejected`, `import.completed`; rules first, else in-app
  (`NotificationEventBridge::onImport`).
- **Run start and source**: `employee_imports.run_started_at` (set on the move to `importing`), `source` (`ui` / `api`;
  every upload comes through the admin screens today).
- **Downloads** (`ImportDownloads`, header actions on every upload screen and on Recent uploads): the file as uploaded and
  a result file (every staged row's columns, action, status, employee code, errors / notes, result; bank account numbers
  stay sealed), for people who may open the upload; each audited `DOWNLOAD`.
- **Recent uploads** (`App\Filament\Pages\RecentUploads`, Audit → Recent uploads, Setup → Governance): the last twelve
  months of every upload type the viewer may work on (bank and salary: their own and those they check), filtered by month
  (default this month), upload type, module and status, searched by file and uploader: type and mode, file, uploader,
  uploaded / run started / run finished, rows / processed / succeeded / failed / errors, status and message, approver and
  rule, workflow status, source. Open goes to the upload's own screen.
- Upload screens show the rule, the workflow status, run start / finish and the source; "Waiting for …" replaces Approve
  while another person or a workflow decides.

**P3 Approval Center actions.**

- **Request types** (`ApprovalCenter::kinds()`): the center's own types (workflow step, leave, leave cancellation,
  attendance regularisation, compensation change, letter) plus every tagged `ApprovalSource`'s types, named by sources
  that implement the optional `DescribesApprovalKinds` (all four existing sources). Setup `options_from: approval_kinds`.
- **Settings** (Setup → Automation, group "Inbox actions"; `InboxActions`): `inbox.bulk_approval.types`,
  `inbox.discussion.types`, `inbox.send_back.types` (all empty by default; nothing is read from them while empty).
- **Bulk approval**: ticked items of the listed types on Approvals ("Select all", optional note, "Approve selected");
  `ApprovalDecisions::approveMany` re-resolves the queue once, re-reads each record and decides each item on its own
  (own transaction, owning service, audit); refusals are listed per item; at most 100 per call. Rejecting stays one at a
  time with a reason.
- **Discussion** (`task_comments`, `TaskComment`, `TaskComments`): one thread per workflow run (a workflow step) or per
  domain request; the approval card shows it with a message box (`Approvals::comment`, `DrawerHost::comment`); task
  inbox rows have "Discussion" for workflow steps; the requester answers on `RequestDiscussion`
  (`/admin/request-discussion?comment=` or `?return=`, reached from the notification or "Sent back to me"). Readers: the
  requester (who raised it, and the employee it is about except for compensation, letters and transfers), the approvers
  (the request in their queue, or a task of its run), earlier participants, `task.view_all`. Messages are immutable
  (≤ 2000 characters), audited without their text, and notify the other participants (`workflow.task.commented`, no
  message text).
- **Send back** (`request_returns`, `RequestReturn`, `RequestReturns`; decision `send_back`, reason required): for the
  listed types that support it — `ApprovalCenter::sendBackKinds()`: workflow steps and leave, plus the source types of
  sources implementing `SupportsSendBack` (time-office requests; loans and reimbursement claims). A source that does not
  implement it offers nothing; compensation changes and expense reports keep their own "return". A workflow step:
  `WorkflowEngine::sendBack` closes the task as `sent_back` (new `TaskStatus::SentBack`), cancels the step's other open
  tasks and puts the run in `InstanceStatus::Returned` ("Sent back"; counted as in flight by the Approval Center and the
  sources, cancellable); the initiator's `resubmit` reopens the same step with a fresh round (earlier approvals of that
  step no longer count: `nodes.<id>.since`). Runs started by the system cannot be sent back. A domain request keeps its
  pending status (balance reservations, overlap checks and payroll inputs untouched) and leaves every approver's queue
  while its return is open; its own screen may still decide it, which closes the return at the next resubmission
  attempt. The requester resubmits from Task inbox → "Sent back to me" (`?tab=returned`), from the request's own screen
  (`SendBackActions::resubmit` on leave, time-office requests, loans, claims) or `RequestDiscussion`. Events
  `workflow.task.sent_back` (to the requester) and `workflow.task.resubmitted` (to the approver); every change audited.
- **Reassign** (`WorkflowEngine::reassign`, task inbox and run viewer, `task.reassign`): a pending task to one person or
  one role, with a reason; never to the run's initiator or the employee it is about; logged `task.reassigned`, audited
  `ASSIGN`, notified `workflow.task.reassigned` ("Reassigned to you: …").

**Data, permissions, commands.** Migrations `2026_11_24_200001_add_import_governance_columns`,
`2026_11_24_200002_create_task_comments_and_request_returns`. `DataInventory`: `task_comments`, `request_returns`
(workflows). No new permission keys (uploads keep their permissions, reassigning uses `task.reassign`) and no new
commands. Notification events `workflow.task.reassigned`, `workflow.task.sent_back`, `workflow.task.resubmitted`,
`workflow.task.commented`, `import.validated`, `import.approved`, `import.rejected`, `import.completed`; workflow trigger
`import.validated`, subject type "Data upload".

**Tests.** `tests/Feature/HroneParity/ImportGovernanceTest.php`, `ApprovalCenterActionsTest.php`.

### Feedback templates, exit interviews, induction, onboarding task settings and transfer allowances (P13, WF-3)

Matrices: `docs/hrone-parity/setup/02-automation-forms-governance.md` (03.3 Feedback templates, 02.5 Task settings,
build package P13) and `docs/hrone-parity/setup/08-workforce-payroll.md` (08.1 #10–#12, 08.2, 08.3 #10–#12, 08.6). Every
new behaviour starts only when a policy, a template or a record asks for it; without them exits, confirmations,
onboarding and payroll behave as before (engine version `payroll-2.5`).

**Why not the Forms engine.** `app/Domain/Configuration` forms (`Form` / `FormVersion` / `FormSubmission`) were read
first. They collect a free-form submission, optionally approved, with no person it is asked of, no due date, no
employee it is about, no access scope and the submitted data audited in clear; their field types have no rating or
agreement scale. Feedback needs all of those, so it is a small domain of its own (`app/Domain/Feedback`) that reuses what
fits: the survey question types and agreement scale (`peopleos.engagement.question_types`, `likert_options`), the
"published copy" idea of form versions (a feedback keeps a copy of the questions it was asked with), configuration packs
and blueprints for the starter library, and the onboarding `form` item type stays as it was.

**Question banks and feedback templates (03.3).**

- `question_banks` (code, name, module, description, status) and `question_bank_questions` (title, response type,
  options `[{value, label}]`, `scale_max` for ratings, mandatory, order, status): HROne's `questionBankName`,
  `questionTitle`, `questionType`, `options` / `optionTitle`, `rating`, `questBankIsMandatory`. Screen Admin → Question
  banks (`QuestionBankResource`; questions edited in the drawer and saved with the bank by `FeedbackTemplates::saveBank`).
- `feedback_templates` (code, "Feedback name", module, `process`, description, `questions` JSON, status), bound to a
  process from `peopleos.feedback.processes`: `exit_interview` (by the employee), `exit_interview_manager`,
  `exit_interview_hr`, `confirmation` (the manager's feedback), `probation` (the employee's self-review), `induction`.
  Each process names the permission that reads its answers (`exit.interview`, `employee.lifecycle`,
  `onboarding.manage`). Questions are written in the template or picked from a bank ("From the question bank" fills the
  question; the template keeps its own copy). `FeedbackQuestions::normalise` checks them (types, 2–50 options, rating
  1–2…10, keys q1, q2 …). Screen Admin → Feedback templates (`FeedbackTemplateResource`: Used for, Questions, Asked,
  Preview, filters process and status). Templates are made inactive, never deleted.
- **Library** ("Search template"): the configuration pack `resources/packs/feedback-starter.json` (six templates — exit
  interview by the employee, the manager and HR, confirmation feedback, probation self-review, induction evaluation —
  and three question banks). "Add from library" (`FeedbackTemplates::addFromLibrary`, `feedback.manage`) adds the chosen
  templates the organisation does not have yet (same code = left alone) with the banks; the whole pack can also be applied
  from Packs & Blueprints. `Blueprints` exports and imports `question_banks` and `feedback_templates` (matched by code).
- Options source `feedback_templates` (`PolicySettingsSchema::options`, `SetupCatalogue::optionsFor`): the active
  templates of the field's `process`.

**Feedback asked of people** (`feedback_requests`, `FeedbackRequest`, access-scoped by the employee it is about;
answers are a sensitive audit attribute). `FeedbackRequests::raise` asks one feedback per subject and process, with a
copy of the template's questions, of a user or of a role's holders, with a due date; nothing is asked when the template is
missing or inactive or nobody can answer. `submit` checks the answers against the copy (mandatory, options, scale), once,
by the person or role asked, never about oneself (except the employee's own interview or self-review). Readers
(`canRead`): the respondent; holders of the process's reading permission inside their access scope (never about
themselves); for confirmation and probation, the manager's own manager when the policy copies feedback to the next
level. The employee never reads what others said about them, and the manager never reads the employee's own interview.
HR who read a process may cancel a pending feedback with a reason. Pending feedback is a "My tasks" item
(`FeedbackTaskSource`, in `peopleos.experience.task_sources`) and is answered on Me → Feedback
(`FeedbackRequestResource`: Give feedback, Answers, Cancel — the list shows only `visibleTo`).

**Exit interviews (08.3 #10–#12).** Separation policy section "Exit interviews": `interview_employee_template_id`
(blank = the standard exit interview), `interview_manager` + `interview_manager_template_id`, `interview_hr` +
`interview_hr_template_id` + `interview_hr_role` (blank = the HR clearance role). `Exits::initiate` raises them
(`raiseForExit`: the employee, the exit case's manager, the role) due on the last working day; withdrawing the
resignation cancels what is pending. When the employee's interview is on a template, "My exit interview" is replaced by
the feedback task (HR can still record the standard interview). The exit case shows the statuses ("Exit interviews
(feedback)"), never the answers. ExitInsights still analyses the standard interview only.

**Confirmation feedback (08.1 #10–#12).** Confirmation policy: `letter_on_terminate` (read already by
`ConfirmationReviews`, now declared), section "Feedback": `feedback_template_id` (the manager's feedback before the
decision), `self_review_template_id` (the employee's probation self-review), `share_feedback_with_next_level`. Opening a
review raises them, due on the probation end date; confirm / extend / terminate are refused while the manager's feedback
is pending ("The manager's confirmation feedback is still pending." — HR may cancel it); a decision cancels a self-review
still pending. Confirmation reviews show a Feedback column (statuses).

**Induction (08.2) and onboarding task settings (02.5).**

- `onboarding_templates.kind` (`onboarding` default / `induction`) and `starts_days_before_joining` (blank = as before).
  An induction programme runs as its own plan (`onboarding_plans.kind`: onboarding, induction, task_campaign) next to the
  onboarding checklist; `Onboarding::templateFor($employee, $kind)`, `start()` refuses a second plan of the same kind in
  progress; `AutoStartOnboarding` starts each kind once; `Employee::onboardingPlan()` is the onboarding-kind plan (as all
  plans were before). A template that starts N days before joining waits until then (`startsNow`) and its preboarding
  items without their own offset fall due N days before joining; `peopleos:onboarding:start-due` (daily 06:25) starts it.
  Only an onboarding plan's completion completes onboarding (timeline, notification, `OnboardingCompleted`); an induction
  records "Induction completed"; issued items just close.
- New item types `profile_section` (`profile_section`: personal, address, family, emergency contact, bank, statutory
  (PAN), education, previous employment — `Onboarding\Support\ProfileSections`; the item completes only once the section
  is filled) and `feedback` (`feedback_template_id`: raises a feedback for the item's owner; answering it completes the
  item, "Done" is refused until then; "Give feedback" on the task list). Any item may carry `declaration_text`: completing
  it needs "I confirm the declaration above"; `onboarding_tasks.declared_at` / `declared_ip` are stored and the completion's
  audit event says `declared`.
- "Issue to current employees" on Onboarding templates ("You want to apply this to: New employees only / All current
  employees matching the template", items to issue, reason): the queued `IssueOnboardingItems` job runs
  `Onboarding::issueItems`, which gives each matching current employee a `task_campaign` plan with the items they have not
  had since the item last changed (so a re-run issues nothing twice), due from today; audited with the counts.
- Onboarding plans list kind (column, filter); templates list kind and start (column, filter).

**Transfer allowances (08.6).** `salary_components.transfer_allowance` (earnings and reimbursements; the component's name
is the label — HROne's "Fields / Label on fields" without fixed slots); Payroll components gained the toggle and a
"Transfer allowances" filter, linked from Setup → Workforce. `transfer_requests.allowances` (`[{salary_component_id,
amount}]`, validated by `TransferRequests::request`: an active component marked as a transfer allowance, once, a positive
amount), `allowances_payroll_run_id`, `allowances_paid_at`; "Request a transfer" has the allowances; the request's summary
and the Transfer requests list show them (and "Due with payroll" / "Paid in payroll on …").
`App\Domain\Payroll\Inputs\TransferAllowanceInput` (key `transfer_allowance`, tag `peopleos.payroll_inputs`): approved
requests with allowances not yet paid whose transfer takes effect by the period's end → one line per allowance with the
component's code, type, tax, PF, ESI and gross treatment, basis `{transfer_request, effective_date, source: {key, ids}}`.
Requests another run not yet finalized carries are left to it (`PendingPayrollInputs`); finalizing the run records the run
and time on the request (`settle`), so a later run never pays it again. Without such requests no line is added.

**Data, permissions, settings, commands.** Migrations `2026_11_26_200001_create_feedback_templates`,
`2026_11_26_200002_add_induction_and_task_settings_to_onboarding`, `2026_11_26_200003_add_transfer_allowances`.
`DataInventory`: `question_banks`, `question_bank_questions`, `feedback_templates` (configuration), `feedback_requests`
(employment). New permission keys `feedback.view` (HR Manager, Auditor) and `feedback.manage` (Tenant HR Admin through
`feedback.*`), entitlement module Core (`Capability::permissionPrefixes`) — run `php artisan peopleos:sync-permissions`.
No new organisation setting. Policy types extended: `exit` (section Exit interviews), `confirmation`
(`letter_on_terminate`, section Feedback). Config: `peopleos.feedback` (modules, processes, respondents),
`peopleos.onboarding.item_types` (+ `profile_section`, `feedback`), `kinds`, `plan_kinds`, `profile_sections`. New command
`peopleos:onboarding:start-due` (daily 06:25). Setup → Workforce links Feedback templates, Question banks and Transfer
allowances.

**Open.** Performance and Engage feedback tabs (360°, one-on-one) wait for their packages (only the Workforce processes are
offered); exit interviews on templates do not feed ExitInsights; transfer allowances are not added to the full and final
settlement of an employee who leaves before payroll pays them (no `SettlementSource`); the generic "New employee / All
employees" `applies_to` of policies (08.1 #13) is still open.

**Tests.** `tests/Feature/HroneParity/FeedbackTemplatesTest.php`, `WorkforceFeedbackTest.php`,
`OnboardingInductionTasksTest.php`, `TransferAllowancesTest.php`.

### Payroll → CTC formula tags, loans / claims / variable pay, bank and pay modes (BP-2, BP-9, BP-13 / CA-5)

Matrix: `docs/hrone-parity/setup/08-workforce-payroll.md` (10.2 #10, #12; 10.3 #34; 10.4; 10.7 #4–#6; 10.8 #16, #32–#36,
#42–#44; 10.9 #16, #17, #24) and `01-core-hr-access-integration.md` §1.28 (CA-5). Every new setting, column and record
left at its default reproduces today's payroll: `PayrollCompletionDefaultsTest` calculates September for two employees,
writes every new setting out at its default, approves a structure version naming its CTC mode and balancing component,
records both employees as paid by bank transfer, calculates again (same lines, net, exceptions, engine version
`payroll-2.5`), then finalizes the run and compares the bank file byte for byte with the file as BankFile wrote it
before; it also reproduces an earlier structure version checksum. The engine version is unchanged: no default
calculation changed (input components and components-mode structures are new data).

**BP-2 CTC formula tags and CTC handling.**

- New calculation method `input` (`peopleos.payroll.calculation_methods`, "Employee input used by formulas, never
  paid"; `SalaryComponent::isInput()`): the value is captured on the compensation like a fixed amount
  (`component_values[CODE]`, salary uploads get a column per input) and formulas read it by its lowercase code wherever
  the input sits in the structure (`PayrollCalculator` and `StructureFormulas::preview` resolve it before the other
  variables; a missing value reads 0). It is never a line: not paid, not on the payslip or register, not in the
  breakup totals (the preview lists it as "Input" with the sample value); My compensation leaves inputs out of "Fixed
  monthly components". The structure check accepts formulas reading an input of the structure in any order, refuses a
  formula override on an input and an input missing from the structure. The component's name is the "rename".
- `salary_structure_versions.balancing_component_id` (null = none, as before) and `ctc_mode` (`ctc`, as before /
  `components`) — content of the version (frozen once submitted, copied to new versions, part of the checksum only
  when set, so earlier checksums reproduce). A balancing component must be an earning in CTC calculated every month,
  come after every other CTC line and work from `ctc_monthly`; without a formula of its own it gets the remainder
  formula `max(0, ctc_monthly - <other CTC lines> [- pf_employer])` (`StructureFormulas::balancingFormula`) as the
  structure's override when the draft is saved. In `components` mode no CTC is entered: `CompensationChanges` works the
  annual CTC out from the component amounts that are part of CTC plus the employer PF estimate on their PF wages
  (`StructureFormulas::componentsCtc`, under the EPF rule in force on the effective date; the employee's PF
  applicability), formulas may not use `ctc_monthly` / `ctc_annual` / `pf_employer`, and the preview shows no balancing
  warning. Payroll pays the amounts as before. Screens: the version draft form ("Salary is built", "CTC balancing
  component"), the Components and Preview actions, the proposal form (Annual CTC optional for such structures), the
  component form explains `input`.

**BP-9 loans, claims and variable pay.**

- `loan` policy type gains three sections (each its own wizard step): Requests — `request_window` + `request_day_from` /
  `request_day_to` (a window may run over the month end), `max_requests_per_year` (financial year, cancelled requests
  not counted), `max_emi_percent_of_net` (this loan's EMI plus open loans' against the latest finalized net pay, else
  monthly CTC through `CompensationOutput`), `allow_exception` (a request beyond the largest amount, most instalments,
  EMI share, yearly count or request days goes to the approver with `loans.exceptions`, flagged high risk in the
  Approval Center; off = refused as before); Approval — `approver_may_edit` (amount, instalments, first recovery month
  within the policy) and `interest_editable` (`Loans::approve($loan, $note, $actor, $changes)`; the schedule is planned
  on the approved terms and the request as asked kept in `loans.requested_terms`; the approve action shows the fields
  only when the policy allows them); Guarantors (shown when a guarantor is required) — `guarantors_required`,
  `max_guarantees_per_guarantor` (open loans, legacy column included), `guarantor_must_accept`. New table
  `loan_guarantors` (rows when more than one guarantor or acceptance is required; `loans.guarantor_employee_id` keeps
  the first, as before); a guarantor accepts or declines in the Approval Center (type `loan_guarantee`, "Accept" /
  "Decline"; `Loans::decideGuarantee`, only the guarantor, while the loan is pending) and is told by
  `payroll.loan_guarantor_requested`; approval waits for every guarantor when acceptance is required. The loan request
  form takes several guarantors; the loan page shows exceptions, the terms asked for and the guarantors.
- Claims (`ReimbursementClaims::claim`): `payroll.claims.one_open_per_component` (default off: a new claim waits while
  one of the same reimbursement is pending or approved and unpaid) and `payroll.claims.not_above_bills` (default off:
  bills must be listed and the claim is at most their total; off keeps "bills must add up when listed").
  `payroll.claims.reminder_task` (off / monthly / quarter_end / year_end — the last month of each financial-year
  quarter or of the year): `peopleos:payroll:claim-reminders` raises `payroll.claim_reminder` once per tenant and
  reminder month for every employed person with a pay-on-claim balance left this financial year (ledger less claims
  waiting), with an in-app note when no rule is set up.
- Variable pay plans: `variable_pay_plans` (`VariablePayPlan`: employee, earning component — a variable one once any
  is marked —, name, schedule `[{month, target}]`, active / closed) kept by payroll (`VariablePayPlans`, never for
  oneself); `variable_pay_requests.variable_pay_plan_id` + `plan_month`. On `payroll.variable.task_day` (0 = never, the
  default) `peopleos:payroll:variable-pay-tasks` asks the employee's manager for each due month's payout
  (`payroll.variable_pay_due`, once per plan and month through scheduler claims, in-app fallback). The manager or
  payroll enters it (`enterPayout`, never for oneself, once per plan month) and it becomes an ordinary pending variable
  pay request, decided and paid as before (`VariablePayInput`). `compensation.self_service.show_variable_plan` (default
  off) shows the schedule — target, payout entered and its status — on My compensation, and lets employees see their
  own plans; managers see their direct reports' plans to enter payouts. Screen: Payroll → Variable pay plans
  (`VariablePayPlanResource`: plan, change schedule, close, enter payout).

**BP-13 / CA-5 bank and pay modes (one design for HROne's Salary bank, Pay modes and Core HR Payment bank).**

- `payroll.pay_modes` (multiselect online_transfer / cash / cheque; default `[online_transfer]`) and
  `employee_payment_modes` (`EmployeePaymentMode`, effective-dated like the PF options: salary mode and paying account,
  reimbursement mode and account — null = as the salary; the latest on or before the period end applies; no row = bank
  transfer). `PaymentModes::set` (payroll.manage in scope, modes in use only, accounts only with bank transfers);
  Payroll → Payment modes. The pay mode lives in its own table, not on `employees` (Compensation invariant 8–9 keeps
  pay columns off the employee).
- `company_bank_accounts` (`CompanyBankAccount`, the debit / payment banks: company, name, bank, salary bank code,
  branch, IFSC, account number encrypted with the last four kept, file layout code, default per company, active /
  inactive; never deleted; classified highly sensitive) — Payroll → Payment banks.
- `bank_file_layouts` (`BankFileLayout`, `BankFileLayouts`): bank-specific salary files as versioned data — delimited
  (comma, semicolon, pipe, tab; quotes as needed or never) or fixed width; ordered columns (field, heading, width,
  alignment, padding, capitals, amount format decimal / whole / paise, date format, same-bank / other-bank values, fixed
  text); header and footer lines with placeholders (`{count}`, `{total}`, `{total_paise}`, `{payment_date:dmY}`,
  `{debit_account}`, `{debit_ifsc}`, `{debit_bank_code}`, …); line ending and file type. Draft → active (the code's
  previous active version retired) → frozen and checksummed; a change is a new version. Payroll → Bank file layouts
  (draft, preview with made-up rows, activate, new version).
- `BankFile`: employees paid in cash or by cheque are left out; reimbursement lines paid another way are split from the
  salary (the reimbursement part never more than net); with paying accounts each transfer is grouped under the
  account on the employee's payment mode, else the company's account at the employee's bank (IFSC bank code), else the
  default; "Bank file" asks which account and writes that account's file in its layout's active version (the export
  audit records the account, layout code, version and checksum). No modes and no accounts: the same file as before.
  `PayrollCalculator` raises `no_bank` only for employees paid by bank transfer (looked up only when no account is on
  file).
- Cash and cheque register: dataset `salary_payments` (finalized and paid runs: salary / reimbursement mode, bank
  transfer, cash and cheque amounts, paying account) and templates "Cash and cheque payments register" and "Salary paid
  by mode (last month)".
- `payroll.bank.show_ifsc_branch` (default on): off hides the IFSC column and branch on the Employee 360 bank tab (and
  the IFSC in a revealed account).
- CA-5 account number rules: `bank_account_rules` (`BankAccountRule`: bank name and / or IFSC bank code, shortest and
  longest length, digits / letters / special characters allowed) — Payroll → Bank account number rules.
  `BankAccountRules` picks the rule by the account's IFSC bank code, else by name; `ChangeBankAccountAction` (add, and
  update when the number or bank changes) and bank detail uploads refuse a number that does not fit. No rule: as
  before (up to 34 characters).

**Data, permissions, commands.** Migrations `2026_11_25_200001_add_structure_balancing_and_ctc_mode`,
`2026_11_25_200002_add_loan_policy_completion`, `2026_11_25_200003_create_variable_pay_plans`,
`2026_11_25_200004_create_payment_banks_and_pay_modes`. `DataInventory`: `loan_guarantors`, `variable_pay_plans`
(payroll); `employee_payment_modes`, `company_bank_accounts`, `bank_file_layouts`, `bank_account_rules` (banking).
Policies (ParityServiceProvider): `LoanGuarantor` and `EmployeePaymentMode` → `LoanPolicy`; `VariablePayPlan` →
`VariablePayPlanPolicy`; paying accounts, layouts and account number rules → `PayrollSetupPolicy` (payroll.view to read, payroll.manage to keep; accounts never deleted, only draft layouts). No new permission
keys (payroll.manage, payroll.request, payroll.view). Settings (Setup → Payroll): `payroll.claims.one_open_per_component`,
`payroll.claims.not_above_bills`, `payroll.claims.reminder_task`, `payroll.variable.task_day`,
`compensation.self_service.show_variable_plan`, `payroll.pay_modes`, `payroll.bank.show_ifsc_branch`; links Variable
pay plans, Payment banks, Bank file layouts, Payment modes, Bank account number rules. Notification events
`payroll.claim_reminder`, `payroll.variable_pay_due`, `payroll.loan_guarantor_requested`. Commands
`peopleos:payroll:claim-reminders` (daily 08:40) and `peopleos:payroll:variable-pay-tasks` (daily 08:45).

**Tests.** `tests/Feature/HroneParity/PayrollCompletionDefaultsTest.php`, `InputComponentsTest.php`,
`LoanClaimVariableCompletionTest.php`, `BankPayModesTest.php`.

Open: salary uploads still need an annual CTC column for a structure built from components (the figure is replaced by
the worked-out CTC); payment banks are not offered as a pick list on employees' own accounts (the rules match by IFSC
bank code or name); bank file layouts are tenant data — no bank's format is shipped (each bank's specification comes
from the bank).

### Expense → Travel (EX-F) and Asset → Asset policy and asset requests (AS-B)

Matrix: `docs/hrone-parity/setup/11-expense-asset-helpdesk.md` (11.1 rows 15 and 25–36, 11.4 rows 28–31, 11.9; 14.1 rows
1–9, 14.2; build packages 9 and 11). Trips can now be requested, approved, booked through the helpdesk, cancelled with
approval and claimed against; asset requests can be raised, approved, fulfilled by the asset type's owner role, and are
raised by themselves for joiners (asset policy) and leavers (by default since the owner decision of 2026-10-29). Apart
from those exit returns, without trips, asset policies or the new switches nothing changes.

**EX-F Travel requests**

- **Data** (migration `2026_11_28_200001_create_travel_requests`): `travel_requests` (number from the new code series
  type `travel_request`, TRV-{YYYY}-; purpose, dates, international, currency, estimated cost and its base amount, visa
  and passport numbers — `encrypted` casts, masked in audit, listed in `peopleos.data_classification.highly_sensitive` —,
  attachments under `tenants/{id}/expense/travel`, backdated reason, advance amount and `expense_advance_id`, workflow
  group, status `submitted` / `approved` / `booking` / `booked` / `rejected` / `cancel_requested` / `cancelled`),
  `travel_request_legs` (journeys and stays: category, mode, from / to city and state, travel date or check-in, return or
  check-out, preferred time, `booked_by` employee / company, booking status `pending` / `booking` / `booked` /
  `cancelled`, `booking_ticket_id`, `booking_details` — reference, provider, cost, currency, notes —, `booking_cost_base`),
  `expense_report_travel` (`ExpenseReportTravel`: the trips a report covers), `expense_receipts.travel_request_id`,
  `expense_advances.travel_request_id`. All tenant-scoped, audited, access-scoped (legs carry `employee_id`); DataInventory
  category `expense`. Custom fields of the existing entity `travel_request` are saved on the trip (`HasCustomFields`).
- **Rules** (`TravelRequests::submit`, under a lock on the employee): purpose and dates; legs in travel-linked categories
  (travel link not none, a travel or accommodation form) of the employee's expense policy, inside the trip (journeys:
  mode `peopleos.expense.travel_modes`, from, to; stays: city, check-in and check-out); `expense.travel.multi_leg` off =
  one journey (with its return) and one stay; `expense.travel.workflow.split_by` / `split_scope` /
  `exclusive_categories` / `exclusive_groups` give each leg a workflow group (`ExpenseSettings::travelWorkflowGroup`) —
  `expense.travel.mixed_categories` off refuses legs of several groups; a trip with one group carries it, a mixed one is
  `mixed` and approved by every group (owner decision 2026-10-29, below); the travel form matrix (`ExpenseForms::travelFields` /
  `travelMissing`: hidden fields are not kept, mandatory ones refused when blank; visa and passport only when
  international; custom fields of `travel_request`); backdating up to `expense.travel.backdated_days` (0 = none) with the
  delay reason when `expense.travel.backdated_reason_required` (filing on behalf by an approver is exempt, as report
  windows are); overlapping open trips refused when `expense.travel.block_overlap`, a trip may start the day another ends
  with `expense.travel.same_day_turnaround`; an advance inside the trip when `expense.travel.advance_allowed` (the expense
  advance policy applies through `ExpenseAdvances::request` with `travel_request_id`; it raises no workflow of its own and
  is left out of the Approval Center — the trip's approval approves it, rejection rejects it, cancellation cancels it
  unless paid; its settle-by date counts from the trip's end when that is after the payment). The trip raises
  `expense.travel_requested` (context: number, `workflow_group`, `workflow_groups`, dates, international, estimated cost,
  advance, legs, destinations).
- **Trips spanning several workflow groups (owner decision 2026-10-29).** One trip, one record, approved by every group:
  migration `2026_12_01_200001_create_travel_request_parts` adds `travel_request_parts` (`TravelRequestPart`: trip,
  employee, workflow group, label — the group's name as report parts show it —, status pending / approved / rejected /
  cancelled, decision stamps; tenant-scoped, audited, access-scoped, DataInventory category `expense`, Gate policy
  `TravelRequestPolicy`) and `travel_request_legs.travel_request_part_id`. `TravelRequests::submit` creates one part per
  group (the trip's `workflow_group` is `mixed`, `TravelRequest::MIXED`) and raises `expense.travel_requested` once per
  part (subject the part, new workflow subject type "Travel request (part)"; context: the part's `workflow_group`, its
  legs and destinations, `part`, `travel_request_id`, `workflow_groups`), so each group's workflow runs on its own part.
  `approvePart` / `rejectPart` (in scope, never one's own, under the employee, trip and part locks; also
  `ExpenseWorkflowBridge` for a part's workflow and the Approval Center type `travel_request_part`, "Travel request
  (workflow group)", listing only that part's legs): when every part is approved the trip is approved as before (its
  advance, the company's bookings, `expense.travel_approved`); a rejected part rejects the trip (its advance with it) and
  cancels the other open parts and their workflows; cancelling a waiting trip cancels its parts and their workflows. Such
  a trip is never decided as a whole (`approve` / `reject` refuse it; the list hides those actions, the Approval Center
  does not list the trip); its page shows "Approvals by workflow group" (`PartsRelationManager`, approve / reject on parts
  no workflow runs for) and the count of groups approved. Cancellation of an approved trip stays one decision. Trips of
  one group work exactly as before (no parts).
- **Approval and cancellation.** Approve / reject in scope, never one's own (`TravelRequestPolicy` = `ExpensePolicy` plus
  the travel desk); `ExpenseWorkflowBridge` decides trips and their cancellations when a workflow on
  `expense.travel_requested` / `expense.travel_cancel_requested` ends; `ExpenseApprovalSource` lists `travel_request` and
  `travel_cancellation` items (backdated and international trips as high risk). Cancel: a trip waiting for approval at
  once; an approved one at once by an approver, else `cancel_requested` (approval; "Keep trip" restores its booking
  status); refused once expenses are claimed against it; cancelling cancels the legs, running workflows and an unpaid
  advance and tells the booking requests' assignees.
- **Bookings** (`TravelBookings`, HROne "assign a task to a travel booking agent through helpdesk" and "who books"): on
  approval each leg the company books (category `booked_by` company, or either and the employee chose the company) is
  raised for the travel desk — one request per trip and desk, in the category's `booking_service_id` service, else the
  active ticket category of purpose `travel_booking` (journeys) / `accommodation_booking` (stays, falling back to travel
  booking). Requests are opened by the new `ServiceRequests::raiseForProcess` (source `system`, the approved trip is the
  authority: the raiser's permissions, the service's audiences and the helpdesk access policy do not apply; routing, SLA
  and approval of the service version do; idempotent per trip and desk). With no desk the legs wait on the trip. The
  travel desk (`expense.travel_desk`, in scope, never its own trip) records each company booking on the trip's "Journeys
  and stays" (reference, provider, cost in any currency kept in the base currency); the employee records their own legs.
  The trip is `booking` until the last company booking is recorded, then `booked`.
- **Expenses and reports.** A travel plan category needs one of the employee's approved trips covering the expense date,
  a travel reference category may point at one (`ExpenseReceipts`; the "Add expense" form offers the trips). Per-trip
  limits, frequencies and the overall limit count that trip's own expenses (`CountsTripUsage`, implemented by
  `ReceiptUsage`; `ExpenseLimits` context `trip_id`). Reports keep `expense_report_travel` in step with their expenses;
  `expense.report.multiple_travel` off (default) = the expenses of one trip per report.
- **Attendance calendar.** `expense.travel.on_attendance_calendar` (default off) marks the days of approved trips
  (`TravelCalendar`, read only; attendance still comes from on-duty requests).
- **Screens.** Expense & travel → Travel requests (approvers, finance, travel desk) / Me → My travel requests: Request
  travel, Request travel for an employee (approvers in scope), approve, reject, cancel, approve / refuse cancellations;
  the trip page shows the travel form (visa and passport masked) and its legs with "Record booking".
- **Notifications.** `expense.travel_requested`, `_approved`, `_rejected`, `_cancel_requested`, `_cancelled`,
  `_booking_requested`, `_booked` (rules first, else in-app to the manager, the employee or the booking requests'
  assignees). Workflow trigger events `expense.travel_requested`, `expense.travel_cancel_requested` (subject type
  "Travel request").
- **Reports.** Datasets `travel_requests` (dates, days, destinations, international, estimated cost, advance, booked cost,
  open bookings, status, trip state upcoming / live / completed, claimed and approved against the trip) and
  `travel_request_legs` (kind, category, mode, places, dates, nights, who books, booking reference, provider, cost,
  helpdesk request), `expense.view`, in scope. Report library (Expense & travel): HROne's Live trip, Travel details,
  Travel summary, Accommodation details.
- **Setup → Expense** (group "Travel"): `expense.report.multiple_travel`, `expense.travel.workflow.split_by`,
  `split_scope`, `exclusive_categories`, `exclusive_groups`, `expense.travel.mixed_categories`, `backdated_days`,
  `backdated_reason_required`, `multi_leg`, `advance_allowed`, `block_overlap`, `same_day_turnaround`,
  `on_attendance_calendar`; link Travel requests. (The help of `split_by` and `mixed_categories` now says how a trip of
  several groups is approved.)
- Behaviour change (intended by EX-C): a travel plan category's expense now needs an approved trip (EX-C accepted it
  without one until travel existed). Projects on travel plans (11.1 rows 30–31) wait for HP-5.

**AS-B Asset entitlement policy and asset requests**

- **Policy type `asset`** ("Asset entitlement policy"; wizard steps Asset assignment → Asset requests → Assignment by
  HR): `entitlements` (asset type, sub type, product — `options_from` `asset_categories`, `asset_subtypes`,
  `asset_models`, new in both `PolicySettingsSchema` and `SetupCatalogue` —, quantity), `request_scope` (mapped / any,
  blank = any), `hr_assign_scope` (any / warn / mapped, blank = any). Read by `AssetEntitlements`; no policy = as before.
  `Assets::assign` refuses an asset outside the entitlement when `mapped` and audits it (`outside_entitlement`) when
  `warn`.
- **Data** (migration `2026_11_28_200002_create_asset_requests`): `asset_requests` (number from the new code series type
  `asset_request`, AR-{YYYY}-; kind new / replacement / return; source employee / manager / hr / policy / exit;
  `source_key` unique per employee for the automatic ones; asset type, sub type, product; the asset to return or replace
  and its assignment; the asset handed over; reason, needed by, due on; status requested / approved / rejected /
  fulfilled / cancelled; the owner role; decision, fulfilment and cancellation stamps). Tenant-scoped, audited,
  access-scoped; DataInventory category `assets`; Gate policy `AssetRequestPolicy`.
- **`AssetRequests`.** Raise: the employee for themselves (`asset.request`, new permission in the employee and manager
  role templates), their line manager for them (`asset.request`), or the asset team in scope (`asset.assign` /
  `asset.manage`, source hr). The sub type and product are kept only when the raiser's audience may choose them
  (`asset.request.subtype_visible_to`, default everyone; `asset.request.model_visible_to`, default HR); under
  `request_scope = mapped` employees and managers may ask only for entitled assets. Returns and replacements name an
  asset the employee holds (one open return per asset). `asset.request.workflow_for` (none / new — new and replacement,
  the default — / return / both) decides which wait for approval: those raise `asset.requested` /
  `asset.return_requested` (workflow trigger events, subject type "Asset request"; `WorkflowTrigger` now listens to
  `AssetEvent`) and, with no workflow running, appear in the Approval Center (`AssetRequestApprovalSource`, types
  `asset_request` / `asset_return_request`) for the line manager and the asset team, never one's own;
  `AssetRequestWorkflowBridge` applies a workflow's outcome. Approved requests go to the asset type's owner role (else
  Asset Admin), due the type's `fulfilment_days` after approval; the owner role or the asset team fulfils (assigns an
  asset of the requested type / sub type / product; a replacement takes the old asset back first; a return takes it
  back) or declines with a reason; the employee, the raiser or the fulfillers cancel open requests.
- **Joiners and leavers** (`AssetRequestTriggers` in `ParityServiceProvider`): on `employee.joined`
  (`EmployeeLifecycleChanged` to Joined) the asset policy's entitlements are raised once each (per quantity), approved by
  the policy, due `asset.onboarding.due_days` (default 7) after joining. On `exit.initiated` a return per asset in
  custody, due `asset.exit.due_days` (default 0) before the last working day (never before today); `exit.withdrawn`
  cancels them. Owner decision 2026-10-29: `asset.exit.return_requests` defaults to **on** (it was off); nothing is
  raised when the employee holds no company asset; exceptions in Setup → Asset: `asset.exit.return_excluded_exit_types`
  (multiselect, new options source `exit_types` = `peopleos.exit.types`, in both `SetupCatalogue::optionsFor` and
  `PolicySettingsSchema::options`) and `asset.exit.return_excluded_categories` (asset types), both empty by default.
  Switching `return_requests` off stops them altogether.
- **Events and notifications.** `AssetEvent` gained recipients: `asset.requested`, `asset.return_requested`,
  `asset.request_to_fulfil` (owner role members, at most 25), `asset.request_approved`, `_rejected`, `_fulfilled`,
  `_cancelled` (rules first, else in-app).
- **Screens.** Assets → Asset requests (the asset team's queue in scope, due and overdue) / Me → My asset requests (own,
  raised, the team's and those the user's roles fulfil): Request an asset, Request for an employee, approve, reject /
  decline, fulfil (asset picker filtered to the request), cancel. Setup → Asset lists the eight settings (six, plus the two
  exceptions of the owner decision), the policy type and the Asset requests link.

**Permissions, commands, tests.** New permission key `asset.request` (run `php artisan peopleos:sync-permissions`; the
employee and manager templates hold it). No scheduled commands. Tests: `tests/Feature/HroneParity/TravelRequestsTest.php`
(defaults, travel form and encryption, backdating, overlap, legs and workflow groups, Approval Center and workflow
decisions, helpdesk bookings and the travel desk, the advance in the trip, cancellation, expense links and per-trip
limits, reports and trips, the attendance calendar, datasets and the four templates, permissions and the screen),
`AssetRequestsTest.php` (defaults, approval in the Approval Center and fulfilment by the owner role, no-approval kinds,
returns and replacements, visibility and mapped requests, HR assignment checks, joiners and exit returns, workflows,
screens and Setup; its defaults test now expects a return request at exit, the intended change of the owner decision).
Owner decisions of 2026-10-29: `TravelRequestPartsTest.php` (one-group trips unchanged, parts per group with their own
workflow run or Approval Center item, approval when every group has approved, rejection by any group, cancellation, the
trip page) and `AssetExitReturnsTest.php` (on by default, none without assets, exceptions by exit type and asset type,
switched off, Setup → Asset). Migration `2026_12_01_200001_create_travel_request_parts`; no new permission keys.

### Engage → reward points, social wall and happiness index (HP-9d, HP-9e, HP-9f)

Matrix: `docs/hrone-parity/setup/12-performance-engage.md` (sections 13.0, 13.2 reward rows, 13.3 achievements, 13.5,
13.7, 13.8). Setup → Engage gained the groups Reward points, Social wall and Happiness index, the policy type
`reward_points` and the links Reward points, Social wall, Mood reasons and Happiness index. All three are off by default
(`engage.points.enabled`, `engage.wall.enabled`, `engage.mood.enabled` = false), so nothing is credited, posted or asked
and payroll results are unchanged.

**HP-9d Reward points** (`app/Domain/Recognition`).

- Ledger `reward_point_entries` (`RewardPointEntry`, access-scoped by employee): append-only rows of signed points in
  three wallets — `budget` (the organisation's points, no employee), `giving` (points an employee may give) and `earned`
  (points received). Kinds: deposit, credit / credit_funding, lapse / lapse_return, award_given / award_received,
  badge_award / badge_funding, redeem / redeem_reversal, expire, adjust. Every movement is a pair, so deposits = budget +
  points to give + points received + redeemed + expired. The only change ever made to a row is a payroll redemption's
  settlement (`payroll_run_id`, `settled_at`, once); the giver's words (`note`) stay out of the audit trail.
  `dedupe_key` (unique per tenant) makes credits, lapses, expiry and badge points idempotent. `reward_point_budgets`
  is one lock row per tenant: every posting against the budget locks it first, then the employees' rows in id order.
- `RewardPoints`: `deposit` (HR, `recognition.points`), `credit` (per employee and cycle: lapse unused points of earlier
  cycles back to the budget unless the policy carries them forward, then pay the cycle's points from the budget — a
  credit the budget cannot cover waits for a later run; expire points received longer ago than the policy's expiry,
  oldest first), `give` (an employee gives their points to a colleague: balance, the giver's policy limit per award, the
  receiver's limit per cycle, never oneself, only colleagues working here; in-app notice `recognition.points_received`),
  `awardBadge` / `reverseBadge` (a badge's points from the budget when given, back when withdrawn; a short budget never
  stops the badge), `redeem` (through payroll only: `engage.points.redeem_modes` contains `payroll`, a points value is
  set and its currency is the base currency; the employee themselves only with `engage.points.self_redeem`, else HR in
  scope; the amount is fixed at redemption), `cancelRedemption` (HR, never once a payroll run carries it), `adjust` (HR,
  from / to the budget, never one's own).
- Policy type `reward_points` (validator `RewardPointRules`; wizard steps Basic · Reward points · Eligibility, then
  the platform's applicability and review): `credit_cycle` (monthly, quarterly, half yearly, yearly — calendar periods on
  the tenant clock), `points_per_cycle`, `max_per_award` (HROne's employee reward limit), `max_received_per_cycle`,
  `carry_forward`, `expiry_months`, and `eligibility` rows (months of service or number of direct reports, from / to,
  points) whose first match replaces the points per cycle.
- Settings: `engage.points.enabled`, `engage.points.value_per_point` (nullable number) and `engage.points.currency`
  (blank = `tenant.base_currency`) — HROne's "one-time setting": `SetupCatalogue` now honours `locked_after` =>
  [class, method] on a catalogue entry (`RewardPoints::ledgerStarted`, true once the ledger has a row); a locked setting
  is shown read-only and `save()` refuses to change it. Number settings may now be `nullable`.
  `engage.points.redeem_modes` (none / payroll; vendor vouchers need a provider), `engage.points.self_redeem`.
- Badges (custom badge "Reward applicability"): `badges.reward_points` (0 = not linked) and `show_points_on_wall`.
- Payroll: `RewardPointsInput` (tag `peopleos.payroll_inputs`): unpaid, unreversed redemptions up to the period end are
  paid as one taxable earning `REWARD` (outside gross, no PF/ESI), carrying the ledger ids; finalizing the run records
  the run on them; a redemption another unfinalized run carries is left to it. With no redemption nothing is added (the
  engine version stays `payroll-2.5`).
- Credit run `peopleos:engage:credit-points` (daily 01:30, `TenantRunner`, idempotent; documented in
  `docs/operations/queue-and-scheduler.md`).
- Screen Reward points (`RewardPointEntryResource`, Engagement for HR, Me for employees once points are on): balances
  in the header (points to give, points received, the budget for HR), Give points, Redeem my points, Deposit points,
  Redeem for an employee, Adjust points, Cancel redemption. Dataset `reward_points_ledger` (`recognition.points`) and
  templates "Reward points ledger (this month)", "Reward points redeemed (this financial year)".

**HP-9e Social wall** (`app/Domain/Recognition`, `SocialWall`).

- `wall_posts` (type, subject, the person it is about, company, author, title, body, meta, attachments, pinned, status,
  hidden by / at / why, dedupe key), `wall_reactions` (one per person and post: like, celebrate, love, applause),
  `wall_comments` (never edited; removed by the author, hidden by a moderator). Nothing is deleted.
- Posts: HR posts (`wall.post`; up to three attachments while `engage.wall.attachments`, served by
  `ParityFileController` type `wall-post` to whoever may see the post); system posts once each from listeners in
  `ParityServiceProvider`: public badges (`RecognitionEvent` `recognition.given`, new; hidden when withdrawn, with the
  badge's points when `show_points_on_wall`), points between colleagues (`recognition.points_given`), celebrations
  (`EmployeeReminderDue` of the hourly birthday run: shared birthdays and wedding anniversaries, work anniversaries),
  announcements (`communication.published`), new joiners (`EmployeeLifecycleChanged` the first time someone becomes a
  working employee) and, while `engage.wall.show_achievements`, completed goals (`performance.goal.completed`, now
  dispatched by `Goals::close`) and learning certificates (`learning.certificate.issued`).
- Visibility: `wall.view` (or `wall.moderate`) while the wall is on; `engage.wall.scope` = company shows the viewer's
  company's posts and organisation-wide ones; a viewer with an access scope sees posts about people in it; an
  announcement only its recipients; a celebration only those who may see it under the celebration's own rules. The
  People directory is unchanged: it already follows access scopes, and employees see only their own circle there.
- Reactions and comments (`engage.wall.comments_disabled_for`: HR post, badges, skill endorsement, rewards, goal
  achievement — also learning certificates — new joiner intro); moderation (`wall.moderate`: hide / restore posts and
  comments with a reason, pin); "Report" tells the moderators in-app (`wall.reported`).
- `engage.wall.festive_theme`: on a holiday of the viewer's holiday calendar the wall and the Home card greet them with
  the holiday ("Happy Diwali!"); the theme is not rebuilt, so this is a greeting, not a colour change.
- Screens: Social wall page (Engagement for moderators, Me for others) and the Home side card "Social wall" (latest
  three posts, one-click like), in the existing `.pos-home` panel classes.

**HP-9f Happiness index (mood bot)** (`app/Domain/Engagement`).

- Settings `engage.mood.enabled`, `engage.mood.frequency` (daily, weekly — default —, fortnightly, monthly),
  `engage.mood.weekday` (the weekly / fortnightly question opens on this day; default Friday) and `engage.mood.privacy`
  (anonymous — default —, confidential, identified).
- `mood_reasons` (`MoodReason`): at most 20 active; HROne's fourteen seeded at provisioning (`MoodDefaults`, also "Add
  standard reasons"); made inactive, never deleted. Screen Mood reasons (`MoodReasonResource`, engagement.view /
  engagement.manage) with "Add new reason" and "Preview".
- `MoodCheckIns`: one check-in per employee and period through the survey anonymity boundary — `mood_participations`
  (identity side: employee, period, date only, unique) and `mood_checkins` (content side: random UUID, no timestamps,
  period, group key, mood 1–5, reason ids, comment; an employee and a date only when identified; confidential
  authorship only in `engagement_identities`, revealed through `ConfidentialIdentities` subject `mood_checkin`). The
  group key is the department merged by `GroupKeys` (groups of at least the privacy threshold of eligible employees),
  fixed per period. Audit: `MOOD_CHECKIN_SUBMITTED`, anonymous unless identified, never the mood, reasons or comment.
  Both models are on the architecture test's not-Auditable list for that reason.
- `MoodAnalytics` (engagement.analytics with an unrestricted scope): only periods that have ended; nothing below
  `engagement.analytics_min_group` check-ins; department groups with complementary suppression; reasons picked;
  comments overall only, for `engagement.comments`, above `text_min_group`, shuffled (a reveal handle only on
  confidential ones for `engagement.confidential_identity`); identified check-ins for `engagement.responses` in scope.
  Page Happiness index; dataset `happiness_index` (one row per period and group, suppressed cells left out, no comments)
  and template "Happiness index by period".
- Home: "How's your mood today?" side card while the bot asks this person (five moods → reasons, comments).

**Data, permissions, commands.** Migrations `2026_11_30_200001_create_reward_points`,
`2026_11_30_200002_create_social_wall`, `2026_11_30_200003_create_mood_checkins`. `DataInventory` (engagement):
`reward_point_budgets`, `reward_point_entries`, `wall_posts`, `wall_reactions`, `wall_comments`, `mood_reasons`,
`mood_participations`, `mood_checkins`, prefix `tenants/{id}/wall`. New permission keys `recognition.points` (HR
Manager; Tenant HR Admin through `recognition.*`), `wall.view` (Employee, Manager, HR Executive, HR Manager),
`wall.post` and `wall.moderate` (HR Manager; Tenant HR Admin through `wall.*`), `engagement.mood` (Employee, Manager,
HR Executive, HR Manager; Tenant HR Admin through `engagement.*`); entitlement module Engagement gained the prefix
`wall` — run `php artisan peopleos:sync-permissions`. New audit action `MOOD_CHECKIN_SUBMITTED`. Command
`peopleos:engage:credit-points`.

**Tests.** `tests/Feature/HroneParity/RewardPointsTest.php`, `SocialWallTest.php`, `HappinessIndexTest.php`.

**Open.** Vendor vouchers for redemption (`pointRedeemType` / vendors) need a voucher provider (EXT); skill endorsement
posts wait for an endorsement feature (the comment restriction exists); the festive "theme" is a greeting, not a colour
variant, until the theme is rebuilt.

### Automation → Delegate, Archive inbox, Employee request lock, Workflow coverage (HS-P4, HS-P5)

Matrix: `docs/hrone-parity/setup/02-automation-forms-governance.md` (sections 02.2, 02.3, 02.6, 02.11; notable findings
3, 4 and 6). Every default reproduces the earlier behaviour: nothing is delegated, archived or locked, and existing
workflows start and decide exactly as before.

**P4 Delegation ("out of office").**

- `task_delegations` (`TaskDelegation`, audited; `TaskDelegationPolicy`): delegator, delegate, from / until, request
  types (empty = every type switched on), reason, status active / revoked. `TaskDelegations` (scoped) creates and ends
  them: never to oneself, not in the past, at most 366 days, one period per person at a time (serialised on the
  delegator's user row), never to someone out of office then too (no chains), only request types in
  `inbox.delegation.types`; people set up their own (`task.delegate`), `task.delegate_any` anyone's. Creating notifies
  the delegate (`workflow.task.delegated`, in-app when no rule).
- **Approval Center.** `ApprovalCenter::gather` adds, while delegation is switched on, each running delegator's queue as
  that delegator sees it (gathered signed in as them through `ScreenAccess::as`, so their scope and permissions decide
  what is listed), for the delegated types, without the delegate's own requests (raised by them or about them,
  `TaskDelegations::isOwnRequest`) and without items already in the delegate's own queue. Such items carry
  `ApprovalItem::$onBehalfOfUserId / $onBehalfOfName / $delegationId` and the fact "On behalf of … (out of office until
  …)". `ApprovalDecisions` re-checks the delegation and decides through `TaskDelegations::actFor`: the owning service
  runs with the delegator as the actor (their authority, separation of duties against them) inside
  `Workflow\Support\DelegatedDecision`, and `AuditRecorder` records the delegate as the actor with metadata
  `on_behalf_of` {user_id, name, delegation_id}. Bulk approval and send back follow the same path.
- **Task inbox.** "Waiting for me" (`TaskInbox::waitingFor`, also the badge) adds the pending workflow steps of running
  delegators (assigned to them or their roles; never runs the delegate started); `TaskActions` decides them as the
  delegator; `workflow_tasks.delegate_id` keeps the delegate (`completed_by` stays the assignee) and the workflow log
  names the delegate with `on_behalf_of`. "Handled by me" and the Approval Center's completed list include them.
- Screen: Workflows → **Out of office** (`OutOfOffice`): own periods and those handed to the viewer (everyone's with
  `task.delegate_any`), "Set up out of office", "End now"; listed when delegation is switched on.

**P4 Archive inbox.**

- `inbox_archives` (`InboxArchive`, audited; per person, keyed by Approval Center item id and type), service
  `InboxArchives` (scoped). Archiving hides the item from that person's queue and counts (`ApprovalCenter::pending`,
  `count`, the task inbox) — the request stays pending, its SLA and escalation run on; restoring brings it back.
- `inbox.archive.types`: per-item "Archive" on the approval card (Approvals page) and on task inbox rows.
  `inbox.archive.enabled` + `.mode` + `.after_days` + `.excluded_types`: manual mode offers "Archive them" (items waiting
  longer than N days) on Approvals; auto mode archives them nightly with `peopleos:inbox:archive` (daily 01:30, each
  active person's queue as they see it; an item the person restored is never archived again). Approvals lists
  "Archived" with Restore; the task inbox has an "Archived" tab. With archiving switched off entirely, archived items
  show again.
- Archives are per person rather than `archived_at` columns on `workflow_tasks` / `onboarding_tasks` (the matrix
  sketch): most Approval Center items are domain requests, and one approver archiving must not hide a request from the
  others. Onboarding tasks (a person's own checklist, not approvals) are not archived.

**P4 Employee request lock.** `App\Domain\Experience\Services\SelfServiceLock::assertOpen($employee, $kind, $requester,
$exception)` runs first in every self-service request path — `Leaves::request` (leave), `TimeOfficeRequests::request`
(time_office), `Regularisations::request` (regularisation), `Loans::request` (loan), `ReimbursementClaims::claim`
(reimbursement_claim), `VariablePay::request` (variable_pay), `ExpenseReports::submit` / `ExpenseAdvances::request`
(expense), `TravelRequests::submit` (travel), `AssetRequests::request` (asset_request), `ServiceRequests::submit` /
`openGeneric` / `submitDraft` (service_desk; a saved draft is not a request) — throwing the domain's own rule exception.
It applies only to the employee's own requests (HR or a manager acting on behalf, and the system, are never locked).
Settings (Setup → Automation, group "Employee request lock"): `inbox.ess_lock.enabled`; `.basis` (overdue_tasks / period
/ both); `.after_days` (1–90): a task of the counted kinds overdue by more than N days locks, and the refusal names the
tasks (the lock lifts when they are done); `.task_kinds` (`employee_task_kinds`: the My tasks sources — workflow,
knowledge, communication, engagement, feedback, performance, assets, servicedesk — plus onboarding tasks the person
owns; blank = all); `.period_from` / `.period_to` (dates: requests refused in between); `.request_kinds`
(`ess_request_kinds`; blank = all); `.employee_ids` (locked employees, blank = all) and `.exempt_employee_ids`.
New option sources `employees` (the saver's access scope, "Name (code)", searchable in the Setup centre),
`employee_task_kinds`, `ess_request_kinds`, in both `SetupCatalogue::optionsFor` and `PolicySettingsSchema::options`.

**P5 Workflow coverage.**

- **When a workflow decides its request** (`Workflow\Support\RequestWorkflows::decides`): the workflow runs on that
  request type ("Runs on"), or it runs on any record and was started by one of the request's P5 approval triggers.
  Workflows on the earlier triggers of regularisations (`attendance.regularisation_requested`) and letters
  (`letter.requested`) that run on any record decided nothing before and still decide nothing. While a deciding run is in
  flight the Approval Center leaves the request to its workflow task (`RequestWorkflows::inFlight` in the regularisation,
  compensation and letter gatherers and in `PayrollRequestsApprovalSource`). Every bridge applies the outcome through
  the owning service in the name of the person who completed the last task (`RequestWorkflows::lastCompleter`), so the
  service's rules (never one's own, scope, guarantors, separation of duties) apply again; a refused step leaves the
  request for the Approval Center.
- New subject types: Attendance regularisation, Loan / salary advance, Reimbursement claim, Variable pay request,
  Compensation change, Letter, Exit case (resignation), Investment declaration. New triggers: `payroll.loan_requested`,
  `payroll.claim_submitted`, `payroll.variable_pay_requested`, `payroll.tax_declaration_submitted`,
  `compensation.change.submitted`, `compensation.change.reviewed`, `exit.resignation_submitted` (`WorkflowTrigger` now
  hears `PayrollEvent` and `CompensationEvent`).
- Bridges (`ParityServiceProvider`): `RegularisationWorkflowBridge`, `PayrollRequestWorkflowBridge` (loans, claims,
  variable pay), `CompensationWorkflowBridge` (one step per run: a submitted change is reviewed, a reviewed change
  approved, or rejected — run one workflow on "submitted" and another on "reviewed"; it lives in the Workflow domain
  because Compensation listens to no other module, CompensationInvariantsTest 15), `LetterWorkflowBridge`,
  `ResignationWorkflowBridge` (approved: `Exits::acceptResignation` sets `exit_cases.resignation_accepted_at/by`, audited,
  timeline, event `exit.resignation_accepted`; rejected: the resignation is withdrawn), `TaxDeclarationWorkflowBridge`
  (`InvestmentDeclarations::verify` / `returnToEmployee`, never one's own).
- **Domain events** (`PayrollEvent`, references only; all in `notifications.events`): `payroll.loan_requested /
  _approved / _rejected / _disbursed`, `payroll.claim_submitted / _approved / _rejected / _paid`,
  `payroll.variable_pay_requested / _approved / _rejected`, `payroll.tax_declaration_submitted` (a declaration newly
  submitted) / `_verified` / `_returned`; `ExitEvent` `exit.resignation_submitted` (employee self-service resignations)
  and `exit.resignation_accepted`.
- **Document node** (`WorkflowEngine::generateDocument`): config `letter_template_id` and `issue` (draft / issue at once
  when no approval is needed) → `Letters::generate` for the run's employee (numbered and approved as the template says;
  the run's subject is the letter's source), `letter_id` in the run context, log `document.generated`. A node without a
  template is skipped as before; a missing or inactive template, or a run not about an employee, fails the run.
- **Approvers** (`ApproverResolver`): `functional_manager` and `hrbp` resolve the subject employee's current
  `functional` / `hrbp` reporting relationship; an inactive functional manager goes to the manager reliever, an HR
  business partner who cannot act to the node's fallback.
- **Deciding automatically** (turnaround time at the last level): escalation action `auto_decide` with a decision
  (approve / reject), accepted on publish only on an approval step after which no other approval step can follow
  (`WorkflowDefinition::isLastApproval`; anywhere else the engine only reminds). `WorkflowEngine::autoDecide` records a
  system decision (`completed_by` empty, log `task.auto_decided`, audit metadata `system`).
- **Conditional workflow**: `workflows.priority` (default 100; form, hidden column) and setting
  `workflow.first_match_only` (default off = every match starts, as before); on, `WorkflowTrigger::fire` tries the
  matching workflows by priority (lower first, then the oldest) and starts only the first whose start conditions match.
  The workflows list gained a "Runs on" filter. The `employee_id` rule field (advance applicability) already existed.

**Data, permissions, commands.** Migrations `2026_11_29_200001_create_task_delegations_and_inbox_archives`
(`task_delegations`, `inbox_archives`, `workflow_tasks.delegate_id`), `2026_11_29_200002_add_workflow_priority_and_resignation_acceptance`
(`workflows.priority`, `exit_cases.resignation_accepted_at/by`). `DataInventory`: `task_delegations`, `inbox_archives`
(workflows). New permission keys `task.delegate` (every role template holding `task.act`) and `task.delegate_any`
(templates with `task.*`) — run `php artisan peopleos:sync-permissions`. New command `peopleos:inbox:archive` (daily
01:30). Settings (Setup → Automation): `inbox.delegation.types`, `inbox.archive.types`, `inbox.archive.enabled`, `.mode`,
`.after_days`, `.excluded_types`, `inbox.ess_lock.*` (nine keys), `workflow.first_match_only`; link "Out of office".

**Tests.** `tests/Feature/HroneParity/DelegationArchiveLockTest.php` (defaults; delegated decisions audited on behalf;
own requests, other types and ended periods; validation and permissions; delegated workflow steps; the Out of office
screen; manual archive and restore; nightly auto-archive with exclusions and restored items; manual bulk archive; the
task inbox Archived tab; the lock by overdue tasks, by period, by employees and kinds, HR on behalf; every self-service
path guarded; Setup options), `WorkflowCoverageTest.php` (earlier workflows decide nothing; regularisation, loan,
compensation, letter, resignation and declaration workflows; payroll events; document node; functional manager and
HRBP; auto-decide at the last level only; first match by priority; configuration).

Not built here: "Formula" conditions (02.11), FBP and settlement workflows (FBP is missing; full and final settlement
keeps its own approval), letter-acceptance tasks for the lock (with P8), polls (HP-9).

### Core HR → Profile access, Social profile access (CA-6) and My profile (MS-2)

Matrices: `docs/hrone-parity/setup/01-core-hr-access-integration.md` §1.21–1.22 (package CA-6) and
`docs/hrone-parity/modules/22-my-space-self-profile.md` (package MS-2; rows 22.2.0 #4, 22.2.1 #6/#7/#11/#13/#14/#18,
22.2.2 #1/#2/#5, 22.2.3 #4, 22.2.7 #1/#2, 22.2.9 #2–5/#8/#9, 22.2.10 #3, 22.3 #8). Every default reproduces the earlier
behaviour.

**CA-6 Profile access (`profile.section_visibility`).**

- Sections `peopleos.profile.sections`: personal, family, professional, skills, bank, documents, previous_employer,
  other_details, history (health insurance joins with MS-5; candidate profile is RMS). Audiences
  `peopleos.profile.audiences`: employee (the person themself), manager (someone who manages them, the configured
  manager relationship types as `Employee360` uses), others (anyone else who may open the profile).
- Setting `profile.section_visibility` (Setup → Core HR, group "Profile access", the CA-1 `matrix` type, rows from
  `peopleos.profile.sections`); default every box ticked = as before. `App\Domain\Experience\Services\ProfileSectionVisibility`
  (request-scoped, in `EXPERIENCE_SCOPED`): `audience()`, `allows(viewer, employee, section)`, `allowsAudience()`. It is an
  extra AND and never grants: callers keep their own rule and add it. People who maintain employee records
  (`employee.update` or `employee.sensitive.update`) are not an audience — HR always sees the full record. Unknown sections
  or audiences are refused; a row missing from a stored matrix takes the platform default.
- Applied to: the Employee 360 tabs (Addresses and Emergency contacts → personal, Family → family, Education and Previous
  employment → professional, Skills, Bank accounts → bank, Documents, Timeline → history; `PersonSatelliteRelationManager`
  carries `$profileSection`), "Additional information" (custom fields → other_details), the `Employee360` summaries skills
  and documents, the workspace's recent changes (history), My HR documents and "Still needed from you" (employee audience),
  and every section of My profile.

**CA-6 Social profile access (directory card).**

- Settings (Setup → Core HR, group "Social profile access", `options_from` `directory_sections` =
  `peopleos.directory.sections`): `directory.sections_visible` (experience, education, skills, goals, planned_leaves,
  last_checkin, off_days; default none = the card as before) and `directory.sections_employee_may_hide` (default none).
- `App\Domain\Experience\Services\DirectorySections::for(viewer, employee)`: only for people the viewer may already find
  (`PeopleVisibility::canSee`), each section through its owner — experience / education (People), skills
  (`SkillProfiles::profile`, names and levels), goals (only goals the viewer may already open under the Performance
  policy; sharing with colleagues is MS-6), planned leaves (`Leaves::approvedBetween`, next 60 days, "Away" with dates
  only, never type or reason), last check-in and days off (new `App\Domain\Attendance\Services\AttendanceGlance`: last
  punch time; holidays via `HolidayResolver::holidayOn`, including chosen restricted holidays, and week-offs via
  `ShiftResolver` for the coming week). Rendered on the person sheet (`DrawerHost::person()['sections']`, the directory
  and command-search "Preview").
- `people.directory_hidden` (json, migration `2026_12_05_200001_add_directory_hidden_to_people`; `Person` fillable / cast,
  `UpdatePersonAction::FIELDS`): Preferences → "Directory card" lists the shown sections the organisation lets people
  hide; a toggle writes through `UpdatePersonAction` (audited, reason "Directory card changed by the employee"). Only
  hideable sections are honoured (a stored choice for another section is ignored); the person always sees their own card.

**MS-2 My profile (`App\Filament\Pages\MyProfile`, `filament.pages.my-profile`).**

- The avatar menu's "My profile" opens it (it opened the Employee 360 before); the 360 stays for HR and managers, and My
  HR "Your record" still opens the person's own 360. Access: the signed-in person's own employee record
  (`CurrentEmployee`, so access scopes apply) that they may view (`employee.self` or `employee.view`); `mount()` refuses
  first.
- Read model `App\Domain\Experience\Services\MyProfileSections` (reads only; every method refuses a record that is not the
  viewer's own): sections = Snapshot + the profile sections the matrix shows to the employee audience (documents also need
  `document.own`). Snapshot adds the stored-but-hidden facts: blood group, nationality, employment type, team, grade /
  level, functional manager, position, skill type (the designation's wage skill category), retirement date (new
  `App\Domain\Exit\Services\RetirementDates`, the exit policy's retirement age / "retire on"; the
  `peopleos:lifecycle:retirement-due` command now uses it, same result) and, while a non-cancelled exit case exists,
  resignation date, last working day and settlement status (never reasons or rehire status: PRV-3 / D-MS5 stays hidden).
  Personal: personal e-mail / phone, current addresses, emergency contacts; Family: marital status, members (dependent,
  nominee share); Professional: experience with months and total, education; Skills (`SkillProfiles`); Other details
  (custom fields with `visible_to_employee`); Bank: pay mode (`PaymentModes::for`), reimbursement mode, accounts (bank,
  branch, IFSC, type, primary; the bank name is what payslips already show, branch and IFSC follow
  `payroll.bank.show_ifsc_branch` as on the HR Bank tab); Previous employer: this financial year's declaration (`InvestmentDeclarations`);
  Documents; History: the timeline (`TimelineCategories::hiddenFor`) and "My changes".
- Own identifiers (PAN, Aadhaar reference, UAN, PF, ESIC numbers) and bank account numbers: only with the new permission
  `employee.self_sensitive` (owner decision PRV-1 / D-MS1 open; **not** in the employee or manager template, so by default
  they are not shown at all, as before). With it they are masked to the last four; "Show" calls `MyProfile::reveal()`, which
  checks the permission, the own record, the matrix row and (for `bank:{id}`) that the account is the person's own, records
  a sensitive view through `SensitiveAccessAuditor` (scope `self_reveal`, purpose the field or `bank_account:{id}`), and
  only then adds the field to `$revealed` (`#[Locked]`: the browser cannot add to it).
- Documents: every active type with "employee can view", grouped by category, with the current document (file name, last
  updated, status, expiry, HR's review note, download link) or "Not uploaded", and the type's sample link or instructions;
  documents of no type are listed too. "Download all" = route `me.documents.zip` (`/me/documents/download-all`, the
  protected chain + `throttle:10,1`, no parameters) → `MyDocumentsZipController` → `MyProfileSections::downloadable()`
  (own, current, `EmployeeDocumentPolicy::isOwn`) → `Documents::bundle()`, which re-checks each document (same employee,
  not archived, `can('view')`), refuses more than `Documents::BUNDLE_MAX_BYTES` (200 MB), builds the ZIP on the fly in a
  temporary file (deleted after sending) and writes one `DOWNLOAD` audit entry per file (purpose `download_all`).
- "My changes": `ChangeIntelligence::ownProfileChanges(viewer, employee)` — create / update audit events on the person's
  own employee record, person record and person satellites (addresses, family, emergency contacts, education, experience,
  profile skills; classified records such as bank, statutory and pay never), earlier / new per field; sensitive fields
  and technical fields (ids, keys, metadata) are left out; only for the viewer's own record, without `audit.view`.

**Data, permissions, commands.** Migration `2026_12_05_200001_add_directory_hidden_to_people` (a column on `people`, already
in `DataInventory`). New permission `employee.self_sensitive` (in no role template except through `employee.*` /
`*`) — run `php artisan peopleos:sync-permissions`. Settings `profile.section_visibility`, `directory.sections_visible`,
`directory.sections_employee_may_hide`. Route `me.documents.zip`. No new commands or tables.

**Tests.** `tests/Feature/HroneParity/ProfileVisibilityTest.php` (defaults; Setup centre save and validation; the matrix per
audience on the 360 tabs, summaries, workspace and My HR, HR not governed; fail-closed; directory sections through their
domains without leave types or others' goals; hiding only allowed sections, from colleagues only; visibility and tenant
isolation), `MyProfileTest.php` (avatar menu and access; sections and stored-but-hidden facts; identifiers hidden by
default; masked, audited, own-only reveal with a locked list; documents list and the audited ZIP; ZIP isolation; My changes).

Not built here: profile editing (MS-3), health insurance (MS-5), the social profile page with about / hobbies / badges /
endorsements and goal sharing (MS-6), photo and signature (MS-7), "other status" (WF-08), the static page layouts
(WF-09).

## WO-1 / WO-2 Workforce desk, bulk actions, employee search

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (packages WO-1 and WO-2; §23.1, §23.2.1, §23.2.2, §23.3.2,
§23.3.3, §23.4, §23.6.1, §23.6.3, §23.6.4). Every default reproduces the earlier behaviour: no register loses a column,
filter, action or navigation entry; nothing runs in bulk unless a person holding `bulk.run` and the action's own
permission starts it; nobody has a saved view until they save one; registers open as before unless the person chose a
default view or followed a desk link with `?view=`.

**Bulk-action framework (WO-1).**

- Contract `App\Domain\Experience\Contracts\BulkOperation` (tag `peopleos.bulk_operations`, registry
  `Experience\Services\BulkOperations`; defaults in `Experience\BulkOperations\BaseBulkOperation`). An operation names its
  key (`{register}.{action}`), label, model, the permission it needs, the inputs asked once for the whole run
  (`schema()`, normalised by `prepare()`), an optional per-record `refusal()`, and `handle()` — which acts through the
  record's owning service only. Built operations: `employees.initiate_exit` (`Exits::initiate`),
  `employees.start_onboarding` (`Onboarding::start`, chosen template or best match), `employees.initiate_bgv`
  (`Bgv::initiate`; consent is per person — each employee's own document of the chosen consent document type, not rejected
  or archived, is attached; without one the employee is refused), `employees.send_email` (`EmployeeMessages`),
  `confirmation_reviews.confirm` (`ConfirmationReviews::confirm`), `exit_cases.start_clearance` (`Exits::startClearance`;
  refused unless the exit is serving notice, because the service silently ignores other statuses) and
  `exit_cases.complete` (`Exits::complete`, optionally without settlement).
- `Experience\Services\BulkRuns::start()` checks the run (`bulk.run` + the operation's permission, at least one and at most
  `peopleos.bulk.max_records` = 5000 records, and — over `peopleos.bulk.confirm_over` = 25 records — the typed count,
  enforced on the server too), records a `bulk_runs` row and queues `Experience\Jobs\RunBulkOperation` (tries 3, timeout
  1800 s, backoff 60 / 300 s, tenant id + `BindTenantContext`; `failed()` marks the run failed).
- `BulkRuns::execute()` runs as the person who started the run (`ScreenAccess::as`: their access scope narrows every
  query, services see them as the actor) inside `AuditRecorder::operation('experience', 'Bulk: …')`, so every audit event
  of the run carries one operation id (kept on the run) and a BULK_OPERATION summary closes it. Each record, on its own:
  not returned by the scoped query → refused "Not in your access, or no longer exists"; then the permission again, the
  record's `view` policy, `AccessScopes::allows`, the operation's `refusal()`; then `handle()` in its own transaction — a
  RuntimeException / LogicException / validation or authorisation exception is reported as refused with the service's
  message, anything else as failed (reported, generic message, record unchanged). Outcomes are `bulk_run_items` rows
  (unique per run and record, so a retried job skips what it already reached). If the starter is no longer active, left
  the tenant or lost the permission, the run fails without touching a record. The starter gets an in-app notification
  (`notifyNow`) with the counts and "See each record".
- Screens: `Filament\Support\BulkRecordActions::selected($register, $keys)` (table toolbar: the rows ticked, or every row
  with Filament's "Select all") and `::allMatching($register, $keys)` (header group "Act on all matching": the
  register's current filters, lens and search — refused when nothing narrows the register, so a whole register is never
  acted on by accident). Both show the operation's inputs and, over 25 records, a "Type N to confirm" field. Results:
  People → Bulk runs (`BulkRunResource`: own runs with `bulk.run`, every run with `bulk.view_all`; the "Records" tab lists
  each record's outcome and reason).
- Owner decisions D-WO3 … D-WO6 follow the proposed defaults: no bulk operation deletes anything (HROne's bulk "Delete" of
  generated tasks stays "cancel with a reason" for WO-8 / WO-11), no "delete records" import intent, and letter revoke /
  supersede are WO-13's (nothing here deletes or unpublishes).

**Saved views, presets and card view (WO-1).**

- `Filament\Support\Pages\Concerns\HasRegisterTools` (on a `PeopleListRecords` page) adds the header tools: "Views"
  (preset views, the person's saved views, "Save this view…", "Manage saved views…"), "Report", "Act on all matching".
  A view keeps the register's filters, search, sort, status lens, visible toggleable columns and (Employees) the card
  layout and card fields; applying one fills the register's own filters, so it only ever narrows the register's own
  scoped query. `?view=<preset key>` opens a register on a preset (the desk links use it); otherwise the person's default
  view applies when they open the register afresh (no filters kept from earlier in the session).
- Storage: `Experience\Services\SavedViews` in the person's `ExperiencePreferences` (new key `saved_views`: register key →
  views; per person inside their tenant; at most `peopleos.bulk.saved_views_per_register` = 20 per register, 50 registers,
  16 KB per view; saving under an existing name replaces it; one default per register). Presentation only, like the
  other preferences (not audited).
- Presets: Employees (new hires, on probation, serving notice, without a background check, exited and alumni),
  Confirmation reviews (waiting, probation ending this month, confirmed), Exit cases (resignations, serving notice, in
  clearance, final clearance, withdrawn), Onboarding plans (onboarding, inductions, completed), Background checks (open,
  discrepancies), Transfer requests (waiting, approved), Letters (confirmation, transfer, relieving, experience,
  appointment, warning letters; waiting for approval).
- Employees card view (§23.1 #10–11): "Cards" in the header (`ListEmployees::$posLayout`, `?layout=cards`) shows a
  three-column card grid (name, code, designation · department, and the chosen fields: joining date, reporting manager,
  employment type, status, company, location, work e-mail); the fields are kept with a saved view.

**Report link (WO-1).** `Filament\Support\RegisterReport::action($templateKeys)` — "Report" on a register adds the matching
report-library template (only those whose dataset the person may read) to their reports and opens it. With "Use the
filters applied on this register" (default on) the register's filters travel as report conditions
(`RegisterReport::filtersFrom`: status / lifecycle state, company, department, location, designation, grade, the
organisation group (department with sub-departments), pasted codes and codes left out, joining date range).
`ReportLibrary::create()` gained an optional `$filters` argument that keeps only fields the person may read in that
dataset and the operators in `ReportLibrary::REGISTER_OPERATORS`. No second report engine.

**Workforce desk (WO-1, §23.1 #1).** `Filament\Support\Pages\OperationsDeskPage` (abstract) renders a desk's sections from
existing registers and pages, each entry checked with its own `canAccess()` (counts from the register's scoped query),
plus the viewer's recent bulk runs on the desk's registers. `Filament\Pages\WorkforceDesk` (People → Workforce desk;
its own area in the People section rail via `ExperienceNavigation::MODULE_AREA`; nothing else moved or renamed) orders the
workforce registers by journey: People (Employees, Directory, Employee changes) · Onboarding (new hires, background
verifications, pre-boarding / onboarding, inductions) · Confirmations · Transfer · Separation (resignations,
withdrawals, clearances, final clearances) · Workforce letters (by type) · Reports and uploads (report library, my
reports, employee and bank uploads, recent uploads, bulk runs). It opens for `employee.view` plus one operations
permission (`WorkforceDesk::OPERATOR_PERMISSIONS`) — not for managers or employees. Business partners (WO-15) are not
built, so the desk has no such entry.

**Employee search and filters (WO-2).** `Filament\Support\EmployeeFilters`, one set for every workforce register (through
the register's relation to its employee; the employee access scope applies inside every sub-query):
- `search()` — code, first / last / preferred name, work e-mail and current designation (on the Employees name column and
  the employee column of Confirmation reviews, Exit cases, Onboarding plans, Background checks, Transfer requests and
  Letters; the other searchable columns of each register still search as before — results are a superset of the earlier
  name-only search).
- `codes()` — a pasted list of codes to keep and one to leave out (comma, semicolon, space or new line; at most
  `peopleos.bulk.pasted_codes_max` = 1000 each, the chip says when more were pasted).
- `organisation()` — company, business unit, division, department (with every sub-department), designation, grade,
  level, location (multi-selects on the current position); on Employees as "more organisation" (business unit, division,
  department with sub-departments, level) next to the existing filters.
- `dateRange()` — Employees: joining date, added on; Confirmation reviews: probation ends; Exit cases: resignation date,
  last working day; Onboarding plans: joining date; Background checks: initiated; Transfer requests: requested, effective;
  Letters: generated.
- `excludePendingResignation()` — leaves out employees with an open exit case; `backgroundCheck()` (Employees, `bgv.view`) —
  none / in progress / completed clear / discrepancy or failed. Employees also gained the toggleable columns "Background
  check" (latest case status and result, `Employee::latestBgvCase`, `bgv.view`) and "Added on".

**Employee 360 extras (WO-2).**
- "Rehire / reactivate" (Action group; `transition` + `assignPosition` on the employee; exited or alumni only): rejoining
  date, optional position changes (blank = unchanged), optional line manager, required reason, through
  `RehireEmployeeAction` — which now also accepts an exited employee (exited → active was already an allowed lifecycle
  transition); the lifecycle moves only through `LifecycleEngine::transition()`; same employee, code, person and history;
  the login is left as it is.
- "Send e-mail" (Request group; `employee.message`): `Employment\Services\EmployeeMessages` queues one e-mail per address
  (work e-mail; personal e-mail only with `employee.sensitive.view`; optional copy to the sender) through the notification
  delivery log (event `employee.emailed`, tenant mail transport, delivered after commit) and audits `EMPLOYEE_EMAILED` on
  the employee with the subject and the kinds of address — never the body. The same service backs the bulk operation.
- "Print profile" (More group; `employee.view` and the record's `view` policy — an HR printout, not offered on one's own
  record through `employee.self`): `EmployeeProfilePrintController` behind a 30-minute signed link
  (`employees.profile-print`, the protected web middleware), the same check again, an EXPORT audit event (and the
  sensitive-access view record when sensitive sections are shown). Date of birth, blood group, personal contacts,
  statutory identifiers and bank accounts appear only with `viewSensitive`, and identifiers / account numbers masked to
  their last four characters. Sections the CA-6 profile access matrix hides from the viewer are left out, as on the 360
  tabs (`ProfileSectionVisibility::allows`: addresses and emergency contacts → personal, family, education and experience
  → professional, bank accounts → bank; HR who maintains records is never governed). The browser prints or saves it as PDF (a server PDF waits for the PDF-library decision).
- "Activity" tab (`ActivityRelationManager`, `audit.view`): every audit event about the employee across modules
  (`ChangeIntelligence::query(…, ['employee_id' => …])` — the viewer's access scope, classified values masked without
  `employee.sensitive.view`), filter by module, link to Change Intelligence. Notification deliveries stay on the delivery
  log (the e-mails sent from PeopleOS appear here as their EMPLOYEE_EMAILED events).

**How a register opts in** (time office TX-1, payroll PO-1, expense / asset XO-1 and performance GO-1 desks reuse this):
1. Bulk actions: write one `BulkOperation` per action (extend `BaseBulkOperation`; `handle()` calls the owning service and
   returns one line; throw a RuntimeException with a message to refuse), tag it `peopleos.bulk_operations` in a service
   provider, and add `BulkRecordActions::selected('<register>', [keys])` to the table's `toolbarActions()`.
2. On the list page (a `PeopleListRecords`): `use HasRegisterTools;`, return the register key from `registerKey()`,
   optionally `presetViews()`, `registerReports()` (report-library template keys) and `registerBulkOperations()` (the
   keys offered on "Act on all matching"), and put `...$this->registerToolActions()` first in `getHeaderActions()`.
3. Employee-linked registers add `...EmployeeFilters::forRegister('employee', '<date column>', '<label>')` to their
   filters and `->searchable(query: EmployeeFilters::search('employee'))` on the employee column.
4. A desk: extend `OperationsDeskPage` with `sections()` (entries are existing resources or pages, with `params` such as
   `['view' => '<preset>']` and an optional cheap `count` closure) and `registers()`; give it the navigation group of the
   area it serves and add its class basename to `ExperienceNavigation::DESKS` (never `MODULE_AREA`: a desk is its area's
   front page, not a rail area of its own — see "Desks inside their areas" at the end).
5. Permissions: grant `bulk.run` to the desk's role templates when its first operation lands (today: Tenant HR Admin via
   `bulk.*`, HR Manager; `bulk.view_all` to Auditor).

**Data, permissions.** Migration `2026_12_04_200001_create_bulk_runs` (`bulk_runs`: person, register, operation, selection,
record ids, input (kept out of the audit trail), counts, status, audit operation id, error, times; `bulk_run_items`: one
outcome per record, unique per run). `DataInventory`: both under `workflows`. `BulkRunItem` is a documented exception to
automatic auditing (derived rows; the records' own changes are audited under the run's operation id). New permission
keys `bulk.run`, `bulk.view_all` (new prefix `bulk` in the Core capability) and `employee.message`; role templates:
Tenant HR Admin `bulk.*` (and `employee.*` already covers `employee.message`), HR Manager `bulk.run` + `employee.message`,
Auditor `bulk.view_all` — run `php artisan peopleos:sync-permissions`. Settings `peopleos.bulk.*` (config, not Setup centre:
technical limits). New audit action `EMPLOYEE_EMAILED`.

**Tests.** `tests/Feature/HroneParity/WorkforceBulkActionsTest.php` (exits for selected employees under one audit
operation; service refusals reported and the rest done; access scope; typed count above 25, max records and permissions;
register bulk action on selected rows and the typed-count field; all matching only when narrowed; bulk confirm refusing
one's own; start clearance / complete refusals; BGV consent per person; bulk e-mail without the body in the trail; bulk
runs visible to their owner or `bulk.view_all`; lost permission and retry; job tenant-awareness; saved views per person
and tenant, default view, apply, cap; preset view from the URL; report with the register's filters; the desk for HR
operators only; registers unchanged for people without the new permissions), `EmployeeSearchProfileTest.php` (search by
code / name / e-mail / designation on Employees and Exit cases; pasted codes; organisation group and date ranges on
Employees and Confirmation reviews; pending resignation and background check filters; filters inside the scope; rehire
of an exited employee through the lifecycle engine; send e-mail from the 360; printable profile and its sensitive
sections, signature and scope; the Activity tab).

Not built here: business partners (WO-15), clearance / exit-interview / pre-joining task registers (WO-11, WO-12, WO-8),
bulk cancel of generated tasks (D-WO3, with WO-8 / WO-11), e-KYC / CCRV / vendor BGV reports (CA-15), ID cards (WO-20),
"other status" filter (WF-08), CC / BCC on e-mails (a copy to the sender only) and mail templates for "Send e-mail",
a per-register "Imports" button (the desk links the upload screens), notification deliveries inside the Activity tab, and
a server-side PDF of the profile.

## TX-1 / TX-2 Time office desk, register depth and finer time-office rights

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (packages TX-1 and TX-2; the rows they close are marked
"→ built" there). Every default reproduces the earlier behaviour: no register lost a column, filter, action or
navigation entry; an employee's own lists (time-office requests, regularisations, leave, balances, penalties, location
requests, attendance) look and behave as before — the new filters and header tools are shown only to holders of the
register's HR view permission (`attendance.view` / `leave.view`); nobody gained or lost an action. No migration, no
setting, no queued job; owner decisions D-TX1 … D-TX9 are untouched (none applies to these packages).

**Time office desk (TX-1, §24.1 #1).** `Filament\Pages\TimeOfficeDesk` (an `OperationsDeskPage`; navigation group
Attendance, its own rail area "Time office desk" via `ExperienceNavigation::MODULE_AREA`) orders the existing registers
as HROne's Time Office panel: Shift (shift rosters, shift requests) · Checkins (punches, regularisations, on duty
requests / cancellations, location requests, geo work locations, check-in devices) · Overtime & comp off (overtime
claims, overtime waiting on attendance records, comp off) · Attendance (records, calendar, exception centre, day
overrides, penalties, arrears) · Leave (requests, cancellations, balances, transactions, encashment requests /
cancellations, short leave and its cancellations, donations, calendar) · Holiday (calendars, restricted holidays and
their cancellations) · Reports and uploads · Time office setup. HROne's separate request and cancellation registers are
preset views (`?view=`) of the one time-office request list. It opens for `attendance.view` or `leave.view` plus one
operations right (`TimeOfficeDesk::OPERATOR_PERMISSIONS`: the manage keys and the TX-2 keys) — not for managers,
payroll admins or auditors — and lists only entries whose screen opens for the viewer; counts come from each
register's own scoped query (`Resource::getEloquentQuery()`, so a self-service-only viewer never sees others' counts).

**Register depth (TX-1).** Registers opted in to WO-1 `HasRegisterTools` (register keys `time_office_requests`,
`regularisations`, `leave_requests`, `leave_encashments`, `location_requests`, `attendance_records`, `leave_balances`,
`shift_roster`, `day_overrides`, `attendance_penalties`, `attendance_arrears`, `attendance_punches`): saved views,
presets, "Report" (library templates; the register translates its filters with `registerReportFilters()` — a new
optional hook `RegisterReport::action()` calls when the page has it — so status / type become the labels the
time-office dataset shows and date ranges land on the dataset's date fields). No bulk operation is registered (bulk
decisions are TX-9). Shared helpers:
- `Filament\Support\TimeOfficeRegisters` — `filters($permission, $dates)`: WO-2 pasted codes, organisation group, the
  new `EmployeeFilters::workforce()` group (employee status, employment type, joining from / until) and the pending-
  resignation toggle, plus the register's date ranges (`effective_dates` = day asked for / attendance date / rostered
  day, `request_dates` = raised, `decision_dates` = decided), each visible only to holders of `$permission`;
  `reportFilters()`; `uploads($keys)` — the "Upload" header group linking the upload screens (time office uploads open
  their dialog with the type chosen: `ListTimeOfficeImports` fills `type` from `?action=create&actionArguments[type]=…`
  when the person may upload it), each link shown only to people allowed that upload.
- Employee search on every register is `EmployeeFilters::search('employee')` (code, names, work e-mail, designation; a
  superset of the earlier name search). Register-specific additions: time-office requests — on-duty type (`category`)
  filter and hidden column, shift-asked-for filter, Detail column for week-off changes; regularisations — request type
  filter, hidden "Raised" column, employee code under the name; leave requests — "Cancellation" filter (waiting /
  decided / cancelled; `cancel_requested` is a leave status that `LeaveRequest::STATUSES` does not list) and hidden
  "Cancellation requested" column; encashments — leave-type filter, hidden "Raised" and cancellation-reason columns;
  location requests — hidden "Raised"; attendance records — shift, work schedule (the schedule the day was processed
  with: `calculation_basis->schedule_id`) and overtime-status filters; shift roster — hidden "Changed on"; day overrides —
  status filter.
- Presets: time-office requests (waiting, cancellations waiting, shift requests, on duty, on duty cancellations, short
  leave, short leave cancellations, restricted holidays and their cancellations, overtime, comp off), regularisations
  (waiting, approved, rejected, all), leave (waiting, approved, leave cancellations, cancellations waiting, cancelled),
  encashments (waiting, approved, cancellations), location requests (waiting, approved), attendance (absent, half days,
  incomplete, overtime waiting, on duty / field duty / WFH), shift roster (by source).

**Detail views (TX-1).** Read-only infolist view pages for time-office requests, regularisations and leave requests
(the `view` policy as before: HR in scope or one's own; decisions stay on the register and in the Approval Center).
Shared entries in `Filament\Support\TimeOfficeDetailEntries`: supporting documents behind 30-minute signed links
(`ParityFileController` types `time-office-request` and `regularisation`: the record's `view` policy or one's own,
audited `ATTACHMENT_DOWNLOADED`), "The day now" (`Attendance\Services\TimeOfficeDetails::dayStatus`: the processed
record's status, shift, punches, holiday, leave and lock, else weekly off / holiday / working day from the resolvers;
at most 14 days listed), and the approval workflow run with a link to its tracker when the viewer may open it. Time-office
requests also show earlier / new shift per day (`TimeOfficeDetails::shiftChanges`: once applied, the earlier shift is
what the request replaced, from `effects.previous_overrides`, else the schedule's shift; before that the shift the day
resolves to now), the weekly off worked and the day taken off, the leave a short leave beyond the limit took off the
balance (ledger keys `sl-{id}` / `sl-{id}-back`), and "chosen N of M" restricted holidays
(`TimeOfficeRequests::restrictedHolidayQuota()` — the quota check, made public; the refusal is unchanged). Leave
requests show the remaining balance of the leave year as recorded (read only, nothing recomputed) and the document link
(`Documents::downloadUrl`, when the viewer may view the document).

**Regularisation raised on behalf (TX-1, §24.8 #1).** "Raise on behalf" on Regularisations (`attendance.manage`, as the
row "Regularise"): employee, date, type, check-in / check-out, reason, documents and "After raising it": Approve now
(default — today's behaviour: request + approval "Applied by HR", day reprocessed) or Send for approval (the request
waits for the approver like the employee's own, `requested_by` = HR). Both go through `Regularisations::request`, so the
attendance policy's HR windows and rules apply.

**Finer rights (TX-2).** New keys inside the existing prefixes: `attendance.roster` (Roster employees, Remove a rostered
day, shift roster upload), `attendance.override` (Day overrides register, day override upload, monthly attendance
upload), `attendance.punch` (Manual punch, Retry a failed punch, punch uploads), `attendance.process` (Process day,
Process dates, Reprocess a day), `attendance.arrears` (declared for TX-3's arrear step; nothing checks it yet),
`leave.adjust` (Adjust a balance, leave balance upload), `leave.carry_forward` (declared for TX-8's year-end screen;
the year close is still the `--close-year` command). Policy abilities on records in scope: `roster`, `override`,
`process` (AttendanceRecordPolicy), `punch` (AttendanceConfigPolicy), `adjust` (LeaveRequestPolicy). Everything else
(configuration, regularise for others, raise on behalf, encash, apply on behalf, cancel any leave, approvals, the
punch payload column) keeps `attendance.manage` / `leave.manage`.
- No one loses or gains access: `config('peopleos.permission_splits')` maps each narrower key to the broad key it was
  split from, and `User::hasPermission()` accepts the broad key for a narrower one — so custom roles and roles holding
  only `attendance.manage` / `leave.manage` keep every action. Role templates get the keys through their wildcards
  (Tenant HR Admin and Attendance Admin `attendance.*`; Tenant HR Admin and HR Manager `leave.*`); on a deploy
  `ProvisionTenantAction::seedSystemRoles()` gives a split key to an existing system role only when the role holds (or
  is given at the same time) its broad key, so a role the tenant took `attendance.manage` from gains nothing. Run
  `php artisan peopleos:sync-permissions`.

**Tests.** `tests/Feature/HroneParity/TimeOfficeDeskTest.php` (desk for operators only and its preset links and counts;
preset views for HROne's request / cancellation registers; search and filters on time-office requests; every register's
filters; attendance by shift and work schedule; leave cancellations view; an employee's own lists unchanged; report with
translated filters; upload links and the upload dialog with its type; detail views — earlier / new shift, documents
behind signed links, the day now, debited leave, restricted-holiday usage, remaining balance; AR raise on behalf both
ways; the split map; each narrower right doing only its actions and the broad keys all of them; role templates and the
deploy guard).

Not built here: bulk decisions and cancellations (TX-9), the run-attendance month desk and arrear step (TX-3), the roster
board (TX-5), the day drawer and punch exclusion (TX-7), the year-end carry-forward screen (TX-8), the check-in approval
register (TX-10), the overtime desk over recorded overtime (TX-11), the `day_roster` dataset (AN-28), the workflow
tracker's reminders / add approver (RQ-2 / RQ-9), and the restricted-holiday helper on the request form (RQ-7a).

## CA-11 / SV-26 API key hardening, webhook operations and API key lifecycle

Matrices: `docs/hrone-parity/setup/01-core-hr-access-integration.md` §6.1 / §6.2 (build package 11) and
`docs/hrone-parity/setup/13-setup-reverification.md` (package SV-26; rows XC-53, XC-56 … XC-60, XC-62, XC-63). Rebuilt
from scratch (the first CA-11 build was lost in the 2026-10-29 reboot). Every default reproduces the earlier behaviour: a
key with no IP list and no limit of its own works from any address under the API-wide limit in its own bucket; without a
rotation a webhook carries one signature; "Send test" sends the same ping.

**API keys (CA-11).**

- Columns on `api_keys`: `description` (255), `allowed_ips` (json; null / empty = any address), `rate_limit_per_minute`
  (null = `peopleos.api.rate_limit_per_minute`), `regenerated_from_id` (self FK, null on delete). `ApiKeys::issue()` takes
  them as `$options`; `ApiKeys::limits()` validates them (every entry an IPv4 / IPv6 address or CIDR block, at most 100;
  rate 1 … `ApiKeys::MAX_RATE_LIMIT` = 10 000) and refuses the save otherwise. `issue()` now sets `status` explicitly, so the
  returned model knows it is active.
- `Integration\Support\IpAllowlist` (normalise / invalid / allows, Symfony `IpUtils`): fails closed — a malformed entry
  that reached a row never matches, so it can only narrow access; no address = refused when a list exists.
- `AuthenticateApiKey`: after the key is verified and before scopes or the tenant, a key used from outside its list gets
  403 `{"code": "ip_not_allowed"}` (last use not stamped). The `api` limiter (AppServiceProvider) uses the key's own limit
  in its key bucket; a verified key presented from a refused address is limited in the caller's IP bucket, so a leaked key
  used elsewhere cannot exhaust the owner's bucket.
- Call log: `AuthenticateApiKey::terminate()` writes `api_request_logs` once per request (guarded against stacked
  `api.key` middleware) for every request made with a verified key — answered, refused by scope / IP or by the rate
  limiter: method, path with ids (never the query string), route name, status, duration (from REQUEST_TIME_FLOAT), IP, user
  agent. Never a request or response body or a header. `Integration\Services\ApiRequestLogs::record()` writes quietly with
  the key's own tenant id and never throws (reported). Rows are tenant-owned (`BelongsToTenant`, category `api` in
  `DataInventory`) and purged by `Retention::purge()` after `retention.api_request_logs_days` (new setting, default 90;
  Setup centre → Governance and Security policy → Retention).
- `ApiKeys::update()` (name, description, scopes, IPs, rate limit, expiry; never the secret; refused on a revoked key;
  `api_key.manage`) is audited with the reason. `ApiKeys::regenerate()` (SV-26 / XC-62): reason required, row lock, only an
  active unexpired key; revokes it ("Regenerated: <reason>") and issues a new key with the same name, description, scopes,
  expiry, IP list and rate limit in one transaction; API_KEY_REGENERATED on the new key names the old key and prefix; the
  new secret is shown once (persistent notification). Revoke stays (no Delete), so the trail stays whole.
- Screens: `ApiKeyResource` form in three sections (Basic info · Scopes · Access limits, a wizard in the drawer); table
  shows the description and "IP addresses"; record actions Details, Edit (reason), Regenerate (reason), Revoke. New
  `ViewApiKey` page: Overview, Auth (scopes; the secret was shown once), IP addresses and rate limit, Usage (30-day calls,
  refused or failed, average response, last call), the `ApiKeyUsageChart` widget (calls and errors per day), and tabs
  "API calls" (`RequestLogsRelationManager`: date, method, request URL, HTTP status, duration, IP, action and user agent
  toggleable; filters dates, method, outcome 2xx / 4xx / 5xx, status code; "Export CSV" queued) and "Security"
  (`SecurityEventsRelationManager`: the key's audit events — issued, changed, regenerated, revoked, call log exported —
  with the user and reason; masked values stay masked).
- `Filament\Pages\ApiReference` (Integrations → API reference; `api_key.view` or `api_key.manage`): the OpenAPI
  description built from the route table by `OpenApiGenerator` (what `peopleos:openapi` writes), grouped by area with
  method, path, summary, scope, sensitivity and parameters; search; "Download OpenAPI (JSON)". It is a panel page (session
  sign-in, the panel's security chain), not `/api/docs` as the matrix suggested: the `api` group has no session. The
  generator's description now names per-key limits, `ip_not_allowed` and the previous-secret header
  (`docs/api/openapi.json` regenerated).
- `ApiKeyPolicy`: `viewAny` / `view` with `api_key.view` or `api_key.manage`; changes need `api_key.manage`.

**Webhooks (SV-26).**

- `Webhooks::attempt()` times each attempt (hrtime) and writes `webhook_delivery_attempts` (attempt, status code,
  duration_ms, 500-character response excerpt, signed_with_previous, attempted_at; deleted with the delivery by the FK
  cascade, so the 90-day delivery purge covers them); the delivery keeps the last `duration_ms`.
- Rotation: `Webhooks::rotateSecret($endpoint, $actor, $graceHours 1–72, $reason)` (row lock; `webhook.manage`) moves the
  secret to `previous_secret` (encrypted cast, hidden, excluded from audit values, in `DataInventory::SECRET_COLUMNS` so it
  is never exported) with `previous_secret_expires_at`, stamps `secret_rotated_at`, and returns the new secret (shown once).
  One SIGNING_SECRET_ROTATED event with the reason (secret masked). While `previousSecretInGrace()` is set, every request
  also carries `X-PeopleOS-Signature-Previous` (same scheme, old secret); after the expiry it never signs and
  `expirePreviousSecrets()` (each `deliverDue()` run and the retention purge) clears it. Editing the secret on the form is an
  immediate replacement: the model clears any previous secret (no grace). The edit page reloads after a rotation, so a later
  save cannot put the old secret back.
- Dead letters: `Webhooks::acknowledge($deliveries, $actor, $note)` (row locks; skips non-failures and acknowledged ones)
  sets `acknowledged_at` / `acknowledged_by`, decrements the endpoint's `failure_count` (so the Home "webhooks failing"
  signal clears) and audits WEBHOOK_ACKNOWLEDGED; `replayMany()` replays several dead letters inside
  `AuditRecorder::operation` (one operation id and a BULK_OPERATION summary); a replay clears an acknowledgement.
  `errorBreakdown()` counts unacknowledged failures by HTTP status ("No response" for transport errors / blocked
  destinations).
- Event test: `Webhooks::sendTest($endpoint, $event = 'ping')`; "Send test" asks for the event. Anything but ping posts the
  usual envelope with `"test": true` and `Enterprise\Support\WebhookSamples::data()` (shape of the real event, ids 0,
  SAMPLE- codes, `"sample": true`; never real records).
- Screens: `WebhookEndpointResource` list gains "Success rate (30 days)" (delivered ÷ attempted among deliveries created
  in 30 days, `withCount`), a status filter, "Send test" with the event, "Rotate secret" (also on the edit page) and "All
  deliveries". `Filament\Support\WebhookDeliveryActions` holds the shared columns and actions (Details with the attempt
  history, payload and its size, Retry now, Replay, Acknowledge, bulk Replay / Acknowledge, Error breakdown) used by the
  endpoint's Deliveries tab and the new `WebhookDeliveryResource` (Enterprise → Webhook deliveries, `webhook.manage`;
  tabs All / Failed deliveries (unacknowledged dead letters) / Waiting to retry; filters webhook, event, status,
  acknowledged, dates; "Export CSV").

**Queued log exports (SV-26 / XC-56, XC-63).** `Integration\Services\IntegrationLogExports` + job
`Integration\Jobs\ExportIntegrationLog` (unique per export; tries 3, timeout 900 s, backoff 60 / 300 s; tenant id +
`BindTenantContext`; `failed()` marks the export failed). `request()` checks the permission (`webhook.manage` for deliveries;
`api_key.view` / `api_key.manage` for API calls), keeps only known filters, records `integration_log_exports` and an EXPORT
audit event (on the key for API calls), and dispatches after commit. `build()` streams a formula-escaped UTF-8 CSV to the
documents disk under `tenants/{id}/integrations/` (deliveries: no payloads, destinations as scheme and host; API calls: the
logged columns only) and notifies the requester in-app. Integrations → Log exports (`IntegrationLogExportResource`, own
exports only; `IntegrationLogExportPolicy`) downloads it for the requester while they hold the permission (DOWNLOAD
audited). Rows and files are purged with `retention.report_runs_days` ("Report runs & exports").

**Data, permissions, audit.** Migrations `2026_12_07_200001_harden_api_keys` (api_keys columns, `api_request_logs`),
`2026_12_07_200002_add_webhook_operations` (endpoint rotation columns, delivery duration / acknowledgement,
`webhook_delivery_attempts`), `2026_12_07_200003_create_integration_log_exports`; long indexes named explicitly.
`DataInventory`: `api_request_logs` → `api`; `webhook_delivery_attempts`, `integration_log_exports` → `integrations` (file
column and the `tenants/{id}/integrations` prefix); `previous_secret` is a secret column. `ApiRequestLog`,
`WebhookDeliveryAttempt` and `IntegrationLogExport` are documented exceptions to automatic auditing (append-only logs;
exports audited explicitly). New permission `api_key.view` (Tenant HR Admin gets it through `api_key.*`; nobody else) →
run `php artisan peopleos:sync-permissions`. New audit actions API_KEY_REGENERATED and WEBHOOK_ACKNOWLEDGED. Setting
`retention.api_request_logs_days`. No scheduler command was added (the existing `peopleos:webhooks:deliver` and
`peopleos:retention:purge` do the clean-up).

**Tests.** `tests/Feature/HroneParity/ApiKeyHardeningTest.php` (defaults unchanged; allowlist validation and fail-closed
matching; 403 outside the list; per-key limit and the refused-address bucket; call log contents with no secret, query or
body, tenant isolation; retention; regenerate and its audit; audited edits and refusals; issue / edit / regenerate from the
screens; key page, API calls and Security tabs and usage for `api_key.view`, actions hidden; API reference and download;
queued call-log export, formula escaping, requester-only audited download, purge) and `WebhookOperationsTest.php` (attempt
history and timing; rotation with both signatures, encryption at rest, audit masking, expiry clean-up, manual replacement;
acknowledge and failure count, error breakdown, bulk replay under one audit operation; event test samples and ping; success
rate and status filter; deliveries page tabs, bulk actions, queued export without payloads or URL paths, permission;
attempts purged with deliveries; inventory).

Not built here: a key logo (HROne "Basic info → logo"), Delete of a key (Revoke keeps the trail), search inside the event
checklist (XC-54) and the webhook auth / custom headers step (SV-17), a per-webhook view page (XC-61 stays the edit page
with its Deliveries tab), an API usage dashboard across keys (the chart is per key), and `api_key.view` in other role
templates (owner decision below).

Owner decisions (defaults applied): who besides Tenant HR Admin should read API keys and their call logs (`api_key.view`
for the Auditor template?); the call-log retention default (90 days); whether a webhook secret should stay revealable on the
edit form (today's behaviour, kept) or be shown only once like API keys.

## GO-1 Performance desk and register depth

Matrix: `docs/hrone-parity/modules/27-performance-engage-operations.md` (package GO-1; §27.1 #1, 2, 8, 9; §27.2.1 #1, 3, 7,
10; §27.2.4 #1–3; §27.3.1 #5, 7; §27.3.2 #1–3; §27.4.3 #2; §27.5.1 #3, 5, 6; §27.5.2 #1–3; with §27.4.1 #1 (HP-8h's rounds
register, now on the desk) and §27.6 #1 (letter views)), rows marked "→ built (GO-1 …)". Every default reproduces the
earlier behaviour: no register lost a column, filter, action or navigation entry; the employee's and the manager's
appraisal, goal, improvement-plan and initiative lists look and behave as before (the new filters, columns and header
tools are shown only to performance HR — `performance.view` / `performance.calibrate`); nothing is reopened unless the
organisation switches the new setting on. No migration, no new permission key, no queued job of its own (bulk runs use
WO-1's).

**Desks (§27.1 #1, #2).** `Filament\Pages\PerformanceDesk` (navigation group Performance, its own rail area
"Performance desk" via `ExperienceNavigation::MODULE_AREA`) orders the performance registers as HROne's Performance panel:
Goals (employee goals, goal sheets, company goal, goal requests) · Reviews (cycles, performance reviews, review requests,
normalise and publish = calibration board, 9-box, calibration sessions) · Continuous feedback (360 feedbacks = HP-8h
rounds, 360 nominations = the participants' "All" tab, initiatives, 1-on-1 meetings, continuous feedback, check-ins) ·
Performance improvement (PIP, PIP evaluations) · Performance letters (performance, PIP and PIP evaluation letters as
Letters views) · Reports · Performance setup. It opens for performance HR holding an operations right
(`PerformanceDesk::OPERATOR_PERMISSIONS`: manage, calibrate, pip, initiatives_manage, feedback_360) — not for managers,
employees or a read-only viewer — and lists only entries whose screen opens for the viewer; counts come from each
register's own scoped query (goal requests waiting, reviews still to do on open appraisals, outcomes waiting).
`Filament\Pages\EngageDesk` (group Engagement, rail area "Engage desk") does the same for the Engage panel: HR handbook ·
Announcement (+ feed, audiences) · Survey (+ results, campaigns) · Recognition (badges, recognitions, reward points,
social wall) · Listening (happiness index, feedback inbox) · Reports · Engage setup; it opens for `kb.manage`,
`communication.manage`, `engagement.manage`, `recognition.manage`, `recognition.points` or `wall.moderate`. Events
(GO-15), polls (GO-16) and fundraisers (GO-18) are not built, so they have no entry; candidate engagement is recruitment.

**Shared register helpers.** `Filament\Support\PerformanceRegisters`: `hrSide()` / `hrOnly($filters)` (HR-only filters),
`actionsMenu()` — HROne's split button / "ACTIONS" menu (§27.1 #8, #9) as a "Performance actions" header group: Initiate
goal or review cycle (cycles list), Create company goal, Normalise and publish ratings (calibration board), Initiate 360
feedback (rounds list with `?action=launchRound`), Add PIP (`?action=create`), Remind reviewers (review requests on "Still
to review"); each a link shown only when its screen and right allow it (nothing is granted). On Goal sheets, Goal requests,
Review requests, Appraisals, Goals, Improvement plans, Initiatives and One-on-ones. `approvalLabel()` / `approvalUrl()` —
HROne's "View workflow": the latest workflow run on a goal plan or improvement plan, linked to its tracker when the viewer
may open it, else "Approval Center" while it waits (the Approvals page). `remindReviewerAction()`,
`reopenReviewAction()`, `goalsAndInitiativesAction()`.

**Goal sheets (§27.2.1 #1, #3, #7, #10).** `Domain\Performance\Services\GoalSheets` reads sheets from what exists — one per
employee and cycle (the appraisal = the cycle's population), goals of level employee not cancelled, and the `goal_plans`
of the pair. Status: not created (no goal) · waiting for approval (a pending plan or goals in `pending_approval`) · sent
back (drafts and the latest plan rejected) · draft · active. The same rules run as SQL (`withSheet()` adds correlated
sub-queries: goals, weightage, drafts, waiting goals, pending plans, number of plans, latest plan id / status;
`whereStatus()` for the filter) and in PHP (`statusFrom()`), so the column and the filter agree. `sheet()` gives the sheet
view: goals with KPIs, total weightage against the required total (`Goals::requiredWeight`), the competencies rated with
them (`GoalSheetRules::competenciesFor`) with their weightage as scoring uses it (`Appraisals::competencyWeightsFor`, the
former private weight lookup made public: pinned template version, else the competency's own) and the competency share
(goal sheet policy, else performance policy, else cycle weights), and every plan in order (versions). Screen
`GoalSheetResource` (Performance → Goal sheets, `/admin/goal-sheets`): a second, read-only resource on `Appraisal` (as
`EmployeeImport` already has several; PeopleOS never resolves a resource from a model — `Filament::getModelResource` is
unused — and every appraisal link names `AppraisalResource`) — employee (WO-2 search), goal
period, sheet status, goals, weightage, version, latest request (link to it), approval; filters goal period, sheet status,
WO-2 codes / organisation / pending-resignation; "View sheet" (slide-over), "Goals" (the Goals list filtered to the
employee and cycle — Goals gained an HR / manager "Employee" filter for it); presets per status; report "Goal progress",
"Overdue goals".

**Goal requests (§27.2.4 #1–3).** `GoalPlanResource` (Performance → Goal requests, `/admin/goal-requests`, list + view):
every `goal_plans` row with employee, goal period, version (its place among the sheet's requests, a correlated count),
requested on / by, route (manager / workflow), status, goals, weightage, decided on / by, note and approval trail; filters
goal period, status, route, requested and decided date ranges, WO-2 employee filters; presets waiting / approved / sent
back. Read-only: decisions stay in the Approval Center, the workflow and `GoalPlans`. The snapshot diff between versions
is GO-5.

**Review requests, reminders, reopen (§27.3.1 #5, #7; §27.3.2 #1–3).** `AppraisalReviewResource` (Performance → Review
requests, `/admin/review-requests`): one row per review (self, manager, approver, peer …) of the appraisals in the viewer's
scope (`whereHas('appraisal')`: the appraisal's access scope applies), with level, reviewer, status, due (the stage's end
date), created, submitted, appraisal status, and the overall rating (hidden by default) only where
`AppraisalVisibility::reviewVisible` allows it (the performance policy's per-level rules; HR sees every level, as on the
Reviews tab). For performance HR only — the same people who already read every review on an appraisal's Reviews tab;
anonymous peers' names follow that tab. 360 rounds (HP-8h) keep their own register and anonymity (`FeedbackRoundResults`
is untouched). Filters performance cycle, level, status, appraisal status, created / submitted ranges, WO-2 employee
filters; presets still to review (all, self, manager, approver) and submitted; report "Appraisal status".
- "Remind" (row; also on the Reviews tab) and bulk "Remind reviewers" (`review_requests.remind`,
  `Experience\BulkOperations\RemindReviewersOperation`, performance.manage + bulk.run):
  `PerformanceReminders::remindReviewer()` sends the scheduled reminder's notification (`performance.reminder.review_due`,
  due = the stage end) and shares its log, so a review is reminded at most once a day whoever triggers it; refused for a
  submitted review, a finalized / locked appraisal, a cycle not running or a stage not open yet.
- "Reopen" (owner decision PER-1 / D-GO1, open): new Setup → Performance setting `performance.review.hr_reopen` (off).
  `Appraisals::reopenReview($review, $reason, $actor)`: only while on; performance.manage within the access scope; reason
  required (≤ 500); self / manager / approver reviews only (`Appraisals::REOPENABLE`; peers and 360 keep their flow); never
  the actor's own review nor a review of their own appraisal; under the appraisal then review row locks, the appraisal not
  finalized or locked and the review submitted. The review returns to pending with its inputs cleared — the earlier
  submission stays in the audit trail twice over: the review's own audited update (overall rating, strengths / areas /
  comments as sensitive values, evidence, submitted at; reason "Reopened by HR: …") and a STATUS_CHANGE event (metadata
  `event = review_reopened`, the earlier submitted-at) carrying each rating row (comments sensitive). The appraisal is
  scored again without it; the reviewer is told (`performance.review.reopened`, with the reason) and submits again through
  `submitReview` — HR never writes it. Row action on Review requests and the Reviews tab, and bulk "Reopen reviews"
  (`review_requests.reopen`, one reason, offered only while the setting is on, refused per review otherwise).
- Appraisals: HR-only "Created" date range (the launch date) and the WO-2 employee filters; `ListAppraisals` opted in to
  `HasRegisterTools` (presets in review / calibration / finalized; reports appraisal status, rating distribution) for HR.

**PIP and PIP evaluations (§27.5.1 #3, #5, #6; §27.5.2 #1–3).** Improvement plans gained, for performance HR: "Requested"
(created on, by) and "Approved" (on, by — `ImprovementPlan::approver()`) columns, an "Approval" column (workflow run or
Approval Center while a plan or outcome waits), filters "Evaluation status" (due = active / extended past the end date
without an outcome, outcome waiting for approval, improved, no improvement, extended, closed), requested / approved / PIP
end date ranges and the WO-2 employee filters; `ManageImprovementPlans` opted in to `HasRegisterTools` with HROne's
registers as views: "PIP evaluations", "Evaluation due", waiting for approval, outcome waiting, active.

**Goals and initiatives drawer (§27.4.3 #2).** "Goals and initiatives" (slide-over) on Initiatives: the row's employee's
goals (with KPIs) and initiatives of a chosen cycle ("Period"; opens on the initiative's goal's cycle, else the employee's
latest). `GoalSheets::goalsAndInitiatives($viewer, $employee, $cycle)`: goals only when the goal rules let the viewer see
that employee's goals (`EmployeeOwnedPolicy::view`: HR, the employee, their performance managers — the action is hidden
otherwise), initiatives through `Initiatives::visibleTo` (an initiative belongs to the cycle by its linked goal, or its due
/ start date inside the cycle period).

**Letters (§27.6 #1).** `ListLetters` presets "Performance letters", "PIP letters", "PIP evaluation letters" (type filter);
the workforce views are unchanged.

**Data, permissions, settings.** No migration. No new permission key (registers: performance.view / calibrate; remind and
reopen: performance.manage; bulk: bulk.run as WO-1). New setting `performance.review.hr_reopen` (false) in
`config/peopleos.php` and Setup → Performance. New notification event `performance.review.reopened` (in-app title in
`NotificationEventBridge`; not a webhook event). Bulk operations tagged `peopleos.bulk_operations` in
`ParityServiceProvider`. `ExperienceNavigation::MODULE_AREA`: Performance desk, Engage desk.

**Tests.** `tests/Feature/HroneParity/PerformanceDeskTest.php` (defaults: reopen off and refused, employee's / manager's
lists unchanged; desks for operators only with HROne's order, counts and views; goal sheet statuses and the status filter
agreeing; sheet view with KPIs, competencies, weights and versions, the Goals link; goal requests with versions, presets,
dates, view page, read-only, HR only; review requests by preset, cycle, dates, search, scope fail-closed; remind once a day,
row and bulk, refusals and rights; reopen with reason, permissions, audit trail of the earlier submission, rescoring,
notification, resubmission, own review / own appraisal / peers / finalized refused; bulk reopen only while on; appraisal
created-date and employee filters and the actions menu; PIP filters, columns and views; the goals and initiatives drawer
and who may open it; letter views; the new screens render and stay tenant-isolated).

Not built here: adding people to running cycles, window extensions and withdrawal (GO-2), performance task kinds
(GO-9), sheet version diffs, attachments and copy tools (GO-5), the workflow tracker's reminders / add approver (RQ-2),
a periodic-review ("review cycle") filter on review requests (periodic reviews live in HP-8i's
`performance_period_reviews`), PIP register search (WO-2 on that list), engage events / polls / fundraisers (GO-15,
GO-16, GO-18).

## XO-1 / XO-2 Expense and asset desks, register depth, the expense receipt page

Matrix: `docs/hrone-parity/modules/26-expense-asset-helpdesk-operations.md` (packages XO-1 and XO-2; §26.1 #1, #2, #4
(and #10 in part); §26.2.1 #2, #3, #6, #7, #9; §26.2.4 #1–2; §26.3.1 #2, #3, #5; §26.3.3 #1–2; §26.4.1 #6, #8–10,
#12–14, #17–21; §26.4.3 #14, #16), rows marked "→ built (XO-1 …)" / "(XO-2 …)". Every default reproduces the earlier
behaviour: no register lost a column, filter, action or navigation entry; the navigation entries keep their place (the
desks give the process order); an employee's own expenses, expense reports, advances, trips and assets look and behave
as before — the new columns, filters and header tools appear only on the HR side of each register (the resource's
`oversees()`: `expense.view / approve / pay` (+ `expense.travel_desk` for trips); `asset.view / manage / assign`); the
duplicate check reaches the same verdicts; money flows are untouched (reimbursement still reaches payroll only through
the existing payroll input). No new permission key, no setting, no queued job; owner decisions D-XO1 … D-XO8 stay on
their defaults (none applies to these packages).

**Desks (XO-1, §26.1 #1, #2).** `Filament\Pages\ExpenseDesk` (Expense & travel → Expense desk, rail area "Expense desk")
and `Filament\Pages\AssetDesk` (Assets → Asset desk, rail area "Asset desk"), both `OperationsDeskPage`s, entries over
the existing screens only:
- Expense desk in HROne's process order: Travel (travel requests waiting · Travel bookings = trips with company bookings
  to make · Travel cancellations · all trips) · Advances (advance requests · Dispense advances · dispensed · Advance
  cancellations) · Expense (receipts · suspected duplicates · reports waiting · all reports) · Payments (Pay expenses ·
  paid reports) · Reports (report library, my reports) · Expense setup (Setup centre `expense`, categories, groups,
  location groups, tax types, expense and travel forms, policies). Opens for `expense.view` or `expense.travel_desk`
  plus an operations right (`ExpenseDesk::OPERATOR_PERMISSIONS`: manage, approve, pay, travel_desk) — so HR, finance,
  the travel desk and the expense admin, never a line manager who only approves (no `expense.view`) nor a read-only
  viewer. Counts come from each register's own scoped query.
- Asset desk in HROne's panel order: Asset inventory (register, in stock, in repair, lost) · Employee assets ("Assets by
  employee" = the register's "In use, by employee" view) · Asset acknowledgement ("Waiting for acknowledgement" view) ·
  Asset request · Reports · Asset setup (Setup centre `asset`, types, sub types, products, policies). Opens for the
  register's HR side holding `asset.manage` or `asset.assign`. XO-9 replaces the two views with registers of their own.

**Register depth (XO-1).** Travel requests (`travel_requests`), Expense advances (`expense_advances`), Expense reports
(`expense_reports`), Expense receipts (`expense_receipts`, XO-2) and the asset register (`assets`) opted in to WO-1
`HasRegisterTools` (views, saved views, "Report"; no bulk operation — bulk decisions are not in scope). Shared helpers in
`Filament\Support\ExpenseRegisters`:
- `filters($hrSide, $dates)` — WO-2 pasted codes, organisation group, employee status / type / joining, the pending-
  resignation toggle and the register's date ranges, each visible only when `$hrSide()`; employee search
  (`EmployeeFilters::search`) on the employee column of trips, advances and receipts (a superset of the number search).
- `cancellation($hrSide)` — HROne's cancellation registers as one filter: waiting (`cancel_requested`), cancelled, refused
  (`cancel_refused_at` set). `travelDates($hrSide)` — trips overlapping a period.
- `waitingWith(...$subjects)` / `tripWaitingWith()` / `advanceWaitingWith()` — "Waiting with": the pending tasks
  (`WorkflowTask::assigneeLabel()`) of the workflow in flight on the record (a mixed trip: on each pending part), else
  "Expense approvers (Approval Center)" (where `ExpenseApprovalSource` lists it); an advance asked inside a trip says it
  is decided with the trip.
- `reportFilters()` — the register's filters as report conditions (status as the dataset labels it, date ranges on the
  dataset's date fields, the trip overlap as `start_date` ≤ until / `end_date` ≥ from; the journeys dataset gets
  `trip_status`), through `TimeOfficeRegisters::reportFilters`.
- Trips gained (HR side, hidden until chosen) Requested on (`submitted_at`), Waiting with, Decided on, Decided by,
  Cancellation refused on; filters Cancellation, Travel date, Requested, Decided + WO-2; views waiting, approved,
  Travel bookings to make, Travel cancellations, cancellations waiting, rejected; reports travel summary, travel details,
  accommodation details, live trip. Advances: Requested on (`created_at`), Waiting with, Decided on / by, Cancellation
  refused on; Cancellation, Requested, Decided + WO-2; views waiting, to dispense, dispensed, Advance cancellations,
  cancellations waiting, closed (each keeps the Files column); reports advance amount, outstanding, recovery register.
  Expense reports: views waiting, returned, approved / partly approved, paid; reports report details, pending, payment
  register. Asset register: views in stock, in use by employee, waiting for acknowledgement, in repair, lost; new HR-side
  "Acknowledgement" filter (pending = an active assignment not acknowledged on a sub type that asks for it, as
  `Assets::pendingAcknowledgements`; acknowledged); reports assets assigned, by category. Tickets: "Report" (tickets by
  category, SLA breaches; for people who may read the service desk dataset).
- Trip and advance pages show "Cancellation refused on" when set.

**Refused cancellations (XO-1, §26.2.4 #1, §26.3.3 #1).** Migration `2026_12_10_200001_add_cancel_refused_at_to_trips_and_advances`:
`travel_requests.cancel_refused_at`, `expense_advances.cancel_refused_at` (nullable timestamps). `TravelRequests::rejectCancellation`
and `ExpenseAdvances::rejectCancellation` — the one path the register's "Keep trip" / "Keep advance", the Approval
Center and the workflow bridge already take — stamp it in the same audited update (approval, note and REJECTED audit
event unchanged). It is never cleared: a trip cancelled after an earlier refusal still shows it.

**Receipt page and register (XO-2, §26.4.1, §26.4.3 #14, #16).** `ExpenseReceiptResource` gained a read-only view page
(`Pages/ViewExpenseReceipt`, "View" on each row; no header actions — change / withdraw stay on the register, decisions
on the report): Expense (number, employee, status, type, category and group, dates, filed on / by, updated on,
declaration), Amount (receipt currency, exchange rate, base amount, limit when filed, over the limit, claimable,
approved, repaid to the employee, exceptional and why, approver note), Mileage (from, to, hours worked, distance,
measured distance and its source, rate per km, odometer readings), Details (purpose, merchant, invoice, participants,
place and location group, trip — linked only when the viewer may open it — description), Tax (supplier GSTIN, supply
of, tax lines), Checks (labels, closest-match score, the receipts it resembles), Report and payment (report — linked when
viewable —, its status, its payment status shown as on the report page: the employee only with
`expense.payment.show_to_employee`), Files (the signed, audited `ParityFileController` route), custom fields
(`CustomFieldsSchema::infolistSection`), History tab (`AuditHistoryRelationManager`, `audit.view`). The page opens for
whoever `ExpensePolicy::view` lets in (own, or expense HR in scope) through the resource's scoped query.
- Register (HR side, hidden until chosen): Invoice number, Repaid to the employee, Updated on, Payment (the report's
  `paymentStatus()`); filters Expense type, Checks (any of the receipt flags), Payment status (paid, scheduled for
  payroll, waiting for payment, nothing to pay — the same rules as `ExpenseReport::paymentStatus()`), Expense date +
  WO-2; views suspected duplicates, over the limit, mileage, not yet in a report, approved waiting for payment, paid;
  reports receipt details, by amount, by mileage, by category.
- Duplicate matches: migration `2026_12_10_200002_add_duplicate_matches_to_expense_receipts` (`expense_receipts.duplicate_matches`,
  json). `ExpenseDuplicateDetector::assess()` now also returns `matches` — every receipt at or above the threshold (score,
  `similar`) and every receipt with the same invoice number from the same merchant (`invoice`), at most
  `MATCHES_KEPT` = 10 per reason, best first — without changing `refusal`, `score`, `of` or `flags`; `ExpenseReceipts`
  stores them on capture and on every change (re-checked). `ExpenseRegisters::duplicateMatches()` resolves them through
  the scoped query and the `view` policy: the page links those the viewer may open and only counts the others (receipts
  captured before XO-2 fall back to `duplicate_of_id`). Dataset `expense_receipts` gained "Resembles receipts" (numbers
  inside the reader's scope).

**Data, permissions.** Two migrations above (columns on tables already classified under `expense` in `DataInventory`;
none secret). No permission key, setting, notification or job. `ExperienceNavigation::MODULE_AREA`: Expense desk,
Asset desk.

**Tests.** `tests/Feature/HroneParity/ExpenseRegistersTest.php` (refused cancellations through the Approval Center and
the register, audit unchanged, kept after a later cancellation; the two cancellation views; requested / decided /
decided by / waiting with — Approval Center and workflow task; date, overlap, organisation and employee search filters on
trips and advances; employees' own lists unchanged; reports with translated filters on trips, journeys, advances and
receipts, Report on expense reports, assets and tickets; the expense desk for operators only, its order and counts; the
asset desk, its views and the acknowledgement filter; the duplicate check keeping its matches with the same verdicts
and re-checking on change; the receipt page — fields, tax, mileage, custom fields, files, history only with audit.view,
owner / colleague / out-of-scope HR —; matched receipts named only where visible and in the dataset; payment status on
the page and the setting; the receipt register's columns, filters and views).

Not built here: bulk decisions on these registers; registers of their own for "Assets by employee" and acknowledgements
(XO-9); the custodian filter (XO-9); the travel bookings workspace (XO-7); the dispensed tab, dispense filters and
on-behalf advances (XO-5); the report review depth (XO-3, which brings the matched receipts into the review); the
attendance day drawer from a receipt (RQ-3); "Ticket details by requestee" (AN-23); employee search on the remaining
registers of matrix 26.

## RQ-1 Request registry, "New request" and "My requests"

Matrix: `docs/hrone-parity/modules/20-requests-inbox-team.md` (package RQ-1; the rows it closes are marked "→ built (RQ-1)"
there, and the matrix-22 rows that point at it). Every default reproduces the earlier behaviour: no module screen,
form, action, approval or navigation entry changed meaning; the two pages and their rail entries are new; nothing new
creates, changes, cancels or deletes a request — the registry only reads and links. No migration, no new permission,
no setting, no queued job.

**Contract and registry.** `App\Domain\Experience\Contracts\RequestType` (tag `peopleos.request_types`, registry
`Experience\Services\RequestRegistry`, defaults `Experience\RequestTypes\BaseRequestType`, status enum
`Experience\Enums\RequestStatus` Pending / Approved / Rejected / Withdrawn / Draft). A type names its key, label,
description, module group, icon, keywords, the module permission, `availableTo($user, $employee)`, `formUrl()` (the
module's EXISTING form: a page or resource URL with `?action=` — Filament mounts that action on load, so the module's
own authorisation, validation, approvals and audit apply), an optional on-behalf permission and `onBehalfUrl()` (the
module's existing on-behalf form), `historyVisibleTo()` (the module list opens for the person), `ownRecords($user,
$employee)` (the model's own query — tenant and access scopes apply — narrowed to the person's employee id; null without
one: fail closed), `ownedByEmployee()`, a status map (module status → RequestStatus), `whereStatus()` / `countByStatus()`
(SQL), the record's summary, its dates (requested / effective = the day or period asked for / decided) with the columns
the date filters use, search columns, `recordUrl()` (the module's view page when the person may view the record, else its
list) and an optional family (`time_office`). Adapters live in `app/Domain/Experience/RequestTypes/` (as WO-1's bulk
operations do), so module domains stay free of Filament and Attendance / Leave code gains no payroll words.
- The 26 types: leave (Home "Request leave"; on behalf Leave requests "Apply on behalf"), leave encashment (My
  encashments), attendance regularisation (Home "Fix my attendance"; on behalf Regularisations "Raise on behalf"), the
  seven time-office kinds — on duty, short leave, restricted holiday, change shift, week-off change, overtime, comp-off
  (Time-office requests "New request" / "Raise on behalf" opened on the kind), location approval, loan (on behalf
  payroll), reimbursement, variable pay (for an employee: payroll or a manager), propose investments (Declare
  investments), confirm investments (My investment proofs; a proof once submitted), expense, expense report, expense
  advance, travel, asset (each with the module's on-behalf form where one exists), helpdesk ticket (Ask HR; agents raise
  for someone from the HR queue), HR service (the service catalogue; launcher only, its requests are helpdesk tickets),
  resignation (My day "Resign"), change goal (goal sheets / goal proposals sent for approval; the Goals list) and form
  (configuration forms, for holders of `form.submit`; their own submissions by `submitted_by`).
- `availableTo` mirrors the destination's own checks and never offers what the module would refuse up front: the page /
  resource `canAccess()`, the action's own `visible()` rule (permission + an employee record), and the module's switches —
  `TimeOfficeRequests::offers()` (new, read only: short leave policy enabled; on duty, shift change, overtime, comp-off
  unless switched off; week-off change only when switched on; restricted holiday only when the calendar asks to choose and
  has optional holidays this year — the same settings and defaults `request()` refuses on), an encashable leave type, a
  reimbursement component paid on claim, a non-statutory earning for variable pay, the expense advance policy's refusal,
  an asset type in use, at least one helpdesk category the access policy allows, a catalogue service available now, the
  investment declaration window and `InvestmentDeclarations::canEdit`, the proof window and an unverified declared item, the
  resignation rule of My day (employed, not serving notice, no open exit), an open goal cycle, a published form. While the
  employee request lock (P4 `SelfServiceLock`) holds a request kind, its types are not offered and "New request" shows the
  lock's reason (`RequestRegistry::LOCK_KINDS`, `lockNotices()`).
- Answers only for the signed-in person (the destinations' `canAccess()` reads them); a type that throws is not offered
  (reported). The registry is request-scoped (`ParityServiceProvider`: `scoped`, forgotten on `RequestHandled`) and memoises
  `available()` / `onBehalf()` per tenant and user for that request.

**My requests (read model + page).** `Experience\Services\MyRequests::for($viewer, $subject = null, $filters)`: own
requests of every type whose module list opens for the person, counted per tab in SQL (`countByStatus`), rows merged
newest first (`PAGE` 25, "Show more"); filters: tab, request type (with its count), a date kind (requested / date asked
for / decided — the chosen type's own labels) with from / until, a search over the type's reference / reason columns or
the record id, "hide closed" (pending and drafts only), and the person's hidden requests. `pending()` gives the Pending
rows of some types or of a family (My work, the form panel). A manager may follow a current report's requests
(`mayFollow`: `PerformanceRelationships::manages`): only employee-owned types whose `viewAny` the manager passes, each
record kept only when the module's `view` policy allows it (at most `TEAM_SCAN` 200 per type) — e.g. comp-off claims and
leave encashments (§20.3.2 #9, #24). `App\Filament\Pages\MyRequests` (Me group, slug `my-requests`; tabs All / Pending /
Approved / Rejected / Withdrawn / Drafts, filters, "Whose requests" for managers, "Hide" / "Show again" on closed requests,
"Show hidden (n)", "Open" → the module screen where the request is withdrawn or followed up, header "New request").

**New request.** `App\Filament\Pages\NewRequest` (Me group, slug `new-request`): the types the person can raise now,
grouped by module, a search over labels / descriptions / keywords, up to twelve favourites shown first (star on each
tile), "Raise for someone" (each type's on-behalf form the person may open) and the lock notice. Both pages open for a
tenant user with an employee record of their own, `form.submit` or an on-behalf permission (`NewRequest::canAccess`,
cheap because the navigation asks on every page); both sit in the My work rail after Team requests
(`ExperienceNavigation::MODULE_SECTION` + the work order). The Home "My requests" card, My work and every module list are
unchanged.

**Preferences.** `ExperiencePreferences` keys `favourite_requests` (type keys, `[a-z0-9_.]`, at most `MAX_PINNED` 12, newest
first; `toggleFavouriteRequest`) and `hidden_requests` ("type:id", at most `MAX_HIDDEN_REQUESTS` 500; `toggleHiddenRequest`,
only for closed requests from the page). Presentation only, like the other preferences (not audited); hiding changes nothing
on the request.

**Quick actions and My work.** `QuickActions::for()` appends, after every existing action, "New request", "My requests"
and one action per available type (`request.{key}`, employee lens) except the four already listed (leave, regularisation,
Ask HR, services) — the Command Center finds them by name and keyword. `forLens()` (Home's featured actions) leaves them out
(`QuickActions::isRequestLauncher`), so Home features exactly what it did.
`WorkInbox::waiting` keeps its five sources and adds the person's other Pending requests from the registry (types other than
leave, regularisation and helpdesk, which it already lists).

**Inside the forms (§20.1.1 #7).** `Filament\Support\PendingRequestsPanel::make($employee)`: a collapsed, read-only
"Your open requests" section (the person's Pending requests of the time-office family: leave, regularisation, the
time-office kinds) at the end of the leave form (`LeaveActions::applyForm`), the time-office form (`TimeOfficeActions::form`)
and Home's / My day's regularisation forms; shown only when the form is for the signed-in person's own record (hidden on
every on-behalf form); a `Text` component, never part of the submitted data. Time-office "New request" and "Raise on behalf"
accept `actionArguments[type]` (a known kind) to open on that kind; without it they mount exactly as before.

**HR bulk cancel (§20.1.19 #4, #18).** WO-1 bulk operations `leave_requests.cancel` (`CancelLeaveOperation`: leave.manage,
refuses leave not open and leave the person may not cancel, `Leaves::cancel` with one reason) and
`reimbursement_claims.cancel` (`CancelClaimOperation`: payroll.manage, refuses claims not pending / approved,
`ReimbursementClaims::cancel`), offered on Leave requests (selected rows and "Act on all matching") and Reimbursement claims
(selected rows) to holders of `bulk.run` and the module right; an employee's own lists show no selection (bulk actions hidden
→ no checkboxes). TX-9 (time-office bulk decisions / cancellations) can add its operations beside these.

**Tests.** `tests/Feature/HroneParity/RequestRegistryTest.php` (the 26 types and complete status maps; an employee is offered
exactly what the modules allow and each type disappears without its module permission; policy / service switches —
time-office switches with the module refusing the same, advance policy, encashable types, claim components, resignation
state; the employee request lock and its notice; forms for `form.submit` and on-behalf forms per permission; the time-office
form opened on a kind and unchanged without one; My requests tabs, type facet, date kinds, search, hide closed, hidden flag;
tenant isolation and fail-closed answers; a manager following a report's comp-off / encashment / leave as the module
policies allow; the two pages, favourites (cap, unknown keys), "Raise for someone", refusals; Quick actions with Home's
featured actions unchanged and Command Center search; My work waiting rows once each; the open-requests panel only on one's
own form and the form still submitting; the My work rail; HR bulk cancel through the services with refusals and rights).

Not adapted (and why): mark attendance (a web check-in, never a request: RQ-14 / TX-10), start trip (no mobile trip
tracking: blocked), job requisition (recruitment, N-A), HR-initiated kinds — data uploads awaiting approval, compensation
proposals, letters, transfer requests, position changes, PIPs, confirmation reviews, settlements (R.2.5 #4, an RQ-1
extension not built; compensation proposals and letters still show in My work "waiting on others" as before), profile
changes as their own type (they are service requests, so they list under "Helpdesk ticket"; section facets are MS-3),
per-type raise / cancel rights (R.2.1 #24: today's module rules apply), cloning or cancelling a form submission (the forms
module has neither). Not built here either: request chrome (RQ-2: header, approver preview, timeline, reminders, notify
others), the unified inbox (RQ-8), calendar picking and "View punches" (RQ-3), manager on-behalf breadth (RQ-16), the My
space hub (MS-1).

Owner decisions (defaults applied): whether "Approved" is the right tab for helpdesk tickets that are resolved / closed and
for resignations already in force without an acceptance workflow (applied: yes; the row keeps the module's own wording);
whether expired expenses (never put in a report) count as "Withdrawn" (applied: yes).

## RQ-2 Request chrome: who approves it, the requester's timeline, reminders, notify others

Matrix: `docs/hrone-parity/modules/20-requests-inbox-team.md` (package RQ-2; the rows it closes are marked "→ built (RQ-2)"
there, and the rows of matrices 22, 24, 26 and 27 that point at it). Every default reproduces the earlier behaviour: nobody
approves anything differently, no module's request rules changed, no workflow starts differently; "Notify others" is off for
every type; the only thing available by default is the requester's own "Send reminder" on their own pending request. No
migration, no new permission, no queued job.

**Approval route of a request type.** New optional contract `Experience\Contracts\DescribesApprovalRoute`, implemented by
`BaseRequestType` with neutral defaults and overridden by the RQ-1 adapters: `raisedEvents()` (the events the module
dispatches when such a request is raised: the workflow triggers and the notification events — `leave.requested`,
`attendance.timeoffice_requested`, `attendance.regularisation_requested`, `attendance.location_requested`,
`leave.encashment_requested`, `payroll.loan_requested` / `claim_submitted` / `variable_pay_requested` /
`tax_declaration_submitted`, `expense.advance_requested` / `report_submitted` / `travel_requested`, `asset.requested` /
`return_requested`, `servicedesk.ticket.created`, `exit.resignation_submitted`, `performance.goal_plan.submitted`,
`form.submitted`), `workflowSubject()` (the model a workflow runs on: an expense report's part), `builtInApprovers()` (how the
module decides it without a workflow — a label such as "Leave approvers in the Approval Center (your manager or HR …)", never
names), `approverPermissions()` (the module's approval rights: leave.approve, attendance.approve / manage, leave.manage,
payroll.manage, expense.approve, asset.assign / manage, performance.team / manage, form.approve), `approvalKind($record)` (its
Approval Center kind, incl. the cancellation kinds), `waitingRecords($record)` (an expense report is approved as its parts, a
mixed trip as its parts), `knowledgeCategory()` (leave, attendance, payroll, expense, travel, it, hr_policy), `owns($record)`
(time-office kinds share a model), `statusField()` / `statusText()`, `employeeOf($record)`. `RequestRegistry::forRecord()`
finds a record's type; `RequestRegistry::options()` is the Setup option source `request_types`.

**"Who approves this" (§20.1.1 #2, §20.1.18 Leave #5) — a dry run.** `Workflow\Support\RequestWorkflows::preview($class,
$triggers, $employee, $requester)`: the workflows `WorkflowTrigger::fire` would start (active, published, on the triggers,
running on any record or on that request type; by priority when `workflow.first_match_only` is on, where the first one that
surely applies ends the search, else every match), only those that would decide the request (`wouldDecide`: runs on the type;
or runs on any record and — for the P5 "strict" classes, `RequestWorkflows::STRICT` + `DECIDING_TRIGGERS` — was started by a
deciding trigger; for leave, time office, expense, asset and goal requests any run decides, as their Approval Center sources
already treat it). Start conditions are checked against what is known before the request exists (the employee's
`EmployeeRuleContext`): a condition on the request's own details (days, amount, leave type …) makes the workflow "may apply"
(listed with that note — HROne's "approved by an exceptional workflow"); a known value that fails leaves it out. The approval
nodes are walked from the start node (a condition decidable from what is known is followed, an undecidable one both ways with
what lies behind it marked "only in some cases"); each step's approvers are resolved by `ApproverResolver` on an UNSAVED
`WorkflowInstance` whose subject is the employee and whose initiator is the requester (fallback, then the manager reliever, as
`createTask` does); a `field` approver reads "chosen from the details of the request". Nothing is saved: no run, no task, no
log. `Experience\Services\RequestPreview::for($type, $viewer, $employee)` adds the module's own approvers when no workflow
surely decides it (and the line manager's name when they hold one of the module's approval rights), who is informed — the
audiences of the active notification rules on the raised events as kinds of people ("Your manager", "Everyone with the role
…", "A person chosen by HR", "(in some cases)" for rules with conditions; never a list of names) — and "Read the policy"
(§20.1.1 #3): up to three published knowledge-base articles of the type's category that the viewer may read
(`KnowledgeBase::visibleTo`, needs `kb.view` and an employee record). Approver names shown are the ones the requester sees once
the request is raised (its timeline's "waiting with").

**Request chrome in the forms (§20.1.1 #1–3, #5).** `Filament\Support\RequestChrome`: `header($employee)` (initials, name
(code), designation · company; "You are raising this request for …" on an on-behalf form, which reads its own employee picker),
`route($type, $employee)` (a collapsed "Who approves this" section rendering the preview; the time-office form passes the kind
chosen), `confirmed($type, $record, $title, $body)` (the module's own success message, unchanged, plus "Follow this request",
"Notify others" when switched on for the type, "Raise another" (the type's form, when the person can raise one now) and "View
my requests" filtered to the type — only on the person's own request; on-behalf requests keep the plain message) and
`followUps()`; `ServiceDeskActions::run()` gained an optional follow-up argument. Wired into: leave (`LeaveActions::applyForm`
/ `apply`, so Home, My day and "Apply on behalf"), time office (`TimeOfficeActions`), regularisation (Home, My day; header and
route on "Raise on behalf"), loans and variable pay (own and on-behalf forms), reimbursement claims, expense advances, new
expense report (header and route; submitting stays on the report), travel, asset requests, leave encashment, location
requests, Ask HR (agents see their pick) and Resign. Nothing in the chrome is submitted; the employee pickers of four on-behalf
forms became `live()` so the header follows the pick.

**Requester timeline (§20.1.1 #8, the activity-log rows, §20.2.1 #20 / #30).** `Experience\Services\RequestTimeline`:
`forOwn($viewer, $type, $record)` only for the signed-in person's own request (the type's `ownRecords()` holds it and its module
list opens for them; anything else is null), `resolveOwn($viewer, 'type:id')`, `forItem($approver, ApprovalItem)` for an item
already in the approver's Approval Center. Entries, oldest first: submitted (and "on your behalf" by whom); decisions,
cancellations and status changes from the audit trail of the request and its parts (explicit actions `*_APPROVED`,
`*_REJECTED`, `*_CANCELLED`, `*_CANCEL_REQUESTED`, `*_ESCALATED`, `*_REASSIGNED`, `*_RESOLVED`, `*_CLOSED`, and status changes
recorded on the model mapped through the type's status map; a service's own record and the model's change within two minutes
are one entry); the deciding workflow runs' `workflow_actions` (started, "Waiting with <assignee> · <step>", approved /
rejected / done by, decided automatically, sent back, resubmitted, moved from … to …, finished, cancelled, stopped); send-backs
of Approval Center requests (`request_returns`); reminders and colleagues informed. It never shows a note an approver wrote with
a decision — the only approver text shown is the question of a send-back, which was addressed to the requester — and nothing
about anyone else's requests. "Now": while pending, whom it waits with and the earliest due time from
`Experience\Services\RequestApprovers::current()`: the pending tasks of a deciding run in flight (assignee or role label), else
the people who have the request in their Approval Center right now — found by asking the Approval Center itself signed in as
each candidate (`ScreenAccess::as`, as delegation gathers a delegator's queue), so every source's own rule decides; candidates
are the line manager and at most 15 holders of the type's approval rights (request-scoped memo).

**Pages.** `Filament\Pages\RequestTracker` (slug `request`, not in navigation, `?request=type:id` locked; opens for
`NewRequest::canAccess()`, 404 for anything not the person's own): header, status and summary, "Where it stands" (waiting with,
due, the trail), "Discussion" (§20.1.1 #12: the existing `TaskComments` thread of the deciding run in flight, else of the record
the Approval Center lists — shown and writable only while `inbox.discussion.types` lists its kind and `TaskComments::mayView`
lets the person in), "Read the policy", and header actions "Send reminder", "Notify others", "Open" (the module screen) and
"Raise another". My requests rows of one's own requests gained "Follow". Approvals (§20.2.1 #20, #26, #30; R.3.2 #2): each
decision card on the Approvals page has "Workflow and policy", loaded for one item at a time (`Approvals::showTrail`,
`trailFor` — the item comes from the viewer's own queue, never from the browser): the trail, whom it waits with (other
approvers too) and the policy articles of its type. Drawer cards are unchanged.

**Send reminder (§20.1.1 #9).** `Experience\Services\RequestFollowUps::remind()`: only the requester, only their own request,
only while it is Pending; at most once every `requests.reminder.min_hours` hours per request (default 24; the last
`REQUEST_REMINDER_SENT` audit event of the request decides; a cache lock per request makes two clicks send one). Recipients:
`RequestApprovers::current()` (never the requester); none → refused ("Nobody can be reminded …"). Audited on the request
(`AuditAction::RequestReminderSent`, metadata request type, recipient ids, via) and sent as `request.reminder`.

**Notify others (§20.1.1 #6, §20.2.1 #40).** `RequestFollowUps::notifyOthers()`: only for the types listed in
`requests.notify_others.types` (empty = off, today's behaviour), only the requester's own request while Pending or Approved;
1–20 colleagues the requester may find in search (`PeopleVisibility`: with `employee.view` the people in their access scope,
otherwise their manager and direct reports) with an active account; a message of at most 500 characters (tags stripped,
optional); at most 10 per person per hour. Audited on the request (`AuditAction::RequestIntimated`, recipient ids and whether a
message was sent — never its text) and sent as `request.intimated`.

**Notifications.** Event `Experience\Events\RequestFollowUpEvent` (`request.reminder`, `request.intimated`, both in
`notifications.events`) → `NotificationEventBridge::onRequestFollowUp`: notification rules first (the request's employee
context, `request.*` labels — type, title, status, requested on, by, and the message of an intimation — and the new audience
type `named` = the people the event names; a reminder also fills `task.assignee_user_ids`, so `assignee` works), else an in-app
note to the recipients (delivery log as every notification). A reminder never carries the request's reason.
`NotificationCategories`: reminders under Approvals, intimations under Mentions.

**Settings (Setup → Automation, group "Requests").** `requests.reminder.min_hours` (1–720, default 24),
`requests.notify_others.types` (multiselect of registry types, option source `request_types` in `SetupCatalogue` and
`PolicySettingsSchema`; default none).

**Tests.** `tests/Feature/HroneParity/RequestChromeTest.php` (defaults and wiring; built-in approvers and the manager hint; a
workflow previewed with its approvers and nothing started; conditional and failing start conditions, first match by priority
and every match; P5 strictness for regularisations; intimation as kinds of people; policy articles by category and `kb.view`;
the header own / on behalf; the chrome in the forms; the requester's trail without approver notes; nobody else's request
(404); a workflow's levels, waiting with and due; reminders once per period, audited, refused when decided or not one's own;
reminders to a workflow's assignee through a `named` rule; the tracker's actions; notify others off by default, recipients by
visibility, length and status refusals, audit without text; the confirmation follow-ups only on one's own; discussion from the
tracker; the approver's on-demand trail and policy; My requests "Follow").

Not built here (and why): "Add approver" (RQ-9), "Move to archive" was RQ-1's hide flag, the unified inbox (RQ-8), timelines
of HR-initiated kinds that are not registry types (confirmation reviews, letters, transfers — matrix 22 §22.14.6 #2 stays), a
trail on the approval drawer (only the Approvals page card has it), a confirmation on submitting an expense report (the report
page's own "Submit" keeps its message), attachments preview (§20.1.1 #4).

Owner decisions (defaults applied): whom an employee may inform (applied: the people they may find in search —
`PeopleVisibility`; HROne lets anyone be searched); whether approver names are shown to the requester in "waiting with"
(applied: yes, the people who have it now, as HROne's "Pending with"); the knowledge-base category per request type (applied:
fixed per adapter); whether "Send reminder" should also be switchable off (applied: always available on one's own pending
request, as the package asked).

**Merge note (2026-10-30).** The new setting key `requests.reminder.min_hours` made the configuration finder's setting search
(`ConfigurationSearch::settings`, any word of four letters or more found in a key) list it for "working hours". "hours" joined
`ConfigurationSearch::GENERIC_WORDS` (with "days", "time"): a unit word no longer picks out a setting on its own.

## AN-26 / AN-27 Loans, reimbursements and variable pay datasets; performance datasets

Matrix: `docs/hrone-parity/modules/21-analytics-reports-projects.md` (packages AN-26, AN-27; §21.2.2 Payroll #32–34, 47,
48, 50, 51, 60–62; §21.1.12 Payroll #6, #8; §21.2.2 Performance #1–7, 9, 10, 12, 15, 17, 18, 20, 23, 24 — #2, 360
nomination status, too, now that HP-8h's nomination sets exist), rows marked "→ built (AN-26 …)" / "(AN-27 …)". Read
only: no migration, no new permission key, no setting, no job. Nothing existing changed its output: the new datasets
and templates are additions (the registry and the library), and `FeedbackRoundResults` gained one read method.

**How each dataset reads.** Every dataset is gated by the right of the screen it reports on and runs in the reader's
access scope (the `AccessScope` global scope of its model, or — for rows that are not scoped themselves — `whereHas` on
the scoped parent: instalments on their loan, check-ins on their goal, reviews and ratings on their appraisal, checkpoints
on their plan). None is on `peopleos.analytics.unattended_datasets`, so a run without a signed-in user (the read API) is
refused before a row is read; scheduled runs run as their owner, as before.

- AN-26 (category "Payroll & compensation"; `payroll.manage` — the Loans & advances, Reimbursement claims and Variable
  pay screens are payroll.manage's, so `payroll.view` alone does not open them): `loans` (terms, recovered so far and
  instalments recovered / left — `withSum` / `withCount` on the schedule —, outstanding, next recovery month, guarantor,
  beyond-policy flag, decision), `loan_installments` (HROne's loan ledger: one row per instalment with due month and a
  `due_on` date for period filters, principal / interest / amount, status, recovered by payroll, settled on),
  `reimbursement_ledger` (openings and accruals credit, paid claims and lapses debit, signed `amount` = balance; `month_start`
  dates openings at their financial-year start; `employee_component` is a grouping key per person and reimbursement),
  `reimbursement_claims` (claimed, approved, payable — 0 when rejected or cancelled —, number and total of bills, number of
  attachments; the files stay on the claim), `variable_pay_plans` (one row per plan: the schedule as "month: target", total
  target, payouts entered, paid and awaiting, months due without a payout, next month due and its target) and
  `variable_pay_requests` (pay month and `pay_month_start`, plan and plan month with that month's target and the
  variance, status). Every amount (and the plan schedule) is a **sensitive field** with `sensitivePermission()` =
  `payroll.manage`: the dataset's readers always see them; a feed without a reader (the warehouse export's `fieldsFor(null)`)
  leaves them out. Month by month target against payout lives in `variable_pay_requests`; a plan × month row set is not
  built (the runner maps one model to one row).
- AN-27 (category "Performance & learning"): `one_on_ones` (with whom, who scheduled it and whether the employee asked,
  agenda, held on, follow-up actions done / open, feedback cards given and their average rating — never the shared notes,
  the private notes or the feedback comments), `continuous_feedback` (type, visibility, goal, competency, status, message;
  the giver of anonymous feedback reads "Anonymous" through `FeedbackEntry::authorLabel()` — never revealed, not even to
  holders of `performance.anonymous_identity`, whose reveal stays an audited action on the entry), `check_ins` (goal
  check-ins at every goal level: whose goal — the employee or the organisation unit —, KPI, progress before / after and the
  change, confidence, source, note, by whom; organisation goals have no employee and stay visible to scoped readers as on
  the Goals screen), `performance_check_ins` (cadence, period, submitted / reviewed, goals updated, actions — never what
  went well, blockers or the feedback both ways), `goal_plans` (one goal sheet per appraisal of a cycle's population —
  so employees without any goal are rows too — with the status from `GoalSheets::withSheet()` / `statusFrom()`, exactly the
  Goal sheets screen's, goals, weightage, drafts and goals waiting, plans submitted and the latest plan's status and
  dates), `pip_checkpoints`, `appraisal_reviews`, `appraisal_ratings`, `development_needs` (competency or skill, levels,
  source, priority, status, target date — never the description, states or reason) — all `performance.view` (the screens
  show HR every row in scope) except as follows.
- PIP confidentiality — `pip_checkpoints` needs `performance.pip` (as `improvement_plans`); a reader without
  `performance.view` gets only the plans `ImprovementPlanResource` lists for them (their own or those they manage), and a
  run without a reader gets nothing. Checkpoint notes and outcomes and the plan's reasons, objectives and outcome never
  reach it.
- Review visibility — `appraisal_reviews` (level, reviewer, status, overall rating, items rated, whether feedback was
  written, strengths / areas to improve / comments) and `appraisal_ratings` (one row per goal / KPI / competency rating
  with the item, its weightage — the goal's or KPI's own, a competency's as scoring uses it, `Appraisals::
  competencyWeightsFor` —, rating, achievement %, comment and `is_final_level` = the appraisal's last level, the approver
  when there is one) are for `performance.view` or `performance.calibrate` (as Review requests / Goal sheets); ratings and
  text follow `AppraisalVisibility::reviewVisible()` for the reader (HR sees every level; a run without a reader sees
  none), the text needs `performance.view` (a field `permission`), and the reviewer of a review marked anonymous is never
  named ("Anonymous") — stricter than the Reviews tab, which names them to HR, because a report travels as a file.
- 360 (HP-8h) — included because it can keep the 360 anonymity rules exactly: `feedback_360_status` (one row per
  assessed employee and round: who nominates, nomination status, submitted / decided, and counts nominated / asked /
  given / declined / still to answer — never who was asked) and `feedback_360_results` (one row per assessed employee and
  source, from the nominations grouped by participant and source, never one per reviewer: anonymous source, asked,
  answered, declined, shown, average score, note). The numbers come only from the new
  `FeedbackRoundResults::aggregatesFor($participant, $viewer)` — the export's aggregate (the private `compile()` gained a
  `$detail` flag so no question aggregates or named answers are built for it) — for HR (`performance.feedback_360` or
  `performance.view`) in their access scope and never about themselves (those rows are left out of the query); an
  anonymous source's average stays blank below the policy's `min_responses`; no reviewer name, single answer, question
  average or comment text; no reader, no rows. Both datasets: `performance.feedback_360` or `performance.view`.

**Templates** (`ReportLibrary`, 28): Payroll & compensation — Loan ledger (this financial year), Loan requests, Loan
summary (disbursed loans), Loans outstanding (till date) (per loan type; the Payroll dashboard's "Loans"), Reimbursement
audit (this financial year) (per employee and reimbursement: credited, claimed or lapsed, balance), Reimbursement monthly
ledger (last month), Reimbursement requests, Reimbursement summary by component (this financial year), Variable pay paid
(this financial year) (the dashboard's "Variables"), Variable pay plans (month-wise schedule), Variable pay requests;
Performance & learning — 1-on-1 details, 360 feedback status, 360 nomination status, 360 feedback details, Company check-in
report (this month), Employee goal check-ins (this month), Team check-in report (this month), Check-in conversations,
Continuous feedback, Goal status (goals not created or not approved), Goals not created, PIP evaluations, Performance
feedback details, Performance review details (final level), Performance review details by approver, Performance review
rating by approver, TNI details (training needs).

**Files.** `app/Domain/Analytics/Datasets/{Loans,LoanInstallments,ReimbursementLedger,ReimbursementClaims,
VariablePayPlans,VariablePayRequests,OneOnOnes,ContinuousFeedback,Feedback360Status,Feedback360Results,CheckIns,
PerformanceCheckIns,GoalPlans,PipCheckpoints,AppraisalReviews,AppraisalRatings,DevelopmentNeeds}Dataset.php`;
`DatasetRegistry.php`, `ReportLibrary.php`; `app/Domain/Performance/Services/FeedbackRoundResults.php`
(`aggregatesFor`).

**Tests.** `tests/Feature/HroneParity/PayrollPerformanceDatasetsTest.php` (registration, categories and the right per
dataset — payroll.view alone, performance.view, performance.calibrate, performance.feedback_360, performance.pip —, none
readable without a signed-in user; every dataset and template over two employees' rows in strict mode; loan, ledger,
claim and variable pay figures; amounts left out of reader-less feeds; anonymous givers and reviewers never named, notes,
private notes, feedback comments and PIP text never reported, comment text only with performance.view, ratings hidden
without a reader; the final review level and weightage; 360 aggregates with the anonymous minimum, the same average as the
round's export, never about the reader, status counts; goal sheets incl. not created and the check-in levels; PIP
checkpoints for the PIP holder without performance.view and none without a reader; every new dataset inside a
location-scoped reader's scope and none of it in another tenant).

Not built here: a plan × month row set for variable pay plans (the runner maps one model to one row; target vs payout
per month is in `variable_pay_requests`); managers' own team reports (every new dataset is HR's, as the existing
performance datasets); the pivoted "ratings by approver" (AN-24); P13 feedback requests (exit interviews, confirmation,
induction — AN-29) and the HP-8i review-feedback form answers.

## MS-1 My space hub and favourites

Matrix: `docs/hrone-parity/modules/22-my-space-self-profile.md` (package MS-1; rows 22.1 #1–5 and 22.1.1 #2–5 are marked
"→ built (MS-1)", and the matrix-20 rows that pointed at MS-1: §20.1.0 #3, R.2.6 #1). Every default reproduces the earlier
behaviour: no page, resource, navigation entry or access rule changed; nobody has a favourite until they heart one; no
item is hidden until the organisation hides one. New: the My space page, its entry in the avatar menu, the favourites at
the top of the avatar menu, and My space rows among the Command Center's navigation results. No migration, no new
permission, no queued job.

**Contract and registry.** `App\Domain\Experience\Contracts\MySpaceItem` (tag `peopleos.myspace_items`, registry
`Experience\Services\MySpace`, request-scoped in `ParityServiceProvider` and forgotten on `RequestHandled`, like RQ-1's
`RequestRegistry`). An item names its key (`[a-z0-9_.]`), label, description, group (`MySpace::GROUPS`: profile, time,
pay, performance, engage, requests), icon, keywords, `available($user)` and `url($user)`. The default adapter
`Experience\MySpaceItems\PageItem` points at a Filament page or a resource's list (optional query parameters, e.g. a My HR
tab); `available()` is the target's OWN `canAccess()` for the signed-in person and nothing else, so My space can neither
show a page the person cannot open nor hide one they can. A target that is not a page or a resource with a list is
refused when the item is built. The registry answers only for the signed-in person inside a tenant, memoises per tenant,
user and item for the request, asks only the hearted items for the favourites (the avatar menu reads them on every page),
and drops an item that throws (reported). Other packages add their own items by tagging a `MySpaceItem` class (or a
`PageItem` binding) — e.g. MS-10's Salary YTD or MS-15's Polls when built.

- The 68 built-in items (`Experience\MySpaceItems\SelfPages::ITEMS`, keywords carry HROne's item names):
  Profile — My profile (MS-2), My HR, HR handbook (My HR → Policies), Knowledge base, My letters, My assets, My exit,
  Alumni portal, Preferences; Time — My day (HROne "Mark attendance"), Attendance calendar (also "Holiday calendar" and
  "Shift" until MS-11 / MS-12), Regularisations, Attendance records, Late-mark penalties, Time-office requests (on duty, short leave, restricted holiday, change
  shift, overtime, comp-off), Location requests (geo location, location approval), Leave requests, Leave balances, Leave
  transactions, Leave donations, Leave encashments; Pay — My compensation (CTC), Payslips, My tax certificates, Declare
  investments, Tax declarations (proposed investment history), My investment proofs (confirmed investment history), Loans
  and advances, Reimbursement claims, Variable pay, Variable pay plans, Expense receipts, Expense reports, Expense advances, Travel requests
  (travel, tickets); Performance — Goals (goal history), My initiatives, Appraisals (performance scorecard),
  One-on-ones, Check-ins, Continuous feedback, 360 feedback, 360 feedback to give, Improvement plans (PIP, PIP
  evaluation), Feedback forms (exit interview, confirmation, probation, induction), My career, Career passport, My
  learning, Course enrolments, Learning certificates, Course catalogue, Training sessions, Development plans, Development
  needs, Skill assessments; Engage — Recognition and Reward points (badges and rewards),
  Social wall, Birthdays and anniversaries, Announcements, Surveys (My HR → Surveys), Assistant; Requests — My requests and
  New request (RQ-1), HR helpdesk tickets (My HR → Requests), Asset requests, My grievances, Out of office.
- Team and HR pages (My team, Team requests, Approvals, Task inbox, desks, registers reached only through HR rights) and
  the inbox pages of the My work rail (My work, Notifications) are not items: a manager's My space holds their own self
  pages. Organisation pages (Directory, Org map) and reference lists (leave types, learning paths, programs, providers,
  instructors, skill levels) are not self pages either; Compensation changes is not one (nobody sees their own there). Several resources are both the person's own list and, for
  people who run the module, the HR register (loans, letters, assets, exits …); the item opens the same list the "Me"
  navigation opened, under the same rule.
- HROne items with no PeopleOS page yet are not listed; their owning packages add them: Attendance ytd and Shift (MS-11),
  Holiday calendar as its own page (MS-12), Salary ytd (MS-10), Fbp approval history (BP-7), Polls, Team announcement and
  Reward store (MS-15; vendor VEN-3), Social profile (MS-6), Clearance tasks, own Confirmation and Trips (MS-16), QR Code
  (MS-17). Job requisition and Position assigned are recruitment (N-A).

**My space page.** `App\Filament\Pages\MySpace` (Me group, slug `my-space`; opens for a tenant user with an employee
record of their own — cheap, the navigation asks on every page): favourites first, then the groups in order with the
items alphabetical; chips "All · n" and one per group that has items (HROne's module chips; `setGroup`); a search over
label, description, group and keywords; a heart per item (`toggleFavourite`, refused for keys the person cannot open).
View `filament/pages/experience/my-space.blade.php` + `partials/myspace-tile.blade.php` (the RQ-1 "New request" tiles).

**Favourites.** `ExperiencePreferences` key `favourite_pages` (item keys, `[a-z0-9_.]{1,64}`, at most `MAX_PINNED` 12,
newest first; `toggleFavouritePage`). Presentation only, per person inside their tenant (the preferences row is
tenant-scoped), not audited. A favourite whose page the person can no longer open, or that the organisation hid, is simply
not shown (the stored key stays, so it returns if access returns).

**Avatar menu.** `AdminPanelProvider::userMenuItems`: twelve slots `myspace-favourite-0 … 11` first (each visible only
while that favourite is available; label, icon and URL from the item), then the existing entries in their order, with
"My space" added before Preferences. With no favourites the menu is as before plus "My space".

**Command Center.** `CommandSearch::matchModules` adds each available item as a navigation row (`id` `myspace:{key}`,
subtitle "My space · {group}") scored on its label, keywords and group; modules win equal scores and a URL already found
is not listed twice.

**Setting.** `myspace.hidden_items` (Setup → Core HR, group "My space", multiselect of registry keys via `options_from`
`myspace_items` = `MySpace::options()`; default `[]`). Hidden items leave My space, its favourites in the avatar menu and
its Command Center rows; the pages themselves keep their navigation, URLs and access (hiding never grants and never
blocks). Unknown keys are refused by the Setup centre.

**Tests.** `tests/Feature/HroneParity/MySpaceTest.php` (registry shape and adapter refusals; every item offered exactly
when its target's own `canAccess()` lets the person in — employee, manager, HR, a person with no rights, a user with no
employee record; items disappearing without their module right; a manager's My space without team pages; fail-closed
answers for another user, without a tenant and for an item that throws; the page with groups, chips, search by HROne's
names and the empty state, 403 without an employee record; favourites with the cap, refusals, the avatar menu order and
favourites no longer available; favourites per person and per tenant; the hidden-items setting through the Setup centre,
leaving the page open; Command Center rows by label and HROne name, no duplicates, nothing the person cannot open).

Not built here: page access per item for roles (CA-9: HROne's 54 "View" grants stay the domain permissions), "Recent
searches" in the flyout (the Command Center keeps its own recent items), HROne items without a page (above).

## PO-1 / PO-3 Payroll desk, payroll register filters and the pre-run readiness panel

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (packages PO-1 and PO-3; §25.1 #1–#7, §25.13 #4; §25.6.2 #2–#5,
#7, #8, #12–#18 and §25.6.1 #8 (display)), rows marked "→ built (PO-1 …)" / "(PO-3 …)". Every default reproduces the
earlier behaviour: no register lost a column, filter, action or navigation entry (the navigation entries keep their place;
the desk gives HROne's order); an employee's own claims, loans, variable pay, payslips, tax declarations and proofs look
and behave as before — the new filters and header tools appear only on the payroll side of each register; the control
room's figures, company cards and runs table are unchanged and the readiness panel below them only reads. Nothing in
these packages writes payroll runs, entries, lines or periods, offers a bulk payroll operation, or blocks a run. No
migration, no new permission key, no setting, no queued job; owner decisions PAY-1 … PAY-16 stay on their defaults (the
thresholds the panel needs wait for PAY-10's rule verification).

**Payroll desk (PO-1, §25.1 #1).** `Filament\Pages\PayrollDesk` (an `OperationsDeskPage`; Payroll → Payroll desk, its own
rail area "Payroll desk" via `ExperienceNavigation::MODULE_AREA`) orders the existing payroll screens as HROne's Payroll
panel: Salary structure (structures, salary structure requests = compensation changes waiting, salary revisions, salary
upload) · Tax & benefit (reimbursement requests and cancellations, proposed investments = submitted declarations,
confirmed investments = proofs to verify, declare investments for an employee, perquisites and other income) · Variable
pay (requests, cancellations, plans) · Loan (loan requests, dispense loans, loans being recovered) · Payroll processing
(run payroll = control room, runs not finalized, adjustments, attendance arrears, approved leave encashments, salary holds,
statutory overrides, PF options, payslips, Payroll Auditor, generate challan = compliance reports, compliance control
room, pay cycle) · Full and final settlement (exit cases in settlement, settled exits — new `ListExitCases` views
`settlement` / `settled`) · Payroll letters (appointment letters; salary revision letters = new `ListLetters` view
`increment`) · Reports (report library, my reports, compensation analytics) · Payroll setup (Setup centre `payroll`,
components, payment banks, payment modes, bank file layouts, bank account rules, statutory profiles, payroll uploads).
HROne's separate request / cancellation / history registers are preset views (`?view=`). It opens for `payroll.view` plus
an operations right (`PayrollDesk::OPERATOR_PERMISSIONS`: manage, calculate, approve, finalize, override) — not for
read-only auditors or employees — and lists only entries whose screen opens for the viewer; counts come from each
register's own scoped query. Its header carries the "Payroll actions" menu.

**Register depth (PO-1, §25.1 #2–#7, §25.13 #4).** `Filament\Support\PayrollRegisters`:
- `filters($permission, $dates)` — the WO-2 employee filters (pasted codes, organisation group, employee status / type /
  joining, pending-resignation toggle) and the register's date ranges, visible only to holders of `$permission`.
- `structure($permission, $on)` — HROne's "Pay Group": the salary structure of the employee's approved compensation in
  force on a date (run entries: the run's period end; elsewhere today), through the contract —
  `CompensationOutput::coveredEmployeeIds($date, ?array $structureIds)` gained the optional structure list.
- `payMonth()` (HROne's "Salary Month", on `pay_month` 'YYYY-MM' columns), `arrears()` ("Pay Arrear (For) Month" and the
  "Arrear Days" bands 1–5 / more than 5 up to 10 / more than 10 / zero / less than zero, over the employee's total arrear
  days in the chosen month, on attendance arrears for `attendance.view`), `revisionMonth()` ("Salary Revision (On) month"
  on compensation changes, by effective date).
- `uploads($keys)` — the register's "IMPORTS": the salary upload and the payroll upload types (perquisites, income outside
  payroll, statutory overrides, PF options), each opening the upload dialog with its type and shown only to people allowed
  that upload.
- `actionsMenu()` — HROne's split button: Run payroll, Assign salary structures (upload), Declare investments for an
  employee, Variable pay request, Loan request (on behalf), Hold salary, Record a perquisite, Generate challan — links to the
  existing actions (`?action=`), each only when its screen opens and the right is held (the action re-checks on mount);
  shown to payroll.view holders who manage or calculate payroll.
- Registers opted in to WO-1 `HasRegisterTools` (register key → views; reports): `reimbursement_claims` (waiting,
  approved, paid, cancellations, rejected; reimbursement requests / audit / summary / monthly ledger), `variable_pay_requests`
  (waiting, approved, paid, cancellations, rejected; variable requests / paid), `loans` (requests, to disburse, recovering,
  closed, declined; loan requests / summary / outstanding / ledger), `salary_holds` (held, released), `compensation_changes`
  (waiting, under review, to execute, scheduled, effective; salary revisions, pending revisions, average increase; salary
  upload), `tax_declarations` (submitted, verified, draft), `tds_investments` (pending, verified, rejected),
  `tax_income_entries` (+ perquisite and outside-income uploads), `statutory_overrides` (+ upload), `pf_options` (+ upload),
  `payroll_adjustments`, `payroll_runs` (salary and component registers, cost by month; actions menu), `payslips`,
  `salary_structures` (CTC register, employees without salary; salary upload). Registers shared with self-service (claims,
  variable pay, loans, payslips, tax declarations, proofs) show the tools only to payroll (`payroll.manage` or
  `payroll.view`, as each resource already splits its query). Pages with labelled dataset values translate their filters
  in `registerReportFilters()` (claims, variable pay incl. the pay month, loans, perquisites, overrides). Employee search on
  claims, variable pay, loans, holds and compensation changes is `EmployeeFilters::search` (a superset of the name search).
- Run entries (`EntriesRelationManager`): pay group on the run's period end, pasted codes, organisation group and the
  pending-resignation toggle (§25.1 #4).
- No bulk operation is registered (bulk payroll decisions belong to later packages).

**Pre-run readiness (PO-3, §25.6.2).** `Domain\Payroll\Services\PayrollReadiness::evaluate(?companyId, activeOnly = true,
withAmounts, today)` looks at each company's pay month holding today (cycle-aware; a fixed period's dates when a run exists;
never creates a period) over exactly the people a run of that month would calculate — `PayrollRuns::populationIn()`, the
population query extracted from `populationQuery()` unchanged — or, with "active employees only" (default), only those still
employed. Fourteen checks, each `ok` / `attention` (count + list) / `no_rule` / `not_run`:
- statutory rules for the month (`RequiredRules` → the establishments' rules not VERIFIED, notice open or missing; §25.6.1 #8);
- salary not processed last month (last month's population without an entry in its finalized / paid run; `not_run` when
  there is none);
- attendance not processed (days up to yesterday inside joining / exit without a record — `AttendanceOutput::processedDates`,
  a new bulk read on the attendance contract; a note when `payroll.lop_from_attendance` is off);
- statutory details missing; PAN, Aadhaar missing; UAN missing where PF applies (employee choice and the statutory context
  of the establishment / company profile, `StatutoryContexts`); ESI IP number missing where ESI was deducted in the
  employee's latest calculated entry — identifiers are tested for presence in SQL (`IS NULL`), never decrypted or shown;
- salary structure missing (no approved compensation at the month end or the last working day inside it);
- breakup checks on the structure's own breakup (`CompensationOutput::forPayroll` + `StructureFormulas::preview`, as the
  salary annexure does; employee attributes loaded only when a formula reads them): breakup ≠ CTC by 1 or more or not
  computable (components-mode structures excluded — `CompensationSegment` now carries the version's `ctcMode`);
  wage share (basic + DA, the config classification list, of gross) below `WAGES.min_wage_share`; monthly wages below
  `MIN_WAGE.monthly_minimum` of the employee's state (establishment state, else the PT state); ESI wages (components with
  `esi_eligibility_wages ?? esi_applicable`) above `ESI.wage_ceiling` for employees with ESI marked;
- special states (serving notice or pending resignation, suspended, on long leave, exited, last working day in the month).

Statutory thresholds come only from a rule version that `ComplianceRules::resolve()` returns for the date (and state) and
that is VERIFIED, checksum-intact, free of an open regulatory notice and carries a positive numeric parameter
(`PayrollReadiness::RULES`). Otherwise the check is `no_rule`, shows "No verified rule" and does not run — no fallback
number; the 50% config guardrail used by the structure preview is not read here. Today (0/24 verified, no `WAGES` or
`MIN_WAGE` versions) all three show "No verified rule". A `MIN_WAGE` version keyed by skill category or zone instead of one
`monthly_minimum` is not applied until employees carry those (BP-15).

**Screen.** The payroll control room (`payroll.view`) gained "Before you run payroll" below the company cards: it loads
after the page (`wire:init` → `loadReadiness`), with a company choice (blank = every company; another tenant's id finds
nothing) and the "Active employees only" toggle; each check is a card with its count (or "No verified rule" / "Not run"),
what it checks and its notes; a card opens its list (first 200 rows) with "Download CSV" →
`PayrollReadiness::csv()`: the same columns as the list, formula-injection-safe cells, audited as `EXPORT` (module
`payroll`, metadata `export = payroll_readiness`, check, rows, company, active-only, amounts). Export right = `payroll.view`,
as the list itself (matrix 25 §25.6.2 #17; existing payroll exports do not use `payroll.manage`). Amount columns (CTC,
breakup, wages) appear in lists and CSV only for readers with `compensation.view`; ratios and rule figures always. The
panel informs and never blocks: calculation, validation and finalize keep their own checks. Everything reads inside the
reader's access scope (employees, statutory details, entries, attendance) and fails closed on tenancy.

**Data, permissions.** No migration, no new table, permission key, setting or job. Contract additions (read-only):
`CompensationOutput::coveredEmployeeIds($date, ?$structureIds)`, `CompensationSegment::$ctcMode`,
`AttendanceOutput::processedDates()`, `PayrollRuns::populationIn()`. `ExperienceNavigation::MODULE_AREA`: Payroll desk.

**Tests.** `tests/Feature/HroneParity/PayrollDeskReadinessTest.php` (every check over a finalized September and an open
October; active employees only vs the whole population; thresholds only with a verified rule — another state's minimum
wage, a rule without the figure and an open notice all leave the check off — with the figures read from the rule; amounts
only with compensation.view and no identifier anywhere; the panel, its list, company choice, toggle and audited CSV; no
payroll write and no period created; access scope and tenant isolation; payroll.view only; the desk for operators only in
HROne's order with views, counts and the actions menu; pay group, pay month, employee and report filters on the
registers and an employee's own list unchanged; run entries by pay group and pending resignation; arrear month and day
bands; revision month; upload links per right).

Not built here: the PAN–Aadhaar link check (§25.6.2 #11, after BP-8 `pan_aadhaar_linked`), minimum wages by skill
category / zone (BP-15), the run cockpit with step tables and step exports (PO-4), exclusion of blocked employees (PO-5),
the employee-centric salary structure register and "Not assigned" export (PO-2), reimbursement / investment / variable /
loan / settlement uploads (PO-18), bulk payroll operations, claim on behalf and bulk encashment (PO-9, PO-8).

## MS-3 Self-service profile changes

Matrix: `docs/hrone-parity/modules/22-my-space-self-profile.md` (package MS-3; rows 22.2.0 #5–8, #12; 22.2.1 #2, #4 and the
edit side of #6; 22.2.2 #1, #2, #5, #12, #14; 22.2.3 #4; 22.2.6 #2; 22.2.7 #2, #3; 22.2.9 #5; 22.2.10 #4; 22.2.11 #1, #2;
22.2.12 #1, #4–6 are marked "→ built (MS-3)"; 22.2.5 #8 is partly built — the mechanism, its section joins with MS-5).
Every default reproduces the earlier behaviour: nothing is editable (`profile.self_edit.sections` all "Employee may change
it" unticked), legal name and date of birth are off, the BGV lock is off; My profile reads exactly as before and no request
type is offered. Owner decisions SSE-1 / D-MS2 (which sections, which need approval) and SSE-2 / D-MS11 (legal identity)
stay open; the defaults above are the "today" answers.

**Built on the Phase 12 flow, not beside it.** Phase 12 lets an employee ask HR for a profile change through a service
request (service desk ticket + `ServiceDomainAction` handler, approval in the workflow engine, an HR executor who is a third
person, values encrypted and purged); that path is unchanged and keeps listing under "Helpdesk ticket". MS-3 reuses its
parts: the People / Employment `Change*Action`s apply every change (their `ProfileChangeGuard` authorisation, rule set,
CA-4 linked-document rule, employee row lock, value-free timeline entry and event, audit with a reason and reference);
`ChangeOrigin` gains the source `self_service` (reference `PCR-000012`); the request's values are encrypted while it waits
and purged when it is decided (`purged_at`), as `DomainActionExecutor::purge` does. What differs from the package sketch
and why: the request is its own record (`profile_change_requests`), not a ticket — a section edit has no service, SLA, team
or third-person executor, and "apply at once" cannot be a ticket; the workflow bridge lives in `app/Domain/People/Listeners`
like the other P5 bridges (there is no `Workflow/Bridges` folder); the reimbursement account (22.2.7 #6) is not built —
`PaymentModes::set` holds pay modes and the company's paying accounts, and PeopleOS has no separate employee reimbursement
account to pay claims into (that needs a payroll/bank-file package first).

**Data.** Migration `2026_12_14_200001_create_profile_change_requests`: `profile_change_requests` (`ProfileChangeRequest`,
People; tenant-scoped, access-scoped by employee, audited — `payload`, `fields`, `open_key` excluded from the audit, the
reason and decision note masked there): employee, requested_by, section, route (`direct` / `approval` / `workflow`), status
(pending / approved / rejected / withdrawn — never deleted), comment (≤ 500), `payload` (encrypted:array, hidden: the
operations with the new values; highly sensitive in `peopleos.data_classification`), `fields` (label + masked earlier / new,
written at submit, kept), `approver_permissions` (the rights the operations' actions ask), `document_ids`, `open_key`
("employee:section" while pending; unique per tenant → one open request per section), `operation_id` (audit operation),
decided_by / at, decision_note, applied_at, `applied_records` (morph type + id of what changed), purged_at. Also
`custom_fields.editable_by_employee` (default false; Custom fields form "Employee may change it") and
`employee_documents.upload_comment` (the uploader's comment — My HR "Upload a document" gained a Comment field; shown on My
profile → Documents and as a toggleable column on the 360 Documents tab). `DataInventory`: `profile_change_requests` in
`people`.

**Sections** (`App\Domain\People\Support\ProfileEditSections`, rows `peopleos.profile.self_edit_sections`): blood group;
legal name and date of birth (`legal_identity`, not a matrix row: `profile.self_edit.legal_identity`); personal e-mail and
phone; addresses (current / permanent, "Same as present"); emergency contacts; PAN / Aadhaar reference / UAN (the reference
only, as today); marital status and family members; education; work experience; profile skills (`person_skills`); other
details (custom fields visible to and editable by the employee); bank account (confirm account number, not stored). Each
names its My profile tab and the CA-6 section that must show it to the employee (`profile.section_visibility`; an employee
edits only what the matrix shows them). Bank, identifiers and legal identity always need approval, whatever the matrix
says; legal identity always needs a supporting document.

**Operations** (`App\Domain\People\Services\ProfileChangeOperations`): a section's form data becomes one operation per
record that changes — `{kind, record_id, values, changed}` — validated with the owning action's own rules (records named by
id must be the employee's; masters such as skills and qualifications looked up in the tenant; self-declared education and
experience stored unverified; a skill already on the profile refused). Applying runs `ChangeAddressAction`,
`ChangeEmergencyContactAction`, `ChangeFamilyMemberAction`, the new `ChangeQualificationAction`, `ChangeExperienceAction`,
`ChangeProfileSkillAction` (all on `ChangePersonRecordAction`; events `employee.qualification_changed`,
`employee.experience_changed`, `employee.profile_skill_changed`), `ChangeStatutoryIdentityAction`, `ChangeBankAccountAction`,
`UpdatePersonAction` (only the section's person fields; it now takes the approval reference) and `CustomFields::store` (only
fields marked editable) — the last two after `ProfileChangeGuard::authorize`, like the others. No entry is removed here
(HR removes entries). `describe()` gives earlier (read from the record at decision time) → new per field, sensitive values
(identifiers, account number) masked unless the viewer holds `employee.sensitive.view`; `maskedSummary()` keeps
"••••" + last four for the history.

**Lifecycle** (`App\Domain\People\Services\ProfileChanges`):
- `submit($employee, $requester, $section, $input, $comment, $document)`: own record only (and `can('view')` it); section
  editable; P4 employee request lock (new kind `profile_change` in `SelfServiceLock::REQUEST_KINDS` and the registry's
  `LOCK_KINDS`); `profile.self_edit.lock_during_bgv` refuses while a BGV case is initiated / in progress; reason required
  (≤ 500, tags stripped; it is the audit reason of every change: "Profile change PCR-…: <reason>"); one open request per
  section (pre-check, employee row lock, `open_key`); nothing changed → refused; documents: a CA-4 type "required with" a
  field that changes and not on file must be attached (that type), legal identity always — stored through
  `DocumentRules::employeeUpload` (pending, HR verifies; the file is removed again if the submission fails).
- Route: raises `profile.change_requested` (EmploymentEvent; `WorkflowTrigger` now hears that one employment event, with
  `section` in the context). A deciding run (P5: runs on "Profile change", or on any record started by that trigger —
  `RequestWorkflows::DECIDING_TRIGGERS`) → route `workflow`; else the section needs approval → `approval`; else applied at
  once (`direct`) in the employee's name.
- Applied at once: the employee does not hold `employee.create` / `employee.update`, so `ProfileChangeGuard::authorize()`
  (new optional `$origin`) accepts a `SelfServiceGrant` instead — set only by `ProfileChanges` while it applies that one
  request (tenant, reference, employee, the employee's own user, the rights of its operations) and cleared at once; a
  self-service origin outside it is refused as before. Approved changes never use the grant.
- `approve($request, $actor, $note)`: pending; not while a deciding workflow is in flight (unless the bridge calls it);
  `mayDecide` = active, holds every `approver_permissions` right, the employee in scope, never the requester or the
  employee; applies all operations in one transaction in the approver's name (normal authorisation), then approved,
  purged, `profile.change_approved`. A refusal by an action leaves the request pending. `reject` needs a note
  (`profile.change_rejected`); `withdraw` (the requester; cancels a run in flight); `revise` after a send-back replaces the
  operations and resubmits through `RequestReturns::resubmit` (the return on the request, or on its workflow run).
- Events `profile.change_requested / _approved / _rejected` (references and section only) are in `notifications.events`.

**Approvals.** `App\Domain\Experience\Sources\ProfileChangeApprovalSource` (tagged; kind "Profile change";
`SupportsSendBack`): pending requests not in a deciding run, each to users who pass `mayDecide`; item = section ·
reference, the reason, impact, high risk for bank / identifiers / legal identity, earlier → new (`changesUsing`); approve /
reject through `ProfileChanges`. A workflow's approval card gets the same earlier → new (`ApprovalCenter::workflowTasks`).
`App\Domain\People\Listeners\ProfileChangeWorkflowBridge` (P5): approved / rejected in the name of the last completer
(`viaWorkflow`); a last approver without the actions' rights (a line manager) leaves it pending for the Approval Center.
Subject type "Profile change" and trigger "Profile change requested by the employee (My profile)" in
`peopleos.workflows`. Policy `ProfileChangeRequestPolicy` (view: own, or `employee.update` in scope; nothing else).

**Request registry (RQ-1 / RQ-2).** `ProfileChangeRequestType` (`profile_change`, module "Profile"): offered while at
least one section is editable for the person and no lock holds it; form = My profile; history = their requests (My
requests, the request page's trail via `RequestTimeline`, "waiting with"); approval route: raised event
`profile.change_requested`, built-in approvers "HR … when the section needs approval; otherwise at once", approver rights
`employee.update` / `employee.sensitive.update`, kind `profile_change`.

**My profile (`MyProfile`).** Each tab lists the parts the person may change ("Change your details"): "Edit" opens the
section form (`App\Filament\Support\ProfileEditForms`: filled with the record as it is; repeaters for entries, not
deletable; "Same as present"; confirm account number; reason; supporting document — type, file, expiry, comment); a part
with a request waiting shows "Change pending since … with …" (RQ-2 waiting labels), Details, Withdraw; sent back, the note,
"Edit" (correct and resubmit) and "Resubmit as it is". History: "Profile change requests" (request, profile type, status,
on, last decided by, pending with, Details — masked earlier / new, the reason and the requester's trail) and "My changes"
rows name their request ("PCR-… · requested … by … · approved … by …" or "applied at once"; `ChangeIntelligence`
passes the audit's approval reference). Skills shows "Skills you declared" (`person_skills`). Request ids from the browser
resolve only within the person's own record.

**Settings (Setup → Core HR, group "Profile changes").** `profile.self_edit.sections` (matrix rows ×
"Employee may change it" / "Needs approval"; default each row unticked / ticked), `profile.self_edit.legal_identity`
(off), `profile.self_edit.lock_during_bgv` (off). No new permission (no `peopleos:sync-permissions` needed), no queued job,
no command.

**Tests.** `tests/Feature/HroneParity/ProfileSelfEditTest.php` (defaults: nothing editable, no request type, no Edit;
applied at once through the People action with reason and reference on the audit and no values on the request's own
audit; approval in the Approval Center with earlier → new, never one's own, applied in the approver's name; one open
request per section, withdraw, reject with a note, never deleted; bank always with approval, confirmation, linked
document with the uploader comment, rights per operation, masking by `employee.sensitive.view`; request lock and BGV lock;
CA-6 matrix and own record only; legal identity off, then approval + document; education / experience / skills / custom
fields through the new actions, foreign-tenant skills refused; workflow deciding in the last approver's name and a
manager's approval falling back to HR; send back, correct and resubmit, the requester's trail; RQ-1 type and My requests;
no forged grant; tenant isolation; the My profile page; classification and encryption at rest).

Not built here: the reimbursement account (22.2.7 #6, above), health insurance (MS-5), photo approval (MS-7), static page
layouts (WF-09), removing entries by self-service (HR keeps removals), HR raising a profile change on someone's behalf
through this path (HR edits the 360 or uses the service desk as before).

## CA-9 / SV-7 Access rights: data-access groups, rule-based roles, page, widget, calendar and geo access

Matrices: `docs/hrone-parity/setup/01-core-hr-access-integration.md` "## 05 Access rights" §5.1, §5.2, §5.4, §5.5 (settings
and lists only), §5.6 and package CA-9; `docs/hrone-parity/setup/13-setup-reverification.md` SV-7 (AU-1, AR-1, AR-3, AR-4)
and AR-5; `docs/hrone-parity/modules/22-my-space-self-profile.md` §22.1 #5. The rows closed are marked "→ built (CA-9)" /
"(SV-7)" there. §5.3 CXO access is CA-10. Every default reproduces the earlier behaviour: no group, no rule, no My space
list on a role and the default settings leave every user, role assignment, query, Home and calendar exactly as it was
(tests compare a snapshot taken before configuring). Everything new only narrows: nothing here can show a person a record,
a page or a card they could not reach before.

**Data-access groups (CA-9, §5.2).** `access_scope_groups` (name, `module` = a key of `peopleos.access.modules`, `basis`
organisation | employees, `reportees` none | direct | all, `include_sub_departments`, status), `access_scope_group_users`,
`access_scope_group_rules` (dimension = `UserAccessScope::DIMENSIONS` or `employee`, scope id); models
`App\Domain\Identity\Models\AccessScopeGroup*` (tenant-scoped, audited). Read by `AccessScopes` as extra layers:
- A member of active groups of a module sees, for that module, only themselves and the people their groups reach
  (several groups of a module add up), AND whatever their own scope rows already limit — so a group can never reach past
  the member's own scope, and no group = exactly the Phase 0.2 behaviour (`employeeKeys` keeps the old sub-select).
- Modules (`peopleos.access.modules`, by domain namespace): `workforce` (Employment, People, Organisation, Documents, Exit,
  Onboarding, Lifecycle, Workforce, Letters, Alumni, Bgv), `time_office`, `payroll`, `performance`, `engage`, `expense`,
  `asset`. A Workforce group narrows everything about people (the `Employee` model and every employee-linked record of
  every module, so no module shows a record whose employee is hidden); another module's group narrows only the
  employee-linked records of that module's domains, on top. Domains in no module (helpdesk, analytics …) follow Workforce
  groups only; organisation units and multi-dimension records (positions, plans) are not affected by groups.
- `AccessScopes::moduleFor($model)`, `layers($user, $module)`, `isScoped($user, $module = null)`, `employeeKeys($user,
  $module = null)`, `constrainEmployees(…, $module)`, `constrainByEmployee(…, $module)` (defaults to the model's module),
  `allows()` (uses the record's module), `allowsEmployeeId(…, $module)`; primed answers (`primeEmployeeIds`) are keyed by
  the module that shaped them. The `AccessScope` global scope passes the model's module. Callers that pass no module get
  the Workforce layer (a narrowing, never a widening).
- Fail closed: a membership or group that is malformed — unknown module, basis, reportees or status, a rule of an unknown
  dimension or a bad id, an employee rule in an organisation group (or the reverse), another tenant's membership or group —
  closes every layer: the member sees nobody at all, not even themselves. An unknown dimension in a user's own scope rows
  now reaches nobody (it used to throw an SQL error). `AccessScopeGroups::problems()` shows it on the group.
- Cache: the scope rows and groups are read once per request (under bypass, filtered to the user's own tenant) and
  forgotten on `RequestHandled` and `JobProcessing` (ParityServiceProvider) and after every change.
- Written only through `App\Domain\Identity\Services\AccessScopeGroups` (`user.assign_roles`, reason, every row audited,
  values and employees the author can see, members of this organisation only). Screen: Access → **Data access groups**
  (`AccessScopeGroupResource`: group view with module / status filters and a "Check" column; Group → Data the group reaches
  → Members wizard); employee view = the user form lists the person's groups. Policy `AccessScopeGroupPolicy` (`user.view`
  / `user.assign_roles`).

**Wider scopes (SV-7, AR-3 / AR-4).** `UserAccessScope::DIMENSIONS` adds `designation`, `grade`, `level` (region waits for
CA-7); the user form offers them and now also divisions and teams (saving the form used to drop rows of those two). Groups
may reach the member's reportees (`direct` = reporting to them today, any type; `all` = every level, at most
`peopleos.access.max_depth`) and a department's sub-departments (`parent_department_id`, same depth).

**Rule-based role membership (SV-7, AU-1).** `role_membership_rules` (role, name, `conditions` in the policy rule format —
`peopleos.rules.fields`: company, department, parent department, designation, grade, level, location, employment type,
employee … — `match` all | any, status, created / updated by, `last_synced_at`) on the Role page (**Membership rules**
relation manager: Rule → Who → Review wizard with the number of people it matches today, switch on / off, delete).
- `RoleMembershipSync` is the only writer of `role_user` rows tagged `source = rule` (new column, default `manual` for
  every existing and hand-given row; `membership_rule_id`). It grants the role organisation-wide to every employed person
  (`LifecycleState::isEmployed`) with an active or invited account of the tenant who matches today, unless they already
  hold it organisation-wide by hand; it removes its own grant when they stop matching, leave, or their account is
  suspended, and when its rule is switched off, deleted, malformed or for a locked role; two rules of a role hand a grant
  over. Each change is audited `ROLE_CHANGED` on the person with `via = membership_rule`, the rule id and name, inside one
  bulk operation per run; runs are serialised per tenant (cache lock) and ignore access scopes (every employee counts).
- Triggers: `QueueRoleMembershipSync` on `EmployeeLifecycleChanged` and the employment events created / transferred /
  promoted / department / designation / location / company changed / rehired queues `SyncRoleMemberships` (tries 3,
  timeout 600, backoff 30/120, tenant-bound) for that employee after the change commits — only for tenants with a rule;
  `peopleos:access:role-rules` (daily 00:45) catches up everyone. A rule save, switch or delete applies to its role at once.
- Ceiling (`RoleGrants::canAssignByRule`): never a locked role (`peopleos.access.rule_locked_roles`: Tenant Super Admin,
  Account Owner) nor a role carrying a platform permission (`tenant.*`), whoever writes the rule — and checked again at
  every run; below the unrestricted (platform operator, Tenant Super Admin, console) only a role whose every permission
  the author holds (stricter than a hand grant: no system-role exception). Checked when a rule is written, edited or
  switched on; switching off or deleting needs only `user.assign_roles`.
- Conditions are validated (`RoleMembershipRules::problems`): at least one condition, known fields and operators, values
  present and numeric ids for model fields, ids the author can see. A stored rule that is malformed matches nobody (the
  rule engine would treat an unknown field with "is none of" as matching everyone).
- Hand-given grants are never touched: `RoleMembers::remove` removes only hand-given rows and refuses a rule-only holder
  ("change the rule"); `RoleMembers::add` of a role held by rule turns the rule's row into a hand-given one; the user form
  shows and saves hand-given roles only (`RoleMembers::syncManual`; the RoleGrants ceiling counts a rule-held role as new)
  and lists the roles held by rule. The Members list shows "By hand" / "Rule: …".
- AR-1: role template `external` (External user: `task.view`, `task.act`), seeded as a system role for every tenant and
  held by nobody until partner sign-in is decided (PRV-9 / IP-04). `ProvisionTenantAction::seedSystemRoles()` now skips a
  role the tenant made itself that carries a template's slug (e.g. its own "external" role): a deploy never gives
  template permissions to a tenant's role.

**Page access per My space item (§22.1 #5, §5.1).** `roles.myspace_hidden_items` (json, null = nothing): the Role form's
"Pages in My space" section ticks the self pages left out for the role's holders. `MySpace::hiddenByRoles()`: roles add up
as permissions do — an item is left out only when every active role of the person leaves it out (no role: nothing); a
stored value that is not a list of keys leaves everything out for that role; unknown keys are ignored; a failure to read
the roles hides every item. Applies to My space, its favourites in the avatar menu and Command Center rows. The pages
themselves keep following the role's permission keys (HROne's "View" grant per page ≈ the permission keys in the role
editor; owner decision D-CA9-1 below). Roles list column "Left out of My space". `roles.status` and the status filter
(§5.1) were built by HS-P14.

**Widget access (§5.6).** Settings `home.hidden_blocks` (multiselect of `HomeComposer::blockOptions()`, HROne widget names
in brackets; default none) and `home.block_order` (comma-separated card keys, pattern-checked; default blank = built-in
order; AR-5: optional). `HomeComposer::for` merges the organisation's hidden cards with the person's own (hiding
"changes" also skips the change feed) and `HomeComposer::arrange()` drops them from MAIN / SIDE and moves the named cards
first (never adds one). A stored hidden list that is not a list switches every card off.

**Calendar access (§5.4).** `App\Filament\Pages\TeamCalendar` (Me → Team calendar, people section / "My team" area;
`Experience\Services\TeamCalendar`): audiences "My team" (a manager's direct reports today) and "Organisation" (the HR lens:
their access scope); one month at a time, chips per event kind. Kinds (`peopleos.calendar.events`): birthdays, work and
marriage anniversaries (through `Birthdays` / `Anniversaries`, their visibility and sharing rules), confirmation due,
joiners, last working day, document expiry (passport, visa …), leave (type, never the reason), overtime, comp-off,
one-on-ones, improvement plan ends, initiative due dates, travel plans, expense claims (mileage, never amounts), badges
received — each read under the viewer's access scope and kept only when the viewer may open the record (its policy), at
most 500 per kind, a failing kind left out (reported). Settings `calendar.manager_events` / `calendar.hr_events`
(default every kind; a stored value that is not a list shows nothing). The leave calendar, birthdays page and the
employee's own attendance calendar are unchanged.

**Geo access (§5.5, lists only).** `App\Filament\Pages\GeoDashboard` (Attendance → Geo dashboard;
`Experience\Services\GeoDashboard`): today's check-ins with a location (time, in / out, matched work location, coordinates
to 5 decimals, geo status; `attendance.view` and the punch policy) and the headcount by location (employed people's
position today, the location's work-location pin; `employee.view`), for a manager's direct reports or HR's access scope.
Settings `geo_dashboard.checkins_visible_to` (manager | hr, default none: punch coordinates are shown on no screen today,
so nobody sees them until the organisation chooses — HROne's default is HR, owner decision PRV-14) and
`geo_dashboard.headcount_visible_to` (default `['hr']`: people HR already sees with their location; work-location pins only
with `attendance.view`); a stored value that is not a list shows nothing. Every opening is audited (`VIEW`, module attendance, the panels shown,
never coordinates). The map waits for the map component (CA-8); no new table (punch locations are already classified).

**Setup centre.** Access rights module: the six settings above (groups Widget access, Calendar access, Geo access;
`options_from` `home_blocks`, `calendar_events`, `geo_audiences`) and links to Data access groups, Team calendar and Geo
dashboard. Because the module now has settings, holders of `settings.view` see it (its settings read-only, as in every
other module; saving needs `settings.update`); its screens still need their own permissions (`CoreHrSetupTest` updated).

**Data, permissions, commands.** Migration `2026_12_13_200001_create_access_rights_tables` (the three group tables,
`role_membership_rules`, `role_user.source` + `membership_rule_id`, `roles.myspace_hidden_items`; long index / FK names set
explicitly). `DataInventory` identity: `access_scope_groups`, `access_scope_group_users`, `access_scope_group_rules`,
`role_membership_rules`. No new permission key (groups and rules use `user.view` / `user.assign_roles` / `role.view`, My
space pages `role.update`); a new role template `external` (seeded by provisioning and by `peopleos:sync-permissions` on
deploy, held by nobody). New command `peopleos:access:role-rules` (daily 00:45), job `SyncRoleMemberships`.

**Tests.** `tests/Feature/HroneParity/AccessRightsTest.php` (a snapshot before configuring equals the snapshot after
configuring for other people; module groups narrow only their module and never past the member's scope; Workforce groups
narrow everything and add up, inactive groups do nothing, deleting them restores the snapshot; direct / all-level
reportees and sub-departments; designation / grade / level scopes; every malformed group or membership — unknown module,
basis, reportees, status, dimension, bad id, mixed basis, another tenant's membership or group — closes everything; an
unknown own-scope dimension reaches nobody; primed answers per module; the service's refusals, other tenants' people and
units, a scoped author, audit; the screens; My space by role with roles adding up and a malformed list; Home cards off /
ordered / malformed / Setup validation; Team calendar per audience, kinds, malformed setting, tenant scope, access;
Geo dashboard: check-ins to nobody by default, headcount to HR, managers when chosen, audit, malformed setting), `RoleMembershipRulesTest.php` (no rules = no
change and nothing queued; grants and removals with audit, moves, joiners, exits and suspended accounts, idempotent runs;
hand-given grants untouched, conversion, refusal, user-form save; the ceiling for authors, system roles, locked roles and
forged rules; malformed rules refused and matching nobody when stored; hand-over between rules and deletion; tenant
isolation; the external role; the role page lists).

Owner decisions (defaults applied; `HROne-Owner-Decisions.md` PRV-10 … PRV-14): D-CA9-1 whether page access per My space item should also block the page itself
(applied: it leaves the item out of My space, favourites and search; the page follows the permission keys); D-CA9-2
whether a Workforce data-access group should narrow every module (applied: yes, so no module shows a record whose employee
is hidden) or only Workforce screens; D-CA9-3 whether Account Owner may be granted by rule (applied: no, locked with Tenant
Super Admin); D-CA9-4 whether a rule may name units outside its author's access scope (applied: no for ids the author
cannot see; the people the rule reaches are not limited to the author's scope, as for a hand grant); D-CA9-5 whether HR
sees located check-ins on the Geo dashboard by default (applied: nobody until chosen, as no screen shows coordinates today).

Not built here: CXO access views (§5.3, CA-10); the geo map, check-in trail and per-location Wi-Fi bank (CA-8); region as a
scope dimension (CA-7); calendar retirement dates (WF-08), working hours and shift (TX-5 / MS-11), scheduled engage events
(EN-06); recruitment rows (N-A); business-partner sign-in for the external role (PRV-9 / IP-04).

**Merge note (2026-10-30).** Confirmed on the whole suite (2,552 tests). Access-scope answers are now forgotten after
every request and queued job, so a scoped request pays its own scope-row and data-access-group lookups (two queries per
request, constant however many rows a list shows). `tests/Feature/ServiceDesk/ServiceDeskScaleTest.php` now starts each
measurement cold (`AccessScopes::forget()`), because raising requests as another user had warmed the agent's answers for
one measurement only.

## CA-10 Shared CXO views

Matrices: `docs/hrone-parity/setup/01-core-hr-access-integration.md` §5.3 (every row) and build package 10;
`docs/hrone-parity/modules/21-analytics-reports-projects.md` §21.1.0 #1, §21.1.1 #1, 2, 4–8, the view editors of §21.1.3 #11,
§21.1.4 #10, §21.1.6 #13, Payroll #11, HR Ops #5 and Custom dashboards #2 (in part), #9 — marked "→ built (CA-10 …)". A
shared view is a dashboard (`dashboards`) with view fields; every one of them is null on a dashboard made before, which keeps
it exactly as it was (role audience, live figures, never e-mailed; tests compare a render before and after a sliced view
rendered in between). Presets are offered ("Add a CXO view"), never seeded. Nothing here grants a figure: a view only
decides where it is listed and which part of the organisation it shows; every widget still follows the viewer's own
permissions and access scope.

**Data.** Migration `2026_12_16_200001_add_shared_view_fields_to_dashboards`: `preset` (the HROne dashboard the view was
made from), `applicability` (json `{dimension: [ids]}`; the form offers one dimension, as HROne's `filterType`),
`viewer_user_ids`, `email_user_ids`, `email_frequency` / `email_day_of_week` / `email_day_of_month` / `email_time` /
`email_next_run_at` / `email_last_run_at`, `refresh_day` (1–28) and index `dashboards_tenant_email_next_idx`. Status gains
`inactive` (HROne's Inactivate). `DataInventory`: columns only on `dashboards` (category analytics; user ids; exported and
deleted with their rows; none secret). No new permission key (views are made by `analytics.manage`, opened with
`analytics.view`), no new job.

**Who it is listed for (`Dashboard::isForUser`).** Analytics managers, the owner, its roles and its named viewers (HROne
"Share view with"). No roles and no named people = everyone with `analytics.view`, as before; named people alone = only them;
a stored list that is not a list of ids names nobody and still restricts the view (fail closed). Being named grants nothing:
`DashboardPolicy::view` still needs `analytics.view`, and each figure its own right.

**The slice — a constraint, never a grant.** `AccessScopes::withinSlice($slice, $callback)` adds the view's applicability as
one more layer for the signed-in user while the view renders: only the employees whose position today matches every
dimension of the slice (no "themselves", no reportees), AND-ed with the user's own scope rows and their CA-9 data-access
groups, in every module (`layers()`; `isScoped()` is true while a slice is active; primed answers carry the slice in their
key). Slices nest and end with the callback (try / finally; `forget()` never clears one). A malformed slice reaches nobody;
while a slice is active, the `AccessScope` global scope shows nothing to a query without a signed-in tenant user (no user,
platform operator). Organisation units and multi-dimension records are not narrowed by it.
- `Dashboards::render()` renders as the viewer (`auth()->setUser` like scheduled reports, the signed-in user restored), then
  inside the slice. A view whose slice is malformed, or a slice on a whole-organisation preset (`Dashboards::ENTERPRISE_ONLY`:
  `hr_ops`, `happiness` — the form locks the field, `DashboardViews::normalise` refuses it), shows an error on every widget.
- In a sliced view a figure the slice cannot narrow is not shown ("Not available in a view limited to part of the
  organisation"): KPIs outside `WorkforceMetrics::SLICEABLE` (pending approvals and the new approval metrics — workflow tasks;
  open grievances — anonymous cases), trends outside `SLICEABLE_SERIES`, the alerts widget (the viewer's own work), and
  report widgets whose dataset `Dashboards::followsSlice()` rejects (its rows must be employees or employee-linked records
  under the `AccessScope` employee path: users, organisation units, multi-dimension records and the organisation-wide
  happiness index are not).

**Payroll (refresh day, read contract).** New contract `Payroll\Contracts\PayrollSummaryReader` (`Payroll\Services\
PayrollSummary`, bound in `ParityServiceProvider`): `latestMonth(?finalizedBefore)` and `byMonth(from, to, ?finalizedBefore)`
over finalized / paid runs only, summing the payroll entries the signed-in user may see (so access scope and slice apply —
never run totals), runs finalized at or after the moment left out. In a view, `people_cost` / `cost_per_head`, the
`people_cost` trend and the new `payroll` widget (HROne "Effective CTC": gross, net paid, deductions, employer cost, people
paid) read it; `Dashboard::payrollAsOf()` = the start of the latest refresh day at or before today (blank = live; outside
1–28 = malformed: payroll widgets show an error). Report widgets on datasets that come from payroll runs
(`Dataset::payrollRunPath()`: `payroll`, `salary_payments` = `run`; `payroll_components`, `payslips` = `entry.run`) are
frozen too (`ReportRunner::run/execute(..., $payrollAsOf)`); CTC, loans and variable pay stay live. Viewers without
`payroll.view` (e.g. `compensation.analytics`) see a payroll figure only when at least `compensation.analytics_min_group`
people were paid in the view. Plain dashboards keep `WorkforceMetrics::lastRun()` (latest finalized run's totals).

**E-mail ("share by email").** `Analytics\Services\DashboardDeliveries`: a notice with the view's name and link only — never
a figure or an attachment — through `Notifier` (in-app at once; e-mail by the tenant-bound `DeliverNotification` job when
Setup → Access rights → CXO access `analytics.views.email` is on, default on). Recipients who could not open the view
(inactive, platform operator, no `analytics.view`, not listed) are skipped; each send is audited (`EXPORT`, module
analytics, metadata `channel = view_notice`, recipients, skipped, e-mail, scheduled). Cadence = the report-schedule rule
(`ReportSchedule::nextOccurrence()`, extracted from `computeNextRun()` unchanged) on `email_next_run_at`, recomputed when the
cadence, recipients or status change (inactive = none); `runDue()` claims each slot with a conditional update (overlapping
runs never send twice) and runs from `peopleos:reports:run-due` (hourly) after the report schedules; "Send now" on the list
(`analytics.manage`).

**Presets (`AnalyticsDefaults::PRESETS`, `addPreset()`, `presetWidgets()`).** `movement`, `hr_ops` (whole organisation),
`payroll`, `helpdesk`, `engage`, `nine_box`, `performance`, `survey`, `pulse`, `time_office`, `happiness` (whole
organisation; over HP-9f's suppressed `happiness_index`), `expense` (EX-G's figures). ROI waits for AN-06 / AN-11 (no
automation-ROI data); recruitment is N-A. A preset view starts shared with its author only (named viewer; owner decision
ANA-9); its reports are added once from the report library as shared reports. New metrics `approvals_on_time` and
`approval_turnaround_days` (workflow tasks decided in the last 30 days; `workflow.view`; whole organisation only). New
widget types (`peopleos.analytics.widget_types`): `payroll`; `nine_box` (counts per box of the latest active or closed cycle
from `NineBox::grid` for the viewer — never names, whatever their view mode; boxes below `performance.analytics_min_group`
hidden with complementary suppression, nothing below it in all); `survey` (`survey_kind` survey | pulse: the latest closed
version the viewer reads as HR through `EngagementAnalytics::access` / `results` — respondents, response rate and
per-question averages, nothing below its threshold, no comments; a slice never filters anonymous results, so a survey whose
population reaches outside the slice ∩ the viewer's scope is not shown). Report widgets may carry `suppress`
(`Dashboards::suppressSmallGroups`: grouped counts below `peopleos.analytics.min_group` folded into one row without figures,
complementary suppression, nothing below it in all) — the performance preset's rating distribution does.

**Screens.** Dashboard builder (`DashboardResource`): steps Dashboard → Shared view (preset, "Applicable on" dimension and
values the author can see, "Share view with", payroll refresh day) → E-mail (recipients, Never / daily / weekly / monthly,
day, time) → Widgets (survey kind, suppress) → Review; checked by `DashboardViews::normalise` (one known dimension, values the
author can see, people of this organisation, cadence fields, refresh day 1–28). List: View and "Applicable on" columns,
named-people audience, e-mail; Clone (a draft copy, never the default, nobody but analytics managers sees it until switched
on), Inactivate / Switch on (the default dashboard stays active), Send now; header "Add a CXO view". `DashboardViewer`: the
switcher lists the views a person may open; a view says under its title what it is applicable on, when its payroll figures
moved and that figures follow the viewer's access; new widget blocks for payroll, 9-box and survey. Setup → Access rights:
setting `analytics.views.email` (group CXO access) and a link "CXO views (dashboard builder)".

**Behaviour change (security).** A trend widget of a protected figure (`people_cost`) now needs that figure's right
(`payroll.view` or `compensation.analytics`) on every dashboard, like its KPI; before, any dashboard viewer saw the trend.
`Dashboards::render()` now also runs as the viewer passed in (it used to compute under whoever was signed in); the page
always passed the signed-in user, so nothing changes there.

**Tests.** `tests/Feature/HroneParity/CxoViewsTest.php` (defaults: no view fields, nothing seeded, the same render before and
after a sliced view, role audience as before; named viewers besides roles and alone, no figure granted, a named person
without analytics.view refused, malformed list fails closed; the slice narrows KPIs and report widgets, never past a scoped
viewer's access, hides what it cannot narrow, malformed and whole-organisation-only slices show nothing, no-user queries see
nothing while slicing and everything is back afterwards; render as the viewer; payroll from finalized entries only (a
calculated run ignored), as of the refresh day (before / after the day, next month), inside the slice, suppressed for
aggregate viewers, malformed refresh day, the people-cost trend right; e-mail cadence, claim, recipients who may open it,
link only, audit, the mail setting, Send now right, inactive views, the scheduler command; every preset renders, ROI
refused, author-only sharing, second copies; approval turnaround and on-time share for the HR Ops view; 9-box and grouped-count complementary suppression; survey / pulse above the
threshold only, only for a population inside the view; the builder steps, validation (incl. values outside a scoped author's access), clone, inactivate, send now, the
viewer subheading, access; the Setup entry).

Owner decisions (defaults applied; `HROne-Owner-Decisions.md` ANA-8 … ANA-10): D-CA10-1 e-mails carry the link only, not a
per-recipient summary; D-CA10-2 preset views start shared with their author; D-CA10-3 the happiness index view is whole
organisation only.

Not built here: the ROI view (AN-06 / AN-11); an ad-hoc dashboard filter with pasted employee codes (§21.1.0 #2, AN-1's
`DashboardFilter`); sub-departments in a slice (the slice matches the chosen units exactly); several dimensions on the form
(the engine ANDs several; the form offers one, as HROne); HR Ops targets per task type (AN-14) and inbox metrics (IP-03);
per-widget export / drill-down (AN-2).

## RQ-8 Unified inbox

Matrix: `docs/hrone-parity/modules/20-requests-inbox-team.md` (package RQ-8 and its re-check extensions; the rows it closes
are marked "→ built (RQ-8)" there, and the rows of matrices 22, 24, 25, 26 and 27 that point at it). Every default
reproduces the earlier behaviour: Approvals, the drawer, the Task inbox and My HR → Tasks list and decide exactly as before;
nobody sees anything new; the only visible change on Approvals is more context on a leave-cancellation card (its reason and
the balance after cancelling, R.3.3 #1). The new page is additive; `task.ignore` reaches only the two HR templates that
already hold `task.*`.

**Read model.** `App\Domain\Experience\Services\UnifiedInbox` (request-scoped, `ParityServiceProvider`, forgotten on
`RequestHandled`) reads, as the person: `ApprovalCenter::pending()` (every built-in kind and tagged `ApprovalSource`,
delegated items and the archive rule included), `ExperienceTasks::for()` without the `workflow` domain (the Approval Center
already lists workflow steps) and the onboarding tasks they own (the task inbox's "Onboarding tasks" tab, for holders of
`task.view` / `task.view_all`). Each becomes an `Experience\Support\InboxEntry` (source decision / task / onboarding, facet,
title, subject, requested / due, from, url, the `ApprovalItem` for decisions, `system`, `overallStatus`, filter facts in
`meta`). A row exists only where one of those reads gives it; workflow subjects are loaded in one query per type (the run is
in the person's queue, as `RequestTimeline::requestOf` reads it), and the employees rows are about through the normal
`Employee` query, so access scopes apply — a code or unit outside the person's scope is never read.
- **Facets (§20.2.1 #4, #8).** A decision is grouped by the RQ-1 request type of its record (`RequestRegistry::forRecord`;
  trip and expense-report parts by their parent's type), except cancellations, exceptional expenses, loan guarantees and leave
  donations (their own Approval Center kind); a workflow step by the type of its run's subject, "Forms" for configuration-form
  submissions (with the run's overall status, R.5 #2), else the workflow's name; own tasks by kind (Policy acknowledgement,
  Announcement, Helpdesk — with tickets' workflow steps —, 360 feedback, …); onboarding tasks as "Onboarding tasks".
- **Views (§20.2.1 #9, #12).** `UnifiedInbox::SHOWS`: All, Unread (items not opened yet: `ExperiencePreferences`
  `inbox_read`, at most 1000 ids, presentation only), Overdue, Due today, Due tomorrow, Missed by me (workflow steps assigned
  to the person or their role closed without their response in the last 30 days — `skipped` with no decision, i.e. decided by
  the other approvers of a parallel / majority step), Relieved employees (rows about exited or alumni employees), Archive
  (`ApprovalCenter::archived`), Not relevant (below).
- **Filters (§20.2.1 #10, #11; R.3.2 #1).** `filter()`: facet, keyword over the row's text (title, person, type, reason,
  requester, facts, code, trip), employee code or name, requested from / until, leave type (`leave_type_id` of the request or
  the run's subject), ticket category (service-desk tasks carry it in the new optional `ExperienceTask::$meta`; tickets'
  workflow steps from the subject), travel request (trip number or purpose), organisation unit (company, business unit,
  division, department, location, team of the employee's current position). `filterOptions()` offers leave type, ticket
  category and travel only when the rows of the chosen facet have them (HROne's per-category filter set).
- **Due labels (§20.2.1 #14).** `InboxEntry::dueLabel()`: Due in N days / Due today / Due tomorrow / Overdue (from each item's
  own due time — unchanged Approval Center rules), "Missed to respond", "Not relevant".
- **Peek and More (§20.2.1 #21–25).** `peek()` = `PersonPeek` (PeopleVisibility, access scopes; status and profile link only
  when the Employee 360 opens) plus `DirectorySections` (CA-6 social-profile sections); null outside the viewer's visibility.
  `more()` = links to the existing forms, each offered only when `ScreenAccess` lets the viewer open the screen and the
  form's own rule holds, never about oneself: Give a badge (`RecognitionResource ?action=giveBadge`, colleague picked,
  `recognition.give`), Give reward points (`?action=givePoints`, colleague picked through the new optional
  `actionArguments[employee]` of `RewardPointActions::give`; only while points are on), Schedule a one-on-one and Create an
  initiative (the resources' create actions, `canCreate()`).

**Page.** `App\Filament\Pages\Inbox` (Workflows → "Unified inbox", slug `inbox`, in the My work rail after My work;
`ExperienceNavigation::MODULE_SECTION`), opens for holders of `task.view` / `task.view_all` or whoever opens Approvals
(`UnifiedInbox::opensFor`). Insight panel; Messages select and quick-filter chips (Archive shown while archiving is in use,
Not relevant for holders of `task.ignore` or while something is listed); facets with counts and "Search by name"; search and
filter panel ("Clear all"); list rows with the type, raised time, due label and "new"; the open item beside the list (≥ 1280
px) or alone with "← List", Previous / Next and "N of M" (`focus`, the phone layout always); `?item=` deep link. The item
shows From, To (or "on behalf of" for delegated items), raised, due, overall status, More, Not relevant, then the existing
approval card (`x-pos.approval-card`: decisions, Archive, "Workflow and policy", discussion) or, for own tasks and missed
steps, "Open"; then the peek. Every action re-reads the item on the server and goes where Approvals sends it:
`ApprovalDecisions::decide` / `approveMany`, `InboxArchives::archive` / `restore`, `TaskComments::postOnItem`,
`RequestTimeline::forItem`. Tick-and-archive (§20.2.1 #33): ticked items that offer "Move to archive" or have waited past the
archive period (`archiveSelected`, at most 100); "Approve selected" for bulk-approval types as on Approvals.

**Not relevant (§20.2.1 #27, R.3.1 #2).** `App\Domain\Workflow\Services\SystemTasks`: a system task is a pending workflow
to-do (never an approval) of a run started by an event trigger (`trigger` in the context, not `manual`) or by the system (no
initiator). `ignore()` needs `task.ignore` and `act` on the task (`task.act` + assignee or role), a reason (1–255
characters), and is checked again under a row lock; `WorkflowEngine::markNotRelevant()` closes it as `skipped` with decision
`not_relevant` and the reason, moves the run on exactly as "Mark done" does (step outcome `completed`, `last_decision`
"completed", `nodes.<id>.not_relevant`), logs `task.not_relevant` and audits `TASK_NOT_RELEVANT`. `restore()` (same people:
`task.ignore` and the task was theirs or their role's) → `WorkflowEngine::restoreNotRelevant()` puts it back as a pending
to-do (refused once the run was cancelled or failed; the run does not go back), logs `task.restored`, audits `TASK_RESTORED`
with the earlier reason. Nothing is deleted. Delegated items are not offered (the delegate decides approvals, not system
tasks). The requester's trail shows "Marked not relevant" / "Back in the inbox" without the reason. Onboarding tasks keep
their own "Skip" (`onboarding.manage`).

**Insight (§20.2.1 #7).** `App\Domain\Experience\Services\InboxInsight::for(viewer, person, days)` (7 / 30 / 90, default 30):
decided = workflow steps the person approved, rejected, finished, sent back or closed as not relevant (their own with no
delegate, or as the delegate) + Approval Center decisions on domain requests logged by `ApprovalDecisions` in the new
`inbox_decisions` (who decided — the delegate when on behalf —, on whose behalf, item, decision, the due time the item showed,
decided at; written after the owning service decided, never blocking it; workflow steps are not logged there); on time =
decided by the due time, delayed = after it, over the decisions that had one. Decisions taken on a module's own screen are not
counted (said on the panel). Another person's insight only for holders of `task.view_all` or `audit.view`, and only for
colleagues whose employee record is in their access scope (`people()`); anyone else gets their own.

**Approval Center fix.** `ApprovalCenter::workflowTasks` now loads each run's starter with the runs (`instance.starter`). Outside
production (strict models) a person with steps of two or more runs lost every workflow step from the queue: reading the
starter lazily threw and the source was skipped. Production behaviour is unchanged.

**Leave cancellation item (R.3.3 #1).** `ApprovalCenter` leave-cancellation items carry the fact "Why cancel: …"
(`cancel_reason`) and "<type> balance after cancelling" before → after (closing balance + the leave's days).

**Data, permissions.** Migration `2026_12_15_200001_create_inbox_decisions` (`inbox_decisions`, `DataInventory` workflows;
`InboxDecision` is a documented exception to automatic auditing: a derived row of decisions the owning services audit). New
permission `task.ignore` in the `task` prefix — reaches Tenant HR Admin and HR Manager through their existing `task.*` (both
already hold `task.act`; no other template changed) → run `php artisan peopleos:sync-permissions`. New audit actions
`TASK_NOT_RELEVANT`, `TASK_RESTORED`. New preference key `inbox_read`. No setting, no queued job, no command.

**Tests.** `tests/Feature/HroneParity/UnifiedInboxTest.php` (exactly the Approval Center's items and one's own tasks, page
access; facets by request type, "Forms" with overall status, workflow names, facet choice; views unread / due today /
tomorrow / overdue / relieved; due labels; filters by employee, range, leave type, unit, ticket category, keyword and the
facet-specific options; open, previous / next, focus, read marks; decisions through the owning services with the insight log,
Approvals unchanged; tick-and-archive and restore; Not relevant with reason, audit, run moving on, restore and later done;
refusals without `task.ignore`, on approvals, hand-started runs and others' tasks; role templates; Missed by me; Insight
counts, periods and who may see whose; More links by right and never about oneself; the peek within visibility; the
leave-cancellation item; the rail and the unchanged pages).

Not built here: send back to an earlier approver, one-item delegation, add approver and the `skip` escalation (RQ-9 — so
"Missed by me" today lists only steps other approvers decided); new task kinds (RQ-10); HROne's project / poll filters
(blocked); counting decisions taken on module screens in Insight; a server-side keyboard map for the new page (Approvals keeps
J / K / A / R).

Owner decisions (defaults applied): who gets `task.ignore` (applied: the two HR templates that already hold every task right;
HROne grants "Ignore api" per role); which tasks are "system" tasks (applied: workflow to-dos of trigger- or system-started
runs; approvals and hand-started runs never); whether Insight should also count decisions taken on module screens (applied: no
— only where a due time is known: workflow steps and Approval Center decisions); whether audit-right holders may see a
colleague's Insight (applied: yes, within their access scope).

## MS-9 / MS-10 / MS-12 My pay pages, salary YTD, my holidays and the leave month view

Matrix: `docs/hrone-parity/modules/22-my-space-self-profile.md` (packages MS-9, MS-10, MS-12; rows 22.5 #1, #2, #6, #14, #16;
22.6 #3, #6 and in part #1, #7; 22.7.1 #1–#5, #7–#14 and in part #6, #15, #16; 22.11 #1–#4, #6; 22.12 #4 — marked
"→ built (MS-9)" / "(MS-10)" / "(MS-12)"). Every default reproduces the earlier behaviour: My compensation shows exactly what
it showed (no tabs, no new block) until the organisation switches the new parts on; the payslip list and view, leave
balances, the attendance calendar, the time-office form and the holiday calendar form keep their behaviour and only gain
additions (the payslip pages and My day are only touched while the password setting is on). New: the pages My payslips,
My salary YTD and My holidays (Me group, each a My space item), the password-confirmation page, "My month by month" on
Leave balances and the calendar's note for employees.

**Payroll read contract (MS-10).** `PaidPayReader::yearToDate(Employee, '2026-27')` (in `Payroll\Services\PaidPay`, bound in
`ParityServiceProvider` as before) reads the employee's payroll entries whose run is finalized or paid (`PaidPay::FINAL`)
and whose payroll month (the month a run is named for: `payroll_periods.year / month`) falls in the financial year — never
an open run, never a recomputation. It returns the twelve months in the financial year's order
(`peopleos.compliance.financial_year_start_month`), the months processed, rows per line type and code (earnings,
deductions, employer contributions; reimbursements apart) with monthly amounts and totals, gross / deductions / net /
reimbursement totals per month (the entry totals = the payslip's), arrear lines (classification `arrear`, an arrear
adjustment, an attendance arrear: counted in their row's month and listed with their note), per row the component's set-up
(`SalaryComponent`: method, formula, taxable, PF / ESI, prorated, arrear eligible, joining / leaving month, regimes) and per
month what the line recorded (formula, dividing factor, full-month amount, paid days), the statutory schemes from the lines'
recorded basis — PF (wages, base, employee, employer EPF / EPS), VPF, ESI (eligibility wages, wages, employee, employer),
PT (wages, state), LWF (state, employee, employer) — overtime (approved minutes from the entry's attendance input, amounts
of components whose formula reads `overtime_*`), bonus paid (classification `bonus`) and days. Lines hidden from the
payslip (PS-1 `hidden_on_payslip`) are left out, as on the payslip. Thresholds: the PF and ESI wage ceiling of the rule
version the latest month recorded, and the statutory bonus rates, only when that version is VERIFIED, checksum-intact,
without an open notice and carries a positive number (`Compliance\Services\VerifiedThresholds`, the PO-3 test made
reusable); otherwise null and the page says "No verified rule". `latestPaid()` gained `period_end`, `state` (the entry's
establishment state, else its PT state) and `wages_full_month` (the wage lines' full-month amounts) for the verdict below;
existing keys are unchanged. `PaidPay::financialYears($employee)` lists the years with final pay.

**My salary YTD (`Filament\Pages\MySalaryYtd`, slug `my-salary-ytd`, MS-10).** Opens for the signed-in person's own employee
record with the payslip right (`viewAny` on payslips: `payroll.payslip` or `payroll.view`); no employee id anywhere in the
URL; opening it is a sensitive view (`SensitiveAccessAuditor`, scope payroll, purpose `salary-ytd`). Header "name (#code) ·
company · business unit", financial-year select (years with final pay and the current one), tabs Salary (the component ×
Apr–Mar grid grouped Earnings / Deductions / Employer contributions with Gross, Total deductions and Net pay rows; "—" for
months not processed; "SALARY NOT PROCESSED"; "Arrears paid this year"; "How it is calculated" per row — a modal with the
component's set-up and each month's full amount, paid days and dividing factor, and its arrears), Reimbursement (paid
through payroll per month; "REIMBURSEMENT NOT PROCESSED") and Statutory (PF with the verified ceiling and the employee's PF
basis, VPF with the choice, ESI with the verified ceiling, PT, LWF, overtime, bonus with the verified rule's rates), and a
link to My tax certificates when it opens.

**My payslips (`Filament\Pages\MyPayslips`, slug `my-payslips`, MS-9).** Same access. Read model
`Payroll\Services\MyPay` (own record only — `MyPay::isOwn`, same user and tenant; every method answers nothing for anyone
else): financial years with an own payslip plus the current one; the year's own payslips of finalized or paid runs by pay
month; HROne's "Search" over month, month name and number; a card per month "Salary <Mon, YYYY>" with the net and "New"
until viewed, opening the existing payslip view (audited there as before) and "Tax slip" — a modal rendering
`MyPay::taxSlip($payslip)` from the payslip's tax snapshot (tax year and regime, income, exemptions and deductions, tax,
deducted earlier / outside / this month, the rule and its status), audited as `PAYSLIP_ACCESSED` with `part = tax_slip`; a
colleague's payslip id shows nothing and records nothing. Setting `payroll.payslip.password_help` (Setup → Payroll, text,
default blank) shows the organisation's help text; `downloadUrl()` is the BP-14 seam (null until the payslip PDF exists).

**My compensation (MS-9).** `MyCompensation::tabs()`: "Salary structure" / "Salary insights" only when
`compensation.self_service.show_wage_split` or `show_insight` is on (none = the page as before; the structure tab is the
earlier page unchanged). New switches (Setup → Payroll, default off): `compensation.self_service.show_statutory` — PF, ESI,
professional tax (with the state), LWF (state) applicability from the employee's statutory choices and the latest final
entry, the PF wage basis and voluntary PF (`PfOptions`), each scheme's deduction in the latest final payroll and the tax
regime payroll used (never an identifier); `compensation.self_service.show_pay_mode` — salary pay mode (and reimbursement
mode) through `MyProfileSections::bank()`, so the CA-6 "bank" row hides it as on My profile. The insights tab (with the wage
split shown) carries the Code on Wages verdict `MyPay::wageCode()` on the latest final pay: wages (basic and DA) as a share
of earnings against `WAGES.min_wage_share` and full-month wages against `MIN_WAGE.monthly_minimum` of the entry's state
(`PayrollReadiness::RULES`), each `ok` / `attention` / `no_rule` with HROne's wording, thresholds only from verified rule
versions (today: "No verified rule" for both), and the change at each revision when `show_insight` is on.

**Password before pay pages (owner decision PRV-4 / D-MS10 open).** Setting `compensation.self_service.confirm_password`
(Setup → Payroll, default **false** = the pages open directly). On: Laravel's password confirmation —
`App\Http\Middleware\ConfirmPasswordForPay` (extends `RequirePassword`, route middleware `:self` on My compensation, My
payslips and My salary YTD; `:payslips` on `PayslipResource`, where payroll staff with `payroll.view` are not asked) sends
the person to `Filament\Auth\ConfirmPayPassword` (route `filament.admin.auth.confirm-pay-password`, `pay/confirm-password`,
authenticated, throttled; the login password through Filament's current-password rule, five tries a minute; "Go to Home")
which records `session()->passwordConfirmed()` and returns to the page asked for. A confirmation lasts
`PayPasswordGate::TIMEOUT_SECONDS` (15 minutes). The trait `ConfirmsPayPassword` covers the pages' later Livewire requests
(nothing is rendered; the browser goes to the confirmation page). While a confirmation is due My day shows its latest
payslip without the net figure. People who sign in only with single sign-on have no PeopleOS password (setting help says
so).

**My holidays (`Filament\Pages\MyHolidays`, slug `my-holidays`, MS-12).** Opens for a person with their own employee record
and `attendance.regularise`, `leave.apply` or `attendance.view`. Read model `Attendance\Services\HolidayYear` (no payroll
words, as the Attendance architecture test requires): the calendar that applies to the employee (`HolidayResolver::calendarFor`
on today, or the year's last / first day for a past / future year — as attendance resolves it), the year's holidays split
into fixed and restricted (optional holidays of a calendar that requires choosing; otherwise optional holidays are
everyone's and are listed as fixed), each with weekday, date, type, half day, past and the choice (`chosen` = approved or
cancellation requested, `requested` = pending, `not_chosen`), the year's quota (`TimeOfficeRequests::restrictedHolidayQuota`)
and the calendar's note. Page: "Holidays for year, <year>", year select (last, this and next year plus the calendar's years),
tabs "Fixed holidays (n)" / "Restricted holidays (n)", the note, a link to the attendance calendar ("Holiday calendar" view)
and "Choose" on a future, unchosen restricted holiday — the restricted-holiday request type's own form URL from
`RequestRegistry` (only when the registry offers it: module right, `TimeOfficeRequests::offers`, request lock) plus
`actionArguments[holiday_id]`. `ListTimeOfficeRequests::fillType` fills the holiday in only on the person's own "New
request" form, only for one of the person's own optional holidays (`TimeOfficeActions::optionalHolidays`); nothing is
submitted (reason and send stay with the person); without the argument the form mounts as before.

**Leave month view (MS-12).** `Leave\Services\LeaveMonthMovements::for($employee, $leaveYear)` pivots the append-only ledger
of a leave year into the twelve months of the leave year (`LeaveYear`), per leave type: days added (positive entries) and
subtracted (negative entries), entries dated outside the year counted in the nearest month. Leave balances gained "My month
by month" (header action, for anyone with their own employee record; the year from the list's Year filter, else the current
leave year): a modal with HROne's "Leave month name / Balance added / Balance subtracted" per type, the person's own
ledger only.

**Data, settings, permissions.** Migration `2026_12_17_200001_add_employee_note_to_holiday_calendars` (`employee_note` text,
nullable; `HolidayCalendar` fillable; the holiday calendar form's "Note for employees"; `DataInventory` comment — a column on
an inventoried table, organisation text). Settings (Setup → Payroll): `compensation.self_service.show_statutory`,
`compensation.self_service.show_pay_mode`, `compensation.self_service.confirm_password` (all false), `payroll.payslip.password_help`
(blank). No new permission key, no queued job. My space items (`SelfPages`): `my_payslips`, `salary_ytd` (Pay) and `holidays`
(Time; HROne's "Holiday calendar" keywords moved there from the attendance calendar item).

**Tests.** `tests/Feature/HroneParity/MySalaryYtdTest.php` (finalized / paid only, the open run never; financial-year
order and boundaries; another employee and another tenant; arrears, deductions and employer contributions, hidden
components; component set-up and month detail; statutory panels from the lines, ceilings only from a verified version the
payroll used; the page with header, tabs, "How it is calculated", empty states, audit; access and My space),
`MyPayPagesTest.php` (defaults unchanged and the settings listed; the password asked on every pay page and the payslip
pages for non-payroll people, timeout, payroll staff not asked, My day without the figure; the confirmation page with a
wrong and the right password; later Livewire requests stopped; My payslips by year, search, finalized only, the audited
tax slip, a colleague's payslip refused; help text and tax slip rows; statutory block and pay mode behind their switches and
the Bank profile row; the Code on Wages verdict only from verified rules and the state's own minimum wage; own record
only and tenant isolation), `MyHolidaysTest.php` (the holiday year with choices, quota, next year, non-selecting
calendars, no calendar; the page with tabs, counts, note, Choose URL and year select; Choose only when offered and the
pre-filled own form; access and My space; the calendar note on the form; the leave month pivot and "My month by month" for
the own ledger only).

Not built here (seams): payslip PDF, its password protection and language, and the Download button (BP-14;
`MyPayslips::downloadUrl()`); employer NPS and PRAN on My compensation (BP-11); pay group (BP-12); an arrear-from date per
revision (22.5 #9 — PeopleOS keeps none: a revision is never dated into finalized payroll and differences are paid as
arrear adjustments, which My salary YTD lists); claimed amounts, bills and taxable totals of reimbursements (RQ-5 claim
totals); statutory bonus detail beyond the verified rule's rates (bonus rule verification); the year's statutory form
("Form 124": identity to be verified — My tax certificates is linked); the "taken N of M" helper inside the time-office form
(RQ-7a; My holidays shows the quota); PF / ESI arrear rows (PeopleOS has no per-scheme arrear computation: arrear earnings
enter that month's wages).

## TX-3 Run attendance: attendance month desk, close and reopen

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-3; §24.1 #10; §24.5.3 #1, #3–#16, #23–#27;
§24.8 #12), rows marked "→ built (TX-3)". Owner decisions on their defaults: TIM-1 / D-TX1 (month close) is built behind
`attendance.month_close.enabled`, **off** — nothing is closed and every closed-day check answers "open", so days lock only
when payroll is finalized, exactly as before; PAY-1 / D-TX6 — a month a finalized payroll covers is never reopened; TIM-5 /
D-TX7 (bulk "apply leave" on absences) is built behind `attendance.bulk_apply_leave`, **off** — the action is not offered
and refuses if started. Nothing in this package changes calculated attendance by default: the processor only treats a day
of a *closed* month like a locked day, and no month is closed while the setting is off.

**Months come from the pay cycle, through contracts.** Attendance does not own the cycle, so it asks
`Attendance\Contracts\AttendanceCycle` (`window(company, year, month)`, `monthOf(company, date)`, `isFinal(company, year,
month)`), implemented on the payroll side by `Payroll\Services\AttendanceMonthCalendar` (fixed period dates once a run exists
or the period closed, else `PayCycle::bounds`; final = the period is closed or has a finalized / paid run). Payroll reads the
attendance month's state through `Attendance\Contracts\AttendancePeriodState` (`closingInUse()`, `monthState()`),
implemented by `AttendanceMonthCloses`. Leave answers which leave still waits on a window through
`Attendance\Contracts\PendingLeaveReader` (`Leave\Services\AttendancePendingLeaveReader`), so attendance services never touch
leave tables. The three bindings are in `ParityServiceProvider::register()`. TX-3's attendance and leave code carries no
payroll words (a test in the package scans its files; the architecture test's money-word rule still applies to all).

**Month close (TIM-1).** `Attendance\Services\AttendanceMonthCloses`:
- `close(company, year, month, actor, ?note)` — `attendance.process`, the company in the actor's scope; refused while month
  close is off, when the month has not ended (its last day must have passed), when already closed or final, and — with
  `attendance.close_requires_no_pending` (default on) — while any regularisation, leave (new or cancellation), on duty,
  restricted holiday, short leave or other time-office request on the month's days waits for a decision (counted across the
  whole company, not only the actor's scope; the refusal lists the counts by kind). The row (`attendance_month_closes`:
  company, year, month, the window's dates at close, status, closed by / at / note, reopened by / at / reason) is written
  under a row lock (the unique key turns a simultaneous close into a clear refusal) and audited with the note.
- `closedDates(employee, from, to)` — the days that fall in a closed month of the company the employee belonged to *on that
  day* (positions effective per day, so a transfer mid-month follows the right company); `isClosedDay()`; `assertOpen()` —
  the lock rule: refused unless `attendance.allow_corrections_after_lock` is on. Every lock site applies it the way it applies
  the payroll lock: `AttendanceOverrides` (set / clear), `Regularisations::request` (and overtime approve / reject, which
  refuse a locked day outright), `TimeOfficeRequests` (request, record approved, revoke on cancellation), `ShiftRoster`
  (assign / clear), `LatePenalties::waive`, `RequestRules::assertNotProcessed` (`{prefix}_after_process` = No also refuses
  closed days), the day-override, shift-roster and overtime uploads (row error), the monthly attendance upload (closed days
  are skipped and reported like locked days) and `Leaves::request` (leave domain → attendance service).
- The calculation: `AttendanceProcessor::process` keeps a day of a closed month as it is, like a locked day — with
  corrections after lock allowed, the difference becomes an attendance arrear (note "Corrected after the attendance month was
  closed"); a day with no record yet is still calculated. Closed months refuse the background run ("Reopen the month to
  process it again").
- `reopen(company, year, month, actor, reason)` — reason required, audited; refused once `AttendanceCycle::isFinal` or any
  attendance day of the company's people in the window is locked (D-TX6: "later corrections follow the corrections-after-lock
  setting"). Arrears recorded while the month was closed (pending or held, on days not locked) are voided ("Month … reopened:
  the day is recalculated instead") and those days recalculated, so a correction lands on the day and never counts twice.
- With the setting switched off later, closed rows stay but have no effect (every check answers "open").

**Payroll warns, never waits (TIM-1).** `Payroll\Services\AttendanceMonthNotice` (contract only): with month close in use and
the pay month's attendance still open, the payroll control room's readiness panel adds a note per company ("Acme India:
attendance for Nov 2026 is not closed yet (Attendance → Run attendance). Payroll can run; …") and the run's Calculate and
Finalize confirmations append it as a warning. Nothing blocks; with the setting off nothing is shown (the readiness checks
and their keys are unchanged).

**Arrears hold / release (§24.5.3 #7–#10).** `attendance_arrears.status` gains `held` (string column, no migration;
`AttendanceArrear::STATUSES`). `Attendance\Services\AttendanceArrears::hold / release / moveWindow` — `attendance.arrears`,
the employee in scope, a reason, row lock, audited; only pending ↔ held move, settled and void never do.
`AttendanceArrearInput` takes only `pending` arrears (held wait); when a run that already paid an arrear is finalized after
it was held, `settle()` still settles it (pending or held among the paid ids). The processor updates a held arrear in place
(still held) when its locked day changes again, voids it when the day is back to what was paid. Bulk operations
`attendance_arrears.hold` / `.release` (WO-1 framework) on the Attendance arrears register (selected rows and "Act on all
matching"), with presets "Pending arrears" / "Held arrears" and the new attendance policy / punch type filters next to PO-1's
arrear month and arrear-days bands (§24.1 #10).

**Background processing (§24.5.3 #11, #13, #14).** `Attendance\Services\AttendanceMonthRuns::start(company, year, month,
employees, actor, filters)` — `attendance.process`; refused before the month starts, for a closed month, or with nobody to
process; records an `attendance_month_runs` row (company, month, window, total / done / failed, the filter labels, started
by) and queues one `ProcessAttendanceRange` per employee (their days of the month up to today, inside joining and exit) with
the run id. The job (tries 3, timeout 600 s, backoff 60 / 300 s, tenant id + `BindTenantContext`, unchanged when no run id is
given) ticks the run when done; `failed()` binds the run's tenant itself (it runs outside the middleware) and ticks it
failed. The tick that reaches the total completes the run once (conditional status update), audits it and sends the
starter an in-app and e-mail notice through `Notifier` (event `attendance.month_processed`, listed in
`peopleos.notifications.events`). The people come from `AttendanceMonthPopulation` (a position with the company on any day
of the month, joined by its end, not gone before its start, past pre-boarding) narrowed by departments, locations,
designations, employee types, pasted codes / codes left out, the attendance policy (or none) and its punch type.

**The desk (§24.5.3 #1–#16, #23–#27; §24.8 #12).** `Filament\Pages\RunAttendance` (Attendance → Run attendance, also on the
Time office desk; `attendance.view` with `attendance.process` or `attendance.arrears`): company and pay-cycle month
selectors (12 back, one ahead; "Oct 2026 · 1 Oct – 24 Oct" on a changeover), the uploads menu (monthly attendance, day
overrides, punches, shift roster, overtime), then — read by `Attendance\Services\AttendanceMonthDesk`, loaded after the page
(`wire:init`), inside the viewer's scope —
- the month's state with Close / Reopen (only with month close on and `attendance.process`; reopen hidden once final) and
  what stops a close;
- "Before you close the month": readiness errors — attendance policy missing (no `attendance` policy resolves; the detail
  says when no time-office policy does either), last month not processed (and, with month close on, not closed), employees
  with missing shift (no schedule by assignment or rule and no rostered day), joined after the cut-off (joining after the
  month's last day within its calendar month, i.e. on a 25th–24th cycle; empty on calendar months) — and reminders of
  requests waiting on the month's days by kind; each card opens its list (first 200 rows) with "Download CSV";
- step 1 (`attendance.process`): absent / half-day counts and CSV, and a table of those days (WO-2 filters, attendance policy
  and punch type filters, link to the calendar) with bulk "Mark present" (`attendance_records.mark_present`:
  `AttendanceOverrides::set` "present" with one reason; `attendance.override`; one's own days refused) and, with TIM-5 on,
  "Apply leave" (`attendance_records.apply_leave`: `Leaves::request` on the employee's behalf then `Leaves::approve` as HR
  with the reason, inside the record's own transaction — eligibility, balance, negative-balance limit, notice and locked /
  closed days apply; half days only with a chosen half; refusals listed in Bulk runs; `leave.manage`);
- step 2 (`attendance.arrears`): pending / held arrears the month would take (corrected days before it), grouped by the month
  they correct, with Hold / Release per month (reason) and links to the register's views;
- step 3 (`attendance.process`): fully processed / not yet (with the list) of the month's people, "Process" with the
  filters above, the latest runs with a progress bar (the section polls while a run is running);
- "Review an employee's month": totals (`AttendanceOutput::summary`), requests waiting, pending / held arrears, closed days,
  the calendar link and "Process this employee's month" (synchronous, refused for a closed month).
Every CSV is formula-safe and audited as `EXPORT` (module attendance, metadata `export = run_attendance`, list, rows,
company, month).

**Data, settings, permissions.** Migration `2026_12_18_200001_create_attendance_month_closes` (`attendance_month_closes`,
unique `amc_tenant_company_month_uq`; `attendance_month_runs`) — both in `DataInventory` (attendance), both models tenant-
scoped and audited (run counters move through query-builder increments, not audited per employee). Settings (Setup centre,
Time office): `attendance.month_close.enabled` (false), `attendance.close_requires_no_pending` (true),
`attendance.bulk_apply_leave` (false); Setup centre link to Run attendance. No new permission key (TX-2's
`attendance.process`, `attendance.arrears`, `attendance.override` and `leave.manage` are checked), no role template change —
the bulk actions also need `bulk.run` (Tenant HR Admin and HR Manager hold it; the Attendance Admin template does not).

**Tests.** `tests/Feature/HroneParity/AttendanceMonthDeskTest.php` (defaults: a closed row has no effect, nothing refused,
no payroll note, no close action; close only after the month ends and with no pending request (and without that rule),
audited, once, `attendance.process` only; closed days refuse overrides, regularisations, on duty, leave and roster changes,
keep their values when punches arrive, other companies and later months untouched; corrections after lock → arrear, reopen
voids it and recalculates the day; never reopened when a finalized payroll covers the month or a day is locked; payroll
notice through the contract; arrears grouped by month, hold / release from the page and the bulk framework, settled never
move, a held arrear paid by a run is settled, a held arrear stays held when its day changes again; background run with
filters, progress, completion notice in-app and e-mail, failed jobs, single completion, closed month and missing right
refused; population filters; readiness, reminders, lists and audited CSV; late joiners on a 25th–24th cycle; mark present and
apply leave (off by default, then approved by HR, refusals listed); the page for each right, close / reopen from the page; no
payroll words in TX-3 attendance code).

Not built here: paid-day adjustments and the month summary by day type (TX-4), the arrear-days upload (TX-13), half-day
statuses by half (TX-6), the employee's own "ESS is locked" calendar banner (matrix 22 MS-11), pay groups (payroll matrices),
scheduling the month run (the nightly processing stays as it is).

## WO-13 Letter registers and lifecycle

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-13; §23.7.1 #1–3, 5–6, 8, 11–12, 14, 16, 22–27;
§23.7.2 #2–5, 7–8, 11–12; §23.11 #5–7, 12–16; also §23.9 #6), rows marked "→ built (WO-13 …)". Owner decision WRK-2
(D-WO5 + D-WO6) on its default: revoke hides the letter from the employee and marks it revoked — the letter and its file
are kept, nothing is deleted; generating again supersedes (a new letter linked to the old one), never overwrites. Every
default reproduces the earlier behaviour: no register lost a column, filter, action or navigation entry; the new columns
are toggled off and the new filters and header tools are shown only to people who read letters (letter.view / issue /
manage) — "My letters" looks as before; no letter is drafted, e-mailed or listed as a task unless the organisation sets
the new policy fields or switches.

**Letters (domain).**
- `Letters::generate()` gained the second to fourth signatories (`$others`, by position 2–4; null = the template's
  `letter_signatory_2_id` … `_4_id`), the `process` that drafted the letter, the letter it supersedes and the schedule. The
  variables `{{ signatory_2.name }}`, `.designation`, `.signature` … `signatory_4.*` are always in the context (blank when
  the position is not named) and in the variables guide; `DocxLetter` embeds each distinct signature image as its own
  picture (the first keeps `rIdPeopleOSSignature` / `media/peopleos-signature.png`).
- `Letters::revoke($letter, $actor, $reason)` (letter.issue, reason ≤ 500, issued letters only): status `revoked`,
  `revoked_at` / `revoked_by` / `revoke_reason`, the issued document set to `archived` (with the reason) so My HR, My profile
  and "Download all" no longer list it, and `EmployeeDocumentPolicy::isOwn` refuses an archived document of a revoked letter
  (the employee cannot open it even by link); LETTER_REVOKED audit. HR (letter.view / issue / manage) may still download it.
- `Letters::supersede($letter, $actor, $reason, ?$template, $issue)` (letter.issue): a new letter for the same employee,
  source, process and schedule, from the same template (same signatories) or another (its signatories), with the extra
  values typed in originally (`Letters::extrasOf`: snapshot paths outside the catalogue); `supersedes_letter_id` links it.
  A letter not yet issued is revoked at once; an issued one stays with the employee until its replacement is issued —
  `issue()` then revokes it ("Superseded by …"). One live replacement per letter; LETTER_SUPERSEDED audit.
- `Letters::download()` allows a revoked letter for people who work with letters; `Letter::usesSalary()` says whether the
  letter printed salary variables.
- New types `probation_extension`, `termination`, `contract`, `transfer_intimation`, `resignation_acceptance` (each gets its
  optional `letter.<type>` code series automatically); status `revoked`.

**Policy hooks and process (`PolicyLetters`).** `PolicyLetters::HOOKS` names every `{policy type}.{setting}` that drafts a
letter; `draft()` now records it as the letter's `process` (confirmation `letter_on_confirm` / `_extend` / `_terminate`,
exit `letter_on_exit` / `letter_on_completion`). New hooks, blank by default: exit `letter_on_acceptance`
(`Exits::acceptResignation`, templates of type resignation acceptance / relieving / custom), transfer
`letter_on_request_approval` (intimation, on `TransferRequests::approve`) and `letter_on_effect` (on approval when the
effective date has come, else by `peopleos:letters:tick` on the effective date, once per transfer, looking back a week);
transfer letters also get `{{ transfer.effective_date }}`, `{{ transfer.changes }}`, `{{ transfer.reason }}`.
`PolicyLetters::label()` is the register's "Process": the hook, schedule / bulk, else the source's module (letters from
before WO-13), else Manual. `template($hook, $employee)` reads a hook's template from the employee's policy (bulk "as per
policy"). Contract letters drafted from contracts wait for WO-5 (the type and its register view exist).

**Registers (Filament).** `LetterResource`: toggleable columns Process, Template, Generated by, Signed by (off by default);
filters letter template, signatory (any of the four), generated by, letter process, employment type (HR only); generate
with signatories 2–4; on a letter: "E-mail the letter", "Generate again", "Revoke", Download for revoked letters (HR), and
the infolist shows the other signatories, process, replaces / replaced by, revocation and — for salary letters — the
compliance warnings. `ListLetters`: views for extension, termination, contract, transfer intimation and resignation
acceptance letters (with Process and Generated by shown), "Ready to issue" (approved, the generation queue) and "Revoked
letters"; earlier views unchanged. Header "Download letters" (issued letters matching the filters, a filter or search
required) and "Letter schedules"; table bulk "Download as zip" and "Generate & publish". `LetterTemplateResource`: second
to fourth signatory. Workforce desk: the new type views, "Letters ready to issue" (count), "Letter schedules".

**Bulk (WO-1 framework).** `employees.generate_letter` (Employees register; letter.issue): a chosen template or "as per
policy" (a hook; employees whose policy names none are refused), up to four signatories, extra values, optionally issue at
once when no approval is needed; process `bulk` or the hook. `letters.publish` ("Generate & publish", Letters register):
issues approved letters; with "change the template" each is superseded by a letter from the chosen template (issued when it
needs no approval); letters waiting for approval are refused.

**Bulk download.** `LetterDownloads::request()` (letter.view / issue / manage; ≤ `peopleos.letters.bulk_download_max` = 500)
records a `letter_downloads` row and queues `BuildLetterDownload` (3 tries, 900 s, backoff 60 / 300, tenant id +
`BindTenantContext`; claimed queued → running; `failed()` marks it failed). The job runs as the person (`ScreenAccess::as`):
only letters still issued, in their scope and passing the letter's `view` policy go in (the rest counted as skipped), each
audited DOWNLOAD (`purpose` bulk_download); the zip is kept on the documents disk under the tenant's letters folder for
`peopleos.letters.download_hours` = 24, the person is notified with a signed link (`letters.downloads.zip`, the protected
web chain, `LetterDownloadController`) that only they can use (audited); the tick removes expired zips (the row stays).

**E-mail the letter.** Off unless `letters.email.enabled` (Setup → Workforce). `LetterEmails::send()` (letter.issue, the
employee's and the letter's `view`, issued letters only): to the work e-mail and/or the personal e-mail (personal only with
employee.sensitive.view), from the person record; CC / BCC only active sign-ins of the tenant or typed addresses checked as
e-mail (≤ `peopleos.letters.email_max_copies` = 10 together). One notification delivery (event `letter.emailed`, the tenant's
mail transport, `DeliverNotification` after commit, resendable) whose source is a `letter_emails` row: the new
`Notifications\Contracts\MailEnvelope` lets a delivery's source add recipients, copies and attachments, and `EmailChannel`
asks only sources whose class implements it (every other e-mail is sent exactly as before; `NotificationMail` takes optional
files). The attachment is the letter's own file, read when the mail goes — a letter revoked meanwhile is not sent (the
delivery fails). Audited on the letter as LETTER_EMAILED (kinds of address, number of copies, ids; never the message).

**Salary-letter compliance warnings.** `LetterSalaryChecks::forLetter()` (letters that printed salary variables): the
approved compensation in force on the letter's salary date through `CompensationOutput` and its breakup from the
structure's own formulas (wages = earnings classified basic / DA, against gross); thresholds only from verified rule data
(`VerifiedThresholds`: `WAGES.min_wage_share`, `MIN_WAGE.monthly_minimum` for the state of the employee's establishment) —
otherwise the check says "No verified rule" and does not run. Read-only, never blocks; no amounts shown.

**Letter schedules.** `letter_schedules` (+ `letter_schedule_items`, unique per schedule, employee and occurrence):
`LetterSchedules::save()` (letter.issue) — template, up to four signatories, extra values, trigger one time (publish now /
at a date and time) or event based (`peopleos.letters.schedule_events`: joining, birthday, confirmation, resignation,
leaving, retirement (exit policy retirement age), work anniversary, absconding) N days before or after; audience named
employees, organisation-wise (`AudienceCriteriaSchema` / `AudienceQuery`, inside the owner's scope) or upcoming joiners
(pre-employee / pre-boarding); issue at once when no approval is needed; e-mail on issue (only with e-mailing on); "save for
later use" (status saved; Start / Pause / Cancel / Use again). `RunLetterSchedule` (3 tries, 1800 s, backoff 60 / 300, tenant
id + `BindTenantContext`; one cache lock per schedule) runs as the owner: a schedule whose owner can no longer issue letters
or whose template is inactive is paused with the reason; at most `peopleos.letters.schedule_max_employees` = 2000 employees a
run; each employee on its own (refusals kept per employee). `peopleos:letters:tick` (daily 06:50; listed in
`docs/operations/queue-and-scheduler.md`) queues event schedules and the one-time schedules due that day (delayed to their
publish time), drafts transfer letters on the effective date and expires bulk downloads. Screens: Letters → Letter schedules
(`LetterScheduleResource`: list with template, event frequency, occurrence, scheduled date, status, created on; wizard form;
view with each employee's outcome).

**Letter tasks (§23.11).** `Letters\Support\LetterTaskSource` (in `peopleos.experience.task_sources`): approved letters not
yet issued, as My tasks of people who issue letters, only with `letters.tasks.issue_queue` on (letters waiting for approval
are Approval Center items already).

**E-sign seam (SV-17).** `Letters\Contracts\LetterSignatureProvider` bound to `NoSignatureProvider` (`available()` false):
nothing offers e-signing until SV-17 chooses a provider; a provider will sign issued letters in `Letter::signatoryIds()` order
and store the signed file as a new version, never overwriting.

**Data, permissions, settings.** Migration `2026_12_19_200001_letter_registers_and_lifecycle` (letters: signatory 2–4,
process, supersedes, schedule, revoked_*; letter_templates: signatory 2–4; new letter_schedules, letter_schedule_items,
letter_downloads, letter_emails; long names explicit). `DataInventory`: the four tables under `documents`, the zip as a file
of `letter_downloads`. No new permission (letter.issue / letter.view / letter.manage, bulk.run). Settings
`letters.email.enabled`, `letters.tasks.issue_queue` (false; Setup → Workforce); technical limits `peopleos.letters.*`.
Audit actions LETTER_REVOKED, LETTER_SUPERSEDED, LETTER_EMAILED. Policy fields exit `letter_on_acceptance`, transfer
`letter_on_request_approval` / `letter_on_effect`. `LetterSchedulePolicy` (schedules and their items).

**Tests.** `tests/Feature/HroneParity/LetterRegistersTest.php` (defaults unchanged; four signatories in written and Word
letters; revoke hides and keeps; generate again links and revokes on issue; new hooks and process labels; transfer letter on
the effective date once; e-mail switch, recipients, copies, attachment, audit without the body, revoked letter not sent;
bulk zip — only issued letters the person may open, audited per file, fetch by the requester only, expiry; bulk generate
chosen / as per policy and Generate & publish; salary warnings from verified rules only; schedules now / later / event,
once per occurrence, saved for later, owner without rights pauses; letter tasks behind the switch; screens and tenant
isolation).

Not built here: e-signing (SV-17), letter acknowledgement (WF-01 / RQ-10), contract letters drafted from contracts (WO-5),
shared ready-made documents and their unpublish (WO-14), ID cards (WO-20), a per-letter e-mail type per signatory (e-sign).

## GO-2 / GO-9 Cycle population, windows and withdrawal; performance task kinds

Matrix: `docs/hrone-parity/modules/27-performance-engage-operations.md` (package GO-2: §27.1 #6; §27.2.2 #3, 4, 6–9; §27.2.4
#4; §27.3.3 #3–5; §27.4.1 #6 — package GO-9: §27.2.1 #21; §27.11 #5–8, 10, 14, 16; with matrix 20 §20.2.2 #53, #54, #56 and
R.3.3 #4, which pointed at the performance matrix), rows marked "→ built (GO-2 …)" / "(GO-9 …)". Every default reproduces
the earlier behaviour: without an extension every window is the stage's own (the frozen cycle dates), nobody is added to or
withdrawn from a running cycle unless HR does it, launch creates the same appraisals. The visible differences are new task
rows (below) and, on My work, the "Reviews to complete" / "Performance reviews" reminder no longer repeating them.
Owner decisions in theme PER stay on their defaults: PER-10 / HP-8k (real deletion of performance transactions) stays "No" —
"Withdraw from cycle" is the safe alternative.

**Add employees to cycle (§27.2.2 #3–4, §27.3.3 #3; HROne "Initiate goal / review cycle for selected employees / OU /
bulk").** `Appraisals::addEmployee($cycle, $employee, $actor)` — performance.manage within the access scope, a running cycle
only (a draft or scheduled cycle includes everyone eligible when it launches), under the cycle row lock. It goes through the
launch path: `ineligibility()` applies the launch rules exactly (employed, joined by the period end, the cycle's eligibility
rules and the performance policy's criteria — HP-8i's joiner cut-off — the former `eligible()` filter is now
`meetsEligibility()`, shared); `createAppraisal()` (now also used by `launch()`) never makes a second appraisal for the same
employee and cycle (the unique key; an existing one is returned) and adds the reviews the stages call for
(`ensureReviews`); the status follows the stages already entered (pending → in review → calibration); the periodic reviews
of the performance policy (`PeriodicReviews::planFor`, extracted from `plan`), the goal sheet policy's system mapping
(`GoalTemplates::assignFor`, extracted from `assignForCycle`) once goal setting has started, and — once the manager review has
opened — the goal templates' and the performance policy's feedback forms for that appraisal only (`raiseFeedback` /
`raiseReviewFeedback` take the appraisal ids). The reviewers of stages already open are told (`performance.review.assigned`),
the employee is told (`performance.appraisal.added`), and the cycle's audit trail records "Added … to the running cycle"
(metadata `employee_added`). An employee already in the cycle gets the open stages' reviews brought up to date (HROne's "start
the current stage's reviews for named appraisals", e.g. a manager review once a manager is known); a withdrawn one is refused
("a withdrawal is final"); a finalized one is refused. In bulk: operation `employees.add_to_cycle` (WO-1, model Employee,
input the running cycle).

**Window extensions (§27.2.2 #6–8, §27.2.4 #4, §27.3.3 #4, §27.4.1 #6; HROne "Extend goal", "Extend review", "Extend 360").**
`performance_window_extensions` (`PerformanceWindowExtension`, append-only: never updated or deleted; a later extension adds a
row and the latest end wins): cycle, stage (`goal_setting`, `self_review`, `manager_review`, `approver_review`, `peer_review`;
calibration and the final rating are HR's own stages), employee (null = everyone), the previous end, the new end, the reason,
who. `App\Domain\Performance\Services\PerformanceWindows`:
- `stageEnd($cycle, $stage, $employeeId)` — the latest of the stage's own end, an extension for everyone and one for the
  employee (null when the stage has no end date); `stageEnds()` for many employees in one query; `extensionEnd()`;
  `reviewDue($appraisal, $type)`.
- `extend($cycle, $stage, ?$employeeIds, $endsOn, $reason, $actor)` — performance.manage, a running cycle, a stage the cycle
  has with an end date, a reason (≤ 500), a date not in the past; for everyone it must be after the current end; chosen
  employees are skipped with the reason when they are not in the cycle, outside the access scope, withdrawn, finalized or
  already have that date or later. Under the cycle row lock, inside one audit operation (each row audited with the reason).
- Read by: `Goals::assertGoalPeriodOpen` — both the HP-8b stage check (`performance.goals.edit_only_in_goal_stage`) and the
  HP-8i policy goal window end at the extended date, and an extension keeps goal setting open for that person until its
  date even once the cycle has moved on to its reviews; `PerformanceReminders::tick` (each pending review falls due at its
  stage's end for that employee; the notification carries that date) and `remindReviewer`; the Review requests "Due" column
  (`AppraisalReviewResource::dueOn`); the GO-9 task due dates.
- 360 rounds (HP-8h): "everyone" still moves the round's own window (`FeedbackRounds::extend`, unchanged); chosen employees
  get a row of stage `feedback_360` on the round (`extendRound`): `roundClosesOn($round, $employeeId)` / `roundOpenFor()` are
  what `FeedbackNominations` now asks before an answer, a decline, a client's link (`byToken`) and a nomination, what
  `pendingFor` / `toNominateFor` filter on (a correlated `EXISTS` on the extensions) and what the 360 task due dates and the
  clients' link expiry (`sendLink`, `refreshLinkExpiry`) follow; `FeedbackRounds::runSchedule` closes a round only once
  `roundLastDay()` (its window or the latest extension) has passed.

**Withdraw from cycle (§27.2.2 #9, §27.3.3 #5 — HROne "Delete goals" / "Delete reviews"; PER-10 / HP-8k: nothing is
deleted).** `Appraisals::withdraw($appraisal, $reason, $actor)` — performance.manage within the access scope, a reason
(≤ 500), never one's own appraisal; under the appraisal row lock, then the employee row (as every goal-sheet change): only a
non-finalized, unlocked appraisal of a running cycle. The appraisal becomes `withdrawn` (`withdrawn_at`, `withdrawn_by`,
`withdrawal_reason` — the reason is a sensitive audit value) and is read-only from then on (model guard; never deleted); its
pending reviews and its scheduled / open periodic reviews become `withdrawn`; its pending goal plan is cancelled
(`GoalPlans::cancel`: status `cancelled`, the reason as the decision note, a workflow waiting on it cancelled through
`WorkflowEngine::cancel`); the employee's open goals of the cycle (draft, waiting, active) are cancelled with the reason
(`Goals::cancelForWithdrawal`, each audited, aligned parents recomputed). Submitted reviews, ratings and history stay. A
STATUS_CHANGE audit event carries the counts (`withdrawn_from_cycle`); the employee and their manager are told
(`performance.appraisal.withdrawn`). Finalized appraisals are immutable and refused. A withdrawn appraisal is refused by
`submitReview`, `addPeerReview`, `reopenReview`, `calibrate`, `finalize`; `score` leaves it as it was; it is not opened,
scored or told at a stage change, not counted as open or unpublished when the cycle closes (it is locked with the others),
and left out of the distribution (`PerformanceDistribution`, `Appraisals::distribution`), the 9-box, the calibration board,
performance analytics, the goal sheets register, the system goal mapping, the periodic-review schedule and the 360 blend
rescoring. In bulk: operation `appraisals.withdraw` (WO-1, model Appraisal, one reason for the run).

**Screens (GO-2).** `Filament\Support\PerformanceCycleActions`: a population picker built from the WO-2 filter group
(`EmployeeFilters`: named employees with the shared search, an organisation unit — company, business unit, division,
department with its sub-departments, designation, grade, level, location — or pasted codes; "Everyone in the cycle" where it
applies; the number chosen shown live and typed to confirm above `peopleos.bulk.confirm_over`), always inside the viewer's
access scope (§27.1 #6). Cycles (row and edit page, running cycles): "Add employees" and "Withdraw from cycle" (bulk runs —
`bulk.run` and performance.manage, queued `RunBulkOperation` with tries / timeout / backoff and the tenant bound, one result
per person on People → Bulk runs), "Extend window" (stage, who, new end date, reason); the cycle page's "Window extensions"
tab (`WindowExtensionsRelationManager`, read-only, performance HR). Goal requests: header "Extend goal"; Review requests:
header "Extend review" (both with a cycle to choose). Appraisals: "Withdraw from cycle" on the appraisal page, bulk on
selected rows and "Act on all matching"; status "Withdrawn from the cycle" (filter, preset, colour) and the withdrawal
facts on the page (HR and managers). The 360 round's "Extend window" asks whom (everyone — as before — or chosen
participants). The "Performance actions" menu links to the running cycles.

**Performance task kinds (GO-9; §27.11 #5–8, 10, 14, 16; §27.2.1 #21).** Five `ExperienceTaskSource`s in
`app/Domain/Performance/Support`, registered in `config('peopleos.experience.task_sources')` as the earlier ones, so My HR →
Tasks, My work and the unified inbox (RQ-8, facets "Performance review", "Create goal", "Goal check-in", "Goal check-in:
company", "Goal check-in: team", "Acknowledge overall rating", "PIP evaluation"; its due labels apply) pick them up with no
change of their own. Each shows a task only to the person who must act, carries the employee in `meta` (the inbox's filters)
and never a rating, a reason or an objective:
- `PerformanceReviewTaskSource` (kind `review`): the person's own pending review (self, manager, approver, peer, upward,
  skip-level, external) of an appraisal still open (cycle running; not finalized, locked or withdrawn) whose stage has
  opened (as `submitReview` allows); due at the stage's end for that employee. A reviewer always sees the review they write
  (AppraisalVisibility), so nothing new is shown.
- `GoalSheetTaskSource` (kind `create_goal`, HROne "Create goal"): the goal sheet of a running cycle at goal setting (or with
  goal setting extended for the employee) whose status (GO-1 `GoalSheets`) is not created, draft or sent back — to the
  employee, or, when the goal sheet policy maps sheets by the system and the reporting manager fills them in first, to the
  appraisal's manager while the goal rules let them see the employee's goals; only while the person may still change the
  goals (`assertGoalPeriodOpen`); due when goal setting ends for the employee (the policy's goal window or the stage, as
  extended).
- `CheckInPromptTaskSource` (kinds `goal_check_in`, `goal_check_in_company`, `goal_check_in_team`): HP-8b's scheduled
  prompts (`performance.checkin.<scope>.interval` / `.day`; off by default = no task) — from the latest prompt day until each
  goal of the scope has a check-in since that day (employee: own active goals; company / team: open organisation goals the
  person created, while they hold performance.manage as the goal rules ask), due the day before the next prompt day; goals of
  closed cycles are left out.
- `AppraisalAcknowledgementTaskSource` (kind `rating_acknowledgement`): the employee's published, finalized, unlocked
  appraisal, only when the performance policy lets the employee see the final rating; no due date.
- `PipEvaluationDueTaskSource` (kind `pip_evaluation_due`): an active or extended improvement plan at or past its end date,
  to the plan's manager only while they may still see and manage it (PIP confidentiality: never the employee or anyone
  else); due on the end date.
`UnifiedInbox::TASK_LABELS` gained the seven labels; `SelfServiceLock::TASK_KINDS['performance']` now reads "Performance
tasks (…)" (the employee request lock — off by default — counts overdue performance tasks when that kind is chosen or the
list is blank); `WorkInbox::DUPLICATES` gained `reviews` (each review is its own row now).

**Data, permissions, settings, jobs.** Migration `2026_12_20_200001_create_performance_window_extensions`
(`performance_window_extensions`; `appraisals.withdrawn_at`, `withdrawn_by` (FK `appraisals_withdrawn_by_fk`),
`withdrawal_reason`). `DataInventory` (performance): the new table. `PerformanceWindowExtension` is `ScopedByEmployee` with
its own `applyAccessScope` (a row for one employee follows that employee's scope; a row for everyone names nobody and stays
visible); `PerformanceWindows` reads the rows unscoped, so a due date never depends on who looks. Config: appraisal status `withdrawn`, goal plan status
`cancelled`, periodic review status `withdrawn`; notification events `performance.appraisal.added`,
`performance.appraisal.withdrawn` (in-app titles in `NotificationEventBridge`; not webhook events); the five task sources.
Bulk operations `employees.add_to_cycle`, `appraisals.withdraw` tagged `peopleos.bulk_operations` (ParityServiceProvider).
No new permission key (performance.manage, performance.feedback_360 and WO-1's bulk.run), no new setting, no command; queued
work is WO-1's `RunBulkOperation`.

**Tests.** `tests/Feature/HroneParity/PerformanceCyclesTasksTest.php` (defaults: the stage's own windows, launch unchanged;
adding a joiner through the launch path once, with the open stages' reviews and notifications; refusals — draft cycle,
rights, not employed, the performance policy's cut-off, access scope; bulk add with results per person and the mapped goal
sheet; withdrawal — reviews, plan, goals, audit, notifications, read-only afterwards, refused by reviews / calibration /
finalization / re-adding, out of the registers and the distribution; finalized and own appraisals refused, bulk withdrawal,
closing a cycle with withdrawn appraisals; goal-setting extensions for chosen people and everyone, after the stage moved on,
the policy goal window, append-only rows and audit; extension refusals and skipped people; reminders and due dates per
employee; 360 extensions per employee — answering, the inbox, the round kept open, then closed; the five task kinds with
their people, due dates, visibility and PIP confidentiality; the unified inbox facet and My work; the screens; tenant
isolation).

Not built here: reinstating a withdrawn appraisal (a withdrawal is final; adding the person again is refused), extending
HP-8i's periodic review windows (they have their own per-review windows and "Initiate periodic review"), cancelling 360
nominations or feedback forms already asked when an appraisal is withdrawn (360 rounds belong to their policy, not the cycle),
notifications about an extension (the new date shows in reminders, review requests and My tasks), a "Delete" of any kind
(PER-10 / HP-8k), the inbox insight counts per performance task kind (AN-30, matrix 21).

## WO-11 Separation desk

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-11; §23.6.1 #6–11, 14, 17–18; §23.6.2 #1, 3–4;
§23.6.3 #1, 4, 7, 9, 11–13; §23.6.4 #1; §23.2.2 #6–7, 20; §23.11 #10–11, 17 are marked "→ built (WO-11 …)"). Owner decisions
WRK-3 / D-WO8 (withdrawal: immediate by default, organisations opt in to approval), WRK-6 / D-WO10 (the leaver's roles and
team: HR chooses on the exit case; what is left is a warning before "Complete exit", never a block) and D-WO3 (cancel with a
reason instead of delete) follow their proposed defaults. WRK-4 is unchanged: a rejected resignation workflow still
withdraws the resignation (`ResignationWorkflowBridge`). Every default reproduces the earlier behaviour: no resignation waits
for acceptance, a withdrawal takes effect at once, no stage gets a due date, the settlement's notice line is the built-in one
(organisation setting, gross ÷ 30), nothing new appears in My tasks or the Approval Center, and no register lost a column,
filter, action or navigation entry. The one deliberate change: withdrawing an exit now closes its open clearance stages as
"Not applicable — exit withdrawn" (cleared stages stay as they are) — before, they were left pending on a withdrawn case.

**Data.** Migration `2026_12_21_200001_separation_desk`:
- `exit_cases`: `approver_reason_id` (the approver's separation reason, a `workforce_reasons` row offered to approvers — kept
  apart from `workforce_reason_id`, the employee's or the initiating HR's), `approver_remark`, `notice_relaxation_days`
  (default 0), `rehire_comment`, `settlement_month` (first of the month; blank = the month of the last working day).
- `exit_clearances`: `due_on`, `expected_on`, `closed_reason` (`withdrawn` / `cancelled`), `evidence_path`, `evidence_name`,
  `evidence_by`, `evidence_at`.
- `exit_withdrawal_requests` (`ExitWithdrawalRequest`, Exit; tenant-scoped, access-scoped by employee, audited — reason and
  decision note masked): exit case, employee, requested by, reason (≤ 500), status pending / approved / rejected / cancelled,
  `open_key` ("case:<id>" while pending; unique per tenant → one open request per exit), decided by / at / note. Never deleted.
- `DataInventory`: `exit_withdrawal_requests` under `employment`; the evidence file (`exit_clearances.evidence_path`, documents
  disk) and the prefix `tenants/{id}/exit-clearances`.

**Domain.**
- `Exit\Services\SeparationDesk`: `decide()` (accept / reject, only with `exit.resignation.requires_acceptance`; `mayDecide` =
  `exit.manage`, the employee in scope, never the employee themself; not while a deciding resignation workflow is in flight;
  on acceptance optionally the approver's reason + remark (its "remark required" rule), a new last working day with its reason
  and relaxation days, then `Exits::acceptResignation` — so the WO-13 acceptance letter hook still applies; a rejection
  withdraws, as WRK-4), `setApproverReason()`, `changeLastWorkingDay()` (`exit.manage` in scope, open exit, settlement not
  approved, a reason ≤ 500, never before the resignation date, never backdated beyond the exit policy's `hr_backdate_days`; the
  employee's exit date and every stage's due date follow; timeline entry and `exit.last_working_day_changed`; an exit whose new
  day falls inside the clearance lead window starts its clearance, as at initiation), `setNoticeRelaxation()` (0 … the notice
  period, resignations only, a reason), `setRehireEligibility()` (open or completed exit; the blacklist reason required once
  the organisation keeps such reasons, as CA-3 at initiation; an alumni profile of that exit follows), `markNotEligibleForRehire()`
  (an exited / alumni employee's latest completed exit), `setSettlementMonth()` (`exit.manage` or `exit.settle`; never before the
  last working day's month; not once paid), `setExpectedDate()`, `attachEvidence()` (the upload in `exit-clearances/` moves to
  `tenants/{tenant}/exit-clearances/{stage}/{ulid}.{ext}` after `FileSafety`; a new file replaces the earlier one),
  `cancelStage()` (D-WO3: `Exits::markNotApplicable` with "Cancelled: <reason>", `closed_reason` cancelled — kept, never deleted),
  `closeForWithdrawal()` (called by `Exits::withdraw`), `assertReadyToComplete()` (called by `Exits::complete`: refuses an
  unaccepted resignation while acceptance is switched on, and an exit whose withdrawal request waits — both only possible with a
  switch on).
- `Exit\Services\ExitWithdrawals` (D-WO8): `request()` — setting off: `Exits::withdraw` at once with the reason as given (as
  before); on: a pending request (the employee or HR in scope; one per exit under the case row lock and `open_key`), event
  `exit.withdrawal_requested` (a workflow trigger; `RequestWorkflows::DECIDING_TRIGGERS` lets a run on any record started by it
  decide the request; subject type "Resignation withdrawal request"). `approve()` → `Exits::withdraw`; `reject()` needs a note;
  `cancel()` by the requester; `mayDecide` = `exit.manage`, in scope, never the requester or the employee; not while a deciding
  run is in flight. `Exit\Listeners\ExitWithdrawalWorkflowBridge` applies a deciding run's outcome in the last completer's name.
- Notice shortfall (`Exit\Support\NoticeShortfall`): notice days − days served (resignation date → last working day) −
  relaxation days, never below 0, resignations only. The exit policy's new section "Notice shortfall recovery"
  (`notice_recovery_rule` blank / `none` / `policy`, `notice_recovery_basis` gross / basic / basic + DA / CTC,
  `notice_recovery_divisor` fixed / days of the LWD month, `notice_recovery_days`): blank keeps the built-in line of
  `FinalSettlements` (organisation setting `exit.notice_recovery`, gross ÷ 30 — now less relaxation days, which are 0 unless HR
  grants some); `none` recovers nothing; `policy` skips the built-in line and `Exit\Settlement\NoticeRecoverySettlementSource`
  (tagged `peopleos.settlement_sources`) adds `NOTICE_RECOVERY` = shortfall × (monthly basis ÷ divisor), the monthly basis read
  only through `CompensationOutput` (the approved compensation on the last working day) and the structure's own formulas
  (`StructureFormulas::preview`, as WO-13's letter checks). No rate is hard-coded. The settlement's inputs record the rule (when
  set) and the settlement month (when chosen).
- Due dates (`Exit\Support\ClearanceDueDates`): last working day − offset; the offset is the largest "due (days before the last
  day)" of the stage's items in the mapped clearance checklist, else the exit policy's new section "Clearance due dates"
  (`clearance_due_manager` / `_it` / `_finance` / `_asset` / `_hr`, days before the last working day, negative = after it),
  else none. Set when the stages are built and moved with the last working day.
- Delegation (`Exit\Support\ClearanceOwners`, §23.6.3 #9): while a P4 out-of-office delegation covering the request type
  `exit_clearance` runs (listed by `ExitApprovalSource` so Setup → Inbox actions → Delegate can offer it; no items), the delegate
  owns the delegator's stages (owner user or role) — `ExitClearance::isOwnedBy`, the exit cases register's query, My tasks —
  never on the delegate's own exit. Without such a delegation, ownership is as before.
- Hand-over (`Exit\Services\ExitHandover`, D-WO10): `rolesHeld()` (hand-given roles of the leaver's sign-in except the
  employee / alumni roles; grants by a membership rule are listed, not actionable), `scopes()` (the leaver's data access),
  `reportees()` (people reporting to the leaver on the day after the last working day, any reporting type), `handOverRole()`
  (replace: `RoleMembers::add` for the replacement — same company as the leaver's grant — then `RoleMembers::remove`; remove;
  `user.assign_roles`, the delegation ceiling, ROLE_CHANGED with "Exit hand-over EXIT-…: reason"), `changeManager()`
  (`ChangeManagerAction::change` from the day after the last working day: `employee.position`, both people in scope, audited and
  on the timeline; bulk change manager stays WO-16), `unresolved()` (the warning).
- Relieving review (`Exit\Services\RelievingReview`, §23.6.1 #14): last payroll processed (`PaidPayReader::latestPaid`), salary
  on hold (new read contract `Payroll\Contracts\SalaryHoldReader`, bound to `Payroll\Services\OpenSalaryHolds`: open holds with
  the month's finalized net pay), notice (served, relaxed, shortfall, the recovery rule in force), settlement month, hand-over
  left. Net amounts only for `exit.settle` or `payroll.view`. Read only.

**Approvals, requests, tasks.**
- `Experience\Sources\ExitApprovalSource` (tagged): `exit_withdrawal` (pending requests not in a deciding run, to `mayDecide`
  holders; approve = withdraw) and `resignation_acceptance` (with `exit.resignation.requires_acceptance`: open, unaccepted
  resignations without a deciding run; "Accept" / "Do not accept" → `SeparationDesk::decide`). The unified inbox (RQ-8) lists
  both through the Approval Center.
- RQ-1: `ResignationWithdrawalRequestType` (`resignation_withdrawal`, module Separation): offered only while withdrawals need
  approval and the employee has an open resignation without a waiting request; form = the exit case's "Withdraw resignation";
  history in My requests; route: raised event `exit.withdrawal_requested`, approver right `exit.manage`, kind `exit_withdrawal`.
  `ResignationRequestType` counts an unaccepted open resignation as Pending ("Waiting for acceptance") while acceptance is
  switched on (before: only while a workflow ran).
- `Exit\Support\ClearanceTaskSource` (in `peopleos.experience.task_sources`, only with `exit.clearance.my_tasks`): open stages
  of exits in clearance for their owners (`exit.clear` / `exit.manage`; delegates included), due on the stage's due date (else
  the last working day); exits ready to complete (every stage cleared or n.a.) for `exit.manage`.

**Screens.**
- Exit case (`ExitCaseResource`, `ExitActions`, `Filament\Support\SeparationActions`): "Accept / reject resignation" (a wizard:
  Decision → Last working day; shown only while the resignation waits for acceptance), "Change last working day", "Notice
  relaxation", "Approver's reason" (when reasons for approvers exist), "Rehire eligibility", "Settlement month" — appended after
  the existing actions (the record page's "More" groups them). "Withdraw resignation" goes through `ExitWithdrawals::request`
  (same result while the switch is off; hidden while a request waits). "Complete exit" shows the hand-over warning. New details
  shown only when set (approver's reason, accepted on, notice shortfall, settlement month, rehire comment), a "Relieving review"
  section for HR, a "Withdrawal requests" tab (once there is one: approve / reject for HR, "Call off" for the requester).
  Filters "Ready to complete (all stages cleared)" and "Withdrawal request" (HR only); presets `ready_to_complete` and
  `withdrawal_requests`.
- Clearance stages (the case's Clearance tab and the register): due (red when overdue), expected, evidence; row actions
  "Expected date", "Attach / Replace evidence", "Evidence" (download through `ParityFileController` type `exit-clearance`, the
  exit case's policy — `ExitCasePolicy::view` now judges a stage and a withdrawal request by their exit case), "Cancel stage",
  and on the HR stage "Roles held" (replace / remove one role, the leaver's roles and data access in the description) and
  "Change manager of the team".
- Exit → Clearances (`ExitClearanceResource`, slug `exit-clearances`, `HasRegisterTools` key `exit_clearances`): one row per
  stage — exit, leaver, last working day, stage, owner, status (closed reasons named), due, assigned, expected, completed,
  recoverable, evidence, exit status; filters status (open by default), stage, exit status, overdue, mine to clear, not started,
  pasted codes, organisation, last working day, due; presets open / overdue / blocked / not started / mine / closed; report
  `clearances_pending`; bulk `exit_clearances.start` and `exit_clearances.cancel`. HR (exit.view / manage / settle) sees the
  stages of the exits in their scope; a stage owner (exit.clear) only their own; in navigation for HR only.
- Employees register: bulk `employees.start_clearance` and `employees.complete_exit` (each to the employee's open exit), row
  action "Not eligible for rehire" (exited / alumni, `exit.manage`, a completed exit still marked eligible).
- Workforce desk → Separation: "Clearance stages", "Ready to complete", "Withdrawal requests waiting" (earlier entries kept).
- Setup → Workforce, group "Separation": `exit.resignation.requires_acceptance`, `exit.withdrawal.requires_approval`,
  `exit.clearance.my_tasks` (all off); link "Clearances". Exit policy sections "Notice shortfall recovery" and "Clearance due
  dates" (blank). Exits dataset: approver's reason and kind, accepted on, relaxation and shortfall days, settlement month,
  withdrawal status and requested on (free-text remarks stay on the case).

**Events, permissions.** `exit.withdrawal_requested`, `exit.withdrawal_approved`, `exit.withdrawal_rejected`,
`exit.last_working_day_changed` (in `notifications.events`; in-app titles in `NotificationEventBridge`). No new permission:
`exit.manage` (decisions, hand-over with `user.assign_roles` / `employee.position`), `exit.clear` (own stages), `exit.settle`
(settlement month), `exit.resign` (the request type) — no `peopleos:sync-permissions` needed. No queued job, no command.

**Tests.** `tests/Feature/HroneParity/SeparationDeskTest.php` (defaults unchanged; acceptance with the approver's reason, a new
day and relaxation, refusals; not accepted → withdrawn from the Approval Center; withdrawal requests approved / rejected, one per
exit, never decided by the requester, RQ-1 type; a workflow on `exit.withdrawal_requested` deciding it; access scope on decisions,
the register and the evidence file; change of last working day within the policy, the shortfall line less relaxation, the policy's
basic ÷ 30 / days-of-month rules and "do not recover"; settlement month; due dates from the checklist or the policy; withdrawn →
n.a.; bulk start / cancel (D-WO3) from the Clearances and Employees registers; P4 delegation of a stage; roles / team hand-over
audited and only a warning; rehire eligibility and "not eligible for rehire"; the relieving review; the task source and the
"Ready to complete" view). `RequestRegistryTest` lists the new type.

Not built here: bulk change manager (WO-16), checklist task depth — sub-status, links, dependencies, auto-complete (SV-23),
approval-level reports and the no-dues certificate (AN-30 / AN-29), replacement hiring (recruitment, N-A), a resignation
attachment (RQ-6), the exit-interview register (WO-12). Open points: whether clearance tasks should be in My tasks by default
(applied: off, as WO-13 did for letters); whether HR who initiated a resignation may also accept it (applied: yes — only the
employee themself never decides it); whether the last working day change should also move asset return requests and exit
interview due dates (applied: no — they keep their dates).

## TX-4 Paid-days register and paid-day adjustments

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-4; §24.5.3 #17–21; §24.5.4 #1–4, #6; the TX-4
part of §24.9 #5), rows marked "→ built (TX-4)". Owner decision TIM-2 / D-TX2 is built on its default: attendance-side
paid-day adjustments sit behind `attendance.paid_day_adjustments.enabled`, **off** — nothing is offered or saved, rows left
behind have no effect, `AttendanceOutput` returns exactly what it returned before (same keys, same values, same types) and
payroll calculates exactly as before (only its own loss-of-pay adjustment). The register, breakdown, review summary and
dataset are read-only additions. No new permission key.

**Paid days, one reading.** `Attendance\Support\PaidDayTally::fromDays()` splits processed days (as `AttendanceDay`) into the
categories HROne's paid-days card uses — present (present, work from home, on duty, field duty and the worked half of a half
day), paid leave, holidays, weekly offs; not paid: unpaid leave, absent (with the absent half of a half day), unpaid weekly
offs — and computes `paid_days` with exactly `AttendanceOutput::summary()`'s formula (paid statuses less half a day per half
day), so the register and the summary never disagree. `minutes()` gives hours by kind of day (holiday = a holiday name on the
day; weekly off = a weekly off or work on an off day; else working day) and approved overtime. Late-mark penalty days stay a
separate quantity, as in the summary. Known quirk kept as it is (default unchanged): the summary counts a day of half-day
leave as half a paid day whatever the other half, so a worked day with half-day leave counts 0.5 and a half day of unpaid
leave −0.5; the categories do not go negative, so in that case they do not add up to the paid days (owner decision TIM-11).

**Adjustments (TIM-2 / D-TX2).** `Attendance\Services\PaidDayAdjustments` (bound to the new read contract
`Attendance\Contracts\PaidDayAdjustmentReader`):
- Table `attendance_paid_day_adjustments` (model `PaidDayAdjustment`, tenant-scoped, `ScopedByEmployee`, audited): employee,
  company / year / month (the attendance month as TX-3 keys months, from `AttendanceCycle`), the window's dates when saved,
  mode, value, categories, paid days before / after as previewed, the rounding used, reason, created by, status
  `active` / `reversed` with reversed by / at / reason. `active` is 1 while counting and null once reversed, so the unique key
  `apda_active_month_uq` allows one counting adjustment per employee, company and month. Nothing is deleted.
- Mode **set** fixes the counts of present days, paid leave, holidays and weekly offs (paid days = their sum; a category left
  blank keeps the processed count; with no counts, `value` is the paid days set — the shape TX-13's upload will feed;
  late-mark penalty days still apply on top, open point TIM-13). Mode
  **reduce** takes, per category, no / partial (a percentage) / full reduction; a partial reduction is rounded by the
  employee's attendance policy, new field `paid_days_round_off` in a "Paid days" section (none = two decimals, nearest / down /
  up to a half or whole day). Help text in reduction wording (`AdjustPaidDaysOperation::help()`).
- `preview()` shows the window, the processed counts, each category before → after, paid days before → after, the adjustment
  it would replace and why it would be refused — without saving. `save()` needs a reason, re-checks the lock rule inside the
  transaction, reverses the adjustment counting until then ("Replaced by a newer adjustment: …") and writes the new one; both
  audited with the reason. `reverse()` needs a reason. A simultaneous save hits the unique key and is refused.
- Refused while switched off; without `attendance.override` (TX-2's split right — HROne's "Override paid days";
  `attendance.manage` still grants it); outside the actor's access scope; on one's own paid days; for someone not in the
  company's month; on a month finalized downstream (`AttendanceCycle::isFinal`) or with a locked day; and on a month closed
  under TX-3 (`AttendanceMonthCloses::assertOpen`: refused unless `attendance.allow_corrections_after_lock` is on).
- The effect is worked out when read (a reduction follows reprocessed days; set counts stay fixed): `AttendanceOutput::summary`
  applies, with the setting on, every counting adjustment whose window lies inside [from, to] to `paid_days` and adds
  `paid_days_by_attendance` and `paid_day_adjustment`. A range that does not hold the whole month (the calendar's current
  month up to today) is left as processed.

**Payroll reads quantities through the contract (D-TX2).** `Payroll\Services\AttendancePaidDayBasis` asks
`PaidDayAdjustmentReader::forMonth(employee, period company, year, month)` (null while off). `PayrollCalculator`, only when an
adjustment counts (open point TIM-12: take it, as built, or only show it), adds `-change` days to loss of pay (bounded to [0, eligible units]; more loss of pay is taken from the last
salary segment like manual LOP, days given back come off the last segment first, then the earlier ones, never below 0 — so
the segments always add up to the entry's loss of pay), records `inputs.attendance_paid_day_adjustment` (before, after, change, mode, description, reason, LOP days)
and, when payroll's own loss-of-pay adjustment also touches the employee-month, a non-blocking exception
`paid_days_adjusted_twice` ("… Both count: check that the same days are not taken twice."). Payroll's manual LOP is
unchanged. The run's "Breakdown" shows a "Paid-day adjustments" section (both, from the entry's basis, with the warning) for
entries that recorded one or while the adjustments are in use. The engine version stays `payroll-2.5` (the default
calculation is identical; a test pins the version).

**The register (§24.5.4 #1–4, #6).** `Filament\Pages\PaidDays` (a `Filament\Support\Pages\RegisterTablePage` — new abstract
page holding `InteractsWithTable`, so the table is built before `HasRegisterTools` applies a preset or default view on a
custom page, as `ListRecords` does for resource lists; Attendance → Paid days, also on the Time office desk and the
Setup centre's Time office links; `attendance.view`, the viewer's access scope): company, attendance month (from
`AttendanceMonthDesk::months`) and "the month" / "the financial year up to the month"
(`peopleos.compliance.financial_year_start_month`). Rows are the month's people (`AttendanceMonthPopulation`), figures from
`Attendance\Services\PaidDaysRegister::figures()` for the page's employees in a few queries: present, paid leave, unpaid
leave, holidays, weekly offs, absent, penalty days, arrear days, adjustments and paid days (= paid days after adjustment less
penalty days, as the attendance calendar shows), with hidden half days, paid days as processed, days processed and hours by
kind of day. Arrear days of a month are the corrections recorded to its days after they were locked (pending, held or
settled — never void), so months add up over a year without counting a correction twice. WO-2 filters (codes, organisation,
status / type / joining, pending resignation) plus "Paid days adjusted", "With late-mark penalty days", "With arrear days";
WO-1 saved views (register key `paid_days`, presets `adjusted`, `penalties`, `arrears`), "Report" (the two new templates)
and "Download CSV" (formula-safe, audited `EXPORT` with `export = paid_days`). "Breakdown" opens one employee: the months, the
month's paid days, hours by kind of day, late-mark penalties (date, late marks, action, days, status), arrears (date, before →
after, days, status) and adjustments with reversed ones ("Reverse" with a reason). Penalties never take leave in PeopleOS, so
HROne's leave code column has nothing to show.

**Adjusting from the page.** With the setting on and `attendance.override`: "Adjust paid days" on a row (wizard: adjustment,
then preview of the month and the reason) and on the selected rows (wizard with each employee's preview, first 50 shown,
typed count over 25, then a WO-1 bulk run `paid_days.adjust` — `Experience\BulkOperations\AdjustPaidDaysOperation`, needs
`bulk.run`; each employee through `PaidDayAdjustments::save`, refusals listed in Bulk runs). Not offered on the year view.

**Review summary (§24.5.3 #17).** Run attendance → "Review an employee's month" adds HROne's "Summary": present / weekly offs /
holidays / paid leave, paid days as processed, adjustment and effective paid days, hours on working days / weekly offs /
holidays, approved overtime hours, arrear and penalty days, and a link to the paid-days breakdown.

**Dataset (§24.5.4 #8).** `attendance_paid_days` (`Analytics\Datasets\AttendancePaidDaysDataset`, category Attendance,
`attendance.view`, the reader's scope through the attendance models' `AccessScope`; not on `analytics.unattended_datasets`, so
no run without a signed-in user): one row per employee and month in which some of their days are processed (grouped in SQL
like `attendance_monthly`); the figures are those of the attendance month of that name for the company the employee belonged
to at the calendar month's end, computed by `PaidDaysRegister` once per month for the month's people (a few queries per
month). Fields: month, month start, period from / to, financial year, the employee fields, company that month, the register's
quantities, adjustment days, paid days as processed, paid days, hours by kind of day, adjusted. Templates: "Paid days before
payroll (last month)" and "… (this financial year)" (grouped per employee).

**Data, settings, wiring.** Migration `2026_12_22_200001_create_attendance_paid_day_adjustments` (`DataInventory`:
attendance). Setting (Setup centre, Time office) `attendance.paid_day_adjustments.enabled` (false); attendance policy field
`paid_days_round_off`; payroll exception label `paid_days_adjusted_twice`. `ParityServiceProvider`: the contract binding and
the bulk operation tag. No new permission (adjustments: `attendance.override`; bulk: also `bulk.run`).

**Tests.** `tests/Feature/HroneParity/PaidDaysTest.php` (off: `AttendanceOutput` byte-identical with a row left behind,
nothing offered or saved; reduce with the policy's rounding and the preview, two-decimal default, refused inputs, audit; set
counts replacing the earlier adjustment, blank categories and total-only, one's own and without the right refused, the broad
key accepted; finalized, locked and closed months refused, closed allowed with corrections after lock; reversal from the
service and the page; payroll: off identical, on → loss-of-pay days through the contract, both adjustments → warning; register
equal to the summary for the month and the year to date, the page's columns, breakdown, year view, preset, audited CSV;
single and bulk adjust from the page with the preview; access scope and rights; desk and Setup centre entries; Run
attendance's review summary; the dataset, its templates, rights, scope and no unattended run; no payroll words in TX-4
attendance code; the table classified).

Not built here: the "Override paid days" upload (TX-13 — rows will be set / reduce adjustments through the same service),
the day drawer from a breakdown row (TX-7), pay groups as a register filter (payroll matrices), a national-holiday split of
holiday hours (holidays carry no type on the attendance day), the employee's own paid-days view (MS-11).

## WO-16 Bulk change manager and bulk invitations

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-16; §23.9 #4–5, 7–8, 10, 14, 17 and the bulk part of
§23.6.3 #13 are marked "→ built (WO-16 …)"). Out of scope and unchanged: the password type (§23.9 #9, N-A by the SaaS.2 rule),
WhatsApp (#11, #16, D-WO7) and licence counts (#12). Every default reproduces the earlier behaviour: nothing moves and nobody is
invited until someone holding `bulk.run` and the operation's own permission runs a wizard; open approvals move only when that
person ticks it; the one-person "Change manager" on the Employee 360 and the Users → Edit invitation / reset actions are untouched;
no register lost a column, filter, action or navigation entry. No migration, no new permission, no setting, no new job.

**Who (one selection for every wizard).** `Employment\Services\EmployeeSelection` (read only; an Employee query, so the access
scope narrows it): `team` — the people reporting to one manager today through `line` ("Reporting team") or `functional`
("Functional team"), leavers left out; `filters` — the WO-2 dimensions on the current position (company, business unit,
division, department with its sub-departments, designation, grade, level, location, employment type), status (lifecycle
state), joining date and "added on" ranges, and the sign-in state (none / invited / active / suspended); at least one filter or
"Everyone in my access" ticked on purpose; `codes` — a pasted list (`peopleos.bulk.pasted_codes_max`). The Employees register's
wizards also offer "the employees this register shows now" once the register is narrowed (as WO-1's "Act on all matching").

**Bulk change manager (§23.9 #4–5).** `Employment\Services\BulkManagerChanges` behind the bulk operation
`employees.change_manager` (permission `employee.position`, with `bulk.run`):
- `prepare()` (once per run): the new manager in the actor's scope and not exited / alumni / pre-joining / suspended, a known
  relationship type (`people.reporting_types`), the effective date, a reason (≤ 500); with "Move their open approvals" also
  `task.reassign`, a line or functional change, a date today or earlier, and a new manager with an active sign-in — otherwise
  the run is not started, with the reason.
- `refusal()` per person, each listed on the run: own manager; a loop (the new manager reports to the person directly or through
  others, walked on the line and on the relationship being changed, on the effective date); a person who has left; outside the
  scope; already reporting to that manager; and anything that would rewrite history — a line of that type already recorded from a
  later date, a current line that already has an end date, or one that started on the effective date itself. Otherwise
  `ChangeManagerAction::change` closes the line open on that date the day before and opens the new one (`HasEffectiveDates`,
  MANAGER_CHANGED, the timeline, `employee.manager_changed`); past rows are never edited beyond that close.
- Open approvals (§23.9 #5, off by default): `managerTasks()` finds the moved person's pending workflow steps assigned to the old
  manager's user (not a role) whose node spec resolved the person's manager — approver / assignee type `manager`,
  `hierarchy_level` level 1 or `initiator_manager` (when the person started the run) for the line, `functional_manager` for the
  functional line — on a run whose subject is the person (`NotificationContext::employeeOf`), and never a step whose run log
  shows it was reassigned or escalated (held for that reason). Each moves by `WorkflowEngine::reassign` (audited ASSIGN with
  "Manager change: <reason>", the run log, the new assignee's notification); a step the engine refuses (the new manager started
  that request or it is about them) stays and is counted in the outcome line. Steps held by a role, a named user, a context field
  or a skip-level step are never touched.

**Bulk invitations and reset links (§23.9 #7–8, #10, #14, #17).** `Identity\Services\BulkInvitations`, the single-user flows
and nothing else:
- `employees.invite_users` ("Send to new user", permission `user.create`): an employee without a sign-in gets a user created from
  the lower-cased work e-mail (status invited, a random password nobody knows — as Users → New user), linked to the employee
  (audited), the chosen roles through `RoleMembers::add` (`user.assign_roles` and RoleGrants; the Employee role offered by
  default), the role membership rules queued when the tenant has any, then `UserInvitations::issue` (the one-time link e-mailed
  directly; only its hash stored; INVITATION_ISSUED). An employee whose user is still invited gets a new invitation (the
  "Send a new invitation" flow, the user's `update` policy).
- `employees.send_reset_links` ("Reset for existing user", permission `user.update`): `PasswordResets::sendNow` (the same broker
  token and e-mail as "Send password reset link", sent at once so the result is known; the broker's throttle refuses a second
  link within a minute).
- Refused with the reason: no (valid) work e-mail; the e-mail already used by another user (any organisation — e-mail is unique
  per platform); a person who has left or is suspended; outside the scope; an active sign-in asked for "new user", no sign-in or
  one still invited asked for "reset"; a suspended sign-in.
- CC (§23.9 #10): up to `BulkInvitations::CC_MAX` = 5 addresses. The invitation / reset e-mail itself goes only to the person; each
  CC address gets a separate notice through the notification delivery log (event `user.invitation_copied`, the tenant mail
  transport, after commit, dedupe key per run / person / address) saying what was sent to whom at which address — no link, no
  token. Its correlation id is the run's audit operation id. The invitee's own address is never copied.
- Users list: `users.resend_invitations` and `users.send_reset_links` (permission `user.update`) for the users ticked
  (`UserInvitationOperation`; a user linked to an employee outside the scope is refused).
- Delivery list (§23.9 #14): the bulk run page shows, for these operations only, a "Delivery list" tab
  (`DeliveryListRelationManager`; whoever may open the run): employee code, name, e-mail, e-mail status (sent — waiting for
  acceptance / accepted / expired / replaced or revoked / reset link sent / not sent / failed), the reason, and the CC notices with
  their delivery status, with "Download list" (CSV, formula cells quoted). Built from the run's items, the invitations and the
  notification deliveries. SMS status is N-A until a provider exists. No link or token is stored anywhere to show: run items keep
  "Invitation sent to <address>", the run input keeps the CC addresses and role ids.

**Screens.** `Filament\Support\WorkforceBulkWizards`: "Change manager" (Who → Change → Review), "Invite to sign in" (Invitation →
Who → Review; the mode, CC and roles first, so the sign-in filter follows the mode) and "Send password reset links" (the same with
the mode fixed). The review shows the count and the first ten people (current → new manager, or address and sign-in state); over
`bulk.confirm_over` the typed count is asked (and enforced again by `BulkRuns::start`). Where: the Employees register header
group "Bulk changes" (`bulkChangeManager`, `bulkInvite`, `bulkResetLinks`) and its toolbar / "Act on all matching" (the three
operations added to `ListEmployees::BULK_OPERATIONS`); the Users list header ("Invite to sign in") and toolbar; the Employee 360
"Action" group "Change manager of the team" (`changeTeamManager`, shown when the person has a team; a leaver's team moves from the
day after the last working day by default — the bulk part of §23.6.3 #13; WO-11's HR-stage action stays as it was); the Workforce
desk's People section ("Change manager", "Invite to sign in", "Send password reset links", opening the register with
`?action=`), listed only for people who may run them (`OperationsDeskPage` items accept a `visible` closure).

**Data, permissions.** No table, no migration, no `DataInventory` change. No new permission: `employee.position` (+ `task.reassign`
to move approvals), `user.create`, `user.update`, `user.assign_roles` (roles), all with `bulk.run` — no `peopleos:sync-permissions`
needed. Runs use WO-1's `RunBulkOperation` (queued, tenant-bound); no job, command or setting was added.

**Tests.** `tests/Feature/HroneParity/BulkManagerInvitesTest.php` (defaults unchanged; a team moved from a date with the earlier
line kept; each refusal reason and history never rewritten; access scope for moves, new managers and invitations; approvals left
alone by default; only manager-resolved steps moved when asked, with the audit; new-user and reset modes with each refusal; the CC
notice never carrying the link or token, nor any trail; the delivery list, its CSV and tab; the wizards from the register, a
manager's page, the Users list and the desk; a leaver's team from the day after the last working day).

Open points (applied defaults): a pre-joiner can be invited (only leavers and suspended people are refused); the e-mail
uniqueness check spans the platform, so an address used in another organisation is refused with the same message as Users →
New user; approvals do not move with a future-dated change (they stay with today's manager); CC notices go one per invited
person, not one summary per run.

## PO-2 Salary structure register and assignment wizard

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (package PO-2; §25.2.1 #1–#5; §25.2.2 #1, #12, #18, #21–#23,
#30 (on screen); §25.2.3 #1), rows marked "→ built (PO-2)". Every default reproduces the earlier behaviour: nothing here
approves, executes or writes compensation history, payroll runs, entries or periods; the Employee 360 compensation tab, its
"Propose compensation change", salary uploads and compensation cycles are unchanged; the compensation changes list keeps
every column, filter, action and view it had (it opens on the new "All" tab). No migration, no new permission key, no
setting, no new queued job (the bulk run reuses WO-1's `RunBulkOperation`). Owner decisions relied on: PAY-1 (finalized
payroll is never reopened — the wizard only proposes; proposals into closed months stay refused by `CompensationChanges`),
PAY-10 (statutory figures only from VERIFIED rule versions; 0 of 24 verified today, so the estimate and the Code on Wages /
minimum wage checks say "No verified rule"), PLT-1 (nothing is deleted: a pending request blocks a second one until it is
decided or cancelled).

**The register (§25.2.1 #1–#5).** `Filament\Pages\SalaryStructureRegister` (a TX-4 `RegisterTablePage` with WO-1
`HasRegisterTools`; Compensation → Employee salary structures, slug `employee-salary-structures`, register key
`employee_salary_structures`; also the first two entries of the Payroll desk's "Salary structure" section, the second on the
"Not assigned" view with its count): one row per employee whose lifecycle state may hold compensation now or later
(`compensation.lifecycle.current` ∪ `future`), inside the reader's access scope (Employee's `AccessScope`), for
`compensation.view`. Columns: employee, designation, state, structure and annual CTC in force with its currency and date
(`CompensationOutput::inForceOn()` — a new bulk read on the contract, one query per page), the latest request (status, change
type, source, request date, effective date) and the tax regime of the employee's declaration for this financial year.
- State (`Compensation\Services\SalaryAssignments`): from the employee's latest compensation change that was not cancelled —
  draft / submitted / under review = **Pending**; approved / scheduled = **Approved** (waiting to take effect); rejected =
  **Rejected**; otherwise **Assigned** when approved compensation is in force today, else **Not assigned**
  (`stateOf()` for a row, `whereState()` as SQL for the filter and the five state cards above the table, which apply it).
- Filters: state, "salary structure in force" (assigned / not assigned — HROne's "NO SALARY STRUCTURE", the **Not assigned**
  preset; employees with a pending first proposal are listed there too), pay group (structure in force, through
  `CompensationOutput::coveredEmployeeIds`), source of the latest request (`compensation.sources`: proposed by a person,
  cycle, position, plan, salary upload), tax regime (`Compliance\Services\DeclaredRegimes`: the declaration's regime for the
  financial year — what TDS uses — or "no declaration (new regime by default)"), request date range, and WO-2's pasted codes,
  organisation group, employee status / type / joining and pending-resignation toggle; search by code, name, e-mail,
  designation. Presets: not assigned, pending, approved (not yet in force), rejected last. Reports: CTC register, employees
  without salary, salary revisions this year.
- "Download CSV" (§25.2.1 #3 "Export All"): the filtered register (at most `bulk.max_records` rows), formula-injection-safe
  cells, for `compensation.export` (the matrix row's right), audited as `EXPORT` (module `compensation`, metadata `export`,
  `rows`, the filters in use).

**Assign salary (§25.2.1 #5, §25.2.2 #1, #12, #18, #21–#23).** The row action "Assign salary" (`compensation.propose`, never
on one's own row) is a four-step wizard ending in Review:
1. **Employee statutory** — PF / ESI / PT applicable, PT state, tax regime and whether PAN, UAN and ESI number are on file
   (presence only, never the value), for `employee.sensitive.view` (opening the wizard records the sensitive read as the 360
   does); with `employee.sensitive.update`, "Change statutory details" edits them through
   `ChangeStatutoryApplicabilityAction` with a required reason (audited on `EmployeeStatutoryDetail`), in the same transaction
   as the proposal.
2. **Salary details** — change type, effective date, structure ("keep the structure in force" or an active structure), the new
   CTC as an amount, **revise by %** of the CTC in force on the effective date (`SalaryAssignments::revise()` =
   `round(CTC × (1 + % / 100), 2)`, the compensation cycles' rule; refused for components-mode structures, whose CTC comes
   from the amounts; the percentage is kept on the change as `increase_percent`), or the CTC unchanged; currency, variable
   target and fixed amounts / inputs prefilled from the compensation in force; reason, internal notes, "Submit for review now"
   (on by default).
3. **Preview** — `Payroll\Services\SalaryProposalPreview` (read-only, nothing stored): the breakup is
   `StructureFormulas::preview` over the proposal's structure version (`SalaryAssignments::segment()` builds the
   `CompensationSegment` a proposal would give) with the employee's CTC, amounts, attributes and PF wage basis (PY-15 option,
   else the establishment's), shown with the structure preview's own view; the **statutory estimate** runs
   `StatutoryEngine::apply` on an in-memory `PayrollComputation` of the effective pay month (the existing `PayrollPeriod` when
   there is one, else an unsaved stand-in — never created) only when an applicable scheme has a rule version that
   `VerifiedThresholds` accepts (VERIFIED, checksum-intact, no open notice; `rule()` now takes a null parameter for rules read
   whole, such as PT slabs), and keeps only the lines of those rule versions: employee PF / VPF, ESI, PT, LWF (with the months
   LWF is deducted) and the employer contributions, the net take home excluding TDS (TDS is not estimated, as HROne's);
   every other scheme says "No verified rule … not estimated" or "Does not apply". **Salary insights**: the structure
   preview's advisory wage-share guardrail ("Wages are greater than exclusions" / "Exclusions are greater than wages …"), then
   the Code on Wages share from a verified `WAGES.min_wage_share` and the minimum wage from a verified
   `MIN_WAGE.monthly_minimum` of the establishment's state — else "No verified … rule", as WO-13's salary letter checks say.
   **Salary payment mode** in force on the effective date (`PaymentModes::for`, bank transfer by default), read-only, with a
   link to Payroll → Payment modes for `payroll.manage`. **Print breakup** downloads the proposal's salary annexure.
4. **Review** — what will be proposed (structure, CTC before → after with the percentage, statutory change, next step), or why
   it would be refused.

Proposing calls `SalaryAssignments::propose()` → `CompensationChanges::propose()` (+ `submit()`), source `manual`: review,
approval, execution by other people, the workflow bridge and the Approval Center apply exactly as for a proposal from
Employee 360; nothing approves or applies here (a test pins it). Refused with the reason (`SalaryAssignments::refusal()`
plus the service's own checks): without `compensation.propose`, outside the access scope, one's own compensation, a change
already pending (draft / submitted / under review) for the employee, no structure in force to keep, no CTC in force to revise.

**Many employees (§25.2.2 #30).** The table's bulk action "Assign salary" (`bulk.run` + `compensation.propose`) asks the same
proposal once — keep each one's structure or choose one; revise each one's CTC by a percentage (default), give the same CTC,
or keep each CTC — over four steps (statutory: changed one employee at a time, with the count of selected employees without
statutory details; salary details; a per-employee preview of the first 25: structure and CTC before → after, or the refusal;
review with WO-1's typed count above 25) and starts a WO-1 bulk run of the new operation
`Experience\BulkOperations\AssignSalaryOperation` (`salary_structures.assign`, tagged `peopleos.bulk_operations`): one proposal
per employee through `SalaryAssignments::propose()`, fixed amounts carried from the compensation in force for the chosen
structure's components, each refusal listed on the run (outside the scope, a pending change, own record, no structure, no CTC).
Run results carry no amounts.

**Print breakup (§25.2.2 #21).** `Letters::breakup()` renders the salary annexure — `LetterContext::salaryFor()` (the
existing `salary()` split so it takes any segment; the letters' output is unchanged) and `SalaryAnnexure::toHtml()` — in the
letter studio's page layout with the employee, structure, effective date and CTC, and, for a proposal, "Proposed salary, not
yet approved"; downloaded as HTML to print; audited as `EXPORT` (module `compensation`, `export = salary_breakup`, never the
amounts). Offered in the wizard's preview (`SalaryStructureRegister::printProposal`, `compensation.propose`) and as "Print
breakup" on each salary structure request (`compensation.view`).

**Salary structure requests (§25.2.3 #1).** The compensation changes list (`CompensationChangeResource`) gained HROne's request
cards as tabs — All (default) · Pending (draft, submitted, under review) · Approved (approved, scheduled, effective) ·
Rejected, with counts from the list's own scoped query — the same groups as a "Request status" filter and the presets
"Pending / Approved / Rejected requests", filters source, tax regime and request date, the columns "Request date" and "Tax
regime" (hidden by default) and the "Print breakup" record action.

**Data, permissions.** No migration. No new permission key (`compensation.view`, `.propose`, `.export`,
`employee.sensitive.view` / `.update`, `bulk.run` as they are). Contract addition: `CompensationOutput::inForceOn()`.
Shared-file changes: `VerifiedThresholds::rule()` (nullable parameter), `LetterContext` (`salaryFor()` public),
`Letters::breakup()`, `ParityServiceProvider` (one bulk operation), `PayrollDesk` (two entries, register key),
`CompensationChangeResource` / `ManageCompensationChanges`. Architecture boundaries kept: Compensation code names no statutory
or payroll class (the estimate lives in Payroll and takes a `CompensationSegment`); Payroll, Letters and Compliance never
name compensation models or services.

**Tests.** `tests/Feature/HroneParity/SalaryStructureRegisterTest.php` (states and counts, columns, every filter, the Not
assigned preset and state cards, the desk entry and count; audited CSV for `compensation.export` only and the register for
`compensation.view` only; access scope and tenant isolation, a bulk run refusing an employee outside the scope; revise by %
and its rounding, refusals without CTC or structure; the preview equals `StructureFormulas::preview` with the employee's
inputs; the estimate writes nothing and says "No verified rule", then estimates PF from a verified EPF rule with the engine's
figures and still not PT; the wizard creates a submitted proposal through CompensationChanges with the statutory change
audited, refuses a second one while pending, saves a draft, and the approval chain still needs other people; bulk refusals
(pending, own, no structure) with one proposal for the rest and no amounts in the results; print breakup in the letter
layout with the letters' own annexure, audited, from the wizard and the requests list; the requests' tabs, filters and
presets).

Not built here: the arrear date on a revision (PO-20), PAN–Aadhaar link and per-employee PF limits / proration, LWF switch,
gratuity and NPS options of HROne's statutory step (BP-8 / BP-11), minimum wages by skill category or zone (BP-15), the
flexible benefit declaration (PO-21), perquisites in the wizard (PO-18), a server-side PDF of the breakup (VEN-5 / PAY-5), the
"revise salary" inbox task (PO-14), the new-joiner "assign salary structure" task.

## XO-3 Expense report review and documents

Matrix: `docs/hrone-parity/modules/26-expense-asset-helpdesk-operations.md` (package XO-3; §26.4.2 #5, #6, #8, #9, #11; §26.4.3
#3–#8, #10, #17, #20, #21, #23–#25 are marked "→ built (XO-3 …)"). Owner decisions: D-XO7 / EXP-13 on its recommended
behaviour behind a switch that is off by default; EXP-1 (a returned report discards the other approvers' line decisions) kept as
built. Every default reproduces the earlier behaviour: with `expense.review.suspect_button` off nobody is offered "Suspected",
a `suspected` flag sent to `approvePart` is ignored, decisions, totals, advances and payment are computed exactly as before; the
employee's own register searches and filters as before (the new filters, columns and "Download documents" are on the HR side,
`ExpenseReportResource::oversees()`); money still reaches payroll only through `ExpenseReportInput` and the F&F through the
settlement source.

**The review (report page).** `Filament\Support\ExpenseReview` (UI helpers of the package) and
`Expense\Support\ExpenseReportSummary` (pure sums over the report's lines):
- Report section: "Created on", "Trips" (the report's `expense_report_travel` trips, linked where the viewer may open them).
- "Distance" (shown when the report has mileage lines): requested = Σ `distance_km`; approved = Σ over decided mileage lines of
  approved ÷ rate per km (base amount ÷ distance), capped at the distance.
- "Decision" (once a line is decided or held): approved, repaid to the employee (reimbursable lines) / paid by the company,
  rejected (claimable − approved of decided lines, held lines apart), held as suspected (amount and count), receipts in the
  report. Payment section: "Paid receipts" (lines with an approved amount once paid).
- "All files": every file of the lines the viewer may open, through the signed, audited `ParityFileController` route.
- History tab: `AuditHistoryRelationManager` on the report (`audit.view`, read-only).
- "Print summary": `ExpenseReportPrintController` (`expense.reports.print`, 30-minute signed link, the protected chain, the
  report's `view` policy, EXPORT audit; view `expense/report-print`) — lines, approvals, amounts, payment (for the employee only
  with `expense.payment.show_to_employee`), independent of the cover-letter switch; the browser prints / saves as PDF.
- Lines (`LinesRelationManager`): number / date / type / distance sortable, Type and Distance (km) columns, an "Expense type"
  filter (HROne's Amount / Mileage tabs), "Open" (the XO-2 receipt page), "Columns" (HR side): up to
  `peopleos.expense.review_columns_max` = 5 more receipt fields — purpose, invoice, from / to, where, participants, description,
  supplier GSTIN, receipt-currency amount, limit, over the limit, repaid to the employee, exceptional reason, trip, filed on and
  every custom field of `expense_receipt` (`cf:<key>`) — kept per person in `ExperiencePreferences` (new key
  `expense_review_columns`, normalised and capped there too; presentation only, not audited); "Limit exceeded" over five.
- Receipt page (`ViewExpenseReceipt`): "Previous receipt" / "Next receipt" within its report (expense date, then filing order;
  among the receipts the viewer may open, through the resource's scoped query and `view`), "Receipt n of N" and "Back to report".
  Navigation only.

**Register (HR side).** Number search also finds the report holding a receipt number (`ExpenseReview::numberSearch`, inside the
access scope); columns "Created on" and "Payment" (hidden until chosen); filters "Payment status" (waiting, scheduled for payroll,
paid outside payroll, paid with payroll, paid in the F&F, nothing to pay — the `paymentStatus()` rules, multi-select), "Period"
(first-to-last expense overlapping the dates), "Created" (date range) and, while the Suspected decision is offered, "Expenses held
as suspected". "Report" carries the payment status and period to the expense reports dataset (`payment_status`, `period_from` /
`period_to`).

**Download documents (§26.4.2 #11; the WO-13 `LetterDownloads` pattern).** Table bulk "Download documents" (rows chosen) and
header "Download documents" (every report matching the register's filters or search; refused un-narrowed), each asking which
documents: receipt files and / or cover letters (only while `expense.report.cover_letter` is on).
`Expense\Services\ExpenseDocumentDownloads::request()` (`expense.view` / `approve` / `pay`; at most
`peopleos.expense.documents.bulk_max` = 200 reports) records an `expense_document_downloads` row and queues
`Expense\Jobs\BuildExpenseDocumentDownload` (3 tries, 900 s, backoff 60 / 300, tenant id + `BindTenantContext`; claimed queued →
running; `failed()` marks it failed). The job runs as the person (`ScreenAccess::as`): only reports and receipts their scoped query
returns and `view` allows, and only files kept under the tenant's own folder on the documents disk, go in (one folder per report);
each receipt file is audited ATTACHMENT_DOWNLOADED (purpose `bulk_download`), each cover letter DOWNLOAD by
`ExpenseCoverLetters`; reports with nothing to include are counted as skipped. The zip lives at
`tenants/{id}/expense/downloads` for `peopleos.expense.documents.download_hours` = 24, the person is notified with a signed link
(`expense.documents.zip`, `ExpenseDocumentDownloadController`, the protected chain) only they can use (audited DOWNLOAD);
`peopleos:expense:expire` (daily) removes expired zips, the row stays.

**Suspected (owner decision D-XO7 / EXP-13).** Setup → Expense `expense.review.suspect_button` (default false). When on, the
approve dialog offers "Suspected" per line (its note required); `ExpenseReports::approvePart` takes `[suspected => true, note]`:
the line is held out — approved at 0, status `suspected` ("Held as suspected"), `suspected_at` / `suspected_by` /
`suspicion_note`; a part (and a report) whose only approved lines are held is partly approved, not rejected (the rejection-reason
rule applies only when a non-held line is set to 0); the part's audit event carries `held_as_suspected`. "Clear suspicion" on the
line (someone who may decide its part, never their own; works even if the switch was turned off meanwhile) calls
`ExpenseReports::clearSuspicion($receipt, $amount, $note)`: approve up to the claimable (blank = in full) or reject (0), the note
becomes the line's approver note, `suspicion_cleared_at` / `_by` stamped; the part's total and status follow; a decided report
follows too (`resettle`: totals, status, advances settled against what the line adds — `ExpenseAdvances::adjustForReport` gained an
optional entry key, `report-{id}-receipt-{line}`, so the extra settlement is posted once —, net payable and, without a payment
step, the payroll schedule). Releasing an amount is refused once the report is paid or while a payroll run or a final settlement
carries it (`ExpenseCommitments`); rejecting is always possible. Audited APPROVED / REJECTED on the report with
`suspicion: cleared`. EXP-1 as built: `returnReport` (and `submit`, `cancel`, `removeReceipt`) clear the suspicion columns with
the other line decisions. Receipt status `suspected` counts against limits and frequencies like a reported line
(`ExpenseReceipt::COUNTING`), is "Pending" in My requests (`ExpenseReceiptRequestType`) and is listed (never matched while the
switch is off) in the receipt register's status filter.

**Data, permissions, settings.** Migration `2026_12_25_200001_expense_report_review_and_documents` (`expense_receipts`:
`suspected_at`, `suspected_by`, `suspicion_note`, `suspicion_cleared_at`, `suspicion_cleared_by` — foreign keys named
explicitly; new `expense_document_downloads`). `DataInventory`: `expense_document_downloads` under `expense`, its zip as a file
(`file_path` / `file_disk`). Model `Expense\Models\ExpenseDocumentDownload` (tenant-scoped, audited; status moves written without
automatic auditing). No new permission key. Setting `expense.review.suspect_button` (false; Setup → Expense); technical limits
`peopleos.expense.documents.{bulk_max, download_hours}`, `peopleos.expense.review_columns_max`. Routes `expense.documents.zip`,
`expense.reports.print`. Job listed in `docs/operations/queue-and-scheduler.md`.

**Tests.** `tests/Feature/HroneParity/ExpenseReportReviewTest.php` (defaults: no Suspected on the screen or in the service, the
employee's own register and lines unchanged; trips, distance and the split / rejected / paid receipts on the page; the printable
summary — signature, view policy, audit, payment visibility; the column picker — per person, custom fields, ≤ 5; next / previous
receipt and the type filter; the History tab and the all-files panel; receipt-number search, payment / period / created filters
and their report conditions, inside the scope; the zip — scope, tenant folder, audit per file, requester-only signed link, cover
letters, expiry; register bulk and all-matching requests; Suspected held out and cleared through the screens; paid and
payroll-carried reports untouched; all lines held is not a rejection; advances settled once more and the payroll schedule
without a payment step; EXP-1 kept and a suspicion cleared while another part waits).

Not built here: payment mode / cheque details (XO-4), the attendance day drawer from a receipt (RQ-3), projects (PM-10), a
supplementary payment for a held line released after its report was paid (open point), held lines as Approval Center items.

## GO-3 Bulk normalisation and team distribution

Matrix: `docs/hrone-parity/modules/27-performance-engage-operations.md` (package GO-3: §27.1 #6 — the normalise grid's
population; §27.3.4 #2–4 with the action index row "Normalize performance rating" (#1, #3–5) and "Normalize review rating";
§27.12 #3), rows marked "→ built (GO-3 …)". Every default reproduces the earlier behaviour: normalising stays HR's
(`performance.calibrate`), the board's per-row "Calibrate" / "Finalize" / "Publish rating" and `Appraisals::calibrate` are
unchanged, the distribution and the 9-box read the rating that stands as before, periodic reviews roll up their reviewer's
rating until HR normalises one. New: two header actions on the calibration board, a toggleable column there (hidden by
default), a read-only "Team distribution" page for managers, a "Normalised" column on the appraisal's Periodic reviews tab.

**Normalise grid (§27.3.4 #3–4; HROne "Normalize performance rating").** `Domain\Performance\Services\RatingNormalisation`:
- `refusal($appraisal, $actor)` — who may normalise whom: performance HR (`performance.calibrate`) within their access scope
  (`AccessScopes::allows`), or — only while `performance.normalise.by_managers` is on (below) — a manager for the people
  they manage; never one's own rating; withdrawn (GO-2), finalized and locked appraisals refused; a manager also only where
  the performance policy shows them the rating (`AppraisalVisibility::ratingsVisible`).
- `normalise($appraisal, $rating, $reason, $actor, $expected)` — the refusal, then `Appraisals::calibrate`, which keeps the
  calibration rules unchanged: the calibration stage must have opened, the rating lies on the cycle's scale, finalized /
  locked appraisals are refused again under the row lock, a rating someone else changed since the grid was opened
  (`$expected`) is refused, and every change writes its `calibration_adjustments` history row and audit event with the
  reason. "Never one's own" is enforced here, not in `calibrate` (today's per-row "Calibrate" does not refuse it — open point).
- `apply($cycle, [appraisal id => rating, expected], $reason, $actor)` — one reason for the batch (required, ≤ 500); each row
  on its own (refusals reported with their reason, the rest done) inside one audit operation "Normalise ratings: <cycle>"
  (BULK_OPERATION summary with the reason; each appraisal's events carry its operation id); returns the distribution
  check's warning (HP-8g `warning()`, warn / block) once something changed — normalising is never blocked.
- `grid($cycle, $employeeIds, $actor)` — one row per appraisal the person may normalise: employee name, code, the
  system-calculated rating (`computed_rating`), the normalised rating so far (`calibrated_rating`) and the rating seen;
  alphabetical (never by rating); people left out counted by reason; withdrawn appraisals are not part of it.

Screens: `Filament\Support\NormaliseActions::ratings()` — the board's header action "Normalise ratings" (running cycle,
`performance.calibrate`), a step wizard: **Who** (`PerformanceCycleActions::population`, the WO-2 picker of GO-2 —
"Everyone on the board" (the board's own department / grade filter applies), chosen employees found with the shared search
(HROne's "Search team member"), an organisation unit (company … location, departments with their sub-departments) or
pasted codes — among the board's population in the viewer's access scope, §27.1 #6); **Ratings** (a table repeater: Employee
name, Employee code, System calculated rating, Normalised rating, New normalised rating; at most
`peopleos.performance.normalise_grid_max` = 500 rows — narrow the people otherwise); **Reason** (one reason, the number of
changes). Only rows whose rating changes are sent, and the action re-derives the allowed appraisals from the choice on the
server (the grid is never trusted). Up to `peopleos.bulk.confirm_over` (25) changes are applied at once (notifications: the
count, each refusal with the person, the distribution warning); more run as a WO-1 bulk run — operation
`appraisals.normalise` (`Experience\BulkOperations\NormaliseRatingsOperation`, `performance.calibrate` + `bulk.run`, the typed
count, the ratings and seen ratings as the run's input — kept out of the audit trail —, one result per appraisal on People →
Bulk runs). The board also gained the toggleable column "Last normalised by" (the actor of the latest calibration
adjustment), so HR sees who changed a rating, including a manager's change.

**Review-rating normalisation per review period (§27.3.4 #2; HROne "Normalize review rating").** HP-8i's periodic reviews
keep the reviewer's rating; migration `2026_12_26_200001_add_normalisation_to_performance_period_reviews` adds
`normalised_rating`, `normalisation_note`, `normalised_by` (FK `perf_period_reviews_normalised_by_fk`) and `normalised_at`.
`PerformancePeriodReview::effectiveRating()` = normalised, else the reviewer's; the model guard still makes a submitted
review read-only except those four columns (`NORMALISATION_COLUMNS`); the note is a sensitive audit value.
`PerformancePolicyRules::rollUp` reads `effectiveRating()`, so a normalised review counts with its normalised rating in the
policy's average / last / custom roll-up. `PeriodicReviews`: `normaliseRefusal()` (performance.calibrate, the access scope,
never about oneself, only a submitted review, the appraisal not withdrawn / finalized / locked), `normalise()` (reason ≤ 500;
under the appraisal then the review row lock; the rating seen; the cycle's scale up to the policy's maximum; audited with the
reason; the appraisal scored again), `normaliseBatch($cycle, $sequence, …)` (one audit operation "Normalise review ratings:
<cycle> #<n>"), `periodsToNormalise()`, `normaliseGrid()`. Board header action "Normalise review ratings" (shown when the cycle
has submitted periodic reviews): **Review period** ("Review cycle" + Who) → **Ratings** (Review rating, Normalised rating, New
normalised rating) → **Reason**; above the bulk threshold a WO-1 run `period_reviews.normalise`
(`NormaliseReviewRatingsOperation`). The appraisal's Periodic reviews tab shows "Normalised" next to the rating, to whom the
rating shows. Normalising review ratings is HR's only (the manager setting covers performance ratings).

**Team distribution (§27.12 #3; HROne Team → Analytics → Performance).** `PerformanceDistribution::compare()` gained an
optional third argument (which rating of each item to place; null = the rating that stands, as before) and `team($cycle,
$viewer)`: the manager's own team (`PerformanceRelationships::teamOf`, `performance.team`, never the viewer), withdrawn
appraisals left out; two curves per level against the targets — the system-calculated rating and the rating that stands
(final, else normalised, else system); only appraisals whose ratings the performance policy lets the manager see are counted
(so a rating kept from the manager cannot be worked out), the others counted as "not counted"; each curve suppressed below
`peopleos.performance.analytics_min_group` (counts and shares hidden, as analytics); names only of the people counted,
alphabetically and never with a rating or a level (no rankings or named top / bottom lists — owner decision ANA-1).
Page `Filament\Pages\TeamRatingDistribution` (Performance → Team distribution, `/admin/team-distribution`,
`performance.team`): cycle picker (cycles with the team's appraisals, latest first), the two curves as cards and a grouped
column chart, the counted names; read-only.

**Manager normalisation (owner decision PER-2 / D-GO2, open — default "Off").** New Setup → Performance setting
`performance.normalise.by_managers` (false). Off: as today, normalising is `performance.calibrate`'s and the team page only
reads. On: a manager (`performance.team`) may normalise the people they manage, with a reason, through the same
`RatingNormalisation::normalise` → `Appraisals::calibrate` path — never their own, never someone else's report, never a
finalized / withdrawn appraisal, never a rating the policy keeps from them — from "Normalise my team's ratings" on the team
page (the same wizard over the team; applied at once, no bulk run, so no `bulk.run` is needed). HR sees each change ("Last
normalised by" on the board, the calibration note, the appraisal's audit history) and the distribution check still applies
(its warning after the batch; `block` still stops the cycle closing). Publishing by managers is not built (GO-19).

**Data, permissions, settings.** Migration `2026_12_26_200001_add_normalisation_to_performance_period_reviews` (columns
only; `DataInventory` notes them under performance, none secret). No new permission key (`performance.calibrate`,
`performance.team`, `bulk.run`). New setting `performance.normalise.by_managers` (false) in `config/peopleos.php` and Setup →
Performance; new config `peopleos.performance.normalise_grid_max` (500). Bulk operations `appraisals.normalise`,
`period_reviews.normalise` tagged `peopleos.bulk_operations` (`ParityServiceProvider`). No queued job of its own (WO-1's
`RunBulkOperation`), no command, no notification event.

**Tests.** `tests/Feature/HroneParity/PerformanceNormalisationTest.php` (defaults: setting off, managers refused, the team page
read-only, per-row calibrate and the distribution unchanged; the grid through calibrate with one reason, history rows, the
audit operation and the grid's rows; refusals per row — finalized, withdrawn, one's own, changed meanwhile, not of this cycle,
out of scope, locked — with the rest done; the batch reason; the population by codes, organisation unit, named employees and
the board's own filter; walking the wizard (the grid filled from the people chosen, people left out with the reason, a stop
when nobody is left); a large batch as a bulk run with the typed count and per-row results; the team distribution —
manager's team only, system against final, suppression below the minimum group, nobody counted or named when the policy
hides ratings, the page and its access; periodic review normalisation — the reviewer's rating kept, the roll-up, the audit
reason, refusals, read-only otherwise, the batch and the board wizard; the manager setting on — own reports only, refusals,
"Last normalised by", the distribution warning, the team page's wizard, the policy's visibility — and off again).

Not built here: a "Review bell curve" (distribution of periodic review ratings per period, §27.12 #1) as its own chart —
`compare()` can now place periodic review ratings, the screen is not built; normalise / review-rating imports (GO-10);
publishing by managers and virtual teams (GO-19); a per-row "Normalise" on the Periodic reviews tab (the board's wizard
covers one or many); refusing one's own rating in the existing per-row "Calibrate" action (unchanged by design — open
point).

## XO-4 Pay expenses depth

Matrix: `docs/hrone-parity/modules/26-expense-asset-helpdesk-operations.md` (package XO-4; §26.4.4 #2, #3, #5, #6, #9–#12, #16,
#18, #19 and §26.4.3 #22 are marked "→ built (XO-4 …)"). Owner decisions: D-XO1 / EXP-7 on its recommended behaviour (any open
month not yet finalized; blank = the next payroll), EXP-5 as built (the payment step stays on), EXP-15 as built by XO-3 (a held
line released after payment can only be rejected — see below). Every default reproduces the earlier behaviour: the page opens on
"To pay" with the same reports and actions; "Pay with payroll" without a month schedules for the next payroll exactly as before
(`payment_scheduled_for` stays null and `ExpenseReportInput` applies the old rule); "Record payment" with only a reference and a
date records exactly what it recorded before (mode, account, cheque and note stay null); no permission key, setting or job.

**The page (`Filament\Pages\PayExpenses`, `expense.pay`).** Now a `RegisterTablePage` with WO-1 `HasRegisterTools` (register key
`pay_expenses`): saved views (a view keeps its tab, stored as the view's `lens`), presets (waiting, scheduled for payroll,
exceptional reports, by employee, paid; `?view=`), "Report" (`expense_payment_register`) and "Download CSV" (the tab with its
filters and search, at most `peopleos.bulk.max_records` rows, formula-injection safe, audited EXPORT in module `expense` with the
tab, row count and filters used). Tabs (`?tab=`), the filters staying when switching:
- "To pay" (default; as before, one row per report): new columns Employee code, Reimbursable (`reimbursable_approved`) and
  Non-reimbursable (approved − reimbursable: information only, never paid to the employee); the payment column says "since …" for
  the next payroll (as before) or "<Mon YYYY> payroll", in red with "finalized without it: change the pay month" when that month
  was finalized without the report. Actions: "Pay with payroll" (optional pay month, optional note), "Change pay month", "Take off
  payroll" (optional reason), "Record payment" (two steps); bulk "Pay the selected with payroll" (month from the tenant's
  upcoming months, checked per report) and "Record one payment for the selected".
- "To pay, by employee" (§26.4.4 #2): one row per employee — a grouped `ExpenseReport` query (the row's key is the employee's
  first payable report, `selectCurrentPageOnly`, no key sort) summing the payable reports that match the filters and search:
  reports, oldest approval, approved, reimbursable, non-reimbursable, advances settled, to pay, waiting / scheduled counts. "Pay
  with payroll" schedules each report of the row not yet scheduled; "Record payment" pays each (one reference); both for the
  selected rows too; "Reports" opens "To pay" searched for the employee's code. Refusals (one's own, out of scope, carried by a
  run) are listed per report; `employeeReports()` re-reads the reports through the scoped query, the filters and the search.
- "Paid": paid reports — reimbursable / non-reimbursable, paid amount, paid on, how (channel and mode), reference, paying account,
  pay month, note, recorded by.
- Filters on every tab (the register's HR side is the whole page): XO-3's "Payment status" and "Period", "Exceptional reports" (a
  report with an expense filed as exceptional), WO-2 codes / organisation group / employee status, type and joining / pending
  resignation, and the "Approved" and "Paid on" date ranges (`ExpenseRegisters::filters`); employee search on the employee
  column, XO-3's number search (also a receipt number) on the number column.

**Pay month (owner decision D-XO1 / EXP-7).** `expense_reports.payment_scheduled_for` (date, the first day of the payroll month;
null = the next payroll). The months come through an expense-owned contract, `Expense\Contracts\PayrollMonths`, bound to
`Payroll\Inputs\ExpensePayrollMonths` in `ParityServiceProvider` (the expense domain still never imports payroll — the
architecture test is unchanged): `openFor(employee)` = the employee's company's payroll month holding today
(`PayCycle::monthContaining`) and the 11 after it, plus an earlier month whose payroll is still being prepared, never a finalized
one (closed, or a run finalized or paid), each labelled ("payroll calculated: included when it is recalculated", "payroll
approved: reopen and recalculate it to include this"); `upcoming()` (several employees at once), `isFinalized(employee, month)`,
`payingAccounts(?employee)`. It only reads `PayCycle` and `PayrollPeriod` / `PayrollRun` — no payroll month is created or
changed. `ExpensePayrollMonths::AHEAD` = 12.
- `ExpenseReports::scheduleForPayroll($report, $actor, $month = null, $note = null)` — blank keeps the old behaviour; a month must
  be "Y-m", not finalized ("The payroll of Sep 2026 is finalized: choose a month not yet finalized.") and one of `openFor()`.
- `reschedulePayroll($report, $month, $actor, $note)` — another open month or back to the next payroll (blank), audited "Payroll
  month changed: A → B"; `cancelPayrollSchedule($report, $reason, $actor)` — off payroll, back to waiting, audited. Both refuse a
  report that is not scheduled, and one a payroll run (or a final settlement) already carries ("it stays with that payroll").
- `Payroll\Inputs\ExpenseReportInput` takes a report with a month only in a computation of that month (`period.year/month`);
  without one, the old rule (`payment_scheduled_at` ≤ the period's end).
- `payMonthMissed($report)`: the chosen month was finalized without the report (its run was calculated before it was scheduled
  and not recalculated, or the employee was not paid that month). It stays scheduled for that month — no other month takes it —
  until finance changes the month, pays it outside payroll or takes it off payroll.

**Never twice.** A report is paid once: by exactly one finalized run of its month (or of the next payroll), outside payroll, or in
the final settlement. Guards (each tested): a month-scheduled report is not read by any other month's run; while a run not yet
finalized (or an unpaid settlement) carries it, `markPaid`, `reschedulePayroll`, `cancelPayrollSchedule` and a second
`scheduleForPayroll` are refused (`ExpenseCommitments`, as for EX-E); finalizing pays it through `paidThrough` (only a report
still payable and still with payroll); once paid nothing pays, moves or schedules it again; a finalized month cannot be chosen;
paying outside payroll clears the month so that month's run leaves it alone; two runs of one month cannot coexist (a closed month
is never reopened).

**Outside payment (§26.4.4 #18, #19; §26.4.3 #22).** `ExpenseReports::markPaid($report, $reference, $on, $actor, $payment = [])`:
`mode` (`ExpenseReport::PAYMENT_MODES`: bank_transfer, cheque, cash; optional — null = not recorded, as before), `account_id`
(an active paying bank account of the employee's company, BP-13; not for cash, only with a mode), `cheque_number` (≤ 30) and
`cheque_date` (required for a cheque, refused for any other mode), `note` (≤ 500). Columns `payment_mode`, `paid_from_account_id`
(foreign key `expense_reports_paid_from_account_fk`, restrict), `cheque_number`, `cheque_date`, `payment_note`, saved in the same
audited update ("Paid outside payroll: <reference>"). "Record payment" is a two-step wizard (How it was paid → Reference). The
report page's Payment section shows the pay month, the mode ("Cheque 004512 of 20 Sep 2026") and — HR side only — the paying
account and the note; the printable summary shows the mode and the pay month.

**Held lines (EXP-15).** Lines held as suspected (XO-3) are approved at 0 until cleared, so they are outside the net payable of
every payment — by payroll in any month or outside payroll — and outside the "by employee" sums. A supplementary payment for a
held line released after its report was paid was not built: the report carries a single payment (one `paid_on`, channel,
reference, run or settlement id, and `isPayable()` / `paidThrough` / the F&F source all key on it), so a second payment needs a
payment-record table, a second payroll input key with its own commitments, the settlement source and the advance settlement
against the extra amount. XO-3's behaviour is kept: once paid (or carried by a run or a settlement) a held line can only be
rejected. Open point for the owner (EXP-15 follow-up).

**Data, permissions.** Migration `2026_12_27_200001_add_payment_details_to_expense_reports` (columns above; index
`expense_reports_tenant_pay_month_idx`; the table is already classified under `expense` in `DataInventory`). No permission key
(`expense.pay` as before), no setting, no job. Shared files touched: `ParityServiceProvider` (one binding),
`ExpenseReportResource` (Payment section), `expense/report-print` view.

**Tests.** `tests/Feature/HroneParity/PayExpensesTest.php` (defaults: one row per report, the next payroll without a month,
nothing more recorded with a reference and a date, the run pays it once; by employee — totals, non-reimbursable, the period and
exceptional filters narrowing them, paying a row with payroll and outside payroll, "Reports", the audited CSV; a chosen month read
only by that month, every other way refused while its run carries it, paid once, never again; a finalized month refused, a month
finalized without the report — flagged, no other month takes it, moved and paid once; moves between months and back to the next
payroll, invalid / past / far months, take off payroll, outside payment of a month-scheduled report; outside payment fields and
every refusal, on the screen and in the service, the audit fields, the Paid tab, CSV and the report page for HR and the employee;
held lines out of payroll and outside payments, release after payment only as a rejection; access scope on every tab and the CSV,
never one's own; saved views keep the tab, presets from the URL).

Not built here: the supplementary payment of a held line released after payment (EXP-15 follow-up); a pay-month filter; the
project filter (PM-10).

## TX-5 Roster board and bulk shift assignment

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-5; §24.2.1 #1, #2, #5, #7, #9; §24.8 #8; the HR
part of §24.13 #2 — the team part is RQ-11's), rows marked "→ built (TX-5)". Every default reproduces the earlier
behaviour: the earlier roster paths ("Roster employees", "Remove", approved shift / week-off changes, the roster upload)
write the same rows with the same messages apart from one wording (see below); a rostered day without the new flag is
resolved and calculated exactly as before; no setting, no new permission key, no new table, no queued job of its own.

**Automatic shift correction on rostered days (§24.2.1 #7).** Migration `2026_12_28_200001_add_auto_shift_to_attendance_shift_overrides`
adds `attendance_shift_overrides.auto_shift` (nullable boolean; every existing row null). `ShiftOverride::allowsAutoShift()`
is true only for `true`. `ShiftResolver::resolve()` adds `'auto_shift' => true` to its answer only for such a day (the key is
absent otherwise, so every other answer has exactly the earlier keys). `AttendanceProcessor::autoShift()` (TO-5) skipped every
rostered day; it now takes part when the answer carries `auto_shift`: the candidates are the shifts the employee's work
schedule lists for correction plus the rostered shift (which wins a tie), the shift whose start is nearest the day's first
check-in is used, and weekly-off rostered days take part only when the schedule allows correction on off days — the TO-5
rule. A schedule listing no shifts for correction makes the flag inert. An approved shift / week-off change on a flagged day
drops the flag (the employee asked for a shift) and its cancellation restores it (`effects.previous_overrides` keeps
`auto_shift` only when it was set). Re-rostering a day through the earlier `ShiftRoster::assign()` without a choice clears an
earlier choice (a null column is never written, so default audits are unchanged).

**Roster service (§24.8 #8, §24.2.1 #5, #9).** `Attendance\Services\ShiftRoster`:
- `assign()` (unchanged signature, two optional trailing arguments): `$weeklyOffDays` (weekday keys `mon` … `sun`; those days
  of the range become weekly offs, the rest take the shift) and `?bool $autoShift` (null = not chosen). Defaults give exactly
  the earlier rows; the roster upload uses them.
- `assignPattern(employees, from, to, ?shift, weeklyOffDays, autoShift, reason, actor)` and `removePattern(employees, from, to,
  reason, actor)` — "Assign shift" / "Remove" with a result summary `{days, done[], refused[]}`: each employee on their own,
  refused (never stopping the others) when outside the actor's access scope (`AccessScopes::allows`), not employed on any day
  of the range (lifecycle past pre-boarding, between joining and exit — `AttendanceMonthPopulation::states()`; days outside
  the employment are skipped and the line says how many), or with a locked day or a day of a closed attendance month in the
  employed days (the lock rule as everywhere: refused unless `attendance.allow_corrections_after_lock`; with it on, the locked
  day keeps its values and the difference becomes an arrear, `AttendanceMonthCloses::assertOpen` for TX-3's closed months).
  Reason required, at most three months; the overrides are written in a transaction per employee, audited with the reason,
  in one `AuditRecorder::operation` (or inside the caller's, e.g. a bulk run's, so its events carry the run's operation id);
  past and present days are recalculated as the earlier roster paths do (`AttendanceProcessor::process`, which never
  rewrites a locked or closed day). `ShiftRoster::WEEKDAYS` / `weekdays()` normalise weekday names.
- Wording: the lock refusal of the roster and of the roster upload now reads "locked (finalized days)" instead of naming
  payroll (attendance code carries no payroll words); the message is otherwise the same and no test asserted it.

**The board (§24.2.1 #1, #2, §24.13 #2 HR part).** `Filament\Pages\RosterBoard` (Attendance → Roster board, also on the Time
office desk under Shift, in the Setup centre's Time office links and linked from Shift roster; a `RegisterTablePage` with WO-1's `HasRegisterTools`, register key
`roster_board`, report `day_roster_this_month`): employees employed on some day of the window × the days of a week (Monday
start) or a month, previous / next / today. `attendance.view` opens it, inside the viewer's access scope (the Employee query's
scope); WO-2's pasted codes, organisation group, status / type / joining and pending-resignation filters choose the people.
Cells come from `Attendance\Services\RosterGrid::cells()` for the page at once: the shift of
`ShiftResolver::resolveBetween()` — a new batch form of `resolve()` giving exactly its answer per day (rostered days,
assignments and shifts read once; schedule rules and positions only when a day falls back to them; the rule context once per
position and tenure month; a test compares it day by day with `resolve()`), the shift's code and times, weekly off, no
schedule, rostered (source, reason, automatic correction) or the schedule's own correction, the holiday
(`HolidayResolver::holidayOn` for the candidate days of the calendars at the window's ends), approved leave
(`LeaveDayResolver::approvedLeaveBetween`) and leave waiting for a decision (`PendingLeaveReader`, TX-3) — leave only through
the attendance contracts —, locked days, days of a closed month, the processed status and shift, and days not employed. Each
cell's tooltip says all of it; the row action "Calendar" opens the employee's attendance calendar for the month.
- "Edit day" (a cell, `attendance.roster`): work a shift (with or without automatic correction), a weekly off, or back to the
  work schedule — through `assignPattern` / `removePattern` for that one day; the result shows above the board.
- "Assign shift" / "Remove rostered days" on the selected employees (`attendance.roster`): a wizard (the days, the shift, the
  weekly-off weekdays and the correction choice; then a review with the reason). Up to `peopleos.bulk.confirm_over` (25)
  employees run at once through `ShiftRoster` and the page shows each employee done or refused with the reason; larger
  selections start a WO-1 bulk run (`bulk.run` too, typed count): operations `roster_board.assign_shift`
  (`Experience\BulkOperations\AssignShiftOperation`) and `roster_board.remove_shift` (`RemoveShiftOperation`), each employee
  through the same service, refusals listed in People → Bulk runs.
- The Shift roster register gained a "Roster board" link and a hidden "Auto shift" column; "Roster employees" is unchanged.

**Roster upload (§24.9 #3 columns).** `ShiftRosterImports` gained two optional columns: "Weekly off on" (weekday names
separated by `;`, `,`, spaces or `/`; invalid names are a row error) and "Auto shift correction" (yes / no; anything else is a
row error); they go to `ShiftRoster::assign()`. A file without them, or with them blank, imports exactly as before (same rows,
same result line). The template and format guide list the two columns.

**Dataset (§24.2.1 #12, matrix 21 §21.2.2 Time Office #36 — shared with AN-28).** `day_roster`
(`Analytics\Datasets\DayRosterDataset`, category Attendance, `attendance.view`, the reader's scope through the rostered days'
`AccessScope`; not on `analytics.unattended_datasets`, so no run without a signed-in user): one row per rostered day —
employee fields, date, day, month, shift code (`WO` for a weekly off), shift, shift timings, weekly off, automatic shift
correction, source, shift change request, reason, changed by, changed on. Templates "Shift roster by day (this month)" and
"Shift roster changes (last 30 days)". Days that follow the work schedule stay in `shift_roster` (assignments).

**Data, permissions, wiring.** Migration `2026_12_28_200001` (a column only; `attendance_shift_overrides` is already classified
under attendance, nothing new for `DataInventory`). No new permission key: `attendance.view`, TX-2's `attendance.roster`
(granted also by `attendance.manage`) and `bulk.run` for bulk runs. `ParityServiceProvider` tags the two bulk operations.

**Tests.** `tests/Feature/HroneParity/RosterBoardTest.php` (defaults: earlier paths store no choice, resolver keys unchanged,
no audit of the new column, a rostered day keeps its shift under the schedule's correction, re-rostering drops a board
choice; weekday names; the board's cells — resolved shift and times, leave approved and waiting, holiday, weekly off,
rostered with source and correction, locked, not employed — and the page with week / month / next and the read-only view;
`resolveBetween` equal to `resolve` day by day over assignments, an inactive schedule, a rule with a two-week rotation,
rostered days and no schedule; "Edit day" shift / weekly off / back to the schedule with recalculation, required reason and a
day outside the scope; "Assign shift" for a range with weekly-off weekdays and one audited operation; automatic correction
honoured on flagged days only, the rostered shift as a candidate, shift change requests dropping and restoring the flag;
remove with recalculation; refusals — locked (and accepted with corrections after lock, the locked day unchanged), closed
month, outside the scope, pre-boarding, left, a joiner's employed days only; large selections as a bulk run with typed count,
the run's operation id on every event, and refused without `bulk.run`; the upload with and without the new columns and its
row errors; the dataset, templates, rights, scope and no unattended run; desk and register entries; no payroll words in the
TX-5 attendance code).

Not built here: the team "Assign shift" for managers (RQ-11 on `ShiftRoster::assignPattern`, `team.shift_assign`), the HR day
drawer from a board cell (TX-7), employee-specific holiday changes on the board (TX-12; the board reads `HolidayResolver`), a
pivoted month roster report (AN-24 over `day_roster`), rostering more than three months at once, and storing a weekly-off
pattern as such (the pattern becomes rostered days).

## MS-4 Person data additions

Matrix: `docs/hrone-parity/modules/22-my-space-self-profile.md` (package MS-4; rows 22.2.1 #1, #9, #10; 22.2.2 #3, #4, #7;
22.2.3 #8–10, #12–14; 22.2.4 #1, #4, #6, #7; 22.2.8 #3, #4 are marked "→ built (MS-4)"). Owner decisions relied on: SSE-4
(identity documents always need approval, like bank details and identifiers), PRV-1 / D-MS1 still open (own numbers stay hidden
by default), D-MS8 / SSE-3 (date of marriage stays day / month — not touched), BP-10 (the 10(10) / 10(10AA) limits are not
verified, so the previous employer's exemptions are kept but not counted). Every default reproduces the earlier behaviour: no
salutation, not a fresher, no device user id, no country code (phones kept as typed), no identity document, no nomination, no
highest qualification, nothing new editable on My profile, and the tax computed exactly as before.

**Person and employee fields.** Migration `2026_12_29_200001_add_person_data_additions`:
- `people.salutation` (option list `salutations` — Mr, Ms, Mrs, Miss, Mx, Dr, Prof, tenant-extendable through `OptionLists`; no
  full stop because letters write `{{ employee.salutation }}. {{ employee.name }}`). Letter variable `employee.salutation` now
  prefers the recorded salutation (`Person::salutationLabel()`); blank = Mr / Ms / Mx from the gender, as before.
- `people.is_fresher` (default false; `Person::isFresher()`): a fresher has no earlier employment — `PersonExperience` refuses a
  new row while it is set, and the person refuses the flag while experience rows exist (`ProfileChangeRefused`; the HR toggle is
  disabled then, the Previous employment "Create" is disabled for a fresher).
- `employees.device_user_id` (≤ 64, unique per tenant: index `employees_tenant_device_user_unique`; blank = null; form check with
  `TenantUnique`): `PunchIngestion::resolveEmployee($code)` tries it before the employee code for device pushes (`ingest`) and
  for retries of failed punches (`retry`). While no employee of the tenant has one, the lookup is the earlier code lookup with no
  extra query (memoised per run); the "Unknown employee code […]" evidence is unchanged. The API punch endpoint still names
  employees by code.
- Phones in E.164 (`App\Domain\People\Support\PhoneNumbers`, rule `E164Phone`): `people.personal_phone_country_code`,
  `employees.work_phone_country_code`, `person_emergency_contacts.phone_country_code` (the alternate phone stays as typed). With
  a code the number (typed nationally, with spaces / dashes / brackets / trunk 0, or as "+code…" / "00code…") must make
  `+<code><number>` of 7–15 digits and is stored so by the models' `saving` hooks (every write path: forms, hire wizard,
  imports, actions, self-service); without a code it is kept exactly as typed. The country-code select lists
  `peopleos.people.phone_country_codes` (ITU calling codes; any 1–3 digit code is accepted from imports).
- `person_family_members.in_school`, `in_hostel` (default false) and `remarks` (≤ 255) — read by no claim rule (no amount coded).
- `person_qualifications.is_highest` (one per person: marking one clears the others, each change audited; `Person::highestQualification()`),
  `mode` (option list `qualification_modes`), `year_started` (not after the year of passing).

**Passport and driving licence (`person_identity_documents`, migration `2026_12_29_200002`).** `PersonIdentityDocument` (People;
tenant-scoped, audited): kind (`peopleos.people.identity_document_kinds`: passport, driving licence; one per kind and person,
unique `person_identity_documents_person_kind_unique`), `number` (encrypted cast, hidden, masked in the audit trail; `number_last4`
kept apart and excluded from the audit), issued / expiry dates, issuing country, place of issue. Highly sensitive in
`peopleos.data_classification`. Written only by `App\Domain\People\Actions\ChangeIdentityDocumentAction` (add / update / remove;
`employee.sensitive.update` within organisation scope whatever the caller; a blank number on update keeps it; the expiry may not
precede the issue; timeline category `statutory`; event `employee.identity_document_changed` without the number). CA-4: linked
fields `passport` and `driving_licence` in `peopleos.documents.linked_fields` — a document type "required with" the kind must be
on file before a number is entered or changed. Employee 360: "Passport & licence" tab (`IdentityDocumentsRelationManager`) like
Bank — `viewSensitive` and the CA-6 personal section, the opening audited, numbers masked, "Reveal" with a purpose (audited).
Expiry reminders: the daily `peopleos:lifecycle:reminders` sweep emits `EmployeeReminderDue('employee.identity_document_expiring')`
(document id, kind, label, expiry date, days left — never the number) for documents expiring within `documents.expiry.reminder_days`
of employees not exited / alumni, and prints "identity documents expiring N" only when N > 0 (the earlier line is unchanged); the
event is a workflow trigger and a notification event, so tenants route it as they do the other reminders.

**Nominations (`person_nominations`, migration `2026_12_29_200003`).** `PersonNomination` (People; tenant-scoped, audited):
family member (foreign key `person_nominations_family_member_foreign`), benefit (option list `nomination_benefits`: PF, EPS,
gratuity, group insurance, ESI, superannuation, other), share (decimal 5,2), nominee address, remarks, guardian name / relation.
`ChangeNominationsAction::replace($employee, $benefit, $rows, …)` saves a benefit's set at once (`employee.update` in scope;
event `employee.nomination_changed`, timeline `personal`), after `check()`: the nominees are the person's own family members, once
per benefit; each share > 0, ≤ 100, two decimals; the shares total exactly 100 ("The shares for Provident fund total 60%: they must
total 100%."); a minor (younger than `peopleos.people.nominee_majority_age` = 18 on the family member's date of birth) needs the
guardian's name and relation; an empty set removes the benefit's nominations. A family member named on a nomination cannot be
removed ("… is a nominee: change the nominations first."). The family member flags `is_nominee` / `nominee_share` stay as they
were. Employee 360: "Nominations" tab (`NominationsRelationManager`, CA-6 family section) with "Set nominees" / "Change" per
benefit and a note naming any benefit whose shares do not total 100.

**Previous employer's exemptions (22.2.8 #3, #4).** The declaration's encrypted `previous_employer` keeps `gratuity_exempt` and
`leave_encashment_exempt` (amounts; `InvestmentDeclarations`), entered on Declare investments → Previous job. The limits are
statutory, so none is in code or config: `App\Domain\Compliance\Services\PriorExemptions` reads them only from the TDS rule
version in force through `VerifiedThresholds` (parameters `gratuity_exemption_limit`, `leave_encashment_exemption_limit`;
verified, checksum-intact, no open notice). `TaxComputer` records them on the tax basis (`previous_exemptions`: declared, counted,
limit, remaining, rule) only when declared, and never changes the monthly tax: without a verified rule they are "not counted";
with one, the remaining exemption is recorded for the payout tax (BP-10) — salary TDS exempts no gratuity or leave encashment. The
wizard says so ("These two do not change your tax …" until a verified rule carries both limits), as do its review and My profile →
Previous employer.

**Where the fields appear.** HR forms (wizards): the employee edit form and the hire wizard (salutation, fresher, phone codes,
device user id), Family (two steps: member; dependant, nominee and schooling), Education (year of starting, mode, highest),
Emergency contacts (phone code); the 360 "All details" shows salutation, device user id and "fresher" when recorded. Employee
import: five columns appended after "Manager employee code" (Salutation — code or name; Fresher — yes / no; Personal phone country
code; Work phone country code; Device user id — unique in the file and against other employees), validated, created and updated
through the same actions, exported in the same columns. Dataset `employees`: salutation, is_fresher, device_user_id,
highest_qualification, passport_expires_on and driving_licence_expires_on (both sensitive). Printable profile (WO-2): salutation,
device user id, schooling and remarks, nominations (family section), highest / mode / years, "fresher", and passport / licence
masked with the statutory block (sensitive viewers, personal section). My profile (MS-2): salutation; passport / licence kind and
dates (the number only with `employee.self_sensitive`, masked, "Show" = an audited `self_reveal` of `identity:{id}`, own record
only — PRV-1 default unchanged: hidden); family notes and nominations; highest qualification and mode; "Fresher".

**Self-service (MS-3).** New `ProfileEditSections`: `identity_documents` (Personal tab; always with approval whatever the matrix
says — SSE-4; CA-4 linked document; high risk in the Approval Center) and `nominations` (Family tab; as the tenant's matrix says).
Rows added to `peopleos.profile.self_edit_sections` and the default of `profile.self_edit.sections` (unticked = as before).
`ProfileChangeOperations` builds `identity_document` operations (a blank number keeps the one on file; one per kind; number masked
in the diff and the kept summary) and `nominations` operations (checked with `ChangeNominationsAction::check`), and applies them
through the two actions — at once only under the request's `SelfServiceGrant`, otherwise in the approver's name; rights asked:
`employee.sensitive.update`, `employee.update`. The contact, emergency contact, family and education forms carry the new fields.

**Data, permissions.** Migrations `2026_12_29_200001` … `200003`. `DataInventory`: `person_identity_documents`,
`person_nominations` under `people` (sensitive); the new columns follow their tables. No new permission key (no
`peopleos:sync-permissions` needed), no Setup setting, no queued job, no command. Config: `peopleos.people.salutations`,
`nomination_benefits`, `qualification_modes`, `identity_document_kinds`, `nominee_majority_age`, `phone_country_codes`;
`OptionLists::LISTS` (three lists); `EmploymentEvent::NAMES` (two events); workflow / notification event
`employee.identity_document_expiring`.

**Tests.** `tests/Feature/HroneParity/PersonDataAdditionsTest.php` (defaults; encrypted number, masked audit, one per kind,
renewal, CA-4 linked field; the expiry reminder without the number, exited people left out; device id first with the code
fallback unchanged, retry, uniqueness per tenant; E.164 normalisation and refusals on every model; nominations — total 100,
minors' guardians, duplicates, foreign members, replacing a set, removal refused; schooling and remarks; one highest
qualification, mode, years; fresher both ways; previous exemptions not counted without a verified rule and never changing the
monthly tax; identity documents always for approval, applied in the approver's name, no forged grant; nominations at once
through the grant; PRV-1 masking and the audited own-only reveal; import validation, creation and export; dataset fields and
sensitivity; the print; organisation scope, sensitive rights and tenant isolation).

Not built here: a claim rule reading "in school" / "in hostel" (no statutory amount coded); the exemption on gratuity / leave
encashment paid by this employer (BP-10); a document-upload slot per identity document on the 360 tab (CA-4 documents stay in
Documents); phone verification (MS-8); the API resource fields.

## TX-6 Attendance status by range and half day

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-6; §24.5.1 #4; §24.5.2 #4; §24.5.3 #6; §24.8 #9;
the HR part of §24.13 #3 — the team action is RQ-11's), rows marked "→ built (TX-6)". Every default reproduces the earlier
behaviour: an override without halves (every existing row, every override written by the earlier paths and every old
upload file) is stored and calculated exactly as before — same columns written, same audit, same record, same
calculation-basis keys, same `AttendanceDay` and summary; no setting, no new permission key, no new table, no queued job of
its own.

**A status per half (§24.5.1 #4, §24.8 #9).** Migration `2026_12_30_200001_add_half_statuses_to_attendance_overrides` adds
`attendance_overrides.first_half_status` and `second_half_status` (nullable strings; every existing row null).
`AttendanceOverride::HALF_STATUSES` — present, absent, weekly off, holiday, work from home, on duty, field duty (a "half
day" is what two different halves make); `hasHalves()`, `halvesLabel()`. `AttendanceOverrides::set()` takes two optional
trailing arguments (`$firstHalf`, `$secondHalf`); `AttendanceOverrides::statuses()` checks them: both halves or neither,
never with a whole-day status, two equal halves stored as that whole-day status (so a day set by half always has two
different halves). The half columns are written only when given or when an earlier override of the day had them (a day set
by half and set again for the whole day loses its halves), so the default write and its audit are unchanged; the
`attendance.override_set` event carries the halves only when set.

**The calculation.** `AttendanceProcessor::applyOverride()` — when the override has halves — gives the day the status the
monthly attendance upload gives a split mark (`AttendanceMark::combine`): two absent halves absent, one absent half a half
day, otherwise the half that says the most about the day (present, then work from home, on duty, holiday, weekly off), with
no exceptions and no late / early minutes, like a whole-day status. Approved full-day leave still wins (the override is not
read). With approved half-day leave the leave keeps its half and the other half decides, as `AttendanceMark::statusOn` reads
it: absent there leaves the day as leave (or unpaid leave), anything else names the day, and the record keeps
`is_half_day_leave`. The record's `calculation_basis` gains `half_statuses` (`first_half`, `second_half`) only on such a day
(the basis is not audited), and `AttendanceDay::adjustments` carries it then — absent otherwise, so the payroll-facing day is
unchanged by default; payroll reads the day's status as before (a half day is half a day of loss of pay).

**Paid days agree (TX-4).** The derived status keeps `AttendanceOutput::summary()` (paid statuses less half a day per half
day), `AttendanceDay::paidQuantity()` (arrears) and payroll's reading consistent by construction. `PaidDayTally::fromDays()`
counts each half of a day set by half (no half-day leave) in its own category — a worked half as present, a weekly-off or
holiday half as such, an absent half as absent — half a day each, so the categories add up to the summary's paid days and
"a weekly off and an absent half" shows half a weekly off, not half a day present. Days with half-day leave keep TX-4's
reading (open point TIM-11 is untouched). The paid-days register (`PaidDaysRegister::figures`) reads the tally, so it agrees.

**Change attendance status (§24.8 #9, §24.5.2 #4, §24.13 #3 HR part).** `AttendanceOverrides::setRange(employees, from, to,
status, firstHalf, secondHalf, reason, actor, ?workedMinutes)` — TX-5's range / refusal / result-summary shape
`{days, done[], refused[]}` (refusal rows add `dates`): the range runs forward, ends today at the latest and covers at most
`AttendanceOverrides::MAX_RANGE_DAYS` (31) days; reason required (500 characters); the actor needs `attendance.override`.
Each employee on their own: refused when outside the actor's access scope (`AccessScopes::allows`), for one's own attendance,
or when not employed at all (lifecycle before joining); days before joining or after leaving, locked days and days of a
closed attendance month (TX-3 `AttendanceMonthCloses::closedDates`; both only while `attendance.allow_corrections_after_lock`
is off — with it on, the locked or closed day keeps its values and the difference becomes an arrear, as for every override)
are listed as refused, consecutive days of one reason as one line ("05 Oct – 06 Oct 2026: Not employed on this day (joins 07
Oct 2026)."); every other day goes through `set()` (its own checks, audit and recalculation; a refusal there is listed for
that day). The done line counts the days changed, the days approved full-day leave keeps and the days refused. One
`AuditRecorder::operation` (or the caller's, inside a bulk run).

**The page.** `Filament\Pages\AttendanceStatusChange` (Attendance → Change attendance status; also on the Time office desk
under Attendance, in the Setup centre's Time office links, and linked from Attendance records and Day overrides; a
`RegisterTablePage` with WO-1's `HasRegisterTools`, register key `attendance_status`; `attendance.view` with
`attendance.override`): employees who have joined and are employed, or left within the last year, inside the viewer's
access scope, with WO-2's pasted codes, organisation group, status / type / joining and pending-resignation filters; row
action "Calendar". "Change attendance status" on the selected employees is a step wizard (Days and status: From, To, the
whole day or each half, the status(es); Review: what happens, the reason, the typed count above `peopleos.bulk.confirm_over`).
Up to 25 employees run at once through `setRange` and the page shows each employee changed and each refusal with its days;
larger selections start a WO-1 bulk run (`bulk.run` too): operation `attendance_status.change`
(`Experience\BulkOperations\ChangeAttendanceStatusOperation`, model Employee, `attendance.override`; own attendance refused
up front; each employee through `setRange`, a partly refused employee is "done" with the refused days in the message, an
employee with nothing changed is refused with them).

**Row "Edit" (§24.5.1 #4).** `AttendanceActions::forRecords()` (Attendance records, the exception centre, Employee 360) gains
"Edit": the same status fields for one day (`ChangeAttendanceStatusOperation::statusFields(true)`: the whole day — blank keeps
the calculated status — or each half, and worked minutes), opened with the day's override, through `setRange` for that day
(same refusals). Offered on days the viewer may override (`AttendanceRecordPolicy::override`, in scope), never on their own
day, and on locked days only with corrections after lock. Day overrides' "Override a day" uses the same fields (the whole day
is the default, so it behaves as before) and the register shows a "By half" column.

**Upload (§24.9 #6 note).** `DayOverrideImports` gained two optional columns, "First half" and "Second half" (aliases
`first_half_status`, `second_half_status`; values as statuses, spaces read as underscores): both or neither, never with a
"Status" (row errors). A file without them, or with them blank, imports exactly as before (same rows, same result line). The
template, format guide and sample list them.

**Manager / HR windows (setup TO-6).** The setup re-verification (`docs/hrone-parity/setup/13-setup-reverification.md`, TO-6:
backdated / future marking by managers and HR, days after the date, hours before shift end) is not built (SV-5 Pending; no
such field exists), so TX-6 applies no window; when SV-5 adds them, `setRange()` / `set()` is the place to enforce them.

**Wording.** The override's lock refusal and the override upload's lock row error now read "locked (finalized days)" instead
of naming payroll (TX-5's precedent; no test asserted the old text).

**Data, permissions, wiring.** Migration `2026_12_30_200001` (two columns; `attendance_overrides` is already classified under
attendance in `DataInventory`). No new permission key: `attendance.override` (TX-2; `attendance.manage` still grants it),
`attendance.view` and `bulk.run`. `ParityServiceProvider` tags the bulk operation.

**Tests.** `tests/Feature/HroneParity/AttendanceHalfDayStatusTest.php` (defaults: whole-day and worked-minutes overrides,
basis keys, `AttendanceDay` keys, summary, no half columns written or audited, an override row written the earlier way gives
the same day; every combination of halves, equal halves, refused inputs, clearing halves; full-day and half-day leave; the
summary, the categories, the register and the paid quantity in agreement; the range with locked (and corrections after
lock → arrear), closed month, joiner / leaver / pre-boarding, own attendance, range and input refusals, one audit operation,
leave kept; the page direct and as a bulk run with the typed count, the run's operation id and without `bulk.run`; access
and scope; row Edit by half and whole day, hidden on locked / own / without the right; "Override a day" by half; the upload
with and without the half columns and its row errors; reprocessing and clearing; desk, setup and register links; no payroll
words in TX-6 code).

Not built here: the team "Attendance status" action for managers (RQ-11 on `setRange`), the manager / HR marking windows
(SV-5), a bulk "change status" on selected attendance-record rows of Run attendance (its bulk list is pinned; the page and
"Edit" cover it), the HR day drawer (TX-7), and ranges longer than 31 days.

## MS-5 Health insurance

Matrix: `docs/hrone-parity/modules/22-my-space-self-profile.md` (package MS-5; rows 22.2.3 #3 and 22.2.5 #1–7 are marked "→ built
(MS-5)", 22.2.5 #8 "→ built (MS-3 + MS-5)"). Owner decisions relied on: PRV-1 / D-MS1 still open (own member ids stay hidden by
default, as own identifiers and bank numbers), SSE-1 / D-MS2 open (the new self-service section is off until the tenant ticks it,
then as its matrix says — default "needs approval"). No premium, tax, deduction or other statutory amount is computed or stored.
Every default reproduces the earlier behaviour: no policy, nothing editable, the profile-access row ticked for everyone, no new
My profile tab (it appears only once the person has a policy, may add one, or has a change of it waiting), no new line in the
daily sweep, no new permission.

**Data (migration `2026_12_31_200001_create_health_insurance_tables`).**
- `employee_insurance_policies` (`App\Domain\People\Models\InsurancePolicy`; tenant-scoped, access-scoped by employee, audited):
  employee, `insurer_name`, `insurer_code`, `policy_number` (one per employee: unique `emp_insurance_policies_employee_policy_unique`;
  codes and numbers stored in capitals), `sum_assured` (decimal 14,2), `issued_on`, `valid_until` (index
  `emp_insurance_policies_valid_until_index`; not before the issue date), `ecard_path` / `ecard_name` (the e-card file).
- `insured_members` (`InsuredMember`; tenant- and access-scoped, audited): policy, employee (repeated so organisation scope
  applies), `family_member_id` (null = the employee), `member_key` ("self" / "family:{id}", unique per policy:
  `insured_members_policy_member_unique`), `name` and `relation` (from the person or the family member), `date_of_birth` and
  `gender` (given here, else from the profile), `member_id` (the insurer's member id: `encrypted` cast, hidden, masked in the audit
  trail; `member_id_last4` kept apart and excluded from the audit; highly sensitive in `peopleos.data_classification`),
  `is_parents_cover` (the member is under the parents' cover — matrix 22.2.3 #3; family members only).
- Foreign keys named explicitly (`emp_insurance_policies_employee_foreign`, `insured_members_policy_foreign`,
  `insured_members_employee_foreign`, `insured_members_family_member_foreign`). A family member a policy covers cannot be removed
  ("… is covered by a health insurance policy: remove them from the policy first.", `PersonFamilyMember` deleting guard, as for
  nominees). `Employee::insurancePolicies()`.

**One write path: `App\Domain\People\Services\InsuranceRecords`.** `save($employee, ?$policy, $data, $members, $actor, $reason,
$origin, removeOthers)` adds or changes a policy and the people it covers in one transaction (employee row locked);
`remove()` (members, then the policy, then its e-card file), `removeMember()`, `attachEcard()`. Every call needs
`employee.sensitive.update` within organisation scope through `ProfileChangeGuard` (the same right as the Bank and Passport &
licence tabs; a self-service change passes only on its `SelfServiceGrant`). Checks: the policy number is unique for the
employee; a member is the employee or one of the same person's own family members ("An insured family member must be one of the
employee's own family members."), once per policy; the parents' cover is for family members; a blank member id on a change keeps
the one on file; nothing changed = no write. Each saved change is one timeline entry (category `personal`) and the event
`employee.insurance_changed` (`EmploymentEvent::NAMES`; operation and origin only, never a member id).

**E-card.** The form's upload lands in the documents disk's `insurance-ecards/` upload area; `attachEcard()` checks it like every
user upload (`FileSafety`: allowed extension, size, sniffed content), moves it to
`tenants/{tenant}/employees/{employee}/insurance/{ULID}.{ext}` (under the tenant's documents prefix) and removes the earlier file.
It is served only through `ParityFileController` type `insurance-ecard` (signed link → tenant → `InsurancePolicyPolicy::view` or
the employee's own record → `ATTACHMENT_DOWNLOADED` audit). `DataInventory::FILES['employee_insurance_policies']` registers
`ecard_path` (documents disk).

**Access.** `App\Domain\People\Policies\InsurancePolicyPolicy` (registered for both models in `ParityServiceProvider`): view =
`employee.sensitive.view` in scope, or the employee's own record while the CA-6 matrix shows them the section; create / update /
delete = `employee.sensitive.update` (in scope). CA-6: new profile section `health_insurance` in `peopleos.profile.sections`
(after Professional) and in the default of `profile.section_visibility` (every audience ticked).

**Employee 360 — "Health insurance" tab (`InsuranceRelationManager`, group "Bank & personal").** Shown with `viewSensitive` and
the CA-6 section (HR who maintain records are not governed by the matrix, as before); opening it is a sensitive view
(`health_insurance`). One row per policy: insurer (code), policy number, sum assured, issue date, valid until (amber within 30
days, red once ended), the people covered with their masked member ids and the parents' cover, the e-card link. "Reveal" asks
for the member and a purpose and records a sensitive view (`insured_member:{id}`) before showing the id. "Add a policy" / "Edit"
are step wizards (FormWizard: Policy → Cover and e-card → Insured members → Review): the members repeater lists "self" and the
person's family members; the HR form replaces the policy's members (a row left out is removed); Delete removes the policy, its
members and its e-card.

**Upload (`App\Domain\People\Imports\InsuranceImports`, type `health_insurance`).** A `RowImports` upload on the staged pipeline
(inspect → map → validate → approve → run, one audited operation) with the new / existing modes of the other uploads
(`MODES`: add people not on file — a policy already on file is kept; update people on file and the policy's given details;
both). One row per insured person: employee code, insurance company, company code, policy number, sum assured (commas
ignored), issue date, valid until, insured person (blank / "Self" = the employee, else a family member's name as on the profile,
with the relation when two share a name), member id, date of birth, gender, parents cover (yes / no). Blank cells keep what is
on file; nobody is removed by an upload. Rows go through `InsuranceRecords::save` (rules, scope, audit, timeline). Like the bank
details upload the rows are sealed (`sealed_data`, encrypted; `data` stays empty), screens show code · name · insurer and policy ·
person · last four (`RowsRelationManager` summary), and the sealed copies and the file are dropped once the upload is run or
discarded. Listed in `RowImportTypes` (permission `employee.sensitive.update` with `employee.import`, screen `workforce`) and so in
`ImportTypes` (module Workforce: Recent uploads, import approval types). New screen `WorkforceUploadResource` ("Workforce
uploads", People group, slug `workforce-uploads`, the time-office upload pages reused), shown only to people who may run a
workforce upload; `RecentUploads::urlFor` opens workforce uploads there.

**My profile (MS-2).** `MyProfileSections::insurance()` lists each policy (insurer and code, policy number, sum assured, issue
date, valid until, "(ended)", the e-card link) and the people it covers (name, relation, date of birth, gender, parents' cover).
Member ids follow PRV-1 exactly like own identifiers: only with `employee.self_sensitive` (not in the employee template, so hidden
by default), masked, "Show" = `MyProfile::reveal('insured:{id}')` — own record and the matrix row checked, an audited
`self_reveal` (`insured_member:{id}`), the revealed list `#[Locked]`. The tab appears only when the person has a policy, may
change the section, or has a change of it waiting (so nobody sees a new empty tab by default).

**Self-service (MS-3).** New `ProfileEditSections` row `health_insurance` (own tab and CA-6 section; not always-approval; no CA-4
document) in `peopleos.profile.self_edit_sections` and the default of `profile.self_edit.sections` (unticked). The form
(`ProfileEditForms`): pick one of one's policies or "Add a new policy", the policy fields, and the people covered (self or own
family members; member id blank keeps it; entries added or changed, never removed — HR removes). `InsuranceRecords::operations()`
builds one `insurance` operation (changed policy fields + "Insured members" summarised with masked ids only), validated with the
same rules; `ProfileChangeOperations` applies it through `InsuranceRecords::apply()` (right asked: `employee.sensitive.update`),
in the approver's name, or at once under the request's `SelfServiceGrant` when the tenant unticks "Needs approval". The request's
payload is encrypted until decided and purged after, as for every section.

**Expiry reminders.** The daily `peopleos:lifecycle:reminders` sweep emits `EmployeeReminderDue('employee.insurance_expiring')`
(policy id, insurer, valid until, days left — never a member id or policy number) for policies whose cover ends within
`documents.expiry.reminder_days` of employees not exited / alumni, and prints "insurance policies expiring N" only when N > 0 (the
earlier lines are unchanged). The event is a workflow trigger ("Health insurance policy expiring") and a notification event.

**Dataset `insurance_members` (`InsuranceMembersDataset`, category People; report library "Health insurance members" and
"Health insurance expiring in the next 60 days").** One row per insured person with the employee columns, insurer, code, policy
number, sum assured, issue date, valid until, days until the cover ends, e-card on file, person, relation, the employee or not,
date of birth, age, gender, parents' cover and "member id on file" — never the member id. `employee.sensitive.view` (as the 360
tab; the members' own access scope applies); policy number, sum assured, date of birth and age are sensitive fields of that right,
so a reader-less feed (the warehouse export) leaves them out; not on `unattended_datasets`.

**Data, permissions, settings.** Migration `2026_12_31_200001`. `DataInventory`: `employee_insurance_policies`,
`insured_members` under `people` (sensitive; label updated), file `employee_insurance_policies.ecard_path`. No new permission key
(no `peopleos:sync-permissions` needed), no new Setup setting (new rows only: `profile.section_visibility` and
`profile.self_edit.sections` gain `health_insurance`), no queued job, no command. Config: `peopleos.profile.sections`,
`profile.self_edit_sections`, `workflows.trigger_events` / `notifications.events` (`employee.insurance_expiring`),
`data_classification.highly_sensitive` (`InsuredMember::member_id`).

**Tests.** `tests/Feature/HroneParity/HealthInsuranceTest.php` (defaults: nothing editable, no tab, no sweep line, empty 360 tab;
member id encrypted at rest, masked on the tab and in the audit, audited open and reveal, blank keeps it, HR form replaces
members; own family members only, once per policy, parents' cover for family only, dates, duplicate policy, insured family
member not removable, value-free event; e-card moved under the tenant folder, unsafe file refused, audited download for sensitive
HR and the employee only, removed with the policy; the upload in new, existing and new-again modes with sealed rows and masked
labels, errors for unknown people / codes / values, files and sealed copies dropped; My profile with PRV-1 hidden by default,
masked and audited own-only reveal, the CA-6 row hiding it; self-service off, with approval (encrypted payload, masked fields, no
forged grant, applied in the approver's name), at once under the grant, foreign family members refused; the expiry reminder
without ids, exited employees left out; the dataset and both templates for sensitive HR only, no member id in any value,
sensitive fields out of reader-less feeds; organisation scope, rights, the 360 create wizard and tenant isolation).

Not built here: a premium / employee-contribution amount or its payroll deduction (statutory and payroll rules, none coded);
the insurer's own e-card or claim APIs; a per-member e-card; enrolment windows (who may enrol when); the API resource fields.

## WO-3 Hire wizard depth

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-3; §23.2.3 #2, #6–9, #12, #15–18, #22 and the
upcoming-joiner rows §23.2.4 #5–6 that point at them are marked "→ built (WO-3)"). Owner decisions relied on: WRK-7 (no
self-service before day one) and WRK-15 (open; HR may invite a pre-joiner as WO-16 allows) — so "create a sign-in" is unticked
by default; PRV-1 (identifiers are never shown to people without the right). Every default reproduces the earlier behaviour: a
hire that fills only the earlier fields calls `HireEmployeeAction` exactly as before and creates the same records (same audit
events, lifecycle transitions, timeline entries, positions and reporting lines); no new step is required; nobody is invited.
The one visible change: after "Create employee" the page shows a completion panel (what was created, what was skipped, next
steps with "Open the Employee 360" first) instead of opening the 360 directly.

**Already there from MS-4 (#2, #6, #9).** Salutation, the personal / work phone country codes (E.164) and the biometric / device
user id are on the Person and Employment steps since MS-4; WO-3 only covers them in its tests.

**Steps** (`App\Filament\Resources\Employees\Pages\CreateEmployee`, still Filament's `HasWizard`; optional parts in
`App\Filament\Support\HireWizardSections`, each carrying the fields of the 360 form that records it):
Person → Identity numbers (`employee.sensitive.update`) → Returning employee (only when something matched) → Employment (+
starting state, `employee.lifecycle`) → Position (+ position seat, `workforce.view` / `workforce.manage`) → Manager (+ functional
manager, HR business partner) → Address → Family → Bank (`employee.sensitive.update`) → Previous employment (hidden for a
fresher) → Other details (custom fields; `employee.update`, only when the organisation has some) → Sign-in (`user.create`).
A step the person may not use is not shown, and its values are not sent (a forged value records nothing; the action refuses
it anyway).

**One write path: `App\Domain\Employment\Actions\HireWithDetailsAction`.** `HireEmployeeAction` plus each optional part through
the action the Employee 360 uses — so validation, encryption, CA-4 linked documents, audit, timeline and events are the 360's:
`ChangeStatutoryIdentityAction` (PAN, Aadhaar reference), `ChangeAddressAction`, `ChangeFamilyMemberAction`,
`ChangeExperienceAction`, `ChangeBankAccountAction`, `CustomFields::store` (after the employee's `update` policy),
`ChangeManagerAction::handle` (functional / `hrbp`, from the joining date, like the line manager) and `AssignPositionAction`'s
seat guard (`PositionSeats`: open, capacity, scope). The hire and every part are one transaction: any refusal ("Not hired" with
the reason) leaves nothing behind, including the employee code. Rights are checked before anything is written: a starting
state needs `employee.lifecycle`, a seat `workforce.view` / `workforce.manage`, a sign-in `user.create` (+ WO-16's role
checks); identifiers and bank need `employee.sensitive.update` (the actions' own guard). A field whose CA-4 document is
"required with" it (PAN, Aadhaar, bank account, address) cannot be filled at hire — a new employee has no documents yet — and
the wizard says to add it on the 360 once the document is uploaded.

**Starting state (#12).** `HireEmployeeAction::handle(…, startState, confirmationDate)` (both optional; null = as before:
pre-boarding for a future joining date, otherwise joined → probation). `HISTORIC_START_STATES`: confirmed (joined → probation on
the joining date → confirmed on the confirmation date, which must be on or after joining and not in the future; probation end =
the confirmation date unless one was given) and active (joined → active, no probation end unless given) — the transitions the
lifecycle configuration and "Change lifecycle state" already allow, through `LifecycleEngine::transition()`. Refused: a future
joining date (starts in pre-boarding), any other state ("has its own steps": notice, exit, etc. need their own records).

**Returning-employee check (#8).** `App\Domain\Employment\Services\ReturningEmployees::matches(actor, person, employee,
identifiers)`: earlier employees found on PAN / Aadhaar reference and on the existing PersonMatcher criteria (new
`PersonMatcher::undisclosedCandidates()` — the same matching without the minimal disclosure, for server-side rules only), each
with status, joining date, last working day, the separation reasons (employee's and organisation's; never free text), rehire
eligibility and the blacklist reason (CA-3 / WO-11: the latest open or completed exit's `is_rehire_eligible` and
`rehire_block_reason_id`). Matching spans the tenant (a scoped HR user cannot dodge it); what the actor sees follows PRV-1:
outside the access scope or not viewable → "A person on record whose details you may not see"; matched on an identifier only and
no `employee.sensitive.view` → the same; matched on other details too → named, matched on those only; exit details need
`exit.view` / `exit.manage`. Identifier values are never shown, stored or logged. A match marked not eligible for rehire is a
warning that needs a ticked confirmation and a reason (≤ 500) — also for a hidden match (generic message) — and the hire then
records `REVIEWED` on the new employee (module employment; reason "Hired despite a match not eligible for rehire: …";
metadata `check: returning_employee`, the visible matched employee ids, the hidden count, what matched). Nothing else blocks:
PersonMatcher's definite duplicates still stop the Person step as before (its notice now adds that someone who worked here
comes back through "Rehire / reactivate" on their 360). A match with a current employee is shown, not confirmed.

**Lookup hashes.** Migration `2027_01_01_200001_add_identifier_hashes_to_employee_statutory_details`:
`employee_statutory_details.pan_hash`, `aadhaar_hash` (HMAC-SHA256 with the app key of the normalised value — upper case,
without spaces, dots, dashes or slashes — prefixed per kind; indexes `emp_statutory_tenant_pan_hash_idx` /
`emp_statutory_tenant_aadhaar_hash_idx`), set by the model's `saving` hook whenever the identifier changes and backfilled by the
migration from the encrypted values (a value that no longer decrypts gets none). An Aadhaar reference shorter than 12
characters (`AADHAAR_MATCH_MIN_LENGTH`: a "last four digits" reference) gets no hash and is never compared. The hashes are
hidden, excluded from the audit (`auditExcludedAttributes`) and from exports (`DataInventory::EXCLUDED_COLUMNS`). A rotated app
key leaves earlier hashes unmatched until the identifier is saved again (as `statutory_registrations.registration_number_hash`).

**Seat, functional manager, HRBP (#15, #16).** "Position (seat)" on the Position step lists open positions (as "Transfer /
promote"); the seat's organisation fills the dimensions left blank, explicit ones win; a full or out-of-scope seat refuses the
hire. Functional manager and HR business partner (`people.reporting_types` `functional`, `hrbp`) from the manager list (the
actor's access scope; the action looks the person up in scope again).

**Sign-in (#18).** "Create a sign-in and send the invitation" (unticked by default) runs WO-16's
`BulkInvitations::invite()` for the new employee after the hire's commit (the invitation e-mail goes out at once, so it can never
point at a rolled-back hire): user from the lower-cased work e-mail, status invited, linked, roles through `RoleMembers` within
the `RoleGrants` ceiling (the Employee role offered by default, `BulkInvitations::defaultRoleIds`), `UserInvitations::issue`.
`invite()` gained an optional `$context` (default "Bulk invitation"; here "Hire wizard invitation") for the audit reasons only.
The work e-mail (present, valid, not used by another user) and the roles (`BulkInvitations::prepare`) are checked before the
hire; a refusal after the commit keeps the hire and shows "Sign-in not created: …" on the completion panel.

**Completion panel (#22).** The page redirects to itself with `?hired=<id>`; the summary (created, skipped, invitation outcome —
no values) waits in the session under `peopleos.hire_completion.<id>` and is pulled once by the person who hired, after the
employee's `view` check (a forged id or a second visit shows the wizard). View `filament.employees.hire-completion`: created,
"Left for later", next steps — Open the Employee 360, Add another employee, Start onboarding (`onboarding.manage`, no plan in
progress; the 360's `?action=startOnboarding`), Complete employee details (edit, `update` policy), Personal details and
Documents (the 360 tabs).

**Data, permissions.** One migration (above; no new table, so no new `DataInventory` table entry — the excluded columns are
listed). No new permission key, no setting, no job, no command, no new audit action (`REVIEWED` is reused).

**Tests.** `tests/Feature/HroneParity/HireWizardDepthTest.php` (defaults: same records and audit events as `HireEmployeeAction`,
pre-boarding for a future date, nobody invited; MS-4 fields; identifiers encrypted, hashed, masked in the audit, same as the
360's statutory action, format and CA-4 refusals; steps and parts only with their rights, forged values ignored, the action's
own refusals; the returning match on PAN with the earlier employment, exit and blacklist reason, confirmation with a reason
audited without the PAN, enforced by the action; eligible matches informational; PersonMatcher criteria and full / short Aadhaar
references; current employees not confirmed; PRV-1 disclosure without `employee.sensitive.view`, without `exit.view`, outside the
scope and across tenants; confirmed and active starting states with their transitions and refusals; seat, functional manager
and HRBP from the joining date, a full seat refusing with nothing left behind; each section creating the same audit trail and
records as the 360 actions, fresher, rollback; invitation off / on with roles, e-mail checks, the RoleGrants ceiling and a
refusal after the commit; the completion panel shown once; access scope).

Not built here: photo (MS-7), health insurance (MS-5), nominations and identity documents at hire (on the 360), a document upload
inside the wizard (so CA-4-linked fields wait for the 360), UAN / PF / ESIC numbers at hire (the statutory step carries PAN and
Aadhaar reference only, as the matrix row asks), skill type per employee (BP-11), the tenant field layout (WF-09), a "Create
employee" from the upcoming-joiner register (WO-4).

## RQ-4 Leave and encashment forms

Matrix: `docs/hrone-parity/modules/20-requests-inbox-team.md` (package RQ-4; §20.1.18 Leave #8–10, Encashment #3, #5;
§20.4.2 #6), rows marked "→ built (RQ-4)". Every default reproduces the earlier behaviour: a request nobody marks private is
stored, audited and shown exactly as before (the flag is written only when true); the leave form's new question defaults to
"Yes" (visible); no encashment rule asks for a file until a policy sets it, and a request without files is stored as before.
New by default: three read-only panels in the forms (comp-off sources, who else is away, the encashment balance helper),
the optional "Supporting file" on the encashment form, a files column on the encashment registers, and a "Team planned
leaves" list on the manager's Home team card when the team has approved leave in the next 30 days. No new permission, no
setting, no queued job.

**Data.** Migration `2027_01_02_200001_add_privacy_and_encashment_files_to_leave`: `leave_requests.is_private` (boolean,
default false) and `leave_encashments.attachments` (json, nullable). `LeaveRequest::isPrivate()` reads the raw attribute (a
request created without the flag does not carry the column in memory). `DataInventory`: both are columns of tables already
in the `leave` category; the files live under the new `leave` prefix `tenants/{id}/leave-encashments` (swept with the tenant).

**Private leave (§20.1.18 Leave #8).** `Leave\Services\LeavePrivacy`:
- *Whether a request is private.* The leave type's TO-7 entitlement field `privacy` ("Privacy of requests", stored since TO-7
  and read only now): `public` = never private, `private` = always private, `employee` — or nothing set, as in every policy
  so far — = the employee's answer. `Leaves::request()` gained a trailing `?bool $private = null` (`LeavePrivacy::decide`; no
  answer = visible), so the API, imports, bulk apply and the service desk keep their behaviour. The leave form
  (`LeaveActions::applyForm`, i.e. Home, My day, Leave requests incl. "Apply on behalf", the employee's Leave tab) asks
  "Make this leave visible to colleagues?" (Yes / No, default Yes) when the rule lets the employee decide, and says the leave
  will show as "Away" when the policy keeps the type private.
- *Who sees it in full.* The employee, whoever may view it (`LeaveRequestPolicy::view`: leave.view in scope — HR and managers
  holding it), whoever may approve it (`approve`: leave.approve in scope), the person who decided it or its cancellation,
  and the assignees / delegates / completers of its workflow tasks. Everybody else gets "Away" with the dates
  (`typeLabel()`); reasons are never shown to colleagues anywhere. A request that is not private short-circuits (no extra
  query), so nothing changes for visible leave.
- *Surfaces.* Leave calendar (the Type column reads "Away", same grey badge; the type filter goes through
  `restrictToDetails()` so a filter never finds a private leave through its type), Team calendar (CA-9: the leave event's
  detail), Home team card (`HomeComposer::team` "away" and the new "planned" rows, rendered as "Team planned leaves" with
  "See more" to the leave calendar when the viewer may open it), Command Center "who is on leave today"
  (`IntentSearch::onLeaveToday`), the person workspace's "what's next" (`PersonWorkspace::next`), and the leave form's
  team-away panel. Counts (team on leave, team pulse) count people, never types; leave type colours are not used on any of
  these surfaces. The directory card's planned leaves already showed "Away" only (CA-6).
- With the shipped role templates every viewer of the leave calendar and of the team calendar's leave events holds
  leave.view or leave.approve over the rows listed, so those screens show private leave in full to them; "Away" appears
  wherever someone without those rights sees a colleague's leave — e.g. a line manager whose role carries no leave right
  (Home team card, planned leaves, search) — and on any screen that later lists leave to such viewers.

**Comp-off sources (§20.1.18 Leave #9).** `CompOffCredits::sources($employee, $type, $on)`: the TO-4 `comp_off_credit`
ledger entries of the leave year (dated the day worked), with their claim's validity; credits whose claim is cancelled,
lapsed (`effects.expired_on`) or past its use-by date are left out; the balance available now (after pending requests) is
given to the newest credits, as expiry assumes (oldest used first), and only credits with something left are returned. A
credit HR posted by hand has no claim: "Credited on <date>". The leave form shows "Comp-off from" when the chosen type's
category is `comp_off` ("Worked on Sun, 20 Sep 2026 · 1 day · use by 20 Oct 2026").

**Who else is away (§20.1.18 Leave #10).** `Leave\Services\TeamAway`: the team is the people with a current reporting
relationship to the employee's line manager plus the employee's own direct reports (the employee left out); rows are
approved, cancellation-requested and pending leave overlapping the chosen dates, at most 20, read with the viewer's access
scope like the leave calendar, and only for a viewer who may open the leave calendar (`TeamAway::mayView`: leave.view or
leave.approve) — for anyone else the panel is not shown. Each row: name, type (or "Away"), dates, "waiting for approval".
The form's collapsed section "Who else is away" appears once a date is chosen.

**Encashment form (§20.1.18 Encashment #3, #5).** `LeaveAdjustments::encashmentAllowance($employee, $type)` (read only):
balance available now, the rule's days a year (`max_encash_days`) and how many are already asked for this year (pending,
approved, paid), most days in one request (`max_encash_per_request`), the balance to keep (`min_balance_after_encash`), whether
a file is required, and the most that can be asked for now in half days — or why the type cannot be encashed. The form (My
encashments → Request encashment) shows it under the leave type and gains "Supporting file" (PDF / JPEG / PNG, up to 5,
the documents disk's `leave-encashments/` upload area), required when the leave type's new entitlement field
`encash_document_required` ("Encashment requests need a supporting file", boolean, default off) says so.
`requestEncashment()` gained `attachments` / `names`: after its unchanged checks it refuses a missing required file, then
checks each upload (only from the upload area, the document allowlist and size, sniffed content never active —
`FileSafety`) and moves it to `tenants/{tenant}/leave-encashments/{employee}/<ulid>.<ext>`; a refused request deletes its
uploads. Files open through `ParityFileController` type `leave-encashment` (signed link → the employee's own request or
leave.view in scope → audited `ATTACHMENT_DOWNLOADED`). The HR register (Leave → Encashments) and My encashments list them
(column "Files"); the approver's Approval Center card says "N supporting file(s) attached".

**Tests.** `tests/Feature/HroneParity/LeaveFormsTest.php` (defaults: visible leave, audit unchanged, the question defaulting
to Yes, Home's form with and without the answer, encashment without files; the policy's privacy rule; a private leave as
"Away" to a manager without leave rights on the Home team card, its planned leaves and search, and in full to the employee,
an approver without leave.view, the decider, the manager with the template's rights and HR on the leave calendar, the team
calendar and search; no type through the type filter, the cards' data or counts; comp-off sources with lapsed, cancelled and
hand-posted credits and pending leave, the panel only for comp-off; the team-away panel by the calendar's rules and access
scope, hidden for employees; the encashment helper and its limits; the file only when the rule asks, upload-area and content
checks, uploads removed on refusal, storage under the tenant, downloads for the employee and HR but not a colleague, the
registers and the approver's card).

Owner decisions relied on: none recorded for this package; defaults applied — the leave type's `privacy` field (TO-7)
decides when set, unset means the employee decides with "visible" as the default answer; "HR" is leave.view in scope and
"the approver" is leave.approve in scope (as asked).

Not built here: the leave API's own "private" field (the policy rule still applies to API requests), a leave type filter
or date range in the team-away panel, leave-type-specific notification wording for private leave (notification rules on
`leave.*` events keep their configured labels), and showing the team's leave to employees who cannot open the leave
calendar (open point).

## XO-5 Advance desk and on-behalf filing

Matrix: `docs/hrone-parity/modules/26-expense-asset-helpdesk-operations.md` (package XO-5; §26.3.1 #9, §26.3.2 #1, #2, #5, #7–#9,
§26.2.2 #8, §26.7 #1, #2 and §26.9 #2 are marked "→ built (XO-5 …)"). Owner decisions: D-XO8 / EXP-14 on its recommended
behaviour behind a switch that is off by default; EXP-3 as built (an advance approved for payroll payout may be dispensed by bank
before payroll runs). Every default reproduces the earlier behaviour: with `expense.advance.partial_dispense` off an advance is
dispensed in full exactly as before (an amount other than the approved one is refused by the service and the screen offers none;
the ledger entry, its key `disbursed`, its note "Dispensed outside payroll", the audit reason and the settle-by / recovery dates are
unchanged); one's own advance requests and reports are recorded as before (no on-behalf mark); "To dispense" lists what it listed;
money still reaches payroll only through `ExpenseAdvancePayoutInput` / `ExpenseAdvanceRecoveryInput` and the F&F settlement source.

**On-behalf filing (§26.7 #1, #2).** `Filament\Support\ExpenseOnBehalf`: offered to `expense.approve` holders (the existing
on-behalf right, `ExpensePolicy::onBehalf`); the employee picker searches the scoped `Employee` query (code, names, work e-mail,
designation) and leaves out one's own; `resolve()` refuses an employee outside the access scope ("Choose an employee in your access
scope."), one's own ("This is your own record: use "Request an advance"." / "New expense report") and anyone the policy does not
let the person file for.
- Expense advances → "Request an advance for an employee": a wizard (Employee — with what the employee's policy allows or why it
  refuses —, Advance — currency, amount, needed on, purpose, files, purpose / files required as the employee's policy says —,
  Review — `RequestChrome::route`). It calls `ExpenseAdvances::request($employee, $data)` with the signed-in person: the employee's
  policy, largest advance, open-advance and outstanding limits, attachment rules and `expense.advance_requested` workflow (else the
  Approval Center) apply exactly as for the employee. The advance records `requested_by` (as before) and, filed by someone else,
  `filed_on_behalf` = true; the SUBMITTED audit event gains `on_behalf` and `employee_id` (only then). The advance page shows
  "Requested by … on the employee's behalf". A trip filed on the employee's behalf marks its advance the same way.
- Expense reports → "New report for an employee": a wizard (Employee, Expenses — title and the employee's unreported expenses —,
  Submit — "Submit it for approval now", off by default, and who approves it). `ExpenseReports::create` with the actor (the CREATE
  audit event of a report created for the employee carries "Created on behalf of the employee"; `created_by` names who); with
  "submit now" `ExpenseReports::submit` files it on behalf — exempt from the submission windows (as the service always allowed),
  `filed_on_behalf` and `submitted_by` set, parts and workflows as for the employee. The report page shows "Filed for the employee:
  Created by … · Submitted by …" (`ExpenseReport::creator()`). The employee may complete a draft HR created; submitting it
  themselves keeps them to the windows.

**Dispense advances (§26.3.2 #1, #2, #5, #7, #9; §26.9 #2).** `Filament\Pages\DispenseAdvances` has two tabs, each its own address
(`?tab=`, links rather than in-page switching, so each tab builds its own table and filters):
- "To dispense" (default, as before): approved advances in scope (and, with partial dispense on, dispensed ones with something
  left to pay out — "To pay" is then the rest, described "4,000.00 of 10,000.00 paid out"), plus "Trip" (number and dates, linked
  where the viewer may open the trip: `ExpenseAdvanceResource::tripLink`) and "Employee's outstanding" (a correlated sub-select:
  the sum outstanding over the employee's dispensed advances); filter "Requested" (date range).
- "Dispensed": advances paid out — dispensed, settled, recovered, written off (`disbursed_on` set) — with approved, paid out
  ("… left to pay out" / "… lapsed"), outstanding, employee's outstanding, dispensed on, how (by bank / with payroll / in the full
  and final settlement), reference, note, settle by, status; filters "Status", "Dispensed" and "Requested" (date ranges).
- Navigation badge: the advances waiting to be dispensed (`DispenseAdvances::awaiting()`) in the signed-in person's access scope,
  never their own (they cannot dispense those), only for `expense.pay`. The Expense desk tile keeps its own count.
- "Dispense" (`ExpenseAdvanceResource::dispenseAction`, on the page, the register and the advance page) gains an optional note
  (≤ 500): the ledger entry's note (255) and `disbursement_note` (the first payout's), shown on the Dispensed tab and the advance
  page; and, with partial dispense on, "Amount paid out now" (default and maximum: what is left).

**Partial dispense (owner decision D-XO8 / EXP-14).** Setup → Expense `expense.advance.partial_dispense` (default false).
`ExpenseAdvances::dispense($advance, $reference, $on, $actor = null, ?float $amount = null, ?string $note = null)`:
- Off: `$amount` must be the approved amount (or null) — "An advance is dispensed in full: …".
- On, first payout: 0.01 … approved; less than approved records `dispensed_amount` (null = the approved amount, so every advance
  dispensed before, in full or with payroll / the F&F, reads as before); status `disbursed`, channel bank, `disbursed_on`,
  reference, settle-by (EX-F: from the trip's end when later) and recovery dates from this payout; audit "Dispensed: R (a of b)".
- On, further payouts (`dispenseMore`, the advance locked): only a `disbursed` advance with something left
  (`remainingToDispense()`: approved − `dispensed_amount`, while not lapsed), on or after the first payout, up to what is left
  (blank = all of it), refused while a payroll run or an unpaid final settlement recovers the advance (`ExpenseCommitments`,
  RECOVERY); ledger (+) under key `disbursed-N` (posted once), `dispensed_amount` raised, settle-by / recovery / first-payout
  fields unchanged; audit "Further payout: R (a; p of b paid out)".
- The rest lapses (`remainder_lapsed_at`, `lapsedAmount()`): when the advance is settled by reports or a refund
  (`closeIfSettled`), recovered by payroll or the F&F (`recovered`) or written off (`writeOff`) — each in its own audited update —,
  when the employee or someone acting for them cancels it (`cancel()` on a partly dispensed advance) or finance does ("Cancel the
  rest" → `cancelRemainder`; `update` or `pay` ability; CANCELLED audit event with `remainder`, `lapsed`; the advance stays
  dispensed for what was paid), and when its trip is cancelled (`cancelWithTrip`). An advance dispensed in full still cannot be
  cancelled.
- The ledger stays the source of truth: outstanding = Σ entries = paid out − settled − refunded − written off − recovered; reports
  settle and payroll / the F&F recover only what was paid out (never the part not yet paid); a new request counts a partly
  dispensed advance as outstanding + what may still be paid out against `max_outstanding_total`.
- EXP-3 as built: an approved payroll-payout advance not yet carried by a run may be dispensed (in full or, with the switch on, in
  part) by bank; it then leaves the payroll payout (status `disbursed`, channel bank) and its rest is paid on this page. A run that
  carries the payout pays the approved amount in full, once, as before.

**Advance page (§26.3.1 #9, §26.3.2 #7).** `ExpenseAdvanceResource`: "Trip", "Requested by" (on behalf), "Paid out so far", "Left to
pay out", "Lapsed, never paid out", "Dispense note"; relations Ledger and History (`AuditHistoryRelationManager`, `audit.view`,
read-only); record action "Cancel the rest"; "Dispense" reads "Pay out the rest" on a partly dispensed advance.

**Trip page (§26.2.2 #8).** `TravelRequestResource`: "Advance" links to the advance page when the viewer may open it; "Advance
amount" (as asked, in the trip currency, and "approved <base> …" once decided) and "Advance needed on".

**Data, permissions, settings.** Migration `2027_01_04_200001_add_advance_desk_columns_to_expense_advances` (`filed_on_behalf`,
`dispensed_amount`, `disbursement_note`, `remainder_lapsed_at`; index `exp_advances_disbursed_on_idx`; the table is already
classified under `expense` in `DataInventory`, comment added). No new permission key (`expense.approve` files on behalf,
`expense.pay` dispenses — no `peopleos:sync-permissions` needed), no job. Setting `expense.advance.partial_dispense` (false; Setup →
Expense). Shared files touched: `ExpenseAdvances`, `ExpenseReports::create` (audit reason), `ExpenseReport` (`creator()`),
`ExpenseAdvanceResource`, `ExpenseReportResource` (one entry), `TravelRequestResource` (three entries), `ListExpenseAdvances`,
`ListExpenseReports`, `DispenseAdvances` (+ view), `config/peopleos.php`, `config/peopleos_setup.php`.

**Tests.** `tests/Feature/HroneParity/AdvanceDeskTest.php` (defaults: the switch off, in full only — an amount refused in the
service and ignored on the screen —, ledger entry / note / audit as before, no second payout, one's own request and report unmarked,
no on-behalf actions for employees; on-behalf advance — the employee's largest advance and purpose rules, filed on behalf and
audited, Approval Center and workflow as for the employee, one's own and out-of-scope refused on the screen and in the service;
on-behalf report — draft and submit now, CREATE audit reason, exempt from the window while the employee is not, the page naming who,
one's own and out-of-scope refused; Dispense advances — badge in scope and never one's own, employee's outstanding, requested /
dispensed / status filters, the Dispensed tab and the note on the ledger, the advance and the tab; partial dispense — several
payouts, settle-by and recovery from the first, keys `disbursed` / `disbursed-2`, limits, outstanding-total exposure, a report
settling only what was paid and the rest lapsing, the ledger summing to 0; payroll recovering only what was paid, a further payout
refused while a run recovers, recovered once and the rest lapsed; cancel the rest by the employee and from the register, the trip
cancellation lapsing it, a full advance still not cancellable; the trip page's advance amount, needed-on and links; the History tab
read-only for audit viewers only; EXP-3 by bank before payroll, in full and in part, and a run carrying the payout paying it once).

Not built here: the preferred mode of advance (RQ-7, then a column here); the employee ledger view and its link (XO-6); dispense as
an inbox task (matrix 20 §20.2.2 #36); the Inbox Insights count (AN-30); projects (PM-10).

## TX-7 Punch management and the HR day drawer

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-7; §24.5.1 #3, #6, #7; §24.5.2 #1, #3; §24.5.4 #5;
§24.13 #7 — the HR part, plus the team option; the team profile tab itself is RQ-11's), rows marked "→ built (TX-7)". Owner
decision D-TX5 is built as proposed: HROne's "Delete punch" never deletes — a punch is **excluded** with a reason, kept as
evidence with its device data, skipped by processing, and can be restored. Every default reproduces the earlier behaviour:
with no excluded punch, processing reads exactly the punches it read before (same rows, same order), every new column is
null, the team option is off, no new permission key, no queued job of its own; employees' own lists and calendar look as
before (the drawer and the new row actions are for `attendance.view`).

**Exclusion (§24.5.1 #6, #7; D-TX5).** Migration `2027_01_03_200001_add_exclusion_to_attendance_punches` adds
`attendance_punches.excluded_at`, `excluded_by` (users, FK `attendance_punches_excluded_by_fk`, null on delete),
`exclusion_reason` (500) and `status_before_exclusion` (20); the table was already classified (attendance) in
`DataInventory`. `AttendancePunch::NOT_COUNTED = ['rejected', 'excluded']` (`STATUSES` gains `excluded`; relation
`excluder()`, `isExcluded()`). Everything that counts punches skips both: `PunchIngestion::punchesBetween` (the processor's
only punch read, also its TO-5 shift correction), `RequestRules::assertHasPunch`, the check-in policy's daily punch limit
(`CheckInGuard`), My day, Home's check-in tile and the directory's last check-in (`AttendanceGlance`); a failing
`ProcessAttendanceDay` no longer marks an excluded punch `failed`. The punch register filter lists "Excluded", the badge is
amber and a hidden "Excluded because" column shows the reason.

**`Attendance\Services\PunchCorrections`.**
- `exclude(punch, reason, actor)` — row lock, status `excluded` with who / when / why and the status it had; audited
  `STATUS_CHANGE` (module attendance, the reason, metadata `punch = excluded`, the punch time / direction / source); the
  punch is not `Auditable` (append-only evidence), so the event is written explicitly. `restore()` puts the earlier status
  back (`normalized` if none), clears the four columns, audited `RESTORE`. `add(employee, at, direction, reason, actor)` —
  a punch recorded by hand through `PunchIngestion::record` (source `manual`, the reason as its note, the check-in guard as
  for every punch), audited `CREATE`; null when an identical punch (same minute and direction, also an excluded one) is on
  record. Each recalculates the punch's day(s) at once (`AttendanceProcessor::process`).
- The punch's day: every day (the previous one or its own) whose punch window holds it, as `PunchIngestion` attributes a
  punch (`daysOf()`); a punch outside every window belongs to its own date.
- Who (`refusal()` / `mayChange()`): holders of `attendance.punch` (TX-2's split right; `attendance.manage` still grants it)
  for employees inside their access scope; with the setting `team.punch_edit` on, also a manager (with `attendance.view`)
  for the people who report to them today (any reporting type, `ReportingRelationship` effective now) inside their scope —
  the relationship scope reaches direct reports whatever the organisation scope says. Never one's own punches (HR included).
  Refusals, each with its reason: no reason; one's own; outside the scope; without the right ("You may not change punches."
  / "… only for the people who report to you."); already excluded; a check-in refused by the policy; restoring a punch that
  is not excluded; a punch in the future; before joining / after leaving; In / Out / Auto only.
- The lock rule on the punch's days (`assertOpen()`): a day of a closed attendance month (TX-3
  `AttendanceMonthCloses::assertOpen`) and a locked (finalized) day are refused unless
  `attendance.allow_corrections_after_lock` is on — then the day keeps its values and the processor records the difference
  as an attendance arrear (and voids it when a restore brings the day back), exactly as TX-6's overrides.

**The HR day drawer (§24.5.2 #3; shared with matrix 20 RQ-3).** `App\Livewire\Attendance\DayDrawer` — a Livewire component
with the shell's drawer chrome (`.pos-drawer`, like `DrawerHost`), rendered once per page by
`resources/views/filament/shell/body-end.blade.php` for holders of `attendance.view` (`DayDrawer::available()`), opened by the
browser event `pos-day-drawer-open` `{employee, date[, readOnly]}`; after a change it dispatches `pos-attendance-day-changed`
(the calendar, roster board and paid days redraw). Every opening re-reads the employee through the access-scoped Employee
query (another person's day outside the scope is "Not available"; one's own day opens too, for RQ-3). Its read model is
`Attendance\Services\AttendanceDayDetails::day()`: the processed record (status, shift, in – out, worked, late / early,
exceptions), locked / closed marks, holiday, weekly off, the punches between the day's punch window and the calendar day
(time, In / Out, source, device, geo status, matched work location and distance, recorded by, note, excluded with who / when /
why, refused with the reason, outside the window), HR's override, pending / approved regularisations and the day's leave
through the contract below. Inline forms (no nested modals, so nothing fights the drawer's focus trap):
- "Add punch" (date, time in the day's time zone, In / Out / Auto, reason) and "Exclude" / "Restore" (reason) —
  `PunchCorrections`, shown only when `mayChange()`;
- "Cancel leave" (reason) on each leave the viewer may cancel — through the contract;
- "Change the day" — TX-6's form (`ChangeAttendanceStatusOperation::statusFields(true)` + reason, a Filament schema
  `overrideForm` inside the drawer, opened with the day's override by `defaultsFor()`), saved through
  `AttendanceOverrides::setRange` for that day (TX-6's rule: `attendance.override` in scope, never one's own day, a day that
  has started, locked days only with corrections after lock); "Remove" the override with a reason (`AttendanceOverrides::clear`);
- "Reprocess" (`attendance.process` in scope, not on a locked day — as the records list's row action);
- "Calendar" (the employee's month).
Read-only: mounted with `readOnly` by a host, opened with `readOnly: true`, or simply a viewer without the rights — no form is
offered and every method refuses ("This day is read-only here."). Every method re-checks on the server.

**Check-in evidence (photos, coordinates, network).** There was no photo-viewing rule before; the drawer applies the
organisation's existing geo-access choice for located check-ins (Setup → Access rights → Geo access,
`geo_dashboard.checkins_visible_to`; owner decision D-CA9-5 / PRV-14: nobody until chosen, "no screen shows punch coordinates
today"): `Filament\Support\CheckInEvidence::visibleTo()` — HR (RoleLens HR) for the people in their access scope, managers
for the people who report to them today; the attendance view right alone never shows them. Then the drawer shows the
coordinates (5 decimals), IP address, Wi-Fi network, the app's face check and "Photo n" links: `ParityFileController` type
`punch-photo` (30-minute signed link; for this type only the same `CheckInEvidence` rule applies instead of the record's
`view` policy — `AttendanceConfigPolicy::view` would let every attendance viewer in; audited `ATTACHMENT_DOWNLOADED`). Others
see "Check-in evidence kept (location / photos shown only to the geo-access audience)". Source, device, geo status and the
matched work location show to everyone who sees the day.

**Leave through a contract.** `Attendance\Contracts\LeaveOnDay` (`onDay(employee, date, viewer)`, `cancel(id, reason, actor)`),
implemented by `Leave\Services\AttendanceLeaveOnDay` (bound in `ParityServiceProvider`): the requests covering the day that
are pending, approved or waiting on a cancellation, under the viewer's access scope and the leave policy's `view`, each with
`can_cancel` (open and the leave policy's `cancel`: `leave.manage` in scope, or one's own); `cancel()` re-checks the policy and
calls `Leaves::cancel` — the same path as "Cancel" on Leave requests (HR reverses at once; others follow the leave type's
cancellation policy), which restores the balance and recalculates the days. Attendance never reads leave tables.

**Where the drawer opens (§24.5.1 #3, §24.5.2 #1, §24.5.4 #5).** `AttendanceActions::forRecords()` (Attendance records, the
exception centre, Employee 360's Attendance tab) gains "Day" (dispatches the event) and "Calendar" (the employee's month),
both for `attendance.view` on records the viewer may view; Employee 360's Attendance tab has an "Attendance calendar" header
link; the attendance calendar's days have a "Day" button for the HR side; the roster board puts a small "Day" link under
each employed day (the cell keeps opening TX-5's "Edit day"); the paid-days breakdown's late-mark penalty and arrear rows
have "Change"; the punch register has "Day" (the day the punch counts on), "Exclude" and "Restore".

**Settings, permissions, wiring.** Setting `team.punch_edit` (false; Setup centre → Time office) — a setting, not a
permission. No new permission key (`attendance.punch`, `attendance.override`, `attendance.process`, `attendance.view`,
`leave.manage` via the leave policy); no new split (TimeOfficeDesk's split list unchanged). No new audit action (STATUS_CHANGE,
RESTORE, CREATE, ATTACHMENT_DOWNLOADED). `ParityServiceProvider` binds `LeaveOnDay`.

**Tests.** `tests/Feature/HroneParity/PunchManagementTest.php` (defaults: the same punches counted with a refused punch around,
no exclusion column written, the team option off and in Setup, no drawer or new row action for an employee; exclude → kept
with its evidence untouched, skipped, the day equal to the same day without the punch, audited, never marked failed by a
later run, restore → the day exactly as before, from the register too; add with a reason and the duplicate; every refusal;
locked day refused, then with corrections after lock the day kept and the arrear recorded and voided on restore; closed month
refused, allowed with corrections after lock; the drawer from records (dispatch), calendar, roster board, paid-days breakdown
and in the shell, "Not available" outside the scope; changing punches, read-only and reprocess from the drawer; evidence and
photo link to nobody by default, to HR when chosen, to managers for their own team only; leave cancel through the contract,
refused without `leave.manage`; TX-6's form with validation, opening with the day's override, remove, own day refused; the
team option off / on, other people and one's own refused, the relationship scope; no payroll words in TX-7 code).

Not built here: the team profile's own time-office tab (RQ-11 — it can open this drawer with the team option), the employee's
own calendar drawer (RQ-3 — `DayDrawer` with `readOnly` or the employee's own day), the check-in approval register and the
punch detail with nearest location / map (TX-10, CA-8), deleting device data (never), an exclusion upload, and a bulk
"exclude" over selected punches.

## XO-6 Advance recovery desk

Matrix: `docs/hrone-parity/modules/26-expense-asset-helpdesk-operations.md` (package XO-6; §26.3.4 #1–#4, #6–#10, §26.3.1 #8 and
§26.7 #5 are marked "→ built (XO-6 …)"). Owner decision D-XO2 / EXP-8 on its recommended "yes" (a scheduled recovery takes
precedence for that advance; the automatic date stays the default). Every default reproduces the earlier behaviour: an advance with no
scheduled recovery is recovered by `ExpenseAdvanceRecoveryInput` exactly as before (same query, same "a report waiting for a decision
holds it" rule, same line and basis); a refund without a mode is recorded exactly as before (note "Refunded by the employee", the new
columns null); the final settlement recovers what it recovered before. No permission key, setting or job.

**The desk (`Filament\Pages\AdvanceRecovery`, Expense & travel → Advance recovery, `expense.pay`; §26.3.4 #1–#4, §26.7 #5).** A
`RegisterTablePage` with WO-1 `HasRegisterTools` (register key `advance_recovery`), one row per employee (the row is the `Employee`):
the scoped `Employee` query (workforce scope) narrowed to employees with a dispensed advance outstanding in the scoped `ExpenseAdvance`
query (the expense module's CA-9 groups too), never the viewer's own record; figures as sub-selects. Tabs (`?tab=`, kept by saved
views as the view's `lens`):
- "Unrecovered" (default): employee code, employee (WO-2 search: code, names, work e-mail, designation), outstanding, advances,
  oldest settle-by (red once passed), scheduled recovery ("Nov 2026 · 5,000.00; Dec 2026 · …", red with "<month> finalized without
  it: change or cancel it on the ledger" when missed), automatic recovery ("from 6 Oct 2026": the earliest policy recovery date of
  the advances with no recovery scheduled), last report submitted (`expense_reports.submitted_at`, "Never"), recovered so far.
- "Recovered": employees with nothing outstanding whose advances were recovered or paid back — recovered through payroll, in the full
  and final settlement, paid back outside payroll, last recovered on.
- Filters (both tabs): WO-2 pasted codes, organisation group and employee status / type / joining (`EmployeeFilters::codes`,
  `organisation`, `workforce` on the employee itself), "No report submitted for N days" (HROne's "Last expense submitted duration":
  no report submitted on or after today − N, or never), "Recovery" (scheduled through payroll, month finalized without it, automatic
  only, nothing scheduled), "Settle-by date passed". Presets (`?view=`): scheduled, missed, unscheduled, overdue, no report for 60
  days, recovered. "Report" (`outstanding_expense_advances`, `expense_advance_recovery_register`), "Download CSV" (the tab with its
  filters and search, ≤ `peopleos.bulk.max_records` rows, formula-injection safe, audited EXPORT `advance_recovery`).
- Row actions (Unrecovered): "Ledger", "Recover through payroll", "Record recovery outside payroll" (both step wizards, only where the
  viewer may recover the employee's advances: `ExpensePolicy::pay` in scope, never one's own); toolbar bulk (WO-1) for both. The
  Expense desk lists the page under Advances (with the count of the Unrecovered tab); Setup → Expense links it.

**Employee ledger (`Filament\Pages\AdvanceLedger`, `?employee=`, not in the navigation; §26.3.1 #8, §26.3.4 #7).** Read-only, from
`expense_advance_entries`: every entry of the employee's advances by entry date (then id) — date, advance (linked where the viewer may
open it), reason (the kind, the entry's note and, for a refund, how), reference, opening outstanding, credit / debit, closing
outstanding (`AdvanceRecoveries::ledger`: a running sum across advances, so the last closing is the employee's outstanding). Summary:
outstanding now, scheduled through payroll, recovered so far. Lists the employee's recoveries through payroll (open first; recovered
ones with the amount and date; cancelled or closed with the reason; missed ones flagged) with "Change" and "Cancel" (page actions
with the schedule as argument). Opens for the employee themselves (`expense.claim`) and for `expense.view` / `approve` / `pay` holders
whose expense access scope reaches the employee (`AccessScopes::allowsEmployeeId(…, 'expense')`); otherwise 404. Linked from the
desk, Dispense advances' "Employee's outstanding" (both tabs; closes XO-5's pending link) and the advance page ("Employee's advance
ledger").

**Recover through payroll (owner decision D-XO2 / EXP-8; §26.3.4 #8).** `Expense\Services\AdvanceRecoveries` (expense domain, reads
payroll only through XO-4's `Expense\Contracts\PayrollMonths`):
- `schedule($advance, $month, $amount, $note, $actor)` and `scheduleForEmployee($employee, $month, ?$amount, …)` (the desk; blank =
  everything outstanding and not yet scheduled, spread over the employee's advances oldest settle-by first — then dispensed on, id —
  leaving out advances a run is recovering or that already have a recovery in that month). Refused: one's own / out of scope ("You
  cannot recover your own advances." / "… this advance."), an advance not dispensed or with nothing outstanding, an advance a payroll
  run not yet finalized (or an unpaid final settlement) already recovers ("… is already being recovered in the payroll of Nov 2026: it
  cannot be scheduled now."), a month not "Y-m", a finalized month ("The payroll of Sep 2026 is finalized: choose a month not yet
  finalized."), a month not among `openFor()` (XO-4's months: the month holding today and the 11 after it, plus an earlier month still
  being prepared), an employee with no company, a second recovery of the same advance in the same month, an amount above what is
  outstanding less the advance's other open recoveries.
- Stored in `expense_advance_recovery_schedules` (`ExpenseAdvanceRecoverySchedule`: advance, employee, `recover_in` = the month's first
  day, amount, status `scheduled` | `recovered` | `cancelled` | `closed`, note, scheduled by, recovered amount / on, payroll run or final
  settlement id, cancelled at / by / reason; tenant-scoped, `ScopedByEmployee`, audited — "Recovery of ADV-… scheduled: Nov 2026
  payroll, 5,000.00").
- `ExpenseAdvanceRecoveryInput` reads the employee's open schedules first. None → the old code path, unchanged. Otherwise an advance with
  an open schedule is left out of the automatic recovery altogether (whatever its recovery date — "precedence for that advance") and is
  recovered only by the run of a scheduled month: one line per advance (`EXP_ADV_RECOVERY`, the same name), amount = min(scheduled
  amount, outstanding), basis + `schedule`, `scheduled_for`, `scheduled_amount`; the ids stay the advance ids, so `PendingPayrollInputs`
  (unchanged) and `ExpenseCommitments` keep every other recovery, refund, write-off, report adjustment and further payout away while the
  run is not finalized. A report waiting for a decision does not hold a scheduled recovery (finance chose month and amount; the report's
  approval then pays the employee in full, as it skips an advance being recovered) — open point XO6-1.
- `settle()` (on `payroll.finalized`) passes the schedule of each line of the run's own month to `ExpenseAdvances::recovered(…,
  $schedules)`, which marks it `recovered` (amount, date, run) in the same transaction as the ledger entry, only if still scheduled — so
  once, with exactly one finalized run. Once recovered (or cancelled) the advance falls back to the automatic date for what is left.
- `missed($schedule)`: open, and `PayrollMonths::isFinalized(employee, month)` — its run was calculated before it was scheduled and
  finalized without recalculation, or the employee was not paid that month. It stays scheduled for that month (no other month takes
  it, and the advance's automatic recovery keeps waiting) and is flagged on the desk, its filter and the ledger until finance moves or
  cancels it.
- `reschedule($schedule, $month, ?$amount, $note)` ("Recovery month changed: Sep 2026 → Oct 2026[; 1,000.00 → 1,500.00]" / "Recovery
  amount changed (Dec 2026): …") and `cancel($schedule, $reason)` ("Recovery cancelled (Nov 2026): …"): XO-4's rules — refused for a
  schedule not open, while a run (or an unpaid final settlement) carries the advance ("it stays with that payroll"), for a finalized or
  not open month, the same month and amount, another open recovery of the advance in that month, an amount above what is free.
- An advance closing first (settled by reports or refunds, recovered, written off) closes its open schedules (`closed`, with "The
  advance was settled / recovered / written off"), audited — never recovered later.
- Final settlement (`ExpenseSettlementSource`): the final salary's lines (payroll inputs) may carry a scheduled recovery of part of an
  advance; the `EXP_ADVANCE_BALANCE` line now recovers the rest of that advance (outstanding − what the final salary carries; before,
  a carried advance was skipped, which was right only because the automatic recovery always takes the whole outstanding — unchanged
  for it), and `settled()` passes the schedules its lines carry, so they are marked recovered when the settlement is paid.

**Recovery outside payroll (§26.3.4 #9).** `ExpenseAdvances::refund($advance, $amount, $reference, $on, $actor, $details = [])`:
`mode` (`ExpenseAdvanceEntry::REFUND_MODES`: bank_transfer, cheque, cash; null = not recorded, as before), `bank_name` (≤ 120; bank
transfer or cheque, required for a cheque, refused for cash), `cheque_number` (≤ 30) and `cheque_date` (required for a cheque, refused
otherwise), `note` (≤ 255, the ledger entry's note instead of "Refunded by the employee"); kept on the ledger entry (`refund_mode`,
`bank_name`, `cheque_number`, `cheque_date`; `refundModeLabel()` "Cheque 004512 of 19 Sep 2026 · HDFC Bank"), audited with it.
`AdvanceRecoveries::refundForEmployee($employee, ?$amount, $reference, $on, $details)` spreads one refund over the employee's advances
oldest settle-by first (advances a run is recovering left out), all or nothing. The desk's wizard: "How it was paid back" (mode
required, bank, cheque) → "Amount and reference" (amount ≤ what can be recovered now, reference — blank for a cheque or cash records
"Cheque <number>" / "Cash <date>" —, received on, remarks). The advance page's "Record refund" became the same two steps with the mode
optional (blank = as before); the advance's Ledger tab gained "How".

**Bulk (WO-1; §26.3.4 #6, #10).** `Experience\BulkOperations\AdvanceRecoveryPayrollOperation` (`advance_recovery.payroll`: salary month
from `PayrollMonths::upcoming()`, checked per employee; optional "at most, per employee" capped at what is free for that month;
comments) and `AdvanceRecoveryRefundOperation` (`advance_recovery.refund`: mode, bank, cheque, reference, received on, remarks; each
employee's whole outstanding that no run is recovering); model `Employee`, permission `expense.pay` (+ `bulk.run`), one's own refused,
each employee through `AdvanceRecoveries` with the service's message as the refusal; offered on the selected rows and on "Act on all
matching". Results: the bulk run's per-employee lines ("Nov 2026: 5,000.00 (2 advances)", "Recovered 3,000.00 (ADV-…)") and the
notification; every audit event of a run carries its operation id.

**Data, permissions.** Migration `2027_01_05_200001_create_expense_advance_recovery_schedules` (table above with foreign keys
`exp_adv_rec_sched_advance_fk`, `…_employee_fk`, `…_by_fk`, `…_cancel_by_fk` and indexes `exp_adv_rec_sched_emp_status_idx`,
`…_adv_status_idx`, `…_month_idx`; the run and settlement ids are plain columns, as on the ledger entries; `expense_advance_entries` gains
`refund_mode`, `bank_name`, `cheque_number`, `cheque_date`). `DataInventory`: the new table under `expense`. No new permission key
(`expense.pay`, `bulk.run` exist — no `peopleos:sync-permissions`), no setting, no job. Shared files touched: `ExpenseAdvances`
(refund details, `recovered()` schedules, closing schedules), `ExpenseAdvanceRecoveryInput`, `ExpenseSettlementSource`,
`ExpenseAdvanceEntry`, `ExpenseAdvanceResource` (refund steps, two entries, `scheduledRecovery()`), `EntriesRelationManager`,
`DispenseAdvances` (two column links), `ExpenseDesk` (one entry), `ParityServiceProvider` (two bulk operations),
`config/peopleos_setup.php` (one link), `DataInventory`.

**Tests.** `tests/Feature/HroneParity/AdvanceRecoveryDeskTest.php` (defaults: no schedule = the automatic recovery's exact line and
basis, recovered once, a plain refund as before; the desk — one row per employee with outstanding, advances, oldest settle-by,
automatic date, last report, the scheduled month and amount, never one's own, Recovered tab, search, "no report for N days",
organisation, employee status, recovery and settle-by filters, preset from the URL, audited CSV, access scope, `expense.pay` only;
ledger running balance across advances with the refund mode, for HR in scope and the employee, 404 otherwise, links from the desk and
Dispense advances; a schedule recovered in exactly its month and once, the automatic date waiting and taking over afterwards, every
other way refused while the run carries it; amount ≤ outstanding and not yet scheduled, one per advance per month, oldest-first
spreading, the screen's maximum, payroll never above the outstanding and the schedule closing with its advance; a finalized month
refused, a missed month flagged on the desk and the ledger, never moved, then moved and recovered once; change / cancel and their
refusals, cancelled = automatic again; refunds by bank transfer, cheque and cash with every refusal, the desk's cheque spread oldest
first, the advance page's refund as before; bulk through WO-1 with per-employee results and refusals; partial dispense (XO-5) counted by
what was paid out, a further payout refused while a run recovers, the rest lapsing once settled; at exit, the final salary deducting
the scheduled part and the settlement's balance line the rest of the same advance, recovered in one ledger entry and the schedule marked
recovered with the settlement).

Not built here: a pay-month or recovery-month filter on the payroll side; notifying the employee when a recovery is scheduled (XO6-2);
recovery modes on the report library's `expense_advance_ledger` dataset (AN follow-up); the recovered amount on the schedule when a
final settlement recovers more than it (the schedule keeps its own part).

## GO-4 Company goals and OKR depth

Matrix: `docs/hrone-parity/modules/27-performance-engage-operations.md` (package GO-4: §27.2.1 #12; §27.2.3 #3–9, 13–15;
§27.7 #4, 8 (GO-4 part), 10; §27.10 #2 (GO-4 part: the final achievement GO-10 will upload)), rows marked "→ built (GO-4 …)".
Every default reproduces the earlier behaviour: goals are public, organisation goals have no owner person, a check-in without
a status or period is exactly as before, the final achievement is the system-calculated one and nothing reads an override
while `performance.goals.achievement_override` is off. Owner decisions relied on: PER-5 / D-GO5 (private goals — the
recommended default, public unless HR makes an organisation goal private) and PER-6 / D-GO7 (achievement override — off).

**Owner person (§27.2.3 #3, #14).** `goals.owner_employee_id` (FK `goals_owner_employee_fk`, nullable) — only on organisation
goals (`Goal` saving guard and `Goals::assertOwnerPerson`: an employee goal belongs to its employee; turning an organisation
goal into an employee goal clears the owner and makes it public). Form field "Owner" (organisation levels), list column
"Owner" (the person, with the organisation unit below), filter "Owner", dataset fields `owner` / `owner_code`. The owner
person sees the goal (also when private) and may check in on it (`GoalPolicy::checkIn`), but does not edit it (organisation
goals stay `performance.manage`'s).

**Goal status (health) and the check-in period (§27.2.1 #12, §27.2.3 #4, §27.7 #4, #10).** `goals.health` (null = not started;
`peopleos.performance.goal_health`: not started, on track, behind, at risk; a closed goal reads "Closed") and on
`goal_check_ins`: `health`, `period_start`, `period_end`. `Goals::checkIn(..., health:, periodStart:, periodEnd:)` validates them
(unknown status, a period ending before it starts) and the status becomes the goal's; without them nothing changes. The
"Check in" dialog (`PerformanceActions::goalCheckIn`, shared with the linked-goals table) gained "Goal status" (placeholder
"Unchanged"), "Period from", "Period to"; the existing confidence field stays. Goals list: "Goal status" badge column and filter
(not started = open goals nobody reported on yet; closed = completed / cancelled, `GoalResource::whereHealth`). API
`POST /api/v1/performance/goals/{goal}/progress` accepts `health`, `period_start`, `period_end`; goals return `health`,
progress rows `health` and the period. Datasets: goals `health`, `health_key`; check-ins `health`, `period_start`, `period_end`.

**Private goals and the visible-to list (§27.2.3 #13; owner decision PER-5 / D-GO5).** `goals.visibility` (public default,
`private` only on organisation goals) and `goal_viewers` (`GoalViewer`: tenant, goal, employee, created_by; unique per goal and
employee; ScopedByEmployee + Auditable). `Domain\Performance\Support\GoalVisibility` holds the rule: a private goal is seen by
its creator, its owner person, the employees on its visible-to list and performance HR (`performance.view` or
`performance.manage`) in scope — unscoped HR always, scoped HR when the owner person is inside their performance access scope
(a private goal without an owner is then only its creator's and list's — fail closed); nobody else. It is enforced by the
global scope `Domain\Performance\Scopes\GoalVisibilityScope` on every `Goal` query: the Goals list and its search and
filters, the "Aligned to" options, the linked-goals table, the goals and check-ins datasets (check-ins require a visible goal),
the edit page (404), `Goal::query()` anywhere a person is signed in. An API-key request reads public goals only (list, progress
read and write: 404 for a private goal), also where it runs a report as its owner. System contexts (console, queue workers,
platform bypass, `AccessScope::withoutScoping`) are not limited. `GoalPolicy` (extends `EmployeeOwnedPolicy`, registered in
`ParityServiceProvider`) refuses view / update / delete / check-in of a goal one may not see. Owners of a goal aligned to a
private goal see only that goal's title (`GoalVisibility::parentTitleFor`: the "↳ parent" line on the list and the current
"Aligned to" option); anyone else who sees the aligned goal reads "A private goal". Roll-ups and alignment checks lift the scope
by name (`Goals::recompute` parent / children, `close`, `assertNotDuplicate`, `cancelForWithdrawal`, `Goal::assertAlignment`,
`Appraisals::close` completing a cycle's goals), so a private goal's progress and a cycle close never depend on who acted;
aligning to a goal one may not see is refused. Event payloads (`performance.goal.created`, `.progress_updated` — webhooks)
carry "A private goal" instead of a private goal's title (`Goal::publicTitle`). Form: step "Owner, visibility and tags" —
"Owner", "Visibility" (public / private), "Visible to" (multiple, shown for private; saved by `Goals::create/update`
(`visible_to`), audited on the goal as one `visible_to` change, plus the viewer rows' own audit events). List: "Visibility"
column (hidden by default) and filter; dataset `visibility`.

**Tags (§27.2.3 #15).** `goals.tags` (JSON list): trimmed, de-duplicated case-insensitively, at most 20 of up to 40 characters,
none = null (`Goals::withTags`). Form TagsInput (suggestions from the goals one may see), list column (hidden by default) and a
multiple "Tags" filter (any of the chosen tags, `whereJsonContains`), dataset `tags`.

**Linked goals (§27.2.3 #6–9, §27.7 #8 GO-4 part; = MS-14 "Linked goals").** `goal_alignments` (`GoalAlignment`: tenant,
goal (the aligned goal), parent, linked_at, unlinked_at, linked_by, unlinked_by; never deleted) is written by the `Goal` model
whenever `parent_id` is set, changed or cleared, by any write path; the migration backfills an open row for every goal aligned
today (linked on = the goal's creation). Re-linking is allowed (a new row; HROne's "unlinked cannot be linked again" is not
copied — the history keeps both). `Goal::alignments()`; relation manager `Goals\RelationManagers\LinkedGoalsRelationManager`
("Linked goals" on the goal's edit page, to whoever may edit the goal): S.no, linked goal and level, owner (employee or owner
person, code), KPIs (count and titles), progress, final achievement (while overriding is on), goal status, status, last check-in
(period or date, comment), linked on, unlinked on ("Still linked"); filters linked / unlinked, status, type and the WO-2
organisation filter (company, business unit, department, location); owner search with the WO-2 employee search; actions "Check
in" (linked rows the viewer may check in on), "Check-ins" (the aligned goal's check-ins: date, period, status, progress, comment,
by — `resources/views/filament/performance/goal-check-ins.blade.php`) and "Open". Only aligned goals the viewer may see are
listed (the Goals list's own rule, `GoalResource::restrictToViewer`, and the private-goal rule). Dataset: `aligned_to` (a
private parent named only as `parentTitleFor` allows) and `linked_goals` (aligned goals not cancelled).

**System, actual and final achievement (§27.2.3 #5; §27.10 #2 GO-4 part; owner decision PER-6 / D-GO7).** New Setup →
Performance setting `performance.goals.achievement_override` (false). The system-calculated achievement stays the goal's
`progress` (key results, check-ins, aligned goals — rolling up exactly as before). `goals.achievement_override` (actual, %,
0–1000), `achievement_override_reason`, `achievement_overridden_by` (FK `goals_achievement_overridden_by_fk`),
`achievement_overridden_at`. `Goals::overrideAchievement($goal, $actual, $reason, $actor)`: only while the setting is on;
performance HR (`performance.manage`) or the performance manager of the goal's person (`performance.team`, the configured
relationship types) — never on one's own goal, never a goal one may not see; reason required (≤ 500); not on a cancelled goal
or a goal of a closed cycle; `null` clears it (the system's stands again); under the goal row lock; audited explicitly with the
before / after value, the reason and metadata `system_achievement` / `final_achievement`. `Goal::finalAchievement()` = the
override while the setting is on and one is entered, else exactly `systemAchievement()` (= progress) — a stored override is
ignored once the setting is off again. `KpiRatings::achievementPct` (the KPI formula rating of reviews) reads the override
only while the setting is on. Roll-ups to parent goals keep using the system values. Screens: "Actual achievement" (row action
on the list, header action on the goal; the modal names the system value; blank clears), "Final achievement" column (with the
system value below when overridden) and "Actual achievement entered" filter, both only while the setting is on. Datasets:
`system_achievement`, `final_achievement`, `achievement_overridden`. GO-10's "OKR final achievement" upload will write through
`overrideAchievement` (one row = one reason).

**Data, permissions, settings.** Migration `2027_01_06_200001_add_company_goal_depth_to_goals` (columns on `goals` and
`goal_check_ins` with named indexes `goals_tenant_owner_idx`, `goals_tenant_visibility_idx`; tables `goal_viewers`,
`goal_alignments` with named FKs / indexes; backfill of open alignments). `DataInventory`: `goal_viewers`, `goal_alignments`
under goals (the new columns noted there; exported and deleted with their rows). No new permission key
(`performance.manage`, `performance.view`, `performance.team`, `performance.goals`). New setting
`performance.goals.achievement_override` (false); new config lists `peopleos.performance.goal_health`, `goal_visibility`.
Report library template "Company goals and OKRs" (`company_goals`, goals dataset, organisation levels). No job, command or
notification event.

**Tests.** `tests/Feature/HroneParity/CompanyGoalsTest.php` (defaults: public, no owner, status not started, check-ins and the
final achievement as before, the override refused and hidden; owner person — check-in rights, status and period on the
check-in and the goal, refusals, owner / status filters incl. not started and closed, dataset columns, level change clearing
owner and privacy; private goals on the list, search, visibility filter, policy, "Aligned to" options, the edit page (404), the
form's saved visible-to list and its audit; the aligned goal's owner reading the title only, the manager reading "A private
goal", roll-up and close through a private parent, goals and check-ins datasets for out-of-scope HR and unscoped HR; HR in
scope vs out of scope, the creator, linked goals leaving a private child out; the API — list, progress read and write 404,
status and period accepted; tags — trimming, limits, filter, suggestions, dataset, the form; linked goals — linked / unlinked
on through re-linking and clearing, never deleted, the relation manager's rows, filters, search, check-in and check-ins view;
the override — off (refused, stored value ignored, KPI formula unchanged), who may (HR, the manager; not the employee, not a
colleague), reason and range, system value kept beside it, the audit with metadata, clearing from the list, the dataset, and
switched off again).

Not built here: prompts to organisation goals' owner persons in the scheduled check-in reminders (HP-8b prompts creators as
before — open point); visible-to by organisation unit or rule conditions (the list names employees); "Add bulk employees" to
link people to a goal (HROne links KPIs from the employee's sheet; aligning stays on the employee's goal); the goal dashboard
and its KPI drawer (GO-6) and the OKR uploads (GO-10).

## WO-4 Upcoming joiners

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-4; §23.3.1 #1, #8, #9, #11, #13, #14, #16 and §23.11 #18
are marked "→ built (WO-4)"; §23.3.1 #7, #10, #12 — the joiner filling and sending back their own profile — wait for WRK-7 /
D-WO11). Owner decisions relied on: WRK-1 / D-WO1 (the recommended option: a new end state `not_joined`), WRK-7 / D-WO11 open (no
joiner access before day one: the profile-completion mail carries no link). Every default reproduces the earlier behaviour: a hire with
a future date still starts pre-boarding with the same records; nothing is mailed and no task is listed until an organisation switches
it on; nobody reaches `not_joined` except through "Mark as not joining"; populations, counts and lists are unchanged for every existing
state (the only edits add `not_joined` to the lists that already leave pre-joiners or leavers out).

**Upcoming joiners register (§23.3.1 #1, #9, #16).** `App\Filament\Pages\UpcomingJoiners` (People → Upcoming joiners; a
`RegisterTablePage` with WO-1's `HasRegisterTools`, register key `upcoming_joiners`): employees in the pre-joining states
(pre-employee, pre-boarding — `UpcomingJoiners::preJoiningStates()`; someone in "onboarding" already counts as employed) and, through
the "Joining status" filter (Joining — default —, Not joining, All), the people marked as not joining. Columns: code, name, expected
joining date (`expected_joining_date`, or `joining_date` for someone hired with a future date — `UpcomingJoiners::expectedDate`),
days to join (today / tomorrow / in N days / N days overdue), designation, department, location and reporting manager of the planned
position and line (joiners have no current position before day one), onboarding progress (the latest onboarding plan), background
check (latest case; `bgv.view`), documents pending (types mandatory for onboarding without a current document, and documents waiting
for verification; `document.view`), status, not-joining reason, work e-mail. Filters: joining status, expected joining date range,
WO-2's pasted codes, organisation (on the planned position: `EmployeeFilters::organisation()` gained an optional `$positionRelation`,
default `currentPosition` as before) and background check. Presets: joining in the next 7 days, joining this month, date passed and not
marked as joined, not joining. Saved views, the "Report" link (template `upcoming_joiners`), an audited CSV ("Export": the filtered rows
up to the bulk limit, EXPORT audit event "Upcoming joiners" with the row count and the filters used; spreadsheet formulas neutralised)
and "Hire employee". Opens for `employee.view` with one joiner right (`employee.lifecycle`, `employee.create`, `onboarding.view` or
`onboarding.manage`); the employee access scope applies (a scoped HR user reaches a joiner once their planned position is in force —
the scope reads positions effective today, unchanged). The Workforce desk lists it under Onboarding (with a count); the earlier "New
hires (not joined yet)" preset of Employees stays. Row actions (and the Employee 360's Action group, for pre-joiners):
`App\Filament\Support\UpcomingJoinerActions`.

**Change expected joining date (§23.3.1 #13).** `App\Domain\Employment\Services\UpcomingJoiners::changeJoiningDate($employee, $date,
$reason, $actor)`: `employee.lifecycle` (the `transition` policy), the record's `view` and access scope; only for a pre-joiner (refused
once joined — "has already joined" — or when not joined); the new date not earlier than today and different from the current one; a
reason (≤ 500). One transaction: `expected_joining_date` (and `joining_date` when it holds the planned date), the probation end (same
number of months from the new date when it was N months from the old one, otherwise shifted by the same days), the hire's position row
and each reporting line that start on the old date — only when it is the employee's only row of its kind and still open (anything
edited since is left alone) — and, through the new `Onboarding::reschedule($plan, $anchor, $reason)`, every in-progress onboarding or
induction plan: the anchor and the due date of each item not started yet (pending) by the same number of days; closed items keep
their dates; task campaigns are not anchored on joining. Audited (the employee's fields with the reason, the plan's anchor with the
number of items moved) and on the timeline ("Expected joining date changed", old → new, reason). The start-due date of templates that
start N days before joining follows by itself (it is computed from the date).

**Not joining (§23.3.1 #14, #16; D-WO1).** `LifecycleState::NotJoined` (`not_joined`, label "Not Joined", grey; fits the existing
`lifecycle_state` / transition columns, no migration): `peopleos.lifecycle.transitions` lets `pre_employee` and `preboarding` reach it
and gives it no way out (`'not_joined' => []`). `isEmployed()` and `isPayrollEligible()` are false, new `isPreJoining()`.
`LifecycleEngine::transition()` refuses `not_joined` unless the call carries a `not_joining_reason` (only
`UpcomingJoiners::markNotJoining` does), so the API's lifecycle endpoint and the 360's "Change lifecycle state" (which no longer offers
it) cannot reach it bare; the timeline title is "Did not join". `markNotJoining($employee, $reasonCode, $note, $actor, $on = today)`:
same rights as above, a reason from the option list `not_joining_reasons` (`OptionLists::LISTS`; built-in
`peopleos.lifecycle.not_joining_reasons`: accepted another offer, counter-offer, compensation, relocation, personal, health, higher
studies, background check outcome, offer withdrawn, did not report, other — "other" needs the note), never a future date. One
transaction: every in-progress onboarding plan is cancelled through `Onboarding::cancel` (pending items closed as skipped, their
feedback requests cancelled — nothing deleted); the planned position rows and reporting lines still open end the day before (a row
starting on or after that day gets an empty range, as a same-day change leaves one), so no team list, seat, organisation chart or
count holds them; `joining_date` is cleared and the planned date kept as `expected_joining_date` (joiner counts and joining-date reports
never count someone who did not join); the transition (reason "Not joining: …", metadata reason code, note, expected date); a linked
active or invited sign-in is suspended ("Did not join"). The record, person, documents, positions, lines and plans stay for retention
and rehire checks: `ReturningEmployees` still finds them (status "Not Joined", not currently employed, no joining date).
`UpcomingJoiners::notJoining()` reads the reason back from the transition; `reportees()` warns when people are set to report to the
joiner.

Never employed, everywhere a population is chosen: whitelists (`employed()`, payroll-eligible states, attendance month population,
compensation states, birthdays / reward states, report templates' active states, billable units, role rules) leave it out by
construction; the lists that left pre-joiners or leavers out now also leave out `not_joined` — `WorkforceMetrics` (headcount, headcount
trend, joiners), `PlatformAnalytics` and `WorkforcePulse` joiners, compliance report data and gratuity entitlements, the organisation
map, the People directory's default, team calendar (probation ends, joining), `EmployeeSelection::team`, identity-document and
insurance reminders, the exit hand-over's reportees, bulk change manager (a not-joined manager or employee is refused), bulk
invitations (refused), `ReturningEmployees::currently_employed`, the journey map (pre-boarding stage), the 360 header tone and the
"disable login" states offered (Setup → Security; nothing listed by default).

**Joining-day task (§23.11 #18).** `App\Domain\Employment\Support\JoiningDayTaskSource` (tagged in `peopleos.experience.task_sources`):
"X joins today" (and "X was expected to join on …" while still not marked as joined), due on the expected date, linking to the
Employee 360 (Mark as joined / Change joining date / Mark as not joining), for people with `employee.view` and `employee.lifecycle` or
`onboarding.manage`, inside their access scope (constrained explicitly for the user asked about), at most 50. Off unless Setup →
Workforce `employee.joining.day_task` is on.

**Download all documents (§23.3.1 #11; the WO-13 / XO-3 pattern).** `App\Domain\Documents\Services\EmployeeDocumentDownloads::request()`
(`document.view` and the employee's `view`; the employee's current documents — archived versions never) records an
`employee_document_downloads` row and queues `App\Domain\Documents\Jobs\BuildEmployeeDocumentDownload` (3 tries, 900 s, backoff 60 /
300, tenant id + `BindTenantContext`; claimed queued → running; `failed()` marks it failed). The job runs as the person
(`ScreenAccess::as`): the employee must still be in their access, each document passes its `view` policy (`Documents::bundle`, which
gained an optional purpose and audit metadata — default `download_all` as before), each document put in the zip is audited as a
DOWNLOAD (purpose `joiner_download_all`); a refusal of the whole set (nothing they may open, too large together) fails the download
with the reason. The zip is kept on the documents disk under `tenants/{id}/employees/downloads` for
`peopleos.lifecycle.joiners.download_hours` = 24; the person is notified with a signed link (`employees.documents.zip`,
`EmployeeDocumentDownloadController`, the protected chain) only they can use (audited DOWNLOAD); `peopleos:onboarding:start-due` (daily)
removes expired zips, the row stays.

**Profile-completion mail (§23.3.1 #8).** `App\Domain\Employment\Services\ProfileCompletionMails::send()` — off unless Setup → Workforce
`employee.joining.profile_completion_mail` is on, and only by hand ("Send profile-completion mail", a two-step wizard: what is missing —
the profile sections of `peopleos.lifecycle.joiners.profile_sections` not filled (`ProfileSections::filled`) and the document types
mandatory for onboarding not on file, prefilled — then the message: personal e-mail (default) and / or work e-mail, colleagues copied
at their work e-mail (inside the sender's access), a copy to the sender, a note). Needs `employee.message` and the joiner's `view`;
only for a pre-joiner. Subject and body from the notification template with key `employee_profile_completion_requested` when the
organisation has an active one (Notification templates), otherwise the built-in text (`peopleos.lifecycle.joiners.profile_completion`);
variables employee.*, tenant.name, initiator.name, joiner.expected_joining_date, joiner.missing (one line per item), joiner.note. It
carries NO sign-in link (WRK-7 / D-WO11 open): `app.url` is not among the variables, a rendered mail naming the application's host is
refused, and it is delivered as plain text without the mail layout (whose header links to the application) —
`NotificationMail` gained `plainText` and `EmailChannel` uses it for the events in `peopleos.notifications.plain_text_events`
(`employee.profile_completion_requested` only). One delivery per address on the notification delivery log (event
`employee.profile_completion_requested`, after commit; not a rule event, nothing fires it automatically); audited on the employee as
EMPLOYEE_EMAILED (purpose `profile_completion`, the sections and document types asked for, the kinds of address, copies, delivery ids —
never the body or an address).

**Data, permissions, settings.** Migration `2027_01_07_200001_create_employee_document_downloads` (foreign keys
`employee_document_downloads_employee_fk`, `…_requester_fk`; indexes `employee_document_downloads_tenant_requester_idx`,
`…_tenant_status_idx`). `DataInventory`: the table under `documents`, its zip as a file. Model
`App\Domain\Documents\Models\EmployeeDocumentDownload` (tenant-scoped, access-scoped through its employee, audited; status moves without automatic auditing). No new
permission key (`employee.lifecycle`, `employee.view`, `document.view`, `employee.message`, `onboarding.*` exist — no
`peopleos:sync-permissions`), no new audit action (UPDATE, DOWNLOAD, EXPORT, EMPLOYEE_EMAILED reused). Settings (Setup → Workforce,
both false): `employee.joining.day_task`, `employee.joining.profile_completion_mail`. Config: `peopleos.lifecycle.not_joining_reasons`,
`peopleos.lifecycle.joiners.{download_hours, profile_sections, profile_completion}`, `peopleos.notifications.plain_text_events`. Option
list `not_joining_reasons`. Route `employees.documents.zip`. Job listed in `docs/operations/queue-and-scheduler.md`.

**Tests.** `tests/Feature/HroneParity/UpcomingJoinersTest.php` (defaults: a future hire as before, no task, no mail, not a rule event,
the engine and the 360's state change refusing `not_joined`; the register — expected date, days to join, onboarding, background check,
documents pending, order, the organisation filter on the planned position, the desk link, the audited CSV, `employee.view` alone
refused, the access scope; changing the date — refusals (earlier than today, no reason, no right, already joined), the onboarding items
not started moved and the closed one kept, probation end, position and line re-dated, timeline and audit; not joining — reason list
and "other" note, onboarding cancelled and nothing deleted, no joining date, position and line ended, the manager's team, sign-in
suspended, never in `employed()`, the headcount (also marked after the planned date) or a payroll population, the not-joining filter,
the rehire check still finding them, no way out; the joining-day task — off, on the day, overdue, scope, rights, gone once joined; the
zip — rights and scope, the job's own check, archived versions out, audit per document, requester-only signed link, the job's tries /
timeout / backoff / tenant, expiry and pruning; the mail — off then on, nothing automatic, the delivery to the personal e-mail with
what is missing and no link, the audit without the body or address, plain text, the organisation's template, a link refused, the
refusals).

Not built here: the joiner filling their own profile, document upload and HR's review / send-back (§23.3.1 #7, #10, #12 — WRK-7 /
D-WO11); "Blacklisted" joining status (pre-joiners have no rehire block); a per-send editable subject / body and saved mail templates
beyond the notification template; BCC; a bulk "Send profile-completion mail" (HROne's filter-then-mail); moving a joiner's salary
assignment with the joining date (compensation effective dates are left as they are); reinstating someone marked as not joining (an
end state — they come back as a new hire); the pre-joiner's planned position in the access scope (scoped HR reaches a joiner from the
planned date).

## Desks inside their areas (2026-10-31)

The desks were rail areas of their own (`ExperienceNavigation::MODULE_AREA`), so the People rail listed the same registers
twice: Time & leave and "Time office desk", Pay and "Payroll desk", Lifecycle and "Workforce desk", Growth and "Performance
desk" (also Assets / "Asset desk" under Organisation, Engagement / "Engage desk" under Communication). Each desk's entries are
mostly its area's own screens (Time office desk 27 of 36, Payroll desk 27 of 38; the rest are reports, setup and self pages),
so the two were merged rather than renamed.

- `ExperienceNavigation::DESKS` lists the desks in the order they lead an area; they left `MODULE_AREA`. A desk falls into the
  area its navigation group gives it (the space, or the group in a one-space section) and is that area's front page: the area
  opens on it (`areaLanding()` tries `DESKS` before `AREA_LANDING`) and the area bar shows it after the person's favourites and
  before the everyday modules (`EVERYDAY` stays at most six). Pay holds two desks: it opens on the Payroll desk.
- Only operators can open a desk (each desk's own `canAccess()`); everyone else opens the area on its old landing.
- One rename, where the names collided over different things: `WorkforceDesk` is labelled **Lifecycle desk** (the employee
  journey — onboarding, confirmations, transfers, separation, letters), because "Workforce" is the Organisation area of
  positions and workforce plans. The class, slug (`workforce-desk`) and URLs are unchanged.

Tests: `tests/Feature/Experience/DeskAreasTest.php` (no "… desk" rail areas, each area opens on its desk, the desk leads the
bar and shows "All in {area}", non-operators keep the old landing); the payroll and expense desk tests assert `DESKS`.

## PO-4 Run cockpit

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (package PO-4; §25.6.1 #2, #3, #6, #7; §25.6.3 #2, #4, #9;
§25.6.4 #1, #12; §25.6.5 #2, #3; §25.6.6 #3, #7; §25.6.8 #1–#5, #7, #8; §25.6.10 #1, #2; §25.6.11 #12), rows marked
"→ built (PO-4 …)". Every default reproduces the earlier behaviour: `payroll.auto_calculate_day` is 0 (never), so no run is
calculated by the scheduler; `CalculatePayrollRun` without its new `notify` flag behaves exactly as before (no notice); every
entry starts unchecked and the tick is never read by validation, approval or finalization; the run page keeps its header
actions, details, totals and history, and the entries table keeps every column, filter and action it had. No new money logic:
nothing here prices, settles or changes an amount — calculation stays in `PayrollRuns` / `PayrollCalculator` and the input
sources; the cockpit only reads. Owner decision PAY-1 / D-PO1 (finalized payroll is never reopened) holds: a finalized or paid
run is never recalculated (screen, service, job, scheduler), and its entries can no longer be ticked. PAY-17 is followed for
the new CSV: the right is `payroll.view`, like the other payroll exports.

**Read model (`Payroll\Services\PayrollRunCockpit`).** One run as HROne's salary dashboard steps — Inputs → Calculate → Verify
(optional, HROne's "Non mandatory") → Approve → Finalize and publish → Pay — each `done` / `current` / `upcoming` from the run
status (draft → Inputs; calculated → Verify; validated → Approve; approved → Finalize; finalized → Pay; paid → all done), with
counters (`counters()`), each read from the records it names in the reader's access scope:
- the run's people: its entries once calculated, otherwise the population a calculation would take (`PayrollRuns::populationIn`,
  unchanged); "Calculated" / "Not calculated yet" (§25.6.4 #1), blocking exceptions, warnings;
- attendance arrears of those people dated before the pay period: pending (what the next calculation takes) and held; "in this
  run" = the arrear ids the run's lines carry; settled in this pay period (`settled_period_id`); void = voided since the
  company's previous finalized payroll (up to this run's finalization) (§25.6.3 #2);
- overtime: entries with approved overtime minutes as the calculation recorded them (`inputs.attendance`), their hours and the
  entries with the `overtime_unpaid` exception (§25.6.3 #9);
- per payroll input source (variable pay, claims, loan instalments and payouts, encashments, shift / transfer allowances,
  reward points, expense reports and advances): the record ids the run's lines carry, read from the entries'
  `inputs.input_sources` (what `PayrollCalculator::inputSources` recorded — the JSON read is narrowed to that key), and the
  register's approved records of the run's people not carried by it (approved after the calculation, or due later);
  payroll adjustments of the period (approved / waiting);
- salary holds of the pay month (held / released, §25.6.6 #3, #7); reviewer ticks ("n of N checked"); approved by / on;
  payslips issued; the salary pay mode in force at the period end (bank transfer / cash / cheque, no mode = bank transfer);
  paid = the run is marked paid and the pay is not on hold.

**Step tables (§25.6.3 #4, #9; §25.6.1 #7).** `table($run, $table, ?$employeeIds)`: attendance arrears (employee, arrear date,
pay arrear (for) month, generated days, status before → after, status, salary month = the settled period or this run, in this
run), overtime (hours, overtime paid = the lines whose component formula reads `overtime_*`, the formula as the rate, no
overtime component), payroll inputs in this run (the input-source lines: input, pay line, type, amount, records), salary holds
of the month (status, reason, held by / on, released on, payment reference) and salaries (pay mode, paying account, hold,
status, paid / LOP days, gross, deductions, net, issues, checked by / on). `csv()` writes any of them (formula-injection-safe
cells), audited as `EXPORT` of the run (module `payroll`, metadata `export = payroll_run_step`, `table`, `rows`, `selection` =
all / table / selected, `employees`); right `payroll.view` (PAY-17).

**Entries table (§25.6.4 #12, §25.6.5 #2).** `EntriesRelationManager` gained the columns Pay mode, Paying account (hidden by
default), Hold and Checked; filters pay mode, paying account (the company's active paying accounts or none — the account is
`PaymentModes::payingAccount`, as the bank file chooses it), hold (held / released / none) and checked; next to PO-1's pay group,
codes, organisation and pending-resignation filters. Selection: "Mark as checked", "Clear the check", "Download CSV
(selected)"; header "Download CSV" (the rows the filters and search show). PO-5 will add its per-employee actions to the same
selection.

**Calculate in background (§25.6.1 #3).** Header action "Calculate in background" / "Recalculate in background" on the run
(`payroll.calculate`, editable runs only) → `PayrollRunCockpit::queueCalculation()`: refused without the right, for an approved
run ("Reopen it first") and for a finalized or paid run ("finalized payroll stays as issued"), and while a calculation of the run
already waits (the job is unique per run: the lock is tested first, so the request is refused instead of silently dropped);
audited `SCHEDULED` (`calculation = background`), then queues `CalculatePayrollRun($run, $actor, notify: true)`. The job is
unchanged in its guarantees (tries 2, timeout 3600 s, backoff 60 s, tenant id + `BindTenantContext`, unique per run, a run
that is no longer editable left as it is); with `notify` it tells the person in the app and by e-mail through `Notifier`
(event `payroll.calculated`, already listed): "Payroll calculated: Sep 2026 · Company" (employees, exceptions), "Payroll not
calculated" (the run was approved / finalized meanwhile) or, from `failed()` (which binds the tenant itself), "Payroll
calculation failed".

**Automatic calculation (§25.6.1 #6, §25.6.10 #1, §25.6.11 #12).** Setting `payroll.auto_calculate_day` (Setup → Payroll,
0–31; 0 = never, the default; a day past the month's end runs on its last day). Command `peopleos:payroll:auto-calculate`
(`{--date=} {--tenant=}`, daily 05:15, `withoutOverlapping()->onOneServer()`, through `TenantRunner`, once per tenant and month
via `scheduler_claims` key `payroll.auto_calculate`) → `PayrollRunCockpit::autoCalculate()`: every run still open (`draft`,
`calculated`, `validated` — `PayrollRun::EDITABLE`) is audited `SCHEDULED` (`calculation = automatic`, the day) and queued as
`CalculatePayrollRun` (no actor, no notice). Approved, finalized and paid runs are never touched; nothing is validated, approved
or finalized and no run is opened. Listed in `docs/operations/queue-and-scheduler.md`.

**Reviewer tick (§25.6.5 #3).** `payroll_entries.reviewed_by` / `reviewed_at` (migration `2027_01_08_200001_add_review_to_
payroll_entries`, foreign key `payroll_entries_reviewed_by_fk` → users, null on delete). `PayrollRunCockpit::review($run,
$entryIds, $actor, $checked)` ticks or clears entries for `payroll.calculate` or `payroll.approve` holders, under the run's row
lock (taken first, as calculation and finalization take it), inside one audit operation: each change is a `REVIEWED` event on the
entry (reviewed_by / reviewed_at before → after; metadata `payroll_run_id`, `employee_id`, `checked`). Refused on a finalized
or paid run, per entry on one's own salary and outside the access scope (or an entry no longer in the run); a second tick is
"already so". A recalculation replaces the run's entries, so every new entry starts unchecked (the trail keeps the earlier
ticks). Never read by validate, approve or finalize. Screen: the row action "Mark as checked" / "Clear the check", the bulk
actions and the "Checked" column / filter (shown while the run is calculated, validated or approved).

**Run cockpit on the run page.** `Filament\Resources\PayrollRuns\Widgets\RunCockpit` (header widget of `ViewPayrollRun`, lazy,
not discovered on the dashboard; `payroll.view`; another tenant's run is not found): the six steps with their counters; the
step tables with their counters, "Open" (first 200 rows) and "CSV"; the inputs the run carries; **Reminders** (§25.6.8,
`Filament\Support\PayrollReminders`): pending proposed investment declarations (submitted), proofs to verify, loan approvals,
loans to dispense, reimbursement claims waiting, holds to release, approved encashments — each count from its register's own
scoped query and each linking to that register's preset view (only registers the viewer can open; counts across companies, as
the registers list them); **Run activity** (§25.6.10 #2) from the audit trail (read-only): run opened, calculations (who, when,
employees, exceptions, results replaced), background / automatic calculation queued, salaries checked (one line per check
action), validated, approved (with the note), returned to draft, refused reopen attempts, finalized (payslips), payment date,
bank file and step-table downloads, marked as paid — a transition audited twice (the model's own UPDATE and the service's event)
is shown once.

**Data, permissions, settings, wiring.** Migration above (columns on an existing table already classified under payroll in
`DataInventory`). No new permission key (`payroll.view`, `payroll.calculate`, `payroll.approve`; the setting under
`settings.update`). New setting `payroll.auto_calculate_day` (0). New command `peopleos:payroll:auto-calculate` (scheduled).
No new notification event (`payroll.calculated` reused), no new audit action (`SCHEDULED`, `REVIEWED`, `EXPORT` exist). Shared
files touched: `CalculatePayrollRun` (optional `notify`, `failed()`), `PayrollEntry` (cast, `reviewer()`), `PayrollActions`
(one action), `ViewPayrollRun` (header widget), `EntriesRelationManager`, `config/peopleos.php`, `config/peopleos_setup.php`,
`routes/console.php`, `docs/operations/queue-and-scheduler.md`.

**Tests.** `tests/Feature/HroneParity/PayrollRunCockpitTest.php` (defaults: day 0 does nothing, the job without `notify` sends
nothing, entries unchecked, finalize as before; counters equal to the underlying records before and after calculation —
arrears pending / held / void / in the run / settled, overtime, variable pay in the run and waiting, loan payouts, adjustments,
holds, pay modes — and the step tables behind them; steps through validate, approve, finalize and paid with settled arrears,
payslips, released holds and paid salaries; reminders with their counts, links and the viewer's registers only; entries filters
by pay mode, paying account, hold and check, bulk tick, selected and filtered CSV audited; access scope on counters, tables, CSV,
the tick and the table, no cockpit or export without payroll.view; background calculation queued for the run, tenant and
person, refused twice, told when done / not done / failed, refused and hidden on approved and finalized runs, the finalized run
unchanged by the job; automatic calculation only on its day, only open runs, once a month, scheduled with overlap and server
guards; the tick audited per entry under one operation, idempotent, cleared on recalculation, refused on one's own salary,
without the rights and once finalized, never blocking; the activity in order with details; the widget's steps, tables, CSV and
tenant isolation; the run page renders).

Not built here: per-employee recalculation and exclusion from the selection (PO-5); bulk hold, release month, released-holds file,
per-entry payment status and publish (PO-7); "Ignore" of an arrear (PO-6); revision and previous-month overtime arrears (PO-20);
a notice to someone when the automatic calculation finishes (open point); reminders narrowed to the run's company.

## AC-1 Actions panel (2026-10-31)

HROne's HR Desk **Actions** (68 global actions: search, "Recent actions", pinned actions, every action the role may run by
module) as PeopleOS's **Actions** panel. Recruitment and Candidate engagement are out of scope (owner, 2026-10-31), so the
panel has eight sections: Workforce, Time office, Payroll, Expense & travel, Performance, Engage, Assets, Helpdesk.

**Where.** `App\Domain\Experience\Services\ActionCatalogue` (request-scoped, `ParityServiceProvider`) is the list: one row per
action — key, section, PeopleOS label, hint, the HROne name (searchable, so HROne words find the PeopleOS action), keywords and
a URL resolver. A resolver returns the module's own screen or form (`?action=` mounts the existing Filament action) only when
that destination's own `canAccess()` / `canCreate()` / permission / action visibility lets the signed-in person in; otherwise
the action is not offered. Every "… for an employee" action comes from `RequestRegistry::onBehalf()` (each request type's
`onBehalfUrl()`), so a request type that gains an HR form shows up without touching the catalogue. The catalogue answers only
for the signed-in person (fail closed, like QuickActions). `App\Livewire\Experience\ActionCentre` renders it: full-screen
panel from the top bar's **Actions** button (`pos-actions-open`; the button and panel are rendered only for people with at
least one action), or inline on `App\Filament\Pages\ActionsPage` (`/admin/actions`, not in the rail). Search filters in the
browser over label, HROne name, section and keywords; "Recent actions" (`recent_actions`, 5) and "Pinned" (`pinned_actions`,
12) are `ExperiencePreferences` keys, re-resolved through the catalogue on every render (a pin for an action the person can no
longer run is not shown). **Run** records the recent action server-side (re-resolving the key) and redirects; ctrl/⌘-click
opens a new tab normally. The Command Center finds every catalogue action (QuickActions keys `hr_action.*` and
`all_actions`, after all other actions, never featured on Home — `QuickActions::isPanelAction`). Styles: theme.css §L.

**Mapping (HROne → PeopleOS).** Workforce: Add employee → hire wizard; Add new hire (upcoming join) → hire wizard with
`?upcoming=1` (joining date starts blank, must be in the future; title "Add new hire (joining later)"); Change manager → bulk
change manager (WO-16); Generate letter → Letters `generate`; Invite to HROne → "Invite to sign in" (WO-16); Resignation request
→ on-behalf form of the resignation request type; Transfer request → Transfer requests `request`. Time office: regularisation,
leave, leave encashment, on duty, restricted holiday, shift change, short leave → their on-behalf forms; Change attendance status
(TX-6); Change shift → Roster board (TX-5); Run attendance (TX-3). Payroll: Assign salary structure → salary structure register,
"not assigned" view (PO-2); Confirmed / Propose investment, Loan, Reimbursement, Variable pay → on-behalf forms; Generate challan
→ Compliance reports; Increment calculator → compensation cycles; Run payroll → payroll control room. Expense: Add expense,
Advance, Travel → on-behalf forms; Pay expenses (XO-4); Recover advance (XO-6). Performance: Add PIP; Change goal → employee
goals; Create company goal; Delete goals / Delete reviews → cycles to "Withdraw from cycle" (GO-2; nothing is deleted, HP-8k);
Extend 360 / goal / review (HP-8h, GO-2); Initiate 360 (`launchRound`); Initiate goal cycle → new cycle; Initiate review cycle →
running cycles; Normalise performance / review ratings and Publish → calibration board actions (GO-3). Engage: announcement,
HR handbook article, survey. Assets: add asset, asset request for an employee. Helpdesk: ticket for an employee. The actions
built in this programme's other packages joined the catalogue as each merged: Add business partner (WO-15), Suggest a
product improvement (AC-3, HROne "Product enhancement request"), Share letters and documents (WO-14), Carry forward leaves
(TX-8a), Change holiday for employees (TX-12), Encash leaves (PO-8; the single "Leave encashment for an employee" comes from
the request registry), Process bonus (PO-22), Generate challan → the challan register (PO-13; Compliance reports for people
who only open that), Increment calculator → the calculator wizard (PO-17; compensation cycles as fallback), Add event
(GO-15), Start a fundraiser (GO-18); reimbursement, investment declaration and proofs for an employee come from AC-2's
on-behalf forms. All 60 HROne actions outside recruitment (Workforce 10, Time office 12, Payroll 11, Expense 5, Performance 14, Engage 5, Asset 2, Helpdesk 1) now have a PeopleOS action.

**Data, permissions.** No migration, no new permission, no new setting. Preference keys `pinned_actions`, `recent_actions`.

**Tests.** `tests/Feature/Experience/ActionCentreTest.php`: an administrator gets the eight sections and no recruitment action;
every action's URL opens (HTTP 200) for the administrator; a leave operator gets only leave actions; a self-service employee
gets none, a 403 on the page and no button; the catalogue answers only for the signed-in person; the top bar shows the button
and panel; run remembers and redirects, an unknown key does nothing; pin / unpin and the recent / pinned strips; pins of actions
no longer allowed are hidden; the Command Center finds the actions, Home never features them; the hire wizard's joining-later mode.

**Launcher redesign (2026-11-01).** The owner asked for the panel, and launchers like it, to be more attractive. The Actions
panel now opens on a hero band in the Home hero's ink-to-iris (title, "N actions across M modules", a 48 px search, recent and
pinned actions as glass chips with their module icon, faint orbit rings); only the body scrolls. Each module gets an icon
tile, a count and its own wayfinding hue; the list hangs off a guide line that lights up beside the hovered action, and pins
show in gold. Loading is a skeleton; empty and no-match states have an icon. Hues are new tokens `--pos-hue-{iris, blue,
green, gold, plum, teal, slate, coral}` (+ `-bg`), designed for light and dark. They are for finding your place only: words
carry the meaning, and they are never a status. The Command Center (top-bar search, "New", Ctrl K) got the same treatment in
`theme.css` §M: a brand hairline on top, an iris search glyph, a segmented scope switch, group labels with counts, result icons
tinted by kind and an iris edge on the highlighted result. Behaviour, markup hooks and keyboard handling are unchanged.

## TX-12 Holiday changes for chosen employees

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-12; §24.7.2 #1–4, 6, 7; §24.8 #10; §24.13 #5 — HROne
"Manage holiday" / global action "Change holiday", `assignholiday_1160`, components `app-assign-holiday-popup`, `-form`,
`-step1`, `-step2`, `app-holiday-bank`), rows marked "→ built (TX-12)". Owner decision TIM-3 / D-TX3 (recommended default):
per-employee changes are dated exceptions on top of the holiday calendars, and the change wins for that day. Every default
reproduces the earlier behaviour: no change row exists, so `HolidayResolver::holidayOn` answers from the calendar exactly as
before (same holiday, same record, same calculation-basis keys); the team option is off.

**Data.** Migration `2027_01_23_200001_create_employee_holiday_changes` — `employee_holiday_changes` (model
`App\Domain\Attendance\Models\EmployeeHolidayChange`, `BelongsToTenant` + `Auditable` + `ScopedByEmployee`), one row per day
changed: `effect` add (the day becomes a holiday: `name`, `holiday_type` public / company / optional, `is_restricted`) or
remove (the employee's calendar holiday of the day stops being one; its name, type and `holiday_id` are kept); `action` is
what HR chose — assign (one add row), remove (one remove row), replace (a remove row and an add row sharing `group_key`);
`source` typed / calendar (`holiday_id` of the picked holiday) / library (`holiday_library_entry_id`); `reason`, `created_by`.
Rows are never deleted: undo sets `status` undone, `active` null, `undone_by`, `undone_at`, `undo_reason`; the unique key
`(tenant_id, employee_id, date, active)` allows one live change per employee and day. Classified under attendance in
`DataInventory`.

**The resolver (D-TX3).** `HolidayResolver::dayOn(employee, date)` → `{holiday, change}`: a live change of the day wins — an
add row answers an unsaved `Holiday` built from the change (`EmployeeHolidayChange::asHoliday()`: no id, no calendar; a
restricted one is of type optional), a remove row answers no holiday; otherwise `calendarHolidayOn()` (the earlier logic,
restricted holidays only when chosen) and a null change. `holidayOn()` returns `dayOn()['holiday']`, so every reader follows
the change without being touched: attendance processing, leave day counting (`LeaveDayCounter`), request rules, the
week-off swap check, day details, the HR day drawer, the glance, the social wall's holiday line. The processor writes
`calculation_basis.holiday_change_id` only on a day a change decides (`holiday_id` is then null for a given holiday).
Leave already applied keeps the days it counted when it was applied (as when a calendar holiday is added later).

**The service.** `App\Domain\Attendance\Services\EmployeeHolidayChanges`:
- `prepare(data)` checks the change once (plain values, storable in a bulk run): action; for remove / replace the holiday of a
  holiday calendar whose date is taken away; for assign / replace the holiday given — typed (name ≤ 150, date), from a
  holiday calendar, or from a published holiday-library list (whatever id the form sends) — and the restricted flag (type
  optional; otherwise the source's public / company, typed = company); a replace must give another day; every day within
  the limits.
- Limits: from `earliestDate()` = the first day of the month `attendance.holiday_change_backdate_months` (default 2, 0–24)
  before the current one (HROne's note "two months prior to the current month … contact your administrator"), up to the
  end of next year.
- `apply(employees, prepared, reason, actor)` — each employee on their own, result `{done[], refused[]}`; refused: outside
  the actor's access scope, one's own holidays, not employed (lifecycle, or the day before joining / after leaving), a locked
  (finalized) day or a day of a closed attendance month unless `attendance.allow_corrections_after_lock` (then the locked day
  keeps its values and processing records any arrear), a day already changed ("undo that change first"), a removal where the
  employee's calendar gives no holiday that day, a removal of a restricted holiday the employee chose (their request is
  cancelled instead), a holiday given on a day their calendar already makes one. One `AuditRecorder::operation` (or the
  caller's, inside a bulk run); each row audited with the reason. A prepared change is checked again when applied (limits
  of that moment).
- `undo(change, reason, actor)` — both rows of a replace, same rights, scope, limits and lock rule; audited update.
- Recalculation: the employee's days among the changed ones that are already processed (an attendance record exists, up to
  today) are queued through `ProcessAttendanceDay` (reason `holiday_change`, after commit); future and unprocessed days are
  processed as usual later.
- Who: `attendance.holiday_change`; with `team.holiday_assign` on, also a manager for the people who report to them today
  (any reporting type, in scope, never themselves) — `refusal()`, `mayUse()`, `teamQuery()`.

**The page (§24.8 #10, §24.7.2).** `App\Filament\Pages\HolidayChanges` (slug `holiday-changes`, Attendance → Holiday changes;
the Time office desk lists it under Holiday; the Setup centre's Time office links) — a `RegisterTablePage` with WO-1's
register tools (register key `holiday_changes`): the changes made, with day, employee, change (given / taken away), holiday
and type, preference, source, reason, by and on, status (undone with who, when, why); filters pasted codes, organisation,
day range, preference, change, status, source; row actions "Undo" (reason; only on live changes the viewer may change) and
"Calendar". Header action **"Change holiday"** (`changeHoliday`; `?action=changeHoliday` opens it on load) — a step wizard in
HROne's order: Preference ("Choose your preference": Assign / Remove / Replace holiday, the holiday to remove or replace —
holidays of the active calendars within the limits, grouped by calendar) → Employees ("Add bulk employees" codes, "Search
employees", the WO-2 organisation dimensions incl. location, status, type, "Everyone you can change"; a live count with the
first names; employed people or leavers within the limits, inside the access scope, only the team for a manager) → Holiday
(the back-dating note; "Is this a restricted holiday?"; typed name and date, or a pick from a holiday calendar or from the
holiday library — published lists of the organisation's countries, national and the regions its locations are in) →
Review (what happens, reason, the typed count above `peopleos.bulk.confirm_over`). Up to 25 employees change at once and the
page lists each one changed or refused; larger selections start a WO-1 bulk run (`bulk.run` too): operation
`attendance.holiday_change` (`Experience\BulkOperations\ChangeHolidayOperation`, model Employee, own holidays refused up
front, each employee through `apply`). `canAccess()`: `attendance.view` and `EmployeeHolidayChanges::mayUse()` (the right, or
the team option with at least one report); `mount()` aborts otherwise.

**Where employees see it.** `HolidayYear` (My holidays): a holiday given joins "Fixed holidays" — or "Restricted holidays"
with the choice `given` ("Given by HR") when restricted — with the note "Given to you by HR"; a holiday taken away leaves the
lists and is listed under "Not holidays for you this year"; the years offered include years with changes; with no calendar
but changes the lists still show. The attendance calendar's month list marks a holiday taken away "Taken away for this
employee" (not a day off) and adds a holiday given ("… · given by HR", a day off); the roster board takes changed days as
candidates. A restricted holiday given by HR is a day off for the employee without a restricted-holiday request and does not
count towards the calendar's restricted-holiday quota (open point below).

**Settings, permissions, wiring.** Settings `attendance.holiday_change_backdate_months` (2) and `team.holiday_assign` (false),
both in Setup centre → Time office. New permission `attendance.holiday_change` (attendance module) split out of
`attendance.manage` (`peopleos.permission_splits`: `attendance.manage` still grants it; Tenant HR Admin and Attendance Admin
get it through `attendance.*`; a role the tenant took `attendance.manage` from gains nothing on deploy) → run
`php artisan peopleos:sync-permissions`; added to `TimeOfficeDesk::OPERATOR_PERMISSIONS`. `ParityServiceProvider` tags the bulk
operation. The TX-2 test listing the split keys now includes the new key.

**Tests.** `tests/Feature/HroneParity/EmployeeHolidayChangesTest.php` (defaults: no rows, same resolver answers and basis;
assign / remove / replace for the chosen employees only with recalculated days and the basis key; picks from a calendar and
the library, withdrawn lists refused; the change winning over a calendar holiday added later and over the calendar's
holiday; every refusal; the back-dating limit, the setting, undo behind the limit, a stored change re-checked; recalculation
only of processed days through the job, undo too; My holidays, the attendance calendar, the roster board and the glance;
the page wizard by codes / filters / search, the list, filters and Undo; `?action=`, desk and setup links; the bulk run and
its refusal without `bulk.run`; access and scope; the split, role templates and the deploy guard; the team option; the data
inventory and no payroll words).

Not built here: the team page entry (`app-assign-holiday-from-team`, RQ-11 places it on the team workspace over this service);
an assigned restricted holiday that waits for the employee's restricted-holiday request (needs requests to point at a
change, open point); half-day holidays given per employee; changes before the back-dating limit (an administrator raises the
setting).

## AC-2 HR requests on behalf

Programme: HR Desk **Actions** panel (2026-10-31), group "Request on behalf". Matrices: `docs/hrone-parity/modules/25-payroll-operations.md`
§25.3.1 #3, §25.3.2 #3, §25.3.3 #3 and `docs/hrone-parity/modules/20-requests-inbox-team.md` §20.1.0 #15 and R.2.7 #1 (rows marked
"→ built (AC-2 …)"). Every HROne "Request on behalf" action now has a PeopleOS form for HR. Leave, regularisation, the time-office
kinds, loan, variable pay, helpdesk ticket, asset, travel and expense were there already; leave encashment on behalf belongs to
PO-8 (`LeaveEncashmentRequestType` is untouched here). Defaults keep today's behaviour: the employee's own claim, declaration and
proof forms, their fields, rules and audit trail are unchanged. No migration, no new permission (no `peopleos:sync-permissions`),
no new setting.

**Picker (`Filament\Support\OnBehalfPicker`).** This is the generic form of XO-5's `ExpenseOnBehalf`, keyed by the module's on-behalf
permission and its data-access module (CA-9):
- `candidates($module, $base)` lists the scoped `Employee` query (or a narrower `$base`, e.g. employed people only), narrowed by
  `AccessScopes::constrainEmployees($query, $user, $module)` and leaving out one's own record.
- `select()` searches those candidates with the WO-2 employee search. Any employee in the access scope gets a label, one's own
  too, so the form can say "use your own request". An employee outside the scope fails the select's validation.
- `resolve($id, $permission, $module, $ownAction, $base)` refuses three cases: no permission ("You cannot file this for other
  employees."), one's own record ("This is your own record: use "<own action>"."), and outside the scope ("Choose an employee in
  your access scope.").

**Reimbursement claim for an employee (§25.3.1 #3, HROne `reimbursement_110` "Apply reimbursement").**
`ListReimbursementClaims` has a new action `claimForEmployee`, "Claim for an employee". It needs `payroll.manage` (the right that
decides claims: `LoanPolicy::approve`, which `ReimbursementClaim` uses). It is a wizard: Employee (picker, request header), Claim
(the same fields as "Claim a reimbursement", shared through `claimFields()`: reimbursement with the *employee's* "Available to
claim", claim date, amount, description, bills, attachments), Review (who approves it, `RequestChrome::route('reimbursement')`).
- The action calls `ReimbursementClaims::claim($employee, …, requester: the HR user)`. The employee's balance, bill
  (`not_above_bills`), attachment, per-claim limit, window, new-regime and one-open-claim rules apply as for the employee.
- The claim is `pending` and raises `payroll.claim_submitted`. It waits for the same workflow or Approval Center approvers. It is
  never approved here.
- The service now refuses a claim filed by someone other than the employee, unless that person holds `payroll.manage` over the
  employee in the payroll access scope ("You cannot claim a reimbursement for this employee."). The employee's own claim, and
  calls with no person (console), are as before.
- On-behalf marker: `requested_by` (as before) names the filer. Only when the filer is not the employee does the SUBMITTED audit
  event add `on_behalf` and `employee_id`.
- The claims list has a new column "Filed on their behalf by" (toggleable, hidden by default). The Approval Center item already
  shows "requested by".
- The filer is not barred from approving the claim (the same as loans and XO-5 advances): see the open point.

**Investment declaration for an employee: proposed (§25.3.2 #3, `purposedinvestmentdeclaration_114`).** `DeclareInvestments` has a
new header action `declareForEmployee`, "Declare for an employee" (`payroll.manage`). Its modal asks for the employee (picker over
`InvestmentDeclarations::employeesFor`, i.e. employed people in scope, never one's own) and the financial year, then calls
`fillFor($employee, $year)`. The same wizard then shows that employee's declaration, with the request header "You are raising
this request for …" (hidden on one's own).
- Window rule: the codebase already lets payroll file outside the window (`windowOpenFor`: `payroll.manage` always may), and
  `payroll.tax.payroll_may_edit` (PS-2) still applies. Both are reused; nothing new is bypassed.
- `InvestmentDeclarations::save` records a declaration saved by someone other than the employee as on their behalf: each audit
  reason ends "(filed on the employee's behalf)", and on submission a SUBMITTED event carries `on_behalf`, `employee_id` and
  `financial_year`. The employee's own saves are unchanged. The page's existing employee select (payroll may pick anyone in scope)
  is marked the same way.

**Investment proof for an employee: confirmed (§25.3.3 #3, `confirmedinvestmentdeclaration_181`).** The Tax declarations register
(`ManageTaxDeclarations`; open to `payroll.view`, so payroll admins reach it) has a new action `submitProofForEmployee`, "Submit
proof for an employee" (`payroll.manage`). It is a wizard:
- Employee (picker).
- Item: the employee's declared items whose proof is not verified, labelled "FY · section · item · declared ₹ · proof due /
  proof sent, to verify / proof rejected", with payroll's note on a rejected proof.
- Proof: the same fields as the employee's "Submit proof", i.e. files (image or PDF, private) and the amount the proof shows.
- Review.

The action calls `InvestmentProofs::submit($item, $files, $amount, HR)`. This is the existing payroll path: `payroll.manage` may
submit at any time, so the proof window is not newly bypassed. The proof then waits for verification. `review` still refuses
one's own proof, and the TDS proofs register is unchanged. The service now also refuses payroll outside its payroll access scope
("You cannot submit proofs for this employee."). A proof sent by someone other than the employee is audited "Investment proof
submitted on behalf of the employee", with a SUBMITTED event carrying `on_behalf`.

**Request types.** `onBehalfPermission()` / `onBehalfUrl()` feed "Raise for someone" on New request and the orchestrator's Actions
launcher. Each URL is the module page with `?action=`, which Filament mounts on load:

| Type (`key`) | Permission | URL | Shown when |
|---|---|---|---|
| `reimbursement` | `payroll.manage` | `ReimbursementClaimResource` index `?action=claimForEmployee` | the resource opens and a pay-on-claim reimbursement component exists |
| `investment_declaration` | `payroll.manage` | `DeclareInvestments` `?action=declareForEmployee` | `DeclareInvestments::canAccess()` |
| `investment_proof` | `payroll.manage` | `TaxDeclarationResource` index `?action=submitProofForEmployee` | `TaxDeclarationResource::canAccess()` |
| `expense_advance` | `expense.approve` | `ExpenseAdvanceResource` index `?action=requestForEmployee` (XO-5) | `ExpenseOnBehalf::offered()` and the resource opens |
| `resignation` | `exit.manage` | `ExitCaseResource` index `?action=initiate` ("Initiate exit"; the type defaults to resignation) | `exit.manage` and the resource opens |

`NewRequest::ON_BEHALF_PERMISSIONS` now includes `exit.manage`, so an exit manager without an employee record also reaches "Raise
for someone". The Payroll desk's "Tax & benefit" section adds three entries, shown to `payroll.manage` only: "Claim a
reimbursement for an employee", "Declare investments for an employee" (now opens the action) and "Submit an investment proof for
an employee". The "Payroll actions" menu adds `posPayClaim` and `posPayProof`, and `posPayInvestments` now opens
`?action=declareForEmployee`.

**Files.** New files: `app/Filament/Support/OnBehalfPicker.php` and `tests/Feature/HroneParity/HrOnBehalfRequestsTest.php`.
Changed files:
- Services: `ReimbursementClaims`, `InvestmentDeclarations`, `InvestmentProofs`.
- Pages and resources: `ListReimbursementClaims`, `ReimbursementClaimResource`, `DeclareInvestments`, `ManageTaxDeclarations`,
  `NewRequest`, `PayrollDesk`, `PayrollRegisters`.
- Request types: `ReimbursementClaim`, `InvestmentDeclaration`, `InvestmentProof`, `ExpenseAdvance`, `Resignation`.

**Not built here.**
- Managers raising these for their reports (RQ-16; the services require `payroll.manage`).
- A required remark when HR edits a declaration (RQ-7).
- Bulk proof upload (PO-11).
- Barring the filer from deciding what they filed (owner point).

## WO-15 Business partners and vendor master

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-15; §23.8 #1–6, #10 and §23.9 #21 are marked "→ built
(WO-15 …)"; §23.8 #7–8 were HAVE and now act on the partner card too; #9, the partner / vendor upload, is §23.10.1 #4 — WO-17,
which writes through the services below); `docs/hrone-parity/setup/01-core-hr-access-integration.md` §1.33 (the vendor master:
code, name, address, GST number; contacts beyond one and vendor custom fields not built); matrix 21 Workforce #3 (the report).
Owner decision D-WO2 is open; the default applied is the one the package brief set: partners are allowed, a partner's sign-in has
**no employee record and no access except the roles an administrator gives**, through the existing delegation ceiling. Every
default reproduces the earlier behaviour: no partner or vendor exists until someone adds one, every existing account is
`standard`, the Users list, the employee form and every role are as before (one more column / filter on Users, partner sign-ins
left out of the employee form's "Login user").

**Vendor master (§1.33).** `App\Domain\Partners\Models\Vendor` (table `vendors`; `BelongsToTenant`, `Auditable`, module
`partners`): code (unique per tenant, compared without case), name, kind (option list `vendor_kinds`: agency, supplier,
consultant, other — `peopleos.partners.vendor_kinds`, extended on Setup → Option lists), GST registration number, PAN (encrypted
at rest, hidden, masked in the audit trail, its last four in `pan_last4`; `peopleos.data_classification.highly_sensitive`), a
contact (name, e-mail, phone), address lines, city, state / region code, postal code, ISO country (IN by default), status active /
inactive (never deleted). Written only by `App\Domain\Partners\Services\Vendors::create(array $data, User $by)` /
`update(Vendor $vendor, array $data, User $by)`: `partner.manage` (PartnerPolicy) for the actor's own organisation; validation in
the service whoever calls it (ValidationException keyed by field) — code letters / digits / `- _ /`, kind from the list (a kind
hidden since stays valid on the vendors that have it), the GST number in the format of the `gstin` registration type
(`RegistrationNumberFormat`: pattern, check character, and the state code it starts with when an Indian state is given — the
same check as Statutory registrations), PAN ABCDE1234F and, with a GST number, the PAN inside it, e-mail, phone, ISO country;
`update()` changes only the fields given (an upload may send some columns) and keeps the PAN when none is given; `tenant_id` and
unknown keys are ignored; `audit_reason` becomes the trail's reason. Screen: `App\Filament\Resources\Vendors\VendorResource`
(Organisation → Vendors; `ManageVendors`, "Add vendor" — Vendor → Tax registration → Contact → Address → Review — opens with
`?action=create`; the edit drawer keeps the PAN when left blank and shows it as ••••••234F); listed in Setup → Core HR.

**Business partners (§23.8 #1–6).** `App\Domain\Partners\Models\BusinessPartner` (table `business_partners`; `BelongsToTenant`,
`Auditable`, module `partners`): code, salutation (option list `salutations`), first / middle / last name, date of birth, gender
(`peopleos.people.genders`), nationality, partner type (option list `partner_types`: customer, background verification, vendor,
system consultant, other — `peopleos.partners.types`; HROne's "Recruiter" is not offered: recruitment is RMS), ISD code and
mobile (E.164 through `PhoneNumbers`, as employees' phones), personal and official e-mail, vendor (`vendor_id`), sign-in
(`user_id`, unique: one sign-in per partner) and status active / inactive. Written only by
`App\Domain\Partners\Services\BusinessPartners::create(array, User)` / `update(BusinessPartner, array, User)`: the same rights and
service-side validation; the code is typed (HROne's "Only a-z, A-Z, 0-9 and _", at most 32, unique per tenant without case) or,
left blank, the next number of the code type `business_partner` (`peopleos.numbering.types`; BP00001 by default; Setup → Number
series — a series for it may not use `-` or `/`: `NumberSeries::problemWith` honours the type's new `characters` rule, every
other type unchanged); a vendor must be of the organisation and active (a link made earlier survives the vendor's inactivation);
`user_id` is never taken from input; making a partner inactive disables their login in the same step.

**Sign-in (§23.8 #7–8, D-WO2).** `users.kind` (`App\Domain\Identity\Enums\UserKind`: `standard` — every account so far —,
`partner`; not fillable; `User::isPartner()`). Only `BusinessPartners::invite(BusinessPartner, User $by, array $roleIds = [])`
makes a partner sign-in: refused with the reason (`inviteRefusal()`) without `partner.manage`, for an inactive partner, without
`user.create` (new sign-in), without an e-mail (official, else personal), for an e-mail any user already has (unique on the
platform, as Users → New user), for a sign-in already active ("send a password reset link from Users") or disabled. It creates the
user (invited, kind partner, a password nobody knows), links it to the partner (audited), gives only the roles chosen — none by
default — through `RoleMembers::add` (`user.assign_roles` and the RoleGrants ceiling, checked before anything is created), then
sends the normal invitation: `TemporaryPasswords::email` (the owner's 2026-10-31 decision replaced invitation links with an
e-mailed temporary password; pending `UserInvitations` links are revoked by it), so the administrator never sees a password. A
partner still invited is sent a new one (the user's `update` policy). No employee record is ever made, and `Employee` refuses
to be linked to a partner sign-in (saving hook; the employee form's "Login user" leaves them out). Role membership rules run
over employees only, so they never reach partners. `disableLogin(BusinessPartner, User, ?reason)` suspends the sign-in, withdraws
an unused temporary password and pending invitation links, and ends every session (`SessionSecurity::revokeUser`, cause
`login_disabled`); `enableLogin()` makes it active again — or invited when the partner never chose a password (address never
proved), to be invited again; both need `partner.manage` and the user's `update` policy (`user.update`). The Users list shows a
"Kind" column and filter.

**Register (§23.8 #1–2, §23.9 #21).** `App\Filament\Resources\BusinessPartners\BusinessPartnerResource` (People → Business
partners, so the Lifecycle area; `ManageBusinessPartners`): name (salutation and names, #code), e-mail (official, else
personal), mobile, type, vendor, sign-in (not invited / invited / signs in / login disabled), status; as a table or as HROne's
cards (`?layout=cards`); filters type, status, sign-in, vendor; search by name, code or e-mail; WO-1 register tools (preset views
Active, Not invited yet, Signing in, Login disabled, Inactive; saved views; the "Business partners" report). ⋮ Edit, Invite to
sign in / Send a new invitation (with roles when the person may give them), Disable login, Enable login, Open sign-in (Users →
Edit). "Add business partner" (Name → Personal details → Type and vendor → Contact → Review) opens directly with
`?action=create`. The Lifecycle desk (`WorkforceDesk`) has a "Business partners" section — Business partners (active count), Add
business partner (`?action=create`, for those who may), Not invited yet, Vendors — and `partner.view` / `partner.manage` join its
operator permissions (still with `employee.view`). Setup → Workforce links the register.

**Report (§23.8 #10, matrix 21 Workforce #3).** Dataset `partners` (`PartnersDataset`, category Administration; `partner.view` or
`partner.manage`): code, name, type (and code), vendor and vendor code, e-mails, mobile, gender, nationality, date of birth
(sensitive: `partner.manage`), sign-in (and code), roles, last sign-in, status (and code), added on. Library template
`business_partners`. Not on `peopleos.analytics.unattended_datasets`.

**Data, permissions.** Migrations `2027_01_21_200001_create_vendors_and_business_partners` (unique `vendors_tenant_code_unique`,
`business_partners_tenant_code_unique`, `business_partners_user_unique`; foreign keys `business_partners_vendor_fk`,
`business_partners_user_fk`, null on delete; indexes `vendors_tenant_status_idx`, `business_partners_tenant_type_status_idx`) and
`2027_01_21_200002_add_kind_to_users` (`users.kind`, default `standard`). Platform invariant 3 ("Person is the one canonical
natural person": no other table with first name and date of birth) now names `business_partners` as its one documented
exception — partners are people outside the workforce, never employees, never matched or merged with a Person, with no
employment data; a partner later hired becomes a Person through the hire like anyone else (an owner point under D-WO2).
`DataInventory`: `business_partners` under identity
(personal; the label names them), `vendors` under organisation (like organisation contacts). New permission keys `partner.view`,
`partner.manage` (group `partner`; Tenant HR Admin template `partner.*`) → run `php artisan peopleos:sync-permissions` (existing
tenants' HR Admin role receives them on the next role seeding, as every new template key). Policy
`App\Domain\Partners\Policies\PartnerPolicy` for both models (no delete). Option lists `partner_types`, `vendor_kinds`. Code type
`business_partner`. No setting, job, command or notification event; audit actions reused (CREATE, UPDATE, TEMPORARY_PASSWORD_ISSUED,
ROLE_CHANGED, SESSIONS_REVOKED, INVITATION_REVOKED).

**Tests.** `tests/Feature/HroneParity/BusinessPartnersTest.php` (vendors: code / kind / GST / PAN validation in the service, PAN
encrypted and masked in the audit, partial update; partners: series and typed codes, the character rule and its series check,
type, mobile E.164, e-mail, date of birth, vendor of the organisation and active; invite: a partner sign-in with no employee
record, no role and no permission, the temporary-password mail, a new invitation, each refusal, never linkable to an employee,
the Users kind filter; roles only as chosen, `user.assign_roles` and the ceiling with nothing created on refusal; disable / enable
for invited and active partners, sessions ended, inactivation; gates for nobody, partner.view, partner.manage without user
rights, the HR admin template; tenant isolation of records, vendor links, actions and the platform-wide e-mail; the wizard from
`?action=create`, service errors on the fields, the ⋮ actions; table, cards, filters, search, preset view; the desk, Setup links,
the report and the inventory).

Not built here: the partner / vendor upload (§23.10.1 #4, WO-17 through `Vendors` / `BusinessPartners`); vendor contacts beyond one
(`vendor_contacts`), vendor custom fields (§1.33–1.34) and `assets.vendor_id`; a "partner-assignable" role flag (D-WO2's proposed
alternative) — any role the inviter may give can be given; partner self-service (a partner's own profile page); counting partner
sign-ins apart from other users in the plan (they are ordinary users).

## WO-14 Bulk document sharing

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` (package WO-14; §23.7.3 #1–9, §23.7.4 #1–3, §23.9 #20 "Share
letters / documents", HROne `publishletters_700`), rows marked "→ built (WO-14 …)". Owner decision D-WO5 on its default:
unpublishing hides the document from the employee and marks it unpublished (who, when, why) — the document, its file and every
row are kept; nothing is deleted. Every default reproduces the earlier behaviour: nothing is shared until someone holding
`document.share` (and `employee.view`) runs the wizard; ordinary uploads, letters, My HR and the Employee 360 Documents tab are
unchanged (an ordinary upload is still pending verification, supersedes earlier versions of its type and fires
`document.uploaded`); e-mail is off unless the person sharing ticks it.

**Sharing (domain).** `Documents\Services\DocumentShares`:
- `start($actor, $data, $files, $confirmed)` (document.share): an active document type employees may see (CA-4 "Employee can
  view"), a title (≤ 255), an optional message (≤ `peopleos.documents.share.message_max` = 1000), and the files — mode `sheet`
  (a .zip plus a .csv sheet with the columns "Employee code" and "File name"; the template is `DocumentShares::template()`;
  header spellings such as `employee_code` / `file name` accepted) or `common` (one file for everyone chosen with the WO-2 /
  WO-16 selection `EmployeeSelection`: filters that must narrow, or "everyone" ticked, or pasted codes). Checked before anything
  is queued: the zip opens, ≤ `zip_max_kb` (12 MB, the panel's temporary-upload limit), ≤ `zip_max_entries` = 2000 files and
  ≤ `zip_max_uncompressed_kb` together; the sheet has the two columns and ≤ `max_rows` = 2000 rows (blank rows skipped; a row
  without a code or a file name, an over-long value or a repeated row is recorded failed at once); the common file passes
  `FileSafety`; ≤ 2000 employees, the typed count above `bulk.confirm_over` (common file only — a sheet is a list HR made); pasted
  codes nobody in the person's access has are recorded failed ("No employee with this code in your access."); the personal e-mail
  needs `employee.sensitive.view`. The selection is read as the person sharing (`ScreenAccess::as`). One `document_share_batches`
  row (status queued), one `document_share_items` row per sheet row or chosen employee, the uploaded files on the documents disk
  under `tenants/{id}/document-shares/{batch}`, then `RunDocumentShare` after the commit.
- `run()` (the job: 3 tries, 1800 s, backoff 60 / 300, tenant id + `BindTenantContext`; claimed queued → running; a retry carries
  on with the items still waiting; `failed()` stops the share with the reason) works as the person who shared: refused whole when
  they are no longer active or lost `document.share`, or the type is no longer active / visible to employees. Each waiting item
  on its own: the employee in their access (the scoped Employee query and the employee's `view` policy — otherwise "No employee
  with this code in your access.", the same words for an unknown code, so nothing outside the scope is revealed); someone who
  never joined (`not_joined`) refused; in a zip the file is matched by its exact name or by its name without extension (any
  case; never another extension), several files of that name are refused ("give the file name with its extension"), folders,
  hidden files and `__MACOSX` are ignored, and nothing is ever extracted to a path taken from the zip (each entry is streamed
  into a temporary file, capped at `documents.max_kb`); `Documents::share()` stores it.
- `Documents::share($employee, $file, $type, $title)`: the same server-side checks as any upload (`FileSafety`: allow-listed
  extension, size, sniffed content), stored as an ordinary employee document of the chosen type and title — status `verified`
  ("Shared by the organisation"; nobody verifies what HR itself shared, so shares never fill the "Documents to verify" queue), its
  own version, issued today, uploaded by the sharer, and **never** archiving the employee's earlier documents of that type; no
  `document.uploaded` event. The employee sees it where they see their documents (My HR → Documents, My profile → Documents,
  "Download all") through `EmployeeDocumentPolicy::isOwn`, as any document of a type they may view. A common file is copied
  for each employee (one file per document, so unpublishing or deleting one never touches another).
- Telling the employee: in-app on by default (`Notifier`, channel in_app, event `document.shared`, source the item, the
  message or "HR shared a document with you (…)"; `NotificationCenter::linkFor` opens My HR → Documents for the employee, the
  share for HR). E-mail off by default: one notification delivery per employee (event `document.shared`, the tenant's mail
  transport, `DeliverNotification` after commit, resendable) to the work e-mail (or personal), whose source is the item — the
  item is a `MailEnvelope` (WO-13) that adds no recipient and no copy and attaches the document's own file when the mail goes; a
  document unpublished meanwhile is not sent (the delivery fails). No CC / BCC exists, so no document ever goes to a copy. An
  employee without that address is shared but not e-mailed (the row says so). The person sharing is told when the share ends
  (event `document.share_completed`, with the counts).
- The end: counts shared / failed / e-mailed on the batch, the zip's files no sheet row names (`unused_files`, ≤ 200), the
  uploaded files removed, audited DOCUMENTS_SHARED (counts, mode, type — never file content) inside one audit operation (each
  document's own creation audited as usual). `errorCsv()`: every failed row (row, employee code, file name, reason) and the unused
  files, formula cells quoted.
- Unpublish (§23.7.3 #7, §23.7.4 #3; D-WO5): `unpublishItem()` one document, `unpublish($items | $batch)` the documents ticked or
  a whole share (document.share; a reason ≤ 500; the employee within the person's access; only documents still shared — the
  others counted as skipped; one audit operation): the item becomes `unpublished` (when, by whom, why), the employee document is
  archived ("Unpublished: …"), audited DOCUMENT_UNPUBLISHED; `EmployeeDocumentPolicy::isOwn` refuses an archived document of an
  unpublished share (the employee cannot open it even by link) while HR (document.view, scope) still can. A share none of whose
  documents is still with an employee is marked unpublished. Nothing is deleted; there is no re-publish (share again).

**Screens.** Group Letters (the Lifecycle rail area, next to Letter schedules): **Shared documents**
(`DocumentShareBatchResource`, slug `document-shares`) — the register of shares (number, document type, title, files, published
on, published by, shared, failed, unpublished, status; filters document type, status, published by, unpublished, published-on
range); header "Share documents" (`DocumentShareActions::share`, a step wizard: Document → Files → Who → Notify → Review; the Who
step is the WO-16 "Who" fields for a common file, a note for a sheet; the review shows the count and the first ten employees),
"Share letters" (letters made in PeopleOS: the Letters register's "Ready to issue" view, where "Generate & publish" publishes them
— letter.issue), "Sheet template" (CSV) and "Published documents". The share's page: the details (type, files, outcome, who
was told, message, unused files, why it stopped, unpublished), "Download errors", "Unpublish" (the whole share) and its rows
(`ItemsRelationManager`: row, employee code, name, file, status, reason / unpublished by and why; "View", "Unpublish", bulk
"Unpublish"). **Published documents** (`PublishedDocumentResource`, slug `published-documents`; §23.7.4) — one row per employee
and shared document inside the viewer's scope (employee code, name, document type, title, file, published on, published by,
status, share), filters document type, published by, published-on range, status, share, the WO-2 codes and organisation filters
and employee search; "View" (the documents' own signed, authorised download), "Unpublish", bulk "Unpublish". Issued letters stay
in Letters (Revoke). Lifecycle desk (`WorkforceDesk`, section "Workforce letters"): "Share documents" (opens the wizard with
`?action=shareDocuments`), "Shared documents", "Published documents"; `document.share` added to the desk's operator permissions.

**Launcher link (Actions panel, §23.9 #20).** "Share documents": `DocumentShareBatchResource::getUrl('index', ['action' =>
'shareDocuments'])` (Filament's `?action=` default action on `ListDocumentShareBatches`). "Share letters":
`LetterResource::getUrl('index', ['view' => 'to_issue'])` (also the register's `shareLetters` header action). Gate: both share
screens' `canAccess()` = `DocumentShareActions::allowed()` = `document.share` and `employee.view`; the wizard action is visible on
the same rule and `DocumentShares::start()` checks `document.share` again.

**Report (§23.7.3 #9).** Dataset `document_shares` (document.share; one row per share row with an employee: share, employee fields,
document type, title, file, status, reason, published on / by, unpublished on / by / why) and the library template "Shared letters /
documents (this month)".

**Data, permissions, settings.** Migration `2027_01_20_200001_create_document_share_batches` (`document_share_batches`,
`document_share_items`; long names explicit). `DataInventory`: both tables under `documents`, the batch's `source_path` /
`sheet_path` as files and the prefix `tenants/{id}/document-shares`. New permission `document.share` (Documents; in the HR Manager
template; Tenant HR Admin holds `document.*`) → `php artisan peopleos:sync-permissions`. No setting; technical limits
`peopleos.documents.share.*`. Audit actions DOCUMENTS_SHARED, DOCUMENT_UNPUBLISHED. Policy `DocumentSharePolicy` (batches and
items; nothing edited or deleted). `DocumentShareItem` is access-scoped by its employee (`ScopedByEmployee`; rows without an
employee — codes nobody has — are seen with their share). `WorkforceBulkWizards::whoFields()` / `state()` made public for reuse.

**Tests.** `tests/Feature/HroneParity/DocumentSharingTest.php` (defaults and the permission's holders; zip + sheet mapping with or
without extension, unknown codes, missing / ambiguous / unsafe files, repeated and incomplete rows, unused files, the errors CSV,
the files removed after the run; the employee sees and opens their own document only; in-app notice with the message; a common
file to selected / pasted codes with the typed count and not-joined refusal; out-of-scope employees refused by sheet, codes and
unpublish; unpublish one or a share — hidden from the employee, kept with its file, HR still opens it, audited, not twice, a
waiting e-mail not sent; e-mail only when asked, only to the employee, attached, no copies; the wizard through `?action=`, the
share page, its rows, the published documents list, its filter and bulk unpublish; the permission gate on service and screens and
the desk; a type kept from employees cannot be shared).

Open points (applied defaults): uploads of a share are not routed through the P2 import approval (maker-checker) — sharing is an
act of the person holding `document.share`; the zip is limited by the panel's temporary upload (12 MB unless the deployment raises
Livewire's and PHP's limits); shared documents are stored as verified rather than pending; a share never replaces the employee's
earlier document of the same type; the sheet is CSV only (no .xlsx); no SMS / WhatsApp (D-WO7); HR Executive does not get
`document.share` by default (it holds no `bulk.run` either).

## AC-3 Product ideas

Matrix: `docs/hrone-parity/modules/23-workforce-operations.md` §23.9 #22 (HROne's "Product enhancement request" /
feature request, `featurerequestcreate_1010`, `app-feature-request`, `app-product-enhancement-wizard`, `app-product-map`),
which was N-A ("vendor product feedback"); the owner asked for it on 2026-10-31 (actions-panel programme). Additive: nothing
existing changes behaviour; nobody holds the new right except the account owner and HR admin templates (and `*`).

**Domain (`app/Domain/ProductFeedback`).** `ProductIdea` (table `product_ideas`, `BelongsToTenant` + `Auditable`): reference
`IDEA-000042` (from the id, so the organisation and Markedge quote the same unique number; stored once the row exists),
module, process (free text), kind (`ProductIdeaKind`: new feature = HROne's "We don't have this feature" / improvement),
title, challenge, current workaround, ideal solution, impact (people affected, priority low / medium / high), attachments
(paths on the documents disk), status (`ProductIdeaStatus`: submitted → under review → planned → in progress → released, or
declined), Markedge's response, status changed at, submitted by / at. Modules are PeopleOS's own module list — the navigation
groups of `ModuleCatalogue::SPACES`, grouped by space in `SPACE_ORDER`, plus "Something else / not sure" — so a new module
appears in the list as soon as it has a navigation group.

- `ProductIdeas::submit($actor, $data)`: the organisation's own people only (a platform operator is refused, also inside an
  organisation), with `product.feedback`; every field checked again (module from the list, kind, lengths, priority, whole
  people count); up to five files, each in the upload area `product-ideas/`, on the document allowlist, within the size limit
  and not active content (`FileSafety`), moved to `tenants/{id}/product-ideas/{ulid}/{name}`. Audited `SUBMITTED` on the
  organisation's chain (module `product_feedback`, field changes).
- `ProductIdeas::changeStatus($idea, $operator, $status, $response)`: `PlatformOperator::assert` (operators only; a tenant
  user is refused whatever their rights); "Submitted" is never set by Markedge; declining needs a response; nothing changing is
  refused. Runs inside the organisation's tenant under a row lock: `STATUS_CHANGE` on the organisation's chain with the field
  changes (status, response, status changed at; reason "Markedge: Submitted → Planned"), and `STATUS_CHANGE` on the platform
  chain (reference, organisation id and slug, from → to, whether the response changed — never the idea's text). The person who
  sent the idea is told in the app and by e-mail through `Notifier` (event `product.idea_status_changed`: "Product idea
  IDEA-000042: Planned", or "… Markedge replied" when only the response changed; skipped for a suspended or deleted user); the
  bell opens the idea (`NotificationCenter::linkFor`).
- `ProductIdeas::attachmentPath()`: an operator opens a file (audited `ATTACHMENT_DOWNLOADED` on both chains).
- `ProductIdeaDirectory`: the operators' cross-tenant read (operators only; `TenantContext::bypass`, listed in the architecture
  test): ideas with the organisation's name and the sender's name and work e-mail (Platform operations already shows an
  organisation's contacts' e-mails), status counts, the organisations that sent ideas. No other tenant record.
- `ProductIdeaPolicy` (`product.feedback` for view / create, nobody edits or deletes; registered in `ParityServiceProvider`).

**Organisation side.** `ProductIdeaResource` (group Platform, next to Data export; label "Product ideas"): the organisation's
ideas (reference, title, module, kind, status, Markedge's response, sent by / on, status since; filters status, module, kind),
the idea page (what was sent, status, response, files through `ParityFileController` type `product-idea` — signed, policy,
audited), and "Suggest an improvement" (`CreateAction` → `PeopleCreateAction` wizard from the form's sections: What it is
about → The challenge → The ideal solution → Impact and files → Review; the files' helper text says Markedge sees them and to
leave out employees' personal data). Lifecycle desk (`WorkforceDesk`) section "Ideas for PeopleOS": Suggest an improvement
(only when the viewer may send ideas) and Product ideas.

**Launcher.** `App\Filament\Resources\ProductIdeas\ProductIdeaResource` index with `?action=create`
(`ProductIdeaResource::suggestUrl()`), gated by `product.feedback`: `canAccess()` = not a platform operator (like Data export;
operators use the platform page) and the policy's `viewAny` (product.feedback); the action shows when `canCreate()` = a tenant
user (tenant_id set) holding product.feedback.

**Platform side.** `PlatformProductIdeasPage` (`/admin/platform-product-ideas`, group Platform, `isPlatformAdmin()` like every
Platform page; `mount()` aborts 403 otherwise): filters by organisation, module, status (with counts), kind and reference or
words of the title / process (`#[Url]`), the latest 100 that match; one idea as written with the organisation, the sender and
the files ("Open", streamed through `attachmentPath`), and "Change status" (status other than Submitted + Markedge's response,
pre-filled) → `ProductIdeas::changeStatus`.

**Data, permissions, wiring.** Migration `2027_01_25_200001_create_product_ideas_table`. `DataInventory`: `product_ideas` in
`service_desk` (exported and deleted with the organisation's data; files swept under the prefix `tenants/{id}/product-ideas`;
like the other JSON attachment lists, the files themselves are not copied into the export package). Permission
`product.feedback` (new group `product`) in the `account-owner` and `tenant-hr-admin` templates (→ `php artisan
peopleos:sync-permissions`, which also tops up every tenant's Account Owner and Tenant HR Admin system roles with it). Notification event
`product.idea_status_changed` listed. No setting, no new audit action (`SUBMITTED`, `STATUS_CHANGE`, `ATTACHMENT_DOWNLOADED`).

**Tests.** `tests/Feature/HroneParity/ProductIdeasTest.php`.

Not built: HROne's paid "Fast-track my feature" path; a roadmap of Markedge's own planned and released features shown to every
organisation (`app-product-map`) — organisations follow only their own ideas; a notice to Markedge when an idea arrives;
comments back and forth on an idea; the organisation withdrawing or editing an idea after sending it.

## TX-8a Carry forward leaves

Matrix: `docs/hrone-parity/modules/24-time-office-operations.md` (package TX-8, carry-forward part: §24.6.6 #1–5, §24.1 #22,
§24.8 #11), rows marked "→ built (TX-8a)". Every default reproduces the earlier behaviour: `peopleos:leave:accrue
--close-year` and the daily run's close of the previous year post the same entries with the same keys, notes, dates, audit
events and leave events in the same order; the desk shows no reminder (`leave.carry_forward_alert_days` = 0); no migration, no
new permission key, no new table. The bulk balance change, HR "Donate on behalf" and the encashment parts of TX-8 are other
builders'.

**One calculation (§24.6.6 #2).** `LeaveAccrual::yearEnd($employee, $period, $codes = null, $read = null)` is the year close's
calculation, made a generator: for each leave type of the employee's leave policy on the last day of the year (only `$codes`
when given) with a positive closing balance, the closing balance, the days carried forward (all of it, none, or up to the
limit — TO-7) and the days that lapse. `closeYear()` iterates it and posts each type before reading the next, exactly as it
did; its new optional `$codes` (null = every type, the command's call) limits the close to chosen types. The preview passes
`$read` — the entitlements (`LeaveEntitlements::fromVersion`, the policy resolved once), the closings and the leave types read
for many employees at once — so nothing is written: `LeaveBalances::closings()` sums the period's ledger in one query and
works the closing out with `LeaveBalances::figures()`, the formula `recompute()` now saves too, rounded as the stored balance
(`asStored`: two places, half up — the value `closeYear` reads back from the recomputed balance).

**Service.** `Leave\Services\LeaveYearEnd`:
- `types($period)`: the leave types an active leave policy lists on the last day of the year — what the screen offers and what
  "pending" counts (a comp-off or other balance no policy lists is never carried by the year close, so it is not pending).
- `preview($employees, $period, $codes)`: per employee the leave policy (name) and one line per type — `pending` (to carry
  forward: the `yearEnd` figures), `posted` (carried forward: what the `cf-{year}` / `lapse-{year}` entries hold) or
  `not_in_policy` (a balance in a type the employee's own policy does not list: the year close leaves it, it stays pending —
  adjust the balance if it should go).
- `post($employees, $period, $codes, $actor)` / `postFor()`: refused until the leave year has ended (leave taken later in the
  year would otherwise be carried forward as well — open point 1 below); each employee on their own — outside the access scope or no
  longer employed is refused — through `closeYear` (idempotent by its keys: a second post writes nothing, "Already carried
  forward"); one `AuditRecorder::operation('leave', 'Carry forward leaves {year}')` unless the caller runs one (a bulk run).
- `pending()` / `pendingCount()` ("Pending actions"): employed employees (as the close takes them) in the viewer's scope with a
  positive closing balance in such a type and no carry / lapse entry for that type and year; `withBalance()`, `posted()`,
  `onPolicy()` (the leave policy resolved per employee on the last day of the year, for the "Leave policy" filter).
- `alert()`: the reminder; `csv()`: the preview, one row per employee and leave type, audited as an export.

**Screen (§24.6.6 #1–5).** `Filament\Pages\LeaveCarryForward` (Leave → Carry forward leaves, slug `carry-forward-leaves`; a
`RegisterTablePage` with WO-1's `HasRegisterTools`, register key `leave_carry_forward`, presets `pending` / `posted`): leave
year (`?year=`, opens on the last ended year while it has pending employees, else the current one) and leave type (`?type=`,
or every type a policy carries); the "Pending actions" count linking the pending view; filters "Show" (still to carry forward /
carried forward), WO-2 pasted codes, organisation (incl. location), status / type / joining, and "Leave policy"; search by code,
name, e-mail, designation. Columns: employee code, employee name, policy name, leave type, no. of total leave, no. of leaves to
be lapsed, no. of leaves to be carried forward, status (one line per type). "Carry forward" on the selected rows (disabled with a
tooltip until the year has ended): a review with the typed count above `peopleos.bulk.confirm_over`; up to 25 employees post at
once with a result above the table ("Leave carried forward successfully."), larger selections start the bulk run
`leave_carry_forward.post` (`Experience\BulkOperations\CarryForwardLeaveOperation`, `bulk.run` too; each employee through
`LeaveYearEnd::postFor`). "Download" gives the filtered preview as CSV. Opens for `leave.view` with `leave.carry_forward` (TX-2;
`leave.manage` still grants it through `permission_splits`); `mount()` refuses others with 403.

**Desk, reminder, placement (§24.1 #22).** Time office desk → Leave → "Carry forward leaves" with the pending count of the year
the screen opens on. `OperationsDeskPage::notices()` (new, empty by default) lets a desk show reminders above its sections;
`TimeOfficeDesk::notices()` shows, to people who can open the screen, "The {year} leave year ends on … (N days left)" from
`leave.carry_forward_alert_days` days before the year ends while someone in their scope is pending, and "… ended on …: leave
still to carry forward" after it until nobody is pending — with a link to the pending view. Setting
`leave.carry_forward_alert_days` (Setup → Leave, number 0–90, default 0 = no reminder). The page sits in the Time & leave area
through its navigation group (as Roster board, Paid days, Change attendance status); it is not added to
`ExperienceNavigation::MODULE_AREA`, which would make it a rail area of its own or pin Time & leave above Lifecycle (see
"Desks inside their areas"). Setup → Leave links it.

**Open owner points.** (1) Posting is refused until the leave year has ended (HROne's behaviour before the end was not
captured); the daily leave run still closes the previous year automatically on its first run after it ends, so the screen
mostly previews and catches up — whether HR should be the only one to close a year (switch the automatic close off) is a
decision. (2) A balance in a type the employee's own leave policy does not list stays pending.

**Tests.** `tests/Feature/HroneParity/LeaveCarryForwardScreenTest.php` (the preview equals what is posted, per type and mode,
and writes nothing; posting only the chosen rows / types; a second post is a no-op with no new audit event; the command and the
screen post identical entries and `closeYear` with and without types; leave type, leave policy and "Show" filters, preset view,
comp-off not pending, not in the policy; refused before the year ends while the preview works; a bulk run above the threshold
under one audit operation and refused without `bulk.run`; CSV and its export audit; the permission gate; the access scope; the
reminder window and who sees it; desk entry, Time & leave area, Setup links; no payroll words).

## PO-22 Process bonus

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (package PO-22; §25.9.3 #1–#4, #7; HROne Payroll → "Process
bonus", `bonusprocess_198`, Bonus dashboard), rows marked "→ built (PO-22 …)". §25.9.3 #5 (HR-typed bonus percentage,
minimum attendance, "lesser / more of bonus wages and minimum wages", ceiling rows) and #6 (minimum-wage bonus slabs) stay
N-A: statutory figures come only from the rule. Defaults reproduce today's behaviour: no bonus run exists until someone
prepares one, `payroll.bonus.percent` is blank, and the compliance bonus register computes exactly what it did (its
calculation moved, unchanged, into a shared class).

**Rule gate (owner point D-PO5).** `Domain\Payroll\Services\BonusRuns::ruleState(fy)` asks, for every month of the accounting
year, `VerifiedThresholds::rule('BONUS', null, month end)` — the version `ComplianceRules::resolve()` returns (active only),
VERIFIED, checksum-intact and free of an open regulatory notice. A month without one names why (proposed and awaiting
verification · active but draft / review · checksum changed · open notice · no version). Unless every month has one, the
year shows **"Awaiting verification of the BONUS rule"** and `prepare`, the line changes, `submit` and `approve` all refuse —
whatever `peopleos.payroll.enforce_verified_rules` says (a proposed version is never used for money). Today both shipped
BONUS versions are `proposed` (Payment of Bonus Act v1 to 20 Nov 2025, Code on Wages v2 from 21 Nov 2025), so every year
waits; the rule data was not changed. Unprocessing (taking an approved bonus back out of its payroll month) stays open.

**Basis (shared with the register).** `Domain\Payroll\Support\BonusBasis` is `BonusRegister`'s former per-employee
calculation, moved verbatim: days paid, wages earned (basic + DA classified lines), wages for bonus with each month measured
under the version in force at its end (a month above the calculation ceiling counts at the ceiling, prorated as the wages
were), the determination and its reason (`determine()` now also says what a pending determination waits for: the
eligibility ceiling crossed during the year, or the minimum-wage limb of the calculation ceiling), bonus paid
(bonus-classified earnings), minimum and maximum bonus. `BonusRegister` calls it; its output is unchanged. The run reads the
company's finalized or paid entries month by month through the new contract method `PaidPayReader::finalEntries(companyId,
'Y-m')` (access-scoped, never an open run); bonus already paid for the year counts the year and the months after it up to the
payment deadline or this month.

**Run and lines.** `bonus_runs` (company + accounting year unique; the rate used and its source, the rule versions month by
month with checksums, the payment deadline = year end + `payment_within_months`, preparer, recalculated at) and
`bonus_run_lines` (one per employee: eligibility `eligible` / `review` / `ineligible` / `insufficient_data` with the reason,
pay group = the code of the salary structure in force at the year end, read through `CompensationOutput::inForceOn` (Payroll never touches compensation models; the screen shows the structure's name), months from / to, bonus days,
wages, wages for bonus, pay rate (the ceiling or HR's override), the rate, statutory bonus, already paid with salary, bonus to
pay, ex gratia, included, status, pay month, who edited / sent / approved, paid run and amounts, the month-by-month basis).
- Eligible = the rule's ELIGIBLE, or pending only on the minimum-wage limb (eligible on wages and days; the bonus uses the
  calculation ceiling and says "Change the pay rate when the minimum wage is higher"); included by default. `review` = wages
  crossed the eligibility ceiling during the year: left out until HR includes it with a reason. Ineligible (wages above the
  ceiling all year, fewer than the rule's minimum days) and insufficient data cannot be included; ex gratia stays possible.
- Statutory bonus = wages for bonus × the employer's rate, at least the rule's `minimum_amount`; bonus to pay = statutory
  bonus − bonus already paid for the year (included lines only).
- The employer's rate: setting `payroll.bonus.percent` (Setup centre → Payroll, number, no default; blank = the rule's
  `minimum_rate`). Outside `[minimum_rate, maximum_rate]` of every month's version the run refuses to prepare; a percentage
  typed to two decimals that rounds to the rule's own rate stands for it (8.33 = 8⅓%, stored 0.083333 and read back as the
  rule's exact rate).
- Pay-rate override (HROne "Pay rate", Edit): only where the rule's `calculation_ceiling_rule` is `max_with_minimum_wage` (the
  ceiling is the applicable minimum wage when higher, which PeopleOS does not hold), never below the ceiling, with a reason
  (audited); a fixed ceiling refuses ("pay anything above the statutory bonus as ex gratia"). Null clears it.
- Ex gratia: an amount HR enters with a reason, for any employee in the run, paid as its own earning classified `ex_gratia`
  (new classification label) and never counted as statutory bonus paid.
- Recalculation (prepare again) updates only lines not yet sent, not carried by a payroll being prepared; HR's override, ex
  gratia and include / leave-out decisions are kept.

**Workflow.** pending ("Not processed") → `submit` with the payroll month (payroll.calculate; open months only — a finalized
month of the company is refused; lines with nothing to pay, one's own and lines a payroll being prepared still carries are
skipped with reasons; a month after the rule's deadline is allowed and flagged) → submitted ("Waiting for approval") →
`approve` by a payroll.approve holder who neither sent nor changed the line and is not its employee (else skipped; nothing
approved = refused) or `sendBack` with a note → processed → paid. Each move runs in an `AuditRecorder::operation` with the
reason on every line's audit row. `unprocess` (payroll.calculate, reason): processed and unpaid lines back to pending;
refused while an approved payroll carries the line; allowed while a draft / calculated / validated payroll carries it (the
notice says to recalculate that payroll; if it is finalized first, it paid the bonus and the line is settled as paid).

**Payroll input.** `Domain\Payroll\Inputs\BonusInput` (key `bonus`, tagged `peopleos.payroll_inputs` in
`ParityServiceProvider`): processed lines of the employee whose pay month is the period's month or earlier, not carried by
another open run (`PendingPayrollInputs`) → "Statutory bonus FY …" with the BONUS pay component's code and tax / PF / ESI /
gross treatment (classification `bonus`) and "Ex gratia FY …" (`EXGRATIA`, classification `ex_gratia`). On
`payroll.finalized` it settles the carried lines: status paid, the run, the time and the amounts the payroll lines actually
paid — so a later payroll never pays them again.

**Screen.** `Filament\Pages\BonusProcessing` (Payroll → Process bonus, slug `process-bonus`; `?company=`, `?year=2025-26`,
`?tab=processed`): company and accounting-year choice (default: the year before the current one); the rule panel (versions
used, your percentage, the rule's range and minimum amount, eligibility and calculation ceilings, minimum days, pay-by date,
the run's counts and totals) or the "Awaiting verification of the BONUS rule" panel with each reason; HROne's tabs
**Non processed bonus** (code, name, pay group, months, bonus days, eligibility with reason, wages, pay rate, wages for bonus,
statutory bonus, paid with salary, bonus to pay, ex gratia, status; Detail · Pay rate · Ex gratia · Include / Leave out;
bulk Process selected · Approve selected · Send back selected) and **Processed bonus** (from / to month, pay rate, bonus days,
bonus amount, ex gratia amount, pay month, status with the payroll that paid it, approved by; Detail · Unprocess; bulk
Unprocess selected); filters eligibility / status, pay group (PO-1 `PayrollRegisters::structure`), pasted codes and the
organisation group (WO-2). Header actions: **Prepare bonus** / Recalculate (`prepareBonus`), **Process bonus**
(`processBonus`, every included line with something to pay), **Approve bonus** (`approveBonus`), **Send back**
(`sendBackBonus`). Detail (HROne's bonus detail) = `detailRows()`: percentage, tax treatment, months, bonus pay days, wages,
pay rate and override reason, minimum wages ("not held"), wages for bonus, minimum / maximum, statutory bonus, already paid,
bonus to pay, ex gratia, total, pay month, status, total paid. Payroll desk → Payroll processing → "Process bonus" (count:
lines waiting for approval). The page sits in the Pay area by its navigation group (desks left `MODULE_AREA` on 2026-10-31,
so no own rail area). Opens for `payroll.view` plus `payroll.calculate` or `payroll.approve` (mount aborts 403 otherwise);
preparing, changing, processing and unprocessing need `payroll.calculate`, approving and sending back `payroll.approve` (the
service checks again).

**Data, permissions, settings, wiring.** Migration `2027_01_28_200001_create_bonus_runs` (`bonus_runs`, `bonus_run_lines`,
classified under payroll in `DataInventory`). No new permission key. New setting `payroll.bonus.percent` (null). New
classification label `ex_gratia`. Contract addition `PaidPayReader::finalEntries()`. Shared files touched: `BonusRegister`
(uses `BonusBasis`), `PaidPay`, `PayrollDesk`, `ParityServiceProvider`, `DataInventory`, `config/peopleos.php`,
`config/peopleos_setup.php`.

**Tests.** `tests/Feature/HroneParity/BonusProcessingTest.php` (refused while only a proposed version applies — the whole year,
and FY 2025-26 month by month across both proposed versions — and with an active version under review although enforcement
is off; the page's awaiting panel and refused Prepare; the rule data untouched; with a verified fixture: eligible / ceiling
crossed / above the ceiling / fewer than the minimum days, wages for bonus at and below the calculation ceiling, the rule
minimum rate, 8.33 = 8⅓%, 20% maximum, 25% and 5% refused, the rule's minimum amount from a fixture version, include with a
reason kept across recalculation; the shared basis; pay-rate override only for the minimum-wage limb, never below the
ceiling, audited with its reason, kept on recalculation and cleared, refused under a fixed ceiling; ex gratia with a reason,
one's own bonus never changed; finalized pay month refused; nothing paid before approval; the sender and the employee never
approve, approval needs payroll.approve; BonusInput lines with the component code, classification and source ids, ex gratia
not counted as bonus paid, unprocess refused under an approved payroll, settled on finalization with the paid amounts, never
paid twice, paid lines kept on recalculation; send back with a note; unprocess before a payroll carries it and while a
calculated payroll carries it (then settled as paid when that payroll is finalized); the page: Prepare from `?action=`, the
tables, Pay rate / Ex gratia / Process / Approve / Unprocess actions, detail, the desk entry and access by right).

Not built here: payment through a separate bonus payroll run (PO-19 supplementary runs; today the bonus is paid with the
chosen month's regular payroll), a second payment for the same employee and year (a paid line is final; an advance paid
before the year ends is counted as "paid with salary" only when it was paid as a bonus-classified earning outside the run), disqualification (s.29), the allocable surplus and set-on / set-off, a minimum-wage
comparator for the calculation ceiling (needs verified `MIN_WAGE` versions keyed to employees, BP-15), a bonus payslip or
letter, a notification on approval, and bulk upload of pay-rate overrides.

## GO-15 Events

Matrix: `docs/hrone-parity/modules/27-performance-engage-operations.md` (package GO-15; §27.8.3 #1–13, 16–21; §27.1 #2
(the Events entry); §27.11 #20, 23, 24; §27.13 #2; §27.16 #5, 13; §27.9 "Add event"), rows marked "→ built (GO-15 …)".
Additive: nothing existing changes until someone adds an event. One migration, one new permission, no setting, no job, no
command.

**Data.** Migration `2027_01_29_200001_create_company_events`: `company_events` (title, `event_type` / `event_sub_type`
from the option lists, description, agenda (Markdown), host employee, `is_mandatory`, `allow_nomination`, `seats` (null =
unlimited), `mode` online / offline / hybrid, location or `venue`, `meeting_url`, `timezone`, `recurrence` (the rule),
`starts_at` / `ends_at` (first session's start, last session's end — the register's timeline filter), `attachments`
(documents disk, `tenants/{id}/events`), `video_url`, `audience_id` or `audience_criteria`, the pre- / post-event feedback
templates, status draft → published → completed or cancelled (`cancel_reason`, who, when), `invited_count`, created /
published / scope user); `company_event_sessions` (`event_id`, `sequence`, `starts_at` in UTC, `duration_minutes`);
`company_event_invitations` (`event_id`, `employee_id`, status invited / nominated / accepted / declined, `source` audience /
hr / nomination, `responded_at`, `nominated_by` = the nominating employee; one per event and employee);
`company_event_attendances` (`session_id`, `employee_id`, `attended`, `marked_by`, `marked_at`; one per session and
employee). Models `App\Domain\Engagement\Models\CompanyEvent`, `CompanyEventSession`, `CompanyEventInvitation`,
`CompanyEventAttendance` (tenant-owned, audited; invitations and attendance `ScopedByEmployee`). A published event keeps its
schedule, audience and mandatory flag (model guard `CompanyEvent::FROZEN`); events are never deleted. All four tables are
classified under `engagement` in `DataInventory`, with the events folder among its prefixes.

**Service `App\Domain\Engagement\Services\CompanyEvents`.**
- `create()` / `update()` (engagement.events): checks the details (option-list values, a meeting URL for online / hybrid, a
  location or venue for offline / hybrid, http(s) links, an employed host, at most 5 attachments in the events folder, active
  feedback templates of the right process); a mandatory event takes no seats and no nominations. `plan()` turns the schedule
  into sessions: start date and time in the event's time zone, duration HH:MM (up to 168 h), repeat none / daily (every n
  days) / weekly (every n weeks on chosen weekdays) up to an end date, at most `engagement.event_max_sessions` (60); local
  times stay the same across daylight-saving changes. A draft changes in full (sessions regenerated); a published event
  changes its details only, never its seats below the acceptances made.
- `publish(CompanyEvent, User)`: draft → published under the event row lock; the saved audience or criteria (plus pasted
  employee codes, which become "Named employees") are resolved in SQL inside the preparer's access scope (`AudienceQuery`,
  as announcements) and written as invitations in bulk (one audited operation, `AUDIENCE_USED`); refused when every session is
  over or the audience reaches nobody. Invitees are told in-app (`EngagementEvent` `event.published` → the notification
  bridge: "You are invited: … (date)" / "Please acknowledge: …").
- `invite(event, employees, User)`: HR adds people to a published event ("Add invitees": chosen people or pasted codes, in
  scope, employed, not invited yet; source `hr`), who are told.
- `respond(CompanyEventInvitation, 'accepted'|'declined', User)`: the invitee only, while the event is published and not
  over. A mandatory event is acknowledged (= accepted), never declined. Seats limit acceptances of an optional event, counted
  under the event row lock (no waiting list). Accepting raises the pre-event feedback request when the event names a template.
- `nominate(CompanyEvent, Employee, User)`: an invitee nominates a working colleague not invited yet, when the event allows
  nominations and seats are left; the nominee gets an invitation `nominated` (`nominated_by` = the nominator) and is told
  (`event.nominated`); they answer in turn.
- `markAttendance(CompanyEventSession $session, Employee $employee, bool $attended, User $by)`: whoever runs events (the
  employee in their scope) or the event's host; published or completed event, session started, the employee invited; one row
  per session and employee, re-marking changes it (audited). Being marked attended raises the post-event feedback request
  (once per invitation). GO-11's "Event attendance" upload calls this method.
- `attendanceCsv(event, User, ?session)`: one row per invitee in the reader's scope (the host's: every invitee) — code, name,
  department, answer, how invited, nominated by, answered on and per session Attended / Absent / blank; formula-safe cells;
  audited as an export.
- `cancel(CompanyEvent, string $reason, User)`: draft or published → cancelled with a reason (≤ 500); everyone invited who
  has not declined is told (`event.cancelled`: "Cancelled: … — reason"); pending pre- / post-event feedback requests are
  cancelled; invitations, answers and attendance are kept. `complete()`: published → completed once the last session has
  started (attendance can still be marked).
- Reads: `stats()` (invited, not answered, nominated, accepted, declined, seats left), `upcomingFor()` / `pendingFor()` /
  `invitationsQuery()` (the person's own invitations), `nextSession()`, `attachmentUrl()` (signed `parity.file` link, type
  `company-event`, opened by `CompanyEventPolicy::view`).

**Screens.**
- `Filament\Resources\CompanyEvents\CompanyEventResource` (Engagement → Events, `/admin/events`, engagement.events): the
  register — event (type · sub-type), date and time in the event's zone (sessions), mode, host, seats, "Nomination" (accepted
  of invited), status, created by; filters Status, Event timeline (upcoming / ongoing / past), Type of event, Type of sub
  event; row actions View, Edit, Publish, Download attendance, Unpublish (cancel, with HROne's back-dated note).
  **"Add event"** = `CreateCompanyEvent` (`/admin/events/create`, header button on the register): the step wizard Basics ·
  Schedule · Place & material · Audience & feedback · Review, with "Publish when created" (on by default; a refused
  publication leaves a draft and says why). Edit opens any step; once published, the schedule and audience steps are
  read-only. The event page (`ViewCompanyEvent`): event, RSVPs (invited, not answered, accepted / acknowledged, declined,
  seats left), sessions, place, links and attachments, audience, feedback, description and agenda; header actions Edit,
  Publish, Add invitees, Download attendance ("Select date": every session or one), Mark completed, Unpublish; relation
  managers Invitations (employee search, Nomination / How invited filters, pasted codes, "Download excel") and Sessions and
  attendance ("Mark attendance": tick who attended a started session — everyone else on the sheet is marked absent — and a
  per-session download).
- `Filament\Pages\MyEvents` (Me → My events, `/admin/my-events`, anyone with an employee record; own invitations only):
  upcoming / past events with date and time, place, host and the person's answer; Details (slide-over: type, sub-type, mode,
  location, host, seats, time zone, sessions, link, video, description, agenda, attachments), Attend / Acknowledge, Decline
  (optional events), Nominate a colleague (when allowed). Navigation badge = invitations waiting.
- Home: the side card "Upcoming events" (`HomeComposer::SIDE`, block key `events` — widget access can switch it off): the next
  three invitations not declined, seats left, Attend / Decline or Acknowledge in place (`Home::eventRespond`).
- My tasks / unified inbox: `Domain\Engagement\Support\EventInvitationTaskSource` (kind `engagement.event_invitation`, label
  "Event invitation"): each invitation waiting for an answer while the event has not ended — "Respond to the invitation: …" or
  "Acknowledge: …", due when the next session starts, opening My events. `SelfServiceLock::TASK_KINDS['engagement']` reads
  "Surveys and event invitations".
- Social wall: post type `event` (`WallPost::TYPES`) — while the wall is on, a published event is posted once (`event:{id}`),
  seen only by its invitees (and moderators); hidden ("The event was cancelled") when cancelled (`SocialWall::onEngagement`).
- Engage desk: section "Events" (between Announcement and Survey) with the register and the count of upcoming published
  events; `engagement.events` makes someone an engage operator. Navigation: `ExperienceNavigation::EVERYDAY['Engagement']`
  lists the register; `MyEvents` sits in the Communication section as its own area "My events" (`MODULE_SECTION`,
  `MODULE_AREA`, like Announcements). The register is not a rail area of its own (desks and registers stay inside their
  area since 2026-10-31).
- Analytics: dataset `event_participants` (category People, engagement.events, the reader's scope): event, type, sub-type,
  date, status, mode, host, mandatory, the employee fields, answer, how invited, nominated by, answered on, sessions, sessions
  attended, attended; library templates "Event details" and "Event attendance (this financial year)".

**Configuration.** Permission `engagement.events` (`config/peopleos.php`; HR Manager template; Tenant HR Admin through
`engagement.*`) — run `php artisan peopleos:sync-permissions`. Option lists `event_types` and `event_sub_types`
(`OptionLists::LISTS`, built-ins in `peopleos.engagement.event_types` / `event_sub_types`; organisations add, rename or hide
them on Option lists). `peopleos.engagement.event_modes`, `event_max_sessions`. Feedback module `engage` and processes
`event_pre` / `event_post` (read by engagement.events) — create templates of those processes on Feedback templates.
Notification titles `event.published`, `event.nominated`, `event.cancelled` (in-app, built-in; not tenant notification-rule
events, not webhooks). Policies `CompanyEventPolicy` (view: runs events, host, or invited once published) and
`CompanyEventRecordPolicy` (sessions, invitations, attendance: read by engagement.events, never written directly).

**Tests.** `tests/Feature/HroneParity/CompanyEventsTest.php`: create and publish to a department only, telling the invitees;
refusals (nobody in the audience, over, places, links, types); sessions from weekly / daily rules across a daylight-saving
change and the session limit; accept / decline, mandatory acknowledged, only the invitee answers, answers stop when over;
seats; nominations; attendance with the exact signature, re-marking, the host, the CSV per event and per session; cancel
tells everyone not declined and keeps the data; complete; permission gates and policies; tenant isolation; My tasks and pre- /
post-event feedback; the wall post (invitees only, hidden on cancel); Home card and My events (accept, decline, nominate, own
rows only); the HR register (wizard steps, create and publish, filters, publish, view, invitations, add invitees, mark
attendance, download, cancel); option lists, desk, navigation, data inventory and the dataset.

Not built here: attendance badges (§27.8.3 #14 — owner decision D-GO4, default off), skill endorsements for attendance (#15,
after MS-6), AI drafting of the agenda (#7, D-GO6), a waiting list when the seats are taken, the host's own event page
(the host marks attendance through the service / GO-11 upload, not the HR register, which needs engagement.events), the
team reports for managers (§27.13 #6, RQ-11), events imports (GO-11 calls `markAttendance`), monthly "On day" / "On the …"
recurrence (daily and weekly only).

## GO-18 Fundraiser campaigns

Matrix: `docs/hrone-parity/modules/27-performance-engage-operations.md` §27.8.9 #1–6 and the action index row "Initiate fundraise"
(`fundraisercampaign_767`), marked "→ built (GO-18 …)". Nothing changes until HR starts a fundraiser: employees see no new screen
or card while none is open to them, and payroll results are unchanged (the payroll input adds nothing while the owner decision
D-GO3 setting is off — the default — or no payroll pledge exists).

**Data.** Migration `2027_01_30_200001_create_fundraisers`. `fundraiser_campaigns` (`FundraiserCampaign`, Engagement, BelongsToTenant
+ Auditable): name, purpose, beneficiary, starts_on / ends_on, target_amount + currency (base currency by default), document_path /
document_name (private documents disk, `tenants/{id}/fundraisers`), audience_id or audience_criteria (pinned, normalised, when it
opens) + scope_user_id (the opener, whose scope bounds the audience), payroll_deduction + deduction_month (optional fixed "Y-m"),
status draft | open | closed | cancelled with who / when and the cancel reason. Only drafts change (model guard); never deleted.
`fundraiser_pledges` (`FundraiserPledge`, BelongsToTenant + Auditable + ScopedByEmployee): campaign, employee, amount, currency,
mode `paid_outside` | `one_time_payroll`, payroll_month, consent_given_at + consent_text (the words agreed to; required for a
payroll pledge by the model), status pledged | deducted | paid | cancelled, note (the donor's message, kept out of the audit
trail), payroll_run_id + deducted_at, paid_on + payment_reference + recorded_by, cancellation. The amount, mode, month and consent
never change (model guard); only a pledged row changes status; never deleted. `DataInventory`: both tables under `engagement`,
plus the prefix `tenants/{id}/fundraisers`.

**Service `Engagement\Services\Fundraisers`** (scoped per request; forgotten on `RequestHandled`). `create` / `update` (drafts,
engagement.manage; criteria within the actor's scope; payroll collection refused while the setting is off or the currency is not
the base currency; the document must be on the documents disk under the tenant's folder), `open` (draft → open on the locked
row: end date not passed, audience normalised, within scope and reaching somebody today, pinned with the opener as scope user,
`AUDIENCE_USED` audited with purpose `fundraiser`), `close`, `cancel` (with a reason; pledges still to be collected are cancelled
in one audit operation), `pledge` (engagement.participate, an employee working here, in the audience — the pinned criteria
resolved by `AudienceQuery` inside the opener's scope —, campaign open and today inside its window on the tenant clock; amount
positive, two decimals; a payroll pledge needs the setting on, the campaign collecting through payroll, the month (the
campaign's fixed one or the donor's choice) among the donor's open payroll months and the consent box ticked), `cancelPledge`
(the donor or HR in scope, while pledged), `recordPayment` (HR in scope; not while a payroll run being prepared deducts it; no
future date), `totals` / `totalsFor` (collected = deducted + paid, pledged, donors, pledges, percent — over every pledge),
`visibleIds` / `openFor` / `canSee`, `pledgesFor` (HR: their access scope; employees: their own), `pledgesCsv` (formula
injection neutralised, `EXPORT` audited), `documentUrl` (30-minute signed `parity.file` link).

**Payroll (owner decision D-GO3, off).** Setting `engagement.fundraisers.payroll_deduction` (Setup → Engage → Fundraisers,
default false). The engagement domain reads payroll only through its contract `Engagement\Contracts\PledgePayroll` (open months
per donor, the tenant's upcoming months, pledges a run being prepared carries), bound in `ParityServiceProvider` to
`Payroll\Inputs\FundraiserPayrollMonths` (it reuses `ExpensePayrollMonths` and `PendingPayrollInputs`). The tagged input
`Payroll\Inputs\FundraiserDeductionInput` (key `fundraiser_pledge`, tag `peopleos.payroll_inputs`): while the setting is on,
each consented pledge of the employee for the period's month still pledged becomes its own deduction line `FUNDRAISER`
("Fundraiser: <name>", outside gross, no tax effect) at exactly the amount pledged; one another unfinalized run carries is left
to it. `payroll.finalized` settles: the carried pledges (locked) become deducted with the run and time; if one was cancelled
or paid after the run was calculated, settlement throws and the finalization rolls back ("recalculate the run before
finalizing") — so a withdrawn consent is never deducted (the same rule as compensation changed since calculation). Cancelling
before the month is calculated simply removes it. HROne's pay-component mapping (`payCode`) is not copied: the line is the
input's own. The run cockpit names the source "Fundraiser pledges".

**Screens.** `FundraiserCampaignResource` (group Engagement; `ExperienceNavigation::MODULE_AREA` → its own area
"Fundraisers" in the Communication section, beside the announcements feed). `canAccess()`: engagement.manage, or
engagement.participate with a fundraiser open to the person (or one they pledged to); the list and the record follow the same
rule (`Fundraisers::listQuery`, `FundraiserPolicy`). List: fundraiser (purpose), status, dates, target, collected (pledged still
to collect), payroll; row actions Donate now, View, Change / Open / Close / Cancel. Header action **Start a fundraiser**
(`FundraiserActions::start()`, name `startFundraiser`, opens directly with `?action=startFundraiser`): steps Cause (campaign name,
purpose, beneficiary, upload document) · Target and dates · Who can donate (saved audience or `AudienceCriteriaSchema`) · Payroll
(only while the setting is on: "Do you want to collect through payroll?", salary deduction month or each donor chooses) · Review
(summary, "Open it now"). View page: Collected against target (collected, target, pledged, progress and donors), the fundraiser,
the pledges (`PledgesRelationManager`: HR in scope or the donor's own; Record payment, Cancel pledge with the payroll warning),
header Donate now, Document, Change, Open, Close, Download pledges (CSV), Cancel fundraiser. Employees donate with
`FundraiserActions::donate()` (amount, "How will you pay?" only when payroll is possible, month, the consent sentence as the
checkbox label, message). Home: side card `fundraisers` ("Fundraisers (Donate now)" in Widget access; the open fundraisers of the
viewer's audience, soonest to end first, at most three: Collected, Target amount, the fundraiser's page, Donate now via
`Home::donateAction()`). Engage desk: section "Fundraiser" (Fundraisers; Start a fundraiser for engagement.manage). My space:
item `fundraisers` (group Engage; listed only while the screen opens for the person). Setup → Engage links Fundraisers. `ParityFileController` type `fundraiser` (the campaign's document, for whoever may view the campaign).

**Permissions.** No new key: managing = `engagement.manage`, pledging = `engagement.participate` (Employee, Manager, HR templates
already hold it). Nothing to sync.

**Tests.** `tests/Feature/HroneParity/FundraisersTest.php` (audience, pinned scope, window, close / cancel visibility, drafts
only change; outside payroll pledges, refusals while the setting is off, payments recorded by HR, the message kept out of the
audit; with the setting on: consent, month, a cancelled pledge removed, deduction exactly once in its month, a pledge withdrawn
after calculation blocking finalization until recalculated, settlement, off again = nothing; totals, CSV with formula
neutralising and export audit, campaign cancellation; documents and tenant isolation; screens — the wizard by URL, Home card and
Donate now, 403s, permission gates).

**Open.** No social wall post for a fundraiser (HROne's wall card is the Home card here; a wall post would need the wall's feed to
honour fundraiser audiences); no notification to the audience when a fundraiser opens; no pay-component mapping; no 80G /
donation receipts (statutory, not built); a payroll pledge whose month was finalized without it (the donor was not paid that
month) stays pledged until HR records an outside payment or someone cancels it.

## PO-13 Statutory challan register

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (package PO-13; §25.6.12 #1, #4 (display), #8, #10–#13, #15–#17),
rows marked "→ built (PO-13 …)". HROne's "Generate challan" (`challan_124`). Defaults reproduce the earlier behaviour: the
register starts empty; a quarterly TDS statement generated with challans typed in uses exactly those (as before), and one
generated with none typed takes the quarter's challans from the register — with none recorded there it is built as before
(`deposit_details_missing` still blocks). Nothing here calculates a contribution, reads a statutory rate or edits payroll: it is
a payment register over the existing returns.

**Data.** Migration `2027_01_26_200001_create_statutory_challans`:
- `statutory_challans` (model `Compliance\Models\StatutoryChallan`, `BelongsToTenant` + `Auditable` + `ScopedByOrganisation`,
  dimension `statutory_return` = company and establishment scope like the returns): statute (`pf`, `esi`, `pt`, `lwf`, `tds`),
  company, legal entity, establishment (PF / ESI / PT / LWF; null for income tax), the registration in force for the month
  (EPF code, ESIC code, PT certificate, LWF registration, TAN), the linked return (nullable: the period's current return when
  recorded — regular before supplementary, never a cancelled or revised one), state (PT / LWF), financial year, period month
  (for income tax the month the salaries were paid, as the TDS ledger counts it), alias, challan no. / TRRN / CRN / challan
  serial, acknowledgement no. / CRN / CIN, BSR code and nature of payment (income tax; the nature defaults to
  `peopleos.compliance.formats.TDS_FORM_138.section_code`), paid on, payment mode (`peopleos.compliance.challan_payment_modes`),
  bank, branch, amount, interest, penalty, receipt (documents disk, `tenants/{id}/statutory-challans/`), notes, status
  `recorded` / `verified` / `cancelled`, recorded / verified / cancelled by and at, cancel reason. `active_key`
  (statute | legal entity | normalised number, null once cancelled; unique per tenant) stops the same challan being recorded
  twice. The payment facts are never edited (model guard: only status, receipt, verification and cancellation fields change;
  a cancelled challan stays as it is) and never deleted.
- `statutory_challan_allocations` (model `StatutoryChallanAllocation`, `ScopedByEmployee`, immutable, audited on the
  challan): a TDS challan's employee rows — employee, ledger row, month, TDS deducted, amount deposited here.
- Both classified under `statutory` in `DataInventory`; the receipt under `FILES` and the tenant prefix
  `tenants/{id}/statutory-challans` (documents disk).

**Service (`Compliance\Services\StatutoryChallans`).**
- `record()` — `compliance.returns.file`; refused for a month not begun, a payment date in the future or before the month, no
  number, an unknown mode, a negative interest / penalty, a zero amount (not income tax), a BSR code that is not seven digits,
  a number already recorded, a unit outside the person's access (checked on the saved row inside the transaction) and — the
  separation of duties — **by the person who approved the return it pays**. Audited `CREATE` (module compliance, reason names
  the statute and the employees); a TDS challan also `ASSIGN` with the employee ids and the amount.
- Income tax (D-PO7): `tdsAllocation($entity, $month, $viewer)` brings the TDS ledger up to date with finalized payroll
  (`TdsLedgers::sync`, as the statement does) and lists each employee the viewer may reach with TDS that month: code, name,
  PAN masked, gross, deducted, already deposited by other challans that are not cancelled, left to deposit. A TDS challan's
  amount is the sum of the chosen employees' "left to deposit" (all of them when none are chosen) — never typed, never more than
  was deducted, never from a payroll that is not finalized; the deductor-year row (`tds_financial_years`) is locked while it
  allocates. Interest and penalty are recorded on the challan; finalized TDS is not edited (a difference is corrected by a TDS
  override in an open month). `tdsMonths()` gives the monthly view (§25.6.12 #17): employees with TDS, deducted, deposited,
  to deposit, employees with challans.
- `verify()` — `compliance.returns.file`, someone other than the recorder, recorded challans only (`REVIEWED`). `cancel()` —
  recorded challans only, with a reason, never once a quarterly statement that carries it is approved or later (`CANCELLED`;
  frees the number and the TDS allocation). `attachReceipt()` — recorded challans only; the file passes `FileSafety` and moves
  from the upload area into the tenant folder (the earlier file is removed).
- `forTdsStatement($entity, $fy, $quarter)` — the quarter's challans that are not cancelled, in the statement's shape
  (`bsr_code`, `deposit_date`, `challan_serial`, `amount`) plus `interest`, `penalty`, `statutory_challan_id`, `month`.
  `TdsQuarterlyReturns::generate()` uses it when no challans are typed and marks the attestation `challan_source = register`;
  `tax_deposited` still sums the tax amounts only.
- `grid($statute, $fy)` — rows = the statute's units in the viewer's scope (active establishments; legal entities for income
  tax), cells = the twelve months: **not due yet** (a month not begun, nothing generated), **not generated**, **generated**
  (draft → validated), **approved** (approved / exported), **paid** (a challan recorded), **filed** (submission recorded on the
  return); filed without a payment shows "payment not recorded". The income-tax cell reads the quarter's statement.
  `coverage($fy)` (§25.6.12 #4, display only): active locations with no establishment and the finalized salaries of the year
  with the `statutory_state_missing` exception.

**Screen (`Filament\Pages\StatutoryChallansPage`, slug `statutory-challans`, title "Challans", group Compliance → the Pay
area).** Tabs Provident fund / Employee state insurance / Professional tax / Labour welfare fund / Income tax and a financial
year select (`?statute=`, `?year=`); the coverage alert; the grid (income tax: deducted / deposited under each cell); a cell
opens its panel (`?cell=`): the return (open it on its own page; "Generate return" / "Regenerate return" through the return
registers' own services — `EpfReturns`, `EsiReturns`, `ProfessionalTaxReturns`, `LwfReturns`, `TdsQuarterlyReturns` — for
`compliance.returns.generate` while nothing or an editable return exists; "Challan file" = the return's exported file for
`compliance.returns.export`; "Working sheet" = the statute's challan working from Compliance reports for
`compliance.reports.generate`), the income-tax summary and the challans recorded (view with every field, the receipt through a
signed link, attach / replace receipt, verify, cancel). **Record payment** is a step wizard — Challan for (statute, unit, month,
the return it pays) → Employees (income tax only: the allocation, all chosen by default, with the totals) → Payment (alias,
number, acknowledgement, BSR and nature for income tax, date, mode, bank, branch, amount except income tax, interest, penalty,
receipt, notes) → Review; a refusal keeps the wizard open. Header: Record payment, Compliance reports, Compliance control room.
The page also has "Challans" on the Compliance control room, and the TDS statement form says that an empty challan list takes
the register's.

**Navigation and launcher.** Payroll desk → Payroll processing → "Generate challan" now opens this page (it opened Compliance
reports); "Compliance reports" is its own entry below it. The "Payroll actions" menu's "Generate challan" opens it too (Compliance
reports for people who may only open those). The page sits in the Pay area through its navigation group (`ModuleCatalogue`
maps Compliance to Pay); it is not in `ExperienceNavigation::MODULE_AREA`, which would make Pay a pinned area of its own.
Launcher: `StatutoryChallansPage` `?action=recordPayment` (optional `actionArguments[statute|unit|month]`), visible with
`compliance.returns.file`; `canAccess()` = signed-in tenant user (not a platform admin) with `compliance.returns.view`.

**Permissions and wiring.** No new key: `compliance.returns.view` (page, register), `compliance.returns.file` (record, verify,
cancel, receipt; label updated), `compliance.returns.generate`, `compliance.returns.export`, `compliance.reports.generate`. Run
`php artisan peopleos:sync-permissions` for the updated label. Policy: `StatutoryReturnPolicy` for both models
(`ParityServiceProvider`). `ParityFileController` type `statutory-challan`. Architecture test: the allocation model is listed
with the tables audited on their parent.

**Tests.** `tests/Feature/HroneParity/StatutoryChallanRegisterTest.php` — grid states through generate, approve, pay and file
(and the page's cells and panel); the wizard with a receipt (moved, signed, audited, forbidden without the right), never edited
or deleted or recorded twice, verified by someone else only, cancelled with a reason; refusals (future month, dates, amount,
mode); the approver cannot record (service and wizard) while someone else can; TDS allocation from finalized payroll only
(masked PAN, BSR format, one employee then the rest, nothing left, cancelling frees it, October calculated but not finalized
gives nothing, payroll TDS lines and the ledger unchanged, the income-tax tab); the quarterly statement with an empty register
(as before), with typed challans (as before) and from the register (cancelled ones left out, interest kept apart, validated,
a carried challan not cancelled once approved); coverage (unmapped location, salaries without a PT state; nothing written);
permissions, launcher, establishment scope, tenant isolation and the payroll desk entry.

Not built here (open points): challan groups across establishments (§25.6.12 #20, setup SV-4); bank challan report formats;
a reminder when a month's challan is due; refusing approval of a return to the person who recorded its payment (the reverse
check); income tax per employee split into tax / surcharge / cess (the ledger holds the total deducted).

## PO-8 Leave encashment by HR (Encash leaves, encash for an employee)

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (package PO-8; §25.6.14 #1–#6, §25.9.1 #8, the panel row
"Leave encashment (Encash leaves)"), `24-time-office-operations.md` (§24.6.4 #6, #8, #9; §24.8 #6) and the on-behalf index row
of `20-requests-inbox-team.md`, rows marked "→ built (PO-8 …)". Every default reproduces the earlier behaviour: an encashment
without a pay month (every existing row, every employee request, every approval that sets none) is paid by
`LeaveEncashmentInput` exactly as before — same query condition, same name, amount, basis and settlement; the employee's own
encashment form, its checks and its Approval Center item are unchanged; the HR register keeps every column, filter and action
(the Approve form only gains an optional pay month, two hidden-by-default columns were added). New by default: the batch
screen and its wizard, a second person's approval of every batch (`payroll.encashment.batch_approval`, on), "Encash for an
employee" on the encashments register, the request type's on-behalf link, and an "Encash leaves" entry on the Payroll desk and
in the payroll actions menu.

**Data.** Migration `2027_01_24_200001_create_leave_encashment_batches`:
- `leave_encashment_batches` (tenant, leave type, leave year `period_year`, `pay_month` 'YYYY-MM' or null, `mode` policy |
  specified, `specified_days`, `applicability` json (who was chosen), `plan` json (the reviewed lines `{employee_id, days,
  note}` and the skipped `{employee_id, reason}`), `results` json (per employee once processed: encashed with days and
  encashment id, or skipped with the reason), `employee_count`, `encashed_count`, `encashed_days`, `status` (pending →
  processing → processed; rejected / withdrawn; unprocessed), `note`, `created_by`, `approved_by` / `approved_at` /
  `decision_note`, `processed_at`, `unprocessed_by` / `_at` / `unprocess_reason`; indexes `leb_tenant_status_idx`,
  `leb_tenant_pay_month_idx`). Classified under `leave` in `DataInventory` (exported and deleted with the tenant's leave data).
- `leave_encashments.leave_encashment_batch_id` (foreign key `le_batch_fk`, null on delete; null = a request) with the unique
  key `le_batch_employee_unique` (one encashment per employee and batch), and `leave_encashments.pay_month` ('YYYY-MM', null).
- Pay month instead of the matrix's `pay_period_id`: payroll periods are per company and are created by payroll, while a batch
  spans companies and may name a month no period exists for yet; variable pay and salary holds already keep 'YYYY-MM'
  (`PayrollRegisters::payMonth`), so the same idiom is used. `Leave\Support\PayMonth` parses ('YYYY-MM' or blank; anything
  else refused), labels ("Nov 2026" / "The next pay run") and offers the month before this one to a year ahead.

**Pay month on payroll (matrix 24 §24.6.4 #8, TX-8 row).** `LeaveEncashmentInput::inputsFor`: an approved encashment without
a pay month is due, as before, in the first run whose period ends on or after its approval; one with a pay month is due in the
run of that month, or the first run after it when that month was missed (`pay_month <= period key`, as variable pay). The
amount (`days × per-day pay` on `payroll.encashment_basis` / `payroll.encashment_divisor`), the `encashment_no_rate`
exception, the taxable `LEAVE_ENC` line and the settlement on finalize are unchanged; a line paid later than its month reads
"… encashment (1 days) for 2026-08" and its basis carries `pay_month` (only when set). The pay month is set by a batch, by HR
on "Encash for an employee", or by the approver on the register's Approve form (`LeaveAdjustments::approveEncashment($e,
$note, $payMonth = null)`; blank keeps what was asked); `requestEncashment()` gained a trailing `?string $payMonth = null`.

**Batches (`Leave\Services\LeaveEncashmentBatches`, model `LeaveEncashmentBatch`).**
- Who (`employees()`): picked employees (search by name, code, designation, e-mail), the WO-2 organisation filters
  (`EmployeeFilters::ORGANISATION`: company, business unit, division, department with sub-departments, designation, grade,
  level, location — or "Everyone in my access" on purpose) through `EmployeeSelection`, or pasted codes ("Add bulk
  employees"). Always the scoped Employee query: nobody outside the person's access scope is ever chosen. At most 5,000.
- Days (`preview()` / `most()`): "As per policy" = the most the leave type's encashment rule allows now — TO-7 through
  `LeaveAdjustments::encashmentAllowance` (balance available less the balance to keep, the days a year left, the most in one
  request) less the days of encashment requests still waiting (kept back from the balance; the year limit already counts
  them), in half days; "Specify" = that number for everyone, capped at the same most ("Capped at 3 days: the most the rule
  allows now"). Skipped with the reason: the person making the batch ("Your own encashment: ask for it in My encashments."),
  anyone not employed, a type the policy does not grant or encash, nothing to encash ("No Earned leave balance to encash.",
  "The rule keeps a balance of 2 days and only 1 day is available.", "… already asked for in encashment requests waiting
  for a decision.", "Already encashed or asked for the 10 days a year the rule allows."). A type that is not active and
  encashable, a mode other than the two, or days not in half days are refused.
- `submit()` (leave.manage): the reviewed lines and skipped are kept on the batch (`plan`); audited `SUBMITTED` (leave type,
  mode, days, pay month, employees, skipped, approval). With `payroll.encashment.batch_approval` on (default) the batch is
  `pending`; off, it is `processing` at once and processed in the name of who made it. A batch nobody can encash is refused.
- Approval (`approvalRefusal()`, `approve()`, `reject()`, `withdraw()`): a second person with leave.manage who did not make the
  batch, is not one of its employees and can reach every employee in it (`AccessScopes::employeeKeys` of the leave module)
  approves it — on the batch page, from the register, or in the Approval Center. Rejecting needs a reason (the maker
  withdraws instead); the maker or anyone with leave.manage withdraws a pending batch. Each audited (`APPROVED`, `REJECTED`,
  `CANCELLED`), under the batch row's lock.
- Processing (`process()`, queued `Leave\Jobs\ProcessLeaveEncashmentBatch`: tries 3, backoff 60 / 300 s, timeout 1,800 s,
  unique per batch, tenant id + `BindTenantContext`; dispatched after commit): in the approver's name (or the maker's), inside
  one audit operation, each reviewed line in its own transaction under the employee's balance lock: the days are never more
  than reviewed and never more than the rule allows at that moment; an approved `leave_encashments` row (requested by the
  maker, reviewed by the approver, note "Encashment batch #N", the batch's pay month, the batch's comments as reason), the
  `encashment` ledger entry, an `APPROVED` audit event and `leave.encashment_approved` (the employee is told as for any
  approved encashment). Safe to run again: an employee the batch already encashed is not encashed twice. Then `processed`,
  `processed_at`, `results`, counts and days.
- Unprocess (`unprocess($batch, $actor, ?$employeeIds, $reason)`, HROne "All employees / Select employee"): on a processed
  batch, in the person's access scope, inside one audit operation: each approved encashment is cancelled through
  `LeaveAdjustments::cancelEncashment` ("Encashment batch #N unprocessed: <reason>"; the days go back through the reversing
  ledger entry) unless it is paid ("Already paid: it stays.") or a payroll run not yet finalized carries it ("Being paid by the
  payroll of Sep 2026: it stays.") — read through the new Leave contract `Leave\Contracts\EncashmentsBeingPaid`, bound to
  `Payroll\Inputs\PendingEncashmentPayments` (over `PendingPayrollInputs`, key `leave_encashment`), as Expense's
  PayrollCommitments. Encashments outside the person's scope are counted and left. The batch records the last unprocess and
  becomes `unprocessed` once none of its encashments is approved or paid.
- Leave stays free of payroll words: the payroll-facing parts (the input, the open-run reader) are in the Payroll domain.

**Approval Center.** `Experience\Sources\LeaveEncashmentBatchApprovalSource` (tagged `peopleos.approval_sources`, kind
`leave_encashment_batch` "Leave encashment batch" in Setup → Inbox actions): pending batches for leave.manage holders the
service would let approve ("Encash Earned leave for 12 employee(s) · As per policy", impact, leave year, skipped count, link to
the batch); approve / reject go to the service.

**Screens.**
- `Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource` (Payroll → "Encash leaves", slug
  `leave-encashment-batches`; badge = batches waiting for approval, for leave.manage): HROne's batch cards as a register (batch,
  leave type, leave encash, pay month, no of employees, days encashed, leave encash by, leave encashment date, status, decided
  by) with HROne's filters Encashment date, Encashed by and Pay month (plus status and leave type), preset views (waiting,
  processed, unprocessed), Approve / Reject on rows. Header "Encash leaves" (`Filament\Support\LeaveEncashmentWizards::batch`,
  `?action=encashLeaves`): Leave type (type, pay month, As per policy / Specify value, comments) → Applicability (search
  employees / organisation and location / paste codes) → Review (leave type, leave encash, pay month, leave year,
  applicability, selected employees and days, approval; each employee's days with the cap note; who is skipped and why; above
  `bulk.confirm_over` the count is typed) → "Process".
- View page: the batch, applicability, skipped (at review and at processing, only employees in the viewer's scope), the
  Encashments relation (the batch's encashments in scope: employee, days, pay month, status, approved by, unprocess reason) and
  Approve / Reject / Withdraw / "Unprocess" ("Do you want to unprocess?" — All employees / Select employee, reason).
- Leave → Encashments register: header "Encash for an employee" (`LeaveEncashmentWizards::forEmployee`, `?action=
  encashForEmployee`) — Employee (picker over the scoped employees, never one's own; the requester header) → Encashment (leave
  type, the employee's balance and rule, days, pay month, comments, supporting file — required when the rule says so) → Review
  (who approves it, `RequestChrome::route`); it calls `requestEncashment()` with HR as the signed-in requester, so the rule,
  balance, limits, file rules (RQ-4), the `leave.encashment_requested` workflow or the Approval Center apply as for the
  employee's own; refused for one's own record, out of scope or without leave.manage. Header "Encash leaves" opens the batch
  wizard. Columns "Raised by" (with "Batch #N") and "Pay month" (hidden by default); the Approve form gained "Pay month".
- `LeaveEncashmentRequestType::onBehalfPermission()` = `leave.manage`, `onBehalfUrl()` = the register with
  `?action=encashForEmployee` (for people who manage leave and can open it).
- Payroll desk → Payroll processing: "Encash leaves" (count = batches waiting for approval). Payroll actions menu:
  "Encash leaves" (`posPayEncashLeaves`). No `ExperienceNavigation::MODULE_AREA` entry: the resource's Payroll group puts it in
  the Pay area, whose landing is the Payroll desk (an area entry would add a rail area of its own).

**Permissions, settings, wiring.** No new permission key (no `peopleos:sync-permissions` needed): batches and the on-behalf
request need `leave.manage` ("encash, apply on behalf"); the screen opens with `leave.view`; approving takes leave.manage plus
the separation and scope checks above. Policy `Leave\Policies\LeaveEncashmentBatchPolicy` (view = leave.view, create / update
= leave.manage, never delete). Setting `payroll.encashment.batch_approval` (true; Setup → Payroll). New job above; no new audit
action or notification event. Shared files touched: `LeaveEncashment` (fillable, `requester()`, `batch()`), `LeaveAdjustments`
(pay month on request and approval), `LeaveEncashmentInput`, `LeaveEncashmentResource`, `ListLeaveEncashments`,
`LeaveEncashmentRequestType`, `PayrollDesk`, `PayrollRegisters::actionsMenu`, `ParityServiceProvider` (source tag, contract
binding, policy), `DataInventory`, `config/peopleos.php`, `config/peopleos_setup.php`.

**Tests.** `tests/Feature/HroneParity/LeaveEncashmentBatchTest.php` (as per policy vs specified capped by the rule, pending
requests kept back, refusals; one's own and leavers skipped, a line capped again at processing, the batch's pay month on the
encashments; applicability by search / filters / codes only in the access scope, and approval only by someone reaching every
employee; second-person approval by default — maker, a member of the batch and a person without the right refused, the
Approval Center item and decision, rejected / withdrawn batches never encashed, the setting off processing at once; the
queued job per batch and tenant, idempotent; pay month honoured (a later month waits, a missed month paid with its name), null
paid exactly as before with the same formula and basis; unprocess leaving paid and open-run encashments and cancelling the
rest for one employee and then all, audited; HR's on-behalf request through the wizard — balance and kept-balance refusals, the
supporting-file rule, one's own / out of scope / without the right refused, the Approval Center decision, the on-behalf link;
the batch screen — wizard review with the cap and skip reasons, filters, approval and unprocess on the view page, access,
Payroll desk entry and actions menu; inventory and setting).

Not built here: a cancellation request with approval for batch encashments (unprocess is a direct leave.manage action, as
HROne's); an encashment amount or liability column (matrix 21 §21.2.2 Payroll #30–#31); keeping the person who raised an
encashment on someone's behalf from approving it (open point); refusing a single "Cancel" on the register while an open payroll
run carries the encashment (open point — the batch unprocess already does).

## PO-17 Increment calculator

Matrix: `docs/hrone-parity/modules/25-payroll-operations.md` (package PO-17; §25.9.2 #1, #2, #5–#10; §25.14 #5; the Actions
row §25.9 #10), rows marked "→ built (PO-17 …)". HROne's increment calculator (`incrementcalculator_655`,
`app-increment-calculator` → `app-increment-step-one…four`, `app-increment-view-flyer`, `app-clone-flyer`) is PeopleOS's
compensation cycle, extended. Every default reproduces the earlier behaviour: a cycle created as before (the "New cycle"
drawer, salary uploads, the API) has basis `ctc_percent`, no proration, no population filter, and its rating-label matrix is
read exactly as before (same formula `round(CTC × (1 + % / 100), 2)`, same line reason, same snapshot checksum, same
validation messages — a test pins all four). Nothing here approves or executes: lines are still `CompensationChange`s
through `CompensationChanges` (submit → review → approve → execute by four different people, budgets refuse overspend at
approval, never for oneself). One behaviour changed on purpose (the error file): when a compensation rule refuses one line
while populating (e.g. a structure without an approved version on the date), that employee is skipped and listed with the
rule's message and the other lines are proposed; before, the whole populate stopped on the first refusal.

**Rules on the cycle (`Compensation\Services\IncrementCalculator`, migration `2027_01_27_200001_add_increment_calculator_to_
compensation_cycles`).** All are cycle rules: locked from submission with the others (`CompensationCycle::RULES`).
- Population (`population` JSON; §25.9.2 #2): organisation filters on the position held on the effective date — business
  unit, division, department (with its sub-departments, `EmployeeSelection::withSubDepartments`, now public), designation,
  grade, level, location, employment type — and HROne's "Add bulk employees" as pasted codes to keep and codes to leave out
  (`EmployeeSelection::parseCodes`, at most `bulk.pasted_codes_max`), inside the cycle's company (and unit), the lifecycle
  states that take part in cycles and the preparer's access scope. A pasted code that gives no eligible employee is listed
  in the error file without saying why (an employee outside the preparer's access is not disclosed).
- Basis (`increment_basis`; §25.9.2 #6): `ctc_percent` (default, the earlier rule), `fixed_percent` (the percentage of fixed
  pay = annual CTC less the annual variable-pay target, never below zero) or `amount` (a flat annual amount;
  `default_increase_amount` when no grid is used). The line keeps its percentage of the CTC in `increase_percent` (rounded
  to 2 places; a line above 1000 % is skipped).
- CTC band × rating grid (§25.9.2 #7), stored in `rating_increase_percent` as `{"shape": "ctc_band_rating", "bands": [{ctc_from,
  ctc_to (null = no upper limit, last band only), value (any other rating, optional), ratings: [{from, to, value}]}]}`; the
  earlier `{label: percent}` shape is still read. HROne's checks with their wording: "To should be greater than or equal to
  From", "From annual salary should be greater than the last filled annual salary in To", "From rating should be greater
  than the last filled rating in To"; values within −100 … 1000 % (or an amount below 10^11); without a performance cycle
  every band needs a value. A line: the band holding the CTC in force (none → skipped "outside every band"); with a
  performance cycle, the finalized rating (`PerformanceOutcomesReader::final_rating`) picks the rating row, else the band's
  value, else skipped (no finalized rating / rating outside the rows).
- Tenure proration (`prorate_on_tenure`, default off; `proration_basis` joining | role, `proration_cutoff` (default the
  effective date), `proration_full_months` (default 12, 1–120); §25.9.2 #5): the increase × min(1, full months ÷ full
  months), counted from joining or from the start of the current designation (the earliest unbroken position row with it,
  never before joining) up to the cut-off; no full month → skipped. The reason says "prorated 6/12 months".
- `population_errors` (JSON): the employees the last populate skipped, with the reason. `compensation_changes.base_ctc_annual`:
  the annual CTC a cycle line was calculated from (summary, managers' view, team pool). `cloned_from_id` (self foreign key
  `comp_cycles_cloned_from_fk`, null on delete).

`CompensationCycles` changes: populate reads the compensation in force through `CompensationOutput::inForceOn()` (no longer
the assignment table), asks `plan()` (new, public, read-only: the planned lines and the skipped employees, also used by the
wizard's Review through `draftOf()`) and proposes each line in its own savepoint; `create()` refuses a duplicate code with a
message instead of a database error; `clone($source, $data, $actor)` (new); `submit()` lapses managers' open proposals.

**Summary and error file (§25.9.2 #1, #8).** `IncrementCalculator::summary($cycle)`: headcount, current cost (the CTC before
the change once submitted, else the CTC calculated from), proposed cost, total increment and its percentage, average
increment (mean of the lines' %), skipped, and with a budget its amount, the share this plan uses and what is left after it
(`CompensationBudgets::measures` variance, less the plan's increase while it is not yet approved) — "over budget" says
approval would be refused. Amounts are in the main currency (the budget's, else the most common), other currencies listed
apart; only lines the viewer may reach. `errorCsv($cycle, $actor)`: Employee code, Employee, Reason, formula-injection-safe
cells, rows outside the downloader's access left out, `compensation.cycles` only, audited `EXPORT` (module `compensation`,
`export = increment_errors`, `rows`).

**Sharing with managers and their proposals (`IncrementPlanSharing`; §25.9.2 #9, §25.14 #5).** Tables
`compensation_cycle_shares` (cycle, manager, shared by / at, revoked by / at; unique cycle + user; never deleted) and
`compensation_cycle_proposals` (cycle, line (null once the plan is recalculated), employee, manager, proposed CTC, base CTC,
%, note, status proposed / accepted / declined / lapsed, decided by / at, decision note; amounts masked in audit; employee
access scope). Both `Auditable`, classified (`DataInventory` compensation; data classification financial / confidential).
- `share($cycle, $userIds, $actor)` (`compensation.cycles`, the plan in scope, not rejected / cancelled): per person refused
  unless an active user with `compensation.team` who manages someone in the plan through a configured manager relationship
  (`CompensationAccess::teamOf`), never the preparer; told in the app (`compensation.cycle.shared`). `revoke()` lapses their
  open proposals. `managersFor($cycle)` lists who can be chosen.
- `teamLines($cycle, $manager)`: only the lines of the people the manager manages, inside the manager's access scope, never
  their own line; nothing when the plan is not shared with them.
- `propose($line, $ctc, $note, $manager)` (draft plans only, under the cycle row lock): within the grid — the increase between
  the lowest (never above "no increase") and the highest value the plan gives the employee's CTC band (or the matrix and
  default), as amounts after proration (`IncrementCalculator::range`) — and within the team's budget: the manager's
  proposals for their team add up to no more than the increase the plan gives the team, so the plan's total and its budget
  use never grow from proposals. The preparer is told (`compensation.cycle.proposal`). Nothing changes until HR decides.
- HR finalises: `accept()` — the preparer only (the proposer of the draft lines), never on their own compensation — adjusts
  the line through `CompensationChanges::update` (reason "… · manager's proposal accepted", `increase_percent` updated; other
  open proposals on the line are declined); `decline()` with a note (any `compensation.cycles` holder in scope, not the
  manager). Recalculating the plan or submitting it lapses open proposals.

**Screens.**
- `Filament\Pages\IncrementCalculator` (Compensation → Increment calculator, slug `increment-calculator`, `compensation.cycles`;
  `canAccess()` = the signed-in user holds `compensation.cycles`, `mount()` aborts otherwise): the increment plans (cycles
  except salary uploads) with HROne's card figures — increments, average increment, total increment, total budget, shared
  with — status and type filters; record actions Open, Clone, Share with managers, Download error file. Header action
  **Create new** (`create`, opens directly with `?action=create`): a four-step wizard — **Population** (plan code and name,
  type, company, effective date, organisation filters, codes to keep / leave out) → **Basis & proration** (basis, budget,
  performance cycle or ad hoc, value when no grid is used, proration) → **Grid** (bands with their rating rows, "+ Add
  another") → **Review** (the calculation run without writing: the summary tiles and the first ten employees who would be
  skipped, or why the plan cannot be made; "Calculate the plan now", on by default). Creating calculates the plan and opens
  the cycle page. **Clone** is the same wizard filled from the plan ("Copy of …"; a rating-label matrix is carried unless bands
  are added). Support class `Filament\Support\IncrementCalculatorWizard`.
- The cycle page (`CompensationCycleResource`): an "Increment calculator" section (basis, proration, population, the grid,
  the summary, shared with, cloned from); header actions Share with managers, Clone, Download error file; the lines show the
  CTC calculated from before submission and the manager's proposal, with **Accept proposal** (preparer) / **Decline
  proposal**. The cycles list links "Increment calculator" (`?action=create`).
- `Filament\Pages\TeamIncrements` (Me → Team increments, slug `team-increments`; `compensation.team` and at least one plan
  shared; `ExperienceNavigation::MODULE_SECTION` people / `MODULE_AREA` "My team"; `?plan=<cycle id>` opens on a plan): the
  manager's team lines of the shared plans — plan, employee, rating, current CTC, planned % and CTC, their proposal and its
  status — and **Propose** (annual CTC, note) while the plan is a draft, with the allowed range in the modal.
- Payroll desk "Salary structure" section: "Increment calculator" and "Create an increment plan" (`?action=create`);
  "Payroll actions" menu: "Increment calculator".

**Data, permissions, wiring.** Migration above (the calculator's rules, the error list and the clone source on
`compensation_cycles`, `base_ctc_annual` on `compensation_changes`, two new tables; long indexes and foreign keys named). No new permission key (`compensation.cycles`,
`compensation.team`, `compensation.propose` as they are; the manager template still lacks `compensation.team`, as before). No
setting, no queued job. Notification titles for `compensation.cycle.shared` / `compensation.cycle.proposal`
(`NotificationEventBridge`); not webhook events. Shared files touched: `CompensationCycles`, `CompensationCycle`,
`CompensationChange`, `EmployeeSelection` (one helper public), `ExperienceNavigation`, `NotificationEventBridge`,
`DataInventory`, `config/peopleos.php` (classification), `PayrollDesk`, `PayrollRegisters`, `CompensationCycleResource`,
`LinesRelationManager`.

**Tests.** `tests/Feature/HroneParity/IncrementCalculatorTest.php` (the grid by band and rating with its skips and HROne's
validations; the earlier label matrix: amounts, reasons, checksum and message unchanged; proration by joining and by
designation with its cut-off and skips; fixed-pay and amount bases by default and by band; the summary and budget used in
the Review and on the plan and the list; population by designation and codes with the error file (unpaid, own, refused by a
rule, codes not found, left out) audited and downloaded; sharing, the manager's team-only view, proposals within the grid and
the team budget, HR accept / decline, lapse on submit, recalculation and revoke; clone with its rules and none of its lines,
from the service and the wizard; the wizard from `?action=create`, the Review, the desk entries and navigation; no
calculator without `compensation.cycles`).

Not built here: HROne's clone flyer download / re-upload of a revised CTC sheet (the salary upload already does it); a
500-employee cap ("Note: Increment planning can be done for 500 employees"); manager proposals for shared plans in the
Approval Center; a manager's own budget share configured by HR (the pool is the plan's calculation for the team).
