<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Employment\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which policy of a type applies to an employee on a date (§26, §43).
 *
 * Configuration applicability (docs/architecture/configuration-applicability.md): a policy whose companies were chosen
 * explicitly is skipped for employees of other companies, and the version returned carries the employee's company's
 * overrides of shared settings (a read-only copy; the stored version is never changed). Every consumer that reads
 * settings through resolve()/resolveAll() therefore sees the company's own values without knowing about them.
 */
final class PolicyResolver
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly EmployeeRuleContext $context,
        private readonly EntityApplicability $applicability,
        private readonly ConfigurationOverrides $overrides,
    ) {}

    public function resolve(string $type, Employee $employee, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        $context = $this->context->build($employee, $on);
        $rule = $this->matchingRule($type, $context, $on);

        return $this->withOverrides($rule?->policy?->versionEffectiveOn($on), $context, $on);
    }

    /** @return array<string, ?PolicyVersion> type => version */
    public function resolveAll(Employee $employee, CarbonInterface|string|null $on = null): array
    {
        $context = $this->context->build($employee, $on);
        $resolved = [];

        foreach (array_keys(config('peopleos.policies.types', [])) as $type) {
            $resolved[$type] = $this->withOverrides($this->matchingRule($type, $context, $on)?->policy?->versionEffectiveOn($on), $context, $on);
        }

        return $resolved;
    }

    /** @param  array<string, mixed>  $context */
    public function matchingRule(string $type, array $context, CarbonInterface|string|null $on = null): ?PolicyAssignmentRule
    {
        return $this->firstMatching($this->candidateRules($type, $on), $context, $on);
    }

    /**
     * The version with the company's overrides in force on the date applied, as a read-only copy (the same version when
     * the company has none).
     *
     * @param  array<string, mixed>  $context
     */
    public function withOverrides(?PolicyVersion $version, array $context, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        $company = $context['company_id'] ?? null;
        if ($version === null || ! filled($company) || ! $this->overrides->hasAny('policy', (int) $version->policy_id)) {
            return $version;
        }
        $values = $this->overrides->valuesFor('policy', (int) $version->policy_id, (int) $company, $on);
        if ($values === []) {
            return $version;
        }
        $copy = clone $version;
        $copy->setAttribute('settings', $this->overrides->applyTo((array) $version->settings, 'policy', (int) $version->policy_id, (int) $company, $on));
        $copy->overriddenForCompany = (int) $company;
        $copy->overriddenPaths = array_keys($values);

        return $copy;
    }

    /**
     * The rules of a type that may apply on a date, best first (what matchingRule() walks), for callers that resolve
     * many employees at once (configuration cloning's impact count).
     *
     * @return Collection<int, PolicyAssignmentRule>
     */
    public function candidates(string $type, CarbonInterface|string|null $on = null): Collection
    {
        return $this->candidateRules($type, $on);
    }

    /**
     * @param  iterable<PolicyAssignmentRule>  $candidates  best first
     * @param  array<string, mixed>  $context
     */
    public function firstMatching(iterable $candidates, array $context, CarbonInterface|string|null $on = null): ?PolicyAssignmentRule
    {
        $company = filled($context['company_id'] ?? null) ? (int) $context['company_id'] : null;
        foreach ($candidates as $rule) {
            // A policy limited to some companies never reaches another company's employees, whatever its rules say.
            if ($company !== null && $rule->policy !== null && ! $this->applicability->appliesTo($rule->policy, $company, $on)) {
                continue;
            }
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
