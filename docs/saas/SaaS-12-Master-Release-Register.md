# SaaS.12 — Master release register

**The durable record of the release programme.** Update it whenever a dependency arrives, and resume from the first task
that is not VERIFIED. Last verified: 10 October 2026, at code commit `1907743`.

## Status values

- NOT STARTED;
- IN PROGRESS;
- BLOCKED — OWNER DECISION;
- BLOCKED — EXTERNAL REVIEW;
- BLOCKED — ENVIRONMENT;
- BLOCKED — TECHNICAL DEFECT;
- READY FOR VERIFICATION;
- VERIFIED;
- NOT APPLICABLE — EVIDENCE REQUIRED.

## The register

| Stage | Task | Status | Evidence | Owner | Dependency | Next action | Acceptance criterion |
|---|---|---|---|---|---|---|---|
| 0 | Establish the truth | VERIFIED | `SaaS-12-Baseline.md` | Engineering | — | — | Baseline recorded |
| 1 | Local credential reuse (PeopleOS, MySQL, `.env`) | VERIFIED | yes/no checks: 0 accepted | Engineering | — | — | 0 |
| 1 | Credential reuse elsewhere; rotation | BLOCKED — OWNER DECISION | — | Owner | O-1 | Owner rotates and confirms | Written confirmation per system |
| 1 | Repository visibility, branch, history | BLOCKED — OWNER DECISION | Plan: `SaaS-11-Security-Findings.md` §2.3 | Owner | O-2, O-3 | Owner chooses; engineering executes only the authorised operation | 0 occurrences on public refs |
| 1 | Collaborators, deploy keys, Actions secrets | BLOCKED — OWNER DECISION | CI uses no secrets | Owner | GitHub access | Owner reviews settings | Review recorded |
| 1 | Secrets in logs, repository, error responses | VERIFIED | Scans: 0; error-response test | Engineering | — | — | — |
| 1 | Escalation fixes, role audit, isolation, field security | VERIFIED (internal) | Tests; mutants; isolation group | Engineering | Gate 3 for external | — | External review |
| 1 | Dependencies | VERIFIED | composer 0, npm 0 (after fix) | Engineering | — | Re-run at release time | 0 |
| 1 | Operator MFA | BLOCKED — ENVIRONMENT | 0 of 1 locally; readiness gate | Operators | O-14 | Enrol in the target | Readiness PASS |
| 2 | Consolidated owner decisions | BLOCKED — OWNER DECISION | `SaaS-12-Owner-Decision-Register.md` | Owner | — | Record decisions with references | Each decision referenced |
| 3 | Statutory engine and gate validated | VERIFIED | Mutants G1–G5 killed; gate tests | Engineering | — | — | — |
| 3 | 24 rules verified or not applicable | BLOCKED — EXTERNAL REVIEW | `SaaS-12-Statutory-Verification-Register.md` | Specialist | O-5 | Engage specialist | Second-operator verification with evidence |
| 4 | Deletion register and restore reconciliation | VERIFIED | `db55ba1`, `1907743`; SQLite faithful restore tests; MySQL drill; mutants R1–R5, C1–C10 | Engineering | — | — | Restore never silently resurrects |
| 4 | SaaS.9 controls (holds, maker-checker, cooling-off, financial protection, certificates, external copies) | VERIFIED (local) | Offboarding suite 59 + 7 tests | Engineering | — | — | — |
| 4 | Backup-expiry evidence | BLOCKED — ENVIRONMENT | — | Infrastructure | D8-5 | — | Expiry records from the backup system |
| 5 | Independent security review | BLOCKED — EXTERNAL REVIEW | Pack and checklist | Reviewer | O-4, O-7, O-8 | Engage | Signed; no open critical or high |
| 6 | Production infrastructure | BLOCKED — ENVIRONMENT | `SaaS-12-Production-Infrastructure-Requirements.md` | Owner, infrastructure | O-7 | Choose provider, region, mail, storage, secret store | Inventory written |
| 7 | Checker coverage | VERIFIED | Permissions and register-disk rules added and tested | Engineering | — | — | — |
| 7 | Production configuration in the target | BLOCKED — ENVIRONMENT | — | Infrastructure | Stage 6 | Run the validator in the target | Exit 0 |
| 8 | Seeders safe; deploy does not reset access | VERIFIED | `812f2c9`, `90ef326`; real-data check (790 pairs identical) | Engineering | — | — | — |
| 8 | Migration safety (fresh, rollback, interrupted, concurrent, locks) | READY FOR VERIFICATION | `SaaS-12-Deployment-and-Migration-Plan.md` | Owner review | — | Owner approves the plan | Approval reference |
| 9 | Real recovery exercise | BLOCKED — ENVIRONMENT | Plan; local evidence only | Infrastructure | O-6, O-7 | Run in the isolated target | Signed, RTO and RPO within approved objectives |
| 10 | Complete validation on the candidate | See final report | Final report §17–§20 | Engineering | — | Repeat in staging | All pass, skips explained |
| 11 | Public registration | BLOCKED — OWNER DECISION | Off; fails closed | Owner | D10, gate 3, staging | — | Separate approval |
| 12 | Billing | BLOCKED — OWNER DECISION | Off; production refuses Razorpay | Owner | B-4, B-7, B-8, B-9 | — | Separate approval and a release enabling live mode |
| 13 | Tenant deletion activation | BLOCKED — OWNER DECISION | Off; refused without authorisation and register disk | Owner | D8, G16, review | — | Separate approval |
| 14 | Staging release candidate | BLOCKED — ENVIRONMENT | Draft `SaaS-12-Release-Candidate-Package.md` | Engineering, owner | O-7, O-8 | Deploy to staging when authorised | Staging validation evidence |
| 15 | Final release gate | BLOCKED | `SaaS-12-Final-Release-Gate-Matrix.md` | Owner | All gates | — | Classification READY or APPROVED |
| 16 | Production deployment | NOT STARTED | — | Operations | O-9 | — | Deployment evidence |
| 17 | Post-release validation | NOT STARTED | — | Operations | Stage 16, O-10 | — | Checks pass during the window |
| 18 | Handover | NOT STARTED (index prepared) | RC package §9 | Engineering | Stage 17 | — | Handover package |
