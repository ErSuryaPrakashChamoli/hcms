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
];
