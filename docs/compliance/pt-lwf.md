# Professional Tax and Labour Welfare Fund Guide

For: payroll and compliance operators handling state levies.

## Principle: the state comes from the establishment

Professional tax (PT) and labour welfare fund (LWF) are state levies. PeopleOS resolves them as:

```
Employee → establishment assignment on the period end date → establishment state
        → establishment statutory profile (PT / LWF applicable?) → state rule version
```

The company's country or the legacy company profile never decides the state for a return. The
payroll line records `state` and `state_source`:

| state_source | Meaning | Effect on the PT return |
|---|---|---|
| `establishment` | From the employee's establishment | Accepted |
| `employee_override` | From the employee's statutory detail (legacy override) | Warning: assign the employee to an establishment in that state |
| `legacy_company_profile` | From the pre-Phase-5 company profile | **Blocking** |
| missing | Line calculated before Phase 5 | **Blocking**: recalculate on engine payroll-2.1 |

## Professional tax return

- One return per **establishment × state × month** (`professional_tax_returns`,
  `professional_tax_return_entries`); `ProfessionalTaxProfile` is the PT view of an establishment
  statutory profile and `ProfessionalTaxRuleVersion` the PT view of the rule store.
- Each line: employee, state, how the state was found, gross used, PT deducted, rule version.
- Blocking: establishment without a state (generation refused), no PT registration certificate,
  state on the line different from the establishment's, state from the legacy profile or unknown,
  no PT rule version for the state and month, rule mismatch or unverified (when enforced).
- Warnings: the rule version does not state the return frequency (monthly, quarterly or annual
  differs by state), employee override, unverified rule (enforcement off), export format.

## Labour welfare fund return

- One return per **establishment × state × month** (`lwf_returns`, `lwf_return_entries`).
- Contribution months, employee and employer amounts and any wage ceiling come from the state's LWF
  rule version (extensible: a new state is a new rule version, not code).
- Blocking: no LWF registration, no LWF rule for the state, a month that is not a contribution month
  but has deductions, state mismatch, rule mismatch or unverified (when enforced).

## Exports

Both exports are generic **reconciliation schedules** (employee, state, amounts). Every state and
welfare board prescribes its own form and portal; these files are not state return files and are
prefixed `UNVERIFIED-FORMAT_`.

## Current rule status

PT rules for MH, KA, TG, AP, WB, TN, GJ, MP and KL and LWF rules for MH, KA, TG, DL, TN, GJ and HR
are all DRAFT: no official state source has been attached yet. States without a rule version (for
example Rajasthan) raise `statutory_rule_missing` in payroll and block their returns.
