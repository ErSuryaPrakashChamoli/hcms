<?php

namespace App\Domain\Skills\Support;

use App\Domain\Platform\Services\SettingsRepository;
use App\Domain\Skills\Models\SkillScale;
use App\Domain\Skills\Models\SkillScaleVersion;

/**
 * HROne setup parity CA-3 (Employee → Skill proficiency): the proficiency levels of profile skills and career path
 * steps. Without a choice (skills.profile_scale = 0) they are PeopleOS's Beginner … Expert list, as before; with a skill
 * scale chosen they are that scale's current levels (code = the level's value). Levels compare by rank: the built-in
 * order, or the scale value; a built-in code recorded before a scale was chosen keeps its built-in rank.
 */
final class SkillProficiencies
{
    public const BUILT_IN_RANK = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3, 'expert' => 4];

    /** @return array<string, string> code => label */
    public static function options(): array
    {
        $version = self::version();
        if ($version === null) {
            return (array) config('peopleos.people.skill_proficiencies', []);
        }

        return collect($version->levels ?? [])->mapWithKeys(fn (array $level) => [self::code((float) $level['value']) => (string) $level['label']])->all();
    }

    /** The default level offered in forms: the built-in "intermediate", or the scale's lowest level. */
    public static function default(): ?string
    {
        return self::version() === null ? 'intermediate' : (array_key_first(self::options()) ?: null);
    }

    public static function rank(?string $code): float
    {
        if ($code === null || $code === '') {
            return 0.0;
        }
        if (isset(self::BUILT_IN_RANK[$code])) {
            return (float) self::BUILT_IN_RANK[$code];
        }

        return is_numeric($code) ? (float) $code : 0.0;
    }

    /** An expert-level proficiency: in the upper half of the levels in use. */
    public static function isExpert(?string $code): bool
    {
        if (isset(self::BUILT_IN_RANK[(string) $code])) {
            return self::BUILT_IN_RANK[$code] > max(self::BUILT_IN_RANK) / 2;
        }
        $top = self::version()?->max();

        return $top !== null && self::rank($code) > $top / 2;
    }

    public static function label(?string $code): ?string
    {
        return $code === null ? null : (self::options()[$code] ?? config("peopleos.people.skill_proficiencies.{$code}", $code));
    }

    private static function version(): ?SkillScaleVersion
    {
        $id = (int) app(SettingsRepository::class)->get('skills.profile_scale', 0);
        if ($id <= 0) {
            return null;
        }
        $scale = SkillScale::query()->with('currentVersion')->find($id);

        return $scale?->status === 'active' ? $scale->currentVersion : null;
    }

    private static function code(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
