<?php

namespace App\Support\Settlements;

use Closure;

/**
 * Streamline S2 (decision ST-1): reopening the latest closed payroll month gives back everything closing it settled,
 * inside one audited transaction (App\Domain\Payroll\Services\PayrollMonthReopen). Records that are otherwise final
 * once settled (the closed run, its period and payslips, deducted pledges, paid-out reward points) accept that one
 * change only while a reversal runs. Nothing else may call during().
 */
final class SettlementReversal
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function during(Closure $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
