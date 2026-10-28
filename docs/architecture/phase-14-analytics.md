# Phase 14 — Analytics

Blueprint §56, §84, §85, §121 Phase 14. Built 2026-09-27.

## What exists

**Datasets (`App\Domain\Analytics\Datasets`)** — one class per reportable subject, each declaring the permission(s) that unlock it, an optional sensitive permission, a tenant-scoped Eloquent query with eager loads, and a field catalogue (`label`, `type` string / number / date / boolean, `value` closure, `sensitive`). Nine datasets: employees (headcount, demographics, tenure, org dimensions, CTC behind `employee.sensitive.view`), attendance, leave, payroll (finalized / paid entries), exits, performance, learning, assets, service desk. `DatasetRegistry` resolves them and filters by user.

**Report builder (§84)** — `Report` stores `dataset` + `definition` JSON: `fields`, `filters` (`field`, `operator`, `value`; operators equals / not equals / any of / contains / gt / gte / lt / lte / between / empty / not empty / last N days / this month / this year), `group_by` + `aggregations` (count / sum / avg / min / max with optional labels), `calculated` fields (safe `FormulaEngine` formulas over numeric row values, usable in filters, grouping and charts), `sort`, `limit`, `visualization` (table / bar / line / pie / kpi with x and y). `ReportRunner` maps rows in memory (bounded by `analytics.max_rows`), so every dataset behaves identically; it returns a `ReportResult` (columns, rows, total, grouped flag, chart series, kpi). `ReportExports` writes UTF‑8 BOM CSV (Excel-compatible) to the documents disk and records a `ReportRun` with an `EXPORT` audit event. `ReportSchedule` (daily / weekly / monthly at a time, recipients) is run by `peopleos:reports:run-due` hourly; recipients get an in-app notice.

**Metrics (`WorkforceMetrics`)** — headcount as of a date (joining / exit aware), joiners and exits in 30 days, 12‑month attrition rate (exits ÷ average headcount), absenteeism (absent ÷ scheduled days, 30 days), on leave today, people cost and cost per head from the last finalized payroll, average tenure, women share, high performers (final rating ≥ 4 in the latest cycle), mandatory learning completion, open tickets / grievances / exits / approvals; monthly series for headcount, joiners, exits, people cost, absenteeism; critical skills (fewest advanced / expert holders).

**Dashboards (§85)** — `Dashboard` with role audience (`role_ids`; empty = everyone with `analytics.view`), default flag and ordered widgets: `kpi` (metric), `trend` (12‑month series), `chart` / `table` / `leaderboard` (a shared report), `alerts` (Needs Attention counts for the viewer). `Dashboards` resolves a board for a viewer and reports per-widget errors instead of failing the page. Every tenant is provisioned with six shared reports and the default **HR overview** dashboard.

## Admin UI ("Analytics" group)

- **Workforce Command Centre** (`analytics.executive`): twelve KPIs, four 12‑month trends, critical skills table; open positions shown as pending the RMS integration.
- **Dashboards**: viewer with a switcher across the boards the user may see. **Dashboard builder** (`analytics.manage`): audience, widgets repeater.
- **Reports**: list (shared or own), builder form (dataset → fields → filters → grouping → calculated → visualization), run page with grouped table, Chart.js chart widget for bar / line / pie, KPI number, CSV export; Schedules and Runs tabs (download).

**Permissions** — `analytics.view|reports|manage|export|executive`. New **Executive** role template. HR Admin gains view / reports / export; Auditor gains view and the command centre.

## Conventions

- Datasets are the only place that knows how to derive values; reports never touch models directly. Add a dataset by subclassing `Dataset` and registering it in `DatasetRegistry`.
- Sensitive fields are stripped by `Dataset::fieldsFor()` for viewers without the dataset's sensitive permission, so a saved report cannot leak them to a less-privileged viewer.
- Report runs and exports are audited; scheduled runs carry `run_by = null` and the schedule id.
- **Unattended runs (2026-10-28).** A result that goes to no signed-in user is unattended: a run with no user, or the
  read API (`GET /api/v1/reports/{report}/run`).
  - It is default deny. Only datasets and fields listed in `peopleos.analytics.unattended_datasets` are used, never a
    sensitive or permissioned field.
  - A definition that selects, filters, groups or aggregates on any other field is refused before a row is read
    (`ReportRunner::unattendedFields()`).
  - The API runs a shared report as its owner (dataset permission and organisation scope, as scheduled runs do) and
    refuses an inactive owner. Starter reports, which have no owner, run with the key's own reach.
  - The key must hold every scope the entry lists, besides `reports.run`.
  - Each run is audited as `EXPORT` and each refusal as `REJECTED` (`channel: api`). Tests:
    `tests/Feature/Enterprise/ReadApiReportAccessTest.php`.
- Compare date columns with `whereDate` in metric queries: SQLite stores dates with a time part, so `between` on plain date strings drops the last day.
- Tests: `tests/Feature/Analytics/ReportingTest.php`, `tests/Feature/Admin/AnalyticsPagesRenderTest.php`.

## Report library and HR report datasets (2026-10-27)

Benchmarked against the standard report catalogues of greytHR, Keka, Zoho People, Pocket HRMS, factoHR, SAP SuccessFactors and Workday (employee master, attendance and leave registers, salary and statutory registers, joiners/exits, IT declarations, audit).

**Datasets** — 41 (was 9), each with a `category()` used to group the builder's dataset picker (`DatasetRegistry::groupedOptions()`) and the library:

| Category | Datasets |
|---|---|
| People | employees (+ birthdays, anniversaries, probation/confirmation, age and tenure bands, BU/division/team/cost centre/work mode, manager code, login), `employee_movements` (position history with previous placement), `manager_changes`, `lifecycle_transitions`, `qualifications` — all `employee.view`; `statutory_bank`, `family_members`, `emergency_contacts` — `employee.sensitive.view`; `employee_documents` (`document.view`), `letters` (`letter.view`) |
| Attendance | attendance (+ in/out, shift times, early leaving, missed punch, OT status), `attendance_monthly` (SQL roll-up per employee per month), `attendance_punches`, `attendance_regularisations`, `shift_roster`, `holidays` |
| Leave | leave requests (+ applied/reviewed, on leave today, back-dated), `leave_balances` (available = closing − pending), `leave_ledger` (signed: credits +, debits −), `leave_encashments` |
| Payroll & compensation | payroll (+ Basic/HRA, PF/ESI/PT/LWF EE+ER, masked UAN/ESI/bank), `payroll_components`, `payroll_adjustments`, `payslips`, `ctc_register` (current salary per employee via the `CompensationOutput` read contract), `salary_revisions` — payroll `payroll.view`, compensation `compensation.view` |
| Income tax | `tax_declarations`, `tax_investments` — `compliance.returns.view` or `compliance.tds.manage` |
| Joining & exit | `onboarding`, `onboarding_tasks`, `bgv`, exits (+ notice, clearances pending, F&F status), `exit_clearances`, `final_settlements` |
| Performance, admin | `goals`, `improvement_plans` (`performance.pip`; no reasons or objectives), `users` (`user.view`; tenant users, roles, MFA, last sign-in) |

**Report library** (`ReportLibrary`, page Analytics → Report library, `ReportLibraryPage`) — 149 ready-made definitions in nine categories (People 47, Attendance 21, Leave 19, Payroll & compensation 21, Statutory & income tax 12, Joining & exit 15, Performance & learning 7, Assets & service desk 4, Administration 3). A template is offered only when the user may read its dataset; "Use" saves it as the user's own report (`analytics.reports`), optionally narrowed to one employee code (datasets with `employee_code` only) or shared (`analytics.manage` only), and opens it for run / CSV export / edit / schedule. Reports list has a "Report library" header action.

**Operators** — added `not_in`, `next_days`, `today`, `yesterday`, `this_week`, `last_month`, `this_financial_year`, `last_financial_year` (start month `peopleos.compliance.financial_year_start_month`). Date filters also accept `today` as a value (`lt today`).

**Conventions**

- Rows hanging off an employee use `Dataset::employeeFields()` / `employeeWith()` (code, name, `employee` = "code · name" for per-person grouping, company, location, department, designation, `employee_status`). Every relation a closure touches must be eager-loaded: `Model::shouldBeStrict()` is on outside production.
- Datasets over models without `ScopedByEmployee` (payroll lines, clearances, onboarding tasks, people records) constrain with `whereHas(...)` on a scoped parent so the viewer's organisation scope applies; `users` scopes with `forCurrentTenant()`.
- Statutory identifiers and bank accounts are always masked (`EmployeeStatutoryDetail::mask`, `maskedAccountNumber()`); full values stay in the statutory returns and the bank advice.
- `attendance_monthly` groups on `substr(date, 1, 7)` (portable across MySQL `ONLY_FULL_GROUP_BY` and SQLite).
- Tests: `tests/Feature/Analytics/ReportLibraryTest.php` seeds every dataset and runs every template (unknown fields, operators or sort keys fail the build).

## Known gaps / deferred

- Not covered for lack of a module: loans and advances, expense claims, recruitment pipeline. Bonus, gratuity and the statutory forms are in Compliance → Compliance reports. Month-on-month payroll variance needs a pivot the builder does not do.

- Excel (`.xlsx`) and PDF exports; only CSV is produced (no spreadsheet or PDF library installed).
- In-memory filtering and grouping are bounded at 10,000 rows per run; very large tenants will need pushed-down SQL aggregation.
- Heatmap, funnel and calendar widgets; dashboards render bars and tables with inline HTML rather than Chart.js (the report page uses Chart.js).
- Open positions require the RMS integration (Phase 16 scope for external feeds).
