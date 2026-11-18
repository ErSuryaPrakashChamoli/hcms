<?php

namespace App\Domain\Website\Support;

/**
 * Statutory figures the public website's CTC calculator uses (resources/js/site.js reads them from the block's
 * data-rules attribute, so the labels, the note and the maths share one source). They mirror the India compliance
 * pack — database/data/compliance/in.php EPF v2 and ESI v1 — and the evidence filed with it:
 *
 * - PF: 12% employee and 12% employer on PF wages (basic + DA + retaining allowance), EPF Scheme, 2026 para 18(2).
 *   Wage ceiling ₹25,000 a month from 17 Sep 2026 (S.O. 5109(E), replacing ₹15,000). An employee whose PF wages are
 *   above the ceiling when they would join is an "excluded employee" (EPF Scheme, 2026 para 2(f)(i)) and need not
 *   join; anyone at or below it must (EPFO wage-ceiling FAQs Q26–Q29). Contributions round to the nearest rupee.
 * - ESI: 0.75% employee and 3.25% employer on gross wages; coverage up to ₹21,000 a month (₹25,000 for a person
 *   with a disability); no employee share at a daily average wage of ₹176 or less (esic.gov.in, Contribution and
 *   Coverage pages). Contributions are rounded up to the next rupee, as payroll does.
 *
 * A website illustration, not payroll: the payroll engine reads the verified rule versions instead.
 */
final class CalculatorRules
{
    public const PF_RATE = 0.12;

    public const PF_WAGE_CEILING = 25000;

    public const PF_CEILING_FROM = '17 September 2026';

    public const ESI_EMPLOYEE_RATE = 0.0075;

    public const ESI_EMPLOYER_RATE = 0.0325;

    public const ESI_WAGE_CEILING = 21000;

    public const ESI_WAGE_CEILING_DISABILITY = 25000;

    public const ESI_EXEMPT_DAILY_WAGE = 176;

    public const GRATUITY_SHARE = 0.0481;

    /** @return array<string, float|int> what the CTC calculator's script needs */
    public static function ctc(): array
    {
        return [
            'pf_rate' => self::PF_RATE, 'pf_ceiling' => self::PF_WAGE_CEILING,
            'esi_employee' => self::ESI_EMPLOYEE_RATE, 'esi_employer' => self::ESI_EMPLOYER_RATE,
            'esi_ceiling' => self::ESI_WAGE_CEILING, 'esi_ceiling_disability' => self::ESI_WAGE_CEILING_DISABILITY,
            'esi_exempt_daily' => self::ESI_EXEMPT_DAILY_WAGE, 'gratuity' => self::GRATUITY_SHARE,
        ];
    }

    /** "₹25,000" */
    public static function inr(int $amount): string
    {
        return '₹'.number_format($amount);
    }
}
