# SaaS.1 — Commercial Security Threat Model

**Status:** Proposed (SaaS.1, architecture only) · **Date:** 6 October 2026 · **Part of:** [SaaS.1 Commercial Architecture & Gap Analysis](SaaS-1-Commercial-Architecture-Gap-Analysis.md)

The commercial layer adds new assets: money, invoices, payment references, entitlements, and the power to suspend, export or delete a whole tenant. It also adds new actors: payment providers, anonymous visitors at signup, and platform operators with commercial powers.

This document:
- maps every commercial threat onto the existing security chain;
- records the controls the later phases must build;
- records the **existing** weaknesses found in SaaS.1 that a commercial launch would expose (§4). These were verified in code; none was fixed in SaaS.1.

```
AUTH → TENANT → ROLE → PERMISSION → ORGANISATION SCOPE → RELATIONSHIP SCOPE → FIELD SECURITY → RECORD
          ▲
          └── commercial additions sit here: tenant ACCESS MODE (State Machines §5) and ENTITLEMENT GATE
              (Entitlement doc §6). They narrow what a tenant may do; they never widen what a user may do.
```

**Principle.** Entitlements and access modes are **subtractive**. A capability needs all of:
- an authenticated user;
- the right tenant;
- an access mode that allows it;
- an entitlement that allows it;
- the permission;
- scope and field rules.

No commercial grant can bypass a permission, and no permission can bypass a missing entitlement.

## 1. Assets

| Asset | Owner | Sensitivity |
|---|---|---|
| Subscription and entitlement state | Platform | Integrity-critical: wrong state means free use or wrongful lock-out |
| Invoices, credit notes, tax data (GSTIN, addresses) | Platform (Markedge's books) about a tenant | Confidential to that tenant; integrity-critical (tax law) |
| Payment references, mandate references, masked instrument display | Platform | Confidential. No card data is ever stored (Billing doc §1.4) |
| Provider webhook secrets and API credentials | Platform (environment / secret store) | Secret |
| Tenant export archives | Tenant data, platform-generated | Highly sensitive: the whole tenant |
| Deletion and suspension controls | Platform operators and tenant owners | Destructive |
| Signup records (visitor e-mail, IP, company) | Platform | Personal data |

## 2. Actors

| Actor | Trust | New in SaaS? |
|---|---|---|
| Anonymous visitor | None | Yes (signup) |
| Tenant user (employee, manager, HR) | Tenant-scoped, permission-scoped | No |
| Tenant owner / billing administrator | Tenant-scoped, holds `billing.*` permissions | Yes (new permission group) |
| Platform operator | Cross-tenant, holds `platform.*` permissions (proposed; today a single `is_platform_admin` flag) | Powers extended |
| Payment provider | Authenticated by signature only | Yes |
| API client (tenant key) | Tenant-scoped, scope-limited | No |
| Background job / scheduler | Tenant-bound per job | No |

## 3. Threats and controls

| # | Threat | Scenario | Control (target) | Chain layer | Test (Gap Analysis §21) |
|---|---|---|---|---|---|
| T1 | **Cross-tenant commercial access** | A tenant owner reads another tenant's invoices by id, through a direct URL or the API | Commercial records are platform-owned and tenant-keyed, readable only through `TenantBilling` services that filter `tenant_id = TenantContext::id()`; references are opaque ULIDs, never ids. An architecture test confines reads of commercial models to those services ([ADR-0017](../architecture/decision-register.md#saas1-proposed-decisions)) | TENANT | "Tenant A cannot see Tenant B billing" (UI, API, export, direct id, reference guessing) |
| T2 | **Entitlement bypass** | A user reaches an unlicensed module through a deep link, the API, a scheduled job, an import, AI or search | The entitlement gate sits under every permission check (module → permission mapping), at domain actions, in `AuthenticateApiKey` (scope → module), and in `BindTenantContext` and `TenantRunner` (module sweeps). The UI reads the same gate but is never the boundary (Entitlement doc §6) | TENANT + PERMISSION | "Unlicensed module refused across UI, API, jobs, imports, AI" |
| T3 | **Seat or employee limit bypass** | Hire or import beyond the limit through parallel requests, the API, CSV import, the pre-employee ingress or rehire | Count-based limits are enforced inside the creating domain action, in the same transaction as the insert, under a per-tenant lock (`SELECT … FOR UPDATE` on a tenant usage row). A bulk import reserves its total before starting. The check covers **every** path because it lives in the action, not the screen | RECORD (domain action) | "Concurrent employee creation cannot exceed the limit"; "Limit not bypassed through API / import / rehire" |
| T4 | **Trial bypass** | Keep using a trial after expiry; re-register to get a new trial | Expiry is acted on by the scheduler and enforced by access mode on every request, API call and job. One trial per verified company identity and verified domain; duplicates detected at signup (Target Architecture §5) | TENANT (access mode) | "Expired trial cannot access a restricted capability" |
| T5 | **Payment webhook forgery** | An attacker posts a fake `payment.succeeded` | Raw-body signature verified in constant time by the provider adapter before any parsing; unknown provider → 404; failure → 401 and a metric; tenant resolved only from verified provider references | AUTH (provider) | "Forged and unsigned webhooks rejected, nothing changes" |
| T6 | **Replay** | A captured genuine webhook is re-sent later | Unique `(gateway, provider_event_id)` plus a timestamp window where the scheme signs one. A different payload under a known event id is refused and alerted | AUTH (provider) | "Replayed webhook processed once" |
| T7 | **Duplicate payment processing** | Webhook and redirect confirmation race, or two webhooks arrive for one payment | `PaymentService::recordSucceeded` is idempotent on unique `(gateway, provider_payment_id)` under a row lock. Allocation is unique per (payment, invoice) | RECORD | "Duplicate webhook cannot create a duplicate payment" (MySQL concurrency suite) |
| T8 | **Privilege escalation to billing powers** | An HR user cancels the subscription or changes the billing e-mail | New permission group `billing.view`, `billing.manage`, `billing.owner_transfer`, granted only to the tenant-super-admin system role by default. Destructive actions (cancel, delete tenant, export all) need re-authentication and MFA once MFA works (§4 W1) | PERMISSION | "Tenant admin without billing permission cannot change billing" |
| T9 | **Platform operator abuse** | An operator comps a friend, extends trials, reads tenant data, deletes a tenant | Split the single `is_platform_admin` flag into platform permissions (`platform.tenants.view`, `.lifecycle`, `.commercial`, `.data_access`, `.delete`). Every commercial operation needs a reason and is audited on the **platform chain** with the operator as actor. Dual control (a second operator approves) for comp, refund above a threshold, entitlement overrides above a threshold, and tenant deletion. Entering a tenant needs a reason, is time-boxed and audited (today it is not, §4 W4) | AUTH + ROLE (platform) | "Platform admin actions are audited"; "Deletion needs two operators" |
| T10 | **Tenant admin abuse** | A tenant admin raises their own limits or edits plan data | Catalogue, overrides and snapshots are platform-owned: no tenant permission writes them, and no Filament resource exposes them in tenant context. `RoleForm` must exclude platform keys from tenant role editing (today it lists all permission rows, §4 W8) | PERMISSION | "Tenant admin cannot modify platform commercial configuration" |
| T11 | **Invoice access** | An invoice PDF URL leaks, or an ex-employee keeps a link | PDFs on a private disk, served by a signed short-lived route that re-authorises `billing.view` after the tenant is bound, plus a `DOWNLOAD` audit (the existing document pattern). Never a permanent URL | TENANT + PERMISSION | "Invoice download requires current permission" |
| T12 | **Billing data exposure** | Payment details in logs, AI or exports | No card data stored. Provider payloads encrypted and purged after the retention window. `RedactSensitiveLogData` extended with provider id patterns. Commercial data never enters AI facts, HCM datasets or the warehouse feed | FIELD | "Logs carry no payment identifiers or payload bodies" |
| T13 | **Export abuse** | A compromised owner account exfiltrates the whole tenant | Full-tenant export needs `tenant.export` (owner only), MFA, a reason, and notifies all owners. Archive encrypted, signed URL with short expiry, single download limit, audited. Rate-limited to N per 30 days | PERMISSION + FIELD | "Export requires permission and MFA; download expires" |
| T14 | **Deletion abuse** | Malicious or mistaken tenant deletion | Owner request + platform confirmation, cooling-off (proposed 14 days) with notices to all owners, legal hold blocks, final export offered, irreversible step only after the window. Audit records are never deleted (`audit_events` FK is `restrictOnDelete` today, and that is correct) | AUTH + ROLE | "Deletion request inside cooling-off can be cancelled; held tenant cannot be deleted" |
| T15 | **Job tenant leakage** | A commercial job processes tenant A's data while bound to tenant B | Commercial jobs are platform jobs that resolve the tenant from **their own** record (billing account → tenant) and then `runAs`. Tenant-owned side effects only inside `runAs`. Existing `TenantAwareJob` + `BindTenantContext` for tenant-side jobs (ADR-0013) | TENANT | "Commercial job binds the tenant of its record" |
| T16 | **Cache tenant leakage** | An entitlement snapshot cached for tenant A served to tenant B | Cache keys are `tenant:{id}:entitlements:v{version}`. The request memo is keyed by tenant id and reset per request and per job (the existing `EXPERIENCE_SCOPED` pattern). Never a global key | TENANT | "Tenant A cannot use Tenant B entitlement" |
| T17 | **API key abuse** | A leaked key used to exceed API quota or reach unlicensed modules | Key scopes map to modules (entitlement checked in `AuthenticateApiKey`). API calls metered per tenant (usage events). Quota per plan. The existing 120/min rate limit stays as abuse protection | TENANT + PERMISSION | "Key cannot reach an unlicensed module; quota enforced" |
| T18 | **Signup abuse** | Bots create tenants; enumeration of existing companies; e-mail bombing | Signup rate-limited per IP and e-mail; CAPTCHA (decision); verified e-mail before provisioning; neutral responses ("if the address is valid you will receive…"); duplicate detection discloses nothing about existing tenants | AUTH | "Duplicate signup does not reveal tenant existence" |
| T19 | **Provisioning race** | A double-submitted signup creates two tenants | `provisioning_runs.idempotency_key` UNIQUE + `tenants.slug` UNIQUE (exists) + resumable steps | RECORD | "Duplicate provisioning cannot create a duplicate tenant" |
| T20 | **Entitlement snapshot tampering** | A direct DB edit or a bug changes entitlements silently | Snapshots carry a hash of their sources; recompilation is deterministic; a daily integrity job recompiles and compares (P2). Overrides are audited platform events | RECORD | "Snapshot recompile equals stored hash" |

## 4. Existing weaknesses that a commercial launch would expose

These were found while verifying the current state (Gap Analysis §3). They are **not** commercial features. They are defects or limits in the existing security foundation that a paid, multi-tenant service open to customers cannot carry. Each has an id used in the gap tables.

| # | Weakness (verified) | Evidence | Why it matters commercially | Priority |
|---|---|---|---|---|
| W1 | **Tenant "MFA required" never takes effect, and no user can enrol MFA.** Filament evaluates `isRequired` when routes are registered, when no tenant is bound, so the closure returns false. No `EnsureMultiFactorAuthenticationIsEnabled` middleware is on any panel route. There is no MFA set-up route and no profile page (`->profile()` not enabled) | `AdminPanelProvider.php:204`; `route:list` (no multi-factor routes; `admin/users` middleware without the MFA middleware) | Customers will be told MFA can be enforced; it cannot. Owners holding billing, export and deletion powers cannot protect their accounts | **P0** |
| W2 | **No password reset; no e-mail verification; no self-service password change** | No `->passwordReset()`, `->emailVerification()` or `->profile()`; `password_reset_tokens` unused | Every forgotten password becomes a support ticket for Markedge; signup cannot verify e-mail ownership | **P0** (with signup) / P1 (sales-led) |
| W3 | **SSO hardening:** identity from userinfo only (no ID token signature or JWKS check, no PKCE); nonce generated but never verified; links to an existing account **by e-mail without an `email_verified` claim**; the `enforce` flag is stored but unused; SSO login skips MFA; a cross-tenant e--mail collision surfaces a raw SQL error | `Sso.php:31,38-59,79`; `SsoController.php:43-47` | Account takeover risk through a misconfigured or hostile IdP; enterprise customers expect SSO enforcement | **P1** |
| W4 | **Platform operator entering a tenant is not audited and needs no reason;** tenant record edits by a platform admin can land in the entered tenant's audit chain; platform-chain events are not viewable in the UI | `TenantsTable.php:36-44`; `Auditable` on `Tenant`; `AuditEvent` registers `TenantScope` | No answer to "who at Markedge looked at our data, and why" (a standard enterprise and DPDP question) | **P0** |
| W5 | **Tenant status check gaps:** document, certificate and attachment download routes run `auth` + `ResolveTenant` only (no tenant-status, user-status or security-policy middleware); suspension does not end live sessions; the tenant edit form can change `status` directly, bypassing the audited suspend flow | `routes/web.php:19-40`; `TenantForm.php:35-38` | Commercial suspension and offboarding rely on status checks being complete | **P0** for the lifecycle phase |
| W6 | **Only one blocking tenant status (`suspended`)** is understood by panel access, API keys, jobs and the scheduler | `TenantStatus::allowsAccess`, `BindTenantContext`, `TenantRunner` | New lifecycle states must be blocking everywhere at once (State Machines §2) | **P0** for the lifecycle phase |
| W7 | **`AccessScopes` is a singleton holding a request-scoped `TenantContext`.** After `forgetScopedInstances()` (queue workers) it keeps a stale context | `AppServiceProvider.php` (singleton `AccessScopes`, scoped `TenantContext`); `AccessScopes.php:35` | Low impact today (jobs run without an authenticated user, so the organisation scope is not applied). Becomes relevant with long-lived workers that act as users, or Octane | P2 |
| W8 | **Tenant role editing lists every permission, including platform keys** (no effect today because `TenantPolicy` denies all). Company-scoped role grants behave tenant-wide. `peopleos:sync-permissions` re-adds permissions a tenant removed from a system role | `RoleForm.php:39-45`; `User.php:164`; `SyncPermissions` + `seedSystemRoles` | Module licensing will hang off permissions (Entitlement doc §6); the role editor must respect entitlements and must never offer platform or commercial keys | P1 |
| W9 | **API idempotency keys never expire and are never purged;** inbound-event uniqueness is on the idempotency key, not the external event id | `EnforceIdempotency.php:42-45`; `InboundEvents.php:64` | Unbounded growth; a provider retry with a new key could duplicate work (the commercial webhook design keys on the provider event id instead) | P2 |
| W10 | **`users.email` is globally unique** | `create_users_table.php:17` | One person cannot have logins in two tenants (a consultant, or someone who moves to a customer). Signup must handle "e-mail already used" without disclosing which tenant | P1 (design constraint) |
| W11 | **No user id in log context;** `monthly` and `slack` log channels lack the redaction tap | `config/logging.php`; `RedactSensitiveLogData` | Commercial observability ("who triggered this suspension") needs actor ids in logs. Unredacted channels could leak payment references | P2 |
| W12 | **Workflow webhook node sends unsigned requests** | `SendWebhook.php:63-83` | Not commercial, but part of the outbound trust story customers will ask about | P3 |
| W13 | **Feature flags can be changed outside their permission.** Blueprint and configuration-pack import sets flags with `blueprint.import` alone, without `features.update`. Feature and setting edits skip the "high" risk governance that `config/peopleos.php` assigns them. Two flags are never read (`organisation.designer`, `security.mfa`) | `Blueprints.php:99-101`; `ConfigurationPacks.php:39,54,82`; `TenantFeatureResource` | Once flags sit under entitlements (Entitlement doc §2), an import must never turn on an unentitled capability, and dead flags must not be sold | P1 |
| W14 | **Provisioning silently drops `tier`, `region` and `trial_ends_at`** entered on the create form. `ProvisionTenantAction::handle` copies seven attributes only | `ProvisionTenantAction.php:50-58`; `CreateTenant.php:19` | A residency or tier commitment recorded at sign-up would be lost | P1 (fix in the provisioning phase) |

## 5. Separation of platform and tenant administration

| Rule | Today | Target |
|---|---|---|
| A platform operator has no tenant | Yes: `isPlatformAdmin()` requires `tenant_id = null`; `CreateUser` forces `is_platform_admin = false` for tenant users | Keep. Add a database check constraint or a test that `is_platform_admin = true` implies `tenant_id IS NULL` |
| A platform operator is never an employee | Not possible through the UI (the employee login picker uses `forCurrentTenant()`); no constraint | Keep, plus a test |
| Platform powers are granular | One flag; `Gate::before` returns true for everything | Platform permission catalogue (T9). `Gate::before` grants platform abilities only, never tenant HCM abilities unless the operator has entered the tenant with `platform.data_access` and a reason |
| Platform work leaves a platform trail | Suspend and reactivate are audited (to the tenant's chain); enter and exit are not | Every platform action audited on the platform chain (`tenant_id` set as the *subject* in metadata), with reason and correlation id; a platform audit viewer in the control plane |
| Tenant admins never see commercial internals of other tenants or the platform catalogue | Yes (nothing exists) | Keep; enforced by T1 and T10 |
