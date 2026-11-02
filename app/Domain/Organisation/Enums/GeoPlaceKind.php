<?php

namespace App\Domain\Organisation\Enums;

use Filament\Support\Contracts\HasLabel;

/** Data import DI-1: the kinds of place in the organisation's own list (geo_places). */
enum GeoPlaceKind: string implements HasLabel
{
    case State = 'state';
    case District = 'district';
    case City = 'city';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
