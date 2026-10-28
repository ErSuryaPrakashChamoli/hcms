# Verification of five Indian statutory rule changes against official sources

Checked on 2026-10-26. This verifies leads from `docs/compliance-research/2026-10-26-state-obligations.md`.
Official documents were downloaded and read directly. Scanned PDFs were rendered and read visually.
Secondary sources (law firms, payroll vendors) were used only to locate documents; where a fact rests on them, the entry says so.
The session's web-search budget ran out during the West Bengal step, so later lookups were limited to direct fetches of known URLs.

| # | Item | Verdict | Confidence |
|---|---|---|---|
| 1 | Karnataka LWF ₹50 / ₹100 / ₹50 | **CONFIRMED** | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| 2 | Haryana LWF 0.2%, cap ₹35 from Jan-2026, employer 2× | **PARTIALLY CONFIRMED**: rate, cap, multiplier and effective date confirmed; the "monthly, due last day of month" payment is **REFUTED** (statute: pay annually before 31 December) | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| 3 | West Bengal PT new slabs from 1-Oct-2026 | **PARTIALLY CONFIRMED**: slabs and 1-Oct-2026 confirmed from the official *draft* (1407-F.T.); final 1607-F.T. text not retrieved | OFFICIAL_SOURCE_PARTIALLY_VERIFIED |
| 4 | Kerala PT half-yearly slabs from 1-Oct-2024 | **CONFIRMED** (correction: it is G.O.**(Rt)**, not G.O.(P)) | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| 5 | Odisha PT repeal from 1-Apr-2026 | **CONFIRMED** for the Ordinance and its savings; the Repeal **Act** is reported passed (news only), but assent and Act number were NOT FOUND | OFFICIAL_SOURCE_PARTIALLY_VERIFIED |

---

## 1. Karnataka Labour Welfare Fund

**Verdict: CONFIRMED.** Confidence: **OFFICIAL_PRIMARY_SOURCE_VERIFIED**.

### Instrument
- **Karnataka Act No. 05 of 2025, the Karnataka Labour Welfare Fund (Amendment) Act, 2024.**
  - Assent of the Governor: 08-01-2025.
  - First published in the Karnataka Gazette Extra-ordinary (Part IV-A, No. 19) on Friday 10-01-2025.
  - Notification **No. DPAL 60 SHASANA 2024, Bengaluru, dated 10.01.2025** (Department of Parliamentary Affairs and Legislation).
- It amends **s.7A(2)** of the Karnataka Labour Welfare Fund Act, 1965 (Karnataka Act 15 of 1965).
- **Effective date:** "It shall come into force at once", which means on publication, 10-01-2025.
- The board's press note dated 05-12-2025 applies the new amounts to **calendar year 2025**: deducted from December 2025 wages and paid by 15-01-2026.

### Official URLs
- KLWB press note dated 05-12-2025, with the scanned gazette copy of Act 05 of 2025 (4 pages, English and Kannada): https://klwb.karnataka.gov.in/uploads/Contribution%202025%20Press%20Note%20&%20Gazette%20Copy_1765191206.pdf
- The page linking it: https://klwb.karnataka.gov.in/48/payment-of-contribution-for-the-calander-year-2025-press-note/en
- Act text on the board's site, consolidated only to the 1976 and 1994 amendments: https://klwb.karnataka.gov.in/33/about-labour-welfare-board/en

### Verbatim excerpts
- Gazette, s.2 of the amending Act: "in section 7A, in sub-section (2), for the words 'twenty rupees, forty rupees and twenty rupees', the words, 'fifty rupees, one hundred rupees and fifty rupees' shall respectively be substituted."
- Gazette, s.1(2): "It shall come into force at once." Also: "(Received the assent of the Governor on the 08th day of January, 2025)".
- Act s.7A(2), which fixes the order of payers: "In respect of every employee whose name stands in the register of an establishment on 31st December of a year sum of [...] respectively shall be payable as contribution for that year by the concerned employee, the employer and the State Government."
- Act s.7A(3): "The employer's contribution and the employee's contribution in respect of a year shall be paid by the employer to the Board on or before 15th January of the following year".
- Act s.7A(4), proviso: "nor shall it be made from any wages other than the wages for the month of December of the year to which the contribution relates". A second proviso allows a later month with the Inspector's written permission.
- Press note (English): "Factory/Establishment will pay contribution every year of Rs. 50/- from each employee & Rs. 100/- from employer for each employee i.e, total Rs. 150/- per employee to be remitted through online payment only."
- Press note: "LAST DATE FOR PAYMENT OF CONTRIBUTION: 15-01-2026 (CALENDER YEAR 2025)". It also says late payment attracts "penal interest at the rate of 12% for first 3 months and after 3 months 18%".

### Frequency and due date
- **Annual, by calendar year.**
- **Who is covered:** employees on the register on 31 December.
- **Deduction:** the employee share is deducted from **December wages only**, unless the Inspector permits a later month.
- **Payment:** employee and employer shares together, **on or before 15 January** of the following year, online only (klwbapps.karnataka.gov.in).
- **Government share:** the State pays its own ₹50 to the Board. The employer does not handle it.

### Covered establishments (Act s.2, as shown on the KLWB page)
- A factory under s.2(m) of the Factories Act, 1948.
- A motor omnibus service.
- A plantation or workshop.
- Any establishment, including a society or a charitable or other trust, that carries on business or trade and "employs or on any working day during the preceding twelve months employed **more than fifty** persons".
- Excluded: Central or State Government establishments that are not factories.
- The press note words it differently: factories with 10+ workers (with power) or 20+ (without power), CLRA contractors with 20+ contract workers, and S&CE-Act or Societies-Act establishments "employing **50 or more**". The Act says *more than fifty*, so it differs from the note by one person. The Act governs.

### Employees, wage ceiling and exemptions
- "Employee" means "any person who is employed for wages to do any work skilled or unskilled, manual or clerical, in an establishment".
- The text on the official page has **no wage ceiling and no managerial or supervisory exclusion**.

### Unresolved
- The board's consolidated Act is stale. It still shows ₹3/₹6/₹3 and does not show the ₹20/₹40/₹20 step. Later changes to the definitions of "employee" or "establishment" therefore cannot be ruled out; check the e-gazette consolidation.
- Which rate applies to the **2024** calendar-year contribution? The register date was 31-12-2024, and the Act took force on 10-01-2025, before the 15-01-2025 payment date. This is likely ₹20/₹40/₹20 but is NOT CONFIRMED.
- The statement form ("Form D" in the earlier lead): NOT CONFIRMED.
- The "factory" definition refers to the Factories Act, which has been replaced by the OSH Code since 21-11-2025. Needs legal review.

### Parameter block
```json
{
  "jurisdiction": "IN-KA", "levy": "LWF",
  "employee_amount": 50, "employer_amount": 100, "government_amount": 50,
  "period": "calendar_year",
  "eligibility_snapshot": "on_register_31_dec",
  "months_deducted": [12],
  "due": {"month": 1, "day": 15, "year_offset": 1},
  "wage_ceiling": null,
  "establishment_threshold": "factory (Factories Act s.2(m)); other business/trade establishments: >50 persons on any working day in preceding 12 months",
  "effective_from": "2025-01-10",
  "first_contribution_year_at_new_rate": 2025,
  "previous": {"employee_amount": 20, "employer_amount": 40, "government_amount": 20},
  "late_interest": "12% first 3 months, 18% thereafter (KLWB press note)",
  "source": "Karnataka Act 05 of 2025, DPAL 60 SHASANA 2024 dt 10-01-2025"
}
```

---

## 2. Haryana Labour Welfare Fund

**Verdict: PARTIALLY CONFIRMED.** Confidence: **OFFICIAL_PRIMARY_SOURCE_VERIFIED** for the parameters.
- Confirmed: rate 0.2%, cap ₹35 from January 2026, employer 2×.
- Contributions accrue monthly, but the statutory **payment** date is **before 31 December each year**. That refutes the lead's "monthly, due last day of the month".

### Instruments
- **Punjab Labour Welfare Fund Act, 1965, s.9A(1)**, as substituted by **Haryana Act No. 18 of 2019 (14-03-2019)**:
  - 0.2% of salary, wages or remuneration, subject to a limit.
  - Employer pays twice the employee amount.
  - "the limit specified above shall be indexed annually to the consumer price index beginning from first of January each year".
- **s.9A(2)**, as substituted by **Haryana Act No. 16 of 2021 (05-04-2021)**: annual payment before 31 December, online.
- **The limit is revised by Haryana Labour Welfare Board notification**, under the CPI-indexation proviso:
  - ₹25 from April 2019 (the Act).
  - ₹31 from January 2023: office letter HLWB/REV/2023/2733-2982 dated 27-06-2023.
  - ₹34 from January 2025: office letter HLWB/REV/2025/1306-1530 dated 07-03-2025.
  - **₹35 from 01-01-2026: Notification No. HLWB/REV/2026/3436 dated 08-05-2026**, signed 04-05-2026 by the Labour Minister-cum-Chairman, HLWB.
  - The ₹31 and ₹34 letter numbers are footnotes in the official consolidated Act.
- **Caveat on the 2026 notification:** the copy I read is a scan hosted by a vendor (sgcms.com). Its endorsement No. 3437 forwards it for publication in the Haryana Gazette (Extraordinary). I did not find it on a government host.
- The ₹35 value itself is confirmed on two official hrylabour.gov.in pages (below).
- **Effective date:** 01-01-2026. This is retroactive: the notification was issued in May 2026.
- **The cap is not revised every year in practice.** The notifications came in 2023, 2025 and 2026; there was no 2024 change.

### Official URLs
- HLWB page with the rate-history table: https://hrylabour.gov.in/content/cms/MTU
- Consolidated Act on the Labour Department site (PDF created 02-06-2026): https://hrylabour.gov.in/staticdocs/labourActpdfdocs/ThePunjabLabourFundACt1965.pdf
- Highlights page: https://hrylabour.gov.in/content/highlights. It still says "thirty four", so it is stale on the cap, but it states the 31 December deposit rule.
- Vendor-hosted scan of HLWB/REV/2026/3436 (secondary host): https://www.sgcms.com/regulatory-updates/wp-content/uploads/2026/05/Haryana-LWF-Revised-Rates.pdf

### Verbatim excerpts
- HLWB page, rate table: "January - 2026 | Rs. 0.2% of salary of employee or wages or any remuneration subject to a limit of Rs. 35/- | Twice the amount contributed by such employee | Rs. 105 (Per month)"
- Consolidated Act, s.9A(1): "Each employee shall contribute to the Fund every month an amount equal to zero point two percent of his salary or wages or any remuneration subject to a limit of rupees thirty-five and each employer in respect of each such employee shall contribute to the Fund every month, twice the amount contributed by such employee"
- Same, proviso: "Provided that the limit specified above shall be indexed annually to the consumer price index beginning from first of January each year."
- Same, Explanation: "'employee' means an employee on the register of an establishment on the last working day of the month."
- Same, s.9A(2): "Every employer shall pay to the Fund both his contribution and the contribution of the employee before the 31st December of every year through online mode in favour of Welfare Commissioner."
- Same, s.9A(4): "Any employer who fails to pay the contribution amount within a period of one month from the date specified under sub-section (2), shall be liable to pay interest at the rate of twelve percent per annum".
- Highlights page: "This amount is required to be deposited by 31st December of every year (section-3,9,9A)."
- HLWB/REV/2026/3436 (vendor-hosted scan): "Further, w.e.f. 01.01.2026, the compliance shall be ensured as under: '(1) Each employee shall contribute [...] subject to a limit of rupees thirty-five [...]'"

### Frequency and due date
- **Accrual is monthly.** Each month: employee = min(0.2% × wages, ₹35); employer = 2 × the employee amount. The basis is employees on the register on the last working day of the month.
- **Payment:** **annually, before 31 December**, online at hrylabour.gov.in.
- **Interest:** 12% a year if not paid within one month after that date.
- Whether the portal also accepts monthly or quarterly remittance is a practice question, not statute, and is NOT CONFIRMED. Either way, the payroll engine should treat monthly accrual and the 31 December deadline separately.

### Coverage and exemptions
- **Establishments (s.2(4)):** a factory; any premises where an "industry" under the Industrial Disputes Act s.2(j) is carried on; and a shop or commercial establishment under the Punjab S&CE Act 1958, "in which, on any day, Ten or more employees are employed or were employed during the preceding twelve months".
- **Employees (s.2(2)):** "any person employed, directly by or through any agency (including a contractor) [...] for remuneration". Contract workers are included.
- **No wage ceiling and no managerial exclusion.**

### Unresolved
- Does the 10-employee threshold attach only to shops and commercial establishments, or to the whole definition? The drafting is ambiguous; legal review needed.
- The contribution base: "wages" is defined by reference to the Payment of Wages Act, which the Code on Wages superseded from 21-11-2025. Legal review needed.
- Rounding of 0.2% is not specified.
- The gazette publication of HLWB/REV/2026/3436 was NOT FOUND on a government host.
- Retroactivity: deductions for January–April 2026 made at the ₹34 cap fall short by ₹1 (employee) and ₹2 (employer) per affected employee per month.

### Parameter block
```json
{
  "jurisdiction": "IN-HR", "levy": "LWF",
  "employee_rate": 0.002,
  "employee_cap_per_month": 35,
  "employer_multiplier": 2,
  "accrual": "monthly",
  "eligibility_snapshot": "on_register_last_working_day_of_month",
  "payment_due": {"rule": "before 31 December of each year", "month": 12, "day": 31, "strictly_before": true},
  "late_interest": "12% p.a. if unpaid one month after due date (s.9A(4))",
  "wage_ceiling": null,
  "establishment_threshold": "10+ employees on any day in preceding 12 months (at least for shops/commercial establishments)",
  "cap_history": [
    {"from": "2019-04-01", "cap": 25},
    {"from": "2023-01-01", "cap": 31, "ref": "HLWB/REV/2023/2733-2982 dt 27-06-2023"},
    {"from": "2025-01-01", "cap": 34, "ref": "HLWB/REV/2025/1306-1530 dt 07-03-2025"},
    {"from": "2026-01-01", "cap": 35, "ref": "HLWB/REV/2026/3436 dt 08-05-2026"}
  ],
  "cap_revision": "CPI-indexed from 1 January each year by statute; set by HLWB notification, not every year and possibly retroactive"
}
```

---

## 3. West Bengal professional tax: new slabs from 1 October 2026

**Verdict: PARTIALLY CONFIRMED.** Confidence: **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**.

### Instruments
- **West Bengal Finance Act, 2026 (West Bengal Act XIII of 2026)**, Law Department notification **No. 756-L dated 07-08-2026**, Kolkata Gazette Extraordinary Part III, 07-08-2026. Official copy read: https://comtax.wb.gov.in/GST/GST_Acts_Rules/756L_20260820.pdf. It amends the WB State Tax on Professions, Trades, Callings and Employments Act, 1979:
  - s.3(2) now sets a default levy of "two thousand five hundred rupees per annum".
  - A new **s.3(2A)** lets the State Government specify, by notification, different rates in the Schedule.
  - s.2(b)(i) and (iii) now cover employees whether or not the employer's headquarters or the salary-disbursing office is in the State.
  - s.4A(4), s.6(1) (manpower details in returns), s.7(4a), s.15(1a) and s.19 (penalties decriminalised) are also amended.
- **Draft Schedule: Finance Department (Revenue) Notification No. 1407-F.T., Howrah, 18-08-2026**, Kolkata Gazette Extraordinary Part I, SAR-470. It was issued under s.3(3) and invited objections within 14 days. **I read this official text in full:** https://comtax.wb.gov.in/pdf/SAR-470_Finance%20Dept(Rev)_1407-FT.pdf
- **Final Schedule: No. 1607-F.T. dated 16-09-2026.** Every source for this is secondary (TeamLease RegTech, Taxaj, DCP Solutions). They say it finalised the draft without objections and kept the same employee slabs. **The official text was NOT retrieved.**
  - comtax.wb.gov.in does not list it.
  - finance.wb.gov.in listing pages return "File Not Found".
  - The wbcomtax.gov.in and wbprofessiontax hosts are unreachable or refused connections.
  - wbxpress has a file at /files/2026/09/1607-FT.pdf, but it is paywalled.

### Employee slab table (Part A, Sl. No. 1), verbatim from the official draft 1407-F.T.
"Employees earning monthly salary or wages or remuneration or reward or compensation etc.":
- a) Not exceeding Rs. 20,000: NIL
- b) Above Rs. 20,000 but not exceeding Rs. 30,000: Rs. 100 per month
- c) Above Rs. 30,000 but not exceeding Rs. 50,000: Rs. 140 per month
- d) Above Rs. 50,000 but not exceeding Rs. 100,000: Rs. 170 per month
- d) [sic] Above Rs. 1,00,000: Rs. 208 per month

The secondary reports of the final 1607-F.T. give the same five bands.

### Effective dates
- Draft N.B.: "(1) Sl. No. 1 of Part A, whole of Part B and whole of Part C of the Schedule will come into force with effect from the 1st day of October, 2026. (2) Sl. No. 2 & Sl. No. 3 of Part A of the Schedule will come into force from the financial year starting on and from the 1st day of April, 2027."
- **Sources conflict on the final notification.** TeamLease says the final 1607-F.T. moved the **whole of Part C** (the flat ₹2,500 a year classes, which include companies and directors) to **1-Apr-2027**. Taxaj and SGCMS say 1-Oct-2026. This does not affect the employee slabs, which every source dates to 1-Oct-2026. It does affect employer-entity PT.

### Old schedule, valid to 30-09-2026 (official)
Official comtax copy of the Schedule effective 1-4-2014 (Notification 440-L dated 11-03-2014, read with 848-F.T. dated 28-05-2014): https://comtax.wb.gov.in/Ptax-Schedule-New_(w.e.f._1-4-2014).pdf
- Not exceeding Rs.8,500: Nil.
- Above Rs.8,500 but not exceeding Rs.10,000: Nil*.
- Rs.10,001–15,000: Rs.110.
- Rs.15,001–25,000: Rs.130.
- Rs.25,001–40,000: Rs.150.
- Above Rs.40,000: Rs.200 per month.

This matches the lead's "≤₹10,000 nil" table.

### Payment and return, from the official Rules consolidation on comtax (2008 PDF; may be stale)
URL: https://comtax.wb.gov.in/Act_Rule_Schedule_Form/PT_Rule.pdf
- **Payment:** rule 12(1)(b)(i) and rule 12(1b)(i): pay "the amount of tax payable according to his accounts for each of the first [two/eleven] months [...] **within twenty-one days from the expiry of each month**". That makes it the **21st of the following month**. The last month of the return period is paid before the return is filed.
- **Return: Form III.** Rule 12(1)(a): **quarterly**, "within one month from the date immediately following the date of expiry of each quarter".
- Rule 12(1a): an employer whose annual tax is **≤ ₹30,000** files **annually** in Form III "within one month from the date immediately following the date of expiry of such year", i.e. by 30 April.
- Rule 12(1c): an employer whose tax exceeds ₹30,000 in a year moves to quarterly returns.
- Rule 12B: certain large employers file an annual CA/CMA/CS certificate within 7 months of year-end.
- **NOT CONFIRMED:** whether later amendments made Form III annual for all employers, as current practice and the lead suggest. The official consolidation predates any such change. Also unconfirmed: the current e-filing date, which DCT extends most years.

### Covered persons and exemptions
- **Covered:** all employees earning salary or wages in West Bengal, deducted by the employer under s.4. Under the 2026 amendment, an employee working in West Bengal is covered even if paid from outside the State, once that provision commences.
- **Part B exemptions:** classes exempted by Notifications 941-F.T. (07-03-1981) and 368-F.T. (28-03-2013), and Border Security Force personnel on active duty. The class lists themselves are NOT itemised here.
- **Cap check:** ₹208 × 12 = ₹2,496, under the ₹2,500 limit in Article 276(2).

### Unresolved
- The official text of **1607-F.T.** is NOT FOUND. That includes its final N.B. dates (Part C dispute) and any wording changes from the draft.
- The **commencement notification for s.2 of the WB Finance Act, 2026** is NOT FOUND. Section 1(2) says that only s.1 commences immediately and the other provisions "on such date [...] as the State Government may, by notification [...] appoint". The new s.3(2A), which the Schedule depends on, needs that notification, unless 1607-F.T. itself does it. **LEGAL_REVIEW_REQUIRED** on this point.
- Return periodicity: whether quarterly Form III still exists for employers paying more than ₹30,000 a year (see above).

### Parameter block
```json
{
  "jurisdiction": "IN-WB", "levy": "PT", "basis": "monthly_salary", "unit": "INR per month",
  "schedules": [
    {"effective_from": "2014-04-01", "effective_to": "2026-09-30",
     "slabs": [[10000, 0], [15000, 110], [25000, 130], [40000, 150], [null, 200]],
     "source": "440-L dt 11-03-2014 r/w 848-F.T. dt 28-05-2014 (official comtax copy)"},
    {"effective_from": "2026-10-01", "effective_to": null,
     "slabs": [[20000, 0], [30000, 100], [50000, 140], [100000, 170], [null, 208]],
     "source": "1407-F.T. dt 18-08-2026 (official draft, read); finalised as 1607-F.T. dt 16-09-2026 (secondary only)"}
  ],
  "payment_due": "within 21 days from expiry of each month (r.12) => 21st of following month; last month of return period paid before return",
  "return": {"form": "III", "periodicity": "quarterly if annual tax > 30000 else annual (r.12, 2008 consolidation; current universal-annual status NOT CONFIRMED)",
             "due": "within one month after period end (annual: 30 April)"},
  "part_c_entity_flat_2500_effective_from": "CONFLICTING: 2026-10-01 (draft N.B.) vs 2027-04-01 (TeamLease on final)"
}
```

---

## 4. Kerala professional tax: half-yearly slabs from 1 October 2024

**Verdict: CONFIRMED.** Confidence: **OFFICIAL_PRIMARY_SOURCE_VERIFIED**.

### Instrument
- **G.O.(Rt) No. 1149/2024/LSGD, Thiruvananthapuram, dated 27-06-2024**, Local Self Government (RC) Department, file LSGD-RC2/13/2024-LSGD. The Malayalam is "സ.ഉ.(സാധാ)", meaning an ordinary order (Rt), **not G.O.(P)** as the lead said.
- It follows the recommendation at para 2.118 of the 6th State Finance Commission's second report, accepted by reference G.O.(P) No. 110/2022/Fin dated 25-06-2022.
- It is signed by Dr. Sharmila Mary Joseph, Principal Secretary.
- Official URL: https://go.lsgkerala.gov.in/files/go20240627_37169.pdf (Malayalam, 2 pages; table in English; read visually).

### Full half-yearly slab table, verbatim from the G.O. (rupees per half-year)

| Sl | Range of Half Yearly Income | Current Rate | New Rate |
|---|---|---|---|
| 1 | Up to Rs.11,999 | Nil | Nil |
| 2 | Rs.12,000 to Rs.17,999 | 120 | 320 |
| 3 | Rs.18,000 to Rs.29,999 | 180 | 450 |
| 4 | Rs.30,000 to Rs.44,999 | 300 | 600 |
| 5 | Rs.45,000 to Rs.99,999 | 450/600/750 | 750 |
| 6 | Rs.1,00,000 to Rs.1,24,999 | 1000 | 1000 |
| 7 | Rs.1,25,000 onwards | 1250 | 1250 |

The old band 5 was three bands: ₹45,000–59,999 → 450; ₹60,000–74,999 → 600; ₹75,000–99,999 → 750. This is per the Kerala Panchayat Raj (Profession Tax) Rules 1996, r.3 Schedule, on the official LSGD law-manual wiki.

### Effective date and scope
- Para 3 of the G.O., translated from Malayalam: "The revised profession tax rates shall come into force from **October 1**, the beginning of the **second half-year of 2024**. Necessary amendments incorporating the rates shall be made in the Kerala Municipality Act 1994, Kerala Municipality (Profession Tax) Rules 2005, Kerala Panchayat Raj Act 1994 and Kerala Panchayat Raj (Profession Tax) Rules 1996."
- **It applies to both grama panchayats and urban bodies.** The subject line says "പഞ്ചായത്തുകളിലെയും മുനിസിപ്പാലിറ്റികളിലെയും" (in panchayats and municipalities). The body says "ഗ്രാമ പഞ്ചായത്തുകളിലെയും നഗരസഭകളിലെയും" (grama panchayats and urban local bodies). The copy list includes all corporation mayors and secretaries, all municipal chairpersons and secretaries, and all grama panchayat presidents and secretaries.

### Frequency and due dates
- **Half-yearly:** April–September and October–March.
- **Panchayats, statutory text** (Kerala Panchayat Raj Act 1994, official LSGD law-manual wiki, https://panchayatwiki.lsgkerala.gov.in/Panchayat:Repo18/Law_Manual_Page0264):
  - **s.205F(2):** "Before the end of August and February every year each head of office and employer shall assess the tax payable by all employees liable to pay tax and recover the amount from them and pay over to the Village Panchayat together with a list of all employees whose tax has been assessed".
  - s.205F(1): the Secretary issues notices in May and November.
  - s.205H: self-drawing officers pay "before the end of August and February".
  - PT Rules 1996, r.20(3): amounts deducted are remitted "within 10 days".
  - r.21(3): DDOs certify remittance in the February and August salary bills.
- **Municipalities and corporations:** the equivalent Municipality Act provision was NOT CONFIRMED officially (the Act text was not fetched). Secondary sources give the same end-August and end-February dates.

### Covered persons and exemptions
- **Liability (KPR Act s.204(1)):** a person who, in the half-year, "holds any appointment, public or private" in the area for **not less than sixty days in the aggregate**, or resides there that long while employed elsewhere. Companies transacting business there for 60+ days are also liable.
- **s.204(3) Explanation:** "aggregate income" excludes **House Rent Allowance, City Compensatory Allowance, Conveyance Allowance and Travelling Allowance**.
- **s.204(4):** credit is given for tax already paid to another local body for the same half-year.
- **Exemption:** half-yearly income up to ₹11,999 pays nil.

### Unresolved
- **The statute sets maximums.** Under s.204(2) and rule 3, rates are *maximum* rates, and each local body fixes its rate by resolution within them. The G.O. directs that the Acts and Rules be amended. Two things are NOT CONFIRMED: whether the Rules' Schedule was formally amended by SRO, and whether each local body passed a resolution. **LEGAL_REVIEW_REQUIRED** before relying on the rates as statutory in every local body. Market practice and LSGD's order both treat them as the applicable rates from 1-10-2024.
- The municipal due-date provision is not fetched.
- A return form for employers: the Panchayat Rules use a requisition-and-demand cycle (Forms I–V), not a self-assessed return. Equivalent municipal forms: NOT CONFIRMED.

### Parameter block
```json
{
  "jurisdiction": "IN-KL", "levy": "PT", "basis": "half_yearly_income",
  "unit": "INR per half-year (NOT monthly)",
  "half_years": [{"from_month": 4, "to_month": 9}, {"from_month": 10, "to_month": 3}],
  "schedules": [
    {"effective_from": "2024-10-01",
     "slabs": [[11999, 0], [17999, 320], [29999, 450], [44999, 600], [99999, 750], [124999, 1000], [null, 1250]],
     "source": "G.O.(Rt) No.1149/2024/LSGD dt 27-06-2024"},
    {"effective_to": "2024-09-30",
     "slabs": [[11999, 0], [17999, 120], [29999, 180], [44999, 300], [59999, 450], [74999, 600], [99999, 750], [124999, 1000], [null, 1250]],
     "source": "KPR (Profession Tax) Rules 1996 r.3 (maximum rates)"}
  ],
  "income_exclusions": ["HRA", "CCA", "conveyance_allowance", "travelling_allowance"],
  "min_days_in_half_year": 60,
  "due": {"first_half": "before end of August", "second_half": "before end of February", "source": "KPR Act s.205F(2) (panchayats); municipalities NOT CONFIRMED"},
  "applies_to": ["grama_panchayat", "municipality", "municipal_corporation"]
}
```

---

## 5. Odisha professional tax repeal

**Verdict: CONFIRMED** for the repeal from 1-4-2026 and its savings clause, from the official Ordinance. The Repeal Act's status: reported passed in news only; assent and the Act are NOT FOUND officially. Confidence: **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**. The Ordinance itself is official primary; the Act's status is secondary only.

### Instrument
- **Odisha Ordinance 02 of 2026: the Odisha State Tax on Professions, Trades, Callings and Employment (Repeal) Ordinance, 2026.**
  - Odisha Gazette Extraordinary **No. 1496, Cuttack, 21-04-2026**.
  - Law Department notification **No. 5969-I-Legis-22/2026/L dated 21-04-2026**.
  - Signed "Dated the 20th April, 2026" by Governor Hari Babu Kambhampati. The notification text says it was promulgated on 21-04-2026.
- It repeals Odisha Act 7 of 2000.
- Official URLs:
  - https://finance.odisha.gov.in/sites/default/files/2026-04/1496-LAW%20DEPTT..pdf
  - Listed at https://finance.odisha.gov.in/en/notification/tax-profession (entry dated 21/04/2026, no later item).

### Verbatim excerpts
- s.1(2): "This Ordinance shall come into force with effect from dated 1st April, 2026."
- s.2(1): "The Odisha State Tax on Professions, Trades, Callings and Employments Act, 2000 is hereby repealed."
- s.2(2): "The repeal of the said Act shall not affect, (a) its previous operation or anything duly done or action taken including orders passed, returns submitted, notices issued, assessments made, taxes paid and arrear of tax to be realized; or. (b) Any right, obligation or liability acquired, accrued or incurred thereunder; or (c) any investigation, legal proceeding or remedy in respect of any such right, obligation or liability [...] may be instituted, continued or enforced as if the said Act had not been repealed."

### What this means for payroll
- **No PT deduction for salary periods from April 2026.**
- Liabilities for FY 2025-26 and earlier survive: March-2026 deposits, the Form V annual return, arrears and assessments.
- The official Commercial Tax PT page (https://odishatax.gov.in/professional_tax.php?lang=en) has not been updated. It still describes monthly deduction and the **annual Form V return, filed online within three months of year-end**, which is the pre-repeal rule for FY 2025-26.

### Repeal Act status as of 26-10-2026
- **Introduced:** the Repeal Bill was introduced on 22-09-2026 and gazetted 23-09-2026 (TeamLease RegTech; secondary).
- **Passed:** Deccan Chronicle (news) reports "the Odisha State Tax on Professions, Trades, Callings and Employments Bill, 2026" among four bills passed in the Monsoon Session. The session began 22-09-2026 and was adjourned sine die on 28-09-2026.
- **NOT FOUND:** the Governor's assent, an Odisha Act number, or a gazette notification of the Act.
  - The Assembly website's bill and bulletin pages run on postback scripts and could not be read.
  - The Odisha Government Press extraordinary-gazette listing stops in 2025.
- **Why it matters (Article 213(2)(a)):** if the Act is not in force, the Ordinance lapses six weeks after the Legislature reassembled. Counting from 22-09-2026, that is about **03-11-2026**. If the Act received assent, the lapse is moot; the Bill reportedly takes deemed effect from 1-4-2026. **LEGAL_REVIEW_REQUIRED** if assent cannot be shown by early November 2026.

### Covered persons and exemptions
- Before repeal, every employer registered under Act 7 of 2000 was covered. After 1-4-2026, no new liability arises.
- The pre-repeal slabs were not re-verified here; they were not in scope.

### Parameter block
```json
{
  "jurisdiction": "IN-OD", "levy": "PT",
  "levy_active_until": "2026-03-31",
  "repealed_from": "2026-04-01",
  "instrument": "Odisha Ordinance 02 of 2026 (Gazette Ext. No.1496, 21-04-2026; Law Dept No.5969-I-Legis-22/2026/L)",
  "savings": ["previous operation", "returns submitted", "assessments", "taxes paid", "arrears recoverable", "accrued liabilities", "proceedings continue"],
  "residual_obligations_fy2025_26": {"annual_return_form": "V", "due": "within 3 months of FY end (official odishatax.gov.in page)"},
  "repeal_act_status": "Bill introduced 22-09-2026; reported passed (news); assent/Act number NOT CONFIRMED",
  "ordinance_lapse_risk_date_if_no_act": "2026-11-03 (approx., Art. 213(2)(a), six weeks from 22-09-2026)"
}
```

---

## Corrections to the earlier research doc
- **Kerala:** the order is G.O.**(Rt)** No.1149/2024/LSGD, not G.O.(P). The due dates (end of August and end of February) are now officially sourced for panchayats (KPR Act s.205F(2)).
- **Haryana:** the payment due date is **before 31 December annually** (s.9A(2), Haryana Act 16 of 2021), not the last day of each month. The cap changes have been irregular, not yearly. The 2026 change was notified on 08-05-2026, retroactive to 01-01-2026.
- **Karnataka:** the instrument is **Karnataka Act No. 05 of 2025**, the LWF (Amendment) Act 2024 (DPAL 60 SHASANA 2024, 10-01-2025). It is not a "Government order". Coverage for non-factory establishments is **more than 50 persons**, not "10+".
- **West Bengal:** 1407-F.T. (18-08-2026) is the official **draft**, inviting objections within 14 days. The enabling law is WB Finance Act 2026 (Act XIII of 2026, 756-L, 07-08-2026), not a "Finance Act 2026" amendment of rates. A commencement notification for its s.2 is still to be found.
- **Odisha:** confirmed as written in the doc. Additionally, the Repeal Bill was reportedly passed in late September 2026; assent is unverified.
