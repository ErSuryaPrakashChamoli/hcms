<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['tenant_id', 'custom_field_id', 'model_type', 'model_id', 'value_text', 'value_number', 'value_date', 'value_json'])]
class CustomFieldValue extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
            'value_date' => 'date',
            'value_json' => 'array',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        $field = $this->relationLoaded('field') ? $this->field : $this->field()->first();

        return ($field?->auditLabel() ?? 'custom field').' on '.class_basename($this->model_type).'#'.$this->model_id;
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function value(): mixed
    {
        return match ($this->field->type) {
            'number' => $this->value_number === null ? null : (float) $this->value_number,
            'date' => $this->value_date?->toDateString(),
            'multiselect' => $this->value_json,
            'boolean' => $this->value_text === null ? null : $this->value_text === '1',
            default => $this->value_text,
        };
    }
}
