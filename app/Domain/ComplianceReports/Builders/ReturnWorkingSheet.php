<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;

/**
 * Working sheet for the unified annual return (Form XVII of the OSH (Central) Rules, 2026 = Form XXIII of the Social
 * Security (Central) Rules) or a half-yearly return: the Part I figures PeopleOS holds — manpower (largest and average
 * daily employment, fixed-term employees, by sex), working time, separations, maternity, bonus, wages and
 * contributions — and, with option part_iv, the Part IV month-wise EPFO / ESIC self-declaration. Hours and shifts,
 * contractors, welfare amenities, unions, strikes and accidents are not in PeopleOS and are left to complete.
 */
final class ReturnWorkingSheet extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment);
        $bySex = fn ($group) => $group->filter(fn ($e) => $this->gender($e->person) === 'M')->count().' / '.$group->filter(fn ($e) => $this->gender($e->person) === 'F')->count().' / '.$group->filter(fn ($e) => ! in_array($this->gender($e->person), ['M', 'F'], true))->count();
        $on = fn (string $date) => $employees->filter(fn (Employee $e) => $e->joining_date->toDateString() <= $date && ($e->exit_date === null || $e->exit_date->toDateString() >= $date));

        $attendance = $context->data->attendance($employees->modelKeys(), $period->start, $period->end);
        $daily = [];
        $manDays = 0.0;
        $overtime = 0;
        foreach ($attendance as $days) {
            foreach ($days as $date => $record) {
                $worked = match ($record->status) {
                    'present', 'wfh', 'on_duty', 'field_duty' => 1.0, 'half_day' => 0.5, default => 0.0
                };
                $manDays += $worked;
                $daily[$date] = ($daily[$date] ?? 0) + ($worked > 0 ? 1 : 0);
                $overtime += (int) $record->overtime_approved_minutes;
            }
        }
        $workingDays = array_filter($daily);

        $leave = $context->data->leave($employees->modelKeys(), $period->start, $period->end);
        $leaveDays = 0.0;
        foreach ($leave as $request) {
            foreach ($request->dates ?? [] as $d) {
                $leaveDays += $period->contains($d['date']) ? (float) $d['days'] : 0;
            }
        }
        $maternity = $leave->filter(fn ($r) => in_array($r->leaveType?->code, $context->definition->options['maternity_codes'] ?? ['ML'], true));
        $fixedTerm = $employees->filter(fn (Employee $e) => preg_match('/fixed/i', (string) $context->data->positionOn($e, $period->end)?->employmentType?->name));
        $exits = ExitCase::query()->whereIn('employee_id', $employees->filter(fn ($e) => $e->exit_date !== null && $period->contains($e->exit_date))->modelKeys())->get()->countBy('type');

        $pay = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'bonus' => 0.0, 'bonus_people' => [], 'bonus_dates' => [], 'pf' => 0.0, 'esi' => 0.0];
        $partIv = [];
        foreach ($period->months() as $month) {
            $entries = $context->data->payrollFor($context, $month);
            $eligible = $entries->filter(fn ($e) => $e->lines->contains('code', 'PF_EE'));
            $esi = $entries->filter(fn ($e) => $e->lines->contains('code', 'ESI_EE'));
            foreach ($entries as $entry) {
                $pay['gross'] += (float) $entry->total_earnings;
                $pay['deductions'] += (float) $entry->total_deductions;
                $pay['net'] += (float) $entry->net_pay;
                $pay['pf'] += $this->amount($entry, 'PF_EE') + $this->amount($entry, 'PF_ER') + $this->amount($entry, 'PF_ADMIN');
                $pay['esi'] += $this->amount($entry, 'ESI_EE') + $this->amount($entry, 'ESI_ER');
                if (($bonus = $this->byClass($entry, ['bonus'], 'earning')) > 0) {
                    $pay['bonus'] += $bonus;
                    $pay['bonus_people'][(int) $entry->employee_id] = true;
                    $pay['bonus_dates'][] = $this->date($entry->run?->paid_at ?? $entry->run?->period?->payment_date);
                }
            }
            $partIv[] = [
                'month' => $month->start->format('M Y'),
                'eligible' => $eligible->count(),
                'not_eligible' => $entries->count() - $eligible->count(),
                'total' => $entries->count(),
                'wages_eligible' => round((float) $eligible->sum('total_earnings'), 2),
                'epf_wages' => round((float) $eligible->sum(fn ($e) => (float) ($e->lines->firstWhere('code', 'PF_EE')?->basis['base'] ?? 0)), 2),
                'wages_not_eligible' => round((float) $entries->sum('total_earnings') - (float) $eligible->sum('total_earnings'), 2),
                'wages_total' => round((float) $entries->sum('total_earnings'), 2),
                'epf_payable' => round((float) $eligible->sum(fn ($e) => $this->amount($e, 'PF_EE') + $this->amount($e, 'PF_ER') + $this->amount($e, 'PF_ADMIN')), 2),
                'esi_employees' => $esi->count(),
                'esi_payable' => round((float) $esi->sum(fn ($e) => $this->amount($e, 'ESI_EE') + $this->amount($e, 'ESI_ER')), 2),
                'paid' => '', 'paid_on' => '', 'balance' => '',
            ];
        }

        $type = fn (array $types) => (int) collect($types)->sum(fn ($t) => $exits[$t] ?? 0);
        $item = fn (string $section, string $label, mixed $value) => ['section' => $section, 'item' => $label, 'value' => is_float($value) ? round($value, 2) : $value];
        $rows = [
            $item('A. General', 'Period', $period->start->format('d-m-Y').' to '.$period->end->format('d-m-Y')),
            $item('A. General', 'Establishment / district / state / PIN', trim(implode(', ', array_filter([$context->establishment?->name, $context->establishment?->district, $context->stateName(), $context->establishment?->postal_code])))),
            $item('C. Manpower (directly employed)', 'Maximum employed on any day (present)', $daily === [] ? 0 : max($daily)),
            $item('C. Manpower (directly employed)', 'Average daily employment (days worked)', $workingDays === [] ? 0.0 : array_sum($workingDays) / count($workingDays)),
            $item('C. Manpower (directly employed)', 'Employed at the start (men / women / transgender or not recorded)', $bySex($on($period->start->toDateString()))),
            $item('C. Manpower (directly employed)', 'Employed at the end (men / women / transgender or not recorded)', $bySex($on($period->end->toDateString()))),
            $item('C. Manpower (directly employed)', 'Fixed-term employees', $fixedTerm->count()),
            $item('C. Manpower (directly employed)', 'Joined / left during the period', $employees->filter(fn ($e) => $period->contains($e->joining_date))->count().' / '.$employees->filter(fn ($e) => $e->exit_date !== null && $period->contains($e->exit_date))->count()),
            $item('Working time', 'Days worked by the establishment', count($workingDays)),
            $item('Working time', 'Man-days worked', $manDays),
            $item('Working time', 'Overtime hours (approved)', round($overtime / 60, 2)),
            $item('Working time', 'Leave days availed (all types)', $leaveDays),
            $item('F. Industrial relations', 'Dismissed / retrenched / discharged or terminated', '— / — / '.$type(['termination'])),
            $item('F. Industrial relations', 'Other separations (resignation, retirement, contract expiry, death, other)', $type(['resignation', 'retirement', 'contract_expiry', 'death', 'mutual', 'absconding', 'other'])),
            $item('G. Maternity benefit', 'Female employees', $employees->filter(fn ($e) => $this->gender($e->person) === 'F')->count()),
            $item('G. Maternity benefit', 'Availed maternity leave (cases / days)', $maternity->pluck('employee_id')->unique()->count().' / '.(float) $maternity->sum('days')),
            $item('G. Maternity benefit', 'Paid medical bonus; wage deductions from female employees', ''),
            $item('H. Bonus', 'Employees paid bonus / total bonus paid', count($pay['bonus_people']).' / '.number_format($pay['bonus'], 2)),
            $item('H. Bonus', 'Date(s) bonus paid', implode(', ', array_unique($pay['bonus_dates']))),
            $item('Wages', 'Gross wages / deductions / net paid', number_format($pay['gross'], 2).' / '.number_format($pay['deductions'], 2).' / '.number_format($pay['net'], 2)),
            $item('Wages', 'PF (employee + employer + charges) / ESI (employee + employer)', number_format($pay['pf'], 2).' / '.number_format($pay['esi'], 2)),
            $item('To complete', 'B. Hours of work and shifts; D. contractors; E. welfare amenities; F. unions, strikes, lay-offs; I–J. accidents, diseases and losses', ''),
        ];

        $sheets = [$this->sheet($context, ['section' => 'Section', 'item' => 'Particular', 'value' => 'Figure'], $rows, [
            'sheet' => 'Part I',
            'warnings' => [...$this->payrollGaps($context), ...($attendance === [] && $employees->isNotEmpty() ? ['No processed attendance for the period: employment, man-days and overtime figures are missing.'] : []), ...$this->attributionGaps($employees)],
            'notes' => ['Copy these figures into the return on the portal (the unified annual return is filed online by 28/29 February for the previous calendar year) or into the state form.'],
        ])];

        if ($context->definition->options['part_iv'] ?? false) {
            $sheets[] = $this->sheet($context, ['month' => 'Month & year', 'eligible' => 'Employees eligible (PF)', 'not_eligible' => 'Not eligible', 'total' => 'Total employed',
                'wages_eligible' => 'Wages paid to eligible', 'epf_wages' => 'EPF wages', 'wages_not_eligible' => 'Wages paid to non-eligible', 'wages_total' => 'Total wages',
                'epf_payable' => 'EPF contribution payable', 'esi_employees' => 'ESI-covered employees', 'esi_payable' => 'ESI contribution payable',
                'paid' => 'Contribution paid', 'paid_on' => 'Date of payment', 'balance' => 'Balance'], $partIv, [
                    'title' => 'Part IV — EPFO / ESIC self-declaration working', 'sheet' => 'Part IV',
                    'money' => ['wages_eligible', 'epf_wages', 'wages_not_eligible', 'wages_total', 'epf_payable', 'esi_payable'],
                    'notes' => ['The portal lays Part IV out April to March; enter the amounts and dates actually paid from the challans.'],
                ]);
        }

        return $sheets;
    }
}
