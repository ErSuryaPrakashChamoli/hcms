<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Leave\Models\LeaveBalance;
use App\Domain\Leave\Services\LeaveYear;
use Illuminate\Database\Eloquent\Builder;

/** Leave balances as last recomputed from the ledger: one row per employee, leave type and leave year. */
class LeaveBalancesDataset extends Dataset
{
    /** the tenant's current leave year, resolved once per dataset instance */
    private ?int $current = null;

    public function key(): string
    {
        return 'leave_balances';
    }

    public function label(): string
    {
        return 'Leave balances (opening, accrued, availed, lapsed, closing per leave type)';
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
        $n = fn (string $column) => fn ($b) => round((float) $b->{$column}, 2);

        return [
            'year' => ['label' => 'Leave year', 'type' => 'number', 'value' => fn ($b) => (int) $b->period_year],
            'is_current_year' => ['label' => 'Current leave year', 'type' => 'boolean', 'value' => fn ($b) => (int) $b->period_year === ($this->current ??= app(LeaveYear::class)->periodFor(now()))],
            ...$this->employeeFields(fn ($b) => $b->employee),
            'leave_type' => ['label' => 'Leave type', 'type' => 'string', 'value' => fn ($b) => $b->leaveType?->name],
            'leave_code' => ['label' => 'Leave code', 'type' => 'string', 'value' => fn ($b) => $b->leaveType?->code],
            'is_paid' => ['label' => 'Paid leave', 'type' => 'boolean', 'value' => fn ($b) => (bool) $b->leaveType?->is_paid],
            'opening' => ['label' => 'Opening (incl. carried forward)', 'type' => 'number', 'value' => $n('opening')],
            'accrued' => ['label' => 'Credited / accrued', 'type' => 'number', 'value' => $n('accrued')],
            'adjusted' => ['label' => 'Adjusted', 'type' => 'number', 'value' => $n('adjusted')],
            'used' => ['label' => 'Availed', 'type' => 'number', 'value' => $n('used')],
            'encashed' => ['label' => 'Encashed', 'type' => 'number', 'value' => $n('encashed')],
            'lapsed' => ['label' => 'Lapsed / expired', 'type' => 'number', 'value' => $n('lapsed')],
            'closing' => ['label' => 'Closing balance', 'type' => 'number', 'value' => $n('closing')],
            'pending' => ['label' => 'Pending approval', 'type' => 'number', 'value' => $n('pending')],
            'available' => ['label' => 'Available to apply', 'type' => 'number', 'value' => fn ($b) => round((float) $b->closing - (float) $b->pending, 2)],
            'computed_at' => ['label' => 'Balance as of', 'type' => 'date', 'value' => fn ($b) => $b->computed_at],
        ];
    }

    public function query(): Builder
    {
        return LeaveBalance::query()->with([...self::employeeWith('employee'), 'leaveType'])->orderByDesc('period_year')->orderBy('employee_id')->orderBy('leave_type_id');
    }
}
