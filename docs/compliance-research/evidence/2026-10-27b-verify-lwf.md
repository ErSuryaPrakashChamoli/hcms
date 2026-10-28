> Copied into the repository's evidence folder on 2026-10-27. References to `dl_<n>` folders, `scripts/`, `ca/`, `certs/` and `work/` are to a temporary working folder that is not kept; each source URL is given in full below.

# Labour Welfare Fund: second primary-source pass, 2026-10-27 (b)

Builds on `docs/compliance-research/evidence/2026-10-27-verify-lwf.md`, `2026-10-27-verify-rule-changes.md` and the rule-change register (P5–P9, W1–W5). It re-tests the stored LWF versions in `database/data/compliance/in.php` (MH, KA, TG, DL, TN, GJ, HR) and the proposed versions (KA v2, HR v2–v5).

**Nothing in the repository was edited.** All files are under `scratchpad/research-2026-10-27b/lwf/`:
- `dl_<n>/`: downloads, one new empty directory each.
- `scripts/`: `fetch.sh`, `aia_bundle.sh`, `html2txt.py`.
- `work/`: OCR output and the fork notes `work/fork-B.md`.
- `fetch-log.txt`: every fetch, with its HTTP result and the CA store used.

## Method and limits
- **TLS.** TLS was verified on every fetch. No `-k`/`--insecure` and no HTTP downgrade was used.
  - Four government hosts send an incomplete certificate chain: dpal.karnataka.gov.in, egazetteharyana.gov.in, egazette.gov.in and pblabour.gov.in.
  - For these, `scripts/aia_bundle.sh` fetched the missing GlobalSign intermediate from the leaf certificate's AIA "CA Issuers" URL, as browsers do. It appended that intermediate to the system trust store and checked that it verifies to a system root. The full chain and the hostname were then verified by curl.
  - Hosts with an **expired or hostname-mismatched** certificate were treated as access failures and were not read: kla.kar.nic.in, cglabour.gov.in and www.labour.wb.gov.in.
- **Reading.** Scanned PDFs were OCR'd with Tesseract (eng). Gujarati and Kannada pages were read visually from rendered images, and Gujarati quotations are given with my own translation. Python ran with `-I`.
- **Delegation.** One fork covered KL, GA, DL, UP, DH and the 18 "no LWF" states (`work/fork-B.md`, `dl_300`–`dl_344`). Its findings are merged below.
  - The downloads `dl_200`–`dl_256` (WB, MP, OD, PB, CH) come from an earlier, interrupted fork whose notes were lost. I re-read those files myself for this report.
- **Not re-searched.** Following the coordinator's instruction to finish with what was in hand, AP, TG, OD, PB and CH were not searched further beyond the items noted.
- **Labels.**
  - A gazette print on a non-government host is at most OFFICIAL_SOURCE_PARTIALLY_VERIFIED.
  - A gazette scan, or the instrument text, on a `*.gov.in`/`*.nic.in` host counts as OFFICIAL_PRIMARY_SOURCE_VERIFIED, even without a digital signature.
  - An official board rate page, rather than the instrument itself, is OFFICIAL_SOURCE_PARTIALLY_VERIFIED.

---

## A. Label changes (previous → new)

| Jurisdiction | Previous | New | One-line reason |
|---|---|---|---|
| KA (P5 / stored v1) | OFFICIAL_PRIMARY_SOURCE_VERIFIED | OFFICIAL_PRIMARY_SOURCE_VERIFIED (strengthened) | The Law Department (DPAL) consolidation on dpal.karnataka.gov.in gives every rate step with its w.e.f. date; Act 05 of 2026 (10+ persons from 07-01-2026) is newly found |
| HR v2 (P6) | OFFICIAL_PRIMARY_SOURCE_VERIFIED | unchanged | Consolidated Act on hrylabour.gov.in: s.9A(1) substituted by Haryana Act 18 of 2019 |
| HR v3–v5 (P7–P9) | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | unchanged | The board's rate table and Act footnotes confirm ₹31/₹34/₹35. The HLWB letters and HLWB/REV/2026/3436 are not on any government host, and the Haryana e-Gazette search sits behind a CAPTCHA |
| HR threshold (B8) | LEGAL_REVIEW_REQUIRED | unchanged | The text attaches "ten or more employees" to the shop / commercial-establishment limb only, but the drafting is ambiguous |
| MH (W1) | OFFICIAL_PRIMARY_SOURCE_VERIFIED (2024 rate) | **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** | The 2024 rate was read only from a gazette print on ramco.com (unsigned) and a PRS reprint. India Code (gov) returned 504 four times. A mirror is never primary |
| MH pre-2024 rates | (not established) | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | Government Central Press reprint (PRS host): ₹6 (wages ≤ ₹3,000) / ₹12 (> ₹3,000), employer 3×, deemed from 31-12-2000 (Mah. 24 of 2003) |
| TN amounts and dates (W2) | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | **OFFICIAL_PRIMARY_SOURCE_VERIFIED** | Two TN gazettes on tn.gov.in hosts: ₹10/₹20 from 23-01-2015 and ₹20/₹40 from 02-12-2022 |
| TN deduction month | CONFLICTING_SOURCES | CONFLICTING_SOURCES (unchanged) | r.12 text (June, before 31 July) against the official Form A (year ending December) and the 2022 board press release (31 January) |
| GJ (W3) | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | **OFFICIAL_PRIMARY_SOURCE_VERIFIED** | Gujarat Govt Gazette Extra. No. 634 (15-11-2016), hosted on glwb.gujarat.gov.in, raises the rates to ₹6/₹12. The board notice says "effective from December 2016" |
| WB | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (rates) | Official PDF of Notfn Labr/576641/2024 (Kolkata Gazette Extra., 02-12-2024) fetched from labour.wb.gov.in |
| AP | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | unchanged | Commencement G.O.Ms.7 (23-04-2015) is read; the text of the amended s.10 (₹30/₹70) is still not read |
| TG | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | unchanged | No new work |
| MP | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | unchanged | Official board contribution page: ₹10 / ₹50 per half-year, employer minimum ₹2,500. The 2026 notification text is not read |
| PB | LEGAL_REVIEW_REQUIRED | unchanged | Bill 15-PLA-2025 (₹10/₹40 a month) passed; gazetting NOT CONFIRMED. The official Act PDF on pblabour.gov.in is the 1965 text with no s.9-A |
| CH | SECONDARY_SOURCE_ONLY | unchanged | G.S.R. 353(E) of 10-04-2018 not found on egazette.gov.in |
| OD | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | unchanged | Rules 2015 read only from a PRS gazette print; Form F confirms half-yearly statements |
| CG | SECONDARY_SOURCE_ONLY | unchanged (but see §CG) | An official CG Labour Department portal shows a Shram Kalyan Mandal establishment-registration service, so the Board exists. No official rate source was found |
| KL | LEGAL_REVIEW_REQUIRED | unchanged | Two funds; no text resolves which applies |
| GA | OFFICIAL_PRIMARY_SOURCE_VERIFIED | unchanged | Goa Act 9 of 2015 gazette on labour.goa.gov.in |
| DL | OFFICIAL_PRIMARY_SOURCE_VERIFIED | unchanged | Form A now confirmed on dlwb.delhi.gov.in |
| UP | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | unchanged (strengthened) | UP LWF Rules 1972 read in full on a government host: no payroll contribution |
| UK | SECONDARY_SOURCE_ONLY | **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** | The Uttarakhand Labour Department lists the UP LWF Rules 1972 as administered there; no payroll contribution |
| DH | LEGAL_REVIEW_REQUIRED | unchanged | No commencement notification for Daman & Diu and no extension to DNH found |
| SK, PY, RJ, ML | SECONDARY_SOURCE_ONLY | **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** | The official labour-department list of laws enforced contains no state LWF Act |
| AS, TR, HP, AR, BR, JH, JK, MN, NL, AN, LA, MZ, LD | SECONDARY_SOURCE_ONLY | unchanged | No comprehensive official list was read, or the host was unreachable |

## B. Stored versions: mismatches confirmed or cleared

| Stored | Verdict | Parameter that differs | Correct history (proposal) |
|---|---|---|---|
| **KA v1** (from 2018-01-01, ₹20/₹40, [12]) | Amounts **match** for 06-04-2017 → 09-01-2025. `effective_from` **differs** | `effective_from`: the law runs from **2017-04-06** (Act 21 of 2017). With 2018-01-01, the December 2017 deduction has no version | ₹6/₹12 (Govt ₹6) from 2011-02-08 (Act 7 of 2011); **₹20/₹40 from 2017-04-06**; ₹50/₹100 from 2025-01-10 (proposed v2, **matches**) |
| **KA v2** (proposed, from 2025-01-10, ₹50/₹100, [12]) | **Match** | — | CY2024 (register 31-12-2024, December 2024 wages) stays ₹20/₹40 on the statutory reading. No board document for CY2024 was read |
| **HR v1** (from 2020-01-01, flat ₹31/₹62, months 1–12) | **Mismatch confirmed (W4)** | Basis (flat vs 0.2% with a cap); `employee_amount`/`employer_amount` | 0.2% up to ₹25 from April 2019 (Act 18 of 2019); ₹31 from Jan 2023; ₹34 from Jan 2025; ₹35 from Jan 2026; employer 2×. Monthly months [1..12] **match** |
| **HR v2–v5** (proposed) | **Match**, with one note | v2 `effective_from` 2020-01-01 is later than the legal start (board table: April 2019; Act dated 14-03-2019). Harmless if there is no pre-2020 payroll | — |
| **MH v1** (from 2015-01-01, ₹25/₹75, [6,12]) | **Mismatch confirmed (W1)** | `employee_amount`/`employer_amount` before 2024-03-18. A wage-slab parameter is missing | **₹6/₹18 (wages ≤ ₹3,000) or ₹12/₹36 (> ₹3,000)** deemed from 2000-12-31 (Mah. 24 of 2003) → 2024-03-17; **₹25/₹75 from 2024-03-18** (Mah. XXV of 2024; first half-year June 2024). Months [6,12] **match** |
| **TN v1** (from 2015-01-01, ₹20/₹40, [12]) | **Mismatch confirmed (W2)** | `employee_amount`/`employer_amount` for 2015-01-23 → 2022-12-01. `months` is unresolved (June vs December) | ₹7/₹14 (G.O.Ms.91, 10-07-2009; board table only) → **₹10/₹20 from 2015-01-23** (SRO A-1(a)/2015) → **₹20/₹40 from 2022-12-02** (SRO A-26(b)/2022) |
| **GJ v1** (from 2015-01-01, ₹6/₹12, [6,12]) | **Mismatch confirmed (W3)** | `employee_amount`/`employer_amount` for June 2015, December 2015 and June 2016; `effective_from` | **₹3/₹6** (Guj. Act 5 of 2005, w.e.f. 2004-12-31) → **₹6/₹12 from 2016-11-15** (gazette date; board: "effective from December 2016 … up to june 2016 previous amount"). Months [6,12] **match** |
| **TG v1** (from 2015-01-01, ₹2/₹5, [12]) | **Match** (no new evidence) | — | — |
| **DL v1** (from 2015-01-01, ₹0.75/₹2.25, [6,12]) | **Match** | `effective_from` is later than 2004-07-13, which is harmless | — |

Not modelled anywhere: supervisory wage ceilings (MH/GJ ₹3,500; DL ₹2,500; TN ₹15,000; WB ₹10,000; GA/TG/AP 1987 text ₹1,600), establishment thresholds, MP's employer minimum, and KA's threshold change (more than 50 persons until 06-01-2026; 10 or more from 07-01-2026).

---

## C. Per-jurisdiction findings

### KA — Karnataka — OFFICIAL_PRIMARY_SOURCE_VERIFIED → OFFICIAL_PRIMARY_SOURCE_VERIFIED
- **Instruments:**
  - Karnataka Labour Welfare Fund Act, 1965 (Act 15 of 1965), s.7A, as amended by Acts 10 of 1994, 7 of 2011, 21 of 2017, **05 of 2025** and **05 of 2026**.
  - Karnataka LWF Rules, 1968.
- **Sources (government host, Department of Parliamentary Affairs and Legislation):**
  - DPAL "Acts alphabetical" page: https://dpal.karnataka.gov.in/87/acts-alpha-and-rules/en (dl_14). It reads: "Labour Welfare Fund Act, 1965, (15 of 1965)- Amended by Act 73 of 1976, 10 of 1994, 7 of 2011 and 21 of 2017. Amended by Acts 05 of 2025 05 of 2026".
  - Consolidated to 05 of 2026: https://dpal.karnataka.gov.in/uploads/media_to_upload1771238983.pdf (dl_17).
  - Consolidated to 05 of 2025: https://dpal.karnataka.gov.in/storage/pdf-files/15of1965(E)(05of2025).pdf (dl_16).
  - Consolidated to 21 of 2017: https://dpal.karnataka.gov.in/storage/pdf-files/15of1965(E).pdf (dl_15).
  - Rules 1968: dl_18.
  - KLWB press note and gazette copy of Act 05/2025: dl_4.
  - LA Bill 68 of 2025: PRS copy, dl_3.
- **Rate history.** Footnotes to s.7A(2) in dl_17, verbatim:
  - "1. Substituted by Act 10 of 1994 w.e.f. 1.12.1993"
  - "2. Substituted by Act 7 of 2011 w.e.f.08.02.2011."
  - "3. Substituted by Act 21 of 2017 w.e.f.06.04.2017."
  - "4. Substituted by Act 05 of 2025 w.e.f. 10.01.2025."
- **What each step set** (statements of objects and reasons):
  - Act 7 of 2011: "from the existing ratio of 3 rupees : 6 rupees : 3 rupees to the ratio of 6 rupees : 12 rupees : 6 rupees".
  - Act 21 of 2017: "from six rupees, twelve rupees and six rupees to twenty rupees, forty rupees and twenty rupees" (L.A. Bill No.08 of 2017).
  - Act 05 of 2025: "[Published in Karnataka Gazette Extra-ordinary No.19 in part-IVA dated:10.01.2025]".
- **Current amounts:** employee ₹50, employer ₹100, State Government ₹50, per calendar year. s.7A(2): "In respect of every employee whose name stands in the register of an establishment on 31st December of a year sum of … fifty rupees, one hundred rupees and fifty rupees respectively shall be payable".
- **Frequency and deduction:** annual. Deducted from December wages only, s.7A(4) proviso: "nor shall it be made from any wages other than the wages for the month of December of the year to which the contribution relates".
- **Due date:** s.7A(3): "on or before 15th January of the following year". Since Act 05 of 2026 (w.e.f. 07.01.2026), payment is by "online payment channels i.e., Net banking or … NEFT or … RTGS or … UPI or demand draft".
- **Interest:** s.7B, after a notice of demand: 12% a year for the first three months, 18% after that.
- **Threshold, new.** **Karnataka Act 05 of 2026** (L.A. Bill No. 68 of 2025; "Published in Karnataka Gazette Extra-ordinary No.12 in part-IVA dated:07.01.2026") changed s.2(4)(iii):
  - It now reads "employed [ten or more persons]", with footnote "2. Substituted by Act 05 of 2026 w.e.f. 07.01.2026".
  - Before that: "more than fifty persons" (dl_15, dl_16).
  - Factories, motor omnibus services, plantations and workshops have no headcount test.
  - The DPAL file number in the statement of objects and reasons is "SAMVYASHAE 82 SHASANA 2025". The gazette notification number "DPAL 82 SHASANA 2025" and the assent date (06-01-2026) are secondary only: NOT CONFIRMED.
- **Exclusions:** none. "employee" means "any person who is employed for wages to do any work skilled or unskilled, manual or clerical, in an establishment". There is no wage ceiling and no managerial exclusion.
- **Dates:**
  - Act 05/2025: assent 08-01-2025; published 10-01-2025; in force "at once" (10-01-2025).
  - Act 05/2026: published 07-01-2026; in force 07-01-2026.
  - Act 21/2017: w.e.f. 06-04-2017.
- **Open points:**
  - CY2024: the register date (31-12-2024) and December 2024 wages predate 10-01-2025, and the Act is not retrospective, so ₹20/₹40 applies by statute. No board document for CY2024 was read.
  - The Karnataka Repealing of Certain Enactments Act 2020: the DPAL consolidation still carries the Act 21 of 2017 rates, so the repeal of the amending Act did not undo the amendment.
- **Conclusion:**
  - Stored v1: amounts match; `effective_from` should be 2017-04-06.
  - Proposed v2: matches.
  - Applicability: the KA threshold dropped from more than 50 to 10 or more persons from 07-01-2026. This matters for CY2026 coverage.

### HR — Haryana — P6 OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged); P7–P9 OFFICIAL_SOURCE_PARTIALLY_VERIFIED (unchanged); B8 LEGAL_REVIEW_REQUIRED (unchanged)
- **Instrument:** Punjab Labour Welfare Fund Act, 1965 (as applied in Haryana), s.9A(1) as substituted by Haryana Act 18 of 2019 (14-03-2019), and s.9A(2) as substituted by Haryana Act 16 of 2021 (05-04-2021). The cap is revised by HLWB letters and notifications.
- **Sources:**
  - Official board rate table: https://hrylabour.gov.in/content/cms/MTU (dl_19).
  - Consolidated Act, PDF created 02-06-2026: https://hrylabour.gov.in/staticdocs/labourActpdfdocs/ThePunjabLabourFundACt1965.pdf (dl_20).
- **Board table, verbatim rows:**
  - "January-2002 Re. 01/- Rs. 02/-"
  - "April - 2007 Rs. 05/- Rs. 10/-"
  - "April - 2012 Rs. 10/- Rs. 20/-"
  - "April - 2019 Rs. 0.2% … subject to a limit of Rs. 25/-"
  - "January - 2023 … Rs. 31/-"
  - "January - 2025 … Rs. 34/-"
  - "January - 2026 … Rs. 35/- | Twice the amount contributed by such employee | Rs. 105 (Per month)"
- **Act s.9A(1), verbatim:** "Each employee shall contribute to the Fund every month an amount equal to zero point two percent of his salary or wages or any remuneration subject to a limit of rupees thirty-five and each employer … twice the amount contributed by such employee". Explanation: employee "on the register of an establishment on the last working day of the month".
- **Act footnotes:**
  - "Sub-section (1) of section 9A substituted by Haryana Act No. 18 of 2019 dated 14th March, 2019"
  - "Section 9A substituted by Vide office letter no. HLWB/REV/2023/2733-2982 dated 27th June, 2023"
  - "… HLWB/REV/2025/1306-1530 dated 07th March, 2025"
- **Frequency and due date:** accrues monthly. s.9A(2): pay "before the 31st December of every year through online mode". s.9A(4): 12% a year if not paid within one month after that date.
- **Exclusions:** no wage ceiling and no managerial exclusion. Contract labour is included.
- **B8, the threshold.** s.2(4), verbatim: "'establishment' means a factory and includes any premises … wherein … any industry within the meaning of clause (j) of section 2 of the Industrial Disputes Act, 1947 … is carried on and also includes a shop or a commercial establishment within the meaning of the Punjab Shops and Commercial Establishments Act, 1958 …, in which, on any day. Ten or more employees are employed or were employed during the preceding twelve months".
  - Grammatically, "in which" attaches to "a shop or a commercial establishment". On that reading, factories and premises where an ID-Act industry is carried on have no headcount test.
  - The consolidation's punctuation ("on any day. Ten") is defective, and the definition came from Haryana Act 7 of 2007. Keep LEGAL_REVIEW_REQUIRED.
- **HLWB/REV/2026/3436 (08-05-2026):** no copy on a government host.
  - The hrylabour.gov.in notifications list (dl_21, dl_22) does not carry it.
  - The Haryana e-Gazette search (https://egazetteharyana.gov.in/, dl_24) needs a CAPTCHA, so it was not queried.
  - The ₹35 cap is confirmed by the board table and by the consolidated Act text.
- **Conclusion:** W4 confirmed. P6–P9 match the official table. P9's evidence gap remains the notification itself.

### MH — Maharashtra — OFFICIAL_PRIMARY_SOURCE_VERIFIED → OFFICIAL_SOURCE_PARTIALLY_VERIFIED
- **Instrument:**
  - Maharashtra (Bombay) Labour Welfare Fund Act, 1953 (XL of 1953), s.6BB.
  - s.6BB(2) as substituted by **Mah. Act XXV of 2024**, which has no commencement clause, so it took effect on first publication, **18-03-2024**.
  - The earlier rates came from **Mah. 24 of 2003**, s.4, "deemed to have been substituted w.e.f. 31-12-2000".
- **Sources:**
  - Gazette print of Act XXV/2024, Maharashtra Govt Gazette Extra. Part VIII No. 41, 18-03-2024: https://www.ramco.com/hubfs/Maharashtra-labour-welfare-fund-amendment-act-2024.pdf (dl_26). Not on a government host; no digital signature.
  - PRS copy, which includes a Government Central Press reprint of the principal Act (pages OCR'd) plus Mah. XX of 2023 and XXV of 2024: https://prsindia.org/files/bills_acts/acts_states/maharashtra/1953/Act40of1953MH.pdf (dl_28).
  - India Code (gov) returned HTTP 504 on four tries (dl_25, dl_27, dl_52 and a background probe).
- **2024 text, verbatim:** "(2) The amount of contribution payable every six months … (a) in respect of an employee, whose name appears in the register of an establishment on the 30th June and 31st December, respectively, shall be at the rate of twenty-five rupees … (b) in respect of an employer … thrice the amount".
- **Pre-2024 text (reprint), verbatim:** "(a)(i) in respect of an employee drawing wages upto and inclusive of three thousand rupees per mensem, six rupees; and (ii) in respect of an employee drawing wages exceeding three thousand rupees per mensem, twelve rupees … (b) in respect of an employer … thrice the amount of contribution payable by an employee". Footnote: "substituted for the words 'one thousand rupees per mensem, one rupee' and shall be deemed to have been substituted w.e.f 31-12-2000 by Mah. 24 of 2003, s. 4".
- **Frequency, deduction and due date:** half-yearly; employees on the register on 30 June and 31 December.
  - s.6BB(3): "before the 15th day of July and 15th day of January".
  - s.6BB(4): deduction only from "the wages for the months of June and December".
- **Exclusions:** s.2(2) excludes staff "employed mainly in a managerial capacity", supervisory staff drawing "wages exceeding three thousand and five hundred rupees per mensem", and apprentices.
- **Threshold:** factories; motor transport; Bombay S&E Act establishments employing "[five] or more persons". An establishment ceases to be covered after three continuous months below five.
- **Other amendment:** Mah. XX of 2023 (11-04-2023) changed only the penalties (s.17A) and added compounding (s.17C).
- **Conclusion:**
  - Amounts verified from official gazette text, but only on non-government hosts, hence the downgrade.
  - W1 confirmed. Proposed history: ₹6/₹18 (wages ≤ ₹3,000) or ₹12/₹36 (> ₹3,000) from 2000-12-31 (deemed) to 2024-03-17; ₹25/₹75 from 2024-03-18. The first half-year at ₹25/₹75 is June 2024; MLWB (board site, not a gov host) says "applicable from June 2024".

### TN — Tamil Nadu — amounts: OFFICIAL_SOURCE_PARTIALLY_VERIFIED → OFFICIAL_PRIMARY_SOURCE_VERIFIED; deduction month: CONFLICTING_SOURCES (unchanged)
- **Instruments:**
  - TN LWF Act 1972, s.15.
  - TN LWF Rules 1973, r.11-A (rates) and r.12 (payment).
- **₹10/₹20 (Govt ₹10):**
  - Source: TN Govt Gazette Extra. No. 21, Part III s.1(a), Friday 23-01-2015; G.O. Ms. No. 16, Labour and Employment (G2), 20-01-2015; No. SRO A-1(a)/2015.
  - URL: https://www.stationeryprinting.tn.gov.in/extraordinary/2015/21-Ex-III-1a.pdf (dl_34).
  - Verbatim: "in rule 11-A,- (1) for the expression 'rupees seven' occurring in two places, the expression 'rupees ten' shall be substituted; and (2) for the expression 'rupees fourteen', the expression 'rupees twenty' shall be substituted". Also: "shall come into force on and from the date of its publication".
- **₹20/₹40 (Govt ₹20):**
  - Source: TN Govt Gazette Extra. No. 538, 02-12-2022; G.O. Ms. No. 161, LW&SD (G2), 02-12-2022; No. SRO A-26(b)/2022.
  - URLs: https://www.lwb.tn.gov.in/uploads/labo_205/subpage_files/Gazette_538.pdf (dl_30) and GO_161.pdf (dl_29).
  - Verbatim: "for the expression 'rupees ten' occurring in two places, the expression 'rupees twenty', shall be substituted; and (2) for the expression 'rupees twenty', the expression 'rupees forty'". In force from publication.
  - The G.O. abstract cites the "Tamil Nadu Labour Welfare Fund (Amendment) Act, 2021". Its Act number is NOT CONFIRMED. The board's Act copy (dl_33) still shows the 1995 caps of ₹10/₹20/₹10.
- **Earlier rates:** the official Labour Department table (https://labour.tn.gov.in/tnlabour_welfarefund.php, dl_35), which has not been updated for 2022:
  - "1998-2008 … Rs. 5/- Rs. 10/- Rs. 5/- No. 159 / 07.12.1998"
  - "2009-2014 … Rs. 7/- Rs.14/- Rs.7/- No. 91 / 10.07.2009"
  - "2015 onwards Rs.10/- Rs.20/- Rs.10/- No.16 / 20.01.2015"
- **Deduction month and due date: still conflicting.**
  - Rules r.12(1), from the board-hosted Indian Kanoon print marked "content could not be verified" (dl_32): pay "for every year before the 31st July of that year … recover from the employees whose names are borne on the registers … on the 30th June of the year, the employee's contribution from his wages for the month of June". The same print records the 2015 r.11-A change but no r.12 change.
  - Official Form A (dl_31): "FOR THE YEAR ENDING DECEMBER", "at Rs. 20.00 per head", "Rs.40.00 per head".
  - Board press release P.R. No. 039 of 07-01-2022 (kygcms.tn.gov.in): it set 31-01-2022 as the deadline for the 2021 contribution. It was seen **only via the search engine's index**; the host refused the connection and timed out.
  - No r.12 amendment was found.
- **Exclusions:** supervisory staff above ₹15,000; apprentices; part-time staff (prior pass).
- **Conclusion:**
  - Amounts and dates are verified. W2 confirmed: stored v1 should be ₹10/₹20 from 2015-01-23 and ₹20/₹40 from 2022-12-02.
  - `months` [12] matches board practice but not the r.12 text: LEGAL_REVIEW_REQUIRED for timing.
  - For a December deduction, December 2022 falls after 02-12-2022 and so is at ₹20/₹40 under board practice. If r.12 (June) were applied, June 2022 would be at ₹10/₹20.

### GJ — Gujarat — OFFICIAL_SOURCE_PARTIALLY_VERIFIED → OFFICIAL_PRIMARY_SOURCE_VERIFIED
- **Instrument:** Bombay Labour Welfare Fund Act 1953 as applied in Gujarat, s.6-B(2) as substituted by Guj. Act 5 of 2005 (w.e.f. 31-12-2004). This provides ₹3/₹6, with a proviso allowing the State to raise the employee rate to not more than ₹6 by notification, the employer paying 2×.
  - Notification under that proviso: **Gujarat Government Gazette Extraordinary, Extra No. 634, Vol. LVII, Part IV-B, Tuesday 15-11-2016**.
  - Labour & Employment Department notification, Sachivalaya, Gandhinagar, **10-11-2016**. Number as printed in Gujarati: "કેએચઆર-૧૯૬-૨૦૧૬-કકબ-૧૦-૨૦૧૬-૧૯૦૭૨૨-મ.૪" (transliterated KHR-196-2016-KKB-10-2016-190722-M.4; the official English rendering is NOT CONFIRMED).
  - Signed S. L. Amrani, Joint Secretary.
- **Source (government host):** https://glwb.gujarat.gov.in/images/glwb/pdf/lwf.pdf (dl_44), a scan with no digital signature, linked from the board notice page https://glwb.gujarat.gov.in/labour-welfare-fund.htm (dl_43).
- **Verbatim (Gujarati):** "જેનું નામ અનુક્રમે જૂન મહિનામાં અથવા યથાપ્રસંગ, ડિસેમ્બર મહિનામાં સંબંધિત સંસ્થાના રજીસ્ટરમાં હોય તે દરેક કામદાર માટે કામદાર-ફાળાની રકમ, દર છ મહિને એવા દરેક કામદાર દીઠ ૬ રૂપિયાના દરે અને માલિકના ફાળાની રકમ, દર છ મહિને એવા દરેક કામદાર દીઠ ૧૨ રૂપિયાના દરે આપવાની રહેશે."
  - My translation: "For every employee whose name is on the establishment's register in June or, as the case may be, December, the employee's contribution shall be at ₹6 per employee every six months and the employer's contribution at ₹12 per employee every six months."
- **Board notice (page last updated 16/11/2016), verbatim:** "Please be noted that there is change in Labour Welfare Fund paid by you from December 2016 and on words … Employee contribution : 6 Rs Employer Contribution: 12 Rs. This is effective from December 2016. up to june 2016 previous amount will be applicable. I/c Welfare Commisiioner".
  - The same banner appears in a 2018 Wayback capture of the board home page (dl_42), which is a mirror and is cited only as corroboration.
- **Dates:**
  - Notification dated 10-11-2016; published 15-11-2016.
  - No separate commencement clause, so in force on publication.
  - First half-year at the new rate: December 2016.
- **Frequency, deduction and due date** (prior pass, Act on glwb): June and December; pay before 15 July and 15 January (s.6-B(3)).
- **Exclusions:** supervisory staff above ₹3,500.
- **Threshold:** S&E establishments with 10+ persons.
- **Conclusion:** W3 confirmed. Stored v1 overstates June 2015, December 2015 and June 2016. Proposal: ₹3/₹6 until 2016-11-14; ₹6/₹12 from 2016-11-15.

### AP — Andhra Pradesh — OFFICIAL_SOURCE_PARTIALLY_VERIFIED (unchanged)
- **New: commencement of AP LWF (Amendment) Act, 2015 (Act No. 7 of 2015).**
  - Source: G.O.Ms.No.7, LET&F (Lab.I), 22-04-2015, on the AP government document server https://api.apfd.apcfss.in/…/1755842263225_GONO722.04.2015labourwelfarefuntact2015.pdf (dl_49, OCR).
  - The host is AP's CFSS, not a `*.gov.in` domain.
  - Verbatim: "the Andhra Pradesh Labour Welfare Fund (Amendment) Act, 2015 (Act No.7 of 2015) was published in Part IV-B Extraordinary issue of the Andhra Pradesh Gazette No.7, dated 9th April, 2015" and "the Government hereby appoint the Twenty Third day of April, 2015, as the date on which the provisions of the said Act shall come into force."
- **Amounts:** ₹30 / ₹70 per year (AP Labour site, prior pass).
  - The text of the amended s.10(1) ("thirty (30) rupees … seventy (70) rupees") was seen only in a search-engine summary of a vendor-hosted 2020 AP notification. That vendor host returned 504 (dl_51).
  - So the statement that Act 7 of 2015 set ₹30/₹70 is NOT CONFIRMED from the text, although it is strongly indicated.
- **Other changes:** dl_50 is G.O.Ms.21 of 07-06-2022, a Rules amendment adding a Vice-Chairman. No rate change.
- **Unchanged facts:**
  - Annual; employees on the register on 31 December; pay by 31 January (r.6, Form F).
  - Effective date of ₹30/₹70: probably 2015-04-23, NOT CONFIRMED.
  - Threshold and ceiling after 2015: NOT CONFIRMED.
- **Stored rule:** not stored.

### TG — Telangana — OFFICIAL_SOURCE_PARTIALLY_VERIFIED (unchanged; no new work)
- ₹2 / ₹5 per year (Act s.10(1) on labour.telangana.gov.in). Annual; register at 31 December; pay by 31 January.
- **Stored v1: matches.** A Telangana amendment is still not excluded.

### WB — West Bengal — OFFICIAL_SOURCE_PARTIALLY_VERIFIED → OFFICIAL_PRIMARY_SOURCE_VERIFIED (rates)
- **Source:** official PDF at https://labour.wb.gov.in/labour_main_api/storage/CMS/Notice/Labr-576641%20Dated-19-11-2024.pdf (dl_205).
  - The old `www.labour.wb.gov.in/sites/default/files/…` URL failed TLS on a hostname mismatch (dl_200).
  - The file is a Kolkata Gazette Extraordinary print, Part I, Monday 02-12-2024, with an eOffice text layer and no digital signature.
- **Instrument:** No. Labr/576641/2024/(LC-LW/MW), dated 19.11.2024. The text layer reads "Labr/S7664112024/(LC-LWIMW)", which is an OCR artefact. The notification is under s.9(2) of the WB LWF Act 1974.
- **Verbatim:**
  - "a. The employers' contribution shall be thirty rupees, b. There shall be no changing of employees contribution i.e. employees contribution shall be three rupees as it is."
  - "Such contributions shall be payable every six months ending on the thirtieth day of June and the thirty first December each year."
  - "This notification shall be deemed to have been come into force with effect from 1st January, 2024."
- **Amounts:** employee ₹3, employer ₹30 per half-year, from 01-01-2024 (retrospective).
  - The earlier ₹3 / ₹15 (secondary sources: from 01-09-2018) is superseded. The 2018 notification was NOT read.
- **Deduction and due date** (Act, prior pass): June and December wages; pay before 15 July and 15 January; electronic payment through the Board portal (WB Act XI of 2023, in force 01-04-2024).
- **Exclusions and threshold:** supervisory staff above ₹10,000 from 01-04-2024; commercial establishments with 10+ persons.
- **Not confirmed:** the form and the government share.
- **Also found:** dl_206, a pre-legislative consultation notice (Labr/1700, 01-12-2020) proposing digital payment. It is not a rate instrument.
- **Stored rule:** not stored. If the catalogue shows ₹3/₹15, that is outdated from 01-01-2024.

### MP — Madhya Pradesh — OFFICIAL_SOURCE_PARTIALLY_VERIFIED (unchanged)
- **Source:** official MP Shram Kalyan Mandal contribution page, https://shramkalyanmandal.mponline.gov.in/Home/contribution (dl_211); the same data is in the new portal bundle (dl_208).
- **Verbatim:** "अभिदाय दरें ( म.प्र.श्रम कल्याण निधि अधिनियम 1982 की धारा 9(2)(3) अनुसार )" ("Contribution rates, under s.9(2)(3) of the MP Shram Kalyan Nidhi Act 1982"):
  - "नियोजक का प्रति श्रमिक 50/- (प्रति छ:माही)": employer ₹50 per worker per half-year.
  - "प्रति श्रमिक 10/- (प्रति छ:माही)": employee ₹10 per worker per half-year.
  - "नियोजक का न्यूनतम अभिदाय 2500/- (प्रति छ:माही)": employer minimum ₹2,500 per half-year.
- **Coverage, board text:** factories under the Factories Act, and commercial establishments with "दस या इससे अधिक कर्मचारी" (ten or more employees).
- **Where the minimum applies:** the portal does not say on this page. The prior pass read "fewer than 50 employees" from the rate master, so that condition is NOT CONFIRMED from the instrument.
- **Not found:** the 2026 notification (reported as LAB-8-0003-2025-Sec-1-XVI(LAB), 28-01-2026) was not on a government host. A Hindi search found only a 2026 amendment Bill listing (secondary).
- **Dates:** effective from the January 2026 half-year per the portal rate master read in the prior pass. Notification and publication dates NOT CONFIRMED.
- **Deduction and due date:** June and December; pay before 15 July and 15 January (s.9(3)).
- **Stored rule:** not stored.

### PB — Punjab — LEGAL_REVIEW_REQUIRED (unchanged)
- **The official Act PDF** on pblabour.gov.in (dl_246, needing the AIA chain) is the **1965 text without s.9-A**. Its "establishment" definition covers other establishments that have "more than twenty persons". So the official host does not give the current contribution clause.
- **Bill 15-PLA-2025** (PRS copy, dl_247; passed by the Vidhan Sabha per punjabassembly.gov.in):
  - Verbatim: "in section 9-A … in sub-section (1), for the words 'five rupees' and 'twenty rupees', … the words 'ten rupees' and 'forty rupees' shall, respectively, be substituted".
  - It would redefine "establishment": factories; MTUs with 10+; S&CE establishments with 20+.
  - It comes into force "from the date of its publication in the Official Gazette".
  - **Assent and gazetting: NOT CONFIRMED.** A search found no gazette entry.
- **Current position (secondary sources and the 2014 Bill):** ₹5 / ₹20 per month, remitted half-yearly by 15 October (April–September) and 15 April (October–March).
- **Stored rule:** not stored.

### CH — Chandigarh — SECONDARY_SOURCE_ONLY (unchanged)
- **Reported instrument:** G.S.R. 353(E) of 10-04-2018 (Gazette of India Extra. Part II s.3(i) No. 226, F. No. U-11020/1/2017-UTL) extended Punjab Act 32 of 2014 (₹5 / ₹20 a month). These details come from secondary search summaries only; the gazette was not found.
- **Other gazettes checked:** dl_253 and dl_254 are signed Gazette of India files, but they concern the Chandigarh BOCW Board's income-tax exemption, not LWF.
- **Punjab's 2025 increase:** reaches Chandigarh only through a central extension; none was found.
- **Conflicting secondary claim:** a ₹15,000 wage limit. NOT CONFIRMED.

### OD — Odisha — OFFICIAL_SOURCE_PARTIALLY_VERIFIED (unchanged)
- **Source:** Odisha LWF Rules 2015 (S.R.O. No.73/2016; Labour & ESI Department notification of 27-01-2016; Odisha Gazette Extra. No. 224, 28-01-2016; in force on publication). Read from the PRS gazette print (dl_239).
- **Verbatim:**
  - r.6(1): "Every employee shall contribute twenty rupees per year to the Fund and every employer shall, in respect of each employee, contribute forty rupees per year".
  - r.6(2): "before the 15th day of January and the 15th day of July of every year in respect of all employees whose names stand on the register of establishment preceding the 31st December and the 30th June respectively, along with a statement showing full particulars in Form F".
  - Form F: "for the half year ending the 30th June / the 31st December".
- **Half-year split:** ₹10 / ₹20 per half-year is consistent with the half-yearly Form F, but no text states the split. It remains an inference.
- **Odisha portal:** the labour.odisha.gov.in gazette-notification list (dl_216–dl_220) shows no LWF item.
- **Stored rule:** not stored.

### CG — Chhattisgarh — SECONDARY_SOURCE_ONLY (unchanged). Does any official source exist for the LWF statement?
- **Partly.** An official Chhattisgarh Labour Department portal, https://shramevjayate.cg.gov.in/shramik/Lwb/ (dl_48), served under "छत्तीसगढ़ शासन, श्रम विभाग" (Government of Chhattisgarh, Labour Department) and hosted by NIC, offers the service "श्रम कल्याण मंडल" (Labour Welfare Board) → "स्थापना पंजीयन हेतु आवेदन पत्र" (application for establishment registration). The portal home (dl_47) lists "श्रम कल्याण मंडल".
- This is official evidence that a CG Labour Welfare Board operates and registers establishments. It does **not** state any rate, period, deduction month or due date.
- **No official source was found for the catalogue's figures** (₹15 / ₹45 per half-year, June/December, 15 July/15 January, ₹10,000 ceiling):
  - cglabour.gov.in and www.cglabour.gov.in fail TLS with a hostname mismatch (dl_45, dl_46).
  - cglabour.nic.in does not resolve.
  - A Hindi search found nothing official.
- **Catalogue statement:** keep the CG LWF figures at SECONDARY_SOURCE_ONLY. The catalogue may cite the shramevjayate.cg.gov.in Board page as official evidence of the Board's existence only.

### KL — Kerala — LEGAL_REVIEW_REQUIRED (unchanged) (fork B)
- **(a) Kerala LWF Act 1975:** s.15(1) as amended by Act 27 of 2021 (deemed from 16-01-2020) sets ₹45 / ₹45 per half-year, paid before 15 July and 15 January. It covers shops and commercial establishments with 2+ persons. Source: PRS gazette print (unsigned).
- **(b) Shops & Commercial Establishments Workers Welfare Fund:** the official board page (peedika.kerala.gov.in, dl_314) says "membership fee of Rs 50 per month" and the employer pays "Rs 50/- per month for each employee". The rate notification is NOT CONFIRMED (Malayalam scans not OCR'd).
- **Not resolved:** which fund an ordinary private employer pays into. Not stored.

### GA — Goa — OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged) (fork B)
- **Instrument:** Goa Act 9 of 2015, s.6, Official Gazette Series I No. 22 (Extraordinary No. 2), 28-08-2015, "shall come into force at once" (dl_303, labour.goa.gov.in). Verbatim: "Every employee shall contribute rupees one hundred and twenty per year … every employer and the Government … rupees three hundred and sixty and rupees two hundred and forty, respectively, per year".
- **Per half-year:** ₹60 / ₹180 (arithmetic).
- **Rules r.4:** statements by 31 July and 31 January, Form A.
- **Exclusions:** supervisory staff above ₹1,600. No headcount threshold.
- **Board page:** its "Rs.10/-, Rs. 30/- & Rs. 20/-" are monthly equivalents and remain ambiguous.
- **Goa Act 4 of 2024:** changes the Board's composition only (secondary). Not stored.

### DL — Delhi — OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged) (fork B)
- **Source:** Notification F.9(1)/89/DLC(W)/100, 13-07-2004, on dlwb.delhi.gov.in (dl_302).
- **Amounts:** ₹0.75 employee / ₹2.25 employer per half-year (Government ₹1.50). Register dates 30 June and 31 December; pay before 15 July and 15 January.
- **Threshold and exclusions:** 5+ persons; supervisory staff above ₹2,500 excluded.
- **Form:** Form A [Rule 3(4)] confirmed (dl_312).
- **Stored v1: matches.**

### UP — Uttar Pradesh — OFFICIAL_SOURCE_PARTIALLY_VERIFIED (unchanged, strengthened) (fork B)
- **Source:** UP LWF Rules 1972 (Notification 153(V)/XXXV-15-529(V)-71, 12-01-1972), read in full from a government host (dl_338).
- **No employee or employer payroll contribution.** The only periodic duty is r.3(iii): "all fines realised … and all unpaid accumulations during the quarters ending March 31, June 30, September 30 and December 31, shall be paid by the employer … on or before 15th April, 15th July, 15th October and 15th January".
- **Threshold:** "Establishment" requires 50+ persons.
- **s.3(2) of the Act:** read only from a secondary site.
- **Payroll:** no LWF deduction. This is a compliance item only.

### UK — Uttarakhand — SECONDARY_SOURCE_ONLY → OFFICIAL_SOURCE_PARTIALLY_VERIFIED (fork B)
- **Source:** labour.uk.gov.in "Acts & Rules" (dl_336) lists "The U.P. Labour Welfare Fund Rules, 1972".
- **Position:** the same as UP: no payroll contribution; quarterly remittance of fines and unpaid accumulations.
- **Not confirmed:** any Uttarakhand adaptation or amendment.

### DH — Dadra & Nagar Haveli and Daman & Diu — LEGAL_REVIEW_REQUIRED (unchanged) (fork B)
- **The 1986 Act:** Goa, Daman and Diu LWF Act 1986, s.1(3), commences "on such date as the Government may, by notification … appoint".
- **No Daman & Diu commencement notification** and no extension to DNH was found. The UT labour pages (ddd.gov.in, dl_321, dl_323) show no LWF.
- **Goa Act 9 of 2015** removed "Daman and Diu" from the Act.
- **Conclusion:** no evidence of any operating levy. If the 1986 Act were in force there unamended, its original rates would apply; those rates are NOT CONFIRMED.

### The 18 states believed to have no LWF (fork B)
- **SK, PY, RJ, ML → OFFICIAL_SOURCE_PARTIALLY_VERIFIED.** Each official list of labour laws enforced or administered contains no state LWF Act:
  - Sikkim (dl_320): "18 (eighteen) labour laws have been enforced".
  - Puducherry (dl_333) and Rajasthan (dl_334): "Acts & Rules" pages.
  - Meghalaya (dl_340): lists only the central Beedi and Cine Workers welfare-fund cess Acts.
  - These lists prove absence only for what they list.
- **AS, TR, HP, AR, BR, JH, JK, MN, NL, AN, LA, MZ, LD → SECONDARY_SOURCE_ONLY (unchanged).**
  - The lists found were partial, the hosts did not respond, or no list was published.
  - Assam has a tea-plantations-only fund (Assam Tea Plantations Employees Welfare Fund Act 1960), which matters only for tea estates.
- **UK:** see above.

---

## D. Unresolved items
1. **HR:** a government-host or gazette copy of HLWB/REV/2026/3436 (and of the 2023 and 2025 letters). The Haryana e-Gazette needs a CAPTCHA, and the hrylabour notifications list does not carry them. B8 threshold scope: legal review.
2. **MH:** a government-host copy of Mah. XXV of 2024 and Mah. 24 of 2003 (India Code returned 504). Needed to restore MH to primary.
3. **TN:** current r.12 text (June vs December). Also the number of the TN LWF (Amendment) Act 2021.
4. **KA:** a board or legal confirmation of the CY2024 rate (₹20/₹40 on the statutory reading). The gazette notification number and assent date of Act 05 of 2026.
5. **AP:** the text of Act 7 of 2015 amending s.10 (₹30/₹70), and any threshold or ceiling change.
6. **MP:** gazette text of the January 2026 rate notification, and the legal condition for the ₹2,500 employer minimum.
7. **PB:** gazetting of Bill 15-PLA-2025. **CH:** the text of G.S.R. 353(E)/2018.
8. **CG:** any official rate instrument. Only the Board's existence is officially evidenced.
9. **KL:** which fund applies. **DH:** whether the 1986 Act operates.
10. **OD:** a government-host copy of the Rules 2015 gazette, and the per-half-year split.

## E. Access failures
| URL | Failure | What was done instead |
|---|---|---|
| https://kla.kar.nic.in/council/house/bills/154/44.pdf, …/157/03eng.pdf | TLS: certificate has expired (curl 60) | Used the DPAL consolidations and the PRS copy of LA Bill 68/2025 |
| https://dpal.karnataka.gov.in/ | Incomplete chain ("unable to get local issuer certificate") | Completed the chain via AIA (GlobalSign GCC R46 OV TLS CA 2025 → Root R46); then verified |
| https://klwb.karnataka.gov.in/61/click-here-for-klwb-fund-act-1965-&-klwb-fund-rules-1968/en | HTTP 500 | Used the DPAL copies |
| https://egazetteharyana.gov.in/ | Incomplete chain (AIA-completed); the search form needs a CAPTCHA | Not queried; HLWB/REV/2026/3436 not retrieved |
| https://www.indiacode.nic.in/… (MH Act 19974; Goa 19807; UP 17134; UK 10459; PB 9153) | HTTP 504 (Akamai edge), repeated | PRS and other copies, labelled partial |
| https://kygcms.tn.gov.in/cms_migrated/document/press_release/pr070122_e_039.pdf | Connection timeout (curl 28); ECONNREFUSED via WebFetch | Content seen only through a search-engine summary |
| https://lwb.tn.gov.in/about.php | HTTP 404 | — |
| https://www.labour.wb.gov.in/sites/default/files/upload/notice/Labr-576641… | TLS hostname mismatch | Official copy obtained from labour.wb.gov.in/labour_main_api/storage/… |
| https://cglabour.gov.in/, https://www.cglabour.gov.in/ | TLS hostname mismatch | shramevjayate.cg.gov.in read instead |
| cglabour.nic.in | DNS NXDOMAIN | — |
| https://pareshram-labour.odisha.gov.in/…/EoDB-OLWF%20Act.pdf | HTTP 404 | PRS print |
| https://pblabour.gov.in/… (first try) | Timeout; then incomplete chain, AIA-completed | Act PDF obtained (the 1965 text without s.9-A) |
| legislative.gov.in / mha.gov.in / the Hindi-domain copy of 173__punjab_labour_28082017.PDF | HTTP 404 | — |
| https://egazette.gov.in/WriteReadData/… | Incomplete chain (AIA-completed) | Files obtained, but they are not LWF instruments |
| https://compfie.aparajitha.com/…/09122020_LEI_07.pdf (vendor) | HTTP 504 | — |
| labour.goa.gov.in/?p=296 | HTTP 404 | Fork B |
| labour.bihar.gov.in, shramadhan.jharkhand.gov.in, jklabouremp.nic.in, labour.nagaland.gov.in, labour.mn.gov.in, labour.andaman.gov.in, labour.ladakh.gov.in | No response (probed hostnames) | Fork B |
| labour.mizoram.gov.in | HTTP 404 | Fork B |
| Kerala LWF Board hosts (guessed) | No response; no official 1975-Act board site found | Fork B |
| peedika.kerala.gov.in Act and scheme scans | Malayalam scans, no OCR model | Read visually where possible |
