# Compliance reports — production readiness

Assessed 2026-10-27, after five passes that day:
- the review pass;
- the remediation pass;
- a stabilisation pass (`compliance-report-test-matrix.md` §10);
- a blocker pass: the C12 guard, the D7 evidence, the C8 paper, the A1–A11 papers, the D6 review pack and the
  Analytics boundary (§11);
- a final pass on the owner's approved directions (§12):
  - D7 option B implemented and verified;
  - C8 implemented, with a CI reproducibility check;
  - the C12-W correction workflow designed and built for configuration-only corrections;
  - a plain-language A-series register;
  - the D6 pack prepared for delivery;
  - every suite re-run on a frozen snapshot.
- a follow-up review on 2026-10-28:
  - D7 and C8 re-verified;
  - the C12-D1 decision paper;
  - the design-system failure (one line fixed, the rest proposed);
  - the Analytics security review (one FAIL, fix proposed);
  - the A-series register completed;
  - the reviewer checklist and cover message;
  - the migration runbook;
  - a fresh full SQLite run (test matrix §13).
- a remediation pass on 2026-10-28 (test matrix §14):
  - the Analytics read-API exposure fixed;
  - the payroll finalisation bypass closed;
  - the theme patch kept for the theme owner's approval;
  - the C12-D1 decision brief;
  - every suite re-run on the frozen snapshot `snap-final5`.

Engineering verification, legal-source verification, professional legal review and owner approval are separate
statuses here. One never stands in for another. Nothing is marked ready without evidence.

## 1. Recommendation: **NO-GO**

**Not ready for production approval as statutory compliance for Indian employers.**
- **Engineering:** in good shape and tested, except:
  - one failing test outside compliance, the theme patch awaiting its owner (§3, row 4);
  - one order-dependent test that fails on MySQL in about a third of runs, pre-existing, fix proposed (§3, row 3).
- **Legal content:** not reviewed by a labour-law professional (D6).
- **Undecided:** owner decisions on the statutory rule versions (A1–A11), including four with deadlines within two
  months.
- **C12:** its review and the payroll treatment of corrections that move money (C12-D1) are open.

What would have to change for a **CONDITIONAL GO** (working papers only, central-sphere establishments, with the
caveats shown in the product):
1. **D6:** a professional review of the central forms and of the states the customer operates in.
2. **A1–A3, A7 and A8 decided:** Karnataka and Haryana LWF, West Bengal PT from October 2026, the notices, and Odisha
   (about 3 Nov 2026).
3. **C12:**
   - a review of the invariant, the guard and the C12-W workflow;
   - decision C12-D1 (or acceptance that payroll-impact corrections stay unapplied);
   - decisions on R2–R5.
4. **D1–D3:**
   - the owner commits;
   - CI runs, including the new catalogue job and the MySQL suites;
   - migrations `2026_11_09_100004` and `2026_11_11_100001` are applied, with `peopleos:sync-permissions`, in each
     environment;
   - the read-only layout check is run there.
5. **C1:** establishment configuration (state, type, both appropriate governments) made mandatory, or verified for
   each customer.
6. **A green full suite:**
   - the design-system breach in another session's theme CSS fixed. One line was fixed here; the token patch for the
     rest awaits the theme owner (§3, row 4);
   - the order-dependent test helper fixed (§3, row 3).
7. **Analytics:**
   - the read-API fix (applied 2026-10-28, §3a) reviewed, including its reviewed-field lists;
   - the owner's review of each dataset's permission;
   - a volume check;
   - only then may the report library go into a release candidate.
8. **D7 follow-through:** maker-checker format verification of the layouts used for exports. D7 itself is done; this
   step is now possible.
9. **Payroll finalisation:** the owner accepts the behaviour change. Finalised payroll can no longer be reopened by
   anyone; corrections go forward as arrears or recoveries until C12-D1 is decided (§3, row 26).

**GO** additionally needs:
- the bonus and gratuity versions verified (A5, A6);
- the Tamil Nadu and Kerala PT models (A10, A4);
- the legal questions B1–B13 and L1–L4 answered.

## 2. Status by area

| Area | Status | Evidence |
|---|---|---|
| Location-based applicability (state, type, per-Code appropriate government, registrations, dated thresholds, per-month decisions, transfers) | **ENGINEERING_VERIFIED** | LBA, SCN, VAR (`compliance-report-test-matrix.md` §1–2) |
| Historical correctness (dated forms, thresholds, rule versions per month; a built pack never rebuilt) | **ENGINEERING_VERIFIED** | LBA, CALC, GRAT, INT |
| Report generation (every builder, ZIP, index, retry, failure handling, duplicate prevention) | **ENGINEERING_VERIFIED** | CRT, SEC, INT, MYSQL |
| Security and privacy (tenant and scope isolation, download re-checks and fingerprint, masking, PoSH confidentiality, review, rate limits, zip-slip, redaction, request lock) | **ENGINEERING_VERIFIED**; pack retention is **OWNER_DECISION_REQUIRED** (D9) | SEC, INT, MYSQL |
| Statutory export layouts on MySQL (D7) | **ENGINEERING_VERIFIED**; **owner decision made** (option B). The 5 development layouts are `intact_against_pack`; nothing was rewritten. Maker-checker format verification is **not done** | D7 paper; `ExportLayoutChecksumTest`; read-only check on `hcm` |
| Gratuity entitlement at exit | **ENGINEERING_VERIFIED**; legally **UNVERIFIED** for exits from 21 Nov 2025 (versions proposed; A6) | GRAT |
| Dependencies | Livewire **4.4.8** (lockfile and runtime; GHSA-mhf7-xp6r-6qrc, affected ≥ 4.3.0 < 4.4.8; the app uses neither `#[Authorize]` nor `#[Json]`). `composer audit`: no advisories (final run, stage 6) | Test matrix §12, stage 6 |
| Legal sources, central | **Partly LEGAL_SOURCE_VERIFIED**: Wages, SS and OSH (Central) Rules, Income-tax Rules (130 / 138 / deposit), PoSH, the 2026 minimum wages and (new) the Code on Wages s.39 bonus time limit are official. **LEGAL_REVIEW_REQUIRED** / **UNVERIFIED** remain (§8) | `india-compliance-source-register.md`; `evidence/2026-10-27c-time-sensitive.md` |
| Legal sources, states | **Mostly partial or secondary.** 42 of 188 entries are primary; S&E is primary in six states | Register §6–9; `state-wise-compliance-matrix.md` |
| Professional legal review | **Not done** (D6). The pack is ready to send; the owner must name a reviewer and authorise sending. Nothing here is PROFESSIONAL_LEGAL_REVIEW_COMPLETE | `legal-review-pack.md` |
| Statutory rule pack | **OWNER_DECISION_REQUIRED**: 11 versions proposed and inactive; W1–W11 confirmed wrong or doubtful; notices N1–N12 recommended, none created | `statutory-rule-change-register.md`; `owner-decision-register-a-series.md` |
| Payroll engine | Tamil Nadu and Kerala PT structure **INCOMPLETE** (half-yearly, per local body); LWF wage ceilings and slabs not modelled | Register §6 |
| Customer configuration | **CONFIGURATION_REQUIRED**: each establishment's state, type and both appropriate governments. Missing facts are reported, never assumed | SCN, LBA |
| Filing | **NOT_APPLICABLE by design**: nothing is filed, paid or submitted | — |
| Catalogue maintenance (C8) | **Owner decision made** (option B) and **ENGINEERING_VERIFIED**: the generator is in `tools/compliance-catalogue/`, and `check.sh` reproduces all three files byte-for-byte. The CI job runs only once the owner commits | C8 paper; test matrix §12 |
| Establishment statutory profiles after payroll (C12) | **ENGINEERING_VERIFIED**: the invariant and the guard. **C12-W** correction workflow built for configuration-only corrections. **OWNER_DECISION_REQUIRED**: review, C12-D1, R2–R5 | `StatutoryProfileHistoryTest` (11), `StatutoryProfileCorrectionTest` (10), mutation checks; decision paper C12 and C12-W |
| Repository stability | The working tree is shared and uncommitted, and other sessions were active (UI shell, theme CSS). The machine restarted twice; those runs are recorded in test matrix §11.3. The final results describe the frozen snapshot `snap-final4` (23:05:06, SHA-256 manifest). Only two documentation files changed during the run | Test matrix §12 |
| Design system (outside compliance) | **FAIL**: `Ux15FoundationTest`. The radius breach is fixed with an existing token, one line. The raw hex colours in another session's iris button remain; a token patch is proposed and verified on a copy | Test matrix §13 |
| Analytics report library (another session, after 14:04) | **FAIL** (security): the read API exposes the new datasets; fix proposed, not applied. Tests 12 / 12 on MySQL. Permission review and volume **BLOCKED** | §3a |

## 3. Acceptance checklist

Marks: **PASS** (met, with the evidence named), **FAIL**, **BLOCKED** (cannot be met without an owner, legal or
external action), **NOT APPLICABLE**.

| # | Criterion | Mark | Evidence or blocker |
|---|---|---|---|
| 1 | Existing uncommitted work remains preserved | **PASS** | No reset, clean, stash, checkout or restore was run. The index was not changed by this work (staged by something outside this session at 10:24). Other sessions' files were left alone, with these exceptions, each made while the owning session was idle or ended, and each a targeted addition that keeps that session's changes:
<ul><li>an additive guard in the profile model (2026-10-27);</li><li>on 2026-10-28, the unattended-run guard in `ReportRunner` (report-library session, ended);</li><li>the analytics allow-list in `config/peopleos.php`;</li><li>the reopen block in the working tree of the staged `PayrollRunTest`, where the index is unchanged</li></ul>
| 2 | All nine reported MySQL compliance-folder failures are resolved | **PASS** | Reproduced on 2026-10-27 (13 tests: 1 failed, 8 errors), then fixed. `snap-final4`: 242 / 242. `snap-final5`, which adds `Enterprise`: 267 / 268. The one error is a different test, the order-dependent flake in row 3 | |
| 3 | The full relevant MySQL suite passes without unexplained skips or failures | **FAIL** (pre-existing test flake) | `snap-final5`:<ul><li>`tests/MySql` 141 / 141, 0 skipped (stage 3, including the two new payroll races);</li><li>stage 2: 267 / 268, with **1 error** in `EpfReturnTest` "keeps returns inside the company scope…".</li></ul>Cause: `employeeWithUser()` takes `Company::query()->first()` with no order, and on MySQL the `(tenant_id, code)` index may return the other company first, since factory codes are random. On unchanged code it failed 2 of 6 runs (`test-runs/epf-company-order-flake-mysql.txt`). Proposed one-line fix: `test-runs/proposed-employee-helper-company-order.patch`. Not applied, because it is a shared test helper outside this work and needs full re-runs | |
| 4 | SQLite regressions pass | **FAIL** (outside compliance) | `snap-final5` (2026-10-28 08:35, digest `e689bd3afbd3ba86`): 1,838 tests, 1,696 passed, **1 failed**, 141 skipped. The 141 are `tests/MySql`, run on MySQL in stage 3: 141 / 141. The one failure is unchanged since `snap-sqlite5`. The theme owner's approval of the patch is still missing. The design-system test fails on another session's theme CSS. Its `border-radius: 999px` was replaced by the existing token `var(--pos-radius-pill)`, an identical value, one line. The same test then fails on raw hex colours in that session's iris button. A token patch is proposed (`test-runs/proposed-theme-css-iris-tokens.patch`): it passes the test 4 / 4 on a copy and renders the same in both themes. Not applied, because it adds design tokens to another session's work. No compliance, payroll or exit test failed |
| 5 | The checksum strategy is documented and historical integrity is protected | **PASS** | Versioned algorithm; old rows unchanged; D7 option B proves them against the pack without rewriting |
| 6 | D7 approved before any historical checksum rewrite | **PASS** | The owner approved option B (verification against the original layout file, no rewrite). Implemented and tested. All 5 development layouts are `intact_against_pack` against the layout pack committed before them. No stored value changed (before and after evidence is byte-identical). Their maker-checker format verification is a separate, open platform step |
| 7 | All required A1–A11 decisions are recorded | **PASS** (recorded) / **BLOCKED** (not decided) | Decision papers (§1 of the decisions document) and the plain-language register |
| 8 | The C8 catalogue-maintenance decision is recorded | **PASS** | Owner chose option B. Implemented in `tools/compliance-catalogue/` with `check.sh` and a CI job. Reproduction verified; negative cases verified |
| 9 | No unverified rule has been activated | **PASS** | 0 activated; 11 proposed remain inactive; `verify()` untouched |
| 10 | All report types have explicit evidence and readiness statuses | **PASS** | §4 below; every catalogue entry's label backed by its sources (`CatalogueIntegrityTest`) |
| 11 | Establishment-specific applicability scenarios pass | **PASS** | All twelve scenarios (`compliance-report-test-matrix.md` §2) |
| 12 | Historical rule versions are applied correctly | **PASS** | EPF v1 → v2, form changes, bonus straddle, gratuity v1 → v2 → v3 by date; a built pack never rebuilt; profile changes and corrections leave finalised runs' rule versions untouched (tested) |
| 13 | Missing configuration cannot be misrepresented as full compliance | **PASS** | MISSING_CONFIGURATION per Code; readiness CONFIGURATION_REQUIRED; a missing registration makes a report incomplete; a missing establishment makes gratuity pending, never the head office |
| 14 | Authorisation, masking, PoSH confidentiality and download security pass | **PASS** | SEC, INT (fingerprint), CRT; C12-W enforces separate requester and reviewer and a new approve permission |
| 15 | ZIP generation, retry, concurrency and audit tests pass | **PASS** | SEC, CRT, INT; MySQL races for packs, layout verification, payroll finalisation and correction apply (`snap-final5` stage 3, 141 / 141) | |
| 16 | The legal-source register and state matrix accurately identify unresolved items | **PASS** | Generated by the C8 tool for revision `2026-10-27b`, with §8's explicit list. Accurate to the evidence read; not a legal opinion |
| 17 | Professional legal review completed before legal accuracy is claimed | **BLOCKED** | Not done (D6). The pack is ready; the owner must name a reviewer and authorise sending. No legal accuracy is claimed |
| 18 | Production operational requirements are documented | **PASS** | §6 below; `compliance-migration-runbook.md`: migrations M1–M5 and the permission sync, with purpose, dependencies, additive or destructive, pre-checks, backups, order, verification and rollback |
| 19 | A final go/no-go recommendation is supported by evidence | **PASS** | §1: **NO-GO**, with the conditions for a conditional go |
| 20 | C12: finalised months keep the statutory context payroll used, and corrections are controlled | **PASS** (engineering) / **BLOCKED** (review, C12-D1, R2–R5) | Invariant and guard; C12-W request, review, apply and disclosure; 21 tests on SQLite, plus stage 2 on MySQL; mutation checks |
| 21 | Analytics changes after the snapshot verified separately | **PASS** (engineering) / **BLOCKED** (review, permissions, volume) | The read-API exposure is fixed (§3a). `ReadApiReportAccessTest` has 9 tests covering the nine required cases. On the original code 6 of them fail (5 failures and 1 error: API runs were not audited at all); the other 3 cover checks that already existed (credentials, `reports.run`, tenant). The Analytics-related set passes 96 / 96 on SQLite; stage 2 on MySQL passes `Analytics` and `Enterprise`. Open: the owner's review of the fix and its field lists, the permission per dataset, and a volume check |
| 22 | Catalogue reproducibility enforced | **PASS** (local) / **BLOCKED** (CI runs only after the owner commits) | `check.sh` exit 0 (stage 0); exit 1 on edits, 2 on a missing input |
| 23 | Time-sensitive legal dates checked against authoritative sources | **PASS** for three of four / **BLOCKED** for one | Official: WB 1406-F.T., Code on Wages s.39 with s.2(a), Haryana s.9A(2). Odisha: the reassembly date is official, but Art. 213 could not be re-read (India Code 504), so the date stays a calculation |
| 24 | Migrations and the permission sync planned, and not run on shared environments | **PASS** (planned, nothing run) / **BLOCKED** (needs approval per environment) | `compliance-migration-runbook.md`. On the development database M4 and M5 are pending; nothing was run |
| 25 | C12-D1 decision paper for money-moving corrections; such corrections stay blocked | **PASS** (paper and brief; blocked in code) / **BLOCKED** (owner decision) | Decision paper C12-D1, options A–D, and the owner brief `c12-d1-decision-brief.md`. The recommendation, not a decision, is a forward adjustment. `apply()` refuses `payroll_impact` corrections (tested). The reopen bypass found earlier is closed (row 26) |
| 26 | Finalised payroll cannot be reopened, recalculated, changed or deleted; the approval gate cannot be raced | **PASS** (engineering) / **BLOCKED** (owner acceptance of the behaviour change; C12-D1) | `PayrollRuns::reopen()` refuses finalised and paid runs on the locked row and audits each attempt (`REJECTED`). Model guards cover `PayrollRun`, `PayrollPeriod`, `Payslip`, `PayrollEntry` and `PayrollEntryLine`. The UI hides Reopen for those runs, and a recalculation audits what it replaced. Correction applies are serialised per establishment. Tests:<ul><li>`PayrollFinalisationIntegrityTest`: 6 tests; all fail on the original code, 4 on substance and 2 on message wording only;</li><li>two `StatutoryProfileCorrectionTest` additions;</li><li>two MySQL races in `PayrollFinalisationConcurrencyTest` (stage 3).</li></ul>Three existing tests changed: `PayrollRunTest` asserts the refusal, and `EpfReturnTest` and `TdsComplianceTest` build a changed month at the data level with a documented helper |

- **FAIL: 2.**
  - Row 3: a pre-existing, order-dependent MySQL test flake; fix proposed.
  - Row 4: the design-system test, outside compliance; patch awaiting the theme owner.
- **BLOCKED, in whole or in part: 9.** Rows 7, 17, 20, 21, 22, 23, 24, 25 and 26.
- **PASS: 15.** The rest.

## 3a. Blocker tracker

Each blocker is tracked on its own. Completed documentation does not close a blocker; only the evidence named does.

| Blocker | Evidence required to close | Evidence now | Status |
|---|---|---|---|
| **C12** | A defined historical-consistency invariant, a safe implementation, regression tests and a review | Invariant defined. Guard implemented. C12-W workflow built (configuration-only path). 21 tests, with mutation checks. Not reviewed. C12-D1 (payroll treatment) and R2–R5 undecided | **OPEN**: review, C12-D1, R2–R5 |
| **D7** | An explicit owner decision, and the approved treatment of historical checksums | Decision B approved and implemented. The 5 development layouts are verified against the committed pack, with nothing rewritten | **CLOSED** for the decision and the integrity verification. Maker-checker format verification of the layouts is a separate platform task |
| **C8** | An explicit owner decision on generator ownership and maintenance | Decision B approved and implemented. Reproduction verified; CI job added | **CLOSED** for the decision and the implementation. The CI job takes effect on commit |
| **A1–A11** | Decisions documented, authoritative legal evidence, and approved rule changes | Papers and a plain-language register. Deadlines checked on official hosts where reachable. No version activated | **OPEN**: owner decisions and evidence gaps |
| **D6** | A completed professional legal review with recorded conclusions | Pack ready, with a delivery checklist; not sent, not reviewed | **OPEN**: the owner must name a reviewer and authorise sending |
| **Analytics** | Separate verification of the changes made after the 14:04 snapshot | Code and permission review done (§3a). The read-API exposure was **fixed** on 2026-10-28, and the tests pass with a mutation check. The permission choices and a volume check are open | **OPEN**: review of the fix; owner permission review; volume |
| **Payroll finalisation** | Finalised payroll immutable on every path, refusals audited, races proven | Fixed on 2026-10-28; SQLite tests plus MySQL races (row 26) | **OPEN**: owner acceptance of the behaviour change; C12-D1 |
| **Design system** (outside compliance) | The full suite green | One line fixed (an existing token). The rest is a proposed patch, still applying cleanly to the current file | **OPEN**: the theme owner approves, or replaces, the patch |
| **MySQL test flake** (test helper) | Stage 2 green on repeated runs | Cause shown; 2 of 6 runs fail on unchanged code; one-line fix proposed | **OPEN**: apply the helper fix, then re-run stage 2 |

### Analytics report library: change boundary

This is another session's work, inspected read-only and not judged faulty.

- **Files.** Since 14:04, 21 files in the first snapshot changed and 36 were added:
  - 32 new datasets, `ReportLibrary`, `ReportLibraryPage` and its view;
  - changes to 10 existing datasets, the `Dataset` base, `DatasetRegistry`, `ReportRunner`, `ListReports` and
    `ReportBuilderSchema`;
  - `ExperienceNavigation`;
  - `config/peopleos.php` (Report Builder filter operators only);
  - `phase-14-analytics.md`;
  - three test files.
- **Shared code with compliance reports.** None. Nothing references `ComplianceReports`, the statutory engine, the
  rule pack or payroll services.
- **Data read.** It reads payroll, compensation, tax-declaration, exit-settlement and statutory-detail data,
  read-only. The component register reads only finalised and paid runs.
- **Payroll, statutory calculations, access controls.**
  - Unchanged.
  - No new permission keys: every new dataset gates on an existing one (for example `employee.sensitive.view`,
    `payroll.view`, `compensation.view`, `exit.view`, `bgv.view`, and `compliance.returns.view` or
    `compliance.tds.manage`).
  - Its only write is the saved report a user creates from a template.
- **Migrations.** None.
- **Tenant isolation.**
  - All queries go through Eloquent models, so the tenant and access scopes apply, including the two aggregate
    queries.
  - There is no `withoutGlobalScope` and no `DB::table`.
  - Its tests cover masking, the sensitive permission, scope for child rows of unscoped models, and users kept
    inside the tenant.
- **Tests.**
  - `ReportingTest`, `ReportLibraryTest` (seeds every dataset and runs every template) and `Ux15NavigationTest`.
  - On the live tree: 22 / 22 on SQLite (16:45) and 12 / 12 on MySQL (16:49).
  - On the final snapshot `snap-final4`: 12 / 12 on MySQL (stage 2) and passing on SQLite (stage 1).
### Analytics security and permission review (2026-10-28)

Reviewed from the code and verified by tests where marked. The read-API finding below was **fixed on 2026-10-28**.
The report-library session that owns the code had ended. The fix is a targeted addition that keeps that session's
changes.

| Area | Result | Evidence |
|---|---|---|
| Authentication | **PASS** | The panel requires sign-in. The read API needs an API key with the `reports.run` scope (route middleware); `ReadApiTest` asserts 401 without a key |
| Authorisation of reports, the library and dashboards (server side) | **PASS** | `ReportPolicy` (owner, shared, `analytics.manage`); `ReportLibraryPage::canAccess()`; `ReportLibrary::create()` checks the dataset and the right to share; `Dashboards` checks shared, owner or manage before running |
| Dataset permission for signed-in users | **PASS** | `ReportRunner::execute()` refuses a dataset the user may not use; `ReportingTest` "exposes datasets by permission…" |
| Field-level limits | **PASS** | Columns, filters, grouping, aggregates and formulas are confined to `fieldsFor($user)`. A filter on a hidden field evaluates against null, so it cannot reveal hidden values |
| **Read API, which runs with no signed-in user** | **PASS** (fixed 2026-10-28) / **BLOCKED** (owner review) | See the finding and its resolution below |
| Tenant boundaries | **PASS** | `BelongsToTenant` on every base model; the API key binds its tenant; a test keeps users inside the tenant |
| Organisation scope for signed-in users (company, location, department, manager) | **PASS** | `AccessScope` on scoped models, and child rows of unscoped models constrained; test "keeps child rows of unscoped models inside the viewer access scope" |
| Sensitive values for signed-in users | **PASS** | Statutory identifiers and bank accounts masked to the last four characters; datasets gated by `employee.sensitive.view`, `payroll.view`, `compensation.view` and similar |
| Choice of permission per dataset | **BLOCKED** (owner) | For example: tax investments to `compliance.returns.view` or `compliance.tds.manage` holders; family and emergency contacts to `employee.sensitive.view` |
| Exports and downloads | **PASS** | Every export audited (`AuditAction::Export`). A stored file can be downloaded only by whoever ran it, or the owner for a scheduled run, and only with `analytics.export` or `analytics.manage`. Scheduled runs use the owner's rights |
| Dashboard chart widget | **PASS** (minor note) | `ReportChart` loads a report by ID without the view-policy check, but runs it with the viewer's own rights, so it cannot reveal data the viewer couldn't otherwise see |
| Filtering, pagination, performance | **BLOCKED** (volume) | Rows are streamed (500 at a time) and filtered in PHP, capped at 10,000 matching rows, with truncation flagged. The screen shows 500, widgets 50, the API up to 5,000. Each run is a full scan of its dataset; there is no volume test on a large tenant |
| Tests for permitted and denied access | **PASS** (API) / **PARTIAL** (signed-in denial per new dataset) | The API path is covered by `ReadApiReportAccessTest` for all nine required cases. Signed-in denials are covered for: dataset permission, sensitive fields, template-creation rights, sharing, tenant users, scope of child rows. Not yet: a signed-in denial for each new dataset |

**FAIL: the read API exposes the new datasets.**
- **How it happens.**
  - `ReadController::runReport()` runs a shared report **with no signed-in user**, so `ReportRunner` skips the dataset
    permission check.
  - With no user, `AccessScope` applies no row restriction, so the run reaches every row in the tenant.
  - The nine original datasets flag their sensitive fields, so a no-user caller gets none of them. The new datasets
    flag none, so `fieldsFor(null)` returns every field.
- **What can be read:** family members (date of birth, phone), emergency contacts, CTC and salary revisions, pay
  components, payslip net pay, tax investments, final-settlement amounts, improvement plans, users (email, MFA), and
  statutory identifiers and bank details (masked).
- **Preconditions:** an analytics manager shares such a report, and an API key holds `reports.run`.
- **Reproduced:** on a disposable copy of today's code, a shared family-members report answered 200
  (`test-runs/analytics-api-gap-unpatched.txt`).
- **Proposed fix** (`test-runs/proposed-analytics-unattended-datasets.patch`, 40 lines):
  - an allow-list `peopleos.analytics.unattended_datasets`, holding the nine reviewed datasets;
  - the API answers 403 for any other dataset;
  - `ReportRunner` refuses a no-user run on one too.
- **Verified on a disposable copy:** the proposed test `test-runs/proposed-ReadApiUnattendedDatasetsTest.php`, plus
  `ReadApiTest`, the Analytics folder and the AI tests: 25 / 25.
- **Not applied as proposed.** It was superseded by the resolution below.

**Resolution (2026-10-28).** It goes further than the proposal, which would have trusted the nine datasets' fields
wholesale and checked neither row scope nor key scopes.
- **Default deny for unattended results.** A run with no user, or the read API, uses only datasets **and fields**
  listed in `peopleos.analytics.unattended_datasets`, never a sensitive or permissioned field. A definition that
  selects, filters, groups or aggregates on any other field is refused before a row is read (`ReportRunner`).
- **The nine datasets, reviewed one by one** against what the matching API scope already returns:
  - **exits:** excluded. No exits scope exists, and it carries the exit-interview reason, rehire eligibility and the
    settlement amount;
  - **employees:** without age, age band, birthday fields (they rebuild the date of birth), marital status,
    nationality, cost centre, FTE, login, expected joining date and CTC;
  - **attendance:** without the free-text note;
  - **leave:** without applied-on, pending days, backdated and the reviewer's name;
  - **payroll:** without the payment date and taxable earnings. UAN, ESI, bank and account were already hidden;
    masking was not treated as enough;
  - **performance:** the final outcome only (no self, manager, goal or competency scores, no manager);
  - **learning:** as reviewed;
  - **assets:** without the custodian, purchase date and cost;
  - **service desk:** without the agent and satisfaction.
- **Key scopes.** Besides `reports.run`, the key must hold the scopes that guard the same data in this API, for
  example `payroll.read` and `employees.read` for payroll.
- **Rows.** The API runs a shared report as its owner, as scheduled runs do: the owner's dataset permission and
  organisation scope apply, and an inactive owner means no run. Starter reports (no owner) run with the key's own
  reach.
- **Audit.** Every run is audited as `EXPORT` and every refusal as `REJECTED` (`channel: api`, key, refusal code). A
  refusal returns a message and no rows.
- **Tests.** `ReadApiReportAccessTest` covers:
  - no, malformed, wrong-secret, revoked and expired keys;
  - a key without `reports.run`;
  - unreviewed datasets;
  - a missing data scope, an owner without the dataset, and an inactive owner;
  - cross-tenant access;
  - the owner's row scope;
  - field refusal, with the owner's signed-in export unchanged;
  - audit;
  - no-user runs and the integrity of the list itself.

  9 / 9 pass. On the original code 6 fail (5 failures and 1 error: API runs were not audited); the other 3 cover
  checks that already existed (`test-runs/analytics-fix-mutation-original-code.txt`). With `ReadApiTest`, 10 / 10. The related set passes 96 / 96
  on SQLite (`test-runs/analytics-fix-related-sqlite.txt`). `snap-final5` passes it on SQLite (stage 1) and on MySQL
  (stage 2).
- **Behaviour change for integrations:** a key that ran reports with `reports.run` alone now also needs the data
  scopes. An exits report no longer runs through the API.

**Still needed before a release candidate includes Analytics:**
- the owner's review of the fix and of its reviewed-field lists;
- the owner's confirmation of each dataset's permission;
- a denial test for each new dataset;
- a volume check on a large tenant;
- inclusion in CI.

## 4. Readiness of each central report

Computed from catalogue revision `2026-10-27b` (`ResolvedForm::legalStatus()`) for a central-sphere establishment
(Karnataka, both governments Central) and a state-sphere one (Maharashtra, both State), in September 2026 or the
period the report is in force. Output classes:
- **Legally verified prescribed form:** a register whose prescribed form rests on official text with no open review.
  The workbook still uses PeopleOS's layout with the form's particulars.
- **Internally prepared working sheet:** a working paper for a return, challan or certificate. The filing itself
  happens on the authority's portal.
- **Management control report:** an internal check, not a statutory report.
- **Draft requiring professional review:** the source is partly verified, conflicting, or under legal review.
- **Unverified statutory output:** no official source behind it.
- **Checklist item:** kept outside PeopleOS; listed in the pack's checklist.

"ENGINEERING_VERIFIED" means the builder runs in tests (CRT builds a whole year, every builder and frequency). It
says nothing about legal correctness.

| Report | Engineering | Central-sphere form | Legal status · output class | State-sphere form | Legal status · output class | Owner decisions |
|---|---|---|---|---|---|---|
| Employee register | ENGINEERING_VERIFIED | Form I (= OSH Form XIII) | LEGAL_SOURCE_VERIFIED · Legally verified prescribed form | Register of employees — PeopleOS layout with the particular… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| Register of wages, overtime, advances, fines and deductions | ENGINEERING_VERIFIED | Form IV (= OSH Form XV) | LEGAL_SOURCE_VERIFIED · Legally verified prescribed form | Register of wages — PeopleOS layout with the particulars of… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| Attendance register-cum-muster roll | ENGINEERING_VERIFIED | Form IX (= OSH Form XIV) | LEGAL_SOURCE_VERIFIED · Legally verified prescribed form | Muster roll — PeopleOS layout with the particulars of Form … | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| Deductions, loans, advances and fines — detail | ENGINEERING_VERIFIED | Supports Form IV (earlier Form C) | LEGAL_REVIEW_REQUIRED · Draft requiring professional review | Supports Form IV (earlier Form C) | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| Overtime detail | ENGINEERING_VERIFIED | Supports Forms IV and IX (overtime hours and wages) | LEGAL_REVIEW_REQUIRED · Draft requiring professional review | Supports Forms IV and IX (overtime hours and wages) | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| Register for leave with wages | ENGINEERING_VERIFIED | Form XX | LEGAL_SOURCE_VERIFIED · Legally verified prescribed form | Leave register — PeopleOS layout with the particulars of Fo… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| Register of women employees (maternity benefit) | ENGINEERING_VERIFIED | Form XXII | LEGAL_SOURCE_VERIFIED · Legally verified prescribed form | Register of women employees — PeopleOS layout with the part… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review |  |
| PF statement and challan (ECR working) | ENGINEERING_VERIFIED | ECR — Electronic Challan cum Return (A/c 1, 2, 10, 21, 22) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | ECR — Electronic Challan cum Return (A/c 1, 2, 10, 21, 22) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) |  |
| ESI contribution statement and challan | ENGINEERING_VERIFIED | Monthly contribution (ESIC portal) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | Monthly contribution (ESIC portal) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) |  |
| TDS on salary — monthly deduction and deposit | ENGINEERING_VERIFIED | Tax deposited through the income-tax challan (TAN) | LEGAL_SOURCE_VERIFIED · Internally prepared working sheet (rule verified) | Tax deposited through the income-tax challan (TAN) | LEGAL_SOURCE_VERIFIED · Internally prepared working sheet (rule verified) |  |
| ESI contribution period summary | ENGINEERING_VERIFIED | Contribution periods April–September and October–March | NOT_APPLICABLE (internal control) · Management control report | Contribution periods April–September and October–March | NOT_APPLICABLE (internal control) · Management control report | B6 |
| TDS on salary — quarterly statement working | ENGINEERING_VERIFIED | Form No. 138 (earlier Form 24Q) | LEGAL_SOURCE_VERIFIED · Internally prepared working sheet (rule verified) | Form No. 138 (earlier Form 24Q) | LEGAL_SOURCE_VERIFIED · Internally prepared working sheet (rule verified) |  |
| TDS on salary — quarterly statement working (Form 24Q) | ENGINEERING_VERIFIED | Form 24Q | UNVERIFIED · Unverified statutory output | Form 24Q | UNVERIFIED · Unverified statutory output |  |
| Half-yearly return working sheet (factories) | ENGINEERING_VERIFIED | Half-yearly return under the state factories rules | UNVERIFIED · Unverified statutory output | Half-yearly return under the state factories rules | UNVERIFIED · Unverified statutory output |  |
| Salary variance report | ENGINEERING_VERIFIED | PeopleOS payroll check | NOT_APPLICABLE (internal control) · Management control report | PeopleOS payroll check | NOT_APPLICABLE (internal control) · Management control report |  |
| Joiners and leavers — statutory actions | ENGINEERING_VERIFIED | UAN / ESIC registration of joiners; date of exit of leavers | NOT_APPLICABLE (internal control) · Management control report | UAN / ESIC registration of joiners; date of exit of leavers | NOT_APPLICABLE (internal control) · Management control report |  |
| Unified annual return working sheet | ENGINEERING_VERIFIED | Form XVII (OSH (Central) Rules, 2026) — Parts I and IV | LEGAL_SOURCE_VERIFIED · Internally prepared working sheet (rule verified) | Annual return under the state's rules (Code rules where not… | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) |  |
| Employment Information Return working | ENGINEERING_VERIFIED | Form XXVI (replaces ER-I and ER-II) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | Form XXVI (replaces ER-I and ER-II) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | B5 |
| Quarterly employment return (ER-I) working | ENGINEERING_VERIFIED | Form ER-I | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | Form ER-I | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) |  |
| Bonus register | ENGINEERING_VERIFIED | No form prescribed under the Code (internal register on the… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review | No form prescribed under the Code (internal register on the… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review | A5, B1–B3, C6 |
| Gratuity eligibility and liability | ENGINEERING_VERIFIED | No register prescribed; nominations in Form III, applicatio… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review | No register prescribed; nominations in Form III, applicatio… | LEGAL_REVIEW_REQUIRED · Draft requiring professional review | A6, C6 |
| Equal remuneration check | ENGINEERING_VERIFIED | No form prescribed under the Code (earlier Form D of the Eq… | NOT_APPLICABLE (internal control) · Management control report | No form prescribed under the Code (earlier Form D of the Eq… | NOT_APPLICABLE (internal control) · Management control report |  |
| List of national, festival and paid holidays | ENGINEERING_VERIFIED | Notice of holidays (state national and festival holidays Ac… | UNVERIFIED · Unverified statutory output | Notice of holidays (state national and festival holidays Ac… | UNVERIFIED · Unverified statutory output |  |
| Salary TDS — annual summary and certificates | ENGINEERING_VERIFIED | Form No. 130 (earlier Form 16) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | Form No. 130 (earlier Form 16) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) |  |
| Internal Committee annual report (PoSH) | ENGINEERING_VERIFIED | Annual report (Rule 14) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) | Annual report (Rule 14) | LEGAL_REVIEW_REQUIRED · Internally prepared working sheet (draft, professional review) |  |
| Update the establishment's registration on Shram Suvidha | Checklist only | Form I (OSH) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form I (OSH) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Wage slips to every employee | Checklist only | Form V (= OSH Form XVI) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form V (= OSH Form XVI) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Letter of appointment to every employee | Checklist only | Appointment letter (prescribed particulars) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Appointment letter (prescribed particulars) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Employees' claims for salary TDS | Checklist only | Form No. 124 (earlier Form 12BB) | UNVERIFIED · Checklist item (no output) | Form No. 124 (earlier Form 12BB) | UNVERIFIED · Checklist item (no output) |  |
| Annual health check-up of employees above the prescribed age | Checklist only | Health check-up record | UNVERIFIED · Checklist item (no output) | Health check-up record | UNVERIFIED · Checklist item (no output) |  |
| Register of accidents and dangerous occurrences; accident notices | Checklist only | Form XIX (register); Form XI (notice) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form XIX (register); Form XI (notice) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Contract labour: principal employer return and contractors' half-yearly returns | Checklist only | Form XVIII (contractor, half-yearly); Form XVII Part III (p… | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form XVIII (contractor, half-yearly); Form XVII Part III (p… | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Vacancy reporting to the Career Centre | Checklist only | Form XXV | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form XXV | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Gratuity nominations | Checklist only | Form III | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form III | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Maternity claims and the abstract of maternity provisions | Checklist only | Forms X and XI (claims); Form XIV (abstract, displayed) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Forms X and XI (claims); Form XIV (abstract, displayed) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Retrenchment and closure notices; worker re-skilling fund | Checklist only | Form XIII (notice); Form XIV (permission, 300+ workers) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Form XIII (notice); Form XIV (permission, 300+ workers) | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Statutory notices and abstracts displayed | Checklist only | Abstracts, wage period and date of payment, holidays, Inter… | UNVERIFIED · Checklist item (no output) | Abstracts, wage period and date of payment, holidays, Inter… | UNVERIFIED · Checklist item (no output) |  |
| Grievance redressal committee, works committee and Internal Committee | Checklist only | Constitution and minutes | LEGAL_REVIEW_REQUIRED · Checklist item (no output) | Constitution and minutes | LEGAL_REVIEW_REQUIRED · Checklist item (no output) |  |
| Standing orders | Checklist only | Certified or model standing orders | UNVERIFIED · Checklist item (no output) | Certified or model standing orders | UNVERIFIED · Checklist item (no output) |  |
| Apprenticeship returns | Checklist only | Apprenticeship portal returns | UNVERIFIED · Checklist item (no output) | Apprenticeship portal returns | UNVERIFIED · Checklist item (no output) |  |

**Verified for central-sphere establishments only** (official text, no open review):
- Form I (employee register);
- Form IV (wage register);
- Form IX (muster roll);
- Form XX (leave register);
- Form XXII (register of women employees);
- working sheets for TDS deposit (r.218), Form No. 138 (r.219) and the unified annual return (Form XVII).

None is professionally reviewed. Every state-sphere register is a draft requiring professional review, or
unverified. State reports are labelled in the catalogue download and in `state-wise-compliance-matrix.md`.

## 5. Rule-version inventory

| | Count | Versions |
|---|---|---|
| Active (unchanged by this work) | 24 | The pack's original versions, including those found wrong (W1–W11) |
| Proposed, inactive | 11 | BONUS v1, v2; GRATUITY v2, v3; LWF KA v2; LWF HR v2–v5; PT WB v2; PT KL v2 |
| Activated by this work | **0** | — |
| Corrections proposed to the owner, not added | 10 | `statutory-rule-change-register.md` §3 |

## 6. Production operational requirements

1. **Migrations** `2026_11_09_100001` to `_100004`, then `php artisan peopleos:sync-permissions`.
2. **Before relying on any export layout on MySQL:** run `php artisan peopleos:compliance:layout-checksums`.
   - It is read-only.
   - Exit code 1 means a real mismatch, which must be investigated.
   - `intact_against_pack` rows were sealed with the original algorithm and are proven against the layout pack (D7,
     decision B); they can then go through maker-checker verification.
3. **Statutory rules:** `php artisan peopleos:compliance:sync` (scheduled weekly) loads the pack; it never activates a
   version.
4. **Queue workers** for `GenerateComplianceReportPack` (packs are built in the background).
5. **Enforcement** is always on in production (`ComplianceRules::enforced()`). Unverified rule versions do not decide
   payroll finalization, export gates or automatic gratuity.
6. **Storage:** the compliance disk holds pack ZIPs, with no purge yet (D9). Back it up with the database; a restored
   pack must still match its recorded SHA-256 to be downloadable.
7. **CI:**
   - `php artisan test`;
   - `DB_CONNECTION=mysql` on the compliance folder;
   - `tests/MySql` with `PEOPLEOS_MYSQL_CONCURRENCY_DB` on disposable databases;
   - `composer audit`;
   - Pint.
8. **Customer onboarding:** each establishment's state, type, appropriate government (Wages / OSH) and Social
   Security appropriate government; registrations (EPF, ESIC, PT, TAN); establishment assignments for every employee.
9. **Customer communication (D5):** reports are working papers, not filings; label meanings; what is unverified.

## 7. Release blockers

1. **D6.** Professional legal review of the catalogue, the state matrix and B1–B13.
2. **Owner decisions.** A1–A11 (rule versions, notices, Odisha before about 3 Nov 2026, Tamil Nadu and Kerala PT
   models), D7, C8, D9, and the product decisions C1–C12. For C12 the guard is implemented and tested; its review
   and residuals R1–R5 remain.
3. **D7.** Layouts sealed before migration `100004` cannot be verified on MySQL until it is decided. Known affected:
   5 draft layouts on the local development database. Other environments are unknown until the read-only check is run
   there.
4. **Time-critical legal facts:**
   - West Bengal PT from October 2026 (A3);
   - Odisha's Ordinance ceases about 3 Nov 2026, six weeks after the Assembly reassembled on 22 Sep 2026 (A8);
   - FY 2025-26 bonus is payable by 30 Nov 2026 (A5);
   - Haryana LWF 2026 (A2), due before 31 Dec 2026.
   - Current position: decisions §1.1 (re-checked 2026-10-27).
5. **Engine gaps:** Tamil Nadu and Kerala PT models; LWF ceilings, slabs and employer minimums; PT and LWF for states
   without a rule version.
6. **D1–D3.** Commit (nothing is committed; the index was staged outside this work), CI with MySQL, migrations in each
   environment.
7. The release blockers recorded for the wider product (SaaS final register) still apply.
8. **Analytics.** The report library added after the 14:04 snapshot needs its own review before it goes into a
   release candidate (§3a). It does not affect compliance reports.

## 8. Unverified obligations (explicit list)

These are the facts that are not settled after both verification passes. "Settled" means official text read, with
no legal review open. Each is labelled in the catalogue or the rule-change register.

**Central:**
- S.O. 4711(E) (bonus eligibility ₹21,000, dated 25-08-2026) on an official host, and the Code on Wages s.39 time
  limit (eight months) on India Code.
- Which minimum wage sets the bonus calculation ceiling (B2). The rates themselves are now official.
- Whether the central bonus S.O.s bind state-sphere establishments (B1), and the FY 2025-26 transition (B3).
- Gratuity:
  - the ceiling under s.53(3) (₹20 lakh rests on the 2018 notification; gazette not read on a government host);
  - the late-payment interest rate (B13);
  - four years and 240 days (B10);
  - the 50% wage rule's application (B11).
- Whether the ESIC half-yearly return and Form 01-A survive as filings under the Code (B6).
- Whether the EIR (Form XXVI) reaches private establishments now (B5).
- Whether certificates for tax year 2025-26 were Form 16 or Form No. 130.
- PoSH annual report deadlines set by states or District Officers.
- State national and festival holidays Acts: their forms, and whether they survive the OSH Code (B12).
- Health check-up, standing orders, apprenticeship returns, statutory notices and employees' tax declarations:
  checklist items with no source cited (UNVERIFIED).
- EPF administrative charges in 2026; whether CY 2026 is the first unified return under the new forms.

**State Code rules:**
- Meghalaya (all four Codes).
- Mizoram:
  - IR final text;
  - Wages final text and forms;
  - the SS Rules' appointed date.
- Gazette dates:
  - UP IR;
  - J&K IR and OSH;
  - the Gujarat Wages and IR gazette prints.
- UP's muster-roll form (Form XIII is appended to the rules but cited by no rule).
- Uttarakhand's annual-return form (its OSH rules are still draft).
- Ladakh SS and OSH commencement; Sikkim Wages effective date.
- The unified return due date in GJ, AR, JK and MZ (1 February in the rule, 28/29 February on the form).

**Professional tax:**
- West Bengal: the final Schedule 1607-F.T. (including Part C's date).
- Odisha:
  - the Repeal Act's assent;
  - the Ordinance's lapse, about 3 November 2026: calculated from the Assembly's reassembly on 22 Sep 2026, which is
    now official; the Repeal Bill's passage is secondary only.
- Kerala:
  - local-body adoption of the 2024 rates;
  - the rules amendment;
  - municipal provisions and due dates.
- Tamil Nadu:
  - rates outside Greater Chennai;
  - panchayat levies;
  - exemptions (s.117-H).
- Gujarat: the 2022 notification on an official host.
- Andhra Pradesh and Telangana: the employer due date.
- Jharkhand:
  - the appointed day;
  - the quarterly-return dates as printed.
- Madhya Pradesh: notification CT-15-0001-2025.
- Punjab:
  - the 2025 Amendment Act and Rules;
  - the income test under the Income-tax Act, 2025.
- Sikkim: the appointed day; r.13(1) over HTTPS.
- Nagaland: the monthly due day.
- Meghalaya: rates and due date.
- Manipur: sourcing over HTTPS.
- Arunachal: no official source for absence.
- Local-body levies in non-levying states.

**Labour welfare fund:**
- Tamil Nadu: the deduction month (A9).
- Haryana:
  - the 2023, 2025 and 2026 cap notifications on a government host;
  - the ten-employee threshold (B8).
- Maharashtra: the 2003 and 2024 amending Acts on a government host.
- Karnataka:
  - a board confirmation of the 2024 rate;
  - the notification number of Act 05 of 2026.
- Andhra Pradesh: the text of Act 7 of 2015.
- Madhya Pradesh:
  - the January 2026 notification;
  - the ₹2,500 employer minimum's condition.
- Punjab and Chandigarh:
  - gazetting of the 2025 Bill;
  - the text of G.S.R. 353(E)/2018.
- Chhattisgarh: any rate instrument (now UNVERIFIED).
- Kerala: which fund applies (B7).
- Dadra and Nagar Haveli and Daman and Diu: whether the 1986 Act is in force.
- Odisha:
  - an official copy of the 2015 Rules;
  - the half-year split.
- Absence of a fund in 13 states and UTs (secondary evidence only; five more now have official lists).

**Shops and establishments:**
- Maharashtra: whether Bill LXXXIX of 2025 was enacted, which decides the 20-worker threshold.
- Forms resting on secondary sources: KA, WB, KL, TG rules forms, AP, AN, DH, JK, MZ, NL, SK, TR, JH and HP.
- Chhattisgarh's forms (unverified); Uttarakhand (no rules).
- Ladakh: which Act applies.
- UP: Form D above 25 employees (sources conflict).
- Rules texts not found anywhere: CG 2021, DNH&DD 2025, Sikkim 1984, Tripura 1970.
- Every state form's layout against the printed form (D6).
