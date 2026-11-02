# Data import

Written 2026-10-31. Describes what is implemented.

One place for every upload an organisation can make (HROne's Import → Select process), under the head it belongs to.
Admin › Data import has two tabs: **Import** (`App\Filament\Pages\DataImport`, `/admin/data-import`) and
**Recent uploads** (`RecentUploads`, unchanged apart from the tab strip and the process name per row).
Recruitment is not listed: it runs in the separate recruitment system (owner decision 2026-10-31).

## The catalogue

`App\Domain\Employment\Imports\ImportCatalogue` lists the heads (Core, Workforce, Time Office, Payroll, Performance,
Engage, Asset, Project Management, Integration) and every process under them. A process is an upload type of the
staged pipeline (`ImportTypes`) plus an optional preset:

| Preset | Effect |
|---|---|
| `mode` | The intent is fixed (`new` for New hire, `update` for Personal details) instead of asked. |
| `defaults` | Field values for blank cells, kept on the upload as `options.defaults` and read by `RowImports` (e.g. Opening leave balance: `kind = opening`). A required field with a default needs no column. |
| `columns` | The fields its CSV format, sample file and format guide show, in order. The upload still accepts every field. |
| `na` | PeopleOS deliberately does not take this from a file; the page says why (Bonus slabs: rates come only from the verified Payment of Bonus rule). |

Several processes share a type: Leave balance, Opening leave balance and Restricted holiday balance are all
`leave_balances`; New hire, New hires (advance), Employee and Personal details are all `employees`; the JV mappings
are one `jv_mappings` type with a dimension per process. A process shows only when its type is registered and the
person may run it (`ImportTypes::allowed`: the upload's own screen checks), or when it is `na`.

## The four steps

1. **Select process**: search, then the heads down the left (`x-pos.browse`, "All modules" first, or "All matches"
   while searching) and the open head's processes on the right. A process links to `?process=<key>`.
2. **Import intent**: what the file is for (`ImportTypes::modes`: the service's own `MODES`, the generic
   insert / update / both of a one-row-one-change upload whose `intents()` is true, or nothing for punches), plus the
   upload's own choices (attendance month and blank days, company and default effective date for salaries, time zone
   for punches).
3. **Prepare your data**: **Download CSV format** (the header row) and **Download sample file** (the header row with
   example rows), both from the upload's own `template()` limited to the process's columns, with its defaults in the
   example cells (`ImportCatalogue::csv`); the format guide (`filament.imports.csv-format`); the file.
4. **Review & fix**: the file is registered (`ImportTypes::register`, which re-checks access), inspected, mapped from
   the suggested mapping and validated. If required columns cannot be matched, the page shows a column matcher. Then
   the counts (to add, to update, skipped, with errors, to decide), the first 50 rows with errors and
   **Download results**. **Re-upload fixes** discards the upload and returns to step 3. **Import N row(s)** approves
   (reason required) and runs it, when `ImportGovernance::mayApproveNow` allows; under maker-checker or workflow
   approval the upload waits in Recent uploads. Employee rows that may be people already on file are decided on the
   upload's own screen.

**Look** (theme.css section N, `.pos-di-*`; the format guide `.pos-fmt-*` is shared by every upload dialog and "File format"
view): the process header takes its module's hue (`DataImport::TONES`, the Actions panel's where both have the module) and
shows the chosen intent; the intent choices are cards; step 3 puts the file first (a drop zone inside a `<form>` whose button
waits while the file uploads — Filament's file field announces `form-processing-started` / `-finished` on the nearest form)
with the template panel beside it (sample file, CSV format, the columns every row needs, tips) and the format guide below;
the review shows the counts as tinted tiles. The step-3 action is `uploadAndCheck`: a component method named like a
`$wire` alias (`upload`) is shadowed in the browser (tests/Feature/Architecture/LivewireReservedMethodsTest.php).

**Every upload screen looks the same** (2026-11-02): the upload dialogs of the per-type screens (Employee, Bank details,
Salary, Monthly attendance, Punch, and the shared Time office / Payroll / Workforce / Data uploads screen) put the file first
(`ImportActions::fileField()`: the drop zone, class `pos-di-dropzone`), then the choices as cards (`modeField()`, class
`pos-di-choices`; `ImportActions::modes()` now defers to `ImportTypes::modes()`, so one-row-one-change uploads offer the
same insert / update / both choice everywhere, default both), then the format guide open underneath
(`formatSection()` / `formatSectionFor()` render `filament.imports.format-guide-block` — a view, not a form section, so the
form-wizard engine keeps the dialog one page). Dialogs are 4xl wide, submit with "Upload and continue", have no "create
another" and no record-form review footer. "Download template" reads "Download sample file". Each upload's own page opens
with `filament.imports.upload-summary` (`ImportActions::summary()`): the process and module tint, the pipeline (uploaded →
columns matched → rows checked → approved → run), the next step and the counts; the old Rows section is folded.

Only the uploader works on an upload on this page (`DataImport::import()`); everyone else opens it from Recent uploads,
on the screen of its area: Workforce, Time office and Payroll uploads keep their screens; the other heads share
**Data uploads** (`DataUploadResource`, not in the menu).

## Building an upload

One-row-one-change uploads extend `RowImports` (fields → check → apply through the owning domain service; each run is
one audited operation). Code-keyed masters extend `MasterImports`: a code on file is updated (unchanged rows skipped),
any other is created with the model's defaults (active from today); an update of a governed configuration record goes
to the Configuration Change Centre when the organisation requires approval, as its edit screen does. Register the type
in `RowImportTypes::TYPES` with its module permission and area (the head). Every upload has `format()` (columns:
label, required, what to enter, example; notes) and at least two example rows, which make its sample file;
`DataImportTest` checks every built process for both.

`employee_imports.type` is 32 characters (migration `2027_01_10_200001`).

## DI-2 — Workforce

Ten upload types under the "Data import DI-2" anchor of `RowImportTypes`, area `workforce` (so they are worked on the
Workforce uploads screen as well as on Data import). Every type has a CSV format and a sample file whose example rows
use the tenant's own codes where it has them. Rows are checked as the uploader (the same right and organisation scope
the write path checks) and run as the runner; blank cells keep what is on file; nothing is removed by an upload unless
the type says so (nominations replace a benefit's set, shortcuts can be unpinned).

Shared helpers: `App\Domain\Employment\Imports\Concerns\WorkforceRows` (uploader, `ProfileChangeGuard` refusals,
yes / no, option-list codes or labels, genders, dates, the `bulk_upload` change origin stamped on audit, timeline and
events).

| Process (catalogue key) | Type | Class | Writes through | Upload right | Intents |
|---|---|---|---|---|---|
| Family details, Children, Dependents (`workforce.family`, `.children`, `.dependents`) | `family_members` | `People\Imports\FamilyMemberImports` | `ChangeFamilyMemberAction` (add: employee.create, update: employee.update) | employee.update | new / existing |
| Education (`workforce.education`) | `qualifications` | `People\Imports\QualificationImports` | `ChangeQualificationAction` (masters by name) | employee.update | new / existing |
| Work experience (`workforce.work_experience`) | `work_experience` | `People\Imports\ExperienceImports` | `ChangeExperienceAction` | employee.update | new / existing |
| Nominee details (`workforce.nominees`) | `nominations` | `People\Imports\NominationImports` | `ChangeNominationsAction::check` / `replace` (one set per employee and benefit) | employee.update | new / existing (per set) |
| Employee skills (`workforce.employee_skills`) | `employee_skills` | `People\Imports\EmployeeSkillImports` | `ChangeProfileSkillAction` (profile skill) + `SkillProfiles::import` (skill history, source "imported") | employee.update (+ skills.manage for levels) | new / existing |
| Employee other details (`workforce.other_details`) | `custom_field_values` | `Employment\Imports\CustomFieldValueImports` | `ProfileChangeGuard` (employee.update) + `CustomFields::store` | employee.update | new / existing |
| Employee (advance) (`workforce.employee_advance`) | `position_changes` | `Employment\Imports\PositionChangeImports` | `AssignPositionAction` + `ChangeManagerAction::change` | employee.position | none (changes only) |
| Employee code (`workforce.employee_code`) | `employee_codes` | `Employment\Imports\EmployeeCodeImports` | `ChangeEmployeeCodeAction` (new) | employee.update | none |
| Employee settings (`workforce.employee_settings`) | `employee_settings` | `Employment\Imports\EmployeeSettingImports` | see below | employee.update (+ each setting's right) | none |
| Shortcut (`workforce.shortcut`) | `shortcuts` | `Experience\Imports\ShortcutImports` | `ExperienceNavigation::toggleFavouriteModule`, run as the employee's user (`ScreenAccess::as`) | user.update | none |

### Per type

- **Family members.** Matched by employee + relation + name (not case-sensitive): update, else add. Relation takes the
  organisation's `family_relations` codes or labels, plus husband / wife / partner → spouse, dad / mom … → father /
  mother, and child / kid → son or daughter, parent → father or mother, sibling → brother or sister by the gender (or by
  the one member of that name already on file under either relation). The Children process (default `relation =
  child`) and its sample are therefore valid; Dependents defaults `is_dependent = yes`. Fields: relation, name, date of
  birth, gender, dependent, occupation, phone, in school, in a hostel, nominee, nominee share %, remarks.
- **Education.** Qualification and specialisation are matched to the masters (Setup → Qualifications) by name; other
  names are kept as typed (as the Education tab allows). Matched by employee + qualification (+ specialisation when
  given; the only such qualification without a specialisation is filled in). Mode of study from `qualification_modes`.
- **Work experience.** Matched by employee + employer (not case-sensitive) + from date. A fresher is refused (the model
  refuses it too).
- **Nominee details.** The rows of one employee and benefit are a set, checked together by
  `ChangeNominationsAction::check` (family members of the profile, once each, shares > 0 totalling 100, a minor nominee
  with a guardian) and saved together by `replace` (the first row of the set saves it; the others confirm their nominee
  was saved). A set is "new" when the benefit has no nominations on file, "existing" otherwise, skipped when equal; a
  nominee on file but not in the file is removed, and the check names them on the set's first row. A nominee already on
  file keeps the address, remarks and guardian on file for blank cells.
- **Employee skills.** The profile skill (`person_skills`: what My profile, the career passport and skill analytics
  show) through `ChangeProfileSkillAction`; level on the organisation's profile scale (`SkillProficiencies`). Optional
  assessed / target levels on the skill's own scale go to the Phase 8 skill history (`employee_skills`, the Employee 360
  Skills tab and gaps) through the new `SkillProfiles::import()`: source `imported` (already in `EmployeeSkill::SOURCES`
  and `SkillProfiles::PRECEDENCE`), never verified, supersedes only the earlier imported level; needs skills.manage. The
  same levels again change nothing.
- **Employee other details.** Long format (employee code, field key or label, value), live employee custom fields only;
  each value read as its type (number, date, yes / no, dropdown option by value or label, multiselect separated by `;` or
  `|`) and validated with the field's own rules before `CustomFields::store` validates and audits it again.
- **Employee (advance).** One dated change per row: any of the 13 position dimension codes (`OrganisationCodes`), a
  change type (`position_change_types` without hire; default transfer) and the line manager (employed, within scope).
  Pre-checks mirror `AssignPositionAction` (after the current position's start, no later position on file); an
  employee's rows must be in date order; a row already true on that date is skipped.
- **Employee code.** New action `App\Domain\Employment\Actions\ChangeEmployeeCodeAction`: the hire form's rule
  (alpha-dash, ≤ 32), free in the whole tenant compared without case (`EmployeeCodeGenerator::holder`,
  `EmployeeCodeTakenException`), checked again under the employee row lock, audited on the employee (before → after)
  and recorded on the timeline in the new category `employee_code` (`TimelineCategories`, kind employment). An employee
  and a new code may each appear once per file; a code held by another employee is refused even if that employee changes
  code in the same file (swap through a temporary code in an earlier file).
- **Employee settings.** Columns, each through its screen's path and right: device user id (employee record, as the
  employee edit screen and employee import; employee.update; unique), show birthday / wedding anniversary to colleagues
  (`UpdatePersonAction`, as the employee edit screen; employee.update), work schedule + from (new
  `App\Domain\Attendance\Services\ScheduleAssignments`, now also used by the Employee 360's "Assign work schedule";
  attendance.manage), salary / reimbursement pay mode + paying account + from (`PaymentModes::set`, as Payroll → Payment
  modes; payroll.manage), sign-in time zone (the user record, as Users → Edit; user.update).
- **Shortcut.** The area bar's favourites (`favourite_modules`, max 30, the oldest drops off). The page is the menu name
  (or "Area › Page" when ambiguous, or the class name); the employee's catalogue is built as that user
  (`ScreenAccess::as` + `ModuleCatalogue::for`), so only pages they may open are pinned. Remove = yes unpins.

### Schema, permissions, other changes

- Migration `2027_01_12_200001_add_occupation_to_person_family_members` (nullable `occupation`, 120): HROne's family
  details carry it. Rule added to `ChangeFamilyMemberAction`; field and toggleable column on the Employee 360 "Family &
  nominees" tab. No new tables (DataInventory unchanged: `person_family_members` is already classified).
- No new permission keys.
- Catalogue (Workforce entries only): "about" texts of Children and Employee settings.

### Decisions (2026-11-01, settled by market practice at the owner's request)

- **HROne employee settings with no per-employee equivalent stay organisation- or policy-level.** Sign-in on several
  devices, two-factor sign-in, single sign-on id and SCIM are identity and security policy (Workday, SuccessFactors and
  Darwinbox set them by organisation, role or identity provider, never by a spreadsheet column); module licences are
  subscription entitlements; Wi-Fi / mobile / geo check-in comes from the check-in policy of the employee's group; profile
  privacy is each person's own choice on Preferences; sign-in invitations go through Employees → "Invite to sign in"
  (they send e-mails, so never from a file). The Employee settings upload lists these in its format notes.
- **A code change keeps attendance devices working.** `ChangeEmployeeCodeAction` copies the old code into the device user
  id when the employee has none (and no other employee uses that id), and says so on the timeline. Punches match the
  device user id first, so devices enrolled with the old code keep finding the person; an employee with a device user id
  keeps it. This is how biometric enrolment ids are kept apart from employee codes in Darwinbox and Keka.
- **Nominations replace a benefit's set.** A nomination is a declaration whose shares total 100 %, so a file row set per
  employee and benefit replaces what is on file (as the screen does); an "add" mode would leave sets that do not add up.
- **Occupation** is on the Employee 360 tab, the hire wizard's family section and My profile (self-service change
  requests), not only the upload.

## DI-1 — Core, Project Management, Integration

Every process of the three heads is built: 17 Core processes, Projects, and the 12 JV mapping processes. Each has a
CSV format (the header row), a sample file (two example rows that use codes on file where there are some) and a
format guide. `CoreImportsTest` runs every one of these processes from its own sample file, in dependency order,
without a single error.

### Types and what each writes through

| Process (catalogue key) | Type | Service | Permission | Writes through |
| --- | --- | --- | --- | --- |
| Branches (`core.branches`) | `locations` | `Organisation\Imports\LocationImports` | `organisation.create` | `Location` save (audited; governed updates go to the Change Centre); `LocationHierarchy` checks on save. Never changes a location's parent. |
| Sub branches | `sub_branches` | `SubBranchImports` | `organisation.create` | `Location` with `parent_location_id`; a new sub branch takes its branch's company (and, for blank cells, country, time zone, region, business region); a location on file must already be in the branch's company. |
| Regions | `regions` | `RegionImports` | `organisation.create` | `Region` (new master). |
| Business regions | `business_regions` | `BusinessRegionImports` | `organisation.create` | `BusinessRegion` (new master). |
| Business units | `business_units` | `BusinessUnitImports` | `organisation.create` | `BusinessUnit` (medium risk: updates wait in the Change Centre when approval is on). |
| Departments | `departments` | `DepartmentImports` | `organisation.create` | `Department`; never changes a parent; refuses moving a sub department, or a department with sub departments, to another company. |
| Sub departments | `sub_departments` | `SubDepartmentImports` | `organisation.create` | `Department` with `parent_department_id` (parent required, same company; `DepartmentHierarchy` refuses loops and moves the designer node). |
| Designations | `designations` | `DesignationImports` | `people_setup.create` | `Designation` (level / grade / department / job family codes, experience range checked low to high, wage skill category, hazardous flag). |
| Grades | `grades` | `GradeImports` | `people_setup.create` | `Grade` (level code, rank). |
| Levels | `levels` | `LevelImports` | `people_setup.create` | `Level` (rank). |
| Skill master | `skills` | `People\Imports\SkillImports` | `skills.manage` | `Skill` (category, type from `peopleos.skills.types`, scale by code). |
| Designation wise skills | `designation_skills` | `Career\Imports\DesignationSkillImports` | `career.manage` | `CareerArchitecture::publishRequirements` (see below). |
| States / Districts / Cities | `geo_states`, `geo_districts`, `geo_cities` | `GeoStateImports`, `GeoDistrictImports`, `GeoCityImports` | `organisation.create` | `GeoPlace` (one table with a kind; the model checks the links on every save). |
| Dimension | `dimensions` | `DimensionImports` | `organisation.create` | `DimensionValue` (parent on file or an earlier row; no loops). |
| Customers | `customers` | `Projects\Imports\CustomerImports` | `project.manage` | `Customer`. |
| Projects (`projects.projects`) | `projects` | `Projects\Imports\ProjectImports` | `project.manage` | `Project` save + `Projects::addMember` for the `Member codes` column (`;`-separated; adds, never removes; current or joining employees, D4). |
| JV … mapping (12 processes) | `jv_mappings` | `Integration\Imports\JvCodeImports` | `integration.manage` | `JvCodes::set` (the process fixes the dimension; its template has Code, JV code, Accounting system code). |

Master uploads are code-keyed (`MasterImports`): a new code creates, a code on file updates (blank cells keep the value
on file), unchanged rows are skipped, a code appears once per file. Parents in the same list (sub branches, sub
departments, dimension values) may be added by an earlier row of the same file. Every upload supports the new /
existing choice.

### Decisions

Design decisions of the package:

- **Designation wise skills reuse role requirements.** PeopleOS already models the skills a designation needs:
  `Career\Models\RoleRequirementVersion` (immutable, effective-dated versions with skills on the pinned skill-scale
  version), maintained through `CareerArchitecture::publishRequirements` (Talent → Role requirements). No new table.
  Because versions are immutable and dated by day, all rows of one designation in a file are published as ONE new
  role-wide version from the row's `Effective from` (blank: today): it carries over the skills, competencies,
  experience, certifications, learning and notes of the version on file and adds or re-levels the skills of the
  file. An upload never removes a skill. A second version for the same designation needs a later `Effective from`.
  The level is a value or a level name on the skill's scale; checking never creates the default scale.
- **JV codes get their own table.** `integration_mappings` maps ONE external value to ONE record (unique per tenant,
  system, dimension and external value) and needs an integration system, while a JV code is commonly shared (many
  departments post to one ledger) and usually applies to every accounting system. `jv_codes` keys the record instead:
  one code per dimension, record and system (`system_key` = system id or '' so the unique key also holds without a
  system). `JvCodes::codeFor()` / `codes()` give a system's own code, else the code for every system. A branch is a
  location without a parent and a sub branch one with a parent (departments likewise), so a record sits in one JV
  dimension. Records are found by code across the organisation (system context, like the Integration Hub's lookups).
  Screen: Integrations → JV codes. Besides the twelve catalogue dimensions, `pay_component` and
  `pay_component_payable` hold the ledgers of pay components (generic JV upload or the screen).
- **Places are one table** (`geo_places`, kind state | district | city; code unique per kind). A state may name its
  region on the platform ISO list (`country_regions`, by country and code, matched like location state codes).
  Districts and cities take their state's country. Screen: Organisation → Places.
- **Customers are organisations** (`customers`), separate from business partners (individual contacts).
- **Projects** live in a new `app/Domain/Projects` domain: `Project` (dates read start to end; customer, company and
  manager checked on save) and `ProjectMember` (employee-scoped, role, allocation 0–100 %, dates); the `Projects`
  service adds members (once at a time) and takes them off (to_date). Screens: Workforce → Customers, Projects
  (Members tab). Uploads find managers and members within the uploader's organisation scope.
- **Locations** gain `parent_location_id`, `region_id`, `business_region_id`. `LocationHierarchy` (on every save, and
  as form rules on the Location screen) keeps a sub branch in its branch's company, refuses loops, and keeps a branch
  with sub branches in its company. The Location screen shows the parent branch, region and business region.
- Region, BusinessRegion and DimensionValue are low-risk governed configuration (edit pages use `GovernedEdit`).
- The "project" permission prefix belongs to the Workforce capability (`Capability::Workforce`), like the screens.

Owner decisions, decided and built (owner instruction 2026-11-01: no open decisions; each follows how Workday,
SAP SuccessFactors, Darwinbox and HROne do it; each has a test in `Di1DecisionsTest`):

- **D1 — Employees are assigned a dimension value: yes, on the position.** Workday custom organizations and
  SuccessFactors custom foundation objects sit on the job record like any org unit, so reporting, policies and
  costing can use them. `employee_positions.dimension_value_id` (migration `200007`) is an optional position field in
  `EmployeePosition::DIMENSIONS`, so it is dated, carried through transfers (`AssignPositionAction`), shown in the
  timeline and audit diffs, offered on the hire wizard and position-change forms, usable in policy assignment rules
  (`EmployeeRuleContext`), salary structure applicability, expense limits and transfer requests, and resolvable by
  code (`OrganisationCodes` `dimension_code`, for the API and position uploads). A value on a position cannot be
  deleted (restrict FK; the delete button hides).
- **D2 — The JV codes feed a payroll journal voucher.** Darwinbox and HROne post a finalized payroll run to the books
  as one balanced JV by ledger and cost segment. `Payroll\Services\JournalVoucher` (Payroll → run → "Journal voucher
  (JV)", same gate as the bank file; audited export): earnings, reimbursements and employer contributions are debited
  to each component's ledger (`pay_component` JV code, else the component code) split by the segment chosen in
  Setup → Payroll (`payroll.jv.segment`: department by default; company, branch, business unit, designation, grade,
  level, region, the organisation's dimension, employee or none; sub branches / sub departments use their own JV
  dimension); deductions are credited to their ledgers; employer contributions are credited to the component's
  payable ledger (`pay_component_payable`, else its ledger); net pay is credited to `payroll.jv.net_pay_account`
  (blank: `NET_PAY`). Amounts are summed in paise, so debits equal credits; an accounting system's own codes can be
  chosen. Only finalized or paid runs.
- **D3 — Changing a record on file needs the module's update permission.** Workday EIB and SuccessFactors imports
  apply the same create / edit security as the screens. Org-master uploads run with `organisation.create`
  (`people_setup.create` for designations, grades, levels); a row that would change a record on file is an error
  unless the uploader also holds `organisation.update` / `people_setup.update` (`RequiresUpdatePermission`). New
  records still load.
- **D4 — Only current or joining employees go on a project.** Staffing tools (Darwinbox projects, SuccessFactors
  allocations) offer active employees only; history stays. `Projects::addMember` and the `Project` model refuse a new
  member or manager who has left or never joined (exited, alumni, not joined); someone already on the project, or
  already its manager, stays when they leave. The upload checks the same rule before anything runs, and the screen
  offers only current and joining employees.
- **D5 — The organisation names its dimension.** Configurable terminology is standard (Workday custom organization
  types, SuccessFactors object labels, HROne's renameable masters). Setup → Core HR → "Name of your own organisation
  dimension" (`organisation.dimension_label`, e.g. Vertical) names the screen, its values and the position field;
  blank keeps "Dimension". Upload headers stay fixed so templates do not change with the name.

### New tables and columns (migrations `2027_01_11_200001` … `200007`; all in `DataInventory`)

`regions`, `business_regions` (organisation); `locations` + `parent_location_id`, `region_id`, `business_region_id`;
`geo_places`, `dimension_values`, `customers`, `projects`, `project_members` (organisation); `jv_codes` (integrations);
`employee_positions` + `dimension_value_id` (employment, D1).

### Settings

`organisation.dimension_label` (Setup → Core HR, D5); `payroll.jv.segment`, `payroll.jv.net_pay_account` (Setup →
Payroll, D2). Setup → Core HR also links Regions, Business regions, Places and Dimension values.

### Permissions

New: `project.view`, `project.manage` (catalogue group `project`; Tenant HR Admin gets `project.*`, Auditor
`project.view`) → run `php artisan peopleos:sync-permissions`. Existing keys used: `organisation.create`,
`people_setup.create`, `skills.manage`, `career.manage`, `integration.manage`.

### Framework change

`ImportCatalogue::csv()`: when an upload's examples hold two rows or more for a process's default value (the JV
uploads give two per dimension), the process's sample shows only those rows; other uploads' samples are unchanged.

No open owner decisions (D1–D5 above are decided and built).

## DI-4 Payroll (and Workforce → Previous employment)

Every type is a `RowImports` subclass registered under the "Data import DI-4" anchor of `RowImportTypes` (area
`payroll`; `previous_employment` area `workforce`). Rows only reach payroll money through the owning services or the
tagged payroll inputs they feed; finalized payroll is never changed (model guards and the services refuse it);
statutory values come only from verified rule data; Compensation is read through `CompensationOutput` and written only
through compensation cycles. Shared cell parsing (amounts with separators, yes / no, months, financial years, sample
employee codes, duplicate rows) is the trait `Payroll\Imports\PayrollImportCells`.

| Type (process) | Class | Writes through | New / existing (`intents`) | Permission (besides `employee.import`) |
|---|---|---|---|---|
| `tax_12baa` (12BAA declaration) | `Compliance\Imports\TaxCreditImports` | `TaxIncomeEntries::recordTaxCredit` (new kind `tax_credit`: TDS / TCS, provision, deductor, TAN, tax) | adds only; the same certificate line is skipped | `payroll.manage` |
| `previous_employment` (Workforce) | `Compliance\Imports\PreviousEmploymentImports` | `InvestmentDeclarations::save` — the declaration's previous employer (the "Previous job" step: taxable salary, TDS, encrypted name / TAN / dates / PF / PT / exemptions used) | yes: replaces the previous employer | `payroll.manage` |
| `previous_salaries` (Previous month salary) | `Compliance\Imports\PreviousSalaryImports` | `TaxIncomeEntries::recordPreviousSalary` (new kind `previous_salary`: month, gross, taxable, basic + DA, HRA, TDS, PF, PT) | yes: a month on file is replaced (old entry cancelled) | `payroll.manage` |
| `investments` (Investments) | `Compliance\Imports\InvestmentImports` | `InvestmentDeclarations::save` with the rest of the declaration unchanged (`currentInput()`); one catalogue item per row (`InvestmentCatalogue`, not rent) | yes: amount changed, 0 removes | `payroll.manage` |
| `hra_declarations` (HRA declaration) | `Compliance\Imports\HraDeclarationImports` | `InvestmentDeclarations::save`: the `HRA_RENT.rent` item (rent a month × months, landlord, PAN, other landlords, address, city) and `METRO` | yes: replaces the rent | `payroll.manage` |
| `employee_statutory` (Employee statutory) | `Employment\Imports\EmployeeStatutoryImports` | `ChangeStatutoryApplicabilityAction` (PF / ESI / PT applicable, PT state, tax regime) and `ChangeStatutoryIdentityAction` (PAN, Aadhaar reference, UAN, PF number, ESI number; encrypted, masked; CA-4 linked documents first) | yes: only the cells filled in change | `employee.sensitive.update` |
| `loans` (Loans) | `Loans\Imports\LoanImports` | `Loans::request` (raise on behalf, policy checked); status `approved` → `Loans::approve` by the person running the upload (never their own loan; interest only when the policy lets the approver change it; a loan beyond the policy stays requested); `Paid out on` → `Loans::markDisbursed` | adds only; the same open loan is skipped | `payroll.manage` |
| `variable_pay` (Variable pay) | `Payroll\Imports\VariablePayImports` | `VariablePay::request` (or `VariablePayPlans::enterPayout` when the row names a plan); requests wait for someone else's decision; paid by `VariablePayInput` | adds only | `payroll.manage` |
| `salary_arrears` (Salary arrear) | `Payroll\Imports\SalaryArrearImports` | a `PayrollAdjustment` of type `arrear` in the open pay month (the Adjustments screen's record and model guards; pending when `payroll.adjustments.require_approval`); reference period when PeopleOS has the arrear month; source `employee_import` | adds only | `payroll.manage` |
| `reimbursement_claims` (pay on claim) | `Claims\Imports\ReimbursementClaimImports` | `ReimbursementClaims::claim` on the employee's behalf with the row's bill (`billsHeldByPayroll`: no attachment, the bill listed); every claim-screen rule pre-checked (balance with earlier rows of the file, one open claim, bills, window, limit, new-regime bills) | adds only | `payroll.manage` |
| `reimbursement_bills` (pay by default) | `Claims\Imports\ReimbursementBillImports` | `ReimbursementBills::record` (new table `reimbursement_bills`; screen Payroll → Reimbursement bills) | adds only; the same bill is skipped | `payroll.manage` |
| `reimbursement_opening` (opening balance, pay on claim) | `Claims\Imports\ReimbursementOpeningImports` | `ReimbursementClaims::opening` (ledger kind `opening`, once per employee, component and year) | adds only; never changed | `payroll.manage` |
| `settlements` (Settlement) | `Exit\Imports\SettlementImports` | `FinalSettlements::addLine` / `removeLine` on the settlement of the exit in progress (draft or calculated only; `ensure()` starts one) | yes: a manual line of the same name and type is replaced | `exit.settle` |
| `pay_group_changes` (Change pay group) | `Compensation\Imports\PayGroupChangeImports` | `CompensationCycles::fromUpload` (one draft cycle per company) — CTC, currency, variable target and the amounts of pay items the new structure also has carried from `CompensationOutput::on()` | adds only (the employee must have compensation) | `compensation.cycles` (+ `compensation.propose`, checked by the upload) |
| `salary_components` (Salary structure, employee CTC manual) | `Compensation\Imports\SalaryComponentImports` | `CompensationCycles::fromUpload`: CTC (optional for a components-mode structure) with each fixed / input pay item's monthly amount | yes: a first CTC (no compensation on file) or a revision | `compensation.cycles` (+ `compensation.propose`) |

**Compensation uploads.** `CompensationRowImports` (abstract) mirrors the salary upload: rows are checked as the
uploader's proposals (`CompensationChanges::check`) and against `UploadTimeline` (extracted from `SalaryImports`, which
now delegates to it: the employee in the company on the date, no other open change, compensation already starting
that day, payroll finalized on or after the date). Running prepares one draft "salary upload" cycle per company (the
company is the employee's position company on the effective date; the cycles are kept in `options.compensation_cycle_ids`);
nothing is paid until each cycle is submitted, reviewed, approved and executed by four different people. Only the
uploader approves (unless the type is under maker-checker) and runs such a file. A pay item's code as a column header
("CONV") maps to its amount column.

**Tax.** `TaxIncomeEntries::totals()` now also returns `outside_basic`, `outside_hra`, `previous_months` and `tax_credit`;
previous-salary months count in `outside_*` like income paid outside payroll. `TaxComputer` (each only when its records
or setting exist, so earlier bases and amounts are unchanged; engine version unchanged):
- adds the previous months' basic + DA and HRA to the year's figures of the HRA exemption;
- counts 12BAA TDS / TCS as tax already deducted (basis `tax_credit_counted`); a TDS rule version carrying
  `other_tds_tcs_credit: false` turns it off for its period (basis `tax_credit_not_counted`);
- from `payroll.reimbursement.bills_from` (Setup → Payroll, a date; blank = never) adds what pay-by-default
  reimbursements pay in the year (finalized or paid payroll before this payment + this month × months left) beyond their
  active bills to taxable salary, per reimbursement (`ReimbursementBills::unbilled`; basis `reimbursement_paid`,
  `reimbursement_bills`, `reimbursement_unbilled_taxable`). No amount or limit is typed.

**Declarations.** `InvestmentDeclarations::currentInput()` rebuilds the declaration as `save()` input (items with
amounts, descriptions and details; a section total entered before items existed in the section's catch-all item;
metro; previous employer), so one upload row changes one part. The screen's rules apply (payroll in scope, the
"payroll may edit submitted declarations" switch, landlord PAN and count); items with a verified proof and verified
declarations are never changed; a declaration started by an upload is saved as submitted, an existing one keeps its
status.

**Presets (catalogue, Payroll head).** The statutory override presets keep their default lines (ESI `ESI_EE`, LWF arrear
`LWF_EE`, Override TDS `TDS`, PF (before salary) `PF_EE`, PT `PT`); the `about` texts say which other line codes the
Line column takes. The statutory override and perquisite samples now have two rows. Perquisites (auto) takes the value
for the year without the recovery column (TDS spreads the tax over the remaining months); Perquisites (manual) has the
recovery. PeopleOS never values a perquisite from the pay group (no verified valuation rule).

**Reimbursement bills (pay by default).** `reimbursement_bills` (`Claims\Models\ReimbursementBill`: employee, pay-by-default
reimbursement, financial year (start year, as the claims ledger), bill date, number, vendor, amount, description;
active / cancelled with a reason, never edited or deleted — model guards; payroll category in `DataInventory`; policy
`LoanPolicy` like the claims). `Claims\Services\ReimbursementBills` records and cancels them (payroll.manage, in scope)
and gives TDS the unbilled part. Screen: Payroll → Reimbursement bills (`ReimbursementBillResource`: list with the
employee filters, "Record a bill" in two sections, cancel), linked from Setup → Payroll.

**Claims raised by payroll.** `ReimbursementClaims::claim()` gained `bool $billsHeldByPayroll = false`: only a user with
`payroll.manage`, for someone else, may set it; the attachment rule is then met by listing the bills, and the claim's
SUBMITTED audit records `bills_held_by_payroll`. Every other caller works as before.

**Migrations.** `2027_01_14_200001_add_previous_salary_and_tax_credit_to_tax_income_entries` (nullable `pay_month`,
`basic_da`, `hra`, `credit_type`, `section`, `deductor_name`, `deductor_tan` on `tax_income_entries`, already classified
in `DataInventory`) and `2027_01_14_200002_create_reimbursement_bills`. No new permission key; one new setting
`payroll.reimbursement.bills_from` (default blank).

### Decisions

Owner instruction (2026-11-01): each open point decided the way leading HCM suites do it, implemented and tested.

1. **12BAA TDS / TCS counts in salary TDS by default.** Since the October 2024 amendment of s.192(2B) Indian payroll
   suites (Keka, greytHR, Darwinbox, HROne) credit the TDS / TCS an employee declares against salary TDS, exactly as
   PeopleOS already credits the previous employer's TDS and tax deducted outside payroll; the declared tax is a fact
   about the employee, not a statutory rate. A TDS rule version can still switch it off (`other_tds_tcs_credit: false`),
   so the law stays in rule data. Test: "records 12BAA tax credits …".
2. **Payroll may upload claims without attachments when it holds the bills.** HR-entered and imported claims in HROne,
   Keka and SAP SuccessFactors carry bill details, not images: the paper or scanned bills sit with payroll. The bill
   must still be listed (amount, number), the claim is audited as "bills held by payroll", and approval stays with
   someone else. Employees' own claims keep the attachment rule. Test: "posts reimbursement opening balances …".
3. **Pay-by-default reimbursements are taxable beyond their bills from an organisation date.** This is how Indian
   suites treat monthly reimbursements (HROne "reimbursement bills — pay by default", greytHR / Keka year-end
   reimbursement tax): paid monthly, tax-free against bills, the unbilled part taxed from the cut-off month. The date
   is the organisation's choice (blank keeps today's behaviour); no limit is typed — the reimbursement's own payments
   and the bills decide. Test: "records bills behind monthly reimbursements …".
4. **Loans may be approved in the upload by the person who runs it, never for their own loan.** HROne auto-approves
   imported loans and Keka / greytHR import active loans for go-live; PeopleOS keeps a person's decision (the runner,
   under the upload's own maker-checker when configured), the loan policy's limits (a loan beyond them stays requested)
   and the interest rule, and "Paid out on" records loans paid before go-live. Test: "raises loans …" (own loan refused).
5. **Previous month salary feeds tax, not payslips.** Keka's "previous payroll" and greytHR's YTD import feed the tax
   worksheet and Form 16 Part B, not payslips or returns already filed by the old system; PeopleOS does the same: TDS
   counts the months (and their basic / HRA), payslip pages, registers and returns stay on PeopleOS payroll. Test:
   "records months paid before go-live …".
6. **LWF applicability is not an employee field.** LWF applies by establishment and state in every Indian suite;
   PeopleOS keeps it on the establishment's statutory profile, so the employee statutory upload does not take it.
7. **Compensation upload files are seen only by their uploader (or checker).** Their rows hold pay, as salary uploads:
   `RowImportTypes::UPLOADER_ONLY` narrows the upload policy, Recent uploads and the upload screens. Test: "turns
   component amounts …" (another administrator cannot view the file).

### Partial

- `pf_arrears` (PF arrear, before arrear) — left unregistered, so the process stays hidden. PeopleOS pays arrears inside
  the month's run (payroll adjustments of type arrear, attendance arrears): PF on a PF-wage arrear is part of the
  month's `PF_EE` / `PF_ER` line, computed by the verified EPF rule on the month's PF wages; an ad hoc arrear is not PF
  wages. HROne's process takes the *wages* of the arrear months (EPF / EPS / EDLI, U / D mode) for a separate arrear
  computation that EPFO receives as an **arrear ECR**. Doing it properly needs that arrear return, whose layout is
  statutory format data not verified in PeopleOS (export layouts are verified data); folding PF on arrears into the
  month's PF lines instead would make the month's ECR report wages and shares that belong to other wage months — a
  wrong statutory return. A statutory override only sets a whole line for the month, so it cannot carry "PF on the
  arrear" either. It waits for BP-10 / PO-20 (revision arrears, `payroll.pf.on_arrears`, arrear ECR layout verified).

**Known limits.** An upload carries no files: claims are uploaded with their bills listed (decision 2). The Payroll
uploads screen lists compensation uploads to their uploader only (decision 7).

**Tests.** `tests/Feature/HroneParity/DataImport/PayrollImportsTest.php` (registry, 12BAA, previous salary, investments,
HRA, previous employment, employee statutory, salary components, pay group change) and `PayrollMoneyImportsTest.php`
(loans, variable pay, salary arrears, reimbursement opening, claims and bills, settlements); helpers `di4*` in
`Di4Helpers.php`.

## Workforce → Vendor and Contract (after WO-15)

- `vendors` (`Partners\Imports\VendorImports`, `partner.manage`): vendors (business partners that are organisations) by
  vendor code, written only through `Partners\Services\Vendors::create` / `update` (its validation, the PAN encrypted and
  shown by its last four characters, the audit). Validation checks the code pattern, the kind (code or name from the
  `vendor_kinds` list), the PAN pattern, the GST number (`RegistrationNumberFormat`, check character and state code) and
  that the PAN is the one inside the GST number, without echoing the PAN. A new vendor needs a name and a kind; an update
  changes only the cells given.
- `employee_contracts` (`Employment\Imports\EmployeeContractImports`, `employee.update`): contracts of contract staff —
  the agency (an active vendor), the contract number and its dates — in the new `employee_contracts` table (migration
  `2027_01_21_900001`, named to run after `vendors`; FK `vendor_id` restrict), model `Employment\Models\EmployeeContract`
  (`ScopedByEmployee`), written only through `Employment\Services\EmployeeContracts::save` (ProfileChangeGuard, validation,
  audit, a timeline entry `contract` for a new contract). Matched by employee and contract number; never deleted (an
  ended contract keeps its end date). Screen: People › Contracts (`EmployeeContractResource`, two-step form).

## DI-3 Time Office and DI-5 Performance, Engage, Asset (package DI-35)

Every row is written through the service the matching screen uses. Registered in `RowImportTypes` under the "Data import
DI-3" (area `time_office`) and "Data import DI-5" (areas `performance`, `engage`, `asset`) anchors; an uploader needs
`employee.import` plus the module permission named below. Tests: `tests/Feature/HroneParity/DataImport/{TimeOfficeImportsTest,
PerformanceImportsTest,EngageAssetImportsTest}.php` (helpers in `Di35Helpers.php`, all prefixed `di35`).

### Time Office

| Type (process) | Class | Writes through | Permission | New / existing |
|---|---|---|---|---|
| `comp_off_credits` (Compensatory off) | `Leave\Imports\CompOffImports` | `TimeOfficeRequests::recordApproved($employee, 'comp_off', …)` — new branch `recordCompOff`: an approved comp-off claim (one per day, never on a day with approved overtime; expiry given or the time-office policy's `comp_off_validity_days`, 90 by default); `attendance.timeoffice_approved` → `CompOffClaimBridge` → `CompOffCredits::credit` (ledger entry keyed to the claim, FIFO expiry, reversal on cancel) | `leave.adjust` | adds only (`intents()` false); a day already claimed is skipped |
| `attendance_arrears` (Override arrear days) | `Attendance\Imports\AttendanceArrearImports` | new `AttendanceArrears::setForMonth` / `planForMonth` / `setRefusal` (decision D35-1): HR's figure is the month's arrear total; the calculation's pending / held arrears of the month are voided, the calculation records none for the month afterwards, and only the figure less what runs already settled is taken (one HR arrear covering `covers_from` … `date`); `attendance.arrears`, access scope, never one's own, the month over, the employee in the company's month population, within ± the month's days; event `attendance.arrear_recorded` | `attendance.arrears` | create / update; the same figure again is skipped |
| `paid_day_adjustments` (Override paid days) | `Attendance\Imports\PaidDayAdjustmentImports` | `PaidDayAdjustments::preview` (check) and `::save` (run): mode set (paid days, or counts of present / paid leave / holidays / weekly offs) or reduce (`full`, `none` or a percentage per category); every TX-4 rule applies. While `attendance.paid_day_adjustments.enabled` is off (default) every row is an error naming the switch | `attendance.override` | create / update (replaces the month's counting adjustment) |
| `overtime_arrears` (Override previous month overtime) | `Attendance\Imports\OvertimeArrearImports` (extends `OvertimeImports`, no longer `final`) | the overtime upload's path, `TimeOfficeRequests::recordApproved($employee, 'overtime', …)`, for days of an earlier attendance month only; a locked or closed day is refused (naming `attendance.allow_corrections_after_lock`) unless that switch is on, and then the minutes become an overtime arrear the next month pays (decision D35-2) | `attendance.manage` | as the overtime upload |

Presets checked: `time_office.attendance_status` and `time_office.working_hours` (`day_overrides` with column subsets) and
`time_office.opening_balance` / `rh_balance` / `leave_balance` (`leave_balances` with defaults) now have sample files that
validate — `DayOverrideImports::examples()` row 2 carries worked minutes, `LeaveBalanceImports::examples()` row 2 is positive
(an opening balance cannot be negative), `OvertimeImports::examples()` has two rows. A working-hours row without a status
validates (it always did; now tested). `LeaveBalanceImports` gained an optional "Leave category" field; the Restricted
holiday balance process fills it with `restricted` and the leave code with `RH` for blank cells and shows only employee code,
leave code, days, year and note (decision D35-7).

**Previous month overtime (D35-2).** On an open day of an earlier month the claim recalculates the day, so the minutes count
for that month as with the overtime upload. On a day already locked downstream or in a closed month (accepted only with
`attendance.allow_corrections_after_lock`), the locked record is never rewritten: `AttendanceProcessor::recordArrear` now
compares the approved overtime as well as the paid quantity and keeps the difference as `attendance_arrears.overtime_minutes`.
`AttendanceOutput::overtimeArrears($employee, $before)` returns the waiting minutes (and their arrears); `PayrollCalculator`
adds them to the month's `overtime_minutes` (so the month's own overtime component pays them), records
`inputs.attendance.overtime_arrear_minutes`, and the overtime line carries the arrears' ids under the `attendance_arrear` source
key, so finalization settles them through `AttendanceArrearInput::settle`. `AttendanceArrearInput` now takes only arrears with
a paid-day difference (overtime-only arrears are the overtime line's). Without such arrears every result is identical.

Migration `2027_01_13_200001_add_source_to_attendance_arrears`: `attendance_arrears.source` (`calculation` default | `upload`),
`covers_from` (first day of the month HR's figure covers) and `overtime_minutes` (default 0). No new table.

### Performance

| Type (process) | Class | Writes through | Permission | New / existing |
|---|---|---|---|---|
| `goal_bank` (Goal bank) | `Performance\Imports\GoalBankImports` (`MasterImports`) | the KRA library (`Kra`, as Performance → KRA library: plain save / update, through the Change Centre when governed), code-keyed; suggested KPIs as `name \| measure \| target \| unit code; …` | `performance.manage` | create / update |
| `goals` (Goals) | `GoalImports` | `Goals::create` / `Goals::update` (source `import`): goal sheet policy, field rights, weight limit (pre-checked with the rows above), goal period, units, duplicate rule; matched on employee + cycle + title among goals the uploader may see (`GoalVisibilityScope`); locked goals keep their definition; withdrawn appraisals and closed cycles refused | `performance.manage` | create / update |
| `initiatives` (Initiatives) | `InitiativeImports` | `Initiatives::create` / `::update` (`mayAssignTo` / `mayEdit`, employed, type, own goal, dates, progress) | `performance.initiatives_manage` | create / update (open initiative of the same employee and title) |
| `improvement_plans` (PIP) | `ImprovementPlanImports` | `ImprovementPlans::open(…, draft: true)` + `addCheckpoint`, then — with "Start now = yes" — `ImprovementPlans::activate` (decision D35-8: the PIP policy's approval, start checklist and letter as on the plan's page) | `performance.pip` | adds only; an employee with an undecided plan is skipped |
| `review_ratings` (Review ratings) | `ReviewRatingImports` | `PeriodicReviews::submit` (HP-8i periodic reviews: `mayRate`, open, window, appraisal not final, scale and the policy's maximum); the appraisal is re-scored | `performance.manage` | adds only; the same rating on a submitted review is skipped, another refused |
| `rating_normalisation` (Normalize performance ratings) | `RatingNormalisationImports` | `RatingNormalisation::normalise` → `Appraisals::calibrate` → `Calibrations::adjust` (the GO-3 grid's path; calibration adjustment with the row's reason); the grid's own conditions too: active cycle, a scale level while final ratings take no decimals; optional "Current rating" = the expected rating | `performance.calibrate` | updates only |
| `review_normalisation` (Normalize review ratings) | `ReviewNormalisationImports` | `PeriodicReviews::normalise` (GO-3: submitted reviews, `performance.calibrate`, scope, not own, policy maximum, reason; appraisal re-scored) + active cycle | `performance.calibrate` | updates only |
| `okr_final` (OKR final achievement) | `OkrFinalImports` | `Goals::checkIn($keyResult, value, …, source 'import', idempotency key per row)`: the key result's value and progress, the objective's roll-up; only goals the uploader may check in on (`GoalPolicy::checkIn`), open, not locked in a closed cycle; employee code blank = organisation objective | `performance.manage` | updates only |
| `okr_total` (OKR total achievement) | `OkrTotalImports` | `Goals::overrideAchievement` (GO-4 actual achievement with a reason); behind `performance.goals.achievement_override` (off by default: every row an error naming the switch); `GoalVisibility::mayOverride` | `performance.manage` | updates only |

### Engage

| Type (process) | Class | Writes through | Permission |
|---|---|---|---|
| `badge_awards` (Badge allocation) | `Recognition\Imports\BadgeAwardImports` | new `Recognitions::allocate` (+ `refusalForAllocation`, `receivedInPeriod`; `give()` and `allocate()` share a private `store()`), owner decision D-GO4 on its recommended behaviour: `recognition.manage`, badge active, custom badges allowed, receiver rules, not to oneself, reason when required, receiving limit (counting the rows above) — no giver quota and no giver roles / conditions; a badge that needs an attachment is refused (given on the Recognition page). Notice, certificate, `recognition.given` (reward points, wall post) as for any badge | `recognition.manage` |
| `reward_points` (Rewards) | `RewardPointImports` | `RewardPoints::adjust` (as "Adjust points": `manages()` incl. scope, never one's own, reason, credits from the budget, debits from earned points) — validation counts the budget and balances after the rows above | `recognition.points` |

Both only add (`intents()` false). Migration `2027_01_15_200001_add_source_to_recognitions`: `recognitions.source` (`person`
default | `upload`); `Recognitions::givenThisMonth` counts only `person` badges, so the monthly quota is unchanged for people
(decision D35-5).
`event_attendance` is not built here (company events, GO-15, another package).

### Asset

| Type (process) | Class | Writes through | Permission |
|---|---|---|---|
| `asset_types` (Asset types) | `Assets\Imports\AssetTypeImports` (`MasterImports`, `AssetCategory` by code; owner role by name or key) | as the category screen (plain save / update; Change Centre when governed) | `asset.manage` |
| `asset_subtypes` (Asset sub types) | `AssetSubtypeImports` (`MasterImports`, `AssetSubtype` by code, under a type; a used sub type keeps its type) | as the sub type screen | `asset.manage` |
| `asset_products` (Products) | `AssetProductImports` (no code: matched on type + manufacturer + name; the model's own sub type / deployment / licence rules) | as the product screen (plain save / update; Change Centre when governed) | `asset.manage` |
| `assets` (Assets) | `AssetImports` (asset tag) | new tag: `Assets::receive` (procured movement), then by status `assign` (custodian, assigned on; the asset policy's mapping applies), `sendForRepair`, `markLost` or `retire`; tag on file: register fields as `EditAsset` and a new location via `relocate()` (movement). Status and custodian never change from a file; serial numbers unique per product (decision D35-6) in the file and against the register, plus the sub type's rule; a type that requires a serial gets one | `asset.manage` |

### Permissions and tables

No new permission keys; no new tables. Columns: `attendance_arrears.source`, `covers_from`, `overtime_minutes`
(`2027_01_13_200001`), `recognitions.source` (`2027_01_15_200001`). Cross-module code touched: `PayrollCalculator` and
`AttendanceArrearInput` (previous month overtime, read through `AttendanceOutput`); Attendance and Leave code never names
payroll amounts.

### Decisions

- **D35-1 Override arrear days takes the month over.** HR's figure is the month's arrear total: the calculation's pending or
  held arrears of the month are replaced (voided), the calculation records none for that month afterwards, and only the figure
  less what runs already settled is taken. That is how manual overrides work in HROne, Darwinbox and SuccessFactors time
  (the manual value wins over the computed one for the period) and it makes re-uploading the same figure harmless.
  Tests: "sets HR arrear days of an earlier month, taking the month over from the calculation".
- **D35-2 Previous month overtime is paid in the next open month.** A closed day is never rewritten; the extra approved minutes
  wait as an overtime arrear and the next run pays them with its own overtime formula, settling them on finalize — HROne's
  "process previous month overtime" step and the usual retro-overtime handling in Workday / SAP. The upload still needs
  `attendance.allow_corrections_after_lock` (off by default). Tests: "records previous month overtime …", "pays previous month
  overtime of a closed day …".
- **D35-3 Review ratings are the periodic review ratings.** The upload submits HP-8i periodic reviews (one rating per review,
  as HROne's "Review ratings" import); appraisal self / manager reviews stay with their reviewers on the form, as in
  SuccessFactors and Workday, and final ratings are changed through the normalisation upload. Test: "submits periodic review
  ratings and normalises them …".
- **D35-4 OKR final = a key result's achieved value; OKR total = the objective's achievement override.** A key result's final
  value is a check-in (it rolls up like any progress update); an objective's total is HR's override, which stays behind
  `performance.goals.achievement_override` (off by default), as Darwinbox and HROne keep manual achievement an explicit,
  audited override. Tests: "records the final value of key results …", "sets the total achievement of objectives only while …".
- **D35-5 Badge allocation is the organisation's.** HR allocates with `recognition.manage`, without using their own monthly
  giver quota and without the badges' giver rules, while each badge's receiving limit, receiver rules and reason still apply
  (D-GO4's recommendation; Workday / Darwinbox treat admin awards the same way). Test: "allocates badges for the organisation
  without using HR's own quota …".
- **D35-6 Serial numbers are unique per product.** In the file and against the register a serial may appear once per product
  (make and model) — per asset type for assets without a product — plus the sub type's own "no duplicates" rule, which is how
  ITAM registers (ServiceNow, SAP) identify hardware: two makes may share a serial, one make may not. Test: "receives assets
  into stock …".
- **D35-7 Restricted holiday balance loads the restricted-holiday leave type.** The process accepts only leave types of category
  Restricted / optional holiday (RH by default), as HROne and Darwinbox keep RH as a leave balance; the general leave balance
  process still takes any leave type. Test: "loads restricted holiday balances only into the restricted-holiday leave type".
- **D35-8 Improvement plans open as drafts unless "Start now".** A row opens a draft with its checkpoints; "Start now = yes"
  starts it through `ImprovementPlans::activate`, so the PIP policy's approval, start checklist and letter apply exactly as on
  the plan's page (bulk initiation in Darwinbox / SuccessFactors also goes through the plan's workflow). Test: "starts
  uploaded plans at once when asked, and the PIP policy's approval still applies".
