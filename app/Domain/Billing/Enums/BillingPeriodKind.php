<?php

namespace App\Domain\Billing\Enums;

/**
 * SaaS.7 completion (B-3): what a calculated billing period charges.
 *
 * - monthly_arrears: a calendar month on monthly terms, billed after it ends on its peak (prorated by days when
 *   only part of it was billable: the first and last months).
 * - annual_advance: a 12-month annual term, billed when it starts on the committed quantity × 12 months.
 * - annual_true_up: a calendar month inside an annual term, billed after it ends on the peak above the commitment.
 */
enum BillingPeriodKind: string
{
    case MonthlyArrears = 'monthly_arrears';
    case AnnualAdvance = 'annual_advance';
    case AnnualTrueUp = 'annual_true_up';

    public function label(): string
    {
        return match ($this) {
            self::MonthlyArrears => 'Monthly (in arrears)',
            self::AnnualAdvance => 'Annual term (in advance)',
            self::AnnualTrueUp => 'Annual true-up (in arrears)',
        };
    }
}
