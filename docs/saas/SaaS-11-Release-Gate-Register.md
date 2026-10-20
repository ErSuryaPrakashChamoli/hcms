# SaaS.11 — Release-gate register

Date: 10 October 2026, at `62f2603` on `feature/oct_1_phase_1`.

**Release decision: BLOCKED.** Critical external gates are open: the credential exposure, statutory verification,
recovery in a target environment, the independent security review and owner decisions. Feature completion and
passing tests do not change this.

A gate is **PASS** only with the evidence named in it. A plan, a local rehearsal or an internal review never passes
an external gate.

## 1. Release scopes

Each capability is released separately; a later scope needs every gate of the earlier ones.

| Scope | What it releases | Gates | State |
|---|---|---|---|
| A. Production service | Operator-provisioned tenants on a production environment | G1–G9 | **BLOCKED** |
| B. Public registration | Self-service signup | A + G10–G11 | **BLOCKED**; switch OFF |
| C. Tenant deletion | Irreversible deletion after offboarding | A + G12–G14 | **BLOCKED**; switch OFF |
| D. Paid billing | Live payments and real invoices | A + G15 | **BLOCKED**; Razorpay OFF |

## 2. Gates

| Gate | Requirement | Evidence required to pass | Evidence today | Owner | Status |
|---|---|---|---|---|---|
| G1 | **Credential exposure closed** | Owner's confirmation that the leaked value is rotated everywhere it was used. A decision on repository visibility. The value removed from `feature/sep_28_phase_1`'s tree; history cleaned or the decision not to clean it recorded | Inactive in every PeopleOS database here; repository public; value in a public branch's tree and in history | Owner | **BLOCKED** |
| G2 | **Independent security review** | Signed checklist (`SaaS-11-External-Security-Review-Checklist.md` §6); no open critical or high finding | Not started; internal reviews only | Owner → external reviewer | **BLOCKED** |
| G3 | **Statutory verification** | Each of the 24 rules verified by a second operator against official sources, with a specialist's sign-off; 0 open notices | 0 of 24 verified; 5 open notices. Package ready (`SaaS-11-Statutory-Verification-Evidence-Package.md`) | Owner → statutory specialist | **BLOCKED** |
| G4 | **Production environment exists and is configured** | `peopleos:config:validate --as-production` exits 0 in the target; `peopleos:readiness` has no FAIL | No environment; 11 errors on the development `.env` (expected) | Infrastructure | **BLOCKED** |
| G5 | **Backup restore and DR verified in the target** | The DR report §4 plan executed and signed; measured RTO and RPO within targets | Local rehearsal only (14 of 14, mechanics) | Infrastructure | **BLOCKED** |
| G6 | **Operations running** | Supervised worker; scheduler on one server with a fresh heartbeat; monitoring of `/health/ready`, failed jobs and audit verification, with alerting to someone on call | None running for PeopleOS | Infrastructure | **BLOCKED** |
| G7 | **Operators' MFA enrolled** | Readiness check "Every platform operator has MFA enrolled" PASS in the target | Gate exists (`62f2603`); 0 of 1 enrolled locally | Operators | **BLOCKED** |
| G8 | **Mail delivery** | Real transport with SPF, DKIM and DMARC; a delivery test to a monitored mailbox; bounce handling | `log` mailer here | Infrastructure | **BLOCKED** |
| G9 | **Engineering validation of the release candidate** | Full SQLite and MySQL suites pass; mutation, browser, accessibility and visual results recorded; skipped tests reported separately | See the final report §5 | Engineering | **PASS for this phase's scope** (not a release decision) |
| G10 | **Owner decisions for registration** | D10-1 to D10-11 decided and recorded (§3) | All 11 open | Owner | **BLOCKED** |
| G11 | **Registration-specific evidence** | Legal-approved terms and privacy notice published and approved (D10-4); offers approved with prices (D10-2, B-4); `PEOPLEOS_PUBLIC_SIGNUP_AUTHORISATION` set to the D10-1 decision reference | None | Owner, legal | **BLOCKED** |
| G12 | **Retention decisions** | D8-1 to D8-8 decided with counsel; policies proposed and approved by two operators | 0 policies; all D8 open | Owner, counsel | **BLOCKED** |
| G13 | **Deletion recoverability** | Out-of-database deletion register and the reconciliation after restore (S11-5); backup ageing evidence (D8-5) | Procedure written; neither built | Engineering, infrastructure | **BLOCKED** |
| G14 | **Production-copy offboarding rehearsal** | `production-copy-rehearsal.md` executed on an approved copy and signed; then `PEOPLEOS_TENANT_DELETION_AUTHORISATION` set | Not executed | Infrastructure, owner | **BLOCKED** |
| G15 | **Commercial inputs** | B-4 prices, B-7 selling entity and registrations, B-8 SAC, B-9 registrations abroad; live provider credentials in the secret store; a payment rehearsal | None entered (no value invented) | Owner, finance, tax | **BLOCKED** |

## 3. Owner decision register: D10-1 to D10-11

These are the owner's decisions. This phase does **not** decide them. "If left open" describes what the software
does today. Every default is safe, but each has a cost.

| ID | Decision | Evidence needed before deciding | If left open | Blocks |
|---|---|---|---|---|
| D10-1 | Authorise public registration, and in which environment | G2 signed with no open critical or high finding; D10-2, D10-3 and D10-4 decided; G4, G8 passed | Registration stays OFF: the signup pages show a notice and refuse every action. Operator provisioning is unaffected | Scope B |
| D10-2 | Offers: which plans, trial length (D-4), which countries | Published plan versions with entitlements; prices on sale in each country's market (B-4) | Nothing is offered. Signup refuses with a stated reason | Scope B |
| D10-3 | Review policy: every application (`all`) or flagged only (D-9) | Proven money path; operator capacity; tolerance for fraudulent trials | `all`: an operator reviews every application. Safe, but it needs staffed review | Nothing (the default is safe) |
| D10-4 | Terms of Service and Privacy Notice | Legal-approved texts at stable https addresses; versions approved on Commercial policies | Signup refuses (no terms to accept) | Scope B |
| D10-5 | One login per address (D-17), kept | Confirmation that a person with an existing account may not register a second organisation with the same address | Current behaviour continues: such an address receives an e-mail, never a second account | Nothing |
| D10-6 | Retention of unconverted applications | Counsel's view on purpose and period (DPDP Act and others) | Kept indefinitely with the contact's name, address and acceptance IP. Privacy exposure grows with time; no period is invented | Scope B (recommended before opening) |
| D10-7 | The Account Owner permission set (35 explicit keys); whether operator-provisioned tenants should also start with it | Product and security review of the set (G2 can inform it) | The current set stands; operator-provisioned tenants keep their first-administrator role choice | Nothing (the current set is least-privilege by design) |
| D10-8 | MFA for account owners from the first sign-in | Security recommendation (internal: require it); UX acceptance | Owner MFA follows the tenant's policy, off by default: a phished owner password is enough to sign in (P2) | Scope B (recommended) |
| D10-9 | CAPTCHA or bot-protection provider | Provider choice; privacy assessment of the third party; accessibility of the challenge | Per-IP and per-address limits, a honeypot and review only. A distributed bot can create unconfirmed applications, though never tenants | Nothing (risk P3) |
| D10-10 | What happens to a tenant whose owner never activates | Policy: a period and an action (suspend, offboard), consistent with D-8 | The tenant stays provisioned indefinitely, holding the owner's contact data | Nothing (cost and privacy over time) |
| D10-11 | Data region at signup (D-11) | The hosting decision (D8-7: India-hosting of books under r.46(8)); infrastructure per region | One region; applicants get no choice. Possible conflict with residency expectations outside India | Scope B outside India |

## 4. Other open owner, legal and specialist decisions

| ID | Decision | Register |
|---|---|---|
| D8-1 … D8-8 | Retention and offboarding | `SaaS-9-Retention-Decision-Register.md` §3 |
| D-15 | Two-operator rule (kept) or a dedicated approver role | SaaS.9 closure §9 |
| D-16 | Entitlement enforcement (shadow mode today) | Decision register |
| B-4, B-7, B-8, B-9 | Commercial and tax inputs | `SaaS-7-Final-Configuration-Gate-Report.md` |
| F15 | Drop the obsolete replay databases | SaaS.9 closure §19 |

## 5. Release switches (all OFF)

| Switch | State here | Production rule |
|---|---|---|
| `PEOPLEOS_PUBLIC_SIGNUP_ENABLED` | unset (false) in `.env` and the 8090 server | refused without `PEOPLEOS_PUBLIC_SIGNUP_AUTHORISATION` |
| `PEOPLEOS_TENANT_DELETION_ENABLED` | unset (false) | refused without `PEOPLEOS_TENANT_DELETION_AUTHORISATION` |
| `PEOPLEOS_RAZORPAY_ENABLED` | unset (false) | refused while on; live keys refused |
| Statutory enforcement | always on in production | not switchable in production |
