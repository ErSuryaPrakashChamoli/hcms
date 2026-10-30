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
   `['view' => '<preset>']` and an optional cheap `count` closure) and `registers()`; give it a navigation group and, if it
   should be its own rail area, an `ExperienceNavigation::MODULE_AREA` entry.
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
