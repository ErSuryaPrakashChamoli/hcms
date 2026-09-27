# PeopleOS Phase 4 Report — Payroll Foundation & Payroll Hardening

Branch `main`, from HEAD ebe5a0a (Phase 3). Architecture: `docs/architecture/payroll-architecture.md`. No compliance filing, bank gateway, ERP, Integration Hub, AI, RMS or Legal Entity work was started.

## Discovery

Payroll already existed from the earlier build: configurable components and structures, the sandboxed `FormulaEngine`, effective-dated salary assignments, a run pipeline (draft → calculated → validated → approved → finalized → paid, reopen) with separation of duties, statutory engine on versioned platform rules (EPF, ESI, PT, LWF, TDS projection, generic SS/TAX), payslip snapshots, bank file, the Phase 0.2 verified-rules gate, and 17 tests. Gaps: the calculator read `attendance_records` directly instead of the Phase 2/3 contracts; one salary assignment was applied to the whole month; divisor fixed to calendar days; approved overtime ignored; no run/finalization locks, calculation version, rule versions or reconciliation; finalized entries, closed-period adjustments and backdated salaries were mutable; adjustments had no approval or arrear source; payslip access ignored organisation scope.

## Legal Entity / Establishment

Payroll processing is per company and safe under ADR-0001's interim model (company = legal entity = establishment). Statutory **filings** need establishment-level registrations and TAN-level aggregation; they are not built and remain blocked on ADR-0001. No workaround was introduced.

## Implemented

- Engine `payroll-2.0`: salary segments, LOP from `AttendanceOutput` (absent) + `LeaveOutput::unpaidDays` (new contract method) + approved manual LOP, divisor setting (calendar / fixed / working days), overtime via configured component only, negative-net policy, per-line basis with segments and statutory rule id/version/verification.
- Runs: row locks on calculate/approve/finalize; `calculation_version`, `rule_versions`, `reconciliation`, `operation_id`; population streamed in chunks; `CalculatePayrollRun` queued, unique, tenant-aware.
- Finalization re-checks engine version, blocking exceptions, reconciliation and the verified-rules gate.
- Immutability: finalized entries/lines refuse changes; closed-period adjustments refused; salary revisions reaching into a paid period refused.
- Adjustments: approval status (optional tenant requirement), arrear/recovery/correction types, source and reference period; `PAYROLL_ADJUSTED`, `PAYROLL_ADJUSTMENT_APPROVED`.
- Payslips: `PAYSLIP_ACCESSED` audit; policy requires `payroll.view` + scope or ownership.
- API: `payroll/periods`, `payroll/runs/{id}`, `payroll/payslips/{number}`.

## Database

Migration `2026_10_03_100001_harden_payroll_foundation` (additive): run version/rule versions/reconciliation/operation id; adjustment status, approver, source, reference period. Replay into an empty MySQL database: 175 tables, identical columns and indexes to dev (the stray `tenants.base_currency` aside).

## Statutory verification status

22 rule versions synced, **0 verified**. All India (EPF, ESI, PT by state, LWF, TDS) and UAE rules remain illustrative. Production finalization stays blocked by the safety gate until each is verified against its official source. No rate was changed.

## Tests

380 passed, 3,559 assertions (previous 369 / 3,433). New `PayrollFoundationTest` (10 cases) and one payroll architecture invariant. One existing test was adapted intentionally: its unpaid half-day leave now comes from an approved unpaid leave request (the leave contract) instead of an attendance status value.

## Phase 3 limitations reviewed

- **Pending leave reservation:** unchanged. Payroll counts only approved (and cancel-requested) unpaid leave; pending requests never reduce pay. Invariant documented.
- **Rehire accrual:** no product decision exists; unchanged and documented as a policy configuration to add later.
- **Leave year vs payroll period:** independent; payroll reads leave by date range only.

## Deferred

Automatic arrear computation from retro changes; supplemental/off-cycle runs; payslip PDFs; employee self-service payroll API (panel only); Form 16 / ECR / challan / returns (after ADR-0001 and verification); gratuity; loans and advance schedules; multi-currency payroll; bank payment integration; payroll approval routed through the workflow engine.

## Known limitations

- ESI/PF eligibility tests use the latest segment's full-month wages in a split month.
- Fixed (non-prorated) components are paid once on the current salary in a split month.
- Row locks are no-ops on SQLite; concurrency is enforced on MySQL.
- Reopening a finalized, unpaid run is still possible (audited, with reason); paid runs cannot be reopened.
