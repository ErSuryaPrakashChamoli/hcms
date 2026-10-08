<?php

/*
 | Regulatory change notices (Phase 6), India. Official evidence that named rule versions are out of date
 | or wrongly based, where the replacement parameters are not yet established from the legal text.
 | Loaded by `peopleos:compliance:sync` (insert-only; content immutable). While open, a notice
 | blocks verification of the affected versions and, under enforcement, payroll on or after its
 | effective date. It is resolved only by linking a new rule version (Compliance → Regulatory notices).
 */
return [
    [
        'jurisdiction' => 'IN',
        'code' => 'EPF',
        'state' => null,
        'affects_versions' => [1],
        'effective_date' => '2026-09-17',
        'title' => 'EPF wage ceiling raised from ₹15,000 to ₹25,000 per month (S.O. 5109(E))',
        'summary' => 'The Ministry of Labour & Employment announced that the wage ceiling for mandatory EPFO coverage rises from ₹15,000 to ₹25,000 per month with effect from 17 September 2026 (Cabinet decision, 16 Sep 2026). An EPFO regional release (PIB, 23 Sep 2026) states the change follows Gazette Notification S.O. 5109(E) and that the pensionable wage ceiling is also ₹25,000 (maximum employer EPS contribution ₹2,083). EPF v1 applies a ₹15,000 ceiling. Not yet established from the gazette text: the EDLI wage ceiling and the treatment of the September 2026 wage month, in which the change takes effect mid-month. Author EPF v2 from the gazette text, then resolve this notice.',
        'references' => [
            ['title' => 'Cabinet Approves Higher EPFO Wage Ceiling of Rs. 25,000, Expanding Mandatory Coverage (PIB Delhi, 16 Sep 2026)', 'url' => 'https://www.labour.gov.in/static/uploads/2026/09/4f607a88c5342aeb980c6b999997caab.pdf', 'sha256' => 'a31038ee4fbc5541515336ebb245ab94194d224c9807a4dc7bdd3968e46e0677'],
            ['title' => 'EPFO Raises Wage Ceiling from Rs. 15,000 to Rs. 25,000 (PIB Kolkata, 23 Sep 2026, release 2313829)', 'url' => 'https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313829&reg=48&lang=2'],
            ['title' => 'Gazette Notification S.O. 5109(E) — cited, text not retrieved', 'url' => null],
        ],
        'retrieved_at' => '2026-09-28',
    ],
    [
        'jurisdiction' => 'IN',
        'code' => 'TDS',
        'state' => null,
        'affects_versions' => [2],
        'effective_date' => '2026-04-01',
        'title' => 'Salary TDS for tax year 2026-27 is governed by the Income-tax Act, 2025, not the Finance Act 2025',
        'summary' => 'TDS v2 (FY 2026-27) carries the Finance Act 2025 rates "carried forward". From 1 April 2026 tax on salaries is deducted under section 392 of the Income-tax Act, 2025 at the rates in Part III of the First Schedule of the Finance Act, 2026, with the new regime under section 202 of that Act (Finance Bill, 2026 as introduced, clauses 3 and 47; Income Tax Department Form No. 130 FAQ). The enacted Finance Act, 2026 text has not been retrieved. Author a corrected TDS version from the enacted text, then resolve this notice.',
        'references' => [
            ['title' => 'The Finance Bill, 2026 (Bill No. 3 of 2026, as introduced in Lok Sabha)', 'url' => 'https://www.indiabudget.gov.in/doc/Finance_Bill.pdf', 'sha256' => '5c98701714c4c473bcd097e376124a4c075881836fe92a5fc4615613c09edabf'],
            ['title' => 'Form No. 130 — Frequently Asked Questions (Income Tax Department)', 'url' => 'https://www.incometaxindia.gov.in/documents/d/guest/form-130-faqs'],
            ['title' => 'Finance Act, 2026 (enacted) — not retrieved', 'url' => null],
        ],
        'retrieved_at' => '2026-09-28',
    ],
    [
        'jurisdiction' => 'IN',
        'code' => 'EPF',
        'state' => null,
        'affects_versions' => [1, 2],
        'effective_date' => '2026-09-17',
        'title' => 'EPFO wage ceiling changed effective 17-Sep-2026: September 2026 intra-month treatment not established',
        'summary' => 'EPFO wage ceiling changed effective 17-Sep-2026. Exact intra-month September payroll treatment requires authoritative implementation evidence before verification. The official releases state the effective date but not whether the September 2026 wage month applies the ₹15,000 or ₹25,000 ceiling, a split by days, or another method. Until an authoritative EPFO / gazette instruction is recorded, neither EPF v1 nor EPF v2 can be verified and payroll on or after 17 Sep 2026 is flagged.',
        'references' => [
            ['title' => 'PIB Delhi, 16 Sep 2026 (effective 17 Sep 2026)', 'url' => 'https://www.labour.gov.in/static/uploads/2026/09/4f607a88c5342aeb980c6b999997caab.pdf', 'sha256' => 'a31038ee4fbc5541515336ebb245ab94194d224c9807a4dc7bdd3968e46e0677'],
            ['title' => 'PIB release 2313829, 23 Sep 2026 (S.O. 5109(E))', 'url' => 'https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313829&reg=48&lang=2', 'sha256' => 'ba2bf8dc7e6dfb4e3673500fbef764269ae5acf04540bec9fd56fe7af8f1f7d6'],
        ],
        'retrieved_at' => '2026-09-28',
    ],
    // Phase 8 evidence reconciliation (1 Oct 2026).
    [
        'jurisdiction' => 'IN',
        'code' => 'EPF',
        'state' => null,
        'affects_versions' => [2],
        'effective_date' => '2026-09-17',
        'title' => 'EPF v2: EDLI ceiling and minimum admin charge contradicted by EPFO guidance; September split-period treatment not implemented',
        'summary' => 'Gazette S.O. 5109(E) (17 Sep 2026, Code on Social Security, 2020, Chapter III) confirms the ₹25,000 wage ceiling from its date of publication; it is silent on rates, EPS, EDLI, administrative charges and the September 2026 transition. EPFO\'s Wage Ceiling FAQs (explanatory, not notified law) state that the revised ceiling applies to EDLI (EPF v2 carries an EDLI wage ceiling of ₹15,000) and that the minimum administrative charge is ₹500 per month for an establishment with a contributing member (EPF v2 carries ₹75). The FAQs split September 2026 into 1–16 Sep at ₹15,000 and 17–30 Sep at ₹25,000 with day-proportionate wages (Q7, Q9), while the Q9 table shows unprorated figures; PeopleOS payroll applies the version effective on the period end to the whole wage month. EPFO states that ECR instructions "are being issued"; none were found, and the EPF / EPS / EDLI scheme texts and S.O. 2702(E) were not retrieved. Publish a corrected version from the scheme texts, implement split-period calculation once EPFO\'s instructions are authoritative, then resolve this notice.',
        'references' => [
            ['title' => 'Gazette of India S.O. 5109(E), 17 Sep 2026 (CG-DL-E-17092026-276299)', 'url' => 'https://egazette.gov.in/WriteReadData/2026/276299.pdf', 'sha256' => '970c2ea088c808a2427f630ba344ab8cfc7a26a10ba9babee2f150b0451c70df'],
            ['title' => 'EPFO circular E-1345653, 25 Sep 2026 (forwards S.O. 5109(E))', 'url' => 'https://pmvbry-cdn.epfindia.gov.in/wp-content/uploads/2026/09/Wage-Ceiling-Circular-28.09.2026.pdf', 'sha256' => '18799245629e0c4c382ccf2b031da62f55d0af872c2e7b28e4b9648d2b2d10fa'],
            ['title' => 'EPFO Wage Ceiling FAQs, 24 Sep 2026', 'url' => 'https://pmvbry-cdn.epfindia.gov.in/wp-content/uploads/2026/09/EPFO_Wage_Ceiling_FAQs.pdf', 'sha256' => '47c22e56faaf6735bb8bd0b70db502e2f86efd4adb654cc4593ff85be6eb1052'],
            ['title' => 'EPF / EPS / EDLI scheme texts and EPFO ECR instructions — not retrieved / not yet issued', 'url' => null],
        ],
        'retrieved_at' => '2026-10-01',
    ],
    [
        'jurisdiction' => 'IN',
        'code' => 'TDS',
        'state' => null,
        'affects_versions' => [3],
        'effective_date' => '2026-04-01',
        'title' => 'TDS v3: new-regime rates for salary TDS and 2025-Act deduction references not established',
        'summary' => 'The Income-tax Act, 2025 confirms the new-regime slabs (s.202(1)), standard deductions (s.19(1)) and rebates (s.156), and salary TDS is deducted at the time of payment under s.392(1) at the "rates in force". Act s.2(90) takes those rates from the Finance Act, and Finance Act, 2026 s.3(10)(ii) applies Part III of the First Schedule, whose Paragraph A carries only the slabs from ₹2,50,000 and excludes s.202 income only for advance tax; the text applying the s.202(1) rates to salary TDS was not found. TDS v3 also names deductions by Income-tax Act, 1961 sections (80C, 80CCD, 80D, 80E, 80G, 80TTA) and the 1961 HRA exemption, and its new-regime 25% surcharge cap appears only in the advance-tax table. A qualified reviewer must settle these from the Act and the Income-tax Rules, 2026 (not retrieved) before v3 can be verified.',
        'references' => [
            ['title' => 'Income-tax Act, 2025 as amended by the Finance Act, 2026 (Income Tax Department)', 'url' => 'https://www.incometaxindia.gov.in/documents/d/guest/income_tax_act_2025_as_amended_by_fa_act_2026-pdf', 'sha256' => 'd54a0ed6a91673d1a4fbcef9b5e472c3efe441139fae38f85a5a0c5b2cfc998b'],
            ['title' => 'The Finance Act, 2026 (No. 4 of 2026)', 'url' => 'https://egazette.gov.in/WriteReadData/2026/271439.pdf', 'sha256' => 'f01136356e61b2534328153941a68f13afd06fe8587d347757c89101d9cc564f'],
            ['title' => 'ITD FAQs on Interplay and Transition (Q6.1, Q6.22–Q6.23)', 'url' => 'https://www.incometaxindia.gov.in/documents/81799/11848482/FAQs-on-Interplay-and-Transition.pdf/05f80c1a-073c-a5d7-fb6f-55509242be53?t=1774082865717', 'sha256' => '0b21c7063d19a81c91b5e2a8911458c003589c2a023c262954dea7fb968917ce'],
            ['title' => 'Income-tax Rules, 2026 — not retrieved', 'url' => null],
        ],
        'retrieved_at' => '2026-10-01',
    ],
];
