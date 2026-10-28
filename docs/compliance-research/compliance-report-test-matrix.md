# Compliance reports — test matrix

Prepared 2026-10-27 and revised after the remediation pass. It maps each testing requirement to the tests that cover
it, and lists what is not covered. Results are recorded in §9, with the exact commands. Skipped tests are never
counted as passes, and a passing test is not evidence that a statutory rule is legally correct.

Test files:
- `tests/Feature/Compliance/ComplianceReportsTest.php` (13 tests): **CRT**
- `tests/Feature/Compliance/LocationBasedApplicabilityTest.php` (4 tests): **LBA**, the acceptance scenario
- `tests/Feature/Compliance/ApplicabilityScenariosTest.php` (3 tests, new): **SCN**
- `tests/Feature/Compliance/ComplianceReportSecurityTest.php` (8 tests): **SEC**
- `tests/Feature/Compliance/ComplianceReportIntegrityTest.php` (3 tests, new): **INT**
- `tests/Feature/Compliance/ComplianceReportCalculationTest.php` (5 tests): **CALC**
- `tests/Feature/Compliance/CatalogueVariantsTest.php` (3 tests): **VAR**
- `tests/Feature/Compliance/CatalogueIntegrityTest.php` (3 tests, new): **CAT**
- `tests/Feature/Exit/GratuityEntitlementTest.php` (9 tests, new): **GRAT**
- `tests/Feature/Compliance/ExportLayoutChecksumTest.php` (10 tests, new): **D7**
- `tests/Feature/Compliance/ExportLayoutTest.php`, `ProductionGateTest.php`: **LAYOUT**, **GATE** (Phase 6.2; the 9 MySQL failures)
- `tests/Feature/Architecture/ComplianceInvariantsTest.php`: **INV**
- `tests/Feature/Payroll/StatutoryTest.php`: **STAT**
- `tests/Feature/Compliance/RuleVerificationTest.php`, `StatutoryEvidenceMaintenanceTest.php`: **RULE**
- `tests/MySql/ComplianceReportsConcurrencyTest.php` (2), `tests/MySql/ExportLayoutConcurrencyTest.php` (2, new): **MYSQL**

## 1. The acceptance scenario (LBA)

One company with five establishments:

| Establishment | State | Type | Appropriate government | Employees |
|---|---|---|---|---|
| Bengaluru | KA | office | Central (both Codes) | — |
| Pune | MH | office | State | 12 (11 at ₹3,00,000; 1 transferred at ₹6,00,000) |
| Chennai | TN | shop | State | — |
| Ahmedabad | GJ | factory | State | — |
| Delhi | none | office | none | — |

| Requirement | Asserted |
|---|---|
| Establishments in at least three states | Each state's S&E registers only for its own establishments. Delhi (no state) gets no state obligations, never Karnataka's (the head office's) |
| Establishment type | Gujarat factory: S&E register NOT_APPLICABLE, with the reason |
| Appropriate government | Bengaluru gets Form IV (central, from 8 May 2026), now APPLICABLE and LEGAL_SOURCE_VERIFIED on the labour.gov.in copy of the Wages Rules. Pune gets the state layout (PENDING_VERIFICATION). Delhi gets MISSING_CONFIGURATION with the reason |
| Different thresholds | Maharashtra S&E: Sep 2025 APPLIES; Nov 2025 PENDING_VERIFICATION (20-worker threshold not verified), "PeopleOS counts 12" |
| Form change partway through a year | CY 2026: Form B in April, Form IV in June. TDS: Form 24Q for FY 2025 Q4, Form No. 138 for FY 2026 Q1–Q3 |
| Rule change partway through a year | EPF v1 → v2 (17 Sep 2026): August EPF wages ₹15,000, October ₹25,000, each sheet naming its version |
| Historical report regenerated with its own version | The August sheet, generated in October after v2 began, still uses v1 |
| Employee transfer | 1 Jul 2026: PT in Maharashtra for June, Karnataka for July; the employee register follows |
| Proposed rules never in payroll | Haryana LWF at v1 (₹31), not proposed v5 |
| Five determinations kept distinct, each with source, dates and reason | Tamil Nadu S&E wage register LEGALLY_UNVERIFIED; Tamil Nadu PT PENDING_VERIFICATION; Bengaluru leave and wage registers APPLICABLE; Gujarat factory NOT_APPLICABLE; Delhi MISSING_CONFIGURATION |

## 2. The twelve applicability scenarios of the remediation brief

| # | Scenario | Test |
|---|---|---|
| 1 | One company, establishments in several states | LBA |
| 2 | Different establishment types | LBA (GJ factory), SCN (factory covered for gratuity) |
| 3 | Different workforce thresholds | LBA (MH S&E 10 → 20), SCN (gratuity 10, bonus 20) |
| 4 | Missing establishment state | LBA (Delhi) |
| 5 | Missing appropriate government | LBA (Delhi, both); **SCN: state and Code on Wages government set, Code on Social Security government missing → maternity register MISSING_CONFIGURATION only** |
| 6 | Missing or incomplete registrations | **SCN: a branch without an EPF code → PF challan produced with "No EPF establishment code is recorded for …", readiness not verified** |
| 7 | A rule changing partway through a year | LBA (EPF v1 → v2), CALC (bonus straddle), GRAT (gratuity v1 → v2 → v3 by exit date) |
| 8 | An employee transferring between establishments | LBA |
| 9 | A form changing between periods | LBA (Form B → Form IV; 24Q → 138), VAR (state forms from their final rules) |
| 10 | A historical report regenerated with the historical version | LBA (August EPF under v1); GRAT (exit on 31 Oct 2025 decided under v1 when run today) |
| 11 | An establishment below a verified threshold | **SCN: a three-person office NOT_APPLICABLE for the gratuity register (the First Schedule's ten, primary); a factory and a once-covered office (ten on one day in 2025) kept in** |
| 12 | An obligation whose legal status stays unverified | LBA (TN S&E wages), CALC (bonus), GRAT (gratuity under proposed Code versions) |

## 3. Calculations (CALC, GRAT, STAT, RULE, INV)

| Requirement | Test | Hand-computed expectation |
|---|---|---|
| Bonus wages at the ₹7,000 ceiling; 8⅓% / 20%; ineligible above ₹21,000; ceiling crossed; straddle; verified path; activation refused | CALC | As before (₹21,000 bonus wages; ₹1,750 / ₹4,200) |
| Gratuity, fixed-term v2 → v3 | CALC | FY 2025: pending; FY 2026: 8,000 × 15/26 × 2 = ₹9,230.77 |
| **Gratuity at exit under the Payment of Gratuity Act** | GRAT | Joined 1 Apr 2018, left 31 Oct 2025: 7 y 7 m → 8 years; 20,000 × 15/26 × 8 = ₹92,307.69; payable |
| **A part year counts only in excess of six months** | GRAT | 6 y 6 m exactly → 6 years; one day more → 7 |
| **Reasons of exit** | GRAT | 4 y 3 m resignation → not eligible; death after 2 y 8 m → eligible, 3 years, ₹34,615.38; 4 y 273 d → pending (240-day rule); termination → pending (forfeiture, PGA s.4(6)); "other" → pending (death or disablement?) |
| **Ceiling and missing wages** | GRAT | ₹7,00,000 a month → ₹20,00,000, capped; no wages → insufficient data, never "not eligible" |
| **Version by date; provisional is never payable** | GRAT | 31 Jan 2026 → v2, legally unverified, provisional ₹92,307.69, not payable; 27 Oct 2026 → v3; enforcement on → v1 unverified → legally unverified |
| **Fixed-term** | GRAT | v2 contract expiry → pending; v3, 1 y 7 m → 2 years, ₹23,076.92 provisional; v3 resignation → review "before the term ends" |
| **The Code's 50% wage rule shown, never applied** | GRAT | v3 stood up as verified: basic + DA ₹20,000 of ₹50,000 → pending, "at least 25,000.00, giving 129,807.69"; of ₹30,000 → eligible, payable, Code s.56(3) |
| **Coverage** | GRAT | Office of 1 → not covered; 9 → not covered; a tenth for four months in 2025 → covered, and still covered in 2026 (s.1(8)); not covered before; no establishment recorded → pending, primary not assumed |
| **Settlement lines** | GRAT | Pre-Code exit: GRATUITY line ₹92,307.69 with its rule; a hand-added gratuity line → no automatic one; Code-era retirement → no line, provisional ₹1,03,846.15 shown |
| PF / ESI challans from finalized payroll only | CRT, INT | An open October run never feeds the October PF challan; its warning says so |
| PT special month only on the top band | STAT | Maharashtra ₹175 band stays ₹175 in February |
| Rule immutability, evidence, maker-checker, proposed counts | RULE | 24 active + 11 proposed |
| Reports read-only, finalized payroll, no rate literals; layout locks include the checksum algorithm | INV | — |

## 4. Integrity of export layouts (D7, LAYOUT, GATE, MYSQL)

| Requirement | Test |
|---|---|
| Same logical data, different key order → same checksum; nested objects | D7 |
| Arrays: order significant | D7 (fields swapped → different checksum) |
| Null, Unicode, numbers, booleans, empty objects and lists stable through the database | D7 (round trip; 1.0 = 1 and {} = [] hash alike; every value change differs) |
| Historical rows under the original algorithm verify as before | D7 (stored key order unchanged → intact; seal not replaced) |
| A historical row broken only by key order: diagnosed, refused (D7 message), never verified, nothing changed | D7 |
| Invalid or corrupted checksums, unknown algorithm → mismatch | D7 |
| Cross-database behaviour | D7 on SQLite and MySQL; MYSQL asserts MySQL re-ordered the keys and every layout still verifies |
| Repeated verification writes nothing | D7 (no insert / update / delete statements; rows identical) |
| Concurrent verification | MYSQL: two checkers at once → one verification, one audit event (failed before the lock) |
| The read-only command | D7 (exit 0 with a key-order diagnosis; exit 1 on a real mismatch) |
| A database before the migration (algorithm column absent) | D7 (rows read as the original algorithm without an error; a canonical seal read that way fails closed) |
| The 9 Phase 6.2 tests | LAYOUT (4), GATE (9 incl. dataset) on MySQL and SQLite |
| Upgrade rehearsal (outside the test suite) | `d7upgrade` run on a disposable MySQL database: rows written by the old code are byte-identical after the migration, diagnosed `original_algorithm_unreproducible`, still accepted by sync |

## 5. Security (SEC, INT, CRT)

| Requirement | Test |
|---|---|
| Masking of UAN, ESIC IP number, PAN, Aadhaar, bank account; full values only with `compliance.sensitive.view`, audited | SEC |
| Another user, another tenant, a narrowed scope cannot download | SEC |
| No permission → no generation; build refused if permission lost before the job | SEC, CRT |
| PoSH counts withheld from non-handlers | SEC |
| Review rules (not the requester; permission; once; audited) | SEC |
| Duplicate request returns the queued pack; simultaneous requests queue one | SEC, MYSQL |
| **A pack file changed after it was built is refused, and no download is recorded** | INT |

## 6. Files and history (SEC, INT, CRT)

| Requirement | Test |
|---|---|
| Failing builder: item failed, BLOCKED, no file, failure reference, no exception text | SEC |
| ZIP equals the index; temporary directories removed; retry in place; zip-slip | SEC |
| **A built pack is never rebuilt: a later payroll change goes into a new pack; the first downloads as built** | INT |
| **An open (calculated, not finalized) run never feeds a statutory report** | INT |

## 7. Catalogue (CAT, VAR)

| Requirement | Test |
|---|---|
| Every label is one of the six; a sourced label cites a source; primary only on an official HTTPS host or a validated signature | CAT, VAR |
| Date ranges forwards; thresholds without overlaps; nothing verified after the review date | CAT |
| Every builder and all 36 states resolve; no rule data in the catalogue | CAT |

## 8. Not covered

- Browser rendering of the Compliance reports page and of the settlement's Gratuity section (Livewire tests cover the
  page; there is no visual test).
- Every one of the 188 catalogue entries built against real payroll; state forms' column layouts against each
  state's printed form (legal review, D6).
- PT and LWF in states without a rule version.
- Large-volume performance (thousands of employees per pack; gratuity coverage loads the company's employees once per
  establishment and period).
- The production queue worker (packs build on the `sync` queue in tests).
- Legal correctness of any rule: tests check that the product applies what the catalogue and rule pack say, not that
  those are right in law.

## 9. Results (2026-10-27, remediation pass)

Environment: PHP 8.5.4, Laravel 13.33, Pest; SQLite in memory (phpunit.xml) and MySQL 8.4.11 on disposable databases
created for these runs and dropped afterwards. Shell variables override the phpunit.xml `<env>`.

**Before the fix (reproduction).** Fresh MySQL database `peopleos_scratch_d7_20261027`:

```
DB_CONNECTION=mysql DB_DATABASE=peopleos_scratch_d7_20261027 php artisan test tests/Feature/Compliance/ExportLayoutTest.php tests/Feature/Compliance/ProductionGateTest.php
```

Result: 13 tests, 4 passed, **1 failed and 8 errors**. These are the nine reported failures:
- `ExportLayoutTest`: "publishes every layout…" failed at `checksumIntact()`; "verifies a layout…" errored.
- `ProductionGateTest`: "lets a fully verified configuration…" and the six "blocks on each missing condition" datasets
  errored, all with "The layout has no intact specification evidence".

**Focused runs after the fix:**

| Run | Database | Result |
|---|---|---|
| `ExportLayoutChecksumTest`, `ExportLayoutTest`, `ProductionGateTest` | SQLite | 22 passed |
| The same three files, `DB_CONNECTION=mysql` | MySQL (`peopleos_scratch_d7_20261027`) | **22 passed** (the nine former failures included) |
| `tests/MySql/ExportLayoutConcurrencyTest.php`, `PEOPLEOS_MYSQL_CONCURRENCY_DB=peopleos_concurrency_d7_20261027` | MySQL | Before the `verify()` lock: 1 of 2 failed (two verifications recorded). After: **2 passed** |
| D7 upgrade rehearsal (script, not a test) on `peopleos_scratch_d7_20261027` | MySQL | 5 layouts written as the old code wrote them stay byte-identical after migration `100004`, are diagnosed `original_algorithm_unreproducible`, and `sync` still accepts them |

**Final runs.** One stage at a time, on the final code and catalogue (revision `2026-10-27b`, SHA-256
`fc04ade1…72b3`):

| # | Command | Database | Tests | Passed | Failed | Skipped |
|---|---|---|---|---|---|---|
| 1 | `php artisan test tests/Feature/Compliance tests/Feature/Exit tests/Feature/Architecture/ComplianceInvariantsTest.php` (12:11–12:16) | SQLite | 157 | **157** | 0 | 0 |
| 2 | `php artisan test --parallel --processes=4` (12:16–12:34) | SQLite | 1,776 | **1,637** | **0** | 139 |
| 3 | `DB_CONNECTION=mysql DB_DATABASE=peopleos_scratch_final_20261027 php artisan test tests/Feature/Compliance tests/Feature/Exit/GratuityEntitlementTest.php` (12:34–12:45) | MySQL, fresh | 147 | **147** | 0 | 0 |
| 4 | `PEOPLEOS_MYSQL_CONCURRENCY_DB=peopleos_concurrency_final_20261027 php artisan test tests/MySql` (13:04–13:33) | MySQL, fresh | 139 | **139** | 0 | **0** |
| 5 | After a last model change (reading a row whose algorithm column is absent, below): the seven test files that touch export layouts (`ExportLayoutChecksumTest`, `ExportLayoutTest`, `ProductionGateTest`, `StatutoryIsolationTest`, `ProductionSeederTest`, `ComplianceInvariantsTest`, `ArchitectureTest`) | SQLite | 65 | **65** | 0 | 0 |
| 6 | The same seven files, `DB_CONNECTION=mysql DB_DATABASE=peopleos_scratch_final_20261027` | MySQL | 65 | **65** | 0 | 0 |
| 7 | `tests/MySql/ExportLayoutConcurrencyTest.php tests/MySql/ComplianceReportsConcurrencyTest.php` | MySQL | 4 | **4** | 0 | 0 |

The 139 skipped tests in run 2 are the MySQL-only suites (`tests/MySql`). They skip on SQLite and are **not** counted
as passes; run 4 is their result. Two of them are new in this pass (`ExportLayoutConcurrencyTest`).

Runs 5–7 followed the last code change. It made `StatutoryExportLayout::checksumAlgorithm()` read the attribute array, so a database that has not yet run migration `100004` reads its layouts as sealed with the original algorithm instead of throwing (strict models). It is covered by a new test in `ExportLayoutChecksumTest`. The full suites (runs 2 and 4) ran before it; the change touches only that model, the layout service and the read-only command, and every test that loads them was rerun.

**Other checks:**
- `composer audit` and `composer audit --locked`: no advisories.
- Pint `--test` on every PHP file changed in this pass, the catalogue included: passed.
- Static analysis: none configured in this project (no PHPStan, Larastan or Psalm).

**Earlier in the day** (first pass), for comparison: the full SQLite suite had 1,610 passed and 137 skipped; the
compliance folder on MySQL had 111 passed and 9 failed (the defect fixed here).

**Caveat: the working tree is shared.** Other sessions were changing files in the same working tree while these runs
took place. Files changed after 12:45 include:
- `ComplianceReports.php` (a new `operatingStates()` method);
- `ComplianceReportsPage.php`;
- `ComplianceControlRoom.php`;
- the establishment statutory profile;
- a new migration, `2026_11_10_100001`.

Those changes are not covered by these results. §10 covers them.

## 10. Stabilisation run on a frozen snapshot (2026-10-27)

Other sessions kept editing the working tree, so a run on the live tree tests no single state of it. This run tested
one fixed state instead.

**Method.**
- At 14:04:03 the files the suites read were copied, with their own `vendor`, into a session folder.
- Left out: `.git`, `node_modules`, storage data and the large docs folders (`docs/presentations`, `docs/handbook`,
  `docs/ux`, `docs/assets`).
- A SHA-256 manifest was written: 3,165 files, manifest digest `398950e2b2e2bf9b`.
- The live tree was re-hashed straight after the copy and matched, so the snapshot is one consistent state.
- The three stages ran one after another inside the snapshot.
- The MySQL stages used fresh databases created for the run and dropped after it (confirmed absent).
- Two parallel processes, not four. The machine had run out of memory earlier in the day, which ended the session.

| # | Command (time) | Database | Tests | Passed | Failed | Skipped | Assertions |
|---|---|---|---|---|---|---|---|
| 1 | `php artisan test --parallel --processes=2` (14:15–14:43) | SQLite | 1,789 | **1,650** | **0** | 139 | 23,099 |
| 2 | `DB_CONNECTION=mysql DB_DATABASE=peopleos_scratch_snap1410 php artisan test tests/Feature/Compliance tests/Feature/Exit tests/Feature/Payroll` (14:43–15:01) | MySQL, fresh | 208 | **208** | 0 | 0 | 2,860 |
| 3 | `PEOPLEOS_MYSQL_CONCURRENCY_DB=peopleos_concurrency_snap1410 php artisan test tests/MySql` (15:01–15:24) | MySQL, fresh | 139 | **139** | 0 | **0** | 849 |

All three stages exited 0.

**The 139 skipped tests in stage 1 are exactly the MySQL-only folder.**
- `php artisan test tests/MySql` on SQLite, in the snapshot, gives 139 tests and 139 skipped.
- The full run skipped 139 in total, so none of the three conditional skips elsewhere fired:
  - `ApiPlatformTest`'s tenant-status guard;
  - two AI-assistant guards in the Experience tests.
- Stage 3 is their result. Skips are not counted as passes.

**Covered here, not in §9.**
- Everything §9's caveat lists.
- Investment declarations, with migration `2026_11_10_100001`.
- Experience navigation and the theme CSS.
- The whole `Exit` and `Payroll` folders on MySQL.

**Changed after the snapshot** (live tree re-hashed at 15:25: 21 files changed, 36 added, none removed):
- No compliance, exit, payroll, migration or rule-pack file changed.
- The changes are another session's Analytics report library:
  - 32 new datasets, `ReportLibrary` and its page and view;
  - changes to the Analytics datasets, `ReportRunner`, `ListReports` and `ReportBuilderSchema`;
  - `ExperienceNavigation`;
  - `config/peopleos.php`, which gains Report Builder filter operators only, with no permission keys;
  - `phase-14-analytics.md`;
  - `ReportingTest`, `ReportLibraryTest` and `Ux15NavigationTest`.
- Two documentation edits from this pass.
- The Analytics changes are not covered by these results.

**Evidence.** The manifest, the per-stage output, the summary and the list of later changes are kept in
`/home/administrator/Documents/hrms/hcms-compliance-research-archive-20261027/test-runs/`, outside the repository.

## 11. Final blocker pass (2026-10-27)

### 11.1 C12: statutory profile history (`StatutoryProfileHistoryTest`, new, 11 tests)

| Brief item | Test |
|---|---|
| 1. Closing a profile after payroll finalisation | "refuses to close a profile before a finalised month and allows closing after it" |
| 2. Reopening it after payroll finalisation | "refuses to reopen a closed profile over a finalised month calculated without it, in the model and the page" (Livewire reopen action included) |
| 3. Attempting to delete the last profile | "refuses to delete an establishment's last profile when that would hand a finalised month to the company settings" (the profile's own window holds no finalised payroll, so only the new check catches it) |
| 4. Historical reports after profile changes | "keeps the report of a finalised month as payroll decided it, and lets later months follow a change" (`pf_challan` in the plan for September, before and after) |
| 5. Future periods after legitimate changes | Same test: closing from 31 Oct 2026 leaves September and October applicable and makes November not applicable; reopening after the last finalised month is allowed |
| 6. Effective-dated applicability | "decides by effective dates: a back-dated profile over a finalised month is refused unless it changes nothing there" |
| 7. Tenant and establishment isolation | "holds only the establishment and the tenant whose payroll is finalised, whoever makes the change": another establishment, a user who sees no employees, another tenant |
| 8. Audit records for each permitted change | "audits each permitted change with its reason and records nothing for a refused one" (before and after values of `effective_to`) |
| 9. Preservation of historical rule versions | "leaves the finalised run, its lines and its rule versions untouched by profile changes" |
| 10. The correction workflow | "lets payroll not yet past review follow a correction, then holds it once approved"; "does not hold the move from the company settings to establishment profiles (ADR-0001)" |
| Pack decides as payroll does | "decides a pack as payroll did when the company has no saved statutory settings" |

**Mutation check.** With the two `assertPayrollHistoryKept()` calls disabled, the file gave 10 tests, 1 passed, 9
failed, each with "Exception [RuntimeException] not thrown". The one that passed is the ADR-0001 test, which checks
that the guard does *not* block. The model was restored from a copy, and its SHA-256 was compared with the edited
file's.

### 11.2 Focused runs on the live tree (code unchanged by others since 15:01)

| Command | Database | Tests | Passed | Failed | Skipped |
|---|---|---|---|---|---|
| `php artisan test tests/Feature/Compliance/StatutoryProfileHistoryTest.php` | SQLite | 11 | **11** | 0 | 0 |
| `EstablishmentStatutoryTest`, `ComplianceReportsTest`, `ApplicabilityScenariosTest`, `LocationBasedApplicabilityTest`, after the change | SQLite | 29 | **29** | 0 | 0 |
| `php artisan test tests/Feature/Compliance tests/Feature/Payroll tests/Feature/Exit tests/Feature/Architecture` (16:30–16:44) | SQLite | 360 | **360** | 0 | 0 |
| `DB_CONNECTION=mysql DB_DATABASE=peopleos_scratch_c12_20261027 php artisan test` with `StatutoryProfileHistoryTest`, `EstablishmentStatutoryTest`, `ComplianceReportsTest`, `ApplicabilityScenariosTest`, `LocationBasedApplicabilityTest` and `ComplianceReportCalculationTest` (16:32–16:41) | MySQL, fresh | 45 | **45** | 0 | 0 |
| `php artisan test tests/Feature/Analytics tests/Feature/Experience/Ux15NavigationTest.php` (16:45) | SQLite | 22 | **22** | 0 | 0 |
| `DB_CONNECTION=mysql … php artisan test tests/Feature/Analytics` (16:46–16:49) | MySQL | 12 | **12** | 0 | 0 |

The MySQL run matters for the guard: it queries `inputs->statutory_context->profile_source`, a JSON path, which
behaves differently on the two engines.

### 11.3 Final runs: two interrupted attempts

The machine stopped twice during the final runs. Neither stop came from the code under test.

- **16:49 snapshot.** Stopped by an orderly system restart at 17:35. Its logs were in `/tmp`, which is in RAM, and were
  lost. Table timestamps in its databases show stages 1–2 had finished and stage 3 had begun. With no logs, no result
  is claimed for it. Its two databases were confirmed to hold only test data, re-registered and dropped.
- **17:42 snapshot** (`snap-final2`; logs on disk from then on).
  - Stage 1: 1,808 tests, 1,668 passed, **1 failed**, 139 skipped. The failure is `Ux15FoundationTest`, explained
    under §12.
  - Stage 2 was cut off: the laptop slept from 18:41 to 19:42 (the system journal is silent), then the display
    driver failed and the machine was restarted.
  - Its databases were dropped.

Lessons, now built into `run-final.sh`:
- logs, manifests and the database ledger are written to disk;
- the run holds an idle-sleep inhibitor (`systemd-inhibit --what=idle`).

## 12. Final pass (owner-approved directions, 2026-10-27)

**First attempt: snapshot `snap-final3`** (19:52:40; 3,227 files; manifest digest `83b26ec1282ec83d`).
- Stage 0 (catalogue check): passed.
- Stage 1 (SQLite): 1,819 tests, 1,679 passed, **1 failed**, 139 skipped. The failure is explained below.
- Stage 2 (MySQL) was stopped after 2 h 20 min. The symptoms:
  - since MySQL started, 7,370 `START TRANSACTION`, 7,369 `COMMIT`, **1** `ROLLBACK`, and 21,306 tables created;
  - so the test transactions were not being rolled back, and the whole schema (about 350 tables) was rebuilt before
    every test.
- **Diagnosis:** one test file run alone gave 9 tests, 0 passed, 9 errors, with 3,159 tables created.
- **Root cause: a defect in this pass's migration.**
  - The index name `statutory_profile_corrections_tenant_id_establishment_id_status_index` (70 characters) is over
    MySQL's 64-character limit.
  - So `migrate:fresh` failed on MySQL, and every test retried it and errored. SQLite accepts the name, which is why
    every SQLite run passed.
- **Fixed** with an explicit name, `stat_profile_corrections_lookup_idx`.
- **A second MySQL-only defect, found on the next run:** `StatutoryProfileCorrections` compared the stored
  `original` snapshot with `!==`. MySQL's `json` column re-sorts keys, the same cause as D7, so 5 tests reported "the
  profile changed".
  - **Fixed:** keys are sorted before a strict comparison.
- **Verified:**
  - `StatutoryProfileCorrectionTest`, `StatutoryProfileHistoryTest`, `ExportLayoutChecksumTest` and
    `GratuityEntitlementTest` on a fresh MySQL database: 41 / 41;
  - one schema build, 41 rollbacks;
  - log `test-runs/c12w-d7-mysql-2.txt`.
- All the attempt's databases (two run databases and three probe databases) were dropped and confirmed absent.

**Final run: snapshot `snap-final4`** (logs `test-runs/snap-final4-*`). 
- Taken 23:05:06: 3,228 files, manifest digest `cdf0a4971d00b857`. The live tree matched it after the copy.
- It includes both MySQL fixes.
- During the run, only two documentation files changed in the live tree: this matrix and the decisions document.
- Both databases were dropped and confirmed absent, and the snapshot copy was removed.

| # | Command | Database | Tests | Passed | Failed | Skipped | Exit |
|---|---|---|---|---|---|---|---|
| 0 | `tools/compliance-catalogue/check.sh` | — | 3 files | 3 identical | 0 | — | 0 | |
| 1 | `php artisan test --parallel --processes=2` | SQLite | 1,819 | 1,679 | **1** | 139 | 1 | |
| 2 | `DB_CONNECTION=mysql DB_DATABASE=peopleos_scratch_snapfinal4_20261027 php artisan test tests/Feature/Compliance tests/Feature/Exit tests/Feature/Payroll tests/Feature/Analytics` | MySQL, fresh | 242 | **242** | 0 | 0 | 0 | |
| 3 | `PEOPLEOS_MYSQL_CONCURRENCY_DB=peopleos_concurrency_snapfinal4_20261027 php artisan test tests/MySql` | MySQL, fresh | 139 | **139** | 0 | **0** | 0 | |
| 4 | `php artisan test tests/MySql` (to show what stage 1 skips) | SQLite | 139 | 0 | 0 | 139 | 0 | |
| 5 | `vendor/bin/pint --test` (whole repository, as CI runs it) | — | whole repository | passed | 0 | — | 0 | |
| 6 | `composer audit --locked --no-interaction` | — | — | no advisories | 0 | — | 0 | |

**What the final run shows.**
- **Stage 1's 139 skips** are exactly `tests/MySql`: stage 4 runs that folder on SQLite and skips all 139, and stage
  3 runs them on MySQL with 139 passing.
- **Stage 1's one failure** is outside compliance (below). No compliance, payroll, exit, C12, C12-W, D7 or Analytics
  test failed on either engine.
- **The full suite is therefore not green.** That is reported as a FAIL, not as a pass with an exception.

**The design-system failure** (stage 1 of every final attempt) is `Ux15FoundationTest`, "keeps the design system governed: tokens only, and no text
below 12 px".
- It finds `border-radius: 999px;` in `resources/css/filament/admin/theme.css`, where the design system allows only
  tokens.
- Another session added that line after 14:04, in its rail-handle and page-chrome work; the file was last changed at
  18:24.
- It is a real breach of the design-system rule, not an environment problem.
- It is outside compliance and was not changed here, because the file belongs to another active session.
- No compliance, payroll, exit, C12 or C12-W test failed.

**Analytics tests executed** (stage 1 on SQLite, stage 2 on MySQL): the whole `tests/Feature/Analytics` folder,
12 tests.
- `ReportingTest` (5):
  - exposes datasets by permission and drops sensitive fields for users without it;
  - lists, filters, calculates, groups, sorts, limits and charts;
  - exports CSV, records runs, and runs due schedules with notifications;
  - computes workforce metrics and series, and resolves dashboards per viewer;
  - provisions starter reports and the default dashboard.
- `ReportLibraryTest` (7):
  - builds every library template from real fields and runs it for an HR admin;
  - rolls attendance up per employee and month, and lists late coming, missed punches and leave;
  - masks statutory identifiers and bank accounts and keeps them behind the sensitive permission;
  - keeps child rows of unscoped models inside the viewer access scope and users inside the tenant;
  - filters on relative dates, financial years and exclusion lists;
  - creates a report from a template, optionally for one employee, within the user rights;
  - renders the report library and turns a template into a report.
- `Ux15NavigationTest`, which the Analytics change touched, ran in stage 1.

**Focused runs during the pass** (live tree, before the snapshot; logs in `test-runs/`):

| Run | Database | Result |
|---|---|---|
| `ExportLayoutChecksumTest`, `ExportLayoutTest`, `ProductionGateTest` after D7 option B | SQLite | 24 / 24 |
| `peopleos:compliance:layout-checksums` on the development database (read-only) | MySQL `hcm` | 5 / 5 `intact_against_pack`, exit 0; the evidence re-run is byte-identical to the earlier capture |
| `StatutoryProfileHistoryTest`, `EstablishmentStatutoryTest`, `ArchitectureTest` after C12-W | SQLite | 45 / 45 |
| `StatutoryProfileCorrectionTest`, first run | SQLite | 8 / 10. One test expectation was wrong (the audit action is an enum). The other failure was a real gap: an approved correction let an equivalent but different change through. The guard now matches the exact approved change |
| `StatutoryProfileCorrectionTest`, `StatutoryProfileHistoryTest` after the fix | SQLite | 21 / 21 |
| Mutation: `approvedCorrectionCovers()` always true | SQLite | 10 / 21 passed, 11 failed (as intended); restored, checksum compared |
| C8 `check.sh`: clean, a one-byte catalogue edit, a hand edit to a table, a missing research note | — | Exit 0 / 1 / 1 / 2; the working tree was unchanged |
| Pint on every changed PHP file | — | Passed, after removing one unused import |

## 13. Follow-up review (2026-10-28)

**Code under test.**
- Identical to `snap-final4` except one line of `resources/css/filament/admin/theme.css`.
- Before that edit, the live tree was re-hashed against the `snap-final4` manifest: only three documentation files
  differed.
- So `snap-final4`'s results (§12) stand for all backend code, MySQL included, since the CSS line affects no backend
  or MySQL test.

**Read-only re-verification** (logs in `test-runs/`):

| Check | Result | Log |
|---|---|---|
| D7: `peopleos:compliance:layout-checksums` on the development database | 5 / 5 `intact_against_pack`, exit 0 | `d7-dev-verification-2026-10-28.txt` |
| D7: evidence script re-run | Byte-identical to the original capture | `d7evidence-output-2026-10-28.txt` |
| C8: `check.sh` clean, a one-byte catalogue edit, a missing research note | Exit 0 / 1 / 2; working tree unchanged | `c8-verification-2026-10-28.txt` |
| C8: CI job | Present in the uncommitted `ci.yml`; **never run** (no commit authorised) | — |

**CSS failure.**
- `Ux15FoundationTest` "keeps the design system governed: tokens only, and no text below 12 px",
  `tests/Feature/Experience/Ux15FoundationTest.php`:
  - line 47: no `border-radius` in px other than 1px;
  - line 49: no hex colour except `#000`.
- The breaches are in another session's uncommitted rail-handle and back-to-top blocks.

| Run | Result | Log |
|---|---|---|
| After replacing `border-radius: 999px` with `var(--pos-radius-pill)` (an identical value) | 3 / 4. The test now fails at line 49: `#7366e6`, `#463ba6`, `#fff` | `css-fix-targeted-2026-10-28.txt` |
| A disposable copy with the proposed token patch applied | 4 / 4 | `css-proposed-patch-test.txt`; patch `proposed-theme-css-iris-tokens.patch` |

**Analytics read API.**

| Run | Result | Log |
|---|---|---|
| Proposed test on a disposable **unpatched** copy | Fails: 200 instead of 403. The gap is reproduced | `analytics-api-gap-unpatched.txt` |
| A disposable **patched** copy: the proposed test, `ReadApiTest`, the Analytics folder and the AI tests | 25 / 25 | `analytics-api-gap-patched.txt`; patch `proposed-analytics-unattended-datasets.patch` |

**Fresh full SQLite run after the CSS line** (`generator/run-sqlite.sh snap-sqlite5`, under an idle-sleep
inhibitor).
- Snapshot taken 2026-10-28 00:31:28: 3,229 files, manifest digest `5379ec7dc650be7a`. The live tree matched it after
  the copy, and **no live file changed during the run**.
- Logs: `test-runs/snap-sqlite5-*`.

| # | Command | Database | Tests | Passed | Failed | Skipped | Exit |
|---|---|---|---|---|---|---|---|
| 0 | `tools/compliance-catalogue/check.sh` | — | 3 files | 3 identical | 0 | — | 0 |
| 1 | `php artisan test --parallel --processes=2` | SQLite | 1,819 | 1,679 | **1** | 139 | 1 |

- **The one failure:** `Ux15FoundationTest` line 49, raw hex colours `#7366e6`, `#463ba6`, `#fff` in another
  session's iris button. The radius breach (line 47) no longer appears. The proposed token patch makes the test pass
  on a copy.
- **No other test failed.**
- **The 139 skips** are `tests/MySql`, which ran 139 / 139 on MySQL in `snap-final4` (its code is unchanged since).

## 14. Remediation pass (2026-10-28)

Logs are in `hcms-compliance-research-archive-20261027/test-runs/`. The discovery record is
`phase1-discovery-2026-10-28.txt`.

### 14.1 Analytics read API (`ReadApiReportAccessTest`, new, 9 tests)

| Run | Result | Log |
|---|---|---|
| New tests plus `ReadApiTest`, live tree | 10 / 10 | `analytics-fix-targeted-1.txt` |
| New tests on a disposable copy with the original `ReadController` and `ReportRunner` | 6 of 9 fail: 5 failures and 1 error, because API runs were not audited. The 3 that pass cover checks that already existed (credentials, `reports.run`, tenant) | `analytics-fix-mutation-original-code.txt` |
| Analytics, Enterprise, AI, architecture, access-scope, hygiene, intelligence, hardening and cross-tenant tests | 96 / 96 | `analytics-fix-related-sqlite.txt` |

### 14.2 Payroll finalisation (`PayrollFinalisationIntegrityTest`, new, 6 tests)

| Run | Result | Log |
|---|---|---|
| Payroll, EPF, TDS, statutory updates, C12 and C12-W, compensation and payroll-page tests | 77 / 79: two errors from the page check (the tenant context was cleared), fixed in the test | `payroll-fix-targeted-1.txt` |
| `PayrollFinalisationIntegrityTest` after that fix | 6 / 6 | `payroll-fix-targeted-3.txt` |
| New tests on a disposable copy with the original payroll files | 6 failed. Four on substance (reopen, direct changes, stale copy, replaced-results audit); two on the new message wording only (paid month and recalculation were already refused) | `payroll-fix-mutation-original-code.txt` |

**Three existing tests changed with the behaviour:**
- `PayrollRunTest` now asserts that a finalised and a paid run are refused, and that nothing changes.
- `EpfReturnTest` (revised ECR) and `TdsComplianceTest` (ledger supersession) build the changed month at the data
  level with `returnFinalizedRunToDraftForTest()` (`tests/Feature/Payroll/PayrollHistoryTestHelpers.php`), so the
  revision logic stays covered.

**MySQL races** (`tests/MySql/PayrollFinalisationConcurrencyTest.php`): finalise vs return to draft, and two applies
of approved corrections of the same profile. Run in `snap-final5` stage 3.

### 14.3 Final run on a frozen snapshot (`generator/run-final5.sh snap-final5 final5`)

- Snapshot taken 2026-10-28 08:35:23: 3,234 files, manifest digest `e689bd3afbd3ba86`. The live tree matched it after
  the copy, and **no live file changed during the run**.
- Both disposable databases were dropped afterwards; the ledger is empty.
- Run under an idle-sleep inhibitor. Logs: `snap-final5-*`.

| # | Command | Database | Tests | Passed | Failed / errors | Skipped | Exit |
|---|---|---|---|---|---|---|---|
| 0 | `tools/compliance-catalogue/check.sh` | — | 3 files | 3 identical | 0 | — | 0 |
| 1 | `php artisan test --parallel --processes=2` | SQLite | 1,838 | 1,696 | **1** failed | 141 | 1 |
| 2 | Compliance, Exit, Payroll, Analytics, Enterprise | fresh MySQL `peopleos_scratch_final5` | 268 | 267 | **1** error | 0 | 2 |
| 3 | `tests/MySql` | MySQL `peopleos_concurrency_final5` | 141 | 141 | 0 | 0 | 0 |
| 4 | `tests/MySql` without a MySQL database | SQLite | 141 | 0 | 0 | 141 (by design) | 0 |
| 5 | `vendor/bin/pint --test` | — | — | pass | — | — | 0 |
| 6 | `composer audit --locked` | — | — | no advisories | — | — | 0 |

- **Stage 1, the one failure:** `Ux15FoundationTest` line 49, the same raw hex colours in another session's theme.
  The token patch is not applied, because the theme owner's approval is missing. It still applies cleanly to the
  current file.
- **Stage 2, the one error:** `EpfReturnTest` "keeps returns inside the company scope and away from managers and other
  establishments", in `EstablishmentAssignments` ("The establishment belongs to a different company…").
  - **Pre-existing and order-dependent,** not caused by this pass. `employeeWithUser()` takes
    `Company::query()->first()` with no order. On MySQL the `(tenant_id, code)` index may return the second company
    first, because factory codes are random.
  - **Shown on unchanged code:** 4 passed and 2 failed in six runs on a disposable database
    (`epf-company-order-flake-mysql.txt`).
  - **Fix proposed, not applied:** `proposed-employee-helper-company-order.patch`. It is a shared test helper outside
    this work.
- **No other test failed.**
