# PEOPLEOS — SaaS.10 FINAL REPORT: self-service signup and tenant provisioning

Date: 9 October 2026. Branch `feature/oct_1_phase_1`. Not pushed, merged or deployed. SaaS.11 was not started.

> **SaaS.10 STATUS: COMPLETE** for its scope: every acceptance criterion passes (§19).
>
> **Public registration: DISABLED.** `PEOPLEOS_PUBLIC_SIGNUP_ENABLED` defaults to false and is unset in every
> environment here. It was switched on only for the disposable browser-fixture server process.
>
> **Production readiness: NOT ESTABLISHED.** A technically complete SaaS.10 does not establish production readiness.
> The inherited blockers remain (§17). Opening registration needs the owner decisions in §18, starting with D10-1.

## 1. Starting and ending commit

| | Commit |
|---|---|
| Start | `8e2b8c5` (SaaS.9 closure report ending commit), clean tree, 47 commits ahead of origin |
| End | `baf1fb0` (docs), followed only by the commit that records this hash in this table |

Commits of this phase, in order:
- `8e2d518` docs: discovery and architecture: signup state machine, provisioning, boundaries, owner decisions
- `8d8727a` feat: identity: an explicit Account Owner role and a delegation ceiling (E1–E3)
- `a679108` feat: self-service signup and idempotent tenant provisioning (switched off by default)
- `797f9f9` test: signup races on MySQL
- `c22d284` test: tests strengthened by surviving mutants
- `767d3aa` fix: first run: list Getting started only in signup tenants; two-column state layouts
- `94e8dab` test: race 6 pauses on the run's first read, not on its lock, and proves the pause happened
- `208efde` fix: signup provisioning runs one at a time (concurrent new tenants deadlocked in the audit chain)
- `9b91f13` test: signup races verify the audit chain only when they ran (skipped under SQLite)
- `fd012f6` test: visual baseline: the audit page's count includes the new Account Owner role's creation
- `baf1fb0` docs: architecture, threat model, runbooks, configuration, decisions and final report
- a final docs commit recording the ending commit above

## 2. Branch and working tree

- **Branch:** `feature/oct_1_phase_1`.
- **Working tree:** clean at start and end. No uncommitted work of anyone else was overwritten.
- **Repository:** remains public (an inherited owner decision). No history rewrite or force-push.
- **Databases changed (additive migration only, backed up first):**
  - the 8090 showcase `hcm_ux_showcase`;
  - development `hcm`.

  Both also received `peopleos:sync-permissions` (the unassigned `account-owner` system role).
- **Disposable databases:**
  - `hcm_saas10_ui_showcase` (browser fixture);
  - `hcm_saas10_mut_concurrency` and `hcm_saas10_perf_concurrency`;
  - `hcm_saas6_concurrency` (races).
- **Servers:**
  - 8090 was found down and restarted detached (it serves the showcase, registration off);
  - the fixture server 8096 and the local SMTP sink were stopped after use;
  - the visual servers 8092, 8093 and 8094 were stopped after use. 8093 and 8094 served the starting commit and the
    current code for the baseline comparison.

## 3. Files and migrations changed

The phase changed 59 files, plus the documentation commit.

**Migration:** `2026_11_01_100001_saas10_signup_applications` (one new platform table; nothing else changes).

**New code:**
- **Signup domain:** `app/Domain/Signup/`:
  - Enums: `SignupStatus`;
  - Models: `SignupApplication`;
  - Services: `SignupCatalogue`, `SignupEligibility`, `SignupApplications`, `SignupProvisioning`, `SignupReview`,
    `SignupMaintenance`, `TenantSetupProgress`;
  - Support: `SignupTransitions`, `SignupAudit`, `SignupMailer`, `SignupRefused`, `StaleClaim`;
  - Notifications: `SignupLink`, `SignupAccountExists`, `SignupUpdate`.
- **Pages:**
  - `app/Filament/Signup/` (`SignupStart`, `SignupConfirm`, `SignupApplicationPage`);
  - `app/Filament/Pages/PlatformSignupsPage.php`;
  - `app/Filament/Pages/GettingStarted.php`;
  - their views.
- **Console:** `app/Console/Commands/SignupTick.php` (`peopleos:signups:tick`).
- **Identity:** `app/Domain/Identity/Services/RoleGrants.php`.

**Changed code:**

| File | Change |
|---|---|
| `ProvisionTenantAction` | `provisionCore` (transaction without the e-mail; first administrator's role) |
| `TenantFirstAdministrator` | Both first-administrator roles; a reason for the invitation |
| `PlatformOperationsOverview` | Both first-administrator roles |
| `CommercialSubscriptions::startSignupTrial` | New entry point |
| `ConfigurationKey` | Four signup policy keys |
| `AuditAction` | `SIGNUP_*` |
| `DataInventory` | `signup_applications` classified as platform |
| `PermissionRegistry::keysMatching` | New |
| `UserForm`, `RoleForm`, `SsoConnectionResource`, `Sso`, `Blueprints` | `RoleGrants` |
| `EditUser`, `CreateUser` | Role changes audited |
| `PlatformCommercialPoliciesPage` | Value formats for the signup keys |
| `AdminPanelProvider` | Three guest routes |
| `routes/console.php` | The tick |
| `home.blade.php` | Banner for signup tenants only |
| `config/peopleos.php` | `account-owner` role, `signup` section, first-administrator roles, `signup.review` default |
| `docs/operations/queue-and-scheduler.md` | The tick |

## 4. Services: new and reused

| Need | Reused | New |
|---|---|---|
| Tenant creation and seeding | `ProvisionTenantAction` (core split out; operator path unchanged) | — |
| Owner activation | `UserInvitations`, `TenantFirstAdministrator`, `AcceptInvitation` page | — |
| Trial | `CommercialSubscriptions` (one new entry point, trigger `signup`) | — |
| Offers, prices, markets, plans | `CommercialConfiguration` + `ConfigurationKey`, `BillingCatalog`, `BillingMarket`, `PlanVersion`, `PlanEntitlement` | `SignupCatalogue` (read model) |
| Audit | `AuditRecorder` (platform and tenant chains) | `SignupAudit` |
| Operator guard | `PlatformOperator` | — |
| Links offered | `ScreenAccess` | — |
| Password and MFA policy | `PasswordPolicy`, `MultiFactor`, `EnforceAccountSecurity` (unchanged) | — |
| Applications, eligibility, provisioning, review, tick | — | Signup services |
| Delegation ceiling | — | `RoleGrants` |

## 5. State transitions implemented

| State | Reached by | Leaves to |
|---|---|---|
| `email_pending` | Applicant starts | `details_pending`, `expired`, `cancelled` |
| `details_pending` | Link confirmed (POST) | `review_pending`, `approved`, `expired`, `cancelled` |
| `review_pending` | Submitted with a review reason | `approved`, `rejected`, `cancelled` |
| `approved` | Operator, or no review required | `provisioning`, `cancelled` |
| `provisioning` | Fenced claim | `activation_pending`, `failed_retryable`, `failed_manual_review` |
| `failed_retryable` | Retryable failure | `provisioning`, `failed_manual_review`, `cancelled` |
| `failed_manual_review` | Refusal or attempts exhausted | `provisioning`, `rejected`, `cancelled` |
| `activation_pending` | Committed provisioning | `active` |
| `active`, `rejected`, `expired`, `cancelled` | — | terminal |

Not introduced, as justified in the discovery: DRAFT, and separate PROVISIONED and ACTIVATION_PENDING.

Business verification is mapped onto existing states, with no invented legal requirement:

| Verification state | Represented by |
|---|---|
| NOT_REQUIRED | `review_required` false |
| PENDING / IN_REVIEW | `review_pending` |
| VERIFIED | `approved`, with the operator and reason |
| REJECTED | `rejected` |
| EXPIRED | `expired` |

A rejected applicant who applies again creates a new application, flagged `PREVIOUSLY_REJECTED`. No documents are
collected; the evidence is the operator's reason and the audit trail, visible to platform operators only.

## 6. Security controls

The threat model (`docs/saas/SaaS-10-Threat-Model.md`) covers 25 threats with their controls and tests. In summary:

- **No enumeration.** One answer and one timing for every address; an existing account is told only in its mailbox.
- **Abuse limits.** Per-IP and per-address limits (the per-address one is silent), and a honeypot.
- **Links.** SHA-256 stored, single use, expiring, consumed only by a POST; the session id is regenerated.
- **No open redirects.** No redirect input exists.
- **Tenant identifiers.** None is accepted from the request; slugs are derived.
- **First administrator.** Account Owner: explicit permissions, never `*`, never a platform operator.
- **Delegation ceiling.** `RoleGrants` fixes three existing escalation paths:
  - **E1:** Tenant Super Admin could be given by anyone holding `user.assign_roles`;
  - **E2:** blueprint import could rewrite any role;
  - **E3:** an SSO default role could be Super Admin.

  Role changes on the user form are now audited.
- **Operators.** Every action is service-guarded, reasoned and audited; no secrets on the page; no impersonation.
- **Commercial.** A trial of the accepted offer only (one entry point); never a paid subscription; no payment provider
  is called.
- **Logs and audit.** No link, token or password in the logs; failure summaries are safe; the platform chain carries
  references, states and codes only.

## 7. Tenant isolation results

| Check | Result | Test |
|---|---|---|
| Signup cannot select another tenant's identifier (`tenant_id`, `slug`, `provisioned_tenant_id` in the request are ignored) | Pass | `SignupApplicationsTest` |
| Same organisation name: a new tenant with a suffixed address, never the existing tenant | Pass | slug test, MySQL race 5 |
| Unauthenticated users cannot see application state without the confirmed session | Pass | `SignupPagesTest`, browser |
| The owner reaches only their own tenant; `TenantPolicy` denies another; platform pages are 403 | Pass | provisioning test, browser |
| No platform-operator flag anywhere in signup code | Pass | architecture test |
| Every audit chain verifies after provisioning (platform and new tenants) | Pass | provisioning tests, MySQL races (`afterEach` verify) |

## 8. Provisioning and recovery behaviour

| Behaviour | How |
|---|---|
| One at a time | A cache lock shared by every server; a run that waits 30 s leaves the application for the tick |
| One tenant per application | Fenced claim, token re-checked under the row lock, unique tenant link, slug and owner e-mail |
| Atomic | Tenant, roles, settings, defaults, owner, trial and links in one transaction; the invitation after commit |
| Retryable failure | Backoff 1/5/15/60 minutes; the core retries a deadlock in place |
| Attempts exhausted or stated refusal | `failed_manual_review`; the applicant gets a neutral "delayed" e-mail |
| Interrupted run | Lease (15 min) → `failed_retryable` (`INTERRUPTED`) → retried; nothing of it was kept |
| Refused invitation | Tenant kept, owner cannot sign in; the tick resends (5 times, 5 minutes apart); operator and applicant can resend |
| Activation | The tick marks `active` and removes the contact's name and address from the application |

## 9. Test results (SQLite and MySQL separately)

| Suite | Result |
|---|---|
| **SQLite** (`php artisan test --parallel --processes=2`, final code `fd012f6`) | **1,447 passed, 0 failed**; 1,582 tests, 19,862 assertions, 20 min. The **135 skipped are exactly the MySQL-gated tests** (not counted as passed here: the next row) |
| **MySQL** (`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_saas6_concurrency php artisan test tests/MySql`, at `9b91f13`; later commits change a visual baseline and documents only) | **135/135 passed**, 821 assertions, 35.5 min |
| Migration | Applied to MySQL development, showcase and every disposable database; the down migration drops the one table |

**New tests this phase:**
- SQLite: 56 tests in all.
  - `SignupApplicationsTest`: 18;
  - `SignupProvisioningTest`: 16;
  - `SignupPagesTest`: 7;
  - `GettingStartedTest`: 3;
  - `RoleGrantsTest`: 11;
  - one architecture test.
- MySQL: `SignupConcurrencyTest`, 7 races.

**Existing tests adjusted:** `BlueprintsTest` (one). Its import ran as a user of another tenant, which the new ceiling
correctly refuses. The importer is now an administrator of the destination tenant.

**Failures found by testing, and their fixes:**
1. **Owner without permissions.** The signup test fixture had not synced the permission catalogue, so the owner's role
   had no permissions. Found by the first-run test; the fixture was fixed. Production syncs the catalogue on deploy.
2. **Skipped races counted as errors.** Under SQLite the seven MySQL races reported a teardown error after being
   skipped, so Pest counted them twice. The guard was fixed (`9b91f13`); the corrected run is the one above.
3. **Concurrent deadlocks.** Two new tenants provisioned at once deadlocked on MySQL. Found by the performance probe;
   fixed in `208efde` (§13 S1).

## 10. Concurrency and mutation results

**MySQL races** (`tests/MySql/SignupConcurrencyTest.php`, real processes, MySQL 8.4):

| # | Race | Guarantee |
|---|---|---|
| 1 | Three starts of one address at once | One open application, one `SIGNUP_STARTED` |
| 2 | One link confirmed twice at once | Opens once; the other gets the one invalid-link message |
| 3 | Two ticks and a direct run on one application | One claim: one tenant, owner, trial and `SIGNUP_PROVISIONED`; attempts = 1 |
| 4 | Approve, reject and cancel at once | One decision, one decision event; a tenant only if approval won |
| 5 | Two organisations with the same name at once | Addresses `same-name-co` and `same-name-co-2`; the loser rolled back and retried |
| 6 | Lease expiry while a run is still committing | The run completes; the recovery waits on the row lock and changes nothing (the pause is asserted) |
| 7 | Four provisionings at once | All provisioned, attempts = 1, InnoDB's deadlock counter unchanged, every chain valid |

**Mutation testing** (private copies of the tree; one mutant at a time against the tests that should catch it):

| Set | Result |
|---|---|
| SQLite S01–S32: switch, link consumption and expiry, enumeration path, silent limit, honeypot, review policy, domain flag, plan limit, terms version, provisioning re-check, owner role, slug uniqueness, trial, safe failure summary, activation minimisation, operator guard, null actor, the four `RoleGrants` paths and the user-form ceiling, Getting started access and banner, Signups page access, entitlements check, state machine, a cancellable provisioned application, fencing token, session regeneration | **32/32 killed**. 30 on the first pass. S22 (SSO attaches Super Admin) and S30 (no early token check) survived because two tests could not fail; both tests were strengthened (`c22d284`) and both mutants are now killed |
| MySQL KS1–KS6 | **6/6 killed** |
| KS1 | Transitions not conditional: race 4 (three decisions) |
| KS2 | Link confirmed without the row lock: race 2 |
| KS3 | No unique index on the open address: race 1 |
| KS4 | Provisioning core without the row lock: survived first, because race 6 paused on the very lock it tested. The race was rewritten to pause on the run's first read and to assert the pause (`94e8dab`); killed |
| KS5 | KS1's mutation against race 3 |
| KS6 | No provisioning lock: race 7 (4 deadlocks) |

**Total: 38/38 mutants killed. None survived.**

## 11. Browser, accessibility and visual results

| Suite | Result |
|---|---|
| SaaS.10 end-to-end (Chromium) | **34/34 checks**; evidence in `docs/saas/evidence/SaaS-10-browser-validation.json` (no addresses or secrets) |
| Accessibility (axe, WCAG 2.0/2.1 A and AA) | **12 states, 0 violations** |
| Console errors | **0** |
| Visual regression (UX.18 suite) | **120/120** after one reviewed baseline update (below) |
| Existing browser suite | **74/74** (6 skipped by design, as before) |

**The end-to-end run.**
- **Environment.** A disposable `hcm_saas10_ui_showcase` database was served on 8096 with registration on for that
  process only. The 8090 showcase kept it off. Mail went to a local SMTP sink, so links never reached the application
  log.
- **Registration off.** The 8090 signup page shows only the notice.
- **Applicant.** The start page offers the approved plan with its trial and price, and is phone-safe. The answer is
  "check your inbox". Opening the link only offers Continue; a used link reads as invalid. The details step shows the
  terms and privacy versions. The submission goes to review.
- **Operator.** The operator page shows readiness and the application's review reason and live eligibility, with no IP
  address. Approval provisions the organisation.
- **Activation.** The activation e-mail carries the one-time invitation; the owner chooses a password; the tick marks
  the application active.
- **First run.** The owner's Home shows the set-up banner. Getting started keeps the five states apart and is
  phone-safe.
- **Boundaries.** The owner gets 403 on `/admin/platform-signups` and `/admin/tenants`. There is no application view
  without the confirmed session.

axe states:
- signup off;
- start (desktop and phone);
- sent;
- confirm;
- details;
- status in review;
- operator list and application;
- owner Home with banner;
- Getting started (desktop and phone).

**Visual regression: one reviewed baseline change.**
- **What changed.** `admin-audit` (chromium-phone, light) changed only in its count line: "949 audit events you can
  see", was 945.
- **Cause.** Every tenant now receives the `account-owner` system role, whose creation is audited (+1). The other +3 is
  seed drift from earlier phases: run the same way, the phase's starting commit renders 948 and still passed, within the
  0.1% pixel tolerance.
- **How it was established.** Full runs of both commits against freshly prepared databases, plus the per-action
  difference of the two databases.
- **Fix.** The baseline was regenerated (`fd012f6`). The changed region is that line only, and every other screen is
  unchanged.

**Local theme build.** `public/build` is gitignored and built at deploy, so the theme was rebuilt locally
(`npm run build`) to include the first-run classes.

**First-run screenshots** (scratch, not committed): start page, details, operator application, Getting started. The
layouts were checked visually.

## 12. Performance results

MySQL 8.4 on the workstation, disposable database, fictional data. Another session's tests used about one core, so
timings carry some noise; query counts do not. The probe file is scratch only (not committed).

| Step | Time | Queries |
|---|---|---|
| Start (median of 20; includes the e-mail through the `array` mailer) | 12 ms (max 38) | 11 (max 19) |
| Confirm the link | 6 ms | 7 |
| Submit details | 15 ms | 17 |
| Provision: tenant, seeding, Account Owner, trial, invitation (median of 20) | 1.53 s (max 1.65) | 3,196 |
| Tick, 10,020 applications, 100 due for expiry | 0.56 s | 505 (5 per expiry, bounded batch) |
| Tick, 10,020 applications, nothing due | 41 ms | 5 |
| Operator page, 10,020 applications | 103 ms | 16 |
| Operator page, one application selected | 169 ms | 38 |
| 10 concurrent starts (10 processes) | 0.6 s wall, all ok | — |
| 4 ticks provisioning 10 applications at once | 18.9 s wall; 10 of 10 provisioned, one at a time | — |

- **Lock duration.** The provisioning transaction holds the application's row (and the provisioning lock) for the run,
  about 1.5 to 2 s.
- **Indexes.** The expiry scan uses `signup_applications_status_expires_index` (range, 1 row examined per match). The
  open-address and link lookups are unique-index const lookups.
- **Before the fix.** The same four-tick run provisioned 5 of 10 at the first attempt: the other five deadlocked and
  were retried later.
- **Cost of provisioning.** About 3,200 queries, dominated by the existing seeding (roles and their permissions,
  settings, module defaults). Operator provisioning pays the same (L5).
- **Signup steps.** Each step is a small constant number of queries. There are no unbounded queries; lists are limited
  (operator list 50, tick batches 100 and 20).

## 13. Defects found and fixed during the phase

| ID | Defect | Found by | Fix |
|---|---|---|---|
| E1 | Any holder of `user.assign_roles` could give Tenant Super Admin (themselves included) | Discovery | `RoleGrants`, `8d8727a` |
| E2 | Blueprint import could rewrite any role's permissions | Discovery | Import ceiling, `8d8727a` |
| E3 | SSO default role could be Tenant Super Admin | Discovery | Refused in form and at sign-in, `8d8727a` |
| S1 | Concurrent provisioning of new tenants deadlocked (audit chain head index gap locks) | MySQL performance probe | One provisioning at a time, `208efde`; race 7 |
| S2 | First-run classes missing from the stale local theme build | Browser screenshots | Two-column layouts; theme rebuilt locally (`public/build` is built at deploy) |
| T1–T3 | Three tests that could not fail (SSO roles read without a tenant; a fence hidden by a second fence; race 6 paused on the lock it should test) | Mutation testing | `c22d284`, `94e8dab` |

## 14. Remaining defects and limitations

| ID | Item | Severity |
|---|---|---|
| L1 | The audit recorder still takes gap locks when several new tenant chains are written at once. Signup avoids it by serialising; operator provisioning at the same moment as a signup provisioning could deadlock (the signup side retries) | P3 |
| L2 | The entitlement engine is in shadow mode: plan limits are shown and recorded, not enforced (D-16) | Inherited |
| L3 | No CAPTCHA or bot-protection provider (D10-9) | P3 |
| L4 | Owner MFA follows the tenant policy (off by default) (D10-8) | P2 |
| L5 | Provisioning costs about 3,200 queries and 1.5 to 2 s per tenant (existing seeding, same as operator provisioning) | P3 |
| L6 | Expiry handles 100 applications per tick, so a backlog of 10,000 due at once takes 100 minutes to drain | P3 |
| L7 | No automatic "activation expired" or trial-ending e-mail; no subscription-status notifications exist yet | P3 |
| L8 | A provisioned tenant whose owner never activates is not closed (D10-10); unconverted applications are never purged (D10-6) | Decision |
| L9 | Domain ownership is not verified: a matching domain only flags the application for review | P3 |

## 15. Owner decisions still required

| ID | Decision |
|---|---|
| D10-1 | Authorise public registration, after an independent security review (`docs/saas/SaaS-10-Threat-Model.md`) |
| D10-2 | Offers: plans, trial length (D-4), countries; then approve `signup.offers` |
| D10-3 | Review policy: every application (default) or flagged only (D-9) |
| D10-4 | Terms of Service and Privacy Notice (legal), then approve `signup.terms` and `signup.privacy_notice` |
| D10-5 | D-17: one login per address (kept) |
| D10-6 | Retention of unconverted applications (no period invented) |
| D10-7 | The Account Owner permission set; whether operator-provisioned tenants should also start with it |
| D10-8 | MFA for account owners from the first sign-in |
| D10-9 | CAPTCHA or bot-protection provider |
| D10-10 | What happens to a tenant whose owner never activates |
| D10-11 | Data region (D-11) at signup |

## 16. Public registration status

**Disabled:**
- the default is false;
- the variable is unset in `.env` and in the 8090 server;
- it was on only for the disposable fixture process on 8096, now stopped;
- the signup pages on 8090 show the notice.

## 17. Inherited blockers (unchanged by this phase)

- **Statutory verification:** 24 rules, 0 verified.
- **Recovery:** backup restore and DR unverified.
- **Production configuration:** not validated.
- **Offboarding rehearsal:** the production-copy rehearsal has not been run.
- **Retention:** D8-1 to D8-8 pending.
- **Tenant deletion:** off.
- **Repository:** the repository is public, the credential remains in history, and the history decision is open.

## 18. Confirmations

- No push, merge or deployment occurred.
- Public signup was not enabled anywhere except the disposable fixture process.
- Tenant deletion was not enabled.
- Live payments were not activated: no provider is called by signup.
- No production data exists here or was modified.
- SaaS.11 has not started.

## 19. Acceptance criteria

| Criterion | Result | Evidence |
|---|---|---|
| Signup and provisioning have explicit state transitions | Yes | `SignupStatus::TRANSITIONS`, `SignupTransitions` (the only status writer; architecture test), state machine test, race 4 |
| E-mail verification is secure | Yes | Hashed single-use expiring links consumed by POST, session regenerated, one answer for every address; link, enumeration and session tests; mutants S02, S03, S04, S32 |
| The initial administrator cannot obtain platform privileges | Yes | Account Owner (explicit keys), no operator flag in signup code (architecture test), `RoleGrants` E1–E3; mutants S12, S19–S23, S31 |
| Tenant provisioning is idempotent and concurrency-safe | Yes | One-at-a-time lock, fenced claim, token re-check under the row lock, unique keys; MySQL races 3, 5, 6, 7; mutants KS1–KS6, S30 |
| Commercial entitlements are correctly enforced | Yes, for signup | Only approved offers, a plan version on sale with entitlements, market and price, the plan limit; trial only via `startSignupTrial`; never a paid subscription. The global entitlement engine stays in shadow mode (D-16, inherited) |
| Partial failures can be recovered safely | Yes | One transaction; backoff; manual review; lease recovery; invitation retries; rollback, lease and invitation tests |
| No tenant boundary can be bypassed | Yes | §7 |
| Operator actions are authorised and audited | Yes | Service guards (operator plus reason), page 403 for others; operator tests; mutants S17, S18, S26 |
| Sensitive values are not exposed in logs | Yes | Log test (no link, token, password or address); failure summaries fixed; applicant audit anonymous; no signup link in any log file |
| Existing SaaS.7–SaaS.9 behaviour remains intact | Yes | Full SQLite and MySQL suites (§9); one existing test adjusted (`BlueprintsTest`: importer now in the destination tenant) |
| Tests pass with failures and skips reported accurately | Yes | §9 (skips are the MySQL-gated tests, run separately) |
| Documentation and runbooks are complete | Yes | §20 |
| Public registration remains disabled unless explicitly authorised | Yes | §16 |

**SaaS.10: COMPLETE. Production readiness: NOT ESTABLISHED** (§17, §15).

## 20. Documents

| Deliverable | Document |
|---|---|
| Discovery report | `docs/saas/SaaS-10-Discovery-and-Architecture.md` |
| State machine; architecture and data flow; migration and rollback | `docs/architecture/saas-10-signup.md` |
| Threat model and security review | `docs/saas/SaaS-10-Threat-Model.md` |
| Operator runbook | `docs/operations/runbooks/signup-operations.md` |
| Customer onboarding runbook | `docs/operations/runbooks/customer-onboarding.md` |
| Failure recovery and reconciliation runbook | `docs/operations/runbooks/signup-recovery.md` |
| Configuration | `docs/operations/signup-configuration.md` |
| Scheduler | `docs/operations/queue-and-scheduler.md` |
| Browser evidence | `docs/saas/evidence/SaaS-10-browser-validation.json` |
| Decisions and invariants | ADR-0104 to 0110 (`decision-register.md`); security invariants 108 to 115 |
| This report | — |
