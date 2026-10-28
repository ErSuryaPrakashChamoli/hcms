<?php

namespace App\Domain\Compliance\Support;

use App\Domain\Compliance\Models\ComplianceRule;

/**
 * One gratuity determination: whether the employee's establishment is covered, whether the employee qualifies, and the
 * amount, under the GRATUITY rule version that governs the date. The status says whether it can be relied on; a
 * provisional amount (computed under a version that is proposed, unverified or under notice) is never payable.
 */
final class GratuityDetermination
{
    public const ELIGIBLE = 'eligible';

    public const NOT_ELIGIBLE = 'not_eligible';

    public const NOT_COVERED = 'not_covered';

    public const PENDING = 'pending_determination';

    public const INSUFFICIENT_DATA = 'insufficient_data';

    public const LEGALLY_UNVERIFIED = 'legally_unverified';

    private const LABELS = [
        self::ELIGIBLE => ['Eligible', 'Qualifies'], self::NOT_ELIGIBLE => ['Not eligible', 'Not yet qualified'], self::NOT_COVERED => ['Not covered', 'Not covered'],
        self::PENDING => ['Pending determination', 'Pending determination'], self::INSUFFICIENT_DATA => ['Insufficient data', 'Insufficient data'],
        self::LEGALLY_UNVERIFIED => ['Legally unverified', 'Legally unverified'],
    ];

    /**
     * @param  array{status: string, reason: string, establishment_id?: ?int, headcount_preceding_12_months?: ?int, headcount_max?: ?int, threshold?: ?int}  $coverage
     * @param  list<string>  $reviews  facts a person must decide before the amount is final
     */
    public function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly bool $accrual,
        public readonly ?ComplianceRule $rule = null,
        public readonly bool $decisive = false,
        public readonly array $coverage = ['status' => 'not_checked', 'reason' => 'Coverage is decided per establishment by the report plan.'],
        public readonly ?string $serviceText = null,
        public readonly ?int $yearsCounted = null,
        public readonly ?int $minimumYears = null,
        public readonly bool $fixedTerm = false,
        public readonly ?float $wages = null,
        public readonly ?float $amount = null,
        public readonly bool $capped = false,
        public readonly array $reviews = [],
        public readonly ?string $paymentDue = null,
    ) {}

    public function label(): string
    {
        return self::LABELS[$this->status][$this->accrual ? 1 : 0];
    }

    /** Only a decisive, eligible determination at exit with an amount is paid automatically. */
    public function payable(): bool
    {
        return ! $this->accrual && $this->status === self::ELIGIBLE && $this->decisive && ($this->amount ?? 0) > 0;
    }

    public function ruleLabel(): ?string
    {
        return $this->rule === null ? null : $this->rule->label().' ('.($this->rule->isProposed() ? 'proposed, not in force' : $this->rule->verification_status).')';
    }

    /** @return array<string, mixed> stored on the settlement and on a gratuity line's basis */
    public function toArray(): array
    {
        return [
            'status' => $this->status, 'label' => $this->label(), 'reason' => $this->reason, 'decisive' => $this->decisive,
            'rule_id' => $this->rule?->getKey(), 'rule' => $this->ruleLabel(), 'coverage' => $this->coverage, 'service' => $this->serviceText,
            'years_counted' => $this->yearsCounted, 'minimum_years' => $this->minimumYears, 'fixed_term' => $this->fixedTerm, 'wages' => $this->wages,
            ($this->decisive ? 'amount' : 'provisional_amount') => $this->amount, 'capped' => $this->capped, 'reviews' => $this->reviews, 'payment_due' => $this->paymentDue,
        ];
    }

    /** @return list<string> a stored determination as readable lines */
    public static function describe(?array $stored): array
    {
        if ($stored === null) {
            return ['Not determined: recalculate the settlement.'];
        }
        $amount = $stored['amount'] ?? $stored['provisional_amount'] ?? null;

        return array_values(array_filter([
            'Status: '.($stored['label'] ?? $stored['status']),
            $stored['reason'] ?? null,
            $amount === null ? null : (isset($stored['amount']) ? 'Amount: ' : 'Provisional amount (not paid automatically): ').number_format((float) $amount, 2).(($stored['capped'] ?? false) ? ' (at the ceiling)' : ''),
            isset($stored['coverage']['reason']) ? 'Coverage: '.$stored['coverage']['reason'] : null,
            isset($stored['rule']) ? 'Rule: '.$stored['rule'] : null,
            ...array_map(fn (string $r) => 'Review: '.$r, (array) ($stored['reviews'] ?? [])),
            isset($stored['payment_due']) ? 'Payment: '.$stored['payment_due'] : null,
        ]));
    }
}
