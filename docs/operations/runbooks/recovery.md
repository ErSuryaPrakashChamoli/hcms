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
