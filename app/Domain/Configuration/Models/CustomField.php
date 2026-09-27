<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Support\EffectiveDating\HasEffectiveDates;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tenant-defined field on a core entity (§41). */
#[Fillable(['tenant_id', 'entity', 'key', 'label', 'type', 'options', 'help_text', 'is_required', 'visible_to_employee', 'visible_to_manager', 'visible_to_hr', 'is_searchable', 'is_reportable', 'sort_order', 'status', 'effective_from', 'effective_to', 'validation'])]
class CustomField extends Model
{
    use Auditable, BelongsToTenant, HasEffectiveDates;

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'validation' => 'array',
            'is_required' => 'boolean',
            'visible_to_employee' => 'boolean',
            'visible_to_manager' => 'boolean',
            'visible_to_hr' => 'boolean',
            'is_searchable' => 'boolean',
            'is_reportable' => 'boolean',
            'sort_order' => 'integer',
            'status' => ActiveStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return "{$this->entity}.{$this->key}";
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    #[Scope]
    protected function forEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity)->where('status', ActiveStatus::Active)->orderBy('sort_order')->orderBy('id');
    }

    /** Which typed column stores this field's value. */
    public function valueColumn(): string
    {
        return match ($this->type) {
            'number' => 'value_number',
            'date' => 'value_date',
            'multiselect' => 'value_json',
            default => 'value_text',
        };
    }

    /** @return array<string, string> option value => label */
    public function optionMap(): array
    {
        $options = [];

        foreach ($this->options ?? [] as $option) {
            if (is_array($option)) {
                $options[(string) ($option['value'] ?? $option['label'])] = (string) ($option['label'] ?? $option['value']);
            } else {
                $options[(string) $option] = (string) $option;
            }
        }

        return $options;
    }

    /** Laravel validation rules for one value. */
    public function rules(): array
    {
        $rules = [$this->is_required ? 'required' : 'nullable'];

        $rules[] = match ($this->type) {
            'number' => 'numeric',
            'date' => 'date',
            'boolean' => 'boolean',
            'email' => 'email',
            'url' => 'url',
            'dropdown' => 'in:'.implode(',', array_keys($this->optionMap())),
            'multiselect' => 'array',
            default => 'string',
        };

        return [...$rules, ...($this->validation ?? [])];
    }
}
