<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-11 (§23.2.2 #6, "Initiate clearance" on the Employees register): Exits::startClearance on each selected
 * employee's open exit still serving its notice. Employees without such an exit are refused.
 */
final class EmployeeStartClearanceOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.start_clearance';
    }

    public function label(): string
    {
        return 'Start clearance';
    }

    public function description(): string
    {
        return 'Starts the clearance stages of each employee\'s exit that is serving its notice, and tells the stage owners. Employees without an exit serving notice are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'exit.manage';
    }

    public function icon(): string
    {
        return 'heroicon-m-clipboard-document-check';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        $case = self::openExit($record);
        if ($case === null) {
            return 'No exit is open for this employee.';
        }

        return in_array($case->status, ['initiated', 'notice'], true) ? null : 'Clearance starts only on an exit serving its notice (this one is '.config('peopleos.exit.statuses.'.$case->status, $case->status).').';
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        $case = self::openExit($record) ?? $this->refuse('No exit is open for this employee.');
        app(Exits::class)->startClearance($case, $actor);

        return "Clearance started for {$case->number}; owners notified";
    }

    public static function openExit(Model $employee): ?ExitCase
    {
        return ExitCase::query()->where('employee_id', $employee->getKey())->whereIn('status', ExitCase::OPEN)->latest('id')->first();
    }
}
