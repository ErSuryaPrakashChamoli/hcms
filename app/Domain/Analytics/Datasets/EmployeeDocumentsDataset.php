<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Documents\Models\EmployeeDocument;
use Illuminate\Database\Eloquent\Builder;

class EmployeeDocumentsDataset extends Dataset
{
    public function key(): string
    {
        return 'employee_documents';
    }

    public function label(): string
    {
        return 'Employee documents (verification status and expiry)';
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
            ...$this->employeeFields(fn ($d) => $d->employee),
            'document_type' => ['label' => 'Document type', 'type' => 'string', 'value' => fn ($d) => $d->type?->name],
            'document_category' => ['label' => 'Document category', 'type' => 'string', 'value' => fn ($d) => $d->type?->category],
            'title' => ['label' => 'Title', 'type' => 'string', 'value' => fn ($d) => $d->title],
            'version' => ['label' => 'Version', 'type' => 'number', 'value' => fn ($d) => (int) $d->version],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($d) => $d->status],
            'uploaded_on' => ['label' => 'Uploaded on', 'type' => 'date', 'value' => fn ($d) => $d->created_at],
            'issued_on' => ['label' => 'Issued on', 'type' => 'date', 'value' => fn ($d) => $d->issued_on],
            'expires_on' => ['label' => 'Expires on', 'type' => 'date', 'value' => fn ($d) => $d->expires_on],
            'days_to_expiry' => ['label' => 'Days to expiry', 'type' => 'number', 'value' => fn ($d) => self::daysFromToday($d->expires_on)],
            'is_expired' => ['label' => 'Expired', 'type' => 'boolean', 'value' => fn ($d) => $d->expires_on !== null && $d->expires_on->lt(today())],
            'verified_by' => ['label' => 'Verified by', 'type' => 'string', 'value' => fn ($d) => $d->verifier?->name],
            'verified_on' => ['label' => 'Verified on', 'type' => 'date', 'value' => fn ($d) => $d->verified_at],
            'count' => ['label' => 'Documents (1 per row)', 'type' => 'number', 'value' => fn ($d) => 1],
        ];
    }

    public function query(): Builder
    {
        return EmployeeDocument::query()->with([...self::employeeWith('employee'), 'type', 'verifier'])->orderByDesc('id');
    }
}
