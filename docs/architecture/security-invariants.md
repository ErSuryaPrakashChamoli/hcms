# PeopleOS security and architecture invariants

These hold for every change (Phase 0.2, extended in Phase 0.3 by the architecture contract). The architecture tests in `tests/Feature/Architecture/ArchitectureTest.php`
enforce the mechanical ones on every CI run; the rest are reviewed.

## Tenancy

1. Every domain model uses `BelongsToTenant` except the six platform-level models: `Tenant`, `User`
   (looked up before a tenant is known; listings use `forCurrentTenant()`), `Permission` (platform
   catalogue), `AuditEvent` and `AuditEventChange` (nullable tenant for platform events; reads scoped
   explicitly; writes only through `AuditRecorder`), `ComplianceRule` (platform-owned statutory rules) and `ComplianceRuleVerification` (its append-only verification history, Phase 5; audited through `AuditRecorder` platform events).
2. With no tenant bound, tenant-owned queries return nothing (`TenantScope` adds `1 = 0`); creating a
   tenant-owned record without a bound tenant throws; moving a record between tenants throws.
3. `TenantContext::bypass()` / `withoutTenancy()` appear only in the allow-listed platform services
   (audit recorder and verifier, access scopes, API key resolution, tenant provisioning, SSO login,
   the queue tenant middleware). Adding a new site requires editing the allow-list in the test.
4. Tenant resolution runs before route-model binding (`ResolveTenant` and `AuthenticateApiKey` are
   prepended to the middleware priority list ahead of `SubstituteBindings`). Controllers that look
   records up by id do so after the tenant is bound.
5. Every queued job under `app/` implements `TenantAwareJob` (`tenantId()`) and returns
   `[new BindTenantContext]` from `middleware()`; the worker re-binds the tenant before `handle()` and
   restores the previous context afterwards (architecture test).
5a. Scheduled commands run with `withoutOverlapping()->onOneServer()` and iterate tenants explicitly
   with `runAs`; they never query tenant-owned models before binding a tenant.
5b. Tenancy violations (`MissingTenantException`, `TenantMismatchException`) render as a plain 403
   without tenant details.

## Organisational access scope (ABAC)

6. `user_access_scopes` rows restrict a user to companies / locations / business units / divisions /
   departments / teams. No rows = tenant-wide (subject to permissions). Rows within one dimension are
   OR-ed; dimensions are AND-ed. A user always reaches their own employee record and (relationship
   scope, ADR-0004) every employee who reports to them today through any reporting type.
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

## Historical integrity and versioning

16. Historical business facts are never overwritten: positions, reporting, salary, organisation units,
    schedules, policy versions, rules, custom fields and addresses are effective-dated; changes create
    new rows. Future-dated rows cannot rewrite the past.
17. Published policy, workflow and form versions are immutable; a running workflow instance stays on
    the version it started from; submissions reference their form version.
18. One Person has exactly one Employee per tenant (database unique constraint); re-employment
    re-activates that Employee rather than creating another.

## Integration and AI

19. PeopleOS carries no RecruitmentEdge / RMS namespace, model, migration, database connection or
    package (architecture test). External systems are represented by external references and mappings;
    PeopleOS owns its primary keys.
20. No integration path bypasses PeopleOS authorisation: API keys carry scopes and bind the tenant
    before any lookup; inbound events (future) are idempotent and audited; outbound webhooks are signed.
21. AI assistants run as the user through the same tenant, permission, scope and classification layers,
    receive redacted facts only, never write, and are logged.

## Data classification

22. `config('peopleos.data_classification')` lists the highly sensitive, financial, statutory and
    confidential classes; every highly-sensitive attribute is masked in audit, excluded or encrypted
    (architecture test). Tenant administrators are not implicitly entitled to sensitive fields.
