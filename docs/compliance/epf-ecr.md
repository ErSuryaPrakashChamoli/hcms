# EPF / ECR Guide

For: payroll and compliance operators preparing EPF Electronic Challan-cum-Returns (ECR).

## What EPFO requires (official sources)

From EPFO's "Revamped ECR" page (epfo.gov.in/revamped-ecr) and the "User Manual Re-engineered ECRs"
v3.0 (employer portal):

- The revamped ECR applies **from wage month September 2025 onwards**.
- Return filing and payment are **separate steps** ("Segregation of Return and Payment").
- The portal applies **system-based validations** on upload and can compute damages and interest.
- The ECR **format is unchanged** ("No change in the existing format of the ECR"); the portal's Help
  File holds the field layout. PeopleOS has not retrieved that Help File.
- Return types:
  - **Regular** — active members for the wage month; contribution rate selected as 12% or 10%.
  - **Supplementary** — only members not in an earlier return of the month; several are allowed;
    needs an approved regular return and no other return in process.
  - **Revised** — only the members being revised; overwrites earlier values once approved; needs an
    approved regular return, no other return in process and **no payment initiated** for the
    month; **downward revision only before payment**; no restriction on upward revision.
- After a four-month relaxation, a regular return is accepted only if returns for all active members
  of the month four months earlier were filed (chronological filing).

## How PeopleOS builds it

- One return per **establishment × wage month × kind** (plus a sequence for supplementary and
  revised returns), from **finalized** payroll only. The establishment of each payroll entry was
  recorded at calculation (`payroll_entries.establishment_id`); entries calculated before Phase 5
  are attributed through the employee's establishment assignment and flagged.
- Members are employees whose finalized payroll has a `PF_EE` line.
- Tables: `epf_return_runs` (return content), `epf_return_entries` (members), `epf_return_revisions`
  (what a revised return changes, the direction and the payment attestation).
- Calculated bases (`calc_*`, from payroll lines) are stored apart from exported values
  (`export_*`, whole rupees):

| ECR field | Calculated from |
|---|---|
| UAN | Employee statutory detail (encrypted; masked on screen and API) |
| Gross wages | Payroll entry gross |
| EPF wages | PF line basis `base` (restricted to the ceiling when the profile says so) |
| EPS wages | min(EPF wages, the EPS ceiling of the **same rule version payroll used**) |
| EDLI wages | min(PF wages, the EDLI ceiling of that rule version) |
| EPF contribution (employee) | `PF_EE` amount |
| EPS contribution | `PF_ER` basis `eps` |
| EPF–EPS difference | `PF_ER` basis `epf` |
| NCP days | Payroll loss-of-pay days (rounded; fractional values warned) |
| Refund of advances | 0 (not modelled) |

## Validations

Blocking: no EPF establishment code for the month; wage month in the future or payroll not
finalized; no members; missing or non-12-digit UAN; duplicate UAN; zero EPF wages; inconsistent PF
basis; the rule version payroll used differs from the one effective for the month (blocking when
enforcement is on); rule checksum changed; rule not VERIFIED (when enforcement is on);
supplementary or revised return without an approved regular return, or with another return in
process; supplementary member already filed; downward revision without the payment attestation.

Warnings: rule not verified (enforcement off); fractional NCP days; establishment attributed at
return time; missing previous-month regular return (chronological order); EPS eligibility not
modelled; export layout not verified.

Reconciliation (blocking on any difference): employee share, employer share (EPS + difference), EPF
wages and gross against the payroll lines of the included entries; member count; establishment
members in payroll (regular returns); return totals against entries.

## Export

`UAN#~#NAME#~#GROSS#~#EPF#~#EPS#~#EDLI#~#EE#~#EPS SHARE#~#ER DIFF#~#NCP#~#REFUND`, one member per
line. The layout is marked `review`, so the file name starts with `UNVERIFIED-FORMAT_` until a
platform administrator verifies the layout against the EPFO Help File. Exporting never files.

## Revisions in practice

1. Correct payroll (reopen, fix, re-finalize).
2. On the approved return, choose "Revise members", pick the members, give a reason, and attest that
   no payment has been initiated if any amount goes down.
3. Validate, approve (by another person), export, file on the portal and record the TRRN.
4. Approving the revision marks the earlier return REVISED; snapshots link old and new values.

## Known limitations

- The 10% contribution option, EPS eligibility by age or joining date, refunds of advances, and
  arrear ECRs are not modelled.
- Payments, challans, damages and interest stay on the EPFO portal.
- The EPF rule version is DRAFT: wage ceiling, EPS, EDLI and admin parameters are not verified.
