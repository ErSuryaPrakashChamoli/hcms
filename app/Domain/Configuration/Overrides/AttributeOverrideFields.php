<?php

namespace App\Domain\Configuration\Overrides;

use Illuminate\Database\Eloquent\Model;

/**
 * Overridable columns of a record, declared in the type's spec: 'overrides' => ['grace_in_minutes' => ['label' => …,
 * 'kind' => 'number', 'rules' => [...]], …]. The shared values are the record's own columns.
 */
final class AttributeOverrideFields implements OverrideFields
{
    /** @param  array<string, array<string, mixed>>  $spec */
    public function __construct(private readonly array $spec) {}

    public function fields(Model $record): array
    {
        $fields = [];
        foreach ($this->spec as $column => $field) {
            $fields[$column] = new OverrideField($column, (string) ($field['label'] ?? $column), (string) ($field['kind'] ?? 'text'),
                (array) ($field['options'] ?? []), (array) ($field['rules'] ?? []), $field['default'] ?? null, $field['group'] ?? null);
        }

        return $fields;
    }

    public function shared(Model $record, string $on): array
    {
        $values = [];
        foreach (array_keys($this->spec) as $column) {
            $value = $record->getAttribute($column);
            $values[$column] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $values;
    }

    public function validate(Model $record, array $values): void {}
}
