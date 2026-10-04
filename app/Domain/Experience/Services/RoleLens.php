<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/**
 * UX: the experience lenses a person works through. A lens changes the information hierarchy,
 * primary actions, home content and navigation. It is derived from existing permissions and
 * relationships and grants nothing: every surface still enforces tenant, permission, organisation
 * scope, relationship scope and field security on its own.
 */
final class RoleLens
{
    public const EMPLOYEE = 'employee';

    public const MANAGER = 'manager';

    public const HR = 'hr';

    public const HR_ADMIN = 'hr_admin';

    public const PAYROLL = 'payroll';

    public const EXECUTIVE = 'executive';

    public const SYSTEM_ADMIN = 'system_admin';

    public const ALL = [self::EMPLOYEE, self::MANAGER, self::HR, self::HR_ADMIN, self::PAYROLL, self::EXECUTIVE, self::SYSTEM_ADMIN];

    public const LABELS = [self::EMPLOYEE => 'Employee', self::MANAGER => 'Manager', self::HR => 'HR', self::HR_ADMIN => 'HR Admin', self::PAYROLL => 'Payroll',
        self::EXECUTIVE => 'Executive', self::SYSTEM_ADMIN => 'Admin'];

    /**
     * UX.16: the experiences people see. HR admin and system admin are one Administration experience; payroll is
     * the payroll side of HR operations. A lens still decides nothing about access.
     */
    public const EXPERIENCE = [self::EMPLOYEE => 'employee', self::MANAGER => 'manager', self::HR => 'hr', self::PAYROLL => 'payroll',
        self::EXECUTIVE => 'executive', self::HR_ADMIN => 'admin', self::SYSTEM_ADMIN => 'admin'];

    /** One label set for the experiences, used by Home ("View as") and Preferences ("Home opens as"). */
    public const EXPERIENCE_LABELS = ['employee' => 'For you', 'manager' => 'Your team', 'hr' => 'People operations', 'payroll' => 'Payroll',
        'executive' => 'Company', 'admin' => 'Administration'];

    /**
     * UX.16: Home opens on the most specific responsibility a person holds: administration, then HR operations,
     * payroll, executive, manager and employee. (Before UX.16 executive came first, so tenant HR admins, whose role
     * includes analytics, opened in the executive view.) A stored "Home opens as" choice always wins.
     */
    private const PRIORITY = [self::HR_ADMIN, self::SYSTEM_ADMIN, self::HR, self::PAYROLL, self::EXECUTIVE, self::MANAGER, self::EMPLOYEE];

    /** @var array<int, array{lenses: list<string>, employee: ?Employee}> */
    private array $cache = [];

    /** @return list<string> */
    public function lenses(User $user): array
    {
        return $this->resolve($user)['lenses'];
    }

    public function employee(User $user): ?Employee
    {
        return $this->resolve($user)['employee'];
    }

    public function primary(User $user, ?string $preferred = null): string
    {
        $lenses = $this->lenses($user);
        if ($preferred !== null && in_array($preferred, $lenses, true)) {
            return $preferred;
        }
        foreach (self::PRIORITY as $lens) {
            if (in_array($lens, $lenses, true)) {
                return $lens;
            }
        }

        return self::EMPLOYEE;
    }

    public function has(User $user, string $lens): bool
    {
        return in_array($lens, $this->lenses($user), true);
    }

    public static function experienceOf(string $lens): string
    {
        return self::EXPERIENCE[$lens] ?? 'employee';
    }

    /**
     * The experiences a person can switch between, each with the lens that opens it (HR admin before system admin
     * for Administration), in the order of their lenses. Duplicates collapse: HR admin + system admin is one entry.
     *
     * @return array<string, string> experience => lens
     */
    public function experiences(User $user): array
    {
        $out = [];
        foreach ($this->lenses($user) as $lens) {
            $out[self::experienceOf($lens)] ??= $lens;
        }

        return $out;
    }

    /** @return array{lenses: list<string>, employee: ?Employee} */
    private function resolve(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }
        $employee = $user->tenant_id ? Employee::query()->with('person')->where('user_id', $user->id)->first() : null;
        $lenses = [];
        if ($employee !== null) {
            $lenses[] = self::EMPLOYEE;
            if ($employee->directReports()->currentlyEffective()->exists()) {
                $lenses[] = self::MANAGER;
            }
        }
        if ($user->hasPermission('employee.update') || $user->hasPermission('servicedesk.agent') || $user->hasPermission('onboarding.manage')) {
            $lenses[] = self::HR;
        }
        if ($user->hasPermission('configuration.publish') || $user->hasPermission('employee.create') && $user->hasPermission('policy.update')) {
            $lenses[] = self::HR_ADMIN;
        }
        if ($user->hasPermission('payroll.calculate') || $user->hasPermission('payroll.approve')) {
            $lenses[] = self::PAYROLL;
        }
        if ($user->hasPermission('analytics.executive')) {
            $lenses[] = self::EXECUTIVE;
        }
        if ($user->isPlatformAdmin() || $user->hasPermission('user.assign_roles') || $user->hasPermission('settings.update')) {
            $lenses[] = self::SYSTEM_ADMIN;
        }

        return $this->cache[$user->id] = ['lenses' => array_values(array_unique($lenses)) ?: [self::EMPLOYEE], 'employee' => $employee];
    }
}
