# Compliance reports

Written 2026-10-26 and revised 2026-10-27, after the review, verification and correction pass and the remediation
pass that followed it. Describes what is
implemented. The research, gaps and open legal questions are in `docs/compliance-research/`.

Compliance → **Compliance reports** produces, for a company and a period, the registers, challan workings,
statements, return working sheets and internal checks that fall due, central and state-wise, for each establishment,
state and legal entity. HR can download any single report as an Excel file, or generate all of them at once as a ZIP.

These are working papers and registers, **not filings**. Generating a report does not file, pay or submit anything.
Returns are still generated, approved, exported and filed through the governed statutory returns (Compliance → EPF /
ESI / PT / LWF / TDS returns) and on the authorities' portals. Every page, workbook header and pack index says so.

## Where things are

| Piece | Path |
|---|---|
| Catalogue (data) | `database/data/compliance/reports/in.php`, revision `2026-10-27b` |
| Catalogue reader | `app/Domain/ComplianceReports/Support/ReportCatalogue.php` (singleton; `canonicalState()`, `checksum()`) |
| Period model | `Support/ReportPeriod.php` |
| One obligation | `Support/ReportDefinition.php` (`resolveFor()`, `thresholdFor()`, `inForceDuring()`, `dueOn()`) |
| Form and sourcing for one establishment and period | `Support/ResolvedForm.php` |
| Shared, memoised reads | `Support/ReportData.php` |
| Context passed to a builder | `Support/ReportContext.php` |
| Output | `Support/ReportSheet.php` → `Support/XlsxWorkbook.php` (OpenSpout) |
| Builders | `app/Domain/ComplianceReports/Builders/*`, wired in `peopleos.compliance.report_builders` |
| Plan, single report, pack, download, review | `Services/ComplianceReports.php` |
| Background pack build | `Jobs/GenerateComplianceReportPack.php` (`ShouldBeUnique`, tenant-aware) |
| Pack record | `compliance_report_packs` → `Models/ComplianceReportPack.php`, `Policies/ComplianceReportPackPolicy.php` |
| Screen | `app/Filament/Pages/ComplianceReportsPage.php`, `resources/views/filament/pages/compliance-reports.blade.php` |
| Establishment facts | `establishments.appropriate_government`, `ss_appropriate_government`, `industry` (migrations `2026_11_09_100002`, `_100003`) |
| Gratuity coverage, eligibility and amount (shared with full and final settlements) | `app/Domain/Compliance/Services/GratuityEntitlements.php`, `Support/GratuityDetermination.php` |

The catalogue lives in its own folder because `ComplianceRules::sync()` loads every `database/data/compliance/*.php`
file as a statutory rule pack.

## Why a separate domain

The statutory-returns domain (`app/Domain/Compliance`) reads only finalized payroll and never touches Attendance or
Leave (an architecture test enforces this). Registers such as the muster roll, the leave register and the register of
women employees need attendance and leave data, so they live in `ComplianceReports`.

It never writes. `ComplianceInvariantsTest` checks that builders and support classes:
- make no create, update, delete or save calls;
- read payroll only through the finalized-run helpers (the salary variance check's `latestRun` is the one exception);
- contain no statutory rate literals.

## Periods

`ReportPeriod` keys are stable strings, stored on packs:

| Period | Key |
|---|---|
| Month | `M:2026-09` |
| Quarter | `FY:2026:Q2` / `CY:2026:Q3` |
| Half-year | `FY:2026:H1` / `CY:2026:H2` |
| Year | `FY:2026` / `CY:2026` |

`FY` years start in `peopleos.compliance.financial_year_start_month` (April); `CY` years in January. An obligation
can follow its own `basis` (calendar: unified annual return, leave register, PoSH, holidays, most LWF; financial: TDS,
bonus, the Employment Information Return).

**What falls due.** A report of frequency F falls in a pack when F is no longer than the pack's frequency. It is then
generated for each period of F, on the report's basis, that *ends* inside the pack's period (`periodsEndingWithin`).
A quarter pack holds each monthly report once per month. Without "include monthly reports", only reports of the
pack's own frequency are generated. `register` entries are generated for the whole period asked; `event` entries and
entries without a builder appear on the checklist only.

## Location-based applicability

`plan()` turns the catalogue into items: one report, for one target, over one or more periods. Applicability is
decided **per target and per sub-period**. A monthly report inside a year pack is decided month by month, so a form,
threshold or obligation that changes partway through the year is applied from its own date.

| Scope | Generated for |
|---|---|
| `establishment` | Each active establishment in the requester's scope. State entries go only to establishments whose own `state` matches |
| `state` | Each state found on payroll lines for the period (PT and LWF), plus establishment states |
| `legal_entity` | Each legal entity with a TAN (TDS) |
| `company` | The company once (salary variance, PoSH) |

For each target and period the decision uses, in order:

1. **The obligation's own effective window** (`effective_from` / `effective_to`). Example: Form 24Q to 31 Mar 2026,
   Form No. 138 from 1 Apr 2026; ER-I to 7 May 2026, Form XXVI from 8 May 2026.
2. **The establishment's state.** Never the head office's. An establishment without a state gets no state
   obligations and a configuration gap ("State").
3. **The establishment's type** (`establishment_types`). A factory is not given shops-and-establishments registers.
   A missing type is a configuration gap.
4. **The appropriate government, per Code.**
   - `appropriate_government` applies under the Code on Wages and the OSH Code.
   - `ss_appropriate_government` applies under the Code on Social Security. Under s.2(3)(a), an establishment with
     branches in more than one state can be Central for social security and State for wages.
   - Each catalogue entry names its Code (`code`: `wages` by default, `ss` for the register of women employees, the
     gratuity register and the Employment Information Return). `ReportDefinition::appropriateGovernment()` reads the
     matching field for the entry's sphere test and its variants.

   Form variants are matched by sphere, by state and by the period end. Central from 8 May 2026 gives Forms I / IV /
   IX / XX / XXII. Central before that gives Forms A–E (Ease of Compliance Rules, 2017).
   - **State, where the state's final Code rules are in force**, gives the state's own form from the rules' effective
     date. Wages registers: AP, BR, LA, GJ, AR, SK, LD, RJ, UP, UK. OSH leave register and annual return: AP, GJ, RJ,
     AR, UP, LA, LD, JK, MZ. SS women register: AP, AR, BR, GJ, LA, UP. Example: Rajasthan's employee register is
     Form IV from 12 Aug 2026.
   - **State otherwise** (24 states and UTs have draft rules only) gives a PeopleOS layout with the particulars of
     the central form, under legal review: the earlier Acts' rules and registers continue.
   - **Unknown** means configuration required, with the action shown.
5. **The statutory profile and registrations** (`requires`, `registration`): EPF, ESI, PT, LWF, TAN.
   - `requires` is decided by `StatutoryContexts::applicability()` on the period end, the same function payroll
     uses: the establishment's profiles, or the company statutory settings (defaults if none are saved).
   - A profile change cannot alter that answer for a month whose payroll is approved or finalised (C12), so a pack
     generated later agrees with the payroll it reads.
6. **Dated thresholds**, measured as the law measures them.
   - `employed_any_day`: the highest count on any day of the period (sweep line).
   - `employed_period_end`: the count on the last day.
   - `employed_during`: anyone employed during the period.

   - `gratuity_coverage`: the First Schedule's own test for gratuity (Chapter V and s.1(8)), decided by
     `GratuityEntitlements::coverage()`. Factories, and establishments whose industry is mining or plantation, are
     covered whatever their size. Others are covered from ten employees on their busiest day up to the period end,
     and once covered, always. Employees are counted by their establishment assignment (else their position's
     location), whoever is asking.

   A threshold that is not primary-verified never excludes on its own. The item becomes `PENDING_VERIFICATION`, is
   still generated, and its reason states the count ("PeopleOS counts 12"). The gratuity threshold is the one
   primary-verified threshold, so an office that has never had ten employees is `NOT_APPLICABLE` for the gratuity
   register.

**Determination** (one word per item, with the reason, legal basis, sources, effective window and source confidence
kept on the item, the manifest and the pack index):

| Determination | Meaning |
|---|---|
| `APPLICABLE` | Applies, and the official primary text was read with no open legal review |
| `NOT_APPLICABLE` | Excluded by a verified fact (type, sphere, profile, a verified threshold, effective window), with the reason |
| `PENDING_VERIFICATION` | Applies or may apply, but the source is only partly verified, conflicting or under legal review, or the threshold that would exclude it is not verified |
| `MISSING_CONFIGURATION` | A fact needed to decide is not configured (state, type, appropriate government). Nothing is assumed |
| `LEGALLY_UNVERIFIED` | No official source behind the obligation (unverified or secondary only) |

**Configuration gaps** (`configurationGaps()`) are listed on the page, in the pack index and in the audit record:
- an establishment without a state, a type or either appropriate government;
- employees attributed by fallback (no establishment assignment). These are reported under the establishment the
  fallback chose, with a "Data gap" warning in each affected report. They are never silently moved to the head office.

**Employees and transfers.** `EmployeeEstablishmentAssignment` is effective-dated. A transfer closes the old
assignment and opens the new one.
- Registers attribute an employee to the establishment of their assignment on their last day in the period.
- Payroll entries carry the establishment recorded when they were calculated, and `inputs.statutory_context.
  establishment_source` says how it was found.
- PT and LWF follow the state on each payroll line (`basis.state`). An employee moved from Maharashtra to Karnataka
  on 1 July is in Maharashtra's June PT statement and Karnataka's July one.

## Statutory rule versions

Rates, ceilings and slabs live only in versioned compliance rules (`database/data/compliance/in.php`), never in the
catalogue or the builders.

- `ComplianceRules::resolve()` returns only `status = active` versions. Payroll uses nothing else.
- Versions prepared for review carry `status = proposed`. `ComplianceRules::sync()` inserts them, never activates them
  and never rewrites a stored version (immutable checksums).
- Only `RuleVerifications::verify()` brings a proposed version into force, after a second platform administrator
  checks complete official evidence. It refuses while any parameter's coverage is "NOT CONFIRMED".
- Reports use `resolveWithProposed()`. Where only a proposed version exists (bonus; gratuity under the Codes; Karnataka
  and Haryana LWF), the report computes **provisionally**. Every result is labelled legally unverified, with the
  version named.
- Every sheet records the rule versions it used ("Calculated with: …"). The pack metadata keeps them, with the payroll
  runs read, the catalogue revision and checksum, and the scope fingerprint.

Eleven versions are proposed (2026-10-26): BONUS v1 and v2, GRATUITY v2 and v3, LWF KA v2, LWF HR v2–v5, PT WB v2 and
PT KL v2. See `docs/compliance-research/statutory-rule-change-register.md`.

## Statuses of one report

| Dimension | Values |
|---|---|
| applicability | APPLIES, NOT_APPLICABLE, CONFIGURATION_REQUIRED, PENDING_VERIFICATION |
| determination | see above |
| data | COMPLETE, INCOMPLETE (data-gap warnings: no finalized payroll, unprocessed attendance days, missing registrations or identifiers, fallback attribution) |
| generation | GENERATED, EMPTY, failed (in the manifest) |
| calculation | VERIFIED (every rule version used is verified), UNVERIFIED, NOT_APPLICABLE (no rule used) |
| legal | LEGAL_SOURCE_VERIFIED, LEGAL_REVIEW_REQUIRED, UNVERIFIED, NOT_STATUTORY (internal controls) |
| readiness | BLOCKED, CONFIGURATION_REQUIRED, INCOMPLETE, UNVERIFIED, LEGAL_REVIEW_REQUIRED, LEGAL_SOURCE_VERIFIED, INTERNAL_CONTROL, NOT_APPLICABLE |
| review | pack-level: reviewed by, at, note |
| filing | where the employer files or pays, outside PeopleOS |

Internal controls (`obligation = internal_control`) include the salary variance, joiners and leavers, ESI
contribution-period summary and equal remuneration checks. They never read as "legal source verified".

## Single report and pack

**Single report.** `single()` builds one item synchronously as an `.xlsx`. It audits an EXPORT on the company with the
item, period, statuses and whether identifiers were unmasked. Throttled to 30 a minute per user.

**Pack.** `request()` stores a pack and queues `GenerateComplianceReportPack`.
- An identical request already queued or building is returned instead of a duplicate. The check and insert run
  under a row lock on the requester's user row, so two simultaneous requests (a double click, two tabs) queue one
  pack. This is proven on MySQL by `tests/MySql/ComplianceReportsConcurrencyTest.php`: without the lock, three
  racing requests create three packs.
- Packs are throttled to 20 an hour per user.
- `retry()` rebuilds a failed pack in place: same record, same path.

The job's `build()`:
1. Re-checks that the requester still holds `compliance.reports.generate` (and `compliance.sensitive.view` for an
   unmasked pack), then runs as the requester, so their access scope applies to every query.
2. Writes one workbook per item into `Establishments/…`, `States/…`, `Legal entities/…` or `Company/`.
   - Names are sanitised: separators, control and reserved characters become spaces, and runs of dots collapse, so
     no entry can climb out of its folder.
   - Work happens in a `0700` temporary directory that is removed afterwards.
3. Adds `00 Index.xlsx`: Reports (every status per item), Configuration (gaps), Rules (versions used), Checklist
   (kept outside PeopleOS) and About.
4. Checks that the ZIP holds exactly the files the index lists. It then streams the ZIP to the compliance disk at a
   deterministic path.

A builder that throws marks only its own item as failed, with readiness BLOCKED, no file and a failure reference. The
exception is logged (database errors without bindings); its message never reaches the manifest or the index.

## Access

| Permission | Allows |
|---|---|
| `compliance.reports.view` | The page and the packs list (company scope) |
| `compliance.reports.generate` | Single reports, packs, retry of one's own failed pack |
| `compliance.reports.review` | Recording the authorised review of a completed pack (never one's own) |
| `compliance.sensitive.view` | Unmasked identifiers |

- Tenant HR Admin gets these through `compliance.*`. Payroll Admin gets `compliance.reports.view` and
  `compliance.reports.generate`. Run `php artisan peopleos:sync-permissions` after deploying.
- **Download** (`canDownload()` / `downloadRefusal()`) is limited to the requester. It also needs the generate
  permission now, and the sensitive permission for an unmasked pack. The requester's current access scope must
  still match the fingerprint taken at generation (sha256 of `AccessScopes::for`). A pack built with wider access
  cannot be downloaded after access is narrowed. Packs of other tenants are invisible (tenant scope).
- **Identifiers** (UAN, ESIC IP number, PAN, Aadhaar, bank account, registration numbers) are masked to the last
  four characters unless the requester holds `compliance.sensitive.view` and asks for them in full. The choice is
  stored on the pack and audited.
- **PoSH.** The Internal Committee report counts cases only for members of the PoSH category's handler roles (or,
  where none are set, holders of `grievance.manage`). For anyone else the counts are withheld with a warning. It never
  shows case details.
- Generation, download, retry and review are audited. `compliance_report_packs` is in the data inventory under
  `statutory`, with its file.

## Data rules

- **Payroll.** Read only from FINALIZED or PAID runs. The salary variance check reads the newest calculated run of
  each month (`latestRun`) and prints the run status, because it is a pre-finalization check.
- **Bonus register.** Each month is measured under the BONUS version in force at that month's end. FY 2025-26
  straddles the Payment of Bonus Act and the Code on Wages; the sheet says so and asks for legal review. Statuses:
  - `ELIGIBLE` and `INELIGIBLE` need a verified version in force.
  - `PENDING_DETERMINATION`: wages crossed the ₹21,000 ceiling during the year, or the calculation ceiling depends on
    a minimum wage PeopleOS does not hold.
  - `INSUFFICIENT_DATA`: no basic or DA in finalized payroll.
  - `LEGALLY_UNVERIFIED`: the version is proposed or unverified, so the determination is shown as provisional.
- **Gratuity register.** Each row is `GratuityEntitlements::accrued()`, the calculator settlements use at exit (see
  below), on the employee's service to the year end or exit. Fixed-term employees (employment type containing
  "fixed") use `fixed_term_minimum_years` where the version states it (v3, SS (Central) Rules r.33, from 8 May 2026).
  Where it does not (v2), the status is pending determination. A part year counts only when it is in excess of six
  months.
- **PF challan** splits EDLI out of `PF_ADMIN` with the EDLI rate of the rule version recorded on the line.
- **Haryana LWF** in the statutory engine supports rate-and-cap versions (`employee_rate`, `employee_cap`,
  `employer_multiplier`), so activating the proposed versions would not compute ₹0.
- **PT special months** (`february_amount`, `march_amount`) replace the top, open-ended band's amount only.
  Maharashtra's ₹175 band stays ₹175 in February (Mah. Act XIX of 2023). This was corrected in `StatutoryEngine`
  during this review.

## Gratuity at exit

`GratuityEntitlements::onExit()` decides gratuity for a full and final settlement. `FinalSettlements::calculate()`
stores the determination in `inputs.gratuity`, and the settlement screen shows it in a Gratuity section.

1. **Version for the date.** The GRATUITY version that governs the last working day. A proposed version prepared for
   a later legal position than the one in force governs provisionally: v2 from 21 Nov 2025, v3 from 8 May 2026,
   while v1 (Payment of Gratuity Act) is in force. A version decides only when it is in force, carries no open notice
   and, while enforcement is on (always in production), is verified.
2. **Coverage.** The establishment the employee belonged to on the last working day, by assignment or location.
   The company's primary establishment is never assumed: without one, the result is pending determination.
3. **Qualifying service.** Completed years against `minimum_years` (five), or `fixed_term_minimum_years` for
   fixed-term employees where the version states it. It is waived on death.
4. **Amount.** Last drawn monthly basic + DA × `rate` × 12 × years counted, up to `ceiling`. A part year counts only
   when it is in excess of six months.
5. **Reviews.** These make an otherwise eligible result pending determination:
   - termination or absconding, for forfeiture (Code s.53(6) / PGA s.4(6));
   - "other" exits, to record death or disablement;
   - a year short with 240 days or more (s.54 / s.2A, case law only);
   - fixed-term exits before the term ends;
   - basic + DA below the configured share of remuneration under the Code (s.2(88)). The alternative amount is
     shown, never applied (decision C6).

Statuses: eligible, not eligible, not covered, pending determination, insufficient data (no wages, no joining date;
never treated as ineligible), legally unverified (provisional amount).

**A gratuity line is added automatically only when the determination is decisive and eligible**, and never when a
person has already added a gratuity line by hand. Today no Code version is verified, so for exits from
21 Nov 2025 the settlement shows the provisional amount and the reason, and a person decides. Tests:
`tests/Feature/Exit/GratuityEntitlementTest.php`.

## Integrity of built packs

A pack, once built, is never rebuilt: only a failed pack can be retried. Its ZIP's SHA-256 is recorded when it is
built, and `download()` hands the file over only if it still matches. Otherwise it refuses, records no download, and
asks for the reports to be generated again. Later payroll changes go into a new pack, and the old one still
downloads as it was built (`ComplianceReportIntegrityTest`).

## Related fix outside the module: export-layout checksums (D7)

Statutory export layouts (`statutory_export_layouts`, Phase 6.2) could not be verified on MySQL: their checksum
hashed the specification in written order, and MySQL's `json` column re-sorts keys. Migration
`2026_11_09_100004` adds `checksum_algorithm`:
- New layouts are sealed `canonical-v2`: keys sorted at every level, list order kept.
- Existing rows keep NULL (the original algorithm) and their checksum byte-for-byte.
- `ExportLayouts::integrity()` and the read-only `php artisan peopleos:compliance:layout-checksums` tell "broken only
  by key order" apart from tampering. Under owner decision D7, option B (2026-10-27), a row broken only by key order
  holds (`intact_against_pack`) when its stored checksum is the original algorithm's checksum of the layout pack's
  definition and its content equals that definition. Nothing stored is rewritten; anything else stays `mismatch`.
- `verify()` now locks the row, so two checkers acting at once record one verification.
- A database that has not yet run migration `2026_11_09_100004` reads its layouts as original-algorithm rows, without an error (`checksumAlgorithm()` reads the attribute array). New layouts can be created only after the migration.

Tests: `ExportLayoutChecksumTest`, `tests/MySql/ExportLayoutConcurrencyTest.php`.

## The catalogue

Each entry carries, as documented in the file header:
- `title`, `category`, `frequency`, `basis`, `scope`, `builder`, `output`, `obligation`, `filing`;
- `form`, `legal_basis`, `variants`, `effective_from`, `effective_to`;
- `requires`, `registration`, `establishment_types`, `thresholds` (or `min_employees` with `threshold_measure`,
  `threshold_basis`, `threshold_confidence`);
- `due`, `due_text`, `options`, `notes`;
- `confidence`, `sources`, `verified_on`, `legal_review`.

`due` is months after the period's last month plus a day (`end` = that month's last day). `end_month` overrides the
rule for periods ending in a given month (March TDS by 30 April; Q4 statement by 31 May).

State entries live under `states.{CODE}.reports`, keyed `{key}:{CODE}`. All 36 states and union territories are
listed, with their PT, LWF and shops-and-establishments position. Codes are canonicalised by
`ReportCatalogue::canonicalState()`: `TS`→`TG`, `CT`→`CG`, `OR`→`OD`, `UT`→`UK`, `DN`/`DD`→`DH`.

**Source confidence.** Labels follow the model in `india-compliance-source-register.md`:
- A mirror copy of a gazette counts as partly verified.
- A gazette copy with a valid Government of India Press signature, or a text on an official domain reached over
  HTTPS, counts as primary. Examples: the Wages, SS and OSH (Central) Rules on labour.gov.in.
- A secondary source never becomes verified, and a label that names a source must cite one.

`CatalogueIntegrityTest` checks the data however it is maintained:
- labels are backed by their sources;
- date ranges run forwards and thresholds do not overlap;
- builders and all 36 states resolve;
- the catalogue holds no rule data.

**Regenerating.** The catalogue and the two research documents are generated by `tools/compliance-catalogue/` (C8,
2026-10-27; see its README). Never edit them by hand.
- `tools/compliance-catalogue/regenerate.sh` writes them from the specs, the research notes and `manual/`.
- `tools/compliance-catalogue/check.sh` regenerates in a temporary directory and fails on any byte of difference.
- The CI job "Compliance catalogue reproducibility" runs `check.sh`.
Revision `2026-10-27a` reproduced byte-for-byte from it. Whether to keep the generator in the repository or edit the
PHP directly is owner decision C8; until then, edit the PHP directly, keep the sourcing honest, and bump
`reviewed.revision`.

## Tests

| File | Covers |
|---|---|
| `tests/Feature/Compliance/ComplianceReportsTest.php` | Catalogue, periods, due dates, single reports, packs, finalized-only payroll, masking in challans |
| `tests/Feature/Compliance/LocationBasedApplicabilityTest.php` | The acceptance scenario: five establishments in KA / MH / TN / GJ / DL (no state), different thresholds, form and threshold changes mid-year, a transfer, EPF v1 → v2 |
| `tests/Feature/Compliance/ComplianceReportSecurityTest.php` | Masking (UAN, ESIC, PAN, Aadhaar, bank account), cross-user / cross-tenant / narrowed-scope download, PoSH confidentiality, review, failure / index / ZIP consistency / temp cleanup / retry, zip-slip, dedupe, build-time permission |
| `tests/Feature/Compliance/ComplianceReportCalculationTest.php` | Bonus (₹7,000 ceiling, 8⅓% / 20%, ineligible, ceiling crossed, straddle year, verified path, activation refused), gratuity fixed-term v2 → v3 |
| `tests/Feature/Compliance/CatalogueVariantsTest.php` | Per-Code appropriate government (SS vs wages), state final-rule forms from their effective date, no primary label without a government host or validated signature |
| `tests/Feature/Architecture/ComplianceInvariantsTest.php` | Read-only, finalized payroll, no rate literals |
| `tests/MySql/ComplianceReportsConcurrencyTest.php` | MySQL only (opt-in): the new columns, and one pack for three racing requests |
| `tests/Feature/Compliance/ApplicabilityScenariosTest.php` | A verified threshold that excludes, a factory and a once-covered office kept in, per-Code appropriate government, a missing registration |
| `tests/Feature/Compliance/ComplianceReportIntegrityTest.php` | A built pack never rebuilt; a changed file refused; open runs never read |
| `tests/Feature/Compliance/CatalogueIntegrityTest.php` | Labels backed by sources, dates, thresholds, builders, states, no rule data |
| `tests/Feature/Exit/GratuityEntitlementTest.php` | Gratuity at exit: versions by date, coverage, reasons of exit, rounding, ceiling, the 50% review, settlement lines |
| `tests/Feature/Compliance/ExportLayoutChecksumTest.php`, `tests/MySql/ExportLayoutConcurrencyTest.php` | D7: canonical seals, original-algorithm rows, diagnosis, no writes, the verification race |

The coverage, and what is not covered, is listed in `docs/compliance-research/compliance-report-test-matrix.md`.

## Known gaps

- Layouts carry the particulars of the forms but are PeopleOS layouts. Where a state prescribes its own layout, keep
  the register in that layout.
- No contract labour, accidents, welfare amenities, unions or inspections are held. These are checklist items.
- Minimum wages are not held, so the bonus calculation ceiling ("₹7,000 or the Central minimum wage, whichever is
  higher") is computed at ₹7,000 and marked pending determination. The 2026 central minimum wages are far above
  ₹7,000 a month, so this matters (B2, C3).
- Gratuity coverage counts the employees PeopleOS holds; contract labour and service before PeopleOS are not
  counted, and there is no customer override (C10).
- Compliance pack files are kept indefinitely; the retention purge does not cover them (D9).
- State LWF managerial and supervisory exclusions (wage ceilings differ by state), employer minimums (Madhya
  Pradesh) and monthly-deduction / half-yearly-remittance funds (Punjab, Chandigarh) are not modelled.
- States without a PT or LWF rule version have no PT or LWF lines in payroll. Their statements are empty and say so.
- The holiday list resolves one holiday calendar per work location.
- Readiness for production: see `docs/compliance-research/compliance-production-readiness.md`.
