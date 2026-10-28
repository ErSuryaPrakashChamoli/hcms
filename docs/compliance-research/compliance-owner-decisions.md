# Compliance reports — owner decisions

Prepared 2026-10-27 and revised the same day after the remediation pass. This document lists the decisions and
actions this work cannot take on the owner's behalf. Each one says what stays blocked until it is made. Nothing
listed here was done automatically: no rule version was activated, no notice created, no stored version or
historical checksum changed.

The A-decisions, D7, C12 and C8 are written as full decision papers (§1–§3). The legal questions (B), the other
product decisions (C) and the release actions (D) are in the tables in §4–§6. The review pack for a labour-law
professional (D6) is `legal-review-pack.md`. Evidence:
- `evidence/2026-10-27c-time-sensitive.md` (the final pass: A2, A3, A5 and A8 re-checked);
- `evidence/2026-10-27b-verify-*.md` (the second pass);
- `evidence/2026-10-27-verify-*.md` (the first pass);
- `statutory-rule-change-register.md`.

The A-papers use the ten points set out in §1. The D7, C12 and C8 papers answer these ten:
1. the decision;
2. what it affects;
3. the current implementation;
4. the evidence;
5. the options;
6. the recommendation;
7. the implications;
8. what stays blocked;
9. the approval needed;
10. what follows approval.

## 1. Statutory rule versions (platform administrators)

Activation always goes through `RuleVerifications::verify()`. That needs a second platform administrator, stored
official evidence, complete parameter coverage and no open notice. "Approve" below means: authorise that process for
the version. It never means a direct database change. No version was activated in any pass.

Each paper answers ten points:
1. the exact question;
2. the jurisdictions and modules affected;
3. the current implementation, with engineering and legal correctness kept apart;
4. the verified legal evidence;
5. the missing or conflicting evidence;
6. the options;
7. the recommendation;
8. the business and compliance risks;
9. the implementation impact;
10. the approval required.

**Engineering correctness** means PeopleOS does what the stored or proposed version says, and tests show it.
**Legal correctness** means the version is right in law. The two are separate: a version can be engineered
correctly and be legally wrong, and the reverse.

### 1.0 Summary

| # | Engineering correctness | Legal correctness | Time-sensitive |
|---|---|---|---|
| A1 KA LWF | Annual flat LWF supported and tested; v2 data complete | v1 wrong since 10 Jan 2025, and its start date is wrong; v2 primary | CY 2026 deducted in December, paid by 15 Jan 2027 |
| A2 HR LWF | Rate-and-cap supported and tested (review pass) | v1's flat formula wrong since 2019; v2 primary; v3–v5 partial | **Before 31 Dec 2026** (s.9A(2)); 12% a year interest if unpaid a month later |
| A3 WB PT | Slab PT supported; v2 matches every source's employee slabs | v1 wrong from 1 Oct 2026 (primary); v2's final text still on no reachable official host (re-checked) | **Now:** October 2026 salaries |
| A4 KL PT | **Gap:** the engine cannot model half-yearly, per-local-body PT with income exclusions | v1 and v2 both wrong in structure; rates need legal review | Half-year to February 2027 |
| A5 Bonus | Register built and tested; provisional | B1–B3 open; S.O. 4711(E) still a mirror copy only (re-checked) | **FY 2025-26 bonus due by 30 Nov 2026** (Code on Wages s.39(1), read on India Code: primary) |
| A6 Gratuity | Entitlement at exit, coverage and register built and tested; provisional from 21 Nov 2025 | Ceiling partial; B10, B11, B13 open | **Ongoing:** every exit since 21 Nov 2025 |
| A7 Notices | Notice mechanism built and tested | W1–W11 confirmed from sources | **Ongoing:** payroll uses known-wrong versions without a warning |
| A8 OD PT | No version; nothing deducted, consistent with the repeal | Repeal primary; no Repeal Act found on any official host; Assembly reassembly date now primary | **About 3 Nov 2026**, seven days away: the Ordinance ceases six weeks after the Assembly reassembled on 22 Sep 2026 |
| A9 TN LWF | Month is a version parameter | Conflicting sources | Next deduction December 2026 (stored) |
| A10 TN PT | **Gap:** half-yearly, per-local-body PT not modelled | Rules 2023 primary; v1 wrong in structure | January 2027 pay bill; paid by 31 Mar 2027 |
| A11 Other PT states | Monthly slab PT supported; annual and quarterly deduction not | Mixed: primary for AS, TR, MZ | Ongoing: no PT computed there |

### 1.1 Time-sensitive items: current legal position (checked 2026-10-27)

Evidence: `evidence/2026-10-27c-time-sensitive.md`. Official hosts only for primary labels. Access failures were
recorded, not bypassed.

| Item | Deadline | Position on 27-10-2026 | Label | Action now |
|---|---|---|---|---|
| **A8** Odisha PT | **About 3 Nov 2026** (Art. 213(2)(a): six weeks from reassembly; our calculation) | Repeal Ordinance in force from 1 Apr 2026 (re-read, primary). The Assembly session began on 22 Sep and rose on 28 Sep 2026 (Secretariat notice on cms.neva.gov.in, primary; new in this pass). Repeal Bill reported passed (secondary only). No assent, Act number or gazette found on any official host | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | Ask the Odisha Law or Commercial Tax Department this week; prepare an inactive version; counsel on the effect if the Ordinance ceases before an Act is in force |
| **A3** West Bengal PT | **Now:** October 2026 salaries | New slabs from 1 Oct 2026 (1406-F.T., re-read, primary). The final Schedule 1607-F.T. is on no reachable official host. Slabs are known from secondary sources and match the official draft; Part C's date conflicts | SECONDARY_SOURCE_ONLY for 1607-F.T. | Obtain 1607-F.T. from the Finance (Revenue) Department; create notice N5 |
| **A5** Bonus | **30 Nov 2026** for FY 2025-26 (eight months from 31 Mar 2026, absent an extension or dispute) | S.O. 4711(E) is dated 25-08-2026 (Gazette Ext. No. 4526; ₹21,000; deemed from 21 Nov 2025), mirror only. The s.39 time limit and the s.2(a) accounting year were then read on India Code at about 20:00 (primary) | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | Read S.O. 4711(E) on egazette.gov.in in a normal browser; B1–B3 to counsel. Register results stay provisional |
| **A2** Haryana LWF | **Before 31 Dec 2026** (s.9A(2)); 12% interest from a month later (s.9A(4)) | The ₹35 cap from January 2026, employer twice, ₹105 total, on the Labour Department's official table (re-confirmed). The instrument HLWB/REV/2026/3436 is still on no official host, and the Haryana e-Gazette failed TLS. The deposit date comes from the department's consolidated Act | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (amount and date primary on department pages) | Decide A2 before December payroll |
| A1 Karnataka LWF | December 2026 deduction; paid by 15 Jan 2027 | Not re-checked in this pass (primary since the second pass) | OFFICIAL_PRIMARY_SOURCE_VERIFIED | Decide A1 before December payroll |
| A6 Gratuity | Ongoing: every exit since 21 Nov 2025 | Not re-checked in this pass | Partial | Decide A6; until then settlements need a manual gratuity line |

### A1: LWF Karnataka v2 (₹50 / ₹100 from 10 Jan 2025)

1. **Question.** Should a second platform administrator verify proposed LWF KA v2 (₹50 employee / ₹100 employer
   from 10 Jan 2025), and should a correction of v1 dated from 2017-04-06 be published?
2. **Jurisdictions and modules.** Karnataka. Payroll (the December LWF deduction), the Karnataka LWF statement, and
   rule versions LWF KA v1 and v2.
3. **Current implementation.**
   - Payroll uses v1: ₹20 / ₹40, December wages, dated from 2018-01-01. v2 is proposed and in review; payroll
     ignores it.
   - Engineering: correct. The annual flat-amount deduction is supported and tested, and v2's data is complete.
   - Legal: v1's amounts are wrong from 10 Jan 2025, and its start date is wrong (6 Apr 2017).
   - PeopleOS does not test the coverage threshold (C11).
4. **Verified legal evidence.** OFFICIAL_PRIMARY_SOURCE_VERIFIED.
   - Karnataka Act 05 of 2025 (gazette 10-01-2025, in force at once), on klwb.karnataka.gov.in.
   - The law department's consolidation (dpal.karnataka.gov.in) gives the rate history:
     - ₹6 / ₹12 from 8 Feb 2011;
     - ₹20 / ₹40 from 6 Apr 2017;
     - ₹50 / ₹100 from 10 Jan 2025.
   - Act 05 of 2026 (in force 7 Jan 2026) lowers the coverage threshold from "more than fifty" to "ten or more
     persons".
5. **Missing or conflicting evidence.** None on the rates. The threshold's application is not modelled.
6. **Options.**
   - (a) Activate v2 now.
   - (b) Activate v2 and also publish a corrected v1 dated 2017-04-06. v1 cannot be edited, so this is a
     correction, not an edit.
   - (c) Wait.
7. **Recommendation.** (b). The 2018 start of v1 only matters for recalculating 2017.
8. **Risks.**
   - Business: the December 2026 deduction at ₹20 / ₹40 underpays the fund by ₹30 / ₹60 per employee. If PeopleOS
     ran December 2025 payroll, calendar year 2025 was underpaid the same way and arrears may be due.
   - Compliance: short contributions. Establishments with ten to fifty persons, covered from 7 Jan 2026, are not
     flagged.
   - The 2024 calendar year stays at ₹20 / ₹40 by statute: its register date is 31 Dec 2024, before the Act.
9. **Implementation impact.**
   - Data only: verify v2, and publish the v1 correction as a new version.
   - Tests: `RuleVerificationTest` counts, and a December 2026 payroll at ₹50 / ₹100.
   - Notice N4 resolved.
   - Blocked until decided: the correct Karnataka LWF for calendar years 2025 and 2026 (due 15 Jan 2026 and
     15 Jan 2027).
10. **Approval required.** "A second platform administrator verifies LWF KA v2 with the stored gazette copy; publish
    a correction of v1 dated 2017-04-06."

### A2: LWF Haryana v2–v5 (0.2% of wages, caps ₹25 / ₹31 / ₹34 / ₹35, employer 2×)

1. **Question.** Should Haryana LWF v2–v5 (0.2% of wages, with an employee cap of ₹25 / ₹31 / ₹34 / ₹35 by period,
   and the employer paying twice) be verified and activated: all, some, or none?
2. **Jurisdictions and modules.** Haryana. Payroll (the monthly LWF line), the Haryana LWF statement, and versions
   LWF HR v1–v5.
3. **Current implementation.**
   - v1 is active: a flat ₹31 employee / ₹62 employer every month. v2–v5 are proposed.
   - Engineering: rate-and-cap is supported and tested (review pass).
   - Legal: v1's flat formula has been wrong since 2019 (W4).
4. **Verified legal evidence.**
   - v2: OFFICIAL_PRIMARY_SOURCE_VERIFIED. Haryana Act 18 of 2019 (s.9A(1)) and the consolidated Act on
     hrylabour.gov.in.
   - The official board table re-confirms the ₹31, ₹34 and ₹35 caps.
5. **Missing or conflicting evidence.**
   - v3 and v4 rest on board letters (OFFICIAL_SOURCE_PARTIALLY_VERIFIED).
   - v5: the ₹35 cap is on the Labour Department's official contribution table (re-confirmed 27-10-2026), but the
     instrument, HLWB/REV/2026/3436 (08-05-2026), is still on no government host. The Haryana e-Gazette failed TLS on
     27-10-2026 (not bypassed). The consolidated Act's s.9A(1) proviso indexes the cap to the consumer price index
     each January. That is the basis for annual caps, but the legal form of each revision (an office letter) is
     unconfirmed.
   - B8: whether the ten-employee threshold applies only to shops needs legal review.
6. **Options.**
   - (a) Activate v2–v4 now, v5 once the gazette copy is obtained.
   - (b) Activate all four on the board's official table.
   - (c) Wait.
7. **Recommendation.** (a). The current year (v5) is the one that matters, so obtain HLWB/REV/2026/3436 first; the
   Haryana Labour Welfare Board can supply it. If it cannot be obtained before December 2026 payroll (the
   contribution is due before 31 Dec 2026), the owner may verify v5 on the department's official table, which
   already shows ₹35. That is option (b) for v5 only.
8. **Risks.**
   - Business: under v1, employees earning below ₹15,500 a month pay more than 0.2% of their wages. In 2026,
     employees above ₹17,500 pay ₹4 a month less than the ₹35 cap, and the employer ₹8 less.
   - Compliance: contributions computed on the wrong formula since 2020 in any PeopleOS payroll. The 2026
     contribution is due before 31 Dec 2026, with 12% a year interest if it is unpaid a month later (s.9A(2) and
     (4), department's consolidated text).
9. **Implementation impact.**
   - Data only.
   - Rate-and-cap payroll tests per year; notice N3 resolved.
   - Blocked until decided: the correct Haryana LWF for 2026.
10. **Approval required.** "Verify LWF HR v2, v3, v4", and "obtain the 2026 notification, then verify v5".

### A3: PT West Bengal v2 (nil to ₹20,000; ₹100 / ₹140 / ₹170 / ₹208 from 1 Oct 2026)

1. **Question.** Should PT WB v2 (nil to ₹20,000; ₹100 / ₹140 / ₹170 / ₹208 above that, from 1 Oct 2026) be
   verified and activated, with v1 ending on 30 Sep 2026?
2. **Jurisdictions and modules.** West Bengal. Payroll PT **from October 2026 salaries**, the West Bengal PT
   statement, and versions PT WB v1 and v2.
3. **Current implementation.**
   - Payroll uses v1: the 2014 slabs, nil only up to ₹10,000, top ₹200. v2 is proposed.
   - Engineering: slab PT is supported, and v2's data matches every source's employee slabs.
   - Legal: v1 is wrong from 1 Oct 2026. Its start date is also wrong: 1 Apr 2014, not 2013 (W6, N12).
4. **Verified legal evidence.**
   - The commencement notification 1406-F.T. (18-08-2026), OFFICIAL_PRIMARY_SOURCE_VERIFIED. The amended s.3(2)
     applies from 1 Oct 2026.
   - The draft Schedule 1407-F.T., OFFICIAL_SOURCE_PARTIALLY_VERIFIED.
   - Re-checked 27-10-2026: 1406-F.T. re-read on comtax.wb.gov.in.
5. **Missing or conflicting evidence.**
   - The final Schedule 1607-F.T. (16-09-2026, reported as SAR-588) was on no official host reachable on
     27-10-2026:
     - it is not in the comtax.wb.gov.in listings;
     - the PT portal is a script application;
     - egazette.wb.gov.in and wbprofessiontax.gov.in do not resolve;
     - wbfin.nic.in and wbcomtax.gov.in refused connections.
   - Its employee slabs are known only from secondary sources. They match the official draft.
   - Part C's date conflicts: 1 Oct 2026 in the draft, 1 Apr 2027 in secondary reports of the final. The employee
     slabs are not in Part C.
6. **Options.**
   - (a) Obtain 1607-F.T. from the Finance (Revenue) Department and verify.
   - (b) Verify on the draft plus the commencement notification.
   - (c) Wait.
7. **Recommendation.** (a), urgently: October salaries are being paid now.
   - Meanwhile create notice N5 so payroll users are warned.
   - If the official text cannot be obtained this month, the owner may choose (b), accepting that the final could
     differ from the draft. No source suggests the employee slabs do.
8. **Risks.**
   - Business: October 2026 payroll on v1 deducts ₹110–₹130 a month from employees earning ₹10,001–₹20,000, who
     owe nothing, and ₹8 too little in the top band. That leads to refunds and corrections.
   - Compliance: wrong deduction and remittance from October 2026.
9. **Implementation impact.**
   - Data only: verify v2; correct v1's dates through a new version.
   - A payroll test for October 2026 on both sides of ₹20,000; N5 resolved.
   - Blocked until decided: correct West Bengal PT from October 2026.
10. **Approval required.** "Obtain 1607-F.T.; a second administrator verifies PT WB v2." Or, if the owner accepts the
    risk of acting on the draft: "Verify PT WB v2 on 1406-F.T. and the draft 1407-F.T."

### A4: PT Kerala v2 (2024 half-yearly rates)

1. **Question.** Whether, and how, to bring Kerala PT up to date: activate v2 as it is, model Kerala PT half-yearly
   and per local body, or keep v1 with a warning.
2. **Jurisdictions and modules.** Kerala. Payroll PT, the Kerala PT statement, the PT engine, and establishment data
   (the local body).
3. **Current implementation.**
   - v1 (the 2016 rates divided by six, deducted monthly) is active. v2 (the G.O.'s 2024 rates divided by six) is
     proposed.
   - Engineering: **gap.** The engine deducts monthly, on gross pay, with one rate table per state. It cannot
     express half-yearly assessment, local-body rates, the s.204(3) exclusions or the 60-day half-year test.
   - Legal: both versions are wrong in structure, and the rates need legal review.
4. **Verified legal evidence.**
   - Panchayat Raj Act s.204(2): rates are "fixed by the Village Panchayat not exceeding the maximum rates
     prescribed".
   - The tax is half-yearly: assessed and paid by the end of August and February.
   - HRA, CCA, conveyance and TA are excluded from income (s.204(3)).
   - G.O.(Rt) 1149/2024/LSGD, read on an official host.
5. **Missing or conflicting evidence.**
   - No amendment of the Profession Tax Rules making the G.O.'s rates the new maximums was found.
   - No council resolutions adopting them.
   - Municipal provisions and due dates. The label is LEGAL_REVIEW_REQUIRED.
6. **Options.**
   - (a) Activate v2 as it is. Its structure is wrong: monthly, gross-based, uniform.
   - (b) Model Kerala PT as half-yearly and per local body, with the exclusions, as A10 proposes for Tamil Nadu.
   - (c) Keep v1 with notice N6.
7. **Recommendation.** (b), with (c) in the meantime. Do not activate v2 as it is.
8. **Risks.**
   - Business: deductions differ from what each local body demands, and employees with excluded allowances are
     over-deducted.
   - Compliance: wrong remittance each half-year.
9. **Implementation impact.**
   - Engine and data: a local-body rate table on the establishment, half-yearly deduction months and income
     exclusions. Shared with A10.
   - Engine tests per local body and half-year; a new proposed version per local body.
10. **Approval required.** "Model half-yearly local-body PT (Kerala and Tamil Nadu); do not activate KL v2."

### A5: BONUS v1 / v2

1. **Question.** Should BONUS v1 (Payment of Bonus Act, to 20 Nov 2025) and v2 (Code on Wages, central sphere, from
   21 Nov 2025) be verified, after the legal questions B1–B3 are answered?
2. **Jurisdictions and modules.** All states. v2 covers the central sphere; the state sphere depends on B1. The
   bonus register in compliance reports. Payroll does not compute bonus.
3. **Current implementation.**
   - Both versions are proposed.
   - The register computes month by month under the version in force, marks every result "Legally unverified", and
     uses Eligible / Ineligible / Pending determination / Insufficient data.
   - `verify()` refuses while `eligibility_ceiling` is NOT CONFIRMED (tested).
   - Engineering: built and tested (CALC).
   - Legal: B1–B3 are open.
4. **Verified legal evidence.**
   - S.O. 4710(E) (calculation ceiling: ₹7,000 or the central minimum wage, whichever is higher):
     OFFICIAL_PRIMARY_SOURCE_VERIFIED, signature valid.
   - The 2026 central minimum wages are official (CLC, 1 Apr 2026; Labour Bureau, 1 Oct 2026). For example,
     unskilled construction in A areas is ₹840 a day, which is ₹21,840 a month at the ×26 conversion of the Wages
     Rules r.3(2).
5. **Missing or conflicting evidence.**
   - S.O. 4711(E) (25-08-2026, Gazette Ext. No. 4526; eligibility "wages not exceeding twenty-one thousand rupees
     per mensum", deemed in force from 21 Nov 2025) is still read only on a mirror, without a signature that
     validates. egazette.gov.in failed TLS and labour.gov.in returned 403 to our tools on 27-10-2026.
   - (Resolved: the payment time limit, Code s.39(1) "within a period of eight months from the close of the
     accounting year", and s.2(a), an accounting year begins on 1 April, were read on India Code at about 20:00 on
     27-10-2026: primary.)
   - B1: do the central S.O.s bind state-sphere establishments?
   - B2: which minimum wage sets the ceiling? It decides most amounts, since ₹21,840 is far above ₹7,000.
   - B3: the FY 2025-26 transition between the Act and the Code.
6. **Options.**
   - (a) Obtain S.O. 4711(E) officially, answer B1–B3, decide C3 (hold minimum wages), then verify.
   - (b) Verify v1 only, for historical years.
   - (c) Leave both provisional.
7. **Recommendation.** (a). Until then, (c) is the safe default, and it is what the product does.
8. **Risks.**
   - Business: customers may pay FY 2025-26 bonus on provisional figures. At the ₹7,000 ceiling, the bonus of a
     worker whose applicable minimum wage is ₹21,840 a month is about a third of what the higher ceiling gives.
   - Compliance: under-payment of statutory bonus. FY 2025-26 bonus falls due by **30 Nov 2026** (eight months
     from 31 Mar 2026, absent an extension order or a dispute).
9. **Implementation impact.**
   - Data: verify the versions.
   - C3 (hold minimum wages) is a data model for the ceiling.
   - Register tests at the minimum-wage ceiling; remove "pending determination" for the ₹7,000 ceiling.
   - Blocked until decided: any bonus result other than "legally unverified".
10. **Approval required.** "Obtain S.O. 4711(E); counsel answers B1–B3; hold minimum wages (C3); then verify BONUS v1
    and v2."

### A6: GRATUITY v2 / v3, and gratuity at exit

1. **Question.** Should GRATUITY v2 (Code on Social Security, from 21 Nov 2025) and v3 (fixed-term one year, from
   8 May 2026) be verified? That also decides whether full and final settlements add gratuity automatically.
2. **Jurisdictions and modules.** All-India. The gratuity register, and the gratuity entitlement at exit
   (`GratuityEntitlements`, used by `FinalSettlements` and the register).
3. **Current implementation.**
   - v1 (Payment of Gratuity Act, 2018 ceiling) is active, with no end date, although that Act was repealed on
     21 Nov 2025.
   - v2 and v3 are proposed. For any date from 21 Nov 2025 the calculator uses them **provisionally**:
     - the result is "Legally unverified";
     - the amount is shown as provisional;
     - **no gratuity line is added to the settlement.**
   - A person can add a manual line; the calculator never adds a second.
   - Before 21 Nov 2025, v1 decides, but only when it is verified while enforcement is on (always on in production).
   - Engineering: calculator, coverage and register built and tested (GRAT, 9 tests).
   - Legal: the ceiling is partly verified; B10, B11 and B13 are open.
4. **Verified legal evidence.**
   - Code s.53–56, the First Schedule (Chapter V) and s.1(8): OFFICIAL_PRIMARY_SOURCE_VERIFIED (India Code;
     labour.gov.in).
   - The Payment of Gratuity Act s.1(3), 1(3A), 2(s), 2A, 4 and 7, re-read on labour.gov.in.
   - SS (Central) Rules r.33: primary.
   - S.O. 1420(E) of 29-03-2018 (₹20 lakh) is confirmed by an official DPE memorandum (dpe.gov.in).
5. **Missing or conflicting evidence.**
   - The S.O. 1420(E) gazette was not read on a government host.
   - No notification under Code s.53(3) was found; MoLE's FAQ says "currently ₹ 20 lakhs".
   - The ₹25 lakh widely reported is for Central Government employees under the CCS rules, not this ceiling.
   - B10 (four years and 240 days), B11 (the 50% wage rule) and B13 (late-payment interest).
6. **Options.**
   - (a) On counsel's advice that the 2018 ceiling continues under s.164(2)(a), verify v2 and v3.
   - (b) Wait for a s.53(3) notification.
   - (c) Verify v3 only, so settlements from 8 May 2026 decide.
7. **Recommendation.** (a), together with C6 (the 50% wage rule) and B10 (the 240-day rule).
8. **Risks.**
   - Business: until this is decided, every settlement since 21 Nov 2025 needs a manual gratuity line, so gratuity
     can be omitted. The calculator prevents counting it twice.
   - Compliance: gratuity is due within 30 days of becoming payable (Code s.56(3)), with interest if late.
9. **Implementation impact.**
   - Data: verify v2 and v3; publish a v1 correction ending 20 Nov 2025.
   - A settlement test with a verified version (the rule exists in tests as a fixture); update the notes in the
     register and the settlement.
   - Once verified, the calculator still holds these cases for a person:
     - terminations and absconding (forfeiture review);
     - a near-miss on five years with 240 days in the last year;
     - fixed-term exits before the term ends;
     - basic + DA below 50% of remuneration under the Code.
10. **Approval required.** "Counsel confirms the ₹20 lakh ceiling continues; a second administrator verifies GRATUITY
    v2 and v3."

### A7: Regulatory notices against stored versions found wrong

1. **Question.** Should the regulatory notices N1–N12 (§2 of the register) be created in
   `database/data/compliance/notices/in.php`?
2. **Jurisdictions and modules.**
   - LWF: Maharashtra, Tamil Nadu, Gujarat, Haryana, Karnataka.
   - PT: West Bengal, Kerala, Karnataka, Tamil Nadu, Madhya Pradesh, Andhra Pradesh.
   - Modules: payroll warnings, rule verification, the production gate.
3. **Current implementation.**
   - No notice has been created; the stored versions stay active and unchanged.
   - Engineering: the notice mechanism exists and is tested (`StatutoryUpdatesTest`). An open notice blocks
     verification of its version, warns payroll users, and under enforcement fails the production gate's
     `rules_verified` check for that rule.
   - Legal: W1–W11 confirmed or refined from sources.
4. **Verified legal evidence.**
   - **LWF:**
     - MH (W1): ₹25 / ₹75 from 18 Mar 2024; ₹6 / ₹18 or ₹12 / ₹36 before.
     - TN (W2): ₹10 / ₹20 from 23 Jan 2015 and ₹20 / ₹40 from 2 Dec 2022, both in official gazettes.
     - GJ (W3): ₹3 / ₹6 until 14 Nov 2016, ₹6 / ₹12 from 15 Nov 2016 (official gazette).
     - HR (W4), and KA (W5, plus the v1 start date of 2017-04-06).
   - **PT:**
     - WB (W6): v1 should start 2014-04-01.
     - KL (W7).
     - KA (W8): the February ₹300 applies only from 1 Apr 2025; Act 33 of 2025's gazette signature validates.
     - TN (W9): Rules 2023 rr.274–278.
     - MP (W10).
     - AP (W11): v1 should start 2013-02-06.
5. **Missing or conflicting evidence.** The Tamil Nadu LWF month is conflicting (A9). Kerala's structure (A4) and
   Tamil Nadu PT rates outside Chennai (A10) are incomplete.
6. **Options.**
   - (a) Create all the notices.
   - (b) Create only those affecting current payroll: HR, KA LWF, WB, KL, TN PT, MP.
   - (c) None.
7. **Recommendation.** (a). Notices only warn and block verification; they change no figure.
8. **Risks.**
   - Business: under enforcement, those rules fail the production gate, so payroll in those states stops showing as
     verified. That is intended: it makes the known errors visible.
   - Compliance: without the notices, nothing tells payroll users that these versions are out of date.
9. **Implementation impact.** Data only. Notice tests (`StatutoryUpdatesTest`), and the gate checks per state.
10. **Approval required.** "Create notices N1–N12 as worded in the register."

### A8: Odisha PT after the Ordinance

1. **Question.** Has the Repeal Act received assent? If not, should a proposed Odisha PT version (the 2000 Act's
   rates) be prepared for when the Ordinance lapses?
2. **Jurisdictions and modules.** Odisha. Payroll PT (none today), the Odisha PT statement and the catalogue entry.
3. **Current implementation.**
   - No Odisha version. The catalogue records the obligation up to 31 Mar 2026, and the repeal.
   - Engineering: nothing is deducted, which is consistent with the repeal.
   - Legal: the repeal is primary; whether it survives the Ordinance's lapse is unproven.
4. **Verified legal evidence.**
   - The Odisha State Tax on Professions, Trades, Callings and Employment (Repeal) Ordinance, 2026 (Ordinance 02 of
     2026; Gazette Extraordinary No. 1496, 21-04-2026), in force from 1 Apr 2026: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
   - **New in this pass:** the Assembly's seventh session commenced on 22 Sep 2026 and was adjourned sine die on
     28 Sep 2026. Source: Assembly Secretariat notice No. LM-51/2026-6543 (147)/L.A., 28-09-2026, on cms.neva.gov.in,
     re-read 27-10-2026. OFFICIAL_PRIMARY_SOURCE_VERIFIED.
   - Under Art. 213(2)(a) an Ordinance ceases to operate at the end of six weeks from the reassembly of the
     Legislature: 22 Sep 2026 plus 42 days is **3 Nov 2026**. That is our calculation; the Article was not re-read
     today.
5. **Missing or conflicting evidence.**
   - The Repeal Bill was introduced on 22 Sep 2026 and is reported passed by 28 Sep 2026, in secondary sources
     only: TeamLease, and Deccan Chronicle and Sambad English, which print the title without "(Repeal)".
   - No assent, Act number or Gazette publication was found on finance.odisha.gov.in, law.odisha.gov.in,
     egazette.odisha.gov.in or the Assembly's sites on 27-10-2026.
   - What happens to the repeal if the Ordinance ceases before an Act is in force is a legal question (D6 pack, A8).
6. **Options.**
   - (a) Confirm with the Odisha Commercial Tax or Law Department.
   - (b) Prepare a proposed version of the 2000 Act's rates now, in case it is needed.
   - (c) Wait.
7. **Recommendation.** (a) and (b), now: there are seven days to 3 Nov 2026.
   - Ask the Odisha Law or Commercial Tax Department whether the Repeal Act has been assented and notified.
   - Prepare a proposed, inactive version of the 2000 Act's rates, so it can be verified at once if needed.
   - Activate nothing until counsel answers.
8. **Risks.**
   - If the Ordinance lapses without an Act, PT revives from the lapse: employers must deduct again, and PeopleOS
     would not.
   - Deducting while the repeal stands would be unlawful.
9. **Implementation impact.**
   - Data: a proposed version of the 2000 Act's rates, dated from the lapse if there is one; the catalogue entry
     updated.
   - A payroll test.
10. **Approval required.** "Confirm the Repeal Act; if none, prepare and verify an Odisha PT version."

### A9: Tamil Nadu LWF deduction month

1. **Question.** Is the Tamil Nadu LWF deducted on June wages, or for the year ending December?
2. **Jurisdictions and modules.** Tamil Nadu. The payroll LWF month, the Tamil Nadu LWF statement, and version
   LWF TN v1.
3. **Current implementation.**
   - December, from Form A and board practice.
   - Engineering: the month is a version parameter, so switching is a data change.
   - Legal: the sources conflict.
4. **Verified legal evidence.**
   - The amounts are primary: ₹10 / ₹20 from 23 Jan 2015 and ₹20 / ₹40 from 2 Dec 2022 (official gazettes).
   - The official Form A and the board's 2022 press release say year ending December, paid by 31 January.
5. **Missing or conflicting evidence.** CONFLICTING_SOURCES: Rules r.12 says June wages, paid before 31 July.
6. **Options.**
   - (a) Ask the Tamil Nadu Labour Welfare Board in writing.
   - (b) Keep December.
   - (c) Switch to June.
7. **Recommendation.** (a), keeping (b) meanwhile.
8. **Risks.** If June is right, deductions are made at the wrong time and payment by 31 July is missed. The amounts
   are the same.
9. **Implementation impact.** Data only: a corrected version if June; notice N2 resolved. Blocked until decided: a
   verified Tamil Nadu LWF version.
10. **Approval required.** "Write to the board; record the answer as evidence."

### A10: Tamil Nadu PT, half-yearly and per local body

1. **Question.** Should Tamil Nadu PT be modelled as the law sets it: half-yearly, and per local body?
2. **Jurisdictions and modules.** Tamil Nadu. Payroll PT and its statement, the engine, and establishment data (the
   local body).
3. **Current implementation.**
   - v1 deducts monthly on the r.275(2) default table divided by six. That is wrong in structure for every local
     body, and wrong in amount in Chennai since October 2024 (bands 2–4 are ₹180 / ₹425 / ₹930 a half-year).
   - Engineering: **gap.** The engine cannot express the half-yearly, per-local-body model.
   - Legal: the rules are primary.
4. **Verified legal evidence.** TN Urban Local Bodies Rules, 2023 (G.O. Ms. No. 45, 12-04-2023, on a tn.gov.in
   host), rr.274–278, primary:
   - the band is chosen on the **average half-yearly income of the previous financial year**;
   - the tax is deducted from the **August and January** pay bills;
   - it is paid by **30 September and 31 March** with Form 14;
   - the r.275(2) default table applies until a council revises it.
   The Greater Chennai Corporation rates come from its official rate page (partial).
5. **Missing or conflicting evidence.** Rates of other councils and panchayats: NOT CONFIRMED. Exemptions
   (s.117-H).
6. **Options.**
   - (a) Build the half-yearly per-local-body model: the local body on the establishment, a rate table per body
     defaulting to r.275(2), deduction in August and January on the previous year's average.
   - (b) Keep v1 with notice N8.
   - (c) Stop computing Tamil Nadu PT and handle it outside payroll.
7. **Recommendation.** (a); (b) until it ships.
8. **Risks.**
   - Business: monthly deductions do not match the half-yearly demand, and Chennai bands 2–4 are under-deducted.
   - Compliance: wrong amounts each half-year. The next deduction is the January 2027 pay bill, paid by 31 Mar 2027.
9. **Implementation impact.**
   - An engine change; the same model serves Kerala (A4) and Puducherry.
   - Engine tests: an August and a January deduction, the previous-year average, the Chennai and default tables.
     Then a new proposed version.
10. **Approval required.** "Build the half-yearly local-body PT model (TN, KL, PY)."

### A11: PT versions for the other levying states

1. **Question.** Should PT rule versions be added for Assam, Bihar, Jharkhand, Puducherry, Punjab (development tax),
   Tripura, Sikkim, Nagaland, Mizoram, Manipur and Meghalaya?
2. **Jurisdictions and modules.** Those eleven states and union territories. Payroll, which computes no PT there
   today, and their statements, which are empty and say so.
3. **Current implementation.**
   - Researched, not in the rule pack.
   - Engineering: monthly slab PT is supported. Annual deduction (BR, MN, MZ) and quarterly deduction (JH) are not.
   - Legal: mixed (below).
4. **Verified legal evidence.** Primary: Assam (monthly, Form III by the last day of the next month, r.12(1)),
   Tripura and Mizoram.
5. **Missing or conflicting evidence.**
   - Partial: Jharkhand (Act and Rules on ctax.jharkhand.gov.in; commencement not found), Puducherry (2017 Act and
     Finance G.O. on official hosts; rates are maximums), Bihar, Nagaland, Manipur, and Sikkim (the department host
     has no modern TLS).
   - Legal review: Punjab (the income test under the Income-tax Act, 2025).
   - Conflicting: Meghalaya.
6. **Options.**
   - (a) Add proposed versions for AS, TR and MZ now, the others as evidence allows.
   - (b) Add all.
   - (c) None.
7. **Recommendation.** (a). Mizoram is annual, so its version needs the annual deduction model before payroll can use
   it.
8. **Risks.** Employers in these states get no PT deduction from PeopleOS: employees are under-deducted, and the
   employer is liable.
9. **Implementation impact.**
   - Data for AS and TR.
   - An engine change for annual and quarterly deduction.
   - Per-state payroll and statement tests.
10. **Approval required.** "Add proposed PT versions for AS, TR and MZ; plan annual and quarterly deduction models."

## 2. Historical integrity

### D7: Historical export-layout checksums on MySQL

> **Status (2026-10-27, final pass).**
> - **Owner decision:** option B. "Proceed with verification against the original layout files without rewriting
>   historical checksums."
> - **Implemented and verified for the five development layouts.**
> - **No stored value was rewritten:** checksums, algorithms, content and timestamps are byte-identical before and
>   after (see "Verification" below).
> - **Still open:** the maker-checker format verification of the five drafts against each authority's specification.
>   That is a separate, routine platform step, and it is not part of D7.

1. **Decision.** How export layouts sealed with the original checksum algorithm get verifiable integrity back on
   MySQL. The original D7 entry (2026-10-27) proposed to "recompute the checksums of unverified layouts in a
   reviewed migration". That rewrite was **not done**. Instead, the forward part of the fix was made without
   touching any existing record (below). The decision left is what to do with records sealed before the fix.
2. **Affected.**
   - Table `statutory_export_layouts`, rows where `checksum_algorithm` is NULL (sealed before migration
     `2026_11_09_100004`). It is the only affected record type. A census of every other checksum in the application (15 groups of sites) (the audit
     chain, TDS certificates, statutory snapshots, survey/plan/structure versions, offboarding signatures) found
     each one written once, built from relational data, or already sorting its keys.
   - Known affected rows: the local development MySQL database holds 5 (`EPF_ECR`, `ESI_MC`, `LWF_RETURN`,
     `PT_RETURN`, `TDS_FORM_138`, all v1). All 5 are `draft`, none has evidence attached, and all 5 are diagnosed
     `original_algorithm_unreproducible`.
   - Any other MySQL environment: **unknown**. Run the read-only check there first.
3. **Current implementation.**
   - New layouts are sealed `canonical-v2`: SHA-256 of `[code, version, specification]`, with the specification
     normalised through JSON, object keys sorted at every level and list order kept.
   - Original-algorithm rows keep their checksum byte-for-byte and are verified exactly as before. On SQLite they
     pass. On MySQL they fail, because MySQL's `json` type re-sorts keys on storage.
   - `ExportLayouts::integrity()` and `php artisan peopleos:compliance:layout-checksums` (read-only) tell the two
     apart:
     - "broken only by key order", where the stored checksum is the original algorithm over the layout pack's
       definition and the content is identical once key order is ignored;
     - real tampering.
   - `verify()` refuses an unreproducible row with a D7 message.
   - A database that has not yet run migration `2026_11_09_100004` reads its layouts as original-algorithm rows, without an error (`checksumAlgorithm()` reads the attribute array). New layouts can be created only after the migration.
   - `isVerified()` stays false for it, so exports carry the `UNVERIFIED-FORMAT_` prefix and the production gate's
     `export_layout_verified` check fails under enforcement. Everything fails closed.
4. **Evidence.**
   - The 9 failures were reproduced on fresh MySQL 8.4.11.
   - A representation probe showed key re-sorting, while `null`, Unicode, `1.0`, `-0.0` and empty `{}` / `[]`
     survive.
   - An upgrade rehearsal on a disposable database showed historical rows byte-identical after the migration,
     diagnosed correctly, and still accepted by `sync`.
   - The read-only inventory of the development database is above.
   - Tests: `ExportLayoutChecksumTest` (10) and `ExportLayoutConcurrencyTest` (2, MySQL).
   - **Per-layout evidence, 2026-10-27, final pass.**
     - Read-only, from the development database `hcm` (MySQL), where migration `100004` is not applied. SELECTs
       only; nothing was written.
     - Each row was compared with every committed version of `database/data/compliance/layouts/in.php` and with the
       working tree.
     - The pack has one commit, `05592313` (2026-09-28 08:57). It predates the rows (all created 2026-10-08 11:53:00)
       and has no staged or unstaged change.

     | Layout | Row id | Status | Stored checksum | Algorithm | Stored row re-hashed | Pack at `05592313` reproduces the stored checksum | Same content as the pack | Evidence attached |
     |---|---|---|---|---|---|---|---|---|
     | `EPF_ECR` v1 | 1 | draft | `573f77ff…611965` | original (no column yet) | differs (MySQL key order) | **yes** | yes | no |
     | `ESI_MC` v1 | 2 | draft | `37d0082a…ae47d5` | original | differs | **yes** | yes | no |
     | `PT_RETURN` v1 | 3 | draft | `8fbb254f…02e758` | original | differs | **yes** | yes | no |
     | `LWF_RETURN` v1 | 4 | draft | `46a76b21…19f200` | original | differs | **yes** | yes | no |
     | `TDS_FORM_138` v1 | 5 | draft | `28805a3b…38fe21` | original | differs | **yes** | yes | no |

     - **Independent verification is possible** for all five. The stored checksum is exactly the original
       algorithm applied to the pack definition committed ten days before the row was written, so the seal can be
       re-derived from version control rather than from the row itself.
     - What it proves: the row was sealed from that definition, and its content still equals it.
     - What it does not prove: the layout's correctness against the authority's format. That is what the
       maker-checker verification of a draft is for, and none of these five has been verified or carries evidence.
     - Full output: `/home/administrator/Documents/hrms/hcms-compliance-research-archive-20261027/test-runs/d7evidence-output.txt`.
5. **Options.**
   - **A. Leave them; re-publish as needed.** Rows stay unverifiable on MySQL. To use one, publish an identical new
     version (`publishVersion()`), which is sealed canonically, and verify it maker-checker. No data change.
     Returns keep pointing at the version they were exported with.
   - **B. Compatibility verification against the layout pack (code only).** Treat an original-algorithm row as
     intact when (i) its stored checksum equals the original algorithm over the pack definition of the same code
     and version, and (ii) its stored content equals that definition once key order is ignored.
     - No record changes.
     - It depends on the pack file staying unchanged; `sync()` already refuses a changed version.
     - It cannot help rows created with `publishVersion()`, which have no pack definition; those need A.
   - **C. Re-seal beside the original (additive migration).** Add `canonical_checksum`, `resealed_at`,
     `resealed_by` and `reseal_basis`. For each row that passes B's proof, write the canonical checksum *next to*
     the untouched original, with one audit event per row (original checksum and algorithm, new checksum, SHA-256
     of the pack definition used). Rows that fail the proof are not resealed. Rollback drops the new columns; the
     original seal was never touched.
   - **D. Overwrite the checksum in place.** Not recommended. It destroys the original seal, so it can no longer be
     shown what was sealed, and it breaks the rule that sealed records are immutable.
   - **Risks of each option.**

     | Option | Risk | Mitigation |
     |---|---|---|
     | A | The five formats stay unusable for verified exports on that database until re-published. A second, identical version can confuse users | The read-only command names the rows; the old version stays a draft |
     | B | Integrity then depends on the pack file. An edited pack could "explain" a tampered row | `sync()` refuses a changed version, and the check also requires equal content. Proof is tied to the committed pack (git history), not only the working tree |
     | C | Adds columns and a write path to sealed records (additive, but a migration on every environment) | The original seal is never touched; per-row audit; rollback drops only the new columns |
     | D | Destroys the original seal and the immutability rule | Not recommended |
6. **Recommendation.**
   - B for pack-originated rows. All five known rows qualify (evidence above), and none is verified, so nothing
     already relied on changes.
   - A for any other row.
   - C only if the database must stand without the pack file.
   - Never D.
7. **Implications.**
   - Legal: none. Layouts are file formats, not statutory rules.
   - Operational: until decided, every MySQL environment that synced layouts before the migration blocks verified
     statutory exports for those five formats. New databases are unaffected.
   - Integrity: B and C preserve the original evidence; D does not.
   - Migration: none for A or B. C needs an additive migration and a per-row audited command.
8. **Blocked until decided.** Verifying the five original-algorithm layouts on any MySQL database that holds them,
   including the development database, and therefore a passing production gate for EPF, ESI, PT, LWF and Form 138
   exports there.
9. **Approval needed.** For example: "Approve option B for `statutory_export_layouts` rows with
   `checksum_algorithm` NULL whose code and version exist in `database/data/compliance/layouts`, and option A for
   all other such rows." Or name option C.
10. **After approval.**
    - B: tests that a key-order-only row becomes intact; that tampered content, a missing pack entry or a changed
      pack entry stay unverifiable; and a MySQL run.
    - C: a rehearsal on a disposable MySQL database (`migrate:fresh`, roll back `100004`, write rows as the old code
      did, migrate, reseal, then the read-only check shows `intact`), with audit events asserted per row, the
      original checksum asserted unchanged, and a rollback test.
    - Either: update this paper and `docs/architecture/statutory-production-readiness.md`.

**Implementation of option B** (uncommitted, 2026-10-27):
- `ExportLayouts::integrity()` returns `intact_against_pack` where it used to return the diagnosis
  `original_algorithm_unreproducible`. The conditions are unchanged:
  - the row was sealed with the original algorithm;
  - its stored checksum is exactly that algorithm's checksum of the layout pack's definition of the same code and
    version;
  - its stored content equals that definition once key order is ignored.
- Anything else is `mismatch`: no pack entry, a pack definition other than the one sealed, changed content or
  checksum, or an unknown algorithm.
- `StatutoryExportLayout::sealHolds()` combines the direct check with the pack proof. `verify()`, `isVerified()`, and
  therefore the export prefix and the production gate, use it.
- The read-only command `peopleos:compliance:layout-checksums` reports the new state. It still fails on a mismatch.
- Tests in `ExportLayoutChecksumTest`:
  - a key-order-only row is proven and passes maker-checker verification, with its checksum, algorithm and content
    unchanged;
  - tampered content, a different sealed definition, and a missing pack entry stay `mismatch`. They cannot be
    verified, and never count as verified even when forced.

**Verification of the five development layouts** (database `hcm`, MySQL; read-only):

| Layout | Original layout file | File checksum | Stored checksum | Algorithm | Method | Result |
|---|---|---|---|---|---|---|
| `EPF_ECR` v1 | `database/data/compliance/layouts/in.php` at commit `05592313` (blob `da50676f`) | SHA-256 `15eb39d3…f4d4` | `573f77ff…611965` | original | Original algorithm over the committed definition equals the stored checksum; canonical stored content equals the definition | **intact_against_pack** |
| `ESI_MC` v1 | same | same | `37d0082a…ae47d5` | original | same | **intact_against_pack** |
| `PT_RETURN` v1 | same | same | `8fbb254f…02e758` | original | same | **intact_against_pack** |
| `LWF_RETURN` v1 | same | same | `46a76b21…19f200` | original | same | **intact_against_pack** |
| `TDS_FORM_138` v1 | same | same | `28805a3b…38fe21` | original | same | **intact_against_pack** |

- **The file predates the records.** Commit `05592313` is dated 2026-09-28 08:57 and is on the remote (`origin/main`
  and four feature branches). The rows were created 2026-10-08 11:53. The working-tree file is byte-identical to the
  commit.
- **Evidence.**
  - `test-runs/d7-dev-verification-2026-10-27.txt`: the read-only command, 5 of 5 `intact_against_pack`, exit 0.
  - `test-runs/d7evidence-output-recheck.txt`, identical to `d7evidence-output.txt`: per-row stored values and the
    comparison with every committed version of the pack.
  - Both are in the research archive, outside the repository.
- **No historical value changed.** The re-run of the evidence script after the change is byte-identical to the run
  before it. No migration was run on the development database: `100004` is still pending there, and the code reads
  its rows as original-algorithm rows.
- **Remaining uncertainty.**
  - The match proves that each row was sealed from that committed definition and still holds its content. It does not
    prove the format is correct against the authority's specification: that is what maker-checker verification is
    for, and none of the five is verified yet.
  - Commit timestamps are set by the committer. The anchor that does not depend on them is the remote copy of the
    commit.
  - Other MySQL environments are unknown until the read-only command is run there.

Migration and test plan for any environment:
1. Run `php artisan migrate` (adds the empty column).
2. Run `php artisan peopleos:compliance:layout-checksums` and record the counts. It exits non-zero on a real
   mismatch, which must be investigated before anything else.
3. Rehearse the chosen option on a disposable copy of the schema.
4. Apply it.
5. Run the check again.

### C12: Establishment statutory profiles changed after payroll

1. **Decision.**
   - Accept, after review, the historical-consistency invariant and the guard implemented on 2026-10-27 (below).
   - Then decide R1–R4: how a genuinely wrong profile for a month already finalised is corrected, and the cases
     the guard does not cover.
2. **Affected.** These code paths were traced from the code, not from labels. "Other session" means uncommitted
   work by another session on 2026-10-27, not touched since 12:53.

   | Step | Code | Notes |
   |---|---|---|
   | Close | `EstablishmentStatutoryProfileResource` action `close` → `update(['effective_to' => …])`, reason required | Since Phase 5 |
   | Reopen | action `reopen` → `update(['effective_to' => null])` | Other session |
   | Delete | action `delete` → `delete()`. The model refuses an open profile, and one that `usedByLockedPayroll()` (a run past review whose period ends inside the profile's own window) | Other session. Until then profiles were never deleted |
   | Create | the page's create action; the ADR-0001 backfill `StatutoryRegistrations::backfillFromLegacyProfile()` (`peopleos:legal-entities:backfill`), which creates an establishment's five first profiles one at a time | Since Phase 5 |
   | Model rules | `EstablishmentStatutoryProfile::booted()`: statute, establishment, settings, registration, no overlap. `ProfessionalTaxProfile` is the same table and model (statute PT) | — |
   | Applicability in payroll | `StatutoryEngine::apply()` → `StatutoryContexts::for()`. The establishment comes from `EstablishmentAssignments::resolve()` on the period end; `StatutoryContext::applies()` uses its profiles when it has any, otherwise the company statutory settings (`CompanyStatutoryProfile`, defaults when none is saved: EPF, ESI, PT and TDS on, LWF off) | Payroll periods are calendar months, so the period end is the month end |
   | Recorded at calculation | `payroll_entries.establishment_id` and `legal_entity_id`; `inputs.statutory_context` (establishment, its source, `profile_source`, `profile_ids`, PT and LWF state); each line's `basis` (rule id, version, checksum, status at calculation); `payroll_runs.rule_versions` | The applicable flag itself is not stored; the profile ids are |
   | Finalisation | `PayrollRuns::finalize()`. A finalised run is never recalculated. It refuses if compensation changed after calculation (fingerprint), but has no such check for the statutory context | Run states: draft, calculated, validated (editable), approved, finalized, paid |
   | Compliance reports | `ComplianceReports::applicability()` → `applies()`, at the report period end, for entries with `requires`: `pf_challan` (EPF), `esi_challan` and `esi_contribution_period` (ESI). Figures come only from `finalized` and `paid` runs (`ReportData`), and the pack index records the runs read (`payroll_runs`) | — |
   | Audit | `Auditable`: CREATE, UPDATE and DELETE with field-level before and after values and the reason, hash-chained | — |
   | Rule versions | `ComplianceRules::resolve()` by date, recorded on each line | Unaffected by profiles |

   **Foreign keys.**
   - A profile belongs to its tenant, company and establishment (`cascadeOnDelete`), its registration
     (`nullOnDelete`) and its creator (`nullOnDelete`).
   - Nothing references a profile by foreign key; payroll entries hold profile ids inside JSON only.
   - Deleting a profile therefore cascades nothing and removes no payroll evidence.
3. **Current implementation and findings** (before this pass's change).
   - **Case A, reopening.**
     - A profile is one row with one start and one end. Reopening sets the end to empty, so the profile applies
       again from its original start to every later date, including months already finalised without it.
     - The row keeps no versions. The date of the change and the old end date exist only in the audit trail.
     - The legal period and the date the user changed it are therefore not distinguished, except through the audit
       trail.
     - The model checked only for overlap.
   - **Case B, deleting the last profile.**
     - Deletion removes the row; the audit DELETE event keeps every field. Nothing cascades, and payroll keeps its
       amounts, establishment and profile ids.
     - With no profile left, the establishment falls back to the company statutory settings **for every date**.
       Months calculated from profiles are then read differently by any pack generated later.
     - The delete check looked only inside the profile's own window.
     - Future months follow the company settings; the page warns about this.
   - **Effect on reports.** A pack generated later could:
     - omit an obligation payroll met (the EPF challan "not applicable" for a month with EPF deducted);
     - or list one payroll never applied.
   - **Same effect from other edits:** closing earlier than a finalised month (possible since Phase 5), back-dated
     creation, and changing `applicable`.
   - **Also found.** `ComplianceReports::applies()` had its own copy of the fallback. With no saved company
     settings it answered "not applicable" where payroll applies the defaults.
4. **Invariant** (defined in this pass).
   > For every approved, finalised or paid payroll run, at every establishment whose entries in it were calculated
   > from establishment profiles, whether EPF, ESI, PT, LWF and TDS applied on the period end stays what it was when
   > the run was calculated. Compliance reports decide it with the same function as payroll.

   What can be reconstructed for a finalised month:

   | # | Item | Reconstructible? | From |
   |---|---|---|---|
   | 1 | Establishment and profile applied | Yes | `establishment_id`, `inputs.statutory_context` (`profile_source`, `profile_ids`); the row's values at that time from the audit trail |
   | 2 | Rule versions | Yes | line `basis`, `payroll_runs.rule_versions` |
   | 3 | Applicability decision and its configuration | Yes for months calculated from profiles (the guard keeps them unchanged). For months calculated from the company settings, only through the audit trail of those settings (R2) | profiles, `company_statutory_profiles`, audit |
   | 4 | Payroll calculation | Yes | entries and lines; a finalised run is never recalculated |
   | 5 | A report generated later | A built pack is frozen and fingerprinted. A new pack takes its figures from finalised payroll and its applicability from the configuration on the period end, which the guard keeps equal to payroll's for protected months | `compliance_report_packs`, the pack index |
   | 6 | Later profile changes and their audit | Yes | audit events with reason and before and after values |

   A pack does two different things, and they are not interchangeable:
   - it **reproduces** finalised payroll results (amounts are read, never recomputed);
   - it **evaluates** whether an obligation applies, from the configuration in force on the period end.

   The invariant is what makes the two agree for protected months; it is not assumed.
5. **Options considered.**

   | Safeguard | Assessment |
   |---|---|
   | Effective-dated profile versions (a new row per change) | Rows are already effective-dated. A version per edit would duplicate the audit trail. Not needed once edits cannot reach protected months |
   | Archive instead of hard delete | The delete checks and the audit DELETE event already keep the evidence. Archiving would touch every query. Not needed |
   | Snapshot applicability at finalisation, and make reports read it | A second source of truth for the same fact. Entries from before it would lack it, and it would diverge from the profile table. Not chosen; it is the route if corrections without reopening payroll are wanted (R1(c)) |
   | Link reports to the payroll context | Exists: packs read only finalised runs and record them (`payroll_runs`) |
   | Refuse changes that alter protected months | **Chosen.** One source of truth, smallest change, and it follows the rule payroll already has (a finalised run is never recalculated) |
   | Controlled correction workflow | Owner decision (R1) |
6. **Implemented** (uncommitted, 2026-10-27).
   - `StatutoryContexts::applicability($establishment, $dates, ?$profiles)`: for each date and statute, the answer
     payroll gives (`StatutoryContext::applies()`).
   - `StatutoryContexts::companySettings()`: payroll's company-settings lookup, moved into a method with unchanged
     behaviour.
   - `ComplianceReports::applies()` now uses `applicability()` instead of its own copy. This fixes the
     no-saved-settings difference.
   - `EstablishmentStatutoryProfile::assertPayrollHistoryKept()` runs on every save (after the overlap check) and
     every delete (after the other session's two checks, which are unchanged):
     - it takes the period ends of runs past review whose entries at the establishment were calculated from
       profiles (`inputs.statutory_context.profile_source = establishment_profile`);
     - it compares each statute's answer on those dates before and after the change;
     - it refuses the change if any answer differs. The message names the statutes and the months.
   - The check reads payroll regardless of the user's access scope.
   - Not changed: the Filament actions, `PayrollRuns` and the backfill.
   - A change that leaves every answer the same is allowed, for example an "exempt" period with `applicable` off over
     a month payroll already had as not applicable.
7. **Evidence.**
   - `tests/Feature/Compliance/StatutoryProfileHistoryTest.php`, 11 tests. They cover the brief's ten points:
     closing after finalisation, reopening (model and page), deleting the last profile, the report for a finalised
     month, later months after a legitimate change, effective dates (back-dated creation), establishment, tenant and
     access-scope isolation, audit of permitted and refused changes, the finalised run's lines and rule versions, the
     correction route while payroll is not yet past review, and the ADR-0001 transition.
   - One more test checks that a pack decides as payroll does when no company settings are saved.
   - **Mutation check:** with the two guard calls disabled, 9 of the 10 guard tests fail ("Exception not thrown").
     The tenth, the ADR-0001 test, is meant to pass either way. The file was restored and its checksum compared.
   - Results are in `compliance-report-test-matrix.md` §11.
8. **Residuals, needing owner decisions.**
   - **R1. Correcting a month already finalised.** A profile that was wrong for a finalised month can no longer be
     corrected in place.
     - (a) Keep refusing; correct through an adjustment in a later month, with a note.
     - (b) Build a controlled correction: a supplementary or reversal run plus the profile correction, with reason
       and approval, and a regenerated pack marked as a revision.
     - (c) Allow the edit with dual approval, snapshot the old applicability on the entries, and make reports read
       the snapshot.
     - Recommendation: (a) now, (b) when there is demand.
     - **Update (owner direction, 2026-10-27):** a controlled correction workflow was requested. It is designed and
       partly built as C12-W below: the configuration-only path, with approval. Its payroll treatment is decision
       C12-D1, which replaces R1's (a)–(c) for corrections that move money.
   - **R2. Months calculated from the company settings are not protected.**
     - Editing the company statutory settings, or creating an establishment's first profiles, can still change how
       a new pack reads those months.
     - The guard leaves them out on purpose: the ADR-0001 backfill creates first profiles one at a time and, for a
       Labour Welfare Fund state that differs from the establishment's, deliberately changes the answer.
     - Recommendation: guard edits to the company settings by the same rule. Make the backfill create the five
       profiles as one change, checked once, with the LWF exception named.
   - **R3. Runs calculated but not yet approved.**
     - A profile change between calculation and approval leaves the run with the old answer.
     - Finalisation checks compensation for staleness, but not the statutory context.
     - Recommendation: compare the recorded `statutory_context` with a fresh resolution at finalisation, as is done
       for compensation. This is a payroll change.
   - **R4. The check and an approval at the same moment are not serialised.** It reads before it writes, and
     approval locks only the run. The outcome is the same as R3, and R3's finalisation check would catch it.
   - **R5.** The other session's reopen and delete feature itself (previously "never deleted") needs the owner's
     acceptance.
9. **Status: OPEN.**
   - Engineering:
     - the invariant is defined, implemented, and tested on SQLite and MySQL;
     - the correction workflow C12-W is built for configuration-only corrections and tested.
   - Not reviewed. Undecided: C12-D1 (payroll treatment), R2–R5.
   - Closing C12 needs:
     - a review of the invariant, the guard and C12-W;
     - decisions on C12-D1 and R2–R5.
10. **Approval needed.** "Accept the C12 invariant and guard; R1 (a); R2 and R3 as recommended; accept the reopen and
    delete feature." Or name other options.

### C12-W: Controlled correction of a finalised month (design)

**Direction approved by the owner (2026-10-27):** design a controlled workflow for correcting a month whose payroll is
already approved or finalised. It must keep the original results, and require review and approval. The C12 guard
stays; this workflow is the only way past it, and only for exactly what a reviewer approved.

**1. What is corrected.** A change to one establishment statutory profile that the guard refuses because it would
alter what applied in a month whose payroll is past review. Changes that touch no such month need no workflow.

**2. Correction types.** Assigned automatically when the request is made, from finalised payroll. The requester never
chooses the type.

| Type | Test, per affected month and statute | Path |
|---|---|---|
| `configuration_only` | The statute goes from applying to not applying, and the month's payroll entries at the establishment carry no amount for it (`PF_EE`, `PF_ER`, `PF_ADMIN`; `ESI_EE`, `ESI_ER`; `PT`; `LWF_EE`, `LWF_ER`; `TDS`). For example, every employee there was exempt | Request → independent approval → apply. Only the configuration changes; no amount moves |
| `payroll_impact` | Anything else. Amounts were deducted or contributed under a statute now said not to apply, or a statute is now said to apply to a month whose payroll did not apply it (this cannot be known without recalculating, so it is treated as impact) | Request → independent approval. **Applying is refused** until the owner decides the payroll and filing treatment (decision C12-D1 below) |

**3. Records.** A new table, `statutory_profile_corrections`. Rows are append-only in substance: the proposal and the
original context are written once, and only the status moves.

| Field | Content |
|---|---|
| tenant, company, establishment, statute | Scope; tenant-isolated (`BelongsToTenant`) |
| `profile_id`, `action` | The profile changed (null when creating) and `update`, `create` or `delete` |
| `original` | The profile row as it was when the request was made: what the original payroll used |
| `proposed` | The new values (`applicable`, `effective_from`, `effective_to`) |
| `affected` | For each affected month and statute: period end, applies before and after, the finalised run ids, and the amount on the statute's lines. The original outcome |
| `classification`, `required_actions` | The type, and for `payroll_impact` the follow-ups the decision must settle (supplementary payroll or adjustment, refund or recovery, amended returns per statute) |
| `reason`, `evidence` | Required reason; a reference to the supporting evidence |
| `status` | `requested` → `approved` → `applied`, or `requested` → `rejected` |
| requested, reviewed and applied by and at; `review_note` | Who and when |

**4. Rules enforced in the backend** (a service, not the screen):
- **Requester** needs `compliance.registrations.manage`.
- **Reviewer** needs the new `compliance.profile_corrections.approve` and must not be the requester. Approval and
  rejection need a note.
- **Apply** needs `compliance.registrations.manage`, an `approved` request, and the type `configuration_only`. Before
  applying, the service checks that:
  - the profile is still exactly `original`;
  - recomputing `affected` gives exactly the stored list.

  Otherwise the request is stale and must be raised again.
- **The guard admits the change only when the differences it finds equal the approved request's `affected` list.**
  Anything else is still refused.
- **Audit:**
  - every request, approval, rejection and application is audited;
  - the profile update itself is audited with the reason "Correction #n: …".
- **What is never touched:** finalised payroll (runs, entries, lines, rule versions) and packs already built.

**5. Reporting the original and corrected outcomes.**
- **Original:**
  - packs built before the correction (frozen and fingerprinted);
  - the request's `original` and `affected.before`;
  - the finalised payroll itself.
- **Corrected:**
  - packs generated after it, which decide from the corrected profile;
  - each affected report item carries a disclosure in its reason and in the pack index: correction number, approval
    date, statute, month, before and after.

**Implementation status (2026-10-27, uncommitted).** Everything in points 1–5 is built, except the payroll
treatment of a `payroll_impact` correction. That is refused with a message naming C12-D1, and the request stays
approved.
- New:
  - `StatutoryProfileCorrection` (model);
  - `StatutoryProfileCorrections` (service: request, approve, reject, apply, disclosures);
  - migration `2026_11_11_100001`, run in tests only, on no other database;
  - the permission `compliance.profile_corrections.approve`.
- Changed:
  - the guard in `EstablishmentStatutoryProfile` admits only the exact approved change: action, profile, values
    and effect;
  - `ComplianceReports` adds the disclosure;
  - the data inventory lists the table.
- Tests: `StatutoryProfileCorrectionTest`, 10 tests, covering:
  - request and type;
  - impact and follow-ups;
  - refused requests;
  - an inert request awaiting approval;
  - separation of requester and reviewer;
  - rejection;
  - an applied configuration-only correction with the original kept and the corrected result disclosed;
  - an impact correction approved but not applied;
  - stale approvals;
  - audit;
  - tenant and establishment isolation, and an exact-match bypass.
- **Mutation check:** with the guard told that any correction covers any change, 11 of the 21 C12 and C12-W tests
  fail.
- The permission must be synced in each environment (`php artisan peopleos:sync-permissions`, D2). It was not run on
  the development database.
- **Two MySQL-only defects, found by the MySQL run and fixed:**
  - the migration's generated index name exceeded MySQL's 64 characters, so it now has an explicit name;
  - the stale-request check compared the stored snapshot with key order mattering, while MySQL's `json` re-sorts
    keys (as in D7), so it now compares after sorting keys.
- The four affected files on fresh MySQL: 41 / 41 (test matrix §12).

**6. Not built here:**
- a Filament screen, to be designed with the owner;
- any payroll recalculation, supplementary run, refund or amended return.

### C12-D1: Settling corrections that move payroll money (decision paper)

**Status: OPEN. No settlement policy is implemented.** Until this is decided and built, a `payroll_impact` correction
can be requested and approved, but `apply()` refuses it. Since 2026-10-28, finalised or paid payroll can no longer be
reopened either, so no path moves money for a finalised month. The owner-facing summary is
`c12-d1-decision-brief.md`.

**1. Question.** A statutory profile was wrong for a month whose payroll is already approved or finalised, and fixing
it changes money. Examples:
- EPF deducted where EPF did not apply;
- ESI not deducted where it did apply.

How is the difference settled, and how do payroll, the statutory returns and the compliance pack show it?

**2. What the code does today** (inspected 2026-10-28):

| Point | Finding | Evidence |
|---|---|---|
| Original payroll results immutable | **Yes** (2026-10-28):<ul><li>`PayrollEntry` and `PayrollEntryLine` refuse update and delete once the run is `finalized` or `paid`;</li><li>`PayrollRuns::reopen()` now refuses a finalized or paid run on the locked row and audits the attempt (`REJECTED`);</li><li>`PayrollRun` takes only finalized → paid and is never deleted;</li><li>a closed `PayrollPeriod` stays closed;</li><li>an issued `Payslip` is never deleted.</li></ul>Before this, a finalised, unpaid run could be reopened with `payroll.finalize`, and recalculation then bulk-deleted its results unaudited. A recalculation of an editable run now records what it replaced | `PayrollFinalisationIntegrityTest`, `PayrollFinalisationConcurrencyTest` (MySQL) |
| Audit of request, approval, rejection, application | **Yes**. CREATE and UPDATE events with field before/after values and the reason. The profile change carries "Correction #n: …" | `StatutoryProfileCorrectionTest` "audits every step…" |
| Audit of later disclosure | Indirect. The disclosure is part of the pack, and generating and downloading packs are audited | `ComplianceReports` |
| Separation of duties | **Server-side**: the reviewer needs `compliance.profile_corrections.approve` and must not be the requester; the applier needs `compliance.registrations.manage`. Enforced in the service, not the screen (there is no screen yet) | `StatutoryProfileCorrections::review()`, `apply()` |
| Historical applicability tied to finalised payroll | **Yes** for months calculated from establishment profiles: no profile create, update or delete may change them, unless an approved correction covers exactly that change. **Not** for months calculated from the company settings (R2). The reopen gap is closed: a finalised run cannot be reopened. An approved, unfinalised run can still return to draft; it then needs a new independent approval | `EstablishmentStatutoryProfile::assertPayrollHistoryKept()` |
| Monetary corrections blocked | **Yes**. The type is computed from finalised payroll lines when the request is made and can never be edited. `apply()` refuses `payroll_impact` and names C12-D1 | Tests "approves but never applies…" |
| Reopening a profile, deleting the last profile | Refused when they change a protected month (tests). Through C12-W, a reopen is an `update` and may be requested. Deleting a profile that locked payroll used is refused even with a correction | `StatutoryProfileHistoryTest`, `StatutoryProfileCorrections::requestDeletion()` |

**What is and is not represented:**

| Item | State |
|---|---|
| Supplementary or reversal run | **Not represented.** No such run type exists |
| Revised statutory returns | **Partly.** EPF supports a `revised` ECR under conditions (payment not initiated, an approved regular return). Not linked to corrections. ESI, PT, LWF and TDS revisions are not checked here |
| Recovery or refund | **Partly.** `PayrollAdjustment` has `arrear`, `recovery` and `correction` types for a later period. Not linked to corrections |
| Interest, damages, penalties | **Not represented**, and deliberately deferred (legal) |
| Reopening finalised, unpaid payroll | **Refused since 2026-10-28**, for every user and path; see the first table |

**3. Options.**

| Option | What happens | Consequences |
|---|---|---|
| **A. Keep blocked** (today) | `payroll_impact` corrections stay approved but unapplied. Settlement happens outside PeopleOS | Payroll, profiles and packs stay consistent. The settlement has no record in PeopleOS, and the pack for that month keeps the original applicability |
| **B. Settle forward** | Apply the profile correction (the pack discloses it), and post a `recovery` or `arrear` adjustment in the next open month, linked to the correction. Amended returns are filed as each statute allows (EPF revised ECR where permitted) | The original run stays untouched. The pack for the past month shows the corrected applicability next to the original figures, so the disclosure is essential. Needs a link from correction to adjustment, and per-statute rules for refunds |
| **C. Supplementary or reversal run** | A new run for the past period that reverses and re-posts the statutory lines; returns regenerated as revisions | Cleanest audit trail; the original run is kept. A large engine change (new run type, period rules, payslips, returns) |
| **D. Reopen and recalculate** (unpaid runs only; removed 2026-10-28) | Reopen the run, change the profile, recalculate, finalise | Simple, but the original results are **not preserved** (bulk delete, no line audit), and it bypasses C12-W approval. Acceptable only with a snapshot of the original results on reopen, and a block once packs, returns or payslips have been issued |

**4. Recommendation** (not a decision):
- Choose **B** for the general case.
- Keep **A** for any statute and direction counsel does not clear for B.
- **D's guard is now the default:** finalised runs are not reopened at all. Payslips are issued at finalisation, so
  "refuse once payslips are issued" already means never reopening a finalised run.
- Do **not** choose C unless supplementary runs are wanted for other reasons.

**5. Approvals required.**
- **Owner:** the option.
- **Payroll lead:** the adjustment treatment.
- **A qualified Indian labour-law and payroll professional, per statute and direction:**
  - EPF, ESI, PT, LWF: refunds, recoveries and revised returns, including whether a refund is allowed and by whom;
  - TDS: correction statements;
  - interest, damages and penalties.

**6. After a decision** (engineering, not started):
- B: link the correction to the adjustment, a per-statute settlement rule table, disclosure of the settlement in the
  pack, and tests.
- D (if ever chosen): a reopen request with independent approval, a snapshot of entries, lines and payslips before
  any change, return-revision triggers, and lifting today's guards for that path only.

## 3. Catalogue maintenance

### C8: Catalogue maintenance and reproducibility

> **Status (2026-10-27, final pass).** These are kept apart: the owner's direction, what was built, what was verified,
> and what is still open.
> - **Owner's approved direction:** option B. Maintain the catalogue generator in the repository, and add a CI
>   reproducibility check.
> - **Built** (uncommitted): `tools/compliance-catalogue/`, holding the generator, its specs, the three research
>   notes, the two doc generators, the hand-maintained sections in `manual/` and a README. Also added: `check.sh`,
>   `regenerate.sh`, and a separate CI job "Compliance catalogue reproducibility" in `.github/workflows/ci.yml`. The
>   existing CI job is unchanged.
> - **What changed from the archive:**
>   - `states.json` is derived from the catalogue at run time, not stored, so there is no second copy;
>   - the two patch files became plain Markdown in `manual/`;
>   - a missing research note now stops the generator instead of silently dropping sources;
>   - the `.bak` copies, the first `gen.py`, the one-off `patch_states.py` and caches stayed in the archive.
> - **Verified:**
>   - `check.sh` reports all three files identical (exit 0);
>   - it fails with a diff on a one-byte catalogue edit or a hand edit to a generated table (exit 1), and on a missing
>     research note (exit 2);
>   - it never wrote to the working tree (checksums before and after).
> - **One visible change:** a notice was added to the two documents' hand-maintained heads, saying they are
>   generated. It was applied through `regenerate.sh`; diffed against the archive's output, nothing else changed.
> - **Still open:**
>   - the CI job runs only once the owner commits and pushes;
>   - the catalogue's legal content still awaits D6;
>   - CI pins Python 3.14 to match the verified output.

1. **Decision.** How `database/data/compliance/reports/in.php` is maintained:
   - **Option A:** maintain the generated catalogue directly;
   - **Option B:** keep its generator in the repository, and enforce reproducible output.
2. **Affected.**
   - **The catalogue.** Revision `2026-10-27b`: 188 entries, 36 states and union territories, SHA-256
     `fc04ade1…72b3`. It has never been committed: it was staged outside this work at 10:24 on 2026-10-27, with
     unstaged changes on top.
   - **The generator.** It is archived on disk, outside the repository, at
     `/home/administrator/Documents/hrms/hcms-compliance-research-archive-20261027/generator/` (see its
     `README.md`).
     - `gen/gen2.py` (145 lines) and six spec modules: `central_spec`, `ptlwf_spec`, `se_spec`, `se2_spec`,
       `thresholds_spec`, `sources_spec`. With them, 873 lines of standard-library Python 3 (run with 3.14.4).
     - Inputs: the two research notes `se-top15.md` (54 KB) and `se-rest.md` (63 KB).
     - Not part of the build: `patch_states.py`, a one-off spec editor; `gen.py`, the first version;
       `states.php.txt`; `*.bak` copies; `__pycache__`.
   - **The doc generators.**
     - `docgen/matrix.py` writes `state-wise-compliance-matrix.md`.
     - `docgen/sources.py` plus `sources-head.md` write `india-compliance-source-register.md`.
     - Their inputs: `states.json` and `verify-state-code-rules.md`.
     - Both documents also carry sections written by hand after the second verification pass, kept as
       `docgen/matrix-second-pass.patch` and `docgen/sources-second-pass.patch`.
3. **Current implementation.**
   - **Re-verified at 16:31 on 2026-10-27,** run from the archive into an empty folder, all three outputs were
     byte-for-byte identical to the repository:
     - `gen2.py` gave the catalogue (`fc04ade1…72b3`);
     - `matrix.py` plus its patch gave the state matrix;
     - `sources.py`, the head and its patch gave the source register.
   - Earlier, revision `2026-10-27a` was reproduced the same way (`ac93990e…7308`).
   - **The catalogue holds no rates, ceilings or rule status,** so no catalogue change can activate a statutory rule
     (`CatalogueIntegrityTest` asserts it). Rules live in `database/data/compliance/in.php` and only
     `RuleVerifications::verify()` activates one.
4. **Evidence.**
   - The reproduction above.
   - `CatalogueIntegrityTest` validates the data however it is maintained:
     - a sourced label cites a source, and a primary label an official HTTPS host;
     - dates run forwards and thresholds do not overlap;
     - builders and states resolve;
     - no rule data is held.
   - `CatalogueVariantsTest` refuses a primary label without an official host.
5. **Options compared.**

   | Consideration | A: maintain the catalogue directly | B: generator in the repository, output enforced |
   |---|---|---|
   | Legal-source traceability | Sources stay as URLs and labels inside each PHP entry; the research notes behind S&E labels leave the trail | Specs keep each fact beside its source and label, and the research notes are versioned with them |
   | Reviewability of changes | Diffs are in long generated PHP lines; one fact can change several entries | Spec diffs are short and per fact; the regenerated PHP diff shows the effect |
   | Reproducibility | None after the first hand edit; the archive drifts out of date | Enforced: a check regenerates and compares byte-for-byte |
   | Maintenance burden | Low tooling cost; PHP editing is error-prone at this size (188 entries × 36 states) | Python 3 (standard library) in a PHP repository; a CI step; specs must be kept tidy |
   | Dependencies | None new | Python 3 on developer machines and CI; no packages |
   | Accidental changes to statutory rules | Not possible: the catalogue holds no rules (tested) | The same, plus the check catches hand edits to the generated file |
   | Manually maintained sections | The research documents become hand-maintained too, and the patches are dropped | The two patch files must be folded into the doc generators first. Patches applied on top of changing output break silently or fail to apply |

6. **Recommendation.** **Option B**, with three conditions:
   - commit only the build: `gen2.py`, the six spec modules, the two research notes, the doc generators and their
     inputs, under `tools/compliance-catalogue/` with the README;
   - fold the two patch files into the doc generators before committing;
   - make the check fail closed. A Pest test that skips when Python is missing would not count as a pass, so the
     check should be a CI job that runs
     `python3 -I tools/compliance-catalogue/gen2.py <tmp>` and compares the result with `cmp`.
   - If Python in CI is not acceptable, choose A: rely on the validation tests, and archive the generator as a
     historical record.
7. **Implications.**
   - **B:** about 1,000 lines of tooling, plus 117 KB of research notes, enter the repository. Every catalogue change
     goes through the specs.
   - **A:** the next hand edit ends reproducibility. The generator and patches become a dated archive.
   - **Either:** rule activation is unaffected.
8. **Blocked until decided.** Versioned, reviewable regeneration of the catalogue and the two research documents.
9. **Approval needed.** "Option B: commit the generator to `tools/compliance-catalogue/` with the conditions above",
   or "Option A: maintain the catalogue directly".
10. **After approval.**
    - B, once the working tree is stable and committed by the owner:
      - copy the build files;
      - fold the patches in;
      - add the CI job;
      - prove it fails on a one-byte edit to the catalogue;
      - update `compliance-reports.md`.
    - A: mark the archive as historical in these documents and remove the regeneration instructions.

## 4. Legal questions for a qualified labour-law professional

| # | Question | Effect today |
|---|---|---|
| B1 | Do the central bonus notifications (S.O. 4710 and 4711(E)) bind establishments where the State is the appropriate government? No State notification under Code on Wages s.26 was found (re-checked 2026-10-27) | The bonus register says so and marks results legally unverified |
| B2 | Which minimum wage sets the bonus calculation ceiling ("₹7,000 or the minimum wage fixed by the Central Government, whichever is higher"): which category, which area, and the daily-to-monthly conversion? The 2026 central rates are now official and far above ₹7,000 a month, so this decides most amounts | Computed at ₹7,000 and marked pending determination |
| B3 | Which law governs bonus for FY 2025-26, which straddles the Payment of Bonus Act (to 20 Nov 2025) and the Code (from 21 Nov 2025)? | Each month is measured under the version then in force, and the sheet asks for review |
| B4 | For each state, whether its final Code rules are notified, and so which register forms apply to state-sphere establishments | State registers use a PeopleOS layout with the central particulars, under legal review (state matrix) |
| B5 | Does the Employment Information Return (Form XXVI) reach private establishments now? r.56(6) is unconditional; r.56(1)(b) and (5)(b) depend on notification | Pending verification |
| B6 | Is the ESIC half-yearly return still a filing? ESIC's guide says it is generated online from the monthly contributions. Also: do the ESI regulations continue under the Code (s.164(2)(b) is not in force)? | Kept as an internal check, not a filing |
| B7 | Which Kerala welfare fund applies: the LWF Act, 1975, or the Shops and Commercial Establishments Workers Welfare Fund? | Kerala LWF shown as legal review required |
| B8 | Haryana LWF: does the ten-employee threshold apply only to shops? | A note |
| B9 | The SS Code multi-state limb (s.2(3)(a)): does a company with branches in several states make every establishment Central for social security? | The customer sets `ss_appropriate_government`; PeopleOS does not infer it |
| B10 | **Gratuity: does four years and 240 days count as five years?** High Courts (Madras, Kerala, Delhi) have held so; there is no Supreme Court ruling squarely on it | The calculator applies a strict five years and makes such a case "pending determination" with the reason |
| B11 | **Gratuity under the Code: does the s.2(88) 50% add-back apply to gratuity wages, and which allowances count?** The text is primary; its application to gratuity rests on MoLE FAQs | Shown as a review with the alternative amount, never applied (with C6) |
| B12 | Do the state national and festival holidays Acts (KA 1963, TN 1958, KL 1958, AP 1974) survive the OSH Code? Which forms do they prescribe? | The holiday list is unverified |
| B13 | Is the 1987 notification of the late-payment interest rate for gratuity still in force under the Code (s.56(4) drops the Act's reference to a notified rate)? | Not computed; the settlement shows "with interest if late" |

## 5. Product decisions

| # | Decision | Current behaviour |
|---|---|---|
| C1 | Make **state, establishment type and both appropriate governments** mandatory when an establishment is created or edited | Optional. Missing values are configuration gaps; nothing is assumed |
| C2 | Keep showing **provisional results** under proposed rule versions (bonus, gratuity, and now gratuity at exit), or show blanks until verified | Shown, labelled legally unverified, with the version named; never paid automatically |
| C3 | Hold **minimum wages** (central and state, by category and area) | Not held. The 2026 central rates are now confirmed (evidence); needed for B2 |
| C4 | Model state LWF supervisory wage ceilings, managerial exclusions, employer minimums (MP) and monthly-deduction / half-yearly-remittance (PB, CH), then add versions for AP, WB, MP, OD, PB, CH, KL, CG, GA | Catalogue entries without payroll lines; statements say so |
| C5 | Model the Kerala PT income exclusions and the 60-day half-year test | Not modelled (A4) |
| C6 | Model the Code's **50% wage rule** in bonus and gratuity wages | Basic + DA only; gratuity shows the difference as a review (B11) |
| C7 | Who holds **`compliance.reports.review`** | Tenant HR Admin through `compliance.*`; Payroll Admin does not |
| C8 | Catalogue maintenance | See the decision paper in §3 |
| C9 | Scheduled pack generation and reminders before due dates | Not built: packs are generated on request |
| C10 | **Gratuity coverage counts the employees PeopleOS holds.** Should a customer be able to declare an establishment covered (contract labour, history before PeopleOS) or exempt (s.5 exemption, a better scheme)? | No override: coverage is computed, with the caveat in the reason |
| C11 | **Karnataka LWF coverage** (ten or more persons from 7 Jan 2026) and other LWF thresholds: test them in payroll? | Not tested; noted on the statement |
| C12 | **Changing an establishment statutory profile after payroll** (reopen and delete were added by another session on 2026-10-27, uncommitted) | Decision paper in §2. Both cases were reproduced in tests, and a guard was implemented and tested. Still **open**: review, plus residuals R1–R5 |

## 6. Release and operations

| # | Action | Status |
|---|---|---|
| D1 | Authorise a **commit**. Nothing was committed. Note: the working tree was staged by something outside this session at 10:24 on 2026-10-27; this work did not change the index, and its later edits are unstaged | Uncommitted |
| D2 | Run `php artisan migrate` (`2026_11_09_100001` to `_100004`) and `php artisan peopleos:sync-permissions` in each environment; then run `php artisan peopleos:compliance:layout-checksums` | `100001`–`100003` applied on the local development database. `100004` is not applied there: the code reads layouts without the column, as original-algorithm rows, so nothing breaks. Apply it before any new layout is created. The read-only report on that database now shows 5 `intact_against_pack`, exit 0 (D7, decision B; it showed `original_algorithm_unreproducible` before decision B) |
| D3 | Run the MySQL suite in CI against a disposable database (`PEOPLEOS_MYSQL_CONCURRENCY_DB`), and the compliance feature folder with `DB_CONNECTION=mysql` | Run locally; see the test matrix |
| D4 | Accept the **Livewire 4.4.6 → 4.4.8** update (GHSA-mhf7-xp6r-6qrc, affected ≥ 4.3.0 < 4.4.8) | In `composer.lock`; `composer audit` clean |
| D5 | Tell customers that compliance reports are working papers, not filings, and that many state facts rest on partly verified or secondary sources | Stated in the product |
| D6 | A labour-law professional's review of the catalogue (central forms and the 36-state matrix) and of B1–B13 | Not done. Required before claiming legal accuracy |
| D7 | Historical export-layout checksums on MySQL | Decision paper in §2 |
| D8 | Clean-up of disposable databases used by this work | Dropped at the end of each pass, the stabilisation snapshot's two included. Confirmed absent; the tool's ledger was empty at 15:25 on 2026-10-27 |
| D9 | **Retention of compliance packs.** Pack ZIPs (salaries, masked identifiers, working papers) are never purged; the retention purge does not cover them | Kept indefinitely. Choose a period (statutory registers are kept five years after the last entry under the Wages Rules r.51(4), but a pack is a copy, not the register) and whether the purge should delete pack files |
