# MARKEDGE PEOPLEOS — END-TO-END PRODUCTION RELEASE REPORT

Programme SaaS.12: release-gate remediation through final production release. Date: 10 October 2026.

> **Final release state: A. BLOCKED — OWNER OR EXTERNAL ACTION REQUIRED.**
>
> Every safe engineering stage was carried out. Four defects were found and fixed, the restore-reconciliation mechanism
> was built and tested, and the external and owner packages were prepared.
>
> The programme stopped at the gates that only the owner, an independent reviewer, a statutory specialist or a real
> environment can satisfy:
> - nothing was deployed;
> - no approval was assumed;
> - no environment was claimed as tested.

## 1. Initial and final commit

| | Commit |
|---|---|
| Initial | `960adeb` (verified HEAD; clean tree; 64 commits ahead of `origin/feature/oct_1_phase_1` at `36a9292`) |
| Final code | `fa42fac` (application code identical to `1907743`; `fa42fac` adds an architecture-test allow-list entry) |
| Final | The documents commit that closes the programme (see `git log`) |

SaaS.12 commits:
- `71c6d3c` style (CI);
- `a185e1a` error-response test;
- `0e935d4` npm audit fix;
- `90ef326` system-role top-up;
- `812f2c9` seeder guard;
- `db55ba1` deletion register and reconciliation;
- `470dd25` file-permission rules;
- `1907743` mutation-driven fixes;
- `30aa659` documents;
- `fa42fac` architecture allow-list (found by the CI-equivalent run);
- the final documents commit.

## 2. Branch and repository state

- Branch `feature/oct_1_phase_1`; nothing pushed, merged or deployed.
- The GitHub repository is **public**. PR #1 (`feature/oct_1_phase_1` → `main`) is open at the old head.
- Remote branches: `main` (`94c439f`), `feature/sep_28_phase_1` (`9bf682f`), `feature/oct_1_phase_1` (`36a9292`).
- History was not rewritten and no branch was deleted.

## 3. Workstreams completed

| Stage | Outcome |
|---|---|
| 0 Truth | Baseline (`SaaS-12-Baseline.md`) |
| 1 Security containment | Local containment verified. External containment blocked on the owner (§4) |
| 2 Owner decisions | Consolidated register (`SaaS-12-Owner-Decision-Register.md`); nothing decided on the owner's behalf |
| 3 Statutory | Gate validated by mutation; per-rule register prepared; 0 rules verified (specialist) |
| 4 Retention and offboarding | **Deletion register and restore reconciliation built and tested** (SQLite faithful restore, MySQL drill, mutation) |
| 5 Independent review | Pack prepared; review not started |
| 6–7 Infrastructure and configuration | Requirements written; checker extended; no environment exists |
| 8 Database and deployment | Deploy-time access reset fixed; seeders hardened; migration behaviour tested; deployment plan |
| 9 DR | Blocked: no environment, no approved objectives |
| 10 Validation | §17–§20 |
| 11–13 Registration, billing, deletion | All off and fail-closed; activation gates documented |
| 14 Release candidate | Draft package; not frozen |
| 15 Final gate | **BLOCKED** (`SaaS-12-Final-Release-Gate-Matrix.md`) |
| 16–18 Deploy, post-release, handover | Not started: no authorisation, no environment |

## 4. Credential exposure and remediation evidence

All checks were yes/no; no secret was displayed.

**Checked here (all negative):**
- The leaked value is accepted by **no** PeopleOS account in 19 databases.
- It is accepted by no MySQL account (`root` by socket and TCP, `markedge_app`).
- No `.env` variable holds it.
- None of the current secrets (`APP_KEY` encoded and decoded, `DB_PASSWORD`, the seed password) appears in logs, in 2,932
  tracked files or in the evidence.

**Not checkable from here:**
- the GitHub account, e-mail, hosting and other products' application accounts (their data was not read);
- collaborators, deploy keys and repository secrets (no authenticated GitHub access).

CI uses no secrets.

**Owner action:** O-1 (rotate everywhere and confirm).

## 5. Repository visibility and history disposition

- **Undecided.** Public; the value is still in the tip of `feature/sep_28_phase_1` and in history.
- The cleanup plan (`SaaS-11-Security-Findings.md` §2.3) covers branch deletion or a removal commit, a coordinated
  `filter-repo` rewrite, PR #1, clones and GitHub Support. It now also needs a collaborator review (B5).
- No change was made without authorisation.

## 6. Independent security review findings

**None: the review has not started** (O-4). The pack maps every required area to its evidence and known gaps.

Internal findings this phase:

| ID | Finding | Severity | Status |
|---|---|---|---|
| D1 | Deploys silently re-granted permissions tenants removed from system roles | P2 | Fixed `90ef326` |
| R1 | A restore could silently bring back a deleted tenant (S11-5) | P1 for deletion | Built `db55ba1` |
| C2, R4 | Two reconciliation edge cases found by mutation | P2 | Fixed `1907743` |
| R4b | `.env` world-readable; checker blind to file permissions | P2 | Gate `470dd25` (local file left for the owner) |
| D2 | Showcase seeders guarded by database name only | P3 | Fixed `812f2c9` |
| B6 | CI failing at Pint, so tests never ran on the branch | P2 | Fixed `71c6d3c` |
| B11 | npm: 2 critical, 1 high (build tools) | P2 | Fixed `0e935d4` |
| B8 | No test of production error responses | P3 | Added `a185e1a` |

## 7. Statutory verification results

- **0 of 24 verified; 5 notices open.** No rule was marked verified, and no value or interpretation was invented.
- The gate cannot be bypassed: production always enforces it; unverified rules and pending notices block calculation;
  finalisation and exports re-check (mutants G1–G5 killed).
- Gratuity is stored but not computed.

## 8. D8 retention decisions

All eight open (`SaaS-12-Owner-Decision-Register.md` Group A). Deletion stays off.

## 9. D10 signup decisions

All eleven open (Group B). Your list's "Terms of Service" and "Privacy Notice" are both D10-4. Registration stays off.

## 10. Billing decisions

B-4, B-7, B-8 and B-9 open (Group C). Live payments stay off. Production refuses Razorpay until an approved release
enables live mode.

## 11. Operator MFA results

One operator in each local database, none enrolled. The readiness gate fails production until each operator enrols (O-14).

## 12. Production configuration assessment

- No production environment exists.
- The checker covers about 45 settings plus, new this phase:
  - `.env` and storage permissions;
  - the register disk when deletion is enabled.
- Run as production on the development `.env`: 12 errors (expected; one is the 664 `.env`).
- Requirements: `SaaS-12-Production-Infrastructure-Requirements.md`.

## 13. Migration and database safety results

- Fresh install: 126 migrations in 74 s on MySQL.
- SaaS.11 integrity: rollback of 5, a full reset and re-apply, identical schema.
- SaaS.12 migration: instant (verified with the algorithm forced).
- An interrupted run is **not** re-runnable: restore from the pre-deploy backup.
- `--isolated` serialises concurrent runs; first installs run from one server.
- Seeders refuse production.
- Deploys no longer reset role permissions: 790 role-permission pairs identical on the development and showcase
  databases.

## 14. Backup and restoration evidence

Local only:
- the SaaS.11 rehearsal (14/14);
- the SaaS.12 MySQL drill: backup, deletion, restore into a new schema, detection, containment, re-application, clean
  reconciliation (`evidence/SaaS-12-restore-reconciliation-drill.json`).

**Target restore: NOT VERIFIED.**

## 15. Disaster recovery results

**Not executed**: no isolated target environment exists and no recovery objectives are approved (O-6, O-7). No restore
was simulated as a substitute.

## 16. Offboarding rehearsal results

- The production-copy rehearsal was **not executed** (no approved copy).
- Its runbook now includes the register and the restore drill.
- The fictional-data MySQL drill exercised the full offboarding-and-deletion workflow and its reconciliation.

## 17. Complete test results

All runs are on this workstation and are reported separately. Skipped tests are not counted as passed.

| Suite | Commit | Result |
|---|---|---|
| **SQLite, CI-equivalent** (a copy prepared as CI does: `.env` from `.env.example`, key generated, whole-repo Pint, then the suite; in parallel here, serial in CI) | `fa42fac` | Pint passed. **1,467 passed, 0 failed**; 1,602 tests, 20,038 assertions, 22.9 min. **135 skipped**: exactly the MySQL-gated tests (next table) |
| First CI-equivalent run | `1907743` | 1,466 passed, **1 failed**: the architecture test found that the two new services were not on the documented tenant-scope bypass allow-list. Investigated and fixed (`fa42fac`); the repeat run above is clean |
| Isolation and authorisation group | SaaS.11 baseline | 189/189. The SaaS.12 additions (role top-up, reconciliation, seeders, configuration) are inside the full run |
| Targeted runs during the phase | various | Identity, platform, signup, configuration and tenancy 179/179; offboarding 59/59; reconciliation 7/7; production configuration 10/10; billing 81/81 after the style fix |

**Mutation testing** (private copies, one mutant at a time, against the tests that should catch it):

| Set | Result |
|---|---|
| SaaS.12 G1–G5: the statutory verified-rules gate | 5/5 killed. G5 needed a strengthened assertion |
| T1–T3 role top-up, H1–H2 seeders, R1–R5 register, C1–C10 reconciliation, V1–V3 validator | 23/23 killed. R4 and C2 needed a new test, and C2 a code refinement (`1907743`) |
| **Total** | **28/28 killed** (25 on the first pass; the 3 survivors were real gaps, fixed, then re-run) |

`STORAGE_PERMISSIONS` has no failing-case test: an unwritable storage directory cannot be produced safely in the suite.

## 18. MySQL and concurrency evidence

| Run | Result |
|---|---|
| **MySQL suite** (`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_saas6_concurrency php artisan test tests/MySql`, MySQL 8.4, clean tree) | `fa42fac`: **135/135 passed**, 821 assertions, 33.2 min. None skipped |
| Concurrency | 132 tests in 18 concurrency files, with real processes; among them the SaaS.10 signup races and the SaaS.9 offboarding races |
| Restore-reconciliation drill (MySQL, fictional data) | Backup (3.6 MB), deletion, restore into a new schema, detection, containment (exit 1), re-application, clean reconciliation (exit 0). Other tenants untouched (5 operating throughout) |
| Migration behaviour (disposable schemas) | Interrupted run not re-runnable; `--isolated` serialises concurrent runs; first install with the database cache store cannot use `--isolated`; instant column add verified |

## 19. Accessibility and browser results

| Run | Result |
|---|---|
| Signup flow (Chromium; disposable fixture on 8096 with registration on for that process only, local SMTP sink; the 8090 showcase off) | **34/34 checks**, 0 console errors |
| Accessibility (axe-core, WCAG 2.0 and 2.1 A and AA) | **12 states, 0 violations** |
| Visual regression (UX.18 suite) | **120/120 passed**; 198 skipped by the designed viewport and engine matrix (same as before); no baseline change |
| Browser suite | **74/74 passed**; 6 skipped by design (phone-only tests on the desktop project) |

Engines and devices are those of the suites (Chromium, WebKit, desktop and phone viewports). No other browser or device
was tested.

## 20. Performance results

| Measure | Result |
|---|---|
| Fresh install, 126 migrations (MySQL 8.4) | 74 s |
| Scale probe in the MySQL run | Query counts constant across 15 to 10,000 employees (Employee 360: 28, people analytics: 64, API page: 15). Times at 10,000: people analytics 134 ms, API page 125 ms. That is slower than the SaaS.11 run (119 and 53 ms) with identical query counts, on a loaded workstation: treat it as noise until it is repeated |
| Gate commands | Validator about 0.5 s; readiness about 0.6 s, including boot (SaaS.11) |
| Reconciliation | Reads one small file per completed deletion; not load-tested (deletion volume is low by nature) |

No production-volume performance test exists: no environment.

## 21. Staging release candidate

Draft only (`SaaS-12-Release-Candidate-Package.md`): no staging environment exists and no authorisation was given.

## 22. Production deployment evidence

**None. Not performed.** No authorisation (O-9) and no environment.

## 23. Post-release validation

Not applicable: nothing was deployed.

## 24. Monitoring and incident response readiness

- Health endpoints and runbooks exist.
- There is no alerting or error-reporting integration, no on-call and no approved monitoring window (O-10, O-11).

## 25. Outstanding blockers

| Party | Blocker |
|---|---|
| Owner | O-1 credential rotation; O-2 and O-3 repository; O-4 and O-5 engagements; O-6 objectives; O-7 environments; O-8 and O-9 authorisations; O-10 and O-11 operations; O-14 operator MFA; D8, D10 and B decisions for their scopes |
| External | Independent security review; statutory verification |
| Environment | Configuration, DR, rehearsal, staging, monitoring |

## 26. Remaining known limitations

- Statutory payroll blocked; gratuity not computed.
- Entitlements in shadow mode.
- SaaS.10 residual risks L1, L3–L9.
- Interrupted migrations need a restore.
- `peopleos.workflows.trigger_events` contains two duplicate keys with identical labels (no effect).

## 27. Owner approvals and references

**None received.** Every approval field in the registers is empty.

## 28. Exact deployed commit

None.

## 29. Rollback readiness

The procedure is documented and the latest migration's rollback was exercised on restored copies. It has not been
rehearsed in any deployment environment.

## 30. Final release state

**A. BLOCKED — OWNER OR EXTERNAL ACTION REQUIRED.**
