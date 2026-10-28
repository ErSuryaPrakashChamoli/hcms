<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;

/**
 * Joiners and leavers of the period with what each needs on the statutory portals: a UAN generated (or linked) and an
 * ESIC registration for joiners covered by PF or ESI, and the date of exit marked on the EPFO and ESIC portals for
 * leavers.
 */
final class JoinersAndLeavers extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $employees = $context->data->employees($context->company, $period->start, $period->end, $context->establishment);

        $joiners = $employees->filter(fn (Employee $e) => $period->contains($e->joining_date))->map(function (Employee $e) use ($context) {
            $detail = $e->statutoryDetail;
            $todo = array_filter([
                ($detail?->pf_applicable ?? true) && trim((string) $detail?->uan) === '' ? 'Generate / link UAN (EPFO)' : null,
                ($detail?->esic_applicable ?? true) && trim((string) $detail?->esic_number) === '' ? 'Register on ESIC if covered' : null,
                trim((string) $detail?->pan) === '' ? 'Collect PAN' : null,
            ]);

            return [
                'code' => $e->employee_code, 'name' => $this->name($e), 'gender' => $this->gender($e->person), 'dob' => $this->date($e->person?->date_of_birth),
                'relative' => $this->relativeName($e->person), 'joined' => $this->date($e->joining_date), 'designation' => $this->designation($context, $e),
                'uan' => $context->identifier($detail?->uan), 'esic' => $context->identifier($detail?->esic_number), 'action' => implode('; ', $todo) ?: 'None',
            ];
        })->values()->all();

        $leavers = $employees->filter(fn (Employee $e) => $e->exit_date !== null && $period->contains($e->exit_date));
        $exits = ExitCase::query()->whereIn('employee_id', $leavers->modelKeys())->orderByDesc('id')->get()->unique('employee_id')->keyBy('employee_id');
        $leaverRows = $leavers->map(fn (Employee $e) => [
            'code' => $e->employee_code, 'name' => $this->name($e), 'joined' => $this->date($e->joining_date), 'left' => $this->date($e->exit_date),
            'reason' => (string) str($exits->get($e->getKey())?->type ?? '')->replace('_', ' ')->ucfirst(),
            'uan' => $context->identifier($e->statutoryDetail?->uan), 'esic' => $context->identifier($e->statutoryDetail?->esic_number),
            'action' => 'Mark date of exit on EPFO and ESIC portals',
        ])->values()->all();

        return [
            $this->sheet($context, ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'gender' => 'Sex', 'dob' => 'Date of birth', 'relative' => 'Father\'s / spouse\'s name',
                'joined' => 'Date of joining', 'designation' => 'Designation', 'uan' => 'UAN', 'esic' => 'ESIC IP no.', 'action' => 'Action needed'], $this->numbered($joiners),
                ['title' => $context->definition->title.' — joiners', 'sheet' => 'Joiners', 'message' => 'Nobody joined in the period.', 'warnings' => $this->attributionGaps($employees)]),
            $this->sheet($context, ['sl' => 'Sl.', 'code' => 'Code', 'name' => 'Name', 'joined' => 'Date of joining', 'left' => 'Date of exit', 'reason' => 'Reason',
                'uan' => 'UAN', 'esic' => 'ESIC IP no.', 'action' => 'Action needed'], $this->numbered($leaverRows),
                ['title' => $context->definition->title.' — leavers', 'sheet' => 'Leavers', 'message' => 'Nobody left in the period.']),
        ];
    }
}
