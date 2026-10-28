<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\People\Models\PersonAddress;

/**
 * Employee register in the order of Form I of the Wages (Central) Rules, 2026 (the same particulars as OSH Form XIII
 * and the earlier consolidated Form A): everyone employed at the establishment at any time in the period. Pay is the
 * full-month rate of the latest finalized payroll in the period. Particulars PeopleOS does not hold (place of birth,
 * promotion history, service book number, identification mark, photograph, specimen signature) are left blank for the
 * establishment to complete.
 */
final class EmployeeRegister extends Builder
{
    public function build(ReportContext $context): array
    {
        $employees = $context->data->employees($context->company, $context->period->start, $context->period->end, $context->establishment);
        $exits = ExitCase::query()->whereIn('employee_id', $employees->modelKeys())->orderByDesc('id')->get()->unique('employee_id')->keyBy('employee_id');
        $pay = [];
        foreach ($context->period->months() as $month) {
            foreach ($context->data->payrollFor($context, $month) as $entry) {
                $pay[(int) $entry->employee_id] = $this->rate($entry);
            }
        }

        $rows = $employees->map(function (Employee $employee) use ($context, $exits, $pay) {
            $person = $employee->person;
            $position = $context->data->positionOn($employee, $context->period->end);
            $detail = $employee->statutoryDetail;
            $account = $employee->bankAccounts->firstWhere('is_primary', true) ?? $employee->bankAccounts->first();
            $present = $this->address($person?->addresses?->firstWhere('type', 'current') ?? $person?->addresses?->first());
            $family = $person?->familyMembers ?? collect();

            return [
                'code' => $employee->employee_code,
                'name' => trim(($person?->first_name ?? '').' '.($person?->middle_name ?? '')) ?: $this->name($employee),
                'surname' => (string) $person?->last_name,
                'gender' => $this->gender($person),
                'relative' => $this->relativeName($person),
                'dob' => $this->date($person?->date_of_birth),
                'birthplace' => '',
                'nationality' => (string) $person?->nationality,
                'education' => (string) $person?->qualifications?->sortByDesc('year_of_completion')->first()?->qualification,
                'joined' => $this->date($employee->joining_date),
                'designation' => (string) $position?->designation?->name,
                'category' => (string) $position?->employeeCategory?->name,
                'employment_type' => (string) $position?->employmentType?->name,
                'posting' => trim(implode(', ', array_filter([$position?->department?->name, $position?->location?->name]))),
                'pay' => $pay[(int) $employee->getKey()] ?? null,
                'promotion' => '',
                'mobile' => (string) ($person?->personal_phone ?: $employee->work_phone),
                'uan' => $context->identifier($detail?->uan),
                'pan' => $context->identifier($detail?->pan),
                'nominee' => $family->where('is_nominee', true)->pluck('name')->implode(', '),
                'family' => $family->map(fn ($m) => $m->name.' ('.$m->relation.')')->implode(', '),
                'eps' => ($detail?->pf_applicable ?? false) ? 'EPS' : '',
                'esic' => $context->identifier($detail?->esic_number),
                'aadhaar' => $context->identifier($detail?->aadhaar_reference),
                'account' => $account === null ? '' : ($context->unmasked ? (string) $account->account_number : '••••'.$account->account_number_last4),
                'bank' => (string) $account?->bank_name,
                'branch' => trim(implode(' ', array_filter([$account?->branch_name, $account?->ifsc ? "({$account->ifsc})" : null]))),
                'present_address' => $present,
                'permanent_address' => $this->address($person?->addresses?->firstWhere('type', 'permanent')) ?: $present,
                'service_book' => '',
                'exit' => $this->date($employee->exit_date),
                'exit_reason' => $employee->exit_date ? (string) str($exits->get($employee->getKey())?->type ?? '')->replace('_', ' ')->ucfirst() : '',
                'identification' => '',
                'photo' => '',
                'signature' => '',
                'remarks' => '',
            ];
        })->values()->all();

        $columns = [
            'sl' => 'Sl. no.', 'code' => 'Employee code', 'name' => 'Name', 'surname' => 'Surname', 'gender' => 'Gender', 'relative' => 'Father\'s / mother\'s / spouse\'s name',
            'dob' => 'Date of birth', 'birthplace' => 'Place of birth', 'nationality' => 'Nationality', 'education' => 'Education level', 'joined' => 'Date of joining',
            'designation' => 'Designation', 'category' => 'Category (HS / S / SS / US)', 'employment_type' => 'Type of employment', 'posting' => 'Details of posting',
            'pay' => 'Pay', 'promotion' => 'Promotion', 'mobile' => 'Mobile number', 'uan' => 'UAN', 'pan' => 'PAN', 'nominee' => 'Nominee', 'family' => 'Details of family',
            'eps' => 'EPS / NPS', 'esic' => 'ESIC IP no.', 'aadhaar' => 'Aadhaar no.', 'account' => 'Bank A/c number', 'bank' => 'Bank', 'branch' => 'Branch (IFSC)',
            'present_address' => 'Present address', 'permanent_address' => 'Permanent address', 'service_book' => 'Service book no.', 'exit' => 'Date of exit',
            'exit_reason' => 'Reason for exit', 'identification' => 'Mark of identification', 'photo' => 'Photo', 'signature' => 'Specimen signature / thumb impression', 'remarks' => 'Remarks',
        ];

        return [$this->sheet($context, $columns, $this->numbered($rows), [
            'money' => ['pay'],
            'message' => 'No employees at this establishment in the period.',
            'warnings' => $this->attributionGaps($employees),
            'notes' => ['Photograph, specimen signature or thumb impression and identification mark are kept on the physical register or the employee file. Registers are preserved for five years after the last entry.'],
        ])];
    }

    private function address(?PersonAddress $address): string
    {
        return $address === null ? '' : trim(implode(', ', array_filter([$address->address_line_1, $address->address_line_2, $address->city, $address->state_code, $address->postal_code])));
    }
}
