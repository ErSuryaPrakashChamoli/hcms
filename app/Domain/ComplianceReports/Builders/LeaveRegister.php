<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Services\LeaveYear;
use Illuminate\Support\Carbon;

/**
 * Register for leave with wages (Form XX of the OSH (Central) Rules, 2026; earlier Form E): per employee and leave
 * type, the date of entry into service, the leave year's opening balance and credit, leave availed in the period with
 * its dates, encashment (payment in lieu of leave, including on discharge), lapse and the balance carried forward.
 * Balances come from the leave ledger (leave_balances); the days availed from approved leave requests.
 */
final class LeaveRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment)->keyBy('id');
        $year = app(LeaveYear::class)->periodFor(Carbon::parse($period->end));
        $balances = LeaveBalance::query()->with('leaveType')->whereIn('employee_id', $employees->keys())->where('period_year', $year)->get()->groupBy('employee_id');
        $leave = $context->data->leave($employees->keys()->all(), $period->start, $period->end)->groupBy('employee_id');

        $rows = [];
        foreach ($employees as $employee) {
            /** @var Employee $employee */
            $taken = $leave->get($employee->getKey(), collect());
            $types = $balances->get($employee->getKey(), collect())->keyBy('leave_type_id');
            foreach ($taken as $request) {
                $types->put($request->leave_type_id, $types->get($request->leave_type_id) ?? $request->leaveType);
            }

            foreach ($types as $typeId => $item) {
                $balance = $item instanceof LeaveBalance ? $item : null;
                $type = $balance?->leaveType ?? $item;
                $requests = $taken->where('leave_type_id', $typeId);
                [$days, $dates] = $this->availed($requests, $period->start->toDateString(), $period->end->toDateString());

                $rows[] = [
                    'code' => $employee->employee_code,
                    'name' => $this->name($employee),
                    'joined' => $this->date($employee->joining_date),
                    'exit' => $this->date($employee->exit_date),
                    'leave_type' => trim(($type?->code ? $type->code.' — ' : '').$type?->name),
                    'paid' => (bool) $type?->is_paid,
                    'opening' => (float) ($balance?->opening ?? 0),
                    'credited' => round((float) ($balance?->accrued ?? 0) + (float) ($balance?->adjusted ?? 0), 2),
                    'availed' => $days,
                    'dates' => $dates,
                    'availed_year' => (float) ($balance?->used ?? 0),
                    'encashed' => (float) ($balance?->encashed ?? 0),
                    'lapsed' => (float) ($balance?->lapsed ?? 0),
                    'closing' => (float) ($balance?->closing ?? 0),
                ];
            }
        }

        $columns = ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'joined' => 'Date of entry into service', 'exit' => 'Date of discharge', 'leave_type' => 'Leave', 'paid' => 'With wages',
            'opening' => 'Balance at start of leave year', 'credited' => 'Credited / adjusted', 'availed' => 'Availed in period', 'dates' => 'Dates availed',
            'availed_year' => 'Availed in leave year', 'encashed' => 'Encashed (paid in lieu)', 'lapsed' => 'Lapsed', 'closing' => 'Balance carried forward'];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'message' => 'No leave balances or leave taken in the period.',
            'warnings' => $this->attributionGaps($employees),
            'notes' => ["Balances are for leave year {$year} (".app(LeaveYear::class)->start($year)->format('d-m-Y').' to '.app(LeaveYear::class)->end($year)->format('d-m-Y').'). Leave wages are paid through payroll at the rate of wages.'],
        ])];
    }

    /** @return array{0: float, 1: string} days in range and their dates */
    private function availed($requests, string $from, string $to): array
    {
        $days = 0.0;
        $dates = [];
        foreach ($requests as $request) {
            /** @var LeaveRequest $request */
            foreach ($request->dates ?? [] as $d) {
                if ($d['date'] >= $from && $d['date'] <= $to) {
                    $days += (float) $d['days'];
                    $dates[] = Carbon::parse($d['date'])->format('d-m').((float) $d['days'] < 1 ? ' (½)' : '');
                }
            }
        }

        return [round($days, 2), implode(', ', $dates)];
    }
}
