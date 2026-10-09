# SaaS.9: tenant data exit (offboarding, export, retention, legal hold, deletion)

Status: implemented on `feature/oct_1_phase_1` (SaaS.9, October 2026). Deletion is **off** in every environment
(`PEOPLEOS_TENANT_DELETION_ENABLED=false`) until the validation gate in the SaaS.9 final report is passed for production.
Scope and discovery: `docs/saas/SaaS-9-Discovery-and-Scope.md`. Operator procedures: `docs/operations/runbooks/`.
Decisions: ADR-0081 to ADR-0096 in `decision-register.md`. Invariants 88 to 101 in `security-invariants.md`.

## 1. What this adds

A customer that leaves PeopleOS is taken out in four separate, audited steps:

1. **Close.** The service ends: nobody signs in, API keys stop, queued work waits, the warehouse feed stops.
2. **Export.** The customer gets a complete, encrypted, signed copy of their data.
3. **Retain.** Data is kept for as long as an approved retention policy says. Legal holds keep what they cover for as long as they are active.
4. **Delete.** What is eligible is deleted under two-person control, then certified. Everything else is retained, protected or anonymised, with the reason recorded.

None of it is automatic. A platform operator runs every step and gives a reason. Deleting needs a second operator, a
cooling-off period and the environment switch.

## 2. State model (validated against the existing lifecycle)

The prompt proposed one linear chain of states (ACTIVE → OFFBOARDING_REQUESTED → … → DELETION_CERTIFIED). That chain is
not stored as one status, for three reasons:

- The tenant's own status already drives access (`Tenant::isAccessible`, SaaS.2/SaaS.8).
- Exports, holds and deletions overlap in time. One field would lose information.
- Eligibility is a computed fact, not a stored one. It changes when a hold, a policy or a payment changes.

| Prompt state | Where it lives in PeopleOS | Stored or derived |
|---|---|---|
| ACTIVE / SUSPENDED | `tenants.status` = `trial` / `active` / `suspended` (unchanged, SaaS.8) | stored |
| OFFBOARDING_REQUESTED | `tenant_offboardings.status = requested` (the tenant keeps working) | stored |
| OFFBOARDING_APPROVED / OFFBOARDING | `tenant_offboardings.status = closed` + `tenants.status = closed` (end of service) | stored |
| EXPORT_IN_PROGRESS / EXPORT_READY / EXPORT_DELIVERED | `tenant_exports.status` = `queued` / `generating` / `ready` (+ `delivered_at`) / `failed` / `expired` | stored per export |
| RETENTION | per category: `RETENTION_RUNNING` until the policy's date | derived (`DeletionPlanner`) |
| LEGAL_HOLD | `legal_holds.status` = `active` / `release_requested` (still holding) / `released` | stored per hold |
| DELETION_ELIGIBLE | per category: `ELIGIBLE`; per tenant: no gate | derived (`DeletionPlanner`) |
| DELETION_APPROVED / DELETION_SCHEDULED | `tenant_deletions.status = approved` with `scheduled_for` (cooling-off) | stored |
| DELETION_IN_PROGRESS | `tenant_deletions.status = in_progress` (+ `tenant_offboardings.deletion_started_at`) | stored |
| DELETED | `tenants.status = deleted` (tombstone row kept) + case `completed`, only when no customer data remains | stored |
| DELETION_CERTIFIED | `tenant_deletions.certificate` + `certificate_signature` + `certified_at` | stored |

Two tenant statuses are new: `closed` and `deleted`. Only offboarding sets them. Provisioning, the tenant form and
suspension refuse them. `TenantStatus::allowsAccess()` is true only for `trial` and `active`.

```
 tenants.status:  trial/active ⇄ suspended
                        │  close (case requested → closed)          reopen (until a deletion is planned)
                        ▼                                                ▲
                      closed ────────────────────────────────────────────┘
                        │  final deletion completed (no customer data left)
                        ▼
                      deleted  (tombstone: id, slug, name; the certificate and the exit's evidence stay)
```

Every transition goes through a service (`TenantOffboardings`, `TenantExports`, `LegalHolds`, `RetentionPolicies`,
`TenantDeletions`) and records:

- the actor, checked by `PlatformOperator::assert` in the service;
- a reason of 5 to 1,000 characters;
- a timestamp, and the effective date where one applies (`service_end_date`, `starts_on`, `effective_from`, `scheduled_for`);
- audit events on the tenant chain and the platform chain (`OffboardingAudit`);
- a conditional update or unique key (§11).

## 3. Components

| Piece | Responsibility |
|---|---|
| `Support/DataInventory` | Every table in the schema, classified exactly once: 36 categories, 304 category tables, 7 child tables, 29 platform tables, 12 tables with file columns. The categories carry: owner, action, export, anonymisable, exact storage prefixes, secret and excluded columns |
| `Support/SchemaGraph` | Foreign-key graph of the live schema; deletion order (children first) by strongly connected components; the few nullable edges inside cycles to clear first |
| `Services/TenantOffboardings` | The case: request, cancel, close, reopen, waive the export |
| `Services/TenantExports` + `ExportPackager` + `Jobs/GenerateTenantExport` | Export requests, generation (resumable), download links, passphrase reveal, delivery record, expiry sweep |
| `Services/RetentionPolicies` | Versioned, effective-dated, per-jurisdiction policies with maker-checker approval |
| `Services/LegalHolds` | Place, request release, approve or reject release; the exit lock `lockFor()` |
| `Services/DeletionPlanner` | Eligibility per category and per tenant (gates), dependency protection, the plan fingerprint |
| `Services/TenantDeletions` | Plan (maker), approve (checker), reject, cancel, retry, the scheduler's dispatch, external dependency records |
| `Services/DeletionExecutor` + `Jobs/ExecuteTenantDeletion` | The worker: claim, start under the lock, batches, verification, certificate |
| `Payments/Services/FinancialStanding` | Read-only facade: the open financial items that block deletion |
| `Subscriptions/Services/SubscriptionDirectory::continuing` | Read-only: subscriptions that still entitle the tenant (block closure) |
| `Console/Commands/OffboardingTick` | `peopleos:offboarding:tick`, every 15 minutes: export sweep, due deletions, stalled deletions |
| Pages | `PlatformOffboardingPage` (operators), `PlatformRetentionPoliciesPage` (operators), `DataExportPage` (tenant users holding `data.export`) |
| Route | `GET /offboarding/exports/{reference}/download`: signed, session-protected, rate-limited |

## 4. Data inventory

Every table of the schema belongs to exactly one place:

- a **category** (the unit of export, retention, holds and deletion);
- a category's **child tables** (pivots and dependants deleted with it);
- the **platform** (never deleted with a tenant).

`ArchitectureTest` fails on a new table that is not classified, or on a table classified twice. A table is never deleted
just because it has a `tenant_id`.

| Owner | Categories | Action in a deletion |
|---|---|---|
| Customer data (29) | identity, people, employment, organisation, attendance, leave, payroll, statutory, tax, banking, documents, performance, goals, learning, career, talent, compensation, assets, service desk, grievances, engagement, communication, notifications, workflows, configuration, analytics, integrations, api, audit | `delete`, or `anonymise` for identity when retained records still reference sign-in accounts. Each only when its retention has ended and nothing holds or needs it |
| Markedge's records (4) | billing, invoices, payments, subscriptions | **retain** always (Markedge's own financial and commercial evidence, under Markedge's retention) |
| Platform (2) | platform operational state (deleted), offboarding records (**retained**: the exit's evidence) | as stated |
| Derived copies (1) | warehouse exports (files only) | delete |

Never exported: credential and secret columns (passwords, remember tokens, TOTP secrets and recovery codes, API key
hashes, client secrets, webhook signing secrets), lookup hashes and inbound payload bodies.

The full table-by-table classification lives in code (`DataInventory::TABLES`, `CHILDREN`, `PLATFORM`, `FILES`). The
discovery report summarises it by category: sensitivity, dependencies, financial or legal significance, files and audit
references.

## 5. Export

- **Who.** A platform operator for any tenant (also after closure), or a tenant user holding `data.export` while the
  tenant is accessible and the user is active. Checked in `TenantExports::authorize`, not only in the page.
- **One official export at a time.** `tenant_exports.in_flight_key` is unique per tenant while an export is queued or
  generating. A second request, or two at once, joins the one in flight (MySQL race 8).
- **No export once a deletion is approved or has started.** The check runs under the exit lock (§11), so a package is
  never read while data is being deleted (MySQL race 3). A deletion that finds an export in flight refuses to start.
- **Generation** is asynchronous (`GenerateTenantExport`, tenant-aware, runs for closed tenants). The worker claims the
  export with a conditional update and a lease. It writes each table (JSON Lines, ids ascending, encrypted columns
  decrypted, secret columns removed) and each referenced or stored file. After each table it saves a checkpoint, and on
  resume it verifies every written file by sha256. A stopped worker is resumed by the sweep. After
  `export_max_attempts`, or on a storage failure, the export is `failed` with the reason. A retry restarts it cleanly.
- **Package.** A ZIP with each entry encrypted (AES-256). The passphrase is random, 32 characters, and stored encrypted.
  The package contains:
  - `manifest.json`: schema version, export reference, tenant id and slug, generated time, application schema, categories
    with per-table record counts, every file with its size and sha256, and missing files;
  - `manifest.sig`: HMAC-SHA256 under a key derived from the application key, per purpose;
  - `README.txt`.
- **Storage and access.**
  - The package sits on the private export disk, never on a public one.
  - A download is a signed link (`download_link_minutes`, default 5) bound to the person it was made for (`u`).
  - The link needs a signed-in session with every SaaS.2 session check, and is limited to 30 requests per minute.
  - The download is streamed with `Cache-Control: no-store`. Every download and every passphrase reveal is counted and
    audited.
  - The package is deleted at `expires_at` (`export_expiry_days`, default 7). The export row, its sha256 and its manifest
    stay.
- **Delivered.** A customer's own download records delivery. So does an operator's delivery record with a note (for a
  package handed over another way). One of these, or an operator's waiver of the export with a reason, is a deletion gate.

## 6. Retention policies (D-8: configuration, never invented)

`retention_policies` (platform table). Each row covers one category and one jurisdiction (ISO country or `*`), with:

- version, status (`pending` / `approved` / `rejected` / `withdrawn`), `effective_from` (today or later);
- `policy_months` and `policy_basis`;
- `legal_minimum_months`, with `legal_basis` and `legal_reference` (both required when a minimum is entered);
- the proposer and the decider, each with a reason.

Rules:
- Proposed by one operator, approved by another.
- A policy can never be below a configured legal minimum. With no minimum configured, the UI and the plan show
  `LEGAL_MINIMUM_NOT_CONFIGURED`. No minimum is assumed and no period is shipped.
- A category with no approved policy is **not deletable** (`RETENTION_POLICY_NOT_CONFIGURED`).
- Retention runs from the tenant's closure date. It uses the longer of the version in force at closure and the version
  in force today, so a later, shorter version never shortens what applied at closure. The tenant's own jurisdiction is
  used first, then `*`.
- Every evaluation is recorded in the plan with the version applied.

## 7. Legal holds

`legal_holds` records:
- scope: the whole tenant, or listed categories;
- reason, matter reference, `starts_on`;
- placed by and when;
- release requested by and when, with its reason;
- released by and when.

- **Placing** takes one operator. It protects at once and never destroys anything.
- **Releasing** is requested by one operator and approved by another. A hold awaiting release still holds.
- **Effect.** Nothing a hold covers is deleted: not by a tenant deletion (planner, approval, every batch) and not by the
  operational retention purge (every batch).
- **Race safety.** Placing a hold takes the exit lock. Each deletion batch and each purge batch takes the same lock and
  re-reads the holds before it deletes. A hold that commits first stops the next batch. A batch that commits first was
  already allowed (MySQL races 4 and 5).

## 8. Deletion eligibility and the plan

`DeletionPlanner::plan(tenant, case)` returns, for every category:
- its action: delete, anonymise, retain, or nothing to do;
- its reason code, for example `ELIGIBLE`, `RETENTION_RUNNING`, `RETENTION_POLICY_NOT_CONFIGURED`, `LEGAL_HOLD`,
  `PROTECTED_BY_DEPENDENCY`, `RETAINED_MARKEDGE_RECORDS`, `RETAINED_EXIT_EVIDENCE`, `NOTHING_TO_DELETE`;
- its retention date and legal minimum, and its holds;
- row counts per table, file counts, and what protects it.

Tenant-level gates, any of which blocks deletion:
- `TENANT_NOT_CLOSED`;
- `EXPORT_NOT_DELIVERED` (unless waived);
- `EXPORT_IN_PROGRESS`;
- `FINANCIAL_DRAFT_INVOICES` / `FINANCIAL_UNPAID_INVOICES` / `FINANCIAL_PAYMENTS_IN_PROGRESS` /
  `FINANCIAL_PAYMENT_EXCEPTIONS` / `FINANCIAL_REFUNDS_IN_PROGRESS`;
- `DELETION_IN_FLIGHT`.

**Dependency protection.** For every foreign key from a kept table into a table about to be deleted, an exact `EXISTS`
query looks for a real reference. Any category referenced by kept data is protected. Identity is the exception: it is
anonymised instead, because kept records such as payroll or audit must still point at a row. Protection repeats until
nothing changes.

**The fingerprint** is sha256 over each category's action, reason, tables and anonymised tables. The checker approves
exactly that plan. If the data, a policy, a hold or a dependency changed meanwhile, approval is refused and the operator
plans again.

## 9. Deletion

**Approval.**
1. The maker plans the deletion.
2. A different operator approves it, after checking that the fingerprint is unchanged and, under the exit lock, that the
   tenant is still closed, the holds are the same and no export is generating.
3. Approval creates the ordered steps and schedules the run after the cooling-off period (`cooling_off_days`, default 14).
4. The scheduler dispatches due deletions, but only when `deletion_enabled` is true.

**Steps, in order:**
1. Per category: its referenced files, then its child rows.
2. The references that close a foreign-key cycle are cleared. Only nullable edges inside a cycle are cleared (19 columns).
3. Every table is deleted, children first, in the topological order of the whole set. The audit chain takes its place
   in that order: before sign-in accounts, so `actor_id` is never nulled under a live chain.
4. Anonymisation (identity only, when it is retained but referenced).
5. The tenant's stored files under its exact prefixes.
6. A verification that no foreign key into a deleted table dangles.
7. External dependencies, recorded but never claimed.

**Each batch** is one short transaction:
1. take the exit lock;
2. re-read the holds for the step's category, and stop with `LegalHoldEncountered` if one now covers it;
3. delete up to `batch_size` rows by id;
4. record the processed count and the heartbeat.

Foreign keys stay enforced throughout.

**The audit chain.** It is deleted only by its own step:
1. The final event id and hash are recorded in the step and the certificate.
2. Rows are deleted newest first, so what remains is always a valid prefix of the chain.
3. The platform chain records the deletion.
4. Events for a tenant whose chain is gone go to the platform chain only.

**Resuming.**
- **Claiming.** The worker claims the run with a conditional update (`approved` → `in_progress`), so two workers produce
  one run (MySQL race 2).
- **Failure.** Any error marks the deletion `failed` with the exact message. Nothing is claimed complete.
- **Retry.** An operator's retry re-plans. Finished steps are not repeated. A category that changed since approval is
  skipped, with the reason. A category already emptied is marked done (MySQL race 11).
- **Stalled workers.** A worker that stopped is marked failed by the tick once its lease expires.

**Final or partial.**
- **Final:** if no customer data remains (anonymised accounts aside), the tenant becomes `deleted` and the case
  `completed`.
- **Partial:** otherwise the tenant stays `closed`, with what remains and why listed in the certificate. A later deletion
  removes it once retention ends.

**Soft or hard.** Deletion is a hard delete, row by row. There is no soft-deleted copy of customer data. Retained
categories are kept as they are. The tenant row is a tombstone.

**Anonymisation.** It applies only to `users`, only when the identity category is otherwise deletable and kept records
reference it, and never when a hold covers identity. Personal columns are replaced deterministically (for example
`deleted-user-{id}@deleted.invalid`), secrets and SSO links are cleared, and the account is suspended.

**External dependencies.**
| Dependency | Starting status |
|---|---|
| Backups | `EXTERNAL_DELETE_REQUIRED` |
| Each integration system | `EXTERNAL_DELETE_REQUIRED` |
| Webhook receivers | `EXTERNAL_DELETE_UNSUPPORTED` |
| Payment provider | `EXTERNAL_DELETE_UNSUPPORTED` |
| Delivered e-mail | `EXTERNAL_DELETE_UNSUPPORTED` |

An operator records `REQUESTED` or `CONFIRMED` with a reason. The update is conditional, and a confirmation is final.

## 10. Certificate

Issued when a run completes, signed (HMAC-SHA256), with its sha256 stored. It contains:
- tenant, offboarding, deletion and plan references;
- requested by / approved by, with times; started and completed times; attempts;
- the executing process;
- categories deleted (steps and counts), anonymised, and retained (reason, retention date, legal minimum, holds,
  protecting dependencies);
- active legal holds;
- the export (reference, sha256, manifest sha256, delivery) or the waiver;
- the audit chain's final event and hash;
- the verification result;
- external dependencies, with their status at issue;
- whether the deletion was final;
- `completed`.

The certificate is kept in `tenant_deletions`, part of the retained offboarding records.

## 11. Locks and concurrency

**Lock order.** One order everywhere, so no deadlock:
1. the commercial lock (`tenant_entitlement_profiles`), where needed;
2. the tenant row;
3. the open case row (`LegalHolds::lockFor`);
4. then the transition's own rows.

**What takes the exit lock:**
- closing (inside the commercial lock, re-checking subscriptions);
- reopening;
- placing a hold;
- planning and approving a deletion;
- requesting or retrying an export;
- starting a deletion, each deletion batch, and finishing it;
- each operational purge batch.

**Unique keys:**
- `tenant_offboardings.open_key`: one open case per tenant;
- `tenant_exports.in_flight_key`: one export in flight;
- `tenant_deletions.in_flight_key`: one deletion planned, approved, running or failed;
- `retention_policies (category, jurisdiction, version)`.

**Conditional updates.** Each status change is an update from the expected state: approve, reject, cancel, retry,
claim, release, waiver, delivery, external record.

**Subscriptions.** A closed or deleted tenant gets no new entitled subscription period. `CommercialSubscriptions`
checks this under the commercial lock, which closing takes too, so a closed tenant never has a continuing subscription
(MySQL race 7). Ending, cancelling and expiring still work.

**The tenants row and foreign keys.** Every insert into a tenant-owned table share-locks the `tenants` row (its foreign
key check). The exit lock holds that row exclusively for one short transaction at a time, so a transaction must never hold
another lock the exit-lock holder is waiting for while it inserts tenant rows. The case found: `TenantCommercialLock::run`
issued `INSERT IGNORE` on the profile row every time, which share-locked the tenants row and then waited for the profile
row that a closing held. That deadlocked a closing against a concurrent subscription change (race 7). It now inserts the
row only when it is missing (ac274fa).

This is a deliberate, scoped exception to the project rule "never lock a parent row that foreign keys point at". Exit
transactions are short, and every interleaving that matters is a MySQL race. The follow-up is a dedicated lock row
created outside transactions; see the final report §39.

**Forced interleavings.** Races 3, 6, 7 and 10 do not rely on timing alone. One side pauses at the exact point between
its checks and its write (a model event, a query, a transaction start), and the other side is released into that window.
Each pause waits on a file, never on a query inside the paused transaction, because a read would fix its snapshot early.

## 12. Integrations

| Area | Behaviour |
|---|---|
| Sign-in, API, sessions | Closing revokes the tenant's sessions. `isAccessible()` is false, so the API refuses keys and the panel refuses users |
| Queued work | Jobs for a closed tenant are deferred (SaaS.8 `DeferredTenantJobs`) and released on reopen. Exit jobs run (`RunsForSuspendedTenants`) |
| Warehouse | The feed is switched off at closure (its prior state is kept and restored on reopen). Snapshots are a deletable category. The purge respects holds |
| Billing and subscriptions | Closing is refused while a subscription continues: end it through the subscription lifecycle first. Open financial items (draft or unpaid invoices, payments in progress, exceptions, refunds) block deletion and are shown, never cleared. Markedge's commercial records are always retained. Nothing financial is silently cancelled |
| Employees | A tenant's exit is not an employee's exit: no employee lifecycle state changes, and no exit workflow, letter or notification is sent |
| Files | Referenced files (12 tables) and stored prefixes are inventoried, exported and deleted. Missing files are counted. Prefixes are exact, never wildcards (an architecture test enforces this) |
| Notifications | Notifications for a closed tenant are deferred like other jobs. The exit itself sends no e-mail to the tenant's people |

## 13. Authorisation

| Action | Who | Where enforced |
|---|---|---|
| Offboarding case, close, reopen, waive export | Platform operator | `PlatformOperator::assert` in `TenantOffboardings` |
| Place a hold / request release / approve release | Operator / operator / a *different* operator | `LegalHolds` |
| Propose / approve a retention policy | Operator / a *different* operator | `RetentionPolicies` |
| Plan / approve a deletion | Operator / a *different* operator | `TenantDeletions` (also the conditional update `requested_by != approver`) |
| Execute a deletion | The queued worker only, after approval, cooling-off and `deletion_enabled` | `DeletionExecutor::execute` |
| Request, download, reveal an export | Operator, or a tenant user with `data.export` on an accessible tenant | `TenantExports::authorize` |
| See the operator pages | Platform operators only | `canAccess` and `mount` (403) |
| See the export page | Tenant users with `data.export`, never operators | `DataExportPage::canAccess` |

No API route reaches offboarding, exports, holds, retention or deletion (`OffboardingPagesTest`).

## 14. Configuration (`config/peopleos.php` → `offboarding`)

| Key | Env | Default |
|---|---|---|
| `deletion_enabled` | `PEOPLEOS_TENANT_DELETION_ENABLED` | `false` |
| `cooling_off_days` | `PEOPLEOS_DELETION_COOLING_OFF_DAYS` | 14 |
| `export_required_before_deletion` | `PEOPLEOS_EXPORT_REQUIRED_BEFORE_DELETION` | `true` |
| `export_expiry_days` | `PEOPLEOS_EXPORT_EXPIRY_DAYS` | 7 |
| `download_link_minutes` | `PEOPLEOS_EXPORT_DOWNLOAD_LINK_MINUTES` | 5 |
| `export_lease_minutes` / `export_max_attempts` | `PEOPLEOS_EXPORT_LEASE_MINUTES` / `PEOPLEOS_EXPORT_MAX_ATTEMPTS` | 30 / 5 |
| `export_disk` | `PEOPLEOS_EXPORT_DISK` | `local` (private) |
| `batch_size` / `deletion_lease_minutes` | `PEOPLEOS_DELETION_BATCH_SIZE` / `PEOPLEOS_DELETION_LEASE_MINUTES` | 1000 / 30 |

Permission: `data.export` (run `php artisan peopleos:sync-permissions`). Schedule: `peopleos:offboarding:tick` every
15 minutes.

## 15. Data model

Six additive tables in migration `2026_10_30_100001_saas9_offboarding_export_retention`:
- `tenant_offboardings`
- `tenant_exports`
- `legal_holds`
- `retention_policies`
- `tenant_deletions`
- `tenant_deletion_steps`

`tenants.status` is a string column, so `closed` and `deleted` need no schema change.

**Rollback.** DOWN drops the six tables. It is safe only while no tenant is `closed` or `deleted`, because the previous
release does not know those statuses. Reopen closed tenants first. A deleted tenant cannot be undone.

## 16. Production safety

- Deletion is disabled by default. When disabled, the eligibility preview works but nothing is planned, approved,
  dispatched or executed.
- Tests use only fixtures they provision, in SQLite in-memory or the disposable `*_concurrency` MySQL database.
- The showcase and development databases were migrated, and no deletion was ever run against them.
- Enabling deletion for real tenants is an owner decision after the gate in the final report.
