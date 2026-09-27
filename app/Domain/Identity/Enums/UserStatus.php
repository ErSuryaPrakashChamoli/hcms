<?php

namespace App\Domain\Identity\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserStatus: string implements HasColor, HasLabel
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Invited => 'info',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }
}
