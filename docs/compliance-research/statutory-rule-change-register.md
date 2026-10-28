# Statutory rule-change register

Prepared 2026-10-27 and revised the same day after the second verification pass (`evidence/2026-10-27b-verify-*.md`).
It lists every change to the statutory rule pack (`database/data/compliance/in.php`) this work proposes, and every
stored version found to be wrong or doubtful.

**Nothing here is in force.**
- Every new version is stored with `status = proposed`. `ComplianceRules::resolve()` ignores proposed versions, so
  payroll is unchanged.
- No stored version was edited: versions are immutable and checksummed, and `sync()` refuses to rewrite one.
- A proposed version comes into force only through `RuleVerifications::verify()`. That needs a second platform
  administrator, stored official evidence, complete parameter coverage and no open regulatory notice.
- Compliance reports, and the gratuity calculator at exit, may show a proposed version's result. When they do, it is
  labelled provisional and legally unverified, and it is never paid automatically.

Confidence labels are defined in `india-compliance-source-register.md`. Owner decisions are in
`compliance-owner-decisions.md` (A1–A11).

## 1. Versions stored as proposed (2026-10-26)

| # | Rule | Version and window | Parameters | Confidence (after the second pass) | Blocks activation | Effect if activated |
|---|---|---|---|---|---|---|
| P1 | BONUS (Payment of Bonus Act, 1965) | v1, 2014-04-01 → 2025-11-20 | eligibility ₹21,000; calculation ₹7,000 or the scheduled-employment minimum wage if higher; 8⅓% / ₹100 minimum; 20% maximum; 30 days; paid within 8 months; 20 persons | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | s.2(13) ₹21,000 (Act 6 of 2016) not read in the gazette | Bonus register results become decisive (payroll does not compute bonus) |
| P2 | BONUS (Code on Wages, central sphere) | v2, 2025-11-21 → | as P1, minimum wage "fixed by the Central Government" (S.O. 4710(E), primary) | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | S.O. 4711(E) (₹21,000) still a mirror copy only; `verify()` refuses while `eligibility_ceiling` is NOT CONFIRMED (tested). The 2026 central minimum wages are now official, which makes the calculation ceiling (B2) material | As P1. **Legal review:** state-sphere establishments (B1) |
| P3 | GRATUITY (Code on Social Security) | v2, 2025-11-21 → | 15/26 per year; 5 years; fixed-term minimum not stated (null); ceiling ₹20 lakh | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | Ceiling: no notification under s.53(3); the 2018 S.O. 1420(E) is confirmed by an official DPE memorandum but its gazette was not read on a government host | **Now read by full and final settlements as well as the register.** On verification, eligible exits in covered establishments get an automatic gratuity line |
| P4 | GRATUITY (Code + SS (Central) Rules r.33) | v3, 2026-05-08 → | as v2, fixed-term minimum 1 year | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | Same ceiling gap | As P3; fixed-term employees with one year qualify |
| P5 | LWF Karnataka | v2, 2025-01-10 → | ₹50 / ₹100 (Government ₹50), December | OFFICIAL_PRIMARY_SOURCE_VERIFIED (Act 05 of 2025; law department consolidation with dated footnotes) | Second-admin verification only | December deduction ₹20 → ₹50 (employer ₹40 → ₹100) |
| P6 | LWF Haryana | v2, 2020-01-01 → 2022-12-31 | 0.2% of wages, cap ₹25, employer 2× | OFFICIAL_PRIMARY_SOURCE_VERIFIED | Supersedes v1 (same start) on verification; the legal start is April 2019, which is harmless | Historical only |
| P7 | LWF Haryana | v3, 2023-01-01 → 2024-12-31 | cap ₹31 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (board letter; confirmed by the official board table) | — | Historical |
| P8 | LWF Haryana | v4, 2025-01-01 → 2025-12-31 | cap ₹34 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | — | Historical |
| P9 | LWF Haryana | v5, 2026-01-01 → | cap ₹35 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (₹35 on the board's official table; HLWB/REV/2026/3436 on no government host, e-Gazette search behind a CAPTCHA) | Gazette copy of HLWB/REV/2026/3436 | Monthly 0.2% of wages up to ₹35 (employer up to ₹70) instead of v1's flat ₹31 / ₹62 |
| P10 | PT West Bengal | v2, 2026-10-01 → | nil to ₹20,000; ₹100; ₹140; ₹170; ₹208 above ₹1,00,000 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED. **New:** the commencement notification 1406-F.T. (18-08-2026) is official: s.3(2) as amended applies from 1 Oct 2026 and the Schedule from its own dates | The official text of the final Schedule 1607-F.T. only | From October 2026 salaries: nil up to ₹20,000 (was ₹10,000); ₹208 top rate (was ₹200) |
| P11 | PT Kerala | v2, 2024-10-01 → | G.O.(Rt) 1149/2024/LSGD half-yearly slabs stored as monthly equivalents | **LEGAL_REVIEW_REQUIRED** (was primary for the G.O.) | The rates are maximums each local body fixes (Panchayat Raj Act s.204(2)); no rules amendment or council adoption found. The version's structure (monthly, on gross, uniform) does not match the law | Do not activate as it is (A4) |

Evidence was submitted for P1–P5, P9, P10 and P11: 8 versions are in review. P6–P8 carry their sources in the pack but
no separate submission. Tests: `RuleVerificationTest`, `StatutoryEvidenceMaintenanceTest`,
`ComplianceReportCalculationTest`, and `GratuityEntitlementTest` (gratuity at exit).

## 2. Odisha professional tax

| Item | Position |
|---|---|
| Stored | No Odisha PT version exists in the pack |
| Law | Odisha Ordinance 02 of 2026 (Gazette Ext. No. 1496, 21-04-2026) repeals the Odisha PT Act, 2000 **from 1 April 2026**, with a savings clause for earlier liabilities. OFFICIAL_PRIMARY_SOURCE_VERIFIED (finance.odisha.gov.in) |
| Open | The official listing read on 27-10-2026 still shows the Ordinance as the latest item. **No Repeal Act, assent or Act number was found.** Without an Act the Ordinance lapses about 3 November 2026 (Art. 213(2)(a); calculated from a secondary reassembly date of 22-09-2026). LEGAL_REVIEW_REQUIRED |
| Product | No version was added: nothing is deductible from April 2026 while the Ordinance or an Act is in force. The catalogue records the obligation up to 31 Mar 2026 and the repeal |
| Owner action | A8: confirm the Repeal Act before about 3 November 2026; if the Ordinance lapses, PT revives and a version is needed |

## 3. Stored versions found wrong or doubtful (not changed)

The stored versions remain active and unchanged. Correcting them needs a new version, and for Tamil Nadu and Kerala a
half-yearly local-body model (A10, A4). The second pass confirmed every finding it re-examined.

| # | Stored version | Finding (second pass in bold where new) | Effect | Confidence |
|---|---|---|---|---|
| W1 | LWF MH v1, from 2015-01-01, ₹25 / ₹75 | ₹25 / ₹75 apply **from 18 Mar 2024** (Mah. Act XXV of 2024), first in the June 2024 deduction. Before: ₹6 / ₹18 (wages up to ₹3,000) or ₹12 / ₹36, **deemed from 31 Dec 2000 (Mah. 24 of 2003)**. The stored version also has no wage-slab parameter | Any recalculation before June 2024 overstates | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (**the 2024 Act was read only in reprints; the label was lowered from primary**) |
| W2 | LWF TN v1, from 2015-01-01, ₹20 / ₹40, December | **₹10 / ₹20 from 23 Jan 2015 (SRO A-1(a)/2015) and ₹20 / ₹40 from 2 Dec 2022 (SRO A-26(b)/2022), both in official gazettes.** The deduction month still conflicts (r.12: June wages, paid by 31 July; Form A and the board: year ending December, paid by 31 January) | 2015–2022 overstated; the month may be wrong | Amounts **OFFICIAL_PRIMARY_SOURCE_VERIFIED**; month CONFLICTING_SOURCES |
| W3 | LWF GJ v1, from 2015-01-01, ₹6 / ₹12 | **₹6 / ₹12 from 15 Nov 2016 (Gujarat Gazette Extra. No. 634; notification of 10-11-2016), first the December 2016 half-year. ₹3 / ₹6 before** | June 2015, December 2015 and June 2016 overstated | **OFFICIAL_PRIMARY_SOURCE_VERIFIED** |
| W4 | LWF HR v1, from 2020-01-01, flat ₹31 / ₹62 every month | The law is 0.2% of wages with a cap (₹25 from April 2019, ₹31 from 2023, ₹34 from 2025, ₹35 from 2026), employer 2×, paid yearly before 31 December | Overstated for low earners and 2020–2022; understated for 2025–2026 at high wages | See P6–P9 |
| W5 | LWF KA v1, from 2018-01-01, ₹20 / ₹40 | Superseded by ₹50 / ₹100 from 10 Jan 2025. **The ₹20 / ₹40 start is 6 Apr 2017 (Act 21 of 2017), not 2018** | Understated from calendar 2025; 2017 not covered | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| W6 | PT WB v1, from 2013-04-01 | Slabs match the schedule effective 1 Apr 2014. The start date is a year early. **v1 must also stop at 30 Sep 2026 (1406-F.T.)**; P10 starts 1 Oct 2026 and supersedes it by date on verification | April 2013–March 2014 only (before PeopleOS); from October 2026, v1's slabs are wrong | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| W7 | PT KL v1, from 2016-04-01 | Superseded from 1 Oct 2024; the structure is wrong too (monthly instead of half-yearly; on gross instead of excluding HRA, CCA, conveyance and TA; uniform instead of per local body). Its 2016 start is NOT CONFIRMED | Understated from October 2024 | LEGAL_REVIEW_REQUIRED |
| W8 | PT KA v1, from 2023-04-01, February ₹300 | Nil below ₹25,000 is right from 1 Apr 2023 (Act 14 of 2023). The February ₹300 applies only from 1 Apr 2025 (Act 33 of 2025, **gazette signature valid, published 15 Apr 2025**). Minor: the bound ₹24,999 against "₹25,000 and above" | February 2024 and 2025 overstated by ₹100 | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| W9 | PT TN v1, from 2018-04-01, monthly | **v1 is the TN Urban Local Bodies Rules, 2023 r.275(2) default table ÷ 6** (the legal default where a council has not revised its rates). The law: band on the **previous financial year's average half-yearly income**; deducted from the **August and January** pay bills; paid by **30 September and 31 March** with Form 14 (rr.274, 277). Greater Chennai Corporation since 1 Oct 2024: ₹180 / ₹425 / ₹930 for bands 2–4 | Wrong structure everywhere; wrong amounts in Chennai since October 2024 | Rules **OFFICIAL_PRIMARY_SOURCE_VERIFIED**; Chennai rates OFFICIAL_SOURCE_PARTIALLY_VERIFIED |
| W10 | PT MP v1, from 2018-04-01 | Third band is ₹166 a month with ₹174 in the 12th month, not ₹167; `march_amount` ₹212 is right for the top band only (the engine now applies it only there). Bands are on annual salary | ₹1 a month too high for the third band; its 12th month ₹7 too low | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| W11 | PT AP v1, from 2013-02-01 | Slabs match. **The operative instrument is G.O.Ms.No. 82 (04-02-2013), published 06-02-2013**: the start should be 2013-02-06 | None in practice | **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (was secondary) |

Cleared by the second pass: PT MH, TG and GJ v1 match (GJ now rests on the department's rate schedule, partial); LWF
TG and DL v1 match.

### Corrections proposed for the owner (not added to the pack)

These are the versions that would correct W1–W11 once approved. They are written out so that the owner can approve
exact parameters; none was inserted, because each needs a second-admin verification, and TN / KL need the
half-yearly model first.

| For | Proposed version | Parameters |
|---|---|---|
| W8 | PT KA, correction of v1, 2023-04-01 → 2025-03-31 | slabs `[[24999.99, 0], [null, 200]]`, no `february_amount` |
| W8 | PT KA, 2025-04-01 → | slabs as above, `february_amount` 300 |
| W10 | PT MP, correction of v1, 2018-04-01 → | third band ₹166 a month, ₹174 in the 12th month; top band ₹208, ₹212 in the 12th month |
| W6 / P10 | PT WB | verify P10 (it supersedes v1 from 1 Oct 2026); a correction of v1's start to 2014-04-01 only if 2013 matters |
| W11 | PT AP, correction of v1, 2013-02-06 → | slabs unchanged |
| W1 | LWF MH, 2000-12-31 → 2024-03-17 and 2024-03-18 → | ₹6 / ₹18 up to ₹3,000 of wages, ₹12 / ₹36 above; then ₹25 / ₹75. Needs a wage-slab parameter in the engine |
| W2 | LWF TN, 2015-01-23 → 2022-12-01 and 2022-12-02 → | ₹10 / ₹20, then ₹20 / ₹40; month per A9 |
| W3 | LWF GJ, → 2016-11-14 and 2016-11-15 → | ₹3 / ₹6, then ₹6 / ₹12 |
| W5 | LWF KA, correction of v1, 2017-04-06 → 2025-01-09 | ₹20 / ₹40 |
| W9 | PT TN | after the half-yearly local-body model (A10) |

### Engine correction made (payroll, first pass)

**Fixed in `StatutoryEngine`:** a PT special-month amount used to replace any non-zero band. It now replaces only
the top, open-ended band:
- Maharashtra's ₹175 band (men ₹7,501–₹10,000) stays ₹175 in February, as the law sets it (Mah. Act XIX of 2023,
  OFFICIAL_PRIMARY_SOURCE_VERIFIED). Before the fix, payroll charged ₹300.
- Madhya Pradesh's `march_amount` of ₹212 now applies only to the ₹208 band.
- Karnataka is unchanged: its February ₹300 is on its only taxed band.

Test: `StatutoryTest`. This changes future payroll for Maharashtra men in the ₹175 band in February. Finalized runs
are not recalculated.

## 4. Recommended regulatory notices (owner to create; not inserted)

Notices are created in `database/data/compliance/notices/in.php` by the owner (A7). An open notice blocks
verification of the version it names until a new version resolves it.

| Notice | Against | Text to use |
|---|---|---|
| N1 | LWF MH v1 | "₹25 / ₹75 apply from 18 Mar 2024 (Mah. Act XXV of 2024), first the June 2024 deduction; earlier half-years were ₹6 / ₹18 (wages up to ₹3,000) or ₹12 / ₹36 (Mah. 24 of 2003)." |
| N2 | LWF TN v1 | "₹10 / ₹20 from 23 Jan 2015; ₹20 / ₹40 from 2 Dec 2022 (official gazettes). Confirm the deduction month (June per r.12, December per Form A)." |
| N3 | LWF HR v1 | "Rate-and-cap formula, not a flat amount: see proposed v2–v5." |
| N4 | LWF KA v1 | "₹20 / ₹40 apply from 6 Apr 2017 (not 2018); ₹50 / ₹100 from 10 Jan 2025: see proposed v2." |
| N5 | PT WB v1 | "v1 ends 30 Sep 2026 (1406-F.T.); new slabs from 1 Oct 2026: see proposed v2, pending the official text of 1607-F.T." |
| N6 | PT KL v1 | "Half-yearly local-body maximums raised from 1 Oct 2024; the structure (half-yearly, exclusions, per local body) is not modelled." |
| N7 | PT KA v1 | "February ₹300 applies from 1 Apr 2025 only (Act 33 of 2025); February 2024 and 2025 were ₹200." |
| N8 | PT TN v1 | "Half-yearly under the TN Urban Local Bodies Rules, 2023 rr.274–278: band on the previous year's average half-yearly income, deducted in August and January; Greater Chennai Corporation revised its table from 1 Oct 2024." |
| N9 | PT MP v1 | "Third band ₹166 (₹174 in the 12th month), not ₹167." |
| N10 | PT AP v1 | "The schedule applies from 6 Feb 2013 (G.O.Ms.No. 82, published 06-02-2013), not 1 Feb 2013." |
| N11 | LWF GJ v1 | "₹6 / ₹12 apply from 15 Nov 2016 (Gujarat Gazette Extra. No. 634); ₹3 / ₹6 before." |
| N12 | PT WB v1 | "The schedule applies from 1 Apr 2014, not 1 Apr 2013." |

## 5. Rules left inactive, and why

| Rule | Why inactive |
|---|---|
| BONUS v1, v2 | Eligibility ceiling not read on an official host; state-sphere application of central S.O.s and the minimum-wage ceiling need legal review; minimum wages not held |
| GRATUITY v2, v3 | Ceiling under s.53(3) not notified (or not found). While inactive, gratuity at exit from 21 Nov 2025 is provisional and never paid automatically |
| LWF KA v2 | Primary-verified, but activation is an owner decision through second-admin verification. Not activated automatically |
| LWF HR v2–v5 | v2 primary-verified; v3–v4 on board letters; v5 on a vendor scan |
| PT WB v2 | Final schedule not read officially (commencement now found) |
| PT KL v2 | Rates are maximums subject to local-body resolutions; the version's structure does not match the law |

**GRATUITY v1** (Payment of Gratuity Act) stays active with no end date although the Act was repealed on
21 Nov 2025. Rule versions are immutable, so its end cannot be added; the gratuity calculator and the register prefer
the proposed Code version for dates from 21 Nov 2025 and label the result provisional.

## 6. Not researched or not stored

- PT for the other levying states (AS, BR, JH, PY, PB, TR, SK, NL, MZ, MN, ML) is researched
  (`state-wise-compliance-matrix.md`) but not in the rule pack, so payroll computes no PT there (A11).
- LWF for AP, WB, MP, OD, PB, CH, KL, CG and GA: researched, not in the rule pack. Adding them needs model changes:
  per-state managerial and supervisory wage ceilings; an employer minimum (MP ₹2,500 a half-year below 50
  employees); monthly deduction with half-yearly remittance (PB, CH); wage slabs (MH before 2024).
- Minimum wages (central or state): not held (C3). The 2026 central rates are now confirmed in the evidence.
