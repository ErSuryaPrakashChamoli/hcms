# PEOPLEOS — SaaS.9 FINAL REPORT

Data export, offboarding and retention (WS7). Branch `feature/oct_1_phase_1`, from `ae35542`. Not pushed, merged or
deployed.

> **Update, 9 October 2026 (SaaS.9 closure).** The credential described below was rotated in every local PeopleOS
> database, with yes/no evidence, and the GitHub repository holding its history was found to be public. Retention
> verification, customer contract terms and external-copy statuses were added. See
> `docs/saas/SaaS-9-Closure-and-Release-Readiness-Report.md`. The rest of this report records SaaS.9 as delivered.
>
> **SaaS.9 STATUS: COMPLETE** for its scope: every item of the acceptance gate passes (§29–§37).
>
> **RELEASE STATUS: BLOCKED.** Deletion stays switched off until the owner decides, and the inherited credential blocker
> below is still active.
>
> **Security blocker (inherited, still active, not a SaaS.9 defect).** In the showcase database `hcm_ux_showcase`
> (served on 8090), two accounts still accept known credentials:
> - `admin@fynnedge.com` accepts the leaked value from the git history;
> - the platform operator `platform@markedge.local` accepts the fixture word.
>
> Checked on 9 October 2026 with a counts-only script; no value was printed. The development database `hcm` is clean.
> This phase did not recreate, print or replace any credential. It must be rotated, and the history value treated as
> compromised, before any release. See §27 and §38.

## 1. Executive Summary

SaaS.9 builds the tenant data exit in four audited steps:

1. **Close** a customer at the end of service.
2. **Export** all of its data to it: encrypted, signed, resumable.
3. **Retain** what an approved retention policy or a legal hold requires.
4. **Delete** what is eligible, with two operators, a cooling-off period, an environment switch, safe batches,
   resumption and a signed certificate.

Every table of the schema (340) is classified exactly once. Nothing is deleted just because it belongs to the tenant:
- Markedge's invoices, payments, billing and subscription records are always kept.
- Open financial items block deletion and are never cleared.
- No retention period or legal minimum is invented.
- A category without an approved policy is never deleted.

Deletion is **off** in every environment (`PEOPLEOS_TENANT_DELETION_ENABLED=false`). It was never executed against
production, the showcase or development. Fixture tenants were deleted only in SQLite test databases, the disposable
`*_concurrency` MySQL databases and the disposable browser fixture database.

Validation:
- full SQLite suite 1,382 passed, 0 failed (1,508 tests; the 126 skipped are the MySQL-gated tests);
- full MySQL suite 126/126, including the 11 exit races and a large-row test;
- 53/53 authorisation and safety mutants and 13/13 concurrency mutants killed;
- 39/39 browser checks with axe clean in every state;
- visual regression 120/120, with no screen changed;
- performance measured at 100, 1,000 and 10,000 employees and a large document set.

The race design found and fixed real defects before any deployment:
- **No lock without an open case.** A tenant without an open case had no exit lock, so a hold could race the purge.
- **A closed tenant could subscribe again.** Nothing stopped a new subscription after closure.
- **Export and approval could overlap.** An export request and a deletion approval could both succeed.
- **Approval and planning could race a change.** Neither re-checked under a lock, so a reopen or a hold could slip in.
- **Not keyboard-reachable.** Table regions were not keyboard-reachable at phone width.
- **Closing could deadlock.** Closing a tenant while a subscription change arrived could fail with a MySQL deadlock: the
  commercial lock re-inserted its row every time, share-locking the tenants row. Found by forcing race 7; fixed in the
  SaaS.3 lock (ac274fa).

## 2. Starting Commit

`ae35542` (docs: SaaS.8 final report ending commits).

## 3. Ending Commit

`65b62cd` (docs: SaaS.9 discovery and scope, architecture, runbooks, ADR-0081..0096, invariants 88-101, evidence, final
report), followed only by the commit recording this ending commit. 22 SaaS.9 commits in all; the code and test commits
are listed in §28. Not pushed, merged or deployed.

## 4. Discovery Findings

Full detail: `docs/saas/SaaS-9-Discovery-and-Scope.md`.

**Schema:**
- 334 tables at the start (299 with `tenant_id`, 35 without).
- 1,255 single-column foreign keys: 444 cascade, 495 set null, 311 restrict, 5 no action. 18 self-references, 7 cycles.
- Cascade cannot be trusted: a restrict grandchild blocks it.

**Files:** 12 tables hold stored paths. There are 11 exact per-tenant storage prefixes in 10 categories. Compliance evidence of the platform
sits beside the tenant's, so a wildcard prefix would destroy platform evidence.

**Access and evidence:**
- Access is decided in one place (`Tenant::isAccessible`), so closing needed no new access check.
- The audit trail is an immutable hash chain with chain locks and no triggers. It can only be removed whole, newest first.

**Inputs from earlier phases:**
- **Financial:** SaaS.6 subscriptions and SaaS.7 invoices, payments and refunds must block, never be cleared.
- **Retention:** no legal retention existed (D-8 open), only SaaS.8 operational purge windows.
- **Deferred work:** SaaS.8 already defers a suspended tenant's work; closure reuses it.

**The lifecycle chain** proposed in the prompt was validated and mapped onto a stored and derived model (§8).

## 5. SaaS.9 Scope

Delivered:
- **The offboarding case:** request, cancel, close, reopen, waive export. New tenant statuses `closed` and `deleted`.
- **Full export:** asynchronous, resumable, one in flight at a time; encrypted and signed; private storage, short-lived
  links bound to one person; customer self-service with the new `data.export` permission.
- **Retention policies:** versioned, effective-dated, per jurisdiction, maker-checker, legal-minimum floor.
- **Legal holds:** immediate placement, two-person release.
- **The deletion planner:** gates, per-category reasons, dependency protection, fingerprint.
- **Two-person deletion:** cooling-off and environment switch.
- **The batched executor:** stops at a hold, resumes, verifies.
- **Anonymisation** of sign-in accounts.
- **The audit chain**, removed whole.
- **External dependency tracking.**
- **A signed certificate.**
- **Three pages:** operators' offboarding page, operators' retention page, the customer's data export page.
- **Integrations:**
  - sessions, API keys and deferred work;
  - the warehouse feed;
  - subscriptions (no new entitlement while closed);
  - financial gates;
  - the operational retention purge, which now honours holds.

## 6. Scope Excluded

- **Enabling deletion anywhere.** This waits for the owner's decision after this gate.
- **Legal retention values** (D-8, owner and legal input).
- **Deleting backups and third-party copies.** These are tracked, never claimed.
- **D-15 operator roles.** Any two operators act as maker and checker meanwhile.
- **Customer-initiated closure or deletion.**
- **Soft-delete copies.**
- **Restoring a deleted tenant.**
- **SaaS.10, self-service signup and RMS** (not touched).

SaaS.7 and SaaS.8 were reopened only for defects or for integration with the new states:
- `TenantSuspensions` refuses closed tenants.
- `TenantForm` and `ProvisionTenantAction` refuse the offboarding-only statuses.
- `CommercialSubscriptions` gives a closed tenant no new entitled period.
- The operational purge honours holds.
- `Capability` maps `data.export` to core.

## 7. Data Inventory

36 categories. Every table is classified exactly once: 304 category tables, 7 child tables, 29 platform tables. 12 tables
have file columns. `ArchitectureTest` fails on a new unclassified table.

Per-table detail (sensitivity, foreign keys in and out, files, excluded columns, anonymisation, audit references):
`docs/saas/evidence/SaaS-9-data-inventory.md`.

| Group | Categories | In a deletion |
|---|---|---|
| Customer HR data (24) | identity, people, employment, organisation, attendance, leave, documents, performance, goals, learning, career, talent, compensation, assets, service desk, grievances, engagement, communication, notifications, workflows, configuration, analytics, integrations, api | deleted once approved retention ends; identity is anonymised when kept records reference it |
| Statutory and financial employee data (4) | payroll, statutory, tax, banking | deleted only once an approved policy ends (the likeliest legal minimums: owner input) |
| Evidence (1) | audit (the tenant's chain) | removed whole by its own step, or retained |
| Markedge's records (4) | billing, invoices, payments, subscriptions | always retained |
| Platform (3) | operational state (deleted), exit records (retained), warehouse snapshots (deleted) | as stated |

## 8. Tenant Offboarding Lifecycle

**Tenant status:** `trial`/`active` ⇄ `suspended`, then `closed`, then `deleted` (a tombstone).

**The case:** `requested`, then `closed`, then `completed`; or `cancelled` (on withdrawal or reopen).

| Prompt state | Implemented as |
|---|---|
| ACTIVE / SUSPENDED | `tenants.status` (unchanged) |
| OFFBOARDING_REQUESTED | case `requested` (the tenant keeps working) |
| OFFBOARDING | case `closed` + tenant `closed` |
| EXPORT_* | per export: `queued` / `generating` / `ready` / `failed` / `expired`, plus `delivered_at` |
| LEGAL_HOLD / RETENTION | per hold record; per category `RETENTION_RUNNING` (derived) |
| DELETION_ELIGIBLE | per category `ELIGIBLE`; per tenant no gate (derived) |
| DELETION_SCHEDULED | deletion `approved` + `scheduled_for` (cooling-off) |
| DELETION_IN_PROGRESS | deletion `in_progress` + `deletion_started_at` |
| DELETED | tenant `deleted`, case `completed` (only when no customer data remains) |
| DELETION_CERTIFIED | signed certificate on the deletion |

Every transition has:
- an operator, checked in the service;
- a reason of 5 to 1,000 characters;
- a timestamp, and an effective date where one applies;
- events on both audit chains;
- a conditional update or unique key under the exit lock.

**Reactivation.**
- Reopening restores the interrupted status, the warehouse feed and deferred work.
- Deferred notifications are sent.
- Permissions and roles were never changed.
- API keys and integrations work again, because the tenant is accessible.
- No subscription restarts by itself: the operator starts one on Subscriptions (a closed tenant could not have one).
- It is refused while a deletion is planned or approved.
- It is refused for good once a deletion has started, including after a partial one (tested).

## 9. Export Architecture

`TenantExports` creates the request, with one in flight per tenant through `in_flight_key`. `GenerateTenantExport`
(queued, tenant-aware, runs for closed tenants) claims it with a lease, then `ExportPackager` builds the package:
1. Each table is written as JSON Lines and CSV, by id, with encrypted columns decrypted and secret columns removed.
2. Each referenced and stored file is copied.
3. A checkpoint is recorded after every table and verified by sha256 on resume.
4. A manifest is built with the schema version, export reference, tenant, generated time, categories, record counts,
   every file's sha256 and any missing files. It is signed with HMAC-SHA256.
5. A README is added.
6. The ZIP is written with AES-256 on each entry.

The tick resumes stalled exports, fails them after 5 attempts and expires ready packages after 7 days. The record,
checksums and manifest stay. An operator retry restarts cleanly.

## 10. Export Security

| Control | Detail |
|---|---|
| Who | An operator, or a tenant user with `data.export` on an accessible tenant, checked in the service |
| What | This tenant's rows only; no secret column; no platform data |
| Storage | Private disk; random passphrase stored encrypted |
| Access | Signed 5-minute link naming the person; session middleware; 30 requests per minute; `no-store` |
| Lifetime | Package expires after 7 days |
| Audit | Every request, download and passphrase reveal |
| Overlap | No export once a deletion is approved or has started |

Browser-verified:
- another person's link returns 403;
- a tampered signature returns 403.

## 11. Retention Architecture

`retention_policies`: category × jurisdiction × version, with:
- `effective_from` (today or later);
- `policy_months` and its basis;
- an optional `legal_minimum_months`, with basis and reference;
- maker-checker status.

Rules:
- **Floor:** a policy can never be below a configured legal minimum.
- **No minimum configured:** the UI and the plan show `LEGAL_MINIMUM_NOT_CONFIGURED`.
- **No approved policy:** the category is not deletable (`RETENTION_POLICY_NOT_CONFIGURED`).
- **Retention clock:** it runs from closure, taking the longer of the version in force at closure and the version in
  force today.
- **Jurisdiction:** the tenant's own jurisdiction applies first, then `*`.
- **Nothing shipped:** no policy values ship with the code.

## 12. Legal Hold

- **Record:** reference, scope (the tenant or categories), categories, reason, matter, start date, placed by and when,
  release requested by and when with its reason, released by and when.
- **Placing:** one operator, effective at once.
- **Releasing:** requested by one operator, approved by a different one. A hold awaiting release still holds.
- **Enforcement:**
  - the planner (`LEGAL_HOLD`);
  - approval (an unchanged hold set, under the lock);
  - every deletion batch and every retention purge batch (re-read under the exit lock).
- **Proven:** MySQL races 4 and 5 show nothing covered is deleted after the hold commits.

## 13. Deletion Eligibility

Tenant gates:
- `TENANT_NOT_CLOSED`
- `EXPORT_NOT_DELIVERED` (unless waived)
- `EXPORT_IN_PROGRESS`
- `FINANCIAL_DRAFT_INVOICES`, `FINANCIAL_UNPAID_INVOICES`, `FINANCIAL_PAYMENTS_IN_PROGRESS`,
  `FINANCIAL_PAYMENT_EXCEPTIONS`, `FINANCIAL_REFUNDS_IN_PROGRESS`
- `DELETION_IN_FLIGHT`

Per-category reasons:
- `ELIGIBLE`
- `RETENTION_RUNNING` (with the date)
- `RETENTION_POLICY_NOT_CONFIGURED`
- `LEGAL_HOLD` (with the references)
- `PROTECTED_BY_DEPENDENCY` (exact `EXISTS` per foreign-key edge from kept data)
- `RETAINED_MARKEDGE_RECORDS`
- `RETAINED_EXIT_EVIDENCE`
- `NOTHING_TO_DELETE`

## 14. Deletion Architecture

1. **Plan.** The maker plans, with a reason. The plan is fingerprinted (sha256 of actions, reasons, tables and
   anonymised tables).
2. **Approve.** A different operator approves, only if the fingerprint is unchanged and, under the exit lock, the tenant
   is still closed, the holds are the same and no export is generating. Approval creates the ordered steps and schedules
   the run 14 days later.
3. **Dispatch.** The tick dispatches the run only when `deletion_enabled` is true.
4. **Claim.** The worker claims with a conditional update, then re-checks under the lock at start.
5. **Run.** Short batches of `batch_size` rows each:
   - take the exit lock;
   - re-read the holds;
   - delete by id.
6. **Steps, in order:**
   - files;
   - child rows;
   - cycle references cleared (19 nullable columns);
   - all rows children-first (strongly connected components of the live foreign-key graph, audit chain in place);
   - anonymisation;
   - stored prefixes;
   - verification of no dangling reference;
   - external dependencies.
7. **Stop.** On any error the run stops as `failed` with the exact message.
8. **Retry.** A retry re-plans: finished steps are not repeated, categories no longer eligible are skipped, emptied ones
   are done.
9. **Delete style.** Hard deletes only, with no soft-deleted copies. The tenant row stays as a tombstone.
10. **Result.** The tenant becomes `deleted` only when no customer data remains. Otherwise it stays `closed` with a
    partial certificate.

## 15. Anonymisation

Only `users`, and only when:
- identity is otherwise deletable;
- retained records (payroll, the audit chain…) reference it;
- no hold covers it.

Personal columns are replaced deterministically (`Deleted user`, `deleted-user-{id}@deleted.invalid`, a random
password). Secrets, recovery codes and SSO links are cleared, and the account is suspended.

Tested: with the audit trail retained, sign-in accounts are anonymised and the chain still verifies.

## 16. Financial/Statutory Retention

**Markedge's records** (billing profile, terms, invoices, credit notes, tax lines, payments, reconciliation, refunds,
subscriptions, plan assignments) are always `RETAINED_MARKEDGE_RECORDS` and are exported to the customer.

**Open items block deletion.** These are draft or unpaid invoices, payments in progress, payment exceptions and refunds
in progress. They are shown and never cleared.

**Closing** is refused while a subscription continues. No new entitled period can start while the tenant is closed
(MySQL race 7).

**Payroll, statutory, tax and banking** data is deleted only under an approved policy. While payroll is retained, every
category it depends on is protected (tested).

**Owner input needed:** the legal minimums (D-8).

## 17. File/Document Handling

- **Inventory:** 12 tables of referenced files, and exact per-tenant prefixes (never wildcards, architecture-guarded).
- **Export:** files are copied into the package. Missing files are listed in the manifest.
- **Deletion:** referenced files go before their rows. Stored prefixes are emptied, and missing files counted.
- **Storage failure:** the run stops with the reason, claims nothing, and completes on retry (tested). Package write
  failures are recorded and retried (tested).
- **Isolation:** the other tenant's files are untouched (tested).

## 18. Warehouse Handling

- At closure the feed is switched off. Its prior state is kept and restored on reopen (tested).
- Snapshots under `warehouse/{slug}` are a deletable category, deleted by prefix (tested in the end-to-end deletion).
- The daily purge honours holds on `warehouse_exports`.
- The feed already skips closed tenants.

## 19. External Dependencies

Each starts with a status:

| Dependency | Starting status |
|---|---|
| Backups | `EXTERNAL_DELETE_REQUIRED` |
| Each integration system | `EXTERNAL_DELETE_REQUIRED` |
| Webhook receivers | `EXTERNAL_DELETE_UNSUPPORTED` |
| Payment provider | `EXTERNAL_DELETE_UNSUPPORTED` |
| Delivered e-mail | `EXTERNAL_DELETE_UNSUPPORTED` |

Operators record `REQUESTED` or `CONFIRMED` with a reason:
- the update is conditional, and a confirmation is final;
- nothing is recorded on a cancelled deletion;
- each record is audited (`EXTERNAL_DELETION_CONFIRMED`).

The certificate states the status at issue, and says later confirmations are in the audit trail. Nothing outside
PeopleOS is claimed deleted.

## 20. Audit

- **New actions:** 34 (`OFFBOARDING_*`, `TENANT_CLOSED` / `REOPENED`, `EXPORT_*`, `LEGAL_HOLD_*`, `RETENTION_POLICY_*`,
  `DELETION_*`, `EXTERNAL_DELETION_CONFIRMED`).
- **Chains:** each is recorded on the tenant chain and on the platform chain, with `subject_tenant_id`. Once the tenant's
  chain is gone, events go to the platform chain only.
- **Deleting the tenant chain:**
  1. the final event and hash are recorded in the step and the certificate;
  2. rows are deleted newest first;
  3. what remains is a valid prefix at every point (tested with a hold mid-chain; every MySQL race verifies both chains
     afterwards).
- **Guard:** an architecture test permits audit deletes only in the executor.
- **Retention of the evidence:** the exit's own records (case, exports, holds, plans, steps, certificate) are retained.

## 21. Maker-Checker

| Operation | Maker | Checker |
|---|---|---|
| Deletion | plans | a different operator approves (early check + conditional update; MySQL race 1) |
| Retention policy | proposes | a different operator approves or rejects |
| Legal hold release | requests | a different operator approves or declines |

The pages offer approval only to an operator who is not the maker (browser-verified). The worker runs only after
approval, cooling-off and the switch. One operator cannot request, approve and delete.

## 22. Tenant Isolation

Tenant A cannot do any of the following to tenant B. Each is a test:

| Attempt | Why it fails |
|---|---|
| Export B | `authorize`: tenant check, including with permissions already loaded in its own tenant |
| See B's exports | tenant scope; the page lists exactly its own |
| Download B's export | signed link bound to the person; another person gets 403 |
| See or reveal B's passphrase | service refusal, "Export not found" on the page |
| See deletion status, certificate or retention rules | operator pages return 403 for every tenant user |
| Place a hold, plan or approve a deletion | the services refuse non-operators |
| Reach the exit through the API | no API route touches the exit (route test) |

Operators' cross-tenant reads are allow-listed (`ArchitectureTest` bypass list: the offboarding services and
`OffboardingAudit`).

## 23. Database Changes

One additive migration, `2026_10_30_100001_saas9_offboarding_export_retention`, creating six tables:
- `tenant_offboardings`
- `tenant_exports`
- `legal_holds`
- `retention_policies`
- `tenant_deletions`
- `tenant_deletion_steps`

Unique keys:
- `open_key` and `in_flight_key` (two): one open case, one export in flight, one deletion in flight;
- policy (category, jurisdiction, version);
- step position.

No existing column changed. `tenants.status` is a string, so the new values needed no change.

## 24. Jobs/Queues

`GenerateTenantExport` and `ExecuteTenantDeletion` are:
- tenant-aware and `RunsForSuspendedTenants` (they run for closed tenants, allow-listed);
- `tries` 1 (retries are explicit and claimed); timeout 3,600 s;
- idempotent (conditional claims, checkpoints, steps).

A closed tenant's other jobs are deferred by the SaaS.8 infrastructure and released on reopen (tested).

`peopleos:offboarding:tick` runs every 15 minutes:
- export sweep (resume, fail, expire);
- dispatch of due deletions;
- stalled runs marked failed.

It is listed in `docs/operations/queue-and-scheduler.md`.

## 25. UI/Admin

**Platform → Offboarding** (operators):
- the tenants list;
- the case;
- before-closing blockers;
- the eligibility plan, with reasons, dates, legal minimums, counts and files;
- exports, with download, passphrase, record-delivered and retry;
- holds, with release;
- deletions, with steps, approve, reject, cancel, retry, external records and the certificate download;
- a switch-off notice.

**Platform → Retention policies** (operators): the policy in force per category and the version history, with propose,
approve, reject and withdraw.

**Data export** (customers with `data.export`): request, status, download and passphrase. No platform control.

## 26. Configuration

All `offboarding.*` keys are env-backed, defaults in brackets:
- `deletion_enabled` (false)
- `cooling_off_days` (14)
- `export_required_before_deletion` (true)
- `export_expiry_days` (7)
- `download_link_minutes` (5)
- `export_lease_minutes` (30)
- `export_max_attempts` (5)
- `export_disk` (local)
- `batch_size` (1,000)
- `deletion_lease_minutes` (30)

The new permission `data.export` is a core capability, never commercial. After deploying, run
`php artisan peopleos:sync-permissions`.

## 27. Security

Deletion requires an authenticated operator, an operator check in the service, a reason, maker-checker, an unchanged
plan, cooling-off, the switch and audit. No API key or tenant user can reach it (mutants X46, X49–X51; route test).

- **Invariants** 88–101 are added to `security-invariants.md`.
- **Export boundaries** are in `security-boundaries.md` §6.

**Open security blocker (inherited):** the leaked showcase credential (see the box at the top). It is still accepted in
`hcm_ux_showcase` by `admin@fynnedge.com`. The operator `platform@markedge.local` accepts the fixture word. It is not
fixed here (owner rotation required), and it is not hidden.

## 28. Migration/Rollback

- **UP → DOWN → UP** on MySQL (`hcm_saas6_concurrency`): passed twice.
- **Migrated:** the 8090 showcase (`hcm_ux_showcase`) and the development database (`hcm`), additively. Deletion is
  disabled in both and was not run.
- **Rollback** is safe only while no tenant is `closed` or `deleted`: reopen them first. A deletion cannot be undone.

SaaS.9 code and test commits, in order (then `65b62cd`, documentation, and the commit recording it, §3):

- `60b7aae` feat: SaaS.9 exit schema, closed/deleted tenant states and the data inventory
- `5641c83` feat: SaaS.9 retention policies, legal holds and the offboarding case
- `b49a3c7` feat: SaaS.9 full tenant export: encrypted, signed, resumable
- `a5cb8d4` feat: SaaS.9 deletion plan, maker-checker approval, executor and certificate
- `54096a1` feat: SaaS.9 operator offboarding and retention pages, customer export page
- `4397fc8` test: SaaS.9 offboarding lifecycle, export, retention, holds, deletion safety, pages and isolation
- `1b4dc39` fix: SaaS.9 one exit lock and lock order for every exit transition
- `7f600a4` fix: SaaS.9 a closed or deleted tenant gets no new entitled subscription period
- `eb659eb` fix: SaaS.9 regressions from the full suite: tenant edit form, data.export capability, allow-list
- `08cfc08` test: SaaS.9 exit guards, storage failures and the 11 MySQL races
- `64107e6` fix: SaaS.9 exit pages: keyboard-reachable table regions, legal minimum column, wording
- `1d73cab` test: SaaS.9 tests strengthened by surviving mutants
- `f4e92f5` test: SaaS.9 nothing runs before the cooling-off ends
- `180f671` fix: SaaS.9 one wording for a deletion already in flight
- `ea2f68e` test: SaaS.9 no tenant user plans, approves, cancels or retries a deletion
- `ac274fa` fix: SaaS.9 closing a tenant deadlocked against a concurrent subscription change
- `938f379` test: SaaS.9 MySQL races force the dangerous interleavings
- `b2d5929` test: SaaS.9 race waits do not depend on how full the shared database is
- `d814817` fix: SaaS.9 large exports broke the certificate and the export lists on MySQL
- `2801255` test: SaaS.9 exports with manifests larger than MySQL's sort buffer

## 29. Test Results

**Full SQLite suite: 1,382 passed, 0 failed** (1,508 tests, 19,077 assertions; `php artisan test --parallel --processes=2`,
on the final tree). The 126 skipped are the MySQL-gated tests (§30).

The first full run during the phase found three regressions, all fixed (eb659eb):
- the tenant edit form rejected an existing suspended tenant's status;
- the new `data.export` key had no capability;
- an architecture allow-list was missing an entry.

A later run found a stale wording expectation (180f671).

The SaaS.9 feature tests are 7 files, 44 tests, plus 11 MySQL races:

| File | Tests | Covers |
|---|---|---|
| `OffboardingLifecycleTest` | 6 | close, reopen, refusals, subscriptions, warehouse feed |
| `TenantExportTest` | 7 | package content, single in-flight export, resume, retry, expiry, links, passphrase |
| `RetentionAndLegalHoldTest` | 6 | policies, legal minimum, versions, holds, purge |
| `TenantDeletionTest` | 4 | end to end, anonymisation, payroll protection, steps |
| `DeletionSafetyTest` | 7 | gates, switch, maker-checker, holds before and during, resume |
| `OffboardingPagesTest` | 5 | pages, isolation, API routes |
| `ExitGuardsTest` | 9 | overlaps, storage failures, no policy, cooling-off, tenant users |

Every scenario listed in prompt §35 is covered, including complete, incomplete and resumed export, retry, expiry,
partial-deletion recovery, historical financial retention, warehouse and file cleanup.

## 30. MySQL Results

- `tests/MySql/OffboardingConcurrencyTest.php`: 11/11 in every run, alone (about 190–235 s) and inside the full suite.
- `tests/MySql/OffboardingLargeRowsTest.php`: 1/1. It fails without d814817 ("Out of sort memory").
- Full MySQL suite: **126/126** (903 s), on the final tree, in `hcm_saas6_concurrency`. Every earlier race (tenancy,
  audit chain, payroll, entitlements, plans, subscriptions, billing, SaaS.8 operations) passed, plus the 11 exit races and
  the large-row test.
- An earlier full run failed one race (4) only on its own 30 s wait: in a database filled by the whole suite its two-row
  batches are slower. The wait was widened (b2d5929); the product was not changed.
- UP/DOWN/UP: passed.

## 31. Concurrency Results

| # | Race | Result |
|---|---|---|
| 1 | Two approvals | one approves, the other "no longer waiting"; steps once; one audit event |
| 2 | Two workers | one claims; one run; started and certified once |
| 3 | Export + deletion approval, both orders forced | an export committed between the approval's checks and its write refuses the approval; an approval committed inside the export request's window refuses the export |
| 4 | Hold + running deletion | stops at the next batch; nothing deleted after the hold; chain valid |
| 5 | Retention purge + hold; approval evaluation + hold | purge stops (`held`, nothing purged after the hold); approval refused, or the held data kept |
| 6 | Reopen + planning (forced: the plan's write lands after the reopen's check); cancel-and-reopen + worker | never a reopened tenant with a deletion |
| 7 | Close + subscription cancellation; close + subscription start (forced: the start arrives while closing holds the commercial lock) | never a closed tenant with a continuing subscription. **Forcing it exposed a deadlock**: closing could fail with a MySQL deadlock error (fixed in ac274fa) |
| 8 | Two export requests | one export; the second joins; one audit event |
| 9 | Export completion + eligibility | planned only after delivery |
| 10 | Deletion + external records (confirmation, then a later "requested") | a confirmation is never overwritten; one audit per record |
| 11 | Retry after partial deletion (two retries 1.3 s apart + tick) | one retry; one run; attempts 2; one certificate |

Races 3, 6, 7 and 10 force their dangerous order: one side pauses between its checks and its write, and the other is
released into that window. Without that, four lock mutants survived (§32).

## 32. Mutation Results

Run on private copies of the final tree (8090 serves the working tree), one mutant at a time, each against the tests
named for it.

**SQLite authorisation and safety mutants: 53/53 killed.**

| Area | Mutants | Examples |
|---|---|---|
| Offboarding case | X01–X08 | close without the operator check or with a continuing subscription; warehouse feed left on; reopen after a deletion started or while one is planned; closed status provisionable, suspendable or accessible |
| Retention | X09–X14 | below the legal minimum; minimum without reference; self-approval; a later shorter version applies; no policy treated as deletable; retention end ignored |
| Holds | X15–X20 | self-release; release request stops holding; planner, batch or purge ignore holds; hold by a tenant user |
| Export | X21–X32 | no `data.export`; another tenant's user; tenant user after closure; secret columns or other tenants' rows exported; link not bound or not signed; second export in flight; export after approval; unverified checkpoint; reveal unaudited; page lists other organisations |
| Deletion | X33–X48 | maker approves; changed plan approved; switch ignored; cooling-off ignored; export or financial gate removed; dependencies or anonymisation removed; export in flight ignored; audit chain oldest-first; external copies claimed; confirmation overwritten; signature unchecked; tenant user plans; closed tenant subscribes; storage failure ignored |
| Pages and inventory | X49–X53 | operator pages open to tenants; export page without permission; unclassified table; wildcard prefix |

**Surviving mutants strengthened tests.** Six mutants survived a first pass. Each found a gap, which a test now covers:

| Mutant | Gap |
|---|---|
| X04 | Reopening was only refused after a final deletion. Now also after a partial one |
| X13 | No planner test for a category without a policy |
| X22 | Another tenant's owner was not tested with permissions already loaded in their own tenant (permissions are memoised per user) |
| X30 | No damaged checkpoint file |
| X36 | No cooling-off test |
| X46 | No tenant user sent through the deletion services |

X48 is killed by non-termination: without the check, the stored-file loop never ends on a refusing disk.

**MySQL concurrency mutants: 13/13 killed** (each removes one lock or conditional, and is run against its race):

| Mutant | Removes | Race |
|---|---|---|
| K01 | the tenant-row part of the exit lock | 5 |
| K02 | the hold re-check per batch | 4 |
| K03 | approval's re-check under the lock | 3 |
| K04 | approval's status condition | 1 |
| K05 | the worker claim's condition | 2 |
| K06 | the export request's re-check under the lock | 3 |
| K07 | closing's commercial lock | 7 |
| K08 | the retry's status condition | 11 |
| K09 | the external record's conditions | 10 |
| K10 | reopen's lock | 6 |
| K11 | planning's re-check under the lock | 6 |
| K12 | the closed-tenant subscription guard | 7 |
| K13 | the commercial-lock row fix | 7 (deadlock returns) |

**Six survived the first pass** (K03, K06, K08, K09, K10, K12). The races were timing-based, and the dangerous order
rarely happened (or MySQL's changed-rows count hid a duplicate write). The races were fixed, not the mutants: each now
forces its interleaving. Forcing race 7 found the deadlock fixed in ac274fa.

## 33. Browser Results

**39/39 checks pass** (Playwright, Chromium), on the final tree, against a disposable fixture database
(`hcm_saas9_ui_showcase`, fictional tenants) served with deletion switched off. Evidence, with no credential or
passphrase in it: `docs/saas/evidence/SaaS-9-browser-validation.json`.

| Persona | Checked |
|---|---|
| Operator one (maker, desktop, password + TOTP) | Switch-off notice; tenants list; Fabrikam: closed case, delivered export, legal hold (`LEGAL_HOLD`), eligibility plan; **no approval offered to the maker**; legal hold form; Litware: completed partial deletion, steps, retained payroll, external dependencies; certificate downloads as signed JSON; retention page shows `LEGAL_MINIMUM_NOT_CONFIGURED`, and a proposal cannot be approved by its proposer |
| Operator two (checker, phone) | Approval offered; approving is **refused while deletion is switched off**, and the deletion stays planned; may approve the retention proposal |
| Customer owner (`data.export`, desktop and phone) | Export page lists the ready export; no platform control; 403 on both operator pages; passphrase shown (shape checked, value never recorded); package downloads through a signed, expiring link |
| Customer user without `data.export` | 403 on the export page and on both operator pages; no "Data export" in the navigation; **the owner's signed link returns 403 for them**; a tampered signature returns 403 |
| All | No horizontal scroll at 1440 and 390 px; no console errors |

**The existing browser suite** (`npm run browser:test`: back-navigation, focus, phone layout, Chromium and WebKit) passes
**74/74** (6 skipped by design), as at SaaS.8. Nothing that already worked regressed.

## 34. Accessibility

axe (WCAG 2.0/2.1 A and AA) passes in every captured state:
- offboarding page (desktop and phone);
- legal hold form;
- deletion with certificate;
- retention page (desktop and phone);
- data export page (desktop and phone).

The first run found `scrollable-region-focusable` at phone width on the offboarding and retention tables. Fixed: every
scrolling table is a labelled, focusable region (64107e6).

## 35. Visual Regression

**120/120 pass, 0 unexpected** (`npm run visual:test`, the UX.18 suite, against its own frozen-clock disposable database
on 8092). This matches the SaaS.8 baseline: no existing screen changed.

| Engine | Screens |
|---|---|
| Chromium: phone, tablet, tablet landscape, desktop; light and dark | 112 |
| Firefox desktop smoke | 4 |
| WebKit (WPE) phone smoke | 4 |

The 198 skipped entries are the suite's per-project screen selection, not failures.

**The first run after the machine restarted had 53 WebKit errors**: the browser could not start, because two libraries
were missing. This was environment, not product. WebKit was given the missing libraries
(`libflite1`, `libavif16`, `libdav1d7`, `libgav1-2`, `libyuv0`, extracted from the distribution's packages into the
scratchpad) and a clean environment, through the suite's documented `WEBKIT_EXE` launcher. Then all four WebKit screens
matched their baselines.

The new SaaS.9 pages are operator and customer-admin screens outside the persona baselines. They are covered by the
browser screenshots and axe in §33–§34.

## 36. Performance

Measured on MySQL 8.4 on the 8-core workstation, machine otherwise quiet. Each dataset is the showcase tenant scaled by
cloning one employee (person, account, employment and every row referencing them). The fixture build is not product
code and not timed as such. Approved retention policies of 0 months make every category deletable, the worst case for
deletion. Batch size 1,000. Time is wall clock; memory is the peak above the process's baseline.

| Dataset (tenant rows) | Export | Plan | Approve | Delete |
|---|---|---|---|---|
| 100 employees (11,226) | 3.6 s, 954 queries, 15 MB; 0.6 MB package | 0.50 s | 0.67 s | 5.6 s, 5,799 queries, 2 MB; final |
| 1,000 employees (51,726) | 5.8 s, 1,063 queries, 15 MB; 1.2 MB | 0.50 s | 0.68 s | 7.6 s, 6,023 queries, 1.8 MB; final |
| 10,000 employees (456,726) | 79.7 s, 2,296 queries, 15.5 MB; 6.6 MB | 0.79 s | 0.83 s | 35.2 s, 9,236 queries, 1.8 MB; final |
| 1,000 employees + 4,893 documents (56,616 rows, 98 MB of files) | 17.5 s, 1,085 queries, 60 MB; 97.9 MB package | 0.70 s | 0.72 s | 8.6 s, 6,219 queries, 2.6 MB; final |

Approval creates 285 steps in every dataset.

**What the numbers show:**
- **Asynchronous.** Export and deletion run in queued workers, never in a request. Closing takes 22–72 ms.
- **Flat memory.** Memory stays flat as the tenant grows tenfold, because export streams table by table (lazily by id)
  and deletion runs in batches.
- **Short locks.** No long lock is taken: each deletion batch is one short transaction.
- **Cheap planning.** Planning and approval stay under a second.

**Before/after** does not apply to the new exit itself. The only changes to existing paths are:
- the retention purge: two indexed queries per batch (the exit lock and the hold read);
- closing: its locks.

**Found by this run (fixed, d814817):** the large-document deletion first failed at its certificate with MySQL "Out of
sort memory". Exports and deletions with megabyte manifests were being sorted with all their columns. The table above is
the run after the fix, and the fix has its own MySQL test.

**Observed:** in the database filled by the whole MySQL suite (134 tenants, 180,000 audit field changes), one small
tenant's plan took 4.4 s instead of 0.5 s. Child tables are filtered through their parent rows, so planning cost grows
with other tenants' child-table volume. This is acceptable for an operator action run by a worker, and is listed in §38.

## 37. Production Readiness Impact

**Ready behind the switch:**
- offboarding (close, reopen);
- exports, including the customer self-service export;
- retention policy administration;
- legal holds.

**Deletion is not enabled.** Enabling it requires:
1. the owner's decision after this report;
2. approved retention policies for real categories (D-8);
3. backup expiry evidence for the external dependency;
4. a production rehearsal on a copy.

The inherited credential blocker still prevents any release.

## 38. Remaining Risks

| Risk | Severity | Mitigation / owner |
|---|---|---|
| Leaked showcase credential still accepted (`hcm_ux_showcase`) | **Critical (release blocker)** | Owner: rotate, treat the history value as compromised, re-check with the counts-only script |
| No legal retention values configured (D-8) | High for enabling deletion | Nothing is deletable without approved policies; owner and legal input |
| Backups keep deleted data until they expire | Medium | Tracked as `EXTERNAL_DELETE_REQUIRED`; backup policy and evidence needed |
| D-15 roles: any two operators can act as maker and checker | Medium | Dedicated roles later; audit names both |
| The export ZIP's AES-256 needs a capable unzip tool (7-Zip, WinZip) | Low | README; runbook |
| Very large tenants: the export holds a full copy in the work directory before zipping | Low–Medium | Measured (§36); disk sizing in the runbook |
| Rollback after a tenant is closed | Low | Documented (reopen first) |
| Planning cost grows with the whole database's child-table volume (4.4 s for a small tenant among 134 tenants) | Low | Operator action in a worker; an index on the child-table filters is possible later |
| The exit lock is the `tenants` row (FOR UPDATE), an exception to the project rule against locking a parent row that foreign keys point at. Any transaction holding a lock an exit transaction waits for, while inserting a row for that tenant, can deadlock against it | Low–Medium | Exit transactions are short. The one real cycle found (the commercial lock's `INSERT IGNORE`) is fixed and covered by race 7 and K13. MySQL resolves any other cycle by failing one side, and exit steps stop safely and are retried. Follow-up: a dedicated lock row, pre-created outside transactions (§39) |

## 39. Deferred Items

- Enabling deletion in production (owner gate).
- D-8 retention values.
- D-15 operator roles.
- Automated external deletion requests (integration partners, backups).
- Streaming export without a local work copy.
- Customer-facing notifications of exit milestones.
- Certificate re-issue after external confirmations (today they live in the audit trail).
- Move the exit lock from the `tenants` row to a dedicated, pre-created lock row (the project rule). A naive version would
  bring back two known traps: a plain read before the lock fixes the snapshot, and gap locks on a missing row deadlock
  inserters. So it needs rows created outside any transaction (at provisioning, plus a backfill).

## 40. Runbooks

`docs/operations/runbooks/`:
- `offboarding.md`
- `export.md`
- `retention.md`
- `legal-hold.md`
- `deletion.md`
- `recovery.md`

## 41. Decision Register

ADR-0081 … ADR-0096 in `docs/architecture/decision-register.md`:
- the state model;
- inventory completeness;
- graph ordering;
- financial retention;
- retention configuration;
- holds;
- maker-checker with fingerprint and switch;
- the lock order;
- export rules and package;
- `data.export`;
- the executor;
- anonymisation;
- the audit chain;
- external dependencies;
- reopen and the certificate.

Architecture: `docs/architecture/saas-9-offboarding.md`. Discovery: `docs/saas/SaaS-9-Discovery-and-Scope.md`.

## 42. Exact Next Phase Recommendation

**Do not start SaaS.10 yet.** In order:

1. **Owner: rotate the leaked showcase credential** and confirm with the counts-only check. This is a release blocker.
2. **Owner and legal: D-8.** Approve retention policies (and any verified legal minimums) per category and jurisdiction,
   payroll, statutory, tax, banking and the audit trail first.
3. **Owner: deletion enablement.** Take the decision, rehearse on a production copy (`PEOPLEOS_TENANT_DELETION_ENABLED`
   only there), and define backup expiry evidence.
4. **Then SaaS.10** (the control plane, as re-sequenced in SaaS.8), on explicit approval.
