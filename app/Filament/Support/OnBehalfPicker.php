<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\OwnRecords;
use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * HROne parity AC-2 (HR Desk "Request on behalf"): the employee picker of a module's "… for an employee" form — the
 * employees in the signed-in person's access scope (the scoped Employee query, narrowed by the module's data-access
 * groups, CA-9), never their own — and the check the form makes before filing: the module's on-behalf permission, in
 * scope, not one's own (that is the employee's own form). The module's service then decides everything else exactly as
 * for the employee (windows, limits, approvals). Expense keeps its own `ExpenseOnBehalf` (XO-5), which this mirrors.
 */
final class OnBehalfPicker
{
    /** Whether the signed-in person holds the module's on-behalf permission. */
    public static function offered(string $permission): bool
    {
        return (bool) auth()->user()?->hasPermission($permission);
    }

    /**
     * The employees the signed-in person may pick: in their access scope for the module, not their own.
     *
     * @param  (Closure(): Builder)|null  $base  a narrower starting query (e.g. employed people only)
     */
    public static function candidates(string $module, ?Closure $base = null): Builder
    {
        $user = auth()->user();
        $query = ($base !== null ? $base() : Employee::query())->with('person');
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        $query->where(fn (Builder $q) => $q->whereNull('employees.user_id')->orWhere('employees.user_id', '!=', $user->getKey()));

        return app(AccessScopes::class)->constrainEmployees($query, $user, $module);
    }

    /** @param  (Closure(): Builder)|null  $base */
    public static function select(string $module, ?Closure $base = null, string $name = 'employee_id'): Select
    {
        return Select::make($name)->label('Employee')->required()->searchable()->live()
            ->helperText('Employees in your access scope. For yourself, use your own request instead.')
            ->getSearchResultsUsing(fn (string $search) => self::candidates($module, $base)->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))
                ->orderBy('employee_code')->limit(20)->get()->mapWithKeys(fn (Employee $e) => [$e->getKey() => $e->auditLabel()])->all())
            // Any employee in the access scope has a label (one's own too, so the form can say to use one's own request).
            ->getOptionLabelUsing(fn ($value) => is_numeric($value) ? ($base !== null ? $base() : Employee::query())->with('person')->find((int) $value)?->auditLabel() : null);
    }

    /**
     * The picked employee when the signed-in person may pick them (null when not picked, out of scope or their own).
     *
     * @param  (Closure(): Builder)|null  $base
     */
    public static function picked(mixed $id, string $module, ?Closure $base = null): ?Employee
    {
        return is_numeric($id) ? self::candidates($module, $base)->find((int) $id) : null;
    }

    /**
     * The employee to file for, or why not: no right to file for others, one's own (the self path), out of scope.
     *
     * @param  (Closure(): Builder)|null  $base
     */
    public static function resolve(mixed $id, string $permission, string $module, string $ownAction, ?Closure $base = null): Employee
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasPermission($permission)) {
            throw new RuntimeException('You cannot file this for other employees.');
        }
        if (is_numeric($id) && app(OwnRecords::class)->isOwn($user, (int) $id)) {
            throw new RuntimeException("This is your own record: use \"{$ownAction}\".");
        }
        $employee = self::picked($id, $module, $base);
        if ($employee === null) {
            throw new RuntimeException('Choose an employee in your access scope.');
        }

        return $employee;
    }
}
