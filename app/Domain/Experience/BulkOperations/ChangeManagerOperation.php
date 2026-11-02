<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\BulkManagerChanges;
use App\Domain\Identity\Models\User;
use App\Filament\Support\WorkforceBulkWizards;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-16 (§23.9 #4–5): move the chosen employees to one new manager from one date, each through
 * ChangeManagerAction (BulkManagerChanges); optionally their open approvals that wait on the old manager as their manager.
 */
final class ChangeManagerOperation extends BaseBulkOperation
{
    public function key(): string
    {
        return 'employees.change_manager';
    }

    public function label(): string
    {
        return 'Change manager';
    }

    public function description(): string
    {
        return 'Each employee gets the new manager from the effective date; the current reporting line is closed the day before and kept as history. People who would be their own manager, a loop, a new manager who has left, or a date that would rewrite history are refused with the reason.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'employee.position';
    }

    public function icon(): string
    {
        return 'heroicon-m-user-group';
    }

    public function color(): string
    {
        return 'gray';
    }

    public function with(): array
    {
        return ['person'];
    }

    public function schema(): array
    {
        return WorkforceBulkWizards::managerChangeFields();
    }

    public function prepare(array $data, User $actor): array
    {
        return app(BulkManagerChanges::class)->prepare($data, $actor);
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        return app(BulkManagerChanges::class)->change($record, $input, $actor);
    }
}
