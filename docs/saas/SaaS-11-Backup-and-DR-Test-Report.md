# SaaS.11 — Backup and disaster recovery test report

Date: 10 October 2026.

| Item | Status |
|---|---|
| **Target-environment backup restore and DR** | **NOT VERIFIED — gate BLOCKED.** No target, production or approved production-like environment exists |
| Local, isolated recovery rehearsal on fictional data | **Executed:** 14 of 14 steps passed (twice; the second run adds a heartbeat read-back). This is evidence of the **procedure and the software's recovery behaviour only**. It is not evidence that any production environment is recoverable |
| Production-copy offboarding rehearsal (F5) | **Not executed** (needs the same environment). Plan: `docs/operations/runbooks/production-copy-rehearsal.md` |

No restore was simulated. No test touched production data (none exists) or the 8090 showcase database. The
rehearsal's source was the disposable fixture database `hcm_saas10_ui_showcase`, which was only read. The restore
went into a new schema that the script refused to reuse if it existed. That schema, the backup artefacts and the
generated passphrase were removed afterwards.

## 1. Infrastructure discovered

| Component | Found |
|---|---|
| Production or staging hosts | none |
| Managed database, replicas, binary-log shipping | none: one local MySQL 8.4 with development and disposable schemas |
| Object storage (S3-compatible), versioning | none: documents on the local private disk |
| Backup system, schedules, retention, off-site copies | none |
| Secret store | none: `.env` on the workstation |
| Scheduler and workers for PeopleOS | none running (the crontab entry and supervisor workers belong to another project) |
| Monitoring and alerting | none wired |

So the §7 items "integrity, consistency, encryption and access, RPO and RTO, queues and scheduler, isolation, audit
integrity, offboarding reconciliation, rollback and escalation" can only be shown **mechanically** here. They are not
shown for an environment. Each is listed in §3 with what the rehearsal proved and what remains.

## 2. Local rehearsal: what was run

Script `rehearsal.py` (session scratch; the steps below are the procedure to reproduce). Evidence without secrets:
`docs/saas/evidence/SaaS-11-recovery-rehearsal.json`.

| # | Step | Result |
|---|---|---|
| 1 | Verify the source before the backup: schema hash, row counts, content hashes of 13 key tables, 5 audit chains, isolation of 4 tenants, decryption of encrypted columns, jobs, signup states | 342 tables, 15,965 rows; 5 chains valid (1,644 events) |
| 2 | Back up: a consistent `mysqldump --single-transaction`, plus the tenant files, with checksums. Encrypted with AES-256-CBC (PBKDF2, 200,000 iterations); plaintext removed; artefacts `0600` | 3 files; dump 457 KB; 3.65 s |
| 3 | Integrity: checksums of the encrypted artefacts, then of the decrypted contents | match |
| 4 | Access control: decryption with a wrong passphrase | refused |
| 5 | Restore into a new, empty, isolated schema; files into an isolated directory | 16.1 s |
| 6 | Application steps: `migrate` (nothing to run), `peopleos:sync-permissions` | as expected |
| 7 | Verify the restored copy with the same checks as step 1 | identical |
| 8 | Compare | identical: tables; schema (columns, indexes, foreign keys); row counts; content of 13 key tables; encrypted values (by hash); queued jobs; signup states; deletions |
| 9 | Files: restored files against the manifest | 3 of 3 match |
| 10 | `peopleos:audit:verify` on the restored copy | platform chain: 68 events verified |
| 11 | `peopleos:readiness` on the restored copy | 0 configuration failures; statutory BLOCKED; production readiness false |
| 12 | Queues and scheduler: heartbeat recorded **and read back** (2 s old); `signups:tick` and `offboarding:tick` idempotent; restored jobs drained by a worker | 0 jobs left, 0 failed |
| 13 | Encryption key dependency | encrypted values readable with the backup's `APP_KEY` (2 of 2), with another key 0 of 2 |
| 14 | Migration rollback and re-apply on the restored copy (the SaaS.10 migration down, then up) | both succeeded |

**Timings (local, small data set):**
- backup 3.65 s;
- restore 16.1 s;
- restore to verified, including the queue step, 31.0 s;
- the source was quiet, so the measured data loss was 0.

These numbers **say nothing about production RTO or RPO.**

## 3. Required verification items (§7)

| Item | Local rehearsal | Target |
|---|---|---|
| Backup integrity | checksums before and after encryption match | NOT VERIFIED |
| Database consistency | schema, counts and content hashes identical | NOT VERIFIED |
| Object consistency | 3 of 3 files by checksum | NOT VERIFIED: no object storage; versioning untested |
| Encryption and access | encrypted at rest; wrong passphrase refused; `APP_KEY` dependency proven | NOT VERIFIED: key custody in a secret store does not exist |
| RPO | 0 on a quiet source (meaningless for production) | NOT VERIFIED. Target 15 min with binary logs, 24 h with daily dumps (`backup-and-disaster-recovery.md` §2) |
| RTO | 31 s for 15,965 rows (meaningless for production) | NOT VERIFIED. Target 4 h (§5 of that document) |
| Queue and scheduler recovery | heartbeat read back; ticks idempotent; restored jobs processed | NOT VERIFIED: no supervised worker or scheduler exists |
| Tenant isolation | 4 tenants, 0 foreign rows, 0 rows without a tenant | NOT VERIFIED |
| Audit integrity | 5 chains valid before and after | NOT VERIFIED |
| Offboarding reconciliation | **Not exercised:** the source had no deletion to reconcile (0 deletions; deletion is disabled). The procedure is in §5 | NOT VERIFIED; precondition for deletion |
| Rollback | latest migration down and up on the restored copy | NOT VERIFIED: no deployment pipeline |
| Escalation | not exercised (no on-call exists) | NOT VERIFIED |

## 4. Target-environment execution plan (to run when an environment is authorised)

**Preconditions** (all required; otherwise do not start):
- written authorisation naming the environment;
- an **isolated** restore target with no route to production services: mail, payment providers, webhooks and
  integrations are disabled or pointed at sinks;
- the backup system's real artefacts;
- `APP_KEY` from the secret store;
- an operator and a second witness.

1. **Select the backup:** the latest scheduled full backup plus binary logs to a chosen point. Record its timestamp and
   checksum from the backup system.
2. **Restore the database** into the isolated target. Record the start time.
3. **Restore objects:** the document bucket at the same point (versioning), and the warehouse and export disks.
4. **Configure:** the production `APP_KEY` from the secret store, with integrations, mail, webhooks and payment
   providers off. Then run `peopleos:config:validate --as-production`.
5. **Application:** run `migrate --force` (expect nothing to run) and `peopleos:sync-permissions`.
6. **Verify** with the read-only checks used here: schema and counts against the source's backup-time figures;
   `peopleos:audit:verify` (every chain); `peopleos:readiness`; tenant isolation sample; decryption sample; document
   checksums for a sample of at least 50 files across tenants.
7. **Queues and scheduler:** start one worker and the scheduler. Confirm the heartbeat, then that the restored jobs
   drain without external side effects.
8. **Deletion reconciliation:** apply §5. Even with deletion still off, prove the register is empty or matches.
9. **Measure:** RTO (step 2 start to step 7 verified) and RPO (the last committed write in the source, against the
   restored data). Compare them with the 4 h and 15 min or 24 h targets.
10. **Rollback drill:** deploy the previous release onto the restored copy and roll it forward again, following
    `production-configuration.md` §9.
11. **Escalation drill:** page the on-call contact from the monitoring system with a synthetic `/health/ready` failure.
12. **Destroy** the restored copy and its keys unless retention is approved. Record the destruction.

**Evidence to keep:**
- timestamps;
- checksums;
- command outputs (no secrets);
- measured RTO and RPO;
- the sample lists;
- the names of the operator and the witness.

**Sign-off:**

| Field | Value |
|---|---|
| Environment and date | |
| Backup identifier and timestamp | |
| Measured RTO / RPO | |
| Failures and their resolution | |
| Operator | |
| Witness | |
| Owner acceptance | |

Then, and only then, may the readiness items "Backup restored and verified in the target environment" and "Disaster
recovery exercised" be treated as met.

## 5. Re-applying deletions after a restore (S11-5, refines F11)

**The gap:** a deletion's certificate is signed and stored in the database (`tenant_deletions.certificate`), and its
issue is recorded on the platform audit chain. An operator can download it from the Platform offboarding page. But a
restore to a point **before** the deletion loses both the deletion and its proof. After that, nothing in the restored
system says a tenant must be deleted again.

**The procedure required before deletion is ever enabled:**
1. When a deletion completes, the operator downloads the certificate and files it in a **deletion register outside the
   database**: a store with its own retention and access control, backed up separately. The register holds:
   - the reference;
   - the tenant id;
   - the certificate's SHA-256;
   - the completion time.
2. **Before a restored database serves traffic**, compare the register against the restored `tenants` and
   `tenant_deletions`. Every tenant in the register whose deletion is missing or incomplete in the restore must be
   deleted again under the same reference. Use a fresh plan, approved by maker-checker, and record that it re-applies
   a certified deletion. The reconciliation is recorded on the platform chain.
3. A restored tenant that the register shows as deleted must **never** be reachable: suspend it first, then reconcile.
4. Backups age out on their own schedule. The external-copy step records `RETENTION_REQUIRED` with the date the last
   backup holding the tenant expires, and later `CONFIRMED` with the backup system's expiry evidence.

**What exists today:**
- the certificate;
- its download;
- the external-copy states (PENDING, REQUESTED, FAILED, RETENTION_REQUIRED, UNSUPPORTED, CONFIRMED; tested in
  `RetentionClosureTest`, where CONFIRMED is final and needs evidence).

**What does not exist:**
- the out-of-database register;
- an automated reconciliation.

**Recommendation:** automate both before deletion is enabled. Until then, deletion stays OFF. The production
configuration gate refuses it without the owner's recorded authorisation (S11-3).

## 6. Backup expiry and the external-copy model

Reviewed against code and tests. The model does not claim a backup was deleted:
- `RETENTION_REQUIRED` needs a kept-until date that is today or later;
- `CONFIRMED` needs evidence and is final;
- the certificate shows the statuses at issue, and later records go to the platform audit trail.

Backup retention targets (35 daily, 12 monthly) are planning values in the DR runbook. **No backup system exists
whose expiry could be verified**, and D8-5 (backup ageing evidence) remains open.
