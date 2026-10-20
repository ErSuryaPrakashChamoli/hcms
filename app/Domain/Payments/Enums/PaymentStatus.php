<?php

namespace App\Domain\Payments\Enums;

/** SaaS.7: a payment only moves forward: initiated → pending → succeeded | failed | cancelled (final states never change). */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Initiated => $next !== self::Initiated,
            self::Pending => $next->isFinal(),
            default => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Initiated, self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
