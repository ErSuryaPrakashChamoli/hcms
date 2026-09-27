# ESI Guide

For: payroll and compliance operators preparing ESI monthly contributions.

## Official basis

From esic.gov.in ("Contribution" and "Coverage", retrieved 28 September 2026):

- Employee contribution 0.75% and employer 3.25% of wages, w.e.f. 01.07.2019.
- Coverage wage limit ₹21,000 per month w.e.f. 01.01.2017 (₹25,000 for persons with disability).
- Employees with a daily average wage up to ₹176 are exempt from the employee contribution.
- Contribution periods: 1 April–30 September and 1 October–31 March.
- Contributions are payable within 15 days of the last day of the month.

The ESI rule version in PeopleOS is in **REVIEW** with this evidence attached. It is not VERIFIED:
rounding is unconfirmed, and the disability limit and the ₹176 exemption are not modelled by
payroll.

## How PeopleOS builds it

- One return per **establishment × month**, from finalized payroll entries that carry `ESI_EE`.
- Tables: `esi_return_runs`, `esi_return_entries`.
- Each line: IP number (encrypted, hashed, masked), contribution period (from the rule version's
  `contribution_periods`, e.g. `2026-04..2026-09`), paid days, ESI wages, employee and employer
  contributions (calculated), and exported days and wages.

## Validations

Blocking: no ESIC employer code; payroll not finalized or month in the future; missing IP number;
IP number not matching the configured pattern (10 digits, itself pending verification); duplicate
IP number; wages with zero days; zero ESI wages; rule mismatch or unverified rule (when enforcement
is on); employee contribution deducted for someone within the daily-wage exemption (only checked
when the rule version carries the threshold).

Warnings: the rule version carries no exemption threshold, so the exemption was not checked;
**coverage continuity** — an employee covered earlier in the contribution period has no ESI this
month (payroll decides coverage month by month, while ESI coverage continues to the end of the
contribution period); export layout unverified; unverified rule when enforcement is off.

Reconciliation: employee and employer contributions against payroll `ESI_EE` / `ESI_ER` lines,
entry count, establishment members, and return totals.

## Export

CSV with IP number, name, days, total monthly wages, and empty columns for the zero-day reason code
and last working day. The column layout follows the ESIC monthly upload template as commonly
published but was **not** verified against the ESIC portal; files are prefixed
`UNVERIFIED-FORMAT_`. PeopleOS does not submit to the ESIC portal.

## Known limitations

- Coverage continuation through the contribution period is detected, not applied, by payroll.
- Persons-with-disability limit, the ₹176 exemption, zero-day reason codes and last working day are
  not modelled.
