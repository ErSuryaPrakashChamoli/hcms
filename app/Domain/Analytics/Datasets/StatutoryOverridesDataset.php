<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Compliance\Models\StatutoryOverride;
use Illuminate\Database\Eloquent\Builder;

/** HROne parity HP-3: the statutory override audit — every PF / ESI / PT / LWF / TDS amount set by hand. */
class StatutoryOverridesDataset extends Dataset
{
    public function key(): string
    {
        return 'statutory_overrides';
    }

    public function label(): string
    {
        return 'Statutory overrides';
    }

    public function category(): string
    {
        return 'Payroll & compensation';
    }

    public function permissions(): array
    {
        return ['payroll.view'];
    }

    public function fields(): array
    {
        return [
            'month' => ['label' => 'Payroll month', 'type' => 'string', 'value' => fn ($r) => $r->period?->key()],
            ...$this->employeeFields(fn ($r) => $r->employee),
            'line' => ['label' => 'Line', 'type' => 'string', 'value' => fn ($r) => $r->codeLabel()],
            'amount' => ['label' => 'Amount', 'type' => 'number', 'value' => fn ($r) => (float) $r->amount],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn ($r) => $r->reason],
            'set_by' => ['label' => 'Set by', 'type' => 'string', 'value' => fn ($r) => $r->creator?->name],
            'set_on' => ['label' => 'Set on', 'type' => 'date', 'value' => fn ($r) => $r->created_at],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($r) => StatutoryOverride::STATUSES[$r->status] ?? $r->status],
            'cancel_reason' => ['label' => 'Cancelled because', 'type' => 'string', 'value' => fn ($r) => $r->cancel_reason],
            'count' => ['label' => 'Overrides (1 per row)', 'type' => 'number', 'value' => fn ($r) => 1],
        ];
    }

    public function query(): Builder
    {
        return StatutoryOverride::query()->with([...self::employeeWith('employee'), 'period', 'creator'])->orderByDesc('id');
    }
}
