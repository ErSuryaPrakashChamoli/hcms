<?php

namespace App\Domain\Performance\Support;

use App\Domain\Performance\Models\RatingScale;
use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne setup parity HP-8b (Performance → Settings): how final ratings are given and published.
 * - performance.rating.final_decimals: 0 = a scale level is chosen (as before); 1–2 = a number with that many decimals.
 * - performance.rating.rounding: round (as before) / floor / ceil — the default offered on calibrate and finalize, and
 *   the level whose label a rating takes when the scale has no ranges (RatingScale::labelFor).
 * - performance.rating_publish: auto (the employee sees the rating when it is finalized, as before) / manual (HR
 *   publishes the ratings; Appraisals::publish).
 */
final class RatingRules
{
    public static function decimals(): int
    {
        return max(0, min(2, (int) app(SettingsRepository::class)->get('performance.rating.final_decimals', 0)));
    }

    public static function rounding(): string
    {
        $mode = (string) app(SettingsRepository::class)->get('performance.rating.rounding', 'round');

        return in_array($mode, ['round', 'floor', 'ceil'], true) ? $mode : 'round';
    }

    public static function manualPublish(): bool
    {
        return app(SettingsRepository::class)->get('performance.rating_publish', 'auto') === 'manual';
    }

    /** A rating rounded to the configured decimals by the configured rule. */
    public static function round(float $value, ?int $decimals = null): float
    {
        $decimals ??= self::decimals();
        $factor = 10 ** $decimals;

        return match (self::rounding()) {
            'floor' => floor($value * $factor + 1e-9) / $factor,
            'ceil' => ceil($value * $factor - 1e-9) / $factor,
            default => round($value, $decimals),
        };
    }

    public static function label(RatingScale $scale, ?float $rating): ?string
    {
        return $scale->labelFor($rating, self::rounding());
    }

    /**
     * The value calibrate / finalize start from. With levels: round() of the rating as before, or the level at or
     * below (floor) / at or above (ceil); with decimals: the rating rounded to them.
     */
    public static function defaultFor(RatingScale $scale, ?float $rating): ?string
    {
        if ($rating === null) {
            return null;
        }
        if (self::decimals() > 0) {
            return (string) self::round($rating);
        }
        $levels = collect($scale->levels)->pluck('value')->map(fn ($v) => (float) $v)->sort()->values();
        $value = match (self::rounding()) {
            'floor' => $levels->filter(fn ($v) => $v <= $rating + 1e-9)->last() ?? $levels->first(),
            'ceil' => $levels->first(fn ($v) => $v >= $rating - 1e-9) ?? $levels->last(),
            default => round($rating),
        };

        return $value === null ? null : (string) $value;
    }
}
