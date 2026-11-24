<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Configuration\Exceptions\ConfigurationException;
use Illuminate\Database\Eloquent\Model;

/**
 * The registry of configuration types that take part in the applicability framework: config('peopleos.configuration_types'),
 * key => spec. Registering a type is all it takes for the shared services (applicability, overrides, cloning, dependency
 * and usage checks, the screens' selector, column, filter and actions) to work for it.
 */
final class ConfigurationTypes
{
    /** @var array<string, ConfigurationType> */
    private array $types = [];

    /** @return array<string, ConfigurationType> */
    public function all(): array
    {
        foreach (array_keys((array) config('peopleos.configuration_types', [])) as $key) {
            $this->types[$key] ??= ConfigurationType::fromConfig($key, (array) config('peopleos.configuration_types')[$key]);
        }

        return $this->types;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('peopleos.configuration_types', []));
    }

    public function for(string $key): ConfigurationType
    {
        return $this->all()[$key] ?? throw new ConfigurationException("\"{$key}\" is not a registered configuration type.");
    }

    public function forModel(Model|string $model): ?ConfigurationType
    {
        $class = $model instanceof Model ? $model::class : $model;
        foreach ($this->all() as $type) {
            if ($type->model === $class) {
                return $type;
            }
        }

        return null;
    }

    public function forModelOrFail(Model|string $model): ConfigurationType
    {
        return $this->forModel($model) ?? throw new ConfigurationException((is_string($model) ? $model : $model::class).' is not a registered configuration type.');
    }

    /** @return array<string, ConfigurationType> */
    public function inModule(string $module): array
    {
        return array_filter($this->all(), fn (ConfigurationType $t) => $t->module === $module);
    }
}
