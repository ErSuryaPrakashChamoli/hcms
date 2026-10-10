# SaaS.12 — Statutory verification register

Date: 10 October 2026. **For:** the qualified statutory specialist engaged by Markedge (Indian payroll compliance; UAE
pension for the one AE rule), and the owner.

> **0 of 24 rule versions are verified. 5 regulatory notices are open. No rule may be treated as verified because it
> has an entry, a plausible value, a source title or a passing test.** Production finalisation and statutory exports
> refuse unverified rules and rules with an open notice. That is enforced always in production, whatever the
> environment says (engine validation below).

The worksheet (what to verify per rule, the parameters as stored, and the sign-off record) is
`SaaS-11-Statutory-Verification-Evidence-Package.md`. Full data: `docs/saas/evidence/SaaS-11-statutory-rules.json`.
This register adds:
- the statutory domain;
- the dependencies;
- test coverage per statute;
- an engine validation that the gate cannot be bypassed.

## 1. Rule register

Columns as the programme asks:
- **Source** is the stored lead, which the specialist must replace with the authoritative document.
- **Applicability conditions and calculations** are in the stored parameters (JSON), to be confirmed clause by clause.
- **Specialist reviewer** and **verification evidence** stay empty until the specialist fills them.
- **Approval** stays BLOCKED until a second operator records `verified` against official evidence.

| ID | Jurisdiction | Statutory domain | Rule | Effective | Source (stored lead) | Dependencies (consumers) | Test coverage (statute) | Stored status | Open notices | Specialist reviewer | Verification evidence | Approval |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 2 | IN | Social security: provident fund | EPF v1 | 2014-09-01 | EPF & MP Act 1952; EPFO circulars (lead, unverified) | Payroll statutory contributions (StatutoryEngine), EPF ECR return (Epf | 15 files (calculation, returns, establishment, production gate) | draft | 2 | | | BLOCKED |
| 3 | IN | Social security: provident fund | EPF v2 | 2026-09-17 | EPF Scheme, 2026 (G.S.R. 525(E), 29 Jun 2026, CG-DL-E-01072026-273957) (lead, unverified) | Payroll statutory contributions (StatutoryEngine), EPF ECR return (Epf | 15 files (calculation, returns, establishment, production gate) | review | 2 | | | BLOCKED |
| 4 | IN | Social security: health insurance | ESI v1 | 2019-07-01 | ESIC — Contribution (with ESIC — Coverage, https://esic.gov.in/coverag (lead, unverified) | Payroll statutory contributions (StatutoryEngine), ESI return (EsiRetu | 5 files | review | 0 | | | BLOCKED |
| 24 | IN | Gratuity (exit benefit) | GRATUITY v1 | 2018-03-29 | Payment of Gratuity Act 1972 (amended 2018) (lead, unverified) | No calculation consumes it today (stored rule only) | **none: no calculation uses this rule** | draft | 0 | | | BLOCKED |
| 17 | IN-DL | Labour welfare fund (state) | LWF v1 | 2015-01-01 | Bombay Labour Welfare Fund Act 1953 (as applied to Delhi) (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 19 | IN-GJ | Labour welfare fund (state) | LWF v1 | 2015-01-01 | Gujarat Labour Welfare Fund Act 1953 (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 20 | IN-HR | Labour welfare fund (state) | LWF v1 | 2020-01-01 | Punjab Labour Welfare Fund Act 1965 (Haryana) (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 15 | IN-KA | Labour welfare fund (state) | LWF v1 | 2018-01-01 | Karnataka Labour Welfare Fund Act 1965 (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 14 | IN-MH | Labour welfare fund (state) | LWF v1 | 2015-01-01 | Maharashtra Labour Welfare Fund Act 1953 (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 16 | IN-TG | Labour welfare fund (state) | LWF v1 | 2015-01-01 | Telangana Labour Welfare Fund Act 1987 (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 18 | IN-TN | Labour welfare fund (state) | LWF v1 | 2015-01-01 | TN Labour Welfare Fund Act 1972 (lead, unverified) | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) | 2 files | draft | 0 | | | BLOCKED |
| 8 | IN-AP | Professional tax (state) | PT v1 | 2013-02-01 | AP Tax on Professions Act 1987 (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 11 | IN-GJ | Professional tax (state) | PT v1 | 2022-04-01 | Gujarat Professions Tax Act 1976 (amended 2022) (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 6 | IN-KA | Professional tax (state) | PT v1 | 2023-04-01 | Karnataka Tax on Professions Act 1976 (amended 2023) (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 13 | IN-KL | Professional tax (state) | PT v1 | 2016-04-01 | Kerala Municipality Act 1994 (half-yearly, monthly equivalent) (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 5 | IN-MH | Professional tax (state) | PT v1 | 2023-04-01 | Maharashtra State Tax on Professions Act 1975 (Sch. I, amended 2023) (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 12 | IN-MP | Professional tax (state) | PT v1 | 2018-04-01 | MP Vritti Kar Adhiniyam 1995 (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 7 | IN-TG | Professional tax (state) | PT v1 | 2014-06-02 | Telangana Tax on Professions Act 1987 (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 10 | IN-TN | Professional tax (state) | PT v1 | 2018-04-01 | TN Municipal Laws (half-yearly, shown as monthly equivalent) (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 9 | IN-WB | Professional tax (state) | PT v1 | 2013-04-01 | WB State Tax on Professions Act 1979 (lead, unverified) | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computati | 10 files | draft | 0 | | | BLOCKED |
| 1 | AE | UAE pension (GPSSA) | SS v1 | 2023-10-31 | Federal Decree-Law No. 57 of 2023 (lead, unverified) | Pension contributions for UAE (AE) tenants (StatutoryEngine, the pensi | 1 file (UAE generic engine) | draft | 0 | | | BLOCKED |
| 21 | IN | Income-tax withholding on salary | TDS v1 | 2025-04-01 to 2026-03-31 | Finance Act 2025 (lead, unverified) | Salary income-tax withholding (TaxComputer / TDS engine, PayrollRuns), | 11 files | draft | 0 | | | BLOCKED |
| 22 | IN | Income-tax withholding on salary | TDS v2 | 2026-04-01 | Finance Act 2025 rates carried forward pending Finance Act 2026 verifi (lead, unverified) | Salary income-tax withholding (TaxComputer / TDS engine, PayrollRuns), | 11 files | draft | 1 | | | BLOCKED |
| 23 | IN | Income-tax withholding on salary | TDS v3 | 2026-04-01 | Income-tax Rules, 2026 (G.S.R. 198(E), Central Board of Direct Taxes,  (lead, unverified) | Salary income-tax withholding (TaxComputer / TDS engine, PayrollRuns), | 11 files | review | 1 | | | BLOCKED |

**Applicability.** If the specialist concludes that a rule does not apply to the deployment (for example the UAE rule for
an India-only launch, or a state the customers do not operate in), record it as **not applicable**, with the evidence
and the owner's approval. Do not mark it verified. An unverified rule blocks only the payroll runs that use it.

## 2. Open regulatory notices (stored, unverified)

Each was recorded by an earlier phase as a possible change the rules do not yet reflect. Its title is **not** a legal
fact: the specialist confirms or rejects it and resolves it with a new rule version or a recorded resolution.

| Statute | Effective date (as stored) | Notice title as stored (unverified) |
|---|---|---|
| EPF | 2026-09-17 | EPF wage ceiling raised from ₹15,000 to ₹25,000 per month (S.O. 5109(E)) |
| TDS | 2026-04-01 | Salary TDS for tax year 2026-27 is governed by the Income-tax Act, 2025, not the Finance Act 2025 |
| EPF | 2026-09-17 | EPFO wage ceiling changed effective 17-Sep-2026: September 2026 intra-month treatment not established |
| EPF | 2026-09-17 | EPF v2: EDLI ceiling and minimum admin charge contradicted by EPFO guidance; September split-period treatme… |
| TDS | 2026-04-01 | TDS v3: new-regime rates for salary TDS and 2025-Act deduction references not established |

## 3. Known gaps for the specialist

- **Gratuity.** The rule is stored (Payment of Gratuity Act 1972, as amended), but **no calculation uses it**: PeopleOS
  does not compute gratuity in final settlements. The specialist and owner decide whether launch requires it. Building
  it is a product change, outside this programme.
- **LWF** has the thinnest test coverage (2 files) of the active India statutes.
- **Conflicting sources** must be recorded as conflicts: the rule stays unverified, with the responsible specialist named
  until resolved.

## 4. Engine validation (the gate cannot be bypassed)

| Check | Evidence |
|---|---|
| Enforcement is always on in production, even when `PEOPLEOS_ENFORCE_VERIFIED_RULES=false` | `RegulatoryReadinessTest` ("forces verified-rule enforcement in production regardless of the development override"); mutant G1 |
| An unverified rule raises a **blocking** exception at calculation | `StatutorySafetyTest`; mutant G2 |
| Finalisation re-checks every rule version used (verified, unchanged checksum) | `StatutorySafetyTest`; mutant G3 |
| A statutory export must pass the production gate first | `ProductionGateTest`; mutant G4 |
| An open regulatory notice blocks the rule it affects | `StatutoryUpdatesTest`, `RegulatoryReadinessTest`; mutant G5 |
| A rule is verified only by a second operator against official evidence | `RuleVerificationTest` (SaaS.9 and earlier) |
| Readiness shows the statutory gate as BLOCKED and never declares production readiness | `PlatformReadinessTest` |

Mutant results are in the final report §17. A killed mutant means a test fails when that check is removed.

## 5. Sign-off

Use §5 of `SaaS-11-Statutory-Verification-Evidence-Package.md`, one row per rule version, with:
- the reviewer;
- the qualification;
- the source document and clause;
- the date;
- the outcome (verified, not applicable, or conflict);
- the approval reference.

The rule is then verified in the product by a second operator (Compliance → Rules → Verify), and the evidence document is
attached. Never mark it verified in the database directly.
