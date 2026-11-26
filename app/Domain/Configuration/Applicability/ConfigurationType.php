<?php

namespace App\Domain\Configuration\Applicability;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One configuration type in config('peopleos.configuration_types'): what it is, which company owns a record (if any),
 * which permission manages it, and which of the three capabilities it supports (shared across companies, per-company
 * overrides, cloning), with the references and usages the framework validates.
 */
final class ConfigurationType
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $references  column (or settings path) => configuration type key it points at
     * @param  list<array{table: string, column: string, company: string}>  $usages  rows that use a record, with the company column
     * @param  array<string, array<string, mixed>>|string|null  $overrides  field => spec, or an OverrideFields provider class
     * @param  array<string, class-string<Model>>  $ownerFrom  columns whose record fixes the owning company (a location's establishment, parent branch)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $module,
        public readonly string $model,
        public readonly ?string $ownerColumn,
        public readonly string $permission,
        public readonly bool $share,
        public readonly array|string|null $overrides,
        public readonly ?string $clone,
        public readonly array $references,
        public readonly array $usages,
        public readonly bool $payrollSensitive,
        public readonly ?string $resource,
        public readonly string $nameColumn,
        public readonly ?string $codeColumn,
        public readonly array $ownerFrom = [],
        public readonly bool $ownerRequired = false,
    ) {}

    /** @param  array<string, mixed>  $spec */
    public static function fromConfig(string $key, array $spec): self
    {
        return new self(
            key: $key,
            label: (string) ($spec['label'] ?? Str::headline($key)),
            module: (string) ($spec['module'] ?? 'configuration'),
            model: (string) $spec['model'],
            ownerColumn: $spec['owner'] ?? null,
            permission: (string) ($spec['permission'] ?? 'configuration.update'),
            share: (bool) ($spec['share'] ?? true),
            overrides: $spec['overrides'] ?? null,
            clone: $spec['clone'] ?? null,
            references: (array) ($spec['references'] ?? []),
            usages: array_values((array) ($spec['usages'] ?? [])),
            payrollSensitive: (bool) ($spec['payroll_sensitive'] ?? false),
            resource: $spec['resource'] ?? null,
            nameColumn: (string) ($spec['name'] ?? 'name'),
            codeColumn: array_key_exists('code', $spec) ? $spec['code'] : 'code',
            ownerFrom: (array) ($spec['owner_from'] ?? []),
            ownerRequired: (bool) ($spec['owner_required'] ?? false),
        );
    }

    public function plural(): string
    {
        return Str::plural(Str::lower($this->label));
    }

    public function supportsOverrides(): bool
    {
        return $this->overrides !== null && $this->overrides !== [];
    }

    public function supportsCloning(): bool
    {
        return $this->clone !== null;
    }

    /** Whether a record belongs to one company through its own column (departments, locations, …). */
    public function companyOwned(): bool
    {
        return $this->ownerColumn !== null;
    }

    public function describe(Model $record): string
    {
        $name = (string) ($record->getAttribute($this->nameColumn) ?? '');
        $code = $this->codeColumn !== null ? (string) ($record->getAttribute($this->codeColumn) ?? '') : '';

        return trim($name.($code !== '' && $code !== $name ? " ({$code})" : '')) ?: "{$this->label} #{$record->getKey()}";
    }
}
