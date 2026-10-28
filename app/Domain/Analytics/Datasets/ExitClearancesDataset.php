<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Exit\Models\ExitClearance;
use Illuminate\Database\Eloquent\Builder;

class ExitClearancesDataset extends Dataset
{
    public function key(): string
    {
        return 'exit_clearances';
    }

    public function label(): string
    {
        return 'Exit clearances (no-dues by department)';
    }

    public function category(): string
    {
        return 'Joining & exit';
    }

    public function permissions(): array
    {
        return ['exit.view'];
    }

    public function fields(): array
    {
        return [
            'case' => ['label' => 'Exit case', 'type' => 'string', 'value' => fn ($c) => $c->exitCase?->number],
            ...$this->employeeFields(fn ($c) => $c->exitCase?->employee),
            'last_working_day' => ['label' => 'Last working day', 'type' => 'date', 'value' => fn ($c) => $c->exitCase?->last_working_day],
            'stage' => ['label' => 'Stage', 'type' => 'string', 'value' => fn ($c) => $c->stage],
            'clearance' => ['label' => 'Clearance', 'type' => 'string', 'value' => fn ($c) => $c->name],
            'owner' => ['label' => 'Owner', 'type' => 'string', 'value' => fn ($c) => $c->owner?->name ?? $c->ownerRole?->name],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($c) => $c->status],
            'recoverable_amount' => ['label' => 'Recoverable amount', 'type' => 'number', 'value' => fn ($c) => (float) $c->recoverable_amount],
            'cleared_by' => ['label' => 'Cleared by', 'type' => 'string', 'value' => fn ($c) => $c->clearer?->name],
            'cleared_on' => ['label' => 'Cleared on', 'type' => 'date', 'value' => fn ($c) => $c->cleared_at],
            'count' => ['label' => 'Clearances (1 per row)', 'type' => 'number', 'value' => fn ($c) => 1],
        ];
    }

    public function query(): Builder
    {
        // Clearances are not access-scoped themselves; requiring a visible exit case applies the viewer's scope.
        return ExitClearance::query()->whereHas('exitCase')->with(['exitCase', ...self::employeeWith('exitCase.employee'), 'owner', 'ownerRole', 'clearer'])->orderByDesc('exit_case_id')->orderBy('sort_order');
    }
}
