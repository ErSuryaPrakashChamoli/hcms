<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Expense\Policies\ExpensePolicy;
use App\Domain\Identity\Services\OwnRecords;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * HROne parity XO-5 (matrix 26 §26.7 #1, #2): the employee picker of "Request an advance for an employee" and "New report
 * for an employee" — the employees in the signed-in person's access scope (the scoped Employee query), never their own,
 * and the check the actions make before filing (`ExpensePolicy::onBehalf`: `expense.approve` in scope, not one's own).
 * The services decide the rest exactly as for the employee (policy, limits, workflow).
 */
final class ExpenseOnBehalf
{
    /** Who may file for others here: `expense.approve` (the existing on-behalf right). */
    public static function offered(): bool
    {
        return (bool) auth()->user()?->can('expense.approve');
    }

    /** The employees the signed-in person may pick: in their access scope, not their own. */
    public static function candidates(): Builder
    {
        $me = auth()->id();

        return Employee::query()->with('person')->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $me));
    }

    public static function employeeSelect(): Select
    {
        return Select::make('employee_id')->label('Employee')->required()->searchable()->live()
            ->helperText('Employees in your access scope. For yourself, use your own request instead.')
            ->getSearchResultsUsing(fn (string $search) => self::candidates()->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))
                ->orderBy('employee_code')->limit(20)->get()->mapWithKeys(fn (Employee $e) => [$e->getKey() => $e->auditLabel()])->all())
            ->getOptionLabelUsing(fn ($value) => self::picked($value)?->auditLabel());
    }

    /** The picked employee within the access scope (null when not picked or out of scope). */
    public static function picked(mixed $id): ?Employee
    {
        return is_numeric($id) ? Employee::query()->with('person')->find((int) $id) : null;
    }

    /** The employee to file for, or why not: out of scope, one's own (the self path), or no right to file for them. */
    public static function resolve(mixed $id, string $ownAction): Employee
    {
        $user = auth()->user();
        $employee = self::picked($id);
        if ($user === null || $employee === null) {
            throw new RuntimeException('Choose an employee in your access scope.');
        }
        if (app(OwnRecords::class)->isOwn($user, $employee->getKey())) {
            throw new RuntimeException("This is your own record: use \"{$ownAction}\".");
        }
        if (! app(ExpensePolicy::class)->onBehalf($user, $employee)) {
            throw new RuntimeException('You cannot file for this employee.');
        }

        return $employee;
    }
}
