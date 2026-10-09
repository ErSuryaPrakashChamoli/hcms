# Runbook: signup failure recovery and reconciliation

Audience: platform operators and the infrastructure owner. Everything here is done from **Platform → Signups** or the
console. Never edit `signup_applications` by hand: its state changes are conditional and audited, and a manual update
would bypass both.

## 1. What can and cannot be left behind

- **A failed set-up leaves nothing.** Provisioning is one transaction: tenant, roles, settings, defaults, the Account
  Owner, the trial and the application's links commit together or not at all.
- **The invitation e-mail is the only step after the commit.** If it fails, the tenant exists, but nobody can sign in
  to it: the owner is `invited` with an unusable password.

## 2. Situations

| Situation | Signs | Recovery |
|---|---|---|
| Transient database error (deadlock, lock timeout, slug collision) | `Set-up failed (retrying)`, `PROVISIONING_ERROR`, next attempt shown | None needed: the tick retries (1, 5, 15, 60 minutes) |
| Repeated error | `Set-up needs attention`, attempts = max | Read the class and line in the log (`Signup provisioning failed`, by reference); fix the cause; **Retry the set-up now** |
| Configuration changed since the applicant applied | `Set-up needs attention` with `NOT_OFFERED` / `PLAN_VERSION_*` / `MARKET_*` / `PRICE_NOT_CONFIGURED` | Restore the configuration and retry, or reject or cancel (the applicant must accept the current offer in a new application) |
| Address taken meanwhile | `EMAIL_IN_USE` | Reject or cancel; ask the applicant for another address |
| Worker or server stopped mid-run | `Setting up` for longer than the lease (15 min) | The tick recovers it (`INTERRUPTED`) and retries. Nothing of the stopped run was committed |
| Several approvals at once | Approved applications are set up one after another (one at a time, about 2 s each); an approval that finds another set-up running leaves the application `Approved` for the tick | None: it is set up within a minute |
| Tick not running | Approved applications stay `Approved`; nothing expires | Check the scheduler (`schedule:run` every minute, one server). Run `php artisan peopleos:signups:tick` once by hand to confirm it works |
| Invitation refused by the mail transport | `Waiting for activation`; activation e-mail shows "not sent (n refused)" | Fix mail; the tick retries (5 times, every 5 minutes); or **Send a new activation e-mail** |
| Invitation sent but expired | The applicant says the link does not work | **Send a new activation e-mail**, or the applicant uses "E-mail me a new activation link" on their registration page |
| Owner never activates | `Waiting for activation` for a long time | Nothing automatic (D10-10). Contact the applicant; the tenant can be offboarded through the normal SaaS.9 flow |
| Activation not reflected | Owner signed in, application still `Waiting for activation` | The tick reconciles within a minute; run it by hand if the scheduler is down |

## 3. Reconciliation checks (read only)

Run on a copy or with read-only credentials:

```sql
-- Applications whose tenant exists but are not marked: must be zero (provisioning sets both together).
SELECT reference FROM signup_applications WHERE provisioned_tenant_id IS NOT NULL AND status NOT IN ('activation_pending', 'active');

-- Runs past their lease that the tick has not yet recovered (should clear within a minute of the tick running).
SELECT reference, claimed_at FROM signup_applications WHERE status = 'provisioning' AND claimed_at < NOW() - INTERVAL 15 MINUTE;

-- Owners active but applications not yet marked (should clear within a minute).
SELECT a.reference FROM signup_applications a JOIN users u ON u.id = a.provisioned_user_id WHERE a.status = 'activation_pending' AND u.status = 'active';
```

If the first query returns rows, stop and escalate: it would mean a manual change bypassed the services.

## 4. Switching registration off during an incident

Set `PEOPLEOS_PUBLIC_SIGNUP_ENABLED=false` and reload the configuration (`php artisan config:cache` where it is
cached). New signups are refused at once:
- applications already submitted still finish or expire through the tick;
- existing tenants are unaffected.
