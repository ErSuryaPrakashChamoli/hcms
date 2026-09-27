# Payroll Compliance Architecture (Phase 5)

For: engineers extending PeopleOS payroll and statutory compliance.

## 1. Layers

```
Company ─┬─ LegalEntity (ADR-0001) ── Establishment ── Location
         │        │                        │
         │        ├─ StatutoryRegistration (entity-level: TAN, PAN)
         │        │                        ├─ StatutoryRegistration (EPFO, ESIC, PT, LWF, S&E)
         │        │                        └─ EstablishmentStatutoryProfile (statute × period)
         │        └─ TdsProfile / TdsFinancialYear
         └─ PayrollPeriod / PayrollRun (per company, Phase 4, frozen)
                   └─ PayrollEntry (+ legal_entity_id, establishment_id recorded at calculation)

Employee ── EmployeeEstablishmentAssignment (effective-dated, immutable history)

ComplianceRule (platform, versioned, immutable, checksum) ── ComplianceRuleVerification (append-only)

StatutoryReturn (lifecycle header) ─┬─ EPF: epf_return_runs / entries / revisions
                                    ├─ ESI: esi_return_runs / entries
                                    ├─ PT:  professional_tax_returns / entries
                                    ├─ LWF: lwf_returns / entries
                                    └─ TDS: tds_quarterly_returns / entries (← tds_annual_ledgers)
        ├─ statutory_return_actions (Part P)   ├─ statutory_snapshots (Part M)
        └─ statutory_reconciliations (Part N)  TdsCertificate (Form No. 130 snapshots)
```

## 2. Calculation path (engine `payroll-2.1`)

1. `PayrollCalculator` (unchanged pipeline from Phase 4) calls `StatutoryEngine::apply()`.
2. `StatutoryContexts::for(employee, period)` resolves: establishment assignment on the period end →
   position location's establishment → company's principal establishment. An establishment of
   another company is ignored.
3. Applicability comes from the establishment's statutory profiles when it has any, otherwise from
   the legacy company profile. PT state: the establishment state when assigned explicitly, else the
   employee override, else the establishment state, else the legacy profile (recorded as
   `state_source`). LWF state follows the establishment when profiles are in use.
4. Each statute resolves its rule version with `ComplianceRules::resolve()`: the newest version
   effective on the date that is not SUPERSEDED or REJECTED — never an older one because the newest
   is unverified. A missing rule raises `statutory_rule_missing`; an unverified one raises
   `unverified_statutory_rule`; both are blocking when `payroll.enforce_verified_rules` is on.
5. Every consulted rule (id, version, checksum, source) is recorded on the lines and in
   `inputs.rules_consulted`; the run's `rule_versions` collects them all.
6. `PayrollRuns::finalize()` calls `ComplianceRules::assertRunVerified()`: every rule version the run
   used must still be VERIFIED and match its checksum.

`2.1` changed calculation outputs only where establishment data differs from the legacy company
profile. Runs calculated on `2.0` and not finalized must be recalculated (the Phase 4 guard
enforces it; an approved run can now be reopened to draft for that).

## 3. Statutory outputs

- `StatutoryReturns` owns the lifecycle, separation of duties, immutability, filing events and
  audit (see Statutory Output Lifecycle).
- A generator (`StatutoryReturnGenerator`) per type owns content: build from finalized payroll,
  validate, reconcile, export, present (masked) and capture snapshots. `PayrollLineReturns` is the
  shared engine for ESI, PT and LWF; `EpfReturns` and `TdsQuarterlyReturns` are bespoke.
- `StatutoryPayrollSource` is the only reader of payroll for statutory outputs; it reads finalized
  or paid runs only and never writes payroll.
- `ComplianceReadiness` evaluates the production gate per return.
- Register a new type in `peopleos.compliance.return_types` with its generator, and its export
  layout in `peopleos.compliance.formats` with a verification status.

## 4. Invariants (tested)

| Invariant | Where enforced |
|---|---|
| Rule versions immutable; pack sync insert-only | `ComplianceRule` guards, `ComplianceRules::sync()` |
| No statutory rate literals in payroll PHP | Architecture test |
| Verification by a different platform admin, official domains only | `RuleVerifications` |
| One establishment assignment per date, history immutable | `EstablishmentAssignments`, model guard |
| Profiles carry no rates and do not overlap | `EstablishmentStatutoryProfile` guard |
| Registration numbers, UAN, IP number, PAN encrypted + masked | model casts, data classification test |
| Return content frozen after approval | `StatutoryReturn` + `FrozenWithReturn` guards |
| Snapshots, actions, reconciliations, verification history append-only | model guards |
| Approver ≠ generator; filer ≠ generator and approver | `StatutoryReturns` |
| Nothing marked SUBMITTED without an external reference | `StatutoryReturns::recordSubmission()` |
| Statutory data visible only with compliance permissions and inside company scope | policies, `AccessScopes::allows()` |
| Every queued statutory job tenant-aware and unique | architecture test, jobs |

## 5. APIs

Read-only, `/api/v1/compliance/*`, scope `compliance.read`: establishments, registrations (masked),
rules and rule detail (payload and verification history), returns, return detail (validation, rule
versions, readiness, action log), entries (masked, access audited), reconciliation.

## 6. Preserved limitations

Phase 4: split-month PF/ESI eligibility uses the latest segment; split-month fixed components paid
once; SQLite does not exercise row locks; finalized-unpaid reopen allowed with a reason; pending
leave does not reduce payroll; rehire accrual unresolved; leave year and payroll period
independent.

Phase 5: mid-month establishment transfers are attributed to the establishment on the period end;
ESI coverage continuity across a contribution period is detected, not applied; EPS eligibility, the
EPF 10% option and the ESI disability limit and daily-wage exemption are not modelled; export
layouts and the Form No. 138 schema are unverified; payments, challans and portal filing stay
outside PeopleOS.
