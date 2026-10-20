# Tenant operations runbook

How Markedge operators run tenants in PeopleOS without database access (SaaS.8). Every action below requires:
- a platform operator account (no tenant, MFA);
- a reason, recorded with who and when on the tenant's audit chain and on the platform chain.

Pages for platform operators only (tenant users get 403):
- **Tenants**;
- **Operations** (`/admin/platform-operations`);
- **Platform audit trail** (`/admin/platform-audit`);
- **Platform readiness**.

```
PROVISION ─► INVITE ─► (customer) VERIFY ─► MFA ─► ACTIVATE ─► CONFIGURE ─► SUBSCRIBE ─► OPERATE ─► SUSPEND ⇄ REACTIVATE
```
Export, offboarding and deletion are not available yet (WS7, decision D-8).

## 1. Provision a tenant

1. **Tenants → New**:
   - name, slug, tier, data region, trial end (metadata), locale defaults;
   - the first administrator's name and e-mail;
   - a **reason** (required), e.g. the signed order form reference.
2. PeopleOS creates the tenant with its system roles, features, settings and organisation defaults in one transaction, then e-mails the administrator an invitation. The administrator chooses their own password; no operator ever sets or sees it.
3. **If the page says "Tenant created, but the invitation e-mail was not sent":** the tenant exists, so do not create it again. Once mail works, use **Send invitation** (section 2). The failure is in the platform audit trail as *Invitation Failed*.

## 2. Send (or re-send) the first administrator's invitation

**Tenants → Send invitation** (shown while the first administrator has not accepted):
- Give a reason, e.g. "Customer lost the e-mail" or "Mail outage resolved".
- The new link replaces any earlier one, which stops working.
- **Refused when:**
  - an invitation was sent less than a minute ago (two operators clicking at once send one);
  - the tenant is suspended;
  - the administrator has already accepted.
- The invitation is valid for `PEOPLEOS_INVITATION_HOURS` (72).

**Operations** flags tenants whose first administrator holds no invitation they can still accept: *Invitation not sent*.

## 3. Suspend a tenant

**Tenants → Suspend**, with a reason (abuse report, legal request, security incident, non-payment decision).
- Every user is signed out at their next request. API keys, SSO, password reset and invitation links stop working, and protected downloads are refused.
- **Scheduled work** for the tenant pauses.
- **Queued work** that reaches a worker is **kept** (deferred), not run and not lost.
- **Payment-provider reconciliation** still runs.
- PeopleOS records the status the suspension interrupted (*trial* or *active*), shown on **Operations** as "since <date> (was trial)".
- Two operators suspending at once produce one suspension and one audit record.

To look inside a suspended tenant (support, investigation), use **Enter tenant**: time-boxed, with a reason and audited.

## 4. Reactivate a tenant

**Tenants → Reactivate**, with a reason.
- The tenant returns to the status it had (*trial* stays *trial*). A tenant suspended before SaaS.8 returns to *active*.
- **Deferred work:** queued jobs deferred during the suspension are dispatched again, each once. The reactivation's audit record shows how many.
- **Sessions:** users sign in again. Sessions that existed before the suspension stay ended.

**If Operations still shows deferred jobs for an active tenant** (a worker or database failure interrupted the release): select the tenant on **Operations → Release deferred work**, with a reason. Each job runs once, even if the release is started twice.

## 5. Change a tenant's record

**Tenants → Edit**: name, slug, tier, data region, trial end, locale defaults, and a **reason** (required). The status never changes here (use Suspend / Reactivate). The change, before and after, is on both audit chains (*Tenant Profile Changed*).

## 6. Daily checks on Operations

| Column | Meaning | What to do |
|---|---|---|
| Deferred jobs | Work queued while suspended | Nothing while suspended. If shown for an active tenant, use Release deferred work |
| Notifications failed (7 d) / stuck | Failed deliveries; deliveries whose worker stopped mid-send (the sweep marks these failed within 5 minutes) | Tell the tenant's administrator: they can **Send again** from **Communication → Delivery log** (permission `notification.resend`), after checking the recipient did not receive it |
| Failing webhooks | The tenant's endpoints with consecutive failures | Ask the tenant to fix the endpoint; deliveries retry, then dead-letter |
| Dead letters | Inbound integration events that exhausted their retries | The tenant reprocesses them from the Integration Hub |
| First admin | *Invitation not sent* | Section 2 |
| Audit chain | Latest verification of the tenant's chain | *Broken*: section 8. *Not verified*: run `php artisan peopleos:audit:verify --tenant=<slug>` |

**Platform-wide:**
- runtime health (database, cache, queue backlog, failed jobs, scheduler heartbeat, integrations);
- the platform chain's verification;
- failed jobs by job class. Inspect them with `php artisan queue:failed` and retry with `php artisan queue:retry <id>` once the cause is fixed. They are pruned after `PEOPLEOS_FAILED_JOBS_RETENTION_HOURS` (30 days).

## 7. Platform audit trail

**Platform audit trail** lists what operators did:
- provisioning, edits, suspensions, reactivations, tenant entry, invitations, deferred-work releases;
- plans, subscriptions, billing and configuration.

You can filter by action, tenant concerned and dates. Select an event for its reason, request id, details and before/after values. Tenant-internal audit trails are not shown here: enter the tenant to read them.

## 8. Audit integrity

- `peopleos:audit:verify --scheduled` runs daily at 02:30 and records one result per chain.
- **Platform readiness** shows them:
  - *PASS*: every chain intact within 48 hours;
  - *FAIL*: a chain broken;
  - *WARN/STALE*: not verified recently.

**A broken chain is a security incident:**
1. Do not modify audit rows.
2. Note the tenant and the event id from Operations or Platform readiness.
3. Preserve the database (snapshot) before anything else.
4. Run `php artisan peopleos:audit:verify --tenant=<slug>` to confirm.
5. Follow the incident process in `docs/production/disaster-recovery.md` (restore comparison from backup).

## 9. Tenant administrators: what changed for them

- **Delivery log → Send again** (permission `notification.resend`, held by the system roles Tenant Super Admin and Tenant HR Admin):
  - sends a failed notification again, with a reason, once;
  - if its outcome is *unknown* (the worker stopped while sending), it may already have arrived: check with the recipient first.
- **Security & retention policy → Data warehouse feed:**
  - the daily warehouse export is **off** until the tenant enables it;
  - daily snapshots are deleted after the window set there (default 30 days; 0 keeps them).

  Before SaaS.8 every tenant was exported daily and nothing was deleted. Enable the feed explicitly if a warehouse consumes it.
- **Employee and legal-entity codes** are checked for duplicates within the organisation only. Another organisation using the same code no longer blocks a save.

## 10. Deploying this release

1. Run `php artisan migrate --force` before the new code serves requests or works the queue. The audit recorder reads through `audit_events_chain_head_index`; without it every audited write fails.
2. Run `php artisan peopleos:sync-permissions` (adds `notification.resend`).
3. Restart the queue workers.

To roll back, revert the code first, then `php artisan migrate:rollback --step=4`.

## 11. Configuration reference

| Setting | Default | Where |
|---|---|---|
| `PEOPLEOS_NOTIFICATIONS_SENDING_LEASE_MINUTES` | 15 | Stuck `sending` threshold |
| `PEOPLEOS_NOTIFICATIONS_REQUEUE_AFTER_MINUTES` | 60 | Lost `queued` threshold |
| `PEOPLEOS_INVITATION_RESEND_SECONDS` | 60 | Invitation throttle |
| `PEOPLEOS_AUDIT_VERIFICATION_MAX_AGE_HOURS` | 48 | Readiness STALE |
| `PEOPLEOS_FAILED_JOBS_RETENTION_HOURS` | 720 | Failed job and batch pruning |
| `PEOPLEOS_HSTS_MAX_AGE` | 31536000 | HSTS on HTTPS (0 = off) |
