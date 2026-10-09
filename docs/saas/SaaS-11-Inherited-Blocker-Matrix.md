# SaaS.11 — Inherited blocker verification matrix

Date: 10 October 2026. Each blocker inherited from SaaS.7 to SaaS.10 was checked against **current** evidence, not
carried forward from the earlier reports.

**Status values:**
- **Unchanged:** still open; re-verified today.
- **Refined:** still open; new evidence changes its scope.
- **Gate added:** still open, but now enforced by a check.
- **Closed:** resolved, with evidence.

Code references are at `62f2603`.

## 1. Release blockers (SaaS.9 closure §18)

| ID | Blocker | Evidence gathered today | Status | Gate |
|---|---|---|---|---|
| F1 | Leaked credential in public history | API: repository **public**, 0 forks, PR #1 open. `d75226e` is in `feature/oct_1_phase_1` and `feature/sep_28_phase_1`, not `main`. No PeopleOS database accepts the value (5 checked, yes/no). The rotated accounts have no sessions or remember-me tokens | **Refined:** the value is also in the **current tree** of public `feature/sep_28_phase_1` (S11-4) | Owner: rotate elsewhere, decide visibility, branch and history (`SaaS-11-Security-Findings.md` §2.3) |
| F2 | Statutory: 24 rules, 0 verified | `peopleos:readiness`: 24 rules, 0 verified, 5 open notices; 21 draft, 3 in review. Enforcement always on in production | **Unchanged.** Evidence package produced | Statutory specialist (`SaaS-11-Statutory-Verification-Evidence-Package.md`) |
| F3 | Backup restore and DR not verified in a target | No target environment exists. A local, isolated rehearsal ran 14 of 14 steps (mechanics only) | **Unchanged** for the target | Infrastructure (`SaaS-11-Backup-and-DR-Test-Report.md` §4) |
| F4 | Production configuration not validated | The validator, run as production on the development `.env`: 11 errors, 2 warnings (expected here). The validator now also covers release switches, seed password, HSTS and robots (S11-3); readiness covers operator MFA (F8) | **Unchanged** for the target; **rules extended** | Infrastructure (`SaaS-11-Production-Configuration-Assessment.md`) |
| F5 | Production-copy offboarding rehearsal not executed | No production-like copy exists | **Unchanged** | Infrastructure plus the owner |

## 2. Preconditions for enabling deletion

| ID | Blocker | Evidence gathered today | Status |
|---|---|---|---|
| F6 | D-8 policies unconfigured | `hcm` and showcase: 0 retention policies, 0 contract terms, 0 legal holds, 0 offboardings, 0 deletions. Unresolved values stay unconfigured; planning refuses with `LEGAL_MINIMUM_NOT_CONFIGURED` / `RETENTION_POLICY_NOT_CONFIGURED` | **Unchanged** (safe) |
| D8-1 … D8-8 | Retention decisions | Register unchanged; no value invented | **Unchanged**: owner and counsel |
| D8-5 | Backup ageing evidence | No backup system exists to prove expiry | **Unchanged** |
| F11 | No automated re-application of deletions after restore | The certificate lives only in the database; a restore before a deletion erases its proof | **Refined (S11-5):** an out-of-database deletion register is required, plus reconciliation before traffic (DR report §5) |
| — | Deletion switch | `PEOPLEOS_TENANT_DELETION_ENABLED` unset (false) in `.env` and the 8090 server. Production now refuses it without `PEOPLEOS_TENANT_DELETION_AUTHORISATION` | **OFF; gate added** |
| — | Maker-checker, holds, cooling-off | Code unchanged since the closure except one inventory entry (`signup_applications`). Tests pass in today's SQLite run | **Unchanged** (controls in place) |

## 3. Security and hygiene findings

| ID | Finding | Evidence gathered today | Status |
|---|---|---|---|
| F7 | Income-tax Rules r.46(8): hosting of electronic books | Legal question; nothing to verify in code | **Unchanged:** counsel (D8-7) |
| F8 | Operator without MFA enrols at next sign-in | 1 operator in each local database, 0 enrolled | **Gate added** (`62f2603`): readiness FAILs in production. Enrolment is an operator action |
| F9 | Exit lock on the `tenants` row | `LegalHolds` still locks the `tenants` row | **Unchanged** (P2 follow-up) |
| F10 | Showcase personas use the fixture word | 8090 listens on `127.0.0.1` only | **Unchanged** (by design; local only) |
| F12 | Planning cost grows with the database | Planner code unchanged; not re-measured | **Unchanged** |
| F13 | Seed password set locally | Still set in the development `.env` (yes/no) | **Gate added:** production refuses it (S11-3) |
| F14 | Cooling-off can be 0 | `max(0, …)` in config | **Unchanged**: a floor is a policy decision; deletion is off and gated |
| F15 | Obsolete replay databases | `hcm_p12_replay`, `hcm_p13_replay`, `hcm_p14_replay`, `hcm_phase12_replay` still exist | **Unchanged**: owner may ask to drop them |
| F18 | Contract-term lookups per category | Code unchanged | **Unchanged** |

## 4. SaaS.10 limitations and decisions

| ID | Item | Status |
|---|---|---|
| D10-1 | Public registration | **OFF** (unset in `.env` and the 8090 server; default false). Production refuses it without a recorded authorisation |
| L1 | Audit-recorder gap locks across simultaneous new chains | Unchanged (P3) |
| L2 / D-16 | Entitlements in shadow mode | Unchanged |
| L3 / D10-9 | No CAPTCHA | Unchanged |
| L4 / D10-8 | Owner MFA off by default | Unchanged |
| L5, L6, L7, L9 | Provisioning cost; expiry batch; no lifecycle e-mails; domain not verified | Unchanged (P3) |
| L8 / D10-6, D10-10 | Unconverted applications and never-activated tenants kept | Unchanged (decision) |
| E1–E3 | Escalation fixes | **Still enforced:** tests pass; mutants re-run against today's code (final report) |

## 5. Commercial gates (SaaS.7)

| Item | Status |
|---|---|
| Live payments | Razorpay **off**. Production now refuses it on, and refuses a live key (S11-3) |
| Real invoicing | Waits for B-4 (prices), B-7 (selling entity, GSTIN, LUT), B-8 (India SAC), B-9 (registrations abroad). No value invented |

## 6. New in this phase

| ID | Item | Status |
|---|---|---|
| S11-1 | Role permission changes not audited | **Closed** (`a5afc65`) |
| S11-2 | Indexable platform | **Closed** (`cf70e5d`) |
| S11-3 | Release switches unknown to the production gate | **Closed** (`cf70e5d`) |
| S11-4 | Credential in a public branch's current tree | Open: owner |
| S11-5 | Deletion proof only in the database | Open: precondition for deletion |
| S11-6 | No PeopleOS scheduler or worker anywhere; CI covers SQLite only | Open: deployment and CI |
| — | Independent security review | **Not started** (external gate) |
