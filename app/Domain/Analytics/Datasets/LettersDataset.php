<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Letters\Models\Letter;
use Illuminate\Database\Eloquent\Builder;

class LettersDataset extends Dataset
{
    public function key(): string
    {
        return 'letters';
    }

    public function label(): string
    {
        return 'HR letters (offer, appointment, confirmation, relieving and others)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['letter.view'];
    }

    public function fields(): array
    {
        return [
            'number' => ['label' => 'Letter number', 'type' => 'string', 'value' => fn ($l) => $l->number],
            ...$this->employeeFields(fn ($l) => $l->employee),
            'type' => ['label' => 'Type', 'type' => 'string', 'value' => fn ($l) => config('peopleos.letters.types.'.$l->type, $l->type)],
            'template' => ['label' => 'Template', 'type' => 'string', 'value' => fn ($l) => $l->template?->name],
            'subject' => ['label' => 'Subject', 'type' => 'string', 'value' => fn ($l) => $l->subject],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($l) => $l->status],
            'requested_on' => ['label' => 'Requested on', 'type' => 'date', 'value' => fn ($l) => $l->created_at],
            'requested_by' => ['label' => 'Requested by', 'type' => 'string', 'value' => fn ($l) => $l->requester?->name],
            'approved_by' => ['label' => 'Approved by', 'type' => 'string', 'value' => fn ($l) => $l->approver?->name],
            'issued_on' => ['label' => 'Issued on', 'type' => 'date', 'value' => fn ($l) => $l->issued_at],
            'month' => ['label' => 'Issue month', 'type' => 'string', 'value' => fn ($l) => $l->issued_at?->format('Y-m')],
            'count' => ['label' => 'Letters (1 per row)', 'type' => 'number', 'value' => fn ($l) => 1],
        ];
    }

    public function query(): Builder
    {
        return Letter::query()->with([...self::employeeWith('employee'), 'template', 'requester', 'approver'])->orderByDesc('id');
    }
}
