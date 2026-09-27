<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/** Which policy of a type applies to an employee on a date (§26, §43). */
final class PolicyResolver
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
    ) {}

    public function resolve(string $type, Employee $employee, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        $rule = $this->matchingRule($type, $this->context->build($employee, $on), $on);

        return $rule?->policy?->versionEffectiveOn($on);
    }

    /** @return array<string, ?PolicyVersion> type => version */
    public function resolveAll(Employee $employee, CarbonInterface|string|null $on = null): array
    {
        $context = $this->context->build($employee, $on);
        $resolved = [];

        foreach (array_keys(config('peopleos.policies.types', [])) as $type) {
            $resolved[$type] = $this->matchingRule($type, $context, $on)?->policy?->versionEffectiveOn($on);
        }

        return $resolved;
    }

    /** @param  array<string, mixed>  $context */
    public function matchingRule(string $type, array $context, CarbonInterface|string|null $on = null): ?PolicyAssignmentRule
    {
        foreach ($this->candidateRules($type, $on) as $rule) {
            if ($this->rules->matches($rule->conditions ?? [], $context, $rule->match)) {
                return $rule;
            }
        }

        return null;
    }

    /** Employees a rule currently captures (used by impact preview). */
    public function employeesMatching(PolicyAssignmentRule $rule, CarbonInterface|string|null $on = null): Collection
    {
        return Employee::query()->with('person')->employed()->get()
            ->filter(fn (Employee $e) => $this->rules->matches($rule->conditions ?? [], $this->context->build($e, $on), $rule->match))
            ->values();
    }

    /** @return Collection<int, PolicyAssignmentRule> */
    private function candidateRules(string $type, CarbonInterface|string|null $on): Collection
    {
        return PolicyAssignmentRule::query()
            ->with('policy')
            ->where('policy_type', $type)
            ->where('status', 'active')
            ->effectiveOn($on)
            ->whereHas('policy', fn ($q) => $q->where('status', 'active'))
            ->orderBy('priority')->orderBy('id')
            ->get();
    }
}
