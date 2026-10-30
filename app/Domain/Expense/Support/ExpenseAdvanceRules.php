<?php

namespace App\Domain\Expense\Support;

use App\Domain\Configuration\Models\PolicyVersion;

/**
 * An employee's expense advance policy on a date (policy type `expense_advance`), with HROne's defaults for unanswered
 * questions: one open advance, settled within 30 days, recovered through payroll 15 days after the settle-by date, paid
 * by bank, cancellation of an approved advance needs approval. No policy: no advance requests (HROne's empty state).
 * Advances themselves arrive with EX-D, which checks requests against these rules.
 */
final class ExpenseAdvanceRules
{
    public const NO_POLICY = 'Expense advance policy needs to be configured to enable employee to request advance for work related expenses.';

    public function __construct(
        public readonly ?int $policyVersionId,
        public readonly bool $enabled,
        public readonly ?float $maxAmount,
        public readonly int $maxOpen,
        public readonly ?float $maxOutstandingTotal,
        public readonly bool $purposeRequired,
        public readonly bool $attachmentRequired,
        public readonly bool $cancellationNeedsApproval,
        public readonly string $disbursement,
        public readonly int $settleWithinDays,
        public readonly string $recovery,
        public readonly int $recoveryAfterDays,
    ) {}

    public static function from(?PolicyVersion $version): self
    {
        $setting = fn (string $key, mixed $default = null) => $version?->setting($key, $default) ?? $default;
        $amount = fn (string $key) => is_numeric($setting($key)) && (float) $setting($key) > 0 ? round((float) $setting($key), 2) : null;
        $whole = fn (string $key, int $default, int $min) => is_numeric($setting($key)) ? max($min, (int) $setting($key)) : $default;

        return new self(
            policyVersionId: $version?->id,
            enabled: $version !== null && Answers::yes($setting('enabled')),
            maxAmount: $amount('max_amount'),
            maxOpen: $whole('max_open', 1, 1),
            maxOutstandingTotal: $amount('max_outstanding_total'),
            purposeRequired: Answers::yes($setting('purpose_required')),
            attachmentRequired: Answers::yes($setting('attachment_required')),
            cancellationNeedsApproval: ! Answers::no($setting('cancellation_needs_approval')),
            disbursement: in_array($setting('disbursement'), ['bank', 'payroll'], true) ? $setting('disbursement') : 'bank',
            settleWithinDays: $whole('settle_within_days', 30, 0),
            recovery: in_array($setting('recovery'), ['payroll', 'exit_only', 'none'], true) ? $setting('recovery') : 'payroll',
            recoveryAfterDays: $whole('recovery_after_days', 15, 0),
        );
    }

    /** Why the employee cannot request an advance, or null when they can. */
    public function refusal(): ?string
    {
        return match (true) {
            $this->policyVersionId === null => self::NO_POLICY,
            ! $this->enabled => 'Your expense advance policy does not allow advance requests.',
            default => null,
        };
    }

    /** Why an advance of this amount is refused, or null (open advances and outstanding totals are EX-D's to count). */
    public function refusalFor(float $amount): ?string
    {
        if (($refusal = $this->refusal()) !== null) {
            return $refusal;
        }
        if ($amount <= 0) {
            return 'An advance must be more than 0.';
        }

        return $this->maxAmount !== null && $amount > $this->maxAmount ? 'Your expense advance policy allows at most '.number_format($this->maxAmount, 2).' per advance.' : null;
    }
}
