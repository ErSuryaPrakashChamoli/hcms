# Production recovery runbook

Date: 11 October 2026. **For:** whoever restores PeopleOS after an incident or runs the DR exercise.

**Status: prepared, never executed against a real environment.** No staging, production or isolated recovery target
exists (O-7). RPO and RTO are **not approved** (O-6), so this runbook measures them and does not judge them. The local
rehearsals (SaaS.11 on fictional data; SaaS.12 MySQL drill) prove the procedure's mechanics only, not production
recoverability.

This is the authoritative recovery procedure. Background, policy and the local test history stay in
`docs/operations/backup-and-disaster-recovery.md`. Deletion reconciliation details: `docs/operations/runbooks/recovery.md`.

## 0. Roles and escalation (to be filled by the owner, O-10)

| Role | Name | Contact |
|---|---|---|
| Incident commander (declares, decides go-live) | | |
| Restorer (database and storage) | | |
| Witness (second person, signs the evidence) | | |
| Escalation (owner) | | |

## 1. Preconditions

- The backup system's real artefacts: a database dump or snapshot plus binary logs; object versions; the deletion
  register's own backup.
- `APP_KEY` (and `APP_PREVIOUS_KEYS`) from the secret store, for the dump's period.
- An **empty, isolated** target. Never restore over a live schema.
- Written authorisation for a non-production exercise target.

## 2. Contain

1. Declare the incident; record the time (the RTO clock starts).
2. `php artisan down`; stop the workers and the scheduler.

## 3. Restore

1. Database:
   - verify the dump's checksum;
   - restore into the empty schema;
   - replay binary logs to the chosen point;
   - record the point (RPO).
2. Objects: documents, exports and warehouse to the same point (versioning).
3. The **deletion register is not restored from the database backup**: it lives on its own versioned disk. Point
   `PEOPLEOS_DELETION_REGISTER_DISK` at it (at a rehearsal register for an exercise, never the production one).
4. Secrets from the store. **Keep the copy inert** until §5:
   - `MAIL_MAILER=array`;
   - `PEOPLEOS_RAZORPAY_ENABLED=false`;
   - `PEOPLEOS_TENANT_DELETION_ENABLED=false`;
   - integrations and webhooks pointed at sinks;
   - no worker, no scheduler.

## 4. Bring the application up internally

1. `php artisan migrate --force` (only if the code is newer than the dump), then `php artisan peopleos:sync-permissions`.
   The sync preserves tenants' removals and audits only new permissions.
2. **`php artisan peopleos:deletions:reconcile --contain`** before anything serves traffic. Then:
   - a tenant restored past a certified deletion is suspended and flagged; nothing is deleted;
   - an identity mismatch or an invalid entry is never acted on;
   - an unreachable register makes the command fail (fail closed): fix access first.
3. `php artisan peopleos:audit:verify`: every chain valid.
4. `php artisan peopleos:readiness`: no FAIL, apart from the statutory gate (expected while blocked).
5. Spot checks:
   - row counts against the last known report;
   - a sample of documents against their fingerprints;
   - tenant isolation (a user of one tenant sees nothing of another).

## 5. Resume

1. Restore the real mail and integration settings.
2. Start the workers and the scheduler. Within 3 minutes the heartbeat is fresh; restored jobs drain without unwanted
   external effects.
3. `php artisan up`. Record the time (the RTO clock stops).
4. Re-apply each contained deletion through the ordinary workflow (`recovery.md`).
5. Post-incident monitoring for the window the owner set (O-10).

## 6. DR exercise (gate 7)

Run §2–§5 in the isolated target with production-like data under access control, or with fictional data if the owner
prefers. Additionally:
- rehearse a rollback (previous release on the restored copy);
- page the on-call contact with a synthetic `/health/ready` failure;
- destroy the copy and its keys afterwards, unless retention was approved.

## 7. Evidence and sign-off

| Field | Value |
|---|---|
| Environment and date | |
| Backup identifier, timestamp, checksum | |
| Measured RPO and RTO | |
| Approved objectives (O-6) and comparison | |
| Reconciliation output (counts) | |
| Audit verification and readiness output | |
| Isolation and document samples | |
| Failures and resolution | |
| Restorer and witness | |
| Owner acceptance | |
