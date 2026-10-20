# Runbook: self-service signup operations (platform operators)

Page: **Platform → Signups** (`/admin/platform-signups`). Platform operators only; MFA as for every operator page.
Everything you do here needs a reason, is refused in the service for anyone else, and is audited with your name.

## 1. Before anything: can signup work today?

The first section reads only configuration; nothing on this page changes it.

| Row | Meaning | Where to fix |
|---|---|---|
| Public registration switch | `off` until the owner authorises it (D10-1) | Environment: `PEOPLEOS_PUBLIC_SIGNUP_ENABLED` (see `signup-configuration.md`) |
| Offers (`signup.offers`) | Plans, trial days and countries offered | Platform → Commercial policies → Propose change; a second operator approves |
| `<plan> in <country>` | `available`, or the code that stops it | See the codes in §5 |
| Terms of Service, Privacy Notice | Version and address the applicant accepts | Commercial policies (`signup.terms`, `signup.privacy_notice`) |
| Review policy | `all` (default) or `flagged` | Commercial policies (`signup.review`) |

## 2. Reviewing an application (`In review`)

1. Open it from **Applications**, filtering by state if needed.
2. Read **Review reasons**:

   | Code | Meaning |
   |---|---|
   | `REVIEW_POLICY` | Every application is reviewed |
   | `NO_SELF_SERVICE_TRIAL` | The offer has 0 trial days; you agree the commercial terms |
   | `DOMAIN_IN_USE` | Another organisation already has accounts at this e-mail domain (possible duplicate) |
   | `PREVIOUSLY_REJECTED` | An earlier application from this address was rejected |

3. Check **Eligibility now**. It must say *Eligible now*. Otherwise its code tells you what changed since the applicant
   applied (§5).
4. Decide:
   - **Approve and set up.**
     - The organisation is provisioned now, as you.
     - The Account Owner gets the activation e-mail.
     - For an offer without a trial, the tenant has no subscription: start one on Platform → Subscriptions once agreed.
   - **Reject.**
     - The applicant gets a neutral e-mail.
     - Your reason stays in the audit trail and is never sent.
5. Contact details are shown only while the application needs them. Do not copy them elsewhere.

## 3. Failed set-ups

| State | What happens | What you do |
|---|---|---|
| `Set-up failed (retrying)` | Retried automatically (1, 5, 15, 60 minutes) | Usually nothing. **Stop automatic retries** if you want to look first |
| `Set-up needs attention` | A stated refusal, or attempts exhausted. The applicant was told it is delayed | Read the failure code; fix the cause; **Retry the set-up now**, **Reject** or **Cancel** |

Nothing from a failed attempt is kept (one transaction), so a retry starts clean. See `signup-recovery.md`.

## 4. Waiting for activation

- **Activation e-mail** shows whether the mail transport accepted the owner's invitation. This is not proof of
  delivery, and the local `log` mailer delivers nothing.
- **Send a new activation e-mail** issues a new one-time link; the previous one stops working.
  - It is throttled (one per minute per tenant).
  - The applicant can also ask for one from their registration page (once every 5 minutes).
- When the owner chooses a password, the tick marks the application **Active** and removes the contact's name and
  address from it.

## 5. Codes

| Code | Where | Meaning | Action |
|---|---|---|---|
| `NOT_OFFERED` | eligibility, failure | The plan is not offered in the country (`signup.offers`) | Restore the offer, or reject |
| `PLAN_VERSION_NOT_ON_SALE` | eligibility, failure | No published version on sale | Publish a version; the application pinned the old one, so reject or cancel and ask the applicant to apply again |
| `PLAN_VERSION_CHANGED` | eligibility, failure | A newer version is on sale than the one accepted | As above: the applicant must accept the current offer |
| `ENTITLEMENTS_NOT_CONFIGURED` | eligibility | The plan version defines no entitlements | Fix the plan (Platform → Plans) |
| `MARKET_NOT_CONFIGURED`, `MARKET_AMBIGUOUS`, `MARKET_CHANGED` | eligibility, failure | No market, more than one market, or a different market for the country | Billing catalogue |
| `PRICE_NOT_CONFIGURED` | eligibility, failure | No price on sale for the plan in that market | Billing catalogue (maker-checker) |
| `OVER_PLAN_LIMIT` | eligibility | Intended employees exceed the plan's limit | Reject or cancel; suggest a larger plan |
| `EMAIL_IN_USE` | eligibility, failure | The address now signs in to PeopleOS (one login per address, D-17) | Reject or cancel; ask for another address |
| `PROVISIONING_ERROR` | failure | Unexpected error; the class and line are in the application log | Retried automatically; see `signup-recovery.md` |
| `INTERRUPTED` | failure | The set-up stopped before committing (lease passed) | Retried automatically |

## 6. Cancelling

Possible until set-up starts, and for failed set-ups. The applicant can start a new application later. A cancelled,
rejected or expired application never comes back.

## 7. Audit

The page lists the application's events on the platform chain:
- references, states and codes;
- operators by name;
- applicant steps without an IP address.

Provisioning and activation events are also on the new tenant's chain. **Platform → Audit trail** shows everything.
