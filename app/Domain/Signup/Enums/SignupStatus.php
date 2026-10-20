<?php

namespace App\Domain\Signup\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * SaaS.10: a self-service signup application's state. Changed only by the Signup services, each change a conditional
 * update from an allowed state (TRANSITIONS) and audited. rejected, expired, cancelled and active are terminal: a new
 * attempt is a new application.
 */
enum SignupStatus: string implements HasColor, HasLabel
{
    case EmailPending = 'email_pending';
    case DetailsPending = 'details_pending';
    case ReviewPending = 'review_pending';
    case Approved = 'approved';
    case Provisioning = 'provisioning';
    case ActivationPending = 'activation_pending';
    case Active = 'active';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case FailedRetryable = 'failed_retryable';
    case FailedManualReview = 'failed_manual_review';

    /** @var array<string, list<string>> from => allowed targets */
    public const TRANSITIONS = [
        'email_pending' => ['details_pending', 'expired', 'cancelled'],
        'details_pending' => ['review_pending', 'approved', 'expired', 'cancelled'],
        'review_pending' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['provisioning', 'cancelled'],
        'provisioning' => ['activation_pending', 'failed_retryable', 'failed_manual_review'],
        'failed_retryable' => ['provisioning', 'failed_manual_review', 'cancelled'],
        'failed_manual_review' => ['provisioning', 'rejected', 'cancelled'],
        'activation_pending' => ['active'],
        'active' => [],
        'rejected' => [],
        'expired' => [],
        'cancelled' => [],
    ];

    /** States in which the application is still open: one per e-mail address (unique generated column). */
    public const OPEN = ['email_pending', 'details_pending', 'review_pending', 'approved', 'provisioning', 'failed_retryable', 'failed_manual_review'];

    public function canBecome(self $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    /** @return list<string> the states from which $to may be reached */
    public static function sourcesOf(self $to): array
    {
        return array_keys(array_filter(self::TRANSITIONS, fn (array $targets) => in_array($to->value, $targets, true)));
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::OPEN, true);
    }

    public function isTerminal(): bool
    {
        return self::TRANSITIONS[$this->value] === [];
    }

    /** Before provisioning starts: the applicant or an operator may still withdraw it. */
    public function cancellable(): bool
    {
        return $this->canBecome(self::Cancelled);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::EmailPending => 'Confirming e-mail',
            self::DetailsPending => 'Completing details',
            self::ReviewPending => 'In review',
            self::Approved => 'Approved',
            self::Provisioning => 'Setting up',
            self::ActivationPending => 'Waiting for activation',
            self::Active => 'Active',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::FailedRetryable => 'Set-up failed (retrying)',
            self::FailedManualReview => 'Set-up needs attention',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::ActivationPending, self::Approved, self::Provisioning => 'info',
            self::ReviewPending, self::DetailsPending, self::EmailPending => 'warning',
            self::FailedRetryable, self::FailedManualReview, self::Rejected => 'danger',
            self::Expired, self::Cancelled => 'gray',
        };
    }
}
