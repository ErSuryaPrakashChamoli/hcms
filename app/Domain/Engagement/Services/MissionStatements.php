<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\MissionStatement;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne setup parity HP-9b: which mission statement an employee sees. Among the active statements in force today (no
 * effective date, or one on or before today) whose audience matches the employee, the most specific wins: the one with
 * the most audience conditions (an empty audience = everyone comes last), then the latest in force, then the newest.
 */
final class MissionStatements
{
    public function __construct(private readonly RuleEngine $rules, private readonly EmployeeRuleContext $context) {}

    public function forEmployee(Employee $employee, bool $homeOnly = false): ?MissionStatement
    {
        $statements = MissionStatement::query()->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()->format('Y-m-d 23:59:59')))
            ->when($homeOnly, fn (Builder $q) => $q->where('show_on_home', true))
            ->get();
        if ($statements->isEmpty()) {
            return null;
        }
        $context = $this->context->build($employee);

        return $statements->filter(fn (MissionStatement $s) => $this->rules->matches((array) ($s->audience ?? []), $context))
            ->sortBy([
                fn (MissionStatement $a, MissionStatement $b) => count((array) $b->audience) <=> count((array) $a->audience),
                fn (MissionStatement $a, MissionStatement $b) => ($b->effective_from?->timestamp ?? 0) <=> ($a->effective_from?->timestamp ?? 0),
                fn (MissionStatement $a, MissionStatement $b) => $b->id <=> $a->id,
            ])->first();
    }
}
