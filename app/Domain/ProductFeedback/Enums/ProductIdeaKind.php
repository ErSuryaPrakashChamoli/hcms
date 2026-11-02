<?php

namespace App\Domain\ProductFeedback\Enums;

use Filament\Support\Contracts\HasLabel;

/** HROne parity AC-3: HROne's "We don't have this feature" vs an improvement of something PeopleOS already does. */
enum ProductIdeaKind: string implements HasLabel
{
    case NewFeature = 'new_feature';
    case Improvement = 'improvement';

    public function getLabel(): string
    {
        return match ($this) {
            self::NewFeature => 'New feature',
            self::Improvement => 'Improvement',
        };
    }

    /** How the form asks it. */
    public function question(): string
    {
        return match ($this) {
            self::NewFeature => 'PeopleOS does not have this feature',
            self::Improvement => 'Improve something PeopleOS already does',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return [self::NewFeature->value => self::NewFeature->getLabel(), self::Improvement->value => self::Improvement->getLabel()];
    }
}
