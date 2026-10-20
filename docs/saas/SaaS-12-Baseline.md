# SaaS.12 — Stage 0 baseline: the observed truth

Date: 10 October 2026. Every row was observed in this session, not carried forward from earlier reports. Secrets were
never displayed: credential checks answer yes or no, and secret scans print variable names and counts only.

**Starting point.**
- Branch `feature/oct_1_phase_1`, HEAD `960adeb` (SaaS.11's ending commit, as reported).
- Clean working tree, no stash, one worktree.
- 64 commits ahead of `origin/feature/oct_1_phase_1` (`36a9292`). Nothing pushed.

**Status values** follow the programme's protocol:
- NOT STARTED;
- IN PROGRESS;
- BLOCKED (owner decision, external review, environment or technical defect);
- READY FOR VERIFICATION;
- VERIFIED;
- NOT APPLICABLE (evidence required).

## 1. Security and repository

| # | Finding | Observed state | Evidence | Sev | Required action | Responsible | Acceptance criterion | Dependency | Status |
|---|---|---|---|---|---|---|---|---|---|
| B1 | Repository visibility | **Public**; 0 forks; default `main`; last push 8 Oct | GitHub API (unauthenticated) | P1 | Decide visibility | Owner | Decision recorded; setting matches | — | BLOCKED — OWNER DECISION |
| B2 | Leaked value in a public branch's current files | Present in the tip of `feature/sep_28_phase_1` (`9bf682f`): 1 occurrence in its seeder (yes/no scan). Also in history of that branch and of `feature/oct_1_phase_1`; not in `main` | Yes/no scan of `git show 9bf682f:…`; `git branch --contains` | P1 | Remove from the branch (push) or delete the branch; then decide on history | Owner (push authority) | 0 occurrences on every public ref | B1 | BLOCKED — OWNER DECISION |
| B3 | Leaked value accepted anywhere here | **No**: 0 of 3 MySQL account checks (`root` socket and TCP, `markedge_app`); 0 `.env` keys; 0 users in 19 PeopleOS databases | `s12/credreuse.php`, `credcheck.php` | — | None here | — | — | — | VERIFIED (this machine only) |
| B4 | Leaked value accepted elsewhere | **Unknown**: GitHub account, e-mail, hosting, other products' application accounts and devices cannot be checked from here, and other products' data must not be read | — | P1 | Owner checks and rotates every place the value was used | Owner | Owner's written confirmation | — | BLOCKED — OWNER DECISION |
| B5 | Collaborators, deploy keys, Actions secrets, branch protection | **Not observable**: `gh` is not installed and no authenticated GitHub access exists. CI uses **no** secrets (workflow inspected) | `.github/workflows/ci.yml` | P2 | Owner reviews in GitHub settings, or grants read access | Owner | Review recorded | — | BLOCKED — OWNER DECISION |
| B6 | CI failing on the branch | The only CI run of `feature/oct_1_phase_1` failed at Pint, so **its tests never ran**. HEAD failed Pint the same way (5 files) | GitHub Actions API (job steps); `pint --test` | P2 | Fix style | Engineering | Whole-repo Pint passes | — | VERIFIED (fixed `71c6d3c`) |
| B7 | Current secrets in logs, tracked files, evidence | **0** occurrences of `APP_KEY` (encoded and decoded), `DB_PASSWORD` and the seed password in logs, 2,932 tracked files and the evidence | `s12/secretscan.php` | — | None | — | — | — | VERIFIED |
| B8 | Error responses | Production renders a generic 500 page and `{"message":"Server Error"}`: no exception, path or token. No test existed | New test, proven to fail with debug on | P3 | Regression test | Engineering | Test in the suite | — | VERIFIED (`a185e1a`) |
| B9 | Sessions and tokens | `hcm`: 4 sessions; showcase: 9 (the browser runs). 0 remember-me tokens, 0 API keys, 0 live invitations in both | Database counts | — | None | — | — | — | VERIFIED |
| B10 | Operator MFA | 1 operator in each of `hcm` and the showcase, **0 enrolled** | Database counts | P2 | Each operator enrols an authenticator | Operators | Readiness check PASS in the target | Target environment | BLOCKED — OWNER DECISION |
| B11 | Dependencies | Composer: 0 advisories. npm: 2 critical and 1 high, all build tooling | `composer audit`, `npm audit` | P2 | Non-breaking fix | Engineering | 0 advisories; identical build | — | VERIFIED (fixed `0e935d4`) |
| B12 | Test-suite operators with the factory password in disposable databases | 6 disposable `hcm_*` test databases have operators accepting the test factory's well-known word | `credcheck.php` | P3 | Never serve them; drop disposable databases after the programme (owner choice) | Owner | Dropped or kept local | — | BLOCKED — OWNER DECISION |

## 2. Deployment safety

| # | Finding | Observed state | Evidence | Sev | Required action | Responsible | Acceptance | Dependency | Status |
|---|---|---|---|---|---|---|---|---|---|
| D1 | Deploy re-grants removed permissions | `peopleos:sync-permissions` (deploy step 5) gave every system-role permission back at each deploy, unaudited, although tenants can edit system roles | Failing test on the old code | **P2** | Fix | Engineering | Removals persist; introductions audited; real data unchanged | — | VERIFIED (fixed `90ef326`) |
| D2 | Showcase seeders guarded by database name only | `UxShowcaseSeeder` and `UxShowcaseNotificationsSeeder` would run in production on a `*_showcase` database | Code; failing test on the old code | P3 | Refuse production | Engineering | Test | — | VERIFIED (fixed `812f2c9`) |
| D3 | Interrupted migration | A killed `migrate` leaves a half-applied migration; re-run fails ("already exists") | Disposable MySQL test | P2 | Recover from pre-deploy backup; documented | Engineering, operations | In the deployment plan | — | VERIFIED (documented) |
| D4 | Concurrent migration | `--isolated` serialises runs; fails on a first install with the database cache store | Disposable MySQL test | P3 | `--isolated` for upgrades | Operations | In the deployment plan | — | VERIFIED (documented) |
| D5 | Deployment artefacts | None in the repository (no container, infrastructure code or deploy scripts) | Repository search | P1 | Define the deployment mechanism | Owner, infrastructure | Mechanism exists and is rehearsed | Environment | BLOCKED — ENVIRONMENT |

## 3. Retention, recovery and configuration

| # | Finding | Observed state | Evidence | Sev | Required action | Responsible | Acceptance | Dependency | Status |
|---|---|---|---|---|---|---|---|---|---|
| R1 | Deletion proof only in the database (S11-5) | A restore before a deletion erased the deletion and its proof | Code | P1 for deletion | Build register and reconciliation | Engineering | Tested with a faithful restore | — | VERIFIED (built `db55ba1`) |
| R2 | D8-1…D8-8 | 0 policies, 0 terms, 0 holds, 0 deletions in `hcm` and the showcase; switch off | Database counts | P1 for deletion | Decide with counsel | Owner, counsel | Approved policy versions | — | BLOCKED — OWNER DECISION |
| R3 | Production environment | None exists | Discovery | P1 | Provide | Owner, infrastructure | Environment inventory | — | BLOCKED — ENVIRONMENT |
| R4 | Production checker coverage | Broad (≈45 keys), but no file-permission checks; the development `.env` is 664 | Validator review | P2 | Add rules | Engineering | Tested | — | VERIFIED (`470dd25`) |
| R5 | Error reporting and alerting integration | None in the application (logs and health endpoints only) | Code review | P2 | Choose a provider; wire alerting | Owner, infrastructure | Alerts reach someone on call | Environment | BLOCKED — OWNER DECISION |
| R6 | Statutory | 24 rules, 0 verified, 5 open notices. Gratuity is a stored rule no calculation uses | Readiness; statutory evidence JSON | P1 | Specialist verification | Owner → specialist | Verified with evidence, or not applicable with approval | — | BLOCKED — EXTERNAL REVIEW |
| R7 | Independent security review | Not started | — | P1 | Engage | Owner | Signed checklist | Review environment | BLOCKED — EXTERNAL REVIEW |
| R8 | Recovery objectives | The DR document's 4 h RTO and 15 min / 24 h RPO were written by engineering as targets; **no owner approval** exists | `backup-and-disaster-recovery.md` | P1 | Approve objectives | Owner | Approval reference | — | BLOCKED — OWNER DECISION |
| R9 | Signup (D10-1…D10-11) and billing (B-4, B-7, B-8, B-9) | All open; switches off | Configuration; registers | P1 for those scopes | Decide | Owner | Recorded decisions | — | BLOCKED — OWNER DECISION |
| R10 | Duplicate configuration keys | `peopleos.workflows.trigger_events` lists two keys twice, with identical labels | IDE diagnostic | Info | None (no behaviour) | — | — | — | NOT APPLICABLE — EVIDENCE REQUIRED (identical values; no change) |
