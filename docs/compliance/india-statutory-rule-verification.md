# India Statutory Rule Verification Guide

For: platform administrators who maintain the statutory rule library, and compliance reviewers.

## 1. What "verified" means

A statutory rule version (EPF, ESI, professional tax per state, labour welfare fund per state, TDS
on salary) is **VERIFIED** only when a platform administrator who did not submit its evidence has
confirmed that an official government source supports every parameter of its payload.

Payroll may be finalized only on VERIFIED, intact rule versions when
`payroll.enforce_verified_rules` is on. It is on by default in every environment
(`PEOPLEOS_ENFORCE_VERIFIED_RULES`, default `true`). The test harness switches it off because the
packs are not verified.

Verification is about evidence, not about code. Passing tests never make a rule verified.

## 2. Status lifecycle

| Status | Meaning | Who moves it |
|---|---|---|
| DRAFT | Published by a pack, no official evidence yet | Pack sync |
| REVIEW | Official evidence attached, waiting for a reviewer | Pack sync (with `evidence`) or a platform admin ("Submit evidence") |
| VERIFIED | A second platform admin confirmed the evidence | Platform admin other than the submitter ("Verify") |
| SUPERSEDED | A verified correction with the same effective date replaced it | Automatic on verifying the correction |
| REJECTED | Evidence contradicts the payload or the version is wrong | Platform admin ("Reject", reason required) |

Every step is an append-only row in `compliance_rule_verifications` and a platform audit event
(`STATUTORY_RULE_CREATED`, `_REVIEWED`, `_VERIFIED`, `_REJECTED`, `_SUPERSEDED`).

## 3. Rules for evidence

- **Official sources only.** The source URL must be HTTPS on an authoritative domain listed in
  `peopleos.compliance.authoritative_domains` (for India: `gov.in`, `nic.in`, `epfindia.gov.in`,
  `epfo.gov.in`, `esic.gov.in`, `incometax.gov.in`, `incometaxindia.gov.in`, `tdscpc.gov.in`,
  `egazette.gov.in`, `indiacode.nic.in`, `labour.gov.in` and their subdomains). Blogs, payroll
  vendors, consultants and news articles are refused.
- **Required fields:** source URL, source title, effective date, the requirement text quoted from
  the source, and a mapping of **every** payload parameter to that requirement. The publication
  date is recorded when the source states one.
- **Ambiguous or partial evidence stays in REVIEW.** A mapping entry that says "NOT CONFIRMED" is a
  reason not to verify.
- **No invented numbers.** No guessed rate, no vendor rate, no "industry standard" rate, and no
  statutory percentage in PHP code (enforced by an architecture test).

## 4. Rule versions are immutable

- Identity (jurisdiction, code, state, version), dates, name and payload never change; the checksum
  (SHA-256 of the canonical payload) proves it.
- `peopleos:compliance:sync` only inserts new versions. If a pack entry differs from the stored
  version, the sync fails with "publish it as version N+1".
- A correction is a new version. When it is verified, earlier versions with the same effective date
  become SUPERSEDED.
- A verified version's evidence (source URL, title, date, verifier) cannot be changed.
- Payroll records the rule id, version and checksum of every rule it consulted. Finalization
  re-checks that each is still VERIFIED and unchanged.

## 5. How to verify a rule

1. Compliance → Compliance rules. Filter by status DRAFT.
2. Open "Submit evidence" on the version. Paste the official URL, title, effective date, quoted
   requirement text, and map each payload key. Save: the version moves to REVIEW.
3. A different platform administrator opens "Verify", re-reads the source, and records
   verification notes. The version moves to VERIFIED.
4. If a parameter is wrong, "Reject" it and publish a corrected version in the pack.

## 6. Evidence gathered on 28 September 2026 (Phase 5 discovery)

Only government sites were used. None of it makes a rule VERIFIED; it is candidate evidence for the
reviewer.

| Rule | Source | What it supports | What it does not |
|---|---|---|---|
| ESI v1 (now in REVIEW) | ESIC "Contribution" — https://esic.gov.in/contribution | Employee 0.75% and employer 3.25% w.e.f. 01.07.2019; contribution periods Apr–Sep and Oct–Mar; employees with a daily average wage up to ₹176 exempt from the employee share; payment within 15 days of month end | Rounding rule |
| ESI v1 | ESIC "Coverage" — https://esic.gov.in/coverage | Coverage wage limit ₹21,000/month w.e.f. 01.01.2017; ₹25,000 for persons with disability | — |
| EPF v1 (DRAFT) | EPFO "Revamped ECR" — https://www.epfo.gov.in/revamped-ecr/ and the "User Manual Re-engineered ECRs" v3.0 (pmvbry.epfindia.gov.in; SHA-256 `3667e099…40f7`) | Contribution rate selectable as 12% or 10% on the return; return types and revision rules (see EPF / ECR guide) | Wage ceiling, EPS and EDLI rates and ceilings, admin charges |
| TDS (DRAFT) | Income Tax Department — "Form No. 138 (Earlier Form No. 24Q)"; "Form No. 130_131_132_133 (Earlier Form No. 16/16A/…)"; Form No. 130 FAQ | Form 138 replaces 24Q (Rule 219, Income-tax Rules, 2026); Form 130 replaces Form 16; salary TDS under section 392 of the Income-tax Act, 2025 | Slabs, rebate, surcharge, cess |
| PT (9 states), LWF (7 states), GRATUITY, UAE SS | none retrieved | — | Everything; they stay DRAFT |

Known gaps a reviewer must resolve before verifying:

- **ESI:** rounding not confirmed; the ₹25,000 limit for persons with disability and the ₹176
  daily-wage exemption are not modelled by payroll. ESI returns warn when the rule version does not
  carry the exemption threshold.
- **EPF:** the 10% contribution option is not modelled; EPS eligibility (age 58+, post-Sept-2014
  higher-wage members) is not modelled.
- **TDS FY 2026-27 (v2):** cites the Finance Act 2025 "carried forward", but from 1 April 2026 salary
  TDS is governed by the Income-tax Act, 2025. It must be re-authored as a new version and must
  not be verified as is.
- **PT:** state filing frequency is not in the rule payloads; PT returns warn about it.

## 7. Current state

At the end of Phase 6: 22 rule versions — 1 REVIEW (ESI), 21 DRAFT, **0 VERIFIED**; 2 open
regulatory notices (EPF v1 wage ceiling from 17 Sep 2026; TDS v2 legal basis for tax year 2026-27).
With enforcement on, production payroll cannot be finalized and no statutory output can pass the
production gate. This is intended.

Phase 6 added stored evidence documents, parameter-level coverage, correction versions and
regulatory notices: see `docs/architecture/statutory-production-readiness.md`. The ESI submission
from Phase 5 has `round` not confirmed and no stored document, so it cannot be verified until a
reviewer supplies both.
