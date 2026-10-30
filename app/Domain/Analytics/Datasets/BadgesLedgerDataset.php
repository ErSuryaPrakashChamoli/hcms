<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Recognition\Models\Recognition;
use Illuminate\Database\Eloquent\Builder;

/** HROne setup parity HP-9c: the badges ledger (every badge given, private ones and withdrawals included), for HR. */
class BadgesLedgerDataset extends Dataset
{
    public function key(): string
    {
        return 'badges_ledger';
    }

    public function label(): string
    {
        return 'Badges ledger (recognition)';
    }

    public function category(): string
    {
        return 'People';
    }

    public function permissions(): array
    {
        return ['recognition.manage'];
    }

    public function fields(): array
    {
        return [
            'reference' => ['label' => 'Reference', 'type' => 'string', 'value' => fn (Recognition $r) => $r->reference()],
            'given_on' => ['label' => 'Given on', 'type' => 'date', 'value' => fn (Recognition $r) => $r->created_at],
            'month' => ['label' => 'Month', 'type' => 'string', 'value' => fn (Recognition $r) => $r->created_at?->format('Y-m')],
            'badge' => ['label' => 'Badge', 'type' => 'string', 'value' => fn (Recognition $r) => $r->badge?->name],
            'badge_type' => ['label' => 'Badge type', 'type' => 'string', 'value' => fn (Recognition $r) => $r->badge?->is_system ? 'Standard' : 'Custom'],
            'core_value' => ['label' => 'Core value', 'type' => 'string', 'value' => fn (Recognition $r) => $r->badge?->core_value],
            ...$this->employeeFields(fn (Recognition $r) => $r->employee),
            'given_by' => ['label' => 'Given by', 'type' => 'string', 'value' => fn (Recognition $r) => $r->giverEmployee?->person?->full_name ?? $r->giver?->name],
            'given_by_code' => ['label' => 'Giver employee code', 'type' => 'string', 'value' => fn (Recognition $r) => $r->giverEmployee?->employee_code],
            'reason' => ['label' => 'Reason', 'type' => 'string', 'value' => fn (Recognition $r) => $r->message],
            'visibility' => ['label' => 'Visibility', 'type' => 'string', 'value' => fn (Recognition $r) => $r->visibility],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (Recognition $r) => $r->isWithdrawn() ? 'withdrawn' : 'given'],
            'withdrawn_on' => ['label' => 'Withdrawn on', 'type' => 'date', 'value' => fn (Recognition $r) => $r->withdrawn_at],
            'withdraw_reason' => ['label' => 'Withdrawal reason', 'type' => 'string', 'value' => fn (Recognition $r) => $r->withdraw_reason],
            'count' => ['label' => 'Badges (1 per row)', 'type' => 'number', 'value' => fn (Recognition $r) => 1],
        ];
    }

    public function query(): Builder
    {
        return Recognition::query()->with([...self::employeeWith('employee'), 'badge', 'giver', 'giverEmployee.person'])->orderByDesc('id');
    }
}
