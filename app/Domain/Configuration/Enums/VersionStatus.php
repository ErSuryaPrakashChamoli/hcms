<?php

namespace App\Domain\Configuration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VersionStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Retired => 'danger',
        };
    }
}
