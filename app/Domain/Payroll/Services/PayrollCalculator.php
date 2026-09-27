<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Models\AttendanceRecord;
use App\Domain\Compliance\Services\StatutoryEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use RuntimeException;

/**
 * The per-employee pipeline (§31): Eligibility → Components (formula) → Proration (LOP, joins,
 * exits) → Adjustments → Statutory (compliance engine) → Tax → Result. Pure: writes nothing.
 */
final class PayrollCalculator
{
    public function __construct(
        private readonly Salaries $salaries,
        private readonly FormulaEngine $formulas,
        private readonly StatutoryEngine $statutory,
        private readonly SettingsRepository $settings,
    ) {}

    public function calculate(Employee $employee, PayrollPeriod $period): PayrollComputation
    {
        $employee->loadMissing(['person', 'statutoryDetail', 'bankAccounts']);
        $assignment = $this->salaries->current($employee, $period->end_date) ?? $this->salaries->current($employee, $period->start_date);
        $c = new PayrollComputation($employee, $period, $assignment);
        $c->daysInPeriod = $period->daysInPeriod();

        $this->attendance($c);

        if ($assignment === null) {
            $c->exception('no_salary', 'No salary assignment is effective in this period.');

            return $c;
        }

        $assignment->loadMissing('structure.items.component');
        $items = $assignment->structure->items->filter(fn ($i) => $i->component && $i->component->status->value === 'active');

        if ($items->isEmpty()) {
            $c->exception('no_structure', "Salary structure {$assignment->structure->code} has no active components.");

            return $c;
        }

        $c->inputs = ['ctc_annual' => (float) $assignment->ctc_annual, 'structure' => $assignment->structure->code, 'assignment_id' => $assignment->id, 'paid_days' => $c->paidDays, 'lop_days' => $c->lopDays, 'days_in_period' => $c->daysInPeriod];

        $proration = $c->daysInPeriod > 0 ? $c->paidDays / $c->daysInPeriod : 0;
        $full = []; // full-month amounts by variable name

        $variables = function (string $name) use (&$full, $assignment, $c) {
            return match (true) {
                isset($full[$name]) => $full[$name],
                $name === 'ctc_annual' => (float) $assignment->ctc_annual,
                $name === 'ctc_monthly' => $assignment->monthlyCtc(),
                $name === 'paid_days' => $c->paidDays,
                $name === 'lop_days' => $c->lopDays,
                $name === 'days_in_period' => (float) $c->daysInPeriod,
                $name === 'pf_employer' => $this->statutory->estimateEmployerPf($c, array_sum(array_intersect_key($full, array_flip($this->pfVariables($assignment->structure->items))))),
                default => $assignment->component_values !== null && array_key_exists(strtoupper($name), $assignment->component_values) ? (float) $assignment->component_values[strtoupper($name)] : null,
            };
        };

        foreach ($items as $item) {
            /** @var SalaryComponent $component */
            $component = $item->component;

            if ($component->is_statutory || $component->calculation_method === 'statutory') {
                continue; // produced by the compliance engine below
            }

            if (! $component->is_recurring) {
                continue; // one-off components enter through adjustments
            }

            try {
                $formula = $item->formula_override ?: $component->formula;
                $amount = $component->calculation_method === 'formula' && $formula
                    ? $this->formulas->evaluate($formula, $variables)
                    : $assignment->valueFor($component->code);
            } catch (RuntimeException $e) {
                $c->exception('formula_error', "{$component->code}: {$e->getMessage()}");
                $amount = 0;
                $formula = $formula ?? null;
            }

            $amount = max(0, round($amount, 2));
            $full[$component->variableName()] = $amount;
            $paid = $component->is_proratable ? round($amount * $proration, 2) : $amount;

            $c->fromComponent($component, $paid, ['formula' => $formula ?? null, 'prorated' => $component->is_proratable, 'proration' => round($proration, 6)], $amount);
        }

        $this->adjustments($c);
        $this->statutory->apply($c);

        if ($c->net() < 0) {
            $c->exception('negative_net', 'Deductions exceed earnings; net pay is negative.');
        }

        if ($employee->bankAccounts->where('is_primary', true)->isEmpty()) {
            $c->exception('no_bank', 'No primary bank account on file.');
        }

        return $c;
    }

    private function pfVariables($items): array
    {
        return $items->filter(fn ($i) => $i->component?->pf_applicable && $i->component->type === 'earning')->map(fn ($i) => $i->component->variableName())->values()->all();
    }

    /** Paid days = calendar days, less days before joining / after exit, less LOP from attendance and manual LOP. */
    private function attendance(PayrollComputation $c): void
    {
        $employee = $c->employee;
        $period = $c->period;
        $start = $period->start_date->copy();
        $end = $period->end_date->copy();

        if ($employee->joining_date && $employee->joining_date->gt($start)) {
            $start = $employee->joining_date->copy()->startOfDay();
        }
        if ($employee->exit_date && $employee->exit_date->lt($end)) {
            $end = $employee->exit_date->copy()->startOfDay();
        }

        $eligibleDays = $end->lt($start) ? 0 : (int) $start->diffInDays($end) + 1;
        $lop = 0.0;

        if ((bool) $this->settings->get('payroll.lop_from_attendance', true) && $eligibleDays > 0) {
            $records = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get(['status', 'is_half_day_leave', 'date']);

            if ($records->isEmpty()) {
                $c->exception('attendance_missing', 'No processed attendance in this period; no attendance LOP applied.');
            }

            foreach ($records as $record) {
                $lop += match ($record->status) {
                    'absent' => 1.0,
                    'unpaid_leave' => $record->is_half_day_leave ? 0.5 : 1.0,
                    default => 0.0,
                };
            }
        }

        $manual = PayrollAdjustment::query()->where('employee_id', $employee->id)->where('payroll_period_id', $period->id)->where('type', 'lop')->sum('amount');
        $lop += (float) $manual;
        $lop = min($lop, $eligibleDays);

        $c->lopDays = round($lop, 2);
        $c->paidDays = round(max(0, $eligibleDays - $lop), 2);
        $c->inputs['eligible_days'] = $eligibleDays;
        $c->inputs['manual_lop'] = (float) $manual;
    }

    private function adjustments(PayrollComputation $c): void
    {
        $adjustments = PayrollAdjustment::query()->with('component')
            ->where('employee_id', $c->employee->id)->where('payroll_period_id', $c->period->id)
            ->where('type', '!=', 'lop')->orderBy('id')->get();

        foreach ($adjustments as $i => $adj) {
            $component = $adj->component;
            $type = match ($adj->type) {
                'deduction' => 'deduction', 'reimbursement' => 'reimbursement', default => 'earning'
            };
            $code = $component?->code ?? ('ADJ'.($i + 1));

            $c->addLine($code, $adj->name, $type, (float) $adj->amount, [
                'taxable' => $type === 'earning' && ($component ? $component->taxable : $adj->taxable),
                'classification' => $component?->classification ?? ($type === 'deduction' ? 'other_deduction' : 'other'),
                'salary_component_id' => $component?->id,
                'basis' => ['adjustment_id' => $adj->id, 'note' => $adj->note],
                'sort_order' => 200 + $i,
                'pf_applicable' => (bool) $component?->pf_applicable,
                'esi_applicable' => $component ? $component->esi_applicable : $type === 'earning',
                'include_in_gross' => $component ? $component->include_in_gross : $type === 'earning',
            ]);
        }
    }
}
