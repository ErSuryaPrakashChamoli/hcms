<?php

namespace App\Domain\Platform\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TenantStatus: string implements HasColor, HasLabel
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }

    public function allowsAccess(): bool
    {
        return $this !== self::Suspended;
    }
}
