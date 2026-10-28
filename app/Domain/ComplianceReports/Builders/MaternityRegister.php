<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Leave\Models\LeaveRequest;

/**
 * Register of women employees in the layout of Form XXII of the Social Security (Central) Rules, 2026: every woman
 * employed at the establishment in the period, with her ESIC and PF numbers, nature of work and, for maternity leave
 * (leave types in leave_codes, ML by default), the notice and leave dates and the proof on file. ESIC-covered women
 * draw maternity benefit from ESIC; the others from the employer. Dates of delivery, amounts of benefit and medical
 * bonus are completed from the claim (Forms X and XI).
 */
final class MaternityRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $codes = $context->definition->options['leave_codes'] ?? ['ML'];
        $women = $context->data->employees($context->company, $period->start, $period->end, $context->establishment)
            ->filter(fn (Employee $e) => $this->gender($e->person) === 'F')->keyBy('id');
        $leave = $context->data->leave($women->keys()->all(), $period->start, $period->end)
            ->filter(fn (LeaveRequest $r) => in_array($r->leaveType?->code, $codes, true))->groupBy('employee_id');
        $esiCovered = [];
        foreach ($period->months() as $month) {
            foreach ($context->data->payrollFor($context, $month) as $entry) {
                if ($entry->lines->contains('code', 'ESI_EE')) {
                    $esiCovered[(int) $entry->employee_id] = true;
                }
            }
        }

        $rows = $women->map(function (Employee $employee) use ($context, $leave, $esiCovered) {
            $requests = $leave->get($employee->getKey(), collect());
            $detail = $employee->statutoryDetail;

            return [
                'code' => $employee->employee_code,
                'name' => $this->name($employee).(($relative = $this->relativeName($employee->person)) !== '' ? ' / '.$relative : ''),
                'appointed' => $this->date($employee->joining_date),
                'insurance' => $context->identifier($detail?->esic_number),
                'pf' => $context->identifier($detail?->uan),
                'work' => $this->designation($context, $employee),
                'notice' => $requests->map(fn ($r) => $this->date($r->created_at))->implode(', '),
                'leave' => $requests->map(fn ($r) => $this->date($r->from_date).' to '.$this->date($r->to_date).' ('.(float) $r->days.' d)')->implode('; '),
                'exit' => $this->date($employee->exit_date),
                'proof' => $requests->isEmpty() ? '' : ($requests->contains(fn ($r) => $r->document_id !== null) ? 'On file' : 'Not attached'),
                'paid_by' => $requests->isEmpty() ? '' : (isset($esiCovered[(int) $employee->getKey()]) ? 'ESIC (insured woman)' : 'Employer'),
                'birth' => '', 'benefit' => '', 'bonus' => '',
                'nominee' => $employee->person?->familyMembers?->where('is_nominee', true)->pluck('name')->implode(', '),
                'remarks' => '',
            ];
        })->values()->all();

        $columns = ['sl' => 'Sl. no.', 'code' => 'Employee code', 'name' => 'Name of woman / father\'s or husband\'s name', 'appointed' => 'Date of appointment',
            'insurance' => 'Insurance no. (ESIC)', 'pf' => 'PF (UAN)', 'work' => 'Nature of work', 'notice' => 'Date of notice (s.62)', 'leave' => 'Maternity leave',
            'exit' => 'Date of discharge / exit', 'proof' => 'Proof of pregnancy / delivery', 'paid_by' => 'Benefit paid by', 'birth' => 'Date of birth of child',
            'benefit' => 'Date and amount of maternity benefit paid', 'bonus' => 'Date and amount of medical bonus', 'nominee' => 'Nominee', 'remarks' => 'Remarks'];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'message' => 'No women employed at this establishment in the period.',
            'warnings' => $this->attributionGaps($women),
            'notes' => ['Women with no maternity leave in the period are listed too: the register covers every woman employee. Display the abstract of maternity provisions (Form XIV) at the workplace.'],
        ])];
    }
}
