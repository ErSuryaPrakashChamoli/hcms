# Runbook: backups and external copies after a tenant deletion

Audience: Markedge platform operators and the infrastructure owner. Page: **Platform → Offboarding → Deletion →
Steps and external dependencies**.

A tenant deletion removes the tenant's data from PeopleOS's own database and file storage. Copies elsewhere are not
deleted by PeopleOS, and the certificate never claims they are:
- it states its scope;
- it lists every external copy that is not confirmed deleted (`external_copies_outstanding`);
- `complete_including_external_copies` is true only when every external copy is `CONFIRMED`.

The page shows the same, live.

## What PeopleOS deletes itself

| Copy | How |
|---|---|
| Database rows (every eligible category) | Deletion steps, in batches |
| Referenced files and the tenant's storage prefixes (documents, attachments, certificates, evidence, statutory exports) | `files` and `stored` steps |
| Warehouse snapshots (`warehouse/{slug}`) | `stored` step of `warehouse_exports` |
| Export packages still stored | Removed when the deletion finishes; the export record, checksums and manifest stay |

## What PeopleOS cannot delete (external copies)

| Entry | Starts as | Who acts | Typical evidence |
|---|---|---|---|
| Database backups | PENDING | Infrastructure owner | Backup schedule and the date the last backup containing the tenant expires; or the provider's deletion confirmation |
| File storage backups and replicas | PENDING | Infrastructure owner | Object versioning and replication settings; lifecycle expiry date; provider confirmation |
| Each integration system the tenant used | PENDING | The customer (or Markedge, by contract) | The external system's deletion confirmation |
| Webhook receivers | UNSUPPORTED | The customer | (the customer's own systems) |
| Payment provider | UNSUPPORTED | — | Markedge's financial records, retained |
| E-mail delivered | UNSUPPORTED | — | Recipients' mailboxes and the provider's logs |

## Recording a status (choose **Record** on the step)

| Status | When | Required |
|---|---|---|
| REQUESTED | You asked the external party to delete | Reason (ticket or reference) |
| FAILED | The request failed or was refused | Reason; request again later |
| RETENTION_REQUIRED | The copy must be kept for now (a backup's own retention, a legal hold at the provider) | **Kept-until date** (today or later), and the reason |
| UNSUPPORTED | The external party cannot delete on request | Reason |
| CONFIRMED | Written confirmation received, or the copy has demonstrably aged out | Reason with the evidence. **Final**: it cannot be changed afterwards |

**Rules:**
- Never record `CONFIRMED` without evidence.
- Changes are conditional, so two operators recording at once make one change, and each record is audited
  (`EXTERNAL_DELETION_CONFIRMED`).
- The certificate shows the statuses at the time it was issued. Later records are in the platform audit trail and on
  the page.

## Backups that outlive a deletion

1. **When the deletion completes,** record each backup entry as `RETENTION_REQUIRED`, with the date the last backup
   containing the tenant ages out. Under the DR runbook's targets (35 daily, 12 monthly) that is up to about 12 months.
2. **If a backup is restored before that date,** re-apply the deletion to the restored database **before** it serves
   traffic:
   - the deletion reference and tenant id are on the certificate;
   - restoring must never resurrect a deleted tenant.
3. **When the date passes,** record `CONFIRMED`, citing the backup system's expiry record.

## Gaps (not available in this environment)

- **No production backups.** No production backup system, object storage or replica exists in this workspace. Backup
  and restore were rehearsed locally only (`docs/production/disaster-recovery.md`).
- **Re-application is deliberate, not automatic.** A restored tenant is detected and contained automatically, and its
  deletion is re-applied through the ordinary workflow (holds, retention, maker-checker, cooling-off). See `recovery.md`.
- **Deletion register (SaaS.12, closes S11-5).** Each completed deletion's signed certificate is written to the
  deletion register (`PEOPLEOS_DELETION_REGISTER_DISK`), outside the database, and `peopleos:deletions:reconcile` finds
  and contains a tenant a restore brought back. See `recovery.md` (restore from backup). Production refuses deletion
  until the register disk is configured; that disk must be private, versioned and backed up separately from the
  database.
