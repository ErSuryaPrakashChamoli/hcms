<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\Platform\Services\SettingsRepository;

/**
 * HROne setup parity CA-6 (Core HR → Employee → Profile access): which sections of a person's profile each audience sees.
 *
 * The tenant setting `profile.section_visibility` is a matrix of profile sections (peopleos.profile.sections) × audiences:
 * - employee: the person themselves (their own Employee 360, My profile, My HR documents);
 * - manager: someone who manages them (the configured manager relationship types, as Employee360 uses);
 * - others: anyone else who may open the profile.
 *
 * It is an extra AND on top of every existing rule: it can only hide a section, never show what a permission, relationship
 * or policy does not already allow (callers keep their own checks and add this one). People who maintain employee records
 * (`employee.update` or `employee.sensitive.update`) are not an audience: HR always sees the full record. An unknown
 * section or audience is refused (fail-closed); a section missing from the stored matrix takes the platform default
 * (every box ticked = as before). Reads only.
 */
final class ProfileSectionVisibility
{
    public const AUDIENCES = ['employee', 'manager', 'others'];

    /** @var array<string, bool> viewer:employee => manages (per request; the service is request-scoped) */
    private array $manages = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PerformanceRelationships $relationships,
    ) {}

    /** @return array<string, string> section key => label */
    public static function sections(): array
    {
        return (array) config('peopleos.profile.sections', []);
    }

    /** The viewer's audience for this employee's profile; null for people who maintain employee records (not governed). */
    public function audience(User $viewer, Employee $employee): ?string
    {
        if ($viewer->hasPermission('employee.update') || $viewer->hasPermission('employee.sensitive.update')) {
            return null;
        }
        if ($employee->getAttribute('user_id') !== null && (int) $employee->getAttribute('user_id') === (int) $viewer->getKey()) {
            return 'employee';
        }
        $key = $viewer->getKey().':'.$employee->getKey();
        $this->manages[$key] ??= $this->relationships->manages($this->relationships->forUser($viewer), $employee->getKey());

        return $this->manages[$key] ? 'manager' : 'others';
    }

    /** May this viewer see this section of this employee's profile, as far as the matrix goes? */
    public function allows(?User $viewer, Employee $employee, string $section): bool
    {
        if ($viewer === null || ! array_key_exists($section, self::sections())) {
            return false;
        }
        // Nothing hidden from anyone in this section (the default): no need to work out who the viewer is.
        if (count(array_filter(self::AUDIENCES, fn (string $audience) => $this->allowsAudience($audience, $section))) === count(self::AUDIENCES)) {
            return true;
        }
        $audience = $this->audience($viewer, $employee);

        return $audience === null || $this->allowsAudience($audience, $section);
    }

    /** The matrix cell itself (My profile asks for the employee audience directly). */
    public function allowsAudience(string $audience, string $section): bool
    {
        if (! in_array($audience, self::AUDIENCES, true) || ! array_key_exists($section, self::sections())) {
            return false;
        }
        $stored = (array) $this->settings->get('profile.section_visibility', []);
        $row = (array) ($stored[$section] ?? []);
        if (! array_key_exists($audience, $row)) {
            $defaults = (array) (config('peopleos.settings')['profile.section_visibility'] ?? []);
            $row = (array) ($defaults[$section] ?? []);
        }

        return (bool) ($row[$audience] ?? true);
    }

    /** @return list<string> the sections this audience sees */
    public function visibleTo(string $audience): array
    {
        return array_values(array_filter(array_keys(self::sections()), fn (string $section) => $this->allowsAudience($audience, $section)));
    }
}
