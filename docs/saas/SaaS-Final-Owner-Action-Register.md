# Final owner action register

Date: 11 October 2026; **re-verified 13 October 2026**. **For:** the owner of Markedge PeopleOS.

**This is the authoritative register of decisions, access and approvals still needed.** It supersedes
`SaaS-12-Owner-Decision-Register.md`; the IDs are unchanged and that file is kept as history. Step-by-step instructions
stay in `SaaS-13-Owner-Action-Pack.md` (referenced by § below).

**Received so far: none** (re-checked 13 October 2026). No decision, approval, credential confirmation, external review,
specialist engagement or infrastructure access has been recorded since SaaS.10. Nothing here is decided by being written down.

## Classes

| Class | Meaning |
|---|---|
| OWNER DECISION | Engineering cannot choose it: security, legal, money, retention, production access or deletion |
| OWNER CREDENTIAL/ACCESS | Engineering needs access it does not have (GitHub, hosting, provider accounts) |
| INDEPENDENT REVIEW | An independent security reviewer |
| STATUTORY SPECIALIST | A qualified payroll and tax specialist |
| REAL INFRASTRUCTURE | An environment that does not exist yet |
| DEPLOYMENT AUTHORISATION | Explicit, written, naming the target and the commit |

## Register

| ID | Need | Class | Options and implications | Exact choice or evidence needed | Blocks |
|---|---|---|---|---|---|
| O-1 | Rotate the leaked password everywhere it was used | OWNER CREDENTIAL/ACCESS | None: until rotated, any system that accepts it is exposed | Written confirmation per system: password changed, sessions ended, tokens reviewed, 2FA, any unusual sign-in since 28 Sep (§1) | Release |
| O-2 | Repository visibility | OWNER DECISION | Private stops new viewers, not existing copies | "Private" or "public", dated | Release |
| O-3 | Exposed branch and history | OWNER DECISION (authorises a remote change) | A: delete `feature/sep_28_phase_1`. B: a removal commit. C: rewrite history (force-push, PR #1 recreated, clones re-cloned, GitHub purge) (§2) | "Authorised: option A/B/C on <date>" | Release |
| O-4 | Independent security review | INDEPENDENT REVIEW | Without it, gate 4 cannot pass | Engagement, scope, review environment; then the signed checklist | Release |
| O-5 | Statutory specialist | STATUTORY SPECIALIST | Payroll stays blocked by the gate until rules are verified | Engagement; per-rule sign-off (`SaaS-Final-Statutory-Verification-Register.md`) | Payroll at launch |
| O-6 | RPO, RTO and backup policy | OWNER DECISION | Options and trade-offs in §4; nothing is chosen | Written choice per row | Release |
| O-7 | Infrastructure: provider, region, mail, storage, secret store | OWNER DECISION + OWNER CREDENTIAL/ACCESS | The repository suggests Laravel Cloud (a skill exists); that is not a decision. Costs from the provider at decision time (§6) | Choice and delegated, least-privilege access | Release |
| O-8 | Authorise staging deployment | DEPLOYMENT AUTHORISATION | — | Written, naming the environment and commit | Staging |
| O-9 | Authorise production deployment | DEPLOYMENT AUTHORISATION | Last, after every gate | Written, naming the release candidate | Release |
| O-10 | On-call names and post-release monitoring window | OWNER DECISION | No window is invented | Names, contacts, escalation, window length | Release |
| O-11 | Monitoring, alerting and error-reporting provider | OWNER DECISION | The application has logs and health endpoints only | Provider choice | Release |
| O-12 | Gratuity in the launch scope | OWNER DECISION + STATUTORY SPECIALIST | Not implemented. If required: specification approved first, then implementation and specialist verification (§5) | "Required" or "excluded", with the specialist's view | India payroll |
| O-14 | Operator MFA enrolment | OWNER DECISION (operators act) | Enforcement exists; 0 operators enrolled (§3) | Readiness "Every platform operator has MFA enrolled" PASS in the target | Release |
| O-15 | Disposable local test databases | OWNER DECISION | Keep or drop (local only) | Say which | Nothing |
| O-16 | D-15: two-operator rule or a dedicated approver role | OWNER DECISION | Default works | Choice | Nothing |
| O-17 | D-16: entitlement enforcement (shadow today) | OWNER DECISION | Limits shown, not enforced | Choice | Paid plans |
| **O-18** (new) | **Payroll in the USA, UK and EU at launch** | OWNER DECISION + STATUTORY SPECIALIST | PeopleOS has no statutory rules there, and payroll is now blocked (`064c262`). (a) Exclude payroll for these markets from launch; the platform's other modules remain usable. (b) Commission specialist-defined rules per country (substantial work, never invented) | "(a)" or "(b)" per market | Payroll in those markets |
| D8-1…D8-8 | Retention and deletion policy | OWNER DECISION (with counsel) | See `SaaS-9-Retention-Decision-Register.md` §3 | Approved policy versions | Deletion activation |
| D10-1…D10-11 | Public registration | OWNER DECISION | See `SaaS-12-Owner-Decision-Register.md` Group B (Terms and Privacy are both D10-4) | Recorded decisions | Signup activation |
| B-4, B-7, B-8, B-9 | Prices, selling entity and registrations, India SAC, foreign registrations | OWNER DECISION (finance, tax) | Per market (India, USA, UK, EU, UAE); entered as governed data, never code | Approved values | Billing activation |

## Status at 13 October 2026

Every item above is **open**. Re-verified today:
- The O-1 exposure persists (the value is still in the public `feature/sep_28_phase_1`; accepted nowhere locally).
- O-2: still public.
- O-14: 0 of 1 operators enrolled.
- O-5, O-12, O-18: 0 of 24 rules verified; USA, UK and EU payroll blocked.

| Responsible | Items waiting on them |
|---|---|
| Owner | O-1, O-2, O-3, O-6, O-7, O-10, O-11, O-12, O-14, O-15, O-16, O-17, O-18; D8, D10, B for their scopes; O-8, O-9 |
| Independent security reviewer (once engaged) | O-4 |
| Statutory specialist (once engaged) | O-5; advice on O-12 and O-18 |
| Infrastructure provider (once chosen) | Everything environment-dependent |

## The single most effective next action

**O-1: rotate the leaked password everywhere it was used, then confirm.** It is the only open item that is an active
security exposure today. Every other gate is a missing approval or environment.
