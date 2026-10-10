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
| Final code | `1907743` |
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
- the documents.

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

PENDING: filled in from the final runs on `1907743`.

## 18. MySQL and concurrency evidence

PENDING.

## 19. Accessibility and browser results

PENDING.

## 20. Performance results

PENDING.

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
