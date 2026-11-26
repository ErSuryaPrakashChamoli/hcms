# Configuration applicability: shared configuration, company overrides and cloning

Built 2026-11-23 for the owner's master requirement "Universal Entity Applicability, Shared Configuration, Entity-Specific Overrides, Configuration Cloning and Cross-Entity Reusability". It extends the cloning framework already in place (`docs/architecture/configuration-cloning.md`, phase 1, OD-1 to OD-10 accepted 2026-11-21). It does not replace it.

"Entity" in the requirement means **company** (`companies`, one tenant has several). Legal entities and establishments stay statutory structures under a company; decision OD-1 already keeps them out of configuration targeting.

The document has four parts: the audit (§1), the design (§2), the implementation report per module (§3) and the test report (§4). §5 lists what is deliberately not done and why.

---

## 1. Audit report (Stage 1)

### 1.1 Scope reviewed

- **All 487 models** in `app/Domain/*/Models`. Each was classified as configuration, transactional or platform-level.
- **The configuration models** among them, with:
  - their create migrations (unique keys);
  - their Filament resources (243 resources);
  - the services that resolve them for an employee;
  - the pickers that offer them.
- **The existing mechanisms:**
  - `RuleEngine` / `EmployeeRuleContext`;
  - `PolicyResolver`;
  - the cloning engine;
  - `AccessScope` / `AccessScopes`;
  - role grants per company (`role_user.company_id`);
  - the audit chain;
  - `DataInventory`.

Modules covered: Organisation, Employment, Onboarding, Exit, Letters, Feedback, Documents, Workflow, Notifications, Configuration (policies, forms), Attendance, Leave, Payroll, Compensation, Expense, Performance, Career, Learning, Skills, Engagement, Recognition, Grievance, Assets, ServiceDesk and Analytics.

### 1.2 How companies own or target configuration today

| Mechanism | Where | Meaning |
|---|---|---|
| Tenant-wide record (no company column) | Almost all configuration: designations, grades, levels, job families, employment types, categories, work modes, policies, leave types, shifts, schedules, calendars, templates, components, categories… | Every company may use it, now and later |
| `company_id` nullable on the record | Locations (NOT NULL), business units, divisions, departments, teams, cost centres, profit centres, letter signatories, code series | The company it belongs to; null = every company |
| `company_id` inside rule conditions | Policy assignment rules, work-schedule rules, holiday-calendar rules, onboarding template conditions, workflow start conditions, goal templates, performance units | Who among employees gets it (eligibility), not who may use it |
| `company_id` on versions or rows | Salary structure versions, compensation ranges and cycles, payroll periods, company bank accounts | Company-specific by construction |
| `company_ids` JSON | Service resolver rules, service definition `org_scope`, audiences `criteria.company_ids` | Targeting lists |

### 1.3 Existing applicability, cloning and override mechanisms

- **Applicability.** There is no explicit "which companies may use this record" for tenant-wide records. Rule conditions decide who gets a record, but not where it may be used. Nothing stops a FynnEdge employee from being placed in a department "owned" by Markedge.
- **Cloning.** The engine and the policy adapter come from phase 1. Elsewhere there are one-off replicas:
  - dashboards;
  - compensation cycles;
  - letter schedules "Use again";
  - website pages;
  - "new version" actions, which are versioning, not cloning.
- **Overrides.** None. The closest pattern is the per-category rows inside expense policy settings.

### 1.4 Gaps found

1. **Placement is never validated.** Hire, transfer, rehire, imports and the API accept any department, location, designation, grade, … for any company (`AssignPositionAction` checks only that a company is given). Pickers are not filtered by company.
2. **No "shared with these companies" state.** The choices are every company (tenant-wide or null company) or exactly one company (`company_id`). Two of three companies cannot be expressed without duplicating the record.
3. **No per-company values.** Two companies on one leave policy cannot have different annual-leave days without two policies, and two policies drift apart.
4. **Copying exists only for policies.**
5. **Pre-existing security gaps** (recorded, not widened):
   - configuration lists are not company-scoped (OD-2);
   - `CostCentre` / `ProfitCentre` declare `accessScopeDimension = 'company'`, which filters their own id by company ids;
   - units with a null company are hidden from company-scoped users;
   - role-owned tasks ignore company-limited role grants (OD-3).

### 1.5 Duplicate configuration patterns

These were found by name across companies:

- policies of the same type with company-specific rules: copies made with the old "Duplicate" action, now Clone;
- departments and locations with the same name in several companies: legitimately separate, company-owned;
- same-named onboarding templates differing only in their company condition.

None are merged automatically. Records with different business meanings are never merged (Part 11). The Clone history and the new "Copied from" column show lineage instead.

### 1.6 Security and dependency findings

- `User::hasPermission($key, $companyId)` honours company-limited role grants. Phase 1 cloning was its first caller; this work is the second.
- Dependencies between masters are plain columns. Examples: designation → level, grade, job family, department; grade → level; department → parent, cost centre; location → region, parent. Policy settings point at leave types, shifts, letter templates, checklists, feedback templates, goal templates, ticket categories, expense categories and asset types (by code or id). Onboarding items point at document types, forms and feedback templates.
- Transactions keep their own references. Positions hold unit ids; attendance records hold policy version ids; payroll lines and payslips hold copied values; finalised payroll is immutable. No configuration change can rewrite them.

---

## 2. Technical design (Stage 2)

### 2.1 Principles (and the alternatives rejected)

- **One logical record, many companies.** A shared configuration is never stored as one copy per company (Part 5.2). The companies are rows next to the record.
- **Three distinct capabilities on one registry:**
  - **shared** (applicability);
  - **override** (a company's own value of a field);
  - **clone** (an independent copy, the phase 1 engine).
- **Entity applicability is the outer gate; rule conditions remain eligibility inside it.** A policy limited to Markedge and FynnEdge is never resolved for Third Labs, whatever its rules say. Within its companies, its rules still decide who gets it. This is the usual split in enterprise HCM: who may use a configuration, then who receives it.
- **Backward compatible by construction.** A record whose companies were never chosen keeps its earlier meaning:
  - tenant-wide → every company, now and later;
  - `company_id` set → its company;
  - `company_id` null → every company.

  No data migration is needed. No existing table changes. Rollback drops three tables.
- **Rejected: per-table columns or a pivot per module.** That would be about 60 migrations and 60 slightly different behaviours (Part 15.4–15.5). One polymorphic set of tables keyed by a registry key is used instead. The key is not a class name, so model renames do not break it.
- **Rejected: a universal relationship store** for every master-to-master link. Typed columns and the organisation tree stay as they are; see the master-relationships audit.

### 2.2 Database (migration `2027_02_06_200001`, nothing existing altered)

**`configuration_applicability`** is the header. It has one row per record whose companies were chosen explicitly.

- Columns: `configuration_type` (registry key), `configuration_id`, `mode` (`all` / `selected` / `single`), `include_future`, `created_by`, `updated_by`.
- Unique per tenant, type and record.

**`configuration_applicability_companies`** holds one row per company, effective-dated.

- Columns: `effective_from` (null = from the beginning), `effective_to`, `source`, who added it, who ended it, and the reason.
- `source` is one of:
  - `earlier`: the reach the record had before its first explicit choice, seeded so history stays true;
  - `chosen`;
  - `future`: joined as a new company.
- Rows are **only ever end-dated** (model guard). Deleting is refused.

**`configuration_overrides`** holds one row per company value.

- Columns: `field` (a column, or a settings path), `value`, `shared_value` (the shared value at the time, kept for history), `effective_from`, `effective_to`, `status` (`active` / `ended` / `superseded`), `version` (per record, company and field), `reason`, who made it and who ended it.
- Only the end of a row may change (model guard); deleting is refused.

All three tables are `BelongsToTenant` and classified in `DataInventory` under configuration. They are exported and deleted with the organisation.

### 2.3 Registry: `config('peopleos.configuration_types')`

One entry per configuration type. It is the only place a module declares its participation:

```php
'department' => [
    'model' => Department::class, 'module' => 'organisation', 'permission' => 'organisation.update',
    'owner' => 'company_id',                                  // company-owned type (null = tenant-wide)
    'references' => ['parent_department_id' => 'department', 'cost_centre_id' => 'cost_centre'],
    'usages' => [['table' => 'employee_positions', 'column' => 'department_id', 'company' => 'company_id', 'current' => 'effective_to', 'label' => 'current positions']],
    'clone' => 'department',                                   // served by GenericCloneAdapter
],
'policy' => [ …, 'overrides' => PolicyOverrideFields::class, 'references_provider' => PolicyReferences::class, 'payroll_sensitive' => true, 'clone' => 'policy' ],
'shift'  => [ …, 'overrides' => ['grace_in_minutes' => ['label' => …, 'kind' => 'number', 'rules' => […]], …], 'clone_children' => ['breaks' => ['foreign' => 'shift_id']] ],
```

59 types are registered (§3). `RegistryAndMigrationTest` fails when a type names a model, table, column, permission, reference or usage that does not exist.

### 2.4 Services (`app/Domain/Configuration/Applicability`, `…/Overrides`)

| Service | Job |
|---|---|
| `ConfigurationTypes` / `ConfigurationType` | The registry |
| `EntityApplicability` | Covers the following:<ul><li>`reach()` and `appliesTo()` on a date;</li><li>`constrain($query, $companyIds)` for pickers, lists and resolvers;</li><li>`plan()`, a read-only review: before and after, joining and leaving, blockers, warnings, whether it is shared;</li><li>`apply()`, one transaction with one `APPLICABILITY_CHANGED` audit event;</li><li>`companyCreated()`, which adds future companies;</li><li>`assertUsableByCompany()` for positions;</li><li>`editableBy()`, `summary()`, `kind()` and `constrainKind()` for list filters.</li></ul>Explicit rows are read once per type and request (a process-wide memo flushed on writes, `RequestHandled`, `JobProcessing` and app register). |
| `ApplicabilityDependencies` | Three checks:<ul><li>**forward**: everything the record references must apply to every company it will apply to;</li><li>**reverse**: nothing that references it may reach a company it leaves;</li><li>**usage**: current rows in a company it leaves block the change ("1 current positions in FynnEdge still use this department").</li></ul>References come from the registry's `references` columns, plus a `ReferencesProvider` for settings and items (`PolicyReferences` reuses the clone adapter's classification of policy settings; `OnboardingTemplateReferences` covers checklist items). |
| `SharedConfigurationGuard` | Model events for every registered type, so screens, imports and the API are all covered:<ul><li>a person limited to some companies cannot update or delete a record that reaches other companies;</li><li>a tenant-wide record they create applies to their companies only;</li><li>a deleted record's applicability rows go with it.</li></ul>System work (no signed-in person) and platform operators are not restricted here; tenant isolation still applies. |
| `ConfigurationOverrides` | Its methods:<ul><li>`set()` and `end()`, effective-dated and versioned, each with an `OVERRIDE_SET` / `OVERRIDE_ENDED` audit event;</li><li>`valuesFor()` and `applyTo()`, which layer a company's values on shared values;</li><li>`explain()`, which shows shared vs company vs default per field;</li><li>`history()`;</li><li>`authorise()`.</li></ul>Values are validated by field rules, and **the settings with the override applied are validated together** by the policy type's own validator, as a publish would. |
| `SettingsPath` | Field paths inside settings. A list row is picked by key, never by position: `entitlements[leave_type_code=AL].days`. |
| `PolicyOverrideFields` / `AttributeOverrideFields` | Which fields a company may override: every answer of a policy type's settings form, including every column of every table row; or the columns declared for a type such as shift. |
| `GenericCloneAdapter` | The clone adapter of every registered type with `clone` and no adapter of its own; see §2.6. |

### 2.5 Precedence (Part 2.2 / Part 6)

For one company on one date:

1. the company's override in force on that date (`active`, or `ended` later);
2. the shared record's value: for versioned policies, the version in force on that date;
3. the field's system default (`explain()` shows "default", e.g. `LeaveEntitlements::DEFAULTS`).

A change to the shared record never touches overrides. An overridden field keeps the company's value until the override ends; ending it restores the shared value from that day. A newer override of the same field ends the older one the day before it starts. One planned for the same start supersedes the earlier plan before it ever applies.

### 2.6 Cloning (Part 7)

The phase 1 engine is unchanged in what it guarantees:

- plan;
- scope and permissions (`configuration.clone`, `configuration.clone.cross_company`, the type's permission for every destination);
- conflicts;
- one transaction inside one audited operation;
- idempotency;
- lineage;
- a copy that is always **inactive** and independent.

What is new:

- **`ApplicabilityKind::EntityApplicability`.** The snapshot carries the source's reach. A copy applies to its destination company only, and the boundedness invariant is still proven.
- **`GenericCloneAdapter`**, which `CloneRegistry` serves for every registered type with a `clone` key:
  - copies the record and its owned children (`clone_children`, e.g. onboarding items, shift breaks, calendar holidays, question bank questions, goal template items with their parent links remapped);
  - gives the copy a new name and code;
  - for a company-owned type, the copy belongs to the destination;
  - for a tenant-wide type, the copy gets explicit applicability "current entity only" (the destination).
- **References.** A shared master the destination may use is reused. One it may not use **blocks** the clone ("make it apply there first"). A unit of another company is mapped to the destination's unit of the same name, or left empty with a warning.
- **Rule columns** (`rule_columns`). Company conditions are dropped (applicability now says the company). Unit conditions are mapped to the destination, or dropped with a warning; the copy is inactive, so it is reviewed before use.
- **Never copied:** ids, timestamps, defaults, system flags, current-version pointers, uploaded files (`clone_exclude`), and every table listed in `usages` / `clone_excluded`.
- **The Clone wizard** uses each type's own name and code columns (onboarding templates and workflows use `key`). Its links open each type's own page.

### 2.7 Where applicability and overrides take effect (runtime)

| Point | What happens |
|---|---|
| `AssignPositionAction`, used by hire, rehire, transfer, promotion, imports and API through the action | Refuses a unit, designation, grade, level, employment type, category, work mode, cost centre or dimension that was explicitly limited to other companies. The check uses the later of the start date and today: past periods are history. |
| Hire wizard and position forms (`CreateEmployee::positionFields`) | Once the company is chosen, pickers offer only records that apply to it |
| `PolicyResolver::resolve()` / `resolveAll()`, used by all 21 policy types: leave, attendance, overtime, short leave, time office (comp-off), loan, check-in, work schedule, exit, confirmation (probation), transfer, helpdesk, expense, expense advance, goal sheet, PIP, 360, performance, asset, reward points, general | A policy limited to some companies is skipped for other companies' employees. The version returned carries the employee's company's overrides as a **read-only copy**: saving it throws, and the stored version is never changed. |
| `ShiftResolver::resolve()` / `resolveBetween()` | A schedule limited to some companies is skipped in rule resolution. The shift returned carries the company's own grace and day minutes, as a read-only copy (`CarriesCompanyOverrides`). |
| `HolidayResolver::calendarFor()` | A calendar limited to some companies is skipped for other companies' employees |
| `Onboarding::templateFor()` | Same, for onboarding and induction templates |
| `WorkflowTrigger::fire()` | A workflow with explicit applicability starts only for its companies' subjects |
| `GoalTemplate::appliesTo()` | Same, for goal templates |
| `CompensationChanges` (assignment of a salary structure) | A structure limited to some companies is never assigned in another company |
| `Letters::generate()` (every letter path) and the employee page's letter picker | A template limited to some companies is not used for another company's people |
| `DocumentRules::missing()` / `missingForField()` and the employee document upload picker | A document type is required of, and offered to, only the companies that use it |
| `ServiceCatalogue::eligible()` | A service, or a service in a request category, limited to some companies is not offered elsewhere |
| `Grievances::raise()`, `Recognitions::refusalForReceiver()`, `AssetRequests::request()` | A grievance category, badge, or asset category/subtype limited to some companies is not used in another company |
| `Appraisals` eligibility (launch and `ineligibility()`), `Learning::applyAssignment()` | A cycle on a template or rating scale limited to some companies appraises only their people; a course or path limited to some companies is assigned only to them |

### 2.8 Permissions (Part 9)

There are two new keys, synced by `peopleos:sync-permissions`.

- `configuration.share`: share configuration across several companies and change which companies a record applies to.
- `configuration.override`: give one company its own values of a shared configuration.

Templates:

- HR Admin gets both keys.
- Expense Admin gets `configuration.override`.
- Tenant Super Admin (`*`) gets everything.

Every check is on the server:

- **Changing applicability** needs the type's own permission. It also needs `configuration.share` when the result reaches more than one company, or includes later companies. Every company joining or leaving must be inside the person's company scope and allowed by their company-specific role grants (`hasPermission($key, $companyId)`).
- **A person limited to some companies:**
  - cannot change where a record applies when it reaches companies outside their access;
  - cannot include later companies;
  - cannot edit or delete a record shared beyond their access (`SharedConfigurationGuard`);
  - **can** override values for their own company. That is what overrides are for.
- **Overrides** need `configuration.override` and the type's permission, for that company, inside the person's access.
- **Clone** is unchanged from phase 1.

### 2.9 Audit

| Event | When |
|---|---|
| `APPLICABILITY_CHANGED` | Every applicability change. Before and after are company names; the metadata carries the full plan. A company created later carries one event listing the records it joined. |
| `OVERRIDE_SET` / `OVERRIDE_ENDED` | Field, company, before and after, effective date, version |
| `CLONE` plus a `CREATE` per created row | Clone, as in phase 1 |

All events are in the tenant's hash chain; the tests verify the chain after each kind.

### 2.10 Screens (Part 8), added centrally to every registered type

The shared pages add these; no module code is needed:

- `FollowsFormWizard` (create and edit wizards);
- `PeopleManageRecords` (side drawers);
- `InteractsWithModuleContext` (every list).

| Where | What |
|---|---|
| Create wizard and create drawer | An **"Applies to"** step: All entities (+ "Include companies created later"), Selected entities (checkboxes), or Current entity only. A choice that means what the record means anyway writes nothing. A person limited to some companies does not get "All entities". |
| Edit wizard | **"Applies to"**: what the record applies to today, with *Applies to…*, *Company values…* and *Clone* |
| Every list | Columns:<ul><li>**Applies to**: badge, "shared" in blue;</li><li>**Company values**: "Own values: FynnEdge Advisory";</li><li>**Copied from** (hidden by default).</li></ul>Filters:<ul><li>**Entity**: records that apply to a company;</li><li>**Applicability**: shared vs one entity only.</li></ul>A **Companies** action group holds *Applies to…*, *Company values…* and *Clone*. |
| *Applies to…* | Two steps: the selector with a From date, then a **Review** listing:<ul><li>configuration and operation;</li><li>applies to now and will apply to;</li><li>joining and leaving;</li><li>whether a shared configuration is affected;</li><li>that it creates no copy;</li><li>blockers and warnings.</li></ul>An explicit confirmation is required when several companies are involved. |
| *Company values…* | Choose a company to see every field as shared vs own. Then set a value or go back to the shared value, from a date. A review states that only this company changes. |

### 2.11 Migration and rollback (Part 11)

- **Nothing is migrated automatically**, and no existing ID, foreign key or row changes. Records start using the framework when someone chooses their companies. At that moment the earlier reach is written as `earlier` rows open at the start, so "where did this apply on 1 March" stays answerable.
- **Rollback.** `migrate:rollback` of `2027_02_06_200001` drops the three tables. Every record is back on its earlier meaning, and no position, attendance, payroll or audit row is affected (`RegistryAndMigrationTest`).
- Duplicate records with different business meanings are never merged.

---

## 3. Implementation report (Stages 3–8)

**Shared** = applicability with dependency, usage and permission checks, list column, filters and actions. **Gate** = where it is enforced at runtime.

| Module | Shared | Override | Clone | Testing | Status |
|---|---|---|---|---|---|
| Organisation | Location, business unit, division, department, team, cost centre, profit centre, region, business region, organisation dimension, designation, level, grade, job family, skill, qualification. Gate: position assignment and pickers. | Not applicable for masters (no per-company values beyond name and code). Designation-level overrides are proposed in the master-relationships audit. | All except qualification (no code) | Shared, Generic cloning, Security, Screens, Registry | **Complete** |
| Workforce | Employment type, employee category, separation and transfer reasons. Gate: position assignment and pickers. Code series were already company-aware. | Via transfer and confirmation policies | Employment type, category | Shared, Registry | **Complete** |
| Lifecycle | Onboarding template (gate: `Onboarding::templateFor`), letter template (gate: `Letters::generate`, which every letter path uses, plus the employee page picker), document type (gate: `DocumentRules` required documents and the employee upload picker), clearance checklist and feedback template (chosen through exit and performance policy settings, so dependency-validated), letter signatory, question bank | Via confirmation (probation), exit and transfer policy settings | Onboarding template with items and bounded conditions, letter template, feedback template, question bank with questions, document type | Generic cloning (onboarding end to end), Lifecycle gates (letters, documents), Registry | **Complete** |
| Attendance | Shift, work schedule (gate), holiday calendar (gate), attendance, overtime, time office, check-in and work-schedule policies (gate) | Policy settings (e.g. grace minutes). Shift grace and day minutes through `ShiftResolver` (both paths). | Shift with breaks, schedule, calendar with holidays, policies | Overrides (grace on policy and shift, batch and single), Shared | **Complete** |
| Leave | Leave type; leave policy (gate) | Every leave policy setting, including each leave type's entitlement row (days, carry-forward, encashment, …) | Leave type, leave policy | Overrides (18/24 days, precedence, versions, removal), Shared | **Complete** |
| Payroll | Salary component; salary structure (gate on assignment); expense group and category; loan, expense and expense-advance policies (gate) | Loan, expense and expense-advance policy settings, refused inside a closed payroll period or attendance month of the company. Component and structure field overrides: **deferred** (§5). | Salary component, expense group, expense category, policies. Salary structure: **deferred** (§5). | Payroll safeguards, Overrides (closed period) | **Partially implemented** (deferred items justified) |
| Performance | Appraisal template and rating scale (gate: appraisal eligibility in `Appraisals`), competency, goal template (gate), KPI rating formula, career track, career path, course and learning path (gate: `Learning::applyAssignment`), skill scale | Goal sheet, PIP, 360, performance policies | Rating scale, competency, goal template with KPIs, KPI formula, career track | Lifecycle gates (appraisal, learning), Registry | **Partially implemented**: versioned appraisal templates, courses and learning paths are not cloned (§5). |
| Engagement | Grievance category (gate: `Grievances::raise`), recognition badge (gate: `Recognitions::refusalForReceiver`), mood reason, audience (company-bound by its own `company_ids` criteria) | Reward points policy | Grievance category, badge, audience | Lifecycle gates (grievance, badge), Registry | **Complete** |
| Assets | Asset category and subtype (gate: `AssetRequests::request`), asset model | Asset policy | Category, subtype | Lifecycle gates (asset request), Registry | **Complete** |
| Services | Service request category, service (gate: `ServiceCatalogue::eligible`, which also checks the service's request category), service-level policy | Helpdesk policy | Service request category | Lifecycle gates (catalogue), Registry | **Complete** |
| Workflows | Workflow (gate: `WorkflowTrigger`), notification template | Not applicable (versioned definitions) | Notification template. Workflows (versioned): **deferred with justification** (§5). | Registry | **Partially implemented** (workflow clone deferred) |
| Administration | Form | Not applicable | — | Registry | **Partially implemented**: custom fields, option lists and tenant settings are not registered (§5). Roles are already company-scoped through role grants. |

Every registered type has all three capabilities that fit it (shared everywhere; overrides wherever the module's values live in a policy or in shift fields; clone wherever the type is not a versioned definition) and the same screens. Every runtime point that picks configuration for an employee is company-gated (§2.7). The statuses short of Complete come only from the deferrals in §5: overrides of salary components and structures, cloning of versioned definitions (salary structures, workflows, appraisal templates, courses, learning paths), and per-company tenant settings. Each deferral has a reason given there.

---

## 4. Test report (Stage 9)

New tests are in `tests/Feature/Configuration/Applicability`. All use Markedge Technologies (MK), FynnEdge Advisory (FE) and Third Labs (TH).

| File | Tests | Covers (requirement) |
|---|---|---|
| `SharedConfigurationTest` | 8 | Earlier meaning kept; one department for MK and FE (one record, one audit event, true history); all entities with and without later companies; safe removal blocked by current positions, then allowed with history kept; no past dates, owner kept; position and picker enforcement; dependency validation both ways; policy gate (13.1, 13.5, Part 12, Part 17) |
| `OverridesTest` | 7 | FE 24/8 vs MK 18/6 with inherited vs customised shown; precedence over a later shared change; ending restores the shared value; versions and supersede; refusals (past, not applicable, unknown field, invalid value); closed payroll period; grace on an attendance policy and on a shift (single and batch resolver); settings paths (13.2) |
| `GenericCloningTest` | 5 | Department clone (inactive, owned by FE, cost centre mapped, source byte-identical, independent later); designation clone (current entity only, references reused, blocked when not applicable); onboarding template with items (new ids, bounded conditions, resolution for FE vs MK, independent later); code taken and permission refusals; every registered clone type resolves an adapter with real columns (13.3) |
| `ApplicabilitySecurityTest` | 5 | `configuration.share` and the type's permission; a Markedge-limited person can neither apply to nor edit for FynnEdge, and what they create stays theirs; `configuration.override` per company; tenant isolation (13.4) |
| `RegistryAndMigrationTest` | 3 | Registry matches schema and permissions; tables in `DataInventory`; migration down and up leaves departments, designations and positions byte-identical and the audit chain valid (13.5, Part 11) |
| `PayrollSafeguardsTest` | 1 | A structure limited to MK is never assigned to an FE employee; MK unaffected |
| `LifecycleGatesTest` | 5 | Letter template, document type, catalogue service, grievance category, badge, asset category, rating scale (appraisal eligibility) and course (learning assignment) limited to MK: used for MK people only |
| `ApplicabilityScreensTest` | 5 | List column and both filters; *Applies to…* with review and required confirmation; the edit step; "Applies to" while creating (nothing written for All entities); *Company values…* from the policy list; side-drawer screens |
| `Cloning/PolicyCloningTest` (phase 1) | 19 | Still green with the engine extended |

Applicability tests: 39, all passing, 841 assertions. `ApplicabilitySecurityTest` also covers an unsaved record (an import preview), which reaches nobody.

**Full regression (2026-11-23):** `php artisan test --parallel --processes=2` on the shared working tree, which also held two other sessions' unfinished import work. Results: 3,389 tests; 3,235 passed; 141 skipped; 6 failed and 7 errors.

Fixed since the run:

- `ArchitectureTest`: the three new models were added to its documented list of models audited explicitly.
- `FormWizardTest`: the Shift wizard gained its "Applies to" step. Quick-edit drawers (those `FormWizard::wants()` keeps on one screen) no longer get the step: their records keep their usual reach, and the row action changes it.
- `ScheduledCommandsSweepTest`: `EmployeeRuleContext::build()` lazy-loaded `person`, which strict mode refuses while the absence-alert command walks employees in chunks. The failure was there before this work (it fails the same way with the committed `PolicyResolver`); fixed anyway.

Not caused by this work:

- `Ux15FoundationTest`: raw font sizes in theme sections that are already committed (the review tag, sign-in, website). It fails at HEAD too.
- `DataImportTest` ×3, `WorkbookImportTest` ×2 and `PopulatedOrganisationSweepTest`: the run caught the import pages mid-edit in another session; they pass in its later runs.
- `EveryPageOpensTest`: the local route cache (`bootstrap/cache/routes-v7.php`) predates the new import page.
- `ApiSweepTest`: the tenant context stays on the other tenant after the sweep's last request. No route or HTTP code changed. It is to be confirmed on a clean worktree at HEAD.

**Security results:** `ApplicabilitySecurityTest` (5) and the permission checks in `ApplicabilityScreensTest` all pass. Tenant isolation, company-limited people, the share and override permissions, and the edit guard are all covered.

**Migration results:** `RegistryAndMigrationTest` passes: down and up keep existing rows byte-identical and the audit chain valid.

**Unresolved:** the `ApiSweepTest` cause above (believed to come from earlier work, still to be confirmed).

---

## 5. Not done, and why

| Item | Reason | Path |
|---|---|---|
| Field overrides on salary components and structures | Structures are already company-specific through `salary_structure_versions.company_id`, behind a two-person approval and the compensation fingerprint that finalise checks. A per-company formula override would bypass both. | Use a company-specific structure version (existing), or clone the component |
| Cloning salary structures, workflows, appraisal templates, courses, learning paths and surveys | Versioned definitions with their own publish and approval flows; a generic copy would skip them | A dedicated adapter per type on the same engine, as policies have |
| Custom fields and option lists per company | A custom field belongs to an entity type and renders in every form of that entity, often before the record's company is known (a new hire's first step). Option lists feed selects across the product by list key. Per-company visibility needs the form renderers to know the company first. | Register them once the hire and profile forms pass the company to `CustomFieldsSchema` |
| Tenant settings (`payroll.*`, pay cycle, `expense.*`) per company | Decision OD-9 (accepted): tenant-wide settings | Unchanged |
| Runtime gate for survey and campaign audiences | Audiences target people directly through their own criteria (`company_ids` among them), so an audience is already company-bound by its definition. | Unchanged |
| Records that predate the framework (no explicit applicability) are not checked on positions | Checking them would refuse placements that work today. History before the framework is not judged retroactively. | Choose their companies explicitly (*Applies to…*); from then on they are enforced |
| Pre-existing: config lists not company-scoped (OD-2); cost and profit centres' access-scope dimension; null-company units hidden from company-scoped users; role-owned tasks ignore company grants (OD-3) | Outside this change; recorded in §1.4 | Separate fix |

## 5a. Fields that must agree, and changing them together (remediation I.6, 2026-11-25)

- A provider may implement `ValidatesCompanyTimeline` (a designation's level and grade do): `ConfigurationOverrides`
  takes a snapshot before a change of one of its `timelineFields()`, and after the write, in the same transaction,
  checks the stored values on every date from the change on; a refusal rolls the change back. Only what the change
  makes wrong is refused.
- `ConfigurationOverrides::together(fn () => ...)` groups changes of a company's values: one transaction, and the check
  runs once after the last change (no refusal of a valid change made in two steps). "Company values…" offers the
  provider's fields together ("Level and grade together").

## 6. Additions from master relationships R3 (2026-11-24)

- **Designation company values.** The designation type declares `overrides` => `DesignationOverrideFields`
  (`app/Domain/Organisation/Overrides`):
  - its fields are level, grade and job family (selects);
  - the shared values are the designation's own columns.

  `DesignationDefaults` reads the effective value for a company, which hire, transfer and seat prefill use.
- **Company-aware validation.** A provider may also implement `CompanyAwareOverrideFields::validateForCompany()`.
  `ConfigurationOverrides::set()` calls it after `validate()`, so a company's own value must suit that company (its
  grade must be one it may use).
- **Dependencies see company values** (`ApplicabilityDependencies::check`):
  - **Forward:** a company with its own value of a link is checked against that value, not the shared one.
  - **Reverse:** narrowing a record's companies is refused while a company's own value of another record uses it
    there. A company whose own values replace every link to the record no longer counts as using it.
  - **Establishment guard:** a location with an establishment cannot be shared beyond that establishment's
    company. Otherwise people in another company would be stamped with that establishment.
- **Cloning folds company values.** `GenericCloneAdapter::materialise()` copies the destination company's own
  values of the source's columns into the copy, so the copy keeps what that company used. This covers every type
  with attribute overrides (designation, shift). The source and its overrides are unchanged.

## 7. "Applies to" is the only company choice; what a record uses comes along (2026-11-26)

The owner reported two problems on a department's "Applies to…": the change was refused because its parent department
("Marketing") did not apply to the joining company, and the department form asked for a Company on its Details step
although "Applies to" asks again which companies use it.

**One place to choose companies.** The eight company-owned masters (location, business unit, division, department, team,
cost centre, profit centre, letter signatory: the types with an `owner` column) no longer show a Company field, on create
or edit, nor a Company list column (the central "Applies to" column and Entity filter remain). Other forms with a
company field (bank accounts, statutory profiles, budgets, cycles, …) are company records by nature, have no "Applies
to" step, and are unchanged.

- Create: `ApplicabilityUi::ownerField()` adds a hidden owner field, dehydrated on create only, whose value is
  `EntityApplicability::ownerFor()`: what the record sits in decides first (registry `owner_from`: a location's
  establishment, then its parent branch), then "Current entity only" → that company, "Selected entities" → the first
  (lowest id) of those ticked, "All entities" → none (every company), unless the type has `owner_required` (locations:
  the column is NOT NULL) → the first company. "Current entity only" now always shows its company picker and requires it.
- Edit: the owner is never edited in the form. When "Applies to…" removes the record's own company, the plan's
  `ownerAfter` moves it (the one company chosen, or the first of several) in the same transaction, through the model
  (audited, `SharedConfigurationGuard::suspend`). The old blocker "belongs to X, so it always applies there" now remains
  only when an `owner_from` link fixes the company ("takes its company from its establishment …"). Usages and records
  that point at it in the company it leaves still block, as before.
- Location form: establishments and parent branches are listed with their company when the organisation has several;
  picking one on create sets "Applies to" to that company; editing lists only the location's own company's.

**Bringing dependencies along.** `ApplicabilitySelection::$withDependencies` (state key `with_dependencies`). With it,
a forward gap found by `ApplicabilityDependencies::check()` (a record it uses that a joining company cannot use) is not
a blocker: `plan()` plans that record too (its reach plus the missing companies; "All entities" stays all), recursively
and once per record, and `apply()` writes the extensions first, in the same transaction, each with its own audit event
("Brought along with department "Content & Creative (CONTENT)": …"). A nested plan's blockers block the whole change.
Imports and other callers keep the earlier behaviour (flag off).

- "Applies to…" review: the box "Also apply what it uses …" (ticked by default, shown only when something could come
  along); the banner lists each record brought along. Unticked, the forward blocker returns.
- Create: the "Applies to" step shows what will come along, and checks the whole choice before the record is created
  (`ApplicabilityUi::createPlan()` on an unsaved probe with the form's links); creating then brings them along. A choice
  that keeps the record's usual reach ("All entities" with later companies for a tenant-wide record, its own company for
  a company's record) writes nothing and is not checked, as before; a location always needs a company.

Tests: `tests/Feature/Configuration/Applicability/AppliesToOnlyTest.php` (7); `SharedConfigurationTest` "leaves out the
owning company" now asserts the move.

## 8. Back-dated changes to where a record applies (2026-11-26)

Owner, on the "Applies to…" screen: "can we apply it back-dated … by default today's date but the calendar can pick a
previous date … don't keep it open for any back-dated days, it must meet the compliances". Until then a change could only
start today or later. Decided by HCM practice for effective-dated configuration (a change may correct the payroll months
still open, never a period already paid or closed):

- **How far back** (`EntityApplicability::earliestStart()`), per company the change touches (joining or leaving; the
  companies that keep it are not affected): at most the **start of the previous payroll month** of that company, read from
  its own pay cycle (`AttendanceCycle`: calendar months, or e.g. the 25th to the 24th). If that month's payroll is
  finalized or paid, or its attendance is closed, the change starts in the current month at the earliest; if the current
  month is already finalized or closed, it cannot be back-dated at all. The latest of these across the companies wins.
- **History stays in order**: never before the last change already made to where the record applies (or one already
  planned), so the history rows never overlap. Records brought along with it are planned with the same date and the same
  rules.
- **Compliance**: a back-dated change needs a reason (refused by the service without one, not only by the screen) and an
  explicit tick on Review; its audit event carries the chosen effective date and `backdated` / `earliest` in its metadata.
- **What it does**: days from the chosen date are worked out with the new companies when attendance and payroll for the
  month are next run; results already worked out keep them until then. Finalized payroll, payslips, statutory returns and
  closed attendance never change. Position checks (`assertUsableByCompany`) still use the later of the start date and today.
- **Screen**: the date is today by default; the calendar opens earlier days down to the earliest allowed for the companies
  chosen, and its line says how far back and why ("… at the earliest: payroll for Oct 2026 is finalized for FynnEdge
  Advisory"). Review shows a "Back-dated to …" box with what the days in between become.
- **Company values** (owner, same day: "do the same for company values too"): `ConfigurationOverrides::set()` / `end()`
  follow the same window for the one company (`BackdatingWindow`, shared with "Applies to…"), never start before the last
  change of that field for that company (so a value that has applied is never marked as replaced before it applied), and
  need a reason when back-dated; `earliestStart($record, $company, $paths)` gives the calendar's first day. The "Company
  values…" screen has the same calendar line, tick, required reason and "Back-dated to …" box. Imports and the
  requirements pre-flight pass today, as before.
- **Not changed**: creating a record applies from today.

Tests: `tests/Feature/Configuration/Applicability/BackdatedApplicabilityTest.php` (window, finalized payroll only for the
companies touched, closed attendance, history order, reason and audit, the screen) and `BackdatedCompanyValuesTest.php`
(window per company, finalized payroll, each field's history, ending back-dated, reason and audit, the screen);
`SharedConfigurationTest` and `OverridesTest` now expect the window's refusal instead of "cannot start in the past".
