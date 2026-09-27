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
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved, self::Completed => 'success',
            self::Rejected => 'danger',
            self::Skipped, self::Cancelled => 'gray',
        };
    }
}
