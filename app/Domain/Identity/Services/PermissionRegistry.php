<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The permission catalogue lives in config/peopleos.php; this service mirrors it into the
 * permissions table and expands wildcard patterns into concrete keys.
 */
final class PermissionRegistry
{
    /** @return array<string, string> key => description */
    public function catalogue(): array
    {
        $all = [];

        foreach (config('peopleos.permissions', []) as $keys) {
            $all += $keys;
        }

        return $all;
    }

    public function isKnown(string $key): bool
    {
        return array_key_exists($key, $this->catalogue());
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_keys(config('peopleos.permissions', []));
    }

    /**
     * Expand patterns such as `company.*` or `*` into catalogue keys.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public function expand(array $patterns): array
    {
        $keys = array_keys($this->catalogue());

        return collect($keys)
            ->filter(fn (string $key) => collect($patterns)->contains(fn (string $pattern) => Str::is($pattern, $key)))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $patterns
     * @return Collection<int, int>
     */
    public function idsMatching(array $patterns): Collection
    {
        return Permission::query()->whereIn('key', $this->expand($patterns))->pluck('id');
    }

    /**
     * Upsert the catalogue into the permissions table. Removed keys are pruned.
     *
     * @return array{created: int, pruned: int}
     */
    public function sync(): array
    {
        $existing = Permission::query()->pluck('id', 'key');
        $created = 0;

        foreach (config('peopleos.permissions', []) as $module => $keys) {
            foreach ($keys as $key => $description) {
                Permission::query()->updateOrCreate(['key' => $key], ['module' => $module, 'description' => $description]);

                if (! $existing->has($key)) {
                    $created++;
                }
            }
        }

        $pruned = Permission::query()->whereNotIn('key', array_keys($this->catalogue()))->delete();

        return ['created' => $created, 'pruned' => $pruned];
    }
}
