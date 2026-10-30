<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Performance\Models\ImprovementPlan;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Performance improvement plans: dates, status and the PIP reasons chosen from the masters (HROne setup parity HP-8a);
 * the reason text, objectives and outcomes stay on the plan.
 */
class ImprovementPlansDataset extends Dataset
{
    public function key(): string
    {
        return 'improvement_plans';
    }

    public function label(): string
    {
        return 'Performance improvement plans (PIP)';
    }

    public function category(): string
    {
        return 'Performance & learning';
    }

    public function permissions(): array
    {
        return ['performance.pip'];
    }

    public function fields(): array
    {
        return [
            ...$this->employeeFields(fn ($p) => $p->employee),
            'manager' => ['label' => 'Manager', 'type' => 'string', 'value' => fn ($p) => $p->manager?->person?->full_name],
            'start_date' => ['label' => 'Start', 'type' => 'date', 'value' => fn ($p) => $p->start_date],
            'end_date' => ['label' => 'End', 'type' => 'date', 'value' => fn ($p) => $p->end_date],
            'days_left' => ['label' => 'Days left', 'type' => 'number', 'value' => fn ($p) => in_array($p->status, ImprovementPlan::FINAL, true) ? null : self::daysFromToday($p->end_date)],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($p) => $p->status],
            'reasons' => ['label' => 'PIP reasons', 'type' => 'string', 'value' => fn ($p) => $p->reason_codes ? collect($p->reason_codes)->map(fn ($code) => $this->reasonNames()[$code] ?? $code)->implode(', ') : null],
            'closed_on' => ['label' => 'Closed on', 'type' => 'date', 'value' => fn ($p) => $p->closed_at],
            'count' => ['label' => 'Plans (1 per row)', 'type' => 'number', 'value' => fn ($p) => 1],
        ];
    }

    /** @var array<int, array<string, string>> per tenant: PIP reason code => name */
    private array $reasonNames = [];

    /** @return array<string, string> PIP reason code => name (inactive entries too, for older plans) */
    private function reasonNames(): array
    {
        return $this->reasonNames[(int) app(TenantContext::class)->id()] ??= PerformanceLookup::query()->ofKind('pip_reason')->pluck('name', 'code')->all();
    }

    public function query(): Builder
    {
        $this->reasonNames = []; // read afresh for each run

        return ImprovementPlan::query()->with([...self::employeeWith('employee'), 'manager.person'])->orderByDesc('start_date');
    }
}
