> Copied into the repository's evidence folder on 2026-10-27. References to `dl_<n>` folders, `scripts/`, `ca/`, `certs/` and `work/` are to a temporary working folder that is not kept; each source URL is given in full below.

# Professional tax: second verification pass (2026-10-27b)

Scope: the priority list in the brief (KA, WB, KL, OD, TN, MP, MH), then AP, JH, TG, AS, BR, PY, SK, GJ, PB, AR,
and spot checks of the others. Inputs were the previous pass (`docs/compliance-research/evidence/2026-10-27-verify-pt.md`),
the rule-change register (P10, P11, Odisha §2, W6–W11), the state matrix and the stored `PT` versions in
`database/data/compliance/in.php` (lines 160–177 v1; 498–537 proposed v2 for WB and KL). No repository file was edited.

Downloads are in `dl_<n>/` next to this file, one per new directory. Scripts are in `scripts/`
(`dl.sh`, `sigcheck.py`, `html2txt.py`, `postback.py`, `addaia.sh`). OCR output is in `ocr_*`.

## Method

- **Labels** are applied strictly as briefed. `OFFICIAL_PRIMARY_SOURCE_VERIFIED` = the instrument text (Act,
  notification, rules, G.O.) on a `*.gov.in` / `*.nic.in` host, or a gazette PDF whose government signature validates.
  An official rate page, FAQ or listing, a gazette print on a non-government host (PRS), or a draft is
  `OFFICIAL_SOURCE_PARTIALLY_VERIFIED`. Where a jurisdiction mixes the two, the label is the weaker one and the
  stronger facts are named.
- **TLS.** No `-k`, no `--insecure`, no HTTP. Two kinds of repair were used, and neither weakens verification:
  1. Where a server sent an incomplete chain, the missing intermediate was fetched from the leaf certificate's AIA
     URL, verified against the system trust store, and added to `ca/bundle.pem`. Hosts repaired this way:
     `mptax.mp.gov.in` (GeoTrust EV RSA CA G2), `commercialtax.gujarat.gov.in` (Entrust OV TLS Issuing RSA CA 2) and
     `psdt.punjab.gov.in` (GeoTrust TLS RSA CA G1).
  2. `comtax.wb.gov.in` refuses connections from a client that requires secure renegotiation
     ("unsafe legacy renegotiation disabled"). I did **not** enable legacy renegotiation. Its PDFs were retrieved
     through the WebFetch tool, which uses a separate client; the bytes were then read locally.
- **Signatures.** `sigcheck.py` extracts the PKCS#7 blob from the PDF ByteRange and runs `openssl cms -verify`.
  Only the Karnataka gazette (Act 33 of 2025) carried a parseable signature.
- **OCR** (tesseract, English only) is marked "(OCR)". A single digit may be misread in those quotes.

---

## 1. Karnataka (KA)

| Field | Finding |
|---|---|
| Law | Karnataka Tax on Profession, Trades, Callings and Employments Act, 1976 (Karnataka Act 35 of 1976) |
| Slab (from 2023-04-01) | Monthly salary or wage **Rs. 25,000 and above** → Rs. 200 a month; below Rs. 25,000 → nil (Schedule entry 1 as substituted by Act 14 of 2023) |
| February | **From 2025-04-01:** Rs. 200 a month except February, and Rs. 300 for February (Act 33 of 2025). **FY 2023-24 and FY 2024-25:** Rs. 200 in every month, February included |
| Frequency | Monthly statement and payment; annual return |
| Payment / statement | s.6A(1): statement (Form 5-A) and payment "within twenty days of the expiry of a month" |
| Annual return | s.6(1): Form 5 "within sixty days of the expiry of the year", i.e. about 30 May. Read on the PRS consolidation (non-government host), so OFFICIAL_SOURCE_PARTIALLY_VERIFIED for this field |
| Label | OFFICIAL_PRIMARY_SOURCE_VERIFIED → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (unchanged; the gazette signature of Act 33 of 2025 now validates) |

**Instruments and dates**

- **Karnataka Act No. 33 of 2025**, Karnataka Tax on Profession, Trades, Callings and Employments (Amendment) Act, 2025.
  - Notification: DPAL 08 SHASANA 2025, 15-04-2025.
  - Gazette: Karnataka Gazette Extraordinary, Part IVA, No. 232, 15-04-2025.
  - Assent: 10-04-2025.
  - Commencement: **2025-04-01**.
  - Source: https://erajyapatra.karnataka.gov.in/WriteReadData/2025/8459.pdf (`dl_2`). The digital signature
    (CN=THAMMAIAH, O=GOVERNMENT OF KARNATAKA; chain Verasys Sub CA 2022 → Verasys CA 2022 → CCA India 2022) gives
    "CMS Verification successful" over a ByteRange that covers the whole file. Poppler's `pdfsig` reported
    "Signature is Invalid" only because it could not open an NSS database.
  - The same text is in https://gst.karnataka.gov.in/Documents/General/ptnotificationact29323.pdf (`dl_1`, an
    iLovePDF merge of Acts 33/2025 and 14/2023).
- **Karnataka Act No. 14 of 2023.**
  - Notification: DPAL 06 SHASANA 2023, 14-03-2023.
  - Gazette: Part IVA, No. 113, 14-03-2023.
  - Assent: 13-03-2023.
  - Commencement: "Save as otherwise provided … with effect from the 1st day of April, 2023". Section 7 substitutes
    the whole Schedule.

**Verbatim**

- Act 33/2025, s.1(2): "It shall come into force with effect from the first day of April, 2025."
- Act 33/2025, s.2: "in serial number 1, for the entries relating to the column number (3),the following shall be
  substituted, namely:- '(i) Rs.200-00 per month (except for the month of February); (ii) Rs.300-00 for the month of
  February'".
- Act 14/2023, Schedule entry 1: "Salary or wage earners whose salary or wage or both, as the case may be, for a month
  is Rs. 25,000-00 and above — Rs. 200-00 per month".

**2026 change.** Karnataka Act 22 of 2026 (PRS Bill 8 of 2026, in force 1-4-2026) concerns the returns of enrolled
persons only. No source shows it changing Schedule entry 1. SECONDARY_SOURCE_ONLY for "no Schedule change".

**Open point.** The PRS consolidation (about 2005) has a s.6A(1) proviso, inserted by Act 5 of 1996: where the tax
deducted in a month is "not more than rupees five thousand", the statement may be furnished "within twenty days of
expiry of a quarter", with quarters ending 31 May, 31 August, 30 November and February. Whether this proviso is still
in force is NOT CONFIRMED. The portal FAQ read in the previous pass states payment "within 20th of succeeding month"
for every employer.

**Stored version (KA v1, 2023-04-01, `[[24999,0],[null,200]]`, february_amount 300): DOES NOT MATCH.**

- `february_amount` 300 is valid only from 2025-04-01. For February 2024 and February 2025 the stored version
  overstates the tax by Rs. 100. The fix is two versions: 2023-04-01 → 2025-03-31 without `february_amount`, and
  2025-04-01 → with `february_amount` 300. W8 is confirmed.
- Minor: the bound 24999 taxes a salary between 24,999 and 25,000 (for example 24,999.50); the law makes it nil.
- The slab amount (200) and the threshold (25,000) match.

---

## 2. West Bengal (WB)

| Field | Finding |
|---|---|
| Law | WB State Tax on Professions, Trades, Callings and Employments Act, 1979, as amended by the WB Finance Act, 2026 (West Ben. Act XIII of 2026) |
| Slabs to 30-09-2026 | Schedule w.e.f. 1-4-2014: ≤10,000 nil; 10,001–15,000 110; 15,001–25,000 130; 25,001–40,000 150; >40,000 200 (previous pass, official comtax copy; not re-read) |
| Slabs from 01-10-2026 | ≤20,000 nil; 20,001–30,000 Rs 100; 30,001–50,000 Rs 140; 50,001–1,00,000 Rs 170; >1,00,000 Rs 208 a month |
| Frequency | Monthly payment within 21 days after the month (r.12, 2008 consolidation); Form III return annual or quarterly. Not re-read this pass |
| Label | OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (commencement point now resolved officially; final 1607-F.T. still not read on an official host) |

**Instruments and dates**

- **WB Finance Act, 2026 (Act XIII of 2026).**
  - Law Dept notification No. 756-L, 07-08-2026.
  - Kolkata Gazette Extraordinary, Part III, SAR-13, 07-08-2026; assent first published the same day.
  - Source: https://comtax.wb.gov.in/GST/GST_Acts_Rules/756L_20260820.pdf (`dl_5`).
  - s.2(2)(a) replaces s.3(2) with a flat "two thousand five hundred rupees per annum".
  - s.2(2)(b) inserts s.3(2A): "Notwithstanding anything contained in sub-section (2), the aforesaid rate of tax shall
    not apply where the State Government, by notification, specifies different rate of tax in the Schedule, the
    persons specified therein shall be liable to pay tax at such rate, whether per annum or per month …".
- **Commencement notification: No. 1406-F.T., Howrah, 18-08-2026.** OFFICIAL_PRIMARY_SOURCE_VERIFIED.
  - Kolkata Gazette Extraordinary, Part I, SAR-469, 18-08-2026.
  - Source: https://comtax.wb.gov.in/GST/GST_Acts_Rules/1406-FT_20260820.pdf (`dl_4`; unsigned PDF on a gov.in host).
  - Verbatim: "(a) to notify that section 1 and sub-section (3), sub-sections (5), (6) and (7) of section 2 of the said
    Act have come into force with immediate effect upon notification of the said Act; (b) to appoint the 1st day of
    October, 2026, as the date on which sub-section (1), clause (a) of sub-section (2) and sub-section (4) of section 2
    of the said Act shall come into force; (c) to appoint such dates on which the provisions of the Schedule notified
    under clause (b) of sub-section (2) of section 2 of the said Act shall come into force."
  - This is the commencement notification the register said was NOT FOUND. Clause (c) ties the Schedule's commencement
    to the dates stated in the Schedule notification itself.
  - Effect: from 1-10-2026, s.3(2) charges a flat ₹2,500 a year unless the s.3(2A) Schedule specifies otherwise. The
    salary slabs therefore depend on the Schedule's own N.B. date.
- **Draft Schedule: No. 1407-F.T., Howrah, 18-08-2026.**
  - Kolkata Gazette Extraordinary, Part I, SAR-470.
  - Source: https://comtax.wb.gov.in/pdf/SAR-470_Finance%20Dept(Rev)_1407-FT.pdf (`dl_64`). Re-read: it invites
    objections within fourteen days.
  - Verbatim N.B.: "(1) Sl. No. 1 of Part A, whole of Part B and whole of Part C of the Schedule will come into force
    with effect from the 1st day of October, 2026. (2) Sl. No. 2 & Sl. No. 3 of Part A of the Schedule will come into
    force from the financial year starting on and from the 1st day of April, 2027."
  - Slab text: "a) Not exceeding Rs. 20,000 NIL / b) Above Rs. 20,000 but not exceeding Rs.30,000 Rs. 100 per month /
    c) … Rs.50,000 Rs. 140 per month / d) … Rs.100,000 Rs. 170 per month / d) Above Rs. 1,00,000 Rs. 208 per month".
- **Final Schedule: No. 1607-F.T., 16-09-2026.** Read only in secondary sources.
  - TeamLease RegTech (article 60103, published 06-10-2026) says Sl. 1 of Part A and Part B apply from 1-10-2026, and
    Sl. 2–3 of Part A and **Part C** from 1-4-2027. Taxaj, AscentHR and SGCMS say Part C applies from 1-10-2026.
  - The employee slabs are the same in every source.
  - Not found on: the comtax.wb.gov.in GST Acts/Rules page (which lists 1406-F.T. and 756-L only), the
    Schedules & Forms page (which still lists only the 2014 P.Tax schedule), and professiontax.wb.gov.in (a JS
    shell, also behind the legacy-renegotiation failure). The wbxpress page reproduces only 1407-F.T.

**Stored versions**

- **WB v1 (2013-04-01, `[[10000,0],[15000,110],[25000,130],[40000,150],[null,200]]`).** The slabs match the 1-4-2014
  schedule.
  - `effective_from` is one year early: W6 stands; the schedule is effective 2014-04-01.
  - v1 must also end on **2026-09-30**. Under 1406-F.T., s.3(2) as amended applies from 1-10-2026, so v1 cannot run
    past that date.
- **WB v2 (proposed, 2026-10-01, `[[20000,0],[30000,100],[50000,140],[100000,170],[null,208]]`).** Matches the official
  draft and every secondary report of the final. The remaining blocker is the official text of 1607-F.T.; the
  commencement of s.3(2A) is no longer a separate blocker. The evidence note "commencement of s.3(2A) were not
  retrieved" can now cite 1406-F.T.

---

## 3. Kerala (KL)

| Field | Finding |
|---|---|
| Law | Kerala Panchayat Raj Act, 1994 ss.204–205 and the KPR (Profession Tax) Rules, 1996; Kerala Municipality Act, 1994 (s.245) and the Kerala Municipality (Profession Tax) Rules, 2005 |
| Rates from 01-10-2024 (half-yearly) | ≤11,999 nil; 12,000–17,999 320; 18,000–29,999 450; 30,000–44,999 600; 45,000–99,999 750; 1,00,000–1,24,999 1,000; ≥1,25,000 1,250 |
| Structure | Half-yearly (Apr–Sep, Oct–Mar). KPR Act s.204(1): "The profession tax shall, subject to such rules as may be prescribed be levied every half year in every Village Panchayat area". Panchayats: employer assesses and recovers "Before the end of August and February" (s.205F(2), previous pass) |
| Who fixes the rate | **The local body.** KPR Act s.204(2): "The profession tax shall be levied at such rates as may be fixed by the Village Panchayat not exceeding the maximum rates prescribed." The LSGD law manual records the Kerala High Court: "Maximum rates of tax are prescribed in the statutory rules leaving it to the Panchayats to prescribe rates of tax subject to the maximum" (Thoshiba Anand L.W. Association, 1985 KLT 95) |
| Label | OFFICIAL_PRIMARY (G.O.) / LEGAL_REVIEW_REQUIRED → **LEGAL_REVIEW_REQUIRED** |

**Instrument and dates**

- **G.O.(Rt) No. 1149/2024/LSGD, Thiruvananthapuram, 27-06-2024** (file LSGD-RC2/13/2024-LSGD).
- Source: https://go.lsgkerala.gov.in/files/go20240627_37169.pdf (`dl_7`). Its embedded signature could not be parsed:
  Poppler reports "Illegal values in ByteRange array". It is an official gov.in host.
- The new rates apply from 1 October 2024, the start of the second half-year of 2024 (para 3, Malayalam).
- Para 3 also directs amendment of the KM Act, the KM (PT) Rules 2005, the KPR Act and the KPR (PT) Rules 1996.
- Statute pages: https://panchayatwiki.lsgkerala.gov.in/Panchayat:Repo18/Law_Manual_Page0258 (s.204; `dl_15`) and
  …Page0260 (case note; `dl_12`).

**What is not confirmed**

- **No S.R.O. amending the Profession Tax Rules' maximum-rate schedule was found** (searches for 2024–2026 Amendment
  Rules returned only the G.O.).
- Council resolutions adopting the new rates: not found.
- So whether the 2024 rates bind a local body that has not adopted them is unresolved, and the G.O. alone does not
  make them statutory maxima. **LEGAL_REVIEW_REQUIRED** stands.
- Kerala Municipality Act s.245 text and the municipal due dates were not read on an official host.

**Stored versions**

- **KL v1 (2016-04-01).** Equals the old KPR Rules maxima ÷ 6 (120/180/300/450/600/750/1000/1250), with 166 for
  166.67 and 208 for 208.33. It is superseded from 2024-10-01 (W7). The 2016-04-01 start date is NOT CONFIRMED.
- **KL v2 (proposed, 2024-10-01).** The amounts equal the G.O. ÷ 6. It differs from the law in four respects:
  1. Structure: the law levies half-yearly, payable by the end of August and of February. The stored version deducts
     monthly.
  2. Base: the law excludes HRA, CCA, conveyance and TA (s.204(3)). The stored version applies the slabs to gross.
  3. Rate status: the law's rates are maxima that each local body fixes. The stored version treats them as uniform.
  4. Band edges: 1999 against 1999.83 and similar rounding, so a monthly salary of 1999.50 is taxed although the
     half-yearly equivalent (11,997) is nil.

---

## 4. Odisha (OD)

| Field | Finding |
|---|---|
| Position | PT on salaries repealed **from 01-04-2026** by Ordinance; savings clause keeps earlier liabilities |
| Label | OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **LEGAL_REVIEW_REQUIRED** (the repeal itself remains OFFICIAL_PRIMARY_SOURCE_VERIFIED; its survival after about 3-Nov-2026 is unproven) |

**Instrument.** Odisha State Tax on Professions, Trades, Callings and Employment (Repeal) Ordinance, 2026
(Ordinance 02 of 2026).
- Gazette Extraordinary No. 1496, 21-04-2026; Law Dept No. 5969-I-Legis-22/2026/L.
- s.1(2): "This Ordinance shall come into force with effect from dated 1st April, 2026."
- Source: https://finance.odisha.gov.in/sites/default/files/2026-04/1496-LAW%20DEPTT..pdf (read in the previous pass).

**This pass:**
- The official listing https://finance.odisha.gov.in/en/notification/tax-profession (`dl_16`), read on 27-10-2026,
  still shows the Ordinance (21/04/2026) as its latest item. **No Repeal Act is listed.**
- Searches found the Bill: introduced 22-09-2026 (TeamLease) and reported passed (Deccan Chronicle, previous pass).
- **No assent, Act number or gazette notification was found** (PRS, finance.odisha.gov.in and news searches).
- Lapse: under Art. 213(2)(a), six weeks from the Assembly reassembling. If that was 22-09-2026 (secondary), the
  Ordinance lapses on **about 03-11-2026**. That date is a calculation, not an official statement.

**Stored:** no Odisha version. This is consistent: nothing is deductible from April 2026 while the Ordinance or an Act
is in force. If the Ordinance lapses without an Act, the 2000 Act revives and a version will be needed.

---

## 5. Tamil Nadu (TN)

| Field | Finding |
|---|---|
| Law | TN Urban Local Bodies Act, 1998 (TN Act 9 of 1999) ss.117-B to 117-H; **TN Urban Local Bodies Rules, 2023**, rr.274–282 |
| Default rates (all urban local bodies, r.275(2) Table) | Average half-yearly income ≤21,000 nil; 21,001–30,000 Rs 135; 30,001–45,000 Rs 315; 45,001–60,000 Rs 690; 60,001–75,000 Rs 1,025; ≥75,001 Rs 1,250. These apply "till such time as the next general revision of tax is carried out by the Council" |
| GCC rates from H2 2024-25 (01-10-2024) | ≤21,000 nil; 21,001–30,000 180; 30,001–45,000 425; 45,001–60,000 930; 60,001–75,000 1,025; above 75,001 1,250 |
| Basis | "average half-yearly income" = "the average of the gross income derived by a person during the two half-years of the previous financial year" (r.274(a)). "Salary" includes pay, DA, regular remuneration and perquisites, but excludes "any form of bonus or gratuity" (r.274(e)) |
| Deduction months | **August and January** (r.277(2)) |
| Payment / return | **By 30 September and 31 March** with Form 14 (r.277(3)) |
| Label | OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (procedure and default table now primary; GCC's 2024 rates are still from an official rate page) |

**Instrument and dates**

- **TN Urban Local Bodies Rules, 2023.**
  - G.O. Ms. No. 45, MAWS (Election) Dept, 12-04-2023; No. SRO A-17(a)/2023.
  - TN Government Gazette Extraordinary No. 124, Part III–Section 1(a), 12-04-2023.
  - r.1(3): "They shall come into force on the 13 April 2023."
  - Source (gov.in host): https://tnurbantree.tn.gov.in/sivagangai/wp-content/uploads/sites/38/2023/07/2023-Service-Rules-404-pages.pdf
    (`dl_17`). OFFICIAL_PRIMARY_SOURCE_VERIFIED for rr.274–282.
- **GCC rates.** https://chennaicorporation.gov.in/gcc/department/revenue/ (`dl_18`): the table has columns "Half yearly
  Profession Tax upto 31.03.2018 / w.e.f. 01.04.2018 / w.e.f. II/2024-2025". This is an official rate page, so
  OFFICIAL_SOURCE_PARTIALLY_VERIFIED.
  - The GCC Council resolution (July 2024, "+35%" per DT Next) and the GCC letter R.D.(HQ).C.No.PT/SPL/2024 of
    20-01-2025 (Akrivia copy) were **not read officially**.
  - DT Next (July 2024) reported the hike would apply "only if" the State approved it. GCC's own page and its
    04-08-2026 press release apply it, so the official GCC position prevails over the news report.

**Verbatim (Rules 2023)**

- r.277(2): "The tax payable by any person earning a salary or wage shall be deducted by his employer through the
  drawing and disbursing officer of the office concerned from the salary payable to such person from the respective
  pay bill for the month of August and January every year."
- r.277(3): "The employer shall, irrespective of whether such deduction has been made or not … be liable to pay the
  half-yearly tax on behalf of such person to the municipality by 30th September and 31st March every year along with
  the details in Form 14."
- r.277(6): even where the DDO "recovers the half-yearly tax due in monthly installments", the half-yearly tax is to
  be remitted in one lump sum within the prescribed time.

This closes the previous gap "January-deduction remittance date NOT CONFIRMED": it is **31 March**.

**Not confirmed**

- Which other corporations and municipalities have carried out a general revision (their rates would differ from the
  r.275 table).
- Village and town panchayat PT under the TN Panchayats Act, 1994 (the tnrd.tn.gov.in G.O. found, `dl_63`, concerns
  only 1992–98 arrears).
- Exemptions under s.117-H.

**Stored version (TN v1, 2018-04-01, `[[3500,0],[5000,22.5],[7500,52.5],[10000,115],[12500,171],[null,208]]`): DOES NOT MATCH.**

- Amounts: v1 equals the r.275(2) default table ÷ 6. That table is still the legal default in urban local bodies
  without a general revision. 1025/6 = 170.83 is stored as 171, and 1250/6 = 208.33 as 208.
  - For GCC since 2024-10-01, the bands 2–4 differ: 180, 425 and 930 a half-year (30, 70.83 and 155 a month), not
    22.5, 52.5 and 115.
- Structure:
  - Stored: a monthly deduction.
  - Law: a half-yearly amount, deducted from the August and January pay bills and remitted by 30 September and
    31 March.
  - Law: the band is chosen on the **average half-yearly income of the previous financial year**, not on the current
    month's gross.
- Date: v1's 2018-04-01 is GCC's date. The Rules' statewide table is in force from 2023-04-13; the position before
  that is NOT CONFIRMED.
- W9 is confirmed and refined.

---

## 6. Madhya Pradesh (MP)

| Field | Finding |
|---|---|
| Slabs (annual salary) | ≤2,25,000 nil; 2,25,001–3,00,000 Rs 1,500 (Rs 125 a month); 3,00,001–4,00,000 Rs 2,000 (**Rs 166** a month for 11 months, **Rs 174** in the 12th); >4,00,000 Rs 2,500 (Rs 208 × 11 + **Rs 212** in the 12th) |
| Annual cap | Rs 2,500: 208 × 11 + 212 = 2,500; 166 × 11 + 174 = 2,000 |
| Frequency | Monthly payment within 10 days after the month (s.12(2)); quarterly Form 7 return by the 15th of the month after the quarter (r.11(1)). Previous pass; not re-read |
| Label | OFFICIAL_PRIMARY_SOURCE_VERIFIED → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** (unchanged) |

**Instruments**

- **MP Act No. 20 of 2018**, MP Vritti Kar (Sanshodhan) Adhiniyam, 2018.
  - Assent 16-07-2018; assent first published in the MP Gazette (Extraordinary) of 18-07-2018.
  - s.1(2): "It shall be deemed to have come into force from 1st April, 2018."
  - Source: https://mptax.mp.gov.in/mpvatweb/download/act/pdf/PT-Amendment-Act-2018.pdf (`dl_19`; OCR `ocr_mp/p-4.txt`
    and `p-5.txt`).
  - Verbatim (OCR): "(c) exceed rupees 3,00,000 but does not exceed rupees 4,00,000 Rupees 2000 (Rupees 166 per month
    for eleven months and rupees 174 for twelfth month) (d) exceed rupees 4,00,000 Rupees 2500 (Rupees 208 per month
    for eleven months and rupees 212 for twelfth month)".
- **MP Act No. 20 of 2023** (assent 10-08-2023; Gazette Extraordinary 11-08-2023; in force on publication) only omits
  ss.23, 24 and 26 (offences). It does not touch the Schedule. Source: PRS compilation (`dl_23`, OCR `ocr_mp23`).
- The official Profession Tax Act page on mptax.mp.gov.in (reached via a POST session, `dl_22/pt2.html`) lists only
  the 2018 Amendment Act, the Act and Rules PDF, and the 2010 amendment.

**Open lead.** Simpliance and DCP list an "Amendment notification under the MP Vritti Kar Adhiniyam, 1995",
No. CT-15-0001-2025-Sec-1-V-(CT)(08), with pages updated 31-03-2026 / 06-04-2026.
- Its content and date are NOT CONFIRMED: both pages are login- or link-only, and it is not on mptax's Act page.
- The indianemployees.com gazette listing for MP Commercial Taxes (Feb–Jul 2026 and May–Dec 2025) shows no Vritti Kar
  item.
- No evidence that it changes the salary slabs. It should be retrieved before activating any MP correction.

**Stored version (MP v1, 2018-04-01, `[[18750,0],[25000,125],[33333,167],[null,208]]`, march_amount 212): DOES NOT MATCH.**

1. Band 3 is stored as 167; the law says **166**. 167 × 12 = 2,004 exceeds the statutory 2,000.
2. Band 3's 12th-month amount of **174** is not stored.
3. `march_amount` 212 is right only for band 4. After the engine fix it applies only to the top band, so band 2 (125)
   and band 3 are no longer hit by it.
4. The bands are on annual salary, so monthly bounds are an approximation. 33333 against 33,333.33 puts a monthly
   salary of 33,333.10 into band 4.

W10 is confirmed.

---

## 7. Maharashtra (MH)

| Field | Finding |
|---|---|
| Slabs (men) | ≤7,500 nil; 7,501–10,000 Rs 175 a month; >10,000 Rs 2,500 a year, paid as Rs 200 a month except February and **Rs 300 in February** |
| Slabs (women) | ≤25,000 nil; >25,000 Rs 2,500 a year: 200 a month, 300 in February |
| February rule | Applies only to the >10,000 (men) and >25,000 (women) entries. The 7,501–10,000 entry is "one hundred seventy-five per month", with no February variant |
| Due dates | Monthly filers: return and payment by the **15th** of the month after the salary month. Annual filers: by **15 March** (Amendment Rules 2026). Earlier: last day of the month / 31 March |
| Label | OFFICIAL_PRIMARY_SOURCE_VERIFIED → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** |

**Instruments**

- **Mah. Act No. XIX of 2023.**
  - First published, after assent, in the Maharashtra Government Gazette on 06-04-2023 (Extraordinary Part VIII,
    No. 35).
  - s.1(2): "It shall come into force on the 1st April 2023."
  - Source: https://www.mahagst.gov.in/public/uploads/mvatservices/1760521417PT%20Amendment%20Act,%202023_English.pdf
    (`dl_24`).
  - Verbatim: "(i) in case of men, whose monthly salaries or wages,–– (a) do not exceed rupees seven thousand five
    hundred; Nil. (b) exceed rupees seven thousand five hundred but do not exceed rupees ten thousand; one hundred
    seventy-five per month. (c) exceed rupees ten thousand; two thousand five hundred per annum to be paid in following
    manner :–– (a) two hundred per month except for the month of February; (b) three hundred for the month of February;
    (ii) in case of women, whose monthly salaries or wages,–– (a) do not exceed rupees twenty-five thousand; Nil.
    (b) exceed rupees twenty-five thousand; two thousand five hundred per annum …".
- **Amendment Rules 2026.**
  - Notification No. व्यकर-2026/C.R.4/Taxation-3, 28-02-2026; Gazette Extraordinary Part IV-B No. 91.
  - Source: https://www.mahagst.gov.in/public/uploads/mvatservices/1772710759_PT%20Notification%2028.2.2026.pdf (`dl_62`).
  - Verbatim: "(1) in clause (a), for the figures and words '31st of March' the figures and words '15th of March'
    shall be substituted ; (2) in clause (c), for the words 'last date' the figures and words '15th day' shall be
    substituted".
  - It has no separate commencement clause, so it took effect on publication, 28-02-2026.
- No 2024–2026 change to Schedule I entry 1 was found (search; secondary).

**Stored version (MH v1, 2023-04-01, `[[7500,0],[10000,175],[null,200]]`, february_amount 300, female_exempt_upto 25000): MATCHES**,
given the engine fix that applies `february_amount` to the top band only. A woman earning ≤25,000 gets nil; above
that, the men's table yields 200 / 300.

---

## 8. Andhra Pradesh (AP)

| Field | Finding |
|---|---|
| Slabs | ≤15,000 nil; 15,001–20,000 Rs 150 a month; >20,000 Rs 200 a month |
| Exemptions | Schedule entry 39, including "Physically Handicapped persons with 40% or more disability of any kind" |
| Frequency | Monthly: Form V is a "Return of tax payable for the month ending on …" |
| Due date | NOT CONFIRMED officially. Act s.7(1) says returns are due "by such dates as may be prescribed"; Rules 12–13 name Form V and payment by challan but give no date. The 10th of the following month remains SECONDARY |
| Label | SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (slabs and instrument now primary; due date still secondary) |

**Instrument and dates**

- **G.O.Ms.No. 82, Revenue (CT-IV) Dept, 04-02-2013.** A notification under s.37(1) of Act 22 of 1987 substituting the
  First Schedule.
- The G.O. orders publication in "an extraordinary issue of the Andhra Pradesh Gazette, Dt.06-02-2013". The gazette
  issue itself was not read. No separate commencement clause, so in force on publication, **06-02-2013**.
- Retrieved by ASP.NET postback from https://apvatisold.apct.gov.in/apportal/AllActs/APPT/Gos_Notifications.aspx
  (`dl_40/go82.bin`). The consolidated schedule "(As amended upto date)" is at
  https://apvatisold.apct.gov.in/apportal/AllActs/APPT/APPTSchedule.aspx (`dl_28`).
- The new apct.gov.in site is a Next.js app; the old URLs on it now return the homepage.
- G.O.Ms.No. 223 of 24-08-2020 (`dl_41`) amends entries 4, 7, 9, 14, 18, 21, 25 and 31 only. Entry 1 is unchanged.
- Verbatim (G.O. 82): "1 Salary and wage earners whose monthly salaries or wages in Rs: (i) Up to 15,000 Nil (ii) From
  15,001 to 20,000 150 PM (iii) Above 20,000 200 PM".

**Stored version (AP v1, 2013-02-01, `[[15000,0],[20000,150],[null,200]]`):** the slabs **match**.
`effective_from` **differs**: 2013-02-01 is stored; the notification was published in the gazette on 06-02-2013.
W11 is confirmed and now officially sourced. The previous pass attributed the date to "Act 12 of 2013"; the operative
instrument read here is the s.37 notification in G.O.Ms.No. 82.

---

## 9. Telangana (TG)

| Field | Finding |
|---|---|
| Slabs | As AP (official First Schedule "(As amended upto date)", https://www.tgct.gov.in/tgportal/AllActs/APPT/APPTSchedule.aspx; previous pass). The TG G.O. list includes G.O. 82 of 04-02-2013, inherited |
| Due date | NOT CONFIRMED. TG Act s.7 (https://www.tgct.gov.in/tgportal/AllActs/APPT/APPTAct/07Returns.html, `dl_34`): "by such dates as may be prescribed". Rule 12 names "Form V" only (`dl_31`). Form V (`dl_37`) is "Return of tax payable for the month ending on" with no date. The FAQ (`dl_38`) gives no employer date. G.O. 30 of 08-03-2021 (`dl_43`) only substitutes rule 8 (exhibition of certificate) |
| Label | **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged) |

**Stored version (TG v1, 2014-06-02, same slabs): MATCHES** for slabs. The start date is the date Telangana was formed,
which is fine for a TG version.

---

## 10. Jharkhand (JH)

| Field | Finding |
|---|---|
| Slabs (annual salary) | ≤3,00,000 nil; 3,00,001–5,00,000 Rs 1,200; 5,00,001–8,00,000 Rs 1,800; 8,00,001–10,00,000 Rs 2,100; >10,00,000 Rs 2,500 a year |
| Exemptions | s.3(3)(ii): persons employed with the Union Ministry of Defence, Union Ministry of Home Affairs and BSF |
| Payment | Quarterly: the employer is liable "to pay/deposit tax within fifteen days after the end of the quarter" (s.5(1)). s.6 Explanation: tax is "charged on monthly basis, but shall be paid by the fifteenth day of the month after the expiry of a quarter". So 15 Jul, 15 Oct, 15 Jan, 15 Apr |
| Quarterly return | JPT 201, r.12(1) (OCR): "by 15th of May, 15th of August, 15th November and 15th February for the quarter ending 30th June, 30th September, 31st December and 31st March respectively". The printed pairing (15 May for the June quarter) is internally inconsistent; it is most likely the 15th of the second month after each quarter. **CONFLICTING** wording; legal check needed |
| Annual return | JPT 203 "within one month after the expiry of six months of the year" (r.12(3), OCR), i.e. **31 October** |
| Label | SECONDARY_SOURCE_ONLY → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** |

**Sources**

- New official host https://ctax.jharkhand.gov.in (valid TLS; the old jharkhandcomtax.gov.in still has a hostname
  mismatch).
- Act copy: https://ctax.jharkhand.gov.in/documents/10231/16291/Other+Acts+and+Rules/4191b34f-315c-43c9-9ceb-628d3c2cb46a?version=1.2
  (`dl_47`, Word-generated, headed "THE JHARKHAND TAX ON PROFESSIONS, TRADES, CALLINGS AND EMPLOYMENTS BILL, 2011";
  hence partial).
- Rules 2012: https://ctax.jharkhand.gov.in/documents/10231/16514/Professional+Tax+Rules%2C+2012/7462df88-a600-4f9c-a035-c92ef02678b5?version=1.1
  (`dl_48`, scanned; OCR `ocr_jh/hi-04.txt`).
- The Rules cite the Act as "Jharkhand Act No. 02, 2012" and are deemed in force "on the Appointed Day". The
  appointed-day notification was NOT CONFIRMED, so `effective_from` is NOT CONFIRMED.
- Verbatim (Act Schedule entry 1): "Salary and wage earners, such persons whose annual salaries or wages: (i) Upto
  Rs. 3,00,000/- Nil (ii) Range from Rs. 3,00,001/- to Rs. 5,00,000/- 1200/- PA (iii) Range from Rs. 5,00,001/- to
  Rs. 8,00,000/- 1800/- PA (iv) Range from Rs. 8,00,001/- to Rs. 10,00,000/- 2100/- PA (v) Above 10,00,000/-
  2,500/- PA".

**Stored:** no JH version.

---

## 11. Assam (AS)

| Field | Finding |
|---|---|
| Slabs | ≤15,000 nil; 15,001–24,999 Rs 180; ≥25,000 Rs 208 a month (Gazette Extraordinary No. 139, notification of 01-04-2025, in force on publication, 02-04-2025; previous pass, OCR, tax.assam.gov.in) |
| Return / payment | **Monthly Form III "on or before the last day of every month"** for the preceding month's salaries, with full payment into the treasury before filing (r.12(1)–(2)). With permission (Form IIIA), quarterly, half-yearly or annual returns, with advance payment within 30 days of the start of the period and the return "before the last date of the month following the said period" (r.12(3)) |
| Label | OFFICIAL_SOURCE_PARTIALLY_VERIFIED → **OFFICIAL_PRIMARY_SOURCE_VERIFIED** |

**Source:** Assam PT Rules 1947 consolidation (PrimoPDF, created 30-09-2010; originally Gazette notification BB.94/45/72
of 21-10-1947): https://tax.assam.gov.in/circular/circular/apt/rules/apt.pdf (`dl_56`).
- Verbatim r.12(1): "Every employer registered under the Act shall furnish a monthly return in Form III on or before
  the last day of every month showing therein the salaries and wages paid by him in respect of the month immediately
  preceeding that month and the amount of tax deducted by him".
- Form III (`dl_55`) notes it was substituted w.e.f. 30-9-1992 by FTX.55/92/40.
- **Conflict note:** the "28th of the following month" in Keka, Zoho and Simpliance is secondary and does not override
  r.12. Amendments to r.12 after 2010 were not checked.

**Stored:** no AS version.

---

## 12. Gujarat (GJ)

| Field | Finding |
|---|---|
| Slabs | Monthly salary up to ₹12,000 zero; more than ₹12,000 ₹200 a month; "Effective from 01/04/2022" |
| Frequency / due | As the previous pass (Rules 2008 consolidation): more than 20 employees, monthly Form 5 within 15 days; 20 or fewer, quarterly payment and annual Form 5-AA |
| Label | OFFICIAL_PRIMARY_SOURCE_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (strict label: only the department's rate schedule shows the 2022 slab) |

**Sources and gaps**

- Rate schedule: https://commercialtax.gujarat.gov.in/vatwebsite/download/schedule/Rate_of_Profession_Tax.pdf (`dl_49`,
  Word 2013 file created 28-06-2024). It is an official rate page, not the instrument.
- Both Act consolidations on the same host are stale:
  - Ptact.pdf (`dl_50`, 2008) shows "(v) Rs. 12,000/- or more 200/- per month" with lower graded bands.
  - PT_1976_Eng.pdf (`dl_51`, 2005).
- The 2022 notification is cited only by secondary sources (Simpliance, Lexplosion) as **GHN-35-PFT-2022-S.3(2)(10)-Th
  dated 08-04-2022**. Not found on an official host; the department listing pages timed out.
- Effective date: the official page says 01/04/2022. FactoHR and CalcGuru say 24-05-2022. The official page prevails,
  but the notification text is needed.

**Stored version (GJ v1, 2022-04-01, `[[12000,0],[null,200]]`): MATCHES** the official rate schedule.

---

## 13. Punjab (PB): Punjab State Development Tax

| Field | Finding |
|---|---|
| Rate | Rs 200 a month for "persons who are assessable under the Head Income from Salaries"; levied only on income-tax payees (s.4(3)); senior citizens per the Income Tax Act (s.4(2)). Previous pass, official psdt.punjab.gov.in |
| Due | Deposit by the last day of the following month (r.14(3), 2021); annual PSDT-6 by 31 August (r.10(1), 2022) |
| Label | **LEGAL_REVIEW_REQUIRED** (unchanged) |

**New this pass**

- The PSDT portal document list (`dl_54`, fetched after an AIA chain repair) has nothing newer than the 13-12-2024
  clarification. No 2025 or 2026 Act or rules are posted.
- Secondary sources report:
  - a **PSDT (Amendment) Bill, 2025** passed by the Punjab Assembly in July 2025, amending definitions, with
    commencement by notification;
  - **PSDT (First Amendment) Rules, 2025** of 07-11-2025, adding forms PSDT-15 to 18 and a lump-sum option payable by
    30 April (TeamLease).
- Neither was read officially. The Assembly PDF failed on legacy TLS renegotiation via curl and timed out via WebFetch.
  NOT CONFIRMED.
- The liability test still cites the Income Tax Act, 1961, which the Income-tax Act, 2025 replaced from 1-4-2026.
  This is a legal-review item.

**Stored:** no PB version.

---

## 14. Sikkim (SK): coordinator's specific question

**Is an https copy reachable?**
- On the department host: **No.** `https://www.sikkimtax.gov.in/` and `https://sikkimtax.gov.in/` both fail with
  `TLS connect error: error:0A000102:SSL routines::unsupported protocol`. The server offers only a TLS version OpenSSL
  3.5 refuses. Not bypassed; no HTTP fallback used.
- **An https copy of the gazetted Act exists on PRS:** https://prsindia.org/files/bills_acts/acts_states/sikkim/2006/2006SK5.pdf
  (`dl_65`). It is a print of Sikkim Government Gazette Extraordinary No. 95, Gangtok, 1 April 2006 (Law Dept
  No. 25/LD/2006, 31-03-2006; assent 24-03-2006). PRS is a non-government host, so the label is partial.

| Field | Finding |
|---|---|
| Slabs (monthly) | "(i). Rs. 20000 or less Nil (ii). Rs.20001 or more, but less than Rs.30001 Rs. 125 per month (iii). Rs 30001 or more, but less than Rs.40001 Rs.150 per month (iv). Rs.40001 and more Rs.200 per month". The column is headed "Rate of tax(Proposal)" **even in the gazette print**, so the caveat is not just the .doc copy |
| Return | s.9(1): "a return in respect of every quarter, on or before the end of the month following the end of the quarter", i.e. 31 Jul, 31 Oct, 31 Jan, 30 Apr |
| Payment | Rule 13(1): monthly, within 15 days after the month. Read in the previous pass over HTTP only; **not re-verified** this pass |
| Commencement | s.1(3): "on such date as the State Government may, by notification in the Official Gazette, appoint". The appointed date is NOT CONFIRMED |
| Label | OFFICIAL_PRIMARY_SOURCE_VERIFIED → **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (under this pass's rules: department copies are HTTP-only, and the HTTPS copy is a gazette print on a non-government host) |

The catalogue's `http://www.sikkimtax.gov.in/...` source URLs should be replaced by, or supplemented with, the PRS
HTTPS gazette copy, with a note that the department host offers no modern TLS.

**Stored:** no SK version.

---

## 15. Puducherry (PY)

| Field | Finding |
|---|---|
| Rates | Maximum half-yearly tax: up to 99,999 nil; 1,00,000–2,00,000 Rs 250; 2,00,001–3,00,000 Rs 500; 3,00,001–4,00,000 Rs 750; 4,00,001–5,00,000 Rs 1,000; ≥5,00,001 Rs 1,250 |
| Effective dates | Panchayats: Puducherry Village and Commune Panchayats (Amendment) Act, 2017 (Act No. 4 of 2017), assent 08-05-2017; Gazette of Puducherry Extraordinary Part II No. 25, 11-05-2017; "come into force with effect from the date of its publication", so **11-05-2017**. Municipalities: Puducherry Municipalities (Amendment) Act, 2017 "dt.09.05.2017 published in the Gazette of Puducherry dt.17.05.2017" per the Finance G.O. The municipal gazette itself was not read |
| Government DDOs | Finance Dept G.O.Ms. No. 24/F3/2017, 04-07-2017: recovery quarterly from the salary of the last month of each quarter (May, August, November, February) |
| Label | **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged: rates are statutory maxima; rates adopted by each council NOT CONFIRMED) |

**Sources**

- https://styandptg.py.gov.in/2017/May/EXTRAORDINARYPART-II/1/25-PART-II%20No%2025%20dated%2011-5-2017.pdf (`dl_58`).
  Verbatim: "SCHEDULE-III [See section 143(2)] Profession Tax … Maximum Half-yearly tax".
- https://finance.py.gov.in/sites/default/files/proftaxgo04jul17.pdf (`dl_57`; this is the "2017 revision G.O." the
  previous pass got a 404 for).
- Note: the G.O. calls the same figures "minimum half yearly rates", while the Act says "Maximum". Legal wording
  conflict.

**Stored:** no PY version.

---

## 16. Bihar (BR)

- Not re-verified beyond searches. The official hosts (`state-tax.bihar.gov.in`, `biharcommercialtax.gov.in`) now fail
  DNS resolution.
- Slabs remain those of the PRS gazette copy of Act 10 of 2011: >3 lakh–5 lakh Rs 1,000; >5 lakh–10 lakh Rs 2,000;
  >10 lakh Rs 2,500 a year.
- Deduction and dates come only from the Rules on legitquest (secondary): deduct from the September salary, deposit
  by 15 November, PT-IV / PT-VIII by 30 November. Simpliance gives the annual return date as 30 November.
- The 2018 Amendment Rules (legitquest) replace Form PT-I. Whether they affect the salary entry is NOT CONFIRMED.
- Label: **OFFICIAL_SOURCE_PARTIALLY_VERIFIED** (unchanged; a gazette print on a non-government host).
- Stored: none.

## 17. North-east, not re-verified in depth

| State | Label (unchanged) | This pass |
|---|---|---|
| TR | OFFICIAL_PRIMARY_SOURCE_VERIFIED | taxes.tripura.gov.in reachable over HTTPS (200). No 2025–26 change found by search. Annual Form VII by 30 April |
| MZ | OFFICIAL_PRIMARY_SOURCE_VERIFIED | zotax.nic.in reachable (200). No change found. Effective-date wording of the 11-07-2024 notification still inconsistent |
| NL | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | nagalandtax.nic.in reachable (200). Monthly due day still NOT CONFIRMED |
| MN | OFFICIAL_SOURCE_PARTIALLY_VERIFIED | manipurvat.gov.in HTTPS certificate **expired** (verify error 10). The previous pass read it over HTTP, which this pass's rules exclude. The facts stand, but their sourcing should be re-done over HTTPS when the certificate is renewed |
| ML | CONFLICTING_SOURCES | No rate notification found. megtaxation.nic.in serves a Let's Encrypt "YR1" chain whose root is not in the local store (unable to get local issuer); not bypassed. Secondary tables still conflict |

## 18. Jurisdictions with no state PT (unchanged)

CG, GA, DL, HR, UP, RJ, HP, UK, JK, LA, CH, AN, DH and LD keep their previous labels (Part C of the previous pass).
- CG: comtax.cg.nic.in now presents a Let's Encrypt (YR1) chain and its leaf expired on 21-10-2026. The 2011 exemption
  notification still cannot be fetched from an official host; CG stays OFFICIAL_SOURCE_PARTIALLY_VERIFIED.
- AR: still **SECONDARY_SOURCE_ONLY**. finance.arunachal.gov.in timed out. The RBI PDF redirected to the RBI home page.
  The CAG "Accounts at a Glance" May-2026 URL returned 404. Search snippets of older RBI appendices show dashes for
  Arunachal under "Taxes on Professions", which is consistent with no levy but secondary as read.

---

## Stored-version verdicts (in.php)

| Version | Verdict | Parameter that differs |
|---|---|---|
| MH v1 2023-04-01 | MATCHES | — (relies on the engine's top-band-only February rule) |
| KA v1 2023-04-01 | MISMATCH (confirmed, W8) | `february_amount` 300 must start 2025-04-01. Minor: bound 24999 against "25,000 and above" |
| TG v1 2014-06-02 | MATCHES | — |
| AP v1 2013-02-01 | Slabs match; date differs (W11 confirmed) | `effective_from` should be 2013-02-06 (G.O.Ms.No. 82 gazette date) |
| WB v1 2013-04-01 | Slabs match; dates differ (W6 confirmed) | `effective_from` 2014-04-01; needs an end date of 2026-09-30 (1406-F.T.) |
| WB v2 2026-10-01 (proposed) | Matches the official draft and secondary final | Blocker: official 1607-F.T. text only |
| TN v1 2018-04-01 | MISMATCH (W9 confirmed and refined) | Model: half-yearly, deducted in August and January, remitted by 30 September and 31 March, band on the previous FY's average half-yearly income. Amounts equal the r.275 default table, not GCC's 2024 table (bands 2–4: 180, 425, 930). Rounding 171 / 208 |
| GJ v1 2022-04-01 | MATCHES (official rate page) | — |
| MP v1 2018-04-01 | MISMATCH (W10 confirmed) | Band 3 is 166, not 167; 12th-month 174 for band 3 missing; `march_amount` 212 valid for band 4 only; bound 33333 |
| KL v1 2016-04-01 | Superseded from 2024-10-01 (W7) | Start date NOT CONFIRMED |
| KL v2 2024-10-01 (proposed) | Amounts = G.O. ÷ 6 | The law levies half-yearly; rates are local-body maxima (s.204(2)); HRA, CCA, conveyance and TA are excluded |

## Access failures (not bypassed)

| Host | Failure | Workaround |
|---|---|---|
| comtax.wb.gov.in, professiontax.wb.gov.in, punjabassembly.gov.in | `unsafe legacy renegotiation disabled` (server lacks RFC 5746) | WebFetch (separate client) for comtax PDFs; the Punjab Assembly PDF timed out in WebFetch |
| www.sikkimtax.gov.in, sikkimtax.gov.in | `unsupported protocol` (legacy TLS only) | PRS gazette copy over HTTPS |
| manipurvat.gov.in | Certificate expired | None this pass |
| comtax.cg.nic.in | Leaf expired 21-10-2026; YR1 chain unverifiable locally | None |
| megtaxation.nic.in | Let's Encrypt YR1 chain: unable to get local issuer | None |
| jharkhandcomtax.gov.in | Hostname mismatch | ctax.jharkhand.gov.in (valid) |
| state-tax.bihar.gov.in, biharcommercialtax.gov.in | DNS resolution failure | PRS and secondary sources |
| finance.arunachal.gov.in | Timeout | None |
| mptax.mp.gov.in, commercialtax.gujarat.gov.in, psdt.punjab.gov.in | Incomplete chain | Intermediate fetched via AIA and verified against the system roots |
| commercialtax.gujarat.gov.in listing pages | Timeout | — |
| apct.gov.in old URLs | Replaced by a Next.js homepage | apvatisold.apct.gov.in (valid TLS) plus ASP.NET postback |
| mptax.mp.gov.in notification list | Needs a POST session; the list did not render | — |
| website.rbi.org.in PDF | Redirect to the home page | — |
| cag.gov.in MKI May-2026 | 404 | — |
| go.lsgkerala.gov.in G.O. signature | ByteRange unparseable | Treated as a gov.in-hosted instrument |
