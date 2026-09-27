# PeopleOS Architecture Contract

- **Status:** Frozen in Phase 0.3 (27 September 2026). Every later phase refers to this document instead of redefining these concepts.
- **Companion documents:** `decision-register.md` (ADR-0002…ADR-0015), `ADR-0001-legal-entity-establishment.md`, `security-invariants.md`, `integration-contract.md`, per-phase notes `phase-*.md`, product blueprint `docs/peopleos-blueprint.md`.
- **Source of truth for "current implementation":** the repository at the Phase 0.3 commit. Where the contract says *target* something is not built yet; where it says *current* it is.

Each section records: Decision · Rationale · Current implementation · Target contract · Allowed exceptions · Deferred implementation · Affected domains · Migration considerations.

---

## 0. Discovery matrix

| Concept | Current implementation | Canonical concept | Status | Decision |
|---|---|---|---|---|
| Tenant | `tenants` (+ settings, features; tier/region metadata), `TenantContext`, `TenantScope`, `BelongsToTenant` | Tenant | Complete | Keep (§1) |
| Company | `companies` (legal_name, country, currency, effective dates) | Company | Complete as management unit | Keep; also stands in for Legal Entity and Establishment (§2, ADR-0001) |
| Legal Entity | collapsed into Company (`legal_name`, PAN/TAN on `company_statutory_profiles`) | Legal Entity | Not modelled | Target defined, deferred (ADR-0001) |
| Establishment | collapsed into `company_statutory_profiles` (one per company: PT/LWF state, PF/ESI codes) | Establishment | Not modelled | Target defined, deferred (ADR-0001) |
| Location | `locations` (company_id, city, state_code, country_code, effective dates) | Location (physical site) | Complete | Keep; will attach to Establishment later |
| Person | `people` + addresses, family, emergency contacts, qualifications, experiences, certifications, skills | Person | Complete | Keep (§3) |
| Employee | `employees` (one per person per tenant; lifecycle_state, code, source, external_reference, joining/probation/confirmation/exit dates) | Employee | Complete | Keep; 1:1 with Person per tenant (ADR-0002) |
| Employment | `employee_positions` (12 effective-dated dimensions, change_type), `reporting_relationships`, `employee_lifecycle_transitions`, `employee_salary_assignments` | Employment (spell + assignments) | Complete as effective-dated history; no separate `employments` table | Employment spell is derived from lifecycle transitions; table deferred (ADR-0002) |
| Organisation | BU, division, department, team, cost centre, profit centre, job family, designation, level, grade, employment type, employee category, work mode, `organisation_nodes` tree | Organisation | Complete | Keep; classified in §4 |
| Reporting | `reporting_relationships` (type ∈ line, functional, dotted, hrbp, mentor, buddy, project, secondary; is_primary; effective dates) | Reporting relationships | Complete | Keep (ADR-0003) |
| Lifecycle | `LifecycleState` (12 states), config-owned transitions, `LifecycleEngine`, transitions table, timeline | Lifecycle | Complete | Keep (§7, ADR-0007) |
| Audit | hash-chained `audit_events` + `audit_event_changes`, operation ids, immutable builders | Audit | Complete | Keep (§9, ADR-0008) |
| Configuration | catalogue in `config/peopleos.php`, `tenant_settings`, `tenant_features`, Change Centre (`configuration_changes`), policies + versions + assignment rules, custom fields, forms + versions, packs/blueprints | Configuration / Policy / Rule | Complete | Keep; status vocabulary mapped in §8 (ADR-0009) |
| Workflow | `workflows`, `workflow_versions`, `workflow_instances` (pinned to `workflow_version_id`), tasks, actions | Workflow | Complete | Keep; version pinning confirmed (ADR-0010) |
| Integration | `api_keys` (scopes, expiry, status), outbound `webhook_endpoints`/`webhook_deliveries` (signed, retried), pre-employee ingress, BGV callback, attendance device punches, `employees.source` + `external_reference` | Integration Hub | Partial | External references, inbound events and mappings defined in `integration-contract.md`; implementation deferred (ADR-0011, ADR-0012) |
| Access control | permission catalogue, roles, policies, `user_access_scopes` + `AccessScope`, sensitive permissions | RBAC + ABAC + field security | RBAC and org/relationship scope complete; field matrix by permission keys only | Contract in §5, §6 (ADR-0004, ADR-0005) |
| Queue | database driver, `SendWebhook` with `BindTenantContext`, `TenantAwareJob` interface | Tenant-aware queue | Complete for existing jobs; enforced by test | ADR-0013 |
| AI | `AiGateway` (feature + permission gate, redaction, logging), assistants on scoped queries | AI boundary | Complete for existing assistants | ADR-0015 |

---

## 1. Tenant

**Decision.** The tenant is the isolation boundary. A tenant may contain many companies (§2). Platform administrators act on a tenant only by explicitly entering it.

**Current.** `TenantContext` (set / runAs / bypass), `TenantScope` (fails closed: no bound tenant → no rows), `BelongsToTenant` (stamps `tenant_id`, refuses cross-tenant writes), `ResolveTenant` (session users → own tenant; platform admins → the tenant entered), `AuthenticateApiKey` (key → tenant). Both run before route-model binding. Six platform-level models are exempt from the tenant trait (`security-invariants.md` §1).

**Target contract.** Unchanged. Dedicated-tenant databases (tier/region metadata) remain a future routing concern; nothing in the domain may assume a single database.

**Allowed exceptions.** `bypass()` only in the allow-listed platform services. **Deferred.** Connection routing per tier. **Affected domains.** All. **Migration.** None.

## 2. Company, Legal Entity, Establishment, Location (ADR-0001)

**Decision.** Four distinct concepts with different natures:

| Concept | Nature | Today | Target |
|---|---|---|---|
| Company | management / brand unit | `companies` | unchanged |
| Legal Entity | legal employer (PAN, TAN, CIN, bank accounts, Form 16 issuer) | = Company (`legal_name`, PAN/TAN on statutory profile) | `legal_entities` 1..n per company |
| Establishment | registered place of work under state/central acts (PF/ESI codes, PT/LWF state, S&E registration) | = `company_statutory_profiles` (one per company) | `establishments` 1..n per legal entity |
| Location | physical site | `locations` (company_id, state_code) | attaches to an establishment |

**Rationale, migration and deferral** are in ADR-0001. A tenant with several companies works today; a company operating in several states must be modelled as one company per establishment until ADR-0001 is implemented. Payroll runs, bank files and statutory profiles are per company today and become per legal entity / establishment later without a destructive change.

## 3. Person, Employee, Employment (ADR-0002)

**Decision.** One Person = one lifetime record per tenant, realised as:

```
Person (people)                       identity: names, dob, gender, personal contacts
 ├─ addresses, family, emergency contacts, qualifications, experiences, certifications, skills
 └─ Employee (employees)  — exactly one per person per tenant (unique tenant_id + person_id)
      ├─ lifecycle_state + employee_lifecycle_transitions  (immutable events)
      ├─ employee_positions            (effective-dated organisation assignment: company, location, BU,
      │                                  division, department, team, designation, level, grade,
      │                                  employment type, employee category, work mode, cost centre)
      ├─ reporting_relationships       (effective-dated, typed)
      ├─ employee_salary_assignments   (effective-dated)
      ├─ statutory detail, bank accounts, documents, BGV, onboarding, attendance, leave, payroll,
      │  performance, learning, assets, tickets, exit, letters, alumni profile
      └─ user_id (login; nullable)
```

**What lives where.**

| Fact | Owner |
|---|---|
| names, date of birth, gender, personal email/phone, addresses, family, qualifications, skills | Person (current-state, with effective-dated addresses) |
| employee code, lifecycle state, joining / probation end / confirmation / exit dates, work email, source, external reference | Employee (current-state; every change is an audited transition or timeline event) |
| company, legal entity*, establishment*, location, department, designation, grade, level, employment type, employee category, work mode, cost centre | Employment assignment = `employee_positions` (effective-dated; *legal entity/establishment arrive with ADR-0001, derived from location by default) |
| manager, functional manager, HRBP, mentor, buddy, project manager | `reporting_relationships` (effective-dated, typed) |
| salary | `employee_salary_assignments` (effective-dated) |
| employment status | `lifecycle_state` (current) + transitions (history) |

**Employment spell.** An employment spell (join → exit) is *derived* from lifecycle transitions; the current model holds one Employee per person and re-employs the same Employee (`alumni → active`, with a new position row and a new transition). A separate `employments` table is not introduced now; if a tenant needs distinct contracts per spell (different legal entity, different employee code) it is added additively with `employee_positions.employment_id`.

**Lifetime events.** joining = hire action (Person reused when `person.id` is supplied); transfer / promotion / department / location / company change = new `employee_positions` row with `change_type`; manager change = new `reporting_relationships` row; status change = lifecycle transition; exit = exit case → `exited`; alumni = `alumni` state with Alumni role; rehire / future re-employment = `alumni → active` transition on the same Employee (UI flow deferred, contract fixed).

**Allowed exceptions.** None: no module may create a second `employees` row for the same person in a tenant. **Deferred.** `employments` table; rehire UI; duplicate-person detection for integrations (`integration-contract.md`).

## 4. Organisation model (ADR-0003)

| Unit | Class | Relation |
|---|---|---|
| Company | management / legal (until ADR-0001) | root under tenant |
| Legal Entity, Establishment | legal / statutory | target, ADR-0001 |
| Location | physical | company_id (→ establishment later) |
| Business Unit, Division, Department, Team | reporting / operational | company_id; `organisation_nodes` tree for the designer |
| Cost Centre, Profit Centre | financial | company_id; referenced by positions (cost centre) |
| Job Family, Designation, Level, Grade | job architecture (tenant-wide) | referenced by positions |
| Employment Type, Employee Category, Work Mode | employment classification (tenant-wide) | referenced by positions |

Units are effective-dated and never deleted while referenced; renames and moves create audit history. The hierarchy is *not* interchangeable: a location is never a legal identity, a department is never a financial unit.

## 5. Reporting relationships (ADR-0003)

`reporting_relationships(employee_id, manager_id, type, is_primary, effective_from, effective_to, reason)` with types line, functional, dotted, hrbp, mentor, buddy, project, secondary. Exactly one primary line relationship is effective at a time (`currentManager`); matrix and temporary relationships are additional rows with their own dates. History is never overwritten. The org chart follows primary line relationships; functional/dotted relationships render as secondary edges. Approvals resolve "manager" as the primary line manager effective on the request date; `hierarchy_level` walks primary relationships.

## 6. Access control (ADR-0004, ADR-0005)

**Decision.** Authorisation is layered and the layers are not conflated:

```
Authentication → Tenant → Role → Permission (RBAC: what may this role do?)
             → Organisation scope (ABAC: which records?) → Relationship scope (whose records?)
             → Field permission / data classification (which fields?) → Record
```

**Current.** RBAC: permission catalogue (`config/peopleos.php`), roles with wildcard expansion, `PermissionPolicy` family, `Gate::before` for platform admins and known permission keys. ABAC: `user_access_scopes` + `AccessScopes` + `AccessScope` global scope on Employee, 41 employee-linked models and organisation units; record-level `allows()` in policies; other-tenant records always refused. Relationship scope (Phase 0.3): a user reaches their own record and every employee who reports to them today (any reporting type). Field security: `*.sensitive.*` permission keys, encrypted casts, masked audit diffs, dataset `sensitivePermission()`, purpose-recorded reveals.

**Visibility matrix (decided).**

| Actor | Sees |
|---|---|
| Employee | own Person/Employee data, own transactions, published policies and announcements for their audience |
| Manager (any reporting type, effective today) | direct reports' records for the abilities their permissions grant, regardless of organisation scope; indirect reports only through `hierarchy_level`-based permissions or scope |
| HR (HR Admin / Manager / Executive roles) | every employee within their organisation scope; tenant-wide when no scope rows |
| HRBP | the employees who have an `hrbp` relationship to them (relationship scope) plus any organisation scope assigned |
| Business Head | organisation scope = their business unit / division; permissions decide depth (e.g. no payroll) |
| Tenant Administrator | tenant-wide data access by permissions; **not** automatically sensitive fields (needs `*.sensitive.*`), and never another tenant |
| Executive | dashboards and aggregates (`analytics.*`) tenant-wide; record-level only via scope/permissions |
| Platform Super Admin | any tenant, only after explicitly entering it; every action audited with source |

**Field-level security contract.** Sensitive fields are classified in `config/peopleos.php` → `data_classification` (highly sensitive: bank account, PAN, Aadhaar reference, UAN, PF, ESIC, secrets; financial: salary, payroll entries, payslips, settlements; statutory: tax declarations, statutory profiles; confidential: grievances, PIPs, one-on-ones, BGV, documents). For each class the abilities *view, edit, export, search* are separate concerns: view/edit = `{resource}.sensitive.view/update` (or the domain's own permission), export = dataset `sensitivePermission()` plus export audit, search = sensitive fields are never searchable columns. The architecture test asserts every highly-sensitive attribute is masked, excluded or encrypted. A generic per-field matrix UI is deferred; adding a sensitive field means adding it to the classification map and the model's `auditSensitiveAttributes()`.

**API keys.** Data contract fixed, enforcement deferred: `api_keys` gains a nullable `access_scope` JSON (`{dimension: [ids]}`, same semantics as `user_access_scopes`) and the key middleware binds it into `AccessScopes` as a synthetic principal; until then keys are tenant-wide and scoped only by permission-like `scopes`, expiry, status and the 120/min rate limit.

**Allowed exceptions.** System contexts (console, workers) are not user-scoped but always tenant-bound.

## 7. Lifecycle and domain events (ADR-0007)

**States (canonical).** `pre_employee → preboarding → onboarding → joined → probation → confirmed → active → on_leave / suspended / notice_period → exited → alumni`, plus `alumni → active` (re-employment). Transferred and Promoted are *position events* on the timeline, not states. Allowed transitions live in `config('peopleos.lifecycle.transitions')` and are enforced by `LifecycleEngine::transition()` with permission `employee.lifecycle`, an effective date, a reason and guards (e.g. exit requires cleared exit case).

**Every transition produces:** `EmployeeLifecycleChanged` domain event → audit event (state-specific action) → timeline entry → notification rules (`employee.*`) → workflow triggers → downstream actions (onboarding start, login suspension on exit, Alumni role).

**Domain events (canonical names).** `employee.pre_employee`, `employee.preboarding`, `employee.onboarding`, `employee.joined`, `employee.probation`, `employee.confirmed`, `employee.active`, `employee.on_leave`, `employee.suspended`, `employee.notice_period`, `employee.exited`, `employee.alumni`; module events `leave.*`, `attendance.*`, `payroll.*`, `performance.*`, `learning.*`, `asset.*`, `servicedesk.*`, `exit.*`, `configuration.*`, `form.*`, `workflow.*`. Position, manager and salary changes are audited and time-lined today; the named events `employee.transferred`, `employee.promoted`, `employee.manager_changed`, `employee.salary_changed`, `employee.department_changed`, `employee.designation_changed`, `employee.location_changed` are *reserved names* to be emitted by the position/salary actions in Phase 1 (no listener may depend on them before that).

**Sync vs queued.** Audit and timeline are synchronous (same transaction). Notifications, webhooks, letters and integration outbound are asynchronous by contract (today: in-app notifications queued, e-mail synchronous, webhooks scheduled); any new listener that calls an external system must be queued and tenant-aware (§10).

## 8. Configuration, policy, rule, workflow (ADR-0009, ADR-0010)

**Vocabulary.** *Configuration* = tenant/company values and reference data (settings, features, org units, calendars, components). *Policy* = a versioned, effective-dated bundle of settings of one type (leave, attendance, overtime, probation, payroll…) assigned to employees by *rules*. *Rule* = deterministic condition over the employee context (12 position dimensions + lifecycle, gender, tenure), operators equals / not_equals / in / not_in / gte / lte. *Workflow* = versioned graph of nodes executed per subject. AI never replaces a deterministic rule.

**Status lifecycle mapping.** The canonical vocabulary Draft → Pending Approval → Approved → Scheduled → Active → Superseded → Archived maps onto: `configuration_changes.status` (draft, pending_approval, approved, scheduled, published, rejected, rolled_back, discarded) for governed edits; `policy_versions.status` (draft, published, retired) where *published* = Active, an earlier published version becomes *Superseded* the moment a later one is published (retained, never edited), and *retired* = Archived. `workflow_versions` and `form_versions` follow the same draft/published/retired rule.

**Ownership levels.** Tenant: settings, features, roles, permissions, policies, workflows, forms, letters, packs. Company: statutory profile, payroll periods/runs, holiday calendars (assignable by rule), bank details. Legal entity / establishment: target of ADR-0001 (registrations, PT/LWF state). Location: calendars and schedules by rule. Employee category / type: policy assignment rules. No universal configuration table.

**Workflow versioning rule (frozen).** A running `workflow_instance` is pinned to `workflow_version_id` and continues on that version until completion; publishing a new version affects only instances started afterwards. Rollback = publish the previous version again. Execution state: instance status (running, waiting, completed, cancelled, failed), `current_node_id`, `context`, per-node `workflow_actions`. Retry: webhook node via queue (3 tries); other failed nodes are marked failed and must be resumed by an operator (generic retry deferred). Escalation and timers run every five minutes. Idempotency: a node that dispatches external effects records the dispatch before following the edge.

**Approval patterns.** Single, sequential, parallel, majority are implemented; conditional via condition nodes; hierarchy via `hierarchy_level`; amount-based and dynamic approvers are contract names reserved for a later phase. Approver resolution uses primary reporting effective on the request date; delegation is recorded as `DELEGATED` audit and task reassignment (`EscalationEngine::reassign`); timeouts remind, then escalate to manager or role; rejection ends the instance with outcome; resubmission is a new instance.

**Forms.** Create → version → publish → collect → validate → approve → audit; submissions reference `form_version_id`, so later edits never change history.

**Custom fields.** Extend employee, company, department, location, designation and asset records with typed values; never a substitute for salary, attendance, statutory or payroll facts, which stay strongly typed.

## 9. Audit, domain events, change history (ADR-0008)

**Audit event** answers *who changed what*: tenant, actor (id, name, roles snapshot), action, module, entity type/id/label, occurred_at, IP, user agent, source, request id, operation id, reason, approval reference, effective date, metadata, field-level before/after (masked when sensitive). Append-only and immutable (model events + `ImmutableBuilder`), per-tenant hash chain, verifier and export commands, never purged, read by permission (`audit.view`, `audit.sensitive_access`).

**Domain event** answers *what business event occurred* and is a PHP event class (`EmployeeLifecycleChanged`, `LeaveEvent`, `PayrollEvent`, …) consumed by the notification bridge, workflow trigger, onboarding starter and webhook bridge. Domain events are not stored as such; their consequences are (notifications, deliveries, instances) and the originating change is in the audit trail with the same request id.

**Change history** = audit events per entity (Audit history tab) plus effective-dated rows for the facts in §3; the two together make any past state reproducible.

## 10. Queue and scheduler (ADR-0013)

**Invariant.** Every queued job that touches tenant-owned data implements `TenantAwareJob` (`tenantId()`), captures the tenant at dispatch and returns `[new BindTenantContext]` from `middleware()`; the middleware binds the tenant with `runAs` and restores the previous context afterwards. Enforced mechanically by the architecture test for every `ShouldQueue` class under `app/`. Filament's own database-notification job carries no tenant data and is exempt by being outside `app/`.

**Scheduler.** Owned by `routes/console.php`; every entry runs `withoutOverlapping()->onOneServer()`; commands iterate tenants explicitly with `runAs` and must never query tenant-owned models before binding one; failures surface in the scheduler log and in `failed_jobs` (queued parts); audit sources are `console`. Operational requirements: `docs/operations/queue-and-scheduler.md`.

## 11. Integration, external references, webhooks, API (ADR-0011, ADR-0012, ADR-0014)

Frozen in `integration-contract.md`: external references (PeopleOS owns its keys), inbound integration events with idempotency and dead-letter states, organisation/status/compensation mappings, the future recruitment boundary, integration security, the API conventions (`/api/v1`, key auth, tenant by key, JSON errors, pagination, filters, rate limit, versioning) and the outbound webhook envelope.

## 12. AI security boundary (ADR-0015)

`User → authentication → tenant → permission (per assistant) → organisation/relationship scope (queries run as the user) → field security (sensitive facts only with the sensitive permission; identifiers redacted before any model call) → AI context → model`. Assistants never write; answers are logged with sources; inference outputs are labelled; payroll and statutory rules are deterministic and out of the model's reach.

## 13. Documents, notifications, reporting, search

- Documents: private disk → authenticated request → tenant bound → policy → signed temporary URL → `DOWNLOAD` audit. Never public storage or permanent URLs.
- Notifications: event → rule → audience → channel → template → delivery → tracking; audiences resolve within the tenant; templates receive only the variables the rule exposes; sensitive values are not templated.
- Reporting and exports: datasets query through the same scoped models as the UI; sensitive fields need the dataset's sensitive permission; every export is audited. Search (table, global, API, AI retrieval) runs on the same scoped queries.

## 14. Errors, retention, database, migrations, testing

**Errors.** Validation → 422 with field messages; authorisation → 403 (`Forbidden.`); tenant mismatch or missing tenant → 403 without tenant details (rendered centrally); not found / other-tenant id → 404; business rule → `RuntimeException` subclasses rendered as user-facing messages in the UI (notification) and 422 in the API; integration failure → delivery/inbound-event status, never an exception to the caller; queue failure → `failed_jobs`; external service failure → deterministic fallback where one exists (AI) or retry with backoff. Stack traces are never rendered outside `APP_DEBUG`.

**Retention.** Audit events: never purged. Employee records, payroll history, compliance records, documents: retained for the employment plus the statutory period — *to be configured/verified per jurisdiction in the compliance phase*. AI interactions, notification deliveries, report runs: tenant settings (365/180/90 days) purged by `peopleos:retention:purge`. Integration events (future): payload metadata retained, sensitive payload bodies purged after processing plus a short window. Workflow executions: retained with the subject.

**Database.** Every tenant-owned table has `tenant_id` (architecture test); foreign keys everywhere (458 constraints); effective dating where history matters (24 tables); JSON only for flexible metadata (settings, parameters, definitions), never for core relational facts; no soft deletes (status columns + audit instead).

**Migrations.** Additive, backward-safe, tenant-safe, data-preserving, guarded with `Schema::hasColumn` on MySQL, reversible where practical; never drop production data, rename identity tables, rewrite employee history or delete audit history.

**Testing.** Every new domain feature ships with unit/feature tests, authorisation tests, tenant-isolation tests and audit tests; payroll and compliance changes additionally need regression, effective-date and failure/retry tests; the architecture suite runs in CI and may only gain allow-list entries with a documented reason.

## 15. Domain and dependency boundaries

`app/Domain/{Module}/{Models,Services,Actions,Policies,Enums,Events,Listeners,Providers,Adapters}` own business logic; `app/Filament` and `app/Http` are delivery layers that call services/actions and never contain business rules; `app/Support` holds cross-cutting primitives (tenancy, effective dating); infrastructure adapters (`AiProvider`, `BgvProvider`, attendance device adapters, notification `Channel`s, SSO presets) sit behind contracts. Dependency direction: UI → Application/Domain services → Domain models → Infrastructure adapters. Domain code must not import Filament, HTTP or vendor SDK classes. Services keep one responsibility; the large classes recorded in the Phase 0.1 debt register are split when touched, not for aesthetics.

Ownership: Identity (users, roles, permissions, access scopes) · Platform (tenants, settings, features) · Organisation · People · Employment · Lifecycle · Onboarding · Documents · Bgv · Configuration (change centre, policies, rules, custom fields, forms, packs) · Workflow · Notifications · Attendance · Leave · Payroll · Compliance · Performance · Learning · Assets · ServiceDesk · Grievance · Knowledge · Communication · Experience · Exit · Letters · Alumni · Analytics · Ai · Integration + Enterprise (API keys, webhooks, SSO, SCIM, security policy, country packs) · Audit.

## 16. Performance characteristics (accepted)

Scoped users' queries add one `IN (sub-select)` whose body contains two further sub-selects (positions effective today, reports effective today); unscoped users pay nothing. Sub-selects use the existing `(tenant_id, employee_id, effective_from)`-style indexes on positions and reporting relationships. Measured on the test dataset the overhead is not observable; it will be re-measured when a tenant exceeds ~10k employees, at which point the employee-key set can be materialised per request. This is an accepted characteristic, not a defect.

## 17. Answers to the final review questions

1. **Canonical Person** — `people` row per natural person per tenant with satellites (§3).
2. **Canonical Employee** — the single `employees` row for that person in the tenant, carrying lifecycle and employment history (§3).
3. **Employment** — the effective-dated assignment history (`employee_positions`, `reporting_relationships`, `employee_salary_assignments`) plus lifecycle transitions; a spell is join → exit derived from transitions (§3).
4. **Rehire** — `alumni → active` on the same Employee with a new position row and transition; never a second employee master (§3).
5–8. **Company / Legal Entity / Establishment / Location** — §2 and ADR-0001: company = management unit and, until ADR-0001, legal entity and establishment; location = physical site.
9. **Hierarchy** — §4: tenant → company → (legal entity → establishment) → location; company → BU → division → department → team; financial units and job architecture referenced by positions.
10. **Reporting** — typed, effective-dated `reporting_relationships`; one primary line relationship at a time (§5).
11. **Security layers** — §6; combined as tenant scope (query) + permission (gate/policy) + organisation and relationship scope (query + policy) + classification (permission keys, masking, encryption).
12. **Alternate paths** — none known: UI, API, search, exports, files, AI and jobs all go through scoped queries and policies; tests in Tenancy, Identity, Experience, Architecture suites prove it.
13–14. **Lifecycle states and events** — §7.
15–17. **Versioned** — policies, workflows, forms, compliance rules, configuration changes; **effective-dated** — positions, reporting, salary, org units, schedules, policy versions, rules, custom fields, addresses; **immutable** — audit events, lifecycle transitions, leave ledger, payroll entries/payslips, workflow actions (§8, §9).
18–19. **Workflow versions** — instances pinned to their version; new versions affect new instances only (§8).
20–21. **Audit** — every Auditable model mutation, sensitive views, downloads, exports, security events, payroll steps, bulk operations; protected by immutability, hash chain, permissions (§9).
22–24. **External ids, idempotency, mapping** — `integration-contract.md`.
25. **Queue tenant context** — `TenantAwareJob` + `BindTenantContext`, enforced by test (§10).
26. **AI** — §12.
27–28. **RMS** — PeopleOS runs with no RMS code, table or package (architecture test); a later integration uses the recruitment adapter behind the Integration Hub with external references and mappings, no shared database or keys.
