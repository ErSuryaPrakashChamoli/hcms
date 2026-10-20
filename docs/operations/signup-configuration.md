# Self-service signup: configuration

Signup reads two kinds of setting:
- **Environment** (operational and security parameters): only the infrastructure owner changes these.
- **Commercial policy** (what is offered and accepted): approved, effective-dated versions on **Platform → Commercial
  policies**. One operator proposes and another approves. A version never changes afterwards.

Nothing has a commercial default except the review policy (`all`). With no approved offer, terms or privacy notice,
signup refuses with a stated reason instead of assuming one.

## 1. Environment

| Variable | Default | Meaning |
|---|---|---|
| `PEOPLEOS_PUBLIC_SIGNUP_ENABLED` | `false` | **D10-1.** Off: each signup page shows a notice and every signup action is refused. Turn on only with the owner's written authorisation after a security review, and only in the environment that authorisation names |
| `PEOPLEOS_SIGNUP_LINK_HOURS` | 24 | Lifetime of a confirmation or continue link (single use) |
| `PEOPLEOS_SIGNUP_COMPLETION_DAYS` | 14 | A confirmed application not completed in this time expires (kept, not deleted) |
| `PEOPLEOS_SIGNUP_START_PER_IP` | 10 per hour | Visible limit per network |
| `PEOPLEOS_SIGNUP_START_PER_ADDRESS` | 3 per hour | Silent limit per e-mail address |
| `PEOPLEOS_SIGNUP_CONFIRM_PER_IP` | 10 per minute | Link confirmations per network |
| `PEOPLEOS_SIGNUP_ACTIVATION_RESEND_SECONDS` | 300 | Minimum time between the applicant's activation e-mail requests |
| `PEOPLEOS_SIGNUP_PROVISIONING_ATTEMPTS` | 5 | Automatic set-up attempts before an operator decides |
| `PEOPLEOS_SIGNUP_LEASE_MINUTES` | 15 | A set-up not committed after this long is treated as interrupted |
| `PEOPLEOS_SIGNUP_PROVISIONING_WAIT_SECONDS` | 30 | Set-ups run one at a time (a lock in the application cache): how long one waits for the one before it before leaving the application to the next tick. The cache store must be shared by every server (Redis or database) |
| `PEOPLEOS_INVITATION_HOURS` | 72 | Lifetime of the activation (invitation) link, shared with all invitations |

The scheduler must run `peopleos:signups:tick` every minute on one server (`docs/operations/queue-and-scheduler.md`).
Mail must be a real transport in production. The `log` mailer delivers nothing, and the audit records only what the
transport accepted.

## 2. Commercial policies

| Key | Format | Example | Decision |
|---|---|---|---|
| `signup.offers` | `PLAN:TRIAL_DAYS:COUNTRY\|COUNTRY`, comma-separated | `core:30:IN, growth:0:IN\|AE` | D10-2 (trial length: D-4) |
| `signup.review` | `all` or `flagged` | `all` | D10-3 (D-9) |
| `signup.terms` | `VERSION https-address` | `2026.1 https://markedge.example/terms` | D10-4 (legal) |
| `signup.privacy_notice` | `VERSION https-address` | `2026.1 https://markedge.example/privacy` | D10-4 (legal) |

- **0 trial days** means the plan is offered for review only: the application always goes to an operator, and approval
  provisions the tenant without a subscription.
- **A plan is available in a country** only when all of these hold:
  - it is offered there;
  - a published version is on sale and defines its entitlements;
  - exactly one active billing market lists the country;
  - a price version is on sale in that market.
- **A new terms version** takes effect for applications submitted from its effective date. An applicant who started
  under the old version is asked to accept the new one (`TERMS_CHANGED`).

## 3. Before switching registration on (checklist)

- [ ] The owner's written authorisation (D10-1) and an independent security review of `docs/saas/SaaS-10-Threat-Model.md`
- [ ] Legal-approved Terms of Service and Privacy Notice published, and their versions approved here (D10-4)
- [ ] Offers approved (D10-2), with prices published for each offered country's market
- [ ] Review policy confirmed (D10-3); `all` is recommended until the money path is proven (D-9)
- [ ] Production mail transport, SPF/DKIM, and a monitored bounce address
- [ ] Scheduler running `peopleos:signups:tick`; queue and cache shared across web servers (rate limits)
- [ ] Decisions D10-6 (retention of unconverted applications), D10-8 (owner MFA), D10-9 (CAPTCHA) recorded
- [ ] The inherited release blockers resolved (SaaS.9 closure report §18)
