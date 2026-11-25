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
- **Volume (opt-in):** `WORKBOOK_VOLUME=100,1000,10000` for the workbooks and `REQUIREMENTS_VOLUME=1000,10000` for the
  employment data requirements; both are skipped unless set.
- **MySQL:** only on a disposable server, never a shared or production database. The repository's own MySQL tests
  (`tests/MySql`) switch to a database named `*workbook*` or `*concurrency*` inside each test. Whole feature suites
  were run on MySQL from a scratch copy of the tree; only that copy's guard accepts a private server on
  127.0.0.1:3307 with a `*_test` database. The repository guard is unchanged.
- **MySQL-safe assertions:** MySQL stores JSON objects with its own key order, so compare JSON objects with
  `toEqual`, not `toBe`. Under a frozen clock several rows share a timestamp and MySQL returns ties in any order, so
  break ties by id (`latest('occurred_at')->latest('id')`; audit event ids sort after the chain's previous event).

## Known limitations

Remediation of 25 Nov 2026 (`Final-Completion-Report-Post-Remediation.md` outside the repository) closed the earlier
list: the five API lookups have explicit contracts (I.1), the CSS is on tokens (I.2), the MySQL concurrency suites
share the database cache and the entitlement cache is generation-keyed (I.3), the OpenAPI file describes the
requirement warnings and refusals (I.4), legal entities, establishments and skills have master pages (I.5), company
level and grade pairs are enforced (I.6), ending a company's own values cannot bring in an unchecked Block (I.7),
payroll readiness reads in batches (I.8), restricted administrators are told why and what to do (I.9), tests never
write into `storage/app`: neither its disks nor the working folders of jobs and downloads, which go through
`App\Support\Storage\ScratchSpace` (I.10), the deployment checklist covers the new job and settings (I.11), OD-4 is
verified (I.12) and large pre-flights run on the queue (I.13). What remains:

- **Operational:** a queue worker must listen on `PEOPLEOS_PREFLIGHT_QUEUE`, or a large organisation's pre-flight stays
  pending (it never authorises Block while pending).
- **By decision:** new tenants start with the OD-4 requirements on Warn; placement readiness items stay review items
  (OD-3).
- **Older pre-flight records** (before company fingerprints) vouch only for companies on the shared settings: a company
  with its own values warns until the next "Switch to Block" records its fingerprint.
