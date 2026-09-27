# PeopleOS Phase 1 Report — Foundation Completion

Performed 27 September 2026 on branch `main` from HEAD 1a783d3 (Phase 0.3). Phase 1 completed the core employee foundation on top of the frozen architecture contract (`docs/architecture/peopleos-architecture-contract.md`); no Phase 0 decision was reopened, no later-phase module was expanded, no RMS dependency was introduced.

## 1. Baseline

| Item | Start | End |
|---|---|---|
| HEAD | 1a783d3 | the commit that adds this report (see `git log --oneline -5`) |
| Tests | 319 passed, 2,706 assertions | 342 passed, 0 failed, 2,915 assertions, 343 s |
| Pint | passed | passed |
| Migrations | 70 ran | 72 ran (2 additive) |
| Pushes | 0 | 0 |

## 2. What already existed (verified, kept)

Discovery against the contract showed most foundation objects were already in place from the earlier build: Person and seven satellites; Employee with the one-per-person constraint; effective-dated positions (13 dimensions incl. cost centre) and typed reporting relationships; 15 organisation unit types with the organisation-node tree and designer; `LifecycleEngine` with config-owned transitions, audit, timeline and `EmployeeLifecycleChanged`; Employee 360 with 23 tabs; document types with categories, per-type versioning, verification, expiry sweep and signed, audited downloads; hire wizard; My Day / My Team / People Control Centre dashboards; read API. Phase 1 filled the gaps below rather than rebuilding.

## 3. Implemented

### Employment and lifecycle (commit 1395868)
- `EmploymentEvent` carrying the contract's reserved names — `employee.created`, `transferred`, `promoted`, `manager_changed`, `salary_changed`, `department_changed`, `designation_changed`, `location_changed`, `company_changed`, `rehired` — emitted only by `HireEmployeeAction`, `AssignPositionAction`, `ChangeManagerAction`, `Salaries::assign` and `RehireEmployeeAction`; bridged to the notification engine and outbound webhooks; names registered in config.
- Authoritative actions: `TransferEmployeeAction`, `PromoteEmployeeAction` (position + optional line manager, one transaction; compensation stays in Payroll), `RehireEmployeeAction` (`alumni → active` on the same Employee, new position, history preserved), `UpdatePersonAction` (person-owned facts only).
- Overlap protection: a position may not start on/before the current one nor before a future-dated one (`OverlappingAssignmentException`).
- Lifecycle guard: `lifecycle_state` cannot be mass-assigned on update; only `LifecycleEngine` (or an explicit `unguarded` block) may change it. Tests use `forceLifecycle()`.
- Employee codes: locked per-tenant sequence table (`employee_code_sequences`), seeded from existing codes, skips manually taken numbers; unique index remains the last line of defence.
- Duplicate-person protection: `PersonMatcher` (definite = personal email / phone / work email / external reference / employee code; possible = name + date of birth). Hire refuses definite duplicates unless `allow_duplicate` or an existing `person.id` is supplied; the hire wizard lets HR attach an existing person and halts on definite duplicates.

### Employee 360 and directory (same commit)
- Directory: SQLite-safe name search (person first/last/preferred), columns for location, manager and employment type, filters for location, designation, grade, employment type, category, work mode and manager, persisted above the table.
- Timeline hides compensation/bank/statutory entries without `employee.sensitive.view`; new categories in the filter.
- Overview shows tenure; manager already shown.

### Import foundation (commit bb0460a)
- `employee_imports` + `employee_import_rows` (staging only). Pipeline in `EmployeeImports`: register (private disk, tenant prefix) → inspect (headers, staged rows, suggested mapping, 5,000-row cap) → map (required fields enforced, one column per field, duplicate policy) → validate (required, dates, emails, gender, organisation codes, manager code, in-file duplicates; deterministic matching → create / update / review / skip) → preview → approve (permission `employee.import`, reason, no open reviews) → run (each row through `HireEmployeeAction` / `UpdatePersonAction` in its own transaction, whole run inside `AuditRecorder::operation()` with counts and affected ids) → discard.
- Filament resource "Employee imports" (People group) with per-step header actions and a row manager to resolve reviews.
- `league/csv` declared as a direct dependency.

### API foundation (commit cddf859)
- `POST /api/v1/employees` (scope `employees.write`) creates through `HireEmployeeAction` with organisation codes (`OrganisationCodes`), manager code, external reference; duplicates return 422 with candidates.
- `POST /api/v1/employees/{id}/lifecycle` transitions through `LifecycleEngine` (422 on invalid transition).
- `GET /api/v1/employees[/{id}]` now served by `EmployeeApiResource`: explicit fields, position with codes, manager; `?include=sensitive` only on single reads, only with scope `employees.sensitive.read`, audited as a sensitive view.
- `GET /api/v1/organisation/{type}` (scope `organisation.read`) for companies, locations, business units, divisions, departments, teams, designations, levels, grades, employment types, employee categories, work modes, cost centres.

## 4. Security

Every new path runs on the scoped queries and policies from Phase 0.2/0.3: directory search and filters (tested inside an organisation scope), timeline classification, import resource (permission + tenant; files on the private disk), API (tenant by key; cross-tenant ids 404; sensitive fields opt-in by scope and audited). Manager visibility across scope boundaries is retained (`AccessScopeTest`). IDOR on employees, imports and API records verified.

## 5. Tests

| Suite | Cases | Covers |
|---|---|---|
| Employment/EmploymentFoundationTest | 10 | created event, department change history + events, promotion with manager, overlap/backdate rejection, rehire, lifecycle guard, code generator, duplicate protection, person update, matrix relationships |
| Employment/EmployeeDirectoryTest | 4 | search/filter, scope-bound search, timeline classification, overview |
| Employment/EmployeeImportTest | 4 | full pipeline + audit operation, row errors + mapping guard, deterministic matching + review + skip policy, tenant/permission/private storage |
| Api/EmployeeApiTest | 5 | create via shared action, validation/duplicates/codes/scopes, lifecycle API, sensitive field protection + audit, organisation reference + tenant isolation |

Regression: every Phase 0 suite passes unchanged apart from the intentional helper change (`forceLifecycle`) in twelve test files, made because direct `lifecycle_state` updates are now refused by design.

## 6. Database

Migrations added: `2026_09_30_100001_create_employee_code_sequences_table`, `2026_09_30_100002_create_employee_import_tables`. Both additive, tenant-keyed, indexed; no destructive migration.

## 7. Deferred items (backlog)

1. Person photo upload UI, nominees, address history UI polish (data model exists).
2. Import: update of positions/managers for existing employees (person facts only today), Excel input, scheduled/API-driven imports.
3. API: `people` and `documents` write endpoints, OpenAPI description, `Idempotency-Key` (Integration Hub phase).
4. Notification rules/templates for the new employment events are registered but not pre-seeded per tenant.
5. Rehire and transfer UI actions on the Employee 360 (actions exist as domain actions; the 360 uses Assign position).
6. Field-permission matrix UI, per-key organisation scope (ADR-0005/0014).
7. Legal Entity / Establishment (ADR-0001) untouched.

## 8. Known limitations

- The lifecycle guard is a model event; raw `DB::table('employees')->update()` bypasses it (no application code does this; the architecture test does not yet scan for it).
- Import matching is exact/deterministic by design; transliterated or misspelt names are not matched.
- `employee_code_sequences` prevents duplicate generation under contention through the row lock; on SQLite (tests) the lock is a no-op and only the unique index applies.

## 9. Git

Commits: 1395868 (employment/lifecycle/directory), bb0460a (import), cddf859 (API), the docs commit that adds this report (report). Working tree clean. Not pushed.
