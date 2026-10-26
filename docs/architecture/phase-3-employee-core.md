# Phase 3 — Employee Core

Implements blueprint §121 Phase 3: employee master, Employee 360, personal data, contacts,
family, dependents, qualifications, experience, skills, statutory, banking, employment history,
reporting relationships and the employee timeline. Lifecycle states and the life-event engine
(§19–§20) arrive here too because hiring needs them.

## One person, one lifetime record (§4)

- `people` is the human being: names, birth, contact, photo, plus satellites `person_addresses`,
  `person_family_members` (dependents and nominees), `person_emergency_contacts`,
  `person_qualifications`, `person_experiences`, `person_certifications`, `person_skills`
  (against a tenant `skills` master). All tenant-scoped and audited.
- Besides the date of birth, a person may carry a celebrated birthday (day and month) when the
  papers say otherwise, and choose whether colleagues see it; wishes and birthday notices are in
  `birthdays.md` (2026-10-23).
- `employees` is the employment of that person in this tenant: code, lifecycle state, joining /
  probation / confirmation / exit dates, work contact, optional login `user_id`. One employee
  per person per tenant (unique); a rehire reuses the person.
- `employee_statutory_details` (PAN, Aadhaar reference, UAN, PF, ESIC, applicability flags,
  tax regime) and `employee_bank_accounts` are employment-side because payroll consumes them.

Domains: `App\Domain\People`, `App\Domain\Employment`, `App\Domain\Lifecycle`.

## Placement and reporting are history, not columns (§70, §100)

- `employee_positions` is the effective-dated organisational assignment: company, location,
  business unit, division, department, team, designation, level, grade, employment type,
  category, work mode, cost centre, with `change_type` (hire, transfer, promotion, …).
  `Employee::currentPosition()` is a `hasOne…ofMany` over the effective row.
- `AssignPositionAction` opens a new row and closes the current one the day before. Blank
  dimensions carry forward, so a transfer only states what changes. It refuses a start date that
  is not after the current row, records a PROMOTED / TRANSFERRED / UPDATE audit event with one
  field change per dimension (names, not ids), and writes a timeline line per change.
- `reporting_relationships` holds typed lines (line, functional, dotted, HRBP, mentor, buddy,
  project, secondary). `ChangeManagerAction` closes the previous line of the same type, rejects
  self-reporting and loops in the primary chain, and audits MANAGER_CHANGED.

## Lifecycle engine (§19–§20)

`LifecycleState` enum; the allowed graph is `config('peopleos.lifecycle.transitions')` (platform
logic, not tenant configuration). `LifecycleEngine::transition()` validates the hop, stamps
joining / confirmation / exit dates, appends `employee_lifecycle_transitions`, records the
matching audit action (JOINED, CONFIRMED, EXIT_INITIATED, EXIT_COMPLETED, ALUMNI_CREATED),
writes the timeline and dispatches `EmployeeLifecycleChanged` (the `employee.*` domain events of
§88, one class, branch on `$to`).

`HireEmployeeAction` composes it all: person (new or existing) → employee with a generated code
(`EmployeeCodeGenerator`, see below) → first position → line manager → pre-employee → joined →
probation (or preboarding for future joiners). One transaction.

### Employee code series (2026-10-23)

`employee_code_series` (`EmployeeCodeSeries`, People Setup → Employee code series, `people_setup.*`)
holds prefix + number padded to `digits` + suffix (`MKT-00042-IN`), one row per company plus an
optional tenant default (`company_id` null). The generator picks the hire's company series, else
the default series, else the old settings pattern (`employee.code.prefix` + `padding`, counted in
`employee_code_sequences`), so tenants that never set up a series keep their codes unchanged.

- The series row is its own counter (`next_number`), taken under `lockForUpdate` and moved with a
  direct update (no audit row per hire; the hire is audited, admin edits of the series are).
- A series never goes back: saving one moves `next_number` past the highest code already used in
  its prefix/suffix pattern, and generation skips any number already held (typed or imported).
- Codes stay unique per tenant across companies (`employees (tenant_id, employee_code)`). A code
  given to `HireEmployeeAction` is looked up first, tenant-wide, outside the access scope and
  without case (as MySQL's index compares), and refused with `EmployeeCodeTakenException`
  (`InvalidArgumentException`: 422 on the API, a failed row on imports).
- Hire wizard: Company moved from Position to the Employment step, then *Auto-generate* (shows
  the next code of that company's series as a preview; the number is taken only on create) or
  *Enter manually* (validated on blur and on Next; the holder is named only if the viewer can see
  them). `allow_manual = false` on a series removes the manual choice, and the page refuses a
  forged manual code server-side.
- The API, imports and the RMS hand-over (`CreatePreEmployeeAction`) generate from the hire's
  company series too.

## Timeline (§18)

`employee_timeline_entries` is materialised, written only by `Lifecycle\Services\Timeline` from
the actions above. Categories so far: lifecycle, position, reporting. Descriptions carry the diff
text ("Department: Finance → Sales") and the reason.

## Sensitive data (§66, §80)

- Identifiers and account numbers use the `encrypted` cast, are `$hidden`, and are masked in
  audit diffs (`auditSensitiveAttributes`). Bank accounts keep `account_number_last4` for display.
- Permissions: `employee.sensitive.view` / `employee.sensitive.update` are separate from
  `employee.*`. `SensitiveEmployeeDataPolicy` governs bank and statutory models; `EmployeePolicy`
  adds `viewSensitive`, `updateSensitive`, `assignPosition`, `transition`.
- `SensitiveAccessAuditor` records a VIEW event when the Bank tab mounts, when a statutory modal
  opens, and when an account is revealed with a stated purpose. Gated by the
  `audit.sensitive_access` feature flag; deduplicated per scope within a request.

## Employee 360 (§17)

`EmployeeResource`: list with current position columns and filters, global search (code, names,
work email), a four-step hire wizard, and the view page as the 360: overview infolist, header
actions for life events (transfer / promote, change manager, lifecycle) and statutory
(view / edit, both audited), and tabs: Timeline, Employment, Organisation, Addresses, Family,
Emergency contacts, Education, Previous employment, Certifications, Skills, Bank, History.
Person satellites hang off the employee page via `PersonSatelliteRelationManager`, which resolves
the relationship through `employee->person`.

## Audit canonical form change

MySQL JSON columns reorder object keys, which broke hash recomputation for events with multi-key
metadata. The canonical payload now sorts metadata keys recursively. Chains written before this
change with reordered metadata no longer verify; the development database was rebuilt. There is
no production data yet.

## Not in this phase

- Photos (`photo_path`) have a column but no upload flow yet (documents / object storage, §39).
- Team-scoped visibility for managers (a manager seeing only their reports) waits for the
  employee portal phase; `employee.view` is currently tenant-wide.
- Salary history is Phase 9 (compensation).

## Tests

`tests/Feature/{Employment,Lifecycle}` and `tests/Feature/Admin/EmployeePagesRenderTest.php`:
hiring, code generation, position history, manager loops, lifecycle graph, encryption and
masking, sensitive-view auditing, permissions split, wizard, 360 actions, bank reveal, global
search.
