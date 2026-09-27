# PeopleOS Phase 5 Completion Report — Compliance & Statutory Readiness

Branch `main`, from HEAD `079952a` (Phase 4, frozen). Discovery: `docs/PeopleOS-Phase-5-Discovery.md`.
Nothing was pushed.

**Statutory production readiness is NOT declared.** The controls are built and tested, but 0 of 22
statutory rule versions are VERIFIED and no export layout is verified, so no return can pass the
production gate and, with enforcement on (the default), production payroll cannot be finalized.

## Commits

| Commit | Scope |
|---|---|
| `0213b1d` phase-5.1 | Discovery report; ADR-0001 legal entities and establishments; transition backfill |
| `5f27bde` phase-5.2 | Statutory registrations, establishment statutory profiles, employee–establishment assignments; engine `payroll-2.1` |
| `632f516` phase-5.3 | Verified rule framework (immutability, checksum, evidence workflow, per-run gate, default-on enforcement) |
| `a5ec798` phase-5.4 | Statutory return control layer (lifecycle, SoD, snapshots, reconciliation) and EPF / ECR |
| `5495610` phase-5.5 | ESI monthly contribution returns |
| `c349913` phase-5.6 | State-aware professional tax and LWF returns |
| `2f7744d` phase-5.7 | TDS annual ledger, Form No. 138 (earlier 24Q) statements, Form No. 130 (earlier Form 16) |
| `116ac6e` phase-5.8 | Compliance control room and production gate |
| `9b02612` phase-5.9 | Read-only compliance API and Part U security tests |
| phase-5.10 | Hardening, documentation and this report |

## Outcomes against the brief

1. **ADR-0001 implemented.** Company › LegalEntity › Establishment (› Location), additive. Every
   company has an explicit primary legal entity and principal establishment (on creation, or via
   `peopleos:legal-entities:backfill`, audited and idempotent). Many legal entities, establishments
   and registrations per company. Payroll runs stay per company (Phase 4 frozen); each payroll
   entry records its legal entity and establishment.
2. **First-class registrations.** `statutory_registrations` with an extensible type catalogue (EPFO
   code and exemptions, ESIC code and region, TAN, PAN, PT registration and enrolment, LWF, shops &
   establishments), entity- or establishment-level, encrypted and masked, never edited in identity
   (a new number supersedes), verified by someone other than the recorder.
3. **Rule packs checked against government sources.** Evidence was gathered only from EPFO, ESIC
   and Income Tax Department sites (see the verification guide). ESI moved to REVIEW with its
   evidence and gaps; everything else stays DRAFT. **No rule was marked VERIFIED**: verification
   needs a human platform administrator other than the submitter, and several parameters are
   unconfirmed.
4. **Statutory outputs.** EPF ECR (regular, supplementary, revised), ESI, PT, LWF, TDS Form No. 138,
   and Form No. 130 — generated from finalized payroll only, validated, reconciled and exported. No
   filing is performed or claimed.
5. **Compliance control layer.** Immutable snapshots, payroll and filing reconciliation, approvals
   with separation of duties, audit events, exports, and the control room with a nine-control
   production gate.

## Parts A–Z (summary)

- **A–D:** ADR-0001 tables; registrations; `employee_establishment_assignments` (no overlaps, one per
  date, no backdating, close-once, never deleted); `establishment_statutory_profiles` (per statute
  and period, yes/no options only — no rates). Payroll resolves Employee → establishment on the
  period end → state → profile.
- **E–G:** `compliance_rules` extended (authority, country, source URL/title/published date,
  verified_by, notes, checksum, superseded_by); statuses DRAFT/REVIEW/VERIFIED/SUPERSEDED/REJECTED;
  versions immutable; pack sync insert-only; `compliance_rule_verifications` append-only; evidence
  must come from an authoritative government domain and map every payload key.
  `payroll.enforce_verified_rules` defaults to **true**; missing or unverified rules are blocking
  payroll exceptions when enforced; no silent fallback to an older or rejected version;
  finalization checks the run's own rule versions and checksums.
- **H:** EPF per EPFO's revamped-ECR rules (separate return/payment, validations, revision
  conditions, chronological filing); calculated bases apart from exported values; UAN encrypted.
- **I:** ESI with contribution periods from the rule; IP number encrypted; coverage-continuity and
  exemption warnings.
- **J–K:** PT and LWF per establishment state; PT blocked when the state came from the company.
- **L:** TDS ledger, Form No. 138 (`FORM_138`, legacy `FORM_24Q`), Form No. 130 (`FORM_130`, legacy
  `FORM_16`) from a verified ledger; no filing-readiness claim (schema unverified).
- **M–Q:** snapshots at approval; payroll and filing reconciliation; lifecycle statuses incl.
  EXPORTED ≠ SUBMITTED; approver ≠ generator, filer ≠ generator and approver; content frozen after
  approval, corrections by revision.
- **R:** audit actions `STATUTORY_RULE_*`, `STATUTORY_REGISTRATION_*`, `STATUTORY_OUTPUT_*`
  (created, validated, approved, exported, submitted, acknowledged, revised, reconciled, cancelled,
  accessed) in the tenant or platform hash chain.
- **S:** `/api/v1/compliance/*` (see integration contract), scope `compliance.read`, masked.
- **T:** Filament: legal entities, establishments, establishment assignments, statutory
  registrations, statutory profiles, compliance rules (with verification actions), rule
  verification history, EPF/ESI/PT/LWF returns, TDS statements, TDS deductor profiles, TDS
  certificates, TDS investment proofs, compliance control room.
- **U:** security tests for company scope, roles, reporting lines, tenant isolation, masking, rule
  and return immutability.
- **V–W:** uniqueness keys, per-return unique identifiers, unique tenant-aware jobs
  (`GenerateEpfReturn`, `GenerateEsiReturn`, `GenerateProfessionalTaxReturn`, `GenerateLwfReturn`,
  `GenerateTdsReturn`, `ReconcileStatutoryReturn`).
- **Y:** seven additive migrations `2026_10_04_100001`–`100007`; no earlier migration rewritten.
- **Z:** documents listed below.

## Intentional contract changes

- Enforcement default is now **on** everywhere (was production only). `phpunit.xml` opts the test
  harness out; enforcement tests opt in. Development environments must set
  `PEOPLEOS_ENFORCE_VERIFIED_RULES=false` explicitly to finalize payroll on draft rules.
- The old config key `peopleos.compliance.enforce_verified_rules` is replaced by
  `peopleos.payroll.enforce_verified_rules`; "illustrative" became "draft".
- Engine `payroll-2.0` → `payroll-2.1`: calculated-but-unfinalized 2.0 runs must be recalculated.
  An approved run that can no longer be finalized can now be reopened to draft (reason required).
- The gate checks the rules a run used instead of every unverified rule in the country.
- Tests adapted on purpose: `StatutorySafetyTest` (rewritten for the new statuses and gate),
  `PayrollFoundationTest` (rule status `draft`/`review`), `AuditHardeningTest` (company creation now
  also audits its legal entity and establishment in the same operation).

## Defects found and fixed along the way

- `PayrollComputation::blocking()` did not treat `invalid_component` as blocking although
  `PayrollCalculator::BLOCKING` lists it; it now uses the constant.
- The statutory rule cache could serve stale verification statuses across calculations; the rule
  store is scoped per request/job and cleared per run calculation.
- `AccessScopes::allows()` let company-scoped users reach records of models whose dimension is not a
  scope dimension; such models are now constrained by `company_id`.
- `AuditRecorder` failed on platform-level entities without `tenant_id` under strict mode.

## Verification

| Check | Result |
|---|---|
| Full suite | 425 passed, 4,070 assertions |
| Architecture tests | 12 passed |
| Pint | clean |
| `migrate:fresh --seed` on a temporary MySQL database | 201 tables; columns and indexes identical to dev except the pre-existing dev drift `tenants.base_currency varchar(3) NOT NULL` (no migration creates it) |
| Dev database | 82 migrations applied; transition backfill run (2 legal entities, 2 establishments, 5 assignments) |
| Audit chains | platform 4 events, demo 582 events — verified |
| Git | clean tree, 0 behind / 33 ahead of `origin/main`, nothing pushed |

## Tests

Final full run: **425 passed, 4,070 assertions, 0 failures**. Phase 4 baseline was 380 tests / 3,559 assertions; every
Phase 4 test still passes (three adapted intentionally, above). New suites: legal structure,
establishment statutory, rule verification, EPF, ESI, PT/LWF, TDS, compliance security, compliance
pages, compliance control room.

## Production gate status

| Gate item | Status |
|---|---|
| ADR-0001 implemented | Yes |
| Registration present | Enforced per return (blocking when missing) |
| Rules VERIFIED | **No — 0 of 22** (1 REVIEW, 21 DRAFT) |
| Rule version captured | Yes (lines, runs, snapshots) |
| Reconciled / 0 blocking / approval | Enforced per return |
| Audit chain intact | Yes |
| Export formats verified | **No** (ECR, ESI, PT, LWF schedules, Form 138 schedule all `review`) |

## Preserved limitations (not fixed, by instruction)

Split-month PF/ESI eligibility uses the latest segment; split-month fixed components are paid once;
SQLite does not exercise row locks; finalized-unpaid reopen is allowed with a reason; pending leave
does not reduce payroll; rehire accrual is unresolved; leave year and payroll period are
independent.

New Phase 5 limitations: mid-month establishment transfers are attributed on the period end; ESI
coverage continuity is detected, not applied; EPS eligibility, EPF 10% option, ESI disability limit
and daily-wage exemption, refunds of advances, arrear ECR, TDS FVU files, TRACES, challan payment
and portal submission are not implemented; the FY 2026-27 TDS rule must be re-authored for the
Income-tax Act, 2025; Eloquent listeners in some pre-existing models return values from `saving`
arrow functions (harmless today, noted in project conventions).

## What must happen before statutory production use

1. A platform administrator gathers official evidence for each rule a tenant needs, and a second
   administrator verifies it (starting with ESI, which is in REVIEW, and EPF).
2. Re-author TDS FY 2026-27 for the Income-tax Act, 2025 and Finance Act, 2026.
3. Verify each export layout against the authority's current upload format (EPFO Help File, ESIC
   template, state PT/LWF forms, Form No. 138 utility).
4. Record and verify each establishment's registrations against certificates.
5. Run a parallel month end to end and reconcile against the portals before relying on it.

## Documents

- `docs/architecture/ADR-0001-legal-entity-establishment.md` (implemented section)
- `docs/architecture/payroll-compliance-architecture.md`
- `docs/compliance/india-statutory-rule-verification.md`
- `docs/compliance/epf-ecr.md`
- `docs/compliance/esi.md`
- `docs/compliance/pt-lwf.md`
- `docs/compliance/tds.md`
- `docs/compliance/compliance-control-room.md`
- `docs/compliance/statutory-output-lifecycle.md`
- `docs/PeopleOS-Phase-5-Report.md` (this report)
