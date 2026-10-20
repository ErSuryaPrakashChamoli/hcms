# Release-gate matrix (the single matrix; revalidated in SaaS.13)

> **Superseded on 11 October 2026 by `SaaS-Final-Release-Gate.md`**, the single go/no-go assessment (kept as history).

Date: 10 October 2026, at code commit `408f2ed`. Branch `feature/oct_1_phase_1`.

## Classification: **BLOCKED — RELEASE GATES OUTSTANDING**

Engineering work available without the owner, external parties or an environment is complete. Release still needs
external and owner action. Not deployed, not authorised.

**Allowed statuses:**
- VERIFIED;
- BLOCKED — OWNER DECISION;
- BLOCKED — EXTERNAL REVIEW;
- BLOCKED — ENVIRONMENT;
- BLOCKED — TECHNICAL DEFECT;
- NOT APPLICABLE — EVIDENCE REQUIRED.

Workstation evidence never counts as staging or production evidence. **No approval reference exists for any gate.**

## Gates

| # | Gate | Evidence | Owner | Date | Status | Remaining limitation | Approval ref |
|---|---|---|---|---|---|---|---|
| 1 | Credential exposure resolved | Locally: accepted nowhere (19 databases, MySQL, `.env`; re-checked SaaS.13). External: unchecked | Owner (O-1) | 10 Oct | BLOCKED — OWNER DECISION | External systems unknown | |
| 2 | Repository exposure addressed | Public; value in the tip of `feature/sep_28_phase_1` (re-scanned SaaS.13) | Owner (O-2, O-3) | 10 Oct | BLOCKED — OWNER DECISION | — | |
| 3 | Independent security review complete | Pack and checklist ready; not engaged | Owner → reviewer (O-4) | 10 Oct | BLOCKED — EXTERNAL REVIEW | No review environment | |
| 4 | Security-critical findings closed | Internally none open beyond gates 1–2; external findings unknown | Engineering, reviewer | 10 Oct | BLOCKED — EXTERNAL REVIEW | Depends on gate 3 | |
| 5 | Applicable statutory rules verified | 0 of 24; 5 notices; gate cannot be bypassed | Owner → specialist (O-5) | 10 Oct | BLOCKED — EXTERNAL REVIEW | — | |
| 6 | Gratuity status explicitly assessed | **Assessed (SaaS.13):** not calculated, no partial implementation; launch need undecided | Owner, specialist (O-12) | 10 Oct | BLOCKED — OWNER DECISION | If required: an approved specification first | |
| 7 | Operator MFA verified | Enforcement verified; enrolment 0 of 1 locally; no production operators | Operators (O-14) | 10 Oct | BLOCKED — ENVIRONMENT | — | |
| 8 | Production configuration validated | Checker extended (`.env`, storage, register disk, demo accounts); no target | Infrastructure (O-7) | 10 Oct | BLOCKED — ENVIRONMENT | — | |
| 9 | Database migration plan approved | Plan; fresh install, rollback, interruption and concurrency tested (SaaS.12) | Owner | 10 Oct | BLOCKED — OWNER DECISION | Interrupted migration needs a restore | |
| 10 | Backup restore verified | Local only | Infrastructure | 10 Oct | BLOCKED — ENVIRONMENT | — | |
| 11 | Disaster recovery verified | Plan only | Infrastructure | 10 Oct | BLOCKED — ENVIRONMENT | — | |
| 12 | RPO/RTO approved | Options prepared (action pack §4) | Owner (O-6) | 10 Oct | BLOCKED — OWNER DECISION | — | |
| 13 | Offboarding rehearsal complete | Runbook; fictional MySQL drill (SaaS.12) | Infrastructure, owner | 10 Oct | BLOCKED — ENVIRONMENT | — | |
| 14 | D8 resolved (deletion activation only) | 0 policies | Owner, counsel | 10 Oct | BLOCKED — OWNER DECISION | Deletion off | |
| 15 | D10 resolved (signup activation only) | All open | Owner | 10 Oct | BLOCKED — OWNER DECISION | Signup off | |
| 16 | Billing resolved (billing activation only) | B-4, B-7, B-8, B-9 open | Owner, finance, tax | 10 Oct | BLOCKED — OWNER DECISION | Payments off | |
| 17 | Monitoring and on-call approved | None | Owner (O-10, O-11) | 10 Oct | BLOCKED — OWNER DECISION | — | |
| 18 | Incident response documented | Runbooks exist; contacts and escalation missing | Owner | 10 Oct | BLOCKED — OWNER DECISION | — | |
| 19 | Rollback verified | Documented; not rehearsed in any environment | Operations | 10 Oct | BLOCKED — ENVIRONMENT | — | |
| 20 | Release candidate validated | Workstation suites (SaaS.13 report §16); no staging | Engineering, owner | 10 Oct | BLOCKED — ENVIRONMENT | Workstation only | |
| 21 | External approvals recorded | None | Owner, reviewers | 10 Oct | BLOCKED — EXTERNAL REVIEW | — | |
| 22 | Production deployment authorised | None | Owner (O-9) | 10 Oct | BLOCKED — OWNER DECISION | — | |

## Capability states (unchanged)

| Capability | State |
|---|---|
| Public signup | **disabled** |
| Tenant deletion | **disabled** |
| Live payments | **disabled** |

Production refuses each without its recorded authorisation.
