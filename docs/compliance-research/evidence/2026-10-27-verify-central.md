# Central labour-law parameters: primary-source verification

Prepared 2026-10-26 for Markedge PeopleOS. This builds on `docs/compliance-research/2026-10-26-central-obligations.md` §§1, 5, 6, 7 and 9, which were used as leads only.

## Labels

| Label | Meaning |
|---|---|
| OFFICIAL_PRIMARY_SOURCE_VERIFIED | Read in the Gazette text or the statute, on an official domain (labour.gov.in, indiacode.nic.in, pib.gov.in, esic.gov.in), or in a Gazette PDF whose Government of India Press signature validates. |
| OFFICIAL_SOURCE_PARTIALLY_VERIFIED | Official text was seen only in part: through a mirror copy of the Gazette, an official FAQ or press release rather than the instrument itself, or a search-result extract from an official domain. |
| SECONDARY_SOURCE_ONLY | Commentary, blogs or vendor guides only. |
| CONFLICTING_SOURCES | Sources (official or otherwise) disagree. |
| LEGAL_REVIEW_REQUIRED | The text is verified, but applying it needs a legal judgement that no official source settles. |
| UNVERIFIED | Not checked, or could not be checked. |

## Access notes

- **egazette.gov.in could not be fetched.** Both curl and WebFetch failed with "unable to verify the first certificate". TLS checks were not bypassed. The Gazette IDs below give the expected egazette URLs, but those URLs were not fetched.
- **epfindia.gov.in now redirects** (301) to epfo.gov.in, which returned 404 for the circular PDFs. Only search-result extracts from the EPFO domain were available.

### Primary documents actually read

| Document | Source and integrity check |
|---|---|
| Code on Wages, 2019 | India Code, "As on 21 Nov 2025": https://www.indiacode.nic.in/bitstream/123456789/15793/1/aA2019-29.pdf |
| Code on Social Security, 2020 | India Code, "As on 21 Nov 2025": https://www.indiacode.nic.in/bitstream/123456789/16823/1/aA2020-36.pdf |
| OSH & WC Code, 2020 | Gazette CG-DL-E-29092020-222112, on labour.gov.in: https://www.labour.gov.in/static/uploads/2025/07/36fcfa5d8e6b9145e282bf7b950d6c47.pdf. **Gazette signature valid.** |
| S.O. 4710(E), 25 Aug 2026 | Gazette CG-DL-E-25082026-275741 (Extraordinary No. 4525), on labour.gov.in: https://www.labour.gov.in/static/uploads/2026/09/3977906b27b1b53b9b98c245091b0726.pdf. **Signature valid** (Government of India Press, signed 2026-08-25 19:16:24 IST, whole document). |
| S.O. 4711(E), 25 Aug 2026 | Gazette CG-DL-E-25082026-275747 (Extraordinary No. 4526). Mirror copy only: https://www.taxheal.com/wp-content/uploads/2026/08/275747.pdf. The Gazette text and header are intact, but the mirror stripped the signature. |
| SS (Central) Rules 2026, G.S.R. 344(E) | Gazette CG-DL-E-08052026-272366, on labour.gov.in: https://www.labour.gov.in/static/uploads/2026/05/49aa9b62c2125499c37399b90e969d67.pdf. The signature reports "Digest Mismatch", meaning the file was re-saved after signing. Its text matches the StaffNews mirror in `dl/ss2026.txt`. |
| OSH (Central) Rules 2026, G.S.R. 345(E) | Local `dl/osh2026.pdf`, Gazette CG-DL-E-09052026-272379. **Signature valid.** |
| Wages (Central) Rules 2026, G.S.R. 343(E) | Local `dl/wages2026.pdf` (PRS copy, unsigned). Text extraction of Chapter V is partly garbled by the Hindi font. |
| MoLE FAQs and handbook | FAQs of 30 Dec 2025: https://www.labour.gov.in/static/uploads/2026/01/de4758d5bfeffc456d7de97a801891b0.pdf<br>Code on Wages FAQs: https://www.labour.gov.in/static/uploads/2026/01/75252c07c1fe3a2f5571ff1464e2375e.pdf<br>Additional FAQs as on 16 Mar 2026: https://www.labour.gov.in/static/uploads/2026/03/a4ccf4c6d97c4f1f36a6d83f8c64213d.pdf<br>Compliance Handbook: https://www.labour.gov.in/static/uploads/2026/02/83978455025732b99b0165def80ab171.pdf<br>All MoLE FAQs carry a disclaimer: "do not purport to be legal documents". |
| Payment of Bonus Act 1965 (repealed) | MoLE copy: https://www.labour.gov.in/static/uploads/2025/06/71f40bdd15ede77446bb56d7b1c16df7.pdf |
| PIB release, 30 Mar 2018 (gratuity) | https://www.pib.gov.in/newsite/PrintRelease.aspx?relid=178218&reg=48&lang=2 |
| ESIC FAQ (Jan 2018) | https://esic.gov.in/attachments/files/faq.pdf |

---

## A. Bonus: Code on Wages, Chapter IV (ss.26–41) and Wages (Central) Rules 2026, Chapter V (rr.21–28)

### Commencement

Chapter IV and s.69 (repeal of the Payment of Bonus Act) came into force on 21 Nov 2025. **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (India Code footnote to s.1(3)): "21st day of November, 2025—Ss. 1 to 41, sub-sections (4) to (9) of section 42, ss. 43 to 66, … section 68, section 69 … vide notification no. S.O. 5322 (E), dated 21st November, 2025".

### A.1 Eligibility wage ceiling: ₹21,000 per month

**OFFICIAL_SOURCE_PARTIALLY_VERIFIED.** The Gazette text was read from a mirror copy (Gazette ID CG-DL-E-25082026-275747). No official-domain copy could be reached.

> "S.O. 4711(E).— In exercise of the powers conferred by sub-section (1) of section 26 of the Code on Wages, 2019 (29 of 2019), the Central Government hereby determines that every employee drawing wages not exceeding twenty-one thousand rupees per mensum shall be paid bonus as specified in the said sub-section.
> 2. This notification shall be deemed to have come into force on the 21st day of November, 2025." — signed DEEPIKA KACHHAL, Jt. Secy.

- The test is "not exceeding", so ₹21,000.00 itself is eligible.
- The S.O. is made by the **Central Government**. Under s.26(1), the amount is "as determined by notification, by the appropriate Government" (see A.3(k)).
- Expected official URL, not fetched: https://egazette.gov.in/WriteReadData/2026/275747.pdf

### A.2 Calculation ceiling: ₹7,000 or the Central minimum wage, whichever is higher

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.** Signed Gazette PDF on labour.gov.in.

> "S.O. 4710(E).— In exercise of the powers conferred by sub-section (2) of section 26 of the Code on Wages, 2019 (29 of 2019), the Central Government hereby determines that for the purpose of calculation of the bonus payable under sub-sections (1) and (3) of section 26 of the said Code, where the wage of an employee being eligible for bonus under sub-section (1) of section 26, exceed seven thousand rupees per mensum, the bonus payable to such employee shall be calculated, as if his wage was seven thousand rupees per mensum or the minimum wage fixed by the Central Government, whichever is higher.
> 2. This notification shall be deemed to have come into force on the 21st day of November, 2025."

The statute, s.26(2), says (OFFICIAL_PRIMARY_SOURCE_VERIFIED): "…calculated as if his wage were such amount, so determined by the appropriate Government or the minimum wage fixed by the appropriate Government, whichever is higher."

**Which minimum wage?**
- The S.O. says "the minimum wage fixed by the **Central Government**". It does not say the State's minimum wage, or a "scheduled employment" rate.
- Code s.2(s) defines "minimum wage" as "the wage fixed under section 6". Minimum wages under s.6 are fixed per skill category and geographical area, so the comparator is the Central minimum rate for the employee's category and area. In practice that is the central-sphere rate (basic plus VDA) under the Chief Labour Commissioner's orders. The CLC lists a VDA order dated 02 Apr 2026, effective 01 Apr 2026 (search extract only; rates not read).
- **LEGAL_REVIEW_REQUIRED:**
  - which Central rate to use for an employee whose category is not covered by a central notification;
  - whether rates continued from the 1948 Act under Code s.69(2) count as fixed "under section 6";
  - converting a daily rate to monthly (×26 is the usual practice, but the S.O. does not say so).

**Practical effect:** Central minimum monthly rates are generally well above ₹7,000. For central-sphere employers the calculation wage is therefore usually the Central minimum wage, not ₹7,000. This inference is consistent with commentary, but **2026 central rates are NOT CONFIRMED**.

**The repealed Act was different** (MoLE copy, OFFICIAL_PRIMARY_SOURCE_VERIFIED). PoB Act s.12 read: "seven thousand rupees or the minimum wage for the scheduled employment, as fixed by the appropriate Government, whichever is higher". The comparator there was the appropriate Government's rate for the scheduled employment, typically the State rate.

**State sphere:** see A.3(k). LEGAL_REVIEW_REQUIRED.

Expected official URL: https://egazette.gov.in/WriteReadData/2026/275741.pdf (the signed copy was read from labour.gov.in).

### A.3 Rates, conditions and applicability

All statute text below is from the India Code copy of the Code on Wages and is **OFFICIAL_PRIMARY_SOURCE_VERIFIED** unless a different label is given.

**(a) Minimum bonus, s.26(1).**
> "…who has put in at least thirty days work in an accounting year, an annual minimum bonus calculated at the rate of eight and one-third per cent. of the wages earned by the employee or one hundred rupees, whichever is higher whether or not the employer has any allocable surplus during the previous accounting year."

The rate is exactly 8⅓% (1/12). Use 1/12, not 0.0833.

**(b) Maximum bonus.**
- s.26(3): where allocable surplus exceeds the minimum bonus, bonus is "in proportion to the wages earned … subject to a maximum of twenty per cent. of such wages".
- s.26(5): bonus on production or productivity is by agreement or settlement, but "the total bonus including the annual minimum bonus … shall not exceed twenty per cent. of the wages earned by the employee in the accounting year".

**(c) Thirty days' work.** s.26(1): "has put in at least thirty days work in an accounting year".

**(d) Accounting year.** s.2(a): "'accounting year' means the year commencing on the 1st day of April". The year is fixed as April–March. The PoB Act had allowed other accounting years.

**(e) "Wages" for bonus: s.2(y), with the 50% add-back.**
- s.2(y) applies throughout the Code "unless the context otherwise requires". Chapter IV has no separate definition.
- Inclusions: basic pay, dearness allowance and retaining allowance.
- Exclusions (a)–(k): bonus not part of the terms of employment, amenities, employer PF/pension contributions, conveyance, special expenses, HRA, award or settlement remuneration, overtime, commission, gratuity, and retrenchment or retirement benefits.
- First proviso: "if payments made by the employer to the employee under clauses (a) to (i) exceeds one-half, or such other per cent. as may be notified by the Central Government, of the all remuneration calculated under this clause, the amount which exceeds such one-half … shall be deemed as remuneration and shall be accordingly added in wages".
- Second proviso (the (d), (f), (g), (h) emoluments) is limited to "equal wages to all genders and … payment of wages". It does not apply to bonus.
- **The add-back applies to bonus.** OFFICIAL_SOURCE_PARTIALLY_VERIFIED, from the MoLE Code on Wages FAQ, Q4: "Allowances exceeding a fixed percentage as notified by central government are added back to wages, increasing the base for PF, gratuity, and bonus". The MoLE FAQ of 30 Dec 2025, Q6, says the single definition "applies across all four Labour Codes".
- **LEGAL_REVIEW_REQUIRED, open points:**
  1. Remuneration not in either list (for example a "special allowance"). On a literal reading of s.2(y) it is wages. The MoLE FAQ illustrations treat all allowances as outside "Basic + DA" until the 50% add-back.
  2. The add-back can raise "wages" for the ₹21,000 eligibility test as well as the calculation base.
  3. Additional FAQ 1(b) (16 Mar 2026) says statutory bonus and employer PF are counted in "remuneration" for the 50% test.
  4. MoLE says annual performance incentives and ESOPs are not wages (FAQ 30 Dec 2025 Q3; Additional FAQ Q4).

**(f) Proportionate reduction, s.27.**
> "Where an employee has not worked for all the working days in an accounting year, the minimum bonus under sub-section (1) of section 26, if such bonus is higher than eight and one third per cent. of the salary or wage of the days such employee has worked in that accounting year, shall be proportionately reduced."

On our reading, this pro-rates the ₹100 floor. The 8⅓% bonus is already proportional because it is computed on wages earned. Suggested formula: `max(wages_earned/12, 100 × days_worked/working_days)`. This is an interpretation (LEGAL_REVIEW_REQUIRED, low risk).

**(g) Disqualification, s.29.** Dismissal from service for "(a) fraud; or (b) riotous or violent behaviour while on the premises of the establishment; or (c) theft, misappropriation or sabotage of any property of the establishment; or (d) conviction for sexual harassment."

**(h) Deductions and adjustments.**
- s.37: puja or other customary bonus, or part of the bonus paid before it falls due, may be deducted ("the employee shall be entitled to receive only the balance").
- s.38: "misconduct causing financial loss to the employer" may be deducted "in respect of that accounting year only".

**(i) Applicability and exemptions.**
- s.41(2): Chapter IV applies to "such establishment in which twenty or more persons are employed or were employed on any day during an accounting year".
- s.41(1) excludes:
  - (a) LIC;
  - (b) seamen;
  - (c) registered dock workers;
  - (d) establishments under the authority of any department of the Central or State Government or a local authority;
  - (e) the Red Cross and like institutions, universities and other educational institutions, and non-profit institutions including hospitals, chambers of commerce and social welfare institutions;
  - (f) RBI;
  - (g) notified public-sector financial institutions;
  - (h) inland water transport on routes through another country;
  - (i) establishments exempted by notification because of profit-sharing schemes.
- s.40: the public sector is excluded unless it competes with the private sector and that business earns at least 20% of gross income.
- **Changes from the PoB Act** (whose s.1(3) is OFFICIAL_PRIMARY_SOURCE_VERIFIED):
  - The Code has no "every factory" limb (PoB s.1(3)(a)), so a factory with fewer than 20 persons is outside Chapter IV.
  - The Code has no power to extend coverage down to 10 persons (the PoB s.1(3) proviso).
  - The count is of "persons", not "employees".

**(j) New establishments, s.26(6)–(9).**
- In the first five accounting years after the year of first sale or service, bonus is payable "only in respect of the accounting year in which the employer derives profit", without set-on/set-off.
- Years 6 and 7 use modified set-on/set-off (Wages Rules rr.22–23, Appendix A). Normal rules apply from year 8.
- s.26(9) extends this to new departments, undertakings or branches.

**(k) Do the central S.O.s bind State-sphere establishments?** **LEGAL_REVIEW_REQUIRED.**
- s.26(1) and (2) give the power to "the appropriate Government".
- Under s.2(d), the State Government is the appropriate Government for "any other establishment", including ordinary private shops, offices, IT/ITES firms and factories (see D).
- On the statutory text, therefore, S.O.s 4710 and 4711 operate only where the Central Government is the appropriate Government.
- The MoLE Additional FAQ Q27 states the general principle: "Central rules will be applicable on the establishments where Central Government is Appropriate Government and state rules will be applicable on the establishments where State Government is Appropriate Government." (OFFICIAL_SOURCE_PARTIALLY_VERIFIED)
- **No State notification under s.26 was found.**
- Code s.69(2) keeps in force "any amount of wages provided in any provision of such enactments for any purpose" as if provided under the corresponding provision of the Code, "till they are repealed … or by the notification to that effect by the Central Government".
- Arguably, therefore, State-sphere establishments keep the PoB Act values: ₹21,000 eligibility (s.2(13)) and ₹7,000 or the appropriate (State) Government's minimum wage for the scheduled employment (s.12). The "scheduled employment" concept no longer exists under the Code.
- Commentary agrees that the Central minimum-wage comparator should not be imported into State-sphere establishments (Labour Codes Advisor; Pradeep's blog). That is SECONDARY_SOURCE_ONLY.

**(l) Payment deadline, s.39(1).**
> "…shall be paid by crediting it in the bank account of the employee by his employer within a period of eight months from the close of the accounting year"

- The appropriate Government may extend this, but "the total period so extended shall not in any case exceed two years".
- For an April–March year the deadline is **30 November** (computed).
- s.39(2): where a dispute is pending, payment is due within 1 month of the award or settlement, but 8⅓% must still be paid within 8 months.

**(m) Contract labour, Wages Rules r.21.** If the contractor fails to pay, the principal ("the company or firm or association or other person as referred to in the proviso to section 43") "shall, on the written information of such failure given by the employees or any registered trade union … pay minimum bonus to the employees".

**(n) Accounting year 2025-26 (1 Apr 2025 – 31 Mar 2026), straddling the repeal.** **LEGAL_REVIEW_REQUIRED.**

What is verified:
- The PoB Act was repealed from 21 Nov 2025 (s.69(1)).
- General Clauses Act s.6 applies (s.69(3)), preserving rights accrued under the repealed Act.
- Both S.O.s are "deemed to have come into force on the 21st day of November, 2025".
- The year closes on 31 Mar 2026, while the Code is in force.
- Under both laws payment is due within 8 months, so **by 30 Nov 2026** (PoB s.19; Code s.39).

What is not settled:
- Whether the whole year is computed under the Code, or Apr–20 Nov 2025 under the PoB Act and 21 Nov 2025–Mar 2026 under the Code.
- No official MoLE clarification was found. Commentary says years closing after commencement fall under Chapter IV (SECONDARY_SOURCE_ONLY).

Where the choice matters:
- The wage definition: PoB s.2(21) "salary or wage" is basic + DA and excludes "any other allowance"; the Code adds back allowances above 50%.
- The calculation-ceiling comparator: the State/scheduled-employment minimum wage under the PoB Act; the Central minimum wage under S.O. 4710 for the central sphere.
- Coverage: small factories, and establishments covered by a State's 10-person extension under the PoB Act.

Product recommendation: split the computation at 2025-11-20/21 and let the tenant choose the regime per segment, with the default set by legal sign-off.

### A.4 Days that count as worked: s.28

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.**

> "28. Computation of number of working days.—For the purposes of section 27, an employee shall be deemed to have worked in an establishment in any accounting year also on the days on which,— (a) he has been laid off under an agreement or as permitted by standing orders … or under any other law applicable to the establishment; (b) he has been on leave with salary or wages; (c) he has been absent due to temporary disablement caused by accident arising out of and in the course of his employment; and (d) the employee has been on maternity leave with salary or wages, during the accounting year."

**LEGAL_REVIEW_REQUIRED:** s.28 is expressly "for the purposes of section 27" (proportionate reduction). It does not expressly cover the "thirty days work" eligibility test in s.26(1). The PoB Act had the same structure (s.14 for s.13). Counting these deemed days toward the 30 days as well is employee-favourable and is common practice.

---

## B. Gratuity: Code on Social Security, Chapter V (ss.53–58) and SS (Central) Rules 2026, rr.31–33

Statute text is from India Code. Rules text is from the labour.gov.in Gazette copy of G.S.R. 344(E). Both in force from 21 Nov 2025 (SS Code ss.17–141, S.O. 5319(E); India Code footnote). **OFFICIAL_PRIMARY_SOURCE_VERIFIED.**

### B.1 Ceiling: ₹20,00,000 (₹20 lakh)

**OFFICIAL_SOURCE_PARTIALLY_VERIFIED.**
- s.53(3): "The amount of gratuity payable to an employee shall not exceed such amount as may be notified by the Central Government." Unlike the bonus S.O.s, this power belongs to the Central Government for all establishments.
- **No notification under s.53(3) of the Code was found.**
- The ₹20 lakh figure rests on three sources:
  1. PIB, 30 Mar 2018: "Now, the Government has issued the notification specifying the maximum limit to Rs. 20 Lakh." This was under the Payment of Gratuity Act, 1972 as amended in 2018, effective 29 Mar 2018. The S.O. number, 1420(E), comes only from search extracts of a MoLE brief.
  2. SS Code s.164(2)(a) saves "any … notification … made thereunder" under the repealed Acts, so the 2018 ceiling continues "to the extent they are not contrary".
  3. MoLE FAQ, 30 Dec 2025, Q12: "The maximum gratuity is as notified by the Central Government which is currently ₹ 20 lakhs." The Handbook §6.3(iii) says "subject to the maximum limit notified by the Central Government".
- The reported "Government letter of 3 Aug 2026" relates to a bank unions' request for a higher ceiling (search extracts mention an NCBE letter of 20 Jul 2026 and a MoLE reply citing the better-terms clause). Nothing in it changes the ceiling. SECONDARY_SOURCE_ONLY.

### B.2 Five years' continuous service and its exceptions

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.**
- s.53(1): gratuity is payable on termination "after he has rendered continuous service for not less than five years", on:
  - (a) superannuation;
  - (b) retirement or resignation;
  - (c) death or disablement due to accident or disease;
  - (d) "termination of his contract period under fixed term employment";
  - (e) a notified event.
- 1st proviso: for working journalists, five years means three.
- 2nd proviso: "completion of continuous service of five years shall not be necessary where the termination of the employment of any employee is due to death or disablement or expiration of fixed term employment or happening of any such event as may be notified".
- s.54 (continuous service):
  - Uninterrupted service includes interruptions for sickness, accident, leave, unauthorised absence not treated as a break, lay-off, strike, or lock-out not due to the employee.
  - A year is deemed if the employee actually worked **240 days** (190 below ground or in a less-than-6-day week) in the preceding 12 calendar months. Six months is deemed at **120 days** (95).
  - Days actually worked include lay-off, "leave with full wages, earned in the previous year", temporary disablement from an employment accident, and maternity leave "so, however, that the total period of such maternity leave does not exceed twenty-six weeks".
  - Seasonal establishments: 75% of operating days.
- **LEGAL_REVIEW_REQUIRED:** courts have used s.54(B)-type deeming to treat 4 years plus 240 days as five years. That is case law, not verified here.

### B.3 Fixed-term employees: one year, pro rata

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.**
- s.53(2), 3rd proviso: "in the case of an employee employed on fixed term employment or a deceased employee, the employer shall pay gratuity on pro rata basis."
- s.2(34)(b): a fixed-term employee is eligible for permanent-employee benefits "proportionately according to the period of service rendered by him even if his period of employment does not extend to the required qualifying period of employment".
- SS (Central) Rules r.33(1)(a), 2nd proviso: "an employee on fixed term employment shall be eligible for gratuity, if he renders service under the contract for a period of at least one year and for subsequent period in excess of six months and more, but less than one year, shall be rounded off to one additional year."
- MoLE Additional FAQs Q14 and Q19: eligible "if he/she renders service under the contract for a period of one year (from start of contract)". Handbook §6.3(ii): "on termination of their contract period after completion of one year service". (OFFICIAL_SOURCE_PARTIALLY_VERIFIED)
- MoLE Additional FAQ Q10: fixed-term employment covers only employees engaged directly by the employer, not contract labour engaged through contractors.
- **LEGAL_REVIEW_REQUIRED:**
  - A fixed-term employee who resigns or is terminated before the contract expires, with less than 5 years. Q19 asks this but the answer does not address it.
  - The one-year minimum appears only in the rules, not the Code.
  - "in excess of six months and more" is ambiguous about exactly 6 months. s.53(2) says "in excess of six months", which would mean more than 6 months.

### B.4 The 15/26 formula and the wage base

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.**
- s.53(2): "For every completed year of service or part thereof in excess of six months, the employer shall pay gratuity … at the rate of fifteen days' wages or such number of days as may be notified by the Central Government, based on the rate of wages last drawn".
- Piece-rated employees: the average of the last 3 months, excluding overtime.
- Seasonal employees: "seven days' wages for each season".
- Explanation 3: "in the case of a monthly rated employee, the fifteen days' wages shall be calculated by dividing the monthly rate of wages last drawn by him by twenty-six and multiplying the quotient by fifteen."
- s.53(4): reduced wages after disablement are split before and after the disablement.
- s.53(5): better terms under an award, agreement or contract are preserved.
- s.53(6): forfeiture, to the extent of the loss caused (damage to property), and wholly or partly (riotous or disorderly conduct or violence; an offence of moral turpitude in the course of employment).
- **Wage base: SS Code s.2(88).** It is identical in substance to Code on Wages s.2(y), including the 50% add-back proviso (verified text).
  - MoLE Additional FAQ Q13: "Any payment made to employee which is not part of components mentioned under section 2(88) … shall not be considered for calculation of gratuity."
  - Q6 and Q11: the revised wage definition applies to gratuity "w.e.f. 21.11.2025".
  - Q17: for exits on or after 21 Nov 2025, gratuity is paid on last-drawn wages "as per the provisions of Code on Social Security, 2020", with no split for service before 21 Nov 2025.
  - These answers are OFFICIAL_SOURCE_PARTIALLY_VERIFIED, being non-binding FAQs.

### B.5 Process

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.**

| Step | Rule | Requirement |
|---|---|---|
| Nomination | s.55, r.32 | Form III in duplicate. An employee already employed a year or more when the rules commenced: within 90 days of commencement. Otherwise within 90 days of completing one year. Late nominations remain valid. The employer verifies and returns an attested duplicate within 30 days. The nominee's Aadhaar is required. A fresh nomination is due within 90 days of acquiring a family. |
| Application | r.33(1) | Form IV within 30 days of gratuity becoming payable; it may be filed in advance where the retirement date is known. A nominee also has 30 days; a legal heir has 1 year. Late claims are allowed for sufficient cause. |
| Employer's notice | r.33(2)(a) | Form V within 15 days of the application. If admissible, it fixes a payment date "not being later than the thirtieth day after the date of receipt of the application". If not, it gives reasons, copied to the competent authority. |
| Payment | s.56(3), r.33(3) | Within 30 days from the date gratuity becomes payable; s.56(2) requires the employer to determine the amount and give notice even if no application is made. Paid by demand draft or bank credit. |
| Interest on delay | s.56(4) | Simple interest "not exceeding the rate notified by the Central Government from time to time for repayment of long term deposits". **The rate is NOT CONFIRMED.** |

### B.6 Applicability

See C.

---

## C. SS Code First Schedule thresholds

**OFFICIAL_PRIMARY_SOURCE_VERIFIED** (India Code text; the MoLE Handbook annex reproduces it identically).

| Chapter | Applicability (column 3, verbatim in substance) |
|---|---|
| III, EPF | "Every establishment in which twenty or more employees are employed." |
| IV, ESIC | "Every establishment in which ten or more persons are employed other than a seasonal factory". Also an establishment with even one employee in a notified hazardous or life-threatening occupation, and plantations by option. Contributions are payable from the date the Corporation provides benefits, as notified. |
| V, Gratuity | "(a) every factory, mine, oilfield, plantation, port and railway company; and (b) every shop or establishment in which ten or more employees are employed, or were employed, on any day of the preceding twelve months; and such shops or establishments as may be notified by the appropriate Government" |
| VI, Maternity Benefit | "(a) to every establishment being a factory, mine or plantation including any such establishment belonging to Government; and (b) to every shop or establishment in which ten or more employees are employed, or were employed, on any day of the preceding twelve months; and such other shops or establishments notified by the appropriate Government." |

- s.1(4) gives the Schedule effect.
- s.1(8): "an establishment to which any Chapter applies at the first instance shall continue to be applied thereafter even if the number of employees therein at any subsequent time falls below the threshold".
- s.1(5) and (7) allow voluntary coverage by agreement of the employer and a majority of employees.

**Related: which old Acts are actually repealed.** OFFICIAL_PRIMARY_SOURCE_VERIFIED from the India Code footnote; consequence LEGAL_REVIEW_REQUIRED.
- S.O. 5319(E) brought into force "Items 1 and 2 and items 4 to 9 of sub-section (1) of section 164". So item 3, the **EPF & MP Act 1952, is not yet repealed**.
- Clause (b) of s.164(2), which continues the EPF and ESI schemes, is also not in force.
- The ESI Act, Maternity Benefit Act and Payment of Gratuity Act are repealed.
- Effect: EPF coverage at 20+ persons, the same number as the First Schedule, still rests on the 1952 Act alongside Chapter III. Which provision governs is a legal-review item.
- ESI wage ceiling: MoLE Additional FAQ Q12 says "At present, Rs 21,000 per month wages notified for ESI coverage will be applicable." (OFFICIAL_SOURCE_PARTIALLY_VERIFIED)

---

## D. "Appropriate Government": when central rules apply to a private company

**Definitions: OFFICIAL_PRIMARY_SOURCE_VERIFIED.**

**Code on Wages, s.2(d) (India Code):**
> "(i) in relation to, an establishment carried on by or under the authority of the Central Government or the establishment of railways, mines, oil field, major ports, air transport service, telecommunication, banking and insurance company or a corporation or other authority established by a Central Act or a central public sector undertaking or subsidiary companies set up by central public sector undertakings or autonomous bodies owned or controlled by the Central Government, including establishment of contractors for the purposes of such establishment, corporation or other authority, central public sector undertakings, subsidiary companies or autonomous bodies, as the case may be, the Central Government; (ii) in relation to any other establishment, the State Government;"

**OSH Code, s.2(1)(d) (signed Gazette copy):**
> "(i) in relation to, establishments [other than those specified in sub-clause (ii)] carried on by or under the authority of the Central Government or concerning any such controlled industry as may be specified in this behalf by the Central Government or the establishment of railways including metro railways, mines, oil field, major ports, air transport service or telecommunication service, banking company or any insurance company (by whatever name called) established by a Central Act or a corporation or other authority established by a Central Act or a Central public sector undertaking or subsidiary companies set up by the Central public sector undertakings or autonomous bodies owned or controlled by the Central Government, including establishment of contractors for the purposes of such establishment … the Central Government: [CPSU <50% proviso]; and (ii) in relation to a factory, motor transport undertaking, plantation, newspaper establishment and establishment relating to beedi and cigar including the establishments not specified in clause (i), the concerned State Government where it is situated. Explanation.—… State Government shall be the appropriate Government in respect of occupational safety, health and working conditions in a factory situated in that State;"

**SS Code, s.2(3) (India Code):** the Central-government limb is the same list, plus "metro railways", "subsidiary companies set up by the principal undertakings", "any company in which not less than fifty-one per cent. of the paid up share capital is held by the Central Government", and:
> "… **or in relation to an establishment having departments or branches in more than one State**, as the case may be, the Central Government; and (b) in relation to any other establishment, the State Government."

**Meaning for private shops, offices and factories:**

| Code | A private company is central-sphere when… | Otherwise |
|---|---|---|
| Wages (minimum wages, payment, deductions, **bonus**) | It is in railways, mines, oil fields, major ports, air transport, telecommunication, or is a banking or insurance company; or it is a contractor working for a central establishment, CPSU, CPSU subsidiary or central autonomous body (for that establishment). This is OFFICIAL_PRIMARY_SOURCE_VERIFIED. There is **no multi-state-branch limb**, so a multi-state IT company is State-sphere for wages and bonus. | State-sphere: ordinary shops, offices, IT/ITES firms and factories. |
| OSH | It is in a central sector as above. **A factory is always State-sphere** (Explanation). | State-sphere, including the residual category. |
| SS (gratuity, maternity, Chapter XIII, Form XXIII) | It is in a central sector, **or it has departments or branches in more than one State**. Many mid-size private companies therefore have the Central Government as appropriate Government for SS Code matters, making the SS (Central) Rules 2026 rr.31–33 and 53 the operative rules for them. | State-sphere. |

Notes on the SS row:
- The text of the multi-state limb is OFFICIAL_PRIMARY_SOURCE_VERIFIED. Its application is LEGAL_REVIEW_REQUIRED: is "establishment" each branch or the enterprise, and how does it interact with Chapter XIII, where r.56(6) leaves the EIR manner to State notification?
- Payment of Gratuity Act s.2(a) carried a similar multi-state limb; that point is from prior knowledge, not re-verified.

**Which rules apply:** MoLE Additional FAQ Q27 (OFFICIAL_SOURCE_PARTIALLY_VERIFIED): "Central rules will be applicable on the establishments where Central Government is Appropriate Government and state rules will be applicable on the establishments where State Government is Appropriate Government." The MoLE Handbook says it "primarily covers provisions of the Codes for the establishments for which the appropriate government is Central Government".

**Product rule:** store the appropriate Government **per Code** for each establishment (Wages, OSH, SS and IR can differ), not one flag per company.

---

## E. ESIC and EPFO

### E.1 ESIC half-yearly "Return of Contribution"

**CONFLICTING_SOURCES. NOT CONFIRMED.**
- The ESIC FAQ (esic.gov.in, 2018), Q26 "What are the returns/ reports to be submitted by the employer?", lists only: "1 Accident Report … Form-12 … on-line within 24 hours", "2 Abstention verification Report … as and when it is sought", and "3. Records including attendance, wages and books of accounts". **It does not list a half-yearly return.** (OFFICIAL_PRIMARY_SOURCE_VERIFIED for what the FAQ says.)
- ESI (General) Regulations reg. 26, as reproduced on secondary sites, still prescribes a return of contributions within 42 days of the end of each contribution period (Apr–Sep, Oct–Mar).
- Vendor guides disagree: some say the return is portal-generated from monthly filings, others that a separate filing is still required (SECONDARY_SOURCE_ONLY).
- The SS (Central) Rules 2026 contain no return-of-contribution rule. They define "contribution period" as "not exceeding six consecutive months, as specified in the regulations".
- ESI regulations continue under SS Code s.164(2)(a).
- No ESIC circular dispensing with the return was found.
- **Product recommendation:** generate a half-yearly contribution summary from the monthly data; it is cheap and harmless either way.

### E.2 EPFO EDLI administrative charge (A/c 22): nil

**OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (search extracts from the EPFO domain; direct fetch failed).
- The EPFO circular of 22.03.2017 (WSU_F&A_ADmCharges_33959.pdf) refers to "non-levy of administrative charges towards EDLI Scheme, 1976".
- The EPFO "Present Rates of Contribution" sheet shows a zero entry for A/c 22. Exempted EDLI establishments pay inspection charges of 0.005% (minimum Re 1) instead.
- Effective date 01.04.2017 (secondary sources agree).
- The EDLI contribution (A/c 21) remains 0.5% of pay (EPFO 2020 FAQ extract).

### E.3 EPF administrative charge (A/c 2): 0.50%, minimum ₹500 (₹75 with no contributing member)

**OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (EPFO-domain extracts).
- "01.06.2018 onwards 0.50% On total pay on which contributions are payable. Minimum Administrative charges payable per month per establishment is Rs. 500/-"
- "if the establishment has no contributory member in the month, the minimum administrative charge will be Rs 75/-"
- The amount is rounded to the nearest rupee.
- Whether these figures changed with the EPF wage ceiling revision to ₹25,000 from 17 Sep 2026 is **NOT CONFIRMED** (no source mentions a change).

---

## F. Unified annual return and Employment Information Return

### F.1 Unified annual return: Form XVII (OSH) and Form XXIII (SS)

**OFFICIAL_PRIMARY_SOURCE_VERIFIED** (signed OSH rules; labour.gov.in SS rules).

**OSH (Central) Rules r.74:**
> "Every employer of an establishment shall send an annual return relating to such establishment in FORM-XVII related to category of employees, health and welfare facilities, retrenchment or layoffs, bonus, maternity benefits etc. to the Inspector-cum-Facilitator having jurisdiction so as to reach on or before the last day of February following the end of each Calendar year electronically."

**OSH r.72(5):** "…on or before the 28th or 29th day of February following the end of each Calendar year, upload a return in FORM-XVII and XVIII on the designated portal".

**OSH r.98(9):** the principal employer files "annual return in FORM-XVII (Part-III)" to the Deputy CLC (Central) by the last day of February "following the end of each Calendar year", except for contracts to produce a given result.

**Form XVII header and instructions:**
- "ANNUAL RETURN FOR THE YEAR …….ENDING 31st December"
- "(1) This return shall be filled-up and furnished on or before the 28th or 29th day of February every year."
- Item A.2: "Period of the Return … Period should be calendar year".
- Instruction (5): to be filled by a contractor or manpower supplier engaging more than 50 workers, and by mines with even one worker.
- Section G covers maternity benefit; section H covers bonus (employees covered, total bonus paid, date paid).

**SS (Central) Rules r.53(5)(a):**
> "The employer to which the provisions of Chapter V and Chapter VI of the Code apply, on or before the 28th or 29th day of February in each year, upload a unified annual return in Form-XXIII online on the web portal of the Central Government in the Ministry of Labour and Employment, giving information as to the particulars specified, in respect of the preceding year".

- r.53(5)(b): a further return within 1 month of sale or abandonment, or 4 months of discontinuance.
- Form XXIII is the same "Single annual integrated return to be filed online under" all four Codes, and also says "ENDING 31st December" and "Period should be calendar year".

**Due date and period:** calendar year January–December, due by the last day of February (28 or 29) of the following year. For calendar year 2026 that is **28 Feb 2027** (computed).

**Internal inconsistency (LEGAL_REVIEW_REQUIRED, low impact):** SS Rules r.2(1)(zh) defines "'year' means the financial year, beginning from the 1st day of April", yet Form XXIII and r.53(5) point to the calendar year. Follow the form: calendar year. Whether CY 2026 is the first filing under the new forms, given the rules started on 8 May 2026, is **NOT CONFIRMED**.

### F.2 Employment Information Return: Form XXVI (SS (Central) Rules r.56(6))

**OFFICIAL_PRIMARY_SOURCE_VERIFIED.**

> "(6) An employer shall furnish to the concerned Career Centre (Regional) yearly returns in Form-XXVI (Employment Information Return) and yearly returns shall be furnished manually or, electronically, or digitally, as the case may be, as specified by the respective State Government by notification, within thirty days of the due date, namely, 31st March of the year."

- **Due 30 April**, covering the year to 31 March: headcount on 31 March, recruits during the year, occupational details on 31 March, unfilled vacancies, and expected vacancies (r.56(5)).
- **LEGAL_REVIEW_REQUIRED, private-sector reach:**
  - r.56 Explanation (B) defines a private-sector establishment as one "with 20 or more employees or such number … as may be notified".
  - Private-sector vacancy reporting (r.56(1)(b)) and record-keeping (r.56(5)(b)) apply only "from such date as may be specified in the notification" by the appropriate Government.
  - r.56(6) itself is unconditional ("An employer shall furnish…") but leaves the manner to State notification.
  - Treat the EIR as applicable to private establishments with 20+ employees, pending each State's notification.
- Related deadlines (r.56(4)):
  - vacancies to the Career Centre (Regional): at least 15 days before the last date for applications;
  - vacancies to the Career Centre (Central): at least 40 days before;
  - results of selection: within 30 days of selection.

---

## Machine-usable parameter blocks

```yaml
bonus:
  law: "Code on Wages, 2019 (29 of 2019), Chapter IV ss.26-41; Wages (Central) Rules, 2026 (G.S.R. 343(E), 8 May 2026) rr.21-28"
  effective_from: "2025-11-21"          # Chapter IV + s.69 repeal of PoB Act: S.O. 5322(E) 21-11-2025; S.O.s 4710/4711 deemed from this date
  accounting_year: { start_month_day: "04-01", end_month_day: "03-31" }   # s.2(a)
  wage_definition: "Code on Wages s.2(y) incl. first proviso 50% add-back"  # LEGAL_REVIEW_REQUIRED on unlisted allowances
  central_sphere:                       # appropriate Government = Central Govt under s.2(d)(i)
    eligibility_ceiling: 21000          # INR/month, wages "not exceeding" -> eligible if wage <= 21000; S.O. 4711(E) 25-08-2026 (OFFICIAL_SOURCE_PARTIALLY_VERIFIED: mirror of Gazette CG-DL-E-25082026-275747)
    calculation_ceiling: 7000           # INR/month floor of the calculation wage; S.O. 4710(E) 25-08-2026 (OFFICIAL_PRIMARY_SOURCE_VERIFIED: signed Gazette CG-DL-E-25082026-275741)
    calculation_ceiling_rule: "calc_wage_m = wage_m <= 7000 ? wage_m : max(7000, central_minimum_wage_monthly(category, area))"
    minimum_wage_source: "minimum wage fixed by the Central Government (S.O. 4710(E)); CLC central-sphere rates incl. VDA; monthly conversion NOT CONFIRMED (x26 customary)"
  state_sphere:                         # appropriate Government = State Govt under s.2(d)(ii)
    status: LEGAL_REVIEW_REQUIRED
    eligibility_ceiling: 21000          # proposed default: PoB Act s.2(13) amount saved by CoW s.69(2) unless State notifies; NOT CONFIRMED
    calculation_ceiling: 7000
    calculation_ceiling_rule: "max(7000, applicable State minimum wage for employee's category/area)"   # PoB s.12 analogue saved by s.69(2); NOT CONFIRMED
    state_notifications_found: []
  minimum_rate: "1/12"                  # 8 1/3 % exactly, s.26(1)
  minimum_amount: 100                   # INR per accounting year, s.26(1); pro-rated by s.27
  minimum_amount_proration: "100 * days_worked_incl_s28 / total_working_days"   # interpretation of s.27
  maximum_rate: 0.20                    # s.26(3), s.26(5)
  minimum_days: 30                      # days of work in the accounting year, s.26(1)
  deemed_worked_days_s28: [lay_off, leave_with_wages, temporary_disablement_employment_accident, maternity_leave_with_wages]   # expressly for s.27; use for s.26(1) = LEGAL_REVIEW_REQUIRED
  payment_within_months: 8              # from close of accounting year, s.39(1); bank credit mandatory
  payment_due_date_april_march_year: "11-30"
  payment_extension_max_total_years: 2  # s.39(1) proviso
  dispute_payment_within_months_of_award: 1   # s.39(2)
  applicability_min_persons: 20         # "twenty or more persons are employed or were employed on any day during an accounting year", s.41(2)
  applicability_note: "No 'every factory' limb and no power to extend to 10-19 persons (both existed in PoB Act s.1(3))"
  exclusions_s41_1: [LIC, seamen, registered_dock_workers, govt_department_or_local_authority_establishments, red_cross_like, universities_educational_institutions, non_profit_hospitals_chambers_social_welfare, RBI, notified_public_sector_FIs, inland_water_transport_foreign_routes, notified_profit_sharing_exempt]
  public_sector_s40: "excluded unless competing with private sector and that income >= 20% of gross income"
  new_establishment_s26_6: "first 5 accounting years after first sale/service: bonus only in profit years, no set-on/set-off; years 6-7 modified set-on/off (rr.22-23, App. A); normal from year 8; applies to new departments/branches (s.26(9))"
  allocable_surplus_pct: { banking_company: 0.60, other: 0.67 }   # s.31(1)
  set_on_set_off_years: 4               # s.36; r.27-28; App. A
  disqualification_s29: [fraud, riotous_or_violent_behaviour_on_premises, theft_misappropriation_sabotage, conviction_for_sexual_harassment]   # on dismissal
  deductions: { s37: "puja/customary bonus and interim bonus paid in the year", s38: "financial loss from misconduct, that accounting year only" }
  contract_labour_r21: "principal pays minimum bonus if contractor fails, on written information from employees/registered union"
  fy_2025_26_transition:
    status: LEGAL_REVIEW_REQUIRED
    facts: "PoB Act repealed 2025-11-21 (s.69(1)); GCA s.6 applies (s.69(3)); S.O.s deemed from 2025-11-21; payment due 2026-11-30 under either law"
    recommended_engine_behaviour: "segment the year at 2025-11-20/21; per-segment regime (PoB: salary_or_wage=basic+DA, calc ceiling max(7000, appropriate-Govt scheduled-employment MW); Code: s.2(y) wages, ceilings as above); default chosen by legal sign-off"
  reporting: "Unified annual return Form XVII/XXIII Part I section H (employees covered, total bonus paid, date paid)"
  legal_references:
    - "Code on Wages 2019 ss.2(a), 2(d), 2(s), 2(y), 26-41, 69 (India Code, as on 21-11-2025)"
    - "S.O. 4711(E) dated 25-08-2026, Gazette Extraordinary Pt II s.3(ii) No. 4526, CG-DL-E-25082026-275747"
    - "S.O. 4710(E) dated 25-08-2026, Gazette Extraordinary Pt II s.3(ii) No. 4525, CG-DL-E-25082026-275741"
    - "S.O. 5322(E) dated 21-11-2025 (commencement)"
    - "Wages (Central) Rules 2026, G.S.R. 343(E) dated 08-05-2026, Chapter V rr.21-28, Appendices A-D; supersedes Payment of Bonus Rules 1975"

gratuity:
  law: "Code on Social Security, 2020 (36 of 2020), Chapter V ss.53-58; Social Security (Central) Rules, 2026 (G.S.R. 344(E), 8 May 2026) rr.31-33"
  effective_from: "2025-11-21"          # S.O. 5319(E); MoLE FAQ: revised wage definition applies to gratuity w.e.f. 21.11.2025
  ceiling: 2000000                      # INR; s.53(3) "as may be notified by the Central Government"; OFFICIAL_SOURCE_PARTIALLY_VERIFIED (2018 PG Act notification saved by s.164(2)(a); MoLE FAQ 30-12-2025 "currently ₹ 20 lakhs"); no s.53(3) notification found
  ceiling_notified_by: "Central Government (all establishments)"
  qualifying_service_years: 5           # continuous service, s.53(1)
  qualifying_service_years_working_journalist: 3
  five_year_waived_for: [death, disablement, expiry_of_fixed_term_employment, notified_event]   # s.53(1) 2nd proviso
  fixed_term:
    min_service_years: 1                # r.33(1)(a) 2nd proviso; MoLE Addl FAQ Q14/Q19 "one year (from start of contract)"
    basis: "pro rata (s.53(2) 3rd proviso; s.2(34)(b))"
    rounding: "remaining period > 6 months and < 1 year rounds up to one additional year (r.33)"   # 'exactly 6 months' LEGAL_REVIEW_REQUIRED
    early_exit_before_contract_end: LEGAL_REVIEW_REQUIRED
    excludes: "contract labour engaged through contractors (MoLE Addl FAQ Q10)"
  days_per_year: 15                     # or as notified by Central Govt, s.53(2)
  seasonal_days_per_season: 7
  monthly_divisor: 26                   # s.53 Explanation 3
  formula_monthly_rated: "last_drawn_monthly_wage / 26 * 15 * qualifying_years"
  year_rounding: "completed years + 1 if the remaining part year is in excess of six months"   # s.53(2)
  piece_rated_wage: "average daily wage over 3 months preceding termination, excluding overtime"
  wage_definition: "SS Code s.2(88) incl. first proviso 50% add-back; rate of wages last drawn"
  pre_code_service: "whole service at last-drawn wage under the Code for exits on/after 2025-11-21 (MoLE Addl FAQ Q17)"
  continuous_service_s54: { deemed_year_days: 240, deemed_year_days_underground_or_lt6day_week: 190, deemed_half_year_days: 120, deemed_half_year_days_underground_or_lt6day_week: 95, seasonal_pct_of_operating_days: 0.75, maternity_leave_counted_max_weeks: 26 }
  payment_within_days: 30               # from date payable, s.56(3)
  employer_notice_form_v_within_days: 15    # of application, r.33(2)(a)
  payment_date_not_later_than_days_after_application: 30   # r.33(2)(a)(i)
  late_interest: "simple interest, rate not exceeding Central Govt notified long-term-deposit rate (s.56(4)); rate NOT CONFIRMED"
  nomination: { form: "III", within_days_of_completing_one_year: 90, employer_return_attested_within_days: 30, fresh_on_acquiring_family_within_days: 90, nominee_aadhaar_required: true }   # s.55, r.32
  application: { form: "IV", employee_within_days: 30, nominee_within_days: 30, legal_heir_within_days: 365 }   # r.33(1)
  payment_mode: [demand_draft, bank_credit]   # r.33(3)
  forfeiture_s53_6: "to extent of damage/loss for wilful act/omission/negligence; wholly or partly for riotous/disorderly conduct/violence or moral-turpitude offence in course of employment"
  applicability: "every factory, mine, oilfield, plantation, port and railway company; every shop/establishment with >=10 employees on any day of the preceding 12 months; notified others (First Schedule, Ch. V); continues if headcount later falls (s.1(8))"
  appropriate_government_note: "Central Govt also for establishments with departments/branches in more than one State (SS Code s.2(3)(a)) -> SS (Central) Rules rr.31-33 govern their process; LEGAL_REVIEW_REQUIRED on scope"
  registers_returns: "no gratuity register or separate return; Payment of Gratuity (Central) Rules 1972 superseded"
  legal_references:
    - "Code on Social Security 2020 ss.1(4), 1(8), 2(3), 2(34), 2(88), 53-56, 164, First Schedule (India Code, as on 21-11-2025)"
    - "S.O. 5319(E) dated 21-11-2025 (commencement)"
    - "Social Security (Central) Rules 2026, G.S.R. 344(E) dated 08-05-2026, rr.31-33, Forms III-IX; CG-DL-E-08052026-272366"
    - "PIB 30-03-2018 (Rs 20 lakh notification under PG Act 1972 as amended by Act 12 of 2018)"
    - "MoLE FAQs 30-12-2025 Q8-Q12; Additional FAQs 16-03-2026 Q6, Q10, Q11, Q13, Q14, Q17, Q19"
```

---

## Summary of labels

| # | Item | Label |
|---|---|---|
| A.1 | Bonus eligibility ₹21,000, S.O. 4711(E), deemed from 21-11-2025 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (Gazette text via mirror) |
| A.2 | Calculation ceiling ₹7,000 or Central minimum wage, S.O. 4710(E) | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| A.2 | Which minimum wage: "fixed by the Central Government" | OFFICIAL_PRIMARY_SOURCE_VERIFIED (text); LEGAL_REVIEW_REQUIRED (category, area, monthly conversion) |
| A.3 | 8⅓% or ₹100; 20% maximum; 30 days; April–March year; s.27, s.29, s.37, s.38, s.41, s.26(6), s.39 | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| A.3 | 50% add-back applies to bonus wages | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (statute plus MoLE FAQ); LEGAL_REVIEW_REQUIRED for unlisted allowances |
| A.3 | Central S.O.s in State-sphere establishments | LEGAL_REVIEW_REQUIRED (text indicates central sphere only; no State notification found) |
| A.3 | FY 2025-26 transition | LEGAL_REVIEW_REQUIRED (due date 30-11-2026 verified) |
| A.4 | s.28 deemed days | OFFICIAL_PRIMARY_SOURCE_VERIFIED; use for the 30-day test is LEGAL_REVIEW_REQUIRED |
| B | Gratuity ceiling ₹20 lakh | OFFICIAL_SOURCE_PARTIALLY_VERIFIED (no s.53(3) notification found) |
| B | 5 years, fixed-term 1 year pro rata, 15/26, s.2(88) wages, rr.32–33 process | OFFICIAL_PRIMARY_SOURCE_VERIFIED (FAQ points partially verified) |
| C | First Schedule thresholds (EPF 20; ESI 10; gratuity 10 or factories etc.; maternity 10 or factories, mines, plantations) | OFFICIAL_PRIMARY_SOURCE_VERIFIED |
| C | EPF Act 1952 not yet repealed (s.164(1) item 3 not in force) | OFFICIAL_PRIMARY_SOURCE_VERIFIED (consequence LEGAL_REVIEW_REQUIRED) |
| D | Appropriate Government definitions, all three Codes | OFFICIAL_PRIMARY_SOURCE_VERIFIED; SS multi-state limb scope LEGAL_REVIEW_REQUIRED |
| E.1 | ESIC half-yearly return | CONFLICTING_SOURCES (NOT CONFIRMED) |
| E.2 | EDLI admin charge (A/c 22) nil from 01-04-2017 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED |
| E.3 | EPF admin charge 0.50%, minimum ₹500 / ₹75 | OFFICIAL_SOURCE_PARTIALLY_VERIFIED |
| F.1 | Unified annual return Form XVII/XXIII: calendar year, due last day of February | OFFICIAL_PRIMARY_SOURCE_VERIFIED (internal "year" definition inconsistency noted) |
| F.2 | EIR Form XXVI: as at 31 March, due within 30 days (30 April) | OFFICIAL_PRIMARY_SOURCE_VERIFIED; private-sector reach LEGAL_REVIEW_REQUIRED |

### Still NOT CONFIRMED

1. An official-domain copy of S.O. 4711(E).
2. 2026 central-sphere minimum wage rates and the monthly conversion.
3. Any State notification under Code on Wages s.26.
4. Any gratuity notification under SS Code s.53(3); the late-payment interest rate under s.56(4).
5. An official ESIC position on the half-yearly return.
6. Whether EPF admin charges changed in 2026.
7. Whether CY 2026 is the first unified-return filing under the new forms.

### Secondary sources used only as leads

- https://pradeepspen.wordpress.com/2026/09/04/perplexity-in-payment-of-bonus-under-wages-code-s-o-4710e-dt-25-08-2026/
- https://labourcodesadvisor.com/articles/bonus-eligibility-21000-calculation-7000-central-minimum-wage/
- https://www.taxheal.com/?p=139259 and https://www.taxheal.com/?p=139255 (Gazette mirrors)
- https://www.staffnews.in/wp-content/uploads/2026/09/Bonus-Ceiling-MoLE-Notification-25-08-2026-275741.pdf
- https://www.incorpx.io/blog/statutory-bonus-code-on-wages-due-date
- https://tallysolutions.com/business-guides/esi-return-filing-due-date-process-online-submission-guide/
- https://fintaxblog.com/pf-admin-charges-reduced-and-edli-admin-charges-waived-by-epfo/
- https://thekanal.in/en-IN/details/gratuity-ceiling-for-bank-employees-labour-ministry-cites-provision-for-enhanced-benefits-72469
