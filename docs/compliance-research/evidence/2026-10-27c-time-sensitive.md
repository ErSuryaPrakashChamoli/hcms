# Time-sensitive statutory items: re-check, 2026-10-27 (final pass)

Scope: A8 (Odisha PT), A3 (West Bengal PT), A2 (Haryana LWF) and A5 (bonus), the items with deadlines in the next
two months. The reads were made on 2026-10-27 between about 16:05 and 16:58 IST by a research agent working from
this session's brief, using web search and page fetches only. No TLS error, CAPTCHA or login was bypassed.
Times were not logged per URL. "Guessed" marks a URL built from a known file-naming pattern; a 404 on one proves
nothing. Scanned PDFs were read as page images; nothing downloaded was executed.

**Independently re-checked in this session (by 17:03 IST):**
- https://hrylabour.gov.in/content/cms/MTU shows "January - 2026 | Rs. 0.2% of salary of employee or wages or any
  remuneration subject to a limit of Rs. 35/- | Twice the amount contributed by such employee | Rs. 105 (Per month)".
  This is not new: the rule-change register (P9) already cites the board's official table for ₹35.
- The Odisha Assembly Secretariat notice No. LM-51/2026-6543 (147)/L.A., dated 28 September 2026, on cms.neva.gov.in
  (a hand-signed scan): "the SEVENTH SESSION of the SEVENTEENTH ODISHA LEGISLATIVE ASSEMBLY, which commenced on the
  22nd September, 2026 has been adjourned sine-die on the 28th September, 2026."

**Re-checked again at about 20:00 IST (final pass, by `curl` with its default identity, no TLS bypass):**
- **Code on Wages, 2019: now OFFICIAL_PRIMARY_SOURCE_VERIFIED.**
  - Source: https://www.indiacode.nic.in/bitstream/123456789/15793/1/aA2019-29.pdf (HTTP 200, PDF, SHA-256
    `846f2e27…`, no embedded signature). Read on India Code over HTTPS, where the fetch tool had been refused (403).
  - s.39(1): "All amounts payable to an employee by way of bonus under this Code shall be paid by crediting it in
    the bank account of the employee by his employer within a period of eight months from the close of the
    accounting year".
  - Proviso: an extension by the appropriate Government "shall not in any case exceed two years".
  - s.2(a): "“accounting year” means the year commencing on the 1st day of April".
  - So FY 2025-26 bonus is due by **30 November 2026**, absent an extension order or a pending dispute (s.39(2)).
- **Constitution, Art. 213(2)(a): not read.** India Code's Constitution PDF
  (https://www.indiacode.nic.in/bitstream/123456789/15240/1/constitution_of_india.pdf) returned HTTP 504 twice.
  legislative.gov.in renders its Constitution page by script. The Odisha date of about 3 November 2026 remains a
  calculation: six weeks from the official reassembly date of 22 September 2026.

**Access failures may be tool-specific.** egazette.gov.in failed TLS ("unable to verify the first certificate"), and
labour.gov.in and indiacode.nic.in returned 403. A person with a normal browser may be able to read:
- https://egazette.gov.in/WriteReadData/2026/275747.pdf (S.O. 4711(E));
- the India Code text of Code on Wages s.39.

They were not bypassed.

---

---

## A8: Odisha professional tax (Repeal Ordinance 02/2026 and Repeal Bill)

### URLs tried

| URL | Host | Read 27-10-2026 | Result |
|---|---|---|---|
| https://finance.odisha.gov.in/en/notification/tax-profession | finance.odisha.gov.in | yes | read. 8 items. Top item is still "Repeal Ordinance, 2026", No. 1496, 21/04/2026. No Repeal Act listed. |
| https://finance.odisha.gov.in/sites/default/files/2026-04/1496-LAW%20DEPTT..pdf | finance.odisha.gov.in | yes | read (PDF text extracted) |
| https://finance.odisha.gov.in/ | finance.odisha.gov.in | yes | read. News list (latest 25-09-2026) has no profession-tax item. |
| https://finance.odisha.gov.in/or/financial-notification | finance.odisha.gov.in | yes | read. Table renders empty (no rows). |
| https://finance.odisha.gov.in/en/notification | finance.odisha.gov.in | yes | 404 |
| https://odishaassembly.nic.in/ | odishaassembly.nic.in | yes | 301 redirect to assembly.odisha.gov.in |
| https://assembly.odisha.gov.in/ | assembly.odisha.gov.in | yes | read. News Flash shows only the 6th-session calendar ("17-FEB TO 08-ARR 2026"). No Bills or Acts section. |
| https://assembly.odisha.gov.in/ViewAssemblyBulletin.aspx | assembly.odisha.gov.in | yes | read. Content is behind a dropdown postback, so no bulletin text could be retrieved. |
| https://assembly.odisha.gov.in/ViewProvisionalCalendar.aspx | assembly.odisha.gov.in | yes | read. Same dropdown problem; no content. |
| https://assembly.odisha.gov.in/ViewNotifications.aspx | assembly.odisha.gov.in | yes | read. Latest entry is 2022; nothing on the 7th session. |
| https://assembly.odisha.gov.in/ProvesionalCalendar/17th-7th%20Session.pdf | assembly.odisha.gov.in | yes | 404 (guessed) |
| https://odisha.neva.gov.in/ | odisha.neva.gov.in (NeVA) | yes | read. Shows "17th Assembly, 7th Session", status "not in session", and links to the two PDFs below. |
| https://cms.neva.gov.in/NeVA/OR/FileStructures//AssemblyFiles/17/7/1_20260905134508974_14dab5843e444a259ecc90639898b98a_oth.pdf | cms.neva.gov.in | yes | read. Scanned PDF, read as page images. Provisional calendar for the 7th session. |
| https://cms.neva.gov.in/NeVA/OR/FileStructures//Notices/0da28548-5e58-4cd9-acf6-5a936b1635b4.pdf | cms.neva.gov.in | yes | read. Scanned PDF, read as page image. Sine-die notice. |
| https://odisha.neva.gov.in/Bill ; /Bill?IsStateDashBoard=True ; /Business ; /Notice/Index/OR | odisha.neva.gov.in | yes | read. Content loads by script; no bill records could be retrieved. |
| https://law.odisha.gov.in/ | law.odisha.gov.in | yes | read (navigation only) |
| https://law.odisha.gov.in/or/publication/acts-and-ordinances/acts | law.odisha.gov.in | yes | read. Newest listed Acts are 2021/2022. No 2026 Act. |
| https://law.odisha.gov.in/or/publication/acts-and-ordinances/ordinances | law.odisha.gov.in | yes | read. Newest listed is 2017. Ordinance 02/2026 is not listed either. |
| https://law.odisha.gov.in/or/publication/odisha-gazette/extraordinary-gazette | law.odisha.gov.in | yes | read. Page 1 shows 1942 gazettes; no year filter available to the tool. |
| https://law.odisha.gov.in/or/notification/law-notification | law.odisha.gov.in | yes | read. Latest entry 19/08/2026 (OSLS Rules). Nothing on profession tax. |
| https://lawodisha.gov.in/ | lawodisha.gov.in | yes | DNS not found |
| https://egazette.odisha.gov.in/ ; /extraordinary ; /bills_acts | egazette.odisha.gov.in | yes | read. Extraordinary page 1 of 620 has private name and partnership notices only. bills_acts: "In this section no document available at this moment." |
| https://egazette.odisha.gov.in/search_gazette | egazette.odisha.gov.in | yes | read. Search form only (needs a form submit); no results. |
| https://odishatax.gov.in/ ; /opt_notification.php | odishatax.gov.in | yes | read. No profession-tax repeal notice. The notifications table renders empty. |
| https://prsindia.org/bills/states?state=Odisha&year=2026 | prsindia.org (non-govt) | yes | read. Lists Ordinance 2 of 2026 only. No Repeal Bill or Act. |
| https://teamleaseregtech.com/updates/article/60568/odisha-state-tax-on-professions-trades-callings-and-employments-repeal/ | non-govt | yes | read (secondary; article dated 21-10-2026) |
| https://www.deccanchronicle.com/nation/amid-repeated-disruptions-odisha-assembly-adjourned-sine-die-1991344 | news | yes | read (28-09-2026 21:45 IST) |
| https://sambadenglish.com/latest-news/odisha-assembly-adjourned-sine-die-after-functioning-for-only-five-days-12587549 | news | yes | read (28-09-2026 19:21 IST) |
| https://www.drishtiias.com/state-pcs-current-affairs/odisha-assembly-passes-jan-vishwas-and-indian-stamp-amendment-bills | non-govt | yes | read. Names 2 of the 4 bills passed; does not name the profession-tax bill. |

### Verbatim quotes

- Ordinance PDF, finance.odisha.gov.in (read 27-10-2026):
  - "No. 1496, CUTTACK, TUESDAY, APRIL 21, 2026/BAISAKHA 1, 1948"
  - "No.5969─I-Legis-22/2026/L.─ The following ordinance promulgated by the Governor of Odisha on the dated 21st April, 2026 is here by Published for General information."
  - "ODISHA ORDINANCE 02 OF 2026"
  - s.1(2): "This Ordinance shall come into force with effect from dated 1st April, 2026."
  - s.2(1): "The Odisha State Tax on Professions, Trades, Callings and Employments Act, 2000 is hereby repealed."
  - The Governor's signature line reads "Dated the 20th April, 2026"; the gazette is dated 21-04-2026.
- Sine-die notice, cms.neva.gov.in (signed scan; read 27-10-2026). Header: "No.LM- 51 /2026- 6543 (147)/L.A., Bhubaneswar, Dated the 28th September, 2026" (the numbers are handwritten). Text: "I am directed to inform you that the SEVENTH SESSION of the SEVENTEENTH ODISHA LEGISLATIVE ASSEMBLY, which commenced on the 22nd September, 2026 has been adjourned sine-die on the 28th September, 2026."
- Provisional calendar, cms.neva.gov.in (signed scan; Memo No. 5792 (227)/L.A., dated 03.09.2026): "PROVISIONAL CALENDAR OF SITTINGS SEVENTEENTH ASSEMBLY SEVENTH SESSION 2026". First sitting: "Tuesday, the 22nd [September] Official Business".
- TeamLease (secondary, 21-10-2026):
  - "introduced the Odisha State Tax on Professions, Trades, Callings and Employments (Repeal) Bill, 2026"
  - "It was published in the Odisha Gazette Extraordinary on September 23, 2026"; it cites "Notification No. 6283/LA"
  - "The provided document is a Bill and does not establish that the repeal has received final enactment."
- Deccan Chronicle (secondary, 28-09-2026):
  - "four important Bills were passed during the session"
  - It lists "the Odisha State Tax on Professions, Trades, Callings and Employments Bill, 2026". Note the title as printed has no "(Repeal)".
  - "The Monsoon Session commenced on September 22."
- Sambad English (secondary, 28-09-2026) gives the same bill title in its list of bills passed.

### Current position

- Ordinance 02 of 2026 (Gazette Ext. No. 1496, 21-04-2026) repeals the 2000 Act from 1-04-2026. Re-confirmed on the official host today.
- The Legislature reassembled on 22-09-2026. This is new in this pass (earlier: secondary) and comes from an official Assembly notice on NeVA. The session was adjourned sine die on 28-09-2026.
- A Repeal Bill was introduced on 22-09-2026 (TeamLease). Two news outlets report it passed by 28-09-2026. Both sources are secondary.
- No Act number, Governor's assent or Gazette publication of a Repeal Act was found on any official host checked. The finance.odisha.gov.in listing still shows only the Ordinance.
- Six-week window under Art. 213(2)(a): 22-09-2026 plus 42 days = 03-11-2026. This is my own calculation; a different day-count convention could move it by one day. The Article text was not re-read today.
- Unless the Repeal Act is assented and in force by that date, the Ordinance stops operating then. What happens to the repeal if the Ordinance lapses first is a question for counsel; it is not answered here.
- I found no official statement on profession tax after the Ordinance. The DDO letter No. 10676 of 22-04-2026 appears only in secondary sources.

### Confidence

- Ordinance and its effective date: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
- Reassembly date 22-09-2026 and sine die 28-09-2026: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
- Bill introduced and passed: SECONDARY_SOURCE_ONLY.
- Assent, Act number, Gazette publication: NOT_CONFIRMED.
- **Item overall: OFFICIAL_SOURCE_PARTIALLY_VERIFIED.**

### What remains unconfirmed

- Whether the Bill received the Governor's assent; its Act number and Gazette number and date.
- The exact title of the Bill as passed (news drops "(Repeal)").
- The official record of the Bill's passage. NeVA and Assembly pages load by script and could not be read.
- Whether the Repeal Act takes effect from 1-04-2026 and repeals the Ordinance.
- Whether anything will be in force on 03-11-2026.

---

## A3: West Bengal professional tax (1406-F.T. commencement; final schedule 1607-F.T.)

### URLs tried

| URL | Host | Read 27-10-2026 | Result |
|---|---|---|---|
| https://comtax.wb.gov.in/ | comtax.wb.gov.in | yes | read. No schedule item in News & Updates. |
| https://comtax.wb.gov.in/GST/GST_Acts_Rules/gst_acts_rules.html | comtax.wb.gov.in | yes | read. Lists 1406-FT_20260820.pdf and 756L_20260820.pdf. No 1407 or 1607. |
| https://comtax.wb.gov.in/GST/GST_Acts_Rules/1406-FT_20260820.pdf | comtax.wb.gov.in | yes | read (PDF text extracted) |
| https://comtax.wb.gov.in/vatnotifications.htm | comtax.wb.gov.in | yes | read. Latest are 1695-FT and 1696-FT of 29/09/2026 (TRO amendments under WBST/WBVAT). The only "1607-FT" is a 2018 CST notification. No 1407. |
| https://comtax.wb.gov.in/Act_Rule_Schedule_Form/vat_cst_wbst_acts_rules.html | comtax.wb.gov.in | yes | read. Only PT_Act.pdf and PT_Rule.pdf. |
| https://comtax.wb.gov.in/GST/GST_Notifications/gst_notification_type.html | comtax.wb.gov.in | yes | read. GST categories only. |
| https://comtax.wb.gov.in/communications/communications.html | comtax.wb.gov.in | yes | read. Only 2026 PT item: return deadline for 2025-26 extended to 31-05-2026. |
| https://comtax.wb.gov.in/GST/GST_Acts_Rules/1607-FT_20260916.pdf ; …_20260917.pdf ; …_20260918.pdf | comtax.wb.gov.in | yes | 404 (guessed) |
| https://comtax.wb.gov.in/notification/1607-FT.pdf ; /notification/1607-FT_20260916.pdf | comtax.wb.gov.in | yes | 404 (guessed) |
| https://comtax.wb.gov.in/notification/1407-FT.pdf ; /GST/GST_Acts_Rules/1407-FT_20260820.pdf | comtax.wb.gov.in | yes | 404 (guessed). I could not find where 1407-F.T. sits on comtax in any listing I checked. |
| https://wbprofessiontax.gov.in/ | — | yes | DNS not found |
| https://professiontax.wb.gov.in/ ; /landing/main-landing-page | professiontax.wb.gov.in | yes | Page is a script app; only the heading "Profession Tax" could be retrieved. |
| https://egazette.wb.gov.in/ | — | yes | DNS not found |
| https://wbfin.nic.in/ | wbfin.nic.in | yes | connection refused (port 443) |
| https://finance.wb.gov.in/ | finance.wb.gov.in | yes | read. No PT item. |
| https://finance.wb.gov.in/Pages/Publication.aspx?type=14 ; /Pages/Publication.aspx ; /Pages/Revenue_Branch2.aspx | finance.wb.gov.in | yes | "File Not Found" |
| https://www.wbcomtax.gov.in/ptax.htm | wbcomtax.gov.in | yes | connection refused (port 443) |
| https://teamleaseregtech.com/updates/article/60103/west-bengal-finance-department-amendment-of-professional-tax-schedule/ | non-govt | yes | read (secondary). Its document viewer link has no file. |
| https://reconscribe.com/west-bengal-professional-tax/ | non-govt | yes | read (secondary) |
| https://www.dcpsol.com/west-bengal-professional-tax-slabs-revised-2026-notification-1607-ft | non-govt | yes | read (secondary; no slabs or Part C date) |
| https://www.praansconsultech.com/gazette-details/west-bengal-introduces-major-changes-to-professional-tax-slabs | non-govt | yes | read. Body text not retrievable. |
| https://www.incorpx.io/blog/west-bengal-professional-tax-new-slabs-2026 | non-govt | yes | 403 |
| https://wbxpress.com/schedule-rates-tax-professions-trades-callings-employments-2026/ | mirror | yes | read. Transcribes draft 1407-F.T. (SAR-470), not 1607. |
| https://wbxpress.com/circulars/finance-2026/ | mirror | yes | read. Has 1407-FT (18.08.2026). No 1607. |
| https://wbxpress.com/tools/professional-tax-deduction-calculator/ | mirror | yes | read |
| https://www.sgcms.com/regulatory-updates/west-bengal-overhauls-professional-tax-new-schedule-notified/ | non-govt | yes | read (article dated 22-08-2026; describes the draft) |

### Verbatim quotes

- 1406-F.T., comtax.wb.gov.in (read 27-10-2026). Header: "No. WB(Part-I)/2026/SAR-469", "TUESDAY, AUGUST 18, 2026", "No. 1406-F.T. Dated, Howrah, the 18th day of August, 2026".
  - (b) "to appoint the 1st day of October, 2026, as the date on which sub-section (1), clause (a) of sub-section (2) and sub-section (4) of section 2 of the said Act shall come into force;"
  - (c) "to appoint such dates on which the provisions of the Schedule notified under clause (b) of sub-section (2) of section 2 of the said Act shall come into force."
- Final 1607-F.T. (secondary only):
  - Reconscribe: "Notification 1607-F.T. dated 16 September 2026 (Kolkata Gazette, Extraordinary, SAR-588)". Also: "the entries for professionals, traders and Part C apply from 1 April 2027".
  - TeamLease salary slabs:
    - "NIL for salary up to ₹20,000"
    - "₹100 for above ₹20,000 up to ₹30,000"
    - "₹140 for above ₹30,000 up to ₹50,000"
    - "₹170 for above ₹50,000 up to ₹1,00,000"
    - "₹208 for above ₹1,00,000"
  - TeamLease dates: Part A Sl. 1 and Part B "take effect from 1 October 2026"; Part A Sl. 2 and 3 and Part C "apply from the financial year commencing 1 April 2027".
- Draft 1407-F.T. (wbxpress mirror of SAR-470):
  - "Any objection or suggestion received within fourteen (14) days from the date of publication"
  - "Sl. No. 1 of Part A, whole of Part B and whole of Part C of the Schedule will come into force with effect from the 1st day of October, 2026."
  - The salary slabs are the same as the TeamLease 1607 figures.
- SGCMS (22-08-2026, describing the draft): Part C "Effective 1 October 2026".
- wbxpress calculator (mirror): the new slab table is headed "Effective From 01.04.2027". This is an outlier: it conflicts with its own draft transcription (1-10-2026) and with every 1607 report.

### Current position

- 1406-F.T. is primary. It brings s.2(1), s.2(2)(a) and s.2(4) of the WB Finance Act, 2026 into force on 1-10-2026. Clause (c) leaves the Schedule's commencement dates to the Schedule notification itself.
- The final Schedule 1607-F.T. (16-09-2026, reported as SAR-588) is still not on any official host I could reach. The comtax listings do not carry it. The PT portal is a script app. egazette.wb.gov.in and wbprofessiontax.gov.in do not resolve. wbfin.nic.in and wbcomtax.gov.in refuse connections.
- Salary slabs from secondary sources: nil up to ₹20,000; ₹100 above ₹20,000 to ₹30,000; ₹140 above ₹30,000 to ₹50,000; ₹170 above ₹50,000 to ₹1,00,000; ₹208 above ₹1,00,000.
- Effective dates from secondary sources: salary slabs from 1-10-2026. Part C from 1-04-2027 in the final, against 1-10-2026 in the draft.

### Confidence

- 1406-F.T.: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
- 1607-F.T. contents and slabs: SECONDARY_SOURCE_ONLY.
- Part C date: CONFLICTING_SOURCES. The draft says 1-10-2026. Sources that describe the final say 1-04-2027. No source gives the final text verbatim.
- **Item overall: SECONDARY_SOURCE_ONLY.**

### What remains unconfirmed

- The official text of 1607-F.T. and its SAR number.
- Whether the final changed any salary band from the draft.
- The final Part C date. Resolving the wbxpress "01.04.2027" outlier for salary slabs.

---

## A2: Haryana labour welfare fund (2026 cap; HLWB/REV/2026/3436; last date)

### URLs tried

| URL | Host | Read 27-10-2026 | Result |
|---|---|---|---|
| https://hlwb.gov.in/ | — | yes | DNS not found |
| https://hrylabour.gov.in/ | hrylabour.gov.in | yes | read. Undated homepage notice on depositing contributions before 31-01-2026. |
| https://hrylabour.gov.in/content/cms/MTU | hrylabour.gov.in | yes | **read. The contribution table has a "January - 2026" row with the ₹35 cap.** |
| https://hrylabour.gov.in/home/readmore?cat_id=46&status=1 | hrylabour.gov.in | yes | read (page 1: 10 items, 21-04-2026 to 05-10-2026). No LWF notification; nothing between the 07-05-2026 and 20-05-2026 items. |
| https://hrylabour.gov.in/hlwbmedia | hrylabour.gov.in | yes | read. No contribution notice. |
| https://hrylabour.gov.in/hlwbmedia/employer_Phamplate | hrylabour.gov.in | yes | Images only (2 JPGs); not read. |
| https://hrylabour.gov.in/content/labour_laws ; /content/labour_policy | hrylabour.gov.in | yes | read. Links to the Act and Rules; no 2026 LWF notification. |
| https://www.hrylabour.gov.in/staticdocs/labourActpdfdocs/Punjab_Labour_Welfar_Fund_Act.pdf | hrylabour.gov.in | yes | read (PDF text extracted). PDF CreationDate 27-11-2025. Consolidated text. |
| https://egazetteharyana.gov.in/ | egazetteharyana.gov.in | yes | **TLS error: "unable to verify the first certificate"** (not bypassed). The earlier pass today reported a CAPTCHA. |
| https://teamleaseregtech.com/updates/article/55716/govt-of-haryana-revised-the-labour-welfare-fund-contribution-ceiling-from-january-2026/ | non-govt | yes | read (secondary; article 10-05-2026; no official URL) |
| https://www.scconline.com/blog/post/2026/05/11/haryana-labour-welfare-fund-contribution-revised-2026/ | non-govt | yes | read (secondary; links only to scconline copies) |

### Verbatim quotes

- hrylabour.gov.in/content/cms/MTU (read 27-10-2026):
  - "Under section 9-A the provision of rate of contribution from the employees working in the Industrial and Commercial Establishments of Haryana State and Employers is as under."
  - Table row: "January - 2026 | Rs. 0.2% of salary of employee or wages or any remuneration subject to a limit of Rs. 35/- | Twice the amount contributed by such employee | Rs. 105 (Per month)". The previous row is January 2025, with the Rs. 34/- limit.
- Consolidated Act PDF on hrylabour.gov.in, s.9A (read 27-10-2026):
  - (2) "Every employer shall pay to the Fund both his contribution and the contribution of the employee before the 31st December of every year through online mode in favour of Welfare Commissioner."
  - (4) "Any employer who fails to pay the contribution amount within a period of one month from the date specified under sub-section (2), shall be liable to pay interest at the rate of twelve percent per annum from the said date until such time the amount is actually deposited with the Welfare Commissioner;"
  - (1) in this consolidation still reads "subject to a limit of rupees thirty-four", with the proviso "the limit specified above shall be indexed annually to the consumer price index beginning from first of January each year."
  - Footnote 5: "Section 9A substituted by Vide office letter no. HLWB/REV/2025/1306-1530 dated 07th March, 2025".
- hrylabour.gov.in homepage notice (undated). Fragments only: "श्रम कल्याण निधि अधिनियम, 1965 की धारा 9(ए) के अंतर्गत देय अंशदान" … "दिनांक 31-01-2026 से पूर्व अनिवार्य रूप से जमा करा दें" ("contribution payable under section 9(A) of the Labour Welfare Fund Act, 1965 … must be deposited before 31-01-2026").
- TeamLease (secondary): "HLWB/REV/2026/3436"; the cap "has been increased from ₹34 to ₹35 per month"; "with effect from January 01, 2026".

### Current position

- **Re-confirmed** (the rule-change register already cited this table): the 2026 cap is readable on an official host. The Labour Department page shows: employee 0.2% of wages capped at ₹35 a month; employer twice the employee amount; total ₹105 a month; effective January 2026.
- The instrument itself (letter HLWB/REV/2026/3436 of 08-05-2026) was still not found on any official host. hlwb.gov.in does not resolve, and the Haryana e-Gazette failed TLS.
- Last date for the 2026 contribution: before 31-12-2026, per s.9A(2) in the official consolidated text.
- Interest at 12% a year applies if payment is not made within one month of that date (s.9A(4)).
- The homepage notice ("deposit before 31-01-2026") fits that one-month window applied to 2025 contributions. That link is my reading, not a statement on the site.

### Confidence

- Cap ₹35, employer twice, from January 2026: OFFICIAL_PRIMARY_SOURCE_VERIFIED. Caveat: this is a departmental web page, not the instrument.
- Instrument number and date HLWB/REV/2026/3436, 08-05-2026: SECONDARY_SOURCE_ONLY.
- Last date (before 31 December; interest after one month): OFFICIAL_PRIMARY_SOURCE_VERIFIED. Caveat: it comes from a departmental consolidation (PDF dated 27-11-2025); the amending Act that inserted s.9A(2) was not read.
- **Item overall: OFFICIAL_SOURCE_PARTIALLY_VERIFIED.**

### What remains unconfirmed

- The text of HLWB/REV/2026/3436 and whether it was published in the Haryana Gazette.
- The legal basis for setting the cap by "office letter" (the consolidation's footnote 5 does the same for 2025).
- The amending Act and date for s.9A(2) and s.9A(4). A secondary search summary says Haryana Act 16 of 2021; not verified.
- Whether any 2026 instruction moves the 31-12-2026 deposit date.

---

## A5: Payment of bonus (S.O. 4711(E) eligibility ceiling; time limit under the Code on Wages)

### URLs tried

| URL | Host | Read 27-10-2026 | Result |
|---|---|---|---|
| https://egazette.gov.in/ | egazette.gov.in | yes | **TLS error: "unable to verify the first certificate"** |
| https://egazette.gov.in/WriteReadData/2026/275747.pdf (the ID from S.O. 4711(E)'s own header) | egazette.gov.in | yes | **TLS error** (same) |
| https://egazette.gov.in/WriteReadData/2026/276299.pdf | egazette.gov.in | yes | TLS error |
| https://www.labour.gov.in/static/uploads/2026/09/3977906b27b1b53b9b98c245091b0726.pdf (search snippet "6556 GI/2026"; reported to hold S.O. 4710(E)) | labour.gov.in | yes | 403 |
| https://labour.gov.in/static/uploads/2026/09/3977906b27b1b53b9b98c245091b0726.pdf | labour.gov.in | yes | 403 |
| https://labour.gov.in/ | labour.gov.in | yes | 403 |
| https://www.labour.gov.in/static/uploads/2025/06/c328da14bbb15fc4ad571dc33e7a4ab3.pdf (Code on Wages) | labour.gov.in | yes | 403 |
| https://www.indiacode.nic.in/bitstream/123456789/15793/1/aA2019-29.pdf | indiacode.nic.in | yes | 403 |
| https://indiacode.nic.in/bitstream/123456789/15793/1/aA2019-29.pdf | indiacode.nic.in | yes | 403 |
| https://www.indiacode.nic.in/handle/123456789/15793?view_type=browse ; https://indiacode.nic.in/handle/123456789/15793?locale=en | indiacode.nic.in | yes | 403 |
| https://egazette.nic.in/WriteReadData/2019/210356.pdf (Code on Wages gazette) | — | yes | DNS not found |
| https://www.vvgnli.gov.in/sites/default/files/Code%20on%20Wages%202019.pdf | vvgnli.gov.in | yes | connection refused (port 443) |
| https://static.pib.gov.in/WriteReadData/specificdocs/documents/2025/nov/doc20251123703501.pdf | static.pib.gov.in | yes | Fetched, but the tool could not extract text; not examined further. |
| https://www.taxheal.com/wp-content/uploads/2026/08/275747.pdf | mirror | yes | read (PDF text extracted). Producer "GPL Ghostscript 9.55.0". pdfsig: "does not contain any signatures". |
| https://www.advocatekhoj.com/library/bareacts/codeonwages/39.php ; …/2.php | non-govt | yes | read (secondary) |
| https://www.lawrbit.com/wp-content/uploads/2020/03/The-Code-on-Wages-2019.pdf | non-govt | yes | 403 |
| https://prsindia.org/files/bills_acts/acts_parliament/2019/The%20Code%20on%20Wages,%202019.pdf | prsindia.org | yes | 404 (guessed) |
| https://ascent-hr.com/notification/ministry-of-labour-employment-notifies-monthly-wage-threshold-for-bonus-eligibility/ ; …/clarification-from-the-ministry-of-labour-employment-about-bonus-calculation/ | non-govt | yes | read (secondary; no official link) |

### Verbatim quotes

- S.O. 4711(E), gazette print on the TaxHeal mirror (read 27-10-2026).
  - Header: "CG-DL-E-25082026-275747"; "No. 4526] NEW DELHI, TUESDAY, AUGUST 25, 2026/BHADRA 3, 1948"; "6557 GI/2026"; "[PART II—SEC. 3(ii)]"; "MINISTRY OF LABOUR AND EMPLOYMENT … New Delhi, the 25th August, 2026".
  - Operative text: "S.O. 4711(E).— In exercise of the powers conferred by sub-section (1) of section 26 of the Code on Wages, 2019 (29 of 2019), the Central Government hereby determines that every employee drawing wages not exceeding twenty-one thousand rupees per mensum shall be paid bonus as specified in the said sub-section."
  - "2. This notification shall be deemed to have come into force on the 21st day of November, 2025."
  - "[F. No. S-33027/4/2026-WB] DEEPIKA KACHHAL, Jt. Secy."
  - The page shows the text "Digitally signed by SARVESH KUMAR SRIVASTAVA Date: 2026.08.25 19:45:19 +05'30'", but this copy carries no embedded signature that can be validated.
- Code on Wages, 2019, s.39 (secondary, advocatekhoj; read 27-10-2026):
  - (1) "All amounts payable to an employee by way of bonus under this Code shall be paid by crediting it in the bank account of the employee by his employer within a period of eight months from the close of the accounting year:"
  - Proviso: "Provided that the appropriate Government or such authority as the appropriate Government may specify in this behalf may, upon an application made to it by the employer and for sufficient reasons, by order, extend the said period of eight months to such further period or periods as it thinks fit; so, however, that the total period so extended shall not in any case exceed two years."
  - (2) "… where there is a dispute regarding payment of bonus pending before any authority, such bonus shall be paid, within a period of one month from the date on which the award becomes enforceable or the settlement comes into operation, in respect of such dispute:"
  - Proviso to (2): it requires 8⅓% "within a period of eight months from the close of the accounting year".
- s.2(a) (secondary): "\"accounting year\" means the year commencing on the 1st day of April;"

### Current position

- S.O. 4711(E), dated 25-08-2026 (Gazette Ext. No. 4526; CG-DL-E-25082026-275747), sets the bonus eligibility ceiling at wages "not exceeding twenty-one thousand rupees per mensum", deemed in force from 21-11-2025.
- Its official URL should be https://egazette.gov.in/WriteReadData/2026/275747.pdf. From this environment that URL fails TLS, and labour.gov.in returns 403, so it could only be read on a mirror.
- Time limit: s.39(1) of the Code on Wages requires payment within eight months from the close of the accounting year. The extension proviso caps any extension at two years in total. Disputed cases follow s.39(2).
- For FY 2025-26 (closing 31-03-2026), the due date is **30-11-2026**. That is my calculation, and it assumes no extension order and no pending dispute.
- The s.39 wording was read only on a secondary site: indiacode.nic.in and labour.gov.in returned 403, and egazette failed TLS.

### Confidence

- S.O. 4711(E): OFFICIAL_SOURCE_PARTIALLY_VERIFIED (gazette print on a non-government mirror; no validated digital signature).
- s.39 time limit: SECONDARY_SOURCE_ONLY (all official hosts failed: 403, TLS or DNS).
- **Item overall: OFFICIAL_SOURCE_PARTIALLY_VERIFIED.**

### What remains unconfirmed

- S.O. 4711(E) read on egazette.gov.in or labour.gov.in itself.
- The s.39 and s.2(a) wording on India Code.
- Whether any order under the s.39(1) proviso extends the FY 2025-26 deadline.
- How the eight-month limit applies to the part of FY 2025-26 before 21-11-2025, when the Payment of Bonus Act, 1965 applied. Not examined.
- Whether S.O. 4711(E) binds establishments whose appropriate government is a State (secondary commentary disputes this).

---

## Summary (6 lines)

1. A8 Odisha: the Assembly reassembled on 22-09-2026 (official NeVA notice) and rose sine die on 28-09-2026. The Repeal Bill is reported passed (secondary only). No assent, Act number or Gazette publication found anywhere official. The Ordinance's six-week window ends on about 03-11-2026.
2. A8 label: OFFICIAL_SOURCE_PARTIALLY_VERIFIED. The Ordinance and the reassembly date are primary; passage is secondary; enactment is NOT_CONFIRMED.
3. A3 West Bengal: 1406-F.T. (commencement 1-10-2026) is primary. Final Schedule 1607-F.T. is still not on any reachable official host. Slabs (secondary): nil up to ₹20,000, then ₹100 / ₹140 / ₹170 / ₹208. Part C date conflicts: 1-10-2026 in the draft, 1-04-2027 in the final per secondary sources. Label: SECONDARY_SOURCE_ONLY.
4. A2 Haryana: hrylabour.gov.in shows the January 2026 cap (re-confirmed) (0.2%, ₹35; employer twice; ₹105 total). Letter HLWB/REV/2026/3436 itself is still not found. The e-Gazette now fails TLS. Label: OFFICIAL_SOURCE_PARTIALLY_VERIFIED.
5. A2 last date: under s.9A(2) of the official consolidated Act, the 2026 contribution is due before 31-12-2026, with 12% interest if unpaid one month after that.
6. A5 bonus: S.O. 4711(E) (25-08-2026; ceiling ₹21,000 a month; deemed from 21-11-2025) is readable only on a mirror (OFFICIAL_SOURCE_PARTIALLY_VERIFIED). s.39 sets eight months from the close of the accounting year, so FY 2025-26 bonus is due by 30-11-2026; the s.39 text is SECONDARY_SOURCE_ONLY because indiacode and labour.gov.in return 403 and egazette fails TLS.
