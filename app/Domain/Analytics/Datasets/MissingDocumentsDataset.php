<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity CA-4: one row per employee and mandatory document type (for every employee, or for onboarding)
 * the employee has no current document of (pending verification or verified). Rejected and superseded uploads do not
 * count. The viewer's organisation scope applies through the employee.
 */
class MissingDocumentsDataset extends Dataset
{
    public function key(): string
    {
        return 'missing_documents';
    }

    public function label(): string
    {
        return 'Missing documents (mandatory document types not on file)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['document.view'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($e) => $e),
            'joining_date' => ['label' => 'Joining date', 'type' => 'date', 'value' => fn ($e) => $e->joining_date],
            'expected_joining_date' => ['label' => 'Expected joining date', 'type' => 'date', 'value' => fn ($e) => $e->expected_joining_date],
            'document_type' => ['label' => 'Missing document', 'type' => 'string', 'value' => fn ($e) => $e->getAttribute('missing_type_name')],
            'document_category' => ['label' => 'Document category', 'type' => 'string', 'value' => fn ($e) => app(OptionLists::class)->label('document_categories', $e->getAttribute('missing_type_category'))],
            'required_of_everyone' => ['label' => 'Mandatory for every employee', 'type' => 'boolean', 'value' => fn ($e) => (bool) $e->getAttribute('missing_for_employee')],
            'required_for_onboarding' => ['label' => 'Mandatory for onboarding', 'type' => 'boolean', 'value' => fn ($e) => (bool) $e->getAttribute('missing_for_onboarding')],
            'count' => ['label' => 'Missing documents (1 per row)', 'type' => 'number', 'value' => fn ($e) => 1],
        ];
    }

    public function query(): Builder
    {
        return Employee::query()
            ->select(['employees.*', 'document_types.name as missing_type_name', 'document_types.category as missing_type_category',
                'document_types.mandatory_for_employee as missing_for_employee', 'document_types.mandatory_for_onboarding as missing_for_onboarding'])
            ->join('document_types', fn ($join) => $join->on('document_types.tenant_id', '=', 'employees.tenant_id')->where('document_types.status', 'active')
                ->where(fn ($w) => $w->where('document_types.mandatory_for_employee', true)->orWhere('document_types.mandatory_for_onboarding', true)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_documents')->whereColumn('employee_documents.employee_id', 'employees.id')
                ->whereColumn('employee_documents.document_type_id', 'document_types.id')->whereIn('employee_documents.status', ['pending', 'verified']))
            ->with(['person', 'currentPosition.company', 'currentPosition.location', 'currentPosition.department', 'currentPosition.designation'])
            ->orderBy('employees.employee_code')->orderBy('document_types.name');
    }
}
