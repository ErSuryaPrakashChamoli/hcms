# SaaS.10 — Self-service signup and tenant provisioning

Status: built, switched off. Public registration stays disabled (`PEOPLEOS_PUBLIC_SIGNUP_ENABLED=false`) until the
owner authorises it after a security review (D10-1). Discovery and owner decisions are in
`docs/saas/SaaS-10-Discovery-and-Architecture.md`; threats are in `docs/saas/SaaS-10-Threat-Model.md`.

## 1. What it does

A prospective customer:
1. chooses a country and a plan offered there;
2. confirms a work e-mail address;
3. gives the organisation's details and accepts the current Terms of Service and Privacy Notice;
4. is approved automatically or by a Markedge operator;
5. receives a provisioned tenant with an **Account Owner** (an explicit role, not Tenant Super Admin) and, if the offer
   has one, a trial;
6. chooses their password through the existing one-time invitation link;
7. sees a first-run "Getting started" page in their tenant.

Nothing in this flow invents a price, a trial length, a country list or terms. All of them are approved,
effective-dated commercial configuration (§6). Missing configuration is a stated refusal code, never an assumption.

## 2. Components

| Component | Role |
|---|---|
| `Signup\Enums\SignupStatus` | States and allowed transitions (`TRANSITIONS`), open states (`OPEN`) |
| `Signup\Models\SignupApplication` | The application, a platform record. It is never deleted, and its link hash, fencing token and IP are hidden from serialisation |
| `Signup\Support\SignupTransitions` | The only writer of the status: conditional updates along `TRANSITIONS` (architecture test) |
| `Signup\Services\SignupCatalogue` | What is offered today, from approved configuration: offers, plan version on sale, market, price, entitlements, employee limit, terms, review policy. Also the operators' readiness view |
| `Signup\Services\SignupEligibility` | Review reasons (server-side), the plan limit, and the provisioning re-check (offer, version, market, price, limit, the address still free) |
| `Signup\Services\SignupApplications` | The applicant: start, confirm (POST), submit, cancel, request a new activation e-mail |
| `Signup\Services\SignupProvisioning` | Claim with a fencing token, then one transaction for tenant, owner and trial, then the invitation after commit. Records failures safely |
| `Signup\Services\SignupReview` | Operators: approve (then provision as the operator), reject, hold for review, retry, cancel, resend activation |
| `Signup\Services\SignupMaintenance` + `peopleos:signups:tick` | Every minute, on one server: expiry, interrupted-run recovery, due provisioning, invitation retries, activation reconciliation |
| `Signup\Support\SignupAudit`, `SignupMailer` | Audit on the platform chain (and on the tenant chain once it exists). E-mails are sent directly and record what the mail transport did |
| `Signup\Services\TenantSetupProgress` + `Filament\Pages\GettingStarted` | The first-run checklist and the Home banner (signup tenants only) |
| `Filament\Signup\SignupStart`, `SignupConfirm`, `SignupApplicationPage` | The guest pages: `/admin/signup`, `/admin/signup/confirm/{token}` and `/admin/signup/application` |
| `Filament\Pages\PlatformSignupsPage` | Operators: readiness, applications, details, live eligibility, actions, audit trail |
| `Identity\Services\RoleGrants` | The delegation ceiling that makes the Account Owner's boundary real |

Reused without a parallel implementation:
- `ProvisionTenantAction`, split into a transactional core and a post-commit invitation;
- `UserInvitations` and `TenantFirstAdministrator`, for activation;
- `CommercialSubscriptions`, through one new entry point, `startSignupTrial`;
- `CommercialConfiguration`, for the policy keys;
- `PlanVersion`, `BillingMarket` and `BillingCatalog`;
- `AuditRecorder`, `PlatformOperator`, `ScreenAccess`, `DataInventory` and `PasswordPolicy`/`MultiFactor`, as they are.

## 3. State machine

| From | To | Who | Guard |
|---|---|---|---|
| (none) | `email_pending` | applicant | Switch on; plan and country offered; terms configured; rate limits; no account and no open application for the address (otherwise an e-mail only) |
| `email_pending` | `details_pending` | applicant (POST) | Link hash matches, not expired; the link is consumed |
| `email_pending` | `expired` | tick | The link expired |
| `details_pending` | `review_pending` | applicant | The submission is valid and some review reason applies |
| `details_pending` | `approved` | applicant | Valid, policy `flagged`, nothing flagged |
| `details_pending` | `expired` | tick | The completion window passed |
| `review_pending` | `approved` | operator | Eligibility re-checked, reason given |
| `review_pending` | `rejected` | operator | Reason given |
| `approved` | `provisioning` | tick (console), or the approving operator | Conditional claim with a new fencing token |
| `provisioning` | `activation_pending` | the run holding the token | One transaction: tenant, owner, trial, links |
| `provisioning` | `failed_retryable` | the run, or the tick (lease) | A retryable error, attempts left |
| `provisioning` | `failed_manual_review` | the run, or the tick | A stated eligibility refusal, or attempts exhausted |
| `failed_retryable` | `provisioning` | tick after the backoff, or operator | Conditional claim |
| `failed_retryable` | `failed_manual_review` | operator | Reason |
| `failed_manual_review` | `provisioning` | operator | Reason |
| `failed_manual_review` | `rejected` | operator | No tenant exists (the failed run rolled back) |
| `activation_pending` | `active` | tick | The owner's account is active (password chosen) |
| `email_pending`, `details_pending`, `review_pending`, `approved`, `failed_*` | `cancelled` | applicant, or an operator with a reason | Before provisioning starts |

`rejected`, `expired`, `cancelled` and `active` are terminal. A new attempt is a new application, and one after a
rejection is flagged `PREVIOUSLY_REJECTED`.

## 4. Data flow

```mermaid
sequenceDiagram
    actor A as Applicant
    participant P as Signup pages
    participant S as Signup services
    participant O as Operator
    participant T as Tick (console)
    participant C as Core (ProvisionTenantAction + CommercialSubscriptions)
    A->>P: country, plan, e-mail, name, company
    P->>S: start (Timebox 400 ms, rate limits, honeypot)
    S-->>A: e-mail: link (new / continue) or "you already have an account"
    A->>P: opens link, presses Continue (POST)
    P->>S: confirm: consume link, new session id, application bound to session
    A->>P: details, Terms vX, Privacy vY, authority
    P->>S: submit: eligibility, review reasons, pinned plan version, market, trial days
    alt review required
        O->>S: approve (re-check) → provision as operator
    else no review
        T->>S: provision (console)
    end
    S->>C: claim → one transaction: tenant + Account Owner (invited) + trial → activation_pending
    S-->>A: after commit: the existing one-time invitation link
    A->>P: chooses password (AcceptInvitation)
    T->>S: reconcile → active; contact name and address removed from the application
    A->>P: signs in → Getting started
```

## 5. Data model

`signup_applications` is a platform table: the application exists before its tenant. Its columns:

| Group | Columns |
|---|---|
| Identity | `reference` (ULID), `status` |
| Contact (removed at activation) | `email`, `email_hash`, `contact_name` |
| Organisation | `company_name`, `legal_name`, `display_name`, `country_code`, `timezone`, `intended_employees` |
| Accepted offer | `plan_code`, `plan_version_id`, `billing_market_id`, `trial_days` |
| Acknowledgements | `terms_version`, `privacy_version`, `authority_confirmed`, `acknowledged_at`, `acknowledged_ip` |
| Link | `token_hash`, `token_expires_at`, `token_sent_at`, `tokens_sent`, `email_verified_at` |
| Review | `review_required`, `review_reasons`, `decided_by`, `decided_at`, `decision_reason` |
| Provisioning | `attempts`, `claim_token`, `claimed_at`, `next_attempt_at`, `failure_code`, `failure_summary`, `failed_at` |
| Result | `provisioned_tenant_id` (unique), `provisioned_user_id`, `subscription_id`, `provisioned_at` |
| Invitation and activation | `invitation_sent_at`, `invitation_failures`, `invitation_attempted_at`, `activated_at` |
| Window and cancellation | `expires_at`, `cancelled_at`, `cancelled_by`, `cancel_reason` |
| Uniqueness | `open_email_hash`: generated, unique, set only while open |

`DataInventory` classifies the table as platform (Markedge's record). The tenant link is `provisioned_tenant_id`
(invariant 22).

## 6. Configuration

| What | Where | Default |
|---|---|---|
| Public registration | `PEOPLEOS_PUBLIC_SIGNUP_ENABLED` | **false** |
| Offers: plan, trial days, countries | Commercial policy `signup.offers` (`core:30:IN\|AE, growth:0:IN`), maker-checker | none, so nothing is offered |
| Review policy | `signup.review`: `all` or `flagged` | `all` (shipped default) |
| Terms of Service and Privacy Notice | `signup.terms`, `signup.privacy_notice` (`VERSION https-address`) | none, so signup cannot start |
| Link lifetime | `PEOPLEOS_SIGNUP_LINK_HOURS` | 24 |
| Completion window after confirming | `PEOPLEOS_SIGNUP_COMPLETION_DAYS` | 14 (the application expires and is kept) |
| Rate limits | `PEOPLEOS_SIGNUP_START_PER_IP` (10/h), `_START_PER_ADDRESS` (3/h), `_CONFIRM_PER_IP` (10/min), `_ACTIVATION_RESEND_SECONDS` (300) | as shown |
| Provisioning | `PEOPLEOS_SIGNUP_PROVISIONING_ATTEMPTS` (5), backoff 1/5/15/60 min, `PEOPLEOS_SIGNUP_LEASE_MINUTES` (15), `PEOPLEOS_SIGNUP_PROVISIONING_WAIT_SECONDS` (30, the wait for the one-at-a-time lock) | as shown |

A plan is offered in a country only when all of these hold:
- it is in the approved offers for that country;
- a published version is on sale;
- that version defines its entitlements;
- exactly one active billing market lists the country;
- a price version is on sale in that market.

Each failure has a code: `NOT_OFFERED`, `PLAN_VERSION_NOT_ON_SALE`, `ENTITLEMENTS_NOT_CONFIGURED`,
`MARKET_NOT_CONFIGURED`, `MARKET_AMBIGUOUS` or `PRICE_NOT_CONFIGURED`.

## 7. Provisioning guarantees

- **One provisioning at a time.** A run takes an atomic lock in the application cache (shared by every server) before
  it claims. It waits up to `PEOPLEOS_SIGNUP_PROVISIONING_WAIT_SECONDS` (30), and otherwise claims nothing (the tick
  retries a minute later). Concurrent provisionings of new tenants deadlocked in the audit chain's head index: gap
  locks between new chains, found by the MySQL performance probe and proven by race 7 (InnoDB's deadlock counter stays
  at zero; without the lock it rose by 4). The core transaction also retries a deadlock in place, under the same claim.
- **One tenant per application.**
  - The claim is a conditional update.
  - The core re-reads the row `FOR UPDATE` and checks the fencing token and that no tenant is linked.
  - `provisioned_tenant_id` is unique; the tenant slug and the owner's e-mail are unique.
  - Verified on MySQL by races 3, 5 and 6.
- **Atomic.** Tenant, roles, settings, defaults, the owner, the trial and the application's links commit together, or
  none of them do. The invitation e-mail goes out after the commit, never inside a transaction that could still roll
  back.
- **Recoverable.**
  - Retryable failures back off: 1, 5, 15, then 60 minutes.
  - After `max_attempts`, or on a stated refusal, the application waits for a person.
  - A run that stopped before committing is recovered after the lease.
  - A refused invitation is retried, at most 5 times, at 5-minute intervals.
- **No half-active customer.** The owner is `invited` with an unusable password until they choose one. A tenant whose
  invitation failed exists, but nobody can sign in to it, and it is visible to operators.
- **Safe failure records.** A code and a fixed summary only. The exception is logged by class and line, never by its
  message (which can carry SQL bindings or an address).

## 8. Security controls (summary; details in the threat model)

| Control | How |
|---|---|
| Enumeration | One answer and one timing for every address. Only the mailbox learns that an account exists (D-17 kept) |
| Abuse | Per-IP and per-address limits (the per-address one is silent), a honeypot, and nothing provisioned before the address is confirmed |
| Links | SHA-256 stored; single use; expiry; consuming one is a POST; a new link replaces the old |
| Session | The application is bound to the session after a confirmed link, with a regenerated session id. The reference is never taken from the browser |
| Tenant identifiers | Never accepted from the request; the slug is derived, unique and never reserved |
| First administrator | `account-owner`: explicit keys, no `*`, no payroll, statutory or sensitive data, no role editing; never a platform operator |
| Delegation | `RoleGrants` (E1–E3): no one below Tenant Super Admin grants it, writes permissions beyond their own, or makes it an SSO default |
| Operators | Every action is refused in its service unless a platform operator gives a reason, and is audited. No tokens, passwords or IPs on the page; no impersonation |
| Commercial | A trial of the accepted offer only, via `startSignupTrial` (one caller, architecture test). Never a paid subscription. No payment provider is called |

## 9. Privacy and data lifecycle

| Application ends as | Contact name and address | Kept |
|---|---|---|
| `active` | Removed at activation (the tenant's user account holds them, under SaaS.9 deletion controls) | Organisation details, accepted versions, time and IP of acceptance, decisions |
| `rejected`, `expired`, `cancelled` | Kept | Everything |
| `failed_manual_review` | Kept until an operator decides | Everything |

Applications that never became a tenant are never purged automatically: no retention period is invented (D10-6). The
operators' page shows them by state. The platform audit chain carries references, states and codes only.

## 10. Notifications

All are sent directly (never queued, so links never sit in the jobs table):

| E-mail | When |
|---|---|
| Link (confirm or continue) | Start, or a new start for an open application |
| You already have an account | Start with an address that signs in to PeopleOS |
| In review / being set up | Submission |
| Not approved | Rejection. Neutral; the operator's reason is not copied |
| Set-up delayed | Manual review after a failure |
| Activation | The existing invitation e-mail, after provisioning; resent by the tick, the applicant (throttled) or an operator |

Each attempt is audited as `SIGNUP_EMAIL_SENT` (accepted by the mail transport, not proof of delivery) or
`SIGNUP_EMAIL_FAILED`. The local `log` mailer delivers nothing.

## 11. Migration and rollback

- **Up:** `2026_11_01_100001_saas10_signup_applications` creates one table. Run `php artisan peopleos:sync-permissions`
  so existing tenants receive the new `account-owner` system role (unassigned).
- **Down:** drops the table. Tenants already provisioned through signup stay ordinary tenants, without their
  application record.
- **Code rollback:** with the switch off nothing is accepted. The scheduler line `peopleos:signups:tick` is removed with
  the code.

## 12. Known limitations

- **Entitlements.** The entitlement engine is in shadow mode (D-16): plan limits are recorded and shown, not enforced.
- **Bot protection.** No CAPTCHA provider is integrated (D10-9).
- **Domain verification.** None: a matching domain only flags the application for review.
- **Abandoned tenants.** A provisioned tenant whose owner never activates is not closed automatically (D10-10).
- **Abandoned applications.** These are kept until a retention decision exists (D10-6).
