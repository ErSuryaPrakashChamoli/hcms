<?php

namespace App\Domain\Organisation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ActiveStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'gray',
        };
    }
}
