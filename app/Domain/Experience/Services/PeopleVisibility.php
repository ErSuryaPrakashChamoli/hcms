<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * UX: who the viewer may find in search, the directory, pinned people and previews. It grants nothing:
 * - with employee.view, the employee query as it already is (the AccessScope global scope narrows
 *   organisation-scoped users);
 * - otherwise, the viewer's own circle: themselves, their current manager and their direct reports,
 *   the people My HR and My Team already show them.
 * Opening a full profile still needs EmployeePolicy::view; a preview shows only directory fields.
 */
final class PeopleVisibility
{
    /** @var array<int, list<int>> */
    private array $circles = [];

    public function __construct(private readonly RoleLens $lenses) {}

    /** @return Builder<Employee> */
    public function query(User $user): Builder
    {
        if ($user->hasPermission('employee.view')) {
            // The AccessScope global scope follows the authenticated user; constrain explicitly for $user too,
            // so the answer is right whoever is signed in (fail-closed for organisation-scoped viewers).
            $scopes = app(AccessScopes::class);
            if (! $scopes->isScoped($user)) {
                return Employee::query();
            }
            // UX.18: when $user is the signed-in person the global scope would add this very constraint a second time
            // (one set query, applied twice, doubled the directory's database time). Apply it once, explicitly.
            $query = auth()->id() === $user->getKey() ? Employee::query()->withoutGlobalScope(AccessScope::class) : Employee::query();

            return $scopes->constrainEmployees($query, $user);
        }

        return Employee::query()->whereKey($this->circle($user));
    }

    public function canSee(User $user, int $employeeId): bool
    {
        return $this->query($user)->whereKey($employeeId)->exists();
    }

    /** Full Employee 360 (existing policy, organisation scope included). */
    public function canOpenProfile(User $user, Employee $employee): bool
    {
        return $user->can('view', $employee);
    }

    /**
     * Name search that works on every database: each word must start a first, last or preferred name,
     * or match the employee code / work email.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function matchName(Builder $query, string $term): Builder
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($term)) ?: [], fn ($w) => $w !== ''));
        if ($words === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($words, $term) {
            $q->whereHas('person', function (Builder $p) use ($words) {
                foreach ($words as $w) {
                    $p->where(fn (Builder $x) => $x->where('first_name', 'like', $w.'%')->orWhere('last_name', 'like', $w.'%')->orWhere('preferred_name', 'like', $w.'%'));
                }
            })->orWhere('employee_code', 'like', trim($term).'%')->orWhere('work_email', 'like', trim($term).'%');
        });
    }

    /** @return list<int> */
    public function circle(User $user): array
    {
        if (isset($this->circles[$user->id])) {
            return $this->circles[$user->id];
        }
        $me = $this->lenses->employee($user);
        if ($me === null) {
            return $this->circles[$user->id] = [];
        }
        $ids = [$me->id];
        if ($manager = $me->currentManager?->manager_id) {
            $ids[] = (int) $manager;
        }
        foreach ($me->directReports()->currentlyEffective()->pluck('employee_id') as $report) {
            $ids[] = (int) $report;
        }

        return $this->circles[$user->id] = array_values(array_unique($ids));
    }
}
