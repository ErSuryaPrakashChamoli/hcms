<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Services\EmployeeHolidayChanges;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne parity TX-12 (matrix 24 §24.7.2 #6, global action "Change holiday" with its bulk-process popup): the same holiday
 * change — assign, remove or replace — for each selected employee, through EmployeeHolidayChanges::apply (refusals per
 * employee: access scope, own holidays, not employed on the day, back-dating limit, locked or closed-month days, a day
 * already changed, no holiday to remove, a holiday already on the day). attendance.holiday_change (attendance.manage also
 * grants it); the Holiday changes page starts it for selections larger than a direct run takes.
 */
final class ChangeHolidayOperation extends BaseBulkOperation
{
    public const KEY = 'attendance.holiday_change';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Change holiday';
    }

    public function description(): string
    {
        return 'Assigns, removes or replaces a holiday for each selected employee on top of their holiday calendar, with this reason; days already processed are recalculated. Employees outside your access and your own holidays are refused; so are days not employed, days before the back-dating limit, locked days and days of a closed attendance month, and days already changed.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'attendance.holiday_change';
    }

    public function icon(): string
    {
        return 'heroicon-m-sun';
    }

    public function prepare(array $data, User $actor): array
    {
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the change.');
        }

        return app(EmployeeHolidayChanges::class)->prepare($data) + ['reason' => mb_substr($reason, 0, 500)];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        /** @var Employee $record */
        return $record->user_id !== null && (int) $record->user_id === (int) $actor->id ? 'You cannot change your own holidays.' : null;
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $result = app(EmployeeHolidayChanges::class)->apply([$record], $input, (string) ($input['reason'] ?? ''), $actor);
        if ($result['done'] === []) {
            throw new RuntimeException($result['refused'][0]['reason'] ?? 'Nothing was changed.');
        }

        return mb_substr($result['done'][0]['message'], 0, 500);
    }
}
