<?php

namespace App\Domain\ProductFeedback\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * HROne parity AC-3: where Markedge's product team has an organisation's idea. Submitted by the organisation; every later
 * state is set by a Markedge operator (ProductIdeas::changeStatus), who may also move an idea back (a released idea
 * reopened, a declined idea taken up again). Released and declined are where an idea usually ends.
 */
enum ProductIdeaStatus: string implements HasColor, HasLabel
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Released = 'released';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Released => 'Released',
            self::Declined => 'Declined',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::UnderReview => 'warning',
            self::Planned => 'info',
            self::InProgress => 'primary',
            self::Released => 'success',
            self::Declined => 'danger',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Released || $this === self::Declined;
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->getLabel();
        }

        return $out;
    }
}
