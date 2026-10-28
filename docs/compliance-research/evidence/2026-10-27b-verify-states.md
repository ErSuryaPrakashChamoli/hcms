> Copied into the repository's evidence folder on 2026-10-27. References to `dl_<n>`, `ocr_*`, `../../state-rules/` and similar folders are to a temporary working folder that is not kept; each source URL is given in full below.

# State Labour Code rules and shops-and-establishments: verification pass 2026-10-27b

Prepared 2026-10-27. This pass follows up `docs/compliance-research/evidence/2026-10-27-verify-state-code-rules.md`
("the previous pass") and the S&E column of `state-wise-compliance-matrix.md`. Nothing in the repository was edited.

**Layout of this file**
- Part 1: open items on state rules under the four Codes (all checked in this pass).
- Part 2: shops and establishments. A per-state summary table comes first. The full per-item evidence follows as
  Appendices A to C, copied from the three group files (`se-group-A.md`, `se-group-B.md`, `se-group-C.md`) in this folder.
- Part 3: answers to the coordinator's follow-up question (catalogue S&E entries that cite no source).
- Part 4: consolidated access failures.

**Labels**, used exactly as in the source register:
- OFFICIAL_PRIMARY_SOURCE_VERIFIED (OPV): instrument text on a `*.gov.in` / `*.nic.in` host, or a gazette PDF whose
  government signature validates.
- OFFICIAL_SOURCE_PARTIALLY_VERIFIED (OSPV): a gazette print on a non-government host (PRS, Simpliance, SGCMS, LKS), an
  official listing or press note rather than the instrument, or a draft read when the final was not.
- SECONDARY_SOURCE_ONLY (SSO), CONFLICTING_SOURCES (CS), LEGAL_REVIEW_REQUIRED (LRR), UNVERIFIED (UNV).

**Re-labelling caveat.** The previous pass and the matrix counted gazette prints on Simpliance, SGCMS and LKS as
"Primary". Under the labels above those are OSPV. Where this pass re-reads such an item, the strict label is given.
Items not re-read keep their old label, and the old "Primary" there should be read as "Primary under the old convention".

**Downloads and scripts**
- This pass (Part 1 and Part 3 checks): `dl_1` to `dl_18`, OCR output and page images in `ocr_13`, `ocr_17`, `ocr_18`,
  `ocr_18b` and `ocr_18c`. The assembly script is `scripts/p_assemble.py`.
- The group forks: `dl_100`–`dl_126` (A), `dl_200`–`dl_227` (B), `dl_300`–`dl_335` (C). Their scripts are in `scripts/`
  (`a_*`, `b_*`, `c_*`).
- Texts already downloaded in the previous pass (`../../state-rules/dl_*`) were re-read where noted. They were not
  re-downloaded.
- TLS was never bypassed.

---

## Part 1 — State rules under the Labour Codes: open items

### P1-1 Meghalaya (all four Codes): any final rules?

- **Fact:**
  - No final rules were found. The 2026 gazette list on megpns.gov.in still ends at 25-08-2026: 160 entries, and only two
    issues for August.
  - The Labour Commissioner's "Labour Codes" page lists only the four Codes and the four central rule sets.
  - **New:** the Labour Department now fixes minimum wages under the Code on Wages, not the MW Act 1948.
    - Notification No.LE&SD.9/2023/Pt/33 (listed as dated 8 October 2026) revises minimum wages plus VDA with effect
      from 01-10-2026, "in exercise of the powers conferred under Sections of the Code on Wages, 2019". The section
      numbers are lost in the OCR.
    - The previous revision, No.LE&SD.9/2023/Pt/26 of 1 May 2026, was read in the previous pass as made under the MW Act
      1948.
- **Label:**
  - Rules status: LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** (unchanged).
  - October 2026 wage notification: **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (meglc.gov.in; read by OCR).
- **Sources:**
  - https://megpns.gov.in/gazette/gazette_2026.html (`dl_7/`)
  - https://meglc.gov.in/labour_codes.html
  - https://meglc.gov.in/notification.html
  - https://meglc.gov.in/notification/w.e.f%201st%20October,%202026.pdf (`dl_17/`, OCR in `ocr_17/`)
- **Instrument:** No.LE&SD.39/2024/128 of 21-11-2025 for all four rule sets, printed in Gazette Pt IIA on 22-01, 29-01,
  05-02 and 12-02-2026 (previous pass). Wage notification No.LE&SD.9/2023/Pt/33.
- **Dates:**
  - Rules: no final publication found, so commencement is NOT CONFIRMED.
  - Wage notification: dated October 2026 (the department listing says 8 October); effective 01-10-2026.
- **Verbatim (OCR):**
  - "In exercise of the powers conferred under Sections of the Code on Wages, 2019, the Governor of Meghalaya is pleased
    to revise the rates of Minimum Wages, inclusive of Variable Dearness Allowance (VDA)".
  - "The above rates of minimum wages are effective from 1st October, 2026."
  - Rates as printed: Unskilled ₹525 + ₹39 = ₹564; Semi-skilled ₹565 + ₹43 = ₹608; Skilled ₹605 + ₹45 = ₹650; Highly
    skilled ₹645 + ₹48 = ₹693. The VDA figures are an OCR reading, so check them against the PDF before use.
- **Conclusion:**
  - Meghalaya now operates the Code on Wages for minimum wages. That is consistent with the Code being in force since
    21-11-2025.
  - It does not settle whether the "DRAFT RULES" printings of 2026 are final rules.
  - Keep ML at LRR. If the rules are treated as final, they use the central CoW forms I / IV / IX / V.
- **Unresolved:**
  - Is there any final-publication notice for No.LE&SD.39/2024/128?
  - Does the department treat the CoW rules as in force?
  - The megpns.gov.in list has no issues after 25-08-2026.

### P1-2 Mizoram

#### P1-2a Social Security: final rules found (not yet commenced)

- **Fact:**
  - The Mizoram Code on Social Security Rules, 2026 are final.
  - They were notified 29-07-2026 and published in Mizoram Gazette Extraordinary Issue No. 639 of 30-07-2026.
  - They supersede the SS Rules 2023 "except as respects things done or omitted".
  - **Commencement is deferred** to a date the State Government appoints by notification. No such notification was
    found.
  - Employer items:
    - Register of women employees: Form XXI, electronic or hard copy (r.27(1)(a)).
    - Chapter V records kept 2 years (r.27(3)).
    - Unified annual return: Form XXII, "on or before the 1st day of February in each year" (r.27(4)(a)).
  - Drafting errors in the text:
    - The heading of Form XXII cites "rule 27(3)(a)".
    - r.27(4)(c) refers to a "further unified return in Form XVIII referred to in clause (a)".
- **Label:**
  - Final rules exist: LEGAL_REVIEW_REQUIRED → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (gazette text on lesde.mizoram.gov.in).
  - In force: **UNVERIFIED** (appointed date not found).
- **Source:** https://lesde.mizoram.gov.in/uploads/qms/308a6a0df19abd3f542e5e1c6674e727/the-mizoram-code-on-social-security-rules-2026.pdf
  - Listed on https://lesde.mizoram.gov.in/qms as "Notified on 29th Jul, 2026".
  - Saved in `dl_2/` and `dl_1/`. The PDF is unsigned.
- **Instrument:** Notification No. B.14015/6/2019-LESDE, Aizawl, 29-07-2026. Gazette Ex-639/2026, Vol. LV.
  - Pre-published as Issue No. 411, notification of 11-05-2026, made public 18-05-2026.
  - Council of Ministers decision: Memo No. J.11011/2/2026-POL of 24-07-2026.
  - Signed by Dr. H. Lianzela, Secretary, LESDE Department.
- **Dates:**
  - Notification 29-07-2026.
  - Publication Thursday 30-07-2026.
  - Commencement: on an appointed date, **NOT CONFIRMED**.
- **Verbatim:**
  - r.1(3): "They shall come into force on such date as the State Government may, by notification in the Official Gazette
    appoint."
  - r.27(4)(a): "on or before the 1st day of February in each year, upload a unified annual return in Form-XXII
    electronically or manually".
  - Preamble: "in supersession of the Mizoram Code on Social Security Rules, 2023 except as respects things done or
    omitted to be done before such supersession".
- **Conclusion:**
  - Change the MZ SS matrix cell from "LRR" to "FINAL 30-07-2026, commencement on an appointed date (not found)".
  - Until the appointed date, neither the 2023 nor the 2026 rules are shown to be in force, so the old Act-era rules
    continue in practice.
  - Do not switch PeopleOS to Form XXI or XXII until commencement is confirmed.
- **Unresolved:** the appointed-date notification. The Mizoram e-gazette is behind a login.

#### P1-2b Industrial Relations

- **Fact:**
  - No final 2026 IR text was found. The LESDE site lists only the pre-publication of 11-05-2026
    (No. B.14015/8/2022-LESDE).
  - The LESDE "Labour Act/Rules" page lists only the SS and OSH finals.
  - The Assembly List of Business of 27-08-2026 laid "The Industrial Relations (Mizoram) Rules, 2026", together with the
    CoW, SS and OSH rules. The previous pass found this.
  - Since the SS rules laid the same day are confirmed final (P1-2a), a final IR notification is probable but not seen.
  - The 2023 IR Rules (Ex-304 of 07-06-2023) also have an appointed-date commencement that was never found.
- **Label:** LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** (unchanged).
- **Sources:**
  - https://lesde.mizoram.gov.in/post/pre-published-the-industrial-relations-mizoram-rules-2026 (`dl_4/`)
  - https://mizoramassembly.in/storage/list-of-businesses/August2026/BIZ-01M0WKPSBS3SW6N9HTSFNB139W.pdf (previous pass)
  - Web search: only drafts are indexed (TeamLease 57181, Simpliance 9732, lexplosion).
- **Dates:**
  - Draft 11-05-2026.
  - Laid 27-08-2026.
  - Final notification and gazette: **NOT CONFIRMED**. If it follows the SS pattern, commencement may also be deferred
    (NOT CONFIRMED).
- **Verbatim:** List of Business item 5(iv): "The Industrial Relations (Mizoram) Rules, 2026".
- **Conclusion:** Keep LRR, and treat the old rules as continuing.
- **Unresolved:** final notification number, gazette issue and commencement clause.

#### P1-2c Code on Wages: register forms

- **Fact:**
  - The final text was not found. The LESDE post "Code on Wages Rules" holds only the draft (Ex-339) and its notification.
  - A web search found only draft-stage pages.
  - The final reported by KSMG (B.14015/5/2019-LESDE, 27-07-2026, Ex-640 of 30-07-2026) fits the SS gazette numbering:
    SS is Ex-639 of the same day. It is still unseen.
- **Label:** OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged). Forms: **NOT
  CONFIRMED**.
- **Sources:**
  - https://lesde.mizoram.gov.in/post/code-on-wages-rules (`dl_4/`)
  - Draft PDF: /uploads/attachments/2026/06/17d7d29a76d3e90247a9f5bea2439d86/posts-237-ex-339-lesde-the-draft-mizoram-code-on-wages-rules-2026.pdf
- **Conclusion:** Keep the draft pattern only as a guess (I wages, IV employee, V slip). Do not configure Mizoram CoW
  forms yet.
- **Unresolved:** the final CoW text and its commencement clause, which may be deferred like SS.

### P1-3 Uttar Pradesh

#### P1-3a IR rules: gazette date

- **Fact:**
  - Still not found. The signed Hindi notification No.1178/36-2-2026-1394213 of 16-10-2026 (Simpliance copy, re-read
    from the previous pass's `dl_A2/up_ir.pdf`) has no gazette header.
  - TeamLease reports the number and the 16-10-2026 date, and itself says the Gazette should be checked.
  - The UP gazette site was not reachable in the previous pass and was not retried.
- **Label:** OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged).
- **Sources:**
  - https://simpliance.in/India/LEI/govt_notification/uttar-pradesh/final-notification-of-the-uttar-pradesh-industrial-relation-rules-2026-10429
  - https://teamleaseregtech.com/updates/article/60520/uttar-pradesh-industrial-relations-rules-2026 (secondary)
- **Dates:**
  - Signed 16-10-2026.
  - Gazette publication: **NOT CONFIRMED**.
  - Commencement: on gazette publication, so **NOT CONFIRMED**.
- **Unresolved:** UP Extraordinary Gazette issue (Kram) and date.

#### P1-3b Wages rules: muster-roll form

- **Fact:**
  - r.40 lists three registers:
    - "Form-I": wages, overtime, fines and deductions.
    - "Form-II": "as per the provisions of sub-section (1) of Section 50 of the Code".
    - "Form-XII": nomination.
  - Records are kept 5 years.
  - Form II is headed "[See sub-rule (2) Rule 40] EMPLOYEE REGISTER". As printed, r.40 has clauses (i) to (v), not
    sub-rules.
  - **Form XIII is headed "[See Sub-section (1) of Section 50 of The Code on Wages, 2019] ATTANDENCE REGISTER-CUM-MUSTER
    ROLL REGISTER".** It cites the Code section directly, and no rule cites it.
  - Form IX is the wage slip (r.42, "on or before payment of wages").
- **Label:**
  - Text: **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (gazette print on Simpliance; the old convention called this Primary).
  - Legal point: LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** (confirmed).
- **Source:** https://simpliance.in/India/LEI/govt_notification/uttar-pradesh/final-notification-of-the-uttar-pradesh-code-on-wages-rules-2026-10193
  (previous pass's `dl_A2/up_wages.pdf`, UP Extra Gazette of 12-08-2026, page 73 for Form XIII).
- **Instrument:** No.1377/XXXVI-03-2026-1901341, 12-08-2026.
- **Dates:** published and in force 12-08-2026.
- **Verbatim:**
  - r.40: "(ii) as per the provisions of sub-section (1) of Section 50 of the Code in Form-II;"
  - Form XIII: "[See Sub-section (1) of Section 50 of The Code on Wages, 2019] ATTANDENCE REGISTER-CUM-MUSTER ROLL
    REGISTER".
- **Conclusion:**
  - Form XIII is the state's format for the attendance-cum-muster register under s.50(1). The safe course is to keep it
    alongside Forms I and II.
  - Whether it is mandatory, given that r.40 does not list it, needs legal review.
- **Unresolved:** any UP corrigendum adding Form XIII to r.40.

### P1-4 Jammu and Kashmir: IR and OSH gazette dates

- **Fact:**
  - The law.jk.gov.in SRO/SO list was re-read. It lists:
    - S.O.179, 23.06.2026, "J&K Industrial Relations Rules, 2026"
    - S.O.224, 12.08.2026, "Occupational Safety, Health and Working Conditions (J&K) Rules, 2026"
    - S.O.176 (CoW) and S.O.177 (SS), both 19.06.2026, still the drafts
  - No later Code-rule entry appears.
  - The list gives the order date, not the gazette date. The signed OSH order has no gazette header either.
- **Label:** OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged, for both).
- **Source:** https://law.jk.gov.in/LnJNotSRO_SO.php (`dl_10/`). Orders: https://law.jk.gov.in/download.php?id=6134 (IR)
  and https://law.jk.gov.in/download.php?id=6472 (OSH).
- **Dates:**
  - IR: order 23-06-2026; gazette **NOT CONFIRMED** (TeamLease says 02-07-2026, secondary).
  - OSH: order 12-08-2026; gazette **NOT CONFIRMED**. Both come into force on gazette publication.
- **Verbatim (list rows):**
  - "179 | 23.06.2026 | J&K Industrial Relations Rules, 2026."
  - "224 | 12.08.2026 | Occupational Safety, Health and Working Conditions (J&K) Rules, 2026."
- **Conclusion:** Use the order dates as provisional effective dates, flagged.
- **Unresolved:** gazette dates. The J&K e-gazette was not located in this pass.

### P1-5 Gujarat: Wages and IR final rules read on the official host

- **Fact:**
  - The Labour Commissioner's "Labour Code" page hosts all four Gujarat rule sets, uploaded 24-06-2026: CoW 2021, IR
    2021, SS 2023 plus the SS Amendment 2026, and OSH 2025.
  - The CoW and IR copies are the **signed notifications** (CoW as a scan; IR as a typeset PDF made 06-10-2021). They
    are not the gazette prints.
  - CoW rules:
    - r.1(3) commencement "from the date of commencement of the code on wages, 2019".
    - r.42(1) fines and r.42(2) deductions go in Form I.
    - **r.42(3): "a register under sub-section (1) of section 50 in Form I and Form IV".** Form IV is "EMPLOYEE REGISTER".
    - **r.43: the wage slip in Form V is issued "within five working days of making payment of wages".** This resolves
      the previous "timing NOT CONFIRMED". It was checked on the page image at 300 dpi.
    - They supersede the PW Procedure Rules 1937, Gujarat PW Rules 1963 (and the two related 1964/1967 rules), Gujarat MW
      Rules 1961, and the Gujarat Ease of Compliance Rules 2017 "to the extent … section 26 of the Payment of Wages Act".
  - IR rules:
    - r.1(2) apply "in respect to the industrial establishments and matters for which the Gujarat Government is the
      appropriate Government".
    - r.1(3) commence "from the commencement of the Industrial Relations Code, 2020".
    - They supersede the Gujarat TU Regulations 1963, the Bombay IE (Standing Orders) Rules 1959 and the ID (Gujarat)
      Rules 1966.
- **Label:**
  - CoW: OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (instrument text on col.gujarat.gov.in).
  - IR: SECONDARY_SOURCE_ONLY → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (same host).
  - Gazette print and publication date: still **NOT CONFIRMED** for both.
- **Sources:**
  - Index: https://col.gujarat.gov.in/4-Labour-Code.htm (`dl_12/`)
  - CoW: https://col.gujarat.gov.in/Portal/Document/3_3122_1_Gujaratrules_1_24062026.pdf (`dl_13/`; page image `ocr_13/p-12.png`)
  - IR: https://col.gujarat.gov.in/Portal/Document/3_3123_1_Gujaratrules_2_24062026.pdf (`dl_14/`)
  - Also listed: SS 2023 `3_3125_1_Gujaratrules_3_1_24062026.pdf`, SS Amendment 2026 `3_3126_1_Gujaratrules_4_24062026.pdf`,
    OSH 2025 `3_3124_1_Gujaratrules_3_24062026.pdf` (see P1-9).
- **Instruments:**
  - CoW: No. KHR/2021/128/LVD/10/2020/555709/M(2), 05-10-2021. The draft was KHR/2021/29/LVD/10/2020/555709/M(2) of
    31-03-2021 (Gazette Extra Pt I-L, pp. 3/1–3/28).
  - IR: No. KHR-2021-129-IDA-122020-539471-M(2), 05-10-2021. The draft was KHR-2021-30-IDA-122020-539471-M(2) of
    31-03-2021 (pp. 2/1–2/90).
  - Both PDFs are unsigned.
- **Dates:**
  - Notification 05-10-2021 for both.
  - Gazette publication: **NOT CONFIRMED**. The CoW forwarding note asks the Government Central Press to publish "in the
    Extra-Ordinary Part 1-L"; its date is illegible.
  - Commencement and effective date: 21-11-2025, with the Codes.
- **Verbatim:**
  - CoW r.42(3): "Every employer of an establishment to which the Code applies shall maintain a register under
    sub-section (1) of section 50 in Form I and Form IV, electronically or otherwise."
  - CoW r.43: "Every employer shall issue wage slips, electronically or otherwise to the employees in Form V under
    sub-section (3) of section 50 within five working days of making payment of wages."
  - IR r.1(3): "They shall come into the force from the commencement of the Industrial Relations Code, 2020."
- **Conclusion:**
  - Upgrade both cells.
  - Add the wage-slip timing to the GJ variant: within 5 working days after payment. The central rule is different.
  - The same department page still lists the ID (Gujarat) Rules 1966 and the BIR Act under "Acts & Rules". That is a
    legacy listing and does not affect the supersession.
- **Unresolved:**
  - The gazette issue and date for both.
  - Any 2026 amendments to the CoW or IR rules (none listed; 20-05-2026 CoW notifications per the previous pass).

### P1-6 Uttarakhand: annual return under the Wages rules

- **Fact:**
  - r.53 sends the CoW return into "the relevant columns of the Form specified for such purpose in the rules made under
    the" OSH Code, with a copy to the Labour Bureau.
  - The proviso: no separate annual return if one is filed under the OSH Code.
  - The Uttarakhand OSH rules are **still draft**.
    - The labour.uk.gov.in "Acts & Rules" list shows the CoW and IR finals (25-08-2026) and only "Code on OSH Proposed
      Rules" (and SS "Proposed Rules").
    - The "Circulars/Notifications" list, latest entry 17-10-2026, shows no OSH final.
  - So no annual-return form currently exists under r.53.
- **Label:** LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** (confirmed). The CoW text stays OFFICIAL_PRIMARY_SOURCE_VERIFIED.
- **Sources:**
  - CoW: https://cdnbbsr.s3waas.gov.in/s36547884cea64550284728eb26b0947ef/uploads/2026/09/202609241754770724.pdf
    (OCR page `../../state-rules/dl_A2/ukw_img/s085.txt`)
  - Listings: https://labour.uk.gov.in/document-category/acts-rules/ and
    https://labour.uk.gov.in/document-category/circulars-notifications/ (`dl_5/`)
- **Instrument:** No.1115/VIII-1/2026-60(Labour)/2019, Sarkari Gazette Extra Kram 55 of 25-08-2026. In force on
  publication.
- **Verbatim (OCR):**
  - r.53: "The return under these rules shall be filed electronically by every employer of an establishment to which the
    Code applies in the relevant columns of the Form specified for such purpose in the rules made under the Occupational,
    Safety Health and Working Conditions Code, 2020 (37 of 2020). A copy of such return shall also be forwarded
    electronically to the Labour Bureau".
  - Proviso: "in case of filing of annual return under the Occupational Safety, Health and Working Conditions Code, 2020,
    there is no need to file separate annual return under any other labour codes."
- **Conclusion:**
  - PeopleOS should show the UK CoW return as "form not yet prescribed (UK OSH rules draft)".
  - Do not generate a form until the UK OSH rules are final.
- **Unresolved:** whether the old MW and PW return duties continue for Uttarakhand meanwhile. r.55 repeals the old wage
  rules with savings, so this needs legal review.

### P1-7 Ladakh: SS and OSH commencement

- **Fact:** no new evidence. The previous pass's texts were re-read.
  - SS r.1(3): "They shall come into force after the date of their final publication in the Official Gazette". The
    gazette is dated 03-08-2026, but the official e-gazette ID is SG-LD-E-10082026-1786. So it is the day after
    03-08-2026 or after 10-08-2026.
  - OSH r.1(c): "They shall come into force on and with effect from the date on which Code shall be enforced by Central
    Government by notification". That is retroactive on its face, to 21-11-2025. The gazette is dated 02-09-2026.
- **Label:** LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** (both unchanged). The texts stay
  OFFICIAL_PRIMARY_SOURCE_VERIFIED (egazette.ladakh.gov.in).
- **Sources:** https://egazette.ladakh.gov.in/WriteReadData/2026/1786.pdf (SS) and
  https://egazette.ladakh.gov.in/WriteReadData/2026/1819.pdf (OSH). Re-read from `../../state-rules/dl_B/la_ss.txt` and
  `la_osh.txt`.
- **Dates:**
  - SS: notification S.O.224 of 20-07-2026; gazette 03-08-2026 (upload ID 10-08-2026); commencement 04-08-2026 or
    11-08-2026 (**LRR**).
  - OSH: S.O.238 of 30-07-2026; gazette 02-09-2026; commencement 21-11-2025 on its face, otherwise 02-09-2026 (**LRR**).
- **Conclusion (suggested treatment):**
  - SS: effective 11-08-2026 at the latest. Use 04-08-2026 only if the 03-08-2026 print date is accepted as the date of
    publication.
  - OSH: effective 02-09-2026. A retroactive start cannot impose register duties for past periods.
- **Unresolved:** an e-gazette upload log or corrigendum.

### P1-8 Sikkim: effective date of the Wages rules

- **Fact:**
  - r.1(3) of the Sikkim Code on Wages Rules, 2022 says "It shall come into force on the date of their final publication
    in the Official Gazette": Gazette Extra No.168 of 28-04-2022.
  - The Code commenced only on 21-11-2025.
  - General Clauses Act s.22 deals with rules made after a Central Act is passed but before it commences.
    - Such rules may be made, but they "shall not take effect till the commencement of the Act".
    - The s.22 text was read only on bare-act sites; India Code timed out.
  - On that basis the rules could not take effect before 21-11-2025.
- **Label:** LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** (unchanged).
  - Rule text: OFFICIAL_PRIMARY_SOURCE_VERIFIED (sikkim.gov.in, previous pass; OCR).
  - GCA s.22 text: SECONDARY_SOURCE_ONLY.
- **Sources:**
  - https://www.sikkim.gov.in/uploads/Gazette/168_20240718.pdf (`../../state-rules/dl_D/sk/sk_wages_ocr.txt`)
  - GCA s.22: https://www.advocatekhoj.com/library/bareacts/generalclauses/22.php (secondary)
  - Official PDF that timed out: https://www.indiacode.nic.in/bitstream/123456789/2328/1/A1897-10.pdf
- **Instrument:** No.20/DL, 20-04-2022. Gazette Extra No.168 of 28-04-2022.
- **Dates:**
  - Publication and stated commencement 28-04-2022.
  - Earliest legally effective date: **21-11-2025** (legal-review view).
  - The rules were not re-notified (NOT CONFIRMED either way).
- **Verbatim (OCR):** r.1(3): "It shall come into force on the date of their final publication in the Official Gazette."
- **Conclusion:**
  - Effective-date the SK CoW variant from 21-11-2025.
  - The same reasoning supports the 21-11-2025 date already used for the GJ (2021/2023/2025) and AR (2021/2022) rules,
    which tie commencement to the Code expressly.
- **Unresolved:**
  - Whether any part of s.67 had commenced before April 2022 (not checked).
  - Whether Sikkim has re-notified or amended the rules since 2025.

### P1-9 Annual-return due dates where the rule and the form disagree (GJ, AR, JK, MZ)

In all four, the rule says **1 February**, while the instructions printed on the form say **28/29 February**. The rule
is the operative provision, and the form's instruction is subordinate to it. Recommended product treatment: due 1
February, with the form instruction shown as a known conflict. The label for each is CONFLICTING_SOURCES within the
instrument, and legal review is required.

| State | Rule (verbatim) | Form instruction (verbatim) | Where read | Label (text) |
|---|---|---|---|---|
| MZ OSH 2026 | r.34: "so as to reach him not later than 1st February following the end of each calendar year" | Form XIX: "This return is to be filled-up and furnished on or before 28th or 29th February every year." | **Re-read on the official host:** lesde.mizoram.gov.in, Gazette Ex-674 of 13-08-2026 (`dl_9/`) | OSPV (Simpliance print) → **OPV**; due date LRR / CS |
| JK OSH 2026 | r.25: "1st February following the end of each calendar year" | Form IX: "on or before 28th or 29th February" | law.jk.gov.in signed order (`../../state-rules/dl_B/jk_6472.txt`) | OSPV (gazette not seen); due date LRR / CS |
| GJ OSH 2025 | r.37: "in duplicate in Form 23 to the Inspector-cum-facilitator having jurisdiction so as to reach him not later than 1st February following the end of each calendar year with a copy to Director General, Labour Bureau and … DGFASLI" | Form 23: "This return is to be filled-up and furnished on or before 28th or 29th February every year." | **Re-read on the official host:** col.gujarat.gov.in signed-notification scan (`dl_18/`). r.37 is on scan page 52 (image checked, `ocr_18b/p52top-052.png`). The Form 23 instruction is on page 123 (OCR, `ocr_18c/p-123.txt`). It matches the LKS print | OSPV (LKS print) → **OPV** (official host; OCR); due date LRR / CS |
| AR OSH 2022 | r.21(2): "not later than 1 February following the end of each calendar year" | Form IX: "on or before 28 or 29 February every year" | LKS gazette print (`../../state-rules/dl_A1/ar/arunachal-osh-rules-2022.txt`) | OSPV (LKS print); due date LRR / CS |

- The new MZ SS return (Form XXII, r.27(4)(a)) is due "on or before the 1st day of February", and Form XXII carries no
  conflicting date.
- The AR SS return (Form XXXIV) is due "on or before the last day of February in each year" (AR SS Rules 2021 r.79(3)(a),
  LKS gazette print, Arunachal Pradesh Extraordinary Gazette of 23-12-2021). It is a separate return with its own date,
  not a conflict.
- GJ OSH official copy: https://col.gujarat.gov.in/Portal/Document/3_3124_1_Gujaratrules_3_24062026.pdf. It is an
  image-only scan ("HP Scan", 150 pages, unsigned) of the signed notification, not the gazette print.
  - Pages 1–52 and 112–123 were OCR'd. The download first timed out at 240 s and succeeded on a retry with a longer limit.
  - On this basis the GJ OSH text label moves from OSPV (LKS print) to **OPV** for r.37 and Form 23.
  - The other GJ OSH forms (13 / 14 / 17 / 19 / 22) were not re-read on the official copy.
- **GJ OSH notification number: CONFLICTING_SOURCES (minor).**
  - The department's signed notification (page 1 of the official scan, `ocr_18b/top-001.png`) reads "No.
    GHR/2025/76/FAC/142021/68396/M3", dated 06-06-2025. It cites the draft as "No.GHR/2021/154/FAC/142021/68396/M3" of
    30-12-2021, in Gazette Extra Part IV A of 31-12-2021, pp. 174-1 to 174-74.
  - The LKS gazette print reads "GR/2025/76/…". The previous pass's correction "(not 'GHR')" therefore rests on the
    gazette print only.
  - Cite both forms until the gazette issue is read on an official host.

### P1-10 New FINAL rules in the large states since the previous pass

- **Fact:** No new final rules were found for MH, KA, TN, TG, KL, WB, DL, HR, PB, MP, OD, CG, JH, AS, HP or GA. Checks
  in this pass:
  - **MH:** labour.maharashtra.gov.in "New Labour Code" page. Still "Last Updated: 01/Sep/2026"; drafts and central rules
    only.
  - **WB:** labour.wb.gov.in notice-list API. 778 notices, the latest 14-10-2026; the Code items are the four drafts of
    13-07-2026.
  - **Web searches** (two broad queries): no report of a final in any of these states.
  - **UK:** labour.uk.gov.in listings to 17-10-2026. SS and OSH are still "Proposed Rules".
  - **JK:** law.jk.gov.in list. CoW and SS are still the drafts of 19-06-2026.
  - **GJ:** nothing new beyond the four known rule sets.
  - The other states rest on the previous pass's listing checks: hrylabour to 26-10-2026, Delhi to 23-10-2026, Goa
    Official Gazette to 23-10-2026, Assam listing to 15-09-2026, Punjab feed to 24-09-2026, and so on. They were not
    re-fetched.
- **Context (secondary):** Business Standard (24-09-2026) reports the Labour Secretary expecting all states and UTs to
  publish their rules by 31-10-2026, with "ten states" already done. Finals may therefore appear within days of this
  pass.
- **Label:** OFFICIAL_SOURCE_PARTIALLY_VERIFIED. The absence rests on official listings and searches, not on a full
  gazette sweep.
- **Conclusion:** No change to the matrix for these states. **Re-run this check after 31-10-2026.**
- **Unresolved:** MP, CG, JH, HP, TR and AN gazettes remain unreachable or captcha-protected, as before.

### Part 1 side findings (outside the brief, for the central and other passes)

- **Central Wages Rules (G.S.R. 343(E)) on an official host.**
  - meglc.gov.in hosts the Gazette of India copy: CG-DL-E-08052026-272365, Extraordinary No. 309, 08-05-2026. The
    Government of India Press signature is present (signer SARVESH KUMAR SRIVASTAVA) but reports **"Digest Mismatch"**:
    the file was re-saved.
  - URL: https://meglc.gov.in/labour_codes/Code%20on%20Wages%20(Central)%20Rules,%202026.pdf (`dl_8/`).
  - The source register lists G.S.R. 343(E) as "PRS copy, unsigned; no official-domain copy reached".
  - This copy would justify the same "Primary (official domain)" treatment the register gives G.S.R. 344(E).
  - labour.maharashtra.gov.in also hosts `code-on-wages-central-rules-2026.pdf` (not downloaded).
- **Tripura:** labour.tripura.gov.in lists "Wages / IR / SS Rules, 2026" uploaded February 2026. Fork C judged these
  probably the 20-01-2026 drafts; they were not opened.

---

## Part 2 — Shops and establishments: summary by state

Full per-item evidence (fact, label change, URL, instrument, dates, verbatim extract, conclusion, unresolved question)
is in Appendices A to C below. "Prev" is the S&E label in `state-wise-compliance-matrix.md`.

| State | Prev | New label(s) | Key official instrument | Catalogue correction |
|---|---|---|---|---|
| **MH** | Partial | Registers and Form R: **OPV**. 20-worker threshold: **LRR** (Ordinance text is OSPV) | 2018 Rules, Gazette Extra Pt I-L No.83, 23-03-2018 (mahakamgar.maharashtra.gov.in). Mah. Ord. VIII of 2025, Gazette 01-10-2025 (mls.org.in) | The replacement Bill LXXXIX of 2025 was not found enacted, so the Ordinance may have lapsed (Art. 213). Do not hard-code the 20 threshold without legal review. "Records kept 3 years" is wrong: r.26(4) is about inspection records |
| **KA** | Partial, Secondary | Amendment Act: **OSPV**. Forms T/U: SSO | Karnataka Act 38 of 2026 (assent 03-09-2026; Gazette 04-09-2026; in force at once), Simpliance print | None (the catalogue note matches) |
| **TN** | Partial, Secondary | Registers U–X: **OPV**. Form ZC by 31 Jan: **OPV**. Commencement 02-07-2024: **OPV**. 10-worker wording: OSPV | SRO A-8/2022 (Gaz. No.13, 30-03-2022); SRO A-23/2025 (Gaz. No.38, 24-09-2025); G.O.Ms.54 (Extra No.216) | None |
| **DL** | Secondary | Form G: **OSPV**. "Every establishment" (no threshold): **OPV**. 2025 renewal omission: SSO | No.15(52)/Lab/2021/4506, Gazette Extra Pt IV No.330, 15-11-2021 | Form G is a register of wages, deductions, overtime and advances, with an attendance sheet |
| **TG** | Partial, Unverified | Integrated register and return: **OPV**. Exemption for no-employee establishments: **OPV**. Forms XXII–XXVII: SSO | G.O.Ms.No.6 of 02-03-2019 (replaces G.O.Ms.23/2016); G.O.Ms.No.11/2009 | Cite G.O.Ms.6/2019. The Form I integrated return covers the financial year and is due **30 April**. Forms II and III are the integrated register |
| **GJ** | Partial | Threshold: **OPV** (signature valid). Registers and return: **OPV** | Gujarat Act 1 of 2026 (assent and gazette 27-02-2026; deemed 16-12-2025); 2020 Rules on col.gujarat.gov.in | The alternative to Form P is the **Gujarat** Ease of Compliance Rules 2017 Forms B and D, not the central rules. Form Q is due within one month after 31 Dec. Rules r.3/r.6 still say "ten" |
| **WB** | Partial, Secondary | Forms W/I/M/J/U/X: SSO. New items: **OPV** | 24×7 permission for 20+ (29-05-2026; Gaz. 08-07-2026, SAR-338); flexi-hours; women's night shift (03-12-2025); deemed renewal (28-12-2018) | No threshold change found |
| **UP** | Secondary | Threshold: **OSPV** (Bill print). Registers: **OSPV / CS** (see Part 3). Form B: as Part 3 | UP Dookan … (Sanshodhan) Vidheyak 2025, UP Extra Gazette 30-12-2025; deemed 19-11-2025 | The Act does not apply below 20. G and H; D wherever deductions are made; E where fines are imposed; kept 6 years. Form B is a close-days notice, copied to the Inspector within 2 days; it is not an end-January filing |
| **HR** | Secondary | Threshold: **OSPV**. Forms C/D/E: **OPV**. Integrated return: SSO | Haryana Act 13 of 2026 (assent 28-01-2026; Gaz. Extra No.20, 05-02-2026; deemed 12-11-2025) | Registers in English or Hindi, signed by the employer and the Inspector, kept **3 years** (not "Punjabi, 2 years") |
| **KL** | Unverified | Return omitted: **OSPV**. Registers: SSO | Gazette Extra No.2644, 10-08-2022 (G.O.(P)78/2022, SRO 790/2022) | Registers: Form A (or B), BB, D, F; notices C and E; visit book |
| **AP** | Secondary | **OSPV** | AP Act 17 of 2025 (assent 15-10-2025; gaz. 21-10-2025), **in force 03-11-2025** (G.O.Ms.No.23) | The amendment has commenced; it is not "awaiting commencement" |
| **PB** | Secondary | Threshold: **OPV**. Forms C/D/E: OSPV | Punjab Act 12 of 2025 (assent 06-08-2025; in force 29-08-2025), in a Gazette of India issue whose signature validates | None for the threshold. Punjabi and 2-year retention: unverified |
| **CH** | Secondary | **OPV** | S.O. 5647(E), 05-12-2025 (Gazette of India Extra No.5459; signature valid) | None (the 5-12-2025 date is confirmed) |
| **HP** | Secondary | Threshold: **OSPV**. Forms: SSO | HP Act No.3 of 2026 (assent 09-02-2026; notification 11-02-2026) | Only the registration statement is limited to 10+ |
| **JK** | Secondary | Commencement: **OPV** | **S.O. 219, 06-08-2026**: the J&K S&E Act 2025 commences on gazette publication (gazette date NOT CONFIRMED) | The 1966 Act is repealed. The 1968 Rules' forms continue under s.35(a). Registration is optional below 20. Electronic records are allowed |
| **LA** | Unverified | **OSPV**. Which Act applies: **CS** | Ladakh Labour Dept disclosures (2022) | The J&K 2025 Act does not extend to Ladakh. The Kargil page says the "1996" Act |
| **JH** | Secondary | SSO (unchanged) | None reachable | None |
| **CG** | Secondary, Unverified | Threshold: **OSPV**. Forms: UNV. Form-14: SSO | CG Act 11 of 2026 (published and in force 08-07-2026) | None (forms stay "not confirmed") |
| **PY** | Secondary | **OPV** | G.O.Ms.06/AIL/Lab./G/2021 (Gaz. Pt I Extra No.292, 30-11-2021); G.O.Ms.14/2022 | The integrated register is optional ("may"). Add yearly online self-certification |
| **AN** | Secondary | Regulation: **OPV**. Commencement: **LRR**. Forms: SSO | Regulation No.10 of 2025 (Gazette of India No.47, 19-11-2025; signature valid); A&N S&E (Amdt) Rules 2026 (No.246/2026, Extra No.247, 12-08-2026) | **No S&E registers below 20 persons.** 10–19 come under the OSH Code (r.12A). Add Form II wage slip and Form VII |
| **DH** | Secondary | Regulation 14 of 2025: **OPV**. Commencement NOT CONFIRMED. Rules 2025 forms: SSO | Regulation 14 of 2025 (Gazette No.51, 19-11-2025) | None ("20+ once it commences" stands) |
| **SK** | Secondary | **OPV** | No.11/LD/2025, 03-12-2025 (Sikkim Gazette Extra No.457, 06-12-2025) | **20 or fewer employees are exempt from the whole Act** (not "certain provisions"); they file Form I within 3 months |
| **MZ** | Secondary | **OSPV**. Threshold (5 vs 10): **LRR** | Ordinance 1 of 2026 (in force 29-06-2026; Gaz. Ex-634, 29-07-2026) | "5+ workers" is uncertain: Bill 64 of 2023 (5→10) passed, but assent is not confirmed |
| **NL** | Secondary | **OSPV** | 1st Amendment Bill 2026 (Gazette Pt V, 13-03-2026; passed 10-03-2026) | Add the 20-person threshold for the Act's duties. Registration applies to all |
| **TR** | Secondary | **OPV** | Tripura Act 11 of 2025 (assent 01-11-2025; gazette and in force 06-11-2025) | None for the threshold. s.16 registration was deleted from 24-05-2021, not 2024 |

---

## Part 3 — Catalogue S&E entries that cite no source: does any source exist?

### AN — Andaman and Nicobar shops registers (`se_wages`, `se_muster`, `se_overtime`, `se_leave`, `se_employees`)

- **Sources that exist:**
  - **Forms: SECONDARY_SOURCE_ONLY.** A&N S&E Rules 2005 r.11, read in a retyped lawrbit compilation (no gazette
    header): https://www.lawrbit.com/wp-content/uploads/2020/08/andama-and-nicobar-islands-shops-and-establishments-acts-and-rules.pdf
    (`dl_335/`).
    - The forms are: wages Form I, wage slip Form II ("at least a day prior to the disbursement of wages"), muster
      Form III, overtime Form IV, leave Form V, employees Form VI, and the food-grain concession Form VII.
    - Records are kept 3 years from the last entry.
  - **Applicability: OFFICIAL_PRIMARY_SOURCE_VERIFIED.**
    - Regulation No.10 of 2025 (Gazette of India No.47, 19-11-2025; signature valid) limits the Regulation to
      establishments with 20 or more persons.
    - The A&N S&E (Amendment) Rules 2026 (No.246/2026, A&N Gazette Extra No.247, 12-08-2026, on labour.and.nic.in) add
      r.12A. Establishments with 10–19 workers come under the OSH Code, with no registration under the Regulation.
  - No official copy of the 2005 Rules was found. labour.and.nic.in has only Forms A, D and E.
- **Recommendation:**
  - Cite lawrbit (SSO) for the forms and Regulation 10/2025 (OPV) for applicability.
  - Gate the registers to 20+ persons. Add Forms II and VII.
  - The Administrator's commencement notification for Regulation 10/2025 was not found (LRR).

### CH — Chandigarh (`se_employees`, `se_wages`, `se_deductions`)

- **Sources that exist:**
  - **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** for Chandigarh: Punjab S&CE Rules 1958 r.5. "a register of employees in
    Form C; a register of wages of employee in Form D; and a register of deduction in Form E."
    - Read in the Haryana Labour Department's consolidated text on hrylabour.gov.in (`dl_206/`):
      https://hrylabour.gov.in/staticdocs/labourActpdfdocs/The_Punjab_Shops_and_Commercial_Establishments_Act_and_Rules.doc
    - That is the original 1958 rule text on an official host, but the Haryana version, not a Chandigarh one.
  - **OFFICIAL_PRIMARY_SOURCE_VERIFIED** for applicability: S.O. 5647(E) of 05-12-2025 extends Punjab Act 12 of 2025 (the
    20-worker threshold) to Chandigarh.
  - Chandigarh's own text of the rules was not reachable. labour.chd.gov.in fails TLS ("dh key too small");
    chandigarh.gov.in fails with "unable to get local issuer certificate".
- **Recommendation:**
  - Cite the hrylabour text (OSPV) for Forms C/D/E and S.O. 5647(E) for the threshold.
  - Language and retention: Haryana's amendments ("English or Hindi", 3 years) do not apply to Chandigarh. The catalogue's
    "English (and Punjabi), 2 years" for Chandigarh is unverified (UNV).

### CG — Chhattisgarh (`se_annual_return`)

- **Sources that exist:**
  - **The duty: OFFICIAL_SOURCE_PARTIALLY_VERIFIED.** CG S&E Act 2017 s.19, from a Simpliance print of the Act: "Every
    employer of a shop or an establishment shall furnish an annual return, in such form and manner (including electronic
    form), to such authority as may be prescribed."
  - **Form-14 and the date: SECONDARY_SOURCE_ONLY.** TeamLease: "upload the annual return online for January to December
    every year in the prescribed Form-14 … within 45 days from the completion of the year".
    https://teamleaseregtech.com/updates/article/20887/
    - 45 days after 31 December is 14 February, which matches the catalogue.
  - The CG S&E Rules 2021 (CG Gazette Extraordinary, 03-02-2022) were not found on any host. The CG labour sites do not
    resolve, and egazette.cg.nic.in timed out.
- **Recommendation:** Cite s.19 (OSPV) and TeamLease (SSO). Keep the form number and due date flagged as secondary.

### UP — Uttar Pradesh (`se_attendance_wages`, `se_leave`, `se_deductions_fines`, `se_close_days`)

- **Sources that exist (new in this pass):**
  - The English translation of the UP Dookan Aur Vanijya Adhishthan Niyamavali, 1963 (Notification No. 2084
    (LL)/XXXVI-B-489(LL)-1962, 01-05-1963), with amendment footnotes, is hosted by the **Uttarakhand** Labour Department.
    - URL: https://cdnbbsr.s3waas.gov.in/s36547884cea64550284728eb26b0947ef/uploads/2025/04/202504141511261401.pdf
      (`dl_6/`; listed on labour.uk.gov.in "Acts & Rules").
    - It is a Word-made consolidation (2016) on a government host, but maintained by another state. It may not carry
      UP's post-2000 amendments.
  - The secondary lawrbit compilation also exists (`dl_204/`).
- **What the official-host text says:**
  - **`se_attendance_wages` and `se_leave`.** r.18(1), substituted by Noti. No. 1036(1)/XXXVI-3-712(S)-80 of 20-08-1994:
    - "(a) Employing up to ten employees shall also maintain a register in respect of attendance, and payment of wages,
      deductions and leave facilities in Form 'CC'."
    - "(b) … exceeding ten but not exceeding twenty-five shall maintain the register of attendance and wages in Form 'G'
      and also maintain a register of leave in Form 'H'."
    - "(c) Employing employees exceeding 25 shall be required to maintain a register of attendance and wages in Form 'G'
      and also maintain a register of leave in Form 'H'."
    - r.18(5): kept "for six years after the expiry of the year to which they relate".
  - **`se_deductions_fines`.**
    - r.12(8): "All such deductions and realizations shall be recorded in a register in Form 'D'".
    - r.13(1): "Every employer shall maintain a register of fines and realization thereof in Form 'E'."
  - **`se_close_days`.** r.8: "(i) The notice specifying close days, shall be [in] Form 'B'. (ii) A copy of every such
    notice shall be sent by the employer to the Inspector concerned within two days of its being first displayed".
    - No end-of-January filing appears.
- **Conflict.** For more than 25 employees, lawrbit's r.18(1)(c) adds "a register of deductions from wages in Form 'D'".
  The official-host consolidation does not. This is **CONFLICTING_SOURCES**. It matters little in practice, because
  r.12(8) requires Form D wherever deductions are made.
- **Labels:**
  - Forms G, H, D, E and B: SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (official host, but another
    state's consolidation, not the UP gazette).
  - D above 25: **CONFLICTING_SOURCES**.
- **Applicability (P2, UP-1):** the Act does not apply below 20 employees from 19-11-2025 (OSPV, Bill print). The Form CC
  tier is therefore moot.
- **Recommendation:**
  - Cite both sources.
  - Gate the registers to 20+ employees: G and H; D wherever deductions are made; E where fines are imposed; 6-year
    retention.
  - Re-word `se_close_days` as the Form B notice with a copy to the Inspector within 2 days of display. It is not an
    annual filing.

---

## Part 4 — Access failures (all passes in this run)

**TLS failures (never bypassed):**
- **egazette.gov.in:** one normal retry by each of forks A and C, then a TLS verification failure (curl exit 60). No
  central gazette was needed in Part 1. The signed central copies came from labour.gov.in, meglc.gov.in or Simpliance.
- **labour.karnataka.gov.in and dpal.karnataka.gov.in:** certificate chain error.
- **egazetteharyana.gov.in:** "unable to get local issuer certificate".
- **lc.kerala.gov.in:** expired certificate.
- **pblabour.gov.in:** "unable to verify the first certificate".
- **punjabassembly.gov.in:** "unsafe legacy renegotiation disabled".
- **labour.chd.gov.in:** "dh key too small". chandigarh.gov.in: "unable to get local issuer certificate".
- **labour.nagaland.gov.in:** "unable to get local issuer certificate".
- **lc.wb.gov.in:** certificate hostname mismatch, plus timeouts.

**Timeouts, gateway errors and DNS failures:**
- **indiacode.nic.in:** timeouts and HTTP 504 throughout (UP Act 26 of 1962, GCA 1897, Puducherry, Jharkhand); WebFetch
  got 403.
- **www.wb.gov.in:** timeout.
- **egazette.cg.nic.in:** timeout. cglabour.nic.in, labour.cg.gov.in and cglabour.cg.gov.in do not resolve.
- **col.gujarat.gov.in:** the 21 MB OSH PDF timed out at 240 s; it succeeded on retry with a longer limit.
- **govtpress.nagaland.gov.in:** 7 of 17 gazette PDFs timed out.

**Login, captcha or JavaScript-only:**
- **Mizoram e-gazette** (egazette.mizoram.gov.in): login-gated. This is why the MZ CoW and IR finals and any SS
  appointed date could not be checked.
- **labour.ap.gov.in, uplabour.gov.in and jharkhand.gov.in/labour:** JavaScript-only, with no document links.
  shramdhan.up.gov.in does not resolve.
- **egazette.ladakh.gov.in:** search is an ASP.NET postback form.
- **compose.kerala.gov.in:** gazette search could not be driven.

**No connection:**
- jklabour.gov.in, labour.jk.gov.in, labour.kerala.gov.in, egazette.kerala.gov.in.
- labour.andaman.gov.in, www.andaman.gov.in, andssw1.and.nic.in. labour.and.nic.in works.
- Jharkhand labour hosts.

**Not found, or not current:**
- **himachal.nic.in and himachal.gov.in:** the S&E Act and Rules PDFs return 404.
- **MH:** MLS English and Marathi copies of Bill 89 of 2025 return 404. The MH 2017 Act is on the official site only in
  Marathi, in a font that cannot be machine-read.
- **TN:** the combined-registers PDF returns 404.
- **megpns.gov.in:** reachable, but the 2026 list stops at 25-08-2026.
- **UP gazette, J&K e-gazette:** not retried or not located.
- **Texts not found on any host:** CG S&E Rules 2021, DNH&DD S&E Rules 2025, Sikkim S&CE Rules 1984, Tripura S&E Rules
  1970.

**Tooling:**
- **pdfsig:** "NSS_Init failed: bad database". Signature integrity was checked, but trust chains were not.

**Search budget:** about 7 web searches in Part 1, about 14 in fork A, 22 in fork B and 17 in fork C.

---

## Appendix A: S&E group A (MH, KA, TN, DL, TG, GJ, WB), from se-group-A.md

### Shops and Establishments — group A (MH, KA, TN, DL, TG, GJ, WB) — verification 2026-10-27b

Scope: registration/applicability threshold, registers prescribed, annual/periodic return. Labels as in the task.
Catalogue claims compared: `catalogue-shops-lines.txt` (in.php lines 318, 264, 443, 178, 458, 202, 509).
Downloads: `dl_100`–`dl_126` in this folder. Scripts: `scripts/a_sigcheck.py`. OCR = tesseract (eng); legacy-font
Marathi text not machine-readable (no Marathi OCR model installed).

---

#### MH — Maharashtra (matrix label before: Partial)

##### MH-1 Registers and annual return under the 2018 Rules
- **Fact:** r.26(1) Muster-Roll cum Wages Register in **Form Q** (not needed if the employer keeps the muster roll-cum-wages
  register under r.27(1) Maharashtra Minimum Wages Rules 1963); r.19 **Leave Book Form O** (free format allowed if
  particulars match); r.20 **Form P** notice of accumulated leave (first quarter of each calendar year); r.14 **Form M**
  notice of hours/rest/weekly holiday (website + notice board); r.16 **Form N** shift schedule; r.13 Form L night-shift
  consent; r.3 Form A registration ("ten or more workers"); r.8 **Form F** intimation ("less than ten workers");
  r.27 **Form R annual return**, uploaded online "within two months for the year ending on 31st December" (= by end-Feb).
  r.26(2) entries "authenticated digitally or manually"; r.26(3) Marathi or English.
- **Label:** Partial → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (bilingual gazette print on a *.gov.in host; English portion read).
- **Source:** https://mahakamgar.maharashtra.gov.in/Site/Upload/PDF/Regulation_of_Employment_Conditions_of_Service_Rules_2018.pdf
  (listed on the Labour Commissioner "Acts & Rules" list, item 31, upload date 21/02/2025).
- **Instrument:** Industries, Energy and Labour Department Notification No. MSA 11/2017/CR 298/Labour 10 — Maharashtra S&E
  (RE&CS) Rules, 2018, Maharashtra Government Gazette Extraordinary Part I-L, Asadharan No. 83 (Vol. 4, Issue 27(6)).
- **Dates:** notification/publication 23-03-2018; commencement clause not seen (NOT CONFIRMED; presumably on publication).
- **Extracts:** "26. Maintenance of registers and records.- (1) The employer shall maintain a Muster-Roll cum Wages Register
  in Form 'Q'"; "27. Annual Return.- Every employer shall online upload the Annual Return in Form 'R' on the website within
  two months for the year ending on 31st December."; "(4) Every employer or manager shall preserve the inspection records of
  the Facilitator for a period of three years".
- **Conclusion / catalogue correction:** Forms Q, O, P, M, N, R and F confirmed. The catalogue note "Records kept 3 years"
  over-reads r.26(4): the 3-year rule in the Rules is for the Facilitator's **inspection records**; a register-retention
  period was not found in the Rules (Act s.29 not read — Act is on the official site only in Marathi legacy-font PDF).
- **Unresolved:** register retention period under the Act; the reported S&E (Amendment) Rules 2025 (12-11-2025, aligning
  Forms H/J/K to 20 workers) — no official copy found (r.3/r.8 on the official site still say "ten"): SECONDARY_SOURCE_ONLY.

##### MH-2 Threshold raised to 20 workers
- **Fact:** Mah. Ord. VIII of 2025 substitutes "twenty" for "ten" in s.1(3)(a),(b), s.6(1) and s.7(1),(2) (and marginal
  note), plus s.12 (9→10 h; 5→6 h; proviso deleted), new s.14 (spread-over 12 h), s.15 (125→144 h). "It shall come into
  force at once." L.A. Bill No. LXXXIX of 2025 (replacement Bill) has the same text, "deemed to have come into force on the
  1st October 2025", and repeals the Ordinance.
- **Label:** Partial → **LEGAL_REVIEW_REQUIRED** (Ordinance text itself: OFFICIAL_SOURCE_PARTIALLY_VERIFIED — gazette print
  on the Legislature Secretariat host mls.org.in, not *.gov.in, unsigned).
- **Sources:** Ordinance (English translation, Gazette Part VIII): https://mls.org.in/ordinance/2025/अध्यादेश%20क्रमांक%20८%20इंग्रजी.pdf
  (listed at https://mls.org.in/adhyadesh.aspx); Bill (Hindi text): https://mls.org.in/assembly-bill/2025/विधानसभा%20संमत/विधानसभा%20वि.क्र.%20८९%20%20-%20हिंदी.pdf;
  bill lists https://mls.org.in/Vidheyake_Assembly-2025-L-C-Bill-introduce.aspx, …-L-A-Bill.aspx,
  https://mls.org.in/Vidheyake_Council-2025-L-C-Bill-pass.aspx, 2026 equivalents.
- **Instrument / dates:** Maharashtra Ordinance No. VIII of 2025, dated 01-10-2025 (Industries, Energy, Labour and Mining
  Department), published Maharashtra Government Gazette Extraordinary Part VIII, Extra No. 81, 01-10-2025; in force
  01-10-2025. Bill LXXXIX of 2025 introduced (listed in the 2025 "introduced" list; 08-12-2025 per PRS — secondary).
- **Extract (Ordinance):** "2. In section 1 … in sub-section (3),— (1) in clause (a), for the word "ten" the word "twenty"
  shall be substituted; (2) in clause (b) … "; "3. In section 6 … in sub-section (1), for the word "ten" the word "twenty"
  shall be substituted."
- **Why legal review:** (a) on the MLS "Assembly-passed 2025" page the Bill 89 entry exists only inside an HTML comment
  (`<!-- … वि.स.वि. क्रं. ८९ … हिंदी … -->`), i.e. not displayed as passed; (b) Bill 89 is absent from the "Assembly
  bills passed by the Council" lists for 2025 (jumps 81→92) and 2026, and from the 2026 Assembly-passed list; (c) the
  2026 Ordinances I–IX (all read) do not re-promulgate it. If the Bill was not passed and assented, Art. 213(2)(a) would end
  the Ordinance six weeks after the Legislature reassembled (winter session from 08-12-2025), restoring the 10-worker
  threshold. Enactment, Act number and assent date: **NOT CONFIRMED**.
- **Conclusion:** catalogue "From 1 Oct 2025 the Act applies to establishments with 20 or more workers" is supported only
  by the Ordinance; keep, but flag LRR and add the Ordinance citation.
- **Unresolved:** was Bill LXXXIX of 2025 passed by both Houses / assented (Maharashtra Gazette Part IV)?

---

#### KA — Karnataka (matrix label before: Partial, Secondary)

##### KA-1 Karnataka S&CE (Amendment) Act, 2026
- **Fact:** new s.3(1)(k) — the Act does not apply to an "Establishment employing ten or more workers, if already registered
  under the Occupational Safety, Health and Working Conditions Code, 2020", proviso: no separate registration for godowns
  within 100 m; s.4(5) substituted: "The registration once issued under section 4 shall be valid until the closure or
  cessation of business of the establishment."; s.4(6) omitted; electronic/digital mode for registration and s.6 notices;
  s.4(3A) 30→7 days. No change to registers/returns found in the text.
- **Label:** Partial → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (gazette print on Simpliance; it carries a DPSP Karnataka
  (Centre for e-Governance) signature, signer P S BASAVARAJU, 04-09-2026, but pdfsig reports "Signature is Invalid" — the
  file was re-saved: the /ByteRange gap falls inside image data; the official sites were unreachable).
- **Source:** https://www.simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvZiB0aGUgS2FybmF0YWthIFNob3BzIGFuZCBDb21tZXJjaWFsIEVzdGFibGlzaG1lbnRzIChBbWVuZG1lbnQpQWN0LCAyMDI2LnBkZg==
- **Instrument:** Karnataka Act No. 38 of 2026; DPAL Secretariat Notification No. DPAL 32 SHASANA 2026; Karnataka Gazette
  Extraordinary Part IVA No. 893.
- **Dates:** assent 03-09-2026; published 04-09-2026; "It shall come into force at once" (04-09-2026).
- **Conclusion:** catalogue note (10+ OSH-registered excluded; registration valid until closure) confirmed.

##### KA-2 Registers and return (Forms T, U, F, H, Q)
- **Label:** Partial/Secondary → **SECONDARY_SOURCE_ONLY** (unchanged in substance; no official text reached).
- **Sources tried:** labour.karnataka.gov.in and dpal.karnataka.gov.in (TLS failure), karmikaspandana.karnataka.gov.in (no
  S&CE rules), erajyapatra (search form only). Secondary: greytHR Form T/U pages; legitquest rules text (older consolidation).
- **Dates:** inserting notification for Forms T/U and the Form U due date (31 January) — NOT CONFIRMED.
- **Unresolved:** official rule numbers for Form T/U; whether the draft S&CE (Amendment) Rules 2025 (registers waived below
  10 employees) were finalised — NOT CONFIRMED.

---

#### TN — Tamil Nadu (matrix label before: Partial, Secondary)

##### TN-1 Registers Forms U, V, W, X
- **Fact:** r.16(1) substituted: "(a) Every employer shall maintain.- (i) a Register of persons employed in Form-U; (ii) a
  Register of Employment in Form-V; (iii) a Register of Wages in Form-W; and (iv) a Register of Leave and Social Security
  Benefits in Form-X. (b) The registers … shall be maintained either electronically or manually." Also r.11(4)–(5) omitted
  and "Forms 'P', Q' and 'R' shall be omitted".
- **Label:** Partial/Secondary → **OFFICIAL_PRIMARY_SOURCE_VERIFIED**.
- **Source:** https://stationeryprinting.tn.gov.in/gazette/2022/13_III_1a.pdf
- **Instrument:** G.O. Ms. No. 23, Labour Welfare and Skill Development (K2), No. SRO A-8/2022 (draft SRO A-4/2021, Gazette
  17-02-2021).
- **Dates:** G.O. 03-03-2022; published TN Government Gazette No. 13, Part III–Sec 1(a), 30-03-2022; commencement clause not
  seen (NOT CONFIRMED; earlier "1-Apr-2022" date is secondary).
- **Conclusion:** catalogue "Forms U, V, W and X (electronic or manual)" confirmed. Old Forms P/Q/R are omitted.

##### TN-2 Combined annual return Form ZC
- **Fact:** new r.16-D: combined annual return under s.47-A, online, "in Form – ZC on or before 31st day of January in each
  year"; Form ZC "Combined annual return for the year …".
- **Label:** Partial → **OFFICIAL_PRIMARY_SOURCE_VERIFIED**.
- **Source:** https://www.stationeryprinting.tn.gov.in/gazette/2025/38_III_1a_2025.pdf
- **Instrument / dates:** G.O. Ms. No. 93, LWSD (K2), 29-08-2025, No. SRO A-23/2025; TN Government Gazette No. 38, Part
  III–Sec 1(a), 24-09-2025 (draft previously published); commencement NOT CONFIRMED (no clause seen).
- **Conclusion:** catalogue "Form ZC combined annual return by 31 January" confirmed.

##### TN-3 Registration threshold (10+ workers) and commencement
- **Fact:** Bill text substitutes s.3 of TN Act 27 of 2018: "the employer of every establishment employing ten or more
  workers shall, within a period of six months from the date of commencement of his business, apply for registration";
  certificate within 24 h or deemed. Commencement notification appoints 02-07-2024 for TN Act 27 of 2018 and TN Act 20 of
  2023. Rules for registration: r.2A Form Y (online, ₹100), r.2B Form Z certificate / Form ZA register, r.2C **Form ZB**
  intimation by establishments existing on commencement, r.6A first-aid box.
- **Labels:** commencement + rules → **OFFICIAL_PRIMARY_SOURCE_VERIFIED**; 10-worker text → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**
  (L.A. Bill No. 16 of 2023 gazette print on PRS; enacted Act text not read).
- **Sources:** https://stationeryprinting.tn.gov.in/extraordinary/2024/216_Ex_II_2_2024.pdf (commencement);
  https://stationeryprinting.tn.gov.in/extraordinary/2024/217_Ex_III_1a_2024.pdf (rules);
  https://prsindia.org/files/bills_acts/bills_states/tamil-nadu/2023/Bill16of2023TamilNadu.pdf (Bill).
- **Instruments / dates:** G.O. Ms. No. 54, LWSD (K2), 02-07-2024, Nos. II(2)/LWSD/520(b-1)/2024 and 520(b-2)/2024, TN Gazette
  Extraordinary No. 216 Part II–Sec 2, 02-07-2024 — Acts in force 02-07-2024. G.O. Ms. No. 55, 02-07-2024, SRO A-13(e)/2024,
  Gazette Extraordinary No. 217 Part III–Sec 1(a), 02-07-2024.
- **Extract:** "the Governor of Tamil Nadu hereby appoints the 02.07.2024, as the date on which the said Act shall come into force."
- **Unresolved:** the Act number of the "(Amendment) Amendment Act, 2023" that carries the 10-worker s.3 (secondary sources
  say TN Act 21 of 2023, gazette 19-05-2023) and its own commencement — NOT CONFIRMED.

---

#### DL — Delhi (matrix label before: Secondary)

##### DL-1 Register Form G (2021 substitution)
- **Fact:** Form G substituted as "REGISTER OF WAGES / DEDUCTIONS / OVERTIME / ADVANCES" (monthly; columns include days
  worked, leave category/availed/rejected/balance, OT hours and wages, advances, fines, other deductions, net pay) with an
  appended "ATTENDANCE REGISTER" (R/L/A/O/H/C codes); Forms H and I deleted; r.3 online registration within 90 days of
  commencement of work; r.4 certificate generated online in Form C; r.6 changes within 30 days; Schedules I and II (fees) deleted.
- **Label:** Secondary → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (Delhi Gazette print by Government of India Press, read from
  Simpliance; no digital signature). The official labour.delhi.gov.in rules page (updated 23-10-2026) still shows the pre-2021
  text (Form G "register of employment and wages", Forms H/I) — outdated, not a conflict of law.
- **Source:** https://simpliance.in/uploads/notifdocs/1333/the-delhi-shops-and-establishments-amendment-rules-2021.pdf;
  official consolidated page https://labour.delhi.gov.in/labour/delhi-shops-establishments-rules-1954-0
- **Instrument:** Labour Department Notification No. 15(52)/Lab/2021/4506 (draft No. 15(52)/Lab/2021/3618-3626 of 24-09-2021);
  Delhi Gazette Extraordinary Part IV, No. 330 (N.C.T.D. No. 247), ID SG-DL-E-16112021-231172.
- **Dates:** notification and publication 15-11-2021; "They shall come into force on the date of their publication in the
  Delhi Gazette" (15-11-2021).
- **Extract:** "7. AMENDMENT OF FORMS: - In the Principal Rules, Form 'G' is substituted as under:- 8. DELETION OF FORMS: -
  In the Principal Rules, the form 'H' and 'I' are deleted."
- **Conclusion:** catalogue "Form G consolidated register" confirmed; describe it as wages/deductions/OT/advances register
  with attendance sheet. r.14 preservation "till the end of the following year" (official page, pre-2021 text) still applies.

##### DL-2 Threshold and annual return
- **Fact:** Act s.5: "the occupier of every establishment shall send to the Chief Inspector a statement …" — no headcount
  threshold. No annual-return provision in the Rules text on the official page.
- **Label:** Secondary → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** for s.5 (labour.delhi.gov.in Act page
  https://labour.delhi.gov.in/labour/delhi-shops-act-1954-0); "no annual return" → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**
  (absence in an outdated consolidation plus the 2021 amendment text).
- **Other official item:** exemption notification F.No.28/Addl.LC/Exemp./S&E Act/2021/… (Schedule-I entries 301–302: women
  under s.14 and all shops under ss.15–16, subject to 9 h/day, 48 h/week, OT at double rate, CCTV etc.), file dated
  17-10-2025 — https://labour.delhi.gov.in/sites/default/files/Labour/circulars-orders/notificationdseexemp_0.pdf (OCR;
  gazette date NOT CONFIRMED). Hours only; no register change.
- **Unresolved:** Delhi S&E (Amendment) Rules, 2025 (F.No.Lab.-L016/1/2025/4617-4623, 09-12-2025, r.5 renewal omitted) — the
  egazette.gov.in copy (WriteReadData/2025/268431.pdf) failed TLS: **SECONDARY_SOURCE_ONLY**.

---

#### TG — Telangana (matrix label before: Partial, Unverified)

##### TG-1 Integrated annual return and integrated register
- **Fact:** G.O.Ms.No.6 supersedes G.O.Ms.No.23 of 24-03-2016; under (inter alia) "section 71 of the Telangana Shops and
  Establishments Act, 1988, r/w rule 29 and rule 33 of the Telangana Shops and Establishments Rules, 1990": "Annual Return in
  Form–I … for the financial year ending 31st March … furnished to the Inspector, on or before 30th April of every succeeding
  year"; "Integrated Register in Form-II and Form-III together … maintained by an employer and furnished to the Inspector on
  demand". Form III heading: "INTEGRATED REGISTER — MUSTER ROLL-CUM-REGISTER OF WAGES / DEDUCTIONS / OVERTIME / ADVANCES".
  An employer who files Form I and keeps Forms II–III is not liable to penalty under the listed Acts/Rules.
- **Label:** Partial → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (G.O. text on labour.telangana.gov.in).
- **Source:** https://labour.telangana.gov.in/content/gos/G.O.Ms.No.6%20%20annual%20integrated%20return.PDF
  (and G.O.Ms.No.23: https://labour.telangana.gov.in/content/gos/GO%20MS%20NO.23%20date.24.03.2016.pdf)
- **Instrument / dates:** G.O.Ms.No.6, LET&F (Labour) Department, dated 02-03-2019; gazette publication date NOT CONFIRMED
  (copy marked to the Commissioner of Printing "for publication in the Official Gazette").
- **Catalogue correction:** cite **G.O.Ms.No.6 of 02-03-2019** (G.O.Ms.No.23/2016 is superseded); the integrated return is
  **Form I, financial year, due 30 April**; integrated registers are Forms II and III.

##### TG-2 Rules registers Forms X–XII, XXII–XXVI and quarterly return Form XXVII
- **Label:** Partial/Unverified → **SECONDARY_SOURCE_ONLY** for the form letters (no official rules text reached; legitquest
  and greytHR only). That rules 29 (registers) and 33 (return) exist is confirmed by G.O.Ms.No.6 (OFFICIAL_PRIMARY).
- **Related official listing:** G.O.Ms.No.4 of 30-01-2016 adapting the AP S&E Rules 1990 to Telangana; G.O.MS.No.54 of
  03-08-2016 (fees; r.26–27) — https://labour.telangana.gov.in/content/gos/2016LETF_MS54.PDF (read; no register change).

##### TG-3 Threshold
- **Fact:** no headcount threshold found; establishments with **no employees** exempt from all provisions: "exempts all the
  shops and establishments in the State where there are no employees as defined under sub-section (8) of section 2 … from all
  the provisions of the Act".
- **Label:** Unverified → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** for the G.O. text; its continued force in Telangana after
  adaptation → LEGAL_REVIEW_REQUIRED (minor).
- **Source:** https://labour.telangana.gov.in/content/gos/Goms.11date.06.01.2009.PDF — G.O.Ms.No.11, LET&F (Lab.II), 06-02-2009
  (AP-era, s.73(4)); AP gazette date NOT CONFIRMED.
- **Also:** Telangana Ordinance No. 8 of 2017 (Telangana Gazette Part IV-B Extraordinary No. 9, 04-09-2017; promulgated
  02-09-2017): s.3(2) proviso — registration granted automatically on upload, no pre-registration inspection (OCR of zip
  images on labour.telangana.gov.in). Replacement Act NOT CONFIRMED. G.O.Rt.No.476 of 13-10-2022 (women night shift, s.23
  exemption, gazette 17-10-2022) read — conditions include "maintain the records and furnish returns as prescribed".

---

#### GJ — Gujarat (matrix label before: Partial)

##### GJ-1 Threshold raised to 20 workers
- **Fact:** s.1(3) "ten" → "twenty" (two places) and s.7 "ten" → "twenty"; s.12 9→10 h, 5→6 h; s.13 women 9 pm–6 am with
  consent and facilities; s.15 9→10 h and 125→144 h; s.7 repeals Guj. Ord. 3 of 2025 with saving. Principal Act s.1(3):
  "The provisions of this Act, except section 7, shall apply to the shops and establishments employing ten [now twenty] or
  more workers; and the provisions of section 7 shall apply to … less than ten [twenty] workers."
- **Label:** Partial → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (official host; Government of Gujarat signature: "Signature is
  Valid", certificate since expired; signed 03-03-2026).
- **Source:** https://col.gujarat.gov.in/Portal/Document/3_3276_1_actrules25092026.pdf (Labour Commissioner Acts & Rules page
  https://col.gujarat.gov.in/e-citizen-act-and-rule.htm); Bill as introduced on NeVA (gov.in):
  https://cms.neva.gov.in/NeVA/GJ/FileStructures/PaperLaid/15/8/AsIntroduced/01%20of2026_AsIn.pdf
- **Instrument:** Gujarat Act No. 1 of 2026 (Gujarat Bill No. 1 of 2026), Gujarat Government Gazette Extraordinary Part IV,
  Extra No. 1, Vol. LXVII.
- **Dates:** assent 27-02-2026; first published 27-02-2026; "deemed to have come into force on the 16th December, 2025".
- **Conclusion:** catalogue "From 16 Dec 2025 the Act applies to establishments with 20 or more workers; smaller ones file an
  intimation" confirmed (cite Gujarat Act No. 1 of 2026).

##### GJ-2 Registers and annual return under the 2020 Rules
- **Fact:** r.16 Identity Card **Form M**; r.17 Leave Book **Form N** ("either manually or electronically"); r.18 **Form O**
  notice by 31 January of workers whose carried-forward leave reached the maximum; r.26(1) Muster-Roll cum Wages Register
  **Form P** "unless any employer or manager maintains a Wages Register in Form-B and Muster Roll in Form-D as prescribed in
  rule 3 of the Schedule to the Ease of Compliance to Maintain Registers under various Labour Laws (Gujarat) Rules, 2017";
  r.26(4) inspection records kept **five years**; r.27 **Form Q** annual return "within one month after completion of the
  calendar year ending on the 31st December … online where online access is available" (= by 31 January).
- **Label:** Partial → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (scan on col.gujarat.gov.in; OCR).
- **Source:** https://col.gujarat.gov.in/Portal/Document/3_1889_1_SERE-Conditions-of-Service-Rules-2020-1.pdf; Act 2019:
  https://col.gujarat.gov.in/images/lc/17-The-guja-shops-and-Act-2019.pdf (Gujarat Act No. 4 of 2019, assent 06-03-2019,
  Gazette Extra Part IV No. 4, 07-03-2019).
- **Instrument / dates:** Labour and Employment Department notification dated 23 June 2020 (OCR "23th June, 202…"); number
  OCR-garbled ("GHR/2020/73/GSE/19/2019/23631/M-3" as best reading — NOT CONFIRMED); draft GHR/2019/117/GSE/13/2019/236319/M-3
  of 30-09-2019, Gazette Extra Part IV-B, 03-10-2019; commencement NOT CONFIRMED.
- **Catalogue correction:** the r.26(1) alternative is the **Gujarat** Ease of Compliance Rules 2017 Forms B and D, not the
  central 2017 rules (prior note said central). Rules r.3 / r.6 still say "ten"; a rules amendment aligning to 20 —
  NOT CONFIRMED.

---

#### WB — West Bengal (matrix label before: Partial, Secondary)

##### WB-1 Registers (Forms W, I, M, J, U, X) and annual return
- **Label:** Partial/Secondary → **SECONDARY_SOURCE_ONLY** (unchanged; the official Act PDF and the Labour Commissionerate
  inspection note could not be fetched — see access failures). Annual return: none found — NOT CONFIRMED.

##### WB-2 Threshold and recent official notifications
- **Fact:** the Labour Department notice list (778 notices, newest 14-10-2026) has **no amendment of the 1963 Act's
  applicability/registration**. Entries titled "Proposed Amendment in Shops & Establishment Act 1963, Recommendation of
  EPDB/BRAP" (ids 714/715) are scans of the same two 29-05-2026 notifications below (OCR). Official notifications read:
  - No. I/775074/2026/Labr-25099/140/2026-LWMW, 29-05-2026, Kolkata Gazette Extraordinary Part I, 08-07-2026,
    WB(Part-I)/2026/SAR-338: registered S&E "which employ 20 or more persons" may open 24×7 for three years, conditions include
    appointment letters to all employees with a copy to the Inspector, daily display of employees on holiday, OT ≤ 144 h a quarter.
  - No. I/775073/2026/Labr-25099/140/2026-LWMW, 29-05-2026: exemption from s.7(2)(i)–(ii) — 8½ h/day, 48 h/week, spread-over
    12 h, OT ≤ 144 h/quarter; "immediate effect".
  - No. I/97/2025/LABR-25099/31/2025-LWMW, 03-12-2025, WB(Part-I)/2025/561: written-order exemption from s.10(b) (women,
    7 pm–6 am).
  - No. Labr/175/IT&EoDB, 28-12-2018, Kolkata Gazette Extraordinary Part I, 31-12-2018 (SAR-736 as printed): "deemed renewal";
    renewal fees dispensed with.
- **Label:** these notifications → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (labour.wb.gov.in). Registration threshold: no
  headcount threshold found — NOT CONFIRMED from Act text.
- **Sources:** https://labour.wb.gov.in/labour_main_api/api/notice-list; PDFs under
  https://labour.wb.gov.in/labour_main_api/storage/CMS/Notice/ (0b6f2ad3-2302-4d68-8828-ce5d38af3a99.pdf,
  a47f61be-db7c-4124-a0d1-506897055f6d.pdf, 96eea607-…pdf, 0f885390-…pdf, I_97_2025_dated_03_12_2025_0.pdf,
  SAR_736_Labour_Department_No._175.pdf).
- **Conclusion:** catalogue WB entry unchanged for forms; consider noting the 24×7 / flexible-hours conditions (appointment
  letter copy to Inspector) for establishments using them. "No return found" stays NOT CONFIRMED.

---

#### Access failures
1. labour.karnataka.gov.in and dpal.karnataka.gov.in — TLS "unable to get local issuer certificate" (curl) / "unable to verify
   the first certificate" (WebFetch). Not bypassed.
2. egazette.gov.in/WriteReadData/2025/268431.pdf (Delhi S&E (Amendment) Rules 2025) — TLS "unable to verify the first
   certificate" on one normal retry (curl and WebFetch). Not bypassed.
3. www.wb.gov.in/acts/act_labour_shops_and_establishments.pdf — no response within 120 s.
4. lc.wb.gov.in — no response (curl); WebFetch: certificate hostname mismatch (cert for myelino.com). Not bypassed.
5. labour.delhi.gov.in/labour/inspectorate — timeout; the site's notification lists render without the S&E items.
6. labour.tn.gov.in/gopdf/The-Tamil-Nadu-Shops-Establishments-Act-1947.pdf (combined registers) — HTTP 404.
7. compfie.aparajitha.com copy of TN 2023 Acts — HTTP 504.
8. mls.org.in English/Marathi passed copies of MH Bill 89 — HTTP 404 (only a hidden Hindi entry exists).
9. mahakamgar.maharashtra.gov.in MH Act 2017 — Marathi only, legacy-font PDF (not machine-readable); no English official copy found.
10. Karnataka Act 38 of 2026 gazette print (Simpliance) — embedded government signature does not validate (file re-saved).
11. Karnataka e-gazette (erajyapatra) — search form only; not queried.

---

## Appendix B: S&E group B (UP, HR, KL, AP, PB, CH, HP, JK, LA, JH), from se-group-B.md

### Shops and Establishments: group B verification (UP, HR, KL, AP, PB, CH, HP, JK, LA, JH)

Pass date: 2026-10-27. Scope: S&E registration threshold, registers prescribed, periodic return. Compared against the catalogue lines in
`catalogue-shops-lines.txt` and the prior notes `se-top15.md` / `se-rest.md`.

Labels as defined in the task: OFFICIAL_PRIMARY_SOURCE_VERIFIED (OPV), OFFICIAL_SOURCE_PARTIALLY_VERIFIED (OSPV),
SECONDARY_SOURCE_ONLY (SSO), CONFLICTING_SOURCES, LEGAL_REVIEW_REQUIRED (LRR), UNVERIFIED.
Downloads are in `dl_200` … `dl_227` next to this file. Every TLS check was left on. `pdfsig` was run on every PDF.

---

#### UP — Uttar Pradesh (previous S&E label: SECONDARY_SOURCE_ONLY)

##### UP-1 Applicability threshold (20 employees)
- **Fact:**
  - The amending Bill inserts s.3(1)(g) into the 1962 Adhiniyam. Section 3 is headed "The provisions of the Act not to apply to certain persons, shops and commercial establishments". The new clause takes shops and commercial establishments employing fewer than 20 employees out of the whole Act, not just out of registration.
  - New s.4-B: where 20 or more employees work, the owner applies online for registration within six months of starting business, and the portal grants registration automatically.
  - New s.2(6)(d): persons engaged through an outsourcing agency count as employees.
  - It is deemed in force from 19-11-2025, the day U.P. Ordinance No. 16 of 2025 was promulgated.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (gazette print on a non-government host; this is the Bill, not the enacted Act).
- **Source:** https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvZiB0aGUgVXR0YXIgUHJhZGVzaCBEb29rYW4gQXVyIFZhbmlqeWEgQWRoaXNodGhhbiAoU2Fuc2hvZGhhbikgQWRoaW5peWFtLCAyMDI1LnBkZg== (Simpliance labels it "Adhiniyam"; the document is the Vidheyak). No signature. Saved in `dl_200/`.
- **Instrument:**
  - UP Dookan Aur Vanijya Adhishthan (Sanshodhan) Vidheyak, 2025, introduced in the Vidhan Sabha on 22-12-2025.
  - Vidhan Sabha notification No. 2930/Vi.Sa./Sansadiya/95(Sa)/2025, 22-12-2025.
  - English translation: UP Sarkar, Sansadiya Karya Anubhag-1, No. 289/XC-S-1–25-36S-2025, 30-12-2025.
  - Both appear in the UP Extraordinary Gazette of 30-12-2025.
  - It replaces U.P. Ordinance No. 16 of 2025, promulgated 19-11-2025.
- **Dates:**
  - Ordinance promulgated 19-11-2025 (Statement of Objects and Reasons).
  - Bill introduced 22-12-2025; Bill published in the gazette 30-12-2025.
  - Deemed commencement 19-11-2025 (s.1(3)).
  - Enacted Act number, assent date and gazette date: **NOT CONFIRMED**. One secondary search summary says "U.P. Act No. 12 of 2026, assented 01-01-2026, published 02-01-2026". It is said to come from an India Code listing, which was unreachable (HTTP 504), so it is not relied on.
- **Verbatim:**
  - s.1(3): "It shall be deemed to have come into force with effect from the 19th day of November, 2025."
  - s.3(1)(g): "(g) shops or commercial establishment employing less than twenty employees."
  - s.4-B(1): "Every owner of a shop or commercial establishment, where twenty or more employees are working, within six months of the commencement of such business, shall submit an application for registration … on the departmental web-portal".
  - SOR: "(U.P. Ordinance no. 16 of 2025) was promulgated by the Governor on 19th November, 2025."
- **Conclusion:**
  - The catalogue's "From 19 Nov 2025 the Act applies to establishments with 20 or more employees" matches the Bill text.
  - Below 20 employees, the Act, including the registers, does not apply at all.
  - New s.28-A(8) requires an appointment letter for every employee.
- **Unresolved:**
  - The enacted Act's number, assent date and gazette publication date.
  - Whether the Bill was passed and assented before the Ordinance lapsed. If it was not, the 19-11-2025 regime rests only on the Ordinance (LRR).

##### UP-2 Registers (Niyamavali, 1963)
- **Fact (r.18 as substituted; r.12(8); r.13):**
  - Up to 10 employees: one register of attendance, wages, deductions and leave in **Form CC**.
  - 11 to 25 employees: **Form G** (register of attendance and wages) and **Form H** (register of leave).
  - More than 25 employees: Forms **G**, **H** and **D** (register of deductions from wages).
  - Fines register in **Form E** (r.13), wherever fines are imposed.
  - Deductions are recorded in Form D (r.12(8)), and advances are entered in Form G (r.12(6)(iv)).
  - Records are preserved for **six years** after the year to which they relate (r.18(5)).
  - Notices must be in Hindi in Devanagari script (r.18(3)).
- **Label:** SECONDARY_SOURCE_ONLY → **SECONDARY_SOURCE_ONLY**. This is a typed compilation, "Last Updated 9th March, 2020", not a gazette print. India Code (official) was unreachable.
- **Source:** https://www.lawrbit.com/wp-content/uploads/2020/08/uttar-pradesh-shops-and-establishments-acts-and-rules.pdf (`dl_204/`).
- **Instrument:** UP Dookan Aur Vanijya Adhishthan Niyamavali, 1963. The date of the r.18 substitution was not shown in the compilation (NOT CONFIRMED).
- **Verbatim (r.18(1)):**
  - "(a) Employing up to ten employees shall also maintain a register in respect of attendance, and payment of wages, deductions and leave facilities in Form 'CC'."
  - "(b) Employing employees exceeding ten but not exceeding twenty-five shall maintain the register of attendance and wages in Form 'G' and also maintain a register of leave in Form 'H'."
  - "(c) Employing employees exceeding 25 shall be required to maintain a register of attendance and wages in Form 'G', a register of leave in Form 'H', a register of deductions from wages in Form 'D'."
  - r.18(5): "preserved for six years after the expiry of the year to which they relate".
- **Conclusion / correction:**
  - The catalogue lists G, H, D and E flat. Instead:
    - D applies only above 25 employees.
    - E applies where fines are imposed.
    - The Form CC tier (up to 10) is moot after the 2025 amendment, because the Act no longer applies below 20.
    - For 20 to 25 employees: G and H. Above 25: G, H and D. Plus E where fines are imposed.
  - Retention is 6 years.
- **Unresolved:**
  - The official text of r.18 and the date of its substitution.
  - Whether the post-2025 rules (online registration form) amended the registers.

##### UP-3 Return
- **Fact:**
  - No annual or periodic return in the Act or the Niyamavali as compiled.
  - Form B (r.8) is the "Notice Specifying Close Days" (weekly close day and public holidays). It is displayed, and a copy goes to the Inspector "within two days of its being first displayed".
  - No "end-January" filing appears in r.8 or Form B. Act s.8(3) only says a change of close day takes effect from 1 January of the following year.
- **Label:** SECONDARY_SOURCE_ONLY → SECONDARY_SOURCE_ONLY.
- **Source:** as UP-2.
- **Verbatim:** r.8 "(i) The notice specifying close days, shall be in Form 'B'. (ii) A copy of every such notice shall be sent by the employer to the Inspector concerned within two days of its being first displayed".
- **Conclusion / correction:** The catalogue's "Form B holidays notice by end-January" is not supported by the rule text read. It should be "Form B close-days notice, copy to the Inspector within 2 days of display; no return".
- **Unresolved:** Whether a Chief Inspector order requires an annual Form B by a date (NOT CONFIRMED).

---

#### HR — Haryana (previous S&E label: SECONDARY_SOURCE_ONLY)

##### HR-1 Applicability threshold (20 workers)
- **Fact:**
  - New s.1(5) of the Haryana S&CE Act, 1958: the Act, except s.13A, applies only to shops and establishments employing 20 or more workers. Below 20, only s.13A applies: an online intimation within one month of starting business, which gives a "Basic Information Performa ID".
  - New s.13: online registration within one month for 20 or more workers.
  - New s.20A: an appointment letter with the employee's photograph. New s.20B: an identity card.
  - Overtime limit: 156 hours a quarter.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**. The enacted Act was read as a gazette print on SGCMS (unsigned). The identical Bill text is on the official assembly host: https://haryanaassembly.gov.in/wp-content/uploads/2025/12/Bill-39-eng.pdf (`dl_205/`, OPV for the Bill).
- **Source (Act):** https://www.sgcms.com/regulatory-updates/wp-content/uploads/2026/02/HaryanaSEAmendment.pdf (`dl_207/`).
- **Instrument:**
  - Haryana Shops and Commercial Establishments (Amendment) Act, 2025, **Haryana Act No. 13 of 2026**.
  - Law and Legislative Department notification **No. Leg.14/2026**, 05-02-2026.
  - Haryana Govt. Gazette Extraordinary **No. 20-2026/Ext.**, 05-02-2026, pp. 85–87.
  - It repeals Haryana Ordinance No. 4 of 2025.
  - Bill No. 39-HLA of 2025 was published in the Gazette Extraordinary of 18-12-2025.
- **Dates:**
  - Assent 28-01-2026; published 05-02-2026.
  - Deemed in force **12-11-2025**.
- **Verbatim:**
  - "received the assent of the Governor of Haryana on the 28th January, 2026"
  - s.1(2): "It shall be deemed to have come into force with effect from the 12th November, 2025."
  - s.1(5): "The provisions of this Act except section 13A shall apply to the shops and establishments employing twenty or more workers and provisions of section 13A of this Act shall apply to the shops and establishments employing less than twenty workers."
- **Conclusion:** The catalogue's "From 12 Nov 2025 … 20 or more workers; smaller ones file an intimation" is correct. Registers apply only at 20 or more workers.
- **Unresolved:** The forms prescribed for the s.13 application, the s.13A intimation and the s.20B ID card (rules "as may be prescribed"): NOT CONFIRMED.

##### HR-2 Registers (Punjab S&CE Rules, 1958 as applicable in Haryana)
- **Fact:**
  - r.5: Form C register of employees, Form D register of wages, Form E register of deductions.
  - **r.6(1): registers are kept in "English or Hindi"**, substituted by Haryana Govt notification GSR-18/PA/15/58/S.34/72 of 28-02-1972.
  - r.6(2): registers are bound and page-numbered.
  - **r.6(3): "Every such register shall be signed by the employer and the Inspector concerned"**, inserted by GSR-14/Adm(1)74 of 19-04-1974.
  - **r.7: registers are preserved for "three" years**, substituted by the Haryana notification of 28-02-1972.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_PRIMARY_SOURCE_VERIFIED**. This is the department's consolidated text on its own host; the date of the consolidation is not stated, so any later amendment is unchecked.
- **Source:** https://hrylabour.gov.in/staticdocs/labourActpdfdocs/The_Punjab_Shops_and_Commercial_Establishments_Act_and_Rules.doc. It is a .docx file, linked from https://hrylabour.gov.in/content/labour_laws (`dl_206/`).
- **Instrument:** Punjab S&CE Rules, 1958 (Notification No. 6089/5544-C-Lab-58, 13-05-1958), with the Haryana amendments footnoted.
- **Verbatim:**
  - "a register of employees in Form C; a register of wages of employee in Form D; and a register of deduction in Form E."
  - "shall be in 2[English or Hindi]"
  - "1[(3) Every such register shall be signed by the employer and the Inspector concerned.]"
  - "preserved by the Employer for a period of 2[three] years"
- **Conclusion / correction:** The catalogue's "Registers bound, in English (and Punjabi), kept 2 years" is **wrong for Haryana**. It should read: bound and page-numbered; English or Hindi; signed by the employer and the Inspector; kept 3 years.
- **Unresolved:** Whether the s.20B ID-card and s.20A appointment-letter formats have been prescribed.

##### HR-3 Return
- **Fact:** A single integrated annual return under all labour laws is filed online on hrylabour.gov.in. The Labour Department order of June 2016 was clarified in January 2019. The due date and form are not confirmed.
- **Label:** SECONDARY_SOURCE_ONLY → SECONDARY_SOURCE_ONLY. Only Simpliance and greytHR summaries were found; the order was not read on the department host.
- **Source:** https://simpliance.in/India/LEI/govt_notification/haryana/online-submission-of-single-annual-return-under-all-labour-laws-in-haryana-465 (not opened).
- **Unresolved:** The order text, its due date, and whether it still covers the S&CE Act after the 2025 amendment.

---

#### KL — Kerala (previous S&E label: UNVERIFIED)

##### KL-1 Return abolished (r.12A and Form H omitted)
- **Fact:**
  - The Kerala S&CE (Amendment) Rules, 2022 omit r.2E, r.2G(3), **r.12A (submission of returns)** and **Form H**.
  - The earlier r.12A, from the compilation, required a quarterly return in Form H for establishments with 10 or more employees, due by the 10th of April, July, October and January.
- **Label:** UNVERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**.
  - The Kerala Gazette PDF is digitally signed. pdfsig reports "Signature is Valid".
  - However, the signer certificate is a personal DSC (CN=VEERAN T, O=PERSONAL) and has expired, so it is not verifiable as a government signature.
  - The file is hosted on a non-government site.
- **Source:** https://alp.consulting/wp-content/uploads/2023/09/Kerala.pdf (`dl_208/`).
- **Instrument:**
  - G.O.(P) No.78/2022/LBR, Labour and Skills (E) Department, 02-08-2022; S.R.O. No.790/2022.
  - Kerala Gazette Extraordinary Vol. XI No. 2644, 10-08-2022.
  - Draft: notification No. LBRD-E3/103/2021/LBR of 15-12-2021, Gazette Extraordinary No. 3711 of 18-12-2021.
- **Dates:** Notified 02-08-2022; published 10-08-2022; "shall come into force at once" (10-08-2022).
- **Verbatim:**
  - "(iii) rule 12A shall be omitted; (iv) Form H shall be omitted."
  - Explanatory note: "the Department of Labour had introduced online registration and renewal of establishment through Labour Commissionerate Automation System."
- **Conclusion:** The catalogue's "None (r.12A omitted 2022)" is confirmed (OSPV).
- **Unresolved:** Re-reading the gazette on compose.kerala.gov.in or lc.kerala.gov.in. lc.kerala.gov.in has an expired TLS certificate.

##### KL-2 Registers (Kerala S&CE Rules, 1961, r.10)
- **Fact:**
  - **Form A**: register of employment. **Form B** may be used instead where opening and closing hours are uniform.
  - **Form BB**: a register "preserved for the period of the whole service of the employee". Its title was not seen.
  - **Form C**: notice of daily hours and rest intervals, which may replace the register. If the notice is used, a record of hours is kept in **Form D**.
  - **Form E**: notice of weekly holiday.
  - **Form F**: register of holidays and leave (s.13).
  - A **visit book** for Inspector remarks.
  - Records are preserved until the end of the next calendar year (Form BB for the whole service).
  - Registers are in English or the language of the majority of employees.
  - r.11 lets the Labour Commissioner approve substitute forms.
- **Label:** UNVERIFIED → **SECONDARY_SOURCE_ONLY**. This is a typed compilation, about 2020, without the forms schedule.
- **Source:** https://elion.co.in/wp-content/uploads/2024/09/kerala-shops-and-commercial-establishments-acts-and-rules.pdf (`dl_211/`).
- **Verbatim:**
  - r.10(1): "Every employer shall maintain a register of employment in Form A provided that where the opening and closing hours are ordinarily uniform, the employer may maintain such register in Form B."
  - r.10(9): "Every employer shall maintain a register in Form F for the holidays and leave granted under section 13".
  - r.10(8): "preserved till the end of the next calender year except the register in Form BB".
- **Conclusion / correction:** Replace the catalogue's "Employment, wages, attendance, leave, overtime (forms not confirmed)" with: Form A (or B) register of employment; Form BB; Form D (if the Form C notice is used); Form F leave and holidays; notices in Forms C and E; visit book. The S&CE Rules prescribe no separate wage register.
- **Threshold:** No headcount threshold was found. Registration (s.5A) applies to every establishment and renews automatically through LCAS. This was not re-read in this pass.
- **Unresolved:** The title and columns of Form BB; the official text of r.10; any post-2022 amendment.

---

#### AP — Andhra Pradesh (previous S&E label: SECONDARY_SOURCE_ONLY)

##### AP-1 The amendment exempting establishments with fewer than 20 workers has **commenced** (03-11-2025)
- **Fact:**
  - The AP S&E (Amendment) Act, 2025 (Act No. 17 of 2025) adds s.73(6). Nothing in the Act applies to establishments engaging fewer than 20 workers, except:
    - registration and renewal;
    - ss.9, 10, 16, 17, 19, 23, 24, 25, 30, 31, 32 and 47;
    - incidental provisions.
  - The full Act applies once 20 or more workers are engaged.
  - G.O.Ms.No.23 appoints **03-11-2025** as the commencement date of all provisions.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (scans of the G.O. and the AP Gazette on Simpliance, unsigned).
- **Source:** https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL0FuZGhyYSBQcmFkZXNoIFNob3BzIGFuZCBFc3RhYmxpc2htZW50cyAoQW1lbmRtZW50KSBBY3QsIDIwMjUucGRm (`dl_212/`). It contains G.O.Ms.No.23 and AP Gazette Part IV-B Extraordinary No. 171.
- **Instrument:**
  - Act No. 17 of 2025, assent 15-10-2025, AP Gazette Pt IV-B Extra No. 171, 21-10-2025.
  - G.O.Ms.No.23, LFB&IMS (Lab.II) Department, 03-11-2025 (file 11400348/2025/I-LBRCOMMR), for publication in the AP Gazette Extraordinary of 03-11-2025.
- **Dates:** Assent 15-10-2025; published 21-10-2025; commencement **03-11-2025**. The gazette issue number of the 03-11-2025 commencement notification is NOT CONFIRMED.
- **Verbatim:**
  - G.O.: "Government of Andhra Pradesh hereby appoints the 3rd November, 2025 as the date on which all the provisions of the said Act shall come into force."
  - s.73(6): "Nothing in this Act, except the provisions related to registration and renewal of shops and establishments and sections 9, 10, 16, 17, 19, 23, 24, 25, 30, 31, 32, 47 and other provisions incidental there to, shall apply to shops and establishments engaging less than 20 workers".
- **Conclusion / correction:** The catalogue's "The 2025 amendment … awaits commencement" is **wrong**. It has been in force since 03-11-2025.
- **Unresolved:**
  - Which section of the 1988 Act imposes registers and records. Section 47 in the scan is termination and service compensation, and the PRS scan (`dl_213/`) is missing the pages for ss.48–75.
  - Whether the register duty falls within the s.73(6) list (LRR), and therefore whether registers apply below 20 workers.

##### AP-2 Registers (AP S&E Rules, 1990) and the Form B combined return
- **Fact (unchanged from the prior pass):**
  - Forms XXII (employment), XXIII (wages), XXV (leave), X–XII (fines, deductions, advances) and XXVI (appointment letter).
  - Form B combined annual return under Act 10 of 2015.
- **Label:** SECONDARY_SOURCE_ONLY → SECONDARY_SOURCE_ONLY. labour.ap.gov.in is a JavaScript application, and no rules PDF was reachable.
- **Unresolved:** The official Rules text, and the due date of the Form B return.

---

#### PB — Punjab (previous S&E label: SECONDARY_SOURCE_ONLY)

##### PB-1 Applicability threshold (20 workers)
- **Fact:**
  - s.1(4) as substituted: the Act applies to shops and establishments employing 20 or more workers. Below 20, only s.13-A applies.
  - s.13-A requires an intimation of business to the Inspector within six months of commencement of the Amendment Act, or of starting business.
  - s.13: registration within six months for 20 or more workers. The certificate is due within 24 hours, otherwise it is deemed granted.
  - Overtime limit: 144 hours a quarter. Spread-over: 12 hours.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_PRIMARY_SOURCE_VERIFIED**. The Punjab Act's text, including the Punjab gazette notification, is reproduced in Gazette of India Extraordinary CG-DL-E-05122025-268276. Its Government of India Press signature validates (pdfsig: "Signature is Valid"; signer O=GOVERNMENT OF INDIA PRESS). The Punjab Gazette print itself was also read on Lawrbit (`dl_214/`, unsigned).
- **Source:**
  - `dl_216/` (see CH-1): https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvbiBleHRlbnNpb24gb2YgdGhlIFB1bmphYiBTaG9wcyBhbmQgQ29tbWVyY2lhbCBFc3RhYmxpc2htZW50cyAoQW1lbmRtZW50KSBBY3QsIDIwMjUsIHRvIHRoZSBVbmlvbiBUZXJyaXRvcnkgb2YgQ2hhbmRpZ2FyaC5wZGY=
  - https://www.lawrbit.com/wp-content/uploads/2025/09/punjab-shops-establishments-act-amendment.pdf
- **Instrument:**
  - Punjab S&CE (Amendment) Act, 2025, **Punjab Act No. 12 of 2025**.
  - Department of Legal and Legislative Affairs notification **No. 13-Leg./2025**, 28-08-2025.
  - Punjab Govt. Gazette (Extra), Legislative Supplement, 29-08-2025, pp. 441–445.
- **Dates:** Assent 06-08-2025; notification 28-08-2025; gazette 29-08-2025; in force on publication (**29-08-2025**).
- **Verbatim:**
  - s.1(2): "It shall come into force on and with effect from the date of its publication in the Official Gazette."
  - s.1(4): "The provisions of this Act shall apply to the shops and establishments employing twenty or more workers, whereas to shops and establishments employing less than twenty workers only section 13-A shall be applicable".
- **Conclusion:** The catalogue's "From 29 Aug 2025 … 20 or more workers; smaller ones file an intimation" is correct.
- **Unresolved:** The s.13-A intimation form and the s.13 application form ("as may be prescribed").

##### PB-2 Registers
- **Fact:** r.5 of the Punjab S&CE Rules, 1958: Form C employees, Form D wages, Form E deductions. These are the original 1958 rule texts, unamended in the Haryana consolidation.
  - Language and retention for Punjab: the Haryana footnotes show that Haryana substituted "English or Hindi" and "three" years in 1972. That is consistent with the catalogue's Punjab text ("English and Punjabi", 2 years), but the Punjab text itself was not read on an official host.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** for Forms C/D/E (the original-rule text read on the official Haryana host). Language and retention for Punjab stay SECONDARY_SOURCE_ONLY.
- **Source:** `dl_206/` (hrylabour.gov.in). pblabour.gov.in failed TLS (see access failures).
- **Unresolved:** Punjab's own amendments to r.6 and r.7; any post-2025 rules.

##### PB-3 Return
- None found; unchanged, SECONDARY_SOURCE_ONLY.

---

#### CH — Chandigarh (previous S&E label: SECONDARY_SOURCE_ONLY)

##### CH-1 Punjab amendment extended to Chandigarh (20 workers)
- **Fact:**
  - MHA extends Punjab Act No. 12 of 2025, "as in force in the State of Punjab on the date of publication", to the UT of Chandigarh under s.87 of the Punjab Reorganisation Act, 1966.
  - Modifications: "State Government" becomes "Union territory of Chandigarh", and "as extended to the Union territory of Chandigarh" is inserted after the Act's name.
  - The 20-worker threshold and s.13-A therefore apply in Chandigarh.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_PRIMARY_SOURCE_VERIFIED**. The Gazette of India PDF has a valid Government of India Press signature: signer CN=SARVESH KUMAR SRIVASTAVA, O=GOVERNMENT OF INDIA PRESS, "Signature is Valid". The issuer is not in the local trust store.
- **Source:** Simpliance copy of the signed gazette, URL in PB-1 (`dl_216/`).
- **Instrument:** **S.O. 5647(E)**, MHA, F.No. U-11036/6/2025-UTL(Part-I), 05-12-2025; Gazette of India Extraordinary Pt II-3(ii) No. 5459, CG-DL-E-05122025-268276.
- **Dates:** Notification and publication 05-12-2025. Effective in Chandigarh from **05-12-2025** (the Act commences on publication, and the extension took effect on publication). The S.O. states no separate date.
- **Verbatim:** "the Central Government hereby extends to the Union territory of Chandigarh, the Punjab Shops and Commercial Establishments (Amendment) Act, 2025 (Punjab Act No. 12 of 2025), as in force in the State of Punjab on the date of publication of this notification, subject to the following modifications".
- **Conclusion:** The catalogue's "From 5 Dec 2025 … 20 or more workers" is correct.
- **Not re-read:** The 18-03-2026 Chandigarh notification exempting OSH-Code-registered establishments from ss.13/13A (Nos. 535694-HII(2)-2026/3140 and /3143) stays OFFICIAL_SOURCE_PARTIALLY_VERIFIED, as read previously via Simpliance.
- **Unresolved:** Registers in Chandigarh follow the Punjab Rules 1958 (C/D/E). Whether the Chandigarh Administration has its own language and retention amendments is NOT CONFIRMED. labour.chd.gov.in failed TLS.

---

#### HP — Himachal Pradesh (previous S&E label: SECONDARY_SOURCE_ONLY)

##### HP-1 Registration statement for 10 or more employees
- **Fact:**
  - s.13(1) now reads "every establishment employing ten or more employees". Only the duty to send the registration statement is limited to 10 or more employees.
  - The rest of the Act, including s.20 records, still applies to all establishments.
  - Overtime limit: 144 hours a quarter (s.7(2)(a)).
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (an authoritative English text with a Hindi notification, read on Simpliance, unsigned).
- **Source:** https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvZiB0aGUgSGltYWNoYWwgUHJhZGVzaCBTaG9wcyBhbmQgQ29tbWVyY2lhbCBFc3RhYmxpc2htZW50cyAoQW1lbmRtZW50KSBBY3QsIDIwMjUucGRm (`dl_217/`).
- **Instrument:**
  - HP Shops and Commercial Establishments (Amendment) Act, 2025, **Act No. 3 of 2026**, from Bill No. 24 of 2025.
  - Vidhi Vibhag notification No. L.L.R.-D(6)-30/2025-Leg., Shimla-2, 11-02-2026. This is OCR of a legacy-font Hindi text.
- **Dates:**
  - Assent 09-02-2026; notification 11-02-2026.
  - The Act has no commencement clause, so commencement depends on the HP General Clauses Act (LRR); probably the date of first publication in the Rajpatra.
  - The Rajpatra issue date is NOT CONFIRMED (the Rajpatra is captcha-gated).
- **Verbatim:** "In section 13 of the principal Act, in sub-section (1), after the words "every establishment", the words "employing ten or more employees" shall be inserted."
- **Conclusion:** The catalogue's "Registration statement for establishments with 10+ employees (2026)" is confirmed (OSPV).

##### HP-2 Registers (HP S&CE Rules, 1972, r.14)
- **Fact (prior):** Form 8 employees, Form 9 wages, Form 10 deductions, Form 11 leave with wages; bound; kept 3 years.
- **Label:** SECONDARY_SOURCE_ONLY → SECONDARY_SOURCE_ONLY. The himachal.nic.in Act and Rules PDFs found by search now return HTTP 404.
- **Unresolved:** The official Rules text.

##### HP-3 Return
- None found; unchanged, SECONDARY_SOURCE_ONLY.

---

#### JK — Jammu and Kashmir (previous S&E label: SECONDARY_SOURCE_ONLY)

##### JK-1 The J&K S&E Act, 2025 has **commenced**; the 1966 Act is repealed
- **Fact:**
  - S.O. 219 appoints the date of its publication in the Official Gazette as the commencement date of the J&K Shops and Establishments (Licensing, Regulation of Employment and Conditions of Service) Act, 2025 (Act No. VI of 2025).
  - Under s.35 of that Act, the J&K S&E Act, 1966 is repealed.
- **Label:** SECONDARY_SOURCE_ONLY → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** for the notification (a signed order on the government host). The gazette publication date is NOT CONFIRMED.
- **Source:** https://law.jk.gov.in/download.php?id=6468 (`dl_220/`), listed at https://law.jk.gov.in/LnJNotSRO_SO.php as "219 | 06.08.2026 | Commencement of Jammu and Kashmir Shops and Establishments Act, 2025. | Labour and Employment Department".
- **Instrument:** S.O. 219, Labour and Employment Department, No. LE-Legl/59/2025-10-L&E (e-7705907), 06-08-2026, signed by Kumar Rajeev Ranjan, Secretary to the Government. The number is from OCR: "LE-Legl/5912025-rO".
- **Dates:**
  - Notification dated 06-08-2026.
  - Commencement = the date of publication in the J&K Official Gazette, which is **NOT CONFIRMED**. The order asks the Government Press to publish in the "next issue".
  - The Act itself: LG's assent and publication in the J&K Official Gazette Extraordinary Vol. 138 No. 32-3, 01-11-2025 (PRS copy, `dl_221/`).
- **Verbatim:** "the Government hereby appoint, the date of publication of this notification in Official Gazette as the day on which the Jammu and Kashmir Shops and Establishments (Licensing, Regulation of Employment and Conditions of Service) Act, 2025 shall come into force."
- **Conclusion / correction:** The catalogue's "The J&K Shops and Establishments Act, 2025 awaits commencement" is **wrong**. The catalogue's act name should move from the 1966 Act and 1968 Rules to the 2025 Act, with the 1968 Rules continued (see JK-2), effective from the gazette date of S.O. 219, on or after 06-08-2026.
- **Unresolved:** The exact gazette date (the e-gazette was not found).

##### JK-2 Threshold, registers and returns under the 2025 Act
- **Fact:**
  - s.1(4): the Act applies to all shops and establishments in J&K.
  - s.5(1): online registration within 60 days, "optional for shops and establishments who have less than twenty employees". 1966-Act registrations are deemed registrations.
  - s.24: "Every employer shall maintain such registers and records as may be prescribed"; "(2) The records may be maintained electronically or manually". A signed hard copy must be produced on demand.
  - Chapter VII is headed "Records and Returns", but it has no return section.
  - s.35 proviso (a): rules and notifications under the 1966 Act are deemed made under the 2025 Act insofar as not inconsistent, until superseded. The 1968 Rules' forms therefore continue:
    - Form L register of employees (attendance, overtime, wages);
    - Form G leave-with-wages register, kept 3 years after the last entry;
    - Form H leave book;
    - Form P service card;
    - notices in Forms M, N and O;
    - records kept 3 years; English or Urdu (r.20, r.11, r.13).
  - The J&K SRO/SO list (to 23-10-2026) shows **no rules under the 2025 Act**.
- **Label:** SECONDARY_SOURCE_ONLY →
  - **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** for the Act provisions (PRS copy of the J&K Official Gazette, unsigned);
  - SECONDARY_SOURCE_ONLY for the 1968 forms (Lawrbit compilation, `dl_223/`);
  - **LEGAL_REVIEW_REQUIRED** for whether r.20(6) ("stamped by the Inspector") survives s.24(2) electronic records.
- **Source:**
  - https://prsindia.org/files/bills_acts/acts_states/jammu-and-kashmir/2025/Act6of2025JK.pdf
  - https://www.lawrbit.com/wp-content/uploads/2020/08/jammu-and-kashmir-shops-and-establishments-acts-and-rules.pdf
  - https://law.jk.gov.in/LnJNotSRO_SO.php
- **Verbatim:**
  - s.5(1) proviso: "Provided that the registration shall be optional for shops and establishments who have less than twenty employees".
  - s.35(a): "every appointment order, rule, bye-law, regulation, notification, registration or notice made, issued or given under the provisions of the Act so repealed shall, in so far as it is not inconsistent with the provisions of this Act, be deemed to have been made … under the provisions of this Act".
- **Conclusion:** Registers apply to all establishments regardless of size. Registration is mandatory only at 20 or more employees. There is no return.
- **Unresolved:** Rules under the 2025 Act; the Form L, G and P layouts on an official host.

---

#### LA — Ladakh (previous S&E label: UNVERIFIED)

##### LA-1 Which S&E law applies
- **Fact:**
  - The UT Labour Department's own disclosures (2022) say it registers establishments "under the shops and Establishment Act". Leh reports "Under Shops & Establishment Act 15449" establishments registered in 2022-23. Kargil names the law "Shops and Establishment Act, 1996".
  - No "1996" J&K S&E Act is known; this is presumably a typo for the J&K Act, 1966.
  - The J&K Act, 2025 extends only to the UT of J&K (s.1(2): "the whole of Union Territory of Jammu and Kashmir"), so it does not apply in Ladakh.
  - No adaptation or repeal order naming the 1966 Act for Ladakh was found.
- **Label:** UNVERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED**: an official department listing shows that an S&E Act is administered. Which Act it is (1966?) is **CONFLICTING_SOURCES** (official "1996" against the secondary "1966").
- **Source:**
  - https://cdnbbsr.s3waas.gov.in/s395192c98732387165bf8e396c0f2dad2/uploads/2022/09/2022091447.pdf (ALC Leh, `dl_225/`)
  - https://cdnbbsr.s3waas.gov.in/s395192c98732387165bf8e396c0f2dad2/uploads/2022/09/2022091487-1.pdf (ALC Kargil, `dl_226/`)
  - Both are linked from https://ladakh.gov.in/labour-department/.
- **Instrument:** The 1966 Act's continuation rests on the J&K Reorganisation Act, 2019 s.95 and the Fifth Schedule. The text was not read: India Code returned 504, and a mirror returned 403.
- **Dates:** NOT CONFIRMED.
- **Verbatim:** Leh: "1 Under Shops & Establishment Act 15449". Kargil: "Shops and Establishment Act, 1996: The Act provides for weekly of hours of work, leave wit wages that is 14 days casual leave and 1 month privilege leave etc."
- **Conclusion:** Keep the catalogue's 1966 Act and 1968 Rules template with the "applicability unconfirmed" note. Registers and returns stay as in J&K 1968 (secondary).
- **Unresolved:** The Fifth Schedule entry or Ladakh adaptation order for the 1966 Act; any Ladakh rules.

---

#### JH — Jharkhand (previous S&E label: SECONDARY_SOURCE_ONLY)

##### JH-1 Registers and returns (Jharkhand S&E Rules, 2001, adapting Bihar 1955)
- **Fact (compilation):**
  - r.42: an annual return for 10 or more persons, by 31 March of the next year. It has no form number.
  - r.43: a quarterly return in **Form XIX** for 10 or more persons, within 30 days of each quarter.
  - r.43(A): where an online return is filed in the government format, only that return is required, "on or before 30st of June every year".
  - r.43(B): the government may notify consolidated forms and computerised or digital registers.
  - Registers: Form IX leave with wages; Form X wages (r.17 and r.19, the compilation is inconsistent); Form XI fines and deductions; Form XXI service card (r.12-A).
- **Label:** SECONDARY_SOURCE_ONLY → SECONDARY_SOURCE_ONLY.
  - Lawrbit compilation (`dl_227/`).
  - India Code (https://www.indiacode.nic.in/bitstream/123456789/7513/1/jharkhand_shopsandestablishment_act.pdf) returned 504.
  - jharkhand.gov.in/labour lists documents only through a JavaScript data table.
- **Verbatim:** r.43(A): "in such cases where on line return is filed in such format as may be prescribed by the Government for the purpose only such online Return shall be required to be filed on or before 30st of June every year."
- **Conclusion:** The catalogue entry is consistent with the compilation. No new official evidence.
- **Unresolved:** The official Rules text and amendment dates (2015 and 2017); whether a consolidated-forms order under r.43(B) exists.

---

#### Catalogue corrections arising (for the parent to apply)

| State | Catalogue now says | Should say (label) |
|---|---|---|
| UP | Registers "Form G …; Form H …; Forms D and E" flat | 20–25 employees: G and H; more than 25: G, H and D; E where fines are imposed; kept 6 years (SSO). The Act does not apply below 20 (OSPV, Bill text) |
| UP | Returns "None (Form B holidays notice by end-January)" | None. Form B close-days notice, copy to the Inspector within 2 days of display (SSO) |
| HR | "Registers bound, in English (and Punjabi), kept 2 years" | Bound and page-numbered; English or Hindi; signed by the employer and the Inspector; kept 3 years (OPV, hrylabour.gov.in) |
| KL | "Employment, wages, attendance, leave, overtime (forms not confirmed)" | Form A (or B) employment; Form BB; Form D hours record (if the Form C notice is used); Form F leave and holidays; notices C and E; visit book (SSO) |
| AP | 2025 amendment "awaits commencement" | In force 03-11-2025 (G.O.Ms.No.23); below 20 workers only registration and the listed sections apply (OSPV) |
| JK | "J&K S&E Act, 2025 awaits commencement" | Commenced on gazette publication of S.O. 219 of 06-08-2026 (OPV for the order; gazette date NOT CONFIRMED). 1966 Act repealed; 1968 Rules' forms continue under s.35(a); registration optional below 20 employees; records may be kept electronically |
| LA | 1966 Act "not confirmed" | The UT Labour Department confirms it administers an S&E Act (OSPV); which Act it is: conflicting ("1996" in the official text) |

#### Access failures

- **www.indiacode.nic.in**: HTTP 504 on every bitstream and handle fetch (UP Act 26 of 1962, Jharkhand Act), retried three times. WebFetch got HTTP 403.
- **egazetteharyana.gov.in**: TLS failure "unable to get local issuer certificate". Not bypassed.
- **lc.kerala.gov.in**: TLS failure "certificate has expired". labour.kerala.gov.in and egazette.kerala.gov.in did not connect. compose.kerala.gov.in was reachable, but its gazette search could not be driven.
- **pblabour.gov.in**: TLS failure "unable to verify the first certificate" (curl and WebFetch).
- **punjabassembly.gov.in**: TLS failure "unsafe legacy renegotiation disabled". Legacy renegotiation was not enabled.
- **labour.chd.gov.in**: TLS failure "dh key too small". chandigarh.gov.in: "unable to get local issuer certificate".
- **labour.ap.gov.in**: a JavaScript application with no static document links.
- **uplabour.gov.in**: reachable, but its GO and latest-updates pages render no document links without JavaScript. shramdhan.up.gov.in does not resolve.
- **himachal.nic.in** and **himachal.gov.in** S&E Act and Rules PDFs: HTTP 404.
- **jklabour.gov.in** and **labour.jk.gov.in**: no connection.
- **taxmanagementindia.com** (Fifth Schedule mirror): HTTP 403.
- **egazette.ladakh.gov.in**: reachable, but its search is an ASP.NET postback form, and the recent-uploads pages showed no S&E items.
- **Jharkhand labour hosts** (shramadhan, labour.jharkhand.gov.in): no connection. jharkhand.gov.in/labour loads documents through JavaScript.

---

## Appendix C: S&E group C (CG, PY, AN, DH, SK, MZ, NL, TR), from se-group-C.md

### Shops and establishments: group C (CG, PY, AN, DH, SK, MZ, NL, TR), 2026-10-27b

Scope: registration or applicability threshold, prescribed registers, and any annual or periodic return. Each fact is checked against the catalogue line in `catalogue-shops-lines.txt` and the prior notes in `se-rest.md`.

The previous S&E label for each state comes from the matrix:
- CG: Secondary, Unverified
- All the others: Secondary

Downloads are in `dl_300` to `dl_335`, OCR output in `ocr_3xx`, and the helper script is `scripts/c_get.sh`.

Label rules applied:
- **OFFICIAL_PRIMARY_SOURCE_VERIFIED (OPV):** the text was read on a `*.gov.in` or `*.nic.in` host, or in a Gazette of India PDF whose Government of India Press signature validates.
  - pdfsig reports "Signature is Valid" for these files, but "Certificate issuer isn't Trusted", because there is no local NSS trust store. Only the cryptographic integrity was checked; the certificate chain was not.
- **OFFICIAL_SOURCE_PARTIALLY_VERIFIED (OSPV):** a gazette print on Simpliance, PRS or elion, or an official listing or press note.
- **SECONDARY_SOURCE_ONLY (SSO):** retyped compilations (lawrbit, elion without a gazette header) and vendor summaries.

---

#### CG — Chhattisgarh

##### CG-1. The Act applies to shops and establishments employing 20 or more workers (from 08-07-2026)
- **Label:** Secondary → **OSPV**
- **Source:** gazette text print on Simpliance: https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL05vdGlmaWNhdGlvbiBvZiB0aGUgQ2hoYXR0aXNnYXJoIFNob3BzIGFuZCBFc3RhYmxpc2htZW50cyAoUmVndWxhdGlvbiBvZiBFbXBsb3ltZW50IGFuZCBDb25kaXRpb25zIG9mIFNlcnZpY2UpIChBbWVuZG1lbnQpIEFjdCwgMjAyNS5wZGY= (dl_300)
- **Instrument:** Chhattisgarh Shops and Establishments (RE&CS) (Amendment) Act, 2025 = **Chhattisgarh Act No. 11 of 2026**; publication notification Kramank 1311/D-85/21-A/Praru./C.G./26.
- **Dates:**
  - Governor's assent 27-12-2025; President's assent 29-06-2026.
  - Published Nava Raipur, 08-07-2026.
  - Commencement: on publication in the Official Gazette, so **08-07-2026**.
  - The Rajpatra (Extraordinary) issue number is not in the print. The secondary figure "No. 367" is NOT CONFIRMED.
- **Verbatim:** "In sub-section (3) of Section 1 of the Chhattisgarh Shops and Establishments … Act, 2017 (No. 21 of 2018) … for the word "ten", the word "twenty" shall be substituted." Also: s.1(3) "It shall come into force from the date of its publication in the Official Gazette."
- **Other changes:**
  - s.6(2): women may work 9 PM to 6 AM, subject to notified conditions.
  - s.8: daily hours nine → ten; interval after five hours → six; spread-over 10½ → 12 hours; overtime 125 → 144 hours.
- **Conclusion:** the catalogue note "From 8 Jul 2026 … 20 or more workers" is confirmed. The TeamLease date of 20-07-2026 is wrong.
- **Unresolved:** a government-host copy (egazette.cg.nic.in or cglabour) could not be reached.

##### CG-2. The 2017 Act came into force on 13-02-2025; the Rules 2021 were gazetted on 03-02-2022
- **Label:** Secondary → **OSPV**
- **Source:** gazette print on Simpliance: https://www.simpliance.in/uploads/notifdocs/2552/chhattisgarh-shops-and-establishments-regulation-of-employment-and-conditions-of-service-act-2017-release-of-effective-date.pdf (dl_334; 29 pages: the notification plus the Act)
- **Instrument:** Notification No. F-10-12/2017/16/434, Nava Raipur, 13-02-2025, under s.1(4).
- **Dates:**
  - The Act was published in the CG Gazette (Extraordinary) on 23-08-2018.
  - The Rules 2021 were published in the CG Gazette (Extraordinary) on 03-02-2022.
  - The Act took effect on 13-02-2025, the date the notification was issued.
- **Verbatim:**
  - "the State Government decides to give effect to the Chhattisgarh Shops and Establishments … Act, 2017 (No. 21 of 2018) from the date of issue of this notification."
  - Act s.30(1): "The Chhattisgarh Shops and Establishments Act, 1958 (No. 25 of 1958) is hereby repealed."
- **Conclusion:** confirmed. The original Act text applied to establishments "employing ten or more workers" (s.1(3)); CG-1 raised this to twenty.

##### CG-3. Registers and annual return
- **Label:**
  - Act duties: Secondary → **OSPV**
  - Form numbers: **UNVERIFIED / NOT CONFIRMED**
  - Form-14 return: **SSO** (unchanged)
- **Sources:**
  - Act text: same print as CG-2.
  - Form-14: TeamLease https://teamleaseregtech.com/updates/article/20887/
- **Verbatim, Act:**
  - s.18(1): "Every employer shall maintain such registers and records as may be prescribed."
  - s.18(2): "The records may be maintained electronically or manually".
  - s.19: "Every employer of a shop or an establishment shall furnish an annual return, in such form and manner (including electronic form), to such authority as may be prescribed."
- **Verbatim, TeamLease (secondary):** "upload the annual return online for January to December every year in the prescribed Form-14 … within 45 days from the completion of the year".
- **Rules amendment of 03-06-2026:** No. RULE-8/65/2026-LABOUR, gazette print on Simpliance, dl_301; **OSPV**.
  - It substitutes r.4 (LIN certificate in Form-2 within 24 hours, online) and r.5 (change in Form-4, fee ₹100).
  - The register of establishments is kept online in Form-3 by the Facilitator.
  - None of these are employer registers.
- **Conclusion:**
  - The catalogue's "As prescribed (forms not confirmed)" stands.
  - "Form-14 annual return by 14 February" rests only on TeamLease. It is consistent with s.19, which requires an annual return.
- **Unresolved:** the Rules 2021 text (CG Gazette Extraordinary, 03-02-2022), its register form numbers and Form-14 were not found on any host.

---

#### PY — Puducherry

##### PY-1. Integrated register (Forms II and III) and consolidated annual return (Form I, by 30 April, online)
- **Label:** Secondary → **OPV**
- **Source:** https://styandptg.py.gov.in/2021/NOVEMBER/EXTRAORDINARYPART-I/292-PART-I%20dated%2030-11-2021.pdf (dl_304; text layer; no digital signature)
- **Instrument:** G.O. Ms. No. 06/AIL/Lab./G/2021, Puducherry, 29-11-2021.
  - Published in The Gazette of Puducherry, Part I, Extraordinary, No. 292, 30-11-2021.
  - The draft was G.O.Ms.No.129/AIL/Lab/G/2020 of 03-12-2020, in Extraordinary Gazette No. 182 of 17-12-2020. No objections were received.
- **Dates:**
  - G.O. 29-11-2021; publication 30-11-2021.
  - No separate commencement clause was seen; the order operates from issue (inference).
  - The return is due by 30 April after each financial year ending 31 March.
- **Verbatim:**
  - Powers: "under section 53 (1) of the Puducherry Shops and Establishments Act, 1964, r/w rule 22 (1) of the Puducherry Shops and Establishments Rules, 1964".
  - "(i) Annual Return in Form-I, for the financial year ending 31st March, may be prepared by an employer and shall be filed only through online. (ii) Integrated Register in Form-II and Form-III together, may be maintained by an employer and shall be filed only through online."
  - Proviso: liable to penalty "if any employer fails to furnish periodical Return for the end of financial year 31st March, on or before 30th April … in Form-I to the Inspector".
  - "FORM – III INTEGRATED REGISTER MUSTR ROLL-CUM-REGISTER OF WAGES / DEDUCTIONS / OVERTIME / ADVANCES".
- **Conclusion:** the catalogue line is confirmed. Two nuances:
  - The integrated register is an option ("may be maintained"). Using it protects the employer from penalties under the ten covered laws.
  - Form I covers the S&E Act together with CLRA, ISMW, PW, ID, MW, MB, Bonus, Gratuity and MTW.

##### PY-2. Registration renewal abolished (auto-renewal by online self-certification)
- **Label:** Secondary → **OPV**
- **Source:** https://styandptg.py.gov.in/2022/NOVEMBER/EXTRAORDINARYPART-II/1/61-PART-II%20dated%2002-11-2022.pdf (dl_305)
- **Instrument:** G.O. Ms. No. 14/AIL/Lab./G/2022, 07-10-2022, Gazette Part II No. 61, 02-11-2022, under s.6(1).
- **Verbatim:** "hereby exempts all Shops and Establishments from the provisions of sub-section (4) of section 7 of the said Act and sub-rule (1) of rule 6 and hereby eliminates the renewal of registration and directs all Shops and Establishments … to pursue auto renewal on submission of Self Certification through online every year along with the fees".
- **Conclusion:** this is an annual online self-certification with the fee. It is not a return, and it is not in the catalogue. Consider adding it as a checklist item.

##### PY-3. Registration threshold
- **Label:** Secondary → **UNVERIFIED** (not re-read)
- **Finding:** none found; the prior notes say the Act applies to all shops in notified areas.
- **Unresolved:** India Code (handle 6108) timed out.
  - One search for a 2025–26 Puducherry amendment found none.
  - The legacy register form numbers under the 1964 Rules were not read.

---

#### AN — Andaman and Nicobar Islands

##### AN-1. The Regulation now applies only where 20 or more persons are employed
- **Label:** Secondary → **OPV** for the text; **LEGAL_REVIEW_REQUIRED** for the commencement date
- **Sources:**
  - Gazette of India Extraordinary Pt II s.1 **No. 47**, 19-11-2025, CG-DL-E-19112025-267836. Copy via Simpliance (dl_312). The Government of India Press signature validates (signed 19-11-2025 23:41:58).
  - Republished in the **A&N Gazette Extraordinary No. 103, 20-11-2025**, Notification No. 97/2025/F.No.M-2530973/1/2025-ALC(SA)-LAB_AN, on the official host https://labour.and.nic.in/webPages/pdf/ShopsEstablishment_ANIAmendment2025.pdf (dl_314; OCR).
- **Instrument:** Andaman and Nicobar Islands Shops and Establishments (Amendment) Regulation, 2025, **No. 10 of 2025** (President, Art. 240).
- **Dates:**
  - Promulgated and published 19-11-2025; republished in the UT on 20-11-2025.
  - Commencement: "on such date as the Administrator may, by notification in the Official Gazette, appoint". **The commencement notification was NOT CONFIRMED.**
  - Nicobar Times (secondary, 04-12-2025) says it "came into effect on November 21, 2025".
  - The 2026 Rules (AN-2) are made "read with Section 8 of the … (Amendment) Regulation, 2025", which suggests the Regulation is in force. That is an inference only.
- **Verbatim:**
  - "1A. This Regulation applies to all establishments in which twenty or more persons are employed or were employed on any day of the preceding twelve months."
  - s.3(1)(d): "three persons" → "twenty persons".
  - s.4(2): apply online.
  - s.4(4): the employer generates the certificate from the portal.
  - s.4(7) is omitted.

##### AN-2. A&N S&E (Amendment) Rules, 2026: lifetime e-registration, and new r.12A
- **Label:** Secondary → **OPV**
- **Source:** https://labour.and.nic.in/webPages/pdf/Andaman_and_Nicobar_Islands_Shops_and_Establishments_(Amendment)_Rules_2026.pdf (dl_314; scanned; OCR in `ocr_314/r26_ocr.txt`)
- **Instrument:** Notification **No. 246/2026/F. No. M-2530973/2/2025-ALC(SA)-LAB_AN**, Sri Vijaya Puram, 12-08-2026, **A&N Gazette Extraordinary No. 247, 12-08-2026**.
  - The draft was in Extraordinary No. 222. OCR reads its date as "5h June, 2026", and secondary sources say 15-06-2026, so the day is NOT CONFIRMED from OCR.
- **Dates:**
  - Commencement: "on the date of their publication in the Official Gazette", so **12-08-2026**.
- **Verbatim (OCR):**
  - r.4: the Form-B certificate is "issued electronically without any requirement of prior approval … shall remain valid for life time of the establishment".
  - "Rule 5 of the Principal Rules shall be omitted."
  - r.8: 24×7 operation; weekly holiday notice in Form E; "Form – D shall be omitted".
  - r.12A(1)(a): the OSH Code "shall apply to every shop or establishment in which ten (10) or more workers are employed".
  - r.12A(2)(a): where an establishment with 20 or more workers "has obtained registration under the Regulation, such registration shall be deemed to be sufficient compliance with the requirement of registration under the Code".
  - r.12A(3): "employing ten (10) or more but less than twenty (20) workers,— (a) The provisions of the Code shall apply; and (b) The provisions relating to registration under the said Regulation shall not apply".
  - r.12A(4)(b): the Regulation "shall continue to apply in respect of matters not expressly provided for or regulated under the Code".
- **Conclusion and catalogue correction:**
  - The note "Establishments with 10–19 workers register under the OSH Code only (2026)" is right, but incomplete.
  - Under AN-1, once commenced, the whole Regulation, including its r.11 registers, applies only to establishments with 20 or more persons. Below 20 there is no S&E register duty.
  - For establishments with 20 or more, the Regulation's registers continue only for matters the Code does not regulate (LRR).

##### AN-3. Registers under r.11 of the Rules 2005
- **Label:** SSO (unchanged). Re-read in a retyped lawrbit compilation with no gazette header.
- **Source:** https://www.lawrbit.com/wp-content/uploads/2020/08/andama-and-nicobar-islands-shops-and-establishments-acts-and-rules.pdf (dl_335)
- **Verbatim:**
  - "(1) A Register of wages shall be maintained … in Form- I"
  - "(2) A wage-slip in Form-II … at least a day prior to the disbursement of wages"
  - "(3) A muster roll … in Form-III"
  - "(4) … register of Overtime in Form IV"
  - "(5) … register of leave in Form- V"
  - "(6) … register of employees in Form -VI"
  - "(7) … computation of cash value of the concessional sale of food grains in Form-VII"
  - "preserved for 3 years from the date of last entry"
- **Return:** none found.
- **Conclusion:** the catalogue forms (I, III, IV, V, VI) match. The catalogue omits the Form II wage slip and the Form VII register.
- **Unresolved:** the Rules 2005 gazette text was not found on an official host; labour.and.nic.in has only Forms A, D and E.

---

#### DH — Dadra and Nagar Haveli and Daman and Diu

##### DH-1. Regulation No. 14 of 2025 raises the threshold from 10 to 20 workers
- **Label:** Secondary → **OPV** for the text; commencement **NOT CONFIRMED**
- **Source:** Gazette of India Extraordinary Pt II s.1 **No. 51**, 19-11-2025, CG-DL-E-19112025-267840.
  - Copy via Simpliance (dl_311). The Government of India Press signature validates (signed 19-11-2025 23:56:32).
  - egazette.gov.in itself failed TLS (see Access failures).
- **Instrument:** DNH and DD Shops and Establishments (RE&CS) Amendment Regulation, 2025, No. 14 of 2025.
- **Dates:**
  - Promulgated and published 19-11-2025.
  - s.1(3): "It shall come into force on such date as the Administrator may, by notification in the Official Gazette, appoint." **The commencement notification was not found.** It is not on ddd.gov.in's labour list, which was read to page 7.
- **Verbatim:**
  - s.2: "in section 1, in sub-section (3), for the words "ten or more workers", the words "twenty or more workers" shall be substituted."
  - s.3 inserts the definition of "State Government" (the Administrator).
  - s.4: daily hours nine → ten.
- **Conclusion:** the catalogue note "10+ workers (20+ once Regulation 14 of 2025 commences)" is confirmed.

##### DH-2. Draft 2026 Amendment Regulation: the Act would apply to all shops
- **Label:** Secondary → **OPV** (draft only)
- **Source:** https://cdnbbsr.s3waas.gov.in/s371e09b16e21f7b6919bbfc43f6a5b2f0/uploads/2026/07/20260716785004937.pdf (ddd.gov.in; dl_309; OCR)
- **Instrument:** Public Notice No. LE/LI/DMN/ReformsAct/427/2026/206, 15-07-2026. It allows 30 days for comments. The draft is "to be promulgated by the President".
- **Verbatim:**
  - "(3) The provisions of this Act shall apply to all shops and establishments".
  - New s.6: registration within 60 days; shops "already registered under the OSH Code, 2020, or under any of the local (Municipal / Panchayat) Acts … shall be deemed to have been registered under this Act".
- **Conclusion:** this is a draft and not in force. Watch for promulgation.

##### DH-3. DNH&DD S&E (RE&CS) Rules, 2025: Form P, Form N and the Form Q annual return by 31 January
- **Label:** SSO (unchanged)
- **Sources:** greytHR and AscentHR. The notification is LE/LI/DMN/SE-R/388/2025/172; its date is given as both 24-06-2025 and 27-06-2025 (CONFLICTING, secondary).
- **Unresolved:** the Rules text was not found on ddd.gov.in. The forms and the 5-year retention period are not verified.

---

#### SK — Sikkim

##### SK-1. Shops and establishments with 20 or fewer employees are exempt from the Act; they file Form I within 3 months
- **Label:** Secondary → **OPV**
- **Sources:**
  - Official: https://www.sikkim.gov.in/uploads/Gazette/457_20260213.pdf (dl_317; scan; OCR). The sikkim.gov.in gazette search lists it as "06 Dec 2025".
  - The Simpliance copy (dl_315) also carries the appended Form I.
- **Instrument:** Notification **No. 11/LD/2025**, 03-12-2025, Department of Labour, issued under **s.5** of the Sikkim Shops and Commercial Establishments Act, 1983 (Act 6 of 1983).
  - Published in the **Sikkim Government Gazette Extraordinary No. 457, Gangtok, 06-12-2025**.
  - File No. GoS/DL/32/ADM/2025-26.
- **Dates:** notification 03-12-2025; publication 06-12-2025. No separate effective date is stated, so it operates from publication (inference).
- **Verbatim (OCR):** "the State Government is hereby pleased to exempt all the shops and commercial establishments employing 20 or less employees/workers from the applicability of the provisions contained in the Act. 2. All the exempted establishments shall intimate the details of their establishment to the Labour Department in FORM I appended with this notification within 3 (three) months from the date of commencement and comply with all existing statutory obligations."
- **Catalogue correction:**
  - The catalogue says "exempt from certain provisions". The notification exempts these establishments from the provisions of the Act as a whole.
  - Establishments with 20 or fewer employees therefore have no S&E registers. Their only duty is the Form I intimation within 3 months of starting business.
  - Whether "comply with all existing statutory obligations" keeps any S&E duty alive is LEGAL_REVIEW_REQUIRED.

##### SK-2. Registers under r.15 of the Rules 1984
- **Label:** SSO (unchanged; not re-read)
- **Finding:** Forms I / K employment, N leave, O leave book; records kept 2 years.
- **Unresolved:**
  - Neither the gazette search ("Shops", "Commercial Establishments Rules", "1984") nor the official Act PDF yielded the Rules 1984.
  - The official Act text (Gazette No. 35 of 07-03-1983: https://www.sikkim.gov.in/uploads/Gazette/1983_THE_SIKKIM_SHOPS_AND_COMMERCIAL_ESTABLISHMENTS_BILL_20221031.pdf) was downloaded (dl_317) but not OCR'd for its register sections.
  - Amendment Act No. 19 of 2022 is in Gazette No. 364 of 12-09-2022 (listed on sikkim.gov.in).
  - No 2026 S&E instrument was found in the gazette search.

---

#### MZ — Mizoram

##### MZ-1. 2026 Ordinance: electronic registration, registration valid until cancelled, and deemed registration for OSH-Code-registered establishments
- **Label:** Secondary → **OSPV**
- **Source:** gazette print on PRS: https://prsindia.org/files/bills_acts/bills_states/mizoram/2026/Ord1of2026MZ.pdf (dl_322)
- **Instrument:** Mizoram S&E (RE&CS) (Amendment) Ordinance, 2026 (**Ordinance No. 1 of 2026**).
  - Published under Notification No. H.12018/214/2010-LJD, 27-07-2026.
  - **Mizoram Gazette Extraordinary, Vol. LV, Issue No. 634, 29-07-2026.**
- **Dates:**
  - Promulgated 29-06-2026.
  - "It shall come into force at once", that is, from promulgation on 29-06-2026.
  - Publication 29-07-2026.
  - Replacement Act: NOT CONFIRMED. The ordinance lapses unless replaced.
- **Verbatim:** "5A. Deemed Registration.- (1) … where any shop or establishment has obtained registration under the Occupational Safety, Health and Working Conditions Code, 2020 … such registration shall, for the purposes of this Act, be deemed to be valid registration under this Act."
  - s.5(6): the certificate is "valid until it is cancelled or surrendered".
- **Conclusion:** the catalogue note is confirmed.

##### MZ-2. Threshold of 5 or more workers, with an unresolved 2023 amendment to 10
- **Label:** Secondary → **LEGAL_REVIEW_REQUIRED**
- **Sources:**
  - PRS print of the 2023 Bill: https://PRSINDIA.org/files/bills_acts/bills_states/mizoram/2023/Billno64of2023MZ.pdf (dl_324)
  - Mizoram Assembly government-bill listing (official listing; host mizoramassembly.in): https://www.mizoramassembly.in/government-bill?page=3 (dl_320)
- **Verbatim:**
  - Existing s.1(2): "it shall apply to the Shops and Establishments employing 5 (five) or more workers".
  - Proposed: "employing 10 (ten) or more workers".
  - The bill's commencement clause: "on such date as the State Government may, by notification in the Official Gazette appoint".
  - Assembly listing: Bill **64 of 2023**, introduced and passed **22-08-2023**. The "Date of Assent" and "Mizoram Act No. & Year" fields are blank.
- **Conclusion:** the catalogue's "5+ workers" holds only if the 2023 Act was never assented to or never commenced. Assent and commencement are NOT CONFIRMED.

##### MZ-3. 2026 Amendment Bill on hours, women's night work and compounding (no threshold change)
- **Label:** new → **OSPV** (official Assembly listing and bill text)
- **Source:** https://mizoramassembly.in/storage/government-bill-attachments/GB-01KJ4ZW8TJNYK88HG99FECX7QM.pdf (dl_321)
- **Instrument:** Bill **38 of 2026**, introduced and passed 23-02-2026. Assent is blank in the listing, so NOT CONFIRMED.
- **Content:**
  - s.8(1): 10 hours a day, 50 a week; 12 hours with overtime; overtime ≤125 hours in 3 months.
  - s.12A: night work by women with consent; employers "shall maintain consent records for inspection" (a new record).
  - New s.25A: compounding.
- **Unresolved:** whether it became an Act.

##### MZ-4. Registers under the Rules 2011
- **Label:** Secondary → **OSPV**. The text is a print of the gazette (Mizoram Gazette Extraordinary Vol. XLI, Issue No. 12, 13-01-2012, Ex-12/2012), hosted on elion.
- **Source:** https://elion.co.in/wp-content/uploads/2024/09/mizoram-shops-and-establishments-acts-and-rules.pdf (dl_323)
- **Instrument:** Notification No. A-47012/5/2009-L&E, under s.28.
- **Verbatim:**
  - r.16: "register in Form 'G' showing the daily and weekly hours of work".
  - r.17: "register in Form 'H' showing the overtime work done in every month".
  - r.49(1): "register of employment in Form 'R'".
  - r.50: "Register of Leave in Form 'S'".
  - r.51: a Leave Card in Form S.
  - r.52(2): "English or Mizo … at least a full period or three successive years".
  - r.49(4): the Chief Inspector may by written order accept an existing muster roll or register.
- **Return:** none found.
- **Conclusion:** the catalogue registers (G, H, R, S, O) are confirmed against the gazette print.

---

#### NL — Nagaland

##### NL-1. Compliance applies only to establishments with 20 or more persons; registration applies to all
- **Label:** Secondary → **OSPV**
- **Sources:**
  - Bill as published: Nagaland Gazette Part V, 13-03-2026, pp. 95–114. Scan via Simpliance: https://simpliance.in/download/file/dXBsb2Fkcy9nb3Z0bm90aWZpY2F0aW9uL0JpbGwgbm90aWZpY2F0aW9uIG9mIHRoZSBOYWdhbGFuZCBTaG9wcyBhbmQgRXN0YWJsaXNobWVudHMgKDFzdCBBbWVuZG1lbnQpIEFjdCwgMjAyNS5wZGY= (dl_332; OCR).
  - Passing: DIPR press note (official host) https://ipr.nagaland.gov.in/node/19930
- **Instrument:** Nagaland Shops and Establishments (1st Amendment) Bill, 2025. Assembly Notification No. AS/LEG-05/Bill/NSE-1/2026/1211, Kohima, 09-03-2026.
- **Dates:**
  - Introduced 09-03-2026; passed 10-03-2026 (DIPR: "The House also passed … the Nagaland Shops and Establishments (First Amendment) Act 2025 … on 10th march 2026").
  - Assent, publication as an Act and commencement are all **NOT CONFIRMED**. The bill says it comes into force on publication.
  - The preceding First Amendment Ordinance, 2025 (gazette 17-12-2025) is secondary only. One secondary gives 30-06-2026, which conflicts.
- **Verbatim (OCR):** "Provided that except the provision of registration, compliance under the Act shall be applied only to such class or classes of shops or establishment where 20 or more persons are employed."
- **Catalogue correction:**
  - Add the 20-person gate, effective from the Act's commencement (NOT CONFIRMED).
  - Below 20, registration and annual renewal remain, but the S&E register duties do not, once the Act is in force (LRR until assent is confirmed).
- **Unresolved:**
  - The Nagaland Government Press e-gazette lists only ordinary issues. Of the 17 issues from Oct 2025 to Sep 2026, 10 were text-searched with no S&E hit; the downloads of the rest timed out.
  - Extraordinary issues are not listed.

##### NL-2. Registers under the Rules 2015
- **Label:** SSO (unchanged). Re-read in a retyped elion compilation with no gazette header.
- **Source:** https://elion.co.in/wp-content/uploads/2024/09/nagaland-shops-and-establishments-acts-and-rules.pdf (dl_333)
- **Verbatim:**
  - r.14: "maintained a register in Form 'H'" (hours).
  - r.15: "leave register in Form 'I'".
  - r.25: "pay register in Form 'J'".
  - r.29: Form 'N' overtime.
  - r.54: "register of employees in Form 'S'".
  - r.55: appointment letter "in Form 'T'".
  - r.56(2): preserved "till the end of the next calendar year".
- **Return:** none found. Annual renewal of registration under s.36 is confirmed on an official host (DIPR, https://ipr.nagaland.gov.in/node/16658: register or renew "on or before 31st May 2025" in Kohima).
- **Conclusion:** the catalogue forms match.
- **Unresolved:** an official Rules text was not found; labour.nagaland.gov.in failed TLS.

---

#### TR — Tripura

##### TR-1. The Act applies to establishments with 20 or more persons, from 06-11-2025
- **Label:** Secondary → **OPV**
- **Source:** https://tripura.gov.in/sites/default/files/TSE%207th%20Amendment%20Act.pdf (dl_327; OCR-quality text layer)
- **Instrument:** **Tripura Act No. 11 of 2025**, Tripura Shops and Establishments (Seventh Amendment) Act, 2025. Law Dept. Notification No. F.8(25)-Law/Leg-I/2025, 03-11-2025.
- **Dates:**
  - Governor's assent 01-11-2025.
  - Published in the Tripura Gazette Extraordinary, Agartala, Thursday **06-11-2025**.
  - Commencement on publication, so 06-11-2025.
- **Verbatim:**
  - "(4) The Act shall be applied to such class or classes of shops or establishments where 20 or more persons are employed".
  - s.7: 8½ → 10 hours; overtime "one hundred and forty-four hours in a quarter".
- **Conclusion:** the catalogue note "From 6 Nov 2025 … 20 or more persons" is confirmed.

##### TR-2. Registration (s.16) deleted with effect from 24-05-2021
- **Label:** Secondary → **OPV**
- **Source:** gazette scan appended to the Tripura High Court's consolidated Act: https://thc.nic.in/Tripura%20State%20Lagislation%20Acts/Shops%20&%20Establishment%20Act,1970,%20Tripura%20As%20amended%20upto%205th%20amendment%20dt.24.05.2021.pdf (dl_330; OCR)
- **Instrument:** Tripura Act No. 5 of 2021, Fifth Amendment. Notification No. F.8(3) Law/Leg-I/2021(P), 15-05-2021.
- **Dates:**
  - Assent 11-05-2021.
  - Tripura Gazette Extraordinary 24-05-2021, in force on publication.
  - It repeals Ordinance No. 1 of 2021 (29-01-2021).
- **Verbatim:**
  - "3. Deletion of Section 16: Section 16 of the Principal Act, is deleted."
  - s.17(1): "this Act" → "this Act and any other law for the time being in force".
  - s.20(a): the certificate of registration is replaced by the "Trade License issued by the Competent Authority".
- **Conclusion:** there is no S&E registration in Tripura. The secondary date of 26-04-2024 is wrong.
  - s.17 still requires "a register of employees in the prescribed form and such other registers … as may be prescribed".

##### TR-3. Tripura Ease of Doing Business Ordinance, 2026: daily and weekly hours to be set by notification
- **Label:** new → **OPV**
- **Source:** https://labour.tripura.gov.in/sites/default/files/2026-02/The%20Tripura%20Shops%20and%20Establishments%20%28Eighth%20Amendment%29%20Ordinance%2C%202026..pdf (dl_329)
- **Instrument:** Tripura Ordinance No. 1 of 2026 (the number is from OCR), "The Tripura Ease of Doing Business Ordinance, 2026", Tripura Gazette Extraordinary, 13-02-2026. The labour department lists it as the "Eighth Amendment".
- **Dates:** "It shall come into force at once", so 13-02-2026 at the latest. Replacement Act: NOT CONFIRMED.
- **Verbatim:**
  - New s.6 and s.7: "There shall be no restriction on number of working hours in a week … Maximum permissible daily and weekly working hours of employees shall be such as may be specified by the State Government by issuing notification."
  - s.21 now carries fine-only penalties, including for false register entries.
- **Conclusion:** this does not change registers. The hours caps from TR-1 now depend on a notification (NOT CONFIRMED).

##### TR-4. Registers under the Rules 1970
- **Label:** SSO (unchanged; not re-read)
- **Finding:** Forms I hours, J leave, K pay, S overtime, U employees, V appointment letter.
- **Unresolved:**
  - The Rules 1970 are not on labour.tripura.gov.in. It hosts only the Fifth Amendment Rules 2017 (fee schedule only; dl_329) and a 1989 corrigendum.
  - The Ease of Compliance Rules 2019 were not re-read in this pass. The prior read found they cover BOCW, CLRA, ISMW and MW, not S&E.
- **Return:** none found.

---

#### Out-of-scope pointers for the parent (Part 1; not verified here)

- **Mizoram, LESDE "Labour Act/Rules" list** (https://lesde.mizoram.gov.in/qms):
  - "The Mizoram Code on Social Security Rules 2026 — Notified on 29th Jul, 2026": /uploads/qms/308a6a0df19abd3f542e5e1c6674e727/the-mizoram-code-on-social-security-rules-2026.pdf
  - "The Mizoram Occupational Safety, Health and Working Conditions Rules 2026 — Notified on 12th Aug, 2026".
  - This bears on the open Mizoram SS item.
- **Tripura, labour.tripura.gov.in Acts & Rules:**
  - "The Tripura Wages Rules, 2026", "Industrial Relations Rules, 2026" and "Social Security Rules, 2026", all uploaded 2026-02. These are probably the 20-01-2026 drafts. Not checked.
  - "The Tripura Draft OSH&WC Rules, 2026", uploaded 2026-08.

#### Access failures

- **egazette.gov.in:** one normal retry. curl exit 60, TLS certificate verification failed for https://egazette.gov.in/WriteReadData/2025/267840.pdf. TLS was not bypassed; the signed copy came via Simpliance instead.
- **Chhattisgarh:**
  - cglabour.nic.in, labour.cg.gov.in and cglabour.cg.gov.in: DNS did not resolve.
  - egazette.cg.nic.in: connection timed out.
  - shramevjayate.cg.gov.in: reachable, but has no Acts/Rules links.
  - Guessed Simpliance URLs for the CG Rules 2021 returned 302 (not found).
- **indiacode.nic.in:** timeout, then HTTP 504 (handle 6108 for Puducherry; bitstream 22174).
- **Andaman and Nicobar:** labour.andaman.gov.in, www.andaman.gov.in and andssw1.and.nic.in gave no connection. labour.and.nic.in worked.
- **Nagaland:**
  - labour.nagaland.gov.in: TLS "unable to get local issuer certificate". Not bypassed.
  - govtpress.nagaland.gov.in: only 10 of 17 gazette PDFs were readable; the other downloads timed out at 120 s. Extraordinary issues are not listed.
- **Mizoram:** egazette.mizoram.gov.in is reachable, but it is a login-gated admin app; its API returns an authentication error.
- **DNH and DD:** ddd.gov.in does not host the S&E Rules 2025 or a commencement notification for Regulation 14 of 2025 (labour list read to page 7; site search found nothing).
- **Sikkim:** the Rules 1984 are not found in the sikkim.gov.in gazette search.
- **pdfsig:** "NSS_Init failed: bad database". Signatures were still parsed. Signature integrity was checked, but certificate trust was not.
- **Web searches used:** about 17 of the cap of 40.

---
