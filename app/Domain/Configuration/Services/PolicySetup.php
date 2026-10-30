<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Enums\ActiveStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The policy wizard's model (HROne-style "Policy → … → Applicability → Advance applicability → Review"). It reads a
 * policy into one flat state and writes it back in one transaction: the policy, its draft version (a published version is
 * never changed: editing it starts a new draft), its applicability, and optionally publishing from a date.
 *
 * Applicability is kept as plain assignment rules: one over organisation dimensions (no conditions = everyone, else
 * each dimension chosen "is any of") and one naming employees. Rules the wizard creates make specific ones win: named
 * employees (priority 50) before groups (100) before everyone (1000). A policy whose rules use anything else (other
 * operators, "any" matching, more rules) keeps them; the wizard then leaves applicability to the rule editor.
 */
final class PolicySetup
{
    public const DIMENSIONS = ['company_id', 'business_unit_id', 'division_id', 'department_id', 'location_id', 'grade_id', 'level_id', 'designation_id', 'employment_type_id', 'employee_category_id', 'work_mode_id', 'lifecycle_state', 'gender'];

    public const APPLICABILITY = 'Applicability';

    public const EMPLOYEES = 'Specific employees';

    /** Who the policy applies to besides the employees named under advance applicability. */
    public const APPLIES_TO = ['everyone' => 'Everyone', 'groups' => 'Selected groups', 'employees' => 'Only the employees named under advance applicability'];

    public function __construct(private readonly Policies $policies) {}

    /** @return array<string, mixed> the wizard state for a policy (its draft, else its latest version) */
    public function load(Policy $policy): array
    {
        $version = $policy->draft()->first() ?? $policy->versions()->first();
        $rules = $this->plainRules($policy);
        $state = [
            'type' => $policy->type, 'name' => $policy->name, 'code' => $policy->code, 'status' => $policy->status?->value, 'description' => $policy->description,
            'settings' => (array) ($version?->settings ?? []), 'applies_to' => 'employees', 'applicability' => [], 'employees' => [],
            'effective_from' => now()->toDateString(), 'rules_until' => null, 'advanced_rules' => $rules === null,
        ];
        if ($rules['applicability'] ?? null) {
            $conditions = (array) $rules['applicability']->conditions;
            $state['applies_to'] = $conditions === [] ? 'everyone' : 'groups';
            foreach ($conditions as $condition) {
                $state['applicability'][$condition['field']] = array_values((array) ($condition['value'] ?? []));
            }
        }
        if ($rules['employees'] ?? null) {
            $state['employees'] = array_values((array) ($rules['employees']->conditions[0]['value'] ?? []));
        }
        $until = collect($rules ?? [])->filter()->map(fn (PolicyAssignmentRule $r) => $r->effective_to?->toDateString())->filter()->first();
        $state['rules_until'] = $until;

        return $state;
    }

    /** Whether the policy's rules are the plain ones the wizard edits. */
    public function manageable(Policy $policy): bool
    {
        return $this->plainRules($policy) !== null;
    }

    /**
     * The policy's rules when the wizard can edit them: at most one active "all of" rule over organisation dimensions
     * (no conditions = everyone) and at most one naming employees, each condition "is" / "is any of". Null otherwise.
     *
     * @return array{applicability: ?PolicyAssignmentRule, employees: ?PolicyAssignmentRule}|null
     */
    private function plainRules(Policy $policy): ?array
    {
        $found = ['applicability' => null, 'employees' => null];
        foreach ($policy->assignmentRules()->get() as $rule) {
            $conditions = (array) $rule->conditions;
            $fields = array_column($conditions, 'field');
            if ($rule->status !== ActiveStatus::Active || ($rule->match ?? 'all') !== 'all' || count($fields) !== count(array_unique($fields))
                || collect($conditions)->contains(fn (array $c) => ! in_array($c['operator'] ?? '', ['in', 'equals'], true))) {
                return null;
            }
            $kind = $fields === ['employee_id'] ? 'employees' : (array_diff($fields, self::DIMENSIONS) === [] ? 'applicability' : null);
            if ($kind === null || $found[$kind] !== null) {
                return null;
            }
            $found[$kind] = $rule;
        }

        return $found;
    }

    /**
     * Save the wizard: the policy, its draft settings and (when the wizard owns them, see manageable()) its applicability
     * rules; publish from `effective_from` when asked.
     *
     * @param  array<string, mixed>  $state
     */
    public function save(array $state, ?Policy $policy, User $actor, bool $publish, string $reason): Policy
    {
        $type = (string) ($policy?->type ?? $state['type'] ?? '');
        if (! array_key_exists($type, (array) config('peopleos.policies.types'))) {
            throw new ConfigurationException('Choose the kind of policy.');
        }
        if (! $actor->can($policy ? 'policy.update' : 'policy.create')) {
            throw new ConfigurationException('You cannot '.($policy ? 'change' : 'create').' policies.');
        }
        if ($publish && ! $actor->can('policy.publish')) {
            throw new ConfigurationException('You cannot publish policies; save it as a draft for someone who can.');
        }
        if (trim($reason) === '') {
            throw new ConfigurationException('Give the reason for the change.');
        }
        $name = trim((string) ($state['name'] ?? ''));
        if ($name === '') {
            throw new ConfigurationException('Name the policy.');
        }
        $code = trim((string) ($state['code'] ?? '')) ?: Str::upper(Str::slug(Str::limit($name, 30, ''), '_'));
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)) {
            throw new ConfigurationException('The code may use letters, numbers, dashes and underscores only.');
        }
        if (Policy::query()->where('code', $code)->when($policy, fn ($q) => $q->whereKeyNot($policy->id))->exists()) {
            throw new ConfigurationException("Another policy already uses the code {$code}.");
        }

        $ownsRules = $policy === null || $this->manageable($policy);
        // HROne setup parity HP-8d / HP-8f: the type's own checks of its answers (Policies::validateSettings).
        Policies::validateSettings($type, $this->settings($type, (array) ($state['settings'] ?? [])));

        return DB::transaction(function () use ($state, $policy, $type, $name, $code, $publish, $reason, $ownsRules) {
            $policy ??= new Policy(['type' => $type]);
            $status = $state['status'] ?? null;
            $policy->fill(['name' => $name, 'code' => $code, 'status' => $status instanceof ActiveStatus ? $status : (ActiveStatus::tryFrom((string) $status) ?? ActiveStatus::Active),
                'description' => filled($state['description'] ?? null) ? $state['description'] : null]);
            $policy->withAuditReason($reason)->save();

            $this->policies->draft($policy, $this->settings($type, (array) ($state['settings'] ?? [])), $reason);
            if ($ownsRules) {
                $this->rules($policy, $state, $reason);
            }
            if ($publish) {
                $this->policies->publish($policy, Carbon::parse($state['effective_from'] ?? now()), $reason);
            }

            return $policy->refresh();
        });
    }

    /** @return array<string, mixed> only the type's own keys, sections flattened */
    private function settings(string $type, array $settings): array
    {
        $keys = [];
        $walk = function (array $fields) use (&$walk, &$keys) {
            foreach ($fields as $f) {
                $f['type'] === 'section' ? $walk($f['fields'] ?? []) : $keys[] = $f['key'];
            }
        };
        $walk((array) config("peopleos.policies.types.{$type}.fields", []));

        return $keys === [] ? $settings : array_intersect_key($settings, array_flip($keys));
    }

    /** @param  array<string, mixed>  $state */
    private function rules(Policy $policy, array $state, string $reason): void
    {
        $found = $this->plainRules($policy) ?? throw new ConfigurationException('This policy\'s assignment rules are edited in the rule editor.');
        $until = filled($state['rules_until'] ?? null) ? Carbon::parse($state['rules_until'])->toDateString() : null;
        $conditions = collect(self::DIMENSIONS)
            ->map(fn (string $field) => ['field' => $field, 'values' => array_values(array_filter((array) ($state['applicability'][$field] ?? []), fn ($v) => $v !== null && $v !== ''))])
            ->filter(fn (array $c) => $c['values'] !== [])
            ->map(fn (array $c) => ['field' => $c['field'], 'operator' => 'in', 'value' => $c['values']])->values()->all();
        $employees = array_values(array_filter((array) ($state['employees'] ?? [])));
        $appliesTo = (string) ($state['applies_to'] ?? 'everyone');
        if ($appliesTo === 'groups' && $conditions === []) {
            throw new ConfigurationException('Choose at least one group the policy applies to, or apply it to everyone.');
        }

        $appliesTo === 'employees'
            ? $found['applicability']?->withAuditReason($reason)->delete()
            : $this->rule($policy, $found['applicability'], self::APPLICABILITY, $appliesTo === 'everyone' ? 1000 : 100, $appliesTo === 'everyone' ? [] : $conditions, $until, $reason);
        $employees === []
            ? $found['employees']?->withAuditReason($reason)->delete()
            : $this->rule($policy, $found['employees'], self::EMPLOYEES, 50, [['field' => 'employee_id', 'operator' => 'in', 'value' => $employees]], $until, $reason);
    }

    /** @param  list<array<string, mixed>>  $conditions */
    private function rule(Policy $policy, ?PolicyAssignmentRule $rule, string $name, int $priority, array $conditions, ?string $until, string $reason): void
    {
        // A rule the wizard adopts keeps its name and priority, so saving never reorders how policies resolve.
        $rule ??= new PolicyAssignmentRule(['policy_id' => $policy->id, 'policy_type' => $policy->type, 'name' => $name, 'priority' => $priority, 'status' => ActiveStatus::Active]);
        $rule->fill(['match' => 'all', 'conditions' => $conditions, 'effective_to' => $until]);
        if ($rule->isDirty() || ! $rule->exists) {
            $rule->withAuditReason($reason)->save();
        }
    }

    /**
     * Draft and published versions, for the review step.
     *
     * @return array{draft: ?int, current: ?string, published: int}
     */
    public function versions(Policy $policy): array
    {
        $current = $policy->versionEffectiveOn();

        return ['draft' => $policy->draft()->value('version'), 'current' => $current ? "v{$current->version} from ".$current->effective_from?->format('d M Y') : null,
            'published' => $policy->versions()->where('status', VersionStatus::Published)->count()];
    }
}
