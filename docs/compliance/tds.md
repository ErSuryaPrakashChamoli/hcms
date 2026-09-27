# TDS on Salary Guide

For: payroll and compliance operators handling income-tax deduction on salary.

## Official basis (Income Tax Department, retrieved 28 September 2026)

- **Form No. 138 (earlier Form No. 24Q)** is the quarterly statement of TDS on salary, filed under
  **Rule 219 of the Income-tax Rules, 2026**. Annexure-I is filed every quarter; Annexure-II (salary,
  exemptions and deductions per employee) is added in Q4.
- **Form No. 130 (earlier Form No. 16)** is the annual TDS certificate to employees, with Parts A, B
  and C; Part C (Annexure-I) applies to salary TDS under **section 392 of the Income-tax Act,
  2025**.
- The deductor needs a valid TAN registered on the e-filing portal.

PeopleOS models the forms as `FORM_138` (legacy code `FORM_24Q`) and `FORM_130` (legacy code
`FORM_16`). The official file schema (utility / FVU) has **not** been verified: exports are
working schedules, and no screen or document claims filing readiness.

## Separation of concerns

| Concern | Where | Notes |
|---|---|---|
| Monthly deduction | Payroll engine (frozen) | Annual projection per the TDS rule version; recorded on the payroll line and `inputs.tax` |
| Declarations | `employee_tax_declarations` (existing) | Regime, declared amounts, previous employer |
| Investment proofs | `tds_employee_investments` | Proof amount and status; the employee cannot verify their own proof |
| Annual ledger | `tds_annual_ledgers` | What finalized payroll deducted, per employee and month |
| Quarterly statement | `statutory_returns` + `tds_quarterly_returns` / `_entries` | Form No. 138 |
| Certificate | `tds_certificates` | Form No. 130 snapshot |
| Deductor | `tds_profiles`, `tds_financial_years` | TAN, responsible person, ledger verification |

## Annual ledger

- One row per employee per finalized payroll run, keyed by run and employee. Rows are never edited:
  if payroll is reopened and re-finalized with different figures, the old row becomes
  `superseded` and a new row is appended; if a run is reopened and not re-finalized, its rows are
  `withdrawn`.
- Syncing is idempotent and runs automatically when a statement is generated.
- **Ledger verification** (Compliance → TDS certificates → "Verify annual ledger") requires all
  four quarterly statements to be ACKNOWLEDGED (or RECONCILED) and each to match the current
  ledger. It stores a checksum; any later ledger change clears the verification.

## Form No. 138 statement

- One per **legal entity (TAN) × financial year quarter**. Deductee rows come from active ledger
  rows with TDS; PAN is encrypted, hashed and masked; rows without PAN carry reason `NO_PAN`.
- Challan / BIN details of the tax deposited are entered with the statement; PeopleOS does not pay
  tax.
- Blocking: no TDS profile, no TAN, missing responsible person, quarter not ended, invalid PAN
  format, rule without reference, unverified rule (when enforced), no deposit details, incomplete
  challan, deposit less than deduction.
- Warnings: missing PAN, unverified rule (enforcement off), and always
  `official_schema_unverified`.
- Reconciliation: tax deducted and row count against the ledger and against payroll `TDS` lines;
  return totals against rows.

## Form No. 130 certificate

- Prepared only from a verified ledger whose checksum is unchanged. One immutable snapshot per
  employee: Part A references (deductor, TAN last four digits, acknowledged quarterly statements,
  TDS by quarter — **Part A itself is downloaded from TRACES**), Part B figures from the ledger and
  the last tax computation, the ledger rows and rule versions used.
- PAN appears only as its last four digits in the snapshot.
- Issuing records the TRACES certificate number and must be done by someone other than the
  preparer. Regenerating an unchanged ledger creates nothing; a changed ledger creates a new
  version that supersedes the earlier one.

## Known limitations

- The FY 2026-27 TDS rule version cites the Finance Act 2025 and must be re-authored for the
  Income-tax Act, 2025 before verification.
- No FVU / utility file, no TRACES integration, no Form 12BA, no lower-deduction certificates, no
  arrears relief computation.
