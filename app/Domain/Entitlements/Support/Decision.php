<?php

namespace App\Domain\Entitlements\Support;

use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\DecisionOutcome;
use App\Domain\Entitlements\Enums\DecisionReason;
use App\Domain\Entitlements\Enums\DecisionSource;

/**
 * SaaS.3: one entitlement answer: what, for which tenant, on which business date, why, and from which layer.
 * It never carries employee data, salaries, tokens or secrets. In SaaS.3 every decision is a shadow decision:
 * enforced() is always false and no caller may use a decision to refuse an action.
 */
final class Decision
{
    public const MODE = 'shadow';

    public function __construct(
        public readonly Capability $capability,
        public readonly DecisionOutcome $outcome,
        public readonly DecisionReason $reason,
        public readonly DecisionSource $source,
        public readonly ?int $tenantId,
        public readonly string $effectiveOn,
        public readonly ?int $entitlementId = null,
        public readonly ?int $overrideId = null,
        public readonly ?int $limit = null,
        public readonly ?int $usage = null,
        public readonly ?string $surface = null,
    ) {}

    public static function unknown(Capability $capability, DecisionReason $reason, ?int $tenantId, string $effectiveOn, ?string $surface = null): self
    {
        return new self($capability, DecisionOutcome::Unknown, $reason, DecisionSource::None, $tenantId, $effectiveOn, surface: $surface);
    }

    public function withSurface(string $surface): self
    {
        return new self($this->capability, $this->outcome, $this->reason, $this->source, $this->tenantId, $this->effectiveOn,
            $this->entitlementId, $this->overrideId, $this->limit, $this->usage, $surface);
    }

    /** SaaS.3: shadow mode. Nothing is ever enforced; this is the single place that says so. */
    public function enforced(): bool
    {
        return false;
    }

    public function mode(): string
    {
        return self::MODE;
    }

    public function allowed(): bool
    {
        return $this->outcome === DecisionOutcome::Allow;
    }

    /** A decision that WOULD refuse the action if enforcement were enabled. */
    public function wouldDeny(): bool
    {
        return $this->outcome === DecisionOutcome::Deny;
    }

    /** @return array<string, mixed> safe for logs, diagnostics and audit metadata */
    public function toArray(): array
    {
        return array_filter([
            'capability' => $this->capability->value,
            'tenant_id' => $this->tenantId,
            'decision' => $this->outcome->value,
            'reason' => $this->reason->value,
            'source' => $this->source->value,
            'mode' => self::MODE,
            'enforced' => false,
            'effective_on' => $this->effectiveOn,
            'entitlement_id' => $this->entitlementId,
            'override_id' => $this->overrideId,
            'limit' => $this->limit,
            'usage' => $this->usage,
            'surface' => $this->surface,
        ], fn ($v) => $v !== null);
    }
}
