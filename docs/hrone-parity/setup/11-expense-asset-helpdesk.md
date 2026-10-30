# HROne Setup vs PeopleOS — 11 Expense · 14 Asset · 15 Helpdesk (field by field)

Prepared 2026-10-28, read-only. Recruitment is out of scope.

**Inputs.** HROne control extraction in `scratchpad/hrone/spec/11_Expense.md`, `14_Asset.md`, `15_Helpdesk.md` and
`common-settings-expense-asset-helpdesk.md` (module Settings panels). The page text dumps in `scratchpad/hrone/setup/*.txt` add the
field lists of the Expense form, the Travel plan form and the Inventory form, and the seeded tax names (CGST, SGST, UTGST, IGST).
**PeopleOS** was checked in code: migrations, models, services, Filament resources, `config/peopleos.php` and
`config/peopleos_setup.php`, not only the docs.

**Status key**

- **HAVE**: an admin can configure the same effect today.
- **PARTIAL**: something exists but does not cover the HROne setting. This includes generic engines (Policy Engine, workflows, notification rules) that would cover it once the module declares its policy type or setting.
- **MISSING**: nothing equivalent.
- **N-A**: UI chrome, or not applicable.

*(inferred)* marks a meaning taken from the control name because HROne's label was not captured.

**Conventions used in the build notes** (all from PeopleOS docs and code):

- A setting is a key in `config/peopleos.php` → `settings` (the default) and an entry in the module's `settings` list in `config/peopleos_setup.php`. It is listed only when code reads it.
- A per-group option is a field of a Policy Engine type in `peopleos.policies.types`. Applicability comes from `policy_assignment_rules` and is versioned through `policy_versions.effective_from`.
- New models use `BelongsToTenant` + `Auditable` and are classified in `DataInventory`.
- Wiring goes in `ParityServiceProvider`.
- Permissions are declared in `config/peopleos.php`, then `php artisan peopleos:sync-permissions`.

---

## Summary

| Section | Controls covered | HAVE | PARTIAL | MISSING | N-A |
|---|---:|---:|---:|---:|---:|
| 11 Expense (settings 41, advance policy 6, expense policy 14, categories 39, location group 6, tax 4, currency 6, expense form 12, travel form 12) | 140 | 4 | 27 | 106 | 3 |
| 14 Asset (settings 10, policy 13, type 5, sub-type 5, products 10, inventory form 5) | 48 | 10 | 19 | 18 | 1 |
| 15 Helpdesk (settings 2, ticket categories 6, resolver rules 13, helpdesk policy 6) | 27 | 7 | 18 | 1 | 1 |
| **Total** | **215** | **21** | **64** | **125** | **5** |

Expense is almost entirely MISSING. Its PARTIAL rows are generic engines (Policy Engine applicability, custom fields, attendance data, letters, service desk) that the new module would plug into. Its HAVE rows are the currency rates. Helpdesk has few MISSING rows because nearly every HROne control has a weaker PeopleOS counterpart.

(Counts are the rows of the tables below. Several HROne controls that share one build answer are grouped into one row, as noted in each row.)

**The five biggest gaps**

1. **Expense module.** PeopleOS has no business-expense capture:
   - no expense categories (amount and mileage), limits, location groups, expense taxes or expense/travel form settings;
   - no expense reports with approval;
   - no expense policy type.

   `reimbursement_claims` covers only CTC reimbursement components (`salary_components.pay_on_claim`). Gap register EX-01..EX-04, EX-07, CF-23.
2. **Expense advances, and their payroll and exit money flow.** Advances cannot be requested, dispensed, adjusted against reports or recovered. There is no expense `PayrollInputSource` and no `SettlementSource` (EX-05).
3. **Travel requests.** There are no travel plans, multi-leg trips, booking through the helpdesk, backdated or overlap rules, or travel form fields (EX-06).
4. **Asset entitlement policy and asset requests.** There is no `asset` policy type, no request, approve and fulfil flow, no onboarding/exit due-day automation, and no request visibility rules. There is no third taxonomy level (sub-type), and no per-type owner role or due days (AS-01..AS-03, CF-26).
5. **Helpdesk resolver rules.** There are no named routing rules across categories and sub-categories, no per-rule working days and hours (the SLA `business_hours` column exists but is not editable), no intimation role, no RCA, no per-category feedback, no mandatory-feedback or show-SLA-to-employee switch, and no helpdesk applicability policy (CF-27, HD-02).

---

## 0. PeopleOS building blocks this analysis relies on

| Building block | Where | Relevance |
|---|---|---|
| Policy Engine: `policies`, `policy_versions` (settings JSON, `effective_from`, publish), `policy_assignment_rules` (conditions on company, location, business unit, division, department, team, designation, level, grade, employment type, employee category, work mode, cost centre, gender, lifecycle state, `tenure_months`; operators equals / in / gte / lte …) | `database/migrations/2026_09_26_110003_create_policies_tables.php`, `app/Domain/Configuration/Services/PolicyResolver.php`, `EmployeeRuleContext.php`, `app/Filament/Resources/Policies/PolicyResource.php` (filters type + status) | HROne "policy name / effective date / Applicability / Advance applicability / Review" on every policy page |
| Policy field types: section, `visible_when`, boolean, yes_no, number, select, radio, multiselect, checkboxes, time, tags, textarea, repeater (nested). `options_from`: leave_types, loan_types, shifts, roles, weekdays | `app/Filament/Support/PolicySettingsSchema.php` | New types `expense`, `expense_advance`, `asset` and `helpdesk` need new `options_from` sources (expense_categories, expense_groups, expense_location_groups, asset_categories, asset_subtypes, asset_models, ticket_categories, service_definitions) → HS-EX-B added `expense_categories`, `expense_groups`, `expense_location_groups` and `expense_periods` (both in `PolicySettingsSchema` and `SetupCatalogue`); a repeater may name its rows by the first column's option label (`item_label` => `options`) |
| Setup centre: modules `asset` and `helpdesk` exist with **no settings and no policy types** (links only); there is no `expense` module. Setting types: boolean, number, text, textarea, select, multiselect, roles, date. `SetupCatalogue::optionsFor` sources: leave_types, roles, loan_types | `config/peopleos_setup.php`, `app/Domain/Configuration/Services/SetupCatalogue.php`, `app/Filament/Pages/SetupCentre.php` | Every "Settings" row below lands here → HS-EX-A added module `expense` (settings, both policy types, links to every master, the forms page and exchange rates) and the optional `group` heading of a setting |
| Workflows (approval / condition / task nodes; approvers manager, hierarchy, role, user, field), Approval Center extension `ApprovalSource` (tag `peopleos.approval_sources`), notification rules (event + role audience + conditions) | `config/peopleos.php` → `workflows`, `app/Domain/Experience/Contracts/ApprovalSource.php`, `app/Filament/Resources/NotificationRules` | Exclusive workflows per category or group, intimation roles |
| Money rails: `PayrollInputSource` (tag `peopleos.payroll_inputs`, settled on `payroll.finalized`), `SettlementSource` (tag `peopleos.settlement_sources`). Examples: `ReimbursementClaimInput`, `LoanDisbursementInput`, `LoanRecoveryInput`, `LoanSettlementSource` | `app/Domain/Payroll/Contracts/PayrollInputSource.php`, `app/Domain/Exit/Contracts/SettlementSource.php` (its docblock already names "expense advances"), `app/Providers/ParityServiceProvider.php` | Expense payouts, advance payouts and recoveries, F&F |
| Claims today: `reimbursement_claims` + `reimbursement_ledger_entries` hold claims against **CTC reimbursement components** (FY balance, bills JSON, attachments). There are no categories, limits, mileage, reports, advances or travel | `database/migrations/2026_11_12_100004_create_reimbursement_claim_tables.php`, `app/Domain/Claims/*` | Pattern to copy (bills, attachments, settle on finalize); not the expense module |
| Currency: `exchange_rates` (from, to, rate, `effective_on`, source), `CurrencyRates::toBase()`, `tenant.base_currency` | `app/Domain/Enterprise/Models/ExchangeRate.php`, `app/Domain/Enterprise/Services/CurrencyRates.php`, `app/Filament/Resources/ExchangeRates/ExchangeRateResource.php` (`currency.manage`) | HROne Currency page |
| Locations: `locations.city`, `state_code`, `country_code`. State list `peopleos.compliance.states` | `database/migrations/2026_09_26_090001_create_locations_table.php` | Location groups (by state and city) |
| Assets: `asset_categories` (name, code, `requires_serial`, `is_it_asset`, `default_life_months`, status), `asset_models` (category, manufacturer, name, specifications, status), `assets`, `asset_assignments` (`acknowledged_at`), movements, repairs, disposals. `Assets` service. Custom fields entity `asset`. Exit asset clearance blocks while assets are in custody unless a recoverable amount is entered → `ASSET_RECOVERY` | `database/migrations/2026_09_27_100002_create_asset_tables.php`, `app/Domain/Assets/*`, `app/Domain/Exit/Services/Exits.php` (lines 171-175), `FinalSettlements.php` (line 149) | Asset pages |
| Service desk: `ticket_categories` (name, code, description, `sla_hours`, `first_response_hours`, default agent, assignee role, escalation role, `workflow_key`, status). `service_definitions` (category, `subcategory`) + versions (availability, org_scope, eligibility, lifecycle_states, `assignment.role_id`, `sla_policy_id`, approval, maker-checker). `service_sla_policies` (calendar, targets per priority low/normal/high/urgent in hours, warn %, escalation role, repeat, max level, pause statuses, `business_hours` JSON **not on the form**). `tickets.satisfaction` 1–5 optional at close. `CaseAssignment::initial` (service, then category default agent or role, least loaded) | `database/migrations/2026_09_27_120001_create_service_desk_tables.php`, `2026_10_12_100001_service_catalogue_foundation.php`, `2026_10_12_100002_service_requests_and_cases.php`, `app/Domain/ServiceDesk/*`, `config/peopleos.php` → `servicedesk` | Helpdesk pages |
| Entitlement capabilities: `Assets` (`asset`), `ServiceDesk` (`servicedesk`, `grievance`, `kb`). **No `Expense` capability** | `app/Domain/Entitlements/Enums/Capability.php` | The new module needs one → HS-EX-A added `Capability::Expense` (`expense`, prefix `expense`) |

---

# 11 Expense

PeopleOS has no expense module (gap register: "Expense & Travel — Whole module — HP-4"). Unless a row says otherwise, the "PeopleOS today" column notes only the reusable primitive.

## 11.1 Expense settings (`app-expense-settings`, Setup → Settings → Expense)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** rows 38 and 41 built, plus `expense.year_start_month` (yearly, half-yearly and quarterly limits count from this month; default April). Their keys are added to `peopleos.settings` and Setup → Expense as code starts reading them. The hidden `isGoodsAndServicesTax` switch was not added: tax is per category (`tax_applicable`, `gst_supply_type`).
>
> **Progress (HS-EX-C, 2026-10-29):** rows 2–10, 13, 17–24, 39 and 40 built and listed in Setup → Expense (groups "Expense" and "Workflow"); rows 11–12 built as a seam (the settings and the edit/tolerance rules run on a distance a maps provider supplies; no provider is connected, EXT). Still open: 16 with EX-E (payment step; until then Pay expenses records outside payments), 15 and 25–36 with EX-F (travel), 1, 14, 30, 31, 37 with HP-5 (projects).
>
> **Progress (HS-EX-F, 2026-10-29):** rows 15, 25–29 and 32–36 built (Setup → Expense, group "Travel"; read by
> `TravelRequests`, `ExpenseReports`, `TravelCalendar`). Row 26 is two keys like row 19; row 27 keeps one workflow group
> per trip when off, and a trip mixing groups (when allowed) is decided as one trip in the `all` group. Still open: 30–31
> (project on travel plans) with HP-5, and 1, 14, 37 with HP-5.
>
> **Progress (owner decision 2026-10-29, trips spanning several workflow groups):** row 27 changed as decided: a trip
> mixing groups stays one record (`workflow_group = mixed`) with one approval part per group (`travel_request_parts`,
> migration `2026_12_01_200001`; each part holds its legs and raises its own `expense.travel_requested`, decided by its
> workflow or in the Approval Center as `travel_request_part`); the trip is approved when every part is, rejected when any
> part is. Trips of one group are decided as one, as before.
>
> **Progress (HS-EX-E, 2026-10-29):** row 16 built: `expense.payment.step_required` (Setup → Expense). Default **on** (deviation from the proposed off, so today's behaviour stays: approved reports wait on Pay expenses, which now pays them outside payroll or with payroll); off sends an approved report straight to the next payroll. Also built: `expense.exit.pending_reports` (11.11, default `ignore`). Still open: 15 and 25–36 with EX-F, 1, 14, 30, 31, 37 with HP-5.

Proposed home: Setup module `expense`, keys `expense.*` with these defaults in `peopleos.settings`.

| # | HROne setting (question/label · control · options) | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Enable project based approval · `isProjectBasedApproval` · Yes/No | — (no projects: gap PR-02, PR-06) | MISSING | `expense.approval.project_based` bool, default false. Approver = the project's manager through a workflow `field` approver (`context.project.manager_user_id`). Build with HP-5 projects. Until then the key is not listed (Setup lists only keys code reads). |
| 2 | Expense expiry period (number) · `expenseExpiryPeriod` | — | MISSING → built (HS-EX-C): `expense.expiry.period` (0 = never); daily `peopleos:expense:expire`; adding to a report refuses expired expenses | `expense.expiry.period` number, default 0 (= never). Daily `peopleos:expense:expire` marks unreported receipts `expired`; `ExpenseReports::addReceipt` refuses expired ones. |
| 3 | Expiry unit · `expenseExpiryPeriodType` *(inferred: days / months)* | — | MISSING → built (HS-EX-C): `expense.expiry.unit` (days / months) | `expense.expiry.unit` select `days` / `months`, default `days`. |
| 4 | Expiry "Based on" · `expenseExpiryPeriodBasedOn` | — | MISSING → built (HS-EX-C): `expense.expiry.based_on` (expense date / end of month / end of the expense year) | `expense.expiry.based_on` select `expense_date` / `month_end` / `financial_year_end`, default `expense_date`. |
| 5 | Report submission window on/off · `isExpenseReportSubmissionWindow` | — | MISSING → built (HS-EX-C): `expense.report_window.enabled`; `ExpenseReports::submit` refuses outside the windows, filing on behalf is exempt | `expense.report_window.enabled` bool, default false. `ExpenseReports::submit` refuses outside the window; HR filing on behalf is exempt. |
| 6 | First cycle "From" day · `reportSubmissionFirstCycleStartDay` | — | MISSING → built (HS-EX-C) | `expense.report_window.first_from` number 1–31, default 1. |
| 7 | First cycle "To" day · `reportSubmissionFirstCycleEndDay` | — | MISSING → built (HS-EX-C) | `expense.report_window.first_to` number 1–31, default 31, clamped to the month's length. |
| 8 | "Do you have more than one expense processing cycle?" · `isMoreThanOneExpenseCycle` | — | MISSING → built (HS-EX-C) | `expense.report_window.second_enabled` bool, default false. |
| 9 | Second cycle "From" day · `reportSubmissionSecondCycleStartDay` | — | MISSING → built (HS-EX-C) | `expense.report_window.second_from` number 1–31, default 16. |
| 10 | Second cycle "To" day · `reportSubmissionSecondCycleEndDay` | — | MISSING → built (HS-EX-C): the report keeps its `window_cycle` | `expense.report_window.second_to` number 1–31, default 31. A window may cross the pay-cycle cut-off (`payroll.pay_cycle`); the payroll input uses the approval date, so no coupling is needed. |
| 11 | Allow editing the map-calculated distance · `isAllowGoogleMapEdit` *(inferred)* | — (no maps provider) | MISSING → seam built (HS-EX-C): `expense.mileage.distance_source` (manual / map) and `map_edit_allowed`; a map distance arrives as `computed_distance_km` + `distance_source = map` from a maps provider (EXT, not connected) | `expense.mileage.distance_source` select `manual` / `map`, default `manual`. Plus `expense.mileage.map_edit_allowed` bool, default false. A map distance needs a maps API (EXT: key held under Integration systems); manual distance works without it. |
| 12 | "How much extra percentage do you want to allow?" · `extraPercentage` | — | MISSING → seam built (HS-EX-C): `expense.mileage.map_edit_tolerance_percent` — beyond it the expense is exceptional (with a reason) or refused; works once a maps provider supplies the distance (EXT) | `expense.mileage.map_edit_tolerance_percent` number 0–100, default 10. An edit beyond the tolerance makes the receipt exceptional, or is refused when exceptional expenses are off. |
| 13 | Several months in one report · `isAllowMultipleMonthExpenseReport` | — | MISSING → built (HS-EX-C): `expense.report.multiple_months` | `expense.report.multiple_months` bool, default true. |
| 14 | Several projects in one report · `isAllowMultipleProjectReport` | — | MISSING | `expense.report.multiple_projects` bool, default true. HP-5. |
| 15 | "Do you want to allow multiple travel request in an expense report?" · `isAllowMultipleTravelPlan` | — | MISSING → built (HS-EX-F): `expense.report.multiple_travel` (default off: one trip per report); reports keep `expense_report_travel` in step with their expenses | `expense.report.multiple_travel` bool, default false. Pivot `expense_report_travel`. |
| 16 | Payment step after final approval · `isFinalApprovalPaymentRequired` *(inferred)* | — | MISSING → built (HS-EX-E): `expense.payment.step_required` (default on = as before); Pay expenses → "Pay with payroll"; off schedules approved reports for payroll on approval; `ExpenseReportInput` pays them | `expense.payment.step_required` bool, default false. Off: approved reports go to the next payroll (`ExpenseReportInput`). On: they wait on Expense → Pay expenses, where `expense.pay` holders choose payroll or an outside payment (reference and date). |
| 17 | Workflow split: "No, all expense categories/expense group have same workflow" / "Expense Category" / "Expense Group" · `expenseCategoryBasedOn` | Workflows are generic; there is no expense subject | MISSING → built (HS-EX-C): `expense.workflow.split_by`; one report part per group, each raising `expense.report_submitted` with `workflow_group` in the context | `expense.workflow.split_by` select `none` / `category` / `group`, default `none`. |
| 18 | "Yes, for all expense categories (groups) different workflow" / "Following categories (groups) will have exclusive workflow" *(part of 17/19)* | — | MISSING → built (HS-EX-C): `expense.workflow.split_scope` (all / listed) | `expense.workflow.split_scope` select `all` / `listed`, default `listed`. |
| 19 | Exclusive categories or groups · `conditionalCategory` (placeholders "Select expense category" / "Select expense group") | — | MISSING → built (HS-EX-C) as two keys: `expense.workflow.exclusive_categories` and `expense.workflow.exclusive_groups` (options from the masters) | `expense.workflow.exclusive` multiselect, `options_from` `expense_categories` or `expense_groups` (new sources in `SetupCatalogue::optionsFor`). The trigger event `expense.report_submitted` carries `report.workflow_group`, and a condition node picks the branch. |
| 20 | Several categories (workflow groups) in one report · `isAllowMultipleCategoryReport` | — | MISSING → built (HS-EX-C): `expense.report.mixed_workflow_groups` (`expense_report_parts`; off: one group per report, exceptional expenses always apart) | `expense.report.mixed_workflow_groups` bool, default true. True: the report is split into `expense_report_parts`, each with its own workflow instance, and the report is decided when every part is. False: one group per report. |
| 21 | Check duplicate receipts · `isCheckDuplicateReceipt` | — (claims have no duplicate check) | MISSING → built (HS-EX-C): `expense.duplicates.check`, `ExpenseDuplicateDetector` | `expense.duplicates.check` bool, default true. `ExpenseDuplicateDetector` scores against the employee's and colleagues' receipts: file sha256 = 100%, otherwise weighted on amount, date, merchant and invoice number. |
| 22 | "Threshold percentage" · `receiptDuplicityPercentage` | — | MISSING → built (HS-EX-C): `expense.duplicates.threshold_percent`; flag `suspected_duplicate` shown to approvers and in the Approval Center | `expense.duplicates.threshold_percent` number 1–100, default 80. At or above it the receipt is flagged `suspected_duplicate` and shown to the approver; an identical file is refused. |
| 23 | Allow editing travel distance · `isEditTravelDistance` | — | MISSING → built (HS-EX-C): `expense.mileage.distance_editable` (odometer and map distances) | `expense.mileage.distance_editable` bool, default true. |
| 24 | Show expense payment details to the employee · `isShowExpensePaymentDetail` | — | MISSING → built (HS-EX-C): `expense.payment.show_to_employee` (payment section of My expense reports) | `expense.payment.show_to_employee` bool, default true. My expense reports shows the payroll month and payslip line, or the outside payment reference. |
| 25 | Travel workflow split basis · `travelCategoryBasedOn` | — | MISSING → built (HS-EX-F): `expense.travel.workflow.split_by` (+ `split_scope`); the trip carries its workflow group in `expense.travel_requested` | `expense.travel.workflow.split_by` select `none` / `category` / `group`, default `none`. |
| 26 | Travel exclusive categories · `travelConditionalCategory` | — | MISSING → built (HS-EX-F) as two keys: `expense.travel.workflow.exclusive_categories` / `exclusive_groups` | `expense.travel.workflow.exclusive` multiselect (`expense_categories` / `expense_groups`). |
| 27 | Several travel categories in one travel request · `isAllowMultipleTravelCategoryReport` | — | MISSING → built (HS-EX-F): `expense.travel.mixed_categories` (off: one workflow group per trip) → owner decision 2026-10-29 built: a mixed trip is one record approved by every group, one `travel_request_parts` row per group (its legs, its own workflow run or Approval Center item); approved when every group has approved, rejected when any rejects | `expense.travel.mixed_categories` bool, default true. |
| 28 | Travel: backdated request allowed, "Days" · `backdatedTravelDays` | — | MISSING → built (HS-EX-F): `expense.travel.backdated_days` (filing on behalf exempt) | `expense.travel.backdated_days` number 0–365, default 0. |
| 29 | Multi-leg or multiple travel · `isMultipleTravelRequired` | — | MISSING → built (HS-EX-F): `expense.travel.multi_leg` (off: one journey and one stay) | `expense.travel.multi_leg` bool, default true (`travel_request_legs`). |
| 30 | Project visible on travel plan · `isProjectVisibleOnTravelPlan` | — | MISSING → open: HP-5 (projects) | `expense.travel.project` select `hidden` / `optional` / `required` (covers 30 and 31). HP-5. |
| 31 | Project required on travel plan · `isProjectRequiredOnTravelPlan` | — | MISSING → open: HP-5 (projects) | Same key as row 30. |
| 32 | Advance inside the travel request · `isAllowAdvanceInTravelRequest` | — | MISSING → built (HS-EX-F): `expense.travel.advance_allowed`; the advance (`expense_advances.travel_request_id`) is approved, rejected and cancelled with the trip | `expense.travel.advance_allowed` bool, default true. Creates an `expense_advances` row linked to the trip and decided with it. |
| 33 | Show travel on the attendance calendar · `IsShowTravelOnAttendanceCalendar` | `app/Filament/Pages/AttendanceCalendar.php` (month grid; no travel) | PARTIAL → built (HS-EX-F): `expense.travel.on_attendance_calendar` (`TravelCalendar`, read-only mark) | `expense.travel.on_attendance_calendar` bool, default false. Read-only overlay of approved travel days. The attendance effect stays with on-duty requests. |
| 34 | Delay reason for backdated travel · `isShowBackdatedTravelDelayReason` | — | MISSING → built (HS-EX-F): `expense.travel.backdated_reason_required` | `expense.travel.backdated_reason_required` bool, default true. |
| 35 | Restrict overlapping travel · `isRestrictOverlapTravel` | — | MISSING → built (HS-EX-F): `expense.travel.block_overlap` (under the employee row lock) | `expense.travel.block_overlap` bool, default true. Uses an employee row lock, as `TimeOfficeRequests` does. |
| 36 | Allow overlap when one trip ends the day the next starts · `isAllowSameEndStartDateTravelOverlap` | — | MISSING → built (HS-EX-F): `expense.travel.same_day_turnaround` | `expense.travel.same_day_turnaround` bool, default true. |
| 37 | Project on expense advance · `isExpenseAdvanceProject` | — | MISSING | `expense.advance.project` select `hidden` / `optional` / `required`. HP-5. |
| 38 | Exceptional expense allowed · `isAllowExceptionalExpenses` | — | MISSING → built (HS-EX-B): `expense.exceptional.enabled` (Setup → Expense), read by `ExpenseLimits`; the exceptional part and `expense.exceptional_submitted` built (HS-EX-C) | `expense.exceptional.enabled` bool, default false. Exceptional receipts fire `expense.exceptional_submitted` (HROne has a separate "Exceptional expense request" workflow). |
| 39 | Expense cover letter download · `isAllowExpenseCoverLetterDownload` | Letters engine (`letter_templates`, HTML/DOCX) with no expense letter type | PARTIAL → built (HS-EX-C): `expense.report.cover_letter`, letter type `expense_cover`, "Download cover letter" on the report | `expense.report.cover_letter` bool, default false. New letter type `expense_cover` in `peopleos.letters.types` with report variables (lines, totals, advance adjusted, signatures); "Download cover letter" on the report. |
| 40 | Allow duplicate invoice number · `isAllowDuplicateInvoiceNo` | — | MISSING → built (HS-EX-C): `expense.duplicates.same_invoice_number` (refuse / flag / allow, same merchant) | `expense.duplicates.same_invoice_number` select `refuse` / `flag` / `allow`, default `flag`, within the same merchant. |
| 41 | Section headings "Expense", "Travel", "Expense advance", "Exceptional expense", "Miscellaneous", "Update" | `SetupCentre` lists a module's settings flat, with one save | N-A → built (HS-EX-A): settings may carry a `group`; Setup → Expense shows headed blocks | Optional `group` key on setup settings, so long modules show headed blocks. |

HROne also returns these general expense settings from its API that the screen did not show (feature inventory §10): `isShowSuspectButton`, `isBulkEditAmount`, `isBulkEditMileage`, `isExceptionalWorkflow`, `isIntegrateWithHelpdesk` + `subcategoryId` + `forwardEmailId`, `isGoodsAndServicesTax`, `isAllowTextBoxLocation`, `isPreferredTimeOff`, `defaultReceiptDuplicityPercentage`. Suggested covers:

- suspect flag: rows 21/22;
- bulk approver edits: `ExpenseReports::approve` takes per-line approved amounts;
- `expense.gst.enabled` bool, default true for IN tenants;
- text-box location: category `location_input = text`;
- helpdesk integration: category `booking_service_id`, see 11.4;
- email-to-expense: EXT, like HD-03.

## 11.2 Expense advance policy (`core/expense/advancepolicy`)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** every row closed; row 6 is enforced when advance requests exist (EX-D).
>
> **Progress (HS-EX-D, 2026-10-29):** advances exist and enforce the policy: no policy / not offered (row 6, HROne's message), largest advance, open advances at a time, most outstanding, purpose, attachment, cancellation approval, payout channel and settle-within days. Recovery (`recovery`, `recovery_after_days`) is read by EX-E.
>
> **Progress (HS-EX-E, 2026-10-29):** every field is now enforced: `disbursement = payroll` advances are paid by the next payroll (`ExpenseAdvancePayoutInput`); `recovery = payroll` sets `recovery_due_on` (settle-by + `recovery_after_days`) when an advance is dispensed and payroll recovers what is outstanding from then (`ExpenseAdvanceRecoveryInput`); `exit_only` recovers only in the final settlement; `none` is never recovered automatically (not even at exit). Advances dispensed before EX-E have no recovery date and are not recovered by payroll.

Captured: wizard steps (Advance policy details, Applicability, Advance applicability, Review), filter (Policy, Status) and the empty state "Expense advance policy needs to be configured to enable employee to request advance for work related expenses". The step's inner controls were not captured, so the fields proposed for row 1 are *(inferred)* from HROne's advance features (request, cancel, dispense, settle, recover; report "Outstanding expense advance").

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Advance policy details (step) | Policy Engine is generic; the `loan` type is the nearest analogue (max amount, open loans, payout by payroll or bank) | MISSING → built (HS-EX-B): policy type `expense_advance` (sections Advance requests, Payout, Settlement and recovery), read through `ExpenseAdvanceRules` | New type **`expense_advance`** in `peopleos.policies.types`. Fields:<br>• `enabled` bool;<br>• `max_amount` number (0 = no limit);<br>• `max_open` number, default 1;<br>• `max_outstanding_total` number (0 = no limit);<br>• `settle_within_days` number, default 30 (after the trip ends, or after the advance is dispensed when there is no trip);<br>• `recovery` select `none` / `payroll` / `exit_only`, default `payroll`;<br>• `recovery_after_days` number, default 15 (after the settle-by date);<br>• `disbursement` select `payroll` / `bank`, default `bank`;<br>• `purpose_required` bool;<br>• `attachment_required` bool;<br>• `cancellation_needs_approval` bool, default true.<br>Policy name and description are `policies.name` / `description`; the effective date is `policy_versions.effective_from`. |
| 2 | Applicability (`app-ou-applicability`, "Search here") | `policy_assignment_rules` | PARTIAL → HAVE (the type is declared; the policy wizard asks applicability and advance applicability) | The engine exists; it works once the type is declared (rules on company, location, department, grade, …). |
| 3 | Advance applicability (Optional) · `from`, `to` | Rule conditions (`gte` / `lte`, `tenure_months`) and rule `effective_from` / `effective_to` | PARTIAL → HAVE (the type is declared; the policy wizard asks applicability and advance applicability) | HROne's from/to range maps to two conditions; no code needed. |
| 4 | Review | Version publish (draft → published, change note) | PARTIAL → HAVE (the type is declared; the policy wizard asks applicability and advance applicability) | Generic. |
| 5 | Filter "Policy" + "Status" | `PolicyResource` filters by type and status | PARTIAL → built (HS-EX-B): Setup → Expense lists "Expense advance policy" with Open (the policy list filtered by type: the link now uses the list's `filters` parameter) and Add policy | Filter `type = expense_advance`. Add the Setup link "Expense advance policies" (policy list filtered by type, as the Setup centre does for its types). |
| 6 | Empty-state rule: no policy → no advance requests | — | MISSING → seam built (HS-EX-B): `ExpenseAdvanceRules::refusal()` gives HROne's message when no policy or `enabled` is no; requests (EX-D) call it | `ExpenseAdvances::request` refuses when no `expense_advance` version resolves for the employee on the date, or `enabled` is false. |

## 11.3 Expense policy (`core/expense/expensepolicy`)

> **Progress (HS-EX-C, 2026-10-29):** receipts enforce the policy: categories offered, exceptional-only, every override and the limit across all categories (`ExpenseReceipts::capture`).
>
> **Progress (HS-EX-A/EX-B, 2026-10-29):** every row closed. Policy type `expense`: categories repeater (category shown as "name · type · group", exceptional-only, overrides of limit, distance, period, location-wise amounts, receipt threshold, frequency count, out-of-limit handling) and the limit across all categories. `ExpensePolicies::categoriesFor()` and `ExpenseLimits::for()` read it; receipts (EX-C) enforce them.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy grid: name, ACTIVE, "Categories ##", "Applicable On", SHOW MORE, "NO EXPENSE POLICY" | `PolicyResource` list (name, code, type, current version, rules count, status) | PARTIAL → built (HS-EX-B): column "Categories" on the policy list filtered to expense policies | Works for type `expense`. Add a "Categories" count shown when type = expense (from the current version's settings). |
| 2 | ADD POLICY wizard (Previous / Next / SAVE): Expense details → Expense categories → Advance Settings → Applicability → Advance applicability → Review | Policy create → draft version → rules → publish | PARTIAL → built: the step-by-step policy wizard (Policy → Expense categories → Limit across all categories → Applicability → Advance applicability → Review) | Generic forms, no wizard. Acceptable in PeopleOS style. |
| 3 | Expense policy name · `expensePolicyName` | `policies.name` | PARTIAL → HAVE (type `expense`) | New type **`expense`** ("Expense policy"). |
| 4 | Effective date · `withEffectiveDate` | `policy_versions.effective_from` | PARTIAL → HAVE (type `expense`) | Generic. |
| 5 | Select categories / Selected categories / "+ ADD" / "No categories selected" | — | MISSING → built (HS-EX-B) | Field `categories`: repeater, first column `category` select `options_from` `expense_categories` (active only). An expense receipt can only use categories of the employee's resolved `expense` policy version on the expense date. |
| 6 | Columns "Expense type", "Expense group" | — | MISSING → built (HS-EX-B) | Read-only display from the master: the repeater item label shows "name · type · group". |
| 7 | "Exceptional category" (per selected category) | — | MISSING → built (HS-EX-B) | Repeater field `exceptional_only` bool: the category can be claimed only as an exceptional expense. Needs `expense.exceptional.enabled`. |
| 8 | Category edited inside the policy ("Note: Changes made here will not effect the main settings of categories") | — | MISSING → built (HS-EX-B) | Repeater override fields (blank = category default):<br>• `limit_amount`;<br>• `km_limit`;<br>• `limit_period`;<br>• `location_limits` (nested repeater: `location_group` `options_from` `expense_location_groups`, `amount`);<br>• `receipt_above`;<br>• `claim_frequency_count`;<br>• `limit_exception_allowed`.<br>Resolved by `ExpenseLimits::for($employee, $category, $date, $locationGroup)`: policy override, then category limit (most specific), then none. |
| 9 | "Do you want to apply limit across all categories?" · `isOverallCategoryLimit` · Yes/No | — | MISSING → built (HS-EX-B) | `overall_limit_enabled` bool. |
| 10 | "Amount" · `overallCategoryLimit` | — | MISSING → built (HS-EX-B) | `overall_limit_amount` number, `visible_when` row 9. |
| 11 | "Frequency" · `expenseCategoryFrequencyTypeId` | — | MISSING → built (HS-EX-B) | `overall_limit_period` select from `peopleos.expense.periods` (`per_claim` / `day` / `week` / `month` / `quarter` / `half_year` / `year` / `trip`). |
| 12 | Applicability (`app-ou-applicability`) | Assignment rules | PARTIAL → HAVE (type `expense`) | Generic. |
| 13 | Advance applicability (Optional) · `from`, `to` | Rule conditions | PARTIAL → HAVE (type `expense`) | Generic. |
| 14 | Review (name, effective date, selected categories, advance settings, overall limit, amount, frequency) | Version view (`PolicySettingsSchema::entry`) | PARTIAL → HAVE (type `expense`) | Generic read-only view. |

## 11.4 Expense categories (`app-expense-category-form`, mileage `app-mileage` and amount `app-amount`)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** every rule is stored and validated (`ExpenseCategories`, Expense & travel → Expense categories); capture-time checks (attendance, tenure window, before joining, declaration, receipts, frequency, hourly bands, auto-filled hours, odometer) run in EX-C, travel links and booking in EX-F. Deviations from 11.10: the employee dimension limits vary by is one per category (`limit_dimension`, as HROne's "For employees" select), and the percentage of CTC is per limit row (`percent`, of the category's `ctc_component_id` or the total CTC) so it can vary by grade and location group. Row 12: the percentage option is always offered — entitlements are shadow-only and HCM may not read them (architecture rule SaaS.5); with no approved compensation the limit is 0 and flagged. Row 11 `map`: stored, typed in until a maps provider (EXT) exists.
>
> **Progress (HS-EX-F, 2026-10-29):** rows 28–31 enforced: a travel plan category's expense needs an approved trip covering
> its date and a travel reference one may point at one (`ExpenseReceipts`); the travel form decides whether a leg is a
> journey or a stay; on approval company legs are raised as helpdesk requests in the booking service (else a ticket
> category of purpose travel / accommodation booking) and the travel desk records the bookings (`TravelBookings`);
> `booked_by` either lets the employee choose per leg. Per-trip limit periods count the trip's own expenses.
>
> **Progress (HS-EX-C, 2026-10-29):** every capture-time rule is enforced by `ExpenseReceipts` (rows 6–10, 13–25, 32–38): limits by dimension and location group through `ExpenseLimits`, percentage of CTC, period usage (`ReceiptUsage`), hourly bands with hours auto-filled from attendance, out-of-limit as exceptional, description, receipt above an amount, odometer readings with two photos, from/to places, claim frequency, tax lines (row 26–27), before joining, attendance (present / minimum time / on duty, any or named types), filing window, declaration. Reimbursable = false lines are approved but not repaid (`net_payable`). Still open: rows 28–31 (travel link, travel form, booking task, who books) are enforced with travel requests (EX-F); a plan-linked category is accepted without a trip until then.

Grid: "Expense categories, N ITEMS, name, ACTIVE, AMOUNT/MILEAGE badge". Proposed table `expense_categories` (11.10), `ExpenseCategoryResource` (`expense.manage`).

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Expense type: Mileage / Amount (radio) | — | MISSING → built (HS-EX-A) | `expense_categories.type` enum `amount` / `mileage`; the form branches on it. |
| 2 | Expense category Name · `expenseCategoryName` | — | MISSING → built (HS-EX-A) | `name` string(120), required. |
| 3 | Expense group (label; master `expenseGroupName`) | — | MISSING → built (HS-EX-A) | `expense_group_id` FK, nullable, to new master `expense_groups` (name, code, sort_order, status) with `ExpenseGroupResource`. |
| 4 | Expense code · `expenseCode` | — | MISSING → built (HS-EX-A) | `code` string(32), alpha-dash, unique per tenant, immutable after create (as other masters). Also the payroll line suffix in reports. |
| 5 | Category description · `categoryDescription` | — | MISSING → built (HS-EX-A) | `description` text. |
| 6 | Mileage: Rate per KM · `ratePerKilometer` | — | MISSING → built (HS-EX-A) | `rate_per_km` decimal(10,4). Claimable = distance × rate. Vehicle variants are separate categories (2-wheeler / 4-wheeler). |
| 7 | Mileage: "Do you want to specify distance limit (KM)" / Amount: "Choose Type" · `categoryLimitTypeId` | — | MISSING → built (HS-EX-A) | `limit_basis` enum `none` / `amount` / `percent_of_ctc` (amount type) or `none` / `km` (mileage). |
| 8 | Mileage: "Specify Limit" (KM) · `kilometerLimit` | — | MISSING → built (HS-EX-A) | `expense_category_limits.km_limit`. |
| 9 | "KM limit for" / "Amount limit for" (employee dimension, label "For employees") | — | MISSING → built (HS-EX-A) | `expense_category_limits.dimension` (one of `EmployeePosition::DIMENSIONS`, e.g. `grade_id`, `designation_id`, `level_id`, `employee_category_id`, null = everyone) + `dimension_value_id`. A grade × city-tier matrix is rows of this table. |
| 10 | "Do you want to calculate distance as per odometer?" · `isOdometerRequired` | — | MISSING → built (HS-EX-A) | `odometer_required` bool. The receipt then needs `odometer_start`, `odometer_end` and two photos; distance = end − start. |
| 11 | "Location input type" · `locationInputType` | — | MISSING → built (HS-EX-A) | `location_input` select `none` / `text` / `map` (map = EXT, see 11.1 row 11). Mileage receipts store `from_place` / `to_place`. |
| 12 | Amount: "Choose Type": Amount / "Percentage(%) of CTC" ("Paid feature: needs payroll") · `amountType` | — | MISSING → built (HS-EX-A) | Same enum as row 7 (`amount` / `percent_of_ctc`). Only offered when the tenant has the Payroll capability (`Capability::Payroll`). |
| 13 | "Specify limit amount" · `amountLimit` (+ "Specify expense limit") | — | MISSING → built (HS-EX-A) | `expense_category_limits.amount` decimal(14,2), in base currency. |
| 14 | "Percentage" "of" "select CTC pay component" · `percentage`, `payCode` | Salary structures and components exist (`salary_components`); no expense reader | PARTIAL → built (HS-EX-A) | `percent_of_ctc` decimal(5,2) + `ctc_component_id` FK `salary_components` (null = total CTC). The limit = % × the employee's annual amount of that component on the expense date, read through the compensation/payroll reader (no new salary logic). |
| 15 | Location-wise limits · `locationWiseCategoryAmountLimitDetails` + "Location group" · `expenseLocationGroupId` (add_circle_outline) | — | MISSING → built (HS-EX-A) | `expense_category_limits.expense_location_group_id` (null = every other place). The receipt's place (city + state) resolves to a group (11.5). |
| 16 | "Limit time period" · `categoryFrequencyTypeId` | — | MISSING → built (HS-EX-A) | `expense_category_limits.period` from `peopleos.expense.periods`, default `per_claim`. Usage is summed over approved and pending receipts in the period. |
| 17 | "Do you want to set hourly constraints for daily allowance?" · `isEnableHourlyConstraint` | — | MISSING → built (HS-EX-A) | `hourly_constraints_enabled` bool (amount type). |
| 18 | Hourly bands: From hours/minutes, To hours/minutes, Percentage · `hourlyConstraintDetails`, `fromStartHours`, `fromStartMintues`, `toEndHours`, `toEndMintues`, `percentage` | — | MISSING → built (HS-EX-A) | `hourly_constraints` JSON `[{from_minutes, to_minutes, percent}]`. The daily-allowance limit = limit × percent of the band holding the hours (worked hours, or trip hours from travel legs). Validation: bands do not overlap and to > from. |
| 19 | "Do you want the system to auto-fill employee's working hours?" · `isAutoFillWorkingHours` | `attendance_records` worked minutes | PARTIAL → built (HS-EX-A) | `auto_fill_worked_hours` bool: the receipt pre-fills hours from the day's `AttendanceRecord` (read only). |
| 20 | "Do you want user to file expense as exceptional when out of limit?" · `isAllowLimitException` | — | MISSING → built (HS-EX-A) | `limit_exception_allowed` bool. Over the limit: when true and `expense.exceptional.enabled`, the receipt becomes `is_exceptional` with a reason and goes to the exceptional workflow; otherwise the claimable amount is capped at the limit (`over_limit_amount` recorded). |
| 21 | Description required · `isDescriptionRequired` | — | MISSING → built (HS-EX-A) | `description_required` bool. |
| 22 | Receipt required · `isReceiptRequired` | `payroll.claims.attachment_required` applies to CTC claims only | MISSING → built (HS-EX-A) | `receipt_required` bool. Files on the private `documents` disk (sha256 stored for the duplicate check). |
| 23 | "Min expense limit for receipt" · `isMinLimitForReceipt`, `minLimitForReceipt` | — | MISSING → built (HS-EX-A) | `receipt_required_above` decimal nullable: a receipt is needed only above this amount. |
| 24 | Reimbursable · `isReimbursable` | — | MISSING → built (HS-EX-A) | `reimbursable` bool, default true. False = company-paid (booked tickets, corporate card): recorded and approved, never paid to the employee. |
| 25 | "Claim frequency" + "Claim frequency count" · `claimFrequencyTypeId`, `claimFrequency` | — | MISSING → built (HS-EX-A) | `claim_frequency_period` (periods enum) + `claim_frequency_count` int: most receipts per period. |
| 26 | GST: "Is tax applicable" · `isTaxApplicable` | — | MISSING → built (HS-EX-A) | `tax_applicable` bool: the receipt shows tax lines (`expense_receipt_taxes`, tax types from 11.6), supplier GSTIN and invoice number. |
| 27 | "GST type": Goods / Services · `goodsAndServicesTaxType` | — | MISSING → built (HS-EX-A) | `gst_supply_type` select `goods` / `services`, nullable. |
| 28 | "Do you want to link it with Pre-travel request?": None / Travel reference / Travel plan · `preTravelRequest` | — | MISSING → built (HS-EX-A); enforced (HS-EX-F): plan = an approved trip covering the date, reference = optionally an approved trip | `travel_link` enum `none` / `reference` / `plan`. `reference` lets the receipt point to an approved trip; `plan` requires one and checks the date is within it. |
| 29 | "Link travel plan with": Accommodation booking form / Travel booking form · `travelPlanFor` | — | MISSING → built (HS-EX-A); enforced (HS-EX-F): the leg kind (journey or stay) follows it | `travel_form` select `travel` / `accommodation`: which travel-form section the category uses (11.9). |
| 30 | "Do you want to assign a task to a travel booking agent through helpdesk?" · `isTravelHelpdeskTicket` + "Sub category" · `subcategoryId` | Service desk services with categories (`service_definitions`), `ServiceRequests` | PARTIAL → built (HS-EX-A); enforced (HS-EX-F): `TravelBookings` raises the booking request on approval (`ServiceRequests::raiseForProcess`, source system) | `booking_service_id` FK `service_definitions`, nullable. On trip approval, `TravelBookings` opens a service request (category purpose `travel_booking`, see 15.2) for the travel desk; booking details come back on the leg. |
| 31 | "Who is responsible to book travel for this category?": Always employee / Always company / Employee or company · `ticketBookedBy` | — | MISSING → built (HS-EX-A); enforced (HS-EX-F): company legs go to the travel desk, `either` lets the employee choose on the leg | `booked_by` enum `employee` / `company` / `either`. Company-booked lines default to `reimbursable = false`. |
| 32 | "Do you want to allow the user to file expenses before the date of joining?" · `isBeforeDateOfJoiningAllowed` | — | MISSING → built (HS-EX-A) | `before_joining_allowed` bool, default false. |
| 33 | "Do you want to restrict the employee to apply expenses based on their attendance?" · `isBasedOnAttendance` | `attendance_records` (status, worked minutes); on duty via `time_office_requests` / regularisation types `on_duty`, `field_duty` | PARTIAL → built (HS-EX-A) | `attendance_rule` JSON `{mode: none / present / min_hours / on_duty}`. Checked by `ExpenseReceipts` through the attendance read side (no attendance writes). |
| 34 | Minimum working Hours / Minutes · `minimumWorkingHours`, `minimumWorkingMinutes` | Worked minutes exist | PARTIAL → built (HS-EX-A) | `attendance_rule.min_minutes`. |
| 35 | "Select on duty type": Specific / Any · `onDutyType` + "Select on Duty" · `onDutyMasterId` | On duty exists as a request type; no on-duty type master | PARTIAL → built (HS-EX-A) | `attendance_rule.on_duty = any / specific` + `on_duty_kinds` multiselect (`on_duty`, `field_duty`, time-office OD duration modes). An on-duty type master would be a Time Office item. |
| 36 | "Do you also want from location to be captured?" · `isFromLocationRequired` (amount) | — | MISSING → built (HS-EX-A) | `from_location_required` bool. |
| 37 | "Do you want to set a tenure for expense submission?" · `isTenureAllowed` + "From Days" / "To Days" · `tenureFromDays`, `tenureToDays` ("To Days must be greater than From Days") | — | MISSING → built (HS-EX-A) | `submission_window_days` JSON `{from, to}`: a receipt may be filed only between from and to days after the expense date. Validated to > from. |
| 38 | "Do you want a declaration on category?" · `isDeclarationRequired` + "Declaration" · `declarationMessage` | — | MISSING → built (HS-EX-A) | `declaration_required` bool + `declaration_text` text. The receipt stores `declaration_accepted_at`. |
| 39 | INACTIVATE / EDIT | — | MISSING → built (HS-EX-A) | `status` ActiveStatus. Inactive categories stay on old receipts and policies but cannot be chosen. |

## 11.5 Location group (`core/expense/locationgroup`)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** every row closed (Expense & travel → Location groups; `ExpenseLocationGroups::resolve()` matches a city before its state; a place belongs to one group).

Page help: "Location groups are used in order to specify different expense limits for different city groups. You may create Tier 1, Tier 2 and Tier 3 location groups."

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Location group name · `locationGroupName` | — (`locations` are the employer's own sites, not expense places) | MISSING → built (HS-EX-A) | `expense_location_groups` (tenant_id, name, code, sort_order, status) + `ExpenseLocationGroupResource`. |
| 2 | States · `stateCodes` (label "Location group details", compareWith) | State codes `peopleos.compliance.states` (IN), `locations.state_code` | PARTIAL → built (HS-EX-A) | `expense_location_group_places` (group_id, `country_code` default IN, `state_code`, `city` null = whole state). State options from `peopleos.compliance.states` for IN, free text elsewhere. |
| 3 | Cities ("Search here") | `locations.city` is free text; no city master | PARTIAL → built (HS-EX-A) | `expense_location_group_places.city` (TagsInput per state). Match on normalised lower-case name. The most specific match wins (city before state). A place in no group uses limits with null group ("Other"). |
| 4 | Review (step) | — | N-A | The resource form shows the places summary. |
| 5 | Grid "TRAVEL ACTIVE · ## States · ## Cities" | — | MISSING → built (HS-EX-A) | Table columns: name, states count, cities count, status. |
| 6 | Filter "Location group" + "Status" (Active / Inactive) | — | MISSING → built (HS-EX-A) | `SelectFilter` name and status. |

## 11.6 Tax (`core/expense/expensetax`)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** every row closed. The four Indian heads are seeded for IN tenants (names only, no rates); older tenants use "Add the standard tax types".

Page help: "Tax types need to be created so that user can submit tax bifurcation at time of expense submission." Seeded: UTGST, IGST, SGST, CGST.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Tax name · `expenseTaxName` ("ADD TAX") | — The `App\Domain\Tax` / `InvoiceTaxLine` GST support is **platform billing**, not tenant data, and must not be reused | MISSING → built (HS-EX-A) | `expense_tax_types` (tenant_id, name, code, `default_rate` decimal nullable, sort_order, status) + `ExpenseTaxTypeResource`. |
| 2 | Status (Active / Inactive) | — | MISSING → built (HS-EX-A) | `status`. |
| 3 | Seeded list CGST, SGST, UTGST, IGST | — | MISSING → built (HS-EX-A) | `config('peopleos.expense.defaults.tax_types')`, seeded for IN tenants by `ExpenseDefaults::seed()` (as `AssetDefaults` / `ServiceDeskDefaults`). |
| 4 | Filter "Select tax" + "Status" | — | MISSING → built (HS-EX-A) | Table filters. |

## 11.7 Currency (`core/expense/expensecurrency`)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** every row closed; receipts convert with `CurrencyRates::toBase` in EX-C.
>
> **Progress (HS-EX-C, 2026-10-29):** row 6 done: a receipt keeps `currency`, `amount`, `exchange_rate` and `base_amount` (rate of the expense date); with no rate it is refused, never guessed. Advances convert at the request date.

Page help: "Currency conversation rates need to be created to convert expenses in base currency."

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy name · `policyName` | `exchange_rates` rows are individually dated; no named rate set | PARTIAL → closed: not needed (rates are dated; the bulk action below enters one date for many currencies) | Not needed: PeopleOS reads the rate effective on the expense date. Optionally tag expense-entered rates `source = expense`. |
| 2 | Effective date · `effectiveDate` | `exchange_rates.effective_on` | HAVE | — |
| 3 | Exchange rate details (repeater) · `exchangeRateDetails` | One rate per row in `ExchangeRateResource` | PARTIAL → built (HS-EX-A): Exchange rates → "Add several rates" (one effective date, many currencies, into the base currency) | Optional "Add several rates" bulk action (one date, many currencies). |
| 4 | Exchange currency · `foreignCurrencyCode` | `exchange_rates.from_currency` | HAVE | — |
| 5 | Rate · `rate` | `exchange_rates.rate` (8 decimals) | HAVE | — |
| 6 | Base Currency · `baseCurrencyCode` | `exchange_rates.to_currency`, `tenant.base_currency` (`CurrencyRates::baseCurrency()`) | HAVE → Setup link built (HS-EX-A); the receipt's conversion is EX-C | The receipt stores `currency`, `amount`, `exchange_rate` and `base_amount` (`CurrencyRates::toBase` on the expense date). With no rate the receipt is refused, and the rate is never guessed. Add a Setup link to Exchange rates in the expense module. |

## 11.8 Expense form (`core/expense/expense-form`, `app-fields`)

> **Progress (HS-EX-C, 2026-10-29):** the matrix is enforced on capture (`ExpenseForms` fields mandatory in the category's section; hidden fields are not stored) and the receipt columns exist (rows 6, 7, 9–12: `purpose`, `location_city` / `location_state` → `expense_location_group_id`, `participants`, `merchant`, `invoice_number`, custom fields of `expense_receipt`). Row 8 waits for projects (HP-5).
>
> **Progress (HS-EX-A/EX-B, 2026-10-29):** rows 1–7 and 9–12 closed at setup level; row 8 waits for projects (HP-5). Per-section visibility of custom fields is kept in `expense.form.fields` (key `custom:<field key>`), not in the custom field's `validation` JSON, because that JSON is merged into the field's Laravel validation rules.

Page help: "Expense form settings let you change label names and decide if they're required or optional." Columns: Visible on Mileage, Visible on Amount, Mandatory on Mileage, Mandatory on Amount, Visible on Accommodation, Visible on Travel details, Mandatory on Accommodation, Mandatory on Travel details, Rename Label. Rows: Purpose, Location, Project, Other Participant, Merchant, Invoice number, Field one … Field ten.

The field matrix is stored in setting `expense.form.fields` (array keyed by field: `{label, visible: [amount, mileage, accommodation, travel], mandatory: [...]}`), edited on a page `App\Filament\Pages\ExpenseFormSetup` (like `PayCyclePage`) and saved through `SettingsRepository::set` with a reason, so it is audited. The defaults in `peopleos.expense.form_fields` reproduce HROne's standard set.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Field list · `fieldsName` | — | MISSING → built (HS-EX-A): `ExpenseForms` (setting `expense.form.fields`), page Expense & travel → Expense and travel forms; `ExpenseForms::missing()` is what EX-C enforces | Standard keys: `purpose`, `location`, `project`, `participants`, `merchant`, `invoice_number`, as columns on `expense_receipts`. |
| 2 | Visible on Mileage / Amount · `isEnable`, `isVisibleOnAmount` | — | MISSING → built (HS-EX-A): `ExpenseForms` (setting `expense.form.fields`), page Expense & travel → Expense and travel forms; `ExpenseForms::missing()` is what EX-C enforces | `visible` per expense type; the receipt form hides fields not visible for the category's type. |
| 3 | Mandatory on Mileage / Amount · `isMandatory`, `isMandatoryOnAmount` | — | MISSING → built (HS-EX-A): `ExpenseForms` (setting `expense.form.fields`), page Expense & travel → Expense and travel forms; `ExpenseForms::missing()` is what EX-C enforces | `mandatory` per type, enforced in `ExpenseReceipts::capture` (server side, not only the form). |
| 4 | Visible / Mandatory on Accommodation and Travel details · `isVisibleOnAccomodation`, `isMandatoryOnAccomodation`, `isVisibleOnTravel`, `isMandatoryOnTravel` (+ `isDisabledForTravel`) | — | MISSING → built (HS-EX-A): `ExpenseForms` (setting `expense.form.fields`), page Expense & travel → Expense and travel forms; `ExpenseForms::missing()` is what EX-C enforces | Same matrix for the travel-booking and accommodation receipt sections (11.4 row 29). |
| 5 | Rename Label · `displayName` | — | MISSING → built (HS-EX-A): `ExpenseForms` (setting `expense.form.fields`), page Expense & travel → Expense and travel forms; `ExpenseForms::missing()` is what EX-C enforces | `label` per field, used on forms, reports and exports. |
| 6 | Purpose | — | MISSING → label and placement built (HS-EX-A); the receipt column is EX-C | `expense_receipts.purpose` string(500). |
| 7 | Location | — | MISSING → label and placement built (HS-EX-A); the receipt column is EX-C | `location_city` + `location_state` → `expense_location_group_id` (resolved and stored). |
| 8 | Project | — | MISSING → open: HP-5 (projects); not in the matrix until projects exist | `project_id`, nullable. HP-5; hidden until projects exist. |
| 9 | Other Participant | — | MISSING → label and placement built (HS-EX-A); the receipt column is EX-C | `participants` JSON (employee ids and/or names); shared receipts can be split per participant later. |
| 10 | Merchant | — | MISSING → label and placement built (HS-EX-A); the receipt column is EX-C | `merchant` string(160) (duplicate-check input). |
| 11 | Invoice number | — | MISSING → label and placement built (HS-EX-A); the receipt column is EX-C | `invoice_number` string(64) (duplicate-invoice rule, 11.1 row 40). |
| 12 | Field one … Field ten (custom slots) | Custom fields engine (`custom_fields`, entities in `peopleos.custom_fields.entities`; typed, required, active, order) | PARTIAL → built (HS-EX-A): custom-field entity `expense_receipt`; its fields are rows of the same matrix (visible and mandatory per section) | Add entity `expense_receipt` (model `ExpenseReceipt`) to `custom_fields.entities`, and a `CustomFieldsSchema::formSection(ExpenseReceipt::class)` on the receipt form. Per-type visibility is stored in the custom field's `validation` JSON as `applies_to: [amount, mileage, accommodation, travel]`. Better than ten fixed slots. |

## 11.9 Travel plan form (`core/expense/travel-plan-form`)

> **Progress (HS-EX-A/EX-B, 2026-10-29):** rows 1–4 and 12 closed; the fields themselves arrive with travel requests (EX-F).
>
> **Progress (HS-EX-F, 2026-10-29):** every row closed: the columns exist on `travel_requests` (visa and passport numbers
> encrypted and masked, attachments under `tenants/{id}/expense/travel`, custom fields of `travel_request`) and
> `TravelRequests::submit` enforces the matrix (hidden fields not kept, mandatory ones refused; visa and passport only
> for international travel). The passport number is prefilled from the employee's latest trip (PeopleOS keeps no
> passport on the profile).

Page help: "Travel form settings let you change label names and decide if they're required or optional." Columns: Visible on travel form, Mandatory on travel form, Rename Label, Action. Rows: Currency, Estimated cost, International travel, Visa number, Passport number, Add attachment, Field one … Field ten. Stored in setting `expense.travel_form.fields` on the same setup page (tab "Travel form").

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Field list · `fieldsName` | — | MISSING → built (HS-EX-A): setting `expense.travel_form.fields`, tab "Travel form"; `ExpenseForms::travelMissing()` is what EX-F enforces | Standard keys below, as columns on `travel_requests`. |
| 2 | Visible on travel form · `isVisibleOnTravelPlan` | — | MISSING → built (HS-EX-A): setting `expense.travel_form.fields`, tab "Travel form"; `ExpenseForms::travelMissing()` is what EX-F enforces | `visible` per field. |
| 3 | Mandatory on travel form · `isMandatoryOnTravelPlan` | — | MISSING → built (HS-EX-A): setting `expense.travel_form.fields`, tab "Travel form"; `ExpenseForms::travelMissing()` is what EX-F enforces | `mandatory` per field, enforced in `TravelRequests::submit`. |
| 4 | Rename Label · `displayName` | — | MISSING → built (HS-EX-A): setting `expense.travel_form.fields`, tab "Travel form"; `ExpenseForms::travelMissing()` is what EX-F enforces | `label` per field. |
| 5 | Action (row menu) | — | N-A | UI. |
| 6 | Currency | `exchange_rates` | PARTIAL → label and placement built (HS-EX-A); column built and enforced (HS-EX-F) | `travel_requests.currency` char(3), default `tenant.base_currency`. |
| 7 | Estimated cost | — | MISSING → label and placement built (HS-EX-A); column built and enforced (HS-EX-F) | `estimated_cost` decimal(14,2) (+ `estimated_cost_base`). |
| 8 | International travel | — | MISSING → label and placement built (HS-EX-A); column built and enforced (HS-EX-F) | `is_international` bool. Visa and passport show when true. |
| 9 | Visa number | — | MISSING → label and placement built (HS-EX-A); column built and enforced (HS-EX-F) | `visa_number`, encrypted cast, classified *sensitive* in field security / DataInventory. |
| 10 | Passport number | Person identity fields exist on the employee profile (sensitive) | PARTIAL → label and placement built (HS-EX-A); column built and enforced (HS-EX-F) | Prefill from the employee's passport document or identity when present. `passport_number` encrypted on the trip, sensitive. |
| 11 | Add attachment | — | MISSING → label and placement built (HS-EX-A); column built and enforced (HS-EX-F) | `attachments` JSON (private `documents` disk; DataInventory file paths). |
| 12 | Field one … Field ten | Custom fields engine | PARTIAL → built (HS-EX-A): custom-field entity `travel_request`, rows of the travel matrix | Entity `travel_request` in `custom_fields.entities` + form section on the travel form. |

## 11.10 Proposed expense data model

> **Progress (HS-EX-A/EX-B, 2026-10-29):** masters built (`expense_groups`, `expense_categories`, `expense_category_limits` with `match_key` for one row per combination, `expense_location_groups`, `expense_location_group_places` with `city_key`, `expense_tax_types`), with the two deviations noted under 11.4. `ExpenseLimits` reads usage through the `ExpenseUsage` contract (bound to `NoExpenseUsage` until EX-C binds the receipts' own). Transactions stay for EX-C … EX-F.
>
> **Progress (HS-EX-C/EX-D, 2026-10-29):** transactions built: `expense_receipts` (+ `reimbursable`, `flags`, `computed_distance_km`, `distance_source`, `attachment_hashes`; no `project_id` / `travel_request_id` until HP-5 / EX-F), `expense_receipt_taxes`, `expense_reports` (+ `reimbursable_approved`, `filed_on_behalf`; no `payroll_run_id` until EX-E), `expense_report_parts` (+ `employee_id`, `kind`, `label`, totals), `expense_advances` (no `travel_request_id` / `project_id` / `disbursed_payroll_run_id` until EX-F / HP-5 / EX-E), `expense_advance_entries` (+ `entry_date`, `reference`). `ExpenseUsage` is bound to `ReceiptUsage`. Numbers come from code series types `expense_receipt` (RCP-), `expense_report` (EXR-), `expense_advance` (EXA-). Travel tables stay for EX-F.
>
> **Progress (HS-EX-F, 2026-10-29):** `travel_requests`, `travel_request_legs` (+ `employee_id`, `preferred_time`, `notes`;
> `starts_on` / `ends_on` for travel date or check-in and return or check-out) and `expense_report_travel` built, with
> `expense_receipts.travel_request_id` and `expense_advances.travel_request_id`; code series type `travel_request`
> (TRV-). Trip statuses `submitted` / `approved` / `booking` / `booked` / `rejected` / `cancel_requested` / `cancelled`
> (no stored `draft`, `completed` or `closed`: the trip state upcoming / live / completed is worked out from the dates).
> No `project_id` until HP-5.
>
> **Progress (HS-EX-E, 2026-10-29):** `expense_reports` gained `payment_scheduled_at`, `payment_scheduled_by`, `payroll_run_id`, `final_settlement_id` (no `payment_scheduled` status: a scheduled report keeps its decision status with channel `payroll`, so "partly approved" stays visible); `expense_advances` gained `disbursed_payroll_run_id`, `recovery_due_on` and the status `recovered`; `expense_advance_entries` gained `payroll_run_id`, `final_settlement_id`. Run and settlement ids are plain references, as on the HP-2 tables. The payroll inputs live in `app/Domain/Payroll/Inputs` (not `app/Domain/Expense/Inputs`): the expense domain may not name payroll runs or computations (`ExpenseInvariantsTest`).

Domain `app/Domain/Expense/{Models,Services,Policies,Enums,Inputs,Settlement,Support,Datasets}`. Every table has `tenant_id` (`BelongsToTenant`) and is `Auditable`. Employee-linked tables are access-scoped (scope as `ReimbursementClaim`, policy `ExpensePolicy`). Numbers come from `App\Support\Numbering\NumberSequences` (`EXR-`, `EXA-`, `TRV-`, `RCP-`). Files go on the private `documents` disk with sha256.

**Masters (configuration)**

| Table | Columns (beyond id, tenant_id, timestamps) | Notes |
|---|---|---|
| `expense_groups` | name, code, sort_order, status | HROne "Expense group" |
| `expense_categories` | expense_group_id?, name, code (unique), description, type `amount`/`mileage`, rate_per_km?, odometer_required, location_input `none`/`text`/`map`, from_location_required, limit_basis `none`/`amount`/`percent_of_ctc`/`km`, percent_of_ctc?, ctc_component_id? (FK salary_components), limit_exception_allowed, description_required, receipt_required, receipt_required_above?, reimbursable, claim_frequency_period?, claim_frequency_count?, tax_applicable, gst_supply_type?, travel_link `none`/`reference`/`plan`, travel_form `travel`/`accommodation`?, booking_service_id? (FK service_definitions), booked_by `employee`/`company`/`either`, before_joining_allowed, attendance_rule JSON, hourly_constraints_enabled, hourly_constraints JSON, auto_fill_worked_hours, submission_window_days JSON, declaration_required, declaration_text?, sort_order, status | 11.4 |
| `expense_category_limits` | expense_category_id, dimension? (`grade_id`/`designation_id`/`level_id`/`employee_category_id`/…), dimension_value_id?, expense_location_group_id?, amount?, km_limit?, period (default `per_claim`) | Unique (category, dimension, value, group). Most specific match wins. A policy override (11.3 row 8) beats it. |
| `expense_location_groups` | name, code, sort_order, status | 11.5 |
| `expense_location_group_places` | expense_location_group_id, country_code, state_code, city? | Unique (tenant, country, state, city): a place is in one group |
| `expense_tax_types` | name, code, default_rate?, sort_order, status | 11.6 |
| (settings) | `expense.form.fields`, `expense.travel_form.fields` | 11.8 / 11.9 |
| (Policy Engine) | types `expense`, `expense_advance` | 11.2 / 11.3 |
| (existing) | `exchange_rates` | 11.7 |

**Transactions**

| Table | Columns | Statuses |
|---|---|---|
| `travel_requests` | number, employee_id, purpose, project_id?, is_international, currency, estimated_cost, estimated_cost_base, visa_number (enc), passport_number (enc), start_date, end_date, backdated_reason?, advance_amount?, expense_advance_id?, custom JSON, attachments JSON, workflow_instance_id?, decided_by?, decided_at?, decision_note?, cancel_reason?, lock_version | `draft` → `submitted` → `approved` / `rejected` → `booking` (company bookings open) → `booked` → `completed` (after end date) → `closed` (expenses reported or advance settled). `cancel_requested` → `cancelled`. Approved trips can be cancelled only through approval. |
| `travel_request_legs` | travel_request_id, sequence, kind `travel`/`accommodation`, mode `air`/`rail`/`road`/`sea`/`hotel`/`other`, from_city, from_state, to_city, to_state, depart_at, return_at?, booked_by, booking_ticket_id? (FK tickets), booking_details JSON (PNR, provider, cost, currency), booking_cost_base? | `pending` / `booking` / `booked` / `cancelled` |
| `expense_advances` | number, employee_id, policy_version_id, travel_request_id?, project_id?, purpose, currency, amount, amount_base, approved_amount?, disbursement_channel `payroll`/`bank`, disbursed_on?, disbursement_reference?, disbursed_payroll_run_id?, settle_by?, outstanding (cached; source of truth is the ledger), workflow_instance_id?, decided_by?, decided_at?, decision_note?, cancel_reason? | `requested` → `approved` / `rejected` → `disbursed` → `settled` (fully adjusted) / `recovered` (payroll or F&F) / `written_off` (HR with reason). `cancel_requested` → `cancelled` (only before disbursal) |
| `expense_advance_entries` (ledger) | expense_advance_id, employee_id, kind `disbursed`/`adjusted`/`recovered`/`refunded`/`written_off`, amount (+ disbursed / − others), expense_report_id?, payroll_run_id?, final_settlement_id?, entry_key (unique per advance), note, created_by | Idempotent, like `reimbursement_ledger_entries`. Outstanding = Σ amount. |
| `expense_receipts` | number, employee_id, expense_category_id, policy_version_id, expense_date, type, currency, amount, exchange_rate, base_amount, distance_km?, from_place?, to_place?, odometer_start?, odometer_end?, worked_minutes?, location_city?, location_state?, expense_location_group_id?, purpose?, merchant?, invoice_number?, participants JSON, project_id?, travel_request_id?, gst_supply_type?, supplier_gstin?, description?, attachments JSON (path, sha256), duplicate_score?, duplicate_of_id?, is_exceptional, exception_reason?, limit_amount?, over_limit_amount, claimable_amount, approved_amount?, approver_note?, declaration_accepted_at?, custom JSON, expires_on?, expense_report_id? | `unreported` → `reported` → `approved` / `partly_approved` / `rejected`. `expired` (job). `withdrawn` (employee, before report submit). |
| `expense_receipt_taxes` | expense_receipt_id, expense_tax_type_id, rate?, amount | — |
| `expense_reports` | number, employee_id, title, period_from, period_to, project_id?, status, total_claimed, total_approved, advance_adjusted, net_payable (may be negative = owed back), payment_channel `payroll`/`bank`/`settlement`?, payment_scheduled_at?, payroll_run_id?, paid_on?, payment_reference?, submitted_at?, decided_by?, decided_at?, decision_note?, window_cycle?, lock_version | `draft` → `submitted` → `returned` (back to draft with note) / `approved` / `partly_approved` / `rejected` → `payment_scheduled` (when `expense.payment.step_required`) → `paid`. `cancelled` (draft or submitted only). |
| `expense_report_parts` | expense_report_id, workflow_group (category or group code), workflow_instance_id?, status, decided_by?, decided_at? | One per workflow group when `expense.report.mixed_workflow_groups` |
| `expense_report_travel` | expense_report_id, travel_request_id | When `expense.report.multiple_travel` |

**Services.**

- `ExpenseCategories` and `ExpenseLimits`. Limits resolve from the policy version, then the category limits, then the location group; usage is counted per period.
- `ExpenseReceipts`: capture, validate (form matrix, limits, frequency, attendance, tenure window, before-joining, declaration, duplicates, expiry) and withdraw.
- `ExpenseReports`: add and remove receipts, submit (window, workflow split), approve (per line, possibly for less), return, reject, cancel, schedule payment, mark paid outside payroll.
- `ExpenseAdvances`: request, approve, cancel, disburse, adjust, recover, write off.
- `TravelRequests`: submit, overlap check, backdated rule, approve, cancel.
- `TravelBookings`: service-desk ticket, booking details.
- `ExpenseDuplicateDetector`.
- `ExpenseDefaults`: taxes and starter groups.

Every decision is refused for one's own records (as `PayrollRequestsApprovalSource`).

**Wiring (in `ParityServiceProvider`).**

- Approval Center source `ExpenseApprovalSource`: reports, report parts, advances, advance cancellations, trips, trip cancellations, exceptional receipts.
- Workflow trigger events `expense.report_submitted`, `expense.exceptional_submitted`, `expense.advance_requested`, `expense.advance_cancel_requested`, `expense.travel_requested` and `expense.travel_cancel_requested`, added to `peopleos.workflows.trigger_events`, with `ExpenseWorkflowBridge` on `WorkflowCompleted`.
- Notification events `expense.*` (HROne processes: Dispense advance, Exceptional expense, Advance cancellation, Advance request, Live trip, Expense report, Expense request, Travel, Travel booking, Travel cancellation).

**Permissions** (new group `expense` in `config/peopleos.php`):

- `expense.view`: view in scope.
- `expense.manage`: categories, groups, location groups, taxes, form setup, policies.
- `expense.claim`: own receipts, reports, advances and trips (employee and manager templates).
- `expense.approve`: decide in scope.
- `expense.pay`: pay expenses, dispense, recover and write off advances (payroll-admin / finance).
- `expense.travel_desk`: book travel and record bookings.

Add `Capability::Expense = 'expense'` with prefix `expense` to `app/Domain/Entitlements/Enums/Capability.php`, a DataInventory group `expense` (personal, financial significance, export true) with its file paths, and the Filament navigation group "Expense & travel" in `app/Providers/Filament/AdminPanelProvider.php`.

## 11.11 Money flows: payroll (`PayrollInputSource`) and exit settlement (`SettlementSource`)

> **Progress (HS-EX-E, 2026-10-29):** built. `ExpenseReportInput`, `ExpenseAdvancePayoutInput`, `ExpenseAdvanceRecoveryInput` (`app/Domain/Payroll/Inputs`, tag `peopleos.payroll_inputs`) and `App\Domain\Expense\Settlement\ExpenseSettlementSource` (tag `peopleos.settlement_sources`) as in the table, with these differences: report lines need channel `payroll` (scheduled on approval when there is no payment step, or from Pay expenses) and count from the scheduling date; codes `EXPENSE` / `EXP_ADVANCE` / `EXP_ADV_RECOVERY` with classifications `expense_reimbursement` / `expense_advance` / `expense_advance_recovery`; recovery reads `recovery_due_on` (set when dispensed) and waits while a report is submitted; no payout in the month the employee leaves; recovery ledger keys `payroll-{run}`; the exit lines are shown gross (one earning, one deduction). Nothing is paid twice: `PayrollCommitments` (records a run not yet finalized carries) and `ExpenseCommitments` (plus open exits' unpaid settlements) refuse outside payment, scheduling, dispensing, cancelling, refunds, write-offs and report adjustment of carried records; inputs and the settlement source skip records carried elsewhere. Open questions: (1) `expense.exit.pending_reports` built with default `ignore` (today's behaviour) instead of the proposed `block` — owner decision; (2) `net_payable` is never negative in PeopleOS (advances are adjusted only up to what a report repays), so an advance left over simply waits for its recovery date, as proposed.
>
> **Earlier (HS-EX-C/EX-D, 2026-10-29):** not built (EX-E). The seams it reads exist: an approved report is `approved` / `partly_approved` with `net_payable` > 0 and `paid_on` null (`ExpenseReport::isPayable()`; Pay expenses marks such reports `paid` with channel `bank` and a reference); advance adjustments are already final ledger entries keyed `report-{id}`, posted when the report is decided; an advance approved with `disbursement_channel = payroll` waits on Dispense advances until EX-E pays it; a dispensed advance carries `settle_by` and `outstanding` for the recovery rule. Nothing here is paid by PeopleOS.

```
Trip (optional) ──approve──► Advance (optional) ──disburse──► ledger +A
                                     │ bank: HR marks dispensed with reference
                                     │ payroll: ExpenseAdvancePayoutInput (non-taxable line), settled on finalize
Receipts ──► Report ──approve (per line)──► approved total T
                     ExpenseReports::approve adjusts open advances FIFO: ledger −min(T, outstanding)
                     net_payable = T − adjusted
          net_payable > 0 ──► ExpenseReportInput (next payroll)   or   Pay expenses (outside payroll, reference)
          advance still outstanding after settle_by + recovery_after_days ──► ExpenseAdvanceRecoveryInput (deduction)
Exit ──► ExpenseSettlementSource: + approved unpaid reports, − outstanding advances; settled() closes both
```

| Source | Interface / tag | Picks up | Line | Settle (on `payroll.finalized` / F&F paid) |
|---|---|---|---|---|
| `App\Domain\Expense\Inputs\ExpenseReportInput` (key `expense_report`) | `PayrollInputSource`, `peopleos.payroll_inputs` | Reports `approved` / `partly_approved` (or `payment_scheduled` with channel payroll when `expense.payment.step_required`), `decided_at` ≤ period end, `net_payable` > 0, no `payroll_run_id` | `{code: 'EXPENSE', name: 'Expense reimbursement', type: 'reimbursement', taxable: false, classification: 'reimbursement', pf_applicable: false, esi_applicable: false, include_in_gross: false, amount: Σ net_payable, ids: report ids, basis: {reports: n}}`. Only reimbursable lines count (company-paid lines are approved but never paid). | `ExpenseReports::paid($ids, $run)`: status `paid`, `payroll_run_id`, `paid_on` = period payment date; advance adjustments become final ledger entries (`entry_key = report-{id}`) |
| `ExpenseAdvancePayoutInput` (key `expense_advance_payout`) | same | Advances `approved` with `disbursement_channel = payroll`, approved ≤ period end | `{code: 'EXP_ADVANCE', type: 'reimbursement', taxable: false, classification: 'advance'}`, like `LoanDisbursementInput` | `ExpenseAdvances::disbursed($ids, $run)`: status `disbursed`, ledger `disbursed` (+), `settle_by` = trip end (or payment date) + `settle_within_days` |
| `ExpenseAdvanceRecoveryInput` (key `expense_advance_recovery`) | same | Advances `disbursed` with outstanding > 0, policy `recovery = payroll`, `settle_by + recovery_after_days` ≤ period end, no open report covering them | `{code: 'EXP_ADV_RECOVERY', type: 'deduction', classification: 'advance_recovery', amount: outstanding, basis: {advance: number, settle_by}}`, like `LoanRecoveryInput` | Ledger `recovered` (−, `entry_key = recovery-{run}-{id}`), status `recovered` |
| `App\Domain\Expense\Settlement\ExpenseSettlementSource` | `SettlementSource`, `peopleos.settlement_sources` | Employee's approved unpaid reports and advances with outstanding > 0 | Earning `EXPENSE_PAYABLE` (Σ net payable), deduction `EXP_ADVANCE_BALANCE` (Σ outstanding after offsetting payable) | `settled()`: reports `paid` with channel `settlement`; advances `recovered`; ledger entries keyed `fnf-{settlement}` |

Rules kept from HP-2:

- Finalized payroll is never reopened.
- A record is settled only by the run whose lines carried its id.
- With no expense records no line is added, and the engine version does not change.
- Expense reimbursement is non-taxable and outside gross, because it repays business spend. A taxable allowance stays a CTC/claims matter.

Two open questions for the owner:

1. Should reports still `submitted` at exit block the finance clearance? Proposed setting `expense.exit.pending_reports` select `block` / `ignore`, default `block`. The finance clearance item "Expense claims closed" already exists in `peopleos.exit.clearance_stages.finance`.
2. Should negative `net_payable` (advance exceeds approved spend) be recovered at once or wait for the recovery rule? Proposed: it waits for the recovery rule, so the employee can submit more receipts or refund.

---

# 14 Asset

## 14.1 Asset settings (`app-asset-setting`, Setup → Settings → Asset)

> **Progress (HS-AS-A, 2026-10-28):** row 10 built (recovery mandatory + recovery basis). Rows 1–9 (joiner and exit due days, request workflow, sub type / product visibility in requests) belong to AS-B (asset requests) and stay open.
>
> **Progress (HS-AS-B, 2026-10-29):** rows 1–9 built (Setup → Asset). Row 2 has an extra switch,
> `asset.exit.return_requests` (default off), because raising return requests at every exit would change today's
> behaviour; with it on, returns are due `asset.exit.due_days` before the last working day.
>
> **Progress (owner decision 2026-10-29, asset return on exit):** row 2 changed as decided: `asset.exit.return_requests`
> now defaults to **on** (a return request per company asset held when the exit is initiated; none when the employee
> holds no asset), with the exceptions `asset.exit.return_excluded_exit_types` (options from `peopleos.exit.types`) and
> `asset.exit.return_excluded_categories` (asset types), both empty by default and listed in Setup → Asset.

Visible text: "Asset · New · Return · Both · Yes · No · Asset sub type · Employees · Managers · HRs · Product · Relieving". The labels of the due-day inputs were not captured, so the meanings are *(inferred)*. Proposed home: Setup module `asset` (today: links only).

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Days to hand over assets to a new joiner · `onboardingDueDays` *(inferred "New")* | Onboarding template items have `owner_role_id` + `due_offset_days` (`onboarding_template_items`): a manual task, not tied to assets | PARTIAL → built (HS-AS-B): `asset.onboarding.due_days`; `AssetEntitlements::provisionJoiner` on employee.joined | `asset.onboarding.due_days` number 0–90, default 7. On `employee.joined`, `AssetEntitlements::provisionJoiner` raises one asset request (kind `new`, source `policy`) per entitlement of the employee's `asset` policy (14.2). Each request goes to the category's owner role (14.3) and is due on joining date + N. |
| 2 | Days to collect assets at exit · `exitDueDays` *(inferred "Return")* | `exit.clearance_lead_days` (7) starts clearance. The asset clearance stage lists assets in custody (`Assets::clearanceFor`, `Exits` line 112) | PARTIAL → built (HS-AS-B): `asset.exit.due_days` with the switch `asset.exit.return_requests`; withdrawn exits cancel them → owner decision 2026-10-29 built: the switch defaults to on, with exceptions by exit type and asset type (`asset.exit.return_excluded_exit_types` / `_categories`); nothing raised when no asset is held | `asset.exit.due_days` number 0–90, default 0 (days before the last working day). On exit initiation, a return request is raised per active assignment, due on LWD − N, owned by the category's role. |
| 3 | Which asset requests follow a workflow: New / Return / Both · `isConditionalWorkflow` *(inferred)* | No asset requests; the workflow engine is generic | MISSING → built (HS-AS-B): `asset.request.workflow_for` (none / new / return / both, default new); workflows on asset.requested / asset.return_requested, else the Approval Center | `asset.request.workflow_for` select `none` / `new` / `return` / `both`, default `new`. Trigger events `asset.requested` and `asset.return_requested`. Requests without a workflow are decided directly by the owner role (`asset.assign`). |
| 4 | Asset sub type visible in requests to Employees · `isRequestAssetSubtypeVisibility` | — | MISSING → built (HS-AS-B): `asset.request.subtype_visible_to` | `asset.request.subtype_visible_to` multiselect `employee` / `manager` / `hr`, default all three (covers rows 4–6). |
| 5 | … to Managers · `isManagerRequestAssetSubtypeVisibility` | — | MISSING → built (HS-AS-B): `asset.request.subtype_visible_to` | Same key as row 4. |
| 6 | … to HRs · `isHRRequestAssetSubtypeVisibility` | — | MISSING → built (HS-AS-B): `asset.request.subtype_visible_to` | Same key as row 4. |
| 7 | Product visible in requests to Employees · `isRequestProductVisibility` | — | MISSING → built (HS-AS-B): `asset.request.model_visible_to` | `asset.request.model_visible_to` multiselect, default `['hr']` (covers rows 7–9). |
| 8 | … to Managers · `isManagerRequestProductVisibility` | — | MISSING → built (HS-AS-B): `asset.request.model_visible_to` | Same key as row 7. |
| 9 | … to HRs · `isHRRequestProductVisibility` | — | MISSING → built (HS-AS-B): `asset.request.model_visible_to` | Same key as row 7. |
| 10 | "Relieving": recovery mandatory · `isRecoveryMandatory` (Yes / No) | `Exits` refuses to clear the asset stage while assets are in custody unless a recoverable amount is entered; the amount flows into F&F as `ASSET_RECOVERY` (`FinalSettlements` line 149). This is "mandatory", fixed. | PARTIAL → built (HS-AS-A): `asset.exit.recovery_required` (default on, as before; off clears the asset stage with an audited waiver reason) + `asset.exit.recovery_basis` (manual / book value suggested on the clearance form) | `asset.exit.recovery_required` bool, default true (today's behaviour). False lets the stage clear with an audited waiver reason and no deduction. Optional `asset.exit.recovery_basis` select `manual` / `book_value` (purchase_cost depreciated over model/category life). |

## 14.2 Asset policy (`core/asset/asset-policy`)

Wizard: Add Policy → Asset Assignment → Applicability → Advance applicability → Review. Help: "You can configure asset policy from here." Proposed: Policy Engine type **`asset`** ("Asset entitlement policy").

> **Progress (HS-AS-B, 2026-10-29):** every row closed: policy type `asset` (wizard steps Asset assignment → Asset requests
> → Assignment by HR → Applicability → Advance applicability → Review), new `options_from` sources `asset_categories`,
> `asset_subtypes`, `asset_models`, read by `AssetEntitlements` (joiner requests, mapped requests, HR assignment check in
> `Assets::assign`); Setup → Asset lists the type.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy grid + ADD POLICY + Previous / Next (`app-cardloader`) | `PolicyResource` | PARTIAL → built (HS-AS-B) | Works once type `asset` is declared. Add a Setup link "Asset policies". |
| 2 | Policy name · `policyName` | `policies.name` | PARTIAL → built (HS-AS-B) | Generic. |
| 3 | Description · `description` | `policies.description` | PARTIAL → built (HS-AS-B) | Generic. |
| 4 | "Please select the asset to be assigned to" · `assetTypeDetails` (list) | — | MISSING → built (HS-AS-B) | Field `entitlements`: repeater (`category`, `subtype`, `model`, `quantity` default 1). Read by `AssetEntitlements::for($employee, $date)`. |
| 5 | Asset type · `assetTypeId` | `asset_categories` master | PARTIAL → built (HS-AS-B) | Repeater `category` select `options_from` `asset_categories` (new source). |
| 6 | Asset sub type · `assetSubTypeId` | — (no sub-types) | MISSING → built (HS-AS-B) | Repeater `subtype`, `options_from` `asset_subtypes` (needs 14.4). |
| 7 | Product · `productId` | `asset_models` master | PARTIAL → built (HS-AS-B) | Repeater `model`, `options_from` `asset_models`. |
| 8 | "Employee can request for": Mapped asset / Any asset · `isEmployeeMapped` | — | MISSING → built (HS-AS-B) | `request_scope` select `mapped` / `any`, default `any`. Mapped: an employee may request only entitled categories, sub-types or models. |
| 9 | HR may assign only mapped assets · `isHrAssignMapped` *(inferred)* | `Assets::assign` accepts any assignable asset | PARTIAL → built (HS-AS-B) | `hr_assign_scope` select `any` / `warn` / `mapped`, default `any` (today's behaviour). Checked in `Assets::assign` through `AssetEntitlements`. |
| 10 | Applicability (`app-ou-applicability`) | Assignment rules | PARTIAL → built (HS-AS-B) | Generic. |
| 11 | Advance applicability (Optional) · `from`, `to` | Rule conditions | PARTIAL → built (HS-AS-B) | Generic. |
| 12 | Review (placeholders Condition / Operator / Value / Role) | Rule conditions field / operator / value. A "Role" condition is not offered (rules test employee attributes) | PARTIAL → built (HS-AS-B) | Generic. Role conditions are not needed: roles are user permissions, and entitlement follows position dimensions. |
| 13 | Filter "Policy" + "Status" | `PolicyResource` filters | PARTIAL → built (HS-AS-B) | Generic. |

## 14.3 Asset type (`core/asset/type`)

> **Progress (HS-AS-A, 2026-10-28):** every row closed. The role and due days are stored and shown; the role is told at exit clearance; request fulfilment by role and due dates arrive with AS-B.

Help: "You can create master of all asset types you want to track and manage in HR-One. For Ex Laptop." In PeopleOS this is **Asset category** (`AssetCategoryResource`).

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Asset Type · `assetTypeName` | `asset_categories.name` (+ code) | HAVE → built (HS-AS-A): Setup link "Asset types (categories)" | Optionally label the resource "Asset types (categories)" for HROne users. |
| 2 | Role · `roleId` | — (only global `exit.it_clearance_role` and the category's `is_it_asset` flag for exit) | PARTIAL → built (HS-AS-A): `owner_role_id`; its members are told when a leaving employee still holds one; request fulfilment comes with AS-B | `asset_categories.owner_role_id`, FK roles, nullable (fallback role `asset-admin`): fulfils requests, gets return tasks, is notified. |
| 3 | Due days · `dueDays` | — | MISSING → built (HS-AS-A): `fulfilment_days` master field; request due dates and overdue alerts come with AS-B | `asset_categories.fulfilment_days` smallint, nullable: request due = approved + N; overdue alert (AS-05). |
| 4 | Status (Active / Inactive) | `asset_categories.status` (ActiveStatus) | HAVE | — |
| 5 | Filter "Asset Type" (multi) + Status | The resource table has no filters | PARTIAL → built (HS-AS-A) | Add SelectFilter name and status (trivial). |

## 14.4 Asset sub type (`core/asset/sub-type`)

> **Progress (HS-AS-A, 2026-10-28):** every row built (`asset_subtypes`, Assets → Sub types).

Help: "Laptop may be a asset type and Premium Series / Standard series will be asset sub types." Gap AS-03.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Asset Sub Type · `assetSubtypeName` | — (two levels: category → model) | MISSING → built (HS-AS-A) | New table `asset_subtypes` (tenant_id, asset_category_id, name, code, allow_duplicate_serial, acknowledgement_required, sort_order, status) + `AssetSubtypeResource` (`asset.manage`). Nullable `asset_subtype_id` on `asset_models` and `assets`. Add to DataInventory group `assets` and to the configuration-change risk list `peopleos.configuration.risk.low` (which already lists AssetCategory and AssetModel). |
| 2 | Asset Type · `assetTypeId` ("Type") | — | MISSING → built (HS-AS-A) | FK `asset_category_id`, required. |
| 3 | Duplicate serial numbers allowed · `isDuplicateSerialNo` | Category `requires_serial` only; `assets.serial_number` is not unique | PARTIAL → built (HS-AS-A): enforced on every asset save (receive, form, edit) | `allow_duplicate_serial` bool, default true (today's behaviour). False: `Assets::receive` and the asset form refuse a serial already used within the sub-type (or the category when there is no sub-type). |
| 4 | Acknowledgement required · `isAssetAcknowledgement` | `asset_assignments.acknowledged_at` + `Assets::acknowledge` (optional for every asset; `asset.own`) | PARTIAL → built (HS-AS-A): My tasks item (`AssetAcknowledgementTaskSource`) + `peopleos:assets:acknowledgement-reminders`; the acknowledgement report is AS-06 / EX-G | `acknowledgement_required` bool, default false. When true, assignment raises an employee task through a new `AssetAcknowledgementTaskSource` (added to `peopleos.experience.task_sources`) and reminders, and feeds the acknowledgement report (AS-06). |
| 5 | Status + filters ("Asset Type", "Asset Sub Type", "Status") | — | MISSING → built (HS-AS-A) | Comes with the resource. |

## 14.5 Products (`core/asset/products`, `app-add-product-flyer`)

> **Progress (HS-AS-A, 2026-10-28):** every row closed (Assets → Models).

Help: "different product types … for ex: Dell Vostro 1120 or MAC Air 128 GB". In PeopleOS: **Asset model** (`AssetModelResource`).

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Name · `productName` | `asset_models.name` | HAVE | — |
| 2 | Asset type · `assetTypeId` | `asset_models.asset_category_id` | HAVE | — |
| 3 | Asset sub type · `assetSubtypeId` | — | MISSING → built (HS-AS-A) | `asset_models.asset_subtype_id` (14.4), filtered by category. |
| 4 | Description · `description` | `specifications` key/value only | PARTIAL → built (HS-AS-A) | `asset_models.description` text. |
| 5 | Manufacturer · `manufacturer` | `asset_models.manufacturer` | HAVE | — |
| 6 | Product life · `productLife` | `asset_categories.default_life_months` (per category) | PARTIAL → built (HS-AS-A): overrides the category for book value | `asset_models.life_months`, nullable, overrides the category (warranty and depreciation basis). |
| 7 | Deployment type · `deploymentTypeId` (HROne master "Deployment types") | — | MISSING → built (HS-AS-A) | `asset_models.deployment_type` select from `peopleos.assets.deployment_types` (e.g. hardware, on-premise software, cloud/SaaS). Use a tenant master only if the owner wants it editable. |
| 8 | License for · `licenseTypeId` | — (category `LICENCE` exists in the defaults) | MISSING → built (HS-AS-A) | `asset_models.licence_basis` select from `peopleos.assets.licence_bases` (per user / per device / organisation-wide), shown for licence or software categories. |
| 9 | Upload document (drop or browse; document viewer) | — | MISSING → built (HS-AS-A): private `tenants/{id}/assets/models`, signed + audited download (`ParityFileController` type `asset-model`) | `asset_models.documents` JSON on the private `documents` disk (+ DataInventory file paths), viewed through a signed URL as ticket attachments are. |
| 10 | Status + filter "Product" | `asset_models.status`; filter by category | HAVE → built (HS-AS-A): Product and Sub type filters added | — |

## 14.6 Inventory form (`core/asset/inventory`, `app-fields`)

> **Progress (HS-AS-A, 2026-10-28):** HAVE throughout; the Setup centre links the custom fields screen as "Inventory form". Asset imports (AS-04) are not built.

Help: "Inventory form settings let you change label names, enable them and decide if they are required or optional." Rows: Asset field 1 … 8.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Fields (Asset field 1–8) · `fieldsName` | Custom fields on entity `asset` (`peopleos.custom_fields.entities.asset`; `CustomFieldsSchema::formSection(Asset::class)` and infolist in `AssetResource`) | HAVE → Setup link "Inventory form (custom fields)" (HS-AS-A) | Unlimited and typed (text, number, date, dropdown …) rather than 8 text slots. |
| 2 | Enable · `isEnable` | `custom_fields.status` | HAVE | — |
| 3 | Mandatory · `isMandatory` | `custom_fields.is_required` | HAVE | — |
| 4 | Rename · `displayName` | `custom_fields.label` | HAVE | — |
| 5 | UPDATE | — | N-A | — |

Asset imports (AS-04: type, sub-type, product, inventory) are not Setup controls but follow from 14.4 and 14.5. They use the staged `RowImports` pipeline (`App\Domain\Employment\Imports\RowImports`).

---

# 15 Helpdesk

## 15.1 Helpdesk settings (`app-help-desk-settings`)

> **Progress (HS-HD-A, 2026-10-28):** both settings built and listed in Setup → Helpdesk.

Visible text: "Helpdesk · Yes · No · UPDATE"; question labels *(inferred from control names)*. Proposed home: Setup module `helpdesk` (today: links only).

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Show SLA status (to the requester) · `showSLAStatus` | SLA is computed (`SlaClock`) and shown only to case workers. `TicketResource::infolist` section "Assignment and SLA" is visible only when `$works` | PARTIAL → built (HS-HD-A) | `servicedesk.show_sla_to_employee` bool, default false (today's behaviour). True: the requester sees resolution due, "on track / overdue" and paused state, never agent names on restricted cases. |
| 2 | Feedback mandatory · `isFeedbackMandatory` | `ServiceDesk::close()` takes an optional 1–5 `satisfaction` + comment (`ServiceDeskActions` line 199). Auto-close after `servicedesk.auto_close_days` | PARTIAL → built (HS-HD-A) | `servicedesk.feedback_required` bool, default false. True: a requester closing a resolved case must rate it; system auto-close is exempt. Combined with the per-category switch (15.2 row 5). |

## 15.2 Ticket categories (`app-ticket-category-form`, grid "Ticket categories N ITEMS")

> **Progress (HS-HD-A, 2026-10-28):** every row closed. A configurable feedback question set stays HD-02.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Category · `categoryName` | `ticket_categories.name` (+ code, sort_order), `TicketCategoryResource` | HAVE | — |
| 2 | Purpose · `categoryPurpose` | `ticket_categories.description` (free text) | PARTIAL → built (HS-HD-A) | `ticket_categories.purpose` select from `peopleos.servicedesk.category_purposes` (`general`, `travel_booking`, `accommodation_booking`, `asset`), default `general`. Lets the expense booking agent (11.4 row 30) and asset requests find their desk. |
| 3 | Sub-categories · `subcategoryDetails` / `subcategoryName` | Services under a category (`service_definitions.ticket_category_id` + `subcategory` string; versions with form, SLA, assignment, approval) | HAVE → "Sub-categories (services)" column on the category list (HS-HD-A) | PeopleOS models sub-categories as catalogue services, which is richer. Optional: a "Services" relation manager on the category page so HROne-style admins see the sub-list in place. |
| 4 | "Is helpdesk RCA mandatory?" Yes / No · `isRCARequired` | — (resolution text only) | MISSING → built (HS-HD-A): `rca_required`, `tickets.root_cause` + `root_cause_code`; dataset field `root_cause` | `ticket_categories.rca_required` bool, default false + `tickets.root_cause` text (+ optional `root_cause_code` from `peopleos.servicedesk.root_causes`). `ServiceDesk::resolve` requires it when the flag is on; shown in agent views and ticket reports (HD-01). |
| 5 | "Do you want to provide feedback for this category?" · `isFeedbackRequired` | Rating offered on every case at close | PARTIAL → built (HS-HD-A) | `ticket_categories.feedback_enabled` bool, default true (today's behaviour). False hides the rating. With `servicedesk.feedback_required` the rating becomes mandatory where enabled. A configurable question set is HD-02. |
| 6 | Filter "Categories" (multi) + "Status" | `TicketCategoryResource` table has no filters | PARTIAL → built (HS-HD-A) | Add SelectFilter name and status (trivial). |

## 15.3 Resolver rules (`core/helpdesk/resolverrules`, `app-help-desk-policy-form` → Resolver details, Define SLA, Review)

> **Progress (HS-HD-B, 2026-10-28):** every row closed (Service Desk → Resolver rules; SLA policies expose service days and hours).

Help: "Configure settings for the ticket assignment to the right resolvers."

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Rule grid + ADD RULES, filter "Rule" + "Status" | No rule entity. Routing is per category (default agent / role) or per service version (`assignment.role_id`) | PARTIAL → built (HS-HD-B) | New table `service_resolver_rules` (tenant_id, name, priority, category_ids JSON, service_ids JSON, company_ids JSON, location_ids JSON, assignee_role_id, assignee_user_id?, notify_role_id?, sla_policy_id?, effective_from, effective_to, status) + `ServiceResolverRuleResource` (`servicedesk.manage`), audited and versioned by dates. |
| 2 | Rule name | — | PARTIAL → built (HS-HD-B) | `name`. |
| 3 | Applicable categories | Category-level assignment | PARTIAL → built (HS-HD-B) | `category_ids` (empty = any). |
| 4 | Applicable subcategories | Service-version assignment (`assignment.role_id`) | HAVE → rules may list services (HS-HD-B) | Kept. Rules may also list `service_ids`. |
| 5 | Assigned to · `authorityRoleId` | `ticket_categories.assignee_role_id`, version `assignment.role_id`, least-loaded eligible agent (`CaseAssignment::initial`) | HAVE → built (HS-HD-B): `ResolverRules::match` first, version / category fallback unchanged | `CaseAssignment::initial` consults matching rules first (lowest priority number, most specific: service > category > any, then location/company), then today's version and category fallback. |
| 6 | "Do you want to intimate any role when the ticket is assigned to the assignee?" Yes / No + "Select intimation role" · `authorityRoleIdNotification` | Notification rules can target a role on `servicedesk.ticket.assigned`, with conditions | PARTIAL → built (HS-HD-B): event `servicedesk.ticket.intimated` | `notify_role_id` on the rule. `CaseAssignment` adds the role's members (capped, eligible only) as recipients of the assignment notification. No notification-rule set-up needed. |
| 7 | "Resolver will not resolve ticket on" (weekly off days) · `weeklyOffDays` | `servicedesk.business_hours.days` (global [1–5]). `service_sla_policies.business_hours` JSON exists and is read by `SlaCalendars` but is **not on `ServiceSlaPolicyResource`'s form** | PARTIAL → built (HS-HD-B): service days on the SLA policy form | Expose `business_hours.days` (CheckboxList Mon–Sun) on the SLA policy form. A rule picks its SLA policy, so "resolver days" = the policy's service days. Holidays stay with the employee's holiday calendar (no second calendar). |
| 8 | Working hours: Start time / End time | Same: `business_hours.start` / `end`, global default 09:00–18:00 | PARTIAL → built (HS-HD-B) | `business_hours.start` / `end` TimePickers on the SLA policy form. |
| 9 | Define SLA: Priority Low / Medium / High → "Due days" | `service_sla_policies.targets` per priority (`low`, `normal`, `high`, `urgent`): first response and resolution in hours | HAVE → "or due days" input converts to service hours (HS-HD-B) | HROne Medium ≈ PeopleOS Normal. Optional "days" input that converts to service hours (days × service-day length). |
| 10 | SLA "Hours" / "Minutes" | Resolution hours accept decimals (min 0.25 h) | HAVE | — |
| 11 | "Select escalation role" | `service_sla_policies.escalation_role_id`, `escalation_repeat_hours`, `max_escalation_level`; `ticket_categories.escalation_role_id` | HAVE | — |
| 12 | Review (Rule details: name, applicable categories, subcategories, assigned to, intimation, non-working days, working hours, SLA) | Category and SLA policy views | PARTIAL → built (HS-HD-B): rule view page | Rule view page summarising its fields and the SLA policy. |
| 13 | `allowZeroAsOption` (0 days allowed) | — | N-A | UI detail. The SLA target minimum is 0.25 h. |

## 15.4 Helpdesk policy (`core/helpdesk/helpdeskpolicy`, applicability)

> **Progress (HS-HD-C, 2026-10-28):** every row closed (policy type `helpdesk`, with the step-by-step policy wizard).

Wizard: Basic details (`name`, `description`) → Applicability → Advance applicability → Review. Help: "Here you can create and configure new policies." This decides which helpdesk categories an employee group may use.

| # | HROne setting | PeopleOS today | Status | Build note |
|---|---|---|---|---|
| 1 | Policy grid + ADD POLICY + filter "Policy" + "Status" | Per-service availability in `service_definition_versions`; no named policy | PARTIAL → built (HS-HD-C) | Policy Engine type **`helpdesk`** ("Helpdesk access policy"). Fields: `categories` multiselect `options_from` `ticket_categories` (empty = all), `services` multiselect `options_from` `service_definitions` (empty = all in the categories), `raise_on_behalf_of_team` bool (managers). `ServiceCatalogue::availableTo()` filters by the resolved policy; no policy = today's behaviour. |
| 2 | Name · `name` | `policies.name` | PARTIAL → built (HS-HD-C) | Generic. |
| 3 | Description · `description` | `policies.description` | PARTIAL → built (HS-HD-C) | Generic. |
| 4 | Applicability | Per service: `org_scope` (companies, departments, locations), audiences (employee / manager / hr), `lifecycle_states`, eligibility | PARTIAL → built (HS-HD-C) | Policy assignment rules give the group-level layer across services. Per-service scope stays for fine control. |
| 5 | Advance applicability | Rule conditions | PARTIAL → built (HS-HD-C) | Generic. |
| 6 | Review | Policy version publish; services have maker-checker approval | PARTIAL → built (HS-HD-C): policy wizard review | Generic. |

---

## Not in these sections

The `app-common-settings` shell shown on every page belongs to other sections' gap files:

- "Delete transactions" (`performanceCycleId`, `taskTypeIds`, `remarks`);
- the time-office, payroll (including `assignReimbursementTask`, `hideNewRegimeReimbursementBillRequest`, `showPayByDefaultOnReimbursementReport`), timesheet, engage points, performance and recruitment panels.

---

## Build packages (ordered)

The order puts small, independent Helpdesk and Asset fixes first, then the Expense chain, which is sequential. Every package:

- stays additive, with defaults that reproduce today's behaviour;
- adds its settings and policy types to `config/peopleos_setup.php`, which `SetupCentreTest` checks;
- classifies new tables in `DataInventory`;
- has tests under `tests/Feature/HroneParity/`;
- runs `php artisan peopleos:sync-permissions` when it adds keys.

| # | Package | Scope | Files likely touched | Depends on | Size |
|---|---|---|---|---|---|
| 1 | **HD-A Helpdesk switches and categories** | `servicedesk.show_sla_to_employee`, `servicedesk.feedback_required`; `ticket_categories.purpose`, `rca_required`, `feedback_enabled`; `tickets.root_cause`; RCA enforced in `resolve`; rating rules in `close`; category filters; Setup module `helpdesk` settings | migration (ticket_categories, tickets), `app/Domain/ServiceDesk/Models/{TicketCategory,Ticket}.php`, `Services/ServiceDesk.php`, `app/Filament/Resources/TicketCategories/TicketCategoryResource.php`, `app/Filament/Resources/Tickets/TicketResource.php`, `app/Filament/Support/ServiceDeskActions.php`, `config/peopleos.php` (settings, `servicedesk.category_purposes`), `config/peopleos_setup.php`, `ServiceDeskDataset` (root cause) | — | S |
| 2 | **AS-A Asset taxonomy and masters** | `asset_subtypes` (+ resource, duplicate-serial rule, acknowledgement-required flag); category `owner_role_id`, `fulfilment_days`, filters; model `asset_subtype_id`, `description`, `life_months`, `deployment_type`, `licence_basis`, `documents`; `assets.asset_subtype_id`; serial check in `Assets::receive`; acknowledgement task source + reminder; `asset.exit.recovery_required` (+ waiver path in `Exits::clear`) | new migration, `app/Domain/Assets/Models/{AssetSubtype,AssetCategory,AssetModel,Asset}.php`, `Services/Assets.php`, new `Support/AssetAcknowledgementTaskSource.php`, `app/Filament/Resources/{AssetSubtypes,AssetCategories,AssetModels,Assets}`, `app/Domain/Exit/Services/Exits.php`, `config/peopleos.php` (assets vocabularies, settings, `experience.task_sources`, configuration models), `config/peopleos_setup.php`, `DataInventory.php` | — | M |
| 3 | **HD-B Resolver rules and service hours** | `service_resolver_rules` + resource; `CaseAssignment::initial` rule matching (fallback unchanged); intimation role recipients; SLA policy form exposes `business_hours` (days, start, end) and optional due-days input; rule review view | migration, `app/Domain/ServiceDesk/Models/ServiceResolverRule.php`, `Services/CaseAssignment.php`, `Services/SlaCalendars.php` (validation only), `app/Filament/Resources/ServiceResolverRules/*`, `app/Filament/Resources/ServiceSlaPolicies/ServiceSlaPolicyResource.php`, `config/peopleos_setup.php` (link), `DataInventory.php` | HD-A (purpose field useful, not required) | M |
| 4 | **EX-A Expense foundation and masters** (built 2026-10-29, HS-EX-A) | `Capability::Expense`; permission group `expense` + role templates; navigation group; DataInventory group; `expense_groups`, `expense_categories`, `expense_category_limits`, `expense_location_groups` (+ places), `expense_tax_types` (+ IN seed via `ExpenseDefaults`); resources for each; `ExpenseFormSetup` page (expense and travel field matrices in settings); custom-field entities `expense_receipt` and `travel_request`; Setup module `expense` (settings from 11.1 as they become read, links incl. Exchange rates); new `options_from` sources | `app/Domain/Expense/**` (new), migrations, `app/Filament/Resources/{ExpenseGroups,ExpenseCategories,ExpenseLocationGroups,ExpenseTaxTypes}`, `app/Filament/Pages/ExpenseFormSetup.php`, `config/peopleos.php` (permissions, roles, settings, `expense` vocabularies, custom_fields.entities), `config/peopleos_setup.php`, `app/Domain/Entitlements/Enums/Capability.php`, `app/Providers/Filament/AdminPanelProvider.php`, `app/Domain/Offboarding/Support/DataInventory.php`, `SetupCatalogue.php` + `PolicySettingsSchema.php` (options sources) | — | L |
| 5 | **EX-B Expense policies** (built 2026-10-29, HS-EX-B) | Policy types `expense` (categories repeater with overrides, exceptional-only, overall limit) and `expense_advance`; `ExpenseLimits` resolver (policy override → category limit by dimension and location group → period usage); % of CTC through the compensation reader | `config/peopleos.php` (`policies.types`), `app/Domain/Expense/Services/ExpenseLimits.php`, `PolicySettingsSchema.php` (sources), `config/peopleos_setup.php` (policy_types) | EX-A | M |
| 6 | **EX-C Receipts and reports** (built 2026-10-29, HS-EX-C) | `expense_receipts`, `expense_receipt_taxes`, `expense_reports`, `expense_report_parts`; capture with every category rule (limits, frequency, attendance, tenure, before-DOJ, declaration, receipts, duplicates, expiry, currency); report windows, split by workflow group, approve per line, return, reject, cancel; workflow events + `ExpenseWorkflowBridge`; `ExpenseApprovalSource`; Me screens (My receipts, My reports) and HR/manager screens; notifications; expiry command; cover letter type; settings rows 2–24, 38–40 | `app/Domain/Expense/{Models,Services,Support}`, migrations, `app/Filament/Resources/{ExpenseReceipts,ExpenseReports}` (+ My pages), `app/Domain/Experience/Sources/ExpenseApprovalSource.php`, `app/Providers/ParityServiceProvider.php`, `config/peopleos.php` (workflow trigger events, notification events, letters.types), `routes/console.php` (schedule) | EX-A, EX-B | L |
| 7 | **EX-D Advances** (built 2026-10-29, HS-EX-D) | `expense_advances` + `expense_advance_entries` ledger; request, cancel (with approval), approve, dispense (bank reference), FIFO adjustment on report approval, write-off; Approval Center items; Pay expenses / Dispense advances screens (`expense.pay`) | `app/Domain/Expense/Models/{ExpenseAdvance,ExpenseAdvanceEntry}.php`, `Services/ExpenseAdvances.php`, `app/Filament/Resources/ExpenseAdvances/*`, `ExpenseApprovalSource`, migrations | EX-C | M |
| 8 | **EX-E Payroll and exit money flows** (built 2026-10-29, HS-EX-E; inputs in `app/Domain/Payroll/Inputs`) | `ExpenseReportInput`, `ExpenseAdvancePayoutInput`, `ExpenseAdvanceRecoveryInput` (tag `peopleos.payroll_inputs`); `ExpenseSettlementSource` (tag `peopleos.settlement_sources`); `expense.payment.step_required` + payment scheduling; `expense.exit.pending_reports`; tests like `PayrollInputsTest` (lines carry ids, settled only on finalize, no line with no records, engine version unchanged) | `app/Domain/Expense/Inputs/*`, `app/Domain/Expense/Settlement/ExpenseSettlementSource.php`, `app/Providers/ParityServiceProvider.php`, `tests/Feature/HroneParity/ExpensePayrollTest.php` | EX-C, EX-D | M |
| 9 | **AS-B Asset entitlement policy and requests** (built 2026-10-29, HS-AS-B: `asset_requests`, `AssetRequests`, `AssetEntitlements`, `AssetRequestApprovalSource`, `AssetRequestWorkflowBridge`, `AssetRequestTriggers`, Assets → Asset requests / Me → My asset requests, permission `asset.request`; exit returns by `asset.exit.return_requests`, on by default since the owner decision of 2026-10-29) | Policy type `asset` (entitlements, `request_scope`, `hr_assign_scope`); `asset_requests` (number, employee_id, kind `new`/`return`/`replacement`, category / sub-type / model, reason, needed_by, due_on, status `requested`/`approved`/`rejected`/`fulfilled`/`cancelled`, asset_id, workflow_instance_id); `AssetRequests` + `AssetEntitlements`; workflow events per `asset.request.workflow_for`; visibility settings; joiner provisioning (`asset.onboarding.due_days`) and exit returns (`asset.exit.due_days`); Approval Center source; new permission `asset.request` (employee and manager templates); owner decision 2026-10-29: exit returns on by default with exceptions by exit type and asset type | migration, `app/Domain/Assets/Models/AssetRequest.php`, `Services/{AssetRequests,AssetEntitlements}.php`, listeners on `employee.joined` / exit initiation, `app/Filament/Resources/AssetRequests/*` (+ My asset requests), `config/peopleos.php` (policy type, permissions, settings, workflow and notification events), `config/peopleos_setup.php`, `ParityServiceProvider.php` | AS-A | L |
| 10 | **HD-C Helpdesk access policy** | Policy type `helpdesk` (categories, services, raise on behalf); `ServiceCatalogue::availableTo()` filter; Setup policy type | `config/peopleos.php` (`policies.types`), `app/Domain/ServiceDesk/Services/ServiceCatalogue.php`, `PolicySettingsSchema.php` (ticket_categories / service_definitions sources), `config/peopleos_setup.php` | — | S |
| 11 | **EX-F Travel** (built 2026-10-29, HS-EX-F: `TravelRequests`, `TravelBookings`, `TravelCalendar`, `ServiceRequests::raiseForProcess`, Expense & travel → Travel requests; projects on trips wait for HP-5) | `travel_requests`, `travel_request_legs`, `expense_report_travel`; travel form matrix enforcement; backdated / overlap / multi-leg / mixed categories / advance-in-trip rules; booking through a service request (category purpose `travel_booking`, category `booking_service_id`); booking details; cancellation with approval; attendance calendar overlay; settings rows 15, 25–36 | `app/Domain/Expense/Services/{TravelRequests,TravelBookings}.php`, migrations, `app/Filament/Resources/TravelRequests/*`, `app/Filament/Pages/AttendanceCalendar.php` (overlay), `ExpenseApprovalSource`, `config/peopleos.php` | EX-C, EX-D, HD-A (purpose) | L |
| 12 | **EX-G Expense reports and dashboard** (expense part built 2026-10-29, HS-EX-G: datasets `expense_receipts`, `expense_reports`, `expense_advances`, `expense_advance_ledger`, 8 HROne templates + 4 more, "Expense overview" dashboard on request; travel datasets `travel_requests` / `travel_request_legs` and the 4 travel templates built with EX-F; asset acknowledgement / history and ticket datasets (AS-06, HD-01) not built here) | Datasets `expense_receipts`, `expense_reports`, `expense_advances`, `travel_requests` + HROne's 12 templates (advance amount, advance recovery register, live trip, payment register, receipts by amount / mileage / details, report details, outstanding advance, travel details, travel summary, accommodation details); expense dashboard (AN-07); asset acknowledgement / history and ticket by requestee / resolver datasets (AS-06, HD-01) | `app/Domain/Analytics/Datasets/*`, `app/Domain/Analytics/Services/{DatasetRegistry,ReportLibrary}.php`, `config/peopleos.php` (API dataset fields) | EX-C … EX-F, AS-B, HD-A | M |
| 13 | **Deferred / external** | Map distance (maps provider key, EXT); project-based approval and project fields on receipts, reports, advances and trips (needs HP-5 projects, PR-02); email-to-expense / email-to-ticket (EXT, HD-03) | — | HP-5, owner decisions | — |
