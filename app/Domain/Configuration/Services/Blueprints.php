<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\Form;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyAssignmentRule;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\PermissionRegistry;
use App\Domain\Organisation\Models\EmployeeCategory;
use App\Domain\Organisation\Models\EmploymentType;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\JobFamily;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\WorkMode;
use App\Domain\People\Models\Skill;
use App\Domain\Platform\Services\FeatureFlags;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Configuration blueprints (§77) and packs (§76): configuration only, never employee data.
 * Records are matched by code/key so importing twice is idempotent.
 */
final class Blueprints
{
    public const FORMAT = 'peopleos.blueprint/1';

    public function __construct(
        private readonly PermissionRegistry $permissions,
        private readonly SettingsRepository $settings,
        private readonly FeatureFlags $features,
        private readonly Policies $policies,
        private readonly Forms $forms,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function export(): array
    {
        $simple = fn (string $model, array $columns) => $model::query()->orderBy('code')->get()
            ->map(fn ($row) => array_intersect_key($row->getAttributes(), array_flip($columns)))->values()->all();

        $grades = Grade::query()->with('level')->orderBy('code')->get()
            ->map(fn (Grade $g) => ['code' => $g->code, 'name' => $g->name, 'rank' => $g->rank, 'level_code' => $g->level?->code, 'description' => $g->description])->all();

        return [
            'format' => self::FORMAT,
            'exported_at' => now()->toIso8601String(),
            'settings' => $this->settings->all(),
            'features' => $this->features->all(),
            'roles' => Role::query()->with('permissions')->orderBy('slug')->get()
                ->map(fn (Role $r) => ['slug' => $r->slug, 'name' => $r->name, 'description' => $r->description, 'is_system' => $r->is_system, 'permissions' => $r->permissions->pluck('key')->sort()->values()->all()])->all(),
            'levels' => $simple(Level::class, ['code', 'name', 'rank', 'description']),
            'grades' => $grades,
            'job_families' => $simple(JobFamily::class, ['code', 'name', 'description']),
            'employment_types' => $simple(EmploymentType::class, ['code', 'name', 'description']),
            'employee_categories' => $simple(EmployeeCategory::class, ['code', 'name', 'description']),
            'work_modes' => $simple(WorkMode::class, ['code', 'name', 'description']),
            'skills' => $simple(Skill::class, ['code', 'name', 'category']),
            'custom_fields' => CustomField::query()->orderBy('entity')->orderBy('sort_order')->get()
                ->map(fn (CustomField $f) => array_intersect_key($f->toArray(), array_flip(['entity', 'key', 'label', 'type', 'options', 'help_text', 'is_required', 'visible_to_employee', 'visible_to_manager', 'visible_to_hr', 'is_searchable', 'is_reportable', 'sort_order', 'validation'])))->all(),
            'forms' => Form::query()->with('published')->orderBy('key')->get()
                ->map(fn (Form $f) => ['key' => $f->key, 'name' => $f->name, 'description' => $f->description, 'requires_approval' => $f->requires_approval, 'fields' => $f->published?->fields ?? $f->versions()->first()?->fields ?? []])->all(),
            'policies' => Policy::query()->with(['versions', 'assignmentRules'])->orderBy('code')->get()
                ->map(fn (Policy $p) => [
                    'code' => $p->code, 'type' => $p->type, 'name' => $p->name, 'description' => $p->description,
                    'settings' => $p->versionEffectiveOn()?->settings ?? $p->versions->first()?->settings ?? [],
                    'rules' => $p->assignmentRules->map(fn (PolicyAssignmentRule $r) => ['name' => $r->name, 'priority' => $r->priority, 'match' => $r->match, 'conditions' => $r->conditions])->all(),
                ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, int> section => records touched
     */
    public function import(array $blueprint, ?string $reason = null): array
    {
        if (($blueprint['format'] ?? null) !== self::FORMAT) {
            throw new ConfigurationException('Unrecognised blueprint format.');
        }

        $reason ??= 'Blueprint import';
        $counts = [];

        DB::transaction(function () use ($blueprint, $reason, &$counts) {
            foreach ($blueprint['settings'] ?? [] as $key => $value) {
                if ($value !== null) {
                    $this->settings->set($key, $value, $reason);
                    $counts['settings'] = ($counts['settings'] ?? 0) + 1;
                }
            }

            foreach ($blueprint['features'] ?? [] as $feature => $enabled) {
                $this->features->set($feature, (bool) $enabled, $reason);
                $counts['features'] = ($counts['features'] ?? 0) + 1;
            }

            $upsert = function (string $section, string $model, array $rows, string $keyColumn = 'code') use ($reason, &$counts) {
                foreach ($rows as $row) {
                    $record = $model::query()->firstOrNew([$keyColumn => $row[$keyColumn]]);
                    $record->fill($row)->withAuditReason($reason)->save();
                    $counts[$section] = ($counts[$section] ?? 0) + 1;
                }
            };

            $upsert('levels', Level::class, $blueprint['levels'] ?? []);

            foreach ($blueprint['grades'] ?? [] as $row) {
                $grade = Grade::query()->firstOrNew(['code' => $row['code']]);
                $grade->fill(['name' => $row['name'], 'rank' => $row['rank'] ?? 0, 'description' => $row['description'] ?? null, 'level_id' => isset($row['level_code']) ? Level::query()->where('code', $row['level_code'])->value('id') : null]);
                $grade->withAuditReason($reason)->save();
                $counts['grades'] = ($counts['grades'] ?? 0) + 1;
            }

            $upsert('job_families', JobFamily::class, $blueprint['job_families'] ?? []);
            $upsert('employment_types', EmploymentType::class, $blueprint['employment_types'] ?? []);
            $upsert('employee_categories', EmployeeCategory::class, $blueprint['employee_categories'] ?? []);
            $upsert('work_modes', WorkMode::class, $blueprint['work_modes'] ?? []);
            $upsert('skills', Skill::class, $blueprint['skills'] ?? []);

            foreach ($blueprint['roles'] ?? [] as $row) {
                $role = Role::query()->firstOrNew(['slug' => $row['slug']]);
                $role->fill(['name' => $row['name'], 'description' => $row['description'] ?? null, 'is_system' => $role->exists ? $role->is_system : (bool) ($row['is_system'] ?? false)]);
                $role->withAuditReason($reason)->save();
                $role->permissions()->sync($this->permissions->idsMatching($row['permissions'] ?? []));
                $counts['roles'] = ($counts['roles'] ?? 0) + 1;
            }

            foreach ($blueprint['custom_fields'] ?? [] as $row) {
                $field = CustomField::query()->firstOrNew(['entity' => $row['entity'], 'key' => $row['key']]);
                $field->fill($row)->withAuditReason($reason)->save();
                $counts['custom_fields'] = ($counts['custom_fields'] ?? 0) + 1;
            }

            foreach ($blueprint['forms'] ?? [] as $row) {
                $form = Form::query()->firstOrNew(['key' => $row['key']]);
                $form->fill(['name' => $row['name'], 'description' => $row['description'] ?? null, 'requires_approval' => (bool) ($row['requires_approval'] ?? false)]);
                $form->withAuditReason($reason)->save();

                $published = $form->published()->first();

                if ($published === null || $published->fields != ($row['fields'] ?? [])) {
                    $draft = $this->forms->draft($form);
                    $draft->withAuditReason($reason)->update(['fields' => $row['fields'] ?? []]);

                    if (! empty($row['fields'])) {
                        $this->forms->publish($form, $reason);
                    }
                }

                $counts['forms'] = ($counts['forms'] ?? 0) + 1;
            }

            foreach ($blueprint['policies'] ?? [] as $row) {
                $policy = Policy::query()->firstOrNew(['code' => $row['code']]);
                $policy->fill(['type' => $row['type'], 'name' => $row['name'], 'description' => $row['description'] ?? null]);
                $policy->withAuditReason($reason)->save();

                $current = $policy->versionEffectiveOn();

                if ($current === null || $current->settings != ($row['settings'] ?? [])) {
                    $this->policies->draft($policy, $row['settings'] ?? [], $reason);
                    $this->policies->publish($policy, now(), $reason);
                }

                foreach ($row['rules'] ?? [] as $ruleRow) {
                    $rule = PolicyAssignmentRule::query()->firstOrNew(['policy_id' => $policy->id, 'name' => $ruleRow['name']]);
                    $rule->fill(['policy_type' => $policy->type, 'priority' => $ruleRow['priority'] ?? 100, 'match' => $ruleRow['match'] ?? 'all', 'conditions' => $ruleRow['conditions'] ?? []]);
                    $rule->withAuditReason($reason)->save();
                }

                $counts['policies'] = ($counts['policies'] ?? 0) + 1;
            }

            $this->audit->record(AuditAction::Import, 'configuration', null, reason: $reason, metadata: ['sections' => $counts, 'format' => self::FORMAT]);
        });

        return $counts;
    }

    /** @return array<string, array{name: string, description: string}> */
    public function packs(): array
    {
        $packs = [];

        foreach (glob(config('peopleos.configuration.packs_path').'/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            $packs[basename($path, '.json')] = ['name' => $data['name'] ?? basename($path, '.json'), 'description' => $data['description'] ?? ''];
        }

        return $packs;
    }

    /** @return array<string, int> */
    public function applyPack(string $key, ?string $reason = null): array
    {
        $path = config('peopleos.configuration.packs_path')."/{$key}.json";

        if (! is_file($path)) {
            throw new ConfigurationException("Unknown configuration pack [{$key}].");
        }

        $blueprint = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->import($blueprint, $reason ?? "Applied configuration pack: {$key}");
    }
}
