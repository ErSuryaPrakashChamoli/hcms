<?php

namespace App\Domain\Employment\Requirements;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Employment\Models\EmployeePosition;
use Illuminate\Support\Carbon;

/**
 * Master relationships R2: the "Employment data requirements" policy type (peopleos.policies.types.employment_data).
 * Which position fields a hire or a change must name, and consistency checks, each Off, Warn or Block. Warn is the
 * default (OD-3); Block is accepted only with a current pre-flight that found it safe (EmploymentRequirementsPreflight).
 * A tenant without such a policy keeps today's rule: only the company is required.
 */
final class EmploymentRequirements
{
    public const TYPE = 'employment_data';

    public const OFF = 'off';

    public const WARN = 'warn';

    public const BLOCK = 'block';

    public const MODES = [self::OFF => 'Off', self::WARN => 'Warn', self::BLOCK => 'Block'];

    /** How long a pre-flight stays current for switching rules to Block. */
    public const PREFLIGHT_DAYS = 30;

    /** The rule key of the required-fields rule (the checks use their own keys). */
    public const REQUIRED = 'required';

    /** Consistency checks: key => [label, what it compares]. */
    public const CHECKS = [
        'grade_level' => ['Grade sits under the level', 'When a position names both, the grade must belong to that level.'],
        'designation_defaults' => ["Level and grade match the designation's defaults for the company", "The designation's own level and grade for the company (its company value, otherwise the shared one)."],
        'usual_department' => ["Department matches the designation's usual department", 'The usual department is information only (OD-1): this compares, it never places anyone.'],
        'seat_match' => ["The seat's designation and grade match the person's", 'When the position takes a named seat, its designation and grade agree with the seat.'],
        'salary_structure' => ['The salary structure still fits after a transfer or promotion', "The employee's salary structure is limited to some grades or other dimensions and the new position falls outside them."],
    ];

    /** The position fields a policy may require (the company is always required). */
    public static function requirableFields(): array
    {
        return collect(EmployeePosition::DIMENSIONS)->except('company_id')->map(fn (array $d) => $d[1])->all();
    }

    /** Why a required field matters: what reads it (shown with every "is required" warning or refusal). */
    public const WHY = [
        'location_id' => 'the statutory establishment (PF, ESI, PT, LWF) and location-based policies such as holidays read it',
        'business_unit_id' => 'policies and reports by business unit read it',
        'division_id' => 'policies and reports by division read it',
        'department_id' => 'policies, reports and increment filters by department read it',
        'team_id' => 'policies and reports by team read it',
        'designation_id' => "letters, reports and the designation's level and grade defaults read it",
        'level_id' => 'policies by level read it',
        'grade_id' => 'pay bands, increments and salary formulas by grade read it',
        'employment_type_id' => 'leave, attendance and statutory policies by employment type read it',
        'employee_category_id' => 'policies by employee category read it',
        'work_mode_id' => 'policies by work mode read it',
        'cost_centre_id' => 'payroll cost allocation (JV) reads it',
        'dimension_value_id' => "policies by the organisation's own dimension read it",
    ];

    public static function why(string $column): string
    {
        return self::WHY[$column] ?? 'policies by this field read it';
    }

    /** OD-4: what a new tenant's seeded policy requires (Company is implied). */
    public const NEW_TENANT_REQUIRED = ['location_id', 'department_id', 'designation_id', 'employment_type_id'];

    /**
     * The settings with defaults: required lists of known fields, every mode one of off|warn|block (blank = warn).
     *
     * @param  array<string, mixed>  $settings
     * @return array{required_on_hire: list<string>, required_on_change: list<string>, required_mode: string, checks: array<string, string>}
     */
    public static function normalise(array $settings): array
    {
        $fields = array_keys(self::requirableFields());
        $list = fn (string $key) => array_values(array_intersect($fields, array_map('strval', (array) ($settings[$key] ?? []))));
        $mode = fn (string $key) => array_key_exists((string) ($settings[$key] ?? ''), self::MODES) ? (string) $settings[$key] : self::WARN;
        $checks = [];
        foreach (array_keys(self::CHECKS) as $check) {
            $checks[$check] = $mode("check_{$check}");
        }

        return ['required_on_hire' => $list('required_on_hire'), 'required_on_change' => $list('required_on_change'), 'required_mode' => $mode('required_mode'), 'checks' => $checks];
    }

    /**
     * The rules set to Block: 'required' and/or check keys.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public static function blockRules(array $settings): array
    {
        $s = self::normalise($settings);
        $rules = $s['required_mode'] === self::BLOCK && ($s['required_on_hire'] !== [] || $s['required_on_change'] !== []) ? [self::REQUIRED] : [];
        foreach ($s['checks'] as $check => $mode) {
            if ($mode === self::BLOCK) {
                $rules[] = $check;
            }
        }

        return $rules;
    }

    /**
     * The settings a copy of a policy starts from (configuration cloning): a pre-flight vouches for the policy it was
     * run for, so the copy drops it and its Block rules start on Warn until a pre-flight of the copy.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function forCopy(array $settings): array
    {
        unset($settings['preflight']);
        foreach (['required_mode', ...array_map(fn (string $c) => "check_{$c}", array_keys(self::CHECKS))] as $key) {
            if (($settings[$key] ?? null) === self::BLOCK) {
                $settings[$key] = self::WARN;
            }
        }

        return $settings;
    }

    /** What a pre-flight vouches for: the whole rule set, so any change needs a new pre-flight before Block. */
    public static function fingerprint(array $settings): string
    {
        return hash('sha256', json_encode(self::normalise($settings)));
    }

    public static function ruleLabel(string $rule): string
    {
        return $rule === self::REQUIRED ? 'Required fields' : (self::CHECKS[$rule][0] ?? $rule);
    }

    /**
     * The policy type's validator (Policies::validateSettings): runs when the wizard saves and when a version is
     * published. Block needs a pre-flight record on the version (written only by the pre-flight's "Switch to Block"),
     * for exactly these settings, run within PREFLIGHT_DAYS, that found every Block rule safe.
     *
     * @param  array<string, mixed>  $settings
     */
    public function validatePolicySettings(array $settings): void
    {
        foreach (['required_mode', ...array_map(fn ($c) => "check_{$c}", array_keys(self::CHECKS))] as $key) {
            if (filled($settings[$key] ?? null) && ! array_key_exists((string) $settings[$key], self::MODES)) {
                throw new ConfigurationException('Each rule is Off, Warn or Block.');
            }
        }
        $blocks = self::blockRules($settings);
        if ($blocks === []) {
            return;
        }
        $preflight = is_array($settings['preflight'] ?? null) ? $settings['preflight'] : null;
        $names = implode(', ', array_map(self::ruleLabel(...), $blocks));
        if ($preflight === null || ($preflight['fingerprint'] ?? null) !== self::fingerprint($settings)) {
            throw new ConfigurationException("Block ({$names}) is switched on only after a pre-flight check of these rules: open Workforce setup → Employment data requirements, run the pre-flight and use \"Switch to Block\". Until then choose Warn.");
        }
        $ranAt = isset($preflight['run_at']) ? Carbon::parse($preflight['run_at']) : null;
        if ($ranAt === null || $ranAt->lt(now()->subDays(self::PREFLIGHT_DAYS))) {
            throw new ConfigurationException('The pre-flight check is more than '.self::PREFLIGHT_DAYS.' days old: run it again before switching to Block.');
        }
        foreach ($blocks as $rule) {
            if (! in_array($rule, (array) ($preflight['safe_rules'] ?? []), true)) {
                throw new ConfigurationException('The pre-flight check did not find "'.self::ruleLabel($rule).'" safe to block: fix what it lists, run it again, or keep the rule on Warn.');
            }
        }
    }
}
