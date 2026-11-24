# Enterprise configuration, master data and relationships: programme summary

The programme makes configuration and master data work across several companies of one tenant (Markedge
Technologies, FynnEdge Advisory). It covers:
- shared configuration with company overrides and independent clones;
- masters that can be created on their own and linked later;
- business rules enforced where an operation needs them;
- one upload engine for master data, with name resolution.

Details live in the documents linked from each section; this page is the map.

| Part | Document | Commits (branch `feature/peopleos-enterprise-configuration-imports`) |
| --- | --- | --- |
| Applicability, shared configuration, overrides, cloning | [configuration-applicability.md](configuration-applicability.md), [configuration-cloning.md](configuration-cloning.md) | `dd552c0b`, R3 additions `02f6f8be` |
| Name-based resolution | [name-resolution.md](name-resolution.md) | `b56f47d4` |
| Master uploads, workbooks, Holiday Calendars, R5 | [master-data-import.md](master-data-import.md), [data-import.md](data-import.md) | `8eb55365` |
| Masters and relationships: R1 to R4, OD-1 to OD-6 | [master-relationships.md](master-relationships.md) | R1 `4121e02e`, R2 `4c712e5a`, R3 `02f6f8be`, R4 `cdeba629` |

## Principles

1. **Create independently, map optionally, enforce when an operation needs it.** A master needs only its
   essentials (name, code, status). Three links stay required for legal reasons:
   - Legal entity → Company;
   - Establishment → Legal entity;
   - Location → Company.

   Links are set in an optional step or later. Rules about required data apply at the hire or change that needs
   them (R2), never at master creation.
2. **One typed home per kind of relationship.**

   | Relationship | Where it lives |
   | --- | --- |
   | Structure | `organisation_nodes` (Organisation Designer) |
   | Default links | Columns on the master |
   | A company's own value | `configuration_overrides` |
   | Sets | Pivot tables |
   | Placement of a person | Effective-dated `employee_positions` |
   | Reporting | `reporting_relationships` |

   There is no generic relationship store.
3. **Tenant first, then company.**
   - Every table is tenant-owned under the global tenant scope.
   - Applicability is chosen from the tenant's companies, within the person's company access.
   - Name resolution, fuzzy suggestions and errors never show another tenant's records.
4. **History is never rewritten.** Positions, audit events, payroll results and earlier policy versions stay as
   they were. Overrides and versions apply from their effective dates.

## Behaviour in one place

- **Applicability.** A configuration record applies to all companies, selected companies or one company,
  optionally including companies created later.
- **Overrides.** Precedence is the company's own value, then the shared value, then the field default. Ending an
  override restores the shared value.
- **Cloning.** A clone makes an independent copy for another company. References are remapped or reselected, and
  the company's own values are folded in. Lineage is recorded.
- **Employment data requirements (R2).**
  - Required fields and consistency checks, each Off, Warn or Block (Warn by default).
  - Block only after a pre-flight. New tenants are seeded on Warn (OD-4); existing tenants stay Company only.
  - One check point (`PositionRequirements` in `AssignPositionAction`) for screens, uploads, workbooks and the
    API.
- **Designation defaults (R3).** Per-company level, grade and job family. They prefill hires, transfers and seats,
  and never change an existing position.
- **Master screens (R4).**
  - Tabs: General, Companies, Links, Company values, Usage, History.
  - Optional Links step and placement on create.
  - Unmapped masters report.
- **Uploads.**
  - Seven workbooks, 78 worksheets, 122 catalogue processes. 44 operational or special-purpose uploads stay
    individual.
  - A reference cell takes a code, a name or "Name (CODE)". An ambiguous or unknown name goes to review and is
    never auto-picked.
  - Blank keeps the value on file; `(none)` clears an optional link (OD-5).
  - An employee upload takes 5,000 people per file.

## Permissions

| Key | Lets a person |
| --- | --- |
| `configuration.share` | Change which companies a record applies to |
| `configuration.override` | Give a company its own values (with the type's own permission and access to the company) |
| `configuration.clone` | Clone configuration (a copy for another company also needs the rights listed in [configuration-cloning.md](configuration-cloning.md)) |
| `policy.view` / `policy.update` / `policy.publish` | See, edit and publish policies; Switch to Block also needs publish. A person limited to some companies changes only policies that reach no other company |
| `organisation.view` / `people_setup.view` | Open the Unmapped masters report |
| `organisation.design` | Place units in the structure (Designer, "Place under" on create) |
| `employee.import` + each upload's own permission | Run uploads and workbooks |

## Database

- **Three migrations**, all additive and reversible: `2027_02_06` (applicability tables), `2027_02_07` (workbook
  columns on `employee_imports`), `2027_02_08` (name mappings).
- **R2 to R4 add no table.** Requirements are policy rows, designation values are configuration overrides, and
  placement uses the existing structure.
- **Verified on MySQL 8.4:** migration from empty, foreign keys, unique keys, `migrate:rollback --step=3`, and
  migrating in commit order.

## Running the tests

- **SQLite (default):** `php artisan test`, or one folder at a time. Use no cached config (`APP_CONFIG_CACHE`
  pointing to a missing file). `tests/TestCase.php` refuses anything but in-memory SQLite.
- **MySQL:** only on a disposable server, never a shared or production database. The repository's own MySQL tests
  (`tests/MySql`) switch to a database named `*workbook*` or `*concurrency*` inside each test. Whole feature suites
  were run on MySQL from a scratch copy of the tree; only that copy's guard accepts a private server on
  127.0.0.1:3307 with a `*_test` database. The repository guard is unchanged.

## Known limitations

- **Pre-existing failures, unchanged:**
  - `ApiSweepTest` assumes every list endpoint answers without parameters; five need parameters or a linked
    employee. This is an API contract question.
  - `Ux15FoundationTest` design-rule breaches in CSS committed on 16 and 19 Nov.
  - Three shared-cache concurrency tests (Entitlements 5 and 6, Plans 6) fail the same way on the base commit.
- **Not built:**
  - Skills, legal entities and establishments (edited in a modal on their list page) have no master tabs.
  - Ending a company's own value of an employment data requirements policy is not pre-flighted: if the shared
    version is on Block, that company then follows the shared Block for the rules and companies the last pre-flight
    covered (the run-time guard still turns anything it did not cover into a warning).
  - Company values are not cross-checked as a level and grade pair, because companies set them one at a time.
  - Placement readiness items stay review items: no automatic blocking (OD-3).
