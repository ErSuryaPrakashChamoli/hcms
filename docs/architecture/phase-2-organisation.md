# Phase 2 — Organisation Core

Implements blueprint §121 Phase 2: locations, business units, divisions, departments, teams,
cost centres, profit centres, levels, grades, job families, designations, employment types,
employee categories, work modes, the structural hierarchy and the Organisation Designer.

## Two dimensions, one source of truth each

- **Ownership** (legal): structural masters carry a nullable `company_id` — the legal entity
  that owns the unit for payroll, statutory and branding purposes (§7, §8).
- **Structure** (organisational): the tree lives *only* in `organisation_nodes`. Masters do
  not carry parent pointers, so there is exactly one place that says what sits beneath what.
  Creating a unit through the Designer inherits `company_id` from the nearest company above it.

## organisation_nodes

Each node wraps one master via a morph (`nodeable_type`/`nodeable_id`, unique per tenant) and
holds `parent_id`, `sort_order`, `depth`, a materialised `path` (`/1/7/23/`) and `status`.
`path` and `depth` are derived from `parent_id` and excluded from field-level audit; `parent_id`
itself is audited, so a move shows as `parent_id: 4 → 9` with the reason.

Containment rules are configuration (§9): `config('peopleos.organisation.node_types')` lists
every node type, whether it may be a root, and which types it may contain. Add a type by adding a
model + config entry; no service code changes.

`OrganisationTree` is the only mutator: `attach`, `createUnit`, `move` (cycle-safe, rewrites the
subtree's paths in one transaction), `rename`, `setStatus` (deactivation cascades to the subtree,
reactivation does not), `reorder`, `detach` (leaf only; the master survives), plus `tree($search)`
and `options()` for selectors.

## Masters

All fourteen use `BelongsToTenant` + `Auditable`, codes unique per tenant, `ActiveStatus`, and
`HasEffectiveDates` where history matters (everything except job families, employment types,
employee categories, work modes). Designations link to level, grade, job family, department, a
default reporting level and many employment types (§11).

New tenants receive starting employment types, employee categories, work modes and levels from
`config('peopleos.organisation.defaults')` (a first configuration-pack seed, §76). Tenants edit
or retire them freely; codes are the stable handle.

The masters were produced by a generator kept outside the repo; treat the generated files as
ordinary hand-maintained code from here on.

## Permissions

Two resource groups keep the role grid manageable: `organisation.{view,create,update,delete,design}`
for structural units and the Designer, `people_setup.{view,create,update,delete}` for the
people masters. `OrganisationStructurePolicy` and `PeopleSetupPolicy` cover the fourteen
models plus `OrganisationNode`. System role templates were extended accordingly.

## Organisation Designer (§10)

`App\Filament\Pages\OrganisationDesigner` — Tree and Chart views over the same nested
collection, search that keeps ancestors of matches, and per-node actions (add child, rename,
move, up/down, deactivate/reactivate, remove). Every action has a "Reason for change" and calls
`OrganisationTree`, so the History tab on each unit tells the full story. Access requires
`organisation.design`.

## Not in this phase

- Reporting relationships (manager, dotted line, HRBP, mentor: §9 second half) need employees
  and land in Phase 3 as `reporting_relationships`.
- Heads of units (department head etc.) likewise wait for the employee master.
- Drag-and-drop in the Designer: the move dialog is the deliberate, auditable path for now.

## Tests

`tests/Feature/Organisation/*`, `tests/Feature/Admin/OrganisationPagesRenderTest.php` — 21 tests
covering containment rules, cycle prevention, path rewriting, cascade deactivation, reorder,
detach, search, tenant isolation, provisioning defaults, designations, every resource page and the
Designer actions end to end.
