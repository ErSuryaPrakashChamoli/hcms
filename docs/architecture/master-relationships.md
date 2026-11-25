# Master relationships: independent masters, optional links and placement safety

Programme approved by the owner on 2026-11-23, after the audit "PeopleOS: Independent Masters, Optional Relationships & Entity Applicability" (exported outside the repo to `~/Documents/hrms/peopleos-master-relationships-audit/`). It extends the existing frameworks and does not replace them:

- configuration applicability, company overrides and cloning (`configuration-applicability.md`, `configuration-cloning.md`);
- master uploads and workbooks (`master-data-import.md`);
- name resolution (`name-resolution.md`).

**The audit's main finding.** Masters are already created on their own. 20 of 23 save with a name, a code and a status. Only three links are required, each for a legal reason: Legal entity → Company, Establishment → Legal entity, Location → Company. The structure (which unit sits under which) lives only in `organisation_nodes`.

The real gap ran the other way. Placing a person checked only the company. That allowed:

- absences recorded only because no shift applied;
- a seat in another company;
- no way to remove a link;
- delete error pages.

The programme closes those gaps in five phases.

## 1. Decisions (recorded 2026-11-23)

| # | Decision | Owner's choice |
| --- | --- | --- |
| OD-1 | Designation's department | **Option A.** Kept as optional information: "Usual department (information only)". It never places anyone; a person's department comes from their own position. |
| OD-2 | Different structures per company | **Option A.** Use company-specific cloned units where structures differ. No per-company tree placement now (kept as a future consideration). |
| OD-3 | Default mode of consistency checks | **Warn.** A company may move to Block after a pre-flight check. |
| OD-4 | Required employment data | **Option A with configurability.** Sensible defaults for new tenants (Company, Location, Department, Designation, Employment type), with conditions per company's employment and statutory needs. Existing tenants keep Company-only until they configure more. |
| OD-5 | Value that removes a link in an upload | **`(none)`.** Case-insensitive, surrounding spaces ignored. Blank still keeps what is on file. |
| OD-6 | Order against the other sessions' work | **Option A.** R1 runs independently. The paused applicability work is finished, tested and committed before R2–R4. R5 stays with the import session. |

Conditions the owner attached, held for every phase:

- strict tenant isolation, company scopes respected;
- errors and suggestions never show another tenant's records;
- employee-position history, audit events and payroll results are never rewritten;
- accepted and refused cases tested, including users with missing permissions and users limited to one company;
- code-based import files keep working;
- no generic payroll block without actionable details.

## 2. Phases

| Phase | Scope | State |
| --- | --- | --- |
| R1 | Safety fixes | Built (§3), applied to the tree 2026-11-23; see §4 for tests |
| R2 | Business enforcement: Employment data requirements policy, `PositionRequirements` in hire / transfer / upload / API, Off / Warn / Block, readiness checks, salary-structure re-check, increment exclusions | Built, commit `4c712e5a` (§5) |
| R3 | Per-company designation defaults (level, grade, job family) on `configuration_overrides` | Built, commit `02f6f8be` (§6) |
| R4 | Relationship screens (Companies · Links · Company values · Usage · History), optional Links step, placement, Unmapped report | Built, commit `cdeba629` (§7) |
| R5 | The 7 missing master uploads plus the Organisation structure upload, with `(none)`, names and applicability | Built (`master-data-import.md` §12) |

**Ownership.** Since 2026-11-23 evening one session owns the whole programme (applicability, workbooks, name
resolution and R1–R5); the earlier sessions named below were dissolved and their work kept as it was in the tree.
R1 was built in a sandbox copy (`~/peopleos-r1-sandbox-new`) and applied to the tree as one patch whose 50 files match
the R1 checksum register. The resolver treats `(none)` as blank (`ResolutionSession::CLEAR`).

## 3. R1: safety fixes

### 3.1 A seat belongs to one company

`PositionSeats` (the `PositionAssignmentGuard` behind `AssignPositionAction`) now refuses two cases:

- **A seat of another company.** A person is placed only in a seat of the company they are placed in: the company chosen on the change, or else the one they already work for. Choosing a seat never implies a move to another company. A move is accepted when the company is chosen too. A new hire has no company yet, so the seat's company is theirs.
- **A held seat carried into another company.** Moving to another company while keeping a seat of the old one is refused. Vacate it, or choose a seat of the new company.

Company names appear in the refusal only when the reader may see them; otherwise it says "another company".

### 3.2 Delete guard on every master

- **`App\Support\Masters\MasterUsage`** reports what uses a record:
  - every single-column foreign key pointing at it, whatever the key does on delete (RESTRICT would give an error page, SET NULL would silently unlink, CASCADE would silently delete history such as a company's payroll runs or people's skills);
  - its place in the organisation structure (`organisation_nodes` is polymorphic, so it has no key).
- **Own parts.** The only exceptions are the record's own parts:
  - `designation_employment_type.designation_id` (a designation's employment-type links);
  - a company's legal entities and establishments (`COMPOSITE_PARTS`). Every company gets a primary legal entity and
    establishment when it is created (`LegalEntities::ensureFor`) and the database deletes them with it, so they are
    not uses. Whatever uses *them* is (a challan, an establishment assignment, a registration …) and is reported as
    "… of its establishments". Without this rule no company could ever be deleted, not even one created by mistake;
    the full regression found it (`AuditTrailTest` "records DELETE events"), fixed 2026-11-23 during integration.
- **Discovery.** Foreign keys are read from the database once per process: one `information_schema` query on MySQL, the schema builder elsewhere. A new table pointing at a master is covered without code.
- **Counts only.** Only counts leave the class, so a refusal never names another record.
- **`GuardsMasterDeletion`** (a model trait) refuses an Eloquent delete of a record in use with `MasterInUseException`, which carries the counts and what to do. It is on all 20 organisation masters and on Skill. Screens, uploads, the API and code all get the same answer. Tenant offboarding deletes in SQL and is not affected.
- **`MasterDeleteAction`** (Filament) replaces `DeleteAction` on the 18 master edit pages and the Skills list:
  - An unused record deletes as before.
  - For a record in use, the modal says what uses it and offers **Deactivate instead**.
  - Deactivation is an edit: it needs edit permission and is audited with the reason "Deactivated instead of deleted: it is in use."
  - When the organisation governs the record, the deactivation is proposed in the Configuration Change Centre instead.
  - Someone who may delete but not edit sees the refusal only.
- **`ActiveMasters::only()`** takes deactivated masters out of the employee pickers: hire, transfer / promote and rehire, through `CreateEmployee::positionFields`. Records and history that use them keep showing them.

### 3.3 Remove a link on transfer and promotion

- **`AssignPositionAction`** accepts `clear`: the position fields this change removes from its effective date.
  - Blank still means "unchanged".
  - The company can never be removed.
  - A field cannot be both changed and removed.
  - Only position fields are accepted.
- **History.** The earlier position keeps its values. The audit diff and the timeline show the removal ("Grade: T4 → —").
- **Screen.** The Transfer / promote form has **Remove from the effective date** (only fields the person has today), and the review step shows "Removed".
- **Promotions** go through the same action, so they get the same behaviour.

### 3.4 `(none)` in uploads

`App\Support\Imports\ClearValue` (`NONE = '(none)'`, `isClear()`, `hint()`) is the one definition the import session uses. The spec handed to hcms-31:

1. **Blank and `(none)`.** Blank keeps what is on file. `(none)` removes an optional link. On a new record `(none)` is the same as blank.
2. **Order at each link cell.** First `isClear()`, then the planned-code check, then the lookup. A clear marker survives `attributes()`'s null filter and becomes null before saving, so `same()` sees the change.
3. **Optional links only.** `(none)` is refused on required links: a location's company, a legal entity's company, an establishment's legal entity, and the parent of a sub-department or sub-branch row.
4. **Structure stays in sync.** Clearing a parent department or parent branch goes through the same path as any parent change.
5. **Review and format guides.** The review shows "Level: L5 → (removed)". Format guides carry `ClearValue::hint()`.
6. **Business-unit upload.** `company_code` becomes optional.
7. **Designation upload.** The department column reads "Usual department (information only)"; its key is unchanged.

**Built by hcms-31 (2026-11-23).**

- Where:
  - `MasterImports`: a clear marker that is written as null just before save; `same()` counts it as a change; the preview shows "Level: L5 → (removed)".
  - `ReadsMasterRows::parentId`.
  - `BusinessUnitImports`: company optional.
  - `DesignationImports`: the "Usual department" label.
  - `ClearValue::hint()` on every optional link column.
- Required columns refuse `(none)` with "<Column> is required here; (none) cannot be used." `(none)` never reaches the resolver.
- Tests in `tests/Feature/Imports/Workbooks/IntegrationTest.php`:
  - "removes an optional link with (none), keeps it when blank, and refuses (none) on required links";
  - "resolves references by code exactly as before …", which checks that legacy code-only files are unchanged.
- `CoreImportsTest` now expects a business unit without a company to be created.
- Not applicable through uploads:
  - designations' default reporting level, since the upload has no such column;
  - department and location parents, since the Departments upload never sets a parent and sub-department / sub-branch parents are required.
- Commit note: the import files use `ClearValue`, so `app/Support/Imports/ClearValue.php` must be committed with or before them.

### 3.5 Agreed spots in hcms-81's files (after the applicability commit)

- `AssignPositionAction`: the `clear` handling. It runs after the merge and before `assertUsableByCompany`, which stays as it is.
- `ViewEmployee`: the Remove field on Transfer / promote.
- `CreateEmployee::positionFields`: `ActiveMasters::only()` wraps the existing options; the `EntityApplicability::options` call inside is unchanged.

### 3.6 Days with no shift block the payroll run

With no work schedule, roster or shift, the attendance processor records every un-punched day absent, weekends too, since nothing says which days are off. Payroll takes loss of pay for absent days.

- **`AttendanceOutput::noShiftAbsences()`** lists those days per person: the processor's `no_shift` exception on an absent day.
  - Holidays and weekly offs that a schedule, roster or override names are never in it, because the processor records them first.
  - Days covered by leave are not absent, so they are not in it either.
- **`PayrollReadiness`** has the check `no_shift`. Each row has the person, the number of days, the dates as ranges ("1–16 Sep, 18–30 Sep") and what to do:
  - "Assign a work schedule, roster or shift, then reprocess attendance", or
  - "A schedule applies now: reprocess attendance for these dates", worked out from `ShiftResolver::resolveBetween`.
- **`PayrollRunChecklist`** has the item **Days with no shift** (Time office, kind `block`). It blocks Calculate (screen, background and scheduled) until cleared and cannot be ticked.
- **Where it does not apply.** Where payroll takes no loss of pay from attendance (`payroll.lop_from_attendance` off), the check does not run and the item reads Not applicable.
- Nothing is written to attendance or payroll. Fixing the schedule and reprocessing clears the item.

### 3.7 Designation's department relabelled (OD-1)

The form reads "Usual department (information only)" with a one-line explanation, and the list column reads "Usual department". No behaviour changes: `Positions::definition` never read it.

### 3.8 Not changed

- No table and no migration. No backfill. No existing employee position, audit event or payroll result is touched.
- R2's enforcement (required fields, Warn / Block) is not in R1; it is in §5.

## 4. Tests

`tests/Feature/MasterRelationships/`:

| File | Covers (accepted and refused) |
| --- | --- |
| `MasterDeleteGuardTest` | Masters created on their own (acceptance criteria 1–5). A company with only its own legal entity and establishment deletes; once something uses those parts it is refused ("… of its establishments"). An unused master deletes. Refusal with counts for current or past positions and other masters (incl. "designation (default reporting level)"). Tree placement counts until detached. CASCADE history counts (person skills); own parts do not. The screen modal offers Deactivate (audited). A delete-only user gets no deactivation. A governed record is proposed for approval. Tenant isolation (another tenant's uses are never counted). |
| `SeatCompanyTest` | Own-company seat accepted. Another company's seat refused, with or without another company chosen. Accepted when the move is stated. A held seat cannot be carried into another company; vacating allows it. A carried seat inside the company still works. |
| `ClearLinksTest` | Clear removes from the effective date and keeps the earlier row. Blank keeps. Company never removed. Changed-and-removed refused. Promotion. The screen's Remove field. Deactivated masters leave the pickers. |
| `NoShiftReadinessTest` | Blocks with person and date ranges. The holiday and the scheduled colleague's days are excluded. Cannot be ticked. "Reprocess" once a schedule applies, and clears after reprocessing. Not applicable without loss of pay from attendance. Passes when everyone has a shift. |
| `EmploymentRequirementsTest` (R2) | Company only without a policy. Warn per company (and recorded on the audit event). Block refused without a pre-flight (wizard and publish). Switch to Block after a pre-flight; hire refused without the field, accepted with it. Rehire (hire rules) and promotion (change rules): Warn notes, Block refuses and rolls back (the rehire's lifecycle step too). Block refused when a company has nothing to meet it. Consent when current people would fail; their next change must complete the data; the earlier position is untouched. Any rule change needs a new pre-flight. Policy versions by effective date. Grade under level and usual department warnings. Publish permission. OD-4 seeding for a new tenant only. |
| `EmploymentRequirementsPathsTest` (R2) | API 422 (Block, and a master that does not apply) and `meta.requirement_warnings`. Job-change and employee uploads: Block a row error before approval, Warn a note and in the run result. Transfer screen notifications and the applicability refusal without an error page. Payroll readiness (employment data, head-office fallback, no leave policy) and the run checklist (blocks only on Block). Increment exclusions. Salary-structure warning on promotion; the pre-flight counts salary-structure mismatches with one salary query per batch. The pre-flight page and Switch to Block; access refused without `policy.view`. |
| `DesignationCompanyDefaultsTest` (R3) | Shared L5/T4, FynnEdge L6/T5. Dated start, ending restores the shared value, positions never rewritten. A company value the company may not use is refused. Transfer prefill and seats. Clone folding and independence. Designation upload with a Company column (set, skip, (none) ends, unknown designation or company). A user limited to another company is refused. Override-aware dependency checks and the location establishment guard. Another tenant's company refused. |
| `RequirementsHardeningTest` (R2–R4, independent review) | Pre-flight counts everyone (limited viewer sees only their own examples); company values of the policy in the pre-flight; rules already on Block kept; a company added after the switch warns until a new pre-flight; copies start on Warn; a company-limited person cannot draft or block a shared policy; transfer-request approval refused as a message; a location's establishment kept to its company and off shared locations (model and form); company values limited to the viewer's companies (Links tab, Unmapped); chained upload rows; increment exclusions with the cycle's other filters; a blank company value of a NOT NULL column keeps the shared value in a copy. |
| `RequirementsVolumeTest` (R2, opt-in) | The pre-flight, the readiness batch check (up to 10,000) and the queued pre-flight end to end: `REQUIREMENTS_VOLUME=1000,10000,30000,50000,100000 vendor/bin/pest tests/Feature/MasterRelationships/RequirementsVolumeTest.php` (JSON lines to `REQUIREMENTS_VOLUME_OUT`). Skipped otherwise. |
| `LevelGradePairsTest` (I.6) | Shared pair refused (and blank or level-less grades allowed); the form's field error; a company value refused writing nothing, accepted together; ending one of the pair refused, both together fine; later dates checked; a shared change that would break a company's pair; a grade moved to another level (shared and company pairs, and the form); data from before the rule not blocking an unrelated change; the upload (shared and company rows) before approval; a clone of disagreeing values refused; "Level and grade together" in Company values. |
| `PreflightRunsTest` (I.7, I.13) | Company fingerprints recorded with Block; an uncontrolled end leaves the company on Warn (fails on the old guard); the controlled end refused while people would fail, covered once accepted; no pre-flight when nothing will block; sync runs recorded; queued runs (pending, idempotent, job as the requester, completed, retry of a finished run, evidence window); superseded when own values change while it waits or a newer request comes; cancelled mid-run; retry and final failure; tenant isolation; the page following a queued run. |
| `SharedPolicyGuidanceTest` (I.9) | The restriction kept (nothing written) and explained: why, how many others (unnamed), who can, the routes; any shared record; the page's notice and Switch to Block unavailable. |
| `MasterScreensTest` (R4) | Tabs per master type (every master with an edit page renders each of its tabs). Empty states of the requirements page and the Unmapped report. A designation created with name and code alone; optional links. Links tab: requirement hints and company values; Company values and Companies tabs. Usage tab. Placement on create, or later. Unmapped report (within the tenant, blocker on Block, access refused). Designer deep link. A location's establishment. |

## 5. R2: employment data requirements

- **Policy type** `employment_data` ("Employment data requirements", Workforce setup):
  - required position fields on hire and rehire, and on other changes (Company is always required);
  - the mode for a missing field;
  - five consistency checks: grade under level, the designation's defaults for the company, usual
    department (information only, OD-1), seat match, salary structure.
  - Each rule is Off, Warn or Block; blank is Warn (OD-3).
- **One check point.** `PositionRequirements` runs inside `AssignPositionAction`, right after the applicability check.
  - Hire, rehire, transfer, promotion, the employee and job-change uploads, workbooks and the API all go through it.
  - The policy is resolved from the proposed position (`EmployeeRuleContext::forPosition`), so company rules and
    company overrides apply even to a hire.
  - Block throws `EmploymentRequirementsException`, an `InvalidArgumentException` (API 422, a row error, a screen
    notification), before anything is written.
  - Every finding names the missing information, the company, the policy and version, and why it matters (what
    reads the field, `EmploymentRequirements::WHY`), for example: "Grade is required for Markedge Technologies
    employees: pay bands, increments and salary formulas by grade read it (Employment data requirements: Hire
    rules, v1)."
  - Warn findings go on the audit event (`requirement_warnings`) and are returned: `requirementWarnings` on the
    position and the hired employee.
  - With no policy, only the company is required, as before.
- **Pre-flight and Block (OD-3).**
  - Configuration → Employment data requirements shows, per policy:
    - who would fail each rule on their next change today;
    - whether each company the policy reaches has masters that could meet a required field.
  - "Switch to Block" publishes the chosen rules on Block with a `preflight` record in the version's settings.
  - The policy validator refuses Block without a pre-flight of exactly these settings, run within 30 days, that
    found the rule safe.
  - People failing today need explicit consent (their next change then needs the data).
  - The wizard keeps only the declared settings, so any later edit needs a new pre-flight.
  - The counts cover everyone the policy reaches, whatever the viewer may see; examples name only people the viewer
    may see. Each company is checked with its own values of the policy (configuration overrides).
  - The record lists every rule on Block (those already on Block are checked again) and the companies checked.
  - **When the rules run**, a Block finding blocks only where the pre-flight vouched for it: the rule is in its safe
    rules, the company among those it checked, and (remediation I.7) the company's settings, its own values applied,
    still have the fingerprint the pre-flight recorded for it (`preflight.company_fingerprints`). Elsewhere (a company
    created or reached later, a company whose own values changed or ended) it is a warning ending "It blocks once a
    pre-flight covers {company}." So nothing blocks unchecked. A record from before company fingerprints vouches only
    for companies on the shared settings.
  - **Ending a company's own values** of a requirements policy goes through `EmploymentRequirementsPreflight::
    endCompanyValues` (the page's "End a company's own values" and "Company values…" on a requirements policy): when
    the settings the company will have block anything, the company is checked with them first; its values end and
    the check is recorded as its coverage (a completed company run) only when safe or with the gaps accepted;
    otherwise nothing ends (outcome refused). Above the queue threshold the check runs on the queue (outcome pending:
    nothing changes until it finds it safe).
  - A copy of the policy (configuration cloning) starts with its Block rules on Warn and without the pre-flight.
  - A person limited to some companies cannot draft, publish or block a policy shared beyond their companies
    (`Policies::draft` / `publish` apply the shared-record guard; refused as a `ConfigurationException`).
  - The page keeps the last pre-flight between requests; "Run the pre-flight again" refreshes it.
  - It reads people in batches of 500: positions, rule contexts and salary assignments take one query each per
    batch, not one per person (`PositionRequirements::primeSalaryAssignments`).
  - **Queued above a threshold (remediation I.13).** Above `PEOPLEOS_PREFLIGHT_QUEUE_THRESHOLD` employed people
    (default 30,000) the pre-flight is a run (`employment_requirement_preflights`) handled by
    `RunEmploymentRequirementsPreflight` as the person who asked: pending → running (progress per batch) →
    completed, failed, superseded or cancelled. Asking again while one is open returns it; a newer request of the
    same policy and purpose supersedes it; a running one stops between batches once superseded or cancelled; a
    failed attempt is retried and the last marks it failed. It completes only if the policy, who it reaches
    (`applicability_fingerprint`) and the companies' own values (`overrides_fingerprint`) did not change while it ran.
    "Switch to Block" then needs a completed run of those rules (Warn or Block read alike: `evidence_fingerprint`),
    current and at most `PEOPLEOS_PREFLIGHT_EVIDENCE_HOURS` old (default 24, never past the 30 days); a pending,
    failed, superseded or cancelled run never counts. The version's record names the run. Below the threshold it runs
    in the request as before, and a switch records its run too.
- **API (remediation I.1, I.4).** `POST /employees` and `/pre-employees`: a refusal by a rule on Block is 422 code
  `employment_requirements` with `requirements` ({rule, field, mode, message} per failed rule); a master that does not
  apply is 422 `master_not_applicable`. Warnings: `meta.requirement_warnings` (messages) and
  `meta.requirement_warning_details` ({rule, field, mode, message}); no `meta` without warnings. Described in
  `docs/api/openapi.json` (`RecordWithRequirementWarnings`, `RequirementsRefusal`, `RequirementProblem`).
- **Defaults (OD-4).** A new tenant gets a policy for everyone requiring Location, Department, Designation and
  Employment type, on Warn (`peopleos.employment_requirements.seed_new_tenants`; off in `phpunit.xml`). Existing
  tenants get nothing.
- **Uploads.** Rows are checked before approval:
  - a later row of the same person is checked against what the earlier row leaves;
  - Block makes a row error;
  - Warn adds a note to the row ("Errors / notes") and to the run result.
- **Readiness.** Payroll readiness lists, per person:
  - missing employment data, blocking the run checklist only for a rule on Block;
  - the statutory establishment taken from the head office, in companies with more than one;
  - formulas reading blank position values, read through `CompensationOutput`;
  - no leave policy, when the organisation uses leave policies.

  The audit plan had these become blocking automatically after a company's first payroll. That would be blocking
  nobody switched on, so it was replaced by OD-3: the placement items are review items, and blocking comes only
  from a rule on Block.
- **Transfers and promotions.** A transfer or promotion outside the employee's salary structure is a warning
  (`SalaryStructureVersion::mismatch`).
- **Increment previews** say who a filter leaves out only because the value is missing ("2 employees excluded: no
  department").
- **History.** Nothing earlier is rewritten. Versions apply by their effective dates.

## 6. R3: company-specific designation defaults

- **The override.** Designation's configuration type declares `overrides` => `DesignationOverrideFields`
  (level, grade, job family).
  - A company's value must be one that company may use (`CompanyAwareOverrideFields`, called by
    `ConfigurationOverrides::set`).
  - **Level and grade agree (remediation I.6, `LevelGradePairs`).** When a designation's level and grade are both
    set and the grade belongs to a level (`grades.level_id`), it must be that level; a grade with no level fits any
    level, and either may be blank. Checked for the shared values and for each company's, on every date from the
    change on where a company's own values start or end, wherever the pair can change: the Designation model (form,
    upload, clone, API), company values set or ended (`ConfigurationOverrides` after the write, in its transaction,
    through `ValidatesCompanyTimeline`), and a grade moved to another level (the Grade model). Only pairs a change
    makes wrong are refused, so data from before the rule does not block an unrelated change. Level and grade change
    together through `ConfigurationOverrides::together()`: "Company values…" offers "Level and grade together", and an
    upload row with a Company sets its values together and is checked before approval.
  - Values are effective-dated, versioned and audited. Ending one restores the shared value.
  - The "Company values" action and column appear through the existing applicability UI.
- **`DesignationDefaults`** returns the company's value or the shared one, and prefills without ever forcing:
  - choosing a designation fills a blank level and grade on hire, transfer, promotion and rehire (with no new
    company, the employee's current one decides);
  - `Positions::definition` gives a seat its company's defaults.
  - Existing positions are never changed.
- **Dependencies.** `ApplicabilityDependencies` reads company values:
  - forward: a company with its own value is checked against it;
  - reverse: an override that uses a grade keeps that grade applying there, and a record whose own value replaces
    the shared link does not block the shared target.
  - A location with an establishment cannot be shared beyond that establishment's company.
- **Cloning** folds in the destination company's values: the copy keeps what that company used, and the shared
  record is unchanged. The prompt's "effective values for the source company" is read as the company the copy is
  made for, which is the company whose people the copy serves.
- **Designation upload.** A Company column:
  - a filled cell sets that company's values from today;
  - `(none)` ends the company's own value; blank keeps it;
  - other columns must be blank on such a row;
  - the uploader needs `configuration.override` and access to the company.

## 7. R4: master screens

- **Edit pages.** Each master's edit page shows the form as "General" beside these tabs (`ShowsMasterTabs`, read-only
  `MasterTab` relation managers), each only where the type has it:
  - **Companies:** where it applies and since when;
  - **Links:** each link or "Not set (optional)", companies with their own value, and what the employment data
    requirements need; units show their place in the structure, locations their establishment;
  - **Company values:** each company's own values;
  - **Usage:** counts only, and delete or deactivate;
  - **History:** the audit trail.
  - Legal entities, establishments and skills have their own edit page (remediation I.5): a legal entity and an
    establishment (parts of one company) show Usage and History; a skill (shared configuration) Companies, Links
    (its skill scale), Usage and History. Creating them stays a dialog on the list.
  - Company values on the Links tab and in the Unmapped report are limited to the companies the person may see.
- **Creating a master** needs only its essentials. Links sit in an optional "Links" step:
  - Designation: level, grade, job family, usual department, reporting level, employment types;
  - Grade: level;
  - Department: parent, cost centre;
  - Location: parent branch, region, business region and establishment. The establishment must be one of the
    location's own company, and a location shared with other companies takes none (`LocationHierarchy::
    assertEstablishment`, on every save; the form shows it as a field error).

  New units can be placed when created ("Place under (optional)", `organisation.design`), or later in the
  Organisation Designer, which opens on a unit with `?search=CODE`.
- **Unmapped masters** (Organisation) lists:
  - designations without a grade: a blocker only when a requirements policy needs one on Block;
  - units not placed;
  - locations without an establishment in companies with more than one (statutory then uses the head office's).

  Each row links to its fix. Access needs `organisation.view` or `people_setup.view`.
- **Administrators limited to some companies (remediation I.9).** A record shared beyond their companies stays
  unchangeable for them (`SharedConfigurationGuard`). The refusal says how many other companies it reaches (never
  which), who can change it, and what they can do for their own companies (clone it, or give their company its own
  values); the requirements page says so before they try and leaves "Switch to Block" unavailable.
