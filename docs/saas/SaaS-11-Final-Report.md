# SaaS.11 — Final report: production assurance, security review and release gate

Date: 10 October 2026. Branch `feature/oct_1_phase_1`.

> **Phase status: COMPLETE for engineering scope.** **Release decision: BLOCKED.** Five things are outstanding:
> - the credential exposure (owner);
> - statutory verification (specialist);
> - recovery in a target environment (infrastructure);
> - the independent security review (external);
> - owner decisions.
>
> No production readiness is claimed. Passing tests are not production readiness.

## 1. Starting and ending commit

| | Commit |
|---|---|
| Start | `e9914b8` (SaaS.10's ending commit; verified at the start, clean tree) |
| Code changes | `a5afc65`, `cf70e5d`, `62f2603` |
| Documents | the documents commit that follows `62f2603` (see `git log`) |

Nothing was pushed, merged or deployed. The branch is ahead of `origin/feature/oct_1_phase_1`.

## 2. What was done

| Mode | Work |
|---|---|
| Discover | Repository, remote and pull request state; deployment configuration; the environment (`.env` names only); databases; queues and scheduler; CI; earlier reports and registers (`SaaS-11-Discovery-Report.md`) |
| Verify | Every inherited blocker against current evidence (`SaaS-11-Inherited-Blocker-Matrix.md`); credential exposure yes/no in 5 databases; history scan of 284 commits; the statutory inventory; retention state; switches |
| Remediate | Four gaps fixed in code (§3); documentation of new variables and gates |
| Test | SQLite, MySQL, mutation, browser, accessibility, visual, migration integrity, a local recovery rehearsal, timings (§5) |
| Document | 10 deliverables (§8), plus updates to operations documents, invariants 116–119 and ADRs 0111–0114 |

## 3. Findings and remediation

| ID | Finding | Severity | Outcome |
|---|---|---|---|
| S11-1 | Role permission changes were not audited (a probe granted `payroll.finalize` with 0 events) | P2 | Fixed `a5afc65` |
| S11-2 | The platform was indexable; tokens travel in link paths | P2 | Fixed `cf70e5d` |
| S11-3 | The production gate ignored the release switches, seed password and HSTS strength | P2 | Fixed `cf70e5d` |
| F8 | Operators without MFA (1 of 1 in each local database) | P2 | Readiness gate `62f2603`; enrolment is an operator action |
| S11-4 | The leaked credential is in the **current tree** of public `feature/sep_28_phase_1` | P1 | Owner action |
| S11-5 | Deletion proof lives only in the database; a restore erases it | P1 for deletion | Procedure written; precondition for deletion |
| S11-6 | No PeopleOS scheduler or worker anywhere; CI covers SQLite only | P3 | Deployment and CI |
| F1 | Leaked credential in public history | P1 | Owner action; inactive in every PeopleOS database here |

Detail and the history-cleanup plan: `SaaS-11-Security-Findings.md`.

## 4. Gates (summary of `SaaS-11-Release-Gate-Register.md`)

| Gate | Status |
|---|---|
| G1 Credential exposure closed | **BLOCKED** (owner) |
| G2 Independent security review | **BLOCKED** (not started) |
| G3 Statutory verification: 24 rules, 0 verified, 5 open notices | **BLOCKED** (package ready for a specialist) |
| G4 Production environment configured | **BLOCKED** (none exists) |
| G5 Backup restore and DR in the target | **BLOCKED** (local rehearsal only) |
| G6 Operations running (worker, scheduler, monitoring) | **BLOCKED** |
| G7 Operators' MFA enrolled | **BLOCKED** |
| G8 Mail delivery | **BLOCKED** |
| G9 Engineering validation | **PASS for this phase's scope** |
| G10–G11 Registration (D10-1 to D10-11, terms, offers) | **BLOCKED**; registration OFF |
| G12–G14 Deletion (D8, deletion register, production-copy rehearsal) | **BLOCKED**; deletion OFF |
| G15 Commercial inputs (B-4, B-7, B-8, B-9) | **BLOCKED**; live payments OFF |

## 5. Validation (reported separately)

| Category | Result |
|---|---|
| **SQLite** (`php artisan test --parallel --processes=2`, at `cf70e5d`) | **1,450 passed, 0 failed**; 1,585 tests, 19,893 assertions, 20.8 min. **135 skipped**: exactly the MySQL-gated tests, not counted as passed. `62f2603` adds one test, run with its file and the other changed files: 29/29 (§5.1) |
| **MySQL** (`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_saas6_concurrency php artisan test tests/MySql`, at `62f2603`, MySQL 8.4) | **135/135 passed**, 820 assertions, 20.7 min. None skipped |
| **Concurrency** | Within the MySQL run: **132 tests in 18 concurrency files**, all passed. They use real processes against MySQL, among them SaaS.10's 7 signup races (duplicate starts, double link use, double provisioning, competing decisions, same-name slugs, lease expiry while committing, four provisionings at once with InnoDB's deadlock counter unchanged) and SaaS.9's 12 offboarding races |
| **Mutation** | **44/44 killed** against today's code. SaaS.11: R1–R12, 12/12. SaaS.10: S01–S32 re-run, 32/32. The copy was compared file by file afterwards: identical |
| **Isolation and authorisation** (run as their own group: `Tenancy`, `Security`, `Identity`, `Signup`, plus the isolation and access tests of platform, admin, statutory, documents, approvals, UX security and compensation) | **189/189 passed**, 1,678 assertions, none skipped |
| **Browser** (SaaS.10 signup flow, fixture on 8096, local SMTP sink) | **34/34 checks**, 0 console errors |
| **Accessibility** (axe-core, WCAG 2.0 and 2.1 A and AA) | **12 states, 0 violations** |
| **Visual regression** (UX.18 suite) | **120/120 passed**. 198 skipped by the suite's viewport and engine matrix, the same as SaaS.10; no baseline change |
| **Browser suite** | **74/74 passed**; 6 skipped by design (phone-only tests on the desktop project), as before |
| **Performance** | `peopleos:config:validate --as-production` 0.53 s; `peopleos:readiness --skip-audit` 0.59–0.73 s (each including boot, 3 runs). Local restore 16.1 s, restore to verified 31.0 s (15,965 rows; not production). MySQL scale probe (in the MySQL run, 15 to 10,000 employees): queries constant per page (Employee 360: 28; people analytics: 64; API page: 15); at 10,000 employees, people analytics 119 ms and the API page 53 ms. The SaaS.10 provisioning probe was **not re-run**: the provisioning path is unchanged, since SaaS.11 adds audit only in the role editor and the import |
| **Migration integrity** (new disposable MySQL schema, then dropped) | 125/125 from empty; a second migrate is a no-op; 0 pending. The 5 most recent rolled back and re-applied: identical schema fingerprint (columns, indexes, foreign keys). **Every `down()`** (`migrate:reset`) leaves only the migrations table; the full re-apply is identical. Development and showcase databases: 0 pending |
| **Restore and recovery** | Local isolated rehearsal on fictional data, **14/14 steps** (`SaaS-11-Backup-and-DR-Test-Report.md`; evidence JSON). **Target DR: NOT VERIFIED** |

### 5.1 Targeted runs

| Run | Result |
|---|---|
| `RoleGrantsTest`, `ProductionConfigurationTest`, `SecurityHardeningTest` and `PlatformReadinessTest` on the mutation copy at `62f2603` (the baseline before mutants) | 29/29 |
| `PlatformReadinessTest` in the working tree | 4/4 |

### 5.2 Skipped and not executed (not passed)

| Item | Why |
|---|---|
| 135 MySQL-gated tests in the SQLite run | They run in the MySQL suite, reported on its own row |
| 198 visual and 6 browser-suite tests | Designed matrix exclusions |
| Target-environment restore, DR, rollback and escalation drills | No environment |
| Production-copy offboarding rehearsal | No approved copy |
| Penetration test, dependency advisory audit | External review |
| SaaS.10 provisioning performance probe | Path unchanged (see Performance) |

**Failures found and investigated in this phase:**
- **My own DR evidence gap.** The first rehearsal recorded the heartbeat command's exit code only. The step now reads
  the heartbeat back on the restored copy, and the evidence is from the second run.
- **Two overstated claims** in my drafts were corrected against the code before commit: the Redis rules, and the
  wording on alerting.

No test failed.

## 6. Security, retention and SaaS.10 confirmations

- **Repository:** public, PR #1 open, history unchanged. No visibility change, rewrite, force-push or external rotation
  was done (not authorised). The plan is in the security findings §2.3.
- **Secrets:** none printed, retrieved or committed. Credential checks are yes/no. A scan of the new documents found 0
  occurrences of the leaked value (§9).
- **Retention:**
  - D8-1 to D8-8 remain open, and no value was invented;
  - legal minimum, Markedge policy and customer contract remain separate;
  - holds, maker-checker, cooling-off and the missing-policy refusals are unchanged and tested;
  - deletion stays OFF.
- **Registration:** OFF everywhere except the disposable 8096 fixture process, which was stopped. The Account Owner
  has no operator privilege; E1–E3 are covered; provisioning is idempotent; commercial configuration fails safe.
- **D10-1 to D10-11:** not decided. A register with the evidence needed and the consequences is in the release-gate
  register §3.

## 7. Owner actions required

1. **Rotate the leaked value wherever else it was used**, then decide the repository's visibility, and decide what
   happens to `feature/sep_28_phase_1` and the history (§2.3 of the security findings).
2. **Commission the independent security review** with the checklist.
3. **Commission statutory verification** with the evidence package.
4. **Provide a target environment**, then run the DR plan and the production-copy rehearsal there.
5. **Enrol MFA for every platform operator.**
6. **Decide D10-1 to D10-11** (registration), **D8-1 to D8-8** (deletion) and **B-4, B-7, B-8, B-9** (billing),
   when each scope is wanted.
7. Optional: drop the obsolete replay databases (F15).

## 8. Deliverables

| # | Deliverable | File |
|---|---|---|
| 1 | Discovery report | `docs/saas/SaaS-11-Discovery-Report.md` |
| 2 | Inherited blocker verification matrix | `docs/saas/SaaS-11-Inherited-Blocker-Matrix.md` |
| 3 | Security findings and remediation evidence | `docs/saas/SaaS-11-Security-Findings.md` |
| 4 | External security review checklist | `docs/saas/SaaS-11-External-Security-Review-Checklist.md` |
| 5 | Statutory verification evidence package | `docs/saas/SaaS-11-Statutory-Verification-Evidence-Package.md`, `docs/saas/evidence/SaaS-11-statutory-rules.json` |
| 6 | Backup and DR test report | `docs/saas/SaaS-11-Backup-and-DR-Test-Report.md`, `docs/saas/evidence/SaaS-11-recovery-rehearsal.json` |
| 7 | Production configuration assessment | `docs/saas/SaaS-11-Production-Configuration-Assessment.md` |
| 8 | Signup security regression report | `docs/saas/SaaS-11-Signup-Security-Regression-Report.md` |
| 9 | Release-gate register (with the D10 decision register) | `docs/saas/SaaS-11-Release-Gate-Register.md` |
| 10 | Final report | this file |

**Also updated:**
- `docs/operations/production-configuration.md` (§6, §6a);
- `docs/operations/backup-and-disaster-recovery.md` (§8);
- `docs/operations/runbooks/external-copies.md` (gaps);
- `docs/production/go-live-checklist.md` (H);
- `docs/architecture/security-invariants.md` (116–119);
- `docs/architecture/decision-register.md` (0111–0114);
- `.env.example`.

## 9. Confirmations

- No push, merge or deployment.
- Public registration not enabled (except the disposable fixture process, stopped).
- Tenant deletion not enabled.
- Live payments not activated.
- No production data exists here, and none was modified.
- No test changed the 8090 showcase's data. The browser flow only viewed its signup notice, which writes session rows,
  as any visit does.
- The disposable schemas created for the restore and for migration integrity were dropped.
- Git history not rewritten; repository visibility not changed; no external credential rotated.
- No legal value invented. No statutory rule marked verified.
- Unrelated work preserved. Logical commits.
- **SaaS.12 has not started.**
