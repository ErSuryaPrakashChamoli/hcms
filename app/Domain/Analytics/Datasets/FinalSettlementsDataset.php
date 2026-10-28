<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Exit\Models\FinalSettlement;
use Illuminate\Database\Eloquent\Builder;

class FinalSettlementsDataset extends Dataset
{
    public function key(): string
    {
        return 'final_settlements';
    }

    public function label(): string
    {
        return 'Full and final settlements';
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
            'case' => ['label' => 'Exit case', 'type' => 'string', 'value' => fn ($s) => $s->exitCase?->number],
            ...$this->employeeFields(fn ($s) => $s->employee),
            'exit_type' => ['label' => 'Exit type', 'type' => 'string', 'value' => fn ($s) => $s->exitCase?->type],
            'last_working_day' => ['label' => 'Last working day', 'type' => 'date', 'value' => fn ($s) => $s->exitCase?->last_working_day],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($s) => $s->status],
            'total_earnings' => ['label' => 'Earnings', 'type' => 'number', 'value' => fn ($s) => (float) $s->total_earnings],
            'total_deductions' => ['label' => 'Deductions / recoveries', 'type' => 'number', 'value' => fn ($s) => (float) $s->total_deductions],
            'net_amount' => ['label' => 'Net payable', 'type' => 'number', 'value' => fn ($s) => (float) $s->net_amount],
            'leave_encashment' => ['label' => 'Leave encashment', 'type' => 'number', 'value' => fn ($s) => round((float) $s->lines->filter(fn ($l) => str_contains(strtolower((string) $l->code), 'encash'))->sum('amount'), 2)],
            'gratuity' => ['label' => 'Gratuity', 'type' => 'number', 'value' => fn ($s) => round((float) $s->lines->filter(fn ($l) => str_contains(strtolower((string) $l->code), 'gratuity'))->sum('amount'), 2)],
            'calculated_on' => ['label' => 'Calculated on', 'type' => 'date', 'value' => fn ($s) => $s->calculated_at],
            'approved_on' => ['label' => 'Approved on', 'type' => 'date', 'value' => fn ($s) => $s->approved_at],
            'paid_on' => ['label' => 'Paid on', 'type' => 'date', 'value' => fn ($s) => $s->paid_at],
            'payment_reference' => ['label' => 'Payment reference', 'type' => 'string', 'value' => fn ($s) => $s->payment_reference],
            'days_since_last_day' => ['label' => 'Days since last working day', 'type' => 'number', 'value' => fn ($s) => $s->exitCase?->last_working_day ? -self::daysFromToday($s->exitCase->last_working_day) : null],
            'count' => ['label' => 'Settlements (1 per row)', 'type' => 'number', 'value' => fn ($s) => 1],
        ];
    }

    public function query(): Builder
    {
        return FinalSettlement::query()->with(['exitCase', ...self::employeeWith('employee'), 'lines'])->orderByDesc('id');
    }
}
