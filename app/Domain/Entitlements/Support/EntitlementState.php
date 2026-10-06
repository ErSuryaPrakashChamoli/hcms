<?php

namespace App\Domain\Entitlements\Support;

use App\Domain\Entitlements\Enums\Capability;

/**
 * SaaS.3: everything the evaluator needs about one tenant's commercial configuration, loaded once (cache, then
 * one query per table) and evaluated in memory for any business date. Immutable; small (one row per capability
 * period). Rows are kept as arrays so the state caches as plain data.
 *
 * Row shape: ['id' => int, 'capability' => string, 'value_bool' => ?bool, 'value_int' => ?int, 'from' => 'Y-m-d', 'to' => ?'Y-m-d'].
 * Only rows that can ever apply are loaded (status active); cancelled rows never took effect.
 */
final class EntitlementState
{
    /**
     * @param  list<array<string, mixed>>  $entitlements
     * @param  list<array<string, mixed>>  $overrides
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly ?string $configuredFrom,
        public readonly array $entitlements = [],
        public readonly array $overrides = [],
        public readonly int $version = 0,
    ) {}

    public static function unconfigured(int $tenantId): self
    {
        return new self($tenantId, null);
    }

    public function isConfiguredOn(string $day): bool
    {
        return $this->configuredFrom !== null && $this->configuredFrom <= $day;
    }

    /** @return array<string, mixed>|null the configuration row covering the day */
    public function entitlementOn(Capability $capability, string $day): ?array
    {
        return self::covering($this->entitlements, $capability, $day);
    }

    /** @return array<string, mixed>|null the override covering the day */
    public function overrideOn(Capability $capability, string $day): ?array
    {
        return self::covering($this->overrides, $capability, $day);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['tenant_id' => $this->tenantId, 'configured_from' => $this->configuredFrom, 'entitlements' => $this->entitlements,
            'overrides' => $this->overrides, 'version' => $this->version];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['tenant_id'], $data['configured_from'] ?? null, $data['entitlements'] ?? [], $data['overrides'] ?? [], (int) ($data['version'] ?? 0));
    }

    /**
     * Inclusive business-date ranges (the HasEffectiveDates convention). The configuration services never let two
     * active rows for one capability overlap; should corrupt data ever do so, the answer is still deterministic:
     * the latest start wins, then the highest id.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private static function covering(array $rows, Capability $capability, string $day): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($row['capability'] !== $capability->value || $row['from'] > $day || ($row['to'] !== null && $row['to'] < $day)) {
                continue;
            }
            if ($best === null || $row['from'] > $best['from'] || ($row['from'] === $best['from'] && $row['id'] > $best['id'])) {
                $best = $row;
            }
        }

        return $best;
    }
}
