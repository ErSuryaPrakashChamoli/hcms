<?php

namespace App\Domain\Identity\Services;

/**
 * UX.15 closure P1-02: one bounded authorisation pass. Inside run(), checks that would otherwise repeat the same
 * lookup for every record (is this employee reachable through the viewer's scope? is this the viewer's own
 * record?) may answer from facts loaded once for the pass, by the same queries, for exactly the records being
 * evaluated. Outside a pass every check runs exactly as before.
 *
 * Request-scoped, and it forgets everything the moment the outermost pass ends, so no fact outlives the evaluation
 * it was loaded for (the access-scope service is a long-lived singleton; this deliberately is not). It never
 * decides anything: policies still evaluate every record; this only spares them repeated identical lookups.
 */
final class AuthorizationContext
{
    private int $depth = 0;

    /** @var array<string, mixed> */
    private array $facts = [];

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            if (--$this->depth === 0) {
                $this->facts = [];
            }
        }
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }

    public function has(string $key): bool
    {
        return $this->depth > 0 && array_key_exists($key, $this->facts);
    }

    public function get(string $key): mixed
    {
        return $this->depth > 0 ? ($this->facts[$key] ?? null) : null;
    }

    /** Record a fact for the current pass (ignored outside a pass). */
    public function put(string $key, mixed $value): void
    {
        if ($this->depth > 0) {
            $this->facts[$key] = $value;
        }
    }

    /**
     * Inside a pass: resolve once and reuse. Outside: resolve every time, as before.
     *
     * @template T
     *
     * @param  callable(): T  $resolve
     * @return T
     */
    public function remember(string $key, callable $resolve): mixed
    {
        if ($this->depth === 0) {
            return $resolve();
        }
        if (! array_key_exists($key, $this->facts)) {
            $this->facts[$key] = $resolve();
        }

        return $this->facts[$key];
    }
}
