# SaaS.10 — Threat model and security review: self-service signup and provisioning

Date: 9 October 2026. Scope:
- the guest signup pages and their services;
- provisioning;
- the Account Owner role and the delegation ceiling;
- the operators' Signups page;
- the first-run page.

Public registration is **off** (D10-1). This review is a precondition for switching it on, not a substitute for the
independent security review the owner requires.

## 1. Assets and trust boundaries

| Asset | Why it matters |
|---|---|
| Other tenants' data and existence | One tenant is one isolated security boundary |
| Platform operator power | Must never be reachable from signup |
| A new tenant's administrator rights | Least privilege; no silent super-administrator |
| Applicants' personal data (name, address, IP at acceptance) | DPDP Act and equivalent obligations; minimisation |
| Commercial entitlements | A form must never grant a paid entitlement |
| Links and invitation tokens | Bearer capabilities |
| The audit chains | Evidence |

Boundaries:
1. The internet → the guest pages (anonymous; CSRF and session through the panel's web middleware).
2. Guest pages → services (all validation and decisions on the server).
3. Console tick → provisioning (no operator; console only).
4. Operator → services (platform operator plus a reason, audited).
5. A new tenant's users → their tenant (fail-closed tenancy).

## 2. Threats and controls

| # | Threat | Control | Test |
|---|---|---|---|
| T1 | **Enumeration of accounts or applications** through answers, errors or timing | `start()` returns the same for a new address, an open application and an existing account. The page shows one message inside a 400 ms Timebox; the per-address limit is silent. Only the mailbox learns that an account exists | `SignupApplicationsTest` (account exists, one open application), `SignupPagesTest` (same page answer) |
| T2 | **Automated signup abuse** | Per-IP limit (visible, address-independent), per-address limit (silent), page-level Livewire limits, honeypot. Nothing is provisioned before the address is confirmed, and every application is reviewed by default (`signup.review` = all). No CAPTCHA (D10-9) | limits and honeypot tests |
| T3 | **Rate-limit bypass** by rotating addresses or IPs | Both dimensions are limited independently. The limiter uses the application cache (database or Redis in production, shared across workers). Residual: a distributed botnet can still create many unconfirmed applications, which expire and are never provisioned | limits test |
| T4 | **Duplicate submission** (double click, two tabs, races) | Unique `open_email_hash`; a consumed link (row lock); conditional transitions; a conditional provisioning claim | MySQL races 1–4 |
| T5 | **Forged or guessed link** | 64 random characters (about 380 bits); only the SHA-256 is stored; the length is checked; one message for every failure | link tests |
| T6 | **Replay of an expired or used link** | An expiry, and the hash is cleared on use under a row lock; a new link replaces the old | link tests, race 2 |
| T7 | **Mail scanners consuming links** | Opening a link changes nothing; the person presses Continue (POST) | `SignupPagesTest` |
| T8 | **CSRF** | Every state change is a Livewire call under the panel's `PreventRequestForgery`. No GET changes state | by construction (panel middleware) |
| T9 | **Session fixation** | The session id is regenerated when a link binds an application to the session | `SignupPagesTest` |
| T10 | **Open redirect** | No page accepts a redirect target. Redirects go to named routes only. Terms links are approved https addresses, shown as links, never redirects | by construction |
| T11 | **Malicious input** (XSS, oversized, SQL) | Validated lengths and formats; Blade escapes all output (`e()` around the configured terms links); query bindings only; the time zone is chosen from the country's IANA list | validation tests |
| T12 | **Unauthorized tenant discovery or targeting** | No tenant id or slug is accepted from the request; the slug is derived, unique, never reserved. A domain match only flags the application, and is never shown to the applicant | "never lets the applicant choose a tenant", slug test, race 5 |
| T13 | **Initial administrator escalation to platform operator** | Signup never names `is_platform_admin` (architecture test). The owner is a tenant user; operator power comes only from the flag with no tenant | provisioning boundary test, architecture test |
| T14 | **Owner escalation inside the tenant** (to Tenant Super Admin) | `RoleGrants` on the user form, role editor, blueprint import and SSO defaults (E1–E3). Role changes on the user form are audited | `RoleGrantsTest` |
| T15 | **Owner reaching another tenant** | The fail-closed tenant scope; the panel binds the owner's tenant; `TenantPolicy` denies tenant users | provisioning boundary test |
| T16 | **Duplicate provisioning** (retries, workers, operator plus tick) | Conditional claim plus fencing token under the row lock; unique tenant link, slug and owner e-mail; one provisioning at a time (a cache lock), so concurrent new tenants never deadlock | races 3, 5, 6, 7; idempotency and lock tests |
| T17 | **Partial provisioning** leaving an apparently active but unusable account | One transaction; the invitation after commit; the owner cannot sign in until activated; the failure is recorded and retried | rollback, lease and invitation tests |
| T18 | **Interrupted run resurrected wrongly** | The lease recovery is conditional on status and claim token, and waits on the row lock of a run still committing | race 6 |
| T19 | **Commercial bypass** (paid plan, a trial the offer does not give, an expired plan) | The offer, version, market, price and limit come from approved configuration and are re-checked at approval and provisioning. `startSignupTrial` is the only path (one caller); no paid subscription is ever started | eligibility and re-check tests |
| T20 | **Unauthorized operator action** | Every `SignupReview` method refuses a non-operator and a null actor. The page is platform-only (403) | operator tests |
| T21 | **Secrets in logs** | Links are never logged; failures are logged by class and line; signup e-mails are never queued | log test |
| T22 | **Personal data on the platform audit chain** | The metadata carries the reference, country, plan and states; applicant steps are recorded anonymously | audit metadata test |
| T23 | **Rejected or cancelled applications resurrected** | Terminal states have no outgoing transitions. A new attempt is a new application, flagged after a rejection | state machine test |
| T24 | **Activation link abuse** | The existing invitation: hashed, single use, expiring, tenant-bound; it refuses a suspended tenant; resends are throttled | suspension and throttle tests |
| T25 | **Terms acceptance disputes** | The versions accepted, the time and the IP are stored with the application. A version change during signup is refused (`TERMS_CHANGED`) | terms test |

## 3. Existing defects found and fixed in this phase

| ID | Defect | Severity | Fix |
|---|---|---|---|
| E1 | Any holder of `user.assign_roles` (for example Tenant HR Admin) could give anyone, themselves included, the Tenant Super Admin role | P1 | `RoleGrants` validation on the user form (new grants only) |
| E2 | Blueprint import could rewrite the permissions of any role, system roles included, for anyone holding `blueprint.import` | P1 | Ceiling: an importer writes only permissions they hold (unless they are a Super Admin, an operator or the console) |
| E3 | An SSO connection's default role could be Tenant Super Admin, so every SSO-created user became a super administrator | P2 | Refused in the form, and never attached at sign-in |

## 4. Residual risks and open decisions

| Risk | Severity | Owner decision |
|---|---|---|
| No CAPTCHA: a distributed bot can create unconfirmed applications (no tenant results) | P3 | D10-9 |
| Owner MFA follows the tenant policy (off by default), so a phished owner password is enough to sign in | P2 | D10-8 (recommended: require MFA for account owners) |
| The applicant's IP at acceptance is personal data, kept indefinitely | P3 | D10-6 |
| A provisioned tenant whose owner never activates stays provisioned | P3 | D10-10 |
| The owner can give themselves fixed specialist roles (for example Payroll Admin): explicit and audited, by design | Accepted | D10-7 |
| The entitlement engine is in shadow mode: plan limits are not enforced | Inherited | D-16 |
| Public repository and credential history (SaaS.9 closure F1) | P1, inherited | Owner |
