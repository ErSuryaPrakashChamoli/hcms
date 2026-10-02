<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Attendance\Services\AttendanceOutput;
use App\Domain\Attendance\Support\AttendanceDay;
use App\Domain\Compensation\Contracts\CompensationOutput;
use App\Domain\Compensation\Support\CompensationSegment;
use App\Domain\Compensation\Support\PayrollCompensation;
use App\Domain\Compliance\Services\StatutoryEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Services\LeaveOutput;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Payroll calculation engine (Phase 4). Deterministic pipeline for one employee and one period:
 *
 *   eligibility window (joining / exit) → salary segments (every approved compensation in force in
 *   the period, read through the Compensation contract, so mid-month revisions are split) → loss of pay from AttendanceOutput (absent days)
 *   and LeaveOutput (unpaid leave dates) + manual LOP → proration with an explicit divisor →
 *   earnings per segment → approved adjustments → statutory (compliance rules) → net → exceptions.
 *
 * It never recalculates attendance or leave and never reads or writes compensation tables; it consumes
 * the AttendanceOutput, LeaveOutput and CompensationOutput contracts. Every line keeps
 * its basis (segments, formula, divisor, rule), and the entry keeps the inputs used.
 */
class PayrollCalculator
{
    /**
     * 2.1 = 2.0 + Phase 5 establishment-resolved statutory context recorded on each entry.
     * 2.2 = 2.1 + Phase 7 salary TDS resolved by the payment date (rule, tax year, year-to-date).
     * 2.3 = 2.2 + Phase 11 compensation read through the CompensationOutput contract (approved
     *       compensation only; the contract version and a fingerprint of the compensation used are
     *       recorded, and finalisation refuses an entry whose compensation changed since). Amounts,
     *       proration and statutory treatment are unchanged.
     */
    public const VERSION = 'payroll-2.3';

    public const BLOCKING = ['no_salary', 'no_structure', 'negative_net', 'formula_error', 'invalid_component'];

    public function __construct(
        private readonly CompensationOutput $compensation,
        private readonly FormulaEngine $formulas,
        private readonly StatutoryEngine $statutory,
        private readonly SettingsRepository $settings,
        private readonly AttendanceOutput $attendance,
        private readonly LeaveOutput $leave,
    ) {}

    public function calculate(Employee $employee, PayrollPeriod $period): PayrollComputation
    {
        $employee->loadMissing(['person', 'statutoryDetail', 'bankAccounts']);

        // 1. Eligibility window.
        $start = $period->start_date->copy()->startOfDay();
        $end = $period->end_date->copy()->startOfDay();
        if ($employee->joining_date && $employee->joining_date->gt($start)) {
            $start = $employee->joining_date->copy()->startOfDay();
        }
        if ($employee->exit_date && $employee->exit_date->lt($end)) {
            $end = $employee->exit_date->copy()->startOfDay();
        }

        // 2. Salary segments: approved compensation for the period, clipped to the eligibility window.
        $compensation = $this->compensation->forPayroll($employee, $period->start_date, $period->end_date);
        $segments = $end->lt($start) ? collect() : $this->segments($compensation, $start, $end);
        $last = $segments->last();
        $c = new PayrollComputation($employee, $period, $last);
        $c->daysInPeriod = $period->daysInPeriod();

        // 3. Loss of pay from the attendance and leave contracts.
        $days = $this->attendance->forEmployee($employee, $period->start_date, $period->end_date)->keyBy('date');
        $lopByDate = $this->lossOfPay($c, $employee, $days, $start, $end);
        $manualLop = (float) PayrollAdjustment::query()->where('employee_id', $employee->id)->where('payroll_period_id', $period->id)->where('type', 'lop')->approved()->sum('amount');

        // 4. Divisor.
        [$basis, $divisor] = $this->divisor($c, $days);
        $eligibleUnits = $end->lt($start) ? 0.0 : $this->units($basis, $days, $start, $end);
        $lop = min($eligibleUnits, round(array_sum($lopByDate) + $manualLop, 2));

        $c->lopDays = round($lop, 2);
        $c->paidDays = round(max(0, $eligibleUnits - $lop), 2);
        $c->inputs = [
            'calculation_version' => self::VERSION,
            'compensation' => ['contract' => $compensation->contractVersion, 'fingerprint' => $compensation->fingerprint],
            'eligible_from' => $start->toDateString(), 'eligible_to' => $end->toDateString(),
            'proration_basis' => $basis, 'divisor' => $divisor, 'eligible_units' => $eligibleUnits,
            'lop_by_date' => $lopByDate, 'manual_lop' => $manualLop,
            'attendance' => ['days_processed' => $days->count(), 'unprocessed_days' => max(0, $c->daysInPeriod - $days->count()), 'approved_overtime_minutes' => (int) $days->sum('approvedOvertimeMinutes'), 'calculation_versions' => $days->pluck('calculationVersion')->filter()->unique()->values()->all()],
            'leave' => $this->leave->forEmployee($employee, $period->start_date, $period->end_date),
            'paid_days' => $c->paidDays, 'lop_days' => $c->lopDays, 'days_in_period' => $c->daysInPeriod,
        ];

        if ($segments->isEmpty()) {
            $c->exception('no_salary', 'No salary assignment is effective in this period.');

            return $c;
        }

        // 5. Earnings, segment by segment; manual LOP is taken from the last segment.
        $overtimeMinutes = (int) $days->sum('approvedOvertimeMinutes');
        $overtimeUsed = false;
        $perComponent = [];
        $segmentInputs = [];

        foreach ($segments as $i => $segment) {
            /** @var CompensationSegment $segment */
            $pay = $segment->compensation;
            $items = $segment->components->filter(fn ($line) => $line->component->status->value === 'active');

            if ($items->isEmpty()) {
                $c->exception('no_structure', "Salary structure {$pay->structureCode} has no active components.");

                return $c;
            }

            $segLop = array_sum(array_filter($lopByDate, fn ($date) => $date >= $segment->from->toDateString() && $date <= $segment->to->toDateString(), ARRAY_FILTER_USE_KEY));
            if ($i === $segments->count() - 1) {
                $segLop += $manualLop;
            }
            $units = $this->units($basis, $days, $segment->from, $segment->to);
            $segPaid = max(0, $units - $segLop);
            $proration = $divisor > 0 ? $segPaid / $divisor : 0.0;
            $segmentInputs[] = ['assignment_id' => $pay->assignmentId, 'structure' => $pay->structureCode, 'ctc_annual' => $pay->ctcAnnual, 'from' => $segment->from->toDateString(), 'to' => $segment->to->toDateString(), 'units' => $units, 'lop' => round($segLop, 2), 'paid_units' => round($segPaid, 2), 'proration' => round($proration, 6)];

            $full = [];
            $variables = function (string $name) use (&$full, $pay, $items, $c, $overtimeMinutes, &$overtimeUsed) {
                if (str_starts_with($name, 'overtime_')) {
                    $overtimeUsed = true;
                }

                return match (true) {
                    isset($full[$name]) => $full[$name],
                    $name === 'ctc_annual' => $pay->ctcAnnual,
                    $name === 'ctc_monthly' => $pay->monthlyCtc(),
                    $name === 'paid_days' => $c->paidDays,
                    $name === 'lop_days' => $c->lopDays,
                    $name === 'days_in_period' => (float) $c->daysInPeriod,
                    $name === 'overtime_minutes' => (float) $overtimeMinutes,
                    $name === 'overtime_hours' => round($overtimeMinutes / 60, 4),
                    $name === 'pf_employer' => $this->statutory->estimateEmployerPf($c, array_sum(array_intersect_key($full, array_flip($this->pfVariables($items))))),
                    default => $pay->hasValueFor($name) ? $pay->valueFor($name) : null,
                };
            };

            foreach ($items as $item) {
                /** @var SalaryComponent $component */
                $component = $item->component;
                if ($component->is_statutory || $component->calculation_method === 'statutory' || ! $component->is_recurring) {
                    continue; // statutory lines come from the compliance engine; one-offs from adjustments
                }
                if (! in_array($component->type, SalaryComponent::TYPES, true)) {
                    $c->exception('invalid_component', "{$component->code}: unknown component type [{$component->type}].");

                    continue;
                }

                $formula = $item->formulaOverride ?: $component->formula;
                try {
                    $amount = $component->calculation_method === 'formula' && $formula ? $this->formulas->evaluate($formula, $variables) : $pay->valueFor($component->code);
                } catch (RuntimeException $e) {
                    $c->exception('formula_error', "{$component->code}: {$e->getMessage()}");
                    $amount = 0;
                }

                $amount = max(0, round($amount, 2));
                $full[$component->variableName()] = $amount;
                // Prorated components are paid per segment; fixed monthly amounts and overtime-driven
                // components (already period quantities) are paid once, on the current (last) salary.
                $usesOvertime = $formula && str_contains($formula, 'overtime_');
                $isLast = $i === $segments->count() - 1;
                $paid = ($component->is_proratable && ! $usesOvertime) ? round($amount * $proration, 2) : ($isLast ? $amount : 0.0);

                $perComponent[$component->id] ??= ['component' => $component, 'paid' => 0.0, 'full' => 0.0, 'formula' => $formula, 'segments' => []];
                $perComponent[$component->id]['paid'] += $paid;
                $perComponent[$component->id]['full'] = $amount;
                $perComponent[$component->id]['segments'][] = ['from' => $segment->from->toDateString(), 'to' => $segment->to->toDateString(), 'full_month' => $amount, 'paid' => round($paid, 2), 'proration' => round($proration, 6)];
            }
        }

        foreach ($perComponent as $row) {
            $c->fromComponent($row['component'], round($row['paid'], 2), ['formula' => $row['formula'], 'prorated' => $row['component']->is_proratable, 'divisor' => $divisor, 'proration_basis' => $basis, 'segments' => $row['segments']], $row['full']);
        }

        $c->inputs['segments'] = $segmentInputs;
        $c->inputs['assignment_id'] = $last?->compensation->assignmentId;
        $c->inputs['structure'] = $last?->compensation->structureCode;
        $c->inputs['ctc_annual'] = (float) $last?->compensation->ctcAnnual;

        if ($overtimeMinutes > 0 && ! $overtimeUsed) {
            $c->exception('overtime_unpaid', "{$overtimeMinutes} approved overtime minute(s) but no salary component uses overtime_minutes / overtime_hours; no rate was invented.");
        }

        // 6. Adjustments, statutory, net, checks.
        $this->adjustments($c);
        $this->statutory->apply($c);

        if ($c->net() < 0 && $this->settings->get('payroll.negative_net_policy', 'block') !== 'allow') {
            $c->exception('negative_net', 'Deductions exceed earnings; net pay is negative.');
        }
        if ($employee->bankAccounts->where('is_primary', true)->isEmpty()) {
            $c->exception('no_bank', 'No primary bank account on file.');
        }

        return $c;
    }

    /** @return Collection<int, CompensationSegment> the contract's segments clipped to the eligibility window */
    private function segments(PayrollCompensation $compensation, Carbon $start, Carbon $end): Collection
    {
        return $compensation->segments
            ->map(fn (CompensationSegment $s) => new CompensationSegment($s->compensation, $s->from->copy()->max($start)->copy(), $s->to->copy()->min($end)->copy(), $s->components))
            ->filter(fn (CompensationSegment $s) => $s->from->lte($s->to))
            ->values();
    }

    /** @return array<string, float> date => LOP days inside the eligibility window */
    private function lossOfPay(PayrollComputation $c, Employee $employee, Collection $days, Carbon $start, Carbon $end): array
    {
        $lop = [];
        if (! (bool) $this->settings->get('payroll.lop_from_attendance', true)) {
            return $lop;
        }

        if ($days->isEmpty()) {
            $c->exception('attendance_missing', 'No processed attendance in this period; no attendance LOP applied.');
        } elseif ($days->count() < $c->daysInPeriod) {
            $c->exception('attendance_incomplete', ($c->daysInPeriod - $days->count()).' day(s) of the period have no processed attendance.');
        }

        /** @var AttendanceDay $day */
        foreach ($days as $date => $day) {
            if ($date >= $start->toDateString() && $date <= $end->toDateString() && $day->status === 'absent') {
                $lop[$date] = 1.0;
            }
        }

        foreach ($this->leave->unpaidDays($employee, $start, $end) as $date => $quantity) {
            $lop[$date] = min(1.0, ($lop[$date] ?? 0) + $quantity);
        }

        ksort($lop);

        return $lop;
    }

    /** @return array{0: string, 1: float} proration basis and divisor */
    private function divisor(PayrollComputation $c, Collection $days): array
    {
        $basis = (string) $this->settings->get('payroll.proration_basis', 'calendar_days');

        if ($basis === 'fixed_days') {
            return ['fixed_days', (float) $this->settings->get('payroll.proration_fixed_days', 30)];
        }
        if ($basis === 'working_days') {
            if ($days->count() < $c->daysInPeriod) {
                $c->exception('attendance_incomplete', 'Working-day proration needs attendance for every day of the period; calendar days were used.');

                return ['calendar_days', (float) $c->daysInPeriod];
            }

            return ['working_days', (float) $days->filter(fn (AttendanceDay $d) => $d->scheduledMinutes > 0)->count()];
        }

        return ['calendar_days', (float) $c->daysInPeriod];
    }

    private function units(string $basis, Collection $days, Carbon $from, Carbon $to): float
    {
        if ($basis === 'working_days') {
            return (float) $days->filter(fn (AttendanceDay $d, string $date) => $date >= $from->toDateString() && $date <= $to->toDateString() && $d->scheduledMinutes > 0)->count();
        }

        return (float) ($from->diffInDays($to) + 1);
    }

    private function pfVariables($items): array
    {
        return $items->filter(fn ($i) => $i->component?->pf_applicable && $i->component->type === 'earning')->map(fn ($i) => $i->component->variableName())->values()->all();
    }

    private function adjustments(PayrollComputation $c): void
    {
        $adjustments = PayrollAdjustment::query()->with('component')
            ->where('employee_id', $c->employee->id)->where('payroll_period_id', $c->period->id)
            ->where('type', '!=', 'lop')->approved()->orderBy('id')->get();

        foreach ($adjustments as $i => $adj) {
            $component = $adj->component;
            $type = match ($adj->type) {
                'deduction', 'recovery' => 'deduction', 'reimbursement' => 'reimbursement', default => 'earning'
            };
            $code = $component?->code ?? ('ADJ'.($i + 1));

            $c->addLine($code, $adj->name, $type, (float) $adj->amount, [
                'taxable' => $type === 'earning' && ($component ? $component->taxable : $adj->taxable),
                'classification' => $component?->classification ?? ($type === 'deduction' ? 'other_deduction' : ($adj->type === 'arrear' ? 'arrear' : 'other')),
                'salary_component_id' => $component?->id,
                'basis' => ['adjustment_id' => $adj->id, 'adjustment_type' => $adj->type, 'note' => $adj->note, 'source' => $adj->source_type ? ['type' => $adj->source_type, 'id' => $adj->source_id] : null],
                'sort_order' => 200 + $i,
                'pf_applicable' => (bool) $component?->pf_applicable,
                'esi_applicable' => $component ? $component->esi_applicable : $type === 'earning',
                'include_in_gross' => $component ? $component->include_in_gross : $type === 'earning',
            ]);
        }
    }
}
