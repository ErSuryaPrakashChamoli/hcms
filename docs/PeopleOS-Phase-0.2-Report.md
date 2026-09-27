# PeopleOS Phase 0.2 Report — Baseline Protection & Security Hardening

Performed 27 September 2026 on branch `main`, starting from HEAD 6fb7365 (Phase 0.1 baseline 430e0f7 plus its report). This was a hardening phase: no feature development, no RMS integration, no schema redesign.

## 1. Scope and baseline

| Item | Start of phase | End of phase |
|---|---|---|
| HEAD | 6fb7365 | the commit that adds this report (see `git log --oneline -2`) |
| Working tree | clean | clean |
| Migrations | 68 ran | 70 ran (2 additive) |
| Tests | 284 passed, 2,535 assertions | 315 passed, 0 failed, 2,668 assertions, 409 s |
| Pint | passed | passed |
| Pushes | 0 | 0 |
| RMS dependency | none | none |

Workstreams delivered: A baseline protection, B ABAC / data-access scoping, C secure ticket attachments, D foundational audit hardening, E Legal Entity / Establishment ADR, F queue architecture verification (with one genuine defect fixed), G statutory data safety.

## 2. Findings from discovery (before coding)

1. **ABAC**: the rule engine existed for policy assignment only; nothing restricted an HR user to a company or location. Role assignments already carried an optional `company_id` on `role_user`, unused for data access.
2. **Ticket attachments** were linked through `Storage::disk()->url()` with no authorisation or audit (and, on the private local disk, only Laravel's own signed serve route stood between the path and the file).
3. **Tenant resolution ran after route-model binding** on the signed document download route: `ResolveTenant` was appended after the `web` group, whose `SubstituteBindings` sorts earlier, so an implicitly bound `EmployeeDocument` was looked up before the tenant was bound. Tests masked this because the tenant context was already set in-process.
4. **Queue**: the workflow webhook job serialised its `WorkflowInstance` but wrote its outcome (`WorkflowAction`, a tenant-owned model) inside the worker without a bound tenant. In a real worker this throws `MissingTenantException`; the sync driver in tests hid it. Filament in-app notifications are queued; no worker was running in development (10 pending jobs).
5. **Audit**: model events refused update/delete, but mass `AuditEvent::query()->update()/delete()` bypassed them; no operation id for bulk actions; `AuditRecorder::labelFor` used `getAttribute()` which throws under strict mode for entities without a name-like attribute.
6. **Statutory rules** had no verification marker; nothing stopped an illustrative pack from finalising production payroll.
7. **User form**: the roles select was force-dehydrated, so saving the user edit form with `user.assign_roles` would have hit a mass-assignment error in `GovernedEdit` (latent, found by the new form test).

## 3. Changes

### A. Baseline protection
- `.github/workflows/ci.yml`: Pint then Pest (SQLite) on every push and pull request.
- `tests/Feature/Architecture/ArchitectureTest.php` (6 invariants): every domain model is tenant-scoped except the six documented platform models; every employee-linked model carries the access scope except documented exceptions; every domain model is audited except the documented append-only/derived tables; tenant bypass only in allow-listed files; no `Storage::url()`, no `env()` outside config, no debug output in `app/`; every Filament resource model has a registered policy.
- `docs/architecture/security-invariants.md` records the invariants and allow-lists.

### B. ABAC / access scoping
- New table `user_access_scopes` (tenant, user, dimension ∈ company | location | business_unit | division | department | team, scope_id; unique per user+dimension+id) and model `UserAccessScope` (audited, tenant-scoped).
- `AccessScopes` service (singleton): resolves a user's scope (no rows = tenant-wide), builds the employee-key sub-select (own record OR position effective today matching every scoped dimension), constrains employee, employee-linked and organisation queries, answers `allows(user, model)` for policies (refusing any record whose `tenant_id` is not the bound tenant), and `assign()` with per-row audit.
- `AccessScope` global scope applied through `ScopedByEmployee` (Employee + 41 employee-linked models, null `employee_id` rows stay visible) and `ScopedByOrganisation` (Company, Location, Department, BusinessUnit, Division, Team, CostCentre, ProfitCentre). System contexts (console, workers, API keys) have no authenticated user and are not user-scoped; tenant scoping still applies.
- `PermissionPolicy::view/update/delete/restore`, `TicketPolicy`, `EmployeeDocumentPolicy` check the scope (defence in depth; the query scope already makes out-of-scope ids 404 in Filament and the API).
- User form: "Access scope" section (companies, locations, departments, business units) saved through `SavesAccessScope` with the audit reason; roles select no longer force-dehydrated.

### C. Ticket attachments
- `GET /tickets/{ticket}/attachments/{comment}` (`TicketAttachmentController`): temporary signed URL (15 min) → `auth` → `ResolveTenant` → ticket resolved after the tenant is bound (404 for other tenants) → `Gate::authorize('view', ticket)` → comment must belong to the ticket and have a file → internal notes only for agents/assignee → `DOWNLOAD` audit event → streamed from the private disk. `ServiceDesk::attachmentUrl()` issues the link; the conversation table uses it; the `Storage::url()` helper is removed.
- `DocumentDownloadController` now resolves the document after the tenant is bound, and `bootstrap/app.php` prepends `ResolveTenant` and `AuthenticateApiKey` to the middleware priority list ahead of `SubstituteBindings` so no future route can bind a scoped model unbound.

### D. Audit hardening
- New actions: `DOWNLOAD`, `ARCHIVE`, `ASSIGN`, `STATUS_CHANGE`, `BULK_OPERATION`. Document downloads now record `DOWNLOAD` (was `VIEW` with purpose); the existing document test was updated for this intentional change.
- `audit_events.operation_id` (additive, indexed). `AuditRecorder::operation(module, label, callback, reason, entityType)` stamps every event inside with one id and writes a `BULK_OPERATION` summary (entity count, success/failure counts, affected ids, error) even when the callback throws. The one existing bulk action (mark training attendance) runs inside it. The hash includes `operation_id` only when set, so chains written before this phase still verify (checked on the dev database: 566 events valid).
- `ImmutableBuilder` on `AuditEvent` and `AuditEventChange` refuses mass update, delete, force-delete, increment and decrement; model events already refused instance-level changes.
- `AuditRecorder::labelFor` reads raw attributes (strict-mode safe); `TicketComment` gained an audit label.

### E. Architecture decision
- `docs/architecture/ADR-0001-legal-entity-establishment.md`: current model (Company = legal entity = establishment via `company_statutory_profiles`), why it matters (PT/LWF per state, PF/ESI codes per establishment, filings per TAN/establishment, banking, S&E policies, reporting), target model (Tenant → Company → LegalEntity → Establishment → Location), additive migration and backfill strategy, backward compatibility, and why it is deferred to the compliance/organisation hardening phase after Phase 0.3.

### F. Queue architecture
- Defect fixed: `SendWebhook` captures `tenantId` at dispatch and runs through the new job middleware `BindTenantContext`, which re-binds the tenant in the worker. Pattern documented for all future jobs.
- `docs/operations/queue-and-scheduler.md`: what is queued (Filament database notifications, webhook job), what is synchronous, driver configuration per environment, the **worker requirement** (in-app notifications never appear without `queue:work`), scheduler requirement, failed-job handling, target production topology. Running a worker is an operational requirement, not an application fix; no queue code was changed beyond the tenant defect.

### G. Statutory safety
- `compliance_rules.verification_status` (default `illustrative`) and `verified_at`; packs load as illustrative; `peopleos:compliance:sync` warns; the Compliance Rules resource shows a red "Illustrative / development only" badge; the Payroll Control Room shows a warning subheading.
- `PayrollRuns::finalize()` calls `ComplianceRules::assertProductionSafe()`, which refuses when `peopleos.compliance.enforce_verified_rules` is on (default: production) and any active rule for the jurisdiction is unverified. No rate was changed or invented.

## 4. Tests added

| Suite | Cases | Covers |
|---|---|---|
| Identity/AccessScopeTest | 7 | ABAC cases 1–5 from the brief, self-access, form + audit of scope changes; IDOR (404 on out-of-scope ids), table and global search, dataset/export, employee-linked ticket, policies, system context |
| Tenancy/CrossTenantAccessTest | 4 | API by key (list, direct id, no key), datasets per tenant, signed document link from another tenant, tenant bound before lookup, admin panel id from another tenant |
| Experience/TicketAttachmentTest | 4 | authorised agent and owner + audit, stranger and internal-note refusal, other tenant (404), unsigned / forged / expired links, guessed storage paths, no `Storage::url()` in app |
| Audit/AuditHardeningTest | 4 | operation id + summary + chain verification, failed bulk summary, builder and model immutability, new actions |
| Workflow/TenantAwareJobTest | 3 | tenant re-bound through the sync queue serialiser, webhook job round-trip, job without tenant fails closed |
| Payroll/StatutorySafetyTest | 3 | packs illustrative, finalize refused then allowed once verified, enforcement off outside production |
| Architecture/ArchitectureTest | 6 | invariants listed in §3.A |

Total: 31 new cases. Existing tests changed: `Documents/DocumentsTest` (VIEW → DOWNLOAD, intentional). No test was weakened.

## 5. Verification

- Full suite: 315 passed, 0 failed, 2,668 assertions, 409 s (previous 284 / 2,535). Pint: passed.
- Dev database: two additive migrations applied (70/70), compliance resynced (22 rule versions, all illustrative), audit chains verified (platform 3, demo 563 events).
- Security paths exercised: tenant isolation (ORM, API, export, file, UI), company/location scope, role permission, IDOR, search, export, attachment access, audit immutability, background-job tenant context.

## 6. Deferred items (recorded, not implemented)

1. Legal Entity / Establishment restructuring (ADR-0001) — compliance/organisation hardening phase.
2. Per-API-key organisational scope; API keys remain tenant-wide integration credentials.
3. Field-level permission matrix (which fields a role may see/edit) — beyond the `*.sensitive` keys.
4. Mechanical enforcement that every queued job carries a tenant (review rule today).
5. Redis / Horizon, queued heavy operations, scheduler overlap guards (infrastructure phase).
6. Official statutory verification and filings (compliance phase). Illustrative rules remain illustrative.
7. `ASSIGN` / `STATUS_CHANGE` / `ARCHIVE` are available but only `DOWNLOAD` and `BULK_OPERATION` are wired into flows; asset and position assignments still record `CREATE`/`UPDATE` with metadata.
8. Administrator Guide: the new "Access scope" section on the user form is documented here and in the security invariants, not yet in the guide PDF.
9. Manager visibility across scope boundaries (a scoped manager whose direct report sits outside the scope) follows the scope; revisit when ABAC dimensions are finalised in Phase 0.3.

## 7. Known limitations

- Scope resolution adds two nested sub-selects to scoped users' queries; unscoped users pay nothing. Position lookups use the existing effective-date indexes; no new index was needed on SQLite/MySQL at current volumes.
- Filament table search on relationship columns (`people.first_name`) is not exercisable on SQLite (json_extract quirk); the scope is asserted on the resource and global-search queries instead.
- Guessed storage paths answer 403 in development because Laravel serves the local disk through its own signed route; on object storage the path simply does not exist.

## 8. Git state

Phase 0.2 commit: f355eb6 ("security(peopleos): harden baseline access and audit controls"). Working tree clean. Not pushed.
