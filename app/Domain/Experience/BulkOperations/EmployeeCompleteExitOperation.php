<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Services\Exits;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\Checkbox;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-11 (§23.2.2 #7, "Initiate final clearance" on the Employees register): Exits::complete on each selected
 * employee's open exit — refused where a stage is still open or the settlement is not approved (unless completing without
 * settlement), exactly as "Complete exit" on the exit case.
 */
final class EmployeeCompleteExitOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.complete_exit';
    }

    public function label(): string
    {
        return 'Complete exit';
    }

    public function description(): string
    {
        return 'Marks each employee as exited on their last working day and disables their login. Employees without an open exit, with clearance stages still open, or without an approved settlement, are refused.';
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
        return 'heroicon-m-check-badge';
    }

    public function color(): string
    {
        return 'success';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return [Checkbox::make('skip_settlement')->label('Complete without an approved settlement (e.g. no dues)')];
    }

    public function prepare(array $data, User $actor): array
    {
        return ['skip_settlement' => (bool) ($data['skip_settlement'] ?? false)];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return EmployeeStartClearanceOperation::openExit($record) === null ? 'No exit is open for this employee.' : null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        $case = EmployeeStartClearanceOperation::openExit($record) ?? $this->refuse('No exit is open for this employee.');
        app(Exits::class)->complete($case, $actor, ! ($input['skip_settlement'] ?? false));

        return "Exit {$case->number} completed";
    }
}
