<?php

namespace App\Domain\Compliance\Support;

/**
 * What an employee can declare under each tax-saving section (old regime), written for someone filing for the first
 * time: every option with what counts and the proof payroll will ask for. Section keys are the keys TaxComputer reads
 * from employee_tax_declarations.declarations; the employer's NPS share (80CCD(2)) is payroll's own and not declared.
 * Item keys ("80C.ppf") are stored on tds_employee_investments.item_key. Labels may change; keys must not.
 */
final class InvestmentCatalogue
{
    public const SECTIONS = [
        'HRA_RENT' => [
            'title' => 'Rent you pay (HRA)',
            'step' => 'Rent',
            'code' => 'HRA',
            'intro' => 'If you live in a rented home and your salary includes House Rent Allowance (HRA), part of that allowance can be tax-free. Tell us the rent you pay. Skip this step if you live in your own home or with family without paying rent.',
            'items' => [
                'rent' => ['label' => 'Rent paid for the home you live in', 'help' => 'Monthly rent times the months you rented this year.', 'proof' => 'Rent receipts or the rental agreement. The landlord’s PAN when the rent is more than ₹1,00,000 a year.'],
            ],
        ],
        '80C' => [
            'title' => 'Savings and investments (Section 80C)',
            'step' => '80C savings',
            'code' => '80C',
            'intro' => 'The most common tax savers. Everything you tick here shares one limit. Your Provident Fund (PF) deducted from salary already counts here: payroll adds it for you, so do not enter it again.',
            'items' => [
                'ppf' => ['label' => 'Public Provident Fund (PPF)', 'help' => 'Money you deposited this year in your own, your spouse’s or your children’s PPF account.', 'proof' => 'PPF passbook or deposit receipts'],
                'life_insurance' => ['label' => 'Life insurance premium', 'help' => 'Premium for a life insurance policy (LIC or any insurer) on your life, your spouse’s or your children’s. Not health or vehicle insurance.', 'proof' => 'Premium receipts'],
                'elss' => ['label' => 'Tax-saving mutual funds (ELSS)', 'help' => 'Equity Linked Savings Scheme investments. The fund statement says “ELSS” or “tax saver”.', 'proof' => 'Mutual fund account statement'],
                'tuition_fees' => ['label' => 'Children’s tuition fees', 'help' => 'Tuition fees paid to a school, college or university in India for up to two children. Only the tuition part, not transport, hostel or development fees.', 'proof' => 'Fee receipts showing the tuition fee'],
                'home_loan_principal' => ['label' => 'Home loan principal repayment', 'help' => 'The principal part of your home loan EMIs (not the interest). Your lender’s yearly certificate shows the split.', 'proof' => 'Home loan certificate from the lender'],
                'tax_saver_fd' => ['label' => '5-year tax-saver fixed deposit', 'help' => 'A bank or post office fixed deposit marked “tax saving”, locked in for 5 years.', 'proof' => 'Fixed deposit receipt'],
                'nsc' => ['label' => 'National Savings Certificate (NSC)', 'help' => 'NSC bought at the post office this year.', 'proof' => 'NSC certificate'],
                'sukanya' => ['label' => 'Sukanya Samriddhi Yojana', 'help' => 'Deposits in a Sukanya Samriddhi account for your daughter.', 'proof' => 'Passbook or deposit receipts'],
                'scss' => ['label' => 'Senior Citizens’ Savings Scheme', 'help' => 'Deposits in the Senior Citizens’ Savings Scheme (only if you are 60 or older).', 'proof' => 'Deposit receipt'],
                'stamp_duty' => ['label' => 'Stamp duty and registration of a new home', 'help' => 'Stamp duty and registration fees paid this year to buy a residential house.', 'proof' => 'Registration and stamp duty receipt'],
                'ulip' => ['label' => 'Unit Linked Insurance Plan (ULIP)', 'help' => 'Premium paid for a ULIP.', 'proof' => 'Premium receipt'],
                'pension_plan' => ['label' => 'Pension plan from an insurer (80CCC)', 'help' => 'Premium for a pension or annuity plan from LIC or another insurer.', 'proof' => 'Premium receipt'],
                'other' => ['label' => 'Something else under 80C', 'help' => 'Another 80C investment not listed above. Describe it.', 'proof' => 'Receipt or statement', 'other' => true],
            ],
        ],
        '80CCD1B' => [
            'title' => 'National Pension System (Section 80CCD(1B))',
            'step' => 'NPS',
            'code' => '80CCD(1B)',
            'intro' => 'Your own contribution to the National Pension System (NPS) gets an extra deduction on top of the 80C limit. Enter only what you paid yourself; your employer’s contribution is handled by payroll.',
            'items' => [
                'nps_tier1' => ['label' => 'Your NPS Tier I contribution', 'help' => 'Money you put into your NPS Tier I account this year (Tier II does not count).', 'proof' => 'NPS transaction statement'],
            ],
        ],
        '80D' => [
            'title' => 'Health insurance (Section 80D)',
            'step' => 'Health insurance',
            'code' => '80D',
            'intro' => 'Health insurance premiums and health check-ups for your family and your parents. Do not include insurance your employer pays for.',
            'items' => [
                'self_family' => ['label' => 'Health insurance for you, your spouse and children', 'help' => 'Mediclaim or health insurance premium you paid yourself (not by cash).', 'proof' => 'Premium receipt'],
                'parents' => ['label' => 'Health insurance for your parents', 'help' => 'Premium you paid for your father’s or mother’s health insurance.', 'proof' => 'Premium receipt'],
                'checkup' => ['label' => 'Preventive health check-up', 'help' => 'Health check-ups for your family or parents, up to ₹5,000 in total. Cash payment is allowed for this one.', 'proof' => 'Bill from the hospital or lab'],
                'senior_parents_medical' => ['label' => 'Medical bills of senior-citizen parents without insurance', 'help' => 'Medical expenses you paid for parents aged 60 or more who have no health insurance.', 'proof' => 'Medical bills'],
            ],
        ],
        '80E' => [
            'title' => 'Education loan interest (Section 80E)',
            'step' => 'Education loan',
            'code' => '80E',
            'intro' => 'Interest (not the principal) on a loan from a bank or approved institution for higher studies of yourself, your spouse or your children. There is no upper limit; it is available for 8 years from when you start repaying.',
            'items' => [
                'education_loan_interest' => ['label' => 'Interest paid on an education loan', 'help' => 'The interest part of this year’s repayments.', 'proof' => 'Interest certificate from the bank'],
            ],
        ],
        '80G' => [
            'title' => 'Donations (Section 80G)',
            'step' => 'Donations',
            'code' => '80G',
            'intro' => 'Donations to approved relief funds and charities. Enter the amount that qualifies: the receipt shows the charity’s 80G registration and whether the donation counts in full or at 50%. Cash donations above ₹2,000 do not qualify.',
            'items' => [
                'relief_funds' => ['label' => 'PM National Relief Fund, PM CARES and other government funds', 'help' => 'These usually count in full.', 'proof' => 'Donation receipt'],
                'charities' => ['label' => 'Registered charities and trusts', 'help' => 'Most count at 50% of what you gave. Enter the qualifying amount from the receipt.', 'proof' => 'Donation receipt with the 80G registration number'],
            ],
        ],
        '80TTA' => [
            'title' => 'Savings account interest (Section 80TTA)',
            'step' => 'Savings interest',
            'code' => '80TTA',
            'intro' => 'Interest earned on savings accounts in a bank, co-operative bank or post office. Fixed deposit and recurring deposit interest do not count.',
            'items' => [
                'savings_interest' => ['label' => 'Interest on your savings accounts', 'help' => 'The total savings interest credited this year across your accounts.', 'proof' => 'Bank statement or interest certificate'],
            ],
        ],
        // HROne parity PY-13: the other heads an employee may report to the employer (s.192(2B)).
        '24B' => [
            'title' => 'Home loan interest (Section 24(b))',
            'step' => 'Home loan',
            'code' => '24(b)',
            'kind' => 'house_property',
            'intro' => 'Interest (not the principal) on a loan taken to buy, build or repair the home you live in. The principal part goes under 80C. Payroll counts this only in the old regime and only up to the limit in the tax rule.',
            'items' => [
                'self_occupied' => ['label' => 'Interest on the home loan for the home you live in', 'help' => 'The interest part of this year’s EMIs. Your lender’s yearly certificate shows the split.', 'proof' => 'Interest certificate from the lender, with the lender’s name, address and PAN'],
            ],
        ],
        'OTHER_INCOME' => [
            'title' => 'Other income you want taxed through salary',
            'step' => 'Other income',
            'code' => 'Other income',
            'kind' => 'income',
            'intro' => 'Income other than this salary that you want payroll to tax with your salary, such as interest on fixed deposits, so you do not owe tax when you file your return. This increases the tax deducted each month. Leave it empty if you would rather pay that tax yourself.',
            'items' => [
                'deposit_interest' => ['label' => 'Interest on fixed and recurring deposits', 'help' => 'Interest you expect for the year from fixed or recurring deposits.', 'proof' => 'Interest certificate or statement'],
                'other' => ['label' => 'Other income', 'help' => 'Another income to tax with your salary. Describe it.', 'proof' => 'Statement or certificate', 'other' => true],
            ],
        ],
    ];

    /** The form-safe key of a section ("80C" → "s_80C"): state paths must not start with a digit. */
    public static function formKey(string $section): string
    {
        return 's_'.$section;
    }

    /** @return array{label: string, help: string, proof: string, other?: bool}|null */
    public static function item(string $itemKey): ?array
    {
        [$section, $item] = array_pad(explode('.', $itemKey, 2), 2, null);

        return self::SECTIONS[$section]['items'][$item] ?? null;
    }

    /** deduction (default), house_property or income: how a section changes taxable income. */
    public static function kind(string $section): string
    {
        return self::SECTIONS[$section]['kind'] ?? 'deduction';
    }

    public static function isOther(string $itemKey): bool
    {
        return (bool) (self::item($itemKey)['other'] ?? false);
    }

    /** The item that carries a section total declared before items existed (or entered as a lump sum). */
    public static function fallbackItem(string $section): string
    {
        $items = self::SECTIONS[$section]['items'];

        return $section.'.'.(isset($items['other']) ? 'other' : array_key_first($items));
    }
}
