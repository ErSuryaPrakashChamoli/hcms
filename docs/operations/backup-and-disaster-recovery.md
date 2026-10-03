# Backup and disaster recovery

Phase 14 (3 October 2026). **Status: documented, locally restore-tested, NOT production-verified.**
The strategy below has been exercised once, on the development machine (§8). It has not been run
against a production database, object storage, or a second site. Disaster recovery may be called
verified only after the §9 exercise succeeds in the target environment and its evidence is recorded.

## 1. What must be recoverable

| Asset | Where | Recovery source |
|---|---|---|
| Relational data (all tenants, audit hash chains, effective-dated history) | MySQL schema (one per environment) | Database backups (§2) |
| Documents, letters, certificates, grievance evidence, announcement attachments, report exports, warehouse feed | `PEOPLEOS_DOCUMENTS_DISK` (local private disk today; S3-compatible object storage for multi-node, see the blocker in `production-configuration.md`) | File / object backups (§3) |
| Encryption key (`APP_KEY`, plus `APP_PREVIOUS_KEYS` during a rotation) | Secret store | Secret-store backup (§4). **Without it, encrypted columns (bank accounts, statutory identifiers, integration secrets, idempotency responses, inbound payloads) cannot be read** |
| Configuration (`.env` values, not the file) | Secret store / deployment configuration | Infrastructure as code / secret store |
| Queue contents | Redis or `jobs` table | Not backed up: rebuildable (§6) |

## 2. Database backups

- **Method:** logical dump with `mysqldump --single-transaction --routines --triggers --hex-blob --set-gtid-purged=OFF` (consistent InnoDB snapshot without locking). At larger sizes, add physical backups (Percona XtraBackup or managed-database snapshots) plus binary-log retention for point-in-time recovery.
- **Frequency (target):** a full dump daily; binary logs shipped continuously; a fresh dump before every deploy (step 2 of the deploy sequence).
- **RPO (target):** 15 minutes with binary-log shipping; 24 hours with daily dumps only.
- **Retention:** 35 daily, 12 monthly (aligned with audit retention policy; adjust per contract).
- **Encryption:** dumps encrypted at rest (KMS / age / GPG), stored off-host, access limited to the operations role. A dump holds personal data and must be handled like production.
- **Integrity:** record the SHA-256 of every dump; verify it before any restore.

## 3. File and object backups

- **Local disk (single node):** nightly `rsync` / snapshot of `storage/app/private` to encrypted off-host storage, taken at the same time as the database dump.
- **S3-compatible storage (multi-node):** bucket versioning, object lock / retention for evidence-bearing prefixes (`tenants/*/grievances`, letters, certificates), cross-region replication. No public access. Server-side encryption.
- **Fingerprints:** documents, certificates, announcement attachments and (Phase 14) grievance evidence carry SHA-256 values in the database. After a restore, a download whose fingerprint no longer matches is refused (409), so tampering or a partial restore cannot go unnoticed.

## 4. Encryption keys

- `APP_KEY` lives in the secret store with its own backup, separate from the data backups.
- Rotating the key: set the new `APP_KEY`, move the old value to `APP_PREVIOUS_KEYS`, keep both until every encrypted column has been re-saved, then retire the old key. A backup taken before a rotation needs the key that was current when it was taken.
- Losing every copy of `APP_KEY` loses the encrypted columns permanently. Treat it as a tier-0 secret.

## 5. Restore procedure (whole environment)

1. Declare the incident; stop writes (`php artisan down`, stop workers and cron).
2. Provision the database server and an **empty** schema. Never restore over a live schema.
3. Verify the dump's SHA-256, then restore: `gunzip -c dump.sql.gz | mysql <schema>`, and replay binary logs up to the chosen point in time.
4. Restore files / objects to the same point in time.
5. Restore secrets (`APP_KEY` from the time of the dump, database / mail / storage credentials).
6. `php artisan migrate --force` (only if the code is newer than the dump; migrations are additive), then `php artisan peopleos:sync-permissions`.
7. Verify:
   - `php artisan peopleos:audit:verify` passes for every chain;
   - `php artisan peopleos:readiness` shows no FAIL;
   - row counts are consistent with the last known report;
   - downloading a sample document passes its fingerprint check.
8. Bring workers and cron back, then `php artisan up`.
9. Replay external work (§6), then close the incident with the evidence recorded.

**RTO (target):** 4 hours for a full environment from daily dumps on the current data volume. It must be measured in the §9 exercise. The local restore below took 12 seconds for the development data set, which says nothing about production volume.

## 6. Queues, integrations and webhooks after a restore

- **Queued jobs:** not restored. Scheduled sweeps are idempotent (claims, reminder logs, leased claims) and simply run again. Undelivered email deliveries stay `queued` / `failed` in `notification_deliveries` and can be retried.
- **Inbound integration events:** events received after the restore point are lost from PeopleOS. Ask each source system to resend from that time. The idempotency key per system makes resending safe (same key ⇒ recorded once; same key with a different body ⇒ 409).
- **Outbound webhooks:** deliveries created after the restore point are lost. Consumers dedupe on the delivery id; replay dead letters from the Integration Hub if needed.

## 7. Tenant-level recovery

PeopleOS keeps every tenant in one schema, keyed by `tenant_id`. Restoring one tenant without
touching others goes like this:

1. Restore the backup to a **separate, disposable** schema.
2. Export that tenant's rows (all tables with `tenant_id`, in foreign-key order) and compare them with production.
3. Repair through domain actions or a reviewed, audited data fix. Overwriting production rows in bulk would break the tenant's audit hash chain.

A tenant-level restore tool is **not built** (deferred). Until it exists, any tenant-level recovery is
an engineering-led operation with sign-off.

## 8. Local restore test (executed 3 October 2026)

| Step | Result |
|---|---|
| Source | Development database `hcm`, after the Phase 14.2 / 14.3 migrations and before the 14.5 / 14.6 migrations were applied |
| Dump | `mysqldump --single-transaction --routines --triggers --no-tablespaces --hex-blob --set-gtid-purged=OFF`, gzip: 2 s, 220 KB |
| Target | New disposable schema `hcm_p14_restore` (confirmed absent beforehand; never the development database) |
| Restore | `gunzip -c … \| mysql hcm_p14_restore`: 12 s, exit 0 |
| Schema comparison | Identical: 295 tables, columns, indexes, foreign keys, CHECK constraints |
| Row counts | Identical in all 296 tables (5,301 rows) |
| Audit chains on the restored copy | `peopleos:audit:verify`: platform 40 events, demo tenant 587 events, all verified |
| Credentials | Passed through a temporary 0600 client file; never printed |

**What this proves:** the dump format restores cleanly and completely on the same MySQL version, and
the audit hash chains survive dump and restore.

**What it does not prove:**
- production data volume;
- object-storage restore;
- key recovery from the secret store;
- binary-log point-in-time recovery;
- cross-host or cross-region restore;
- the RTO / RPO targets.

## 9. Verification still required (deployment blocker)

Before go-live, in the target environment, operations must:

1. Restore the latest production-like backup into an isolated environment, using the §5 procedure end to end, including files and `APP_KEY` from the secret store.
2. Record: backup timestamp, restore start / end (measured RTO), data loss window (measured RPO), audit verification output, readiness output, sample document fingerprint checks.
3. Repeat at least quarterly, and after any change to storage, encryption or database topology.
4. Record the evidence with the operations sign-off. Only then may the readiness item "Backup restored and verified in the target environment" be treated as satisfied.
