<?php

namespace App\Domain\Performance\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne setup parity HP-8a (Performance → Goal → Performance terminology): the organisation's own name for each
 * performance term, from the settings performance.labels.<key>. A blank setting keeps PeopleOS's wording: the term's
 * built-in name, or the wording a screen used before (the fallback a caller passes, e.g. "Key results" for KPIs).
 */
final class PerformanceLabels
{
    /** Built-in names, as HROne lists the terms. */
    public const DEFAULTS = [
        'kra' => 'KRA',
        'kpi' => 'KPI',
        'competency' => 'Competency',
        'perspective' => 'Perspective',
        'uom' => 'Unit',
        'target' => 'Target',
        'feedback' => 'Feedback',
        'achievement' => 'Achievement',
        'kpi_due_date' => 'KPI due date',
    ];

    /** "COPY OKR LABELS": the terms an OKR organisation uses. */
    public const OKR = ['kra' => 'Objective', 'kpi' => 'Key result', 'achievement' => 'Progress'];

    public static function get(string $key, ?string $fallback = null): string
    {
        $custom = self::custom($key);

        return $custom ?? $fallback ?? self::DEFAULTS[$key] ?? $key;
    }

    /** The plural form; with no custom name, the fallback (already plural) or the built-in name made plural. */
    public static function plural(string $key, ?string $fallback = null): string
    {
        $custom = self::custom($key);

        return $custom !== null ? Str::plural($custom) : ($fallback ?? Str::plural(self::DEFAULTS[$key] ?? $key));
    }

    public static function custom(string $key): ?string
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new RuntimeException("Unknown performance term [{$key}].");
        }
        $value = trim((string) app(SettingsRepository::class)->get("performance.labels.{$key}"));

        return $value === '' ? null : $value;
    }

    /** @return array<string, string> goal types (config peopleos.performance.goal_types) with the KRA / KPI terms in use */
    public static function goalTypes(): array
    {
        $types = (array) config('peopleos.performance.goal_types', []);
        foreach (['kra', 'kpi'] as $key) {
            if (isset($types[$key])) {
                $types[$key] = self::get($key, $types[$key]);
            }
        }

        return $types;
    }

    /** @return array<string, string> every term with the name in use */
    public static function all(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn ($default, $key) => [$key => self::get($key)])->all();
    }

    /**
     * Set the OKR names (Objective, Key result, Progress); each change is audited with the reason, like any setting.
     *
     * @return list<string> the setting keys changed
     */
    public static function useOkrTerms(User $actor, string $reason): array
    {
        if (! $actor->can('settings.update')) {
            throw new RuntimeException('You cannot change settings.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give the reason for the change.');
        }
        $settings = app(SettingsRepository::class);
        $changed = [];
        DB::transaction(function () use ($settings, $reason, &$changed) {
            foreach (self::OKR as $key => $label) {
                if (self::custom($key) !== $label) {
                    $settings->set("performance.labels.{$key}", $label, $reason);
                    $changed[] = "performance.labels.{$key}";
                }
            }
        });

        return $changed;
    }
}
