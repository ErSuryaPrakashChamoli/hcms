<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Custom Field Engine (§41): definitions per entity, typed storage, validation. */
final class CustomFields
{
    /** @return array<string, array{label: string, model: class-string<Model>}> */
    public function entities(): array
    {
        return config('peopleos.custom_fields.entities', []);
    }

    public function entityKeyFor(Model|string $model): string
    {
        $class = is_string($model) ? $model : $model::class;

        foreach ($this->entities() as $key => $entity) {
            if ($entity['model'] === $class) {
                return $key;
            }
        }

        throw new ConfigurationException("{$class} does not support custom fields.");
    }

    /** @return Collection<int, CustomField> */
    public function definitionsFor(Model|string $model, bool $effectiveOnly = true): Collection
    {
        $entity = $this->entityKeyFor($model);

        return CustomField::query()
            ->forEntity($entity)
            ->when($effectiveOnly, fn ($q) => $q->currentlyEffective())
            ->get();
    }

    /** @return array<string, mixed> key => typed value */
    public function valuesFor(Model $model): array
    {
        $values = [];

        $rows = $model->customFieldValues()->with('field')->get();

        foreach ($rows as $row) {
            if ($row->field !== null) {
                $values[$row->field->key] = $row->value();
            }
        }

        return $values;
    }

    /**
     * Validate then persist values (only keys with a live definition are written).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the validated values
     *
     * @throws ValidationException
     */
    public function store(Model $model, array $values, ?string $reason = null): array
    {
        // Partial updates are allowed: only keys that were supplied are validated and written.
        $definitions = $this->definitionsFor($model)->keyBy('key')->intersectByKeys($values);

        $validated = Validator::make(
            array_intersect_key($values, $definitions->all()),
            $definitions->mapWithKeys(fn (CustomField $f) => [$f->key => $f->rules()])->all(),
            [],
            $definitions->mapWithKeys(fn (CustomField $f) => [$f->key => $f->label])->all(),
        )->validate();

        DB::transaction(function () use ($model, $definitions, $validated, $reason) {
            foreach ($definitions as $key => $field) {
                if (! array_key_exists($key, $validated)) {
                    continue;
                }

                $row = CustomFieldValue::query()->firstOrNew([
                    'custom_field_id' => $field->id,
                    'model_type' => $model->getMorphClass(),
                    'model_id' => $model->getKey(),
                ]);

                $row->setRelation('field', $field);
                $row->fill(['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null]);
                $row->{$field->valueColumn()} = $this->normalise($field, $validated[$key]);
                $row->withAuditReason($reason)->save();
            }
        });

        return $validated;
    }

    private function normalise(CustomField $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->type) {
            'boolean' => $value ? '1' : '0',
            'multiselect' => array_values((array) $value),
            default => $value,
        };
    }
}
