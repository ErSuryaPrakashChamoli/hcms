# PEOPLEOS — SaaS.9 CLOSURE & RELEASE READINESS REPORT

Date: 9 October 2026. Branch `feature/oct_1_phase_1`. Not pushed, merged or deployed. SaaS.10 was not started.

> **Phase status: COMPLETE.** Every item of this phase's acceptance gate passes (§20).
>
> **Release readiness: BLOCKED.** These are business, legal and operational blockers, not technical failures, and the
> software stays safe while they remain:
> - the statutory gate (24 rules, 0 verified);
> - no verified backup restore or DR exercise in a target environment;
> - production configuration and infrastructure not yet validated;
> - the production-copy rehearsal is planned, not executed;
> - D-8 retention values are not configured, so deletion stays impossible;
> - the public Git history decision is open.
>
> **The leaked credential no longer opens any PeopleOS account here** (rotated and verified, §3–§4). It remains in
> public Git history and must be treated as compromised wherever else it was used.

## 1. Starting and ending commit

| | Commit |
|---|---|
| Start | `bead3b6` (docs: SaaS.9 final report ending commit), clean tree |
| End | `a56ec54` (docs), followed only by the commit that records this hash in this table |

Commits of this phase, in order:
- `92f6306` feat: the legal minimum, company policy and customer contract stay distinct
- `0ee384c` feat: external copies tracked honestly; export packages removed with the data
- `05e0ed4` test: verification, start events, contract terms, immutability, external copies
- `e4c0fdf` fix: the deletion step status column is too short for RETENTION_REQUIRED
- `259ca5f` fix: idempotency key reclaim race answered 422 instead of 409
- `ef086aa` test: contract retention term decided once under concurrency (MySQL race 12)
- `a56ec54` docs: credential remediation evidence, retention decision register, runbooks, release readiness report
- a final docs commit recording the ending commit above

## 2. Branch and repository state

- **Branch:** `feature/oct_1_phase_1`, 39 commits ahead of `origin/feature/oct_1_phase_1` at the start. Clean working
  tree at start and end. No push, merge or deploy.
- **Remote:** `github.com/ErSuryaPrakashChamoli/hcms`, **public** (unauthenticated HTTP 200 on the repository page and
  its API).
- **Environments:**

  | Environment | Database | Server | Deletion switch |
  |---|---|---|---|
  | Development | `hcm` (`.env`) | none running | off (unset, default false) |
  | Showcase | `hcm_ux_showcase` | `127.0.0.1:8090` | off |
  | Disposable test databases | `hcm_saas6_concurrency`, `hcm_saas9b_concurrency`, `hcm_saas9_perf_concurrency_showcase`, `hcm_saas9_ui_showcase`, `hcm_ux_visual_showcase` | fixture servers only during runs | on in test processes only |

- **Processes not touched:** the RMS project's dev server (`:8000`) and its test runs, and another project's queue
  workers (`fynn_on_live`).
- **Migrations:** `hcm` and `hcm_ux_showcase` received this phase's two additive migrations. No data was changed apart
  from the credential rotation (§4).

## 3. Credential status by environment and account (no secrets)

Yes/no tests in memory. Full evidence: `docs/saas/evidence/SaaS-9-credential-remediation.md`.

| Database | Account | Before | After |
|---|---|---|---|
| `hcm_ux_showcase` | `admin@fynnedge.com` (tenant user, no MFA) | **leaked value accepted** | rejected; rotated; sessions revoked |
| `hcm_ux_showcase` | `platform@markedge.local` (platform operator, no MFA) | **fixture word accepted** | rejected; rotated; sessions revoked |
| `hcm` (development) | `platform@markedge.local` | neither accepted | unchanged |
| `hcm` | `admin@fynnedge.com` | absent | — |
| `hcm_p12_replay`, `hcm_p13_replay`, `hcm_p14_replay` (Phase 12–14 replay copies; ownership established from their reports) | `admin@fynnedge.com` | **leaked value accepted** | rejected; rotated |
| same | `platform@markedge.local` | **fixture word accepted** | rejected; rotated |
| Six databases of another local project (Fynn) | `admin@fynnedge.com` | leaked value **not** accepted | not touched |

**Final sweep of every PeopleOS database:**
- No database accepts the leaked value.
- The only platform operators accepting the fixture word are the test-factory accounts in the disposable `*_concurrency`
  databases, which is by design.
- The 14 showcase demo personas use the documented fixture word, by design: showcase only, bound to localhost, no
  operator among them.

## 4. Remediation performed, and owner actions

**Performed** with the application's own mechanisms (ADR-0097). For each affected account:
1. A random replacement, never displayed or stored, set through the `hashed` cast.
2. `SessionSecurity::revokeUser`: the session epoch advances (every session invalid) and the remember-me token is
   cleared. On the pre-SaaS.2 replay copies, the remember token was cleared directly.
3. Database sessions and reset tokens deleted.
4. `PASSWORD_CHANGED` and `SESSIONS_REVOKED` audited.

**Verified:**
- the old credential is rejected;
- the new one passes `Auth::validate`, then was discarded;
- the reset flow can reach the account;
- 0 other accounts changed;
- the showcase audit chains verify;
- the leaked value is in no working-tree file, log or scratch file.

**Owner actions:** see §19, items 1–4.

## 5. Git history review

| Question | Answer |
|---|---|
| In history? | Yes. Introduced in `d75226e` (28 Sep 2026) in `database/seeders/DatabaseSeeder.php` **and** `docs/architecture/phase-1-foundation.md`. Removed in `8f54d4d` (6 Oct 2026, SaaS.2) |
| Published? | Yes: `origin/feature/sep_28_phase_1` and `origin/feature/oct_1_phase_1`, in a **public** repository |
| Real or fixture? | A seeded password for an account on Markedge's domain. Whether it was reused elsewhere cannot be known from here: treat it as real and compromised |
| Other secrets? | None found in all 264 commits: no application keys, cloud or payment-provider keys, private keys, tokens, `.env` files or dumps. The pattern matches were classified as harmless (casts, labels, variable names, fictional test keys, a third-party public key inside saved government web evidence) |
| History rewritten? | **No** (needs your authorisation). Safe procedure and impact: credential evidence §6.3 (`git filter-repo --replace-text` on a mirror, force-push two branches, collaborators re-clone, GitHub Support purge, forks unaffected) |

## 6. Retention categories reviewed

All 36 inventory categories, plus the copies outside the tenant tables:
- export packages;
- warehouse snapshots;
- database backups;
- file storage backups and replicas;
- integrations, webhook receivers, the payment provider, delivered e-mail.

Full register: `docs/saas/SaaS-9-Retention-Decision-Register.md`.

**Model changes, all additive** (ADR-0098 to 0101):
- **A: verified legal minimum.** Source, reviewer and date. An unverified minimum is
  `LEGAL_MINIMUM_PENDING_VERIFICATION` and keeps its category from deletion.
- **B: company policy.** Counted from its start event: the closure, or the financial year end.
- **C: customer contract terms** (`tenant_retention_terms`). A minimum extends retention; a deletion deadline is a
  recorded conflict, never a deletion.
- **Immutable decided versions.**

Policies, terms and the pages were verified by tests and in the browser. Nothing activates without a second operator and
an effective date.

## 7. Verified legal minimums and their authoritative sources

Read in the official text this phase. These are facts, not configuration: **none is configured in the system.**

| Ref | Provision | Period | Obligated party | Source |
|---|---|---|---|---|
| L1 | CGST Act 2017 s.36 | 72 months from the due date of the annual return | Markedge (registered supplier) | taxinformation.cbic.gov.in |
| L2 | Income-tax Rules 2026 (G.S.R. 198(E)) r.46(9); r.46(8) India-hosting of electronic books | 7 tax years | Markedge, and employers for their own books | incometax.gov.in |
| L3 | Code on Wages (Central) Rules 2026 (G.S.R. 343(E), 8 May 2026) r.51(4) | 5 years after the last entry | The employer (central sphere) | labour.gov.in |
| L4 | Social Security (Central) Rules 2026 (G.S.R. 344(E)) r.53(1)(e) | 5 calendar years from the last entry | The employer | labour.gov.in |
| L5 | DPDP Act 2023 s.8(7) | Erase when the purpose is served, unless law requires retention | The customer (fiduciary), through Markedge (processor) | meity.gov.in (when s.8 starts applying was not verified) |
| L6 | Companies Act 2013 s.128(5) | 8 financial years | Markedge | Reported by research; not re-verified (site refused access) |
| L7 | GDPR Art. 28(3)(g) | Delete or return at the end of the services | Markedge as processor | Reported by research; not re-verified (EUR-Lex refused access) |

**Not verified or not researched:**
- State labour rules;
- the EPF period;
- whether the ESI Regulations are still in force;
- UK GDPR;
- every non-India market.

All of these stay `LEGAL_MINIMUM_NOT_CONFIGURED`.

## 8. Company policy values supplied or pending

**None supplied.** Every category is `RETENTION_POLICY_NOT_CONFIGURED`, so nothing is deletable. The decisions open to
you, with their consequences, are D8-1 to D8-8 in the register. In short:
- **D8-1:** customer-data policy after closure (L5 and L7 point towards deleting at the end of the service);
- **D8-2:** whether to keep anything on a customer's behalf by contract;
- **D8-3:** Markedge's own records (L1, L2, L6);
- **D8-4:** the audit trail after exit;
- **D8-5:** backup retention, and the evidence that a deleted tenant has aged out of backups;
- **D8-6:** export package expiry;
- **D8-7:** India-hosting (L2a);
- **D8-8:** state rules and other markets.

## 9. D-15 outcome

**No change; the safer existing behaviour remains** (ADR-0103):
- **Two operators.** Deletion needs two *different* platform operators. The maker can never approve: the page does not
  offer it and the service refuses it (mutant X33).
- **No tenant user or API key.** Tenant users and API keys cannot plan, approve, cancel, reject or retry a deletion, or
  record external copies (mutant X46 and a test). No API route reaches the exit.
- **Cooling-off.** It is set only by the environment (14 days by default). No operator action shortens it, and
  execution before it ends is tested (mutant X36).
- **Holds.** Release needs two operators. Every batch re-checks holds under the exit lock.

No new role was created. A dedicated deletion-approver role is an option for the SaaS.10 control plane. Inherent limit:
two colluding operators can act together (as in every maker-checker), and both are named in the audit and the
certificate.

## 10. Backups and external copies

- **States:** PENDING, REQUESTED, FAILED, RETENTION_REQUIRED (with a kept-until date), UNSUPPORTED, CONFIRMED (final)
  (ADR-0102).
- **Backup entries:** database backups, and file storage backups with replicas.
- **The certificate:**
  - states its scope;
  - lists `external_copies_outstanding`;
  - sets `complete_including_external_copies` to true only when every copy is confirmed.

  The page shows "Not complete outside PeopleOS" live.
- **Export packages:** a deletion now removes the tenant's package files; records and checksums stay.

Procedure: `docs/operations/runbooks/external-copies.md`.

**Gaps:**
- No production backup or object-storage system exists here.
- Restores were rehearsed locally only.
- There is no automated "re-apply deletions after a restore" job; it is a manual rehearsal step.
- No real backup was touched.

## 11. Deletion switch status

`PEOPLEOS_TENANT_DELETION_ENABLED` is **unset, so false**, in `.env` and in the 8090 server's environment. It was never
enabled in development, showcase or production. Destructive tests ran only in SQLite test databases, the disposable
MySQL test databases and the disposable browser fixture.

Validated, each by a test:
- disabled means nothing runs;
- holds block deletion;
- a missing policy blocks deletion;
- missing approval blocks execution;
- self-approval is refused;
- the cooling-off is enforced;
- a deletion cannot start twice (MySQL race 2);
- it resumes after a failure (race 11);
- the certificate matches what was retained;
- financial and statutory records are protected.

## 12. Production-copy rehearsal plan

`docs/operations/runbooks/production-copy-rehearsal.md` covers all 18 steps:
1. restore;
2. secrets;
3. isolation baseline;
4. export;
5. export signature and checksums;
6. hold;
7. second-person release;
8. eligibility;
9. plan;
10. maker-checker;
11. cooling-off;
12. execute (the isolated copy only);
13. retained financial and statutory records;
14. files and warehouse;
15. external copies;
16. certificate;
17. other tenants unaffected;
18. evidence and sign-off.

**Not executed this phase.**

## 13. Security findings and severity

| ID | Sev | Finding | Status |
|---|---|---|---|
| F1 | P1 | Leaked credential in **public** Git history (two branches) | Rotated in every PeopleOS database (not active here). Owner: rotate wherever else it was used; decide repository visibility and history cleanup |
| F2 | P1 | Statutory gate BLOCKED: 24 rules, 0 verified, 5 open notices (`peopleos:readiness`) | Open. Needs authoritative evidence per rule and professional sign-off |
| F3 | P1 | Backup restore and DR not verified in a target environment; no production infrastructure here | Open. Readiness shows UNVERIFIED |
| F4 | P1 | Production configuration not validated: the config validator, run as production, finds 10 errors on this local `.env`, as expected for a development machine | Open. Must pass in the target environment |
| F5 | P1 | Production-copy rehearsal not executed | Plan written |
| F6 | P1 (for deletion) | D-8 company policies unconfigured | Safe: nothing is deletable. Business and legal decision |
| F7 | P2 | Income-tax Rules r.46(8) India-hosting of electronic books | Legal review before a hosting decision |
| F8 | P2 | An operator without enrolled MFA is sent to enrol on next sign-in, so a leaked password would let an attacker enrol their own authenticator | Recommend enrolling MFA at operator creation; both showcase operators had none |
| F9 | P2 | Exit lock on the `tenants` row (a documented exception to the foreign-key lock rule) | Follow-up: a dedicated, pre-created lock row |
| F10 | P2 | Showcase demo personas use the fixture word (by design; localhost only) | Keep the showcase local; never expose `:8090` |
| F11 | P2 | No automated re-application of deletions after a backup restore | Manual rehearsal step; automate before enabling deletion |
| F12 | P2 | Planning cost grows with the whole database (4.4 s for a small tenant among 134) | Acceptable for an operator action; optimise later |
| F13 | P3 | `PEOPLEOS_SEED_PASSWORD` is shorter than 16 characters (local only) | Leave unset or lengthen |
| F14 | P3 | Cooling-off can be set to 0 by the environment | Document; consider a production floor |
| F15 | P3 | Obsolete replay databases (`hcm_p12/13/14_replay`) | Rotated; may be dropped on your instruction |
| F18 | P3 | Planning looks up contract terms per category: 147 more queries per plan, constant in tenant size (§17) | Load a tenant's terms once per plan; not urgent (planning under 1 s) |
| F16 | — | **Fixed:** the deletion step status column was too short for `external_retention_required` on MySQL | `e4c0fdf`, plus a MySQL column-length guard test |
| F17 | — | **Fixed:** idempotency reclaim race answered 422 instead of 409 under load | `259ca5f`, plus a forced-interleaving race that fails without the fix |

No P0 remains: the only critical item, the active credential, is closed locally.

## 14. Test results (SQLite and MySQL separately)

Final runs on the final application code (`259ca5f`). Later commits add one MySQL race (`ef086aa`, run separately below) and documentation:

| Suite | Result |
|---|---|
| **SQLite** (`php artisan test --parallel --processes=2`) | **1,391 passed, 0 failed**, 1,518 tests, 19,174 assertions. The **127 skipped are the MySQL-gated tests** (not counted as passed here; they are the next row) |
| **MySQL** (`PEOPLEOS_MYSQL_CONCURRENCY_DB=hcm_saas6_concurrency php artisan test tests/MySql`) | **127/127 passed**, 751 assertions, 1,415 s (under parallel load) |
| MySQL race 12 (added after the full runs, `ef086aa`) | The whole offboarding race file **12/12**, 107 assertions. The MySQL suite now has 128 tests, which are the 128 skipped in a SQLite run |
| Migration integrity | `2026_10_31_100001` UP → DOWN → UP on MySQL. `2026_10_31_100002` applied on development, showcase and test databases. The column-length guard test passes |
| New tests this phase | `RetentionClosureTest` (8); a pages test (1); MySQL `OffboardingLargeRowsTest` column guard (1); MySQL race 12, contract-term decisions (1); the forced idempotency race (rewritten) |

**Failures found by testing, and their fixes:**
1. **Column too short.** The browser fixture (MySQL) found the deletion step status column too short for
   `external_retention_required` (`e4c0fdf`).
2. **Idempotency race (a real SaaS.2 defect).** An intermediate full MySQL run failed SaaS.2 race 5 under load. It was
   investigated, not re-run until green: the idempotency middleware answered 422 when a concurrent request had just
   deleted the expired key. Fixed in `259ca5f`. The race now forces the interleaving and fails without the fix.
3. **Stale assertion.** The export-package assertion in `TenantDeletionTest` was updated for the new rule that packages
   are removed by a deletion.

## 15. Concurrency and mutation results

**Concurrency (MySQL 8.4, real processes):**
- **SaaS.9 races 1–11** pass on the closure code (§14).
- **New race 12** (`ef086aa`): an approval, a rejection and the proposer's withdrawal of one contract retention term
  arrive at once. Exactly one wins, the other two are told "This term is no longer pending.", and one audit event is
  written. With the "still pending" condition removed, all three report success, so the race fails. The offboarding race
  file then ran **12/12** (107 assertions).
- **SaaS.2 race 5** (idempotency) was rewritten to force the interleaving that failed under load:
  1. a deleter process removes the expired key;
  2. a file flag holds it until a reader process has read "no row";
  3. `DB::beforeExecuting` and a `QueryExecuted` listener pause each side at the right query.

  The race returns 422 without the fix in `259ca5f` and 409 or 200 with it.
- **Exit lock.** `LegalHolds::lockFor` (tenant row, then case) still serialises closing, planning, approval, the
  deletion batches, the purge and reopening. Its lock on the `tenants` row is the documented exception (F9).

**Mutation testing.** Each mutant ran on a private copy of the final tree, never the tree that 8090 serves. They ran
one at a time, each against the tests that should catch it:

| Set | Result |
|---|---|
| SaaS.9 authorisation and safety mutants (X01–X53), re-run on the closure code | **53/53 killed**. X48 ("storage failure ignored") was killed by non-termination: the deletion never finishes, and the test run timed out. X50 and X52 were then run on their own (both killed) |
| Closure mutants (C01–C12): unverified minimum treated as verified; half a verification accepted; verification without a minimum; financial-year start ignored; contractual minimum ignored; deadline conflict not recorded; a term approved by its proposer; a decided policy version editable; export packages left behind; certificate claiming completeness with copies outstanding; retention-required without a date; a term proposed by a tenant user | **12/12 killed** |
| MySQL lock and conditional mutants (K01–K13), each removing one lock or condition and run against its race | **13/13 killed** on the final tree |
| Closure race 12: the term transition not conditional on pending | **Killed** (all three decisions succeeded) |
| Idempotency fix (`259ca5f`) reverted | **Killed** by the forced race 5 (422) |

**Total: 80/80 mutants killed. None survived.**

## 16. Browser, accessibility and visual results

| Suite | Result |
|---|---|
| SaaS.9 browser checks (`hcm_saas9_ui_showcase`, deletion off) | **42/42**. New this phase: contract-retention section and `CONTRACT_DELETION_DEADLINE_CONFLICT` on the plan; a verified minimum shown with its reviewer and an unverified one as `LEGAL_MINIMUM_PENDING_VERIFICATION`; financial-year start events; the checker offered approval of a pending contract term; "Not complete outside PeopleOS" with `RETENTION_REQUIRED`; the certificate's `complete_including_external_copies: false`, outstanding copies and scope. Evidence: `docs/saas/evidence/SaaS-9-browser-validation.json` |
| Accessibility (axe, WCAG 2.0/2.1 A and AA) | **8 states, 0 violations** (offboarding desktop and phone, hold form, deletion and certificate, retention desktop and phone, data export desktop and phone) |
| Visual regression (UX.18 suite) | **120/120, 0 unexpected**: no existing screen changed (Chromium 112, Firefox 4, WebKit 4) |
| Existing browser suite | **74/74** (6 skipped by design) |

**The browser fixture found a real defect** that SQLite tests cannot: the deletion step status column was too short for
`external_retention_required` on MySQL. Fixed in `e4c0fdf`, which also adds a MySQL guard test.

## 17. Performance results

Same probe as SaaS.9 §36: MySQL 8.4, the showcase tenant scaled by cloning employees, approved 0-month policies (the
worst case for deletion), batch size 1,000. It ran on the final code after the other runs had finished. The desktop was
in use, so timings carry some noise; query counts do not. The probe file is not committed. SaaS.9 → closure:

| Dataset (tenant rows) | Export | Plan | Approve | Delete |
|---|---|---|---|---|
| 100 employees (11,226) | 3.6 → 3.7 s; 954 → 954 queries | 0.50 → 0.59 s; 667 → 814 | 0.67 → 0.83 s; 970 → 1,118 | 5.6 → 6.7 s; 5,799 → 6,097; 1.7 MB |
| 1,000 employees (51,726) | 5.8 → 6.0 s; 1,063 → 1,063 | 0.50 → 0.61 s; 667 → 814 | 0.68 → 0.78 s; 970 → 1,118 | 7.6 → 8.1 s; 6,023 → 6,321; 1.7 MB |
| 10,000 employees (456,726) | 79.7 → 83.7 s; 2,296 → 2,296; 15.5 MB | 0.79 → 0.83 s; 667 → 814 | 0.83 → 0.94 s; 970 → 1,118 | 35.2 → 36.1 s; 9,236 → 9,534; 1.7 MB |
| 1,000 employees + 4,893 documents (98 MB of files) | 17.5 → 23.4 s; 1,085 → 1,085; 51 MB | 0.70 → 0.93 s; 667 → 814 | 0.72 → 1.13 s; 970 → 1,118 | 8.6 → 11.0 s; 6,219 → 6,517; 2.4 MB |

Every deletion completed, with a final certificate.

**What changed and why:**
- **Planning: +147 queries, the same at every size.** The contract-term lookup makes four indexed queries per category
  (the category's own term, then `*`, at the closure date and today) for 36 categories. Approval re-plans, so it adds
  the same. The cost depends on the number of categories, not on tenant size, and planning stays under a second. Loading
  a tenant's terms once per plan would remove it (F18, P3).
- **Deletion: +298 queries, the same at every size.** Execution re-plans twice (at the start and for the certificate).
  The rest is the new external entry and the removal of export packages. With documents, the extra 2.4 s includes
  deleting the 97.9 MB export package, which SaaS.9 left in storage.
- **Export: no code change.** Query counts are identical; the time differences are machine noise.
- **Memory stays flat.** Export peaks at about 15 MB, or 51 MB with documents; deletion at under 2.5 MB.

## 18. Remaining blockers

**Release** is blocked by F2 (statutory), F3 (restore and DR), F4 (production configuration and infrastructure) and F5
(rehearsal).

**Enabling deletion** additionally needs:
- F6 (D-8 policies);
- D8-5 (backup ageing evidence);
- F11 (re-apply after restore);
- your decision.

**Hygiene and decisions:** F1 (credential elsewhere, repository visibility, history).

**Nature of the blockers:** none is a failing test or a known defect in the shipped code. The system refuses deletion
while they remain.

## 19. Exact actions required from you

1. **Rotate the leaked password wherever else it was ever used** (outside this machine). This repository's accounts are
   already rotated.
2. **Decide whether the GitHub repository should be public.** If you want the history cleaned, authorise the rewrite
   procedure (credential evidence §6.3).
3. **Reset the two showcase passwords if you use those accounts.** Go to
   `http://127.0.0.1:8090/admin/password-reset/request`; the link is written to `storage/logs/laravel.log` (log
   mailer).
4. **Optional:** say whether to drop the obsolete replay databases (F15).
5. **D-8:** with counsel, decide D8-1 to D8-8 in the register. Then one operator proposes and another approves each
   policy on the Retention policies page.
6. **D-15:** keep the two-operator rule (the default), or ask for a dedicated approver role in SaaS.10.
7. **Statutory:** commission verification of the 24 rules (authoritative evidence and professional sign-off).
8. **Infrastructure:** provide a target environment, then run the backup restore and DR exercise and the
   production-copy rehearsal. Only then decide whether to enable deletion in production.

## 20. Release readiness

| Gate item | Result |
|---|---|
| Credential status verified | Yes (§3) |
| Authorised local remediation completed and verified | Yes (§4) |
| No credential printed or committed | Yes (yes/no checks only; working tree, logs and scratch files clean) |
| Retention configuration safe | Yes: nothing deletable without approved policies; unverified minimums block deletion |
| Unresolved legal values explicitly unconfigured | Yes (`LEGAL_MINIMUM_NOT_CONFIGURED` / `RETENTION_POLICY_NOT_CONFIGURED`) |
| Maker-checker enforced | Yes (§9) |
| Deletion remains disabled | Yes (§11) |
| Backup and external-copy limitations documented | Yes (§10) |
| Regression tests pass | Yes: SQLite 1,391/1,391 run (0 failed; 127 MySQL-gated), MySQL 127/127, then race 12 with its file 12/12 |
| Security checks pass | Yes (isolation, authorisation, export security, mutants) |
| Documentation complete | Yes (§21) |

**Phase: COMPLETE. Release readiness: BLOCKED** (business, legal and operational, see §18). The software is safe in
the blocked state.

## 21. SaaS.10 not started; documents

SaaS.10 was not started. Nothing was pushed, merged or deployed. Live Razorpay was not activated. Production data was
not touched (there is none here).

Documents:
- this report;
- `docs/saas/evidence/SaaS-9-credential-remediation.md`;
- `docs/saas/SaaS-9-Retention-Decision-Register.md`;
- `docs/operations/runbooks/external-copies.md`;
- `docs/operations/runbooks/production-copy-rehearsal.md`;
- updated `deletion.md` and `retention.md` runbooks;
- `docs/architecture/saas-9-offboarding.md` (§6, §9, §10, §15);
- decision register ADR-0097 to 0103;
- security invariants 102 to 107;
- `docs/saas/evidence/SaaS-9-browser-validation.json` (refreshed);
- a dated update note at the top of the SaaS.9 final report.
