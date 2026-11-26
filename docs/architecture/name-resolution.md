# Name-based import resolution

Written 2026-11-23. Describes what is implemented. Part C of the owner's master requirement "Universal entity applicability,
consolidated master imports, name-based imports" (the other parts: `configuration-applicability.md`, `data-import.md`).

**Principle: users manage names; PeopleOS manages codes and relationships.** Any import (a per-type upload, a worksheet
of a master workbook, or anything else that reads typed references) can name a master record by its name, its code, or
both. One shared service turns the typed text into the record's id, or explains in plain English why it cannot. Internal
ids and codes never change.

## How the three parts fit together

| Concern | Owner | What this part uses from it |
|---|---|---|
| Which companies a record applies to (all, selected, current only; effective-dated) | `App\Domain\Configuration\Applicability` (`EntityApplicability`, `ConfigurationTypes`) | `constrain($query, $companyId, $on)` decides whether a candidate applies to the row's company. `config('peopleos.configuration_types')` is the list of masters (with name, code, owner column, manage permission). |
| Consolidated workbooks, dependency order, the Data import page | `App\Domain\Employment\Imports\Workbooks`, `MasterImports`, `ReadsMasterRows` | They call this resolver from `lookup()` / `parentId()` / the employee import's organisation-code check (the import owner wires it in). They keep their own planned-code check first. |
| Name → record, ambiguity, conflicts, suggestions, mappings, creating a missing master | **`App\Domain\Organisation\Resolution`** (this document) | — |

There is therefore **one** list of masters (the configuration-type registry), **one** applicability rule and **one**
resolution path, instead of the dozen copies the audit below found.

## Audit: how imports resolved references before this (2026-11-23)

Read-only sweep of all 70 import classes (plus 12 subclasses the first search missed, `EmployeeImports`,
`BankAccountImports` and `OrganisationCodes`); the claims below were spot-checked against the code.

- **About 12 separate resolution patterns across 60+ call sites.** Examples:
  - `MasterImports::lookup()` (a lower-cased pluck map);
  - `ReadsMasterRows::parentId()` (a `whereRaw lower()` per row);
  - about 20 inline "pluck id by code" copies;
  - `strtoupper` + plain `where('code')`, which depends on the collation: case-insensitive on MySQL, case-sensitive on SQLite;
  - collection `first()` code-then-name lookups;
  - option-list code-or-label loops.

  Some uploads resolve the same column one way at validation and another way at run time (EmployeeImports: a pluck map, then `OrganisationCodes`).
- **Code-only almost everywhere.** About ten importers already accept a name, and most of them **silently take the first (or last) match** when names repeat:
  - first match: work schedule, skill, custom field (by label), variable pay plan, objective by title, option-list labels;
  - last match: role by name, vendor kind, salutation.

  Only asset product, event, goal, key result and family member report "more than one".
- **AccessScope is inconsistent for the same masters.** `lookup()`, `parentId()`, DesignationSkill, Asset and JvCodes ignore it, while PositionChange, EmployeeImports, `OrganisationCodes` and GeoLocation apply it. So a department outside the uploader's scope resolves in a master upload but "does not exist" in an employee upload.
- **Company consistency is not checked at validation:**
  - **Position changes:** a position change's codes validate one by one and fail only at run time (`AssignPositionAction::assertUsableByCompany`).
  - **Departments:** a department's cost centre is looked up across the whole tenant.
  - **Assets:** an asset's location is not checked against its company.
  - **Paying account:** an employee's paying account is matched across all companies.

## The service (`app/Domain/Organisation/Resolution`)

```php
$session = app(MasterResolver::class)->session($uploader, scope: "employee_imports:{$upload->id}");
$r = $session->resolve('department', $row['department'], companyId: $companyId, sheet: 'Employees', row: 12, column: 'Department');
$r->ok() ? $position['department_id'] = $r->id : $errors[] = $r->error();
$upload->options['references'] = $session->report()->toArray();     // for the review
```

| Piece | Job |
|---|---|
| `MasterResolver` | Entry point: `session(?User $actor, ?string $scope, $on)`, `resolve()` for a one-off lookup, `keyFor(Model::class)`. |
| `MasterCatalogue` | The masters, built from `config('peopleos.configuration_types')` (59 types: organisation units, designations, levels, grades, leave types, shifts, salary components, templates, …) plus `company` and `employee`. Keyed like the registry (`department`); a model class is accepted too. |
| `ResolutionSession` | One per file or worksheet. Each master is loaded once; each distinct value is decided once (memoised); the tenant is pinned (a session refuses to be used for another organisation). |
| `Index\MasterIndex` | A master loaded whole (id, code, names, owner company, parent columns, live or not) in one query; the actor's access in one more (only when they are scoped); one `constrain()` query per company asked about. |
| `Index\EmployeeIndex` | Employees fetched only for the values asked (by employee code, work e-mail or full name), with the company of the position in force. |
| `Index\ActorVisibility` | Applies the actor's organisation access explicitly (see Security). |
| `NameKey` | The exact comparison key, and the similarity used for suggestions only. |
| `Resolution`, `ResolutionStatus`, `Candidate` | The answer. Never thrown for data. |
| `ResolutionReport` | One entry per distinct (master, company, typed value): status, record, candidates, count, first 20 places. `toArray()` / `fromArray()` / `merge()`. |
| `ReferenceMappings` | Confirm what a value means (`map`), `retire` a mapping, `create` a missing master. |
| `App\Livewire\Resolution\ReferenceReview` | The review table and its actions. |

### Order of evidence (the same for every master)

1. **Code**, compared case-insensitively and trimmed: exactly what code-based imports have always done, so every existing file resolves the same way (tested against the legacy `lower(code)` query).
2. **Exact name** (`NameKey::key`). The comparison ignores:
   - Unicode compatibility forms (NFKC);
   - typographic quotes, dashes and non-breaking spaces (what Excel inserts);
   - repeated and surrounding spaces;
   - case.

   Nothing else is ignored: "Human Resource" is not "Human Resources", and "Sales and Business Development" is not "Sales & Business Development".
3. **A confirmed mapping**, in this order:
   1. one made for this upload, for the row's company;
   2. one made for this upload, for any company;
   3. a remembered one, for the row's company;
   4. a remembered one, for any company.

Further rules:

- **One cell, text that is both a code and a name.** A single cell may hold either. When the text is one record's code and another record's name, the **code wins** and the result carries a warning.
- **`(none)`** (`ResolutionSession::CLEAR`, the same rule as the imports' `ClearValue`: any case, surrounding spaces ignored) is the uploads' explicit "remove this link". In a value, name or code cell it is **Blank**: never matched (not even to a record literally called "(none)"), never suggested, never reported. The import decides what clearing means before it asks.
- **Separate name and code columns.** When both are given they must describe the same record, otherwise the result is a **Conflict**. This covers three cases:
  - the code points at a record whose name is different;
  - the name matches nothing;
  - the code does not exist but the name does.

### Statuses

| Status | When | Row usable? |
|---|---|---|
| Matched | Exactly one record, by code, name or mapping. Warnings: inactive or no longer in force; the text was also another record's name. | yes |
| Planned | A record an earlier worksheet will create (`plan()`); id -1. | yes |
| Blank | Nothing typed (whether that is allowed is the import's rule). | yes |
| Suggested | No exact match, but similar records exist (≥ 0.75; at most 3). **Never applied automatically.** | no, until a person confirms |
| Unmatched | Nothing matched and nothing is similar. | no |
| Ambiguous | Several live records of that name for the company (or a planned namesake of an existing one). Each candidate is named with its code. | no, until a person chooses or the file uses a code |
| Conflict | Name and code disagree. | no |
| NotApplicable | The record exists but does not apply to the row's company ("belongs to FynnEdge Advisory, not Markedge Technologies"; "is not available to FynnEdge Advisory"). | no |

**Suggestions** (`NameKey::similarity`). A suggestion is offered when any of these holds:

- the same words once punctuation, "&" and plurals are ignored (0.95);
- the typed text is the candidate's initials, e.g. "HR" (0.85);
- the edit-distance ratio is high enough;
- every typed word appears in the candidate's name (0.75).

Suggestions come only from records that apply to the row's company and that the actor can see. People are never suggested.

### Entity-aware, effective-dated

With `companyId`, a candidate must apply to that company on the session's date (`on`, default today), as decided by
`EntityApplicability::constrain()`. Records whose companies were never chosen keep their earlier meaning:

- a tenant-wide master applies to every company;
- a unit with a `company_id` applies to its own company;
- a unit with `company_id` null applies to every company.

So two companies may each own a "Human Resources", and a row is matched to its own company's. Without `companyId`,
both names are candidates and the result is Ambiguous. Pass `companyId` only where the row names a company.

Among namesakes the **live** one is preferred: status not inactive or archived, and in force on the date. An inactive
record is still matched when it is the only one, with a warning; existing imports never refused inactive codes, and
refusing them would break files that worked.

`within: ['parent_department_id' => 4]` narrows names by the record's own reference columns (the registry's
`references` plus the owner column). An unknown column is a programming error (`InvalidArgumentException`).

### Employees

The `employee` master matches on:

- employee code;
- work e-mail;
- full name: "first last", "first middle last" or "preferred last".

Further rules:

- One word never matches a person.
- Namesakes are Ambiguous.
- `companyId` only narrows namesakes; it never refuses a code, because a manager may sit in another company.
- A person is never suggested.
- A person's name is never *remembered* as a mapping (names change and repeat), only confirmed for one upload.

## Mappings and creating a missing master

`master_reference_mappings` (migration `2027_02_08_200001`; `BelongsToTenant` + `Auditable`; classified under
`organisation` in `DataInventory`) holds what a typed value was confirmed to mean. Each row records:

- the master;
- the company (null = any);
- the value as typed and its key;
- the record;
- the scope: an upload such as `employee_imports:42`, or null = remembered for every upload;
- the status, reason, who made it and who retired it.

Re-mapping a value retires the old row; nothing is deleted. `active_key` (`master|company|scope|sha1(value)`, at most 191
characters, null once retired) keeps one active mapping per value.

`ReferenceMappings::map()` refuses, with a message shown as it is:

- remembering without the master's manage permission (from the registry, e.g. `organisation.update`, checked for the row's company);
- remembering a person;
- an upload mapping without an upload;
- a record the actor cannot see;
- a record that does not apply to the row's company;
- a value that is already the exact name of a different record (use the code instead).

`ReferenceMappings::create()` creates a master only when a person asks:

- **Which masters:** only those whose records need just a name and a code (and, for units, a company), listed in `MasterCatalogue::CREATABLE`. Anything richer is created on its own screen.
- **Rights:** the master's **own create policy** (`Gate::allows('create', Model::class)`: `organisation.create` for structure units, `people_setup.create` for designations, levels and grades).
- **Company:** it must be one the actor can reach.
- **Duplicates:** checked under a cache lock, as the system rather than as the actor (a record the actor cannot see still makes a new one a duplicate). It refuses when a record of that name already exists for the company. When a tenant-wide one exists but does not apply to the company, the message is "extend where it applies instead of creating a second one".
- **Code:** generated from the name (`SALES_AND_BUSINESS_D`, at most 20 characters, `_2`, `_3`… when taken, retried on a unique-key race inside a savepoint).
- **Applicability:**
  - a company-owned unit gets the row's company;
  - a tenant-wide master applies **to the row's company only**, through `EntityApplicability::apply(single)`, unless all companies are chosen explicitly (`all`, companies created later not included).
- **Audit:** everything runs in one audited operation.

## The review (`ReferenceReview`)

`@livewire(\App\Livewire\Resolution\ReferenceReview::class, ['report' => $report->toArray(), 'scope' => "employee_imports:{$id}"], key(...))`

**What it shows:**

- the table "Supplied | Resolved record | Entity | Status | What to do", with counts (matched, need attention, settled here);
- by default, what needs attention plus what was settled on this screen; "Show matched names too" shows everything;
- 50 rows at a time ("Show more"); what `forReview()` left out is counted, with a note to correct the file instead.

**What a person can do for each entry:**

- **Use \<candidate\>**: one button per suggestion or namesake;
- **Choose a record**: a list of up to 300 records of that master that apply to the row's company and that the person can see, live ones first;
- **Create \<value\>**: only when allowed, and after a confirmation that tells them to check the suggestions first;
- **Remember for later uploads**: only with the master's manage right, and never for people.

**Behaviour:**

- `report`, `scope` and the row count are `#[Locked]`, so the browser cannot point a mapping at another upload. The host page authorises the upload and passes its scope.
- Every action goes through `ReferenceMappings`, which re-checks rights, access and applicability. Refusals are shown as notifications.
- After an action it dispatches `references-resolved` `{scope, key}`; the host re-validates the upload.
- It uses only existing `pos-di-*` / `pos-fmt-*` styles (no new CSS, no build needed).

## Security

- **Tenant isolation:** every query is tenant-scoped (fail-closed `BelongsToTenant`); a session pins its tenant.
- **Organisation access:**
  - **Why it is applied explicitly:** the `AccessScope` global scope follows the *signed-in* user, but uploads are re-checked in queued jobs as the uploader with nobody signed in.
  - **How:** `ActorVisibility` applies the actor's scope the same way `AccessScope::apply()` does (`constrainOrganisation` for units, `constrainEmployees` for people).
  - **Effect:** records outside it are never matched, suggested or named in a message.
  - **No actor (system context):** sees everything, as today.
- **Mappings and creation:** limited by the rights listed above. A mapping never widens what a record applies to, and never lets a row reach a record its company cannot use.
- **Transactional data:** the service reads masters and writes only mappings and, on request, simple masters. It never touches employees, attendance, payroll or other business records.

## Performance

A 2000-row file with two references per row costs **at most 4 queries** after the first load of each master (tested):
values are decided once per session, and masters are held in memory. Each master's first use costs one load query, one
mappings query, one applicability query per company asked about and, for a scoped actor, one access query.

Employees are fetched per distinct value (at most 500 namesakes per name) unless the column is handed over first:
`$session->prime('employee', $values)` fetches codes and work e-mails 200 to a query, so a 10 000-row file costs a
couple of queries (tested). Full names are not primed.

The report keeps one entry per distinct reference. Masters give tens; people can give hundreds, so the review is
given `forReview()`: everything needing attention (up to 500), plain matches (up to 200), three places each, the rest
counted.

## Decisions (by world-class HCM practice; the owner asked for decisions to be made and recorded)

| # | Decision | Why |
|---|---|---|
| NR-1 | Never auto-apply a fuzzy or "near" match; suggest at most three and require a person to confirm. | The requirement says so, and silent guesses in master data are how payroll lands on the wrong cost centre. Workday and SuccessFactors integrations likewise require an explicit value map. |
| NR-2 | In a single cell, a code beats a name (with a warning when both exist). | Backward compatibility: every existing file used codes. |
| NR-3 | "&" vs "and", plurals and punctuation give a suggestion, not a match. | These are different names to a database and often to people ("Sales & Marketing" vs "Sales and Marketing" may both exist after a merger). |
| NR-4 | Inactive records still match when they are the only candidate (with a warning); the live namesake wins otherwise. | Existing imports accept inactive codes; refusing would break working files. The import's own rules decide whether an inactive unit may be assigned. |
| NR-5 | A mapping belongs to one upload by default; "remember" is opt-in, needs the master's manage right, and is never offered for people. | A remembered alias changes how every later file is read: it is master-data governance, not a row fix. |
| NR-6 | Created masters apply to the row's company only unless "all companies" is chosen; future companies never join silently. | The requirement: future-entity applicability must be an explicit choice. Least privilege. |
| NR-7 | Only name-and-code masters can be created from an import. | Richer masters (leave types, shifts, salary components) carry rules that a name cannot supply. |
| NR-8 | The public API keeps codes (`OrganisationCodes`, `EmployeeController`). | Integrations need stable identifiers; names are for people preparing spreadsheets. |
| NR-9 | Matching runs in PHP on loaded keys, not in SQL. | One comparison rule on MySQL and SQLite alike (collations differ: MySQL compares without case, SQLite does not). |

## Tests

- `tests/Feature/Imports/NameResolution/MasterResolverTest.php` (25 tests) covers:
  - the requirement's example rows, run with Markedge Technologies and FynnEdge Advisory;
  - normalisation and its limits;
  - code backward compatibility;
  - code versus name;
  - name and code conflicts;
  - ambiguity;
  - suggestions never applied;
  - applicability, both company-owned and through `EntityApplicability`;
  - access scope with nobody signed in;
  - live versus inactive;
  - `within` narrowing;
  - mappings: for one upload, remembered, retired, settling ambiguity, refusals;
  - creating a master: the right required, duplicates, a code suffix, company-only versus all companies;
  - planned references;
  - employees, and priming a 10 000-row column (same answers, a couple of queries);
  - tenant isolation, including no record or candidate of another organisation in results or the report;
  - failing closed with no organisation bound, with or without an actor;
  - `(none)` as Blank;
  - the report round trip;
  - the query budget;
  - catalogue coverage of every registered type.
- `tests/Feature/Imports/NameResolution/ReferenceReviewTest.php` (7 tests) covers:
  - the table;
  - taking a suggestion;
  - picking a record and remembering it;
  - creating a missing master;
  - refusals for a scoped viewer;
  - the locked properties;
  - a large report: compacted, paged 50 at a time, the rest counted.

32 tests, 341 assertions, all passing (SQLite in memory, 2026-11-23).

## Names first in every upload (owner decision 2026-11-26)

The owner asked for names instead of codes in every upload, "from the root and across the project": codes are hard to
handle and add complexity. Before this, the resolver above was wired only into the master uploads, the employee upload and
job changes, and every format guide, template and sample still asked for codes. Now:

| Piece | What it does |
|---|---|
| Column headings | A column that names another record is headed by the record: "Department", "Leave type", "Salary structure", "Manager". Field keys did not change (`department_code`, `leave_code` …), so saved mappings, process presets, workbook dependencies and old files keep working. |
| Old headings | `tests/Fixtures/Imports/legacy-headers.php` holds every heading the column matcher recognised before; `NamesFirstTest` checks each still maps to the same field (aliases where the old heading's slug is not the field key). |
| Row uploads | `RowImports::references()` declares the reference columns (field → master). `ReferenceCells` turns a name in them into the record's code **before** `check()` and `apply()` see the row, so each upload's own lookups by code are unchanged. A code on file passes through untouched. A name several records share, a near miss, a conflict or a record that does not apply to the row's company is the row's error (the resolver's message), and goes to the review. A name that matches nothing is left to the upload ("does not exist"). Names are matched for the row's company: the one the row names, else the company of the employee the row is about. |
| Master uploads | References already went through `lookup()` / `parentId()`. Their own Code is now optional (`MasterImports::keyed()`): blank, the row changes the record of that name (for the row's company when records belong to companies; the live one among an active and an inactive namesake), or adds one with a code made from the name (`ReferenceMappings::codeFrom`, then `_2`, `_3` … when taken, deleted records and earlier rows of the file included). Several records of the name are refused ("put the code of the one you mean in the Code column"); two rows adding one name are refused. A parent named in an earlier row of the same file is found by name (`addedEarlier()`). Workbooks plan a master row under its code and its name, so later worksheets can name it. |
| Other lists | `MasterCatalogue::OTHER` adds vendors, customers, performance cycles, KRAs, units of measurement and the performance lookups (perspective, initiative type, PIP reason; one model, told apart by `MasterSpec::$where`). |
| Countries | `Countries::codeOf()` takes "India" or "IN"; `ReadsMasterRows::country()` uses it. |
| Wording | `App\Support\Imports\ReferenceGuide`: one wording for reference columns, people, a master's own code and the notes. The workbook Read me and the upload pages say the same. |

Deliberate limits:

- **The row's own employee stays "Employee code"** in employee-level uploads (leave balances, attendance, salary, family …).
  It is the person's key: names repeat, and a leave balance or a salary must never land on a namesake. Other people a row
  names (manager, reviewer, custodian, guarantors, project members) take employee code, work e-mail or full name; a full
  name two people share is refused.
- **Real-world and external codes stay codes**: postal/PIN codes, phone dialling codes, device ids, JV and accounting
  system codes, ISO region codes, an insurer's own code, official state and district codes of the place lists.
- Record forms on screen still ask for a code; this change covers uploads.

### After an upload: columns and names (owner request 2026-11-26)

"Let them choose the field when a column name is wrong, show when a name is not available, and let them pick an existing
value or add the new one, for every field type." On the Data import page (step 4) and on every upload's own page
(`filament.imports.upload-review`, shared by the employee, bank, punch, attendance sheet, salary and time office / data
upload pages, which reload after a decision: `RechecksUploadReview`):

- **Columns in your file** (`App\Livewire\Imports\ColumnMatch`): each column of the file, its first value, the field it is
  read as and a status — Matched, Left out, or **Not recognised** (a heading the column matcher could not place and no
  field is chosen for). The panel opens by itself when a column is not recognised or a required field has no column.
  "Save and check again" maps the file with the chosen fields (and the upload's own options: mode, month, company …) and
  checks every row again. Only the person who may prepare the upload changes it, before approval; a workbook's worksheet
  is read-only here (its columns follow the template).
- **Names in your file** (`ReferenceReview`): a value that matches nothing is **Not available**; one that only resembles
  records is "Not found: similar names". For each: take a suggestion, **choose an existing record**, or:
  - **add it as new** for lists a name and a code fully describe — the organisation units and people-setup lists as
    before, and now skills, qualifications, asset types, customers, KRAs, perspectives, initiative types and PIP reasons
    (`MasterCatalogue::CREATABLE`; right: the list's create policy, else its management right, `.update` read as
    `.create`; a performance lookup gets the kind it was looked up in);
  - **set up a new one** on its own screen for lists that carry rules (leave types, shifts, salary structures, pay items,
    holiday calendars, cycles …), then **Check the file again**. A leave type without entitlements or a shift without
    timings made from a bare name would quietly give wrong balances, attendance or pay, so these are never created here.
  - People are never added from a name (the employee upload adds them); the review says so.

Tests: `tests/Feature/Imports/UploadReviewTest.php` and `NameResolution/ReferenceReviewTest.php`; `tests/Feature/Imports/NamesFirstTest.php` (old headings still map; no reference column asks for a code; names in
row and master uploads; blank codes; shared names refused; a workbook naming records an earlier worksheet adds) and
`NamesFirstMastersTest.php` (places, branches, designation company values, JV mappings, projects, vendors, skills, the
unit tree). 2026-11-26, SQLite: those 20 plus every upload suite (538 tests in all) pass.

## Status and what is left

- **Built and tested:** the service, the mappings table, missing-master creation, the review component.
- **Wiring into the existing uploads: the owner has asked for it (reported by the import session, 2026-11-23); the import session owns the call sites and is wiring them.** It changes how about 20 single uploads read references (`master-data-import.md` §10). There are three integration points:
  - `MasterImports::lookup()`;
  - `ReadsMasterRows::parentId()`;
  - the employee import's organisation-code check.

  It can be done in two steps:
  1. **Additive.** Call `resolve()` with no actor and no company. Every row that resolves today resolves to the same id, because codes come first under the same rule. A row that fails today because it holds a name now matches when exactly one record has that name.
  2. **Opt-in.** Pass the uploader and the row's company, to get access and applicability checks.

  Until then, existing uploads behave exactly as before. For parity, pass the uploader as actor only where the upload
  applied organisation access before (employee upload, organisation codes, position change, locations), and keep the
  upload's own message for an unknown code.
- **Adopted everywhere (2026-11-26, "Names first" above):** the inline lookups listed in the audit now receive codes
  translated from names by `ReferenceCells`. The importers that matched names themselves and took the first or last
  namesake still do so where a name reaches them unchanged (a name that no record has as its exact name).
- **The company-consistency gaps in the audit are not fixed here** (they belong to their importers): cost centre by department company, asset location by company, paying account by employee company, and position codes checked at validation. Passing `companyId` to the resolver fixes each one as it is adopted.
- **Not run on MySQL:** the local MySQL account (`hcm_app`) may not create a scratch database, so no MySQL run was possible. The DDL was checked by hand instead:
  - identifiers are at most 43 characters;
  - `active_key` is at most 191 characters, in a 255 column;
  - status values fit `varchar(16)`.

  Run `php artisan migrate` on MySQL before release.
