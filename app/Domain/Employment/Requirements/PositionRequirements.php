<?php

namespace App\Domain\Employment\Requirements;

use App\Domain\Compensation\Models\EmployeeSalaryAssignment;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Services\DesignationDefaults;
use App\Domain\Workforce\Models\Position;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Master relationships R2: the one check point for the employment data a position needs. AssignPositionAction calls it
 * for every hire, rehire, transfer, promotion and other change (screens, uploads and the API all go through that
 * action); upload previews call assess() so a refusal shows before approval. The policy is resolved from the
 * PROPOSED position (company, location, …), so a company's own rules and its overrides apply even to a hire. With no
 * policy nothing beyond the company is required, exactly as before.
 */
final class PositionRequirements
{
    public const HIRE_TYPES = ['hire', 'rehire'];

    /** @var array<string, ?string> */
    private array $names = [];

    public function __construct(
        private readonly PolicyResolver $resolver,
        private readonly EmployeeRuleContext $context,
        private readonly DesignationDefaults $defaults,
    ) {}

    /** The policy version in force for this position (with its company's overrides), or null: Company only. */
    public function policyFor(array $position, ?Employee $employee = null, CarbonInterface|string|null $on = null): ?PolicyVersion
    {
        $context = $this->context->forPosition($position, $employee, $on);

        return $this->resolver->withOverrides($this->resolver->matchingRule(EmploymentRequirements::TYPE, $context, $on)?->policy?->versionEffectiveOn($on), $context, $on);
    }

    /**
     * What the requirements say about a position (as it would be after the change), without refusing anything.
     *
     * @param  array<string, mixed>  $position  position field => id (position_id = the named seat, if any)
     */
    public function assess(array $position, string $changeType, CarbonInterface|string|null $on = null, ?Employee $employee = null): RequirementsResult
    {
        $on = Carbon::parse($on ?? now())->startOfDay();
        $version = $this->policyFor($position, $employee, $on);
        if ($version === null) {
            return RequirementsResult::none();
        }

        return $this->evaluate($position, $changeType, (array) $version->settings, trim(($version->policy?->name ?? 'Employment data requirements').', v'.$version->version), $on, $employee);
    }

    /**
     * The rules of one set of settings applied to a position (assess() with the policy already chosen; the pre-flight
     * evaluates proposed settings with it).
     *
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>  $rawSettings
     */
    public function evaluate(array $position, string $changeType, array $rawSettings, string $policy, CarbonInterface|string|null $on = null, ?Employee $employee = null): RequirementsResult
    {
        $on = Carbon::parse($on ?? now())->startOfDay();
        $settings = EmploymentRequirements::normalise($rawSettings);
        $company = $this->name(Company::class, $position['company_id'] ?? null) ?? 'this company';
        $source = "(Employment data requirements: {$policy})";
        $problems = [];

        // Required fields: on hire and rehire, or on any other change.
        $required = in_array($changeType, self::HIRE_TYPES, true) ? $settings['required_on_hire'] : $settings['required_on_change'];
        if ($settings['required_mode'] !== EmploymentRequirements::OFF) {
            foreach ($required as $column) {
                if (blank($position[$column] ?? null)) {
                    $label = EmploymentRequirements::requirableFields()[$column];
                    $problems[] = new RequirementProblem(EmploymentRequirements::REQUIRED, $column, $settings['required_mode'], "{$label} is required for {$company} employees {$source}.");
                }
            }
        }

        $checks = $settings['checks'];
        $level = filled($position['level_id'] ?? null) ? (int) $position['level_id'] : null;
        $grade = filled($position['grade_id'] ?? null) ? (int) $position['grade_id'] : null;
        $designation = filled($position['designation_id'] ?? null) ? (int) $position['designation_id'] : null;

        if ($checks['grade_level'] !== EmploymentRequirements::OFF && $level !== null && $grade !== null) {
            $gradeLevel = Grade::query()->withoutGlobalScope(AccessScope::class)->whereKey($grade)->value('level_id');
            if ($gradeLevel !== null && (int) $gradeLevel !== $level) {
                $problems[] = new RequirementProblem('grade_level', 'grade_id', $checks['grade_level'],
                    sprintf('Grade %s belongs to level %s, not %s %s.', $this->name(Grade::class, $grade), $this->name(Level::class, $gradeLevel), $this->name(Level::class, $level), $source));
            }
        }

        if ($designation !== null && $checks['designation_defaults'] !== EmploymentRequirements::OFF) {
            $defaults = $this->defaults->for($designation, filled($position['company_id'] ?? null) ? (int) $position['company_id'] : null, $on);
            foreach (['level_id' => Level::class, 'grade_id' => Grade::class] as $column => $class) {
                $chosen = filled($position[$column] ?? null) ? (int) $position[$column] : null;
                if ($defaults[$column] !== null && $chosen !== null && $chosen !== $defaults[$column]) {
                    $what = strtolower(EmploymentRequirements::requirableFields()[$column]);
                    $problems[] = new RequirementProblem('designation_defaults', $column, $checks['designation_defaults'],
                        sprintf("%s's %s at %s is %s; this position has %s %s.", $this->name(Designation::class, $designation), $what, $company, $this->name($class, $defaults[$column]), $this->name($class, $chosen), $source));
                }
            }
        }

        if ($designation !== null && $checks['usual_department'] !== EmploymentRequirements::OFF && filled($position['department_id'] ?? null)) {
            $usual = Designation::query()->withoutGlobalScope(AccessScope::class)->whereKey($designation)->value('department_id');
            if ($usual !== null && (int) $usual !== (int) $position['department_id']) {
                $problems[] = new RequirementProblem('usual_department', 'department_id', $checks['usual_department'],
                    sprintf("%s's usual department is %s (information only); this position is in %s %s.", $this->name(Designation::class, $designation), $this->name(Department::class, $usual), $this->name(Department::class, $position['department_id']), $source));
            }
        }

        if ($checks['seat_match'] !== EmploymentRequirements::OFF && filled($position['position_id'] ?? null)) {
            $seat = Position::query()->withoutGlobalScope(AccessScope::class)->find($position['position_id'])?->versionOn($on);
            foreach (['designation_id' => Designation::class, 'grade_id' => Grade::class] as $column => $class) {
                $chosen = filled($position[$column] ?? null) ? (int) $position[$column] : null;
                $wanted = $seat?->getAttribute($column);
                if ($seat !== null && $wanted !== null && $chosen !== null && (int) $wanted !== $chosen) {
                    $problems[] = new RequirementProblem('seat_match', $column, $checks['seat_match'],
                        sprintf('Seat %s is for %s %s; this position has %s %s.', $seat->title ?? '#'.$position['position_id'], strtolower(EmploymentRequirements::requirableFields()[$column]), $this->name($class, $wanted), $this->name($class, $chosen), $source));
                }
            }
        }

        if ($employee !== null && ! in_array($changeType, self::HIRE_TYPES, true) && $checks['salary_structure'] !== EmploymentRequirements::OFF) {
            $assignment = EmployeeSalaryAssignment::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $employee->getKey())
                ->active()->effectiveOn($on)->orderByDesc('effective_from')->with('structure')->first();
            $structureVersion = $assignment?->structure?->versionOn($on);
            $mismatch = $structureVersion?->mismatch(array_intersect_key($position, EmployeePosition::DIMENSIONS));
            if ($mismatch !== null) {
                $problems[] = new RequirementProblem('salary_structure', null, $checks['salary_structure'],
                    sprintf("Salary structure %s does not apply to the new position's %s: review the salary %s.", $assignment->structure->code, $mismatch, $source));
            }
        }

        return new RequirementsResult($problems, $policy);
    }

    /**
     * assess() for many employees' current positions at once (readiness lists): one query for their positions, the
     * rules read once, each policy version once per company.
     *
     * @param  iterable<Employee>  $employees  with their person loaded
     * @return array<int, RequirementsResult> employee id => result (only those a policy reaches)
     */
    public function assessMany(iterable $employees, CarbonInterface|string|null $on = null, string $changeType = 'transfer'): array
    {
        $on = Carbon::parse($on ?? now())->startOfDay();
        $candidates = $this->resolver->candidates(EmploymentRequirements::TYPE, $on);
        if ($candidates->isEmpty()) {
            return [];
        }
        $versions = [];
        $out = [];
        foreach ($this->context->buildMany($employees, $on) as $employeeId => $context) {
            $rule = $this->resolver->firstMatching($candidates, $context, $on);
            if ($rule === null || blank($context['company_id'] ?? null)) {
                continue;
            }
            $key = $rule->policy_id.'@'.$context['company_id'];
            if (! array_key_exists($key, $versions)) {
                $versions[$key] = $this->resolver->withOverrides($rule->policy?->versionEffectiveOn($on), $context, $on);
            }
            $version = $versions[$key];
            if ($version !== null) {
                $out[$employeeId] = $this->evaluate(array_intersect_key($context, EmployeePosition::DIMENSIONS), $changeType, (array) $version->settings,
                    trim(($version->policy?->name ?? 'Employment data requirements').', v'.$version->version), $on);
            }
        }

        return $out;
    }

    /**
     * assess() and refuse when a Block rule fails; otherwise the warnings, for the caller to show and the audit trail.
     *
     * @param  array<string, mixed>  $position
     */
    public function enforce(array $position, string $changeType, CarbonInterface|string|null $on = null, ?Employee $employee = null): RequirementsResult
    {
        $result = $this->assess($position, $changeType, $on, $employee);
        if ($result->blocking() !== []) {
            throw new EmploymentRequirementsException($result);
        }

        return $result;
    }

    /** @param  class-string<Model>  $class */
    private function name(string $class, mixed $id): ?string
    {
        if (blank($id)) {
            return null;
        }
        $key = $class.'#'.$id;
        if (! array_key_exists($key, $this->names)) {
            $this->names[$key] = $class::query()->withoutGlobalScope(AccessScope::class)->whereKey($id)->value('name') ?? '#'.$id;
        }

        return $this->names[$key];
    }
}
