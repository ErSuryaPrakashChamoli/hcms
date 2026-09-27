# Phase 1 — SaaS Foundation

Implements blueprint §121 Phase 1: authentication, tenants, users, companies, tenant isolation,
roles, permissions, feature flags, settings and the audit engine. Everything here is treated as
*protected platform architecture* (§101, §129): tenants configure on top of it, never inside it.

## Layout

```
app/
  Domain/
    Platform/      Tenant, TenantSetting, TenantFeature, SettingsRepository, FeatureFlags, ProvisionTenantAction
    Identity/      User, Role, Permission, PermissionRegistry, policies, enums
    Organisation/  Company (+ CompanyPolicy)
    Audit/         AuditEvent, AuditEventChange, AuditRecorder, Auditable trait, AuditIntegrityVerifier, auth listeners
  Support/
    Tenancy/       TenantContext, TenantScope, BelongsToTenant, exceptions
    EffectiveDating/ HasEffectiveDates
  Filament/        Admin Control Centre resources, AuditHistoryRelationManager, TenantOverview widget
  Http/Middleware/ AssignRequestId, ResolveTenant, SetAuditSource
config/peopleos.php   Permission catalogue, system role templates, default features and settings
```

Models live under `App\Domain\{Module}\Models` and bind their factory with `#[UseFactory]`.
The audit module name is derived from that namespace (`organisation`, `identity`, `platform`).

## Tenancy (§81)

- Single database, `tenant_id` on every tenant-owned table.
- `TenantContext` (container-scoped) holds the acting tenant. `ResolveTenant` middleware binds it
  from the authenticated user; platform admins bind it by "entering" a tenant (session key).
- `BelongsToTenant` adds `TenantScope` and stamps `tenant_id` on create. It throws on cross-tenant
  writes and on any attempt to move a record between tenants.
- **Fail closed:** with no tenant bound, tenant-scoped queries return nothing. Cross-tenant reads
  need an explicit `TenantContext::bypass()`; keep those in platform code only.
- `User` is deliberately *not* globally scoped (it is looked up before a tenant is known, at
  login). Tenant-facing listings use `User::forCurrentTenant()`.
- The tenant id travels to queued jobs as hidden `Context`; `AppServiceProvider` re-binds it on
  hydration.

## Identity and authorisation (§78–§80)

- Permissions are `resource.action` keys defined in `config/peopleos.php` and mirrored into the
  `permissions` table by `php artisan peopleos:sync-permissions`. Tenants cannot create keys.
- Roles are tenant-owned. System roles (blueprint defaults) are provisioned per tenant with
  wildcard templates (`company.*`, `*`) expanded to concrete keys at provisioning time; the sync
  command tops them up when the catalogue grows.
- `role_user.company_id` carries optional entity-specific grants (§8); null = tenant-wide.
- `Gate::before`: platform admins (`users.is_platform_admin`, `tenant_id` null) pass everything;
  bare permission keys resolve straight from the user's roles. Model policies extend
  `PermissionPolicy`, which maps `viewAny/view/create/update/delete` to `{resource}.{action}`.
- `TenantPolicy` denies every tenant user regardless of role. Platform Super Admin is a flag, not a
  configurable role, on purpose (§101 "protect: security, tenant isolation").
- Field-level security (§80) is not yet built; `config('peopleos.audit.sensitive_attributes')` is
  the first hook for it.

## Audit and change intelligence (§63–§68)

- `AuditRecorder` is the only write path. Every event stores who (actor id/name/roles), what
  (action, module, entity type/id/label), when (µs), where (ip, user agent, source, request id),
  why (reason, approval reference), effective date and metadata.
- `Auditable` trait records CREATE/UPDATE/DELETE/RESTORE with field-level before/after rows in
  `audit_event_changes`. Attributes in `ignored_attributes` are skipped; `sensitive_attributes`
  are masked. Call `$model->withAuditReason($reason, $approvalRef)` before saving.
- Append-only: the models throw `ImmutableAuditRecordException` on update/delete and the
  `AuditEventPolicy` denies those abilities. Enforcement at the database-user level (no UPDATE/DELETE
  grant on `audit_events`) is a deployment task.
- Hash chain per tenant (and one for platform-level events): `hash = sha256(previous_hash | canonical
  json)`. `php artisan peopleos:audit:verify` and the "Verify integrity" button on Change History
  recompute the chain. The canonical payload format is part of the storage schema; changing it
  invalidates existing chains.
- Security events (LOGIN, LOGOUT, LOGIN_FAILED, PASSWORD_CHANGED) come from auth event listeners.
- Every request carries an `X-Request-Id` (`AssignRequestId`), stored on each event (§112).

## Effective dating (§70)

`HasEffectiveDates` gives `effectiveOn($date)`, `currentlyEffective()`, `futureDated()`, `expired()`
and `isEffectiveOn()`. Company uses it; later phases use it for salary, designation, shifts, policies.

## Admin Control Centre (Filament 5)

Navigation groups: Organisation (Companies), Access (Users, Roles), Customisation (Settings, Feature
flags), Audit (Change history), Platform (Tenants — platform admins only).

- Forms never expose `tenant_id`; it comes from context. Edit forms carry a non-persisted
  "Reason for change" field that becomes the audit reason.
- Every resource has a **History** tab (`AuditHistoryRelationManager`) — the §104 "What changed?"
  experience.
- Platform admins enter a tenant from the Tenants list and leave via the user menu.

## Commands

| Command | Purpose |
| --- | --- |
| `peopleos:sync-permissions` | Mirror the catalogue into the DB and top up system roles |
| `peopleos:audit:verify [--tenant=]` | Recompute hash chains |
| `db:seed` | Platform admin `platform@markedge.local`, tenant `demo` with `admin@demo.local` (password `password`) |

## Tests

`tests/Feature/{Tenancy,Identity,Audit,Platform,Support,Admin}` — 49 tests covering §115 layers 4
(tenant isolation), 5 (permissions), 118 (audit) plus effective dating, provisioning and panel
rendering/access. Run `php artisan test`.

## Known gaps / next (Phase 1)

- Phase 2 (organisation core) hangs locations, departments, levels, grades, designations off
  `companies` using the same three traits.
- MFA, password policy, session/device management (§82) are configured as feature flags/settings
  only; enforcement is pending.
- Platform-level audit events (tenant null) are visible via the CLI verifier, not yet in the UI.
- Configuration versioning/approval/impact preview (§69–§75) build on `Auditable` + effective dates
  in Phase 4.
