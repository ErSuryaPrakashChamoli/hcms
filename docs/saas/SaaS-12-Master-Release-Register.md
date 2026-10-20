# Master release register (SaaS.12, maintained through SaaS.13)

**The single durable record of the release programme.** Update it whenever a dependency arrives, and resume from the
first row that is not VERIFIED.

Last revalidated: **13 October 2026**, at commit `7314f7d` (no code change since `064c262`; no blocker changed; log in
`SaaS-Final-Release-Gate.md`). Every row was re-checked, not carried
forward. The final documents are `SaaS-Final-*.md`; the go/no-go assessment is `SaaS-Final-Release-Gate.md`.

**Status values:**
- NOT STARTED;
- IN PROGRESS;
- BLOCKED — OWNER DECISION;
- BLOCKED — EXTERNAL REVIEW;
- BLOCKED — ENVIRONMENT;
- BLOCKED — TECHNICAL DEFECT;
- READY FOR VERIFICATION;
- VERIFIED;
- NOT APPLICABLE — EVIDENCE REQUIRED.

The "Can work continue?" column says whether independent engineering can proceed while the row is blocked.

## Security and repository

| Task | Status | Exact blocker | Required action | Responsible | Current evidence | Evidence required (acceptance) | Dependency | Can work continue? | Next action |
|---|---|---|---|---|---|---|---|---|---|
| Local credential reuse | VERIFIED | — | — | Engineering | SaaS.13 yes/no: 0 of 19 PeopleOS databases, 0 MySQL accounts, 0 `.env` values accept the value | — | — | Yes | Re-check after any restore or new database |
| External credential reuse and rotation | BLOCKED — OWNER DECISION | Engineering has no authorised access to GitHub, e-mail, hosting or other applications | Rotate everywhere it was used; end sessions; revoke tokens; check security logs since 28 Sep | Owner | — | Written confirmation per system | O-1 | Yes | Owner follows `SaaS-13-Owner-Action-Pack.md` §1 |
| Public branch still holds the value | BLOCKED — OWNER DECISION | `feature/sep_28_phase_1` tip `9bf682f` holds it in 2 files (SaaS.13 scan) | Authorise option A, B or C | Owner | Remote unchanged since 8 Oct | 0 occurrences on every public ref | O-2, O-3 | Yes | Engineering runs only the authorised option, then re-scans |
| Repository visibility | BLOCKED — OWNER DECISION | Public (API, 10 Oct) | Decide | Owner | — | Setting matches the decision | O-2 | Yes | — |
| Collaborators, deploy keys, Actions secrets | BLOCKED — OWNER DECISION | Not observable without GitHub access | Review in GitHub settings | Owner | CI uses no secrets | Review recorded | GitHub access | Yes | — |
| Development `.env` exposure | VERIFIED | — | — | Engineering | **SaaS.13:** 664 → 600; app verified; never committed; exposure assessed (action pack §7) | — | — | Yes | Owner may rotate development secrets if others had machine access |
| Secrets in logs, repository, errors | VERIFIED | — | — | Engineering | SaaS.12 scans 0; error-response test | — | — | Yes | Re-scan at release |
| Escalation fixes, role audit, isolation, field security | VERIFIED (internal) | External confirmation pending | — | Engineering | Tests, mutants; SaaS.13 full runs | Independent review | Review | Yes | — |
| Dependencies | VERIFIED | — | — | Engineering | composer 0, npm 0 (SaaS.12) | 0 at release time | — | Yes | Re-run at the release-candidate freeze |

## Identity and operators

| Task | Status | Exact blocker | Required action | Responsible | Current evidence | Evidence required | Dependency | Can work continue? | Next action |
|---|---|---|---|---|---|---|---|---|---|
| MFA enforcement on every sign-in path | VERIFIED | — | — | Engineering | Per-request check; remember-me and SSO challenged; downloads covered; recovery codes single-use and audited; resets audited; readiness gate | — | — | Yes | — |
| Operator MFA enrolment | BLOCKED — ENVIRONMENT | 0 of 1 enrolled (development, showcase); no production operators exist | Each operator enrols | Operators, owner | SaaS.13 counts | Readiness "Every platform operator has MFA enrolled" PASS in the target | O-14, O-7 | Yes | Action pack §3 |
| Demo or test accounts in production | VERIFIED (gate) | — | — | Engineering | **SaaS.13:** readiness FAILs in production on reserved-domain accounts (9 on development: WARN) | PASS in the target | — | Yes | — |
| Deploy-time permission sync | VERIFIED | — | — | Engineering | SaaS.13 real-data re-check on `hcm`: 790 pairs identical, 0 audit events | — | — | Yes | — |

## Statutory

| Task | Status | Exact blocker | Required action | Responsible | Current evidence | Evidence required | Dependency | Can work continue? | Next action |
|---|---|---|---|---|---|---|---|---|---|
| Verified-rules gate cannot be bypassed | VERIFIED | — | — | Engineering | Mutants G1–G5 killed (SaaS.12); gate tests in the SaaS.13 run | — | — | Yes | — |
| 24 rules verified or not applicable | BLOCKED — EXTERNAL REVIEW | No specialist engaged; 0 of 24 verified, 5 open notices | Engage a specialist | Owner → specialist | `SaaS-12-Statutory-Verification-Register.md` | Second-operator verification with official evidence per rule | O-5 | Yes | — |
| Gratuity | BLOCKED — OWNER DECISION | Not calculated; whether launch needs it is undecided | Decide scope with the specialist | Owner, specialist | **SaaS.13:** discovery confirms no implementation (stored rule only; manual settlement lines only) | Decision; if required, an approved specification before any code | O-12, O-5 | Yes | Action pack §5 |
| Payroll in jurisdictions without rules | VERIFIED (control) | — | — | Engineering | **11 Oct:** payroll in a jurisdiction with no statutory rule (USA, UK, EU) is blocked in production (`064c262`; tested; mutants J1–J3) | — | — | Yes | — |
| Payroll scope for the USA, UK and EU (O-18) | BLOCKED — OWNER DECISION | No rules exist; nothing may be invented | Exclude from launch, or commission specialist rules | Owner, specialist | `SaaS-Final-Statutory-Verification-Register.md` §1 | Decision per market | O-18, O-5 | Yes | — |

## Retention, deletion and recovery

| Task | Status | Exact blocker | Required action | Responsible | Current evidence | Evidence required | Dependency | Can work continue? | Next action |
|---|---|---|---|---|---|---|---|---|---|
| Restore reconciliation | VERIFIED | — | — | Engineering | SaaS.12 build, drill, mutants; **SaaS.13:** an unreachable register fails closed (tested) | — | — | Yes | — |
| SaaS.9 controls | VERIFIED (local) | — | — | Engineering | Offboarding suite in the SaaS.13 run | — | — | Yes | — |
| D8-1…D8-8 | BLOCKED — OWNER DECISION | Undecided | Decide with counsel | Owner, counsel | 0 policies | Approved policy versions (two operators) | — | Yes | — |
| Backup-expiry evidence | BLOCKED — ENVIRONMENT | No backup system | — | Infrastructure | — | Expiry records | D8-5, O-7 | Yes | — |
| Recovery objectives | BLOCKED — OWNER DECISION | RPO and RTO not approved | Choose | Owner | Options: action pack §4 | Written choice | O-6 | Yes | — |
| Real recovery exercise | BLOCKED — ENVIRONMENT | No isolated target | Provide one | Infrastructure | Local evidence only (SaaS.11, SaaS.12) | Signed exercise within approved objectives | O-6, O-7 | Yes | — |
| Production-copy offboarding rehearsal | BLOCKED — ENVIRONMENT | No approved copy | — | Infrastructure, owner | Runbook ready | Signed rehearsal | O-7 | Yes | — |

## Environment, deployment and release

| Task | Status | Exact blocker | Required action | Responsible | Current evidence | Evidence required | Dependency | Can work continue? | Next action |
|---|---|---|---|---|---|---|---|---|---|
| Independent security review | BLOCKED — EXTERNAL REVIEW | Not engaged; no review environment | Engage | Owner → reviewer | Pack and checklist | Signed; no open critical or high | O-4, O-7, O-8 | Yes | — |
| Infrastructure | BLOCKED — OWNER DECISION | No provider chosen; nothing provisioned; no `gh` or `cloud` access | Choose and delegate access | Owner | Requirements written | Environment inventory | O-7 | Yes | Action pack §6 |
| Production configuration | BLOCKED — ENVIRONMENT | No target | — | Infrastructure | Checker extended (SaaS.11, SaaS.12, SaaS.13) | Validator exit 0, readiness without FAIL, in the target | O-7 | Yes | — |
| Monitoring, on-call, incident contacts | BLOCKED — OWNER DECISION | No provider, no names, no window | Decide | Owner | — | Alert received by on-call | O-10, O-11 | Yes | — |
| Migration plan | READY FOR VERIFICATION | Owner approval | Review and approve | Owner | `SaaS-12-Deployment-and-Migration-Plan.md` | Approval reference | — | Yes | — |
| Complete validation (workstation) | VERIFIED (workstation) | — | — | Engineering | Final programme on `064c262`: see `SaaS-Final-Master-Completion-Report.md` §6 (signup flow re-run 35/35, 0 axe violations) | All pass, skips explained | — | Yes | Repeat in staging |
| Staging release candidate | BLOCKED — ENVIRONMENT | No staging; no authorisation | — | Engineering, owner | Draft release-candidate package | Staging validation evidence | O-7, O-8 | Yes | — |
| Public registration, billing, deletion activation | BLOCKED — OWNER DECISION | D10, B and D8 decisions; review; staging | — | Owner | All off; production refuses each without authorisation | Separate approvals | — | Yes | — |
| Production deployment, post-release, handover | NOT STARTED | No authorisation (O-9), no environment | — | Owner, operations | — | Deployment and monitoring evidence | All gates | — | — |
