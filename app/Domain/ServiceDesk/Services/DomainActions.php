<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\ServiceDesk\Contracts\ServiceDomainAction;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;

/** Phase 12: the registry of service → domain hand-offs (`peopleos.servicedesk.domain_actions`). */
final class DomainActions
{
    /** @return array<string, string> key => label */
    public function options(): array
    {
        return collect(config('peopleos.servicedesk.domain_actions', []))->map(fn (string $class, string $key) => app($class)->label())->all();
    }

    public function find(?string $key): ?ServiceDomainAction
    {
        // Keys contain dots (profile.bank_account): read the map, never a dotted config path.
        $class = $key ? (config('peopleos.servicedesk.domain_actions', [])[$key] ?? null) : null;

        return $class ? app($class) : null;
    }

    public function get(string $key): ServiceDomainAction
    {
        return $this->find($key) ?? throw new ServiceDeskRuleViolation("Unknown domain action [{$key}].");
    }
}
