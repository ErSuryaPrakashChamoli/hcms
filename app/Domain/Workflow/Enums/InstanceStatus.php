<?php

namespace App\Domain\Workflow\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InstanceStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Waiting => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Running, self::Waiting], true);
    }
}
