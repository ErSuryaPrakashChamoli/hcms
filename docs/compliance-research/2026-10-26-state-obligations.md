# Indian state/UT labour compliance for the PeopleOS Compliance Reports Centre

**Researched:** 2026-10-26. **Scope:** state-level obligations only:
- **A.** Professional Tax (PT)
- **B.** Labour Welfare Fund (LWF)
- **C.** Shops & Establishments (S&E) registers and returns
- **D.** Other state periodic HR filings (top 15 states only)

Central obligations (EPF, ESI, TDS, the Labour Codes as such) are out of scope.

**Confidence labels on every fact:**
- **HIGH:** an authoritative source (state gazette, department or board website, rule text) was fetched and read.
- **MEDIUM:** a reputable secondary source (law firm, Big-4, established compliance publisher), or two or more concordant secondaries, or authoritative but older text.
- **LOW:** a single secondary source, or sources that conflict.
- **NOT CONFIRMED:** could not be verified. "N (absence)" means no levy or Act was found in any compilation, which is evidence of absence, not proof.

Secondary sources are labelled as such inline. Simpliance (simpliance.in) is the main secondary source for LWF and the smaller states' PT. It is a compliance-content publisher with per-page "updated as on" dates, quoted where shown.

## Method and limits
- Four research passes ran in parallel: S&E for the top 15 states, S&E for the other 21 states/UTs, other state filings (D), and PT/LWF (A and B).
- The web-search budget for this session ran out partway through the PT/LWF pass. After that, facts were verified only by fetching known official or publisher pages directly; no further searching was possible. The weakest areas are:
  - LWF amounts outside MH/KA/TN/HR, which rest mostly on a single secondary source;
  - PT return forms and due dates in the north-eastern states;
  - Kerala's sector-specific welfare funds.
- Several official sites could not be read: gst.kar.nic.in and apct.gov.in (DNS failure), public.mlwb.in (TLS certificate failure; not bypassed), shramsewa.mp.gov.in (connection reset), and wblwb.org (currently serving spam links).

## Changes in 2025–26 that the report engine must effective-date
| Change | Effective | Where |
|---|---|---|
| **Odisha PT repealed** (Ordinance 02/2026; FY 2025-26 arrears and annual return saved) | 1-Apr-2026 | OD-A |
| **West Bengal PT slabs replaced** (nil to ₹20,000; top rate ₹208) | 1-Oct-2026 (mid-FY) | WB-A |
| **Maharashtra PT due dates moved to the 15th** (monthly: 15th; annual: 15 Mar) | Notification 28-Feb-2026 | MH-A |
| Karnataka PT: ₹300 in February, ₹15,001–24,999 band now nil | FY 2025-26 onward | KA-A |
| Assam PT: nil to ₹15,000, then ₹180 / ₹208 | 1-Apr-2025 | AS-A |
| Kerala PT half-yearly slabs raised | 1-Oct-2024 | KL-A |
| Haryana LWF employee cap revised every January (₹31 → ₹34 → **₹35**) | Jan-2026 | HR-B |
| MP LWF reported raised to ₹10 / ₹50 | Notification 28-Jan-2025 | MP-B |
| Labour Codes in force; state OSH rules final in **GJ, RJ, AP, UP**, replacing Factories/CLRA returns there | 21-Nov-2025 onward | D |
| S&E applicability or registration thresholds raised to **20 workers** in many states; **Bihar S&E law repealed**; several new S&E Acts awaiting commencement (GA, JK, DN/DD) | 2025–26 | C |

## Product design implications
- **PT frequency depends on employer data in two states.**
  - **MH:** the employer's *previous-year PT liability* decides it (≥ ₹1,00,000 monthly, otherwise annual), and the employer files monthly in its registration year.
  - **GJ:** *headcount* decides it (> 20 monthly Form 5; ≤ 20 quarterly payment plus annual Form 5-AA).
  - Store both inputs per employer, per state.
- **Special-month amounts:** February ₹300 (MH, KA; and OD before repeal); 12th-month ₹174 / ₹212 (MP). Gender-specific slabs in MH (women nil up to ₹25,000).
- **Local-body PT:** TN, KL and PY levy PT half-yearly through municipalities and panchayats, so rates can differ by local body. Model "PT authority" below state level for these states.
- **LWF exclusions:** most LWF Acts exclude managerial or supervisory staff above a wage ceiling (MH, GJ ₹3,500; WB, TG, GA ₹1,600; DL ₹2,500; TN ₹15,000; CG ₹10,000). This needs an employee flag ("managerial/supervisory") plus the wage test.
- **Calendar periods vary:** LWF half-years are June/December almost everywhere; Punjab and Chandigarh pay for April–September and October–March; Goa pays for January–June and July–December. Annual LWF states (KA, TN, AP, TG) cover the calendar year and are due in January.
- **Combined returns** cut across sections: many states fold S&E, minimum wages, CLRA, bonus and maternity data into one annual return (KA Form U/20, TN Form ZC/22, KL Form 21, AP Form B, HR single return, TG integrated Form I, PY Form-I, MP/OD combined, RJ OSH Form-22). The Reports Centre should generate these as first-class outputs rather than only the per-Act returns.

## Cross-cutting notes: S&E (section C)
- Cross-cutting note: the central "Ease of Compliance to Maintain Registers under various Labour Laws Rules, 2017" (G.S.R. 154(E), 21-Feb-2017; in force on notification; combined registers, electronic or otherwise) cover only 9 CENTRAL Acts (BOCW 1996, CLRA 1970, Equal Remuneration 1976, ISMW 1979, Mines 1952, Minimum Wages 1948, Payment of Wages 1936, Sales Promotion Employees 1976, Working Journalists 1955) — 56 registers reduced to 5: Form A Employee register, Form B Wage register, Form C Register of loans/recoveries, Form D Attendance register, Form E Register of rest/leave/leave wages (form titles from memory corroborated by Gujarat r.26 proviso naming Form B = wages, Form D = muster roll — MEDIUM). Sources: https://www.simpliance.in/India/LEI/govt_notification/Central/ease-of-compliance-to-maintain-registers-under-various-labour-law-rules-2017-92 ; https://www.azbpartners.com/bank/ease-of-compliance-to-maintain-registers-under-various-labour-laws-rules-2017/ — MEDIUM. They do NOT replace state S&E registers unless a state rule says so (Gujarat does — see GJ). State-level consolidation found: KA (Form T/U), TN (Forms U–X, ZC), TG (G.O.Ms.23/2016 integrated registers & return), AP (Act 10 of 2015 combined return), HR (single integrated annual return), GJ (accepts central Forms B & D).
- Labour Codes note (out of scope, context only): the four Labour Codes took effect 21-Nov-2025 per simpliance advisory; OSH&WC Code-registered establishments are carved out in KA 2026 amendment. Several states (MH, GJ, HR, UP, PB, OD, AP) raised S&E applicability/registration thresholds to 20 workers in 2025–26 — a 20-worker gate is now common.
- **OSH&WC Code in force 21-Nov-2025** has triggered a wave of S&E changes (2025–26): repeal (BR), threshold hikes to **20 workers** (CG, GA-2025 Act, NL, TR, UK, CH via Punjab amendment, DN/DD Regulation 14/2025, AN registration), hikes to 10 (HP registration, AS split), deemed registration via OSH Code (AS, MZ, CH exemption, AN r.12A, DN/DD draft). The report engine needs **effective-dated applicability thresholds per state** and a "covered by OSH Code instead" outcome.
- **Commencement-pending new Acts** (must not be applied until notified): GA 2025 Act, JK 2025 Act, DN/DD Regulation 14/2025; Bihar 2025 Act commencement unclear (now repealed anyway).
- **States/UTs with a statutory S&E return** (confirmed text): GA (quarterly Form XXIX by 10th of next month, 5+ employees), JH (annual by 31-Mar + quarterly Form XIX within 30 days, 10+; or single online return by 30-Jun), MN legacy (annual statement Form III by end-Jan), PY (consolidated annual return Form-I online by 30-Apr), CG (annual Form-14 within 45 days of calendar year-end), DN/DD (annual Form Q by 31-Jan, secondary), AS/UK (annual return mandated by Act, form not yet prescribed). None found: HP, ML, MZ, NL, SK, TR, CH, AN.
- **Electronic / consolidated**: Act-level "electronically or manually" clauses in AS 2022, BR 2025 (repealed), GA 2025 (pending), JK 2025 (pending), UK 2017; JH r.43B (digital allowed by order); PY integrated register (online) includes S&E; HP and TR Ease-of-Compliance rules **exclude** S&E. Most older-Act states (GA 1975, HP, ML, MZ, NL, SK, TR, CH) require **bound, page-numbered** registers but allow Inspector/Commissioner-approved alternative formats (useful hook for HRMS-generated registers).
- **No S&E law found**: AR, LD (and BR after repeal) — LA applicability of J&K 1966 Act unconfirmed.

## Cross-cutting notes: other state filings (section D)
### Cross-cutting: Labour Codes / OSH Code state rules (affects every state below)

- The four Labour Codes (incl. the Occupational Safety, Health and Working Conditions Code 2020, the "OSH Code") came into force on **21 Nov 2025**. OSH (Central) Rules 2026 were notified as G.S.R. 345(E) on 8 May 2026. They cover only central-sphere establishments; their annual return is Form XVII, due by the last day of February. Sources: https://www.corporateprofessionals.com/articles/final-osh-rules-key-changes-employers-should-note/ ; https://www.taxclue.in/blog/rule-73-74-osh-central-rules-2026-display-on-notice-board-and-annual-return/ — MEDIUM (secondary).
- **State OSH rules decide whether a state's old Factories Rules / CLRA Rules returns still apply.** Where final state OSH rules are notified, they replace the state Factories Rules and the state CLRA Rules, and the OSH-rule annual return replaces Factories Rules Form 20-27 style returns. Where only draft rules exist, the old state rules are still followed in practice during the transition. That practice is NOT CONFIRMED against a specific savings-clause notification for each state.
- Status of state OSH rules in late 2026. Trackers disagree, so treat each state's status as provisional:
  - Final: **Gujarat** (gazette 12 Jun 2025), **Rajasthan** (30 Jun 2026), **Andhra Pradesh** (6 Aug 2026) and **Uttar Pradesh** (27 Aug 2026).
  - Draft only: MH, KA, TN, TG, DL, HR, KL, MP, PB, OD (Odisha published its draft in Feb 2026).
  - Not shown on the tracker: WB.
  - Sources: LKS tracker https://employmentlaw.lkslaw.com/states (secondary, law firm; its "last verified" date is ambiguous; it lists UP as draft, which conflicts with TeamLease); TeamLease UP https://teamleaseregtech.com/updates/article/59333/uttar-pradesh-occupational-safety-health-and-working-conditions-rules- (secondary); Khanna & Associates tracker of 21 Oct 2026 https://khannaandassociates.com/blog/state-labour-code-rules-status-tracker-which-states-still-have-only-draft-rules-in-october-2026/ (secondary; it says MH, KA and MP are reported as "final" on some sites and "draft" on others). Confidence: MEDIUM for GJ/RJ/AP/UP being final, LOW for the rest.
- **HRMS design implication:** each state's return template needs an effective-date switch: "pre-OSH-rules" forms (Factories Rules, CLRA Rules, MW Rules, etc.) and "post-OSH-rules" forms (state OSH Rules annual return). Wage Code / IR / SS state rules can likewise replace the Minimum Wages / Payment of Wages / Bonus / Maternity returns, but only Punjab's Wage Code rules are reported final (29 Dec 2025, LKS, LOW).

### Generic baseline (central rules that most state rules copy; verify state-specific variance)

- **CLRA (Central) Rules 1971 r.82.** Contractor half-yearly return in **Form XXIV**, due within 30 days of the close of each half-year (i.e. by 30 Jul and 30 Jan). Principal employer annual return in **Form XXV**, due by **15 Feb**. Most state CLRA Rules mirror these form numbers. Confidence: MEDIUM. This is a well-established central rule, but the authoritative text was not fetched this session.
- **Minimum Wages (Central) Rules 1950 r.21(4A).** Annual return in **Form III**, due by **1 Feb**. Many state MW Rules use Form III with a 1 Feb / 31 Jan date. Confidence: MEDIUM, not fetched.
- **Payment of Bonus Rules 1975 r.5.** Annual return in **Form D**, due within 30 days of the payment of bonus. This is a central rule but is filed with the state Inspector for the state sphere. Confidence: MEDIUM, not fetched.
- **Factories Act 1948 — the typical state pattern** (the forms differ by state):
  - Annual return for the calendar year, due by **31 Jan / 1 Feb**.
  - Half-yearly return for Jan–Jun, due by **15–31 Jul**.
  - Annual return of holidays: some states require it by 30 Nov / 31 Dec.

---

# State-by-state detail

## MH — Maharashtra

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Maharashtra State Tax on Professions, Trades, Callings and Employments Act, 1975 + Rules, 1975 (MahaGST / Sales Tax Dept). — HIGH
- **Frequency (Rule 11(3), consolidated text):** previous-year PT liability **< ₹1,00,000 → annual return**; **≥ ₹1,00,000 → monthly return**; clause (b) quarterly omitted. **Rule 11A:** in the year registration is granted, the employer files **monthly** until year-end. The ₹1,00,000 threshold was substituted on 6-Jun-2019; the ₹50,000 / ₹5,000 / ₹20,000 figures in older guides are superseded. Payment is due with the return (Rule 11(1)). Source: https://www.comply360.in/labor-law-library/wp-content/uploads/2024/02/THE-MAHARASHTRA-STATE-TAX-ON-PROFESSIONS-TRADES-CALLINGS-AND-EMPLYOMENTS-RULES-1975-.pdf (consolidated rules text, secondary host; footnote 20 "Subs. … 6th June 2019") — HIGH for the rule text.
- **Due dates:**
  - Before the 2026 amendment: monthly return by the **last day of the month** for salary paid in the preceding month (rule illustration: April salary paid in May → May return due 31 May). Annual return by **31 March**, covering March of the previous year plus April–February. — HIGH (rule text)
  - **From the Maharashtra State Tax on Professions … (Amendment) Rules, 2026** (Finance Dept notification dated 28-Feb-2026, reported as No. VYAKAR-2026/C.R.4/Taxation-3): "31st of March" → **"15th of March"**; "last date" → **"15th day"**. Monthly returns are now due by the **15th of the following month** and annual returns by **15 March**. Sources: https://taxguru.in/goods-and-service-tax/maharashtra-advances-profession-tax-due-dates-15th.html ; https://www.sgcms.com/regulatory-updates/amendment-in-professional-tax-maharashtra/ — MEDIUM (two concordant secondaries; official PDF not fetched). Simpliance still shows "last day" / 31 March — LOW (out of date).
- **Return form:** **Form III-B** (electronic return or revised return, Rule 11(2A)). Payment by challan **MTR-6** *before* uploading the return. The paper Form III is legacy. — HIGH (rule text)
- **Slabs (monthly salary):**
  - Men: ≤ ₹7,500 nil; ₹7,501–10,000 ₹175; > ₹10,000 ₹200, and **₹300 in February** (₹2,500 a year).
  - Women: ≤ ₹25,000 nil; > ₹25,000 ₹200, and ₹300 in February.
  - Sources: https://www.simpliance.in/professional-tax-detail/maharashtra ; https://www.hono.ai/professional-tax/maharashtra — MEDIUM (concordant secondaries)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Maharashtra Labour Welfare Fund Act, 1953 + Rules, 1953.
- **Contribution:** employee **₹25**, employer **₹75** per half-year (total ₹100). Simpliance notes the "latest amendment as March 2024 (Maharashtra Gazette Notification)".
- **Frequency and due dates:** **half-yearly**. Deduct from wages for the periods ending **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** establishments employing **5+** persons; covers all employees except those in managerial or supervisory capacity.
- **Form:** **Form A-1** (return-cum-challan).
- Source: https://www.simpliance.in/labour-welfare-fund-detail/maharashtra (updated 17-Jun-2025) — MEDIUM (single reputable secondary). The official MLWB portal (public.mlwb.in) failed TLS certificate verification and was not read.

### C. Shops & Establishments
- **1. Act / Rules:** Maharashtra Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2017 (Mah. LXI of 2017; in force 19-Dec-2017, repealed Bombay S&E Act 1948) + Maharashtra S&E (RE&CS) Rules, 2018 (notified 23-Mar-2018; replaced 1961 Rules). Source: https://www.legitquest.com/act/maharashtra-shops-and-establishments-regulation-of-employment-and-conditions-of-service-rules-2018/8B68 (rules text, secondary host) — HIGH for form list; https://www.india-briefing.com/news/maharashtra-shops-establishments-rules-2018-explained-16688.html/ — MEDIUM.
  - **Threshold amendment (2025):** Mah. Ordinance VIII of 2025 (1-Oct-2025) replaced by L.A. Bill LXXXIX of 2025 (introduced 8-Dec-2025; deemed in force 1-Oct-2025): s.1(3), s.6(1), s.7 "ten" -> "twenty" — establishments with **20+ workers register**, **<20 file intimation only** (Form F); daily hours 9 -> 10 (s.12), spread-over 12h (s.14), OT cap 125 -> 144 hrs/quarter (s.15). Source (Maharashtra Govt Gazette Extraordinary Part VIII, 8-Dec-2025, via PRS): https://prsindia.org/files/bills_acts/bills_states/maharashtra/2025/Bill89of2025MH.pdf — HIGH for Ordinance/Bill content; final passage/assent of the Amendment Act — NOT CONFIRMED (ordinance itself: https://prsindia.org/files/bills_acts/bills_states/maharashtra/2025/Ord8of2025MH.pdf; Khaitan alert https://khaitanco.com/thought-leadership/Maharashtra-promulgates-key-ordinance-to-amend-shops-and-establishments-law — MEDIUM). Rules amended correspondingly (S&E Amendment Rules 2025, published 12-Nov-2025, applicable 19-Nov-2025: forms H/J/K aligned to 20-worker threshold; Form L night-shift consent of women retained 3 yrs; CCTV footage 45 days) — https://www.corpseed.com/law-update/maharashtra-shops-and-establishments-amendment-rules-2025 — LOW (single secondary).
- **2. Registers / forms (Rules 2018):**
  - Form Q — Muster-Roll cum Wages Register (r.26(1)); proviso: not required if employer already keeps the register under r.27 Maharashtra Minimum Wages Rules 1963. OT entries to be made immediately after OT is worked (r.26(2)). — HIGH (legitquest rules text)
  - Form O — Leave Book (r.19; worker holds book, employer keeps copy). Form P — Notice of accumulated leave (r.20). — HIGH
  - Form L — Consent of woman worker for night shift (r.13(2)(i)). — HIGH
  - Notices: Form M — hours of work, rest interval, weekly holiday (r.14; website + notice board); Form N — shift schedule & weekly holiday (r.16(1)). — HIGH
  - Other employer forms: A registration application (r.3), D renewal application (r.6), F intimation (<threshold, r.8), I notice of change (r.10), J/K closure intimation (r.11), S compounding (r.31), T managerial persons (r.33), U confidential-work persons (r.34). Forms B/C/E/G/H are authority-side (certificates/registers). — HIGH
  - No separate fines/deductions/advances register under the 2018 Rules (only Form Q). — MEDIUM (rules text as fetched was partially truncated after Form M)
  - Records kept 3 years (r.26(4)); Marathi or English. — HIGH
- **3. Annual return:** **Form R — Annual Return (r.27)**, uploaded online, for calendar year ending 31-Dec, **due within 2 months (i.e. by end-Feb)**; print copy on Facilitator's demand. Source: legitquest rules text — HIGH; Form R copy https://www.scribd.com/document/511544552/Form-R — LOW (corroboration).
- **4. Consolidated / electronic registers:** r.26: entries "authenticated digitally or manually" — electronic maintenance permitted (Majmudar: hard copies to be produced on inspection, https://www.majmudarindia.com/new-legislation-applicable-to-shops-and-establishments-in-maharashtra/ — MEDIUM). Form Q itself is a combined muster-roll-cum-wages register. A Maharashtra-specific "Ease of Compliance" consolidated-register notification covering the S&E Act — NOT CONFIRMED (none found). The central Ease of Compliance Rules 2017 do not cover the state S&E Act.
- **5. Renewal:** 2017 Act s.6 — certificate validity max 10 yrs (renewal Form D/E under r.6–7). A Govt Resolution (Industry, Energy & Labour Dept, dated 2-Mar or 2-Apr-2019, effective ~3-May-2019) reportedly **eliminated renewal** — https://lexplosion.in/?p=43162 (secondary, dates inconsistent) — LOW; conflicting secondary (Mondaq) still describes renewal. Treat as NOT CONFIRMED officially; <threshold establishments file one-time intimation (Form F), no renewal.

### D. Other state periodic HR filings
- **1. Factories.**
  - Rules and form: Maharashtra Factories Rules 1963, **Form 27** annual return for the calendar year, under r.119(1).
  - Due date: a Pune DISH office form copy says "on or before 1st February of every year". greytHR lists 10 Jan instead, which conflicts. Sources: https://vcportal.endurancegroup.com/data/contractor/1194_18__section1_.pdf (copy of the DISH Pune form; the link now returns 404; seen via search snippet) — LOW-MEDIUM; https://www.greythr.com/wiki/maharashtra-compliance-calendar — LOW.
  - **Form 27A** is listed by greytHR as a *monthly* return due on the 10th. NOT CONFIRMED; it may be a misreading. No half-yearly factories return was confirmed for MH (NOT CONFIRMED).
  - greytHR also lists an "Annual list of holidays" due to the Inspector by 30 Dec — LOW.
  - Online filing: the Maharashtra Factories (Second Amendment) Rules 2025 (dated 3 Oct 2025) make Forms 1, 4 etc. electronic. Whether Form 27 is on the e-portal is NOT CONFIRMED. DISH site: mahadish.in. Source: https://ksandk.com/md/newsletter/maharashtra-factories-second-amendment-rules-2025/ — MEDIUM (secondary).
- **2. Minimum Wages.** Maharashtra Minimum Wages Rules 1963, **Form III** annual return. greytHR lists it as due 30 Jan; the central baseline is 1 Feb. Source: greytHR calendar above — LOW.
- **3. Integrated return.**
  - What it is: the **Self-Certification-cum-Consolidated Annual Return Scheme**, created by GR of 23 Jun 2015. It covers 16 labour Acts for shops, establishments and factories. Members file one consolidated return in place of several, filed online on the LMS portal (lms.mahaonline.gov.in) since a 2018 direction.
  - Form and due date: greytHR lists the return as "Annexure IV", due **29 Apr** to the Regional Committee — LOW. For the 2019 return, the deadline was extended to 31 Jul 2020 (COVID), which implies a normal date earlier in the year.
  - Sources: https://labour.gov.in/maharashtra-0 (MoLE state page, authoritative but old) — MEDIUM; https://www.simpliance.in/India/LEI/govt_notification/Mizoram/extension-of-consolidated-annual-return-under-various-labour-laws-due-to-covid-19-in-maharashtra-703 — LOW.
  - The exact list of the 16 Acts was NOT CONFIRMED.
- **4. CLRA.** Maharashtra Contract Labour (R&A) Rules (amended 2015/2016 for 7-day licensing). Form XXIV and Form XXV follow the central pattern. Keka cites 15 Feb for Form XXV — LOW. Source: https://labour.gov.in/maharashtra-0 — MEDIUM, for the licensing amendment only.
- **5. Other.** greytHR lists an annual return under the Maharashtra Maternity Benefit Rules in **Form 11**, due 14 Jan to the Chief Inspector — LOW.
- **OSH Code status:** draft only, so the Factories Rules 1963 still apply (LKS tracker) — LOW-MEDIUM.

---

## KA — Karnataka

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Karnataka Tax on Professions, Trades, Callings and Employments Act, 1976 + Rules, 1976. Portal: https://ptax.karnataka.gov.in/ptemployer — HIGH
- **Slabs (from 1-Apr-2025, Karnataka Act No. 33 of 2025; Notification DPAL 08 SHASANA 2025 dated 15-04-2025):** monthly salary **< ₹25,000 nil**; **≥ ₹25,000 ₹200 a month, and ₹300 in February** (₹2,500 a year). The old ₹15,000–24,999 band (₹150) is now nil. The official employer portal notice reads "PT for Feb-2026 to be deducted at Rs. 300.00 per employee instead of Rs. 200.00". Gazette PDF: https://erajyapatra.karnataka.gov.in/WriteReadData/2025/8459.pdf (via https://akriviahcm.com/resources/global-payroll/alerts/revision-of-karnataka-professional-tax-rate). — HIGH (official portal notice) / MEDIUM (band detail)
- **Frequency and due date:** **monthly**. Under s.6A the employer files a statement (**Form 5-A**) and pays **within 20 days after the end of each month**, i.e. by the 20th of the following month. A 2023 Removal of Difficulties Order allowed April 2023 to be filed by 31 May 2023. Source: https://lexplosion.in/?p=136528 — MEDIUM (law-firm alert quoting s.6A; Act text host gst.kar.nic.in unreachable)
- **Annual return:** **Form 5**, within **60 days** of the end of the financial year (≈ 30 May). Sources: https://www.simpliance.in/professional-tax-detail/karnataka ; https://www.greythr.com/wiki/compliances/karnataka-form-5-annual-profession-tax-return/ — MEDIUM
- **Other:** Simpliance lists an exemption for persons over 60 — LOW.

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Karnataka Labour Welfare Fund Act, 1965 + Rules, 1968.
- **Contribution:** employee **₹50**, employer **₹100**, government ₹50, per employee per year. The official KLWB homepage links "the Government order in the Karnataka Gazette raising welfare-fund contribution to 50 100 50". Sources: https://klwb.karnataka.gov.in/ — HIGH (rate triple from official page); https://www.simpliance.in/labour-welfare-fund-detail/karnataka (updated 13-Jan-2026) — MEDIUM
- **Frequency and due date:** **annual**, calendar year. Deduct at **31 December**, pay by **15 January**. — MEDIUM (Simpliance)
- **Applicability:** **10+** employees. — MEDIUM
- **Form:** **Form D**. — MEDIUM

### C. Shops & Establishments
- **1. Act / Rules:** Karnataka Shops and Commercial Establishments Act, 1961 (Karnataka Act 8 of 1962) + Karnataka S&CE Rules, 1963 (made under s.40; Karnataka Gazette May 1963). Act text: https://prsindia.org/files/bills_acts/acts_states/karnataka/1962/1962KA8.pdf — HIGH (authoritative host, not fetched in full); Rules: https://www.legitquest.com/act/karnataka-shops-and-commercial-establishments-rules-1963/AF72 — HIGH for rules as consolidated on that page (older consolidation; later amendments missing).
  - **Karnataka S&CE (Amendment) Act, 2026 (Karnataka Act No. 38 of 2026; assent 3-Sep-2026; notified DPAL 32 SHASANA 2026, 4-Sep-2026; in force immediately):** registration valid until closure (no periodic renewal); online registration/fees; deemed registration if no reply in 7 days; establishments with 10+ workers already registered under the OSH&WC Code 2020 excluded from the Act; godowns within 100 m need no separate registration; service certificate within 7 days; ban on retaining original certificates; s.25(1)(h)–(o) women night-shift transport conditions omitted; compounding at 50%/75%; Payment of Wages Act / WC Act refs replaced by Code on Wages / Code on SS. Sources: https://ksandk.com/newsletter/karnataka-shops-and-commercial-establishments-amendment-act-2026-key-changes-for-employers/ and https://www.azbpartners.com/bank/karnataka-recent-key-amendments-to-the-shops-and-commercial-establishment-law/ — MEDIUM (two concordant law-firm alerts; gazette not fetched).
  - **Draft S&CE (Amendment) Rules, 2025** (LD 158 LET 2025; comments till 11-Oct-2025): proposes omitting the duty to maintain registers/records and display notices for employers with **<10 employees**. Final notification — NOT CONFIRMED. Sources: https://lexplosion.in/public-comments-invited-on-draft-karnataka-shops-and-commercial-establishments-amendment-rules-2025/ ; https://simpliance.in/India/LEI/govt_notification/karnataka/draft-notification-of-the-karnataka-shops-and-commercial-establishments-amendment-rules-2025-7270 — LOW/MEDIUM.
- **2. Registers / forms (Rules 1963):**
  - Form F — Leave with Wages Register (r.8(1); keep 3 yrs after last entry). — HIGH (legitquest rules text)
  - Form H — Leave with Wages Book (r.9(1); employee's property). — HIGH
  - Form P — Weekly holiday notice (r.24(4)). Form Q — Appointment order (r.24(9A), inserted by LD 37 LSC 97 dt 31-8-1998). Form E — notice of opening/closing hours. Form D — notice of change (r.5). Form A — registration statement (r.3); Form AA — renewal statement (r.3A). Form J — medical certificate of age (r.22). Forms R/S — IT/ITES women night-shift exemption application/grant (r.24-B, LD 237 LET 2002 dt 18-9-2003). Visit Book (r.24(11)). — HIGH
  - **Form T — Combined Muster Roll-cum-Register of Wages** (attendance, OT, component-wise wages, deductions; month-wise). — MEDIUM (secondary: https://www.greythr.com/wiki/compliances/karnataka-form-t-combined-muster-roll-cum-register-wages/ ; inserting notification/rule number NOT CONFIRMED).
  - Entries to be made on the same day (r.24(7)); records retained till end of following calendar year (r.24(8)). — HIGH
- **3. Annual return:** Old Form G annual leave return (due 1 Sep) **omitted** by LD 37 LSC 97 (31-8-1998). Current: **Form U — Combined Annual Return**, online only via e-Karmika; filing Form U substitutes annual returns under Karnataka CLRA Rules (Form XXV), Karnataka MW Rules (Form III), Karnataka PW Rules (Form XX), Karnataka Maternity Benefit Rules (Form L). **Due ~31 January** for preceding calendar year (greytHR calendar shows 30-Jan for 2025). Sources: https://greythr.com/wiki/compliances/karnataka-form-u-combined-annual-return-karnataka ; https://www.greythr.com/blog/file-your-form-u/ — MEDIUM (secondary only; rule number & official due date NOT CONFIRMED).
- **4. Consolidated / electronic registers:** Form T (combined muster-cum-wages) and Form U (combined annual return across S&CE/MW/PW/CLRA/MB) are Karnataka's consolidation — MEDIUM (secondary). A Karnataka rule expressly permitting electronic registers under the S&CE Rules — NOT CONFIRMED. Draft 2025 rules would exempt <10-employee employers from registers — NOT CONFIRMED as final.
- **5. Renewal:** r.3A: renewal once in 5 years (Form AA, ≥90 days before expiry) — HIGH (rules text). Govt Order dt 11-Jun-2019: periodic renewal discontinued, auto-renewal on self-certification + fee (https://khaitanco.com/thought-leadership/karnataka-requirement-for-s-and-e-act ; https://simpliance.in/India/LEI/govt_notification/karnataka/elimination-of-renewal-of-registration-under-karnataka-shops-and-commercial-establishments-act-1961-514 — MEDIUM). Amendment Act 2026: registration valid till closure (no renewal) — MEDIUM.

### D. Other state periodic HR filings
- **1. Factories (Karnataka Factories Rules 1969).**
  - **Form 20** is the *Combined Annual Return* under r.134(1), for the calendar year, filed with the Area Inspector. greytHR lists it as due **31 Jan** — MEDIUM-LOW.
  - **Form 21** is the half-yearly return, for the half-years ending 30 Jun and 31 Dec. greytHR lists it as due **14 Jul** — LOW. Some sources say 15 Jul or 31 Jul; NOT CONFIRMED.
  - Sources: https://www.greythr.com/wiki/compliances/karnataka-form-20-combined-annual-return ; https://www.greythr.com/wiki/karnataka-compliance-calendar/ (2026-27 calendar) — LOW-MEDIUM (secondary).
  - Online filing: the Department of Factories, Boilers, Industrial Safety & Health portal is esuraksha.karnataka.gov.in (from a 2020 departmental circular, seen in a snippet). Whether returns are filed online there is NOT CONFIRMED.
- **2. Minimum Wages.** Karnataka Minimum Wages Rules 1958, **Form III** (r.22(4)). greytHR lists it as due 30 Jan. It is *not required* where Form 20 (factories) or Form U (shops) has been filed. Source: https://greythr.com/wiki/compliances/karnataka-form-iii-annual-return — LOW-MEDIUM.
- **3. Integrated returns.**
  - Factories: filing **Form 20** waives the following separate returns (greytHR; MEDIUM-LOW):
    - CLRA Form XXV (r.82(2), Contract Labour (R&A) Karnataka Rules 1974)
    - MW Form III (r.22(4))
    - Bonus Form D (r.5)
    - Karnataka Payment of Wages Rules 1963 Form IV (r.20)
    - Karnataka Maternity Benefit Rules 1966 Forms K/L/M (r.6)
  - Non-factory establishments: **Form U** (Combined Annual Return under the Karnataka Shops & Commercial Establishments Rules) plays the same role. It is filed online only on the e-Karmika portal, due 31 Jan (greytHR also cites 30 Jan). It covers employment, leave, welfare, maternity and contract labour data, and waives Form XXV, Form III, Payment of Wages Form XX/IV and Maternity Form L. Sources: https://greythr.com/wiki/compliances/karnataka-form-u-combined-annual-return-karnataka ; https://nishithdesai.com/research-and-articles/hotline/hr-law-hotline/labour-law-compliances-made-easier-in-bangalore-5886 — MEDIUM-LOW. The S&E agent should coordinate on this.
- **4. CLRA.** Contract Labour (R&A) Karnataka Rules 1974:
  - Contractor half-yearly return: Form XXIV.
  - Principal employer annual return: Form XXV (r.82(2)), waived if Form 20 or Form U is filed.
  - Due dates are believed to follow the central pattern (30 days after the half-year / 15 Feb) — NOT CONFIRMED for the KA text.
- **5. Other.** greytHR lists a **Karnataka Payment of Wages Rules 1963, Form IV** annual return due 30 Jan — LOW.
- **OSH Code status:** draft only, per the LKS tracker. Some trackers claim the rules are final — LOW. Treat the Factories Rules 1969 as still applicable.

---

## TN — Tamil Nadu

### A. Professional Tax (PT)
- **Levies on salaried: Y. It is a local-body tax.** In urban areas the basis is **s.117-C of the Tamil Nadu Urban Local Bodies Act, 1998** with **Rule 277(2) of the TN Urban Local Bodies Rules, 2023**. Greater Chennai Corporation (GCC) and other corporations and municipalities collect it; panchayat areas have their own levy. Simpliance still cites the older TN Municipal Laws (Second Amendment) Act 1998 / TN Professional Tax Rules 1999. Source: https://www.dtnext.in/news/chennai/professional-tax-must-be-paid-before-sept-30-chennai-corporation (6-Aug-2026) — MEDIUM
- **Frequency:** **half-yearly** (April–September and October–March), assessed on half-yearly gross salary. — MEDIUM
- **Due dates:** GCC directed employers to deduct from the **August** salary and pay by **30 September** (DT Next, MEDIUM). The second half is due by **31 March** (https://calcguru.in/?p=540 — LOW; Simpliance: "every 6 months starting on last day of September" — LOW).
- **Form:** **Form 2** (employer tax payment), per https://www.simpliance.in/professional-tax-detail/tamil-nadu — LOW. GCC online portal: erp.chennaicorporation.gov.in.
- **Slabs (GCC, half-yearly income, effective 1-Oct-2024; circular R.D.(HQ).C.No.PT/SPL/2024 dated 20-Jan-2025):** ≤ ₹21,000 nil; ₹21,001–30,000 ₹180; ₹30,001–45,000 ₹425; ₹45,001–60,000 ₹930; ₹60,001–75,000 ₹1,025; > ₹75,000 ₹1,250. Sources: https://akriviahcm.com/resources/?p=31840 ; https://ascent-hr.com/notification/professional-tax-slab-revision-chennai/ ; Simpliance (same table) — MEDIUM. Whether other local bodies use identical rates is NOT CONFIRMED.

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Tamil Nadu Labour Welfare Fund Act, 1972 + Rules, 1973.
- **Contribution:** **employee ₹20, employer ₹40**, government ₹20, per employee per year. Source: official TN Labour Welfare Board "Source of Fund" page (rates published as an embedded image; read by OCR), https://www.lwb.tn.gov.in/sub_page_content.php?menu_id=NDU1&submenu_id=ODUz&dept_id=205&lang=MQ== (site last updated 09-Oct-2026) — HIGH
- **Frequency and due date:** **annual**. Deduct at **31 December**, pay by **31 January**. — MEDIUM (https://www.simpliance.in/labour-welfare-fund-detail/tamil-nadu)
- **Applicability:** **5+** employees. Excludes managerial or supervisory staff drawing more than ₹15,000 a month, apprentices and part-timers. — MEDIUM
- **Form and portal:** **Form A**; pay online at https://lwmis.lwb.tn.gov.in. — MEDIUM
- **Other:** TN's new S&E combined annual return (Form ZC) also asks for TN LWF remittance details (see C).

### C. Shops & Establishments
- **1. Act / Rules:** Tamil Nadu Shops and Establishments Act, 1947 (TN Act XXXVI of 1947) + TN S&E Rules, 1948 (made under s.49(1)). Amendments: TN S&E (Amendment) Act, 2018 (TN Act 27 of 2018 — introduces registration + prohibition of discrimination against women), amended by TN Act 26 of 2021 (renewal dispensed with; certificate within 1 day) and TN S&E (Amendment) Amendment Act, 2023 (L.A. Bill 16 of 2023: new s.3 — **registration mandatory only for establishments employing 10+ workers**, apply within 6 months of commencement; deemed registration if not issued in 24 h; existing 10+ establishments furnish details + self-declaration within 1 yr). Registration regime brought into force **2-Jul-2024**. Sources: TN Govt Gazette Extraordinary (Bill 16/2023) via https://prsindia.org/files/bills_acts/bills_states/tamil-nadu/2023/Bill16of2023TamilNadu.pdf — HIGH; commencement https://mondaq.com/india/government-public-sector/1494960/tamil-nadu-implements-shops-and-establishments-registration-requirement-for-employers-along-with-welfare-provisions-changes-effective-from-2-july-2024 — MEDIUM.
  - Rules amendment G.O. Ms. No.55, LW&SD (K2), 2-Jul-2024, SRO A-13(e)/2024 (Gazette Extra. No.217): r.2A Form Y registration application (online, Rs.100); r.2B Form Z certificate (24 h) & Form ZA register of establishments (Inspector); r.2C **Form ZB intimation by existing establishments**; r.2D amendment of certificate online; r.6A first-aid box (1 per 150 persons); r.18 penalty raised to Rs.2,000. Source: https://stationeryprinting.tn.gov.in/extraordinary/2024/217_Ex_III_1a_2024.pdf — HIGH.
- **2. Registers / forms:** Amended **r.16(1)**: every employer shall maintain (a) **Form U — Register of persons employed**; **Form V — Register of Employment**; **Form W — Register of Wages**; **Form X — Register of Leave and Social Security Benefits**; (b) maintained electronically or manually; (c) electronic layout may be adjusted without changing integrity, serial number and column contents. Draft published TN Gazette 17-Feb-2021; final effective ~early 2022 (lexplosion dated 1-Apr-2022, "immediate effect"); exact G.O./SRO number NOT CONFIRMED. Old registers (Form P fines/deductions/advances, Form Q employment, Form R wages under r.11(4)–(5)/r.16) were proposed for omission in the 2021 draft — omission in final text NOT CONFIRMED. Sources: https://bombaychamber.com/labour-law-updates/provisions-relating-to-maintaining-of-registers-under-the-tamil-nadu-shops-and-establishments-rules-1948-amended ; https://lexplosion.in/employers-of-shops-and-establishments-in-tamil-nadu-now-required-to-comply-with-a-new-set-of-forms-which-can-be-maintained-electronically-or-manually-provisions-gain-immediate-effect/ ; draft summary https://compfie.aparajitha.com/wp-content/uploads/2021/03/05032021_LEI_01-1.pdf — MEDIUM (concordant secondaries quoting rule text).
- **3. Annual return:** **Form ZC — Combined Annual Return** for year ending 31-Dec, under s.47-A, **new r.16-D**, filed online via Labour Dept portal to area Inspector **on or before 31 January each year**. Inserted by G.O. Ms. No.93, LW&SD (K2), 29-Aug-2025, SRO A-23/2025 (TN Govt Gazette No.38, Part III Sec 1(a), 24-Sep-2025). Contents: headcount M/F/young persons, hours/spread-over, wages break-up, deductions (fines/damages/breach/others), fines fund & TN LWF remittance, earned leave, maternity benefit, bonus, permanency (480 days), contract labour, ISMW. First due 31-Jan-2026 (for CY2025) — inference. Source: https://www.stationeryprinting.tn.gov.in/gazette/2025/38_III_1a_2025.pdf — HIGH.
- **4. Consolidated / electronic registers:** Yes — four consolidated registers U/V/W/X, expressly electronic-or-manual (r.16(1)(b)–(c)) — MEDIUM (see sources in 2). Form ZC is a combined return (S&E + MB + bonus + CLRA + ISMW data) — HIGH.
- **5. Renewal:** None — renewal dispensed with by TN Act 26 of 2021 (per SOR of Bill 16/2023); certificate valid until closure (closure intimation within 30 days, s.3(7)). Source: Bill 16/2023 gazette (above) — HIGH.

### D. Other state periodic HR filings
- **1. Factories (Tamil Nadu Factories Rules 1950, r.100).**
  - **Form 22** is the *Combined Annual Return* for the calendar year, filed in duplicate with the Inspector **on or before 31 Jan** of the following year. It was introduced by r.100(2) as substituted by **G.O.Ms.No.257, Labour & Employment (M2), dated 16 Aug 2004** (preliminary notification G.O.Ms.No.128 of 14 Nov 2003). The form also carries data under the Maternity Benefit Act, Payment of Wages Act s.7, Payment of Bonus and Contract Labour. Source: https://dish.tn.gov.in/assets/pdf/Combined%20Annual%20return%20form.pdf (official DISH TN; text extracted) — **HIGH**.
  - **Form 21** is the half-yearly return. The due date is cited as **31 Jul** for Jan–Jun (Deskera) — LOW. The rule text was not fetched; the 31 Jan date for the Jul–Dec half is NOT CONFIRMED.
  - Online filing: the TN Factories Rules amendment of 28 Feb 2018 enables online filing of annual and half-yearly returns via the **DISH TN portal https://dish.tn.gov.in/**. The same amendment extended registration/renewal up to 10 years. Source: https://simpliance.in/India/LEI/govt_notification/tamil-nadu/tamil-nadu-factories-rules-amendment-introducing-compliance-through-online-portal-and-extension-of-registration-and-renewal-upto-ten-years-312 — MEDIUM (secondary).
- **2. Minimum Wages.** Tamil Nadu Minimum Wages Rules, annual return form and due date — NOT CONFIRMED. Assume the central baseline: Form III by 1 Feb.
- **3. Integrated return.** Form 22 (above) is TN's combined return for factories and industrial establishments — HIGH. It was not verified whether its filing formally waives separate Form XXV / Form D / Maternity returns. The form content covers those Acts, but no waiver clause was confirmed (NOT CONFIRMED). DISH TN also has a self-certification scheme document (https://dish.tn.gov.in/assets/pdf/selfcertification.pdf), not reviewed.
- **4. CLRA.** Tamil Nadu Contract Labour (R&A) Rules 1975 — state-specific dates NOT CONFIRMED (assume Form XXIV / Form XXV per the baseline).
- **5. Other.** Tamil Nadu Industrial Establishments (Conferment of Permanent Status to Workmen) Rules 1981 have an annual return in **Form 2**. It is *not required* if the combined annual return in Form 22 under TNFR r.100(2) has been furnished. Source: https://simpliance.in/India/LEI/govt_notification/tamil-nadu/amendments-to-the-tamil-nadu-industrial-establishments-conferment-of-permanent-status-to-workmen-rules-1981-366 (snippet) — MEDIUM-LOW. The TN Industrial Establishments (National & Festival Holidays) Act annual return is NOT CONFIRMED.
- **OSH Code status:** draft only (LKS) — LOW-MEDIUM. The Factories Rules 1950 still apply.

---

## TG — Telangana

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Telangana Tax on Professions, Trades, Callings and Employments Act, 1987 (the AP 1987 Act as adapted) + Rules, 1987.
- **Frequency and form:** **monthly**. Rule 12: the return under s.7(1) is in **Form V**, titled "Return of tax payable for the month ending on …". Rule 13: payment by challan. Late amounts carry interest at **2% per month**. Sources: https://www.tgct.gov.in/tgportal/AllActs/APPT/APPTRules.aspx ; https://www.tgct.gov.in/tgportal/AllActs/APPT/forms/form5.html — HIGH. The official Form V still prints a 6-band legacy slab grid.
- **Due date:** **10th of the following month**, for return and payment. Act s.7 says only "by such dates as may be prescribed". Sources: https://www.simpliance.in/professional-tax-detail/telangana ; https://cleartax.in/s/professional-tax-telangana — MEDIUM
- **Slabs (monthly):** ≤ ₹15,000 nil; ₹15,001–20,000 ₹150; > ₹20,000 ₹200. Sources: official FAQ https://www.tgct.gov.in/tgportal/faqs/FAQsProfessionTax.doc (text partly garbled) + Simpliance — MEDIUM

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Telangana Labour Welfare Fund Act, 1987 + Rules, 1988.
- **Contribution:** employee **₹2**, employer **₹5** per year.
- **Frequency and due date:** **annual**. Deduct at **31 December**, pay by **31 January**.
- **Applicability:** **20+** employees, per Simpliance. Excludes managerial or supervisory staff drawing more than ₹1,600.
- **Form:** **Form F**.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/telangana (updated 3-Oct-2024) — LOW-MEDIUM (single secondary; amounts long-standing)

### C. Shops & Establishments
- **1. Act / Rules:** Telangana Shops and Establishments Act, 1988 (originally A.P. Act 20 of 1988, adapted on bifurcation) + Telangana S&E Rules, 1990 (made under s.71; in force 1-11-1991). Applies to all notified-area shops/establishments (no employee-count threshold for registration found). Sources: https://www.indiacode.nic.in/bitstream/123456789/8549/1/act_20_of_1988.pdf (authoritative; not fetched — 403) ; rules text https://www.legitquest.com/act/telangana-shops-and-establishments-rules-1990/D55C — HIGH (rules text as consolidated; amendment footnotes absent). Post-2016 amendment to the Act — NOT CONFIRMED (none found).
- **2. Registers / forms (Rules 1990):**
  - Form X — Register of Fines (r.17(3)(a)); Form XI — Register of deductions for damage or loss (r.17(4)); Form XII — Register of Advances (r.18(4)). — HIGH
  - **Form XXII — Register of Employment (r.29(1))**; **Form XXIII — Register of Wages (r.29(2))**; Form XXIV — Notice of weekly holiday (r.29(3), establishments other than shops); **Form XXV — Register of leave granted (r.29(6))**; Form XXVI — Letter of Appointment with photo (r.30). Abstract of Act & Rules to be exhibited (r.29(4)); Visit Book (r.29(10)); entries same day, OT entries before/after OT (r.29(7)); preserve 3 yrs (r.29(8)); English or majority language (r.29(9)); separate registers for offices/godowns elsewhere (r.29(11)); name board in Telugu (r.29(13)). — HIGH
  - Employer forms: I registration statement (r.3(1)), III renewal application (r.3(4)), VI duplicate RC (r.5), VII notice of change (r.6(1)). — HIGH
- **3. Returns:** **Form XXVII — Periodical return (r.33)** by employers with **10+ employees**, **quarterly** for quarters ending Mar/Jun/Sep/Dec, **due by 10th of following month** (per rules text) — HIGH as to rules text; whether still enforced in practice after integrated return — NOT CONFIRMED. **Integrated Annual Return** under G.O.Ms.No.23 LET&F (Lab) dt 24-Mar-2016 (Form I common return; Forms II & III integrated registers), amended by G.O.Ms.No.6 dt 2-Mar-2019; covers 14 laws incl. **Telangana S&E Act 1988**, MW, PW, CLRA, ISMW, Factories, BOCW, MTW, Bonus, Gratuity, TS National & Festival Holidays Act; financial-year basis; extended due dates 30-Jun-2020 (two-month extension ⇒ normal due ~30 April — inference) and 30-Jun-2021 (G.O.Rt.No.248). Sources: https://www.simpliance.in/India/LEI/govt_notification/telangana/extension-of-due-date-for-furnishing-of-integrated-returns-under-various-labour-laws-in-telangana-1172 ; https://complinity.com/legal-update/covid-19-extension-of-due-date-for-filing-integrated-annual-returns-in-telangana-1885/ ; official GO host (not retrievable) https://tsfactories.cgg.gov.in/content/gos/Labour%20GO%20on%20Int%20return.pdf — MEDIUM (normal due date LOW).
- **4. Consolidated / electronic registers:** Yes — integrated registers (Forms II & III) under G.O.Ms.No.23/2016 as amended by G.O.Ms.No.6/2019, covering TS S&E Rules 1990 among others; IT/ITeS exemption under S&E Act conditional on integrated registers + integrated returns (Deloitte note, https://www2.deloitte.com/content/dam/Deloitte/in/Documents/tax/in-tax-gbt-exemption-to-it-or-ites-establishments-under-telangana-shops-noexp.pdf — not retrievable). Self-certification with integrated return (G.O.Rt.No.106, Feb-2021). Exact titles of Forms II/III and express e-register permission — NOT CONFIRMED. — MEDIUM
- **5. Renewal:** Rules r.3(5): renewal for 1 yr or up to 3 yrs (Form III; penalty for late renewal) — HIGH (rules text). G.O.Ms.No.17 (Ease of Doing Business; c.2017–2019, exact date NOT CONFIRMED): exempted from s.3(2) (final RC within 1 day of upload) and from **renewal under s.4, subject to annual fee payment by 1 December each year** to extend validity. Source: https://simpliance.in/India/LEI/govt_notification/telangana/exemption-for-shops-and-establishment-towards-grant-and-renewal-of-registration-certificate-in-telangana-515 — MEDIUM.

### D. Other state periodic HR filings
- **1. Factories.** Telangana Factories Rules 1950 (adapted from the AP rules). Separate Form 20 / Form 21 filing was NOT CONFIRMED; annual factory returns are filed online inside the integrated return (below). For the 2019 annual return, the Factories Dept first said the due date was 31 Jan 2020 and then clarified that the online due date was **30 Apr 2020**. Sources: https://lexplosion.in/tag/telangana-labour-department (secondary snippet) — LOW-MEDIUM. Factories Dept site: tsfactories.cgg.gov.in; its integrated-return GO PDF returned 404.
- **2. Minimum Wages.** Telangana Minimum Wages Rules, **Form III** annual return. greytHR lists it as due 30 Jan. Source: https://greythr.com/wiki/telangana-compliance-calendar — LOW. Whether it is subsumed in the integrated return is NOT CONFIRMED; the integrated return covers the MW Act per G.O.Rt.248.
- **3. Integrated return.**
  - Origin: an "Ease of Doing Business — simplification procedure" order of 2016 by the LET&F (Labour) Dept created a common annual return (**Form I**) plus integrated registers. A 2019 order widened the list of laws.
  - Laws covered, per G.O.Rt.No.248 (2021 COVID extension to 30 Jun 2021):
    - CLRA
    - TS Shops & Establishments
    - ISMW
    - Minimum Wages
    - Payment of Wages
    - Motor Transport Workers
    - BOCW
    - Beedi & Cigar
    - TS Factories & Establishments (National, Festival & Other Holidays) Act 1974
    - Factories Act
    - ID (Central) Rules
    - BOCW Cess
    - Payment of Bonus Rules
    - Payment of Gratuity
    - Maternity Benefit (Mines & Circus) Rules
  - Due date: about **30 Apr** (greytHR lists 29 Apr). Online filing is mandatory (2021 notice).
  - Sources: https://www.simpliance.in/India/LEI/govt_notification/telangana/extension-of-due-date-for-furnishing-of-integrated-returns-under-various-labour-laws-in-telangana-1172 — MEDIUM-LOW; greytHR calendar — LOW.
- **4. CLRA.** Covered by the integrated return. A separate state-specific date is NOT CONFIRMED.
- **5. Other.** Under the Telangana National & Festival Holidays Act there is a **Form I holiday intimation** for the next year, due ~30 Dec (greytHR) — LOW.
- **OSH Code status:** draft only (LKS) — LOW-MEDIUM.

---

## AP — Andhra Pradesh

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Andhra Pradesh Tax on Professions, Trades, Callings and Employments Act, 1987 + Rules, 1987. Online registration, returns and payments run on https://myprofessiontax.apct.gov.in — HIGH (portal notice)
- **Frequency, due date and form:** **monthly**. Return **Form V** and payment by the **10th of the following month**. The rule structure matches TG (Rule 12 Form V, Rule 13 challan). Source: https://www.simpliance.in/professional-tax-detail/andhra-pradesh — MEDIUM. The apct.gov.in rules page was unreachable from here.
- **Slabs (monthly):** ≤ ₹15,000 nil; ₹15,001–20,000 ₹150; > ₹20,000 ₹200. — MEDIUM

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Andhra Pradesh Labour Welfare Fund Act, 1987 + Rules, 1988.
- **Contribution:** employee **₹30**, employer **₹70** per year.
- **Frequency and due date:** **annual**. Deduct at **31 December**, pay by **31 January**.
- **Applicability:** **1+** employees. Excludes those employed mainly in a managerial capacity, apprentices and part-timers.
- **Form:** **Form F**.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/andhra-pradesh (updated 3-Oct-2024) — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules:** Andhra Pradesh Shops and Establishments Act, 1988 (A.P. Act 20 of 1988) + A.P. S&E Rules, 1990. Act text: https://prsindia.org/files/bills_acts/acts_states/andhra-pradesh/1988/1988AP20.pdf — HIGH (authoritative host; not parsed). Also **A.P. (Issuance of Integrated Registration and Furnishing of Combined Returns under various Labour Laws by certain Establishments) Act, 2015 (Act 10 of 2015; in force 23-Apr-2015)** — schedules amended by G.O.Ms.No.1 dt 20-1-2017 and G.O.Ms.No.11 LET&F (Lab.I) dt 18-3-2019 — HIGH (GO fetched: https://simpliance.in/uploads/notifdocs/505/Amendment%20To%20Andhra%20Pradesh%20%28Issuance%20Of%20Integrated%20Registration%20And%20Furnishing%20Of%20Combined%20Returns%20Under%20Various%20Labour%20Laws%20By%20Certain%20Establishments%29%20Act%2C2015.pdf).
  - **A.P. S&E (Amendment) Act, 2025** (L.A. Bill 11 of 2025, introduced 19-Sep-2025; reported as Act No.17 of 2025, assent 15-Oct-2025; **commencement on a date to be notified** — commencement notification NOT CONFIRMED): daily hours 8 -> 10 (48/week) for shops (s.9) and establishments (s.16); spread-over 12 h; OT cap 144 h/quarter; rest after 6 h; women at night (before 6 AM/after 8:30 PM) with consent + safeguards (s.23); **new s.73(6): establishments with <20 workers exempt from the Act except registration/renewal and ss.9,10,16,17,19,23,24,25,30,31,32,47** (full Act applies once 20+). Source (AP Gazette Extra. Part IV-A No.11, 19-Sep-2025): https://prsindia.org/files/bills_acts/bills_states/andhra-pradesh/2025/Bill11of2025AP.pdf — HIGH for bill text; Act No./assent — LOW (search snippet only).
- **2. Registers / forms (A.P. S&E Rules 1990):** same scheme as Telangana (TS rules were adapted from AP 1990 Rules): Form X fines, XI deductions for damage/loss, XII advances, **XXII Register of Employment (r.29(1))**, **XXIII Register of Wages (r.29(2))**, XXIV weekly-holiday notice, **XXV leave register (r.29(6))**, XXVI appointment letter; Visit Book. Source: https://deccanlawhouse.com/products/form-22-form-xxii-register-of-employment-telangana-a-p-shops-and-establishments-rules-1990-rule-29-1 (secondary: names both TS & AP Rules 1990 r.29(1)) + TS rules text above — MEDIUM. Whether the registers apply to <20-worker establishments after the 2025 amendment depends on which section carries the register duty — NOT CONFIRMED.
- **3. Returns:** **Form B — Combined Annual Return** under s.4(6) of A.P. Act 10 of 2015, filed online; covers A.P. S&E Act 1988, MTW, CLRA, ISMW, BOCW, Gratuity, Beedi & Cigar, MW, Bonus, Child & Adolescent Labour, Boilers, Factories (12 Acts after 2019); calendar-year data (leave section refers to "calendar year to which this return relates"). Due date — NOT CONFIRMED. Source: G.O.Ms.No.11 dt 18-3-2019 (above) — HIGH for form/coverage. Form XXVII quarterly periodical return under r.33 (as in TS) — MEDIUM (inferred from common 1990 Rules; AP-specific confirmation NOT CONFIRMED).
- **4. Consolidated / electronic registers:** Integrated registration (Form A → certificate Form C, generated instantaneously online) and combined annual return (Form B) under Act 10 of 2015 — HIGH. Integrated/consolidated *registers* for AP S&E — NOT CONFIRMED.
- **5. Renewal:** G.O.Ms.No.7 dt 13-Feb-2019 exempted all S&E registered under s.3 from renewal; modified by G.O.Ms.No.15 dt 6-Jun-2019; c.Mar-2022 replaced by **auto-renewal on self-certification + online fee via Mee-Seva** (signed 22-3-2022 per lexplosion). Sources: https://simpliance.in/India/LEI/govt_notification/andhra-pradesh/exemption-for-shops-and-establishments-from-renewal-of-registration-in-andhra-pradesh-517 ; https://lexplosion.in/instead-of-exempting-shops-and-establishments-from-renewing-registrations-going-forward-employers-in-andhra-pradesh-to-auto-renew-se-licenses-through-mee-seva-portal/ — MEDIUM.

### D. Other state periodic HR filings
- **1. Factories.**
  - Pre-OSH: AP Factories Rules 1950. The Form 20 annual / Form 21 half-yearly numbers are NOT CONFIRMED this session. Factories Act returns were folded into the combined return from 2019 (below).
  - **Post-OSH (now in force):** the **Occupational Safety, Health and Working Conditions (Andhra Pradesh) Rules 2026**, G.O.Rt.No.146, dated 6 Aug 2026 (some sources give 7 Aug), in force on publication. They replace the state rules under 8 old Acts, including the Factories Act.
    - **Annual Return in Form XII by 1 Feb** to the Inspector-cum-Facilitator.
    - **Half-yearly return in Form XII(A) within 30 days after each calendar half-year.**
  - Sources: https://lexplosion.in/andhra-pradesh-government-notifies-the-occupational-safety-health-and-working-conditions-andhra-pradesh-rules-2026/ ; https://teamleaseregtech.com/updates/article/58562/andhra-pradesh-notifies-occupational-safety-health-and-working-conditions-rules-2026/ — MEDIUM-LOW (secondary; gazette not fetched). Whether these must be filed on a portal is NOT CONFIRMED.
- **2. Minimum Wages.** AP Minimum Wages Rules Form III (formerly due 31 Jan) is replaced by combined Form B (below) — MEDIUM-LOW.
- **3. Integrated return.**
  - Legal basis: the **AP (Issuance of Integrated Registration and Furnishing of Combined Returns under various Labour Laws by certain Establishments) Act 2015** (Act 10 of 2015, in force 23 Apr 2015).
  - Return: the **consolidated return in Form B**, filed online by **30 Jun** for the year ending 31 Mar. It replaces Form III (MW, formerly due 31 Jan) and Form XXV (CLRA, formerly due 15 Feb).
  - First Schedule, original Acts: AP Shops & Establishments, Motor Transport Workers, CLRA, ISMW, BOCW, Payment of Gratuity.
  - First Schedule, added by **G.O.Ms.No.11, LET&F (Lab.I), dated 18 Mar 2019**: Beedi & Cigar, Minimum Wages, Payment of Bonus, Child & Adolescent Labour, Boilers, Factories.
  - Sources: https://simpliance.in/uploads/notifdocs/505/ (GO text extracted from PDF) — **HIGH** for the list of Acts; Form B due date and period from https://simpliance.in/India/LEI/govt_notification/andhra-pradesh/the-andhra-pradesh-issuance-of-integrated-registration-and-extent-and-furnishing-of-combined-returns-under-commencement-various-labour-laws-by-certain-establishments-act-2015-132 — MEDIUM-LOW. greytHR lists 29 Jun.
  - How Form B interacts with the new OSH Rules annual return (Form XII) is NOT CONFIRMED. Form B probably still applies to the non-OSH Acts.
- **4. CLRA.** Form XXV is folded into Form B (above). CLRA state rules are superseded by the AP OSH Rules 2026 (TeamLease) — MEDIUM-LOW.
- **5. Other.** None confirmed.

---

## DL — Delhi (NCT)

### A. Professional Tax (PT)
- **Levies: N.** No Delhi PT statute appears in any state-wise compilation. Zoho lists 21 states + 1 UT that levy PT, and Delhi is not among them (https://www.zoho.com/in/payroll/academy/taxes-and-compliance/professional-tax-rules.html). — MEDIUM (absence of evidence)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Bombay Labour Welfare Fund Act, 1953 as extended to Delhi + Delhi Labour Welfare Fund Rules, 1997.
- **Contribution:** employee **₹0.75**, employer **₹2.25** per half-year.
- **Frequency and due dates:** **half-yearly**. Deduct at **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** **5+** employees. Excludes managerial or supervisory staff drawing more than ₹2,500 a month.
- **Form:** **Form A**.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/delhi (updated 3-Oct-2024) — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules:** Delhi Shops and Establishments Act, 1954 + Delhi S&E Rules, 1954 (rules under s.47). Amendments: Delhi S&E (Amendment) Rules, 2021 (draft No.15(52)/Lab/2021/3618-3626 dt 24-Sep-2021; final notified 15-Nov-2021, No.15(52)/Lab/2021/4506): online registration within 90 days of commencement (r.3), certificate auto-generated online in Form C (r.4), changes online within 30 days (r.6), fee Schedules I & II deleted (no fee), **Form G substituted, Forms H & I omitted**. Delhi S&E (Amendment) Rules, 2025 (F.No.Lab.-L016/1/2025/4617-4623 dt 9-Dec-2025): **r.5 (renewal) omitted**. Employee-count threshold for registration — none found (NOT CONFIRMED). Sources: rules text on official site https://labour.delhi.gov.in/labour/delhi-shops-establishments-rules-1954-0 — HIGH (but shows pre-2021 consolidated text); 2021 amendment https://www.legitquest.com/act/delhi-shops-and-establishments-amendment-rules-2021/A495 (draft text), https://www.teamleaseregtech.com/updates/article/15362/delhi-shops-and-establishments-amendment-rules-2021 , https://www.scconline.com/blog/post/2021/09/28/delhi-shops-and-establishments-amendment-rules-2021/ — MEDIUM; 2025 amendment https://ascent-hr.com/notification/delhi-shops-establishments-amendment-rules-2025/ , https://www.dailypioneer.com/2025/state-editions/delhi-govt-scraps-mandatory-renewal-of-shop-registration.html — MEDIUM.
- **2. Registers / forms:**
  - Current (post-15-Nov-2021): **Form G — Register of Wages / Deductions / Overtime / Advances** (consolidated; Mondaq says it also records attendance) under r.14(1) (bound, serially numbered pages, r.14(1A)). Forms H (Register of Employment and Remuneration) and I (Register of Leave) **omitted**. — MEDIUM (secondary concordant; official consolidated post-2021 text NOT fetched).
  - Pre-2021 text (official site): Form G register of employment & wages; Form H alternative register for uniform hours + Form I leave record; Form 1 (or "J" — rendered "1" on site) weekly-holiday notice (r.14(2)); Form K close-day/working hours/rest interval notice (r.14(6)); Form E close-day choice (r.8); fines & deductions limits r.9 (no form specified). — HIGH (official site text) but partly superseded.
  - Attendance entries same day; other entries by mid-day next day (r.14(3)); preserve till end of following year (r.14(5)); r.14A: Govt may accept employer's own register containing Form G/H/I particulars. — HIGH
- **3. Annual return:** None under Delhi S&E Act/Rules (no return provision in rules text). Source: official rules page above — MEDIUM-HIGH (absence-of-provision finding).
- **4. Consolidated / electronic registers:** Form G (2021) is a single consolidated wages/deductions/OT/advances register — MEDIUM. r.14A permits alternative employer registers on approval — HIGH. Express electronic-maintenance provision — NOT CONFIRMED. Delhi adoption of a state ease-of-compliance consolidated register notification for S&E — NOT CONFIRMED.
- **5. Renewal:** Old r.5: renewal every 21 years (Form L). **Abolished** by Delhi S&E (Amendment) Rules 2025 (9-Dec-2025) — registration now one-time. — MEDIUM (secondary; gazette not fetched).

### D. Other state periodic HR filings
> Note: greytHR calendars appear to list "deadline minus one day" (e.g. 14 Jan for a 15 Jan deadline). The dates below give both readings.

- **1. Factories (Delhi Factories Rules 1950).**
  - **Form 21** annual return, due ~**15 Jan** (greytHR lists 14 Jan).
  - **Form 22** half-yearly return for the half-years ending 30 Jun and 31 Dec, due ~**15 Jul / 15 Jan** (greytHR lists 14 Jul / 14 Jan).
  - Sources conflict. One checklist (carajput) has Form 20 annual by 15 Jan and Form 21 half-yearly by 15 Jul. A forum post has Form 21 half-yearly and Form 22 annual.
  - Sources: https://www.greythr.com/wiki/delhi-compliance-calendar/ ; https://deskera.com/blog/form-22 — LOW. Rule text not fetched.
  - Online filing: NOT CONFIRMED. Delhi uses the e-District portal for CLRA licences.
  - greytHR also lists a "List of Holidays" intimation to the Inspector by ~30 Dec — LOW.
- **2. Minimum Wages.** Delhi Minimum Wages Rules, **Form III** annual return, due ~31 Jan / 1 Feb (greytHR lists 30 Jan) — LOW.
- **3. Integrated return.** None found for Delhi (NOT CONFIRMED that none exists).
- **4. CLRA (Contract Labour (R&A) Delhi Rules 1972).**
  - Contractor **Form XXIV**, half-yearly to the Licensing Officer, due ~30 Jul / 30 Jan.
  - Principal employer **Form XXV**, annual to the Registering Officer, due ~**15 Feb**.
  - Source: greytHR Delhi calendar — LOW. The dates match the central pattern.
- **5. Other.** Delhi Industrial Employment (National & Festival Holidays) Rules: **Form A** annual holiday intimation, due ~30 Dec (greytHR) — LOW.
- **OSH Code status:** draft only (LKS, Khanna) — MEDIUM-LOW. The Delhi Factories Rules 1950 still apply.

---

## HR — Haryana

### A. Professional Tax (PT)
- **Levies: N.** Haryana is not in any PT compilation. — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Punjab Labour Welfare Fund Act, 1965 as applicable to Haryana (s.9-A) + Punjab LWF Rules, 1966.
- **Contribution (monthly):**
  - Employee: **0.2% of salary or wages, capped at ₹35 a month from January 2026**. Earlier caps: ₹34 from Jan-2025, ₹31 from Jan-2023, ₹25 from Apr-2019.
  - Employer: **twice the employee amount**, so at most ₹70; total at most ₹105.
  - Source: official page https://hrylabour.gov.in/content/cms/MTU — HIGH. It carries a rate-history table, so the cap is revised each January.
- **Frequency and due date:** **monthly**. Simpliance gives the due date as the **last day of the month**, and says quarterly, half-yearly or yearly payment is accepted in certain cases. Source: https://www.simpliance.in/labour-welfare-fund-detail/haryana — LOW. Payment is online through hrylabour.gov.in.
- **Applicability:** **10+** employees (Simpliance). — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules:** **Haryana Shops and Commercial Establishments Act, 1958** (Punjab Act 15 of 1958 as applied to/adapted by Haryana — referred to as "Haryana S&CE Act, 1958" in the 2025 Bill) + Punjab S&CE Rules, 1958 as applicable in Haryana. **Haryana S&CE (Amendment) Ordinance 2025 (Ord. No.4 of 2025, 12-Nov-2025)** replaced by Bill No.39-HLA of 2025 (Haryana Gazette Extra., 18-Dec-2025; deemed in force 12-Nov-2025); reported enacted as Haryana S&CE (Amendment) Act 2025 (notification Leg. 14/2026, ~5-Feb-2026 — LOW, single secondary https://ascent-hr.com/?p=37733). Key changes (Bill text): new s.1(5) — **Act applies only to establishments with 20+ workers; <20 workers only s.13A (online intimation within 1 month → Basic Information Performa (BIP) ID)**; new s.13 — online registration within 1 month for 20+; RC issued within Right to Service Act timeline; RC **valid until amended/cancelled on closure/revoked**; change intimation within 7 days; closure intimation within 30 days; penalties replace imprisonment (false entries in records/registers Rs 5,000–25,000 for repeat). EY: daily hours 9 -> 10 (48/week), OT 50 -> 156 h/quarter at 2x, rest after 6 h, mandatory appointment letter (with photo) and ID card. Sources: https://prsindia.org/files/bills_acts/bills_states/haryana/2025/Bill39of2025HR.pdf (also https://haryanaassembly.gov.in/wp-content/uploads/2025/12/Bill-39-eng.pdf) — HIGH for bill text; https://www.ey.com/content/dam/ey-unified-site/ey-com/en-in/alerts-hub/11/amendments-in-the-haryana-shops-and-commercial-establishments-act-1958.pdf — MEDIUM; https://www.taxmann.com/post/blog/haryana-assembly-passes-haryana-shops-and-commercial-establishments-amendment-bill — MEDIUM.
- **2. Registers / forms (Punjab S&CE Rules 1958):** Form A — intimation under s.10(2)(i) (r.3); Form B — notice under s.20(1) exhibited in establishment (r.4); **Form C — Register of employees (r.5(1))**; **Form D — Register of wages (r.5(2))**; **Form E — Register of deductions (r.5(3))**; Form F — registration/renewal statement & certificate (r.13(1)); Form G — notice of change (r.13(2)); Form H — register of establishments (Inspector, r.13(4)); Form I — communicable-disease certificate (food establishments, r.19(3)). Registers in English and Punjabi (Haryana practice: Hindi/English — NOT CONFIRMED), bound and page-numbered (r.6), preserved 2 years (r.7). No separate leave/fines/OT register form in rules text. Source: https://www.legitquest.com/act/the-punjab-shops-and-commercial-establishments-rules-1958/8907 (Punjab text; Haryana-specific amendments to forms NOT CONFIRMED) — MEDIUM for Haryana. Post-2025 prescribed forms for registration/intimation/appointment letter/ID card — NOT CONFIRMED (rules "as may be prescribed").
- **3. Annual return:** **Single Integrated Annual Return** under all labour laws, online on www.hrylabour.gov.in (Labour Dept orders Endst. No.3254-55 dt 16-Jun-2016, clarified Jan-2019); covers Punjab/Haryana S&CE Act 1958, Factories, CLRA, BOCW + Cess, ISMW, PW, ID, MW, ESI, MB, Bonus, Gratuity. Due date & form number — NOT CONFIRMED (Factories leg reportedly 15-Jan, Form 21, via draft). Sources: https://greythr.com/blog/online-submission-single-annual-return-under-all-labour-laws-haryana ; https://simpliance.in/India/LEI/govt_notification/haryana/online-submission-of-single-annual-return-under-all-labour-laws-in-haryana-465 — MEDIUM.
- **4. Consolidated / electronic registers:** Single integrated annual return (above) — MEDIUM. Haryana notification of consolidated/electronic registers for S&CE — NOT CONFIRMED.
- **5. Renewal:** Already exempted by Haryana Labour Dept **Notification No.11/23/2018-4Lab dt 21-Nov-2018** (s.28 power): all commercial establishments/establishments exempt from periodic renewal under s.13(2)(ii) — HIGH (notification fetched: https://www.indianlabourarchives.org/bitstream/20.500.14121/3076/1/Exemption_from_Periodic_Renewal_of_Registration_Certificates_of_Commercial_Establishments.pdf). Now statutory: RC valid until amended/cancelled/revoked (s.13(3) as substituted, Bill 39-HLA of 2025; EY: "lifetime validity") — HIGH (bill text) / enactment MEDIUM.

### D. Other state periodic HR filings
- **1. Factories (Punjab Factory Rules 1952 as applied in Haryana).**
  - greytHR lists a **Form 22** annual return due ~31 Jan (shown as 30 Jan) and a **Form 21** half-yearly return due ~15 Jul (shown as 14 Jul) — LOW.
  - A 30 Aug 2019 draft amendment proposed a unified single annual return on the Shram Suvidha portal "on or before 15th January every year in Form 21". It is unclear whether this was finalised (snippet via lexplosion) — NOT CONFIRMED.
  - Source: https://www.greythr.com/wiki/haryana-compliance-calendar/ (2026-27) — LOW.
- **2. Minimum Wages.** Haryana (Punjab) Minimum Wages Rules 1950, **Form III** annual return, due ~31 Jan (greytHR) — LOW.
- **3. Integrated return.** The **Single Integrated Annual Return** was set up by an order of 16 Jun 2016 (Endst. No. 3254-55) and clarified/mandated in Jan 2019. It is filed **online only at www.hrylabour.gov.in**; a state services page also mentions the Shram Suvidha portal.
  - Acts covered: Punjab Shops & Commercial Establishments Act 1958, Factories, CLRA, BOCW, BOCW Cess, ISMW, Payment of Wages, ID Act, Minimum Wages, ESI, Maternity Benefit, Payment of Bonus, Payment of Gratuity.
  - Due date: about **30 Jan** (greytHR lists 29 Jan) — LOW.
  - Sources: https://simpliance.in/India/LEI/govt_notification/haryana/online-submission-of-single-annual-return-under-all-labour-laws-in-haryana-465 ; https://www.greythr.com/blog/online-submission-single-annual-return-under-all-labour-laws-haryana/ — MEDIUM-LOW. Whether it formally waives the separate Form 22 / Form III / Form XXV is NOT CONFIRMED.
- **4. CLRA.** Punjab Contract Labour (R&A) Rules 1973 apply. State-specific dates are NOT CONFIRMED; assume the baseline. CLRA is covered by the integrated return.
- **5. Other.** Punjab Industrial Establishments (National & Festival Holidays and Casual & Sick Leave) Act: **Form A** annual holiday return, due ~30 Dec (greytHR) — LOW.
- **OSH Code status:** draft only. Revised drafts were reported in May 2026 (Khanna tracker) — MEDIUM-LOW.

---

## UP — Uttar Pradesh

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence; not in any compilation)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** No UP LWF Act was found; Simpliance shows "No Records Found" (https://www.simpliance.in/labour-welfare-fund-detail/uttar-pradesh). — LOW-MEDIUM (absence)

### C. Shops & Establishments
- **1. Act / Rules:** Uttar Pradesh Dookan Aur Vanijya Adhishthan Adhiniyam, 1962 (UP Shops & Commercial Establishments Act 1962) + UP Dookan Aur Vanijya Adhishthan Niyamavali, 1963 (in force 1-5-1963). **UP Dookan Aur Vanijya Adhishthan (Sanshodhan) Adhiniyam, 2025** (replaced the 2025 Ordinance; effective 19-Nov-2025; Act number NOT CONFIRMED): **applicability & registration limited to establishments with 20+ employees** (smaller may opt in — India Briefing); online registration via portal within 6 months, auto-grant; definitions widened (medical practitioners, architects, service/delivery platforms; outsourced workers = employees); daily hours 8 -> 9 (48/week), max 11 h/day incl. OT, OT 144 h/quarter at 2x; women 7 PM–6 AM with consent/safeguards; mandatory appointment letter; fines Rs 2,000/10,000; 15-day improvement notice before prosecution. Sources: https://knowledge.dlapiper.com/dlapiperknowledge/globalemploymentlatestdevelopments/2026/uttar-pradesh-enhances-coverage-for-employees-and-applicability-limits-for-registration-of-commercial-establishments ; https://www.india-briefing.com/news/uttar-pradesh-shops-establishments-update-employer-guide-42642.html/ ; https://ascent-hr.com/notification/uttar-pradesh-shops-commercial-establishments-amendment-ordinance-2025/ — MEDIUM (concordant secondaries; gazette not fetched).
- **2. Registers / forms (Niyamavali 1963):** Form A — notice of weekly close day (r.7); Form B — notice specifying close days/public holidays (r.8(1), acknowledged by Inspector before end-January each year); Form C — notice of weekly holiday (r.9); **Form D — Register of deductions from wages (r.12(8))**; **Form E — Register of fines & realisation (r.12(8))**; Form F — maternity notice of absence (r.17); **Form G — Register of Attendance & Wages (incl. OT, advances) (r.18(1)(a))**; **Form H — Register of Leave (earned/sick/casual) (r.18(1)(b))**; Form K register of establishments (Inspector, r.2-A(1)); Form L registration application (r.2-A(2), s.4-B(1)); Form N notice of change (r.2-A(6)); Form O loss of RC (r.2-A(10)). Source: https://www.deskera.com/blog/uttar-pradesh-shops-and-commercial-establishments-act (secondary); rules text https://vlex.in/vid/uttar-pradesh-shops-and-545671266 (not fetched) — MEDIUM. Post-2025 register changes — NOT CONFIRMED.
- **3. Annual return:** No annual return under UP S&E Act/Rules found; only annual Form B close-days notice (by end-January). — LOW/MEDIUM (absence; NOT CONFIRMED from official text).
- **4. Consolidated / electronic registers:** Form G is a combined attendance-cum-wages register — MEDIUM. UP state notification adopting consolidated/electronic registers for S&E — NOT CONFIRMED.
- **5. Renewal:** Earlier rules: registration for 5 financial years, renewable (up to 10). s.4-C (as amended) reportedly makes RC valid for the duration the establishment exists (https://lawgist.in/states/uttar-pradesh/uttar-pradesh-dookan-aur-vanijya-adhishthan-adhiniyam-1962/4C — LOW); 2022 draft Navam Sanshodhan Niyamavali — one-time fee, omit validity/renewal rules (https://lexplosion.in/uttar-pradesh-govt-issues-draft-uttar-pradesh-dookan-aur-vanijya-adhishthan-navam-sanshodhan-niyamawali-2022-owners-of-shops-or-commercial-establishment-to-apply-for-registration-by-paying-one-ti/ — LOW; final status NOT CONFIRMED). Net: renewal likely no longer required — NOT CONFIRMED officially.

### D. Other state periodic HR filings
- **1. Factories.**
  - Pre-OSH: UP Factories Rules 1950. Annual / half-yearly form numbers NOT CONFIRMED.
  - **Post-OSH:** the **Uttar Pradesh Occupational Safety, Health and Working Conditions Rules 2026** (Notification No. 962/XXXVI-03-2026-1903305 per TeamLease) were notified and published in the Gazette on **27 Aug 2026** and took effect on publication. They supersede the UP Factories Rules, UP Contract Labour Rules, ISMW Rules, Welfare Officers Rules, Safety Officers Rules, BOCW Rules and the earlier UP OSH Rules 2022, and provide for electronic compliance via the Labour Dept portal. Sources: https://teamleaseregtech.com/updates/article/59333/uttar-pradesh-occupational-safety-health-and-working-conditions-rules- ; https://ascent-hr.com/notification/occupational-safety-health-working-conditions-uttar-pradesh-rules-2026-notified/ — MEDIUM (two secondaries agree). The LKS tracker still shows UP as draft, which conflicts.
  - Returns: the **draft** (23 Mar 2026) had an **annual return by 31 Jan**, plus a **half-yearly return by 31 Jul (Jan–Jun) only for Major Accident Hazard installations**. No form number was given. Source: https://lexplosion.in/uttar-pradesh-government-proposes-draft-uttar-pradesh-occupational-safety-health-and-working-conditions-rules-2026/ — LOW. The final-rule form number and due date are NOT CONFIRMED.
- **2. Minimum Wages.** UP Minimum Wages Rules annual return — NOT CONFIRMED. Assume the baseline: Form III by 1 Feb.
- **3. Integrated return.** None confirmed. The UP labour portal **uplabouracts.in** (MoLE state page) handles registration, renewal, **annual return submission** and inspection reports under 7 labour Acts. Source: https://labour.gov.in/uttar-pradesh-0 — MEDIUM (authoritative, undated). Which Acts and forms are covered is NOT CONFIRMED.
- **4. CLRA.** UP Contract Labour Rules are superseded by the UP OSH Rules 2026 (secondary) — MEDIUM. The new contractor / principal-employer return forms are NOT CONFIRMED.
- **5. Other.** None confirmed.

---

## GJ — Gujarat

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Gujarat State Tax on Professions, Traders, Callings and Employments Act, 1976, now styled the Gujarat Panchayats, Municipalities, Municipal Corporations and State Tax on Professions … Act, 1976, + Rules, 1976. Within their limits, municipal corporations such as Surat (SMC) and Ahmedabad (AMC) collect it.
- **Frequency (depends on headcount):**
  - **More than 20 employees:** **monthly** return in **Form 5**, filed and paid within **15 days of month-end** (Rule 11).
  - **20 or fewer employees in the preceding year:** pay **quarterly** within 15 days of quarter-end, and file an **annual return in Form 5-AA** within 15 days after the year ends (Rule 11A).
  - Form 5-B is the application for permission to file annually. Forms 5-C and 5-CC are consolidated returns (monthly / annual) for multiple places of work.
  - Sources: Rules PDF https://commercialtax.gujarat.gov.in/vatwebsite/download/rule/Ptrules.pdf (rule text as surfaced by search; older consolidation) — MEDIUM-HIGH; official SMC FAQ https://www.suratmunicipal.gov.in/FAQs/ProfessionTax (updated 15-Jan-2025: "20 or more staff" monthly within 15 days; "fewer than 20" quarterly) — HIGH. The two sources draw the line at exactly 20 employees differently, so verify.
- **Slabs (monthly):** **≤ ₹12,000 nil** (State Government notification of 01-04-2022); **> ₹12,000 ₹200**. Late payment interest is 12% a year. Source: SMC FAQ — HIGH; Simpliance concurs — https://www.simpliance.in/professional-tax-detail/gujarat

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Gujarat Labour Welfare Fund Act, 1953 + Labour Welfare Fund (Gujarat) Rules, 1962.
- **Contribution:** employee **₹6**, employer **₹12** per half-year.
- **Frequency and due dates:** **half-yearly**. Deduct at **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** **10+** employees. Excludes managerial or supervisory staff drawing more than ₹3,500 a month.
- **Form and portal:** **Form A-1**; registration and payment at glwbcrm.gujarat.gov.in.
- Sources: https://www.simpliance.in/labour-welfare-fund-detail/gujarat (updated 3-Oct-2024) — LOW-MEDIUM. The official https://glwb.gujarat.gov.in/ (updated 3-Oct-2026) links the payment portal but publishes no rates. The due dates were not confirmed against GLWB.

### C. Shops & Establishments
- **1. Act / Rules:** Gujarat Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2019 (Guj. 4 of 2019; replaced Bombay S&E Act 1948 in Gujarat) + **Gujarat S&E (RE&CS) Rules, 2020** (made under s.39; draft published 3-Oct-2019; final notification date NOT CONFIRMED). Rules text: https://www.legitquest.com/act/gujarat-shops-and-establishments-regulation-of-employment-and-conditions-of-service-rules-2020/D118 — HIGH (rules text). **Amendment:** Guj. Ordinance 3 of 2025 (in force 16-Dec-2025) replaced by Gujarat Bill No.1 of 2026 (Gujarat Govt Gazette Extra. Part V, 11-Feb-2026; deemed in force 16-Dec-2025): s.1(3) & s.7 "ten" -> "twenty" — **Act/registration applies to establishments with 20+ workers; <20 file intimation (s.7)**; daily hours 9 -> 10, rest after 6 h (s.12); OT 125 -> 144 h/quarter (s.15); women night work 9 PM–6 AM with consent + facilities (s.13). Source: https://prsindia.org/files/bills_acts/bills_states/gujarat/2026/Bill1of2026GJ.pdf — HIGH for bill text; enactment/assent — NOT CONFIRMED. Ordinance: https://prsindia.org/files/bills_acts/bills_states/gujarat/2025/Ord3of2025GJ.pdf ; https://www.scconline.com/blog/post/2025/12/26/gujarat-shops-and-establishments-amendment-ordinance-2025-explained/ — MEDIUM.
- **2. Registers / forms (Rules 2020):**
  - **Form P — Muster-Roll cum Wages Register (r.26(1))**; proviso: employers keeping Wages Register **Form B** and Muster Roll **Form D** under the central **Ease of Compliance to Maintain Registers under various Labour Laws Rules, 2017** may use those instead. OT entries immediately after OT (r.26(2)); entries authenticated digitally or manually. — HIGH
  - **Form N — Leave Book** (r.17; "free to maintain Leave Book ... either manually or electronically"); Form O — notice of workers whose carried-forward leave reached maximum (r.18). — HIGH
  - Form J — woman worker's night-work consent (r.11); Form K — weekly holiday notice per shift (r.12); Form L — shift schedule (r.14(i)); **Form M — Identity Card** (r.16). — HIGH
  - Employer filings: A registration (10+ — now 20+) (r.3); D intimation (<threshold) (r.6); G notice of change within 30 days (r.9(1)); H/I closure (r.10); R compounding (r.30(1)); S managerial persons (r.32); T confidential-position persons (r.33). Authority-side: B certificate, C register of establishments, E intimation receipt, F register of intimations. — HIGH
- **3. Annual return:** **Form Q — Annual Return (r.27)**, for calendar year ending 31-Dec, to Inspector **within one month (i.e. by 31 January)**, online where available. — HIGH (rules text)
- **4. Consolidated / electronic registers:** Yes — r.26(1) proviso expressly accepts central Ease of Compliance Rules 2017 Forms B (wages) and D (muster roll) in lieu of Form P; Form P itself is combined muster-cum-wages; digital authentication allowed (r.26(2)); Leave Book manual or electronic (r.17). — HIGH
- **5. Renewal:** None — 2019 Act/2020 Rules contain no renewal/validity provision (one-time registration; changes via Form G, closure via H/I). Source: rules text above — MEDIUM (absence-of-provision; corroborate with Act s.6). Secondary: https://www.myndsolution.com/glossary/registrationrenewalamendment-under-shops-commercial-est-act/ (Gujarat abolished renewal) — LOW.

### D. Other state periodic HR filings
- **1. Factories.**
  - **Current regime:** the **Gujarat Occupational Safety, Health and Working Conditions Rules 2025**, notification GR/2025/76/FAC/142021/68396/M3 dated 6 Jun 2025, Gazette Extra No. 87 of 12 Jun 2025. They are in force from the OSH Code commencement date (r.2(4)), i.e. 21 Nov 2025. They were made in supersession of:
    - Gujarat Factories Rules 1963
    - Contract Labour (R&A) (Gujarat) Rules 1972
    - Gujarat BOCW Rules 2003
    - Gujarat Motor Transport Workers Rules 1965
    - Beedi & Cigar Rules 1968
    - ISMW (Gujarat) Rules 1981
  - **r.37 annual return:** "Every employer of an establishment shall send annually a return … in duplicate in **Form 23**". It must reach the Inspector-cum-Facilitator **not later than 1 Feb** following the calendar year, with copies to DG Labour Bureau and DGFASLI, "electronically or otherwise". No half-yearly return was seen in the rules text.
  - Sources: https://employmentlaw.lkslaw.com/states/gujarat/osh (verbatim gazette text, law firm) and https://www.taxclue.in/blog/gujarat-osh-rules-2025-registers-records-returns-and-the-inspector-cum-facilitator/ — **MEDIUM-HIGH** (two secondaries agree; gazette PDF not fetched directly).
  - Pre-OSH (Gujarat Factories Rules 1963) forms are no longer relevant for periods after 21 Nov 2025. Their numbers were not verified.
- **2. Minimum Wages.** Gujarat Minimum Wages Rules **Form III** — Gujarat-specific date NOT CONFIRMED. Assume ~1 Feb. Gujarat Wage Code rules are reported final (LKS lists all 4 codes notified), so a Wage-Code annual return may now apply instead — NOT CONFIRMED.
- **3. Integrated return.** Under the Gujarat CLRA Rules 1972 amendment (GHR/2021/81/LBL/192020/510460/M3, 2021), the half-yearly **Form XXIV** and annual **Form XXV** were replaced by a **consolidated annual return in Annexure V** under the "Self-certification cum consolidated annual return scheme". It is filed online **1 Jan – 1 Mar** each year. Source: https://www.simpliance.in/India/LEI/govt_notification/gujarat/the-contract-labour-regulation-and-abolition-gujarat-amendments-rules-2021-1236 — MEDIUM-LOW. This is now probably superseded by the Gujarat OSH Rules 2025, since the CLRA Gujarat Rules were superseded — NOT CONFIRMED.
- **4. CLRA.** Superseded by the Gujarat OSH Rules 2025 (above). Contractor/principal-employer returns under the new rules were NOT CONFIRMED.
- **5. Other.** None confirmed.

---

## WB — West Bengal

### A. Professional Tax (PT)
- **Levies on salaried: Y.** West Bengal State Tax on Professions, Trades, Callings and Employments Act, 1979 + Rules, 1979.
- **Payment:** **monthly**, by the **21st of the following month**. Tax deducted for March is paid before the annual return. Source: https://www.taxaj.com/learn/?p=90736 — MEDIUM-LOW
- **Return:** **annual return, Form III** (Rule 12). The base due date is **30 April** for e-filing (paper copy by 15 May), inferred from Directorate of Commercial Taxes extension notices (e.g. 2021: "extended from the earlier date of 30th April"). Source: https://simpliance.in/India/LEI/govt_notification/west-bengal/extension-of-due-date-for-filing-annual-returns-under-the-west-bengal-state-tax-on-professions-trades-callings-and-employments-act-1979-701 — MEDIUM. Simpliance's current page shows 15 May — LOW. Deadlines are extended most years.
- **Slabs (monthly) — changed mid-year, effective-date them:**
  - **Up to 30-Sep-2026:** ≤ ₹10,000 nil; ₹10,001–15,000 ₹110; ₹15,001–25,000 ₹130; ₹25,001–40,000 ₹150; > ₹40,000 ₹200.
  - **From 1-Oct-2026** (Notification **1607-F.T. dated 16-Sep-2026**, under the Act as amended by the WB Finance Act 2026; draft 1407-F.T. of 18-Aug-2026): ≤ ₹20,000 nil; ₹20,001–30,000 ₹100; ₹30,001–50,000 ₹140; ₹50,001–1,00,000 ₹170; > ₹1,00,000 **₹208**.
  - Rates for professionals and traders change from 1-Apr-2027.
  - Sources: https://www.taxaj.com/learn/?p=90736 ; https://www.praansconsultech.com/gazette-details/west-bengal-introduces-major-changes-to-professional-tax-slabs ; https://www.dcpsol.com/west-bengal-professional-tax-slabs-revised-2026-notification-1607-ft ; https://www.simpliance.in/professional-tax-detail/west-bengal (already shows the new table) — MEDIUM (four concordant secondaries; gazette not fetched)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** West Bengal Labour Welfare Fund Act, 1974 + Rules, 1976.
- **Contribution:** employee **₹3**, employer **₹30** per half-year (Simpliance, updated 17-Jun-2025). — LOW (single secondary). Verify the employer amount with WBLWB, because older compilations show a lower figure (NOT CONFIRMED).
- **Frequency and due dates:** **half-yearly**. Deduct at **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** **10+** employees. Excludes managerial or supervisory staff drawing more than ₹1,600 a month.
- **Form:** **Form D** (statement of contributions).
- Source: https://www.simpliance.in/labour-welfare-fund-detail/west-bengal. The board's own site (wblwb.org) currently shows unrelated spam links and no rate data, so it was not used.

### C. Shops & Establishments
- **1. Act / Rules:** West Bengal Shops and Establishments Act, 1963 + WB S&E Rules, 1964 (Notification 2911-IR dt 13-07-1964). Recent threshold/hours amendment — NOT CONFIRMED (none found). Sources: rules index https://wbxpress.com/west-bengal-shops-establishments-rules-1964/ ; rules text (r.1–16) https://vlex.in/vid/west-bengal-shops-and-545673870 — MEDIUM-HIGH (secondary hosts of rules text); Act PDF https://www.wb.gov.in/acts/act_labour_shops_and_establishments.pdf (official; download timed out).
- **2. Registers / forms (Rules 1964):**
  - **Form I or Form I(1) — Register of hours of work and rest intervals (r.13; employer's choice)** — HIGH (vlex rules text).
  - Form G — notice of weekly closure (r.11); Form H — notice of weekly holidays (r.12; display, keep 1 yr) — HIGH.
  - **Form J — Leave register (r.21)**; **Form M — Pay register (r.30)**; **Form U — Register of overtime (r.40)** (r.38 OT notice); **Form W — Register of employees (r.52)**; **Form X — Letter of appointment (r.53)**; r.54 maintenance & preservation of records; r.55 special registers. Rule titles from wbxpress index (MEDIUM); form letters from WB Labour Commissionerate "General Inspection note under WB S&E Act 1963" (official, seen only via search snippet: https://lc.wb.gov.in/sites/default/files/contentpdf/1455098290General%20Inspection%20note%20under%20West%20Bengal%20Shops%20%26%20Establishments%20Act%2C%201963.pdf) + Deskera (https://www.deskera.com/blog/west-bengal-form-w-register-of-employees/ , https://www.deskera.com/blog/west-bengal-register-form-j-register-of-leave/) — MEDIUM. Keka's conflicting letters (Form D OT, Form F leave) — discard (LOW).
  - Form K — maternity benefit claim notice (Deskera) — LOW.
- **3. Annual return:** None found under WB S&E Act/Rules (vendor: "no specific annual return mandate"). — LOW (NOT CONFIRMED from official text).
- **4. Consolidated / electronic registers:** WB notification adopting consolidated/electronic registers for S&E — NOT CONFIRMED. Form I/I(1) choice is the only alternative-format provision found.
- **5. Renewal:** Act s.16(6)/r.6: renewal in Form D; certificate validity 3 years (greytHR quoting Act: "within thirty days after the expiry of three years from the date of issue or renewal") — MEDIUM. Notification dt **28-Dec-2018**: registered S&E **deemed renewed** after expiry (renewal requirement exempted) — https://simpliance.in/India/LEI/govt_notification/west-bengal/license-under-west-bengal-shops-and-establishment-act-1963-is-deemed-to-be-renewed-after-expiry-of-license-period-444 ; https://lexplosion.in/?p=32459 — MEDIUM; but Labour Commissionerate RTPS timelines still list renewal (https://lc.wb.gov.in/important-notification/general-timelines-deatils) — conflicting ⇒ overall LOW/MEDIUM.

### D. Other state periodic HR filings
- **1. Factories (West Bengal Factories Rules 1958).**
  - **Form 22** annual return. greytHR lists it due ~31 Jan (shown as 30 Jan).
  - **Form 23** half-yearly return, due ~15 Jul (shown as 14 Jul).
  - **Form 23-A** *quarterly* return, due ~15th of the month after each quarter (Oct/Jan/Apr/Jul). WB is unusual in requiring a quarterly return.
  - The WB Directorate of Factories page (labour.wb.gov.in/node/113) lists "Form-22: Annual Return; Form-23: Half Yearly Return; Form-23(A): Quarterly Return". This was seen in a search snippet; the page did not render on fetch.
  - Sources: https://labour.wb.gov.in/node/113 (official, snippet only) — MEDIUM for the form numbers; https://greythr.com/wiki/west-bengal-compliance-calendar — LOW for the dates.
- **2. Minimum Wages.** WB Minimum Wages Rules **Form III** annual return, due ~31 Jan (greytHR) — LOW.
- **3. Integrated return.** The **Single Annual Integrated Online Return** was introduced by notification in the Kolkata Gazette of **28 Aug 2017**. It covers CLRA, BOCW, Motor Transport Workers, ISMW, Minimum Wages and the Factories Act, and is filed online at **wblc.gov.in**. Due date and period NOT CONFIRMED. Source: https://simpliance.in/India/LEI/govt_notification/west-bengal/single-annual-integrated-online-return-under-various-labour-laws-290 — MEDIUM-LOW. Whether it replaces the individual forms is NOT CONFIRMED.
- **4. CLRA.** WB CLRA Rules — NOT CONFIRMED. Assume the baseline. CLRA is covered by the integrated return.
- **5. Other.** WB Payment of Wages Rules **Form IV** annual return, due ~31 Jan (greytHR) — LOW.
- **OSH Code status:** NOT CONFIRMED. No WB OSH rules appear on the trackers; assume the old rules apply.

---

## KL — Kerala

### A. Professional Tax (PT)
- **Levies on salaried: Y. It is a local-body tax.** Kerala Panchayat Raj Act, 1994 / Kerala Municipality Act, 1994, with the Kerala Panchayat Raj (Profession Tax) Rules, 1996 and the Kerala Municipality (Profession Tax) Rules, 2005. — HIGH (rules named in the G.O.)
- **Frequency:** **half-yearly** (April–September and October–March). — MEDIUM
- **Slabs (half-yearly income), revised effective 1-Oct-2024 by G.O.(P) No.1149/2024/LSGD dated 27-06-2024:** ≤ ₹11,999 nil; ₹12,000–17,999 ₹320; ₹18,000–29,999 ₹450; ₹30,000–44,999 ₹600; ₹45,000–99,999 ₹750; ₹1,00,000–1,24,999 ₹1,000; ≥ ₹1,25,000 ₹1,250. Source: official G.O. https://go.lsgkerala.gov.in/files/go20240627_37169.pdf (scanned; table read by OCR) — HIGH
- **Due dates:** **31 August** for the first half and **end of February** for the second, per secondary sources. Others say to confirm with the local body. — LOW
- **Return form:** NOT CONFIRMED.

### B. Labour Welfare Fund (LWF)
- **Applies: Y, but Kerala has several sector welfare funds rather than one LWF.**
- **Shops & commercial establishments fund** (the one Simpliance lists): Kerala Shops and Commercial Establishments Workers Welfare Fund Act, 2006 + Scheme, 2007.
  - Contribution: **monthly**, employee **₹50**, employer **₹50**.
  - Due date: by the **5th of every month**.
  - Applicability: **1+** employees covered by the Kerala S&CE Act 1960.
  - Forms 5 and 6; portal peedika.kerala.gov.in.
  - Source: https://www.simpliance.in/labour-welfare-fund-detail/kerala (updated 3-Oct-2024) — LOW-MEDIUM
- **Kerala Labour Welfare Fund Act, 1975** (factories and other notified establishments): contribution amounts and dates — NOT CONFIRMED.

### C. Shops & Establishments
- **1. Act / Rules:** Kerala Shops and Commercial Establishments Act, 1960 (Act 34 of 1960) + Kerala S&CE Rules, 1961. Amendments: Kerala S&CE (Amendment) Act, 2018 (women night work, seating etc. — secondary); **Kerala S&CE (Amendment) Act, 2021** (Bill No.65 of 2021, replacing Ord. 123 of 2021; deemed in force 9-Nov-2020): online registration via **Labour Commissionerate Automation System (LCAS)** (s.5A(1)); **auto-renewal** on fee payment (s.5A(6)); s.5A(7) omitted. Kerala S&CE (Amendment) Rules, 2022 — G.O.(P) No.78/2022/LBR (draft 15-Dec-2021): omitted r.2E (duplicate RC), r.2G(3), **r.12A (submission of return)**. 2023 draft: "Automated" -> "Automation" System wording only. Employee-count threshold — none found. Sources: Bill 65/2021 https://prsindia.org/files/bills_acts/bills_states/kerala/2021/Bill%20no.%2065%20of%202021_Kerala.pdf — HIGH; 2022 rules https://www.simpliance.in/India/LEI/govt_notification/kerala/the-kerala-shops-and-commercial-establishments-amendment-rules-2022-1525 — MEDIUM; 2023 draft https://compfie.aparajitha.com/wp-content/uploads/2023/07/07072023_LEI_02.pdf — MEDIUM; Act https://www.indiacode.nic.in/bitstream/123456789/17191/3/ks.pdf (not fetched).
- **2. Registers / forms:** Act s.30 (records) requires registers of employment/wages, attendance, leave, overtime. Form letters in Rules 1961 — **NOT CONFIRMED**. Keka (single secondary, internally inconsistent) gives: Form A registration application, Form B RC, **Form F combined register of employment and wages**, **Form G leave register**, Form J notice of change — LOW. https://www.keka.com/compliance/acts/kerala-shops-and-commercial-establishments-act-1960
- **3. Annual return:** **None currently** — the return under r.12A was **omitted** by G.O.(P) No.78/2022/LBR (2022). — MEDIUM (secondary report of gazette). (Keka's "Form H quarterly return" predates/contradicts this — LOW.)
- **4. Consolidated / electronic registers:** Kerala notification adopting consolidated/electronic S&CE registers — NOT CONFIRMED. Registration/renewal fully online via LCAS (lcas.lc.kerala.gov.in) — HIGH.
- **5. Renewal:** Still required in law: RC valid for the year granted, renewable year to year (s.5A); since 9-Nov-2020 renewal is **automatic on online fee payment through LCAS** (apply ≥30 days before expiry). Source: Bill 65/2021 text — HIGH.

### D. Other state periodic HR filings
- **1. Factories (Kerala Factories Rules 1957, r.127).**
  - **Form 21** annual return for the year ending 31 Dec, prescribed under r.127(1). It is a **"Master Form"**: one combined annual return under the Factories Act 1948, Payment of Wages Act 1936, Minimum Wages Act 1948, Maternity Benefit Act 1961, Workmen's Compensation Act 1923 and Industrial Employment (Standing Orders) Act 1946, for registered factories. A copy goes to each prescribed authority **by 31 Jan** of the following year.
  - **Form 22** half-yearly return for the half-year ending June, under r.127(2). It must reach the prescribed authority **by 15 Jul**.
  - Sources: https://www.fabkerala.gov.in/images/forms2019/form21.pdf and https://www.fabkerala.gov.in/images/forms2019/form22.pdf (official Dept of Factories & Boilers, Kerala; text extracted) — **HIGH**.
  - Online filing: NOT CONFIRMED.
- **2. Minimum Wages.** For factories, MW data is part of the Form 21 master return (HIGH). For non-factory establishments, the Kerala MW Rules annual return form and date are NOT CONFIRMED.
- **3. Integrated return.** Form 21 is the integrated return for factories (above). An integrated return for non-factory establishments is NOT CONFIRMED.
- **4. CLRA.** Kerala Contract Labour (R&A) Rules — NOT CONFIRMED. Assume the baseline.
- **5. Other.** None confirmed.
- **OSH Code status:** draft only (LKS) — LOW-MEDIUM.

---

## RJ — Rajasthan

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** No Rajasthan LWF Act was found in compilations. — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules:** Rajasthan Shops and Commercial Establishments Act, 1958 (Act 31 of 1958) + Rajasthan S&CE Rules, 1959. Act: https://prsindia.org/files/bills_acts/acts_states/rajasthan/1958/Act31of1958RJ.pdf — HIGH (authoritative host; not parsed). Amendments: (a) Rules amendment **G.S.R. 43 dt 26-Nov-2019** (registration provisions; reportedly one-time fee & permanent validity; LDMS online portal) — https://complinity.com/legal-update/amendment-in-rajasthan-shops-and-commercial-establishment-rules-1959-610/ — MEDIUM for existence, LOW for content; (b) **notification dt 19-Aug-2025 under s.3(2) r/w r.3: establishments employing up to 10 workers exempt from registration** (other provisions still apply) — https://ksandk.com/newsletter/rajasthan-eases-registration-for-small-establishments/ — MEDIUM; (c) **Rajasthan S&CE (Amendment) Act, 2026 (Act No.5 of 2026; assent 24-Mar-2026; Rajasthan Gazette Extra. 27-Mar-2026; deemed in force 17-Dec-2025)**: daily hours 9 -> 10 (s.7(1)); OT 50 -> 144 h/quarter; rest after 6 h (s.9); apprentice/child age 12 -> 14; young persons 14–18. Sources: https://www.sgcms.com/regulatory-updates/rajasthan-shops-commercial-establishments-amendment-act-2026/ ; bill https://prsindia.org/files/bills_acts/bills_states/rajasthan/2026/Bill5of2026RJ.pdf — MEDIUM.
- **2. Registers / forms (Rules 1959):** Form 1 registration statement (r.3); Form 2 register of establishments (Inspector, r.4(1)); Form 3 RC (r.4(1)); Form 4 notice of change (r.5); Form 5 renewal application (r.6(2)); Form 6 notice of opening/closing hours of shops (r.11(2)) — HIGH (vlex rules text, r.1–11 only, https://vlex.in/vid/rajasthan-shops-and-commercial-545671338). **Form 11 — Register of employment**; **Form 12 — register where opening & closing hours ordinarily uniform (r.22(1); includes OT columns)**; Form 13 — notice of daily periods of work; **Form 14 — record of hours of work**; Form 15 — notice of weekly holidays — MEDIUM/LOW (single secondary: https://www.deskera.com/blog/form-12-rajasthan/). Wages/leave/fines registers under S&CE Rules — NOT CONFIRMED.
- **3. Annual return:** None found under Rajasthan S&CE Act/Rules (rule 4(3) refers only to returns under r.3(1)–(2) i.e. registration statements/fees). — LOW (NOT CONFIRMED).
- **4. Consolidated / electronic registers:** Rajasthan notification adopting consolidated/electronic S&CE registers — NOT CONFIRMED. Registration online via LDMS portal — MEDIUM.
- **5. Renewal:** Original r.4(2)/r.6: RC valid to 31-Dec, renewable up to 5 years (Form 5) — HIGH (old rules text). After G.S.R.43/2019: one-time fee, certificate valid permanently (https://calcguru.in/?p=1433 — LOW). Net: renewal likely abolished — NOT CONFIRMED officially.

### D. Other state periodic HR filings
- **1. Factories.**
  - **Current regime:** the **Rajasthan Occupational Safety, Health and Working Conditions Rules 2026**, Notification **S.O.23 dated 30 Jun 2026** (Rajasthan Raj-Patra Extraordinary, Part 4(ga)), in force on publication (30 Jun 2026). The draft was published 3 Feb 2026. They repeal:
    - Rajasthan Factories Rules 1951
    - Rajasthan Contract Labour (R&A) Rules 1971
    - Rajasthan BOCW Rules 2009
    - ISMW Rules 1981
    - Motor Transport Workers Rules 1962
    - Welfare Officers Rules 1959
    - Beedi & Cigar Rules 1969
  - **Unified annual return:** r.49(5)(a) requires every occupier/employer, **on or before 1 Feb each year**, to "upload a unified annual return in **Form-22** … online on the official web portal" for the preceding year. r.51 repeats this: the return goes to the Inspector-cum-Facilitator by 1 Feb, with an electronic copy to DG Labour Bureau.
    - The Form-22 heading reads "**Single Annual Integrated Return to be filed On-line under the OSH Code 2020, the IR Code 2020, the SS Code 2020 and the Code on Wages 2019**". It covers category of employees, health/welfare, retrenchment/lay-offs, bonus and maternity benefit.
    - Part I is for all establishments; Part II is for mines.
    - It is also required for contractors/manpower suppliers with more than 50 workers.
  - **Sale/abandonment/discontinuance:** a further Form-22 is due within 1 month (sale/abandonment) or 4 months (discontinuance), under r.49(5)(b).
  - Portals named in commentary: LDMS / RajFAB (secondary, NOT CONFIRMED).
  - Source: https://www.sgcms.com/regulatory-updates/wp-content/uploads/2026/07/Final-Notification-of-the-Rajasthan-Occupational-Safety-Health-and-Working-Condition-Rules-2026.pdf (gazette PDF, third-party mirror; text extracted) — **HIGH**. Corroborated by https://taxclue.in/blog/rajasthan-osh-rules-2026-registers-records-returns-and-inspector-cum-facilitator/ (secondary).
- **2. Minimum Wages.** Form-22 is stated to be integrated across all 4 Codes, but Rajasthan's Wage Code rules are reported as draft. A separate Rajasthan MW Rules Form III is NOT CONFIRMED; assume the baseline until the Wage Code rules are final.
- **3. Integrated return.** Form-22 (above) — **HIGH**.
- **4. CLRA (now under the OSH Rules 2026, r.75).**
  - **Contractor half-yearly return in Form-23** (Jan–Jun, Jul–Dec), filed electronically with the licensing authority **within 30 days of the half-year's close**, under r.75(6). This applies to all contractors, "including the contractors employing less than 50 contract labour".
  - **Principal employer annual return in Part II of Form-22**, due **by 1 Feb**, electronically, under r.75(8). Contracts to produce a given result are excepted.
  - Source: same gazette PDF — **HIGH**.
- **5. Other.** None confirmed.

---

## MP — Madhya Pradesh

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Madhya Pradesh Vritti Kar Adhiniyam, 1995 + MP Vritti Kar Niyam, 1995 (Commercial Tax Dept).
- **Slabs (annual salary):**
  - ≤ ₹2,25,000 nil.
  - ₹2,25,001–3,00,000: ₹1,500 a year (₹125 a month).
  - ₹3,00,001–4,00,000: ₹2,000 a year (₹166 a month for 11 months, ₹174 in the 12th).
  - > ₹4,00,000: ₹2,500 a year (₹208 a month for 11 months, ₹212 in the 12th).
  - Sources: https://www.simpliance.in/professional-tax-detail/madhya-pradesh ; https://kredily.com/compliance/professional-tax-madhya-pradesh/ — MEDIUM
- **Payment:** **monthly**, within **10 days** after month-end (by the 10th). — MEDIUM (concordant secondaries)
- **Return:** **quarterly return in Form 7**; Simpliance gives "every 3 months starting 15 January", i.e. 15 Jan / 15 Apr / 15 Jul / 15 Oct (that reading is an inference). — LOW (single secondary)
- **Registration:** online, in Form I. — MEDIUM

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Madhya Pradesh Shram Kalyan Nidhi Adhiniyam, 1982 + Rules, 1984.
- **Contribution:** employee **₹10**, employer **₹50** per half-year. Simpliance remarks "As per amendment notification dated 28th Jan 2025, rates changed".
- **Frequency and due dates:** **half-yearly**. Deduct at **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** **1+** employees. Excludes managerial or supervisory staff.
- **Form:** **Form A**.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/madhya-pradesh (updated 23-Feb-2026) — LOW-MEDIUM. The official shramsewa.mp.gov.in site did not respond.

### C. Shops & Establishments
- **1. Act / Rules:** Madhya Pradesh Shops and Establishments Act, 1958 (M.P. Act 25 of 1958; amended inter alia by 21 of 2014 and **20 of 2025**) + M.P. S&E Rules, 1959. **M.P. S&E (Second Amendment) Act, 2025** (in force 15-12-2025): online registration via Labour Dept portal within 30 days of applicability, fee Rs 2,500, auto-generated certificate (s.6); change intimation within 7 days (s.8); closure update within 10 days → portal cancels RC; Central Inspection System; no inspection of establishments with ≤20 workers without Labour Commissioner's prior approval (s.41). Act text (with amendments list): https://prsindia.org/files/bills_acts/acts_states/madhya-pradesh/1958/1958MP25.pdf — HIGH (host), not parsed; https://www.scconline.com/blog/post/2025/12/19/madhya-pradesh-shops-and-establishments-second-amendment-2025-explained/ ; https://www.india-briefing.com/news/madhya-pradesh-shops-establishments-act-2025-digital-compliance-42609.html/ — MEDIUM. Employee-count applicability threshold — NOT CONFIRMED (none found; Act applies in notified areas).
- **2. Registers / forms (Rules 1959):**
  - **Form N — Register of Employees showing attendance, wages, overtime, fines & other deductions and account of wages (r.20(1); OT also r.21(2))** — combined muster-cum-wages (Notification 305-1954-XVI dt 2-5-1972). — HIGH (rules text https://www.legitquest.com/act/madhya-pradesh-shops-and-establishments-rules-1959/D508)
  - **Form I — Register of Leave (r.13(1); separate page per employee r.20(4))**; **Form J — Leave Book (r.13(2), employee's property)**; **Form K — Register of refusal of leave (r.13(3), s.26(3))**. — HIGH
  - Form L — register of lime-washing/painting dates (r.15(1)); Form K-3 — medical examination record (acid cleaning, r.15(2-D)); register of inspection notes (r.20(13)). — HIGH
  - Notices: Form O holidays to employees (r.20(2)); Form P periods of work (r.20(3)); Form Q closed day/alteration (r.20(5)); Form R substitution of weekly holiday for festival (r.20(6)). — HIGH
  - Employer filings: Form A registration statement (r.3(1)); Form D notice of change (r.7); Form E notice of closure (r.8(1)). Post-2025 portal forms — NOT CONFIRMED.
- **3. Annual return:** None for employers under MP S&E Rules (r.22(2) is the Labour Commissioner's annual report). — HIGH (rules text; absence).
- **4. Consolidated / electronic registers:** Form N is a single consolidated attendance/wages/OT/fines/deductions register — HIGH. Express electronic-register provision or state ease-of-compliance adoption — NOT CONFIRMED.
- **5. Renewal:** Old r.5(1): renewal every 5 calendar years (endorsed in Form C Part B) — HIGH (rules text, as of 1995 amendment). Labour Dept notification dt **8-Mar-2019** exempted all registered S&E from renewal (https://lexplosion.in/?p=37286 — MEDIUM; certificates issued since show "lifetime" validity — https://www.scribd.com/document/642226004/2 — LOW). Second Amendment Act 2025 reportedly removes renewal (https://calcguru.in/?p=1435 — LOW).

### D. Other state periodic HR filings
- **1. Factories (Madhya Pradesh Factories Rules 1962).**
  - Annual return: **Form 27**. Its due date was moved **from 15 Jan to 1 Feb**, to be filed **online**, by Notification No. 275/1143/2019/A-16 (c. 2020). Source: https://simpliance.in/India/LEI/govt_notification/madhya-pradesh/amendment-to-the-madhya-pradesh-factories-rules-1962-744 — MEDIUM-LOW.
  - greytHR still lists Form 27 annual due ~15 Jan, plus a separate "Unified Factory Return" (online) due 31 Jan — this conflicts with the notification.
  - **Form 28** half-yearly return, due ~15 Jul / 15 Jan (greytHR) — LOW.
  - greytHR also lists an annual holiday intimation due ~30 Dec — LOW.
  - A further MP Factories Rules amendment was notified 3 Apr 2026 (No. 64-2026-A-XVI); its content is NOT CONFIRMED.
  - Source: https://www.greythr.com/wiki/madhya-pradesh-compliance-calendar — LOW.
- **2. Minimum Wages.** Minimum Wages (MP) Rules **Form III**, due ~31 Jan (greytHR) — LOW.
- **3. Integrated return.** A **combined register and annual return** was notified under **s.17 of the MP Labour Laws (Amendment) and Miscellaneous Provisions Act 2015**, and may be kept in computerised/digital form. It covers 15 laws:
  - CLRA
  - Equal Remuneration
  - Factories
  - ID Act
  - ISMW
  - Labour Laws (Exemption from Furnishing Returns…) Act 1988
  - Maternity Benefit
  - Minimum Wages
  - Motor Transport Workers
  - Payment of Gratuity
  - Payment of Wages
  - Payment of Bonus
  - Sales Promotion Employees
  - MP Shram Kalyan Nidhi Adhiniyam 1982
  - MP Shops & Establishments Act 1958
  - Form name, due date and portal NOT CONFIRMED. Source: https://www.greythr.com/wiki/acts/notification-of-combined-register-and-annual-returns-under-various-labour-laws-in-madhya-pradesh/ — LOW-MEDIUM.
- **4. CLRA.** MP CLRA Rules — NOT CONFIRMED. Assume the baseline. CLRA is covered by the combined return.
- **5. Other.** None confirmed.
- **OSH Code status:** draft (5 Jan 2026 drafts per the LKS tracker). Other sites claim the rules are final — LOW.

---

## PB — Punjab

### A. Professional Tax (PT)
- **Levies on salaried: Y, as a "State Development Tax".** Punjab State Development Tax Act, 2018 + Rules, 2018 (portal psdt.punjab.gov.in).
- **Rate:** **₹200 a month** on persons assessable under "Salaries". The threshold is taxable income above ₹2.5 lakh (old regime) or above ₹3 lakh (new regime), per Simpliance AY 2025-26. Source: https://www.simpliance.in/professional-tax-detail/punjab — MEDIUM
- **Deduction and payment:** the Rules summary says the employer deducts **every quarter** (₹600 a quarter) and deposits by **Challan PSDT-8**. Other secondaries say monthly deposit by the last day of the following month. Sources: https://taxguru.in/goods-and-service-tax/punjab-development-tax-registration-rules-professional-forms.html — MEDIUM-LOW; https://www.zoho.com/in/payroll/academy/payroll-laws/punjab/professional-tax.html — LOW. **The sources conflict.**
- **Return:** **annual return in Form PSDT-6** by **30 April** of the following FY (Rule 10). — MEDIUM-LOW (taxguru rules summary)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Punjab Labour Welfare Fund Act, 1965 + Rules, 1966.
- **Contribution:** employee **₹5 a month**, employer **₹20 a month**.
- **Frequency and due dates:** deducted monthly, paid **half-yearly**: April–September by **15 October**, October–March by **15 April**. Under s.9A(2), payment is by crossed cheque or DD to the Welfare Commissioner.
- **Applicability:** **10+** employees on any day in the preceding 12 months.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/punjab (updated 24-Jul-2025) — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules:** Punjab Shops and Commercial Establishments Act, 1958 (Punjab Act 15 of 1958) + Punjab S&CE Rules, 1958. **Punjab S&CE (Amendment) Act, 2025** (Official Gazette 29-Aug-2025, effective that date): **Act no longer applies to establishments with <20 workers except new s.13-A** (intimation of commencement to Inspector within 6 months of 29-Aug-2025 or of commencement); 20+ workers register within 6 months of commencement; RC within 24 h else **deemed granted**; daily hours 9 -> 10, OT 50 -> 144 h/quarter (s.7); spread-over 10 -> 12 h (s.8(2)); higher fines; compounding for ss.6, 20 (records), 21, 26. Act: https://indiacode.nic.in/bitstream/123456789/11215/1/punjab_shops_and_commercial_establishments_act,_1958.pdf (official; not fetched); amendment: https://khaitanco.com/thought-leadership/Punjab-brings-key-amendments-to-Shops-and-Establishments-Law ; https://knowledge.dlapiper.com/dlapiperknowledge/globalemploymentlatestdevelopments/2025/Punjab-eases-its-shops-and-establishments-rules-Key-amendments-for-2025 — MEDIUM.
- **2. Registers / forms (Punjab S&CE Rules 1958):** Form A — intimation under s.10(2)(i) (r.3); Form B — notice under s.20(1) to be exhibited (r.4); **Form C — Register of employees (r.5(1))**; **Form D — Register of wages (r.5(2))**; **Form E — Register of deductions (r.5(3))**; Form F — registration/renewal statement & RC (r.13(1)); Form G — notice of change (r.13(2)); Form H — register of establishments (Inspector, r.13(4)); Form I — communicable-disease certificate (food/drink establishments, r.19(3)). Registers in English and Punjabi, ink, bound & page-numbered (r.6); preserve 2 years (r.7); OT paid next pay day (r.8); fines need Chief Inspector approval (r.9). Source: https://www.legitquest.com/act/the-punjab-shops-and-commercial-establishments-rules-1958/8907 — HIGH (rules text as consolidated; later amendments, e.g. s.13-A intimation form, NOT CONFIRMED).
- **3. Annual return:** None found under Punjab S&CE Act/Rules. — LOW (NOT CONFIRMED). (The single integrated annual return found is Haryana's, not Punjab's.)
- **4. Consolidated / electronic registers:** Punjab notification adopting consolidated/electronic S&CE registers — NOT CONFIRMED.
- **5. Renewal:** Rules: RC renewal via Form F (annual, by 31 March per secondary). Punjab Govt order dt 1-Nov-2018 reportedly ended renewal requirement — https://lexplosion.in/?p=43166 (dead link; search snippet only) — LOW. Post-2025 validity — NOT CONFIRMED.

### D. Other state periodic HR filings
- **1. Factories (Punjab Factory Rules 1952).** Annual return: **Form 22**, due ~31 Jan (greytHR lists 30 Jan) — LOW. The half-yearly return form is **conflicting**:
  - Form 20 (greytHR Punjab calendar, due ~15 Jul)
  - Form 21 (greytHR Haryana calendar under the same rules)
  - Form 22 "half-yearly return filed under r.107(3)" (a greytHR wiki page title)
  - The rule text was not fetched, so the half-yearly form is NOT CONFIRMED.
  - Sources: https://www.greythr.com/wiki/punjab-compliance-calendar ; https://www.greythr.com/wiki/compliances/punjab-form-22-half-yearly-return/ — LOW.
  - Online portal: NOT CONFIRMED.
- **2. Minimum Wages.**
  - The Punjab Minimum Wages Rules 1950 annual return (Form III assumed) — NOT CONFIRMED.
  - **Punjab's Code on Wages rules were reported final on 29 Dec 2025** (LKS tracker) — LOW. If so, the MW Rules 1950 return may be replaced. Any Wage-Code return form is NOT CONFIRMED.
- **3. Integrated return.** None found for Punjab. Haryana's single return is Haryana-only. NOT CONFIRMED.
- **4. CLRA.** Punjab Contract Labour (R&A) Rules 1973 — assume the baseline. NOT CONFIRMED.
- **5. Other.** Punjab Industrial Establishments (National & Festival Holidays and Casual & Sick Leave) Act: holiday return — NOT CONFIRMED for PB. Haryana uses Form A under the same Act.
- **OSH Code status:** draft (LKS) — LOW-MEDIUM.

---

## OD — Odisha

### A. Professional Tax (PT)
- **Levies: N from 1-Apr-2026 (repealed).** The **Odisha State Tax on Professions, Trades, Callings and Employments (Repeal) Ordinance, 2026** (Odisha Ordinance 02 of 2026; Gazette Extraordinary No.1496, 21-Apr-2026; Law Dept notification No.5969-I-Legis-22/2026/L) repeals Odisha Act 7 of 2000 **with effect from 1-Apr-2026**. The savings clause keeps "returns submitted … taxes paid and arrear of tax to be realized" and accrued liabilities. Source: https://finance.odisha.gov.in/sites/default/files/2026-04/1496-LAW%20DEPTT..pdf (official gazette; text extracted) — **HIGH**
- **Replacement Bill:** introduced in the Assembly on 22-Sep-2026 (https://teamleaseregtech.com/updates/article/60568/odisha-state-tax-on-professions-trades-callings-and-employments-repeal/ — secondary). Its passage is NOT CONFIRMED; an ordinance lapses if not replaced.
- **Pre-repeal (needed for FY 2025-26 and earlier):**
  - Monthly deduction and treasury deposit.
  - **Annual return in Form V**, online, within **3 months** of year-end, i.e. by 30-Jun-2026 for FY 2025-26 (https://odishatax.gov.in/professional_tax.php?lang=en — HIGH, official).
  - Slabs on annual salary: ≤ ₹1,60,000 nil; ₹1,60,001–3,00,000 ₹125 a month; > ₹3,00,000 ₹200 a month (₹300 in one month, February). — LOW-MEDIUM (secondary)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Odisha Labour Welfare Fund Act, 1996 + Rules, 2015.
- **Contribution:** employee **₹10**, employer **₹20** per half-year.
- **Frequency and due dates:** **half-yearly**. Deduct at **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** **20+** employees. Excludes managerial or supervisory staff, apprentices and part-timers.
- **Form:** **Form F**. The LWF Rules 2015 are also within Odisha's electronic combined annual return (see D).
- Source: https://www.simpliance.in/labour-welfare-fund-detail/odisha (updated 12-Nov-2024) — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules:** Odisha Shops and Commercial Establishments Act, 1956 (Odisha Act 30 of 1956) + Odisha S&CE Rules, 1958 (r.42 power). Act: https://prsindia.org/files/bills_acts/acts_states/odisha/1956/Act30of1956Odisha.pdf — HIGH (host; not parsed). **Odisha S&CE (Amendment) Ordinance 03 of 2025 → Bill 2025** (deemed in force **8-Nov-2025**; Cabinet approval 10-10-2025): new s.1(2-a) — **Act applies only to establishments with 20+ employees (except s.4(2-a))**; new s.4 — 20+ register within 6 months of commencement; RC within 7 working days else deemed granted; daily hours 9 -> 10, max 12 h incl. OT, OT 50 -> 144 h/quarter (s.7); rest after 6 h (s.9); open 24x7 & 365 days with weekly holiday / compensatory off within 3 months (ss.11–12); adolescents barred at night (s.23); women at night by notification with consent (s.23A). Source (Odisha Legislative Assembly NeVA bill text): https://cms.neva.gov.in/NeVA/OR/FileStructures/Notices/200696d2-d9ab-463f-a0eb-0da8b87863eb.pdf — HIGH for bill text; enactment — NOT CONFIRMED.
- **2. Registers / forms (Rules 1958):** Form 1 — application for registration (official portal) — MEDIUM. Per single secondary (https://deskera.com/blog/orissa-form-10): Form 5 daily working hours; Form 8 paid leave/salary deductions; **Form 9 — Leave register**; **Form 10 — Combined Muster Roll-cum-Register of Wages**; **Form 11 — register of fines, damages/losses and deductions/advances**; **Form 12 — overtime register**; Form 13 (unclear) — LOW. Rule numbers & official titles — NOT CONFIRMED (official rules PDF https://www.lawrbit.com/wp-content/uploads/2020/08/odisha-shop-and-commercial-establishments-acts-and-rules.pdf blocked 403).
- **3. Annual return:** NOT CONFIRMED (none found).
- **4. Consolidated / electronic registers:** Form 10 combined muster-cum-wages (secondary, LOW). Odisha notification adopting consolidated/electronic S&CE registers — NOT CONFIRMED.
- **5. Renewal:** **Abolished** — Odisha S&CE (Amendment) Rules, 2019, S.R.O. No.267/2019 dt 31-Jul-2019 (Odisha Gazette Extra. No.1439): renewal fee column & renewal references omitted; r.6: RC valid "till it is revoked, or closed by furnishing an undertaking in **Form 16**" (closure undertaking). Source: https://investodisha.gov.in/Application/uploadDocuments/compendium/BO_1702979236.pdf — HIGH.

### D. Other state periodic HR filings
- **1. Factories (Odisha Factories Rules 1950).**
  - Annual / half-yearly form numbers and due dates: NOT CONFIRMED.
  - Online filing: Labour & ESI Dept Resolution No. 5463 (28 Oct 2020, Odisha Gazette Extraordinary No. 1291, 29 Oct 2020) mandates electronic submission of *applications* under the Factories Rules and other rules. Source: https://labour.odisha.gov.in/sites/default/files/2021-02/1291_1.pdf (official; text extracted) — **HIGH**, but it covers applications only, not returns.
- **2. Minimum Wages.** Odisha Minimum Wages Rules 1954 — the separate form is NOT CONFIRMED. The rules are included in the combined annual return (below).
- **3. Integrated return.**
  - The **Combined Annual Return** (with reports, notices and undertakings) must be sent **electronically** by establishments employing **20 or more workers**, under Notification **LESI-LL2-LABGNC-0005-2020-5457/LESI** (Oct 2020). It covers 12 sets of rules:
    - Odisha Factories Rules 1950
    - Payment of Wages Rules
    - Minimum Wages Rules 1954
    - BOCW Rules 2002
    - CLRA Rules 1975
    - ISMW Rules 1980
    - Shops & Commercial Establishments Rules 1958
    - Motor Transport Workers Rules 1966
    - Industrial Establishments (National & Festival) Holidays Rules 1972
    - Beedi & Cigar Rules 1969
    - Labour Welfare Fund Rules 2015
    - Maternity Benefit Rules 1965
  - Due date and portal NOT CONFIRMED. The likely portal is the Odisha labour directorate's portal, but this is unverified. Source: https://simpliance.in/India/LEI/govt_notification/odisha/online-filing-of-returns-reports-notices-and-undertakings-under-various-labour-laws-in-odisha-1022 — MEDIUM-LOW.
- **4. CLRA.** Covered by the combined return. Separate dates NOT CONFIRMED.
- **5. Other.** None confirmed.
- **OSH Code status:** **Draft** Odisha OSH Rules 2026 (Feb 2026). They would supersede the Odisha Factories Rules 1950, Factories (MAH) Rules 2001, CLRA Rules 1975, ISMW, BOCW, MTW and Beedi rules. The draft proposes:
  - a **unified annual return in Form-IX**, uploaded on the State portal "on or before the 28th or 29th day of February". Another clause of the same draft says 1 Feb, so the draft is internally inconsistent.
  - a contractor half-yearly return in **Form-IXA**, due within 30 days of the half-year.
  - a principal employer annual return in **Form-IX Part II**, due by 1 Feb.
  - Source: https://lexplosion.in/wp-content/uploads/2026/02/Draft-Odisha-OSH-Rules-2026.pdf (draft text extracted) — MEDIUM, as a draft only. Final notification NOT CONFIRMED as of Oct 2026.

---

## AS — Assam

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Assam Professions, Trades, Callings and Employments Taxation Act, 1947 + Rules, 1947 (portal tax.assam.gov.in).
- **Slabs (monthly, from 1-Apr-2025; notification eCF No.169350/124):** ≤ ₹15,000 nil; ₹15,001–24,999 ₹180; ≥ ₹25,000 ₹208. The edge bands at 15,000 and 25,000 vary between sources. Sources: https://www.simpliance.in/professional-tax-detail/assam ; https://www.sgcms.com/regulatory-updates/amendment-in-assam-professional-tax-april-2025/ ; https://product-updates.greythr.com/en/revised-assam-professional-tax-slabs-applicable-from-april-2025-GP8quctV — MEDIUM
- **Payment:** deduct monthly and deposit by the **28th of the following month**. — LOW-MEDIUM (Keka https://www.keka.com/compliance/acts/assam-professions-trades-callings-and-employments-taxation-act)
- **Return:** **monthly** by default; the employer may apply in **Form IIIA** to file quarterly, half-yearly or annually. Return forms are **Form III** (and VII-C). — LOW (secondary)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** No Assam LWF Act was found. — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Assam Shops and Establishment Act, 2022 (Assam Act No. 3 of 2025)** — President's assent 13-Mar-2025; published Assam Gazette Extraordinary No.124, 21-Mar-2025 (Notfn LGL.239/2022/272); "shall come into force at once". Repeals the Assam S&E Act, 1971 (s.40, with saving proviso). Modelled on the Model S&E (RE&CS) Act 2016. Source: PRS copy of gazette (OCR'd) https://prsindia.org/files/bills_acts/acts_states/assam/2025/Act3of2025AS.pdf ; official gazette https://labourcommissioner.assam.gov.in/sites/default/files/public_utility/ASSAM%20GAZETTE%20ASSAM%20SHOPS%26ESTTS%20BILL%20Dated%2021-03-25_compressed.pdf — HIGH (gazette text).
  - Original s.6: registration (LIN) for establishments employing **5+** workers within 60 days; s.7 intimation for <5. **Amended** by Assam Ordinance VI of 2025 (14-Oct-2025) → **Assam S&E (Amendment) Act, 2025 = Assam Act VII of 2026** (Governor's assent 6-Feb-2026; gazette 9-Feb-2026): s.6(1) "five" → "**twenty**"; s.11 and s.13 substituted. Source: same PRS compilation (appended amendments) — HIGH; TeamLease summary https://teamleaseregtech.com/updates/article/49572/assam-shops-and-establishments-amendment-ordinance-2025/ — secondary.
  - **Assam S&E (Amendment) Act, 2026 = Assam Act XVII of 2026** (Governor's assent 31-Aug-2026; gazette No.513, 2-Sep-2026; in force at once): renames Facilitator → "Inspector-cum-Facilitator"; **omits s.6 (registration) and s.8**; new s.7 = online **intimation** for establishments with **<10 workers** within 60 days; proviso: provisions of the Act *not included in the OSH&WC Code 2020* apply to establishments with **10+ employees** (i.e. 10+ are now under OSH Code registration). New s.9 change-of-particulars within 30 days; appointment letter + ID card (s.16). Source: PRS compilation (OCR) above — HIGH; comparative statement https://assambidhansabha.org/assets/uploads/bills/en/En_HBCMM1787737140.pdf — HIGH; SGCMS summary https://www.sgcms.com/regulatory-updates/assam-shops-and-establishments-amendment-act-2026/ — secondary.
  - **Rules**: Rules under the 2022 Act — NOT CONFIRMED (no notified Rules found). Labour Commissioner portal still cites 1971 Act + Rules (Rule 45 registration; Schedule I fees under Rules 45/49/50/52) https://labourcommissioner.assam.gov.in/information-and-services/registration-auto-renewal-shops-establishments — HIGH that page exists, but outdated vs 2022 Act. Old rules = Assam S&E Rules, 1976 — MEDIUM (secondary, not fetched).
- **2. Registers**: Act s.25(1): "Every employer shall maintain the registers and records, in such form and in such manner as may be prescribed"; s.25(3) produce on demand; s.25(4) keep at the premises. Specific register forms under 2022 Act — NOT CONFIRMED (Rules not found). Old 1971-regime form numbers — NOT CONFIRMED (Keka lists employee/wage/hours/holiday registers without numbers; Deskera blog cites "Form 15 register of leave with wages" — LOW). Source: gazette OCR above — HIGH for the duty; forms NOT CONFIRMED.
- **3. Returns**: s.26 "Annual Returns": employer shall furnish an annual return in prescribed form and manner (including electronic form); 2026 amendment names the Chief Inspector-cum-Facilitator / Inspector-cum-Facilitator as recipient. Form number and due date — NOT CONFIRMED (Rules not found). Source: gazette OCR — HIGH (duty only).
- **4. Electronic / consolidated registers**: s.25(2): "The records may be maintained electronically or manually" (hard copy on demand at inspection; 2026 amendment adds "or soft copy"). "Register of establishments" may be manual or electronic (s.2). Adoption of an Ease-of-Compliance consolidated-register rule — NOT CONFIRMED. Source: gazette OCR — HIGH.
- **HRMS note**: for 10+ employee establishments in Assam, registers/returns now effectively follow the OSH&WC Code / Code on Wages central forms (out of scope here); S&E registers apply mainly to <10-worker units and to residual non-Code provisions. Confidence MEDIUM (inference from the s.7 proviso).

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## BR — Bihar

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Bihar Tax on Professions, Trades, Callings and Employments Act, 2011 + Bihar Professional Tax Rules, 2011.
- **Slabs (annual salary):** ≤ ₹3 lakh nil; ₹3–5 lakh ₹1,000; ₹5–10 lakh ₹2,000; > ₹10 lakh ₹2,500. — MEDIUM
- **Deduction, payment and returns (once a year):**
  - The employer deducts the year's tax from the **September** salary and deposits it by **15 November** (Rule 5(2)).
  - **Statement Form PT-IV** is due by the **end of November** (Rule 5(3)).
  - Employees who become liable later: deposit by **15 April**, statement **Form PT-V** by the **end of May** (Rule 5(4)–(6)).
  - **Employer annual return: Form PT-VIII by the end of November** (Rule 6(1)). Enrolled persons file Form PT-IX.
  - Sources: rules text https://www.legitquest.com/act/bihar-professional-tax-rules-2011/7687 — MEDIUM; https://www.simpliance.in/professional-tax-detail/bihar (annual return 30 Nov, PT-VIII/PT-IX) — MEDIUM

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability** — **No state S&E Act currently in force (as of Oct-2026).**
  - Bihar S&E Act, 1953 (+ Bihar S&E Rules, 1955) was repealed by s.31(1) of the **Bihar Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2025 (Bihar Act 09 of 2025)** — Governor's assent 7-Aug-2025, Bihar Gazette Extraordinary No.1340, 12-Aug-2025. The 2025 Act applied to shops/establishments with **10+ workers** (s.1(4)); registration for 10+ (s.6); commencement "on such date as the State Government may notify" (s.1(3)) — commencement notification NOT CONFIRMED. Source: PRS copy of gazette https://prsindia.org/files/bills_acts/acts_states/bihar/2025/Act9of2025BR.pdf — HIGH (gazette text; PRS disclaimer).
  - The 2025 Act was repealed by **Bihar Ordinance No.01 of 2026** (promulgated 28-May-2026; gazette 1-Jun-2026) and then by the **Bihar S&E (RE&CS) (Repeal) Act, 2026 (Bihar Act 15 of 2026)** — Governor's assent 2-Aug-2026, Bihar Gazette Extraordinary No.921, 3-Aug-2026; in force on publication. Stated reason: overlap with OSH&WC Code 2020 (in force 21-Nov-2025). Savings clause for actions taken. Source: PRS copy https://prsindia.org/files/bills_acts/acts_states/bihar/2026/Act15of2026BR.pdf — HIGH; SCC Online https://www.scconline.com/blog/post/2026/06/04/bihar-repeals-shops-establishments-act-2025/ — secondary; India Briefing https://www.india-briefing.com/news/bihar-shops-and-establishments-act-repealed-with-oshwc-code-45849.html/ — secondary (says shops now governed by Bihar OSHWC Rules, 2025).
  - Repeal Act does not revive the 1953 Act (no revival clause; General Clauses principle) — MEDIUM (legal inference; caveat: if the 2025 Act was never brought into force, its s.31 repeal of the 1953 Act arguably never operated — commencement NOT CONFIRMED; get legal sign-off).
- **2. Registers**: Under the (repealed) 2025 Act s.19: registers "as may be prescribed", electronic or manual. Now: OSH Code / Bihar OSHWC Rules 2025 registers (out of scope). Legacy 1955-Rules form numbers — NOT CONFIRMED (not needed going forward). Source: Act 9/2025 PRS copy — HIGH.
- **3. Returns**: 2025 Act s.20 annual return (repealed). No S&E return currently. Legacy: greytHR lists a "Quarterly Return Form XIX" under the old Bihar regime https://greythr.com/wiki/bihar-compliance-calendar — LOW (and obsolete). Current: none under S&E — HIGH (Act repealed).
- **4. Electronic registers**: Moot for S&E (repealed). 2025 Act s.19(2) allowed electronic. Source: as above — HIGH.
- **HRMS note**: Bihar should generate **no S&E registers**; route to OSH Code/Code on Wages central registers. Keep 1953/2025-regime outputs only for historical periods (2025 Act 12-Aug-2025 → 1-Jun-2026 if commenced; 1953 Act before that).

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## CG — Chhattisgarh

### A. Professional Tax (PT)
- **Levies on salaried: N, probably.** The Chhattisgarh Vritti Kar Adhiniyam, 1995 exists, but **Notification No. F-10-22/2011/CT/V(22) "exempts salaried employees"**, so employers need not deduct PT. Source: https://www.greythr.com/wiki/acts/professional-tax-chhattisgarh/ — LOW-MEDIUM (single secondary; notification not fetched; the same page and Zoho still print a slab table — conflicting). Confirm with the CG Commercial Tax Dept.

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Chhattisgarh Shram Kalyan Nidhi Adhiniyam, 1982 + Rules, 1984.
- **Contribution:** employee **₹15**, employer **₹45** per half-year.
- **Frequency and due dates:** **half-yearly**. Deduct at **30 June** and **31 December**; pay by **15 July** and **15 January**.
- **Applicability:** **1+** employees. Excludes managerial or administrative staff, and supervisory staff drawing more than ₹10,000 a month.
- **Form:** **Form A**.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/chhattisgarh (updated 3-Oct-2024) — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Chhattisgarh Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2017 (CG Act No. 21 of 2018)** — brought into force **13-Feb-2025** by Notfn F-10-12/2017/16/434 (s.1(4)); replaces the CG (MP) Shops & Establishments Act, 1958 regime. Applied to **10+ workers**; establishments registered under ESI/EPF deemed registered but must obtain LIN. Source: K&S newsletter https://ksandk.com/newsletter/the-government-of-chhattisgarh-notifies-the-effective-date-of-the-chhattisgarh-shops-and-establishments-regulation-of-employment-and-conditions-of-service-act-2017/ — secondary, MEDIUM; Act No. "21 of 2018" corroborated in official rule amendment text (below) — HIGH.
  - **Amendment: CG S&E (RE&CS) (Amendment) Act, 2025 = CG Act No. 11 of 2026** (Governor 27-Dec-2025; President 29-Jun-2026; gazette & commencement **08-Jul-2026**): threshold **10 → 20 workers** (s.1(3)); women night work 9pm–6am with conditions (s.6(2)); daily hours 9 → 10, spread-over 10.5 → 12; OT cap 125 → 144 hrs. Source: SGCMS https://www.sgcms.com/regulatory-updates/chhattisgarh-shops-establishments-amendment-act-2025/ ; TeamLease https://teamleaseregtech.com/updates/article/57953/chhattisgarh-shops-and-establishments-regulation-of-employment-and-con — secondary, MEDIUM (dates conflict slightly across secondaries: 08-Jul vs 20-Jul-2026).
  - **Rules: CG S&E (RE&CS) Rules, 2021** (notified ~03-Feb-2022, Notfn F 10-12/2017/16) — TeamLease https://teamleaseregtech.com/updates/article/20887/chhattisgarh-shops-and-establishments-regulation-of-employment-and-con/ — MEDIUM. Amended by Notfn ESTB/4762/2025-LABOUR dt 25-Sep-2025 (Form-6 women night-shift consent, Form-7 intimation of night-shift employment; rule 7) and Notfn RULE-8/65/2026-LABOUR dt 03-Jun-2026 (rule 4: LIN certificate in **Form-2** within 24 hrs online; register of establishments online in **Form-3** by Facilitator; rule 5: change notice in **Form-4**, fee Rs.100). Source: official gazette PDFs via Simpliance https://simpliance.in/India/LEI/govt_notification/chhattisgarh/notification-regarding-amendments-under-chhattisgarh-shops-and-establishments-regulation-of-employment-and-conditions-of-service-rules-2021-9763 and https://simpliance.in/India/LEI/govt_notification/chhattisgarh/amendment-notification-under-chhattisgarh-shops-and-establishments-regulation-of-employment-and-condition-of-service-rules-2021-7444 — HIGH (gazette text read).
- **2. Registers**: Act requires registers/records "as prescribed" (Model-Act pattern). Specific employer register form numbers in the 2021 Rules (employment/attendance/wages/leave/OT) — NOT CONFIRMED (Rules text not retrieved). Secondary sources list employee, wage, leave, attendance/OT registers without form numbers (greytHR https://www.greythr.com/wiki/acts/chhattisgarh-shops-commercial-establishments-act/ — LOW).
- **3. Returns**: **Annual return in Form-14**, online on Labour Dept portal, calendar year Jan–Dec, due **within 45 days of year-end (i.e. by 14-Feb)**. Source: TeamLease Rules summary (above) — MEDIUM.
- **4. Electronic**: Registration/LIN, register of establishments and annual return are online (Rules r.4 as amended 2026 — HIGH). Employer registers maintainable electronically — Model-Act clause expected but NOT CONFIRMED for CG text. Ease-of-Compliance consolidated register adoption — NOT CONFIRMED.
- **HRMS note**: from 08-Jul-2026 applicability is 20+ workers; below that, S&E registers not required under this Act.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## GA — Goa

### A. Professional Tax (PT)
- **Levies on salaried employees: NOT CONFIRMED.** No Goa PT on salaried employees appears in any compilation (Zoho's list excludes Goa; Simpliance has no Goa PT page). Treat as N pending confirmation.

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Goa Labour Welfare Fund Act, 1986 + Rules, 1990. Contribution portal: iwocm.goalwb.in.
- **Amounts — the two sources conflict:**
  - Official Goa Labour Welfare Board page (https://labour.goa.gov.in/labour-welfare-board/): employee **₹10**, employer **₹30** (total ₹40). A government ₹20 goes only to the Retrenched Workers Assistance Scheme.
  - Simpliance (updated 2-Apr-2025, https://www.simpliance.in/labour-welfare-fund-detail/goa): employee **₹60**, employer **₹180** per half-year.
  - A "Notification: Amendment of GLWB" was posted 11-Jun-2024, but its PDF could not be opened.
  - **Amounts: LOW until resolved.** The official page may be stale.
- **Frequency and due dates:** **half-yearly**. January–June due **31 July**; July–December due **31 January**. — MEDIUM-HIGH (both sources agree)
- **Applicability (Simpliance):** **1+** employees. Excludes managerial or supervisory staff drawing more than ₹1,600 a month. **Form A**.

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **In force (as last confirmed): Goa Shops and Establishments Act, 1973 (Goa Act 13 of 1974)** (originally "Goa, Daman and Diu S&E Act") + **Goa, Daman and Diu Shops and Establishments Rules, 1975** (published Official Gazette Series I No.43, 25-Jan-1975). Amended by Goa S&E (Amendment) Act, 2021 (effective 02-May-2022, Notfn 24/14/2021-LAB/209: electronic filing, auto-renewal, penalties). Sources: Rules PDF on Labour Dept site https://labour.goa.gov.in/wp-content/uploads/2020/07/7475-43-SI-EOG-1-GDD-Shops-Establishment-Rules-1975-25-01-1975.pdf — HIGH; 1973 Act https://prsindia.org/files/bills_acts/acts_states/goa/1974/1974GOA13.pdf — HIGH (not fully read); 2021 amendment https://simpliance.in/India/LEI/govt_notification/Goa/the-goa-shops-and-establishments-amendment-act-2021-1458 — secondary, MEDIUM.
  - **Enacted, commencement pending: Goa Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2025 (Goa Act 23 of 2025)** — passed 07-Aug-2025, Governor's assent 19-Sep-2025, published Official Gazette Series I No.26 Extraordinary No.3, 01-Oct-2025 (Notfn 7/29/2025-LA/143). s.1(3): applies to establishments employing **20+ workers** (only s.7 online intimation for <20); s.1(4): in force on a date to be notified; s.43 repeals the 1973 Act from commencement. Commencement notification — NOT CONFIRMED (Lawrbit, Feb-2026: "yet to be announced"). Further amended by Goa Ease of Doing Business (Misc. Amendments) Bill, 2026 (hours caps moved to notification; women 6am–7pm) — enactment NOT CONFIRMED. Sources: PRS gazette copy https://prsindia.org/files/bills_acts/acts_states/goa/2025/Act23of2025GA.pdf — HIGH; https://www.lawrbit.com/article/goa-shops-and-establishments-act-2025/ — secondary; https://www.outlookbusiness.com/news/ease-of-doing-business-goa-assembly-passes-bill-revising-stamp-vendor-rules-womens-work-timings — secondary.
- **2. Registers (1975 Rules, current regime)** — all HIGH (Rules PDF read):
  - Form I statement for registration; Form II register of establishments (Inspector); Form III registration certificate; Form VI notice of change.
  - **Form IX** register of fines; **Form X** register of deductions for damage/loss (s.35(2)); **Form XI** register of advances.
  - **Form XII** register of leave; **Form XIII** leave book (employee).
  - **Form XX** register of employment (or **Form XXI** where opening/closing hours uniform); **Form XXII** notice of hours of work.
  - **Form XXIII** register of wages (rate, OT wages, gross, deductions, net, employee signature).
  - Form XXIV intimation of close day / Form XXV notice of close day (shops); Form XXVI notice of weekly holiday (other establishments); **Form XXVII** register of compensatory holidays (hotels/restaurants/theatres); Form XXVIII list of holidays (if more than notified).
  - Visit book (bound, ≥100 pages) — r.31(12). Records preserved **3 years** after last entry (r.31(9)); same-day entries, OT entries before/after OT (r.31(8)); English or Marathi/Konkani (r.31(10)); bound & page-numbered (r.31(11)).
  - Under the 2025 Act (once in force): s.27 registers "as may be prescribed" (Rules not yet found) — NOT CONFIRMED forms.
- **3. Returns**: **Quarterly return in Form XXIX** (r.34) by employers with **5+ employees**, for quarters ending 31-Mar/30-Jun/30-Sep/31-Dec, to reach the area Inspector **by the 10th of the following month**, copy to Commissioner — HIGH (Rules PDF). 2025 Act s.28: annual return as prescribed (form/due date NOT CONFIRMED).
- **4. Electronic / alternative registers**: r.32 — Commissioner may by written order permit an employer's own muster roll/register/record containing the prescribed particulars to be maintained in place of prescribed forms (alternative-form approval) — HIGH. 1975 Rules require bound registers (r.31(11)); 2021 amendment introduced electronic filing (details NOT CONFIRMED). 2025 Act s.27(2): records "may be maintained electronically or manually" (hard copy on demand) — HIGH (applies once in force).

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## HP — Himachal Pradesh

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Himachal Pradesh Shops and Commercial Establishments Act, 1969** (amended incl. HP Act 15 of 2004, HP Act 27 of 2012) + **HP Shops and Commercial Establishments Rules, 1972**. Sources: Act PDF on HP govt site https://himachal.nic.in/WriteReadData/l892s/10_l892s/THE%20HIMACHAL%20PRADESH%20SHOPS%20AND%20COMMERCIAL%20ESTABLISHMENTS%20ACT,%201969-57966166.pdf — HIGH (official, not fully re-read); Act+Rules compilation https://elion.co.in/wp-content/uploads/2024/09/himachal-pradesh-shops-and-commercial-establishment-acts-and-rules.pdf — read in full for rules, MEDIUM-HIGH (secondary host of official text).
  - **HP S&CE (Amendment) Act, 2025 = HP Act No. 3 of 2026** (notified 11-Feb-2026): s.13(1) registration statement applies to establishments with **10+ employees**; s.7(2) proviso OT cap **144 hrs per quarter**. Source: TeamLease https://teamleaseregtech.com/updates/article/52887/himachal-pradesh-shops-and-commercial-establishments-amendment-act-2025/ ; Tribune https://www.tribuneindia.com/news/himachal/house-clears-overhaul-of-shops-establishments-law/amp — secondary, MEDIUM.
  - 22-Sep-2026: Labour Dept exempted all shops/CEs from ss.9–10 (opening/closing hours, close day) and Rule 11 for 24x7 operation — TeamLease https://teamleaseregtech.com/updates/article/59860/department-of-labour-employment-overseas-placement-himachal-pradesh-is — secondary, MEDIUM.
- **2. Registers** (Rules 1972, r.14 — MEDIUM-HIGH, compilation text read):
  - **Form No. 8** — Register of employees; **Form No. 9** — Register of wages; **Form No. 10** — Register of deductions; **Form No. 11** — Register of leave with wages.
  - Act s.20: notice of close-day/working hours/intervals in **Form No. 7** (r.13); record of working hours, rest intervals, leave and separately OT (s.20(2)); attendance marked within one hour of start of duty, OT entries before/after (s.20(3)); photograph of each employee after 3 months' service (s.20(4)).
  - Form No. 6 — intimation under s.10 (r.12); Form No. 1 statement/registration & renewal; Form No. 2 register of establishments; Form No. 3 registration certificate (display); Form No. 4 change notice; Form No. 12 health certificate (food handlers).
  - r.15: registers in English or Hindi, ink, **bound and serially numbered**; r.16: preserve **3 years**.
- **3. Returns**: No annual/periodic return found in Act or 1972 Rules — "none found" (MEDIUM; full rules text searched for "return").
- **4. Electronic / consolidated**: HP Ease of Compliance to Maintain Registers under various Labour Laws Rules, 2020 (Notfn Shram (A)4-8/2019) covers only BOCW, CLRA, ISMW, MW, PW — **not the S&CE Act**. Source: Simpliance https://simpliance.in/India/LEI/govt_notification/himachal-pradesh/the-himachal-pradesh-ease-of-compliance-to-maintain-registers-under-various-labour-laws-rules-2020-915 — secondary, MEDIUM. S&CE registers: bound registers required (r.15(2)); no electronic-register provision found — MEDIUM. Online auto-renewal of S&CE registration (Notfn Shram (F)2-1/2020 dt 17-Aug-2020) — MEDIUM.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## JH — Jharkhand

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Jharkhand Tax on Profession, Trades, Callings and Employments Act, 2011 + Rules, 2012.
- **Slabs (annual salary):** ≤ ₹3 lakh nil; ₹3–5 lakh ₹1,200 (₹100 a month); ₹5–8 lakh ₹1,800 (₹150 a month); ₹8–10 lakh ₹2,100 (₹175 a month); > ₹10 lakh ₹2,500 (₹208 a month). Sources: https://www.simpliance.in/professional-tax-detail/jharkhand ; https://www.zoho.com/in/payroll/academy/payroll-laws/jharkhand/professional-tax.html — MEDIUM
- **Payment:** **quarterly**, within 15 days after each quarter (Zoho). Zoho's printed dates look inconsistent. — LOW
- **Return:** **annual return, Form JPT-203, by 31 October** (Simpliance). — LOW

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Bihar Shops and Establishments Act, 1953 (Bihar Act VIII of 1954) as adapted by Jharkhand** (adaptation under s.85 Bihar Reorganisation Act 2000) + **Jharkhand Shops and Establishments Rules, 2001** (= Bihar S&E Rules 1955 adapted; Notfn No. 2/SEA (LC) 501/2001 L&T-2870 dt 14-Nov-2001; some sources say 2002). Amendments: Jharkhand Amendment Act 2015 (s.14 women's working hours relaxation; gazetted 19-Sep-2022); Rules amended 30-Apr-2015 (r.42 threshold, r.43A online return, r.43B consolidated/digital registers) and 2017 (online deemed registration, renewal r.3A, 10-year validity r.3B). Note: Bihar's own repeal of its S&E law (2025/2026) does **not** affect Jharkhand's adapted Act. No 2025–26 Jharkhand replacement Act found. Sources: Rules compilation https://www.lawrbit.com/wp-content/uploads/2020/08/jharkhand-shops-establishment-acts-and-rule.pdf — read, MEDIUM-HIGH (secondary host of official text); India Code https://www.indiacode.nic.in/handle/123456789/7513 — HIGH (listing); 2015 amendment via Aparajitha https://compfie.aparajitha.com/wp-content/uploads/2022/09/26092022_LEI_01.pdf — secondary, MEDIUM; 2017 rules https://www.legitquest.com/act/jharkhand-shops-establishment-amendment-rules-2017/7564 — MEDIUM.
  - Applicability: Act applies to all shops/establishments (no headcount floor found in Act); returns apply to **10+ persons** (r.42/43). Registration fee slabs start at "up to 10 employees". — MEDIUM.
- **2. Registers** (Jharkhand Rules 2001; numbering matches Bihar 1955 Rules — MEDIUM-HIGH):
  - **Form IX** — Leave with Wages Register (r.14; preserve 3 yrs after last entry).
  - **Form X** — Register of (attendance and) wages and overtime payment (r.17).
  - **Form XI** — Register of fines and deductions (r.19) [Jharkhand compilation prints "Form X" at r.19 but r.37(3) refers to Forms IX, X and XI; Bihar 1955 text = Form XI — treat as Form XI, MEDIUM].
  - **Form XXI** — Service card in triplicate (r.12-A; copy to Inspecting Officer within a week of first employment).
  - **Form VII** — Notice of weekly holidays (r.12) and notice of periods of work (r.13); copy to Inspecting Officer within 2 days.
  - Inspecting Officers' Visit & Inspection Book (r.29); Form I registration, Form II register of establishments, Form III certificate, Form IV change/closure notice.
  - Same-day entries (r.33); preserve **3 years** after calendar year (r.34); kept at office and place of business, English/Hindi (r.37).
- **3. Returns**:
  - **Annual return** (r.42) — employers with **10+ persons**, calendar year, to Inspecting Officer **by 31-Mar** of following year (no form number; content as required by Chief Inspecting Officer).
  - **Quarterly return in Form XIX** (r.43) — 10+ persons, quarters ending Mar/Jun/Sep/Dec, **within 30 days of quarter-end**.
  - **r.43(A)** (2015): where an **online return** is filed in the Government-prescribed format, only that online return is required, **on or before 30-June every year** (replaces r.42/43 filings).
  - Source: Rules compilation above — MEDIUM-HIGH. (greytHR calendar shows quarterly Form XIX — LOW corroboration.)
- **4. Electronic / consolidated**: **r.43(B)** (2015): Government may notify consolidated forms in lieu of prescribed registers, and may allow registers/records in **computerized or digital formats** — MEDIUM-HIGH (rules text). r.37(3): Inspecting Officer may approve an existing muster roll/register in place of Forms IX/X/XI — MEDIUM-HIGH. Whether a consolidated-forms order has actually been notified — NOT CONFIRMED.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## JK — Jammu & Kashmir (UT)

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **In force (as last confirmed): Jammu and Kashmir Shops and Establishments Act, 1966 + J&K Shops and Establishments Rules, 1968** (amended incl. J&K S&E (Amendment) Rules 2017, SRO 293: registration certificate Form C / close-day Form O within 15 days). Sources: Rules text https://www.legitquest.com/act/the-jammu-and-kashmir-shops-and-establishments-rules-1968/F0A2 — MEDIUM (secondary host of official text); 2017 amendment https://simpliance.in/India/LEI/govt_notification/jammu-and-kashmir/jammu-and-kashmir-shops-and-establishments-amendment-rules-2017-202 — MEDIUM.
  - **Enacted, commencement pending: J&K Shops and Establishments (Licensing, Regulation of Employment and Conditions of Service) Act, 2025 (Act No. VI of 2025)** — LG's assent 1-Nov-2025; J&K Official Gazette Extraordinary Vol.138 No.32-3, 1-Nov-2025. s.1(3) in force on date notified; s.1(4) applies to **all** shops/establishments; s.5 online registration within 60 days, **optional for <20 employees**; 1966-Act registrations deemed registered; s.24 records; s.35 repeals 1966 Act. Commencement notification — NOT CONFIRMED (none found; commentary https://righttoinformation.wiki/_export/xhtml/jk-shops-establishments-act-2025-all-sizes-coverage also found none). Source: PRS gazette copy https://prsindia.org/files/bills_acts/acts_states/jammu-and-kashmir/2025/Act6of2025JK.pdf — HIGH.
  - Conflicting claim of a "2022 Act (Act XXVII of 2022)" replacing 1966 (setindiabiz blog) — LOW / disregard.
- **2. Registers (1968 Rules; current regime)** — MEDIUM:
  - **Form L** — Register of employees (attendance, overtime, wages) (r.20(1)).
  - **Form G** — Leave with wages register (r.11(1); preserve 3 yrs after last entry); **Form H** — Leave book (employee; r.11(3)); **Form Q** — register of complaints re refused leave (r.11(6)).
  - **Form P** — Service card (duplicate to Inspector) (r.13); **Form I** — lime-washing/painting register (r.14(2)); **Form J** — annual medical certificate for food/drink staff (r.17(3)); **Form K** — inspection note/visit book (r.19).
  - Notices: **Form M** holidays; **Form N** daily hours & intervals; **Form O** weekly close day (r.20(2)–(4)).
  - Registration: Form A statement, Form B register of establishments, Form C certificate, Form D change, Form E closure.
  - r.20: registers to be **stamped by the Inspector**; same-day entries; preserve **3 years** after calendar year; English or Urdu script.
  - Under 2025 Act (once in force): s.24(1) registers "as may be prescribed" — forms NOT CONFIRMED (no Rules found).
- **3. Returns**: 1968 Rules — no periodic return to employer found; annual renewal of registration (Form A + Form C) **before 31-March each year** (r.4; may be superseded by 2017 amendment — NOT CONFIRMED). 2025 Act: Chapter VII titled "Records and Returns" but no annual-return section found in text — "none found" (HIGH for Act text).
- **4. Electronic**: 1968 Rules — no electronic provision; registers stamped by Inspector (MEDIUM). 2025 Act s.24(2): "records may be maintained electronically or manually" (hard copy on demand) — HIGH (applies once commenced). Ease-of-Compliance adoption — NOT CONFIRMED.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## MN — Manipur

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Manipur Professions, Trades, Callings and Employments Taxation Act, 1981 + Rules, 1982.
- **Slabs (annual income):** ≤ ₹50,000 nil; ₹50,001–75,000 ₹1,200; ₹75,001–1,00,000 ₹2,000; ₹1,00,001–1,25,000 ₹2,400; > ₹1,25,000 ₹2,500. Sources: https://www.simpliance.in/professional-tax-detail/manipur ; Zoho — LOW-MEDIUM
- **Return:** **annual**, due **30 March** (Simpliance); form not named. — LOW. The employer's monthly deposit schedule is NOT CONFIRMED.

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Manipur Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2021** — preceded by Ordinance published 4-Aug-2021; Act published **06-Nov-2023**, **deemed in force from 29-Jun-2021**. Applies to establishments with **10+ workers**; registration/LIN within 6 months, renewed annually (per Mondaq). Repeals the **Manipur S&E Act, 1972 (Manipur Act 4 of 1973)** and the 2021 Ordinance. Sources: Mondaq https://mondaq.com/india/employee-benefits-compensation/1498064/introduction-of-the-manipur-shops-and-establishments-regulation-of-employment-and-conditions-of-service-act-2021 — secondary, MEDIUM; greytHR notification https://greythr.com/notifications/manipur-shops-and-establishments-regulation-on-employment-and-conditions-of-servi — secondary, MEDIUM; Simpliance (Ordinance) https://simpliance.in/India/LEI/govt_notification/manipur/the-manipur-shops-and-establishments-regulation-of-employment-and-conditions-of-service-ordinance-2021-1241 — MEDIUM. Act number of 2021 Act — NOT CONFIRMED.
  - **Rules under the 2021 Act — NOT CONFIRMED** (none found). Legacy: Manipur S&E Rules, 1973 (under 1972 Act). Manipur has also framed OSHWC Code rules while keeping its S&E Act (India Briefing https://www.india-briefing.com/news/bihar-shops-and-establishments-act-repealed-with-oshwc-code-45849.html/ — secondary).
- **2. Registers**
  - 2021 Act: records and registers "as may be prescribed" — forms NOT CONFIRMED.
  - **Legacy 1973 Rules (1972 Act; still used by many practitioners)** — MEDIUM (compilation https://www.lawrbit.com/wp-content/uploads/2020/08/manipur-shops-and-establishments-acts-and-rules.pdf read): **Form I** register of wages (r.3); **Form II** register of fines and deductions (r.5); **Form VI** leave with wages register (r.17(2), preserve 3 yrs); **Form VII** register of attendance, overtime and wages (r.17(3)); notices **Form V** weekly holiday, **Form VIII** daily hours/intervals, **Form IV** weekly closure; registration Form IX, change Form XI, closure Form XIII. r.17(7): preserve till end of next year. **r.17(8)**: registers kept under Minimum Wages Act / Payment of Wages Act / Labour Laws (Exemption from Furnishing Returns & Maintaining Registers) Act 1988 (muster roll, wages, deductions, OT, fines, advances, muster-roll-cum-wages) are **deemed** to satisfy r.3, r.5, r.17(2)-(3).
- **3. Returns**
  - 2021 Act: annual return referred to (Mondaq) — form/due date NOT CONFIRMED.
  - Legacy: **Annual statement in Form III** (s.30 of 1972 Act + r.15) to Chief Inspecting Officer — employees, wage rates, benefits — **before end of January** following the year. greytHR calendar still lists Form III due 30-Jan https://www.greythr.com/wiki/manipur-compliance-calendar — MEDIUM (statute text read; status after repeal uncertain).
- **4. Electronic / consolidated**: Legacy r.17(8) = deemed-compliance via central registers (consolidation-like) — MEDIUM. Electronic registers under 2021 Act — NOT CONFIRMED (Model-Act pattern usually allows; not verified).
- **HRMS note**: until 2021-Act Rules are found, offering legacy Forms I/II/VI/VII + Form III annual statement as "best available" with a NOT CONFIRMED flag is reasonable.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## ML — Meghalaya

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Meghalaya Professions, Trades, Callings and Employments Taxation Act (Assam Act VI of 1947 as adapted).
- **2022 amendment** (Act No. 15 of 2022; Gazette Extraordinary 27-Sep-2022):
  - The **Schedule of rates is omitted**; rates are now "as may be notified by the State Government from time to time".
  - Employers must register within **90 days** of becoming liable (new s.3A).
  - New s.12(5): interest at **12% a year** if tax is "not paid on or before the last day of the following month of the quarter next". This implies **quarterly payment by the end of the month after each quarter**.
  - Source: https://PRSINDIA.org/files/bills_acts/acts_states/meghalaya/2022/Act15of2022Meghalaya.pdf (gazette text extracted) — HIGH for the text; the quarterly reading is an inference — MEDIUM.
- **Current notified rates: the sources conflict.**
  - Simpliance (https://www.simpliance.in/professional-tax-detail/meghalaya) lists 12 annual-income bands from ≤ ₹50,000 nil to > ₹5,00,000 ₹2,500. It also shows "PT remittance and monthly return, every month on day 28, Form VII-C", which may predate the 2022 amendment.
  - Tax Garden lists ≤ ₹1.8 lakh nil, then ₹500 / ₹1,000 / ₹1,500 / ₹2,500.
  - Both — LOW.

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Meghalaya Shops and Establishment Act, 2003 (Meghalaya Act No. 4 of 2004)** — Governor's assent 2-Apr-2004; Gazette Extraordinary 5-Apr-2004 (short title cites 2003, India Code titles it 2004). + **Meghalaya Shops and Establishment Rules, 2004** (dated 7-Oct-2004, Dept of Labour, Employment & Skill Development). Sources: PRS gazette copy https://prsindia.org/files/bills_acts/acts_states/meghalaya/2004/2004MG4.pdf — HIGH; Rules listing https://meghalaya.gov.in/meghalaya/index.php/node/37393 and PDF http://meglc.gov.in/acts/Meghalaya%20Shops%20and%20Establishment%20Rules%202004.pdf — HIGH (official; OCR'd scan).
  - **Meghalaya S&E (Amendment) Act, 2025 (Act No. 13 of 2025)** — Gazette Extraordinary 2-Oct-2025, in force on publication: s.3(1) registration only for establishments **employing 5+ employees**; s.6 open all days of the year; OT cap 144 hrs/quarter (double rate, optional); s.8 women night shift 8pm–6am with conditions (written consent kept as record). Source: PRS copy (appended amendment) — HIGH; TeamLease https://teamleaseregtech.com/updates/article/49196/meghalaya-shops-and-establishments-amendment-act-2025/ — secondary.
  - Meghalaya has also framed OSHWC Code rules while retaining its S&E Act (India Briefing — secondary).
- **2. Registers** (Rules 2004, OCR of official PDF — HIGH, minor OCR uncertainty):
  - **Form Q** — Register of hours of work and interval for rest (r.51; weekly, incl. opening/closing hours).
  - **Form R** — Register of overtime work and payment of overtime wages (r.52; monthly). **Form I** — overtime slip to employee after each OT occasion (r.19).
  - **Form S** — Register of employment (r.53; employee particulars, pay, allowances; signed by employee; corrections within 3 days).
  - **Form T** — Register of leave (with wages), and **Leave Card** in same Form T per employee.
  - **Form M** — Letter of appointment (every employee); **Form N** — register of lime-washing/painting dates; **Form O** — medical/fitness certificate; Form G weekly closure notice; Form H notice (spread/half-day) — countersigned by Inspector.
  - Registration: Form A statement, Form B register of shops & establishments, Form C certificate, Form D change, Form E closure, Form F.
  - Act s.25: registers/records/notices as prescribed, kept at premises. Rules: English, ink, **preserve 3 successive years**, serially page-marked and bound, same-day entries authenticated.
  - No separate register of wages/fines found in Rules — NOT CONFIRMED (wage particulars sit in Form S/R).
- **3. Returns**: None found in Act or Rules (searched OCR text for "return/annual/statement") — "none found", MEDIUM. Registration renewal by calendar year (Form A) — MEDIUM.
- **4. Electronic / alternative**: r.53(4): Labour Commissioner may by written order allow an existing muster roll/register/record substantially containing the particulars to be maintained in place of prescribed forms (copy to area Inspector) — HIGH. Electronic registers — NOT CONFIRMED (bound registers required).

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## MZ — Mizoram

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Mizoram Professions, Trades, Callings and Employments Taxation Act, 1995 + Rules, 1996.
- **Slabs (monthly):** ≤ ₹5,000 nil; ₹5,001–8,000 ₹75; ₹8,001–10,000 ₹120; ₹10,001–12,000 ₹150; ₹12,001–15,000 ₹180; ₹15,001–20,000 ₹195; > ₹20,000 ₹208. Zoho shows ₹208 from ₹15,001. Source: https://www.simpliance.in/professional-tax-detail/mizoram — LOW
- **Return:** **annual**, due **30 June** (Simpliance). — LOW

### B. Labour Welfare Fund (LWF)
- **Applies: N.** Simpliance shows "No Records Found". — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Mizoram Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2010 (Act No. 14 of 2010)** (Rules cite it as "Mizoram Shops and Establishment Act, 2010") + **Mizoram Shops and Establishments Rules, 2011** (Notfn No.A-47012/5/2009-L&E; Mizoram Gazette Extraordinary Vol.XLI Issue 12, 13-Jan-2012). s.1(2): applies to shops/establishments **employing 5+ workers**. Sources: Rules gazette text in compilation https://elion.co.in/wp-content/uploads/2024/09/mizoram-shops-and-establishments-acts-and-rules.pdf — read, MEDIUM-HIGH; threshold from official 2023 Bill comparison statement https://prsindia.org/files/bills_acts/bills_states/mizoram/2023/Billno64of2023MZ.pdf — HIGH.
  - Draft **Amendment Bill 2023** (dated 23-Feb-2023) proposes threshold **5 → 10 workers** and bank/railway/cantonment exclusion — enactment NOT CONFIRMED (same PRS source).
  - **Mizoram S&E (RE&CS) (Amendment) Ordinance, 2026 (Ordinance No.1 of 2026)** — promulgated 29-Jun-2026; Gazette Extraordinary Issue 634, 29-Jul-2026 (Notfn H.12018/214/2010-LJD dt 27-Jul-2026); in force at once: s.2(28) "electronically" defined; s.5(2) registration manual or electronic; s.5(6) registration valid until cancelled/surrendered (no renewal); **new s.5A deemed registration** for establishments registered under the **OSH&WC Code 2020**. Source: PRS copy https://prsindia.org/files/bills_acts/bills_states/mizoram/2026/Ord1of2026MZ.pdf — HIGH. (Replacement Act status NOT CONFIRMED; ordinance lapses if not enacted.)
- **2. Registers** (Rules 2011 — MEDIUM-HIGH):
  - **Form G** — Register of hours of work and interval for rest (r.16).
  - **Form H** — Register of overtime work and payment of overtime wages (r.17, monthly); **Form I** — overtime slip (r.18).
  - **Form R** — Register of employment (r.49; kept up to date, signed by employee).
  - **Form S** — Register of leave (r.50) + **Leave Card** in Form S per employee (r.51).
  - **Form O** — Appointment letter (r.34; copy to Chief Inspector & area Inspector); **Form P** — lime-washing/painting register; **Form Q** — health certificate; **Form J** weekly closure notice (shops), **Form K** notice of 1½ days' weekly rest (other establishments) — countersigned by Inspector.
  - Registration: Form A (application/renewal), Form B register of S&E, Form C certificate, Form D change (within 2 months), Form E closure, Form F.
  - r.52: English or Mizo; preserve **3 successive years** (appointment letters 3 yrs after exit); serially page-marked and bound; same-day authenticated entries.
  - No separate wage/fine register found — NOT CONFIRMED.
- **3. Returns**: None found in 2011 Rules (searched "annual/return/statement") — "none found", MEDIUM.
- **4. Electronic / alternative**: r.49(4)-type clause: Chief Inspector may by written order allow an existing muster roll/register to substitute for prescribed forms — MEDIUM-HIGH. Electronic **registration** permitted from 2026 Ordinance — HIGH; electronic **registers** — NOT CONFIRMED (Rules require bound registers).

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## NL — Nagaland

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Nagaland Professions, Trades, Callings and Employments Taxation Act, 1968 + Rules, 1970.
- **Slabs (monthly):** ≤ ₹4,000 nil; ₹4,001–5,000 ₹35; ₹5,001–7,000 ₹75; ₹7,001–9,000 ₹110; ₹9,001–12,000 ₹180; > ₹12,000 ₹208. Simpliance and Zoho agree. Source: https://www.simpliance.in/professional-tax-detail/nagaland — LOW-MEDIUM
- **Return:** **annual return, Form V, by 30 April** (Simpliance). — LOW

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Nagaland Shops and Establishments Act, 1982 (Nagaland Act 5 of 1985)** — commonly cited by the Labour Dept/Rules as "Nagaland Shops and Establishment Act, 1986" (same statute; naming discrepancy). + **Nagaland Shops and Establishment Rules, 2015** (made under s.34; registration under s.36 + rr.4–5, renewal every 12 months, Form "B"). Sources: Act text https://www.nagaland.gov.in/storage/PostFiles/The-Nagaland-Shops-and-Establishments-Act-1982.pdf — HIGH; Rules compilation https://elion.co.in/wp-content/uploads/2024/09/nagaland-shops-and-establishments-acts-and-rules.pdf — read, MEDIUM-HIGH; Labour Dept page https://labour.nagaland.gov.in/?p=44 and IPR notice https://ipr.nagaland.gov.in/asst-labour-commissioner-kohima-informs — HIGH (registration/renewal).
  - **Nagaland S&E (First Amendment) Ordinance, 2025** (gazette 17-Dec-2025) → **(First Amendment) Bill, 2025** passed 10-Mar-2026 (Notfn AS/LEG-05/NSE-1/2026/1211 dt 13-Mar-2026): main provisions for establishments employing **20+ persons** (in Town Committee and specified areas), registration for all; 10 hrs/day cap, OT 144 hrs/quarter at double rate; spread-over 12.5 hrs; hotels/restaurants close by 11pm; women/minors night restrictions. Assent/gazette of Act — NOT CONFIRMED (one secondary cites commencement 30-Jun-2026 — LOW). Sources: TeamLease https://teamleaseregtech.com/updates/article/54178/nagaland-shops-and-establishments-1st-amendment-bill-2025/ and https://teamleaseregtech.com/updates/article/57599/nagaland-shops-and-establishments-first-amendment-ordinance-2025 ; Eastern Mirror https://www.easternmirrornagaland.com/nagaland-promulgates-new-ordinance-for-hotels-and-establishments — secondary, MEDIUM. Nagaland Jan Vishwas (Amendment of Provisions) Bill, 2026 decriminalises minor S&E offences — MEDIUM.
  - Registration/renewal applications **online-only** via Labour Dept portal (notice dated 8-Jun-2026) — IPR Nagaland — MEDIUM.
- **2. Registers** (Rules 2015 — MEDIUM-HIGH):
  - **Form H** — Register of hours of work and rest intervals (r.14; entries same day).
  - **Form I** — Leave register (r.15; refusals/postponements recorded).
  - **Form J** — Pay register (r.25; payment entries certified in presence of two witnesses).
  - **Form N** — Register of overtime work and payment of overtime wages (r.29, monthly).
  - **Form S** — Register of employees (r.54; corrections within 3 days).
  - **Form T** — Letter of appointment (r.55).
  - Notices: Form F weekly closure (shops); Form G(?) notice for entertainment establishments (compilation prints "Form 'C'" — NOT CONFIRMED).
  - r.56: English, ink; preserve till **end of next calendar year**; separate sets at godowns/offices off-premises.
- **3. Returns**: None found in Rules 2015 or Act (searched) — "none found", MEDIUM. Annual renewal of registration (every 12 months) — HIGH.
- **4. Electronic / alternative**: **r.57 Special registers** — Chief Inspector/Registering Authority may by written order deem an employer's own register/record (with the particulars) as compliant, application with copies of the format — MEDIUM-HIGH. Electronic registers — NOT CONFIRMED. Online registration/renewal — MEDIUM.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## SK — Sikkim

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Sikkim Tax on Professions, Trades, Callings and Employments Act, 2006 + Rules, 2006.
- **Slabs (monthly):** ≤ ₹20,000 nil; ₹20,001–30,000 ₹125; ₹30,001–40,000 ₹150; > ₹40,000 ₹200. Simpliance and Zoho agree. Source: https://www.simpliance.in/professional-tax-detail/sikkim — LOW-MEDIUM
- **Return:** **quarterly return, Form 5**, due by the end of the month after each quarter: 31 Jul / 31 Oct / 31 Jan / 30 Apr. Simpliance and Zoho broadly agree. — LOW

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Sikkim Shops and Commercial Establishments Act, 1983 (Act 6 of 1983)** + **Sikkim Shops and Commercial Establishments Rules, 1984**. Amended by Act No. 19 of 2022 (assent 25-Aug-2022; gazette No.364, 12-Sep-2022: omits s.73(c), removes imprisonment in s.74). Sources: PRS copy https://prsindia.org/files/bills_acts/acts_states/sikkim/1983/1983SK6.pdf — HIGH; Act+Rules compilation https://www.lawrbit.com/wp-content/uploads/2020/08/sikkim-shop-and-commercial-establishments-acts-and-rules.pdf — read, MEDIUM-HIGH.
  - Registration (s.6) within 30 days; certificate valid to end of calendar year; annual renewal (r.5, Form A) — MEDIUM-HIGH.
  - **Notification No. 11/LD/2025**: establishments with **≤20 employees** exempted from "certain provisions"; must file **Form-I** with Labour Dept within 3 months of starting business. Exact sections exempted — NOT CONFIRMED. Source: DCPSol https://www.dcpsol.com/sikkim-shops-establishments-act-exemption-20-employees — secondary, LOW-MEDIUM.
- **2. Registers** (Rules 1984, r.15 — MEDIUM-HIGH):
  - Shops/commercial establishments: **Form I** — Register of employment (or **Form K** where opening/closing hours & rest intervals are uniform).
  - Residential hotels/restaurants/eating houses/theatres: **Form J** — Register of employment (or **Form L** if uniform hours); **Form M** — notice of weekly holiday.
  - **Form N** — Register of leave; **Form O** — Leave Book (employee's property).
  - Fines register (Act s. on fines (8)) and deductions register ("in such form as may be prescribed") — form numbers NOT CONFIRMED.
  - **Form H** — lime-washing/painting register; Form G age certificate; Form E/F security-deposit receipts/seizure.
  - **Visit book** (bound, 7"x6", ≥100 pages) — r.15(12).
  - Same-day entries (OT immediately after completion) — r.15(8); preserve **2 years** after last entry — r.15(9); English or listed local languages — r.15(11); separate sets at off-premises godowns — r.15(13).
- **3. Returns**: None found in Act/Rules (searched "return/annual") — "none found", MEDIUM. Annual renewal of registration — MEDIUM-HIGH.
- **4. Electronic / alternative**: r.15(10): State Govt may by written order allow an employer's muster roll/register/record with the required particulars in place of prescribed forms — MEDIUM-HIGH. Electronic registers — NOT CONFIRMED.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## TR — Tripura

### A. Professional Tax (PT)
- **Levies on salaried: Y.** Tripura Professions, Trades, Callings and Employments Taxation Act, 1997 + Rules, 1998. Online portal: https://ptax.tripura.gov.in/
- **Slabs (salary and wage earners, official portal "Tax Rates" page):** ≤ ₹7,500 nil; ₹7,501–15,000 ₹1,800 a year (₹150 a month); ≥ ₹15,001 ₹2,496 a year (₹208 a month). Source: https://ptax.tripura.gov.in/TaxDetails.aspx — HIGH
- **Return and payment frequency and due dates:** NOT CONFIRMED. The portal FAQ PDF returned 404.

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Tripura Shops and Establishments Act, 1970** + **Tripura Shops and Establishment Rules, 1970** (made under s.25; Tripura Gazette 1971). Amended up to 5th Amendment (24-May-2021: **s.16 registration deleted** — no registration required; one secondary says effective 26-Apr-2024 — LOW). Rules 6th Amendment 2019 (Notfn F.24(1)-LAB/ENF/SE/89/9779 dt 10-Dec-2019: penalties r.43, dustbins r.50). Sources: consolidated Act (Tripura HC site) https://thc.nic.in/Tripura%20State%20Lagislation%20Acts/Shops%20&%20Establishment%20Act,1970,%20Tripura%20As%20amended%20upto%205th%20amendment%20dt.24.05.2021.pdf — HIGH; 6th amendment rules (gazette) https://www.simpliance.in/uploads/notifdocs/637/Tripura%20Shops%20And%20Establishments%20%28Sixth%20Amendment%29%20Rules%2C%202019.pdf — HIGH; Rules compilation https://www.lawrbit.com/wp-content/uploads/2020/08/tripura-shops-and-establishments-acts-and-rules.pdf — OCR-quality text read, MEDIUM.
  - **Tripura S&E (Seventh Amendment) Act, 2025 (Tripura Act No. 11 of 2025)** — Governor's assent 1-Nov-2025; gazette 6-Nov-2025; in force on publication (preceded by Ordinance 17-May-2025): new **s.1(4): Act applies to shops/establishments where 20+ persons are employed**; daily hours 8.5 → 10; OT 120/yr → **144 per quarter**; written consent for pregnant/lactating women OT; spread-over 12.5 hrs; s.10 night/shift work substituted. Source: official Act PDF https://tripura.gov.in/sites/default/files/TSE%207th%20Amendment%20Act.pdf — HIGH.
- **2. Registers** (Rules 1970 — MEDIUM; OCR-quality text):
  - **Form I** — Register of hours of work and rest intervals (r.13; daily hours, opening/closing; same-day entries).
  - **Form J** — Leave register (r.21; incl. refusals/postponements).
  - **Form K** — Pay register (r.22; payment entries certified).
  - **Form S** — Register of overtime (r.32, monthly) [OCR reads "Form 8"; alphabetical sequence implies Form S — MEDIUM].
  - **Form U** — Register of employees (r.44; corrections within 3 days). **Form V** — Letter of appointment (r.45).
  - Act s.17: register of employees + other prescribed registers, kept up to date, employees may inspect/sign.
  - r.46: English, Bengali or Hindi; preserve **till end of next calendar year** (Forms I and J: till end of the **fourth** next calendar year per OCR — verify); separate sets at off-premises godowns.
- **3. Returns**: None found in Act/Rules — "none found", MEDIUM.
- **4. Electronic / consolidated**: **Tripura Ease of Compliance to Maintain Registers under various Labour Laws Rules, 2019** (Notfn F.21(98)-LAB/ENF/Labour Laws/2017/7062 dt 12-Sep-2019; combined Forms A–D, electronic allowed) covers **BOCW, CLRA, ISMW and Minimum Wages only — NOT the S&E Act**. Source: https://labour.tripura.gov.in/sites/default/files/2025-01/The%20Tripura%20Ease%20of%20Compliance%20to%20Maintain%20Registers%20under%20various%20Labour%20Laws%20Rules%2C%202019.pdf — HIGH. S&E: **r.47 Special registers** — Government may by written order deem an employer's own register (5 copies of format with application) as compliant — MEDIUM.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## UK — Uttarakhand

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Uttarakhand Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2017 (Uttarakhand Act No. 03 of 2018)** — Model-Act based; originally applied to shops/establishments employing **10+** workers (s.1(2)); deemed registration for already-registered units; registration via online portal (Labour Act Management System). Sources: PRS gazette copy https://prsindia.org/files/bills_acts/acts_states/uttarakhand/2018/Act%203%20of%202018%20UKD.pdf — HIGH; India Code https://www.indiacode.nic.in/handle/123456789/5070 — HIGH (listing). Act No. 12 of 2022 (s.21 decriminalisation) — enforcement notification NOT CONFIRMED.
  - **Uttarakhand S&E (RE&CS) (Amendment) Act, 2026 (Uttarakhand Act No. 06 of 2026)** — Governor's assent 15-Apr-2026; English translation notified No.129/XXXVI(3)/2026/25(1)/2026 dt **17-Apr-2026**; in force at once; repeals the 2025 Amendment Ordinance: **s.1(2) threshold "ten" → "twenty"**; s.8 daily hours 9 → 10 (continuous 5 → 6), shift incl. rest ≤ 12 hrs; OT 125 → **144 hrs** (per s.8(3)); s.9 "nine hours" → "ten hours"; **s.19 substituted**: "Every employer of shop and establishment shall furnish an annual return, in such a form and manner (including electronic form), as may be prescribed." Source: PRS copy (appended amendment, OCR'd) https://prsindia.org/files/bills_acts/acts_states/uttarakhand/2017/Act3of2017UK.pdf — HIGH.
  - 17-Feb-2026 notification: no restriction on opening/closing times (24x7), subject to hours/rest/leave compliance — TeamLease https://teamleaseregtech.com/updates/article/53450/govt-of-uttarakhand-issued-a-notification-allowing-shops-and-establishments-to-operate-24x7/ — secondary, MEDIUM.
  - **Rules** under the 2017 Act — name/year and text NOT CONFIRMED (not found). Draft Uttarakhand OSH Code Rules 2026 published 22-May-2026 (out of scope).
- **2. Registers**: s.18(1) registers and records "as may be prescribed" — specific forms NOT CONFIRMED (Rules not found). Source: Act text — HIGH (duty only).
- **3. Returns**: **Annual return** — s.19 (as substituted 2026): form/manner incl. electronic "as may be prescribed"; form number and due date — NOT CONFIRMED.
- **4. Electronic**: s.18(2): "The records may be maintained electronically or manually" (signed hard copy on demand at inspection) — HIGH. Annual return may be electronic (s.19) — HIGH. Ease-of-Compliance adoption for S&E — NOT CONFIRMED.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## AR — Arunachal Pradesh

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**: **No state S&E Act found — NOT CONFIRMED either way.** Three targeted searches found no gazette/India Code record of an "Arunachal Pradesh Shops and Establishments Act" (the "S&E Act 2017?" hint could not be verified). Secondary sources conflict: calcguru says no publicly available dedicated S&E Act (businesses use municipal/district trade licence) https://calcguru.in/?p=1484 — LOW; TaxClue/IndiaFilings (commercial registration sellers) assert registration under an "Arunachal Pradesh S&E Act" without citation https://www.taxclue.in/blog/shop-and-establishment-act-in-arunachal-pradesh — LOW; India Briefing lists Arunachal among states that framed OSHWC rules "while keeping their S&E Acts" — LOW (no citation). L&S state tracker https://employmentlaw.lkslaw.com/states/arunachal-pradesh lists only the four Labour Codes — MEDIUM. No evidence the Assam S&E Act 1971 was extended to Arunachal — NOT CONFIRMED.
  - What does exist: **Arunachal Pradesh OSH&WC Rules, 2022** (Notfn LAB(W)03/2020 dt 1-Apr-2022; Extraordinary Gazette No.132, Vol.XXIX, 2-May-2022) — out of scope but the operative registration/register regime. Source: gazette via Aparajitha https://compfie.aparajitha.com/wp-content/uploads/2022/07/26072022_LEI_04.pdf — MEDIUM.
- **2. Registers**: NOT CONFIRMED (no S&E Act/Rules found).
- **3. Returns**: none found.
- **4. Electronic/consolidated**: n/a (NOT CONFIRMED).
- **HRMS note**: default to "no state S&E registers"; rely on OSH Code/Code on Wages central registers. Flag for legal confirmation with AP Labour Dept.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## CH — Chandigarh (UT)

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: Y.** Punjab Labour Welfare Fund Act, 1965 as applicable to UT Chandigarh + Punjab LWF Rules, 1966.
- **Contribution:** employee **₹5 a month**, employer **₹20 a month**.
- **Frequency and due dates:** deducted monthly (last day of the month); submitted by **15 October** and **15 April**, half-yearly as in Punjab.
- **Applicability:** **10+** employees on any day in the preceding 12 months.
- Source: https://www.simpliance.in/labour-welfare-fund-detail/chandigarh (updated 24-Jul-2025) — LOW-MEDIUM

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Punjab Shops and Commercial Establishments Act, 1958 (Punjab Act No. 15 of 1958) as applicable to UT Chandigarh** + **Punjab Shops and Commercial Establishments Rules, 1958** (as applicable). Source: Chandigarh Administration Gazette No.035, 19-Mar-2026 (recites the Act as applicable) via https://www.simpliance.in/India/LEI/govt_notification/chandigarh/notification-on-registration-of-establishments-under-oshw-code-2020-and-exemption-from-registration-under-punjab-shops-and-commercial-establishment-act-1958-as-applicable-for-chandigarh-8992 — HIGH (gazette PDF read); Rules text https://www.simpliance.in/download/file/dXBsb2Fkcy9hY3RzL1RoZSBQdW5qYWIgU2hvcHMgYW5kIENvbW1lcmNpYWwgRXN0YWJsaXNobWVudHMgUnVsZXMsIDE5NTgucGRm — MEDIUM-HIGH.
  - **Punjab S&CE (Amendment) Act, 2025 extended to Chandigarh** by MHA **S.O.5647(E) dt 05-Dec-2025**: main provisions apply to establishments with **20+ workers**; <20 only s.13-A (intimation within 6 months); 10 hrs/day, 48/week; OT 144 hrs/quarter. Source: AscentHR https://ascent-hr.com/notification/extension-of-the-punjab-shops-commercial-establishments-amendment-act-2025-to-chandigarh/ — secondary, MEDIUM; recital confirmed in Chandigarh gazette (Notfn No.535694-HII(2)-2026/3140 dt 18-Mar-2026) — HIGH.
  - **Notfn No.535694-HII(2)-2026/3143 dt 18-Mar-2026** (s.28): establishments **registered under the OSH&WC Code 2020** are **exempted from s.13 (registration) and s.13A (intimation)** to avoid duplicate registration — HIGH (gazette text).
  - Notfn No.535694-HII(2)-2026/3140 dt 18-Mar-2026 amends exemption Notfn No.12/2/149-HII(2)-2025/12994 dt 14-Aug-2025 (conditions: ≤10 hrs/day, 48/week; spread-over ≤12 hrs) — HIGH. Other conditions of the 14-Aug-2025 notification (any register/record conditions) — NOT CONFIRMED.
- **2. Registers** (Punjab Rules 1958, r.5 — MEDIUM-HIGH): **Form C** — Register of employees; **Form D** — Register of wages; **Form E** — Register of deductions. **Form A** — intimation under s.10 (employment of persons); **Form B** — notice under s.20(1) (close day/hours, exhibited). Registration statement Form F, change Form G, register of establishments Form H (Inspector), Form I health certificate. r.6: English **and** Punjabi, ink, **bound and page-numbered**; r.7: preserve **2 years**. (Whether Chandigarh has a separately notified rule set — NOT CONFIRMED; Punjab Rules assumed as applied.)
- **3. Returns**: None found in Punjab Act/Rules — "none found", MEDIUM.
- **4. Electronic / consolidated**: Rules require bound registers; no electronic-register provision found — MEDIUM. Ease-of-Compliance adoption for S&E in Chandigarh — NOT CONFIRMED.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## PY — Puducherry (UT)

### A. Professional Tax (PT)
- **Levies on salaried: Y. It is a local-body tax.** Puducherry Municipalities Act, 1973 + Pondicherry Municipalities (Profession Tax) Rules, 1976; Puducherry Village and Commune Panchayats Act, 1973 + (Profession Tax) Rules, 1976. — MEDIUM
- **Frequency:** **half-yearly**.
- **Slabs (half-yearly income):** ≤ ₹99,999 nil; ₹1,00,000–2,00,000 ₹250; ₹2,00,001–3,00,000 ₹500; ₹3,00,001–4,00,000 ₹750; ₹4,00,001–5,00,000 ₹1,000; > ₹5,00,000 ₹1,250. Source: https://www.simpliance.in/professional-tax-detail/puducherry — LOW-MEDIUM. The minimum rates were revised in 2017 (finance.py.gov.in proftaxgo04jul17.pdf, seen only in a search result).
- **Due dates:** Simpliance gives "every 6 months starting last day of June"; Zoho gives 31 July and 31 January. **The sources conflict — NOT CONFIRMED.**

### B. Labour Welfare Fund (LWF)
- **Applies: N.** Simpliance shows "No Records Found". — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Puducherry (Pondicherry) Shops and Establishments Act, 1964 (Puducherry Act 36 of 1964)** + **Pondicherry Shops and Establishments Rules, 1964**. Applies to all shops/commercial establishments in the UT (no headcount floor found). Registration fee slabs (r.3(2), amended 2010 G.O.Ms.No.6/AIL/Lab/G/2010). **Renewal eliminated** — G.O.Ms.No.14/AIL/Lab./G/2022 dt 07-Oct-2022 (exemption under s.6; auto-renewal); Self-Certification-cum-Online Inspection Scheme (G.O.Ms.No.15/Lab./AIL/G/2022). Sources: Act text compilation https://www.lawrbit.com/wp-content/uploads/2020/08/pudducherry-shops-and-establishments-acts-and-rules-1.pdf — MEDIUM-HIGH; Gazette Extraordinary Part II No.61, 02-Nov-2022 https://styandptg.py.gov.in/2022/NOVEMBER/EXTRAORDINARYPART-II/1/61-PART-II%20dated%2002-11-2022.pdf — HIGH; India Code https://www.indiacode.nic.in/handle/123456789/6108 — HIGH (listing). No 2025–26 amendment found.
- **2. Registers**
  - Act s.51: registers/records/notices "as may be prescribed"; fines register and deductions register "in such form as may be prescribed". Legacy register form numbers under the 1964 Rules — NOT CONFIRMED (Rules text not retrieved).
  - **Integrated Register in Form-II and Form-III** (Form III = muster roll-cum-register of wages/deductions/overtime/advances) under **G.O.Ms.No.6/AIL/Lab/G/2021** — common to S&E Act + CLRA, ISMW, PW, MW, MB, ID, Bonus, Gratuity, MTW; maintained and "filed only through online"; compliance with the integrated register shields from penalty under individual Acts. Sources: draft G.O.Ms.No.129/AIL/Lab./G/2020 dt 03-Dec-2020, Gazette Extraordinary Part I No.182, 17-Dec-2020 https://styandptg.py.gov.in/2020/DECEMBER/EXTRAORDINARYPART-I/182-PART-I%20dated%2017-12-2020.pdf — HIGH (draft text read); final G.O. via Simpliance https://www.simpliance.in/India/LEI/govt_notification/puducherry/consolidated-annual-return-and-integrated-register-under-various-labour-laws-in-puducherry-1362 — MEDIUM (final order date NOT CONFIRMED; listed Nov-2021).
- **3. Returns**: **Consolidated Annual Return in Form-I** (financial year ending 31-Mar), **online only**, due **on or before 30-April** of the following year (or date under the specific Act) — covers S&E Act s.53(1) r/w Rule 22(1). Source: as above — HIGH (draft) / MEDIUM (final).
- **4. Electronic / consolidated**: Yes — Puducherry's own consolidated regime (Integrated Register Forms II+III, online; common return Form I) explicitly includes the S&E Act — MEDIUM-HIGH.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## AN — Andaman & Nicobar Islands (UT)

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Andaman and Nicobar Islands Shops and Establishments Regulation, 2004** (President's Regulation under Art.240; repeals Weekly Holidays Act 1942 as applied, s.36; s.29 registers; commencement by Administrator's notification) + **A&N Islands Shops and Establishments Rules, 2005** (as cited by SGCMS). Sources: Regulation + Rules compilation https://www.lawrbit.com/wp-content/uploads/2020/08/andama-and-nicobar-islands-shops-and-establishments-acts-and-rules.pdf — read, MEDIUM-HIGH; Simpliance https://simpliance.in/India/LEI/acts/Andaman-and-Nicobar-Islands/the-andaman-and-nicobar-islands-shops-and-establishments-regulation-2004-121 — MEDIUM. Rules' year (2005) — MEDIUM.
  - **A&N S&E (Amendment) Rules, 2026** (draft A&N Gazette Extraordinary No.222, 15-Jun-2026; final in force on gazette publication ~12-Aug-2026 — date to verify): r.4 electronic registration (Form A) with auto-approved certificate (Form B) valid for the establishment's lifetime; r.5 weekly closure omitted; r.8 24x7 with conditions (women night shift 7pm–6am, 19 conditions); r.12 fees "electronically"; **new r.12A**: 10–19 workers → OSH&WC Code registration only; **20+ workers** → Regulation registration (deemed sufficient for both). Source: SGCMS https://www.sgcms.com/regulatory-updates/andaman-and-nicobar-islands-shops-and-establishments-amendment-rules-2026/ — secondary, MEDIUM. Online Form C (notice of change within 15 days) since 2024 — AscentHR https://ascent-hr.com/notification/online-facility-to-submit-the-notice-of-change-under-the-shops-and-establishments-rules/ — MEDIUM.
- **2. Registers** (Rules r.11 — MEDIUM-HIGH):
  - **Form I** — Register of wages (at work spot); **Form II** — Wage slip (issued ≥1 day before disbursement).
  - **Form III** — Muster roll (attendance recorded daily within 3 hours of start of work).
  - **Form IV** — Register of overtime; **Form V** — Register of leave; **Form VI** — Register of employees; **Form VII** — Register of computation of cash value of concessional sale of food grains.
  - **Form F** — Register of compensatory holidays (hotels/restaurants/theatres working on holidays); Form E holiday roster (display + copy to Registering Officer); Form C change notice.
  - Preserve **3 years** from last entry (r.11).
- **3. Returns**: None found in Regulation/Rules — "none found", MEDIUM.
- **4. Electronic / consolidated**: Electronic registration and fees from 2026 amendment — MEDIUM. Electronic maintenance of registers / consolidated-register adoption — NOT CONFIRMED.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## DN/DD — Dadra & Nagar Haveli and Daman & Diu (UT)

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence). The UT adopted Gujarat's S&E Act, but no extension of the Gujarat LWF Act was found.

### C. Shops & Establishments
- **1. Act / Rules / applicability**
  - **Gujarat Shops and Establishments (Regulation of Employment and Conditions of Service) Act, 2019, as extended to the UT of DNH&DD** (India Code listing https://www.indiacode.nic.in/handle/123456789/19334 — HIGH, listing). Earlier regimes: Goa, Daman and Diu S&E Act 1973 + GDD S&E Rules 1975 (Daman & Diu) and DNH S&E Rules 2000 (DNH) — superseded by the 2025 Rules (secondary).
  - **DNH&DD S&E (RE&CS) Rules, 2025** — notified 24-Jun-2025 (Notfn LE/LI/DMN/SE-R/388/2025/172; one source dates it 27-Jun-2025), in force on gazette publication; supersede GDD Rules 1975 and DNH Rules 2000. Registration Form A for **10+ workers**; Form D intimation for <10. Sources: greytHR https://www.greythr.com/notifications/the-dadra-nagar-haveli-and-daman-diu-shops-and-establishments-regulation-of-emplo/ — secondary, MEDIUM; AscentHR https://ascent-hr.com/?p=34144 — secondary.
  - **DNH&DD S&E (RE&CS) Amendment Regulation, 2025 (Regulation No. 14 of 2025)** — President's Regulation, Gazette of India Extraordinary Part II s.1 No.51, **19-Nov-2025**: s.1(3) "ten or more workers" → **"twenty or more workers"**; daily hours 9 → 10; OT 125 → 144 hrs (s.15); s.14 substituted; **commencement on a date the Administrator notifies — commencement NOT CONFIRMED**. Source: gazette PDF via Simpliance (egazette.gov.in/WriteReadData/2025/267840.pdf) — HIGH.
  - **Draft 2026 amendment** (Notice LE/LI/DMN/Reforms Act/427/2026/206 dt 15-Jul-2026; Official Gazette Series II No.26, 17-Jul-2026): apply to all shops/establishments; omit ss.7–10 (intimation route); centralised online registration with deemed registration for OSH-Code/municipal/panchayat registrations; 24-hour opening. **Draft only.** Source: Corpseed https://www.corpseed.com/law-update/draft-dnh-dd-shops-and-establishments-amendment-2026 ; TeamLease https://teamleaseregtech.com/updates/article/58435/draft-dadra-and-nagar-haveli-and-daman-and-diu-shops-and-establishment — secondary, MEDIUM.
- **2. Registers** (2025 Rules per greytHR — MEDIUM, secondary):
  - **Form P** — Combined Muster Roll-cum-Wage Register (manual or digital).
  - **Form N** — Leave Book (earned, casual, festival leave); **Form O** — notice of leave accumulation limit (max 45 days).
  - **Form K** — Weekly holiday notice; **Form L** — shift-wise deployment list (display + share with Inspector); **Form M** — worker identity card; **Form J** — women night-shift written consent.
  - **Form S** — declaration of managerial staff; **Form T** — declaration of confidential staff (≤1% of staff, max 50).
  - Registration-side: Form A application, Form B certificate, Form C register of establishments, Form D intimation (<10), Form E acknowledgement, Form F register of intimated establishments, Form G change (within 30 days), Forms H/I closure, Form R compounding.
  - Preserve records **5 years** (secondary).
- **3. Returns**: **Annual Return in Form Q, due by 31-January** (greytHR — MEDIUM, secondary; another source says registration is one-time — consistent, as renewal ≠ return).
- **4. Electronic**: Form P combined muster-roll-cum-wage register may be maintained **digitally or manually** (greytHR — MEDIUM). Gujarat-Act pattern also permits electronic records (verify for UT text — NOT CONFIRMED).
- **HRMS note**: until the 2025 Regulation's commencement is notified, applicability = 10+ workers (Rules) / 20+ thereafter.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## LD — Lakshadweep (UT)

### A. Professional Tax (PT)
- **Levies: N.** Lakshadweep is not in any PT compilation. — NOT CONFIRMED (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**: **No S&E Act/Regulation found — NOT CONFIRMED (likely none).** Targeted search found no gazette/India Code S&E law for Lakshadweep; calcguru says a dedicated Lakshadweep S&E Act "could not be confirmed in the public record" and that the Administration is notifying Labour-Code rules / moving registration to Shram Suvidha https://calcguru.in/?p=1496 — LOW; commercial registration sites (IndiaFilings, RegisterKaro) assert a licence without citation — LOW. India Briefing lists Lakshadweep among territories that framed OSHWC rules "while keeping S&E Acts" — LOW (uncited, contradicts above).
- **2. Registers**: NOT CONFIRMED (none found).
- **3. Returns**: none found.
- **4. Electronic/consolidated**: n/a.
- **HRMS note**: default "no state S&E registers"; use OSH Code/Code on Wages central forms; flag for confirmation with the UT Labour Dept.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

## LA — Ladakh (UT)

### A. Professional Tax (PT)
- **Levies: N.** — MEDIUM (absence)

### B. Labour Welfare Fund (LWF)
- **Applies: N.** — NOT CONFIRMED (absence)

### C. Shops & Establishments
- **1. Act / Rules / applicability**: **J&K Shops and Establishments Act, 1966 + J&K S&E Rules, 1968, as continued/adapted for UT Ladakh — LOW / NOT CONFIRMED.** Under the J&K Reorganisation Act 2019 (s.96), MHA issued the UT of Ladakh Reorganisation (Adaptation of State Laws) Order, 2020 adapting 55 and repealing 87 erstwhile-State laws; whether the 1966 Shops Act is in the adapted list was **not confirmed** (Daily Excelsior report does not name it). calcguru (secondary) states Ladakh inherited the 1966 Act/1968 Rules and advises confirmation https://calcguru.in/?p=1494 — LOW. The **J&K 2025 Act (Act VI of 2025) extends only to UT of J&K** (s.1(2)) — does **not** apply to Ladakh — HIGH (Act text, see JK).
- **2. Registers**: If 1966 regime applies: same as J&K 1968 Rules — **Form L** register of employees (attendance/OT/wages), **Form G** leave-with-wages register, **Form H** leave book, **Form P** service card, notices Forms M/N/O; preserve 3 yrs — LOW (applicability unconfirmed).
- **3. Returns**: none found.
- **4. Electronic**: none found (1968 Rules paper-based; registers stamped by Inspector).
- **HRMS note**: treat as J&K-1966 template with an "applicability unconfirmed" flag.

### D. Other state periodic HR filings
- Not researched; section D was limited to the top 15 states. Any combined or integrated returns that also cover the S&E Act (e.g. PY Form-I) are noted under C.

---

# Summary table 1: Professional Tax (employer obligations for salaried staff)

Confidence for each cell is in the state section (A). "N (absence)" means no levy was found in any compilation.

| State | Levies (Y/N) | Frequency | Due date (payment / return) | Return form |
|---|---|---|---|---|
| MH | Y | Monthly if previous-year liability ≥ ₹1,00,000 (and in registration year); otherwise annual | **From Feb-2026:** monthly by the 15th of the following month; annual by **15 Mar**. Before: last day of the month / 31 Mar | Form III-B (e-return) + MTR-6 challan |
| KA | Y | Monthly + annual | Monthly statement and payment within 20 days of month-end (20th); annual within 60 days of FY end (~30 May) | Form 5-A (monthly); Form 5 (annual) |
| TN | Y (local body) | Half-yearly (Apr–Sep, Oct–Mar) | 30 Sep (deduct from Aug salary); 31 Mar (LOW) | Form 2 (LOW) |
| TG | Y | Monthly | 10th of the following month | Form V |
| AP | Y | Monthly | 10th of the following month | Form V |
| DL | N (absence) | – | – | – |
| HR | N (absence) | – | – | – |
| UP | N (absence) | – | – | – |
| GJ | Y | > 20 employees monthly; ≤ 20 quarterly payment + annual return | Monthly within 15 days of month-end; quarterly within 15 days of quarter-end; annual return within 15 days after year-end | Form 5 (monthly); Form 5-AA (annual); 5-C/5-CC (consolidated) |
| WB | Y | Monthly payment + annual return | Payment by the 21st of the following month; return by 30 Apr (e-file; often extended; Simpliance says 15 May) | Form III |
| KL | Y (local body) | Half-yearly | 31 Aug / end-Feb (LOW) | NOT CONFIRMED |
| RJ | N (absence) | – | – | – |
| MP | Y | Monthly payment + quarterly return (LOW) | Payment by the 10th of the following month; return 15 Jan/Apr/Jul/Oct (LOW) | Form 7 (LOW) |
| PB | Y (State Development Tax) | Deduction quarterly per Rules (monthly per others, conflicting) + annual return | Annual return by 30 Apr | PSDT-6 (return); PSDT-8 (challan) |
| OD | **N from 1-Apr-2026 (repealed)**; Y before | Before repeal: monthly deposit + annual return | FY 2025-26 annual return within 3 months of year-end (by 30-Jun-2026) | Form V (legacy) |
| AS | Y | Monthly (quarterly / half-yearly / annual with permission in Form IIIA) | 28th of the following month | Form III (LOW) |
| BR | Y | Annual | Deposit by 15 Nov (from Sept salary); late joiners by 15 Apr; statement PT-IV and return PT-VIII by 30 Nov | PT-IV, PT-VIII (PT-V for late joiners) |
| CG | N for salaried (2011 exemption notification; LOW-MEDIUM) | – | – | – |
| GA | NOT CONFIRMED (treat N) | – | – | – |
| HP | N (absence) | – | – | – |
| JH | Y | Quarterly payment + annual return | Within 15 days after each quarter (LOW); annual 31 Oct (LOW) | JPT-203 |
| JK | N (absence) | – | – | – |
| MN | Y | Annual (LOW) | 30 Mar (LOW) | NOT CONFIRMED |
| ML | Y (rates by notification since 2022) | Quarterly payment implied by 2022 Act; Simpliance says monthly (conflict) | End of the month after each quarter (inferred) / 28th monthly (LOW) | Form VII-C (LOW) |
| MZ | Y | Annual return (LOW) | 30 Jun (LOW) | NOT CONFIRMED |
| NL | Y | Annual return (LOW) | 30 Apr (LOW) | Form V (LOW) |
| SK | Y | Quarterly (LOW) | 31 Jul / 31 Oct / 31 Jan / 30 Apr (LOW) | Form 5 (LOW) |
| TR | Y | NOT CONFIRMED | NOT CONFIRMED | NOT CONFIRMED |
| UK | N (absence) | – | – | – |
| AR | N (absence) | – | – | – |
| CH | N (absence) | – | – | – |
| PY | Y (local body) | Half-yearly | NOT CONFIRMED (sources conflict) | NOT CONFIRMED |
| AN | N (absence) | – | – | – |
| DN/DD | N (absence) | – | – | – |
| LD | N (NOT CONFIRMED) | – | – | – |
| LA | N (absence) | – | – | – |

# Summary table 2: Labour Welfare Fund

Amounts are per employee per contribution period unless marked "/month". Confidence for each cell is in the state section (B).

| State | Applies (Y/N) | Employee | Employer | Frequency | Periods | Due date |
|---|---|---|---|---|---|---|
| MH | Y | ₹25 | ₹75 | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| KA | Y | ₹50 | ₹100 (+ Govt ₹50) | Annual | Calendar year (deduct Dec) | 15 Jan |
| TN | Y | ₹20 | ₹40 (+ Govt ₹20) | Annual | Calendar year (deduct Dec) | 31 Jan |
| TG | Y | ₹2 | ₹5 | Annual | Calendar year (deduct Dec) | 31 Jan |
| AP | Y | ₹30 | ₹70 | Annual | Calendar year (deduct Dec) | 31 Jan |
| DL | Y | ₹0.75 | ₹2.25 | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| HR | Y | 0.2% of wages, max ₹35/month (Jan-2026) | 2 × employee (max ₹70/month) | Monthly | Each month | Last day of the month (LOW) |
| UP | N (absence) | – | – | – | – | – |
| GJ | Y | ₹6 | ₹12 | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| WB | Y | ₹3 | ₹30 (LOW; verify) | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| KL | Y (sector funds; S&CE Workers WF shown) | ₹50/month | ₹50/month | Monthly | Each month | 5th of the month |
| RJ | N (absence) | – | – | – | – | – |
| MP | Y | ₹10 | ₹50 (revised Jan-2025) | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| PB | Y | ₹5/month | ₹20/month | Deducted monthly, paid half-yearly | Apr–Sep, Oct–Mar | 15 Oct, 15 Apr |
| OD | Y | ₹10 | ₹20 | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| AS | N (absence) | – | – | – | – | – |
| BR | N (absence) | – | – | – | – | – |
| CG | Y | ₹15 | ₹45 | Half-yearly | Jun, Dec | 15 Jul, 15 Jan |
| GA | Y | ₹10 (official page) vs ₹60 (Simpliance), CONFLICT | ₹30 vs ₹180, CONFLICT | Half-yearly | Jan–Jun, Jul–Dec | 31 Jul, 31 Jan |
| HP | N (absence) | – | – | – | – | – |
| JH | N (absence) | – | – | – | – | – |
| JK | N (absence) | – | – | – | – | – |
| MN | N (absence) | – | – | – | – | – |
| ML | N (absence) | – | – | – | – | – |
| MZ | N (absence) | – | – | – | – | – |
| NL | N (absence) | – | – | – | – | – |
| SK | N (absence) | – | – | – | – | – |
| TR | N (absence) | – | – | – | – | – |
| UK | N (absence) | – | – | – | – | – |
| AR | N (absence) | – | – | – | – | – |
| CH | Y | ₹5/month | ₹20/month | Deducted monthly, paid half-yearly | Apr–Sep, Oct–Mar | 15 Oct, 15 Apr |
| PY | N (absence) | – | – | – | – | – |
| AN | N (absence) | – | – | – | – | – |
| DN/DD | N (absence) | – | – | – | – | – |
| LD | N (absence) | – | – | – | – | – |
| LA | N (absence) | – | – | – | – | – |

## Highest-value open items to close before encoding as rules
1. **Goa LWF amounts:** the official page says ₹10 / ₹30, Simpliance says ₹60 / ₹180. Read the 11-Jun-2024 "Amendment of GLWB" notification.
2. **West Bengal LWF employer amount (₹30?)** and **MP LWF ₹50 employer:** each rests on a single secondary source. Confirm with WBLWB and the MP Shram Kalyan Mandal.
3. **Maharashtra PT 2026 due-date amendment:** fetch the 28-Feb-2026 notification from mahagst.gov.in. Also confirm the MH LWF ₹25 / ₹75 March-2024 gazette.
4. **Odisha PT Repeal Bill 2026:** confirm it was passed, or the ordinance lapses and PT revives.
5. **Kerala:** half-yearly PT due dates per local body; whether the Kerala LWF Act 1975 applies to the employer's establishments, and its contribution rates.
6. **Chhattisgarh PT on salaried staff:** the 2011 exemption notification.
7. **North-east PT return mechanics** (TR, MN, MZ, NL, SK, ML): all LOW or NOT CONFIRMED.
8. **Punjab PSDT:** monthly or quarterly deposit.
9. **MP PT return** (Form 7, quarterly?).
10. **Telangana LWF applicability** (20+ employees?).

## Sources that matter most (official)
- MH PT Rules (consolidated text): https://www.comply360.in/labor-law-library/wp-content/uploads/2024/02/THE-MAHARASHTRA-STATE-TAX-ON-PROFESSIONS-TRADES-CALLINGS-AND-EMPLYOMENTS-RULES-1975-.pdf
- KA PT employer portal notice: https://ptax.karnataka.gov.in/ptemployer · KLWB: https://klwb.karnataka.gov.in/
- TN LWB "Source of Fund": https://www.lwb.tn.gov.in/sub_page_content.php?menu_id=NDU1&submenu_id=ODUz&dept_id=205&lang=MQ==
- TG PT rules and Form V: https://www.tgct.gov.in/tgportal/AllActs/APPT/APPTRules.aspx · https://www.tgct.gov.in/tgportal/AllActs/APPT/forms/form5.html
- GJ PT (SMC FAQ): https://www.suratmunicipal.gov.in/FAQs/ProfessionTax
- HR LWF rates: https://hrylabour.gov.in/content/cms/MTU
- KL PT G.O. 1149/2024/LSGD: https://go.lsgkerala.gov.in/files/go20240627_37169.pdf
- OD PT Repeal Ordinance: https://finance.odisha.gov.in/sites/default/files/2026-04/1496-LAW%20DEPTT..pdf
- ML PT Amendment Act 2022: https://PRSINDIA.org/files/bills_acts/acts_states/meghalaya/2022/Act15of2022Meghalaya.pdf
- TR PT rates: https://ptax.tripura.gov.in/TaxDetails.aspx
- Goa LWB: https://labour.goa.gov.in/labour-welfare-board/
- S&E and D official sources are cited inline in sections C and D.
