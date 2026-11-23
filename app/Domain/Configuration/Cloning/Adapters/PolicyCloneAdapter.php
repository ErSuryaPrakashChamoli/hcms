<?php

namespace App\Domain\Configuration\Cloning\Adapters;

use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Assets\Models\AssetModel;
use App\Domain\Assets\Models\AssetSubtype;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Configuration\Cloning\ApplicabilityKind;
use App\Domain\Configuration\Cloning\CloneAdapter;
use App\Domain\Configuration\Cloning\ClonePlan;
use App\Domain\Configuration\Cloning\Conflict;
use App\Domain\Configuration\Cloning\Destination;
use App\Domain\Configuration\Cloning\Materialised;
use App\Domain\Configuration\Cloning\Reference;
use App\Domain\Configuration\Cloning\ReferenceKind;
use App\Domain\Configuration\Cloning\Snapshot;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ClearanceChecklist;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Models\ExpenseLocationGroup;
use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Domain\Performance\Models\Competency;
use App\Domain\Performance\Models\GoalTemplate;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\TicketCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cloning for every policy type (config peopleos.policies.types: leave, attendance, overtime, exit, expense, …).
 *
 * - Copies the settings of the version in effect today (else the draft, else the newest), or the version the person
 *   picks, into a new policy's draft v1 (decision OD-5).
 * - Copies the source's current applicability rules (active, not ended), rewritten by the engine for the destination,
 *   saved INACTIVE: PolicyResolver takes active rules of active policies without asking for a published version, so an
 *   active rule on a draft-only policy would resolve to nothing. Publishing the copy turns its rules on in the same step
 *   (Policies::publish()).
 * - Records its settings point at (leave types, shifts, roles, letter / feedback / goal templates, clearance checklists,
 *   expense and asset masters, …) are shared by every company: they are reused as they are. Each settings source is
 *   classified in OPTION_SOURCES or STATIC_SOURCES (an architecture test fails on one that is not).
 */
final class PolicyCloneAdapter implements CloneAdapter
{
    /** @var array<string, array{0: ReferenceKind, 1: class-string<Model>, 2: string}> settings source => kind, model, stored key */
    public const OPTION_SOURCES = [
        'leave_types' => [ReferenceKind::SharedMaster, LeaveType::class, 'code'],
        'shifts' => [ReferenceKind::SharedMaster, Shift::class, 'code'],
        'competencies' => [ReferenceKind::SharedMaster, Competency::class, 'code'],
        'ticket_categories' => [ReferenceKind::SharedMaster, TicketCategory::class, 'id'],
        'expense_categories' => [ReferenceKind::SharedMaster, ExpenseCategory::class, 'id'],
        'expense_location_groups' => [ReferenceKind::SharedMaster, ExpenseLocationGroup::class, 'id'],
        'asset_categories' => [ReferenceKind::SharedMaster, AssetCategory::class, 'id'],
        'asset_subtypes' => [ReferenceKind::SharedMaster, AssetSubtype::class, 'id'],
        'asset_models' => [ReferenceKind::SharedMaster, AssetModel::class, 'id'],
        'roles' => [ReferenceKind::Role, Role::class, 'slug'],
        'clearance_checklists' => [ReferenceKind::ReferencedConfiguration, ClearanceChecklist::class, 'id'],
        'letter_templates' => [ReferenceKind::ReferencedConfiguration, LetterTemplate::class, 'id'],
        'feedback_templates' => [ReferenceKind::ReferencedConfiguration, FeedbackTemplate::class, 'id'],
        'goal_templates' => [ReferenceKind::ReferencedConfiguration, GoalTemplate::class, 'code'],
        'service_definitions' => [ReferenceKind::ReferencedConfiguration, ServiceDefinition::class, 'id'],
    ];

    /** Settings sources that are fixed lists in configuration, not records: nothing to resolve. */
    public const STATIC_SOURCES = ['loan_types', 'expense_periods'];

    public function __construct(
        private readonly Policies $policies,
        private readonly PolicyResolver $resolver,
        private readonly EmployeeRuleContext $context,
        private readonly RuleEngine $rules,
    ) {}

    public function key(): string
    {
        return 'policy';
    }

    public function label(): string
    {
        return 'Policy';
    }

    public function model(): string
    {
        return Policy::class;
    }

    public function createPermission(): string
    {
        return 'policy.create';
    }

    public function applicability(): ApplicabilityKind
    {
        return ApplicabilityKind::RuleConditions;
    }

    public function find(int $id): ?Model
    {
        return Policy::query()->find($id);
    }

    public function sourceOptions(Model $source): array
    {
        /** @var Policy $source */
        $versions = $source->versions()->get();
        if ($versions->isEmpty()) {
            return [];
        }
        $inForce = $source->versionEffectiveOn();
        $options = $versions->mapWithKeys(fn (PolicyVersion $v) => [$v->id => "v{$v->version} · ".match (true) {
            $inForce?->is($v) => 'in force since '.$v->effective_from?->format('d M Y'),
            $v->status === VersionStatus::Draft => 'draft',
            $v->status === VersionStatus::Published && $v->effective_from?->isFuture() => 'published, from '.$v->effective_from->format('d M Y'),
            default => $v->status->value.($v->effective_from ? ', from '.$v->effective_from->format('d M Y') : ''),
        }])->all();

        return ['version_id' => ['label' => 'Copy the settings of', 'options' => $options, 'default' => $this->defaultVersion($source)?->id,
            'help' => 'The version in force today, unless you pick another. The copy starts as a draft either way.']];
    }

    public function snapshot(Model $source, array $options = []): Snapshot
    {
        /** @var Policy $source */
        $version = isset($options['version_id']) && filled($options['version_id'])
            ? $source->versions()->whereKey((int) $options['version_id'])->first()
            : null;
        $version ??= $this->defaultVersion($source);
        $today = now()->toDateString();
        $rules = $source->assignmentRules()->where('status', ActiveStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderBy('priority')->orderBy('id')->get()
            ->map(fn (PolicyAssignmentRule $r) => [
                'id' => (int) $r->id, 'name' => (string) $r->name, 'priority' => (int) $r->priority, 'match' => (string) ($r->match ?: 'all'),
                'conditions' => array_values((array) $r->conditions), 'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
            ])->all();

        return new Snapshot(
            adapter: $this->key(),
            sourceId: (int) $source->id,
            label: "{$source->name} ({$source->code})",
            identity: ['type' => $source->type, 'name' => $source->name, 'code' => $source->code, 'description' => $source->description, 'status' => $source->status?->value],
            content: ['version_id' => $version?->id, 'version' => $version?->version, 'version_status' => $version?->status?->value, 'settings' => (array) ($version?->settings ?? [])],
            applicability: ['rules' => $rules, 'not_copied' => $source->assignmentRules()->count() - count($rules)],
        );
    }

    public function references(Snapshot $snapshot): array
    {
        $found = [];
        $this->walk((array) config('peopleos.policies.types.'.$snapshot->identity['type'].'.fields', []), (array) ($snapshot->content['settings'] ?? []), 'settings.', $found);

        $references = [];
        foreach ($found as [$path, $label, $source, $value]) {
            [$kind, $model, $column] = self::OPTION_SOURCES[$source];
            $target = $model::query()->withoutGlobalScope(AccessScope::class)->where($column, $value)->first();
            $status = $target?->getAttribute('status');
            $status = $status instanceof \BackedEnum ? $status->value : $status;
            $references[] = new Reference(
                path: $path, label: $label, kind: $kind, type: $source, value: $column === 'id' && ctype_digit((string) $value) ? (int) $value : (string) $value,
                targetId: $target?->getKey() === null ? null : (int) $target->getKey(),
                targetLabel: $target === null ? null : (string) ($target->getAttribute('name') ?? $value),
                strategy: $kind->defaultStrategy(),
                problem: match (true) {
                    $target === null => 'it no longer exists; the copy keeps the same setting, so check it before publishing',
                    $status !== null && $status !== 'active' => 'it is not active',
                    default => null,
                },
            );
        }

        return $references;
    }

    public function impact(Snapshot $snapshot, Destination $destination, ClonePlan $plan): ?array
    {
        $rules = collect($plan->rulesFor($destination->companyId))->sortBy('priority')->values();
        $limit = (int) config('peopleos.cloning.limits.impact_employees', 2000);
        $employees = Employee::query()->withoutGlobalScope(AccessScope::class)->with('person')->employed()
            ->whereHas('positions', fn ($q) => $q->effectiveOn(now())->where('company_id', $destination->companyId))
            ->orderBy('id')->limit($limit + 1)->get();
        $capped = $employees->count() > $limit;
        $matched = 0;
        $winning = 0;
        $holders = [];
        $candidates = $this->resolver->candidates($snapshot->identity['type']);
        foreach ($this->context->buildMany($employees->take($limit)) as $context) {
            $mine = $rules->first(fn (array $rule) => $this->rules->matches($rule['conditions'], $context, 'all'));
            if ($mine === null) {
                continue;
            }
            $matched++;
            $current = $this->resolver->firstMatching($candidates, $context);
            if ($current === null || (int) $mine['priority'] < (int) $current->priority) {
                $winning++;
            } else {
                $key = $current->policy_id;
                $holders[$key] = ['name' => (string) $current->policy?->name, 'priority' => (int) $current->priority, 'count' => ($holders[$key]['count'] ?? 0) + 1];
            }
        }

        return ['employees' => min($employees->count(), $limit), 'matched' => $matched, 'winning' => $winning, 'capped' => $capped, 'holders' => array_values($holders)];
    }

    public function conflicts(Snapshot $snapshot, Destination $destination, ClonePlan $plan): array
    {
        $company = $destination->companyId;
        $name = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($company)->value('name') ?? 'the company';
        if ($plan->rulesFor($company) === []) {
            return [Conflict::warning('applies_to_nobody', "The copy for {$name} has no applicability rules, so it applies to nobody until you choose who it applies to.", $company)];
        }
        $impact = $plan->impact[$company] ?? null;
        if ($impact === null || $impact['employees'] === 0) {
            return [];
        }
        if ($impact['matched'] === 0) {
            return [Conflict::warning('matches_nobody', "Nobody in {$name} matches the copy's rules today.", $company)];
        }
        $conflicts = [];
        foreach ((array) ($impact['holders'] ?? []) as $holder) {
            $conflicts[] = Conflict::warning('outranked', "{$holder['count']} of the {$impact['matched']} people the copy covers in {$name} would keep \"{$holder['name']}\" (priority {$holder['priority']}), which ranks first. Give the copy a lower priority number for it to win.", $company);
        }

        return $conflicts;
    }

    public function codeTaken(string $code): bool
    {
        // Compared as MySQL's unique index compares it (case-insensitively).
        return Policy::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->exists();
    }

    public function lookAlikes(Snapshot $snapshot, Destination $destination): array
    {
        return Policy::query()->where('type', $snapshot->identity['type'])->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($destination->name))])
            ->orderBy('name')->pluck('name')->all();
    }

    public function materialise(Snapshot $snapshot, Destination $destination, ClonePlan $plan, User $actor): Materialised
    {
        $reason = $plan->auditReason();
        $policy = new Policy(['type' => $snapshot->identity['type'], 'name' => trim($destination->name), 'code' => $destination->code,
            'description' => $snapshot->identity['description'] ?? null, 'status' => ActiveStatus::Active]);
        $policy->withAuditReason($reason)->save();
        $this->policies->draft($policy, (array) ($snapshot->content['settings'] ?? []), 'Copied from '.$snapshot->label.(isset($snapshot->content['version']) ? " v{$snapshot->content['version']}" : ''));

        $children = [];
        foreach ($plan->rulesFor($destination->companyId) as $rule) {
            $copy = new PolicyAssignmentRule([
                'policy_type' => $policy->type, 'policy_id' => $policy->id, 'name' => $rule['name'], 'priority' => (int) $rule['priority'], 'match' => 'all',
                'conditions' => $rule['conditions'], 'status' => ActiveStatus::Inactive, 'effective_from' => $rule['effective_from'], 'effective_to' => $rule['effective_to'],
            ]);
            $copy->withAuditReason($reason)->save();
            $children[] = ['adapter' => 'policy.rule', 'source_id' => $rule['source_rule_id'] ?? null, 'target_id' => (int) $copy->id];
        }

        return new Materialised($policy, $children);
    }

    public function excluded(): array
    {
        return ['expense_receipts', 'expense_advances', 'feedback_rounds'];
    }

    /** The version in force today, else the draft, else the newest. */
    private function defaultVersion(Policy $policy): ?PolicyVersion
    {
        return $policy->versionEffectiveOn() ?? $policy->draft()->first() ?? $policy->versions()->first();
    }

    /**
     * Every record-backed setting value, with where it sits and its label (sections are flat in settings; repeater items
     * are walked with their own fields).
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $settings
     * @param  list<array{0: string, 1: string, 2: string, 3: int|string}>  $found
     */
    private function walk(array $fields, array $settings, string $prefix, array &$found): void
    {
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            if ($type === 'section') {
                $this->walk((array) ($field['fields'] ?? []), $settings, $prefix, $found);

                continue;
            }
            $key = (string) ($field['key'] ?? '');
            $value = $settings[$key] ?? null;
            if ($type === 'repeater') {
                foreach (array_values((array) $value) as $i => $item) {
                    if (is_array($item)) {
                        $this->walk((array) ($field['fields'] ?? []), $item, "{$prefix}{$key}.{$i}.", $found);
                    }
                }

                continue;
            }
            $source = $field['options_from'] ?? null;
            if (! is_string($source) || ! isset(self::OPTION_SOURCES[$source])) {
                continue;
            }
            foreach ((array) $value as $one) {
                if ($one !== null && $one !== '' && (is_int($one) || is_string($one))) {
                    $found[] = ["{$prefix}{$key}", (string) ($field['label'] ?? $key), $source, $one];
                }
            }
        }
    }
}
