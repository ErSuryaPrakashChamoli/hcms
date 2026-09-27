<?php

namespace App\Domain\Organisation\Enums;

use Filament\Support\Contracts\HasLabel;

enum LocationType: string implements HasLabel
{
    case Office = 'office';
    case Branch = 'branch';
    case Factory = 'factory';
    case Warehouse = 'warehouse';
    case Site = 'site';
    case Remote = 'remote';
    case Other = 'other';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
