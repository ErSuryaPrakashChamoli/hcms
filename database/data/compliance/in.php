<?php

/*
 | India compliance pack (§32). Platform-owned, versioned, effective-dated. Loaded by
 | `peopleos:compliance:sync`. Amounts in INR.
 |
 | Phase 5 rules for this file:
 | - A published version is immutable (checksum). A correction is a NEW version; editing an existing
 |   entry makes the sync fail.
 | - Every version is created as DRAFT. An entry may carry 'evidence' from an official source
 |   (see peopleos.compliance.authoritative_domains); the sync then submits it for REVIEW.
 | - Nothing here can mark a rule VERIFIED. Only a platform administrator who did not submit the
 |   evidence can verify it, in the Rule Verification screen.
 | - Evidence gathered on 28 Sep 2026 is listed in docs/compliance/india-statutory-rule-verification.md.
 */
return [
    [
        'code' => 'EPF', 'state' => null, 'name' => 'Employees Provident Fund', 'version' => 1, 'effective_from' => '2014-09-01', 'authority' => 'EPFO',
        'source' => 'EPF & MP Act 1952; EPFO circulars',
        'notes' => 'DRAFT: no official source retrieved yet confirms the wage ceiling, EPS, EDLI and admin parameters. The EPFO Re-engineered ECR user manual v3.0 only confirms that a 12% or 10% contribution rate is selected on the return; the 10% option is not modelled.',
        'parameters' => [
            'employee_rate' => 0.12, 'employer_rate' => 0.12, 'eps_rate' => 0.0833, 'eps_wage_ceiling' => 15000, 'wage_ceiling' => 15000,
            'edli_rate' => 0.005, 'edli_wage_ceiling' => 15000, 'admin_rate' => 0.005, 'admin_minimum' => 75, 'round' => 'nearest',
        ],
    ],
    [
        'code' => 'EPF', 'state' => null, 'name' => 'Employees Provident Fund — wage ceiling ₹25,000', 'version' => 2, 'effective_from' => '2026-09-17', 'authority' => 'EPFO',
        'source' => 'Gazette Notification S.O. 5109(E) (cited, not retrieved); Ministry of Labour & Employment / PIB releases of 16 and 23 Sep 2026',
        'notes' => 'DRAFT (Phase 7). Wage ceiling and EPS (pensionable) wage ceiling raised to 25,000 from 17 Sep 2026 per the Ministry/PIB releases citing S.O. 5109(E). Every other parameter is carried forward from v1 and NOT CONFIRMED, including the EDLI wage ceiling and administrative charges. The September 2026 intra-month treatment is not established (see the regulatory notice). Cannot be verified until the gazette text supports every parameter.',
        'parameters' => [
            'employee_rate' => 0.12, 'employer_rate' => 0.12, 'eps_rate' => 0.0833, 'eps_wage_ceiling' => 25000, 'wage_ceiling' => 25000,
            'edli_rate' => 0.005, 'edli_wage_ceiling' => 15000, 'admin_rate' => 0.005, 'admin_minimum' => 75, 'round' => 'nearest',
        ],
        'evidence' => [
            'authority' => 'EPFO',
            'source_url' => 'https://www.labour.gov.in/static/uploads/2026/09/4f607a88c5342aeb980c6b999997caab.pdf',
            'source_title' => 'Cabinet Approves Higher EPFO Wage Ceiling of Rs. 25,000, Expanding Mandatory Coverage (PIB Delhi); corroborated by PIB release 2313829',
            'source_published_date' => '2026-09-16',
            'effective_date' => '2026-09-17',
            'retrieved_at' => '2026-09-28',
            'requirement_text' => '"The wage ceiling for mandatory coverage under the Employees\' Provident Fund Organisation (EPFO) has been enhanced from Rs. 15,000 to Rs. 25,000 per month with effect from Vishwakarma Jayanti and Sewa Divas on 17 September 2026." (PIB, 16 Sep 2026). "The revision follows the Union cabinet decision and Gazette Notification S.O. 5109(E) … With the pensionable wage ceiling increased to Rs.25,000, the maximum employer pension contribution at 8.33% will rise from Rs.1,250 to Rs.2,083 per month." (PIB release 2313829, 23 Sep 2026).',
            'mapping' => [
                'wage_ceiling' => 'NOT CONFIRMED against the gazette: 25,000 from 17 Sep 2026 is stated in the PIB releases (corroborating), S.O. 5109(E) text not retrieved.',
                'eps_wage_ceiling' => 'NOT CONFIRMED against the gazette: pensionable wage ceiling 25,000 per PIB release 2313829 (corroborating).',
                'eps_rate' => 'NOT CONFIRMED against the scheme text: 8.33% mentioned in PIB release 2313829 (corroborating).',
                'employee_rate' => 'NOT CONFIRMED: carried forward from v1; not stated in the releases.',
                'employer_rate' => 'NOT CONFIRMED: carried forward from v1; not stated in the releases.',
                'edli_rate' => 'NOT CONFIRMED: carried forward from v1.',
                'edli_wage_ceiling' => 'NOT CONFIRMED: carried forward from v1 (15,000); the releases do not state the EDLI ceiling.',
                'admin_rate' => 'NOT CONFIRMED: carried forward from v1.',
                'admin_minimum' => 'NOT CONFIRMED: carried forward from v1.',
                'round' => 'NOT CONFIRMED: rounding is not stated.',
            ],
            'evidence_reference' => 'database/data/compliance/evidence/epf (PIB PDF sha256 a31038ee…, PIB 2313829 html sha256 ba2bf8dc…)',
            'notes' => 'Press releases corroborate but do not substitute for the gazette notification.',
        ],
        'evidence_documents' => [
            ['path' => 'epf/pib-2026-09-16-epfo-wage-ceiling.pdf', 'sha256' => 'a31038ee4fbc5541515336ebb245ab94194d224c9807a4dc7bdd3968e46e0677', 'retrieved_at' => '2026-09-28', 'source_url' => 'https://www.labour.gov.in/static/uploads/2026/09/4f607a88c5342aeb980c6b999997caab.pdf'],
            ['path' => 'epf/pib-2313829-2026-09-23-epfo-ro-berhampore.html', 'sha256' => 'ba2bf8dc7e6dfb4e3673500fbef764269ae5acf04540bec9fd56fe7af8f1f7d6', 'retrieved_at' => '2026-09-28', 'source_url' => 'https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313829&reg=48&lang=2'],
        ],
        // Phase 8 reconciliation (1 Oct 2026): the gazette notification itself, EPFO's covering circular
        // and FAQs. Submitted as a new evidence revision; nothing here verifies the rule.
        'evidence_revisions' => [[
            'revision' => 'phase-8-2026-10-01',
            'evidence' => [
                'authority' => 'EPFO',
                'source_url' => 'https://egazette.gov.in/WriteReadData/2026/276299.pdf',
                'source_title' => 'Gazette of India Extraordinary, Part II Section 3(ii), S.O. 5109(E), Ministry of Labour and Employment, 17 Sep 2026 (CG-DL-E-17092026-276299); with EPFO circular E-1345653 (25.09.2026) and EPFO "Wage Ceiling FAQs" (24 Sep 2026)',
                'source_published_date' => '2026-09-17',
                'effective_date' => '2026-09-17',
                'retrieved_at' => '2026-10-01',
                'requirement_text' => 'S.O. 5109(E): "In exercise of the powers conferred by clause (89) of section 2 of the Code on Social Security, 2020 (36 of 2020) and in supersession of the notification of the Government of India in the Ministry of Labour and Employment, number S.O. 2702(E) dated 29th May 2026, except as respects things done or omitted to be done before such supersession, the Central Government hereby notifies rupees twenty-five thousand (₹25,000) per month as the wage ceiling for the purposes of Chapter III of the said Code, with effect from the date of publication of this notification in the Official Gazette." (digitally signed 17 Sep 2026). The gazette is silent on contribution rates, EPS, EDLI, administrative charges, eligibility, the September 2026 transition and rounding. EPFO circular E-1345653 only forwards the notification "for information and further necessary action". EPFO Wage Ceiling FAQs (explanatory, not notified law): Q5 "Is the revised wage ceiling applicable to EPF, EPS and EDLI? Answer: Yes."; Q9 "Period 1: Up to 16.09.2026 Contribution will be calculated subject to the earlier wage ceiling of ₹15,000. Period 2: From 17.09.2026 Contribution will be calculated subject to the revised wage ceiling of ₹25,000", illustrated in Q7 with proportionate wages "(₹20,000 × 16/30)" and "(₹20,000 × 14/30)"; Q13 "The minimum administrative charges are, however, ₹500 per month for such establishment, which have at least one contributing member during the month and ₹75 per month per establishment, in case the establishment has no active contributory members"; rates shown as employee 12%, EPS 8.33%, employer EPF 3.67%, EDLI 0.5%, admin 0.5%.',
                'mapping' => [
                    'wage_ceiling' => 'S.O. 5109(E): "the Central Government hereby notifies rupees twenty-five thousand (₹25,000) per month as the wage ceiling for the purposes of Chapter III of the said Code, with effect from the date of publication of this notification in the Official Gazette" (published 17 Sep 2026).',
                    'eps_wage_ceiling' => 'NOT CONFIRMED: the gazette sets the Chapter III wage ceiling; EPFO FAQ Q13 ("Membership of EPS is available only to such employees whose wages … do not exceed the wage ceiling (i.e.₹25,000 per month w.e.f 17.09.2026)") and PIB 2314111 ("8.33% of ₹25,000") are explanatory. The Employees\' Pension Scheme text was not retrieved.',
                    'eps_rate' => 'NOT CONFIRMED: 8.33% appears in the EPFO FAQ illustrations and PIB 2314111; the scheme text was not retrieved.',
                    'employee_rate' => 'NOT CONFIRMED: 12% appears in the EPFO FAQ illustrations only; the contribution provision of the Code / scheme was not retrieved.',
                    'employer_rate' => 'NOT CONFIRMED: 12% (EPS 8.33% + EPF 3.67%) appears in the EPFO FAQ illustrations only; the scheme text was not retrieved.',
                    'edli_rate' => 'NOT CONFIRMED: 0.5% appears in the EPFO FAQ illustrations only; the EDLI scheme text was not retrieved.',
                    'edli_wage_ceiling' => 'NOT CONFIRMED — CONTRADICTED: this version carries ₹15,000 from v1, but EPFO FAQ Q5 says the revised ceiling applies to EDLI and its illustrations charge EDLI on wages of ₹20,000. A corrected version needs the EDLI scheme text; the value is not changed on the FAQ alone.',
                    'admin_rate' => 'NOT CONFIRMED: 0.5% appears in the EPFO FAQ illustrations only.',
                    'admin_minimum' => 'NOT CONFIRMED — CONTRADICTED: this version carries ₹75; EPFO FAQ Q13 states ₹500 per month for an establishment with at least one contributing member and ₹75 only when it has none. Needs the notified administrative-charge text.',
                    'round' => 'NOT CONFIRMED: no rounding rule in the gazette, circular or FAQ (FAQ figures appear both to two decimals and in whole rupees).',
                ],
                'evidence_reference' => 'database/data/compliance/evidence/epf (S.O. 5109(E) sha256 970c2ea0…, circular sha256 18799245…, FAQs sha256 47c22e56…, PIB 2314111 sha256 076c23a6…)',
                'notes' => 'Phase 8 evidence reconciliation. The legal basis is the Code on Social Security, 2020 (Chapter III), not the EPF & MP Act, 1952. S.O. 2702(E) of 29 May 2026 (superseded) and the EPF / EPS / EDLI scheme texts were not retrieved. The FAQ\'s September proration conflicts with its own Q9 table and with PeopleOS payroll, which applies the version effective on the period end to the whole month (see the regulatory notice). eGazette served an incomplete TLS chain; the PDF was retrieved without chain verification and its SHA-256 recorded; the same notification is reproduced in the EPFO circular fetched over valid TLS.',
            ],
            'evidence_documents' => [
                ['path' => 'epf/s-o-5109e-gazette-276299-2026-09-17.pdf', 'sha256' => '970c2ea088c808a2427f630ba344ab8cfc7a26a10ba9babee2f150b0451c70df', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://egazette.gov.in/WriteReadData/2026/276299.pdf'],
                ['path' => 'epf/epfo-circular-e1345653-wage-ceiling-2026-09-25.pdf', 'sha256' => '18799245629e0c4c382ccf2b031da62f55d0af872c2e7b28e4b9648d2b2d10fa', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://pmvbry-cdn.epfindia.gov.in/wp-content/uploads/2026/09/Wage-Ceiling-Circular-28.09.2026.pdf'],
                ['path' => 'epf/epfo-wage-ceiling-faqs-2026-09-24.pdf', 'sha256' => '47c22e56faaf6735bb8bd0b70db502e2f86efd4adb654cc4593ff85be6eb1052', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://pmvbry-cdn.epfindia.gov.in/wp-content/uploads/2026/09/EPFO_Wage_Ceiling_FAQs.pdf'],
                ['path' => 'epf/pib-2314111-2026-09-23-imphal.html', 'sha256' => '076c23a6f17d3ec220a60c245531bd7ad9210207f53a01e3472d6ce947fa8325', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://www.pib.gov.in/PressReleasePage.aspx?PRID=2314111&reg=48&lang=2'],
            ],
        ]],
    ],
    [
        'code' => 'ESI', 'state' => null, 'name' => 'Employees State Insurance', 'version' => 1, 'effective_from' => '2019-07-01', 'authority' => 'ESIC',
        'source' => 'ESI (Central) Amendment Rules 2019; wage ceiling notification 2017',
        'parameters' => ['employee_rate' => 0.0075, 'employer_rate' => 0.0325, 'wage_ceiling' => 21000, 'round' => 'ceil', 'contribution_periods' => [[4, 9], [10, 3]]],
        'evidence' => [
            'authority' => 'ESIC',
            'source_url' => 'https://esic.gov.in/contribution',
            'source_title' => 'ESIC — Contribution (with ESIC — Coverage, https://esic.gov.in/coverage)',
            'source_published_date' => null,
            'effective_date' => '2019-07-01',
            'requirement_text' => "Contribution: \"the employee's contribution rate (w.e.f. 01.07.2019) is 0.75% of the wages and that of employer's is 3.25% of the wages paid/payable\"; \"Employees in receipt of a daily average wage upto Rs.176/- are exempted from payment of contribution\"; contribution periods \"1st April to 30th Sept.\" and \"1st Oct to 31st March of the year following\". Coverage: \"The existing wage limit for coverage under the Act, effective from 01.01.2017, is Rs.21,000/- per month (Rs.25,000/- per month in the case of Persons with Disability).\"",
            'mapping' => [
                'employee_rate' => 'Contribution page: employee 0.75% w.e.f. 01.07.2019 — matches.',
                'employer_rate' => 'Contribution page: employer 3.25% w.e.f. 01.07.2019 — matches.',
                'wage_ceiling' => 'Coverage page: Rs.21,000 per month w.e.f. 01.01.2017 — matches; the Rs.25,000 limit for persons with disability is NOT modelled.',
                'round' => 'NOT CONFIRMED: the retrieved pages do not state the rounding rule.',
                'contribution_periods' => 'Contribution page: April–September and October–March — matches.',
            ],
            'evidence_reference' => 'Retrieved 2026-09-28 from esic.gov.in (page text quoted above).',
            'retrieved_at' => '2026-09-28',
            'notes' => 'Blocking gaps for verification: rounding not confirmed; persons-with-disability limit and the Rs.176 daily-average-wage employee exemption are not modelled by the engine.',
        ],
    ],
    // Professional tax: monthly slabs on gross, per state. Slab = [upper bound inclusive or null, monthly tax].
    ['code' => 'PT', 'state' => 'MH', 'name' => 'Professional tax – Maharashtra', 'version' => 1, 'effective_from' => '2023-04-01', 'source' => 'Maharashtra State Tax on Professions Act 1975 (Sch. I, amended 2023)',
        'parameters' => ['slabs' => [[7500, 0], [10000, 175], [null, 200]], 'february_amount' => 300, 'female_exempt_upto' => 25000]],
    ['code' => 'PT', 'state' => 'KA', 'name' => 'Professional tax – Karnataka', 'version' => 1, 'effective_from' => '2023-04-01', 'source' => 'Karnataka Tax on Professions Act 1976 (amended 2023)',
        'parameters' => ['slabs' => [[24999, 0], [null, 200]], 'february_amount' => 300]],
    ['code' => 'PT', 'state' => 'TG', 'name' => 'Professional tax – Telangana', 'version' => 1, 'effective_from' => '2014-06-02', 'source' => 'Telangana Tax on Professions Act 1987',
        'parameters' => ['slabs' => [[15000, 0], [20000, 150], [null, 200]]]],
    ['code' => 'PT', 'state' => 'AP', 'name' => 'Professional tax – Andhra Pradesh', 'version' => 1, 'effective_from' => '2013-02-01', 'source' => 'AP Tax on Professions Act 1987',
        'parameters' => ['slabs' => [[15000, 0], [20000, 150], [null, 200]]]],
    ['code' => 'PT', 'state' => 'WB', 'name' => 'Professional tax – West Bengal', 'version' => 1, 'effective_from' => '2013-04-01', 'source' => 'WB State Tax on Professions Act 1979',
        'parameters' => ['slabs' => [[10000, 0], [15000, 110], [25000, 130], [40000, 150], [null, 200]]]],
    ['code' => 'PT', 'state' => 'TN', 'name' => 'Professional tax – Tamil Nadu', 'version' => 1, 'effective_from' => '2018-04-01', 'source' => 'TN Municipal Laws (half-yearly, shown as monthly equivalent)',
        'parameters' => ['slabs' => [[3500, 0], [5000, 22.5], [7500, 52.5], [10000, 115], [12500, 171], [null, 208]]]],
    ['code' => 'PT', 'state' => 'GJ', 'name' => 'Professional tax – Gujarat', 'version' => 1, 'effective_from' => '2022-04-01', 'source' => 'Gujarat Professions Tax Act 1976 (amended 2022)',
        'parameters' => ['slabs' => [[12000, 0], [null, 200]]]],
    ['code' => 'PT', 'state' => 'MP', 'name' => 'Professional tax – Madhya Pradesh', 'version' => 1, 'effective_from' => '2018-04-01', 'source' => 'MP Vritti Kar Adhiniyam 1995',
        'parameters' => ['slabs' => [[18750, 0], [25000, 125], [33333, 167], [null, 208]], 'march_amount' => 212]],
    ['code' => 'PT', 'state' => 'KL', 'name' => 'Professional tax – Kerala', 'version' => 1, 'effective_from' => '2016-04-01', 'source' => 'Kerala Municipality Act 1994 (half-yearly, monthly equivalent)',
        'parameters' => ['slabs' => [[1999, 0], [2999, 20], [4999, 30], [7499, 50], [9999, 75], [12499, 100], [16666, 125], [20833, 166], [null, 208]]]],
    // Labour welfare fund: employee/employer contribution, deducted in the listed months.
    ['code' => 'LWF', 'state' => 'MH', 'name' => 'Labour welfare fund – Maharashtra', 'version' => 1, 'effective_from' => '2015-01-01', 'source' => 'Maharashtra Labour Welfare Fund Act 1953',
        'parameters' => ['months' => [6, 12], 'employee_amount' => 25, 'employer_amount' => 75, 'wage_ceiling' => null]],
    ['code' => 'LWF', 'state' => 'KA', 'name' => 'Labour welfare fund – Karnataka', 'version' => 1, 'effective_from' => '2018-01-01', 'source' => 'Karnataka Labour Welfare Fund Act 1965',
        'parameters' => ['months' => [12], 'employee_amount' => 20, 'employer_amount' => 40, 'wage_ceiling' => null]],
    ['code' => 'LWF', 'state' => 'TG', 'name' => 'Labour welfare fund – Telangana', 'version' => 1, 'effective_from' => '2015-01-01', 'source' => 'Telangana Labour Welfare Fund Act 1987',
        'parameters' => ['months' => [12], 'employee_amount' => 2, 'employer_amount' => 5, 'wage_ceiling' => null]],
    ['code' => 'LWF', 'state' => 'DL', 'name' => 'Labour welfare fund – Delhi', 'version' => 1, 'effective_from' => '2015-01-01', 'source' => 'Bombay Labour Welfare Fund Act 1953 (as applied to Delhi)',
        'parameters' => ['months' => [6, 12], 'employee_amount' => 0.75, 'employer_amount' => 2.25, 'wage_ceiling' => null]],
    ['code' => 'LWF', 'state' => 'TN', 'name' => 'Labour welfare fund – Tamil Nadu', 'version' => 1, 'effective_from' => '2015-01-01', 'source' => 'TN Labour Welfare Fund Act 1972',
        'parameters' => ['months' => [12], 'employee_amount' => 20, 'employer_amount' => 40, 'wage_ceiling' => null]],
    ['code' => 'LWF', 'state' => 'GJ', 'name' => 'Labour welfare fund – Gujarat', 'version' => 1, 'effective_from' => '2015-01-01', 'source' => 'Gujarat Labour Welfare Fund Act 1953',
        'parameters' => ['months' => [6, 12], 'employee_amount' => 6, 'employer_amount' => 12, 'wage_ceiling' => null]],
    ['code' => 'LWF', 'state' => 'HR', 'name' => 'Labour welfare fund – Haryana', 'version' => 1, 'effective_from' => '2020-01-01', 'source' => 'Punjab Labour Welfare Fund Act 1965 (Haryana)',
        'parameters' => ['months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'employee_amount' => 31, 'employer_amount' => 62, 'wage_ceiling' => null]],
    // Income tax (TDS on salary, section 192). Slabs: [upper bound or null, rate].
    [
        'code' => 'TDS', 'state' => null, 'name' => 'Income tax on salary – FY 2025-26', 'version' => 1, 'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'authority' => 'INCOME_TAX',
        'notes' => 'DRAFT: slabs, rebate and surcharge not yet checked against an official Income Tax Department source.',
        'source' => 'Finance Act 2025',
        'parameters' => [
            'new' => ['slabs' => [[400000, 0], [800000, 0.05], [1200000, 0.10], [1600000, 0.15], [2000000, 0.20], [2400000, 0.25], [null, 0.30]], 'standard_deduction' => 75000, 'rebate_income_limit' => 1200000, 'rebate_max' => 60000, 'marginal_relief' => true, 'allow_chapter_via' => ['80CCD2']],
            'old' => ['slabs' => [[250000, 0], [500000, 0.05], [1000000, 0.20], [null, 0.30]], 'standard_deduction' => 50000, 'rebate_income_limit' => 500000, 'rebate_max' => 12500, 'marginal_relief' => false, 'allow_chapter_via' => ['80C', '80CCD1B', '80D', '80E', '80G', '80TTA', '80CCD2'], 'limits' => ['80C' => 150000, '80CCD1B' => 50000, '80D' => 25000, '80TTA' => 10000], 'hra_exempt' => true],
            'cess_rate' => 0.04,
            'surcharge' => [[5000000, 0], [10000000, 0.10], [20000000, 0.15], [50000000, 0.25], [null, 0.37]],
            'surcharge_new_regime_cap' => 0.25,
        ],
    ],
    [
        'code' => 'TDS', 'state' => null, 'name' => 'Income tax on salary – FY 2026-27', 'version' => 2, 'effective_from' => '2026-04-01', 'authority' => 'INCOME_TAX',
        'source' => 'Finance Act 2025 rates carried forward pending Finance Act 2026 verification',
        'notes' => 'DRAFT and must not be verified as is: from 1 April 2026 salary TDS falls under section 392 of the Income-tax Act, 2025 (Income Tax Department, Form No. 130 FAQ). Re-author as a new version from the Income-tax Act, 2025 and the Finance Act, 2026.',
        'parameters' => [
            'new' => ['slabs' => [[400000, 0], [800000, 0.05], [1200000, 0.10], [1600000, 0.15], [2000000, 0.20], [2400000, 0.25], [null, 0.30]], 'standard_deduction' => 75000, 'rebate_income_limit' => 1200000, 'rebate_max' => 60000, 'marginal_relief' => true, 'allow_chapter_via' => ['80CCD2']],
            'old' => ['slabs' => [[250000, 0], [500000, 0.05], [1000000, 0.20], [null, 0.30]], 'standard_deduction' => 50000, 'rebate_income_limit' => 500000, 'rebate_max' => 12500, 'marginal_relief' => false, 'allow_chapter_via' => ['80C', '80CCD1B', '80D', '80E', '80G', '80TTA', '80CCD2'], 'limits' => ['80C' => 150000, '80CCD1B' => 50000, '80D' => 25000, '80TTA' => 10000], 'hra_exempt' => true],
            'cess_rate' => 0.04,
            'surcharge' => [[5000000, 0], [10000000, 0.10], [20000000, 0.15], [50000000, 0.25], [null, 0.37]],
            'surcharge_new_regime_cap' => 0.25,
        ],
    ],
    [
        'code' => 'TDS', 'state' => null, 'name' => 'Income tax on salary – Tax Year 2026-27 (Income-tax Act, 2025, s.392(1))', 'version' => 3, 'effective_from' => '2026-04-01', 'authority' => 'INCOME_TAX',
        'corrects_version' => 2,
        'correction_reason' => 'v2 cites the Finance Act 2025 "carried forward". From 1 April 2026 salary TDS is deducted under section 392(1) of the Income-tax Act, 2025 at the rates in Part III of the First Schedule of the Finance Act, 2026 (Act No. 4 of 2026), with the computation reset for tax year 2026-27 and the Act chosen by the date of payment.',
        'source' => 'Income-tax Act, 2025 s.392(1); Finance Act, 2026 (No. 4 of 2026) s.2(10), (16) and Part III of the First Schedule; Income Tax Department TDS Compliance FAQs',
        'notes' => 'DRAFT (Phase 7). Old-regime slabs, surcharge and cess are traced to the enacted Finance Act, 2026. New-regime slabs, rebate, standard deduction, deduction limits and the new-regime surcharge cap sit in the Income-tax Act, 2025 (section 202 and others), whose text was not retrieved: those values are carried forward and NOT CONFIRMED, so this version cannot be verified yet.',
        'parameters' => [
            'legal_basis' => ['act' => 'Income-tax Act, 2025', 'section' => '392(1)', 'tax_year' => '2026-27', 'rates' => 'Finance Act, 2026, Part III of the First Schedule'],
            'deduction_trigger' => 'payment_date',
            'new' => ['slabs' => [[400000, 0], [800000, 0.05], [1200000, 0.10], [1600000, 0.15], [2000000, 0.20], [2400000, 0.25], [null, 0.30]], 'standard_deduction' => 75000, 'rebate_income_limit' => 1200000, 'rebate_max' => 60000, 'marginal_relief' => true, 'allow_chapter_via' => ['80CCD2']],
            'old' => ['slabs' => [[250000, 0], [500000, 0.05], [1000000, 0.20], [null, 0.30]], 'standard_deduction' => 50000, 'rebate_income_limit' => 500000, 'rebate_max' => 12500, 'marginal_relief' => false, 'allow_chapter_via' => ['80C', '80CCD1B', '80D', '80E', '80G', '80TTA', '80CCD2'], 'limits' => ['80C' => 150000, '80CCD1B' => 50000, '80D' => 25000, '80TTA' => 10000], 'hra_exempt' => true],
            'cess_rate' => 0.04,
            'surcharge' => [[5000000, 0], [10000000, 0.10], [20000000, 0.15], [50000000, 0.25], [null, 0.37]],
            'surcharge_new_regime_cap' => 0.25,
        ],
        'evidence' => [
            'authority' => 'INCOME_TAX',
            'source_url' => 'https://egazette.gov.in/WriteReadData/2026/271439.pdf',
            'source_title' => 'The Finance Act, 2026 (No. 4 of 2026), Gazette of India Extraordinary Part II Section 1, 30 March 2026; with Income Tax Department TDS Compliance FAQs',
            'source_published_date' => '2026-03-30',
            'effective_date' => '2026-04-01',
            'retrieved_at' => '2026-09-28',
            'requirement_text' => 'Finance Act, 2026 s.2(10)(ii): income-tax "deducted from, or paid on, income chargeable under the head "Salaries" under section 392 … shall be … deducted … at the rate or rates specified in Part III of the First Schedule". Part III Paragraph A(I): nil up to ₹250000; 5% above ₹250000 to ₹500000; ₹12500 plus 20% above ₹500000 to ₹1000000; ₹112500 plus 30% above ₹1000000. Paragraph F Table 1 (individuals): surcharge 10% above ₹5000000, 15% above ₹10000000, 25% above ₹20000000, 37% above ₹50000000. s.2(16): Health and Education Cess 4%. ITD TDS Compliance FAQs: "For salary pertaining to Tax Year 2026-27 (paid from April 2026 onwards): TDS obligations shall be in accordance to Section 392(1) of the new act … The employer must reset the TDS computation from 1st April, 2026"; the governing Act follows the earlier of credit or payment.',
            'mapping' => [
                'legal_basis' => 'Finance Act, 2026 s.2(10)(ii) and Part III; ITD TDS Compliance FAQs (salary paid from April 2026 → s.392(1), Income-tax Act, 2025).',
                'deduction_trigger' => 'ITD TDS Compliance FAQs: the Act follows the earlier of credit or payment; salary paid up to March 2026 → old Act, paid from April 2026 → new Act.',
                'old' => 'NOT CONFIRMED in full: slabs traced to Finance Act 2026 Part III Paragraph A(I); standard deduction, rebate, deduction limits and HRA treatment sit in the Income-tax Act, 2025 (not retrieved).',
                'new' => 'NOT CONFIRMED: new-regime rates are in section 202 of the Income-tax Act, 2025 (not retrieved); carried forward from v2.',
                'cess_rate' => 'Finance Act, 2026 s.2(16): Health and Education Cess at 4% of income-tax and surcharge.',
                'surcharge' => 'Finance Act, 2026 Part III Paragraph F, Table 1, Sl. No. 1 (individuals): 10% / 15% / 25% / 37% at ₹50 lakh / ₹1 crore / ₹2 crore / ₹5 crore.',
                'surcharge_new_regime_cap' => 'NOT CONFIRMED: the new-regime surcharge cap follows section 202 of the Income-tax Act, 2025 (not retrieved).',
            ],
            'evidence_reference' => 'database/data/compliance/evidence/tds (Finance Act 2026 gazette sha256 f0113635…, ITD FAQ html sha256 8296c757…)',
            'notes' => 'eGazette served an incomplete TLS chain; the gazette PDF was retrieved without chain verification and its SHA-256 recorded.',
        ],
        'evidence_documents' => [
            ['path' => 'tds/finance-act-2026-no-4-of-2026-gazette-271439.pdf', 'sha256' => 'f01136356e61b2534328153941a68f13afd06fe8587d347757c89101d9cc564f', 'retrieved_at' => '2026-09-28', 'source_url' => 'https://egazette.gov.in/WriteReadData/2026/271439.pdf'],
            ['path' => 'tds/itd-tds-compliance-faqs-2026-09-28.html', 'sha256' => '8296c75793f04971f52b3496611cbe08919fb4cee88104fced87079b81cc572b', 'retrieved_at' => '2026-09-28', 'source_url' => 'https://www.incometax.gov.in/iec/foportal/help/all-topics/e-filing-services/%20tds%20compliance-faq'],
        ],
        // Phase 8 reconciliation (1 Oct 2026): the Income-tax Act, 2025 text and the ITD transition FAQs.
        // Also corrects the Phase 7 citations (Finance Act s.3, not s.2; the salary-specific transition
        // rule, not the generic "earlier of credit or payment" rule). Nothing here verifies the rule.
        'evidence_revisions' => [[
            'revision' => 'phase-8-2026-10-01',
            'evidence' => [
                'authority' => 'INCOME_TAX',
                'source_url' => 'https://www.incometaxindia.gov.in/documents/d/guest/income_tax_act_2025_as_amended_by_fa_act_2026-pdf',
                'source_title' => 'Income-tax Act, 2025 (No. 30 of 2025) as amended by the Finance Act, 2026 (Income Tax Department), cross-checked with the enacted Act (Gazette 265620); Finance Act, 2026 (No. 4 of 2026); ITD "FAQs on Interplay and Transition"',
                'source_published_date' => '2026-03-30',
                'effective_date' => '2026-04-01',
                'retrieved_at' => '2026-10-01',
                'requirement_text' => 'Income-tax Act, 2025 s.392(1): deduct income-tax on salary "at the time of such payment at the average rate of income-tax computed on the basis of the rates in force for the tax year in which the payment is made, on the estimated income of the assessee under this head for such year". Finance Act, 2026 s.3(10)(ii): income-tax "deducted from, or paid on, income chargeable under the head "Salaries" under section 392 … shall be … deducted … at the rate or rates specified in Part III of the First Schedule and such tax shall be increased by a surcharge"; s.3(16): Health and Education Cess "at the rate of 4% of such income-tax and surcharge". Act s.202(1) new regime: "Upto ₹ 400000 Nil … From ₹ 400001 to ₹ 800000 5% … From ₹ 800001 to ₹ 1200000 10% … From ₹ 1200001 to ₹ 1600000 15% … From ₹ 1600001 to ₹ 2000000 20% … From ₹ 2000001 to ₹ 2400000 25% … Above ₹ 2400000 30%", applying "unless the person exercises the option". s.19(1) Table Sl. 2 standard deduction: "(a) ₹ 75000 or the salary, whichever is less, where income-tax is computed under section 202(1); (b) ₹ 50000 or the salary, whichever is less, in any other case". s.156(1): "100% of income-tax payable or ₹ 12500, whichever is less … if such total income does not exceed ₹ 500000"; s.156(2): "(a) the income does not exceed twelve lakh rupees, 100% of the income-tax payable or ₹ 60000, whichever is less; (b) … an amount equal to the amount by which the income-tax payable on such total income is in excess of the amount by which the total income exceeds twelve lakh rupees". ITD FAQs Q6.22: "Under the TDS provisions relating to salary, tax is required to be deducted at the time of payment … Salary for March 2026 paid on 31 March 2026 will be governed by the Income-tax Act, 1961 … Salary for April 2026 paid on 30 April 2026 will be governed by the Income-tax Act, 2025". (Q6.1\'s "earlier of the event of credit or payment" rule is the general, non-salary rule.)',
                'mapping' => [
                    'legal_basis' => 'Income-tax Act, 2025 s.392(1) (deduction at the time of payment at the average rate on the estimated salary income) and Finance Act, 2026 s.3(10)(ii) (salary TDS under s.392 at the rates in Part III of the First Schedule plus surcharge); ITD FAQs Q6.22–Q6.23.',
                    'deduction_trigger' => 'ITD FAQs Q6.22: "Under the TDS provisions relating to salary, tax is required to be deducted at the time of payment. Thus, TDS on salary shall be governed by different Acts, based on the date of payment of salary" — the salary-specific rule (not the general "earlier of credit or payment" rule in Q6.1).',
                    'old' => 'NOT CONFIRMED in part: slabs match Finance Act, 2026 Part III Paragraph A(I); standard deduction ₹50,000 matches Act s.19(1) Table Sl. 2(b); rebate ₹12,500 up to ₹5,00,000 matches s.156(1). The deduction identifiers and limits (80C, 80CCD(1B), 80D, 80E, 80G, 80TTA) and the HRA exemption are Income-tax Act, 1961 provisions; their 2025-Act equivalents were not mapped.',
                    'new' => 'NOT CONFIRMED in part: slabs match Act s.202(1); standard deduction ₹75,000 matches s.19(1) Table Sl. 2(a); rebate ₹60,000 up to ₹12,00,000 and marginal relief match s.156(2)(a)–(b). Open: (i) the text by which the s.202(1) rates become the "rates in force" for s.392 salary TDS — Act s.2(90) points to the Finance Act, whose Part III Paragraph A carries only the slabs above and excludes s.202 income only for advance tax; (ii) the deduction identifier 80CCD(2) is a 1961-Act section.',
                    'cess_rate' => 'Finance Act, 2026 s.3(16): Health and Education Cess "at the rate of 4% of such income-tax and surcharge" for the amounts under sub-sections (6) to (14), which include salary TDS under s.3(10)(ii).',
                    'surcharge' => 'Finance Act, 2026 Part III Paragraph F, Table 1 (individuals): 10% / 15% / 25% / 37% where income exceeds ₹50 lakh / ₹1 crore / ₹2 crore / ₹5 crore, with marginal relief.',
                    'surcharge_new_regime_cap' => 'NOT CONFIRMED: a 25% cap for s.202 income appears only in the advance-tax table of Finance Act, 2026 s.3(12)(b) (Sl. 10); Part III Paragraph F, which governs salary TDS, carries no s.202 cap.',
                ],
                'evidence_reference' => 'database/data/compliance/evidence/tds (Act as amended sha256 d54a0ed6…, enacted Act gazette sha256 7acfb16f…, ITD transition FAQs sha256 0b21c706…, Finance Act 2026 sha256 f0113635…)',
                'notes' => 'Phase 8 evidence reconciliation. Supersedes the Phase 7 citations "Finance Act, 2026 s.2(10)(ii)" and "s.2(16)" (the 2025-Act provisions are in s.3) and the deduction-trigger citation of the general credit-or-payment rule. Rounding of monthly salary TDS is not stated in s.392 (s.516 rounds total income to the nearest ₹10; its application to TDS was not assumed). The Income-tax Rules, 2026 were not retrieved.',
            ],
            'evidence_documents' => [
                ['path' => 'tds/income-tax-act-2025-as-amended-fa-2026-itd.pdf', 'sha256' => 'd54a0ed6a91673d1a4fbcef9b5e472c3efe441139fae38f85a5a0c5b2cfc998b', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://www.incometaxindia.gov.in/documents/d/guest/income_tax_act_2025_as_amended_by_fa_act_2026-pdf'],
                ['path' => 'tds/income-tax-act-2025-gazette-265620.pdf', 'sha256' => '7acfb16fd7f3db8b4f85fc532b6d6924478ef86fa2e5b7683d282f8825f8017c', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://egazette.gov.in/WriteReadData/2025/265620.pdf'],
                ['path' => 'tds/itd-faqs-interplay-transition-2026.pdf', 'sha256' => '0b21c7063d19a81c91b5e2a8911458c003589c2a023c262954dea7fb968917ce', 'retrieved_at' => '2026-10-01', 'source_url' => 'https://www.incometaxindia.gov.in/documents/81799/11848482/FAQs-on-Interplay-and-Transition.pdf/05f80c1a-073c-a5d7-fb6f-55509242be53?t=1774082865717'],
            ],
        ]],
    ],
    ['code' => 'GRATUITY', 'state' => null, 'name' => 'Gratuity', 'version' => 1, 'effective_from' => '2018-03-29', 'source' => 'Payment of Gratuity Act 1972 (amended 2018)',
        'parameters' => ['rate' => 15 / 26 / 12, 'minimum_years' => 5, 'ceiling' => 2000000]],
];
