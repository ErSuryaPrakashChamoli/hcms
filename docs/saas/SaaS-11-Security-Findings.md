# SaaS.11 — Security findings and remediation evidence

Date: 10 October 2026. Branch `feature/oct_1_phase_1`. **No secret value was displayed, retrieved or written while
preparing this document.** Credential checks answer yes or no. Commits and files are named, never their content.

**What this is:** an internal review by the engineering agent that built the software.
**What it is not:** the independent security review the owner requires. That review is an external gate:
`SaaS-11-External-Security-Review-Checklist.md`. Passing internal tests is not a substitute for it.

## 1. Summary

| ID | Finding | Severity | Status |
|---|---|---|---|
| F1 | A leaked credential is in the history of a **public** repository | P1 | **Open, owner action.** Not active in any PeopleOS database here |
| S11-4 | The same credential is in the **current tree** of the public branch `feature/sep_28_phase_1` | P1 | **Open, owner action** (needs a push or a branch deletion) |
| S11-1 | A change of a role's permissions was not audited | P2 | **Fixed** `a5afc65` |
| S11-2 | Pages were indexable; tokens travel in link paths | P2 | **Fixed** `cf70e5d` |
| S11-3 | The production gate did not know the release switches | P2 | **Fixed** `cf70e5d` |
| F8 | Operators without an enrolled authenticator (1 of 1 in each local database) | P2 | **Gate added** `62f2603` (readiness FAILs in production); enrolment is an operator action |
| S11-5 | Deletion certificates live only in the database (a restore erases the proof as well as the deletion) | P1 for enabling deletion | Recorded; precondition for deletion |
| S11-6 | CI runs only Pint and the SQLite suite, on `main` and pull requests | P3 | Recommendation |
| L1–L9 | SaaS.10 limitations | P2–P3 | Unchanged (§6) |

No P0 is open: the credential is not active anywhere this phase could check.

## 2. Repository exposure (F1, S11-4)

### 2.1 Evidence

| Fact | Evidence |
|---|---|
| Visibility | GitHub API, unauthenticated, 10 October 2026: `public`, 0 forks, default branch `main`, last push 8 October |
| Open pull request | #1, `feature/oct_1_phase_1` → `main`. GitHub keeps its own reference to the head commits (`refs/pull/1/head`) |
| Commit that introduced the value | `d75226e` (28 September 2026, "user") |
| Commit that removed it from the seeders and documents | `8f54d4d` (6 October 2026, SaaS.2) |
| Branches whose history contains `d75226e` | `feature/oct_1_phase_1` (local and remote), `feature/sep_28_phase_1` (local and remote). **Not** `main` |
| Branches without `8f54d4d` | `feature/sep_28_phase_1` (tip `9bf682f`): its **current files** still hold the value in `database/seeders/DatabaseSeeder.php` and `docs/architecture/phase-1-foundation.md` |
| Commits rewritten if history were cleaned | every commit from `d75226e` onwards on both branches: 151 between `d75226e` and `8f54d4d` on this branch alone, plus everything after it |
| Is the value accepted anywhere here | **No.** Checked yes/no against every user in 5 PeopleOS databases (development, showcase and fixture databases) |
| Sessions and tokens of the rotated accounts | none: no session rows and no remember-me token for the rotated showcase accounts (SaaS.9 closure §4, re-checked) |
| Other secrets in history | 284 commits on all refs scanned for cloud keys, private keys, application keys, live payment keys, tokens and `.env` files: none. Two pattern hits are a fictional refusal-test key and a documentation placeholder |

### 2.2 What remains exposed, and why rotation matters more than a rewrite

Anyone who cloned or browsed the repository since 28 September may already have the value. **A history rewrite cannot
recall it.** The remedy that matters is that the value is accepted **nowhere**:
- In PeopleOS this holds: every PeopleOS database here refuses it.
- Outside PeopleOS (another system, account, e-mail or database where the same password was used) only the owner can
  check and rotate it. This phase did not rotate any external credential. That needs the owner's authorisation.

### 2.3 History-cleanup plan (prepared, NOT executed; needs explicit authorisation)

The order matters. Each step names what it does and does not achieve.

1. **Rotate everywhere first** (owner). Rotation is the only step that removes the risk; the rest reduces further
   exposure.
2. **Decide visibility** (owner). Making the repository private stops new viewers. It does not affect existing
   clones, and GitHub may keep cached views.
3. **Minimum without a rewrite** (needs push authority):
   - either delete the remote branch `feature/sep_28_phase_1`, whose history is in `feature/oct_1_phase_1`;
   - or push a commit to it that removes the two files' value.

   Either way, the current files stop showing it. History still holds it.
4. **History rewrite** (needs explicit authorisation to rewrite and force-push, including over the owner's own commits):
   1. Freeze pushes; record every branch tip and open pull request.
   2. On a fresh mirror clone (`git clone --mirror`), run `git filter-repo --replace-text <file>`. The file holds the
      literal and its replacement; it is created outside the repository, read from a secure location, and destroyed
      afterwards. It is never committed, pasted or logged.
   3. Verify on the mirror: a yes/no scan of every ref finds the value nowhere. `git fsck` is clean. The tree of
      every rewritten tip equals the old tip's tree except for the replaced text.
   4. Force-push every affected branch. Close and recreate pull request #1, because GitHub keeps
      `refs/pull/1/head` on the old commits.
   5. Ask GitHub Support to purge cached views and unreferenced commits. Old SHAs stay reachable by URL until they do.
   6. Every clone (this workstation included) re-clones; no old clone may push again. Commit SHAs cited in reports
      before the rewrite stop resolving: keep a mapping file (`filter-repo` writes `commit-map`).
   7. Re-run the yes/no scan against the remote after the purge.
5. **Prevent recurrence:** enable GitHub secret scanning and push protection, and add a pre-commit secret scan.
   The seeders already refuse production and generate a password when none is set (SaaS.2). The production validator
   now refuses a seed password (S11-3).

**Consequence of not authorising step 4:** the value stays readable in public history. It is harmless only if it is
accepted nowhere. Step 1 is therefore not optional.

## 3. Findings fixed in this phase

### S11-1 — role permission changes were not audited (P2, fixed `a5afc65`)

**Evidence of the gap:** a probe gave a role `payroll.finalize` through the role editor and recorded 0 audit events.
The user form audited role **assignments**, but nothing audited a role's **permissions**. So a holder of `role.update`,
or an importer under the E2 ceiling, could widen a role silently.

**Fix:** `RoleGrants::recordPermissionChange()` records `ROLE_CHANGED` with:
- the permissions granted and the permissions removed (keys only);
- whether the role is a system role;
- the reason and the actor.

It is called from three places: the role editor (`EditRole`), role creation (`CreateRole`) and the blueprint import
(`Blueprints`). Nothing is recorded when nothing changed.

**Tests:** `RoleGrantsTest` "audits a change of a role's permissions in the role editor and in a blueprint import".
**Mutants R1–R3:** see the final report.

### S11-2 — indexable platform (P2, fixed `cf70e5d`)

**Gap:** `robots.txt` allowed everything, and no response carried a noindex directive. Invitation, signup and
password-reset links carry their token in the path, so a crawler that learned such a link could have stored it.

**Fix:**
- `robots.txt` disallows everything;
- every response carries `X-Robots-Tag: noindex, nofollow` (`PEOPLEOS_ROBOTS_TAG`), including the API and health
  endpoints;
- an empty tag is a validator warning.

**Tests:** `SecurityHardeningTest`. **Mutant R10.**

### S11-3 — release switches unknown to the production gate (P2, fixed `cf70e5d`)

**Gap:** the validator accepted all of these in production:
- registration switched on;
- tenant deletion switched on;
- Razorpay on, or a live key;
- a demo seed password;
- a short HSTS max-age.

**Fix:** each is now an error, as shown below.

| Switch or setting | Production rule |
|---|---|
| Registration | refused without `PEOPLEOS_PUBLIC_SIGNUP_AUTHORISATION` |
| Tenant deletion | refused without `PEOPLEOS_TENANT_DELETION_AUTHORISATION` |
| Razorpay | refused while on |
| A `rzp_live_` key | refused |
| A seed password | refused when set |
| HSTS max-age | refused below one year |

The authorisation values are decision references, not secrets. The validator checks presence; reviewers check
authenticity.

**Tests:** `ProductionConfigurationTest`. **Mutants R4–R9.**

### F8 — operator MFA (gate added `62f2603`)

**Gap:** an operator with no authenticator is sent to enrol one at the next sign-in. So whoever holds the password
first enrols their own authenticator. Both local databases have one operator each, neither enrolled.

**Fix:** readiness reports "Every platform operator has MFA enrolled". It FAILs in production while any operator who
can sign in has none; suspended operators are not counted; it reports counts, never names.

**Not fixed in code:** enrolment at operator creation (a feature, not built in this phase). Enrolment itself is an
operator action.

**Tests:** `PlatformReadinessTest`. **Mutants R11–R12.**

## 4. Review of required areas

| Area | Review | Evidence |
|---|---|---|
| **SaaS.10 escalation fixes E1–E3** | Unchanged since SaaS.10, still enforced. E1: the user form refuses granting a role above the actor's ceiling. E2: a blueprint import writes only the importer's own permissions. E3: an SSO default role cannot be Tenant Super Admin, and it is never attached at sign-in | `RoleGrantsTest` (12 tests including S11-1); SaaS.10 mutants S17–S23 re-run against today's code (final report) |
| **Tenant isolation** | Fail-closed `TenantContext` and `BelongsToTenant` unchanged. The restore rehearsal re-proved isolation on a restored copy: 4 tenants, 0 foreign rows, 0 rows without a tenant | isolation suites (SQLite); restore evidence `evidence/SaaS-11-recovery-rehearsal.json` |
| **Account Owner permissions** | 35 explicit keys, no wildcard, all in the 256-key catalogue. No platform permission exists in the catalogue at all: operator power is the `is_platform_admin` flag, which signup never sets (architecture test). Holds `user.assign_roles`, bounded by `RoleGrants`; cannot give Tenant Super Admin. Holds `security.manage`, `audit.view` and `data.export` for its own tenant. Whether that set is right is D10-7 | config `roles.account-owner`; `SignupProvisioningTest` boundary test |
| **Platform operator access** | Platform pages refuse tenant users (403). Every `SignupReview` action refuses a non-operator and a null actor. Tenant access by an operator is time-limited (`PEOPLEOS_PLATFORM_TENANT_ACCESS_MINUTES`) and audited | operator and page tests |
| **MFA** | Platform MFA is required (`PEOPLEOS_PLATFORM_MFA_REQUIRED`, refused off in production). Tenant MFA follows the tenant policy, off by default, including for account owners (D10-8). Enrolment gap: F8 | SaaS.2 tests; readiness check |
| **Audit coverage** | New: role permission changes (S11-1). Already covered: role assignments, user changes, signup transitions, operator decisions, offboarding, exports, configuration. The hash chains verified valid on the source and on the restored copy (5 chains, 1,644 events) | `RoleGrantsTest`; restore evidence |
| **Session and token revocation** | The rotated accounts have no sessions and no remember-me tokens. A password reset, an MFA reset or an administrator's "end sessions" action bumps `session_epoch`, which ends every session on every device and clears the remember-me token. Each is audited (`SESSIONS_REVOKED`) | `SessionSecurity::revokeUser`; SaaS.9 closure; re-checked here |

## 5. Not reviewed, and why

| Area | Reason |
|---|---|
| Penetration test against a running deployment | no deployment exists; external reviewer |
| Infrastructure (network, WAF, TLS, secret store, backups) | none exists |
| Dependency vulnerability audit with an advisory feed | `composer audit` and `npm audit` need network advisories; recommended for the external review |
| Code review by someone other than the author | that is the external gate |

## 6. Residual risks carried from SaaS.10

| ID | Risk | Severity |
|---|---|---|
| L1 | Audit recorder gap locks when several new tenant chains are written at once (signup serialises; operator plus signup provisioning together could deadlock, and the signup side retries) | P3 |
| L2 | Entitlements in shadow mode (D-16) | Inherited |
| L3 | No CAPTCHA (D10-9) | P3 |
| L4 | Owner MFA off by default (D10-8) | P2 |
| L5 | Provisioning cost about 3,200 queries per tenant | P3 |
| L6 | Expiry drains 100 per tick | P3 |
| L7 | No activation-expired or trial-ending e-mail | P3 |
| L8 | Never-activated tenants and unconverted applications are kept (D10-10, D10-6) | Decision |
| L9 | Domain ownership not verified (flag only) | P3 |
