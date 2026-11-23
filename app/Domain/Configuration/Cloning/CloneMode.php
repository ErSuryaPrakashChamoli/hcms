<?php

namespace App\Domain\Configuration\Cloning;

/**
 * Cloning modes 1–3 (phase 1). A variation stays inside a company the source already applies to and needs
 * configuration.clone; another company, or several at once, needs configuration.clone.cross_company as well.
 */
enum CloneMode: string
{
    case Variation = 'variation';
    case Company = 'company';
    case Companies = 'companies';

    public function label(): string
    {
        return match ($this) {
            self::Variation => 'A variation in the same company',
            self::Company => 'Another company',
            self::Companies => 'Several companies',
        };
    }

    public function crossCompany(): bool
    {
        return $this !== self::Variation;
    }
}
