<?php

namespace App\Domain\Expense\Support;

/**
 * What an employee may claim in one expense category on one date (ExpenseLimits::for): whether the category is open to
 * them, the limit in force and where it came from, what the period has used and what remains, the claim frequency, the
 * policy's overall limit, what happens over the limit (capped, or filed as an exceptional expense) and when a receipt is
 * needed. Amounts are in the base currency; a distance limit is in km (amountLimit converts it at the category's rate).
 */
final class ExpenseLimit
{
    /**
     * @param  array{0: string, 1: string}|null  $window  Y-m-d
     * @param  array{period: string, count: int, used: int, remaining: int, window: ?array{0: string, 1: string}}|null  $frequency
     * @param  array{amount: float, period: string, used: float, remaining: float, window: ?array{0: string, 1: string}}|null  $overall
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $refusal = null,
        public readonly ?int $policyVersionId = null,
        public readonly ?int $categoryId = null,
        public readonly bool $exceptionalOnly = false,
        public readonly string $basis = 'none',
        public readonly ?string $unit = null,
        public readonly ?float $limit = null,
        public readonly ?float $amountLimit = null,
        public readonly string $period = 'per_claim',
        public readonly ?string $source = null,
        public readonly ?int $limitRowId = null,
        public readonly ?array $window = null,
        public readonly float $used = 0.0,
        public readonly ?float $remaining = null,
        public readonly ?float $hourlyPercent = null,
        public readonly ?array $frequency = null,
        public readonly ?array $overall = null,
        public readonly string $overLimit = 'cap',
        public readonly bool $receiptRequired = false,
        public readonly ?float $receiptRequiredAbove = null,
        public readonly bool $compensationMissing = false,
    ) {}

    public static function refused(string $reason, ?int $policyVersionId = null, ?int $categoryId = null): self
    {
        return new self(false, $reason, $policyVersionId, $categoryId);
    }

    /** Whether a receipt must be attached to a claim of this amount. */
    public function needsReceipt(float $amount): bool
    {
        return $this->receiptRequired && ($this->receiptRequiredAbove === null || $amount > $this->receiptRequiredAbove);
    }

    /** The most a new claim can take within the category's limit and the policy's overall limit (null = no limit). */
    public function headroom(): ?float
    {
        $caps = array_values(array_filter([$this->remaining, $this->overall['remaining'] ?? null], fn ($v) => $v !== null));

        return $caps === [] ? null : (float) min($caps);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
