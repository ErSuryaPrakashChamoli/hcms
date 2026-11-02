<?php

namespace App\Domain\Identity\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * HROne parity WO-15: what kind of sign-in an account is. Every account is `standard` unless BusinessPartners::invite
 * created it for a business partner. The kind grants nothing: access comes only from roles.
 */
enum UserKind: string implements HasColor, HasLabel
{
    case Standard = 'standard';
    case Partner = 'partner';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Organisation user',
            self::Partner => 'Business partner',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Standard => 'gray',
            self::Partner => 'warning',
        };
    }
}
