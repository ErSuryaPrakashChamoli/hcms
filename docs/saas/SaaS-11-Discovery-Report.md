# SaaS.11 — Discovery report: production assurance, security review and release gate

Date: 10 October 2026. Branch `feature/oct_1_phase_1`, HEAD `e9914b8` at the start (SaaS.10's ending commit,
confirmed), clean working tree, 59 commits ahead of `origin/feature/oct_1_phase_1`, nothing pushed.

This phase builds no feature. It verifies the inherited blockers against current evidence, reviews security, exercises
recovery where it can be exercised safely, assesses production configuration, re-checks SaaS.10, and records what only
the owner, a statutory specialist or an independent reviewer can decide.

## 1. Actual state found

| Area | Finding (evidence gathered on 10 October 2026) |
|---|---|
| Repository | `github.com/ErSuryaPrakashChamoli/hcms` is **public**: the unauthenticated API reports `"visibility": "public"`, 0 forks. Remote branches: `main` (`94c439f`), `feature/sep_28_phase_1` (`9bf682f`), `feature/oct_1_phase_1` (`36a9292`; the local branch is 59 commits ahead) |
| Leaked credential | Introduced in `d75226e`, removed in `8f54d4d`. **New:** the tip of the public branch `feature/sep_28_phase_1` still contains it in two files (`database/seeders/DatabaseSeeder.php`, `docs/architecture/phase-1-foundation.md`), visible without reading history. No local PeopleOS database accepts it (5 checked, yes/no only) |
| History secret scan | 284 commits on all refs: no cloud keys, private keys, application keys, live payment keys, tokens or `.env` files. Two pattern hits are fictional or placeholders (a `rzp_live_FICT…` refusal test, an `<your-aws-…>` documentation example) |
| Environment here | A development workstation: `.env` is local (`APP_ENV=local`, debug on, `log` mail, root database user, no Redis). There is **no production, staging or production-like environment**, no object storage and no secret store |
| Scheduler and workers | Neither runs for PeopleOS here: the only crontab entry and the two supervisor workers belong to another local project. The PeopleOS scheduler heartbeat has never been recorded on the development database |
| CI | `.github/workflows/ci.yml` runs Pint and the SQLite suite on pushes to `main` and on pull requests only; never the MySQL suite or the browser suites |
| Production configuration gate | `peopleos:config:validate --as-production` on the development `.env`: 11 errors, 2 warnings, as expected for a development machine |
| Readiness | `peopleos:readiness`: verdict NOT DECLARED. Statutory BLOCKED (24 rules, 0 verified, 5 open notices); restore and DR UNVERIFIED; 0 configuration failures in local mode |
| Statutory rules | 24 versions (EPF 2, ESI 1, PT 9, LWF 7, TDS 3, gratuity 1, UAE pension 1): 21 `draft`, 3 `review`, 0 `verified`. Finalisation enforcement is always on in production |
| Retention and deletion | `hcm` and `hcm_ux_showcase`: 0 retention policies, 0 contract terms, 0 legal holds, 0 offboardings, 0 deletions. Deletion off, registration off, Razorpay off, cooling-off 14 days |
| Credential remediation | Holds: the rotated showcase accounts have no remember-me token and no sessions. Operators accept neither the fixture word nor the leaked value in any database |

## 2. Gaps found in this phase

| ID | Gap | Severity | Outcome |
|---|---|---|---|
| S11-1 | A change of a role's permissions (role editor, blueprint import) was **not audited**: a role could be given `payroll.finalize` without a trace (proven by a probe: 0 events) | P2 | Fixed (`a5afc65`), tested, mutation-tested |
| S11-2 | The platform was **indexable**: `robots.txt` allowed everything, and no page carried a noindex header, though invitation and signup links carry their token in the path | P2 | Fixed (`cf70e5d`) |
| S11-3 | The **production configuration gate did not know the release switches**: registration, deletion, live payments, a demo seed password and HSTS strength | P2 | Fixed (`cf70e5d`): refused in production without a recorded authorisation reference |
| S11-4 | The credential is in the **current tree** of a public branch, not only in history | P1 | Owner action (it needs a push or a branch deletion, neither authorised) |
| S11-5 | Deletion evidence (certificates) exists **only in the database**: a restore to a point before a deletion loses both the deletion and its proof, so nothing tells operators to re-apply it | P1 for enabling deletion | Recorded; precondition before deletion is ever enabled (refines F11) |
| S11-6 | No PeopleOS scheduler or worker anywhere here; CI covers SQLite only | P3 | Production deployment requirement; CI recommendation |

## 3. What this phase can and cannot prove

**Can, with evidence in this repository:**
- configuration rules;
- code-level security controls and their tests;
- a local, isolated restore of fictional data with integrity checks;
- the statutory inventory;
- the test suites.

**Cannot:**
- production recoverability (no target environment);
- production configuration (no production environment);
- statutory correctness (needs a qualified specialist);
- independent security assurance (needs an external reviewer);
- any owner decision.

These remain BLOCKED in the release-gate register: `SaaS-11-Release-Gate-Register.md`.
