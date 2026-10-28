# Owner decision register: statutory rule decisions A1–A11

Prepared 2026-10-27 for the business owner. It sums up the eleven rule decisions in plain language. The full decision
papers, with evidence, are in `compliance-owner-decisions.md` §1. The sources are in `india-compliance-source-register.md`
and `evidence/`.

**Nothing here is decided.** No rule has been switched on.
- Every "recommended" option is a **recommendation**, not an owner decision.
- Every deadline is a **reported deadline**, with how firmly it is sourced. None is a legal conclusion until a
  qualified Indian labour-law professional confirms it (D6).
- Status on 2026-10-28: still undecided. As of that date A8 is about 6 days away, A3 is overdue in effect (October
  salaries), and A5 is 33 days away. Each item now states the consequence of delay.

Each item keeps two questions apart:
- what **the software** does (engineering, tested);
- whether that is **right in law** (evidence, and a lawyer's view where marked).

Switching a rule on always needs two platform administrators and stored official evidence, and is never done by hand
in the database.

## At a glance

| # | Topic | Reported deadline (how sure) | Recommendation (not a decision) | Lawyer needed? |
|---|---|---|---|---|
| A8 | Odisha professional tax | **About 3 Nov 2026**: the repeal Ordinance stops having effect unless the Repeal Act is in force. **Needs confirmation**: calculated from the official reassembly date; the Article itself was not re-read, and the Act was not found | Ask the Odisha Law or Commercial Tax Department this week; prepare an inactive tax version in case it is needed | Yes: what happens if the Ordinance lapses first |
| A3 | West Bengal professional tax | **Now:** new slabs apply to October 2026 salaries (the start date is official). **Needs confirmation**: the slabs themselves, since the final Schedule is not on an official site | Get the final Schedule 1607-F.T. from the Finance Department; warn payroll users meanwhile | Only if acting on the draft |
| A5 | Bonus | **30 Nov 2026** for FY 2025-26 (the time limit is official, Code on Wages s.39 and s.2(a)). **Needs counsel** on which law and ceilings apply (B1–B3) | Keep bonus results provisional; get counsel's answers on B1–B3 now | Yes |
| A2 | Haryana labour welfare fund | **Before 31 Dec 2026** for 2026, then 12% interest (the department's official consolidated Act; the amending Act was not read). **Needs confirmation** of the 2026 notification | Switch on the 2020–2025 versions; switch on 2026 once the notification is obtained, or on the department's official table if it is not obtained in time | Partly (B8) |
| A1 | Karnataka labour welfare fund | December 2026 deduction, paid by **15 Jan 2027** (earlier evidence) | Switch on the new rates, and correct the old version's start date | No |
| A6 | Gratuity | **Ongoing:** every exit since 21 Nov 2025 | Ask counsel to confirm the ₹20 lakh ceiling, then switch on the Code versions | Yes |
| A7 | Warnings on known-wrong rules | Ongoing | Create all twelve warnings | No |
| A9 | Tamil Nadu labour welfare fund month | Next deduction December 2026 (current setting) | Ask the Tamil Nadu board in writing; keep December meanwhile | No (a factual question for the board) |
| A10 | Tamil Nadu professional tax | January 2027 pay bill; paid by 31 Mar 2027 (official rules) | Build the half-yearly, per-local-body model | Partly (exemptions, local rates) |
| A4 | Kerala professional tax | Half-year ending February 2027 | Same model as A10; do not switch on the 2024 version as it is | Yes |
| A11 | Professional tax in eleven other states | Ongoing: none computed there | Add Assam, Tripura and Mizoram first | Yes for Punjab and Meghalaya |

## A1 — Karnataka labour welfare fund

- **Question.** Switch on the new Karnataka rates (₹50 employee, ₹100 employer, from 10 Jan 2025), and correct the old
  version's start date to 6 Apr 2017?
- **Where.** Karnataka.
- **Software today.** Deducts the old ₹20 / ₹40 each December. The new version exists but is switched off.
- **Evidence.** Official: Karnataka Act 05 of 2025, and the law department's dated history of the rates. Act 05 of
  2026 widens coverage to ten or more persons from 7 Jan 2026.
- **Legal uncertainty.** None on the rates. PeopleOS does not check the ten-person coverage test.
- **Options.**
  - Switch on, and correct the start date (recommended).
  - Switch on only.
  - Wait.
- **If accepted.** The December 2026 deduction is right. If PeopleOS ran December 2025, that year was underpaid by
  ₹30 / ₹60 per person, and arrears may be due.
- **If rejected.** Underpayment continues.
- **Work.** Data only, plus tests.
- **Deadline.** Paid by 15 Jan 2027 (earlier evidence).
- **Consequence of delay.** If not switched on before the December 2026 deduction (paid by 15 Jan 2027), Karnataka deducts ₹20 / ₹40 instead of ₹50 / ₹100 for another year, and the underpayment and possible arrears grow.
- **Evidence still needed.** None for the rates. Optional: whether the ten-person coverage test (C11) should be checked in payroll.
- **Qualified legal reviewer needed to resolve it?** No.
## A2 — Haryana labour welfare fund

- **Question.** Replace the flat ₹31 with the legal formula: 0.2% of wages, capped (₹25 / ₹31 / ₹34 / ₹35 by year),
  with the employer paying twice?
- **Where.** Haryana.
- **Software today.** Flat ₹31 / ₹62 every month. The software already supports the formula, and it is tested.
- **Evidence.** Official: Haryana Act 18 of 2019, and the department's contribution table, which shows ₹35 for
  January 2026.
- **Not found on a government site:** the 2026 notification HLWB/REV/2026/3436. The Haryana e-Gazette failed its
  security check today; it was not bypassed.
- **Legal uncertainty.** Whether the ten-employee threshold applies only to shops (B8), and the legal form of the
  yearly cap revisions.
- **Options.**
  - (a) Switch on 2020–2025 now, and 2026 once the notification is obtained (recommended).
  - (b) Switch on all four on the department's table.
  - (c) Wait.
- **If accepted.** Correct deductions. Low earners pay less, and high earners slightly more.
- **If rejected.** Wrong amounts continue, and the 2026 contribution may be wrong when it falls due.
- **Work.** Data only.
- **Deadline.** Before 31 Dec 2026, with 12% interest after a month (official department text).
- **Consequence of delay.** Each month the flat ₹31 / ₹62 continues. If the 2026 version is not settled before 31 Dec 2026, the 2026 contribution may be wrong when due, and late deposit attracts interest (12% in the department's text).
- **Evidence still needed.** The official copy of HLWB/REV/2026/3436, or the owner's acceptance of the department's official table instead; counsel on B8 (does the ten-employee threshold apply only to shops?).
- **Qualified legal reviewer needed to resolve it?** Partly: B8, and the legal form of yearly cap revisions.
## A3 — West Bengal professional tax

- **Question.** Switch on the new slabs from 1 Oct 2026 (nothing up to ₹20,000, then ₹100 / ₹140 / ₹170 / ₹208),
  and end the old slabs on 30 Sep 2026?
- **Where.** West Bengal.
- **Software today.** Old slabs. Employees earning ₹10,001–₹20,000 are being charged ₹110–₹130 a month they no longer
  owe.
- **Evidence.**
  - Official: the start notification 1406-F.T., and the draft Schedule.
  - Not found on any government site today: the **final** Schedule 1607-F.T. Its slabs are known from secondary
    sources, which match the draft.
- **Legal uncertainty.** None on the start date. Only the final text is missing.
- **Options.**
  - (a) Get 1607-F.T. from the Finance (Revenue) Department, then switch on (recommended, urgent).
  - (b) Switch on using the draft.
  - (c) Wait.
- **If accepted.** Correct deductions from October, plus refunds for any October over-deduction.
- **If rejected.** Over-deduction continues every month.
- **Work.** Data only, plus a warning for users meanwhile.
- **Deadline.** Now.
- **Consequence of delay.** Every month of delay over-deducts ₹110–₹130 from each West Bengal employee earning ₹10,001–₹20,000, and the refunds owed grow.
- **Evidence still needed.** The official text of Schedule 1607-F.T. (Kolkata Gazette, reported SAR-588), from the Finance (Revenue) Department, including Part C's date.
- **Qualified legal reviewer needed to resolve it?** Only if the owner chooses to act on the draft before the official text is found.
## A4 — Kerala professional tax

- **Question.** Bring Kerala in line with the law. The tax is half-yearly, set by each local body within a maximum,
  and certain allowances are excluded.
- **Where.** Kerala.
- **Software today.** A monthly, state-wide deduction. That is wrong in structure, and the software cannot yet model
  the right one.
- **Evidence.** Official: the Panchayat Raj Act's rules on maximums, half-years and exclusions.
- **Not found:** proof that the 2024 maximums were adopted.
- **Legal uncertainty.** Yes: the adoption of the 2024 rates, and municipal rules.
- **Options.**
  - Build the half-yearly, per-local-body model (recommended).
  - Switch on the 2024 version as it is (not recommended).
  - Keep the current version with a warning.
- **If accepted.** Correct Kerala PT, after an engine change.
- **If rejected.** Deductions keep differing from what local bodies demand.
- **Work.** An engine and data change, shared with A10.
- **Deadline.** The half-year ending February 2027.
- **Consequence of delay.** The half-year ending February 2027 is computed on the wrong, monthly model and will differ from what local bodies demand.
- **Evidence still needed.** Proof that the 2024 maximums were adopted (a Profession Tax Rules amendment, and local-body resolutions); municipal provisions and due dates.
- **Qualified legal reviewer needed to resolve it?** Yes.
## A5 — Bonus

- **Question.** Make bonus results final instead of provisional, once counsel answers three questions:
  - B1: do the central notifications bind state-sphere employers?
  - B2: which minimum wage sets the calculation ceiling?
  - B3: which law covers FY 2025-26, which straddles the old Act and the Code?
- **Where.** All states.
- **Software today.** The bonus register calculates every result, but marks each one "legally unverified". Payroll
  does not pay bonus automatically.
- **Evidence.**
  - Official: the calculation-ceiling notification S.O. 4710(E); the 2026 central minimum wages; and the 8-month
    payment limit (Code on Wages s.39, read today on India Code).
  - Eligibility notification S.O. 4711(E) (₹21,000): read only on a copy, not a government site.
- **Legal uncertainty.** Yes: B1–B3. B2 decides most amounts. At ₹7,000 the bonus can be about a third of what the
  minimum-wage ceiling gives.
- **Options.**
  - (a) Obtain S.O. 4711(E), get counsel's answers, hold minimum wages in PeopleOS, then switch on (recommended).
  - (b) Switch on the old Act's version only.
  - (c) Keep everything provisional (what happens now).
- **If accepted.** Decisive bonus figures.
- **If rejected.** Customers pay FY 2025-26 bonus on provisional figures, with a risk of underpayment.
- **Work.** Data, plus holding minimum wages (C3).
- **Deadline.** FY 2025-26 bonus is payable by **30 Nov 2026** (official).
- **Consequence of delay.** Bonus for FY 2025-26 falls due by 30 Nov 2026. Without answers, customers pay on provisional figures, at risk of underpayment.
- **Evidence still needed.** S.O. 4711(E) read on egazette.gov.in; counsel's answers to B1–B3; a source of minimum wages by category and area (C3).
- **Qualified legal reviewer needed to resolve it?** Yes.
## A6 — Gratuity

- **Question.** Switch on the gratuity rules under the Code on Social Security, so that full and final settlements
  add gratuity automatically?
- **Where.** All-India.
- **Software today.** Calculates gratuity at exit, but marks it provisional and adds no line to the settlement. A
  person adds it by hand, and the software prevents adding it twice.
- **Evidence.** Official: the Code's gratuity chapter, coverage and the fixed-term rule. The ₹20 lakh ceiling is
  confirmed only indirectly.
- **Legal uncertainty.** Yes:
  - does the 2018 ceiling continue under the Code?
  - do four years and 240 days count as five years (B10)?
  - the 50% wage rule (B11);
  - the late-payment interest rate (B13).
- **Options.**
  - (a) Counsel confirms the ceiling, then switch on (recommended).
  - (b) Wait for a new notification.
  - (c) Switch on the fixed-term version only.
- **If accepted.** Automatic gratuity for clear cases. Unclear cases still go to a person.
- **If rejected.** Every settlement needs a manual gratuity line, which risks omission.
- **Work.** Data only.
- **Deadline.** Ongoing. Gratuity is due within 30 days.
- **Consequence of delay.** Every exit keeps needing a manual gratuity line, with the risk of omission. Gratuity is due within 30 days of exit.
- **Evidence still needed.** Counsel's opinion that the ₹20 lakh ceiling continues under s.164(2)(a); answers to B10, B11 and B13.
- **Qualified legal reviewer needed to resolve it?** Yes.
## A7 — Warnings on rules known to be wrong

- **Question.** Create the twelve warnings (N1–N12) that tell payroll users a stored rule is out of date?
- **Where.** Labour welfare fund in Maharashtra, Tamil Nadu, Gujarat, Haryana and Karnataka. Professional tax in West
  Bengal, Kerala, Karnataka, Tamil Nadu, Madhya Pradesh and Andhra Pradesh.
- **Software today.** No warnings. The out-of-date rules keep working silently.
- **Evidence.** Each correction is sourced (W1–W11).
- **Legal uncertainty.** Little. A warning changes no figure.
- **Options.**
  - All twelve (recommended).
  - Only those affecting current payroll.
  - None.
- **If accepted.** Users see the problems. Under the production checks, those states show as not verified. That is
  intended.
- **If rejected.** The errors stay invisible.
- **Work.** Data only.
- **Deadline.** None. The sooner the better.
- **Consequence of delay.** Known-wrong rules keep working without any warning to payroll users.
- **Evidence still needed.** None: each correction is sourced (W1–W11).
- **Qualified legal reviewer needed to resolve it?** No.
## A8 — Odisha professional tax

- **Question.** Has the Repeal Act become law? If not, prepare a professional tax version in case the tax revives.
- **Where.** Odisha.
- **Software today.** No Odisha tax deducted since April 2026, which matches the repeal Ordinance.
- **Evidence.**
  - Official: the repeal Ordinance (in force from 1 Apr 2026), and the Assembly's notice that its session began on
    22 Sep 2026.
  - Secondary only: news that the Repeal Bill passed.
  - Not found anywhere official: assent, an Act number, or a gazette.
- **Legal uncertainty.** Yes: what happens if the Ordinance stops having effect before the Act is in force.
- **Options.**
  - (a) Confirm with the department, and (b) prepare an inactive version now (recommended).
  - (c) Wait.
- **If accepted.** Ready either way.
- **If rejected.** If the tax revives, PeopleOS under-deducts from the date it revives.
- **Work.** Data only (an inactive version).
- **Deadline.** About 3 Nov 2026. This is calculated; the constitutional text could not be re-read today because
  India Code timed out.
- **Consequence of delay.** If the Ordinance ceases around 3 Nov 2026 without the Repeal Act in force and the tax revives, PeopleOS under-deducts from that date, with no version ready.
- **Evidence still needed.** Assent, Act number and Gazette publication of the Odisha Repeal Act (Law or Commercial Tax Department); the text of Art. 213(2)(a) on an official host; counsel on what follows if the Ordinance ceases first.
- **Qualified legal reviewer needed to resolve it?** Yes.
## A9 — Tamil Nadu labour welfare fund: which month

- **Question.** Is the deduction made on June wages (the rules) or for the year ending December (the official form and
  the board)?
- **Where.** Tamil Nadu.
- **Software today.** December.
- **Evidence.** The amounts are official. The sources conflict on the month.
- **Legal uncertainty.** A factual question for the board.
- **Options.**
  - Write to the board (recommended).
  - Keep December.
  - Switch to June.
- **If accepted.** A verified version.
- **If rejected.** A risk of deducting in the wrong month (the amounts are the same).
- **Work.** Data only.
- **Deadline.** The next deduction is December 2026 under the current setting.
- **Consequence of delay.** The December 2026 deduction may fall in the wrong month (the amounts are the same).
- **Evidence still needed.** A written answer from the Tamil Nadu Labour Welfare Board.
- **Qualified legal reviewer needed to resolve it?** No: a factual question for the board.
## A10 — Tamil Nadu professional tax

- **Question.** Model Tamil Nadu professional tax as the law sets it?
  - half-yearly, per local body;
  - deducted in August and January;
  - based on the previous year's average income.
- **Where.** Tamil Nadu (the same model serves Kerala and Puducherry).
- **Software today.** A monthly deduction on a default table. That is wrong in structure, and too low in Chennai since
  October 2024.
- **Evidence.** Official: the 2023 Urban Local Bodies Rules.
- **Legal uncertainty.** Partly: rates outside Chennai, and exemptions.
- **Options.**
  - Build the model (recommended).
  - Keep the current version with a warning.
  - Stop computing it.
- **If accepted.** Correct amounts each half-year, after an engine change.
- **If rejected.** Wrong amounts each half-year.
- **Work.** An engine and data change.
- **Deadline.** The January 2027 pay bill; paid by 31 Mar 2027.
- **Consequence of delay.** The January 2027 deduction is computed on the wrong, monthly model, and too low in Chennai.
- **Evidence still needed.** Rates of councils and panchayats outside Greater Chennai; the s.117-H exemptions.
- **Qualified legal reviewer needed to resolve it?** Partly: exemptions and local rates.
## A11 — Professional tax in eleven other states

- **Question.** Add professional tax for Assam, Bihar, Jharkhand, Puducherry, Punjab, Tripura, Sikkim, Nagaland,
  Mizoram, Manipur and Meghalaya?
- **Where.** Those states.
- **Software today.** No tax is computed there; their statements say so.
- **Evidence.**
  - Official: Assam, Tripura, Mizoram.
  - Partial: the others.
  - Legal review: Punjab.
  - Conflicting: Meghalaya.
- **Legal uncertainty.** Yes for Punjab and Meghalaya.
- **Options.**
  - Assam, Tripura and Mizoram first (recommended).
  - All at once.
  - None.
- **If accepted.** Deductions start once each version is verified.
- **If rejected.** Employers there get no deduction from PeopleOS.
- **Work.** Data. States that collect yearly or quarterly need an engine change.
- **Deadline.** None set; the gap is ongoing.
- **Consequence of delay.** Employers in the eleven states keep getting no deduction from PeopleOS and must compute it by hand.
- **Evidence still needed.** Each state's Act and rates on an official host; Punjab's income test; Meghalaya's conflicting sources.
- **Qualified legal reviewer needed to resolve it?** Yes for Punjab and Meghalaya.
