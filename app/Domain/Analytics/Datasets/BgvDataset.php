<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Bgv\Models\BgvCase;
use Illuminate\Database\Eloquent\Builder;

class BgvDataset extends Dataset
{
    public function key(): string
    {
        return 'bgv';
    }

    public function label(): string
    {
        return 'Background verification cases';
    }

    public function category(): string
    {
        return 'Joining & exit';
    }

    public function permissions(): array
    {
        return ['bgv.view'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($c) => $c->employee),
            'provider' => ['label' => 'Provider', 'type' => 'string', 'value' => fn ($c) => $c->provider],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($c) => $c->status],
            'result' => ['label' => 'Result', 'type' => 'string', 'value' => fn ($c) => $c->overall_result],
            'consent_given' => ['label' => 'Consent given', 'type' => 'boolean', 'value' => fn ($c) => $c->consent_given_at !== null],
            'initiated_on' => ['label' => 'Initiated on', 'type' => 'date', 'value' => fn ($c) => $c->initiated_at],
            'completed_on' => ['label' => 'Completed on', 'type' => 'date', 'value' => fn ($c) => $c->completed_at],
            'tat_days' => ['label' => 'Turnaround (days)', 'type' => 'number', 'value' => fn ($c) => $c->initiated_at ? (int) $c->initiated_at->startOfDay()->diffInDays(($c->completed_at ?? now())->startOfDay()) : null],
            'checks' => ['label' => 'Checks', 'type' => 'string', 'value' => fn ($c) => $c->checks->pluck('type')->implode(', ')],
            'checks_open' => ['label' => 'Checks open', 'type' => 'number', 'value' => fn ($c) => $c->checks->whereIn('status', ['pending', 'in_progress'])->count()],
            'checks_flagged' => ['label' => 'Checks with discrepancy / failed', 'type' => 'number', 'value' => fn ($c) => $c->checks->whereIn('status', ['discrepancy', 'failed'])->count()],
            'count' => ['label' => 'Cases (1 per row)', 'type' => 'number', 'value' => fn ($c) => 1],
        ];
    }

    public function query(): Builder
    {
        return BgvCase::query()->with([...self::employeeWith('employee'), 'checks'])->orderByDesc('id');
    }
}
