# Disaster recovery

Production readiness closure, 3 October 2026. This supersedes
`docs/operations/backup-and-disaster-recovery.md` as the operative runbook; that file stays as the
Phase 14 record.

> **TARGET DR = NOT VERIFIED.** No staging or production environment, object storage, secret store or
> second site was reachable from the build workstation. Every measured value below comes from a
> **local rehearsal** on the development machine. It proves the procedure and the tooling, not
> production RPO / RTO. §12 lists exactly what is needed to verify target DR.

## 1. Backup strategy

| Asset | Method | Frequency (target) | Retention (target) | Protection |
|---|---|---|---|---|
| MySQL schema (all tenants, audit chains, effective-dated history) | Logical dump (`mysqldump --single-transaction --routines --triggers --hex-blob --set-gtid-purged=OFF`, gzip), plus continuous binary-log shipping for point-in-time recovery. Managed-database snapshots where available | Full daily; binlogs continuous; an extra dump before every deploy | 35 daily, 12 monthly | Encrypted at rest (KMS / age / GPG); off-host; operations role only; SHA-256 recorded per dump |
| Object storage (documents, letters, certificates, grievance evidence, attachments, report exports, warehouse feed, statutory evidence and returns) | Bucket versioning + object lock on evidence prefixes + cross-region replication (S3-compatible). Local disk on a single node: nightly encrypted snapshot of `storage/app/private` | Continuous (replication) / nightly | Versions 35 days; evidence prefixes per legal retention | Block Public Access, default encryption |
| `APP_KEY` (and `APP_PREVIOUS_KEYS` during rotation) | Secret store with its own backup, separate from data backups | On change | Every key ever used for a retained backup | Tier-0 secret |
| Integration and webhook secrets, SSO client secrets | Stored encrypted in the database, so they are covered by the database backup. They are readable only with `APP_KEY` | — | — | — |
| Configuration (env values) | Infrastructure as code / secret store | On change | — | Never in git |
| Redis | Not backed up: cache, locks and (optionally) queue are rebuildable (§7) | — | — | — |

## 2. Database backup

1. `mysqldump` as above, from a replica where possible (consistent InnoDB snapshot, no table locks).
2. Record: start / end time (UTC), source size, dump size, SHA-256.
3. Encrypt, upload off-host, verify the upload checksum.
4. Keep binary logs from the dump's position for point-in-time recovery.

## 3. Object storage backup

- Versioning on the bucket. A deleted or overwritten object stays recoverable for the retention window.
- Object lock (governance mode) on `tenants/*/grievances/*`, letters, certificates and `compliance-evidence/*`.
- PeopleOS stores SHA-256 fingerprints for documents, certificates, announcement attachments and grievance evidence. After a restore, a file that does not match is refused on download (409). A partial or tampered restore is therefore visible, never silent.

## 4. Encryption keys

- Every encrypted column needs the `APP_KEY` that was current when the row was written: bank accounts, statutory identifiers, integration secrets, inbound payloads, idempotency responses, SSO and webhook secrets.
- A backup restored without its key cannot read them. Store each key version with the backup set it belongs to.
- Rotation: new `APP_KEY`, old value in `APP_PREVIOUS_KEYS` until every encrypted column is re-saved, then retire the old key.

## 5. Restore order

1. Declare the incident. Stop writes: `php artisan down`, stop workers and cron.
2. Provision MySQL and an **empty** schema. Never restore over a live schema.
3. Verify the dump's SHA-256, restore it, then replay binary logs to the chosen point in time.
4. Restore object storage to the same point (bucket versions or snapshot).
5. Restore secrets: the `APP_KEY` of that period, database, mail, storage and AI credentials.
6. Deploy the application release that matches the schema. Run `php artisan migrate --force` (additive migrations only) and `php artisan peopleos:sync-permissions`.
7. `php artisan config:cache route:cache view:cache event:cache`.
8. Verify (§9). Then start workers and cron, and `php artisan up`.

## 6. Application restoration

- **Boot check:** `php artisan migrate:status` shows every migration ran; `php artisan peopleos:config:validate` shows 0 errors.
- **Readiness:** `/health/ready` returns 200 with database, cache, storage, configuration and locks `ok`.
- **Audit:** `php artisan peopleos:audit:verify` passes for every chain.

## 7. Redis, queue and scheduler

| Component | Treatment |
|---|---|
| Redis cache / locks | Start empty. Rate limiters, cached settings and locks rebuild; nothing authoritative lives there |
| Queue (Redis or `jobs` table) | Jobs queued after the restore point are lost. Scheduled sweeps are idempotent (claims, reminder logs, leased claims) and simply run again. Email deliveries left `queued` / `failed` in `notification_deliveries` can be retried |
| Scheduler | Start cron on **one** node after verification. The heartbeat must reappear within 3 minutes (`/health/ready`). Once-per-period claims (`scheduler_claims`) prevent repeats within the same period |
| Failed jobs | Review before retrying; never bulk-retry jobs from before the incident without checking their side effects |

## 8. Webhooks, integrations and audit

- **Outbound webhooks:** deliveries created after the restore point are lost. Consumers dedupe on `X-PeopleOS-Delivery`. Dead letters can be replayed (audited) from the Integration Hub.
- **Inbound events (including signed BGV callbacks):** events received after the restore point are lost. Ask each source system to resend from that time. Idempotency keys make resending safe (same key ⇒ once; same key with a different body ⇒ 409).
- **Audit preservation:** audit rows are append-only and hash-chained. A restore keeps the chain intact up to the restore point (verified in §10). **Never** "repair" a chain by editing rows; record the incident and the restore point as a new audited event.

## 9. Verification checklist (run after every restore)

| # | Check | Command / evidence | Expected |
|---|---|---|---|
| 1 | Dump checksum | `sha256sum -c` | match |
| 2 | Schema | Compare `information_schema` tables / columns / indexes / FKs / checks with the source | identical |
| 3 | Row counts | Per-table counts against the last known report | consistent with the restore point |
| 4 | Migrations | `php artisan migrate:status` | all ran |
| 5 | Configuration | `php artisan peopleos:config:validate` | 0 errors |
| 6 | Audit chains | `php artisan peopleos:audit:verify` | every chain verified |
| 7 | Tenants | Tenant / user / employee counts per tenant | consistent |
| 8 | Employee 360 smoke | An HR user opens one employee's 360 | every permitted section renders |
| 9 | Storage references | Every `employee_documents.path` exists on its disk; sample fingerprints match | 0 missing |
| 10 | Cache / queue / locks | `/health/ready` | `ok` |
| 11 | Statutory gate | `php artisan peopleos:readiness` | unchanged (BLOCKED until verified) |
| 12 | Smoke test | `docs/production/smoke-test-checklist.md` | pass |

## 10. Local rehearsal (executed 3 October 2026; NOT target DR)

The runbook was rehearsed end to end on the development machine. The source was the development
database `hcm`; the target was a new disposable schema, `hcm_closure_restore`, confirmed absent first.

| Step | Result |
|---|---|
| Backup | 09:50:20 → 09:50:22 UTC (2.1 s); source 25.5 MB; dump 233 KB gzip; SHA-256 recorded and verified |
| Restore | 09:50:22 → 09:50:33 UTC (10.6 s) |
| Schema | Identical: 296 tables (plus `migrations`) |
| Row counts | Identical: 297 tables, 5,304 rows |
| Application boot on the restored schema | 108 / 108 migrations ran |
| Audit chains | Platform 40 events, demo tenant 587 events: verified |
| Tenants | 1 tenant, 2 users, 5 employees, consistent with the source |
| Employee 360 smoke | HR user: 21 sections rendered |
| Storage references | 2 / 2 document files present (local disk) |
| Cache / queue / locks | Cache round-trip ok; database queue readable (10 pending); atomic lock ok |
| Statutory gate | Still BLOCKED (24 / 0 / 5), unchanged by the restore |
| **Observed local recovery time** | **38.5 s** from backup start to verified application (restore + verification 36.2 s) |
| Observed data-loss window | 0: the backup was taken at the start of the rehearsal. In production, RPO is the time since the last dump (daily) or the binlog shipping lag |

**Clean-up:** the disposable schema and the dump file were deleted after the rehearsal. So were the
Phase 14 restore schema `hcm_p14_restore` and its dump, because both held copies of development
data. Credentials were passed through a temporary 0600 client file that was removed automatically.

## 11. RPO / RTO

| Measure | Target (to be confirmed by the business) | Measured |
|---|---|---|
| RPO | 15 minutes with binlog shipping (24 hours with daily dumps only) | **Not measured in a target environment** |
| RTO | 4 hours for a full environment | **Not measured in a target environment.** Local rehearsal: 38.5 s for a 25 MB database; production volume, object storage and infrastructure provisioning are not represented |

## 12. Target DR verification: required infrastructure and access (NOT VERIFIED)

To verify DR, operations need:

1. An isolated restore environment (staging) with MySQL 8, the application release and a shared cache.
2. Access to the production backup store and binlogs (read-only), plus the decryption key.
3. Read access to object-storage versions / snapshots, and a restore bucket.
4. The secret store entries for the backup period (`APP_KEY` versions, credentials).
5. Then a run of §5 → §9, recording: backup timestamp, restore start / end, database size, row counts, schema result, audit result, health, smoke result, **measured RPO and RTO**.
6. Repeat quarterly and after any storage, encryption or topology change. Record the evidence in the go-live checklist (section N).

## 13. Tenant-level recovery

All tenants share one schema keyed by `tenant_id`. A single tenant is recovered like this:

1. Restore to a separate disposable schema.
2. Extract that tenant's rows in foreign-key order.
3. Compare them with production.
4. Repair through domain actions, or a reviewed and audited data fix.

Never bulk-overwrite production rows: that would break the tenant's audit chain. A tenant-level
restore tool is **deferred**, so tenant recovery is an engineering-led operation with sign-off.

## 14. Rollback procedure (failed deploy)

1. `php artisan down`; stop workers and cron.
2. **Database changes in the deploy:**
   - Prefer a forward fix.
   - If a rollback is required, restore the pre-deploy dump (taken in deploy step 2) into a new schema and switch the application to it.
   - Additive migrations can be rolled back with `migrate:rollback --step=N`. Each Phase 14 `down()` was replayed on a disposable database (the closure added no migrations), but a rollback drops the data those migrations hold.
3. Redeploy the previous release, then run `config:cache` / `route:cache`.
4. Verify (§9), restart workers and cron, then `php artisan up`.
5. Record the incident. Integrations resend from the rollback point (idempotent).
