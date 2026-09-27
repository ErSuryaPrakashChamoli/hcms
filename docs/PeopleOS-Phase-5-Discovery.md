# PeopleOS Phase 5 — Compliance & Statutory Readiness: Discovery Report

- **Date:** 28 September 2026
- **Baseline:** HEAD `079952a` (Phase 4 frozen), clean tree, 75 migrations applied, 380 tests / 3,559 assertions.
- **Scope of this report:** what exists today, how it maps to the Phase 5 architecture, the conflicts
  found, and the resolution adopted for each. No code was changed while producing it.

## 1. What exists today

### Tables

| Table | Owner | Relevant columns | Notes |
|---|---|---|---|
| `companies` | tenant | `legal_name`, `country_code`, `currency`, effective dates | Company = legal entity = establishment today |
| `locations` | tenant | `company_id`, `state_code`, `country_code` | State ignored by the statutory engine |
| `company_statutory_profiles` | tenant | one per company: `jurisdiction`, `pt_state`, `lwf_state`, `pf/esi/pt/lwf/tds_applicable`, `pf_restrict_to_ceiling`, `pf_establishment_code`, `esi_code`, `pt_registration`, `tan`, `pan` | Registrations hard-coded as columns |
| `compliance_rules` | platform (no tenant) | `jurisdiction` (country code), `code`, `state`, `name`, `version`, effective dates, `parameters` json, `source` (free text), `status` (`active`), `verification_status` (`illustrative`/`verified`), `verified_at` | unique (`jurisdiction`,`code`,`state`,`version`) |
| `employee_statutory_details` | tenant | `pan`, `aadhaar_reference`, `uan`, `pf_number`, `esic_number` (encrypted), applicability flags, `tax_regime`, `pt_state_code` | Scoped by employee |
| `employee_tax_declarations` | tenant | per employee per FY: regime, `declarations` json, previous-employer income/TDS, status | Covers the suggested `tds_employee_declarations` |
| `payroll_periods`, `payroll_runs` | tenant | per **company**; runs carry `calculation_version`, `rule_versions`, `reconciliation`, `operation_id` | Phase 4, frozen |
| `payroll_entries`, `payroll_entry_lines` | tenant | lines carry `basis` incl. `rule_id`, `code`, `version`, `verification_status` | Immutable once the run is finalized |
| `user_access_scopes` | tenant | dimensions company, location, business_unit, division, department, team | No legal-entity / establishment dimension |

### Services and code paths

- `Compliance\Services\ComplianceRules` — `resolve(code, date, state, jurisdiction)` picks the highest
  `active` version effective on the date; `sync()` **updates rows in place** (`updateOrCreate`) from
  `database/data/compliance/{in,ae}.php`; `assertProductionSafe()` blocks finalization when *any*
  active rule of the jurisdiction is unverified.
- `Compliance\Services\StatutoryEngine` — EPF, ESI, PT, LWF, TDS (India) and generic SS/TAX. Takes
  applicability and the PT/LWF state from the **company** profile (employee `pt_state_code`
  overrides PT). When an applicable statute has no rule, it **silently skips** it.
- `Compliance\Services\TaxComputer` — monthly TDS by annual projection (s.192 of the 1961 Act) inside
  payroll; no annual ledger, no quarterly statement, no certificate.
- `Payroll\Services\PayrollRuns::finalize()` — calls `assertProductionSafe(jurisdiction of company profile)`.
- Config `peopleos.compliance.enforce_verified_rules` = env `PEOPLEOS_ENFORCE_VERIFIED_RULES`,
  default **true only when `APP_ENV=production`**.
- Filament: `ComplianceRules` (read-only list), `StatutoryProfiles` (company profile), `TaxDeclarations`,
  `Locations`, `PayrollControlRoom` (shows whether a profile exists).
- API: `/api/v1/...` with API-key scopes from `peopleos.api.scopes`; no compliance endpoints.
- Tests: `StatutoryTest` (6), `StatutorySafetyTest` (3), payroll suites using the company profile.

### Rule inventory

22 versions synced, **0 verified**: EPF v1, ESI v1, PT (MH, KA, TG, AP, WB, TN, GJ, MP, KL), LWF (MH, KA,
TG, DL, TN, GJ, HR), TDS v1 (FY 2025-26), TDS v2 (FY 2026-27, "carried forward pending verification"),
GRATUITY v1, SS (AE) v1. Sources are free-text act names; no URL, no reviewer, no evidence, no checksum.

## 2. Official evidence gathered during discovery (28 Sep 2026)

Only government domains were used. Evidence supports **REVIEW**, not VERIFIED: verification needs a
named human reviewer, and several parameters were not confirmed.

| Authority | Source | What it confirms | Gaps |
|---|---|---|---|
| EPFO | "Revamped ECR", epfo.gov.in/revamped-ecr/ | Revamped ECR "for wage month Sept 2025 onwards"; segregation of return and payment; system validations; damages/interest with ECR; revision "with certain conditions"; "No change in the existing format of the ECR" | ECR field layout is in the portal Help File, not retrieved |
| EPFO | "User Manual Re-engineered ECRs" v3.0 (pmvbry.epfindia.gov.in, PDF sha256 `3667e099…40f7`) | Return types Regular / Supplementary / Revised; supplementary only for members not in an earlier return, multiple allowed, needs an approved regular return; revised only for affected members, overwrites, needs approved regular return, allowed only when no other return is in process and no payment initiated; downward revision only before payment; contribution rate choice 12% or 10%; after a 4-month relaxation, a regular return is allowed only if returns for all active members of the month four months prior were filed | EPF wage ceiling, EPS and EDLI parameters not confirmed from this source |
| ESIC | "Contribution", esic.gov.in/contribution | EE 0.75% and ER 3.25% w.e.f. 01.07.2019; contribution periods Apr–Sep and Oct–Mar; daily average wage up to ₹176 exempt from employee contribution; payment within 15 days of month end | — |
| ESIC | "Coverage", esic.gov.in/coverage | Coverage wage limit ₹21,000/month w.e.f. 01.01.2017; ₹25,000 for persons with disability | Current pack lacks the disability limit and the ₹176 exemption |
| Income Tax Dept | "Form No. 138 (Earlier Form No. 24Q)", incometaxindia.gov.in | Form 138 replaces 24Q; filed under Rule 219 of the Income-tax Rules, 2026; Annexure-I in Q1–Q3, Annexure-II added in Q4 | File format / utility version not retrieved (FAQ page returned 403) |
| Income Tax Dept | "Form No. 130_131_132_133 (Earlier Form No. 16/16A/…)", Form 130 FAQ | Form 130 replaces Form 16; Parts A, B, C; salary TDS is under **section 392** of the Income-tax Act, 2025 | Certificate layout not retrieved |

Consequences: the prompt's statements on the revamped ECR and on Form 138 are confirmed. The
certificate is modelled as `FORM_130` with `legacy_code = FORM_16`. The FY 2026-27 TDS rule is
based on the 1961-Act slabs "carried forward" and cites the wrong act for that year; it stays DRAFT
and must be re-authored against the Income-tax Act, 2025 and Finance Act 2026.

## 3. Mapping to the Phase 5 architecture

| Phase 5 concept | Existing artefact | Decision |
|---|---|---|
| Company | `companies` | Unchanged |
| LegalEntity | none (`companies.legal_name`) | New `legal_entities`; backfill one per company |
| Establishment | none (company profile `pt_state`) | New `establishments`; backfill one per legal entity; `locations.establishment_id` nullable |
| Statutory registrations | columns on `company_statutory_profiles` | New `statutory_registrations`; backfill from the columns (status `unverified`); columns kept for compatibility, no longer read by new code |
| Employee ↔ establishment | none | New `employee_establishment_assignments`; backfill from current position's location/company |
| Statutory profiles per establishment | `company_statutory_profiles` (per company) | New `establishment_statutory_profiles` (per establishment, statute, effective period; no rates). Company profile remains the legacy fallback |
| Rule versions | `compliance_rules` | Extend additively (authority, country, source_url/title/published date, verified_by, verification_notes, checksum). `parameters` is the `rule_payload` |
| Rule verification history | none | New `compliance_rule_verifications` (append-only) |
| Enforcement flag | `peopleos.compliance.enforce_verified_rules` (prod-only default) | New `peopleos.payroll.enforce_verified_rules`, default **true**; the old key is removed |
| EPF/ECR outputs | none | `statutory_returns` header + `epf_return_runs`, `epf_return_entries`, `epf_return_revisions` |
| ESI outputs | none | header + `esi_return_runs`, `esi_return_entries` |
| PT | rules in `compliance_rules`, state from company | `ProfessionalTaxProfile` = establishment profile (statute PT); `ProfessionalTaxRuleVersion` = `compliance_rules` code PT; header + `professional_tax_returns`, `professional_tax_return_entries` |
| LWF | rules + company `lwf_state` | header + `lwf_returns`, `lwf_return_entries` |
| TDS | `TaxComputer` inside payroll, `employee_tax_declarations` | Monthly deduction stays in payroll (frozen). New `tds_profiles`, `tds_financial_years`, `tds_employee_investments`, `tds_annual_ledgers`, `tds_quarterly_returns` (FORM_138/24Q), `tds_quarterly_return_entries`, `tds_certificates` (FORM_130/16). `employee_tax_declarations` **is** the declaration table; no duplicate |
| Snapshots | line `basis` json | New `statutory_snapshots` (immutable, per return entry, replacement on revision) |
| Reconciliation | `PayrollReconciliation` (run-level) | New `statutory_reconciliations` per return |
| Approvals / SoD | run approval columns | New `statutory_return_actions` (every lifecycle action with user, reason, source, version) |
| Filing events | none | `statutory_returns` status + `external_reference`; SUBMITTED/ACKNOWLEDGED only via recorded filing events |
| Control room | `PayrollControlRoom` | New `ComplianceControlRoom` page linked from it |
| API | `/api/v1/*` | `/api/v1/compliance/*`, scope `compliance.read` |

## 4. Conflicts and resolutions

1. **Rule sync updates versions in place.** `ComplianceRules::sync()` uses `updateOrCreate`, which
   violates "rule versions immutable". *Resolution:* sync inserts missing versions only; an existing
   version with a different checksum is refused with an error ("publish a new version"); the model
   refuses updates to identity, payload, dates and checksum; status moves only through the
   verification service.
2. **Status vocabulary.** `status=active` + `verification_status ∈ {illustrative, verified}` vs the
   required DRAFT/REVIEW/VERIFIED/SUPERSEDED/REJECTED. *Resolution:* `verification_status` carries the
   five states (data migration: illustrative → draft, verified → verified). `status` stays for
   backward compatibility and is always `active`.
3. **Resolution silently falls back.** Today the engine uses whichever active version is newest,
   and skips a statute without a rule. *Resolution:* resolve the newest version effective on the date
   that is not REJECTED/SUPERSEDED, never an older one because the newest is unverified. When
   enforcement is on, an unverified or missing rule becomes a **blocking** payroll exception, not a
   silent skip.
4. **Gate scope.** `assertProductionSafe` blocks on any unverified rule in the country, including
   states the employer never uses. *Resolution:* finalization checks the rule versions the run
   actually used (by id), requiring VERIFIED and an unchanged checksum; missing applicable rules are
   already blocking exceptions (item 3).
5. **Enforcement default.** Required default `true`; today false outside production. *Resolution:*
   default true everywhere. The test harness opts out in `phpunit.xml` (illustrative packs), and
   enforcement tests switch it on explicitly. `StatutorySafetyTest`'s "does not enforce outside
   production" case is an intentional contract change.
6. **PT/LWF state from the company.** Violates "never from company country / company profile".
   *Resolution:* Employee → establishment assignment (on the period end date) → establishment state →
   establishment profile → verified rule. Fallback for employers not yet migrated: the company's
   primary establishment, then the legacy profile (basis records `state_source`; statutory returns
   treat the legacy source as a blocking validation).
7. **Payroll engine version.** Establishment-resolved statutory state and new blocking exceptions
   change calculation outputs. *Resolution:* `PayrollCalculator::VERSION` becomes `payroll-2.1`
   (2.0 + establishment resolution + statutory snapshot fields). Runs calculated on 2.0 and not yet
   finalized must be recalculated (the Phase 4 finalize guard already enforces this). Finalized
   2.0 runs are untouched.
8. **Where the establishment lives on payroll.** Returns must not re-resolve history. *Resolution:*
   `payroll_entries` gains nullable `legal_entity_id` and `establishment_id` written at calculation
   (a draft-time field; finalized entries are never mutated). Entries calculated before Phase 5
   resolve through assignments at return time and are flagged as such.
9. **ADR-0001 put establishment on `employee_positions`.** The prompt requires a separate
   effective-dated assignment table. *Resolution:* follow the prompt; ADR-0001 is updated.
10. **Registrations at entity level.** Part B keys registrations by establishment, but TAN/PAN belong
    to the legal entity. *Resolution:* `statutory_registrations` has `legal_entity_id` (required) and
    `establishment_id` (nullable = entity-level).
11. **TDS form names.** The prompt says Form 16; official sources show Form 130 replaced it.
    *Resolution:* `FORM_130` with `legacy_code = FORM_16`, alongside `FORM_138` / `FORM_24Q`.
12. **TDS separation.** Monthly TDS remains inside the frozen payroll engine. *Resolution:* the new
    ledger, quarterly statement and certificate read finalized payroll only and never recompute or
    alter payroll TDS.
13. **API path.** Prompt lists `/api/compliance/*`; every PeopleOS API is versioned under `/api/v1`.
    *Resolution:* `/api/v1/compliance/*` (documented).
14. **Access scopes.** No legal-entity/establishment dimension. *Resolution:* compliance tables carry a
    denormalised `company_id` and use the existing `company` dimension; returns additionally require
    compliance permissions, so reporting-line access never reveals statutory data.
15. **Rule verifiers.** Rules are platform-owned. *Resolution:* only platform administrators may
    review/verify/reject; submitter ≠ verifier. Tenants read.
16. **`migrate:fresh --seed`.** Must never run on the real development database. *Resolution:*
    run it on a temporary database, compare with `hcm` after `hcm` is migrated, then drop it.
17. **ECR / Form 138 file layouts not retrieved.** *Resolution:* export layouts live in versioned
    format definitions with their own verification status (REVIEW). Exports record the format version
    and status; readiness requires a VERIFIED format.
18. **Payments.** EPFO revision rules depend on payment state; payments are a Phase 5 non-goal.
    *Resolution:* a downward revision requires an explicit attestation that payment has not been
    initiated, recorded with the revision.

## 5. Preserved Phase 4 limitations (documented, not fixed)

Split-month PF/ESI eligibility uses the latest segment; split-month fixed components paid once;
SQLite does not exercise row locks; finalized-unpaid reopen is allowed with a reason; pending leave
does not reduce payroll; rehire accrual unresolved; leave year and payroll period are independent.
New in Phase 5: an employee transferred between establishments mid-month is attributed to the
establishment effective on the period end date.

## 6. Commit plan

phase-5.1 ADR-0001 foundation · 5.2 registrations, assignments, establishment profiles ·
5.3 verified rule framework · 5.4 return control layer + EPF/ECR · 5.5 ESI · 5.6 PT/LWF · 5.7 TDS ·
5.8 reconciliation & control room · 5.9 API & security · 5.10 hardening & documentation.
