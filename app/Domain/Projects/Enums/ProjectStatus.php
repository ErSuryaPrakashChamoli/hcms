<?php

namespace App\Domain\Projects\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Data import DI-1: where a project stands. */
enum ProjectStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnHold => 'On hold',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'info',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Completed, self::Cancelled => 'gray',
        };
    }
}
