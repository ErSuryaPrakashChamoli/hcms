<?php

namespace App\Domain\Organisation\Support;

use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Str;

/**
 * Data import DI-1 (decision D5): what the organisation calls its own dimension (Setup → Core HR, setting
 * organisation.dimension_label: "Vertical", "Practice", "Programme" …). Blank keeps PeopleOS's word, "Dimension".
 */
final class DimensionLabel
{
    public const SETTING = 'organisation.dimension_label';

    /** "Vertical", or "Dimension" when the organisation has not named it. */
    public static function name(): string
    {
        $label = trim((string) app(SettingsRepository::class)->get(self::SETTING));

        return $label === '' ? 'Dimension' : Str::ucfirst($label);
    }

    /** The name of one value: "vertical", or "dimension value". */
    public static function valueLabel(): string
    {
        $label = trim((string) app(SettingsRepository::class)->get(self::SETTING));

        return $label === '' ? 'dimension value' : mb_strtolower($label);
    }
}
