# Final statutory verification register

Date: 11 October 2026. **For:** the owner and the statutory specialist(s) to be engaged.

**This is the authoritative statutory register.** It supersedes `SaaS-12-Statutory-Verification-Register.md` (kept as
history). The specialist's worksheet and sign-off record remain `SaaS-11-Statutory-Verification-Evidence-Package.md`; full
data: `evidence/SaaS-11-statutory-rules.json`.

> **0 of 24 rule versions are verified; 5 regulatory notices are open; no specialist is engaged.** No rule is labelled
> verified. In production the verified-rules gate refuses to finalise payroll or export a return that uses an unverified
> rule, or a rule with an open notice. Since 11 October 2026 it also refuses payroll in a jurisdiction for which
> PeopleOS holds no rule at all.

## 1. Launch-market coverage

The intended markets are India, the USA, the UK, the EU and the UAE.

| Market | Payroll statutory rules in PeopleOS | Payroll in production today | What is needed |
|---|---|---|---|
| India | 23 rule versions (EPF, ESI, PT for 9 states, LWF for 7 states, TDS, gratuity), all unverified | **Blocked** by the verified-rules gate | Specialist verification per rule; gratuity decision (§3) |
| UAE | 1 rule (GPSSA pension for UAE nationals), unverified | **Blocked** by the verified-rules gate | Specialist verification; confirmation that no other UAE payroll statute applies to the launch scope |
| USA | **None** (country pack only: formats, labour defaults) | **Blocked**: no rules (fix `064c262`) | An owner decision: exclude payroll for the USA from launch, or commission specialist-defined federal and state rules. Nothing is invented |
| UK | **None** (country pack only) | **Blocked** (`064c262`) | As for the USA (PAYE, National Insurance, workplace pension) |
| EU | **None** (no country pack for any member state) | **Blocked** (`064c262`) | As above, per member state |

**Billing in these markets is a separate question.** Tax rules for invoicing (GST, VAT, US sales tax) are SaaS.7 tax
configuration (B-8, B-9), with live payments off.

Payroll is not the only capability that depends on country rules. Leave minimums and notice periods come from country
packs as defaults that tenants configure. They are not statutory calculations.

## 2. Rule register

The columns follow the programme's requirements:
- **Specialist review**: none, until engaged.
- **Approval**: BLOCKED until a second operator records `verified` against official evidence, after the specialist's
  sign-off.
- **Source**: the stored lead only. The specialist must replace it with the authoritative document.

| ID | Jurisdiction | Rule and calculation scope | Effective | Source | Specialist review | Implementation | Tests (calculation, boundary, refusal) | Uncertainty | Approval |
|---|---|---|---|---|---|---|---|---|---|
| 2 | IN | EPF v1: Provident fund contributions (employee, employer, EPS, EDLI, admin) | 2014-09-01 | EPF & MP Act 1952; EPFO circulars (stored lead, unverified) | **None** | `StatutoryEngine` (EPF block); EPF ECR return | Calculation: CTC split at the wage ceiling. Boundary: EPF v1/v2 change on 17 Sep 2026; above-ceiling exemption. Refusal: verification blocked on open questions | draft; 2 open notice(s) | BLOCKED |
| 3 | IN | EPF v2: Provident fund contributions (employee, employer, EPS, EDLI, admin) | 2026-09-17 | EPF Scheme, 2026 (G.S.R. 525(E), 29 Jun 2026, CG-DL-E-010720 (stored lead, unverified) | **None** | `StatutoryEngine` (EPF block); EPF ECR return | Calculation: CTC split at the wage ceiling. Boundary: EPF v1/v2 change on 17 Sep 2026; above-ceiling exemption. Refusal: verification blocked on open questions | review; 2 open notice(s) | BLOCKED |
| 4 | IN | ESI v1: Health insurance contributions within the wage ceiling | 2019-07-01 | ESIC — Contribution (with ESIC — Coverage, https://esic.gov. (stored lead, unverified) | **None** | `StatutoryEngine` (ESI block); ESI return | Calculation: low wages. Boundary: drop-out within the contribution period; ceiling eligibility. Refusal: published as DRAFT or REVIEW, never VERIFIED | review; 0 open notice(s) | BLOCKED |
| 24 | IN | GRATUITY v1: Gratuity at exit | 2018-03-29 | Payment of Gratuity Act 1972 (amended 2018) (stored lead, unverified) | **None** | **None**: no calculation uses it | **None** | draft; 0 open notice(s); no consumer | BLOCKED |
| 17 | IN-DL | LWF v1: State labour welfare fund in contribution months | 2015-01-01 | Bombay Labour Welfare Fund Act 1953 (as applied to Delhi) (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 19 | IN-GJ | LWF v1: State labour welfare fund in contribution months | 2015-01-01 | Gujarat Labour Welfare Fund Act 1953 (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 20 | IN-HR | LWF v1: State labour welfare fund in contribution months | 2020-01-01 | Punjab Labour Welfare Fund Act 1965 (Haryana) (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 15 | IN-KA | LWF v1: State labour welfare fund in contribution months | 2018-01-01 | Karnataka Labour Welfare Fund Act 1965 (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 14 | IN-MH | LWF v1: State labour welfare fund in contribution months | 2015-01-01 | Maharashtra Labour Welfare Fund Act 1953 (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 16 | IN-TG | LWF v1: State labour welfare fund in contribution months | 2015-01-01 | Telangana Labour Welfare Fund Act 1987 (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 18 | IN-TN | LWF v1: State labour welfare fund in contribution months | 2015-01-01 | TN Labour Welfare Fund Act 1972 (stored lead, unverified) | **None** | `StatutoryEngine` (LWF block); LWF return | Calculation and boundary: contribution months only. **Thinnest coverage (2 files)** | draft; 0 open notice(s) | BLOCKED |
| 8 | IN-AP | PT v1: State professional tax by slab | 2013-02-01 | AP Tax on Professions Act 1987 (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 11 | IN-GJ | PT v1: State professional tax by slab | 2022-04-01 | Gujarat Professions Tax Act 1976 (amended 2022) (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 6 | IN-KA | PT v1: State professional tax by slab | 2023-04-01 | Karnataka Tax on Professions Act 1976 (amended 2023) (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 13 | IN-KL | PT v1: State professional tax by slab | 2016-04-01 | Kerala Municipality Act 1994 (half-yearly, monthly equivalen (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 5 | IN-MH | PT v1: State professional tax by slab | 2023-04-01 | Maharashtra State Tax on Professions Act 1975 (Sch. I, amend (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 12 | IN-MP | PT v1: State professional tax by slab | 2018-04-01 | MP Vritti Kar Adhiniyam 1995 (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 7 | IN-TG | PT v1: State professional tax by slab | 2014-06-02 | Telangana Tax on Professions Act 1987 (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 10 | IN-TN | PT v1: State professional tax by slab | 2018-04-01 | TN Municipal Laws (half-yearly, shown as monthly equivalent) (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 9 | IN-WB | PT v1: State professional tax by slab | 2013-04-01 | WB State Tax on Professions Act 1979 (stored lead, unverified) | **None** | `StatutoryEngine` (PT block, establishment state); PT return | Calculation: state slab per establishment. Boundary: February amount, female exemption. Refusal: legacy-profile state or no registration blocked | draft; 0 open notice(s) | BLOCKED |
| 1 | AE | SS v1: UAE pension contributions (nationals) | 2023-10-31 | Federal Decree-Law No. 57 of 2023 (stored lead, unverified) | **None** | `StatutoryEngine::applyGeneric` (pension branch) | Calculation: GPSSA on gross. Refusal: applicability switch off (EnterpriseTest) | draft; 0 open notice(s) | BLOCKED |
| 21 | IN | TDS v1: Salary income-tax withholding (projection, regimes) | 2025-04-01 to 2026-03-31 | Finance Act 2025 (stored lead, unverified) | **None** | `TaxComputer` / TDS engine; TDS returns | Calculation: regimes, rebate, chapter VI-A limits, no-PAN rate. Boundary: payment-date cases A–F. Refusal: open questions blocked | draft; 0 open notice(s) | BLOCKED |
| 22 | IN | TDS v2: Salary income-tax withholding (projection, regimes) | 2026-04-01 | Finance Act 2025 rates carried forward pending Finance Act 2 (stored lead, unverified) | **None** | `TaxComputer` / TDS engine; TDS returns | Calculation: regimes, rebate, chapter VI-A limits, no-PAN rate. Boundary: payment-date cases A–F. Refusal: open questions blocked | draft; 1 open notice(s) | BLOCKED |
| 23 | IN | TDS v3: Salary income-tax withholding (projection, regimes) | 2026-04-01 | Income-tax Rules, 2026 (G.S.R. 198(E), Central Board of Dire (stored lead, unverified) | **None** | `TaxComputer` / TDS engine; TDS returns | Calculation: regimes, rebate, chapter VI-A limits, no-PAN rate. Boundary: payment-date cases A–F. Refusal: open questions blocked | review; 1 open notice(s) | BLOCKED |

**Statute-agnostic refusal tests**, which apply to every rule:
- unverified or noticed rules block calculation, finalisation and export (`StatutorySafetyTest`, `ProductionGateTest`,
  `RegulatoryReadinessTest`, `StatutoryUpdatesTest`; mutants G1–G5 killed);
- production always enforces;
- a jurisdiction without rules blocks (`EnterpriseTest`, 11 Oct 2026).

## 3. Gratuity

- **Status:** not implemented.
  - A stored rule exists (Payment of Gratuity Act 1972, as amended 2018; parameters `rate`, `ceiling`, `minimum_years`,
    all unverified), but no calculation uses it.
  - The exit settlement computes final-period salary, leave encashment, notice recovery and asset recovery, with no
    gratuity line; operators can add only manual lines.
  - A payroll classification labelled "Gratuity provision" is a tenants' component label, not a calculation.
- **Whether it is mandatory for launch is undecided** (owner, with the specialist). The stored rule cites the Payment
  of Gratuity Act 1972; whether and how it applies to the launch customers is for the specialist. Whether PeopleOS must
  compute it, or the customer settles it outside PeopleOS with a manual settlement line, is a scope and product
  decision. Engineering does not decide it.
- **If required:** the sequence in the owner action register (O-12) applies. The specialist approves a specification
  first, implementation follows it, the specialist verifies, and it stays behind the verified-rules gate. **If
  excluded:** record the decision and tell customers that settlement lines for gratuity are their own figures.

## 4. Conflicts and uncertainty

Five notices are open (EPF wage-ceiling change and intra-month treatment; EDLI ceiling and admin charge; TDS 2026-27 rule
basis and new-regime rates). Each blocks its rules until the specialist resolves it with a new rule version or a recorded
resolution. Where sources conflict, the rule stays unverified and the responsible specialist is named here.

## 5. Sign-off

Per rule: reviewer, qualification, source document and clause, date, outcome (verified, not applicable with the owner's
approval, or conflict), approval reference. Use the worksheet in `SaaS-11-Statutory-Verification-Evidence-Package.md` §5.
Verify in the product through Compliance → Rules → Verify (a second operator). Never set the status in the database.
