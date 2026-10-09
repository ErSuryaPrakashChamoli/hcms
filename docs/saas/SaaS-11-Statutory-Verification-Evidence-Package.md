# SaaS.11 — Statutory verification evidence package

Date: 10 October 2026. For a qualified statutory specialist (Indian payroll compliance: EPF, ESI, professional tax,
labour welfare fund, income tax; and UAE pension for the one AE rule).

> **No rule is verified.** Of 24 stored rule versions, 21 are `draft` and 3 are in `review`; 5 regulatory notices are
> open. Production finalisation refuses any unverified rule or a rule with an open notice: the enforcement is always on
> in production, whatever the environment override says (`ComplianceRules::enforced()`). Nothing in this package
> verifies anything. It is the specialist's worksheet.

Machine-readable inventory, with every parameter as stored:
- `docs/saas/evidence/SaaS-11-statutory-rules.json`, listing for each rule:
  - its consumers;
  - its verification events;
  - its evidence documents (sha256);
  - its parameter reviews;
  - its notices.

## 1. What the reviewer verifies, per rule

For each rule version:
1. **Authoritative source.** Use the official gazette, act, rules or notification, or the authority's own published
   rate table, and record its URL, title and date of publication. A secondary source (a blog, a payroll vendor's
   summary) is not acceptable. The stored "source" column is a lead to follow, not evidence.
2. **Every parameter** (rates, ceilings, slabs, months, rounding, exemptions) against that source, with the clause.
3. **Effective dates:** the date the rule version starts and, if superseded, ends.
4. **Applicability:**
   - state, establishment size, wage thresholds;
   - for PT, the slab basis (monthly or annual) and any gender or month special cases.
5. **Notices:** resolve each open notice (§3), either by a new rule version or by a recorded resolution.

The verification itself happens in PeopleOS, never by editing data:
1. Attach the official document (`compliance_evidence_documents`, sha256 recorded).
2. Record each parameter's review.
3. A **second platform operator** moves the rule to `verified` (`RuleVerifications`; maker-checker, audited).

PeopleOS refuses verification without official-domain evidence.

## 2. Inventory (24 rule versions)

Reviewer columns are blank on purpose: to be completed by the specialist.

| ID | Code | State / country | Version | Effective | Status | Evidence docs | Open notices | Stored source (lead only) | Reviewer | Review date | Approval |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 2 | EPF | IN | v1 | 2014-09-01 | draft | 0 | 1, 3 | EPF & MP Act 1952; EPFO circulars | | | |
| 3 | EPF | IN | v2 | 2026-09-17 | review | 16 | 3, 4 | Gazette Notification S.O. 5109(E) (cited, not retrieved); Ministry of Labour & Employment / PIB releases of 16 and 23 Sep 2026 <https://egazette.gov.in/WriteReadData/2026/273957.pdf> | | | |
| 4 | ESI | IN | v1 | 2019-07-01 | review | 0 | — | ESI (Central) Amendment Rules 2019; wage ceiling notification 2017 <https://esic.gov.in/contribution> | | | |
| 24 | GRATUITY | IN | v1 | 2018-03-29 | draft | 0 | — | Payment of Gratuity Act 1972 (amended 2018) | | | |
| 17 | LWF | DL | v1 | 2015-01-01 | draft | 0 | — | Bombay Labour Welfare Fund Act 1953 (as applied to Delhi) | | | |
| 19 | LWF | GJ | v1 | 2015-01-01 | draft | 0 | — | Gujarat Labour Welfare Fund Act 1953 | | | |
| 20 | LWF | HR | v1 | 2020-01-01 | draft | 0 | — | Punjab Labour Welfare Fund Act 1965 (Haryana) | | | |
| 15 | LWF | KA | v1 | 2018-01-01 | draft | 0 | — | Karnataka Labour Welfare Fund Act 1965 | | | |
| 14 | LWF | MH | v1 | 2015-01-01 | draft | 0 | — | Maharashtra Labour Welfare Fund Act 1953 | | | |
| 16 | LWF | TG | v1 | 2015-01-01 | draft | 0 | — | Telangana Labour Welfare Fund Act 1987 | | | |
| 18 | LWF | TN | v1 | 2015-01-01 | draft | 0 | — | TN Labour Welfare Fund Act 1972 | | | |
| 8 | PT | AP | v1 | 2013-02-01 | draft | 0 | — | AP Tax on Professions Act 1987 | | | |
| 11 | PT | GJ | v1 | 2022-04-01 | draft | 0 | — | Gujarat Professions Tax Act 1976 (amended 2022) | | | |
| 6 | PT | KA | v1 | 2023-04-01 | draft | 0 | — | Karnataka Tax on Professions Act 1976 (amended 2023) | | | |
| 13 | PT | KL | v1 | 2016-04-01 | draft | 0 | — | Kerala Municipality Act 1994 (half-yearly, monthly equivalent) | | | |
| 5 | PT | MH | v1 | 2023-04-01 | draft | 0 | — | Maharashtra State Tax on Professions Act 1975 (Sch. I, amended 2023) | | | |
| 12 | PT | MP | v1 | 2018-04-01 | draft | 0 | — | MP Vritti Kar Adhiniyam 1995 | | | |
| 7 | PT | TG | v1 | 2014-06-02 | draft | 0 | — | Telangana Tax on Professions Act 1987 | | | |
| 10 | PT | TN | v1 | 2018-04-01 | draft | 0 | — | TN Municipal Laws (half-yearly, shown as monthly equivalent) | | | |
| 9 | PT | WB | v1 | 2013-04-01 | draft | 0 | — | WB State Tax on Professions Act 1979 | | | |
| 1 | SS | AE | v1 | 2023-10-31 | draft | 0 | — | Federal Decree-Law No. 57 of 2023 | | | |
| 21 | TDS | IN | v1 | 2025-04-01 → 2026-03-31 | draft | 0 | — | Finance Act 2025 | | | |
| 22 | TDS | IN | v2 | 2026-04-01 | draft | 0 | 2 | Finance Act 2025 rates carried forward pending Finance Act 2026 verification | | | |
| 23 | TDS | IN | v3 | 2026-04-01 | review | 6 | 5 | Income-tax Act, 2025 s.392(1); Finance Act, 2026 (No. 4 of 2026) s.2(10), (16) and Part III of the First Schedule; Income Tax Department TDS Compliance FAQs <https://www.incometaxindia.gov.in/documents/81799/11848482/En-Notified-IT-Rules-2026-20-03-2026.pdf/a332bf2a-da14-8b94-dde2-5a2ea1428318?t=1773990110473> | | | |

**Parameters as stored (candidate, unverified).** These are abbreviated here; the full values are in the JSON:

| ID | Code | Parameters |
|---|---|---|
| 2 | EPF  v1 | `{"round": "nearest", "eps_rate": 0.0833, "edli_rate": 0.005, "admin_rate": 0.005, "wage_ceiling": 15000, "admin_minimum": 75, "employee_rate": 0.12, "employe…` |
| 3 | EPF  v2 | `{"round": "nearest", "eps_rate": 0.0833, "edli_rate": 0.005, "admin_rate": 0.005, "wage_ceiling": 25000, "admin_minimum": 75, "employee_rate": 0.12, "employe…` |
| 4 | ESI  v1 | `{"round": "ceil", "wage_ceiling": 21000, "employee_rate": 0.0075, "employer_rate": 0.0325, "contribution_periods": [[4, 9], [10, 3]]}` |
| 24 | GRATUITY  v1 | `{"rate": 0.04807692307692307, "ceiling": 2000000, "minimum_years": 5}` |
| 17 | LWF DL v1 | `{"months": [6, 12], "wage_ceiling": null, "employee_amount": 0.75, "employer_amount": 2.25}` |
| 19 | LWF GJ v1 | `{"months": [6, 12], "wage_ceiling": null, "employee_amount": 6, "employer_amount": 12}` |
| 20 | LWF HR v1 | `{"months": [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], "wage_ceiling": null, "employee_amount": 31, "employer_amount": 62}` |
| 15 | LWF KA v1 | `{"months": [12], "wage_ceiling": null, "employee_amount": 20, "employer_amount": 40}` |
| 14 | LWF MH v1 | `{"months": [6, 12], "wage_ceiling": null, "employee_amount": 25, "employer_amount": 75}` |
| 16 | LWF TG v1 | `{"months": [12], "wage_ceiling": null, "employee_amount": 2, "employer_amount": 5}` |
| 18 | LWF TN v1 | `{"months": [12], "wage_ceiling": null, "employee_amount": 20, "employer_amount": 40}` |
| 8 | PT AP v1 | `{"slabs": [[15000, 0], [20000, 150], [null, 200]]}` |
| 11 | PT GJ v1 | `{"slabs": [[12000, 0], [null, 200]]}` |
| 6 | PT KA v1 | `{"slabs": [[24999, 0], [null, 200]], "february_amount": 300}` |
| 13 | PT KL v1 | `{"slabs": [[1999, 0], [2999, 20], [4999, 30], [7499, 50], [9999, 75], [12499, 100], [16666, 125], [20833, 166], [null, 208]]}` |
| 5 | PT MH v1 | `{"slabs": [[7500, 0], [10000, 175], [null, 200]], "february_amount": 300, "female_exempt_upto": 25000}` |
| 12 | PT MP v1 | `{"slabs": [[18750, 0], [25000, 125], [33333, 167], [null, 208]], "march_amount": 212}` |
| 7 | PT TG v1 | `{"slabs": [[15000, 0], [20000, 150], [null, 200]]}` |
| 10 | PT TN v1 | `{"slabs": [[3500, 0], [5000, 22.5], [7500, 52.5], [10000, 115], [12500, 171], [null, 208]]}` |
| 9 | PT WB v1 | `{"slabs": [[10000, 0], [15000, 110], [25000, 130], [40000, 150], [null, 200]]}` |
| 1 | SS  v1 | `{"round": "nearest", "wage_floor": 3000, "wage_ceiling": 70000, "employee_rate": 0.11, "employer_rate": 0.15}` |
| 21 | TDS  v1 | `{"new": {"slabs": [[400000, 0], [800000, 0.05], [1200000, 0.1], [1600000, 0.15], [2000000, 0.2], [2400000, 0.25], [null, 0.3]], "rebate_max": 60000, "margina…` |
| 22 | TDS  v2 | `{"new": {"slabs": [[400000, 0], [800000, 0.05], [1200000, 0.1], [1600000, 0.15], [2000000, 0.2], [2400000, 0.25], [null, 0.3]], "rebate_max": 60000, "margina…` |
| 23 | TDS  v3 | `{"new": {"slabs": [[400000, 0], [800000, 0.05], [1200000, 0.1], [1600000, 0.15], [2000000, 0.2], [2400000, 0.25], [null, 0.3]], "rebate_max": 60000, "margina…` |

## 3. Open regulatory notices (5)

| ID | Code | Affects versions | Effective | Title |
|---|---|---|---|---|
| 1 | EPF | [1] | 2026-09-17 | EPF wage ceiling raised from ₹15,000 to ₹25,000 per month (S.O. 5109(E)) |
| 2 | TDS | [2] | 2026-04-01 | Salary TDS for tax year 2026-27 is governed by the Income-tax Act, 2025, not the Finance Act 2025 |
| 3 | EPF | [1, 2] | 2026-09-17 | EPFO wage ceiling changed effective 17-Sep-2026: September 2026 intra-month treatment not established |
| 4 | EPF | [2] | 2026-09-17 | EPF v2: EDLI ceiling and minimum admin charge contradicted by EPFO guidance; September split-period treatment not implemented |
| 5 | TDS | [3] | 2026-04-01 | TDS v3: new-regime rates for salary TDS and 2025-Act deduction references not established |

## 4. Dependencies: what each rule feeds

| Code | Used by |
|---|---|
| EPF | Payroll statutory contributions (StatutoryEngine), EPF ECR return (EpfReturns), parallel payroll, compliance readiness |
| ESI | Payroll statutory contributions (StatutoryEngine), ESI return (EsiReturns), parallel payroll, compliance readiness |
| PT | Payroll deduction (StatutoryEngine, PayrollRuns), income-tax computation (TaxComputer: PT is deductible), PT return |
| LWF | Payroll statutory deduction (StatutoryEngine), LWF return (LwfReturns) |
| TDS | Salary income-tax withholding (TaxComputer / TDS engine, PayrollRuns), quarterly TDS return and Form 16 (TDS returns and certificates) |
| SS | Pension contributions for UAE (AE) tenants (StatutoryEngine, the pension branch) |
| GRATUITY | No calculation consumes it today (stored rule only) |

A tenant's payroll needs, for its establishments' states:
- EPF and ESI (if registered);
- PT and LWF (per state);
- TDS for the tax year.

`RequiredRules` lists, per tenant, exactly which verified versions a run will need.

## 5. Sign-off record (to be completed)

| Item | Value |
|---|---|
| Reviewer (name, qualification, firm) | |
| Scope reviewed (rule IDs) | |
| Authoritative sources used | |
| Review date(s) | |
| Rules verified / rejected / corrected (new version needed) | |
| Notices resolved | |
| Approval by Markedge (owner) | |
| Second-operator verification in PeopleOS (operator, date) | |

## 6. Until then

- **Readiness:** `peopleos:readiness` reports the statutory gate as **BLOCKED** (24 rules, 0 verified, 5 open
  notices).
- **Payroll:** finalisation is refused for any run that needs an unverified rule.
- **Release:** the release gate stays **BLOCKED** on this item (`SaaS-11-Release-Gate-Register.md`).
