<?php

namespace App\Domain\Configuration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RiskLevel: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
