<?php

namespace App\Domain\Configuration\Cloning;

use App\Domain\Configuration\Cloning\Adapters\GenericCloneAdapter;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use Illuminate\Database\Eloquent\Model;

/**
 * The cloneable configuration types: config('peopleos.cloning.adapters'), key => adapter class, plus every type registered
 * in config('peopleos.configuration_types') with a 'clone' key of its own name, served by GenericCloneAdapter.
 */
final class CloneRegistry
{
    /** @var array<string, CloneAdapter> */
    private array $resolved = [];

    /** @return array<string, class-string<CloneAdapter>> */
    public function keys(): array
    {
        $keys = (array) config('peopleos.cloning.adapters', []);
        foreach ((array) config('peopleos.configuration_types', []) as $type => $spec) {
            if (($spec['clone'] ?? null) === $type && ! isset($keys[$type])) {
                $keys[$type] = GenericCloneAdapter::class;
            }
        }

        return $keys;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->keys());
    }

    public function for(string $key): CloneAdapter
    {
        $class = $this->keys()[$key] ?? throw new ConfigurationException("Nothing of the kind \"{$key}\" can be cloned.");
        $adapter = $this->resolved[$key] ??= ($class === GenericCloneAdapter::class ? app($class, ['key' => $key]) : app($class));
        if (! $adapter instanceof CloneAdapter) {
            throw new ConfigurationException("{$class} is not a clone adapter.");
        }

        return $adapter;
    }

    public function forModel(Model|string $model): ?CloneAdapter
    {
        $class = $model instanceof Model ? $model::class : $model;
        foreach (array_keys($this->keys()) as $key) {
            if ($this->for($key)->model() === $class) {
                return $this->for($key);
            }
        }

        return null;
    }

    public function label(string $key): string
    {
        return $this->has($key) ? $this->for($key)->label() : $key;
    }

    /** @return array<string, string> key => label, for filters */
    public function labels(): array
    {
        return collect(array_keys($this->keys()))->mapWithKeys(fn (string $key) => [$key => $this->label($key)])->all();
    }
}
