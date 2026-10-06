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

## Phase 5 additions

- Statutory output rows (`statutory_return_actions`, `statutory_snapshots`, `statutory_reconciliations`
  and the per-type return runs, entries and revisions) are derived from finalized payroll or
  append-only. They are not individually `Auditable`; every lifecycle step is a
  `STATUTORY_OUTPUT_*` audit event plus a `statutory_return_actions` row. Snapshots, actions,
  reconciliations and revision records refuse updates and deletes; return lines are frozen once the
  return leaves the editable statuses.
- Statutory identifiers (registration numbers, UAN, ESI IP number, PAN on returns) are encrypted,
  hashed with the app key for uniqueness and shown masked; unmasking needs
  `compliance.sensitive.view` and is recorded as `STATUTORY_OUTPUT_ACCESSED`.
- Statutory returns are visible only with `compliance.returns.view` and within the company access
  scope; a reporting line never grants access.

## Phase 14 additions

23. **Livewire requests carry the full tenant chain.** `ResolveTenant` and `EnforceSecurityPolicy` are
    persistent panel middleware, so `/livewire/update` binds the tenant and enforces the IP allow-list and
    idle timeout like a page load (`TenantIsolationHardeningTest`).
24. **User ids from input are resolved inside the tenant.** Form pickers and actions use
    `User::forCurrentTenant()`; talent review participants are validated in the domain; report
    schedule and event-bridge recipients are filtered to the tenant.
25. **Identity checks see the whole tenant, disclose minimally.** `PersonMatcher` and
    `EmployeeCodeGenerator` ignore the caller's organisation scope (never the tenant). A match outside
    the caller's scope is returned without name, code or ids, and the caller cannot override it.
26. **SCIM never leaks another tenant's login.** A userName held anywhere returns 409 with a neutral
    message (create, replace, patch).
27. **Scoped audit reads.** Change Intelligence and the audit list apply the organisation scope
    through employee- and person-linked records; classified values are masked without
    `employee.sensitive.view`.
28. **AI data boundary (ADR-0016).** Prohibited data (passwords, keys, tokens, secrets) never leaves
    PeopleOS and is not stored in the AI log. Restricted data leaves only under the tenant's
    `restricted` policy. Each external call is audited with counts only; the AI log is readable only with
    `ai.admin` (or by its author).
29. **Logs never carry secrets or protected identifiers.** Every channel has the
    `RedactSensitiveLogData` tap (keys and values); slow-query logs never carry bindings; failed-job logs
    carry the exception class.
30. **Files are served only through authorised, audited routes.** `local.serve = false`. Grievance
    evidence is tenant-prefixed and fingerprinted. Every document download is audited. A stored report
    export is re-downloadable only by its producer (or the owner, for a scheduled run).
31. **Queue and scheduler.** A tenant-aware job without a tenant fails; suspended tenants' jobs and
    scheduled runs are skipped (retention excepted). Per-tenant failures are isolated.

## SaaS.2 additions (foundation hardening)

32. **Account security is enforced per request.** `EnforceAccountSecurity` (persistent panel middleware, and on
    every protected download) requires a session to have proved its authenticator (`MultiFactor::isVerified`),
    sends users who must use MFA and have none to set-up, and sends unverified local addresses to verification.
    MFA "required" is never a Filament route-time flag. Every login (password, remember-me, SSO) starts unproven
    (`SecureNewSession` on the `Login` event).
33. **A dead session is signed out, not refused.** `EnsureSessionIsValid` runs before authentication (middleware
    priority) and signs out an inactive user, a tenant user of an inaccessible tenant, or a session whose session
    epochs (`SessionSecurity`) changed. Suspension, MFA reset, password reset and "sign out everywhere" raise
    epochs and clear remember-me tokens; no session rows are deleted.
34. **Protected web routes run the full stack.** Any route serving tenant data outside the panel uses
    `web, auth, auth.session, EnsureSessionIsValid, ResolveTenant, EnforceSecurityPolicy, EnforceAccountSecurity`
    (see `routes/web.php`), never `auth` alone.
35. **Operators reach a tenant only through a grant.** `ResolveTenant` binds a platform operator to a tenant only
    through a valid, unexpired `PlatformTenantAccess` grant (reason, optional reference, time box), audited on the
    platform chain and the tenant's chain; everything recorded during it carries `platform_access_id`. No tenant
    identity is ever created for an operator. Platform `tenant.*` keys are never effective for tenant users.
36. **Identity links are one-time and reveal nothing.** Invitations store only a SHA-256 of their token and work
    once for one invited user of an accessible tenant; password resets are silent, token-checked before any
    policy message, locked against double use, and never queued. Administrators never choose or see passwords.
37. **Status changes go through their service.** Tenant suspension and reactivation use `TenantSuspensions`
    (conditional update, never a lock on the tenants row); the tenant edit form cannot change the status. A
    `Tenant`'s own changes are audited in its own chain (`auditTenantId()`); `AuditRecorder` writes to the
    platform chain only when asked (`platform: true`).
38. **Outbound workflow webhooks are signed** with the PeopleOS scheme (`X-PeopleOS-Timestamp`,
    `X-PeopleOS-Signature`, a delivery id stable across retries); configured headers cannot override them.

## SaaS.3 additions (entitlements, shadow mode)

39. **Entitlements never replace authorisation.** A commercial entitlement answers "does this tenant have the
    capability?"; permissions, scopes, field security and policies alone decide what a user may do. No permission,
    policy, gate, scope or navigation item reads an entitlement.
40. **Shadow mode never blocks.** HCM code calls `Entitlements::observe()` and ignores the result; it never throws, and
    `Decision::enforced()` is always false. Commercial evaluation fails open (UNKNOWN, logged); security never does.
41. **Only platform operators change entitlements**, through `EntitlementConfiguration`, with a reason, audited on the
    tenant chain and the platform chain; history is never rewritten (changes start today or later). Missing
    configuration is UNKNOWN, never DENY.

## SaaS.4 additions (commercial plans, shadow mode)

42. **Plans never reach authorisation or HCM code.** Only the entitlement services and the two platform pages
    reference plan or assignment models (architecture test). A plan neither grants nor removes a permission.
43. **Only platform operators change plans and assignments**, through `PlanCatalog` and `EntitlementConfiguration`,
    with a reason, audited on the platform chain (and on the tenant's chain for assignments). Tenant administrators,
    whatever their roles, are refused in the services and cannot open the pages.
44. **A published plan version never changes**, and neither does what it says about any capability. A tenant
    assigned to a version keeps it when the catalogue evolves. Assignments start today or later; history is kept.
45. **No plan switches a protected capability off**, and the core and security controls cannot appear in a plan.
    Tenants without a plan stay UNKNOWN: no default plan is ever assigned silently.

### Phase 14 review of raw queries and scope bypasses

| Pattern | Count | Review result |
|---|---|---|
| `TenantContext::bypass()` / `withoutTenancy()` | 11 files (SaaS.2: +1, SaaS.3: +1; SaaS.4: +0 files, two more reads in the allow-listed `EntitlementDiagnostics`: tenants per plan version, counts only, and Markedge's platform audit chain) | Platform services only, each on the architecture allow-list: audit recorder / verifier (cross-tenant chains), API key resolution (before a tenant exists), SSO connection lookup by slug, tenant provisioning, access-scope rows, job tenant binding, health and readiness (counts only), invitation token lookup (before the invitee is signed in), the operators' cross-tenant entitlement shadow summary (counts only) |
| `withoutGlobalScope(AccessScope::class)` (345 call sites in 110 files) and `AccessScope::withoutScoping()` (32) | Mechanically each removes only the **organisation** scope; the fail-closed tenant scope stays. Reviewed by category (not line by line): domain services checking a target by id after an explicit `AccessScopes::allows` check, background sweeps, aggregate analytics with small-group suppression, and identity checks (Phase 14: tenant-wide on purpose) |
| `withoutGlobalScopes()` (all) | 1 | `NumberSequences::highest`. Phase 14 narrowed it to the access scope with an explicit `tenant_id` filter |
| `DB::table()` | 13 | Each carries an explicit tenant id or a key of a tenant-scoped row: employee-code sequences, scheduler claims, audit-chain locks (platform), engagement answer aggregates (Phase 14 added explicit `tenant_id` filters), EPF revision rows by return id, health counts (platform, counts only) |
| Interpolated SQL fragments (`selectRaw` / `whereRaw` with `{$…}`) | 5 | Interpolated values come from code constants or allow-listed dimension names (workforce dimension columns, movement-type CASE built from config keys, PersonMatcher column names); user input is always bound |
| `DB::select` / `statement` / `unprepared` | 1 | Health `select 1` |
