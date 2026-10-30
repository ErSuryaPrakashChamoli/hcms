<?php

namespace App\Domain\Workflow\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    // HROne setup parity P3: the approver sent the run back to its initiator instead of deciding.
    case SentBack = 'sent_back';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::SentBack => 'Sent back',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::SentBack => 'warning',
            self::Approved, self::Completed => 'success',
            self::Rejected => 'danger',
            self::Skipped, self::Cancelled => 'gray',
        };
    }
}
