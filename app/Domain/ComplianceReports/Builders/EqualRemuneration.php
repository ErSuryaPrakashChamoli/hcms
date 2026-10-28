<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * Equal remuneration register: per designation, how many men, women and others are employed and the range of their
 * monthly rate of wages (full-month earnings in their latest finalized payroll of the period), so that unequal pay for
 * the same work stands out.
 */
final class EqualRemuneration extends Builder
{
    public function build(ReportContext $context): array
    {
        $latest = [];
        foreach ($context->period->months() as $month) {
            foreach ($context->data->payrollFor($context, $month) as $entry) {
                $latest[(int) $entry->employee_id] = $entry;
            }
        }

        $groups = [];
        foreach ($latest as $entry) {
            /** @var PayrollEntry $entry */
            $designation = $entry->employee ? ($this->designation($context, $entry->employee) ?: 'No designation') : 'No designation';
            $gender = $this->gender($entry->employee?->person) ?: 'O';
            $rate = $this->rate($entry);
            $basic = $this->rate($entry, ['basic', 'da']);
            $groups[$designation][$gender][] = ['rate' => $rate, 'basic' => $basic];
        }
        ksort($groups);

        $range = fn (array $items, string $key) => $items === [] ? '' : number_format(min(array_column($items, $key)), 2).($items !== [] && min(array_column($items, $key)) != max(array_column($items, $key)) ? ' – '.number_format(max(array_column($items, $key)), 2) : '');
        $rows = [];
        foreach ($groups as $designation => $byGender) {
            $men = $byGender['M'] ?? [];
            $women = $byGender['F'] ?? [];
            $others = $byGender['O'] ?? [];
            $flag = $men !== [] && $women !== [] && (min(array_column($women, 'basic')) < min(array_column($men, 'basic')) || max(array_column($women, 'basic')) < max(array_column($men, 'basic')));
            $rows[] = [
                'designation' => $designation,
                'men' => count($men), 'women' => count($women), 'others' => count($others),
                'men_rate' => $range($men, 'rate'), 'women_rate' => $range($women, 'rate'), 'others_rate' => $range($others, 'rate'),
                'men_basic' => $range($men, 'basic'), 'women_basic' => $range($women, 'basic'),
                'review' => $flag ? 'Review: women\'s basic + DA range is below men\'s' : '',
            ];
        }

        $columns = ['sl' => 'Sl.', 'designation' => 'Category / designation', 'men' => 'Men', 'women' => 'Women', 'others' => 'Others',
            'men_rate' => 'Monthly rate — men', 'women_rate' => 'Monthly rate — women', 'others_rate' => 'Monthly rate — others',
            'men_basic' => 'Basic + DA — men', 'women_basic' => 'Basic + DA — women', 'review' => 'Review'];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'message' => $this->noPayrollMessage($context->forPeriod($context->period->months()[count($context->period->months()) - 1])),
            'warnings' => $this->payrollGaps($context),
            'notes' => ['Differences can be justified by grade, experience or performance; the register shows the ranges so they can be reviewed.'],
        ])];
    }
}
