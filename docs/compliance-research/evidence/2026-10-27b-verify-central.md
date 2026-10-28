# Central labour-law facts: verification pass 2026-10-27b

Prepared 2026-10-27. This pass builds on `docs/compliance-research/india-compliance-source-register.md` (§§1, 3, 4)
and `docs/compliance-research/evidence/2026-10-27-verify-central.md`, referred to below as "the register" and
"the earlier evidence". No repository file was edited by the pass; this copy was added to the evidence folder afterwards.

Labels are the register's own, used exactly: OFFICIAL_PRIMARY_SOURCE_VERIFIED (PRIMARY),
OFFICIAL_SOURCE_PARTIALLY_VERIFIED (PARTIAL), SECONDARY_SOURCE_ONLY (SECONDARY), CONFLICTING_SOURCES,
LEGAL_REVIEW_REQUIRED, UNVERIFIED. A mirror copy is never treated as primary, and a secondary source never
overrides an official one. Facts not found are written NOT CONFIRMED. "Computed" marks arithmetic of mine, not
a statement in any source.

Documents were downloaded into a temporary working folder that is not kept in the repository; §0 lists each one's
URL and the start of its SHA-256, so any copy can be checked. Downloads used curl with TLS verification on and
`--proto =https`. The two minimum-wage orders are scans and were read by OCR.

## 0. Documents read in this pass

| dl | Document | Host | sha256 (first 16) | Integrity |
|---|---|---|---|---|
| dl_2 | Code on Wages (Central) Rules, 2026, G.S.R. 343(E), 08-05-2026, Gazette CG-DL-E-08052026-272365 (Extraordinary No. 309), 69 pp. | labour.gov.in `/static/uploads/2026/05/6eb0c35ba63b776487a025e5123b6b12.pdf` | 39fd8e7c94ea7515 | GoI Press signature (S. K. Srivastava, 2026-05-08 20:12) reports **Digest Mismatch**: the file was re-saved after signing, the same as the SS Rules copy. Official host. |
| dl_3 | S.O. 5109(E), 17-09-2026 (EPF wage ceiling ₹25,000; supersedes S.O. 2702(E)) | labour.gov.in `/static/uploads/2026/09/2b0f1dbbd3769fc388416186ec145328.pdf` | 970c2ea088c808a2 | Signature valid, whole document |
| dl_5 | S.O. 2702(E), 29-05-2026 (₹15,000 wage ceiling, Chapter III) | labour.gov.in `/2026/06/1dbfa6f5…pdf` | — | Signature valid |
| dl_8 | S.O. 2451(E), 12-05-2026 (DG Labour Bureau appointed under CoW s.7(2) to compute cost-of-living allowance) | labour.gov.in `/2026/05/92d8e09f…pdf` | — | Signature valid |
| dl_10 | S.O. 4710(E), 25-08-2026, CG-DL-E-25082026-275741 | labour.gov.in `/2026/09/3977906b27b1b53b9b98c245091b0726.pdf` | e5c782fc9dcef917 | Signature valid, whole document, signed 2026-08-25 19:16:24 |
| dl_12 | OSH (Central) Rules, 2026, G.S.R. 345(E), CG-DL-E-09052026-272379, 318 pp. | labour.gov.in `/2026/05/ee246f790cad0b8e99c3828f34fa09a6.pdf` | e70b1398caedc1f2 | Signature valid, whole document (2026-05-09 00:39:59). Before this, the register held this instrument only via a Simpliance copy. |
| dl_14 | Payment of Gratuity Act, 1972 (consolidated, with the Act 12 of 2018 footnotes), 11 pp. | labour.gov.in `/static/uploads/2025/06/23e3be0e31de495acd1698ed85007ec1.pdf` | 1c39f70ef567db0b | Statute text on an official host |
| dl_15 | Payment of Gratuity Act, 1972 (older consolidation: s.4(3) "ten lakh rupees") | labour.gov.in `/2025/06/072a4b7e…pdf` | — | Superseded text; not relied on |
| dl_19 | Tamil Nadu Labour Dept PDF reproducing S.O. 1420(E) (a taxguru.in printout) | labour.tn.gov.in | 2fd3b93d085ed732 | Official host, but the content is a print of a secondary web page. Treated as a mirror. |
| dl_22 | DPE O.M. No. W-02/0020/2018-DPE (WC)-GL-XII/18, 11-04-2018 (cites S.O. 1419(E) and S.O. 1420(E)) | dpe.gov.in `/static/uploads/2025/07/297e62a32e3e83bfcb7fafbdd27c8564.pdf` | eb82121b0f24b664 | Official GoI memorandum (scan) |
| dl_23 | PIB release 2022470, 01-06-2024 (DoPPW: ₹25 lakh for Central Government employees) | pib.gov.in | — | Official press release |
| dl_24 | Code on Social Security, 2020, "As on the 21th November, 2025", 113 pp. | indiacode.nic.in `/bitstream/123456789/16823/1/aA2020-36.pdf` | 53ad67f75fbc874f | Statute text on an official host |
| dl_25 | Social Security (Central) Rules, 2026, G.S.R. 344(E), CG-DL-E-08052026-272366, 259 pp. | labour.gov.in `/2026/05/49aa9b62c2125499c37399b90e969d67.pdf` | 37c5a8cd8684e936 | Signature reports Digest Mismatch (as recorded before). Official host. |
| dl_26 | MoLE "Additional FAQs on Labour Codes (As on 16.03.2026)" | labour.gov.in `/2026/03/a4ccf4c6…pdf` | d55818a3956bebf5 | Official FAQ (non-binding) |
| dl_27 | MoLE "FAQs on Labour Codes" (30-12-2025) | labour.gov.in `/2026/01/de4758d5…pdf` | cadd78f9b48ebc26 | Official FAQ (non-binding) |
| dl_30 | VDA orders w.e.f. 01-10-2026 (Labour Bureau, File No. 6/1/2026-VDA, 30-09-2026), 30 pp. scan | clc.gov.in `/wp-content/uploads/2026/10/VDA-Oct-26.pdf` | dd88901c039b0dcb | Unsigned scan, read by OCR (tesseract, eng) with visual check of one table |
| dl_32 | VDA orders w.e.f. 01-04-2026 (CLC(C), File No. 1/6(1)/2025-LS-II etc., 30-03-2026), 29 pp. scan | clc.gov.in `/wp-content/uploads/2026/10/April-2006.pdf` (the listing calls it "VDA Order April 2026") | 39d6cdfb04b45114 | Unsigned scan, read by OCR |
| dl_33 | ESIC "A Guide For Employers" (PDF created 2024-09-10) | esic.gov.in `/attachments/publicationfile/6f673bcd3d6110170f790d2909767b4f.pdf` | f63e11d822fbefd1 | Official guidance, not an instrument |
| dl_35 | Income-tax Rules, 2026, G.S.R. 198(E), 20-03-2026, 976 pp. | Copy from the repository evidence store (`database/data/compliance/evidence/tds/income-tax-rules-2026-gsr-198e-itd.pdf`). Per the catalogue, retrieved 2026-10-01 from incometaxindia.gov.in. | f565e0f5ff3bf8f7 | **sha256 matches the catalogue's evidence record.** Not signed. A live re-fetch from incometaxindia.gov.in returned 403 today. |
| dl_38 | Sexual Harassment of Women at Workplace Rules, 2013, G.S.R. 769(E), 09-12-2013 (gazette print) | wcd.gov.in `/documents/uploaded/1719914401_2NUqe91gql.pdf` | 48469a08e87fcf94 | Official host |
| dl_39 | Sexual Harassment of Women at Workplace Act, 2013 (gazette print) | wcd.gov.in `/documents/uploaded/1710219823.pdf` | abe091d5a22cf2a4 | Official host |

Note on signatures: `pdfsig` reports "Certificate issuer isn't Trusted" for every GoI Press signature, because no
NSS trust database for the India PKI is installed here. "Signature is Valid" means the cryptographic digest over
the signed byte ranges matches. That is the same test the register applied.

---

## 1. GRATUITY

### 1a(i). Coverage under the Payment of Gratuity Act, 1972: s.1(3) and s.1(3A)

- **Previous label:** not in the register (the PGA was cited only via the 2018 PIB release).
- **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (statute text, labour.gov.in).
- **Source:** dl_14, https://www.labour.gov.in/static/uploads/2025/06/23e3be0e31de495acd1698ed85007ec1.pdf
- **Instrument:** PGA 1972 (39 of 1972), s.1(3); s.1(3A) inserted by Act 26 of 1984, s.2. The insertion's date
  is NOT CONFIRMED: footnote 1 at the s.1(3A) bracket was not separately extracted.
- **Dates:**
  - Act dated 21 Aug 1972.
  - s.1(3A) amendment: NOT CONFIRMED.
  - **Repeal:** by Code on Social Security s.164(1) item 6. The repeal is in force from 21 Nov 2025: India Code
    footnote, "Items 1 and 2 and items 4 to 9 of sub-section (1) of section 164 … vide notification no.
    S.O. 5319 (E), dated 21st November, 2025".
- **Extract:**
  > "(3) It shall apply to— (a) every factory, mine, oilfield, plantation, port and railway company; (b) every shop or establishment within the meaning of any law for the time being in force in relation to shops and establishments in a State, in which ten or more persons are employed, or were employed, on any day of the preceding twelve months; (c) such other establishments or class of establishments, in which ten or more employees are employed, or were employed, on any day of the preceding twelve months, as the Central Government may, by notification, specify in this behalf."
  >
  > "[(3A) A shop or establishment to which this Act has become applicable shall continue to be governed by this Act notwithstanding that the number of persons employed therein at any time after it has become so applicable falls below ten.]"
- **Conclusion:** the PGA governs exits up to 20 Nov 2025. Its 10-person test counts **persons**, and its limb (b)
  needs the establishment to be a shop or establishment under a State S&E law.
- **Unresolved:** none for the text.

### 1a(ii). Coverage under the Code on Social Security, 2020: First Schedule entry for Chapter V, and s.1(8)

- **Previous label:** PRIMARY ("SS First Schedule thresholds").
- **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged; re-read).
- **Source:** dl_24, https://www.indiacode.nic.in/bitstream/123456789/16823/1/aA2020-36.pdf
- **Instrument:** SS Code s.1(4), s.1(8), First Schedule (Chapter V).
- **Dates:** Code assented 28 Sep 2020 (not re-checked). Commencement of ss.1–14 and 17–141 (which include
  Chapter V, ss.53–58), and of the s.164 repeal items: 21 Nov 2025 (S.O. 5319(E), India Code footnote).
- **Extracts:**
  > First Schedule, Chapter V, Gratuity: "(a) every factory, mine, oilfield, plantation, port and railway company; and (b) every shop or establishment in which ten or more employees are employed, or were employed, on any day of the preceding twelve months; and such shops or establishments as may be notified by the appropriate Government from time to time."
  >
  > s.1(4): "The applicability of the Chapters specified in columns (1) and (2) of the First Schedule shall … be such as is specified in corresponding entry in column (3) of that Schedule."
  >
  > s.1(8): "Notwithstanding anything contained in sub-section (4), an establishment to which any Chapter applies at the first instance shall continue to be applied thereafter even if the number of employees therein at any subsequent time falls below the threshold specified in the First Schedule in respect of that Chapter."
- **Conclusion: three differences from the PGA.**
  1. The count is of **employees**, not persons.
  2. Limb (b) no longer refers to a State S&E law.
  3. The "stay covered" rule is now general, in s.1(8), not gratuity-specific.
- **Unresolved:** whether "employees" in the Schedule is read with s.2(26) (employee, which carries exclusions)
  rather than with every person engaged. LEGAL_REVIEW_REQUIRED, low impact for typical offices.

### 1b(i). Eligibility, grounds and the five-year rule (PGA s.4(1); Code s.53(1))

- **Previous label:** PRIMARY (Code).
- **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED for both texts.
- **Sources:** dl_14 (PGA), dl_24 (Code).
- **Extracts:**
  > PGA s.4(1): "Gratuity shall be payable to an employee on the termination of his employment after he has rendered continuous service for not less than five years,— (a) on his superannuation, or (b) on his retirement or resignation, or (c) on his death or disablement due to accident or disease: Provided that the completion of continuous service of five years shall not be necessary where the termination of the employment of any employee is due to death or disablement".
  >
  > Code s.53(1) adds "(d) on termination of his contract period under fixed term employment; or (e) on happening of any such event as may be notified by the Central Government". The 2nd proviso reads: "completion of continuous service of five years shall not be necessary where the termination of the employment of any employee is due to death or disablement or expiration of fixed term employment or happening of any such event as may be notified by the Central Government". The 1st proviso reduces five years to three for working journalists. The 3rd proviso ("Provided also") pays a deceased employee's gratuity to the nominee or heirs, with minors' shares deposited with the competent authority.
- **Explanation 2 (disablement):** "such disablement as incapacitates an employee for the work which he was
  capable of performing before the accident or disease".
- **Explanation 1** excludes holders of a post under the Central or State Government who are governed by other
  gratuity rules.
- **Conclusion:** unchanged from the register. No event under s.53(1)(e) was found notified (search only).
- **Unresolved:** whether any event has been notified under s.53(1)(e): NOT CONFIRMED.

### 1b(ii). Continuous service and the 240/190-day deeming (PGA s.2A; Code s.54)

- **Previous label:** PRIMARY (Code s.54). The "4 years + 240 days" point was LEGAL_REVIEW_REQUIRED, case law
  not verified.
- **New label:**
  - Statute text: OFFICIAL_PRIMARY_SOURCE_VERIFIED (both).
  - 4 years + 240 days: **LEGAL_REVIEW_REQUIRED** (unchanged). It is now cited, but only through secondary
    reporting.
- **Extracts:**
  > PGA s.2A(2): "where an employee (not being an employee employed in a seasonal establishment) is not in continuous service within the meaning of clause (1), for any period of one year or six months, he shall be deemed to be in continuous service under the employer— (a) for the said period of one year, if the employee during the period of twelve calendar months preceding the date with reference to which calculation is to be made, has actually worked under the employer for not less than— (i) one hundred and ninety days, in the case of an employee employed below the ground in a mine or in an establishment which works for less than six days in a week; and (ii) two hundred and forty days, in any other case; (b) for the said period of six months … (i) ninety-five days … (ii) one hundred and twenty days".
  >
  > Explanation (PGA, inserted 1987): days actually worked include lay-off, "leave with full wages, earned in the previous year", temporary disablement from an employment accident, and maternity leave not exceeding "such period as may be notified by the Central Government" (substituted for "twelve weeks" by Act 12 of 2018 w.e.f. 29-3-2018).
  >
  > Code s.54(B) is the same text, with maternity leave capped directly: "does not exceed twenty-six weeks". s.54(C): seasonal establishments need "not less than seventy-five per cent. of the number of days on which the establishment was in operation".
- **Case law** (none read on a court website; all from LiveLaw, "The Jurisprudence Of Gratuity…",
  https://www.livelaw.in/amp/know-the-law/minimum-continuous-service-requirement-payment-of-gratuity-act-analysis-298114,
  and forum/blog leads; SECONDARY):

  | Case | Court and reference | Reported holding |
  |---|---|---|
  | Mettur Beardsell Ltd v. Regional Labour Commissioner (Central) | Madras HC, W.P. 2135/1987, decided 1996; cited as 1998 LLR 1072 / 1998 (3) LLN 414 (citations inconsistent across reproductions) | 240-day deeming applied to the fifth year: for |
  | Management of Salem Textiles Ltd v. Appellate Authority | Madras HC, W.P. 32663/2007 (2011) | For |
  | Sreeja B. v. Regional Joint Labour Commissioner | Kerala HC, W.P.(C) 13911/2012 (2015) | For |
  | TV Today Network Ltd v. Ankita Sodhi | Delhi HC, W.P.(C) 6271/2019 (2024); exit at 4 y 11 m 20 d | For |
  | Rajasthan SRTC v. Mohani Devi | C.A. No. 2236 of 2020 (court and year NOT CONFIRMED) | Literal reading of s.4(1), described as obiter |

  No Supreme Court ruling squarely deciding the fifth-year question was identified.
- **Conclusion:** it is **not legally settled**. Several High Courts accept 4 years + 240 days (190 where
  applicable) in the twelve months before exit. Note that the statutory deeming is triggered only where the
  employee "is not in continuous service within the meaning of clause (1)". Whether it extends to uninterrupted
  service of 4 years plus a part year is exactly the contested point.
- **Product implication:** the calculator should make "4y + 240 days qualifies" a tenant- or legal-signed-off
  setting, defaulting to strict five years until legal review. Rounding of the **amount** (part year over six
  months, s.53(2)) is a separate rule and is settled text.
- **Unresolved:**
  - The judgments were not read from court websites.
  - Whether courts apply the PGA case law to Code s.54 (identical text) is LEGAL_REVIEW_REQUIRED.

### 1b(iii). Amount (Code s.53(2); PGA s.4(2))

- **Previous label:** PRIMARY. **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged).
- **Extract** (Code s.53(2)):
  > "For every completed year of service or part thereof in excess of six months, the employer shall pay gratuity to an employee at the rate of fifteen days' wages or such number of days as may be notified by the Central Government, based on the rate of wages last drawn by the employee concerned: Provided that in the case of a piece-rated employee, daily wages shall be computed on the average of the total wages received by him for a period of three months immediately preceding the termination of his employment, and, for this purpose, the wages paid for any overtime work shall not be taken into account: Provided further that in the case of an employee who is employed in a seasonal establishment and who is not so employed throughout the year, the employer shall pay the gratuity at the rate of seven days' wages for each season: Provided also that in the case of an employee employed on fixed term employment or a deceased employee, the employer shall pay gratuity on pro rata basis."
  >
  > Explanation 3: "in the case of a monthly rated employee, the fifteen days' wages shall be calculated by dividing the monthly rate of wages last drawn by him by twenty-six and multiplying the quotient by fifteen."
- **PGA s.4(2)** is identical, except that it has no "or such number of days as may be notified" and no
  fixed-term/deceased pro-rata proviso.
- **Other subsections:**
  - Code s.53(4) (reduced wages after disablement) and s.53(5) (better terms) match PGA s.4(4) and s.4(5).
  - No notification under s.53(2) changing the fifteen days was found (search only): NOT CONFIRMED that none exists.
- **Unresolved:** what "pro rata" adds for a deceased employee, given that the amount is already per completed
  year: LEGAL_REVIEW_REQUIRED, low impact.

### 1b(iv). Forfeiture (PGA s.4(6); Code s.53(6))

- **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (texts identical in substance).
- **Extract** (Code):
  > "(a) the gratuity of an employee, whose services have been terminated for any act, wilful omission or negligence causing any damage or loss to, or destruction of, property belonging to the employer, shall be forfeited to the extent of the damage or loss so caused; (b) the gratuity payable to an employee may be wholly or partially forfeited— (i) if the services of such employee have been terminated for his riotous or disorderly conduct or any other act of violence on his part, or (ii) if the services of such employee have been terminated for any act which constitutes an offence involving moral turpitude, provided such offence is committed by him in the course of his employment."
- **Unresolved:** whether a criminal conviction is needed for (b)(ii) is case law: LEGAL_REVIEW_REQUIRED.

### 1b(v). Payment time limit and interest (PGA s.7(2)–(3A); Code s.56(2)–(4); SS (Central) Rules r.33)

- **Previous label:**
  - Process: PRIMARY.
  - Interest rate: NOT CONFIRMED.
- **New label:**
  - Time limit: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
  - Interest text: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
  - **Rate:** SECONDARY_SOURCE_ONLY, with the S.O. number CONFLICTING among secondary sources. Its survival
    under the Code is LEGAL_REVIEW_REQUIRED.
- **Extracts:**
  > PGA s.7(3): "The employer shall arrange to pay the amount of gratuity within thirty days from the date it becomes payable to the person to whom the gratuity is payable." s.7(3A): "…simple interest at such rate, not exceeding the rate notified by the Central Government from time to time for repayment of long-term deposits, as that Government may, by notification specify: Provided that no such interest shall be payable if the delay in the payment is due to the fault of the employee and the employer has obtained permission in writing from the controlling authority".
  >
  > Code s.56(3) is the same 30 days. s.56(4): "…simple interest at such rate, not exceeding the rate notified by the Central Government from time to time for repayment of long term deposits". The words "as that Government may, by notification specify" are **dropped**.
  >
  > s.56(2) (both laws): "As soon as gratuity becomes payable, the employer shall, whether an application … has been made or not, determine the amount of gratuity and give notice in writing to the person … and also to the competent authority".
  >
  > SS (Central) Rules r.33(2)(a)(i): within fifteen days of the application, the employer issues Form-V "fixing a date, not being later than the thirtieth day after the date of receipt of the application". r.33(3): payment "through Demand Draft or by crediting in the bank account".
- **Rate:**
  - Secondary sources (LiveLaw and Verdictum reports of the Jharkhand HC in *Tata Steel Ltd v. State of
    Jharkhand*; search extracts) report a notification of 01-10-1987 fixing **10% simple interest**.
  - It is cited as S.O. 873(E) by some and S.O. 874(E) by others. CONFLICTING.
  - The notification was not read.
  - The SS (Central) Rules 2026 contain no gratuity interest rate. The 12% in r.57 is for s.77 (employee's
    compensation), not gratuity.
  - Whether the 1987 PGA notification survives under Code s.164(2)(a) for s.56(4) is LEGAL_REVIEW_REQUIRED.
- **Unresolved:** the 1987 notification text, its S.O. number, and its status under the Code.

### 1b(vi). Nomination (PGA s.6; Code s.55; SS (Central) Rules r.32)

- **Previous label:** PRIMARY (r.32). **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged).
- **Extracts:**
  > Code s.55(1): "Each employee, who has completed one year of service, shall make, a nomination within such time, in such form and in such manner, as may be prescribed by the appropriate Government." s.55(3): nomination in favour of a non-family member, where the employee has a family, "shall be void".
  >
  > r.32(1): Form-III in duplicate; "(i) … already in employment for a year or more on the date of commencement of these rules … within ninety days from such date; and (ii) … completes one year of service after the date of commencement … within ninety days of the completion of one year of service". Late nominations are accepted: "no nomination so accepted shall be invalid merely because it was filed after the specified period". r.32(2): verified and attested duplicate returned within thirty days; Aadhaar of the nominee to be provided.
- **PGA s.6:** same structure, with "such time … as may be prescribed". The time limit in the Payment of Gratuity
  (Central) Rules, 1972 was not read: NOT CONFIRMED.
- **Unresolved:** none for the Code.

### 1c. Wages for gratuity (PGA s.2(s); Code s.2(88))

- **Previous label:**
  - Code s.2(88): PRIMARY.
  - Applying the 50% add-back to gratuity: PARTIAL (MoLE FAQ).
- **New label:**
  - PGA s.2(s): OFFICIAL_PRIMARY_SOURCE_VERIFIED (new).
  - Code s.2(88) text including the proviso: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
  - **Application of the proviso to gratuity:** OFFICIAL_SOURCE_PARTIALLY_VERIFIED, unchanged. The statute is
    consistent with it, and the confirmation is a non-binding MoLE FAQ.
- **Extracts:**
  > PGA s.2(s): "'wages' means all emoluments which are earned by an employee while on duty or on leave in accordance with the terms and conditions of his employment and which are paid or are payable to him in cash and includes dearness allowance but does not include any bonus, commission, house rent allowance, overtime wages and any other allowance."
  >
  > Code s.2(88): "'wages' means all remuneration, whether by way of salaries, allowances or otherwise … and includes,— (a) basic pay; (b) dearness allowance; and (c) retaining allowance, if any, but does not include—" (a)–(k). First proviso: "Provided that for calculating the wages under this clause, if payments made by the employer to the employee under sub-clauses (a) to (i) exceeds one-half, or such other per cent. as may be notified by the Central Government, of the all remuneration calculated under this clause, the amount which exceeds such one-half, or the per cent. so notified, shall be deemed as remuneration and shall be accordingly added in wages under this clause". Second proviso: "for the purpose of equal wages to all genders and for the purpose of payment of wages, the emoluments specified in sub-clauses (d), (f), (g) and (h) shall be taken for computation of wage". Explanation: remuneration in kind up to 15% of total wages is deemed wages.
  >
  > MoLE Additional FAQ (16-03-2026) Q6: "Is the revised definition of 'wages' … applicable for gratuity calculation from … 21.11.2025?" Reply: "Yes. Gratuity, based on revised definition of wages will be applicable w.e.f. 21.11.2025". Q13: "Any payment made to employee which is not part of components mentioned under section 2(88) of the Code on Social Security, 2020 shall not be considered for calculation of gratuity." Q17: gratuity is paid on the rate of wages last drawn "on and after 21.11.2025 as per the provisions of Code on Social Security, 2020" (no split of pre-Code service). Q1(a): "Gratuity, ESI and other retirement benefits are not included" in remuneration for the 50% test; employer PF/pension and statutory bonus are.
- **Conclusion:**
  - s.2(88) is the Code's only definition of "wages", and Chapter V uses "wages" (s.53(2) "fifteen days' wages").
    The definition section applies "unless the context otherwise requires", and nothing in Chapter V displaces it.
  - The first proviso is drafted "for calculating the wages under this clause", so it travels with the definition.
  - The second proviso is limited to equal wages and payment of wages, so it does not apply to gratuity.
  - The MoLE FAQ confirms the gratuity application.
  - Main practical change from the PGA: allowances outside the exclusion list, and excluded components above
    50%, now enter the gratuity base.
- **Unresolved:**
  - No "other per cent." has been notified (none found).
  - How unlisted allowances (for example "special allowance") are treated: LEGAL_REVIEW_REQUIRED (as before).

### 1d. Ceiling

**(i) ₹20 lakh, the 2018 notification under PGA s.4(3)**

- **Previous label:** PARTIAL (S.O. number from search extracts only).
- **New label:** **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged label, better evidence). The S.O. number, date
  and operative words are now confirmed by an official GoI memorandum (DPE, dpe.gov.in). The gazette instrument
  itself was not read on a government host (egazette unreachable; labour.gov.in 404; the lc.wb.gov.in copy
  failed TLS).
- **Sources:**
  - dl_22, https://www.dpe.gov.in/static/uploads/2025/07/297e62a32e3e83bfcb7fafbdd27c8564.pdf (DPE O.M. dated 11-04-2018).
  - dl_19, https://labour.tn.gov.in/pdf/archives/Govt-increases-maximum-gratuity-limit.pdf (TN Labour Dept PDF
    of a taxguru printout; a mirror).
  - dl_14 (PGA s.4(3) footnote).
- **Instrument:** S.O. 1420(E) dated 29-03-2018, F. No. S-42012/02/2016-SS-II, signed Manish Gupta, Jt. Secy (file
  number and signatory from the dl_19 mirror only). Made under PGA s.4(3) as amended by Act 12 of 2018, s.4.
- **Dates:**
  - Notification date: 29 Mar 2018.
  - Gazette publication: NOT CONFIRMED (a search extract of the lc.wb.gov.in copy says Gazette Extraordinary
    Pt II s.3(ii) No. 1283; not read).
  - Commencement of Act 12 of 2018: 29 Mar 2018 (S.O. 1419(E), per the DPE O.M.).
  - Effective date of the ceiling: no separate date stated in the operative text read. PGA footnote: s.4(3)
    substituted "w.e.f. 29-3-2018".
- **Extracts:**
  > DPE O.M.: "M/o L&E vide Notification No. S.O. 1420(E) dated 29.03.2018 has notified that Central Government hereby specifies that the amount of gratuity payable to an employee under the Act shall not exceed twenty lakh rupees."
  >
  > PGA s.4(3): "The amount of gratuity payable to an employee shall not exceed [such amount as may be notified by the Central Government from time to time]". Footnote 4: "Subs. by Act 12 of 2018, s. 4, for 'ten lakh rupees' (w.e.f. 29-3-2018)."

**(ii) Notification under Code s.53(3)**

- **Previous label:** NOT CONFIRMED / PARTIAL.
- **New label:** **NOT CONFIRMED (none found)**. Web searches in 2026 found no notification under s.53(3).
- **Extract:** s.53(3): "The amount of gratuity payable to an employee shall not exceed such amount as may be
  notified by the Central Government."
- **Carry-over route** (Code s.164(2)(a), PRIMARY): notifications made under the repealed Acts "shall be deemed to
  have been … provided for such purpose under the corresponding provisions of this Code … and shall be in force to
  the extent they are not contrary to the provisions of this Code … till they are repealed".
- **MoLE FAQ 30-12-2025, Q12** (PARTIAL): "The maximum gratuity is as notified by the Central Government which is
  currently ₹ 20 lakhs."
- **Conclusion:** ₹20 lakh applies under the Code by saving of S.O. 1420(E) (the statute's route), as MoLE's FAQ
  confirms. Whether a PGA s.4(3) notification is "made under the corresponding provision" (s.53(3)) is a mild
  legal point. LEGAL_REVIEW_REQUIRED, low risk.

**(iii) ₹25 lakh reports**

- **New label:** OFFICIAL_SOURCE_PARTIALLY_VERIFIED (PIB release, dl_23).
- **Source:** https://www.pib.gov.in/PressReleaseIframePage.aspx?PRID=2022470 (01-06-2024).
- **Extract:**
  > "…issued instructions vide OM No. 28/03/2024-P&PW (B)/Gratuity/9559 dated 30.05.2024, enhancing the maximum limit of Retirement Gratuity and Death Gratuity to Rs. 25 lakh under the CCS (Pension) Rules, 2021 and the CCS (Payment of Gratuity under NPS) Rules, 2021. This enhancement will be effective from 1st January 2024."
- **Conclusion:** ₹25 lakh is for **Central Government employees under CCS rules** (2024, not 2026). It is not the
  PGA or Code ceiling.
  - 2026 reports of ₹25 lakh concern bank employees: an NCBE letter, and a MoLE reply of 3 Aug 2026 citing
    s.53(5) "better terms". SECONDARY only; not read.
  - Nothing found raises the statutory ceiling.
- **Unresolved:** the MoLE letter of 3 Aug 2026 was not read.

### 1e. SS (Central) Rules 2026 r.33 (fixed-term one year), and state SS rules

- **Previous label:** PRIMARY. **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (unchanged; re-read on labour.gov.in).
- **Source:** dl_25.
- **Instrument:** G.S.R. 344(E), r.33(1)(a), second proviso.
- **Dates:**
  - Notification and publication: 08 May 2026 (Gazette CG-DL-E-08052026-272366).
  - Commencement: r.1(2), "They shall come into force on the date of their publication in the Official Gazette",
    so 08 May 2026.
- **Extract:**
  > "Provided further that an employee on fixed term employment shall be eligible for gratuity, if he renders service under the contract for a period of at least one year and for subsequent period in excess of six months and more, but less than one year, shall be rounded off to one additional year."
- **Also read:**
  - r.33(1)(d): where gratuity became payable before the rules commenced, limitation periods run from commencement.
  - r.33(4): an application to the competent authority (Form-VI) is due within 180 days.
- **State SS rules that change gratuity** (listed, not researched):

  | State | Change | Label |
  |---|---|---|
  | Gujarat | Code on Social Security (Gujarat) (Amendment) Rules, 2026, 13-02-2026: reportedly reduces the fixed-term qualifying period to one year | SECONDARY_SOURCE_ONLY (ascent-hr.com lead; not read) |

  No other state change to gratuity was found in this pass.
- **Unresolved:** the Gujarat amendment text; whether the one-year rule binds state-sphere establishments in
  states whose rules are silent.

---

## 2. BONUS

### 2a. S.O. 4711(E): eligibility ₹21,000

- **Previous label:** PARTIAL (mirror).
- **New label:** **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged). No official-host copy could be obtained.
- **Attempts:**
  - egazette.gov.in `WriteReadData/2026/275747.pdf`: TLS chain incomplete (see §10).
  - labour.gov.in: the S.O. 4710(E) companion is there (dl_10), but searches (site-restricted, by S.O. number, by
    file number S-33027/5/2026-WB, and by verbatim phrase) surfaced no labour.gov.in copy of 4711(E).
  - labour.gov.in listing pages are client-rendered and not readable by fetch (see §10).
- **Mirror text (TaxHeal, unchanged from the earlier evidence):** "every employee drawing wages not exceeding
  twenty-one thousand rupees per mensum shall be paid bonus as specified in the said sub-section … deemed to have
  come into force on the 21st day of November, 2025."
- **Dates:**
  - Notification: 25 Aug 2026.
  - Gazette CG-DL-E-25082026-275747 (from the mirror header).
  - Deemed commencement: 21 Nov 2025.
- **Unresolved:** an official-host copy (egazette.gov.in once its TLS chain is fixed, or a labour.gov.in upload).

### 2b. S.O. 4710(E): calculation ceiling ₹7,000 or Central minimum wage

- **Previous label:** PRIMARY. **New label:** OFFICIAL_PRIMARY_SOURCE_VERIFIED (re-read; signature valid).
- **Source:** dl_10.
- **Extract:**
  > "…where the wage of an employee being eligible for bonus under sub-section (1) of section 26, exceed seven thousand rupees per mensum, the bonus payable to such employee shall be calculated, as if his wage was seven thousand rupees per mensum or the minimum wage fixed by the Central Government, whichever is higher. 2. This notification shall be deemed to have come into force on the 21st day of November, 2025." [F. No. S-33027/5/2026-WB], DEEPIKA KACHHAL, Jt. Secy.
- **New supporting fact:** Wages (Central) Rules r.3(2) (dl_2, PRIMARY) prescribes the daily-to-monthly
  conversion for minimum wages:
  > "Where the rate of wages for a day is fixed, such amount shall be divided by eight for fixing the rate of wages for an hour and multiplied by twenty six for fixing the rate of wages for a month".
- **Effect on the open point:** the ×26 conversion is now primary for minimum-wage rates. Its use inside the
  S.O. 4710(E) comparator is still LEGAL_REVIEW_REQUIRED, but narrowed.
- **Illustration (computed):** the lowest Central rate found (§4) is ₹565/day (C-area unskilled, from
  01-10-2026). ×26 gives ₹14,690 a month, so for central-sphere employees the calculation wage is the Central
  minimum wage, not ₹7,000.

### 2c. Rates and limits (Code on Wages ss.26–41)

- **Previous label:** PRIMARY. **New label:** unchanged.
- The Code text was not re-read in this pass. The earlier evidence quotes s.26(1) "eight and one-third per cent. …
  or one hundred rupees, whichever is higher", the 20% maximum in s.26(3) and s.26(5), and s.41(2) "twenty or
  more persons".
- The Wages (Central) Rules Chapter V (rr.21–28) is now confirmed on labour.gov.in (dl_2):
  - r.21: contractor failure, principal pays minimum bonus.
  - rr.22–23: sixth and seventh accounting years.
  - rr.24–28: gross profits, further sums, carry-forward.
- The preamble supersedes the Payment of Bonus Rules, 1975 (item (ix)). PRIMARY.

### 2d. State bonus ceilings under the Code

- **Previous label:** LEGAL_REVIEW_REQUIRED (none found).
- **New label:** **NOT CONFIRMED (none found)**. LEGAL_REVIEW_REQUIRED stays for the state sphere.
- Searches found no State notification under Code on Wages s.26(1) or s.26(2). Commentary (Khaitan Ergo
  04-09-2026; LKS; Mondaq) says states are "expected" to issue their own. SECONDARY.

---

## 3. Code on Wages (Central) Rules, 2026: official copy and register forms

- **Previous label:** PARTIAL (PRS copy, unsigned).
- **New label:** **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (labour.gov.in copy; the GoI Press signature reports a digest
  mismatch, exactly as the SS Rules copy the register already accepts as primary).
- **Source:** dl_2, https://www.labour.gov.in/static/uploads/2026/05/6eb0c35ba63b776487a025e5123b6b12.pdf
- **Instrument:** G.S.R. 343(E), Ministry of Labour and Employment, "New Delhi, the 8th May, 2026"; Gazette of
  India Extraordinary Pt II s.3(i) No. 309, CG-DL-E-08052026-272365.
  - The short title is "Code on Wages (Central) Rules, 2026".
  - Draft: G.S.R. 936(E), 30 Dec 2025.
- **Dates:**
  - Notification: 08 May 2026.
  - Publication: 08 May 2026 (Gazette dated Friday, May 8, 2026).
  - Commencement and effective date: r.1(2), "They shall come into force on the date of their publication in the
    Official Gazette", so 08 May 2026.
- **Extract (r.51):**
  > "51. Form of register, etc.- (1) The employer of all establishments to which the Code applies shall maintain under sub-section (1) of section 50, electronically or in physical form in the formats appended to these rules, the following registers:–– (i) Employee Register in Form I; (ii) Register of Wages, Overtime, Advances, Fines and Deductions for Damage and Loss in Form IV; and (iii) Attendance Register-cum-Muster Roll in Form IX."
  >
  > r.51(2), (3): fines (s.19(8)) and deductions (s.21(3)) are recorded in Form IV. r.51(4): "The registers maintained under these rules shall be preserved for a period of five years after the date of last entry made therein."
  >
  > r.52: "Every employer shall issue wage slips, electronically or in physical form to the employees in Form V under sub-section (3) of section 50 on or before payment of wages."
- **Other forms in the rules:**
  - Form II: claim.
  - Form III: appeal under s.49(1).
  - Form VI: compounding.
  - Form VII: nomination.
  - Form VIII: notice to appear (the duplicate nomination text also mentions Form VIII).
- **Superseded rules include:**
  - Minimum Wages (Central) Rules 1950;
  - Payment of Wages (Procedure) Rules 1937;
  - Payment of Bonus Rules 1975;
  - Equal Remuneration Rules 1976;
  - item (xvii): the Ease of Compliance to Maintain Register (2017) rules for the ER, MW and PW Acts.
- **Also verified:** r.4, VDA computed "once before 1st April and then before 1st October in every year"; r.3(2),
  the ×26 monthly conversion.
- **Conclusion:** central-sphere registers are Forms I, IV and IX, with the Form V wage slip, from 08 May 2026. The
  2017 Ease-of-Compliance Forms A–E are superseded for the central sphere from that date.
- **Unresolved:** whether registers for 21 Nov 2025 – 7 May 2026 should follow the old rules (MoLE FAQ Q1: old
  rules continue "till final notification of new rules … to the extent these are in line with Codes"). Most
  likely yes. PARTIAL (FAQ).

---

## 4. Central minimum wages: VDA revisions effective 1 Apr 2026 and 1 Oct 2026

- **Previous label:** NOT CONFIRMED ("2026 central rates NOT CONFIRMED"; CLC order known only from a search extract).
- **New label:** **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (the orders themselves, on clc.gov.in). The figures were read
  by OCR from unsigned scans, with the construction table visually checked. Individual digits elsewhere carry an
  OCR risk.
- **Listing:** https://clc.gov.in/?page_id=144 (the old `/clc/min-wages` returns 404).
  - Row 1: "VDA Order October 2026 … 30/09/2026".
  - Row 2: "VDA Order April 2026 … 01/04/2026".

**April 2026** (dl_32, https://clc.gov.in/wp-content/uploads/2026/10/April-2006.pdf)

- **Issuer:** Office of the Chief Labour Commissioner (C), New Delhi.
- **File Nos.:** 1/6(1)/2025-LS-II (Agriculture), 1/6(6)/2025-LS-II (Watch & ward), 1/6(7)/2025-LS-II (Stone mines), etc.
- **Dated:** 30/03/2026 (some headers OCR-garbled).
- **Effective:** 01.04.2026.
- **Basis:** "average Consumer Price Index for Industrial workers reaching 424.80 from 413.52 as on 31.12.2025
  (Base 2016=100) … increase of 11.28 points".
- **Legal basis stated:** powers under S.O. 186(E)–192(E) of 19-01-2017.
  > "The instant notification is being issued in pursuance of sub-section (2) of Section 69 of the Code on Wages, 2019 (29 of 2019) and shall remain in force till the corresponding order is issued in pursuance of sub-section (2) of section 7 of the said Code".
- **Rates read (₹/day, basic + VDA):**

  | Employment | Category | A | B | C |
  |---|---|---|---|---|
  | Construction | Unskilled | 827 | 693 | 556 |
  | Construction | Semi-skilled / unskilled supervisory | 918 | 781 | 650 |
  | Construction | Skilled / clerical | 1008 | 918 | 781 |
  | Construction | Highly skilled | 1094 | 1008 | 918 |
  | Agriculture | Unskilled | 528 | 483 | 478 |
  | Loading/unloading | (single rate) | 827 | 693 | 556 |

  Sweeping-and-cleaning and watch-and-ward rates were not cleanly extracted: NOT CONFIRMED from OCR.

**October 2026** (dl_30, https://clc.gov.in/wp-content/uploads/2026/10/VDA-Oct-26.pdf)

- **Issuer: changed.** Labour Bureau, Chandigarh, signed Shyam Singh Negi, Director General.
- **File No.:** 6/1/2026-VDA. **Dated:** 30 September 2026. **Effective:** 01.10.2026.
- **Legal basis stated:**
  > "In exercise of the powers conferred by Central Government vide Notification No. S.O. 2451(E) dated 12th May, 2026 of the Ministry of Labour and Employment the undersigned hereby revises the rates of Variable Dearness Allowance … on the basis of the average Consumer Price Index for Industrial Workers reaching 431.42 from 424.80 as on 30.06.2026 (linked to Base 2016=100) and thereby resulting in an increase of 6.62 points."

  It also states issue "in pursuance of sub-section (2) of Section 69". S.O. 2451(E) itself was read (dl_8,
  signature valid): it appoints "Director General, Labour Bureau" as authority under CoW s.7(2) "for computation
  of the cost of living allowance", superseding S.O. 186(E)–192(E) of 2017.
- **Rates read (₹/day, basic + VDA):**

  | Employment | Category | A | B | C |
  |---|---|---|---|---|
  | Construction | Unskilled | 523+317=840 | 437+267=704 | 350+215=565 |
  | Construction | Semi-skilled / unskilled supervisory | 933 | 794 | 661 |
  | Construction | Skilled / clerical | 637+387=1024 | 933 | 794 |
  | Construction | Highly skilled | 693+418=1111 (visually checked) | 1024 | 933 |
  | Sweeping and cleaning | — | 840 | 704 | 565 |
  | Loading and unloading | — | 840 | 704 | 565 |
  | Watch and ward (without arms) | — | 1024 | 933 | 794 |
  | Agriculture | Unskilled | 537 | 491 | 486 |
  | Agriculture | Highly skilled | 705 | 655 | 587 |
  | Non-coal mines | Unskilled, above ground | 565 | — | — |
  | Non-coal mines | Unskilled, below ground | 704 | — | — |

  - Watch and ward (with arms) VDA: 418 / 387 / 354.
  - Areas A, B and C follow the city classification in Part II of the 19-01-2017 notification (Annexure I in
    each order).
- **Conclusion:**
  - Central-sphere minimum rates for 2026 are confirmed.
  - The October 2026 order comes from DG Labour Bureau under S.O. 2451(E), not from the CLC.
  - Monthly rate = daily × 26 (Wages Rules r.3(2)), so construction unskilled A-area from 1 Oct 2026 is
    ₹21,840 a month (computed).
- **Unresolved:**
  - OCR digits outside the checked table should be verified visually before they are loaded as rule data.
  - The sweeping and watch-and-ward rows of the April order were not extracted.

---

## 5. ESIC half-yearly return of contributions

- **Previous label:** CONFLICTING_SOURCES.
- **New label:** **OFFICIAL_SOURCE_PARTIALLY_VERIFIED.** ESIC's own employer guide says the return exists but is
  generated online from monthly filings. The regulation, or a circular dispensing with the separate filing, was
  not read.
- **Source:** dl_33, https://esic.gov.in/attachments/publicationfile/6f673bcd3d6110170f790d2909767b4f.pdf ("A Guide
  For Employers"; PDF created 2024-09-10).
- **Instrument:** ESI (General) Regulations reg. 26 (not read), via the ESIC guide.
- **Dates:** guide publication NOT CONFIRMED (PDF metadata 10-09-2024). Return periods: Apr–Sep and Oct–Mar.
- **Extract:**
  > "Returns/reports to be submitted by the Employer: The Employer has to submit the following records Online: … An annual return in Form 01-A by 31st January of every year showing the changes, if any, during the preceding year. Return of Contributions for the six months within 42 days of expiry of each contribution period, i.e. by 11th November for the contribution period ended 30th Sept., and 12th May for the contribution period ended 31st March. (Now it is generated online on the basis of the Monthly contribution filed)". Also: "Ensure that the Return of Contribution is generated in time by making regular payments (not after 42 day ending of contribution period)."
- **Conclusion:**
  - No separate employer filing is needed. The half-yearly return is system-generated from monthly contribution
    filings made within 15 days of the following month. Late monthly payment past the 42-day window affects its
    generation.
  - PeopleOS should produce a half-yearly contribution summary as an internal report, not as a filing obligation.
  - New side finding: ESIC lists an "annual return in Form 01-A by 31st January". Whether it is still required
    under the Code is NOT CONFIRMED.
- **Unresolved:**
  - The reg. 26 text, and any ESIC circular.
  - Whether ESIC has new regulations under the SS Code: NOT CONFIRMED. The SS Code s.164(2)(b) continuation of ESI
    regulations is **not in force** (India Code footnote). ESI regulations continue only via s.164(2)(a):
    LEGAL_REVIEW_REQUIRED.

---

## 6. Employment Information Return, Form XXVI (SS (Central) Rules r.56)

- **Previous label:** PRIMARY. Private reach: LEGAL_REVIEW_REQUIRED.
- **New label:** unchanged (re-read on labour.gov.in, dl_25).
- **Extracts:**
  > r.56(6): "An employer shall furnish to the concerned Career Centre (Regional) yearly returns in Form-XXVI (Employment Information Return) and yearly returns shall be furnished manually or, electronically, or digitally, as the case may be, as specified by the respective State Government by notification, within thirty days of the due date, namely, 31st March of the year."
  >
  > Explanation (B): "'establishment in private sector' means an establishment which is not an establishment in public sector and with 20 or more employees or such number of employees as may be notified by the Central Government are employed therein."
  >
  > r.56(1)(b) (private vacancy reporting) and r.56(5)(b) (private record-keeping) apply "from such date as may be specified in the notification made by the appropriate Government". Form-XXVI is headed "[See rule 56(1)(d) and 56 (6)] Form EIR (Employment Information Return) Yearly Return to be submitted to the Career Centre (Regional) for the Year ended…".
- **Dates:** in force 08 May 2026. The return covers the position as at 31 March and is **due by 30 April**
  (computed from "within thirty days of the due date, namely, 31st March"). First return under these rules:
  year ended 31 Mar 2026 → 30 Apr 2026, if applicable (computed; transitional application NOT CONFIRMED).
- **Conclusion:** r.56(6) is unconditional on its face ("An employer shall furnish"). For private establishments
  (20+ employees), the record-keeping that feeds the return depends on an appropriate-Government notification, and
  the manner of filing on a State notification.
- **Unresolved:** whether any State has issued the r.56(5)(b)/(6) notifications: NOT CONFIRMED (not researched).

---

## 7. Income-tax Rules, 2026: Form No. 130 and Form No. 138

- **Previous label:** PARTIAL / SECONDARY ("r.218; Form No. 138", text not fetched).
- **New label:** **OFFICIAL_PRIMARY_SOURCE_VERIFIED.**
  - Read from the official gazette PDF held in the repo's evidence store. Its sha256 (f565e0f5…) equals the
    catalogue's record of the file retrieved on 2026-10-01 from
    https://www.incometaxindia.gov.in/documents/81799/11848482/En-Notified-IT-Rules-2026-20-03-2026.pdf/a332bf2a-da14-8b94-dde2-5a2ea1428318?t=1773990110473
  - Today's re-fetch from incometaxindia.gov.in returned HTTP 403.
  - If the caller wants only same-day host reads to count, downgrade to PARTIAL.
- **Instrument:** G.S.R. 198(E), CBDT, "New Delhi, the 20th March, 2026", under s.533 of the Income-tax Act,
  2025 (30 of 2025). r.1(2): "They shall come into force on the 1st April, 2026."

**Form No. 130** is the certificate of tax deducted at source on salary.

> r.215(1) Table, Sl. 1: "Deduction or payment of tax under sections 392 [Other than sub-section (7) thereof] and 393(1) [Table: Sl.No.8 (iii)]" | Form 130 | "By the 15th June of the financial year immediately following the tax year in which the income was paid and tax deducted."
>
> r.215(2): "If an assessee is employed by more than one employer during the year, each of the employers shall issue Part A and B of the certificate in Form No. 130, pertaining to the period for which such assessee was employed with each of the employers and Part C may be issued by each of the employers or the last employer at the option of the assessee."

The certificate is generated and downloaded from the DGIT(S) portal (r.215(1)).

**Form No. 138** is the quarterly statement of TDS for the same sections.

> r.219(1) Table, Sl. 1: "Section 392 [other than section 392(7)] and section 393(1) [Table: Sl. No. 8(iii)]" | Form 138.
>
> r.219(4): quarter ending 30th June → "31st July of the financial year"; 30th September → "31st October of the financial year"; 31st December → "31st January of the financial year"; 31st March → "31st May of the financial year immediately following the tax year in which the deduction or collection, as the case may be, is required to be made."

- **Computed dates:**
  - Q2 of tax year 2026-27 is due 31 Oct 2026 (four days from today).
  - The first Form 130 (tax year 2026-27) is due 15 Jun 2027.
- **Conclusion:** Form 130 is the annual salary TDS certificate, due 15 June after the tax year. Form 138 is the
  quarterly salary TDS statement, due 31 Jul, 31 Oct, 31 Jan and 31 May.
- **Unresolved:** whether certificates for FY 2025-26 (due 15 Jun 2026) were Form 16 under the 1962 Rules.
  Secondary sources conflict, and the Income-tax Act 2025 savings provision was not read. LEGAL_REVIEW_REQUIRED,
  historical only.

---

## 8. PoSH annual report (SH Act 2013 s.21–22; SH Rules 2013 r.14)

- **Previous label:** not in the register (UNVERIFIED).
- **New label:**
  - Who files, to whom, content and period: OFFICIAL_PRIMARY_SOURCE_VERIFIED.
  - **Deadline: NOT CONFIRMED centrally.** Neither the Act nor the central Rules prescribes a date.
- **Sources:**
  - dl_39 (Act), https://wcd.gov.in/documents/uploaded/1710219823.pdf
  - dl_38 (Rules), https://wcd.gov.in/documents/uploaded/1719914401_2NUqe91gql.pdf
- **Instrument:** Act 14 of 2013, ss.21, 22, 29(2)(nz); Rules G.S.R. 769(E), "New Delhi, the 9th December, 2013",
  F. No. 19-5/2013-WW, r.14. Commencement of the Act: NOT CONFIRMED (not read).
- **Extracts:**
  > s.21: "(1) The Internal Committee or the Local Committee, as the case may be, shall in each calendar year prepare, in such form and at such time as may be prescribed, an annual report and submit the same to the employer and the District Officer. (2) The District Officer shall forward a brief report on the annual reports received under sub-section (1) to the State Government."
  >
  > s.22: "The employer shall include in its report the number of cases filed, if any, and their disposal under this Act in the annual report of his organisation or where no such report is required to be prepared, intimate such number of cases, if any, to the District Officer."
  >
  > r.14: "Preparation of annual report.- The annual report which the Complaints Committee shall prepare under Section 21, shall have the following details:- (a) number of complaints of sexual harassment received in the year; (b) number of complaints disposed off during the year; (c) number of cases pending for more than ninety days; (d) number of workshops or awareness programme against sexual harassment carried out; (e) nature of action taken by the employer or District Officer."
- **Conclusion:**
  - The **Internal Committee** (Local Committee for others) prepares the report per **calendar year** and submits
    it to the **employer and the District Officer**. The employer separately reports case numbers in its own
    annual report, or to the District Officer.
  - s.29(2)(nz) empowers rules on "the form and time", but r.14 prescribes content only.
  - Any date (for example 31 January) is state- or district-set and is **not** a central requirement.
- **Unresolved:** state or district deadlines (not researched).

---

## 9. Holiday notice forms (national and festival holidays)

- **Previous label:** not in the register.
- **New label:**
  - Central position: OFFICIAL_PRIMARY_SOURCE_VERIFIED (absence, within the central rules read).
  - State NFH Acts: SECONDARY_SOURCE_ONLY (identified only).
- **Central:**
  - The OSH (Central) Rules 2026 (dl_12, signed, labour.gov.in) prescribe a **weekly holiday** notice and a
    compensatory-holiday notice, but no national or festival holiday form.
    > r.67(1): "The employer shall post a notice at conspicuous places of workplace of every establishment, showing the weekly holiday including digital means like electronic notice boards." r.68(2): the employer displays, "on or before the end of the month in which holidays are lost, a notice in respect of workers allowed compensatory holidays during the following month".
  - r.66 gives working journalists and sales promotion employees "ten holidays in a calendar year".
  - The Wages (Central) Rules mention holidays only in the context of wages (dl_2 line 1982).
  - The words "national holiday" and "festival" do not appear in the OSH (Central) Rules text (searched).
  - **No central NFH form exists under the Codes in the rules read.**
- **State NFH Acts identified (leads, not verified):**
  - Karnataka Industrial Establishments (National and Festival Holidays) Act, 1963 (Act 24 of 1963) and Rules 1964.
  - Tamil Nadu Industrial Establishments (National and Festival Holidays) Act, 1958 (Act 33 of 1958; PRS copy exists).
  - Kerala Industrial Establishments (National and Festival Holidays) Act, 1958 (Act 47 of 1958; India Code copy exists).
  - Andhra Pradesh Factories and Establishments (National, Festival and Other Holidays) Act and Rules, 1974.
  - Punjab and others: UNVERIFIED (not surfaced in this pass).
- **Unresolved:**
  - The state forms, which carry the employer's annual holiday-list notice.
  - Whether these state Acts survive the OSH Code (s.143 repeal list not checked).

---

## 10. Side findings (not asked, recorded for the register)

- **PGA s.2(a) multi-state limb.** It was previously "from prior knowledge, not re-verified". It is now
  OFFICIAL_PRIMARY_SOURCE_VERIFIED (dl_14):
  > "(a) 'appropriate Government' means,— (i) in relation to an establishment— (a) belonging to, or under the control of, the Central Government, (b) having branches in more than one State, …"
- **OSH (Central) Rules 2026, G.S.R. 345(E).** A signed copy (signature valid) is on labour.gov.in (dl_12). It can
  replace the Simpliance-sourced copy in the register.
- **EPF wage ceiling.**
  - S.O. 2702(E), 29-05-2026: ₹15,000 for Chapter III (dl_5, signed).
  - Superseded by S.O. 5109(E), 17-09-2026: ₹25,000 "from the date of publication" (dl_3, signed). It is the same
    notification the register cites (a different labour.gov.in upload).

---

## 11. Access failures

| URL | Error | Fallback |
|---|---|---|
| https://egazette.gov.in/ and https://egazette.gov.in/WriteReadData/2026/275747.pdf | curl (60) "unable to get local issuer certificate". WebFetch: "unable to verify the first certificate". The server sends only its leaf (CN=egazette.gov.in, issuer Let's Encrypt YR1). The chain does validate to ISRG Root X1 once the AIA intermediates (YR1, Root YR cross-signed by X1) are added. Using a CA bundle with those intermediates was refused by the session's permission system, so TLS was not altered. | labour.gov.in copies; DPE O.M. |
| https://www.egazette.gov.in/ | Certificate hostname mismatch | — |
| https://www.labour.gov.in/whats-new | WebFetch HTTP 403. The site is a client-rendered Next.js app, so listing pages carry no data for curl. Querying its back-end API was refused by the permission system and not pursued. | Direct `/static/uploads/` PDFs found by search |
| https://labour.gov.in/sites/default/files/184299.pdf (S.O. 1420(E)) | HTTP 404 | DPE O.M.; TN mirror |
| https://labour.gov.in/sites/default/files/gratuity_2.pdf | HTTP 404 | — |
| https://www.indiacode.nic.in/repealedfileopen?rfilename=A1972-39.pdf | HTTP 504 | labour.gov.in PGA copy |
| https://www.indiacode.nic.in/bitstream/123456789/20365/1/a1972-39_(1).pdf | HTTP 504 | same |
| https://www.indiacode.nic.in/handle/123456789/1703?locale=en | WebFetch HTTP 403 | — |
| https://upload.indiacode.nic.in/showfile?…sexual_harassment_at_workplace_rules_sw.pdf | No response within 110 s | wcd.gov.in copy |
| https://wblc.gov.in/sites/default/files/upload/content_act_rule_pdf/2018_Gratuity.pdf | No response within 110 s | — |
| https://lc.wb.gov.in/sites/default/files/upload/content_act_rule_pdf/2018_Gratuity.pdf | TLS hostname mismatch (certificate is for myelino.com), in both curl and WebFetch | — |
| https://main.sci.gov.in/supremecourt/2022/5126/5126_2022_34_6_34351_Order_21-Mar-2022.pdf | DNS: could not resolve host | — |
| https://clc.gov.in/clc/min-wages | HTTP 404 (site restructured) | https://clc.gov.in/?page_id=144 |
| https://clc.gov.in/wp-content/uploads/2026/10/April-2006.pdf (first attempt, dl_31) | Killed by my 110 s timeout; partial file left in dl_31 | Re-downloaded complete to dl_32 |
| https://www.incometaxindia.gov.in/ (home), /documents/d/guest/fn-138 (curl), /documents/d/guest/form-138-faqs (WebFetch) | HTTP 403 | Repo-held official copy with hash match |

## 12. Secondary sources used only as leads

- LiveLaw, gratuity continuous-service analysis (case list in §1b(ii)).
- LiveLaw and Verdictum on *Tata Steel v. State of Jharkhand* (10% interest).
- Khaitan Ergo 04-09-2026, LKS, Mondaq, SCC Online, TeamLease RegTech (bonus S.O.s; states "expected" to notify).
- TaxHeal mirror of S.O. 4711(E).
- ascent-hr.com (Gujarat SS Amendment Rules 2026).
- Shriram Life, ClearTax, India Briefing, TaxGuru (Form 130 transition).
- greythr, casemine, legitquest, PRS (state NFH Acts).
- hellobanker.in (bank ₹25 lakh).
