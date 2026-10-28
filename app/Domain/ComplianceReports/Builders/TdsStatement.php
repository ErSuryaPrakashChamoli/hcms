<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\Payroll\Models\PayrollEntry;

/**
 * Salary TDS for a legal entity (one TAN) over a month, quarter or year: per employee the salary paid and tax deducted
 * in each month of the period, with totals. Option "certificates" adds the status of each employee's salary TDS
 * certificate for the financial year. The quarterly statement itself is generated, approved and exported through
 * Compliance → TDS returns, and certificates are issued there.
 */
final class TdsStatement extends Builder
{
    public function build(ReportContext $context): array
    {
        $months = $context->period->months();
        $employees = [];
        $payrollSeen = false;

        foreach ($months as $month) {
            $entries = $context->data->payrollFor($context, $month);
            $payrollSeen = $payrollSeen || $entries->isNotEmpty();
            foreach ($entries as $entry) {
                /** @var PayrollEntry $entry */
                $tds = $this->amount($entry, 'TDS');
                $key = (int) $entry->employee_id;
                $employees[$key] ??= ['pan' => $context->identifier($entry->employee?->statutoryDetail?->pan), 'code' => $entry->employee?->employee_code, 'name' => $this->name($entry->employee), 'salary' => 0.0, 'tds' => 0.0];
                $employees[$key]['s_'.$month->start->format('Ym')] = round((float) $entry->total_earnings, 2);
                $employees[$key]['t_'.$month->start->format('Ym')] = $tds;
                $employees[$key]['salary'] = round($employees[$key]['salary'] + $employees[$key]['s_'.$month->start->format('Ym')], 2);
                $employees[$key]['tds'] = round($employees[$key]['tds'] + $tds, 2);
            }
        }

        $columns = ['sl' => 'Sl.', 'pan' => 'PAN', 'code' => 'Code', 'name' => 'Name'];
        $money = [];
        foreach ($months as $month) {
            $label = $month->start->format('M Y');
            $columns['s_'.$month->start->format('Ym')] = "Salary {$label}";
            $columns['t_'.$month->start->format('Ym')] = "TDS {$label}";
            $money[] = 's_'.$month->start->format('Ym');
            $money[] = 't_'.$month->start->format('Ym');
        }
        $columns += ['salary' => 'Salary paid in period', 'tds' => 'Tax deducted in period'];
        $money = [...$money, 'salary', 'tds'];

        if (($context->definition->options['certificates'] ?? false) && $employees !== []) {
            $columns['certificate'] = 'Salary TDS certificate';
            $fy = FinancialYear::make()->label($context->period->start);
            $certificates = TdsCertificate::query()->whereIn('employee_id', array_keys($employees))->where('financial_year', $fy)->orderByDesc('version')->get()->unique('employee_id')->keyBy('employee_id');
            foreach ($employees as $id => $row) {
                $certificate = $certificates->get($id);
                $employees[$id]['certificate'] = $certificate === null ? 'Not generated' : ucfirst((string) $certificate->status).($certificate->issued_at ? ' on '.$this->date($certificate->issued_at) : '');
            }
        }

        $rows = array_values($employees);
        $missingPan = count(array_filter($rows, fn ($r) => $r['tds'] > 0 && $r['pan'] === ''));

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => $money,
            'totals' => $rows === [] ? null : ReportSheet::sum($columns, $rows, $money),
            'message' => $payrollSeen ? 'No salary paid in the period.' : $this->noPayrollMessage($context->forPeriod($months[count($months) - 1])),
            'registration' => 'tan',
            'warnings' => [...$this->payrollGaps($context), ...$this->registrationGaps($context, 'tan'), ...($missingPan > 0 ? ["{$missingPan} employee(s) with tax deducted have no PAN on file."] : [])],
            'rules' => $this->rules($context, collect($months)->flatMap(fn ($m) => $context->data->payrollFor($context, $m)), ['TDS']),
            'notes' => $missingPan > 0 ? ["{$missingPan} employee(s) with tax deducted have no PAN on file: tax is deducted at the higher rate and the statement will be rejected — record their PAN."] : [],
        ])];
    }
}
