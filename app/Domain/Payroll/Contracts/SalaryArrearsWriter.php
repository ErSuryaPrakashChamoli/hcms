<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;

/**
 * Owner, 2026-11-27: a salary change dated in a month whose payroll is already finalised is paid as arrears. Payroll's
 * contract to Compensation: whether that is switched on, which closed months a date reaches (for previews and upload
 * checks) and, once Compensation has written the change into the timeline, the arrears themselves — each closed month
 * recalculated on the new salary and compared, pay item by pay item, with what was paid; the difference becomes an
 * arrear (or a recovery) in the employee's next open payroll month. Closed payroll is never rewritten.
 */
interface SalaryArrearsWriter
{
    /** Whether a change dated in a finalised month is accepted and paid as arrears (off = refused, as before). */
    public function enabled(): bool;

    /**
     * The finalised months of the employee a change dated $from reaches, and the month that would pay the arrears.
     * Reads without locking (previews and upload checks); null when no finalised month is reached.
     *
     * @return array{through: string, months: list<array{period_id: int, label: string, start: string, end: string}>, payout: string}|null
     */
    public function reach(int $employeeId, CarbonInterface $from): ?array;

    /**
     * Record the arrears of a change already written into the timeline (inside the transaction that wrote it).
     *
     * @param  array{type: string, id: int, reference: string}  $source  what the arrears pay for (kept on each adjustment)
     * @return array{months: list<string>, payout: ?string, total: float, lines: list<array{month: string, code: string, name: string, paid: float, due: float, difference: float}>}
     */
    public function record(int $employeeId, CarbonInterface $from, array $source, ?User $actor = null): array;
}
