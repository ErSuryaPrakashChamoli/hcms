<?php

namespace App\Domain\Configuration\Overrides;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Leave\Services\LeaveEntitlements;
use Illuminate\Database\Eloquent\Model;

/**
 * Which settings of a policy a company may override: every answer of the policy type's settings form (config
 * peopleos.policies.types.<type>.fields), and, inside its tables (leave entitlements, …), every column of every row the
 * shared policy has, picked by the row's key (entitlements[leave_type_code=AL].days). Overridden settings are checked
 * together with the type's own validator, as a publish would.
 */
final class PolicyOverrideFields implements OverrideFields
{
    /** Field types whose answers are values a company can set (sections only group, tags/text stay shared). */
    private const KINDS = ['number' => 'number', 'boolean' => 'boolean', 'yes_no' => 'boolean', 'select' => 'select', 'radio' => 'select',
        'multiselect' => 'multiselect', 'checkboxes' => 'multiselect', 'time' => 'time'];

    public function fields(Model $record): array
    {
        /** @var Policy $record */
        $schema = (array) config("peopleos.policies.types.{$record->type}.fields", []);
        $settings = $this->shared($record, today()->toDateString());
        $fields = [];
        $this->walk($schema, $settings, null, $fields);

        return $fields;
    }

    public function shared(Model $record, string $on): array
    {
        /** @var Policy $record */
        return (array) ($this->versionOn($record, $on)?->settings ?? []);
    }

    public function validate(Model $record, array $values): void
    {
        /** @var Policy $record */
        try {
            Policies::validateSettings($record->type, $values);
        } catch (ConfigurationException $e) {
            throw new ApplicabilityRefusedException('With this override the policy would not be valid: '.$e->getMessage());
        }
    }

    /** The version a company's settings are read from on a date: in force then, else the draft being prepared. */
    public function versionOn(Policy $policy, string $on): ?PolicyVersion
    {
        return $policy->versionEffectiveOn($on)
            ?? $policy->versions()->where('status', VersionStatus::Draft->value)->latest('version')->first()
            ?? $policy->versions()->latest('version')->first();
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @param  array<string, mixed>  $settings
     * @param  array<string, OverrideField>  $fields
     */
    private function walk(array $schema, array $settings, ?string $group, array &$fields): void
    {
        foreach ($schema as $field) {
            $type = (string) ($field['type'] ?? '');
            $key = (string) ($field['key'] ?? '');
            if ($type === 'section') {
                $this->walk((array) ($field['fields'] ?? []), $settings, (string) ($field['label'] ?? $group), $fields);

                continue;
            }
            if ($type === 'repeater') {
                $children = array_values(array_filter((array) ($field['fields'] ?? []), 'is_array'));
                $rowKey = (string) ($field['row_key'] ?? collect($children)->first(fn (array $c) => in_array($c['type'] ?? '', ['select', 'radio'], true))['key'] ?? '');
                if ($rowKey === '') {
                    continue;
                }
                foreach ((array) ($settings[$key] ?? []) as $row) {
                    if (! is_array($row) || blank($row[$rowKey] ?? null)) {
                        continue;
                    }
                    $rowLabel = ($field['label'] ?? $key).' · '.$row[$rowKey];
                    foreach ($children as $child) {
                        if (($child['key'] ?? null) === $rowKey || ! isset(self::KINDS[$child['type'] ?? ''])) {
                            continue;
                        }
                        $path = "{$key}[{$rowKey}={$row[$rowKey]}].{$child['key']}";
                        $default = $key === 'entitlements' ? (LeaveEntitlements::DEFAULTS[$child['key']] ?? null) : ($child['default'] ?? null);
                        $fields[$path] = $this->field($path, $rowLabel.' · '.($child['label'] ?? $child['key']), $child, $default, $rowLabel);
                    }
                }

                continue;
            }
            if ($key === '' || ! isset(self::KINDS[$type])) {
                continue;
            }
            $fields[$key] = $this->field($key, (string) ($field['label'] ?? $key), $field, $field['default'] ?? null, $group);
        }
    }

    /** @param  array<string, mixed>  $spec */
    private function field(string $path, string $label, array $spec, mixed $default, ?string $group): OverrideField
    {
        $kind = self::KINDS[$spec['type']];
        $options = is_array($spec['options'] ?? null) ? $spec['options'] : [];
        $rules = match ($kind) {
            'number' => array_values(array_filter(['nullable', 'numeric', isset($spec['min']) ? 'min:'.$spec['min'] : null, isset($spec['max']) ? 'max:'.$spec['max'] : null])),
            default => [],
        };

        return new OverrideField($path, $label, $kind, $options, $rules, $default, $group);
    }
}
