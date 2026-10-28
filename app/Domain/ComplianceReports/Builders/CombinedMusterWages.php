<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;

/**
 * Combined muster roll-cum-register of wages, the single register several states prescribe for shops and
 * establishments (Maharashtra Form Q, Karnataka Form T, Gujarat Form P, Madhya Pradesh Form N, Uttar Pradesh Form G…):
 * each employee's daily attendance, days worked and overtime from the muster roll, then the month's rate, wages
 * earned, deductions and net pay from the wage register, on one row.
 */
final class CombinedMusterWages extends Builder
{
    private const SHARED = ['sl', 'code', 'name', 'designation', 'department', 'duration', 'wage_period', 'days_worked', 'ot_hours'];

    public function __construct(private readonly MusterRoll $muster, private readonly WageRegister $wages) {}

    public function build(ReportContext $context): array
    {
        $muster = $this->muster->build($context)[0];
        $wages = $this->wages->build($context)[0];
        $wageColumns = array_diff_key($wages->columns, array_flip(self::SHARED));
        $byCode = collect($wages->rows)->keyBy('code');

        $rows = array_map(fn (array $row) => $row + array_intersect_key($byCode->get($row['code'], []), $wageColumns), $muster->rows);
        $columns = $muster->columns + $wageColumns;
        $totals = $muster->totals === null ? null : $muster->totals + array_intersect_key((array) $wages->totals, $wageColumns);

        return [$this->sheet($context, $columns, $rows, [
            'money' => $wages->money,
            'totals' => $totals,
            'message' => $muster->message,
            'notes' => [...array_diff($muster->notes, $context->definition->notes), ...($wages->isEmpty() ? [(string) $wages->message] : array_slice($wages->notes, 0, 1))],
            'warnings' => [...$muster->warnings, ...$wages->warnings],
            'rules' => $wages->rules,
        ])];
    }
}
