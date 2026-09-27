# Phase 4 — Configuration Platform

Implements blueprint §121 Phase 4: custom fields, form builder, policy engine, rule engine,
configuration versioning, the Configuration Change Centre with approval, impact preview,
scheduled publishing and rollback, configuration packs and blueprints. Domain:
`App\Domain\Configuration`.

## Custom Field Engine (§41)

- `custom_fields` defines a field per entity (`config('peopleos.custom_fields.entities')`:
  employee, company, department, location, designation) with type, options, required flag,
  audience visibility flags, searchable/reportable flags, sort order, status, effective dates and
  optional extra Laravel validation rules.
- `custom_field_values` stores one row per field per record in a typed column (`value_text`,
  `value_number`, `value_date`, `value_json`) so common queries never parse JSON.
- `HasCustomFields` (on the five entities) gives `customFields()`, `customField($key)` and
  `setCustomFields([...], $reason)`; `Services\CustomFields` validates against the live
  definitions. Partial updates are allowed: only supplied keys are validated and written.
- Filament: `CustomFieldsSchema::formSection()` / `infolistSection()` render the tenant's fields
  into any form or infolist; `SavesCustomFields` persists them from pages. Wired into Employee,
  Company, Department, Location and Designation.

## Form Builder (§42)

`forms` → `form_versions` (draft / published / retired, immutable once published) →
`form_submissions` (pinned to the version they used). `Services\Forms` handles draft creation
(copying the published fields), publish (retires the previous version), submit (validates
against the version's rules), and review (approve / reject). Field types: text, long text,
number, date, dropdown, radio, checkbox, email, employee / department / location selectors.
File and signature fields wait for document storage (§39). `FormFieldsSchema` renders a
published version so HR can fill a form on someone's behalf; the employee portal reuses it later.

## Rule Engine and Policy Engine (§26, §43)

- `RuleEngine::matches($conditions, $context, all|any)` with operators equals, not_equals, in,
  not_in, greater_than, less_than, gte, lte, is_empty, is_not_empty. Pure and side-effect free.
- `EmployeeRuleContext` flattens an employee as of a date: every position dimension, lifecycle
  state, gender, tenure in months. Testable fields are declared in `config('peopleos.rules.fields')`.
- `policies` are typed bundles of settings; `policy_versions` are effective-dated and immutable
  once published (the previous version ends the day before the new one starts). Restoring an
  old version creates a new draft. Policy types and their settings forms live in
  `config('peopleos.policies.types')` (leave, attendance, overtime, working hours, exit, general);
  later modules read settings from the resolved version instead of hard-coding them.
- `policy_assignment_rules`: IF conditions THEN policy, with priority (lower wins) and effective
  dates. `PolicyResolver::resolve($type, $employee, $date)` returns the applicable version;
  `resolveAll()` feeds the "Applicable policies" panel on the Employee 360.

## Configuration Change Centre (§69, §71–§75)

- `configuration_changes` records every governed edit: subject, risk, status, before snapshot,
  payload, impact preview, effective date, requester, reviewer, notes, rollback link.
- Risk per subject class is configuration (`config('peopleos.configuration.risk')`). When the
  tenant enables the `configuration.approval` feature, changes at or above the
  `configuration.approval.minimum_risk` setting wait for approval; otherwise they publish at once
  and still leave a published change row, so history is complete either way.
- Publishing writes through the subject model with the change's reason and `CHANGE-{id}` as the
  audit approval reference. Future-dated approvals become *scheduled* and are published by
  `peopleos:configuration:publish-due` (scheduled daily at 00:05).
- Rollback proposes a new change carrying the before snapshot, links it to the original, and
  marks the original rolled back. Nothing is deleted.
- `ImpactPreview` answers "what will this affect?": employees in the unit for organisation
  dimensions, employees captured by a rule or policy, users holding a role.
- Filament: `GovernedEdit` trait on every configuration edit page (18 pages) routes saves
  through the service and halts with a notification when approval applies. The Change Centre
  resource has Pending / Scheduled / Recently published / Rejected / History tabs, a diff and
  impact view, and approve / reject / publish now / discard / roll back actions.
- Simulation mode (§74) is payroll-specific and waits for Phase 9.

## Packs and blueprints (§76, §77)

`Services\Blueprints` exports configuration only (settings, features, roles, people setup,
skills, custom fields, forms, policies with rules) as `peopleos.blueprint/1` JSON and imports it
additively, matching by code or key, so repeated imports are idempotent. Seven packs ship in
`resources/packs` (startup, it-company, bpo, manufacturing, retail, consulting, enterprise); the
enterprise pack switches approval on. The Packs & Blueprints page and
`peopleos:blueprint:export` / `peopleos:blueprint:import` expose the same service.

## Tests

`tests/Feature/Configuration/*` and `tests/Feature/Admin/ConfigurationPagesRenderTest.php`:
custom field storage, validation and UI; every rule operator; policy resolution, effective dates,
immutability and restore; change routing, approval, scheduling, rejection, rollback, impact,
governed pages; form lifecycle; packs and cross-tenant blueprint round trip.
