<?php

namespace App\Domain\Tax\Enums;

/** SaaS.7: B2B or B2C, always explicit on a billing profile; never inferred from a tax number. */
enum CustomerType: string
{
    case Business = 'business';
    case Consumer = 'consumer';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business (B2B)',
            self::Consumer => 'Consumer (B2C)',
        };
    }
}
