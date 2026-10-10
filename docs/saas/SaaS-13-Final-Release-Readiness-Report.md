# MARKEDGE PEOPLEOS — SAAS.13 FINAL RELEASE READINESS REPORT

Date: 10 October 2026. **For:** the owner of Markedge PeopleOS.

> **Conclusion: BLOCKED — RELEASE GATES OUTSTANDING.**
>
> - Every safe engineering task available on this workstation was done.
> - The remaining gates need the owner, an independent security reviewer, a statutory specialist or real infrastructure.
>   None of those has arrived since SaaS.12. All were re-checked today; none was assumed.
> - Deployment was **not** authorised and **not** performed. No post-release validation exists.

## 1. Starting and ending commit

| | Commit |
|---|---|
| Start | `d4792e1` (verified: branch `feature/oct_1_phase_1`, clean tree, 75 commits ahead of `origin`) |
| Code | `d08512e` (unreachable register fails closed), `408f2ed` (demo-account readiness) |
| End | The documents commit that closes SaaS.13 (see `git log`) |

## 2. Branch and working tree

- Clean before and after.
- Nothing pushed, merged, force-pushed or deployed.
- No history rewritten.
- The workstation rebooted before this phase (17:17). The 8090 showcase was restarted.
- Another project's test run (RecruitmentEdge, `.re-worktrees/cp-test`) was seen on the machine and left untouched.

## 3. Changes

| Change | Why |
|---|---|
| `d08512e`: planning a deletion refuses with a reason, and the offboarding tick reports (critical log, exit 1) when the deletion register is unreachable | Probed: a raw storage exception and an unhandled tick crash (Section 11, missing or unavailable evidence) |
| `408f2ed`: readiness FAILs in production while an account on a reserved domain (`.local`, `.test`, `example.*`) can sign in | Section 13 demo-credential coverage: nothing noticed demo accounts in a production database |
| Development `.env` 664 → 600 (no commit: workstation file) | Section 13 explicit instruction; application verified afterwards |

**Documents:**
- `SaaS-13-Owner-Action-Pack.md`: credential rotation steps; repository options with exact commands; MFA enrolment; RPO
  and RTO options; gratuity decision and specification sequence; infrastructure inputs.
- The master register and the release-gate matrix, updated in place.
- Recovery runbook, production configuration, invariants 124–125, ADRs 0119–0120, architecture note.

## 4. Credential remediation status

- **Local (re-verified today, yes/no, value never displayed):** accepted by none of 19 PeopleOS databases, no MySQL
  account (`root` by socket and TCP, `markedge_app`) and no `.env` value.
- **External:** **not checked**. No authorised access to GitHub, e-mail, hosting or other applications exists. Those
  systems must be treated as potentially compromised until you confirm each one (action pack §1).

## 5. Repository exposure status

- **Public** (API, today).
- `feature/sep_28_phase_1` (tip `9bf682f`, unchanged since 8 October) still contains the value in two files (scan,
  today).
- PR #1 is open.
- Options A, B and C, with commands, impact and verification: action pack §2. None was executed without authorisation.

## 6. MFA evidence

- **Enforcement verified:**
  - checked per request;
  - remember-me and SSO sessions challenged;
  - protected downloads covered;
  - recovery codes single-use and audited;
  - resets audited and ending sessions;
  - the server-side emergency removal is audited, never silent;
  - readiness FAILs in production on an unenrolled operator.
- **Enrolment: not verified.** 0 of 1 operators in each local database; no production operators exist. Instructions:
  action pack §3.

## 7. Independent security review

**Not engaged. No reviewer, no findings.** The pack and checklist are ready (`SaaS-12-Independent-Security-Review-Pack.md`,
`SaaS-11-External-Security-Review-Checklist.md`).

## 8. Statutory verification

0 of 24 rules verified, 5 notices open. No specialist is engaged. No rule was marked verified, and no value was
invented.

## 9. Gratuity capability and status

- **Not implemented**:
  - a stored, unverified rule that no code uses;
  - the exit settlement has no gratuity line (manual lines only);
  - a payroll classification label is not a calculation.
- Whether launch needs it is your decision with the specialist. If it does, the approved specification comes before
  any code, and the feature stays behind the verified-rules gate (action pack §5).

## 10. Infrastructure status

**None exists.** Nothing was provisioned, and nothing billable will be without authorisation. Requirements:
`SaaS-12-Production-Infrastructure-Requirements.md`. Inputs needed: action pack §6.

## 11. Recovery evidence

- Local only: the SaaS.11 rehearsal and the SaaS.12 MySQL drill. **These are not production recovery tests.**
- Real recovery: BLOCKED — ENVIRONMENT.
- RPO and RTO: options prepared; **not approved**.

## 12. Production configuration

- The checker now also covers demo accounts.
- An unreachable deletion register fails closed.
- Run as production on the development `.env`: 11 errors (expected on a development machine; the `.env` permission
  error is gone).
- No target exists to validate.

## 13. Database and seeder safety

- Permission sync re-verified on real development data: 790 pairs identical, 0 audit events.
- Seeders refuse production (tested).
- Migration behaviour as established in SaaS.12, unchanged (no migration this phase).

## 14. D8, D10 and billing decisions

All open (`SaaS-12-Owner-Decision-Register.md`). Signup, deletion and live payments stay **disabled**.

## 15. Staging results

**None**: no staging environment, and no authorisation.

## 16. Test results

All results below were executed today on code `408f2ed`, on this workstation. They are not staging or production
evidence. Skipped tests are not counted as passed.

| Suite | Result |
|---|---|
| **SQLite, CI-equivalent** (a copy prepared as CI does: `.env` from `.env.example`, key generated, whole-repo Pint, then the suite; in parallel here, serial in CI) | Pint passed. **1,469 passed, 0 failed**; 1,604 tests, 20,055 assertions, 21.6 min. **135 skipped**: exactly the MySQL-gated tests |
| **MySQL** (`tests/MySql`, MySQL 8.4, clean tree) | **135/135 passed**, 821 assertions. 61 min: another project's test suite ran on the machine at the same time |
| Concurrency | Within the MySQL run: 132 tests in 18 concurrency files, all passed |
| Mutation (SaaS.13 changes) | **5/5 killed**: unreadable register at planning (U1) and in the tick (U2); demo accounts passing (D1); suspended accounts counted (D2); `.local` not recognised (D3). Baseline 13/13 |
| Tenant isolation and authorisation | Inside the full SQLite run (tenancy, security, identity, signup and access tests). Permission sync re-verified on real development data (790 pairs identical) |
| Visual regression (UX.18) | **120/120 passed**; 198 skipped by the designed viewport and engine matrix (as before) |
| Browser suite | **74/74 passed**; 6 skipped by design (phone-only tests on the desktop project) |
| Signup browser flow with axe | **Not re-run in SaaS.13.** Its scratch tooling and fixture credentials were lost when the workstation rebooted, and SaaS.13 changed no signup code. Last executed in SaaS.12 on `fa42fac`: 34/34, 0 violations |
| Dependencies | composer 0 advisories; npm 0 vulnerabilities (today) |
| Performance | MySQL scale probe: query counts constant (people analytics 64, API page 15) from 15 to 10,000 employees. Times are noisy under the concurrent load (people analytics 185 ms and API page 51 ms at 10,000) |
| Migration checks | No migration in SaaS.13; the SaaS.12 checks stand |
| Recovery tests | Not possible: no environment |

## 17. Release-gate matrix

`SaaS-12-Final-Release-Gate-Matrix.md` (the single matrix, revalidated today): 22 gates, none with an approval
reference. Gate 6 (gratuity assessed) has its assessment done but the decision open.

## 18. Outstanding blockers and exact owner actions

1. **O-1:** rotate the leaked password everywhere it was used and confirm per system (action pack §1).
2. **O-2, O-3:** decide visibility, and authorise option A, B or C for the public branch and history (§2).
3. **O-14:** have each operator enrol MFA (§3).
4. **O-6:** choose RPO, RTO and the backup policy (§4).
5. **O-12, O-5:** decide whether launch needs gratuity, and engage a statutory specialist (§5).
6. **O-4:** engage an independent security reviewer.
7. **O-7, O-8, O-10, O-11:** choose infrastructure, monitoring and on-call, and delegate access (§6).
8. **D8, D10, B:** only for the capabilities you want to switch on.
9. **O-9:** production deployment authorisation, last.

## 19. Deployment

| Question | Answer |
|---|---|
| Was deployment authorised? | **No** |
| Was production deployed? | **No** |
| Did post-release validation pass? | **Not applicable**: nothing deployed |
