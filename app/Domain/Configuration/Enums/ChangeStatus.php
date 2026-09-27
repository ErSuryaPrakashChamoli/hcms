<?php

namespace App\Domain\Configuration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ChangeStatus: string implements HasColor, HasLabel
{
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Rejected = 'rejected';
    case RolledBack = 'rolled_back';
    case Discarded = 'discarded';

    public function getLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingApproval => 'warning',
            self::Scheduled => 'info',
            self::Published => 'success',
            self::Rejected, self::Discarded => 'gray',
            self::RolledBack => 'danger',
        };
    }
}
