# Production-copy rehearsal plan: tenant export, hold, deletion, certificate

Status: **plan only. Not executed.** Run it later, in an isolated, access-controlled copy of production, before
deletion is ever enabled in production. Never run it against production itself.

Roles:
- **Rehearsal lead** (owner of the evidence).
- **Two platform operators** (maker and checker, different people).
- **Infrastructure owner.**
- **Counsel or compliance reviewer** for the retention values used.

## 0. Preconditions

- [ ] **Isolated environment:**
  - separate network;
  - no production DNS or mail;
  - its own `APP_KEY` copy from the secret store, held only by the rehearsal lead;
  - queue workers and the scheduler running there only.
- [ ] **Neutralised outbound traffic.** Mail goes to a sink (`MAIL_MAILER=log` or a capture service). Webhooks,
  integrations and payment providers point to blackholes. Razorpay stays in test mode, never live.
- [ ] **Deletion enabled in that environment only.** Set `PEOPLEOS_TENANT_DELETION_ENABLED=true`, and record the
  environment's hostname in the evidence. Production keeps it `false`.
- [ ] **A clearly identifiable test tenant.** Choose one tenant to rehearse on (or a copy of one, renamed
  "REHEARSAL – …"). Record its id and slug.
- [ ] **Approved rehearsal retention policies.** These are entered for the rehearsal only, with reason "rehearsal" and
  values agreed with counsel. They are never copied to production.

## 1. Restore

1. Restore the latest production backup (database and file storage) into the isolated environment, following
   `docs/production/disaster-recovery.md`.
2. Record:
   - the backup timestamp;
   - the restore start and end (measured RTO);
   - the data-loss window (RPO).
3. Run `php artisan peopleos:audit:verify` and `php artisan peopleos:readiness --json`, and keep both outputs.
4. **SaaS.12:**
   - Point `PEOPLEOS_DELETION_REGISTER_DISK` at a **rehearsal** register, never the production one: a production register
     on a copy that shares its `APP_KEY` would contain the copy's tenants.
   - Run `php artisan peopleos:deletions:reconcile` and keep the output.

## 2. Credentials and secrets

1. **Rotate staff accounts.** Rotate every operator and customer-administrator credential in the copy: random
   passwords, then `SessionSecurity::revokeUser` for each.
2. **Replace provider secrets.** Remove or replace integration secrets, webhook signing secrets and provider keys with
   rehearsal values (never the production ones).
3. **Confirm in the evidence that no production secret was copied into the repository or the logs.**

## 3. Tenant isolation

Pick a second tenant as the control. Record its row counts per table (`tenantRowCounts`) and its storage file list.
These are compared in step 17.

## 4–5. Export, signature and checksums

1. The maker requests an export of the test tenant, and the worker generates it. Record the duration, size, record count
   and file count.
2. Download it through the signed link. Check that the link expires and refuses another person.
3. Verify:
   - the HMAC signature of `manifest.json` against `manifest.sig`;
   - `sha256sum` of every file against the manifest;
   - the record counts against the database;
   - that no secret column is present.
4. Record the delivery on the page.

## 6–7. Legal hold, then release with a second person

1. Place a hold on the whole test tenant. Plan a deletion and confirm the plan shows `LEGAL_HOLD` and that nothing is
   eligible.
2. Operator A requests release. Show that operator A cannot approve it. Operator B approves.

## 8. Retention eligibility

1. Check the eligibility table:
   - each category's reason;
   - kept-until date;
   - legal minimum state (verified, pending or not configured);
   - customer-contract terms and conflicts.
2. Confirm that a category without a policy shows `RETENTION_POLICY_NOT_CONFIGURED`.

## 9–11. Plan, maker-checker, cooling-off

1. The maker plans. Keep the plan JSON and its fingerprint.
2. Show that the maker cannot approve. The checker approves.
3. Show that nothing runs before the cooling-off ends: run the tick and the worker, and the deletion stays approved. For
   the rehearsal you may set `PEOPLEOS_DELETION_COOLING_OFF_DAYS=0` **only in that environment**, and record that you
   did.
4. Show that a hold placed after the approval stops the run at its next batch (optional repeat of the MySQL race 4
   scenario).

## 12. Execute (the isolated copy only)

Let the worker run. Record:
- duration;
- steps;
- per-step counts;
- failures and retries, if any.

If it fails, retry, and record the resumption.

## 13–14. Retained records; files and warehouse

1. Markedge's invoices, payments, billing and subscriptions must be unchanged (counts and checksums).
2. Categories kept by retention must be untouched.
3. The tenant's storage prefixes and warehouse folder must be empty, and its export package files removed.

## 15. Backups and external copies

1. Record each external entry's status: database backups, file storage backups and replicas, integrations.
2. Confirm the certificate says `complete_including_external_copies: false` while any is not confirmed.

## 16. Certificate

1. Download it. Verify its signature. Confirm that the rehearsal register holds the same entry
   (`deletion-register/<reference>.json`, the same SHA-256).
2. **Restore drill (SaaS.12):**
   - restore the step 1 backup again into a second isolated schema;
   - run `peopleos:deletions:reconcile --contain`;
   - confirm that the rehearsed tenant is reported as restored past its deletion and is suspended, and that nothing was deleted;
   - re-apply it through the ordinary workflow and confirm RE-APPLIED.
3. Check that the certificate lists:
   - categories deleted, retained (with reasons) and anonymised;
   - holds;
   - the export reference and checksum;
   - approver and executing process;
   - its scope and outstanding external copies.

## 17. No other tenant affected

1. The control tenant's row counts and file list must equal step 3's.
2. Both audit chains verify.

## 18. Evidence and sign-off

Keep in one evidence folder, with no secrets:
- the readiness and audit outputs;
- the plan JSON;
- the certificate;
- screenshots;
- timings;
- the control-tenant comparison;
- any failures and how they were resolved.

Sign-off by the rehearsal lead, both operators and the reviewer. Then **destroy the isolated environment**, including
its backups and the copied `APP_KEY`.
