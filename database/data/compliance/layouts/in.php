<?php

/*
 | Statutory export layouts (Phase 6.2), India. Versioned and immutable: a changed specification is a
 | new version. Loaded by `peopleos:compliance:sync` as DRAFT; only the maker-checker workflow can
 | verify a layout, against the authority's current upload specification (with a stored copy).
 | None of these has been verified: the specifications below reflect what PeopleOS writes today.
 */
$amount = '/^\d+$/';
$decimal = '/^\d+(\.\d{1,2})?$/';

return [
    [
        'code' => 'EPF_ECR', 'version' => 1, 'name' => 'EPF Electronic Challan-cum-Return (text upload)', 'authority' => 'EPFO',
        'notes' => 'EPFO states the revamped ECR keeps the existing format (epfo.gov.in/revamped-ecr); the field layout is in the employer-portal Help File, not retrieved.',
        'specification' => [
            'type' => 'delimited', 'separator' => '#~#', 'quote' => false, 'header' => false, 'encoding' => 'UTF-8', 'line_ending' => "\n",
            'fields' => [
                ['name' => 'UAN', 'required' => true, 'pattern' => '/^\d{12}$/'],
                ['name' => 'MEMBER NAME', 'required' => true],
                ['name' => 'GROSS WAGES', 'required' => true, 'pattern' => $amount],
                ['name' => 'EPF WAGES', 'required' => true, 'pattern' => $amount],
                ['name' => 'EPS WAGES', 'required' => true, 'pattern' => $amount],
                ['name' => 'EDLI WAGES', 'required' => true, 'pattern' => $amount],
                ['name' => 'EPF CONTRI REMITTED', 'required' => true, 'pattern' => $amount],
                ['name' => 'EPS CONTRI REMITTED', 'required' => true, 'pattern' => $amount],
                ['name' => 'EPF EPS DIFF REMITTED', 'required' => true, 'pattern' => $amount],
                ['name' => 'NCP DAYS', 'required' => true, 'pattern' => '/^\d{1,2}$/'],
                ['name' => 'REFUND OF ADVANCES', 'required' => true, 'pattern' => $amount],
            ],
        ],
    ],
    [
        'code' => 'ESI_MC', 'version' => 1, 'name' => 'ESI monthly contribution upload', 'authority' => 'ESIC',
        'notes' => 'ESIC employer-portal monthly contribution template not retrieved.',
        'specification' => [
            'type' => 'delimited', 'separator' => ',', 'quote' => true, 'header' => true, 'encoding' => 'UTF-8', 'line_ending' => "\n",
            'fields' => [
                ['name' => 'IP Number', 'required' => true, 'pattern' => '/^\d{10}$/'],
                ['name' => 'IP Name', 'required' => true],
                ['name' => 'No of Days for which wages paid/payable during the month', 'required' => true, 'pattern' => '/^\d{1,2}$/'],
                ['name' => 'Total Monthly Wages', 'required' => true, 'pattern' => $decimal],
                ['name' => 'Reason Code for Zero workings days', 'required' => false],
                ['name' => 'Last Working Day', 'required' => false],
            ],
        ],
    ],
    [
        'code' => 'PT_RETURN', 'version' => 1, 'name' => 'Professional tax working schedule (generic, not a state form)', 'authority' => 'STATE_PT',
        'notes' => 'Each state prescribes its own return; this schedule is for reconciliation only.',
        'specification' => [
            'type' => 'delimited', 'separator' => ',', 'quote' => true, 'header' => true, 'encoding' => 'UTF-8', 'line_ending' => "\n",
            'fields' => [
                ['name' => 'Employee', 'required' => true], ['name' => 'State', 'required' => true, 'pattern' => '/^[A-Z]{2}$/'],
                ['name' => 'Gross salary', 'required' => true, 'pattern' => $decimal], ['name' => 'Professional tax', 'required' => true, 'pattern' => $decimal],
            ],
        ],
    ],
    [
        'code' => 'LWF_RETURN', 'version' => 1, 'name' => 'Labour welfare fund working schedule (generic, not a board form)', 'authority' => 'STATE_LWF',
        'notes' => 'Each welfare board prescribes its own return; this schedule is for reconciliation only.',
        'specification' => [
            'type' => 'delimited', 'separator' => ',', 'quote' => true, 'header' => true, 'encoding' => 'UTF-8', 'line_ending' => "\n",
            'fields' => [
                ['name' => 'Employee', 'required' => true], ['name' => 'State', 'required' => true, 'pattern' => '/^[A-Z]{2}$/'],
                ['name' => 'Employee contribution', 'required' => true, 'pattern' => $decimal], ['name' => 'Employer contribution', 'required' => true, 'pattern' => $decimal],
            ],
        ],
    ],
    [
        'code' => 'TDS_FORM_138', 'version' => 1, 'name' => 'Form No. 138 working schedule (not the utility / FVU file)', 'authority' => 'INCOME_TAX',
        'notes' => 'The Form No. 138 utility / FVU schema was not retrieved; this is a working schedule.',
        'specification' => [
            'type' => 'delimited', 'separator' => ',', 'quote' => true, 'header' => true, 'encoding' => 'UTF-8', 'line_ending' => "\n",
            'fields' => [
                ['name' => 'PAN', 'required' => true, 'pattern' => '/^([A-Z]{5}[0-9]{4}[A-Z]|PANNOTAVBL)$/'],
                ['name' => 'Deductee name', 'required' => true], ['name' => 'Section', 'required' => true],
                ['name' => 'Date of payment/credit', 'required' => true, 'pattern' => '/^\d{4}-\d{2}-\d{2}$/'],
                ['name' => 'Amount paid/credited', 'required' => true, 'pattern' => $decimal], ['name' => 'Tax deducted', 'required' => true, 'pattern' => $decimal],
                ['name' => 'Reason code', 'required' => false],
            ],
        ],
    ],
];
