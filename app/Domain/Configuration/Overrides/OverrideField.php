<?php

namespace App\Domain\Configuration\Overrides;

/** One field of a configuration record that a company may give its own value (an override). */
final class OverrideField
{
    /**
     * @param  string  $kind  number | boolean | select | multiselect | text | time
     * @param  array<int|string, string>  $options
     * @param  list<mixed>  $rules  Laravel validation rules for the value
     */
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly string $kind,
        public readonly array $options = [],
        public readonly array $rules = [],
        public readonly mixed $default = null,
        public readonly ?string $group = null,
    ) {}

    public function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $this->kind === 'boolean' => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', array_map(fn ($v) => (string) ($this->options[$v] ?? $v), $value)) ?: '—',
            $this->options !== [] && array_key_exists((string) $value, $this->options) => (string) $this->options[(string) $value],
            default => (string) $value,
        };
    }

    /** @return list<mixed> */
    public function validationRules(): array
    {
        if ($this->rules !== []) {
            return $this->rules;
        }

        return match ($this->kind) {
            'number' => ['nullable', 'numeric'],
            'boolean' => ['required', 'boolean'],
            'select' => $this->options !== [] ? ['nullable', 'in:'.implode(',', array_map('strval', array_keys($this->options)))] : ['nullable', 'string', 'max:191'],
            'multiselect' => ['nullable', 'array'],
            'time' => ['nullable', 'date_format:H:i'],
            default => ['nullable', 'string', 'max:1000'],
        };
    }

    public function cast(mixed $value): mixed
    {
        return match ($this->kind) {
            'number' => $value === null || $value === '' ? null : (str_contains((string) $value, '.') ? (float) $value : (int) $value),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'multiselect' => array_values((array) $value),
            default => $value === '' ? null : $value,
        };
    }
}
