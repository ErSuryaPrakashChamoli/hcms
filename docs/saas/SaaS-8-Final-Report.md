# PEOPLEOS — SaaS.8 FINAL REPORT

**Phase:** SaaS.8, discovery, architecture, implementation and validation (owner prompt of 8 October 2026).
**Branch:** `feature/oct_1_phase_1`. Not pushed, not merged, not deployed. SaaS.9 not started. SaaS.7 not reopened (no regression found).

**STATUS = COMPLETE (SaaS.8 scope: every acceptance check passed). A security blocker remains outside the code: a leaked credential still accepted in the local showcase database, owner action (section 30). PeopleOS is NOT production-ready.**

## 1. Executive Summary

**Scope.** SaaS.8 delivers **Platform Operations and Tenant Lifecycle Reliability**: the core of SaaS.1 Workstream 8 (operators run tenants without database access, with audit) and the Workstream 1 items SaaS.2 left open (the platform-chain viewer, tenant edits on the platform chain, scheduled audit verification, queue pruning).

**Discovery** checked the whole platform against the code. It found two P0 defects and a set of P1 gaps, all fixed here:
- **P0: cross-tenant inference.** Form uniqueness ignored the tenant, revealing other tenants' employee codes and headcount and breaking saves.
- **P0: silent data loss.** Queued work that reached a worker while its tenant was suspended was dropped.
- **P1 gaps:**
  - lifecycle actions authorised only by the page;
  - a reactivated trial tenant becoming active;
  - provisioning half-failing on a mail error;
  - notifications stuck forever with no resend;
  - no security headers (clickjacking);
  - an API rate limit anyone could exhaust with a known key prefix;
  - an unguarded workflow webhook payload;
  - an operator able to prepare and approve payroll alone;
  - no platform audit viewer, no per-tenant operations view, no scheduled audit verification;
  - an audit chain-head read that scanned and locked other tenants' rows;
  - a warehouse feed exporting every tenant's data daily, forever.

**Built:**
- **Lifecycle guards:** service-level operator guards and both-chain audit for every lifecycle operation, and a status that survives suspension.
- **Reliability:** deferred (never lost) suspended-tenant work; an atomic first-administrator invitation with an operator resend; a notification recovery sweep and an audited resend.
- **Security:** security headers, a rate limit that only verified keys can claim, the webhook payload guard, and payroll four-eyes without an operator exception.
- **Evidence and governance:** scheduled and recorded audit verification, queue pruning, an opt-in warehouse feed with expiring snapshots.
- **Operator console:** a Platform operations page and a Platform audit trail.

All of it uses existing patterns:
- the authorisation chain;
- both audit chains;
- fail-closed tenancy;
- conditional updates and row locks proven on MySQL;
- additive migrations.

No business value, legal requirement, price or tax rule was invented. Retention windows that law will decide (D-8) are configurable operational defaults.

**Validation.** Final runs on the end state:
- **Test suite (SQLite):** 1,450 tests: 1,336 passed, **0 failed**, 114 skipped (the MySQL-gated suites, 9 of them new), 16,983 assertions, 17 m 34 s.
- **MySQL 8.4:** **114 / 114 passed**, 630 assertions, 9 m 46 s. This includes 9 new races (each SaaS.8 transition, plus an EXPLAIN guard on the recorder's real chain-head query), with both audit chains verified after every race.
- **Mutation:** 44 of 44 SQLite authorisation and safety mutants and 7 of 7 MySQL concurrency mutants killed. Two MySQL mutants first survived; the races were strengthened until both were killed.
- **Browser:** 40 of 40 checks across operators, the tenant administrator and six personas, desktop and phone.
- **Accessibility:** axe WCAG 2.1 AA, 11 states, 0 violations.
- **Visual regression:** 120 of 120, no baseline changed.
- **Behavioural browser suite:** 74 of 74.
- **Migrations:** UP → DOWN → UP clean on MySQL.
- **Performance:** the readiness page went from 2,793 ms / 138 queries to 84 ms / 18 queries at 24k audit events; the chain-head read examines 1 row instead of 26,585.

**Not production-ready.** SaaS.8 was not meant to make PeopleOS production-ready, and it does not:
- the statutory gate is 0/24 verified;
- backup restore and DR are unverified;
- WS7 (export, offboarding, deletion) and WS9 are open;
- a leaked credential is still accepted in the local showcase database (section 30).

## 2. Starting Commit

`cc039cb` (docs: SaaS.7 final configuration gate report, runbook, ADR-0065..0069, invariants 73-77). Clean tree.

## 3. Ending Commit

- **Code and tests:** `f190adc` (test: SaaS.8 the readiness page records no verification when it loads).
- **Documentation:** `5ee9797`, plus the one-line correction of this section committed right after it.
- Thirteen code and test commits and two documentation commits. Not pushed, merged or deployed.

| Commit | Purpose |
|---|---|
| `5f77b8c` | fix: tenant-scoped uniqueness validation on tenant-owned tables (P0 inference) |
| `a08d322` | feat: tenant lifecycle integrity and deferred suspended-tenant work |
| `d982d7a` | feat: notification delivery recovery and audited resend |
| `899b432` | fix: security hardening (headers, verified API rate limit, webhook payload guard, payroll four-eyes) |
| `21c6660` | feat: scheduled audit verification, queue pruning, warehouse feed governance |
| `b8aa78e` | feat: platform operations page and platform audit trail (WS8 core) |
| `a91b0b3` | perf: audit chain-head index; OpenAPI describes the verified-key rate limit |
| `5079b96` | perf: Tenants list reads invited administrators once per request |
| `5120f8d` | perf: the chain-head read names the chain index; MySQL races for every new transition |
| `2403088` | perf: retention batches delete by id range |
| `3d89b7b` | fix: operations and audit trail tables (left-aligned links, untruncated flag) |
| `f39078a` | test: suspension and reactivation races read before either writes |
| `f190adc` | test: the readiness page records no verification when it loads (kills S38) |
| `5ee9797` | docs: SaaS.8 discovery and scope, architecture, runbook, ADR-0070..0080, invariants 78-87, browser evidence, this report |
| (next) | docs: this section's ending commits |

## 4. Discovery Findings

Full record: `docs/saas/SaaS-8-Discovery-and-Scope.md` (questions A to I, production-readiness snapshot, audit claims checked, ranked gaps).

**Method.** Four parallel read-only audits (lifecycle and operators; operations and production readiness; data governance and integrations; scale and tenant isolation). Every claim was then verified in code. One claim was verified with MySQL EXPLAIN.

**Corrected claims:**
- **Not confirmed:** "webhook secret revealed on the edit form". The secret is a hidden attribute.
- **Already documented as deferred:** "SSO nonce not verified" (SaaS.2 W3).

| Rank | Finding (verified) |
|---|---|
| P0 | Filament `unique()` ignores the tenant: employee and legal-entity codes checked across tenants (inference of codes and headcount; saves broken) |
| P0 | A suspended tenant's queued job was acknowledged and lost (`BindTenantContext` returned `null`) |
| P1 | `TenantSuspensions` accepted any actor; reactivation always wrote `active` |
| P1 | Provisioning audited on the tenant chain only; invitation e-mail failure escaped after the commit (half-provisioned tenant) |
| P1 | Tenant edits: optional reason, tenant chain only; no platform-chain viewer |
| P1 | Notifications stuck in `sending` / `queued` forever; failed ones could not be resent |
| P1 | No security headers (panel frameable); API limit bucket from the unverified presented prefix; workflow webhook payload unguarded; operator could prepare and approve a payroll run |
| P1 | No per-tenant operations or failed-job view; `audit:verify` never scheduled; readiness re-hashed every event per load; chain-head read scanned other tenants' rows (EXPLAIN: `PRIMARY`, backward scan, `FOR UPDATE`) |
| P1 | Warehouse feed exported every tenant daily without opt-in; snapshots never deleted |
| P2 | Failed jobs and batches never pruned; retention deletes unbatched |
| P2 / later | Audit filter shapes, in-memory audit export, `notifications` growth; `users.email` global (D-17); SSO slug collision; auto-increment ids |

**Customer self-service vs platform control:**
- **Customers:** users, roles, settings, retention windows, API keys, webhooks, and now the warehouse feed and resending failed notifications.
- **Operators:** tenant lifecycle, commercial configuration, readiness and operations.
- **Missing:** self-service signup, plan changes, export and deletion (WS6, WS7).

**Integration hub.** RMS stays outside PeopleOS. Only the Integration Hub contract crosses the boundary (architecture test), and no table, id, database or model is shared. Every webhook is signed with a stable delivery id and timestamp (replay-safe for receivers). Every inbound event is keyed and leased. Every queued tenant job carries its tenant (architecture test).

**Notifications.** All are transactional. No marketing channel or automation exists, and none was added.

**AI and search.** Unchanged:
- tenant-keyed caches;
- per-user AI limits;
- the operator bypass of permission checks is operator design (`Gate::before`), documented.

**Commercial regression.** None: billing, tax and payments were not touched. Missing configuration still refuses. No live payment.

**Cross-tenant inference** of employee count, pricing, invoices, tax, payments, configuration, usage and audit:
- employee count via codes: **closed**;
- commercial data: platform-only pages (tests);
- audit: tenant-scoped, and the platform trail shows the platform chain only;
- remaining low-level oracles (`users.email`, SSO slug, ids) are recorded in section 30.

## 5. Original Roadmap Alignment

SaaS.1 §23 minimum for a sales-led paid launch: WS1 → WS2 → WS3 → WS4 (billed metric) → WS5 → **WS7 → WS8 (core operations)** → WS9.

| Workstream | Status after SaaS.8 |
|---|---|
| WS1 Identity and platform foundation | Complete. G-PLAT-1 viewer and tenant edits on the platform chain, G-JOB-1 `audit:verify` scheduled and pruning: closed by SaaS.8 |
| WS2–WS3 | Delivered (SaaS.3–SaaS.6), enforcement in shadow mode |
| WS4 | Model and observation (SaaS.5); enforcement pending D-6 / D-7 / D-16 |
| WS5 | Delivered (SaaS.7); invoicing waits for B-4 / B-7 / B-8 / B-9 data |
| WS6 Signup | Not started (D-9, D-17, terms) |
| WS7 Export, offboarding, retention | Not started; **next (SaaS.9)**, needs D-8 |
| WS8 Control plane | **Core delivered** (lifecycle operations with audit, per-tenant health and failed-job view, platform audit trail). Dual control for lifecycle and operator roles wait for D-15; commercial reporting is P2 |
| WS9 Validation | Not started |

**Re-sequencing (ADR-0070).** The provisional labels in the SaaS.7 baseline (SaaS.8 signup, SaaS.9 offboarding, SaaS.10 control plane) are replaced:
- **SaaS.8:** WS8 core and the WS1 remainder. These are the foundations every WS7 operation needs.
- **SaaS.9:** WS7.
- **Afterwards:** WS6 signup.

## 6. SaaS.8 Scope Selected

| Item | Delivered |
|---|---|
| S1 Tenant-scoped uniqueness | `TenantUnique::rule()` on employee code (edit and hire wizard), legal-entity code, asset tag; architecture guard |
| S2 Deferred work | `tenant_deferred_jobs`, `DeferredTenantJobs`, `BindTenantContext` defers; released once on reactivation or by an operator |
| S3 Lifecycle integrity | `PlatformOperator` guard; `TenantSuspensions` status restore and deferred release; provisioning on the platform chain with a required reason; atomic invitation, `INVITATION_FAILED`, operator "Send invitation"; `TenantProfiles` for edits |
| S4 Notification reliability | `peopleos:notifications:recover`; "Send again" with `notification.resend` |
| S5 Security | `SecurityHeaders`; verified-key rate limit; workflow webhook `PayloadGuard`; payroll four-eyes without operator exception |
| S6 Integrity and hygiene | `audit_verification_runs`, daily `peopleos:audit:verify --scheduled`, readiness from records; `peopleos:operations:prune`; chain-head index; batched retention |
| S7 Governance | Warehouse feed opt-in and snapshot retention (Security & retention policy) |
| S8 Operator console | Platform operations page; Platform audit trail |

## 7. Scope Excluded

| Excluded | Reason |
|---|---|
| WS6 self-service signup | D-9 (sales-led first), D-17 (global e-mail), legal terms |
| WS7 tenant export, closing, deletion, legal hold, certificate | D-8 open; recommended as SaaS.9 |
| Closing / pending-deletion tenant states | Arrive with WS7 |
| Operator roles, dual control for lifecycle | D-15 open |
| Entitlement enforcement | D-6, D-7, D-16 |
| Backup automation, restore and DR exercise | Infrastructure and operator evidence |
| SSO hardening (nonce, PKCE, ID token) | SaaS.2 W3 |
| Audit partitioning / archive, 100k scale, per-tenant queue fairness | Need staging measurements with audit volume and many tenants |
| Script CSP | Needs Filament / Livewire nonces |
| RMS / recruitment | Out of PeopleOS by contract |
| SaaS.9 | Not started, as instructed |

## 8. Architecture

`docs/architecture/saas-8-platform-operations.md`. In short:

```
PROVISION ─► INVITE ─► VERIFY ─► MFA ─► ACTIVATE ─► CONFIGURE ─► SUBSCRIBE ─► OPERATE ─► SUSPEND ⇄ REACTIVATE
 operator+reason  atomic e-mail,                                                        status kept;  status restored;
 both chains      resend (locked)                                                       work deferred work released once
```

- **Operator guard:** in every lifecycle service.
- **Transitions:** conditional updates (never a lock on the tenants row).
- **Deferred work:** encrypted, released under row locks, at least once.
- **Notifications:** a recovery sweep with an unknown-outcome rule, and a person-only resend.
- **Audit integrity:** recorded and read, not recomputed per page.
- **Operator pages:** counts and platform-chain events only.

## 9. Domain Boundaries

| Domain | Added |
|---|---|
| Platform | `PlatformOperator`, `LifecycleRefused`, `TenantFirstAdministrator`, `TenantProfiles`, `TenantDeferredWork`, `PlatformOperationsOverview`, `TenantDeferredJob`; hardened `TenantSuspensions`, `ProvisionTenantAction` |
| Support/Tenancy | `DeferredTenantJobs`; `BindTenantContext` |
| Audit | `AuditVerificationRun`, `AuditVerifications`, `PlatformAuditTrail` |
| Notifications | `NotificationRecovery`; policy `resend` |
| Enterprise | Warehouse opt-in and purge; batched `Retention` |
| HTTP | `SecurityHeaders`; `ApiKeys::resolveFor` |
| Filament | `PlatformOperationsPage`, `PlatformAuditTrailPage`, `TenantUnique`; actions on Tenants, Delivery log, Security & retention policy |

**Untouched:** billing, tax, payments, plans, subscriptions, entitlements, HCM business logic (one payroll segregation-of-duties fix), RMS.

## 10. Database Changes

All additive, indexed, with foreign keys:

| Migration | Change |
|---|---|
| `2026_10_29_100001_saas8_tenant_lifecycle_and_deferred_work` | `tenants.suspended_from`, `tenants.suspended_at`; `tenant_deferred_jobs` (FK tenants, cascade; `(tenant_id, id)`) |
| `2026_10_29_100002_saas8_notification_delivery_recovery` | `notification_deliveries.resend_count`; `(tenant_id, status, updated_at)` |
| `2026_10_29_100003_saas8_audit_verification_runs` | `audit_verification_runs` (platform-level; FK `subject_tenant_id` null on delete; `(chain, subject_tenant_id, id)`, `finished_at`) |
| `2026_10_29_100004_saas8_audit_chain_head_index` | `audit_events (tenant_id, id)` |

## 11. Security Model

Every new action follows the same chain:
- authentication;
- tenant context, or a platform operator with no tenant;
- authorisation in the domain service, not only the page;
- scope;
- audit on both chains where the platform is involved;
- a reason where sensitive;
- concurrency safety (conditional update or row lock, proven on MySQL).

| Action | Authentication / context | Authorisation (service) | Reason | Audit | Concurrency |
|---|---|---|---|---|---|
| Provision tenant | Operator (web) / console | `PlatformOperator` | Required on web | Tenant + platform | Unique slug (race 8) |
| Edit tenant | Operator | `PlatformOperator` | Required | Both | Model save |
| Suspend / reactivate | Operator | `PlatformOperator` | Required | Both | Conditional update (races 3, 4) |
| Send first-admin invitation | Operator | `PlatformOperator` | Required | Both | User row lock + throttle (race 7) |
| Release deferred work | Operator | `PlatformOperator` | Required | Both | Row locks (race 1) |
| Defer / release jobs | Worker / reactivation | System (tenant from the job) | — | Count on reactivation | Row locks; re-check after insert (race 2) |
| Resend notification | Tenant user in its tenant | `notification.resend` + policy + tenant match | Required | Tenant | Conditional update (race 5) |
| Recovery sweep | Scheduler, per tenant | System | — | — (operational) | Conditional touch (race 6) |
| Warehouse feed | Tenant setting (`security.manage`) | Service refuses without opt-in | — | Settings audited | — |
| Operations / audit trail pages | Operator | `canAccess` + `mount` abort | — | Read-only | — |

Security headers, the verified-key rate limit, `PayloadGuard` on workflow webhooks and payroll four-eyes: section 4 of the architecture; invariants 83 and 84.

## 12. Tenant Isolation

- **Uniqueness:** checked within the tenant (`TenantUnique`, fail-closed). The architecture test allows only documented global constraints. Two-tenant tests cover hire and edit.
- **Deferred jobs:** rows carry their tenant. A release filters by tenant (a test proves another tenant's work stays). Jobs re-bind their own tenant.
- **Notifications:** a resend refuses a delivery of another tenant (scope, plus an explicit tenant match).
- **Operator pages:** grouped counts only. No record, person, message, URL or secret: a test asserts the page excludes stuck-message subjects, recipient addresses, webhook URLs and secrets. The audit trail is platform-chain only and cannot open a tenant event by id.
- **Bypasses:** three new `bypass()` users, allow-listed with reasons; `PlatformReadiness` no longer bypasses.
- **Unchanged:** fail-closed tenancy and every earlier isolation test (full suite green).

## 13. Authorization

- **Platform operators** (`is_platform_admin`, no tenant, MFA): every lifecycle service checks it itself. A web request naming no operator is refused whoever is signed in (test with the console flag off).
- **Tenant users** keep the PeopleOS chain: AUTH → TENANT → ROLE → PERMISSION → ORG SCOPE → RELATIONSHIP SCOPE → FIELD SECURITY → RECORD.
  - New permission `notification.resend` (synced). The system roles Tenant Super Admin (`*`) and Tenant HR Admin (`notification.*`) hold it; HR Manager and the other roles do not.
  - The new platform pages are 403 for every tenant persona.
- **Segregation of duties:** payroll approval now refuses the preparer even when the preparer is an operator.

## 14. Audit

**New actions:**
- `TENANT_PROFILE_CHANGED`;
- `INVITATION_FAILED`;
- `DEFERRED_WORK_RELEASED`;
- `NOTIFICATION_RESENT` (tenant chain).

**Now also on the platform chain:**
- `TENANT_PROVISIONED`;
- `INVITATION_ISSUED` (first administrator);
- `TENANT_PROFILE_CHANGED`.

**Content rules:**
- no personal data on the platform chain (no e-mail; exception class only);
- no message content in `NOTIFICATION_RESENT`.

**Verification:**
- recorded per chain, daily;
- readiness FAIL on a broken chain, STALE after 48 hours.

**Chains intact:** every MySQL race asserts both chains intact afterwards.

## 15. Configuration

| Key | Default |
|---|---|
| `PEOPLEOS_NOTIFICATIONS_SENDING_LEASE_MINUTES` | 15 |
| `PEOPLEOS_NOTIFICATIONS_REQUEUE_AFTER_MINUTES` | 60 |
| `PEOPLEOS_HSTS_MAX_AGE` | 31536000 |
| `PEOPLEOS_INVITATION_RESEND_SECONDS` | 60 |
| `PEOPLEOS_AUDIT_VERIFICATION_MAX_AGE_HOURS` | 48 |
| `PEOPLEOS_FAILED_JOBS_RETENTION_HOURS` | 720 |
| Tenant `warehouse.export_enabled` | false |
| Tenant `warehouse.retention_days` | 30 |

These are operational defaults, not legal periods. D-8 remains the owner's decision.

## 16. Events/Jobs/Queues

- **Deferral:** `BindTenantContext` defers instead of dropping. `RunsForSuspendedTenants` jobs still run.
- **New schedule entries** (all `withoutOverlapping` and `onOneServer`; inventory test):
  - `peopleos:audit:verify --scheduled` daily 02:30;
  - `peopleos:operations:prune` daily 03:45;
  - `peopleos:notifications:recover` every 5 minutes, per tenant through `TenantRunner`.
- **Warehouse feed:** `peopleos:warehouse:export` skips tenants without the opt-in.
- **Tenant context:** every queued tenant job still carries its tenant (architecture test). Deferred jobs keep it, encrypted.
- **Webhooks:** unchanged and replay-safe for receivers (stable delivery id, timestamped signature). A deferred `SendWebhook` keeps its delivery id.

## 17. UI/Admin Changes

| Who | Where | What |
|---|---|---|
| Operators | Platform → **Operations** | Health, platform-chain verification, failed jobs by class; per-tenant attention list; Release deferred work |
| Operators | Platform → **Platform audit trail** | Platform chain with filters and before/after |
| Operators | Tenants | Reason required on create and edit; **Send invitation**; reactivation restores trial |
| Operators | Platform readiness | Audit check from recorded verification |
| Tenant administrators | Communication → Delivery log | **Send again** on failed deliveries (unknown-outcome warning) |
| Tenant administrators | Security & retention policy | **Data warehouse feed**: enable, snapshot days |
| Everyone | Employee / legal-entity forms | Duplicate codes checked within the organisation only |

## 18. API/Integration Changes

- **Rate limit:** per verified key, otherwise per IP. The OpenAPI description was regenerated (`peopleos:openapi`).
- **Security headers:** added to API responses.
- **Workflow webhook payloads:** cleaned by `PayloadGuard`.
- **Unchanged:** API shapes, scopes, idempotency, inbound events. RMS stays external, with no new dependency.

## 19. Operational Changes

Runbook: `docs/operations/tenant-operations-runbook.md`:
- provision, invite, suspend, reactivate, edit;
- daily checks on Operations;
- audit trail and integrity incidents;
- what tenant administrators gained.

The queue and scheduler inventory was updated (`docs/operations/queue-and-scheduler.md`), and so were the security boundaries (`docs/production/security-boundaries.md` §4 and §5).

## 20. Migration/Rollback

- **Tested on MySQL 8.4** (disposable `hcm_saas6_concurrency`, migrate:fresh):
  - UP applied all four migrations.
  - `migrate:rollback --step=4` (DOWN) removed every SaaS.8 column, table and index (verified in `information_schema`).
  - UP again produced a schema identical to the first UP (column and index inventory diff: none).
- **Then:**
  - the 8090 showcase database (`hcm_ux_showcase`) was migrated additively (batch 8);
  - the development database `hcm` was migrated additively (batch 2; its first SaaS.8 migration had already been applied when someone else re-created it, see section 30);
  - permissions were synced on both (`notification.resend`).
- **Deployment order:** run the migrations before the new code serves traffic or works the queue. The audit recorder names the chain-head index, so without it every audited write fails. To roll back, revert the code first.

**Rollback:**
- `php artisan migrate:rollback --step=4` removes every SaaS.8 column, table and index (verified on MySQL).
- **Data lost on rollback:** deferred jobs not yet released, resend counts, verification history and suspension memory. The audit events stay.
- **Code rollback:** revert the SaaS.8 commits. The behaviour changes listed in the architecture §10 return to their old form (including the warehouse feed for every tenant, and dropped jobs for suspended tenants).

## 21. Test Results

| Run | Result |
|---|---|
| **Full suite, final** (SQLite, `--parallel --processes=2`, end state) | 1,450 tests: 1,336 passed, **0 failed**, 114 skipped (the MySQL-gated suites, 9 of them new), 16,983 assertions, 17 m 34 s |
| Full suite, first (after the feature commits) | 1,441 tests: 1,336 passed, 0 failed, 105 skipped (MySQL-gated), 16,983 assertions, 19 m 35 s |
| SaaS.8 feature tests (`tests/Feature/Operations`) | 7 files, 40 tests (plus 1 new architecture test): uniqueness 4, deferred work and lifecycle 7, provisioning integrity 7, notification recovery 6, security hardening 4, operations hygiene 7, platform pages 5 |
| Architecture | New guards: Filament `unique()` must be tenant-scoped; deferred-job and verification models in the allow-lists; three new `bypass()` users reviewed; scheduler inventory includes every new command |
| Adapted existing tests | `TenantProvisioningMetadataTest` (platform-chain provisioning, `TENANT_PROFILE_CHANGED`), `EnterpriseTest` and `IntelligenceTest` (warehouse opt-in), `ArchitectureTest` (allow-lists and the new uniqueness guard); `docs/operations/queue-and-scheduler.md` inventory (read by `OperationsHardeningTest`) |

**How the tests were proven to bite:**
- the uniqueness tests failed with the original rule (2 of 4 failing);
- the suspension, invitation and resend tests failed under the matching mutants (section 24).

## 22. MySQL Results

- **Full MySQL suite, final** (disposable `hcm_saas6_concurrency`, pcntl fork harness, end state): **114 / 114 passed**, 630 assertions, 9 m 46 s.
- **Earlier runs:** 113 / 113 before the index hint, 114 / 114 after it.
- **New file:** `tests/MySql/PlatformOperationsConcurrencyTest.php`, 9 tests.
- **Migrations:** UP → DOWN → UP on MySQL 8.4 (section 20).
- **EXPLAIN evidence** (26,827 audit events, 115 tenants), for the quietest tenant's chain-head read:

| Plan | Rows examined | Time |
|---|---|---|
| Default (backward primary-key scan) | 26,585 | 13.4 ms |
| Through `audit_events_chain_head_index` | 1 | 0.09 ms |

Laravel's `forceIndex` now makes the recorder use the index. Test 9 captures the recorder's real query and asserts the plan.

## 23. Concurrency Results

| # | Race | Expected and observed |
|---|---|---|
| 1 | Reactivation release and an operator release at once (5 deferred jobs) | Each job runs once (5 companies, 0 rows left) |
| 2 | A job deferred while the tenant is being reactivated | Runs exactly once; nothing stranded |
| 3 | Two operators reactivate at once (asymmetric reads before writes) | One transition, trial restored, one audit pair; the other reports no change |
| 4 | Two operators suspend a trial tenant at once (every query paused) | One transition, `suspended_from = trial`, one platform event |
| 5 | Two administrators resend one failed delivery | One resend, `resend_count = 1`, sent, one audit event |
| 6 | Two recovery sweeps at once | Lost delivery dispatched once (requeued sum 1), stuck one failed once |
| 7 | Two operators send the first administrator's invitation | One invitation; the other refused ("moments ago"); one platform event |
| 8 | Two operators provision the same slug | One tenant; one platform `TENANT_PROVISIONED` |
| 9 | Chain-head plan | `audit_events_chain_head_index`, at most 1 row examined |

Every race asserts the tenant chain and the platform chain intact afterwards (`AuditIntegrityVerifier`).

## 24. Mutation Results

Run on a private copy of the tree (8090 serves the working tree), one mutant at a time, each against the tests named for it.

**SQLite authorisation and safety mutants: 44 of 44 killed.**

| Area | Mutants | Killed |
|---|---|---|
| Operator guard (bypass, web without an operator, reason) | S01–S03 | 3 |
| Provisioning (guard, reason, platform chain); tenant edits (guard, status) | S04–S08 | 5 |
| Suspension memory, reactivation release | S09–S11 | 3 |
| Deferred work (dropped again, reconciliation deferred, release while suspended, rows kept, stranded deferral, clear payload, tenant-user release) | S12–S18 | 7 |
| Tenant-scoped uniqueness (rule removed, no filter, fail-open) | S19–S21 | 3 |
| Notifications (no permission, cross-tenant, no reason, fresh sending swept, stuck resent automatically) | S22–S26 | 5 |
| Security (headers off, framing, HSTS on HTTP, unverified bucket, payload guard, payroll four-eyes) | S27–S32 | 6 |
| First-admin invitation (not atomic, no throttle, while suspended) | S33–S35 | 3 |
| Audit verification (broken hidden, stale hidden, page re-hashes) | S36–S38 | 3 |
| Warehouse (no opt-in, never purged) | S39–S40 | 2 |
| Platform pages (operations or audit open to tenants, tenant chain listed or opened) | S41–S44 | 4 |

**MySQL concurrency mutants: 7 of 7 killed.**

| Mutant | Result |
|---|---|
| K1: release without row locks | Killed (10 runs instead of 5) |
| K2: resend not conditional | Killed |
| K3: requeue without the conditional touch | Killed |
| K4: invitation send without the row lock | Killed |
| K5: suspension not conditional on the status read | Killed, after strengthening race 4 |
| K6: reactivation not conditional | Killed, after strengthening race 3 |
| K7: chain-head read without the index | Killed (plan `audit_events_tenant_id_occurred_at_index`) |

**K5 and K6 survived the first run** because the races never had both writers read before either wrote. K6 also hid behind MySQL's changed-rows count: identical values written in the same second report 0 rows. The races were fixed (f39078a), not the mutants.

## 25. Browser Results

Evidence: `docs/saas/evidence/SaaS-8-browser-validation.json`.

**Setup:**
- disposable MySQL database `hcm_saas8_ui_showcase` (UxShowcaseSeeder plus a fictional SaaS.8 fixture), dropped afterwards;
- Chromium with Playwright 1.62, desktop 1440×900 and phone 390×844 (touch);
- two platform operators signing in with password and TOTP (generated into a 0600 scratch file, never printed, deleted afterwards).

**Result: 40 of 40 checks passed.**
- **Security headers:** present on the sign-in page; no HSTS over HTTP.
- **Operator, Operations:**
  - shows the suspended trial tenant "since … (was trial)" with 1 deferred job and the tenant whose invitation failed;
  - no message content, URL or secret.
- **Reactivation** from the Tenants list: back to **Trial**, and the deferred job dispatched again.
- **Send invitation:** offered and sent.
- **Platform audit trail:**
  - filtered by action;
  - the reactivation listed with its reason;
  - detail shows suspended → trial;
  - no tenant-internal events.
- **Readiness:** reads the recorded verification.
- **Operations and audit trail on a phone:** no horizontal page scroll.
- **Tenant administrator:**
  - "Send again" offered on the failed delivery and not on the one still sending; resent;
  - Security & retention policy shows the Data warehouse feed, off.
- **Employee, manager, HR, executive, administrator, payroll (desktop and phone):**
  - every platform page returns 403 (Operations, Platform audit trail, Tenants);
  - no platform navigation.
- **8090 showcase after its migration:**
  - employee, HR and administrator sign in;
  - Home, People, Employees, the delivery log and Security & retention policy return 200;
  - an audited save succeeded (`LOGIN` and `CREATE` events written through the indexed chain-head read).

**Script defects found and fixed during the run:**
- a notice read without waiting;
- an upper-cased heading compared case-sensitively;
- the panel's 5-per-minute sign-in limit.

The product found by the browser pass: centred row links and a truncated flag, fixed in 3d89b7b.

## 26. Accessibility Results

**axe-core 4.13, WCAG 2 A/AA and 2.1 A/AA: 11 states, 0 violations.**

States checked:
- Platform operations (desktop, phone);
- Tenants list with the Reactivate modal;
- Platform audit trail with an event open (desktop) and on a phone;
- delivery log with the Send again modal;
- Security & retention policy;
- after the table polish: both platform pages again at desktop and phone.

**Transient:** one colour-contrast node on the Tenants Reactivate modal in the first run, captured while the modal was still opening. It did not reproduce in the two later runs.

**Markup:** the new tables have captions and column headers (`scope="col"`); filters are labelled controls.

## 27. Visual Results

- **Visual regression suite** (UX.18 harness, frozen showcase on 8092, Chromium phone, tablet and desktop, Firefox, WebKit): **120 passed, 0 failed, 0 flaky**, 198 skipped by design (screen and project combinations a shot does not declare, as in UX.18). **No baseline was updated.**
- **Behavioural browser suite** (Back closes overlays, focus order, aborted calls, UX.19 keyboard checks; Chromium, Firefox, WebKit): **74 passed, 0 failed**, 6 skipped by design.
- **New platform pages:** screenshots reviewed (scratch). They follow the existing platform-page layout: sections, tables, badges.

## 28. Performance Results

Same probe, same machine, SQLite in-memory, before (`cc039cb`) and after (end state):

| Measurement | Before | After |
|---|---|---|
| Platform readiness page, 5 tenants / 2k audit events | 346 ms, 28 queries | 75 ms, 18 queries |
| Platform readiness page, 20 tenants / 8k events | 1,227 ms, 58 queries | 81 ms, 18 queries |
| Platform readiness page, 20 tenants / 24k events | 2,793 ms, 138 queries | 84 ms, 18 queries |
| Tenants list, 5 / 20 tenants | 86 / 98 ms, 11 queries | 81 / 95 ms, 12 queries (one grouped invitation query; an earlier per-row version was replaced) |
| Platform operations page (new), 5 / 20 tenants | — | 73 / 93–106 ms, 24 queries (flat) |
| Platform audit trail page (new), 2k / 24k events | — | 63 / 89 ms, 10 queries (flat) |
| API `GET /v1/employees` (median of 5) | 2.7 ms, 4 queries | 3.3 ms, 4 queries (key resolved once, shared by limiter and authentication) |
| Retention purge, 5,000 / 20,000 old deliveries | 30 / 101 ms, 8 queries | 49 / 255 ms, 21 / 51 queries |
| Audit chain-head read on MySQL (quietest of 115 tenants, 26,827 events) | 26,585 rows, 13.4 ms | 1 row, 0.09 ms |

**Purge trade-off.** The purge is now slower in total on SQLite, by design: no statement deletes more than 1,000 rows, which bounds lock time on MySQL. A first batching version (a list of 1,000 bound ids) measured 677 ms and was replaced by id-range batches.

## 29. Production Readiness Impact

**Improved:**
- no silent loss of suspended-tenant work;
- invitations and notifications recoverable without database access;
- clickjacking closed;
- the API limit cannot be exhausted by a stranger;
- scheduled integrity evidence;
- pruned queue tables;
- an opt-in, expiring warehouse feed;
- an operator console with audit;
- cross-tenant inference via codes closed;
- audit write path no longer contends across tenants.

**Unchanged blockers:**
- statutory gate 0/24 verified;
- backup restore and DR unverified (operator evidence);
- WS7 (export, offboarding, deletion) missing;
- WS9 not done;
- entitlements not enforced;
- commercial inputs B-4/B-7/B-8/B-9 missing;
- the credential in section 30.

**PeopleOS is NOT production-ready**, and SaaS.8 does not claim otherwise.

## 30. Remaining Risks

| Risk | Notes |
|---|---|
| **Leaked credential (security blocker, owner action)** | **Release blocker: owner action.**
- **Re-checked today with a counts-only script** (no value or hash printed):
  - `admin@fynnedge.com` in the local showcase database `hcm_ux_showcase` (served on 8090) still accepts the leaked value;
  - `platform@markedge.local` in the same database accepts the demo fixture word.
- **Development database `hcm`:** after its re-seed (next row), no account accepts either.
- **Not done by SaaS.8:** no replacement credential was created in code, seeders, fixtures or docs.
- **Owner action:** rotate the leaked value wherever it was used, and remove or reset those showcase accounts. |
| Dev database recreated outside this work | - **What happened:** the development database `hcm` was dropped, re-migrated and re-seeded at 17:21 IST on 8 October by something other than SaaS.8's commands. Those target SQLite in-memory or disposable `*_concurrency` / `*_showcase` databases; this session's command history shows no `migrate:fresh` or seed against `hcm`. It already contained the first SaaS.8 migration, which existed in the working tree at the time.
- **Since then:** SaaS.8 applied the remaining three migrations additively. |
| `users.email` global (D-17) | Existence oracle through the user form, SCIM and SSO; SSO auto-provision collision returns 500 |
| SSO slug global; public redirect confirms slugs | Low |
| Global auto-increment ids | Reveal platform-wide volume over time (low) |
| Audit at volume | Filters (`whereDate`, `DISTINCT module`), in-memory audit export, no archive; not measured with large audit volume |
| Many tenants | Scheduled sweeps loop tenants serially on one queue; no per-tenant fairness |
| Deferred work is at least once | A failed commit after dispatch can repeat a job; PeopleOS jobs are idempotent, a future non-idempotent job must claim its work |
| Scheduled work during suspension | Not deferred (re-derived from state); e.g. a reminder window that passes while suspended is not replayed |
| Platform operators are equal | No roles or dual control for lifecycle actions (D-15) |
| Script CSP absent | Only framing is restricted |

## 31. Deferred Items

- **WS7 (SaaS.9):**
  - tenant export;
  - closing, pending-deletion and deletion-blocked states;
  - deletion workflow with cooling-off, legal hold and certificate;
  - storage inventory;
  - retention policy (D-8).
- **WS6:** signup, after D-9 and D-17.
- **D-15:** operator roles and dual control.
- **Audit at volume:** archive or partitioning, filter indexes, streamed export.
- **Scale:** per-tenant queue fairness; 100k-employee and many-tenant measurements in staging.
- **SSO:** hardening (W3).
- **Script CSP.**
- **Production evidence:** restore and DR exercise; statutory verification.

## 32. Runbook

`docs/operations/tenant-operations-runbook.md`. Commands:

```
php artisan peopleos:audit:verify [--tenant=<slug>] [--scheduled]   # verify and record chains
php artisan peopleos:notifications:recover [--tenant=<slug>]         # stuck / lost deliveries
php artisan peopleos:operations:prune                                # failed jobs and batches
php artisan peopleos:warehouse:export [--tenant=<slug>]              # opted-in tenants only
php artisan queue:failed | queue:retry <id>                           # failed jobs (shown by class on Operations)
```

## 33. Decision Register Changes

ADR-0070 to ADR-0080 (`decision-register.md`, "SaaS.8 decisions"):
- 0070 re-sequencing;
- 0071 service-level lifecycle authorisation;
- 0072 suspension memory;
- 0073 deferred work;
- 0074 atomic invitation and resend;
- 0075 notification recovery;
- 0076 headers, rate limit, payload guard, payroll four-eyes;
- 0077 recorded audit verification and chain-head index;
- 0078 warehouse opt-in;
- 0079 tenant-scoped uniqueness;
- 0080 counts-not-records operator console.

Security invariants 78 to 87.

## 34. Exact Next Phase Recommendation

**SaaS.9: Data Export, Offboarding and Retention (SaaS.1 WS7)**, once the owner decides **D-8**:
- the retention after subscription end;
- statutory and payroll record handling at deletion;
- Markedge's commercial record retention.

Until D-8 is decided, keep those values configurable rather than invented.

**Scope:**
- tenant export (requested → approved → running → ready → expired, encrypted, permissioned, audited);
- tenant states `closing`, `pending_deletion`, `deletion_blocked`, `deleted` treated like `suspended` by `BindTenantContext` and `TenantRunner`;
- the deletion workflow with cooling-off, legal hold, per-domain purge and a certificate;
- storage inventory;
- DR tombstones.

It builds directly on SaaS.8:
- the operator guard;
- deferred work, which must also cover the new non-accessible states;
- the operations page;
- the platform audit trail.

**Before SaaS.9:** the owner should rotate or remove the leaked credential (section 30).

---

*STOP after this report.*
