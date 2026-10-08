# SaaS.8 — Platform operations and tenant lifecycle reliability

What SaaS.8 built, how it fits the existing architecture, and the conventions it adds. Discovery, ranking and scope are in `docs/saas/SaaS-8-Discovery-and-Scope.md`; decisions ADR-0070 to ADR-0080 are in `decision-register.md`; invariants 78 to 87 are in `security-invariants.md`; operator procedures are in `docs/operations/tenant-operations-runbook.md`.

## 1. Domain boundaries

| Area | Owner | SaaS.8 additions |
|---|---|---|
| Tenant lifecycle (platform) | `app/Domain/Platform` | `Support/PlatformOperator` (service-level operator guard), `Support/LifecycleRefused`, `Services/TenantFirstAdministrator`, `Services/TenantProfiles`, `Services/TenantDeferredWork`, `Services/PlatformOperationsOverview`, `Models/TenantDeferredJob`; `TenantSuspensions` and `ProvisionTenantAction` hardened |
| Tenancy infrastructure | `app/Support/Tenancy/Jobs` | `DeferredTenantJobs`; `BindTenantContext` defers instead of dropping |
| Audit | `app/Domain/Audit` | `Models/AuditVerificationRun`, `Services/AuditVerifications`, `Services/PlatformAuditTrail`; chain-head index |
| Notifications | `app/Domain/Notifications` | `Services/NotificationRecovery`; policy `resend`; permission `notification.resend` |
| Enterprise | `app/Domain/Enterprise` | Warehouse feed opt-in and snapshot purge; batched retention |
| HTTP | `app/Http/Middleware` | `SecurityHeaders`; `ApiKeys::resolveFor` shared by the rate limiter and `AuthenticateApiKey` |
| Panel | `app/Filament` | `Pages/PlatformOperationsPage`, `Pages/PlatformAuditTrailPage`, `Support/TenantUnique`; Tenants list "Send invitation"; delivery log "Send again"; Security & retention policy "Data warehouse feed" |

Nothing in SaaS.8 touches billing, tax, payments, plans, subscriptions, entitlements, HCM business rules (except the payroll four-eyes exception) or RMS.

## 2. Tenant lifecycle

```
PROVISION ─► INVITE ─► VERIFY ─► MFA ─► ACTIVATE ─► CONFIGURE ─► SUBSCRIBE ─► OPERATE ─► SUSPEND ⇄ REACTIVATE
   │            │                                                                         │          │
   │            └─ invitation atomic with its e-mail; failure recorded; operator resend    │          └─ status restored (trial|active);
   │                                                                                       │             deferred work released once
   └─ operator + reason (web); both chains                                                 └─ interrupted status recorded; sessions ended;
                                                                                              queued work deferred (not lost)
EXPORT / OFFBOARD / RETAIN / DELETE: not built (WS7, needs D-8)
```

### 2.1 Operator guard (`PlatformOperator`)
- **A named actor must be a platform operator:** `is_platform_admin` and no tenant. MFA is enforced per request (SaaS.2).
- **Without an actor:**
  - only the console may act (seeders, runbook commands, the queue worker);
  - a web request that names no operator is refused, whoever is signed in.
- **Reasons:** 5 to 1,000 characters, kept on both chains.
- **Called by:** `TenantSuspensions`, `ProvisionTenantAction` (reason required when an operator acts), `TenantProfiles`, `TenantFirstAdministrator::send`, `TenantDeferredWork`.
- **Pages:** platform-only as before. The page is no longer the only guard.

### 2.2 Suspension and reactivation (`TenantSuspensions`)
- **Suspend:**
  - reads the current status;
  - one conditional update `WHERE status = <read>` sets `suspended`, `suspended_from` (the interrupted status) and `suspended_at`;
  - sessions are revoked in the same transaction.
- **Reactivate:** one conditional update `WHERE status = 'suspended'` restores `suspended_from`. A tenant suspended before SaaS.8 has none recorded and becomes `active`, as before.
- **No lock on the tenants row:** every child insert holds a shared lock on it through its foreign key (SaaS.2 lesson).
- **Concurrency:** two operators acting at once produce one transition and one audit pair; the second call returns `false`.
- **Audit:** `TENANT_SUSPENDED` / `TENANT_REACTIVATED` on the tenant chain and the platform chain, with the status before and after, and on reactivation the number of deferred jobs.

### 2.3 Deferred work (`DeferredTenantJobs`)
- **Before:** `BindTenantContext` acknowledged a suspended tenant's job and returned. The queue deleted it.
- **Now:** the job, without its queue handle (`$job->job`), is serialized and stored in `tenant_deferred_jobs`, encrypted with the application key (cast `encrypted`; a job may carry a webhook URL or headers).
- **Exception:** `RunsForSuspendedTenants` jobs (payment-provider events) still run and are never deferred.
- **Release** (`release()`), triggered by reactivation, by the operator action on Platform operations (`TenantDeferredWork`), or by a later `defer()`:
  - only if the tenant is accessible;
  - in batches of 100: `SELECT … FOR UPDATE` on the tenant's rows by `(tenant_id, id)`, `dispatch()` each job, delete the rows, commit;
  - two releases serialise on the row locks, so each job is dispatched once (MySQL race 1);
  - **at least once:** dispatch happens before the commit, so a failed commit can repeat a job but never loses one. PeopleOS jobs are idempotent (delivery claims, unique locks, delivery ids, leased claims).
- **Deferral racing reactivation:** after inserting, `defer()` reads the tenant status again and releases at once if the tenant is accessible. A job deferred while the tenant is being reactivated never waits for a reactivation that already happened (MySQL race 2).
- **Unserializable jobs:** only an object that never went through a queue (an anonymous class, a closure) cannot be serialized. It is logged as an error, not stored.
- **Not deferred: scheduled work.** `TenantRunner` skips suspended tenants, and every scheduled sweep is re-derived from state on its next run.

### 2.4 Provisioning and the first administrator
- **Provisioning:** `ProvisionTenantAction` is unchanged in substance (one transaction). It now:
  - requires an operator and a reason on the web;
  - records `TENANT_PROVISIONED` on the platform chain as well, with subject tenant id, slug, status, tier and region, and no personal data (the tenant chain keeps the administrator e-mail as before).
- **The invitation** (`TenantFirstAdministrator::inviteAfterProvisioning`) runs after the commit:
  - The invitation row, its `INVITATION_ISSUED` audit and the e-mail run in one transaction. An e-mail the transport refuses rolls them back, so no pending invitation exists that nobody received.
  - A failure is recorded as `INVITATION_FAILED` on both chains (exception class only). The tenant stays provisioned, and the create page warns the operator.
- **Send invitation** (Tenants list; `TenantFirstAdministrator::send`):
  - **Who:** an operator, with a reason, for a tenant that is not suspended and whose first super administrator is still `invited`.
  - **Concurrency:** the administrator's user row is locked, and an invitation issued within `peopleos.identity.invitation_resend_seconds` (60) is refused. Two operators at once produce one invitation (MySQL race 7). One attempt only: a deadlock retry could e-mail a second link.
  - **Audit:** `INVITATION_ISSUED` on the platform chain too.

### 2.5 Tenant record edits (`TenantProfiles`)
- **Editable fields:** `name`, `slug`, `tier`, `region`, `trial_ends_at`, locale defaults. The status and the suspension columns are never editable.
- **Validation:** unknown tiers and regions are refused.
- **Requirements:** an operator and a reason (the form requires the reason).
- **Audit:** `TENANT_PROFILE_CHANGED` on the tenant chain (model diff) and on the platform chain (explicit before/after).

## 3. Notification reliability (`NotificationRecovery`)

```
queued ──claim──► sending ──► sent
   ▲                 │  └──► queued (retry) / failed (final)
   │                 └─(worker lost; > lease)──► failed  "Outcome unknown"   [sweep]
   └─(job lost; > requeue window)── dispatched again                         [sweep]
failed ──"Send again" (notification.resend, reason)──► queued ──► …            [administrator]
```

- **Sweep** (`peopleos:notifications:recover`, every 5 minutes, per tenant; suspended tenants wait):
  - `sending` older than `PEOPLEOS_NOTIFICATIONS_SENDING_LEASE_MINUTES` (15) becomes `failed` with `UNKNOWN_OUTCOME`. The lease is far above the job's 60 s timeout. It is never sent again automatically, because the recipient may have received it.
  - Async `queued` older than `PEOPLEOS_NOTIFICATIONS_REQUEUE_AFTER_MINUTES` (60) is touched first with a conditional update, then dispatched again. Two sweeps dispatch it once (MySQL race 6), and the delivery claim prevents a double send anyway.
- **Resend:** the actor must hold `notification.resend` (policy `resend`, checked in the service), act in the delivery's tenant, give a reason of 5 to 500 characters, and the delivery must be `failed`.
  - **Concurrency:** `failed → queued` is a conditional update, so two administrators produce one resend (MySQL race 5).
  - **Audit:** `NOTIFICATION_RESENT` on the tenant chain, with channel, event and count, and no subject or body.
  - The delivery log explains an unknown outcome before the administrator confirms.
- **Scope:** all notifications are transactional. No marketing channel or automation exists, and none was added.

## 4. Security hardening
- **`SecurityHeaders`** (appended to every response: panel, API, downloads, health):
  - `X-Frame-Options: SAMEORIGIN` and `Content-Security-Policy: frame-ancestors 'self'`;
  - `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()` (none is used);
  - `Strict-Transport-Security: max-age=<PEOPLEOS_HSTS_MAX_AGE, default 31536000>` on HTTPS requests only, without subdomains;
  - a header a route already set is kept;
  - a full script CSP is not attempted (Filament and Livewire need nonces).
- **API rate limit:**
  - The limiter resolves the key through `ApiKeys::resolveFor()`, memoised on the request so `AuthenticateApiKey` reuses the same resolution.
  - A verified key has its own bucket (`key:<public prefix>`). Anything unverified, invalid or absent shares the caller's IP bucket.
  - **Closed:** exhausting a tenant's limit by presenting its prefix with garbage secrets, and unlimited failed attempts with fresh random tokens.
- **Workflow webhook node:** the payload goes through `PayloadGuard::clean` like every outbound payload.
- **Payroll approval:** the preparer can never approve, with no exception for platform operators.

## 5. Integrity, hygiene and governance
- **Audit verification:**
  - `peopleos:audit:verify --scheduled` runs daily at 02:30. `AuditVerifications` writes one `audit_verification_runs` row per chain (`subject_tenant_id`, `chain`, `valid`, `checked`, `broken_event_id`, `reason`, `trigger`, timings).
  - `status()`: FAIL if any chain's latest result is broken; STALE if a chain was never verified or its latest result is older than `PEOPLEOS_AUDIT_VERIFICATION_MAX_AGE_HOURS` (48); otherwise PASS.
  - The readiness page reads the recorded results (`report(verifyAudit: false)`). `peopleos:readiness` still verifies live unless `--skip-audit`, and records the results.
- **Chain-head index** `audit_events (tenant_id, id)`. `AuditRecorder` reads the head through it (`forceIndex`):
  - Left to itself, MySQL kept a backward primary-key scan even with the index present. EXPLAIN ANALYZE for a quiet tenant: 26,585 rows examined in 13.4 ms, against 1 row in 0.09 ms through the index.
  - Under `FOR UPDATE` that scan read and locked other tenants' newest rows.
  - A MySQL test (race suite, test 9) captures the recorder's real query and asserts the plan uses the chain index and examines at most one row.
- **`peopleos:operations:prune`** (daily 03:45): `queue:prune-failed` and `queue:prune-batches` after `PEOPLEOS_FAILED_JOBS_RETENTION_HOURS` (720).
- **Warehouse feed:**
  - `warehouse.export_enabled` (default **off**) is required by `WarehouseExport::run` and by the command.
  - `warehouse.retention_days` (default 30; 0 keeps) is applied by the retention purge to `warehouse/<slug>/<date>` folders of the bound tenant only.
  - Both are set on Security & retention policy (`security.manage`). These are operational defaults; legal retention (D-8) is open.
- **Retention purge:** deletes at most 1,000 rows per statement (notification deliveries, AI interactions, webhook deliveries; idempotency keys as before). Each pass deletes the matching rows up to the 1,000th matching id. The SQLite probe measured 20,000 rows in 255 ms, against 101 ms for the former single delete: a short statement, not a faster total, is the point on MySQL.

## 6. Operator console
- **Platform operations** (`/admin/platform-operations`, platform operators only):
  - **Platform-wide:** runtime health (database, cache, queue, failed jobs, scheduler, integrations), the platform chain's latest verification, and failed jobs grouped by job class (display name only, never the payload; newest 1,000).
  - **Per tenant, attention first:**
    - status, and since when and from what;
    - deferred jobs;
    - notifications failed (7 days) and stuck;
    - failing webhook endpoints;
    - inbound dead letters;
    - a first administrator without an invitation they can accept;
    - the latest audit-chain verification.
  - **Data use:** one grouped query per figure. Counts and states only: no tenant record, person, message, URL or secret.
  - **Action:** Release deferred work (operator, reason, both chains).
- **Platform audit trail** (`/admin/platform-audit`, platform operators only):
  - The platform chain only. A tenant chain event is never listed and cannot be opened by id.
  - Filters: action, tenant concerned (`metadata.subject_tenant_id`), and a date range on indexed columns (`tenant_id + occurred_at`).
  - The detail shows reason, source, request id, metadata, before and after values, and the chains' latest verification.

## 7. Tenant isolation
- **Uniqueness:**
  - Every Filament `unique()` on a tenant-owned table is narrowed by `TenantUnique::rule()` (fail-closed without a tenant).
  - The architecture test allows only `tenants.slug`, the first administrator's `users.email` (platform pages), `users.email` (D-17) and `statutory_profiles.company_id` (a global id offered from the tenant's own companies).
- **Bypasses:** new uses of `bypass()` are allow-listed with reasons:
  - `DeferredTenantJobs` (rows always filtered by the tenant concerned);
  - `PlatformOperationsOverview` (grouped counts);
  - `PlatformAuditTrail` (`tenant_id IS NULL` only).
- **Read model:** the operations page is counts-only. An operator who needs a record enters the tenant (audited, SaaS.2).

## 8. Data model (additive)

| Migration | Change |
|---|---|
| `2026_10_29_100001_saas8_tenant_lifecycle_and_deferred_work` | `tenants.suspended_from` (string 16, null), `tenants.suspended_at`; `tenant_deferred_jobs` (tenant FK cascade, `job_class`, encrypted `payload`, `connection`, `queue`, `deferred_at`; index `(tenant_id, id)`) |
| `2026_10_29_100002_saas8_notification_delivery_recovery` | `notification_deliveries.resend_count`; index `(tenant_id, status, updated_at)` |
| `2026_10_29_100003_saas8_audit_verification_runs` | `audit_verification_runs` (platform-level; `subject_tenant_id` FK null on delete; indexes `(chain, subject_tenant_id, id)`, `finished_at`) |
| `2026_10_29_100004_saas8_audit_chain_head_index` | `audit_events (tenant_id, id)` |

All four were run UP → DOWN → UP on MySQL 8.4.

**Deployment order.**
- Run `php artisan migrate --force` **before** the SaaS.8 code serves requests or works the queue. The audit recorder names `audit_events_chain_head_index`, so on a database without it every audited write fails.
- **Rollback:** revert the code first, then roll back the migrations.

## 9. Configuration

| Key | Default | Meaning |
|---|---|---|
| `PEOPLEOS_NOTIFICATIONS_SENDING_LEASE_MINUTES` | 15 (min 5) | `sending` older than this lost its worker |
| `PEOPLEOS_NOTIFICATIONS_REQUEUE_AFTER_MINUTES` | 60 (min 15) | async `queued` older than this lost its job |
| `PEOPLEOS_HSTS_MAX_AGE` | 31536000 (0 = off) | HSTS on HTTPS responses |
| `PEOPLEOS_INVITATION_RESEND_SECONDS` | 60 | throttle on the first-administrator invitation |
| `PEOPLEOS_AUDIT_VERIFICATION_MAX_AGE_HOURS` | 48 | recorded verification older than this is STALE |
| `PEOPLEOS_FAILED_JOBS_RETENTION_HOURS` | 720 (min 24) | failed jobs and batches pruned after this |
| Tenant setting `warehouse.export_enabled` | false | the daily warehouse feed runs for this tenant |
| Tenant setting `warehouse.retention_days` | 30 | daily snapshots kept (0 keeps) |
| Permission `notification.resend` | system roles Tenant Super Admin (`*`) and Tenant HR Admin (`notification.*`) | send a failed delivery again |

## 10. Behaviour changes to know

| Change | Before | After |
|---|---|---|
| Warehouse feed | Exported for every tenant daily | Only tenants that enable it |
| Reactivating a suspended trial tenant | Became `active` | Back to `trial` |
| Suspended-tenant queued jobs | Lost | Run after reactivation |
| Tenant edit audit action | `UPDATE` (tenant chain) | `TENANT_PROFILE_CHANGED` (both chains) |
| Tenant create/edit form | Reason optional | Reason required |
| Operator preparing and approving a payroll run | Allowed | Refused (another person approves) |
| API limit for invalid keys | Per presented prefix / per token hash | Per IP |
| Readiness page audit check | Live re-hash on every load | Latest recorded verification (STALE if none in 48 h) |
