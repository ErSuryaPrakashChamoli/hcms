# PeopleOS compliance content — legal review pack (D6)

Prepared 2026-10-27 for a qualified Indian labour-law professional.

**Status: NOT REVIEWED.** This pack has been prepared and has not been sent. §4.8 (correcting a finalised month) was
added on 2026-10-28. Decision D6 stays open until a reviewer
completes and signs §6, and the signed review is filed as evidence (§7). Preparing or sending the pack does not
complete D6.

## 1. What we are asking

PeopleOS is HR and payroll software for Indian employers. For each establishment and each month it lists the labour
and payroll compliance obligations it believes apply, generates working papers for them, and computes statutory
deductions from rule versions.

We ask you to review the **legal content** behind this:
- which obligations apply;
- where they apply (state, establishment type, appropriate government);
- from and until when;
- in which statutory form, and with which due dates;
- the rule versions proposed for professional tax, labour welfare fund, bonus and gratuity.

Not in scope:
- the software itself;
- filing on portals (PeopleOS files nothing);
- advice on an individual employee's case.

Everything below is our research, labelled with how far it was verified. None of it has had professional review.

## 2. How to read the material

**Confidence labels**, used on every catalogue entry, threshold and rule version:

| Label | Meaning |
|---|---|
| OFFICIAL_PRIMARY_SOURCE_VERIFIED | The text was read on an official HTTPS government host, or in a PDF whose digital signature validates |
| OFFICIAL_SOURCE_PARTIALLY_VERIFIED | An official source confirms part of it, or a gazette print was read on a non-government host |
| SECONDARY_SOURCE_ONLY | Commentary, consultancy or mirror sites only |
| LEGAL_REVIEW_REQUIRED | The text is known, but its meaning or application needs a lawyer |
| CONFLICTING_SOURCES | Sources disagree |
| UNVERIFIED | No source cited |

**How applicability is decided.** Per establishment and per month, from:
- its state, never the head office's;
- its establishment type;
- the appropriate government under each Code;
- its registrations;
- dated thresholds, measured as the law measures them (for example, the largest number employed on any day);
- dated form versions.

Where a fact is missing, the obligation is marked "configuration required" rather than assumed.

**Rule versions.**
- Rates and ceilings live in dated, immutable rule versions.
- A version marked "proposed" is not used by payroll.
- Activating one needs a second administrator and stored official evidence.

## 3. Documents in the pack

All paths are in the PeopleOS repository unless stated otherwise.

| # | Document | What it holds | What to check |
|---|---|---|---|
| 1 | `docs/compliance-research/state-wise-compliance-matrix.md` | Per state and union territory (36): final or draft Code rules, shops and establishments, PT, LWF, holidays, with labels | Rule status per state; forms; thresholds |
| 2 | `docs/compliance-research/india-compliance-source-register.md` | Every source used, with label, host and date read; §0 counts; §8 the list of unresolved items | Whether each source is the right instrument and still in force |
| 3 | The report catalogue: `india-hr-compliance-catalogue.xlsx`, downloaded from PeopleOS (Compliance reports page → "India compliance catalogue"). Source: `database/data/compliance/reports/in.php`, revision `2026-10-27b`, 188 entries | Each obligation: law, form, frequency, due date, applicability conditions, dated variants and thresholds, labels and sources | Applicability conditions, forms and due dates |
| 4 | `docs/compliance-research/statutory-rule-change-register.md` | P1–P11 proposed rule versions; W1–W11 stored versions found wrong; N1–N12 proposed warnings | §5 below asks for a decision on each P |
| 5 | `database/data/compliance/in.php` | The stored rule pack (rates, ceilings, dates) | The values behind §5 |
| 6 | `docs/compliance-research/compliance-owner-decisions.md` §1 and §4 | A1–A11 (rule-version decisions) and B1–B13 (legal questions) | §4 below repeats the questions |
| 7 | `docs/compliance-research/compliance-gap-assessment.md` | What the product covers and does not | Missing obligations |
| 8 | `docs/compliance-research/evidence/2026-10-27*-verify-*.md` | The verification notes: URLs, quotes, access failures | Source by source, where needed |
| 9 | `docs/compliance-research/compliance-production-readiness.md` §8 | The explicit list of unverified obligations | Anything you can settle |
| 10 | `docs/compliance-research/owner-decision-register-a-series.md` | A1–A11 in plain language: question, current behaviour, evidence, options, deadlines | Context for §4.4–4.5 and §5 |
| 11 | `docs/compliance-research/evidence/2026-10-27c-time-sensitive.md` | The final re-check of the Odisha, West Bengal, Haryana and bonus items, with URLs and quotes | §4.7 |
| 12 | On request: the raw downloads (about 4.3 GB, outside the repository) | Copies of the pages and PDFs read | — |

## 4. Questions that need a legal determination

For each question, please give an answer, the authority relied on (section, rule, notification, judgment), and any
condition or exception.

### 4.1 Labour Codes: applicability and appropriate government

| # | Question | What PeopleOS does today |
|---|---|---|
| B4 | For each state: are its final rules under each Code notified, and so which register forms apply to establishments where the State is the appropriate government? Where only draft rules exist, do the earlier Acts' registers continue? | State registers use a PeopleOS layout with the central particulars, marked for legal review |
| B5 | Does the Employment Information Return (Form XXVI, SS (Central) Rules r.56) reach private establishments now? r.56(6) is unconditional; r.56(1)(b) and (5)(b) depend on notification | Pending verification |
| B6 | Is the ESIC half-yearly return still a filing (ESIC's guide says it is generated from monthly contributions)? Do the ESI regulations continue under the Code (s.164(2)(b) is not in force)? | Kept as an internal check, not a filing |
| B9 | Code on Social Security s.2(3)(a): does a company with branches in several states make every establishment Central for social security? | The customer sets the appropriate government; PeopleOS does not infer it |
| B12 | Do the state national and festival holidays Acts (Karnataka 1963, Tamil Nadu 1958, Kerala 1958, Andhra Pradesh 1974) survive the OSH Code, and which forms do they prescribe? | Holiday list unverified |
| L1 | Unified annual return in Gujarat, Arunachal Pradesh, Jammu & Kashmir and Mizoram: the rule says 1 February, the form says 28/29 February. Which governs? | Shown as conflicting |

### 4.2 Bonus (Code on Wages, 2019; Payment of Bonus Act, 1965 until 20 Nov 2025)

| # | Question | What PeopleOS does today |
|---|---|---|
| B1 | Do the central notifications S.O. 4710(E) and 4711(E) bind establishments where the State is the appropriate government? No State notification under Code on Wages s.26 was found | Results marked legally unverified |
| B2 | Which minimum wage sets the calculation ceiling ("₹7,000 or the minimum wage fixed by the Central Government, whichever is higher"): which category, which area, and the daily-to-monthly conversion? | Computed at ₹7,000 and marked pending determination |
| B3 | Which law governs bonus for FY 2025-26, which straddles the Act (to 20 Nov 2025) and the Code (from 21 Nov 2025)? | Each month measured under the version then in force; flagged for review |
| L2 | Is S.O. 4711(E) (eligibility ₹21,000 a month) authentic and in force? We have read it only on a mirror site | Proposed version P2 waits on it |

### 4.3 Gratuity (Code on Social Security, 2020, Ch. V; Payment of Gratuity Act, 1972 until 20 Nov 2025)

| # | Question | What PeopleOS does today |
|---|---|---|
| L3 | Does the ₹20 lakh ceiling (S.O. 1420(E), 29-03-2018) continue under the Code through s.164(2)(a), absent a notification under s.53(3)? | Ceiling applied provisionally; P3 and P4 not activated |
| B10 | Do four years and 240 days in the last year count as five years? Several High Courts have held so; there is no Supreme Court ruling squarely on it | Strict five years; such cases are "pending determination" |
| B11 | Does the s.2(88) 50% add-back apply to gratuity wages, and which allowances count? | Shown as a review with the alternative amount, never applied |
| B13 | Is the 1987 notification of the late-payment interest rate still in force under the Code (s.56(4))? | Not computed |
| L4 | Coverage: First Schedule Ch. V and s.1(8) as we apply them (factories, mines and plantations always; other establishments from ten employees on any day; once covered, always). Fixed-term employees: one year from 8 May 2026 (SS (Central) Rules r.33). Forfeiture under s.53(6) left to a person | Applied as stated, provisionally |

### 4.4 Professional tax

| # | Question | What PeopleOS does today |
|---|---|---|
| A3 | West Bengal: the final Schedule No. 1607-F.T. (16-09-2026): employee slabs from 1 Oct 2026, and the date of Part C | Old slabs still in payroll; new version P10 waits on the official text |
| A8 | Odisha: has the Repeal Act received assent? If not, does PT revive when Ordinance 02 of 2026 ceases (about 3 Nov 2026: six weeks after the Assembly reassembled on 22 Sep 2026, our calculation), and from when? | No Odisha PT deducted from April 2026 |
| A4 | Kerala: are the G.O.(Rt) 1149/2024 rates maximums that each local body must adopt, and has the Profession Tax Rules amendment been made? | Old rates; P11 not activated |
| A10 | Tamil Nadu: the TN Urban Local Bodies Rules, 2023 rr.274–278 (half-yearly; band on the previous year's average half-yearly income; deducted in August and January). Rates outside Greater Chennai, panchayat levies, and exemptions (s.117-H) | Monthly deduction on the default table, marked wrong in structure |
| A11 | The other levying states (Assam, Bihar, Jharkhand, Puducherry, Punjab development tax, Tripura, Sikkim, Nagaland, Mizoram, Manipur, Meghalaya): current Acts, rates and periods | No PT computed there |
| W6–W11 | Corrections of stored versions: WB start 1 Apr 2014, KL structure, KA February ₹300 from 1 Apr 2025, TN structure, MP third band ₹166, AP from 6 Feb 2013 | Warnings proposed, not created |

### 4.5 Labour welfare fund

| # | Question | What PeopleOS does today |
|---|---|---|
| A1 | Karnataka: ₹50 / ₹100 from 10 Jan 2025 (Act 05 of 2025), and the "ten or more persons" coverage from 7 Jan 2026 (Act 05 of 2026) | ₹20 / ₹40 still in payroll; P5 proposed |
| A2 / B8 | Haryana: 0.2% of wages with caps ₹25 / ₹31 / ₹34 / ₹35 and employer twice; does the ten-employee threshold apply only to shops? | Flat ₹31 still in payroll; P6–P9 proposed |
| A9 | Tamil Nadu: June wages (Rules r.12) or year ending December (Form A and the board)? | December |
| B7 | Kerala: the LWF Act, 1975, or the Shops and Commercial Establishments Workers Welfare Fund? | Legal review required |
| W1–W5 | Corrections of stored versions for Maharashtra, Tamil Nadu, Gujarat, Haryana and Karnataka | Warnings proposed, not created |

### 4.6 Other unresolved items

`compliance-production-readiness.md` §8 lists every other unsettled fact: state Code rule gazette dates, forms not
cited by any rule, PoSH report deadlines, and checklist items with no source. Please mark any you can settle.

### 4.7 Effective dates to confirm

| # | Date in question | Our position (27-10-2026) |
|---|---|---|
| E1 | Odisha: when Ordinance 02 of 2026 ceases to operate, and what follows for professional tax | About 3 Nov 2026: six weeks from the Assembly's reassembly on 22 Sep 2026 (official notice). Our calculation; Art. 213 not re-read (India Code unavailable) |
| E2 | West Bengal: start of the new salary slabs, and of Part C | Salary slabs 1 Oct 2026 (1406-F.T., official). Part C: 1 Oct 2026 in the draft, 1 Apr 2027 in secondary reports of the final |
| E3 | Bonus for FY 2025-26: last date to pay | 30 Nov 2026: Code on Wages s.39(1) and s.2(a), read on India Code |
| E4 | Haryana LWF 2026: last date to deposit, and the start of the ₹35 cap | Before 31 Dec 2026 (s.9A(2)); the cap from January 2026 (department's table) |
| E5 | Karnataka LWF: start of ₹20 / ₹40 (6 Apr 2017) and of ₹50 / ₹100 (10 Jan 2025) | Law department consolidation |
| E6 | Gratuity: the Code from 21 Nov 2025, and fixed-term one year from 8 May 2026 | Primary texts; the ceiling's continuity is L3 |
| E7 | The start dates of each state's final Code rules used for register forms | `india-compliance-source-register.md` §8 and the state matrix |

### 4.8 Correcting a finalised month (added 2026-10-28; PeopleOS decision C12-D1)

**The situation.** An establishment's statutory set-up was wrong for a month whose payroll is already finalised and
whose returns may already be filed. For example:
- EPF was deducted where the establishment was not covered;
- ESI was not deducted where it applied.

PeopleOS keeps the finalised month as issued. The owner must choose how the difference is settled; the options are a
later-month adjustment, a supplementary run, or settlement outside PeopleOS. We ask only about the law; please answer
per statute and per direction (amount over-deducted or under-deducted).

| # | Question |
|---|---|
| S1 | **EPF.** Where contributions were remitted for a wage month that was not covered, may the employee share be refunded through a later month's payroll? How is the remitted amount (employee share, employer share, charges) recovered, and is a revised return for the original wage month required instead? |
| S2 | **EPF and ESI.** Where a contribution that was due was not paid, must it be paid against the original wage month or contribution period, rather than as a later-month adjustment? Which interest and damages apply, and can any part be recovered from the employee? |
| S3 | **ESI.** Is excess ESI deducted from employees refundable by the employer, and how is the excess paid to ESIC treated? |
| S4 | **Professional tax and labour welfare fund**, per state in which PeopleOS computes them. May an excess deduction be refunded through payroll? Is a revised return or a fresh challan needed? Does the answer differ by state? |
| S5 | **TDS on salary.** May an over- or under-deduction be evened out in later months of the same financial year? When is a correction statement for the quarterly return required? |
| S6 | **Records.** What must the employer keep to show the correction, for example the original and revised returns and the communication to employees? |

PeopleOS applies no settlement until the owner decides, informed by these answers.

## 5. Proposed rule versions: approve or reject

Please mark each **Approve**, **Approve with correction** (state it) or **Reject**, with the effective date you
confirm. Details are in the rule-change register, rows P1–P11.

| # | Rule | Version and dates | Content (summary) | Our label | Your decision | Effective date confirmed |
|---|---|---|---|---|---|---|
| P1 | Bonus (Payment of Bonus Act) | v1, 2014-04-01 → 2025-11-20 | eligibility ₹21,000; calculation ₹7,000 or minimum wage if higher; 8⅓% (min ₹100) to 20% | Partial | | |
| P2 | Bonus (Code on Wages, central sphere) | v2, 2025-11-21 → | as P1, minimum wage fixed by the Central Government | Partial | | |
| P3 | Gratuity (Code on Social Security) | v2, 2025-11-21 → | 15/26 per year; five years; ceiling ₹20 lakh | Partial | | |
| P4 | Gratuity (Code + SS (Central) Rules r.33) | v3, 2026-05-08 → | as v2; fixed-term one year | Partial | | |
| P5 | LWF Karnataka | v2, 2025-01-10 → | ₹50 / ₹100, December | Primary | | |
| P6 | LWF Haryana | v2, 2020-01-01 → 2022-12-31 | 0.2% of wages, cap ₹25, employer 2× | Primary | | |
| P7 | LWF Haryana | v3, 2023-01-01 → 2024-12-31 | cap ₹31 | Partial | | |
| P8 | LWF Haryana | v4, 2025-01-01 → 2025-12-31 | cap ₹34 | Partial | | |
| P9 | LWF Haryana | v5, 2026-01-01 → | cap ₹35 | Partial | | |
| P10 | PT West Bengal | v2, 2026-10-01 → | nil to ₹20,000; ₹100 / ₹140 / ₹170 / ₹208 | Partial | | |
| P11 | PT Kerala | v2, 2024-10-01 → | 2024 half-yearly maximums as monthly equivalents | Legal review | | |

Approval here does not activate a version. Each approved version is still verified in PeopleOS by two platform
administrators, against stored official evidence.

## 6. Sign-off

> To be completed by the reviewer. A review is recorded as complete only when this section is filled in, signed and
> filed.

| Field | Entry |
|---|---|
| Reviewer name | |
| Qualification and enrolment (Bar Council and number, or firm and designation) | |
| Firm or organisation | |
| Engagement reference | |
| Review period (from – to) | |
| Jurisdictions reviewed (central; list each state and union territory) | |
| Laws and instruments reviewed | |
| Sources examined (the pack documents, plus any others relied on) | |
| Questions answered (B1–B13, L1–L4, E1–E7, S1–S6, A1–A11 items in §4) | Answers attached as: |
| Rule versions approved (P-numbers, with confirmed effective dates) | |
| Rule versions approved with correction (P-number: correction) | |
| Rule versions rejected (P-number: reason) | |
| Catalogue entries or matrix rows requiring correction | |
| Exceptions and assumptions | |
| Items outside the reviewer's competence or not reviewed | |
| Review date | |

**Approval status** (tick one):
- [ ] Approved without exceptions, for the jurisdictions and items listed above.
- [ ] Approved with the exceptions and corrections listed above.
- [ ] Not approved. Corrections are required before use.
- [ ] Partial review only (scope as listed).

Signature: ____________________ Date: ____________

## 7. Delivery (for PeopleOS)

**Status: ready to send, not sent.** The next action is the owner's:
1. Name the reviewer: a qualified Indian labour-law professional, firm and engagement.
2. Authorise sending. PeopleOS sends nothing outside without that.

Then send this document with items 1–11 of §3. Download the catalogue as a spreadsheet from the Compliance reports
page. Leave the raw downloads (item 12) for the reviewer to ask for.

The pack holds no credentials and no personal data of employees or customers.

### 7.1 Choosing the reviewer (checklist for the owner)

- [ ] **Qualified to practise Indian labour and employment law.** An advocate enrolled with a State Bar Council, or a
      firm whose named partner is. The enrolment number is recorded in the sign-off (§6).
- [ ] **Experience with each subject in the pack:**
  - [ ] the four Labour Codes and their central and state rules;
  - [ ] EPF and ESI;
  - [ ] professional tax and labour welfare fund in the states the customers operate in;
  - [ ] bonus and gratuity.
- [ ] **Covers the jurisdictions needed now:**
  - Odisha, West Bengal and Haryana, which are time-sensitive;
  - Karnataka, Tamil Nadu and Kerala;
  - the central sphere;
  - or the reviewer names a correspondent for any state outside their practice.
- [ ] **Can answer the time-sensitive questions first**, within days:
  - E1 (Odisha, before 3 Nov 2026);
  - E2 / A3 (West Bengal);
  - E3 (bonus, before 30 Nov 2026);
  - E4 (Haryana, before 31 Dec 2026).
- [ ] **No conflict of interest** with PeopleOS or its customers.
- [ ] **Engagement letter** settling scope (§1), deliverables (the §6 sign-off, plus written answers to §4 and §5),
      fees, timeline, confidentiality, and that the advice may be relied on in the product's documentation.
- [ ] **Confidentiality.** The pack holds no personal data; the catalogue and decision papers are commercially
      confidential.
- [ ] **Owner authorisation to send** recorded, with the date and the person authorising.

### 7.2 Cover message (draft: not sent)

> **Subject:** Review of Indian labour-law compliance content in PeopleOS — engagement request
>
> Dear [Name],
>
> PeopleOS is HR and payroll software for employers in India. It lists, for each establishment and month, the labour
> and payroll compliance obligations it believes apply, and prepares working papers for them. It also computes
> statutory deductions from dated rule versions. Before we rely on any of this in production, we would like a
> qualified labour-law professional to review the legal content behind it.
>
> We are asking for a review of our research, not of software:
> - which obligations apply, where and from when;
> - the prescribed forms and due dates;
> - the proposed rule versions for professional tax, labour welfare fund, bonus and gratuity;
> - a list of specific legal questions.
>
> We have labelled every fact with how far we verified it, and we claim no legal accuracy until your review is
> complete.
>
> Four items are time-sensitive, and we would be grateful for an early view:
> - Odisha professional tax after Ordinance 02 of 2026: whether the Repeal Act is in force, and what follows if the
>   Ordinance ceases on or about 3 November 2026;
> - the West Bengal professional tax schedule from 1 October 2026;
> - the bonus payable for FY 2025-26 (by 30 November 2026), and which ceilings apply;
> - the Haryana labour welfare fund contribution for 2026.
>
> The enclosed review pack explains the material, lists every question, and includes a sign-off form. The pack holds
> no personal data.
>
> Could you let us know whether you could take this on, which jurisdictions you can cover yourself, and your proposed
> timeline and fees? We would then send an engagement letter.
>
> Yours sincerely,
> [Name, role]
> [Organisation, contact details]
>
> Enclosures: the review pack (this document), and documents 1–11 listed in its §3.

Placeholders in square brackets are for the owner to fill in. **Nothing has been sent, and no reviewer has been
contacted.** Sending needs the owner's explicit authorisation (§7).

## 8. After the review (for PeopleOS)

1. File the signed review, and every document it relies on, as
   `docs/compliance-research/evidence/legal-review-<date>.md`, with the signed copy kept outside the repository.
2. Record each answer against its question in `compliance-owner-decisions.md` §4 and the matrix or register row it
   affects.
3. For each approved rule version:
   - attach the official evidence;
   - a second platform administrator verifies it through `RuleVerifications::verify()`.
4. Make corrections as new versions and catalogue revisions; never as edits to stored versions.
5. Mark items PROFESSIONAL_LEGAL_REVIEW_COMPLETE only where the signed review covers them, and only then mark D6
   complete, for the reviewed scope.
