<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Services\ShiftRoster;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * HROne parity TX-5 (matrix 24 §24.8 #8, global action "Change shift" → "Assign shift"; §24.2.1 #5, #7, #9): the same
 * roster for each selected employee — a shift for a date range, the weekly-off weekdays of the range and, if chosen,
 * automatic shift correction on the rostered days — through ShiftRoster::assignPattern, which refuses an employee outside
 * the access scope, not employed on any day of the range, or with a locked day or a day of a closed attendance month in
 * it. attendance.roster (TX-2; attendance.manage still grants it); the Roster board starts it for selections larger than
 * a direct run takes.
 */
final class AssignShiftOperation extends BaseBulkOperation
{
    public const KEY = 'roster_board.assign_shift';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Assign shift';
    }

    public function description(): string
    {
        return 'Rosters each selected employee from the first to the last day: the shift on every day except the weekly-off weekdays, which become weekly offs; past days are recalculated. Employees outside your access, not employed then, or with locked days or days of a closed attendance month are refused.';
    }

    public function model(): string
    {
        return Employee::class;
    }

    public function permission(): string
    {
        return 'attendance.roster';
    }

    public function icon(): string
    {
        return 'heroicon-m-calendar-days';
    }

    /**
     * The roster fields (shared with the Roster board's wizard): the range, the shift, the weekly-off weekdays and the
     * automatic shift correction choice.
     *
     * @return list<mixed>
     */
    public static function fields(?string $from = null, ?string $to = null): array
    {
        return [
            DatePicker::make('from')->label('From')->native(false)->required()->default($from),
            DatePicker::make('to')->label('To')->native(false)->required()->afterOrEqual('from')->default($to),
            Select::make('shift_id')->label('Shift')->native(false)->searchable()
                ->options(fn () => Shift::query()->orderBy('name')->get()->mapWithKeys(fn (Shift $s) => [$s->id => $s->name.' ('.$s->code.')'])->all())
                ->placeholder('Weekly off')->helperText('Leave empty to make every day of the range a weekly off.'),
            CheckboxList::make('weekly_off_days')->label('Weekly off on')->options(ShiftRoster::WEEKDAYS)->columns(4)
                ->helperText('These weekdays of the range become weekly offs; the other days take the shift.'),
            Toggle::make('auto_shift')->label('Allow automatic shift correction on these days')
                ->helperText('The day\'s first check-in picks the nearest shift among the rostered one and those the employee\'s work schedule lists for automatic correction (on weekly offs only when the schedule allows it).'),
        ];
    }

    public function schema(): array
    {
        return [...self::fields(), Textarea::make('reason')->label('Reason')->required()->maxLength(255)];
    }

    public function prepare(array $data, User $actor): array
    {
        try {
            $from = Carbon::parse((string) ($data['from'] ?? ''))->toDateString();
            $to = Carbon::parse((string) ($data['to'] ?? ''))->toDateString();
        } catch (Throwable) {
            throw new RuntimeException('Choose the first and the last day.');
        }
        if (empty($data['from']) || empty($data['to'])) {
            throw new RuntimeException('Choose the first and the last day.');
        }
        if ($to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) {
            throw new RuntimeException('The range must run forward and cover at most three months.');
        }
        $shiftId = filled($data['shift_id'] ?? null) ? (int) $data['shift_id'] : null;
        if ($shiftId !== null && ! Shift::query()->whereKey($shiftId)->exists()) {
            throw new RuntimeException('Choose a shift that exists.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Give the reason for the roster.');
        }

        return ['from' => $from, 'to' => $to, 'shift_id' => $shiftId, 'weekly_off_days' => ShiftRoster::weekdays((array) ($data['weekly_off_days'] ?? [])),
            'auto_shift' => (bool) ($data['auto_shift'] ?? false), 'reason' => mb_substr($reason, 0, 255)];
    }

    public function with(): array
    {
        return ['person'];
    }

    public function handle(Model $record, array $input, User $actor): string
    {
        /** @var Employee $record */
        $shift = null;
        if (($input['shift_id'] ?? null) !== null && ($shift = Shift::query()->find((int) $input['shift_id'])) === null) {
            throw new RuntimeException('The shift no longer exists.');
        }
        $result = app(ShiftRoster::class)->assignPattern([$record], (string) $input['from'], (string) $input['to'], $shift, (array) ($input['weekly_off_days'] ?? []),
            (bool) ($input['auto_shift'] ?? false), (string) $input['reason'], $actor);
        if ($result['refused'] !== []) {
            throw new RuntimeException($result['refused'][0]['reason']);
        }

        return $result['done'][0]['message'] ?? 'Rostered.';
    }
}
