<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fields are stored as a list of {key, label, type, required, options[], help_text}.
 * Published and retired versions are immutable.
 */
#[Fillable(['tenant_id', 'form_id', 'version', 'fields', 'status', 'published_by', 'published_at', 'retired_at'])]
class FormVersion extends Model
{
    use Auditable, BelongsToTenant;

    public const FIELD_TYPES = [
        'text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number', 'date' => 'Date',
        'dropdown' => 'Dropdown', 'radio' => 'Radio', 'checkbox' => 'Checkbox', 'email' => 'Email',
        'employee' => 'Employee selector', 'department' => 'Department selector', 'location' => 'Location selector',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') !== VersionStatus::Draft->value && $version->isDirty('fields')) {
                throw new ConfigurationException('Published form versions are immutable; create a new draft.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'version' => 'integer',
            'status' => VersionStatus::class,
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        $form = $this->relationLoaded('form') ? $this->form : $this->form()->first();

        return ($form?->name ?? 'Form')." v{$this->version}";
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** Laravel validation rules derived from the field definitions. */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields ?? [] as $field) {
            $set = [($field['required'] ?? false) ? 'required' : 'nullable'];
            $set[] = match ($field['type'] ?? 'text') {
                'number' => 'numeric',
                'date' => 'date',
                'checkbox' => 'boolean',
                'email' => 'email',
                'dropdown', 'radio' => 'in:'.implode(',', array_keys(self::optionMap($field))),
                'employee', 'department', 'location' => 'integer',
                default => 'string',
            };
            $rules[$field['key']] = $set;
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function optionMap(array $field): array
    {
        $map = [];

        foreach ($field['options'] ?? [] as $option) {
            $value = is_array($option) ? ($option['value'] ?? $option['label'] ?? '') : $option;
            $label = is_array($option) ? ($option['label'] ?? $value) : $option;
            $map[(string) $value] = (string) $label;
        }

        return $map;
    }
}
