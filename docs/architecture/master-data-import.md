# Master uploads (consolidated workbooks)

Written 2026-11-23. Describes what is implemented. Part A of the "Intelligent Master Data Import, Consolidation &
Multi-Entity Configuration" brief. Part B (shared configuration, entity overrides, applicability) is built separately:
see `configuration-applicability.md` and `configuration-cloning.md`. Name-based reference resolution (by name as well
as code) is built separately too: see `name-resolution.md`. It is not yet wired into the workbook engine (see Limitations).

Admin › Data import now opens with **Preferred imports — Master uploads**: one Excel workbook for many kinds of data,
checked together and imported in the order the data depends on. Every single upload stays underneath, unchanged.

## 1. What the audit found

The existing pipeline (`app/Domain/Employment/Imports`, documented in `data-import.md`) was sound and worth keeping:

- 112 catalogue processes under 9 heads (core 19, workforce 21, time office 15, payroll 26, performance 9, engage 3,
  asset 4, projects 1, integration 14; two are `na`) over 84 upload types.
- One staged pipeline for every type: upload → inspect → map → validate → approve → run. Rows are staged in
  `employee_import_rows` and checked as the uploader. Each run is one audited operation, and every write goes through
  the owning domain service.
- Approval rules (`ImportGovernance`: self, maker-checker, workflow), events, Recent uploads, per-type screens and
  permissions were already central.

Gaps that mattered for consolidation:

| # | Gap | Effect |
|---|---|---|
| G1 | Every upload is one CSV of one type. | A go-live meant 20–60 separate files, uploaded in the right order by hand. |
| G2 | Validation and the run happen inside the web request. | Large files risked time-outs. Nothing was queued. |
| G3 | Each upload only sees what is already on file. | Departments for a company in the same batch were "does not exist". |
| G4 | The employee upload refused a manager who was a new hire in the same file. | Reporting lines needed a second pass. |
| G5 | There was no Companies or Cost centres upload. | An organisation could not be built from files. |
| G6 | A row's "imported" status was written after its change committed. | A run stopped in between would apply that row again on a re-run. |

## 2. Design decision: one engine on the existing pipeline

The brief proposed seven master imports and suggested considering a common engine. The design goes further. There is
**one generic workbook engine and no new import logic**. A workbook is a list of worksheets, and each worksheet is an
*existing catalogue process*. Underneath, each populated worksheet becomes an ordinary upload of its own type. So
every column, check, permission, approval rule and write path of the single uploads applies unchanged, and a new
single upload joins a workbook with one line.

Alternatives rejected:

- **Separate importers per master category.** This would duplicate about 80 types' business rules (a parallel
  engine, which the brief forbids).
- **Previewing by running the whole workbook in a transaction and rolling it back.** Side effects outside the database
  would escape the rollback: invitations, mail (the local `.env` sends real mail), files, caches. It would also double
  the work for 10,000 rows.

## 3. Final grouping (7 workbooks, 78 worksheets)

| Workbook (`WorkbookCatalogue`) | Worksheets |
|---|---|
| **Organisation Master** (`workbook_organisation`) | Companies*, Cost centres*, States, Districts, Cities, Regions, Business regions, Branches, Sub branches, Business units, Departments, Sub departments, Levels, Grades, Designations, Dimension values, Skills, Designation skills |
| **Employee Master** (`workbook_employees`) | Employees (all columns), Job history, Addresses, Emergency contacts, Government IDs, Family members, Education, Work experience, Nominees, Employee skills, Other details, Bank accounts, Employee settings, Vendors, Contracts, Health insurance |
| **Attendance & Time Office** (`workbook_time_office`) | Holiday calendars†, Holidays†, Opening leave balances, Comp-off credits, Leave taken, Shift roster, Geo work locations |
| **Payroll & Compensation** (`workbook_payroll`) | Statutory details, PF options, Salaries (component amounts, any number of companies), Previous employment, Salary paid this year, Investments, HRA declarations, 12BAA declarations, Loans, Reimbursement opening, Perquisites |
| **Performance Goals** (`workbook_goals`) | Goal bank, Goals, Initiatives |
| **Asset Master** (`workbook_assets`) | Asset types, Asset sub types, Products, Assets (with custodian) |
| **Accounting Codes (JV)** (`workbook_accounting`) | 14 tabs, one per JV dimension (one upload type, one preset per tab) |

† added 2026-11-23 after the owner approved the grouping (Stage 1 review): `HolidayCalendarImports` (`holiday_calendars`,
`attendance.manage`; calendar code, name, year, status, Applies to / Future companies through `SetsApplicability`) and
`HolidayImports` (`holidays`; one row per holiday matched on calendar + date, the calendar by code, exact name or
"Name (CODE)"; never removes a holiday; refuses a calendar that also reaches companies outside the uploader's access).
Who gets a calendar stays on its Rules tab: a rule is a condition over the employee's position, which a sheet cannot
express safely, so the run's result says when a calendar has no rule yet. Both are also single uploads under Time
office. Holidays depend on calendars (`holiday_calendar` kind), so a calendar the workbook adds makes its holidays wait.

The cards show each person's real company reach (`MasterImport::companiesLine`: up to three names, otherwise "all N
companies" or "N of M companies"), not a fixed "every company".

The catalogue now holds 122 processes over 94 upload types (120 + the two holiday uploads). 78 sit in a workbook; the
other 44 stay individual for the reasons below and in the Stage 0 audit's Appendix A (9 presets of a type with a
workbook tab, 29 periodic operational inputs, 4 special-purpose, 2 not applicable). None was removed or hidden.

\* new single uploads, also listed under Core: `CompanyImports` (`company.create`; country, and the state and city the
country needs, as the company form requires; time zone and currency default from the country; the legal entity and
primary establishment are created by the model as on the form) and `CostCentreImports` (`organisation.create`).

Deviations from the proposed seven, and why:

- **Attendance records, adjustments and overrides are not in a workbook.** The same goes for payroll's monthly inputs
  (statutory overrides, arrears, variable pay, reimbursement bills and claims, income outside payroll, settlements,
  pay-group changes), performance ratings, normalisation and OKR achievement, and Engage (badges, rewards, event
  attendance). These are *periodic operational* uploads: each has its own month or cycle and its own approval, and
  none refers to another. Coupling them would let one late or wrong sheet hold up the others. Workbooks are for
  set-up and go-live data that is loaded together and refers to itself.
- **"Performance & Goals" became Goals.** Cycles, templates and rating scales are configuration screens without
  uploads, and ratings belong to the cycle's end.
- **"Engagement & Other" became Accounting codes (JV).** Fourteen tabs of one type is the clearest win. Engage has no
  relationships to consolidate. Customers → Projects (two small uploads) stays individual.
- **Presets are folded into one tab.** New hire, New hires (advance), Employee and Personal details are one Employees
  tab with every column; the workbook's choice (add / update / both) decides. Children and Dependents are the Family
  members tab (`relation`, `is_dependent` columns). Restricted holiday balance is Opening leave balances with leave
  code RH. CTC (auto) is covered by Salaries, which is multi-company (the CTC upload prepares one cycle for one
  company).
- **Employee code changes and Shortcuts stay individual.** The first re-keys employees mid-file; the second is a
  per-user preference, not master data.

## 4. Dependencies

Each worksheet declares what it **provides** (reference kind → the column with the key) and what it **needs**
(kind → the columns that refer to it). Kinds are listed in `WorkbookCatalogue::KINDS`, mapped to a model class and
key column (`company` → `Company.code`, `employee` → `Employee.employee_code`, `asset_product` → `AssetModel.name`, …).
Some kinds are not records, for example `family_member` ("this employee gets family members in this workbook").

- **Order** (`DependencyPlanner`): a worksheet runs after every *other* worksheet that provides a kind it needs. It
  runs level by level (a worksheet's level is one more than the highest level it waits for), each level in the
  workbook's own order, and a circle is refused. Sub branches under new branches and sub departments under new
  departments resolve this way. A tab referring to its own kind keeps the existing "parent added by an earlier row of
  the same file" rule.
- **Preview** (`PlannedReferences`, a scoped service that is inactive outside a workbook check). Worksheets are
  checked in run order. After each one, the keys its rows will create are *planned*, and the keys of rows with errors
  are *blocked*. Then:
  - **Code-keyed masters** (`MasterImports` subclasses: companies, departments, designations, asset types …) are
    checked **in full**. `MasterImports::lookup()` and `ReadsMasterRows::parentId()` accept a planned code as −1 ("added
    by an earlier worksheet"), the convention `parentId()` already used. `LocationImports::checkHierarchy` and
    `GeoCityImports` skip checks that need the real id.
  - **Uploads that do their own lookups** (employee-keyed profile data, products, contracts, goals …) leave a row that
    names a planned key **waiting** (`RowImports::validate`, `BankAccountImports::validate`). The preview still checks
    its required columns. It is checked in full right before its worksheet runs, once the record exists.
  - A reference to a **blocked** key is an error naming that row ("depends on row 2 of Companies, which has errors").
    Nothing is skipped silently.
- **Run**: a worksheet that runs after another one of the same workbook is **checked again** right before it runs,
  against the data as it then is. Rows that had errors in the preview stay out, even if they would now pass: the
  person approved what they saw.
- **Managers in the same file** (`EmployeeImports`, also for single uploads): a manager may be a new hire anywhere in
  the file. Hires run in row order. A manager who does not exist yet is set afterwards (`ChangeManagerAction::handle`,
  line manager from the joining date). A manager whose own row failed is not set, and the row says so.

## 5. The workbook file

- **Template** (`WorkbookWriter::template`): a *Read me* tab (purpose, rules, every worksheet in run order with "runs
  after" and its required columns), a *Columns* tab (worksheet, column, required, what to enter, example, from each
  upload's own `format()`), and one tab per worksheet the person may upload. Headers are the uploads' own labels, so
  columns match without manual mapping. Required headers are dark, every header carries a comment, and the header row
  is frozen. **Sample** = the same with each upload's example rows (the person's own codes where an upload uses them).
- **Reader** (`WorkbookReader`, OpenSpout, streaming):
  - Tabs are matched by name, ignoring case.
  - *Read me* and *Columns* are ignored. Any other unknown tab with rows is reported as a warning, never silently.
  - The first non-empty row is the header, and blank rows are left out.
  - A last column, `Sheet row`, keeps each row's Excel row number for every message and the error workbook.
  - Dates become YYYY-MM-DD, whole numbers lose ".0", and booleans become yes / no.
  - Limits: `peopleos.imports.workbooks.max_sheet_rows` (20,000) and `.max_rows` (50,000).
- **Error workbook** (`WorkbookWriter::errors`): a Summary tab (sheet problems and warnings), then per worksheet the
  rows with errors, waiting rows and rows that failed in the run. Each row carries its Sheet row, status, what to fix
  and the original cells. Bank rows show no values: their data is sealed.

## 6. Lifecycle

The workbook is an `employee_imports` row of type `workbook_<key>`. Each populated worksheet is an upload of its own
type with `parent_id`, `sheet` and `stage` (migration `2027_02_07_100001`).

1. **Upload** (`WorkbookImports::register`): `.xlsx` only. The person chooses add / update / both once, for every
   worksheet that asks.
2. **Check** (queued, `RunWorkbookImport` phase `check`): `inspect` stages each populated worksheet and matches its
   columns. A worksheet is *blocked*, with the reason, when its columns cannot be matched, its header repeats a
   column, or the person may not make that upload. Then `validate` checks every row in run order, as described above.
3. **Review**: counts (to add, update, skip, waiting, errors), each worksheet in run order and the error workbook.
   Rows that may be people already on file are decided on the Employees worksheet's own upload (Open).
4. **Approve** (`approve`): refused while a worksheet is blocked, rows await a decision, nothing would change, or the
   approver may not make every kind of upload in it.
5. **Import** (queued, `RunWorkbookImport` phase `run` → `execute`):
   - Runs worksheet by worksheet as the **uploader**. Their permissions are checked again at the start.
   - Each worksheet is approved by the engine and run by its own service.
   - The whole run is one audit operation; each worksheet adds its own.
   - A cache lock keeps two workers off one workbook.
   - Progress (current worksheet, rows done) is shown live.
6. **Results**: per worksheet added / updated / skipped / failed, and the error workbook.

**Resumable**: `RowImports`, `EmployeeImports` and `BankAccountImports` now run only rows still `pending`, and write a
row's outcome in the same transaction as its change (fixes G6). A retried job continues where it stopped. Uploads that
build something in one step (`CompensationRowImports`: compensation cycles per company) declare `resumable() = false`;
an interrupted one is marked failed for a person to look at, never run twice. `queue` is `PEOPLEOS_WORKBOOK_QUEUE`
(default true). Set it false only on a machine without a worker (the run then happens in the request).

## 7. Security and governance

- **Per worksheet**: each one needs the same right as its single upload (`ImportTypes::allowed`). The template and the
  cards show only worksheets the person may upload. A populated worksheet they may not upload blocks the workbook.
- **Approval** (`ImportGovernance::modeFor`): a workbook takes the strictest mode of its own type and every worksheet
  type it holds (workflow > maker-checker > self). Putting bank details or pay in a workbook never loosens their rule.
- **Worksheets are never approved, run or discarded on their own**:
  - approval: only while their workbook is `importing` (`ImportGovernance::refusal`);
  - discarding: not once the workbook is approved or has run (`ImportGovernance::updating`);
  - events: none of their own (the workbook's events stand for them).
- **Visibility** (`WorkbookImports::maySee`, `EmployeeImportPolicy`, Recent uploads): a workbook can hold bank details
  or pay, so it is seen by its uploader and, under maker-checker or workflow approval, by a checker once it is checked.
  Either way, only while they may make every kind of upload in it. Recent uploads lists a workbook once; its worksheets
  open from the workbook.
- **Audit**: the workbook and each worksheet are audited uploads with the uploader, approver, reason and an operation
  id. Every row change is audited by its own service as before.
- **No new permission keys.** Workbooks reuse `employee.import` plus each worksheet's own right.

## 8. What changed in existing code

| File | Change |
|---|---|
| `RowImports` | waiting rows (`PlannedReferences::deferral`, `missingRequired`); run only pending rows; outcome in the row's transaction; counts from rows; `resumable()` |
| `MasterImports::lookup`, `ReadsMasterRows::parentId` | planned code → −1; blocked code → message naming the row |
| `MasterImports::check` / `apply` | the row's record through a code → id map (`existing()`), not a per-row `lower(code)` scan; lookups cached for the whole run |
| `EmployeeImports` | manager may be a new hire anywhere in the file (set after the rows); run only pending rows; outcome in the transaction; `resumable()` |
| `BankAccountImports` | waiting rows; run only pending rows; `resumable()` |
| `CompensationRowImports` | `resumable() = false` |
| `LocationImports`, `GeoCityImports` | skip hierarchy checks on a planned (−1) id |
| `ImportGovernance` | `modeFor()`; worksheet rules; no events for worksheets |
| `ImportTypes`, `EmployeeImportPolicy`, `RecentUploads` | workbook types (labels, module, rights, service, register, visibility, link) |
| `RowImportTypes`, `ImportCatalogue` | Companies and Cost centres |
| `DataImport` page + view | Preferred imports band (cards, Template) |

New code:

- `app/Domain/Employment/Imports/Workbooks/` (`WorkbookCatalogue`, `DependencyPlanner`, `PlannedReferences`,
  `WorkbookReader`, `WorkbookWriter`, `WorkbookImports`)
- `app/Domain/Employment/Jobs/RunWorkbookImport`
- `app/Filament/Pages/MasterImport` with views `filament/pages/master-import`, `filament/imports/master-upload-cards`
  and `master-upload-history`
- theme.css section U (`.pos-mu-*`)

Single uploads behave as before, with two exceptions, both deliberate:

1. A manager who is new in the same employee file is now accepted.
2. An interrupted run no longer re-applies a row whose change had committed.

`PlannedReferences` is inactive outside a workbook check (tested).

## 9. Tests and measurements

`tests/Feature/Imports/Workbooks/WorkbookImportTest.php` covers the following.

- **Catalogue integrity**: built uploads, field names, Excel limits, no circles.
- **Planner order.**
- **A whole organisation from one out-of-order workbook**, including blank rows and Sheet row numbers.
- **Errors**: a dependent row naming the failed row, and the error workbook's rows.
- **Employees**: new hires with managers further down, and family rows waiting for their hire.
- **Approval**: the strictest rule (maker-checker on bank details), and worksheets that cannot be approved or
  discarded alone.
- **Blocked worksheets**: refused rights, unmatched columns and unknown tabs.
- **Templates**: they read back and map without help.
- **Single uploads are unchanged.**
- **Resume**: no row runs twice.
- **Recent uploads**: listing and visibility.
- **Pages**: the Data import band and Template download, and the master upload page from upload to finished import.

`WorkbookVolumeTest.php` measures volume. It is off by default; run it with `WORKBOOK_VOLUME=100,1000,10000`.
Measured 2026-11-23 on SQLite in memory (indicative; the workstation was shared with other sessions). Check and run
are the background job's time, and peak memory includes the test framework's ~160 MB:

| Workbook | Rows | Check | Run | Peak memory |
|---|---|---|---|---|
| Organisation (1 company + N departments in the same workbook) | 101 | 0.3 s | 0.6 s | 159 MB |
| | 1,001 | 1.4 s | 5.7 s | 167 MB |
| | 10,001 | 12.7 s | 122 s | 171 MB |
| Employees (N new hires, managers further down the same sheet, + N family rows waiting for them) | 200 | 0.4 s | 10.3 s | 173 MB |
| | 2,000 | 3.6 s | 204 s | 187 MB |

Memory stays flat: the reader streams, rows are walked with cursors, and lookups are maps loaded once. Hiring costs
about 0.2 s per person (the full hire action: person, employment, position, reporting line, events), so the most one
Employees worksheet takes (5,000 people, below) runs in about 17 minutes. That is under the job's 3,600 s timeout, and
a retry resumes where the run stopped.

An Employees worksheet is the employee upload, so it keeps that upload's limit of 5,000 people per file
(`peopleos.people.import_max_rows`). A larger sheet is refused when the workbook is checked ("The file has more than
5000 rows; split it."), the workbook cannot be approved, and nobody is hired; the volume test asserts this for 10,000
(2026-11-24). Other worksheets take up to their upload's limit, and a whole workbook up to 50,000 rows.

**MySQL 8.4** (`tests/MySql/WorkbookMySqlTest.php`, run 2026-11-23 on a private throwaway server: own data folder and
port, root on 127.0.0.1, database `hcm_workbook_mysql`; `PEOPLEOS_MYSQL_WORKBOOK_DB`, `DB_HOST` / `DB_PORT` /
`DB_USERNAME` / `DB_PASSWORD`, `WORKBOOK_VOLUME`):

| Workbook | Rows | Check | Run | Peak memory |
|---|---|---|---|---|
| Organisation (1 company + N departments, half the rows naming the company by name) | 1,001 | 4.3 s | 15.3 s | 171 MB |
| | 10,001 | 41.7 s | 159.5 s | 193 MB |
| Employees (1,000 new hires, managers further down the sheet, + 1,000 family rows waiting for them) | 2,000 | 8.0–8.9 s | 210–237 s | 185–191 MB |

The MySQL run found two faults that SQLite hides. Both are fixed and covered by that test:
- Count-by-status queries on an upload's rows inherited the relation's `ORDER BY row_number`. MySQL's
  `ONLY_FULL_GROUP_BY` (Laravel's strict mode, as in production) refuses that, so a worksheet that had preview errors
  failed when re-checked before its run. They now use `reorder()`.
- MySQL stores JSON as `"state": "blocked"` (with a space), so the preview's "blocked" count matched nothing there.
  Both spellings match now.

The volume test found a quadratic cost in the existing code-keyed uploads, now fixed. `MasterImports` found each
row's record with `lower(code) = ?`, which cannot use the code index, so every row scanned the whole list while the
run kept adding to it (10,000 departments: 151 s). Rows are now matched through a code → id map loaded once per check
or run (`MasterImports::existing`), and lookups of other masters are cached for the run. A smaller growth remains in
the per-row write path (audit hashing and model events: about 5 s per 1,000 rows at the start, 7.6 s by row 4,000). It
is not specific to workbooks and is worth profiling on MySQL.

## 10. Limitations and follow-ups

- **An Employees worksheet takes up to 5,000 people**, the employee upload's limit (section 9). More go in a second
  Employee Master workbook.
- **Name lookups cover registered masters only.** States, districts and cities, vendors, KRAs and skill scales stay
  code-only: they are not in the master catalogue, or a code column narrows them.
- **A manager who is hired in the same file is named by employee code.** Managers already on file may be named by
  work e-mail or full name. Employees' own rows in profile uploads (family, bank …) are identified by employee code
  only: matching a person by name is never safe enough to write their data.
- **The name review panel** is on the Data import review step and the master upload page. The per-type upload screens
  (Employee import, Workforce / Time office / Payroll uploads) show the resolver's message on the row: ambiguous names
  list their candidates with codes.
- **Waiting rows** get a required-column check in the preview. Their full check runs immediately before their
  worksheet runs. A row that fails then is listed in the error workbook, and the other rows still go in.
- **Applicability for company-limited uploaders.** Previewing "Applies to" on a *new* record works for them:
  `EntityApplicability::plan()` treats an unsaved record as reaching nobody (covered by `ApplicabilitySecurityTest`).
  An earlier version of this note described that as pending; it was fixed before the integration.
- **Bank details resumed after an interruption** report only the last attempt's counts on that worksheet (rows and
  results are complete).
- A worksheet whose rows await a person's decision at run time (possible duplicates that appeared after approval) is
  failed with that reason; its dependants' rows then fail on their missing references.
- **Locally there is no queue worker for this project.** Run `php artisan queue:work`, or set
  `PEOPLEOS_WORKBOOK_QUEUE=false`.

## 11. Deploying

1. `php artisan migrate` (adds `employee_imports.parent_id`, `sheet`, `stage`; reversible).
2. Make sure queue workers run (`docs/operations/queue-and-scheduler.md`). `RunWorkbookImport` has a 3600 s timeout,
   under `retry_after` 3900.
3. `npm run build` (theme section U) and `php artisan optimize` (component and route caches pick up the new page).
   `deploy.sh` does both.
4. No permissions to sync. No settings to change.

## 12. Integration phase (2026-11-23): names, applicability, the remaining masters, R1

**Name-based references** (`Workbooks\ImportReferences`, the bridge to `Organisation\Resolution\MasterResolver`).

- Opened per upload check and run (`RowImports`, `EmployeeImports`), and once per workbook check, so names an earlier
  worksheet adds resolve as planned and one review covers the workbook. The session acts as the uploader: records
  outside their access are never matched, suggested or named, and every lookup is bound to the organisation.
- Order at every reference cell:
  1. `(none)`: clear the link (never looked up).
  2. The code, exactly as before (`MasterImports::lookup`, `ReadsMasterRows::parentId`, the employee and job-change
     uploads' code maps).
  3. A code an earlier worksheet adds (`PlannedReferences`).
  4. The row of an earlier worksheet with errors (blocked).
  5. The resolver: exact name (case, spacing, quotes and dashes ignored), `Name (CODE)` (both must describe the same
     record, else a conflict), or a mapping someone confirmed in the review.
- An unknown value keeps the message code files always had. Ambiguous names, near misses ("Did you mean …?") and
  name/code conflicts are row errors with the candidates, and go to the review panel (`ReferenceReview`, stored as
  `options.references`). They are never chosen automatically. A decision re-checks the upload (the workbook in the
  background).
- At run time names resolve again (deterministic; confirmed mappings apply). The employee upload replaces names by
  codes before hiring, so `OrganisationCodes` (also the public API's) stays code-only.

**Company applicability.** When a row names a company (a `company_code` column, or the employee and job-change
uploads' company), every other reference must apply to it. This holds by code as well as by name: company-owned units,
explicit company choices and later companies, through `EntityApplicability`. This is the one deliberate change for code
files, and only for rows that name a company: a cost centre or level of another company was accepted before and is
refused now, with the reason.

**"Applies to"** (`Concerns\SetsApplicability`, on every organisation master registered as shareable: companies'
cost and profit centres, regions, branches, business units, divisions, departments, teams, levels, grades, job
families, designations, employment types, employee categories, work modes and dimension values).

- **Applies to**: `all`, one company (that company only; for a company-owned unit, its own company), or several
  separated by `;`. Companies are given by code or name. Blank keeps what is on file.
- **Future companies**: with `all`, `yes` (default) or `no`.
- Checked with `EntityApplicability::plan()` as the uploader, and `editableBy()` for updates: a record that also
  reaches companies outside their access is refused, as on its screen.
- Written with `apply()` in the row's transaction. A choice that means what a new record means anyway writes nothing,
  as `ApplicabilityUi::saveOnCreate`. Moving an explicitly limited company-owned unit to a company outside its reach
  needs Applies to.
- Overrides and clones are untouched: an upload changes the shared record only; companies' overrides keep precedence,
  and clones never follow their source (tested).

**Remaining masters.**
- Divisions, Teams, Profit centres (each optionally in a company), Job families, Employment types, Employee categories
  and Work modes: one `SimpleMasterImports` base plus a few lines each.
- Organisation structure (`OrganisationTreeImports`): one row per unit, giving the unit type and code and the parent's
  type and code. It places units through `OrganisationTree::attach` / `move`, so the tree's nesting rules apply and
  departments keep their parent department in step.
- All are in the Data import Core head and in the Organisation structure workbook (26 worksheets). The Unit tree tab
  runs last and its rows wait for the units the workbook adds.

**R1 upload rules** (master-relationships programme; built here at the applicability session's request, spec from its
owner).
- `(none)` (any case, spaces ignored; `App\Support\Imports\ClearValue`) removes an optional link. Blank still keeps
  it. On a new record, `(none)` means no link.
- `(none)` is refused on required links: a branch's company, a sub department's or sub branch's parent, every
  required column. The message is "<Column> is required here; (none) cannot be used."
- The preview shows the removal ("Level: L5 → (removed)"), and the run's result names it.
- A business unit's company is optional, as on its form.
- A designation's department column reads "Usual department (information only)". Its key is unchanged, so existing
  files still work.
- Every optional link column's guide says how to remove it.

**Preview and results.** Each worksheet shows its rows:
- checked in full;
- waiting for an earlier worksheet, with which worksheet and how many;
- blocked by a row with errors in an earlier worksheet;
- with other errors.

What it will add, update and skip is shown too. "Rows" opens the first 50 rows to look at, each with its sheet row,
status, what to know and the worksheet and row it depends on. Beside the approval, a note says when the final check
happens.

After the run, every worksheet is *Completed*, *Partially completed*, *Failed* or *Not run* (`WorkbookImports::outcome`).

## 13. Designation company values and employment data requirements (2026-11-24)

**Designations upload: Company column** (master relationships R3; `DesignationImports`).

| Company cell | What the row does |
| --- | --- |
| Blank | The row is the designation itself, exactly as before. |
| A company code or name | The row gives that company its own level, grade and job family of the designation, from today, as configuration overrides. The shared values stay for everyone else. |

- **On a Company row:**
  - `(none)` ends the company's own value, so the shared one applies again; blank keeps it;
  - the other columns must be blank (they describe the shared designation);
  - the uploader needs `configuration.override` with the designation permission, and access to that company;
  - a value the company may not use is refused.
- **Workbooks.** A Company row for a designation, or company, added earlier in the file or workbook is applied
  when the row runs.

**Employee and job-change uploads** (master relationships R2) check each new hire and each position change against
the employment data requirements before approval:
- a rule on Block makes the row an error, with the policy's message;
- a rule on Warn leaves the row valid and adds the warning as a note, in the preview and in the run result.

Without a requirements policy nothing changes.

## 14. Names first (2026-11-26)

Every worksheet takes names: reference columns are headed by the record ("Company", "Department", "Manager") and a master
worksheet's Code may be left blank (a code is made from the name, or the record of that name is changed). A master row is
planned under its code and its name (`WorkbookImports::publish()`), so later worksheets may name a record an earlier one
adds. The Read me rules and the Master import page say so. Details: `name-resolution.md`, "Names first in every upload".
