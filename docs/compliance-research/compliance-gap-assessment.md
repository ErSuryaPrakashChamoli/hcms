# Compliance reports — gap assessment

Assessed 2026-10-26, before the corrections described in `compliance-production-readiness.md`.

Starting point:
- Branch `feature/oct_22_salary_module`, HEAD `a936a4d1`.
- The compliance reports module is uncommitted, alongside other uncommitted features (bulk uploads, Home redesign,
  letters and others). Those are not touched by this work.

## 1. What was inspected

| Area | Inspected |
|---|---|
| Module | `app/Domain/ComplianceReports/**`, `ComplianceReportsPage`, Blade view, `compliance_report_packs` migration, policy, job, permissions, config wiring, catalogue `database/data/compliance/reports/in.php` |
| Statutory rule engine | `ComplianceRule`, `ComplianceRules::resolve()` / `sync()`, `RuleVerifications`, notices (`database/data/compliance/notices/in.php`), the rule pack `database/data/compliance/in.php`, the 24 stored versions (23 IN + 1 AE) |
| Organisation | `Company → LegalEntity → Establishment → Location`, `EmployeeEstablishmentAssignment`, `EstablishmentAssignments::resolve()`, `StatutoryRegistration` (entity- and establishment-level), statutory profiles |
| Payroll | `PayrollRun` statuses, `PayrollEntry` establishment recording, statutory line `basis` (rule id, version, state) |
| Security | Tenant scope, `AccessScope`, pack policy, download path, masking, audit, job context, temporary files, ZIP entry names |
| Tests | `tests/Feature/Compliance/ComplianceReportsTest.php` (13 tests), compliance invariants, MySQL suite (`tests/MySql`, opt-in) |
| Research | `docs/compliance-research/2026-10-26-central-obligations.md`, `2026-10-26-state-obligations.md` |
| Docs | `docs/architecture/compliance-reports.md`, `statutory-production-readiness.md` |

### The 24 stored rule versions

All 24 are `status = active`. None is VERIFIED: 4 are in REVIEW (EPF v2, ESI v1, TDS v3, AE SS v1) and the rest are
DRAFT. Five regulatory notices are open (EPF ×3, TDS ×2).

| Code | Versions |
|---|---|
| EPF | v1 2014-09-01; v2 2026-09-17 |
| ESI | v1 2019-07-01 |
| TDS | v1 FY 2025-26; v2 and v3 from 2026-04-01 |
| GRATUITY | v1 2018-03-29 (Payment of Gratuity Act) |
| PT | MH, KA, TG, AP, WB, TN, GJ, MP, KL (v1 each) |
| LWF | MH, KA, TG, DL, TN, GJ, HR (v1 each) |
| AE SS | v1 |

`ComplianceRules::resolve()` takes the newest version effective on the date with `status = active`, whatever its
verification status. The pack sync always writes `active`, so any version added to the pack takes effect in payroll
from its effective date. A safe "prepared but not yet in force" state did not exist.

## 2. Confirmed implementation defects

| # | Defect | Effect |
|---|---|---|
| D1 | The establishment **State** field offers only the 13 states in `peopleos.compliance.states` | Establishments in 23 states and UTs cannot be configured, so their state obligations never apply |
| D2 | No record of the **appropriate government** (Central / State) per establishment | Central-rule forms (Forms I/IV/IX) are shown to every establishment, including state-sphere private ones |
| D3 | Catalogue entries have **no effective dates or form variants** | A report for a period before 8 May 2026 cites forms not yet in force; legacy forms are never selected; state thresholds that changed in 2025–26 apply to earlier periods |
| D4 | Missing establishment **state or type** is not flagged | State obligations silently vanish. A missing type reads as "applies to … only", the wrong reason |
| D5 | Employees with no establishment assignment are attributed to the **principal establishment** by fallback, with no warning | Multi-state companies report fallback employees under the head-office state |
| D6 | PT and LWF states come only from establishments' states | Employees taxed in another state (employee PT override) are missed |
| D7 | Thresholds are counted one way (assignments overlapping the period), with no measure or effective date | Wrong for laws counting "on any day of the year" or "ordinarily employed"; ignores dated threshold changes |
| D8 | No per-report status beyond generated / empty / not applicable / failed | Cannot tell applicability, data completeness, calculation verification, review and external filing apart |
| D9 | No record of the **rule versions** behind a report's figures, the payroll runs read or the catalogue revision | A pack cannot be traced back to its statutory basis |
| D10 | Source confidence is a loose `official` / `secondary` / `unverified` label with no sources or verification date | Labels such as "official" were assigned from agent research, not checked against the source |
| D11 | Every output is presented the same way | Registers, working sheets, internal checks and external filings are not distinguished; nothing says "not filing-ready" |
| D12 | Bonus register: no eligibility status model; wages capped proportionally with no verified rule | Cannot produce eligible / ineligible / pending / insufficient data / unverified |
| D13 | Gratuity: fixed-term employees (one year under the SS Code) are not modelled; the only rule is the 2018 Payment of Gratuity Act version | Fixed-term employees reported as not qualifying |
| D14 | ZIP entry names keep `/` from establishment names | `A/../../x` becomes a zip-slip path on extraction (see S2) |
| D15 | Pack file loaded into memory to store it (`file_get_contents`) | Large packs can exhaust memory |
| D16 | No retry for a failed pack; no duplicate-request guard | Users re-request and create duplicate packs |
| D17 | Failure messages copied into the manifest | Database exceptions could expose values through the index (see S5) |

## 3. Security issues

| # | Issue | Severity |
|---|---|---|
| S1 | **PoSH annual report counted confidential PoSH cases** under the general employee scope, bypassing `Grievances::canAccess()` (Internal Committee / handler roles only) | High (confidentiality of PoSH cases) |
| S2 | Zip-slip through establishment names (D14) | Medium |
| S3 | Download did not re-check the requester's **current access scope** or **sensitive permission**. A pack built with wider access or unmasked identifiers stayed downloadable after access was narrowed or revoked | Medium |
| S4 | Background build did not re-check `compliance.reports.generate` at build time | Low |
| S5 | Exception messages placed in the manifest and index | Low |
| S6 | No rate limit on single-report generation or pack requests (each is heavy) | Low |
| S7 | Temporary directory created with default 0755 | Low |
| S8 | Livewire 4.4.6 (GHSA-mhf7-xp6r-6qrc, `#[Authorize]` bypass, affected ≥4.3.0 <4.4.8). The app and Filament do not use `#[Authorize]` / `#[Json]` | Medium (advisory), not exploitable in current code |

Correct and kept unchanged:
- Tenant scope on packs.
- Company scope through `ScopedByOrganisation`.
- Requester-only download.
- Masking by default.
- Unmasked identifiers only with `compliance.sensitive.view`.
- Audit of generation and download.
- Job tenant binding.
- Files on the private compliance disk; no public URL.

## 4. Legal research gaps

| # | Gap |
|---|---|
| L1 | **Appropriate government and state Code rules.** The catalogue presents central Forms I/IV/IX as the registers for all establishments. For most private establishments the State is the appropriate government, and which forms apply depends on whether the state has notified final rules under the Codes. Not researched per state |
| L2 | Many state facts (PT slabs, LWF amounts, S&E forms, thresholds) rest on one secondary source, labelled "secondary" or "official" without per-parameter evidence |
| L3 | Bonus ceilings (S.O. 4710/4711(E)): gazette text not read. Whether central notifications bind state-sphere establishments is not settled. Transition for FY 2025-26 not settled |
| L4 | Gratuity ceiling under SS Code s.53(3) not confirmed. First Schedule thresholds (EPF 20, ESI 10, gratuity 10, maternity 10) not read from the gazette |
| L5 | EIR (Form XXVI) applies to private establishments only "from such date as may be notified"; ESIC half-yearly return status not confirmed; EPF A/c 22 rate not confirmed |
| L6 | Transitional rules for registers kept under the old Acts (which form applies to which period) are not confirmed per jurisdiction |

## 5. Outdated statutory rules (leads; not activated until verified)

| Rule | Stored | Lead |
|---|---|---|
| Karnataka LWF v1 | ₹20 / ₹40, December | ₹50 / ₹100 (+ government ₹50) |
| Haryana LWF v1 | ₹31 / ₹62 monthly | 0.2% of wages up to ₹35 a month from January 2026; employer twice |
| West Bengal PT v1 | 2013 slabs | New slabs from 1 Oct 2026 |
| Kerala PT v1 | 2016 monthly-equivalent slabs | Raised from 1 Oct 2024 (half-yearly) |
| Odisha PT | No version stored | Repealed from 1 Apr 2026 |
| BONUS | None | ₹21,000 eligibility, ₹7,000 / minimum wage calculation ceiling, 8.33–20% |
| GRATUITY | 2018 Act version only | SS Code: fixed-term after one year; ceiling under s.53(3) |

## 6. Incorrect applicability or due-date calculations

- **Register forms:** Form I/IV/IX labels applied before 8 May 2026 and to state-sphere establishments (D2, D3).
- **State thresholds (Maharashtra, Gujarat, Haryana, Punjab, Chandigarh, Uttar Pradesh, Odisha, Tripura,
  Chhattisgarh, Uttarakhand):** the 2025–26 threshold of 20 is applied to periods before each state's change date
  (D3, D7).
- **Employment Information Return:** treated as applying at 20+ employees with no regard to the notification date
  (L5).
- **Odisha PT:** the catalogue says "not levied" for every period, including FY 2025-26, when it was levied.

The due-date arithmetic itself is correct for every rule encoded: next-month 7th, 15th and 21st; March TDS 30 April;
quarterly TDS 31 July / 31 October / 31 January / 31 May; bonus 30 November; unified return 28/29 February; EIR
30 April; LWF 15 July / 15 January.

## 7. Missing reports

- **Wage slips (Form V):** issued from Payroll → Payslips. Only a checklist entry; acceptable.
- **Factories Act registers and the annual return under state factories rules:** checklist only.
- **Not held in PeopleOS** (contract labour, accidents, welfare amenities): checklist, correctly.
- **Data gaps:** no "configuration required" item where state, type or registration is missing (D4).

## 8. Incomplete tests

Not covered:
- Multiple legal entities.
- Central versus state sphere.
- Effective-dated thresholds and forms.
- Historical periods.
- Fallback attribution.
- Missing state or registration data.
- Rule-version traceability.
- Proposed (inactive) rule versions.
- Bonus statuses.
- PoSH confidentiality.
- Cross-tenant download.
- Identifier guessing by another user.
- Scope change after generation.
- Zip-slip names.
- Index-to-ZIP consistency.
- Failure reporting and retry.
- Temporary-file cleanup.
- Masking of Aadhaar and bank accounts.

The MySQL suite has not been run against MySQL for this module.

## 9. Already correct and kept

- Single-report Excel download.
- Generate all as a ZIP with index and checklist.
- Monthly reports inside quarter and year packs.
- Finalized-only payroll (the variance check reads newer runs and says so).
- Requester-only download.
- Default masking.
- Audit of generation and download.
- Tenant-bound job.
- Read-only builders (architecture invariant).
- Due-date arithmetic.
- PF challan account split from the rule version on each line.
- PT and LWF attributed by the state on each payroll line.
- State-code aliases (TS → TG and others).

## 10. Found during the correction pass (2026-10-27)

| # | Defect | Fixed |
|---|---|---|
| D18 | Social Security obligations (register of women employees, gratuity, EIR) chose their form by the **Wages** appropriate government. The `ss_appropriate_government` field was stored but never read | Yes. Entry-level `code` (`ss`) and `ReportDefinition::appropriateGovernment()`; `CatalogueVariantsTest` |
| D19 | Pack dedupe was check-then-insert: three simultaneous requests created three packs on MySQL | Yes. Row lock on the requester; `tests/MySql/ComplianceReportsConcurrencyTest.php`, mutation-checked |
| D20 | Ten central entries were labelled primary-verified on mirror copies: the Wages (Central) Rules from PRS's unsigned copy; SS Rules cited from StaffNews | Yes. Wages Rules entries relabelled partial; SS Rules cite labour.gov.in; a test forbids primary without an official host or a valid signature |
| D21 | Salary variance (an internal control with no legal source) read "official primary source verified" | Yes. Internal controls have legal status NOT_STATUTORY and readiness INTERNAL_CONTROL |
| D22 | PT special-month amount applied to every non-zero band: Maharashtra's ₹175 band was charged ₹300 in February | Yes, in `StatutoryEngine` (top band only); `StatutoryTest` |
| D23 | ZIP entry names could keep `..` runs (not a traversal, since separators were already removed) | Yes. Dot runs collapse |
| D24 | State register forms ignored the state's own final Code rules (12 states have some) | Yes. Dated per-state variants |
| D25 | Several stored rule versions are wrong or wrongly dated: MH / TN / GJ LWF from 2015; KA PT February ₹300 from 2023; TN PT superseded and monthly; MP PT third band | No: documented (W1–W11) with recommended notices; rule versions are immutable and corrections need owner decisions |

## 11. Found during the remediation pass (2026-10-27, second pass)

| # | Defect | Fixed |
|---|---|---|
| D26 | **Export layouts could never be verified on MySQL** (9 MySQL-only failures in `ExportLayoutTest` and `ProductionGateTest`). The checksum hashed the specification as written; MySQL's `json` column re-sorts object keys. A census of every other checksum in the application found no other affected record type | Forward fix: `checksum_algorithm` (`canonical-v2` for new layouts); old rows unchanged and verified as before; read-only diagnosis and command. The 9 tests pass on MySQL. Old rows on MySQL: owner decision D7 |
| D27 | Two checkers verifying the same export layout at the same moment both succeeded (two verifications, two audit events) | Yes. `verify()` locks the row and re-checks the status; `tests/MySql/ExportLayoutConcurrencyTest.php`, failing before the fix |
| D28 | **Full and final settlements never computed gratuity**, and nothing tested whether an establishment is covered | Yes. `GratuityEntitlements` (coverage under the First Schedule and s.1(8), eligibility by reason of exit, amount by rule version); a line only when decisive; `GratuityEntitlementTest` |
| D29 | The gratuity register counted a part year of exactly six months as a year (the law: "in excess of six months") | Yes. The register now uses the same calculator |
| D30 | The gratuity threshold was labelled "secondary, schedule text not verified" although the First Schedule had been read in the official text; and the plan measured it only within the period, which would wrongly exclude factories and once-covered establishments | Yes. Labelled primary, measured by the First Schedule's own test (`gratuity_coverage`) |
| D31 | A pack's ZIP was handed over without checking the fingerprint recorded when it was built | Yes. `download()` refuses a changed file; `ComplianceReportIntegrityTest` |
| D32 | 26 catalogue entries were labelled "secondary source only" with no source cited; Sikkim PT was primary on plain-HTTP copies | Yes. Sourced or relabelled UNVERIFIED; Sikkim partial; `CatalogueIntegrityTest` |
| D33 | The PoSH annual report carried a due date of 31 January as if it were statutory; neither the Act nor the central Rules sets one | Yes. Due date removed; the obligation and its content are now primary-verified |
| D34 | Stored versions found wrong in the first pass (W1–W11) | Confirmed or refined with official evidence. Not changed: immutable; exact corrections and notices N1–N12 proposed for the owner (A7) |
| D35 | Pack ZIPs are never purged by the retention job | No: retention period is an owner decision (D9) |
| D36 | The working tree was staged by a process outside this work at 10:24 on 2026-10-27 | Not changed. Recorded (D1) |
| D37 | Introduced and fixed in this pass: before migration `100004` runs, reading an export layout threw `MissingAttributeException` under strict models (seen on the development database) | Yes. `checksumAlgorithm()` reads the attribute array, so such rows read as the original algorithm; a canonical seal read without the column fails closed; test in `ExportLayoutChecksumTest` |
