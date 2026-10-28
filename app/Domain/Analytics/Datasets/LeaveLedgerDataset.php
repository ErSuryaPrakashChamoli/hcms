<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Services\LeaveYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Every leave credit and debit as posted (credits positive, debits negative). */
class LeaveLedgerDataset extends Dataset
{
    /** the tenant's current leave year, resolved once per dataset instance */
    private ?int $current = null;

    public function key(): string
    {
        return 'leave_ledger';
    }

    public function label(): string
    {
        return 'Leave ledger (credits, availed, comp-off, lapses, encashments)';
    }

    public function category(): string
    {
        return 'Leave';
    }

    public function permissions(): array
    {
        return ['leave.view'];
    }

    public function fields(): array
    {
        return [
            'entry_date' => ['label' => 'Date', 'type' => 'date', 'value' => fn ($l) => $l->entry_date],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn ($l) => $l->entry_date?->format('Y-m')],
            'year' => ['label' => 'Leave year', 'type' => 'number', 'value' => fn ($l) => (int) $l->period_year],
            'is_current_year' => ['label' => 'Current leave year', 'type' => 'boolean', 'value' => fn ($l) => (int) $l->period_year === ($this->current ??= app(LeaveYear::class)->periodFor(now()))],
            ...$this->employeeFields(fn ($l) => $l->employee),
            'leave_type' => ['label' => 'Leave type', 'type' => 'string', 'value' => fn ($l) => $l->leaveType?->name],
            'type' => ['label' => 'Entry type', 'type' => 'string', 'value' => fn ($l) => $l->type],
            'type_label' => ['label' => 'Entry', 'type' => 'string', 'value' => fn ($l) => Str::of($l->type)->replace('_', ' ')->ucfirst()->toString()],
            'days' => ['label' => 'Days (+ credit / − debit)', 'type' => 'number', 'value' => fn ($l) => round((float) $l->days, 2)],
            'credit' => ['label' => 'Credit', 'type' => 'number', 'value' => fn ($l) => max(round((float) $l->days, 2), 0)],
            'debit' => ['label' => 'Debit', 'type' => 'number', 'value' => fn ($l) => max(round(-(float) $l->days, 2), 0)],
            'note' => ['label' => 'Note', 'type' => 'string', 'value' => fn ($l) => $l->note],
            'posted_by' => ['label' => 'Posted by', 'type' => 'string', 'value' => fn ($l) => $l->creator?->name ?? 'System'],
        ];
    }

    public function query(): Builder
    {
        return LeaveLedgerEntry::query()->with([...self::employeeWith('employee'), 'leaveType', 'creator'])->orderByDesc('entry_date')->orderByDesc('id');
    }
}
