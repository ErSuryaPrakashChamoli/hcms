# Configuration cloning (phase 1: engine + policies)

> **2026-11-23:** the engine now also serves every type registered in `config('peopleos.configuration_types')` with a `clone` key, through `Adapters\GenericCloneAdapter` and the new `ApplicabilityKind::EntityApplicability` (a copy applies to its destination only). Shared configuration and per-company overrides sit next to it. See `docs/architecture/configuration-applicability.md`.

Built 2026-11-21 from the accepted design "Configuration Cloning & Reusability Framework: Audit, PRD Addendum & Technical Design" (owner accepted it and all ten open decisions, OD-1 to OD-10, on 2026-11-21; phase 1 authorised the same day). Phases 2a–2e (other modules) and 3 (template library) are not built.

**What it is:** a shared platform capability, not a module. A configuration type gets a **Clone** action in its own screens. One engine behind that action handles scope, permissions, applicability, dependencies, conflicts, the transaction, idempotency, lineage and audit. A clone is always an **independent, inactive copy** under a new code, limited to the companies chosen. Changes to the source never reach the copy, and nothing is ever merged into an existing record.

## Why a copy is "new code + bounded applicability"

Almost all configuration is tenant-wide: there is no `company_id` on policies. Companies are targeted through applicability rule conditions (`company_id` among `config('peopleos.rules.fields')`). So "clone to FynnEdge" means a new record with its own code whose rules are rewritten to apply to FynnEdge only. Shared masters (leave types, shifts, templates, roles, …) are reused, not copied.

If two companies truly share the same rules, the better answer is one record whose applicability covers both (a governed edit of the source). The wizard says so on its first step.

## Engine (`app/Domain/Configuration/Cloning`)

| Piece | Job |
|---|---|
| `ConfigurationCloner` | `plan()` is read-only. `execute()` does the following in order: idempotency check, then re-plan and refuse on a changed source, a blocker or an unacknowledged warning. It then runs one `AuditRecorder::operation()` around one `DB::transaction()`, with the transaction inside so a failure's audit summary survives the rollback. Inside it re-reads the source under lock and compares its checksum, materialises each copy through its adapter, writes lineage and records a CLONE event. |
| `CloneRegistry` | `config('peopleos.cloning.adapters')`, key ⇒ adapter class. Registering an adapter is all a new type needs. |
| `CloneAdapter` | Per type: snapshot, references, impact, own conflicts, code check, look-alikes, `materialise()` (INACTIVE, through the module's own service), and the transactional tables it must never write (`excluded()`). |
| `Snapshot` | Deterministic JSON of identity, content and applicability. Its SHA-256 is the version stamp. |
| `ApplicabilityRewriter` | Rewrites and proves the **boundedness invariant**. Every copied rule is `all of` and starts with `company_id is any of [destination]`. Shared values (designation, grade, level, employment type, category, work mode, gender, tenure, lifecycle state) are kept exactly. Company-owned units are mapped to the destination's own. A rule naming employees is not copied. An empty rule (everyone) gets the company condition. `any of` rules with several conditions are refused. Unknown fields are refused. Also handles a `company_id` column and a list of company ids, for later waves. |
| `OrgUnitMapper` | Which company a location, business unit, division, department or team belongs to (null = shared), and the same-named unit in the destination. |
| `CloneScope` | The tenant feature `configuration.cloning` must be on. The person needs `configuration.clone`, plus `configuration.clone.cross_company` for another company or several. Destinations are active companies inside the person's company scope. For **each** destination the person needs `configuration.clone` and the type's create permission. This is the first caller of `User::hasPermission($key, $companyId)`, so a role limited to one company only clones into it. A scoped person may clone a source that applies to every company, or only to their companies (OD-4). |
| `ConflictDetector` | Blockers: missing name, invalid code, code in use (compared case-insensitively, as MySQL does), code repeated across destinations. Warnings: look-alikes and references with problems. Then the adapter's own checks. |
| `CloneLineage` | Created from / copies, read from `configuration_lineage`. |

**Tables** (migration `2027_02_05_200001`, both `BelongsToTenant`, append-only, classified in `DataInventory` under configuration):

- `configuration_clone_operations` holds one row per attempt: completed, failed or **refused**. Each row has the source snapshot and checksum, the destinations, the full plan, the result, the error, the audit operation id and the idempotency key. The key is unique per tenant and is released on failure or refusal, so the same wizard can retry. Only the outcome may be written after a row starts.
- `configuration_lineage` has one row per copy (root) and per copied rule, reused record and mapped unit (dependency).

**Audit:** every row a clone creates has its own CREATE event carrying the operation id, with the reason "Cloned from <source>: <reason>". One CLONE event, on the source, holds the manifest. The operation's BULK_OPERATION summary closes it, and is still written when the clone fails. A refused attempt has its own CLONE event (status `refused`).

**Limits** (`config('peopleos.cloning.limits')`): 10 destinations. The impact count looks at up to 2000 people per destination company.

## Policies (all 21 types): `Adapters\PolicyCloneAdapter`

- **Settings:** by default the version in force today is copied, else the draft, else the newest. The person may pick another version (OD-5). The copy gets these settings as its draft v1.
- **Rules:** the source's active, not-ended rules are rewritten per destination and saved **inactive**. Inactive rules are required: `PolicyResolver` takes active rules of active policies without asking for a published version, so an active rule on a draft-only policy would resolve to nothing.
- **Activation:** `Policies::publish()` turns a copy's rules on as part of its first publish. "Awaiting activation" means the policy has clone lineage and has never been published (`Policies::awaitsCloneActivation()`). While a copy waits, the policy wizard (`PolicySetup`) treats its inactive rules as its own and keeps any rule it adds inactive.
- **References:** settings backed by records are reused. Each settings source is classified in `PolicyCloneAdapter::OPTION_SOURCES` (kind, model, stored key) or `STATIC_SOURCES`. A test fails on any unclassified `options_from` or rule field.
- **Impact and conflicts:** the engine counts who in each destination company the copy matches today, and how many it would win against the policy that holds them now. It warns when the copy matches nobody, applies to nobody, or would be outranked; each warning names the holder and its priority.
- **Duplicate became Clone** (OD-10). `Policies::duplicate()` is gone. HR Admin and Expense Admin get `configuration.clone` in their role templates as its successor. `configuration.clone.cross_company` is granted deliberately; only Tenant Super Admin (`*`) has it by default.

## Screens

- **Clone** (`App\Filament\Support\Cloning\CloneAction::make('policy')`) appears on the policy list rows and the policy page header. It is a wizard with these steps:
  - **Source:** what is copied, whom it applies to today, the version choice, and a hint to extend applicability instead.
  - **Companies:** a repeater of company, name, code (live unique check) and priority. One row without cross-company permission. The proposed code is `<SOURCE>_<COMPANY CODE>`, made unique (OD-7).
  - **Dependencies:** shown only when the source points at something.
  - **Applicability:** a mapping choice for each unit of another company, with a suggestion or a required choice, then the rules after the clone and who they reach today.
  - **Review:** blockers, warnings and an acknowledgement, then the reason.

  The list stays put, and the notice has **Open** for each copy (the policy wizard, where it is finished and published).
- **Policy page:** the subheading says *Copied from … on … by …* and lists the source's copies. **Compare with source** shows three read-only columns: the source when copied, the source now and this copy now, with differences highlighted and nothing applied.
- **Clone history** (`ConfigurationCloneOperationResource`) sits in Setup → Configuration next to the Change Centre and in the Setup finder links. It has filters, a manifest view and a CSV export guarded by `SpreadsheetCell`. People limited to some companies see only their own clones and clones into their companies.

## Known limits (by design or decided)

- Destinations are companies only. Legal entity and establishment are not rule fields (OD-1).
- Tenant-wide settings (pay cycle, `payroll.*`, `expense.*`, …) cannot differ by company, so they cannot be cloned per company (OD-9).
- Configuration lists are still not filtered by company scope, and role-owned tasks still ignore company-limited role grants. The engine enforces scope itself (OD-2, OD-3).
- "Clone too" for referenced templates (letter, feedback, checklist) arrives with their adapters in wave 2a. In phase 1 every policy reference is reused.
- `configuration.templates.manage` (the template library, mode 5) is added with phase 3, not now, so the role editor shows no dead permission.
- Cloning is synchronous: no queue and no API in phase 1.

## Tests

`tests/Feature/Configuration/Cloning/PolicyCloningTest.php` covers the framework criteria AC-F1 to AC-F13:

- source byte-identical, and resolution unchanged until activation;
- a property test that every rewritten rule is bounded, over random condition sets;
- several companies at once;
- dependencies and the classification guard;
- no transactional rows written;
- conflicts, including a code taken mid-write;
- fault injection on destination 2 of 3;
- idempotency;
- tenant isolation;
- the permission and scope matrix, including the feature switch;
- audit and chain verification;
- lineage without propagation, and Compare;
- the wizard from the policy list and Clone history.

`HroneParity/GoalSheetPolicyTest` covers Clone in place of Duplicate.
