# PeopleOS security invariants

These hold for every change. The architecture tests in `tests/Feature/Architecture/ArchitectureTest.php`
enforce the mechanical ones on every CI run; the rest are reviewed.

## Tenancy

1. Every domain model uses `BelongsToTenant` except the six platform-level models: `Tenant`, `User`
   (looked up before a tenant is known; listings use `forCurrentTenant()`), `Permission` (platform
   catalogue), `AuditEvent` and `AuditEventChange` (nullable tenant for platform events; reads scoped
   explicitly; writes only through `AuditRecorder`), `ComplianceRule` (platform-owned statutory rules).
2. With no tenant bound, tenant-owned queries return nothing (`TenantScope` adds `1 = 0`); creating a
   tenant-owned record without a bound tenant throws; moving a record between tenants throws.
3. `TenantContext::bypass()` / `withoutTenancy()` appear only in the allow-listed platform services
   (audit recorder and verifier, access scopes, API key resolution, tenant provisioning, SSO login,
   the queue tenant middleware). Adding a new site requires editing the allow-list in the test.
4. Tenant resolution runs before route-model binding (`ResolveTenant` and `AuthenticateApiKey` are
   prepended to the middleware priority list ahead of `SubstituteBindings`). Controllers that look
   records up by id do so after the tenant is bound.
5. Queued jobs that touch tenant-owned data carry `public ?int $tenantId` and return
   `[new BindTenantContext]` from `middleware()`; the worker re-binds the tenant before `handle()`.

## Organisational access scope (ABAC)

6. `user_access_scopes` rows restrict a user to companies / locations / business units / divisions /
   departments / teams. No rows = tenant-wide (subject to permissions). Rows within one dimension are
   OR-ed; dimensions are AND-ed. A user always reaches their own employee record.
7. The scope is enforced at the query layer by the `AccessScope` global scope on `Employee`, on every
   employee-linked model (`ScopedByEmployee`) and on organisation units (`ScopedByOrganisation`), so
   Filament tables, relation managers, global and table search, report datasets, CSV exports,
   assistants and services all see the same restricted set. Record-level policies (`PermissionPolicy`,
   `TicketPolicy`, `EmployeeDocumentPolicy`) check `AccessScopes::allows()` as defence in depth and
   refuse any record whose `tenant_id` is not the bound tenant.
8. System contexts (console commands, queue workers, API keys) have no authenticated user and are not
   user-scoped; they remain tenant-bound. API keys are tenant-wide integration credentials; per-key
   organisational scope is a deferred item.
9. Hidden navigation is never an authorisation boundary; every resource model has a registered
   policy (architecture test) and every state change goes through a domain service.

## Files

10. Employee documents and ticket attachments live on the private documents disk and are served only
    through temporary signed routes that re-authorise the record after the tenant is bound and
    write a `DOWNLOAD` audit event. `Storage::url()` is never used in application code.

## Audit

11. Every `Auditable` model writes field-level before/after changes with actor, roles, tenant, IP,
    user agent, source, request id and reason. Sensitive attributes are masked.
12. Audit rows are append-only: model events and the Eloquent builder both refuse update, delete and
    force-delete; the per-tenant hash chain is verified by `peopleos:audit:verify`.
13. Bulk operations run inside `AuditRecorder::operation()`, which stamps every event with one
    `operation_id` and writes a `BULK_OPERATION` summary (entity count, success and failure counts,
    affected ids, error).
14. Sensitive views (bank, statutory, payslips, grievances, documents, AI answers) are audited with a
    purpose.

## Statutory data

15. Compliance rules carry `verification_status`; packs load as `illustrative`. With
    `peopleos.compliance.enforce_verified_rules` on (default in production) payroll cannot be
    finalized on illustrative rules. Nothing in the repository claims legal compliance.
