# SaaS.12 — Final release-gate matrix

Date: 10 October 2026. Branch `feature/oct_1_phase_1`.

## Classification: **BLOCKED**

Not "ready for production approval": credential exposure, the independent review, statutory verification, every
environment gate and the owner's approvals are outstanding. The other states (approved, deployed, verified) cannot apply:
nothing is approved and nothing is deployed.

## Status values

- **VERIFIED** means the evidence named exists. For gates about the software, the evidence is from this workstation.
- **BLOCKED** names its dependency.
- No approval reference exists for any gate. Each "Approval" cell stays empty until the owner or reviewer supplies one.

## The 25 gates

| # | Gate | Requirement | Evidence | Responsible | Verified | Status | Remaining limitation | Approval |
|---|---|---|---|---|---|---|---|---|
| 1 | Credential exposure closed | Rotated everywhere it was used | PeopleOS here: refused in 19 databases, 3 MySQL accounts and `.env` (yes/no) | Owner (O-1) | 10 Oct | **BLOCKED — OWNER DECISION** | Use elsewhere unknown | |
| 2 | Repository exposure addressed | Visibility decided; value gone from current files; history decision | Repository public; value in the tip of `feature/sep_28_phase_1` | Owner (O-2, O-3) | 10 Oct | **BLOCKED — OWNER DECISION** | — | |
| 3 | Independent security review complete | Signed checklist | Pack ready (`SaaS-12-Independent-Security-Review-Pack.md`) | Owner → reviewer (O-4) | — | **BLOCKED — EXTERNAL REVIEW** | No review environment | |
| 4 | Security-critical findings resolved | No open critical or high | Internal: none open except F1 (gates 1–2); external: none yet | Engineering, reviewer | 10 Oct | **BLOCKED — EXTERNAL REVIEW** | Depends on gate 3 | |
| 5 | Statutory rules verified as applicable | Verified, or not applicable with approval | 0 of 24 verified, 5 notices; gate cannot be bypassed (mutants G1–G5) | Owner → specialist (O-5) | 10 Oct | **BLOCKED — EXTERNAL REVIEW** | Gratuity not computed | |
| 6 | Legal and privacy review complete | Terms, privacy notice, DPDP review, hosting (D8-7) | None | Owner, counsel | — | **BLOCKED — OWNER DECISION** | — | |
| 7 | D8 retention approved | D8-1…D8-8 decided; policies approved by two operators | 0 policies | Owner, counsel | 10 Oct | **BLOCKED — OWNER DECISION** (needed for deletion only) | — | |
| 8 | D10 signup decisions approved | D10-1…D10-11 | All open; registration off | Owner | 10 Oct | **BLOCKED — OWNER DECISION** (needed for registration only) | — | |
| 9 | Billing decisions approved (if billing launches) | B-4, B-7, B-8, B-9 | All open; payments off | Owner, finance, tax | 10 Oct | **BLOCKED — OWNER DECISION** (needed for billing only) | — | |
| 10 | Operator MFA verified | All operators enrolled (readiness) | 0 of 1 locally; readiness gate exists | Operators (O-14) | 10 Oct | **BLOCKED — ENVIRONMENT** | — | |
| 11 | Production configuration validated | Validator exit 0 and readiness without FAIL **in the target** | Validator extended (permissions, register disk); 12 errors on the development `.env` (expected) | Infrastructure | 10 Oct | **BLOCKED — ENVIRONMENT** | — | |
| 12 | Database migrations approved | Plan reviewed; fresh install and rollback tested | 126 migrations from empty in 74 s; integrity (SaaS.11); interrupted and concurrent behaviour tested; instant column add verified | Engineering → owner | 10 Oct | **READY FOR VERIFICATION** (owner review) | Interrupted migration needs a restore | |
| 13 | Backup restoration verified | Restore in the target, signed | Local rehearsals only (SaaS.11 14/14; SaaS.12 MySQL drill) | Infrastructure (O-7) | 10 Oct | **BLOCKED — ENVIRONMENT** | — | |
| 14 | Disaster recovery verified | Plan executed, RTO and RPO measured | Plan written (`SaaS-11-Backup-and-DR-Test-Report.md` §4) | Infrastructure | — | **BLOCKED — ENVIRONMENT** | — | |
| 15 | Recovery objectives approved | Owner approves RPO and RTO | Engineering targets only | Owner (O-6) | 10 Oct | **BLOCKED — OWNER DECISION** | — | |
| 16 | Offboarding rehearsal completed | Production-copy rehearsal, signed | Runbook extended with the reconciliation drill; MySQL drill on fictional data passed | Infrastructure, owner | 10 Oct | **BLOCKED — ENVIRONMENT** | — | |
| 17 | Signup validated in staging | Staging run with decisions in place | Local browser flow (SaaS.11: 34/34; re-run in the final report) | Engineering, owner | — | **BLOCKED — ENVIRONMENT** (and gate 8) | — | |
| 18 | Billing validated in test mode | Payment rehearsal in staging | SaaS.7 test-mode suite only | Engineering | — | **BLOCKED — ENVIRONMENT** (and gate 9) | — | |
| 19 | Tenant deletion remains safely controlled | Off; refused without authorisation and register disk; reconciliation in place | Switch unset; validator rules tested; reconciliation tested (SQLite, MySQL drill, mutants) | Engineering | 10 Oct | **VERIFIED** | Activation is a separate gate | |
| 20 | Regression tests pass | SQLite, MySQL, browser, visual and mutation on the candidate | Final report §17–§19 | Engineering | 10 Oct | See the final report | Workstation evidence only | |
| 21 | Monitoring and alerting operational | Health wired; alerts reach on-call | No provider (O-11) | Owner, infrastructure | — | **BLOCKED — OWNER DECISION** | — | |
| 22 | Incident response procedure documented | Runbooks plus contacts and escalation | Runbooks exist; contacts and on-call missing (O-10) | Owner | 10 Oct | **BLOCKED — OWNER DECISION** | — | |
| 23 | Rollback procedure verified | Rehearsed in staging | Documented; latest-migration rollback exercised on restored copies | Operations | — | **BLOCKED — ENVIRONMENT** | — | |
| 24 | Release candidate approved | Frozen after staging; owner approval | Draft package | Owner | — | **BLOCKED — OWNER DECISION** | — | |
| 25 | Production deployment authorisation | Recorded, naming the commit | None | Owner (O-9) | — | **BLOCKED — OWNER DECISION** | — | |

## What changes the classification

**READY FOR PRODUCTION APPROVAL** needs:
- gates 1–5, 10–16 and 20–23 satisfied;
- gate 6 for anything customer-facing;
- gates 7–9 only for the scopes being launched.

**APPROVED FOR PRODUCTION DEPLOYMENT** additionally needs gates 24 and 25.
