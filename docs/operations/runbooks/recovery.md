# Runbook: exit recovery (failed or stopped exports and deletions)

## A deletion shows "Failed"
1. Read the failure on the page. It is the exact reason, for example:
   - `A legal hold (…) now covers people: the deletion stopped before deleting more.`
   - `A file of … could not be deleted.`
   - `An export of this tenant is being generated…`
   - a database error.
2. Fix the cause:
   - a storage permission;
   - the database;
   - wait for the export to finish;
   - for a hold: nothing to fix. The held categories are kept.
3. Choose **Retry (resume)** with a reason. The worker re-plans, skips finished steps and anything no longer eligible,
   and continues.
4. Never edit `tenant_deletion_steps` or delete rows by hand. A manual delete is not certified, and can break the audit
   chain or foreign keys.

## A deletion is "In progress" but nothing moves
- The tick marks it failed once its heartbeat is older than 30 minutes. Then retry.
- Check that the queue worker runs (`php artisan queue:work`) and that the tick is scheduled (`php artisan schedule:list`
  shows `peopleos:offboarding:tick`).

## An export shows "Failed" or stays "Generating"
- "Generating" past 30 minutes with no progress: the tick resumes it from its checkpoint, up to 5 attempts.
- "Failed": fix the cause (disk space, storage credentials), then **Retry**. A retry restarts the package from scratch.

## Reopen after a failed deletion?
No. Once a deletion has started, the tenant cannot be reopened. Retry instead, or contact the owner.

## Restore from backup
A deleted tenant is not restored into production. Backups expire on their own schedule (see
`docs/operations/backup-and-disaster-recovery.md`). The deletion's external dependency "backups" records when the last
backup containing the tenant expired.

### After any restore: deletion reconciliation (SaaS.12)

Every completed deletion's signed certificate is also kept in the **deletion register**. The register lives on its own disk
(`PEOPLEOS_DELETION_REGISTER_DISK`), outside the database, and is backed up and versioned separately. A restored database
can predate deletions; the register cannot.

1. Before the restored copy serves traffic and before workers and the scheduler start, run
   `php artisan peopleos:deletions:reconcile --contain`. It exits non-zero while anything needs a person. The offboarding
   tick repeats the containment every 15 minutes once the scheduler runs.
2. Read each outcome:

   | Outcome | Meaning | Action |
   |---|---|---|
   | CONSISTENT | The database holds this completed deletion | None |
   | RE-APPLIED | A later deletion re-applied it | None |
   | NOT PRESENT | The restore predates the tenant itself | None |
   | RESTORED PAST A DELETION | The tenant is back with data its deletion removed | Contained automatically (the tenant is suspended; `DELETION_REAPPLICATION_REQUIRED` on both chains). Re-apply it (step 3) |
   | IDENTITY MISMATCH | A tenant with the same id but another address | **Never acted on.** Establish whether it is the same customer before anything else |
   | INVALID | The entry fails its hash or signature (tampering, or a key the installation does not hold) | **Never acted on.** Investigate; after a key rotation set `APP_PREVIOUS_KEYS` |
   | UNREGISTERED (reported separately) | A completed deletion without a register entry | The tick writes it; check the register disk |
3. **Re-apply through the ordinary workflow.** Nothing replays automatically, because the restored data may differ from the
   original (a legal hold, a retention term or an open financial item may be back):
   - start the offboarding, close it, deliver or waive the export;
   - plan the deletion (one operator). The plan lists the deletions it re-applies (`reapplies`), with the original
     approval;
   - approve (a **second** operator, against the original certificate). The cooling-off applies;
   - when it completes, its certificate also lists what it re-applied, and reconciliation shows RE-APPLIED.

   If a legal hold or a retention requirement in the restored data stops the re-application, the tenant stays suspended
   and readiness shows a warning until people resolve it. It is never deleted around the hold.

**If the register cannot be read (SaaS.13):** reconciliation fails closed.
- No deletion can be planned (it cannot be checked against earlier certified deletions).
- Readiness fails in production.
- The offboarding tick logs a critical error and exits non-zero.

Restore access to the register disk before anything else. A register that is reachable but **empty or lost** cannot
reveal deletions the restored database predates. That is why it must be versioned, ideally object-locked, and backed up
separately from the database.
