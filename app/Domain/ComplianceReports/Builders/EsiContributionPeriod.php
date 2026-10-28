<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * ESI contribution period summary (April–September or October–March): per insured person the days paid, wages and
 * contributions month by month, with totals. Used to confirm coverage continuity within the contribution period and
 * the benefit-period eligibility questions ESIC asks.
 */
final class EsiContributionPeriod extends Builder
{
    public function build(ReportContext $context): array
    {
        $months = $context->period->months();
        $people = [];
        foreach ($months as $month) {
            foreach ($context->data->payrollFor($context, $month) as $entry) {
                /** @var PayrollEntry $entry */
                if (! $entry->lines->contains('code', 'ESI_EE') && ! $entry->lines->contains('code', 'ESI_ER')) {
                    continue;
                }
                $key = (int) $entry->employee_id;
                $m = $month->start->format('Ym');
                $people[$key] ??= ['ip' => $context->identifier($entry->employee?->statutoryDetail?->esic_number), 'code' => $entry->employee?->employee_code, 'name' => $this->name($entry->employee), 'days' => 0.0, 'wages' => 0.0, 'ee' => 0.0, 'er' => 0.0];
                $wages = round((float) ($entry->lines->firstWhere('code', 'ESI_EE')?->basis['wages'] ?? $entry->gross), 2);
                $people[$key]['w_'.$m] = $wages;
                $people[$key]['days'] += (float) $entry->paid_days;
                $people[$key]['wages'] = round($people[$key]['wages'] + $wages, 2);
                $people[$key]['ee'] = round($people[$key]['ee'] + $this->amount($entry, 'ESI_EE'), 2);
                $people[$key]['er'] = round($people[$key]['er'] + $this->amount($entry, 'ESI_ER'), 2);
            }
        }

        $columns = ['sl' => 'Sl.', 'ip' => 'IP number', 'code' => 'Code', 'name' => 'Name'];
        foreach ($months as $month) {
            $columns['w_'.$month->start->format('Ym')] = 'Wages '.$month->start->format('M Y');
        }
        $columns += ['days' => 'Days paid', 'wages' => 'Total wages', 'ee' => 'Employee contribution', 'er' => 'Employer contribution'];
        $money = [...array_map(fn ($m) => 'w_'.$m->start->format('Ym'), $months), 'wages', 'ee', 'er'];
        $rows = array_values($people);

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => $money,
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, [...$money, 'days']),
            'message' => 'No ESI contributions in the contribution period.',
            'registration' => 'esic_employer_code',
            'warnings' => [...$this->payrollGaps($context), ...$this->registrationGaps($context, 'esic_employer_code')],
            'rules' => $this->rules($context, collect($months)->flatMap(fn ($m) => $context->data->payrollFor($context, $m)), ['ESI_EE', 'ESI_ER']),
        ])];
    }
}
