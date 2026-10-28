# Labour Welfare Fund (LWF): primary-source check, 2026-10-26/27

Scope: MH, TN, TG, AP, DL, GJ, WB, KL, MP, PB, CH, OD, CG, GA. Also tests whether 20 other states/UTs really have no employer LWF. Karnataka and Haryana are out of scope (checked separately).

## Method and limits
- **How sources were read:** official pages and PDFs were downloaded and read directly. Scanned gazettes were OCR'd. JavaScript-only board sites (MLWB, AP Labour, MP SKM) were read from their published JS bundles.
- **Where the files are:** local copies are under `scratchpad/dl/<state>/`.
- **Who did what:** WB, MP and CG were checked by one sub-agent, and PB, CH and KL by another. Their full notes are in `scratchpad/lwf-fork-wb-mp-cg.md` and `scratchpad/lwf-fork-pb-ch-kl.md`, and their results are folded in below.
- **Search ran out:** the web-search budget for the session was used up partway through. After that, nothing new could be searched for, and only known official URLs were fetched. Any later amendment that could only be found by searching may have been missed. Each block says where this applies.
- **Absence test:** "absence" findings come from the PRS Legislative Research state-acts index (prsindia.org/acts/states, filtered by title). A control run found the expected LWF Acts for MH, KA, HR, WB, KL, TN, DL and GJ, and MP once Hindi titles were added. PRS is not exhaustive, so absence is never labelled as officially verified.
- **Labels:** each state gets one of OFFICIAL_PRIMARY_SOURCE_VERIFIED, OFFICIAL_SOURCE_PARTIALLY_VERIFIED, SECONDARY_SOURCE_ONLY, CONFLICTING_SOURCES, LEGAL_REVIEW_REQUIRED or UNVERIFIED.

## Comparison with `database/data/compliance/in.php`

| State | Stored (months, ee/er) | Official position (2026-10) | Verdict |
|---|---|---|---|
| MH | [6,12], ₹25 / ₹75, eff. 2015-01-01 | ₹25 / ₹75 per half-year (June and December), set by Mah. Act XXV of 2024 (gazette 18-Mar-2024), applied from June 2024. Before that: ₹6/₹18 (≤₹3,000 wage) or ₹12/₹36. | **Amounts and months MATCH.** `effective_from` 2015-01-01 is **wrong**: the rates before Jun-2024 were different. |
| TG | [12], ₹2 / ₹5, eff. 2015-01-01 | ₹2 / ₹5 per year (Act s.10); employees on the register at 31 Dec, paid by 31 Jan (Rules 1988 r.6). | **MATCH.** No Telangana amendment found, but the search budget ran out before this could be checked fully. |
| DL | [6,12], ₹0.75 / ₹2.25 | ₹0.75 / ₹2.25 per half-year, with a government share of ₹1.50. Register dates 30 Jun and 31 Dec; pay before 15 Jul and 15 Jan (DLWB notification of 13-07-2004). | **MATCH.** |
| TN | [12], ₹20 / ₹40, eff. 2015-01-01 | ₹20 / ₹40 per year, with a government share of ₹20. Set by G.O.(Ms) 161, 2-Dec-2022, in force from 2-Dec-2022. | **Amounts MATCH. `effective_from` is wrong**: from 2015 to Dec-2022 the rates were ₹10/₹20. The **deduction month CONFLICTS**: the official board's copy of Rules r.12 says June wages, paid before 31 July; Form A and secondary sources say year ending December, paid by 31 January. |
| GJ | [6,12], ₹6 / ₹12, eff. 2015-01-01 | ₹6 / ₹12 per half-year (GLWB official Form A-1). The Act says June/December, paid before 15 Jul and 15 Jan. | **MATCH.** `effective_from` is doubtful: a secondary source says the rate went from ₹3/₹6 to ₹6/₹12 from Dec-2016, and the notification was not found. |
| GA | not stored | ₹120 / ₹360 per year, with a government share of ₹240 (Goa Act 9 of 2015). Paid half-yearly as ₹60 / ₹180 (Jan–Jun by 31 Jul, Jul–Dec by 31 Jan). | **Missing.** The ₹10/₹30 on the official page is the **monthly equivalent** (120/12, 360/12), not a per-period amount. |
| AP | not stored | ₹30 / ₹70 per year (AP Labour Welfare Board, official site). Employees on the register at 31 Dec, paid by 31 Jan, Form F. | **Missing.** |
| WB | not stored | ₹3 / ₹30 per half-year from 1-Jan-2024 (Notfn Labr/576641/2024, 19-11-2024). Supervisory ceiling is ₹10,000 from 1-Apr-2024. | **Missing.** |
| KL | not stored | Two funds: the KL Labour Welfare Fund Act 1975 at ₹45 / ₹45 per half-year (Act 27 of 2021), and the Shops & Commercial Establishments Workers Welfare Fund at ₹50 / ₹50 per month. | **Missing; needs legal review** (which fund applies). |
| MP | not stored | ₹10 / ₹50 per half-year from Jan-2026, with an employer minimum of ₹2,500 per half-year if under 50 employees (official SKM portal). | **Missing.** The product model has no field for an employer minimum. |
| PB | not stored | ₹5 / ₹20 **per month** (s.9-A, as amended in 2014). Paid half-yearly (Apr–Sep by 15 Oct, Oct–Mar by 15 Apr). Bill 15-PLA-2025 (₹10/₹40) has passed the Assembly but its gazetting is NOT CONFIRMED. | **Missing; needs legal review.** |
| CH | not stored | ₹5 / ₹20 per month (G.S.R. 353(E), 10-Apr-2018; secondary source only). | **Missing.** |
| OD | not stored | ₹20 / ₹40 per year (Odisha LWF Rules 2015 r.6), paid before 15 Jan and 15 Jul. The split of ₹10 / ₹20 per half-year is an inference. | **Missing.** |
| CG | not stored | ₹15 / ₹45 per half-year (secondary sources only). | **Missing.** |

Cross-cutting point for the product: every Act checked excludes managerial staff, and most also exclude supervisory staff above a wage ceiling. The figures differ by state: MH/GJ ₹3,500, DL ₹2,500, GA/TG/AP ₹1,600, WB ₹10,000, TN ₹15,000. TN, TG, AP, OD and KL also exclude apprentices and part-time staff. All stored rows have `wage_ceiling => null`, and there is no managerial flag.

---

## MH — Maharashtra — OFFICIAL_PRIMARY_SOURCE_VERIFIED
- **Act:** Maharashtra Labour Welfare Fund Act, 1953 (Bom. XL of 1953), s.6BB(2), as substituted by the **Maharashtra Labour Welfare Fund (Amendment) Act, 2024 (Mah. Act No. XXV of 2024)**, first published 18-Mar-2024 (Bill introduced 1-Mar-2024). Gazette text (Maharashtra Govt Gazette, English translation):
  - "(a) in respect of an employee, whose name appears in the register of an establishment on the 30th June and 31st December, respectively, shall be at the rate of twenty-five rupees"
  - "(b) in respect of an employer, for each employee referred to in clause (a), thrice the amount of contribution payable by an employee."
  - Proviso: the State may, on a Board proposal, "increase once in every three years, the rate of employee's contribution ... such increase shall not exceed thirty per cent."
  - Statement of objects and reasons: "no increase in rate of contribution to the Fund since year 2003".
- **Official MLWB site** (mlwb.in, page text read from its JS bundle):
  - "the board receives bilateral contribution from workers, employers and government once every 6 months. This contribution is deducted from the salary of June and December months ... all shops and establishments under 'Bombay Shops and Establishments Act 1948' (where 5 or more workers are employed)"
  - "revised contribution rate is applicable from June 2024: ... Rs. 25/- | Rs. 75/- | Rs. 50/-" (employee | employer | government)
  - Old rates: ₹6/₹18/₹12 for wages ≤₹3,000 and ₹12/₹36/₹24 for wages >₹3,000.
- **Form:** Form A-1 (Rule 3-A), the "statement of employees' and employer's contribution as on 30th June and 31st December". The official PDF on mlwb.in still prints the old two-slab rates.
- **Due date:** 15 July / 15 January. **NOT CONFIRMED from the rules text**: rule 3-A could not be read, and the search budget ran out.
- **Managerial/supervisory exclusion:** supervisory staff drawing more than ₹3,500/month are excluded. This comes from a search-engine summary of the India Code text; the PDF returned 403. Treat it as partially verified.
- **Next possible revision:** the proviso allows at most +30% every three years, so the earliest is around 2027. No increase notification was found (search was limited).

```yaml
state: MH
applies: true
act: "Maharashtra Labour Welfare Fund Act, 1953 s.6BB(2) as substituted by Mah. Act XXV of 2024; Bombay/Maharashtra LWF Rules, 1953"
employee_amount: 25
employer_amount: 75
government_amount: 50
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "excludes managerial; supervisory drawing > ₹3,500/month excluded (India Code text via search summary — partially verified). No wage slab since 2024 (old ₹3,000 slab abolished)."
establishment_threshold: "factories; motor transport; S&E establishments with 5+ workers (MLWB site)"
frequency: half-yearly (register on 30 Jun and 31 Dec)
due_rule: "pay by 15 Jul / 15 Jan (secondary; rule 3-A text NOT CONFIRMED)"
form: "Form A-1 (Rule 3-A)"
effective_from: "2024-06 deduction (MLWB: 'applicable from June 2024'); Act XXV of 2024 published 2024-03-18"
confidence: OFFICIAL_PRIMARY_SOURCE_VERIFIED
stored_value_check: "MATCH (amounts, months); stored effective_from 2015-01-01 is wrong — should be a new version from the June-2024 period"
sources:
  - https://www.ramco.com/hubfs/Maharashtra-labour-welfare-fund-amendment-act-2024.pdf  # gazette copy of Mah. Act XXV of 2024 (third-party host of the gazette PDF)
  - https://hi.prsindia.org/files/bills_acts/bills_states/maharashtra/2024/Bill10of2024MH.pdf  # Bill text, Maharashtra Govt Gazette Extra. Part VIII, 1-Mar-2024
  - https://mlwb.in/  # official board site (contribution page in JS bundle)
  - https://mlwb.in/documents/workers-welfare/form_A1_nidhi_bharana_form.pdf
```

## TN — Tamil Nadu — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
The amounts are verified from the primary source; the deduction month is in conflict.
- **Act:** Tamil Nadu Labour Welfare Fund Act, 1972 (TN Act 36 of 1972), s.15. The caps were raised to ₹50/₹100/₹50 by the TN LWF (Amendment) Act, 2021 (L.A. Bill 28 of 2021). The TN Labour Welfare Fund Rules, 1973 set the actual rates in **r.11-A**.
- **Rate notification:** **G.O.(Ms) No.161, Labour Welfare and Skill Development (G2), 2-Dec-2022; S.R.O. A-26(b)/2022; TN Govt Gazette Extraordinary No.538, Part III s.1(a), 2-Dec-2022.**
  - Text: "in rule 11-A,-- (1) for the expression 'rupees ten' occurring in two places, the expression 'rupees twenty' shall be substituted; and (2) for the expression 'rupees twenty', the expression 'rupees forty'".
  - It is in force from publication, i.e. **2-Dec-2022**.
  - Result: employee ₹20, employer ₹40, government ₹20 per year.
- **Official board page** (lwb.tn.gov.in "Source of Fund", an embedded image read by OCR): "paying Annual Labour Welfare Contribution ... Employees Contribution Rs.20/- Employers Contribution Rs.40/- Government Contribution Rs.20/-".
- **Applicability** (board page image): "All Factories, Plantations, Motor Transport Undertakings, Shops, Commercial & Catering Establishments which employs five or more employees. Workers employed for a period of atleast 30 days in a year excluding apprentice / trainee."
- **Exclusions** (Act s.2(b), official copy): managerial staff; supervisory staff drawing "wages exceeding fifteen thousand rupees per mensem" (TN Act 8 of 2018, w.e.f. 14-Mar-2018); apprentices and part-time staff.
- **Deduction month and due date: CONFLICT.**
  - The board's copy of the Rules, an Indian Kanoon print marked "content could not be verified", has r.12(1): "pay ... for every year before the 31st July of that year ... recover from the employees whose names are borne on the registers ... on the 30th June of the year, the employee's contribution from his wages for the month of June".
  - The official **Form A** (r.12(4)) is headed "FOR THE YEAR ENDING DECEMBER" with "Rs. 20.00 per head / Rs.40.00 per head".
  - Secondary sources (Simpliance and others) say the deduction is at 31-Dec and payment by 31-Jan.
  - An amendment of r.12 was NOT FOUND, and further searching was impossible.
  - The product stores [12]. **Treat as LEGAL_REVIEW for timing.**

```yaml
state: TN
applies: true
act: "Tamil Nadu Labour Welfare Fund Act, 1972 s.15 (caps amended by TN LWF (Amendment) Act, 2021); TN LWF Rules, 1973 r.11-A, r.12"
employee_amount: 20
employer_amount: 40
government_amount: 20
basis: flat
rate: null
cap: null
months_deducted: "CONFLICT: [6] per r.12 text on board site vs [12] per Form A heading/secondary"
wage_ceiling: "excludes managerial; supervisory > ₹15,000/month; apprentices; part-time"
establishment_threshold: "factories, plantations, motor transport undertakings (any); shops/commercial/catering/societies/trusts with 5+ persons"
frequency: annual
due_rule: "CONFLICT: before 31 Jul (r.12 text) vs by 31 Jan (secondary/Form A 'year ending December')"
form: "Form A (r.12(4))"
effective_from: "2022-12-02 (G.O.(Ms) 161 / SRO A-26(b)/2022)"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "amounts MATCH; stored effective_from 2015-01-01 wrong (₹10/₹20 applied until 2-Dec-2022); deduction month unresolved"
sources:
  - https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/GO_161.pdf
  - https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/Gazette_538.pdf
  - https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/FORM%20A.pdf
  - https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/tnlwf_act-1972.pdf
  - https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/Tamil_Nadu_Labour_Welfare_Fund_Rules_1973.PDF
  - https://www.lwb.tn.gov.in/sub_page_content.php?menu_id=NDU1&submenu_id=ODUz&dept_id=205&lang=MQ==
  - https://www.lwb.tn.gov.in/sub_page_content.php?menu_id=NDU1&submenu_id=ODQ5&dept_id=205&lang=MQ==
  - https://prsindia.org/files/bills_acts/bills_states/tamil-nadu/2021/BILL%20No.%2028%20of%202021%20TN.pdf
```

## TG — Telangana — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
- **Act:** Andhra Pradesh Labour Welfare Fund Act, 1987 (Act 34 of 1987), as adapted for Telangana by **G.O.Ms.No.6, LET&F (Lab), 01-02-2016** (Telangana Gazette Extra. 06-02-2016, deemed from 02-06-2014). The adaptation also carries over the AP LWF Rules, 1988.
- **Contribution:** the official Act text on labour.telangana.gov.in, s.10(1), reads: "Every employee shall contribute two rupees per year to the Fund and every employer shall, in respect of each such employee, contribute five rupees per year to the Fund."
- **AP's later increase does not apply:** AP's 2015 increase came after bifurcation, and G.O.6 adapts the Act as it stood on 1-6-2014.
- **Coverage** (s.2):
  - Establishments: factories and motor transport undertakings with no headcount test; S&E establishments, societies and trusts "which employs ... twenty or more persons".
  - Employees: managerial staff, supervisory staff drawing more than ₹1,600/month, apprentices and part-time staff are excluded.
- **Due date and form:** AP LWF Rules 1988 r.6, as carried over to Telangana by G.O.6. The text was read from the copy on the AP official site: "employer's contribution and also the employees' contribution, whose names stand on the register of establishment preceding the 31st December, by the 31st January succeeding year, along with a statement showing full particulars in Form-F."
- **Amendments:** the Telangana Labour Dept G.O. list shows no LWF rate G.O. A Telangana amendment Act could not be searched for once the budget ran out.

```yaml
state: TG
applies: true
act: "AP Labour Welfare Fund Act, 1987 (Act 34 of 1987) as adapted to Telangana (G.O.Ms.No.6 dt 01-02-2016); AP LWF Rules, 1988 as adapted"
employee_amount: 2
employer_amount: 5
government_amount: null
basis: flat
rate: null
cap: null
months_deducted: [12]
wage_ceiling: "excludes managerial; supervisory > ₹1,600/month; apprentices; part-time"
establishment_threshold: "factories & motor transport undertakings (any); S&E establishments/societies/trusts with 20+ persons"
frequency: annual
due_rule: "employees on register at 31 Dec; pay by 31 Jan of succeeding year (r.6)"
form: "Form F"
effective_from: "1987 Act rate (unchanged); TG adaptation deemed 2014-06-02"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "MATCH"
sources:
  - https://labour.telangana.gov.in/content/ActsRules/TELANGANA%20LABOUR%20WELFARE%20FUND%20ACT,%201987.htm
  - https://labour.telangana.gov.in/content/gos/Goms.no.6date.01.02.2016.PDF
  - https://api.apfd.apcfss.in/socialwelfaredms/user-defined-path/file-download/labour/1755842059305_THEANDHRAPRADESHLABOURWELFAREFUNDRULES1988.pdf
```

## AP — Andhra Pradesh — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
- **Act:** AP Labour Welfare Fund Act, 1987 (Act 34 of 1987). Amended by:
  - AP LWF (Amendment) Act, 2015 (Act No.7 of 2015; AP Gazette Part IV-B Extra. No.7, 9-Apr-2015; in force **23-Apr-2015** by G.O.Ms.No.7 LET&F (Lab.I), 22-04-2015);
  - Act 3 of 2022 (adds a Vice-Chairman);
  - Act 27 of 2022 (penalties; deemed from 9-Sep-2022).
- **Rules:** AP LWF Rules, 1988 (G.O.Ms.No.131, 1988), amended by G.O.Ms.No.21 of 7-Jun-2022 and G.O.Ms.No.3 of 4-May-2023 (appeals and penalties).
- **Amount:** the official AP Labour site (labour.ap.gov.in, JS bundle) says: "The collection of contributions (Rs.30/- + Rs.70/-) from the workers and Managements of various establishments like Shops & Establishments, Factories, Motor Transport Undertakings, and Societies & Trusts is being done through A.P. Labour Welfare Board."
  - The text of the amending Act that raised s.10 from ₹2/₹5 to ₹30/₹70 was **NOT read**. It is probably Act 7 of 2015, but that is NOT CONFIRMED. PRS appends only the 2022 Acts, and the official PDFs of the Act still show the 1987 text.
- **Due date and form** (Rules r.6, official copy): employees on the register preceding 31 December, paid "by the 31st January succeeding year", with Form F.
- **Coverage:** the 1987 text says 20+ persons for S&E establishments, societies and trusts, and has a ₹1,600 supervisory ceiling. Whether the 2015 amendment changed the threshold or the ceiling is NOT CONFIRMED. Secondary sources say 1+ employees.

```yaml
state: AP
applies: true
act: "AP Labour Welfare Fund Act, 1987 (Act 34 of 1987) as amended (Act 7 of 2015; Acts 3 & 27 of 2022); AP LWF Rules, 1988"
employee_amount: 30
employer_amount: 70
government_amount: NOT CONFIRMED
basis: flat
rate: null
cap: null
months_deducted: [12]
wage_ceiling: "1987 text: excludes managerial; supervisory > ₹1,600; apprentices; part-time — post-2015 status NOT CONFIRMED"
establishment_threshold: "1987 text: factories & MTUs any; S&E/societies/trusts 20+ — post-2015 status NOT CONFIRMED (secondary: 1+)"
frequency: annual
due_rule: "register preceding 31 Dec; pay by 31 Jan of succeeding year (r.6)"
form: "Form F"
effective_from: "NOT CONFIRMED (probably 2015-04-23, AP Act 7 of 2015 commencement)"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "not stored"
sources:
  - https://labour.ap.gov.in/  # 'Functions of the AP Labour Welfare Board' (Rs.30 + Rs.70)
  - https://api.apfd.apcfss.in/socialwelfaredms/user-defined-path/file-download/labour/1755842263225_GONO722.04.2015labourwelfarefuntact2015.pdf
  - https://api.apfd.apcfss.in/socialwelfaredms/user-defined-path/file-download/labour/1755842059305_THEANDHRAPRADESHLABOURWELFAREFUNDRULES1988.pdf
  - https://api.apfd.apcfss.in/socialwelfaredms/user-defined-path/file-download/labour/1755844468408_AmendmenttoLabourWelfarefundAct.pdf
  - https://prsindia.org/files/bills_acts/acts_states/andhra-pradesh/1987/1987AP34.pdf
```

## DL — Delhi — OFFICIAL_PRIMARY_SOURCE_VERIFIED
- **Act:** Bombay Labour Welfare Fund Act, 1953 as extended to NCT Delhi, plus the Delhi Labour Welfare Fund Rules, 1997. The Fund was constituted by Notification No. F.9(1)/89/DLC(W)/100, 13-07-2004.
- **Official Delhi Labour Welfare Board page:**
  - "Rs. 0.75 paise every six months per employee as stands on the register of establishment as on 30th June and 31st December every year respectively, as share of employee; Rs. 2.25 every six months per employee, as share of employer before the 15th day of July and 15th day of January every year; and Rs. 1.50 every six months per employee, as share of Govt. of Delhi"
  - "restricted to establishments employing five or more persons, as per section 2(4)"
  - "All the employees except those working in the managerial or supervisory capacity and drawing wages exceeding Rs.2,500 ... per month"
- **Form:** NOT CONFIRMED officially (secondary sources say Form A).
- **Later revisions:** none are listed on the board site, whose notices and latest-update pages are empty. Maharashtra's 2024 amendment does not extend to Delhi.

```yaml
state: DL
applies: true
act: "Bombay Labour Welfare Fund Act, 1953 as extended to NCT of Delhi; Delhi Labour Welfare Fund Rules, 1997"
employee_amount: 0.75
employer_amount: 2.25
government_amount: 1.50
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "excludes managerial/supervisory drawing > ₹2,500/month"
establishment_threshold: "5+ persons (s.2(4))"
frequency: half-yearly
due_rule: "before 15 Jul and 15 Jan"
form: "NOT CONFIRMED (Form A per secondary)"
effective_from: "2004-07-13 (Fund constitution notification)"
confidence: OFFICIAL_PRIMARY_SOURCE_VERIFIED
stored_value_check: "MATCH"
sources:
  - https://dlwb.delhi.gov.in/dlwb/constitution-labour-welfare-fund-national-capital-territory-delhi-notification-ii
```

## GJ — Gujarat — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
- **Act:** Bombay Labour Welfare Fund Act, 1953 as extended to and amended in Gujarat, most recently by Guj. Act 5 of 2005 (w.e.f. 31-12-2004). Rules: Labour Welfare Fund (Gujarat) Rules, 1962. Source: GLWB's own compilation PDF, OCR'd.
- **s.6-B(2):** "payable every six months in respect of every employee whose name stands on the register ... in the month of June, or ... December, at the rate of three rupees ... employer's contribution ... six rupees"
  - Proviso: the State may by notification raise the employee rate "to such amount not exceeding six rupees", with the employer paying twice the employee rate.
- **s.6-B(3):** "Every employer shall pay to the Board both ... before the 15th day of July and 15th day of January, every year."
- **Current amount:** the official GLWB **Form A-1** (Rule 3B), "Employees and Employer Contribution Form for the month ending on 30th June/31st December", says "Employees/Workers Share ... @ Rs. 6.00 per Employee/Worker; Employer/Owner Share ... @ Rs 12.00".
  - The notification that raised the employee rate to the ₹6 statutory maximum was **NOT FOUND**.
  - Simpliance (secondary) says the change was "revised from Dec 2016".
- **Coverage:** establishments under the S&E Act are covered at "ten or more persons", and stop being covered after 3 continuous months below 10.
- **Exclusions:** managerial staff, and supervisory staff above "three thousand and five hundred rupees per mensem" (Guj. Act 5 of 2005).
- **Payment:** online via glwbcrm.gujarat.gov.in.

```yaml
state: GJ
applies: true
act: "Bombay Labour Welfare Fund Act, 1953 (as extended/amended in Gujarat, latest Guj. Act 5 of 2005) s.6-B; Labour Welfare Fund (Gujarat) Rules, 1962"
employee_amount: 6
employer_amount: 12
government_amount: NOT CONFIRMED
basis: flat
rate: null
cap: "statutory max employee ₹6 (proviso to s.6-B(2)); employer = 2× employee"
months_deducted: [6, 12]
wage_ceiling: "excludes managerial; supervisory > ₹3,500/month"
establishment_threshold: "factories; tramway/motor omnibus; S&E establishments with 10+ persons"
frequency: half-yearly
due_rule: "before 15 Jul and 15 Jan (s.6-B(3))"
form: "Form A-1 (Rule 3B)"
effective_from: "NOT CONFIRMED (secondary: Dec-2016; previously ₹3/₹6)"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "MATCH (amounts, months); stored effective_from 2015-01-01 doubtful"
sources:
  - https://glwb.gujarat.gov.in/images/glwb/pdf/labour-welfare-fund-form-a-1.pdf
  - https://glwb.gujarat.gov.in/images/glwb/pdf/Bombay-labour-welfare-fund-act-1953.pdf
  - https://www.simpliance.in/India/LEI/govt_notification/gujarat/amendment-to-gujarat-labour-welfare-fund-34  # secondary
```

## GA — Goa — OFFICIAL_PRIMARY_SOURCE_VERIFIED (conflict resolved)
- **Act:** Goa, Daman and Diu Labour Welfare Fund Act, 1986 (Act 4 of 1987; Official Gazette Series I No.5, 30-Apr-1987), s.14(1). Amended in 1998 (₹12/₹36), 2004 (₹60/₹180/₹120) and **2015**.
- **The 2015 amendment:** **Goa Labour Welfare Fund (Amendment) Act, 2015 (Goa Act 9 of 2015)**, assent 26-8-2015, Official Gazette Series I No.22 (Extraordinary No.2), 28-Aug-2015, "shall come into force at once". s.6 substitutes s.14(1): "Every employee shall contribute rupees one hundred and twenty per year to the Fund and every employer and the Government shall, in respect of each such employee, contribute rupees three hundred and sixty and rupees two hundred and forty, respectively, per year to the Fund."
- **Resolving the conflict:** the official board page (labour.goa.gov.in/labour-welfare-board/) says "The current rate of contribution is Rs.10/-, Rs. 30/- & Rs. 20/- respectively ... half yearly contribution ... January to June by 31st July and ... July to December by 31st January".
  - ₹10/₹30/₹20 is exactly the statutory annual ₹120/₹360/₹240 divided by 12, so it is a **monthly** rate.
  - The board's table showing "10.00 | 30.00 | 40.00" against each half-year is misleading.
  - The per-half-year amount is therefore **₹60 employee / ₹180 employer** (₹120 government), which matches Simpliance.
  - The ₹10/₹30 reading is stale or a misreading. The 11-Jun-2024 "Amendment of GLWB" item was not located among the board's notifications; the list ends at the 2015 Act and the 2010 Rules.
- **Rules 1990 r.4** (Series I No.49, 8-Mar-1990): "Every employer shall submit to the Secretary on or before the 31st day of July and 31st of January a statement of employer's contribution and employees' contribution in respect of employees whose names stand on the establishment register on 30th June and 31st December respectively; in Form A."
- **Coverage** (s.2(c), 1987 text):
  - Employees: excludes staff "employed mainly in a managerial capacity" and supervisory staff drawing "wages exceeding one thousand and six hundred rupees per mensem".
  - Establishments: s.2(e) lists factories, motor transport, S&E Act establishments, contractors, scheduled employments, plantations, ports and others. **No headcount threshold** appears in the Act text.
- **Government share:** under the Board page, it is used only for the Retrenched Workers Assistance Scheme.

```yaml
state: GA
applies: true
act: "Goa, Daman and Diu Labour Welfare Fund Act, 1986 (Act 4 of 1987) s.14(1) as substituted by Goa Act 9 of 2015; Goa LWF Rules, 1990"
employee_amount: 60        # per half-year (₹120/yr)
employer_amount: 180       # per half-year (₹360/yr)
government_amount: 120     # per half-year (₹240/yr)
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "excludes managerial; supervisory > ₹1,600/month"
establishment_threshold: "none in Act (s.2(e) lists factories, S&E establishments, contractors, scheduled employments, plantations, etc.)"
frequency: half-yearly (statutory amount stated per year)
due_rule: "Jan–Jun by 31 Jul; Jul–Dec by 31 Jan (r.4; board page)"
form: "Form A (r.4)"
effective_from: "2015-08-28 (Goa Act 9 of 2015, in force at once)"
confidence: OFFICIAL_PRIMARY_SOURCE_VERIFIED
stored_value_check: "not stored"
note: "Half-year split (₹60/₹180) = annual statutory amount ÷ 2, consistent with board page (₹10/₹30 per month) and Simpliance."
sources:
  - https://labour.goa.gov.in/wp-content/uploads/2020/07/SERIES-I-No.-22.pdf   # Goa Act 9 of 2015
  - https://labour.goa.gov.in/wp-content/uploads/2020/07/SERIES-I-No.-50.pdf   # Goa Act 6 of 2004
  - https://labour.goa.gov.in/wp-content/uploads/2020/07/SERIES-I-No.5.pdf     # 1986 Act
  - https://labour.goa.gov.in/wp-content/uploads/2020/07/SERIES-I-No.-49.pdf   # Rules 1990
  - https://labour.goa.gov.in/labour-welfare-board/
  - https://labour.goa.gov.in/notifications-wb/
```

## WB — West Bengal — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
Full notes are in `lwf-fork-wb-mp-cg.md`.
- **Statute:** WB LWF Act 1974, read from the PRS copy with its amending Acts.
  - s.9(2): rates "as may be notified", payable "in every six months ending on the thirtieth day of June, and the thirty-first day of December".
  - s.9(3): payment "before the 15th day of July and the 15th day of January".
  - s.9(4): deduction only from "the wages for the months of June and December".
  - s.2(5)(iii): commercial establishments are covered at "ten or more persons".
- **WB Act XI of 2023** (in force 1-Apr-2024 by Notfn 15(LC-LW/MW)/2024, 09-02-2024):
  - raises the supervisory ceiling from ₹1,600 to **₹10,000**;
  - removes the rule that released an establishment after 3 months below 10 persons;
  - makes payment through the Board portal mandatory.
- **Rates:** Notfn **Labr/576641/2024/(LC-LW/MW), 19-11-2024** (Kolkata Gazette Extra. 2-12-2024) sets employer ₹30 and employee ₹3, "deemed ... with effect from 1st January, 2024".
  - The official PDF could not be downloaded (certificate failure). Its wording was confirmed through the search index of the official PDF and the Indian Tea Association circular.
- **Not confirmed:** the government share and the form.

```yaml
state: WB
applies: true
act: "West Bengal Labour Welfare Fund Act, 1974 (as amended by WB Act XLVI of 2017 and WB Act XI of 2023); WB LWF Rules, 1976"
employee_amount: 3
employer_amount: 30
government_amount: NOT CONFIRMED
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "excludes managerial; supervisory > ₹10,000/month from 2024-04-01 (was ₹1,600)"
establishment_threshold: "commercial establishments 10+ persons; factories & tramway/MTU any"
frequency: half-yearly
due_rule: "before 15 Jul and 15 Jan; electronic payment via Board portal"
form: NOT CONFIRMED
effective_from: "2024-01-01 (Notfn Labr/576641/2024 dt 19-11-2024, retrospective)"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "not stored"
sources:
  - https://prsindia.org/files/bills_acts/acts_states/west-bengal/1974/ActNo.38of1974WB.pdf
  - https://silpasathi.wb.gov.in/sites/default/files/upload/eodb_doc/UDMA%20decriminalizarion.pdf
  - https://www.labour.wb.gov.in/sites/default/files/upload/notice/Labr-576641%20Dated-19-11-2024.pdf  # not fetchable (TLS); wording via search index
  - https://www.indiatea.org/uploads/circular/1739174532.pdf  # secondary
```

## KL — Kerala — LEGAL_REVIEW_REQUIRED
There are two overlapping statutory funds. The sub-agent covered the Shops fund. The Kerala LWF Act 1975 below was read in this session.

**1. Kerala Labour Welfare Fund Act, 1975 (Act 11 of 1977)**
- **Source:** PRS copy with its amending Acts (1986, 1987, 1994, 2021).
- **s.15(1), as amended by Kerala LWF (Amendment) Act, 2021 (Act 27 of 2021):**
  - Kerala Gazette Extra. Vol. X No.3356, 15-Nov-2021; Notification No.558/Leg.C2/2020/Law; assent 13-Nov-2021.
  - "deemed to have come into force on the 16th day of January, 2020".
  - Text: "for the words 'four rupees', the words 'forty five rupees' ... for the words 'eight rupees', the words 'forty five rupees' shall be substituted". So s.15(1) now reads employee ₹45 and employer ₹45 **per half year**.
- **s.15(2):** "pay ... before the 15th day of July and 15th day of January of every year".
- **Coverage, s.2(f):**
  - factories and motor transport undertakings;
  - plantations (tea, rubber, coffee, cardamom, oil palm, cocoa) with 10+ persons;
  - **commercial establishments or shops under the Kerala S&CE Act 1960 that employ "two or more persons"** (1994 amendment);
  - societies and trusts with "more than twenty persons".
- **Exclusions, s.2(d):** managerial staff, supervisory staff with mainly managerial functions, apprentices and part-time staff. The wage ceiling was **omitted** in 1994.

**2. Kerala Shops & Commercial Establishments Workers Welfare Fund Act, 2006 + Scheme 2007**
- **Current rate:** the official board page (peedika.kerala.gov.in) says ₹50 employee + ₹50 employer **per month**. The statutory figure was ₹20/₹20, and the revision notification is NOT CONFIRMED.
- **Membership:** the fund is membership-based (age 18–55, 3 months' service). It excludes workers covered by any other Kerala welfare fund.
- **IT/ITES scheme 2021** (G.O.(P) 24/2021/LBR): ₹100 + ₹100 per month, due before the 5th of the following month.

**Why legal review is needed:** both the 1975 Act and the 2006 Act reach shops and commercial establishments, and the 2006 Act excludes workers covered by other funds. Which fund an ordinary private office, shop or IT employer pays into cannot be settled from the texts read. The 1975 Rules, forms and any 2020+ notifications were not read.

```yaml
state: KL
applies: true
act: "(a) Kerala Labour Welfare Fund Act, 1975 (Act 11 of 1977) s.15 as amended by Act 27 of 2021; (b) Kerala Shops & Commercial Establishments Workers Welfare Fund Act, 2006 + Scheme 2007; (c) IT/ITES scheme 2021"
employee_amount: "(a) 45 per half-year; (b) 50 per month; (c) 100 per month"
employer_amount: "(a) 45 per half-year; (b) 50 per month; (c) 100 per month"
government_amount: "(a) NOT CONFIRMED; (b) statute: max(₹5, 25% of worker share)/month — current NOT CONFIRMED"
basis: flat
rate: null
cap: null
months_deducted: "(a) NOT CONFIRMED (half-years ending Jun/Dec implied by 15 Jul/15 Jan due dates); (b),(c) monthly"
wage_ceiling: "(a) none (omitted 1994); excludes managerial, apprentices, part-time; (b) none found"
establishment_threshold: "(a) shops/commercial establishments 2+ persons; plantations 10+; societies >20; factories/MTU any; (b) membership-based, no headcount threshold"
frequency: "(a) half-yearly; (b),(c) monthly"
due_rule: "(a) before 15 Jul and 15 Jan; (b) NOT CONFIRMED officially (5th of month per secondary); (c) before 5th of following month"
form: NOT CONFIRMED
effective_from: "(a) 2020-01-16 (deemed, Act 27 of 2021); (b) NOT CONFIRMED; (c) 2021-02-26"
confidence: LEGAL_REVIEW_REQUIRED
stored_value_check: "not stored"
sources:
  - https://prsindia.org/files/bills_acts/acts_states/kerala/1977/1977KERALA11.pdf
  - https://peedika.kerala.gov.in/Details_Payment.php
  - https://peedika.kerala.gov.in/Uploads/WebUpload/Act-31-10-2006.pdf
  - https://peedika.kerala.gov.in/Uploads/WebUpload/IT%20SCHEME.pdf
```

## MP — Madhya Pradesh — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
- **Rates (official MP Shram Kalyan Mandal portal, read by the sub-agent):**
  - Rate master "Jan-26–Present: 10 / 50 / 2500": employee ₹10 and employer ₹50 per half-year, with an employer **minimum of ₹2,500 per half-year for establishments with fewer than 50 employees**.
  - Previous rate "Jan-13–Dec-25: 10 / 30 / 1500".
- **Notification:** LAB-8-0003-2025-Sec-1-XVI(LAB), 28-01-2026, per secondary sources. The gazette was not read.
- **Statute** (MP Shram Kalyan Nidhi Adhiniyam 1982, PRS copy, English text, OCR read in this session):
  - s.9(2): amounts are payable every six months for employees on the register (30 Jun / 31 Dec).
  - s.9(3): pay "every year before the 15th day of July and 15th day of January".
  - Deduction only from "the wages for the month of June and December".
  - The State Government pays "a contribution of an amount equal to the employer's contribution".
  - s.9(9) (ins. 1996): the State may revise rates by notification.
  - The definition of employee excludes "managerial or administrative capacity" staff and supervisory staff drawing more than "one thousand and six hundred rupees". Whether later Hindi-language amendments (11/2013, 20/2014, 9/2023, 3/2026) changed this to ₹10,000, as secondary sources say, is NOT CONFIRMED.

```yaml
state: MP
applies: true
act: "Madhya Pradesh Shram Kalyan Nidhi Adhiniyam, 1982 (Act 36 of 1983) s.9; MP Shram Kalyan Nidhi Rules, 1984"
employee_amount: 10
employer_amount: 50
employer_minimum_per_half_year: 2500   # establishments < 50 employees (portal label; legal wording NOT CONFIRMED)
government_amount: "equal to employer's contribution per s.9 English text (current practice NOT CONFIRMED)"
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "excludes managerial/administrative; supervisory > ₹1,600 in English Act text; ₹10,000 per secondary — NOT CONFIRMED"
establishment_threshold: "NOT CONFIRMED (secondary: 1+)"
frequency: half-yearly
due_rule: "before 15 Jul and 15 Jan (s.9(3))"
form: "NOT CONFIRMED (secondary: Form A); SKM online portal"
effective_from: "2026-01 half-year (portal rate master); notification LAB-8-0003-2025-Sec-1-XVI(LAB) dt 28-01-2026 per secondary"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "not stored"
sources:
  - https://shramkalyanmandal.mp.gov.in/SKM/
  - https://prsindia.org/files/bills_acts/acts_states/madhya-pradesh/1983/1983MP36.pdf
  - https://www.dcpsol.com/madhya-pradesh-revises-labour-welfare-fund-contributions-january-2026-amendment  # secondary
```

## PB — Punjab — LEGAL_REVIEW_REQUIRED
Full notes are in `lwf-fork-pb-ch-kl.md`.
- **s.9-A** (inserted by Punjab Act 18 of 2006): the amounts were raised to ₹5 employee / ₹20 employer **per month** by Bill 1-PLA-2014, enacted as Punjab Act 32 of 2014. The Act number and date come from a secondary source.
- **Who counts:** the "employee" is one named on the register on the last working day of the month.
- **Payment:** "before the fifteenth day of October, for the period ... 1st day of April to the 30th day of September, and before the fifteenth day of April, for the period ... 1st day of October to the 31st day of March".
- **Pending increase:** **Bill 15-PLA-2025**, passed by the Vidhan Sabha in July 2025, raises the rates to ₹10/₹40 per month from the date of gazette publication. Assent and gazetting are NOT CONFIRMED.
- **Threshold conflict:** the official 1965 text covers non-factory establishments with "more than twenty persons"; secondary sources say 10+.
- **No exclusions:** the definition has no wage ceiling and no managerial exclusion.

```yaml
state: PB
applies: true
act: "Punjab Labour Welfare Fund Act, 1965 s.9-A (ins. Punjab Act 18 of 2006; amended Punjab Act 32 of 2014); Punjab LWF Rules, 1966"
employee_amount: 5     # per month; ₹10 if Bill 15-PLA-2025 has been notified (NOT CONFIRMED)
employer_amount: 20    # per month; ₹40 if Bill 15-PLA-2025 has been notified (NOT CONFIRMED)
government_amount: null
basis: flat
rate: null
cap: null
months_deducted: [1,2,3,4,5,6,7,8,9,10,11,12]
wage_ceiling: null
establishment_threshold: "factory/motor omnibus any; others >20 persons (official 1965 text) vs 10+ (secondary) — CONFLICT"
frequency: "deducted monthly, remitted half-yearly"
due_rule: "Apr–Sep by 15 Oct; Oct–Mar by 15 Apr"
form: NOT CONFIRMED (online deposit on pblabour.gov.in)
effective_from: "2014-12-15 (Punjab Act 32 of 2014, secondary)"
confidence: LEGAL_REVIEW_REQUIRED
stored_value_check: "not stored"
sources:
  - https://prsindia.org/files/bills_acts/acts_states/punjab/1965/1965PN17.pdf
  - https://www.punjabassembly.gov.in/images/docs/AssemblyFiles/14/145/20140307/Documents/Bill%20No%201%20-2014%20Introduced%20(1).pdf
  - https://punjabassembly.gov.in/images/docs/AssemblyFiles/16/179/20250710/Documents/15%20PLA%20passed%20Bill%202025.pdf
  - https://prsindia.org/files/bills_acts/bills_states/punjab/2025/Bill15of205PB.pdf
```

## CH — Chandigarh — SECONDARY_SOURCE_ONLY
- **Applicability:** the Punjab LWF Act 1965 applies; the official text refers to the Board "of ... the Union Territory of Chandigarh".
- **Rates:** ₹5/₹20 per month from **G.S.R. 353(E), 10-Apr-2018**, which extended the Punjab 2014 amendment to the UT. This rests on Simpliance only; the gazette was not read.
- **Due dates:** same as Punjab.
- **Punjab 2025 increase:** applies to Chandigarh only if the Centre extends it, and no extension was found.
- **Threshold:** same conflict as Punjab (more than 20 vs 10+).

```yaml
state: CH
applies: true
act: "Punjab Labour Welfare Fund Act, 1965 as applicable to UT Chandigarh; 2014 amendment extended by G.S.R. 353(E) dt 10-Apr-2018 (secondary)"
employee_amount: 5    # per month
employer_amount: 20   # per month
government_amount: null
basis: flat
rate: null
cap: null
months_deducted: [1,2,3,4,5,6,7,8,9,10,11,12]
wage_ceiling: null
establishment_threshold: ">20 persons per 1965 text vs 10+ secondary — NOT CONFIRMED"
frequency: "deducted monthly, remitted half-yearly"
due_rule: "Apr–Sep by 15 Oct; Oct–Mar by 15 Apr"
form: NOT CONFIRMED
effective_from: "2018-04-10 (secondary)"
confidence: SECONDARY_SOURCE_ONLY
stored_value_check: "not stored"
sources:
  - https://www.simpliance.in/India/LEI/govt_notification/punjab/applicability-of-the-punjab-lwf-amendment-act-2014-to-chandigarh-356  # secondary
  - https://pblabour.gov.in/pbLabourStaticSiteAdmin/uploads/5c6efb8f-d1d8-465a-b017-0064a1b1b5ad_the_punjab_labour_welfare_fund_act_1965.pdf
```

## OD — Odisha — OFFICIAL_SOURCE_PARTIALLY_VERIFIED
- **Act:** **Orissa Labour Welfare Fund Act, 1996 (Orissa Act 14 of 2005)**. It received the President's assent 28-Nov-2005 and was published in the Orissa Gazette Extraordinary No.2071. The earlier notes call it the "Odisha LWF Act, 1996"; the Act number is 14 of 2005.
- **Rules:** **Odisha Labour Welfare Fund Rules, 2015**: S.R.O. No.73/2016, Labour & ESI Dept notification 27-Jan-2016, Odisha Gazette Extra. No.224, 28-Jan-2016, in force on publication. Read from the PRS copy of the gazette text.
- **r.6(1):** "Every employee shall contribute twenty rupees per year to the Fund and every employer shall, in respect of each employee, contribute forty rupees per year to the Fund."
- **r.6(2):** pay "before the 15th day of January and the 15th day of July of every year in respect of all employees whose names stand on the register of establishment preceding the 31st December and the 30th June respectively, along with a statement showing full particulars in Form F."
  - The rule does not say how the annual amount is split between the two half-years. **₹10/₹20 per half-year is an inference**, which matches secondary sources.
- **Coverage, s.2:**
  - Establishments: factories and motor transport undertakings with no headcount test; S&CE establishments, societies and trusts employing "twenty or more persons".
  - Exclusions: managerial staff, supervisory staff with managerial functions, apprentices and part-time staff. **No wage ceiling.**
- **Amendments:** none after 2016 were found, but the search budget ran out before this could be checked.

```yaml
state: OD
applies: true
act: "Orissa Labour Welfare Fund Act, 1996 (Orissa Act 14 of 2005); Odisha Labour Welfare Fund Rules, 2015 (SRO 73/2016)"
employee_amount: 10    # per half-year (inferred from ₹20/yr)
employer_amount: 20    # per half-year (inferred from ₹40/yr)
government_amount: NOT CONFIRMED
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "none; excludes managerial, supervisory with managerial functions, apprentices, part-time"
establishment_threshold: "factories & MTUs any; S&CE establishments/societies/trusts 20+ persons"
frequency: half-yearly (annual amount stated)
due_rule: "before 15 Jan (register at 31 Dec) and 15 Jul (register at 30 Jun)"
form: "Form F"
effective_from: "2016-01-28 (Rules 2015 published)"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
stored_value_check: "not stored"
sources:
  - https://prsindia.org/files/bills_acts/acts_states/odisha/2005/Odisha%20Act%2014%20of%202005.pdf
```

## CG — Chhattisgarh — SECONDARY_SOURCE_ONLY
- **Rates:** ₹15 / ₹45 per half-year, deducted at 30 Jun / 31 Dec and due 15 Jul / 15 Jan. The supervisory ceiling is ₹10,000, coverage starts at 1+ employees, and the form is Form A. All of this comes from Simpliance and greytHR.
- **Official site:** cglabour.nic.in is dead (DNS does not resolve), so it could not be checked.
- **Not found:** no notification, and no PRS copy of the CG Act.
- **MP comparison:** MP raised its rates in 2026. Whether CG did the same is NOT CONFIRMED.

```yaml
state: CG
applies: true
act: "Chhattisgarh Shram Kalyan Nidhi Adhiniyam, 1982; CG Shram Kalyan Nidhi Rules, 1984"
employee_amount: 15
employer_amount: 45
government_amount: NOT CONFIRMED
basis: flat
rate: null
cap: null
months_deducted: [6, 12]
wage_ceiling: "excludes managerial/administrative; supervisory > ₹10,000 (secondary)"
establishment_threshold: "1+ (secondary)"
frequency: half-yearly
due_rule: "15 Jul / 15 Jan (secondary)"
form: "Form A (secondary)"
effective_from: NOT CONFIRMED
confidence: SECONDARY_SOURCE_ONLY
stored_value_check: "not stored"
sources:
  - https://www.simpliance.in/labour-welfare-fund-detail/chhattisgarh  # secondary
```

---

## States and UTs expected to have no employer LWF

**Test used:** the PRS state-acts index (https://prsindia.org/acts/states?state=<State>&title=<q>), searched for "Welfare Fund", "Labour Welfare", "Shram Kalyan" and "Kalyan Nidhi". The control run is described under Method. Prior secondary checks (Simpliance "No Records Found") are carried over from `docs/compliance-research/2026-10-26-state-obligations.md`. **The index is not exhaustive. Only a positive find is strong evidence.**

### UP — Uttar Pradesh — OFFICIAL_SOURCE_PARTIALLY_VERIFIED (no periodic payroll contribution found, but an LWF Act does exist)
- **The Act exists:** **Uttar Pradesh Labour Welfare Fund Act, 1965 (U.P. Act XIV of 1965)** ("Authoritative English Text of the Uttar Pradesh Shram Kalyan Nidhi Adhiniyam, 1965"; President's assent 20-Jul-1965; UP Gazette Extra. 26-Jul-1965). The earlier notes said "No UP LWF Act was found", which is **wrong**.
- **2024 amendment:** **U.P. Labour Welfare Fund (Amendment) Act, 2024 (U.P. Act 23 of 2024)**, assent 26-Dec-2024, Notfn No.502(2)/LXXIX-V-1–2024-1-ka-21-2024, in force on publication 26-Dec-2024.
  - In s.2(2), the definition of employee, "semi-skilled" is inserted and "four hundred" becomes "**twenty four thousand**" (the supervisory wage limit).
  - Statement of objects and reasons: "The Uttar Pradesh Labour Welfare Board is not given any financial assistance/grant by the State Government or Central Government ... with the aim of providing benefits to maximum number of workers of the State by registering them in the Uttar Pradesh Labour Welfare Board".
- **No periodic employer/employee contribution was found.** The scanned pages of the PRS copy were read visually.
  - Chapter III is "Unpaid Accumulations and application of Fund". s.6: "all unpaid accumulations shall be paid to the Board"; s.7 covers purposes of the Fund.
  - Chapters IV–V (ss.8–16) cover officers and accounts. s.17: "Any sum payable into the Fund under this Act ... recoverable ... as an arrear of land revenue".
  - s.23 amends Payment of Wages Act s.8(8) so that fines "shall be paid into the Fund".
  - s.25(2), the rule-making list, covers "(a) manner of collection of sums specified in sub-section (2) of section 3" and has **no item on rates of contribution**.
  - **Caveats:** the page holding s.2(3)–s.3(2), which lists what the Fund consists of, is missing from the PRS scan. Amendments between 1965 and 2024, other than Act 23 of 2024, were not checked.
- **What this means for the product:** no half-yearly or annual payroll deduction. Employers do still have duties under the Act: unpaid accumulations and fines must go to the Board. Re-check s.3(2) and the UP LWF Rules before treating UP as "no LWF".

```yaml
state: UP
applies: false   # no periodic employer/employee payroll contribution found; Act exists (unpaid accumulations + fines go to the Fund)
act: "Uttar Pradesh Labour Welfare Fund Act, 1965 (U.P. Act XIV of 1965) as amended by U.P. Act 23 of 2024"
employee_amount: null
employer_amount: null
government_amount: null
basis: null
rate: null
cap: null
months_deducted: []
wage_ceiling: "employee definition covers supervisory staff with wages ≤ ₹24,000/month (from 2024-12-26; was ₹400) — relevant to Board registration/benefits, not to a deduction"
establishment_threshold: NOT CONFIRMED
frequency: null
due_rule: "unpaid accumulations/fines to Board (s.6, s.23); no contribution due date found"
form: NOT CONFIRMED
effective_from: "Act 1965; amendment 2024-12-26"
confidence: OFFICIAL_SOURCE_PARTIALLY_VERIFIED
caveat: "s.3(2) page missing from PRS scan; 1965–2024 amendments other than Act 23 of 2024 not checked"
sources:
  - https://prsindia.org/files/bills_acts/acts_states/uttar-pradesh/1965/1965UP14.pdf
```

### DH — Dadra & Nagar Haveli and Daman & Diu — LEGAL_REVIEW_REQUIRED
- **Possible legacy Act:** the **Goa, Daman and Diu Labour Welfare Fund Act, 1986 (Act 4 of 1987)** was passed for the whole "Union territory of Goa, Daman and Diu" (s.1(2)). It was published 30-Apr-1987, before Goa became a state (30-May-1987), and comes into force "on such date as the Government may, by notification ... appoint".
- **Goa's later amendments do not reach Daman and Diu:** they were made by the Goa legislature and substitute "State of Goa".
- **Open question:** whether the Act was ever brought into force in Daman and Diu, or extended to Dadra & Nagar Haveli, is **NOT CONFIRMED**. The PRS index for DNH&DD lists no LWF Act. If the Act does apply, the original 1987 rates (₹6/₹12 per year) might still apply, which also needs review.

```yaml
state: DH
applies: LEGAL_REVIEW_REQUIRED
act: "possibly Goa, Daman and Diu Labour Welfare Fund Act, 1986 (Act 4 of 1987) as it stood for the UT — commencement in Daman & Diu NOT CONFIRMED"
employee_amount: NOT CONFIRMED
employer_amount: NOT CONFIRMED
government_amount: NOT CONFIRMED
basis: NOT CONFIRMED
rate: null
cap: null
months_deducted: NOT CONFIRMED
wage_ceiling: NOT CONFIRMED
establishment_threshold: NOT CONFIRMED
frequency: NOT CONFIRMED
due_rule: NOT CONFIRMED
form: NOT CONFIRMED
effective_from: NOT CONFIRMED
confidence: LEGAL_REVIEW_REQUIRED
sources:
  - https://labour.goa.gov.in/wp-content/uploads/2020/07/SERIES-I-No.5.pdf
```

### No general LWF Act found — SECONDARY_SOURCE_ONLY (evidence of absence, not proof)
In each of these, the PRS title filters returned no labour welfare fund or Shram Kalyan Nidhi Act. The only welfare-fund Acts listed are sector funds, noted against the state.

| State | PRS result | Sector funds that are not a general LWF | Label |
|---|---|---|---|
| RJ | only "Rajasthan Advocates Welfare Fund Act, 1987" | — | SECONDARY_SOURCE_ONLY |
| BR | only "Bihar Advocates' Welfare Fund Act, 1983" | — | SECONDARY_SOURCE_ONLY |
| JH | only the advocates' and advocates' clerks' welfare fund Acts | — | SECONDARY_SOURCE_ONLY |
| AS | "Assam Advocate Welfare Fund Act, 1998"; "**Assam Tea Plantations Employees Welfare Fund Act, 1960**" | tea plantations only, so it matters only for tea-estate employers | SECONDARY_SOURCE_ONLY |
| HP | only "HP Advocates Welfare Fund Act, 1996" | — | SECONDARY_SOURCE_ONLY |
| UK | only the UP Advocate Welfare Fund (Uttaranchal Amendment) Act 2005. UK inherited UP laws in 2000, so the UP LWF Act 1965 may have carried over (adaptation or repeal NOT CONFIRMED). Even so, that Act levies no periodic contribution (see UP) | — | SECONDARY_SOURCE_ONLY |
| JK | none | — | SECONDARY_SOURCE_ONLY |
| LA | none | — | SECONDARY_SOURCE_ONLY |
| LD | none | — | SECONDARY_SOURCE_ONLY |
| AN | none | — | SECONDARY_SOURCE_ONLY |
| PY | none | — | SECONDARY_SOURCE_ONLY |
| AR | none | — | SECONDARY_SOURCE_ONLY |
| MN | none | — | SECONDARY_SOURCE_ONLY |
| ML | none | — | SECONDARY_SOURCE_ONLY |
| MZ | none | — | SECONDARY_SOURCE_ONLY |
| NL | none | — | SECONDARY_SOURCE_ONLY |
| SK | none | — | SECONDARY_SOURCE_ONLY |
| TR | none | — | SECONDARY_SOURCE_ONLY |

```yaml
- {state: RJ, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Rajasthan&title=Welfare%20Fund"]}
- {state: BR, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Bihar&title=Welfare%20Fund"]}
- {state: JH, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Jharkhand&title=Welfare%20Fund"]}
- {state: AS, applies: false, act: null, note: "sector-only Assam Tea Plantations Employees Welfare Fund Act, 1960", confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Assam&title=Welfare%20Fund"]}
- {state: HP, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Himachal%20Pradesh&title=Welfare%20Fund"]}
- {state: UK, applies: false, act: null, note: "UP LWF Act 1965 possibly inherited in 2000 (NOT CONFIRMED) but it levies no periodic contribution", confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Uttarakhand&title=Welfare%20Fund"]}
- {state: JK, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Jammu%20and%20Kashmir&title=Welfare%20Fund"]}
- {state: LA, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Ladakh&title=Welfare%20Fund"]}
- {state: LD, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Lakshadweep&title=Welfare%20Fund"]}
- {state: AN, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Andaman%20and%20Nicobar%20Islands&title=Welfare%20Fund"]}
- {state: PY, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Puducherry&title=Welfare%20Fund"]}
- {state: AR, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Arunachal%20Pradesh&title=Welfare%20Fund"]}
- {state: MN, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Manipur&title=Welfare%20Fund"]}
- {state: ML, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Meghalaya&title=Welfare%20Fund"]}
- {state: MZ, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Mizoram&title=Welfare%20Fund"]}
- {state: NL, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Nagaland&title=Welfare%20Fund"]}
- {state: SK, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Sikkim&title=Welfare%20Fund"]}
- {state: TR, applies: false, act: null, confidence: SECONDARY_SOURCE_ONLY, sources: ["https://prsindia.org/acts/states?state=Tripura&title=Welfare%20Fund"]}
```

---

## Items still open, in priority order
1. **UP:** read the missing s.3(2) page of the UP LWF Act 1965, the UP LWF Rules and any 1965–2024 amendments, to confirm that no periodic contribution is levied. The ss.4–25 text that was read contains none. Also check whether the Act applies in Uttarakhand.
2. **TN deduction month:** June wages and 31 July (r.12 text) versus December and 31 January (Form A heading and secondary sources). Get the current r.12 text from the TN Gazette.
3. **Punjab:** whether Bill 15-PLA-2025 (₹10/₹40 per month, plus a 20+ threshold for S&E establishments) has been gazetted.
4. **Kerala:** which fund applies (LWF 1975 at ₹45/₹45 per half-year vs the Shops fund at ₹50/₹50 per month), and the notification that raised the Shops fund rate.
5. **MP:** read the 28-01-2026 gazette; confirm the ₹2,500 minimum rule and the current supervisory ceiling.
6. **AP:** read the text of AP Act 7 of 2015, to confirm that it set ₹30/₹70 and to check for any threshold or ceiling change.
7. **GJ:** find the notification that raised the rate to ₹6/₹12, and its date.
8. **CG:** find any primary source; the official site is dead.
9. **DH:** check whether the Goa, Daman & Diu LWF Act 1986 is in force in Daman and Diu.
10. **Product model gaps:** none of the following can be stored today:
    - managerial/supervisory flags and per-state wage ceilings;
    - an employer minimum (MP);
    - monthly accrual with half-yearly remittance (PB, CH);
    - membership-based funds (KL);
    - a government share, which is informational only.
    - The stored `effective_from` dates for MH, TN and GJ need new versions.
