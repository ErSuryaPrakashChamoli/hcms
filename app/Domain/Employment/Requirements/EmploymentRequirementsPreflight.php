<?php

namespace App\Domain\Employment\Requirements;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Master relationships R2, OD-3: before a rule of an employment data requirements policy may Block, a pre-flight
 * checks it against today's data. For the people the policy reaches it counts who would fail each rule on their next
 * change, and for each company it checks the required masters exist (a company with no grades cannot require one).
 * "Switch to Block" publishes a new version with the chosen rules on Block and the pre-flight record the policy
 * validator asks for (EmploymentRequirements::validatePolicySettings). Read-only otherwise.
 */
final class EmploymentRequirementsPreflight
{
    private const SAMPLES = 10;

    public function __construct(
        private readonly PolicyResolver $resolver,
        private readonly EmployeeRuleContext $context,
        private readonly PositionRequirements $requirements,
        private readonly EntityApplicability $applicability,
        private readonly Policies $policies,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $settings  proposed settings (default: the version in force, else the latest)
     * @return array{policy: string, on: string, employees: int, companies: array<int, string>, fingerprint: string,
     *     rules: array<string, array{label: string, mode: string, violations: int, samples: list<string>, unconfigured: list<string>, safe: bool}>}
     */
    public function run(Policy $policy, ?array $settings = null, CarbonInterface|string|null $on = null): array
    {
        $on = Carbon::parse($on ?? today())->startOfDay();
        $settings ??= (array) ($policy->versionEffectiveOn($on)?->settings ?? $policy->versions()->first()?->settings ?? []);
        $normal = EmploymentRequirements::normalise($settings);
        $label = $policy->name.' (pre-flight)';
        $candidates = $this->resolver->candidates(EmploymentRequirements::TYPE, $on);

        $rules = [];
        if ($normal['required_mode'] !== EmploymentRequirements::OFF && ($normal['required_on_hire'] !== [] || $normal['required_on_change'] !== [])) {
            $rules[EmploymentRequirements::REQUIRED] = $normal['required_mode'];
        }
        foreach ($normal['checks'] as $check => $mode) {
            if ($mode !== EmploymentRequirements::OFF) {
                $rules[$check] = $mode;
            }
        }
        $report = array_map(fn (string $mode) => ['mode' => $mode, 'violations' => 0, 'samples' => [], 'unconfigured' => []], $rules);
        $reached = 0;
        $companies = [];

        Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->with('person')->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$report, &$reached, &$companies, $candidates, $policy, $settings, $label, $on) {
                $contexts = $this->context->buildMany($chunk, $on);
                $seats = EmployeePosition::query()->withoutGlobalScope(AccessScope::class)->whereIn('employee_id', $chunk->modelKeys())->effectiveOn($on)
                    ->orderByDesc('effective_from')->get(['employee_id', 'position_id'])->unique('employee_id')->pluck('position_id', 'employee_id');
                // Counts cover everyone the policy reaches; examples name only people the viewer may see.
                $visible = Employee::query()->whereKey($chunk->modelKeys())->pluck('id')->flip();
                foreach ($chunk as $employee) {
                    $context = $contexts[$employee->id] ?? null;
                    if ($context === null || blank($context['company_id'] ?? null)
                        || $this->resolver->firstMatching($candidates, $context, $on)?->policy_id !== $policy->id) {
                        continue;
                    }
                    $reached++;
                    $companies[(int) $context['company_id']] = true;
                    $position = array_intersect_key($context, EmployeePosition::DIMENSIONS) + ['position_id' => $seats[$employee->id] ?? null];
                    foreach ($this->requirements->evaluate($position, 'transfer', $settings, $label, $on, $employee)->problems as $problem) {
                        if (! isset($report[$problem->rule])) {
                            continue;
                        }
                        $report[$problem->rule]['violations']++;
                        if (isset($visible[$employee->id]) && count($report[$problem->rule]['samples']) < self::SAMPLES) {
                            $report[$problem->rule]['samples'][] = trim(($employee->employee_code ?? '#'.$employee->id).' — '.$problem->message);
                        }
                    }
                }
            });

        // The companies the policy can reach even with nobody there yet: those its rules name, else every company it
        // applies to.
        $all = null;
        foreach ($policy->assignmentRules()->where('status', 'active')->get() as $rule) {
            $named = collect((array) $rule->conditions)->first(fn ($c) => ($c['field'] ?? null) === 'company_id' && in_array($c['operator'] ?? 'in', ['in', 'equals'], true));
            $ids = $named !== null ? (array) $named['value']
                : ($this->applicability->reach($policy, $on) ?? ($all ??= Company::query()->withoutGlobalScope(AccessScope::class)->pluck('id')->all()));
            foreach ($ids as $id) {
                $companies[(int) $id] = true;
            }
        }

        // A required field a company cannot fill: no master of that kind may be used there.
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey(array_keys($companies))->orderBy('name')->pluck('name', 'id')->all();
        if (isset($report[EmploymentRequirements::REQUIRED])) {
            $fields = array_values(array_unique([...$normal['required_on_hire'], ...$normal['required_on_change']]));
            foreach (array_keys($names) as $companyId) {
                foreach ($fields as $column) {
                    $model = (new EmployeePosition)->{EmployeePosition::DIMENSIONS[$column][0]}()->getRelated()::class;
                    if ($this->applicability->options($model, (int) $companyId) === []) {
                        $report[EmploymentRequirements::REQUIRED]['unconfigured'][] = "{$names[$companyId]} has no ".strtolower(EmployeePosition::DIMENSIONS[$column][1]).' it may use.';
                    }
                }
            }
        }

        $out = [];
        foreach ($report as $rule => $row) {
            $out[$rule] = ['label' => EmploymentRequirements::ruleLabel($rule)] + $row + ['safe' => $row['unconfigured'] === [] && $row['violations'] === 0];
        }

        return ['policy' => $policy->name, 'on' => $on->toDateString(), 'employees' => $reached, 'companies' => $names,
            'fingerprint' => EmploymentRequirements::fingerprint($settings), 'rules' => $out];
    }

    /**
     * Publish a new version with the chosen rules on Block, after a pre-flight of exactly those settings. Refused when
     * a company cannot meet a required field; when people would fail today, only with $acceptCurrentGaps (their next
     * change then needs the data first).
     *
     * @param  list<string>  $rules  'required' and/or check keys
     */
    public function switchToBlock(Policy $policy, array $rules, User $actor, string $reason, CarbonInterface|string|null $from = null, bool $acceptCurrentGaps = false): PolicyVersion
    {
        if ($policy->type !== EmploymentRequirements::TYPE) {
            throw new ConfigurationException('Only an employment data requirements policy has a pre-flight.');
        }
        if (! $actor->can('policy.update') || ! $actor->can('policy.publish')) {
            throw new ConfigurationException('Switching rules to Block publishes the policy: you need to be allowed to change and publish policies.');
        }
        if (trim($reason) === '') {
            throw new ConfigurationException('Give the reason for blocking.');
        }
        $known = [EmploymentRequirements::REQUIRED, ...array_keys(EmploymentRequirements::CHECKS)];
        $rules = array_values(array_unique(array_intersect($known, $rules)));
        if ($rules === []) {
            throw new ConfigurationException('Choose the rules to switch to Block.');
        }
        if ($policy->draft()->exists()) {
            throw new ConfigurationException('A draft of this policy is waiting: publish or discard it first, then run the pre-flight again.');
        }
        $base = $policy->versionEffectiveOn(today())?->settings ?? throw new ConfigurationException('Publish the policy before switching rules to Block.');
        unset($base['preflight']);

        $settings = $base;
        foreach ($rules as $rule) {
            $settings[$rule === EmploymentRequirements::REQUIRED ? 'required_mode' : "check_{$rule}"] = EmploymentRequirements::BLOCK;
        }
        $report = $this->run($policy, $settings);
        foreach ($rules as $rule) {
            $row = $report['rules'][$rule] ?? null;
            if ($row === null) {
                throw new ConfigurationException('"'.EmploymentRequirements::ruleLabel($rule).'" has nothing to block: give it fields or switch it on first.');
            }
            if ($row['unconfigured'] !== []) {
                throw new ConfigurationException('Not safe to block "'.$row['label'].'": '.implode(' ', $row['unconfigured']));
            }
            if ($row['violations'] > 0 && ! $acceptCurrentGaps) {
                throw new ConfigurationException("{$row['violations']} current employees would fail \"{$row['label']}\" on their next change. Complete their data first, or accept that their next change needs it.");
            }
        }

        $settings['preflight'] = [
            'run_at' => now()->toIso8601String(),
            'by' => $actor->getKey(),
            'fingerprint' => EmploymentRequirements::fingerprint($settings),
            'safe_rules' => $rules,
            'employees' => $report['employees'],
            'violations' => array_map(fn (array $r) => $r['violations'], $report['rules']),
            'accepted_gaps' => $acceptCurrentGaps,
        ];

        return DB::transaction(function () use ($policy, $settings, $reason, $from, $rules, $report, $acceptCurrentGaps) {
            $this->policies->draft($policy, $settings, $reason);
            $version = $this->policies->publish($policy, $from ?? today(), $reason);
            $this->audit->record(AuditAction::Update, 'configuration', $policy, reason: $reason, effectiveDate: $version->effective_from,
                metadata: ['employment_requirements_block' => $rules, 'preflight_employees' => $report['employees'], 'accepted_gaps' => $acceptCurrentGaps,
                    'violations' => array_map(fn (array $r) => $r['violations'], $report['rules'])]);

            return $version;
        });
    }
}
