# HROne vs PeopleOS — 25 Payroll operations (HR Desk → Payroll), field by field

Prepared 2026-10-29, analysis only (no code, no tests, no HROne access). Recruitment is out of scope (separate RMS):
such rows are N-A. Package prefix: **PO-**. No personal data is copied: employee names, codes, amounts and counts
seen on screenshots or in API bodies are left out (counters are described, not quoted).

**Inputs.**

- `hrone-research/menu-tree.json` → panel **"Payroll"**: top items Salary structures, Salary structure requests,
  Reports, Payroll Setup; groups Salary structure (no items of its own: the two top items), Tax & benefit (7), Variable
  pay (2), Loan (2), Payroll processing (4), Full and final settlement (2), Payroll letters (2), and the URL each opened.
- `hrone-research/pages.jsonl` (latest line per id): `rail_Payroll` (+ `__tab_01_Reports`), the 23 `menu_Payroll__*`
  pages and their `__tab_01_Reports` captures (Salary_structures, Salary_structure_requests, Reports,
  Reimbursement_requests, Reimbursement_cancellations, Proposed_investments, Proposed_investments_requests,
  Confirmed_investments, Confirmed_investments_requests, Flexible_benefit_Requests, Variable_pay_requests,
  Variable_pay_cancellations, Loans, Loan_requests, Run_payroll_salary_dashboard, Generate_challan, Salary_YTD,
  Leave_encashment, Settlement, Settlement_requests, Appointment_letters, Salary_revision_letters), the route captures
  `route_app_payroll*`, `route_app_processes_payroll*` (salary dashboard, salary structure, bonus process, generate
  challan, increment calculator), `route_app_request_initiate_{loan,reimbursement,variable}`,
  `route_app_request_{proposed,confirmed}investment` and `rail_Analytics__Payroll`. The five `desk_*` captures are other
  modules (Tickets, Asset inventory, HR handbook, Employee goals, Travel request); none is a payroll page.
- Lookups returned by the captured payroll APIs (configuration, not personal data): `payroll/Reasons?requestFor=3`
  (hold / unprocess reasons: Inconsistent attendance, Inconsistent salary structure, Missing variable inputs,
  Inconsistent Investment inputs, Inconsistent TDS, Inconsistent settings, Others), `scheduler/SchedulerType` (payroll
  scheduler job types: process attendance correction arrear, increment arrear, previous month overtime, overtime, OT
  increment arrear, salary, publish / unpublish salary and reimbursement, unlock salary, overtime, previous-month OT,
  increment arrear, attendance correction arrear, salary payment, reimbursement payment, increment overtime),
  `SalaryDashboard/StatutoryDetails` (review status of 12 statutory set-ups), `SalaryDashboard/Reminder` and `Counter`
  (field names of the reminders and warnings), `Hold/Dashboard`, `Payments/{Salary,Reimbursement}/Dashboard`,
  `Arrear/Counter/{A,I,O}`, `Overtime/Counter`, `SalaryPublish/Counter`, `ReimbursementPublish/Counter`,
  `Setting/General` (the payroll general settings already rated in setup matrix 08 §10.9) and `workforce/import/ImportList`
  (the 25 payroll import types with their instructions).
- `hrone-research/menu-permission-tree.md` and `menu-catalogue.json` → `MenuAccessPermission/0` with each node's
  grantable actions: HR Desk → Search → Payroll [19], Inbox Insights → Payroll [21], Action → Payroll [11] (Salary
  dashboard alone has 14 rights), Imports → Payroll [25], Analytics → Payroll [6], Reports → Payroll [65]; Team →
  Reports → Payroll [2], Team → Imports → Payroll [1], Team → People → Action → Payroll (6 rights), Team → People →
  Team profile → Payroll (2 rights). `globalAction` (Payroll: Assign salary structure, Confirmed investment, Encash
  leaves, Generate challan, Increment calculator, Loan request, Process bonus, Propose investment request, Reimbursement
  request, Run payroll (salary dashboard), Variable pay request) and `AvailableSearch` (each page's `importIds` /
  `actionIds`).
- `hrone-research/components.json`: salary dashboard `app-process-salary` → `app-process-payroll`,
  `app-salary-process-steps-card`, `app-salary-process-steps-sidebar`, `app-payroll-table`, `app-warning-card`,
  `app-reminders-card`, `app-schedular-card`, `app-verify-salary-sidebar`, `app-hold-salary-dialog`,
  `app-payment-on-hold-sidebar`, `app-add-variable`, `app-mid-month-increment`, `app-bulk-payslip-layout`,
  `app-bulk-email-slip`, `app-bulk-edit-pay-components`, `app-unclaimed-reimbursement-flyer`,
  `app-reimbursement-delete-flyer`, `app-referral-bonus`, `app-leave-encashment-flyer`, `app-employee-search-filter`,
  `app-confirm-dialog`; salary structures `app-salary-structure*`, `app-assign-salary-structure`,
  `app-update-salary-structure` (+ `-step2`), `app-define-salary-structure`, `app-gol-statutory-details`,
  `app-gol-salary-details`, `app-perquisites-details`, `app-salary-insights`; tax and benefit
  `app-reimbursement-process-*`, `app-reimbursement-task`, `app-reimbursement-detail`,
  `app-investment-declaration-search`, `app-investment-dec-review`, `app-initiate-invest-dec-form`,
  `app-fbp-history-search`, `app-fbp-salary-*`, `app-initiated-fbp-approval`; variable pay `app-variable-*`; loans
  `app-loan-process-*`, `app-loan-detail`, `app-view-loan-detail`, `app-loan-activity-log`; challans
  `app-generate-challan`, `app-*-group-card`, `app-provident-fund-sidebar`, `app-esi-sidebar`, `app-pt-sidebar`,
  `app-lwf-sidebar`, `app-tax-sidebar`, `app-tax-challan-step-one/two`, `app-view-tax-challan`, `app-return-sidebar`,
  `app-missed-subbranch-dialog`, `app-pension-edli-merge-flyer`; salary YTD `app-salary-ytd-*`; leave encashment
  `app-leave-encashment*`; settlement `app-settlement-*`, `app-review-emp-salary`, `app-review-emp-attendance`,
  `app-res-*`, `app-send-settlement-slip`, `app-edit-leave-flyer`; letters `app-salary-revision-letter`,
  `app-letter-generation-detail`; actions `app-increment-calculator`, `app-increment-step-one…four`,
  `app-increment-view-flyer`, `app-process-bonus*`, `app-non-processed-bonus`, `app-processed-bonus`, `app-bonus-*`.
- Page text and screenshots under the session scratchpad `hrone/deep/<id>/` for the ids above (structure only).
- PeopleOS, checked in code: domains `Payroll` (`PayrollRuns`, `PayrollCalculator`, `PayrollComputation`,
  `PayrollReconciliation`, `Payslips`, `BankFile`, `BankFileLayouts`, `PaymentModes`, `SalaryHolds`, `VariablePay`,
  `VariablePayPlans`, `PayCycle`, `PaidPay`, `StructureFormulas`, `FormulaEngine`; inputs `AttendanceArrearInput`,
  `LeaveEncashmentInput`, `LoanDisbursementInput`, `LoanRecoveryInput`, `VariablePayInput`, `ReimbursementClaimInput`,
  `ShiftAllowanceInput`, `TransferAllowanceInput`, `RewardPointsInput`, `Expense*Input`; job `CalculatePayrollRun`),
  `Compensation` (`CompensationChanges`, `CompensationCycles`, `CompensationBudgets`, `CompensationStructures`,
  `SalaryImports`, `CompensationOutput`), `Compliance` (`StatutoryEngine`, `StatutoryOverrides`, `TaxComputer`,
  `InvestmentDeclarations`, `InvestmentProofs`, `TaxIncomeEntries`, `PfOptions`, `GratuityEntitlements`, returns
  `EpfReturns`, `EsiReturns`, `ProfessionalTaxReturns`, `LwfReturns`, `Tds*`, uploads `PerquisiteImports`,
  `OutsideIncomeImports`, `StatutoryOverrideImports`, `PfOptionImports`), `ComplianceReports` (`PfChallan`,
  `EsiChallan`, `BonusRegister`, `WageRegister`, `SalaryVariance` …), `Loans`, `Claims`, `Leave`
  (`LeaveAdjustments::requestEncashment`), `Exit` (`FinalSettlements`, `SettlementSource`), `Letters`, `Experience`
  (`ApprovalCenter`, `PayrollRequestsApprovalSource`, `TimeOfficeApprovalSource`), `Analytics` (`ReportLibrary`,
  `WorkforceMetrics`); Filament `Pages/{PayrollControlRoom, PayrollAuditor, PayCyclePage, ComplianceControlRoom,
  ComplianceReportsPage, DeclareInvestments, MyCompensation}`, `Support/{PayrollActions, CompensationActions,
  StatutoryReturnActions, ExitActions}`, resources `PayrollRuns` (+ `EntriesRelationManager`), `PayrollAdjustments`,
  `PayrollUploads`, `SalaryImports`, `Payslips`, `SalaryHolds`, `SalaryStructures`, `SalaryComponents`,
  `CompensationChanges`, `CompensationCycles`, `CompensationBudgets`, `Loans`, `ReimbursementClaims`,
  `VariablePayRequests`, `VariablePayPlans`, `LeaveEncashments`, `AttendanceArrears`, `TaxDeclarations`,
  `TdsInvestments`, `TaxIncomeEntries`, `StatutoryOverrides`, `PfOptions`, `EpfReturns`, `EsiReturns`,
  `ProfessionalTaxReturns`, `LwfReturns`, `TdsReturns`, `TdsCertificates`, `EmployeePaymentModes`,
  `CompanyBankAccounts`, `BankFileLayouts`, `ExitCases` (+ `SettlementRelationManager`), `Letters`, `LetterTemplates`;
  `Employees/RelationManagers/{CompensationRelationManager, CompensationChangesRelationManager}`; `config/peopleos.php`
  (payroll permissions `payroll.view / manage / calculate / approve / finalize / payslip / request / override`,
  compensation and compliance permissions, payroll settings), `config/peopleos_setup.php`; docs
  `docs/architecture/hrone-parity.md` (HP-2, HP-3, PS-1 … PS-3, BP-2, BP-9, BP-13 / CA-5, EX-E),
  `docs/hrone-parity/setup/00-build-status.md`, `HROne-Gap-Register.md` (PY-01 … PY-33).

**Pointed, not repeated.** Employee request forms (loan, reimbursement, proposed / confirmed investment, variable, leave
encashment) → `20-requests-inbox-team.md` §20.1.3–20.1.6, §20.1.15, §20.1.18; request history cards → §20.1.19; inbox
task views (`app-loan`, `app-loan-guarantor`, `app-reimbursement`, `app-claim-reimbursement`, `app-variable`,
`app-investment-dec-inbox`, `app-investment-declaration`, `app-statutory-setting`, `app-assign-salary-structure`,
`app-fbp-salary-structure`, `app-settlement-inbox`, `app-leave-encashment-detail`) → §20.2.2; team actions → §20.3.2;
team reports → §20.3.4. The report catalogue (HR Desk → Reports → Payroll) → `21-analytics-reports-projects.md` §21.2.2
"Payroll (64)"; report centre, views and schedules → §21.2.1; the payroll dashboard → §21.1.12; the Inbox Insights page
itself → §21.3 (AN-30). Employee pay self-service (My CTC, payslip, salary YTD drill-down, payroll histories) →
`22-my-space-self-profile.md` §22.5–22.7. Shared HR-desk register chrome (filters flyer, chips, saved filters, bulk
framework, typed-count confirmation, import wizard) → `23-workforce-operations.md` §23.1 and §23.10.0; review relieving
details → §23.6.1 #14; letter-register mechanics → §23.7.1. Payroll setup (pay groups, components, settings, slabs,
statutory template, challan set-up) → `setup/08-workforce-payroll.md` §10.1–10.12; statutory DETAILS dialogs, tax
settings, deductor and challan groups → `setup/13-setup-reverification.md` §9 (PY-1 … PY-32; packages SV-1 … SV-4).
Such rows appear here once, naming the matrix and section.

**Rules this matrix applies.** Statutory values (rates, ceilings, slabs, exemption limits, minimum wages, bonus
percentages and ceilings) come only from verified rule versions, never from tenant entry or uploads: HROne screens that
let HR type them are rated N-A with the rule-data equivalent named. Finalized payroll is never reopened: HROne's
"unprocess", "unlock", "unpublish" and "edit after release" on a closed month are rated N-A (closed months) with the
safe equivalent (exclude / recalculate inside an open run, arrear or recovery in the next open month, a supplementary
run). Every proposal is additive with defaults reproducing today's behaviour.

**Progress.**

- Covered: every page of the Payroll panel (23 menu pages, each Records view with its filters, cards, actions and
  dialogs from components, and each Reports tab), the salary dashboard (header, warnings, 11 steps, process sidebars,
  hold / release / publish / payment dialogs, reminders and their flyers, scheduler, bulk payslip download and e-mail),
  Generate challan (PF, ESI, LWF, PT, Income tax challans and the 24Q card), Salary YTD (HR), Leave encashment (bulk
  encash), Full and final settlement (register, three-step initiation, salary-review stepper with its 11 steps,
  summary, slips, bulk pay), payroll letters, the increment calculator and bonus processing, all 19 nodes of HR Desk →
  Search → Payroll with their rights, Action → Payroll [11] with the 14 salary-dashboard rights and the 11 payroll
  global actions, Imports → Payroll [25] (each import with what its instructions say), Inbox Insights → Payroll [21],
  Analytics → Payroll [6], Reports → Payroll [65] (one pointer row plus the one report matrix 21 lacks), Team →
  Reports / Imports / People → Action / Team profile → Payroll.
- Result: 398 rated rows (99 HAVE / 171 PARTIAL / 110 MISSING / 18 N-A), 22 build packages PO-1 … PO-22, 14 owner
  decisions D-PO1 … D-PO14. Complete for scope.
- Could not: the crawl account had no processed payroll for the open month, so step tables, challan cards and the
  settlement stepper were captured empty; their columns and dialogs come from `components.json` (marked
  *(inferred)* where only the label is known). Import template columns are not captured (no template file was
  downloaded); each import is rated from its name and instructions. The bonus and increment-calculator pages opened
  without data (`route_app_payroll_bonusprocess`, `route_app_payroll_incrementcalculator`), so their dialogs come from
  components.
- Built (2026-10-30): PO-1 (Payroll desk in HROne's panel order; pay-group, pay-month, arrear-month / arrear-day,
  revision-month and WO-2 employee filters; views, saved views, "Report", upload links and a "Payroll actions" menu on
  the payroll registers — no bulk payroll operation) and PO-3 (read-only pre-run readiness panel on the payroll control
  room: 14 checks with counts, lists and an audited CSV; statutory thresholds only from VERIFIED rule versions, else "No
  verified rule"). The rows they close are marked "→ built" with the package; §25.6.2 #11 (PAN–Aadhaar link) waits for
  BP-8 `pan_aadhaar_linked`. See `docs/architecture/hrone-parity.md` "PO-1 / PO-3". The counts below are the
  analysis-time counts.

---

## 0. PeopleOS building blocks this matrix relies on

| Building block | Where | Relevance |
|---|---|---|
| Payroll run pipeline: one run per company and pay period (`payroll_periods`, pay cycle from `PayCycle`); draft → calculated → validated → approved (by someone other than the preparer) → finalized (attendance locked, period closed, payslips generated) → paid; an approved run may return to draft (reason); finalized runs are never reopened; recalculation replaces the run's entries (audited with what they were); the engine version and every statutory rule version used are recorded and must be VERIFIED at finalize; compensation fingerprints must still match | `PayrollRuns`, `PayrollRunResource`, `PayrollActions::forRun` (Calculate / Recalculate, Validate, Approve, Finalize & generate payslips, Bank file, Mark as paid, Set payment date, Reopen) | HROne Run payroll (salary dashboard) |
| Payroll control room: per company the current pay month (cycle-aware), run status, headcount, employees without salary, statutory profile present; readiness counts (no salary, no primary bank, no PAN, active rules); runs table; links to Pay cycle and Compliance control room; unverified-rule banner | `Pages/PayrollControlRoom` | HROne salary dashboard header and warnings |
| Calculation: eligibility window (joining / exit) → salary segments (every approved compensation in force in the period, so a mid-month revision is prorated) → attendance LOP / proration → earnings per segment → approved adjustments → payroll inputs (tag `peopleos.payroll_inputs`) → statutory (compliance rules) → net → exceptions (`no_salary`, `no_structure`, `formula_error`, `invalid_component`, `overtime_unpaid`, `negative_net`, `no_bank`, `attendance_missing`, `attendance_incomplete`, `no_pan`, `statutory_*`, `unverified_statutory_rule`, `statutory_override`, `encashment_no_rate`); blocking exceptions stop validation | `PayrollCalculator`, `StatutoryEngine` | HROne process salary |
| Payroll inputs: attendance arrears (days of earlier months), leave encashment, loan payout and EMI, variable pay, pay-on-claim reimbursements, shift and transfer allowances, reward points, expense reports and advances; each line carries its source ids, settled only when the run that paid it is finalized | `Payroll/Inputs/*`, `PayrollInputs` | HROne steps 1–6 inputs |
| Payroll adjustments (earning, deduction, LOP days, reimbursement, arrear, recovery, correction; per employee and open period; approve; edit / delete while the period is open) | `PayrollAdjustmentResource` | HROne "Add variable", manual arrear |
| Statutory overrides (PF / ESI / PT / LWF / TDS amount per employee and pay month, reason, never on one's own pay, never once finalized; upload) | `StatutoryOverrides`, `StatutoryOverrideResource`, `StatutoryOverrideImports`, permission `payroll.override` | HROne Override TDS / ESI / PF imports, "edit TDS" |
| Salary holds (employee, pay month, reason; release with note and outside reference; held employees left out of the bank file) | `SalaryHolds`, `SalaryHoldResource` | HROne Release salary / hold |
| Payment: pay modes (bank transfer / cash / cheque, reimbursements apart), paying accounts, versioned bank file layouts, one file per paying account; cash and cheque register | `BankFile`, `PaymentModes`, `CompanyBankAccountResource`, `BankFileLayoutResource`, `EmployeePaymentModeResource` | HROne Release salary / pay modes |
| Payslips: generated at finalize as an immutable snapshot (number, period, totals, lines), viewed on screen; no PDF, e-mail or password | `Payslips`, `PayslipResource` | HROne Publish salary |
| AI Payroll Auditor (anomaly findings with evidence for a calculated or later run) and compliance builder `SalaryVariance` | `Pages/PayrollAuditor`, `ComplianceReports/Builders/SalaryVariance` | HROne Verify salary |
| Compensation: per-employee effective-dated assignments (structure, annual CTC, currency, variable target, fixed / input component values) created only through compensation changes (propose → review → approve → execute, never for oneself, Approval Center kind `compensation_change`), cycles (rating → % matrix, budget, populate, adjust, approve, execute), budgets, ranges, salary upload (new / update / both: employee code, structure code, annual CTC, effective from, currency, variable target, change type, reason, fixed component columns) | `CompensationChanges`, `CompensationCycles`, `CompensationBudgets`, `SalaryImports`, Employee 360 → Compensation | HROne Assign salary structure, increment calculator, CTC imports |
| Loans and advances (policy-driven request, approval with edits, guarantors, schedule, paid out, prepay N instalments, defer an instalment, payroll recovery, exit recovery) | `Loans`, `LoanResource` | HROne Loans / Loan requests |
| Reimbursement claims (pay on claim: accrual ledger posted at finalize, balance, bills, approve for less, cancel; reminders to claim) | `ReimbursementClaims`, `ReimbursementClaimResource` | HROne Reimbursement requests |
| Variable pay requests and plans (request for self or "for an employee", approve / reject / cancel; plans with monthly targets and a task day) | `VariablePay`, `VariablePayPlans` and their resources | HROne Variable pay |
| Tax declarations and proofs (declare for an employee, regime, sections, landlords, previous employer, other income, employer NPS; windows; proof verification feeding TDS; Form 12BB) | `DeclareInvestments`, `TaxDeclarationResource`, `TdsInvestmentResource`, `InvestmentProofs` | HROne Proposed / Confirmed investments |
| Statutory returns (EPF ECR, ESI, PT, LWF monthly; TDS quarterly statement and annual certificates): generate from finalized payroll → validate & reconcile → approve (never the generator) → export → record submission → record acknowledgement → reconcile filing; compliance report packs (PF / ESI challan working sheets, registers, returns) | `StatutoryReturnActions`, `EpfReturnResource` …, `TdsReturnResource`, `TdsCertificateResource`, `ComplianceReportsPage` | HROne Generate challan |
| Full and final settlement on the exit case: Start settlement, Calculate (final month salary through the payroll calculator as `SAL_*` lines, leave encashment at `exit.encashment_basis`, gratuity from the GRATUITY rule, notice shortfall recovery, asset recovery from clearances, settlement sources: loans, expenses), Add / remove manual lines, Breakdown, Approve (posts the encashment to the leave ledger), Mark paid (reference; settlement sources settle) | `FinalSettlements`, `ExitCases/RelationManagers/SettlementRelationManager` | HROne Settlement |
| Approval Center sources for payroll: compensation changes, letters, loans, loan guarantees, reimbursement claims, variable pay (`PayrollRequestsApprovalSource`), leave encashment (`TimeOfficeApprovalSource`); tax declarations through `TaxDeclarationWorkflowBridge` when a workflow runs | `ApprovalCenter`, `Experience/Sources/*` | HROne Inbox insights → Payroll |
| Payroll uploads screen (row uploads: perquisites, income outside payroll, statutory overrides, PF options) and the salary upload; staged pipeline with P2 governance; Recent uploads | `PayrollUploadResource`, `RowImportTypes`, `SalaryImportResource` | HROne Imports → Payroll |
| Report library payroll datasets and templates (salary register, component register, YTD summary, bank transfer, CTC register, revisions, arrears, reimbursements, TDS, PF / ESI / PT / LWF registers, F&F register, perquisites, overrides, salary payments …) | `ReportLibrary`, `DatasetRegistry` | HROne Reports tabs |

---

## 25.0 Index — HR Desk → Search → Payroll [19] and the Payroll panel

Not counted. Each node's rows are in the section named. "Rights" = the actions HROne can grant on the node. "Overall"
= the weakest material row of the section.

| # | Search node (rights) | Payroll panel item / URL code | Section | Overall |
|---|---|---|---|---|
| 1 | Salary structure (View, Edit, Update employee perquisites, View employee perquisites) | Salary structures · `salarystructure_113` | 25.2.1–25.2.2, 25.2.4 | PARTIAL |
| 2 | Salary structure history (View) | Salary structure requests · `salarystructurehistory_152` | 25.2.3 | PARTIAL |
| 3 | Reimbursement history (View) | Reimbursement requests · `reimbursementhistory_170` | 25.3.1 | PARTIAL |
| 4 | Reimbursement cancel (View) | Reimbursement cancellations · `reimbursement_cancel` | 25.3.1 | MISSING |
| 5 | Proposed investment declaration (View) | Proposed investments · `purposedinvestmentdeclaration_114` | 25.3.2 | PARTIAL |
| 6 | Proposed investment history (View) | Proposed investments requests · `purposedinvestmenthistory_201` | 25.3.2 | PARTIAL |
| 7 | Confirmed investment declaration (View) | Confirmed investments · `confirmedinvestmentdeclaration_181` | 25.3.3 | PARTIAL |
| 8 | Confirmed investment history (View) | Confirmed investments requests · `confirmedinvestmenthistory_202` | 25.3.3 | PARTIAL |
| 9 | Fbp request history (View, View) | Flexible benefit Requests · `fbprequest_684` | 25.3.4 | MISSING |
| 10 | Variable history (View) | Variable pay requests · `variable_739` | 25.4 | PARTIAL |
| 11 | Variable cancel (View) | Variable pay cancellations · `variablecancel_2383` | 25.4 | MISSING |
| 12 | Dispense loan (View, Adjust) | Loans · `loan_112` | 25.5 | PARTIAL |
| 13 | Loan history (View) | Loan requests · `loan_235` | 25.5 | PARTIAL |
| 14 | — (Action node "Salary dashboard", §25.6.11) | Run payroll (salary dashboard) · `salarydetails_109` | 25.6.1–25.6.11 | PARTIAL |
| 15 | — (Action node "Generate challan") | Generate challan · `challan_124` | 25.6.12 | PARTIAL |
| 16 | Salary ytd (View) | Salary YTD · `salaryytd_178` | 25.6.13 | PARTIAL |
| 17 | Leave encashment (Encash leaves) | Leave encashment · `leaveencashment_115` | 25.6.14 | MISSING |
| 18 | Settlement (View, Relieving details, Attendance review, Salary review, Edit settlement) | Settlement · `settlement_117` | 25.7.1–25.7.3 | PARTIAL |
| 19 | Settlement history (View) | Settlement requests · `settlementhistory_276` | 25.7.4 | PARTIAL |
| 20 | Appointment letter (View letter, Generate letter, Unpublish) | Appointment letters · `appointmentletter_272` | 25.8 | PARTIAL |
| 21 | Salary revision letter (View letter) | Salary revision letters · `salaryrevisionletter_709` | 25.8 | PARTIAL |
| — | — | Reports (`openReports`, module 3) | 25.13 | PARTIAL |
| — | — | Payroll Setup | `setup/08-workforce-payroll.md` §10, `setup/13-setup-reverification.md` §9 | — |

---

## 25.1 Payroll panel and register chrome (`rail_Payroll`, every `/app/processes/search/*` payroll page)

The Payroll panel uses the same shell as Workforce (matrix 23 §23.1: Records / Reports tabs, search, Filters flyer,
chips, IMPORTS, split action button, cards with SHOW MORE, bulk notes, Create View). Only what differs on the payroll
pages is rated here.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Rail "Payroll" panel: top items Salary structures, Salary structure requests; groups Salary structure, Tax & benefit, Variable pay, Loan, Payroll processing, Full and final settlement, Payroll letters; Reports; Payroll Setup; panel search | One home for every payroll operation, grouped by money flow | Navigation group "Payroll" (Control room, Auditor, runs, adjustments, holds, loans, claims, variable pay, payslips, uploads, structures, components …) plus Compliance and Compensation groups; ordered by object, not by HROne's journey | PARTIAL → built (PO-1: Payroll desk (Payroll → Payroll desk, own rail area) in HROne's order — Salary structure · Tax & benefit · Variable pay · Loan · Payroll processing · Full and final settlement · Payroll letters · Reports · Payroll setup — each entry the existing register or page with a preset view (`?view=`), counts from its scoped query, a "Payroll actions" header menu; nothing moved or renamed) | PO-1: a "Payroll desk" cluster ordering the existing entries as Salary structure · Tax & benefit · Variable pay · Loan · Processing · Full and final · Letters · Reports · Setup, each linking the existing resource with its preset filter; no new screens where a resource exists |
| 2 | Shared chrome rows (Records / Reports tabs, search by name / code / designation / e-mail, Filters flyer, organisation and location filters, "Add bulk employees", status / type / other status filters, saved filters, card grid, bulk selection, typed-count confirmation, bulk progress popup, "Looking for something else") | Same as the Workforce pages | As rated in matrix 23 §23.1 #2–#17 | PARTIAL → built (PO-1 applied WO-1 / WO-2 to the payroll registers: saved views, presets, "Report", employee search, pasted codes, organisation group, employee status / type / joining, pending-resignation toggle — payroll side only; no bulk payroll operation) | Pointer: WO-1 / WO-2 build these once for every HR-desk register; PO-1 applies them to the payroll registers |
| 3 | Pay-group filter ("Pay Group" on the salary dashboard, verify and unclaimed / delete-reimbursement flyers, bulk payslip "Download for specific paygroup") | Narrow any payroll list to one pay group | Pay group = salary structure; no structure filter on runs, entries, claims or variable pay | MISSING → built (PO-1: "Salary structure (pay group)" filter — the structure of the approved compensation in force (run entries: on the run's period end; claims, variable pay, loans, holds: today) through CompensationOutput::coveredEmployeeIds($date, $structureIds)) | PO-1: "Salary structure" filter (from the employee's assignment in force on the period end) on run entries, claims, variable pay, loans, holds; reused by PO-7 bulk downloads |
| 4 | "Exclude employees with pending resignation", "Exclude Employees" (payroll filters) | Leave leavers out of a payroll list | — | MISSING → built (PO-1: WO-2 "Leave out employees with a pending resignation or exit" on run entries and every opted-in payroll register) | WO-2 filter (exit case open), reused on run entries |
| 5 | Filters "Pay Arrear (For) Month", "Salary Month", "Salary", "Arrear Days" (1-5 / 5-10 / >10 / Zero / Less than zero), "Salary Revision (On) month", "Leave Type" (payroll variant of `app-employee-search-filter`) | Payroll-specific slices of a step table | Run entries filter by status only; arrears list has no filters | MISSING → built (PO-1: "Arrear for month" and "Arrear days" bands (1–5, more than 5 up to 10, more than 10, zero, less than zero; per employee total) on attendance arrears; "Revision (effective) month" on compensation changes; "Pay month" (HROne's Salary month) on variable pay and salary holds; leave type on encashments was TX-1) | PO-3: step tables with these filters (arrear month, revision month, arrear-day bands) over entries and inputs |
| 6 | "IMPORTS" on each page (`AvailableSearch.importIds`: structures 22, 23, 20, 118, 57; reimbursements 51, 53, 52; investments 24, 114; variable 21 / 179; loans 130; settlement 108) | Upload the data of this register | Payroll uploads and Salary upload screens; not linked from the registers | PARTIAL → built (PO-1: "Upload" header group linking the salary upload (compensation changes, salary structures) and the payroll upload types (perquisites, income outside payroll, statutory overrides, PF options), each shown only to people allowed that upload; reimbursement, investment, variable, loan and settlement uploads do not exist yet (PO-18)) | §25.10; PO-1 links each register to its upload types |
| 7 | Split action button per page (Assign salary structure, Reimbursement request, Propose investment request, Confirmed investment, Variable pay request, Loan request, Encash leaves) | Start the HR action from the register | Header actions exist for some (loan "Raise on behalf", variable pay "For an employee", Declare investments); none for claims or bulk encashment | PARTIAL → built (PO-1: "Payroll actions" menu (desk, runs, claims, variable pay, loans, holds) linking Run payroll, salary upload, declare investments, variable pay request, loan request on behalf, hold salary, record a perquisite, generate challan — each only when its screen and right allow it; claim on behalf and bulk encashment stay §25.9 (PO-9, PO-8)) | §25.9 |
| 8 | Card avatar, name and code, company, department / designation, status chip; type-specific lines ("Requested by :", "Requested on :", "Pay month :", "Settlement type :", "Generated by :", "Salary revision date :") | Card per record | Tables with the same facts as columns | HAVE | Card layout is WO-1 (matrix 23 §23.1 #10) |
| 9 | "Payroll Setup" panel item | Jump to payroll setup | Setup centre → Payroll | HAVE | Setup matrices 08 / 13 |
| 10 | Banners (subscription overdue, contact verification, low bandwidth, chat) | Vendor chrome | — | N-A | Vendor / UI chrome |

---

## 25.2 Salary structure

### 25.2.1 Salary structures — Records (`/app/processes/search/salarystructure/search`, `salarystructure_113`; `app-salary-structure-search` → `app-salary-structure-search-home`, `app-salary-structure-card`, `app-salary-structure`)

An employee-centric list: every employee with the state of their salary structure, and the entry point for assigning
one. PeopleOS keeps salary as effective-dated compensation assignments reached per employee (Employee 360 →
Compensation) or through the compensation-change list; there is no "employees and their salary structure" register.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Card per employee with chip Assigned / Not Assigned / Pending / Approved / Rejected | Salary-structure state of each employee | Employee 360 → Compensation (assignments: from, to, in force, type, structure, annual CTC, monthly, variable target, fixed components, range position, reason, approver); Compensation changes list (status); Control room counts employees without salary | PARTIAL | PO-2: "Salary structures" register — one row per employed person: structure and annual CTC in force (through `CompensationOutput`, never reading compensation tables from payroll), effective from, state (assigned / not assigned / change pending / rejected last), link to the 360 tab |
| 2 | Filters: Status, Source, Tax regime, Request date (`app-salary-structure-search-home`) | Slice by state, origin (UI / import / task), regime, request date | Compensation changes: filters change type, status; `source` column; no regime or date filter | PARTIAL | PO-2 filters on the register: state, source (screen / upload / cycle / position), tax regime (from the current declaration), proposed-on range |
| 3 | "Please find employees for whom Salary Structure is pending", "Export All", "NO SALARY STRUCTURE" (`app-salary-structure`) | List and export joiners without a structure | Control room "without salary" count only; run exception `no_salary` per employee after calculation | PARTIAL | PO-2 preset "Not assigned" + export (`compensation.export`, audited); PO-3 shows the same count before the run |
| 4 | Search "Search employee by name, code, designation, email" | Find an employee | Employee pickers search name / code | PARTIAL | WO-2 shared employee search |
| 5 | Split button "Assign salary structure" (global action; `EmployeesForSalaryStructure` API) | Start assignment for an employee | Employee 360 → Compensation → "Propose compensation change" / "Propose from position" | PARTIAL | §25.2.2; PO-2 adds the action on the register (one or many employees) |
| 6 | "Imports ▾" (CTC auto, CTC manual, Employee statutory, Perquisites auto, Change pay group) | Bulk structure uploads | Salary upload (new / update / both) | PARTIAL | §25.10 rows 1–5 |
| 7 | Reports tab (report picker, Create View) | Page report | Report library `ctc_register`, `salary_revisions` | PARTIAL | Matrix 21 §21.2.2 Payroll #6–#9 (AN-24 `ctc_components`) |

### 25.2.2 Assign salary structure (`app-update-salary-structure` → "Employee Statutory" `app-gol-statutory-details` + "Salary Structure" `app-update-salary-structure-step2` / `app-gol-salary-details`; `app-perquisites-details`; `app-define-salary-structure`; `app-salary-insights`; new-join task `app-assign-salary-structure`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Two steps "Step 1 Employee statutory · Step 2 Salary details", Previous / Next / Completed | Statutory set-up and salary together | Statutory details on Employee 360 (view / edit, applicability action) and the compensation proposal are separate actions | PARTIAL | PO-2: "Assign salary" wizard (statutory step reusing `ChangeStatutoryApplicabilityAction`, then the existing proposal form) — forms as wizards (owner preference) |
| 2 | "View PAN detail" / "PAN detail missing" | Show PAN or warn | Statutory details (PAN masked; reveal audited); readiness count `no_pan` | HAVE | — |
| 3 | "Is employee's Aadhar and PAN number linked?" Yes / No (`panAdhaarLinked`) | Higher TDS when not linked | — | MISSING | BP-8 `pan_aadhaar_linked` (setup matrix 08 §10.11 #3); TDS consequence from the TDS rule version, not hard-coded |
| 4 | Provident Fund applicable (`providentFundApplicable`), "Employee PF contribution limit" (`providentFundGrossLimit`), "Employer PF contribution limit" (`employerProvidentFundGrossLimit`) | PF membership and the wage basis per side | `employee_statutory_details.pf_applicable`; `employee_pf_options` (effective-dated: VPF, ceiling / actual wage basis) — one basis for both sides | PARTIAL | BP-11 `employer_wage_basis` on `employee_pf_options` |
| 5 | "Prorate PF as per paid days" (`providentFundProrate`), "Prorate employees PF contribution limit on paid days" (`providentFundLimitProrate`) | Whether the PF ceiling is prorated for part months | PF on earned PF wages (paid days); ceiling not prorated | PARTIAL | BP-11 `prorate_ceiling` (employee option + EPF profile default) |
| 6 | Employee state insurance applicable (`candidateStateInsuranceApplicable`) | ESI membership | `esic_applicable`; eligibility from the ESI rule | HAVE | — |
| 7 | Labour welfare fund applicable (`laborWelfareFundApplicable`) | LWF membership | LWF by establishment state and rule; no employee switch | MISSING | BP-11 `lwf_applicable` (default true = today) |
| 8 | Professional tax applicable (`professionalTaxApplicable`) | PT membership | `pt_applicable`, `pt_state_code` | HAVE | — |
| 9 | Gratuity applicable (`gratuityApplicable`), "Do you want to provision gratuity in CTC?" (`gratuityPartOfCastToCompany`) | Gratuity eligibility and whether it sits in CTC | Eligibility from the GRATUITY rule at exit (`GratuityEntitlements`); CTC inclusion by an employer-gratuity line in the structure | PARTIAL | BP-11 `gratuity_service_from`; CTC inclusion stays a structure choice (SV PY-10) |
| 10 | Employee contribution to NPS: Percentage / Amount, "prorate the amount as per paid days?", Minimum contribution ("shouldn't be greater than NPS Amount"), PRAN number (`nationalPensionScheme*`, `pranNumber`) | Employee NPS deduction set-up | NPS by component classification `nps_employee` in the structure; no per-employee %, minimum or PRAN | PARTIAL | BP-11 (`pran`, per-employee NPS amount / % through an input component, BP-2 `input`) |
| 11 | Employer contribution to NPS: Amount / Percentage, prorate, minimum, PRAN (step 2) | Employer NPS (80CCD(2)) | `nps_employer` classification; "Employer NPS" action on Tax declarations (payroll-owned amount) | PARTIAL | BP-11 (same input-component route); 80CCD(2) limit from the TDS rule |
| 12 | "Salary payment mode" | Bank / cash / cheque for this employee | Payroll → Payment modes (`employee_payment_modes`, effective-dated, BP-13) | HAVE | PO-2 shows it read-only in the wizard with a link |
| 13 | CTC (`costToCompany`) | Annual CTC | Annual CTC on the proposal (optional for components-mode structures) | HAVE | — |
| 14 | Salary effective date (`salaryEffectiveDate`) ("Salary revision month") | When the new salary applies | `effective_from` (any day; part months are prorated by segments) | HAVE | HROne starts revisions on the 1st or joining day; PeopleOS is a superset |
| 15 | Arrear effective date (`arrearEffectiveDate`) ("Arrear with effect from", ≤ revision month) | Back-dated revision paid as arrear for months already processed | Proposals into closed pay months are refused (finalized payroll is never reopened); no arrear date | MISSING | PO-20 (= BP-10 `RevisionArrearInput`): `compensation_changes.arrear_from` (≤ effective month); the difference for finalized months is paid in the next open run; closed months untouched |
| 16 | Pay group (`payGroup`) | Which pay group (structure) | Structure select (active structures; applicability checked) | HAVE | — |
| 17 | Currency (`currencyCode`) | Pay currency | Currency select | HAVE | — |
| 18 | Revision mode (`revisionMode`): revised CTC amount (`revisedCtcAmount`) or increment % (`incrementPercentage`) | Revise by amount or by percent | Proposal by amount; percentages only in compensation cycles | PARTIAL | PO-2: "Increase by %" on the proposal (CTC worked out from the CTC in force, rounded as the structure says) |
| 19 | Remarks (`salaryStructureRemarks` / `remarks`), "External remarks: Remark 1 / 2 / 3", "Internal remarks", "Comments" | Notes shown to the employee and internal notes | Reason (required) and internal notes (never shown) | HAVE | — |
| 20 | "Template name", "Description", "Select whichever is applicable", "Salary structure details" | Structure chosen and its description | Structure name / description on the proposal | HAVE | — |
| 21 | "Print Salary" | Printable breakup for the employee | Salary annexure in letters (`{{ salary.annexure }}`); structure Preview | PARTIAL | PO-2 "Print breakup" renders the annexure for the proposal (letter-studio layout, no new template engine) |
| 22 | Breakup (`app-gol-salary-details`): (A) Salary details, CTC, "Go"; (B) Gross benefits; (C) Other benefits; (D) Contributions / Retirals with "Monthly Contribution" and "Calculate Retirals"; (E) Recurring deductions (Employee PF, ESI, LWF); (F) Out of CTC allowances; Gross (B); "Net take home (Excluding TDS)"; columns Component Name, Nature of Component, Formula, Monthly Remuneration; arrows to reorder; TDS | Live breakup of the employee's CTC with retirals and take-home | `StructureFormulas::preview` per structure version (sample CTC, lines, employer PF estimate, balancing warning, wage share); not for the employee being proposed; no recurring-deduction estimate | PARTIAL | PO-2: preview inside the proposal with the employee's CTC, inputs and PF options; deductions estimated read-only from `StatutoryEngine` for the effective month (rule data, nothing stored) |
| 23 | Salary insights: "Compliant as per the New Code of Wages", "Wages are greater than exclusions", "Exclusions are greater than wages. You may want to review the salary structure break up", "Wages are less than defined minimum wage or minimum wages slab doesn't exist"; columns Wages / Exclusions / Not part of Wages/Exclusions | Code-on-Wages and minimum-wage check on the assignment | Wage-share guardrail (advisory) in the structure preview and My compensation wage split; no minimum wage check | PARTIAL | PO-2 shows the guardrail for the proposal; minimum wage after BP-15 `MIN_WAGE` rule versions (verified data) |
| 24 | Perquisites details (`app-perquisites-details`): Type A rows (`isSelected`, from month / year, to month / year, perquisite value, recovered amount, effective perquisite value), rent-free accommodation rows (`PerquisitesTypeRFA`, "by employer"), "City slab" | Perquisites tied to the salary structure, valued per month | Perquisite entries per financial year (`tax_income_entries` kind perquisite: type, value, recovered, taxable; upload) | PARTIAL | PO-18: monthly perquisite schedule (from / to month) as entries per month; RFA valuation by city slab only from TDS rule parameters (owner-verified), else entered value |
| 25 | Rights "Update employee perquisites" / "View employee perquisites" | Separate access to perquisites | `payroll.manage` / `payroll.view` | PARTIAL | Kept coarse unless the owner asks (D-PO12) |
| 26 | "Do you never intend to process payroll of this employee in HRone?" (new-join task) | Mark employees paid outside the system | — (they raise `no_salary` every month) | MISSING | PO-5: effective-dated "not paid through PeopleOS" marker (reason) that removes the employee from the run population and readiness counts; default none |
| 27 | "New join CTC", "Offer letter" (CTC from the offer) | Use the offer's CTC for the joiner | — (offers live in the RMS) | N-A | Recruitment (RMS boundary); CTC can arrive through the salary upload |
| 28 | "ASSIGN / REVIEW SALARY STRUCTURE", "Please review salary structure", workflow, IGNORED | Maker-checker on assignment | Propose → review → approve → execute, never for oneself; Approval Center kind `compensation_change`; workflows | HAVE | — |
| 29 | "Assigned Allowances": Allowance / Amount / Variable | Fixed allowances and variable target | Fixed component values and inputs; variable target | HAVE | — |
| 30 | Bulk assignment (CTC imports, task per joiner) | Many employees at once | Salary upload (new / update / both) | HAVE | On-screen multi-select in PO-2 |

### 25.2.3 Salary structure requests (`/app/processes/search/salarystructurehistory/history`, `salarystructurehistory_152`) and their Reports tab

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Request cards (Approved / Pending / Rejected), same filters as §25.2.1 #2 | History of assignments and revisions | Compensation changes list: reference, employee, change type, effective from, annual CTC, previous CTC, increase, status, proposer, approver, source; filters change type, status | HAVE | Date and regime filters with PO-2 |
| 2 | Reports tab columns Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, PAN, Aadhaar no, Date of birth, confirmation, leaving, resignation, Father's name, Gender, Marital status, Skill type | Employee block of the salary-history report | `salary_revisions` and employee datasets (PAN / Aadhaar masked; father's name and skill type not in payroll datasets) | PARTIAL | Matrix 21 §21.2.2 Payroll #6 (skill type after BP-15 `wage_skill_categories`) |
| 3 | Columns Salary revision month, Arrear with effect from, Pay group, PF applicability | Revision facts | Effective from, structure; no arrear month | PARTIAL | `arrear_from` with PO-20 |
| 4 | Columns Yearly CTC, Monthly CTC, Total gross benefit, Employer PF, Employer ESI, Employer NPS, Gratuity, Total contributions, Total recurring deductions, Net take home, one column per component ("Name · code") | Money breakup per revision | `ctc_register` totals (annual, monthly, fixed, variable) | PARTIAL | Matrix 21 AN-24 `ctc_components` (+ employer contributions and take-home from the preview of row 22) |
| 5 | Right "View" | Read access | `compensation.view` | HAVE | — |

---

## 25.3 Tax & benefit

### 25.3.1 Reimbursement requests (`/app/processes/search/reimbursementhistory/history`, `reimbursementhistory_170`) and Reimbursement cancellations (`/app/processes/search/cancelreimburshment/search`, `reimbursement_cancel`) — `app-reimbursement-process-search`, `-card`, `-view-form` → `app-reimbursement-task`, `-workflow`, `app-reimbursement-detail`, `app-reimbursement-table`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Request cards with status (Pending / Undo / Rejected / Approved), SHOW MORE | Every reimbursement claim in scope | Reimbursement claims list: employee, component, claim date, amount, approved amount, bills, status, description; HR sees all (`payroll.manage`) | HAVE | — |
| 2 | Filters Status, Component type, Request date, Approved date | Slice claims | Status filter only | PARTIAL | PO-9: component, claim-date and decided-date ranges, salary structure (PO-1) |
| 3 | Split button "Reimbursement request" (global action; right "Apply reimbursement") | HR raises a claim for an employee | Claims are raised only by the employee ("Claim a reimbursement") | MISSING | PO-9: "Claim for an employee" (`payroll.manage`, in scope, never one's own; same balance and bill rules; approved by someone else) |
| 4 | Bulk "Cancel all" ("In order to do bulk cancel of reimbursement request, apply status filter", "Employees selected") | Cancel many open claims | Cancel one claim at a time (pending / approved, not paid; reason; audited) | MISSING | PO-9 bulk cancel over the WO-1 bulk framework (each claim through `ReimbursementClaims::cancel`; refused for a claim an unfinalized run already carries, like EX-E's "nothing paid twice") |
| 5 | Claim view (`app-reimbursement-task`: component, "Amount which can be claimed", Claim amount, Bill amount, View bill, Remarks, "Invalid Amount"; `app-reimbursement-detail`: submitted bill amount, bill already submitted, claim already, attachment, leave period, leave type, claimed amount, activity log, due chips) | Review a claim | Approval Center kind `reimbursement_claim` (approved amount editable, bills, attachments) | HAVE | Matrix 20 §20.2.2 #17; leave period / type belong to LTA (PY-04) |
| 6 | Help "Once you submit cancel request from here, you need to go to insights page to approve it" (cancellation needs approval) | Cancelling an approved claim is itself approved | Direct cancellation by the employee or payroll (reason, audited) | PARTIAL | PO-9: setting `payroll.claims.cancel_requires_approval` (default off = today) turning a cancel of an approved claim into a request in the Approval Center |
| 7 | Reimbursement cancellations register (cards of cancellation requests; right "View") | List of cancellation requests | — (cancelled claims are visible by status) | MISSING | PO-9: preset "Cancellation requests" once row 6 is on; until then the status filter "cancelled" serves |
| 8 | Totals table (`app-reimbursement-table`): Total Claimed, Total Paid, Total Bill, Taxable (FY) | Year totals per employee and component | Balance per component (ledger less waiting claims); no FY totals on the list | PARTIAL | PO-9 summary row per employee / component for the FY (ledger read); matrix 22 MS-10 for self |
| 9 | "Imports ▾" (Reimbursement bills — pay by default, Reimbursement claims — pay on claim, Reimbursement opening balance) | Bulk claim inputs | — | MISSING | §25.10 rows 15–17 |
| 10 | Reports tab | Claim reports | `reimbursements_this_fy`; ledger datasets missing | PARTIAL | Matrix 21 §21.2.2 Payroll #47–#51 (AN-26) |

### 25.3.2 Proposed investments (`/app/processes/search/proinvestment/search`, `purposedinvestmentdeclaration_114`) and Proposed investments requests (`/proinvestment/history`, `purposedinvestmenthistory_201`) — `app-investment-declaration-search`, `app-investment-dec-review`, `app-initiate-invest-dec-form`, `app-invest-tax-computation`, `app-invest-dec-exemption`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Cards per declaration ("From :", status, "Last updated :") | Every proposed declaration | Tax declarations list: code, employee, financial year, regime, declared total, status; Open / View, Verify, Employer NPS | HAVE | — |
| 2 | Filters Status, Financial year, Tax regime, Request Date, Approved Date | Slice declarations | Status filter | PARTIAL | PO-11: FY, regime, submitted-on and verified-on ranges |
| 3 | Split button "Propose investment request" / "Investment Request" (rights "Request on behalf") | HR declares for an employee | Declare investments with an employee picker (`payroll.manage`; outside the window only payroll changes declarations) | HAVE | — |
| 4 | "HRA Import" | Upload rent / landlord declarations | — | MISSING | §25.10 row 6 (PO-11) |
| 5 | "Investment Import" | Upload declarations | — | MISSING | §25.10 row 8 (PO-11) |
| 6 | Bulk document download ("In order to download documents in bulk, please apply appropriate filter", "Employee(s) selected") | Download proofs of many employees | Signed per-file downloads (`ParityFileController`, audited) | MISSING | PO-11 = matrix 20 RQ-7b: zip of the filtered proofs, one audit entry per file |
| 7 | Review (`app-investment-dec-review`): Edit, ADD INVESTMENT, Activity / Activity log, "Edited" marker, Download proofs, Note | Payroll edits a submitted declaration with a trail | Payroll may edit (`payroll.tax.payroll_may_edit`, default on); audit events record each change; no activity panel on the declaration | PARTIAL | PO-11: activity panel from audit events (who / when / what changed) |
| 8 | "Tax regime change request: The user has requested to change the tax regime from … to …", "Tax type" | Approve a regime switch | Regime chosen on the declaration; `regime_locked_after_submit` | MISSING | Matrix 20 RQ-7b (regime-change request); PO-11 shows it on the review |
| 9 | Declaration form fields (steps, regime comparison, estimator, Chapter VI-A, HRA / 80GG, previous employer, other income, summary, confirmation text) | The declaration itself | Declare investments wizard | PARTIAL | Rated field by field in matrix 20 §20.1.5 (RQ-7b) |
| 10 | Exemption limits in review (`app-invest-dec-exemption`: `maxLimitForSelf`, `maxLimitForParent`, Remarks; `app-invest-tax-computation`: Self / Parents contribution) | Per-employee 80D limits (self / parents, senior citizen) | 80D limits from the TDS rule version and the declaration's senior-citizen answers | N-A | Statutory limits come only from rule data; the declaration records the facts (age of parents) that pick the limit |
| 11 | Requests list = each submission of a proposed declaration (history) | Versions of the declaration over the year | One declaration per FY, edited in place (audited); no submission snapshots | PARTIAL | PO-11: snapshot per submission (`tax_declaration_submissions`: submitted at, by, regime, totals, items) |
| 12 | Approval of a proposed declaration (Inbox insight "Proposed investment") | Payroll approves declarations | Workflow when configured (`TaxDeclarationWorkflowBridge`); not an Approval Center kind | PARTIAL | Matrix 20 RQ-10 (#20) |
| 13 | Reports tab | Investment reports | Investment templates (80C, 80D, other sections, other income, details); HRA columns and "not submitted" partial | PARTIAL | Matrix 21 §21.2.2 Payroll #21–#27 |

### 25.3.3 Confirmed investments (`/app/processes/search/coninvestment/search`, `confirmedinvestmentdeclaration_181`) and Confirmed investments requests (`/coninvestment/history`, `confirmedinvestmenthistory_202`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Cards per proof submission (status, FY) | Proofs to verify | TDS investments list: employee, FY, section, description, declared, proof claimed, proof amount, files, proof status; filter proof status | HAVE | — |
| 2 | Filters Status, FY, Tax regime, Request / Approved date | Slice | Proof-status filter | PARTIAL | PO-11 (as §25.3.2 #2) |
| 3 | Split button "Confirmed investment" (rights "Request on behalf") | HR submits proofs for an employee | Employees submit in the proof window (Me → My investment proofs); payroll verifies; no HR upload of an employee's proof | PARTIAL | PO-11: "Submit proof for an employee" (`compliance.tds.manage`, files, claimed amount; the verifier must be someone else) |
| 4 | Verification of each item (approve the confirmed amount, reject with a reason) | Verified amounts feed TDS | "Verify proof" per item (`InvestmentProofs`), "Verify" per declaration; verified amounts used from `payroll.tax.verified_proofs_from` | HAVE | — |
| 5 | Bulk document download | Download proofs of many | As §25.3.2 #6 | MISSING | PO-11 |
| 6 | Requests list = each proof submission | History of submissions | Proof fields on each item (submitted at, claimed, files, status) | PARTIAL | PO-11 snapshots (as §25.3.2 #11) |
| 7 | Reports tab | Proof reports | As §25.3.2 #13 | PARTIAL | Matrix 21 |

### 25.3.4 Flexible benefit Requests (`/app/processes/search/fbphistory/search`, `fbprequest_684`; `app-fbp-history-search`, `app-fbp-salary-structure`, `app-fbp-salary-details`, `app-initiated-fbp-approval`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Cards "Salary Structure", filters Status, Request date, Approved date; workflow flyer; rights View, View | Employees' flexible-benefit choices and their approval | — (FBP not built, PY-03) | MISSING | PO-21 (= setup BP-7 FBP part): `fbp_declarations` (employee, FY, component, amount, status) within the structure's FBP components (min / max from component fields), approval source, paid through the compensation values |
| 2 | "Declare FBP" / "Please declare FBP structure" (employee task), "Please approve FBP" (approver task), "View Details", IGNORED | Declare and approve the split | — | MISSING | PO-21 (inbox kinds "Declare FBP", "FBP approval" with PO-14) |
| 3 | FBP detail (`app-fbp-salary-details`, `app-define-salary-structure`): Salary Structure, salary insights, Comments | Show the declared split against the structure | — | MISSING | PO-21 reuses the PO-2 breakup preview |
| 4 | Import note "In case of FBP enabled pay group, user is expected to self validate minimum and maximum amount mapped on a component" | FBP bounds on uploads | — | MISSING | PO-21 validates bounds on the salary upload too |

---

## 25.4 Variable pay — Variable pay requests (`/app/processes/search/variable/search`, `variable_739`) and Variable pay cancellations (`/variable-cancel/search`, `variablecancel_2383`) — `app-variable-search` → `app-variable-cards`, `app-variable-request-flyer`, `app-variable-approval-details`, `app-variable-payment-details`, `app-variable-activity-log`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Cards "Requested by :", "Requested on :", "Pay month :", status | Every variable pay request | Variable pay requests list: employee, component, amount, pay month, reason, requested by, status | HAVE | — |
| 2 | Filters Status, "Requested by" (employee search) | Slice | Status filter | PARTIAL | PO-6: requested-by, pay month, component filters |
| 3 | Split button "Variable pay request" (global action; right "Request on behalf") | HR / manager requests for an employee | "For an employee" (`payroll.manage` or a manager with `leave.approve`), never for oneself | HAVE | — |
| 4 | Approval view (`app-variable-approval-details`): Employee name, Pay month, "Pay variable" (editable `payAmount`), Comments (`approverRemarks`), Attachment, "Total eligibility in current Financial year", "Total variable paid in current Financial year", Activity log | Approver sees the year's eligibility and paid total and may change the amount | Approval Center kind `variable_pay` (approve / reject with note); no amount edit, no FY totals | PARTIAL | Matrix 20 RQ-5 (amount edit within policy); PO-6 FY eligibility (variable target in force through `CompensationOutput`) and paid-to-date (finalized lines through `PaidPayReader`) |
| 5 | "Pay as per: Formula / Specific" (`isFormulaPay`), component (`payCode`), "Select months" | Pay the structure's variable formula or a specific amount, for chosen months | Specific amount per request; plans hold monthly targets | PARTIAL | PO-6: "As per structure" works the amount out from the component's formula and the variable target at request time (shown, stored as the amount) |
| 6 | "Do you want to enable auto payment as per the defined payment schedule?" (`payAfterRequestApproval`) | Pay the planned schedule without a monthly request | Plans ask the manager on `payroll.variable.task_day`; each payout becomes a request | PARTIAL | PO-6: plan flag `auto_pay` (default off = today) creating the month's request from the target, approved by the plan's approval rule |
| 7 | "View PAN detail / PAN detail missing", "Is employee's Aadhar and PAN number linked?" on the payment form | Tax check before paying | `no_pan` exception at calculation | PARTIAL | BP-8 flag (as §25.2.2 #3) |
| 8 | Activity log (`app-variable-activity-log`: "Change") | Changes to the request | Audit events (no panel) | PARTIAL | PO-6 activity panel from audit events |
| 9 | Variable pay cancellations register (cancellation requests; Inbox insight "Variable cancel"; import 179) | Cancelling an approved variable is approved | Direct cancel of a pending / approved, unpaid request (reason, audited) | MISSING | PO-6: setting `payroll.variable.cancel_requires_approval` (default off = today) + register preset; a cancel is refused while an unfinalized run carries the request |
| 10 | "IMPORTS" (Variable pay 21, Variable cancel 179) | Bulk variable inputs | — | MISSING | §25.10 row 13 |
| 11 | Reports tab | Variable reports | No variable datasets | PARTIAL | Matrix 21 §21.2.2 Payroll #60–#62 (AN-26) |

---

## 25.5 Loan — Loans (`/app/processes/search/loan/search`, `loan_112`; Search node "Dispense loan": View, Adjust) and Loan requests (`/loanhistory/history`, `loan_235`; "Loan history") — `app-loan-process-search`, `-card`, `-workflow`, `app-loan-detail`, `app-view-loan-detail`, `app-loan-activity-log`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Loan cards; Loan requests = the request history | Loans and requests in scope | Loans & advances list (number, employee, type, amount, EMI, instalments, outstanding, status) with the instalment schedule; requests and loans are one record | HAVE | — |
| 2 | Filters Loan request date, Loan approved date, Dispense date, Loan type, Status | Slice | Filters loan type, status | PARTIAL | PO-10 date ranges (= matrix 20 RQ-1 #17) |
| 3 | Split button "Loan request" (global action; right "Request on behalf") | HR raises a loan for an employee | "Raise on behalf" (`payroll.manage`) | HAVE | — |
| 4 | Approval (`app-loan-detail`): Loan amount (`approveLoanAmount`), No. of EMI (`equatedMonthlyInstallments`), first month of deduction (`equatedMonthlyInstallmentsStartDate`), Comments (`remarks`), APPROVE / REJECT | Approver may change the terms | `Loans::approve` with changes when the loan policy allows (`approver_may_edit`, `interest_editable`); request as asked kept | HAVE | — |
| 5 | Interest type (`interestType`) and Rate of interest (`interestRate`) | Flat or reducing interest | Interest a year on a reducing balance (policy); rate editable when allowed | PARTIAL | PO-10: policy option `interest_method` reducing (default = today) / flat |
| 6 | "Auto loan scheme" (`loanScheme`) | Pick the loan scheme the terms come from | Loan types of the `loan` policy | HAVE | — |
| 7 | IGNORE / IGNORED | Dismiss the task without deciding | Archive (P4) for the approver's own queue; the request stays pending | HAVE | — |
| 8 | DISPENSE (task "Dispense loan"; Inbox insight "Dispense loan") | Pay the loan out | "Mark paid out" (outside payroll) or payout through payroll (`LoanDisbursementInput`, `payout` = payroll) | HAVE | — |
| 9 | "EXISTING LOANS — No of Loans, Total outstanding amount, VIEW DETAILS" | Approver sees open loans | Open-loan limit enforced by the policy; no panel | PARTIAL | Matrix 20 RQ-5 ("your open loans" panel, also on approval) |
| 10 | "Please upload related document", "UPLOAD ATTACHMENT", "Upload the signed document", guarantee terms download, "Employee consent form" | Documents on the loan | — | MISSING | Matrix 20 RQ-5 (attachments, guarantee terms, signed copy) |
| 11 | "View guarantor details" | Guarantors | Guarantors on the loan page (BP-9) | HAVE | — |
| 12 | Activity log (`app-loan-activity-log`) and due chips (MISSED TO RESPOND, OVERDUE, DUE TOMORROW / TODAY) | Trail and urgency | Audit events; Approval Center ageing | PARTIAL → due chips built (RQ-8: unified inbox due labels and "Missed to respond"); the loan activity panel stays PO-10 | PO-10 activity panel; due chips with matrix 20 RQ-8 |
| 13 | Loan view (`app-view-loan-detail`): Loan amount, Total interest, Paid, To pay, Date of request, Date of dispense, Approved by, EMI, Loan end date, Consent form | Loan statement | Loan page: terms, outstanding, schedule (sequence, due month, EMI, principal, interest, status, recovered on) | HAVE | — |
| 14 | Adjust "Settle loan out of system" (Comments, amount to be paid) | Record a repayment made outside payroll | "Prepay" N instalments (note) | PARTIAL | PO-10: settle any amount (applied to the last instalments; reference, audited) |
| 15 | Adjust "Recover complete loan" | Recover the whole outstanding in the next payroll | — | MISSING | PO-10: bring every remaining instalment due in the next open month (`LoanRecoveryInput` picks them; exit recovery unchanged) |
| 16 | Adjust "Skip EMI for this month" | Defer one EMI | "Defer" an instalment (adds one at the end) | HAVE | — |
| 17 | Adjust "Restructure loan": New loan amount, New No. of EMI's ("Value should be greater than 0"), Loan end date, "No. of EMI's left", "Total Outstanding Loan amount" | Change the remaining schedule | — | MISSING | PO-10: restructure the outstanding into a new schedule (new version of the remaining instalments; reason; approval by someone other than the requester) |
| 18 | Adjust "Reduce this month's EMI" (`adjustAmount` ≤ EMI) | Partial deferral | — | MISSING | PO-10: partial defer (the unrecovered part becomes a new last instalment) |
| 19 | "IMPORTS" (Loans 130) | Bulk loans | — | MISSING | §25.10 row 21 |
| 20 | Reports tab | Loan reports | No loan datasets | PARTIAL | Matrix 21 §21.2.2 Payroll #32–#34 (AN-26) |

---

## 25.6 Payroll processing

### 25.6.1 Run payroll (salary dashboard) — header and the run dialog (`/app/processes/search/processsalary/action`, `salarydetails_109`; `app-process-salary`, `app-month-selector`, `app-process-payroll`, `app-salary-process-steps-card`)

HROne processes payroll **per employee** through eleven steps on one dashboard (arrears and overtime, salary, verify,
release, publish, reimbursement release and publish), each with counters and a side table; employees can be processed,
unprocessed, held, released and published separately, and steps can be unlocked later. PeopleOS processes **one run
per company and pay period** (calculate → validate → approve → finalize → paid) with payroll inputs inside one
calculation; finalized runs are never reopened.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Month selector "<month> · Change" (`MonthYear/-1`: per month `payrollProcessed`, `timeOfficeProcessed`) | Pick the pay month; see what is processed | Control room: per company the current pay month (cycle-aware, "25th–24th" cycles), run status, headcount, without salary; runs table; "Open payroll run" (company, year, month) | HAVE | — |
| 2 | Stepper of 11 steps with "(Mandatory)" / "(Non mandatory)", check marks and per-step counters | Where the month stands | Run status badge; totals and exception count on the run | PARTIAL | PO-4: run cockpit — the run view as steps (inputs → calculate → verify → approve → finalize / publish → pay) with counters read from the run, its inputs and holds; read model only |
| 3 | "Run payroll" → "Run payroll now" / "Process payroll in background for current month" (`schedulerType`); "Successful! Your request to process in background has been submitted … notified through email and push notification" | Run now or as a background job | "Calculate" runs synchronously from the screen; `CalculatePayrollRun` (queued, unique per run, tenant-bound) exists but the screen does not use it | PARTIAL | PO-4: "Calculate in background" dispatching `CalculatePayrollRun` with a notification on completion (event `payroll.calculated` already exists) |
| 4 | "Select the payroll processes to include in this payroll run" | Choose which steps run | One calculation always includes every input (arrears, OT, variable, claims, loans, encashment, holds …) | HAVE | Nothing to choose: inputs not wanted this month are held, cancelled or deferred at their source |
| 5 | Applicability: "Process payroll as per your data access rights" / "for specific employees" (Add bulk employees) / "for employees organization wise" (Organization, Location) | Process a subset | Run population = every payroll-eligible employee of the company in the period | MISSING | PO-5: "Recalculate selected employees" inside an open run (only their entries replaced; totals and reconciliation refreshed); PO-19 for separate runs per population |
| 6 | "Schedule payroll for … on" (`scheduleDay`, "All-day of the month"), "Policy name" (`schedulerName`), "Process payroll of the Current month" (`monthType`) | Recurring scheduled processing | — | MISSING | PO-4: setting `payroll.auto_calculate_day` (0 = never, default) recalculating open runs on that day; never validates, approves or finalizes |
| 7 | "Download" | Export the dashboard data *(inferred)* | Report library salary register; no export on the run | PARTIAL | PO-4: export of the step tables (CSV, audited) |
| 8 | "Payroll Setup Required — The salary cannot be processed. Please configure the payroll settings first. Configure"; statutory review status of 12 set-ups (`SalaryDashboard/StatutoryDetails`) | Gate processing on reviewed settings | Banner when statutory rules are not VERIFIED; finalize refuses unverified rules; `ComplianceReadiness` | PARTIAL → built (PO-3 display: the readiness panel's "Statutory rules for the month" lists the rules the establishments need that are not VERIFIED (RequiredRules), with the enforcement note; the year-scoped statutory review itself stays setup PY-1) | Setup matrix 13 PY-1 (year-scoped statutory review); PO-3 shows its result on the cockpit |
| 9 | "IMPORTS" menu on the dashboard | Upload payroll inputs | Payroll uploads screen | PARTIAL | §25.10 |
| 10 | "ACTIONS" menu (HR desk payroll actions) | Start an action | Header actions on the control room and the run | PARTIAL | §25.9 |
| 11 | Chat bubble | Vendor support chat | — | N-A | Vendor chrome |

### 25.6.2 Warning card and pre-run checks (`app-warning-card`; `SalaryDashboard/Counter`, `AttendanceCounter`, `SalaryCounter`, `Reminder`, `Salary/Invalid/CTC/Employee/Details`; `app-bulk-edit-pay-components`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "There are some employees whose salaries cannot be processed. However, you can process the salary for the rest of the employees." | Blocked employees do not stop the others | Any blocking exception stops validation of the whole run | PARTIAL | PO-5: "Exclude from this run" (reason) for blocked employees; their pay follows in a later run (PO-19) or as an arrear |
| 2 | "Active employees only" (`activeEmployeesOnly`) | Count only active employees | Readiness counts employed people | MISSING → built (PO-3: "Active employees only" (default on: payroll-eligible and still employed) or everyone in the pay month's run population) | PO-3 toggle (lifecycle employed vs. everyone in the period population) |
| 3 | "Salary unprocessed last month" | Employees left out of last month | — | MISSING → built (PO-3: last month's run population without an entry in its finalized / paid run; "not run" when last month has no finalized payroll) | PO-3: in last month's population without an entry in its finalized run |
| 4 | "Attendance not processed" | Attendance missing for the month | Per-employee exceptions `attendance_missing` / `attendance_incomplete` after calculation | PARTIAL → built (PO-3: days of the pay month up to yesterday (inside joining and exit) without a processed attendance record, through AttendanceOutput::processedDates) | PO-3: count before calculation (days without a processed attendance record) |
| 5 | "Statutory details missing" | No statutory record | `no_pan` readiness only | PARTIAL → built (PO-3: employees with no statutory details row) | PO-3: employees without a statutory detail row |
| 6 | "Salary Structure missing" | No structure | Readiness `no_salary`; exception `no_salary` | HAVE | — |
| 7 | "Non-Compliant Employee List" + Download; alert "N employee salary structures are not compliant. Please review and update them …" | Code-on-Wages wage share per employee | Wage-share guardrail per structure version (advisory) | PARTIAL → built (PO-3: basic + DA share of gross in the structure breakup below `WAGES.min_wage_share` of a VERIFIED rule version; without one the check says "No verified rule" and does not run (the 50% config figure is not used)) | PO-3: employees whose last calculated breakup is below the guardrail (classification config, no statutory value hard-coded) |
| 8 | "Employees Below Minimum Wage" + Download; alert "… wages are below the defined minimum wage" | Minimum wage check | — | MISSING → built (PO-3: monthly wages (basic + DA) below `MIN_WAGE.monthly_minimum` of a VERIFIED rule for the employee's state; no such rule exists yet, so the check reports "No verified rule" (BP-15 brings the data)) | PO-3 count only where a verified `MIN_WAGE` rule exists (BP-15); hidden otherwise |
| 9 | "Bank details missing" | No bank account | Readiness `no_bank`; exception only for bank-transfer employees | HAVE | — |
| 10 | "PAN card details missing" | No PAN | Readiness `no_pan`; exception `no_pan` (higher-rate TDS) | HAVE | — |
| 11 | "PAN is not linked with Aadhar" | Link status | — | MISSING | PO-3 after BP-8 `pan_aadhaar_linked` |
| 12 | "Aadhar number missing" | No Aadhaar | — (Aadhaar reference stored, not counted) | MISSING → built (PO-3 (presence only)) | PO-3 |
| 13 | "UAN missing" | PF member without UAN | — | MISSING → built (PO-3: PF applies (employee choice and the establishment / company profile) and no UAN) | PO-3 (PF applicable and no UAN) |
| 14 | "ESI number missing" | ESI-covered without IP number | — | MISSING → built (PO-3: ESI deducted in the latest calculated payroll and no ESI IP number) | PO-3 (ESI covered in the last run and no IP number) |
| 15 | "ESI greater than 21000" (`wrongESIConfiguration`) | ESI marked for employees above the ceiling | Coverage decided by the ESI rule's ceiling each month (contribution-period continuation is SV-1) | PARTIAL → built (PO-3: ESI marked and the breakup's ESI wages above `ESI.wage_ceiling` of a VERIFIED rule in force (no number in code; "No verified rule" today)) | PO-3 list labelled "above the ESI wage ceiling" with the ceiling read from the rule in force (no number in code) |
| 16 | "Invalid Employee CTC" | Breakup does not match CTC | Structure preview warns on imbalance; finalize refuses stale compensation | PARTIAL → built (PO-3: the structure's breakup (StructureFormulas::preview through CompensationOutput, components-mode structures excluded) differs from the CTC by 1 or more, or cannot be worked out) | PO-3: assignments whose structure breakup does not balance to the CTC (from `StructureFormulas`) |
| 17 | Each count opens its list; "Download" | Act on the list | — | MISSING → built (PO-3: each count opens its list on the control room; "Download CSV" (payroll.view, audited EXPORT, no identifier values, amounts only with compensation.view)) | PO-3 drill lists with CSV (`payroll.view`, audited) |
| 18 | Confirm dialog (`app-confirm-dialog`): "There are: Pending Resignation, Sabbatical, Retired, Terminated, Absconding, Suspended, Resigned" | Warn about employees in special states in the run | Population is a positive list of payroll-eligible lifecycle states; no warning | PARTIAL → built (PO-3: serving notice or pending resignation, suspended, on long leave, exited, last working day in the pay month; "other status" stays WF-08) | PO-3: list of employees in notice period / exited in the period; "other status" after WF-08 |
| 19 | "Map every pay component to a remuneration category to make all salary structures compliant" (`app-bulk-edit-pay-components`: Pay component, Component type, Nature of payment, Remuneration category, "Recommended mapping", "Applying mappings", "New code of wages"; "We recommend re-assigning salary structures for all active employees") | Classify components as wages / exclusions in one screen | Component classification and the guardrail's classification list (config); per-component `part_of_wages` is setup BP-1 | PARTIAL | Setup matrix 08 BP-1 (`part_of_wages`); a bulk mapping grid on Salary components (PO-3 links it) |

### 25.6.3 Steps 1–5: arrears and overtime (`Arrear/Counter/{A,I,O}`, `Overtime/Counter`; side tables in `app-salary-process-steps-sidebar`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Step 1 "Calculate attendance correction arrear" (Non mandatory) | Pay or recover days corrected after their month closed | `attendance_arrears` (pending) paid / recovered by `AttendanceArrearInput` at the gross day rate in the next calculation; settled at finalize | HAVE | — |
| 2 | Step 1 counters "Calculation completed", "Ready to calculate", "Ignored", "Manual arrear import" | Progress of arrears | Attendance arrears list (status pending / settled / void) | PARTIAL | PO-4 counters |
| 3 | Step 1 "Ignored" | Skip an arrear | — | MISSING | PO-6: "Ignore" (status `void`, reason, `payroll.manage`, audited) on pending arrears |
| 4 | Side table columns "Generated arrear days", "Pay arrear (For) month", "Salary month", Status, Action | Arrear rows | Attendance arrears list: employee, date, status before / after, days, status (filter status); the settled period is stored but not shown | PARTIAL | PO-4 step table adds pay-for month and settled run |
| 5 | Step 2 "Calculate increment arrear" (completed / ready / ignored; "Salary revision (On) month") | Pay back-dated revisions | — | MISSING | PO-20 (= BP-10 `RevisionArrearInput`) |
| 6 | Step 3 "Process previous month overtime" | Pay OT approved after its month closed | OT is paid only from the period's own approved minutes | MISSING | PO-20: `OvertimeArrearInput` for approved OT dated in finalized months (rate from the component formula of that month) |
| 7 | Step 4 "OT increment arrear": "OT Increment Amount", "New Rate", "Old Rate", "OT Month" | OT difference after a back-dated revision | — | MISSING | PO-20 |
| 8 | Step 5 "Process overtime" (Processed / Ready to process) | Pay the month's overtime | Approved overtime minutes feed components reading `overtime_minutes` / `overtime_hours`; `overtime_unpaid` exception when no component uses them | HAVE | — |
| 9 | Step 5 columns "Rate", "Hourly rate", "Overtime hours" | OT detail per employee | Line basis carries the formula and minutes | PARTIAL | PO-4 step table |
| 10 | Unlock any step (scheduler jobs "unlock attendance correction arrear / increment arrear / overtime / previous month overtime / increment overtime") | Undo a processed step | Recalculation while the run is open replaces everything | HAVE | After finalize: N-A (§25.6.4 #10) |

### 25.6.4 Step 6 "Process salary" (Mandatory) with "Add variable", "Import variable", "Calculate mid month increment" (`app-add-variable`, `app-mid-month-increment`, `app-payroll-table`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Process salary; counters "Processed", "Ready to process" | Calculate salaries | Calculate / Recalculate (`payroll.calculate`); totals and exceptions on the run | HAVE | Counters with PO-4 |
| 2 | Add variable — form: employee (search), component (`payCode`), specific amount (`variableAmount` / `payAmount`), Comments (`remarks`), "One time variable" | Enter a one-off amount for this month | Payroll adjustment (earning / deduction with a component, open period, approve) or variable pay "For an employee" | HAVE | — |
| 3 | Add variable — "Pay as per: Variable as per salary structure / Formula / Specific amount" (`isFormulaPay`, `formula`) | Pay the structure's variable formula | Specific amounts only | MISSING | PO-6 (as §25.4 #5) |
| 4 | Add variable — many employees in one grid ("Employee(s) selected", filters, chips) | Bulk entry | One adjustment per form | MISSING | PO-6: grid (employees × component, amount or "as per structure"), each row an adjustment approved by someone other than the preparer |
| 5 | "Variable summary": Employee code, name, Component name, Formula, Pay as per, Amount, Actions ("Edit the amount" → New amount; Delete) | Review and correct entered variables | Adjustments list (period, code, employee, status, type, name, amount, note; approve; edit / delete while the period is open) | HAVE | — |
| 6 | Alert "Once you upload salary structure for Nov 2022 and thereafter update salary structure w.e.f Nov 2022, the variable imported would still be paid … update it as 0" | Pitfall of HROne's import model | Adjustments are separate records; a structure change does not interact with them | N-A | Does not arise |
| 7 | "Import variable" | Upload variables | — | MISSING | §25.10 row 13 |
| 8 | Mid-month increment for an **open** month | Part-month revision | Every approved compensation in force in the period is a segment; earnings are prorated per segment | HAVE | — |
| 9 | Mid-month increment for a **processed** month (`app-mid-month-increment`): employees (+ bulk), "month for which mid-month increment needs to be paid" (`arrearDate`), "month with which CTC needs to be compared" (`effectiveRateDate`), days (`arrearDays`, "cannot be greater than the increment month days"); table Arrear month, Revision month, Mid month increment; detail System-calculated / Confirm mid month increment, PF, ESI, LWF, PT gross; Component name, Old rate, New rate, Difference; "Processed mid month increment"; Unprocess | Arrear for part of a closed month with confirmable statutory wages | Closed months cannot take a back-dated revision | MISSING | PO-20: the revision arrear covers part months (days from the effective date); statutory wages of the arrear follow SV-2 profile choices; review before the run, never editing the closed month |
| 10 | "Unprocess salary" per employee; "Please select the reason for your action" (reasons: Inconsistent attendance, Inconsistent salary structure, Missing variable inputs, Inconsistent Investment inputs, Inconsistent TDS, Inconsistent settings, Others); Response table (Employee code, name, Response) | Undo one employee's processed salary | Whole-run recalculation while open; an approved run may return to draft (reason); finalized runs never reopen | PARTIAL | PO-5 inside an open run: "Recalculate" / "Exclude from this run" per employee with a reason from option list `payroll.exclusion_reasons` (seeded with HROne's seven); after finalize N-A (correct by arrear / recovery, D-PO1) |
| 11 | "TDS amount — You can edit the latest TDS amount from here" (`newTDSAmount`) | Change this month's TDS | Statutory override (TDS) for the employee and month, reason, never one's own, never once finalized (`payroll.override`) | HAVE | — |
| 12 | Step table chrome: "Employee(s) selected", "Show" (`rowsToShowInTable`), "Filters Applied", View, Bank Name, Pay Mode, Status, Action, "Salary month", "Reimbursement month" | Per-employee table of the step | Run entries: code, employee, paid days, LOP, gross, deductions, net, status, issues, Breakdown | PARTIAL | PO-4: entries table with pay mode, paying account, hold, status filters, selection for PO-5 actions |
| 13 | Captcha before bulk processing (`app-hrone-captcha`) | Guard against mistakes | Confirmation dialogs, reasons, separation of duties | N-A | PeopleOS protects with reasons and maker-checker, not captchas |

### 25.6.5 Step 7 "Verify salary" (Non mandatory) (`app-verify-salary-sidebar`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Verify salary step ("Ready to verify") | Someone checks the calculated salaries | Validate (no blocking exceptions) and Approve by someone other than the preparer; AI Payroll Auditor findings; `SalaryVariance` builder | HAVE | — |
| 2 | Verify filters: Search Employees, Pay Group, Organization, Status, Type | Narrow the check | Entries table has no filters | PARTIAL | PO-4 (with PO-1 filters) |
| 3 | Per-employee "verified" progress *(inferred from "Ready to verify")* | Track which salaries were checked | Approval is for the whole run | MISSING | PO-4: optional reviewer tick per entry (who, when; audited), shown as "n of N checked"; approval rules unchanged |

### 25.6.6 Step 8 "Release salary" (Mandatory): holds, release, payment (`app-hold-salary-dialog`, `app-payment-on-hold-sidebar`, `Hold/Dashboard`, `Payments/Salary/Dashboard`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Release salary (rights "Release salary", "Transfer salary") | Release the month's pay for transfer | Finalize & generate payslips; Bank file (per paying account, layout version, held employees left out); Mark as paid (`payroll.finalize`) | HAVE | — |
| 2 | Hold dialog: "Salary for N employees will be placed on hold. Please provide a reason for audit and record purposes", "Reason for hold" (pick list with add / delete: "Are you sure you want to delete this salary hold reason?"), "Save & hold" | Hold many salaries with a listed reason | Hold one employee per pay month with a free-text reason | PARTIAL | PO-7: bulk hold from the entries table; reason from option list `payroll.hold_reasons` (Option lists; free text still allowed) |
| 3 | Counters "Hold this month"; chips "Salary on hold", "Hold salary released" | Hold state | Salary holds list (employee, pay month, reason, held by, status, released at, reference) | HAVE | Counters with PO-4 |
| 4 | "Release in future month" (counter) | Hold now, pay in a named later month | Release any time with a note and reference; no planned month | PARTIAL | PO-7: `salary_holds.release_month` (nullable) shown on the cockpit of that month |
| 5 | "Release hold salary" → "Payments On Hold" (search, "VIEW DETAILS FOR", "ADD TO PAYMENT", Next, "Fetching the payments on hold…") | Add released holds to a payment batch | Release a hold; the bank file of a finalized run includes it on the next download | PARTIAL | PO-7: "Released holds" bank file (only released, not yet transferred employees; audited with checksum) |
| 6 | Counter "Settlement" | Employees paid through F&F instead | F&F calculates the final month (`SAL_*` lines) and notes salary already paid | PARTIAL | PO-12: show F&F employees in the release step and refuse paying the same month twice (the settlement already checks finalized runs) |
| 7 | Counters "Released this month", "Ready to release" | Progress | Run status | PARTIAL | PO-4 |
| 8 | "Enter OTP to release salary — We've just shared an OTP on your email and phone number", RESEND OTP IN / RESEND, Cancel / SUBMIT | Second factor before release | Finalize needs `payroll.finalize`; MFA at sign-in | MISSING | SV-21 (setup matrix 13: OTP before salary release), reused by PO-7 for Finalize and Bank file |
| 9 | Paytm payout: "Secured and partnered with Paytm", "By connecting to Paytm, you'll be able to release salaries using paytm account", "By clicking on PAY, the payment will be processed in the background and the action cannot be undone later" | Pay salaries directly through a payout provider | Bank files for upload to the bank | MISSING | External payout provider (SV-21 EXT; owner decision D-PO4) |
| 10 | "Salary transfer status", "Response", columns Bank Name, Pay Mode | Per-employee transfer outcome | Run marked paid as a whole | PARTIAL | PO-7: per-entry payment status (in file / paid outside / held / failed + reference), optional import of the bank's response file |

### 25.6.7 Steps 9–11: publish salary, release and publish reimbursement; payslip distribution (`app-process-salary` dialogs, `app-bulk-payslip-layout`, `app-bulk-email-slip`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Step 9 "Publish salary" (Published / Ready to publish): "Are you sure you want to publish salary slip? On publishing the salary, password protected salary slip would be published to employee. Employee can access it on web and mobile." | Release payslips to employees as a separate step | Payslips are generated and visible at finalize | PARTIAL | PO-7: setting `payroll.payslip.publish` (`on_finalize` default = today / `manual`) with "Publish payslips" (all or selected) recording `published_at`; D-PO2 |
| 2 | "Unpublish salary" (scheduler job) | Withdraw published payslips | Issued payslips stay | N-A | Finalized payroll and issued payslips are immutable (D-PO1) |
| 3 | Step 10 "Release reimbursement" (Released / Ready to release / Total) | Pay reimbursements separately from salary | Claims paid as non-taxable lines in the salary run; the bank file splits reimbursement by its own pay mode / account | PARTIAL | PO-7: "Reimbursement file" (the reimbursement part only) when a separate transfer is wanted |
| 4 | Step 11 "Publish reimbursement" ("Are you sure you want to publish reimbursement slip?"; Published / Ready / Total) | Reimbursement slip to employees | Claim lines on the payslip | PARTIAL | PO-7: reimbursement statement (claims paid, balances) as a payslip section or slip type |
| 5 | Bulk download: "What do you want to download? Pay slip / Tax slip / Reimbursement slip" | Download slips in bulk | Payslips viewed one at a time on screen | MISSING | PO-7 (PDF needs the BP-14 PDF-library decision, D-PO9) |
| 6 | "Do you want to download? Individual file for each employee / Single file for all employees" (`separateFiles`), "File name" (`fileName`), "Last downloads" (File name, Slip type, Status, Created date, download) | Batch output and its log | — | MISSING | PO-7: queued zip / merged PDF with a downloads log (Data export pattern, audited) |
| 7 | Applicability "as per your data access rights / specific employees / organization wise / specific paygroup" (Organization, Location, Add bulk employees, Paygroup) | Who is included | — | MISSING | PO-7 (scope rules applied server-side) |
| 8 | Template (`template`), template language (`templateLanguageId`: English, Hindi, Odia, Marathi, Kannada, Malayalam …), financial year (`financialYearId`) | Payslip layout and language | One payslip layout | MISSING | BP-14 (payslip glossary and language) |
| 9 | "Choose password type: System generated password / User defined password" (new / confirm password, criteria: 6–40 characters, upper, lower, digit, special); note "password … last 4 digits of employee's PAN and month year … of date of joining" | Protect PDFs | — | MISSING | BP-14 / D-PO9; the PAN-and-joining-date pattern is guessable — PeopleOS should prefer a per-employee secret or signed links |
| 10 | "Tax slip" | TDS worksheet slip | TDS ledger and projection; no slip | MISSING | PO-7 after matrix 21 AN-24 `tds_worksheets` |
| 11 | Email payslip (`app-bulk-email-slip`): Slip type, Applicability (incl. specific paygroup), "Share on Personal email / Official email", "Email payslip" | Send slips by e-mail | Notification event `payroll.finalized` (rule-driven, run-level); no per-employee payslip mail | MISSING | PO-7: notification event `payroll.payslip_published` (in-app + mail with a sign-in link; attachments only if D-PO9 allows) |
| 12 | Rights "Publish salary", "Publish reimbursement", "Release reimbursement", "Bulk generation", "Email slip" | Access to each | `payroll.finalize` covers finalize and payment | PARTIAL | PO-7: new key `payroll.publish` (publish, bulk download, e-mail; sync) |

### 25.6.8 Reminders card (`app-reminders-card`; `SalaryDashboard/Reminder`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | "Pending proposed investment declaration" (count, opens the list) | Declarations awaiting payroll | Tax declarations (status filter); no count on the payroll screen | PARTIAL | PO-4 reminders panel (counts in the viewer's scope, each opening the filtered list) |
| 2 | "Pending confirmed investment declaration" | Proofs awaiting verification | TDS investments (proof-status filter) | PARTIAL | PO-4 |
| 3 | "Pending loan approvals" | Loans waiting | Approval Center (per approver); loans list status filter | PARTIAL | PO-4 (organisation-wide count for payroll) |
| 4 | "Pending dispense loan" | Approved loans not paid out | Loans with status approved | PARTIAL | PO-4 |
| 5 | "Pending reimbursement" | Claims waiting | Approval Center; claims status filter | PARTIAL | PO-4 |
| 6 | "Pending FBP approvals" | FBP declarations waiting | — | MISSING | PO-21 |
| 7 | "Release Hold Salary" | Holds to release | Salary holds list (status held) | HAVE | Count with PO-4 |
| 8 | "Encash Leaves" | Encashments to pay | Leave encashments list (status) | HAVE | Count with PO-4 |
| 9 | "Unclaimed reimbursement", "Credit/delete reimbursement's entitlement limit", "Delete claimed reimbursement", "Pay referral bonus", "Recover referral bonus" | Open the flyers of §25.6.9 | — | PARTIAL | §25.6.9 |

### 25.6.9 Reminder flyers (`app-unclaimed-reimbursement-flyer`, reimbursement credit / delete note, `app-reimbursement-delete-flyer`, `app-referral-bonus`, `app-leave-encashment-flyer`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Unclaimed reimbursement: filters Search, Pay group, Pay component, Organization, Location, Status, Type, "Apply Filter"; table Employee name, Employee code, Pay component; Select; "Process" | Act on balances never claimed (pay out or lapse) *(inferred)* | Ledger balance per component and FY; kind `lapse` defined but never posted; reminders to claim (BP-9) | MISSING | PO-9: year-end treatment setting `payroll.claims.year_end` (`keep` default = today / `lapse` / `pay_taxable`) applied by a reviewed batch; pay-out through a taxable payroll input; D-PO3 |
| 2 | "Credit / delete reimbursement" note — "If you click Credit: All claim-based reimbursement components will be credited for the selected month for all employees whose claim has not yet been created" | Credit the month's entitlement | The month's pay-on-claim amount is posted to the ledger automatically when the run is finalized | HAVE | — |
| 3 | "If you click Delete: All credited balances … will be removed for employees whose salary is not yet processed, and whose reimbursement request for this month is still pending"; "Delete reimbursement for selected employees / for selected OU" (Location, Organization, Add bulk employees, component, DELETE REIMBURSEMENT) | Remove credited balances | Ledger entries are never deleted | N-A | Ledger rows are audit records; the equivalent is a reversing `adjustment` entry with a reason (PO-9, D-PO8) |
| 4 | "Delete claimed reimbursement" (`app-reimbursement-delete-flyer`): "Only unprocessed reimbursements are visible"; filters Search, Pay group, Pay component, Organization, Location, Status, Type, bulk employees; columns Employee Code, Employee Name, Pay Component Name, Requested Date, Requested By, Requested Mode, Approved Amount, Submitted Bill Amount, Payment Status; Delete | Remove unpaid claims in bulk | Cancel one unpaid claim at a time (reason, audited); never deleted | PARTIAL | PO-9 bulk cancel (§25.3.1 #4); "delete" becomes cancel (D-PO8) |
| 5 | Referral bonus (`app-referral-bonus`): Candidate Name, Position Name, Date of Joining, Referral Amount, Payment Status (Not paid / Paid), Payment Date, Amount to be recovered, Last Working Date; filters position, payment status, joining date range; Pay / Paid offline / Ignore / Retrieve payment / Recover / Recovered offline; "variable component"; "Payment retrieval is final and cannot be undone" | Pay and recover employee referral bonuses | — | N-A | Recruitment (RMS boundary); if the RMS sends approved referral bonuses, a payroll input source can pay them like variable pay (D-PO10) |
| 6 | Encash leaves flyer (`app-leave-encashment-flyer`): tabs Unprocessed / Processed; Employee Name, Employee Code, Request Date, Leave Type; Cancel | Encashment requests to pay this month | Leave encashments list (approved / paid; cancel while unpaid) | HAVE | — |

### 25.6.10 Payroll scheduler (`app-schedular-card`; `scheduler/SchedulerType`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Scheduler cards: Now / Recurring, Policy name, Applicability (data access / specific employees / organisation wise), "Next scheduled date", Edit, SHOW MORE, "NO SCHEDULAR CARD" | Recurring background processing | — | MISSING | PO-4 (`payroll.auto_calculate_day`, §25.6.1 #6) |
| 2 | "View scheduled jobs" | Job history | Queue and audit (`PayrollCalculated` with replaced results); no screen | PARTIAL | PO-4: run activity panel (calculations, by, when, employees, exceptions) from audit events |
| 3 | Job types "publish salary", "publish reimbursement" | Publish on schedule | — | MISSING | PO-7 (publish on a date when `manual`) |
| 4 | Job types "unpublish …", "unlock salary / overtime / arrears / salary payment / reimbursement payment" | Undo after release | Recalculate / reopen only before finalize | N-A | Finalized payroll is never reopened (D-PO1) |

### 25.6.11 Rights of HR Desk → Action → Payroll → Salary dashboard (14)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Salary dashboard | Open the dashboard | `payroll.view` (Control room, runs) | HAVE | — |
| 2 | Calculate attendance correction / increment arrear | Steps 1–2 | Attendance arrears are part of Calculate (`payroll.calculate`); increment arrears missing | PARTIAL | PO-20 |
| 3 | Process previous month overtime / process overtime | Steps 3–5 | OT part of Calculate; previous-month OT missing | PARTIAL | PO-20 |
| 4 | Process salary | Step 6 | `payroll.calculate` | HAVE | — |
| 5 | Add variable | Enter variables | `payroll.manage` (adjustments) | HAVE | — |
| 6 | Verify salary | Step 7 | `payroll.approve` (approve ≠ preparer) | HAVE | — |
| 7 | Release salary | Step 8 | `payroll.finalize` | HAVE | — |
| 8 | Transfer salary | Pay out | `payroll.finalize` (bank file, mark paid) | HAVE | — |
| 9 | Publish salary | Step 9 | — (publish = finalize) | PARTIAL | PO-7 `payroll.publish` |
| 10 | Release reimbursement | Step 10 | Part of finalize / bank file | PARTIAL | PO-7 |
| 11 | Publish reimbursement | Step 11 | — | MISSING | PO-7 |
| 12 | Payroll scheduler | Schedule jobs | — | MISSING | PO-4 (setting under `settings.update`) |
| 13 | Bulk generation | Bulk slips | — | MISSING | PO-7 |
| 14 | Email slip | E-mail slips | — | MISSING | PO-7 |

### 25.6.12 Generate challan (`/app/processes/search/generatechallan/action`, `challan_124`; global action "Generate challan"; rights Challan dashboard, Generate pf / esi / pt / lwf / tax challan; `app-generate-challan` → `app-generate-challan-group-card`, `app-esi-challan-group-card`, `app-pt-group-card`, `app-lwf-group-card`, `app-tax-challan-group-card`, `app-return-challan-group-card` and their sidebars)

PeopleOS builds statutory outputs from finalized payroll in the Compliance domain: monthly EPF ECR, ESI, PT and LWF
returns and the quarterly TDS statement, each generate → validate & reconcile → approve (never the generator) →
export (versioned layout) → record submission → record acknowledgement → reconcile filing; and compliance report packs
(PF / ESI challan working sheets, registers). What is missing is the **challan as a payment record** (setup matrix 08
§10.1 #17, PY-21, BP-15).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs Provident fund / Employee state insurance / Labour welfare fund / Professional tax / Income tax; "Financial year" select; month buttons April … March; "No provident fund challan" | One FY × month grid per statute | Separate return lists per statute (EPF, ESI, PT, LWF, TDS) with period; Compliance reports by month / quarter / half / year | PARTIAL | PO-13: "Challans" page — FY grid per statute (month cells: not generated / generated / paid / filed) over the existing returns plus the new challan register |
| 2 | "Generate" per month and group | Build the month's challan from processed payroll | "Generate" return from finalized payroll (`compliance.returns.generate`) | HAVE | — |
| 3 | "This challan needs to be regenerated!" | Payroll changed after generation | Returns move to `reconciliation_required` / are revised when payroll differs | HAVE | — |
| 4 | "N sub-branches not mapped" + `app-missed-subbranch-dialog` (Sub-branch name, State name, Action, "Do it later", "Map Sub-branch") | Locations not mapped to a statutory unit | `statutory_state_missing` exception; establishment assignment; no gap list | PARTIAL | Setup matrix 08 §10.1 #1 (statutory coverage panel, BP-15); PO-13 shows it on the grid |
| 5 | "Download format: Challan / Excel" | File for the portal or a spreadsheet | "Export file" in the versioned layout (`statutory_export_layouts`); xlsx working sheets in compliance packs | HAVE | — |
| 6 | ESI "Download format: ESI / ESI with Sub unit code" | ESI file variant with sub-unit code | ESI layout `ESI_MC`; no sub-unit code | PARTIAL | Setup matrix 08 §10.1 #4 (registration `esic_sub_unit`, layout variant; BP-11) |
| 7 | PF card (`app-provident-fund-sidebar`): Total no. of subscribers, Total PF gross, Total pension wages, Total EDLI wages, Employee's A/C No. 1, Employer's A/C 1, 2, 10, 21, 22 | Contribution summary by account | EPF return run totals (members, wages, EPF / EPS / EDLI, admin charges) and the PF challan working sheet | HAVE | — |
| 8 | PF payment: Submitted date (`submittedDate`), Submitted by, Payment mode (`paymentType`), Acknowledgement no (`acknowledgementNo`), Upload | Record the challan payment | "Record submission" (reference) and "Record acknowledgement" (reference) on the return; no payment mode, amount paid, date paid or receipt | PARTIAL | PO-13 (= BP-15 PY-21): `statutory_challans` (statute, establishment / registration, period, challan no. / TRRN / CRN, paid on, mode, bank, amount, interest, penalty, receipt file, recorded by; `compliance.returns.file`, never the approver) |
| 9 | ESI card (`app-esi-sidebar`): Total no. of subscribers, Total employee's / employer's ESI contribution, Total monthly wages | ESI summary | ESI return totals; ESI challan working sheet | HAVE | — |
| 10 | ESI payment: challan date (`challanDate`), Acknowledgement no, Payment mode, Upload, Submitted date / by | Record payment | As row 8 | PARTIAL | PO-13 |
| 11 | PT card (`app-pt-sidebar`): Total no. of subscribers, Total gross pay, Total professional tax; challan date, payment mode, acknowledgement no, upload | PT summary and payment | PT return (state, employees, wages, PT); payment as row 8 | PARTIAL | PO-13 |
| 12 | LWF card: generate / download; its "DETAILS" opens the LWF settings (`app-lwf-sidebar`: applicable, basis Rate / Earnings, on arrear, separately for arrear, on settlement, Haryana mid-month leavers, provision in CTC) | LWF challan and settings | LWF return (`LwfReturns`); settings rated in setup matrix 13 PY-19 | PARTIAL | Return HAVE; settings SV-2; payment PO-13 |
| 13 | Income tax step 1 "Select employees" (`app-tax-challan-step-one`): filters (search, organization, location, bulk); columns Employee code, Employee name, PAN no., Salary processed date, Gross salary, Income tax, Surcharge, Ecess, Total tax, Total tax to be deposited, Ecess / Surcharge / TDS to be deposited, Balanced taxable income, Gross salary to be deposited | Which employees' TDS goes into this challan | TDS per employee in finalized entries; the quarterly statement takes challans {BSR code, deposit date, challan serial, amount} and checks deposited ≥ deducted | PARTIAL | PO-13: TDS challan with an allocation per employee read from finalized payroll (deducted, already allocated, to allocate) |
| 14 | "Edit Income Tax" (per employee, at challan time) | Change the TDS being deposited | — | N-A | Finalized TDS is never edited; a difference is corrected by a TDS override in an open month; interest and penalty are recorded on the challan (D-PO7) |
| 15 | Step 2 "Challan details" (`app-tax-challan-step-two`): Challan alias (`challanName`), Nature of payment (`natureOfPayment`), Challan number (`challanNumber`), Payment mode (`paymentType`), Challan date (`challanDate`), Bank (`bank`), Branch (`branch`), BSR Code (`basicStatisticalReturnsCode`), Interest paid (`interestPaid`), Penalty paid (`penaltyPaid`); Total income tax / surcharge / ecess / amount | The TDS challan record | Typed at statement generation (BSR, date, serial, amount only) | PARTIAL | PO-13 (`statutory_challans` kind tds; the quarterly statement picks challans from it) |
| 16 | View tax challan (`app-view-tax-challan`): every field above + Submitted Date / by, Acknowledgement No, Upload, document viewer | Challan view | — | PARTIAL | PO-13 |
| 17 | Tax group card: Total employees, Total TDS deducted, TDS to be deposited, TDS deducted, "Employee with generated challans" | Deducted vs deposited per month | `tax_deposited` vs `tax_deducted` checked per quarter | PARTIAL | PO-13 (monthly view) |
| 18 | 24Q card (`app-return-challan-group-card`, `app-return-sidebar`): "Your request for 24q is under process", Group name, No. of employees, Total tax, Amount, Submitted date / by, Acknowledgement no (`acknowledgmentNumber`), Upload | Quarterly TDS return and its filing | TDS quarterly statement (Form 138 under the 2025 Act; 24Q before): generate, validate, approve, export, record submission / acknowledgement | HAVE | — |
| 19 | "Merge pension wages with PF wages" (`app-pension-edli-merge-flyer`: employee table with Company, Business unit, Department, Sub department, Designation, Date of joining; Process / Cancel) | Make pension wages equal PF wages for chosen employees *(inferred: higher-pension members)* | EPS wages capped by the EPF rule | MISSING | BP-11 employee PF option (EPS on actual wages) only where the EPF rule version provides it (verified data) |
| 20 | Challan groups (several sub-branches on one PF / ESI / PT / LWF / TDS challan) | Group establishments per challan | One return per establishment (EPF) or entity (TDS) | PARTIAL | Setup matrix 13 PY-29 … PY-32 (SV-4) |
| 21 | Rights Challan dashboard; Generate pf / esi / pt / lwf / tax challan | Access | `compliance.returns.view / generate / approve / export / file` (separation of duties) | HAVE | — |

### 25.6.13 Salary YTD — HR view (`/app/processes/search/salaryytd/search`, `salaryytd_178`; `app-salary-ytd-process-search`, `-card`, `-detail`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Employee cards, "Financial Year" filter, Apply, CLEAR ALL, SHOW MORE | Pick an employee and FY | Report template "Year-to-date salary summary" (`ytd_salary_summary`: gross, deductions, net, TDS per employee) | PARTIAL | PO-15: HR "Salary YTD" page = the MS-10 page with an employee picker (`payroll.view`, scope), through `PaidPayReader::yearToDate` (finalized / paid runs only) |
| 2 | Detail tabs per component and statute (Salary YTD, Provident Fund, VPF, ESI, PT, LWF, Overtime, Bonus; reimbursement totals; "SALARY NOT PROCESSED" / "REIMBURSEMENT NOT PROCESSED") | Year-to-date with configuration and calculation per component | — | MISSING | Rated field by field in matrix 22 §22.7.1 #3–#12 (MS-10); PO-15 reuses them |
| 3 | Password checker (`app-password-checker`) on the detail | Re-authenticate before pay data | HR access is permission-based | N-A | Self-service only (matrix 22 MS-9) |
| 4 | Reports tab | YTD reports | YTD salary register HAVE; component-wise needs a pivot | PARTIAL | Matrix 21 §21.2.2 Payroll #53, #63 |
| 5 | Right "View" | Read access | `payroll.view` | HAVE | — |

### 25.6.14 Leave encashment — HR bulk encashment (`/app/processes/search/leaveencashment/search`, `leaveencashment_115`; global action "Encash leaves", right "Encash leave dashboard"; `app-leave-encashment-process-search`, `app-leave-encashment-card`, `app-leave-encashment` → `-step1`, `-step2`, `-review`, `app-leave-encashment-view`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Batch cards: Leave type, Leave encashment month, No of employees, "Leave encash by", "Leave encashment date", Pay month; SHOW MORE | Encashment batches HR has run | Encashments come only from employee requests (`LeaveAdjustments::requestEncashment`), one by one | MISSING | PO-8: `leave_encashment_batches` (leave type, pay month, mode, days, applicability, by, at) |
| 2 | Filters Encashment date, Encashed by, Pay month | Slice batches | — | MISSING | PO-8 |
| 3 | Step "Leave type": Leave type (`leaveType`), Pay month (`payMonth`), "Leave Encash: As per policy / Specify" (`leaveEncash`), "Specify Value" (`specifyValue`) | Encash a leave type for many employees, by policy or a set number of days | — | MISSING | PO-8: days = the leave type's encashable balance under its encashment rule (TO-7) or a specified number (never above the balance) |
| 4 | Step "Applicability": Search Employees, Organization, Location, Add bulk employees | Who is included | — | MISSING | PO-8 (scope-checked) |
| 5 | Step "Review": Leave Type, Leave encash, Applicability, Selected employee, Specify; Process; "Please hold on…" | Confirm and run | — | MISSING | PO-8: creates approved `leave_encashments` (source = batch, approver = a second person when `payroll.encashment.batch_approval` is on, default on) and posts nothing until paid |
| 6 | View batch: "Unprocess" ("Do you want to unprocess? Yes / No"; All employees / Select employee) | Undo a batch | — | MISSING | PO-8: cancel the batch's encashments that no finalized run has paid; paid ones stay (N-A to undo) |
| 7 | Payment of encashments in the pay month | Pay through payroll | `LeaveEncashmentInput` (taxable `LEAVE_ENC` at `payroll.encashment_basis` / divisor; `encashment_no_rate` exception) | HAVE | — |
| 8 | Employee encashment requests and cancellations (Time Office registers) | Request-driven encashment | Leave encashments (approve / reject / cancel; Approval Center) | HAVE | Matrix 20 §20.1.18 |
| 9 | Reports tab | Encashment reports | `leave_encashments` dataset (days, decision); amount / liability partial | PARTIAL | Matrix 21 §21.2.2 Payroll #30–#31 |

---

## 25.7 Full and final settlement

PeopleOS settles on the exit case (Exit cases → Settlement tab): Start settlement, Calculate (final month salary
through the payroll calculator as `SAL_*` lines, leave encashment, gratuity from the GRATUITY rule, notice shortfall
recovery, asset recovery from clearances, settlement sources for loans and expenses), Add / remove manual lines,
Breakdown (lines with basis, gratuity determination, inputs), Approve (freezes lines, posts the encashment to the leave
ledger), Mark paid (reference; sources settle). Permission `exit.settle`. There is no settlement register across exits,
no slip and no acceptance.

### 25.7.1 Settlement register (`/app/processes/search/settlement/search`, `settlement_117`; rights View, Relieving details, Attendance review, Salary review, Edit settlement; `app-settlement-search`, `app-settlement-filter`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Cards per separating employee: "Settlement type :" (Primary), status (Not initiated / initiated / completed …) | Every settlement to do or done | Settlement on each exit case; report templates `fnf_pending`, `fnf_register` | PARTIAL | PO-12: "Settlements" register over `final_settlements` and open exit cases without one (status, net, LWD, paid) — no new data |
| 2 | Filters Status, Date of relieving, Settlement type, Payment status, Settlement month, Request date, Approved date | Slice | — | MISSING | PO-12 |
| 3 | "Settlement type" Primary (and further types) | A first settlement and later ones | One settlement per exit case | MISSING | PO-12: supplementary settlement (new settlement after the primary is paid, for late items: arrears, bonus, reimbursements), the safe way to change a paid settlement |
| 4 | Bulk pay: "In order to do bulk settlement payment, apply status & payment status filter as Completed & Unpaid", "Employees selected", Pay | Pay many settlements | "Mark paid" one at a time | MISSING | PO-12 bulk "Pay" over approved, unpaid settlements |
| 5 | Pay dialog: "Select payment mode: Cash / Bank / Cheque", Comments (`paidRemarks`), Bank (`bankCode`), Cheque number (`chequeNumber`), Cheque date (`chequeDate`), "Pay Settlement" | Record how it was paid | Payment reference only | PARTIAL | PO-12: mode, paying account (BP-13 accounts), cheque number / date, comments; a settlement bank file in the account's layout (`BankFile` source = settlements) |
| 6 | "Imports ▾" (Settlement import) | Upload settlements | — | MISSING | §25.10 row 11 |
| 7 | Reports tab | Settlement reports | `fnf_register` | HAVE | Matrix 21 §21.2.2 Payroll #58 |
| 8 | Rights Relieving details / Attendance review / Salary review / Edit settlement | Split access by step | `exit.settle` (calculate, approve, pay) | PARTIAL | PO-12 keeps one permission; independent approval by setting (D-PO13) |

### 25.7.2 Initiate settlement (`app-settlement-card-flyer` → `app-settlement-card`: steps 1 Review relieving details, 2 Review employee's attendance, 3 Review employee's salary; `app-review-emp-salary` stepper; `app-res-*`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Header: View Profile, Date of joining, Date of resignation, Expected last working day, Date of relieving, Resignation reason by employee / by approver, Employee other status (+ remarks), "Settlement separation reason"; desktop-only notice | Facts of the exit | Exit case: type, resignation date, notice, last working day, reason, status; "other status" not modelled | HAVE | Other status = WF-08 (matrix 23) |
| 2 | "Write Notes": Internal Notes / External Notes ("Max 500 characters…") | Notes on the settlement | Settlement `notes` (approval note) | PARTIAL | PO-12: internal and external remarks (external printed on the slip) |
| 3 | Step 1 "Review relieving details" | Relieving date, notice recovery days, holds | Exit case | PARTIAL | Matrix 23 §23.6.1 #14 |
| 4 | Step 2 "Review employee's attendance": stepper Pending Request, Process Attendance, Summary (`app-review-emp-attendance`); flyer "Date", "No. of punches" | Close attendance before settling | F&F salary uses processed attendance (`attendance_missing` / `incomplete` noted); pending time-office requests not checked | PARTIAL | PO-12: attendance step listing open time-office requests and unprocessed days up to the LWD |
| 5 | Salary step "Open request" (`app-res-pending-request`): "You can not proceed further until all pending request are closed. You can do the same from insights." | Gate on open requests | — | MISSING | PO-12: the employee's pending requests (leave, attendance, claims, loans, variable pay, expenses, encashments) listed; approval refused while any is pending when `exit.settlement.block_on_pending` is on (default off = today) |
| 6 | Salary step "Investment" (`app-res-investment`: declaration review / form) | Final declaration for the leaver's TDS | The final month's TDS uses the declaration as monthly payroll does | PARTIAL | Setup matrix 13 PY-22 (`payroll.tax.leavers_use_verified_proofs`); PO-12 links the declaration |
| 7 | Salary step "Variable" (`app-res-variable`: component, Pay as per Formula / Specific amount, "Variable as per salary structure", Amount, Comments, "Pay Variable") | Pay a final variable | Approved variable pay for the month flows into the `SAL_*` lines; manual "Add line" | HAVE | "As per structure" with PO-6 |
| 8 | Salary step "Loan" (`app-res-loan`: review, loan detail) | Recover open loans | `LoanSettlementSource` recovers the outstanding and closes the loan | HAVE | — |
| 9 | Salary step "Arrear" (`app-res-arrear`): select months to process increment arrear / overtime arrear / attendance correction arrear; "Calculate …"; process / unlock; "Pending Reviews" | Settle arrears at exit | Attendance arrears flow into the final salary through the inputs; increment and OT arrears missing | PARTIAL | PO-20 |
| 10 | Salary step "Reimbursement Details" (`app-res-reimbursement`): Unpaid reimbursement request, "Add Payable Amount", Submitted bill amount, Upload files (≤ 500 kb; pdf, doc, docx, txt, xls, xlsx, jpeg, jpg, png, bmp), Add comments, Payable amount, Date, Time, "Add reimbursement amount", Comments, Attachment, Paid till date, Bill already submitted, Total credits, Total claimed, Total available for claim, Total submitted bill amount; REVIEW / DETAILS | Settle claims and remaining reimbursement balance | Approved unpaid claims paid in the final salary; remaining balance not considered | PARTIAL | PO-12 (with PO-9): balance at exit shown; pay against bills entered here (claim created and approved by the settler's counterpart) or lapse per `payroll.claims.year_end` |
| 11 | Salary step "Leave encashment" (`app-res-leave-encashment`): Encashable leave (A), Encashed leave (B), Encashment rate (C), Actual leave encashment amount (E = B × C), Payable amount, Note, Disclaimer | Encash leave at exit | `ENCASH_<type>` lines: available days × per-day at `exit.encashment_basis` | HAVE | — |
| 12 | Leave encashment exemption: Average salary per day (D = last 10 months' salary / 10 / 30), Exemption limit as per govt. (F), Average salary of last 10 months (G), H = D × B, "Exempted amount Minimum of E, F, G & H" | Tax-exempt part of leave encashment at retirement / resignation (s.10(10AA)) | — (encashment fully taxable) | MISSING | PO-12 with BP-10: exemption limit, averaging months and divisor as TDS rule parameters (new rule version, owner-verified, D-PO6); nothing hard-coded |
| 13 | "Edit Leave" (`app-edit-leave-flyer`: Type of leaves, Add Leave / Subtract Leave, Leave, Remarks) | Correct the balance before encashing | Leave adjustments on the leave ledger (separate screen) | PARTIAL | PO-12 inline adjustment (through `LeaveAdjustments`, reason) |
| 14 | Salary step "Gratuity" (`app-res-gratuity`): Gratuity limit, eligibility criteria, duration round off, Leaving reason, Gratuity wages, Joining date, work duration, Gratuity amount, Gratuity payable amount, death-in-service note | Gratuity computation | `GratuityEntitlements` from the GRATUITY rule (service, years counted, wages, cap, coverage, exit type); Breakdown shows it | HAVE | — |
| 15 | Salary step "Recovery" — Review Clearance: Clearance pay component, "Clearance recovery as per process", "Final amount for clearance recovery", Comment (≤ 500), Upload files | Recover clearance dues | `ASSET_RECOVERY` from clearance recoverable amounts; manual lines | PARTIAL | PO-12: override with comment and attachment |
| 16 | Salary step "Recovery" — Review Notice pay recovery: Notice Pay component (`payCode`, "Notice pay / recovery"), notice days (`noticeDays`), payable amount, "Total notice amount", comment, attachments | Notice shortfall recovery or notice pay | `NOTICE_RECOVERY` = shortfall days × gross / 30 when `exit.notice_recovery` is on; notice pay to the employee by a manual line | PARTIAL | PO-12: notice days override (reason), notice pay payable line; rule from the exit policy (WO-11) |
| 17 | Salary step "Process salary" (`app-res-salary`: Process salary, Calculate overtime, errors, process / unlock) | Final month salary | Calculate (final month through `PayrollCalculator`; already-paid check) | HAVE | — |
| 18 | Summary (`app-res-summary`): "Settlement slips", "Please view and download settlement slips", "Download all" | Slips at the end | Breakdown modal | PARTIAL | PO-12 slip (§25.7.3 #6) |

### 25.7.3 Settlement summary, slips and payment (`app-settlement-summary-view`, `-flyer`, `app-send-settlement-slip`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Settlement details table: Primary / NA, Type, Settlement month, Amount, "Settltment inititated on", "Settlement accepted on", Action, View | Settlement history of the employee | One settlement: status, earnings, deductions, net, calculated at, approved by, paid at, reference | PARTIAL | PO-12 (type, settlement month, accepted on) |
| 2 | "Edit settlement" before completion; edit grid (`employeeVariableSalary`: Pay component, amount, remarks) | Change components | Recalculate, add / remove manual lines while not approved | HAVE | — |
| 3 | "Edit settlement" after payment: "The payments listed have been already processed for the selected month … editing these records will reverse the entire monthly settlement process" | Reopen a paid settlement | An approved settlement cannot be recalculated; a paid one is final | N-A | Immutability; the equivalent is a supplementary settlement (§25.7.1 #3, D-PO1) |
| 4 | Warning "There are additional perquisite inputs also there for the settlement month that may lead to additional taxable income …" | Tax inputs affecting the final month | Perquisite and outside-income entries count in TDS; no warning on the settlement | PARTIAL | PO-12 lists the final month's tax inputs on the breakdown |
| 5 | "Post settlement payment" / "Pay settlement": payment mode Cash / Bank / Cheque, Comments, Bank, Cheque number, Cheque date | Pay | Mark paid (reference) | PARTIAL | PO-12 (as §25.7.1 #5) |
| 6 | Send settlement slip (`app-send-settlement-slip`): "By which mean do you want to share employee settlement slips? Email / Digital acceptance", Personal email (`personalMailId`), CC (`ccMailId`, "Settlement slips will be sent to this mail as well"), Subject (`emailSubject`), Body (`emailBody`, rich text, "settlement slip mailer"), View | Send the slip | — | MISSING | PO-12: settlement slip as letter type `settlement_slip` (letter studio template with settlement variables), issued to the employee's documents and mailed (setting `exit.settlement_slip_share`, BP-10) |
| 7 | "Digital acceptance"; "Settlement accepted on" | Employee accepts the settlement | — | MISSING | PO-12: acceptance = letter acknowledgement (matrix 20 RQ-10 / WF-01 `requires_acknowledgement`) |
| 8 | Settlement slips: View, "Download all", "Sit tight; we're fetching slips!" | Download slips | — | MISSING | PO-12 (PDF with BP-14 decision) |
| 9 | Header facts and remarks (DOJ, DOR, expected LWD, DOL, reasons, other status, settlement type, External / Internal Remarks), View profile | Context | Exit case | PARTIAL | As §25.7.2 #1–#2 |

### 25.7.4 Settlement requests (`/app/processes/search/settlement/history`, `settlementhistory_276`; Search node "Settlement history")

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Settlement request cards with workflow (Inbox insights "Initiate settlement", "Settlement") | Settlements awaiting approval | Approve on the exit case (`exit.settle`); not in the Approval Center; approver may be the calculator | PARTIAL | PO-12: Approval Center source `final_settlement`; setting `exit.settlement.independent_approval` (default off = today; D-PO13) |
| 2 | Same filters, bulk-pay note and imports as §25.7.1 | — | As §25.7.1 | PARTIAL | PO-12 |
| 3 | Right "View" | Read access | `exit.view` | HAVE | — |

---

## 25.8 Payroll letters — Appointment letters (`/app/processes/search/appointmentletter/search`, `appointmentletter_272`; rights View letter, Generate letter, Unpublish) and Salary revision letters (`/salaryrevisionletter/search`, `salaryrevisionletter_709`; right View letter) — `app-salary-revision-letter`, `app-letter-generation-detail`

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Register mechanics: filters Status, Letter Template ("Choose Letter Template"), search; cards "Generated by :", "Generated on :"; bulk download note "In order to download letter in bulk, apply a status filter as published"; generation detail (template, signatories 1–4, Publish, Revoke, Re-generate & Publish, download, e-mail with CC / BCC, acknowledgement details, digital signature, approvers) | Letter register | Letters (type filter; generate; approve / reject / issue / download) | PARTIAL | Rated in matrix 23 §23.7.1 #1–#18 (WO-13) |
| 2 | Appointment letter produced after the salary structure is assigned (setting `isGenerateAppointmentLetter`; Inbox insight "Appointment letter") | Letter queued on assignment | Letter type `appointment`; generated by hand with the salary annexure | PARTIAL | PO-16 (= setup BP-16: `letters.auto.appointment_employment_types` on the first approved compensation) |
| 3 | Salary revision letter produced after a revision (setting `isGenerateLetterAfterSalaryRevision`; import flag "assign task to generate salary revision letter"; Inbox insight "Salary revision letter") | Letter queued on revision | Letter type `increment`; generated by hand | PARTIAL | PO-16 (= BP-16 `letters.auto.revision`, PY-28) |
| 4 | Card "Salary revision date :" | Ties the letter to the revision | Letters do not record the compensation change they came from | MISSING | PO-16: letter `source` = compensation change; date and CTC variables from it |
| 5 | Salary annexure in the letter | CTC breakup in the letter | `{{ salary.annexure }}` | HAVE | Per-structure annexure template: setup BP-12 |
| 6 | Generation warnings "This employee's wages are currently below the defined minimum wage …", "Exclusions are greater than wages …" | Compliance check on salary letters | — on letters | PARTIAL | Matrix 23 §23.7.1 #16 (WO-13) |
| 7 | Rights View letter / Generate letter / Unpublish | Access | `letter.view`, `letter.issue`, `letter.manage`; no revoke | PARTIAL | Revoke = WO-13 |
| 8 | Reports tab columns Employee code, Full name, Employment status, Company, Business unit, Department, Designation, Branch, Sub branch, Letter process, Letter type, Letter template, Letter status, Assigned to, Assigned on, Generated by, Generated on | Letter report | `letters` dataset | HAVE | "Assigned to" needs the WO-13 generation queue |

---

## 25.9 HR Desk actions — Action → Payroll [11] and the payroll global actions

### 25.9.1 Index (not counted; each action is rated in the section named)

| # | Action node (rights) / global action | Section | Overall |
|---|---|---|---|
| 1 | Salary dashboard (14 rights) / "Run payroll (salary dashboard)" | §25.6.1–25.6.11 | PARTIAL |
| 2 | Assign salary structure (Request on behalf, Update employee perquisites, View employee perquisites) / "Assign salary structure" | §25.2.2 | PARTIAL |
| 3 | Proposed investment declaration (Request on behalf) / "Propose investment request" | §25.3.2 #3 | HAVE |
| 4 | Confirmed investment declaration (Request on behalf) / "Confirmed investment" | §25.3.3 #3 | PARTIAL |
| 5 | Loan (Request on behalf) / "Loan request" | §25.5 #3 | HAVE |
| 6 | Reimbursement (Apply reimbursement) / "Reimbursement request" | §25.3.1 #3 | MISSING |
| 7 | Variable (Request on behalf) / "Variable pay request" | §25.4 #3 | HAVE |
| 8 | Encash leaves (Encash leave dashboard) / "Encash leaves" | §25.6.14 | MISSING |
| 9 | Generate challan (Challan dashboard, Generate pf / lwf / pt / esi / tax challan) / "Generate challan" | §25.6.12 | PARTIAL |
| 10 | Increment calculator (Create) / "Increment calculator" | §25.9.2 | PARTIAL |
| 11 | Process bonus (Bonus dashboard, Process bonus) / "Process bonus" | §25.9.3 | MISSING |

### 25.9.2 Increment calculator (`/app/payroll/incrementcalculator`, `incrementcalculator_655`; `app-increment-calculator` → `app-increment-step-one…four`, `app-increment-view-flyer`, `app-clone-flyer`; API `IncrementCalculator/Policy/Search`)

PeopleOS plans increments in compensation cycles (name, company, type annual increment / promotion / market
adjustment, effective from, budget, performance cycle with a rating → % matrix; populate, adjust lines, submit →
review → approve → execute into compensation changes; budgets refuse overspend at approval).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | List: "Create new", "Search by name", Filter (multiple), SEARCH; cards with "Increments", "Average increment :", "Total Budget", "Total Increment", "groups", Show more | Saved increment plans | Compensation cycles list (code, name, company, type, effective from, employees, status) | PARTIAL | PO-17: average %, total increase and budget on the list |
| 2 | Step 1 Details: Policy name (`incrementCalculatorPolicyName`); employees by Search, Organization, Location, Status, Type, "Add bulk employees"; "Note: Increment planning can be done for 500 employees" | Who is in the plan | Cycle for a company; populate adds its employees | PARTIAL | PO-17: population by organisation filters and pasted codes |
| 3 | Step 2 Budget: Total budget (`incrementBudgetAmount`) | Budget the plan | Compensation budget linked to the cycle (approved; enforced at approval) | HAVE | — |
| 4 | "How do you want to do increment? Performance cycle / Adhoc" (`incrementType`), Performance cycle (`performanceCycleId`), Review cycle (`reviewCyclePeriod`) | Rating-driven or ad hoc | Cycle with a performance cycle (finalized ratings) or without | HAVE | — |
| 5 | "Do you want to prorate increment as per tenure?" (`isProrateOnTenure`) | Smaller increments for short service | — | MISSING | PO-17: prorate by months of service in the review period (default off) |
| 6 | Step 3 "On what basis you want to do increment? Percentage / Amount" (`incrementPolicyBasedOn`) | Percent or fixed amount | Percent only | PARTIAL | PO-17: amount option |
| 7 | Grid rows: Annual CTC from / to (`fromSalary`, `toSalary`) × Rating from / to (`fromRating`, `toRating`) → value (`amountPercentage`); "+ Add another", Add, Delete; validations ("To should be greater than or equal to From", "From rating should be greater than the last filled rating in To", "From annual salary should be greater than the last filled annual salary in To") | Increment matrix by CTC band and rating | Rating label → % only | PARTIAL | PO-17: CTC band × rating grid stored on the cycle (`rating_increase_percent` becomes a band-aware matrix; old shape still read) |
| 8 | Step 4 Summary: Average increment, Total Budget, Total Increment, "DOWNLOAD ERROR EXCEL", "Warning!! Click here to view" | Outcome and problems | Cycle lines with increase % and budget variance | PARTIAL | PO-17: summary panel and an error file (employees skipped and why) |
| 9 | "Share with" ("Please select employee(s)"), "Successfull! Increment details has been shared", DONE | Share the plan with managers *(inferred)* | — | MISSING | PO-17: read-only share with chosen managers, limited to their teams (compensation scope rules) |
| 10 | Clone (`app-clone-flyer`) | Copy a plan | — | MISSING | PO-17 |
| 11 | View (`app-increment-view-flyer`: details, budget, basis, grid, "INCREMENT DETAILS") | Read the plan | Cycle view | HAVE | — |
| 12 | Applying the plan to salaries | Turn the plan into revisions | Execute → compensation changes (approved, effective-dated) | HAVE | — |
| 13 | Right "Create" | Access | `compensation.cycles` (+ budget, approve, execute) | HAVE | — |

### 25.9.3 Process bonus (`/app/payroll/bonusprocess`, `bonusprocess_198`; rights Bonus dashboard, Process bonus; `app-process-bonus-sidebar` → `app-non-processed-bonus`, `app-processed-bonus`; settings `app-bonus-sidebar`; slabs `app-bonus-view`, `app-bonus-create`)

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Tabs "Non Processed Bonus" / "Processed Bonus", "Process Bonus" | Bonus dashboard | — | MISSING | PO-22 (= BP-15 PY-19; blocked until the BONUS rule is verified, D-PO5) |
| 2 | Non processed: Month, Employee code, Employee name, Pay group, Bonus days, Pay rate (`payRate`, Edit), selection, "Hang on!", Done | Eligible employees and their bonus basis | Compliance `BonusRegister` computes a provisional statutory bonus per employee for a FY (rule awaiting the owner) | PARTIAL | PO-22: bonus run (FY, eligible list from the register, pay-rate override with reason) |
| 3 | Processed: "Employees whose bonus was processed in …", From month, To month, Pay rate, Pay group, Bonus days, Bonus Amount, Ex gratia Amount, Paid month, Paid with salary | Bonus paid and how | — (nothing paid) | MISSING | PO-22: `BonusInput` (tag `peopleos.payroll_inputs`) paying statutory bonus and ex gratia in the chosen month, or a supplementary run (PO-19) |
| 4 | Detail: Bonus Percentage, Tax Configuration (Taxable), Bonus Pay Days, Bonus pay month, Total Paid, Minimum wages, Branch Name, Nature of Industry, Skill Type, Total | How a bonus was computed | Register basis per employee | PARTIAL | PO-22 |
| 5 | Settings (`app-bonus-sidebar`): "Is bonus applicable?", "Bonus percentage", "Minimum attendance (in days)", "On what do you want to process bonus? Lesser of bonus wages and minimum wages / More of … / On minimum wages", ceiling rows (from / to / amount), "Bonus compliance" | Statutory bonus parameters typed by HR | — | N-A | Statutory: percentages, wage and eligibility ceilings and minimum days come only from the BONUS rule version; the employer's chosen percentage within the rule's range is setup BP-15 `payroll.bonus.percent` |
| 6 | Bonus (minimum wages) slabs (`app-bonus-view`, `app-bonus-create`): State, Branch, Skill type, Industry type, Effective month, Minimum wages, add / delete, INACTIVATE, "NO SLAB CREATED" | Minimum wages used for bonus | — | N-A | Statutory minimum wages only from verified `MIN_WAGE` rule versions (BP-15); the upload "Bonus slabs" is refused for the same reason (§25.10 row 20) |
| 7 | Rights "Bonus dashboard", "Process bonus" | Access | — | MISSING | PO-22 (`payroll.calculate` to prepare, `payroll.approve` to approve) |

---

## 25.10 Imports → Payroll [25] (+ the variable-cancel import 179 and Team → Imports → Payroll [1])

The import wizard, logs and import approval are shared with every module and rated in matrix 23 §23.10.0 (row 28
here). PeopleOS payroll uploads today: Salary upload (new / update / both: employee code, structure code, annual CTC,
effective from, currency, variable target, change type, reason, one column per fixed / input component) and the row
uploads on Payroll → Payroll uploads (perquisites, income outside payroll, statutory overrides with `payroll.override`,
PF options / VPF), all on the staged pipeline with P2 approval. Template columns of HROne's imports were not captured;
each row is rated from the import's name and its instructions (`ImportList.noteDetail`).

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | CTC (employee CTC auto) — "Employee ctc (auto)": "CTC is assigned as per the pay group imported"; Salary revision month; "Arrear with effect from" (≤ revision month); revision month cannot be a processed month; FBP min / max self-validated; flags "assign task to declare FBP" / "generate salary revision letter" | Assign or revise CTC; breakup from the pay group | Salary upload (structure code, annual CTC, effective from; refused for closed months) | HAVE | Arrear month with PO-20; FBP with PO-21; letter flag with PO-16 |
| 2 | Salary structure (employee CTC manual) — "Salary structure ctc break-up": amounts per component; "If you specify any specific amount or 0 then same would be assigned for that component"; special allowance auto-computed; pay group only for reference | Assign with a per-component breakup | Salary upload columns for fixed and input components only; formula components follow the structure | PARTIAL | PO-18: allowed per-employee amounts are those the structure marks fixed / input (BP-2); a formula component cannot be overridden per employee (structure integrity) — the upload explains the refusal |
| 3 | Employee statutory — "Employee statutory details": PF / ESI / PT / LWF applicability (when Y, PF number and UAN required), statutory numbers, "Payment mode salary" (cash / cheque / online + payment bank), "Payment mode reimbursement" | Bulk statutory and pay-mode set-up | PF options upload (VPF, wage basis); statutory numbers, applicability and pay modes only on screen | PARTIAL | PO-18: statutory details upload through `ChangeStatutoryIdentityAction` / `ChangeStatutoryApplicabilityAction` and `PaymentModes::set` (masked in result files; `compliance.sensitive.view` to see numbers) |
| 4 | Perquisites (auto) — "CTC Perquisites" | Perquisites from the pay group's perquisite set-up | Perquisite entries are entered or uploaded as values | PARTIAL | PO-18 with §25.2.2 #24 (valuation only from rule parameters) |
| 5 | Change pay group — new pay group with revision month and arrear month; formula components may take amounts; FBP / letter flags | Move employees to another structure | Salary upload with another structure code (a revision) | PARTIAL | Arrear month PO-20; flags PO-16 / PO-21 |
| 6 | HRA declaration — "Hra declaration" | Upload rent and landlord details | — | MISSING | PO-11: rows per employee, FY and landlord (rent per month, period, metro, landlord name, landlord PAN when the rule needs it) into the declaration; same validations as the screen |
| 7 | 12BAA declaration (max 500 rows) | Employees' declarations of tax deducted / collected on other income for salary TDS *(inferred)* | — (other income can be declared; TDS / TCS on it is not) | MISSING | PO-11 after owner verification of the form and heads under the Income-tax Act 2025 (D-PO11); TDS credit counted by `TaxComputer` only from verified rule data |
| 8 | Investments — "Investment import": request type propose / confirmed, FY; "Some … less frequent … not available in import"; "validations … not enforced for admin … Landlord PAN validation is not enforced"; 80D / 80DDB / 80U maximum eligibility "admin's discretion" | Upload declarations or proofs | — | MISSING | PO-11: proposed or verified amounts per item and FY; PeopleOS keeps every validation of the screen (landlord PAN rule, section limits from the TDS rule) — no admin bypass |
| 9 | Income outside system — "Incentive paid outside payroll": added to taxable income for the FY; TDS deducted outside adjusted | Income paid outside payroll | Income outside payroll upload (`OutsideIncomeImports`): amount, taxable part, TDS / PF / PT deducted outside | HAVE | — |
| 10 | Perquisites (manual) — "Perquisites import": one row per perquisite type mapped in the pay group; sub type tag; value taxed u/s 17(2); recovered amount reduces it; before salary processing | Perquisite values | Perquisite upload (`PerquisiteImports`: type, value, recovered, taxable) | HAVE | — |
| 11 | Settlement — "Settlement Import" (setting "submit and approve settlement import request") | Upload settlements | — | MISSING | PO-18 (= BP-10 settlement upload, `exit.settlement_import_auto_approve` default off): manual lines onto calculated settlements, approval by someone else |
| 12 | Salary arrear — "Manual arrear": "only … for customer onboarding … should not be used post go live", "not … for reimbursement inputs", "considered without referring to any other payroll settings", arrear PF / VPF / ESI only when the arrear month is processed | Arrears typed in bulk | Payroll adjustments of type arrear, on screen | PARTIAL | PO-6: arrear upload creating pending adjustments (type arrear, reference month, component), approved by someone else |
| 13 | Variable pay — "Monthly variable" / "Salary variables" (type B): only variables mapped to the pay group; structure value shown; formula pays per formula; 0 to skip; last row wins | Monthly variable inputs | — | MISSING | PO-6: variable upload (employee, component, pay month, amount or "as structure") creating variable pay requests decided by someone other than the uploader |
| 14 | Variable cancel (import 179, offered on Variable pay cancellations) | Cancel variables in bulk | — | MISSING | PO-6 (cancel rows go through `VariablePay::cancel`) |
| 15 | Reimbursement bills (pay by default): "confirm bills for pay by default reimbursement components", "Value imported … added for all the months … to exclude income from taxation, subject to … non taxable limit", before salary processing | Bills that make monthly reimbursements tax-free | Monthly reimbursement components are non-taxable by component flag; no bills behind them | MISSING | PO-9 after BP-8: bills per component and FY; TDS treats the component as taxable beyond the bills (exemption limits from the component / rule, never typed) |
| 16 | Reimbursement claims (pay on claim): three columns per type (Balance — information only, Claim amount, Bill amount); paid in the pay month | Claims in bulk | — | MISSING | PO-9: claims upload (each row a claim checked against the balance, approved by someone else) |
| 17 | Reimbursement opening balance (pay on claim): "only … at implementation" | Opening balances | `ReimbursementClaims::opening()` exists (ledger kind `opening`); no screen or upload | MISSING | PO-9 upload calling `opening()` (once per employee, component and FY) |
| 18 | Override TDS: imported value posted to challans and reports; "can only be done after salary is processed"; lost on unprocess | Set TDS by hand | Statutory override upload (TDS), before finalize, never one's own | HAVE | PeopleOS applies overrides before finalize (finalized runs are not changed) |
| 19 | ESI (override ESI): employer and employee values posted to challans; after processing | Set ESI by hand | Statutory override upload (ESI) | HAVE | As row 18 |
| 20 | Bonus slabs — "Update Bonus Slab" | Minimum-wage slabs for bonus | — | N-A | Statutory values only from verified `MIN_WAGE` / BONUS rule versions (BP-15) |
| 21 | Loans — "Loan Import" (max 1000): imported dispense cards auto-approved; searchable in loan history; approver-edit and interest-edit settings do not apply | Bulk loans (go-live or offline loans) | — | MISSING | PO-10: loans upload (type, principal, instalments, interest, first month, already recovered count) creating disbursed loans and schedules; P2 approval instead of auto-approval |
| 22 | PF (before salary) — "Override pf (before salary processing)": EPF / EPS wages uploaded; PF not more than the employee limit; kept on reprocessing | PF wages by hand | Statutory override of the PF amount (not wages) | PARTIAL | PO-18: wage-basis override per employee and month (EPF / EPS / EDLI wages) as a statutory override kind, contributions still from the rule |
| 23 | PT (before salary) — "Pt before salary" | PT by hand before processing | Statutory override upload (PT) | HAVE | — |
| 24 | LWF arrear — "LWF arrear (before salary processing)" | LWF on arrears by hand | — | MISSING | PO-20 (LWF on arrears per SV-2 profile choice) |
| 25 | PF arrear (before arrear) — "Override pf arrear": arrear month / pay month, EPF / EPS / EDLI wages taken as is, U / D mode | PF on arrears by hand | — | MISSING | PO-20 |
| 26 | Previous month salary — "Mid year implementation": for customers starting mid-year; "not to be used for the actual processing of payroll"; 24Q may need to be managed outside | Opening year-to-date | — | MISSING | Setup BP-17 (`payroll_opening_balances`, opening YTD upload read by TDS and registers) |
| 27 | Team → Imports → Payroll → Variable pay | Managers upload their team's variables | — | MISSING | PO-6 (manager scope: direct reports only; payroll decides) |
| 28 | Import wizard, logs, approval (shared) | Upload flow | Staged pipeline, Recent uploads, P2 approval | PARTIAL | Matrix 23 §23.10.0 (WO-17) |

---

## 25.11 Inbox Insights → Payroll [21]

The Inbox Insights page itself (organisation-wide open-task counts, overdue, relieved owners, reminders, bulk archive)
is rated in matrix 21 §21.3 (AN-30, MISSING). Each row here rates whether PeopleOS has the **task kind** AN-30 would
count: HAVE = the kind exists and is listed for its owners (Approval Center or My tasks); PARTIAL = it exists only on
its own screen or in part; MISSING = no such work item.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | New join salary structure | Assign a joiner's salary | Control room count "without salary"; no task | PARTIAL | PO-14 (= matrix 20 RQ-10 #23): HR task per joiner without a compensation in force on joining |
| 2 | Assign salary structure (rights View, Update / View employee perquisites) | Approve an assignment | Approval Center kind `compensation_change` | HAVE | — |
| 3 | Variable cancel | Approve a variable cancellation | — (direct cancel) | MISSING | PO-6 (§25.4 #9) |
| 4 | Proposed investment | Review a proposed declaration | Workflow task when a workflow is configured (`TaxDeclarationWorkflowBridge`); else the list | PARTIAL | Matrix 20 RQ-10 #20 |
| 5 | Confirmed investment | Verify proofs | Proof verification on TDS investments | PARTIAL | PO-14: Approval Center source for proofs awaiting verification |
| 6 | Fbp approval | Approve FBP | — | MISSING | PO-21 |
| 7 | Salary revision letter | Generate / approve the revision letter | Letter approval kind `letter` (when a template needs approval); no generation task | PARTIAL | PO-16 |
| 8 | Declare fbp | Employee declares FBP | — | MISSING | PO-21 |
| 9 | Reimbursement cancel | Approve a claim cancellation | — (direct cancel) | MISSING | PO-9 (§25.3.1 #6) |
| 10 | Variable | Approve variable pay | Approval Center kind `variable_pay` | HAVE | — |
| 11 | Loan | Approve a loan | Approval Center kind `loan` | HAVE | — |
| 12 | Dispense loan | Pay out an approved loan | Approved loans in the list ("Mark paid out"); no task | PARTIAL | PO-14: task for approved loans paid outside payroll and not yet paid out |
| 13 | Loan guarantor | Guarantor accepts | Approval Center kind `loan_guarantee` | HAVE | — |
| 14 | Submit investment declaration | Employee declares in the window | Window settings and notification rules; no employee task | PARTIAL | Matrix 20 RQ-10 #21 |
| 15 | Claim reimbursement | Employee claims an unclaimed balance | Reminder notification `payroll.claim_reminder` (BP-9, `payroll.claims.reminder_task`); not a task | PARTIAL | Matrix 20 RQ-10 #18 (task form of the reminder) |
| 16 | Reimbursement | Approve a claim | Approval Center kind `reimbursement_claim` | HAVE | — |
| 17 | Settlement | Approve / complete a settlement | On the exit case | PARTIAL | PO-12 (Approval Center source `final_settlement`) |
| 18 | Initiate settlement | Start F&F when the exit reaches its last day | `fnf_pending` report; no task | PARTIAL | Matrix 20 RQ-10 #25 / PO-14 |
| 19 | Declare statutory details | HR declares a joiner's statutory set-up | Statutory details screens; no task | PARTIAL | Matrix 20 RQ-10 #22 |
| 20 | Appointment letter | Generate the appointment letter | Letters by hand; approval kind `letter` | PARTIAL | PO-16 |
| 21 | Revise ctc | Revise a salary after an event (transfer, promotion, confirmation) *(inferred)* | Proposals by hand ("Propose from position" after a transfer); cycles | PARTIAL | PO-14: task to HR when a transfer / promotion / confirmation policy says "revise salary" and no change is proposed |

---

## 25.12 Analytics → Payroll [6] and Analytics → Dashboards → Payroll

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Proposed investment not submitted | Employees without a declaration this FY | — (no metric; report gap in matrix 21 #26) | MISSING | PO-15: metric `declarations_missing` (`WorkforceMetrics`, restricted to `payroll.view`) |
| 2 | Confirmed investment not submitted | Employees without proofs in the proof window | — | MISSING | PO-15: metric `proofs_missing` |
| 3 | Total employee cost, last month | Employer cost of the last run | Metric `people_cost` (last run's employer cost) and `cost_per_head` | HAVE | — |
| 4 | Salary paid last month | Net paid | Run totals carry net; no metric | PARTIAL | PO-15: metric `net_paid_last_month` (finalized / paid runs) |
| 5 | Net ctc as per salary structure | Sum of CTC in force | Compensation analytics (fixed / variable totals) | PARTIAL | PO-15: metric `ctc_in_force_monthly` through `CompensationOutput` (`compensation.analytics`, small-group suppression) |
| 6 | Total outstanding loan | Loans outstanding | Loans carry `outstanding`; no metric | MISSING | PO-15: metric `loans_outstanding` |
| 7 | Analytics → Dashboards → Payroll (View) | Payroll dashboard | Compensation analytics; payroll templates | PARTIAL | Matrix 21 §21.1.12 (AN-12) |

---

## 25.13 Reports — HR Desk → Reports → Payroll [65], the Payroll panel "Reports" page, each page's Reports tab and Team → Reports → Payroll [2]

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | HR Desk → Reports → Payroll: 64 reports (Hold salary release details … Reimbursement audit) | The payroll report catalogue | Rated one by one in matrix 21 §21.2.2 "Payroll (64)": 25 HAVE / 32 PARTIAL / 7 MISSING. Name differences: tree "ESI(employee state insurance)" = 21 #12; "Perquisites report" = #46 "Perquisite"; "Current CTC(salary structure)" = #8 | PARTIAL | Matrix 21 AN-24 / AN-25 / AN-26; nothing repeated here |
| 2 | "Bonus register becil" (the 65th node; not in matrix 21) | A customer-specific variant of the bonus register | Compliance `BonusRegister` (BONUS rule awaiting the owner) | PARTIAL | As matrix 21 #5 (PY-19 / PO-22); customer layouts are report-library templates, not code |
| 3 | Payroll panel "Reports" page (`openReports`, module 3): report cards with name, format, description, "History", "Download", "Open"; counts per process (`report/Count/Process`) | Browse and run payroll reports | Report library (categories, search, templates, run, CSV export, schedules) | PARTIAL | Matrix 21 §21.2.1 (xlsx output, run history) |
| 4 | Reports tab on every payroll page (default report, "Create View", report picker) | The page's report | Library templates are not linked from the registers | PARTIAL → built (PO-1: "Report" on the payroll registers (claims, variable pay, loans, compensation changes, tax declarations, investment proofs, perquisites, overrides, PF options, adjustments, runs, payslips, salary structures) with the register's filters as report conditions) | WO-1 "Report" header action (matrix 23 §23.1 #2), applied by PO-1 |
| 5 | Team → Reports → Payroll: Variable paid, Salary register | Managers' payroll reports for their team | The manager role has no report-library permission (matrix 20 §20.3.4 #1); no variable-paid template | MISSING | Matrix 20 RQ-11 (team reports) after matrix 21 AN-26; salary visibility to managers is a tenant choice (CA-6) |

---

## 25.14 Team → People → Action → Payroll (6 rights) and Team profile → Payroll (2 rights)

Team actions per member are rated in matrix 20 §20.3.1–20.3.2; the payroll ones are listed here because they reach
payroll processes.

| # | HROne element (tab / field / column / action) | What it does (inferred where unclear, mark *(inferred)*) | PeopleOS today (file or screen) | Status | Build note |
|---|---|---|---|---|---|
| 1 | Action "Reimbursement" | Manager claims for a team member | Claims only by the employee | MISSING | PO-9: claim on behalf for managers when the reimbursement rules allow (`payroll.claims.manager_may_claim`, default off) |
| 2 | Action "Loan request" | Manager requests a loan for a team member | "Raise on behalf" needs `payroll.manage` | PARTIAL | PO-10: loan policy `manager_may_request` (default off) |
| 3 | Action "Confirm invest" | Manager submits a member's proofs | Employee and payroll only | N-A | Privacy by design: tax declarations and proofs stay between the employee and payroll (D-PO14) |
| 4 | Action "Propose invest" | Manager declares for a member | As row 3 | N-A | As row 3 |
| 5 | Action "Increment calculator" | Manager plans team increments | Cycles prepared by HR (`compensation.cycles`); managers see team compensation (`compensation.team`) | PARTIAL | PO-17 (shared plans with manager proposals within their team) |
| 6 | Action "Variable" | Manager requests variable pay | "For an employee" (managers with `leave.approve`); plan payouts by the manager | HAVE | — |
| 7 | Team profile "Ctc" | Member's CTC | Compensation with `compensation.view`; not in the manager template | PARTIAL | Matrix 20 §20.3.2 #11 (CA-6) |
| 8 | Team profile "Payslip" | Member's payslips | Payroll permissions only | PARTIAL | Matrix 20 §20.3.2 #30 (CA-6) |

---

## Counts

Rated rows only (index tables §25.0 and §25.9.1 are not counted).

| Area | Rows | HAVE | PARTIAL | MISSING | N-A |
|---|---|---|---|---|---|
| 25.1 Panel and register chrome | 10 | 2 | 4 | 3 | 1 |
| 25.2 Salary structure (register, assign, requests) | 42 | 15 | 22 | 4 | 1 |
| 25.3 Tax & benefit (reimbursements, investments, FBP) | 34 | 6 | 14 | 13 | 1 |
| 25.4 Variable pay | 11 | 2 | 7 | 2 | 0 |
| 25.5 Loan | 20 | 9 | 6 | 5 | 0 |
| 25.6.1–25.6.11 Run payroll (salary dashboard, 11 steps, holds, publish, reminders, scheduler, rights) | 111 | 26 | 42 | 36 | 7 |
| 25.6.12 Generate challan | 21 | 7 | 12 | 1 | 1 |
| 25.6.13 Salary YTD (HR) | 5 | 1 | 2 | 1 | 1 |
| 25.6.14 Leave encashment (bulk) | 9 | 2 | 1 | 6 | 0 |
| 25.7 Full and final settlement | 38 | 9 | 19 | 9 | 1 |
| 25.8 Payroll letters | 8 | 2 | 5 | 1 | 0 |
| 25.9 Increment calculator, process bonus | 20 | 5 | 7 | 6 | 2 |
| 25.10 Imports → Payroll | 28 | 6 | 7 | 14 | 1 |
| 25.11 Inbox Insights → Payroll | 21 | 5 | 12 | 4 | 0 |
| 25.12 Analytics → Payroll | 7 | 1 | 3 | 3 | 0 |
| 25.13 Reports (pointer rows) | 5 | 0 | 4 | 1 | 0 |
| 25.14 Team → Payroll | 8 | 1 | 4 | 1 | 2 |
| **Total** | **398** | **99** | **171** | **110** | **18** |

The 64 payroll reports are counted in matrix 21 (25 HAVE / 32 PARTIAL / 7 MISSING) and appear here as one pointer row.

## Biggest gaps

1. **Per-employee processing inside a run (PO-5, PO-19).** HROne processes, holds, unprocesses, verifies, releases and
   publishes employee by employee, and "you can process the salary for the rest of the employees" when some are
   blocked. PeopleOS runs the whole company and period at once: one blocking exception stops validation for everyone,
   there is no "recalculate these employees", no exclusion with a reason and no second run in the period (§25.6.1 #5,
   §25.6.2 #1, §25.6.4 #10).
2. **Pre-run readiness (PO-3).** Eight of HROne's 16 warning-card checks are missing (salary unprocessed last month,
   active-only toggle, PAN–Aadhaar link, Aadhaar, UAN, ESI number, minimum wage, drill-down lists with download) and five
   more exist only as post-calculation exceptions (§25.6.2).
3. **Revision and overtime arrears (PO-20 = BP-10).** No increment arrear, no "arrear with effect from", no
   previous-month overtime, no OT increment arrear, no mid-month increment for a closed month, no PF / LWF arrear inputs
   (§25.2.2 #15, §25.6.3 #5–#7, §25.6.4 #9, §25.10 #24–#25). Back-dated revisions into closed months are simply refused.
4. **Payslip publishing and distribution (PO-7 + BP-14).** No publish step separate from finalize, no bulk PDF
   download, no e-mailing, no password, no tax slip or reimbursement slip, no per-employee transfer status (§25.6.6
   #10, §25.6.7).
5. **Payroll uploads (PO-6, PO-9, PO-10, PO-11, PO-18).** 14 of the 28 import rows are missing: variable pay (and team
   variable), variable cancel, loans, reimbursement claims / bills / opening balances, investments, HRA, 12BAA,
   settlement, LWF / PF arrears, mid-year opening YTD (§25.10).
6. **Full and final settlement desk (PO-12).** No register across exits or its filters, no supplementary settlement,
   no bulk pay or payment mode / cheque details, no settlement slip, e-mail or employee acceptance, no gate on open
   requests, no s.10(10AA) exemption, no Approval Center item (§25.7).
7. **Challan payment register (PO-13 = BP-15 PY-21).** Returns are generated, approved, exported and filed, but
   challan payments (date, mode, TRRN / CRN / BSR, bank, interest, penalty, receipt) and the TDS challan's employee
   allocation are not recorded; no FY × month grid (§25.6.12).
8. **HR bulk leave encashment (PO-8).** HROne's "Encash leaves" batch (leave type, pay month, as per policy or N days,
   applicability, review, unprocess) has no equivalent; encashment is request-only (§25.6.14).
9. **Reimbursement operations (PO-9).** HR cannot claim for an employee, cancel in bulk, route cancellations through
   approval, act on unclaimed balances at year end or load opening balances (§25.3.1, §25.6.9).
10. **Flexible benefits and bonus (PO-21, PO-22).** FBP declaration and approval do not exist (§25.3.4); statutory
    bonus is computed in a provisional register but never processed or paid, blocked on the BONUS rule (§25.9.3).

Also notable: loan restructuring, partial EMI and "recover complete loan" (§25.5 #15, #17, #18); increment calculator
CTC-band grid, tenure proration, amount basis and sharing (§25.9.2); employee-centric salary-structure register and
assignment wizard with a per-employee breakup (§25.2).

**Where HROne behaviour would break PeopleOS rules (rated N-A, safe equivalent named).** Unprocess / unlock /
unpublish after release, "Edit settlement" after payment and "Edit Income Tax" at challan time would change finalized
payroll — PeopleOS corrects by arrear or recovery in the next open month, a TDS override in an open month, a
supplementary run or a supplementary settlement. HR-typed bonus percentages, ceilings, minimum-wage slabs and 80D
limits would put statutory values outside verified rule data — PeopleOS reads them from rule versions only. Deleting
credited reimbursement balances or claims would erase audit records — PeopleOS cancels or posts a reversing adjustment.
The investment import's admin bypass of validations (e.g. landlord PAN) is not copied. HROne's payslip password (last
four PAN characters plus joining month-year) is guessable; PeopleOS should use a per-employee secret or signed links.

## Owner decisions needed

| # | Decision | Options (recommended first) | Blocks |
|---|---|---|---|
| D-PO1 | Corrections to finalized payroll | Keep "never reopened": arrear / recovery / TDS override in an open month, supplementary run or settlement · allow HROne-style unprocess after release (not recommended) | PO-5, PO-12, PO-19 wording |
| D-PO2 | Payslip visibility | Publish at finalize (today) with an optional `manual` publish step per tenant · always a separate publish step | PO-7 |
| D-PO3 | Unclaimed pay-on-claim balance at financial-year end | Keep (today) · lapse · pay as taxable in the last month | PO-9 |
| D-PO4 | Salary payout provider and OTP before release | Bank files only (today) + OTP on finalize / bank file · integrate a payout provider (external contract) | PO-7, SV-21 |
| D-PO5 | BONUS rule verification | Verify the rule version (statutory) · leave bonus unprocessed | PO-22 |
| D-PO6 | s.10(10AA) leave-encashment exemption rule version | Verify a TDS rule version carrying the limit, averaging months and divisor · keep encashment fully taxable | PO-12 row 12 (= BP-10) |
| D-PO7 | TDS at challan time | Deposit what finalized payroll deducted; differences by override next month (recommended) · allow editing (breaks immutability) | PO-13 |
| D-PO8 | "Delete" of claims and credited balances | Cancel / reversing adjustment with a reason (recommended) · hard delete (not recommended) | PO-9 |
| D-PO9 | PDF library and payslip protection | Choose the PDF library (shared with BP-14); per-employee secret or signed links instead of PAN / DOB-derived passwords | PO-7, PO-12 slips |
| D-PO10 | Referral bonus | Keep in the RMS (N-A) · let the RMS push approved referral bonuses as a payroll input | §25.6.9 #5 |
| D-PO11 | Form 12BAA and heads under the Income-tax Act 2025 | Verify which declaration of TDS / TCS on other income applies from 1 Apr 2026 before building · skip | PO-11 row 7 |
| D-PO12 | Perquisite rights | Keep under `payroll.manage` / `payroll.view` · add `payroll.perquisites` | §25.2.2 #25 |
| D-PO13 | Settlement approval by a second person | Setting default off (today) · on by default for new tenants | PO-12 |
| D-PO14 | Managers on team members' tax declarations | Keep N-A (privacy) · allow with a policy switch | §25.14 #3–#4 |

## Build packages (ordered)

Each package is additive; every new setting defaults to today's behaviour; statutory values only from verified rule
versions; finalized payroll never reopened; new money paths are `peopleos.payroll_inputs` sources or
`peopleos.settlement_sources`; new decisions are Approval Center sources (`peopleos.approval_sources`); organisation
switches go to `config/peopleos_setup.php` (Setup → Payroll); per-group options go to policy types. Tests are Pest
feature tests in `tests/Feature/HroneParity/`, each package ending with an entry in `docs/architecture/hrone-parity.md`
and a default-unchanged check like `PayrollCompletionDefaultsTest` where calculation paths are touched.

| # | Package | Scope (rows it closes) | Files likely touched | Depends on | Size (S/M/L) |
|---|---|---|---|---|---|
| 1 | **PO-3 Pre-run readiness panel** (**built 2026-10-30**; row 11 waits for BP-8) — read-only checks with counts, drill lists and CSV: salary not processed last month, attendance not processed, statutory details missing, structure missing, PAN / Aadhaar / UAN / ESI IP missing, PAN–Aadhaar not linked (after BP-8), wage-share below the guardrail, below minimum wage (only with a verified `MIN_WAGE` rule), above the ESI ceiling (ceiling read from the rule), unbalanced CTC, special lifecycle states; "active employees only" | §25.6.2 #2–#5, #7, #8, #11–#18; §25.6.1 #8 (display) | `Pages/PayrollControlRoom` (+ view), new `Payroll/Services/PayrollReadiness` (reads `CompensationOutput`, statutory details, attendance, last finalized run), `StructureFormulas`, `ComplianceRules`; analytics-style CSV export (audited) | BP-8 (row 11), BP-15 (row 8) | M |
| 2 | **PO-4 Run cockpit** — the run view as steps (inputs → calculate → verify → approve → finalize / publish → pay) with counters (arrears pending / settled / void, OT, variable, claims, loans, encashments, holds, released, paid); reminders panel (pending declarations, proofs, loans, dispense, claims, holds, encashments); entries table with pay mode, account, hold, status filters and selection; "Calculate in background" (`CalculatePayrollRun` + notification); `payroll.auto_calculate_day` (0 default; recalculates open runs only); step-table export; optional reviewer tick per entry; run activity from audit events | §25.6.1 #2, #3, #6, #7; §25.6.3 #2, #4, #9; §25.6.4 #1, #12; §25.6.5 #2, #3; §25.6.6 #3, #7; §25.6.8 #1–#5, #7, #8; §25.6.10 #1, #2; §25.6.11 #12 | `PayrollRunResource`, `EntriesRelationManager`, `PayrollActions`, `Pages/PayrollControlRoom`, `Jobs/CalculatePayrollRun`, new console command (scheduled), `config/peopleos.php` + `peopleos_setup.php` (setting), migration `payroll_entries.reviewed_by/_at` | PO-3 | M |
| 3 | **PO-5 Per-employee processing in an open run** — "Recalculate selected employees" (only their entries replaced; totals, reconciliation and audit refreshed); "Exclude from this run" with a reason from option list `payroll.exclusion_reasons` (seeded with HROne's seven) and re-include; effective-dated "not paid through PeopleOS" marker removing an employee from the population and readiness; never after finalize | §25.6.1 #5; §25.6.2 #1; §25.6.4 #10; §25.2.2 #26 | `PayrollRuns` (population, `recalculateEmployees`, exclusions), new table `payroll_run_exclusions`, new `employee_payroll_exemptions`, `PayrollActions`, `OptionLists` seed, `DataInventory`, policies | PO-4; D-PO1 | M |
| 4 | **PO-1 Payroll desk navigation and register filters** (**built 2026-10-30**) — "Payroll desk" cluster ordered like HROne's panel, each entry linking the existing resource with presets; salary-structure (pay group) filter on entries, claims, variable pay, loans, holds; pending-resignation exclusion; payroll filters (arrear month, revision month, arrear-day bands); each register linked to its upload types and its report template | §25.1 #1, #3–#7; §25.13 #4 | Filament navigation (`AdminPanelProvider` / cluster), resources' table filters, shared filter group from WO-2 | WO-1, WO-2 (matrix 23) | S |
| 5 | **PO-2 Salary structure register and assignment wizard** — employee-centric register (structure and CTC in force, state, source, regime, dates; "Not assigned" preset; export); "Assign salary" wizard (statutory step, proposal, revise by %, per-employee breakup preview with read-only deduction estimate, wage-share guardrail, print breakup through the salary annexure) for one or many employees | §25.2.1 #1–#5; §25.2.2 #1, #18, #21–#23, #30 (on screen); §25.2.3 #1 | new `Filament/Pages/SalaryStructureRegister` (or resource over employees), `CompensationRelationManager` / `CompensationActions`, `CompensationChanges` (percent), `StructureFormulas::preview`, `StatutoryEngine` (estimate, no writes), `Letters/Support/SalaryAnnexure` | PO-1 | M |
| 6 | **PO-6 Payroll inputs desk** — bulk "Add variable" grid (employees × component, amount or "as per structure"), each row an adjustment / request approved by someone else; variable upload (+ manager scope for Team → Imports); variable cancellation approval setting `payroll.variable.cancel_requires_approval` + register preset + upload; manual arrear upload; "Ignore" attendance arrear (void with reason); approver FY eligibility / paid totals; plan `auto_pay`; activity panel; cancellations refused while an unfinalized run carries the record | §25.4 #2, #4–#6, #8, #9; §25.6.3 #3; §25.6.4 #3, #4; §25.7.2 #7 (as structure); §25.10 #12–#14, #27; §25.11 #3 | `PayrollAdjustmentResource`, `VariablePay`, `VariablePayPlans`, `VariablePayRequestResource`, `AttendanceArrearResource`, new `RowImports` subclasses registered in `RowImportTypes`, `PendingPayrollInputs`, `PayrollRequestsApprovalSource`, migration (`variable_pay_plans.auto_pay`) | PO-1; matrix 20 RQ-5 | M |
| 7 | **PO-7 Release, publish and payslip distribution** — `payroll.payslip.publish` (`on_finalize` default / `manual`) with "Publish payslips" and `published_at`; bulk hold with reason option list `payroll.hold_reasons`; `salary_holds.release_month`; "released holds" bank file; per-entry payment status (+ optional bank response import); reimbursement-only file and statement; bulk payslip download (zip / merged PDF, downloads log) and e-mail (`payroll.payslip_published`); new permission `payroll.publish` (sync); OTP on finalize / bank file reusing SV-21 | §25.6.6 #2, #4, #5, #8, #10; §25.6.7 #1, #3–#7, #10–#12; §25.6.10 #3; §25.6.11 #9–#11, #13, #14 | `Payslips`, `PayslipResource`, `SalaryHolds`, `SalaryHoldResource`, `BankFile`, `PayrollActions`, `config/peopleos.php` (permission, settings, notification event), migrations (`payslips.published_at`, `salary_holds.release_month`, `payroll_entries.payment_status`), `composer.json` after D-PO9 | BP-14 (PDF, password, language), SV-21 (OTP); D-PO2, D-PO9 | L |
| 8 | **PO-8 Bulk leave encashment** — `leave_encashment_batches` (leave type, pay month, as per policy / N days, applicability, by, at); stepper Leave type → Applicability → Review → Process creating approved encashments (second-person approval when `payroll.encashment.batch_approval`, default on); batch list and filters; cancel unpaid encashments of a batch | §25.6.14 #1–#6 | new model / service / page in `Leave` (batch) using `LeaveAdjustments`, `LeaveEncashmentInput` unchanged, `LeaveEncashmentResource`, `DataInventory`, `config/peopleos_setup.php` | TO-7 encashment rules | M |
| 9 | **PO-9 Reimbursement operations** — claim for an employee (HR; managers when `payroll.claims.manager_may_claim`); bulk cancel; `payroll.claims.cancel_requires_approval` (default off) as an Approval Center kind; FY totals per employee and component; year-end treatment `payroll.claims.year_end` (`keep` default / `lapse` / `pay_taxable`) by a reviewed batch; reversing adjustments instead of deletes; uploads for claims, opening balances (`ReimbursementClaims::opening`) and pay-by-default bills (after BP-8) | §25.3.1 #2–#4, #6–#8; §25.6.9 #1, #3, #4; §25.10 #15–#17; §25.11 #9; §25.14 #1 | `ReimbursementClaims`, `ReimbursementClaimResource`, `ReimbursementLedgerEntry`, new `Payroll/Inputs/UnclaimedReimbursementInput`, `PayrollRequestsApprovalSource`, `RowImportTypes` + new imports, settings | BP-8 (bills); D-PO3, D-PO8 | M |
| 10 | **PO-10 Loan operations** — date filters; interest method (reducing default / flat) on the loan policy; settle any amount outside payroll; "recover complete loan" next month; restructure (new schedule version for the outstanding, approved by someone else); partial EMI deferral; activity panel; loans upload (P2 approval, disbursed loans with schedules); `manager_may_request` policy option | §25.5 #2, #5, #12, #14, #15, #17, #18; §25.10 #21; §25.14 #2 | `Loans`, `LoanResource`, `LoanInstallment`, `LoanRecoveryInput` (unchanged contract), `config/peopleos.php` `policies.types.loan`, new loan import | matrix 20 RQ-5 | M |
| 11 | **PO-11 Investment desk** — FY / regime / date filters; activity panel; submission snapshots (`tax_declaration_submissions`); proof submission for an employee (verifier ≠ submitter); bulk proof zip (= RQ-7b); investments, HRA and (after D-PO11) 12BAA uploads with the screen's validations | §25.3.2 #2, #4–#7, #11; §25.3.3 #2, #3, #5, #6; §25.10 #6–#8 | `InvestmentDeclarations`, `InvestmentProofs`, `TaxDeclarationResource`, `TdsInvestmentResource`, `DeclareInvestments`, `ParityFileController`, new imports in `RowImportTypes`, migration | matrix 20 RQ-7b; D-PO11 | M |
| 12 | **PO-12 Settlement desk** — settlements register and filters; supplementary settlements; open-request gate (`exit.settlement.block_on_pending`, default off); attendance step; inline leave adjustment; notice-days override and notice pay line; clearance recovery override with attachment; reimbursement balance at exit; internal / external remarks; payment mode, paying account, cheque details, bulk pay and a settlement bank file; Approval Center source `final_settlement` with `exit.settlement.independent_approval` (default off); settlement slip as letter type `settlement_slip`, e-mail, employee acceptance; tax-input warning; s.10(10AA) exemption once its rule version is verified | §25.7.1 #1–#5, #8; §25.7.2 #2, #4, #5, #10, #12, #13, #15, #16, #18; §25.7.3 #1, #4–#9; §25.7.4 #1, #2; §25.6.6 #6; §25.11 #17 | `FinalSettlements`, `FinalSettlement` / lines (+ `type`, `parent_id`, `payment_mode`, remarks), `SettlementRelationManager`, new settlements page, `BankFile` (settlement source), new `Experience/Sources/SettlementApprovalSource`, `Letters` (type, variables), `TaxComputer` (exemption from rule params), settings | BP-10 (rule + upload), PO-7 (PDF), WO-11, WF-01 acknowledgement; D-PO6, D-PO13 | L |
| 13 | **PO-13 Statutory challan register** — `statutory_challans` (statute, establishment / registration / entity, period, challan no. / TRRN / CRN / BSR, paid on, mode, bank, branch, amount, interest, penalty, receipt, recorded by — never the approver); FY × month grid per statute over returns and challans; TDS challan with employee allocation read from finalized payroll; the quarterly statement picks challans from the register; coverage alert on the grid | §25.6.12 #1, #4 (display), #8, #10–#13, #15–#17 | new model / service / resource in `Compliance`, `TdsQuarterlyReturns`, `StatutoryReturnActions`, `ComplianceControlRoom`, policy (`compliance.returns.file`), `DataInventory` | BP-15 PY-21 (register part needs no rule data); D-PO7 | M |
| 14 | **PO-14 Payroll inbox task kinds** — joiner without salary, proofs awaiting verification, approved loans not paid out, exit at last day without settlement, "revise salary" after a transfer / promotion / confirmation policy event — as task sources counted by AN-30 | §25.11 #1, #5, #12, #18, #21 | `Experience` task sources (`peopleos.experience.task_sources`), `ApprovalCenter`, listeners in `ParityServiceProvider` | matrix 20 RQ-10, matrix 21 AN-30 | S |
| 15 | **PO-15 Payroll analytics tiles and HR salary YTD** — metrics `declarations_missing`, `proofs_missing`, `net_paid_last_month`, `ctc_in_force_monthly` (through `CompensationOutput`, suppression), `loans_outstanding`; HR "Salary YTD" page = MS-10 page with an employee picker | §25.12 #1, #2, #4–#6; §25.6.13 #1, #2 | `WorkforceMetrics`, `config/peopleos.php` `analytics.metrics`, `AnalyticsDefaults` (payroll board), MS-10 page + `PaidPayReader::yearToDate` | matrix 22 MS-10, matrix 21 AN-12 | S |
| 16 | **PO-16 Payroll letters automation** — appointment letter on the first approved compensation, revision letter on an approved revision (`letters.auto.*`), letter `source` = compensation change with its date, register presets; upload flags "generate revision letter" | §25.8 #2–#4; §25.11 #7, #20; §25.10 #1, #5 (flags) | `Letters`, `PolicyLetters`, listeners on compensation events, `LetterResource`, `SalaryImports` | setup BP-16 (PY-28), WO-13 | S |
| 17 | **PO-17 Increment calculator** — population by organisation filters and codes; tenure proration; amount basis; CTC band × rating grid (old rating-only shape still read); summary and error file; share with managers (their teams only) and manager proposals; clone | §25.9.2 #1, #2, #5–#10; §25.14 #5 | `CompensationCycles`, `CompensationCycle` (matrix shape), `CompensationCycleResource`, `CompensationAccess`, migration | — | M |
| 18 | **PO-18 Payroll uploads completion** — statutory details and pay-mode upload (masked results); structure upload explains formula-component refusals; perquisite schedules and rule-based valuation where verified; PF wage-basis override kind; settlement upload (BP-10) | §25.10 #2–#4, #11, #22; §25.2.2 #24 | `RowImportTypes`, new imports, `ChangeStatutoryIdentityAction`, `ChangeStatutoryApplicabilityAction`, `PaymentModes`, `StatutoryOverrides`, `TaxIncomeEntries`, `SalaryImports` | BP-10, BP-11, SV-2 | M |
| 19 | **PO-19 Supplementary runs** — several runs per period with non-overlapping populations (regular / supplementary / bonus): excluded employees, released holds, late inputs; uniqueness by period + population | §25.6.1 #5 (rest), §25.6.2 #1 (complete), §25.9.3 #3 (option) | `PayrollRuns` (population, uniqueness), `PayrollRun` (`kind`, `salary_structure_ids` / employee list), `PayrollPeriod` closing rule, `PayrollActions`, compliance returns reading every finalized run of the period | setup BP-12 (PY-22), PO-5 | L |
| 20 | **PO-20 Revision and overtime arrears** (= setup BP-10 calculation part) — `compensation_changes.arrear_from`; `RevisionArrearInput` (finalized months' difference, part months by days, only `is_arrear_eligible` components); `OvertimeArrearInput` for OT approved after its month closed and the OT difference after a revision; PF / ESI / PT / LWF on arrears by SV-2 profile choices; PF and LWF arrear uploads | §25.2.2 #15; §25.2.3 #3; §25.6.3 #5–#7; §25.6.4 #9; §25.6.11 #2, #3; §25.7.2 #9; §25.10 #24, #25 | `Payroll/Inputs/*`, `PayrollInputs`, `PayrollCalculator`, `CompensationChanges`, `StatutoryEngine`, `RowImportTypes`, migration | setup BP-10, SV-2 | L |
| 21 | **PO-21 Flexible benefit plan** (= setup BP-7 FBP part, PY-03) — `fbp_declarations` per employee, FY and component within the structure's FBP bounds; declare / approve tasks and Approval Center source; history register; bounds on uploads | §25.3.4 #1–#4; §25.6.8 #6; §25.11 #6, #8 | new model / service / resource in `Compensation`, `SalaryStructureVersion` (`fbp_enabled`), component FBP fields, `PayrollCalculator` reads values through `CompensationOutput`, approval source | setup BP-12, PO-2 | L |
| 22 | **PO-22 Bonus processing** (= setup BP-15 PY-19) — bonus run per FY from the verified BONUS rule: eligible list, pay-rate override with reason, statutory bonus and ex gratia, `BonusInput` into a chosen month or a supplementary run, processed list; employer percentage within the rule's range as a setting | §25.9.3 #1–#4, #7 | `ComplianceReports/Builders/BonusRegister`, new `Payroll/Inputs/BonusInput`, new bonus run model / page, `database/data/compliance/in.php` (verified rule) | owner verification of the BONUS rule (D-PO5); PO-19 optional | M (blocked) |

**Kept out on purpose:** unprocess / unlock / unpublish after finalize; editing paid settlements or finalized TDS;
tenant-typed statutory slabs, percentages, ceilings and minimum wages; deleting ledger entries or claims; captcha on
bulk actions; the investment import's admin bypass of validations; referral bonus (recruitment, unless D-PO10);
managers acting on team members' tax declarations (unless D-PO14).
