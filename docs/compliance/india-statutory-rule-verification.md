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

## 8. Phase 7 statutory updates (28 September 2026)

Two controlled updates were published as **new versions**. Nothing existing was overwritten, and
nothing was verified.

| Rule | Version | Effective | Status | Evidence stored | Not confirmed (blocks verification) |
|---|---|---|---|---|---|
| EPF | v2 | 17 Sep 2026 | REVIEW | PIB release 16 Sep 2026 (PDF), PIB release 23 Sep 2026 (HTML) — corroborating only | Every parameter, including the ₹25,000 wage and EPS ceilings: the Gazette notification S.O. 5109(E) text was not obtained. EDLI, admin charges, rounding and eligibility are carried from v1 unchanged and not inferred |
| TDS | v3 (corrects v2) | 1 Apr 2026 | REVIEW | Income Tax Department "TDS Compliance FAQs"; the Finance Act, 2026 (No. 4 of 2026) gazette | `old` (slabs traced to Part III Para A, but standard deduction, rebate and deduction limits are in the Income-tax Act, 2025, not retrieved), `new` (section 202, not retrieved), `surcharge_new_regime_cap`. Covered: legal basis, deduction trigger, cess 4%, surcharge rates |

- **EPF v1** (₹15,000 ceiling) is untouched and still resolves for dates before 17 Sep 2026.
- **Open notice (EPF v1 and v2):** "EPFO wage ceiling changed effective 17-Sep-2026. Exact
  intra-month September payroll treatment requires authoritative implementation evidence before
  verification." Neither version can be verified while it is open.
- **TDS by payment date:** salary paid up to 31 Mar 2026 → Income-tax Act, 1961 (TDS v1, FY 2025-26);
  paid from 1 Apr 2026 → Income-tax Act, 2025, section 392(1) (TDS v3, tax year 2026-27). Payroll
  periods carry an optional `payment_date` (default: period end) and payroll engine `payroll-2.2`
  resolves the TDS rule, tax year, year-to-date and ledger month by it.
- Evidence files and SHA-256 hashes: `database/data/compliance/evidence/README.md`.

Current state after Phase 7: 24 rule versions — 3 REVIEW (ESI v1, EPF v2, TDS v3), 21 DRAFT,
**0 VERIFIED**; 3 open notices (EPF v1 wage ceiling; TDS v2 legal basis; EPF v1+v2 September
2026 treatment). Statutory production readiness is not declared.

## 9. Phase 8 evidence reconciliation (1 October 2026)

The open EPF / TDS blockers were re-examined against newly retrieved official texts. The new
evidence is recorded as an append-only **evidence revision** (`evidence_revisions` in the pack,
submitted once per version, label `pack:in.php#phase-8-2026-10-01`). Earlier submissions stay in the
history. Nothing was verified and no notice was resolved.

| Rule | Now covered | Still not confirmed | Contradicted by official guidance |
|---|---|---|---|
| EPF v2 | `wage_ceiling` — S.O. 5109(E), 17 Sep 2026: ₹25,000 per month for Chapter III of the Code on Social Security, 2020, from publication | `eps_wage_ceiling`, `eps_rate`, `employee_rate`, `employer_rate`, `edli_rate`, `admin_rate`, `round` (EPFO FAQ illustrations only; the scheme texts were not retrieved) | `edli_wage_ceiling` (v2 carries ₹15,000; EPFO FAQ Q5 applies the new ceiling to EDLI) and `admin_minimum` (v2 carries ₹75; FAQ Q13: ₹500 for an establishment with a contributing member) |
| TDS v3 | `legal_basis` (s.392(1); Finance Act 2026 s.3(10)(ii)), `deduction_trigger` (ITD FAQ Q6.22: salary by date of payment), `cess_rate` (s.3(16)), `surcharge` (Part III Para F) | `new` (values match s.202(1), s.19(1), s.156(2), but the text applying s.202 rates to s.392 salary TDS was not found, and 80CCD(2) is a 1961-Act section), `old` (values match Part III Para A, s.19(1), s.156(1); deduction identifiers and HRA are 1961-Act provisions), `surcharge_new_regime_cap` (only in the advance-tax table) | — |

Corrections to Phase 7 citations (in the new submission; earlier records unchanged):
- TDS v3 cited "Finance Act, 2026 s.2(10)(ii)" and "s.2(16)"; the provisions are s.3(10)(ii) and
  s.3(16).
- Its deduction trigger cited the general "earlier of credit or payment" rule (ITD FAQ Q6.1). The
  salary-specific rule is Q6.22 (date of payment), which is what payroll already applies.

New open notices (5 in total):
- **EPF v2:** the two contradicted values and the September 2026 split.
  - EPFO's FAQs prorate September by days (1–16 at ₹15,000, 17–30 at ₹25,000) and are internally
    inconsistent.
  - PeopleOS payroll applies the version effective on the period end to the whole wage month.
  - EPFO's ECR instructions "are being issued".
- **TDS v3:** the s.202 → s.392 rate linkage, the 1961-Act deduction references and the
  advance-tax-only surcharge cap.

**What a second verifier needs:**
- the EPF / EPS / EDLI scheme texts;
- S.O. 2702(E) of 29 May 2026;
- EPFO's ECR instructions for September 2026;
- the Income-tax Rules, 2026;
- a qualified ruling on the s.202 / s.392 linkage.

Then publish corrected versions where values change, resolve the notices and verify.

Current state after Phase 8: 24 rule versions — 3 REVIEW, 21 DRAFT, **0 VERIFIED**; 5 open notices.
Statutory production readiness is not declared.

## 10. Phase 9 evidence maintenance (1 October 2026)

Phase 9 was not a statutory readiness phase. Newly retrieved official texts were recorded as a
further evidence revision (`pack:in.php#phase-9-2026-10-01`) of EPF v2 and TDS v3:

- No payload changed and no version was published.
- Nothing was verified and no notice was resolved or added.
- Payroll is unchanged.

Files and SHA-256 hashes: `database/data/compliance/evidence/README.md`.

**Retrieved (official domains only):**
- **EPF / EPS / EDLI Schemes, 2026:** EPF Scheme G.S.R. 525(E), EDLI Scheme G.S.R. 526(E) and EPS
  G.S.R. 527(E), all of 29 Jun 2026 and made under the Code on Social Security, 2020.
  - The EPF Scheme supersedes the EPF Scheme, 1952.
- **Rate notifications of 1 Jul 2026:**
  - S.O. 3580(E): EPS 8⅓%.
  - S.O. 3581(E): EDLI 0.5%.
  - S.O. 3582(E): EPF 12%, deemed in force from 21 Nov 2025.
- **Corrigenda** G.S.R. 703(E), 704(E) and 705(E) of 4 Aug 2026.
- **S.O. 2702(E)** of 29 May 2026: ₹15,000 for Chapter III, with no commencement clause. It is
  superseded by S.O. 5109(E).
- **Income-tax Rules, 2026** (G.S.R. 198(E), in force 1 Apr 2026).

| Rule | Covered by notified text | Still open (not decided here) |
|---|---|---|
| EPF v2 | `wage_ceiling` (S.O. 5109(E); EPF para 18(3))<br>`eps_wage_ceiling` (EPS para 4(1), 11(3))<br>`employee_rate`, `employer_rate` (EPF para 18(2); S.O. 3582(E))<br>`edli_rate` (S.O. 3581(E))<br>`round` (EPF 18(5), EPS 4(3), EDLI 5(3): nearest rupee, 50 paise up) | `edli_wage_ceiling` — **contradicted by notified text**<br>`eps_rate` — **two notified texts differ**<br>`admin_rate`, `admin_minimum` — **not notified** |
| TDS v3 | Unchanged. The Rules add rules 204 / 205 / 215 / 219 and Form No. 130 ("Surcharge, wherever applicable"; "Health and education cess @ 4%"). | `new`, `old`, `surcharge_new_regime_cap` — the Rules prescribe no rates, map no 1961 sections and do not settle the cap |

**EPF v2 open items, in detail:**
- **`edli_wage_ceiling`:** EDLI para 5(1) applies the clause (89) ceiling, which is ₹25,000 from
  17 Sep 2026. v2 carries ₹15,000.
- **`eps_rate`:** EPS para 4(1) says "eight and thirty-three hundredths per cent"; S.O. 3580(E)
  says "eight and one-third per cent".
- **`admin_rate`, `admin_minimum`:** EPF para 29(1) leaves the percentage to a notification, and
  none was found. The "₹500" in para 29(2) is a daily late fee.

**Not modelled:**
- the 10% rate for notified classes;
- the S.O. 3582(E) exceptions;
- the 9.49% EPS joint option.

**September 2026:** still not established by notified text.
- EPF para 18(4) uses "wages actually drawn or payable during the month".
- EPS para 11(1) prorates pensionable wages per wage-ceiling period for pension, not for
  contributions.
- No EPFO ECR instruction or circular after 25 Sep 2026 was found. The "Wage Ceiling Circular
  28.09.2026" re-posts E-1345653.
- This is recorded for the future payroll remediation phase. The blocking notice stays open.

**EPF v1:** from 29 Jun 2026 its legal basis is the Code and the 2026 Schemes (S.O. 2702(E),
₹15,000), not the EPF & MP Act, 1952 that its source names. Its values are consistent with those
texts apart from the unnotified administrative charges. v1 is not changed.

**Not found:**
- a notification fixing EPF administrative charges under the 2026 Scheme;
- EPFO ECR instructions for September 2026;
- a CBDT circular on salary TDS for tax year 2026-27.

**What a second verifier still needs:**
- **EPF v2:**
  - a ruling on the EDLI ceiling, then a corrected version;
  - a ruling on the EPS rate wording (8.33% or 8⅓%);
  - the administrative-charge notification;
  - EPFO's September 2026 instructions.
- **TDS v3:** a qualified ruling on the three open questions.

Current state after Phase 9: 24 rule versions — 3 REVIEW, 21 DRAFT, **0 VERIFIED**; 5 open notices.
The Phase 8 EPF notice says the scheme texts were "not retrieved". Notices are immutable, so this
revision supersedes that statement without editing the notice.

**Statutory production readiness: NOT DECLARED.**
